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
| **Powerful & extensible** | Action/filter hooks, plugins, settings API, shortcodes, custom post types & taxonomies. |
| **Integrated community** | Forums, topics, groups, moderation, PMs, and attachments share users, roles, and capabilities with the CMS. |
| **Secure & private by default** | Prepared statements, modern hashing, CSRF/XSS protections, **no telemetry**. |
| **Migration friendly** | Planned high-quality importers from WordPress (WXR) and phpBB. |

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

### Option D — Manual config (advanced)

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
├── docker/                   # Dockerfile, Apache vhost, nginx example
├── docker-compose.yml
├── composer.json
├── tests/
├── LICENSE                   # GPLv2-or-later
└── README.md
```

---

## Development

```bash
# Install dev tools (PHPUnit, PHPCS)
composer install

# Unit / structure tests
composer test
# or: vendor/bin/phpunit
# or: pytest tests/ -v

# Structure check only
composer test:structure
# or: php tests/Structure/assert-structure.php

# Coding standards / static analysis (PSR-12 adapted PHPCS)
composer cs
composer cs:check
composer cs:fix   # auto-fix where possible
```

CI (GitHub Actions) runs `composer test` and `composer cs:check` on PHP 8.2, 8.3, and 8.4.

See [`CODING_STANDARDS.md`](CODING_STANDARDS.md), [`phpunit.xml.dist`](phpunit.xml.dist), and [`CHANGELOG.md`](CHANGELOG.md).

---

## Security & privacy

- PDO **prepared statements** only for database access  
- Nonces / capability checks on privileged actions (as features land)  
- Password hashing with Argon2id (installer + auth)  
- **`AP_TELEMETRY` is false by default** — no site identification is sent for version checks by default  

Optional “Hall of Fame” domain registration (fully voluntary, withdrawable) is the only install-counting path — never automatic pings. Admins can join or leave under **Settings → Hall of Fame**; the dashboard may show a one-time prompt. Registration sends only the site domain (no telemetry).

---

## License

[GNU General Public License v2.0 or later](LICENSE) (`GPL-2.0-or-later`).

---

## Links

- Project site: [agorapress.extrovertednerd.com](https://agorapress.extrovertednerd.com)  
- Source: [github.com/ExtrovertedNerd/AgoraPress](https://github.com/ExtrovertedNerd/AgoraPress)  
- Version endpoint (planned): `https://agorapress.extrovertednerd.com/version.json`  

---

*AgoraPress — free forever. Publish. Discuss. Own your stack.*
