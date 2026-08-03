<?php

/**
 * Agora default theme — setup, template tags, color schemes, forum templates.
 *
 * Six pure-CSS schemes (3 light + 3 dark), selectable via the
 * `agora_color_scheme` option / Theme Options admin screen.
 * Blog + forum front-end templates share the same tokens and layout shell.
 *
 * @package Agora
 */

declare(strict_types=1);

/** Option name storing the active color scheme slug. */
const AGORA_COLOR_SCHEME_OPTION = 'agora_color_scheme';

/** Default scheme (light marble). */
const AGORA_DEFAULT_COLOR_SCHEME = 'marble';

/** Stylesheet version (fallback when style.css header is unavailable). */
const AGORA_THEME_VERSION = '0.3.0';

/**
 * Register theme chrome: nav locations + modular sidebars (idempotent).
 *
 * Called at load and again from {@see agora_register_theme_hooks()} so locations
 * survive AP_Nav_Menu::reset() / mid-request hook resets in tests and admin.
 */
function agora_register_theme_chrome(): void
{
    // Theme menu locations (Primary + Footer) — fully controllable from Appearance → Menus.
    if (function_exists('ap_register_nav_menus')) {
        ap_register_nav_menus([
            'primary' => 'Primary',
            'footer' => 'Footer',
        ]);
    } elseif (class_exists('AP_Nav_Menu', false)) {
        AP_Nav_Menu::registerLocations([
            'primary' => 'Primary',
            'footer' => 'Footer',
        ]);
    }

    // Modular widget areas (Primary Sidebar + Footer).
    if (function_exists('ap_register_sidebar')) {
        ap_register_sidebar('sidebar-1', [
            'name' => 'Primary Sidebar',
            'description' => 'Widgets beside main content on blog and archive views.',
            'before_widget' => '<section id="%1$s" class="widget %2$s">',
            'after_widget' => '</section>',
            'before_title' => '<h2 class="widget-title">',
            'after_title' => '</h2>',
        ]);
        ap_register_sidebar('footer-1', [
            'name' => 'Footer',
            'description' => 'Widgets in the site footer.',
            'before_widget' => '<section id="%1$s" class="widget widget--footer %2$s">',
            'after_widget' => '</section>',
            'before_title' => '<h2 class="widget-title">',
            'after_title' => '</h2>',
        ]);
    } elseif (class_exists('AP_Widgets', false)) {
        AP_Widgets::registerSidebar('sidebar-1', [
            'name' => 'Primary Sidebar',
            'description' => 'Widgets beside main content on blog and archive views.',
        ]);
        AP_Widgets::registerSidebar('footer-1', [
            'name' => 'Footer',
            'description' => 'Widgets in the site footer.',
        ]);
    }
}

/**
 * Register Agora theme hooks (idempotent; safe after ap_reset_hooks in tests).
 */
function agora_register_theme_hooks(): void
{
    agora_register_theme_chrome();

    if (!function_exists('ap_add_action')) {
        return;
    }
    ap_add_action('ap_enqueue_scripts', 'agora_enqueue_assets');
    if (function_exists('ap_add_filter')) {
        ap_add_filter('ap_template_hierarchy', 'agora_forum_template_hierarchy', 10, 2);
        ap_add_filter('ap_body_class', 'agora_filter_body_class', 10, 2);
    }
}

// Front-end styles via the native enqueue API (parent style first when child).
agora_register_theme_hooks();
if (function_exists('ap_add_action')) {
    // Re-bind when AP_Theme::setup fires after_setup_theme (e.g. after hook reset).
    ap_add_action('ap_after_setup_theme', 'agora_register_theme_hooks');
}

/**
 * Enqueue Agora (or child-of-Agora) stylesheets.
 *
 * When a child theme is active, loads parent style.css then the child's
 * style.css with a dependency so cascade order is correct.
 */
