<?php

/**
 * AgoraPress admin shell helpers — URLs, auth gate, notices, menu.
 *
 * Access requires a logged-in user with the `read` capability (all default
 * roles). Individual screens and menu items use more specific caps.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * Admin environment helpers.
 */
class AP_Admin
{
    /** @var list<array{message: string, type: string}> */
    private static array $notices = [];

    /**
     * Absolute filesystem path to ap-admin/ (trailing slash).
     */
    public static function path(): string
    {
        return defined('AP_ABSPATH')
            ? AP_ABSPATH . 'ap-admin/'
            : dirname(__DIR__) . '/';
    }

    /**
     * URL path to ap-admin relative to site root (no leading slash required by callers).
     *
     * When AP_SITEURL is defined, returns an absolute URL; otherwise a root-relative path.
     */
    public static function url(string $path = '', array $query = []): string
    {
        $path = ltrim($path, '/');
        $base = self::baseUrl();
        $url = $base . ($path !== '' ? $path : '');

        if ($query !== []) {
            $qs = http_build_query($query);
            if ($qs !== '') {
                $url .= (str_contains($url, '?') ? '&' : '?') . $qs;
            }
        }

        return $url;
    }

    /**
     * Base admin URL (…/ap-admin/).
     */
    public static function baseUrl(): string
    {
        if (defined('AP_SITEURL') && is_string(AP_SITEURL) && AP_SITEURL !== '') {
            return rtrim((string) AP_SITEURL, '/') . '/ap-admin/';
        }

        // Derive from script name when possible (…/ap-admin/edit.php → /ap-admin/).
        $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '/ap-admin/index.php');
        $dir = str_replace('\\', '/', dirname($script));
        if (!str_ends_with($dir, '/')) {
            $dir .= '/';
        }
        // If we are already under ap-admin, use that; else assume /ap-admin/.
        if (str_contains($dir, '/ap-admin')) {
            $pos = strpos($dir, '/ap-admin');
            if ($pos !== false) {
                return substr($dir, 0, $pos) . '/ap-admin/';
            }
        }

