# AgoraPress

**Lightweight, free-forever open-source CMS with integrated forums.**

Spiritual successor to classic WordPress + phpBB: easy to self-host, theme, and maintain. No bloat, no paywalls, no telemetry by default.

> **Status:** Early development (Phase 1). Web installer at `/install/` and CLI installer (`php install/cli.php`). Full CMS/forum features continue to land incrementally.

---

## Vision summary

AgoraPress restores the spirit of early WordPress while adding first-class community forums in one install. The name comes from the ancient Greek *agora* — the public square and marketplace of ideas: **publishing + open discussion**.

### Core principles

| Principle | What it means |
|-----------|----------------|
| **Free forever** | Core features, starter theme(s), and essential modules under GPLv2-or-later. No official paywalls or freemium tiers for core functionality. Optional unobtrusive donation link in admin only. |
| **Lightweight by design** | Minimal core footprint, optional modules, fast on shared hosting, modern PHP 8.2+ with no legacy cruft. |
| **Easy self-host & maintain** | 5-minute web installer, CLI install path, Docker Compose one-liner, clear updates, familiar mental model for classic WP users. |
| **Easy to theme** | Pure PHP template hierarchy and child themes. **Classic WordPress Theme Compatibility Layer** so many pre-block WP themes can run with minimal changes. |
| **Powerful & extensible** | Action/filter hooks, plugins, settings API, shortcodes, custom post types & taxonomies, lightweight REST API. |
| **Integrated community** | Forums, topics, groups, moderation, PMs, and attachments share users, roles, and capabilities with the CMS. |
| **Secure & private by default** | Prepared statements, modern hashing, CSRF/XSS protections, **no telemetry**. |
| **Migration friendly** | WordPress WXR + phpBB importers under Tools → Import (JSON export or live phpBB database). |

### Three independent modules

Any combination of **Static Pages**, **Blog**, and **Forum** can be enabled. A pure brochure site, a blog-only install, forums only, or all three — the architecture is built so each works cleanly alone or together.

### Non-goals (v1)

- Full Gutenberg / Full Site Editing in core  
- Official hosted SaaS or paid marketplace from the core project  
- Heavy AI features or telemetry  
- PHP &lt; 8.2  

---

## Requirements

| | |
|---|---|
| **PHP** | 8.2+ (8.3 / 8.4 recommended) |
| **Extensions** | PDO + pdo_mysql (or pdo_sqlite), mbstring, json, curl, fileinfo, gd or imagick, zip · *recommended:* intl |
| **Database** | MySQL 8.0+ / MariaDB 10.6+ (primary) · SQLite 3.35+ · PostgreSQL |
| **Web server** | Apache (`mod_rewrite`) or Nginx with URL rewriting |
| **Memory** | 64 MB+ recommended |

Table prefix defaults to `ap_` (configurable before install).

---

## Quick start

### Option A — Docker Compose (recommended for local dev)

