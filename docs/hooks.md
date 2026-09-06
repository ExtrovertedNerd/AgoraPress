# Hooks (Actions & Filters)

This is the **integrator hook guide** for AgoraPress **`0.3.6-beta`** (schema `AP_DB_VERSION` **12**). It describes the public action/filter API, request lifecycle, and a **selected** set of core hook names that exist in shipped code.

AgoraPress uses a WordPress-inspired hook system so plugins and themes can extend core without forking it. This file is **not** an encyclopedia. Every name in the tables below was grepped from shipped `ap_do_action` / `ap_apply_filters` (or is labeled as a compat **map target** that native core does **not** fire). Grep for the rest. Do **not invent** hook names. If a name is not in this file and not in core, it is **not in core**.

Audience index: [README.md](README.md). Plugin registration: [plugins.md](plugins.md). Theme load: [themes.md](themes.md).

**Source:** `ap-includes/hooks.php`, `class-ap-hook.php`, `class-ap-hooks.php`

## Actions vs filters

| Type | Purpose | API |
|------|---------|-----|
| **Action** | Run side effects when something happens | `ap_add_action` / `ap_do_action` |
| **Filter** | Transform a value before it is used | `ap_add_filter` / `ap_apply_filters` |

Actions and filters share the same registry and priority model. Internally, actions are filters that discard the return value.

## Public API

### Actions

```php
ap_add_action(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): bool
ap_do_action(string $hook, mixed ...$args): void
ap_do_action_ref_array(string $hook, array $args = []): void
ap_remove_action(string $hook, callable $callback, int $priority = 10): bool
ap_remove_all_actions(string $hook, int|false $priority = false): void
ap_has_action(string $hook, callable|false $callback = false): bool|int
ap_did_action(string $hook): int
ap_current_action(): string|false
ap_doing_action(?string $hook = null): bool
```

### Filters

```php
ap_add_filter(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): bool
ap_apply_filters(string $hook, mixed $value, mixed ...$args): mixed
ap_apply_filters_ref_array(string $hook, array $args): mixed
ap_remove_filter(string $hook, callable $callback, int $priority = 10): bool
ap_remove_all_filters(string $hook, int|false $priority = false): void
ap_has_filter(string $hook, callable|false $callback = false): bool|int
ap_current_filter(): string|false
ap_doing_filter(?string $hook = null): bool
```

### Tests

```php
ap_reset_hooks(): void   // Clear all hooks (unit tests only)
```

## Behaviour notes

- **Priority:** Lower numbers run first. Default is `10`. Same priority keeps registration order.
- **Accepted args:** Only the first `$acceptedArgs` parameters are passed to the callback.
- **Dedupe:** Registering the same callable at the same priority is a no-op success.
- **Nesting:** Hooks may fire while another hook is running. `ap_current_filter()` / `ap_doing_filter()` track the stack.
- **Mid-run changes:** Callbacks may add further callbacks at the same or later priority in the current pass; remove during run is supported.
- **Catch-all `"all"`:** If anything is registered on hook name `all`, it runs **before** every other hook. The target hook name is prepended as the first argument. While `all` runs, `ap_current_filter()` still reports the *target* hook name.

## Examples

### Add a footer credit (action)

```php
ap_add_action('ap_footer', static function (): void {
    echo '<p class="site-credit">Powered by AgoraPress</p>';
}, 20);
```

### Modify post content (filter)

```php
ap_add_filter('ap_the_content', static function (string $content): string {
    return $content . "\n<!-- tracked -->";
}, 20);
```

### Remove a callback

```php
$cb = static function (): void { /* … */ };
ap_add_action('ap_loaded', $cb);
ap_remove_action('ap_loaded', $cb); // same callable + default priority 10
```

### Priority and accepted args

```php
ap_add_filter(
    'ap_canonical_url',
    static function (string $url, $query): string {
        return rtrim($url, '/') . '/';
    },
    10,
    2 // receive $url and $query
);
```

## Core lifecycle (request order)

Approximate front-end / shared bootstrap order (`ap-includes/bootstrap.php`):

