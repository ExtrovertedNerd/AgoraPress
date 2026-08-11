# AgoraPress

**Lightweight, free-forever CMS with integrated forums.**

A spiritual successor to classic WordPress + phpBB: one install for publishing and community discussion. No bloat, no paywalls, **no telemetry** by default.

> **Status:** **`0.3.4-beta`** — MVP feature-complete with Site Icon (favicon pack), plugin admin pages (ACP registry + router), forum UI phpBB-parity (board index + two-pane topic view), forum likes/moderation, Theme Options API, and local privacy-respecting site analytics; **ready for live-site install**. Product principles and intentional deviations: [docs/vision-compliance.md](docs/vision-compliance.md).

---

## Vision summary

AgoraPress is named after the ancient Greek *agora* — a public square for ideas: **publish and discuss**.

| Principle | Meaning |
|-----------|---------|
| **Free forever** | Core under GPLv2-or-later. No freemium gates on core features. |
| **Lightweight** | Optional modules, modern PHP 8.2+, no runtime Composer deps. |
| **Easy to host** | Web installer, CLI install, Docker Compose. |
| **Easy to theme** | PHP templates + **Classic WordPress Theme Compatibility Layer**. |
| **Extensible** | Hooks, plugins, shortcodes, Settings API, REST, CLI. |
| **Community-ready** | Forums share users, roles, and media with the CMS. |
| **Private by default** | Prepared statements, nonces, Argon2id passwords, **no telemetry**. |

### Modules

Enable any mix of **Static Pages**, **Blog**, and **Forum**. Brochure site, blog only, forums only, or all three.

### What ships today (MVP)

| Area | Capabilities |
|------|----------------|
| **CMS** | Posts, pages, revisions, media library, comments, categories/tags, menus, widgets, feeds, sitemaps, Open Graph |
| **Editor** | Lightweight visual WYSIWYG (Visual \| Text modes); HTML storage; legacy Markdown/BBCode still converts on display |
| **Forums** | Hierarchy, topics/replies, attachments, groups & ACL by user level, moderation (edit/delete/lock), post likes, user stats, search, flood guards, PMs, online/unread |
| **Default theme** | Agora — six pure-CSS schemes (3 light + 3 dark), guest Log in / Register, topic like/edit/delete/lock toolbar, dark-mode form contrast, long-string wrapping |
| **Admin** | Responsive light/dark UI, roles/caps, users, Site Health, **Tools → Analytics** (opt-in local pageviews), **Appearance → Theme Options** (Additional CSS + theme Settings API / theme_mods), Import (WXR + phpBB), privacy export/erase |
| **Ops** | Installers, one-click core update (no site identity), `ap-cli`, release packaging, Apache/Nginx hardening examples |
| **Extensibility** | Hooks, plugins/mu-plugins, shortcodes, Settings/Options API, plugin ACP pages (`ap_register_admin_page` + WP shims), REST (`/ap-json/`), Classic WP theme shims |

### Non-goals (v1)

- Gutenberg / Full Site Editing in core  
- Official hosted SaaS or paid marketplace  
- Heavy AI features or telemetry  
- PHP older than 8.2  

---

## Requirements

| | |
|---|---|
| **PHP** | 8.2+ (8.3 / 8.4 recommended) |
| **Extensions** | PDO + `pdo_mysql` or `pdo_sqlite`, mbstring, json, curl, fileinfo, zip, gd or imagick · *recommended:* intl |
| **Database** | MySQL 8.0+ / MariaDB 10.6+ (production) · SQLite (local demos) · PostgreSQL |
| **Web server** | Apache with `mod_rewrite`, or Nginx with rewrite rules |
| **Disk** | Writable site root (for `ap-config.php`) and `ap-content/` (including `uploads/`) |

Default table prefix: `ap_` (changeable at install). Schema target: `AP_DB_VERSION` **12** (see [docs/schema.md](docs/schema.md)).

---

## Quick start

