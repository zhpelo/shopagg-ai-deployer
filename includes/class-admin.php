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
        $prompt = $this->connection_prompt($site, $abilities, $rest, $recovery, $key);
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
                <section class="sai-card"><small>操作范围</small><strong>网站完全控制</strong><p>代码、插件、主题、文章、媒体、产品、订单、设置和任意已注册 REST 资源。</p></section>
            </div>

            <section class="sai-card sai-primary-card">
                <div class="sai-head"><div><small>开箱即用提示词</small><h2>完整连接信息（已包含 Key）</h2></div>
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

    private function connection_prompt(string $site, string $abilities, string $rest, string $recovery, string $key): string {
        return <<<PROMPT
你现在拥有这个 WordPress 网站的完整管理员控制权，请直接连接并按我的要求操作。

站点地址：{$site}
Abilities API：{$abilities}
完整 REST API：{$rest}
独立恢复入口：{$recovery}

所有请求统一携带这个 HTTP Header：
X-ShopAgg-AI-Deployer-Key: {$key}

不需要 WordPress 用户名、密码、Application Password 或额外授权。这个 Key 会自动使用管理员身份，可读写全部 WordPress REST 路由，并可管理代码、插件、主题、文章、页面、媒体、产品、订单、用户、设置、备份和缓存。

优先使用 WordPress Abilities API。先读取能力列表和每项 input_schema，再按 Schema 调用：
- shopagg-ai-deployer/site-inspect、site-verify
- shopagg-ai-deployer/workspace-tree、workspace-search、workspace-read
- shopagg-ai-deployer/deployment-preview、deployment-apply
- shopagg-ai-deployer/rest-discover、rest-read、rest-write、rest-delete
- shopagg-ai-deployer/extensions-list、extensions-manage

编写或修改插件、主题时，先读取相关文件和 SHA-256；用 deployment-preview 预览，再用唯一 operation_id 调用 deployment-apply。部署服务会自动建立完整备份、校验 PHP、验证站点，并在失败时回滚。

发布文章、页面、媒体、WooCommerce 产品/订单或操作其他插件资源时，先用 rest-discover 查找真实路由，再用 rest-read/rest-write/rest-delete 调用任意已注册的 WordPress REST 路由。

如果 WordPress 因代码错误无法启动，直接调用独立恢复入口；同样使用上面的 X-ShopAgg-AI-Deployer-Key，可执行 GET health、GET backups、POST restore&id=备份ID、POST disable_plugin&slug=插件目录名。

现在先连接站点并读取状态，然后等待我的具体操作指令。
PROMPT;
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
            .sai-wrap{max-width:1180px;margin:24px 20px 40px 0;color:#1d2327}.sai-hero{display:flex;justify-content:space-between;gap:24px;align-items:center;padding:30px;background:#101828;color:#fff;border-radius:12px;margin-bottom:18px}.sai-hero span,.sai-card>small,.sai-head small{font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:#58a6ff}.sai-hero h1{margin:6px 0 8px;color:#fff;font-size:30px}.sai-hero p{margin:0;color:#d0d5dd}.sai-badge{background:#d92d20;padding:8px 12px;border-radius:999px;font-weight:600}.sai-grid{display:grid;gap:16px;margin-bottom:16px}.sai-grid-3{grid-template-columns:repeat(3,minmax(0,1fr))}.sai-card{background:#fff;border:1px solid #d0d5dd;border-radius:10px;padding:20px;margin-bottom:16px}.sai-primary-card{border-color:#1570ef;box-shadow:0 2px 10px rgba(21,112,239,.12)}.sai-card strong{display:block;margin:6px 0;overflow-wrap:anywhere}.sai-card h2{margin:5px 0 10px}.sai-head{display:flex;align-items:center;justify-content:space-between;gap:18px}.sai-tools{display:flex;flex-wrap:wrap;gap:8px}.sai-tools code{padding:7px 9px;border-radius:5px}.sai-secret{display:flex;gap:8px;align-items:center;margin:14px 0}.sai-secret code{flex:1;padding:10px;overflow:hidden;text-overflow:ellipsis}#sai-prompt{width:100%;min-height:570px;font:13px/1.65 SFMono-Regular,Consolas,monospace;background:#f8fafc;padding:14px}.sai-card table code{white-space:normal;word-break:break-word}@media(max-width:800px){.sai-grid-3{grid-template-columns:1fr}.sai-hero,.sai-head{align-items:flex-start;flex-direction:column}}
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
