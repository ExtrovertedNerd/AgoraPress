<?php

/**
 * AgoraPress theme loader and classic template hierarchy.
 *
 * Resolves the active theme (stylesheet / optional parent template), builds a
 * WordPress-inspired candidate list from AP_Query conditionals, locates the
 * first matching PHP file under the child then parent theme, and loads it.
 * Pure PHP templates only — block / FSE themes are out of scope.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * Theme API: discovery, hierarchy, locate/load, and front-end render.
 */
class AP_Theme
{
    /** Default theme directory slug shipped with core. */
    public const DEFAULT_SLUG = 'agora';

    /** Option: active theme slug (child when using a parent). */
    public const OPTION_STYLESHEET = 'stylesheet';

    /** Option: parent theme slug (same as stylesheet when no parent). */
    public const OPTION_TEMPLATE = 'template';

    /** @var bool Whether setup() has loaded theme functions.php files. */
    private static bool $setupDone = false;

    /** @var string|null Override themes root (tests). */
    private static ?string $themesRootOverride = null;

    /** @var string|null Forced stylesheet slug (tests / runtime override). */
    private static ?string $stylesheetOverride = null;

    /** @var string|null Forced template (parent) slug. */
    private static ?string $templateOverride = null;

    // -------------------------------------------------------------------------
    // Paths & active theme
    // -------------------------------------------------------------------------

    /**
     * Absolute filesystem path to the themes directory (no trailing slash).
     */
    public static function themesRoot(): string
    {
        if (self::$themesRootOverride !== null && self::$themesRootOverride !== '') {
            return rtrim(self::$themesRootOverride, '/\\');
        }

        if (defined('AP_THEME_DIR')) {
            return (string) AP_THEME_DIR;
        }

        if (defined('AP_CONTENT_DIR')) {
            return AP_CONTENT_DIR . '/themes';
        }

        if (defined('AP_ABSPATH')) {
            return rtrim((string) AP_ABSPATH, '/\\') . '/ap-content/themes';
        }

        return dirname(__DIR__) . '/ap-content/themes';
    }

    /**
     * Override themes root for tests. Pass null to clear.
     */
    public static function setThemesRootOverride(?string $path): void
    {
        self::$themesRootOverride = $path !== null && $path !== ''
            ? rtrim($path, '/\\')
            : null;
    }

    /**
     * Active theme slug (stylesheet directory name).
     */
    public static function getStylesheet(?AP_DB $db = null): string
    {
        if (self::$stylesheetOverride !== null && self::$stylesheetOverride !== '') {
            return self::sanitizeSlug(self::$stylesheetOverride);
        }

        $slug = self::sanitizeSlug(self::readOption(self::OPTION_STYLESHEET, '', $db));
        if ($slug === '' || !self::themeDirExists($slug)) {
            $slug = self::DEFAULT_SLUG;
        }

        return $slug;
    }

    /**
     * Parent theme slug (template directory). Same as stylesheet when no parent.
     */
    public static function getTemplate(?AP_DB $db = null): string
    {
        if (self::$templateOverride !== null && self::$templateOverride !== '') {
            return self::sanitizeSlug(self::$templateOverride);
        }

        $slug = self::sanitizeSlug(self::readOption(self::OPTION_TEMPLATE, '', $db));
        if ($slug === '') {
            // Infer parent from style.css Template header of the stylesheet theme.
            $headers = self::getThemeHeaders(self::getStylesheet($db));
            $parent = is_array($headers) ? self::sanitizeSlug((string) ($headers['Template'] ?? '')) : '';
            $slug = $parent !== '' ? $parent : self::getStylesheet($db);
        }

        if (!self::themeDirExists($slug)) {
            $slug = self::getStylesheet($db);
            if (!self::themeDirExists($slug)) {
                $slug = self::DEFAULT_SLUG;
            }
        }

        return $slug;
    }

    /**
     * Force active theme for the current request (does not write options).
     *
     * @param string|null $stylesheet Child/active slug; null clears override.
     * @param string|null $template   Parent slug; when null and stylesheet set, uses stylesheet.
     */
    public static function setActiveOverride(?string $stylesheet, ?string $template = null): void
    {
        if ($stylesheet === null || $stylesheet === '') {
            self::$stylesheetOverride = null;
            self::$templateOverride = null;

            return;
        }

        self::$stylesheetOverride = self::sanitizeSlug($stylesheet);
        self::$templateOverride = $template !== null && $template !== ''
            ? self::sanitizeSlug($template)
            : self::$stylesheetOverride;
        self::$setupDone = false;
    }

