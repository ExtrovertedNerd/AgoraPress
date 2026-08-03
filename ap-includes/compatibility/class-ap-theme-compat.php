<?php

/**
 * Classic WordPress Theme Compatibility Layer — coordinator.
 *
 * Loads WP-style template tags and function shims so many pre-block classic
 * PHP themes can run on AgoraPress with minimal changes. Block / FSE themes
 * (theme.json, block templates) are detected and reported as out of scope.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * Compatibility mode, shim loading, block-theme detection, safe functions.php.
 */
class AP_Theme_Compat
{
    /** Option storing per-theme compat flags: { "slug": "auto"|"on"|"off", ... }. */
    public const OPTION_MODE_MAP = 'ap_theme_compat_modes';

    /** Mode: enable when the theme looks classic (default). */
    public const MODE_AUTO = 'auto';

    /** Mode: always enable shims for this theme. */
    public const MODE_ON = 'on';

    /** Mode: never enable shims for this theme. */
    public const MODE_OFF = 'off';

    /** @var bool Whether shim files have been included. */
    private static bool $shimsLoaded = false;

    /** @var bool|null Cached “compat active for this request” decision. */
    private static ?bool $active = null;

    /** @var array<string, string> WP hook name → AgoraPress hook name. */
    private static array $hookMap = [
        'after_setup_theme' => 'ap_after_setup_theme',
        'wp_enqueue_scripts' => 'ap_enqueue_scripts',
        'wp_head' => 'ap_head',
        'wp_footer' => 'ap_footer',
        'wp_print_styles' => 'ap_print_styles',
        'wp_print_scripts' => 'ap_print_scripts',
        'init' => 'ap_init',
        'widgets_init' => 'ap_widgets_init',
        'template_redirect' => 'ap_template_redirect',
        'wp' => 'ap_wp',
        'the_content' => 'ap_the_content',
        'body_class' => 'ap_body_class',
        'post_class' => 'ap_post_class',
        'excerpt_length' => 'ap_excerpt_length',
        'excerpt_more' => 'ap_excerpt_more',
        'nav_menu_css_class' => 'ap_nav_menu_css_class',
        'wp_nav_menu_args' => 'ap_nav_menu_args',
    ];

    /**
     * Absolute path to the compatibility directory (no trailing slash).
     */
    public static function dir(): string
    {
        return __DIR__;
    }

    /**
     * Whether shim files are currently loaded.
     */
    public static function shimsLoaded(): bool
    {
        return self::$shimsLoaded;
    }

    /**
     * Whether compatibility is active for the current request / active theme.
     */
    public static function isActive(?AP_DB $db = null): bool
    {
        if (self::$active !== null) {
            return self::$active;
        }

        $slug = class_exists('AP_Theme', false)
            ? AP_Theme::getStylesheet($db)
            : '';

        self::$active = self::shouldEnableForTheme($slug, $db);

        return self::$active;
    }

    /**
     * Force active/inactive for the current request (tests). Pass null to clear.
     */
    public static function setActiveOverride(?bool $active): void
    {
        self::$active = $active;
    }

    /**
     * Reset static state (unit tests).
     */
    public static function reset(): void
    {
        self::$active = null;
        // Shims stay loaded once included (cannot un-define functions).
    }

    /**
     * Per-theme compatibility mode: auto | on | off.
     */
    public static function getMode(string $slug, ?AP_DB $db = null): string
    {
        $slug = self::sanitizeSlug($slug);
        if ($slug === '') {
            return self::MODE_AUTO;
        }

        $map = self::readModeMap($db);
        $mode = isset($map[$slug]) ? strtolower(trim((string) $map[$slug])) : self::MODE_AUTO;
        if (!in_array($mode, [self::MODE_AUTO, self::MODE_ON, self::MODE_OFF], true)) {
            return self::MODE_AUTO;
        }

        return $mode;
    }

