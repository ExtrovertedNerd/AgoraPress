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
    /** @var list<array{message: string, type: string, escape: bool}> */
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
            'update-core.php' => 'update_core',
            'import.php' => 'import',
            'site-health.php' => 'view_site_health',
            'analytics.php' => 'manage_options',
            'export-personal-data.php' => 'export_others_personal_data',
            'erase-personal-data.php' => 'erase_others_personal_data',
            'options-general.php' => 'manage_options',
            'options-modules.php' => 'manage_options',
            'options-writing.php' => 'manage_options',
            'options-reading.php' => 'manage_options',
            'options-discussion.php' => 'manage_options',
            'options-media.php' => 'manage_options',
            'options-permalink.php' => 'manage_options',
            'options-privacy.php' => 'manage_privacy_options',
            'options-hall-of-fame.php' => 'manage_options',
            'options-forums.php' => 'manage_options',
            // Forums (module-gated in screens)
            'forums.php' => 'manage_forums',
            'forum-edit.php' => 'manage_forums',
            'forum-topics.php' => 'moderate_forums',
            'forum-moderation.php' => 'moderate_forums',
            'forum-groups.php' => 'manage_forums',
            // Dynamic (documented for tests / tooling):
            // edit.php, post.php, post-new.php, revision.php → edit_posts|edit_pages
            // admin.php → capability from AP_Admin_Menu registry for ?page={id}
            //   (use capabilityForScreen() / registeredScreenCapabilities())
        ];
    }

    /**
     * Capability map for registered ACP pages (registry id → capability).
     *
     * Keys match menu item ids / {@see registeredPageScreenContext()} screen ids
     * so screenCapabilities tooling and sidebar active state stay aligned.
     *
     * @return array<string, string> page id => capability
     */
    public static function registeredScreenCapabilities(): array
    {
        if (!class_exists('AP_Admin_Menu', false)) {
            return [];
        }

        $map = [];
        foreach (AP_Admin_Menu::all() as $id => $page) {
            $map[(string) $id] = self::capabilityForRegisteredPage($page);
        }

        return $map;
    }

    /**
     * Resolve the required capability for an admin screen.
     *
     * Static core screens use {@see screenCapabilities()}. Registered plugin
     * pages (admin.php?page={id}) resolve via the AP_Admin_Menu allowlist.
     * Unknown screens return null (callers must not treat that as allow).
     *
     * @param string      $screen   Basename (e.g. options-general.php) or admin.php
     * @param string|null $pageSlug Optional ?page= id; when null and $screen is
     *                              admin.php, reads {@see requestPageSlug()}.
     */
    public static function capabilityForScreen(string $screen, ?string $pageSlug = null): ?string
    {
        $screen = str_replace('\\', '/', trim($screen));
        $base = strtolower(basename($screen));
        if ($base === '') {
            return null;
        }

        // Registered router: admin.php?page={id} (allowlist only).
        if ($base === 'admin.php' || $base === 'admin') {
            $slug = $pageSlug !== null ? self::sanitizePageSlug($pageSlug) : self::requestPageSlug();
            if ($slug === '') {
                return null;
            }
            $page = self::getRegisteredAdminPage($slug);
            if ($page === null) {
                return null;
            }

            return self::capabilityForRegisteredPage($page);
        }

        $map = self::screenCapabilities();
        if (isset($map[$base])) {
            return $map[$base];
        }

        // Menu / screen id for a registered page (same key as sidebar active id).
        $asId = self::sanitizePageSlug($base);
        if ($asId !== '' && class_exists('AP_Admin_Menu', false) && AP_Admin_Menu::exists($asId)) {
            $page = self::getRegisteredAdminPage($asId);
            if ($page !== null) {
                return self::capabilityForRegisteredPage($page);
            }
        }

        return null;
    }

    /**
     * URL for a registered ACP admin page (admin.php?page={id}).
     *
     * Extra query args are merged; `page` always wins and is sanitized.
     *
     * @param array<string, scalar|null> $query
     */
    public static function pageUrl(string $pageId, array $query = []): string
    {
        $id = self::sanitizePageSlug($pageId);
        $query['page'] = $id;

        return self::url('admin.php', $query);
    }

    /**
     * Sanitize a ?page= slug (URL-safe [a-z0-9_\-]).
     *
     * Prefer {@see AP_Admin_Menu::sanitizeId()} when the registry class is loaded.
     */
    public static function sanitizePageSlug(string $raw): string
    {
        if (class_exists('AP_Admin_Menu', false)) {
            return AP_Admin_Menu::sanitizeId($raw);
        }

        $id = strtolower(trim($raw));
        if ($id === '') {
            return '';
        }

        return preg_replace('/[^a-z0-9_\-]/', '', $id) ?? '';
    }

    /**
     * Read and sanitize the current request’s ?page= slug (or a raw override).
     */
    public static function requestPageSlug(?string $raw = null): string
    {
        if ($raw === null) {
            $raw = (string) ($_GET['page'] ?? '');
        }

        return self::sanitizePageSlug($raw);
    }

    /**
     * Look up a registered admin page by id (registry allowlist only).
     *
     * @return array{
     *   id: string,
     *   parent: string,
     *   title: string,
     *   menu: string,
     *   capability: string,
     *   callback: callable|string,
     *   plugin: string,
     *   position: int
     * }|null
     */
    public static function getRegisteredAdminPage(string $pageId): ?array
    {
        if (!class_exists('AP_Admin_Menu', false)) {
            return null;
        }

        return AP_Admin_Menu::get($pageId);
    }

    /**
     * Resolve the registered admin page for the current (or given) ?page= slug.
     *
     * Returns null when the slug is empty, fails sanitization, or is not in the
     * AP_Admin_Menu allowlist. Callers (admin.php) must treat null as a safe
     * 404 — never as a filesystem path to include.
     *
     * @return array{
     *   id: string,
     *   parent: string,
     *   title: string,
     *   menu: string,
     *   capability: string,
     *   callback: callable|string,
     *   plugin: string,
     *   position: int
     * }|null
     */
    public static function resolveRequestedAdminPage(?string $raw = null): ?array
    {
        $pageId = self::requestPageSlug($raw);
        if ($pageId === '') {
            return null;
        }

        return self::getRegisteredAdminPage($pageId);
    }

    /**
     * Default safe message for unknown admin.php?page= values.
     *
     * Intentionally static — never interpolates the raw query string (avoids
     * reflecting path-like or XSS payloads into the 404 body).
     */
    public static function unknownAdminPageMessage(): string
    {
        return 'The requested admin page was not found.';
    }

    /**
     * Resolve a stored admin-page callback to a real callable, or null if unusable.
     *
     * Accepts:
     * - Real callables (closures, invokables, array callables)
     * - {@see AP_Admin_String_Callback} wrappers (string function names normalized
     *   at registration) once the named target is loadable
     * - Legacy bare string function names / Class::method once loadable
     */
    public static function resolveAdminPageCallback(mixed $callback): ?callable
    {
        if ($callback instanceof AP_Admin_String_Callback) {
            // Only succeed when the named function/method exists (soft-fail otherwise).
            return $callback->isResolved() ? $callback : null;
        }

        if (is_string($callback)) {
            $callback = trim($callback);
            if ($callback === '') {
                return null;
            }
            // Legacy bare strings: accept only when already callable.
            if (!is_callable($callback)) {
                return null;
            }

            /** @var callable $callback */
            return $callback;
        }

        if (!is_callable($callback)) {
            return null;
        }

        /** @var callable $callback */
        return $callback;
    }

    /**
     * Invoke a registered admin page callback.
     *
     * Returns false when the callback cannot be resolved (does not include paths).
     * String function-name wrappers fail soft when the target is not yet loadable.
     */
    public static function invokeAdminPageCallback(mixed $callback): bool
    {
        $fn = self::resolveAdminPageCallback($callback);
        if ($fn === null) {
            return false;
        }
        $fn();

        return true;
    }

    /**
     * Capability required to view a registered admin page (from the allowlist record).
     *
     * Falls back to {@see AP_Admin_Menu::DEFAULT_CAPABILITY} when the page omits
     * capability (should not happen for normalized registry rows).
     */
    public static function capabilityForRegisteredPage(array $page): string
    {
        $cap = trim((string) ($page['capability'] ?? ''));
        if ($cap !== '') {
            return $cap;
        }
        if (class_exists('AP_Admin_Menu', false)) {
            return AP_Admin_Menu::DEFAULT_CAPABILITY;
        }

        return 'manage_options';
    }

    /**
     * Build admin chrome variables for a registered page (title, menu screen id, body class).
     *
     * Used by admin.php before including admin-header.php so the sidebar can mark
     * the item active (Phase 3 menu merge uses the registry id as screen id).
     *
     * @param array{
     *   id?: string,
     *   title?: string,
     *   menu?: string,
     *   capability?: string,
     *   callback?: mixed,
     *   parent?: string,
     *   plugin?: string,
     *   position?: int
     * } $page
     *
     * @return array{title: string, screen: string, body_class: string}
     */
    public static function registeredPageScreenContext(array $page): array
    {
        $id = self::sanitizePageSlug((string) ($page['id'] ?? ''));
        $title = trim((string) ($page['title'] ?? ''));
        if ($title === '') {
            $title = trim((string) ($page['menu'] ?? ''));
        }
        if ($title === '') {
            $title = $id !== '' ? $id : 'Admin';
        }

        $safeId = $id !== '' ? preg_replace('/[^a-z0-9_\-]/', '', $id) ?? '' : '';
        $bodyClass = 'ap-admin-registered-page';
        if ($safeId !== '') {
            $bodyClass .= ' ap-admin-page--' . $safeId;
        }

        return [
            'title' => $title,
            'screen' => $id,
            'body_class' => $bodyClass,
        ];
    }

    /**
     * Whether a sidebar menu item should be marked active for the current screen.
     *
     * Screen id is the menu item id (e.g. posts, options-general) or a registered
     * admin page id from {@see registeredPageScreenContext()} / admin.php.
     */
    public static function isMenuItemActive(string $itemId, string $currentScreen): bool
    {
        $itemId = trim($itemId);
        $currentScreen = trim($currentScreen);
        if ($itemId === '' || $currentScreen === '') {
            return false;
        }

        return $itemId === $currentScreen;
    }

    /**
     * Set the `active` flag on each menu item from the current screen id.
     *
     * Used by {@see menuItems()} so core and registry-merged items share one
     * active-state path. Matches item id to $currentScreen exactly.
     *
     * @param list<array{id: string, label?: string, url?: string, active?: bool, cap?: string, module?: string, section?: string}> $items
     *
     * @return list<array{id: string, label?: string, url?: string, active: bool, cap?: string, module?: string, section?: string}>
     */
    public static function applyMenuActiveState(array $items, string $currentScreen): array
    {
        $out = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $id = (string) ($item['id'] ?? '');
            $item['active'] = self::isMenuItemActive($id, $currentScreen);
            $out[] = $item;
        }

        return $out;
    }

    /**
     * Map a registry `parent` key to a sidebar menu section key.
     *
     * - settings → settings
     * - plugins  → plugins
     * - tools    → tools
     * - '' / unknown → plugins (default placement under Plugins)
     */
    public static function menuSectionForRegisteredParent(string $parent): string
    {
        if (class_exists('AP_Admin_Menu', false)) {
            $parent = AP_Admin_Menu::sanitizeParent($parent);
        } else {
            $parent = strtolower(trim($parent));
        }

        return match ($parent) {
            'settings' => 'settings',
            'tools' => 'tools',
            'plugins' => 'plugins',
            default => 'plugins',
        };
    }

    /**
     * Whether a registered page may appear in the ACP sidebar.
     *
     * Pages without a `plugin` key are always eligible (capability filtering is
     * applied later in {@see menuItems()}). When `plugin` is set, the linked
     * plugin must be active — inactive plugins hide their menu entries.
     *
     * If the plugin subsystem is unavailable, linked pages stay visible so
     * structural tests and early boot do not drop registry entries.
     *
     * @param array{plugin?: string, ...} $page
     */
    public static function isRegisteredPagePluginActive(array $page, ?AP_DB $db = null): bool
    {
        $plugin = trim((string) ($page['plugin'] ?? ''));
        if ($plugin === '') {
            return true;
        }

        if (!class_exists('AP_Plugin', false)) {
            return true;
        }

        return AP_Plugin::isActive($plugin, $db);
    }

    /**
     * Normalize a plugin basename the same way the registry stores `plugin`.
     */
    public static function normalizePluginBasename(string $pluginBasename): string
    {
        $pluginBasename = str_replace('\\', '/', trim($pluginBasename));

        return ltrim($pluginBasename, '/');
    }

    /**
     * Whether the viewer may open a registered page (capability check).
     *
     * When $userId is a positive int, uses {@see userCan()}. Otherwise uses
     * {@see currentUserCan()}. If the roles layer is not loaded, returns true
     * so structural unit tests without a full auth stack still work.
     */
    public static function viewerCanAccessRegisteredPage(
        array $page,
        ?int $userId = null,
        ?AP_DB $db = null
    ): bool {
        $cap = self::capabilityForRegisteredPage($page);
        if ($userId !== null && $userId > 0) {
            return self::userCan($userId, $cap, null, $db);
        }
        if (!class_exists('AP_Roles', false)) {
            return true;
        }

        return self::currentUserCan($cap, $db);
    }

    /**
     * Settings action links for a plugin on plugins.php.
     *
     * Looks up registry pages with matching `plugin` basename. Returns only when
     * the plugin is active (when AP_Plugin is available) and the viewer has each
     * page’s capability. Ordered by registry position (stable by id).
     *
     * Each link uses the admin router URL ({@see pageUrl()}) — never a raw path
     * under ap-content/plugins/.
     *
     * @return list<array{id: string, label: string, url: string, capability: string}>
     */
    public static function pluginSettingsActionLinks(
        string $pluginBasename,
        ?AP_DB $db = null,
        ?int $userId = null
    ): array {
        $pluginBasename = self::normalizePluginBasename($pluginBasename);
        if ($pluginBasename === '' || !class_exists('AP_Admin_Menu', false)) {
            return [];
        }

        // plugins.php only shows Settings for active plugins.
        if (class_exists('AP_Plugin', false) && !AP_Plugin::isActive($pluginBasename, $db)) {
            return [];
        }

        $pages = AP_Admin_Menu::forPlugin($pluginBasename);
        if ($pages === []) {
            return [];
        }

        // Sort by position (stable by id) — forPlugin returns insertion order.
        usort(
            $pages,
            static function (array $a, array $b): int {
                $pos = ($a['position'] ?? 50) <=> ($b['position'] ?? 50);
                if ($pos !== 0) {
                    return $pos;
                }

                return strcmp((string) ($a['id'] ?? ''), (string) ($b['id'] ?? ''));
            }
        );

        $links = [];
        foreach ($pages as $page) {
            $id = (string) ($page['id'] ?? '');
            if ($id === '') {
                continue;
            }
            if (!self::viewerCanAccessRegisteredPage($page, $userId, $db)) {
                continue;
            }
            $label = trim((string) ($page['menu'] ?? ''));
            if ($label === '') {
                $label = trim((string) ($page['title'] ?? ''));
            }
            if ($label === '') {
                $label = $id;
            }
            $links[] = [
                'id' => $id,
                'label' => $label,
                'url' => self::pageUrl($id),
                'capability' => self::capabilityForRegisteredPage($page),
            ];
        }

        return $links;
    }

    /**
     * Primary Settings action link for a plugin (first by position), or null.
     *
     * Label is always “Settings” for the plugins list row action (WordPress-style).
     * Menu/title from the registry is available via {@see pluginSettingsActionLinks()}.
     *
     * @return array{id: string, label: string, url: string, capability: string}|null
     */
    public static function pluginSettingsActionLink(
        string $pluginBasename,
        ?AP_DB $db = null,
        ?int $userId = null
    ): ?array {
        $links = self::pluginSettingsActionLinks($pluginBasename, $db, $userId);
        if ($links === []) {
            return null;
        }
        $primary = $links[0];
        $primary['label'] = 'Settings';

        return $primary;
    }

    /**
     * Build sidebar menu item rows from the AP_Admin_Menu registry.
     *
     * Ordered by registry position (stable by id). Active state is left false;
     * {@see applyMenuActiveState()} is applied after merge in {@see menuItems()}.
     * Entries tied to an inactive plugin (`plugin` key) are omitted.
     *
     * @return list<array{id: string, label: string, url: string, active: bool, cap: string, module: string, section: string}>
     */
    public static function registeredMenuItems(?AP_DB $db = null): array
    {
        if (!class_exists('AP_Admin_Menu', false)) {
            return [];
        }

        $items = [];
        foreach (AP_Admin_Menu::allSorted() as $page) {
            $id = (string) ($page['id'] ?? '');
            if ($id === '') {
                continue;
            }
            if (!self::isRegisteredPagePluginActive($page, $db)) {
                continue;
            }
            $items[] = [
                'id' => $id,
                'label' => (string) ($page['menu'] !== '' ? $page['menu'] : $page['title']),
                'url' => self::pageUrl($id),
                'active' => false,
                'cap' => self::capabilityForRegisteredPage($page),
                'module' => '',
                'section' => self::menuSectionForRegisteredParent((string) ($page['parent'] ?? '')),
            ];
        }

        return $items;
    }

    /**
     * Merge registry pages into the core sidebar menu by parent section.
     *
     * Registered items are inserted after the last core item of their section so
     * the sidebar keeps one contiguous block per section. When a section has no
     * core items, those registry rows are appended. Core item ids are never
     * replaced (first wins — protects hardcoded ACP entries). Pages linked to
     * inactive plugins are not merged (see {@see registeredMenuItems()}).
     *
     * @param list<array{id: string, label?: string, url?: string, active?: bool, cap?: string, module?: string, section?: string}> $items
     *
     * @return list<array{id: string, label?: string, url?: string, active?: bool, cap?: string, module?: string, section?: string}>
     */
    public static function mergeRegisteredMenuItems(array $items, ?AP_DB $db = null): array
    {
        $registered = self::registeredMenuItems($db);
        if ($registered === []) {
            return $items;
        }

        $coreIds = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $id = (string) ($item['id'] ?? '');
            if ($id !== '') {
                $coreIds[$id] = true;
            }
        }

        /** @var array<string, list<array{id: string, label: string, url: string, active: bool, cap: string, module: string, section: string}>> $bySection */
        $bySection = [];
        foreach ($registered as $item) {
            $id = (string) $item['id'];
            if ($id === '' || isset($coreIds[$id])) {
                continue;
            }
            $section = (string) ($item['section'] !== '' ? $item['section'] : 'plugins');
            $bySection[$section][] = $item;
        }
        if ($bySection === []) {
            return $items;
        }

        $lastIndexBySection = [];
        foreach ($items as $i => $item) {
            if (!is_array($item)) {
                continue;
            }
            $section = (string) ($item['section'] ?? '');
            if ($section !== '') {
                $lastIndexBySection[$section] = $i;
            }
        }

        $out = [];
        $inserted = [];
        foreach ($items as $i => $item) {
            $out[] = $item;
            if (!is_array($item)) {
                continue;
            }
            $section = (string) ($item['section'] ?? '');
            if (
                $section !== ''
                && isset($bySection[$section])
                && ($lastIndexBySection[$section] ?? -1) === $i
            ) {
                foreach ($bySection[$section] as $reg) {
                    $out[] = $reg;
                }
                $inserted[$section] = true;
            }
        }

        foreach ($bySection as $section => $regs) {
            if (isset($inserted[$section])) {
                continue;
            }
            foreach ($regs as $reg) {
                $out[] = $reg;
            }
        }

        return $out;
    }

    /**
     * Build the HTML body for an admin 404 (unknown ?page=) without exiting.
     *
     * Escapes the message; does not read or embed $_GET. Used by {@see notFound()}
     * and unit tests.
     */
    public static function notFoundHtml(string $message = 'Not found'): string
    {
        $safe = function_exists('ap_esc_html')
            ? ap_esc_html($message)
            : htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $home = self::url('index.php');
        $homeEsc = function_exists('ap_esc_url')
            ? ap_esc_url($home)
            : htmlspecialchars($home, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>Not found — AgoraPress</title></head><body>'
            . '<main style="font-family:system-ui,sans-serif;max-width:32rem;margin:3rem auto;padding:1rem">'
            . '<h1>Page not found</h1>'
            . '<p>' . $safe . '</p>'
            . '<p><a href="' . $homeEsc . '">Back to dashboard</a></p>'
            . '</main></body></html>';
    }

    /**
     * Emit a 404 page and stop (unknown / unregistered admin.php?page= values).
     *
     * Safe error for the registry allowlist router: never includes a path from
     * query input. Prefer {@see unknownAdminPageMessage()} so the body does not
     * reflect the raw ?page= value.
     *
     * @return never
     */
    public static function notFound(string $message = 'Not found'): never
    {
        if (!headers_sent()) {
            http_response_code(404);
            header('Content-Type: text/html; charset=utf-8');
        }

        echo self::notFoundHtml($message);
        exit(0);
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
     * @param string $type   success|error|warning|info
     * @param bool   $escape When true (default), message is HTML-escaped on render.
     *                       Pass false only for trusted HTML built with escaping helpers
     *                       (e.g. version-check notice with download links).
     */
    public static function addNotice(string $message, string $type = 'success', bool $escape = true): void
    {
        $allowed = ['success', 'error', 'warning', 'info'];
        if (!in_array($type, $allowed, true)) {
            $type = 'info';
        }
        self::$notices[] = [
            'message' => $message,
            'type' => $type,
            'escape' => $escape,
        ];
    }

    /**
     * @return list<array{message: string, type: string, escape: bool}>
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

        $html = '<div class="ap-notices">' . "\n";
        foreach (self::$notices as $notice) {
            $type = ap_esc_attr($notice['type']);
            $escape = $notice['escape'] ?? true;
            $msg = $escape
                ? ap_esc_html($notice['message'])
                : (string) $notice['message'];
            // Errors/warnings interrupt; success/info are polite status updates (WCAG live regions).
            $role = in_array((string) $notice['type'], ['error', 'warning'], true) ? 'alert' : 'status';
            $html .= '  <div class="ap-notice ap-notice--' . $type . '" role="' . $role . '">' . $msg . '</div>' . "\n";
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
            'forums' => 'Forums',
            'appearance' => 'Appearance',
            'plugins' => 'Plugins',
            'users' => 'Users',
            'tools' => 'Tools',
            'settings' => 'Settings',
            default => '',
        };
    }

    /**
     * Native action name for ACP menu registration (preferred).
     *
     * Fired once per authenticated admin request from admin-bootstrap so
     * plugins can call {@see ap_register_admin_page()} (and WP add_*_page
     * shims) before the sidebar is built.
     */
    public const ADMIN_MENU_HOOK = 'ap_admin_menu';

    /**
     * WordPress-compatible alias of {@see ADMIN_MENU_HOOK}.
     *
     * Same request, same timing — listeners on either name run during
     * {@see fireAdminMenu()}.
     */
    public const ADMIN_MENU_HOOK_WP = 'admin_menu';

    /**
     * Fire admin_menu-equivalent actions once per request.
     *
     * Order: native {@see ADMIN_MENU_HOOK} (`ap_admin_menu`), then WP alias
     * {@see ADMIN_MENU_HOOK_WP} (`admin_menu`). Subsequent calls in the same
     * request are no-ops (tracked via {@see ap_did_action()}).
     *
     * Invoked from ap-admin/admin-bootstrap.php after login (not on the login
     * screen where `$ap_admin_skip_auth` is set).
     *
     * @return bool True when actions were fired on this call; false when skipped.
     */
    public static function fireAdminMenu(): bool
    {
        if (!function_exists('ap_do_action')) {
            return false;
        }

        // Once per request — ap_do_action increments even with zero callbacks.
        if (function_exists('ap_did_action') && ap_did_action(self::ADMIN_MENU_HOOK) > 0) {
            return false;
        }

        ap_do_action(self::ADMIN_MENU_HOOK);
        ap_do_action(self::ADMIN_MENU_HOOK_WP);

        return true;
    }

    /**
     * Admin menu items for the sidebar (filtered by current user capabilities).
     *
     * Core hardcoded items are listed first; pages from {@see AP_Admin_Menu}
     * are merged by parent section via {@see mergeRegisteredMenuItems()}
     * (settings → settings, plugins → plugins, tools → tools, empty → plugins).
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
                'id' => 'update-core',
                'label' => 'Update Core',
                'url' => self::url('update-core.php'),
                'active' => $current === 'update-core',
                'cap' => 'update_core',
                'module' => '',
                'section' => 'tools',
            ],
            [
                'id' => 'import',
                'label' => 'Import',
                'url' => self::url('import.php'),
                'active' => $current === 'import',
                'cap' => 'import',
                'module' => '',
                'section' => 'tools',
            ],
            [
                'id' => 'site-health',
                'label' => 'Site Health',
                'url' => self::url('site-health.php'),
                'active' => $current === 'site-health',
                'cap' => 'view_site_health',
                'module' => '',
                'section' => 'tools',
            ],
            [
                'id' => 'analytics',
                'label' => 'Analytics',
                'url' => self::url('analytics.php'),
                'active' => $current === 'analytics',
                'cap' => 'manage_options',
                'module' => '',
                'section' => 'tools',
            ],
            [
                'id' => 'export-personal-data',
                'label' => 'Export Personal Data',
                'url' => self::url('export-personal-data.php'),
                'active' => $current === 'export-personal-data',
                'cap' => 'export_others_personal_data',
                'module' => '',
                'section' => 'tools',
            ],
            [
                'id' => 'erase-personal-data',
                'label' => 'Erase Personal Data',
                'url' => self::url('erase-personal-data.php'),
                'active' => $current === 'erase-personal-data',
                'cap' => 'erase_others_personal_data',
                'module' => '',
                'section' => 'tools',
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
                'id' => 'forums',
                'label' => 'Forums',
                'url' => self::url('forums.php'),
                'active' => $current === 'forums',
                'cap' => 'manage_forums',
                'module' => 'forum',
                'section' => 'forums',
            ],
            [
                'id' => 'forum-topics',
                'label' => 'Topics',
                'url' => self::url('forum-topics.php'),
                'active' => $current === 'forum-topics',
                'cap' => 'moderate_forums',
                'module' => 'forum',
                'section' => 'forums',
            ],
            [
                'id' => 'forum-moderation',
                'label' => 'Moderation',
                'url' => self::url('forum-moderation.php'),
                'active' => $current === 'forum-moderation',
                'cap' => 'moderate_forums',
                'module' => 'forum',
                'section' => 'forums',
            ],
            [
                'id' => 'forum-groups',
                'label' => 'Groups',
                'url' => self::url('forum-groups.php'),
                'active' => $current === 'forum-groups',
                'cap' => 'manage_forums',
                'module' => 'forum',
                'section' => 'forums',
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
                'id' => 'options-privacy',
                'label' => 'Privacy',
                'url' => self::url('options-privacy.php'),
                'active' => $current === 'options-privacy',
                'cap' => 'manage_privacy_options',
                'module' => '',
                'section' => 'settings',
            ],
            [
                'id' => 'options-forums',
                'label' => 'Forums',
                'url' => self::url('options-forums.php'),
                'active' => $current === 'options-forums',
                'cap' => 'manage_options',
                'module' => 'forum',
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

        // Plugin-registered ACP pages (settings / plugins / tools / default plugins).
        // Linked-plugin pages are omitted when that plugin is inactive.
        $items = self::mergeRegisteredMenuItems($items, $db);

        // Single active-state path: item id === $current (registry page ids use
        // the same convention via registeredPageScreenContext / admin.php).
        $items = self::applyMenuActiveState($items, $current);

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
            'image_edited' => ['Image scaled or cropped.', 'success'],
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
            'privacy_saved' => ['Privacy settings saved.', 'success'],
            'menu_created' => ['Menu created.', 'success'],
            'menu_saved' => ['Menu saved.', 'success'],
            'menu_deleted' => ['Menu deleted.', 'success'],
            'menu_item_moved' => ['Menu item order updated.', 'success'],
            'menu_item_removed' => ['Menu item removed.', 'success'],
            'menu_locations_saved' => ['Menu locations updated.', 'success'],
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
            // Forums
            'forum_created' => ['Forum created.', 'success'],
            'forum_updated' => ['Forum updated.', 'success'],
            'forum_deleted' => ['Forum deleted.', 'success'],
            'bulk_forum_deleted' => ['Selected forums deleted.', 'success'],
            'topic_locked' => ['Topic locked.', 'success'],
            'topic_unlocked' => ['Topic unlocked.', 'success'],
            'topic_sticky' => ['Topic marked sticky.', 'success'],
            'topic_unsticky' => ['Topic sticky removed.', 'success'],
            'topic_approved' => ['Topic approved.', 'success'],
            'topic_unapproved' => ['Topic unapproved.', 'success'],
            'topic_trashed' => ['Topic soft-deleted.', 'success'],
            'topic_restored' => ['Topic restored.', 'success'],
            'topic_deleted' => ['Topic permanently deleted.', 'success'],
            'bulk_topic_locked' => ['Selected topics locked.', 'success'],
            'bulk_topic_unlocked' => ['Selected topics unlocked.', 'success'],
            'bulk_topic_sticky' => ['Selected topics marked sticky.', 'success'],
            'bulk_topic_unsticky' => ['Selected topics un-stickied.', 'success'],
            'bulk_topic_approved' => ['Selected topics approved.', 'success'],
            'bulk_topic_unapproved' => ['Selected topics unapproved.', 'success'],
            'bulk_topic_trashed' => ['Selected topics soft-deleted.', 'success'],
            'bulk_topic_restored' => ['Selected topics restored.', 'success'],
            'bulk_topic_deleted' => ['Selected topics permanently deleted.', 'success'],
            'forum_post_approved' => ['Forum post approved.', 'success'],
            'forum_post_trashed' => ['Forum post soft-deleted.', 'success'],
            'bulk_forum_post_approved' => ['Selected forum posts approved.', 'success'],
            'bulk_forum_post_trashed' => ['Selected forum posts soft-deleted.', 'success'],
            'report_resolved' => ['Report resolved.', 'success'],
            'report_dismissed' => ['Report dismissed.', 'success'],
            'report_reopened' => ['Report re-opened.', 'success'],
            'bulk_report_resolved' => ['Selected reports resolved.', 'success'],
            'bulk_report_dismissed' => ['Selected reports dismissed.', 'success'],
            'group_created' => ['Group created.', 'success'],
            'group_updated' => ['Group updated.', 'success'],
            'group_deleted' => ['Group deleted.', 'success'],
            'group_member_added' => ['Member added to group.', 'success'],
            'forums_saved' => ['Forum settings saved.', 'success'],
            'analytics_saved' => ['Analytics settings saved.', 'success'],
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
