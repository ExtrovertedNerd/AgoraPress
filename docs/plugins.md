# Plugin API

This is the **plugin integrator guide** for AgoraPress **`0.3.13-beta`** (schema `AP_DB_VERSION` **13**). It describes headers, must-use plugins, shortcodes, the Settings API, ACP pages (`ap_register_admin_page`), and the zip installer API (functions, options, error codes, hooks) **as built**.

Plugins extend AgoraPress via headers, activation hooks, and the [hook system](hooks.md). Layout and headers are deliberately familiar to classic WordPress plugin authors. Operator screens: [admin.md](admin.md). Shell verbs: [cli.md](cli.md). REST registration: [rest.md](rest.md).

There is **no** `php ap-cli plugin install` verb, **no** plugin directory, and **no** paid marketplace in core.

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
ap_register_upgrade_hook(string $file, callable $callback): void
ap_validate_plugin_zip(string $zipPath, array $opts = []): AP_Plugin_Install_Result|AP_Plugin_Install_Error
ap_install_plugin(string $zipPath, array $opts = []): AP_Plugin_Install_Result|AP_Plugin_Install_Error
ap_restore_previous_plugin(string $slug, array $opts = []): AP_Plugin_Install_Result|AP_Plugin_Install_Error
ap_list_installed_plugins(array $opts = []): array
ap_install_plugin_from_zip(string $zipPath, array $args = []): array  // legacy array result
ap_upload_plugin(array $file, array $args = []): array                // legacy array result
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

Activate: Admin → Plugins ([admin.md](admin.md)), or:

```bash
php ap-cli plugin list
php ap-cli plugin activate site-notice/site-notice.php
# or single-file:
php ap-cli plugin activate hello.php
php ap-cli plugin deactivate hello.php
```

Built-in CLI is `list` / `activate` / `deactivate` only — [cli.md](cli.md).

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

Core option groups as built: `general`, `modules`, `writing`, `reading`, `discussion`, `media`, `permalink`, `forums`. Themes use group `theme_options` ([themes.md](themes.md)). Options such as `rest_api_enabled` and `version_check_enabled` have **no** dedicated Settings screen — toggle them with `php ap-cli option` ([cli.md](cli.md), [rest.md](rest.md)). Use nonces and capability checks on any custom admin form. Operator map of those screens: [admin.md](admin.md).

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

Base: `/ap-json/` or `?rest_route=/…`. Core `ap/v1` resources, cookie + `X-AP-Nonce` or HTTP Basic, and `rest_api_enabled`: [rest.md](rest.md). Writes exist **only** on posts; other core resources are GET-only.

## CLI commands

```php
ap_add_action('ap_cli_init', static function (): void {
    AP_Cli::addCommand('myplugin hello', static function (array $args): int {
        echo "Hello\n";
        return 0;
    });
});
```

Plugin-registered verbs are not core. Built-in groups: [cli.md](cli.md).

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

Plugins should **not** expose raw PHP under `ap-content/plugins/**` as admin endpoints. Register a settings (or tools) screen in the Control Panel so it loads through the admin shell: login, capability check, header/footer, and sidebar. Operator screen map: [admin.md](admin.md).

