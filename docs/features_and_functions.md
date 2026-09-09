# Features and functions

| | |
|---|---|
| Core | `AP_VERSION` **0.3.6-beta** |
| Schema | `AP_DB_VERSION` **12** |
| Table prefix | default `ap_` (`$table_prefix` in `ap-config.php`) |
| Control Panel | `/ap-admin/` |
| REST prefix | `/ap-json/` · namespace `ap/v1` |
| Telemetry | **none** — no `AP_TELEMETRY` constant, flag, or option; version check sends **no site identity** |
| Rule | If a row is not in this catalog and not in the linked guide, it is **not in core** |
| Depth | Topic guides, not this file |

## Modules

| Module | Option | Default | Screen | Guide |
|--------|--------|---------|--------|-------|
| Static Pages | `ap_module_static_pages` | on (`1`) | Settings → Modules (`options-modules.php`) | [admin.md](admin.md#settings) · [install.md](install.md) |
| Blog | `ap_module_blog` | on (`1`) | Settings → Modules (`options-modules.php`) | [admin.md](admin.md#settings) |
| Forum | `ap_module_forum` | on (`1`) | Settings → Modules (`options-modules.php`) | [forums.md](forums.md) · [admin.md](admin.md#forums) |

| Toggle how | Not this |
|------------|----------|
| Settings → Modules, or `php ap-cli option set ap_module_{static_pages\|blog\|forum} 0\|1` | **No** `php ap-cli module` / `php ap-cli forum` |
| Constraint | At least one of the three modules must stay on |

## Operator-facing options

| Option | Default | Surface | Guide |
|--------|---------|---------|-------|
| `ap_module_static_pages` | `1` | Settings → Modules | [admin.md](admin.md#settings) |
| `ap_module_blog` | `1` | Settings → Modules | [admin.md](admin.md#settings) |
| `ap_module_forum` | `1` | Settings → Modules | [forums.md](forums.md) |
| `blogname` | (installer `--site-title`) | Settings → General | [admin.md](admin.md#settings) |
| `blogdescription` | `''` | Settings → General | [admin.md](admin.md#settings) |
| `siteurl` | (installer `--site-url`) | Settings → General | [admin.md](admin.md#settings) |
| `home` | (installer `--site-url`) | Settings → General | [admin.md](admin.md#settings) |
| `admin_email` | (installer) | Settings → General | [admin.md](admin.md#settings) |
| `mail_from_name` | `''` (falls back to `blogname`) | Settings → Mail | [admin.md](admin.md#mail) |
| `mail_from_email` | `''` (falls back to `admin_email`; not the same option) | Settings → Mail | [admin.md](admin.md#mail) |
| `mail_reply_to` | `''` (falls back to `admin_email`; **no** `ap-config.php` constant) | Settings → Mail | [admin.md](admin.md#mail) |
| `mail_transport` | `php` (`php` \| `smtp`) | Settings → Mail | [admin.md](admin.md#mail) |
| `smtp_host` | `''` | Settings → Mail | [admin.md](admin.md#mail) |
| `smtp_port` | `587` | Settings → Mail | [admin.md](admin.md#mail) |
| `smtp_encryption` | `tls` (`none` \| `tls` \| `ssl`) | Settings → Mail | [admin.md](admin.md#mail) |
| `smtp_user` | `''` | Settings → Mail | [admin.md](admin.md#mail) |
| `smtp_pass` | `''` (write-only in ACP) | Settings → Mail | [admin.md](admin.md#mail) |
| `mail_last_error` | `''` (autoload `no`; last send failure) | Settings → Mail and Tools → Site Health | [admin.md](admin.md#mail) · [troubleshooting.md](troubleshooting.md#mail-not-arriving) |
| `users_can_register` | `0` | Settings → General | [admin.md](admin.md#sign-in) · [admin.md](admin.md#public-registration) |
| `require_email_verification` | `1` | Settings → General | [admin.md](admin.md#settings) · [admin.md](admin.md#public-registration) |
| `registration_captcha` | `off` (`off` \| `math` \| `guard`) | Settings → General | [admin.md](admin.md#public-registration) · [security.md](security.md#public-registration-gate) |
| `reserved_usernames` | `''` (one extra login per line; filter `ap_reserved_usernames`) | Settings → General | [admin.md](admin.md#public-registration) · [hooks.md](hooks.md#users--registration) |
| `default_role` | `subscriber` | Settings → General | [roles.md](roles.md) |
| `timezone_string` | `UTC` | Settings → General | [admin.md](admin.md#settings) |
| `WPLANG` | `''` | Settings → General | [admin.md](admin.md#settings) |
| `date_format` | `Y-m-d` | Settings → General | [admin.md](admin.md#settings) |
| `time_format` | `H:i` | Settings → General | [admin.md](admin.md#settings) |
| `start_of_week` | `1` | Settings → General | [admin.md](admin.md#settings) |
| `site_icon` | `0` (none) | Settings → General | [site-icon.md](site-icon.md) |
| `default_category` | `0` (seeded) | Settings → Writing | [admin.md](admin.md#settings) |
| `use_smilies` | `1` | Settings → Writing | [admin.md](admin.md#settings) |
| `default_comment_status` | `open` | Settings → Writing | [admin.md](admin.md#settings) |
| `show_on_front` | `posts` | Settings → Reading | [admin.md](admin.md#settings) |
| `page_on_front` | `0` | Settings → Reading | [admin.md](admin.md#settings) |
| `page_for_posts` | `0` | Settings → Reading | [admin.md](admin.md#settings) |
| `posts_per_page` | `10` | Settings → Reading | [admin.md](admin.md#settings) |
| `posts_per_rss` | `10` | Settings → Reading | [admin.md](admin.md#settings) |
| `rss_use_excerpt` | `0` | Settings → Reading | [admin.md](admin.md#settings) |
| `show_avatars` | `1` | Settings → Discussion | [admin.md](admin.md#settings) |
| `avatar_default` | `mystery` | Settings → Discussion | [admin.md](admin.md#settings) |
| `avatar_rating` | `g` | Settings → Discussion | [admin.md](admin.md#settings) |
| `max_image_display_width` | `1200` | Settings → Media | [admin.md](admin.md#settings) |
| `uploads_use_yearmonth_folders` | `1` | Settings → Media | [admin.md](admin.md#settings) |
| `permalink_structure` | `''` = Plain `?p=` / `?page_id=` | Settings → Permalinks | [rewrites.md](rewrites.md) |
| `category_base` | `''` | Settings → Permalinks | [rewrites.md](rewrites.md) |
| `tag_base` | `''` | Settings → Permalinks | [rewrites.md](rewrites.md) |
| `wp_page_for_privacy_policy` | (sample may set) | Settings → Privacy | [security.md](security.md) |
| `agora_color_scheme` | `marble` | Appearance → Theme Options | [themes.md](themes.md#default-theme-agora) |
| `custom_css` | `''` | Appearance → Theme Options | [themes.md](themes.md) |
| `stylesheet` | `agora` | Appearance → Themes | [themes.md](themes.md) |
| `template` | `agora` | Appearance → Themes | [themes.md](themes.md) |
| `analytics_enabled` | `0` | Tools → Analytics **and** CLI | [admin.md](admin.md#tools) |
| `analytics_retention_days` | `90` | Tools → Analytics | [admin.md](admin.md#tools) |
| `rest_api_enabled` | `1` | **CLI only** (`php ap-cli option`) — no ACP screen | [rest.md](rest.md) |
| `version_check_enabled` | `1` | **CLI only** — no ACP screen; check sends **no site identity** | [updates.md](updates.md) |
| `blog_public` | `1` | **CLI only** — no ACP field | [cli.md](cli.md) |
| `sitemap_enabled` | `1` | **CLI only** — no ACP field | [cli.md](cli.md) |
| `open_graph_enabled` | `1` | **CLI only** — no ACP field | [cli.md](cli.md) |
| `forum_attachment_max_per_post` | `5` | **CLI only** — not on Settings → Forums | [forums.md](forums.md) |
| `forum_attachment_user_quota` | `10485760` | **CLI only** — not on Settings → Forums | [forums.md](forums.md) |
| `forum_online_window` | `900` | **CLI only** — not on Settings → Forums | [forums.md](forums.md) |

| `ap-config.php` mail constant | Overrides option | Guide |
|-------------------------------|-------------------|-------|
| `AP_MAIL_FROM_NAME` | `mail_from_name` | [admin.md](admin.md#mail) · [install.md](install.md#manual-config) |
| `AP_MAIL_FROM_EMAIL` | `mail_from_email` | [admin.md](admin.md#mail) · [install.md](install.md#manual-config) |
| `AP_MAIL_TRANSPORT` | `mail_transport` | [admin.md](admin.md#mail) · [install.md](install.md#manual-config) |
| `AP_SMTP_HOST` | `smtp_host` | [admin.md](admin.md#mail) · [install.md](install.md#manual-config) |
| `AP_SMTP_PORT` | `smtp_port` | [admin.md](admin.md#mail) · [install.md](install.md#manual-config) |
| `AP_SMTP_ENCRYPTION` | `smtp_encryption` | [admin.md](admin.md#mail) · [install.md](install.md#manual-config) |
| `AP_SMTP_USER` | `smtp_user` | [admin.md](admin.md#mail) · [install.md](install.md#manual-config) |
| `AP_SMTP_PASS` | `smtp_pass` | [admin.md](admin.md#mail) · [install.md](install.md#manual-config) |
| (none this pass) | `mail_reply_to` has **no** constant | [admin.md](admin.md#mail) |

| Public register gate (when `users_can_register` is on) | As built | Guide |
|--------------------------------------------------------|----------|-------|
| Honeypot `ap_hp` | Always-on, even if visible captcha is `off`; must stay empty | [admin.md](admin.md#public-registration) · [security.md](security.md#public-registration-gate) |
| Min fill | ~3 seconds (`AP_Registration::MIN_FILL_SECONDS`) | [admin.md](admin.md#public-registration) · [security.md](security.md#public-registration-gate) |
| Form ticket `ap_form_ticket` | Issued on GET; 30-minute TTL; naked POST fails closed | [admin.md](admin.md#public-registration) · [security.md](security.md#public-registration-gate) |
| Visible `registration_captcha` | `off` \| `math` (Human check) \| `guard` (`ap_guard_ack` + signed token; JS PoW in `ap-admin/js/register-guard.js`) | [admin.md](admin.md#public-registration) · [security.md](security.md#public-registration-gate) |
| Public reserved logins | Locked list + `reserved_usernames` extras + filter `ap_reserved_usernames`; error “That username is not available.” | [admin.md](admin.md#public-registration) · [security.md](security.md#reserved-logins-anti-squat) |
| ACP / CLI create | Users → Add and `php ap-cli user create` **may** use reserved logins | [admin.md](admin.md#users) · [cli.md](cli.md#user) |
| `ap_user_created` | Fires from `AP_User::create()` after insert, including pending | [hooks.md](hooks.md#users--registration) |

| Settings → Forums option | Default | Guide |
|--------------------------|---------|-------|
| `forum_topics_per_page` | `20` | [forums.md](forums.md) |
| `forum_posts_per_page` | `15` | [forums.md](forums.md) |
| `forum_allow_guest_viewing` | `1` | [forums.md](forums.md) |
| `forum_allow_guest_posting` | `0` | [forums.md](forums.md) |
| `forum_private_messaging_enabled` | `1` | [forums.md](forums.md) |
| `forum_attachments_enabled` | `1` | [forums.md](forums.md) |
| `forum_attachment_max_size` | `2097152` | [forums.md](forums.md) |
| `forum_attachment_allowed_types` | `jpg,jpeg,png,gif,webp,pdf,txt,zip` | [forums.md](forums.md) |
| `forum_posts_require_approval` | `0` | [forums.md](forums.md) |
| `forum_search_enabled` | `1` | [forums.md](forums.md) |
| `forum_online_enabled` | `1` | [forums.md](forums.md) |
| `forum_unread_tracking_enabled` | `1` | [forums.md](forums.md) |
| `forum_signatures_enabled` | `1` | [forums.md](forums.md) |
| `forum_flood_interval` | `30` | [forums.md](forums.md) |
| `forum_spam_max_links` | `5` | [forums.md](forums.md) |
| `forum_spam_blacklist` | `''` | [forums.md](forums.md) |

| Forum Edit access (not Settings → Forums) | As built | Guide |
|-------------------------------------------|----------|-------|
| posted `forum_access_level` | `public` \| `members` \| `members_readonly` \| `moderators` \| `administrators` \| `group_only` \| `custom` | [forums.md](forums.md#access-level-presets-forums--edit) |
| **This group only** (`group_only`) | Named non-system groups + administrators; deny guests; do **not** stamp deny on virtual `registered` | [forums.md](forums.md#this-group-only-group_only) |
| picker `forum_access_groups[]` | One or more named (non-system) group ids | [forums.md](forums.md#this-group-only-group_only) |
| option `forum_group_only` | `forum_id` → named group ids; schema **12**; **no** Settings → Forums field | [forums.md](forums.md#this-group-only-group_only) |
| Listing hygiene | No `view_forum` → omitted from index, search, feeds, sitemap, REST (`rest_cannot_view`) | [forums.md](forums.md#listing-hygiene-view_forum) |

| Discussion extra (Settings → Discussion) | Default | Guide |
|------------------------------------------|---------|-------|
| `require_name_email` | `1` | [admin.md](admin.md#settings) |
| `comment_moderation` | `0` | [admin.md](admin.md#settings) |
| `comment_registration` | `0` | [admin.md](admin.md#settings) |
| `close_comments_for_old_posts` | `0` | [admin.md](admin.md#settings) |
| `close_comments_days_old` | `14` | [admin.md](admin.md#settings) |
| `thread_comments` | `1` | [admin.md](admin.md#settings) |
| `thread_comments_depth` | `5` | [admin.md](admin.md#settings) |

| Media sizes (Settings → Media) | Default | Guide |
|--------------------------------|---------|-------|
| `thumbnail_size_w` / `thumbnail_size_h` / `thumbnail_crop` | `150` / `150` / `1` | [admin.md](admin.md#settings) |
| `medium_size_w` / `medium_size_h` | `300` / `300` | [admin.md](admin.md#settings) |
| `large_size_w` / `large_size_h` | `1024` / `1024` | [admin.md](admin.md#settings) |

| Hall of Fame option | Default | Screen | Guide |
|---------------------|---------|--------|-------|
| `hall_of_fame_status` | `''` | Settings → Hall of Fame | [admin.md](admin.md#hall-of-fame-handshake) |
| `hall_of_fame_domain` | `''` | same | [admin.md](admin.md#hall-of-fame-handshake) |
| `hall_of_fame_token` | `''` | same | [admin.md](admin.md#hall-of-fame-handshake) |
| `hall_of_fame_joined_at` | `''` | same | [admin.md](admin.md#hall-of-fame-handshake) |
| `hall_of_fame_dismissed` | `0` | Dashboard dismiss | [admin.md](admin.md#hall-of-fame-handshake) |

| Rate-limit option | Default | Surface | Guide |
|-------------------|---------|---------|-------|
| `rate_limit_login_max` | `5` | **no ACP screen** | [security.md](security.md) |
| `rate_limit_login_window` | `900` | **no ACP screen** | [security.md](security.md) |
| `rate_limit_login_lockout` | `900` | **no ACP screen** | [security.md](security.md) |
| `rate_limit_register_max` | `5` | **no ACP screen** | [security.md](security.md) |
| `rate_limit_register_window` | `3600` | **no ACP screen** | [security.md](security.md) |
| `rate_limit_register_lockout` | `3600` | **no ACP screen** | [security.md](security.md) |
| `rate_limit_password_reset_max` | `5` | **no ACP screen** | [security.md](security.md) |
| `rate_limit_password_reset_window` | `3600` | **no ACP screen** | [security.md](security.md) |
| `rate_limit_password_reset_lockout` | `1800` | **no ACP screen** | [security.md](security.md) |
| `rate_limit_upload_max` | `40` | **no ACP screen** | [security.md](security.md) |
| `rate_limit_upload_window` | `600` | **no ACP screen** | [security.md](security.md) |
| `rate_limit_upload_lockout` | `300` | **no ACP screen** | [security.md](security.md) |
| `rate_limit_mail_max` | `20` | **no ACP screen** (outbound `mail` action) | [security.md](security.md#outbound-mail) |
| `rate_limit_mail_window` | `3600` | **no ACP screen** (outbound `mail` action) | [security.md](security.md#outbound-mail) |
| `rate_limit_mail_lockout` | `3600` | **no ACP screen** (outbound `mail` action) | [security.md](security.md#outbound-mail) |

| CLI-only options | Command | Guide |
|------------------|---------|-------|
| `rest_api_enabled`, `version_check_enabled`, `blog_public`, `sitemap_enabled`, `open_graph_enabled`, `forum_attachment_max_per_post`, `forum_attachment_user_quota`, `forum_online_window` | `php ap-cli option get\|set` | [cli.md](cli.md#option) |

## Schema

| | |
|---|---|
| Target | `AP_DB_VERSION` **12** (`ap-includes/version.php`) |
| Prefix | default `ap_` — physical names `{prefix}{base}` |
| Drivers | MySQL 8+ / MariaDB 10.6+ (production), SQLite (demos), PostgreSQL |
| Apply | installer, or `php ap-cli db migrate` |
| Registry | `{prefix}schema_migrations` |
| Depth | [schema.md](schema.md) — if a base name is not here and not in `ap_all_base_tables()`, it is **not in core** |

| Ver | File | Creates |
|-----|------|---------|
| 1 | `0001_core_options_users.php` | `options`, `users`, `usermeta` |
| 2 | `0002_core_posts_postmeta.php` | `posts`, `postmeta` |
| 3 | `0003_core_terms_taxonomies.php` | `terms`, `term_taxonomy`, `term_relationships` |
| 4 | `0004_core_comments_commentmeta.php` | `comments`, `commentmeta` |
| 5 | `0005_forum_tables.php` | `forums`, `topics`, `forum_posts`, `groups`, `group_members`, `messages`, `ranks`, `reports`, `online` |
| 6 | `0006_forum_attachments.php` | `forum_attachments` |
| 7 | `0007_forum_permissions.php` | `forum_permissions` |
| 8 | `0008_forum_moderation.php` | `warnings`, `bans` |
| 9 | `0009_forum_online_unread.php` | `topic_track`, `forum_track` |
| 10 | `0010_analytics_tables.php` | `analytics_hits`, `analytics_daily` |
| 11 | `0011_forum_likes_stats.php` | `forum_post_likes`; `forum_posts.like_count` |
| 12 | `0012_topic_type_enum.php` | **No new table.** Canonical `topics.topic_type` `standard` \| `sticky` \| `announcement` \| `rules` |

| Core base (`ap_core_base_tables()`) | Forum base (`ap_forum_base_tables()`) |
|-------------------------------------|---------------------------------------|
| `schema_migrations` | `forums` |
| `options` | `topics` |
| `users` | `forum_posts` |
| `usermeta` | `forum_attachments` |
| `posts` | `groups` |
| `postmeta` | `group_members` |
| `terms` | `forum_permissions` |
| `term_taxonomy` | `messages` |
| `term_relationships` | `ranks` |
| `comments` | `reports` |
| `commentmeta` | `warnings` |
| `analytics_hits` | `bans` |
| `analytics_daily` | `online` |
| | `topic_track` |
| | `forum_track` |
| | `forum_post_likes` |

## Roles and capabilities

| Role | Level | Caps beyond the previous | Guide |
|------|-------|--------------------------|-------|
| `subscriber` | 0 | `read`, `delete_own_comments` | [roles.md](roles.md#built-in-roles) |
| `contributor` | 1 | + `edit_posts`, `delete_posts` | [roles.md](roles.md#who-gets-which-caps) |
| `author` | 2 | + `publish_posts`, `edit_published_posts`, `delete_published_posts`, `upload_files`, `edit_own_comments` | [roles.md](roles.md#who-gets-which-caps) |
| `editor` | 7 | + others/private posts, full pages, `manage_categories`, `moderate_comments`, `moderate_forums` | [roles.md](roles.md#who-gets-which-caps) |
| `administrator` | 10 | every primitive (incl. `manage_forums`, `view_site_health`, privacy tools) | [roles.md](roles.md#primitive-capabilities) |

| Layer | What it gates | Stored in | Guide |
|-------|----------------|-----------|-------|
| CMS roles (`AP_Roles`) | ACP, posts, pages, media, comments, plugins, themes, settings, REST writes | option `ap_user_roles`; usermeta `ap_capabilities` | [roles.md](roles.md) |
| Numeric level | WP-style convenience 0–10 | usermeta `ap_user_level` — **not** the forum ladder | [roles.md](roles.md) |
| Forum ACL (`AP_Forum_Permissions`) | View / read / post / attach / moderate **a forum** | `{prefix}forum_permissions` | [forums.md](forums.md) · [roles.md](roles.md#forum-acl-relationship) |
| Comment ownership | `edit_own_comments`, `delete_own_comments` (blog comments only) | role + meta `edit_comment` / `delete_comment` | [roles.md](roles.md#comment-ownership) |
| Default new-user role | option `default_role` = `subscriber` | Settings → General | [roles.md](roles.md) |

| Forum user level | System group | Who lands on it | Guide |
|------------------|--------------|-----------------|-------|
| ACP Forums → Edit | `forum_access_level` (Public / Members only / Read only / Moderators only / Administrators only / **This group only** (`group_only`) / Custom) | Custom uses the four rungs below; `group_only` uses named groups | [forums.md](forums.md#access-level-presets-forums--edit) · [roles.md](roles.md#forum-user-levels-not-cms-roles) |
| `guest` | `guests` | Not logged in | [roles.md](roles.md#forum-user-levels-not-cms-roles) |
| `registered` | `registered` | Any logged-in user | [roles.md](roles.md#forum-user-levels-not-cms-roles) |
| `moderator` | `global_moderators` | CMS cap `moderate_forums` | [roles.md](roles.md#forum-user-levels-not-cms-roles) |
| `administrator` | `administrators` | CMS cap `manage_forums` **or** CMS role `administrator` | [roles.md](roles.md#forum-user-levels-not-cms-roles) |
| `group_only` | named groups in option `forum_group_only` | Chosen named groups + administrators; guests denied; virtual `registered` **not** explicitly denied | [forums.md](forums.md#this-group-only-group_only) |

| Not a CMS role / not an ACP screen | Guide |
|------------------------------------|-------|
| Extra built-in roles (`moderator`, `super_admin`, `forum_moderator`) | [roles.md](roles.md) |
| Roles / Capabilities admin screen; editing `ap_user_roles` from Settings | [roles.md](roles.md) |
| `php ap-cli role` | [cli.md](cli.md) |
| Users → Ban / suspend control (forum bans live on Moderation; `user_status` = `1`) | [roles.md](roles.md) · [forums.md](forums.md) |
| `user_has_cap` / `map_meta_cap` filter | [roles.md](roles.md) |
| `ap_add_user_cap` wrapper (use `AP_Roles::addUserCap`) | [roles.md](roles.md) |

## Forum topic types

| `topics.topic_type` | Notes | Guide |
|---------------------|-------|-------|
| `standard` | Default | [forums.md](forums.md) |
| `sticky` | Stays at top of a forum | [forums.md](forums.md) |
| `announcement` | Board announcement | [forums.md](forums.md) |
| `rules` | Rules topic | [forums.md](forums.md) |
| Schema 12 backfill | `normal`→`standard`, `announce`/`global`→`announcement`. Extra types are **not in core** | [schema.md](schema.md) |

## `ap-cli` verbs

| Group | Usage as registered | Needs install | Subcommands | Guide |
|-------|---------------------|---------------|-------------|-------|
| `help` | `help [<command>]` | no | top-level or per-command | [cli.md](cli.md#help) |
| `version` | `version` | no | (also `-V` / `--version`) | [cli.md](cli.md#version) |
| `cli` | `cli info` | no | `info` only (bare `cli` → `cli info`) | [cli.md](cli.md#cli-info) |
| `core` | `core <version\|check-update>` | yes | `version` (default), `core check-update` (`--force`). **No** `core update` | [cli.md](cli.md#core) |
| `db` | `db <check\|migrate>` | yes | `db check` (default), `db migrate`. **No** `db rollback` | [cli.md](cli.md#db) |
| `option` | `option <get\|set\|delete\|list> ...` | yes | get / set / delete / list (`--search` on list). Bare `option` is usage | [cli.md](cli.md#option) |
| `plugin` | `plugin <list\|activate\|deactivate>` | yes | list (`--format=json`), activate, deactivate. **No install/zip** | [cli.md](cli.md#plugin) |
| `theme` | `theme <list\|activate>` | yes | list, activate. **No install/zip** | [cli.md](cli.md#theme) |
| `user` | `user <list\|get\|create>` | yes | list / get / create. **No** `user update` / `user delete`. Create **may** use reserved logins | [cli.md](cli.md#user) |
| `post` | `post <list\|get\|create\|update>` | yes | `--type=post\|page`; `--file` **local filesystem only**. Create defaults: **post → draft**, **page → publish**. **No** `post delete` | [cli.md](cli.md#post) |
| `cache` | `cache flush` | yes | flush (default) | [cli.md](cli.md#cache-flush) |
| `cron` | `cron event <list\|run>` | yes | aliases: `cron list`, `cron run`. Bare `cron` is usage | [cli.md](cli.md#cron) |
| `rewrite` | `rewrite flush` | yes | flush (default). **No** `rewrite list` | [cli.md](cli.md#rewrite-flush) |
| `site` | `site health [--format=text\|json]` | yes | health only. Text + critical check → exit `2`; `--format=json` always `0` | [cli.md](cli.md#site-health) |

| Global flag | As built | Guide |
|-------------|----------|-------|
| `--path=<path>` | AgoraPress root | [cli.md](cli.md#global-flags) |
| `--url=<url>` | Sets `AP_HOME` for this process; does not rewrite stored `home` / `siteurl` | [cli.md](cli.md#global-flags) |
| `--skip-plugins` | Defines `AP_CLI_SKIP_PLUGINS`. Skips **active** plugins; MU-plugins still load | [cli.md](cli.md#global-flags) |
| `--skip-themes` | Defines `AP_CLI_SKIP_THEMES`. Accepted / reserved; bootstrap does **not** read it | [cli.md](cli.md#global-flags) |
| `-h` / `--help` | Help; does not boot core (no plugin verbs) | [cli.md](cli.md#global-flags) |
| `-V` / `--version` | `AgoraPress {AP_VERSION} (PHP {PHP_VERSION})` | [cli.md](cli.md#global-flags) |

| Exit | Constant | Meaning | Guide |
|------|----------|---------|-------|
| `0` | `EXIT_OK` | ok, help, or version | [cli.md](cli.md#exit-codes) |
| `1` | `EXIT_USAGE` | usage, unknown command, not CLI SAPI | [cli.md](cli.md#exit-codes) |
| `2` | `EXIT_ERROR` | runtime error (DB, PHP &lt; 8.2, missing `bootstrap.php`, **text** Site Health with a critical check) | [cli.md](cli.md#exit-codes) |
| `3` | `EXIT_NOT_INSTALLED` | missing `ap-config.php` on a command that needs an installed site | [cli.md](cli.md#exit-codes) |

| Not this tool | Where | Guide |
|---------------|-------|-------|
| Fresh install | `php install/cli.php` / `/install/` | [install.md](install.md) |
| Apply a core zip | Tools → Update Core (no `php ap-cli core update`) | [updates.md](updates.md) |
| Plugin / theme zip | ACP installers (`install_plugins` / `install_themes`) | [plugins.md](plugins.md) · [themes.md](themes.md) |
| Plugin-registered verbs | `ap_cli_init` via `AP_Cli::addCommand()` | [cli.md](cli.md) |

## REST resources (`ap/v1`)

| Constant / option | Value | Guide |
|-------------------|--------|-------|
| Pretty prefix | `/ap-json/` (`AP_Rest::URL_PREFIX` = `ap-json`) | [rest.md](rest.md) |
| Query fallback | `?rest_route=` | [rest.md](rest.md) |
| Namespace | `ap/v1` | [rest.md](rest.md) |
| Master switch | `rest_api_enabled` default `1` (**no ACP screen**) | [rest.md](rest.md) |
| Off | HTTP **404** JSON `rest_disabled` | [rest.md](rest.md) · [troubleshooting.md](troubleshooting.md) |
| Auth | HTTP Basic first, then session cookie | [rest.md](rest.md) |
| Cookie-write nonce | `X-AP-Nonce` action `ap_rest` or `_ap_nonce` | [rest.md](rest.md) |

| Method | Route | Notes | Guide |
|--------|-------|--------|-------|
| GET | `/` | index (`/ap-json/`) | [rest.md](rest.md#index-and-settings) |
| GET | `/ap/v1` | namespace index | [rest.md](rest.md#index-and-settings) |
| GET | `/ap/v1/settings` | public site settings | [rest.md](rest.md#index-and-settings) |
| GET | `/ap/v1/posts` | list (blog module) | [rest.md](rest.md#apv1-resources-as-built) |
| POST | `/ap/v1/posts` | create (auth + cap) | [rest.md](rest.md#posts-the-only-built-in-writes) |
| GET | `/ap/v1/posts/{id}` | get | [rest.md](rest.md#apv1-resources-as-built) |
| PUT, PATCH | `/ap/v1/posts/{id}` | update | [rest.md](rest.md#posts-the-only-built-in-writes) |
| DELETE | `/ap/v1/posts/{id}` | delete | [rest.md](rest.md#posts-the-only-built-in-writes) |
| GET | `/ap/v1/pages` `[/{id}]` | **GET only** | [rest.md](rest.md#pages) |
| GET | `/ap/v1/comments` `[/{id}]` | GET only | [rest.md](rest.md#comments) |
| GET | `/ap/v1/users` `[/{id}]` | public fields, GET only | [rest.md](rest.md#users) |
| GET | `/ap/v1/categories` `[/{id}]` | GET only | [rest.md](rest.md#categories-and-tags) |
| GET | `/ap/v1/tags` `[/{id}]` | GET only | [rest.md](rest.md#categories-and-tags) |
| GET | `/ap/v1/forums` `[/{id}]` | GET; 404 if forum module off; unlistable / `group_only` omitted or JSON 404 `rest_cannot_view` | [forums.md](forums.md#listing-hygiene-view_forum) · [rest.md](rest.md#forums-and-topics) |
| GET | `/ap/v1/topics` `[/{id}]` | GET; same listable / `rest_cannot_view` rule | [forums.md](forums.md#listing-hygiene-view_forum) · [rest.md](rest.md#forums-and-topics) |

| REST writes | As built | Guide |
|-------------|----------|-------|
| Posts | POST / PUT / PATCH / DELETE | [rest.md](rest.md#posts-the-only-built-in-writes) |
| Pages, comments, users, categories, tags, forums, topics | **GET only** | [rest.md](rest.md) |
| Plugin routes | `ap_rest_api_init` + `AP_Rest::registerRoute()` | [plugins.md](plugins.md) |
| Application Passwords / OAuth / JWT / TOTP | **not in core** | [rest.md](rest.md) |

## Admin screens (`/ap-admin/`)

| | |
|---|---|
| Screen files | shipped `ap-admin/*.php` entry scripts |
| Not screens | `admin-bootstrap.php`, `admin-header.php`, `admin-footer.php`, `includes/`, `css/` |
| Task map | [admin.md](admin.md) |

| File | Task | Cap | Guide |
|------|------|-----|-------|
| `login.php` | Login / register / lost password / reset / verify email / resend (`?action=` allowlist) | no login required | [admin.md](admin.md#sign-in) · [admin.md](admin.md#public-registration) |
| `index.php` | Dashboard | `read` | [admin.md](admin.md#dashboard) |
| `profile.php` | Own profile | `read` | [admin.md](admin.md#users) |
| `edit.php` | Posts/pages list (`?post_type=`) | `edit_posts` / `edit_pages` | [admin.md](admin.md#content) |
| `post-new.php` | Add post/page | same | [admin.md](admin.md#content) |
| `post.php` | Edit row | meta `edit_post` / `edit_page` | [admin.md](admin.md#content) |
| `revision.php` | Revisions | same meta cap | [admin.md](admin.md#content) |
| `edit-comments.php` | Comments list | `moderate_comments` | [admin.md](admin.md#content) |
| `comment.php` | Single comment | meta `edit_comment` | [admin.md](admin.md#content) |
| `edit-tags.php` | Categories / tags | `manage_categories` | [admin.md](admin.md#content) |
| `media.php` `media-new.php` `upload.php` | Media library / upload | `upload_files` | [admin.md](admin.md#content) |
| `nav-menus.php` | Menus | `edit_theme_options` | [admin.md](admin.md#appearance) |
| `widgets.php` | Widgets | `edit_theme_options` | [admin.md](admin.md#appearance) |
| `themes.php` | Themes + **theme zip installer** (`install_themes`) | `switch_themes` | [admin.md](admin.md#appearance) · [themes.md](themes.md) |
| `theme-options.php` | Theme Options / Additional CSS | `edit_theme_options` | [admin.md](admin.md#appearance) · [themes.md](themes.md) |
| `plugins.php` | Plugins + **plugin zip installer** (`install_plugins`) | `activate_plugins` | [admin.md](admin.md#plugin-zip-installer) · [plugins.md](plugins.md#plugin-installer) |
| `users.php` | Users list (pending **Activate** needs `edit_users`) | `list_users` | [admin.md](admin.md#users) |
| `user-new.php` | Add user (reserved logins **allowed**) | `create_users` | [admin.md](admin.md#users) |
| `user-edit.php` | Edit selected account (pending **Resend verification** / **Activate account**) | `edit_users` | [admin.md](admin.md#users) |
| `forums.php` `forum-edit.php` | Forum tree / edit (**This group only** / `group_only`) | `manage_forums` | [admin.md](admin.md#forums) · [forums.md](forums.md#this-group-only-group_only) |
| `forum-groups.php` | Groups + ACL | `manage_forums` | [admin.md](admin.md#forums) · [forums.md](forums.md) |
| `forum-topics.php` `forum-moderation.php` | Topics / mod queue | `moderate_forums` | [admin.md](admin.md#forums) · [forums.md](forums.md) |
| `options-general.php` | General + **Site Icon** + membership (`registration_captcha`, `reserved_usernames`) | `manage_options` | [admin.md](admin.md#settings) · [admin.md](admin.md#public-registration) · [site-icon.md](site-icon.md) |
| `options-mail.php` | Mail (from identity, php/smtp, test to `admin_email`) | `manage_options` | [admin.md](admin.md#mail) |
| `options-writing.php` | Writing | `manage_options` | [admin.md](admin.md#settings) |
| `options-reading.php` | Reading / front page / feeds | `manage_options` | [admin.md](admin.md#settings) |
| `options-discussion.php` | Discussion / avatars | `manage_options` | [admin.md](admin.md#settings) |
| `options-media.php` | Media sizes | `manage_options` | [admin.md](admin.md#settings) |
| `options-permalink.php` | Permalinks | `manage_options` | [admin.md](admin.md#settings) · [rewrites.md](rewrites.md) |
| `options-privacy.php` | Privacy policy page | `manage_privacy_options` (`manage_options` fallback) | [admin.md](admin.md#settings) |
| `options-modules.php` | Pages / Blog / Forum toggles | `manage_options` | [admin.md](admin.md#settings) |
| `options-forums.php` | Forum settings | `manage_options` | [admin.md](admin.md#settings) · [forums.md](forums.md) |
| `options-hall-of-fame.php` | Voluntary handshake (join / leave / dismiss) | `manage_options` | [admin.md](admin.md#hall-of-fame-handshake) |
| `analytics.php` | Tools → Analytics | `manage_options` | [admin.md](admin.md#tools) |
| `site-health.php` | Tools → Site Health (outbound-mail last error; does **not** send) | `view_site_health` (`manage_options` fallback) | [admin.md](admin.md#tools) · [troubleshooting.md](troubleshooting.md#mail-not-arriving) |
| `update-core.php` | Tools → Update Core | `update_core` | [admin.md](admin.md#tools) · [updates.md](updates.md) |
| `import.php` | Tools → Import (WXR + phpBB) | `import` | [admin.md](admin.md#tools) |
| `export-personal-data.php` | Privacy export | `export_others_personal_data` | [admin.md](admin.md#tools) · [security.md](security.md) |
| `erase-personal-data.php` | Privacy erase | `erase_others_personal_data` | [admin.md](admin.md#tools) · [security.md](security.md) |
| `admin.php` | Registered ACP pages (`?page={id}` via `AP_Admin_Menu` allowlist) | registry `capability` (default `manage_options`) | [plugins.md](plugins.md#admin-pages-settings-screens-in-the-acp) — `ap_register_admin_page` |

| Extra chrome | As built | Guide |
|--------------|----------|-------|
| Donation / tip | Subtle admin-footer donation link **always appears** (`AP_Hall_Of_Fame::DONATION_URL`; not a paywall; **not an option**) | [admin.md](admin.md#donation--tip-never-a-paywall) |

| No ACP screen for | Toggle | Guide |
|-------------------|--------|-------|
| REST master switch | `php ap-cli option set rest_api_enabled 0` | [rest.md](rest.md) |
| Version check | `php ap-cli option set version_check_enabled 0` | [updates.md](updates.md) |
| `blog_public`, `sitemap_enabled`, `open_graph_enabled` | `php ap-cli option` | [cli.md](cli.md) |
| Roles / capabilities editor | **not in core** | [roles.md](roles.md) |
| Users → Ban / suspend | **not in core** (forum Moderation has bans) | [roles.md](roles.md) |

## Default Agora schemes

| Mode | Slug | Option / screen | Guide |
|------|------|-----------------|-------|
| Light (default) | `marble` | `agora_color_scheme` · Appearance → Theme Options | [themes.md](themes.md#default-theme-agora) |
| Light | `parchment` | same | [themes.md](themes.md#default-theme-agora) |
| Light | `cloud` | same | [themes.md](themes.md#default-theme-agora) |
| Dark | `obsidian` | same | [themes.md](themes.md#default-theme-agora) |
| Dark | `midnight` | same | [themes.md](themes.md#default-theme-agora) |
| Dark | `charcoal` | same | [themes.md](themes.md#default-theme-agora) |

## Install and updates

| Path | As built | Guide |
|------|----------|-------|
| Web installer | `/install/` steps `requirements` → `database` → `site` → `run` → `done` | [install.md](install.md) |
| CLI installer | `php install/cli.php` (flags + `AP_ADMIN_PASSWORD` / `AP_DB_PASSWORD`; exit `0`/`1`/`2`/`3`) | [install.md](install.md) |
| Uploads dir | Installer creates `ap-content/uploads/` (silent `index.php`) when missing and `ap-content/` is writable; runtime files stay gitignored | [install.md](install.md#permissions) |
| Docker | `docker-compose.yml` (example `localhost:8080`) | [install.md](install.md) |
| Manual config | copy `ap-config-sample.php` → `ap-config.php` | [install.md](install.md#manual-config) |
| Mail constants | Commented `AP_MAIL_*` / `AP_SMTP_*` names in the sample; defined values override Settings → Mail | [install.md](install.md#manual-config) · [admin.md](admin.md#mail) |
| Public `version.json` | `https://agorapress.extrovertednerd.com/version.json` — GET, **no site identity** | [updates.md](updates.md) |
| One-click apply | Tools → Update Core (`update-core.php`, cap `update_core`) | [updates.md](updates.md) |
| CLI check | `php ap-cli core check-update` (`--force`) | [updates.md](updates.md) · [cli.md](cli.md) |
| Schema apply | `php ap-cli db migrate` | [updates.md](updates.md) · [cli.md](cli.md) |
| Package | `php bin/package-release.php` → zip + sha256 + `version.json` | [updates.md](updates.md) |
| Does **not** overwrite | `ap-config.php`, `ap-config-sample.php`, `install/`, `ap-content/uploads/`, custom plugins / MU-plugins / themes (default `agora` **may** update) | [updates.md](updates.md) |
| Not a verb | `php ap-cli core update` | [updates.md](updates.md) |

## Rewrites

| Surface | As built | Guide |
|---------|----------|-------|
| Front controller | `index.php` | [rewrites.md](rewrites.md) |
| Apache | shipped `.htaccess` — `RewriteRule . /index.php [L]` | [rewrites.md](rewrites.md) |
| Nginx | shipped `docker/nginx.conf.example` — `try_files $uri $uri/ /index.php?$args;` | [rewrites.md](rewrites.md) |
| Flush | `php ap-cli rewrite flush` | [rewrites.md](rewrites.md) · [cli.md](cli.md) |
| Missing `try_files` | pretty URLs (and `/ap-json/`) 404 | [rewrites.md](rewrites.md) · [troubleshooting.md](troubleshooting.md) |
| Static root `favicon.ico` | left alone (not rewritten) | [rewrites.md](rewrites.md) · [site-icon.md](site-icon.md) |

| Structure (Settings → Permalinks) | `permalink_structure` (`AP_Rewrite`) | Guide |
|-----------------------------------|--------------------------------------|-------|
| Plain (fresh default) | `''` → `?p=` / `?page_id=` | [rewrites.md](rewrites.md) |
| Day and name | `/%year%/%monthnum%/%day%/%postname%/` (`/YYYY/MM/DD/slug/`) | [rewrites.md](rewrites.md) |
| Month and name | `/%year%/%monthnum%/%postname%/` | [rewrites.md](rewrites.md) |
| Numeric | `/archives/%post_id%` | [rewrites.md](rewrites.md) |
| Post name | `/%postname%/` | [rewrites.md](rewrites.md) |
| Custom | combination of supported tags | [rewrites.md](rewrites.md) |
| Pages | `/slug/` when pretty (`pagename`) | [rewrites.md](rewrites.md) |

## Hooks

| Hook | Type | Guide |
|------|------|-------|
| Selected actions / filters / lifecycle | — | [hooks.md](hooks.md) — **grep core for the rest**. This catalog is not a second hook encyclopedia. |
| `ap_mail_send` | filter | `AP_Mail::send()`: return `true`/`false` to replace php/smtp; `null` continues. Does not run when the `mail` rate limit blocks the send — [hooks.md](hooks.md#mail) · [admin.md](admin.md#mail) |
| Classic-compat `wp_mail()` | shim | Calls `AP_Mail::send()` when the classic layer is loaded; attachments ignored this pass — [compatibility.md](compatibility.md) · [admin.md](admin.md#mail) |
| `ap_user_created` | action | After `AP_User::create()` insert, including pending (`user_status` 1). Args: id, login, email, status — [hooks.md](hooks.md#users--registration) |
| `ap_reserved_usernames` | filter | Public-register reserved logins (locked list + extras). May **add** names; locked names always remain — [hooks.md](hooks.md#users--registration) |
| `ap_registration_captcha_mode` | filter | Visible mode string (`off` / `math` / `guard` / plugin) — [hooks.md](hooks.md#users--registration) · [admin.md](admin.md#public-registration) |
| `ap_registration_captcha_challenge` | filter | Challenge payload for the register form — [hooks.md](hooks.md#users--registration) · [admin.md](admin.md#public-registration) |
| `ap_registration_verify_captcha` | filter | Verify posted captcha/guard data — [hooks.md](hooks.md#users--registration) · [admin.md](admin.md#public-registration) |
| `ap_registration_captcha_fields` | action | Extra markup for a plugin-supplied visible mode — [admin.md](admin.md#public-registration) |
| Plugin ACP pages | — | [plugins.md](plugins.md#admin-pages-settings-screens-in-the-acp) (`ap_register_admin_page`) |
| REST registration | — | [rest.md](rest.md) (`ap_rest_api_init`) |
| CLI registration | — | [cli.md](cli.md) (`ap_cli_init`) |

## Not in core

| Surface | Notes |
|---------|-------|
| Gutenberg / FSE / block themes on the compat layer | [editor.md](editor.md) · [compatibility.md](compatibility.md) |
| Official SaaS, paid marketplace, telemetry, PHP &lt; 8.2, multisite, e-commerce | [vision-compliance.md](vision-compliance.md) |
| `AP_TELEMETRY` constant / flag / option; version check site identity | [security.md](security.md) · [updates.md](updates.md) |
| REST writes for pages, comments, users, categories, tags, forums, topics | [rest.md](rest.md) |
| Application Passwords / OAuth / JWT / TOTP / 2FA | [rest.md](rest.md) · [security.md](security.md) |
| ACP screens for `rest_api_enabled`, `blog_public`, `sitemap_enabled`, `open_graph_enabled`, `version_check_enabled` | [cli.md](cli.md) |
| Roles admin screen; Users → Ban / suspend | [roles.md](roles.md) |
| `php ap-cli plugin install` / `theme install` / `user update\|delete` / `post delete` | [cli.md](cli.md#not-in-this-tool--not-in-core) |
| `php ap-cli core update` / `db rollback` / `rewrite list` / `module` / `forum` / `role` | [cli.md](cli.md) |
| Extra core roles beyond administrator / editor / author / contributor / subscriber | [roles.md](roles.md) |
| Extra topic types beyond `standard` \| `sticky` \| `announcement` \| `rules` | [forums.md](forums.md) |
| PHPMailer / Composer mail library; HTML mail / newsletters / comment-subscription mail | [admin.md](admin.md#mail) |
| Google / hCaptcha / Turnstile widgets | [security.md](security.md#public-registration-gate) |
| A second docs index (`docs/index.md`) or a Bot-only tree | [README.md](README.md) |

## Guides this catalog points at

| Guide | Role |
|-------|------|
| [README.md](README.md) | Audience index (the only docs index) |
| [install.md](install.md) | Web / CLI / Docker / manual install |
| [updates.md](updates.md) | `version.json`, Tools → Update Core, `db migrate` |
| [rewrites.md](rewrites.md) | Front controller, permalinks, `try_files` |
| [cli.md](cli.md) | Every built-in `ap-cli` group |
| [admin.md](admin.md) | `/ap-admin/` screens by task |
| [forums.md](forums.md) | Forum module |
| [roles.md](roles.md) | Roles, caps, forum ACL relationship |
| [rest.md](rest.md) | `/ap-json/`, `ap/v1`, `rest_api_enabled` |
| [security.md](security.md) | Nonces, Argon2id, no telemetry, privacy |
| [troubleshooting.md](troubleshooting.md) | Symptom → check |
| [bot_handbook.md](bot_handbook.md) | Trusted-agent operating model |
| [hooks.md](hooks.md) | Selected hooks + grep for the rest |
| [themes.md](themes.md) | Hierarchy, Agora, Theme Options |
| [plugins.md](plugins.md) | Headers, zip installer, `ap_register_admin_page` |
| [editor.md](editor.md) | Visual \| Text (no blocks in core) |
| [site-icon.md](site-icon.md) | Favicon pack |
| [compatibility.md](compatibility.md) | Classic WP theme shim |
| [schema.md](schema.md) | Tables, migrations, `AP_DB_VERSION` |
| [vision-compliance.md](vision-compliance.md) | Principles checklist |