Pick one install path. After install, admin is at **`/ap-admin/`**.

### 1. Docker (fastest for local try-out)

Needs [Docker](https://docs.docker.com/get-docker/) and Docker Compose.

```bash
git clone https://github.com/ExtrovertedNerd/AgoraPress.git
cd AgoraPress
docker compose up -d --build
```

1. Open **http://localhost:8080** — you should see a “not installed” page, or go straight to **http://localhost:8080/install/**
2. In the installer, use these database settings (they match `docker-compose.yml`):

| Field | Value |
|-------|--------|
| Driver | MySQL |
| Host | `db` |
| Database | `agorapress` |
| User | `agorapress` |
| Password | `agorapress` |
| Table prefix | `ap_` |

3. Set site title, public URL (`http://localhost:8080`), and an admin username/email/password.
4. Finish install → log in at **http://localhost:8080/ap-admin/**

Notes:

- HTTP port defaults to **8080** (override with `AP_HTTP_PORT` in a local `.env`).
- MySQL is published on the host as **127.0.0.1:3307** (not 3306) so it won’t clash with a local MySQL.
- Stop: `docker compose down`

### 2. Web installer (shared hosting or VPS)

Best for a real server when you have a browser and FTP/SFTP or a control panel.

1. **Get the files**  
   - Release zip: build with `php bin/package-release.php` (or download a published package), **or**  
   - Clone/copy the repo so the document root contains `index.php`, `ap-admin/`, `ap-includes/`, `ap-content/`, `install/`.

2. **Create a database** (MySQL/MariaDB recommended). Note host, name, user, and password.

3. **Permissions**  
   The PHP user must be able to:
   - Create `ap-config.php` in the site root  
   - Write under `ap-content/` (and `ap-content/uploads/`)

4. **Point the vhost** at the AgoraPress root (the folder that contains `index.php`).

5. **Open the installer** in your browser:  
   `https://your-domain.example/install/`

6. Complete the steps:
   - **Requirements** — PHP version, extensions, writable paths  
   - **Database** — driver, credentials, table prefix (`ap_` by default)  
   - **Site & admin** — title, site URL (use `https://…` in production), administrator account  
   - **Install** — creates tables, seeds options, writes `ap-config.php`

7. **After success**
   - Log in at `/ap-admin/`
   - Change the admin password if it was temporary  
   - **Settings → Modules** — enable Pages / Blog / Forum as needed  
   - **Settings → Permalinks** — pick a structure; keep shipped [`.htaccess`](.htaccess) (Apache) or adapt [`docker/nginx.conf.example`](docker/nginx.conf.example)  
   - Run **Tools → Site Health**  
   - Optional: **Tools → Analytics** — enable local pageview collection (off by default; data stays in your database)

**Never commit `ap-config.php`.** It is gitignored and contains secrets.

### 3. CLI installer (automation / SSH)

Same result as the web installer, non-interactive. Refuses to overwrite an existing `ap-config.php`.

```bash
# Help
php install/cli.php --help

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
```

Useful flags: `--table-prefix=ap_`, `--config-path=/path/to/ap-config.php`, `--skip-requirements`, `--sample-content` / `--no-sample-content`.  
Passwords via env (optional): `AP_ADMIN_PASSWORD`, `AP_DB_PASSWORD`.  
Exit codes: `0` ok · `1` usage · `2` requirements · `3` install failure.

### 4. Manual config (advanced)

```bash
cp ap-config-sample.php ap-config.php
# Edit AP_DB_*, $table_prefix, and unique auth keys/salts
```

You still need schema migrations applied (run the web or CLI installer, or use `php ap-cli db migrate` after a partial setup). Prefer the installers unless you know you need a hand-written config.

### Production install (live site)

Use this checklist for a public host (shared hosting, VPS, or container):

1. Prefer **MySQL 8+ / MariaDB 10.6+** (not SQLite) for production.  
2. Upload the release zip (or clone) so the document root is the AgoraPress root.  
3. Create a database and a DB user with full rights on that database.  
4. Ensure the web server can write `ap-config.php` and `ap-content/` (including `uploads/`).  
5. Run **`/install/`** or `php install/cli.php` — then log in at `/ap-admin/`.  
6. Set the site URL to **HTTPS** and terminate TLS on the web server.  
7. Keep `AP_DEBUG` / `AP_DEBUG_DISPLAY` **false** (installer default).  
8. Confirm rewrites: Apache [`.htaccess`](.htaccess) or [`docker/nginx.conf.example`](docker/nginx.conf.example).  
9. Confirm hardening: config, `.env`, and DB files are not downloadable (shipped rules cover common cases).  
10. After install: strong admin password, modules (Pages / Blog / Forum), menus, privacy policy, **Tools → Site Health**.  
11. Optional: **Tools → Import** for WordPress WXR or phpBB content.  
12. Optional: **Tools → Analytics** — opt-in local pageviews (no third-party scripts; retention prune; off by default).

---

## After install

### Admin & site CLI (`ap-cli`)

Manage an installed site from the shell (CLI only — not a remote API):

```bash
php ap-cli --help
php ap-cli version
php ap-cli site health
php ap-cli option get blogname
php ap-cli plugin list
php ap-cli theme list
php ap-cli user list
php ap-cli db check
php ap-cli db migrate
php ap-cli cache flush
php ap-cli rewrite flush
php ap-cli cron event list
php ap-cli core check-update
```

**Content (posts & pages)** — local files only; `--file` rejects URLs:

```bash
php ap-cli post list --type=page
php ap-cli post get --slug=about --type=page
php ap-cli post create --type=page --title="About" --slug=about --file=./about.html
php ap-cli post create --type=post --title="Hello" --file=./hello.html --status=publish
php ap-cli post update --slug=about --type=page --file=./about.html
php ap-cli post update --id=123 --title="New title"
php ap-cli help post
```

Defaults on create: **posts → draft**, **pages → publish**. Update only changes fields you pass (`--title`, `--file`, `--status`, `--name` for rename).

Global flags: `--path=/var/www/site`, `--url=https://example.com`, `--skip-plugins`, `--skip-themes`.  
Exit codes: `0` ok · `1` usage · `2` error · `3` not installed.

### Front-end account links

The default **Agora** theme shows **Log in** for guests (and **Register** when Settings → General allows registration). Logged-in visitors see welcome / profile / log out.

### REST API

JSON at **`/ap-json/`** (pretty permalinks) or `?rest_route=/ap/v1/posts`.  
Namespace `ap/v1` for settings, posts, pages, comments, users, taxonomies, and forums when enabled.  
Auth: session cookie + `X-AP-Nonce`, or HTTP Basic. Disable with option `rest_api_enabled=0`.

### Updates

- Admin **Tools → Update Core** (optional one-click from public `version.json`; no site identity sent)  
- Or deploy a new package / git pull and run `php ap-cli db migrate` if needed  
- Updates **do not** rewrite `ap-config.php`, user uploads/plugins/custom themes, or (from `0.1.4-dev`) the `install/` directory and `ap-config-sample.php` — safe to remove those after a production install  

### Local analytics (opt-in)

**Tools → Analytics** (`manage_options`) shows pageviews today / 7 / 30 days, top paths, top referrers, and a daily table. Collection is **off by default** (`analytics_enabled`). When enabled:

- Server-side only (no front-end JS, no third-party pixels or endpoints)
- Data stays in your site database (`analytics_hits` / `analytics_daily`; prefix `ap_` by default)
- Skips ap-admin, feeds, REST, sitemaps, obvious bots, logged-in admins (`manage_options`), and HTTP `DNT: 1`
- Retention defaults to **90 days** with a daily cron prune

Not related to Hall of Fame or the public version check. Schema and options: [docs/schema.md](docs/schema.md).

---

## Release packaging

Build an installable zip for fresh installs and one-click updates:

```bash
php bin/package-release.php
# or: composer package
# Options: --output-dir=DIR  --version=VER  --dry-run  --json
```

| Output | Purpose |
|--------|---------|
| `dist/AgoraPress-{version}.zip` | Production tree under `AgoraPress/` |
| `dist/AgoraPress-{version}.sha256` | Checksum for `version.json` |
| `dist/version.json` | Public version endpoint payload (version, URLs, sha256, released) |

Excludes tests, vendor, secrets, and runtime uploads. `dist/` is gitignored. Fresh-install assets (`install/`, `ap-config-sample.php`) remain in the zip; the updater simply does not re-apply them onto a live site.

---

## Project layout

```
/
├── index.php                 # Front controller
├── ap-cli                    # Manage an installed site
├── ap-config-sample.php      # Copy → ap-config.php (never commit secrets)
├── install/                  # Web + CLI installer
├── ap-admin/                 # Admin UI
├── ap-includes/              # Core (hooks, editor, forums, WP compatibility/)
├── ap-content/
│   ├── themes/               # Default: agora
│   ├── plugins/
│   ├── mu-plugins/
│   ├── languages/
│   └── uploads/              # Runtime media (not in git)
├── bin/package-release.php
├── docker/ + docker-compose.yml
├── docs/                     # Developer guides
├── tests/
├── CHANGELOG.md
├── LICENSE                   # GPLv2-or-later
└── README.md
```

---

## Development

```bash
composer install          # PHPUnit, PHPCS, PHPStan (dev only)
composer test             # PHPUnit
composer cs:check         # Coding standards
composer analyse          # PHPStan
composer package          # Production zip
# or: pytest tests/ -v
```

CI runs tests, CS, and analysis on PHP 8.2–8.4.  
See [`CODING_STANDARDS.md`](CODING_STANDARDS.md) for style rules.

---

## Developer documentation

Full extension guides live under [`docs/`](docs/README.md):

| Guide | Topic |
|-------|--------|
| [docs/README.md](docs/README.md) | Index, conventions, source map |
| [docs/hooks.md](docs/hooks.md) | Actions, filters, lifecycle |
| [docs/themes.md](docs/themes.md) | Template hierarchy, Agora theme, assets |
| [docs/plugins.md](docs/plugins.md) | Plugin headers, shortcodes, settings |
| [docs/editor.md](docs/editor.md) | Visual editor contract (no blocks in core) |
| [docs/site-icon.md](docs/site-icon.md) | Site icon / favicon pack (admin, sizes, head tags) |
| [docs/compatibility.md](docs/compatibility.md) | Classic WordPress Theme Compatibility Layer |
| [docs/schema.md](docs/schema.md) | Tables, migrations, multi-driver notes |
| [docs/vision-compliance.md](docs/vision-compliance.md) | Principles checklist & intentional deviations |

---

## Security & privacy

- PDO prepared statements; nonces and capability checks on privileged actions  
- Argon2id password hashing  
- **No telemetry** — version check and updates never send your domain or site identity  
- **Local analytics only** — opt-in pageviews for admins; never third-party analytics endpoints or beacons  
- Optional Hall of Fame domain listing is voluntary only (Settings → Hall of Fame)  
- Privacy tools: export / erase personal data under Tools  
- Production hardening: deny web access to `ap-config.php`, `.env`, SQLite/DB files, and raw `ap-includes/` PHP (Apache `.htaccess`, Nginx example)  

---

## License

[GNU General Public License v2.0 or later](LICENSE) (`GPL-2.0-or-later`).

---

## Links

- Site: [agorapress.extrovertednerd.com](https://agorapress.extrovertednerd.com)  
- Source: [github.com/ExtrovertedNerd/AgoraPress](https://github.com/ExtrovertedNerd/AgoraPress)  
- Version endpoint: `https://agorapress.extrovertednerd.com/version.json`  

*AgoraPress — free forever. Publish. Discuss. Own your stack.*
