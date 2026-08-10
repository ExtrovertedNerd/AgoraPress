<?php

/**
 * AgoraPress plugin admin page registry (ACP allowlist store).
 *
 * Plugins register settings/admin screens via {@see ap_register_admin_page()},
 * {@see self::register()}, or WordPress-compatible shims ({@see add_options_page()},
 * {@see add_menu_page()}, {@see add_submenu_page()}, {@see add_plugins_page()}).
 * The admin router only invokes pages present here — never arbitrary paths from
 * query arguments.
 *
 * Registered page shape (normalized):
 * - id          string  unique slug → ?page={id}
 * - parent      string  settings | plugins | tools | '' (empty → plugins section later)
 * - title       string  document / screen title
 * - menu        string  sidebar label
 * - capability  string  required cap (default manage_options)
 * - callback    callable  render callback (string function names become
 *                         {@see AP_Admin_String_Callback} wrappers at registration)
 * - plugin      string  optional plugin basename for Settings links
 * - position    int     menu sort order (default 50)
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * In-memory store for registered ACP admin pages.
 */
class AP_Admin_Menu
{
    /** Default capability when registration omits capability. */
    public const DEFAULT_CAPABILITY = 'manage_options';

    /** Default menu position when registration omits position. */
    public const DEFAULT_POSITION = 50;

    /**
     * Allowed parent section keys (maps to AP_Admin menu sections).
     *
     * Empty string is allowed and means “default placement” (Plugins section).
     *
     * @return list<string>
     */
    public static function allowedParents(): array
    {
        return ['settings', 'plugins', 'tools', ''];
    }

    /**
     * Registered pages keyed by id.
     *
     * @var array<string, array{
     *   id: string,
     *   parent: string,
     *   title: string,
     *   menu: string,
     *   capability: string,
     *   callback: callable,
     *   plugin: string,
     *   position: int
     * }>
     */
    private static array $pages = [];

    /**
     * Register an admin page in the allowlist store.
     *
     * Returns false when required fields are missing/invalid or the id is already
     * registered (first registration wins — avoids silent overwrite of another
     * plugin’s screen).
     *
     * String function-name callbacks are normalized to {@see AP_Admin_String_Callback}
     * wrappers so the stored value is always a real callable (late-bound at render).
     *
     * @param array{
     *   id?: string,
     *   parent?: string,
     *   title?: string,
     *   menu?: string,
     *   capability?: string,
     *   callback?: callable|string,
     *   plugin?: string,
     *   position?: int|string
     * } $args
     */
    public static function register(array $args): bool
    {
        $id = self::sanitizeId((string) ($args['id'] ?? ''));
        if ($id === '') {
            return false;
        }

        if (isset(self::$pages[$id])) {
            return false;
        }

        $callback = self::normalizeCallback($args['callback'] ?? null);
        if ($callback === null) {
            return false;
        }

        $parent = self::sanitizeParent((string) ($args['parent'] ?? ''));
        $title = trim((string) ($args['title'] ?? ''));
        $menu = trim((string) ($args['menu'] ?? ''));
        if ($menu === '') {
            $menu = $title !== '' ? $title : $id;
        }
        if ($title === '') {
            $title = $menu;
        }

        $capability = trim((string) ($args['capability'] ?? self::DEFAULT_CAPABILITY));
        if ($capability === '') {
            $capability = self::DEFAULT_CAPABILITY;
        }

        $plugin = trim((string) ($args['plugin'] ?? ''));
        // Normalize Windows-style separators; basename-style plugin file only.
        if ($plugin !== '') {
            $plugin = str_replace('\\', '/', $plugin);
            $plugin = ltrim($plugin, '/');
        }

        $position = $args['position'] ?? self::DEFAULT_POSITION;
        if (is_string($position) && is_numeric($position)) {
            $position = (int) $position;
        }
        if (!is_int($position)) {
            $position = self::DEFAULT_POSITION;
        }

        self::$pages[$id] = [
            'id' => $id,
            'parent' => $parent,
            'title' => $title,
            'menu' => $menu,
            'capability' => $capability,
            'callback' => $callback,
            'plugin' => $plugin,
            'position' => $position,
        ];

        return true;
    }

