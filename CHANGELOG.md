# Changelog

All notable changes to this project will be documented in this file.

Format based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
This project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.1] - 2026-04-04

### Fixed
- Replace `addslashes()` with `json_encode()` for inline JS config (XSS safety)
- Validate `$_GET['channel']` with `in_array()` against allowed update channels
- Wrap database rollback operations in `BEGIN`/`COMMIT` transaction for atomicity

### Added
- GitHub Actions CI workflow: PHP lint (8.1–8.3), plugin manifest validation, security pattern checks

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

[1.1.1]: https://github.com/ChesnoTech/osTicket-push-notifications/releases/tag/v1.1.1
[1.1.0]: https://github.com/ChesnoTech/osTicket-push-notifications/releases/tag/v1.1.0
[1.0.0]: https://github.com/ChesnoTech/osTicket-push-notifications/releases/tag/v1.0.0
