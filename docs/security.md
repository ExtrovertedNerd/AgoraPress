# Security and privacy

This is the **hardening and privacy guide** for AgoraPress **`0.3.13-beta`**
(schema `AP_DB_VERSION` **14**). It describes the security model **as built**:
PDO prepared statements, HMAC nonces, Argon2id passwords, rate limits,
`php` / `smtp` outbound mail, a first-party public-register gate, reserved
logins as **anti-squat** (not 2FA), production deny rules, no telemetry,
local opt-in analytics, voluntary Hall of Fame, and GDPR-style privacy
export / erase.

The compact landing-page bullets are in
[`../README.md`](../README.md#security--privacy). This file is the operator
depth guide. It does **not** invent two-factor authentication, a WAF, a
core Content-Security-Policy, PHPMailer, or third-party CAPTCHA widgets —
those are **not in core**.

**Source (as built):** `ap-includes/class-ap-db.php`,
`ap-includes/class-ap-nonce.php`, `ap-includes/class-ap-user.php`,
`ap-includes/class-ap-session.php`, `ap-includes/class-ap-rate-limit.php`,
`ap-includes/class-ap-formatting.php`, `ap-includes/class-ap-privacy.php`,
`ap-includes/class-ap-version-check.php`, `ap-includes/class-ap-hall-of-fame.php`,
`ap-includes/class-ap-analytics.php`, `ap-includes/class-ap-media.php`,
`ap-includes/class-ap-mail.php`, `ap-includes/class-ap-smtp.php`,
`ap-includes/class-ap-registration.php`,
`ap-includes/class-ap-account-notice.php`, `ap-includes/class-ap-activity-log.php`,
`ap-includes/class-ap-site-health.php`, `ap-includes/class-ap-rest.php`,
`install/index.php`, `ap-admin/login.php`, `ap-admin/profile.php`,
`ap-admin/user-edit.php`, `ap-admin/includes/class-ap-admin-user-edit.php`,
`ap-admin/options-mail.php`,
`ap-admin/options-general.php`, `ap-admin/js/register-guard.js`,
`ap-admin/options-privacy.php`,
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
| HMAC nonces on state-changing ACP forms and REST cookie writes | PHP `$_SESSION` CSRF after install (only the web installer uses `$_SESSION`) |
| Argon2id password hashes (`PASSWORD_ARGON2ID` when PHP has it) | Optional 2FA / TOTP |
| Native outbound mail (`php` / `smtp` via `AP_Mail::send()`) | PHPMailer, Composer mail libraries, HTML newsletters |
| Always-on public-register gate + first-party `math` / `guard` | Google reCAPTCHA, hCaptcha, or Turnstile in core |
| Reserved public-register logins (**anti-squat**, not a second factor) | Treating those names as 2FA / TOTP |
| Transient-backed rate limits (login, register, password reset, current-password checks, upload, outbound mail) | Fail2ban, IP firewalls, or a bundled WAF |
| Capability checks on admin screens and privileged APIs | A second permission system besides [roles.md](roles.md) / forum ACL |
| Escape on output / sanitize on input (`AP_Formatting`) | “Trusted HTML from the database” as a security model |
| Deny rules in shipped Apache / Nginx examples | IIS `web.config`, Caddy, or PHP built-in-server deny files |
| **No telemetry** — no `AP_TELEMETRY` constant, flag, or option | Phone-home install counts or anonymous usage pings |
| Local analytics only, option `analytics_enabled` default **off** | Third-party pixels, beacons, or analytics endpoints |
| Voluntary Hall of Fame (domain listing, withdrawable) | Automatic domain registration at install |
| Tools → Export / Erase Personal Data | A user self-service privacy portal |
| Site Health (salts, debug, HTTPS, telemetry absence, outbound mail) | Automatic Let’s Encrypt / HSTS / CSP headers |

HTTPS is **operator-provided**. Site Health recommends it. The shipped Nginx
example comments TLS lines; it does not issue certificates.

---

## Prepared statements

`AP_DB` is a PDO layer. Native prepares are on
(`PDO::ATTR_EMULATE_PREPARES` = `false`). `$apdb->query( $sql, $params )`
always `prepare()`s, then binds `$params` (`?` or named placeholders).
Helpers `insert()`, `update()`, and `delete()` bind **values** the same way.
Table and column names are **identifiers**: they must match
`/^[A-Za-z_][A-Za-z0-9_]*$/` (`AP_DB::isSafeIdentifier()`) before they are
quoted for the driver (backticks on MySQL, double quotes on SQLite /
PostgreSQL).

There is no helper that interpolates untrusted values into SQL. Plugins must
put every value in `$params` and never concatenate request data into `$sql`.

Drivers: MySQL 8+ / MariaDB 10.6+ (production), SQLite (demos), PostgreSQL.
See [schema.md](schema.md).

---

## Nonces (CSRF)

`AP_Nonce` issues HMAC-SHA256 tokens of `tick|action|user_id` using
`AP_NONCE_KEY` + `AP_NONCE_SALT` from `ap-config.php`. The value placed in
forms and URLs is the **last 12 hex characters** of that HMAC (short enough
for query strings). One tick is **12 hours** (`AP_Nonce::TICK_SECONDS`). A
token is valid for the **current or previous** tick so a form submitted near
a boundary still verifies (`hash_equals`).

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

**REST** cookie-authenticated writes need a nonce for action `ap_rest`. Send
header `X-AP-Nonce` (WP-compat `X-WP-Nonce`) or field `_ap_nonce` /
`_wpnonce`. HTTP Basic skips the nonce because the credentials are the
proof. Details: [rest.md](rest.md).

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

When a signed-in user changes their own password, other sessions and
remember-me tokens are destroyed and the current session id is rotated so
that browser stays signed in (`AP_User::update()` /
`AP_Session::rotateCurrentSession()`). Password reset and
`AP_User::updatePassword()` revoke every stored session token. Changing
another account's password revokes all of that account's sessions. Inactive
accounts (`user_status !== 0`, including pending email verification) cannot
authenticate. An administrator with `edit_users` can activate a pending
verification account from Users without the emailed key
(`AP_Registration::activatePendingUser()`). That path requires an
activate-purpose key on the row, so it does **not** lift a forum ban that
reuses `user_status` = `1`.

Own-account password and email changes also require the current password.
An administrator editing a different user does not. See
[Profile re-authentication](#profile-re-authentication).

**Optional 2FA / TOTP is not in core.** The public-register gate and
reserved-login list are anti-spam / **anti-squat**. They are **not** a
second password factor. Re-entering the current password on Profile is the
same password, not a second factor.

---

## Profile re-authentication

Profile (`ap-admin/profile.php`, cap `read`) and Users → Edit of **your own**
account (`user-edit.php`, even with `edit_users`) ask for **Current password**
(`name="current_password"`, `autocomplete="current-password"`) beside the
email field and the new-password fields. The check runs only when the
password or the email address is being changed, including a change that only
rewrites the letter case of the same mailbox. Display name, bio, website,
signature, avatar, admin color mode, and the forum-notify checkbox save
without it. A wrong value in the field is ignored when neither password nor
email is changing.

Verification is `AP_User::checkPassword()` (`password_verify`). The submitted
value is not logged, not copied onto the saved field bag, and not written
back into the form.

| Result | Notice | What is stored |
|--------|--------|----------------|
| Password or email change, field empty | `Enter your current password to change your password or email.` | Other submitted fields. Password and email stay as stored. |
| Field filled, hash does not match | `Your current password is incorrect.` | Same. |
| Failed checks over the limit | `Too many attempts, try again in N minute.` or `minutes.` | The password is not verified while the lockout holds. Other fields still save. |
| Current password matches | No re-authentication notice | The new password and/or email, plus the other fields. |

The screen still returns an error for the rejected password or email change
after the other fields have been saved. Nonce (`update-profile-{id}` on
Profile, `update-user-{id}` on user-edit) and capability checks are unchanged.
Users → Add does not show the field.

### Administrator editing another user

An actor with `edit_users` who saves a **different** account may set that
account’s password or email without the target’s current password. A posted
`current_password` on that form is discarded. Users → Edit of the signed-in
user’s own row is the Profile rule above. Holding `edit_users` does not
bypass it, and that self-save is not audited.

Each successful password or email change on the other account appends
`{prefix}activity_log` (`AP_Activity_Log`, schema **14**). The row stores
actor id, target id, field name (`password` or `user_email`), UTC
`created_at`, and client IP. It has no column for the password, the hash, or
the address. A letter-case-only email rewrite is stored as `user_email` and
does not fire `ap_email_changed`. A failed save writes nothing. A log failure
does not undo the user update. Columns and indexes:
[schema.md](schema.md#activity_log).

### Sessions and notice mail

A successful password change on the signed-in account destroys that user’s
other sessions and remember-me tokens, rotates this request’s session id,
and leaves this browser signed in. A password change on any other account
destroys all of that account’s sessions. An email change does not, by
itself, destroy sessions. Password reset goes through
`AP_User::updatePassword()`, which revokes every stored session token,
including the browser that submitted the reset. A login rehash uses that
same method and does not send the notice below.

`AP_Account_Notice` sends **text/plain** through `AP_Mail::send()` with
`skip_rate_limit`, so the message does not consume the verification / reset
`mail` bucket. The body states what changed, when (UTC), and the client IP,
and tells the reader to contact the site administrator if they did not make
the change. It contains no password and no reset key. The password notice
includes the public lost-password URL (`login.php?action=lostpassword`) with
no key on the query string.

| Change | Recipients |
|--------|------------|
| Password | The address that was on the account before this save (a simultaneous email change still reaches that inbox). Subject: `[{site}] Your password was changed`. |
| Email | The previous address (`[{site}] Your email address was changed`, including that password-reset mail now goes to the new address) and a confirmation to the new address (`[{site}] Your new email address is confirmed`). |
| Same mailbox, including letter case only | Nothing is sent. `ap_email_changed` does not fire. The stored spelling can still update when the current password checked out. |

When outbound mail is disabled (`mail_transport` `none`, `off`, or
`disabled`) or not configured (SMTP with an empty host, or PHP `mail()`
unavailable), the notice is skipped. The reason is stored in
`mail_last_error` (Settings → Mail / Site Health) and written to the PHP
error log as `AgoraPress mail: … (password change notice)` or
`(email change notice)`. That line has no password and no mailbox. A skipped
or failed send does not undo the account change.

### Throttle

Failed current-password checks use `AP_Rate_Limit` action `current_password`
on the same transient store as login. The buckets are the user id and the
client IP. Either bucket blocks. They do not consume the `login`,
`password_reset`, or `mail` buckets. A successful check clears both
`current_password` counters. While locked, the submitted password is not
verified and the counter is not cleared. Only attempt counts are stored.

Defaults (options, **no ACP screen**; `php ap-cli option get` / `set`):

| Option | Default | Meaning |
|--------|---------|---------|
| `rate_limit_current_password_max` | `5` | Failures allowed in the window |
| `rate_limit_current_password_window` | `900` | Window, seconds (15 minutes) |
| `rate_limit_current_password_lockout` | `900` | Lockout, seconds (15 minutes) |

`getLimits('current_password')` applies those options, clamps them, then
runs filter `ap_current_password_rate_limits`. The filter receives
`array{max, window, lockout}` and the user id (`0` when the limit is read
outside a per-user check). Use `accepted_args` 2. A non-array return is
ignored. A non-numeric field keeps the resolved value. Returned numbers are
clamped: `max` 1–1000, `window` 30–86400, `lockout` 0–86400. The lockout
notice always states the wait in whole minutes (at least 1).

### Hooks

After the row is stored, a successful password change fires
`ap_password_changed`. A successful change to a different mailbox
(case-insensitive) fires `ap_email_changed`. Each action receives the
account user id, the actor user id, and `self` or `admin`. Use
`accepted_args` 3. The password and the addresses are not arguments.

| Context | When |
|---------|------|
| `self` | Profile, user-edit of this same account, `AP_User::update()` with no other actor, and password reset (`AP_Registration::resetPassword()`). Reset uses the account id as the actor even when another user is signed in. |
| `admin` | User-edit of a different account, or `AP_User::update()` with a different actor. |

A failed save does not fire either action. `AP_User::updatePassword()` does
not fire `ap_password_changed` (login rehash). Password reset fires it after
that method returns. Argument detail: [hooks.md](hooks.md#users--registration).

---

## Auth cookies and rate limits

Logged-in state is a signed cookie plus a server-side session token — not PHP
`$_SESSION`.

| Piece | As built |
|-------|----------|
| Cookie name | `ap_logged_in_` + 12 hex chars of a hash of `AP_LOGGED_IN_KEY` + `AP_LOGGED_IN_SALT` |
| Cookie value | `user_id\|expiration\|token\|hmac` (HMAC-SHA256; payload also binds login + a password-hash fragment) |
| Flags | `HttpOnly`, `SameSite=Lax`, `Secure` when PHP sees HTTPS, port 443, or `X-Forwarded-Proto: https` (this header is **not** gated on `AP_TRUST_PROXY`) |
| Lifetime | 2 days default; **14 days** with “remember me” |
| Server token | Random 32-byte hex, **hashed** in usermeta key `session_tokens` (cap 50 sessions / user) |

Logout destroys the current token and expires the cookie. A password change
invalidates outstanding cookies even before token cleanup (password fragment
in the HMAC). The browser that changed its own password receives a new
session id and cookie. Remember-me is a 14-day lifetime on that same token
store, not a second credential.

`AP_Session::login()` applies **rate limits** (IP + identity) before
`AP_User::authenticate()`. Failed attempts increment both buckets; success
clears them. Identity and IP keys are hashed so raw emails do not sit in
transient names. REST HTTP Basic (`AP_Rest::authenticate()`) uses those
same `login` buckets and thresholds. A locked Basic attempt is refused
with HTTP 429 before the password is checked, so a correct password and a
wrong password look the same while the lockout holds. Form failures and
Basic failures share the counters.

Default windows (`AP_Rate_Limit`; override with options
`rate_limit_{action}_max` / `_window` / `_lockout`):

| Action | Max | Window | Lockout |
|--------|-----|--------|---------|
| `login` | 5 | 15 min | 15 min |
| `current_password` | 5 | 15 min | 15 min |
| `register` | 5 | 1 h | 1 h |
| `password_reset` | 5 | 1 h | 30 min |
| `upload` | 40 | 10 min | 5 min |
| `mail` | 20 | 1 h | 1 h |

`current_password` counts failed current-password checks per user id and per
IP. It does not share buckets with `login`. Options
`rate_limit_current_password_max` / `_window` / `_lockout`, then filter
`ap_current_password_rate_limits`. Detail:
[Profile re-authentication](#profile-re-authentication).

`AP_Mail::send()` (the only outbound API) applies the `mail` action to the
client IP **and** each recipient identity before php/smtp or the `ap_mail_send`
filter. Either bucket can block. That is the backstop so an open register
form cannot turn the configured SMTP (or PHP `mail()`) into a cannon.
Invalid recipients do not consume quota. A blocked send stores the lockout
text in `mail_last_error` (Settings → Mail / Site Health) and returns false.
Override with `rate_limit_mail_max` / `_window` / `_lockout` (**no ACP
screen**). Tests may call `AP_Rate_Limit::disable()`. Transports, constants,
and failed-send honesty: [Outbound mail](#outbound-mail).

Client IP is `REMOTE_ADDR` by default (not spoofable without a proxy). Only if
you **define `AP_TRUST_PROXY` true** in `ap-config.php` (not in the sample
file — add it yourself behind a trusted reverse proxy) does the limiter also
read `X-Forwarded-For` / `X-Real-IP` / `Client-IP` (`HTTP_CLIENT_IP`). Do not
set that flag on a host that accepts those headers from the public internet.
The cookie `Secure` flag still honours `X-Forwarded-Proto` without this
constant — that only affects whether the cookie is marked Secure, not the
rate-limit IP.

Registration is off by default (`users_can_register` = `0`). When it is on,
the public form is gated as described in
[Public registration gate](#public-registration-gate) and
[Reserved logins (anti-squat)](#reserved-logins-anti-squat). Those checks
are anti-spam / anti-squat, **not** two-factor authentication.

Forum flood / spam guards (`forum_flood_interval` default 30 s) are
documented with forums ([forums.md](forums.md)).

---

## Outbound mail

`AP_Mail::send()` (`ap_mail()`) is the **only** outbound API. Bodies are
**text/plain** (`Content-Type: text/plain; charset=UTF-8`). There is **no**
PHPMailer, **no** Composer runtime mail library, **no** HTML templates, **no**
newsletters, and **no** comment-subscription mail.

| Transport | As built |
|-----------|----------|
| `php` | PHP `mail()`. Default. Zero-config fallback. In-memory test outbox unchanged. |
| `smtp` | Native `stream_socket_client` client (`AP_SMTP`). AUTH PLAIN and AUTH LOGIN. Encryption `none` / `tls` (STARTTLS, typically port 587) / `ssl` (SMTPS, typically port 465). |

Operator screen: **Settings → Mail** (`ap-admin/options-mail.php`, cap
`manage_options`) — [admin.md](admin.md#mail). Own settings group (not stuffed
into General). “Send test email to admin_email” hits General `admin_email`,
not the From address. Last error is shown on that screen and on Tools → Site
Health. Site Health does **not** send a test message and omits SMTP secrets.

When defined in `ap-config.php`, these constants **override** the matching
options (so a password can live next to DB creds). Names are comments in
`ap-config-sample.php` — never a real password:

`AP_MAIL_FROM_NAME`, `AP_MAIL_FROM_EMAIL`, `AP_MAIL_TRANSPORT`,
`AP_SMTP_HOST`, `AP_SMTP_PORT`, `AP_SMTP_ENCRYPTION`, `AP_SMTP_USER`,
`AP_SMTP_PASS`

There is **no** Reply-To `ap-config.php` constant. Generic examples only:
`smtp.example.com`, `noreply@example.com`.

Filter `ap_mail_send` may replace php/smtp (return `true` / `false` to
short-circuit; `null` continues). It does **not** run when the `mail` rate
limit blocks the send — [hooks.md](hooks.md). Classic-compat `wp_mail()`
calls `AP_Mail::send()` when that layer is loaded —
[compatibility.md](compatibility.md). Native plugins should call `ap_mail()`
/ `AP_Mail::send()`.

If `require_email_verification` is on and `send()` fails: keep the pending
user; **do not** show “check your email” as success; store last error; offer
resend on `login.php?action=resend` and on Users → Edit. Verification and
password-reset bodies: the link expires in 24 hours; if the message is
missing, check the spam folder — the sending server may be new. The
action URL is the absolute `{siteurl}/ap-admin/login.php?…` (built from
the `siteurl` option, not the path-only `AP_Admin::url()`).

---

## Public registration gate

Registration is off by default (`users_can_register` = `0`). Membership
options live on Settings → General (`options-general.php`), not a separate
membership plugin — [admin.md](admin.md#public-registration).

This gate is **anti-spam for an open form**. It is **not** two-factor
authentication, not a second password, and not a substitute for Argon2id.
Optional 2FA / TOTP is **not in core**. Email verification
(`require_email_verification`, default on) is proof of mailbox, **not** 2FA.

**Always-on** when the public form is open (even if visible captcha is `off`):

| Check | As built |
|-------|----------|
| Honeypot | Field `ap_hp`, labeled Website, `aria-hidden`. Must stay empty. Also rejects a posted `website` field. |
| Minimum fill | ~3 seconds (`AP_Registration::MIN_FILL_SECONDS`) from the form ticket’s issue time. |
| Form ticket | Hidden `ap_form_ticket` issued on GET (30-minute TTL, `AP_Registration::FORM_TICKET_TTL`). A naked POST without a valid ticket fails closed. |

Those three failures share one generic error: “Could not complete registration. Please try again.” The form does not say which check failed.

**Visible modes** (`registration_captcha`; sanitizer allowlist `off` / `math` /
`guard`; unknown saved values collapse to `off`; existing sites stay on
`off`):

| Mode | As built |
|------|----------|
| `off` | Default. No visible fieldset. Hidden gate still runs. |
| `math` | “Human check” fieldset. Arithmetic prompt; fields `captcha_answer` + `captcha_token`. |
| `guard` | Recommended-when-on. First-party checkbox card (`ap_guard_ack` = `1`) plus a signed token. Tiny JS proof-of-work (`ap-admin/js/register-guard.js`) is progressive enhancement. No-JS fallback: type a short code into `captcha_answer`. **No** Google reCAPTCHA, hCaptcha, or Turnstile widget in core. |

Hooks `ap_registration_captcha_mode`, `ap_registration_captcha_challenge`,
`ap_registration_verify_captcha`, and (for a plugin-supplied mode) action
`ap_registration_captcha_fields` stay as shipped — [hooks.md](hooks.md).

IP rate limit on the register action is in the table above. Failed
verification mail must not claim success — [Outbound mail](#outbound-mail).

---

## Reserved logins (anti-squat)

Reserved names stop strangers from **self-registering staff and system
logins** (`admin`, `root`, `moderator`, …). That is **anti-squat**: it keeps
those logins off the public form. It is **not** a second password factor,
**not** TOTP, and **not 2FA**. Optional 2FA / TOTP remains **not in core**.

Site administrators **may** still create those accounts from Users → Add,
Users → Edit, and `php ap-cli user create`. `AP_User::create()` does not
enforce this list.

Locked list in `AP_Registration::RESERVED_LOGINS` — the extras textarea
cannot remove these:

```
root, admin, administrator, administrators, adm, mod, moderator,
moderators, mods, webmaster, postmaster, hostmaster, support, security,
abuse, staff, superadmin, sysadmin, guest, nobody, noreply, no-reply,
www, mail, system, owner, ap-admin, agora, agorapress
```

Per-site extras: option `reserved_usernames` (textarea, one login per line).
Locked names typed there are dropped on save so the option stores extras
only. Plugins may **add** names with filter `ap_reserved_usernames`; locked
core names always remain after the filter. Public error for a reserved **or**
already-taken login: “That username is not available.” The form does **not**
say the name is reserved.

New logins collide case-insensitively (`Silas` and `silas` are the same
login). Existing rows that already differ only by case are not merged. After
a successful insert, `AP_User::create()` fires action `ap_user_created`
(user id, login, email, status), including `STATUS_PENDING`. Manual activate
of a pending row stays an `edit_users` path
(`AP_Registration::activatePendingUser()`) — [Passwords (Argon2id)](#passwords-argon2id).

---

## Capability checks

Privileged admin screens call `AP_Admin::requireLogin()` and a capability
(`AP_Admin::requireCapability()` / `ap_current_user_can()`). Examples as
built:

| Screen | Primary cap (fallbacks in code) |
|--------|----------------------------------|
| Settings → Mail | `manage_options` |
| Settings → Privacy | `manage_privacy_options` (`manage_options`) |
| Tools → Export Personal Data | `export_others_personal_data` (`manage_options`, `export`) |
| Tools → Erase Personal Data | `erase_others_personal_data` (`manage_options`, `delete_users`) |
| Tools → Update Core | `update_core` |
| Tools → Site Health | `view_site_health` (`manage_options`) |
| Tools → Analytics | `manage_options` |

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
| `~* ^/ap-content/uploads/.+\.svg$` | `Content-Security-Policy: script-src 'none'; sandbox` and `X-Content-Type-Options: nosniff` |
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

Skipped by default: CLI (`AP_CLI`), `/ap-admin/` (and `AP_ADMIN`),
non-GET/HEAD, feeds, REST (`/ap-json/`), sitemaps, robots.txt, coarse bot
UAs, logged-in `manage_options` users, `DNT: 1`. Public **404s are recorded**
by default (`status_code=404`). Retention prune
(`analytics_retention_days`, default 90) still runs after you turn collection
off.

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
  `mod_php` is in use, and denies `php` / `phtml` / `phar` / `cgi` / `pl` /
  `py` / `asp` / `aspx` / `jsp` / `shtml` / `.htaccess` / `.htpasswd`.
- SVG uploads are parsed as XML (no external entities). Elements and
  attributes are allowlisted. Script, `foreignObject`, event handlers, and
  `javascript:` / `data:` / `vbscript:` URLs are rejected, including entity
  encodings. `animate` / `set` that target `href` are rejected.
- Shipped root `.htaccess` does **not** set SVG response headers and does
  **not** use an expression container. Those directives make some
  `AllowOverride` setups reject the whole file (HTTP 500 on every URL).
- `ap-content/uploads/.htaccess` (also written by
  `AP_Media::uploadsHtaccessContents()` / `ensureProtectionFiles()`) sends
  uploaded SVG with `Content-Security-Policy: script-src 'none'; sandbox`
  and `X-Content-Type-Options: nosniff` inside `<IfModule mod_headers.c>`
  and `<FilesMatch "\.svg$">`. That block is **best effort**: it runs only
  when `mod_headers` is loaded and the vhost allows `Header` in that
  directory. If SVG URLs return HTTP 500, remove the block.
- [`docker/nginx.conf.example`](../docker/nginx.conf.example) has the same
  policy in `location ~* ^/ap-content/uploads/.+\.svg$`. Nginx does not
  read `.htaccess`; copy that location into the live server block.
- Shipped `.htaccess` files and the PHP that writes them do not use an
  Apache `<If>` expression container or an `m~` delimiter. One-click
  update does not replace an existing `ap-content/uploads/.htaccess`.
  A differing package copy is written to
  `ap-content/uploads/.htaccess.ap-new`.

Password-protected posts keep their body and excerpt out of RSS/Atom
(`This post is password protected.` and no `content:encoded`), Open Graph /
Twitter descriptions (site tagline, or that same generic line), search and
archive excerpts, and sitemaps. A request that already holds the post
password cookie can see the text. There is no JSON-LD block in core.

Nginx should not `fastcgi_pass` PHP under `uploads/` (restrict PHP to known
entry points when you can — the example file comments that). Site Icon uses
the same library ([site-icon.md](site-icon.md)).

---

## Site Health and debug

**Tools → Site Health** (`ap-admin/site-health.php`, cap `view_site_health`)
never transmits check results off-site. Version-check info appears only if
already cached (no forced network). Security-relevant checks as built:

| Check | Good looks like |
|-------|-----------------|
| Authentication keys & salts | All eight `AP_*_KEY` / `AP_*_SALT` constants defined and not the sample placeholder |
| Debug mode | `AP_DEBUG` off |
| Telemetry | Always good (collectors do not exist) |
| HTTPS | `siteurl` is `https://` |
| Outbound mail | Transport `php` or configured SMTP; last error empty. Does **not** send a test message |
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
7. Registration off unless you intend it. If it is on: Settings → Mail
   actually delivers; review rate-limit defaults; reserved extras and
   `registration_captcha` (`guard` when you want a visible first-party
   check). Reserved logins are **anti-squat**, not 2FA.
8. Analytics stays off unless you opt in. Hall of Fame stays opt-in.
9. Set a privacy policy page. Use Tools → Export / Erase when a data subject
   asks — there is no self-service portal in core.
10. Run **Tools → Site Health** after install and after host changes.

---

## Related docs

| Need | Doc |
|------|-----|
| Permissions, installer CSRF, post-install | [install.md](install.md) |
| Front controller; deny rules in the same files | [rewrites.md](rewrites.md) |
| Version check / updater, no site identity | [updates.md](updates.md) |
| Capabilities | [roles.md](roles.md) |
| `X-AP-Nonce`, Basic, `rest_api_enabled` | [rest.md](rest.md) |
| Settings → Mail, public register, Users resend / activate | [admin.md](admin.md) |
| Privacy principles checklist | [vision-compliance.md](vision-compliance.md) |
| Installer CSRF / session symptoms; mail not arriving | [troubleshooting.md](troubleshooting.md) |
| `php ap-cli option set` for flags with no ACP screen | [cli.md](cli.md) |
| Plugin Settings API / nonces | [plugins.md](plugins.md) |
| Classic `wp_mail()` shim | [compatibility.md](compatibility.md) |
| `ap_mail_send`, `ap_reserved_usernames`, `ap_user_created`, `ap_password_changed`, `ap_email_changed`, `ap_current_password_rate_limits` | [hooks.md](hooks.md) |
| Prepared statements; no invented tables | [schema.md](schema.md) |
| Forum flood vs login rate limits | [forums.md](forums.md) |
