<?php

/**
 * AgoraPress Widgets / modular areas.
 *
 * Themes register sidebars (named regions). Core and plugins register widget
 * types. Instance placements live in option `sidebars_widgets`; per-type
 * settings live in `widget_{id_base}` (multi-instance arrays, WP-style).
 *
 * Admin: ap-admin/widgets.php. Front-end: ap_dynamic_sidebar() / ap_is_active_sidebar().
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * Register, persist, and render widgets and modular areas (sidebars).
 */
class AP_Widgets
{
    /** Option: sidebar id => list of widget instance ids (e.g. text-1). */
    public const OPTION_SIDEBARS = 'sidebars_widgets';

    /** Pseudo-sidebar holding unassigned widget instances. */
    public const INACTIVE = 'ap_inactive_widgets';

    /**
     * Registered sidebars (id => args).
     *
     * @var array<string, array{
     *   id: string,
     *   name: string,
     *   description: string,
     *   class: string,
     *   before_widget: string,
     *   after_widget: string,
     *   before_title: string,
     *   after_title: string
     * }>
     */
    private static array $sidebars = [];

    /**
     * Registered widget types (id_base => definition).
     *
     * @var array<string, array{
     *   id_base: string,
     *   name: string,
     *   description: string,
     *   classname: string,
     *   defaults: array<string, mixed>,
     *   form_fields: array<string, array<string, mixed>>,
     *   render_callback: callable
     * }>
     */
    private static array $widgetTypes = [];

    private static bool $coreRegistered = false;

    /**
     * Register a modular area (sidebar).
     *
     * @param array{
     *   name?: string,
     *   description?: string,
     *   class?: string,
     *   before_widget?: string,
     *   after_widget?: string,
     *   before_title?: string,
     *   after_title?: string
     * } $args
     */
    public static function registerSidebar(string $id, array $args = []): void
    {
        $id = self::sanitizeId($id);
        if ($id === '' || $id === self::INACTIVE) {
            return;
        }

        $defaults = [
            'name' => $id,
            'description' => '',
            'class' => '',
            'before_widget' => '<section id="%1$s" class="widget %2$s">',
            'after_widget' => '</section>',
            'before_title' => '<h2 class="widget-title">',
            'after_title' => '</h2>',
        ];
        $merged = array_merge($defaults, $args);
        self::$sidebars[$id] = [
            'id' => $id,
            'name' => (string) $merged['name'],
            'description' => (string) $merged['description'],
            'class' => (string) $merged['class'],
            'before_widget' => (string) $merged['before_widget'],
            'after_widget' => (string) $merged['after_widget'],
            'before_title' => (string) $merged['before_title'],
            'after_title' => (string) $merged['after_title'],
        ];
    }

    /**
     * Unregister a sidebar (does not delete assigned widgets from storage).
     */
    public static function unregisterSidebar(string $id): void
    {
        $id = self::sanitizeId($id);
        unset(self::$sidebars[$id]);
    }

    /**
     * All registered sidebars.
     *
     * @return array<string, array<string, string>>
     */
    public static function getSidebars(): array
    {
        return self::$sidebars;
    }

    /**
     * Single registered sidebar, or null.
     *
     * @return array<string, string>|null
     */
    public static function getSidebar(string $id): ?array
    {
        $id = self::sanitizeId($id);

        return self::$sidebars[$id] ?? null;
    }

    /**
     * Whether a sidebar id is registered.
     */
    public static function isRegisteredSidebar(string $id): bool
    {
        $id = self::sanitizeId($id);

        return $id !== '' && isset(self::$sidebars[$id]);
    }

    /**
     * Register a widget type.
     *
     * @param array{
     *   name?: string,
     *   description?: string,
     *   classname?: string,
     *   defaults?: array<string, mixed>,
     *   form_fields?: array<string, array<string, mixed>>,
     *   render_callback?: callable
     * } $args
     */
    public static function registerWidget(string $idBase, array $args = []): void
    {
        $idBase = self::sanitizeId($idBase);
        if ($idBase === '') {
            return;
        }

        $render = $args['render_callback'] ?? null;
        if (!is_callable($render)) {
            return;
        }

        self::$widgetTypes[$idBase] = [
            'id_base' => $idBase,
            'name' => (string) ($args['name'] ?? $idBase),
            'description' => (string) ($args['description'] ?? ''),
            'classname' => (string) ($args['classname'] ?? 'widget_' . $idBase),
            'defaults' => is_array($args['defaults'] ?? null) ? $args['defaults'] : [],
            'form_fields' => is_array($args['form_fields'] ?? null) ? $args['form_fields'] : [],
            'render_callback' => $render,
        ];
    }

