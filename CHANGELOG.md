# Changelog

Notable changes to AgoraPress. Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versioning: [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Core version: `AP_VERSION` in `ap-includes/version.php` (currently **0.3.10-beta**).

Older releases (**0.3.8-beta** through **0.2.0-beta**) are in [CHANGELOG-archive.md](CHANGELOG-archive.md).

## [Unreleased]

### Added

### Changed

## [0.3.10-beta] - 2026-09-14

Spoilers, ACP author picker, topic email notify, `ap_comments_template()`. Schema `AP_DB_VERSION` **13**; no telemetry by default.

### Package

- Beta package `0.3.10-beta` (zip + SHA-256 + `version.json` under `dist/` via `bin/package-release.php`).

### Added

- `[spoiler]` / `[spoiler=Label]` / `[spoiler title="Label"]` → `<details class="ap-spoiler">`; default label **Spoiler**; toolbar on post, page, comment, forum
- ACP **Author** `<select>` on Add New and Edit for posts and pages (`edit_others_posts` / `edit_others_pages`)
- Topic email notify: site option, user option, and per-topic Subscribe (all default **off**); `{prefix}topic_subscriptions`; `AP_Cron` worker; own rate bucket (`forum_notify_max_per_minute` default 4), not `rate_limit_mail`
- `ap_comments_template()` — theme `comments.php` or core fallback; no auto-inject

### Changed

- `AP_DB_VERSION` **13**. `topic_track` / `forum_track` stay unread tracking
- Insert and update persist `post_author` when the actor may set it
- Agora `single.php` calls `ap_comments_template()` (exactly one form)

### Fixed

- Closed spoiler body is unreadable; excerpts, feeds, Open Graph, search snippets, and forum last-post blurbs omit inner text
- Crafted author POST cannot assign an id the actor may not set
- Failed topic-notify send does not claim success and does not drop the subscription

## [0.3.9-beta] - 2026-09-13

Default category, Agora scheme preview, editor contrast. Schema stays `AP_DB_VERSION` **12**; no telemetry by default.

### Package

- Beta package `0.3.9-beta` (zip + SHA-256 + `version.json` under `dist/` via `bin/package-release.php`).

### Added

- **Set as default** on Posts → Categories (`manage_categories` + nonce)
- Agora Theme Option `agora_visitor_color_preview` (default off): six-swatch header; pick is `?agora_scheme=` / cookie only, never writes `agora_color_scheme`
- Editor contrast: `.ap-editor` inherits `color-scheme`; `--ap-editor-*` with `Canvas`/`Field` fallbacks

### Changed

- `ensureDefaultCategory()` seeds Uncategorized only when no living default exists; it does not clobber a valid `default_category`
- Writing Default Post Category lists living ids only (no magic `0`). Uncategorized is deletable once it is not the default
- Public “Posted in” lists skip empty-name terms and omit the line when none remain

### Fixed

- Categories screen no longer re-locks Uncategorized as the default
- Failed delete of the current default (or last remaining category) uses an honest message
- Empty-name terms no longer print “Posted in , ,”
- Editor toolbar readable on `color-scheme: dark` pages without Agora CSS
