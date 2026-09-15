# Features and functions

| | |
|---|---|
| Core | `AP_VERSION` **0.3.11-beta** |
| Schema | `AP_DB_VERSION` **13** |
| Table prefix | default `ap_` (`$table_prefix` in `ap-config.php`) |
| Control Panel | `/ap-admin/` |
| REST prefix | `/ap-json/` · namespace `ap/v1` |
| Telemetry | **none** — no `AP_TELEMETRY` constant, flag, or option; version check sends **no site identity** |
| Rule | If a row is not in this catalog and not in the linked guide, it is **not in core** |
| Public-safe | Generic examples only (`example.com`). No private hosts, persona mailboxes, or live fleet inventory — [README.md](README.md#public-safe-rule) |
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
| `default_category` | living category term id (**no** magic `0`) | Settings → Writing · Posts → Categories | [admin.md](admin.md#default-post-category) |
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
| `agora_visitor_color_preview` | `0` (off) | Appearance → Theme Options (Agora) | [themes.md](themes.md#visitor-color-scheme-preview) |
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
| `forum_notify_max_per_minute` | `4` (clamped 1–60) | **CLI only** — not on Settings → Forums; own topic-notify cap (not `rate_limit_mail`) | [forums.md](forums.md#topic-email-notifications) · [admin.md](admin.md#topic-email-notifications) |

| Default post category | As built | Guide |
|-----------------------|----------|-------|
| Stored value | Living category term id. **No** magic `0` | [admin.md](admin.md#default-post-category) |
| Installer | Options seed `0`; `ensureDefaultCategory()` then persists Uncategorized’s id | [admin.md](admin.md#default-post-category) |
| `ensureDefaultCategory()` | May create slug `uncategorized`. Sets the option only when stored is `0` / empty / dead. Does **not** clobber a living custom default | [admin.md](admin.md#default-post-category) |
| Cannot delete | Current default; last remaining category | [admin.md](admin.md#default-post-category) |
| Uncategorized | Seed, not immortal. Deletable once it is not the default. Orphan posts reassign to the new default | [admin.md](admin.md#default-post-category) |
| **Set as default** | Posts → Categories row action. GET `action=set-default` + `tag_ID`. Nonce `set-default-tag-{id}`. Cap `manage_categories` | [admin.md](admin.md#default-post-category) |
| Hidden Delete | “This is the default category. Set another category as default first.” | [admin.md](admin.md#default-post-category) |
| Writing | Living categories only. **No** `<option value="0">`. `AP_Settings::sanitizeDefaultCategory()` | [admin.md](admin.md#default-post-category) |
| Public lists | `ap_get_the_category_list` skips empty-name terms; omit the line when none remain (no “Posted in , ,”) | [themes.md](themes.md#default-theme-agora) |

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
| `forum_topic_notify_enabled` | `0` (off: no Subscribe chrome, no enqueue, no send) | [forums.md](forums.md#topic-email-notifications) · [admin.md](admin.md#topic-email-notifications) |
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
| `rate_limit_mail_max` | `20` | **no ACP screen** (outbound `mail` action: verification / reset / test; **not** topic notify) | [security.md](security.md#outbound-mail) |
| `rate_limit_mail_window` | `3600` | **no ACP screen** (outbound `mail` action: verification / reset / test; **not** topic notify) | [security.md](security.md#outbound-mail) |
| `rate_limit_mail_lockout` | `3600` | **no ACP screen** (outbound `mail` action: verification / reset / test; **not** topic notify) | [security.md](security.md#outbound-mail) |

| CLI-only options | Command | Guide |
|------------------|---------|-------|
| `rest_api_enabled`, `version_check_enabled`, `blog_public`, `sitemap_enabled`, `open_graph_enabled`, `forum_attachment_max_per_post`, `forum_attachment_user_quota`, `forum_online_window`, `forum_notify_max_per_minute` | `php ap-cli option get\|set` | [cli.md](cli.md#option) |

## Schema

| | |
|---|---|
| Target | `AP_DB_VERSION` **13** (`ap-includes/version.php`) |
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
| 13 | `0013_topic_subscriptions.php` | `topic_subscriptions` (`user_id`, `topic_id`, `created_at`; unique `(user_id, topic_id)`; index on `topic_id`). Seeds `forum_topic_notify_enabled` `'0'`, `forum_notify_max_per_minute` `'4'`. Does **not** backfill usermeta `forum_notify_email`. Unread tables unchanged. |

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
| | `topic_subscriptions` |

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

## Topic email notifications

| Gate | Key | Default | Surface | Guide |
|------|-----|---------|---------|-------|
| 1. Site master | option `forum_topic_notify_enabled` | **off** (`'0'`) | Settings → Forums: **Allow topic email notifications.** Off: no chrome, no enqueue, no send | [admin.md](admin.md#topic-email-notifications) |
| 2. User master | usermeta `forum_notify_email` | **off** (`'0'`; missing = off) | Profile: **Email me about topics I subscribe to.** Users → Edit: **Email this member about topics they subscribe to.** Users → Add omits the fieldset | [admin.md](admin.md#topic-email-notifications) · [forums.md](forums.md#topic-email-notifications) |
| 3. Per-topic watch | `{prefix}topic_subscriptions` | no row | Topic **Subscribe** / **Unsubscribe**, or compose **Notify me of replies** (default off) | [forums.md](forums.md#topic-email-notifications) |

| Piece | As built | Guide |
|-------|----------|-------|
| Three gates | All default **off**. Mail only when every gate is on | [forums.md](forums.md#topic-email-notifications) |
| Class | `AP_Forum_Notify` (`ap-includes/class-ap-forum-notify.php`) | [forums.md](forums.md#topic-email-notifications) |
| Mail when | Site on **and** user on **and** subscribed **and** reply approved **and** recipient still `view_forum` **and** usable email **and** not the poster | [forums.md](forums.md#topic-email-notifications) |
| Chrome | Gate 1 + logged in + `view_forum`. Does **not** require the user master. Guests never qualify | [forums.md](forums.md#topic-email-notifications) |
| Auto-watch | **No.** Visit / start / reply does not subscribe unless **Subscribe** or compose `notify_replies` | [forums.md](forums.md#topic-email-notifications) |
| First Subscribe (master off) | Flip `forum_notify_email` **on** with a notice (`topic_subscribed_email_on`). Do **not** refuse | [forums.md](forums.md#topic-email-notifications) |
| Table | `{prefix}topic_subscriptions` (`user_id`, `topic_id`, `created_at`). Unique `(user_id, topic_id)`. Index `topic_id`. Schema **13**. **Not** `topic_track` / `forum_track` | [forums.md](forums.md#topic-email-notifications) |
| Drop rows | Unsubscribe; user deleted; topic **hard**-deleted. Soft-delete keeps watches. Failed send does **not** drop the subscription | [forums.md](forums.md#topic-email-notifications) |
| Enqueue | Approved reply POST → `AP_Cron` hook `ap_forum_topic_notify` (`topic_id` + `reply_post_id`). No N SMTP in the request. `createReply()` itself does **not** enqueue | [forums.md](forums.md#topic-email-notifications) |
| Worker | `AP_Forum_Notify::processQueuedReply()`. Drops poster, user-master off, lost `view_forum`, bad addresses. Digest same `(user, topic)` when several unsent replies exist | [forums.md](forums.md#topic-email-notifications) |
| Rate cap | Own bucket transient `ap_fn_rpm` (60s). Option `forum_notify_max_per_minute` default **4** (**CLI only**). `AP_Mail::send()` with `skip_rate_limit` — does **not** consume `rate_limit_mail` | [forums.md](forums.md#topic-email-notifications) |
| Mail | `text/plain`. Subject `[{site name}] New reply in {topic title}` (digest: `{n} new replies`). Body: title, reply author, spoiler-stripped excerpt, absolute URL, signed unsubscribe. From / Reply-To: Settings → Mail | [forums.md](forums.md#topic-email-notifications) · [admin.md](admin.md#mail) |
| Unsubscribe token | Query `ap_forum_unsub` (HMAC, `user_id` + `topic_id`, 45-day TTL). No session. Drops **that** watch only; never turns the user master off | [forums.md](forums.md#topic-email-notifications) |
| Profile list | Title + **Unsubscribe**. Empty: “No topic subscriptions.” Forum module on (even if site switch off). No Agora front-end forum-account page | [admin.md](admin.md#topic-email-notifications) |
| Not this | Guest watches; board-wide watches; blog-comment subscriptions; HTML newsletters; push; auto-watch on visit; reusing `topic_track`; consuming `rate_limit_mail`; a Settings → Forums field for `forum_notify_max_per_minute` | [forums.md](forums.md#topic-email-notifications) |

## Forum moderation

| Piece | As built | Guide |
|-------|----------|-------|
| API | `AP_Forum_Moderation::moveTopic` / `mergeTopics` / `splitTopic` / `createReport`. UI layers **call** these; they do **not** rewrite them | [forums.md](forums.md#moderation) |
| Schema | **13** (no bump). `{prefix}reports` (migration 5). `{prefix}topic_subscriptions` stay on move; merge retargets | [schema.md](schema.md) · [forums.md](forums.md#moderation) |
| Front POST | `ap_forum_move_topic`, `ap_forum_merge_topic`, `ap_forum_split_topic`, `ap_forum_report_post` (`AP_Forum_Front::handlePost`) | [forums.md](forums.md#front-urls-and-templates) |
| Notices | `topic_moved`, `topics_merged`, `topic_split`, `post_reported` | [forums.md](forums.md#front-notices) |
| ACP Topics | `forum-topics.php`, cap `moderate_forums`. Row **Move** + bulk **Move to…**. Bulk **Merge into…**. **No** split. **No** row **Merge** | [admin.md](admin.md#topics-move-and-merge) |
| ACP reports | `forum-moderation.php` already lists `{prefix}reports` (resolve / dismiss / reopen). Front insert does **not** rebuild the queue | [forums.md](forums.md#moderation) · [admin.md](admin.md#forums) |

| Last Post | As built | Guide |
|-----------|----------|-------|
| Helper | One: `AP_Forum::refreshForumLastPost($forumId)`. `AP_Forum_Moderation` private wrapper only calls that. **No** second algorithm | [forums.md](forums.md#last-post) |
| Visible | Newest `forum_posts` with `post_approved=1` whose topic is `topic_approved=1` and `topic_status` ≠ `deleted`. Locked topics still qualify | [forums.md](forums.md#last-post) |
| Empty board | `last_post_id` / `last_topic_id` / `last_poster_id` → `0`; `last_post_time` → `1970-01-01 00:00:00` (`AP_Forum::EMPTY_DATETIME`) | [forums.md](forums.md#last-post) |
| Runs on | `deleteTopic` (soft and force), `deletePost` of an approved reply, `restoreTopic` (when approved), `moveTopic`, `mergeTopics`, `splitTopic`, post unapprove | [forums.md](forums.md#last-post) |
| Index cell | `forumToDisplayRow()` / `buildForumLastPostPayload()`. Stale deleted / missing / unapproved pointer → recount, then render or empty. Never a title or permalink for a deleted topic | [forums.md](forums.md#last-post) |
| Empty cell | `ap_forum_empty_last_post_html()` — **No posts** / **—** / **—** | [forums.md](forums.md#last-post) |
| Heal | Loading `/forums/` once recounts a stale pointer. **No** ACP “rebuild last post” button | [forums.md](forums.md#last-post) · [troubleshooting.md](troubleshooting.md#last-post-points-at-a-deleted-topic) |
| Not last | Deleting a topic that is **not** last leaves the real last post in place | [forums.md](forums.md#last-post) |

| Move | As built | Guide |
|------|----------|-------|
| Front | Topic toolbar **Move** when `move_topics` or `moderate_forum` on current **and** at least one other moderateable forum | [forums.md](forums.md#moderation) · [troubleshooting.md](troubleshooting.md#cannot-move-a-topic) |
| POST | `ap_forum_move_topic` (nonce `ap_forum_move_topic_{id}`, field `dest_forum_id`) | [forums.md](forums.md#moderation) |
| Dest | Forums the actor can moderate; **not** categories, **not** link boards, **not** current | [forums.md](forums.md#moderation) |
| Identity | Same `topic_id` and slug. `{prefix}topic_subscriptions` stay. **No** shadow “moved from” row | [forums.md](forums.md#moderation) |
| Helpers | `ap_forum_user_can_move_topic()`, `ap_forum_move_destinations()`, `ap_forum_move_topic_form_html()` · `userCanMoveTopic()` / `listMoveDestinations()` | [forums.md](forums.md#moderation) |
| ACP | Row **Move** (`topic-move-{id}`) + destination picker (`ap-topic-move-picker`). Bulk **Move to…** (`dest_forum_id` / `dest_forum_id2`) | [admin.md](admin.md#topics-move-and-merge) |
| Success | Front notice `topic_moved`. ACP stays on Topics. Bulk notice `bulk_topic_moved` | [admin.md](admin.md#topics-move-and-merge) |

| Merge | As built | Guide |
|-------|----------|-------|
| Front | Toolbar **Merge** when `moderate_forum` on current **and** at least one other moderateable topic | [forums.md](forums.md#moderation) |
| POST | `ap_forum_merge_topic` (nonce `ap_forum_merge_topic_{id}`, field `target_topic_id`) | [forums.md](forums.md#moderation) |
| Target | Topics in forums the actor can moderate; omits current, deleted, and shadow `moved` rows | [forums.md](forums.md#moderation) |
| Result | Posts move onto the target; source **deleted** (not soft-deleted). Subs source → target; duplicate `(user_id, target_id)` dropped. Redirect to target, notice `topics_merged` | [forums.md](forums.md#moderation) |
| Helpers | `ap_forum_user_can_merge_topic()`, `ap_forum_merge_targets()`, `ap_forum_merge_topic_form_html()` · `userCanMergeTopic()` / `listMergeTargets()` | [forums.md](forums.md#moderation) |
| ACP | Bulk **Merge into…** only (`target_topic_id` / `target_topic_id2`). **No** row **Merge**. Redirects to the target | [admin.md](admin.md#topics-move-and-merge) |

| Split | As built | Guide |
|-------|----------|-------|
| Front | Toolbar **Split** when `moderate_forum` on current **and** the topic has at least two posts | [forums.md](forums.md#moderation) |
| Form | Checkbox per post (`post_ids[]`, none pre-checked); new title (`topic_title`); optional dest (`dest_forum_id`, default current) | [forums.md](forums.md#moderation) |
| Dest | Forums the actor can moderate, **including** current; not categories or link boards | [forums.md](forums.md#moderation) |
| POST | `ap_forum_split_topic` (nonce `ap_forum_split_topic_{id}`). Calls `splitTopic` with `moderator_id` | [forums.md](forums.md#moderation) |
| Rule | Earliest selected post becomes the new first post. Original keeps ≥1 post. Redirect to the new topic, notice `topic_split` | [forums.md](forums.md#moderation) |
| Helpers | `ap_forum_user_can_split_topic()`, `ap_forum_split_destinations()`, `ap_forum_split_topic_form_html()` · `userCanSplitTopic()` / `listSplitDestinations()` | [forums.md](forums.md#moderation) |
| ACP | **No** split on Topics | [admin.md](admin.md#topics-move-and-merge) |

| Report | As built | Guide |
|--------|----------|-------|
| Who | Logged-in + `view_forum` on that forum (`can_report` from `getPostsDisplayData`). Guests: **no** form | [forums.md](forums.md#moderation) |
| POST | `ap_forum_report_post` (nonce `ap_forum_report_post_{id}`, hidden `post_id`). Reason required (`report_reason`, maxlength 255) | [forums.md](forums.md#moderation) |
| Insert | `{prefix}reports` type `post`, status `open`. One open report per user per post | [forums.md](forums.md#moderation) |
| Flood | `forum_flood_interval` via `isReportFlooding()`. Moderators / `manage_forums` skip | [forums.md](forums.md#moderation) |
| Fail | `createReport` returns `0` → does **not** claim success. No report mail this pass | [forums.md](forums.md#moderation) |
| Success | Notice `post_reported` (anchor `#post-{id}`). Helper `ap_forum_report_post_form_html()` | [forums.md](forums.md#moderation) |

| Not this | Guide |
|----------|-------|
| Shadow / “moved from” stub topics (`topic_status` includes `moved`; move / merge / split do **not** insert those rows) | [forums.md](forums.md#not-in-core) |
| Guest reports; report-notification email | [forums.md](forums.md#not-in-core) |
| ACP Topics **Split**; ACP row **Merge**; ACP “rebuild last post” button | [admin.md](admin.md#topics-move-and-merge) · [forums.md](forums.md#not-in-core) |
| Warning / ban issue screens | [forums.md](forums.md#not-in-core) |
| `php ap-cli forum` moderate verb | [cli.md](cli.md) |
| Private hosts, persona mailboxes, live fleet inventory | Generic examples only (`example.com`) — [README.md](README.md#public-safe-rule) · [forums.md](forums.md#last-post) |

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
| `post` | `post <list\|get\|create\|update>` | yes | `--type=post\|page`; `--file` **local filesystem only**. Create defaults: **post → draft**, **page → publish**. Update `--author=` living owner of that type. **No** `post delete` | [cli.md](cli.md#post) |
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
| `profile.php` | Own profile | `read` | [admin.md](admin.md#users) · Forum notify fieldset: [admin.md](admin.md#topic-email-notifications) |
| `edit.php` | Posts/pages list (`?post_type=`) | `edit_posts` / `edit_pages` | [admin.md](admin.md#content) · Quick Edit Author: [admin.md](admin.md#author-posts-and-pages) |
| `post-new.php` | Add post/page | same | [admin.md](admin.md#content) · Author picker: [admin.md](admin.md#author-posts-and-pages) |
| `post.php` | Edit row | meta `edit_post` / `edit_page` | [admin.md](admin.md#content) · Author picker: [admin.md](admin.md#author-posts-and-pages) |
| `revision.php` | Revisions | same meta cap | [admin.md](admin.md#content) |
| `edit-comments.php` | Comments list | `moderate_comments` | [admin.md](admin.md#content) |
| `comment.php` | Single comment | meta `edit_comment` | [admin.md](admin.md#content) |
| `edit-tags.php` | Categories / tags (**Set as default** on category) | `manage_categories` | [admin.md](admin.md#content) · [admin.md](admin.md#default-post-category) |
| `media.php` `media-new.php` `upload.php` | Media library / upload | `upload_files` | [admin.md](admin.md#content) |
| `nav-menus.php` | Menus | `edit_theme_options` | [admin.md](admin.md#appearance) |
| `widgets.php` | Widgets | `edit_theme_options` | [admin.md](admin.md#appearance) |
| `themes.php` | Themes + **theme zip installer** (`install_themes`) | `switch_themes` | [admin.md](admin.md#appearance) · [themes.md](themes.md) |
| `theme-options.php` | Theme Options / Additional CSS / Agora schemes + visitor preview | `edit_theme_options` | [admin.md](admin.md#appearance) · [themes.md](themes.md#theme-options-acp) |
| `plugins.php` | Plugins + **plugin zip installer** (`install_plugins`) | `activate_plugins` | [admin.md](admin.md#plugin-zip-installer) · [plugins.md](plugins.md#plugin-installer) |
| `users.php` | Users list (pending **Activate** needs `edit_users`) | `list_users` | [admin.md](admin.md#users) |
| `user-new.php` | Add user (reserved logins **allowed**) | `create_users` | [admin.md](admin.md#users) |
| `user-edit.php` | Edit selected account (pending **Resend verification** / **Activate account**) | `edit_users` | [admin.md](admin.md#users) · Forum notify fieldset: [admin.md](admin.md#topic-email-notifications) |
| `forums.php` `forum-edit.php` | Forum tree / edit (**This group only** / `group_only`) | `manage_forums` | [admin.md](admin.md#forums) · [forums.md](forums.md#this-group-only-group_only) |
| `forum-groups.php` | Groups + ACL | `manage_forums` | [admin.md](admin.md#forums) · [forums.md](forums.md) |
| `forum-topics.php` `forum-moderation.php` | Topics / mod queue (row **Move** / bulk **Move to…** / bulk **Merge into…**; **no** split on Topics) | `moderate_forums` | [admin.md](admin.md#forums) · [admin.md](admin.md#topics-move-and-merge) · Front Move / Merge / Split / Report: [forums.md](forums.md#moderation) |
| `options-general.php` | General + **Site Icon** + membership (`registration_captcha`, `reserved_usernames`) | `manage_options` | [admin.md](admin.md#settings) · [admin.md](admin.md#public-registration) · [site-icon.md](site-icon.md) |
| `options-mail.php` | Mail (from identity, php/smtp, test to `admin_email`) | `manage_options` | [admin.md](admin.md#mail) |
| `options-writing.php` | Writing (Default Post Category, living ids only) | `manage_options` | [admin.md](admin.md#settings) · [admin.md](admin.md#default-post-category) |
| `options-reading.php` | Reading / front page / feeds | `manage_options` | [admin.md](admin.md#settings) |
| `options-discussion.php` | Discussion / avatars | `manage_options` | [admin.md](admin.md#settings) |
| `options-media.php` | Media sizes | `manage_options` | [admin.md](admin.md#settings) |
| `options-permalink.php` | Permalinks | `manage_options` | [admin.md](admin.md#settings) · [rewrites.md](rewrites.md) |
| `options-privacy.php` | Privacy policy page | `manage_privacy_options` (`manage_options` fallback) | [admin.md](admin.md#settings) |
| `options-modules.php` | Pages / Blog / Forum toggles | `manage_options` | [admin.md](admin.md#settings) |
| `options-forums.php` | Forum settings | `manage_options` | [admin.md](admin.md#settings) · [forums.md](forums.md) · **Allow topic email notifications**: [admin.md](admin.md#topic-email-notifications) |
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
| Topic-notify send cap `forum_notify_max_per_minute` | `php ap-cli option set forum_notify_max_per_minute N` | [forums.md](forums.md#topic-email-notifications) |
| Roles / capabilities editor | **not in core** | [roles.md](roles.md) |
| Users → Ban / suspend | **not in core** (forum Moderation has bans) | [roles.md](roles.md) |

## ACP author picker

| Rule | As built | Guide |
|------|----------|-------|
| Screens | Add New / Edit (`post-new.php`, `post.php`) for **posts** and **pages**; Quick Edit on Posts / Pages (`edit.php`, POST `action=quick_edit`) | [admin.md](admin.md#author-posts-and-pages) |
| Cap | `edit_others_posts` / `edit_others_pages`. **No** new capability. Authors editing their own post do **not** see it | [admin.md](admin.md#author-posts-and-pages) |
| Control | Sidebar metabox **Author** (`ap-metabox-author`); `<select name="post_author" id="post_author">` | [admin.md](admin.md#author-posts-and-pages) |
| Options | Living, active users (`user_status` = 0) who can own that type (`edit_posts` / `edit_pages`). Pending / banned omitted. Current author stays listed even if inactive | [admin.md](admin.md#author-posts-and-pages) |
| Insert | Persists posted id when the actor may assign a living owner; otherwise the logged-in user | [admin.md](admin.md#author-posts-and-pages) |
| Update | Persists posted id when allowed; otherwise existing `post_author`. Does **not** unset `post_author` | [admin.md](admin.md#author-posts-and-pages) |
| Crafted POST | Forbidden / unknown / inactive / non-owner id ignored. Create → logged-in user; edit → keep existing | [admin.md](admin.md#author-posts-and-pages) |
| Quick Edit | Same assignment rules. Author field only when the actor may assign. Nonce `quick-edit-{id}` | [admin.md](admin.md#author-posts-and-pages) |
| CLI | `php ap-cli post update --author=` — living owner of that type; ineligible id leaves current author (exit `2`) | [cli.md](cli.md#post) |
| Source | `AP_Admin_Post_Edit::canAssignAuthor()` / `authorCandidates()` | [admin.md](admin.md#author-posts-and-pages) |
| Not this | Forum topic-starter reassignment; comment-author user reassignment (`comment.php` Author is the display name); media owner | [admin.md](admin.md#author-posts-and-pages) |

## Default Agora schemes

| Mode | Slug | Option / screen | Guide |
|------|------|-----------------|-------|
| Light (default) | `marble` | `agora_color_scheme` · Appearance → Theme Options | [themes.md](themes.md#default-theme-agora) |
| Light | `parchment` | same | [themes.md](themes.md#default-theme-agora) |
| Light | `cloud` | same | [themes.md](themes.md#default-theme-agora) |
| Dark | `obsidian` | same | [themes.md](themes.md#default-theme-agora) |
| Dark | `midnight` | same | [themes.md](themes.md#default-theme-agora) |
| Dark | `charcoal` | same | [themes.md](themes.md#default-theme-agora) |

| Visitor preview | As built | Guide |
|-----------------|----------|-------|
| Option | `agora_visitor_color_preview` default **off** (`'0'`). Label: **Allow visitors to preview color schemes** | [themes.md](themes.md#visitor-color-scheme-preview) |
| Persist | Cookie / `?agora_scheme=` only. **Never** writes `agora_color_scheme` | [themes.md](themes.md#visitor-color-scheme-preview) |
| Resolve (option on) | valid `?agora_scheme=` → valid cookie `agora_scheme` → site option → `marble` (`agora_get_color_scheme()`) | [themes.md](themes.md#visitor-color-scheme-preview) |
| Site default | `agora_get_stored_color_scheme()` ignores query/cookie | [themes.md](themes.md#visitor-color-scheme-preview) |
| Option off | No visitor control markup; query/cookie ignored | [themes.md](themes.md#visitor-color-scheme-preview) |
| Control | `<nav class="agora-scheme-preview">` in `site-header__inner` (after account indicator). No-JS = GET links | [themes.md](themes.md#visitor-color-scheme-preview) |
| Cookie | `agora_scheme`, path `/`, `SameSite=Lax`, not HttpOnly, max-age 30 days | [themes.md](themes.md#visitor-color-scheme-preview) |
| Filter | `agora_color_scheme` on the already-resolved slug (`agora_filter_color_scheme()`). Never writes the option | [themes.md](themes.md#visitor-color-scheme-preview) |
| Not this | No hostname special case; no product-site-only theme; no `php ap-cli` preview verb | [themes.md](themes.md#visitor-color-scheme-preview) |

## Visual editor

| Piece | As built | Guide |
|-------|----------|-------|
| Widget | `AP_Editor` (`ap-includes/class-ap-editor.php`) | [editor.md](editor.md) |
| CSS | `ap-includes/css/ap-editor.css` — `color-scheme: inherit` | [editor.md](editor.md#contrast-contract) |
| Spoiler CSS | `ap-includes/css/ap-spoiler.css` — `color-scheme: inherit`; **no** `ap-includes/js/ap-spoiler.js` | [editor.md](editor.md#spoilers) · [themes.md](themes.md#spoiler-css) |
| Spoiler button | Toolbar **Spoiler** (`id` `spoiler`, command `visual-spoiler`) on post, page, comment, and forum | [editor.md](editor.md#spoilers) |
| Dark hosts | `color-scheme: dark` on `html`/`body`; `html.agora-mode-dark` / `body.agora-mode-dark`; `[data-ap-color-mode=dark]` | [editor.md](editor.md#contrast-contract) · [themes.md](themes.md#editor-contrast) |
| Pairing | Chrome `Canvas` / `CanvasText`; surface `Field` / `FieldText` | [editor.md](editor.md#contrast-contract) |
| Buttons | `currentColor`; isolation vs theme `button { color: inherit }` | [editor.md](editor.md#contrast-contract) |
| Emoji | Unicode glyph. No third-party icon font | [editor.md](editor.md#contrast-contract) |
| Architecture | Classic Visual \| Text. Gutenberg / FSE **not in core** | [editor.md](editor.md) |

| Token | Role | Guide |
|-------|------|-------|
| `--ap-editor-bg` | Toolbar / chrome background (`Canvas`) | [themes.md](themes.md#editor-contrast) |
| `--ap-editor-fg` | Toolbar / chrome / surface text (`CanvasText` / `FieldText`) | [themes.md](themes.md#editor-contrast) |
| `--ap-editor-surface` | Visual surface + textarea background (`Field`) | [themes.md](themes.md#editor-contrast) |
| `--ap-editor-border` | Chrome border | [themes.md](themes.md#editor-contrast) |
| `--ap-on-accent` | Active Visual \| Text chip text (fallback `#fff`) | [themes.md](themes.md#editor-contrast) |

## Spoilers

| Piece | As built | Guide |
|-------|----------|-------|
| Conversion | `AP_Content_Format` (BBCode → HTML). **Not** an `AP_Shortcode` handler named `spoiler` | [editor.md](editor.md#spoilers) |
| Stored BBCode | `[spoiler]hidden[/spoiler]`, `[spoiler=Label]hidden[/spoiler]`, `[spoiler title="Label"]hidden[/spoiler]` (single-quoted `title='Label'` too) | [editor.md](editor.md#spoilers) |
| Stored HTML | Native `<details class="ap-spoiler">` (Visual button, or Text HTML source) | [editor.md](editor.md#spoilers) |
| Output | `<details class="ap-spoiler">` + `<summary class="ap-spoiler__summary">` + `<div class="ap-spoiler__body">` | [editor.md](editor.md#spoilers) · [themes.md](themes.md#spoiler-css) |
| Default label | **Spoiler**. Empty / whitespace-only body → nothing | [editor.md](editor.md#spoilers) |
| Nested | Innermost first, then one extra pass (one nesting level) | [editor.md](editor.md#spoilers) |
| Toolbar Visual | Wraps selection in `<details class="ap-spoiler">`. Always inserts label **Spoiler** (no prompt) | [editor.md](editor.md#spoilers) |
| Toolbar Text | Wraps with `[spoiler]` … `[/spoiler]` (`data-ap-editor-wrap-open` / `wrap-close`) | [editor.md](editor.md#spoilers) |
| Surfaces | Post, page, comment, and forum compose (`AP_Editor`) | [editor.md](editor.md#spoilers) |
| CSS | `ap-includes/css/ap-spoiler.css` (`color-scheme: inherit`). Closed body `display: none` (not hover-only). Open body follows page `color-scheme`. Native keyboard | [themes.md](themes.md#spoiler-css) · [editor.md](editor.md#spoilers) |
| kses | Keeps `details` / `summary` / `class` / `open` plus allow-listed inner markup | [editor.md](editor.md#spoilers) |
| Snippets | `AP_Content_Format::stripSpoilers()` / `ap_strip_spoilers()`. Default placeholder **`[Spoiler]`**; pass `''` to drop. Used in excerpts, feeds, Open Graph, search snippets, forum last-post blurbs, notify excerpts | [editor.md](editor.md#spoilers) · [forums.md](forums.md#topic-email-notifications) |
| JavaScript | Works with JavaScript off (native `<details>`). **No** `ap-includes/js/ap-spoiler.js` | [editor.md](editor.md#spoilers) |
| Not this | Per-forum “everything in this room is spoilered” flag; hover-only reveal; Visual label prompt; a second spoiler shortcode | [editor.md](editor.md#spoilers) |

## Comments template

| Piece | As built | Guide |
|-------|----------|-------|
| Helper | `ap_comments_template( ?string $file = null )` in `ap-includes/template-tags.php` | [themes.md](themes.md#comments-template) |
| Prints | Approved comment list + Leave-a-comment form | [themes.md](themes.md#comments-template) |
| Auto-inject | **No.** `ap_the_content()` prints the post body only. Themes that never call the helper get no form | [themes.md](themes.md#comments-template) |
| Empty (no markup) | Blog module off; not singular / 404 / feed; no current post; post type does not support `comments` (core `page` does **not**); comments closed **and** no approved list | [themes.md](themes.md#comments-template) |
| Locate | 1. `$file` if readable `.php` with no `..` 2. Theme `comments.php` (child then parent) 3. Core fallback `ap-includes/theme-compat/comments.php` | [themes.md](themes.md#comments-template) |
| Fallback | Approved list or “No comments yet.”; closed copy; log-in-to-comment when `comment_registration` and guest; else form + `AP_Editor` (context `comment`) | [themes.md](themes.md#comments-template) |
| POST | `ap_comment_action=ap_comment_post` via `ap_handle_comment_form_post()` — same handler Agora uses. **No** second endpoint | [themes.md](themes.md#comments-template) |
| Agora | `single.php` calls `ap_comments_template()` **once** (theme `comments.php`). Exactly one form. `page.php` does **not** call it | [themes.md](themes.md#comments-template) · [editor.md](editor.md) |
| Hierarchy | `comments.php` is **not** in `AP_Theme::getHierarchy()` | [themes.md](themes.md#comments-template) |
| Not this | Auto-append after `ap_the_content`; a form on themes that never call the helper; page comments in core; a second comments POST handler | [themes.md](themes.md#comments-template) |

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
| `agora_color_scheme` | filter | Resolved slug only (`agora_filter_color_scheme()`). Invalid returns keep the slug. **Never** writes the site option — [themes.md](themes.md#visitor-color-scheme-preview) |
| `ap_forum_topic_notify` | cron | Queued topic-notify worker (`topic_id` + `reply_post_id`). Reply POST schedules only — [forums.md](forums.md#topic-email-notifications) |
| Plugin ACP pages | — | [plugins.md](plugins.md#admin-pages-settings-screens-in-the-acp) (`ap_register_admin_page`) |
| REST registration | — | [rest.md](rest.md) (`ap_rest_api_init`) |
| CLI registration | — | [cli.md](cli.md) (`ap_cli_init`) |

## Not in core

| Surface | Notes |
|---------|-------|
| Gutenberg / FSE / block themes on the compat layer | [editor.md](editor.md) · [compatibility.md](compatibility.md) |
| Magic `default_category` `0` / “— Uncategorized / site default —” Writing sentinel | [admin.md](admin.md#default-post-category) |
| Hostname allowlist or product-site-only theme for scheme preview | [themes.md](themes.md#visitor-color-scheme-preview) |
| `php ap-cli` visitor-preview verb | [themes.md](themes.md#visitor-color-scheme-preview) · [cli.md](cli.md) |
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
| Per-forum spoiler flag; hover-only spoilers; `AP_Shortcode` handler named `spoiler`; `ap-includes/js/ap-spoiler.js` | [editor.md](editor.md#spoilers) |
| Auto-inject comment form / auto-append after `ap_the_content` | [themes.md](themes.md#comments-template) |
| Guest watches, board-wide watches, auto-watch on visit / start / reply | [forums.md](forums.md#topic-email-notifications) |
| Topic notify consuming `rate_limit_mail`; Settings field for `forum_notify_max_per_minute` | [forums.md](forums.md#topic-email-notifications) |
| Shadow / “moved from” stub topics; ACP Topics **Split**; ACP row **Merge**; ACP “rebuild last post” button | [forums.md](forums.md#not-in-core) · [admin.md](admin.md#topics-move-and-merge) |
| Guest reports; report-notification email | [forums.md](forums.md#not-in-core) |
| Warning / ban issue screens | [forums.md](forums.md#not-in-core) |
| Forum topic-starter or comment-author reassignment from the Posts / Pages Author picker | [admin.md](admin.md#author-posts-and-pages) |
| Google / hCaptcha / Turnstile widgets | [security.md](security.md#public-registration-gate) |
| A second docs index (`docs/index.md`) or a Bot-only tree | [README.md](README.md) |
| Private hosts, persona mailboxes, live fleet inventory | [README.md](README.md#public-safe-rule) · [bot_handbook.md](bot_handbook.md#public-safe-rule) |

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
