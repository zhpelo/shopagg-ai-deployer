<?php
/**
 * Authenticated WordPress REST API.
 *
 * @package SHOPAGG_AI_Deployer
 */

require_once SHOPAGG_AI_DEPLOYER_DIR . 'includes/class-file-ops.php';
require_once SHOPAGG_AI_DEPLOYER_DIR . 'includes/class-backup.php';

class WB_Deployer_API {

    private WB_Deployer_File_Ops $file_ops;
    private WB_Deployer_Backup $backup;

    public function __construct() {
        $this->file_ops = new WB_Deployer_File_Ops();
        $this->backup = new WB_Deployer_Backup($this->file_ops);
    }

    public function register(): void {
        $namespace = SHOPAGG_AI_DEPLOYER_REST_NS;
        $authenticated = ['permission_callback' => [$this, 'check_permission']];

        $this->route($namespace, '/status', 'GET', 'handle_status', $authenticated);
        $this->route($namespace, '/activity', 'GET', 'handle_activity', $authenticated);
        $this->route($namespace, '/health', 'GET', 'handle_health', $authenticated);
        $this->route($namespace, '/deploy', 'POST', 'handle_deploy', $authenticated);

        register_rest_route($namespace, '/files/(?P<path>.+)', [
            array_merge($authenticated, ['methods' => 'GET', 'callback' => [$this, 'handle_get_file']]),
            array_merge($authenticated, ['methods' => 'PUT', 'callback' => [$this, 'handle_put_file']]),
            array_merge($authenticated, ['methods' => 'DELETE', 'callback' => [$this, 'handle_delete_file']]),
        ]);
        $this->route($namespace, '/list/(?P<path>.+)', 'GET', 'handle_list_dir', $authenticated);
        $this->route($namespace, '/delete-dir', 'POST', 'handle_delete_directory', $authenticated);

        $this->route($namespace, '/backups', 'GET', 'handle_list_backups', $authenticated);
        register_rest_route($namespace, '/backups/(?P<id>[a-zA-Z0-9_.-]+)', [
            array_merge($authenticated, ['methods' => 'GET', 'callback' => [$this, 'handle_get_backup']]),
            array_merge($authenticated, ['methods' => 'DELETE', 'callback' => [$this, 'handle_delete_backup']]),
        ]);
        $this->route(
            $namespace,
            '/backups/(?P<id>[a-zA-Z0-9_.-]+)/restore',
            'POST',
            'handle_restore',
            $authenticated
        );

        $this->route($namespace, '/plugins', 'GET', 'handle_list_plugins', $authenticated);
        $this->route(
            $namespace,
            '/plugins/(?P<slug>[a-zA-Z0-9_-]+)/activate',
            'POST',
            'handle_activate_plugin',
            $authenticated
        );
        $this->route(
            $namespace,
            '/plugins/(?P<slug>[a-zA-Z0-9_-]+)/deactivate',
            'POST',
            'handle_deactivate_plugin',
            $authenticated
        );
        $this->route(
            $namespace,
            '/plugins/(?P<slug>[a-zA-Z0-9_-]+)',
            'DELETE',
            'handle_delete_plugin',
            $authenticated
        );

        $this->route($namespace, '/themes', 'GET', 'handle_list_themes', $authenticated);
        $this->route(
            $namespace,
            '/themes/(?P<slug>[a-zA-Z0-9_-]+)/activate',
            'POST',
            'handle_activate_theme',
            $authenticated
        );

        register_rest_route($namespace, '/posts', [
            array_merge($authenticated, ['methods' => 'GET', 'callback' => [$this, 'handle_list_posts']]),
            array_merge($authenticated, ['methods' => 'POST', 'callback' => [$this, 'handle_create_post']]),
        ]);
        register_rest_route($namespace, '/posts/(?P<id>\d+)', [
            array_merge($authenticated, ['methods' => 'GET', 'callback' => [$this, 'handle_get_post']]),
            array_merge($authenticated, ['methods' => ['PUT', 'PATCH'], 'callback' => [$this, 'handle_update_post']]),
            array_merge($authenticated, ['methods' => 'DELETE', 'callback' => [$this, 'handle_delete_post']]),
        ]);

        $this->route(
            $namespace,
            '/code/(?P<type>plugin|theme)/(?P<slug>[a-zA-Z0-9_-]+)',
            'GET',
            'handle_get_code',
            $authenticated
        );
        $this->route($namespace, '/cache/clear', ['GET', 'POST'], 'handle_clear_cache', $authenticated);

        register_rest_route($namespace, '/options/(?P<name>[a-zA-Z0-9_-]+)', [
            array_merge($authenticated, ['methods' => 'GET', 'callback' => [$this, 'handle_get_option']]),
            array_merge($authenticated, ['methods' => 'POST', 'callback' => [$this, 'handle_set_option']]),
        ]);

        $this->route(
            $namespace,
            '/settings/regenerate-key',
            'POST',
            'handle_regenerate_key',
            ['permission_callback' => static fn(): bool => current_user_can('manage_options')]
        );
    }