    /**
     * Unregister a widget type.
     */
    public static function unregisterWidget(string $idBase): void
    {
        $idBase = self::sanitizeId($idBase);
        unset(self::$widgetTypes[$idBase]);
    }

    /**
     * Registered widget types.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function getWidgetTypes(): array
    {
        return self::$widgetTypes;
    }

    /**
     * Single widget type, or null.
     *
     * @return array<string, mixed>|null
     */
    public static function getWidgetType(string $idBase): ?array
    {
        $idBase = self::sanitizeId($idBase);

        return self::$widgetTypes[$idBase] ?? null;
    }

    /**
     * Whether a widget type is registered.
     */
    public static function isRegisteredWidget(string $idBase): bool
    {
        $idBase = self::sanitizeId($idBase);

        return $idBase !== '' && isset(self::$widgetTypes[$idBase]);
    }

    /**
     * Register built-in widget types (idempotent).
     */
    public static function registerCore(): void
    {
        if (self::$coreRegistered) {
            return;
        }
        self::$coreRegistered = true;

        self::registerWidget('text', [
            'name' => 'Text',
            'description' => 'Arbitrary text or HTML.',
            'classname' => 'widget_text',
            'defaults' => [
                'title' => '',
                'text' => '',
            ],
            'form_fields' => [
                'title' => ['label' => 'Title', 'type' => 'text'],
                'text' => ['label' => 'Content', 'type' => 'textarea'],
            ],
            'render_callback' => [self::class, 'renderTextWidget'],
        ]);

        self::registerWidget('recent_posts', [
            'name' => 'Recent Posts',
            'description' => 'Your site’s most recent posts.',
            'classname' => 'widget_recent_posts',
            'defaults' => [
                'title' => 'Recent Posts',
                'number' => 5,
            ],
            'form_fields' => [
                'title' => ['label' => 'Title', 'type' => 'text'],
                'number' => ['label' => 'Number of posts', 'type' => 'number', 'min' => 1, 'max' => 20],
            ],
            'render_callback' => [self::class, 'renderRecentPostsWidget'],
        ]);

        self::registerWidget('categories', [
            'name' => 'Categories',
            'description' => 'A list of categories.',
            'classname' => 'widget_categories',
            'defaults' => [
                'title' => 'Categories',
                'count' => 0,
            ],
            'form_fields' => [
                'title' => ['label' => 'Title', 'type' => 'text'],
                'count' => ['label' => 'Show post counts', 'type' => 'checkbox'],
            ],
            'render_callback' => [self::class, 'renderCategoriesWidget'],
        ]);

        self::registerWidget('search', [
            'name' => 'Search',
            'description' => 'A search form for your site.',
            'classname' => 'widget_search',
            'defaults' => [
                'title' => 'Search',
            ],
            'form_fields' => [
                'title' => ['label' => 'Title', 'type' => 'text'],
            ],
            'render_callback' => [self::class, 'renderSearchWidget'],
        ]);

        self::registerWidget('pages', [
            'name' => 'Pages',
            'description' => 'A list of your site’s Pages.',
            'classname' => 'widget_pages',
            'defaults' => [
                'title' => 'Pages',
            ],
            'form_fields' => [
                'title' => ['label' => 'Title', 'type' => 'text'],
            ],
            'render_callback' => [self::class, 'renderPagesWidget'],
        ]);

        self::registerWidget('nav_menu', [
            'name' => 'Navigation Menu',
            'description' => 'Add a navigation menu to a modular area.',
            'classname' => 'widget_nav_menu',
            'defaults' => [
                'title' => '',
                'menu' => '',
            ],
            'form_fields' => [
                'title' => ['label' => 'Title', 'type' => 'text'],
                'menu' => [
                    'label' => 'Select Menu',
                    'type' => 'select',
                    'options_callback' => [self::class, 'navMenuSelectOptions'],
                ],
            ],
            'render_callback' => [self::class, 'renderNavMenuWidget'],
        ]);
    }

