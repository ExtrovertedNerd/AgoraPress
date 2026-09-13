# AgoraPress documentation index

This directory is the **operator, integrator, and trusted-agent documentation**
for AgoraPress **`0.3.8-beta`** (schema `AP_DB_VERSION` **12**). Start here if
you need exhaustive instructions, then follow the topic guides for depth.

The human landing page (vision, requirements, four install paths) remains
[`../README.md`](../README.md). **This file is the only docs index.** There is
**one documentation tree**. Do not create or look for `docs/index.md` — it is
not part of the tree.

Product status: MVP feature-complete (Site Icon, plugin ACP pages, forum UI
phpBB-parity, forum likes/moderation, Theme Options, local opt-in analytics);
ready for live-site install.

AgoraPress is a **clean rewrite** inspired by classic WordPress (not a fork).
The public API uses the `ap_` prefix. The
[Classic WordPress Theme Compatibility Layer](compatibility.md) optionally
exposes many bare WordPress names for classic PHP themes.

| Start here | Purpose |
|------------|---------|
| [../README.md](../README.md) | Human landing: vision, requirements, Docker / web / CLI / manual install |
| [install.md](install.md) | Installer depth: web, CLI flags, Docker, permissions, post-install checklist |
| [features_and_functions.md](features_and_functions.md) | Lookup catalog of tables (does this option / command / screen exist?) |
| [bot_handbook.md](bot_handbook.md) | Trusted-agent operating model for *this* product |
| `php ap-cli --help` / `php ap-cli COMMAND --help` | Live CLI help for an installed site |

A trusted scripted agent (Grok Bot) that can only read this **public**
repository should start at this index, then [bot_handbook.md](bot_handbook.md).
Do not invent hooks, routes, CLI commands, options, capabilities, tables, or
admin screens. If a surface is not in these guides and not in the shipped
code, say it is **not in core**.

**Sources (as built):** topic guides in this directory, shipped PHP under
`ap-includes/` / `ap-admin/` / `install/`, [`ap-cli`](cli.md),
[`.htaccess`](../.htaccess),
[`docker/nginx.conf.example`](../docker/nginx.conf.example).

---

## Public-safe rule

This repository is **public**. Everything under `docs/` and the root
`README.md` is readable by the world.

**Never write** into these docs:

- Private host paths, service names, php-fpm pool names, or vhost files
- Live site inventory (which domains run AgoraPress vs other apps)
- Persona display names or mailboxes as if they were product facts
- Credentials, app passwords, API keys, or salts
- Private add-on theme/plugin internals
- Internal forge, mail-server, or other-product runbook material

**May write:**