1. Config + core includes  
2. Object cache / page-cache drop-in hooks (when enabled)  
3. **Must-use plugins** load → **`ap_mu_plugins_loaded`**  
4. **Active plugins** load → **`ap_plugins_loaded`**  
5. Pseudo-cron (skipped when `AP_CLI` is set)  
6. **`ap_loaded`** — core bootstrap finished  
7. Front: rewrite, main query, feeds/REST/sitemaps short-circuits  
8. Theme `functions.php` (parent then child) → **`ap_after_setup_theme`**  
9. Template load → themes call **`ap_head()`** / **`ap_footer()`**, which fire **`ap_enqueue_scripts`**, **`ap_head`**, **`ap_footer`** then print assets via the **functions** `ap_print_styles()` / `ap_print_scripts()` (those function names are **not** actions core fires)

Admin and CLI share the early steps; admin has its own screen bootstrap after login (`ap_admin_menu`).

## Named hooks used by core (selected)

This is not an exhaustive dump of every string. Prefer grepping `ap_do_action` / `ap_apply_filters` in `ap-includes/` (and `ap-admin/` for ACP-only names) when you need a full inventory. Common extension points:

### Bootstrap & lifecycle

| Hook | Type | When |
|------|------|------|
| `ap_mu_plugins_loaded` | action | After must-use plugins are included |
| `ap_plugins_loaded` | action | After active plugins are included |
| `ap_loaded` | action | After bootstrap completes |
| `ap_after_setup_theme` | action | After theme `functions.php` files load |
| `ap_init` | action | Compat **map target** for WP `init` only. Native core does **not** fire this. Native plugins use `ap_loaded` / `ap_plugins_loaded`. |
| `ap_template_redirect` | action | Compat **map target** for WP `template_redirect` only. Native core does **not** fire this. |
| `ap_cli_init` | action | When `ap-cli` is ready for custom commands (`AP_Cli::addCommand`) — [cli.md](cli.md) |
| `ap_rest_api_init` | action | Register REST routes — [rest.md](rest.md) |
| `ap_admin_menu` | action | After ACP login, before the sidebar; plugins call `ap_register_admin_page()` here. WP alias `admin_menu` fires next — [plugins.md](plugins.md), [admin.md](admin.md) |
| `ap_core_updated` | action | After a successful one-click core update — [updates.md](updates.md) |

### Theme / front output

| Hook | Type | Notes |
|------|------|-------|
| `ap_enqueue_scripts` | action | Register/enqueue styles & scripts (fired from `ap_head()`) |
| `ap_head` | action | Inside `ap_head()`, after enqueue and before assets print |
| `ap_footer` | action | Inside `ap_footer()`, before footer scripts print |
| `ap_template_hierarchy` | filter | Candidate template filenames (array) |
| `ap_template_include` | filter | Absolute path of the template to load |
| `ap_the_content` | filter | Post/page body HTML |
| `ap_body_class` / `ap_post_class` | filter | CSS class lists |
| `ap_switch_theme` | action | After active theme options change |
| `ap_theme_options_register` | action | Appearance → Theme Options: register settings/sections/fields — [themes.md](themes.md) |
| `ap_theme_installed` / `ap_theme_deleted` | action | After ACP theme zip install / delete (`AP_Theme_Installer`) — [themes.md](themes.md) |
| `ap_site_icon_meta_tags` | filter | `<link rel="icon">` tag list when `site_icon` > 0 — [site-icon.md](site-icon.md) |
| `ap_theme_compat_loaded` | action | After classic WP shims are included — [compatibility.md](compatibility.md) |

`ap_print_styles()` / `ap_print_scripts()` are **PHP functions** (`AP_Assets::printStyles()` / `printScripts()`), not actions native core fires. The WP names `wp_print_styles` / `wp_print_scripts` are compat **map targets** only.

Excerpt shaping is `ap_get_the_excerpt($post, $words)` (word-count argument). There is **no** native `ap_excerpt_length` / `ap_excerpt_more` filter — those strings exist only as compat **map targets**.

### Content & comments

| Hook | Type | Notes |
|------|------|-------|
| `ap_post_inserted` / `ap_post_updated` / `ap_post_trashed` / `ap_post_untrashed` / `ap_post_deleted` | action | Post lifecycle |
| `ap_pre_comment_insert` / `ap_comment_inserted` / `ap_comment_updated` / `ap_comment_status_changed` / `ap_comment_deleted` | action | Comments |
| `ap_pre_comment_approved` | filter | Return status override / spam hooks |
| `ap_format_content` | filter | BBCode/Markdown/HTML pipeline (`AP_Content_Format`) |
| `ap_editor_buttons` | filter | Classic visual editor toolbar button definitions |
| `ap_editor_emojis` | filter | Unicode emoji catalog for the lightweight picker |
| `ap_editor_mode` | filter | Default editor mode for a context (`post`, `forum`, …) — visual surface |