    /**
     * Map of sidebar id => ordered widget instance ids.
     *
     * @return array<string, list<string>>
     */
    public static function getSidebarsWidgets(?AP_DB $db = null): array
    {
        $raw = AP_Options::get(self::OPTION_SIDEBARS, [], $db);
        if (!is_array($raw)) {
            return [self::INACTIVE => []];
        }

        $out = [];
        foreach ($raw as $sidebarId => $widgets) {
            if (!is_string($sidebarId) || !is_array($widgets)) {
                continue;
            }
            $sidebarId = self::sanitizeId($sidebarId);
            if ($sidebarId === '') {
                continue;
            }
            $list = [];
            foreach ($widgets as $wid) {
                if (!is_string($wid) && !is_int($wid)) {
                    continue;
                }
                $wid = self::sanitizeWidgetId((string) $wid);
                if ($wid !== '') {
                    $list[] = $wid;
                }
            }
            $out[$sidebarId] = $list;
        }

        if (!isset($out[self::INACTIVE])) {
            $out[self::INACTIVE] = [];
        }

        return $out;
    }

    /**
     * Persist full sidebars_widgets map.
     *
     * @param array<string, list<string>> $map
     */
    public static function setSidebarsWidgets(array $map, ?AP_DB $db = null): bool
    {
        $clean = [];
        foreach ($map as $sidebarId => $widgets) {
            if (!is_string($sidebarId) || !is_array($widgets)) {
                continue;
            }
            $sidebarId = self::sanitizeId($sidebarId);
            if ($sidebarId === '') {
                continue;
            }
            $list = [];
            foreach ($widgets as $wid) {
                if (!is_string($wid) && !is_int($wid)) {
                    continue;
                }
                $wid = self::sanitizeWidgetId((string) $wid);
                if ($wid !== '') {
                    $list[] = $wid;
                }
            }
            $clean[$sidebarId] = $list;
        }
        if (!isset($clean[self::INACTIVE])) {
            $clean[self::INACTIVE] = [];
        }

        return AP_Options::update(self::OPTION_SIDEBARS, $clean, $db);
    }

    /**
     * Widget instance ids assigned to a sidebar (empty list if none).
     *
     * @return list<string>
     */
    public static function getWidgetsForSidebar(string $sidebarId, ?AP_DB $db = null): array
    {
        $sidebarId = self::sanitizeId($sidebarId);
        $map = self::getSidebarsWidgets($db);

        return $map[$sidebarId] ?? [];
    }

    /**
     * Whether a registered sidebar has at least one assigned widget.
     */
    public static function isActiveSidebar(string $sidebarId, ?AP_DB $db = null): bool
    {
        $sidebarId = self::sanitizeId($sidebarId);
        if ($sidebarId === '' || !isset(self::$sidebars[$sidebarId])) {
            return false;
        }
        $widgets = self::getWidgetsForSidebar($sidebarId, $db);
        foreach ($widgets as $wid) {
            $parsed = self::parseWidgetId($wid);
            if ($parsed !== null && isset(self::$widgetTypes[$parsed['id_base']])) {
                return true;
            }
        }

        return false;
    }

    /**
     * All multi-instance settings for a widget type.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getWidgetSettings(string $idBase, ?AP_DB $db = null): array
    {
        $idBase = self::sanitizeId($idBase);
        if ($idBase === '') {
            return [];
        }
        $raw = AP_Options::get(self::settingsOptionName($idBase), [], $db);
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $num => $inst) {
            if ($num === '_multiwidget' || !is_array($inst)) {
                continue;
            }
            $n = (int) $num;
            if ($n < 1) {
                continue;
            }
            $out[$n] = $inst;
        }

        return $out;
    }

    /**
     * Persist multi-instance settings for a widget type.
     *
     * @param array<int, array<string, mixed>> $instances
     */
    public static function setWidgetSettings(string $idBase, array $instances, ?AP_DB $db = null): bool
    {
        $idBase = self::sanitizeId($idBase);
        if ($idBase === '') {
            return false;
        }
        $clean = ['_multiwidget' => 1];
        foreach ($instances as $num => $inst) {
            $n = (int) $num;
            if ($n < 1 || !is_array($inst)) {
                continue;
            }
            $clean[$n] = $inst;
        }

        return AP_Options::update(self::settingsOptionName($idBase), $clean, $db);
    }

    /**
     * Settings for one widget instance (merged with type defaults).
     *
     * @return array<string, mixed>
     */
    public static function getInstance(string $widgetId, ?AP_DB $db = null): array
    {
        $parsed = self::parseWidgetId($widgetId);
        if ($parsed === null) {
            return [];
        }
        $type = self::getWidgetType($parsed['id_base']);
        $defaults = is_array($type['defaults'] ?? null) ? $type['defaults'] : [];
        $all = self::getWidgetSettings($parsed['id_base'], $db);
        $inst = $all[$parsed['number']] ?? [];

        return array_merge($defaults, is_array($inst) ? $inst : []);
    }