    /**
     * Persist per-theme compatibility mode.
     */
    public static function setMode(string $slug, string $mode, ?AP_DB $db = null): bool
    {
        $slug = self::sanitizeSlug($slug);
        $mode = strtolower(trim($mode));
        if ($slug === '' || !in_array($mode, [self::MODE_AUTO, self::MODE_ON, self::MODE_OFF], true)) {
            return false;
        }

        $map = self::readModeMap($db);
        $map[$slug] = $mode;

        return self::writeModeMap($map, $db);
    }

    /**
     * Whether shims should be enabled for a theme slug.
     *
     * Block / FSE themes are never auto-enabled (out of scope). Mode "on"
     * still loads shims (partial PHP support only). Mode "off" disables.
     */
    public static function shouldEnableForTheme(string $slug, ?AP_DB $db = null): bool
    {
        $slug = self::sanitizeSlug($slug);
        $mode = self::getMode($slug, $db);

        if ($mode === self::MODE_OFF) {
            return false;
        }
        if ($mode === self::MODE_ON) {
            return true;
        }

        // auto
        if ($slug === '') {
            return false;
        }

        // Default Agora theme is native — no need for WP shims.
        if ($slug === 'agora' || (class_exists('AP_Theme', false) && $slug === AP_Theme::DEFAULT_SLUG)) {
            return false;
        }

        if (self::isBlockTheme($slug)) {
            return false;
        }

        // Classic theme: style.css + (index.php or parent).
        if (class_exists('AP_Theme', false)) {
            return AP_Theme::getThemeHeaders($slug) !== null;
        }

        return false;
    }

