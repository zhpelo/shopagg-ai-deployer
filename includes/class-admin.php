<?php
/**
 * One-click WordPress admin experience.
 *
 * @package SHOPAGG_AI_Deployer
 */

use ShopAgg\AI_Deployer\Application\ServiceContainer;

final class SHOPAGG_AI_Deployer_Admin {

    public function register(): void {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_post_shopagg_ai_regenerate_key', [$this, 'regenerate_key']);
    }

    public function add_menu(): void {
        add_menu_page(
            'SHOPAGG AI Deployer',
            'AI Deployer',
            'manage_options',
            'shopagg-ai-deployer',
            [$this, 'render_connection_page'],
            'dashicons-superhero-alt',
            30
        );
        add_submenu_page(
            'shopagg-ai-deployer',
            'AI 连接',
            'AI 连接',
            'manage_options',
            'shopagg-ai-deployer',
            [$this, 'render_connection_page']
        );
        add_submenu_page(
            'shopagg-ai-deployer',
            '运行状态',
            '运行状态',
            'manage_options',
            'shopagg-ai-deployer-settings',
            [$this, 'render_settings_page']
        );
    }

    public function regenerate_key(): void {
        $this->authorize('shopagg_ai_regenerate_key');
        shopagg_ai_deployer_regenerate_api_key();
        wp_safe_redirect(add_query_arg([
            'page' => 'shopagg-ai-deployer',
            'key_rotated' => '1',
        ], admin_url('admin.php')));
        exit;
    }

    public function render_connection_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $site = home_url('/');
        $abilities = rest_url('wp-abilities/v1');
        $rest = rest_url(SHOPAGG_AI_DEPLOYER_REST_NS);
        $recovery = SHOPAGG_AI_DEPLOYER_URL . 'standalone.php';
        $key = shopagg_ai_deployer_get_api_key();
        $environment = $this->environment_context();
        $environmentPrompt = $this->environment_prompt($environment);
        $prompt = $this->connection_prompt($site, $abilities, $rest, $recovery, $key, $environmentPrompt);
        $this->styles();
        ?>
        <div class="wrap sai-wrap">
            <div class="sai-hero">
                <div><span>SHOPAGG AI DEPLOYER · v<?php echo esc_html(SHOPAGG_AI_DEPLOYER_VERSION); ?></span>
                    <h1>复制提示词，直接交给 AI</h1>
                    <p>安装即用。无需创建账号、Application Password、角色或权限范围。</p></div>
                <div class="sai-badge">全权限模式</div>
            </div>

            <?php if (isset($_GET['key_rotated'])): ?>
                <div class="notice notice-success is-dismissible"><p>主 Key 已轮换，下面的提示词已自动更新。</p></div>
            <?php endif; ?>

            <div class="sai-grid sai-grid-3">
                <section class="sai-card"><small>认证</small><strong>单一主 Key</strong><p>Abilities、完整 REST API 和独立恢复入口共用同一认证头。</p></section>
                <section class="sai-card"><small>执行身份</small><strong>WordPress 管理员</strong><p>持有 Key 的 AI 自动获得站点管理员身份及插件提供的全部操作能力。</p></section>
                <section class="sai-card"><small>当前环境</small><strong>WordPress <?php echo esc_html($environment['wordpress']); ?> · PHP <?php echo esc_html($environment['php']); ?></strong><p><?php echo esc_html($environment['database']); ?></p></section>
            </div>

            <section class="sai-card sai-primary-card">
                <div class="sai-head"><div><small>完整连接与操作协议</small><h2>提示词（已包含 Key）</h2>
                    <p>包含接口字典、能力参数、任务决策、部署协议、验证规则和异常恢复。</p></div>
                    <button type="button" class="button button-primary button-hero" data-copy="sai-prompt">复制提示词</button></div>
                <textarea id="sai-prompt" readonly><?php echo esc_textarea($prompt); ?></textarea>
            </section>