    private function route(
        string $namespace,
        string $route,
        string|array $methods,
        string $handler,
        array $extra
    ): void {
        register_rest_route($namespace, $route, array_merge($extra, [
            'methods' => $methods,
            'callback' => [$this, $handler],
        ]));
    }

    public function check_permission(WP_REST_Request $request): bool|WP_Error {
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        if (function_exists('do_action')) {
            do_action('litespeed_control_set_nocache', 'SHOPAGG authenticated API');
        }
        $provided = trim((string) $request->get_header('X-ShopAgg-AI-Deployer-Key'));
        $expected = shopagg_ai_deployer_get_api_key();
        if ($provided === '' || $expected === '' || !hash_equals($expected, $provided)) {
            return new WP_Error(
                'shopagg_deployer_unauthorized',
                'Invalid or missing deployer key.',
                ['status' => 401]
            );
        }
        return true;
    }

    public function handle_status(): WP_REST_Response {
        $plugins = $this->get_plugins_data();
        $themes = wp_get_themes();
        $active_plugins = count(array_filter($plugins, static fn(array $plugin): bool => $plugin['active']));
        $backups = $this->backup->list_snapshots();

        return $this->response([
            'success' => true,
            'plugin_version' => SHOPAGG_AI_DEPLOYER_VERSION,
            'wordpress_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
            'site_url' => home_url('/'),
            'rest_namespace' => SHOPAGG_AI_DEPLOYER_REST_NS,
            'plugins' => ['total' => count($plugins), 'active' => $active_plugins],
            'themes' => ['total' => count($themes), 'active' => get_option('stylesheet')],
            'backups' => ['count' => count($backups), 'latest' => $backups[0]['id'] ?? null],
            'limits' => [
                'deploy_files' => 100,
                'deploy_file_bytes' => 5 * 1024 * 1024,
                'deploy_total_bytes' => 20 * 1024 * 1024,
                'code_default_bytes' => 2 * 1024 * 1024,
            ],
        ]);
    }

    public function handle_activity(WP_REST_Request $request): WP_REST_Response {
        $limit = max(1, min(100, (int) ($request->get_param('limit') ?: 30)));
        $activity = get_option('shopagg_ai_deployer_activity', []);
        return $this->response([
            'success' => true,
            'items' => array_slice(is_array($activity) ? $activity : [], 0, $limit),
        ]);
    }

    public function handle_health(): WP_REST_Response {
        return $this->response($this->check_wordpress_health());
    }