function agora_enqueue_assets(): void
{
    if (!function_exists('ap_enqueue_style')) {
        return;
    }

    $ver = AGORA_THEME_VERSION;
    $headers = function_exists('ap_get_theme_headers') ? ap_get_theme_headers('agora') : null;
    if (is_array($headers) && !empty($headers['Version'])) {
        $ver = (string) $headers['Version'];
    }

    $templateUri = function_exists('ap_get_template_uri') ? ap_get_template_uri() : '';
    $styleCss = function_exists('ap_get_style_css_uri')
        ? ap_get_style_css_uri()
        : (function_exists('ap_get_stylesheet_uri') ? ap_get_stylesheet_uri() . '/style.css' : '');

    $isChild = function_exists('ap_is_child_theme') && ap_is_child_theme();
    if ($isChild && $templateUri !== '') {
        ap_enqueue_style('agora-parent', $templateUri . '/style.css', [], $ver);
        if ($styleCss !== '') {
            ap_enqueue_style('agora-style', $styleCss, ['agora-parent'], $ver);
        }
    } elseif ($styleCss !== '') {
        ap_enqueue_style('agora-style', $styleCss, [], $ver);
    }
}

/**
 * Catalog of the six built-in color schemes.
 *
 * Keys are stable slugs used in options, body classes, and CSS.
 *
 * @return array<string, array{label: string, mode: string, description: string}>
 */
function agora_get_color_schemes(): array
{
    return [
        'marble' => [
            'label' => 'Marble',
            'mode' => 'light',
            'description' => 'Cool stone white with indigo accents.',
        ],
        'parchment' => [
            'label' => 'Parchment',
            'mode' => 'light',
            'description' => 'Warm paper cream with terracotta links.',
        ],
        'cloud' => [
            'label' => 'Cloud',
            'mode' => 'light',
            'description' => 'Airy sky blue-gray with cyan accents.',
        ],
        'obsidian' => [
            'label' => 'Obsidian',
            'mode' => 'dark',
            'description' => 'Volcanic near-black with violet edges.',
        ],
        'midnight' => [
            'label' => 'Midnight',
            'mode' => 'dark',
            'description' => 'Deep navy night with electric blue.',
        ],
        'charcoal' => [
            'label' => 'Charcoal',
            'mode' => 'dark',
            'description' => 'Warm graphite with amber highlights.',
        ],
    ];
}

/**
 * Whether a slug is one of the six built-in schemes.
 */
function agora_is_valid_color_scheme(string $slug): bool
{
    return array_key_exists(agora_sanitize_color_scheme_raw($slug), agora_get_color_schemes());
}

/**
 * Normalize a scheme slug (lowercase a-z only); does not fall back.
 */
function agora_sanitize_color_scheme_raw(string $slug): string
{
    $slug = strtolower(trim($slug));
    $slug = preg_replace('/[^a-z0-9\-]/', '', $slug) ?? '';

    return $slug;
}

/**
 * Sanitize to a known scheme slug, defaulting to Marble when invalid.
 */
function agora_sanitize_color_scheme(string $slug): string
{
    $slug = agora_sanitize_color_scheme_raw($slug);
    $schemes = agora_get_color_schemes();
    if ($slug !== '' && isset($schemes[$slug])) {
        return $slug;
    }

    return AGORA_DEFAULT_COLOR_SCHEME;
}

/**
 * Active color scheme slug (defaults to marble).
 */
function agora_get_color_scheme(?AP_DB $db = null): string
{
    $raw = agora_read_option(AGORA_COLOR_SCHEME_OPTION, AGORA_DEFAULT_COLOR_SCHEME, $db);

    return agora_sanitize_color_scheme($raw);
}

/**
 * Persist the active color scheme. Returns false for unknown slugs.
 */
function agora_set_color_scheme(string $slug, ?AP_DB $db = null): bool
{
    $slug = agora_sanitize_color_scheme_raw($slug);
    if (!agora_is_valid_color_scheme($slug)) {
        return false;
    }

    return agora_write_option(AGORA_COLOR_SCHEME_OPTION, $slug, $db);
}

/**
 * light|dark for the given (or active) scheme.
 */
function agora_get_color_scheme_mode(?string $slug = null, ?AP_DB $db = null): string
{
    $slug = $slug !== null ? agora_sanitize_color_scheme($slug) : agora_get_color_scheme($db);
    $schemes = agora_get_color_schemes();

    return (string) ($schemes[$slug]['mode'] ?? 'light');
}

/**
 * Space-separated body classes for the active scheme.
 *
 * Always includes `agora-theme` and `agora-scheme-{slug}` plus `agora-mode-light|dark`.
 * Forum views also get `agora-forum` and a view-specific class.
 */
