<?php

/**
 * Classic WordPress function shims for theme compatibility.
 *
 * Defines bare WP names only when they do not already exist. Maps common
 * hooks (wp_enqueue_scripts → ap_enqueue_scripts, etc.) via AP_Theme_Compat.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

// -----------------------------------------------------------------------------
// Hooks
// -----------------------------------------------------------------------------

if (!function_exists('add_action')) {
    /**
     * @param callable|string|array<int|string, mixed> $callback
     */
    function add_action(string $hook, callable|string|array $callback, int $priority = 10, int $accepted_args = 1): bool
    {
        if (!function_exists('ap_add_action')) {
            return false;
        }
        $hook = class_exists('AP_Theme_Compat', false)
            ? AP_Theme_Compat::mapHook($hook)
            : $hook;

        return ap_add_action($hook, $callback, $priority, $accepted_args);
    }
}

if (!function_exists('do_action')) {
    function do_action(string $hook, mixed ...$args): void
    {
        if (!function_exists('ap_do_action')) {
            return;
        }
        $hook = class_exists('AP_Theme_Compat', false)
            ? AP_Theme_Compat::mapHook($hook)
            : $hook;
        ap_do_action($hook, ...$args);
    }
}

if (!function_exists('do_action_ref_array')) {
    /**
     * @param array<int, mixed> $args
     */
    function do_action_ref_array(string $hook, array $args = []): void
    {
        if (!function_exists('ap_do_action_ref_array')) {
            return;
        }
        $hook = class_exists('AP_Theme_Compat', false)
            ? AP_Theme_Compat::mapHook($hook)
            : $hook;
        ap_do_action_ref_array($hook, $args);
    }
}

if (!function_exists('remove_action')) {
    /**
     * @param callable|string|array<int|string, mixed> $callback
     */
    function remove_action(string $hook, callable|string|array $callback, int $priority = 10): bool
    {
        if (!function_exists('ap_remove_action')) {
            return false;
        }
        $hook = class_exists('AP_Theme_Compat', false)
            ? AP_Theme_Compat::mapHook($hook)
            : $hook;

        return ap_remove_action($hook, $callback, $priority);
    }
}

