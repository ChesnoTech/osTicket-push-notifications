# Changelog

All notable changes to this project will be documented in this file.

Format based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
This project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.0] - 2026-04-04

### Added
- Auto-update system with GitHub release checking
- Update Manager admin UI (full-page, Joomla-style)
- Release channels: Stable, RC, Beta, Dev
- Automatic file + database backup before updates
- One-click rollback from backup list
- Database migration framework (`migrations/`)
- Cron-based update check every 12 hours
- Admin notification banner when update available
- Update Channel selector in plugin config
- `.gitignore` and `.github/` templates

## [1.0.0] - 2026-03-31

### Added
- Web Push (PWA) notifications for osTicket staff
- VAPID authentication (zero external dependencies)
- Signal handlers: ticket.created, object.created, object.edited, model.updated, cron
- Per-agent preferences: event toggles, department filter, quiet hours
- Configurable notification icon URL
- Independent from osTicket email alert settings
- Service Worker with notification click navigation
- Test notification endpoint
- Subscription cleanup on cron
- Multilingual README (8 languages)

[1.1.0]: https://github.com/ChesnoTech/osTicket-push-notifications/releases/tag/v1.1.0
[1.0.0]: https://github.com/ChesnoTech/osTicket-push-notifications/releases/tag/v1.0.0