    /**
     * Persist active theme options (stylesheet + template/parent).
     */
    public static function setActive(string $stylesheet, ?string $template = null, ?AP_DB $db = null): bool
    {
        $stylesheet = self::sanitizeSlug($stylesheet);
        if ($stylesheet === '' || !self::isValidTheme($stylesheet)) {
            return false;
        }

        if ($template === null || $template === '') {
            $parent = self::getDeclaredParent($stylesheet);
            $template = $parent !== '' && self::themeDirExists($parent) ? $parent : $stylesheet;
        } else {
            $template = self::sanitizeSlug($template);
            if ($template === '' || !self::themeDirExists($template)) {
                return false;
            }
            // Child themes must declare (and match) their parent Template header.
            $declared = self::getDeclaredParent($stylesheet);
            if ($declared !== '' && $declared !== $template) {
                return false;
            }
        }

        $ok1 = self::writeOption(self::OPTION_STYLESHEET, $stylesheet, $db);
        $ok2 = self::writeOption(self::OPTION_TEMPLATE, $template, $db);
        self::$stylesheetOverride = null;
        self::$templateOverride = null;
        self::$setupDone = false;

        $ok = $ok1 && $ok2;
        if ($ok && function_exists('ap_do_action')) {
            ap_do_action('ap_switch_theme', $stylesheet, $template);
        }

        return $ok;
    }

    /**
     * Absolute path to the active (child) theme directory.
     */
    public static function getStylesheetDirectory(?AP_DB $db = null): string
    {
        return self::themesRoot() . '/' . self::getStylesheet($db);
    }

    /**
     * Absolute path to the parent theme directory (same as stylesheet when none).
     */
    public static function getTemplateDirectory(?AP_DB $db = null): string
    {
        return self::themesRoot() . '/' . self::getTemplate($db);
    }

    /**
     * Public URI for the active theme directory (no trailing slash).
     */
    public static function getStylesheetUri(?AP_DB $db = null): string
    {
        return self::themeUri(self::getStylesheet($db), $db);
    }

    /**
     * Public URI for the parent theme directory (no trailing slash).
     */
    public static function getTemplateUri(?AP_DB $db = null): string
    {
        return self::themeUri(self::getTemplate($db), $db);
    }

    /**
     * Public URI of the active theme's style.css file.
     *
     * Classic WP maps get_stylesheet_uri() to this path. Directory URIs are
     * getStylesheetUri() / getTemplateUri().
     */
    public static function getStyleCssUri(?AP_DB $db = null): string
    {
        return self::getStylesheetUri($db) . '/style.css';
    }

    /**
     * Whether the active theme is a child (stylesheet ≠ template).
     */
    public static function isChildTheme(?AP_DB $db = null): bool
    {
        return self::getStylesheet($db) !== self::getTemplate($db);
    }

