<?php

namespace ShopAgg\AI_Deployer\Integration;

use ShopAgg\AI_Deployer\Application\ServiceContainer;

final class Abilities {

    private ServiceContainer $services;

    public function __construct(ServiceContainer $services) {
        $this->services = $services;
    }

    public function hook(): void {
        add_action('wp_abilities_api_categories_init', [$this, 'registerCategories']);
        add_action('wp_abilities_api_init', [$this, 'registerAbilities']);
    }

    public function registerCategories(): void {
        if (!function_exists('wp_register_ability_category')) {
            return;
        }
        wp_register_ability_category('shopagg-site', [
            'label' => __('SHOPAGG Site', 'shopagg-ai-deployer'),
            'description' => __('Inspect and verify the WordPress site.', 'shopagg-ai-deployer'),
        ]);
        wp_register_ability_category('shopagg-code', [
            'label' => __('SHOPAGG Code Workspace', 'shopagg-ai-deployer'),
            'description' => __('Read, search, preview, and transactionally deploy WordPress code.', 'shopagg-ai-deployer'),
        ]);
        wp_register_ability_category('shopagg-wordpress', [
            'label' => __('SHOPAGG WordPress Resources', 'shopagg-ai-deployer'),
            'description' => __('Access WordPress and WooCommerce resources through their native REST controllers.', 'shopagg-ai-deployer'),
        ]);
    }

    public function registerAbilities(): void {
        if (!function_exists('wp_register_ability')) {
            return;
        }

        $this->register('site-inspect', 'Inspect site',
            'Return a compact environment, extension, backup, and AI connection summary.',
            'shopagg-site', null,
            fn(): array => $this->siteInspect(),
            static fn(): bool => shopagg_ai_deployer_has_api_access(), true, false, true);

        $this->register('site-verify', 'Verify site health',
            'Probe the homepage and optional same-site paths for HTTP or fatal PHP failures.',
            'shopagg-site', $this->objectSchema([
                'paths' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 9],
            ]),
            fn($input): array => $this->services->health()->verify((array) (($input['paths'] ?? []))),
            static fn(): bool => shopagg_ai_deployer_has_api_access(), true, false, false);

