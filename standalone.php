<?php
/**
 * Standalone Emergency Recovery Endpoint
 *
 * This file operates COMPLETELY INDEPENDENTLY from WordPress.
 * It does NOT load wp-load.php, does NOT use any WordPress functions.
 *
 * When WordPress crashes due to a bad deployment, this endpoint remains
 * fully functional — you can revert files, disable plugins, and restore
 * backups without WordPress ever loading.
 *
 * Access: https://yoursite.com/wp-content/plugins/shopagg-ai-deployer/standalone.php?action=xxx
 * Auth:   X-ShopAgg-AI-Deployer-Key header
 *
 * @package SHOPAGG_AI_Deployer
 */

// ---- Bootstrap (no WordPress!) ------------------------------------------------

define('WB_STANDALONE', true);
define('WB_DEPLOYER_DIR_STANDALONE', __DIR__);
define('WB_WP_CONTENT_DIR', dirname(__DIR__, 2));  // __DIR__=plugins/shopagg-ai-deployer → wp-content
define('WB_ABSPATH', dirname(WB_WP_CONTENT_DIR) . '/');

// Load shared pure-PHP components
require_once WB_DEPLOYER_DIR_STANDALONE . '/includes/class-file-ops.php';
require_once WB_DEPLOYER_DIR_STANDALONE . '/includes/class-backup.php';

$file_ops = new WB_Deployer_File_Ops(WB_WP_CONTENT_DIR);
$backup   = new WB_Deployer_Backup($file_ops);

// ---- Authentication -----------------------------------------------------------

function standalone_get_api_key(): string {
    $config = WB_DEPLOYER_DIR_STANDALONE . '/includes/config.php';
    if (file_exists($config)) {
        return (string) include $config;
    }
    return '';
}

function standalone_authenticate(): bool {
    $provided = $_SERVER['HTTP_X_SHOPAGG_AI_DEPLOYER_KEY'] ?? '';
    $expected = standalone_get_api_key();

    if (empty($expected) || empty($provided)) {
        return false;
    }

    return hash_equals($expected, $provided);
}

// ---- Database connection (from wp-config.php) ---------------------------------

function standalone_get_db_config(): ?array {
    $config_file = WB_ABSPATH . 'wp-config.php';

    if (!file_exists($config_file)) {
        return null;
    }

    $content = file_get_contents($config_file);

    $get_define = function(string $name) use ($content): ?string {
        if (preg_match("/define\s*\(\s*['\"]{$name}['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)/", $content, $m)) {
            return $m[1];
        }
        return null;
    };

    $db_name = $get_define('DB_NAME');
    $db_user = $get_define('DB_USER');
    $db_pass = $get_define('DB_PASSWORD');
    $db_host = $get_define('DB_HOST');

    if (!$db_name || !$db_user) {
        return null;
    }

    $port = 3306;
    if (str_contains($db_host, ':')) {
        [$db_host, $port] = explode(':', $db_host, 2);
    }

    return [
        'host'     => $db_host,
        'port'     => (int) $port,
        'name'     => $db_name,
        'user'     => $db_user,
        'password' => $db_pass ?? '',
        'charset'  => 'utf8mb4',
    ];
}

function standalone_get_table_prefix(): string {
    $config_file = WB_ABSPATH . 'wp-config.php';
    if (!file_exists($config_file)) {
        return 'wp_';
    }
    $content = file_get_contents($config_file);
    if (preg_match('/\$table_prefix\s*=\s*[\'"]([^\'"]+)[\'"]/', $content, $m)) {
        return $m[1];
    }
    return 'wp_';
}