    /**
     * Absolute path to a theme's screenshot if present (png/jpg/jpeg/webp/gif).
     */
    public static function getScreenshotPath(string $slug): ?string
    {
        $slug = self::sanitizeSlug($slug);
        if ($slug === '' || !self::themeDirExists($slug)) {
            return null;
        }
        $dir = self::themesRoot() . '/' . $slug;
        $candidates = [
            'screenshot.png',
            'screenshot.jpg',
            'screenshot.jpeg',
            'screenshot.webp',
            'screenshot.gif',
        ];
        foreach ($candidates as $file) {
            $path = $dir . '/' . $file;
            if (is_readable($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Public URI of a theme's screenshot, or empty string when none.
     */
    public static function getScreenshotUri(string $slug, ?AP_DB $db = null): string
    {
        $path = self::getScreenshotPath($slug);
        if ($path === null) {
            return '';
        }
        $file = basename($path);

        return self::themeUri($slug, $db) . '/' . $file;
    }

    /**
     * Parent slug declared in a theme's style.css Template header (empty if none).
     */
    public static function getDeclaredParent(string $slug): string
    {
        $headers = self::getThemeHeaders($slug);
        if ($headers === null) {
            return '';
        }

        return self::sanitizeSlug((string) ($headers['Template'] ?? ''));
    }

    /**
     * Whether a theme slug is a valid child of its declared Template parent.
     * Standalone (parent) themes return true when they have style.css + index.php.
     */
    public static function isValidTheme(string $slug): bool
    {
        $slug = self::sanitizeSlug($slug);
        $headers = self::getThemeHeaders($slug);
        if ($headers === null) {
            return false;
        }

        $parent = self::sanitizeSlug((string) ($headers['Template'] ?? ''));
        if ($parent !== '') {
            if ($parent === $slug) {
                return false;
            }
            // Parent must exist and not itself be a child of this theme (simple cycle guard).
            if (!self::themeDirExists($parent) || self::getThemeHeaders($parent) === null) {
                return false;
            }
            $grand = self::getDeclaredParent($parent);
            if ($grand === $slug) {
                return false;
            }
        }

        // Parent themes need index.php; children may inherit it.
        if ($parent === '') {
            $index = self::themesRoot() . '/' . $slug . '/index.php';
            if (!is_file($index)) {
                return false;
            }
        }

        return true;
    }

    // -------------------------------------------------------------------------
    // Discovery & style.css headers
    // -------------------------------------------------------------------------

    /**
     * List installed themes keyed by slug.
     *
     * @return array<string, array<string, string>>
     */
    public static function listThemes(): array
    {
        $root = self::themesRoot();
        if (!is_dir($root)) {
            return [];
        }

        $out = [];
        $entries = @scandir($root);
        if (!is_array($entries)) {
            return [];
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
                continue;
            }
            $slug = self::sanitizeSlug($entry);
            if ($slug === '' || $slug !== $entry) {
                // Only allow directory names that are already safe slugs.
                continue;
            }
            if (!is_dir($root . '/' . $entry)) {
                continue;
            }
            $headers = self::getThemeHeaders($entry);
            if ($headers === null || !self::isValidTheme($entry)) {
                continue;
            }
            $parent = self::sanitizeSlug((string) ($headers['Template'] ?? ''));
            $headers['Is Child'] = $parent !== '' ? '1' : '0';
            $headers['Parent'] = $parent;
            $shot = self::getScreenshotUri($entry);
            if ($shot !== '') {
                $headers['Screenshot'] = $shot;
            }
            $out[$entry] = $headers;
        }

        ksort($out);

        return $out;
    }

    /**
     * Parsed style.css headers for a theme slug, or null if missing/invalid.
     *
     * @return array<string, string>|null
     */
    public static function getThemeHeaders(string $slug): ?array
    {
        $slug = self::sanitizeSlug($slug);
        if ($slug === '') {
            return null;
        }

        $path = self::themesRoot() . '/' . $slug . '/style.css';
        if (!is_readable($path)) {
            return null;
        }

        $headers = self::parseStyleCss($path);
        if ($headers === []) {
            return null;
        }

        // Require at least a Theme Name (classic WP convention).
        if (trim((string) ($headers['Theme Name'] ?? '')) === '') {
            return null;
        }

        $headers['Slug'] = $slug;

        return $headers;
    }

    /**
     * Parse classic theme headers from the top of style.css.
     *
     * @return array<string, string>
     */
    public static function parseStyleCss(string $path): array
    {
        if (!is_readable($path)) {
            return [];
        }

        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return [];
        }

        // Only need the header block (first ~8 KiB is enough for classic themes).
        $chunk = (string) fread($fh, 8192);
        fclose($fh);

        $known = [
            'Theme Name',
            'Theme URI',
            'Description',
            'Author',
            'Author URI',
            'Version',
            'Template',
            'Status',
            'Tags',
            'Text Domain',
            'Domain Path',
            'Requires at least',
            'Requires PHP',
            'License',
            'License URI',
        ];

        $headers = [];
        foreach ($known as $field) {
            $pattern = '/^[ \\t\\/*#@]*' . preg_quote($field, '/') . ':[ \\t]*(.*)$/mi';
            if (preg_match($pattern, $chunk, $m) === 1) {
                $headers[$field] = trim((string) $m[1]);
            }
        }

        return $headers;
    }

    /**
     * Page templates declared via "Template Name:" in theme PHP files.
     *
     * Scans the active (child) theme first, then parent. Keys are relative
     * paths (e.g. full-width.php or templates/landing.php).
     *
     * @return array<string, string> relative path => template name
     */
    public static function getPageTemplates(?AP_DB $db = null): array
    {
        $found = [];
        $dirs = array_unique([
            self::getStylesheetDirectory($db),
            self::getTemplateDirectory($db),
        ]);

        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            self::scanPageTemplates($dir, $dir, $found);
        }

        return $found;
    }

    // -------------------------------------------------------------------------
    // Template hierarchy
    // -------------------------------------------------------------------------

    /**
     * Ordered list of candidate template filenames for the current (or given) query.
     *
     * More specific templates come first; index.php is always last.
     *
     * @return list<string>
     */
    public static function getHierarchy(?AP_Query $query = null, ?AP_DB $db = null): array
    {
        $query = self::resolveQuery($query);
        $templates = [];

        if ($query->is_404) {
            $templates[] = '404.php';
        } elseif ($query->is_search) {
            $templates[] = 'search.php';
        } elseif (!empty($query->is_front_page) && !empty($query->is_page)) {
            // Static front page: front-page.php, then page templates, then singular.
            $templates[] = 'front-page.php';
            $post = self::queriedPost($query);
            if ($post instanceof AP_Post) {
                $custom = class_exists('AP_Post', false)
                    ? AP_Post::getPageTemplate($post->ID, $db)
                    : 'default';
                if (is_string($custom) && $custom !== '' && $custom !== 'default') {
                    $custom = ltrim(str_replace('\\', '/', $custom), '/');
                    if (!str_ends_with($custom, '.php')) {
                        $templates[] = $custom . '.php';
                    }
                    $templates[] = $custom;
                }
                $slug = (string) $post->post_name;
                if ($slug !== '') {
                    $templates[] = 'page-' . $slug . '.php';
                }
                $templates[] = 'page-' . $post->ID . '.php';
            }
            $templates[] = 'page.php';
            $templates[] = 'singular.php';
        } elseif (!empty($query->is_front_page) && !empty($query->is_home)) {
            // Latest posts on the front.
            $templates[] = 'front-page.php';
            $templates[] = 'home.php';
        } elseif ($query->is_home) {
            // Blog posts index (including page_for_posts when static front is used).
            $templates[] = 'home.php';
        } elseif ($query->is_page) {
            $post = self::queriedPost($query);
            if ($post instanceof AP_Post) {
                $custom = class_exists('AP_Post', false)
                    ? AP_Post::getPageTemplate($post->ID, $db)
                    : 'default';
                if (is_string($custom) && $custom !== '' && $custom !== 'default') {
                    $custom = ltrim(str_replace('\\', '/', $custom), '/');
                    // Allow "full-width" or "full-width.php" or "templates/x.php".
                    if (!str_ends_with($custom, '.php')) {
                        $templates[] = $custom . '.php';
                    }
                    $templates[] = $custom;
                }
                $slug = (string) $post->post_name;
                if ($slug !== '') {
                    $templates[] = 'page-' . $slug . '.php';
                }
                $templates[] = 'page-' . $post->ID . '.php';
            }
            $templates[] = 'page.php';
            $templates[] = 'singular.php';
        } elseif ($query->is_single) {
            $post = self::queriedPost($query);
            $type = $post instanceof AP_Post ? (string) $post->post_type : 'post';
            $slug = $post instanceof AP_Post ? (string) $post->post_name : '';
            if ($type === 'attachment') {
                $mime = $post instanceof AP_Post ? (string) $post->post_mime_type : '';
                if ($mime !== '' && str_contains($mime, '/')) {
                    [$major, $minor] = explode('/', $mime, 2);
                    $minor = str_replace(['+', '.'], '-', $minor);
                    $templates[] = $major . '-' . $minor . '.php';
                    $templates[] = $minor . '.php';
                    $templates[] = $major . '.php';
                }
                $templates[] = 'attachment.php';
            }
            if ($slug !== '') {
                $templates[] = 'single-' . $type . '-' . $slug . '.php';
            }
            if ($type !== '' && $type !== 'post') {
                $templates[] = 'single-' . $type . '.php';
            } elseif ($type === 'post') {
                $templates[] = 'single-post.php';
            }
            $templates[] = 'single.php';
            $templates[] = 'singular.php';
        } elseif ($query->is_category) {
            $slug = (string) $query->get('category_name', '');
            $catId = (int) $query->get('cat', 0);
            if ($slug !== '') {
                $templates[] = 'category-' . $slug . '.php';
            }
            if ($catId > 0) {
                $templates[] = 'category-' . $catId . '.php';
            }
            $templates[] = 'category.php';
            $templates[] = 'archive.php';
        } elseif ($query->is_tag) {
            $slug = (string) $query->get('tag', '');
            $tagId = (int) $query->get('tag_id', 0);
            // tag may be comma-separated; use first slug.
            if ($slug !== '' && str_contains($slug, ',')) {
                $slug = explode(',', $slug, 2)[0];
            }
            $slug = self::sanitizeSlug($slug);
            if ($slug !== '') {
                $templates[] = 'tag-' . $slug . '.php';
            }
            if ($tagId > 0) {
                $templates[] = 'tag-' . $tagId . '.php';
            }
            $templates[] = 'tag.php';
            $templates[] = 'archive.php';
        } elseif ($query->is_tax) {
            $tax = '';
            $termSlug = '';
            $taxQuery = $query->get('tax_query', []);
            if (is_array($taxQuery) && $taxQuery !== []) {
                $first = $taxQuery[0] ?? null;
                if (is_array($first)) {
                    $tax = self::sanitizeSlug((string) ($first['taxonomy'] ?? ''));
                    $terms = $first['terms'] ?? [];
                    if (is_array($terms) && $terms !== []) {
                        $termSlug = self::sanitizeSlug((string) $terms[0]);
                    } elseif (is_string($terms) && $terms !== '') {
                        $termSlug = self::sanitizeSlug($terms);
                    }
                }
            }
            if ($tax !== '' && $termSlug !== '') {
                $templates[] = 'taxonomy-' . $tax . '-' . $termSlug . '.php';
            }
            if ($tax !== '') {
                $templates[] = 'taxonomy-' . $tax . '.php';
            }
            $templates[] = 'taxonomy.php';
            $templates[] = 'archive.php';
        } elseif ($query->is_author) {
            $authorName = self::sanitizeSlug((string) $query->get('author_name', ''));
            $authorId = (int) $query->get('author', 0);
            if ($authorName !== '') {
                $templates[] = 'author-' . $authorName . '.php';
            }
            if ($authorId > 0) {
                $templates[] = 'author-' . $authorId . '.php';
            }
            $templates[] = 'author.php';
            $templates[] = 'archive.php';
        } elseif ($query->is_date) {
            $templates[] = 'date.php';
            $templates[] = 'archive.php';
        } elseif ($query->is_post_type_archive) {
            $types = $query->get('post_type', 'post');
            $type = is_array($types) ? (string) ($types[0] ?? '') : (string) $types;
            $type = self::sanitizeSlug($type);
            if ($type !== '') {
                $templates[] = 'archive-' . $type . '.php';
            }
            $templates[] = 'archive.php';
        } elseif ($query->is_archive) {
            $templates[] = 'archive.php';
        }

        $templates[] = 'index.php';

        // De-duplicate while preserving order.
        $unique = [];
        $seen = [];
        foreach ($templates as $t) {
            $t = str_replace('\\', '/', (string) $t);
            $t = ltrim($t, '/');
            if ($t === '' || isset($seen[$t])) {
                continue;
            }
            // Path traversal guard on candidate names.
            if (str_contains($t, '..')) {
                continue;
            }
            $seen[$t] = true;
            $unique[] = $t;
        }

        if (function_exists('ap_apply_filters')) {
            /** @var list<string> $unique */
            $unique = ap_apply_filters('ap_template_hierarchy', $unique, $query);
            if (!is_array($unique)) {
                $unique = ['index.php'];
            }
        }

        return array_values($unique);
    }

    /**
     * Locate the first existing template among candidates (child then parent).
     *
     * @param list<string>|string $templates Relative theme paths (e.g. single.php).
     * @param bool                $load      When true, load the located template.
     * @param bool                $requireOnce
     * @param array<string, mixed> $args     Extracted into template scope when loading.
     *
     * @return string Absolute path to the template, or empty string if none found.
     */
    public static function locateTemplate(
        array|string $templates,
        bool $load = false,
        bool $requireOnce = true,
        array $args = [],
        ?AP_DB $db = null
    ): string {
        if (is_string($templates)) {
            $templates = [$templates];
        }

        $stylesheetDir = self::getStylesheetDirectory($db);
        $templateDir = self::getTemplateDirectory($db);
        $searchDirs = array_values(array_unique([$stylesheetDir, $templateDir]));

        $located = '';
        foreach ($templates as $template) {
            $template = str_replace('\\', '/', (string) $template);
            $template = ltrim($template, '/');
            if ($template === '' || str_contains($template, '..')) {
                continue;
            }

            foreach ($searchDirs as $dir) {
                $path = $dir . '/' . $template;
                if (is_file($path) && self::isPathInsideTheme($path, $searchDirs)) {
                    $located = $path;
                    break 2;
                }
            }
        }

        if ($load && $located !== '') {
            self::loadTemplate($located, $requireOnce, $args);
        }

        return $located;
    }

    /**
     * Include a template file with $args available and common globals set.
     *
     * @param array<string, mixed> $args
     */
    public static function loadTemplate(string $path, bool $requireOnce = true, array $args = []): void
    {
        if ($path === '' || !is_file($path)) {
            return;
        }

        // Expose main query / current post for pure PHP templates.
        global $ap_query, $ap_post;
        if (isset($GLOBALS['ap_query']) && $GLOBALS['ap_query'] instanceof AP_Query) {
            $ap_query = $GLOBALS['ap_query'];
        }
        if (isset($GLOBALS['ap_post']) && $GLOBALS['ap_post'] instanceof AP_Post) {
            $ap_post = $GLOBALS['ap_post'];
        } elseif (
            isset($ap_query)
            && $ap_query instanceof AP_Query
            && $ap_query->post instanceof AP_Post
        ) {
            $ap_post = $ap_query->post;
            $GLOBALS['ap_post'] = $ap_post;
        }

        if ($args !== []) {
            // Prefer extract for classic template familiarity (scoped to this function).
            extract($args, EXTR_SKIP);
        }

        if ($requireOnce) {
            require_once $path;
        } else {
            require $path;
        }
    }

    /**
     * Load header.php or header-{$name}.php from the active theme stack.
     *
     * @param array<string, mixed> $args
     */
    public static function getHeader(string $name = '', array $args = [], ?AP_DB $db = null): void
    {
        $templates = [];
        if ($name !== '') {
            $templates[] = 'header-' . $name . '.php';
        }
        $templates[] = 'header.php';
        self::locateTemplate($templates, true, false, $args, $db);
    }

    /**
     * Load footer.php or footer-{$name}.php.
     *
     * @param array<string, mixed> $args
     */
    public static function getFooter(string $name = '', array $args = [], ?AP_DB $db = null): void
    {
        $templates = [];
        if ($name !== '') {
            $templates[] = 'footer-' . $name . '.php';
        }
        $templates[] = 'footer.php';
        self::locateTemplate($templates, true, false, $args, $db);
    }

    /**
     * Load sidebar.php or sidebar-{$name}.php.
     *
     * @param array<string, mixed> $args
     */
    public static function getSidebar(string $name = '', array $args = [], ?AP_DB $db = null): void
    {
        $templates = [];
        if ($name !== '') {
            $templates[] = 'sidebar-' . $name . '.php';
        }
        $templates[] = 'sidebar.php';
        self::locateTemplate($templates, true, false, $args, $db);
    }

    /**
     * Load parent then child functions.php once per request.
     *
     * When the Classic WP Theme Compatibility Layer is active for the theme,
     * WP shims load first and functions.php is included via the safe loader.
     */
    public static function setup(?AP_DB $db = null): void
    {
        if (self::$setupDone) {
            return;
        }
        self::$setupDone = true;

        // Load WP shims before theme functions.php when compat mode allows.
        if (class_exists('AP_Theme_Compat', false)) {
            AP_Theme_Compat::beforeThemeSetup($db);
        }

        $templateDir = self::getTemplateDirectory($db);
        $stylesheetDir = self::getStylesheetDirectory($db);

        $files = [];
        $parentFn = $templateDir . '/functions.php';
        if (is_file($parentFn)) {
            $files[] = $parentFn;
        }
        if ($stylesheetDir !== $templateDir) {
            $childFn = $stylesheetDir . '/functions.php';
            if (is_file($childFn)) {
                $files[] = $childFn;
            }
        }

        $useSafeLoad = class_exists('AP_Theme_Compat', false) && AP_Theme_Compat::isActive($db);
        foreach ($files as $file) {
            if ($useSafeLoad) {
                AP_Theme_Compat::safeLoadFunctionsPhp($file, $db);
            } else {
                require_once $file;
            }
        }

        // Re-register theme hooks after a mid-process hook reset (tests) when the
        // active theme exposes a conventional {slug}_register_theme_hooks() helper.
        // Parent first, then child (mirrors functions.php load order).
        $stylesheet = self::getStylesheet($db);
        $template = self::getTemplate($db);
        $hookFns = [];
        if ($template !== $stylesheet) {
            $hookFns[] = str_replace('-', '_', $template) . '_register_theme_hooks';
        }
        $hookFns[] = str_replace('-', '_', $stylesheet) . '_register_theme_hooks';
        foreach (array_unique($hookFns) as $fn) {
            if (function_exists($fn)) {
                $fn();
            }
        }

        if (function_exists('ap_do_action')) {
            ap_do_action('ap_after_setup_theme');
        }
    }

    /**
     * Resolve hierarchy, locate template, and load it for the main query.
     *
     * Safe fallback HTML is printed when no theme template is available.
     */
    public static function render(?AP_Query $query = null, ?AP_DB $db = null): void
    {
        $query = self::resolveQuery($query);
        ap_set_query($query);

        // Seed global post for singular views before template tags run.
        if ($query->post instanceof AP_Post) {
            $GLOBALS['ap_post'] = $query->post;
        }

        self::setup($db);

        $hierarchy = self::getHierarchy($query, $db);
        $template = self::locateTemplate($hierarchy, false, true, [], $db);

        if (function_exists('ap_apply_filters')) {
            $filtered = ap_apply_filters('ap_template_include', $template, $query);
            if (is_string($filtered)) {
                $template = $filtered;
            }
        }

        if ($template !== '' && is_file($template)) {
            if (!headers_sent()) {
                if ($query->is_404) {
                    http_response_code(404);
                }
                header('Content-Type: text/html; charset=utf-8');
            }
            // require (not once): templates may run more than once per process (tests, nested).
            self::loadTemplate($template, false, []);

            return;
        }

        // No theme / no index.php — minimal emergency output.
        if (!headers_sent()) {
            http_response_code($query->is_404 ? 404 : 200);
            header('Content-Type: text/html; charset=utf-8');
        }
        echo self::fallbackHtml($query);
    }

    /**
     * Reset static state (unit tests).
     */
    public static function reset(): void
    {
        self::$setupDone = false;
        self::$themesRootOverride = null;
        self::$stylesheetOverride = null;
        self::$templateOverride = null;
        self::$customCssRegistered = false;
        if (class_exists('AP_Assets', false)) {
            AP_Assets::reset();
        }
        if (class_exists('AP_Theme_Compat', false)) {
            AP_Theme_Compat::reset();
        }
    }

    // -------------------------------------------------------------------------
    // Additional CSS (Appearance → Theme Options)
    // -------------------------------------------------------------------------

    /** Option: site-owner Additional CSS (printed in ap_head). */
    public const OPTION_CUSTOM_CSS = 'custom_css';

    /** Soft cap for stored Additional CSS (bytes). */
    public const CUSTOM_CSS_MAX_BYTES = 102400;

    /** @var bool Whether custom CSS printer was registered this request. */
    private static bool $customCssRegistered = false;

    /**
     * Register ap_head printer for Additional CSS (idempotent).
     */
    public static function registerCustomCss(): void
    {
        if (self::$customCssRegistered) {
            return;
        }
        self::$customCssRegistered = true;

        if (!function_exists('ap_add_action')) {
            return;
        }

        ap_add_action('ap_head', [self::class, 'printCustomCss'], 99);
    }

    /**
     * Read Additional CSS from options.
     */
    public static function getCustomCss(?AP_DB $db = null): string
    {
        $raw = self::readOption(self::OPTION_CUSTOM_CSS, '', $db);

        return is_string($raw) ? $raw : '';
    }

    /**
     * Sanitize Additional CSS for storage.
     *
     * Strips null bytes, closes out of any accidental </style>, and blocks
     * common XSS vectors. Does not attempt a full CSS parser.
     */
    public static function sanitizeCustomCss(string $css): string
    {
        $css = str_replace("\0", '', $css);
        // Normalize newlines.
        $css = str_replace(["\r\n", "\r"], "\n", $css);
        // Prevent breaking out of the <style> wrapper.
        $css = preg_replace('/<\/\s*style\b[^>]*>/i', '', $css) ?? $css;
        // Block HTML/script injection patterns that should never appear in CSS.
        $css = preg_replace('/<\s*script\b[^>]*>.*?<\s*\/\s*script\s*>/is', '', $css) ?? $css;
        $css = preg_replace('/<\s*\/?\s*(script|iframe|object|embed|link|meta|base)\b[^>]*>/i', '', $css) ?? $css;
        $css = preg_replace('/expression\s*\(/i', '', $css) ?? $css;
        $css = preg_replace('/javascript\s*:/i', '', $css) ?? $css;
        $css = preg_replace('/vbscript\s*:/i', '', $css) ?? $css;
        $css = preg_replace('/-moz-binding\s*:/i', '', $css) ?? $css;
        $css = preg_replace('/behavior\s*:/i', '', $css) ?? $css;

        if (strlen($css) > self::CUSTOM_CSS_MAX_BYTES) {
            $css = substr($css, 0, self::CUSTOM_CSS_MAX_BYTES);
        }

        return $css;
    }

    /**
     * Persist Additional CSS. Returns true when saved (or unchanged empty).
     */
    public static function updateCustomCss(string $css, ?AP_DB $db = null): bool
    {
        $clean = self::sanitizeCustomCss($css);

        return self::writeOption(self::OPTION_CUSTOM_CSS, $clean, $db);
    }

    /**
     * Print Additional CSS inside a style tag on the public front-end.
     */
    public static function printCustomCss(?AP_DB $db = null): void
    {
        $css = self::getCustomCss($db);
        $css = self::sanitizeCustomCss($css);
        if (trim($css) === '') {
            return;
        }

        // Final safety: never emit closing style / script tags.
        $css = str_replace(['</style', '</STYLE', '</script', '</SCRIPT'], '', $css);

        echo '<style id="ap-custom-css">' . "\n" . $css . "\n" . '</style>' . "\n";
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    private static function themeDirExists(string $slug): bool
    {
        $slug = self::sanitizeSlug($slug);
        if ($slug === '') {
            return false;
        }

        return is_dir(self::themesRoot() . '/' . $slug);
    }

    private static function themeUri(string $slug, ?AP_DB $db = null): string
    {
        $slug = self::sanitizeSlug($slug);
        $base = '';

        if (defined('AP_CONTENT_URL') && is_string(AP_CONTENT_URL) && AP_CONTENT_URL !== '') {
            $base = rtrim((string) AP_CONTENT_URL, '/') . '/themes';
        } elseif (class_exists('AP_Rewrite', false)) {
            $home = AP_Rewrite::homeUrl('', $db);
            $base = rtrim($home, '/') . '/ap-content/themes';
        } else {
            $base = '/ap-content/themes';
        }

        return $base . '/' . $slug;
    }

    private static function sanitizeSlug(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9_\\-]+/', '-', $value) ?? $value;
        $value = preg_replace('/\\-+/', '-', $value) ?? $value;

        return trim($value, '-');
    }

    /**
     * @param list<string> $themeDirs
     */
    private static function isPathInsideTheme(string $path, array $themeDirs): bool
    {
        $real = realpath($path);
        if ($real === false) {
            return false;
        }

        foreach ($themeDirs as $dir) {
            $root = realpath($dir);
            if ($root === false) {
                continue;
            }
            if ($real === $root || str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
                return true;
            }
        }

        return false;
    }

    private static function resolveQuery(?AP_Query $query): AP_Query
    {
        if ($query instanceof AP_Query) {
            return $query;
        }

        if (isset($GLOBALS['ap_query']) && $GLOBALS['ap_query'] instanceof AP_Query) {
            return $GLOBALS['ap_query'];
        }

        return new AP_Query([]);
    }

    private static function queriedPost(AP_Query $query): ?AP_Post
    {
        if ($query->post instanceof AP_Post) {
            return $query->post;
        }

        if ($query->post_count > 0 && ($query->posts[0] ?? null) instanceof AP_Post) {
            return $query->posts[0];
        }

        return null;
    }

    /**
     * @param array<string, string> $found
     */
    private static function scanPageTemplates(string $root, string $dir, array &$found): void
    {
        $entries = @scandir($dir);
        if (!is_array($entries)) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                // One level of nesting (e.g. templates/).
                if (substr_count(str_replace('\\', '/', substr($path, strlen($root))), '/') <= 1) {
                    self::scanPageTemplates($root, $path, $found);
                }
                continue;
            }
            if (!str_ends_with(strtolower($entry), '.php')) {
                continue;
            }
            // Skip partials that are not page templates.
            $base = strtolower($entry);
            $skipBases = [
                'functions.php',
                'header.php',
                'footer.php',
                'sidebar.php',
                'index.php',
                'single.php',
                'page.php',
                'home.php',
                'archive.php',
                'search.php',
                '404.php',
                'comments.php',
            ];
            if (in_array($base, $skipBases, true)) {
                continue;
            }

            $name = self::readTemplateNameHeader($path);
            if ($name === null || $name === '') {
                continue;
            }

            $rel = ltrim(str_replace('\\', '/', substr($path, strlen($root))), '/');
            if ($rel === '' || str_contains($rel, '..')) {
                continue;
            }
            // Child wins: do not overwrite an already-found relative path.
            if (!isset($found[$rel])) {
                $found[$rel] = $name;
            }
        }
    }

    private static function readTemplateNameHeader(string $path): ?string
    {
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return null;
        }
        $chunk = (string) fread($fh, 4096);
        fclose($fh);

        if (preg_match('/^[ \\t\\/*#@]*Template Name:[ \\t]*(.*)$/mi', $chunk, $m) === 1) {
            return trim((string) $m[1]);
        }

        return null;
    }

    private static function fallbackHtml(AP_Query $query): string
    {
        $title = 'AgoraPress';
        $body = '<p>No theme template was found. Activate the default <code>agora</code> theme '
            . 'or add an <code>index.php</code> to the active theme.</p>';

        if ($query->is_404) {
            $title = 'Not Found';
            $body = '<p>The requested content could not be found.</p>';
        } elseif ($query->post_count > 0 && ($query->posts[0] ?? null) instanceof AP_Post) {
            $parts = [];
            foreach ($query->posts as $post) {
                if (!$post instanceof AP_Post) {
                    continue;
                }
                $t = htmlspecialchars((string) $post->post_title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $c = htmlspecialchars((string) $post->post_content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $parts[] = '<article><h2>' . $t . '</h2><div>' . nl2br($c) . '</div></article>';
            }
            if ($parts !== []) {
                $body = implode("\n", $parts);
            }
        }

        return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</title>'
            . '</head><body><main>' . $body . '</main></body></html>';
    }

    private static function readOption(string $name, string $default = '', ?AP_DB $db = null): string
    {
        $db = self::resolveDbOptional($db);
        if ($db === null) {
            return $default;
        }
        try {
            $val = $db->getVar(
                'SELECT option_value FROM ' . $db->quoteIdentifier($db->table('options'))
                . ' WHERE option_name = ? LIMIT 1',
                [$name]
            );
        } catch (Throwable) {
            return $default;
        }

        return $val !== null ? (string) $val : $default;
    }

    private static function writeOption(string $name, string $value, ?AP_DB $db = null): bool
    {
        $db = self::resolveDbOptional($db);
        if ($db === null) {
            return false;
        }
        try {
            $existing = $db->getVar(
                'SELECT option_id FROM ' . $db->quoteIdentifier($db->table('options'))
                . ' WHERE option_name = ? LIMIT 1',
                [$name]
            );
            if ($existing !== null) {
                return $db->update(
                    'options',
                    ['option_value' => $value],
                    ['option_name' => $name]
                ) !== false;
            }

            return $db->insert('options', [
                'option_name' => $name,
                'option_value' => $value,
                'autoload' => 'yes',
            ]) !== false;
        } catch (Throwable) {
            return false;
        }
    }

    private static function resolveDbOptional(?AP_DB $db): ?AP_DB
    {
        if ($db instanceof AP_DB) {
            return $db;
        }
        if (function_exists('ap_db')) {
            try {
                return ap_db();
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }
}