        return '/ap-admin/';
    }

    /**
     * Whether the current request has a logged-in user.
     */
    public static function isLoggedIn(?AP_DB $db = null): bool
    {
        return ap_is_user_logged_in($db);
    }

    /**
     * Require a logged-in user or redirect to the admin login screen.
     *
     * Also requires the `read` capability so users without any role cannot
     * enter the admin shell.
     *
     * @return never|void Exits via redirect when not authenticated.
     */
    public static function requireLogin(?AP_DB $db = null): void
    {
        if (!self::isLoggedIn($db)) {
            $redirect = self::currentRequestUrl();
            $login = self::url('login.php', $redirect !== '' ? ['redirect_to' => $redirect] : []);
            self::redirect($login);
        }

        // Logged in but no admin-capable role (e.g. empty caps).
        if (!self::currentUserCan('read', $db)) {
            self::denyAccess('You do not have permission to access the admin area.');
        }
    }

    /**
     * Whether the current user has a capability (meta-caps mapped when $objectId set).
     *
     * @param int|null $objectId Optional post/comment ID for meta capabilities
     *                           such as edit_post / delete_post / edit_comment.
     */
    public static function currentUserCan(
        string $capability,
        ?AP_DB $db = null,
        ?int $objectId = null
    ): bool {
        if (function_exists('ap_current_user_can')) {
            return ap_current_user_can($capability, $objectId, $db);
        }
        if (class_exists('AP_Roles', false)) {
            return AP_Roles::currentUserCan($capability, $objectId, $db);
        }

        // Roles not loaded yet — deny privileged checks, allow only bare login probes.
        return false;
    }

    /**
     * Whether a specific user has a capability (prefers actor id for unit tests).
     *
     * When the roles layer is not bootstrapped, returns true so legacy unit paths
     * that only exercise nonces continue to work. Production admin always loads roles.
     */
    public static function userCan(
        int $userId,
        string $capability,
        ?int $objectId = null,
        ?AP_DB $db = null
    ): bool {
        if (!class_exists('AP_Roles', false)) {
            return true;
        }
        if ($userId > 0 && function_exists('ap_user_can')) {
            return ap_user_can($userId, $capability, $objectId, $db);
        }
        if (function_exists('ap_current_user_can')) {
            return ap_current_user_can($capability, $objectId, $db);
        }

        return AP_Roles::userCan($userId, $capability, $objectId, $db);
    }

    /**
     * Require a capability or exit with 403.
     *
     * @param int|null $objectId Optional object for meta-capability mapping.
     *
     * @return never|void
     */
    public static function requireCapability(
        string $capability,
        ?AP_DB $db = null,
        ?int $objectId = null
    ): void {
        self::requireLogin($db);
        if (self::currentUserCan($capability, $db, $objectId)) {
            return;
        }

        self::denyAccess('You do not have permission to perform this action.');
    }

    /**
     * Primitive list/create capability for a post type UI (posts vs pages).
     *
     * Custom types fall back to edit_posts until type-specific caps land.
     */
    public static function editCapabilityForPostType(string $postType): string
    {
        return $postType === 'page' ? 'edit_pages' : 'edit_posts';
    }

    /**
     * Meta capability for editing a single post/page row.
     */
    public static function editMetaCapForPostType(string $postType): string
    {
        return $postType === 'page' ? 'edit_page' : 'edit_post';
    }

    /**
     * Meta capability for deleting a single post/page row.
     */
    public static function deleteMetaCapForPostType(string $postType): string
    {
        return $postType === 'page' ? 'delete_page' : 'delete_post';
    }

    /**
     * Primitive publish capability for a post type.
     */
    public static function publishCapabilityForPostType(string $postType): string
    {
        return $postType === 'page' ? 'publish_pages' : 'publish_posts';
    }

    /**
     * Map of admin screen basenames → required capability for screen access.
     *
     * Screens whose cap depends on query args (edit.php, post.php, …) are omitted
     * here and resolved in the entry script via {@see self::editCapabilityForPostType()}.
     *
     * @return array<string, string> basename => capability
     */
    public static function screenCapabilities(): array
    {
        return [
            'index.php' => 'read',
            'profile.php' => 'read',
            'users.php' => 'list_users',
            'user-new.php' => 'create_users',
            'user-edit.php' => 'edit_users',
            'edit-comments.php' => 'moderate_comments',
            'edit-tags.php' => 'manage_categories',
            'upload.php' => 'upload_files',
            'media.php' => 'upload_files',
            'media-new.php' => 'upload_files',
            'themes.php' => 'switch_themes',
            'theme-options.php' => 'edit_theme_options',
            'nav-menus.php' => 'edit_theme_options',
            'widgets.php' => 'edit_theme_options',
            'plugins.php' => 'activate_plugins',
            'options-general.php' => 'manage_options',
            'options-modules.php' => 'manage_options',
            'options-writing.php' => 'manage_options',
            'options-reading.php' => 'manage_options',
            'options-discussion.php' => 'manage_options',
            'options-media.php' => 'manage_options',
            'options-permalink.php' => 'manage_options',
            'options-hall-of-fame.php' => 'manage_options',
            // Dynamic (documented for tests / tooling):
            // edit.php, post.php, post-new.php, revision.php → edit_posts|edit_pages
        ];
    }

    /**
     * Emit a 403 page and stop (or throw in CLI/tests when headers already sent).
     *
     * @return never
     */
    public static function denyAccess(string $message = 'Forbidden'): never
    {
        if (!headers_sent()) {
            http_response_code(403);
            header('Content-Type: text/html; charset=utf-8');
        }

        $safe = function_exists('ap_esc_html')
            ? ap_esc_html($message)
            : htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $home = self::url('index.php');
        $homeEsc = function_exists('ap_esc_url')
            ? ap_esc_url($home)
            : htmlspecialchars($home, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>Forbidden — AgoraPress</title></head><body>'
            . '<main style="font-family:system-ui,sans-serif;max-width:32rem;margin:3rem auto;padding:1rem">'
            . '<h1>Permission denied</h1>'
            . '<p>' . $safe . '</p>'
            . '<p><a href="' . $homeEsc . '">Back to dashboard</a></p>'
            . '</main></body></html>';
        exit(0);
    }

    /**
     * Safe redirect and exit.
     *
     * @return never
     */
    public static function redirect(string $location, int $status = 302): never
    {
        // Allow only relative paths or same-host absolute URLs.
        $location = self::sanitizeRedirect($location);
        if (!headers_sent()) {
            header('Location: ' . $location, true, $status);
        }
        exit(0);
    }

    /**
     * Sanitize a redirect target (open-redirect protection).
     */
    public static function sanitizeRedirect(string $location): string
    {
        $location = trim($location);
        if ($location === '') {
            return self::url('index.php');
        }

        // Absolute URL: only allow when host matches AP_SITEURL (open-redirect safe).
        if (preg_match('#^https?://#i', $location) === 1) {
            $host = parse_url($location, PHP_URL_HOST);
            if ($host === null || $host === false || $host === '') {
                return self::url('index.php');
            }
            $siteHost = '';
            if (defined('AP_SITEURL') && is_string(AP_SITEURL) && AP_SITEURL !== '') {
                $siteHost = (string) (parse_url((string) AP_SITEURL, PHP_URL_HOST) ?: '');
            }
            // Without a configured site host, reject all absolute redirects.
            if ($siteHost === '' || strcasecmp((string) $host, $siteHost) !== 0) {
                return self::url('index.php');
            }

            return $location;
        }

        // Relative: must start with / or be an ap-admin path.
        if (str_starts_with($location, '/')) {
            return $location;
        }

        // Bare path like "edit.php?…" relative to admin.
        if (preg_match('#^[a-z0-9_\-./?&=%]+$#i', $location) === 1) {
            return self::url(ltrim($location, '/'));
        }

        return self::url('index.php');
    }

    /**
     * Current request URL path + query (for redirect_to).
     */
    public static function currentRequestUrl(): string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        if ($uri === '') {
            return '';
        }
        if (str_starts_with($uri, 'http://') || str_starts_with($uri, 'https://')) {
            $parts = parse_url($uri);
            $path = (string) ($parts['path'] ?? '/');
            $query = isset($parts['query']) ? '?' . $parts['query'] : '';

            return $path . $query;
        }

        return $uri;
    }

    /**
     * Queue an admin notice for the next render.
     *
     * @param string $type success|error|warning|info
     */
    public static function addNotice(string $message, string $type = 'success'): void
    {
        $allowed = ['success', 'error', 'warning', 'info'];
        if (!in_array($type, $allowed, true)) {
            $type = 'info';
        }
        self::$notices[] = ['message' => $message, 'type' => $type];
    }

    /**
     * @return list<array{message: string, type: string}>
     */
    public static function getNotices(): array
    {
        return self::$notices;
    }

    /**
     * Clear queued notices (tests).
     */
    public static function clearNotices(): void
    {
        self::$notices = [];
    }

    /**
     * Render queued notices as HTML.
     */
    public static function renderNotices(): string
    {
        if (self::$notices === []) {
            return '';
        }

        $html = '<div class="ap-notices" role="status">' . "\n";
        foreach (self::$notices as $notice) {
            $type = ap_esc_attr($notice['type']);
            $msg = ap_esc_html($notice['message']);
            $html .= '  <div class="ap-notice ap-notice--' . $type . '">' . $msg . '</div>' . "\n";
        }
        $html .= '</div>' . "\n";

        return $html;
    }

    /**
     * Usermeta key for the admin color mode preference (auto|light|dark).
     */
    public const COLOR_MODE_META = 'ap_admin_color_mode';

    /**
     * Allowed admin color modes.
     *
     * @return list<string>
     */
    public static function colorModes(): array
    {
        return ['auto', 'light', 'dark'];
    }

    /**
     * Human labels for admin color modes.
     *
     * @return array<string, string>
     */
    public static function colorModeLabels(): array
    {
        return [
            'auto' => 'System',
            'light' => 'Light',
            'dark' => 'Dark',
        ];
    }

    /**
     * Sanitize an admin color mode value (defaults to auto).
     */
    public static function sanitizeColorMode(string $mode): string
    {
        $mode = strtolower(trim($mode));

        return in_array($mode, self::colorModes(), true) ? $mode : 'auto';
    }

    /**
     * Resolve the admin color mode for a user (usermeta), defaulting to auto.
     *
     * When $userId is null/0, tries the current logged-in user.
     */
    public static function getColorMode(?int $userId = null, ?AP_DB $db = null): string
    {
        if ($userId === null || $userId < 1) {
            // Session helpers require AP_Session; unit tests may load only AP_Admin.
            if (
                function_exists('ap_get_current_user_id')
                && class_exists('AP_Session', false)
            ) {
                $userId = ap_get_current_user_id();
            } else {
                $userId = 0;
            }
        }
        if ($userId < 1) {
            return 'auto';
        }
        if (!function_exists('ap_get_user_meta') || !class_exists('AP_User', false)) {
            return 'auto';
        }
        $raw = ap_get_user_meta($userId, self::COLOR_MODE_META, $db);

        return self::sanitizeColorMode(is_string($raw) ? $raw : 'auto');
    }

    /**
     * Persist the admin color mode for a user.
     */
    public static function setColorMode(int $userId, string $mode, ?AP_DB $db = null): bool
    {
        if ($userId < 1 || !function_exists('ap_update_user_meta')) {
            return false;
        }

        return ap_update_user_meta($userId, self::COLOR_MODE_META, self::sanitizeColorMode($mode), $db);
    }

    /**
     * Next mode when cycling the top-bar theme toggle (auto → light → dark → auto).
     */
    public static function nextColorMode(string $mode): string
    {
        $modes = self::colorModes();
        $current = self::sanitizeColorMode($mode);
        $idx = array_search($current, $modes, true);
        if ($idx === false) {
            return 'auto';
        }

        return $modes[($idx + 1) % count($modes)];
    }

    /**
     * Site display name for the admin chrome (blogname option, fallback AgoraPress).
     */
    public static function siteName(?AP_DB $db = null): string
    {
        $name = '';
        if (function_exists('ap_get_option')) {
            $raw = ap_get_option('blogname', 'AgoraPress', $db);
            $name = is_string($raw) ? trim($raw) : '';
        }
        if ($name === '') {
            $name = 'AgoraPress';
        }

        return $name;
    }

    /**
     * Front-end home URL for “Visit Site” (root-relative fallback).
     */
    public static function homeUrl(?AP_DB $db = null): string
    {
        if (function_exists('ap_home_url')) {
            $url = ap_home_url('/', $db);
            if (is_string($url) && $url !== '') {
                return $url;
            }
        }
        if (defined('AP_SITEURL') && is_string(AP_SITEURL) && AP_SITEURL !== '') {
            return rtrim((string) AP_SITEURL, '/') . '/';
        }

        return '/';
    }

    /**
     * Human label for a sidebar menu section key.
     */
    public static function menuSectionLabel(string $section): string
    {
        return match ($section) {
            'content' => 'Content',
            'appearance' => 'Appearance',
            'plugins' => 'Plugins',
            'users' => 'Users',
            'settings' => 'Settings',
            default => '',
        };
    }

    /**
     * Admin menu items for the sidebar (filtered by current user capabilities).
     *
     * Optional keys: cap, module, section (for visual grouping in the shell).
     *
     * @return list<array{id: string, label: string, url: string, active: bool, cap: string, module?: string, section?: string}>
     */
    public static function menuItems(string $current = '', ?AP_DB $db = null): array
    {
        $items = [
            [
                'id' => 'dashboard',
                'label' => 'Dashboard',
                'url' => self::url('index.php'),
                'active' => $current === 'dashboard',
                'cap' => 'read',
                'module' => '',
                'section' => '',
            ],
            [
                'id' => 'posts',
                'label' => 'Posts',
                'url' => self::url('edit.php', ['post_type' => 'post']),
                'active' => $current === 'posts',
                'cap' => 'edit_posts',
                'module' => 'blog',
                'section' => 'content',
            ],
            [
                'id' => 'categories',
                'label' => 'Categories',
                'url' => self::url('edit-tags.php', ['taxonomy' => 'category']),
                'active' => $current === 'categories',
                'cap' => 'manage_categories',
                'module' => 'blog',
                'section' => 'content',
            ],
            [
                'id' => 'tags',
                'label' => 'Tags',
                'url' => self::url('edit-tags.php', ['taxonomy' => 'post_tag']),
                'active' => $current === 'tags',
                'cap' => 'manage_categories',
                'module' => 'blog',
                'section' => 'content',
            ],
            [
                'id' => 'pages',
                'label' => 'Pages',
                'url' => self::url('edit.php', ['post_type' => 'page']),
                'active' => $current === 'pages',
                'cap' => 'edit_pages',
                'module' => 'static_pages',
                'section' => 'content',
            ],
            [
                'id' => 'comments',
                'label' => 'Comments',
                'url' => self::url('edit-comments.php'),
                'active' => $current === 'comments',
                'cap' => 'moderate_comments',
                'module' => 'blog',
                'section' => 'content',
            ],
            [
                'id' => 'media',
                'label' => 'Media',
                'url' => self::url('upload.php'),
                'active' => $current === 'media',
                'cap' => 'upload_files',
                'module' => '',
                'section' => 'content',
            ],
            [
                'id' => 'users',
                'label' => 'Users',
                'url' => self::url('users.php'),
                'active' => $current === 'users',
                'cap' => 'list_users',
                'module' => '',
                'section' => 'users',
            ],
            [
                'id' => 'profile',
                'label' => 'Profile',
                'url' => self::url('profile.php'),
                'active' => $current === 'profile',
                'cap' => 'read',
                'module' => '',
                'section' => 'users',
            ],
            [
                'id' => 'themes',
                'label' => 'Themes',
                'url' => self::url('themes.php'),
                'active' => $current === 'themes',
                'cap' => 'switch_themes',
                'module' => '',
                'section' => 'appearance',
            ],
            [
                'id' => 'theme-options',
                'label' => 'Theme Options',
                'url' => self::url('theme-options.php'),
                'active' => $current === 'theme-options',
                'cap' => 'edit_theme_options',
                'module' => '',
                'section' => 'appearance',
            ],
            [
                'id' => 'nav-menus',
                'label' => 'Menus',
                'url' => self::url('nav-menus.php'),
                'active' => $current === 'nav-menus',
                'cap' => 'edit_theme_options',
                'module' => '',
                'section' => 'appearance',
            ],
            [
                'id' => 'widgets',
                'label' => 'Widgets',
                'url' => self::url('widgets.php'),
                'active' => $current === 'widgets',
                'cap' => 'edit_theme_options',
                'module' => '',
                'section' => 'appearance',
            ],
            [
                'id' => 'plugins',
                'label' => 'Installed Plugins',
                'url' => self::url('plugins.php'),
                'active' => $current === 'plugins',
                'cap' => 'activate_plugins',
                'module' => '',
                'section' => 'plugins',
            ],
            [
                'id' => 'options-general',
                'label' => 'General',
                'url' => self::url('options-general.php'),
                'active' => $current === 'options-general',
                'cap' => 'manage_options',
                'module' => '',
                'section' => 'settings',
            ],
            [
                'id' => 'options-modules',
                'label' => 'Modules',
                'url' => self::url('options-modules.php'),
                'active' => $current === 'options-modules',
                'cap' => 'manage_options',
                'module' => '',
                'section' => 'settings',
            ],
            [
                'id' => 'options-writing',
                'label' => 'Writing',
                'url' => self::url('options-writing.php'),
                'active' => $current === 'options-writing',
                'cap' => 'manage_options',
                'module' => 'blog',
                'section' => 'settings',
            ],
            [
                'id' => 'options-reading',
                'label' => 'Reading',
                'url' => self::url('options-reading.php'),
                'active' => $current === 'options-reading',
                'cap' => 'manage_options',
                'module' => '',
                'section' => 'settings',
            ],
            [
                'id' => 'options-discussion',
                'label' => 'Discussion',
                'url' => self::url('options-discussion.php'),
                'active' => $current === 'options-discussion',
                'cap' => 'manage_options',
                'module' => 'blog',
                'section' => 'settings',
            ],
            [
                'id' => 'options-media',
                'label' => 'Media Settings',
                'url' => self::url('options-media.php'),
                'active' => $current === 'options-media',
                'cap' => 'manage_options',
                'module' => '',
                'section' => 'settings',
            ],
            [
                'id' => 'options-permalink',
                'label' => 'Permalinks',
                'url' => self::url('options-permalink.php'),
                'active' => $current === 'options-permalink',
                'cap' => 'manage_options',
                'module' => '',
                'section' => 'settings',
            ],
            [
                'id' => 'options-hall-of-fame',
                'label' => 'Hall of Fame',
                'url' => self::url('options-hall-of-fame.php'),
                'active' => $current === 'options-hall-of-fame',
                'cap' => 'manage_options',
                'module' => '',
                'section' => 'settings',
            ],
        ];

        // Filter by capability only for an authenticated request. Unauthenticated
        // callers (structural unit tests, pre-login) receive the full menu map.
        if (!class_exists('AP_Roles', false) || !self::isLoggedIn($db)) {
            return $items;
        }

        $visible = [];
        foreach ($items as $item) {
            if (!self::currentUserCan((string) $item['cap'], $db)) {
                continue;
            }
            $module = (string) ($item['module'] ?? '');
            $moduleOff = $module !== ''
                && class_exists('AP_Options', false)
                && !AP_Options::isModuleEnabled($module, $db);
            if ($moduleOff) {
                continue;
            }
            $visible[] = $item;
        }

        return $visible;
    }

    /**
     * Resolve post_type query arg for list/edit screens (post|page only for now).
     */
    public static function resolvePostType(string $raw, string $default = 'post'): string
    {
        $type = strtolower(trim($raw));
        $type = preg_replace('/[^a-z0-9_\-]/', '', $type) ?? '';
        if ($type === '' || !AP_Post::typeExists($type)) {
            return $default;
        }
        // Only show UI for types with show_ui.
        $obj = AP_Post::getTypeObject($type);
        if ($obj === null || empty($obj['show_ui'])) {
            return $default;
        }

        return $type;
    }

    /**
     * Human label for a post type.
     */
    public static function postTypeLabel(string $type, bool $singular = false): string
    {
        $obj = AP_Post::getTypeObject($type);
        if ($obj === null) {
            return $singular ? 'Item' : 'Items';
        }
        $label = (string) ($obj['label'] ?? $type);
        if ($singular) {
            // Built-ins: Posts → Post, Pages → Page.
            if (str_ends_with($label, 's') && !str_ends_with($label, 'ss')) {
                return substr($label, 0, -1);
            }
        }

        return $label;
    }

    /**
     * Pull flash notice from query string (after redirect).
     */
    public static function consumeQueryNotice(): void
    {
        $msg = (string) ($_GET['message'] ?? '');
        $map = [
            'created' => ['Post created.', 'success'],
            'updated' => ['Post updated.', 'success'],
            'autosaved' => ['Draft autosaved.', 'success'],
            'revision_restored' => ['Revision restored.', 'success'],
            'revision_deleted' => ['Revision deleted.', 'success'],
            'trashed' => ['Moved to Trash.', 'success'],
            'untrashed' => ['Restored from Trash.', 'success'],
            'deleted' => ['Permanently deleted.', 'success'],
            'bulk_trashed' => ['Selected items moved to Trash.', 'success'],
            'bulk_untrashed' => ['Selected items restored.', 'success'],
            'bulk_deleted' => ['Selected items permanently deleted.', 'success'],
            'uploaded' => ['File uploaded.', 'success'],
            'bulk_uploaded' => ['Files uploaded.', 'success'],
            'term_created' => ['Term created.', 'success'],
            'term_updated' => ['Term updated.', 'success'],
            'term_deleted' => ['Term deleted.', 'success'],
            'bulk_term_deleted' => ['Selected terms deleted.', 'success'],
            'theme_options_saved' => ['Theme options saved.', 'success'],
            'general_saved' => ['General settings saved.', 'success'],
            'modules_saved' => ['Module settings saved.', 'success'],
            'writing_saved' => ['Writing settings saved.', 'success'],
            'reading_saved' => ['Reading settings saved.', 'success'],
            'discussion_saved' => ['Discussion settings saved.', 'success'],
            'media_saved' => ['Media settings saved.', 'success'],
            'permalink_saved' => ['Permalink settings saved.', 'success'],
            'menu_created' => ['Menu created.', 'success'],
            'menu_saved' => ['Menu saved.', 'success'],
            'menu_deleted' => ['Menu deleted.', 'success'],
            'comment_approved' => ['Comment approved.', 'success'],
            'comment_unapproved' => ['Comment unapproved.', 'success'],
            'comment_spammed' => ['Comment marked as spam.', 'success'],
            'comment_unspammed' => ['Comment marked as not spam.', 'success'],
            'comment_trashed' => ['Comment moved to Trash.', 'success'],
            'comment_untrashed' => ['Comment restored from Trash.', 'success'],
            'comment_deleted' => ['Comment permanently deleted.', 'success'],
            'bulk_comment_approved' => ['Selected comments approved.', 'success'],
            'bulk_comment_unapproved' => ['Selected comments unapproved.', 'success'],
            'bulk_comment_spammed' => ['Selected comments marked as spam.', 'success'],
            'bulk_comment_unspammed' => ['Selected comments marked as not spam.', 'success'],
            'bulk_comment_trashed' => ['Selected comments moved to Trash.', 'success'],
            'bulk_comment_untrashed' => ['Selected comments restored.', 'success'],
            'bulk_comment_deleted' => ['Selected comments permanently deleted.', 'success'],
            'user_created' => ['User created.', 'success'],
            'user_updated' => ['User updated.', 'success'],
            'user_deleted' => ['User deleted.', 'success'],
            'profile_updated' => ['Profile updated.', 'success'],
            'bulk_user_deleted' => ['Selected users deleted.', 'success'],
            'bulk_user_role' => ['Selected users’ roles updated.', 'success'],
            'draft_saved' => ['Draft saved. Open Posts to continue editing.', 'success'],
            'hall_of_fame_joined' => ['Welcome to the Hall of Fame — your domain was registered voluntarily.', 'success'],
            'hall_of_fame_left' => ['You left the Hall of Fame. Your domain registration was withdrawn.', 'success'],
            'hall_of_fame_left_local' => [
                'Local Hall of Fame membership cleared. The project API could not be reached; try Leave again later if needed.',
                'warning',
            ],
            'hall_of_fame_dismissed' => ['Hall of Fame prompt dismissed. You can join anytime under Settings → Hall of Fame.', 'success'],
            'hall_of_fame_donation_saved' => ['Donation link preference saved.', 'success'],
            'error' => ['Something went wrong. Please try again.', 'error'],
            'nonce' => ['Security check failed. Please reload and try again.', 'error'],
            'not_found' => ['That item could not be found.', 'error'],
        ];
        if (isset($map[$msg])) {
            self::addNotice($map[$msg][0], $map[$msg][1]);
        }
        $count = (int) ($_GET['count'] ?? 0);
        if ($count > 1 && str_starts_with($msg, 'bulk_')) {
            // Prefer generic bulk messages already set; optional count override later.
        }
    }
}