    /**
     * Save settings for one widget instance.
     *
     * @param array<string, mixed> $instance
     */
    public static function saveInstance(string $widgetId, array $instance, ?AP_DB $db = null): bool
    {
        $parsed = self::parseWidgetId($widgetId);
        if ($parsed === null) {
            return false;
        }
        $type = self::getWidgetType($parsed['id_base']);
        if ($type === null) {
            return false;
        }
        $sanitized = self::sanitizeInstance($parsed['id_base'], $instance, $type);
        $all = self::getWidgetSettings($parsed['id_base'], $db);
        $all[$parsed['number']] = $sanitized;

        return self::setWidgetSettings($parsed['id_base'], $all, $db);
    }

    /**
     * Create a new instance of a widget type and append it to a sidebar (or inactive).
     *
     * @param array<string, mixed> $instance
     *
     * @return string|false New widget id (e.g. text-2) or false on failure
     */
    public static function addWidget(string $idBase, string $sidebarId = self::INACTIVE, array $instance = [], ?AP_DB $db = null): string|false
    {
        $idBase = self::sanitizeId($idBase);
        $sidebarId = self::sanitizeId($sidebarId);
        if ($idBase === '' || !isset(self::$widgetTypes[$idBase])) {
            return false;
        }
        if ($sidebarId === '') {
            $sidebarId = self::INACTIVE;
        }

        $number = self::nextInstanceNumber($idBase, $db);
        $widgetId = $idBase . '-' . $number;
        $type = self::$widgetTypes[$idBase];
        $merged = array_merge(
            is_array($type['defaults']) ? $type['defaults'] : [],
            $instance
        );
        if (!self::saveInstance($widgetId, $merged, $db)) {
            return false;
        }

        $map = self::getSidebarsWidgets($db);
        if (!isset($map[$sidebarId])) {
            $map[$sidebarId] = [];
        }
        $map[$sidebarId][] = $widgetId;
        if (!self::setSidebarsWidgets($map, $db)) {
            return false;
        }

        return $widgetId;
    }

    /**
     * Remove a widget instance from all sidebars and delete its settings.
     */
    public static function removeWidget(string $widgetId, ?AP_DB $db = null): bool
    {
        $widgetId = self::sanitizeWidgetId($widgetId);
        $parsed = self::parseWidgetId($widgetId);
        if ($parsed === null) {
            return false;
        }

        $map = self::getSidebarsWidgets($db);
        $changed = false;
        foreach ($map as $sid => $list) {
            $filtered = array_values(array_filter(
                $list,
                static fn (string $id): bool => $id !== $widgetId
            ));
            if ($filtered !== $list) {
                $map[$sid] = $filtered;
                $changed = true;
            }
        }
        if ($changed) {
            self::setSidebarsWidgets($map, $db);
        }

        $all = self::getWidgetSettings($parsed['id_base'], $db);
        if (isset($all[$parsed['number']])) {
            unset($all[$parsed['number']]);
            self::setWidgetSettings($parsed['id_base'], $all, $db);
        }

        return true;
    }

    /**
     * Move a widget to another sidebar (optionally at a position).
     */
    public static function moveWidget(string $widgetId, string $toSidebar, ?int $position = null, ?AP_DB $db = null): bool
    {
        $widgetId = self::sanitizeWidgetId($widgetId);
        $toSidebar = self::sanitizeId($toSidebar);
        if ($widgetId === '' || $toSidebar === '' || self::parseWidgetId($widgetId) === null) {
            return false;
        }

        $map = self::getSidebarsWidgets($db);
        $found = false;
        foreach ($map as $sid => $list) {
            $filtered = array_values(array_filter(
                $list,
                static fn (string $id): bool => $id !== $widgetId
            ));
            if ($filtered !== $list) {
                $map[$sid] = $filtered;
                $found = true;
            }
        }
        if (!$found && !self::instanceExists($widgetId, $db)) {
            return false;
        }

        if (!isset($map[$toSidebar])) {
            $map[$toSidebar] = [];
        }
        if ($position === null || $position < 0 || $position >= count($map[$toSidebar])) {
            $map[$toSidebar][] = $widgetId;
        } else {
            array_splice($map[$toSidebar], $position, 0, [$widgetId]);
        }

        return self::setSidebarsWidgets($map, $db);
    }

