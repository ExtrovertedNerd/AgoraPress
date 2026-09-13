# Theme hierarchy & theme API

This is the **theme integrator guide** for AgoraPress **`0.3.8-beta`** (schema `AP_DB_VERSION` **12**). It describes the native template hierarchy, default **Agora** theme, assets, Theme Options, and the ACP zip installer **as built**.

AgoraPress themes are **pure PHP templates** with a classic WordPress-inspired hierarchy. Block / Full Site Editing themes (`theme.json`, HTML block templates) are **out of scope** for the native loader (see [compatibility](compatibility.md)) — they are **not in core**. Operator screens: [admin.md](admin.md). Activate from the shell: [cli.md](cli.md). Forum templates: [forums.md](forums.md).

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
| `ap_the_category` / `ap_get_the_category` | Category terms and linked names |
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
Admin: Appearance → Menus, Appearance → Widgets ([admin.md](admin.md)).

## Default theme: Agora

Current stylesheet version: **0.3.9** (`AGORA_THEME_VERSION` / `style.css` header). Board index uses stable phpBB-parity hooks (`.ap-forum-cat-header`, `.ap-forum-row--{unread|read|neutral|locked}`, `.ap-forum-icon--{type}`, three-line `.ap-forum-last-post__*`) styled only in theme CSS — not core — so custom themes can restyle freely. Topic view adds `.ap-forum-first-unread` / `.ap-forum-first-unread-wrap` for the SPEC B1 jump link.

**Source:** `ap-content/themes/agora/functions.php`, `header.php`, `style.css`, `ap-admin/theme-options.php`. Editor chrome: `ap-includes/css/ap-editor.css` ([editor.md](editor.md)).

