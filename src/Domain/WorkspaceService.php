<?php

namespace ShopAgg\AI_Deployer\Domain;

final class WorkspaceService {

    private \WB_Deployer_File_Ops $files;

    public function __construct(\WB_Deployer_File_Ops $files) {
        $this->files = $files;
    }

    public function tree(array $input): array {
        $path = $this->normalizeManagedPath((string) ($input['path'] ?? 'plugins'));
        if ($path === null) {
            return $this->error('invalid_path', 'Path must be inside plugins, themes, or mu-plugins.');
        }
        $limit = max(1, min(2000, (int) ($input['limit'] ?? 500)));
        $offset = max(0, (int) ($input['offset'] ?? 0));
        $all = $this->files->list_dir_recursive($path);
        $slice = array_slice($all, $offset, $limit);
        $items = [];
        foreach ($slice as $file) {
            $info = $this->files->file_info($file);
            $items[] = [
                'path' => $file,
                'size' => (int) ($info['size'] ?? 0),
                'modified' => (string) ($info['modified'] ?? ''),
                'sha256' => $info['sha256'] ?? null,
            ];
        }
        return [
            'success' => true,
            'path' => $path,
            'items' => $items,
            'pagination' => [
                'offset' => $offset,
                'limit' => $limit,
                'total' => count($all),
                'has_more' => $offset + count($slice) < count($all),
            ],
        ];
    }

    public function search(array $input): array {
        $path = $this->normalizeManagedPath((string) ($input['path'] ?? 'plugins'));
        $query = (string) ($input['query'] ?? '');
        if ($path === null || $query === '') {
            return $this->error('invalid_search', 'A managed path and non-empty query are required.');
        }
        $caseSensitive = !empty($input['case_sensitive']);
        $maxMatches = max(1, min(500, (int) ($input['max_matches'] ?? 100)));
        $extensions = array_values(array_filter(array_map(
            'sanitize_key',
            (array) ($input['extensions'] ?? ['php', 'js', 'css', 'json', 'html', 'md', 'txt'])
        )));
        $matches = [];
        $scanned = 0;

        foreach ($this->files->list_dir_recursive($path) as $file) {
            if (count($matches) >= $maxMatches) {
                break;
            }
            $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if ($extensions && !in_array($extension, $extensions, true)) {
                continue;
            }
            $info = $this->files->file_info($file);
            if (empty($info['exists']) || (int) $info['size'] > 1024 * 1024) {
                continue;
            }
            $content = $this->files->read_file($file);
            if ($content === false || str_contains($content, "\0")) {
                continue;
            }
            $scanned++;
            $lines = preg_split('/\R/', $content) ?: [];
            foreach ($lines as $index => $line) {
                $found = $caseSensitive ? str_contains($line, $query) : stripos($line, $query) !== false;
                if ($found) {
                    $matches[] = [
                        'path' => $file,
                        'line' => $index + 1,
                        'text' => function_exists('mb_substr') ? mb_substr($line, 0, 500) : substr($line, 0, 500),
                    ];
                    if (count($matches) >= $maxMatches) {
                        break 2;
                    }
                }
            }
        }

        return [
            'success' => true,
            'query' => $query,
            'path' => $path,
            'scanned_files' => $scanned,
            'matches' => $matches,
            'truncated' => count($matches) >= $maxMatches,
        ];
    }

    public function read(array $input): array {
        $requests = (array) ($input['files'] ?? []);
        if (!$requests || count($requests) > 20) {
            return $this->error('invalid_files', 'Provide between 1 and 20 file requests.');
        }
        $results = [];
        foreach ($requests as $request) {
            $request = is_array($request) ? $request : [];
            $path = $this->normalizeManagedPath((string) ($request['path'] ?? ''));
            if ($path === null) {
                $results[] = ['success' => false, 'path' => (string) ($request['path'] ?? ''), 'error' => 'Invalid path.'];
                continue;
            }
            $content = $this->files->read_file($path);
            if ($content === false) {
                $results[] = ['success' => false, 'path' => $path, 'error' => 'File not found or unreadable.'];
                continue;
            }
            if (strlen($content) > 2 * 1024 * 1024) {
                $results[] = ['success' => false, 'path' => $path, 'error' => 'File exceeds the 2 MB read limit.'];
                continue;
            }
            $lines = preg_split('/\R/', $content) ?: [''];
            $start = max(1, (int) ($request['start_line'] ?? 1));
            $end = min(count($lines), max($start, (int) ($request['end_line'] ?? ($start + 399))));
            if ($end - $start > 399) {
                $end = $start + 399;
            }
            $results[] = [
                'success' => true,
                'path' => $path,
                'start_line' => $start,
                'end_line' => $end,
                'total_lines' => count($lines),
                'content' => implode("\n", array_slice($lines, $start - 1, $end - $start + 1)),
                'sha256' => hash('sha256', $content),
            ];
        }
        return [
            'success' => !array_filter($results, static fn(array $item): bool => empty($item['success'])),
            'files' => $results,
        ];
    }

    public function normalizeManagedPath(string $path): ?string {
        $path = rawurldecode(trim(str_replace('\\', '/', $path)));
        $path = trim($path, '/');
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '..')) {
            return null;
        }
        if ($path === 'plugins' || $path === 'themes' || $path === 'mu-plugins') {
            return $path;
        }
        foreach (['plugins/', 'themes/', 'mu-plugins/'] as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return $path;
            }
        }
        return null;
    }

    private function error(string $code, string $message): array {
        return ['success' => false, 'code' => $code, 'error' => $message];
    }
}
