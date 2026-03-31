[English](../README.md) | [Русский](README.ru.md) | [العربية](README.ar.md) | [Español](README.es.md) | [Français](README.fr.md) | [Deutsch](README.de.md) | 🌐 **中文** | [Português](README.pt.md) | [Türkçe](README.tr.md)

# osTicket 推送通知插件

osTicket 工作人员面板的 Web Push（PWA）通知功能。为工单事件提供实时浏览器推送通知，完全独立于电子邮件提醒。

## 功能特性

- **实时推送通知**，涵盖以下事件：新工单、新消息/回复、工单分配、工单转移、逾期工单
- **独立于电子邮件提醒** — 即使所有电子邮件提醒均已禁用，仍可正常工作
- **坐席偏好设置**，支持按事件单独开关、按部门筛选以及免打扰时段设置
- **管理员控制**，包含主开关、按事件单独开关、自定义通知图标及 VAPID 密钥管理
- **多语言支持**，使用 osTicket 内置翻译系统
- **移动端自适应**，在移动端导航栏提供铃铛图标和齿轮图标
- **深色模式**兼容（osTicketAwesome 主题）
- 基于 **Service Worker** — 即使浏览器标签页已关闭，仍可正常工作
- **零依赖** — 纯 PHP Web Push 实现，无需 Composer

## 系统要求

- osTicket **1.18+**
- PHP **8.0+**，需启用 `openssl` 扩展
- HTTPS（Web Push API 必需）

## 安装步骤

1. 将 `push-notifications/` 文件夹复制到 `include/plugins/` 目录
2. 在管理面板中，进入 **管理 > 插件 > 添加新插件**
3. 点击"Push Notifications"旁边的 **安装**
4. 将状态设置为 **启用** 并保存
5. 进入 **实例** 标签，点击 **添加新实例**
6. 设置实例名称，将状态设置为 **已启用**
7. 在 **配置** 标签中：
   - 输入 VAPID Subject（例如 `mailto:admin@example.com`）
   - 勾选 **启用推送通知**
   - 启用所需的提醒类型
   - 可选填自定义通知图标 URL
   - 保存 — VAPID 密钥将自动生成

## 工作原理

### 管理员配置

| 设置项 | 说明 |
|---|---|
| 启用推送通知 | 总开关（开/关） |
| VAPID Subject | 用于推送服务识别的联系邮箱 |
| VAPID Keys | 首次保存时自动生成 |
| 提醒开关 | 各事件类型的全局开关 |
| 通知图标 URL | 自定义图标/Logo（留空使用默认图标） |

### 坐席偏好设置

| 设置项 | 说明 |
|---|---|
| 事件开关 | 选择哪些事件类型触发推送通知 |
| 部门筛选 | 仅接收来自所选部门的通知 |
| 免打扰时段 | 在指定时间段内屏蔽通知 |

### 通知发送流程

```
插件总开关已开启？
  └─ 插件事件开关已开启？
      └─ 坐席已订阅推送？
          └─ 坐席事件偏好已开启？
              └─ 工单部门在坐席的部门筛选范围内？（空 = 全部）
                  └─ 当前不在坐席的免打扰时段内？
                      └─ 发送推送 ✓
```

> **注意：** 推送通知与 osTicket 的电子邮件提醒设置完全独立。

## 架构说明

| 文件 | 用途 |
|---|---|
| `plugin.php` | 插件清单文件 |
| `config.php` | 管理员配置 + VAPID 密钥 + 数据库表 |
| `class.PushNotificationsPlugin.php` | 启动引导、信号处理、AJAX、静态资源 |
| `class.PushNotificationsAjax.php` | AJAX 控制器 |
| `class.PushDispatcher.php` | 通知分发与筛选 |
| `class.WebPush.php` | 纯 PHP Web Push 发送器 |
| `assets/push-notifications.js` | 客户端 UI 脚本 |
| `assets/push-notifications.css` | 样式表 |
| `assets/sw.js` | Service Worker |

## 数据库表

- `ost_push_subscription` — 每位坐席的推送订阅端点
- `ost_push_preferences` — 每位坐席的通知偏好设置

## 作者

ChesnoTech

## 许可证

GPL-2.0（与 osTicket 相同）