function agora_body_class(?AP_DB $db = null): string
{
    $scheme = agora_get_color_scheme($db);
    $mode = agora_get_color_scheme_mode($scheme, $db);
    $classes = [
        'agora-theme',
        'agora-scheme-' . $scheme,
        'agora-mode-' . $mode,
    ];

    $forumView = agora_get_forum_view();
    if ($forumView !== '') {
        $classes[] = 'agora-forum';
        $classes[] = 'agora-forum--' . $forumView;
        $classes[] = 'layout-wide';
    }

    return implode(' ', $classes);
}

/**
 * Append forum / layout classes onto core body classes (idempotent).
 *
 * @param list<string>  $classes
 * @param AP_Query|null $query
 *
 * @return list<string>
 */
function agora_filter_body_class(array $classes, $query = null): array
{
    $forumView = agora_get_forum_view($query instanceof AP_Query ? $query : null);
    if ($forumView === '') {
        return $classes;
    }

    $add = ['agora-forum', 'agora-forum--' . $forumView, 'layout-wide'];
    $seen = array_fill_keys($classes, true);
    foreach ($add as $c) {
        if (!isset($seen[$c])) {
            $classes[] = $c;
            $seen[$c] = true;
        }
    }

    return $classes;
}

/**
 * Home URL for theme templates (safe when rewrite is not loaded).
 */
function agora_home_url(string $path = '/'): string
{
    if (function_exists('ap_home_url') && class_exists('AP_Rewrite', false)) {
        return ap_home_url($path);
    }

    // Minimal fallback for isolated tests / early boot.
    $base = '/';
    if (isset($GLOBALS['apdb']) && $GLOBALS['apdb'] instanceof AP_DB) {
        try {
            $val = $GLOBALS['apdb']->getVar(
                'SELECT option_value FROM ' . $GLOBALS['apdb']->quoteIdentifier($GLOBALS['apdb']->table('options'))
                . ' WHERE option_name = ? LIMIT 1',
                ['home']
            );
            if (is_string($val) && $val !== '') {
                $base = rtrim($val, '/');
            }
        } catch (Throwable) {
            // keep default
        }
    }
    if ($path === '' || $path === '/') {
        return $base === '/' ? '/' : $base . '/';
    }
    if ($path[0] !== '/') {
        $path = '/' . $path;
    }

    return ($base === '/' ? '' : $base) . $path;
}

// -----------------------------------------------------------------------------
// Forum view resolution + template hierarchy
// -----------------------------------------------------------------------------

/**
 * Active forum front-end view for the current (or given) query.
 *
 * Recognized values: index | forum | topic | search (empty when not a forum request).
 * Driven by query vars set by the rewrite layer + {@see AP_Forum_Front}:
 * - ap_forum_view: explicit view slug (index|forum|topic|search)
 * - topic_id: implies topic
 * - forum_id: implies forum
 * - ap_forum: non-empty implies index
 */
function agora_get_forum_view(?AP_Query $query = null): string
{
    $q = $query;
    if (!$q instanceof AP_Query && isset($GLOBALS['ap_query']) && $GLOBALS['ap_query'] instanceof AP_Query) {
        $q = $GLOBALS['ap_query'];
    }

    $view = '';
    if ($q instanceof AP_Query) {
        $view = strtolower(trim((string) $q->get('ap_forum_view', '')));
        if ($view === '' && (int) $q->get('topic_id', 0) > 0) {
            $view = 'topic';
        } elseif ($view === '' && (int) $q->get('forum_id', 0) > 0) {
            $view = 'forum';
        } elseif ($view === '') {
            $flag = $q->get('ap_forum', null);
            if ($flag !== null && $flag !== '' && $flag !== false && $flag !== 0 && $flag !== '0') {
                $view = 'index';
            }
        }
    }

    // Allow templates / tests to set a request-level override without a query object.
    if ($view === '' && isset($GLOBALS['agora_forum_view']) && is_string($GLOBALS['agora_forum_view'])) {
        $view = strtolower(trim($GLOBALS['agora_forum_view']));
    }

    $view = preg_replace('/[^a-z0-9\-]/', '', $view) ?? '';
    if (!in_array($view, ['index', 'forum', 'topic', 'search'], true)) {
        return '';
    }

    return $view;
}

/**
 * Prepend Agora forum templates when the request is a forum view.
 *
 * @param list<string>  $templates
 * @param AP_Query|null $query
 *
 * @return list<string>
 */
