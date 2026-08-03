<?php

/**
 * AgoraPress front-end template tags.
 *
 * Classic WordPress-inspired helpers for themes: title, content, permalink,
 * date, author, body class, and site identity. Prefer these in core themes;
 * the Classic WP Theme Compatibility Layer (Phase 4) will alias bare WP names.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

// -----------------------------------------------------------------------------
// Loop post resolution
// -----------------------------------------------------------------------------

/**
 * Current post in the loop / singular view, or null.
 */
function ap_get_post_in_loop(): ?AP_Post
{
    if (isset($GLOBALS['ap_post']) && $GLOBALS['ap_post'] instanceof AP_Post) {
        return $GLOBALS['ap_post'];
    }
    if (isset($GLOBALS['ap_query']) && $GLOBALS['ap_query'] instanceof AP_Query) {
        $q = $GLOBALS['ap_query'];
        if ($q->post instanceof AP_Post) {
            return $q->post;
        }
    }

    return null;
}

/**
 * Current post ID, or 0.
 */
function ap_get_the_ID(): int
{
    $post = ap_get_post_in_loop();

    return $post instanceof AP_Post ? (int) $post->ID : 0;
}

/**
 * Echo the current post ID.
 */
function ap_the_ID(): void
{
    echo (string) ap_get_the_ID();
}

// -----------------------------------------------------------------------------
// Title
// -----------------------------------------------------------------------------

/**
 * Title for a post or the current loop post (unescaped).
 */
function ap_get_the_title(AP_Post|int|null $post = null): string
{
    $obj = ap_resolve_template_post($post);

    return $obj instanceof AP_Post ? (string) $obj->post_title : '';
}

/**
 * Echo the post title (escaped for HTML by default).
 *
 * @param string               $before Markup before the title.
 * @param string               $after  Markup after the title.
 * @param AP_Post|int|null     $post   Optional post; null = current.
 */
function ap_the_title(string $before = '', string $after = '', AP_Post|int|null $post = null): void
{
    $title = ap_get_the_title($post);
    if ($title === '') {
        return;
    }
    echo $before . ap_esc_html($title) . $after;
}

// -----------------------------------------------------------------------------
// Content / excerpt
// -----------------------------------------------------------------------------

/**
 * Post content (raw from DB; themes should escape or trust filtered HTML later).
 */
function ap_get_the_content(AP_Post|int|null $post = null): string
{
    $obj = ap_resolve_template_post($post);

    return $obj instanceof AP_Post ? (string) $obj->post_content : '';
}

/**
 * Echo post content.
 *
 * Runs the `ap_the_content` filter (core registers shortcode expansion + plain-text
 * escaping there). When no filter is available, falls back to escaped nl2br HTML.
 */
function ap_the_content(AP_Post|int|null $post = null): void
{
    $content = ap_get_the_content($post);
    if ($content === '') {
        return;
    }

    if (function_exists('ap_apply_filters')) {
        $filtered = ap_apply_filters('ap_the_content', $content, $post);
        if (is_string($filtered)) {
            $content = $filtered;
        }
    } elseif (class_exists('AP_Shortcode', false)) {
        $content = AP_Shortcode::formatContent($content);
    } else {
        $content = nl2br(ap_esc_html($content), false);
    }

    echo $content;
}

/**
 * Excerpt, or auto-trimmed content when empty.
 */
function ap_get_the_excerpt(AP_Post|int|null $post = null, int $words = 55): string
{
    $obj = ap_resolve_template_post($post);
    if (!$obj instanceof AP_Post) {
        return '';
    }

    $excerpt = trim((string) $obj->post_excerpt);
    if ($excerpt !== '') {
        return $excerpt;
    }

    $text = trim(ap_strip_all_tags((string) $obj->post_content));
    if ($text === '') {
        return '';
    }

    $parts = preg_split('/\s+/u', $text, $words + 1) ?: [];
    if (count($parts) > $words) {
        $parts = array_slice($parts, 0, $words);

        return implode(' ', $parts) . '…';
    }

    return implode(' ', $parts);
}

/**
 * Echo the excerpt (HTML-escaped).
 */
function ap_the_excerpt(AP_Post|int|null $post = null): void
{
    $excerpt = ap_get_the_excerpt($post);
    if ($excerpt === '') {
        return;
    }
    echo ap_esc_html($excerpt);
}

// -----------------------------------------------------------------------------
// Permalink
// -----------------------------------------------------------------------------

/**
 * Permalink for the current (or given) post.
 */
