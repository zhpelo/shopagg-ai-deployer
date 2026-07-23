<?php
/**
 * Safe file operations inside wp-content.
 *
 * @package SHOPAGG_AI_Deployer
 */

class WB_Deployer_File_Ops {

    private string $wp_content_dir;

    public function __construct(?string $wp_content_dir = null) {
        $base = $wp_content_dir ?: (defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : dirname(dirname(__DIR__)));
        $real = realpath($base);
        $this->wp_content_dir = rtrim($real ?: $base, '/\\');
    }

    public function resolve(string $relative_path): string {
        $normalized = $this->normalize_relative_path($relative_path);
        if ($normalized === false) {
            return $this->wp_content_dir . '/__shopagg_invalid_path__';
        }
        return $this->wp_content_dir . '/' . $normalized;
    }

    public function relative(string $absolute_path): string {
        $absolute_path = str_replace('\\', '/', $absolute_path);
        $prefix = str_replace('\\', '/', $this->wp_content_dir) . '/';
        return str_starts_with($absolute_path, $prefix)
            ? substr($absolute_path, strlen($prefix))
            : $absolute_path;
    }

    public function is_safe(string $resolved_path): bool {
        $base = str_replace('\\', '/', $this->wp_content_dir);
        $path = str_replace('\\', '/', $resolved_path);

        if ($path === $base || !str_starts_with($path, $base . '/')) {
            return false;
        }

        $relative = substr($path, strlen($base) + 1);
        if ($this->normalize_relative_path($relative) === false) {
            return false;
        }

        $probe = $path;
        while (!file_exists($probe) && $probe !== $base) {
            $parent = dirname($probe);
            if ($parent === $probe) {
                return false;
            }
            $probe = $parent;
        }

        $real_probe = realpath($probe);
        if ($real_probe === false) {
            return false;
        }
        $real_probe = str_replace('\\', '/', $real_probe);

        return $real_probe === $base || str_starts_with($real_probe, $base . '/');
    }

    public function read_file(string $relative_path): string|false {
        $abs = $this->validated_absolute_path($relative_path);
        if ($abs === false || $this->is_sensitive_path($relative_path) || !is_file($abs) || !is_readable($abs)) {
            return false;
        }
        return @file_get_contents($abs);
    }

    public function write_file(string $relative_path, string $content): array {
        $abs = $this->validated_absolute_path($relative_path);
        if ($abs === false || $this->is_sensitive_path($relative_path)) {
            return $this->error('Unsafe or protected path.', $relative_path);
        }

        $dir = dirname($abs);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            return $this->error('Cannot create directory.', $relative_path);
        }
        if (!$this->is_safe($abs) || is_link($abs)) {
            return $this->error('Symlink or path traversal denied.', $relative_path);
        }

        $tmp = @tempnam($dir, '.shopagg-');
        if ($tmp === false) {
            return $this->error('Cannot create temporary file.', $relative_path);
        }

        $bytes = @file_put_contents($tmp, $content, LOCK_EX);
        if ($bytes === false) {
            @unlink($tmp);
            return $this->error('Write failed.', $relative_path);
        }

        @chmod($tmp, 0644);
        if (!@rename($tmp, $abs)) {
            @unlink($tmp);
            return $this->error('Atomic replace failed.', $relative_path);
        }

