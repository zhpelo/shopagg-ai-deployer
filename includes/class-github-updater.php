<?php
/**
 * GitHub-backed update metadata for SHOPAGG AI Deployer.
 *
 * @package SHOPAGG_AI_Deployer
 */

class SHOPAGG_AI_Deployer_GitHub_Updater {

    private const REPOSITORY = 'zhpelo/shopagg-ai-deployer';
    private const BRANCH = 'main';
    private const SLUG = 'shopagg-ai-deployer';
    private const PLUGIN_FILE = 'shopagg-ai-deployer/shopagg-ai-deployer.php';
    private const CACHE_KEY = 'shopagg_ai_deployer_github_metadata';

    public function register(): void {
        add_filter('pre_set_site_transient_update_plugins', [$this, 'provide_update']);
        add_filter('plugins_api', [$this, 'provide_plugin_information'], 20, 3);
        add_filter('upgrader_source_selection', [$this, 'normalize_archive_directory'], 10, 4);
        add_filter('plugin_row_meta', [$this, 'add_repository_link'], 10, 2);
    }

    public function provide_update(mixed $transient): mixed {
        if (!is_object($transient) || empty($transient->checked[self::PLUGIN_FILE])) {
            return $transient;
        }

        $metadata = $this->get_remote_metadata();
        if ($metadata === null) {
            return $transient;
        }

        $update = $this->to_update_object($metadata);
        if (version_compare($metadata['version'], SHOPAGG_AI_DEPLOYER_VERSION, '>')) {
            $transient->response[self::PLUGIN_FILE] = $update;
            if (isset($transient->no_update[self::PLUGIN_FILE])) {
                unset($transient->no_update[self::PLUGIN_FILE]);
            }
        } else {
            $transient->no_update[self::PLUGIN_FILE] = $update;
            if (isset($transient->response[self::PLUGIN_FILE])) {
                unset($transient->response[self::PLUGIN_FILE]);
            }
        }

        return $transient;
    }

    public function provide_plugin_information(mixed $result, string $action, mixed $args): mixed {
        if ($action !== 'plugin_information' || !is_object($args) || ($args->slug ?? '') !== self::SLUG) {
            return $result;
        }

        $metadata = $this->get_remote_metadata();
        if ($metadata === null) {
            return $result;
        }

        return (object) [
            'name' => 'SHOPAGG AI Deployer',
            'slug' => self::SLUG,
            'version' => $metadata['version'],
            'author' => '<a href="https://zhuangpenglong.com/">庄朋龙</a>',
            'homepage' => 'https://github.com/' . self::REPOSITORY,
            'requires' => $metadata['requires_wordpress'],
            'tested' => $metadata['tested_wordpress'],
            'requires_php' => $metadata['requires_php'],
            'download_link' => $metadata['package'],
            'last_updated' => $metadata['published_at'],
            'sections' => [
                'description' => '通过认证 REST API 安全部署 WordPress 代码，并提供自动备份、健康检查、回滚和独立急救通道。',
                'changelog' => sprintf(
                    'GitHub main 分支当前版本：%s。更新包固定到提交 %s。',
                    esc_html($metadata['version']),
                    esc_html(substr($metadata['commit'], 0, 12))
                ),
            ],
        ];
    }

    public function normalize_archive_directory(
        string|WP_Error $source,
        string $remote_source,
        mixed $upgrader,
        array $hook_extra
    ): string|WP_Error {
        if (is_wp_error($source) || ($hook_extra['plugin'] ?? '') !== self::PLUGIN_FILE) {
            return $source;
        }

        $source_path = untrailingslashit($source);
        if (basename($source_path) === self::SLUG) {
            return trailingslashit($source_path);
        }

        global $wp_filesystem;
        if (!$wp_filesystem) {
            return new WP_Error(
                'shopagg_github_filesystem_unavailable',
                'WordPress filesystem access is unavailable for the GitHub update.'
            );
        }

        $normalized = untrailingslashit($remote_source) . '/' . self::SLUG;
        if ($wp_filesystem->exists($normalized)) {
            $wp_filesystem->delete($normalized, true);
        }
        if (!$wp_filesystem->move($source_path, $normalized, true)) {
            return new WP_Error(
                'shopagg_github_archive_normalization_failed',
                'Could not normalize the GitHub archive directory.'
            );
        }

        return trailingslashit($normalized);
    }

