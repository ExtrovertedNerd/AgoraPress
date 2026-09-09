# Troubleshooting

This is the **symptom → check** guide for AgoraPress **`0.3.6-beta`** (schema
`AP_DB_VERSION` **12**). It describes common failures **as built**. Start
here when a site misbehaves; follow the linked topic guide for depth.

This file does **not** invent a ticket system, remote log collector, or
host-control-panel “repair” button — those are **not in core**. Diagnose
from the public surfaces below. If a check is not in this guide and not in
the shipped code, say it is **not in core**.

**Source (as built):** `index.php`, `install/index.php`,
`ap-includes/class-ap-rewrite.php`, `ap-includes/class-ap-rest.php`,
`ap-includes/class-ap-options.php`, `ap-includes/class-ap-media.php`,
`ap-includes/class-ap-session.php`, `ap-includes/class-ap-site-health.php`,
`ap-includes/class-ap-user.php`, `ap-includes/class-ap-core-updater.php`,
`ap-includes/class-ap-rate-limit.php`, `ap-includes/class-ap-mail.php`,
`ap-includes/class-ap-smtp.php`, `ap-includes/class-ap-registration.php`,
`ap-includes/class-ap-forum.php`, `ap-includes/class-ap-forum-front.php`,
`ap-includes/class-ap-forum-permissions.php`, `ap-includes/class-ap-group.php`,
`ap-includes/functions.php` (`ap_handle_comment_form_post`),
`ap-admin/user-edit.php`, `ap-admin/admin-header.php`,
`ap-admin/login.php`, `ap-admin/options-mail.php`,
`ap-admin/options-modules.php`, `ap-admin/options-permalink.php`,
`ap-admin/forum-edit.php`, `ap-admin/forum-groups.php`,
`ap-admin/site-health.php`, `ap-includes/compatibility/`,
[`.htaccess`](../.htaccess),
[`docker/nginx.conf.example`](../docker/nginx.conf.example),
[`docker/apache-vhost.conf`](../docker/apache-vhost.conf).

Generic examples only (`example.com`, `localhost`, `admin@example.com`,
`noreply@example.com`, `smtp.example.com`, `/var/www/agorapress`).
Document **mechanisms**, not a private install.

---

## First: Tools → Site Health

Before guessing, run **Tools → Site Health** (`/ap-admin/site-health.php`,
cap `view_site_health`; `manage_options` is accepted as a fallback) or:

```bash
php ap-cli site health
```

That surface already checks PHP/extensions and writable paths (including
`ap-content/uploads/`), the database, schema vs `AP_DB_VERSION` (pending
migrations are **critical**), salts, `AP_DEBUG`, telemetry absence, HTTPS,
admin email, outbound mail (transport + stored last error; it does **not**
send a test message), privacy policy, modules, a cached core-update notice,
object and page cache drop-ins, autoloaded options, PHP memory, and disk
space. It does **not** probe `mod_rewrite` or nginx `try_files` — those are
host configuration.

---

## Symptom table

