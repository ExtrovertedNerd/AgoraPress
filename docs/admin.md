# Admin Control Panel (`/ap-admin/`)

This is the **operator map of `/ap-admin/`** for AgoraPress **`0.3.6-beta`**
(schema `AP_DB_VERSION` **12**). It describes the Control Panel **as built**:
screens grouped by task, the capability that gates each area, the voluntary
Hall of Fame handshake, and the unobtrusive donation link. It does **not**
invent screens, menus, or options.

The compact landing-page bullets are in
[`../README.md`](../README.md). This file is the operator depth guide. Plugin
zip installer and ACP `ap_register_admin_page()` stay in
[plugins.md](plugins.md) — this file only points at those sections. Roles and
meta-caps are in [roles.md](roles.md).

**Source (as built):** `ap-admin/` entry scripts, `ap-admin/admin-bootstrap.php`,
`ap-admin/includes/class-ap-admin.php` (`AP_Admin`),
`ap-includes/class-ap-admin-menu.php` (`AP_Admin_Menu`),
`ap-includes/class-ap-plugin-installer.php` (`AP_Plugin_Installer`),
`ap-includes/class-ap-hall-of-fame.php` (`AP_Hall_Of_Fame`),
`ap-admin/admin-header.php`, `ap-admin/admin-footer.php`.

There is **no** Gutenberg / block editor in the ACP, **no** official plugin or
theme marketplace, and **no** paywall. Missing from this guide and from core
means **not in core**.

---

## How the ACP works

The Control Panel lives at **`/ap-admin/`**. Pretty permalinks are not required
to reach it; the directory is a real folder of PHP entry scripts. After login,
the shell (`admin-header.php` / `admin-footer.php`) wraps every screen.

Every screen except `login.php` includes `admin-bootstrap.php`, which defines
`AP_ADMIN`, boots core, and (unless `$ap_admin_skip_auth`) calls
`AP_Admin::requireLogin()`. Logged-in ACP requests also:

1. Queue an **Update available** banner via
   `AP_Version_Check::maybeQueueAdminNotice()` (cached `version.json`; **no
   site identity** — [updates.md](updates.md)).
2. Fire `AP_Admin::fireAdminMenu()` **once** per request:
   `ap_admin_menu`, then the WordPress-compatible alias `admin_menu`. Plugins
   register pages here (`ap_register_admin_page()`).

ACP HTML sets `meta name="robots" content="noindex, nofollow"`.

