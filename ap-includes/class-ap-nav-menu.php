<?php

/**
 * AgoraPress navigation menus.
 *
 * Themes register locations; menus and items are stored as site options
 * (JSON). Items may point at pages, posts, categories/tags, custom URLs, or
 * special “useful links” (privacy policy, login/account, register).
 * Admin screen: ap-admin/nav-menus.php. Front-end: ap_nav_menu() / AP_Nav_Menu::render().
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * Register, persist, and render navigation menus.
 */
class AP_Nav_Menu
{
    /** Option holding all menus keyed by slug. */
    public const OPTION_MENUS = 'ap_nav_menus';

    /** Option: theme location slug => menu slug. */
    public const OPTION_LOCATIONS = 'nav_menu_locations';

    /** @var array<string, string> Registered theme locations (slug => description). */
    private static array $locations = [];

    /**
     * Register a theme menu location (call from theme setup).
     */
    public static function registerLocation(string $location, string $description = ''): void
    {
        $location = self::sanitizeSlug($location);
        if ($location === '') {
            return;
        }
        self::$locations[$location] = $description !== '' ? $description : $location;
    }

    /**
     * Register multiple locations at once.
     *
     * @param array<string, string> $locations slug => description
     */
    public static function registerLocations(array $locations): void
    {
        foreach ($locations as $slug => $desc) {
            if (is_string($slug)) {
                self::registerLocation($slug, is_string($desc) ? $desc : '');
            }
        }
    }

    /**
     * Registered theme locations.
     *
     * @return array<string, string>
     */
    public static function getRegisteredLocations(): array
    {
        return self::$locations;
    }

    /**
     * Assigned menu slug per theme location.
     *
     * @return array<string, string>
     */
    public static function getLocationAssignments(?AP_DB $db = null): array
    {
        $raw = AP_Options::get(self::OPTION_LOCATIONS, [], $db);
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $loc => $menu) {
            if (!is_string($loc) || !is_string($menu)) {
                continue;
            }
            $loc = self::sanitizeSlug($loc);
            $menu = self::sanitizeSlug($menu);
            if ($loc !== '' && $menu !== '') {
                $out[$loc] = $menu;
            }
        }

