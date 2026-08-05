# Changelog

Notable changes to AgoraPress. Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versioning: [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Core version: `AP_VERSION` in `ap-includes/version.php` (currently **0.1.2-dev**).  
No tagged public release yet — everything below is **[Unreleased]**.

## [Unreleased]

### Changed

- Visual **WYSIWYG editor** for posts, pages, comments, and forums (formatted preview while editing; HTML on save). Legacy Markdown/BBCode still converts on display.
- Concise public docs: this changelog and the README install guide.

### Fixed

- Published posts showed raw markup (`**bold**`, `[b]…[/b]`) instead of formatted HTML.
- Default theme primary/footer menus could render twice when no custom menu was assigned.

### Added

**Install & ops**

- Web installer (`/install/`), CLI install (`php install/cli.php`), Docker Compose
- Config sample (`ap-config-sample.php`), table prefix `ap_`, multi-driver DB (MySQL/MariaDB, SQLite, PostgreSQL)
- Release packaging (`bin/package-release.php` → zip + SHA-256 + `version.json.example`)
- One-click core update + version check (no site identity sent)
- `ap-cli` for options, plugins, themes, users, DB migrate, cache, cron, health
- Live-site install readiness: production hardening denies secrets, SQLite/DB downloads, and direct `ap-includes/` access (Apache `.htaccess`, Nginx example)

**CMS**

- Static Pages, Blog, Forum as independent modules
- Posts/pages, revisions, media library, comments, categories/tags
- Visual editor, shortcodes (`AP_Shortcode`), content format (HTML + legacy Markdown/BBCode)
- Menus, widgets, feeds (RSS/Atom), sitemaps, Open Graph
- Default **Agora** theme (six color schemes) + Classic WordPress Theme Compatibility Layer
- Hooks, plugins, mu-plugins, Settings API, Options API (`AP_Options`), query layer (`AP_Query`)

**Forums**

- Hierarchy, topics/replies, attachments, groups & permissions
- Moderation (`AP_Forum_Moderation`), search, flood/spam guards, PMs, online/unread

**Admin & security**

- Responsive admin (light/dark via `prefers-color-scheme`), roles/caps, users, Site Health
- Nonces, rate limits, privacy / personal data export & erase, i18n/RTL (`AP_L10n` / gettext MO packs)
- Object cache API + `object-cache.php` drop-in; page-cache hooks
- Performance and accessibility basics (autoload priming, landmarks, contrast)
- Cron (`AP_Cron`), transients (`AP_Transient`)
- WordPress WXR + phpBB importers
- REST API (`/ap-json/`), optional sample content
- GPLv2-or-later; no telemetry by default

**Tooling**

- PHPUnit (`phpunit.xml.dist`), PHPCS, PHPStan, pytest smokes under `tests/`
- Layout: `ap-admin/`, `ap-includes/`, `ap-content/`, `install/`, `docker-compose.yml`
