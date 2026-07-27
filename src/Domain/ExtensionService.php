<?php

namespace ShopAgg\AI_Deployer\Domain;

use ShopAgg\AI_Deployer\Infrastructure\AuditLog;
use ShopAgg\AI_Deployer\Infrastructure\OperationLock;

final class ExtensionService {

    private \WB_Deployer_File_Ops $files;
    private \WB_Deployer_Backup $backups;
    private HealthVerifier $health;
    private AuditLog $audit;
    private OperationLock $lock;

    public function __construct(
        \WB_Deployer_File_Ops $files,
        \WB_Deployer_Backup $backups,
        HealthVerifier $health,
        AuditLog $audit,
        OperationLock $lock
    ) {
        $this->files = $files;
        $this->backups = $backups;
        $this->health = $health;
        $this->audit = $audit;
        $this->lock = $lock;
    }

    public function execute(array $input): array {
        $type = sanitize_key((string) ($input['type'] ?? 'plugin'));
        $action = sanitize_key((string) ($input['action'] ?? 'list'));
        $slug = sanitize_key((string) ($input['slug'] ?? ''));

        if ($action === 'list') {
            return $type === 'theme' ? $this->listThemes() : $this->listPlugins();
        }
        if (!$this->lock->acquire('extensions')) {
            return $this->error('extension_locked', 'Another extension operation is already running.');
        }
        try {
            if ($type === 'theme' && $action === 'activate') {
                return $this->activateTheme($slug);
            }
            if ($type !== 'plugin') {
                return $this->error('unsupported_action', 'Only theme activation is supported.');
            }
            return match ($action) {
                'activate' => $this->activatePlugin($slug),
                'deactivate' => $this->deactivatePlugin($slug),
                'delete' => $this->deletePlugin($slug),
                'update' => $this->updatePlugin($slug, !empty($input['force_check'])),
                default => $this->error('unsupported_action', 'Unsupported extension action.'),
            };
        } finally {
            $this->lock->release();
        }
    }

    private function listPlugins(): array {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $items = [];
        foreach (get_plugins() as $file => $data) {
            $slug = dirname($file) === '.' ? basename($file, '.php') : dirname($file);
            $items[] = [
                'slug' => $slug,
                'file' => $file,
                'name' => $data['Name'],
                'version' => $data['Version'],
                'active' => is_plugin_active($file),
            ];
        }
        return ['success' => true, 'type' => 'plugin', 'items' => $items];
    }

    private function listThemes(): array {
        $items = [];
        $active = get_option('stylesheet');
        foreach (wp_get_themes() as $slug => $theme) {
            $items[] = [
                'slug' => $slug,
                'name' => $theme->get('Name'),
                'version' => $theme->get('Version'),
                'active' => $active === $slug,
                'parent' => $theme->parent() ? $theme->parent()->get_stylesheet() : null,
            ];
        }
        return ['success' => true, 'type' => 'theme', 'items' => $items];
    }

    private function activatePlugin(string $slug): array {
        $file = $this->findPlugin($slug);
        if ($file === null) {
            return $this->error('plugin_not_found', 'Plugin not found.');
        }
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $result = activate_plugin($file, '', false, false);
        if (is_wp_error($result)) {
            return $this->finish('activate_plugin', $slug, $this->error('activation_failed', $result->get_error_message()));
        }
        $health = $this->health->verify();
        if (empty($health['ok'])) {
            deactivate_plugins($file, true, false);
            return $this->finish('activate_plugin', $slug, [
                'success' => false,
                'code' => 'health_failed',
                'error' => 'The plugin was deactivated because health verification failed.',
                'health' => $health,
            ]);
        }
        return $this->finish('activate_plugin', $slug, ['success' => true, 'file' => $file, 'health' => $health]);
    }

    private function deactivatePlugin(string $slug): array {
        $file = $this->findPlugin($slug);
        if ($file === null) {
            return $this->error('plugin_not_found', 'Plugin not found.');
        }
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        deactivate_plugins($file, false, false);
        return $this->finish('deactivate_plugin', $slug, ['success' => !is_plugin_active($file), 'file' => $file]);
    }

    private function activateTheme(string $slug): array {
        $theme = wp_get_theme($slug);
        if (!$theme->exists() || $theme->errors()) {
            return $this->error('theme_not_found', 'Theme not found or invalid.');
        }
        $previous = (string) get_option('stylesheet');
        switch_theme($slug);
        $health = $this->health->verify();
        if (empty($health['ok'])) {
            switch_theme($previous);
            return $this->finish('activate_theme', $slug, [
                'success' => false,
                'code' => 'health_failed',
                'error' => 'The previous theme was restored because health verification failed.',
                'health' => $health,
            ]);
        }
        return $this->finish('activate_theme', $slug, ['success' => true, 'health' => $health]);
    }

