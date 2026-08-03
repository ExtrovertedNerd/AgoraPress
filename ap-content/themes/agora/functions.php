<?php

/**
 * Agora default theme — setup, template tags, color schemes.
 *
 * Six pure-CSS schemes (3 light + 3 dark), selectable via the
 * `agora_color_scheme` option / Theme Options admin screen.
 *
 * @package Agora
 */

declare(strict_types=1);

/** Option name storing the active color scheme slug. */
const AGORA_COLOR_SCHEME_OPTION = 'agora_color_scheme';

/** Default scheme (light marble). */
const AGORA_DEFAULT_COLOR_SCHEME = 'marble';

// Theme menu locations (Primary + Footer).
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

    return implode(' ', $classes);
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
    // Full shortcode / oEmbed pipeline lands later; safe HTML-escape for now.
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
