# Changelog

Notable changes to AgoraPress. Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versioning: [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Core version: `AP_VERSION` in `ap-includes/version.php` (currently **0.3.4-beta**).

## [Unreleased]

## [0.3.4-beta] - 2026-08-14

Installer contrast, plugin zip install, Hall of Fame handshake, unified Agora desktop width, plus Site Icon. No schema change (`AP_DB_VERSION` **12**); privacy posture unchanged (no telemetry by default).

### Package

- Beta package `0.3.4-beta` (zip + SHA-256 + `version.json` under `dist/` via `bin/package-release.php`).

### Added

- **Plugin zip upload:** Plugins screen installs a classic plugin from a `.zip` (`AP_Plugin_Installer`) — single-file or `folder/plugin.php`, optional overwrite, delete when inactive
- **Hall of Fame handshake:** Join writes a short-lived proof file; the project API fetches it to confirm domain control before listing the site
- **Site Icon (favicon pack):** Settings → General → Site Icon — upload, Media Library pick, preview, remove (`manage_options` + nonce); option `site_icon` = attachment ID (`0` = none)
- **Derivatives on set/change:** PNG sizes **32 / 180 / 192 / 512** plus multi-size `.ico` when possible (GD or Imagick); cleanup of previous pack on replace/remove; meta under `_ap_attachment_metadata` key `site_icon` (not intermediate `sizes`)
- **Front-end head tags:** `AP_Media::printSiteIconTags()` on `ap_head` when `site_icon` &gt; 0 (`rel="icon"` + `apple-touch-icon`); filter `ap_site_icon_meta_tags`; no synthetic root `favicon.ico` link when unset (manual web-root `favicon.ico` remains a passive browser fallback)
- **Developer docs:** `docs/site-icon.md` (admin flow, sizes, head output, hooks)

### Changed

- **Agora desktop width:** blog, pages, and forums share one shell (`--ap-max` 68rem) so the header/main/footer no longer jump between views

### Fixed

- **Installer dark mode:** form fields, buttons, and notices stay readable when the browser is in dark mode (no white text on a white field)

### Tests

- Option save/preserve/remove on General settings; site-icon generation/cleanup helpers; head tag output when set vs unset; rewrite leaves static root `favicon.ico` alone
- Plugin zip installer (folder + single-file, overwrite, path traversal); installer dark-mode CSS; Hall of Fame handshake client; Agora unified shell width

## [0.3.3-beta] - 2026-08-10

Comment display and ownership: NBSP rendering, edit/delete own comments by role. No schema change (`AP_DB_VERSION` **12**); privacy posture unchanged (no telemetry by default).

### Package

- Beta package `0.3.3-beta` (zip + SHA-256 + `version.json` under `dist/` via `bin/package-release.php`).

### Fixed

- **Literal `&nbsp;` in comments:** visual editor often inserts non-breaking spaces; display escaped them to `&amp;nbsp;`. `AP_Content_Format::normalizeNbsp()` converts entities and Unicode NBSP to ordinary spaces on format and on comment insert/update.

### Added

- **Comment ownership caps:** `delete_own_comments` (Subscriber+), `edit_own_comments` (Author+); meta caps map by ownership; moderators keep full access via `moderate_comments`; `ensureDefaults()` merges caps on upgrade
- **Front-end edit/delete** on comments the viewer can manage (Agora / ZeroShits); **Admin** comment editor (`ap-admin/comment.php`)

### Tests

- NBSP normalization; role matrix for delete-own / edit-own / admin edit-all

## [0.3.2-beta] - 2026-08-10

Blog comment posting fix for logged-in users. No schema change (`AP_DB_VERSION` **12**); privacy posture unchanged (no telemetry by default).

### Package

- Beta package `0.3.2-beta` (zip + SHA-256 + `version.json` under `dist/` via `bin/package-release.php`).

### Fixed

- **Logged-in blog comments never saved:** handler called non-existent `AP_User::get()`; now uses `AP_User::getById()` / `ap_get_user_by('id', …)`. Guests were unaffected.
- POST exceptions redirect with `comment_error=server`; success distinguishes approved vs pending (`comment_ok=1` / `pending`); `comment_moderation` honored for logged-in users.

### Tests

- Logged-in form post regression; guest pending signal; `comment_moderation` holds inserts unless status forced.

## [0.3.1-beta] - 2026-08-09

Plugin admin pages in the Control Panel (registry + allowlisted router). No schema change (`AP_DB_VERSION` **12**); privacy posture unchanged (no telemetry by default).

### Package

- Beta package `0.3.1-beta` (zip + SHA-256 + `version.json` under `dist/` via `bin/package-release.php`).

### Added

- **Plugin admin pages (ACP registry + router):** `ap_register_admin_page()` / `AP_Admin_Menu` allowlist; router `admin.php?page={id}` (capability gate, no arbitrary path includes); sidebar merge; Plugins **Settings** action; WP shims (`add_options_page`, etc.); sample `ap-content/plugins/logos/`; docs in `docs/plugins.md`

### Changed

- Registered pages merge alongside hardcoded ACP entries; core menu items and module filters unchanged.

## [0.3.0-beta] - 2026-08-07

Schema `AP_DB_VERSION` **12** (topic type enum). Privacy posture unchanged: no telemetry by default; board stats are local DB aggregates only.

### Package

- Beta package `0.3.0-beta` (zip + SHA-256 + `version.json` under `dist/` via `bin/package-release.php`).

### Added

- **Forum UI phpBB-parity** (board index + topic view): category columns, read/unread row classes, topic types (`standard` / `sticky` / `announcement` / `rules`), two-pane posts with author pane, first-unread jump, board footer totals, migration **12** type normalize/backfill, Agora theme markup/CSS, helpers/tests for stats and read markers

### Changed

- Forum read-tracking and stats helpers for board index aggregates; profile location/signature fields for author pane.

## [0.2.1-beta] - 2026-08-06

Schema `AP_DB_VERSION` **11**. Privacy posture unchanged: no telemetry by default; optional local analytics only.

### Package

- Beta package `0.2.1-beta` (zip + SHA-256 + `version.json` under `dist/` via `bin/package-release.php`).

### Added

- **Forum likes & moderation UI:** thumbs-up; edit/delete own when ACL allows; mod soft-delete and lock; `like_count` + usermeta counters; migration **11** (`forum_post_likes`); `AP_Forum_Like` / `AP_Forum_Stats`
- **Theme Options Settings API:** `ap_theme_options_register`, `theme_mods_{stylesheet}`, `ap_get_theme_mod` / `ap_set_theme_mod` + WP shims

### Changed

- README and developer docs for schema v11, forum likes/moderation, Theme Options API.

### Fixed

- Forum post-count hooks re-register correctly after `ap_reset_hooks()`.

## [0.2.0-beta] - 2026-08-05

First public beta. Schema `AP_DB_VERSION` **10**. Privacy posture unchanged: no telemetry by default; optional local analytics only.

### Package

- Beta package `0.2.0-beta` (zip + SHA-256 + `version.json` under `dist/` via `bin/package-release.php`).

### Added

- **Local site analytics** (admin-only, privacy-respecting): opt-in (`analytics_enabled`, default **off**); retention (`analytics_retention_days`, default **90**); server-side pageview recorder on public GET/HEAD (no front-end JS/third-party); skips ap-admin, feeds, REST, bots, `manage_options` users, `DNT: 1`; migration **10**: `analytics_hits` + `analytics_daily`; cron prune; ACP **Tools → Analytics**
- **ap-cli `post`**: list / get / create / update from local `--file` paths only
- Default **Agora** theme header: guests see **Log in** (and **Register** when enabled)

### Changed

- Visual **WYSIWYG editor** for posts, pages, comments, forums; legacy Markdown/BBCode still converts on display
- Concise public docs; core updater no longer rewrites `install/` or `ap-config-sample.php` on disk
- Product README and developer docs refreshed for MVP surface

### Fixed

- Published posts showed raw markup instead of formatted HTML
- Default theme menus could render twice; long unbroken strings wrap; dark color schemes use scheme-matched editor fields

### Added (MVP surface)

**Forums**

- ACP **visibility & permissions** per forum by user level with presets + custom matrix (forum-only)

**Appearance & editor**

- **Additional CSS** on Appearance → Theme Options (`custom_css` on `ap_head`)
- **Visual | Text** mode switcher; media scale/crop (GD); max display width (Settings → Media)

**Install & ops**

- Web installer (`/install/`), CLI install (`php install/cli.php`), Docker Compose
- Config sample (`ap-config-sample.php`), table prefix `ap_`, multi-driver DB
- Release packaging (`bin/package-release.php`); one-click core update + version check (no site identity)
- `ap-cli` for options, plugins, themes, users, posts/pages, migrate, cache, cron, health
- Live-site install readiness: production hardening denies secrets, SQLite/DB downloads, and direct `ap-includes/` access

**CMS**

- Static Pages, Blog, Forum as independent modules
- Posts/pages, revisions, media, comments, taxonomies; shortcodes; content format
- Menus, widgets, feeds, sitemaps, Open Graph
- Default **Agora** theme (six schemes) + Classic WordPress Theme Compatibility Layer
- Hooks, plugins, Settings API, Options API (`AP_Options`), query (`AP_Query`)

**Forums**

- Hierarchy, topics/replies, attachments, groups & permissions
- Moderation (`AP_Forum_Moderation`), search, flood/spam guards, PMs, online/unread

**Admin & security**

- Responsive admin, roles/caps, users, Site Health
- Nonces, rate limits, privacy / personal data export & erase (`AP_Privacy`), i18n/RTL
- Object cache API; cron; transients; WXR + phpBB importers; REST (`/ap-json/`)
- Performance and accessibility basics (autoload priming, landmarks, contrast)
- GPLv2-or-later; no telemetry by default

**Tooling**

- PHPUnit (`phpunit.xml.dist`), PHPCS, PHPStan, pytest smokes under `tests/`
- Layout: `ap-admin/`, `ap-includes/`, `ap-content/`, `install/`, `docker-compose.yml`
