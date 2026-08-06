# Theme hierarchy & theme API

AgoraPress themes are **pure PHP templates** with a classic WordPress-inspired hierarchy. Block / Full Site Editing themes (`theme.json`, HTML block templates) are **out of scope** for the native loader (see [compatibility](compatibility.md)).

**Source:** `ap-includes/class-ap-theme.php`, `template-tags.php`, `class-ap-assets.php`  
**Default theme:** `ap-content/themes/agora/`

## Directory layout

```
ap-content/themes/
└── my-theme/
    ├── style.css          # Required headers (Theme Name)
    ├── index.php          # Required for parent themes
    ├── functions.php      # Optional setup / hooks
    ├── header.php
    ├── footer.php
    ├── sidebar.php
    ├── single.php
    ├── page.php
    ├── screenshot.png     # Optional preview
    └── …
```

Themes live under `ap-content/themes/{slug}/`. The active theme is stored in options:

| Option | Meaning |
|--------|---------|
| `stylesheet` | Active theme slug (child when using a parent) |
| `template` | Parent theme slug (same as stylesheet when none) |

Default slug: **`agora`**.

## style.css headers

Parsed from the top of `style.css` (first ~8 KiB). **Theme Name** is required.

Supported fields include:

`Theme Name`, `Theme URI`, `Description`, `Author`, `Author URI`, `Version`, `Template`, `Status`, `Tags`, `Text Domain`, `Domain Path`, `Requires at least`, `Requires PHP`, `License`, `License URI`

### Child themes

Set **`Template: parent-slug`** in the child’s `style.css`. The parent must exist and be a valid theme. Children may omit `index.php` and inherit templates from the parent.

```css
/*
Theme Name: Agora Child
Template: agora
Version: 1.0.0
*/
```

## Template hierarchy

`AP_Theme::getHierarchy()` builds an ordered list of candidate filenames from the main `AP_Query` conditionals. More specific templates come first; **`index.php` is always last**.

Resolution order for each candidate:

1. Active (child) theme directory  
2. Parent theme directory  

Filter: **`ap_template_hierarchy`** (array of relative paths).  
After locate: **`ap_template_include`** (absolute path string).

### By query type

| Condition | Candidates (simplified) |
|-----------|-------------------------|
| 404 | `404.php` → `index.php` |
| Search | `search.php` → `index.php` |
| Static front page | `front-page.php` → custom page template → `page-{slug}.php` → `page-{id}.php` → `page.php` → `singular.php` → `index.php` |
| Blog posts on front | `front-page.php` → `home.php` → `index.php` |
| Posts index | `home.php` → `index.php` |
| Page | custom template → `page-{slug}.php` → `page-{id}.php` → `page.php` → `singular.php` → `index.php` |
| Single post / CPT | `single-{type}-{slug}.php` → `single-{type}.php` → `single.php` → `singular.php` → `index.php` |
| Attachment | mime-specific → `attachment.php` → single chain |
| Category | `category-{slug}.php` → `category-{id}.php` → `category.php` → `archive.php` → `index.php` |
| Tag | `tag-{slug}.php` → `tag-{id}.php` → `tag.php` → `archive.php` → `index.php` |
| Custom taxonomy | `taxonomy-{tax}-{term}.php` → `taxonomy-{tax}.php` → `taxonomy.php` → `archive.php` → `index.php` |
| Author | `author-{nicename}.php` → `author-{id}.php` → `author.php` → `archive.php` → `index.php` |
| Date | `date.php` → `archive.php` → `index.php` |
| CPT archive | `archive-{type}.php` → `archive.php` → `index.php` |

### Page templates

Declare in any theme PHP file:

```php
<?php
/**
 * Template Name: Full Width
 */
```

Admin can assign these to pages. Relative paths such as `templates/landing.php` are supported.

### Forum templates (default Agora)

When the Forum module is on, the front controller and `AP_Forum_Front` drive forum views. Agora ships:

- `forum.php` — forum index  
- `forum-view.php` — single forum / topic list  
- `topic.php` — topic + replies (like / edit / delete / lock when ACL allows; author post & like stats)  
- `forum-search.php` — search results  

Post action flags come from `AP_Forum::getPostsDisplayData()` (`can_edit`, `can_delete`, `can_like`, `like_count`, `author_stats`). Forms post to `AP_Forum_Front::handlePost()` (`ap_forum_edit_post`, `ap_forum_delete_post`, `ap_forum_like_post`, topic lock/unlock).