    public function handle_deploy(WP_REST_Request $request): WP_REST_Response {
        $files = $request->get_param('files');
        $auto_backup = filter_var($request->get_param('auto_backup') ?? true, FILTER_VALIDATE_BOOLEAN);
        $health_check = filter_var($request->get_param('health_check') ?? true, FILTER_VALIDATE_BOOLEAN);

        $validation = $this->validate_deployment($files);
        if (is_wp_error($validation)) {
            return $this->error($validation->get_error_message(), 400);
        }

        $paths = array_column($files, 'path');
        $backup_id = null;
        if ($auto_backup) {
            $backup_id = $this->backup->create_snapshot($paths, 'deploy');
            if ($backup_id === null) {
                return $this->error('Backup creation failed; deployment was cancelled.', 500);
            }
        }

        $results = [];
        foreach ($files as $file) {
            $results[] = $this->file_ops->write_file((string) $file['path'], (string) $file['content']);
        }
        $success = !in_array(false, array_map(static fn(array $result): bool => !empty($result['success']), $results), true);

        $rollback = null;
        if (!$success && $backup_id) {
            $rollback = $this->backup->restore_snapshot($backup_id);
        }

        $health = null;
        if ($success && $health_check) {
            $health = $this->check_wordpress_health();
            if (empty($health['ok']) && $backup_id) {
                $rollback = $this->backup->restore_snapshot($backup_id);
                $health['auto_rollback'] = true;
                $health['restored_from'] = $backup_id;
                $success = false;
            }
        }

        wp_clean_plugins_cache(true);
        $this->record_activity('deploy', [
            'paths' => $paths,
            'backup_id' => $backup_id,
            'health_check' => $health_check,
        ], $success);

        return $this->response([
            'success' => $success,
            'results' => $results,
            'backup_id' => $backup_id,
            'health' => $health,
            'rollback' => $rollback,
        ], $success ? 200 : 500);
    }

    public function handle_get_file(WP_REST_Request $request): WP_REST_Response {
        $path = urldecode((string) $request->get_param('path'));
        $content = $this->file_ops->read_file($path);
        if ($content === false) {
            return $this->error('File not found, protected, or outside wp-content.', 404);
        }
        $max_bytes = max(1024, min(10 * 1024 * 1024, (int) ($request->get_param('max_bytes') ?: 2 * 1024 * 1024)));
        if (strlen($content) > $max_bytes) {
            return $this->error('File exceeds max_bytes. Increase max_bytes up to 10485760 if required.', 413);
        }
        return $this->response([
            'success' => true,
            'path' => $path,
            'content' => $content,
            'info' => $this->file_ops->file_info($path),
        ]);
    }

    public function handle_put_file(WP_REST_Request $request): WP_REST_Response {
        $path = urldecode((string) $request->get_param('path'));
        $proxy = new WP_REST_Request('POST');
        $proxy->set_param('files', [[
            'path' => $path,
            'content' => (string) ($request->get_param('content') ?? ''),
        ]]);
        $proxy->set_param('auto_backup', $request->get_param('auto_backup') ?? true);
        $proxy->set_param('health_check', $request->get_param('health_check') ?? true);
        return $this->handle_deploy($proxy);
    }

    public function handle_delete_file(WP_REST_Request $request): WP_REST_Response {
        $path = urldecode((string) $request->get_param('path'));
        if (!$this->is_manageable_path($path)) {
            return $this->error('Only plugin, theme, and mu-plugin files may be deleted.', 400);
        }
        $backup_id = null;
        if ($this->file_ops->file_exists($path)) {
            $backup_id = $this->backup->create_snapshot([$path], 'delete-file');
            if ($backup_id === null) {
                return $this->error('Backup creation failed; deletion was cancelled.', 500);
            }
        }
        $result = $this->file_ops->delete_file($path);
        $this->record_activity('delete_file', ['path' => $path, 'backup_id' => $backup_id], !empty($result['success']));
        return $this->response(array_merge($result, ['backup_id' => $backup_id]), !empty($result['success']) ? 200 : 400);
    }

    public function handle_list_dir(WP_REST_Request $request): WP_REST_Response {
        $path = urldecode((string) $request->get_param('path'));
        $recursive = filter_var($request->get_param('recursive') ?? false, FILTER_VALIDATE_BOOLEAN);
        $files = $recursive ? $this->file_ops->list_dir_recursive($path) : $this->file_ops->list_dir($path);
        return $this->response([
            'success' => true,
            'path' => $path,
            'recursive' => $recursive,
            'count' => count($files),
            'files' => $files,
        ]);
    }