    /**
     * Whether a page id is registered.
     */
    public static function exists(string $id): bool
    {
        $id = self::sanitizeId($id);

        return $id !== '' && isset(self::$pages[$id]);
    }

    /**
     * Fetch one registered page by id, or null when unknown.
     *
     * @return array{
     *   id: string,
     *   parent: string,
     *   title: string,
     *   menu: string,
     *   capability: string,
     *   callback: callable,
     *   plugin: string,
     *   position: int
     * }|null
     */
    public static function get(string $id): ?array
    {
        $id = self::sanitizeId($id);
        if ($id === '' || !isset(self::$pages[$id])) {
            return null;
        }

        return self::$pages[$id];
    }

    /**
     * All registered pages (id-keyed), insertion order.
     *
     * @return array<string, array{
     *   id: string,
     *   parent: string,
     *   title: string,
     *   menu: string,
     *   capability: string,
     *   callback: callable,
     *   plugin: string,
     *   position: int
     * }>
     */
    public static function all(): array
    {
        return self::$pages;
    }

    /**
     * Registered pages sorted by position (stable by id for ties).
     *
     * @return list<array{
     *   id: string,
     *   parent: string,
     *   title: string,
     *   menu: string,
     *   capability: string,
     *   callback: callable,
     *   plugin: string,
     *   position: int
     * }>
     */
    public static function allSorted(): array
    {
        $pages = array_values(self::$pages);
        usort(
            $pages,
            static function (array $a, array $b): int {
                $pos = $a['position'] <=> $b['position'];
                if ($pos !== 0) {
                    return $pos;
                }

                return strcmp($a['id'], $b['id']);
            }
        );

        return $pages;
    }

    /**
     * Pages registered for a given plugin basename (Settings link lookup).
     *
     * @return list<array{
     *   id: string,
     *   parent: string,
     *   title: string,
     *   menu: string,
     *   capability: string,
     *   callback: callable,
     *   plugin: string,
     *   position: int
     * }>
     */
    public static function forPlugin(string $pluginBasename): array
    {
        $pluginBasename = str_replace('\\', '/', trim($pluginBasename));
        $pluginBasename = ltrim($pluginBasename, '/');
        if ($pluginBasename === '') {
            return [];
        }

        $out = [];
        foreach (self::$pages as $page) {
            if ($page['plugin'] === $pluginBasename) {
                $out[] = $page;
            }
        }

        return $out;
    }

    /**
     * Remove a registered page (tests / deactivation helpers).
     */
    public static function remove(string $id): bool
    {
        $id = self::sanitizeId($id);
        if ($id === '' || !isset(self::$pages[$id])) {
            return false;
        }
        unset(self::$pages[$id]);

        return true;
    }

    /**
     * Clear the registry (unit tests).
     */
    public static function reset(): void
    {
        self::$pages = [];
    }

    /**
     * Sanitize a page id slug (URL-safe [a-z0-9_\-]).
     */
    public static function sanitizeId(string $id): string
    {
        $id = strtolower(trim($id));
        if ($id === '') {
            return '';
        }
        $id = preg_replace('/[^a-z0-9_\-]/', '', $id) ?? '';

        return $id;
    }

    /**
     * Normalize parent to an allowed section key (unknown → '').
     */
    public static function sanitizeParent(string $parent): string
    {
        $parent = strtolower(trim($parent));
        if (in_array($parent, self::allowedParents(), true)) {
            return $parent;
        }

        return '';
    }