| Symptom | First check | Then |
|---------|-------------|------|
| Pretty URL 404 (`/slug/`, `/YYYY/MM/DD/slug/`) while `?p=` still works | Front controller missing: Apache `mod_rewrite` + shipped [`.htaccess`](../.htaccess), or nginx `try_files $uri $uri/ /index.php?$args` | [rewrites.md](rewrites.md). Flush only after the web server reaches `index.php`. If `/about/` shows the **home page**, permalinks are still **Plain**. |
| Web installer “Invalid security token”, or you bounce back to requirements after POST | PHP `session.save_path` not writable by the **php-fpm** (or Apache PHP) user | [security.md](security.md#php-sessionsave_path), [install.md](install.md#web-installer) |
| Media upload or Site Icon fails | `ap-content/uploads/` (and year/month subdirs) must be writable by PHP; Site Icon also needs **GD or Imagick** and a raster image | [install.md](install.md#permissions), [site-icon.md](site-icon.md) |
| Forum / blog / pages “missing” from menus or ACP | **Settings → Modules** (`options-modules.php`). Options `ap_module_static_pages`, `ap_module_blog`, `ap_module_forum` | At least one module must stay on. Front `/forums/` is an empty state, not a web-server 404. If **one** board is missing while `/forums/` works, that is ACL — [Group board invisible](#group-board-invisible). |
| Group board invisible (index / search / feed / sitemap / REST) or generic “You cannot view this.” | Per-forum ACL (`view_forum`), usually **This group only** (`group_only`) — not the Forum module toggle and not a rewrite 404 | [Group board invisible](#group-board-invisible), [forums.md](forums.md#this-group-only-group_only) |
| “Members only” but every logged-in user can see the board | **Members only** is all registered accounts. A named group uses **This group only** | [Group board invisible](#group-board-invisible) |
| Classic WP theme looks broken | Compat layer is for **classic PHP** themes. Block / FSE (`theme.json`, HTML under `templates/`) is out of scope | [compatibility.md](compatibility.md) |
| REST 404 on `/ap-json/` or `?rest_route=` | Front controller (pretty `/ap-json/…`) **and** option `rest_api_enabled` | Distinguish a web-server HTML 404 from JSON `rest_disabled` / `rest_no_route` / `rest_module_disabled`. [rest.md](rest.md) |
| Logged-in blog comments do not save, or ACP Edit User shows the admin instead of the selected account | Current core already has the 0.3.2 / 0.3.6 behaviour | Confirm you are on **0.3.6-beta**. See [Logged-in comments and Edit User](#logged-in-comments-and-edit-user). |
| Login rejected / “too many attempts” / “verify your email” | Rate limit (`rate_limited`) or `require_email_verification` — not a broken `session.save_path` | [Login fails](#login-fails), [security.md](security.md), [roles.md](roles.md) |
| Verification mail never arrives (reset / test too) | SMTP (or PHP `mail()`) on **Settings → Mail**; check the **spam** folder (new sending server); Site Health `mail_last_error` | [Mail not arriving](#mail-not-arriving) |
| Admin screens look “old schema” after a zip/rsync, or Update Core is greyed | `php ap-cli db check` then `php ap-cli db migrate`. Pre-flight: `version_check_enabled`, ZipArchive, writable root | [updates.md](updates.md) |

Each row is expanded below.

---

## Pretty permalink 404 (`?p=` still works)

**Symptom:** `/about/`, `/hello-world/`, or `/2026/08/03/hello-world/`
returns the web server’s 404 page. The same post still opens as
`https://example.com/?p=123` (pages: `?page_id=`).

**Cause:** pretty paths never reached `index.php`. AgoraPress only parses
the path after the web server sends unknown URLs to the front controller.
Flushing rewrite rules does **not** fix a missing `try_files` /
`mod_rewrite` hop.

| Host | What must be true |
|------|-------------------|
| **Apache** | Shipped [`.htaccess`](../.htaccess) in the document root, `mod_rewrite` enabled, vhost `AllowOverride All` (Compose image: [`docker/apache-vhost.conf`](../docker/apache-vhost.conf)). |
| **Nginx** | `try_files $uri $uri/ /index.php?$args;` in `location /` as in [`docker/nginx.conf.example`](../docker/nginx.conf.example). Nginx does **not** read `.htaccess`. |

Confirm:

1. Document root is the AgoraPress root (the folder that contains
   `index.php`, `ap-admin/`, `ap-includes/`, `ap-content/`, `install/`).
2. `/?p=123` still works — that proves PHP and the database are fine.
3. A real file (theme CSS, or a manual root `favicon.ico`) is **not**
   rewritten — `$uri` / `!-f` should serve it as a static file.
4. After the front controller works: **Settings → Permalinks → Save
   Changes**, or `php ap-cli rewrite flush`. That regenerates option
   `rewrite_rules`. It does **not** write `.htaccess` or nginx config.

Fresh installs seed **Plain** permalinks (empty `permalink_structure`).
Stay on Plain until the front controller is confirmed. With a working
front controller and Plain still on, `/about/` reaches `index.php` but
AgoraPress treats it as the **front page** (query-string vars only) — that
is **not** a 404. Turn a pretty structure on (and flush) before you expect
`/slug/` or `/YYYY/MM/DD/slug/` to resolve.

Details and the day-and-name vs `/slug/` page split:
[rewrites.md](rewrites.md).

`/sitemap.xml` and `/robots.txt` also need the front controller even when
pretty permalinks are off. Query-string forms `?sitemap=index` and
`?robots=1` still work.

One-click **Tools → Update Core** **does** copy root `.htaccess` from the
package. Custom rewrite edits there are overwritten; nginx config is
never written by core. [updates.md](updates.md).

There is **no** IIS `web.config`, Caddyfile, or Site Health check for
`try_files` in core.

---

## Installer CSRF / session will not persist

**Symptom:** the web installer (`/install/`) shows **“Invalid security
token. Please reload the page and try again.”** and **returns you to the
requirements step**, or the database step is blank after POST. CLI
install (`php install/cli.php`) is unaffected.

**Cause:** only `install/index.php` calls PHP `session_start()`. The wizard
stores CSRF (`$_SESSION['ap_install_csrf']`) and step state in **PHP
sessions**. PHP writes those files under `session.save_path` (php.ini /
pool config). That directory must be writable by the **same user that
runs PHP** (php-fpm or Apache).

A common host failure: the save path is a **`770` directory** whose group
does not include the php-fpm user. `session_start()` cannot persist.

This is a **host mechanism**, not an AgoraPress option.

Fix:

1. Learn the path as the PHP user, not as root:
   `php -i | grep session.save_path` under the site’s pool, or a one-line
   `session_start()` probe.
2. Make that directory owned/writable by the php-fpm (or Apache PHP) user.
   Do not make the tree world-writable as a shortcut.
3. Retry `/install/`.

`/ap-admin/` after install does **not** use PHP `$_SESSION` for CSRF
(HMAC `AP_Nonce` + signed cookies via `AP_Session`). Login nonce action is
`admin-login`. Fix the save path anyway for the installer and for any
plugin that uses native PHP sessions.

Depth: [security.md](security.md#php-sessionsave_path),
[install.md](install.md#web-installer).

---

## Uploads / Site Icon fail

**Symptom:** Media Library upload errors, or Settings → General → Site Icon
does not generate a favicon pack.

**Cause (permissions):** PHP cannot create or write
`ap-content/uploads/` (optionally `uploads/YYYY/MM/` when
`uploads_use_yearmonth_folders` is on). `AP_Media::uploadDir()` returns
`Unable to create upload directory.` or `Upload directory is not writable.`

The requirements checker treats these as **required**:

| Path | Need |
|------|------|
| Site root | PHP can create `ap-config.php` |
| `ap-content/` | Directory exists and is writable |
| `ap-content/uploads/` | Directory exists and is writable. Installer creates it (silent `index.php`) when missing if `ap-content/` is writable |

Typical Unix setup: directories owned by the PHP user, mode that user can
write. The installer does not chown the tree for you.

**Cause (Site Icon specifically):**

- Image editing needs **GD or Imagick**. Missing both:
  `Image editing requires the PHP GD or Imagick extension.`
- Source must be a **raster** image. SVG is rejected for the favicon pack
  (`Site icon must be a raster image.`). Ordinary media may still accept
  SVG after a strict scan.
- Option `site_icon` is an attachment ID (`0` = none). Derivatives live
  next to the original under `ap-content/uploads/`.

`ap-content/uploads/index.php` returns 403 so the directory is not a
script entry point. Media files are served as static files when they
exist. Confirm the web server is not rewriting existing upload files
through PHP.

Depth: [install.md](install.md#permissions), [site-icon.md](site-icon.md).

---

## Forum / blog / pages missing

**Symptom:** no Forums / Posts / Pages menu in ACP, forum screens 403, or
the public forum looks empty even though `?p=` works and the front
controller is fine.

**Cause:** the matching **module is off**. AgoraPress treats Static Pages,
Blog, and Forum as independent modules. ACP menus, some ACP screens, REST
resources, and fallback navigation follow the toggle. Pretty post/page
URLs are **not** rewritten away when a module is off — do not treat a
web-server 404 as a module problem until the front controller works
([Pretty permalink 404](#pretty-permalink-404-p-still-works)).

| Module | Option | Admin |
|--------|--------|--------|
| Static Pages | `ap_module_static_pages` | Settings → Modules (`options-modules.php`) |
| Blog | `ap_module_blog` | same |
| Forum | `ap_module_forum` | same |

What “off” looks like **as built**:

| Surface | Behaviour |
|---------|-----------|
| ACP sidebar | Matching items hidden (`AP_Admin::menuItems()`). |
| Forum ACP screens, Writing / Discussion, Settings → Forums | HTTP **403** `The Forum module is disabled. Enable it under Settings → Modules.` (Blog: `The Blog module is disabled…`). |
| Front `/forums/` (and other forum views) | Query flag `ap_forum_disabled`. Agora empty state **“The forum module is currently disabled.”** — **not** a hard 404 on the index. |
| Forum POST create/reply/like | Notice `The forum module is disabled.` |
| REST blog / pages / forums / topics | JSON **404** `rest_module_disabled` (route still registered). |
| Fallback primary nav | Published pages omitted when Static Pages is off; Forums link omitted when Forum is off. |
| Tables / topics / posts | **Not** dropped. Turn the module back on. |

Fresh install seeds all three **on**. `AP_Options::updateModules()`
refuses to turn **all** of them off — at least one must stay on.

```bash
php ap-cli option get ap_module_forum
php ap-cli option get ap_module_blog
php ap-cli option get ap_module_static_pages
php ap-cli option set ap_module_forum 1
```

There is **no** `php ap-cli module` verb. Use **Settings → Modules** or
`option get` / `option set`. Depth: [forums.md](forums.md#module-off),
[admin.md](admin.md).

If the Forum module is **on** and `/forums/` itself works, but **one**
board (or an empty parent category that only held that board) is missing,
that is **ACL**, not this toggle —
[Group board invisible](#group-board-invisible).

---

## Compat theme looks broken

**Symptom:** an uploaded WordPress theme renders a blank page, missing
templates, or unstyled HTML.

**Cause:** the Classic WordPress Theme Compatibility Layer
(`ap-includes/compatibility/`) shims **classic PHP** themes
(`style.css` + `index.php`). It is **not** Gutenberg / Full Site Editing.

| In scope | Out of scope |
|----------|----------------|
| Classic PHP themes, common template tags, hook name map | **Block / FSE** themes (`theme.json`, HTML under `templates/`) |
| Mode `auto` / `on` / `off` per slug (`ap_theme_compat_modes`) | Every WordPress function ever shipped |
| Conversion **report** (dry-run; does not rewrite the theme) | Plugins that assume a full WP core |

Detected block themes are **never auto-enabled**. Mode `on` can still load
shims for partial PHP support only — results are not guaranteed.

Default **Agora** is native and does not need WP shims (`auto` keeps them
off). If Agora itself looks wrong, that is a theme/CSS issue, not the
compat layer.

Read the limits before filing a bug: [compatibility.md](compatibility.md).

---

## REST 404

**Symptom:** `/ap-json/`, `/ap-json/ap/v1/posts`, or
`?rest_route=/ap/v1/posts` returns 404.

Split the 404 **kind** before changing permalinks.

| What you see | Meaning | Check |
|--------------|---------|--------|
| Web server HTML 404 (nginx/Apache default page) on `/ap-json/…` | Request never reached `index.php` | Same front-controller fix as pretty permalinks. `?rest_route=/ap/v1/posts` still works when `/` runs `index.php`. |
| JSON `{"code":"rest_disabled","message":"REST API is disabled.",…}` status **404** | Option `rest_api_enabled` is `0` (or filter `ap_rest_enabled` forced off) | `php ap-cli option get rest_api_enabled` — `php ap-cli option set rest_api_enabled 1` to enable. Fresh install default is **on**. |
| JSON `rest_no_route` | Path/method is not registered | Built-in namespace is `ap/v1`. Do not invent routes. |
| JSON `rest_module_disabled` | That resource’s module is off | Settings → Modules (see above). |
| JSON `rest_not_logged_in` status **401** | Write without credentials | Cookie session or HTTP Basic. |
| JSON `rest_cookie_invalid_nonce` status **403** | Cookie write missing/bad nonce | Header `X-AP-Nonce` (aliases `X-WP-Nonce`, body `_ap_nonce`) for action `ap_rest`. HTTP Basic skips the nonce. |

Pretty prefix: `/ap-json/` (`AP_Rest::URL_PREFIX`). PHP recognizes
`/ap-json/…` **even when pretty permalinks are off**, but the web server
still has to hand that path to `index.php`. Missing `try_files` /
`mod_rewrite` 404s `/ap-json/` the same way it 404s `/slug/`.

Plain equivalent (no rewriting required beyond running `index.php`):

```
https://example.com/?rest_route=/ap/v1/posts
```

Auth as built: session cookie + `X-AP-Nonce` for cookie writes, or HTTP
Basic. Depth: [rest.md](rest.md), [rewrites.md](rewrites.md),
[security.md](security.md).

---

## Logged-in comments and Edit User

These used to surprise operators. On **0.3.6-beta** the current behaviour
is:

### Logged-in blog comments (since 0.3.2-beta)

Logged-in visitors **can** post blog comments. The handler uses
`AP_User::getById()` / `ap_get_user_by('id', …)`. Guests were never on
that path.

| Query on redirect | Meaning |
|-------------------|---------|
| `comment_ok=1` | Saved and approved |
| `comment_ok=pending` | Saved; waiting on `comment_moderation` |
| `comment_ok=edited` / `comment_ok=deleted` | Own-comment edit / delete succeeded |
| `comment_error=server` | Handler threw; `index.php` redirects instead of failing silently |
| `comment_error=nonce` | HMAC failed (`ap-comment-post-{postId}`) |
| `comment_error=empty` | Empty body |
| `comment_error=identity` | Guest name/email required (`require_name_email`) |
| `comment_error=login` | `comment_registration` is on and the visitor is a guest |
| `comment_error=closed` | Comments closed / insert refused |
| `comment_error=forbidden` | Cap check failed on edit/delete |

`comment_moderation` is honored for logged-in users as well as guests.
Settings → Discussion. Ownership caps (since 0.3.3-beta):
`delete_own_comments` (Subscriber+), `edit_own_comments` (Author+);
moderators keep `moderate_comments`. See [roles.md](roles.md).

If comments still do not save on 0.3.6-beta, check the blog module, the
post’s comment status, rate limits, and the `comment_error=` token — not a
missing `AP_User::get()` method.

### ACP Edit User (since 0.3.6-beta)

**Users → Edit** (`user-edit.php?user_id=ID`) shows the **selected**
account. The header include stores the signed-in administrator as
`$ap_admin_user` so it does not overwrite the screen-local `$editUser`.

If the form shows the administrator’s login/email when you opened another
user, you are not on 0.3.6-beta. Check `AP_VERSION` (Dashboard, or
`php ap-cli version`) and update ([updates.md](updates.md)).

Do not treat older war stories as current product behaviour.

---

## Login fails

**Symptom:** `/ap-admin/login.php` rejects a known password, or the form
says too many attempts.

Login after install does **not** use PHP `$_SESSION`. The form nonce
action is `admin-login` (`AP_Nonce`). Success sets a signed auth cookie
(`AP_Session`: HMAC-SHA256, usermeta session tokens). A broken
`session.save_path` is the **installer** failure, not this one.

| What you see | Meaning | Check |
|--------------|---------|--------|
| `Too many failed login attempts. Please try again later.` (code `rate_limited`) | Transient-backed IP + identity lockout (`AP_Rate_Limit::checkLogin`) | Wait; there is **no** core unlock CLI. [security.md](security.md) |
| `Please verify your email address before logging in.` | Account `user_status` is pending and `require_email_verification` is on | Settings → General; confirmation mail. Fresh install seeds that option **on**. If the message never arrived: [Mail not arriving](#mail-not-arriving) (SMTP / spam, public `login.php?action=resend`). An administrator can **Activate** the account from Users (`users.php` row action or Users → Edit **Activate account**) without the email link. That path does not lift a forum ban. |
| `Invalid username or password.` | Credentials, or the account does not exist | Caps / roles: [roles.md](roles.md). |
| `Could not establish a session. Please try again.` | Signed cookie could not be set (`AP_Session::setAuthCookie` failed) | Browser cookies; `AP_LOGGED_IN_KEY` / `AP_LOGGED_IN_SALT` in `ap-config.php`. |
| `Security check failed. Please try again.` | Login form nonce failed | Reload the form; do not cache `login.php`. |

---

## Mail not arriving

**Symptom:** a **verification** message never arrives (same checks for
password-reset mail and the Settings → Mail test).

**First:** the transport on **Settings → Mail** (`php` or `smtp`), then the
recipient’s **spam** folder. Verification and reset bodies are text/plain
and already say the link expires in **24 hours** and to check spam — a
newly configured sending server is often untrusted.

Do **not** treat “check your email” as proof the message left the server.
If `require_email_verification` is on and `AP_Mail::send()` fails,
registration **keeps** the pending user and does **not** print that
success copy. The register screen says “Your account was created, but the
verification email could not be sent.” and switches to public
**Resend verification** (`login.php?action=resend`).

**Tools → Site Health** reports the configured transport (`php` or `smtp`)
and the stored last error (`mail_last_error`). That check does **not** send
a test message (Site Health never transmits data off-site).

1. Open **Settings → Mail** (`options-mail.php`).
2. Confirm From email (example `noreply@example.com`; this is not
   `admin_email`) and transport.
3. For SMTP: host (example `smtp.example.com`), port, encryption
   (`none` / `tls` / `ssl`), username. The password field is write-only.
   Native client (`AP_SMTP`, `stream_socket_client`): AUTH PLAIN / LOGIN,
   STARTTLS `tls` usually port 587, SMTPS `ssl` usually port 465.
4. Use **Send test email to admin_email**. That hits General `admin_email`,
   not From email. A failure is stored as `mail_last_error` and shown on
   that screen and on Site Health.
5. If last error is `Too many attempts. Please try again in …`, outbound
   mail hit the `mail` rate limit (IP + recipient, default 20/hour). Wait,
   or raise `rate_limit_mail_max` via `php ap-cli option set` —
   [security.md](security.md). There is **no** core unlock CLI.
6. Check the spam folder. The mailed body itself tells the recipient to
   do this when the sending server may be new.
7. When defined in `ap-config.php`, `AP_MAIL_FROM_NAME`,
   `AP_MAIL_FROM_EMAIL`, `AP_MAIL_TRANSPORT`, `AP_SMTP_HOST`,
   `AP_SMTP_PORT`, `AP_SMTP_ENCRYPTION`, `AP_SMTP_USER`, and `AP_SMTP_PASS`
   override the options table. `ap-config-sample.php` documents the names
   as comments — never a real password. There is **no** Reply-To constant.
8. For a pending account whose confirmation never arrived: public
   `login.php?action=resend`, or **Users → Edit** **Resend verification**
   and **Activate account**. The users list shows a **Pending** label and
   an **Activate** row action. Activate skips the emailed key; it does not
   lift a forum ban. Public resend success copy is generic (no account
   enumeration). A real `send()` failure is reported honestly.

PHP `mail()` delivery depends on the host MTA. There is **no** PHPMailer
in core, **no** HTML mail, and **no** comment-subscription mail. Depth:
[admin.md](admin.md#mail), [security.md](security.md#outbound-mail).

---

## Group board invisible

**Symptom:** one forum is missing from the board index, search, feeds,
sitemap, REST, or nav — or a known `/forums/{slug}/` / `/topic/{slug}/`
URL shows the generic **“You cannot view this.”** — while `/forums/`
itself still works and ACP **Forums** still lists the board.

**Cause:** per-forum ACL (`view_forum` on `{prefix}forum_permissions`),
usually the **This group only** preset (`group_only`). This is **not** the
Forum module being off, **not** a pretty-permalink 404, and **not**
`forum_status=hidden` by itself (hidden forums still need `view_forum`).

Split the “missing board” **kind** before toggling modules or flushing
rewrites:

| What you see | Meaning | Check |
|--------------|---------|--------|
| Agora empty state **“The forum module is currently disabled.”** on `/forums/` | Option `ap_module_forum` is off | [Forum / blog / pages missing](#forum--blog--pages-missing) |
| Web-server HTML 404 on `/forums/` or `/forums/{slug}/` | Front controller never ran | [Pretty permalink 404](#pretty-permalink-404-p-still-works) |
| Generic **“You cannot view this.”** (`AP_Forum_Front::CANNOT_VIEW_MESSAGE`; query `ap_forum_cannot_view` / `is_404`) | Viewer lacks `view_forum`. Response does **not** print the board name or slug | Forums → Edit access preset; named-group roster |
| JSON **404** `rest_cannot_view` / “You cannot view this.” | Same ACL on REST. Missing and unlistable share that code so clients cannot probe ids | [rest.md](rest.md) · [forums.md](forums.md#listing-hygiene-view_forum) |
| JSON **404** `rest_module_disabled` | Forum module off | Settings → Modules |
| Board listed in ACP, omitted on the public index / search / feed / sitemap / nav | Listing hygiene: anyone without `view_forum` does not see that forum, or an empty parent category that only held unlistable children | Expected for **This group only** |

**This group only** vs **Members only** (Forums → Edit,
`forum_access_level`):

| Preset | Who can `view_forum` |
|--------|----------------------|
| Members only (`members`) | **Every** logged-in account (virtual `registered`). Guests cannot. If you set this and “everyone logged in can see it”, that is the preset working. |
| This group only (`group_only`) | Chosen named (non-system) group(s) plus administrators (`manage_forums`). Guests and ordinary registered non-members cannot list or open it. Option `forum_group_only` maps `forum_id` → group ids (schema **12**; **no** Settings → Forums field). |

Create groups and add members under **Forums → Groups**
(`forum-groups.php`), then return to Forums → Edit and pick those groups
(`forum_access_groups[]`). Hidden named groups have **no public Join**
control — the operator is the roster. An empty picker still marks the
forum group-only; then only `manage_forums` can enter.

**Staff caveat:** `manage_forums` (Administrator) **always** allows and
still sees the board in ACP. Site-wide `moderate_forums` (Editor+) does
**not** walk into a `group_only` room unless that user is also in a chosen
named group.

Apply rules that surprise operators: do **not** stamp an explicit deny on
virtual `registered` (every member is also registered; deny-wins would lock
the chosen group out). Settings → Forums checkboxes
`forum_allow_guest_viewing` / `forum_allow_guest_posting` are **stored**;
`AP_Forum_Permissions::userCan()` does **not** read them. Toggling those
alone does not hide or open a board.

Direct unlistable URLs stay generic on pretty paths, scoped search, HTML
feed/sitemap 404s (never RSS/Atom/XML whose `<title>` would name the
room), and REST. Depth: [forums.md](forums.md#this-group-only-group_only),
[roles.md](roles.md#forum-acl-relationship).

---

## Update Core / pending schema

**Symptom:** ACP looks like an older schema after a zip extract, git pull,
or rsync; Site Health flags pending migrations as **critical**; or
**Tools → Update Core** is greyed / pre-flight fails.

```bash
php ap-cli db check
php ap-cli db migrate
php ap-cli core check-update
```

One-click apply is **Tools → Update Core** only. There is **no**
`php ap-cli core update` (apply) verb.

| What you see | Check |
|--------------|--------|
| Site Health “pending migration(s)” | `php ap-cli db migrate`. Target is `AP_DB_VERSION` **12**. [schema.md](schema.md) |
| `Files were updated but database migration failed: …` | Files already on the new tree; finish with `db migrate` (no automatic file rollback). |
| Front-end 503 “Site briefly unavailable” / “AgoraPress is installing an update.” | Live `.maintenance` lock. Older than **30 minutes** is ignored. |
| Pre-flight greyed | `version_check_enabled` (default on), outbound HTTP GET of public `version.json` (**no site identity**), writable site root, PHP `ZipArchive`, cURL or `allow_url_fopen`, writable temp dir. |

Depth: [updates.md](updates.md).

---

## Also useful

| Symptom | Check |
|---------|--------|
| White screen / PHP fatals on a public host | `AP_DEBUG` / `AP_DEBUG_DISPLAY` / `AP_DEBUG_LOG` in `ap-config.php` must stay **false** in production. Site Health flags debug on. Staging only. With `AP_DEBUG` + `AP_DEBUG_LOG`, PHP logs to `ap-content/debug.log`. |
| Installer cannot write config | Site root must be writable so PHP can **create** `ap-config.php`. [install.md](install.md#permissions) |
| REST cookie POST/PUT/DELETE 403 | `rest_cookie_invalid_nonce` — `X-AP-Nonce` for action `ap_rest`. |

---

## What is not in core

Do not invent these while diagnosing:

- Gutenberg / Full Site Editing, block themes on the compat layer
- Official SaaS, paid marketplace, telemetry, PHP older than 8.2
- IIS `web.config`, Caddy, Traefik, or a PHP built-in-server router
- `php ap-cli core update` (apply) — check is `core check-update`; apply is
  Tools → Update Core or a manual file deploy
- `php ap-cli module …`
- Two-factor authentication, a bundled WAF, or Fail2ban
- A user self-service privacy portal
- PHPMailer, HTML mail, newsletters, or comment-subscription mail
- A public group Join button on default Agora (Forums → Groups is the roster)
- Forum ACL applied to blog posts or static pages
- Host-specific pool names, private vhosts, or live fleet inventory

If the surface is missing from these guides and from the shipped tree, it
is **not in core**.

---

## Related documentation

| Doc | Why |
|-----|-----|
| [README.md](README.md) | Docs index (humans + trusted agent) |
| [install.md](install.md) | Permissions, installer CSRF, post-install |
| [rewrites.md](rewrites.md) | Front controller, `try_files`, `?p=` vs pretty |
| [updates.md](updates.md) | `version.json`, Update Core, `db migrate` |
| [cli.md](cli.md) | Built-in `ap-cli` groups, flags, exit codes |
| [admin.md](admin.md) | `/ap-admin/` screens including Site Health |
| [forums.md](forums.md) | Forum module, **This group only**, listing hygiene |
| [roles.md](roles.md) | Caps, comment ownership |
| [rest.md](rest.md) | `/ap-json/`, `ap/v1`, `rest_api_enabled` |
| [security.md](security.md) | Sessions, nonces, deny rules |
| [site-icon.md](site-icon.md) | Favicon pack, GD/Imagick |
| [compatibility.md](compatibility.md) | Classic PHP themes; block/FSE out of scope |
| [schema.md](schema.md) | Pending migrations / “old schema” after update |
| [plugins.md](plugins.md) · [themes.md](themes.md) | Zip installers, drop-in paths |
| [hooks.md](hooks.md) | Selected actions/filters (grep for the rest) |
| [bot_handbook.md](bot_handbook.md) | Trusted-agent operating model |

*AgoraPress — free forever. Publish. Discuss. Own your stack.*