    /**
     * Reorder widgets within a sidebar.
     *
     * @param list<string> $orderedIds Full ordered list of widget ids for the sidebar
     */
    public static function reorderSidebar(string $sidebarId, array $orderedIds, ?AP_DB $db = null): bool
    {
        $sidebarId = self::sanitizeId($sidebarId);
        if ($sidebarId === '') {
            return false;
        }
        $clean = [];
        foreach ($orderedIds as $wid) {
            if (!is_string($wid) && !is_int($wid)) {
                continue;
            }
            $wid = self::sanitizeWidgetId((string) $wid);
            if ($wid !== '' && self::parseWidgetId($wid) !== null) {
                $clean[] = $wid;
            }
        }
        $map = self::getSidebarsWidgets($db);
        $map[$sidebarId] = $clean;

        return self::setSidebarsWidgets($map, $db);
    }

    /**
     * Render a sidebar’s widgets to HTML.
     *
     * @param array{echo?: bool} $args
     */
    public static function dynamicSidebar(string $sidebarId, array $args = [], ?AP_DB $db = null): string
    {
        $sidebarId = self::sanitizeId($sidebarId);
        $echo = !array_key_exists('echo', $args) || !empty($args['echo']);

        if ($sidebarId === '' || !isset(self::$sidebars[$sidebarId])) {
            if ($echo) {
                echo '';
            }

            return '';
        }

        $sidebar = self::$sidebars[$sidebarId];
        $widgetIds = self::getWidgetsForSidebar($sidebarId, $db);
        if ($widgetIds === []) {
            if ($echo) {
                echo '';
            }

            return '';
        }

        $html = '';
        foreach ($widgetIds as $widgetId) {
            $html .= self::renderWidget($widgetId, $sidebar, $db);
        }

        if ($echo) {
            echo $html;
        }

        return $html;
    }

    /**
     * Render a single widget instance.
     *
     * @param array<string, string> $sidebarArgs
     */
    public static function renderWidget(string $widgetId, array $sidebarArgs = [], ?AP_DB $db = null): string
    {
        $widgetId = self::sanitizeWidgetId($widgetId);
        $parsed = self::parseWidgetId($widgetId);
        if ($parsed === null) {
            return '';
        }
        $type = self::getWidgetType($parsed['id_base']);
        if ($type === null || !is_callable($type['render_callback'])) {
            return '';
        }

        $defaults = [
            'id' => '',
            'name' => '',
            'description' => '',
            'class' => '',
            'before_widget' => '<section id="%1$s" class="widget %2$s">',
            'after_widget' => '</section>',
            'before_title' => '<h2 class="widget-title">',
            'after_title' => '</h2>',
        ];
        $sidebarArgs = array_merge($defaults, $sidebarArgs);

        $classname = (string) $type['classname'];
        $beforeWidget = sprintf(
            (string) $sidebarArgs['before_widget'],
            ap_esc_attr($widgetId),
            ap_esc_attr(trim($classname . ' ' . (string) $sidebarArgs['class']))
        );

        $instance = self::getInstance($widgetId, $db);
        $renderArgs = [
            'widget_id' => $widgetId,
            'widget_name' => (string) $type['name'],
            'id_base' => $parsed['id_base'],
            'number' => $parsed['number'],
            'before_widget' => $beforeWidget,
            'after_widget' => (string) $sidebarArgs['after_widget'],
            'before_title' => (string) $sidebarArgs['before_title'],
            'after_title' => (string) $sidebarArgs['after_title'],
            'sidebar_id' => (string) ($sidebarArgs['id'] ?? ''),
        ];

        try {
            $out = call_user_func($type['render_callback'], $instance, $renderArgs, $db);
        } catch (Throwable) {
            return '';
        }

        return is_string($out) ? $out : '';
    }

    /**
     * Parse "text-1" → id_base + number.
     *
     * @return array{id_base: string, number: int}|null
     */
    public static function parseWidgetId(string $widgetId): ?array
    {
        $widgetId = self::sanitizeWidgetId($widgetId);
        if ($widgetId === '' || !preg_match('/^([a-z0-9_]+)-([1-9][0-9]*)$/', $widgetId, $m)) {
            return null;
        }

        return [
            'id_base' => $m[1],
            'number' => (int) $m[2],
        ];
    }

    /**
     * Next free multi-widget number for a type.
     */
    public static function nextInstanceNumber(string $idBase, ?AP_DB $db = null): int
    {
        $settings = self::getWidgetSettings($idBase, $db);
        $max = 0;
        foreach (array_keys($settings) as $n) {
            $max = max($max, (int) $n);
        }
        // Also scan sidebars_widgets for orphaned ids without settings.
        $map = self::getSidebarsWidgets($db);
        foreach ($map as $list) {
            foreach ($list as $wid) {
                $parsed = self::parseWidgetId($wid);
                if ($parsed !== null && $parsed['id_base'] === $idBase) {
                    $max = max($max, $parsed['number']);
                }
            }
        }

        return $max + 1;
    }