    /**
     * WordPress / AgoraPress admin screen file → ACP section parent map.
     *
     * Used by {@see mapWpParent()} and {@see add_submenu_page()}. Only maps to the
     * three registry parent sections (settings | plugins | tools). Screens that
     * belong to other ACP groups (content, appearance, users, forums) intentionally
     * omit entries so {@see mapWpParent()} falls back to '' (default Plugins
     * placement for plugin-registered submenus).
     *
     * @return array<string, string> Basename keys (lowercase) → parent section.
     */
    public static function wpParentMap(): array
    {
        return [
            // Settings (WP options-* + AgoraPress settings screens)
            'options-general.php' => 'settings',
            'options.php' => 'settings',
            'settings.php' => 'settings',
            'options-writing.php' => 'settings',
            'options-reading.php' => 'settings',
            'options-discussion.php' => 'settings',
            'options-media.php' => 'settings',
            'options-permalink.php' => 'settings',
            'options-privacy.php' => 'settings',
            'options-modules.php' => 'settings',
            'options-forums.php' => 'settings',
            'options-hall-of-fame.php' => 'settings',
            // Plugins
            'plugins.php' => 'plugins',
            'plugin-install.php' => 'plugins',
            'plugin-editor.php' => 'plugins',
            // Tools (WP tools + AgoraPress tools screens)
            'tools.php' => 'tools',
            'import.php' => 'tools',
            'export.php' => 'tools',
            'site-health.php' => 'tools',
            'export-personal-data.php' => 'tools',
            'erase-personal-data.php' => 'tools',
            'update-core.php' => 'tools',
            'analytics.php' => 'tools',
            // Legacy WP aliases
            'management.php' => 'tools',
        ];
    }

    /**
     * Map a WordPress admin parent slug (file or section) to an AgoraPress section.
     *
     * Used by {@see add_submenu_page()} and related WP shims.
     *
     * - Native section keys (settings | plugins | tools | '') pass through.
     * - WP / ACP screen files map via {@see wpParentMap()} (e.g. options-general.php → settings).
     * - Path prefixes, query strings, and fragments are stripped to the basename.
     * - Unknown / custom top-level menu slugs → '' (default placement under Plugins).
     */
    public static function mapWpParent(string $parentSlug): string
    {
        $parentSlug = strtolower(trim($parentSlug));
        if ($parentSlug === '') {
            return '';
        }

        // Drop query string / fragment if a full admin URL path was passed.
        $qPos = strpos($parentSlug, '?');
        if ($qPos !== false) {
            $parentSlug = substr($parentSlug, 0, $qPos);
        }
        $hPos = strpos($parentSlug, '#');
        if ($hPos !== false) {
            $parentSlug = substr($parentSlug, 0, $hPos);
        }

        $parentSlug = str_replace('\\', '/', $parentSlug);
        $parentSlug = basename($parentSlug);
        $parentSlug = trim($parentSlug);

        if ($parentSlug === '' || in_array($parentSlug, self::allowedParents(), true)) {
            return self::sanitizeParent($parentSlug);
        }

        $map = self::wpParentMap();
        if (isset($map[$parentSlug])) {
            return $map[$parentSlug];
        }

        // Custom top-level menu slugs from add_menu_page() have no ACP section.
        // Content / appearance / users screens (themes.php, users.php, edit.php, …)
        // also fall through here — plugin submenus default under Plugins.
        return '';
    }

    /**
     * Normalize a WP-style menu callback for storage in the registry.
     *
     * - Real callables (closures, invokables, array callables) are returned as-is.
     * - Non-empty string function names / Class::method are trimmed and wrapped in
     *   {@see AP_Admin_String_Callback} so the registry always holds a real callable
     *   while still resolving late-defined functions at render time (WordPress
     *   string-callback pattern + theme-compat style wrappers).
     * - Already-wrapped {@see AP_Admin_String_Callback} instances are returned as-is.
     * - Empty / invalid values return null (caller should reject registration).
     */
    public static function normalizeCallback(mixed $callback): ?callable
    {
        if ($callback === null || $callback === false || $callback === '') {
            return null;
        }

        if ($callback instanceof AP_Admin_String_Callback) {
            return $callback;
        }

        if (is_string($callback)) {
            $callback = trim($callback);
            if ($callback === '' || !self::isValidCallback($callback)) {
                return null;
            }

            return new AP_Admin_String_Callback($callback);
        }

        if (is_callable($callback)) {
            /** @var callable $callback */
            return $callback;
        }

        return null;
    }

