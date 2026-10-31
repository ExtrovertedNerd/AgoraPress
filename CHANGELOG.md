# Changelog

Notable changes to AgoraPress. Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versioning: [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Core version: `AP_VERSION` in `ap-includes/version.php` (currently **0.3.13-beta**).

Older releases (**0.3.8-beta** through **0.2.0-beta**) are in [CHANGELOG-archive.md](CHANGELOG-archive.md).

## [Unreleased]

### Fixed

- Root `.htaccess` no longer uses an expression container or response-header directives. Those directives make some `AllowOverride` setups return HTTP 500 for every URL. Uploaded SVG `Content-Security-Policy` and `nosniff` live in `ap-content/uploads/.htaccess` inside `<IfModule mod_headers.c>` and `<FilesMatch "\.svg$">` (best effort). The same policy is in the SVG `location` of `docker/nginx.conf.example` (AP-033).
- One-click update does not replace an existing root `.htaccess` or an existing `ap-content/uploads/.htaccess`. A differing package copy is written to `.htaccess.ap-new` in that directory and a notice is logged. After files and migrations, a short loopback GET of the home URL and `/ap-json/` runs. HTTP 5xx restores the pre-update files and `.htaccess`, reports `rolled_back`, and leaves maintenance off. If the loopback cannot be reached, the update stands and a warning is recorded (AP-033).

### Added

### Changed

## [0.3.13-beta] - 2026-10-31

Plugin zip install and upgrade (`AP_Plugin_Installer`). Own-account password and email changes require the current password. An administrator changing another user's password or email is recorded in `{prefix}activity_log`. Schema `AP_DB_VERSION` **14**; no telemetry by default.

### Package

- Beta package `0.3.13-beta` (zip + SHA-256 + `version.json` under `dist/` via `bin/package-release.php`).

### Added

- `AP_Plugin_Installer::validate()` / `install()` / `restorePrevious()` / `latestBackup()` / `listInstalled()`, and `ap_validate_plugin_zip()` / `ap_install_plugin()` / `ap_restore_previous_plugin()` / `ap_list_installed_plugins()`
- `AP_Plugin_Install_Result` (`slug`, `name`, `version`, `previous_version`, `action` `installed`|`upgraded`|`restored`, `backup_path`, `activated`) or `AP_Plugin_Install_Error` (`code`, `message`)
- Options: `overwrite` and `activate` apply only as boolean `true`; optional `expected_sha256` is checked before anything is extracted
- Cap `install_plugins` (administrators only). Plugins → **Upload plugin (.zip)**: POST, multipart, nonce `plugin-upload`, file field `pluginzip`, checkbox **Activate after install**
- An existing slug is not replaced on the first POST. The screen shows the installed version and the new version; **Replace plugin** is a second POST (nonce `plugin-overwrite`)
- **Restore previous version** on a row when a backup exists (POST, nonce `restore-plugin_{slug}`). The current copy is backed up first
- Staging under `{content}/.ap-plugin-staging/`; backups under `{content}/.ap-plugin-backups/` (keep last **5**, filter `ap_plugin_zip_max_backups`). Nothing is extracted straight into the plugins directory
- Zip check is magic bytes, not the extension. Reject absolute paths, `..`, backslashes, drive letters, NUL bytes, symlinks, and special files. Entry-count and uncompressed-size caps reject bombs
- One top-level slug folder (main file `{slug}/{slug}.php`, or the only header PHP directly in that folder) or one flat `{slug}.php`, with **Plugin Name** and **Version**. A theme (`Theme Name` in `style.css` at the archive root or one directory down) or a core package is rejected
- Size cap default **20 MiB** (`ap_plugin_zip_max_bytes`); **5000** entries (`ap_plugin_zip_max_entries`); **128 MiB** uncompressed (`ap_plugin_zip_max_uncompressed_bytes`)
- Actions `ap_plugin_before_install` / `ap_plugin_after_install`, `ap_plugin_before_upgrade` / `ap_plugin_after_upgrade`, `ap_plugin_before_restore` / `ap_plugin_after_restore`, `ap_plugin_before_activate` / `ap_plugin_after_activate`, `ap_plugin_before_deactivate` / `ap_plugin_after_deactivate`
- Operator how-to: [docs/admin.md](docs/admin.md#plugin-zip-installer). Developer API: [docs/plugins.md](docs/plugins.md#plugin-installer)
- `{prefix}activity_log`: an administrator who changes another user's password or email on user-edit is recorded with actor id, target id, field name (`password` or `user_email`), UTC time, and IP. The password is not stored

### Changed

- Schema `AP_DB_VERSION` **14** (`{prefix}activity_log` for admin password and email changes on another user; the password is not stored)
- A plugin that was active stays active after upgrade or restore. If `activate` fails, the new files stay and the plugin is inactive; that failure does not restore the previous version
- A failed swap puts the timestamped backup back. There is no `php ap-cli plugin install`
- The Plugins screen calls `install()` and does not fire legacy `ap_plugin_installed`. `installFromZip()` / `handleUpload()` remain on the **40 MiB** `DEFAULT_MAX_BYTES` path

### Security

- Profile (`profile.php`) and Users → Edit of your own account require **Current password** (`current_password`, `autocomplete="current-password"`) to change the password or the email. Display name and the other profile fields save without it. The check is `AP_User::checkPassword()` (`password_verify`). The submitted value is not logged, echoed, or stored
- Missing current password: `Enter your current password to change your password or email.` Wrong current password: `Your current password is incorrect.` The rejected password and email stay as stored; other submitted fields still save. Nonce and capability checks are unchanged
- Failed checks use `AP_Rate_Limit` action `current_password`, per user id and per IP (default 5 failures / 15 minutes). Options `rate_limit_current_password_max`, `rate_limit_current_password_window`, and `rate_limit_current_password_lockout` (no ACP screen). Filter `ap_current_password_rate_limits`. Over the limit: `Too many attempts, try again in N minutes.` A successful check clears both counters
- An administrator with `edit_users` may set another user's password or email without that user's current password. Each such change is appended to `{prefix}activity_log` (actor id, target id, field `password` or `user_email`, UTC time, IP). The password is not stored. Editing your own row is not exempt and is not written to the log
- A successful own password change destroys that user's other sessions and remember-me tokens, rotates this session id, and keeps this browser signed in. An administrator reset of another user's password destroys all of that user's sessions. An email change does not, by itself, destroy sessions
- `AP_Account_Notice` emails the account on a password change. An email change notifies the old address and confirms the new one (`text/plain`; what changed, UTC time, and IP; no password and no reset key). Outbound mail that is disabled or not configured is skipped; the reason is logged and the account change stands
- Actions `ap_password_changed` and `ap_email_changed` (account user id, actor user id, context `self` or `admin`). Depth: [docs/security.md](docs/security.md#profile-re-authentication)
- REST HTTP Basic authentication uses the same login lockout as the sign-in form (`AP_Rate_Limit` action `login`, per IP and per identity). While the lockout holds, the password is not checked and the response is **429** (AP-001)
- SVG uploads are parsed with DOM. The element and attribute allowlist is applied to entity-decoded values. `javascript:`, `data:`, and `vbscript:` URLs are rejected, and `animate` / `set` that set `href` are rejected. Uploaded SVG is served with `Content-Security-Policy: script-src 'none'; sandbox` and `X-Content-Type-Options: nosniff` (AP-002)
- Password-protected posts do not put the body in feeds, Open Graph, Twitter, or the meta description, JSON-LD, search, or the sitemap (AP-003)

### Fixed

- REST HTTP Basic authentication now uses the login lockout. A locked client gets **429** and the password is not checked (AP-001)
- SVG uploads are parsed with DOM against an entity-decoded allowlist. `javascript:`, `data:`, and `vbscript:` URLs are blocked, as are `animate` / `set` `href` values. Uploads are served with `Content-Security-Policy: script-src 'none'; sandbox` and `X-Content-Type-Options: nosniff` (AP-002)
- Password-protected posts no longer leak the body in feeds, Open Graph, Twitter, or the meta description, JSON-LD, search, or the sitemap. Public text is `This post is password protected.` (AP-003)

## [0.3.11-beta] - 2026-09-15

Forum Move / Merge / Split UI, front Report, last-post recount. Schema `AP_DB_VERSION` **13**; no telemetry by default.

### Package

- Beta package `0.3.11-beta` (zip + SHA-256 + `version.json` under `dist/` via `bin/package-release.php`).

### Added

- Topic toolbar **Move** (`ap_forum_move_topic`) when `move_topics` or `moderate_forum`; destination forums the actor can also moderate (not categories, not current)
- ACP Topics row **Move** + bulk **Move to…** (same destination rule)
- Topic toolbar **Merge** (`ap_forum_merge_topic`) into a topic the actor can moderate; ACP Topics bulk **Merge into…**
- Topic toolbar **Split** (`ap_forum_split_topic`): checkbox per post, new title, optional dest forum; original keeps ≥1 post
- **Report** on each post (`ap_forum_report_post`) when logged-in + `view_forum`; inserts `{prefix}reports` type `post`, status `open`

### Changed

- Schema stays `AP_DB_VERSION` **13** (no bump). No shadow / “moved from” stub topics
- `mergeTopics` retargets `{prefix}topic_subscriptions` source → target; duplicate `(user_id, target_id)` pairs are dropped
- One `AP_Forum::refreshForumLastPost($forumId)` on every delete / restore / move / merge / split / post-unapprove path
- Move keeps the same `topic_id` and slug; `{prefix}topic_subscriptions` stay on that id

### Fixed

- Board-index Last Post never prints a deleted topic: stale / missing / unapproved pointer recounts, then renders or shows empty
- Empty board (or only deleted / unapproved content) clears `last_post_id` / `last_topic_id` / `last_poster_id` / `last_post_time`
- Deleting a topic that is not last leaves the real last post in place
- Failed report insert does not claim success; guests cannot report; one open report per user per post

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