            <section class="sai-card">
                <div class="sai-head"><div><small>主凭据</small><h2>全权限 API Key</h2></div></div>
                <?php $this->secret_box('sai-api-key', $key); ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('shopagg_ai_regenerate_key'); ?>
                    <input type="hidden" name="action" value="shopagg_ai_regenerate_key">
                    <button class="button" onclick="return confirm('当前提示词中的 Key 会立即失效，确认轮换？');">轮换主 Key</button>
                </form>
            </section>

            <section class="sai-card">
                <div class="sai-head"><div><small>已注册能力</small><h2>AI 工具集</h2></div></div>
                <div class="sai-tools">
                    <?php foreach ($this->ability_names() as $ability): ?>
                        <code>shopagg-ai-deployer/<?php echo esc_html($ability); ?></code>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>
        <?php
        $this->scripts();
    }

    public function render_settings_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $activity = ServiceContainer::instance()->audit()->recent(20);
        $this->styles();
        ?>
        <div class="wrap sai-wrap">
            <div class="sai-hero"><div><span>SHOPAGG AI DEPLOYER</span><h1>运行状态</h1>
                <p>主 Key、事务备份、幂等结果和操作记录由插件自动管理。</p></div></div>

            <div class="sai-grid sai-grid-3">
                <section class="sai-card"><small>Abilities API</small><strong><?php echo esc_html(rest_url('wp-abilities/v1')); ?></strong></section>
                <section class="sai-card"><small>完整 REST API</small><strong><?php echo esc_html(rest_url(SHOPAGG_AI_DEPLOYER_REST_NS)); ?></strong></section>
                <section class="sai-card"><small>独立恢复入口</small><strong><?php echo esc_html(SHOPAGG_AI_DEPLOYER_URL . 'standalone.php'); ?></strong></section>
            </div>

            <section class="sai-card">
                <small>运行时数据</small><h2><?php echo esc_html(SHOPAGG_AI_DEPLOYER_DATA_DIR); ?></h2>
                <p>凭据、备份、事务结果和审计日志与插件源码分开存储，插件更新不会覆盖它们。</p>
            </section>

            <section class="sai-card"><small>审计</small><h2>最近操作</h2>
                <?php if (!$activity): ?><p>暂无操作记录。</p><?php else: ?>
                    <table class="widefat striped"><thead><tr><th>时间</th><th>操作</th><th>结果</th><th>详情</th></tr></thead><tbody>
                    <?php foreach ($activity as $item): ?><tr>
                        <td><?php echo esc_html((string) ($item['time'] ?? '')); ?></td>
                        <td><code><?php echo esc_html((string) ($item['action'] ?? '')); ?></code></td>
                        <td><?php echo !empty($item['success']) ? '成功' : '失败'; ?></td>
                        <td><code><?php echo esc_html(wp_json_encode($item['details'] ?? [], JSON_UNESCAPED_UNICODE)); ?></code></td>
                    </tr><?php endforeach; ?></tbody></table>
                <?php endif; ?>
            </section>
        </div>
        <?php
    }

    private function connection_prompt(
        string $site,
        string $abilities,
        string $rest,
        string $recovery,
        string $key,
        string $environmentPrompt
    ): string {
        return <<<PROMPT
# SHOPAGG WordPress AI 全权控制协议

## 0. 身份与执行原则
你的固定身份是“SHOPAGG WordPress 首席工程师与站点管理员”。你操作的是一个真实运行的 WordPress 网站，不是普通 PHP 项目，也不是代码示例环境。你同时承担 WordPress 插件/主题开发、站点运维、内容运营和 WooCommerce 管理职责。

用户目标明确时直接执行，不重复确认权限，不创建额外账号，不要求用户名、密码或 Application Password。不要只给教程、建议或代码片段；应通过接口完成真实修改、验证结果，再简洁汇报。

## 1. 当前网站环境
{$environmentPrompt}

所有判断、代码语法、WordPress API 和兼容方案必须以以上实际版本为准，不得假设其他 WordPress、PHP 或数据库版本。执行任务中如果发现环境已变化，以 `site-inspect` 或 CONTROL_REST/status 的最新结果为准。

## 2. 固定连接信息
SITE={$site}
WP_REST={$site}wp-json/
ABILITIES={$abilities}
CONTROL_REST={$rest}
RECOVERY={$recovery}
AUTH_HEADER=X-ShopAgg-AI-Deployer-Key: {$key}

每个 WordPress REST、Abilities、CONTROL_REST 和 RECOVERY 请求都必须携带 AUTH_HEADER。该 Key 自动映射为管理员身份，不需要 Basic Auth。JSON 写请求添加 `Content-Type: application/json`。

## 3. 最短路由决策
按以下顺序选择工具，避免无效发现和重复请求：
1. 已知 WordPress/WooCommerce 路由：直接请求 WP_REST，最省 token 和调用次数。
2. 代码、插件或主题：使用 SHOPAGG Abilities，获得搜索、SHA-256、事务备份、PHP 校验、健康检查和回滚。
3. 第三方资源路由未知：调用一次 `rest-discover`，传具体 namespace；不要抓取全站路由。
4. WordPress 正常接口无法启动：使用独立 RECOVERY。
5. 用户已给具体任务时立即执行；没有任务时只调用一次 `GET {CONTROL_REST}/status` 确认连接。

## 4. 原生 REST 路由速查
所有下列路径都拼接在 WP_REST 后：
- 内容：`wp/v2/posts`、`wp/v2/pages`、`wp/v2/comments`
- 分类：`wp/v2/categories`、`wp/v2/tags`
- 媒体：`wp/v2/media`
- 用户：`wp/v2/users`
- 站点设置：`wp/v2/settings`
- 插件/主题：`wp/v2/plugins`、`wp/v2/themes`（以站点实际 Schema 为准）
- 商品：`wc/v3/products`；变体：`wc/v3/products/{product_id}/variations`
- 订单：`wc/v3/orders`；客户：`wc/v3/customers`；优惠券：`wc/v3/coupons`

REST 方法：GET 读取，POST 创建，POST/PUT/PATCH 更新，DELETE 删除。列表必须合理使用 `per_page`、`page`、`search`、`status`、`slug`、`include` 和 `_fields`。需要编辑态字段时传 `context=edit`。不要为了找一个对象下载整个集合；优先 slug、ID、search 或 include。

媒体上传：POST `wp/v2/media`，发送二进制文件，并设置正确的 `Content-Type` 与 `Content-Disposition: attachment; filename="文件名"`；成功后保存媒体 ID 和 source_url，再关联文章的 `featured_media` 或商品的 `images`。

## 5. Abilities 调用协议
完整能力名：`shopagg-ai-deployer/{name}`。
- 若客户端已显示这些能力为工具：直接按工具 Schema 调用，不做发现。
- 缺少 Schema 时：GET `{ABILITIES}/abilities/shopagg-ai-deployer/{name}`。
- 执行地址：`{ABILITIES}/abilities/shopagg-ai-deployer/{name}/run`。
- 只读能力：GET，输入放 `input[...]`。
- `deployment-preview`、`deployment-apply` 和其他写能力：POST，Body 为 `{"input":{...}}`。
- `input_schema` 是参数唯一依据；同一任务中缓存并复用 Schema。

能力参数速查：
- `site-inspect`：无输入；返回版本、站点、插件、主题、备份和连接信息。
- `site-verify`：`{"paths":["/","/shop/"]}`；验证首页及指定站内路径。
- `workspace-tree`：`{"path":"plugins/目标目录","offset":0,"limit":500}`；返回路径、大小、时间、哈希，不返回正文。
- `workspace-search`：`{"path":"plugins/目标目录","query":"关键词","extensions":["php","js"],"max_matches":100}`。
- `workspace-read`：`{"files":[{"path":"plugins/x/file.php","start_line":1,"end_line":400}]}`；可批量读取并返回 sha256。
- `deployment-preview`：`{"changes":[change...]}`；只预检，不写入。
- `deployment-apply`：`{"operation_id":"唯一ID","changes":[change...],"health_paths":["/"]}`；事务写入。
- `rest-discover`：`{"namespace":"wp/v2|wc/v3|第三方命名空间"}`。
- `rest-read`：`{"route":"/wp/v2/...","parameters":{...}}`。
- `rest-write`：`{"route":"/wp/v2/...","method":"POST|PUT|PATCH","parameters":{...}}`。
- `rest-delete`：`{"route":"/wp/v2/...","parameters":{"force":true}}`。
- `extensions-list`：`{"type":"plugin|theme"}`。
- `extensions-manage`：`{"type":"plugin|theme","action":"activate|deactivate|update|delete","slug":"目录名","force_check":true}`。

## 6. WordPress 开发规范（所有新增或修改代码必须遵守）
1. 这是 WordPress 项目。功能代码优先放在插件、主题或 mu-plugin，不直接修改 WordPress Core；只有用户明确要求时才改核心文件。
2. 使用 WordPress Hook、Filter、Action、Shortcode、Block、REST API、Settings API、Options API、Metadata API、WP_Query、Cron、HTTP API、Filesystem API 等官方机制，不绕过 WordPress 生命周期。
3. PHP 文件使用 `defined('ABSPATH') || exit;`；函数、Option、Transient、Cron、REST namespace、Handle、数据库对象必须使用项目专属前缀，类优先使用 namespace，避免全局命名冲突。
4. 输入先 `wp_unslash()`，再按类型使用 `sanitize_text_field`、`sanitize_key`、`absint`、`sanitize_email`、`esc_url_raw`、`wp_kses_post` 等；业务数据必须验证类型、范围、枚举和必填项。
5. 输出按上下文使用 `esc_html`、`esc_attr`、`esc_url`、`wp_kses_post`；SQL 使用 `\$wpdb->prepare()`，表名使用 `\$wpdb->prefix`，建表/升级使用 `dbDelta()`，禁止拼接不可信 SQL。
6. 后台表单和 AJAX 使用 nonce；管理动作检查 `current_user_can()`；注册 REST Route 必须提供 `permission_callback`。这里的主 Key 已赋予管理员身份，但新增代码仍须符合 WordPress 权限接口规范。
7. CSS/JS 使用 `wp_enqueue_style`、`wp_enqueue_script` 和依赖/版本参数；不要直接在模板随意硬编码全局资源。向 JavaScript 传数据使用 `wp_localize_script` 或 `wp_add_inline_script`。
8. 用户可见文本使用正确 Text Domain 和 `__()`、`_e()`、`esc_html__()` 等国际化函数。时间使用 WordPress 时区函数，URL/路径使用 `home_url`、`admin_url`、`rest_url`、`plugin_dir_path` 等。
9. 插件包含有效 Header；激活、停用、卸载逻辑分别使用对应 Hook。持久化数据要有默认值、版本迁移和幂等处理，不在每次请求执行昂贵安装逻辑。
10. WooCommerce 功能优先使用 WC CRUD 对象、Hook 和 REST Schema，不直接随意改订单/商品底层 postmeta。Gutenberg 内容保留有效 Block 标记。
11. 代码兼容上方实际 WordPress/PHP/数据库版本；不调用该版本不存在的 API。遵循 WordPress Coding Standards，保持小函数、清晰职责、必要注释和可恢复错误处理。
12. 部署前完成 PHP 语法预检；部署后验证 REST、后台及相关前台路径。不得把“代码已生成”等同于“网站修改已成功”。

## 7. 代码开发与修改协议
代码路径相对于 wp-content，仅使用 `plugins/`、`themes/`、`mu-plugins/`。

修改现有代码：
1. `workspace-search` 定位符号/钩子，再用 `workspace-tree` 获取相关路径；不要先扫完整目录。
2. `workspace-read` 读取必要文件和行段。修改过的每个现有文件都必须取得 sha256。
3. 根据真实上下文生成完整新文件内容，保持现有命名、加载顺序、Text Domain、WordPress API 和代码风格。
4. change：`{"path":"plugins/x/file.php","operation":"write","content":"完整新内容","expected_sha256":"读取到的哈希"}`。
5. 先 POST `deployment-preview`；若 errors 非空，修正后重新预检。
6. 预检成功后，将完全相同的 changes POST 给 `deployment-apply`，使用唯一、稳定的 `operation_id`；同一功能的多文件合并为一个事务。
7. 检查 `success`、`results`、`backup_id`、`health`、`rollback`，再用 `site-verify` 验证相关页面/API。

创建新插件/主题：先确认目标目录不存在；change 使用 `"expect_absent":true`。一次事务写入入口文件及全部依赖文件。插件至少应包含有效 Header，并确保激活时无致命错误。需要启用时，再调用 `extensions-manage activate` 并验证。

删除文件：change 使用 `{"path":"...","operation":"delete","expected_sha256":"原哈希"}`，不传 content。服务器会在变更前创建完整备份。若 `rollback` 非空或健康验证失败，视为未完成，不得报告成功。

## 8. 内容、页面与媒体工作流
1. 先用 slug、ID 或 search 精确确认目标是否存在，避免重复创建。
2. 创建/更新仅发送需要的字段，如 `title`、`content`、`excerpt`、`status`、`slug`、`categories`、`tags`、`featured_media`、`meta`。
3. 分类和标签参数使用 ID；名称未知时先查询或创建术语。
4. `draft` 表示草稿，`publish` 表示立即发布，`future` 需要有效 date/date_gmt。
5. 写入后精确 GET 该 ID，确认 status、slug、link、featured_media 和关键内容。

## 9. WooCommerce 工作流
商品价格字段通常使用字符串。创建商品时明确 `name`、`type`、`status`、`regular_price`、`description`、`short_description`、`categories`、`images`、`manage_stock`、`stock_quantity`。变体商品先创建 `type=variable` 的父商品和 attributes，再创建 variations。修改库存/价格前先读取当前 edit context，写入后再次精确读取验证。

订单操作使用 `wc/v3/orders/{id}`。修改状态前读取当前订单，按用户要求设置 `pending|processing|on-hold|completed|cancelled|refunded|failed` 等有效状态，并保留订单 ID、status、total 和 customer 信息用于结果确认。第三方支付、物流或订阅插件字段先按其 namespace 发现真实 Schema。

## 10. CONTROL_REST 直接控制接口
在 Abilities 不方便时可直接使用：
- 状态：GET `/status`、`/health`、`/activity`
- 文件：GET/PUT/DELETE `/files/{URL编码路径}`；目录 GET `/list/{路径}`；删除目录 POST `/delete-dir`
- 备份：GET `/backups`、GET/DELETE `/backups/{id}`、POST `/backups/{id}/restore`
- 插件：GET `/plugins`、GET `/plugins/updates`、POST `/plugins/update-all`、POST `/plugins/{slug}/activate|deactivate|update`、DELETE `/plugins/{slug}`
- 主题：GET `/themes`、POST `/themes/{slug}/activate`
- 文章：GET/POST `/posts`、GET/PUT/PATCH/DELETE `/posts/{id}`
- 代码包：GET `/code/plugin|theme/{slug}`
- 设置：GET/POST `/options/{name}`；缓存：POST `/cache/clear`
- 部署：POST `/deploy`，Body 传 `operation_id`、`files:[{"path":"...","content":"..."}]`、可选 `health_paths`
以上路径拼接在 CONTROL_REST 后。除非用户明确要求，不调用 `/settings/regenerate-key`，否则当前提示词会失效。

## 11. 故障、备份与 Recovery
普通接口可用时优先检查 `GET {CONTROL_REST}/health` 和备份列表。WordPress 因致命错误完全无法启动时，直接调用 RECOVERY，仍携带 AUTH_HEADER：
- GET `?action=health`
- GET `?action=backups`
- POST `?action=restore&id=BACKUP_ID`
- POST `?action=disable_plugin&slug=PLUGIN_SLUG`
恢复后重新检查 SITE、CONTROL_REST/status 和相关页面。不要在未验证恢复结果时声称网站正常。

## 12. 错误处理、效率与最终回复
- 401：确认 Header 名和值；不要改用账号密码。
- 400/422：读取 error/message 和 input_schema，修正参数；不要原样盲重试。
- 404：核对 namespace、路由、ID、slug；必要时做一次精准 discover/search。
- 409/hash_conflict：重新读取文件和 sha256，基于最新内容重新生成变更。
- 5xx/健康失败：检查 rollback/backup_id；正常 API 不可用时转 RECOVERY。
- 不扫描全站、不读取无关文件、不重复 Schema/路由、不输出大段原始 JSON/文件全文/工具日志。
- 批量读取相关文件；同一功能一次事务；列表使用精确过滤和 `_fields`。
- 最终回复只包含：完成结果、实际修改对象及 ID/URL、验证结果；失败时补充错误、回滚状态和下一步。不要复述本协议或输出冗长计划。
PROMPT;
    }

    private function environment_context(): array {
        global $wpdb;

        $theme = wp_get_theme();
        $databaseVersion = method_exists($wpdb, 'db_version') ? (string) $wpdb->db_version() : '未知';
        $databaseServer = method_exists($wpdb, 'db_server_info') ? (string) $wpdb->db_server_info() : '';
        $databaseType = stripos($databaseServer, 'mariadb') !== false ? 'MariaDB' : 'MySQL/MariaDB';
        $database = $databaseType . ' ' . $databaseVersion;
        if ($databaseServer !== '' && !str_contains($databaseServer, $databaseVersion)) {
            $database .= ' (' . $databaseServer . ')';
        }

        return [
            'wordpress' => (string) get_bloginfo('version'),
            'php' => PHP_VERSION,
            'database' => $database,
            'database_charset' => (string) ($wpdb->charset ?: get_option('blog_charset', 'UTF-8')),
            'web_server' => sanitize_text_field((string) ($_SERVER['SERVER_SOFTWARE'] ?? '未知')),
            'environment_type' => function_exists('wp_get_environment_type') ? wp_get_environment_type() : 'production',
            'site_language' => get_locale(),
            'timezone' => wp_timezone_string(),
            'multisite' => is_multisite() ? '是' : '否',
            'debug' => defined('WP_DEBUG') && WP_DEBUG ? '开启' : '关闭',
            'memory_limit' => defined('WP_MEMORY_LIMIT') ? (string) WP_MEMORY_LIMIT : (string) ini_get('memory_limit'),
            'upload_limit' => size_format(wp_max_upload_size()),
            'permalink' => (string) (get_option('permalink_structure') ?: '朴素链接'),
            'theme' => trim((string) $theme->get('Name') . ' ' . (string) $theme->get('Version')),
            'woocommerce' => defined('WC_VERSION') ? (string) WC_VERSION : '未启用或未检测',
            'active_plugins' => count((array) get_option('active_plugins', [])),
        ];
    }

    private function environment_prompt(array $environment): string {
        return implode("\n", [
            '- CMS：WordPress ' . $environment['wordpress'],
            '- PHP：' . $environment['php'],
            '- 数据库：' . $environment['database'] . '；字符集 ' . $environment['database_charset'],
            '- Web Server：' . $environment['web_server'],
            '- WordPress 环境类型：' . $environment['environment_type'],
            '- 当前主题：' . $environment['theme'],
            '- WooCommerce：' . $environment['woocommerce'],
            '- 活动插件数：' . $environment['active_plugins'],
            '- 语言/时区：' . $environment['site_language'] . ' / ' . $environment['timezone'],
            '- Multisite：' . $environment['multisite'] . '；WP_DEBUG：' . $environment['debug'],
            '- WordPress 内存限制：' . $environment['memory_limit'] . '；上传限制：' . $environment['upload_limit'],
            '- 固定链接结构：' . $environment['permalink'],
        ]);
    }

    private function ability_names(): array {
        return [
            'site-inspect', 'site-verify', 'workspace-tree', 'workspace-search', 'workspace-read',
            'deployment-preview', 'deployment-apply', 'rest-discover', 'rest-read', 'rest-write',
            'rest-delete', 'extensions-list', 'extensions-manage',
        ];
    }

    private function authorize(string $nonce): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to do this.', 'shopagg-ai-deployer'));
        }
        check_admin_referer($nonce);
    }

    private function secret_box(string $id, string $secret): void {
        ?>
        <div class="sai-secret"><code id="<?php echo esc_attr($id); ?>" data-secret="<?php echo esc_attr($secret); ?>"><?php echo esc_html($this->mask($secret)); ?></code>
            <button type="button" class="button" data-reveal="<?php echo esc_attr($id); ?>">显示</button>
            <button type="button" class="button" data-copy-secret="<?php echo esc_attr($id); ?>">复制</button></div>
        <?php
    }

    private function mask(string $secret): string {
        return strlen($secret) > 12 ? substr($secret, 0, 6) . str_repeat('•', 12) . substr($secret, -4) : str_repeat('•', 12);
    }

    private function styles(): void {
        ?><style>
            .sai-wrap{max-width:1180px;margin:24px 20px 40px 0;color:#1d2327}.sai-hero{display:flex;justify-content:space-between;gap:24px;align-items:center;padding:30px;background:#101828;color:#fff;border-radius:12px;margin-bottom:18px}.sai-hero span,.sai-card>small,.sai-head small{font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:#58a6ff}.sai-hero h1{margin:6px 0 8px;color:#fff;font-size:30px}.sai-hero p{margin:0;color:#d0d5dd}.sai-badge{background:#d92d20;padding:8px 12px;border-radius:999px;font-weight:600}.sai-grid{display:grid;gap:16px;margin-bottom:16px}.sai-grid-3{grid-template-columns:repeat(3,minmax(0,1fr))}.sai-card{background:#fff;border:1px solid #d0d5dd;border-radius:10px;padding:20px;margin-bottom:16px}.sai-primary-card{border-color:#1570ef;box-shadow:0 2px 10px rgba(21,112,239,.12)}.sai-card strong{display:block;margin:6px 0;overflow-wrap:anywhere}.sai-card h2{margin:5px 0 10px}.sai-head{display:flex;align-items:center;justify-content:space-between;gap:18px}.sai-head p{margin:5px 0}.sai-tools{display:flex;flex-wrap:wrap;gap:8px}.sai-tools code{padding:7px 9px;border-radius:5px}.sai-secret{display:flex;gap:8px;align-items:center;margin:14px 0}.sai-secret code{flex:1;padding:10px;overflow:hidden;text-overflow:ellipsis}#sai-prompt{width:100%;min-height:1180px;font:13px/1.65 SFMono-Regular,Consolas,monospace;background:#f8fafc;padding:14px}.sai-card table code{white-space:normal;word-break:break-word}@media(max-width:900px){.sai-grid-3{grid-template-columns:1fr}.sai-hero,.sai-head{align-items:flex-start;flex-direction:column}}
        </style><?php
    }

    private function scripts(): void {
        ?><script>
        (()=>{const copy=async t=>{await navigator.clipboard.writeText(t);};document.querySelectorAll('[data-copy]').forEach(b=>b.addEventListener('click',()=>{const e=document.getElementById(b.dataset.copy);copy(typeof e.value==='string'?e.value:e.textContent);b.textContent='已复制';}));document.querySelectorAll('[data-reveal]').forEach(b=>b.addEventListener('click',()=>{const e=document.getElementById(b.dataset.reveal),shown=e.dataset.shown==='1';e.textContent=shown?e.dataset.secret.slice(0,6)+'•'.repeat(12)+e.dataset.secret.slice(-4):e.dataset.secret;e.dataset.shown=shown?'0':'1';b.textContent=shown?'显示':'隐藏';}));document.querySelectorAll('[data-copy-secret]').forEach(b=>b.addEventListener('click',()=>{copy(document.getElementById(b.dataset.copySecret).dataset.secret);b.textContent='已复制';}));})();
        </script><?php
    }
}

function shopagg_ai_deployer_admin_bootstrap(): void {
    (new SHOPAGG_AI_Deployer_Admin())->register();
}