    /**
     * WordPress-style admin page hook name for a successful registration.
     *
     * Mirrors WP’s `get_plugin_page_hookname()` shape enough for truthy checks
     * and simple comparisons (settings_page_{slug}, plugins_page_{slug}, …).
     */
    public static function wpHookName(string $parent, string $menuSlug): string
    {
        $slug = self::sanitizeId($menuSlug);
        if ($slug === '') {
            return '';
        }

        $parent = self::sanitizeParent($parent);

        return match ($parent) {
            'settings' => 'settings_page_' . $slug,
            'plugins' => 'plugins_page_' . $slug,
            'tools' => 'tools_page_' . $slug,
            default => 'toplevel_page_' . $slug,
        };
    }

    /**
     * Register from a WordPress-style add_*_page() call.
     *
     * @param callable|string|array|null $callback
     * @param int|float|string|null      $position
     *
     * @return string|false Hook name on success, false on failure (WP-compatible).
     */
    public static function registerFromWp(
        string $parent,
        string $pageTitle,
        string $menuTitle,
        string $capability,
        string $menuSlug,
        mixed $callback = '',
        int|float|string|null $position = null
    ): string|false {
        $normalized = self::normalizeCallback($callback);
        if ($normalized === null) {
            return false;
        }

        $id = self::sanitizeId($menuSlug);
        if ($id === '') {
            return false;
        }

        $parent = self::sanitizeParent($parent);

        $args = [
            'id' => $id,
            'parent' => $parent,
            'title' => $pageTitle,
            'menu' => $menuTitle !== '' ? $menuTitle : $pageTitle,
            'capability' => $capability,
            'callback' => $normalized,
        ];

        if ($position !== null && $position !== '') {
            $args['position'] = $position;
        }

        if (!self::register($args)) {
            return false;
        }

        $hook = self::wpHookName($parent, $id);

        return $hook !== '' ? $hook : $id;
    }

    /**
     * Whether a callback value is acceptable for storage.
     *
     * Accepts real callables (including {@see AP_Admin_String_Callback} wrappers)
     * and non-empty string function names (resolved when the page is rendered so
     * late-defined functions still work).
     */
    public static function isValidCallback(mixed $callback): bool
    {
        if ($callback === null) {
            return false;
        }
        if ($callback instanceof AP_Admin_String_Callback) {
            return $callback->target() !== '';
        }
        if (is_callable($callback)) {
            return true;
        }
        if (!is_string($callback)) {
            return false;
        }
        $callback = trim($callback);
        if ($callback === '') {
            return false;
        }
        // Valid PHP function / static method string names (not full invokables).
        // Class::method is deferred to is_callable at render when the class exists.
        if (str_contains($callback, '::')) {
            return (bool) preg_match(
                '/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*::[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*$/',
                $callback
            );
        }

        return (bool) preg_match(
            '/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*$/',
            $callback
        );
    }
}

/**
 * Late-bound admin page callback from a function name or Class::method string.
 *
 * WordPress plugins often pass string callbacks to add_options_page() before the
 * named function is loadable in every bootstrap path. This invokable wrapper:
 * - Is always a real {@see callable} for registry storage / type hints
 * - Resolves the named target only when the page is rendered
 * - Lets {@see AP_Admin::resolveAdminPageCallback()} report failure when the
 *   target is still missing (safe “invalid callback” notice instead of a fatal)
 *
 * @package AgoraPress
 */
final class AP_Admin_String_Callback
{
    public function __construct(private readonly string $target)
    {
    }

    /**
     * Original function name or Class::method string.
     */
    public function target(): string
    {
        return $this->target;
    }

    /**
     * Whether the named target is callable right now.
     */
    public function isResolved(): bool
    {
        return is_callable($this->target);
    }

    /**
     * Resolve to the underlying callable, or null when not yet loadable.
     */
    public function resolve(): ?callable
    {
        if (!is_callable($this->target)) {
            return null;
        }

        /** @var callable $fn */
        $fn = $this->target;

        return $fn;
    }

    /**
     * Invoke the named target when loadable (no-op if still missing).
     *
     * Prefer {@see AP_Admin::invokeAdminPageCallback()} which treats an unresolved
     * target as a soft failure rather than a silent no-op.
     */
    public function __invoke(): void
    {
        $fn = $this->resolve();
        if ($fn === null) {
            return;
        }
        $fn();
    }
}