The core editor is a **classic visual WYSIWYG** (contenteditable + textarea, Visual \| Text modes) — see [editor.md](editor.md).  
There is **no** block-editor hook surface in core.

### Forums

| Hook | Type | Notes |
|------|------|-------|
| `ap_pre_forum_post_status` | filter | Approval / spam decision for topics & replies |
| `ap_topic_created` / `ap_forum_post_inserted` | action | After a topic or reply is stored. Grep `ap_topic_` / `ap_forum_` in `class-ap-forum.php` for the rest (updated, deleted, pre-insert, …). |
| `ap_forum_topics_per_page` / `ap_forum_posts_per_page` / `ap_forum_search_per_page` | filter | Pagination |
| `ap_forum_post_liked` / `ap_forum_post_unliked` | action | After a like is cast or removed — [forums.md](forums.md) |
| `ap_moderation_topic_soft_deleted` / `ap_moderation_topic_restored` / `ap_moderation_post_soft_deleted` | action | Selected moderation actions. Grep `ap_moderation_` in `class-ap-forum-moderation.php` for the rest (move, merge, split, approve, …). |
| `ap_pre_pm_send` / `ap_pm_sent` | action | Private messages. Grep `ap_pm_` in `class-ap-private-message.php` for the rest. |
| `ap_online_tracked` | action | Who’s online |

### Plugins

| Hook | Type | Notes |
|------|------|-------|
| `ap_activate_plugin` | action | After a plugin is activated. Also fires `ap_activate_{slug}` (`AP_Plugin::hookSlug()`). |
| `ap_deactivate_plugin` | action | After deactivation. Also fires `ap_deactivate_{slug}`. |
| `ap_plugin_installed` / `ap_plugin_deleted` | action | After ACP zip install / delete (`AP_Plugin_Installer`) — [plugins.md](plugins.md) |
| `ap_plugin_header_fields` | filter | Extend header field names |

### Cache, SEO, privacy, health, analytics

| Hook | Type | Notes |
|------|------|-------|
| `ap_page_cache_flush` | action | Full-page cache invalidation. Grep `ap_page_cache_purge_` in `class-ap-page-cache.php` for URL/post/topic/forum variants. |
| `ap_should_cache_request` / `ap_page_cache_enabled` | filter | Cache policy |
| `ap_canonical_url` / `ap_open_graph_meta` | filter | SEO tags |
| `ap_rest_enabled` | filter | Master switch alongside option `rest_api_enabled` (**no** ACP Settings screen; CLI only). Grep `ap_rest_` in `class-ap-rest.php` for namespaces / prepare / served. — [rest.md](rest.md) |
| `ap_sitemaps_enabled` / `ap_sitemap_providers` / `ap_robots_txt` | filter | Sitemaps |
| `ap_privacy_export_data` / `ap_privacy_erase_data` | filter | GDPR-style tools |
| `ap_site_health_checks` / `ap_site_health_info` | filter | Site Health |
| `ap_cron_schedules` | filter | Extra recurrence keys for `AP_Cron` |
| `ap_version_check_enabled` / `ap_version_check_url` | filter | Update checks (no site identity sent) |
| `ap_analytics_enabled` / `ap_analytics_retention_days` | filter | Local analytics config (default collection **off**) |
| `ap_analytics_should_record` | filter | Final gate before writing a hit |
| `ap_analytics_exclude_admins` / `ap_analytics_record_404` | filter | Skip logged-in admins (default true); record 404s (default true) |
| `ap_analytics_ua_class` | filter | Coarse UA class (`browser` / `bot` / `other`) |
| `ap_analytics_hit_recorded` / `ap_analytics_pruned` / `ap_analytics_rolled_up` | action | After record / prune / daily rollup |
| `ap_analytics_prune` | action (cron) | Daily retention prune hook name (`AP_Analytics::CRON_HOOK`) |

### i18n

| Hook | Type | Notes |
|------|------|-------|
| `ap_locale` | filter | Active locale |
| `ap_gettext` / `ap_ngettext` | filter | Translation results. Also `ap_gettext_with_context` / `ap_ngettext_with_context`. |
| `ap_is_rtl` / `ap_language_attributes` | filter | Direction & HTML attrs |

## Grep for the rest

Do **not** treat this file as complete. Inventory from code:

```bash
grep -R --include='*.php' -E "ap_do_action\(|ap_apply_filters\(" ap-includes/ ap-admin/
```