function ap_get_the_permalink(AP_Post|int|null $post = null, ?AP_DB $db = null): string
{
    $obj = ap_resolve_template_post($post);
    if (!$obj instanceof AP_Post) {
        return '';
    }
    if (function_exists('ap_get_permalink') && class_exists('AP_Rewrite', false)) {
        return ap_get_permalink($obj, $db);
    }

    return '?p=' . (int) $obj->ID;
}

/**
 * Echo the permalink (escaped URL).
 */
function ap_the_permalink(AP_Post|int|null $post = null, ?AP_DB $db = null): void
{
    $url = ap_get_the_permalink($post, $db);
    if ($url === '') {
        return;
    }
    echo ap_esc_url($url);
}

// -----------------------------------------------------------------------------
// Date / author
// -----------------------------------------------------------------------------

/**
 * Formatted post date.
 *
 * @param string $format PHP date format; empty uses site date_format option.
 */
function ap_get_the_date(string $format = '', AP_Post|int|null $post = null, ?AP_DB $db = null): string
{
    $obj = ap_resolve_template_post($post);
    if (!$obj instanceof AP_Post || $obj->post_date === '') {
        return '';
    }

    if ($format === '') {
        $format = class_exists('AP_Options', false)
            ? (string) AP_Options::get('date_format', 'Y-m-d', $db)
            : 'Y-m-d';
        if ($format === '') {
            $format = 'Y-m-d';
        }
    }

    $ts = strtotime((string) $obj->post_date);

    return $ts !== false ? date($format, $ts) : (string) $obj->post_date;
}

/**
 * Echo the post date (escaped).
 */
function ap_the_date(string $format = '', AP_Post|int|null $post = null, ?AP_DB $db = null): void
{
    $date = ap_get_the_date($format, $post, $db);
    if ($date === '') {
        return;
    }
    echo ap_esc_html($date);
}

/**
 * Display name of the post author.
 */
function ap_get_the_author(AP_Post|int|null $post = null, ?AP_DB $db = null): string
{
    $obj = ap_resolve_template_post($post);
    if (!$obj instanceof AP_Post || $obj->post_author < 1) {
        return '';
    }

    if (function_exists('ap_get_user_by')) {
        $user = ap_get_user_by('id', $obj->post_author, $db);
        if ($user instanceof AP_User) {
            $name = $user->display_name !== '' ? $user->display_name : $user->user_login;

            return (string) $name;
        }
    }

    return '';
}

/**
 * Echo the author display name (escaped).
 */
function ap_the_author(AP_Post|int|null $post = null, ?AP_DB $db = null): void
{
    $name = ap_get_the_author($post, $db);
    if ($name === '') {
        return;
    }
    echo ap_esc_html($name);
}

/**
 * Avatar HTML for the post author (empty when avatars disabled or no author).
 *
 * @param array<string, mixed> $args Passed to {@see ap_get_avatar()}.
 */
function ap_get_the_author_avatar(
    int $size = 96,
    AP_Post|int|null $post = null,
    array $args = [],
    ?AP_DB $db = null
): string {
    $obj = ap_resolve_template_post($post);
    if (!$obj instanceof AP_Post || $obj->post_author < 1) {
        return '';
    }
    if (!function_exists('ap_get_avatar')) {
        return '';
    }

    return ap_get_avatar($obj->post_author, $size, '', '', $args, $db);
}

/**
 * Echo the post author avatar.
 *
 * @param array<string, mixed> $args
 */
function ap_the_author_avatar(
    int $size = 96,
    AP_Post|int|null $post = null,
    array $args = [],
    ?AP_DB $db = null
): void {
    echo ap_get_the_author_avatar($size, $post, $args, $db);
}

// -----------------------------------------------------------------------------
// Site identity
// -----------------------------------------------------------------------------

/**
 * Site title (blogname).
 */
function ap_get_bloginfo(string $show = 'name', ?AP_DB $db = null): string
{
    return match ($show) {
        'name', 'blogname', 'title' => (string) (
            class_exists('AP_Options', false)
                ? AP_Options::get('blogname', 'AgoraPress', $db)
                : 'AgoraPress'
        ),
        'description', 'blogdescription' => (string) (
            class_exists('AP_Options', false)
                ? AP_Options::get('blogdescription', '', $db)
                : ''
        ),
        'url', 'home', 'siteurl' => function_exists('ap_home_url')
            ? ap_home_url('/', $db)
            : '/',
        'charset' => 'UTF-8',
        'language', 'html_lang' => 'en',
        'version' => defined('AP_VERSION') ? (string) AP_VERSION : '',
        'rss2_url' => class_exists('AP_Rewrite', false)
            ? AP_Rewrite::getFeedLink('rss2', $db)
            : (function_exists('ap_home_url') ? ap_home_url('/?feed=rss2', $db) : '/?feed=rss2'),
        'atom_url' => class_exists('AP_Rewrite', false)
            ? AP_Rewrite::getFeedLink('atom', $db)
            : (function_exists('ap_home_url') ? ap_home_url('/?feed=atom', $db) : '/?feed=atom'),
        default => '',
    };
}