        clearstatcache(true, $abs);
        return [
            'success' => true,
            'path' => $this->normalize_relative_path($relative_path),
            'bytes' => $bytes,
            'sha256' => hash('sha256', $content),
        ];
    }

    public function delete_file(string $relative_path): array {
        $abs = $this->validated_absolute_path($relative_path);
        if ($abs === false || $this->is_sensitive_path($relative_path)) {
            return $this->error('Unsafe or protected path.', $relative_path);
        }
        if (!file_exists($abs) && !is_link($abs)) {
            return ['success' => true, 'path' => $relative_path, 'existed' => false];
        }
        if (is_dir($abs)) {
            return $this->error('Path is a directory.', $relative_path);
        }
        if (@unlink($abs)) {
            return ['success' => true, 'path' => $relative_path, 'existed' => true];
        }
        return $this->error('Delete failed.', $relative_path);
    }

    public function file_exists(string $relative_path): bool {
        $abs = $this->validated_absolute_path($relative_path);
        return $abs !== false && !$this->is_sensitive_path($relative_path) && file_exists($abs);
    }

    public function list_dir(string $relative_dir, string $pattern = '*'): array {
        $abs = $this->validated_absolute_path($relative_dir);
        if ($abs === false || !is_dir($abs)) {
            return [];
        }

        $files = glob($abs . '/' . $pattern);
        if ($files === false) {
            return [];
        }

        $results = [];
        foreach ($files as $file) {
            $relative = $this->relative($file);
            if ($this->is_safe($file) && !$this->is_sensitive_path($relative)) {
                $results[] = $relative;
            }
        }
        sort($results);
        return $results;
    }

    public function list_dir_recursive(string $relative_dir): array {
        $abs = $this->validated_absolute_path($relative_dir);
        if ($abs === false || !is_dir($abs)) {
            return [];
        }

        $results = [];
        $directory = new RecursiveDirectoryIterator(
            $abs,
            RecursiveDirectoryIterator::SKIP_DOTS | RecursiveDirectoryIterator::CURRENT_AS_FILEINFO
        );
        $filter = new RecursiveCallbackFilterIterator(
            $directory,
            function (SplFileInfo $current): bool {
                if ($current->isLink()) {
                    return false;
                }
                $relative = $this->relative($current->getPathname());
                if ($this->is_sensitive_path($relative)) {
                    return false;
                }
                $name = $current->getFilename();
                if ($current->isDir() && in_array($name, ['.git', 'node_modules'], true)) {
                    return false;
                }
                return true;
            }
        );

        $iterator = new RecursiveIteratorIterator($filter);
        foreach ($iterator as $file) {
            if ($file->isFile() && $this->is_safe($file->getPathname())) {
                $results[] = $this->relative($file->getPathname());
            }
        }

        sort($results);
        return $results;
    }

    public function file_info(string $relative_path): array {
        $abs = $this->validated_absolute_path($relative_path);
        $info = [
            'path' => $relative_path,
            'exists' => false,
            'size' => 0,
            'modified' => '',
            'readable' => false,
            'writable' => false,
            'sha256' => null,
        ];

        if ($abs !== false && !$this->is_sensitive_path($relative_path) && is_file($abs)) {
            $info['exists'] = true;
            $info['size'] = (int) filesize($abs);
            $info['modified'] = gmdate('c', (int) filemtime($abs));
            $info['readable'] = is_readable($abs);
            $info['writable'] = is_writable($abs);
            if ($info['readable'] && $info['size'] <= 10 * 1024 * 1024) {
                $info['sha256'] = hash_file('sha256', $abs);
            }
        }
        return $info;
    }

    public function create_dir(string $relative_dir): array {
        $abs = $this->validated_absolute_path($relative_dir);
        if ($abs === false || $this->is_sensitive_path($relative_dir)) {
            return $this->error('Unsafe or protected path.', $relative_dir);
        }
        if (is_dir($abs)) {
            return ['success' => true, 'path' => $relative_dir, 'existed' => true];
        }
        if (@mkdir($abs, 0755, true) && $this->is_safe($abs)) {
            return ['success' => true, 'path' => $relative_dir, 'existed' => false];
        }
        return $this->error('Cannot create directory.', $relative_dir);
    }

    public function delete_directory(string $relative_dir): array {
        $normalized = $this->normalize_relative_path($relative_dir);
        $abs = $this->validated_absolute_path($relative_dir);
        if ($normalized === false || $abs === false || $this->is_sensitive_path($relative_dir)) {
            return $this->error('Unsafe or protected directory.', $relative_dir, ['deleted_count' => 0]);
        }
        if (substr_count($normalized, '/') < 1) {
            return $this->error('Top-level wp-content directories cannot be removed.', $relative_dir, ['deleted_count' => 0]);
        }
        if (!is_dir($abs)) {
            return ['success' => true, 'path' => $normalized, 'deleted_count' => 0, 'existed' => false];
        }

        $count = 0;
        $errors = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($abs, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $file) {
            $path = $file->getPathname();
            if (!$this->is_safe($path)) {
                $errors[] = 'Unsafe path skipped: ' . $this->relative($path);
                continue;
            }
            if ($file->isDir() && !$file->isLink()) {
                if (!@rmdir($path)) {
                    $errors[] = 'Cannot remove directory: ' . $this->relative($path);
                }
            } elseif (@unlink($path)) {
                $count++;
            } else {
                $errors[] = 'Cannot delete file: ' . $this->relative($path);
            }
        }

        if (!@rmdir($abs)) {
            $errors[] = 'Cannot remove directory: ' . $normalized;
        }

        return [
            'success' => empty($errors),
            'path' => $normalized,
            'deleted_count' => $count,
            'errors' => $errors,
        ];
    }

    public function get_wp_content_dir(): string {
        return $this->wp_content_dir;
    }

    private function validated_absolute_path(string $relative_path): string|false {
        $normalized = $this->normalize_relative_path($relative_path);
        if ($normalized === false) {
            return false;
        }
        $abs = $this->wp_content_dir . '/' . $normalized;
        return $this->is_safe($abs) ? $abs : false;
    }

    private function normalize_relative_path(string $path): string|false {
        $path = rawurldecode(trim(str_replace('\\', '/', $path)));
        if ($path === '' || str_contains($path, "\0") || str_starts_with($path, '/') || preg_match('/^[a-zA-Z]:\//', $path)) {
            return false;
        }
        $segments = explode('/', $path);
        $clean = [];
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                return false;
            }
            $clean[] = $segment;
        }
        return $clean ? implode('/', $clean) : false;
    }

    private function is_sensitive_path(string $relative_path): bool {
        $path = strtolower(str_replace('\\', '/', ltrim($relative_path, '/')));
        if ($path === 'plugins/shopagg-ai-deployer/includes/config.php') {
            return true;
        }
        if (str_starts_with($path, 'plugins/shopagg-ai-deployer/backups/')) {
            return true;
        }
        return (bool) preg_match('/(^|\/)(\.env(?:\..*)?|[^\/]+\.(?:pem|key|p12|pfx))$/i', $path);
    }

    private function error(string $message, string $path, array $extra = []): array {
        return array_merge([
            'success' => false,
            'error' => $message,
            'path' => $path,
        ], $extra);
    }
}