Custom themes can override these filenames in the child/parent stack the same way as blog templates.

## Partials

```php
// In a template:
ap_get_header();           // header.php or header-{$name}.php
ap_get_footer();
ap_get_sidebar();
ap_get_template_part('content', 'single'); // content-single.php then content.php
```

Class API mirrors this: `AP_Theme::getHeader()`, `getFooter()`, `getSidebar()`, `locateTemplate()`, `loadTemplate()`.

## Theme API (procedural)

```php
ap_get_stylesheet()          // active slug
ap_get_template()            // parent slug
ap_get_stylesheet_directory()
ap_get_template_directory()
ap_get_stylesheet_uri()      // directory URI
ap_get_style_css_uri()       // …/style.css
ap_is_child_theme()
ap_template_loader()         // resolve hierarchy and load (front controller)
```

Discovery / activation (also used by admin and `ap-cli`):

```php
AP_Theme::listThemes()
AP_Theme::setActive(string $stylesheet, ?string $template = null)
AP_Theme::getThemeHeaders(string $slug)
```

Action **`ap_switch_theme`** fires after a successful `setActive`.

## functions.php load order

1. Parent `functions.php` (if child theme)  
2. Child `functions.php`  
3. Optional re-register helpers `{slug}_register_theme_hooks()` (parent then child)  
4. Action **`ap_after_setup_theme`**

When classic WP compatibility is active for the theme, `functions.php` is loaded through a **safe loader** that catches fatals where possible (see [compatibility](compatibility.md)).

## Assets (enqueue)

WordPress-inspired register → enqueue → print via `AP_Assets`:

```php
ap_add_action('ap_enqueue_scripts', static function (): void {
    ap_enqueue_style(
        'my-theme',
        ap_get_stylesheet_uri() . '/style.css',
        [],
        '1.0.0'
    );
    ap_enqueue_script(
        'my-theme',
        ap_get_stylesheet_directory_uri() . '/js/theme.js',
        [],
        '1.0.0',
        true // footer
    );
});
```

Related: `ap_register_style` / `ap_register_script`, `ap_print_styles` / `ap_print_scripts`, `ap_head()` / `ap_footer()`, optional script `strategy` (`defer` / `async`).

Default Agora enqueues its stylesheet on `ap_enqueue_scripts` and calls `ap_head()` / `ap_footer()` from header/footer.

## Template tags

Native tags live in `ap-includes/template-tags.php` (examples):

| Tag | Purpose |
|-----|---------|
| `ap_the_title` / `ap_get_the_title` | Title |
| `ap_the_content` / `ap_get_the_content` | Content (filters `ap_the_content`) |
| `ap_the_excerpt` / `ap_get_the_excerpt` | Excerpt |
| `ap_the_permalink` / `ap_get_the_permalink` | Permalink |
| `ap_the_date` / `ap_the_author` | Meta |
| `ap_bloginfo` / `ap_get_bloginfo` | Site info |
| `ap_body_class` / `ap_post_class` | Classes |
| Loop helpers | Via query / `have_posts`-style APIs in tags + compat |

Always escape when printing raw values; content filters may return HTML intentionally.

## Menus & sidebars

```php
// In functions.php after setup:
ap_register_nav_menus([
    'primary' => 'Primary',
    'footer'  => 'Footer',
]);

ap_register_sidebar([
    'id' => 'sidebar-1',
    'name' => 'Main Sidebar',
]);
```

Render: `ap_nav_menu(['theme_location' => 'primary'])`, `ap_dynamic_sidebar('sidebar-1')`.  
Admin: Appearance → Menus, Appearance → Widgets.

## Default theme: Agora

Current stylesheet version: **0.3.3** (`AGORA_THEME_VERSION` / `style.css` header).

