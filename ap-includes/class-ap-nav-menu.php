<?php

/**
 * AgoraPress navigation menus.
 *
 * Themes register locations; menus and items are stored as site options
 * (JSON). Items may point at pages, posts, categories/tags, or custom URLs.
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
        if ($menu === null || $menu['items'] === []) {
            $html = '';
            if (is_callable($args['fallback_cb'])) {
                $html = (string) call_user_func($args['fallback_cb'], $args, $db);
            }
            if (!empty($args['echo'])) {
                echo $html;
            }

            return $html;
        }

        $itemsHtml = self::renderItems($menu['items'], $db);
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

        return (string) ($item['url'] ?? 'Link');
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
            $url = self::itemUrl($item, $db);
            $title = self::itemTitle($item, $db);
            if ($title === '') {
                continue;
            }
            $target = !empty($item['target']) ? (string) $item['target'] : '';
            $classes = ['menu-item'];
            $type = (string) ($item['type'] ?? 'custom');
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
        $allowed = ['custom', 'page', 'post', 'category', 'post_tag', 'tag'];
        if (!in_array($type, $allowed, true)) {
            $type = 'custom';
        }

        $title = isset($item['title']) ? trim((string) $item['title']) : '';
        $url = isset($item['url']) ? trim((string) $item['url']) : '';
        $objectId = max(0, (int) ($item['object_id'] ?? 0));
        $target = isset($item['target']) && (string) $item['target'] === '_blank' ? '_blank' : '';

        if ($type === 'custom' && $url === '' && $title === '') {
            return null;
        }
        if (in_array($type, ['page', 'post', 'category', 'post_tag', 'tag'], true) && $objectId < 1) {
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

    private static function objectPermalink(int $id, string $type, ?AP_DB $db): string
    {
        if ($id < 1 || !class_exists('AP_Post', false)) {
            return '';
        }
        $post = AP_Post::get($id, $db);
        if (!$post instanceof AP_Post) {
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