| Feature | Detail |
|---------|--------|
| Weight | Lightweight, **image-free**, pure CSS |
| Schemes | **Six** (3 light + 3 dark): Marble, Parchment, Cloud, Obsidian, Midnight, Charcoal — [catalog](#color-schemes) |
| Site default | Option `agora_color_scheme` (Appearance → Theme Options). Hard fallback **`marble`**. |
| Visitor preview | Optional Theme Option `agora_visitor_color_preview` (default **off**). Cookie / `?agora_scheme=` only — [visitor preview](#visitor-color-scheme-preview) |
| Body classes | `agora-theme`, `agora-scheme-{slug}`, `agora-mode-light\|dark` |
| `color-scheme` | Each scheme sets `color-scheme: light` or `dark` on `body.agora-scheme-{slug}`. `header.php` also emits `<meta name="color-scheme" content="light\|dark">` and `data-agora-scheme-mode` on `<html>`. |
| Account chrome | Guests: **Log in** (+ **Register** when `users_can_register` is on). Logged-in: welcome, profile, log out (`agora_the_account_indicator`) |
| Forms | Comment/forum fields use scheme tokens (`--ap-field-bg`, `--ap-surface`, …). The visual editor also maps `--ap-editor-*` so dark schemes keep dark chrome — [editor contrast](#editor-contrast) |
| Long strings | `overflow-wrap: anywhere` so unbroken strings (e.g. Monero addresses) wrap instead of stretching the layout |
| Custom CSS | Appearance → Theme Options → Additional CSS (`custom_css` / `AP_Theme::printCustomCss` on `ap_head`) |
| Templates | Blog + forum templates, landmarks, reduced-motion / contrast support |
| Post categories | Linked names in entry meta on blog lists, archives, search, and single posts; single posts also list them after the content (`Posted in`) |
| Nav | Primary + footer menu locations; fallbacks list published pages and useful login/register links when open |

### Color schemes

Six **pure-CSS** schemes. Slugs are stable (options, body classes, `?agora_scheme=`, cookie). Catalog: `agora_get_color_schemes()`.

| Mode | Slug | Label | As built |
|------|------|-------|----------|
| Light (default) | `marble` | Marble | Cool stone white, indigo accents. `color-scheme: light`. |
| Light | `parchment` | Parchment | Warm paper cream, terracotta links. `color-scheme: light`. |
| Light | `cloud` | Cloud | Airy sky blue-gray, cyan accents. `color-scheme: light`. |
| Dark | `obsidian` | Obsidian | Volcanic near-black, violet edges. `color-scheme: dark`. |
| Dark | `midnight` | Midnight | Deep navy night, electric blue. `color-scheme: dark`. |
| Dark | `charcoal` | Charcoal | Warm graphite, amber highlights. `color-scheme: dark`. |

Site default is option **`agora_color_scheme`** (installer default `marble`). Appearance → Theme Options shows six radio cards when the active stylesheet is `agora` and `agora_get_color_schemes()` exists. That screen reads **`agora_get_stored_color_scheme()`** so a visitor preview cannot appear selected as the site default.

Invalid stored slugs sanitize to **`marble`**. `agora_set_color_scheme()` returns false for unknown slugs and does not write them.

Body classes come from `agora_body_class()` / `agora_get_color_scheme()` (the resolved slug for **this request**, which may be a visitor preview). Mode class is `agora-mode-light` or `agora-mode-dark` via `agora_get_color_scheme_mode()`.

### Visitor color-scheme preview

Optional Agora Theme Option, default **off**. Any Agora site can turn it on. There is **no** hostname special case and **no** Agora fork. Generic examples only (`example.com`). Do not document private hosts, persona mailboxes, or live fleet inventory here.

| Piece | As built |
|-------|----------|
| Option | `agora_visitor_color_preview` (`'0'` / `'1'`). Installer default `'0'`. Constant `AGORA_VISITOR_COLOR_PREVIEW_OPTION`. |
| Screen | Appearance → Theme Options, only when Agora is active. Checkbox label: **Allow visitors to preview color schemes.** Help: visitors can try all six schemes on the public site; their choice does **not** change the site default. |
| Persist | `agora_set_visitor_color_preview()`. Read: `agora_visitor_color_preview_enabled()`. |
| Control gate | `agora_visitor_color_preview_control_enabled()`: option **on** **and** `agora_get_color_schemes()` exists (stock Agora or a child that still loads those helpers). Gated on that option only — **not** on the request host. |
| Markup | Compact six-swatch `<nav class="agora-scheme-preview">` in `site-header__inner` **after** the account indicator (`header.php`). Option off: **no** visitor control markup. |
| No-JS | Each swatch is a GET link `?agora_scheme={slug}` (`agora_visitor_color_preview_url()`). Host-relative path + query, or query-only. Invalid slugs yield `''`. Full reload is the shipped path; there is **no** Agora JS enhancer. |
| Current | Resolved slug gets `is-current` and `aria-current="true"`. Light group, then a separator, then dark group. Swatch colors: `agora_get_color_scheme_swatches()` (same catalog as Theme Options). |

**Resolve order** in `agora_get_color_scheme()` (and body-class / `color-scheme` helpers that call it) when the option is **on**:

1. Valid query arg `?agora_scheme={slug}` (refreshes the preview cookie, then used for this request).
2. Valid preview cookie `agora_scheme`.
3. Site option `agora_color_scheme`.
4. `marble`.

When the option is **off**, query and cookie are ignored; the stored site option (then `marble`) wins.

A preview **must not** write `agora_color_scheme`. `agora_get_stored_color_scheme()` ignores query/cookie. Invalid slugs are ignored (same as a missing value).

**Cookie** (`AGORA_COLOR_SCHEME_COOKIE` = `agora_scheme`): path `/`, `SameSite=Lax`, **not** HttpOnly, `Secure` when the request is HTTPS, max-age **30 days** (`AGORA_COLOR_SCHEME_COOKIE_TTL` = `2592000`). Clearing the preview is **not** a shipped control; the next visit without a cookie uses the site default.

**Filter:** `agora_color_scheme` runs on the already-resolved slug (`agora_filter_color_scheme()`). A plugin can inject a preview without a theme fork. Invalid or non-scheme returns keep the resolved slug. The filter **never** writes the site option.

**Not in core**

- A hostname allowlist or product-site-only theme for the swatches  
- A second “site default vs visitor” setting beyond the checkbox  
- A dedicated CLI verb (Theme Options is the screen; there is no `php ap-cli theme` preview flag)  
- A live body-class swap without reload  

### Editor contrast

The visual editor chrome is a **core contract** (`AP_Editor` +
`ap-includes/css/ap-editor.css`), not an Agora-only restyle. Full table:
[editor.md](editor.md#contrast-contract).

Dark custom themes set `color-scheme: dark` on `html` or `body` and use light
text. `.ap-editor` uses `color-scheme: inherit`. Dark chrome also honors
`html.agora-mode-dark` / `body.agora-mode-dark` and `[data-ap-color-mode=dark]`.
Isolation stops a theme `button { color: inherit }` from bleaching letter
labels (`B`, `I`, `Link`).

**Optional `--ap-editor-*` tokens** (unset = page `--ap-*`, then system colors).
Themes **may** gold-plate these. They are **not** required when the page sets
`color-scheme: dark` and uses light text.

| Token | Role |
|-------|------|
| `--ap-editor-bg` | Toolbar / chrome background (`Canvas`) |
| `--ap-editor-fg` | Toolbar / chrome / surface text (`CanvasText` / `FieldText`) |
| `--ap-editor-surface` | Visual surface + textarea background (`Field`) |
| `--ap-editor-border` | Chrome border |
| `--ap-on-accent` | Active Visual \| Text chip text (fallback `#fff`) |

Agora’s six schemes still win when present. `body.agora-theme` maps:

```css
--ap-editor-bg: var(--ap-surface-2);
--ap-editor-fg: var(--ap-fg);
--ap-editor-surface: var(--ap-field-bg, var(--ap-card));
--ap-editor-border: var(--ap-border);
```

Dark Agora schemes use light accents; `--ap-on-accent` keeps the active Visual \| Text chip readable. Do **not** add site-specific theme CSS to core.

## Theme Options (ACP)

Appearance → **Theme Options** (`theme-options.php`, cap `edit_theme_options`) is the shared screen for theme settings. Core always provides **Additional CSS**. When the active stylesheet is **`agora`**, the same screen also exposes the six color-scheme radios and the **Allow visitors to preview color schemes** checkbox (`agora_visitor_color_preview`). Themes declare more options with the Settings API (WordPress-compatible names when the Classic WP compatibility layer is loaded). Operator map: [admin.md](admin.md).

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

Zip packages can be uploaded under Appearance → Themes (`AP_Theme_Installer`, cap `install_themes`):

- Requires `style.css` with Theme Name  
- Parent themes need `index.php`  
- Block/FSE packages rejected by default  
- Active theme and protected default `agora` cannot be deleted  

There is **no** `php ap-cli theme install` verb. Built-in CLI is `theme list` / `theme activate` only ([cli.md](cli.md)). Drop-in without zip still works: copy the folder into `ap-content/themes/` yourself, then activate.

CLI conversion report for classic WP themes: see [compatibility](compatibility.md).

## Checklist for a new theme

1. Create `ap-content/themes/my-theme/style.css` with Theme Name  
2. Add `index.php` (and preferably `header.php` / `footer.php`)  
3. Enqueue CSS on `ap_enqueue_scripts`; call `ap_head()` / `ap_footer()`  
4. Override hierarchy templates as needed  
5. Register menus/sidebars if used  
6. Activate via admin or `php ap-cli theme activate my-theme`  
7. For a dark front-end, set `color-scheme: dark` on `html` or `body` and use light text. Optional `--ap-editor-*` tokens gold-plate the visual editor; they are **not** required when `color-scheme` is set. Agora already maps those tokens for its six schemes.  

## Related docs

| Need | Doc |
|------|-----|
| ACP Appearance screens | [admin.md](admin.md) |
| `php ap-cli theme list\|activate` | [cli.md](cli.md) |
| Forum templates / two-pane CSS | [forums.md](forums.md) |
| Classic WP shim; block/FSE out of scope | [compatibility.md](compatibility.md) |
| `ap_enqueue_scripts`, `ap_head`, template filters | [hooks.md](hooks.md) |
| Front-end comment/forum editor; `--ap-editor-*` / `color-scheme` | [editor.md](editor.md#contrast-contract) · [tokens](#editor-contrast) |
| Pretty permalinks / front controller | [rewrites.md](rewrites.md) |
| Site Icon in `ap_head` | [site-icon.md](site-icon.md) |
| Plugin zip installer (parallel surface) | [plugins.md](plugins.md) |
| `ap-content/themes/` permissions | [install.md](install.md) |
| Custom themes are not overwritten on update | [updates.md](updates.md) |
| Escape on output in templates | [security.md](security.md) |
| Compat theme looks broken | [troubleshooting.md](troubleshooting.md) |  