    public function add_repository_link(array $links, string $plugin_file): array {
        if ($plugin_file === self::PLUGIN_FILE) {
            $links[] = '<a href="https://github.com/' . esc_attr(self::REPOSITORY)
                . '" target="_blank" rel="noopener noreferrer">GitHub</a>';
        }
        return $links;
    }

    private function get_remote_metadata(): ?array {
        $cached = get_site_transient(self::CACHE_KEY);
        if (is_array($cached)) {
            return empty($cached['error']) ? $cached : null;
        }

        $commit_response = wp_remote_get(
            'https://api.github.com/repos/' . self::REPOSITORY . '/commits/' . self::BRANCH,
            $this->request_args()
        );
        if (is_wp_error($commit_response) || wp_remote_retrieve_response_code($commit_response) !== 200) {
            $this->cache_error('Could not retrieve the GitHub commit.');
            return null;
        }

        $commit_data = json_decode((string) wp_remote_retrieve_body($commit_response), true);
        $commit = sanitize_text_field((string) ($commit_data['sha'] ?? ''));
        if (!preg_match('/^[a-f0-9]{40}$/', $commit)) {
            $this->cache_error('GitHub returned an invalid commit.');
            return null;
        }

        $plugin_response = wp_remote_get(
            'https://raw.githubusercontent.com/' . self::REPOSITORY . '/' . $commit . '/shopagg-ai-deployer.php',
            $this->request_args()
        );
        if (is_wp_error($plugin_response) || wp_remote_retrieve_response_code($plugin_response) !== 200) {
            $this->cache_error('Could not retrieve the GitHub plugin metadata.');
            return null;
        }

        $headers = $this->parse_plugin_headers((string) wp_remote_retrieve_body($plugin_response));
        if ($headers['version'] === '') {
            $this->cache_error('The GitHub plugin version is missing.');
            return null;
        }

        $metadata = [
            'version' => $headers['version'],
            'requires_wordpress' => $headers['requires_wordpress'] ?: '6.5',
            'requires_php' => $headers['requires_php'] ?: '8.0',
            'tested_wordpress' => '7.0',
            'commit' => $commit,
            'package' => 'https://github.com/' . self::REPOSITORY . '/archive/' . $commit . '.zip',
            'published_at' => sanitize_text_field((string) ($commit_data['commit']['committer']['date'] ?? '')),
        ];
        set_site_transient(self::CACHE_KEY, $metadata, 6 * HOUR_IN_SECONDS);
        return $metadata;
    }

    private function request_args(): array {
        return [
            'timeout' => 12,
            'redirection' => 3,
            'sslverify' => true,
            'headers' => [
                'Accept' => 'application/vnd.github+json',
                'User-Agent' => 'SHOPAGG-AI-Deployer/' . SHOPAGG_AI_DEPLOYER_VERSION,
            ],
        ];
    }

    private function parse_plugin_headers(string $source): array {
        return [
            'version' => $this->header_value($source, 'Version'),
            'requires_wordpress' => $this->header_value($source, 'Requires at least'),
            'requires_php' => $this->header_value($source, 'Requires PHP'),
        ];
    }

    private function header_value(string $source, string $name): string {
        $pattern = '/^[ \t\/*#@]*' . preg_quote($name, '/') . ':\s*(.+)$/mi';
        if (!preg_match($pattern, $source, $match)) {
            return '';
        }
        return sanitize_text_field(trim((string) $match[1]));
    }

    private function to_update_object(array $metadata): object {
        return (object) [
            'id' => 'https://github.com/' . self::REPOSITORY,
            'slug' => self::SLUG,
            'plugin' => self::PLUGIN_FILE,
            'new_version' => $metadata['version'],
            'url' => 'https://github.com/' . self::REPOSITORY,
            'package' => $metadata['package'],
            'requires' => $metadata['requires_wordpress'],
            'tested' => $metadata['tested_wordpress'],
            'requires_php' => $metadata['requires_php'],
        ];
    }

    private function cache_error(string $message): void {
        set_site_transient(self::CACHE_KEY, ['error' => $message], 30 * MINUTE_IN_SECONDS);
    }
}

function shopagg_ai_deployer_github_updater_bootstrap(): void {
    (new SHOPAGG_AI_Deployer_GitHub_Updater())->register();
}