    public function handle_delete_directory(WP_REST_Request $request): WP_REST_Response {
        $path = trim((string) $request->get_param('path'));
        if (!$this->is_manageable_path($path) || $path === 'plugins/shopagg-ai-deployer') {
            return $this->error('Unsafe directory or self-deletion denied.', 400);
        }
        $files = $this->file_ops->list_dir_recursive($path);
        $backup_id = null;
        if ($files) {
            $backup_id = $this->backup->create_snapshot($files, 'delete-directory');
            if ($backup_id === null) {
                return $this->error('Backup creation failed; deletion was cancelled.', 500);
            }
        }
        $result = $this->file_ops->delete_directory($path);
        wp_clean_plugins_cache(true);
        $this->record_activity('delete_directory', ['path' => $path, 'backup_id' => $backup_id], !empty($result['success']));
        return $this->response(array_merge($result, ['backup_id' => $backup_id]), !empty($result['success']) ? 200 : 400);
    }

    public function handle_list_backups(): WP_REST_Response {
        return $this->response(['success' => true, 'backups' => $this->backup->list_snapshots()]);
    }

    public function handle_get_backup(WP_REST_Request $request): WP_REST_Response {
        $snapshot = $this->backup->get_snapshot((string) $request->get_param('id'));
        return $snapshot === null
            ? $this->error('Backup not found.', 404)
            : $this->response(['success' => true, 'backup' => $snapshot]);
    }

    public function handle_restore(WP_REST_Request $request): WP_REST_Response {
        $id = (string) $request->get_param('id');
        $result = $this->backup->restore_snapshot($id);
        wp_clean_plugins_cache(true);
        $this->record_activity('restore_backup', ['id' => $id], !empty($result['success']));
        return $this->response($result, !empty($result['success']) ? 200 : 500);
    }

    public function handle_delete_backup(WP_REST_Request $request): WP_REST_Response {
        $id = (string) $request->get_param('id');
        $result = $this->backup->delete_snapshot($id);
        $this->record_activity('delete_backup', ['id' => $id], !empty($result['success']));
        return $this->response($result, !empty($result['success']) ? 200 : 404);
    }

    public function handle_list_plugins(): WP_REST_Response {
        return $this->response(['success' => true, 'plugins' => $this->get_plugins_data()]);
    }

    public function handle_activate_plugin(WP_REST_Request $request): WP_REST_Response {
        $slug = (string) $request->get_param('slug');
        $plugin_file = $this->find_plugin_file($slug);
        if ($plugin_file === null) {
            return $this->error('Plugin not found.', 404);
        }
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $result = activate_plugin($plugin_file, '', false, false);
        if (is_wp_error($result)) {
            $this->record_activity('activate_plugin', ['slug' => $slug], false);
            return $this->error($result->get_error_message(), 500);
        }
        wp_clean_plugins_cache(true);
        $this->record_activity('activate_plugin', ['slug' => $slug], true);
        return $this->response(['success' => true, 'message' => "Plugin '{$slug}' activated.", 'file' => $plugin_file]);
    }

    public function handle_deactivate_plugin(WP_REST_Request $request): WP_REST_Response {
        $slug = (string) $request->get_param('slug');
        if ($slug === 'shopagg-ai-deployer') {
            return $this->error('Remote self-deactivation is denied; use WordPress admin if required.', 400);
        }
        $plugin_file = $this->find_plugin_file($slug);
        if ($plugin_file === null || !is_plugin_active($plugin_file)) {
            return $this->error('Plugin is not active or was not found.', 404);
        }
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        deactivate_plugins($plugin_file, false, false);
        wp_clean_plugins_cache(true);
        $this->record_activity('deactivate_plugin', ['slug' => $slug], true);
        return $this->response(['success' => true, 'message' => "Plugin '{$slug}' deactivated.", 'file' => $plugin_file]);
    }

    public function handle_delete_plugin(WP_REST_Request $request): WP_REST_Response {
        $slug = (string) $request->get_param('slug');
        if ($slug === 'shopagg-ai-deployer') {
            return $this->error('Remote self-deletion is denied.', 400);
        }
        $plugin_file = $this->find_plugin_file($slug);
        if ($plugin_file === null) {
            return $this->error('Plugin not found.', 404);
        }

        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        if (is_plugin_active($plugin_file)) {
            deactivate_plugins($plugin_file, false, false);
        }

        $directory = dirname($plugin_file);
        $target = $directory === '.' ? 'plugins/' . $plugin_file : 'plugins/' . $directory;
        $files = $directory === '.' ? [$target] : $this->file_ops->list_dir_recursive($target);
        $backup_id = $this->backup->create_snapshot($files, 'delete-plugin');
        if ($backup_id === null) {
            return $this->error('Backup creation failed; plugin deletion was cancelled.', 500);
        }

        $result = $directory === '.'
            ? $this->file_ops->delete_file($target)
            : $this->file_ops->delete_directory($target);
        wp_clean_plugins_cache(true);
        $this->record_activity('delete_plugin', ['slug' => $slug, 'backup_id' => $backup_id], !empty($result['success']));
        return $this->response(array_merge($result, ['backup_id' => $backup_id]), !empty($result['success']) ? 200 : 500);
    }

