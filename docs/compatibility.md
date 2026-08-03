# Classic WordPress Theme Compatibility Layer

High-priority differentiator: many **pre-block classic PHP themes** can be uploaded and run on AgoraPress with minimal changes.

**Location:** `ap-includes/compatibility/`

| File | Role |
|------|------|
| `load.php` | Public entry: loads coordinator + converter |
| `class-ap-theme-compat.php` | Mode, shim loading, hook map, block detection, safe `functions.php` |
| `class-ap-theme-converter.php` | Analysis / conversion report |
| `functions-shim.php` | Bare WP function names → AgoraPress |
| `template-tags.php` | Classic loop/template tags |
| `cli-convert.php` | CLI report entry |

## What is in scope

- Classic PHP themes with `style.css` + `index.php` (or child + parent)  
- Common template tags (`the_title`, `get_header`, `have_posts`, …)  
- Common functions (`add_action`, `wp_enqueue_style`, `bloginfo`, …)  
- Hook name mapping (`wp_enqueue_scripts` → `ap_enqueue_scripts`, …)  
- Safe loading of theme `functions.php` when compat is active  
- Screenshots and `style.css` headers  
- Per-theme mode: **auto** | **on** | **off**  
- Conversion / analysis helper (dry-run report; does not rewrite the theme)  

## What is out of scope

- **Block / FSE themes** (`theme.json`, HTML files under `templates/`)  
- Full Gutenberg editor features  
- Every WordPress function ever shipped  
- Plugins that assume a full WP core (post types APIs may differ; prefer native `ap_*`)  

Detected block themes are **never auto-enabled**. Mode `on` can still load shims for partial PHP support only — results are not guaranteed.

## Enabling compatibility

Option map: **`ap_theme_compat_modes`** — `{ "slug": "auto"|"on"|"off", ... }`.

| Mode | Behaviour |
|------|-----------|
| `auto` (default) | Enable for classic non-Agora themes with valid headers; skip Agora; skip block themes |
| `on` | Always load shims for that slug |
| `off` | Never load shims for that slug |

Default **Agora** theme is native and does **not** need WP shims (`auto` keeps them off).

### Procedural helpers

```php
ap_load_theme_compat(bool $force = false, ?AP_DB $db = null): bool
ap_theme_compat_is_active(?AP_DB $db = null): bool
ap_theme_compat_get_mode(string $slug, ?AP_DB $db = null): string
ap_theme_compat_set_mode(string $slug, string $mode, ?AP_DB $db = null): bool
ap_is_block_theme(string $slug): bool
ap_analyze_wp_theme(string $path): array
ap_theme_compat_analyze(string $path): array
ap_theme_compat_report(string $path): string
```

Shims load lazily when a classic theme needs them, or eagerly with `AP_Theme_Compat::ensureLoaded(true)` / `ap_load_theme_compat(true)`.

## Hook name map

When shims are loaded, WP hook names used in `add_action` / `add_filter` / `do_action` / `apply_filters` are rewritten via `AP_Theme_Compat::mapHook()`:

| WordPress | AgoraPress |
|-----------|------------|
| `after_setup_theme` | `ap_after_setup_theme` |
| `wp_enqueue_scripts` | `ap_enqueue_scripts` |
| `wp_head` | `ap_head` |
| `wp_footer` | `ap_footer` |
| `wp_print_styles` | `ap_print_styles` |
| `wp_print_scripts` | `ap_print_scripts` |
| `init` | `ap_init` |
| `widgets_init` | `ap_widgets_init` |
| `template_redirect` | `ap_template_redirect` |
| `wp` | `ap_wp` |
| `the_content` | `ap_the_content` |
| `body_class` | `ap_body_class` |
| `post_class` | `ap_post_class` |
| `excerpt_length` | `ap_excerpt_length` |
| `excerpt_more` | `ap_excerpt_more` |
| `nav_menu_css_class` | `ap_nav_menu_css_class` |
| `wp_nav_menu_args` | `ap_nav_menu_args` |

Unmapped names pass through unchanged (they will only fire if something explicitly calls them).

## Shimmed symbols (representative)

The converter reports against a known list. Common shims include:

**Hooks:** `add_action`, `add_filter`, `do_action`, `apply_filters`, `remove_action`, `remove_filter`, `has_action`, `has_filter`

**Templates:** `get_header`, `get_footer`, `get_sidebar`, `get_template_part`, `locate_template`

**Loop / content:** `have_posts`, `the_post`, `the_title`, `the_content`, `the_excerpt`, `the_permalink`, `body_class`, `post_class`

**Conditionals:** `is_home`, `is_single`, `is_page`, `is_archive`, `is_search`, `is_404`, …

**Assets / head:** `wp_enqueue_style`, `wp_enqueue_script`, `wp_head`, `wp_footer`

**Paths / site:** `get_stylesheet_directory`, `get_template_directory`, `get_stylesheet_uri`, `bloginfo`, `home_url`

**Chrome:** `register_nav_menus`, `register_sidebar`, `add_theme_support`

**Escaping / sanitize (extended):** `esc_html`, `esc_attr`, `esc_url`, `esc_js`, `esc_xml`, `sanitize_text_field`, `sanitize_email`, `absint`, …

Native AgoraPress themes and plugins should prefer **`ap_*`** names so they work whether or not shims are loaded.

## Safe functions.php loading

When compat is active, `AP_Theme::setup()` loads parent then child `functions.php` via `AP_Theme_Compat::safeLoadFunctionsPhp()` instead of a bare `require_once`. This reduces the chance that a hard failure in a legacy theme takes down the entire site.

## Uploading classic themes

Admin **Appearance → Themes** accepts zip uploads (`AP_Theme_Installer`):

1. Package must contain `style.css` with Theme Name  
2. Parent themes need `index.php`  
3. Block/FSE packages are rejected by default  
4. After install, activate the theme; with mode `auto`, shims load if the theme looks classic  

## Conversion helper (CLI)

Dry-run analysis only — **does not modify files**.

```bash
php ap-includes/compatibility/cli-convert.php /path/to/theme
php ap-includes/compatibility/cli-convert.php --path=/path/to/theme --json
```

Exit codes:

| Code | Meaning |
|------|---------|
| 0 | Classic theme structure supported |
| 1 | Usage / help without path |
| 2 | Path missing |
| 3 | Block / FSE theme |
| 4 | Classic structure incomplete / unsupported |

The report covers headers, screenshots, common templates present/missing, shimmed vs unshimmed symbols found in PHP, a rough score, and conversion tips toward native `ap_*` APIs.

Programmatic:

```php
$analysis = ap_analyze_wp_theme('/path/to/theme');
echo ap_theme_compat_report('/path/to/theme');
```

## Limitations & tips for theme authors

1. Prefer **`ap_add_action('ap_enqueue_scripts', …)`** in new code; shims are a bridge, not the long-term API.  
2. Avoid WordPress-only APIs with no AgoraPress equivalent (many REST/block APIs, Multisite, etc.).  
3. Query and post objects are AgoraPress classes (`AP_Query`, `AP_Post`); shims map common template usage, not every WP global.  
4. Database table prefix is **`ap_` by default**, not `wp_`. Direct SQL against `wp_*` tables will fail.  
5. Test with the default **Agora** theme first, then activate the classic theme and check the conversion report.  
6. Child themes of classic parents work when `Template:` points at an installed parent.

## Related

- [Theme hierarchy](themes.md) — native loader behaviour  
- [Hooks](hooks.md) — full action/filter API  
- [Plugin API](plugins.md) — plugins always use `ap_*` (no bare WP plugin runtime)  