    /**
     * Reset in-memory registries (tests).
     */
    public static function reset(): void
    {
        self::$sidebars = [];
        self::$widgetTypes = [];
        self::$coreRegistered = false;
    }

    // -------------------------------------------------------------------------
    // Built-in widget renderers
    // -------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $instance
     * @param array<string, mixed> $args
     */
    public static function renderTextWidget(array $instance, array $args, ?AP_DB $db = null): string
    {
        $title = trim((string) ($instance['title'] ?? ''));
        $text = (string) ($instance['text'] ?? '');
        $html = (string) ($args['before_widget'] ?? '');
        if ($title !== '') {
            $html .= (string) ($args['before_title'] ?? '')
                . ap_esc_html($title)
                . (string) ($args['after_title'] ?? '');
        }
        // Escape plain text with newlines; allow intentional HTML via filter later.
        $body = nl2br(ap_esc_html($text), false);
        if (function_exists('ap_apply_filters')) {
            $filtered = ap_apply_filters('ap_widget_text', $body, $instance, $args);
            if (is_string($filtered)) {
                $body = $filtered;
            }
        }
        $html .= '<div class="widget-text">' . $body . '</div>';
        $html .= (string) ($args['after_widget'] ?? '');

        return $html;
    }

    /**
     * @param array<string, mixed> $instance
     * @param array<string, mixed> $args
     */
    public static function renderRecentPostsWidget(array $instance, array $args, ?AP_DB $db = null): string
    {
        $title = trim((string) ($instance['title'] ?? 'Recent Posts'));
        $number = max(1, min(20, (int) ($instance['number'] ?? 5)));

        $posts = [];
        if (class_exists('AP_Post', false)) {
            $posts = AP_Post::query([
                'post_type' => 'post',
                'post_status' => 'publish',
                'limit' => $number,
                'orderby' => 'post_date',
                'order' => 'DESC',
            ], $db);
        }

        $html = (string) ($args['before_widget'] ?? '');
        if ($title !== '') {
            $html .= (string) ($args['before_title'] ?? '')
                . ap_esc_html($title)
                . (string) ($args['after_title'] ?? '');
        }
        $html .= '<ul class="widget-recent-posts">';
        if ($posts === []) {
            $html .= '<li class="widget-empty">No posts yet.</li>';
        } else {
            foreach ($posts as $post) {
                if (!$post instanceof AP_Post) {
                    continue;
                }
                $url = self::postPermalink($post, $db);
                $html .= '<li><a href="' . ap_esc_url($url) . '">'
                    . ap_esc_html((string) $post->post_title)
                    . '</a></li>';
            }
        }
        $html .= '</ul>';
        $html .= (string) ($args['after_widget'] ?? '');

        return $html;
    }

    /**
     * @param array<string, mixed> $instance
     * @param array<string, mixed> $args
     */
    public static function renderCategoriesWidget(array $instance, array $args, ?AP_DB $db = null): string
    {
        $title = trim((string) ($instance['title'] ?? 'Categories'));
        $showCount = !empty($instance['count']);

        $terms = [];
        if (class_exists('AP_Taxonomy', false)) {
            $terms = AP_Taxonomy::getTerms('category', [
                'hide_empty' => false,
                'number' => 100,
            ], $db);
        }

        $html = (string) ($args['before_widget'] ?? '');
        if ($title !== '') {
            $html .= (string) ($args['before_title'] ?? '')
                . ap_esc_html($title)
                . (string) ($args['after_title'] ?? '');
        }
        $html .= '<ul class="widget-categories">';
        if ($terms === []) {
            $html .= '<li class="widget-empty">No categories.</li>';
        } else {
            foreach ($terms as $term) {
                if (!is_object($term)) {
                    continue;
                }
                $name = (string) ($term->name ?? '');
                $count = (int) ($term->count ?? 0);
                $termId = (int) ($term->term_id ?? 0);
                $slug = (string) ($term->slug ?? '');
                $url = $slug !== '' ? '?category_name=' . rawurlencode($slug) : '#';
                if ($termId > 0 && class_exists('AP_Rewrite', false)) {
                    try {
                        if (function_exists('ap_get_term_link')) {
                            $linked = (string) ap_get_term_link($term, 'category', $db);
                        } else {
                            $linked = (string) AP_Rewrite::getTermLink($term, 'category', $db);
                        }
                        if ($linked !== '') {
                            $url = $linked;
                        }
                    } catch (Throwable) {
                        // keep plain category_name link
                    }
                }
                $label = ap_esc_html($name);
                if ($showCount) {
                    $label .= ' <span class="count">(' . $count . ')</span>';
                }
                $html .= '<li><a href="' . ap_esc_url($url) . '">' . $label . '</a></li>';
            }
        }
        $html .= '</ul>';
        $html .= (string) ($args['after_widget'] ?? '');

        return $html;
    }