Requires [Docker](https://docs.docker.com/get-docker/) and Docker Compose.

```bash
git clone https://github.com/ExtrovertedNerd/AgoraPress.git
cd AgoraPress
docker compose up -d --build
```

Open **http://localhost:8080**

- Default HTTP port: `8080` (override with `AP_HTTP_PORT` in a local `.env`)
- MySQL is published on **127.0.0.1:3307** by default (avoids clashing with a host MySQL on 3306)
- Default DB credentials (dev only): user/password/database `agorapress`, root password `root`

Open the site: you should see a friendly **not installed** page (HTTP 503) with a link to the web installer, or go directly to **http://localhost:8080/install/**.

Installer flow: **requirements → database → site info & admin → tables + config**.  
Docker DB defaults: host `db`, database/user/password `agorapress`, table prefix `ap_`.

Stop the stack:

```bash
docker compose down
```

### Option B — Web installer (recommended)

1. Place the project document root where your web server can serve it (or point the vhost at this directory).
2. Ensure PHP 8.2+ and the extensions above are available; make the site root and `ap-content/` (including `uploads/`) writable by the web server.
3. Create a MySQL/MariaDB database (or use SQLite for a local demo).
4. Open `/install/` in the browser and complete the steps:
   - **Requirements** — PHP version, extensions, filesystem
   - **Database** — driver, credentials, table prefix (default `ap_`)
   - **Site & admin** — title, URL, administrator account (Argon2id password hash)
   - **Install** — runs versioned migrations, seeds options/admin, writes `ap-config.php`
5. **Never commit `ap-config.php`** — it is gitignored.

### Option C — CLI installer

Non-interactive install for servers, automation, and Docker. Same core path as the web installer (migrations, salts, admin seed, `ap-config.php`).

```bash
# Help
php install/cli.php --help

# SQLite zero-config demo
php install/cli.php \
  --db-driver=sqlite \
  --site-title="My AgoraPress Site" \
  --site-url=http://localhost:8080 \
  --admin-user=admin \
  --admin-email=admin@example.com \
  --admin-password=changeme123

# MySQL (e.g. Docker Compose service host "db")
php install/cli.php \
  --db-driver=mysql \
  --db-host=db \
  --db-name=agorapress \
  --db-user=agorapress \
  --db-password=agorapress \
  --site-title="My AgoraPress Site" \
  --site-url=http://localhost:8080 \
  --admin-user=admin \
  --admin-email=admin@example.com \
  --admin-password=changeme123
```

Useful flags: `--table-prefix=ap_`, `--config-path=/path/to/ap-config.php`, `--skip-requirements`.  
Passwords may also come from env: `AP_ADMIN_PASSWORD`, `AP_DB_PASSWORD` (avoids argv history).  
Exit codes: `0` success, `1` usage, `2` requirements, `3` install failure. Refuses to overwrite an existing `ap-config.php`.

### Option D — ap-cli (manage an installed site)

After install, use **`ap-cli`** for day-to-day operations (WP-CLI-inspired, no extra dependencies):

```bash
php ap-cli --help
php ap-cli version
php ap-cli cli info

# Options, plugins, themes, users
php ap-cli option get blogname
php ap-cli option set blogname "My Site"
php ap-cli plugin list
php ap-cli plugin activate my-plugin/plugin.php
php ap-cli theme list
php ap-cli theme activate agora
php ap-cli user list
php ap-cli user create --user_login=editor --user_email=ed@example.com --user_pass=secretpass --role=editor

# Schema, cache, cron, rewrites, health
php ap-cli db check
php ap-cli db migrate
php ap-cli cache flush
php ap-cli cron event list
php ap-cli cron event run
php ap-cli rewrite flush
php ap-cli site health
php ap-cli core check-update
```

Global flags: `--path=/var/www/site`, `--url=https://example.com`, `--skip-plugins`.  
Exit codes: `0` ok, `1` usage, `2` error, `3` not installed.  
Plugins can register commands on the `ap_cli_init` action via `AP_Cli::addCommand()`.

### REST API (lightweight)

JSON API at **`/ap-json/`** (pretty permalinks) or **`?rest_route=/ap/v1/posts`** (plain). Primary namespace: `ap/v1`.

| Endpoint | Methods | Notes |
|----------|---------|--------|
| `/ap-json/` | GET | Site index, namespaces, route map |
| `/ap-json/ap/v1/settings` | GET | Public site settings + module toggles |
| `/ap-json/ap/v1/posts` | GET, POST | List / create posts |
| `/ap-json/ap/v1/posts/{id}` | GET, PUT/PATCH, DELETE | Single post (auth for write) |
| `/ap-json/ap/v1/pages` | GET | Published pages |
| `/ap-json/ap/v1/comments` | GET | Approved comments |
| `/ap-json/ap/v1/users` | GET | Public user profiles |
| `/ap-json/ap/v1/categories`, `/tags` | GET | Taxonomies |
| `/ap-json/ap/v1/forums`, `/topics` | GET | When Forum module is on |

Auth: browser session cookie (send `X-AP-Nonce` for writes) or HTTP Basic (`username:password`). Disable with option `rest_api_enabled=0`. Plugins register routes on `ap_rest_api_init` via `ap_register_rest_route()`.

### Option E — Manual config (advanced)

```bash
cp ap-config-sample.php ap-config.php
```

Set `AP_DB_*`, `$table_prefix` (default `ap_`), and unique auth keys/salts. Schema migrations still need to be applied (web or CLI installer).

Docker Compose defaults for reference (when the web container talks to the `db` service):

| Constant / setting | Typical Docker value |
|--------------------|----------------------|
| `AP_DB_HOST` | `db` |
| `AP_DB_NAME` | `agorapress` |
| `AP_DB_USER` | `agorapress` |
| `AP_DB_PASSWORD` | `agorapress` |
| `$table_prefix` | `ap_` |

From the **host** machine (not inside the web container), MySQL is usually `127.0.0.1:3307`.

### Nginx

An example reverse-proxy / rewrite config lives at [`docker/nginx.conf.example`](docker/nginx.conf.example). Apache users can use the included [`.htaccess`](.htaccess).

---

## Project layout

```
/
├── index.php                 # Front controller
├── ap-cli                    # Operational CLI for installed sites
├── ap-config-sample.php      # Sample config (copy → ap-config.php)
├── install/                  # Web (index.php) + CLI (cli.php) installer
├── ap-admin/                 # Administration UI
├── ap-includes/              # Core libraries, hooks, WP theme compatibility/
├── ap-content/
│   ├── themes/
│   ├── plugins/
│   ├── mu-plugins/
│   ├── languages/
│   └── uploads/              # Runtime — not committed
├── bin/package-release.php   # Build production zip + SHA-256 (not shipped in the zip)
├── docker/                   # Dockerfile, Apache vhost, nginx example
├── docker-compose.yml
├── docs/                     # Developer documentation
├── composer.json
├── tests/
├── CHANGELOG.md
├── LICENSE                   # GPLv2-or-later
└── README.md
```

---

## Developer documentation

Extending AgoraPress (hooks, themes, plugins, WP theme compatibility, database schema):

| Guide | Path |
|-------|------|
| Index | [`docs/README.md`](docs/README.md) |
| Hooks | [`docs/hooks.md`](docs/hooks.md) |
| Theme hierarchy | [`docs/themes.md`](docs/themes.md) |
| Plugin API | [`docs/plugins.md`](docs/plugins.md) |
| Classic WP compatibility | [`docs/compatibility.md`](docs/compatibility.md) |
| Database schema | [`docs/schema.md`](docs/schema.md) |

## Development

```bash
# Install dev tools (PHPUnit, PHPCS, PHPStan)
composer install

# Unit / structure tests
composer test
# or: vendor/bin/phpunit
# or: pytest tests/ -v

# Structure check only
composer test:structure
# or: php tests/Structure/assert-structure.php

# Coding standards (PSR-12 adapted PHPCS)
composer cs
composer cs:check
composer cs:fix   # auto-fix where possible

# Static analysis (PHPStan level 3 on ap-includes)
composer analyse

# Production release package (zip + SHA-256 + version.json.example)
composer package
# or: php bin/package-release.php
# Dry run: composer package:dry-run
```

CI (GitHub Actions) runs `composer test`, `composer cs:check`, and `composer analyse` on PHP 8.2, 8.3, and 8.4.

See [`CODING_STANDARDS.md`](CODING_STANDARDS.md), [`phpunit.xml.dist`](phpunit.xml.dist), [`phpstan.neon.dist`](phpstan.neon.dist), and [`CHANGELOG.md`](CHANGELOG.md).

---

## Release packaging

Operators publish installable core packages for fresh installs and one-click updates (`AP_Core_Updater` + public `version.json`).

```bash
php bin/package-release.php
# Options: --output-dir=DIR  --version=VER  --prefix=NAME  --dry-run  --json
```

| Artifact | Description |
|----------|-------------|
| `dist/AgoraPress-{version}.zip` | Production tree under a top-level `AgoraPress/` folder (`index.php`, `ap-admin/`, `ap-includes/`, default Agora theme, installer, docs, …) |
| `dist/AgoraPress-{version}.sha256` | SHA-256 of the zip (for optional `sha256` in `version.json`) |
| `dist/version.json.example` | Template for the public version endpoint (edit URLs before serving) |

**Not shipped:** `tests/`, `vendor/`, `.git` / `.github`, `.hephaestus/`, PHPCS/PHPUnit/PHPStan configs, `composer.lock`, secrets (`ap-config.php`, `.env`), runtime upload content, and the packaging script itself (`bin/`).

The zip is recognized by the core updater (`index.php` + `ap-includes/version.php` + `ap-admin/`). Version labels come from `AP_VERSION` in `ap-includes/version.php` unless `--version=` is set. Package artifacts under `dist/` are gitignored.

---

## Security & privacy

- PDO **prepared statements** only for database access  
- Nonces / capability checks on privileged actions (as features land)  
- Password hashing with Argon2id (installer + auth)  
- **`AP_TELEMETRY` is false by default** — no site identification is sent for version checks by default  
- **Version check** (admin-only): GET of the public `version.json` endpoint; transient-cached; fails silently; **never** sends domain or other site identity. Option `version_check_enabled` (default on) can disable checks for offline installs.  
- **One-click auto-update** (Dashboard → **Update Core**, cap `update_core`): downloads the published package URL from `version.json` (optional SHA-256 verification), applies core files while preserving `ap-config.php` and user content under `ap-content/` (uploads, plugins, mu-plugins, custom themes; default `agora` theme may update), runs pending DB migrations, and uses a brief front-end maintenance page. No site identity is sent on the package GET.
- **Privacy tools (GDPR-style):** Settings → **Privacy** (policy page selector); Tools → **Export Personal Data** / **Erase Personal Data** — portable JSON export of a user’s personal data, and erase (anonymize content ownership + delete account). Caps `manage_privacy_options`, `export_others_personal_data`, `erase_others_personal_data`.

Optional “Hall of Fame” domain registration (fully voluntary, withdrawable) is the only install-counting path — never automatic pings. Admins can join or leave under **Settings → Hall of Fame**; the dashboard may show a one-time prompt. Registration sends only the site domain (no telemetry).

---

## License

[GNU General Public License v2.0 or later](LICENSE) (`GPL-2.0-or-later`).

---

## Links

- Project site: [agorapress.extrovertednerd.com](https://agorapress.extrovertednerd.com)  
- Source: [github.com/ExtrovertedNerd/AgoraPress](https://github.com/ExtrovertedNerd/AgoraPress)  
- Version endpoint: `https://agorapress.extrovertednerd.com/version.json`  

---

*AgoraPress — free forever. Publish. Discuss. Own your stack.*
