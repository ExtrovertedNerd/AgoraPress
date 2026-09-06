# Security and privacy

This is the **hardening and privacy guide** for AgoraPress **`0.3.6-beta`**
(schema `AP_DB_VERSION` **12**). It describes the security model **as built**:
PDO prepared statements, HMAC nonces, Argon2id passwords, rate limits,
production deny rules, no telemetry, local opt-in analytics, voluntary Hall of
Fame, and GDPR-style privacy export / erase.

The compact landing-page bullets are in
[`../README.md`](../README.md#security--privacy). This file is the operator
depth guide. It does **not** invent two-factor authentication, a WAF, or a
core Content-Security-Policy — those are **not in core**.

**Source (as built):** `ap-includes/class-ap-db.php`,
`ap-includes/class-ap-nonce.php`, `ap-includes/class-ap-user.php`,
`ap-includes/class-ap-session.php`, `ap-includes/class-ap-rate-limit.php`,
`ap-includes/class-ap-formatting.php`, `ap-includes/class-ap-privacy.php`,
`ap-includes/class-ap-version-check.php`, `ap-includes/class-ap-hall-of-fame.php`,
`ap-includes/class-ap-analytics.php`, `ap-includes/class-ap-media.php`,
`ap-includes/class-ap-site-health.php`, `ap-includes/class-ap-rest.php`,
`install/index.php`, `ap-admin/login.php`, `ap-admin/options-privacy.php`,
`ap-admin/export-personal-data.php`, `ap-admin/erase-personal-data.php`,
[`.htaccess`](../.htaccess), [`ap-content/.htaccess`](../ap-content/.htaccess),
[`docker/nginx.conf.example`](../docker/nginx.conf.example),
[`docker/apache-vhost.conf`](../docker/apache-vhost.conf),
`ap-config-sample.php`.

---

## What core does (and does not)

| In core | Not in core |
|---------|-------------|
| PDO prepared statements only (`AP_DB`) | Query builders that interpolate untrusted SQL |
| HMAC nonces on state-changing forms and REST cookie writes | PHP `$_SESSION` CSRF for `/ap-admin/` (ACP uses HMAC) |
| Argon2id password hashes (`PASSWORD_ARGON2ID` when PHP has it) | Optional 2FA / TOTP |
| Transient-backed rate limits (login, register, password reset, upload) | Fail2ban, IP firewalls, or a bundled WAF |
| Capability checks on admin screens and privileged APIs | A second permission system besides [roles.md](roles.md) / forum ACL |
| Escape on output / sanitize on input (`AP_Formatting`) | “Trusted HTML from the database” as a security model |
| Deny rules in shipped Apache / Nginx examples | IIS `web.config`, Caddy, or PHP built-in-server deny files |
| **No telemetry** — no `AP_TELEMETRY` constant, flag, or option | Phone-home install counts or anonymous usage pings |
| Local analytics only, option `analytics_enabled` default **off** | Third-party pixels, beacons, or analytics endpoints |
| Voluntary Hall of Fame (domain listing, withdrawable) | Automatic domain registration at install |
| Tools → Export / Erase Personal Data | A user self-service privacy portal |
| Site Health (salts, debug, HTTPS, telemetry absence) | Automatic Let’s Encrypt / HSTS / CSP headers |

HTTPS is **operator-provided**. Site Health recommends it. The shipped Nginx
example comments TLS lines; it does not issue certificates.

---

## Prepared statements

`AP_DB` is a PDO layer. Every value-bearing query goes through
`PDOStatement::prepare()` + bound parameters (`?` or named placeholders).
Native prepares are on (`PDO::ATTR_EMULATE_PREPARES` = `false`).

Helpers `insert()`, `update()`, and `delete()` bind **values**. Table and
column names are **identifiers**: they must match
`/^[A-Za-z_][A-Za-z0-9_]*$/` (`AP_DB::isSafeIdentifier()`) before they are
quoted for the driver (backticks on MySQL, double quotes on SQLite /
PostgreSQL). Do not concatenate request data into SQL.

Drivers: MySQL 8+ / MariaDB 10.6+ (production), SQLite (demos), PostgreSQL.
See [schema.md](schema.md).

There is **no** `$apdb->query()` path that runs interpolated user SQL. Plugins
that talk to the database must use the same bound-parameter contract.

---

## Nonces (CSRF)

`AP_Nonce` issues HMAC-SHA256 tokens of `tick|action|user_id` using
`AP_NONCE_KEY` + `AP_NONCE_SALT` from `ap-config.php`. One tick is **12 hours**
(`AP_Nonce::TICK_SECONDS`). A token is valid for the **current or previous**
tick so a form submitted near a boundary still verifies (`hash_equals`).

| Helper | Use |
|--------|-----|
| `ap_create_nonce( $action )` / `AP_Nonce::create()` | Token string |
| `ap_nonce_field( $action )` / `AP_Nonce::field()` | Hidden input, default name `_ap_nonce` (optional `_ap_http_referer`) |
| `ap_check_nonce( $nonce, $action )` / `AP_Nonce::check()` | Boolean verify |
| `AP_Nonce::url( $url, $action )` | Append `_ap_nonce` to a GET state-changing link |
| `AP_Nonce::verifyRequest( $request, $action )` | Read `_ap_nonce` (or `_wpnonce` alias) from a request bag |

Admin POSTs check a **named action** (examples as built: `admin-login`,
`log-out`, `ap_options_privacy`, `export-personal-data`,
`erase-personal-data`). Logout is nonce-protected so a third-party page cannot
force a sign-out.

**REST** cookie-authenticated writes need header `X-AP-Nonce` (or body
`_ap_nonce` / `_wpnonce`) for action `ap_rest`. HTTP Basic skips the nonce
because the credentials are the proof. Details: [rest.md](rest.md).

Changing `AP_NONCE_KEY` / `AP_NONCE_SALT` after install invalidates outstanding
nonces (and, with the logged-in pair, cookies). Site Health flags missing or
placeholder salts as **critical**.

### Web installer CSRF (PHP sessions)

The **web installer** (`install/index.php`) is a different path. It has not
written `ap-config.php` yet, so it cannot use HMAC salts. Each wizard POST
carries `ap_csrf` compared with `hash_equals` against
`$_SESSION['ap_install_csrf']`. `session_start()` uses `cookie_httponly`,
`cookie_samesite=Lax`, `use_strict_mode`. CLI install (`php install/cli.php`)
does not use PHP sessions.

If PHP cannot persist that session, the installer fails the security token
check or loses the database step. See [PHP `session.save_path`](#php-sessionsave_path)
below.

`/ap-admin/` does **not** use PHP `$_SESSION` for CSRF. After install, ACP
forms use `AP_Nonce`.

---

## Passwords (Argon2id)

`AP_User::hashPassword()` uses **`PASSWORD_ARGON2ID`** when the PHP runtime
defines it, otherwise `PASSWORD_DEFAULT`. Verify is `password_verify()`. Empty
passwords never match.

On a successful login, a stored hash is **rehashed** when
`password_needs_rehash()` says the algorithm or cost is outdated (transparent
upgrade). Missing-account checks still run `password_verify()` against a dummy
hash so failure cost is similar to a wrong password.

Password changes revoke **all** stored session tokens (`AP_Session`). Inactive
accounts (`user_status !== 0`, including pending email verification) cannot
authenticate.

**Optional 2FA is not in core.**

---

## Auth cookies and rate limits

Logged-in state is a signed cookie plus a server-side session token — not PHP
`$_SESSION`.

| Piece | As built |
|-------|----------|
| Cookie name | `ap_logged_in_` + 12 hex chars of a hash of `AP_LOGGED_IN_KEY` + `AP_LOGGED_IN_SALT` |
| Cookie value | `user_id\|expiration\|token\|hmac` (HMAC-SHA256; payload also binds login + a password-hash fragment) |
| Flags | `HttpOnly`, `SameSite=Lax`, `Secure` when the request is HTTPS (or `X-Forwarded-Proto: https`) |
| Lifetime | 2 days default; **14 days** with “remember me” |
| Server token | Random 32-byte hex, **hashed** in usermeta key `session_tokens` (cap 50 sessions / user) |

Logout destroys the current token and expires the cookie. A password change
invalidates cookies even before token cleanup (password fragment in the HMAC).

`AP_Session::login()` applies **rate limits** (IP + identity) before
`AP_User::authenticate()`. Failed attempts increment both buckets; success
clears them. Identity and IP keys are hashed so raw emails do not sit in
transient names.

Default windows (`AP_Rate_Limit`; override with options
`rate_limit_{action}_max` / `_window` / `_lockout`):

| Action | Max | Window | Lockout |
|--------|-----|--------|---------|
| `login` | 5 | 15 min | 15 min |
| `register` | 5 | 1 h | 1 h |
| `password_reset` | 5 | 1 h | 30 min |
| `upload` | 40 | 10 min | 5 min |

Client IP is `REMOTE_ADDR` by default (not spoofable without a proxy). Only if
you **define `AP_TRUST_PROXY` true** in `ap-config.php` (not in the sample
file — add it yourself behind a trusted reverse proxy) does the limiter also
read `X-Forwarded-For` / `X-Real-IP`. Do not set that flag on a host that
accepts those headers from the public internet.

Registration is off by default (`users_can_register` = `0`). Optional math
CAPTCHA (`registration_captcha`) and email verification are additional
anti-spam, not a second password factor. Forum flood / spam guards
(`forum_flood_interval` default 30 s) are documented with forums
([forums.md](forums.md)).

---

## Capability checks

Privileged admin screens call `AP_Admin::requireLogin()` and a capability
(`AP_Admin::requireCapability()` / `ap_current_user_can()`). Examples as
built:

| Screen | Primary cap (fallbacks in code) |
|--------|----------------------------------|
| Settings → Privacy | `manage_privacy_options` (`manage_options`) |
| Tools → Export Personal Data | `export_others_personal_data` (`manage_options`, `export`) |
| Tools → Erase Personal Data | `erase_others_personal_data` (`manage_options`, `delete_users`) |
| Tools → Update Core | `update_core` |
| Tools → Site Health / Analytics | `manage_options` |

Roles, comment ownership caps, and forum ACL: [roles.md](roles.md). Do not
invent extra roles.

---

## Escaping and sanitization

`AP_Formatting` (wrappers `ap_esc_html`, `ap_esc_attr`, `ap_esc_url`,
`ap_sanitize_text_field`, …):

- **Escape on output** by context (HTML body, attributes, URLs, JS, XML).
- **Sanitize on input** for storage / comparison.
- URL helpers reject `javascript:`, `data:`, `vbscript:` and other schemes
  outside the allowed list (`http`, `https`, `mailto`, `ftp`, `ftps`, `tel`,
  `sms`, filterable via `ap_allowed_protocols`).

Never treat database HTML as trusted. Themes and plugins must escape at the
template.

---

## Production deny rules

Pretty permalinks still need a front controller ([rewrites.md](rewrites.md)).
The same shipped files **deny secrets and raw core PHP**.

### Apache (shipped [`.htaccess`](../.htaccess))

| Rule | Effect |
|------|--------|
| `FilesMatch` `ap-config.php`, `composer.json` / `composer.lock`, `.env` | `Require all denied` |
| `FilesMatch` `*.sqlite` / `*.sqlite3` / `*.db` | Never serve DB files (demo path `ap-content/database.sqlite`) |
| `RewriteRule ^ap-includes/(css\|js)/` | Public editor CSS/JS stay reachable |
| `RewriteRule ^ap-includes/` `[F,L]` | Direct HTTP to core PHP / schema is **forbidden** |
| Existing files / dirs | Not rewritten (`favicon.ico`, uploads, `/ap-admin/`, `/install/`) |

[`ap-content/.htaccess`](../ap-content/.htaccess) turns **indexes off** and
repeats the SQLite / `.db` deny (runtime data if rewrite is bypassed).
[`docker/apache-vhost.conf`](../docker/apache-vhost.conf) repeats the secrets
`FilesMatch` for the Docker Apache image.

### Nginx (shipped [`docker/nginx.conf.example`](../docker/nginx.conf.example))

Copy the deny blocks into the live server, then adjust `root`, `server_name`,
TLS, and the php-fpm socket. Generic `root` in that file is
`/var/www/agorapress`; generic `server_name` is `example.com`.

| Location | Effect |
|----------|--------|
| `~* ^/(ap-config\.php\|composer\.(json\|lock)\|\.env)` | `deny all` |
| `~* \.(sqlite3?\|db)$` | `deny all` |
| `^~ /ap-includes/css/` and `…/js/` | `try_files $uri =404` (public assets) |
| `^~ /ap-includes/` | `deny all` (raw PHP / schema) |
| `~ /\.` | `deny all` (dotfiles) |
| `location /` | `try_files $uri $uri/ /index.php?$args` |

If you skip these denies, `ap-config.php` (credentials + salts) and a SQLite
file under `ap-content/` can be downloaded as static files. That is a host
misconfiguration, not an AgoraPress setting.

**Never commit `ap-config.php`.** It is gitignored. Rotate salts if it leaked.

---

## PHP `session.save_path`

This is a **host mechanism**, not an AgoraPress option.

The web installer stores CSRF + wizard state in PHP sessions. PHP writes those
files under `session.save_path` (php.ini / pool config). That directory must
be **writable by the php-fpm user** (or the Apache PHP user).

A common failure: the save path is a **`770` directory** whose group does not
include the php-fpm user. `session_start()` cannot persist. Symptoms:

- Web installer: “security token” / CSRF failure, or the database step is
  forgotten after POST.
- Any PHP that auto-starts sessions: warnings on every request.

Fix the path (or its ownership / mode) so the **same user that runs PHP** can
create files there. Do not make the tree world-writable as a shortcut. Confirm
with `php -i | grep session.save_path` as that user, or a one-line
`session_start()` probe under the site’s php-fpm pool — not as root.

ACP login after install does **not** depend on this path (signed cookies).
Fix it anyway for the installer and for any plugin that uses native PHP
sessions.

Permissions for `ap-config.php` / `ap-content/` / `uploads/`:
[install.md](install.md#permissions).

---

## No telemetry

There is **no** `AP_TELEMETRY` constant, flag, or option. Config sample,
installer, and Site Health all treat telemetry as unused. Do not add
phone-home behaviour in core-adjacent plugins without an explicit opt-in.

Network egress from **core** is limited to three paths:

### 1. Version check (admin, optional)

Plain **HTTP GET** of public
`https://agorapress.extrovertednerd.com/version.json`.
User-Agent: `AgoraPress/{AP_VERSION} (VersionCheck; no-site-id)`.
No site URL, domain, or admin email in query or headers.

Option `version_check_enabled` (installer default `1`). There is **no**
Settings screen for it — use CLI (`php ap-cli option set version_check_enabled 0`)
or filter `ap_version_check_enabled`. See [updates.md](updates.md#privacy-no-site-identity).

### 2. Core package download (admin-initiated)

One-click **Tools → Update Core** GETs the published zip. User-Agent:
`AgoraPress/{AP_VERSION} (CoreUpdater; no-site-id)`. Same no-identity rule.

### 3. Hall of Fame (voluntary only)

**Settings → Hall of Fame** (`ap-admin/options-hall-of-fame.php`, cap
`manage_options`). Never runs at install. Join is a domain-control **file
handshake**: the project API issues a code, this site writes a short-lived
public `agorapress-hof-*.txt`, the project fetches it, this site deletes it.
Payload is **domain + action / token / challenge** — never user identity,
email, or diagnostics. Leave / dismiss are explicit. Public pages:

- `https://agorapress.extrovertednerd.com/hall-of-fame`
- Donation / tip (admin footer, unobtrusive, never a paywall):
  `https://agorapress.extrovertednerd.com/donate`

Site Health’s telemetry check always reports **good**: core does not include
collectors.

---

## Local analytics (opt-in)

Option **`analytics_enabled`** defaults to **`0`**. When an administrator
turns it on (Tools → Analytics), `AP_Analytics` records **public GET/HEAD**
pageviews in the site database (`analytics_hits` / `analytics_daily`). No
front-end JS, no third-party scripts, no external endpoints.

Skipped by default: CLI, `/ap-admin/`, feeds, REST (`/ap-json/`), sitemaps,
robots.txt, coarse bot UAs, logged-in `manage_options` users, `DNT: 1`.
Retention prune (`analytics_retention_days`, default 90) still runs after you
turn collection off.

This is **not** Hall of Fame and **not** version-check traffic.

---

## Privacy export / erase

| Screen | Path | Cap |
|--------|------|-----|
| Settings → Privacy | `/ap-admin/options-privacy.php` | `manage_privacy_options` |
| Tools → Export Personal Data | `/ap-admin/export-personal-data.php` | `export_others_personal_data` |
| Tools → Erase Personal Data | `/ap-admin/erase-personal-data.php` | `erase_others_personal_data` |

**Privacy policy page** is option `wp_page_for_privacy_policy` (a published
page ID, `0` = none). Site Health recommends setting one.

**Export** looks up a user by ID, login, or email, previews groups, then
downloads JSON (`agorapress-personal-data-…json`,
`X-Content-Type-Options: nosniff`). Groups as built: user profile, usermeta,
posts/pages, comments, media, forum topics / posts, private messages, group
memberships, moderation records. Plugins may merge extra groups with filter
`ap_privacy_export_data`. **Never exported:** `session_tokens`,
`ap_session_tokens`, `ap_password_reset`, `ap_activation_key`.

**Erase** anonymizes content then **deletes the account**. You must type
`erase` in the confirmation field. Guards as built:

- You cannot erase **your own** account from this screen.
- You cannot erase the **sole administrator**.
- Optional reassign target for posts/pages/attachments (else author `0`).

What erase does (content kept for site integrity):

| Store | Action |
|-------|--------|
| Posts / pages / attachments | Reassigned |
| Comments | Author name “Deleted User”; email / URL / IP cleared |
| Forum topics / posts | Poster identity detached; IP scrubbed |
| Private messages | Hard-deleted both sides |
| Group memberships, unread tracks, online rows, bans / warnings | Removed |
| Account + usermeta | Deleted |

Plugins scrub extra stores on filter `ap_privacy_erase_data` (before account
delete). Actions: `ap_privacy_personal_data_exported` /
`ap_privacy_personal_data_erased`.

There is **no** front-end “download my data” form in core. Operators run the
Tools screens.

---

## Uploads

Media lives under `ap-content/uploads/` (optionally `YYYY/MM/`). `AP_Media`:

- Extension / MIME **allow-list** (not a deny-list).
- `is_uploaded_file()` unless a test harness.
- Size cap: min of core default (16 MiB) and PHP `upload_max_filesize` /
  `post_max_size`.
- Upload action is rate-limited (table above).
- `ap-content/uploads/index.php` returns **403** (not a script entry point).
- `AP_Media::ensureProtectionFiles()` writes an uploads `.htaccess` that
  allows static media, disables indexes, turns the PHP engine off when
  `mod_php` is in use, and denies `php` / `phtml` / `phar` / common script
  extensions.

Nginx should not `fastcgi_pass` PHP under `uploads/` (restrict PHP to known
entry points when you can — the example file comments that). Site Icon uses
the same library ([site-icon.md](site-icon.md)).

---

## Site Health and debug

**Tools → Site Health** (`ap-admin/site-health.php`) never transmits check
results off-site. Version-check info appears only if already cached (no forced
network). Security-relevant checks as built:

| Check | Good looks like |
|-------|-----------------|
| Authentication keys & salts | All eight `AP_*_KEY` / `AP_*_SALT` constants defined and not the sample placeholder |
| Debug mode | `AP_DEBUG` off |
| Telemetry | Always good (collectors do not exist) |
| HTTPS | `siteurl` is `https://` |
| Privacy policy | A published page is selected |

Leave `AP_DEBUG`, `AP_DEBUG_DISPLAY`, and `AP_SAVEQUERIES` **false** on
production (`ap-config-sample.php`). Displaying errors leaks paths.

REST master switch: option `rest_api_enabled` (default on). Set `0` to disable
`/ap-json/` ([rest.md](rest.md)).

---

## Operator checklist

1. Unique salts in `ap-config.php` (installer fills them; never leave
   `put your unique phrase here`).
2. `ap-config.php` unreadable over HTTP — shipped deny rules actually in
   effect (Apache `AllowOverride All` / Nginx deny `location`s).
3. PHP `session.save_path` writable by the php-fpm user (installer CSRF).
4. `ap-content/` and `uploads/` writable by PHP, not world-writable.
5. TLS at the reverse proxy; Site Health HTTPS check green.
6. `AP_DEBUG` off on the public site.
7. Registration off unless you intend it; review rate-limit defaults.
8. Analytics stays off unless you opt in. Hall of Fame stays opt-in.
9. Set a privacy policy page. Use Tools → Export / Erase when a data subject
   asks — there is no self-service portal in core.
10. Run **Tools → Site Health** after install and after host changes.

---

## Related

- [install.md](install.md) — permissions, installer CSRF, post-install
- [rewrites.md](rewrites.md) — front controller; deny rules live in the same files
- [updates.md](updates.md) — version check / updater, no site identity
- [roles.md](roles.md) — capabilities
- [rest.md](rest.md) — `X-AP-Nonce`, Basic, `rest_api_enabled`
- [admin.md](admin.md) — ACP screens including Hall of Fame and privacy tools
- [vision-compliance.md](vision-compliance.md) — privacy principles checklist
- [troubleshooting.md](troubleshooting.md) — installer CSRF / session symptoms