/**
 * Echo bloginfo (escaped for HTML text, or URL when appropriate).
 */
function ap_bloginfo(string $show = 'name', ?AP_DB $db = null): void
{
    $value = ap_get_bloginfo($show, $db);
    if ($value === '') {
        return;
    }
    if (in_array($show, ['url', 'home', 'siteurl', 'rss2_url', 'atom_url'], true)) {
        echo ap_esc_url($value);

        return;
    }
    echo ap_esc_html($value);
}

// -----------------------------------------------------------------------------
// Body class / conditionals (main query)
// -----------------------------------------------------------------------------

/**
 * Body class list for the main query (not escaped).
 *
 * @return list<string>
 */
function ap_get_body_class(string|array $extra = [], ?AP_Query $query = null): array
{
    $classes = ['agorapress'];

    $q = $query;
    if (!$q instanceof AP_Query && isset($GLOBALS['ap_query']) && $GLOBALS['ap_query'] instanceof AP_Query) {
        $q = $GLOBALS['ap_query'];
    }

    if ($q instanceof AP_Query) {
        if (!empty($q->is_front_page)) {
            $classes[] = 'home';
            $classes[] = 'front-page';
        }
        if (!empty($q->is_home)) {
            $classes[] = 'blog';
        }
        if ($q->is_singular) {
            $classes[] = 'singular';
        }
        if ($q->is_single) {
            $classes[] = 'single';
            $post = $q->post;
            if ($post instanceof AP_Post) {
                $classes[] = 'single-' . ap_sanitize_html_class($post->post_type);
                $classes[] = 'postid-' . (int) $post->ID;
            }
        }
        if ($q->is_page) {
            $classes[] = 'page';
            $post = $q->post;
            if ($post instanceof AP_Post) {
                $classes[] = 'page-id-' . (int) $post->ID;
            }
        }
        if ($q->is_archive) {
            $classes[] = 'archive';
        }
        if ($q->is_search) {
            $classes[] = 'search';
        }
        if ($q->is_404) {
            $classes[] = 'error404';
        }
        if ($q->is_category) {
            $classes[] = 'category';
        }
        if ($q->is_tag) {
            $classes[] = 'tag';
        }
        if ($q->is_author) {
            $classes[] = 'author';
        }
        if ($q->is_date) {
            $classes[] = 'date';
        }
    }

    if (is_string($extra) && $extra !== '') {
        $extra = preg_split('/\s+/', $extra) ?: [];
    }
    if (is_array($extra)) {
        foreach ($extra as $c) {
            if (is_string($c) && $c !== '') {
                $classes[] = $c;
            }
        }
    }

    // De-dupe while preserving order.
    $out = [];
    $seen = [];
    foreach ($classes as $c) {
        $c = ap_sanitize_html_class((string) $c);
        if ($c === '' || isset($seen[$c])) {
            continue;
        }
        $seen[$c] = true;
        $out[] = $c;
    }

    if (function_exists('ap_apply_filters')) {
        $filtered = ap_apply_filters('ap_body_class', $out, $q);
        if (is_array($filtered)) {
            $out = [];
            foreach ($filtered as $c) {
                if (is_string($c) && $c !== '') {
                    $out[] = ap_sanitize_html_class($c);
                }
            }
        }
    }

    return array_values(array_filter($out, static fn (string $c): bool => $c !== ''));
}

/**
 * Echo space-separated body classes (attribute-safe).
 *
 * @param string|list<string> $extra
 */
function ap_body_class(string|array $extra = [], ?AP_Query $query = null): void
{
    echo ap_esc_attr(implode(' ', ap_get_body_class($extra, $query)));
}

/**
 * Sanitize a single HTML class token.
 */
function ap_sanitize_html_class(string $class): string
{
    $class = strtolower(trim($class));
    $class = preg_replace('/[^a-z0-9_\-]/', '', $class) ?? '';

    return $class;
}

// -----------------------------------------------------------------------------
// Internals
// -----------------------------------------------------------------------------

/**
 * @internal
 */
function ap_resolve_template_post(AP_Post|int|null $post): ?AP_Post
{
    if ($post instanceof AP_Post) {
        return $post;
    }
    if (is_int($post) && $post > 0 && function_exists('ap_get_post')) {
        return ap_get_post($post);
    }

    return ap_get_post_in_loop();
}