        $this->register('workspace-tree', 'List code workspace',
            'List code files with sizes, timestamps, and hashes without returning file contents.',
            'shopagg-code', $this->objectSchema([
                'path' => ['type' => 'string', 'default' => 'plugins'],
                'offset' => ['type' => 'integer', 'minimum' => 0, 'default' => 0],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 2000, 'default' => 500],
            ]),
            fn($input): array => $this->services->workspace()->tree((array) $input),
            static fn(): bool => shopagg_ai_deployer_has_api_access(), true, false, false);

        $this->register('workspace-search', 'Search code workspace',
            'Search text files and return concise path, line, and snippet matches.',
            'shopagg-code', $this->objectSchema([
                'path' => ['type' => 'string', 'default' => 'plugins'],
                'query' => ['type' => 'string', 'minLength' => 1],
                'case_sensitive' => ['type' => 'boolean', 'default' => false],
                'extensions' => ['type' => 'array', 'items' => ['type' => 'string']],
                'max_matches' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500, 'default' => 100],
            ], ['query']),
            fn($input): array => $this->services->workspace()->search((array) $input),
            static fn(): bool => shopagg_ai_deployer_has_api_access(), true, false, false);

        $this->register('workspace-read', 'Read code ranges',
            'Batch-read up to 20 files by line range and return a SHA-256 precondition for safe edits.',
            'shopagg-code', $this->objectSchema([
                'files' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'maxItems' => 20,
                    'items' => $this->objectSchema([
                        'path' => ['type' => 'string'],
                        'start_line' => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
                        'end_line' => ['type' => 'integer', 'minimum' => 1],
                    ], ['path']),
                ],
            ], ['files']),
            fn($input): array => $this->services->workspace()->read((array) $input),
            static fn(): bool => shopagg_ai_deployer_has_api_access(), true, false, false);

        $changesSchema = [
            'type' => 'array',
            'minItems' => 1,
            'maxItems' => 100,
            'items' => $this->objectSchema([
                'path' => ['type' => 'string'],
                'operation' => ['type' => 'string', 'enum' => ['write', 'delete'], 'default' => 'write'],
                'content' => ['type' => 'string'],
                'expected_sha256' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'],
                'expect_absent' => ['type' => 'boolean', 'default' => false],
            ], ['path']),
        ];
        $this->register('deployment-preview', 'Preview code deployment',
            'Validate paths, size limits, PHP syntax, and file hash preconditions without changing the site.',
            'shopagg-code', $this->objectSchema(['changes' => $changesSchema], ['changes']),
            fn($input): array => $this->services->deployment()->preview((array) $input),
            static fn(): bool => shopagg_ai_deployer_has_api_access(), false, false, true);

        $this->register('deployment-apply', 'Apply code deployment',
            'Apply a preconditioned code transaction with fail-closed backup, health verification, and automatic rollback.',
            'shopagg-code', $this->objectSchema([
                'operation_id' => ['type' => 'string', 'pattern' => '^[a-zA-Z0-9_-]{1,100}$'],
                'changes' => $changesSchema,
                'health_paths' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 9],
            ], ['operation_id', 'changes']),
            fn($input): array => $this->services->deployment()->apply((array) $input),
            static fn(): bool => shopagg_ai_deployer_has_api_access(), false, true, false);

        $this->register('rest-discover', 'Discover WordPress REST resources',
            'Discover any registered WordPress REST route and its argument summary.',
            'shopagg-wordpress', $this->objectSchema([
                'namespace' => ['type' => 'string'],
            ]),
            fn($input): array => $this->services->wordpress()->discover((array) $input),
            static fn(): bool => shopagg_ai_deployer_has_api_access(), true, false, false);

        $restSchema = $this->objectSchema([
            'route' => ['type' => 'string'],
            'parameters' => ['type' => 'object', 'additionalProperties' => true],
        ], ['route']);
        $this->register('rest-read', 'Read WordPress resource',
            'Execute an administrator-authenticated GET against any registered WordPress REST route.',
            'shopagg-wordpress', $restSchema,
            fn($input): array => $this->services->wordpress()->read((array) $input),
            static fn(): bool => shopagg_ai_deployer_has_api_access(), true, false, false);

        $writeSchema = $restSchema;
        $writeSchema['properties']['method'] = ['type' => 'string', 'enum' => ['POST', 'PUT', 'PATCH'], 'default' => 'POST'];
        $this->register('rest-write', 'Write WordPress resource',
            'Execute an administrator-authenticated create or update against any registered WordPress REST route.',
            'shopagg-wordpress', $writeSchema,
            fn($input): array => $this->services->wordpress()->write((array) $input),
            static fn(): bool => shopagg_ai_deployer_has_api_access(), false, false, false);

        $this->register('rest-delete', 'Delete WordPress resource',
            'Execute an administrator-authenticated DELETE against any registered WordPress REST route.',
            'shopagg-wordpress', $restSchema,
            fn($input): array => $this->services->wordpress()->delete((array) $input),
            static fn(): bool => shopagg_ai_deployer_has_api_access(), false, true, false);

        $extensionList = $this->objectSchema([
            'type' => ['type' => 'string', 'enum' => ['plugin', 'theme'], 'default' => 'plugin'],
        ]);
        $this->register('extensions-list', 'List extensions',
            'List installed plugins or themes and their activation state.',
            'shopagg-wordpress', $extensionList,
            fn($input): array => $this->services->extensions()->execute(['action' => 'list'] + (array) $input),
            static fn(): bool => shopagg_ai_deployer_has_api_access(), true, false, false);

        $this->register('extensions-manage', 'Manage extensions',
            'Activate, deactivate, update, or delete a plugin, or activate a theme, with backup and health checks where applicable.',
            'shopagg-wordpress', $this->objectSchema([
                'type' => ['type' => 'string', 'enum' => ['plugin', 'theme'], 'default' => 'plugin'],
                'action' => ['type' => 'string', 'enum' => ['activate', 'deactivate', 'update', 'delete']],
                'slug' => ['type' => 'string'],
                'force_check' => ['type' => 'boolean', 'default' => false],
            ], ['action', 'slug']),
            fn($input): array => $this->services->extensions()->execute((array) $input),
            static fn(): bool => shopagg_ai_deployer_has_api_access(), false, true, false);
    }

    private function register(
        string $name,
        string $label,
        string $description,
        string $category,
        ?array $inputSchema,
        callable $execute,
        callable $permission,
        bool $readonly,
        bool $destructive,
        bool $idempotent
    ): void {
        $args = [
            'label' => __($label, 'shopagg-ai-deployer'),
            'description' => __($description, 'shopagg-ai-deployer'),
            'category' => $category,
            'output_schema' => ['type' => 'object', 'additionalProperties' => true],
            'execute_callback' => $execute,
            'permission_callback' => $permission,
            'meta' => [
                'show_in_rest' => true,
                'mcp' => ['public' => true],
                'annotations' => [
                    'readonly' => $readonly,
                    'destructive' => $destructive,
                    'idempotent' => $idempotent,
                ],
            ],
        ];
        if ($inputSchema !== null) {
            $args['input_schema'] = $inputSchema;
        }
        wp_register_ability('shopagg-ai-deployer/' . $name, $args);
    }

    private function objectSchema(array $properties, array $required = []): array {
        $schema = [
            'type' => 'object',
            'properties' => $properties,
            'additionalProperties' => false,
        ];
        if ($required) {
            $schema['required'] = $required;
        }
        return $schema;
    }

    private function siteInspect(): array {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $plugins = get_plugins();
        $active = array_filter(array_keys($plugins), 'is_plugin_active');
        return [
            'success' => true,
            'plugin_version' => SHOPAGG_AI_DEPLOYER_VERSION,
            'wordpress_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
            'site_url' => home_url('/'),
            'abilities_api' => rest_url('wp-abilities/v1'),
            'mcp_adapter_available' => class_exists('WP\\MCP\\Core\\McpAdapter'),
            'plugins' => ['total' => count($plugins), 'active' => count($active)],
            'themes' => ['total' => count(wp_get_themes()), 'active' => get_option('stylesheet')],
            'backups' => ['count' => count($this->services->backups()->list_snapshots())],
            'data_directory_outside_plugin' => !str_starts_with(
                wp_normalize_path(SHOPAGG_AI_DEPLOYER_DATA_DIR),
                wp_normalize_path(SHOPAGG_AI_DEPLOYER_DIR)
            ),
        ];
    }
}
