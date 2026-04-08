# Changelog

All notable changes to this project will be documented in this file.

Format based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
This project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.3.0] - 2026-04-08

### Added
- Minor vs major update detection — admin can see and choose between minor (same major version, safe) and major (new major version, may break) updates
- Separate update cards with distinct styling: green for minor, amber for major
- Version-targeted updates — admin selects exactly which version to install
- Breaking change warning on major upgrade cards
- `checkForUpdates()` method scans all releases to find best minor and best major candidate
- `fetchAllForChannel()` method retrieves all eligible releases for a channel
- `fetchReleaseByVersion()` method fetches a specific release by tag for targeted installs

### Changed
- Update check API now returns `{minor, major}` objects instead of a single flat result
- Apply update API now accepts `{version}` in POST body to target a specific release
- "Update Status" card renamed to "Available Updates" with enhanced layout

## [1.2.0] - 2026-04-07

### Changed
- Move update management from floating banner to "Updates" tab on plugin config page
- Remove update notification from non-related pages — now only visible in plugin settings
- Remove `update_section` and `update_channel` from config form (managed in Updates tab)

### Added
- Tabbed config UI: "Settings" tab for plugin config, "Updates" tab for update management
- Update badge on tab when new version is available
- Dark mode support for Updates tab UI
- URL hash `#updates` to deep-link directly to the Updates tab

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

[1.3.0]: https://github.com/ChesnoTech/osTicket-push-notifications/releases/tag/v1.3.0
[1.2.0]: https://github.com/ChesnoTech/osTicket-push-notifications/releases/tag/v1.2.0
[1.1.1]: https://github.com/ChesnoTech/osTicket-push-notifications/releases/tag/v1.1.1
[1.1.0]: https://github.com/ChesnoTech/osTicket-push-notifications/releases/tag/v1.1.0
[1.0.0]: https://github.com/ChesnoTech/osTicket-push-notifications/releases/tag/v1.0.0