| Feature | Detail |
|---------|--------|
| Weight | Lightweight, **image-free**, pure CSS |
| Schemes | **Six** (3 light + 3 dark): Marble, Parchment, Cloud, Obsidian, Midnight, Charcoal |
| Selection | Option `agora_color_scheme` (Appearance → Theme Options) |
| Body classes | `agora-theme`, `agora-scheme-{slug}`, `agora-mode-light\|dark` |
| Account chrome | Guests: **Log in** (+ **Register** when `users_can_register` is on). Logged-in: welcome, profile, log out (`agora_the_account_indicator`) |
| Forms | Comment/forum fields and the visual editor use scheme tokens (`--ap-field-bg`, `--ap-surface`, …) so dark modes keep dark fields and contrasting text |
| Long strings | `overflow-wrap: anywhere` so unbroken strings (e.g. Monero addresses) wrap instead of stretching the layout |
| Custom CSS | Appearance → Theme Options → Additional CSS (`custom_css` / `AP_Theme::printCustomCss` on `ap_head`) |
| Templates | Blog + forum templates, landmarks, reduced-motion / contrast support |
| Nav | Primary + footer menu locations; fallbacks list published pages and useful login/register links when open |

## Theme Options (ACP)

Appearance → **Theme Options** is the shared screen for theme settings. Core always provides **Additional CSS**. Themes declare more options with the Settings API (WordPress-compatible names when the Classic WP compatibility layer is loaded).

### Registration (in `functions.php`)

```php
ap_add_action('ap_theme_options_register', static function (): void {
    // Only when this theme is active (recommended).
    if (ap_get_stylesheet() !== 'my-theme') {
        return;
    }

    $group = AP_Theme::THEME_OPTIONS_GROUP; // 'theme_options'
    $page  = AP_Theme::THEME_OPTIONS_PAGE;  // 'theme_options'

    ap_register_setting($group, 'my_theme_tagline', [
        'type' => 'string',
        'default' => '',
        'sanitize_callback' => 'ap_sanitize_text_field',
    ]);

    ap_add_settings_section(
        'my_theme_main',
        'My theme',
        static function (): void {
            echo '<p>Options for the active theme.</p>';
        },
        $page
    );

    ap_add_settings_field(
        'my_theme_tagline',
        'Tagline override',
        static function (): void {
            $v = (string) ap_get_option('my_theme_tagline', '');
            echo '<input type="text" class="regular-text" name="my_theme_tagline" value="'
                . ap_esc_attr($v) . '">';
        },
        $page,
        'my_theme_main'
    );
});
```

WordPress-style aliases (when shims are loaded): `register_setting`, `add_settings_section`, `add_settings_field`, `settings_fields`, `do_settings_sections`.

The Theme Options form posts to group `theme_options`; registered option names are read from `$_POST` and sanitized via each setting’s `sanitize_callback`.

### Theme mods (per-theme key/value bag)

WordPress stores theme-specific values in `theme_mods_{stylesheet}`. AgoraPress mirrors that:

```php
ap_get_theme_mod( 'header_text', 'default' );
ap_set_theme_mod( 'header_text', 'Hello' );
ap_remove_theme_mod( 'header_text' );
ap_get_theme_mods(); // full array
```

Bare names `get_theme_mod` / `set_theme_mod` / `remove_theme_mod` are available under the Classic WP compatibility layer.

### Core helpers

| API | Role |
|-----|------|
| `AP_Theme::registerThemeOptions()` | Load theme + fire `ap_theme_options_register` |
| `AP_Theme::hasRegisteredThemeOptions()` | Whether sections/fields exist for the page |
| `AP_Theme::THEME_OPTIONS_PAGE` / `THEME_OPTIONS_GROUP` | Stable ids (`theme_options`) |

## Theme installer

Zip packages can be uploaded under Appearance → Themes (`AP_Theme_Installer`):

- Requires `style.css` with Theme Name  
- Parent themes need `index.php`  
- Block/FSE packages rejected by default  
- Active theme and protected default `agora` cannot be deleted  

CLI conversion report for classic WP themes: see [compatibility](compatibility.md).

## Checklist for a new theme

1. Create `ap-content/themes/my-theme/style.css` with Theme Name  
2. Add `index.php` (and preferably `header.php` / `footer.php`)  
3. Enqueue CSS on `ap_enqueue_scripts`; call `ap_head()` / `ap_footer()`  
4. Override hierarchy templates as needed  
5. Register menus/sidebars if used  
6. Activate via admin or `php ap-cli theme activate my-theme`  
7. If supporting dark schemes, define field/surface tokens so form controls stay readable  

## Related

- [Hooks](hooks.md) — `ap_enqueue_scripts`, `ap_head`, template filters  
- [Compatibility](compatibility.md) — classic WP themes  
- [Editor](editor.md) — front-end comment/forum editor styling  
- [README](../README.md) — install and default-theme notes  