function agora_forum_template_hierarchy(array $templates, $query = null): array
{
    $view = agora_get_forum_view($query instanceof AP_Query ? $query : null);
    if ($view === '') {
        return $templates;
    }

    $prefix = match ($view) {
        'topic' => ['topic.php', 'forum-topic.php', 'single-topic.php'],
        'forum' => ['forum-view.php', 'single-forum.php'],
        'search' => ['forum-search.php', 'search-forum.php', 'forum.php'],
        default => ['forum.php', 'forums.php'],
    };

    $merged = array_merge($prefix, $templates);
    $unique = [];
    $seen = [];
    foreach ($merged as $t) {
        $t = str_replace('\\', '/', (string) $t);
        $t = ltrim($t, '/');
        if ($t === '' || isset($seen[$t]) || str_contains($t, '..')) {
            continue;
        }
        $seen[$t] = true;
        $unique[] = $t;
    }

    return $unique;
}

/**
 * Forum index categories/forums for templates (filterable).
 *
 * Each category: ['name' => string, 'forums' => list of forum rows].
 * Forum row: name, description, url, topics, posts, last_post (optional).
 * Loads live data from {@see AP_Forum} when the forum module is available.
 *
 * @return list<array{name: string, forums: list<array<string, mixed>>}>
 */
function agora_get_forum_index_data(): array
{
    $data = [];
    if (function_exists('ap_get_forum_index_data')) {
        try {
            $data = ap_get_forum_index_data();
        } catch (Throwable) {
            $data = [];
        }
    } elseif (class_exists('AP_Forum', false)) {
        try {
            $data = AP_Forum::getIndexData();
        } catch (Throwable) {
            $data = [];
        }
    }
    if (function_exists('ap_apply_filters')) {
        $filtered = ap_apply_filters('agora_forum_index_data', $data);
        if (is_array($filtered)) {
            $data = $filtered;
        }
    }

    return array_values($data);
}

/**
 * Topics for a single forum view (filterable).
 *
 * @return list<array<string, mixed>>
 */
function agora_get_forum_topics_data(int $forumId = 0): array
{
    $data = [];
    if ($forumId > 0) {
        $page = 1;
        if (isset($GLOBALS['ap_query']) && $GLOBALS['ap_query'] instanceof AP_Query) {
            $page = max(1, (int) $GLOBALS['ap_query']->get('paged', 1));
        }
        $args = ['per_page' => 20, 'page' => $page];
        if (function_exists('ap_get_forum_topics_data')) {
            try {
                $data = ap_get_forum_topics_data($forumId, $args);
            } catch (Throwable) {
                $data = [];
            }
        } elseif (class_exists('AP_Forum', false)) {
            try {
                $data = AP_Forum::getTopicsDisplayData($forumId, $args);
            } catch (Throwable) {
                $data = [];
            }
        }
    }
    if (function_exists('ap_apply_filters')) {
        $filtered = ap_apply_filters('agora_forum_topics_data', $data, $forumId);
        if (is_array($filtered)) {
            $data = $filtered;
        }
    }

    return array_values($data);
}

/**
 * Posts for a topic view (filterable).
 *
 * @return list<array<string, mixed>>
 */
function agora_get_topic_posts_data(int $topicId = 0): array
{
    $data = [];
    if ($topicId > 0) {
        $page = 1;
        if (isset($GLOBALS['ap_query']) && $GLOBALS['ap_query'] instanceof AP_Query) {
            $page = max(1, (int) $GLOBALS['ap_query']->get('paged', 1));
        }
        $args = ['per_page' => 20, 'page' => $page];
        if (function_exists('ap_get_topic_posts_data')) {
            try {
                $data = ap_get_topic_posts_data($topicId, $args);
            } catch (Throwable) {
                $data = [];
            }
        } elseif (class_exists('AP_Forum', false)) {
            try {
                $data = AP_Forum::getPostsDisplayData($topicId, $args);
            } catch (Throwable) {
                $data = [];
            }
        }
    }
    if (function_exists('ap_apply_filters')) {
        $filtered = ap_apply_filters('agora_topic_posts_data', $data, $topicId);
        if (is_array($filtered)) {
            $data = $filtered;
        }
    }

    return array_values($data);
}

/**
 * Forum flash notice for templates (wrapper around core helper).
 *
 * @return array{type: string, message: string}|null
 */
function agora_get_forum_notice(): ?array
{
    if (function_exists('ap_get_forum_notice')) {
        return ap_get_forum_notice();
    }
    if (class_exists('AP_Forum_Front', false)) {
        return AP_Forum_Front::getNotice();
    }

    return null;
}

