<?php
/**
 * Minimal break-glass recovery endpoint. It intentionally does not load WordPress.
 *
 * @package SHOPAGG_AI_Deployer
 */

define('WB_STANDALONE', true);
define('WB_DEPLOYER_DIR_STANDALONE', __DIR__);
define('WB_WP_CONTENT_DIR', dirname(__DIR__, 2));
define('WB_ABSPATH', dirname(WB_WP_CONTENT_DIR) . '/');

$configured_data_dir = getenv('SHOPAGG_AI_DEPLOYER_DATA_DIR');
$wp_config_source = @file_get_contents(WB_ABSPATH . 'wp-config.php');
$configured_constant = '';
if (is_string($wp_config_source)
    && preg_match(
        "/define\\s*\\(\\s*['\"]SHOPAGG_AI_DEPLOYER_DATA_DIR['\"]\\s*,\\s*['\"]([^'\"]+)['\"]\\s*\\)/",
        $wp_config_source,
        $configured_match
    )) {
    $configured_constant = $configured_match[1];
}
define(
    'SHOPAGG_AI_DEPLOYER_DATA_DIR',
    is_string($configured_data_dir) && trim($configured_data_dir) !== ''
        ? rtrim($configured_data_dir, '/\\')
        : ($configured_constant !== '' ? rtrim($configured_constant, '/\\') : WB_WP_CONTENT_DIR . '/.shopagg-ai-deployer')
);
define('SHOPAGG_AI_DEPLOYER_BACKUP_DIR', SHOPAGG_AI_DEPLOYER_DATA_DIR . '/backups');

require_once WB_DEPLOYER_DIR_STANDALONE . '/includes/class-file-ops.php';
require_once WB_DEPLOYER_DIR_STANDALONE . '/includes/class-backup.php';

$file_ops = new WB_Deployer_File_Ops(WB_WP_CONTENT_DIR);
$backup = new WB_Deployer_Backup($file_ops, SHOPAGG_AI_DEPLOYER_BACKUP_DIR);

function standalone_send_json(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-ShopAgg-AI-Deployer-Recovery: true');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function standalone_credentials(): array {
    $file = SHOPAGG_AI_DEPLOYER_DATA_DIR . '/credentials.php';
    if (!is_file($file) || !is_readable($file)) {
        return [];
    }
    $value = include $file;
    return is_array($value) ? $value : [];
}

