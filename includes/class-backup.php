<?php
/**
 * Timestamped file snapshots with rollback support.
 *
 * @package SHOPAGG_AI_Deployer
 */

class WB_Deployer_Backup {

    private WB_Deployer_File_Ops $file_ops;
    private string $backup_dir;
    private int $max_backups;

    public function __construct(
        ?WB_Deployer_File_Ops $file_ops = null,
        ?string $backup_dir = null,
        int $max_backups = 30
    ) {
        $this->file_ops = $file_ops ?? new WB_Deployer_File_Ops();
        $this->backup_dir = $backup_dir ?? $this->file_ops->resolve('plugins/shopagg-ai-deployer/backups');
        $this->max_backups = max(5, $max_backups);
    }

    public function create_snapshot(array $relative_paths, string $reason = 'deployment'): ?string {
        $relative_paths = array_values(array_unique(array_filter(array_map('strval', $relative_paths))));
        if (!$relative_paths) {
            return null;
        }

        $id = $this->create_unique_id();
        $snapshot_dir = $this->backup_dir . '/' . $id;
        if (!@mkdir($snapshot_dir, 0755, true)) {
            return null;
        }

        $manifest = [];
        $total_bytes = 0;
        foreach ($relative_paths as $relative_path) {
            $content = $this->file_ops->read_file($relative_path);
            if ($content === false) {
                $manifest[$relative_path] = [
                    'existed' => false,
                    'size' => 0,
                    'sha256' => null,
                ];
                continue;
            }

            $destination = $snapshot_dir . '/' . ltrim(str_replace('\\', '/', $relative_path), '/');
            $destination_dir = dirname($destination);
            if (!is_dir($destination_dir) && !@mkdir($destination_dir, 0755, true)) {
                $manifest[$relative_path] = [
                    'existed' => true,
                    'size' => strlen($content),
                    'sha256' => hash('sha256', $content),
                    'backup_error' => 'Cannot create backup directory.',
                ];
                continue;
            }

            if (@file_put_contents($destination, $content, LOCK_EX) === false) {
                $manifest[$relative_path] = [
                    'existed' => true,
                    'size' => strlen($content),
                    'sha256' => hash('sha256', $content),
                    'backup_error' => 'Cannot write backup file.',
                ];
                continue;
            }

            $size = strlen($content);
            $total_bytes += $size;
            $manifest[$relative_path] = [
                'existed' => true,
                'size' => $size,
                'sha256' => hash('sha256', $content),
            ];
        }

        $data = [
            'id' => $id,
            'created' => gmdate('c'),
            'reason' => trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $reason)),
            'files' => $manifest,
            'count' => count($manifest),
            'total_bytes' => $total_bytes,
            'plugin_version' => defined('SHOPAGG_AI_DEPLOYER_VERSION') ? SHOPAGG_AI_DEPLOYER_VERSION : 'standalone',
        ];

        if (@file_put_contents(
            $snapshot_dir . '/manifest.json',
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        ) === false) {
            $this->recursive_delete($snapshot_dir);
            return null;
        }

        $this->cleanup();
        return $id;
    }

    public function restore_snapshot(string $snapshot_id): array {
        $snapshot_dir = $this->snapshot_dir($snapshot_id);
        if ($snapshot_dir === false || !is_dir($snapshot_dir)) {
            return ['success' => false, 'restored' => 0, 'errors' => ['Snapshot not found.']];
        }

        $manifest_path = $snapshot_dir . '/manifest.json';
        $manifest = $this->read_manifest($manifest_path);
        if ($manifest === null || !isset($manifest['files']) || !is_array($manifest['files'])) {
            return ['success' => false, 'restored' => 0, 'errors' => ['Invalid or missing manifest.']];
        }

        $restored = 0;
        $errors = [];
        foreach ($manifest['files'] as $relative_path => $info) {
            $existed = is_array($info) ? ($info['existed'] ?? true) : $info !== null;
            $source = $snapshot_dir . '/' . ltrim(str_replace('\\', '/', $relative_path), '/');

            if (!$existed) {
                $result = $this->file_ops->delete_file($relative_path);
            } elseif (!is_file($source)) {
                $errors[] = "Missing backup file: {$relative_path}";
                continue;
            } else {
                $content = @file_get_contents($source);
                if ($content === false) {
                    $errors[] = "Cannot read backup file: {$relative_path}";
                    continue;
                }
                $result = $this->file_ops->write_file($relative_path, $content);
            }

            if (!empty($result['success'])) {
                $restored++;
            } else {
                $errors[] = "Restore failed: {$relative_path} — " . ($result['error'] ?? 'unknown error');
            }
        }

        return [
            'success' => !$errors,
            'snapshot_id' => $snapshot_id,
            'restored' => $restored,
            'errors' => $errors,
        ];
    }

    public function list_snapshots(): array {
        if (!is_dir($this->backup_dir)) {
            return [];
        }
        $directories = glob($this->backup_dir . '/*', GLOB_ONLYDIR);
        if ($directories === false) {
            return [];
        }

        $results = [];
        foreach ($directories as $directory) {
            $data = $this->read_manifest($directory . '/manifest.json');
            $results[] = [
                'id' => basename($directory),
                'created' => $data['created'] ?? gmdate('c', (int) filemtime($directory)),
                'reason' => $data['reason'] ?? 'legacy',
                'count' => (int) ($data['count'] ?? 0),
                'total_bytes' => (int) ($data['total_bytes'] ?? 0),
            ];
        }

        usort($results, static fn(array $a, array $b): int => strcmp($b['id'], $a['id']));
        return $results;
    }

    public function get_snapshot(string $id): ?array {
        $directory = $this->snapshot_dir($id);
        if ($directory === false) {
            return null;
        }
        return $this->read_manifest($directory . '/manifest.json');
    }

    public function delete_snapshot(string $id): array {
        $directory = $this->snapshot_dir($id);
        if ($directory === false || !is_dir($directory)) {
            return ['success' => false, 'error' => 'Snapshot not found.'];
        }
        $this->recursive_delete($directory);
        return [
            'success' => !is_dir($directory),
            'id' => $id,
        ];
    }

    private function create_unique_id(): string {
        $microtime = microtime(true);
        $microseconds = sprintf('%06d', (int) (($microtime - floor($microtime)) * 1000000));
        try {
            $suffix = bin2hex(random_bytes(2));
        } catch (Throwable $e) {
            $suffix = substr(md5(uniqid('', true)), 0, 4);
        }
        return gmdate('Ymd_His', (int) $microtime) . '_' . $microseconds . '_' . $suffix;
    }

    private function snapshot_dir(string $id): string|false {
        if (!preg_match('/^[a-zA-Z0-9_.-]+$/', $id)) {
            return false;
        }
        $directory = $this->backup_dir . '/' . $id;
        $parent = realpath(dirname($directory));
        $backup_root = realpath($this->backup_dir);
        if ($parent === false || $backup_root === false || $parent !== $backup_root) {
            return false;
        }
        return $directory;
    }

    private function read_manifest(string $path): ?array {
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);
        return is_array($data) ? $data : null;
    }

    private function cleanup(): void {
        $snapshots = $this->list_snapshots();
        if (count($snapshots) <= $this->max_backups) {
            return;
        }
        $expired = array_slice($snapshots, $this->max_backups);
        foreach ($expired as $snapshot) {
            $directory = $this->snapshot_dir($snapshot['id']);
            if ($directory !== false) {
                $this->recursive_delete($directory);
            }
        }
    }

    private function recursive_delete(string $directory): void {
        if (!is_dir($directory)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir() && !$item->isLink()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($directory);
    }
}
