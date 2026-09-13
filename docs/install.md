# Installing AgoraPress

This is the **installer depth guide** for AgoraPress **`0.3.9-beta`** (schema
`AP_DB_VERSION` **12**). It describes the four install paths **as built**: web
installer, CLI installer, Docker Compose, and a hand-written
`ap-config-sample.php`.

The human landing page (vision, requirements table, compact copies of these
paths) is [`../README.md`](../README.md). Read that first if you are new; this
file adds flags, exit codes, permissions, and the post-install checklist
without repeating the whole README.

**Source (as built):** `install/index.php`, `install/cli.php`,
`ap-includes/class-ap-cli-install.php`, `ap-includes/class-ap-installer.php`,
`ap-includes/class-ap-requirements.php`, `ap-config-sample.php`,
`docker-compose.yml`, `docker/Dockerfile`, `docker/apache-vhost.conf`,
`docker/php-agorapress.ini`, `docker/nginx.conf.example`.

Admin after a successful install is **`/ap-admin/`**. Fresh install is **not**
`php ap-cli …` — that tool manages an already-installed site (see
[cli.md](cli.md)). The installer is `php install/cli.php` or the browser at
`/install/`.

---

## Choose a path

| Path | When to use | Entry |
|------|-------------|--------|
| [Docker Compose](#docker-compose) | Fastest local try-out | `docker compose up -d --build` then `/install/` |
| [Web installer](#web-installer) | Shared hosting or a VPS with a browser | `https://your-domain.example/install/` |
| [CLI installer](#cli-installer) | Automation / SSH, non-interactive | `php install/cli.php --help` |
| [Manual config](#manual-config) | You already know you need a hand-written `ap-config.php` | Copy `ap-config-sample.php` |

All four paths write (or assume) **`ap-config.php` in the site root**. Presence
of that file is the “already installed” signal. **Never commit `ap-config.php`**
— it is gitignored and contains database credentials and auth salts.

Document root must be the AgoraPress root: the folder that contains
`index.php`, `ap-admin/`, `ap-includes/`, `ap-content/`, and `install/`.

---

## Requirements (summary)

Full table: [README — Requirements](../README.md#requirements).

| | |
|---|---|
| **PHP** | 8.2+ (8.3 / 8.4 recommended) |
| **Extensions (required)** | PDO plus at least one of `pdo_mysql` / `pdo_sqlite` / `pdo_pgsql`; mbstring; json; curl; fileinfo; zip; **gd or imagick** |
| **Recommended** | `intl` (warning only; install can continue) |
| **Database** | MySQL 8.0+ / MariaDB 10.6+ for production; SQLite for local demos; PostgreSQL is also supported |
| **Web server** | Apache with `mod_rewrite`, or Nginx with a front-controller `try_files` (see [rewrites.md](rewrites.md)) |
| **Disk** | PHP must be able to create `ap-config.php` and write `ap-content/` (including `uploads/`) |

Default table prefix: **`ap_`** (changeable at install). Schema target:
`AP_DB_VERSION` **12**.

The installer (`AP_Requirements`) will not continue while a **required** check
fails. The CLI can pass `--skip-requirements` if you know what you are doing;
the web installer has no skip flag.

---

## Docker Compose

Fastest way to try AgoraPress locally. Needs
[Docker](https://docs.docker.com/get-docker/) and Docker Compose. The shipped
stack is a **dev image**: PHP 8.3 + Apache (`docker/Dockerfile`) plus MySQL 8.0.
It does **not** auto-run the installer — you still complete `/install/` (or the
CLI installer inside the web container).

```bash
git clone https://github.com/ExtrovertedNerd/AgoraPress.git
cd AgoraPress
docker compose up -d --build
```

1. Open **http://localhost:8080**. Until `ap-config.php` exists, the front
   controller shows a “not installed” page (HTTP **503** is expected) with a
   link to the web installer. You can also go straight to
   **http://localhost:8080/install/**.
2. In the installer, use these database settings (they match
   `docker-compose.yml`):

| Field | Value |
|-------|--------|
| Driver | MySQL |
| Host | `db` (Compose service name, **not** `localhost`) |
| Database | `agorapress` |
| User | `agorapress` |
| Password | `agorapress` |
| Table prefix | `ap_` |

3. Set site title, public URL (`http://localhost:8080`), and an administrator
   username / email / password (password **min 8 characters**).
4. Finish install → log in at **http://localhost:8080/ap-admin/**

Notes (as built in `docker-compose.yml`):

- HTTP port defaults to **8080**. Override with `AP_HTTP_PORT` in a local
  `.env` (gitignored).
- MySQL is published on the host as **`127.0.0.1:3307`** (not 3306) so it does
  not clash with a local MySQL. From **inside** the `web` container the host is
  `db` on port 3306.
- The project tree is bind-mounted at `/var/www/html`. Apache
  (`docker/apache-vhost.conf`) sets `AllowOverride All` so shipped `.htaccess`
  rewrites work.
- Compose sets `AP_DB_*` and `AP_TABLE_PREFIX` on `web` so they match the MySQL
  service. The **installer form does not auto-fill from those env vars** — type
  the table values above (or pass `--db-*` / `--table-prefix` to
  `php install/cli.php`). The CLI installer also does **not** read
  `AP_DB_HOST` / `AP_DB_NAME` / `AP_DB_USER` / `AP_TABLE_PREFIX`.
- Optional Compose overrides via `.env`: `AP_DB_NAME`, `AP_DB_USER`,
  `AP_DB_PASSWORD`, `AP_DB_ROOT_PASSWORD`, `AP_TABLE_PREFIX`, `AP_DB_PORT`.
  Demo defaults (`agorapress` / `root`) are for local try-out only.
- CLI installer inside the stack, from the project directory on the host:
  `docker compose exec web php install/cli.php …` (same flags as
  [CLI installer](#cli-installer)). From the host PHP talking to published
  MySQL, use `--db-host=127.0.0.1:3307` instead of `db`.
- The shipped `docker/Dockerfile` is PHP 8.3 + Apache with **`pdo_mysql`**
  (plus gd, intl, mbstring, opcache, zip). It does **not** install
  `pdo_pgsql`. `docker/php-agorapress.ini` is a **dev** overlay
  (`display_errors On`, `memory_limit 128M`, `upload_max_filesize` /
  `post_max_size` 64M).
- Stop: `docker compose down`. The MySQL volume `ap_db_data` persists until you
  remove it (`docker compose down -v`).

This Compose file is **Apache**, not Nginx. The shipped
[`docker/nginx.conf.example`](../docker/nginx.conf.example) is a production
example (`root /var/www/agorapress`,
`try_files $uri $uri/ /index.php?$args`). See [rewrites.md](rewrites.md).

There is **no** Windows installer, `.deb` / `.rpm`, or hosted SaaS install in
core.

---

## Web installer

Best for a real server when you have a browser and FTP/SFTP or a control panel.

**URL:** `https://your-domain.example/install/` (`install/index.php`).

### Before you open it

1. **Get the files**
   - Release zip: build with `php bin/package-release.php` (or download a
     published package), **or**
   - Clone/copy the repo so the document root contains `index.php`,
     `ap-admin/`, `ap-includes/`, `ap-content/`, `install/`.
2. **Create a database** (MySQL / MariaDB recommended). Note host, name, user,
   and password.
3. **Permissions** — see [Permissions](#permissions). The PHP user must be
   able to create `ap-config.php` and write `ap-content/` (including
   `uploads/`).
4. **Point the vhost** at the AgoraPress root.
5. Confirm PHP can write its **session save path**. The web installer uses PHP
   sessions (CSRF token + step state). If `session.save_path` is not writable
   by the php-fpm (or Apache PHP) user, the installer may fail the security
   token check or lose the database step. A failed CSRF check shows
   “Invalid security token” and **returns you to the requirements step**.

### Steps (as built)

The wizard is five steps. Each POST is CSRF-protected. You cannot skip a
failed **required** requirements check.

| Step | Query | What happens |
|------|-------|----------------|
| 1. Requirements | `?step=requirements` | PHP version, extensions, PDO driver, gd/imagick, writable paths |
| 2. Database | `?step=database` | Driver (`mysql` / `sqlite` / `pgsql`), name (SQLite: file path), user, password, host, table prefix; **tests the connection** before continuing |
| 3. Site & admin | `?step=site` | Site title, public site URL, admin username / email / password (confirm), optional **Add sample content** |
| 4. Install | `?step=run` | Review summary → **Run installation** |
| 5. Done | `?step=done` | Tables created, admin seeded, `ap-config.php` written |

**Database defaults**

- Driver: MySQL / MariaDB.
- Host: `localhost` (use `db` in Docker; `127.0.0.1:3307` is valid when MySQL
  is on a non-default port).
- Table prefix: `ap_`.
- Charset / collation (not shown as extra fields): `utf8mb4` /
  `utf8mb4_unicode_ci`.
- SQLite with an empty name: the web POST handler and the CLI both fill
  `AP_Installer::defaultSqlitePath()` — an **absolute**
  `{site-root}ap-content/database.sqlite` written into `ap-config.php`.
  Shipped Apache and Nginx examples deny direct download of `.sqlite` / `.db`
  files.

**Site & admin validation** (`AP_Installer::validateSiteAndAdmin`)

- Site title required, max 200 characters.
- Site URL required and must be a valid URL (use `https://…` in production).
  The form guesses the current request URL (strips `/install`).
- Admin email must be valid.
- Username: 3–60 characters, letters, numbers, underscore, dot, or hyphen.
- Password: at least 8 characters; confirmation must match (web form only).

**Sample content (web):** checkbox **Add sample content**, **default on**. Seeds
a Hello World post, About / Sample / Privacy pages, and a welcome forum topic
(`AP_Sample_Content`). Failures here are non-fatal — core install still
succeeds. Uncheck the box for an empty site.

**What “Run installation” does** (same core path as the CLI: `AP_Installer::run`):

1. Connects with the submitted credentials (does not read `ap-config.php`).
2. Applies schema migrations through `AP_DB_VERSION` **12**.
3. Seeds options (site title, URL, modules **on**, plain permalinks, analytics
   **off**, `AP_DEBUG` **false** in the generated config, default theme
   **agora**, and the other installer defaults).
4. Creates the administrator (password hashed with Argon2id when available).
5. Ensures built-in taxonomies and the default Uncategorized category.
6. Optionally seeds sample content.
7. Generates unique auth keys / salts and writes `ap-config.php` (atomic write;
   **refuses to overwrite** an existing file).

If tables succeed but the config file cannot be written, the run step shows
the generated PHP in a textarea so you can copy it to `ap-config.php`
manually.

### Already installed

If `ap-config.php` is already present, `/install/` returns **HTTP 403** and
will not overwrite the site. The browser session that **just finished**
install can still open `?step=done`; every other client gets 403.

Removing `ap-config.php` does **not** drop database tables — only do that if
you intentionally want a fresh config. A second run against existing tables
does **not** wipe data: versioned migrations skip already-applied versions,
`seedAdminUser` creates an administrator only when the users table is empty,
and options are upserted. There is no uninstall wizard in core.

The “not installed” home page (no `ap-config.php`) is HTTP 503 with a button
to **Run the web installer**.

---

## CLI installer

Same result as the web installer, non-interactive. Refuses to overwrite an
existing `ap-config.php`. Must be run from the command line (`php` SAPI); it
exits `1` if invoked from a web SAPI.

```bash
php install/cli.php --help
```

### Required flags

| Flag | Meaning |
|------|---------|
| `--site-title=TITLE` | Site title |
| `--site-url=URL` | Public site URL (e.g. `https://example.com`) |
| `--admin-user=USER` | Administrator username (3–60 chars, same rules as the web form) |
| `--admin-email=EMAIL` | Administrator email |
| `--admin-password=PASS` | Administrator password (min 8 chars), **or** env `AP_ADMIN_PASSWORD` |

`--db-name` is required except when `--db-driver=sqlite` (then the default
file is `ap-content/database.sqlite`).

### Database flags

| Flag | Default | Notes |
|------|---------|--------|
| `--db-driver=DRIVER` | `mysql` | `mysql` \| `sqlite` \| `pgsql` |
| `--db-name=NAME` | (required) | Database name, or SQLite file path |
| `--db-user=USER` | empty | Ignored for SQLite |
| `--db-password=PASS` | env `AP_DB_PASSWORD` or empty | Or set `AP_DB_PASSWORD` |
| `--db-host=HOST` | `localhost` | Use `db` inside Compose; may include a port (`127.0.0.1:3307`) |
| `--db-charset=CHARSET` | `utf8mb4` | There is **no** `--db-collate` flag; collation is always `utf8mb4_unicode_ci` |
| `--table-prefix=PREFIX` | `ap_` | Letters, numbers, underscore; a leading digit gets `ap_` prepended; empty becomes `ap_` |

### Other flags

| Flag | Meaning |
|------|---------|
| `--config-path=PATH` | Write `ap-config.php` here (default: site root) |
| `--skip-requirements` | Skip PHP / extension / filesystem checks |
| `--sample-content` | Seed Hello World post, pages, and a welcome forum |
| `--no-sample-content` | Skip sample content (**default for CLI**) |
| `-h`, `--help` | Usage text (exit `0`) |

`--sample-content=0` / `false` / `no` / `off` disables; any other value
enables. Unknown options and missing required flags are usage errors.

Passwords via environment (optional, so they stay out of process lists):

- `AP_ADMIN_PASSWORD`
- `AP_DB_PASSWORD`

The CLI does **not** read `AP_DB_HOST` / `AP_DB_NAME` / `AP_DB_USER` /
`AP_TABLE_PREFIX` from the environment — those are Compose labels, not
installer flags. Pass `--db-*` / `--table-prefix` explicitly.

### Examples

```bash
# Quick local demo with SQLite (no separate DB server)
php install/cli.php \
  --db-driver=sqlite \
  --site-title="My AgoraPress Site" \
  --site-url=http://localhost:8080 \
  --admin-user=admin \
  --admin-email=admin@example.com \
  --admin-password='choose-a-strong-password'

# MySQL / MariaDB (production-style)
php install/cli.php \
  --db-driver=mysql \
  --db-host=127.0.0.1 \
  --db-name=agorapress \
  --db-user=agorapress \
  --db-password='db-password' \
  --site-title="My AgoraPress Site" \
  --site-url=https://example.com \
  --admin-user=admin \
  --admin-email=admin@example.com \
  --admin-password='choose-a-strong-password'

# Same MySQL values as Docker Compose, from inside the web container
docker compose exec web php install/cli.php \
  --db-driver=mysql --db-host=db --db-name=agorapress \
  --db-user=agorapress --db-password=agorapress \
  --site-title="My Site" --site-url=http://localhost:8080 \
  --admin-user=admin --admin-email=admin@example.com \
  --admin-password='choose-a-strong-password' \
  --sample-content

# Same stack, but PHP on the host talking to published MySQL
php install/cli.php \
  --db-driver=mysql --db-host=127.0.0.1:3307 --db-name=agorapress \
  --db-user=agorapress --db-password=agorapress \
  --site-title="My Site" --site-url=http://localhost:8080 \
  --admin-user=admin --admin-email=admin@example.com \
  --admin-password='choose-a-strong-password'
```

### Exit codes

| Code | Constant | Meaning |
|------|----------|---------|
| `0` | `AP_Cli_Install::EXIT_OK` | Success or `--help` |
| `1` | `EXIT_USAGE` | Missing flags, unknown options, or not CLI SAPI |
| `2` | `EXIT_REQUIREMENTS` | A required `AP_Requirements` check failed |
| `3` | `EXIT_INSTALL` | Config already exists, DB/migrate/write failure |

These codes are **not** the same as `php ap-cli` (`0` ok, `1` usage, `2`
error, `3` not installed). See [cli.md](cli.md).

If tables are created but `ap-config.php` cannot be written, the CLI prints
the generated file between `-----BEGIN AP-CONFIG-----` and
`-----END AP-CONFIG-----` (exit `3`). Save that output as `ap-config.php`.

---

## Manual config

Advanced path. Prefer the installers unless you need a hand-written file.

```bash
cp ap-config-sample.php ap-config.php
# Edit AP_DB_*, $table_prefix, and unique auth keys/salts
```

`ap-config-sample.php` documents the constants. You must set:

- `AP_DB_DRIVER` — `mysql`, `sqlite`, or `pgsql`
- `AP_DB_NAME` — database name, or an **absolute** SQLite path
  (e.g. `__DIR__ . '/ap-content/database.sqlite'`)
- `AP_DB_USER` / `AP_DB_PASSWORD` — ignored for SQLite
- `AP_DB_HOST` — `localhost`, `127.0.0.1:3307`, or `db` inside Compose
- `$table_prefix` — default `'ap_'` (change only **before** tables exist)
- The eight `AP_*_KEY` / `AP_*_SALT` constants — **replace** the placeholder
  `put your unique phrase here`. Changing salts later invalidates cookies and
  nonces. **Tools → Site Health** flags leftover placeholders.

Leave `AP_DEBUG`, `AP_DEBUG_DISPLAY`, and `AP_DEBUG_LOG` **false** on
production. `AP_CACHE` stays false unless you install a compatible
`ap-content/advanced-cache.php` drop-in (core does not ship a disk page-cache
engine). The sample also documents `AP_SAVEQUERIES` / `AP_DEBUG_QUERIES`
(leave false) and optional `AP_CONTENT_DIR` / `AP_CONTENT_URL` overrides;
the **generated** installer config omits those extra knobs (runtime defaults
stay off / unset).

Optional outbound-mail constants are comments in the sample, not live
defines: `AP_MAIL_FROM_NAME`, `AP_MAIL_FROM_EMAIL`, `AP_MAIL_TRANSPORT`,
`AP_SMTP_HOST`, `AP_SMTP_PORT`, `AP_SMTP_ENCRYPTION`, `AP_SMTP_USER`,
`AP_SMTP_PASS`. When defined they override Settings → Mail options so an
SMTP password can live next to the database password. Leave them commented
to use the admin screen. Generic examples only (`smtp.example.com`,
`noreply@example.com`) — never a real password.

Copying the sample is **not** a full install. Schema migrations still need to
run. Because a readable `ap-config.php` is the “installed” signal, the web and
CLI **installers will refuse** once that file exists. Apply schema with:

```bash
php ap-cli db migrate
php ap-cli db check
```

`php ap-cli` requires `ap-config.php` and a working database connection (exit
`3` if the config is missing). It does not create the administrator account —
that is installer-only. If you skip the installer entirely, you still need an
admin user (use the installer, or create one after tables exist with
`php ap-cli user create` — see [cli.md](cli.md)).

---

## Permissions

The requirements checker (`AP_Requirements`) treats these as **required**:

| Path | Need |
|------|------|
| Site root (`ap-config.php` location) | Directory writable so PHP can **create** `ap-config.php` (or the file itself writable if it already exists) |
| `ap-content/` | Directory exists and is writable |
| `ap-content/uploads/` | Directory exists and is writable. The installer creates it (with a silent `index.php` that returns 403) when it is missing and `ap-content/` is writable. Runtime files stay gitignored (`/ap-content/uploads/`). |

Typical Unix setup: directories owned by the PHP user (php-fpm or Apache),
mode that user can write. Do not make the tree world-writable as a shortcut.

Also:

- **SQLite:** the parent directory of the `.sqlite` file must be writable. The
  installer will `mkdir` that parent at `0755` when needed. Default path:
  `ap-content/database.sqlite`.
- **Web installer sessions:** PHP `session.save_path` must be writable by the
  same user that runs PHP. This is a host mechanism, not an AgoraPress
  setting. If sessions cannot be stored, CSRF checks fail and step data is
  lost.
- **After install:** keep `ap-config.php` unreadable over HTTP. Shipped
  [`.htaccess`](../.htaccess) and [`docker/nginx.conf.example`](../docker/nginx.conf.example)
  deny `ap-config.php`, `.env`, SQLite/DB files, and raw `ap-includes/` PHP
  (CSS/JS under `ap-includes` stay public). `ap-content/.htaccess` turns off
  indexes and denies `.sqlite` / `.db`. See [security.md](security.md).
- **Uploads:** the installer creates `ap-content/uploads/` when it is missing
  and `ap-content/` is writable (web and CLI share `AP_Requirements`).
  `ap-content/uploads/index.php` returns 403 so the directory is not a
  script entry point. Media files are served as static files when they
  exist. Do not use `--skip-requirements` to paper over a missing uploads
  directory.

The installer does not chown the tree for you.

---

## What a fresh install turns on

Installer-seeded options (not a complete dump — see
[schema.md](schema.md) and [features_and_functions.md](features_and_functions.md)):

| Option / constant | Fresh-install default |
|-------------------|------------------------|
| Modules `ap_module_static_pages`, `ap_module_blog`, `ap_module_forum` | All **on** (at least one must stay on) |
| `permalink_structure` | Empty = **Plain** (`?p=` / `?page_id=`) |
| `analytics_enabled` | **`0`** (opt-in; data stays in the site DB) |
| `rest_api_enabled` | `1` |
| `users_can_register` | `0` |
| `require_email_verification` | `1` |
| `stylesheet` / `template` | `agora` |
| `AP_DEBUG` / `AP_DEBUG_DISPLAY` / `AP_DEBUG_LOG` in generated config | `false` |
| `hall_of_fame_*` | Empty — installer does **not** ping or register a domain |
| `version_check_enabled` | `1` — cached GET of public `version.json` only; **no site identity** |

There is **no telemetry** constant or option. Core never phones home with
site-identifying data.

---

## Post-install checklist

Do these after **any** install path. Admin is `/ap-admin/`.

1. **Log in** at `/ap-admin/` with the administrator you created. Change the
   password if it was temporary.
2. **Settings → Modules** (`options-modules.php`) — enable or disable
   **Static Pages**, **Blog**, and **Forum** independently. Related menus and
   front-end routes follow these toggles. At least one module must remain on.
3. **Settings → Permalinks** (`options-permalink.php`) — fresh installs use
   **Plain** (`?p=` / `?page_id=`), which works without rewriting. Pretty
   structures (Day and name `/YYYY/MM/DD/slug/`, Month and name, Numeric,
   Post name, or custom) need the front controller:
   - Apache: keep shipped [`.htaccess`](../.htaccess) (`mod_rewrite`,
     `AllowOverride All`).
   - Nginx: `try_files $uri $uri/ /index.php?$args` as in
     [`docker/nginx.conf.example`](../docker/nginx.conf.example). Missing
     `try_files` is the usual cause of 404s on pretty URLs.
   - After changing structure: **Settings → Permalinks** save, or
     `php ap-cli rewrite flush`.
   - Pages use `/slug/` when pretty permalinks are on; posts follow the
     chosen structure. Details: [rewrites.md](rewrites.md).
4. **Tools → Site Health** (`site-health.php`) — PHP/extensions, writable
   paths, database, schema vs `AP_DB_VERSION`, unique salts, debug off, HTTPS,
   admin email, outbound mail (transport + last error; it does **not** send a
   test message), privacy policy, modules, pending core update (from cached
   `version.json` only). CLI equivalent: `php ap-cli site health`.
5. **Site URL** — production should be `https://…`. Terminate TLS on the web
   server. Site Health warns when the stored URL is not HTTPS.
6. **Optional: Tools → Analytics** (`analytics.php`, cap `manage_options`) —
   local pageviews, **off by default** (`analytics_enabled`). No front-end JS,
   no third-party pixels. Retention defaults to 90 days. Not Hall of Fame and
   not the version check.
7. **Optional: Tools → Import** — WordPress WXR or phpBB. Not required for a
   blank site.
8. **Privacy** — sample content may include a Privacy Policy page; Site Health
   looks for one. Tools also include export / erase personal data.
9. Confirm **`AP_DEBUG` stays false** on a public host.
10. Confirm deny rules still apply after you customize the vhost
    ([security.md](security.md)).

Day-to-day CLI on an installed site (`php ap-cli --help`): version, site
health, option, plugin, theme, user, post, `db check` / `db migrate`, cache
flush, rewrite flush, cron event list, `core check-update`. Cookbook:
[cli.md](cli.md).

Updates (one-click **Tools → Update Core**, `version.json`, what is **not**
overwritten): [updates.md](updates.md). An update does not rewrite
`ap-config.php`, uploads, custom themes/plugins, `install/`, or
`ap-config-sample.php`.

---

## Production notes

The compact live-site list lives in
[README — Production install](../README.md#production-install-live-site).
In short:

- Prefer **MySQL 8+ / MariaDB 10.6+**, not SQLite.
- Document root = AgoraPress root; create a database user with full rights on
  that database only.
- Run `/install/` or `php install/cli.php`, then `/ap-admin/`.
- HTTPS, `AP_DEBUG` false, shipped rewrite + deny rules.
- Strong admin password, modules, menus, privacy policy, Site Health.

Generic examples in this guide (`example.com`, `localhost`,
`admin@example.com`, `noreply@example.com`, `smtp.example.com`,
`/var/www/agorapress`) are **examples**. Do not copy private host paths
into public docs or tickets.

---

## Related documentation

| Doc | Why |
|-----|-----|
| [../README.md](../README.md) | Landing page: vision, requirements, four paths |
| [README.md](README.md) | Docs index (humans + trusted agent) |
| [rewrites.md](rewrites.md) | Front controller, `.htaccess`, nginx `try_files`, pretty vs `?p=` |
| [cli.md](cli.md) | Built-in `ap-cli` after install |
| [updates.md](updates.md) | `version.json`, Update Core, `db migrate` |
| [admin.md](admin.md) | `/ap-admin/` screens |
| [security.md](security.md) | Deny rules, nonces, no telemetry |
| [troubleshooting.md](troubleshooting.md) | Symptom → check |
| [schema.md](schema.md) | Tables, migrations, prefix, drivers |
| [forums.md](forums.md) | Forum module after Settings → Modules |
| [roles.md](roles.md) | First administrator and default roles |
| [rest.md](rest.md) | `/ap-json/` (`rest_api_enabled` default on) |
| [site-icon.md](site-icon.md) | Settings → General favicon pack |
| [plugins.md](plugins.md) · [themes.md](themes.md) | Drop-in under `ap-content/` |

*AgoraPress — free forever. Publish. Discuss. Own your stack.*
