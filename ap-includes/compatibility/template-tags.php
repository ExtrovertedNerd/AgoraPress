<?php

/**
 * Classic WordPress template tag shims for theme compatibility.
 *
 * Maps common loop/conditionals/title/content tags onto AgoraPress
 * `ap_*` helpers and AP_Query conditionals.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

// -----------------------------------------------------------------------------
// Loop
// -----------------------------------------------------------------------------

if (!function_exists('have_posts')) {
    function have_posts(): bool
    {
        return function_exists('ap_have_posts') && ap_have_posts();
    }
}

if (!function_exists('the_post')) {
    function the_post(): void
    {
        if (function_exists('ap_the_post')) {
            ap_the_post();
        }
    }
}

if (!function_exists('rewind_posts')) {
    function rewind_posts(): void
    {
        if (function_exists('ap_rewind_posts')) {
            ap_rewind_posts();
        }
    }
}

if (!function_exists('get_post')) {
    /**
     * @param int|AP_Post|null $post
     */
    function get_post(int|AP_Post|null $post = null, string $output = 'OBJECT', string $filter = 'raw'): AP_Post|null
    {
        if ($post instanceof AP_Post) {
            return $post;
        }
        if (is_int($post) && $post > 0 && function_exists('ap_get_post')) {
            return ap_get_post($post);
        }
        if (function_exists('ap_get_post_in_loop')) {
            return ap_get_post_in_loop();
        }

        return null;
    }
}

// -----------------------------------------------------------------------------
// Title / content / excerpt
// -----------------------------------------------------------------------------

if (!function_exists('get_the_ID')) {
    function get_the_ID(): int
    {
        return function_exists('ap_get_the_ID') ? ap_get_the_ID() : 0;
    }
}

if (!function_exists('the_ID')) {
    function the_ID(): void
    {
        if (function_exists('ap_the_ID')) {
            ap_the_ID();
        } else {
            echo (string) get_the_ID();
        }
    }
}

if (!function_exists('get_the_title')) {
    /**
     * @param int|AP_Post|null $post
     */
    function get_the_title(int|AP_Post|null $post = null): string
    {
        return function_exists('ap_get_the_title')
            ? ap_get_the_title($post)
            : '';
    }
}

if (!function_exists('the_title')) {
    /**
     * @param int|AP_Post|null $post
     */
    function the_title(string $before = '', string $after = '', bool $display = true, int|AP_Post|null $post = null): string|null
    {
        $title = get_the_title($post);
        if ($title === '') {
            return $display ? null : '';
        }
        $out = $before . esc_html($title) . $after;
        if ($display) {
            echo $out;

            return null;
        }

        return $out;
    }
}

if (!function_exists('get_the_content')) {
    /**
     * @param int|AP_Post|null $post
     */
    function get_the_content(
        ?string $more_link_text = null,
        bool $strip_teaser = false,
        int|AP_Post|null $post = null
    ): string {
        return function_exists('ap_get_the_content')
            ? ap_get_the_content($post)
            : '';
    }
}

if (!function_exists('the_content')) {
    function the_content(?string $more_link_text = null, bool $strip_teaser = false): void
    {
        if (function_exists('ap_the_content')) {
            ap_the_content();
        } else {
            echo get_the_content($more_link_text, $strip_teaser);
        }
    }
}

if (!function_exists('get_the_excerpt')) {
    /**
     * @param int|AP_Post|null $post
     */
    function get_the_excerpt(int|AP_Post|null $post = null): string
    {
        return function_exists('ap_get_the_excerpt')
            ? ap_get_the_excerpt($post)
            : '';
    }
}

if (!function_exists('the_excerpt')) {
    function the_excerpt(): void
    {
        if (function_exists('ap_the_excerpt')) {
            ap_the_excerpt();
        } else {
            echo esc_html(get_the_excerpt());
        }
    }
}

if (!function_exists('get_permalink')) {
    /**
     * @param int|AP_Post|null $post
     */
    function get_permalink(int|AP_Post|null $post = null): string|false
    {
        if (function_exists('ap_get_the_permalink')) {
            $url = ap_get_the_permalink($post);

            return $url !== '' ? $url : false;
        }

        return false;
    }
}

if (!function_exists('the_permalink')) {
    function the_permalink(): void
    {
        if (function_exists('ap_the_permalink')) {
            ap_the_permalink();
        } else {
            $url = get_permalink();
            if (is_string($url) && $url !== '') {
                echo esc_url($url);
            }
        }
    }
}

