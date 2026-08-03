<?php

/**
 * AgoraPress admin shell helpers — URLs, auth gate, notices, menu.
 *
 * Full roles/capabilities land in Phase 3. For now any logged-in user may
 * access ap-admin; capability checks will tighten later.
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
     * @return never|void Exits via redirect when not authenticated.
     */
    public static function requireLogin(?AP_DB $db = null): void
    {
        if (self::isLoggedIn($db)) {
            return;
        }

        $redirect = self::currentRequestUrl();
        $login = self::url('login.php', $redirect !== '' ? ['redirect_to' => $redirect] : []);
        self::redirect($login);
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
     * Admin menu items for the sidebar.
     *
     * @return list<array{id: string, label: string, url: string, active: bool}>
     */
    public static function menuItems(string $current = ''): array
    {
        $items = [
            [
                'id' => 'dashboard',
                'label' => 'Dashboard',
                'url' => self::url('index.php'),
                'active' => $current === 'dashboard',
            ],
            [
                'id' => 'posts',
                'label' => 'Posts',
                'url' => self::url('edit.php', ['post_type' => 'post']),
                'active' => $current === 'posts',
            ],
            [
                'id' => 'categories',
                'label' => 'Categories',
                'url' => self::url('edit-tags.php', ['taxonomy' => 'category']),
                'active' => $current === 'categories',
            ],
            [
                'id' => 'tags',
                'label' => 'Tags',
                'url' => self::url('edit-tags.php', ['taxonomy' => 'post_tag']),
                'active' => $current === 'tags',
            ],
            [
                'id' => 'pages',
                'label' => 'Pages',
                'url' => self::url('edit.php', ['post_type' => 'page']),
                'active' => $current === 'pages',
            ],
            [
                'id' => 'comments',
                'label' => 'Comments',
                'url' => self::url('edit-comments.php'),
                'active' => $current === 'comments',
            ],
            [
                'id' => 'media',
                'label' => 'Media',
                'url' => self::url('upload.php'),
                'active' => $current === 'media',
            ],
            [
                'id' => 'theme-options',
                'label' => 'Theme Options',
                'url' => self::url('theme-options.php'),
                'active' => $current === 'theme-options',
            ],
            [
                'id' => 'nav-menus',
                'label' => 'Menus',
                'url' => self::url('nav-menus.php'),
                'active' => $current === 'nav-menus',
            ],
            [
                'id' => 'options-reading',
                'label' => 'Reading',
                'url' => self::url('options-reading.php'),
                'active' => $current === 'options-reading',
            ],
        ];

        return $items;
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
            'reading_saved' => ['Reading settings saved.', 'success'],
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