        return $out;
    }

    /**
     * Assign menus to theme locations.
     *
     * @param array<string, string> $map location => menu slug (empty string clears)
     */
    public static function setLocationAssignments(array $map, ?AP_DB $db = null): bool
    {
        $clean = [];
        foreach ($map as $loc => $menu) {
            if (!is_string($loc)) {
                continue;
            }
            $loc = self::sanitizeSlug($loc);
            $menu = is_string($menu) ? self::sanitizeSlug($menu) : '';
            if ($loc === '') {
                continue;
            }
            if ($menu !== '') {
                $clean[$loc] = $menu;
            }
        }

        return AP_Options::update(self::OPTION_LOCATIONS, $clean, $db);
    }

    /**
     * Build a full location => menu map from the Manage Locations admin form.
     *
     * Expects POST fields `menu_location[{location_slug}]` with menu slugs
     * (empty string = unassigned). Only registered location keys are kept.
     *
     * @param array<string, mixed>  $post                Typically $_POST
     * @param array<string, string> $registeredLocations slug => description
     *
     * @return array<string, string> location => menu slug (omits empty)
     */
    public static function locationsFromAdminPost(array $post, array $registeredLocations): array
    {
        $raw = $post['menu_location'] ?? [];
        if (!is_array($raw)) {
            $raw = [];
        }

        $map = [];
        foreach ($registeredLocations as $loc => $_desc) {
            if (!is_string($loc)) {
                continue;
            }
            $loc = self::sanitizeSlug($loc);
            if ($loc === '') {
                continue;
            }
            $menu = '';
            if (isset($raw[$loc])) {
                $menu = is_string($raw[$loc]) ? self::sanitizeSlug($raw[$loc]) : '';
            }
            if ($menu !== '') {
                $map[$loc] = $menu;
            }
        }

        return $map;
    }

    /**
     * Theme location slugs currently assigned to a given menu.
     *
     * @return list<string>
     */
    public static function getLocationsForMenu(string $menuSlug, ?AP_DB $db = null): array
    {
        $menuSlug = self::sanitizeSlug($menuSlug);
        if ($menuSlug === '') {
            return [];
        }
        $out = [];
        foreach (self::getLocationAssignments($db) as $loc => $assigned) {
            if ($assigned === $menuSlug) {
                $out[] = $loc;
            }
        }

        return $out;
    }

    /**
     * Merge per-menu location checkboxes into the global assignment map.
     *
     * When editing one menu, checked locations are set to that menu; locations
     * that were assigned to it and are now unchecked are cleared. Assignments
     * for other menus are left intact.
     *
     * @param array<string, string> $current      Existing location => menu map
     * @param array<string, string> $registered   Registered locations (slug => desc)
     * @param string                $menuSlug     Menu being edited
     * @param array<string, mixed>  $post         Typically $_POST (location_{slug}=1)
     *
     * @return array<string, string>
     */
    public static function mergeMenuLocationCheckboxes(
        array $current,
        array $registered,
        string $menuSlug,
        array $post
    ): array {
        $menuSlug = self::sanitizeSlug($menuSlug);
        if ($menuSlug === '') {
            return $current;
        }

        $map = $current;
        foreach ($registered as $loc => $_desc) {
            if (!is_string($loc)) {
                continue;
            }
            $loc = self::sanitizeSlug($loc);
            if ($loc === '') {
                continue;
            }
            $field = 'location_' . $loc;
            $checked = !empty($post[$field]) && (string) $post[$field] === '1';
            if ($checked) {
                $map[$loc] = $menuSlug;
            } elseif (isset($map[$loc]) && $map[$loc] === $menuSlug) {
                unset($map[$loc]);
            }
        }

        return $map;
    }

    /**
     * All menus (slug => data).
     *
     * @return array<string, array{name: string, items: list<array<string, mixed>>}>
     */
    public static function getMenus(?AP_DB $db = null): array
    {
        $raw = AP_Options::get(self::OPTION_MENUS, [], $db);
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $slug => $menu) {
            if (!is_string($slug) || !is_array($menu)) {
                continue;
            }
            $slug = self::sanitizeSlug($slug);
            if ($slug === '') {
                continue;
            }
            $out[$slug] = self::normalizeMenu($menu, $slug);
        }

        return $out;
    }

    /**
     * Single menu by slug, or null.
     *
     * @return array{name: string, items: list<array<string, mixed>>}|null
     */
    public static function getMenu(string $slug, ?AP_DB $db = null): ?array
    {
        $slug = self::sanitizeSlug($slug);
        if ($slug === '') {
            return null;
        }
        $menus = self::getMenus($db);

        return $menus[$slug] ?? null;
    }

    /**
     * Item types accepted by the menu editor and storage layer.
     *
     * @return list<string>
     */
    public static function allowedItemTypes(): array
    {
        return [
            'custom',
            'page',
            'post',
            'category',
            'post_tag',
            'tag',
            'forum',
            // Dynamic utility links (resolved at render time).
            'privacy_policy',
            'login',
            'register',
        ];
    }

    /**
     * Special item types that resolve dynamically (no object_id / fixed URL).
     *
     * @return list<string>
     */
    public static function usefulLinkTypes(): array
    {
        return ['privacy_policy', 'login', 'register'];
    }

    /**
     * Catalog of placeable utility links for Appearance → Menus.
     *
     * @return list<array{
     *   type: string,
     *   label: string,
     *   description: string,
     *   available: bool
     * }>
     */
    public static function getUsefulLinks(?AP_DB $db = null): array
    {
        $privacyUrl = self::privacyPolicyUrl($db);
        $canRegister = self::usersCanRegister($db);

        return [
            [
                'type' => 'privacy_policy',
                'label' => 'Privacy Policy',
                'description' => $privacyUrl !== ''
                    ? 'Uses the page selected under Settings → Privacy.'
                    : 'Set a privacy policy page under Settings → Privacy first.',
                'available' => $privacyUrl !== '',
            ],
            [
                'type' => 'login',
                'label' => 'Login / Account',
                'description' => 'Shows “Login” when visitors are logged out, “Account” when logged in.',
                'available' => true,
            ],
            [
                'type' => 'register',
                'label' => 'Register',
                'description' => $canRegister
                    ? 'Public registration form.'
                    : 'Enable “Anyone can register” under Settings → General first.',
                'available' => $canRegister,
            ],
        ];
    }

    /**
     * Create or update a menu.
     *
     * @param list<array<string, mixed>> $items
     */
    public static function saveMenu(string $slug, string $name, array $items = [], ?AP_DB $db = null): bool
    {
        $slug = self::sanitizeSlug($slug);
        if ($slug === '') {
            // Derive from name.
            $slug = self::sanitizeSlug($name);
        }
        if ($slug === '') {
            return false;
        }
        $name = trim($name) !== '' ? trim($name) : $slug;

        $menus = self::getMenus($db);
        $menus[$slug] = self::normalizeMenu([
            'name' => $name,
            'items' => $items,
        ], $slug);

        return AP_Options::update(self::OPTION_MENUS, $menus, $db);
    }

    /**
     * Build a raw items list from admin POST data (existing rows + add pickers).
     *
     * Existing rows use item_title / item_type / item_url / item_object_id keyed by index.
     * Checked item_remove[i] drops that row. New content comes from add_page[], add_post[],
     * add_category[], add_forum[] (IDs), add_useful[] (privacy_policy|login|register),
     * and optional new_item_* custom-link fields.
     *
     * @param array<string, mixed> $post Typically $_POST
     *
     * @return list<array<string, mixed>>
     */
    public static function itemsFromAdminPost(array $post): array
    {
        $items = [];
        $titles = $post['item_title'] ?? [];
        $types = $post['item_type'] ?? [];
        $urls = $post['item_url'] ?? [];
        $objectIds = $post['item_object_id'] ?? [];
        $remove = $post['item_remove'] ?? [];

        if (is_array($titles)) {
            foreach ($titles as $idx => $title) {
                if (is_array($remove) && !empty($remove[$idx])) {
                    continue;
                }
                $type = is_array($types) ? (string) ($types[$idx] ?? 'custom') : 'custom';
                $url = is_array($urls) ? (string) ($urls[$idx] ?? '') : '';
                $oid = is_array($objectIds) ? (int) ($objectIds[$idx] ?? 0) : 0;
                $items[] = [
                    'type' => $type,
                    'title' => is_string($title) ? $title : (string) $title,
                    'url' => $url,
                    'object_id' => $oid,
                ];
            }
        }

        $pickerMap = [
            'page' => 'add_page',
            'post' => 'add_post',
            'category' => 'add_category',
            'forum' => 'add_forum',
            'post_tag' => 'add_tag',
        ];
        foreach ($pickerMap as $type => $field) {
            $ids = $post[$field] ?? [];
            if (!is_array($ids)) {
                continue;
            }
            foreach ($ids as $id) {
                $oid = (int) $id;
                if ($oid < 1) {
                    continue;
                }
                $items[] = [
                    'type' => $type,
                    'title' => '',
                    'url' => '',
                    'object_id' => $oid,
                ];
            }
        }

        // Useful links panel: add_useful[] = privacy_policy | login | register.
        $useful = $post['add_useful'] ?? [];
        if (is_array($useful)) {
            $allowedUseful = self::usefulLinkTypes();
            foreach ($useful as $rawType) {
                $type = self::sanitizeSlug((string) $rawType);
                if (!in_array($type, $allowedUseful, true)) {
                    continue;
                }
                $items[] = [
                    'type' => $type,
                    'title' => '',
                    'url' => '',
                    'object_id' => 0,
                ];
            }
        }

        $newTitle = isset($post['new_item_title']) ? trim((string) $post['new_item_title']) : '';
        $newUrl = isset($post['new_item_url']) ? trim((string) $post['new_item_url']) : '';
        $newType = isset($post['new_item_type']) ? trim((string) $post['new_item_type']) : 'custom';
        $newOid = (int) ($post['new_item_object_id'] ?? 0);
        if ($newTitle !== '' || $newUrl !== '' || $newOid > 0) {
            $items[] = [
                'type' => $newType !== '' ? $newType : 'custom',
                'title' => $newTitle,
                'url' => $newUrl,
                'object_id' => $newOid,
            ];
        }

        return $items;
    }

    /**
     * Reorder a single item within a menu (up or down). Returns false if nothing moved.
     */
    public static function moveItem(string $slug, int $index, string $direction, ?AP_DB $db = null): bool
    {
        $menu = self::getMenu($slug, $db);
        if ($menu === null) {
            return false;
        }
        $items = $menu['items'];
        $count = count($items);
        if ($index < 0 || $index >= $count) {
            return false;
        }
        $swap = $direction === 'up' ? $index - 1 : $index + 1;
        if ($swap < 0 || $swap >= $count) {
            return false;
        }
        $tmp = $items[$swap];
        $items[$swap] = $items[$index];
        $items[$index] = $tmp;

        return self::saveMenu($slug, $menu['name'], $items, $db);
    }

    /**
     * Remove a single item by index.
     */
    public static function removeItem(string $slug, int $index, ?AP_DB $db = null): bool
    {
        $menu = self::getMenu($slug, $db);
        if ($menu === null) {
            return false;
        }
        if (!isset($menu['items'][$index])) {
            return false;
        }
        $items = $menu['items'];
        array_splice($items, $index, 1);

        return self::saveMenu($slug, $menu['name'], $items, $db);
    }

    /**
     * Delete a menu and clear location assignments that pointed at it.
     */
    public static function deleteMenu(string $slug, ?AP_DB $db = null): bool
    {
        $slug = self::sanitizeSlug($slug);
        if ($slug === '') {
            return false;
        }
        $menus = self::getMenus($db);
        if (!isset($menus[$slug])) {
            return false;
        }
        unset($menus[$slug]);
        $ok = AP_Options::update(self::OPTION_MENUS, $menus, $db);

        $locs = self::getLocationAssignments($db);
        $changed = false;
        foreach ($locs as $loc => $menu) {
            if ($menu === $slug) {
                unset($locs[$loc]);
                $changed = true;
            }
        }
        if ($changed) {
            self::setLocationAssignments($locs, $db);
        }

        return $ok;
    }

    /**
     * Resolve menu slug for a theme location (or empty).
     */
    public static function getMenuSlugForLocation(string $location, ?AP_DB $db = null): string
    {
        $location = self::sanitizeSlug($location);
        $map = self::getLocationAssignments($db);

        return $map[$location] ?? '';
    }

    /**
     * Whether a location has an assigned menu with at least one item.
     */
    public static function hasNavMenu(string $location, ?AP_DB $db = null): bool
    {
        $slug = self::getMenuSlugForLocation($location, $db);
        if ($slug === '') {
            return false;
        }
        $menu = self::getMenu($slug, $db);

        return $menu !== null && $menu['items'] !== [];
    }

    /**
     * Render a nav menu to HTML (or return empty string).
     *
     * @param array{
     *   theme_location?: string,
     *   menu?: string,
     *   container?: string,
     *   container_class?: string,
     *   container_id?: string,
     *   menu_class?: string,
     *   menu_id?: string,
     *   depth?: int,
     *   fallback_cb?: callable|false|null,
     *   echo?: bool
     * } $args
     */
    public static function render(array $args = [], ?AP_DB $db = null): string
    {
        $defaults = [
            'theme_location' => '',
            'menu' => '',
            'container' => 'nav',
            'container_class' => 'ap-nav',
            'container_id' => '',
            'menu_class' => 'ap-menu',
            'menu_id' => '',
            'depth' => 0,
            'fallback_cb' => null,
            'echo' => true,
        ];
        $args = array_merge($defaults, $args);

        $slug = self::sanitizeSlug((string) $args['menu']);
        if ($slug === '' && (string) $args['theme_location'] !== '') {
            $slug = self::getMenuSlugForLocation((string) $args['theme_location'], $db);
        }

        $menu = $slug !== '' ? self::getMenu($slug, $db) : null;
        $itemsHtml = ($menu !== null && $menu['items'] !== [])
            ? self::renderItems($menu['items'], $db)
            : '';

        // Empty menu, or only unpublished/invalid items → optional fallback.
        if ($itemsHtml === '') {
            $html = '';
            if (is_callable($args['fallback_cb'])) {
                $html = (string) call_user_func($args['fallback_cb'], $args, $db);
            }
            if (!empty($args['echo'])) {
                echo $html;
            }

            return $html;
        }

        $ulId = (string) $args['menu_id'];
        $ulClass = (string) $args['menu_class'];
        $ul = '<ul'
            . ($ulId !== '' ? ' id="' . ap_esc_attr($ulId) . '"' : '')
            . ($ulClass !== '' ? ' class="' . ap_esc_attr($ulClass) . '"' : '')
            . '>' . $itemsHtml . '</ul>';

        $container = strtolower(trim((string) $args['container']));
        $allowed = ['nav', 'div', ''];
        if (!in_array($container, $allowed, true)) {
            $container = 'nav';
        }

        if ($container === '') {
            $html = $ul;
        } else {
            $cid = (string) $args['container_id'];
            $cclass = (string) $args['container_class'];
            $label = $menu['name'] !== '' ? $menu['name'] : 'Menu';
            $html = '<' . $container
                . ($cid !== '' ? ' id="' . ap_esc_attr($cid) . '"' : '')
                . ($cclass !== '' ? ' class="' . ap_esc_attr($cclass) . '"' : '')
                . ' aria-label="' . ap_esc_attr($label) . '">'
                . $ul
                . '</' . $container . '>';
        }

        if (!empty($args['echo'])) {
            echo $html;
        }

        return $html;
    }

    /**
     * Build public URL for a menu item.
     *
     * @param array<string, mixed> $item
     */
    public static function itemUrl(array $item, ?AP_DB $db = null): string
    {
        $type = (string) ($item['type'] ?? 'custom');
        $objectId = (int) ($item['object_id'] ?? 0);

        return match ($type) {
            'page', 'post' => self::objectPermalink($objectId, $type, $db),
            'category' => self::termLink($objectId, 'category', $db),
            'post_tag', 'tag' => self::termLink($objectId, 'post_tag', $db),
            'forum' => self::forumLink($objectId, $db),
            'privacy_policy' => self::privacyPolicyUrl($db),
            'login' => self::loginOrAccountUrl($db),
            'register' => self::registerUrl($db),
            default => (string) ($item['url'] ?? ''),
        };
    }

    /**
     * Display title for a menu item (falls back to object title).
     *
     * @param array<string, mixed> $item
     */
    public static function itemTitle(array $item, ?AP_DB $db = null): string
    {
        $title = trim((string) ($item['title'] ?? ''));
        if ($title !== '') {
            return $title;
        }

        $type = (string) ($item['type'] ?? 'custom');
        if ($type === 'privacy_policy') {
            return 'Privacy Policy';
        }
        if ($type === 'login') {
            return self::isUserLoggedIn($db) ? 'Account' : 'Login';
        }
        if ($type === 'register') {
            return 'Register';
        }

        $objectId = (int) ($item['object_id'] ?? 0);
        if ($objectId > 0 && in_array($type, ['page', 'post'], true) && class_exists('AP_Post', false)) {
            $post = AP_Post::get($objectId, $db);
            if ($post instanceof AP_Post) {
                return (string) $post->post_title;
            }
        }
        if ($objectId > 0 && in_array($type, ['category', 'post_tag', 'tag'], true) && class_exists('AP_Taxonomy', false)) {
            $tax = $type === 'tag' ? 'post_tag' : $type;
            $term = AP_Taxonomy::getTerm($objectId, $tax, $db);
            if (is_object($term) && isset($term->name)) {
                return (string) $term->name;
            }
        }
        if ($objectId > 0 && $type === 'forum' && class_exists('AP_Forum', false)) {
            $forum = AP_Forum::getForum($objectId, $db);
            if (is_object($forum) && isset($forum->forum_name)) {
                return (string) $forum->forum_name;
            }
        }

        $fallback = trim((string) ($item['url'] ?? ''));
        if ($fallback !== '') {
            return $fallback;
        }
        if ($objectId > 0) {
            return ucfirst($type) . ' #' . $objectId;
        }

        return 'Link';
    }

    /**
     * Whether a stored menu item should appear on the public site.
     *
     * Page and post items only render when the object exists, matches the
     * expected type, and has a public (published) status. Useful links resolve
     * at render time (privacy page must exist; register only when open).
     * Custom links and taxonomy/forum items stay visible when they resolve a label.
     *
     * @param array<string, mixed> $item
     */
    public static function isItemVisible(array $item, ?AP_DB $db = null): bool
    {
        $type = (string) ($item['type'] ?? 'custom');
        $objectId = (int) ($item['object_id'] ?? 0);

        if (in_array($type, ['page', 'post'], true)) {
            if ($objectId < 1 || !class_exists('AP_Post', false)) {
                return false;
            }
            $post = AP_Post::get($objectId, $db);
            if (!$post instanceof AP_Post) {
                return false;
            }
            if ($post->post_type !== $type) {
                return false;
            }
            if (method_exists($post, 'isPubliclyViewable')) {
                return $post->isPubliclyViewable();
            }

            return AP_Post::isPublicStatus((string) $post->post_status);
        }

        if ($type === 'privacy_policy') {
            return self::privacyPolicyUrl($db) !== '';
        }
        if ($type === 'login') {
            return self::loginOrAccountUrl($db) !== '';
        }
        if ($type === 'register') {
            // Hide when registration is closed (or URL cannot be built).
            return self::usersCanRegister($db) && self::registerUrl($db) !== '';
        }

        // Non-content items: visible when they can produce a title.
        return self::itemTitle($item, $db) !== '';
    }

    /**
     * Published pages available for menus / fallback navigation.
     *
     * @return list<AP_Post>
     */
    public static function getPublishedPages(?AP_DB $db = null, int $limit = 100): array
    {
        if (!class_exists('AP_Post', false)) {
            return [];
        }
        if (function_exists('ap_is_module_enabled') && !ap_is_module_enabled('static_pages', $db)) {
            return [];
        }

        $limit = max(0, min(200, $limit));
        if ($limit === 0) {
            return [];
        }

        // Fetch a bit extra so we can still fill $limit after show_in_nav filtering.
        $fetchLimit = min(200, max($limit, $limit * 2));
        $pages = AP_Post::query([
            'post_type' => 'page',
            'post_status' => 'publish',
            'limit' => $fetchLimit,
            'orderby' => 'menu_order',
            'order' => 'ASC',
        ], $db);

        $out = [];
        foreach ($pages as $page) {
            if (!$page instanceof AP_Post) {
                continue;
            }
            // Per-page “Show in navigation” control (default on when meta missing).
            if (!AP_Post::showsInNav((int) $page->ID, $db)) {
                continue;
            }
            $out[] = $page;
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * Default primary-location fallback: Home, published pages, optional Forums.
     *
     * Used when no custom menu is assigned (or the assigned menu has no items).
     * Ensures published static pages can appear in the navigation bar without
     * requiring a hand-built menu. Themes may pass this as fallback_cb.
     *
     * @param array<string, mixed> $args Same shape as render() args when used as callback
     */
    public static function fallbackPrimary(array $args = [], ?AP_DB $db = null): string
    {
        $container = strtolower(trim((string) ($args['container'] ?? 'nav')));
        if (!in_array($container, ['nav', 'div', ''], true)) {
            $container = 'nav';
        }
        $containerClass = (string) ($args['container_class'] ?? 'ap-nav ap-nav--primary');
        $containerId = (string) ($args['container_id'] ?? '');
        $menuClass = (string) ($args['menu_class'] ?? 'ap-menu');
        $menuId = (string) ($args['menu_id'] ?? '');
        $ariaLabel = (string) ($args['aria_label'] ?? $args['container_aria_label'] ?? 'Primary');
        if ($ariaLabel === '') {
            $ariaLabel = 'Primary';
        }
        $includeHome = !array_key_exists('include_home', $args) || !empty($args['include_home']);
        $includeForums = !array_key_exists('include_forums', $args) || !empty($args['include_forums']);
        $pageLimit = isset($args['page_limit']) ? (int) $args['page_limit'] : 50;

        $home = '/';
        if (function_exists('ap_home_url') && class_exists('AP_Rewrite', false)) {
            try {
                $home = ap_home_url('/');
            } catch (Throwable) {
                $home = '/';
            }
        }

        $itemsHtml = '';
        if ($includeHome) {
            $itemsHtml .= '<li class="menu-item menu-item-type-custom menu-item-home">'
                . '<a href="' . ap_esc_url($home) . '">' . ap_esc_html('Home') . '</a></li>';
        }

        foreach (self::getPublishedPages($db, $pageLimit) as $page) {
            $url = self::objectPermalink((int) $page->ID, 'page', $db);
            $title = trim((string) $page->post_title);
            if ($title === '') {
                $title = 'Page #' . (int) $page->ID;
            }
            $itemsHtml .= '<li class="menu-item menu-item-type-page">'
                . '<a href="' . ap_esc_url($url !== '' ? $url : '#') . '">'
                . ap_esc_html($title) . '</a></li>';
        }

        if ($includeForums) {
            $forumNav = class_exists('AP_Forum', false);
            if ($forumNav && function_exists('ap_is_module_enabled')) {
                try {
                    $forumNav = ap_is_module_enabled('forum', $db);
                } catch (Throwable) {
                    $forumNav = class_exists('AP_Forum', false);
                }
            }
            if ($forumNav) {
                $forumsHref = rtrim($home, '/') . '/forums/';
                if (function_exists('ap_forums_url') && class_exists('AP_Forum', false)) {
                    try {
                        $forumsHref = ap_forums_url();
                    } catch (Throwable) {
                        // keep path fallback
                    }
                }
                $itemsHtml .= '<li class="menu-item menu-item-type-forum">'
                    . '<a href="' . ap_esc_url($forumsHref) . '">'
                    . ap_esc_html('Forums') . '</a></li>';
            }
        }

        if ($itemsHtml === '') {
            return '';
        }

        $ul = '<ul'
            . ($menuId !== '' ? ' id="' . ap_esc_attr($menuId) . '"' : '')
            . ($menuClass !== '' ? ' class="' . ap_esc_attr($menuClass) . '"' : '')
            . '>' . $itemsHtml . '</ul>';

        if ($container === '') {
            $html = $ul;
        } else {
            $html = '<' . $container
                . ($containerId !== '' ? ' id="' . ap_esc_attr($containerId) . '"' : '')
                . ($containerClass !== '' ? ' class="' . ap_esc_attr($containerClass) . '"' : '')
                . ' aria-label="' . ap_esc_attr($ariaLabel) . '">'
                . $ul
                . '</' . $container . '>';
        }

        if (!empty($args['echo'])) {
            echo $html;
        }

        return $html;
    }

    /**
     * Default footer-location fallback: Privacy Policy + Login/Account (+ Register).
     *
     * Used when no custom footer menu is assigned. Makes common utility links
     * available without building a menu by hand. Themes may pass this as
     * fallback_cb for the footer location.
     *
     * @param array<string, mixed> $args Same shape as render() args when used as callback
     */
    public static function fallbackFooter(array $args = [], ?AP_DB $db = null): string
    {
        $container = strtolower(trim((string) ($args['container'] ?? 'nav')));
        if (!in_array($container, ['nav', 'div', ''], true)) {
            $container = 'nav';
        }
        $containerClass = (string) ($args['container_class'] ?? 'ap-nav ap-nav--footer');
        $containerId = (string) ($args['container_id'] ?? '');
        $menuClass = (string) ($args['menu_class'] ?? 'ap-menu ap-menu--footer');
        $menuId = (string) ($args['menu_id'] ?? '');
        $ariaLabel = (string) ($args['aria_label'] ?? $args['container_aria_label'] ?? 'Footer');
        if ($ariaLabel === '') {
            $ariaLabel = 'Footer';
        }

        $includePrivacy = !array_key_exists('include_privacy', $args) || !empty($args['include_privacy']);
        $includeLogin = !array_key_exists('include_login', $args) || !empty($args['include_login']);
        $includeRegister = !array_key_exists('include_register', $args) || !empty($args['include_register']);

        $pseudoItems = [];
        if ($includePrivacy) {
            $pseudoItems[] = ['type' => 'privacy_policy', 'title' => '', 'url' => '', 'object_id' => 0];
        }
        if ($includeLogin) {
            $pseudoItems[] = ['type' => 'login', 'title' => '', 'url' => '', 'object_id' => 0];
        }
        if ($includeRegister) {
            $pseudoItems[] = ['type' => 'register', 'title' => '', 'url' => '', 'object_id' => 0];
        }

        $itemsHtml = self::renderItems($pseudoItems, $db);
        if ($itemsHtml === '') {
            return '';
        }

        $ul = '<ul'
            . ($menuId !== '' ? ' id="' . ap_esc_attr($menuId) . '"' : '')
            . ($menuClass !== '' ? ' class="' . ap_esc_attr($menuClass) . '"' : '')
            . '>' . $itemsHtml . '</ul>';

        if ($container === '') {
            $html = $ul;
        } else {
            $html = '<' . $container
                . ($containerId !== '' ? ' id="' . ap_esc_attr($containerId) . '"' : '')
                . ($containerClass !== '' ? ' class="' . ap_esc_attr($containerClass) . '"' : '')
                . ' aria-label="' . ap_esc_attr($ariaLabel) . '">'
                . $ul
                . '</' . $container . '>';
        }

        if (!empty($args['echo'])) {
            echo $html;
        }

        return $html;
    }

    /**
     * Clear registered locations (tests).
     */
    public static function reset(): void
    {
        self::$locations = [];
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * @param list<array<string, mixed>> $items
     */
    private static function renderItems(array $items, ?AP_DB $db): string
    {
        $html = '';
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            // Hide draft / missing / wrong-type pages & posts from the public bar.
            if (!self::isItemVisible($item, $db)) {
                continue;
            }
            $url = self::itemUrl($item, $db);
            $title = self::itemTitle($item, $db);
            if ($title === '') {
                continue;
            }
            // Object / dynamic items without a resolvable URL must not surface.
            $type = (string) ($item['type'] ?? 'custom');
            if (
                (in_array($type, ['page', 'post'], true) || in_array($type, self::usefulLinkTypes(), true))
                && $url === ''
            ) {
                continue;
            }
            $target = !empty($item['target']) ? (string) $item['target'] : '';
            $classes = ['menu-item'];
            $classes[] = 'menu-item-type-' . self::sanitizeSlug($type);
            if (!empty($item['classes']) && is_array($item['classes'])) {
                foreach ($item['classes'] as $c) {
                    if (is_string($c) && $c !== '') {
                        $classes[] = self::sanitizeSlug($c);
                    }
                }
            }
            $classAttr = implode(' ', array_filter($classes));
            $html .= '<li class="' . ap_esc_attr($classAttr) . '">';
            $html .= '<a href="' . ap_esc_url($url !== '' ? $url : '#') . '"';
            if ($target === '_blank') {
                $html .= ' target="_blank" rel="noopener noreferrer"';
            }
            $html .= '>' . ap_esc_html($title) . '</a>';
            $html .= '</li>';
        }

        return $html;
    }

    /**
     * @param array<string, mixed> $menu
     *
     * @return array{name: string, items: list<array<string, mixed>>}
     */
    private static function normalizeMenu(array $menu, string $slug): array
    {
        $name = isset($menu['name']) && is_string($menu['name']) && trim($menu['name']) !== ''
            ? trim($menu['name'])
            : $slug;
        $items = [];
        $rawItems = $menu['items'] ?? [];
        if (is_array($rawItems)) {
            foreach ($rawItems as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $norm = self::normalizeItem($item);
                if ($norm !== null) {
                    $items[] = $norm;
                }
            }
        }

        return ['name' => $name, 'items' => $items];
    }

    /**
     * @param array<string, mixed> $item
     *
     * @return array<string, mixed>|null
     */
    private static function normalizeItem(array $item): ?array
    {
        $type = self::sanitizeSlug((string) ($item['type'] ?? 'custom'));
        if ($type === '') {
            $type = 'custom';
        }
        if (!in_array($type, self::allowedItemTypes(), true)) {
            $type = 'custom';
        }

        $title = isset($item['title']) ? trim((string) $item['title']) : '';
        $url = isset($item['url']) ? trim((string) $item['url']) : '';
        $objectId = max(0, (int) ($item['object_id'] ?? 0));
        $target = isset($item['target']) && (string) $item['target'] === '_blank' ? '_blank' : '';

        // Dynamic useful links need only a type (title optional override).
        if (in_array($type, self::usefulLinkTypes(), true)) {
            return [
                'type' => $type,
                'title' => $title,
                'url' => '',
                'object_id' => 0,
                'target' => $target,
                'classes' => [],
            ];
        }

        if ($type === 'custom' && $url === '' && $title === '') {
            return null;
        }
        if (in_array($type, ['page', 'post', 'category', 'post_tag', 'tag', 'forum'], true) && $objectId < 1) {
            return null;
        }

        return [
            'type' => $type,
            'title' => $title,
            'url' => $url,
            'object_id' => $objectId,
            'target' => $target,
            'classes' => [],
        ];
    }

    /**
     * Privacy policy public URL when a published page is configured.
     */
    private static function privacyPolicyUrl(?AP_DB $db): string
    {
        if (function_exists('ap_get_privacy_policy_url')) {
            try {
                $url = ap_get_privacy_policy_url($db);

                return is_string($url) ? $url : '';
            } catch (Throwable) {
                // fall through
            }
        }
        if (class_exists('AP_Privacy', false)) {
            try {
                return (string) AP_Privacy::getPrivacyPolicyUrl($db);
            } catch (Throwable) {
                return '';
            }
        }

        return '';
    }

    /**
     * Login URL when logged out; account/profile URL when logged in.
     */
    private static function loginOrAccountUrl(?AP_DB $db): string
    {
        if (self::isUserLoggedIn($db)) {
            return self::accountUrl($db);
        }

        return self::loginUrl($db);
    }

    private static function loginUrl(?AP_DB $db): string
    {
        if (class_exists('AP_Registration', false) && method_exists('AP_Registration', 'loginActionUrl')) {
            try {
                $url = AP_Registration::loginActionUrl('login', [], $db);
                if (is_string($url) && $url !== '') {
                    return $url;
                }
            } catch (Throwable) {
                // fall through
            }
        }
        if (class_exists('AP_Admin', false) && method_exists('AP_Admin', 'url')) {
            try {
                return (string) AP_Admin::url('login.php');
            } catch (Throwable) {
                // fall through
            }
        }

        return self::adminPathUrl('login.php', $db);
    }

    private static function accountUrl(?AP_DB $db): string
    {
        if (class_exists('AP_Admin', false) && method_exists('AP_Admin', 'url')) {
            try {
                return (string) AP_Admin::url('profile.php');
            } catch (Throwable) {
                // fall through
            }
        }

        return self::adminPathUrl('profile.php', $db);
    }

    private static function registerUrl(?AP_DB $db): string
    {
        if (!self::usersCanRegister($db)) {
            return '';
        }
        if (class_exists('AP_Registration', false) && method_exists('AP_Registration', 'loginActionUrl')) {
            try {
                $url = AP_Registration::loginActionUrl('register', [], $db);
                if (is_string($url) && $url !== '') {
                    return $url;
                }
            } catch (Throwable) {
                // fall through
            }
        }
        if (class_exists('AP_Admin', false) && method_exists('AP_Admin', 'url')) {
            try {
                return (string) AP_Admin::url('login.php', ['action' => 'register']);
            } catch (Throwable) {
                // fall through
            }
        }

        $base = self::adminPathUrl('login.php', $db);
        if ($base === '') {
            return '';
        }

        return $base . (str_contains($base, '?') ? '&' : '?') . 'action=register';
    }

    /**
     * Build /ap-admin/{file} absolute or relative URL without requiring AP_Admin.
     */
    private static function adminPathUrl(string $file, ?AP_DB $db): string
    {
        $file = ltrim($file, '/');
        if (class_exists('AP_Rewrite', false) && method_exists('AP_Rewrite', 'siteUrl')) {
            try {
                $site = AP_Rewrite::siteUrl('ap-admin/' . $file, $db);
                if (is_string($site) && $site !== '') {
                    return $site;
                }
            } catch (Throwable) {
                // fall through
            }
        }

        $site = '';
        if (class_exists('AP_Options', false)) {
            try {
                $site = (string) AP_Options::get('siteurl', '', $db);
                if ($site === '') {
                    $site = (string) AP_Options::get('home', '', $db);
                }
            } catch (Throwable) {
                $site = '';
            }
        }
        if ($site === '' && defined('AP_SITEURL') && is_string(AP_SITEURL) && AP_SITEURL !== '') {
            $site = (string) AP_SITEURL;
        }

        return $site !== ''
            ? rtrim($site, '/') . '/ap-admin/' . $file
            : '/ap-admin/' . $file;
    }

    private static function isUserLoggedIn(?AP_DB $db): bool
    {
        if (function_exists('ap_is_user_logged_in')) {
            try {
                return (bool) ap_is_user_logged_in($db);
            } catch (Throwable) {
                return false;
            }
        }
        if (class_exists('AP_Session', false) && method_exists('AP_Session', 'isLoggedIn')) {
            try {
                return (bool) AP_Session::isLoggedIn($db);
            } catch (Throwable) {
                return false;
            }
        }

        return false;
    }

    private static function usersCanRegister(?AP_DB $db): bool
    {
        if (function_exists('ap_users_can_register')) {
            try {
                return (bool) ap_users_can_register($db);
            } catch (Throwable) {
                // fall through
            }
        }
        if (class_exists('AP_Options', false)) {
            try {
                $raw = AP_Options::get('users_can_register', '0', $db);

                return $raw === '1' || $raw === 1 || $raw === true;
            } catch (Throwable) {
                return false;
            }
        }

        return false;
    }

    private static function forumLink(int $id, ?AP_DB $db): string
    {
        if ($id < 1 || !class_exists('AP_Forum', false)) {
            return '';
        }
        $forum = AP_Forum::getForum($id, $db);
        if (!is_object($forum)) {
            return '';
        }
        if (method_exists('AP_Forum', 'forumUrl')) {
            return (string) AP_Forum::forumUrl($forum);
        }

        return '?ap_forum_view=forum&forum_id=' . $id;
    }

    private static function objectPermalink(int $id, string $type, ?AP_DB $db): string
    {
        if ($id < 1 || !class_exists('AP_Post', false)) {
            return '';
        }
        $post = AP_Post::get($id, $db);
        if (!$post instanceof AP_Post) {
            return '';
        }
        if ($post->post_type !== $type) {
            return '';
        }
        // Only published (public) content gets a public menu link.
        $public = method_exists($post, 'isPubliclyViewable')
            ? $post->isPubliclyViewable()
            : AP_Post::isPublicStatus((string) $post->post_status);
        if (!$public) {
            return '';
        }
        if (function_exists('ap_get_permalink') && class_exists('AP_Rewrite', false)) {
            return ap_get_permalink($post, $db);
        }

        return $type === 'page' ? '?page_id=' . $id : '?p=' . $id;
    }

    private static function termLink(int $termId, string $taxonomy, ?AP_DB $db): string
    {
        if ($termId < 1 || !class_exists('AP_Taxonomy', false)) {
            return '';
        }
        $term = AP_Taxonomy::getTerm($termId, $taxonomy, $db);
        if (!is_object($term)) {
            return '';
        }
        if (function_exists('ap_get_term_link') && class_exists('AP_Rewrite', false)) {
            return ap_get_term_link($term, $taxonomy, $db);
        }

        return $taxonomy === 'category' ? '?cat=' . $termId : '?tag_id=' . $termId;
    }

    private static function sanitizeSlug(string $slug): string
    {
        $slug = strtolower(trim($slug));
        $slug = preg_replace('/[^a-z0-9_\-]/', '', $slug) ?? '';

        return $slug;
    }
}