/**
 * Forum search results for the current request (filterable).
 *
 * @return array{query: string, total: int, results: list<array<string, mixed>>}
 */
function agora_get_forum_search_data(): array
{
    $data = [
        'query' => '',
        'total' => 0,
        'results' => [],
    ];
    $q = isset($GLOBALS['ap_query']) && $GLOBALS['ap_query'] instanceof AP_Query
        ? $GLOBALS['ap_query']
        : null;
    if ($q instanceof AP_Query) {
        $data['query'] = trim((string) $q->get('forum_s', ''));
        if ($data['query'] === '') {
            $data['query'] = trim((string) $q->get('s', ''));
        }
        $data['total'] = (int) $q->get('forum_search_total', 0);
        $results = $q->get('forum_search_results', []);
        $data['results'] = is_array($results) ? array_values($results) : [];
    }
    if ($data['results'] === [] && $data['query'] !== '' && function_exists('ap_forum_search')) {
        try {
            $page = $q instanceof AP_Query ? max(1, (int) $q->get('paged', 1)) : 1;
            $userId = 0;
            if (function_exists('ap_get_current_user_id')) {
                $userId = (int) ap_get_current_user_id();
            } elseif (class_exists('AP_User', false) && method_exists('AP_User', 'getCurrentUserId')) {
                $userId = (int) AP_User::getCurrentUserId();
            }
            $search = ap_forum_search($data['query'], [
                'type' => 'all',
                'per_page' => 20,
                'page' => $page,
                'check_permissions' => true,
                'user_id' => $userId,
            ]);
            $data['total'] = (int) ($search['total'] ?? 0);
            $data['results'] = is_array($search['results'] ?? null) ? array_values($search['results']) : [];
        } catch (Throwable) {
            // keep empty
        }
    }
    if (function_exists('ap_apply_filters')) {
        $filtered = ap_apply_filters('agora_forum_search_data', $data);
        if (is_array($filtered)) {
            $data = $filtered;
        }
    }

    return [
        'query' => (string) ($data['query'] ?? ''),
        'total' => (int) ($data['total'] ?? 0),
        'results' => is_array($data['results'] ?? null) ? array_values($data['results']) : [],
    ];
}

/**
 * Forum search form action URL.
 */
function agora_forum_search_url(string $query = ''): string
{
    if (function_exists('ap_forum_search_url')) {
        return ap_forum_search_url($query);
    }
    if (class_exists('AP_Forum', false)) {
        return AP_Forum::searchUrl($query);
    }

    return function_exists('agora_home_url')
        ? agora_home_url('/forums/search/')
        : '/forums/search/';
}

/**
 * Escape helper used by theme templates.
 */
