<?php

namespace ShopAgg\AI_Deployer\Domain;

use ShopAgg\AI_Deployer\Infrastructure\AuditLog;
use ShopAgg\AI_Deployer\Infrastructure\OperationLock;

final class DeploymentService {

    private \WB_Deployer_File_Ops $files;
    private \WB_Deployer_Backup $backups;
    private WorkspaceService $workspace;
    private HealthVerifier $health;
    private AuditLog $audit;
    private OperationLock $lock;

    public function __construct(
        \WB_Deployer_File_Ops $files,
        \WB_Deployer_Backup $backups,
        WorkspaceService $workspace,
        HealthVerifier $health,
        AuditLog $audit,
        OperationLock $lock
    ) {
        $this->files = $files;
        $this->backups = $backups;
        $this->workspace = $workspace;
        $this->health = $health;
        $this->audit = $audit;
        $this->lock = $lock;
    }

    public function preview(array $input): array {
        $changes = (array) ($input['changes'] ?? []);
        if (!$changes || count($changes) > 100) {
            return $this->error('invalid_changes', 'Provide between 1 and 100 changes.');
        }

        $errors = [];
        $prepared = [];
        $seen = [];
        $totalBytes = 0;

        foreach ($changes as $index => $change) {
            if (!is_array($change)) {
                $errors[] = ['index' => $index, 'code' => 'invalid_change', 'message' => 'Change must be an object.'];
                continue;
            }
            $path = $this->workspace->normalizeManagedPath((string) ($change['path'] ?? ''));
            $operation = sanitize_key((string) ($change['operation'] ?? 'write'));
            if ($path === null || !in_array($operation, ['write', 'delete'], true)) {
                $errors[] = ['index' => $index, 'code' => 'invalid_change', 'message' => 'Invalid path or operation.'];
                continue;
            }
            if (isset($seen[$path])) {
                $errors[] = ['path' => $path, 'code' => 'duplicate_path', 'message' => 'Each path may appear only once.'];
                continue;
            }
            $seen[$path] = true;

            $info = $this->files->file_info($path);
            $exists = !empty($info['exists']);
            $currentHash = $exists ? ($info['sha256'] ?? null) : null;
            if (array_key_exists('expected_sha256', $change)
                && (string) $change['expected_sha256'] !== (string) $currentHash) {
                $errors[] = [
                    'path' => $path,
                    'code' => 'hash_conflict',
                    'message' => 'The file changed after it was read.',
                    'current_sha256' => $currentHash,
                ];
                continue;
            }
            if (!empty($change['expect_absent']) && $exists) {
                $errors[] = ['path' => $path, 'code' => 'already_exists', 'message' => 'Expected a new file, but the path exists.'];
                continue;
            }

            $content = '';
            if ($operation === 'write') {
                if (!array_key_exists('content', $change) || !is_string($change['content'])) {
                    $errors[] = ['path' => $path, 'code' => 'missing_content', 'message' => 'Write changes require string content.'];
                    continue;
                }
                $content = $change['content'];
                $bytes = strlen($content);
                if ($bytes > 5 * 1024 * 1024) {
                    $errors[] = ['path' => $path, 'code' => 'file_too_large', 'message' => 'A file exceeds 5 MB.'];
                    continue;
                }
                $totalBytes += $bytes;
                if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'php') {
                    try {
                        token_get_all($content, TOKEN_PARSE);
                    } catch (\ParseError $error) {
                        $errors[] = [
                            'path' => $path,
                            'code' => 'php_parse_error',
                            'message' => $error->getMessage(),
                        ];
                        continue;
                    }
                }
            }
            $prepared[] = [
                'path' => $path,
                'operation' => $operation,
                'content' => $content,
                'before' => ['exists' => $exists, 'sha256' => $currentHash, 'size' => (int) ($info['size'] ?? 0)],
                'after_sha256' => $operation === 'write' ? hash('sha256', $content) : null,
            ];
        }

