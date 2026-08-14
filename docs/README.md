# AgoraPress Developer Documentation

Guides for extending and operating AgoraPress: plugins, themes, hooks, schema, and the Classic WordPress Theme Compatibility Layer.

AgoraPress is a **clean rewrite** inspired by classic WordPress (not a fork). The public API uses the `ap_` prefix. The [Classic WordPress Theme Compatibility Layer](compatibility.md) optionally exposes many bare WordPress names for classic PHP themes.

**Current core:** `AP_VERSION` **0.3.5-beta** · schema `AP_DB_VERSION` **12** · product status: MVP feature-complete with Site Icon (favicon pack), plugin admin pages (ACP registry + router), forum UI phpBB-parity, forum likes/moderation, Theme Options API, and local opt-in analytics; ready for live-site install.

## Guides

| Guide | What it covers |
|-------|----------------|
| [Hooks](hooks.md) | Actions, filters, priorities, lifecycle hooks |
| [Theme hierarchy](themes.md) | Template files, child themes, Agora defaults, assets, template tags |
| [Plugin API](plugins.md) | Headers, activation, MU-plugins, shortcodes, settings, ACP admin pages, REST registration |
| [Visual editor](editor.md) | Lightweight visual WYSIWYG (no block editor in core) |
| [Site icon (favicon)](site-icon.md) | Favicon pack generation, option, head tags, passive root fallback |
| [Compatibility layer](compatibility.md) | WP shims, hook maps, conversion CLI, limitations |
| [Database schema](schema.md) | Tables, migrations, prefix, multi-driver notes |
| [Vision compliance](vision-compliance.md) | Principles checklist, intentional deviations, test guards |

## Quick mental model

```
Request
  → bootstrap (config, DB, roles, options, …)
  → MU plugins → active plugins
  → ap_plugins_loaded → ap_loaded
  → rewrite / query (front) or admin bootstrap
  → theme setup (functions.php) → ap_after_setup_theme
  → template hierarchy → locate → render
     (themes call ap_enqueue_scripts, ap_head, ap_footer)
```

CLI (`php ap-cli …`) boots the same core for installed sites, then dispatches built-in or plugin-registered commands (`ap_cli_init`).

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
| REST | `AP_Rest` → `/ap-json/` namespace `ap/v1` |
| CLI | `ap-cli` → option, plugin, theme, user, **post**, db, cache, cron, rewrite, site, core |
| Analytics | `AP_Analytics` → Tools → Analytics (`ap-admin/analytics.php`); opt-in, local DB only |
| Compat | `ap-includes/compatibility/` |
| Updates | `AP_Version_Check`, `AP_Core_Updater` (no site identity) |

## Conventions

- **Prefix:** Core functions and hooks use `ap_`. Options and table bases are unprefixed names; the DB layer adds the site table prefix (default `ap_`).
- **Strict types:** Core ships with `declare(strict_types=1)`. Match that in new plugins when practical.
- **Security:** Prepared statements only; nonces on state-changing forms; capability checks; escape on output (`ap_esc_html`, `ap_esc_attr`, …) and sanitize on input (`ap_sanitize_text_field`, …).
- **No telemetry by default:** Version checks never send site identity. Do not add phone-home behaviour in core-adjacent plugins without an explicit opt-in.
- **Local analytics (optional):** `AP_Analytics` records public pageviews only when `analytics_enabled` is on (default **off**). Data never leaves the site database. Not Hall of Fame and not version-check traffic.
- **Modules:** Static Pages, Blog, and Forum can be toggled independently. Check options / `AP_Options::isModuleEnabled()` before assuming a module is on.
- **Vision fidelity:** Before large features, re-read [vision-compliance.md](vision-compliance.md). Free forever, lightweight, and privacy defaults are non-negotiable.

## Related product docs

- [README.md](../README.md) — install, Docker, CLI, REST, production checklist  
- [CODING_STANDARDS.md](../CODING_STANDARDS.md) — PSR-12 adapted style  
- [CHANGELOG.md](../CHANGELOG.md) — notable changes  

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
| Schema | `ap-includes/schema/migrations/`, `class-ap-migrator.php` |
| Default theme | `ap-content/themes/agora/` |
| Forums | `class-ap-forum*.php`, `class-ap-forum-front.php`, `class-ap-forum-like.php`, `class-ap-forum-stats.php`, migration `0011_forum_likes_stats.php` |
| REST | `class-ap-rest.php` |
| Install / update | `install/`, `class-ap-installer.php`, `class-ap-core-updater.php` |
| Analytics | `class-ap-analytics.php`, `ap-admin/analytics.php`, `ap-admin/includes/class-ap-admin-analytics.php`, migration `0010_analytics_tables.php` |
| Site icon / favicon | `class-ap-media.php` (pack + head tags), `class-ap-options.php` (`site_icon`), `ap-admin/options-general.php` |

---

*AgoraPress — free forever. Publish. Discuss. Own your stack.*