| Rule | As built |
|------|----------|
| Login | Every screen except `login.php` requires a logged-in user with the `read` capability (`AP_Admin::requireLogin()`). Users with no role cannot enter. Failure **redirects** to `login.php` (not 403). |
| Screen cap | Each entry script then calls `AP_Admin::requireCapability(…)` (or a meta-cap on a row). Failure is HTTP **403**. |
| Unknown plugin page | `admin.php?page=` not in the `AP_Admin_Menu` allowlist → HTTP **404**. Body is the static string `The requested admin page was not found.` (never reflects the raw `?page=` value). The router never includes a filesystem path from query input. |
| Nonces | State-changing forms use HMAC tokens (`_ap_nonce` / `AP_Nonce`). The ACP does **not** use PHP `$_SESSION` CSRF for those forms — see [security.md](security.md). |
| Modules | Options `ap_module_static_pages`, `ap_module_blog`, and `ap_module_forum` hide related sidebar items and deny the matching screens (HTTP 403) when off. At least one module must stay on ([Settings → Modules](#settings)). |
| Menu | Sidebar items come from `AP_Admin::menuItems()`. Plugin pages registered with `ap_register_admin_page()` merge under Settings, Plugins, or Tools (empty `parent` → Plugins). Inactive plugins’ pages are omitted. |
| Session path | Login and the installer need a PHP `session.save_path` **writable by the php-fpm user**. A `770` directory the fpm user is not in fails CSRF / “security token” errors — [troubleshooting.md](troubleshooting.md). |
| Color mode | Header toggle cycles System / Light / Dark (`localStorage` key `ap_admin_color_mode`). Profile stores the same key as usermeta (`AP_Admin::COLOR_MODE_META`). The shell reads `data-ap-color-mode-pref` from that usermeta, then localStorage wins if set. Not a paywall. |

Not screens (chrome / loaders only): `admin-bootstrap.php`, `admin-header.php`,
`admin-footer.php`, `ap-admin/includes/`, `ap-admin/css/`.

### Router (`admin.php`)

**URL:** `/ap-admin/admin.php?page={id}` · **Cap:** the `capability` stored on
that `AP_Admin_Menu` row (default `manage_options`)

Pipeline as built (`AP_Admin::resolveRequestedAdminPage()`):

1. Login (bootstrap).
2. Sanitize `?page=` and look it up **only** in `AP_Admin_Menu`.
3. Unknown / empty / path-like slug → safe 404 (no callback, no include).
4. `AP_Admin::requireCapability()` on every render.
5. Header + registered callback + footer.

Canonical registration: [plugins.md](plugins.md#admin-pages-settings-screens-in-the-acp).
Operator pointer: [Plugin-registered ACP pages](#plugin-registered-acp-pages).

---

## Sign in

**URL:** `/ap-admin/login.php` (also `/ap-admin/` redirects here when logged
out). This screen skips the auth gate (`$ap_admin_skip_auth`).

`?action=` is an allowlist. Unknown values fall back to `login`. `resetpass` is
an alias of `rp`.

| `?action=` | What it does |
|------------|----------------|
| `login` (default) | Username or email (`log`) + password (`pwd`). Optional “remember me”. Nonce `admin-login`. Rate-limited (`AP_Rate_Limit`). Pending email verification is a distinct error. |
| `logout` | CSRF-protected (`log-out` nonce). |
| `register` | Shown only when option `users_can_register` is on (Settings → General). Nonce `admin-register`. Always-on when open: honeypot `ap_hp` (labeled Website), ~3s minimum fill, short-lived form ticket `ap_form_ticket` issued on GET. Optional math CAPTCHA. |
| `lostpassword` | Request a reset mail. Nonce `admin-lostpassword`. |
| `rp` / `resetpass` | Set a new password with the mailed key. |
| `verifyemail` | Confirm a new account from the mailed link. |

Already-logged-in visitors are sent to the dashboard (except verification
links). Registration, default role, and “require email verification” live under
[Settings → General](#settings), not on a separate membership screen.

There is **no** TOTP / 2FA in core.

---

## Dashboard

**Menu:** Dashboard · **URL:** `/ap-admin/index.php` · **Cap:** `read`

At a Glance (module-aware counts), Activity (recent posts/pages and comments),
and Quick Draft when the Blog module is on and the user can `edit_posts`
(POST `ap_dashboard_action=quick-draft`).

Administrators with `manage_options` who have not joined or dismissed Hall of
Fame see a voluntary join prompt here. Dismiss is POST
`ap_dashboard_action=hof-dismiss` (nonce `hall-of-fame-dismiss`); it stores
option `hall_of_fame_dismissed` and sends **no** data. The full join/leave UI
is [Settings → Hall of Fame](#hall-of-fame-handshake).

---

## Content

These items sit under the **Content** sidebar section. Blog/Pages items hide
when the matching module is off. Writing and Discussion **Settings** screens
also 403 with “The Blog module is disabled…” when Blog is off.

| Task | Menu | Cap | Notes |
|------|------|-----|-------|
| List / bulk-edit posts | Posts (`edit.php?post_type=post`) | `edit_posts` | Blog module. Status views, bulk trash/delete. |
| Write / edit a post | Add New / Edit (`post-new.php`, `post.php`) | `edit_posts` / meta `edit_post` | Visual editor is classic WYSIWYG — [editor.md](editor.md). No blocks in core. |
| Revisions | `revision.php?post=` | meta `edit_post` / `edit_page` | List, restore, delete autosaves. Post type must support revisions. |
| List / bulk-edit pages | Pages (`edit.php?post_type=page`) | `edit_pages` | Static Pages module. Hierarchical. |
| Write / edit a page | `post-new.php?post_type=page`, `post.php` | `edit_pages` / meta `edit_page` | |
| Categories | Categories (`edit-tags.php?taxonomy=category`) | `manage_categories` | Blog module. |
| Tags | Tags (`edit-tags.php?taxonomy=post_tag`) | `manage_categories` | Blog module. |
| Comments | Comments (`edit-comments.php`) | `moderate_comments` | Views: All / Pending / Approved / Spam / Trash. Bulk + row: approve, spam, trash, delete. |
| Edit one comment | `comment.php?c=` | meta `edit_comment` | Screen map lists `read`; the entry script then requires `edit_comment` on that row. Own comments with `edit_own_comments`, or any with `moderate_comments`. Only moderators may change approval status here. |
| Media library | Media (`upload.php`) | `upload_files` | List, upload (drag-and-drop), bulk delete. Files land under `ap-content/uploads/`. |
| Edit one attachment | `media.php` | `upload_files` | Title / caption / alt. |
| Add New Media | `media-new.php` | `upload_files` | Redirects to the library upload panel (`upload.php#ap-media-upload`). |

Site Icon is **not** a Media screen. It lives on Settings → General
([site-icon.md](site-icon.md)).

---

## Forums

Visible only when the Forum module is on (`ap_module_forum`). Operator depth
for hierarchy, topic types, likes, ACL, PMs, and module-off behaviour:
[forums.md](forums.md). Module-off on these screens is HTTP 403:
“The Forum module is disabled. Enable it under Settings → Modules.”

| Task | Menu | Cap | Notes |
|------|------|-----|-------|
| Forum tree | Forums (`forums.php`) | `manage_forums` | Categories and forums; bulk delete. |
| Create / edit a forum | `forum-edit.php` | `manage_forums` | Per-forum visibility (`forum_access_level`): Public, Members only, Read only (members), Moderators only, Administrators only, or Custom (Guest / Registered / Moderator / Administrator matrix). Forum ACL is never applied to blog posts or pages. |
| Topics | Topics (`forum-topics.php`) | `moderate_forums` | Lock, sticky, approve, trash, delete. Topic types: `standard` / `sticky` / `announcement` / `rules`. |
| Moderation queue | Moderation (`forum-moderation.php`) | `moderate_forums` | Pending topics/posts and reports. |
| Groups | Groups (`forum-groups.php`) | `manage_forums` | Named groups and membership; used with per-forum ACL. |

Site-wide forum defaults (guests, attachments, flood, search, online/unread,
PMs, signatures, spam) are **Settings → Forums** (`options-forums.php`, cap
`manage_options`), not the Forums menu. Not on that screen (CLI only —
[cli.md](cli.md) / [forums.md](forums.md)): `forum_attachment_max_per_post`,
`forum_attachment_user_quota`, `forum_online_window`.

---

## Appearance

| Task | Menu | Cap | Notes |
|------|------|-----|-------|
| Themes | Themes (`themes.php`) | `switch_themes` | List, activate, zip upload (`install_themes`, nonce `theme-upload`, POST `ap_theme_action=upload`, file `themezip`), delete. Classic PHP themes with a `Theme Name` header in `style.css`. Block / FSE packages are rejected — [compatibility.md](compatibility.md). Zip max **40 MiB** (`AP_Theme_Installer::DEFAULT_MAX_BYTES`), also bounded by PHP upload limits. `ZipArchive` required. |
| Theme Options | Theme Options (`theme-options.php`) | `edit_theme_options` | Core always offers Additional CSS. The default Agora theme also exposes color schemes. Themes register extra fields on `ap_theme_options_register` — [themes.md](themes.md). |
| Menus | Menus (`nav-menus.php`) | `edit_theme_options` | Pages / posts / categories / forums / useful links / custom URLs; assign to theme locations. |
| Widgets | Widgets (`widgets.php`) | `edit_theme_options` | Assign built-in widgets (Text, Recent Posts, Categories, Search, Pages, Navigation Menu) to theme sidebars. If the theme registered none, the screen seeds `sidebar-1` (Primary Sidebar) and `footer-1` (Footer). |

There is **no** theme file editor (`theme-editor.php`) in core.

---

## Plugins

**Menu:** Installed Plugins · **URL:** `/ap-admin/plugins.php` · **Cap:**
`activate_plugins`

Lists plugins under `ap-content/plugins/` that have a **Plugin Name** header.
Activate / deactivate / delete use GET + nonce
(`activate-plugin_{basename}` / `deactivate-plugin_{basename}` /
`delete-plugin_{basename}`). Must-use plugins in
`ap-content/mu-plugins/` load always and **cannot** be deactivated here.

An active plugin that registered an ACP page with a `plugin` basename gets a
**Settings** link → `admin.php?page={id}`.

Zip upload, overwrite, and delete: [Plugin zip installer](#plugin-zip-installer)
below, which points at [plugins.md](plugins.md#plugin-installer). Integrator
API (`ap_register_admin_page`, headers, MU-plugins):
[plugins.md](plugins.md#admin-pages-settings-screens-in-the-acp).

There is **no** plugin directory, paid marketplace, or plugin file editor
(`plugin-editor.php`) in core.

---

## Users

Edit User loads the **selected** account (`user-edit.php?user_id=`). A missing
or unknown id redirects to Users (`message=not_found`). Opening your own id
without `edit_users` redirects to Profile.

| Task | Menu | Cap | Notes |
|------|------|-----|-------|
| All users | Users (`users.php`) | `list_users` | Filter by role; bulk / row delete (not the sole administrator). |
| Add user | `user-new.php` | `create_users` | Login, email, password, role. Nonce `create-user`. |
| Edit another user | `user-edit.php?user_id=` | `edit_users` | Profile fields, role, password. Cannot demote the last administrator. Role is **not** editable on Profile. |
| Own profile | Profile (`profile.php`) | `read` | Any logged-in ACP user. Display name, email, avatar upload, signature, admin color-mode preference (usermeta `ap_admin_color_mode`). Changing password revokes other sessions. |

Default role for self-registration is Settings → General (`default_role`).
Core roles and comment-ownership caps: [roles.md](roles.md).

---

## Tools

| Task | Menu | Cap | Notes |
|------|------|-----|-------|
| Update Core | Update Core (`update-core.php`) | `update_core` | One-click zip apply from public `version.json`. **No site identity** on the check. Full guide: [updates.md](updates.md). There is **no** `php ap-cli core update` apply verb. |
| Import | Import (`import.php`) | `import` | WordPress **WXR** (`.xml`, POST `ap_import_action=wxr`, nonce `import-wxr`) and **phpBB** portable JSON (`phpbb-json`, nonce `import-phpbb-json`) **or** live database (`phpbb-db`, nonce `import-phpbb-db`; default prefix `phpbb_`). `manage_options` is accepted as a fallback on the POST path. WXR can import authors / attachments / comments; newly created authors need a password reset before they can log in. |
| Site Health | Site Health (`site-health.php`) | `view_site_health` | Tabs: **Status** / **Info** (`?tab=`). Status checks (HTTPS, salts, debug, telemetry absence, outbound mail transport + last error) never send data off-site — the mail check does **not** send a test message. Info is copy-paste system information (mail section omits SMTP secrets). Clear caches (expired transients) POST nonce `site-health-clear-caches`. `manage_options` is accepted as a fallback. CLI: `php ap-cli site health` — [cli.md](cli.md). |
| Analytics | Analytics (`analytics.php`) | `manage_options` (`AP_Admin_Analytics::CAPABILITY`) | Local pageview reports. Option `analytics_enabled` default **off**. Retention `analytics_retention_days` (default **90**, range 1–3650). Report window `?days=` is **7 / 14 / 30 / 90** (default **30**) — that window is not the retention option. Collection skips ACP, feeds, REST, logged-in administrators, and `DNT: 1`. Data never leaves the site database. Not Hall of Fame and not version-check traffic. Settings nonce `ap_analytics_settings`. |
| Export Personal Data | Export Personal Data (`export-personal-data.php`) | `export_others_personal_data` | Lookup by ID / login / email; JSON package (profile, posts, comments, forum activity, PMs). Preview or download. `manage_options` / `export` accepted as fallbacks. There is **no** user self-service portal. |
| Erase Personal Data | Erase Personal Data (`erase-personal-data.php`) | `erase_others_personal_data` | Anonymize identifiers and delete the account; content is retained (reassigned or “Deleted User”). Type `erase` to confirm. Cannot erase your own account from this screen. Cannot erase the sole administrator. `manage_options` / `delete_users` accepted as fallbacks. |

These options have **no** dedicated Settings screen — use
`php ap-cli option set …` ([cli.md](cli.md)):

- `version_check_enabled` (installer default `1`)
- `rest_api_enabled` (default on — [rest.md](rest.md))
- `blog_public`, `sitemap_enabled`, `open_graph_enabled` (default on)

---

## Settings

All of these require `manage_options` except Privacy (`manage_privacy_options`,
with `manage_options` accepted as a fallback).

| Task | Menu | What it stores |
|------|------|----------------|
| General | General (`options-general.php`) | `blogname`, `blogdescription`, **Site Icon** (`site_icon` attachment ID), `siteurl`, `home`, `admin_email`, `users_can_register`, `require_email_verification`, `registration_captcha` (`off` / `math`), `default_role`, `WPLANG`, `timezone_string`, `date_format`, `time_format`, `start_of_week`. |
| Mail | Settings → Mail (`options-mail.php`) | Own group (not General). From name (`mail_from_name`, empty uses `blogname`), From email (`mail_from_email`, separate from `admin_email`; empty uses `admin_email`; example `noreply@example.com`), optional Reply-To (`mail_reply_to`, empty uses `admin_email`), transport `php` \| `smtp` (`mail_transport`, default `php`), SMTP `smtp_host` / `smtp_port` / `smtp_encryption` (`none` \| `tls` \| `ssl`) / `smtp_user` / write-only `smtp_pass` (blank keeps the stored secret), **Send test email to admin_email**, last error (`mail_last_error`, also on **Tools → Site Health**). Nonce `ap_settings_mail`. When defined in `ap-config.php`, `AP_MAIL_FROM_NAME`, `AP_MAIL_FROM_EMAIL`, `AP_MAIL_TRANSPORT`, `AP_SMTP_HOST`, `AP_SMTP_PORT`, `AP_SMTP_ENCRYPTION`, `AP_SMTP_USER`, and `AP_SMTP_PASS` override these options (names documented as comments in `ap-config-sample.php`; never put a real password there). Outbound volume is rate-limited (`AP_Rate_Limit` action `mail`, default 20/hour per IP and per recipient; **no ACP screen**) — [security.md](security.md). |
| Modules | Modules (`options-modules.php`) | Independent toggles for Static Pages, Blog, and Forum. At least one must remain enabled. Related menus and front-end routes follow these switches. Nonce `ap_settings_modules`. |
| Writing | Writing (`options-writing.php`) | Blog module (403 when off). Default category, smilies, default comment status on new posts. |
| Reading | Reading (`options-reading.php`) | `show_on_front` (`posts` / `page`), `page_on_front`, `page_for_posts`, `posts_per_page`, `posts_per_rss`, `rss_use_excerpt`. |
| Discussion | Discussion (`options-discussion.php`) | Blog module (403 when off). Comment defaults, moderation, registration-required comments, auto-close, threading, avatars. |
| Media | Media Settings (`options-media.php`) | Thumbnail / medium / large sizes, crop, `uploads_use_yearmonth_folders`. |
| Permalinks | Permalinks (`options-permalink.php`) | `permalink_structure` (Plain `''`, Day and name `/%year%/%monthnum%/%day%/%postname%/`, Month and name, Numeric `/archives/%post_id%`, Post name `/%postname%/`, or custom), `category_base`, `tag_base`. Saving regenerates rewrite rules. Server `try_files` / `mod_rewrite`: [rewrites.md](rewrites.md). |
| Privacy | Privacy (`options-privacy.php`) | Public Privacy Policy page (`wp_page_for_privacy_policy`). Links to Export / Erase Personal Data. |
| Forums | Forums (`options-forums.php`) | Forum module (403 when off). Topics/posts per page, guest view/post, PMs, attachments (max size / allowed types), flood interval, approval, search, online, unread, signatures, spam blacklist / max links. Per-forum ACL is on **Forums → Edit**, not here. |
| Hall of Fame | Hall of Fame (`options-hall-of-fame.php`) | Voluntary domain handshake — [section below](#hall-of-fame-handshake). |

---

## Plugin zip installer

Canonical contract (package shape, helpers, max size, nonce, overwrite, hooks)
stays in [plugins.md](plugins.md#plugin-installer). This section is the
**operator pointer** for the ACP screen.

**Screen:** Plugins → Upload plugin (`plugins.php` POST `ap_plugin_action=upload`).

| Piece | As built |
|-------|----------|
| Screen cap | `activate_plugins` (to see Plugins) |
| Upload cap | `install_plugins` |
| Delete cap | `delete_plugins`, or `install_plugins` as a fallback |
| Class | `AP_Plugin_Installer` (`ap-includes/class-ap-plugin-installer.php`) |
| PHP | `ZipArchive` is required |
| Max size | `AP_Plugin_Installer::DEFAULT_MAX_BYTES` = **40 MiB** (also bounded by PHP `upload_max_filesize` / `post_max_size`; override `AP_MAX_PLUGIN_UPLOAD_BYTES` or `AP_MAX_UPLOAD_BYTES` in `ap-config.php`) |
| Nonce | `plugin-upload` |
| File field | `pluginzip` |
| Overwrite | checkbox `overwrite` |

The zip must contain a PHP file with a **Plugin Name** header. Installed
plugins stay **inactive** until you activate them. Active plugins cannot be
deleted. There is **no** CLI `plugin install` — [cli.md](cli.md). Theme zip
upload is a **separate** flow on Appearance → Themes (`AP_Theme_Installer`,
cap `install_themes`).

---

## Hall of Fame handshake

Hall of Fame is the **only** optional way to count installs. Core never phones
home during install or ordinary browsing. Join is an explicit administrator
action. `AP_Hall_Of_Fame::usesInstallerPings()` is always **false**.

**Screen:** Settings → Hall of Fame (`options-hall-of-fame.php`) · **Cap:**
`manage_options`

**Source:** `ap-includes/class-ap-hall-of-fame.php`

| Constant / option | As built |
|-------------------|----------|
| Endpoint | `https://agorapress.extrovertednerd.com/api/hall-of-fame` (`AP_Hall_Of_Fame::DEFAULT_ENDPOINT`). Override with `AP_HALL_OF_FAME_ENDPOINT` in `ap-config.php`. |
| Public page | `https://agorapress.extrovertednerd.com/hall-of-fame` (`PUBLIC_PAGE_URL`) |
| Status option | `hall_of_fame_status` = `joined` or empty |
| Domain option | `hall_of_fame_domain` (hostname only, no path; `www.` stripped; IPs rejected) |
| Token option | `hall_of_fame_token` (opaque withdrawal token) |
| Joined at | `hall_of_fame_joined_at` (ISO-8601 UTC) |
| Dismissed | `hall_of_fame_dismissed` = `1` hides the dashboard prompt |

### Join flow (file-proof)

1. Administrator clicks **Join the Hall of Fame** (nonce `hall-of-fame-join`).
   Domain defaults from `siteurl` / `home`.
2. This site POSTs `{action: "challenge", domain}` to the endpoint.
3. The project API returns a hex `challenge` (32–64 chars) and a proof
   `filename` matching `agorapress-hof-{hex}.txt`
   (`PROOF_FILENAME_PATTERN` = `agorapress-hof-[a-f0-9]{8,32}.txt`).
4. This site writes that file at the **site root** (`AP_ABSPATH`) with the
   challenge plus a trailing newline as the body, mode `0644`.
5. This site POSTs `{action: "verify", domain, challenge, proof_url}`. The
   project fetches the public file to confirm control of the domain.
6. This site **deletes** the proof file immediately after the verify attempt
   (success or failure).
7. On success, local options are set to joined. Payload never includes email,
   user id, site title, version, or environment data.

Leave (nonce `hall-of-fame-leave`) POSTs `{action: "leave", domain, token}`
and **always** clears local membership, even if the remote call fails (so the
admin can re-join). Dismiss on the dashboard sends **nothing**.

This is **not** telemetry, **not** `analytics_enabled`, and **not** the
`version.json` check. Installers never contact the project site.

---

## Donation / tip (never a paywall)

A subtle **Donate** link always appears in the admin footer
(`ap-admin/admin-footer.php`, class `ap-footer-donate`). URL:

`https://agorapress.extrovertednerd.com/donate`

(`AP_Hall_Of_Fame::DONATION_URL`)

The footer also says “free forever, no telemetry by default.” The Donate link
is permanent and non-optional — the constitution’s “price” for a
free-forever CMS — and **never** blocks features, screens, updates, or
modules. There is no toggle to hide it, and there is no paywall.

Administrators also see a Hall of Fame footer link to
`options-hall-of-fame.php`. Settings → Hall of Fame repeats the donation URL
as a button; that screen is still voluntary membership, not a store.

---

## Plugin-registered ACP pages

Canonical registration (`ap_register_admin_page()`, field list, WP shims,
`ap_admin_menu` / `admin_menu`, security notes) stays in
[plugins.md](plugins.md#admin-pages-settings-screens-in-the-acp). This section
is the **operator pointer**.

Allowed `parent` values: `settings`, `plugins`, `tools`, or empty (empty →
Plugins section). Default capability `manage_options`. Default position `50`.
First registration of an id wins (later duplicates return false).

Plugins must **not** expose raw PHP under `ap-content/plugins/**` as admin
endpoints. Register a page so it loads through the admin shell:
`/ap-admin/admin.php?page={id}` — allowlist only (`AP_Admin_Menu`). Unknown /
empty / path-like `?page=` → safe 404. Capability is checked on every render
(default `manage_options`).

---

## Capability cheat sheet

Caps below are the **screen** gates (`AP_Admin::screenCapabilities()` plus
post-type / meta-cap exceptions). Administrators have all of them. See
[roles.md](roles.md) for who else does.

| Area | Capability |
|------|------------|
| Enter ACP / Dashboard / Profile | `read` |
| Posts | `edit_posts` (row: `edit_post` / `delete_post`; publish: `publish_posts`) |
| Pages | `edit_pages` (row: `edit_page` / `delete_page`; publish: `publish_pages`) |
| Categories / tags | `manage_categories` |
| Comments list | `moderate_comments` (row edit: `edit_comment`) |
| Media | `upload_files` |
| Forums tree / edit / groups | `manage_forums` |
| Forum topics / moderation | `moderate_forums` |
| Themes list / activate | `switch_themes` (zip: `install_themes`) |
| Theme Options / Menus / Widgets | `edit_theme_options` |
| Plugins list / activate | `activate_plugins` (zip: `install_plugins`; delete: `delete_plugins`) |
| Users list | `list_users` |
| Add / edit users | `create_users` / `edit_users` |
| Most Settings | `manage_options` |
| Privacy | `manage_privacy_options` |
| Update Core | `update_core` |
| Import | `import` |
| Site Health | `view_site_health` |
| Analytics | `manage_options` |
| Export / Erase Personal Data | `export_others_personal_data` / `erase_others_personal_data` |
| Registered plugin pages | The `capability` stored on that `AP_Admin_Menu` row |

---

## Not in core

Do not tell operators these exist in `/ap-admin/`:

- Gutenberg / block editor / FSE theme editor
- Official plugin or theme marketplace / directory / “Add New” from a remote catalog
- Plugin or theme **file** editors
- Two-factor authentication / TOTP
- A Settings screen for `rest_api_enabled`, `version_check_enabled`, `blog_public`, `sitemap_enabled`, or `open_graph_enabled` (use [cli.md](cli.md))
- `php ap-cli core update` (apply is Tools → Update Core or a manual file deploy)
- A user self-service privacy portal (export/erase are administrator Tools)
- Telemetry collectors, `AP_TELEMETRY`, installer pings, or `usesInstallerPings()`
- A paywall, license key screen, or optional-hide for the footer Donate link

If a plugin registered an extra sidebar item via `ap_register_admin_page()`,
that page is **that plugin**, not core.

---

## Related docs

| Need | Doc |
|------|-----|
| Install, then first login | [install.md](install.md) |
| Pretty permalinks 404 | [rewrites.md](rewrites.md) · [troubleshooting.md](troubleshooting.md) |
| One-click core update | [updates.md](updates.md) |
| `php ap-cli …` | [cli.md](cli.md) |
| Forums in depth | [forums.md](forums.md) |
| Roles and caps | [roles.md](roles.md) |
| REST (`/ap-json/`, `rest_api_enabled`) | [rest.md](rest.md) |
| Nonces, deny rules, no telemetry | [security.md](security.md) |
| Plugin headers, Settings API | [plugins.md](plugins.md) |
| Plugin zip installer / `ap_register_admin_page` | [plugins.md](plugins.md#plugin-installer) · [plugins.md](plugins.md#admin-pages-settings-screens-in-the-acp) |
| Site Icon pack | [site-icon.md](site-icon.md) |
| Visual editor contract | [editor.md](editor.md) |
| Theme Options / Agora schemes | [themes.md](themes.md) |
| Tables / `AP_DB_VERSION` | [schema.md](schema.md) |
| Selected hooks (`ap_admin_menu`) | [hooks.md](hooks.md) |
| Trusted-agent rules | [bot_handbook.md](bot_handbook.md) |
| “Does this screen exist?” | [features_and_functions.md](features_and_functions.md) |
