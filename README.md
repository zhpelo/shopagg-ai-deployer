# SHOPAGG AI Deployer

SHOPAGG AI Deployer 是一个面向 WordPress 的远程开发与恢复插件，让受信任的 AI 助手能够通过认证 REST API 读取插件和主题代码、部署文件、管理备份、执行健康检查，并在 WordPress 无法启动时使用独立急救通道恢复网站。

当前版本：`1.3.0`

## 主要功能

- 通过专属 API Key 认证所有远程请求
- 读取和部署插件、主题及 MU Plugin 文件
- 部署前自动创建文件快照
- 原子写入，避免文件只写入一部分
- 部署后自动检查网站健康状态
- 写入失败或健康检查失败时自动回滚
- 列出、查看、恢复和删除备份
- 检查、单独更新或批量更新已安装插件
- 插件更新前完整备份，失败或健康检查异常时自动回滚
- 从 GitHub main 分支获取 SHOPAGG AI Deployer 自身的更新
- 管理插件的激活、停用和安全删除
- 激活主题
- 创建、读取、更新和删除文章
- 清理 WordPress、LiteSpeed Cache 和 WP Rocket 缓存
- 保存最近 100 条远程操作审计记录
- 提供不依赖 WordPress 加载的 Standalone 急救通道
- WordPress 后台自动生成可复制、可下载的 AI 提示词文档

## 系统要求

- WordPress 6.5 或更高版本
- PHP 8.0 或更高版本
- Linux 服务器
- Standalone 数据库急救功能需要 PDO MySQL

## 安装

1. 下载本仓库。
2. 将目录命名为 `shopagg-ai-deployer`。
3. 上传到 `wp-content/plugins/`。
4. 在 WordPress 后台激活 **SHOPAGG AI Deployer**。
5. 打开 **AI Deployer → AI 提示词**，复制当前网站专属的连接文档。

插件首次运行时会自动生成：

```text
wp-content/plugins/shopagg-ai-deployer/includes/config.php
```

该文件保存网站专属 API Key，已被 `.gitignore` 排除。不要将它提交到代码仓库。

## REST API

基础路径：

```text
https://example.com/wp-json/shopagg-ai-deployer/v1
```

所有请求必须携带：

```text
X-ShopAgg-AI-Deployer-Key: YOUR_API_KEY
```

常用端点：

| 方法 | 端点 | 用途 |
|---|---|---|
| GET | `/status` | 获取环境、插件、主题和备份概况 |
| GET | `/health` | 检查网站健康状态 |
| GET | `/activity` | 查看最近远程操作 |
| POST | `/deploy` | 批量部署文件并自动备份、检查、回滚 |
| GET | `/plugins` | 列出插件 |
| GET | `/plugins/updates` | 检查可用的插件更新 |
| POST | `/plugins/{slug}/update` | 备份、更新并验证指定插件 |
| POST | `/plugins/update-all` | 批量更新插件，可设置排除项 |
| POST | `/plugins/{slug}/activate` | 激活插件 |
| POST | `/plugins/{slug}/deactivate` | 停用插件 |
| DELETE | `/plugins/{slug}` | 备份并删除插件 |
| GET | `/themes` | 列出主题 |
| POST | `/themes/{slug}/activate` | 激活主题 |
| GET | `/backups` | 列出备份 |
| POST | `/backups/{id}/restore` | 恢复备份 |
| GET | `/code/{type}/{slug}` | 读取插件或主题源码 |
| POST | `/cache/clear` | 清理缓存 |

部署示例：

```bash
curl -X POST "https://example.com/wp-json/shopagg-ai-deployer/v1/deploy" \
  -H "Content-Type: application/json" \
  -H "X-ShopAgg-AI-Deployer-Key: YOUR_API_KEY" \
  -d '{
    "files": [
      {
        "path": "plugins/example-plugin/example-plugin.php",
        "content": "<?php\n// Plugin code"
      }
    ],
    "auto_backup": true,
    "health_check": true
  }'
```

插件更新示例：

```bash
# 只检查，不修改
curl -s "https://example.com/wp-json/shopagg-ai-deployer/v1/plugins/updates?force=1" \
  -H "X-ShopAgg-AI-Deployer-Key: YOUR_API_KEY"

# 更新指定插件
curl -X POST "https://example.com/wp-json/shopagg-ai-deployer/v1/plugins/example-plugin/update" \
  -H "Content-Type: application/json" \
  -H "X-ShopAgg-AI-Deployer-Key: YOUR_API_KEY" \
  -d '{"force_check":true}'

# 批量更新，并排除指定插件
curl -X POST "https://example.com/wp-json/shopagg-ai-deployer/v1/plugins/update-all" \
  -H "Content-Type: application/json" \
  -H "X-ShopAgg-AI-Deployer-Key: YOUR_API_KEY" \
  -d '{"force_check":true,"exclude":["example-plugin"],"stop_on_error":false}'
```

每个插件更新前都会备份完整插件目录，并在更新后检查网站健康状态；失败时自动回滚。为了避免在请求处理中替换自身，REST 接口禁止远程自更新 SHOPAGG AI Deployer。自身版本由 GitHub main 分支提供，确认变更后可在 WordPress 插件页面使用标准更新流程。

## Standalone 急救通道

当 WordPress 因错误代码而白屏或返回 500 时，可以使用：

```text
https://example.com/wp-content/plugins/shopagg-ai-deployer/standalone.php
```

示例：

```bash
curl -s "https://example.com/wp-content/plugins/shopagg-ai-deployer/standalone.php?action=health" \
  -H "X-ShopAgg-AI-Deployer-Key: YOUR_API_KEY"
```

Standalone 不加载 WordPress，可用于：

- 检查网站状态
- 查看和恢复备份
- 读取或重新部署文件
- 禁用指定插件
- 紧急禁用所有插件

## 安全设计

- 所有接口使用 `hash_equals` 验证 API Key
- REST 响应强制使用 `private/no-store`，避免代理或 LiteSpeed 缓存认证结果
- 文件操作限制在 `wp-content`
- 部署仅允许 `plugins/`、`themes/` 和 `mu-plugins/`
- 阻止目录穿越和符号链接越界
- 禁止通过源码接口读取 API Key、备份、`.env` 和私钥文件
- 禁止远程停用或删除 Deployer 自身
- 禁止通过 REST 更新 Deployer 自身，GitHub 更新包固定到具体提交
- 删除插件和文件前自动创建备份
- 审计日志不会保存 API Key 和源码内容

## 不应提交的运行时数据

```text
includes/config.php
backups/
```

如果 API Key 曾经出现在公开位置，请立即在 WordPress 后台重新生成。

## License

GPL-2.0-or-later