    public function handle_list_themes(): WP_REST_Response {
        $themes = [];
        $current = get_option('stylesheet');
        foreach (wp_get_themes() as $slug => $theme) {
            $themes[] = [
                'slug' => $slug,
                'name' => $theme->get('Name'),
                'version' => $theme->get('Version'),
                'active' => $slug === $current,
                'parent' => $theme->parent() ? $theme->parent()->get_stylesheet() : null,
            ];
        }
        return $this->response(['success' => true, 'themes' => $themes]);
    }

    public function handle_activate_theme(WP_REST_Request $request): WP_REST_Response {
        $slug = (string) $request->get_param('slug');
        $theme = wp_get_theme($slug);
        if (!$theme->exists() || $theme->errors()) {
            return $this->error('Theme not found or invalid.', 404);
        }
        switch_theme($slug);
        $success = get_option('stylesheet') === $slug;
        $this->record_activity('activate_theme', ['slug' => $slug], $success);
        return $this->response([
            'success' => $success,
            'message' => $success ? "Theme '{$slug}' activated." : 'Theme activation failed.',
        ], $success ? 200 : 500);
    }

    public function handle_create_post(WP_REST_Request $request): WP_REST_Response {
        $post_data = $this->post_data_from_request($request, true);
        if (is_wp_error($post_data)) {
            return $this->error($post_data->get_error_message(), 400);
        }
        $post_id = wp_insert_post($post_data, true);
        if (is_wp_error($post_id)) {
            return $this->error($post_id->get_error_message(), 500);
        }
        $this->record_activity('create_post', ['id' => $post_id], true);
        return $this->response([
            'success' => true,
            'post' => $this->format_post(get_post($post_id), true),
        ], 201);
    }

    public function handle_list_posts(WP_REST_Request $request): WP_REST_Response {
        $per_page = max(1, min(100, (int) ($request->get_param('per_page') ?: $request->get_param('limit') ?: 20)));
        $page = max(1, (int) ($request->get_param('page') ?: 1));
        $status = sanitize_key((string) ($request->get_param('status') ?: 'publish'));
        $query = new WP_Query([
            'post_type' => 'post',
            'post_status' => in_array($status, ['publish', 'draft', 'pending', 'private', 'future', 'any'], true) ? $status : 'publish',
            'posts_per_page' => $per_page,
            'paged' => $page,
            'orderby' => 'date',
            'order' => 'DESC',
            's' => sanitize_text_field((string) $request->get_param('search')),
        ]);
        return $this->response([
            'success' => true,
            'posts' => array_map(fn(WP_Post $post): array => $this->format_post($post, false), $query->posts),
            'pagination' => [
                'page' => $page,
                'per_page' => $per_page,
                'total' => (int) $query->found_posts,
                'total_pages' => (int) $query->max_num_pages,
            ],
        ]);
    }

    public function handle_get_post(WP_REST_Request $request): WP_REST_Response {
        $post = get_post((int) $request->get_param('id'));
        if (!$post || $post->post_type !== 'post') {
            return $this->error('Post not found.', 404);
        }
        return $this->response(['success' => true, 'post' => $this->format_post($post, true)]);
    }

