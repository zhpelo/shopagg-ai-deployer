<?php
/**
 * Plugin Name: SHOPAGG AI Deployer
 * Plugin URI:  https://www.shopagg.com/
 * Description: One-key, full-control WordPress access for AI through Abilities, REST, transactional deployment, and independent recovery.
 * Version:     2.0.0
 * Requires at least: 6.9
 * Requires PHP: 8.0
 * Update URI:  https://github.com/zhpelo/shopagg-ai-deployer
 * Author:      庄朋龙
 * Author URI:  https://zhuangpenglong.com/
 * License:     GPL-2.0+
 * Text Domain: shopagg-ai-deployer
 *
 * @package SHOPAGG_AI_Deployer
 */

defined('ABSPATH') || exit;

define('SHOPAGG_AI_DEPLOYER_VERSION', '2.0.0');
define('SHOPAGG_AI_DEPLOYER_DIR', plugin_dir_path(__FILE__));
define('SHOPAGG_AI_DEPLOYER_URL', plugin_dir_url(__FILE__));
define('SHOPAGG_AI_DEPLOYER_REST_NS', 'shopagg-ai-deployer/v1');

spl_autoload_register(static function (string $class): void {
    $prefix = 'ShopAgg\\AI_Deployer\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = SHOPAGG_AI_DEPLOYER_DIR . 'src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

use ShopAgg\AI_Deployer\Application\ServiceContainer;
use ShopAgg\AI_Deployer\Infrastructure\Runtime;
use ShopAgg\AI_Deployer\Integration\Abilities;

if (!defined('SHOPAGG_AI_DEPLOYER_DATA_DIR')) {
    define('SHOPAGG_AI_DEPLOYER_DATA_DIR', Runtime::defaultDataDir());
}
define('SHOPAGG_AI_DEPLOYER_BACKUP_DIR', SHOPAGG_AI_DEPLOYER_DATA_DIR . '/backups');
define('SHOPAGG_AI_DEPLOYER_CONFIG_FILE', SHOPAGG_AI_DEPLOYER_DATA_DIR . '/credentials.php');

register_activation_hook(__FILE__, 'shopagg_ai_deployer_activate');

function shopagg_ai_deployer_activate(): void {
    Runtime::ensure(home_url('/'));
    update_option('shopagg_ai_deployer_version', SHOPAGG_AI_DEPLOYER_VERSION, false);
}

function shopagg_ai_deployer_maybe_upgrade(): void {
    $installed = (string) get_option('shopagg_ai_deployer_version', '');
    $credentials = Runtime::readCredentials();
    if ($installed !== SHOPAGG_AI_DEPLOYER_VERSION
        || !is_file(SHOPAGG_AI_DEPLOYER_CONFIG_FILE)
        || Runtime::credential('api_key') === ''
        || isset($credentials['legacy_api_key'])
        || isset($credentials['recovery_key'])) {
        Runtime::ensure(home_url('/'));
        update_option('shopagg_ai_deployer_version', SHOPAGG_AI_DEPLOYER_VERSION, false);
    }
    shopagg_ai_deployer_remove_obsolete_access_model();
}

/**
 * Remove roles and scoped capabilities created by early 2.0 development builds.
 */
function shopagg_ai_deployer_remove_obsolete_access_model(): void {
    if ((string) get_option('shopagg_ai_deployer_access_model', '') === 'single-key') {
        return;
    }
    remove_role('shopagg_ai_operator');
    $administrator = get_role('administrator');
    if ($administrator) {
        foreach (['shopagg_read_code', 'shopagg_deploy_code', 'shopagg_manage_extensions', 'shopagg_ai_control'] as $capability) {
            $administrator->remove_cap($capability);
        }
    }
    update_option('shopagg_ai_deployer_access_model', 'single-key', false);
}

/**
 * Single full-control credential shared by Abilities, REST, and Recovery.
 */
function shopagg_ai_deployer_get_api_key(): string {
    return Runtime::credential('api_key');
}

function shopagg_ai_deployer_regenerate_api_key(): string {
    return Runtime::rotateCredential('api_key');
}

function shopagg_ai_deployer_has_api_access(): bool {
    if (is_user_logged_in() && current_user_can('manage_options')) {
        return true;
    }
    $provided = trim((string) ($_SERVER['HTTP_X_SHOPAGG_AI_DEPLOYER_KEY'] ?? ''));
    $expected = shopagg_ai_deployer_get_api_key();
    return $provided !== '' && $expected !== '' && hash_equals($expected, $provided);
}

function shopagg_ai_deployer_control_user_id(): int {
    $users = get_users([
        'role' => 'administrator',
        'number' => 1,
        'orderby' => 'ID',
        'order' => 'ASC',
        'fields' => 'ID',
    ]);
    return isset($users[0]) ? (int) $users[0] : 1;
}

add_filter('determine_current_user', static function ($userId) {
    if ($userId || !defined('REST_REQUEST') || !REST_REQUEST) {
        return $userId;
    }
    $provided = trim((string) ($_SERVER['HTTP_X_SHOPAGG_AI_DEPLOYER_KEY'] ?? ''));
    $expected = shopagg_ai_deployer_get_api_key();
    return $provided !== '' && $expected !== '' && hash_equals($expected, $provided)
        ? shopagg_ai_deployer_control_user_id()
        : $userId;
}, 30);

shopagg_ai_deployer_maybe_upgrade();

$abilities = new Abilities(ServiceContainer::instance());
$abilities->hook();

add_action('rest_api_init', static function (): void {
    require_once SHOPAGG_AI_DEPLOYER_DIR . 'includes/class-api.php';
    shopagg_ai_deployer_register_routes();
});

if (is_admin()) {
    require_once SHOPAGG_AI_DEPLOYER_DIR . 'includes/class-admin.php';
    shopagg_ai_deployer_admin_bootstrap();
}

if (is_admin() || wp_doing_cron() || (defined('WP_CLI') && WP_CLI)) {
    require_once SHOPAGG_AI_DEPLOYER_DIR . 'includes/class-github-updater.php';
    shopagg_ai_deployer_github_updater_bootstrap();
}

add_filter('rest_post_dispatch', static function ($response, WP_REST_Server $server, WP_REST_Request $request) {
    $route = $request->get_route();
    if (!str_starts_with($route, '/' . SHOPAGG_AI_DEPLOYER_REST_NS . '/')
        && !str_starts_with($route, '/wp-abilities/v1/')) {
        return $response;
    }
    if (!defined('DONOTCACHEPAGE')) {
        define('DONOTCACHEPAGE', true);
    }
    do_action('litespeed_control_set_nocache', 'SHOPAGG AI control API');
    if (is_object($response) && method_exists($response, 'header')) {
        $response->header('Cache-Control', 'private, no-store, no-cache, must-revalidate, max-age=0');
        $response->header('Pragma', 'no-cache');
        $response->header('Expires', '0');
        $response->header('Vary', 'Authorization, X-ShopAgg-AI-Deployer-Key');
        $response->header('X-LiteSpeed-Cache-Control', 'no-cache');
    }
    return $response;
}, 10, 3);

add_filter('plugin_action_links_' . plugin_basename(__FILE__), static function (array $links): array {
    array_unshift(
        $links,
        '<a href="' . esc_url(admin_url('admin.php?page=shopagg-ai-deployer')) . '">AI 连接</a>',
        '<a href="' . esc_url(admin_url('admin.php?page=shopagg-ai-deployer-settings')) . '">运行状态</a>'
    );
    return $links;
});
