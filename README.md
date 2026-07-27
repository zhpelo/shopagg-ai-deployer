# SHOPAGG AI Deployer

SHOPAGG AI Deployer 2.0 是一个面向 AI Agent 的 WordPress 全控制入口。插件安装激活后会自动生成一把主 Key；后台提供已经写入站点地址、接口地址和 Key 的完整提示词，复制给 AI 即可使用。

当前版本：`2.0.0`

## 需求

- WordPress 6.9+
- PHP 8.0+
- 独立恢复入口的停用插件功能需要 PDO MySQL

## 使用方法

1. 安装并激活插件。
2. 打开“AI Deployer → AI 连接”。
3. 点击“复制提示词”。
4. 把提示词发送给支持 HTTP/API 工具的 AI。

无需创建独立 WordPress 用户、Application Password、角色、capability 或 AI 平台账号。提示词已包含唯一认证头：

```text
X-ShopAgg-AI-Deployer-Key: 自动生成的主 Key
```

Abilities API、插件完整 REST API和独立恢复入口共用这把 Key。认证成功后，请求自动采用站点管理员身份。

## 2.0 架构

- **Abilities 能力层**：使用 WordPress 6.9+ Abilities API 提供可发现的工具及 JSON Schema。
- **单 Key 全权限入口**：Key 自动映射为 WordPress 管理员，不再维护第二套用户或细分权限。
- **服务层**：工作区、部署、健康验证、REST 网关和扩展管理与接口层解耦。
- **事务式部署**：支持部署锁、幂等 `operation_id`、SHA-256 前置条件、PHP 语法检查、完整备份、健康验证和自动回滚。
- **原生资源复用**：AI 可以发现和调用站点上任意已注册的 WordPress REST 路由，包括 WordPress、WooCommerce 和第三方插件资源。
- **独立恢复入口**：WordPress 启动失败时，不加载 WordPress 即可检查站点、恢复备份或停用插件。

## AI 能力

| 能力 | 用途 |
|---|---|
| `site-inspect` | 站点、版本、扩展、备份和连接概况 |
| `site-verify` | 检查首页及指定站内路径 |
| `workspace-tree` | 返回文件树、大小、时间和哈希 |
| `workspace-search` | 按文本搜索代码，返回行号和摘要 |
| `workspace-read` | 批量分段读取文件 |
| `deployment-preview` | 路径、大小、PHP 语法和哈希冲突预检 |
| `deployment-apply` | 带备份、健康验证和回滚的事务部署 |
| `rest-discover` | 发现站点全部 WordPress REST 资源 |
| `rest-read/write/delete` | 以管理员身份操作任意 REST 资源 |
| `extensions-list/manage` | 列出、激活、停用、更新和删除插件/主题 |

Abilities 根地址：

```text
https://example.com/wp-json/wp-abilities/v1
```

完整 REST API：

```text
https://example.com/wp-json/shopagg-ai-deployer/v1
```

如果安装官方 WordPress MCP Adapter，标记为公开的 SHOPAGG Abilities 也可以通过 MCP 自动发现和执行。

## 代码部署流程

1. 使用 `workspace-tree/search/read` 读取真实文件和 SHA-256。
2. 使用 `deployment-preview` 校验变更。
3. 使用唯一 `operation_id` 调用 `deployment-apply`。
4. 服务端在锁内重新检查哈希、建立备份、执行修改并验证站点。
5. PHP 校验、写入或健康检查失败时自动恢复；重复 `operation_id` 直接返回原结果。

这些机制用于保证 AI 操作可恢复，不限制主 Key 的 WordPress 权限范围。

## 运行时数据

默认位置：

```text
wp-content/.shopagg-ai-deployer/
```

其中保存主 Key、备份、幂等结果和 JSONL 操作日志。数据不在插件目录内，插件升级不会覆盖。

可以在 `wp-config.php` 指定其他位置：

```php
define('SHOPAGG_AI_DEPLOYER_DATA_DIR', '/path/to/shopagg-ai-deployer-data');
```

独立恢复入口不加载 WordPress，因此也可以通过 PHP-FPM 的 `SHOPAGG_AI_DEPLOYER_DATA_DIR` 环境变量指定同一路径。

## 独立恢复入口

```text
https://example.com/wp-content/plugins/shopagg-ai-deployer/standalone.php
```

仍然携带统一主 Key：

```text
X-ShopAgg-AI-Deployer-Key: 主 Key
```

支持：

```text
GET  ?action=health
GET  ?action=backups
POST ?action=restore&id=BACKUP_ID
POST ?action=disable_plugin&slug=PLUGIN_SLUG
```

## License

GPL-2.0-or-later