function standalone_db_connect(): ?PDO {
    $config = standalone_get_db_config();
    if (!$config) {
        return null;
    }

    try {
        $dsn = "mysql:host={$config['host']};port={$config['port']};dbname={$config['name']};charset={$config['charset']}";
        $pdo = new PDO($dsn, $config['user'], $config['password'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        return $pdo;
    } catch (PDOException $e) {
        return null;
    }
}

// ---- Plugin management via direct DB ------------------------------------------

function standalone_get_active_plugins(PDO $pdo): array {
    $prefix = standalone_get_table_prefix();
    $stmt   = $pdo->prepare("SELECT option_value FROM {$prefix}options WHERE option_name = 'active_plugins'");
    $stmt->execute();
    $row = $stmt->fetch();

    if (!$row) {
        return [];
    }

    $plugins = @unserialize($row['option_value']);
    return is_array($plugins) ? $plugins : [];
}

function standalone_set_active_plugins(PDO $pdo, array $plugins): bool {
    $prefix = standalone_get_table_prefix();
    $value  = serialize($plugins);

    $stmt = $pdo->prepare(
        "INSERT INTO {$prefix}options (option_name, option_value, autoload) VALUES ('active_plugins', :value, 'yes') "
      . "ON DUPLICATE KEY UPDATE option_value = :value2"
    );
    return $stmt->execute(['value' => $value, 'value2' => $value]);
}

function standalone_disable_plugin(PDO $pdo, string $slug): array {
    $active  = standalone_get_active_plugins($pdo);
    $found   = false;
    $removed = [];

    $new_active = [];
    foreach ($active as $file) {
        if (strpos($file, $slug . '/') === 0 || $file === $slug . '.php') {
            $found   = true;
            $removed[] = $file;
        } else {
            $new_active[] = $file;
        }
    }

    if (!$found) {
        return ['success' => false, 'error' => "Plugin '{$slug}' not found in active list."];
    }

    standalone_set_active_plugins($pdo, array_values($new_active));

    return [
        'success' => true,
        'message' => "Plugin '{$slug}' disabled.",
        'removed' => $removed,
    ];
}

/**
 * Swap one plugin entry for another in active_plugins.
 * Used during plugin directory rename / migration.
 */
function standalone_swap_plugin(PDO $pdo, string $old_slug, string $new_slug): array {
    $active = standalone_get_active_plugins($pdo);
    $found  = false;

    $new_active = [];
    foreach ($active as $file) {
        if (strpos($file, $old_slug . '/') === 0 || $file === $old_slug . '.php') {
            $found = true;
            // Derive new filename from old
            $new_file = str_replace($old_slug, $new_slug, $file);
            $new_active[] = $new_file;
        } else {
            $new_active[] = $file;
        }
    }

    if (!$found) {
        return ['success' => false, 'error' => "Plugin '{$old_slug}' not found in active list."];
    }

    standalone_set_active_plugins($pdo, array_values($new_active));

    return [
        'success' => true,
        'message' => "Swapped '{$old_slug}' → '{$new_slug}' in active plugins.",
        'before'  => $active,
        'after'   => $new_active,
    ];
}

function standalone_disable_all_plugins(PDO $pdo): array {
    standalone_set_active_plugins($pdo, []);
    return ['success' => true, 'message' => 'All plugins disabled.'];
}

// ---- Health check -------------------------------------------------------------

function standalone_health_check(): array {
    $url = standalone_detect_site_url();

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_NOBODY         => false,
    ]);

    $body       = curl_exec($ch);
    $http_code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($curl_error) {
        return ['ok' => false, 'error' => "cURL error: {$curl_error}"];
    }

    $has_fatal = stripos($body, 'fatal error') !== false
              || stripos($body, 'parse error') !== false
              || stripos($body, 'uncaught error') !== false
              || stripos($body, 'there has been a critical error') !== false;

    return [
        'ok'          => $http_code >= 200 && $http_code < 500 && !$has_fatal,
        'status_code' => $http_code,
        'has_fatal'   => $has_fatal,
    ];
}

function standalone_detect_site_url(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host . '/';
}

// ---- Routing ------------------------------------------------------------------