        if ($totalBytes > 20 * 1024 * 1024) {
            $errors[] = ['code' => 'deployment_too_large', 'message' => 'The deployment exceeds 20 MB.'];
        }

        return [
            'success' => !$errors,
            'dry_run' => true,
            'change_count' => count($prepared),
            'total_bytes' => $totalBytes,
            'changes' => $prepared,
            'errors' => $errors,
        ];
    }

    public function apply(array $input): array {
        $operationId = sanitize_key((string) ($input['operation_id'] ?? ''));
        $fingerprint = hash('sha256', wp_json_encode($input));
        if ($operationId !== '') {
            $stored = $this->readOperation($operationId);
            if ($stored) {
                if (($stored['fingerprint'] ?? '') !== $fingerprint) {
                    return $this->error('operation_conflict', 'This operation_id was already used with different input.');
                }
                $result = (array) ($stored['result'] ?? []);
                $result['replayed'] = true;
                return $result;
            }
        }

        if (!$this->lock->acquire('deployment')) {
            return $this->error('deployment_locked', 'Another deployment is already running.');
        }

        try {
            $preview = $this->preview($input);
            if (empty($preview['success'])) {
                return $preview;
            }
            $changes = $preview['changes'];
            $paths = array_column($changes, 'path');
            $backupId = $this->backups->create_snapshot($paths, 'ability-deploy:' . ($operationId ?: 'anonymous'));
            if ($backupId === null) {
                return $this->error('backup_failed', 'A complete backup could not be created; no files were changed.');
            }

            $results = [];
            foreach ($changes as $change) {
                $results[] = $change['operation'] === 'delete'
                    ? $this->files->delete_file($change['path'])
                    : $this->files->write_file($change['path'], $change['content']);
            }
            $success = !array_filter($results, static fn(array $result): bool => empty($result['success']));
            $rollback = null;
            if (!$success) {
                $rollback = $this->backups->restore_snapshot($backupId);
            }

            $health = null;
            if ($success) {
                $health = $this->health->verify((array) ($input['health_paths'] ?? []));
                if (empty($health['ok'])) {
                    $rollback = $this->backups->restore_snapshot($backupId);
                    $success = false;
                    $health['auto_rollback'] = true;
                    $health['restored_from'] = $backupId;
                }
            }
            wp_clean_plugins_cache(true);
            $result = [
                'success' => $success,
                'operation_id' => $operationId ?: null,
                'backup_id' => $backupId,
                'results' => $results,
                'health' => $health,
                'rollback' => $rollback,
                'replayed' => false,
            ];
            $this->audit->record('deploy', [
                'operation_id' => $operationId ?: null,
                'paths' => $paths,
                'backup_id' => $backupId,
                'rolled_back' => $rollback !== null,
            ], $success);
            if ($operationId !== '') {
                $this->writeOperation($operationId, $fingerprint, $result);
            }
            return $result;
        } finally {
            $this->lock->release();
        }
    }

    private function operationFile(string $operationId): string {
        return SHOPAGG_AI_DEPLOYER_DATA_DIR . '/operations/' . hash('sha256', $operationId) . '.json';
    }

    private function readOperation(string $operationId): ?array {
        $file = $this->operationFile($operationId);
        if (!is_file($file)) {
            return null;
        }
        $data = json_decode((string) @file_get_contents($file), true);
        return is_array($data) ? $data : null;
    }

    private function writeOperation(string $operationId, string $fingerprint, array $result): void {
        $file = $this->operationFile($operationId);
        $data = wp_json_encode([
            'operation_id' => $operationId,
            'fingerprint' => $fingerprint,
            'created_at' => gmdate('c'),
            'result' => $result,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        @file_put_contents($file, $data, LOCK_EX);
        @chmod($file, 0600);
    }

    private function error(string $code, string $message): array {
        return ['success' => false, 'code' => $code, 'error' => $message];
    }
}