    public function handle_update_post(WP_REST_Request $request): WP_REST_Response {
        $post_id = (int) $request->get_param('id');
        $existing = get_post($post_id);
        if (!$existing || $existing->post_type !== 'post') {
            return $this->error('Post not found.', 404);
        }
        $data = $this->post_data_from_request($request, false);
        if (is_wp_error($data)) {
            return $this->error($data->get_error_message(), 400);
        }
        $data['ID'] = $post_id;
        $result = wp_update_post($data, true);
        if (is_wp_error($result)) {
            return $this->error($result->get_error_message(), 500);
        }
        $this->record_activity('update_post', ['id' => $post_id], true);
        return $this->response(['success' => true, 'post' => $this->format_post(get_post($post_id), true)]);
    }

    public function handle_delete_post(WP_REST_Request $request): WP_REST_Response {
        $post_id = (int) $request->get_param('id');
        $force = filter_var($request->get_param('force') ?? false, FILTER_VALIDATE_BOOLEAN);
        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'post') {
            return $this->error('Post not found.', 404);
        }
        $result = wp_delete_post($post_id, $force);
        $success = $result instanceof WP_Post;
        $this->record_activity('delete_post', ['id' => $post_id, 'force' => $force], $success);
        return $this->response([
            'success' => $success,
            'id' => $post_id,
            'deleted' => $force,
            'trashed' => !$force && $success,
        ], $success ? 200 : 500);
    }

    public function handle_get_code(WP_REST_Request $request): WP_REST_Response {
        $type = (string) $request->get_param('type');
        $slug = (string) $request->get_param('slug');
        $path = ($type === 'plugin' ? 'plugins/' : 'themes/') . $slug;
        $extensions = array_values(array_filter(array_map(
            'sanitize_key',
            explode(',', (string) ($request->get_param('extensions') ?: 'php,css,js,json,txt,md,html'))
        )));
        $max_bytes = max(64 * 1024, min(10 * 1024 * 1024, (int) ($request->get_param('max_bytes') ?: 2 * 1024 * 1024)));
        $files = $this->file_ops->list_dir_recursive($path);
        $code = [];
        $skipped = [];
        $total_bytes = 0;

        foreach ($files as $file) {
            if ($this->should_skip_code_path($file)) {
                $skipped[] = ['path' => $file, 'reason' => 'excluded_directory'];
                continue;
            }
            $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (!in_array($extension, $extensions, true)) {
                continue;
            }
            $info = $this->file_ops->file_info($file);
            $size = (int) ($info['size'] ?? 0);
            if ($size > 1024 * 1024 || $total_bytes + $size > $max_bytes) {
                $skipped[] = ['path' => $file, 'size' => $size, 'reason' => 'size_limit'];
                continue;
            }
            $content = $this->file_ops->read_file($file);
            if ($content !== false) {
                $code[$file] = $content;
                $total_bytes += strlen($content);
            }
        }

        return $this->response([
            'success' => true,
            'type' => $type,
            'slug' => $slug,
            'files' => $code,
            'file_count' => count($code),
            'total_bytes' => $total_bytes,
            'truncated' => !empty($skipped),
            'skipped' => $skipped,
        ]);
    }

    public function handle_clear_cache(): WP_REST_Response {
        $actions = [];
        if (wp_cache_flush()) {
            $actions[] = 'wp_cache_flush';
        }
        wp_clean_plugins_cache(true);
        $actions[] = 'wp_clean_plugins_cache';
        if (has_action('litespeed_purge_all')) {
            do_action('litespeed_purge_all');
            $actions[] = 'litespeed_purge_all';
        }
        if (function_exists('rocket_clean_domain')) {
            rocket_clean_domain();
            $actions[] = 'wp_rocket_clean_domain';
        }
        $this->record_activity('clear_cache', ['actions' => $actions], true);
        return $this->response(['success' => true, 'actions' => $actions]);
    }

    public function handle_get_option(WP_REST_Request $request): WP_REST_Response {
        $name = sanitize_key((string) $request->get_param('name'));
        if ($this->is_protected_option($name)) {
            return $this->error('This option is protected. Use the dedicated plugin or theme endpoint.', 403);
        }
        $sentinel = new stdClass();
        $value = get_option($name, $sentinel);
        return $this->response([
            'success' => true,
            'name' => $name,
            'exists' => $value !== $sentinel,
            'value' => $value === $sentinel ? null : $value,
        ]);
    }

    public function handle_set_option(WP_REST_Request $request): WP_REST_Response {
        $name = sanitize_key((string) $request->get_param('name'));
        if ($this->is_protected_option($name)) {
            return $this->error('This option is protected. Use the dedicated plugin or theme endpoint.', 403);
        }
        $old_value = get_option($name, null);
        $backup_name = 'shopagg_option_backup_' . gmdate('Ymd_His') . '_' . substr(md5($name), 0, 8);
        update_option($backup_name, ['name' => $name, 'value' => $old_value, 'created' => gmdate('c')], false);
        $updated = update_option($name, $request->get_param('value'));
        $this->record_activity('set_option', ['name' => $name, 'backup_option' => $backup_name], true);
        return $this->response([
            'success' => true,
            'name' => $name,
            'updated' => $updated,
            'backup_option' => $backup_name,
        ]);
    }

    public function handle_regenerate_key(): WP_REST_Response {
        return $this->response(['success' => true, 'api_key' => shopagg_ai_deployer_regenerate_api_key()]);
    }

    private function validate_deployment(mixed $files): true|WP_Error {
        if (!is_array($files) || !$files) {
            return new WP_Error('empty_deploy', 'No files provided.');
        }
        if (count($files) > 100) {
            return new WP_Error('too_many_files', 'A deployment may contain at most 100 files.');
        }
        $total_bytes = 0;
        foreach ($files as $file) {
            if (!is_array($file) || empty($file['path']) || !array_key_exists('content', $file)) {
                return new WP_Error('invalid_file', 'Every file requires path and content.');
            }
            if (!$this->is_manageable_path((string) $file['path'])) {
                return new WP_Error('invalid_path', 'Deployments are limited to plugins, themes, and mu-plugins.');
            }
            if (!is_string($file['content'])) {
                return new WP_Error('invalid_content', 'File content must be a string.');
            }
            $bytes = strlen($file['content']);
            if ($bytes > 5 * 1024 * 1024) {
                return new WP_Error('file_too_large', 'A deployment file exceeds the 5 MB limit.');
            }
            $total_bytes += $bytes;
        }
        if ($total_bytes > 20 * 1024 * 1024) {
            return new WP_Error('deploy_too_large', 'Deployment exceeds the 20 MB total limit.');
        }
        return true;
    }

    private function is_manageable_path(string $path): bool {
        $path = ltrim(str_replace('\\', '/', rawurldecode($path)), '/');
        if ($path === '' || str_contains($path, '..') || str_contains($path, "\0")) {
            return false;
        }
        return str_starts_with($path, 'plugins/')
            || str_starts_with($path, 'themes/')
            || str_starts_with($path, 'mu-plugins/');
    }

    private function get_plugins_data(): array {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $active = get_option('active_plugins', []);
        $plugins = [];
        foreach (get_plugins() as $file => $data) {
            $slug = dirname($file);
            if ($slug === '.') {
                $slug = basename($file, '.php');
            }
            $plugins[] = [
                'slug' => $slug,
                'file' => $file,
                'name' => $data['Name'],
                'version' => $data['Version'],
                'active' => in_array($file, $active, true),
                'description' => wp_strip_all_tags($data['Description'] ?? ''),
                'requires_php' => $data['RequiresPHP'] ?? '',
                'requires_wp' => $data['RequiresWP'] ?? '',
            ];
        }
        return $plugins;
    }

    private function find_plugin_file(string $slug): ?string {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        foreach (get_plugins() as $file => $data) {
            if (str_starts_with($file, $slug . '/') || $file === $slug . '.php') {
                return $file;
            }
        }
        return null;
    }

    private function post_data_from_request(WP_REST_Request $request, bool $creating): array|WP_Error {
        $data = ['post_type' => 'post'];
        $fields = [
            'title' => 'post_title',
            'content' => 'post_content',
            'excerpt' => 'post_excerpt',
        ];
        foreach ($fields as $parameter => $field) {
            if ($request->has_param($parameter)) {
                $data[$field] = (string) $request->get_param($parameter);
            }
        }
        if ($creating && empty($data['post_title'])) {
            return new WP_Error('missing_title', 'Title is required.');
        }
        if ($request->has_param('status') || $creating) {
            $status = sanitize_key((string) ($request->get_param('status') ?: 'draft'));
            $data['post_status'] = in_array($status, ['publish', 'draft', 'pending', 'private', 'future'], true)
                ? $status
                : 'draft';
        }
        if ($request->has_param('categories')) {
            $data['post_category'] = array_map('intval', (array) $request->get_param('categories'));
        }
        if ($request->has_param('tags')) {
            $data['tags_input'] = array_map('sanitize_text_field', (array) $request->get_param('tags'));
        }
        return $data;
    }

    private function format_post(WP_Post $post, bool $include_content): array {
        $result = [
            'id' => $post->ID,
            'title' => $post->post_title,
            'status' => $post->post_status,
            'date' => get_post_time('c', true, $post),
            'modified' => get_post_modified_time('c', true, $post),
            'url' => get_permalink($post),
            'excerpt' => $post->post_excerpt ?: wp_trim_words(wp_strip_all_tags($post->post_content), 55),
        ];
        if ($include_content) {
            $result['content'] = $post->post_content;
            $result['categories'] = wp_get_post_categories($post->ID);
            $result['tags'] = wp_get_post_tags($post->ID, ['fields' => 'names']);
        }
        return $result;
    }

    private function should_skip_code_path(string $path): bool {
        $path = '/' . strtolower(str_replace('\\', '/', $path)) . '/';
        return str_contains($path, '/backups/')
            || str_contains($path, '/vendor/')
            || str_contains($path, '/node_modules/')
            || str_contains($path, '/.git/');
    }

    private function is_protected_option(string $name): bool {
        return in_array($name, [
            'active_plugins',
            'active_sitewide_plugins',
            'template',
            'stylesheet',
            'siteurl',
            'home',
            'cron',
            'wp_user_roles',
        ], true);
    }

    private function check_wordpress_health(): array {
        $started = microtime(true);
        $url = add_query_arg('shopagg_health', (string) time(), home_url('/'));
        $response = wp_remote_get($url, [
            'timeout' => 15,
            'sslverify' => true,
            'redirection' => 3,
            'headers' => ['Cache-Control' => 'no-cache'],
        ]);
        if (is_wp_error($response)) {
            return [
                'ok' => false,
                'error' => $response->get_error_message(),
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            ];
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body = strtolower((string) wp_remote_retrieve_body($response));
        $fatal_markers = [
            'fatal error',
            'parse error',
            'uncaught error',
            'there has been a critical error',
            'error establishing a database connection',
        ];
        $matched = null;
        foreach ($fatal_markers as $marker) {
            if (str_contains($body, $marker)) {
                $matched = $marker;
                break;
            }
        }

        return [
            'ok' => $status >= 200 && $status < 400 && $matched === null,
            'status_code' => $status,
            'fatal_marker' => $matched,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        ];
    }

    private function record_activity(string $action, array $details, bool $success): void {
        $activity = get_option('shopagg_ai_deployer_activity', []);
        if (!is_array($activity)) {
            $activity = [];
        }
        array_unshift($activity, [
            'time' => gmdate('c'),
            'action' => $action,
            'success' => $success,
            'details' => $details,
            'ip_hash' => isset($_SERVER['REMOTE_ADDR'])
                ? substr(hash_hmac('sha256', (string) $_SERVER['REMOTE_ADDR'], wp_salt('auth')), 0, 12)
                : null,
        ]);
        update_option('shopagg_ai_deployer_activity', array_slice($activity, 0, 100), false);
    }

    private function response(array $data, int $status = 200): WP_REST_Response {
        $response = new WP_REST_Response($data, $status);
        $response->header('Cache-Control', 'private, no-store, no-cache, must-revalidate, max-age=0');
        $response->header('Pragma', 'no-cache');
        $response->header('Expires', '0');
        $response->header('Vary', 'X-ShopAgg-AI-Deployer-Key');
        $response->header('X-LiteSpeed-Cache-Control', 'no-cache');
        return $response;
    }

    private function error(string $message, int $status): WP_REST_Response {
        return $this->response(['success' => false, 'error' => $message], $status);
    }
}

function shopagg_ai_deployer_register_routes(): void {
    (new WB_Deployer_API())->register();
}