**Source:** `ap-includes/class-ap-admin-menu.php`  
**Router:** `ap-admin/admin.php?page={id}`  
**Helpers:** `ap_register_admin_page()`, `ap_get_admin_page()`, `ap_get_admin_pages()`, `AP_Admin::pageUrl()`

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
- Zip upload lives in [Plugin installer](#plugin-installer) below (`AP_Plugin_Installer`, cap `install_plugins`)

## Plugin installer

This is the **canonical** developer contract for installing a plugin from a zip: functions, options, result shape, error codes, and hooks. The operator how-to (form, confirmation, notices, restore button) is [admin.md](admin.md#plugin-zip-installer). Theme zip upload is a **separate** class (`AP_Theme_Installer`) on Appearance → Themes.

**Source:** `ap-includes/class-ap-plugin-installer.php` (`AP_Plugin_Installer`), `ap-includes/class-ap-plugin-install-result.php` (`AP_Plugin_Install_Result`, `AP_Plugin_Install_Error`)  
**Procedural wrappers:** `ap-includes/functions.php`  
**ACP screen:** Plugins → **Upload plugin (.zip)** (`plugins.php`). Seeing the list requires `activate_plugins`. The upload form and **Restore previous version** require `install_plugins` (administrators only). Delete requires `delete_plugins`, or `install_plugins` as a fallback. Nonce on the upload form: `plugin-upload`.

The class methods do **not** check a capability. A plugin that exposes them to a user must check `install_plugins` itself. `ZipArchive` is required.

There is **no** `php ap-cli plugin install`. After a zip install, activate with the Plugins screen or `php ap-cli plugin activate my-plugin/my-plugin.php` ([cli.md](cli.md)). Copying a PHP file or folder into `ap-content/plugins/` still works. Active plugins **cannot** be deleted — deactivate first.

### Functions

Tell success from failure with `instanceof`. A result is not an error array.

| Call | Returns |
|------|---------|
| `AP_Plugin_Installer::validate(string $zipPath, array $opts = [])` | `AP_Plugin_Install_Result` or `AP_Plugin_Install_Error`. Same checks as install. Extracts only into a private staging directory, then removes it. Does not write the plugins directory. |
| `ap_validate_plugin_zip($zipPath, $opts)` | Same. |
| `AP_Plugin_Installer::install(string $zipPath, array $opts = [])` | Same types. Moves the staged payload into the plugins directory. |
| `ap_install_plugin($zipPath, $opts)` | Same. |
| `AP_Plugin_Installer::restorePrevious(string $slug, array $opts = [])` | Same types. Puts the newest restorable backup of that slug back. |
| `ap_restore_previous_plugin($slug, $opts)` | Same. |
| `AP_Plugin_Installer::latestBackup(string $slug, array $opts = [])` | `array{slug, name, version, plugin}` or `null`. Does not move files or fire restore actions. An invalid slug is `null`. |
| `AP_Plugin_Installer::listInstalled(array $opts = [])` | `list<array{slug, name, version, active}>`. Ordered by slug, then by basename. The basename is not in the returned row. A folder and a flat file can share a slug; both are listed. Symlinks are skipped. |
| `ap_list_installed_plugins($opts)` | Same. |
| `AP_Plugin_Installer::slugFromBasename(string $plugin)` | Slug for `demo/demo.php` or `demo.php`. Empty string when the name is not a lowercase slug PHP file (extra slash, `..`, NUL, non-PHP). |

`validate()` reports the action install **would** take (`installed` or `upgraded`) and the previous version when that slug is already on disk. `overwrite` and `activate` are not applied there. `backupPath()` is null and `activated()` is false.

`install()` of a new slug is action `installed` (no previous version, no backup). An existing slug stays in place and the return is `already_installed` unless `overwrite` is boolean `true`. True moves the previous folder or `{slug}.php` into `{content}/.ap-plugin-backups/{slug}/{stamp}/`, then moves the staged payload into place. `rename` is used when it works; otherwise the tree is copied and the source is removed. A failed swap, or a failed check after the swap, moves that backup back. The result `backupPath()` is the stamp directory.

A plugin that was already active stays active. Its upgrade routine runs when one was registered with `ap_register_upgrade_hook()` / `AP_Plugin::registerUpgradeHook()`. The callback receives the plugin basename, the previous version or null, and the new version. If this request already included the plugin, the routine runs on the next `AP_Plugin::loadActivePlugins()` so the replacement file is the one that registers it. A failed attempt to keep that plugin active restores the backup.

`activate` must be boolean `true`. Any other value, including the string `"1"` or integer `1`, does not activate. The Plugins screen converts a posted checkbox of `1` into boolean `true` before it calls `install()`. True activates after the files are in place. If activation fails, the new files stay, the plugin is left inactive, and the return is `activation_failed`. The previous version is **not** restored for that failure. `message()` names the plugin and says it remains inactive. An already-active plugin is not activated again; `activated()` is still true when `activate` was true.

`restorePrevious()` trims the slug. It must be a lowercase plugin slug. The newest stamp name (the one that sorts last) is the only candidate. A stamp that is not a single safe plugin payload is not restored and does not fall through to an older stamp. When the slug is installed, those files move into a new timestamped backup first and are put back if the restore fails. The consumed stamp is removed only after a restore that sticks. When the slug is not installed, the backup moves into place, `previousVersion()` is null, and `backupPath()` is null. Otherwise `previousVersion()` is the version that was displaced, when it could be read, and `backupPath()` is the new stamp that holds those files. A plugin that was active stays active, including when the restored main-file basename differs. An inactive plugin stays inactive. Activation and upgrade hooks are not run. `activated()` is true only when the restored plugin is on the live active list afterward. A `plugins_root` other than that live directory does not change the active list.

Backups live under `{content}/.ap-plugin-backups/` (mode-restricted, `.htaccess` deny). Staging lives under `{content}/.ap-plugin-staging/` (mode `0700`). Nothing is extracted directly into the plugins directory. `ZipArchive::extractTo()` is not used.

### Options

`overwrite` and `activate` count only when the value is boolean `true`.

| Key | Used by | Meaning |
|-----|---------|---------|
| `overwrite` | `install()` | Boolean `true` replaces an existing slug and writes a backup. Any other value returns `already_installed` and does not fire upgrade actions. Ignored by `validate()`. |
| `activate` | `install()` | Boolean `true` activates after the files are in place. Any other value leaves a plugin that was not already active inactive. Ignored by `validate()`. |
| `expected_sha256` | `validate()`, `install()` | When a non-empty string, compared to the file **before** the archive is opened. A blank value skips the check. 64 hex characters, case-insensitive, optional `sha256:` prefix. A mismatch or an unusable digest is `sha256_mismatch` and nothing is extracted. |
| `plugins_root` | all of the calls above | Directory used to detect or list an existing slug. Default: the plugins directory. |
| `content_dir` | `validate()`, `install()`, `restorePrevious()`, `latestBackup()` | Content directory that owns private staging and backups. Default: the parent of the plugins directory. Not an option of `listInstalled()`. |

```php
$result = ap_install_plugin('/tmp/example-plugin.zip', [
    'overwrite' => true,
    'activate' => true,
    'expected_sha256' => 'sha256:<64 hex characters>',
]);

if ($result instanceof AP_Plugin_Install_Error) {
    $code = $result->code();
    $message = $result->message();
} else {
    $slug = $result->slug();
    $action = $result->action(); // installed, upgraded, or restored
}
```

### Result

`AP_Plugin_Install_Result::toArray()`:

| Key | Meaning |
|-----|---------|
| `ok` | `true` |
| `slug` | Lowercase plugin slug |
| `name` | Plugin Name header |
| `version` | Version header |
| `previous_version` | Previous version string, or `null` |
| `action` | `installed`, `upgraded`, or `restored` (`ACTION_INSTALLED`, `ACTION_UPGRADED`, `ACTION_RESTORED`) |
| `backup_path` | Stamp directory of the displaced copy, or `null` |
| `activated` | Whether the plugin is active for this result |
| `plugin` | Basename under the plugins directory (`folder/file.php` or `file.php`) |

`AP_Plugin_Install_Error::toArray()` is `ok` `false`, plus `code` and `message`. `message()` is safe to show to an admin. `code()` is the stable machine value (`AP_Plugin_Install_Error::CODES`).

`listInstalled()` rows are `slug`, `name`, `version`, `active` (bool). `active` is true only when that basename is on the live active list for this plugins directory. Any other directory reports `active` false and does not read the active list. A folder whose main file is not `{slug}/{slug}.php`, and that has several header files, is omitted. Dot entries are skipped.

### Error codes

| Code | When |
|------|------|
| `missing_file` | The zip path cannot be read. |
| `empty` | The file is empty. |
| `not_zip` | The file does not start with a zip signature. The extension is ignored. |
| `zip_open_failed` | `ZipArchive` cannot open the archive. |
| `zip_extension_missing` | The PHP `ZipArchive` extension is missing. |
| `oversize` | The file is larger than the filterable byte cap. |
| `sha256_mismatch` | `expected_sha256` was set and does not match, or the digest is unusable. |
| `unsafe_path` | An entry is absolute, contains `..`, a backslash, a drive letter, or a NUL byte, or its realpath leaves the staging directory. |
| `symlink` | A central-directory external attribute is a Unix symlink. Honored even when the host id is not Unix. |
| `special_file` | A fifo, device, socket, or other type that is not a regular file or directory. |
| `too_many_entries` | Central-directory count, including directories, exceeds the cap. Checked before inflate. |
| `uncompressed_too_large` | The sum of declared uncompressed sizes exceeds the cap. Checked before inflate. |
| `bad_structure` | Not one plugin: more than one top-level folder, or not a single folder / flat plugin. `__MACOSX/` and `.DS_Store` are ignored. |
| `invalid_slug` | The folder name or flat main-file name is not a lowercase slug (`/^[a-z0-9]+(?:[_-][a-z0-9]+)*$/`). |
| `missing_header` | The main PHP file lacks **Plugin Name** or **Version**. |
| `theme_package` | A `style.css` at the archive root, or one directory down, has a **Theme Name** header. A deeper `style.css` is rejected only when the zip has no plugin header. |
| `core_package` | AgoraPress (`ap-includes/version.php`, `index.php`, and `ap-admin/`) or WordPress (`wp-admin/` plus `wp-includes/`), even when a plugin header is nested inside. |
| `already_installed` | The slug exists and `overwrite` is not boolean `true`. |
| `staging_failed` | The private staging directory could not be created or removed. |
| `install_failed` | The payload could not be placed, or a failed upgrade could not restore the previous plugin. |
| `activation_failed` | The files are installed. The plugin is not left active. The previous version is not restored. |
| `backup_failed` | The previous plugin could not be backed up, so it was left unchanged. |
| `no_backup` | `restorePrevious()` found no restorable stamp for that slug. |
| `restore_failed` | The newest backup could not be put back. An installed copy that was still in place is left unchanged. |

The Plugins screen maps each code to a fixed sentence. See [admin.md](admin.md#plugin-zip-error-notices). `activation_failed` on that screen shows `message()` when it is non-empty. Restore and backup failures on **Restore previous version** do the same when the installer sentence is a short single line.

### Package shape

- **Folder:** one top-level directory whose name is a lowercase slug. The main file is `{slug}/{slug}.php` when that file has a plugin header. Otherwise it is the only PHP file directly in that folder with **Plugin Name** and **Version**. Several header files and no `{slug}/{slug}.php`, or a header only in a subdirectory, is `bad_structure`. Installed as `ap-content/plugins/{slug}/`.
- **Flat:** one PHP file at the archive root. Its filename stem is the slug. Installed as `ap-content/plugins/{slug}.php`.

### Limits

| Constant | Default | Filter | Applies to |
|----------|---------|--------|------------|
| `DEFAULT_VALIDATE_MAX_BYTES` | **20 MiB** (`20971520`) | `ap_plugin_zip_max_bytes` (args: cap, zip path) | `validate()` / `install()` and the size shown on **Upload plugin (.zip)** |
| `DEFAULT_MAX_ENTRIES` | `5000` | `ap_plugin_zip_max_entries` (args: cap, zip path) | Central-directory entries. Directories count. |
| `DEFAULT_MAX_UNCOMPRESSED_BYTES` | **128 MiB** (`134217728`) | `ap_plugin_zip_max_uncompressed_bytes` (args: cap, zip path) | Sum of declared uncompressed sizes, not the zip file size. |
| `DEFAULT_MAX_BACKUPS` | `5` | `ap_plugin_zip_max_backups` (args: cap, slug) | Timestamped backups kept per slug after a successful upgrade or restore. The backup just written counts. Zero is rejected; a non-positive filter return leaves the default. |
| `DEFAULT_MAX_BYTES` | **40 MiB** (`41943040`) | none (see below) | Legacy `installFromZip()` / `handleUpload()` / `maxUploadBytes()` only |

A limit filter may return a positive integer or a digit string. Any other return leaves the default. `maxValidateBytes()` is **not** tied to PHP `upload_max_filesize`. The legacy cap is: `AP_MAX_PLUGIN_UPLOAD_BYTES` when that constant is a positive int, otherwise `DEFAULT_MAX_BYTES`, raised when `AP_MAX_UPLOAD_BYTES` is a larger positive int, then lowered to PHP `upload_max_filesize` / `post_max_size` when those are smaller. Use `accepted_args` `2` to read the zip path or slug.

### Hooks

Listener exceptions on these actions are ignored. A logger cannot cancel install, upgrade, restore, activate, or deactivate.

Context array for install, upgrade, and restore (one argument): `slug`, `name`, `version`, `previous_version`, `plugin`, `action` (`installed`, `upgraded`, or `restored`), `backup_path`, `activated`. On the before action, `activated` is false and `backup_path` is null until a backup exists. After runs only when that operation sticks. A failed upgrade or restore that puts the previous files back does not fire after. Optional activation runs **before** the install or upgrade after action, so `activated` matches the result. A failed activation still fires after with `activated` false. A rejected zip or a refused overwrite fires neither install nor upgrade actions.

| Hook | Type | When |
|------|------|------|
| `ap_plugin_before_install` / `ap_plugin_after_install` | action | `install()` for a new slug. |
| `ap_plugin_before_upgrade` / `ap_plugin_after_upgrade` | action | `install()` when `overwrite` replaces an existing slug. |
| `ap_plugin_before_restore` / `ap_plugin_after_restore` | action | `restorePrevious()`. |
| `ap_plugin_before_activate` / `ap_plugin_after_activate` | action | `AP_Plugin::activate()`, including optional activation from `install()`. Arg: plugin basename. Before: before the file is loaded. After: after the active list is saved. An already active plugin does not fire them. `ap_activate_plugin` and `ap_activate_{slug}` still fire during activation. |
| `ap_plugin_before_deactivate` / `ap_plugin_after_deactivate` | action | `AP_Plugin::deactivate()`. Arg: plugin basename. An inactive plugin does not fire them. `ap_deactivate_plugin` and `ap_deactivate_{slug}` still fire during deactivation. |
| `ap_plugin_zip_max_bytes` | filter | See [Limits](#limits). |
| `ap_plugin_zip_max_entries` | filter | See [Limits](#limits). |
| `ap_plugin_zip_max_uncompressed_bytes` | filter | See [Limits](#limits). |
| `ap_plugin_zip_max_backups` | filter | See [Limits](#limits). |
| `ap_plugin_installed` | action | After a successful **legacy** `installFromZip()` only. Args: plugin basename, result array (`ok`, `plugin`, `slug`, `path`, `headers`, `is_folder`, …). The Plugins screen calls `install()` and does **not** fire this. |
| `ap_plugin_deleted` | action | After `deletePlugin()` removes an inactive plugin. Arg: plugin basename. The Plugins screen delete action still uses that method. |

Same inventory: [hooks.md](hooks.md#plugins).

### Legacy helpers

`ap_install_plugin_from_zip()` / `AP_Plugin_Installer::installFromZip()` and `ap_upload_plugin()` / `handleUpload()` return an array (`ok`, `plugin`, `slug`, `path`, `headers`, `is_folder`, `overwritten`, `errors`, `warnings`), not `AP_Plugin_Install_Result`. They use `DEFAULT_MAX_BYTES` (**40 MiB**) via `maxUploadBytes()`. Prefer `ap_validate_plugin_zip()` and `ap_install_plugin()` for new code. `ap_delete_plugin()` / `deletePlugin()` still deletes an inactive plugin and returns `array{ok, plugin, errors}`.

## Related docs

| Need | Doc |
|------|-----|
| Hooks | [hooks.md](hooks.md) |
| ACP screen map / Hall of Fame | [admin.md](admin.md) |
| Zip installer (this file; pointer from admin) | [Plugin installer](#plugin-installer) · [admin.md](admin.md#plugin-zip-installer) |
| Plugin-registered ACP pages | [Admin pages](#admin-pages-settings-screens-in-the-acp) (`ap_register_admin_page`) |
| Built-in CLI verbs | [cli.md](cli.md) |
| REST `/ap-json/` `ap/v1` | [rest.md](rest.md) |
| Themes | [themes.md](themes.md) |
| Schema / custom tables | Prefer options/postmeta first; migrations are core-owned ([schema.md](schema.md)) |
| Roles / caps | [roles.md](roles.md) (`ap_user_can`, `AP_Roles`) |
| Nonces, prepared statements | [security.md](security.md) |
| Drop-in path under `ap-content/plugins/` | [install.md](install.md) |
| Custom plugins are not overwritten on update | [updates.md](updates.md) |
| Pretty `/ap-json/` vs `?rest_route=` | [rewrites.md](rewrites.md) |
| Forum module hooks / module-off | [forums.md](forums.md) |
| Symptom → check | [troubleshooting.md](troubleshooting.md) |
