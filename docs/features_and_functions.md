# Features and functions

| | |
|---|---|
| Core | `AP_VERSION` **0.3.6-beta** |
| Schema | `AP_DB_VERSION` **12** |
| Table prefix | default `ap_` |
| Control Panel | `/ap-admin/` |
| REST prefix | `/ap-json/` · namespace `ap/v1` |
| Rule | If a row is not in this catalog and not in the linked guide, it is **not in core** |
| Depth | Topic guides, not this file |

## Modules

| Module | Option | Default | Screen | Guide |
|--------|--------|---------|--------|-------|
| Static Pages | `ap_module_static_pages` | on | Settings → Modules (`options-modules.php`) | [admin.md](admin.md#settings) · [install.md](install.md) |
| Blog | `ap_module_blog` | on | Settings → Modules (`options-modules.php`) | [admin.md](admin.md#settings) |
| Forum | `ap_module_forum` | on | Settings → Modules (`options-modules.php`) | [forums.md](forums.md) · [admin.md](admin.md#forums) |

## Operator-facing options

| Option | Default | Surface | Guide |
|--------|---------|---------|-------|
| `ap_module_static_pages` | on | Settings → Modules | [admin.md](admin.md#settings) |
| `ap_module_blog` | on | Settings → Modules | [admin.md](admin.md#settings) |
| `ap_module_forum` | on | Settings → Modules | [forums.md](forums.md) |
| `blogname` | (installer) | Settings → General | [admin.md](admin.md#settings) |
| `blogdescription` | (installer) | Settings → General | [admin.md](admin.md#settings) |
| `siteurl` | (installer) | Settings → General | [admin.md](admin.md#settings) |
| `home` | (installer) | Settings → General | [admin.md](admin.md#settings) |
| `admin_email` | (installer) | Settings → General | [admin.md](admin.md#settings) |
| `users_can_register` | `0` | Settings → General | [admin.md](admin.md#sign-in) |
| `require_email_verification` | `1` | Settings → General | [admin.md](admin.md#settings) |
| `registration_captcha` | `off` (`off` \| `math`) | Settings → General | [admin.md](admin.md#settings) |
| `default_role` | `subscriber` | Settings → General | [roles.md](roles.md) |
| `timezone_string` | (installer) | Settings → General | [admin.md](admin.md#settings) |
| `WPLANG` | (installer) | Settings → General | [admin.md](admin.md#settings) |
| `date_format` | (installer) | Settings → General | [admin.md](admin.md#settings) |
| `time_format` | (installer) | Settings → General | [admin.md](admin.md#settings) |
| `start_of_week` | (installer) | Settings → General | [admin.md](admin.md#settings) |
| `site_icon` | empty | Settings → General | [site-icon.md](site-icon.md) |
| `default_category` | (seeded) | Settings → Writing | [admin.md](admin.md#settings) |
| `use_smilies` | (seeded) | Settings → Writing | [admin.md](admin.md#settings) |
| `default_comment_status` | (seeded) | Settings → Writing | [admin.md](admin.md#settings) |
| `show_on_front` | (seeded) | Settings → Reading | [admin.md](admin.md#settings) |
| `page_on_front` | (seeded) | Settings → Reading | [admin.md](admin.md#settings) |
| `page_for_posts` | (seeded) | Settings → Reading | [admin.md](admin.md#settings) |
| `posts_per_page` | (seeded) | Settings → Reading | [admin.md](admin.md#settings) |
| `posts_per_rss` | (seeded) | Settings → Reading | [admin.md](admin.md#settings) |
| `rss_use_excerpt` | (seeded) | Settings → Reading | [admin.md](admin.md#settings) |
| `show_avatars` | (seeded) | Settings → Discussion | [admin.md](admin.md#settings) |
| `avatar_default` | (seeded) | Settings → Discussion | [admin.md](admin.md#settings) |
| `avatar_rating` | (seeded) | Settings → Discussion | [admin.md](admin.md#settings) |
| `max_image_display_width` | (seeded) | Settings → Media | [admin.md](admin.md#settings) |
| `uploads_use_yearmonth_folders` | (seeded) | Settings → Media | [admin.md](admin.md#settings) |
| `permalink_structure` | empty = Plain `?p=` / `?page_id=` | Settings → Permalinks | [rewrites.md](rewrites.md) |
| `category_base` | (seeded) | Settings → Permalinks | [rewrites.md](rewrites.md) |
| `tag_base` | (seeded) | Settings → Permalinks | [rewrites.md](rewrites.md) |
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
| `rate_limit_*` | login / register / reset / upload windows | **no ACP screen** | [security.md](security.md) |

| Settings group (extra fields, not a complete key dump) | Screen | Guide |
|--------------------------------------------------------|--------|-------|
| Discussion comment policy | `options-discussion.php` | [admin.md](admin.md#settings) |
| Media thumbnail / medium / large sizes + crop | `options-media.php` | [admin.md](admin.md#settings) |
| Forums: per-page, guest view/post, PMs, attachments, approval, search, online, unread, signatures, attachment max size/types, flood interval, spam max links / blacklist | `options-forums.php` | [forums.md](forums.md) · [admin.md](admin.md#settings) |
| Hall of Fame handshake keys | `options-hall-of-fame.php` | [admin.md](admin.md#hall-of-fame-handshake) |

| CLI-only options | Command | Guide |
|------------------|---------|-------|
| `rest_api_enabled`, `version_check_enabled`, `blog_public`, `sitemap_enabled`, `open_graph_enabled`, `forum_attachment_max_per_post`, `forum_attachment_user_quota`, `forum_online_window` | `php ap-cli option get\|set` | [cli.md](cli.md#option) |

## Roles and capabilities

| Role | Level | Caps beyond the previous | Guide |
|------|-------|--------------------------|-------|
| `subscriber` | 0 | `read`, `delete_own_comments` | [roles.md](roles.md#built-in-roles) |
| `contributor` | 1 | + `edit_posts`, `delete_posts` | [roles.md](roles.md#who-gets-which-caps) |
| `author` | 2 | + `publish_posts`, `edit_published_posts`, `delete_published_posts`, `upload_files`, `edit_own_comments` | [roles.md](roles.md#who-gets-which-caps) |
| `editor` | 7 | + others/private posts, full pages, `manage_categories`, `moderate_comments`, `moderate_forums` | [roles.md](roles.md#who-gets-which-caps) |
| `administrator` | 10 | every primitive | [roles.md](roles.md#primitive-capabilities) |

| Layer | What it gates | Guide |
|-------|----------------|-------|
| CMS roles (`AP_Roles`) | ACP, posts, pages, media, comments, plugins, themes, settings, REST writes | [roles.md](roles.md) |
| Forum ACL (`AP_Forum_Permissions`) | View / read / post / attach / moderate **a forum** | [forums.md](forums.md) · [roles.md](roles.md#forum-acl-relationship) |
| Comment ownership | `edit_own_comments`, `delete_own_comments` | [roles.md](roles.md#comment-ownership) |
| Default new-user role | option `default_role` = `subscriber` | [roles.md](roles.md) |

## `ap-cli` verbs

| Group | Usage as registered | Subcommands | Guide |
|-------|---------------------|-------------|-------|
| `help` | `help [<command>]` | top-level or per-command | [cli.md](cli.md#help) |
| `version` | `version` | (also `-V` / `--version`) | [cli.md](cli.md#version) |
| `cli` | `cli info` | `info` only | [cli.md](cli.md#cli-info) |
| `core` | `core <version\|check-update>` | `version` (default), `core check-update` (`--force`) | [cli.md](cli.md#core) |
| `db` | `db <check\|migrate>` | `db check` (default), `db migrate` | [cli.md](cli.md#db) |
| `option` | `option <get\|set\|delete\|list> ...` | get / set / delete / list (`--search` on list) | [cli.md](cli.md#option) |
| `plugin` | `plugin <list\|activate\|deactivate>` | list (`--format=json`), activate, deactivate. **No install/zip** | [cli.md](cli.md#plugin) |
| `theme` | `theme <list\|activate>` | list, activate. **No install/zip** | [cli.md](cli.md#theme) |
| `user` | `user <list\|get\|create>` | list / get / create. **No update/delete** | [cli.md](cli.md#user) |
| `post` | `post <list\|get\|create\|update>` | `--type=post\|page`; `--file` **local filesystem only**. Create defaults: **post → draft**, **page → publish**. **No delete** | [cli.md](cli.md#post) |
| `cache` | `cache flush` | flush (default) | [cli.md](cli.md#cache-flush) |
| `cron` | `cron event <list\|run>` | aliases: `cron list`, `cron run` | [cli.md](cli.md#cron) |
| `rewrite` | `rewrite flush` | flush (default) | [cli.md](cli.md#rewrite-flush) |
| `site` | `site health [--format=text\|json]` | health only | [cli.md](cli.md#site-health) |

| Global flag | Guide |
|-------------|-------|
| `--path=<path>` | [cli.md](cli.md#global-flags) |
| `--url=<url>` | [cli.md](cli.md#global-flags) |
| `--skip-plugins` | [cli.md](cli.md#global-flags) |
| `--skip-themes` | [cli.md](cli.md#global-flags) |
| `-h` / `--help` | [cli.md](cli.md#global-flags) |
| `-V` / `--version` | [cli.md](cli.md#global-flags) |

| Exit | Meaning | Guide |
|------|---------|-------|
| `0` | ok | [cli.md](cli.md#exit-codes) |
| `1` | usage | [cli.md](cli.md#exit-codes) |
| `2` | error | [cli.md](cli.md#exit-codes) |
| `3` | not installed | [cli.md](cli.md#exit-codes) |

| Not this tool | Where | Guide |
|---------------|-------|-------|
| Fresh install | `php install/cli.php` / `/install/` | [install.md](install.md) |
| Plugin-registered verbs | `ap_cli_init` via `AP_Cli::addCommand()` | [cli.md](cli.md) |

## REST resources (`ap/v1`)

| Constant / option | Value | Guide |
|-------------------|--------|-------|
| Pretty prefix | `/ap-json/` | [rest.md](rest.md) |
| Query fallback | `?rest_route=` | [rest.md](rest.md) |
| Namespace | `ap/v1` | [rest.md](rest.md) |
| Master switch | `rest_api_enabled` default `1` (**no ACP screen**) | [rest.md](rest.md) |
| Auth | HTTP Basic first, then session cookie | [rest.md](rest.md) |
| Cookie-write nonce | `X-AP-Nonce` action `ap_rest` or `_ap_nonce` | [rest.md](rest.md) |

| Method | Route | Notes | Guide |
|--------|-------|--------|-------|
| GET | `/` | index | [rest.md](rest.md#index-and-settings) |
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
| GET | `/ap/v1/forums` `[/{id}]` | GET; 404 if forum module off | [rest.md](rest.md#forums-and-topics) |
| GET | `/ap/v1/topics` `[/{id}]` | GET; same | [rest.md](rest.md#forums-and-topics) |

| REST writes | As built | Guide |
|-------------|----------|-------|
| Posts | POST / PUT / PATCH / DELETE | [rest.md](rest.md#posts-the-only-built-in-writes) |
| Pages, comments, users, categories, tags, forums, topics | **GET only** | [rest.md](rest.md) |
| Plugin routes | `ap_rest_api_init` + `AP_Rest::registerRoute()` | [plugins.md](plugins.md) |

## Admin screens (`/ap-admin/`)

| | |
|---|---|
| Screen files | shipped `ap-admin/*.php` entry scripts |
| Not screens | `admin-bootstrap.php`, `admin-header.php`, `admin-footer.php`, `includes/`, `css/` |
| Task map | [admin.md](admin.md) |

| File | Task | Cap | Guide |
|------|------|-----|-------|
| `login.php` | Login / register / lost password / reset / verify email (`?action=` allowlist) | no login required | [admin.md](admin.md#sign-in) |
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
| `users.php` | Users list | `list_users` | [admin.md](admin.md#users) |
| `user-new.php` | Add user | `create_users` | [admin.md](admin.md#users) |
| `user-edit.php` | Edit selected account | `edit_users` | [admin.md](admin.md#users) |
| `forums.php` `forum-edit.php` | Forum tree / edit | `manage_forums` | [admin.md](admin.md#forums) · [forums.md](forums.md) |
| `forum-groups.php` | Groups + ACL | `manage_forums` | [admin.md](admin.md#forums) · [forums.md](forums.md) |
| `forum-topics.php` `forum-moderation.php` | Topics / mod queue | `moderate_forums` | [admin.md](admin.md#forums) · [forums.md](forums.md) |
| `options-general.php` | General + **Site Icon** | `manage_options` | [admin.md](admin.md#settings) · [site-icon.md](site-icon.md) |
| `options-writing.php` | Writing | `manage_options` | [admin.md](admin.md#settings) |
| `options-reading.php` | Reading / front page / feeds | `manage_options` | [admin.md](admin.md#settings) |
| `options-discussion.php` | Discussion / avatars | `manage_options` | [admin.md](admin.md#settings) |
| `options-media.php` | Media sizes | `manage_options` | [admin.md](admin.md#settings) |
| `options-permalink.php` | Permalinks | `manage_options` | [admin.md](admin.md#settings) · [rewrites.md](rewrites.md) |
| `options-privacy.php` | Privacy policy page | `manage_privacy_options` (`manage_options` fallback) | [admin.md](admin.md#settings) |
| `options-modules.php` | Pages / Blog / Forum toggles | `manage_options` | [admin.md](admin.md#settings) |
| `options-forums.php` | Forum settings | `manage_options` | [admin.md](admin.md#settings) · [forums.md](forums.md) |
| `options-hall-of-fame.php` | Voluntary handshake + donation URL | `manage_options` | [admin.md](admin.md#hall-of-fame-handshake) |
| `analytics.php` | Tools → Analytics | `manage_options` | [admin.md](admin.md#tools) |
| `site-health.php` | Tools → Site Health | `view_site_health` (`manage_options` fallback) | [admin.md](admin.md#tools) |
| `update-core.php` | Tools → Update Core | `update_core` | [admin.md](admin.md#tools) · [updates.md](updates.md) |
| `import.php` | Tools → Import (WXR + phpBB) | `import` | [admin.md](admin.md#tools) |
| `export-personal-data.php` | Privacy export | `export_others_personal_data` | [admin.md](admin.md#tools) · [security.md](security.md) |
| `erase-personal-data.php` | Privacy erase | `erase_others_personal_data` | [admin.md](admin.md#tools) · [security.md](security.md) |
| `admin.php` | Registered ACP pages (`?page={id}` via `AP_Admin_Menu` allowlist) | registry `capability` (default `manage_options`) | [plugins.md](plugins.md#admin-pages-settings-screens-in-the-acp) — `ap_register_admin_page` |

| Extra chrome | As built | Guide |
|--------------|----------|-------|
| Donation / tip | Subtle admin-footer donation link **always appears** (not a paywall; not an option) | [admin.md](admin.md#donation--tip-never-a-paywall) |

## Default Agora schemes

| Mode | Slug | Option / screen | Guide |
|------|------|-----------------|-------|
| Light (default) | `marble` | `agora_color_scheme` · Appearance → Theme Options | [themes.md](themes.md#default-theme-agora) |
| Light | `parchment` | same | [themes.md](themes.md#default-theme-agora) |
| Light | `cloud` | same | [themes.md](themes.md#default-theme-agora) |
| Dark | `obsidian` | same | [themes.md](themes.md#default-theme-agora) |
| Dark | `midnight` | same | [themes.md](themes.md#default-theme-agora) |
| Dark | `charcoal` | same | [themes.md](themes.md#default-theme-agora) |

## Hooks

| Surface | Guide |
|---------|-------|
| Selected actions / filters / lifecycle | [hooks.md](hooks.md) — **grep core for the rest**. This catalog is not a second hook encyclopedia. |
| Plugin ACP pages | [plugins.md](plugins.md#admin-pages-settings-screens-in-the-acp) (`ap_register_admin_page`) |
| REST registration | [rest.md](rest.md) (`ap_rest_api_init`) |
| CLI registration | [cli.md](cli.md) (`ap_cli_init`) |

## Not in core

| Surface | Notes |
|---------|-------|
| Gutenberg / FSE / block themes on the compat layer | [editor.md](editor.md) · [compatibility.md](compatibility.md) |
| Official SaaS, paid marketplace, telemetry, PHP &lt; 8.2, multisite, e-commerce | [vision-compliance.md](vision-compliance.md) |
| REST writes for pages, comments, users, categories, tags, forums, topics | [rest.md](rest.md) |
| ACP screens for `rest_api_enabled`, `blog_public`, `sitemap_enabled`, `open_graph_enabled`, `version_check_enabled` | [cli.md](cli.md) |
| `ap-cli plugin install` / `theme install` / `user update\|delete` / `post delete` | [cli.md](cli.md#not-in-this-tool--not-in-core) |
| Extra core roles beyond administrator / editor / author / contributor / subscriber | [roles.md](roles.md) |
| Extra topic types beyond `standard` \| `sticky` \| `announcement` \| `rules` | [forums.md](forums.md) |
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
