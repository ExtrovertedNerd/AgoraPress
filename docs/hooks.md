# Hooks (Actions & Filters)

AgoraPress uses a WordPress-inspired hook system so plugins and themes can extend core without forking it.

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
ap_add_action('ap_init', $cb);
ap_remove_action('ap_init', $cb); // same callable + default priority 10
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

Approximate front-end / shared bootstrap order:

1. Config + core includes  
2. Object cache / page-cache drop-in hooks (when enabled)  
3. **Must-use plugins** load  
4. **Active plugins** load → `ap_plugins_loaded`  
5. Pseudo-cron (skipped when `AP_CLI` is set)  
6. **`ap_loaded`** — core bootstrap finished  
7. Front: rewrite, main query, feeds/REST/sitemaps short-circuits  
8. Theme `functions.php` (parent then child) → **`ap_after_setup_theme`**  
9. Template load → themes call **`ap_enqueue_scripts`**, **`ap_head`**, **`ap_footer`**

Admin and CLI share the early steps; admin has its own screen bootstrap after login.

## Named hooks used by core (selected)

This is not an exhaustive dump of every string. Prefer grepping `ap_do_action` / `ap_apply_filters` in `ap-includes/` when you need a full inventory. Common extension points:

### Bootstrap & lifecycle

| Hook | Type | When |
|------|------|------|
| `ap_plugins_loaded` | action | After active plugins are included |
| `ap_loaded` | action | After bootstrap completes |
| `ap_after_setup_theme` | action | After theme `functions.php` files load |
| `ap_init` | action | Mapped from WP `init` in compat; use for late setup |
| `ap_template_redirect` | action | Compat map for pre-template work |
| `ap_cli_init` | action | When `ap-cli` is ready for custom commands |
| `ap_rest_api_init` | action | Register REST routes |

### Theme / front output

| Hook | Type | Notes |
|------|------|-------|
| `ap_enqueue_scripts` | action | Register/enqueue styles & scripts |
| `ap_head` | action | Inside `ap_head()` (meta, assets print hooks) |
| `ap_footer` | action | Inside `ap_footer()` |
| `ap_print_styles` / `ap_print_scripts` | action | Asset printing |
| `ap_template_hierarchy` | filter | Candidate template filenames (array) |
| `ap_template_include` | filter | Absolute path of the template to load |
| `ap_the_content` | filter | Post/page body HTML |
| `ap_body_class` / `ap_post_class` | filter | CSS class lists |
| `ap_excerpt_length` / `ap_excerpt_more` | filter | Excerpt shaping |
| `ap_switch_theme` | action | After active theme options change |

### Content & comments

| Hook | Type | Notes |
|------|------|-------|
| `ap_post_inserted` / `ap_post_updated` / `ap_post_trashed` / `ap_post_untrashed` / `ap_post_deleted` | action | Post lifecycle |
| `ap_pre_comment_insert` / `ap_comment_inserted` / `ap_comment_updated` / `ap_comment_deleted` | action | Comments |
| `ap_pre_comment_approved` | filter | Return status override / spam hooks |
| `ap_format_content` | filter | BBCode/Markdown/HTML pipeline |

### Forums

| Hook | Type | Notes |
|------|------|-------|
| `ap_pre_forum_post_status` | filter | Approval / spam decision for topics & replies |
| `ap_forum_topics_per_page` / `ap_forum_posts_per_page` / `ap_forum_search_per_page` | filter | Pagination |
| `ap_moderation_*` | action | Soft-delete, restore, move, merge, … |
| `ap_pre_pm_send` / `ap_pm_sent` | action | Private messages |
| `ap_online_tracked` | action | Who’s online |

### Plugins

| Hook | Type | Notes |
|------|------|-------|
| `ap_activate_plugin` | action | After a plugin is activated |
| `ap_deactivate_plugin` | action | After deactivation |
| `ap_plugin_header_fields` | filter | Extend header field names |

### Cache, SEO, privacy, health

| Hook | Type | Notes |
|------|------|-------|
| `ap_page_cache_flush` / `ap_page_cache_purge_*` | action | Full-page cache invalidation |
| `ap_should_cache_request` / `ap_page_cache_enabled` | filter | Cache policy |
| `ap_canonical_url` / `ap_open_graph_meta` | filter | SEO tags |
| `ap_sitemaps_enabled` / `ap_sitemap_providers` / `ap_robots_txt` | filter | Sitemaps |
| `ap_privacy_export_data` / `ap_privacy_erase_data` | filter | GDPR-style tools |
| `ap_site_health_checks` / `ap_site_health_info` | filter | Site Health |
| `ap_version_check_enabled` / `ap_version_check_url` | filter | Update checks (no site identity sent) |

### i18n

| Hook | Type | Notes |
|------|------|-------|
| `ap_locale` | filter | Active locale |
| `ap_gettext` / `ap_ngettext` / … | filter | Translation results |
| `ap_is_rtl` / `ap_language_attributes` | filter | Direction & HTML attrs |

## Best practices

1. **Namespace your hook names** when defining plugin-private hooks (`myplugin_foo`), and use core `ap_*` names only when intentionally integrating with core.
2. **Keep callbacks small** and fail soft — a thrown exception in a popular hook can break the whole request.
3. **Document `$acceptedArgs`** when your callback needs more than the first parameter.
4. **Remove with the same priority** you used when adding.
5. Prefer **filters for data** and **actions for side effects** (logging, enqueue, redirects).

## Compatibility note

When the [Classic WP Theme Compatibility Layer](compatibility.md) is active, bare names like `add_action` / `apply_filters` map common WP hook names to AgoraPress names (for example `wp_enqueue_scripts` → `ap_enqueue_scripts`). Native plugins and themes should call the `ap_*` API directly.