if (!function_exists('add_filter')) {
    /**
     * @param callable|string|array<int|string, mixed> $callback
     */
    function add_filter(string $hook, callable|string|array $callback, int $priority = 10, int $accepted_args = 1): bool
    {
        if (!function_exists('ap_add_filter')) {
            return false;
        }
        $hook = class_exists('AP_Theme_Compat', false)
            ? AP_Theme_Compat::mapHook($hook)
            : $hook;

        return ap_add_filter($hook, $callback, $priority, $accepted_args);
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
    {
        if (!function_exists('ap_apply_filters')) {
            return $value;
        }
        $hook = class_exists('AP_Theme_Compat', false)
            ? AP_Theme_Compat::mapHook($hook)
            : $hook;

        return ap_apply_filters($hook, $value, ...$args);
    }
}

if (!function_exists('remove_filter')) {
    /**
     * @param callable|string|array<int|string, mixed> $callback
     */
    function remove_filter(string $hook, callable|string|array $callback, int $priority = 10): bool
    {
        if (!function_exists('ap_remove_filter')) {
            return false;
        }
        $hook = class_exists('AP_Theme_Compat', false)
            ? AP_Theme_Compat::mapHook($hook)
            : $hook;

        return ap_remove_filter($hook, $callback, $priority);
    }
}

if (!function_exists('has_action')) {
    /**
     * @param callable|string|array<int|string, mixed>|false $callback
     */
    function has_action(string $hook, callable|string|array|false $callback = false): bool|int
    {
        if (!function_exists('ap_has_action')) {
            return false;
        }
        $hook = class_exists('AP_Theme_Compat', false)
            ? AP_Theme_Compat::mapHook($hook)
            : $hook;

        return ap_has_action($hook, $callback);
    }
}

if (!function_exists('has_filter')) {
    /**
     * @param callable|string|array<int|string, mixed>|false $callback
     */
    function has_filter(string $hook, callable|string|array|false $callback = false): bool|int
    {
        if (!function_exists('ap_has_filter')) {
            return false;
        }
        $hook = class_exists('AP_Theme_Compat', false)
            ? AP_Theme_Compat::mapHook($hook)
            : $hook;

        return ap_has_filter($hook, $callback);
    }
}

// -----------------------------------------------------------------------------
// Options
// -----------------------------------------------------------------------------

if (!function_exists('get_option')) {
    function get_option(string $option, mixed $default = false): mixed
    {
        if (function_exists('ap_get_option')) {
            return ap_get_option($option, $default);
        }

        return $default;
    }
}

if (!function_exists('update_option')) {
    function update_option(string $option, mixed $value): bool
    {
        if (function_exists('ap_update_option')) {
            return ap_update_option($option, $value);
        }

        return false;
    }
}

if (!function_exists('delete_option')) {
    function delete_option(string $option): bool
    {
        if (function_exists('ap_delete_option')) {
            return ap_delete_option($option);
        }

        return false;
    }
}

// -----------------------------------------------------------------------------
// Escaping / sanitization
// -----------------------------------------------------------------------------

if (!function_exists('esc_html')) {
    function esc_html(string $text): string
    {
        return function_exists('ap_esc_html')
            ? ap_esc_html($text)
            : htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr(string $text): string
    {
        return function_exists('ap_esc_attr')
            ? ap_esc_attr($text)
            : htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('esc_url')) {
    function esc_url(string $url): string
    {
        return function_exists('ap_esc_url')
            ? ap_esc_url($url)
            : htmlspecialchars(trim($url), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('esc_textarea')) {
    function esc_textarea(string $text): string
    {
        return function_exists('ap_esc_textarea')
            ? ap_esc_textarea($text)
            : esc_html($text);
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $str): string
    {
        return function_exists('ap_sanitize_text_field')
            ? ap_sanitize_text_field($str)
            : trim(strip_tags($str));
    }
}

if (!function_exists('sanitize_html_class')) {
    /**
     * @param string|list<string> $class
     */
    function sanitize_html_class(string|array $class, string $fallback = ''): string
    {
        if (is_array($class)) {
            $class = implode(' ', $class);
        }
        if (function_exists('ap_sanitize_html_class')) {
            $out = ap_sanitize_html_class($class);

            return $out !== '' ? $out : $fallback;
        }
        $out = preg_replace('/[^a-zA-Z0-9_\-]/', '', $class) ?? '';

        return $out !== '' ? $out : $fallback;
    }
}

// -----------------------------------------------------------------------------
// URLs
// -----------------------------------------------------------------------------

if (!function_exists('home_url')) {
    function home_url(string $path = ''): string
    {
        return function_exists('ap_home_url') ? ap_home_url($path) : '/' . ltrim($path, '/');
    }
}

if (!function_exists('site_url')) {
    function site_url(string $path = ''): string
    {
        return function_exists('ap_site_url') ? ap_site_url($path) : home_url($path);
    }
}

if (!function_exists('admin_url')) {
    function admin_url(string $path = ''): string
    {
        $base = function_exists('ap_site_url') ? ap_site_url('ap-admin/') : '/ap-admin/';

        return rtrim($base, '/') . '/' . ltrim($path, '/');
    }
}

if (!function_exists('content_url')) {
    function content_url(string $path = ''): string
    {
        if (defined('AP_CONTENT_URL') && is_string(AP_CONTENT_URL) && AP_CONTENT_URL !== '') {
            $base = rtrim(AP_CONTENT_URL, '/');
        } else {
            $base = rtrim(home_url('ap-content'), '/');
        }

        return $path === '' ? $base : $base . '/' . ltrim($path, '/');
    }
}

// -----------------------------------------------------------------------------
// Assets (wp_enqueue_*)
// -----------------------------------------------------------------------------

if (!function_exists('wp_register_style')) {
    /**
     * @param list<string> $deps
     * @param string|bool|null $ver
     */
    function wp_register_style(
        string $handle,
        string $src = '',
        array $deps = [],
        string|bool|null $ver = false,
        string $media = 'all'
    ): bool {
        return function_exists('ap_register_style')
            ? ap_register_style($handle, $src, $deps, $ver, $media)
            : false;
    }
}

if (!function_exists('wp_enqueue_style')) {
    /**
     * @param list<string> $deps
     * @param string|bool|null $ver
     */
    function wp_enqueue_style(
        string $handle,
        string $src = '',
        array $deps = [],
        string|bool|null $ver = false,
        string $media = 'all'
    ): bool {
        return function_exists('ap_enqueue_style')
            ? ap_enqueue_style($handle, $src, $deps, $ver, $media)
            : false;
    }
}

if (!function_exists('wp_dequeue_style')) {
    function wp_dequeue_style(string $handle): void
    {
        if (function_exists('ap_dequeue_style')) {
            ap_dequeue_style($handle);
        }
    }
}

if (!function_exists('wp_register_script')) {
    /**
     * @param list<string> $deps
     * @param string|bool|null $ver
     */
    function wp_register_script(
        string $handle,
        string $src = '',
        array $deps = [],
        string|bool|null $ver = false,
        bool $in_footer = false
    ): bool {
        return function_exists('ap_register_script')
            ? ap_register_script($handle, $src, $deps, $ver, $in_footer)
            : false;
    }
}

if (!function_exists('wp_enqueue_script')) {
    /**
     * @param list<string> $deps
     * @param string|bool|null $ver
     */
    function wp_enqueue_script(
        string $handle,
        string $src = '',
        array $deps = [],
        string|bool|null $ver = false,
        bool $in_footer = false
    ): bool {
        return function_exists('ap_enqueue_script')
            ? ap_enqueue_script($handle, $src, $deps, $ver, $in_footer)
            : false;
    }
}

if (!function_exists('wp_dequeue_script')) {
    function wp_dequeue_script(string $handle): void
    {
        if (function_exists('ap_dequeue_script')) {
            ap_dequeue_script($handle);
        }
    }
}

if (!function_exists('wp_add_inline_style')) {
    function wp_add_inline_style(string $handle, string $data): bool
    {
        return function_exists('ap_add_inline_style')
            ? ap_add_inline_style($handle, $data)
            : false;
    }
}

if (!function_exists('wp_add_inline_script')) {
    function wp_add_inline_script(string $handle, string $data, string $position = 'after'): bool
    {
        return function_exists('ap_add_inline_script')
            ? ap_add_inline_script($handle, $data, $position)
            : false;
    }
}

if (!function_exists('wp_head')) {
    function wp_head(): void
    {
        if (function_exists('ap_head')) {
            ap_head();
        }
    }
}

if (!function_exists('wp_footer')) {
    function wp_footer(): void
    {
        if (function_exists('ap_footer')) {
            ap_footer();
        }
    }
}

// -----------------------------------------------------------------------------
// Theme paths / style.css
// -----------------------------------------------------------------------------

if (!function_exists('get_stylesheet')) {
    function get_stylesheet(): string
    {
        return function_exists('ap_get_stylesheet') ? ap_get_stylesheet() : '';
    }
}

if (!function_exists('get_template')) {
    function get_template(): string
    {
        return function_exists('ap_get_template') ? ap_get_template() : '';
    }
}

if (!function_exists('get_stylesheet_directory')) {
    function get_stylesheet_directory(): string
    {
        return function_exists('ap_get_stylesheet_directory') ? ap_get_stylesheet_directory() : '';
    }
}

if (!function_exists('get_template_directory')) {
    function get_template_directory(): string
    {
        return function_exists('ap_get_template_directory') ? ap_get_template_directory() : '';
    }
}

if (!function_exists('get_stylesheet_directory_uri')) {
    function get_stylesheet_directory_uri(): string
    {
        return function_exists('ap_get_stylesheet_uri') ? ap_get_stylesheet_uri() : '';
    }
}

if (!function_exists('get_template_directory_uri')) {
    function get_template_directory_uri(): string
    {
        return function_exists('ap_get_template_uri') ? ap_get_template_uri() : '';
    }
}

if (!function_exists('get_stylesheet_uri')) {
    /**
     * URI of the active theme's style.css file (classic WP semantics).
     */
    function get_stylesheet_uri(): string
    {
        return function_exists('ap_get_style_css_uri')
            ? ap_get_style_css_uri()
            : (get_stylesheet_directory_uri() . '/style.css');
    }
}

if (!function_exists('get_theme_root')) {
    function get_theme_root(): string
    {
        return class_exists('AP_Theme', false) ? AP_Theme::themesRoot() : '';
    }
}

if (!function_exists('get_theme_root_uri')) {
    function get_theme_root_uri(): string
    {
        if (defined('AP_CONTENT_URL') && is_string(AP_CONTENT_URL) && AP_CONTENT_URL !== '') {
            return rtrim(AP_CONTENT_URL, '/') . '/themes';
        }

        return rtrim(home_url('ap-content/themes'), '/');
    }
}

if (!function_exists('is_child_theme')) {
    function is_child_theme(): bool
    {
        return function_exists('ap_is_child_theme') && ap_is_child_theme();
    }
}

// -----------------------------------------------------------------------------
// Template parts
// -----------------------------------------------------------------------------

if (!function_exists('get_header')) {
    /**
     * @param array<string, mixed> $args
     */
    function get_header(?string $name = null, array $args = []): void
    {
        if (function_exists('ap_get_header')) {
            ap_get_header($name ?? '', $args);
        }
    }
}

if (!function_exists('get_footer')) {
    /**
     * @param array<string, mixed> $args
     */
    function get_footer(?string $name = null, array $args = []): void
    {
        if (function_exists('ap_get_footer')) {
            ap_get_footer($name ?? '', $args);
        }
    }
}

if (!function_exists('get_sidebar')) {
    /**
     * @param array<string, mixed> $args
     */
    function get_sidebar(?string $name = null, array $args = []): void
    {
        if (function_exists('ap_get_sidebar')) {
            ap_get_sidebar($name ?? '', $args);
        }
    }
}

if (!function_exists('get_template_part')) {
    /**
     * Load a template part (slug.php or slug-name.php).
     *
     * @param array<string, mixed> $args
     */
    function get_template_part(string $slug, ?string $name = null, array $args = []): void
    {
        $templates = [];
        if ($name !== null && $name !== '') {
            $templates[] = $slug . '-' . $name . '.php';
        }
        $templates[] = $slug . '.php';
        if (function_exists('ap_locate_template')) {
            ap_locate_template($templates, true, false, $args);
        }
    }
}

if (!function_exists('locate_template')) {
    /**
     * @param list<string>|string $template_names
     * @param array<string, mixed> $args
     */
    function locate_template(
        array|string $template_names,
        bool $load = false,
        bool $require_once = true,
        array $args = []
    ): string {
        return function_exists('ap_locate_template')
            ? ap_locate_template($template_names, $load, $require_once, $args)
            : '';
    }
}

if (!function_exists('load_template')) {
    /**
     * @param array<string, mixed> $args
     */
    function load_template(string $_template_file, bool $require_once = true, array $args = []): void
    {
        if (class_exists('AP_Theme', false)) {
            AP_Theme::loadTemplate($_template_file, $require_once, $args);
        } elseif ($require_once) {
            require_once $_template_file;
        } else {
            require $_template_file;
        }
    }
}

// -----------------------------------------------------------------------------
// Theme support (lightweight stubs)
// -----------------------------------------------------------------------------

if (!function_exists('add_theme_support')) {
    /**
     * @param mixed ...$args
     */
    function add_theme_support(string $feature, mixed ...$args): bool
    {
        if (!isset($GLOBALS['ap_theme_support']) || !is_array($GLOBALS['ap_theme_support'])) {
            $GLOBALS['ap_theme_support'] = [];
        }
        $GLOBALS['ap_theme_support'][$feature] = $args !== [] ? $args : true;

        return true;
    }
}

if (!function_exists('get_theme_support')) {
    function get_theme_support(string $feature): mixed
    {
        if (!isset($GLOBALS['ap_theme_support']) || !is_array($GLOBALS['ap_theme_support'])) {
            return false;
        }

        return $GLOBALS['ap_theme_support'][$feature] ?? false;
    }
}

if (!function_exists('current_theme_supports')) {
    function current_theme_supports(string $feature): bool
    {
        return get_theme_support($feature) !== false;
    }
}

if (!function_exists('remove_theme_support')) {
    function remove_theme_support(string $feature): bool
    {
        if (!isset($GLOBALS['ap_theme_support']) || !is_array($GLOBALS['ap_theme_support'])) {
            return false;
        }
        if (!array_key_exists($feature, $GLOBALS['ap_theme_support'])) {
            return false;
        }
        unset($GLOBALS['ap_theme_support'][$feature]);

        return true;
    }
}

// -----------------------------------------------------------------------------
// Menus / sidebars
// -----------------------------------------------------------------------------

if (!function_exists('register_nav_menu')) {
    function register_nav_menu(string $location, string $description = ''): void
    {
        if (function_exists('ap_register_nav_menu')) {
            ap_register_nav_menu($location, $description);
        }
    }
}

if (!function_exists('register_nav_menus')) {
    /**
     * @param array<string, string> $locations
     */
    function register_nav_menus(array $locations = []): void
    {
        if (function_exists('ap_register_nav_menus')) {
            ap_register_nav_menus($locations);
        }
    }
}

if (!function_exists('has_nav_menu')) {
    function has_nav_menu(string $location): bool
    {
        return function_exists('ap_has_nav_menu') && ap_has_nav_menu($location);
    }
}

if (!function_exists('wp_nav_menu')) {
    /**
     * @param array<string, mixed> $args
     */
    function wp_nav_menu(array $args = []): string|false
    {
        if (!function_exists('ap_nav_menu')) {
            return false;
        }
        $html = ap_nav_menu($args);
        $echo = !isset($args['echo']) || (bool) $args['echo'];
        if ($echo) {
            echo $html;

            return false;
        }

        return $html;
    }
}

if (!function_exists('register_sidebar')) {
    /**
     * @param array<string, mixed> $args
     */
    function register_sidebar(array|string $args = []): string
    {
        if (is_string($args)) {
            $args = ['name' => $args, 'id' => sanitize_title($args)];
        }
        $id = (string) ($args['id'] ?? 'sidebar-1');
        if (function_exists('ap_register_sidebar')) {
            ap_register_sidebar($id, $args);
        }

        return $id;
    }
}

if (!function_exists('register_sidebars')) {
    /**
     * @param array<string, mixed> $args
     */
    function register_sidebars(int $number = 1, array $args = []): void
    {
        $number = max(1, $number);
        for ($i = 1; $i <= $number; $i++) {
            $a = $args;
            $a['id'] = (string) ($args['id'] ?? 'sidebar') . ($number > 1 ? '-' . $i : '');
            $a['name'] = (string) ($args['name'] ?? 'Sidebar') . ($number > 1 ? ' ' . $i : '');
            register_sidebar($a);
        }
    }
}

if (!function_exists('is_active_sidebar')) {
    function is_active_sidebar(string|int $index): bool
    {
        return function_exists('ap_is_active_sidebar') && ap_is_active_sidebar((string) $index);
    }
}

if (!function_exists('dynamic_sidebar')) {
    function dynamic_sidebar(string|int $index = 1): bool
    {
        if (!function_exists('ap_dynamic_sidebar')) {
            return false;
        }
        $html = ap_dynamic_sidebar((string) $index);

        return $html !== '';
    }
}

// -----------------------------------------------------------------------------
// Misc helpers used by classic themes
// -----------------------------------------------------------------------------

if (!function_exists('sanitize_title')) {
    function sanitize_title(string $title, string $fallback = ''): string
    {
        $title = strtolower(trim($title));
        $title = preg_replace('/[^a-z0-9_\-]+/', '-', $title) ?? '';
        $title = trim(preg_replace('/\-+/', '-', $title) ?? '', '-');

        return $title !== '' ? $title : $fallback;
    }
}

if (!function_exists('__')) {
    /**
     * i18n stub — returns the string unchanged until full gettext lands.
     */
    function __(string $text, string $domain = 'default'): string
    {
        return $text;
    }
}

if (!function_exists('_e')) {
    function _e(string $text, string $domain = 'default'): void
    {
        echo __($text, $domain);
    }
}

if (!function_exists('esc_html__')) {
    function esc_html__(string $text, string $domain = 'default'): string
    {
        return esc_html(__($text, $domain));
    }
}

if (!function_exists('esc_attr__')) {
    function esc_attr__(string $text, string $domain = 'default'): string
    {
        return esc_attr(__($text, $domain));
    }
}

if (!function_exists('esc_html_e')) {
    function esc_html_e(string $text, string $domain = 'default'): void
    {
        echo esc_html__($text, $domain);
    }
}

if (!function_exists('esc_attr_e')) {
    function esc_attr_e(string $text, string $domain = 'default'): void
    {
        echo esc_attr__($text, $domain);
    }
}

if (!function_exists('language_attributes')) {
    function language_attributes(string $doctype = 'html'): void
    {
        $lang = function_exists('ap_get_bloginfo') ? ap_get_bloginfo('language') : 'en';
        if ($lang === '') {
            $lang = 'en';
        }
        echo 'lang="' . esc_attr($lang) . '"';
    }
}

if (!function_exists('bloginfo')) {
    function bloginfo(string $show = ''): void
    {
        if (function_exists('ap_bloginfo')) {
            ap_bloginfo($show === '' ? 'name' : $show);

            return;
        }
        echo esc_html(get_bloginfo($show));
    }
}

if (!function_exists('get_bloginfo')) {
    function get_bloginfo(string $show = '', string $filter = 'raw'): string
    {
        if ($show === '' || $show === 'name') {
            $show = 'name';
        }
        if (function_exists('ap_get_bloginfo')) {
            return ap_get_bloginfo($show);
        }

        return '';
    }
}

if (!function_exists('wp_title')) {
    /**
     * Simple document title helper (classic themes).
     */
    function wp_title(string $sep = '&raquo;', bool $display = true, string $seplocation = ''): string|null
    {
        $parts = [];
        if (function_exists('ap_get_bloginfo')) {
            $parts[] = ap_get_bloginfo('name');
        }
        if (function_exists('ap_get_the_title')) {
            $title = ap_get_the_title();
            if ($title !== '') {
                $parts[] = $title;
            }
        }
        $text = $seplocation === 'right'
            ? implode(" $sep ", $parts)
            : implode(" $sep ", array_reverse($parts));
        if ($display) {
            echo esc_html($text);

            return null;
        }

        return $text;
    }
}

if (!function_exists('is_user_logged_in')) {
    function is_user_logged_in(): bool
    {
        return function_exists('ap_is_user_logged_in') && ap_is_user_logged_in();
    }
}

if (!function_exists('wp_get_current_user')) {
    function wp_get_current_user(): object
    {
        if (function_exists('ap_get_current_user')) {
            $user = ap_get_current_user();
            if ($user instanceof AP_User) {
                return $user;
            }
        }

        return (object) [
            'ID' => 0,
            'user_login' => '',
            'display_name' => '',
        ];
    }
}

if (!function_exists('get_current_user_id')) {
    function get_current_user_id(): int
    {
        return function_exists('ap_get_current_user_id') ? ap_get_current_user_id() : 0;
    }
}

if (!function_exists('wp_create_nonce')) {
    function wp_create_nonce(string $action = '-1'): string
    {
        return function_exists('ap_create_nonce') ? ap_create_nonce($action) : '';
    }
}

if (!function_exists('wp_verify_nonce')) {
    /**
     * @return int|false
     */
    function wp_verify_nonce(string $nonce, string $action = '-1'): int|false
    {
        if (!function_exists('ap_check_nonce')) {
            return false;
        }

        return ap_check_nonce($nonce, $action) ? 1 : false;
    }
}

if (!function_exists('checked')) {
    function checked(mixed $checked, mixed $current = true, bool $display = true): string
    {
        $result = ((string) $checked === (string) $current) ? ' checked="checked"' : '';
        if ($display) {
            echo $result;
        }

        return $result;
    }
}

if (!function_exists('selected')) {
    function selected(mixed $selected, mixed $current = true, bool $display = true): string
    {
        $result = ((string) $selected === (string) $current) ? ' selected="selected"' : '';
        if ($display) {
            echo $result;
        }

        return $result;
    }
}

if (!function_exists('disabled')) {
    function disabled(mixed $disabled, mixed $current = true, bool $display = true): string
    {
        $result = ((string) $disabled === (string) $current) ? ' disabled="disabled"' : '';
        if ($display) {
            echo $result;
        }

        return $result;
    }
}

if (!function_exists('absint')) {
    function absint(mixed $value): int
    {
        return abs((int) $value);
    }
}

if (!function_exists('trailingslashit')) {
    function trailingslashit(string $value): string
    {
        return rtrim($value, '/\\') . '/';
    }
}

if (!function_exists('untrailingslashit')) {
    function untrailingslashit(string $value): string
    {
        return rtrim($value, '/\\');
    }
}
