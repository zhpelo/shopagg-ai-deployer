<?php
/**
 * WordPress admin experience for SHOPAGG AI Deployer.
 *
 * @package SHOPAGG_AI_Deployer
 */

class SHOPAGG_AI_Deployer_Admin {

    public function register(): void {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_post_shopagg_ai_regenerate_key', [$this, 'handle_regenerate']);
    }

    public function add_menu(): void {
        add_menu_page(
            'SHOPAGG AI Deployer',
            'AI Deployer',
            'manage_options',
            'shopagg-ai-deployer',
            [$this, 'render_ai_page'],
            'dashicons-superhero-alt',
            30
        );
        add_submenu_page(
            'shopagg-ai-deployer',
            'AI 提示词文档',
            'AI 提示词',
            'manage_options',
            'shopagg-ai-deployer',
            [$this, 'render_ai_page']
        );
        add_submenu_page(
            'shopagg-ai-deployer',
            '连接与安全设置',
            '连接与安全',
            'manage_options',
            'shopagg-ai-deployer-settings',
            [$this, 'render_settings_page']
        );
    }

    public function handle_regenerate(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to do this.', 'shopagg-ai-deployer'));
        }
        check_admin_referer('shopagg_ai_regenerate_key');
        $key = shopagg_ai_deployer_regenerate_api_key();
        set_transient('shopagg_ai_deployer_new_key_' . get_current_user_id(), $key, 120);
        wp_safe_redirect(add_query_arg([
            'page' => 'shopagg-ai-deployer-settings',
            'key_regenerated' => '1',
        ], admin_url('admin.php')));
        exit;
    }

    public function render_ai_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $key = shopagg_ai_deployer_get_api_key();
        $site_url = home_url('/');
        $rest_url = untrailingslashit(rest_url(SHOPAGG_AI_DEPLOYER_REST_NS));
        $standalone_url = SHOPAGG_AI_DEPLOYER_URL . 'standalone.php';
        $prompt = $this->build_ai_prompt($site_url, $key, $rest_url, $standalone_url);
        $status = $this->get_status();
        $this->render_styles();
        ?>
        <div class="wrap shopagg-deployer">
            <section class="sai-hero">
                <div>
                    <div class="sai-eyebrow">SHOPAGG AI DEPLOYER · v<?php echo esc_html(SHOPAGG_AI_DEPLOYER_VERSION); ?></div>
                    <h1>AI 提示词</h1>
                    <p>复制包含网站连接信息、操作规范和恢复流程的提示词，发送给你使用的 AI 工具。</p>
                </div>
                <div class="sai-hero-status">
                    <span class="sai-dot"></span>
                    <div><strong>连接正常</strong><small>REST 与急救通道可用</small></div>
                </div>
            </section>

            <section class="sai-metrics" aria-label="系统概况">
                <div class="sai-metric">
                    <span class="dashicons dashicons-yes-alt"></span>
                    <div><strong>REST API</strong><small>WordPress 正常时使用</small></div>
                </div>
                <div class="sai-metric">
                    <span class="dashicons dashicons-shield-alt"></span>
                    <div><strong>急救通道</strong><small><?php echo $status['standalone_ok'] ? '独立运行，可用' : '文件不可读'; ?></small></div>
                </div>
                <div class="sai-metric">
                    <span class="dashicons dashicons-backup"></span>
                    <div><strong><?php echo (int) $status['backup_count']; ?> 个备份</strong><small>部署前自动创建</small></div>
                </div>
                <div class="sai-metric">
                    <span class="dashicons dashicons-lock"></span>
                    <div><strong>路径保护</strong><small>限制在插件和主题</small></div>
                </div>
            </section>

            <section class="sai-panel">
                <div class="sai-panel-head">
                    <div>
                        <span class="sai-section-label">3 步开始</span>
                        <h2>使用方法</h2>
                    </div>
                </div>
                <div class="sai-steps">
                    <div><span>01</span><strong>复制提示词</strong><p>点击下方按钮复制完整连接文档。</p></div>
                    <div><span>02</span><strong>发送给 AI</strong><p>粘贴到 ChatGPT、Codex、Claude 等工具。</p></div>
                    <div><span>03</span><strong>描述任务</strong><p>例如“检查插件并优化首页加载速度”。</p></div>
                </div>
            </section>

            <section class="sai-panel sai-prompt-panel">
                <div class="sai-panel-head">
                    <div>
                        <span class="sai-section-label">AI 提示词文档</span>
                        <h2>提示词内容</h2>
                        <p>包含当前网站地址、专属密钥、只读与写入边界、备份要求和事故恢复流程。</p>
                    </div>
                    <div class="sai-actions">
                        <button type="button" class="button" id="sai-download-prompt">
                            <span class="dashicons dashicons-download"></span> 下载 Markdown
                        </button>
                        <button type="button" class="button button-primary" id="sai-copy-prompt">
                            <span class="dashicons dashicons-clipboard"></span> 复制提示词
                        </button>
                    </div>
                </div>

                <div class="sai-security-note">
                    <span class="dashicons dashicons-warning"></span>
                    <div><strong>提示词包含完整控制密钥</strong><p>仅发送给你信任的 AI 工具，不要发布到群聊、论坛、工单或代码仓库。</p></div>
                </div>

                <textarea id="sai-prompt" readonly spellcheck="false"><?php echo esc_textarea($prompt); ?></textarea>
                <div class="sai-prompt-foot">
                    <span><?php echo esc_html(number_format_i18n(function_exists('mb_strlen') ? mb_strlen($prompt) : strlen($prompt))); ?> 个字符</span>
                    <span>生成时间：<?php echo esc_html(wp_date('Y-m-d H:i')); ?></span>
                </div>
            </section>

            <section class="sai-panel">
                <div class="sai-panel-head">
                    <div>
                        <span class="sai-section-label">连接信息</span>
                        <h2>当前站点</h2>
                    </div>
                    <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=shopagg-ai-deployer-settings')); ?>">连接与安全设置</a>
                </div>
                <div class="sai-connection-grid">
                    <div><small>网站地址</small><code><?php echo esc_html($site_url); ?></code></div>
                    <div><small>REST API</small><code><?php echo esc_html($rest_url); ?></code></div>
                    <div><small>Standalone</small><code><?php echo esc_html($standalone_url); ?></code></div>
                    <div><small>API Key</small><code><?php echo esc_html($this->mask_key($key)); ?></code></div>
                </div>
            </section>
        </div>
        <?php
        $this->render_prompt_script();
    }

    public function render_settings_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $key = shopagg_ai_deployer_get_api_key();
        $status = $this->get_status();
        $new_key = false;
        if (isset($_GET['key_regenerated'])) {
            $new_key = get_transient('shopagg_ai_deployer_new_key_' . get_current_user_id());
            delete_transient('shopagg_ai_deployer_new_key_' . get_current_user_id());
        }
        $activity = get_option('shopagg_ai_deployer_activity', []);
        $activity = is_array($activity) ? array_slice($activity, 0, 8) : [];
        $this->render_styles();
        ?>
        <div class="wrap shopagg-deployer">
            <section class="sai-page-title">
                <div>
                    <div class="sai-eyebrow">SHOPAGG AI DEPLOYER</div>
                    <h1>连接与安全</h1>
                    <p>管理访问凭据、检查恢复能力、查看最近的远程操作。</p>
                </div>
                <a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=shopagg-ai-deployer')); ?>">返回 AI 提示词</a>
            </section>

            <?php if ($new_key): ?>
                <div class="notice notice-warning">
                    <p><strong>新的 API Key 已生成，旧 Key 已立即失效。</strong></p>
                    <p class="sai-new-key">
                        <code id="sai-new-key"><?php echo esc_html($new_key); ?></code>
                        <button type="button" class="button" data-copy-target="sai-new-key">复制新 Key</button>
                    </p>
                    <p>此 Key 只显示一次，请立即更新你的 AI 提示词或安全保存。</p>
                </div>
            <?php elseif (isset($_GET['key_regenerated'])): ?>
                <div class="notice notice-info"><p>新 Key 的一次性显示窗口已结束，可再次生成。</p></div>
            <?php endif; ?>

            <section class="sai-metrics">
                <div class="sai-metric"><span class="dashicons dashicons-wordpress"></span><div><strong><?php echo esc_html(get_bloginfo('version')); ?></strong><small>WordPress</small></div></div>
                <div class="sai-metric"><span class="dashicons dashicons-editor-code"></span><div><strong><?php echo esc_html(PHP_VERSION); ?></strong><small>PHP</small></div></div>
                <div class="sai-metric"><span class="dashicons dashicons-backup"></span><div><strong><?php echo (int) $status['backup_count']; ?></strong><small>备份快照</small></div></div>
                <div class="sai-metric"><span class="dashicons dashicons-shield"></span><div><strong><?php echo $status['standalone_ok'] ? '可用' : '异常'; ?></strong><small>Standalone</small></div></div>
            </section>

            <div class="sai-two-column">
                <section class="sai-panel">
                    <div class="sai-panel-head"><div><span class="sai-section-label">凭据</span><h2>API Key</h2></div></div>
                    <p>REST API 与急救通道共用此密钥。重新生成后，所有使用旧 Key 的连接都会立即失效。</p>
                    <div class="sai-key-box">
                        <code id="sai-current-key" data-key="<?php echo esc_attr($key); ?>"><?php echo esc_html($this->mask_key($key)); ?></code>
                        <button type="button" class="button" id="sai-reveal-key">显示</button>
                        <button type="button" class="button" id="sai-copy-key">复制</button>
                    </div>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="sai-danger-form">
                        <?php wp_nonce_field('shopagg_ai_regenerate_key'); ?>
                        <input type="hidden" name="action" value="shopagg_ai_regenerate_key">
                        <button type="submit" class="button" onclick="return confirm('确认重新生成 API Key？旧 Key 会立即失效。');">重新生成 Key</button>
                    </form>
                </section>

                <section class="sai-panel">
                    <div class="sai-panel-head"><div><span class="sai-section-label">恢复能力</span><h2>安全检查</h2></div></div>
                    <ul class="sai-check-list">
                        <li class="<?php echo $key ? 'ok' : 'bad'; ?>"><span></span>API Key <?php echo $key ? '已设置' : '缺失'; ?></li>
                        <li class="<?php echo $status['backup_writable'] ? 'ok' : 'bad'; ?>"><span></span>备份目录<?php echo $status['backup_writable'] ? '可写' : '不可写'; ?></li>
                        <li class="<?php echo $status['standalone_ok'] ? 'ok' : 'bad'; ?>"><span></span>Standalone <?php echo $status['standalone_ok'] ? '文件可读' : '不可用'; ?></li>
                        <li class="<?php echo $status['config_parsable'] ? 'ok' : 'bad'; ?>"><span></span>wp-config.php <?php echo $status['config_parsable'] ? '可供急救通道解析' : '不可读'; ?></li>
                        <li class="ok"><span></span>敏感配置与备份目录禁止通过源码接口读取</li>
                    </ul>
                </section>
            </div>

            <section class="sai-panel">
                <div class="sai-panel-head"><div><span class="sai-section-label">端点</span><h2>连接地址</h2></div></div>
                <div class="sai-table-wrap">
                    <table class="widefat striped">
                        <thead><tr><th>通道</th><th>地址</th><th>使用场景</th></tr></thead>
                        <tbody>
                            <tr><td><strong>REST API</strong></td><td><code><?php echo esc_html(untrailingslashit(rest_url(SHOPAGG_AI_DEPLOYER_REST_NS))); ?></code></td><td>日常读取、部署和内容管理</td></tr>
                            <tr><td><strong>Standalone</strong></td><td><code><?php echo esc_html(SHOPAGG_AI_DEPLOYER_URL . 'standalone.php?action='); ?></code></td><td>WordPress 白屏或 500 时恢复</td></tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="sai-panel">
                <div class="sai-panel-head"><div><span class="sai-section-label">审计记录</span><h2>最近操作</h2></div></div>
                <?php if (!$activity): ?>
                    <div class="sai-empty">暂无新版审计记录。升级后的部署、恢复、插件操作和缓存清理会显示在这里。</div>
                <?php else: ?>
                    <div class="sai-table-wrap">
                        <table class="widefat striped">
                            <thead><tr><th>时间</th><th>操作</th><th>结果</th><th>摘要</th></tr></thead>
                            <tbody>
                            <?php foreach ($activity as $item): ?>
                                <tr>
                                    <td><?php echo esc_html($this->format_utc_time($item['time'] ?? '')); ?></td>
                                    <td><code><?php echo esc_html($item['action'] ?? 'unknown'); ?></code></td>
                                    <td><span class="sai-result <?php echo !empty($item['success']) ? 'ok' : 'bad'; ?>"><?php echo !empty($item['success']) ? '成功' : '失败'; ?></span></td>
                                    <td><?php echo esc_html(wp_json_encode($item['details'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        </div>
        <?php
        $this->render_settings_script();
    }

    private function build_ai_prompt(
        string $site_url,
        string $api_key,
        string $rest_url,
        string $standalone_url
    ): string {
        $version = SHOPAGG_AI_DEPLOYER_VERSION;
        return <<<PROMPT
你是 SHOPAGG AI Deployer，一个通过受保护 REST API 开发和管理 WordPress 网站的 AI 助手。

## 当前连接

- 网站：{$site_url}
- REST API：{$rest_url}
- Standalone 急救通道：{$standalone_url}
- 插件版本：{$version}
- 认证 Header：`X-ShopAgg-AI-Deployer-Key: {$api_key}`

API Key 具有网站完整控制权限。不得在回复、日志、代码、截图或可公开文件中展示它；调用接口时直接放入请求 Header。

## 执行边界

1. 用户只要求检查、分析、说明或给建议时，只进行 GET 请求，不修改网站。
2. 用户明确要求创建、修改、删除、激活、停用、恢复或发布时，才执行对应写操作。
3. 修改现有插件或主题前，先读取相关代码；不要凭文件名猜测现状。
4. 所有代码部署必须使用 `auto_backup: true` 和 `health_check: true`。
5. 删除前确认精确目标并保留备份；不要删除本插件，不修改 `wp-config.php` 或 WordPress 核心。
6. 每次写操作后验证接口结果、网站健康状态及用户要求对应的页面效果。
7. REST API 不可用或网站白屏/500 时，立即改用 Standalone；优先恢复最近备份或禁用可疑插件。
8. 不要声称修改成功，除非已经通过接口返回值和最终验证确认。

## 标准工作流

### 检查或分析

1. `GET /status` 获取环境概况。
2. 按任务调用 `/plugins`、`/themes`、`/list/...`、`/files/...`、`/code/...` 或 `/posts`。
3. 返回基于真实数据的结论，不执行写操作。

### 修改代码

1. 读取目标目录和相关源文件。
2. 只修改完成任务所需的文件，保留现有兼容性。
3. 使用 `POST /deploy` 一次提交同一变更涉及的全部文件。
4. 检查 `success`、每个 `results` 项、`backup_id` 和 `health.ok`。
5. 调用 `GET /health` 并检查实际页面；需要时调用 `POST /cache/clear`。

### 更新插件

1. 用户只要求检查时，调用 `GET /plugins/updates`，不得执行更新。
2. 用户明确指定插件后，调用 `POST /plugins/{slug}/update`。
3. 只有用户明确要求更新全部插件时，才调用 `POST /plugins/update-all`；可通过 `exclude` 排除插件。
4. 每个插件更新前会备份完整目录，更新后检查首页健康状态；失败会自动恢复备份。
5. SHOPAGG AI Deployer 自身不允许通过 REST 自更新；它从 GitHub 获取版本信息，应在 WordPress 插件页面执行审核后的标准更新。

### 事故恢复

1. REST 可用：`POST /backups/{id}/restore`。
2. REST 不可用：Standalone `action=health` → `action=backups` → `action=restore`。
3. 已知是某插件引发故障时，可用 Standalone `action=disable_plugin&slug=插件目录名`。

## 请求格式

所有 REST 请求都携带：

```bash
-H "X-ShopAgg-AI-Deployer-Key: {$api_key}"
```

JSON 请求额外携带：

```bash
-H "Content-Type: application/json"
```

### 状态与健康

```bash
curl -s "{$rest_url}/status" -H "X-ShopAgg-AI-Deployer-Key: {$api_key}"
curl -s "{$rest_url}/health" -H "X-ShopAgg-AI-Deployer-Key: {$api_key}"
curl -s "{$rest_url}/activity?limit=20" -H "X-ShopAgg-AI-Deployer-Key: {$api_key}"
```

### 读取插件、主题和文件

```bash
curl -s "{$rest_url}/plugins" -H "X-ShopAgg-AI-Deployer-Key: {$api_key}"
curl -s "{$rest_url}/themes" -H "X-ShopAgg-AI-Deployer-Key: {$api_key}"
curl -s "{$rest_url}/list/plugins/my-plugin?recursive=1" -H "X-ShopAgg-AI-Deployer-Key: {$api_key}"
curl -s "{$rest_url}/files/plugins/my-plugin/my-plugin.php" -H "X-ShopAgg-AI-Deployer-Key: {$api_key}"
curl -s "{$rest_url}/code/plugin/my-plugin?max_bytes=2097152" -H "X-ShopAgg-AI-Deployer-Key: {$api_key}"
curl -s "{$rest_url}/code/theme/my-theme?max_bytes=2097152" -H "X-ShopAgg-AI-Deployer-Key: {$api_key}"
```

`/code` 默认最多返回 2 MB，并排除备份、vendor、node_modules、.git 和敏感配置。若 `truncated=true`，根据 `skipped` 使用 `/files/{path}` 精确读取需要的文件，不要盲目提高限制。

### 部署文件

```bash
curl -X POST "{$rest_url}/deploy" \
  -H "Content-Type: application/json" \
  -H "X-ShopAgg-AI-Deployer-Key: {$api_key}" \
  -d '{
    "files": [
      {"path": "plugins/my-plugin/my-plugin.php", "content": "<?php ..."},
      {"path": "plugins/my-plugin/assets/app.css", "content": "/* ... */"}
    ],
    "auto_backup": true,
    "health_check": true
  }'
```

只允许写入 `plugins/`、`themes/` 和 `mu-plugins/`。单文件上限 5 MB，每次部署最多 100 个文件、总计 20 MB。写入采用原子替换；任何文件失败或健康检查失败时会自动回滚。

### 插件与主题

```bash
curl -s "{$rest_url}/plugins/updates?force=1" -H "X-ShopAgg-AI-Deployer-Key: {$api_key}"
curl -X POST "{$rest_url}/plugins/my-plugin/update" \
  -H "Content-Type: application/json" \
  -H "X-ShopAgg-AI-Deployer-Key: {$api_key}" \
  -d '{"force_check":true}'
curl -X POST "{$rest_url}/plugins/update-all" \
  -H "Content-Type: application/json" \
  -H "X-ShopAgg-AI-Deployer-Key: {$api_key}" \
  -d '{"force_check":true,"exclude":[],"stop_on_error":false}'
curl -X POST "{$rest_url}/plugins/my-plugin/activate" -H "X-ShopAgg-AI-Deployer-Key: {$api_key}"
curl -X POST "{$rest_url}/plugins/my-plugin/deactivate" -H "X-ShopAgg-AI-Deployer-Key: {$api_key}"
curl -X DELETE "{$rest_url}/plugins/my-plugin" -H "X-ShopAgg-AI-Deployer-Key: {$api_key}"
curl -X POST "{$rest_url}/themes/my-theme/activate" -H "X-ShopAgg-AI-Deployer-Key: {$api_key}"
```

插件激活和停用会执行 WordPress 标准钩子。删除插件会先自动备份并返回 `backup_id`。插件更新接口不会返回下载包地址；不兼容、无下载包或更新后健康检查失败时不会保留异常版本。

### 备份

```bash
curl -s "{$rest_url}/backups" -H "X-ShopAgg-AI-Deployer-Key: {$api_key}"
curl -s "{$rest_url}/backups/BACKUP_ID" -H "X-ShopAgg-AI-Deployer-Key: {$api_key}"
curl -X POST "{$rest_url}/backups/BACKUP_ID/restore" -H "X-ShopAgg-AI-Deployer-Key: {$api_key}"
curl -X DELETE "{$rest_url}/backups/BACKUP_ID" -H "X-ShopAgg-AI-Deployer-Key: {$api_key}"
```

### 文章

```bash
curl -s "{$rest_url}/posts?per_page=10&page=1&status=publish" -H "X-ShopAgg-AI-Deployer-Key: {$api_key}"
curl -s "{$rest_url}/posts/123" -H "X-ShopAgg-AI-Deployer-Key: {$api_key}"
curl -X POST "{$rest_url}/posts" \
  -H "Content-Type: application/json" \
  -H "X-ShopAgg-AI-Deployer-Key: {$api_key}" \
  -d '{"title":"文章标题","content":"<p>正文</p>","status":"draft"}'
curl -X PATCH "{$rest_url}/posts/123" \
  -H "Content-Type: application/json" \
  -H "X-ShopAgg-AI-Deployer-Key: {$api_key}" \
  -d '{"title":"新标题"}'
curl -X DELETE "{$rest_url}/posts/123" -H "X-ShopAgg-AI-Deployer-Key: {$api_key}"
```

删除文章默认移入回收站；只有用户明确要求永久删除时才使用 `?force=true`。

### 缓存与选项

```bash
curl -X POST "{$rest_url}/cache/clear" -H "X-ShopAgg-AI-Deployer-Key: {$api_key}"
curl -s "{$rest_url}/options/option_name" -H "X-ShopAgg-AI-Deployer-Key: {$api_key}"
curl -X POST "{$rest_url}/options/option_name" \
  -H "Content-Type: application/json" \
  -H "X-ShopAgg-AI-Deployer-Key: {$api_key}" \
  -d '{"value":"new value"}'
```

设置选项前先读取原值。写入时系统会保存原值备份；核心插件列表、主题、站点地址和计划任务等高风险选项受保护，必须使用专用接口或由用户在后台处理。

## Standalone 急救通道

Standalone 不加载 WordPress，仅在 REST API 无法工作时使用：

```bash
curl -s "{$standalone_url}?action=health" -H "X-ShopAgg-AI-Deployer-Key: {$api_key}"
curl -s "{$standalone_url}?action=backups" -H "X-ShopAgg-AI-Deployer-Key: {$api_key}"
curl -X POST "{$standalone_url}?action=restore&id=BACKUP_ID" -H "X-ShopAgg-AI-Deployer-Key: {$api_key}"
curl -X POST "{$standalone_url}?action=disable_plugin&slug=my-plugin" -H "X-ShopAgg-AI-Deployer-Key: {$api_key}"
curl -X POST "{$standalone_url}?action=disable_all_plugins" -H "X-ShopAgg-AI-Deployer-Key: {$api_key}"
curl -s "{$standalone_url}?action=read&path=plugins/my-plugin/my-plugin.php" -H "X-ShopAgg-AI-Deployer-Key: {$api_key}"
```

完成任务时简洁报告：做了什么、备份 ID、健康检查结果、是否还有需要用户确认的事项。任何回复中都不要复述 API Key。
PROMPT;
    }

    private function get_status(): array {
        $backup_dir = SHOPAGG_AI_DEPLOYER_BACKUP_DIR;
        $directories = is_dir($backup_dir) ? glob($backup_dir . '/*', GLOB_ONLYDIR) : [];
        return [
            'backup_count' => is_array($directories) ? count($directories) : 0,
            'backup_writable' => is_dir($backup_dir) && is_writable($backup_dir),
            'standalone_ok' => is_readable(SHOPAGG_AI_DEPLOYER_DIR . 'standalone.php'),
            'config_parsable' => is_readable(ABSPATH . 'wp-config.php'),
        ];
    }

    private function mask_key(string $key): string {
        if (strlen($key) < 12) {
            return str_repeat('•', max(8, strlen($key)));
        }
        return substr($key, 0, 5) . str_repeat('•', 20) . substr($key, -5);
    }

    private function format_utc_time(string $time): string {
        $timestamp = strtotime($time);
        return $timestamp ? wp_date('Y-m-d H:i:s', $timestamp) : $time;
    }

    private function render_styles(): void {
        ?>
        <style>
            .shopagg-deployer{max-width:1120px;margin-top:24px;color:#1d2327}
            .shopagg-deployer *{box-sizing:border-box}
            .shopagg-deployer h1,.shopagg-deployer h2,.shopagg-deployer p{margin-top:0}
            .sai-hero,.sai-page-title{display:flex;align-items:flex-end;justify-content:space-between;gap:24px;margin:0 0 20px;padding:0 0 18px;border-bottom:1px solid #c3c4c7;background:transparent;color:#1d2327}
            .sai-page-title{padding-top:0}
            .sai-hero h1,.sai-page-title h1{font-size:28px;font-weight:500;line-height:1.2;margin:5px 0 8px;color:#1d2327}
            .sai-hero p,.sai-page-title p{font-size:14px;line-height:1.6;max-width:720px;margin:0;color:#50575e}
            .sai-eyebrow,.sai-section-label{font-size:11px;font-weight:600;letter-spacing:.06em;text-transform:uppercase}
            .sai-eyebrow{color:#646970}
            .sai-section-label{display:block;margin-bottom:5px;color:#2271b1}
            .sai-hero-status{display:flex;align-items:center;gap:8px;min-width:190px;padding:0 0 3px;background:transparent}
            .sai-hero-status strong,.sai-hero-status small{display:block;color:#1d2327}.sai-hero-status small{margin-top:2px;color:#646970}
            .sai-dot{width:8px;height:8px;border-radius:50%;background:#00a32a}
            .sai-metrics{display:grid;grid-template-columns:repeat(4,1fr);margin-bottom:20px;border:1px solid #c3c4c7;background:#fff}
            .sai-metric{display:flex;align-items:center;gap:11px;min-height:72px;padding:14px 16px;border-right:1px solid #dcdcde;background:#fff}
            .sai-metric:last-child{border-right:0}
            .sai-metric>.dashicons{width:24px;height:24px;color:#2271b1;font-size:20px}
            .sai-metric strong,.sai-metric small{display:block}.sai-metric strong{font-size:14px}.sai-metric small{margin-top:3px;color:#646970}
            .sai-panel{margin-bottom:20px;padding:22px;border:1px solid #c3c4c7;background:#fff}
            .sai-panel h2{font-size:18px;font-weight:600;margin:0;color:#1d2327}.sai-panel>p{color:#50575e;line-height:1.6}
            .sai-panel-head{display:flex;align-items:flex-start;justify-content:space-between;gap:18px;margin-bottom:18px;padding-bottom:14px;border-bottom:1px solid #dcdcde}
            .sai-panel-head p{max-width:720px;margin:6px 0 0;color:#646970;line-height:1.55}
            .sai-steps{display:grid;grid-template-columns:repeat(3,1fr)}
            .sai-steps>div{position:relative;padding:4px 24px 4px 48px;border-right:1px solid #dcdcde}
            .sai-steps>div:first-child{padding-left:48px}.sai-steps>div:last-child{border-right:0}
            .sai-steps span{position:absolute;left:8px;top:2px;color:#2271b1;font-size:13px;font-weight:600}
            .sai-steps strong{display:block;font-size:14px}.sai-steps p{margin:5px 0 0;color:#646970;line-height:1.45}
            .sai-actions{display:flex;gap:9px;flex-wrap:wrap}.sai-actions .button,.sai-page-title .button{display:inline-flex;align-items:center;gap:5px}
            .sai-security-note{display:flex;gap:10px;margin-bottom:14px;padding:11px 13px;border-left:4px solid #dba617;background:#f6f7f7;color:#3c434a}
            .sai-security-note .dashicons{margin-top:1px;color:#996800}.sai-security-note strong{display:block}.sai-security-note p{margin:3px 0 0;color:#50575e}
            #sai-prompt{display:block;width:100%;min-height:560px;padding:16px;border:1px solid #8c8f94;border-radius:0;resize:vertical;background:#f6f7f7;color:#1d2327;font:12.5px/1.65 SFMono-Regular,Consolas,Liberation Mono,monospace;box-shadow:none}
            #sai-prompt:focus{outline:1px solid #2271b1;border-color:#2271b1;box-shadow:0 0 0 1px #2271b1}
            .sai-prompt-foot{display:flex;justify-content:space-between;margin-top:8px;color:#646970;font-size:12px}
            .sai-connection-grid{display:grid;grid-template-columns:1fr 1fr;border-top:1px solid #dcdcde;border-left:1px solid #dcdcde}
            .sai-connection-grid>div{min-width:0;padding:12px 14px;border-right:1px solid #dcdcde;border-bottom:1px solid #dcdcde;background:#fff}.sai-connection-grid small{display:block;margin-bottom:5px;color:#646970}.sai-connection-grid code{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;background:transparent;padding:0;color:#1d2327}
            .sai-two-column{display:grid;grid-template-columns:1fr 1fr;gap:20px}.sai-two-column .sai-panel{height:calc(100% - 20px)}
            .sai-key-box{display:flex;align-items:center;gap:8px;padding:10px;border:1px solid #c3c4c7;background:#f6f7f7}.sai-key-box code{min-width:0;flex:1;overflow:hidden;text-overflow:ellipsis}
            .sai-danger-form{margin-top:16px;padding-top:16px;border-top:1px solid #dcdcde}
            .sai-check-list{display:grid;gap:12px;margin:0;padding:0;list-style:none}.sai-check-list li{display:flex;align-items:center;gap:9px}.sai-check-list li>span{width:9px;height:9px;border-radius:50%}.sai-check-list .ok>span{background:#22c55e}.sai-check-list .bad>span{background:#ef4444}
            .sai-table-wrap{overflow-x:auto}.sai-table-wrap table{border:0;box-shadow:none}.sai-table-wrap th{font-weight:700}.sai-table-wrap td{vertical-align:middle}
            .sai-result{display:inline-block;font-size:12px;font-weight:600}.sai-result.ok{color:#008a20}.sai-result.bad{color:#d63638}
            .sai-empty{padding:24px;border:1px dashed #c3c4c7;text-align:center;color:#646970;background:#f6f7f7}
            .sai-new-key{display:flex;align-items:center;gap:8px}.sai-new-key code{word-break:break-all}
            .sai-toast{position:fixed;right:24px;bottom:24px;z-index:99999;padding:10px 14px;border-left:4px solid #2271b1;background:#fff;color:#1d2327;box-shadow:0 1px 3px rgba(0,0,0,.2)}
            @media(max-width:900px){.sai-metrics{grid-template-columns:1fr 1fr}.sai-metric:nth-child(2){border-right:0}.sai-metric:nth-child(-n+2){border-bottom:1px solid #dcdcde}.sai-two-column{grid-template-columns:1fr}.sai-hero,.sai-page-title{align-items:flex-start;flex-direction:column}.sai-steps{grid-template-columns:1fr}.sai-steps>div{padding:12px 12px 12px 48px;border-right:0;border-bottom:1px solid #dcdcde}.sai-steps>div:last-child{border-bottom:0}.sai-steps span{top:12px}}
            @media(max-width:600px){.shopagg-deployer{margin-right:10px}.sai-hero h1,.sai-page-title h1{font-size:24px}.sai-metrics,.sai-connection-grid{grid-template-columns:1fr}.sai-metric{border-right:0;border-bottom:1px solid #dcdcde}.sai-metric:last-child{border-bottom:0}.sai-panel{padding:16px}.sai-panel-head{flex-direction:column}.sai-prompt-foot{gap:8px;flex-direction:column}}
        </style>
        <?php
    }

    private function render_prompt_script(): void {
        ?>
        <script>
        (() => {
            const prompt = document.getElementById('sai-prompt');
            const toast = message => {
                const old = document.querySelector('.sai-toast');
                if (old) old.remove();
                const node = document.createElement('div');
                node.className = 'sai-toast';
                node.textContent = message;
                document.body.appendChild(node);
                setTimeout(() => node.remove(), 1800);
            };
            document.getElementById('sai-copy-prompt')?.addEventListener('click', async () => {
                try {
                    await navigator.clipboard.writeText(prompt.value);
                } catch (e) {
                    prompt.select();
                    document.execCommand('copy');
                    window.getSelection()?.removeAllRanges();
                }
                toast('提示词已复制');
            });
            document.getElementById('sai-download-prompt')?.addEventListener('click', () => {
                const blob = new Blob([prompt.value], {type: 'text/markdown;charset=utf-8'});
                const url = URL.createObjectURL(blob);
                const link = document.createElement('a');
                link.href = url;
                link.download = 'shopagg-ai-deployer-prompt.md';
                link.click();
                URL.revokeObjectURL(url);
                toast('Markdown 文档已下载');
            });
        })();
        </script>
        <?php
    }

    private function render_settings_script(): void {
        ?>
        <script>
        (() => {
            const toast = message => {
                const node = document.createElement('div');
                node.className = 'sai-toast';
                node.textContent = message;
                document.body.appendChild(node);
                setTimeout(() => node.remove(), 1800);
            };
            const current = document.getElementById('sai-current-key');
            let revealed = false;
            document.getElementById('sai-reveal-key')?.addEventListener('click', event => {
                revealed = !revealed;
                current.textContent = revealed ? current.dataset.key : '<?php echo esc_js($this->mask_key(shopagg_ai_deployer_get_api_key())); ?>';
                event.currentTarget.textContent = revealed ? '隐藏' : '显示';
            });
            document.getElementById('sai-copy-key')?.addEventListener('click', async () => {
                await navigator.clipboard.writeText(current.dataset.key);
                toast('API Key 已复制');
            });
            document.querySelectorAll('[data-copy-target]').forEach(button => {
                button.addEventListener('click', async () => {
                    const target = document.getElementById(button.dataset.copyTarget);
                    await navigator.clipboard.writeText(target.textContent);
                    toast('已复制');
                });
            });
        })();
        </script>
        <?php
    }
}

function shopagg_ai_deployer_admin_bootstrap(): void {
    (new SHOPAGG_AI_Deployer_Admin())->register();
}
