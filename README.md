🌐 **English** | [Русский](docs/README.ru.md) | [العربية](docs/README.ar.md) | [Español](docs/README.es.md) | [Français](docs/README.fr.md) | [Deutsch](docs/README.de.md) | [中文](docs/README.zh.md) | [Português](docs/README.pt.md) | [Türkçe](docs/README.tr.md)

# osTicket Push Notifications Plugin

Web Push (PWA) notifications for osTicket staff panel. Delivers real-time browser push notifications for ticket events, completely independent from email alerts.

## Features

- **Real-time push notifications** for: new tickets, new messages/replies, assignments, transfers, overdue tickets
- **Independent from email alerts** — works even when all email alerts are disabled
- **Agent preferences** with per-event toggles, per-department filtering, and quiet hours
- **Admin controls** with master switch, per-event toggles, custom notification icon, and VAPID key management
- **Multi-language support** using osTicket's built-in translation system
- **Mobile responsive** with bell + gear icons in mobile nav bar
- **Dark mode** compatible (osTicketAwesome theme)
- **Service Worker** based — works even when the browser tab is closed
- **Zero dependencies** — pure PHP Web Push implementation, no Composer required

## Requirements

- osTicket **1.18+**
- PHP **8.0+** with `openssl` extension (for VAPID key generation and payload encryption)
- HTTPS (required by Web Push API)

## Installation

1. Copy the `push-notifications/` folder to `include/plugins/`
2. In Admin Panel, go to **Manage > Plugins > Add New Plugin**
3. Click **Install** next to "Push Notifications"
4. Set Status to **Active** and save
5. Go to **Instances** tab, click **Add New Instance**
6. Set instance name, status to **Enabled**
7. In the **Config** tab:
   - Enter a VAPID Subject (e.g., `mailto:admin@example.com`)
   - Check **Enable Push Notifications**
   - Enable desired alert types
   - Optionally set a custom Notification Icon URL
   - Save — VAPID keys are auto-generated

## How It Works

### Admin Configuration (Admin Panel > Plugins > Push Notifications)

| Setting | Description |
|---|---|
| Enable Push Notifications | Master on/off switch |
| VAPID Subject | Contact email for push service identification |
| VAPID Keys | Auto-generated on first save |
| New Ticket / Message / Assignment / Transfer / Overdue Alerts | Per-event global toggles |
| Notification Icon URL | Custom icon/logo for push notifications (leave empty for default) |

### Agent Preferences (Gear icon next to bell in nav bar)

Each agent can customize their own notification preferences:

| Setting | Description |
|---|---|
| Event toggles | Choose which event types trigger push notifications |
| Department filter | Only receive notifications from selected departments |
| Quiet hours | Suppress notifications during a time range (supports overnight spans) |

### Notification Flow

```
Plugin master switch ON?
  └─ Plugin event toggle ON? (e.g., alert_new_ticket)
      └─ Agent has push subscription?
          └─ Agent event preference ON?
              └─ Ticket dept in agent's dept filter? (empty = all)
                  └─ Not in agent's quiet hours?
                      └─ SEND PUSH ✓
```

> **Note:** Push notifications are completely independent from osTicket's email alert settings. You can disable all email alerts and push will continue working.

## Architecture

| File | Purpose |
|---|---|
| `plugin.php` | Plugin manifest (id, version, name) |
| `config.php` | Admin config fields + VAPID key generation + DB table creation |
| `class.PushNotificationsPlugin.php` | Bootstrap, signal hooks, AJAX routes, asset injection |
| `class.PushNotificationsAjax.php` | AJAX controller (subscribe, unsubscribe, preferences, test) |
| `class.PushDispatcher.php` | Notification dispatch with recipient logic + preferences filtering |
| `class.WebPush.php` | Pure PHP Web Push sender (VAPID + ECDH + AES-128-GCM, no Composer) |
| `assets/push-notifications.js` | Client-side bell/gear UI, preferences modal, service worker registration |
| `assets/push-notifications.css` | Styles for nav icons, modal, toggles, dark mode |
| `assets/sw.js` | Service worker for receiving and displaying push notifications |

## Database Tables

The plugin creates two tables on first config save:

- `ost_push_subscription` — stores browser push subscription endpoints per agent
- `ost_push_preferences` — stores per-agent notification preferences

## Author

ChesnoTech

## License

GPL-2.0 (same as osTicket)