- Skip `tests/` — those files fire dummy names.  
- Skip `ap-includes/hooks.php` — that is the API, not a fire site.  
- Default **Agora** theme filters (`agora_*` in `ap-content/themes/agora/functions.php`) are **theme-private**, not the core API.  
- Cron events fire whatever hook name was scheduled (`AP_Cron`); `ap_analytics_prune` is the one core registers.  
- There is **no** `user_has_cap` filter. Capabilities go through `AP_Roles` / `ap_add_cap` — [roles.md](roles.md).

## Compat map targets native core does not fire

When the [Classic WP Theme Compatibility Layer](compatibility.md) is active, `AP_Theme_Compat::mapHook()` rewrites some WP names to `ap_*` strings. Several of those **map targets are not fired by native core**. They run only if a classic theme (or shim) calls the WP source name via `do_action` / `apply_filters`.

From the shipped `$hookMap` in `class-ap-theme-compat.php`, native core does **not** fire:

| Map target | WP source | Notes |
|------------|-----------|-------|
| `ap_init` | `init` | Use `ap_loaded` / `ap_plugins_loaded` |
| `ap_template_redirect` | `template_redirect` | Not a native front-controller hook |
| `ap_widgets_init` | `widgets_init` | Widgets register in core without this action |
| `ap_wp` | `wp` | No native “main query parsed” action of this name |
| `ap_print_styles` / `ap_print_scripts` | `wp_print_styles` / `wp_print_scripts` | PHP **functions** of the same name exist; they are not actions |
| `ap_excerpt_length` / `ap_excerpt_more` | `excerpt_length` / `excerpt_more` | Use `ap_get_the_excerpt()`’s `$words` argument |
| `ap_nav_menu_css_class` / `ap_nav_menu_args` | `nav_menu_css_class` / `wp_nav_menu_args` | Menu HTML is `ap_nav_menu()` / `AP_Nav_Menu` |

Unmapped `wp_*` names are rewritten to `ap_*` by prefix swap. That rewrite is **not** a promise that core fires the result. If you cannot grep `ap_do_action('…')` or `ap_apply_filters('…')` in `ap-includes/` / `ap-admin/`, it is **not in core**.

Mapped names that **are** natively fired (so a classic theme’s `wp_enqueue_scripts` listener actually runs) include `ap_after_setup_theme`, `ap_enqueue_scripts`, `ap_head`, `ap_footer`, `ap_the_content`, `ap_body_class`, and `ap_post_class`.

## Best practices

1. **Namespace your hook names** when defining plugin-private hooks (`myplugin_foo`), and use core `ap_*` names only when intentionally integrating with core.
2. **Keep callbacks small** and fail soft — a thrown exception in a popular hook can break the whole request.
3. **Document `$acceptedArgs`** when your callback needs more than the first parameter.
4. **Remove with the same priority** you used when adding.
5. Prefer **filters for data** and **actions for side effects** (logging, enqueue, redirects).

## Compatibility note

When the [Classic WP Theme Compatibility Layer](compatibility.md) is active, bare names like `add_action` / `apply_filters` map common WP hook names to AgoraPress names (for example `wp_enqueue_scripts` → `ap_enqueue_scripts`). Native plugins and themes should call the `ap_*` API directly.

## Related docs

| Need | Doc |
|------|-----|
| Plugin headers, ACP pages, zip installer | [plugins.md](plugins.md) |
| Template hierarchy, Theme Options | [themes.md](themes.md) |
| REST `ap_rest_api_init` / `ap_rest_enabled` | [rest.md](rest.md) |
| `ap_cli_init` built-ins vs plugin verbs | [cli.md](cli.md) |
| Forum filters (`ap_pre_forum_post_status`, …) | [forums.md](forums.md) |
| Visual editor filters | [editor.md](editor.md) |
| Roles / `ap_add_cap` (there is **no** `user_has_cap` filter) | [roles.md](roles.md) |
| Site Icon filter | [site-icon.md](site-icon.md) |
| ACP screens / zip installer | [admin.md](admin.md) |
| Classic WP hook map | [compatibility.md](compatibility.md) |
| Version-check / `ap_core_updated` | [updates.md](updates.md) |
| Nonces and capability checks | [security.md](security.md) |
| Pretty permalinks / front controller | [rewrites.md](rewrites.md) |
| Fresh install load order | [install.md](install.md) |
| Symptom → check | [troubleshooting.md](troubleshooting.md) |