    /**
     * @param array<string, mixed> $instance
     * @param array<string, mixed> $args
     */
    public static function renderSearchWidget(array $instance, array $args, ?AP_DB $db = null): string
    {
        $title = trim((string) ($instance['title'] ?? 'Search'));
        $home = function_exists('ap_home_url') ? ap_home_url('/', $db) : '/';

        $html = (string) ($args['before_widget'] ?? '');
        if ($title !== '') {
            $html .= (string) ($args['before_title'] ?? '')
                . ap_esc_html($title)
                . (string) ($args['after_title'] ?? '');
        }
        $html .= '<form role="search" method="get" class="widget-search-form" action="'
            . ap_esc_url($home) . '">';
        $html .= '<label class="screen-reader-text" for="ap-widget-search-'
            . ap_esc_attr((string) ($args['widget_id'] ?? 's')) . '">Search</label>';
        $html .= '<input type="search" id="ap-widget-search-'
            . ap_esc_attr((string) ($args['widget_id'] ?? 's'))
            . '" name="s" value="" placeholder="Search…" required>';
        $html .= '<button type="submit">Search</button>';
        $html .= '</form>';
        $html .= (string) ($args['after_widget'] ?? '');

        return $html;
    }

    /**
     * @param array<string, mixed> $instance
     * @param array<string, mixed> $args
     */
    public static function renderPagesWidget(array $instance, array $args, ?AP_DB $db = null): string
    {
        $title = trim((string) ($instance['title'] ?? 'Pages'));

        $pages = [];
        if (class_exists('AP_Post', false)) {
            $pages = AP_Post::query([
                'post_type' => 'page',
                'post_status' => 'publish',
                'limit' => 50,
                'orderby' => 'menu_order',
                'order' => 'ASC',
            ], $db);
            // Secondary sort by title when menu_order ties — already ASC by menu_order.
            if ($pages === []) {
                $pages = AP_Post::query([
                    'post_type' => 'page',
                    'post_status' => 'publish',
                    'limit' => 50,
                    'orderby' => 'post_title',
                    'order' => 'ASC',
                ], $db);
            }
        }

        $html = (string) ($args['before_widget'] ?? '');
        if ($title !== '') {
            $html .= (string) ($args['before_title'] ?? '')
                . ap_esc_html($title)
                . (string) ($args['after_title'] ?? '');
        }
        $html .= '<ul class="widget-pages">';
        if ($pages === []) {
            $html .= '<li class="widget-empty">No pages.</li>';
        } else {
            foreach ($pages as $page) {
                if (!$page instanceof AP_Post) {
                    continue;
                }
                $url = self::postPermalink($page, $db);
                $html .= '<li><a href="' . ap_esc_url($url) . '">'
                    . ap_esc_html((string) $page->post_title)
                    . '</a></li>';
            }
        }
        $html .= '</ul>';
        $html .= (string) ($args['after_widget'] ?? '');

        return $html;
    }

    /**
     * @param array<string, mixed> $instance
     * @param array<string, mixed> $args
     */
    public static function renderNavMenuWidget(array $instance, array $args, ?AP_DB $db = null): string
    {
        $title = trim((string) ($instance['title'] ?? ''));
        $menuSlug = self::sanitizeId((string) ($instance['menu'] ?? ''));

        $menuHtml = '';
        if ($menuSlug !== '' && class_exists('AP_Nav_Menu', false)) {
            $menuHtml = AP_Nav_Menu::render([
                'menu' => $menuSlug,
                'container' => 'nav',
                'container_class' => 'ap-nav ap-nav--widget',
                'menu_class' => 'ap-menu ap-menu--widget',
                'echo' => false,
            ], $db);
        }

        $html = (string) ($args['before_widget'] ?? '');
        if ($title !== '') {
            $html .= (string) ($args['before_title'] ?? '')
                . ap_esc_html($title)
                . (string) ($args['after_title'] ?? '');
        }
        if ($menuHtml === '') {
            $html .= '<p class="widget-empty">Select a menu in the widget settings.</p>';
        } else {
            $html .= $menuHtml;
        }
        $html .= (string) ($args['after_widget'] ?? '');

        return $html;
    }

