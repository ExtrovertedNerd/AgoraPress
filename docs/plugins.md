# Plugin API

Plugins extend AgoraPress via headers, activation hooks, and the [hook system](hooks.md). Layout and headers are deliberately familiar to classic WordPress plugin authors.

**Source:** `ap-includes/class-ap-plugin.php`  
**Procedural API:** `ap-includes/functions.php`  
**Directories:** `ap-content/plugins/`, `ap-content/mu-plugins/`

## Layout

Two basenames are supported (WordPress-style):

```
ap-content/plugins/
├── hello.php                    # Single-file plugin
└── my-plugin/
    └── my-plugin.php            # Folder plugin (main file at one level)
```

Basenames:

- Single file: `hello.php`  
- Folder: `my-plugin/my-plugin.php`  

Active basenames are stored as a JSON list in option **`active_plugins`**.

## Plugin headers

Place a standard header comment at the top of the main PHP file. **Plugin Name** is required.

```php
<?php
/**
 * Plugin Name: Example Plugin
 * Plugin URI:  https://example.com/plugins/example
 * Description: Demonstrates the AgoraPress plugin API.
 * Version:     1.0.0
 * Author:      Example Author
 * Author URI:  https://example.com
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: example-plugin
 * Domain Path: /languages
 * Requires PHP: 8.2
 * Requires at least: 0.1.0
 */
```

Known fields (filterable via `ap_plugin_header_fields`):

`Plugin Name`, `Plugin URI`, `Description`, `Version`, `Author`, `Author URI`, `License`, `License URI`, `Text Domain`, `Domain Path`, `Network`, `Requires at least`, `Requires PHP`, `Requires Plugins`, `Update URI`

## Load order

During bootstrap (`ap-includes/bootstrap.php`):

1. Core classes and Settings / Shortcode / Widgets registration  
2. **Must-use plugins** (`ap-content/mu-plugins/`) — always loaded  
3. **Active plugins** (unless `AP_CLI_SKIP_PLUGINS` is set)  
4. Action **`ap_plugins_loaded`**  
5. Pseudo-cron (not on CLI)  
6. Action **`ap_loaded`**

MU plugins load from the top-level of `mu-plugins/` (PHP files). They cannot be deactivated from the admin Plugins screen.

## Procedural API

```php
ap_get_plugins_dir(): string
ap_get_plugins(): array                         // basename => headers
ap_get_plugin_data(string $plugin): ?array
ap_get_active_plugins(?AP_DB $db = null): array
ap_is_plugin_active(string $plugin, ?AP_DB $db = null): bool
ap_activate_plugin(string $plugin, ?AP_DB $db = null): array{ok: bool, errors: list<string>}
ap_deactivate_plugin(string $plugin, ?AP_DB $db = null): array{ok: bool, errors: list<string>}
ap_plugin_basename(string $file): string
ap_plugin_path(string $plugin): string
ap_plugin_dir(string $plugin): string
ap_plugin_url(string $plugin, string $path = '', ?AP_DB $db = null): string
ap_register_activation_hook(string $file, callable $callback): void
ap_register_deactivation_hook(string $file, callable $callback): void
ap_install_plugin_from_zip(string $zipPath, array $args = []): array
ap_upload_plugin(array $file, array $args = []): array
ap_delete_plugin(string $plugin, ?AP_DB $db = null): array
```

Activation includes the main file, runs registered activation callbacks, then updates `active_plugins` and fires related actions (`ap_activate_plugin`, …). Deactivation runs deactivation hooks then removes the basename from the list.

## Minimal plugin example

```php
<?php
/**
 * Plugin Name: Site Notice
 * Description: Adds a footer notice via hooks.
 * Version: 1.0.0
 */

declare(strict_types=1);

if (!defined('AP_ABSPATH')) {
    exit;
}

ap_register_activation_hook(__FILE__, static function (): void {
    ap_add_option('site_notice_text', 'Hello from Site Notice');
});

ap_register_deactivation_hook(__FILE__, static function (): void {
    // Optional cleanup; often leave options for reactivation.
});

ap_add_action('ap_footer', static function (): void {
    $text = (string) ap_get_option('site_notice_text', '');
    if ($text === '') {
        return;
    }
    echo '<p class="site-notice">' . ap_esc_html($text) . '</p>';
}, 50);
```

Activate: Admin → Plugins, or:

```bash
php ap-cli plugin list
php ap-cli plugin activate site-notice/site-notice.php
# or single-file:
php ap-cli plugin activate hello.php
php ap-cli plugin deactivate hello.php
```

Content automation (core CLI, not a plugin API) uses local files only:

```bash
php ap-cli post create --type=page --title="About" --file=./about.html
php ap-cli help post
```