function standalone_send_json(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-ShopAgg-AI-Deployer-Standalone: true');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Authenticate first
if (!standalone_authenticate()) {
    standalone_send_json(['success' => false, 'error' => 'Unauthorized. Provide X-ShopAgg-AI-Deployer-Key header.'], 401);
}

// Route to action
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

switch ($action) {

    // ---- Deploy ---------------------------------------------------------------
    case 'deploy':
        if ($method !== 'POST') {
            standalone_send_json(['success' => false, 'error' => 'Use POST.'], 405);
        }

        $input  = json_decode(file_get_contents('php://input'), true);
        $files  = $input['files'] ?? [];
        $auto_backup  = $input['auto_backup'] ?? true;
        $health_check = $input['health_check'] ?? false;

        if (empty($files)) {
            standalone_send_json(['success' => false, 'error' => 'No files provided.'], 400);
        }

        $backup_id = null;
        if ($auto_backup) {
            $paths = array_column($files, 'path');
            $backup_id = $backup->create_snapshot($paths);
        }

        $results = [];
        foreach ($files as $file) {
            $path    = $file['path'] ?? '';
            $content = $file['content'] ?? '';
            if (empty($path)) {
                $results[] = ['success' => false, 'error' => 'Path is required.'];
                continue;
            }
            $results[] = $file_ops->write_file($path, $content);
        }

        $health = null;
        if ($health_check) {
            $health = standalone_health_check();
            if (!$health['ok'] && $backup_id) {
                $backup->restore_snapshot($backup_id);
                $health['auto_rollback'] = true;
                $health['restored_from'] = $backup_id;
            }
        }

        standalone_send_json([
            'success'   => true,
            'results'   => $results,
            'backup_id' => $backup_id,
            'health'    => $health,
        ]);
        break;

    // ---- Read file -------------------------------------------------------------
    case 'read':
        $path = $_GET['path'] ?? '';
        if (empty($path)) {
            standalone_send_json(['success' => false, 'error' => 'path parameter required.'], 400);
        }
        $content = $file_ops->read_file($path);
        if ($content === false) {
            standalone_send_json(['success' => false, 'error' => 'File not found.'], 404);
        }
        standalone_send_json(['success' => true, 'path' => $path, 'content' => $content]);
        break;

    // ---- Write file ------------------------------------------------------------
    case 'write':
        if ($method !== 'POST') {
            standalone_send_json(['success' => false, 'error' => 'Use POST.'], 405);
        }
        $input   = json_decode(file_get_contents('php://input'), true);
        $path    = $input['path'] ?? '';
        $content = $input['content'] ?? '';
        if (empty($path)) {
            standalone_send_json(['success' => false, 'error' => 'path required.'], 400);
        }
        if ($file_ops->file_exists($path)) {
            $backup->create_snapshot([$path]);
        }
        $result = $file_ops->write_file($path, $content);
        standalone_send_json($result, $result['success'] ? 200 : 400);
        break;

    // ---- Delete file -----------------------------------------------------------
    case 'delete':
        $path = $_GET['path'] ?? '';
        if (empty($path)) {
            standalone_send_json(['success' => false, 'error' => 'path parameter required.'], 400);
        }
        if ($file_ops->file_exists($path)) {
            $backup->create_snapshot([$path]);
        }
        $result = $file_ops->delete_file($path);
        standalone_send_json($result, $result['success'] ? 200 : 400);
        break;

    // ---- Delete directory recursively ------------------------------------------
    case 'delete_dir':
        $path = $_GET['path'] ?? '';
        if (empty($path)) {
            standalone_send_json(['success' => false, 'error' => 'path parameter required.'], 400);
        }
        $result = $file_ops->delete_directory($path);
        standalone_send_json($result, $result['success'] ? 200 : 400);
        break;

    // ---- List directory --------------------------------------------------------
    case 'list':
        $path      = $_GET['path'] ?? '';
        $recursive = ($_GET['recursive'] ?? '') === '1';
        if (empty($path)) {
            standalone_send_json(['success' => false, 'error' => 'path parameter required.'], 400);
        }
        $files = $recursive
            ? $file_ops->list_dir_recursive($path)
            : $file_ops->list_dir($path);
        standalone_send_json(['success' => true, 'path' => $path, 'files' => $files]);
        break;

    // ---- Health check ----------------------------------------------------------
    case 'health':
        standalone_send_json(standalone_health_check());
        break;

    // ---- List backups ----------------------------------------------------------
    case 'backups':
        standalone_send_json(['success' => true, 'backups' => $backup->list_snapshots()]);
        break;

    // ---- Restore backup --------------------------------------------------------
    case 'restore':
        $id = $_GET['id'] ?? $_POST['id'] ?? '';
        if (empty($id)) {
            standalone_send_json(['success' => false, 'error' => 'id parameter required.'], 400);
        }
        $result = $backup->restore_snapshot($id);
        standalone_send_json($result, $result['success'] ? 200 : 500);
        break;

    // ---- Delete backup ---------------------------------------------------------
    case 'delete_backup':
        $id = $_GET['id'] ?? $_POST['id'] ?? '';
        if (empty($id)) {
            standalone_send_json(['success' => false, 'error' => 'id parameter required.'], 400);
        }
        $result = $backup->delete_snapshot($id);
        standalone_send_json($result);
        break;

    // ---- Disable plugin (DB direct) --------------------------------------------
    case 'disable_plugin':
        $slug = $_GET['slug'] ?? $_POST['slug'] ?? '';
        if (empty($slug)) {
            standalone_send_json(['success' => false, 'error' => 'slug parameter required.'], 400);
        }
        $pdo = standalone_db_connect();
        if (!$pdo) {
            standalone_send_json(['success' => false, 'error' => 'Cannot connect to database.'], 500);
        }
        standalone_send_json(standalone_disable_plugin($pdo, $slug));
        break;

    // ---- Swap plugin in active list (migration helper) -------------------------
    case 'swap_plugin':
        $old_slug = $_GET['old'] ?? $_POST['old'] ?? '';
        $new_slug = $_GET['new'] ?? $_POST['new'] ?? '';
        if (empty($old_slug) || empty($new_slug)) {
            standalone_send_json(['success' => false, 'error' => 'old and new parameters required.'], 400);
        }
        $pdo = standalone_db_connect();
        if (!$pdo) {
            standalone_send_json(['success' => false, 'error' => 'Cannot connect to database.'], 500);
        }
        standalone_send_json(standalone_swap_plugin($pdo, $old_slug, $new_slug));
        break;

    // ---- Disable all plugins (nuclear option) ----------------------------------
    case 'disable_all_plugins':
        $pdo = standalone_db_connect();
        if (!$pdo) {
            standalone_send_json(['success' => false, 'error' => 'Cannot connect to database.'], 500);
        }
        standalone_send_json(standalone_disable_all_plugins($pdo));
        break;

    // ---- Get plugin/theme code -------------------------------------------------
    case 'get_code':
        $type = $_GET['type'] ?? 'plugin';
        $slug = $_GET['slug'] ?? '';
        if (empty($slug)) {
            standalone_send_json(['success' => false, 'error' => 'slug parameter required.'], 400);
        }
        $prefix    = ($type === 'plugin') ? 'plugins/' : 'themes/';
        $path      = $prefix . $slug;
        $all_files = $file_ops->list_dir_recursive($path);
        $code      = [];
        foreach ($all_files as $file) {
            $ext = pathinfo($file, PATHINFO_EXTENSION);
            if (in_array($ext, ['php', 'css', 'js', 'json', 'txt'], true)) {
                $content = $file_ops->read_file($file);
                if ($content !== false) {
                    $code[$file] = $content;
                }
            }
        }
        standalone_send_json(['success' => true, 'type' => $type, 'slug' => $slug, 'files' => $code]);
        break;

    // ---- Server info -----------------------------------------------------------
    case 'info':
        standalone_send_json([
            'success'     => true,
            'php_version' => PHP_VERSION,
            'standalone'  => true,
            'wp_content'  => WB_WP_CONTENT_DIR,
            'abspath'     => WB_ABSPATH,
            'db_reachable' => standalone_db_connect() !== null,
        ]);
        break;

    // ---- Default ---------------------------------------------------------------
    default:
        standalone_send_json([
            'success'     => true,
            'message'     => 'SHOPAGG AI Deployer Standalone — specify an action.',
            'available_actions' => [
                'deploy'              => 'POST — Deploy files with backup',
                'read'                => 'GET  — Read a file',
                'write'               => 'POST — Write a file',
                'delete'              => 'GET  — Delete a file',
                'delete_dir'          => 'GET  — Recursively delete a directory',
                'list'                => 'GET  — List directory',
                'health'              => 'GET  — WordPress health check',
                'backups'             => 'GET  — List backup snapshots',
                'restore'             => 'POST — Restore from backup',
                'delete_backup'       => 'POST — Delete a backup',
                'disable_plugin'      => 'POST — Disable plugin via DB',
                'swap_plugin'         => 'POST — Swap plugin entry (for migrations)',
                'disable_all_plugins' => 'POST — Disable ALL plugins via DB',
                'get_code'            => 'GET  — Get plugin/theme source code',
                'info'                => 'GET  — Server info',
            ],
        ]);
}