function agora_esc(string $text): string
{
    return function_exists('ap_esc_html')
        ? ap_esc_html($text)
        : htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Attribute escape helper.
 */
function agora_esc_attr(string $text): string
{
    return function_exists('ap_esc_attr')
        ? ap_esc_attr($text)
        : htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * URL escape helper.
 */
function agora_esc_url(string $url): string
{
    return function_exists('ap_esc_url')
        ? ap_esc_url($url)
        : htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Print a simple pagination nav when max_num_pages > 1.
 */
function agora_the_posts_pagination(?AP_Query $query = null): void
{
    $q = $query;
    if (!$q instanceof AP_Query && isset($GLOBALS['ap_query']) && $GLOBALS['ap_query'] instanceof AP_Query) {
        $q = $GLOBALS['ap_query'];
    }
    if (!$q instanceof AP_Query || $q->max_num_pages < 2) {
        return;
    }

    $current = max(1, (int) $q->get('paged', 1));
    $total = (int) $q->max_num_pages;
    $home = agora_home_url('/');

    echo '<nav class="ap-pagination" aria-label="Posts pagination">';

    // Previous
    if ($current > 1) {
        $prev = $current - 1;
        $url = $prev <= 1 ? $home : rtrim($home, '/') . '/page/' . $prev . '/';
        echo '<a href="' . agora_esc_url($url) . '" rel="prev">Previous</a>';
    } else {
        echo '<span class="disabled" aria-disabled="true">Previous</span>';
    }

    $start = max(1, $current - 2);
    $end = min($total, $current + 2);
    for ($i = $start; $i <= $end; $i++) {
        if ($i === $current) {
            echo '<span class="current" aria-current="page">' . $i . '</span>';
            continue;
        }
        $url = $i <= 1 ? $home : rtrim($home, '/') . '/page/' . $i . '/';
        echo '<a href="' . agora_esc_url($url) . '">' . $i . '</a>';
    }

    if ($current < $total) {
        $url = rtrim($home, '/') . '/page/' . ($current + 1) . '/';
        echo '<a href="' . agora_esc_url($url) . '" rel="next">Next</a>';
    } else {
        echo '<span class="disabled" aria-disabled="true">Next</span>';
    }

    echo '</nav>';
}

/**
 * Print entry meta (date + optional author) for the current post.
 */
function agora_the_entry_meta(): void
{
    $date = function_exists('agora_the_date') ? agora_the_date() : '';
    $author = function_exists('ap_get_the_author') ? ap_get_the_author() : '';
    if ($date === '' && $author === '') {
        return;
    }

    echo '<p class="ap-entry__meta">';
    if ($author !== '') {
        echo '<span class="ap-meta-author">' . agora_esc($author) . '</span>';
    }
    if ($date !== '') {
        $cls = $author !== '' ? ' class="ap-meta-sep"' : '';
        echo '<time' . $cls . ' datetime="' . agora_esc_attr($date) . '">'
            . agora_esc($date) . '</time>';
    }
    echo '</p>';
}

/**
 * Site title from options (blogname), with a safe default.
 */
function agora_site_name(?AP_DB $db = null): string
{
    $name = agora_read_option('blogname', '', $db);

    return $name !== '' ? $name : 'AgoraPress';
}

/**
 * Site tagline (blogdescription).
 */
function agora_site_description(?AP_DB $db = null): string
{
    return agora_read_option('blogdescription', '', $db);
}

/**
 * Escape and print the current post title.
 */
function agora_the_title(): void
{
    if (function_exists('ap_the_title')) {
        ap_the_title();

        return;
    }
    global $ap_post;
    $title = $ap_post instanceof AP_Post ? (string) $ap_post->post_title : '';
    echo function_exists('ap_esc_html') ? ap_esc_html($title) : htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Escape and print the current post content (basic newlines → breaks for MVP).
 */
function agora_the_content(): void
{
    if (function_exists('ap_the_content')) {
        ap_the_content();

        return;
    }
    global $ap_post;
    $content = $ap_post instanceof AP_Post ? (string) $ap_post->post_content : '';
    // Content pipeline: shortcodes + plain-text escape via ap_the_content filter.
    $escaped = function_exists('ap_esc_html')
        ? ap_esc_html($content)
        : htmlspecialchars($content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    echo nl2br($escaped, false);
}

/**
 * Permalink for the current post in the loop.
 */
function agora_the_permalink(): string
{
    if (function_exists('ap_get_the_permalink')) {
        return ap_get_the_permalink();
    }
    global $ap_post;
    if (!$ap_post instanceof AP_Post) {
        return '';
    }
    if (function_exists('ap_get_permalink') && class_exists('AP_Rewrite', false)) {
        return ap_get_permalink($ap_post);
    }

    return '?p=' . (int) $ap_post->ID;
}

/**
 * Formatted post date for the current post.
 */
function agora_the_date(): string
{
    if (function_exists('ap_get_the_date')) {
        return ap_get_the_date();
    }
    global $ap_post;
    if (!$ap_post instanceof AP_Post || $ap_post->post_date === '') {
        return '';
    }

    $ts = strtotime((string) $ap_post->post_date);

    return $ts !== false ? date('Y-m-d', $ts) : (string) $ap_post->post_date;
}

/**
 * Read a string option value (theme-local helper; Options API lands later).
 */
function agora_read_option(string $name, string $default = '', ?AP_DB $db = null): string
{
    try {
        if ($db === null && function_exists('ap_db')) {
            $db = ap_db();
        }
        if (!$db instanceof AP_DB) {
            return $default;
        }
        $val = $db->getVar(
            'SELECT option_value FROM ' . $db->quoteIdentifier($db->table('options'))
            . ' WHERE option_name = ? LIMIT 1',
            [$name]
        );
        if ($val === null || $val === '') {
            return $default;
        }

        return (string) $val;
    } catch (Throwable) {
        return $default;
    }
}

/**
 * Insert or update a string option.
 */
function agora_write_option(string $name, string $value, ?AP_DB $db = null): bool
{
    try {
        if ($db === null && function_exists('ap_db')) {
            $db = ap_db();
        }
        if (!$db instanceof AP_DB) {
            return false;
        }
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