## Shortcodes

```php
ap_add_shortcode('hello', static function (array $atts = []): string {
    $name = ap_sanitize_text_field((string) ($atts['name'] ?? 'world'));
    return 'Hello, ' . ap_esc_html($name);
});
```

Core registers built-in shortcodes and expands them on the `ap_the_content` filter (`AP_Shortcode`). API: `ap_add_shortcode` / `ap_remove_shortcode` / `ap_do_shortcode` (see `class-ap-shortcode.php`).

## Settings API

Register options that appear on admin settings screens:

```php
ap_add_action('ap_loaded', static function (): void {
    ap_register_setting('myplugin', 'myplugin_option', [
        'type' => 'string',
        'default' => '',
        'sanitize_callback' => 'ap_sanitize_text_field',
    ]);
    ap_add_settings_section('myplugin_main', 'Main', null, 'myplugin');
    ap_add_settings_field(
        'myplugin_option',
        'Label',
        static function (): void {
            $v = (string) ap_get_option('myplugin_option', '');
            echo '<input name="myplugin_option" value="' . ap_esc_attr($v) . '" />';
        },
        'myplugin',
        'myplugin_main'
    );
});
```

Core option groups: general, modules, writing, reading, discussion, media, permalink (and others as features land). Use nonces and capability checks on any custom admin form.

## Options, transients, cron

```php
ap_get_option / ap_update_option / ap_add_option / ap_delete_option
ap_set_transient / ap_get_transient / ap_delete_transient
// Cron: AP_Cron + ap_schedule_event-style helpers (see class-ap-cron.php)
```

Object cache: `ap_cache_get` / `ap_cache_set` / … with optional drop-in under `ap-content/object-cache.php`.

## REST routes

```php
ap_add_action('ap_rest_api_init', static function (): void {
    ap_register_rest_route('myplugin/v1', '/ping', [
        'methods' => 'GET',
        'callback' => static function (): array {
            return ['ok' => true];
        },
        'permission_callback' => '__return_true',
    ]);
});
```

Base: `/ap-json/` or `?rest_route=/…`. See README for core `ap/v1` routes.

## CLI commands

```php
ap_add_action('ap_cli_init', static function (): void {
    AP_Cli::addCommand('myplugin hello', static function (array $args): int {
        echo "Hello\n";
        return 0;
    });
});
```

## Widgets

```php
ap_register_widget('my_text', [/* widget class or definition */]);
// Themes: ap_register_sidebar / ap_dynamic_sidebar
```

Built-ins: Text, Recent Posts, Categories, Search, Pages, Navigation Menu.

## Security checklist for plugin authors

1. **Capability checks** on every privileged admin/REST action (`ap_current_user_can`)  
2. **Nonces** on state-changing forms (`ap_nonce_field` / `ap_verify_request_nonce`)  
3. **Escape output**; sanitize input  
4. **Prepared statements** via `$apdb` / `AP_DB` only — never concatenate user input into SQL  
5. Fail closed if the Forum/Blog module is off when you depend on it  

## Admin pages (settings screens in the ACP)

Plugins should **not** expose raw PHP under `ap-content/plugins/**` as admin endpoints. Register a settings (or tools) screen in the Control Panel so it loads through the admin shell: login, capability check, header/footer, and sidebar.

**Source:** `ap-includes/class-ap-admin-menu.php`  
**Router:** `ap-admin/admin.php?page={id}`  
**Helpers:** `ap_register_admin_page()`, `ap_get_admin_page()`, `ap_get_admin_pages()`, `AP_Admin::pageUrl()`  
**Sample:** `ap-content/plugins/logos/logos.php`

### Register a page

Call `ap_register_admin_page()` from the plugin main file (or on `ap_admin_menu` / `admin_menu` during admin bootstrap). First registration wins for a given `id` (duplicates are rejected).

```php
function myplugin_render_settings(): void
{
    echo '<div class="ap-wrap">';
    echo '<h1>' . ap_esc_html__('My Plugin', 'myplugin') . '</h1>';
    // Forms: nonces + capability checks (default cap is manage_options).
    echo '</div>';
}

ap_register_admin_page([
    'id'         => 'myplugin',           // unique slug → admin.php?page=myplugin
    'parent'     => 'settings',           // settings | plugins | tools | '' (default → Plugins)
    'title'      => 'My Plugin',          // document / screen title
    'menu'       => 'My Plugin',          // sidebar label
    'capability' => 'manage_options',     // checked on every render
    'callback'   => 'myplugin_render_settings', // callable or function-name string
    'plugin'     => ap_plugin_basename(__FILE__), // ties Settings link on plugins.php
    'position'   => 50,                   // optional menu sort order
]);
```