if (!function_exists('get_the_date')) {
    /**
     * @param int|AP_Post|null $post
     */
    function get_the_date(string $format = '', int|AP_Post|null $post = null): string|int|false
    {
        return function_exists('ap_get_the_date')
            ? ap_get_the_date($format, $post)
            : '';
    }
}

if (!function_exists('the_date')) {
    function the_date(string $format = '', string $before = '', string $after = '', bool $display = true): string|null
    {
        $date = (string) get_the_date($format);
        if ($date === '') {
            return $display ? null : '';
        }
        $out = $before . esc_html($date) . $after;
        if ($display) {
            echo $out;

            return null;
        }

        return $out;
    }
}

if (!function_exists('get_the_author')) {
    function get_the_author(): string
    {
        return function_exists('ap_get_the_author') ? ap_get_the_author() : '';
    }
}

if (!function_exists('the_author')) {
    function the_author(): void
    {
        if (function_exists('ap_the_author')) {
            ap_the_author();
        } else {
            echo esc_html(get_the_author());
        }
    }
}

// -----------------------------------------------------------------------------
// Body / post class
// -----------------------------------------------------------------------------

if (!function_exists('get_body_class')) {
    /**
     * @param string|list<string> $class
     *
     * @return list<string>
     */
    function get_body_class(string|array $class = ''): array
    {
        return function_exists('ap_get_body_class')
            ? ap_get_body_class($class)
            : [];
    }
}

if (!function_exists('body_class')) {
    /**
     * @param string|list<string> $class
     */
    function body_class(string|array $class = ''): void
    {
        if (function_exists('ap_body_class')) {
            ap_body_class($class);
        } else {
            echo esc_attr(implode(' ', get_body_class($class)));
        }
    }
}

if (!function_exists('get_post_class')) {
    /**
     * @param string|list<string> $class
     * @param int|AP_Post|null    $post
     *
     * @return list<string>
     */
    function get_post_class(string|array $class = '', int|AP_Post|null $post = null): array
    {
        $classes = ['post'];
        $obj = null;
        if (function_exists('ap_resolve_template_post')) {
            $obj = ap_resolve_template_post($post);
        } elseif ($post instanceof AP_Post) {
            $obj = $post;
        }

        if ($obj instanceof AP_Post) {
            $classes[] = 'post-' . (int) $obj->ID;
            $type = (string) $obj->post_type;
            if ($type !== '') {
                $classes[] = 'type-' . sanitize_html_class($type);
            }
            $status = (string) $obj->post_status;
            if ($status !== '') {
                $classes[] = 'status-' . sanitize_html_class($status);
            }
            $classes[] = 'hentry';
        }

        if (is_string($class) && $class !== '') {
            $class = preg_split('/\s+/', $class) ?: [];
        }
        if (is_array($class)) {
            foreach ($class as $c) {
                if (is_string($c) && $c !== '') {
                    $classes[] = sanitize_html_class($c);
                }
            }
        }

        $out = [];
        $seen = [];
        foreach ($classes as $c) {
            $c = sanitize_html_class((string) $c);
            if ($c === '' || isset($seen[$c])) {
                continue;
            }
            $seen[$c] = true;
            $out[] = $c;
        }

        if (function_exists('ap_apply_filters')) {
            $filtered = ap_apply_filters('ap_post_class', $out, $class, $obj);
            if (is_array($filtered)) {
                $out = [];
                foreach ($filtered as $c) {
                    if (is_string($c) && $c !== '') {
                        $out[] = sanitize_html_class($c);
                    }
                }
            }
        }

        return array_values(array_filter($out, static fn (string $c): bool => $c !== ''));
    }
}

if (!function_exists('post_class')) {
    /**
     * @param string|list<string> $class
     * @param int|AP_Post|null    $post
     */
    function post_class(string|array $class = '', int|AP_Post|null $post = null): void
    {
        echo 'class="' . esc_attr(implode(' ', get_post_class($class, $post))) . '"';
    }
}

// -----------------------------------------------------------------------------
// Conditionals (main query)
// -----------------------------------------------------------------------------

/**
 * Resolve the main query for conditionals.
 */
function ap_compat_main_query(): ?AP_Query
{
    if (isset($GLOBALS['ap_query']) && $GLOBALS['ap_query'] instanceof AP_Query) {
        return $GLOBALS['ap_query'];
    }
    if (function_exists('ap_query')) {
        try {
            $q = ap_query();

            return $q instanceof AP_Query ? $q : null;
        } catch (Throwable) {
            return null;
        }
    }

    return null;
}

if (!function_exists('is_home')) {
    function is_home(): bool
    {
        $q = ap_compat_main_query();

        return $q instanceof AP_Query && !empty($q->is_home);
    }
}