    /**
     * Options for the nav_menu widget select field.
     *
     * @return array<string, string>
     */
    public static function navMenuSelectOptions(?AP_DB $db = null): array
    {
        $options = ['' => '— Select —'];
        if (!class_exists('AP_Nav_Menu', false)) {
            return $options;
        }
        foreach (AP_Nav_Menu::getMenus($db) as $slug => $menu) {
            $options[$slug] = (string) ($menu['name'] ?? $slug);
        }

        return $options;
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * Safe public URL for a post/page (works when rewrite layer is not loaded).
     */
    private static function postPermalink(AP_Post $post, ?AP_DB $db = null): string
    {
        if (class_exists('AP_Rewrite', false) && function_exists('ap_get_permalink')) {
            try {
                $url = (string) ap_get_permalink($post, $db);

                return $url !== '' ? $url : self::plainPostLink($post);
            } catch (Throwable) {
                return self::plainPostLink($post);
            }
        }
        if (class_exists('AP_Rewrite', false)) {
            try {
                $url = (string) AP_Rewrite::getPermalink($post, $db);

                return $url !== '' ? $url : self::plainPostLink($post);
            } catch (Throwable) {
                return self::plainPostLink($post);
            }
        }

        return self::plainPostLink($post);
    }

    private static function plainPostLink(AP_Post $post): string
    {
        if ($post->post_type === 'page') {
            return '?page_id=' . (int) $post->ID;
        }

        return '?p=' . (int) $post->ID;
    }

    /**
     * Option name for multi-widget settings.
     */
    public static function settingsOptionName(string $idBase): string
    {
        return 'widget_' . self::sanitizeId($idBase);
    }

    /**
     * Sanitize a sidebar / id_base slug.
     */
    public static function sanitizeId(string $id): string
    {
        $id = strtolower(trim($id));
        $id = preg_replace('/[^a-z0-9_\-]/', '', $id) ?? '';

        return $id;
    }

    /**
     * Sanitize a full widget instance id (id_base-number).
     */
    public static function sanitizeWidgetId(string $id): string
    {
        $id = strtolower(trim($id));
        $id = preg_replace('/[^a-z0-9_\-]/', '', $id) ?? '';

        return $id;
    }

    /**
     * @param array<string, mixed> $instance
     * @param array<string, mixed> $type
     *
     * @return array<string, mixed>
     */
    private static function sanitizeInstance(string $idBase, array $instance, array $type): array
    {
        $defaults = is_array($type['defaults'] ?? null) ? $type['defaults'] : [];
        $fields = is_array($type['form_fields'] ?? null) ? $type['form_fields'] : [];
        $out = $defaults;

        foreach ($fields as $key => $field) {
            if (!is_string($key) || !is_array($field)) {
                continue;
            }
            $fieldType = (string) ($field['type'] ?? 'text');
            $raw = $instance[$key] ?? ($defaults[$key] ?? '');
            $out[$key] = match ($fieldType) {
                'checkbox' => !empty($raw) && $raw !== '0' && $raw !== 0 ? 1 : 0,
                'number' => (int) $raw,
                'textarea' => is_string($raw) || is_numeric($raw)
                    ? (function_exists('ap_sanitize_text_field')
                        ? self::sanitizeMultiline((string) $raw)
                        : self::sanitizeMultiline((string) $raw))
                    : '',
                default => function_exists('ap_sanitize_text_field')
                    ? ap_sanitize_text_field(is_scalar($raw) ? (string) $raw : '')
                    : trim(strip_tags(is_scalar($raw) ? (string) $raw : '')),
            };
            if ($fieldType === 'number') {
                $min = isset($field['min']) ? (int) $field['min'] : null;
                $max = isset($field['max']) ? (int) $field['max'] : null;
                if ($min !== null) {
                    $out[$key] = max($min, (int) $out[$key]);
                }
                if ($max !== null) {
                    $out[$key] = min($max, (int) $out[$key]);
                }
            }
        }

        // Preserve unknown keys from defaults only; drop arbitrary input.
        return $out;
    }

    private static function sanitizeMultiline(string $text): string
    {
        $text = str_replace("\0", '', $text);
        // Allow newlines; strip tags for safety in MVP (HTML widgets can use filter).
        return trim(strip_tags($text));
    }

    private static function instanceExists(string $widgetId, ?AP_DB $db): bool
    {
        $parsed = self::parseWidgetId($widgetId);
        if ($parsed === null) {
            return false;
        }
        $all = self::getWidgetSettings($parsed['id_base'], $db);

        return isset($all[$parsed['number']]);
    }
}