| Key | Required | Notes |
|-----|----------|--------|
| `id` | yes | URL-safe slug (`[a-z0-9_\-]`); becomes `?page=` |
| `callback` | yes | Real callable or function name / `Class::method` string (late-bound) |
| `parent` | no | `settings`, `plugins`, `tools`, or `''` (Plugins section) |
| `title` / `menu` | no | One fills the other when omitted; fallback is `id` |
| `capability` | no | Default `manage_options` |
| `plugin` | no | Plugin basename; when set, a **Settings** action appears on Plugins when active |
| `position` | no | Default `50` |

Lookup helpers:

```php
ap_get_admin_page(string $id): ?array
ap_get_admin_pages(): array                 // id-keyed, insertion order
ap_get_admin_pages_sorted(): array          // by position
ap_get_admin_pages_for_plugin(string $basename): array
AP_Admin::pageUrl('myplugin');              // admin.php?page=myplugin
```

### How the router works

1. Admin bootstrap requires login and fires **`ap_admin_menu`** then **`admin_menu`** so plugins can register pages.  
2. Request hits `ap-admin/admin.php?page={id}`.  
3. The slug is looked up **only** in the `AP_Admin_Menu` allowlist (never treated as a filesystem path).  
4. Unknown / empty / path-like `?page=` → safe **404** (no includes, no callback).  
5. `AP_Admin::requireCapability($page['capability'])` on every render.  
6. Admin header → invoke registered callback → admin footer.

### Menu and Plugins list

- Registered pages merge into the ACP sidebar under the mapped section (`settings` / `plugins` / `tools`).  
- When `plugin` is set and that plugin is **inactive**, the menu item is hidden.  
- On **Plugins** (`ap-admin/plugins.php`), an active plugin with a registered page gets a **Settings** link → `AP_Admin::pageUrl($id)`.  
- Core hardcoded menu items are unchanged; modules still filter as today.

### WordPress-compatible shims

Thin wrappers map classic WP registration onto the same registry (same router and cap gate):

| Shim | Maps to |
|------|---------|
| `add_options_page(...)` | parent `settings` |
| `add_plugins_page(...)` | parent `plugins` |
| `add_menu_page(...)` | parent `''` (Plugins section default) |
| `add_submenu_page($parent, ...)` | parent via map (`options-general.php` → settings, `plugins.php` → plugins, `tools.php` → tools, …) |

Prefer registering on `ap_admin_menu` (or `admin_menu`) if you use the WP shims so the menu fires after login:

```php
ap_add_action('ap_admin_menu', static function (): void {
    add_options_page(
        'My Plugin',
        'My Plugin',
        'manage_options',
        'myplugin',
        'myplugin_render_settings'
    );
});
```

String function-name callbacks are accepted (wrapped for late binding), matching classic WP plugin style.

### Security notes

1. **Registry allowlist only** — never include a path from query input.  
2. **Capability check every request** (default `manage_options`).  
3. Do **not** rely on direct HTTP to `ap-content/plugins/**/admin/*.php` as public admin endpoints.  
4. Use nonces and sanitization/escaping on any form you render in the callback.

### Admin UI (core screens)

- **Plugins** screen: `ap-admin/plugins.php` (cap `activate_plugins`)  
- Nonce-protected activate / deactivate links  
- Zip upload (`AP_Plugin_Installer`, cap `install_plugins`): `.zip` with a **Plugin Name** PHP file at the archive root or one folder deep (`plugin-name/plugin.php`). Overwrite replaces an existing slug. Active plugins cannot be deleted (deactivate first; cap `delete_plugins`).  

## Plugin installer

Zip packages can be uploaded under Plugins (`AP_Plugin_Installer`), the same flow as Appearance → Themes:

- Requires a PHP file with a `Plugin Name` header  
- Single-file packages (`hello.php` at the zip root) install as `ap-content/plugins/hello.php`  
- Folder packages (`my-plugin/my-plugin.php`) install as `ap-content/plugins/my-plugin/`  
- Path traversal, disallowed script types, and oversized archives are rejected  
- Installed plugins stay inactive until you activate them  

CLI: `php ap-cli plugin activate my-plugin/my-plugin.php` after upload.

## Related APIs

| Concern | Entry points |
|---------|----------------|
| Hooks | [hooks.md](hooks.md) |
| Themes | [themes.md](themes.md) |
| Schema / custom tables | Prefer options/postmeta first; migrations are core-owned ([schema.md](schema.md)) |
| Roles | `ap_user_can`, `AP_Roles` |
| Admin pages | `ap_register_admin_page`, `AP_Admin_Menu`, `ap-admin/admin.php` |