    private function deletePlugin(string $slug): array {
        $file = $this->findPlugin($slug);
        if ($file === null) {
            return $this->error('plugin_not_found', 'Plugin not found.');
        }
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $wasActive = is_plugin_active($file);
        $target = dirname($file) === '.' ? 'plugins/' . $file : 'plugins/' . dirname($file);
        $paths = str_ends_with($target, '.php') ? [$target] : $this->files->list_dir_recursive($target);
        $backupId = $this->backups->create_snapshot($paths, 'delete-plugin:' . $slug);
        if ($backupId === null) {
            return $this->error('backup_failed', 'A complete backup could not be created.');
        }
        if ($wasActive) {
            deactivate_plugins($file, false, false);
        }
        $result = str_ends_with($target, '.php')
            ? $this->files->delete_file($target)
            : $this->files->delete_directory($target);
        if (empty($result['success'])) {
            $this->backups->restore_snapshot($backupId);
        }
        $result['backup_id'] = $backupId;
        return $this->finish('delete_plugin', $slug, $result);
    }

    private function updatePlugin(string $slug, bool $force): array {
        $file = $this->findPlugin($slug);
        if ($file === null) {
            return $this->error('plugin_not_found', 'Plugin not found.');
        }
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/update.php';
        if ($force) {
            delete_site_transient('update_plugins');
            wp_update_plugins();
        }
        $updates = get_site_transient('update_plugins');
        $update = is_object($updates) && isset($updates->response[$file]) ? $updates->response[$file] : null;
        if (!is_object($update) || empty($update->package)) {
            return $this->error('update_unavailable', 'No downloadable update is available.');
        }
        $requiresPhp = (string) ($update->requires_php ?? '');
        $requiresWp = (string) ($update->requires ?? '');
        if (($requiresPhp && version_compare(PHP_VERSION, $requiresPhp, '<'))
            || ($requiresWp && version_compare(get_bloginfo('version'), $requiresWp, '<'))) {
            return $this->error('update_incompatible', 'The update is incompatible with this PHP or WordPress version.');
        }
        $target = dirname($file) === '.' ? 'plugins/' . $file : 'plugins/' . dirname($file);
        $paths = str_ends_with($target, '.php') ? [$target] : $this->files->list_dir_recursive($target);
        $backupId = $this->backups->create_snapshot($paths, 'update-plugin:' . $slug);
        if ($backupId === null) {
            return $this->error('backup_failed', 'A complete backup could not be created.');
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        $wasActive = is_plugin_active($file);
        $skin = new \Automatic_Upgrader_Skin();
        $upgrader = new \Plugin_Upgrader($skin);
        $upgraded = $upgrader->upgrade($file, ['clear_update_cache' => false]);
        if (is_wp_error($upgraded) || $upgraded !== true) {
            $rollback = $this->rollbackPlugin($target, $backupId, $file, $wasActive);
            $message = is_wp_error($upgraded) ? $upgraded->get_error_message() : 'WordPress did not complete the update.';
            return $this->finish('update_plugin', $slug, [
                'success' => false,
                'code' => 'update_failed',
                'error' => $message,
                'backup_id' => $backupId,
                'rollback' => $rollback,
            ]);
        }
        wp_clean_plugins_cache(true);
        if ($wasActive && !is_plugin_active($file)) {
            $activation = activate_plugin($file, '', false, false);
            if (is_wp_error($activation)) {
                $rollback = $this->rollbackPlugin($target, $backupId, $file, true);
                return $this->finish('update_plugin', $slug, [
                    'success' => false,
                    'code' => 'reactivation_failed',
                    'error' => $activation->get_error_message(),
                    'backup_id' => $backupId,
                    'rollback' => $rollback,
                ]);
            }
        }
        $health = $this->health->verify();
        if (empty($health['ok'])) {
            $rollback = $this->rollbackPlugin($target, $backupId, $file, $wasActive);
            return $this->finish('update_plugin', $slug, [
                'success' => false,
                'code' => 'health_failed',
                'error' => 'The update was rolled back because health verification failed.',
                'backup_id' => $backupId,
                'health' => $health,
                'rollback' => $rollback,
            ]);
        }
        $plugins = get_plugins();
        return $this->finish('update_plugin', $slug, [
            'success' => true,
            'file' => $file,
            'version' => $plugins[$file]['Version'] ?? (string) ($update->new_version ?? ''),
            'backup_id' => $backupId,
            'health' => $health,
        ]);
    }

    private function rollbackPlugin(string $target, string $backupId, string $file, bool $activate): array {
        $deleted = str_ends_with($target, '.php')
            ? $this->files->delete_file($target)
            : $this->files->delete_directory($target);
        if (empty($deleted['success'])) {
            return ['success' => false, 'error' => 'Could not remove the failed update.', 'delete' => $deleted];
        }
        $restored = $this->backups->restore_snapshot($backupId);
        wp_clean_plugins_cache(false);
        if ($activate && !is_plugin_active($file)) {
            $activation = activate_plugin($file, '', false, false);
            if (is_wp_error($activation)) {
                $restored['success'] = false;
                $restored['activation_error'] = $activation->get_error_message();
            }
        }
        return $restored;
    }

    private function findPlugin(string $slug): ?string {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        foreach (get_plugins() as $file => $data) {
            if ($file === $slug . '.php' || str_starts_with($file, $slug . '/')) {
                return $file;
            }
        }
        return null;
    }

    private function finish(string $action, string $slug, array $result): array {
        $this->audit->record($action, ['slug' => $slug] + array_intersect_key($result, array_flip(['backup_id', 'code'])), !empty($result['success']));
        return $result;
    }

    private function error(string $code, string $message): array {
        return ['success' => false, 'code' => $code, 'error' => $message];
    }
}