if (!function_exists('is_front_page')) {
    function is_front_page(): bool
    {
        $q = ap_compat_main_query();

        return $q instanceof AP_Query && !empty($q->is_front_page);
    }
}

if (!function_exists('is_single')) {
    function is_single(int|string|array|false $post = false): bool
    {
        $q = ap_compat_main_query();
        if (!$q instanceof AP_Query || !$q->is_single) {
            return false;
        }
        if ($post === false || $post === '' || $post === []) {
            return true;
        }
        // Optional slug/ID match against current post.
        $obj = $q->post;
        if (!$obj instanceof AP_Post) {
            return true;
        }
        $needles = is_array($post) ? $post : [$post];
        foreach ($needles as $n) {
            if (is_int($n) && (int) $obj->ID === $n) {
                return true;
            }
            if (is_string($n) && ($obj->post_name === $n || (string) $obj->ID === $n)) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('is_page')) {
    function is_page(int|string|array|false $page = false): bool
    {
        $q = ap_compat_main_query();
        if (!$q instanceof AP_Query || !$q->is_page) {
            return false;
        }
        if ($page === false || $page === '' || $page === []) {
            return true;
        }
        $obj = $q->post;
        if (!$obj instanceof AP_Post) {
            return true;
        }
        $needles = is_array($page) ? $page : [$page];
        foreach ($needles as $n) {
            if (is_int($n) && (int) $obj->ID === $n) {
                return true;
            }
            if (is_string($n) && ($obj->post_name === $n || (string) $obj->ID === $n)) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('is_singular')) {
    /**
     * @param string|list<string>|false $post_types
     */
    function is_singular(string|array|false $post_types = false): bool
    {
        $q = ap_compat_main_query();
        if (!$q instanceof AP_Query || !$q->is_singular) {
            return false;
        }
        if ($post_types === false || $post_types === '' || $post_types === []) {
            return true;
        }
        $obj = $q->post;
        if (!$obj instanceof AP_Post) {
            return true;
        }
        $types = is_array($post_types) ? $post_types : [$post_types];

        return in_array((string) $obj->post_type, $types, true);
    }
}

if (!function_exists('is_archive')) {
    function is_archive(): bool
    {
        $q = ap_compat_main_query();

        return $q instanceof AP_Query && !empty($q->is_archive);
    }
}

if (!function_exists('is_search')) {
    function is_search(): bool
    {
        $q = ap_compat_main_query();

        return $q instanceof AP_Query && !empty($q->is_search);
    }
}

if (!function_exists('is_404')) {
    function is_404(): bool
    {
        $q = ap_compat_main_query();

        return $q instanceof AP_Query && !empty($q->is_404);
    }
}

if (!function_exists('is_category')) {
    function is_category(int|string|array|false $category = false): bool
    {
        $q = ap_compat_main_query();

        return $q instanceof AP_Query && !empty($q->is_category);
    }
}

if (!function_exists('is_tag')) {
    function is_tag(int|string|array|false $tag = false): bool
    {
        $q = ap_compat_main_query();

        return $q instanceof AP_Query && !empty($q->is_tag);
    }
}

if (!function_exists('is_tax')) {
    function is_tax(string|array $taxonomy = '', int|string|array $term = ''): bool
    {
        $q = ap_compat_main_query();

        return $q instanceof AP_Query && !empty($q->is_tax);
    }
}

if (!function_exists('is_author')) {
    function is_author(int|string|array|false $author = false): bool
    {
        $q = ap_compat_main_query();

        return $q instanceof AP_Query && !empty($q->is_author);
    }
}

if (!function_exists('is_date')) {
    function is_date(): bool
    {
        $q = ap_compat_main_query();

        return $q instanceof AP_Query && !empty($q->is_date);
    }
}

if (!function_exists('is_attachment')) {
    function is_attachment(): bool
    {
        $q = ap_compat_main_query();
        if (!$q instanceof AP_Query || !$q->is_singular) {
            return false;
        }
        $obj = $q->post;

        return $obj instanceof AP_Post && (string) $obj->post_type === 'attachment';
    }
}

if (!function_exists('get_search_query')) {
    function get_search_query(bool $escaped = true): string
    {
        $q = ap_compat_main_query();
        $s = '';
        if ($q instanceof AP_Query) {
            $s = (string) $q->get('s', '');
        }
        if ($escaped) {
            return esc_attr($s);
        }

        return $s;
    }
}

if (!function_exists('the_search_query')) {
    function the_search_query(): void
    {
        echo get_search_query(true);
    }
}