    /**
     * Detect block / FSE themes (theme.json and/or templates/ with HTML files).
     */
    public static function isBlockTheme(string $slug): bool
    {
        $slug = self::sanitizeSlug($slug);
        if ($slug === '' || !class_exists('AP_Theme', false)) {
            return false;
        }

        $dir = AP_Theme::themesRoot() . '/' . $slug;
        if (!is_dir($dir)) {
            return false;
        }

        if (is_readable($dir . '/theme.json')) {
            return true;
        }

        $templatesDir = $dir . '/templates';
        if (is_dir($templatesDir)) {
            $entries = @scandir($templatesDir);
            if (is_array($entries)) {
                foreach ($entries as $entry) {
                    if (str_ends_with(strtolower($entry), '.html')) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Whether a theme directory looks like a classic PHP theme (style.css + Theme Name).
     */
    public static function isClassicTheme(string $slug): bool
    {
        if (self::isBlockTheme($slug)) {
            return false;
        }
        if (!class_exists('AP_Theme', false)) {
            return false;
        }

        return AP_Theme::getThemeHeaders($slug) !== null;
    }

    /**
     * Map a classic WordPress hook name to the AgoraPress equivalent (or same).
     */
    public static function mapHook(string $hook): string
    {
        $hook = trim($hook);
        if ($hook === '') {
            return $hook;
        }

        if (isset(self::$hookMap[$hook])) {
            return self::$hookMap[$hook];
        }

        // wp_* → ap_* when not already mapped.
        if (str_starts_with($hook, 'wp_')) {
            $candidate = 'ap_' . substr($hook, 3);
            return $candidate;
        }

        return $hook;
    }

    /**
     * Ensure compatibility shims are loaded (idempotent).
     *
     * When $force is false, only loads if {@see isActive()} is true.
     * When $force is true, always includes shim files.
     */
    public static function ensureLoaded(bool $force = false, ?AP_DB $db = null): bool
    {
        if (self::$shimsLoaded) {
            return true;
        }

        if (!$force && !self::isActive($db)) {
            return false;
        }

        $base = self::dir();
        require_once $base . '/functions-shim.php';
        require_once $base . '/template-tags.php';
        self::$shimsLoaded = true;

        if (function_exists('ap_do_action')) {
            /**
             * Fires after the classic WP theme compatibility shims are loaded.
             */
            ap_do_action('ap_theme_compat_loaded');
        }

        return true;
    }

    /**
     * Load a theme functions.php safely (shims first, isolated require_once).
     *
     * Does not catch fatals (PHP cannot recover from those), but ensures
     * compatibility aliases exist and the path is readable before include.
     *
     * @return bool True when the file was included (or already included).
     */
    public static function safeLoadFunctionsPhp(string $path, ?AP_DB $db = null): bool
    {
        $path = str_replace('\\', '/', $path);
        if ($path === '' || str_contains($path, '..')) {
            return false;
        }
        if (!is_file($path) || !is_readable($path)) {
            return false;
        }

        // Prefer realpath containment under themes root when available.
        $real = realpath($path);
        if ($real !== false && class_exists('AP_Theme', false)) {
            $root = realpath(AP_Theme::themesRoot());
            if ($root !== false && !str_starts_with($real, $root . DIRECTORY_SEPARATOR) && $real !== $root) {
                return false;
            }
            $path = $real;
        }

        self::ensureLoaded(true, $db);

        require_once $path;

        return true;
    }

    /**
     * Prepare for theme setup: load shims when compat is active for the theme.
     *
     * Called from {@see AP_Theme::setup()} before functions.php includes.
     */
    public static function beforeThemeSetup(?AP_DB $db = null): void
    {
        // Re-evaluate active flag each setup (theme may have been switched).
        self::$active = null;
        if (self::isActive($db)) {
            self::ensureLoaded(true, $db);
        }
    }

    /**
     * Known WP → AP hook map (for tests / conversion report).
     *
     * @return array<string, string>
     */
    public static function hookMap(): array
    {
        return self::$hookMap;
    }

    /**
     * @return array<string, string>
     */
    private static function readModeMap(?AP_DB $db): array
    {
        $raw = null;
        if (class_exists('AP_Options', false)) {
            $raw = AP_Options::get(self::OPTION_MODE_MAP, '{}', $db);
        } elseif ($db instanceof AP_DB) {
            try {
                $row = $db->getRow(
                    'SELECT option_value FROM ' . $db->table('options') . ' WHERE option_name = ? LIMIT 1',
                    [self::OPTION_MODE_MAP]
                );
                $raw = is_object($row) ? (string) ($row->option_value ?? '{}') : '{}';
            } catch (Throwable) {
                $raw = '{}';
            }
        }

        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $slug => $mode) {
            if (is_string($slug) && is_string($mode)) {
                $out[self::sanitizeSlug($slug)] = strtolower(trim($mode));
            }
        }

        return $out;
    }

    /**
     * @param array<string, string> $map
     */
    private static function writeModeMap(array $map, ?AP_DB $db): bool
    {
        $json = json_encode($map, JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return false;
        }

        if (class_exists('AP_Options', false)) {
            return AP_Options::update(self::OPTION_MODE_MAP, $json, $db);
        }
        if ($db instanceof AP_DB) {
            try {
                $existing = $db->getVar(
                    'SELECT option_id FROM ' . $db->table('options') . ' WHERE option_name = ? LIMIT 1',
                    [self::OPTION_MODE_MAP]
                );
                if ($existing) {
                    return $db->update(
                        'options',
                        ['option_value' => $json],
                        ['option_name' => self::OPTION_MODE_MAP]
                    ) !== false;
                }

                return $db->insert('options', [
                    'option_name' => self::OPTION_MODE_MAP,
                    'option_value' => $json,
                    'autoload' => 'yes',
                ]) !== false;
            } catch (Throwable) {
                return false;
            }
        }

        return false;
    }

    private static function sanitizeSlug(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9_\\-]+/', '-', $value) ?? $value;
        $value = preg_replace('/\\-+/', '-', $value) ?? $value;

        return trim($value, '-');
    }
}