- The already-public product site
  [https://agorapress.extrovertednerd.com](https://agorapress.extrovertednerd.com)
  and its `version.json`
- Generic examples: `example.com`, `https://your-domain.example`,
  `admin@example.com`, `noreply@example.com`, `smtp.example.com`,
  `localhost`
- `/var/www/agorapress` (already used in shipped `docker/nginx.conf.example`)
- Generic mechanisms, stated without a private install: nginx `try_files`,
  PHP `session.save_path` must be writable by the php-fpm user, permalinks
  need a front controller

If a fact is only true of one private install, document the **mechanism**, not
the install.

Trusted agents: the same public-safe rule is restated in
[bot_handbook.md](bot_handbook.md).

---

## By audience

### New operators

1. [README — vision and requirements](../README.md#vision-summary)
2. [README — quick start](../README.md#quick-start) (Docker, web installer, CLI installer, manual `ap-config-sample.php`)
3. [install.md](install.md) — permissions, post-install checklist (modules, permalinks, Site Health, analytics opt-in)
4. [rewrites.md](rewrites.md) — front controller, Apache `.htaccess`, nginx `try_files $uri $uri/ /index.php?$args`, pretty permalinks
5. [security.md](security.md) — prepared statements, nonces, deny rules, no telemetry
6. Log in at `/ap-admin/` and run **Tools → Site Health**

### Day-to-day operators

| Task | Doc |
|------|-----|
| Install or reinstall | [install.md](install.md) · [../README.md](../README.md) |
| Pretty permalinks / 404 on `/slug/` | [rewrites.md](rewrites.md) · [troubleshooting.md](troubleshooting.md) |
| Admin Control Panel (`/ap-admin/`) | [admin.md](admin.md) |
| `php ap-cli …` | [cli.md](cli.md) |
| Core updates (`version.json`, Tools → Update Core, `db migrate`) | [updates.md](updates.md) |
| Forums (hierarchy, likes, moderation, ACL, PMs) | [forums.md](forums.md) |
| Roles and capabilities | [roles.md](roles.md) |
| REST (`/ap-json/`, `ap/v1`, `rest_api_enabled`) | [rest.md](rest.md) |
| Toggle REST (`rest_api_enabled` — **no** ACP screen) | [cli.md](cli.md) · [rest.md](rest.md) |
| Plugin / theme zip installers | [admin.md](admin.md) · [plugins.md](plugins.md) · [themes.md](themes.md) |
| Hall of Fame (voluntary) | [admin.md](admin.md) |
| Privacy export / erase | [admin.md](admin.md) · [security.md](security.md) |
| Harden the host | [security.md](security.md) |
| Symptom → check | [troubleshooting.md](troubleshooting.md) |
| Local analytics (opt-in, default **off**, `analytics_enabled`) | [admin.md](admin.md) · [schema.md](schema.md) |
| Lookup “does this exist?” | [features_and_functions.md](features_and_functions.md) |

### Theme & plugin authors

| Task | Doc |
|------|-----|
| Template hierarchy, Agora theme, Theme Options, assets | [themes.md](themes.md) |
| Plugin headers, MU-plugins, shortcodes, Settings API, zip installer | [plugins.md](plugins.md) |
| ACP pages (`ap_register_admin_page`) | [plugins.md](plugins.md) · [admin.md](admin.md) |
| Actions, filters, lifecycle | [hooks.md](hooks.md) |
| Visual editor contract (no blocks in core; dark `color-scheme` chrome) | [editor.md](editor.md) |
| Site icon / favicon pack | [site-icon.md](site-icon.md) |
| Classic WordPress theme shim (block/FSE out of scope) | [compatibility.md](compatibility.md) |
| REST registration (`ap_rest_api_init`) | [rest.md](rest.md) · [plugins.md](plugins.md) |
| CLI registration (`ap_cli_init`) | [cli.md](cli.md) · [plugins.md](plugins.md) |

### Trusted agent (Grok Bot)

Read **this index first**. Follow the audience tables. Then use the topic
guides and the lookup catalog. Do **not** invent product surfaces.

| Task | Doc |
|------|-----|
| Operating model (public-safe, do not invent, diagnose, file, close the loop) | [bot_handbook.md](bot_handbook.md) |
| “Does this option / command / screen / table exist?” | [features_and_functions.md](features_and_functions.md) |
| Common failures | [troubleshooting.md](troubleshooting.md) |
| Install / rewrites / CLI / admin / forums / REST / roles / security | The day-to-day table above |
| File a Heph bug against registry name **AgoraPress** | [bot_handbook.md](bot_handbook.md) (short pointer to Heph Agent API — do not duplicate that contract here) |

Describe the system **as built** at 0.3.8-beta / schema 12. Missing from these
guides and from core means **not in core**. Stay inside the public-safe rule
above.

### Developers

| Topic | Doc |
|-------|-----|
| Tables, migrations, prefix, multi-driver | [schema.md](schema.md) |
| Principles checklist and intentional deviations | [vision-compliance.md](vision-compliance.md) |
| PSR-12 adapted style | [../CODING_STANDARDS.md](../CODING_STANDARDS.md) |
| Package / source map | [Source map](#source-map) below |
| Request lifecycle | [Quick mental model](#quick-mental-model) below |
| Tests | `composer test` / `pytest tests/` — see [../README.md](../README.md#development) |

---

## Topic guides (complete list)

| Document | Covers |
|----------|--------|
| [install.md](install.md) | Web installer (`/install/`), `php install/cli.php`, Docker, manual config, permissions, post-install |
| [updates.md](updates.md) | `version.json`, Tools → Update Core, `package-release.php`, what an update does **not** overwrite |
| [rewrites.md](rewrites.md) | Front controller, `.htaccess`, nginx `try_files $uri $uri/ /index.php?$args`, pretty vs `?p=`, `ap-cli rewrite flush` |
| [cli.md](cli.md) | Every built-in `ap-cli` command group, global flags, exit codes |
| [admin.md](admin.md) | `/ap-admin/` as built (screens by task, zip installer, Hall of Fame) |
| [forums.md](forums.md) | Hierarchy, topic types, likes, moderation, ACL, PMs, search, flood, module-off |
| [roles.md](roles.md) | Roles, caps, comment ownership, forum ACL relationship |
| [rest.md](rest.md) | `/ap-json/`, `ap/v1` resources as built, cookie + `X-AP-Nonce` or Basic, `rest_api_enabled` |
| [security.md](security.md) | Prepared statements, nonces, Argon2id, deny rules, no telemetry, privacy export/erase |
| [troubleshooting.md](troubleshooting.md) | Symptom → check → related path |
| [bot_handbook.md](bot_handbook.md) | How a trusted agent supports AgoraPress from this public repo |
| [features_and_functions.md](features_and_functions.md) | Lookup catalog of tables pointing at the guides above |
| [hooks.md](hooks.md) | Selected actions, filters, priorities, lifecycle (grep core for the rest) |
| [themes.md](themes.md) | Template files, child themes, Agora defaults, assets, Theme Options |
| [plugins.md](plugins.md) | Headers, activation, MU-plugins, shortcodes, settings, ACP admin pages, REST registration |
| [editor.md](editor.md) | Lightweight visual WYSIWYG, contrast contract (no block editor in core) |
| [site-icon.md](site-icon.md) | Favicon pack generation, option, head tags, passive root fallback |
| [compatibility.md](compatibility.md) | WP shims, hook maps, conversion CLI, limitations |
| [schema.md](schema.md) | Tables, migrations, prefix, multi-driver notes |
| [vision-compliance.md](vision-compliance.md) | Principles checklist, intentional deviations, test guards |

---

## Quick mental model

```
Request
  → web server (static file, or front controller index.php)
  → bootstrap (config, DB, roles, options, …)
  → MU plugins → active plugins
  → ap_plugins_loaded → ap_loaded
  → rewrite / query (front) or admin bootstrap
  → theme setup (functions.php) → ap_after_setup_theme
  → template hierarchy → locate → render
     (themes call ap_enqueue_scripts, ap_head, ap_footer)
```

Pretty permalinks need that front controller (`try_files` on nginx, shipped
`.htaccess` on Apache). Missing `try_files` 404s `/slug/` while `?p=` still
works — [rewrites.md](rewrites.md).

CLI (`php ap-cli …`) boots the same core for installed sites, then dispatches
built-in or plugin-registered commands (`ap_cli_init`). Fresh install is a
**separate** tool: `php install/cli.php` (or the web installer at `/install/`).

---

## Feature map (for integrators)

| Surface | Entry points |
|---------|----------------|
| Options / Settings | `AP_Options`, Settings API (`ap_register_setting`, …) |
| Posts / pages | `AP_Post`, `AP_Query`, admin screens, `ap-cli post` |
| Media | `AP_Media`, uploads under `ap-content/uploads/` |
| Site icon | Option `site_icon` + favicon pack — see [site-icon.md](site-icon.md) |
| Comments | `AP_Comment`, discussion settings |
| Forums | `AP_Forum*`, `AP_Forum_Like`, `AP_Forum_Stats`, dedicated tables (see [schema](schema.md)) |
| Themes | `AP_Theme` (hierarchy, theme_mods, Theme Options), default `ap-content/themes/agora/` |
| Plugins | `AP_Plugin`, `ap-content/plugins/`, `mu-plugins/`, ACP pages via `ap_register_admin_page` |
| REST | `AP_Rest` → `/ap-json/` namespace `ap/v1`. Writes exist **only** on posts; other builtins are GET. `rest_api_enabled` has **no** ACP screen (CLI / option). |
| CLI | `ap-cli` → help, version, cli, core, db, option, plugin, theme, user, post, cache, cron, rewrite, site |
| Analytics | `AP_Analytics` → Tools → Analytics (`ap-admin/analytics.php`); opt-in (`analytics_enabled`), local DB only |
| Compat | `ap-includes/compatibility/` |
| Updates | `AP_Version_Check`, `AP_Core_Updater` (no site identity) |
| Roles | `AP_Roles` — five builtins: administrator, editor, author, contributor, subscriber |

---

## Quick command map

Global flags (every group): `--path`, `--url`, `--skip-plugins`, `--skip-themes`,
`-h` / `--help`, `-V` / `--version`. Exit codes and defaults: **[cli.md](cli.md)**.

```text
php install/cli.php …          # fresh install (not ap-cli)

php ap-cli help [<command>]    # no install
php ap-cli version             # no install; also -V / --version
php ap-cli cli info            # no install
php ap-cli core {version|check-update}
php ap-cli db {check|migrate}
php ap-cli option {get|set|delete|list}
php ap-cli plugin {list|activate|deactivate}
php ap-cli theme {list|activate}
php ap-cli user {list|get|create}
php ap-cli post {list|get|create|update}
php ap-cli cache flush
php ap-cli cron event {list|run}
php ap-cli rewrite flush
php ap-cli site health
```

`post --file` is **local filesystem only** (rejects URLs). Create defaults:
posts → **draft**, pages → **publish**.

Plugins may add commands on `ap_cli_init` — those are not core verbs.

---

## Not in core

If a surface is missing from the catalog and from shipped PHP, it is **not in
core**. Do not invent it in answers or in new docs.

| Surface | See |
|---------|-----|
| Gutenberg / FSE / block themes on the compat layer | [editor.md](editor.md) · [compatibility.md](compatibility.md) |
| Official SaaS, paid marketplace, telemetry, PHP older than 8.2, multisite, e-commerce | [vision-compliance.md](vision-compliance.md) |
| REST writes for pages, comments, users, categories, tags, forums, topics | [rest.md](rest.md) |
| ACP screens for `rest_api_enabled`, `blog_public`, `sitemap_enabled`, `open_graph_enabled`, `version_check_enabled` | [cli.md](cli.md) · [rest.md](rest.md) |
| `plugin install` / `theme install` / `user update` / `user delete` / `post delete` / `core update` / `db rollback` / `rewrite list` | [cli.md](cli.md) |
| Extra core roles beyond administrator / editor / author / contributor / subscriber | [roles.md](roles.md) |
| Extra topic types beyond `standard` \| `sticky` \| `announcement` \| `rules` | [forums.md](forums.md) |
| `php ap-cli module` / `php ap-cli forum` | Modules and forums toggle via **Settings → Modules** or `php ap-cli option` |
| A second docs index (`docs/index.md`) or a Bot-only tree | This file |

---

## Conventions

- **Prefix:** Core functions and hooks use `ap_`. Options and table bases are unprefixed names; the DB layer adds the site table prefix (default `ap_`).
- **Strict types:** Core ships with `declare(strict_types=1)`. Match that in new plugins when practical.
- **Security:** Prepared statements only; nonces on state-changing forms; capability checks; escape on output (`ap_esc_html`, `ap_esc_attr`, …) and sanitize on input (`ap_sanitize_text_field`, …).
- **No telemetry by default:** Version checks never send site identity. Do not add phone-home behaviour in core-adjacent plugins without an explicit opt-in.
- **Local analytics (optional):** `AP_Analytics` records public pageviews only when `analytics_enabled` is on (default **off**). Data never leaves the site database. Not Hall of Fame and not version-check traffic.
- **Modules:** Static Pages, Blog, and Forum can be toggled independently. Check options / `AP_Options::isModuleEnabled()` before assuming a module is on.
- **Vision fidelity:** Before large features, re-read [vision-compliance.md](vision-compliance.md). Free forever, lightweight, and privacy defaults are non-negotiable.

---

## Related product docs

- [README.md](../README.md) — install, Docker, CLI, REST, production checklist
- [CODING_STANDARDS.md](../CODING_STANDARDS.md) — PSR-12 adapted style
- [CHANGELOG.md](../CHANGELOG.md) — notable changes

---

## Source map

| Area | Primary files |
|------|----------------|
| Hooks | `ap-includes/hooks.php`, `class-ap-hook.php`, `class-ap-hooks.php` |
| Themes | `ap-includes/class-ap-theme.php` (hierarchy, theme_mods, Theme Options), `template-tags.php`, `class-ap-assets.php` |
| Plugins | `ap-includes/class-ap-plugin.php`, `class-ap-plugin-installer.php`, procedural helpers in `functions.php` |
| Plugin admin pages | `class-ap-admin-menu.php`, `ap-admin/admin.php`, `AP_Admin::pageUrl()` |
| Posts / CLI content | `class-ap-post.php`, `class-ap-cli.php` (`cmdPost`) |
| Visual editor | `ap-includes/class-ap-editor.php`, `css/ap-editor.css`, `js/ap-editor.js` |
| Compatibility | `ap-includes/compatibility/` |
| Schema | `ap-includes/schema/migrations/` (through `0012_topic_type_enum.php`), `class-ap-migrator.php` |
| Default theme | `ap-content/themes/agora/` |
| Forums | `class-ap-forum*.php`, `class-ap-forum-front.php`, `class-ap-forum-like.php`, `class-ap-forum-stats.php`, migration `0011_forum_likes_stats.php` |
| REST | `class-ap-rest.php` |
| Roles | `class-ap-roles.php` |
| Rewrites | `class-ap-rewrite.php`, root `.htaccess`, `docker/nginx.conf.example` |
| Install / update | `install/`, `class-ap-installer.php`, `class-ap-cli-install.php`, `class-ap-core-updater.php` |
| Analytics | `class-ap-analytics.php`, `ap-admin/analytics.php`, `ap-admin/includes/class-ap-admin-analytics.php`, migration `0010_analytics_tables.php` |
| Site icon / favicon | `class-ap-media.php` (pack + head tags), `class-ap-options.php` (`site_icon`), `ap-admin/options-general.php` |
| Mail | `class-ap-mail.php`, `class-ap-smtp.php`, Settings → Mail (`ap-admin/options-mail.php`); classic-compat `wp_mail()` in `compatibility/functions-shim.php` |

---

*AgoraPress — free forever. Publish. Discuss. Own your stack.*