function standalone_client_id(): string {
    return substr(hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown')), 0, 24);
}

function standalone_authenticate(): bool {
    $credentials = standalone_credentials();
    $expected = (string) ($credentials['api_key'] ?? '');
    $provided = trim((string) ($_SERVER['HTTP_X_SHOPAGG_AI_DEPLOYER_KEY'] ?? ''));
    return $expected !== '' && $provided !== '' && hash_equals($expected, $provided);
}

function standalone_audit(string $action, array $details, bool $success): void {
    $file = SHOPAGG_AI_DEPLOYER_DATA_DIR . '/logs/recovery.jsonl';
    $entry = [
        'time' => gmdate('c'),
        'action' => $action,
        'success' => $success,
        'details' => $details,
        'client' => standalone_client_id(),
    ];
    @file_put_contents($file, json_encode($entry, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
    @chmod($file, 0600);
}

function standalone_get_db_config(): ?array {
    $file = WB_ABSPATH . 'wp-config.php';
    if (!is_file($file) || !is_readable($file)) {
        return null;
    }
    $content = (string) file_get_contents($file);
    $read = static function (string $name) use ($content): ?string {
        $pattern = "/define\\s*\\(\\s*['\"]" . preg_quote($name, '/') . "['\"]\\s*,\\s*['\"]([^'\"]*)['\"]\\s*\\)/";
        return preg_match($pattern, $content, $match) ? $match[1] : null;
    };
    $name = $read('DB_NAME');
    $user = $read('DB_USER');
    $host = $read('DB_HOST') ?: 'localhost';
    if ($name === null || $user === null) {
        return null;
    }
    $port = 3306;
    if (preg_match('/^([^:]+):(\d+)$/', $host, $match)) {
        $host = $match[1];
        $port = (int) $match[2];
    }
    return [
        'host' => $host,
        'port' => $port,
        'name' => $name,
        'user' => $user,
        'password' => $read('DB_PASSWORD') ?? '',
    ];
}

function standalone_table_prefix(): ?string {
    $content = @file_get_contents(WB_ABSPATH . 'wp-config.php');
    if (!is_string($content) || !preg_match('/\$table_prefix\s*=\s*[\'\"]([^\'\"]+)[\'\"]/', $content, $match)) {
        return 'wp_';
    }
    return preg_match('/^[A-Za-z0-9_]+$/', $match[1]) ? $match[1] : null;
}

function standalone_db(): ?PDO {
    $config = standalone_get_db_config();
    if (!$config || !standalone_table_prefix()) {
        return null;
    }
    try {
        return new PDO(
            "mysql:host={$config['host']};port={$config['port']};dbname={$config['name']};charset=utf8mb4",
            $config['user'],
            $config['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    } catch (Throwable $error) {
        return null;
    }
}

function standalone_disable_plugin(PDO $database, string $slug): array {
    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $slug)) {
        return ['success' => false, 'error' => 'Invalid plugin slug.'];
    }
    $prefix = standalone_table_prefix();
    if ($prefix === null) {
        return ['success' => false, 'error' => 'Invalid table prefix.'];
    }
    $statement = $database->prepare("SELECT option_value FROM {$prefix}options WHERE option_name = 'active_plugins'");
    $statement->execute();
    $row = $statement->fetch();
    $active = $row ? @unserialize($row['option_value'], ['allowed_classes' => false]) : [];
    $active = is_array($active) ? $active : [];
    $removed = [];
    $remaining = [];
    foreach ($active as $file) {
        if (str_starts_with($file, $slug . '/') || $file === $slug . '.php') {
            $removed[] = $file;
        } else {
            $remaining[] = $file;
        }
    }
    if (!$removed) {
        return ['success' => false, 'error' => 'Plugin is not active.'];
    }
    $update = $database->prepare("UPDATE {$prefix}options SET option_value = :value WHERE option_name = 'active_plugins'");
    $success = $update->execute(['value' => serialize(array_values($remaining))]);
    return ['success' => $success, 'slug' => $slug, 'removed' => $removed];
}

function standalone_health(): array {
    $credentials = standalone_credentials();
    $url = (string) ($credentials['site_url'] ?? '');
    if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
        return ['ok' => false, 'error' => 'A trusted site URL is not configured.'];
    }
    $handle = curl_init($url);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
    ]);
    $body = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    $error = curl_error($handle);
    curl_close($handle);
    if ($error !== '') {
        return ['ok' => false, 'status_code' => $status, 'error' => $error];
    }
    $body = strtolower(is_string($body) ? $body : '');
    $fatal = null;
    foreach (['fatal error', 'parse error', 'uncaught error', 'there has been a critical error'] as $marker) {
        if (str_contains($body, $marker)) {
            $fatal = $marker;
            break;
        }
    }
    return ['ok' => $status >= 200 && $status < 400 && $fatal === null, 'status_code' => $status, 'fatal_marker' => $fatal];
}

if (!standalone_authenticate()) {
    standalone_send_json([
        'success' => false,
        'error' => 'Unauthorized. Provide X-ShopAgg-AI-Deployer-Key.',
    ], 401);
}

$action = (string) ($_GET['action'] ?? $_POST['action'] ?? '');
$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($action === 'health' && $method === 'GET') {
    standalone_send_json(standalone_health());
}
if ($action === 'backups' && $method === 'GET') {
    standalone_send_json(['success' => true, 'backups' => $backup->list_snapshots()]);
}
if ($action === 'restore' && $method === 'POST') {
    $id = (string) ($_GET['id'] ?? $_POST['id'] ?? '');
    $result = $backup->restore_snapshot($id);
    standalone_audit('restore', ['id' => $id], !empty($result['success']));
    standalone_send_json($result, !empty($result['success']) ? 200 : 500);
}
if ($action === 'disable_plugin' && $method === 'POST') {
    $slug = (string) ($_GET['slug'] ?? $_POST['slug'] ?? '');
    $database = standalone_db();
    if (!$database) {
        standalone_send_json(['success' => false, 'error' => 'Cannot connect to the WordPress database.'], 500);
    }
    $result = standalone_disable_plugin($database, $slug);
    standalone_audit('disable_plugin', ['slug' => $slug], !empty($result['success']));
    standalone_send_json($result, !empty($result['success']) ? 200 : 400);
}

standalone_send_json([
    'success' => false,
    'error' => 'Unknown action or HTTP method.',
    'available_actions' => [
        'GET health',
        'GET backups',
        'POST restore&id=BACKUP_ID',
        'POST disable_plugin&slug=PLUGIN_SLUG',
    ],
], 405);
