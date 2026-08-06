# Changelog

Notable changes to AgoraPress. Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versioning: [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Core version: `AP_VERSION` in `ap-includes/version.php` (currently **0.2.1-beta**).

## [Unreleased]

### Added

- (Post-release work lands here.)

## [0.2.1-beta] - 2026-08-06

Schema `AP_DB_VERSION` **11**. Privacy posture unchanged: no telemetry by default; optional local analytics only.

### Package

- Beta package `0.2.1-beta` (zip + SHA-256 + `version.json` under `dist/` via `bin/package-release.php`).

### Added

- **Forum likes & moderation UI** (phpBB-style parity step):
  - Thumbs-up for registered users on forum posts; one like per user per post
  - Edit and delete own posts when forum ACL grants `edit_own` / `delete_own`
  - Moderators/admins: edit any post, soft-delete, lock/unlock topics
  - Per-post `like_count` and author post/like counters in the topic view
  - Usermeta stats: `forum_posts`, `forum_likes_given`, `forum_likes_received` (kept in sync on post insert/unapprove/approve/delete and like/unlike)
  - Migration **11**: `forum_post_likes` table + `forum_posts.like_count`
  - Classes: `AP_Forum_Like`, `AP_Forum_Stats`; front handlers on `AP_Forum_Front`
  - Default **Agora** theme topic toolbar (like / edit / delete / lock)
- **Theme Options Settings API** (WordPress-compatible theme_mods):
  - Themes register sections/fields on Appearance → Theme Options via `ap_theme_options_register`
  - `theme_mods_{stylesheet}` storage; `ap_get_theme_mod` / `ap_set_theme_mod` / `ap_remove_theme_mod`
  - Classic WP compatibility shims: `get_theme_mod`, `set_theme_mod`, `remove_theme_mod`

### Changed

- Product README and developer docs updated for schema v11, forum likes/moderation, and Theme Options API.

### Fixed

- Forum user post-count hooks re-register correctly after `ap_reset_hooks()` (tests and re-bootstrap).

## [0.2.0-beta] - 2026-08-05

First public beta. Schema `AP_DB_VERSION` **10**. Privacy posture unchanged: no telemetry by default; optional local analytics only.

### Package

- Beta package `0.2.0-beta` (zip + SHA-256 + `version.json` under `dist/` via `bin/package-release.php`).

### Added

- **Local site analytics** (admin-only, privacy-respecting):
  - Opt-in collection (`analytics_enabled`, default **off**); retention days (`analytics_retention_days`, default **90**)
  - Server-side pageview recorder on public GET/HEAD — no front-end JS, no third-party scripts or endpoints
  - Skips ap-admin, feeds, REST, sitemaps, obvious bots, logged-in `manage_options` users, and HTTP `DNT: 1`
  - Migration **10**: `analytics_hits` + `analytics_daily` tables (`ap_` prefix)
  - Daily cron prune of rows older than the retention window
  - ACP **Tools → Analytics** (`manage_options`): pageviews today / 7d / 30d, top paths, top referrers, daily table; enable + retention settings; empty states when disabled or no data
  - Data stays in the site database only — not Hall of Fame registration and not version-check traffic
- **ap-cli `post`**: list / get / create / update posts and pages from the shell using local `--file` paths only (no remote URLs).
- Default **Agora** theme header: guests see **Log in** (and **Register** when public registration is enabled).

### Changed

- Visual **WYSIWYG editor** for posts, pages, comments, and forums (formatted preview while editing; HTML on save). Legacy Markdown/BBCode still converts on display.
- Concise public docs: this changelog and the README install guide.
- Core one-click updater no longer rewrites the `install/` directory or `ap-config-sample.php` on disk (fresh-install assets only).
- Product README and developer docs refreshed for the current MVP surface (CLI content, updater safety, Agora theme auth/forms, live-site install).

### Fixed

- Published posts showed raw markup (`**bold**`, `[b]…[/b]`) instead of formatted HTML.
- Default theme primary/footer menus could render twice when no custom menu was assigned.
- Default **Agora** theme wraps extremely long unbroken strings (e.g. Monero donation addresses) so they no longer stretch the page.
- Comment and forum text fields (including the visual editor) use scheme-matched dark field backgrounds and contrasting text in dark color schemes instead of light browser defaults.

### Added (MVP surface)

**Forums**

- ACP **visibility & permissions** per forum by user level (Guest → Registered → Moderator → Administrator): presets (Public, Members only, Read only, Moderators only, Administrators only) plus a full custom matrix. Forum-only — blog posts/pages still use publish status.

**Appearance & editor**

- **Additional CSS** on Appearance → Theme Options (`custom_css` option) so site owners can add rules without editing theme files (printed on `ap_head`)
- **Visual | Text** mode switcher on the classic editor (raw HTML source for embeds, long addresses, fine-grained markup)
- Media: scale/crop on attachment details (GD), intermediate sizes on upload, **max display width** for content images (Settings → Media)

**Install & ops**

- Web installer (`/install/`), CLI install (`php install/cli.php`), Docker Compose
- Config sample (`ap-config-sample.php`), table prefix `ap_`, multi-driver DB (MySQL/MariaDB, SQLite, PostgreSQL)
- Release packaging (`bin/package-release.php` → zip + SHA-256 + `version.json`)
- One-click core update + version check (no site identity sent)
- `ap-cli` for options, plugins, themes, users, posts/pages, DB migrate, cache, cron, health
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
