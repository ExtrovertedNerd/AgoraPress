# AgoraPress Developer Documentation

Guides for extending AgoraPress with plugins, themes, hooks, and database knowledge.

AgoraPress is a **clean rewrite** inspired by classic WordPress (not a fork). The public API uses the `ap_` prefix. The [Classic WordPress Theme Compatibility Layer](compatibility.md) optionally exposes many bare WordPress names for classic PHP themes.

## Guides

| Guide | What it covers |
|-------|----------------|
| [Hooks](hooks.md) | Actions, filters, priorities, lifecycle hooks |
| [Theme hierarchy](themes.md) | Template files, child themes, assets, template tags |
| [Plugin API](plugins.md) | Headers, activation, MU-plugins, shortcodes, settings |
| [Visual editor](editor.md) | Lightweight visual WYSIWYG (no block editor in core) |
| [Compatibility layer](compatibility.md) | WP shims, hook maps, conversion CLI, limitations |
| [Database schema](schema.md) | Tables, migrations, prefix, multi-driver notes |
| [Vision compliance](vision-compliance.md) | Constitution reevaluation, principles checklist, intentional deviations |

## Quick mental model

```
Request
  → bootstrap (config, DB, roles, options, …)
  → MU plugins → active plugins
  → ap_loaded
  → rewrite / query (front) or admin bootstrap
  → theme setup (functions.php) → ap_after_setup_theme
  → template hierarchy → locate → render
```

## Conventions

- **Prefix:** Core functions and hooks use `ap_`. Options and table bases are unprefixed names; the DB layer adds the site table prefix (default `ap_`).
- **Strict types:** Core ships with `declare(strict_types=1)`. Match that in new plugins when practical.
- **Security:** Prepared statements only; nonces on state-changing forms; capability checks; escape on output (`ap_esc_html`, `ap_esc_attr`, …) and sanitize on input (`ap_sanitize_text_field`, …).
- **No telemetry by default:** Version checks never send site identity. Do not add phone-home behaviour in core-adjacent plugins without an explicit opt-in.
- **Modules:** Static Pages, Blog, and Forum can be toggled independently. Check options / `AP_Options::isModuleEnabled()` before assuming a module is on.
- **Vision fidelity:** Before large features, re-read [vision-compliance.md](vision-compliance.md). Free forever, lightweight, and privacy defaults are non-negotiable.

## Related product docs

- [README.md](../README.md) — install, Docker, CLI, REST overview  
- [CODING_STANDARDS.md](../CODING_STANDARDS.md) — PSR-12 adapted style  
- [CHANGELOG.md](../CHANGELOG.md) — notable changes  

## Source map

| Area | Primary files |
|------|----------------|
| Hooks | `ap-includes/hooks.php`, `class-ap-hook.php`, `class-ap-hooks.php` |
| Themes | `ap-includes/class-ap-theme.php`, `template-tags.php`, `class-ap-assets.php` |
| Plugins | `ap-includes/class-ap-plugin.php`, procedural helpers in `functions.php` |
| Visual editor | `ap-includes/class-ap-editor.php`, `css/ap-editor.css`, `js/ap-editor.js` |
| Compatibility | `ap-includes/compatibility/` |
| Schema | `ap-includes/schema/migrations/`, `class-ap-migrator.php` |
| Default theme | `ap-content/themes/agora/` |

---

*AgoraPress — free forever. Publish. Discuss. Own your stack.*
