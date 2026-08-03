<?php

/**
 * Appearance — Menus.
 *
 * Create a menu, add pages / posts / categories / forums / custom links,
 * reorder and remove items, assign to theme locations (Primary, Footer, …).
 * Manage Locations assigns any registered theme location independently.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

require_once __DIR__ . '/admin-bootstrap.php';

AP_Admin::requireCapability('edit_theme_options');

AP_Admin::consumeQueryNotice();

// Load theme so locations registered in functions.php are available.
if (class_exists('AP_Theme', false)) {
    AP_Theme::setup(ap_db());
}

$userId = ap_get_current_user_id();
$db = ap_db();

$locations = AP_Nav_Menu::getRegisteredLocations();
// Always offer sensible defaults when the theme has not registered any yet.
if ($locations === []) {
    $locations = [
        'primary' => 'Primary',
        'footer' => 'Footer',
    ];
    AP_Nav_Menu::registerLocations($locations);
}

$menus = AP_Nav_Menu::getMenus($db);
$assignments = AP_Nav_Menu::getLocationAssignments($db);

// Screen tab: edit menus (default) or manage theme locations.
$screenTab = isset($_GET['tab']) ? strtolower((string) $_GET['tab']) : 'edit';
if (!in_array($screenTab, ['edit', 'locations'], true)) {
    $screenTab = 'edit';}

// Which menu is being edited?
$editSlug = isset($_GET['menu']) ? preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $_GET['menu'])) ?? '' : '';
if ($editSlug === '' && $menus !== []) {
    $editSlug = (string) array_key_first($menus);
}
$currentMenu = $editSlug !== '' ? ($menus[$editSlug] ?? null) : null;

// Module-aware add panels.
$showPages = !function_exists('ap_is_module_enabled') || ap_is_module_enabled('static_pages', $db);
$showPosts = !function_exists('ap_is_module_enabled') || ap_is_module_enabled('blog', $db);
$showForums = !function_exists('ap_is_module_enabled') || ap_is_module_enabled('forum', $db);

// Human-readable notes for well-known locations (themes may register more).
$locationHelp = [
    'primary' => 'Site header / main navigation (default Agora theme).',
    'footer' => 'Site footer navigation (default Agora theme).',
];

// --- Actions ---
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $nonce = (string) ($_POST['_ap_nonce'] ?? '');
    $action = (string) ($_POST['ap_menu_action'] ?? '');

    if (!ap_check_nonce($nonce, 'ap_nav_menus', $userId > 0 ? $userId : null)) {
        AP_Admin::addNotice('Security check failed. Please try again.', 'error');
    } elseif ($action === 'create') {
        $name = ap_sanitize_text_field((string) ($_POST['menu_name'] ?? ''));
        if ($name === '') {
            AP_Admin::addNotice('Please enter a menu name.', 'error');
        } else {
            $slug = strtolower(preg_replace('/[^a-z0-9]+/', '-', $name) ?? '');
            $slug = trim($slug, '-') ?: 'menu';
            // Ensure uniqueness.
            $base = $slug;
            $i = 2;
            while (isset($menus[$slug])) {
                $slug = $base . '-' . $i;
                $i++;
            }
            if (AP_Nav_Menu::saveMenu($slug, $name, [], $db)) {
                AP_Admin::redirect(AP_Admin::url('nav-menus.php', ['menu' => $slug, 'message' => 'menu_created']));
            }
            AP_Admin::addNotice('Could not create the menu.', 'error');
        }
    } elseif ($action === 'save_locations') {
        $locMap = AP_Nav_Menu::locationsFromAdminPost($_POST, $locations);
        // Drop assignments pointing at deleted menus.
        foreach ($locMap as $loc => $menuSlug) {
            if (!isset($menus[$menuSlug])) {
                unset($locMap[$loc]);
            }
        }
        if (AP_Nav_Menu::setLocationAssignments($locMap, $db)) {
            AP_Admin::redirect(AP_Admin::url('nav-menus.php', [
                'tab' => 'locations',
                'message' => 'menu_locations_saved',
            ]));
        }
        AP_Admin::addNotice('Could not save menu locations.', 'error');
    } elseif ($action === 'save' && $editSlug !== '') {
        $name = ap_sanitize_text_field((string) ($_POST['menu_name'] ?? ''));
        $items = AP_Nav_Menu::itemsFromAdminPost($_POST);
        // Sanitize titles/urls for storage (object IDs already cast in helper).
        foreach ($items as $k => $item) {
            if (!is_array($item)) {
                unset($items[$k]);
                continue;
            }
            $items[$k]['title'] = ap_sanitize_text_field((string) ($item['title'] ?? ''));
            $items[$k]['url'] = ap_sanitize_text_field((string) ($item['url'] ?? ''));
            $items[$k]['type'] = ap_sanitize_text_field((string) ($item['type'] ?? 'custom'));
            $items[$k]['object_id'] = (int) ($item['object_id'] ?? 0);
        }
        $items = array_values($items);

        if (AP_Nav_Menu::saveMenu($editSlug, $name !== '' ? $name : $editSlug, $items, $db)) {
            $locMap = AP_Nav_Menu::mergeMenuLocationCheckboxes(
                $assignments,
                $locations,
                $editSlug,
                $_POST
            );
            AP_Nav_Menu::setLocationAssignments($locMap, $db);
            AP_Admin::redirect(AP_Admin::url('nav-menus.php', ['menu' => $editSlug, 'message' => 'menu_saved']));
        }
        AP_Admin::addNotice('Could not save the menu.', 'error');
    } elseif ($action === 'move' && $editSlug !== '') {
        $index = (int) ($_POST['item_index'] ?? -1);
        $direction = (string) ($_POST['direction'] ?? '');
        if (AP_Nav_Menu::moveItem($editSlug, $index, $direction, $db)) {
            AP_Admin::redirect(AP_Admin::url('nav-menus.php', ['menu' => $editSlug, 'message' => 'menu_item_moved']));
        }
        AP_Admin::addNotice('Could not reorder that item.', 'error');
    } elseif ($action === 'remove_item' && $editSlug !== '') {
        $index = (int) ($_POST['item_index'] ?? -1);
        if (AP_Nav_Menu::removeItem($editSlug, $index, $db)) {
            AP_Admin::redirect(AP_Admin::url('nav-menus.php', ['menu' => $editSlug, 'message' => 'menu_item_removed']));
        }
        AP_Admin::addNotice('Could not remove that item.', 'error');
    } elseif ($action === 'delete' && $editSlug !== '') {
        if (AP_Nav_Menu::deleteMenu($editSlug, $db)) {
            AP_Admin::redirect(AP_Admin::url('nav-menus.php', ['message' => 'menu_deleted']));
        }
        AP_Admin::addNotice('Could not delete the menu.', 'error');
    }
}

// Refresh after possible mutations in same request (failed save path).
$menus = AP_Nav_Menu::getMenus($db);
$assignments = AP_Nav_Menu::getLocationAssignments($db);
$currentMenu = $editSlug !== '' ? ($menus[$editSlug] ?? null) : null;

// Candidates for "add from content".
// Pages honor the per-page “Show in navigation” flag (via getPublishedPages).
$pages = [];
if ($showPages && class_exists('AP_Nav_Menu', false)) {
    $pages = AP_Nav_Menu::getPublishedPages($db, 100);
    // Prefer title order in the picker (fallback nav uses menu_order).
    usort(
        $pages,
        static function ($a, $b): int {
            $ta = $a instanceof AP_Post ? (string) $a->post_title : '';
            $tb = $b instanceof AP_Post ? (string) $b->post_title : '';

            return strcasecmp($ta, $tb);
        }
    );
} elseif ($showPages && class_exists('AP_Post', false)) {
    $pages = AP_Post::query([
        'post_type' => 'page',
        'post_status' => 'publish',
        'limit' => 100,
        'orderby' => 'post_title',
        'order' => 'ASC',
    ], $db);
}
$posts = [];
if ($showPosts && class_exists('AP_Post', false)) {
    $posts = AP_Post::query([
        'post_type' => 'post',
        'post_status' => 'publish',
        'limit' => 50,
        'orderby' => 'post_date',
        'order' => 'DESC',
    ], $db);
}
$categories = [];
if ($showPosts && class_exists('AP_Taxonomy', false)) {
    $categories = AP_Taxonomy::getTerms('category', ['hide_empty' => false, 'number' => 100], $db);
    if (!is_array($categories)) {
        $categories = [];
    }
}
$forums = [];
if ($showForums && class_exists('AP_Forum', false)) {
    $forums = AP_Forum::getForums(['per_page' => 100], $db);
}

$ap_admin_title = 'Menus';
$ap_admin_screen = 'nav-menus';
require __DIR__ . '/admin-header.php';
?>
<div class="ap-page-header">
    <h1>Menus</h1>
</div>

<p>
    Build navigation menus and assign them to theme locations.
    The active theme registers locations such as
    <strong>Primary</strong> (header) and <strong>Footer</strong>;
    any registered location can be controlled here.
    Published <strong>Pages</strong> with <strong>Show in navigation</strong> enabled
    can be added from the Pages panel below (or appear automatically in the primary bar
    when no custom menu is assigned). Toggle that checkbox on each page’s edit screen.
</p>

<nav class="ap-menus-tabs" aria-label="Menus sections">
    <a class="ap-menus-tab<?php echo $screenTab === 'edit' ? ' is-active' : ''; ?>"
        href="<?php echo ap_esc_url(AP_Admin::url('nav-menus.php', $editSlug !== '' ? ['menu' => $editSlug] : [])); ?>">
        Edit Menus
    </a>
    <a class="ap-menus-tab<?php echo $screenTab === 'locations' ? ' is-active' : ''; ?>"
        href="<?php echo ap_esc_url(AP_Admin::url('nav-menus.php', ['tab' => 'locations'])); ?>">
        Manage Locations
    </a>
</nav>

<?php if ($screenTab === 'locations') : ?>
    <section class="ap-menus-locations-panel" aria-labelledby="ap-locations-heading">
        <h2 id="ap-locations-heading">Theme locations</h2>
        <p class="ap-help">
            Choose which menu appears in each theme location.
            Locations come from the active theme (and plugins that register more).
            Leaving a location empty uses the theme’s fallback (if any).
        </p>

        <?php if ($menus === []) : ?>
            <p>
                No menus yet.
                <a href="<?php echo ap_esc_url(AP_Admin::url('nav-menus.php')); ?>">Create a menu</a>
                first, then assign it here.
            </p>
        <?php else : ?>
            <form method="post" action="<?php echo ap_esc_url(AP_Admin::url('nav-menus.php', ['tab' => 'locations'])); ?>"
                class="ap-form" id="ap-menu-locations-form">
                <?php echo ap_nonce_field('ap_nav_menus', '_ap_nonce', false); ?>
                <input type="hidden" name="ap_menu_action" value="save_locations">

                <table class="ap-table ap-menu-locations-table">
                    <thead>
                        <tr>
                            <th scope="col">Theme location</th>
                            <th scope="col">Assigned menu</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($locations as $loc => $desc) :
                            $currentAssign = (string) ($assignments[$loc] ?? '');
                            $help = $locationHelp[$loc] ?? '';
                            ?>
                            <tr>
                                <th scope="row">
                                    <label for="menu_location_<?php echo ap_esc_attr($loc); ?>">
                                        <?php echo ap_esc_html($desc !== '' ? $desc : $loc); ?>
                                    </label>
                                    <div class="ap-help">
                                        <code><?php echo ap_esc_html($loc); ?></code>
                                        <?php if ($help !== '') : ?>
                                            — <?php echo ap_esc_html($help); ?>
                                        <?php endif; ?>
                                    </div>
                                </th>
                                <td>
                                    <select name="menu_location[<?php echo ap_esc_attr($loc); ?>]"
                                        id="menu_location_<?php echo ap_esc_attr($loc); ?>">
                                        <option value="">— Select a Menu —</option>
                                        <?php foreach ($menus as $slug => $menu) : ?>
                                            <option value="<?php echo ap_esc_attr($slug); ?>"
                                                <?php echo $currentAssign === $slug ? ' selected' : ''; ?>>
                                                <?php echo ap_esc_html($menu['name']); ?>
                                                <?php
                                                $itemCount = count($menu['items'] ?? []);
                                                echo ap_esc_html(' (' . $itemCount . ' item' . ($itemCount === 1 ? '' : 's') . ')');
                                                ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <p class="ap-form-actions">
                    <button type="submit" class="button button-primary">Save Locations</button>
                </p>
            </form>
        <?php endif; ?>
    </section>
<?php else : ?>

<div class="ap-menus-layout">
    <aside class="ap-menus-sidebar">
        <h2>Your Menus</h2>
        <ul class="ap-menu-list">
            <?php foreach ($menus as $slug => $menu) :
                $menuLocs = AP_Nav_Menu::getLocationsForMenu($slug, $db);
                ?>
                <li class="<?php echo $slug === $editSlug ? 'current' : ''; ?>">
                    <a href="<?php echo ap_esc_url(AP_Admin::url('nav-menus.php', ['menu' => $slug])); ?>">
                        <?php echo ap_esc_html($menu['name']); ?>
                        <?php if ($menuLocs !== []) : ?>
                            <span class="ap-menu-loc-badges">
                                <?php foreach ($menuLocs as $locSlug) :
                                    $locLabel = $locations[$locSlug] ?? $locSlug;
                                    ?>
                                    <span class="ap-menu-loc-badge" title="Assigned to <?php echo ap_esc_attr($locLabel); ?>">
                                        <?php echo ap_esc_html($locLabel); ?>
                                    </span>
                                <?php endforeach; ?>
                            </span>
                        <?php endif; ?>
                    </a>
                </li>
            <?php endforeach; ?>
            <?php if ($menus === []) : ?>
                <li><em>No menus yet.</em></li>
            <?php endif; ?>
        </ul>

        <form method="post" action="" class="ap-form ap-form--compact">
            <?php echo ap_nonce_field('ap_nav_menus', '_ap_nonce', false); ?>
            <input type="hidden" name="ap_menu_action" value="create">
            <p class="ap-field">
                <label for="new_menu_name">Create a new menu</label>
                <input type="text" name="menu_name" id="new_menu_name" required maxlength="100"
                    placeholder="Menu name">
            </p>
            <p>
                <button type="submit" class="button">Create Menu</button>
            </p>
        </form>
    </aside>

    <section class="ap-menus-editor">
        <?php if ($currentMenu === null) : ?>
            <p>Create a menu to get started, or select one from the list.</p>
        <?php else : ?>
            <form method="post" action="<?php echo ap_esc_url(AP_Admin::url('nav-menus.php', ['menu' => $editSlug])); ?>"
                class="ap-form" id="ap-menu-save-form">
                <?php echo ap_nonce_field('ap_nav_menus', '_ap_nonce', false); ?>
                <input type="hidden" name="ap_menu_action" value="save">

                <p class="ap-field">
                    <label for="menu_name">Menu Name</label>
                    <input type="text" name="menu_name" id="menu_name" required maxlength="100"
                        value="<?php echo ap_esc_attr($currentMenu['name']); ?>">
                </p>

                <h2>Menu structure</h2>
                <?php if ($currentMenu['items'] === []) : ?>
                    <p class="ap-help">No items yet. Use the panels below to add pages, posts, categories, forums, or custom links, then save.</p>
                <?php else : ?>
                    <?php $itemCount = count($currentMenu['items']); ?>
                    <ol class="ap-menu-items">
                        <?php foreach ($currentMenu['items'] as $i => $item) :
                            $typeLabel = (string) ($item['type'] ?? 'custom');
                            $displayTitle = AP_Nav_Menu::itemTitle($item, $db);
                            ?>
                            <li class="ap-menu-item-row">
                                <div class="ap-menu-item-main">
                                    <input type="hidden" name="item_type[<?php echo (int) $i; ?>]"
                                        value="<?php echo ap_esc_attr($typeLabel); ?>">
                                    <input type="hidden" name="item_object_id[<?php echo (int) $i; ?>]"
                                        value="<?php echo (int) ($item['object_id'] ?? 0); ?>">
                                    <input type="text" name="item_title[<?php echo (int) $i; ?>]"
                                        value="<?php echo ap_esc_attr((string) ($item['title'] ?? '')); ?>"
                                        placeholder="<?php echo ap_esc_attr($displayTitle !== '' ? $displayTitle : 'Label'); ?>"
                                        aria-label="Item title">
                                    <?php if ($typeLabel === 'custom') : ?>
                                        <input type="text" name="item_url[<?php echo (int) $i; ?>]"
                                            value="<?php echo ap_esc_attr((string) ($item['url'] ?? '')); ?>"
                                            aria-label="Item URL" placeholder="/ or https://">
                                    <?php else : ?>
                                        <input type="hidden" name="item_url[<?php echo (int) $i; ?>]" value="">
                                        <span class="ap-help ap-menu-item-meta">
                                            <?php echo ap_esc_html($typeLabel); ?>
                                            #<?php echo (int) ($item['object_id'] ?? 0); ?>
                                            <?php if ($displayTitle !== '' && (string) ($item['title'] ?? '') === '') : ?>
                                                — <?php echo ap_esc_html($displayTitle); ?>
                                            <?php endif; ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div class="ap-menu-item-actions">
                                    <?php if ($i > 0) : ?>
                                        <button type="submit" class="button button-small"
                                            form="ap-menu-move-<?php echo (int) $i; ?>-up"
                                            title="Move up" aria-label="Move up">↑</button>
                                    <?php endif; ?>
                                    <?php if ($i < $itemCount - 1) : ?>
                                        <button type="submit" class="button button-small"
                                            form="ap-menu-move-<?php echo (int) $i; ?>-down"
                                            title="Move down" aria-label="Move down">↓</button>
                                    <?php endif; ?>
                                    <button type="submit" class="button button-small"
                                        form="ap-menu-remove-<?php echo (int) $i; ?>"
                                        title="Remove item" aria-label="Remove item">Remove</button>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                    <p class="ap-help">Use ↑ / ↓ to reorder and Remove to drop an item. Edit labels, then Save Menu.</p>
                <?php endif; ?>

                <h2>Add items</h2>
                <div class="ap-menu-add">
                    <div class="ap-menu-add-panels">
                        <?php if ($showPages) : ?>
                            <fieldset class="ap-menu-add-panel">
                                <legend>Pages</legend>
                                <?php if ($pages === []) : ?>
                                    <p class="ap-help">No published pages.</p>
                                <?php else : ?>
                                    <ul class="ap-menu-picker">
                                        <?php foreach ($pages as $p) : ?>
                                            <?php if (!$p instanceof AP_Post) {
                                                continue;
                                            } ?>
                                            <li>
                                                <label>
                                                    <input type="checkbox" name="add_page[]"
                                                        value="<?php echo (int) $p->ID; ?>">
                                                    <?php echo ap_esc_html((string) $p->post_title); ?>
                                                </label>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </fieldset>
                        <?php endif; ?>

                        <?php if ($showPosts) : ?>
                            <fieldset class="ap-menu-add-panel">
                                <legend>Posts</legend>
                                <?php if ($posts === []) : ?>
                                    <p class="ap-help">No published posts.</p>
                                <?php else : ?>
                                    <ul class="ap-menu-picker">
                                        <?php foreach ($posts as $p) : ?>
                                            <?php if (!$p instanceof AP_Post) {
                                                continue;
                                            } ?>
                                            <li>
                                                <label>
                                                    <input type="checkbox" name="add_post[]"
                                                        value="<?php echo (int) $p->ID; ?>">
                                                    <?php echo ap_esc_html((string) $p->post_title); ?>
                                                </label>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </fieldset>

                            <fieldset class="ap-menu-add-panel">
                                <legend>Categories</legend>
                                <?php if ($categories === []) : ?>
                                    <p class="ap-help">No categories.</p>
                                <?php else : ?>
                                    <ul class="ap-menu-picker">
                                        <?php foreach ($categories as $term) : ?>
                                            <?php if (!is_object($term)) {
                                                continue;
                                            } ?>
                                            <li>
                                                <label>
                                                    <input type="checkbox" name="add_category[]"
                                                        value="<?php echo (int) ($term->term_id ?? 0); ?>">
                                                    <?php echo ap_esc_html((string) ($term->name ?? '')); ?>
                                                </label>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </fieldset>
                        <?php endif; ?>

                        <?php if ($showForums) : ?>
                            <fieldset class="ap-menu-add-panel">
                                <legend>Forums</legend>
                                <?php if ($forums === []) : ?>
                                    <p class="ap-help">No forums yet.</p>
                                <?php else : ?>
                                    <ul class="ap-menu-picker">
                                        <?php foreach ($forums as $forum) : ?>
                                            <?php if (!is_object($forum)) {
                                                continue;
                                            } ?>
                                            <li>
                                                <label>
                                                    <input type="checkbox" name="add_forum[]"
                                                        value="<?php echo (int) ($forum->forum_id ?? 0); ?>">
                                                    <?php echo ap_esc_html((string) ($forum->forum_name ?? '')); ?>
                                                    <?php if ((string) ($forum->forum_type ?? '') === 'category') : ?>
                                                        <span class="ap-help">(category)</span>
                                                    <?php endif; ?>
                                                </label>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </fieldset>
                        <?php endif; ?>

                        <fieldset class="ap-menu-add-panel">
                            <legend>Custom link</legend>
                            <p class="ap-field">
                                <label for="new_item_title">Label</label>
                                <input type="text" name="new_item_title" id="new_item_title" maxlength="200"
                                    placeholder="e.g. Home">
                            </p>
                            <p class="ap-field">
                                <label for="new_item_url">URL</label>
                                <input type="text" name="new_item_url" id="new_item_url"
                                    placeholder="/ or https://example.com">
                            </p>
                            <input type="hidden" name="new_item_type" value="custom">
                        </fieldset>
                    </div>
                    <p class="ap-help">Select content and/or fill in a custom link, then click Save Menu to add them.</p>
                </div>

                <h2>Display location</h2>
                <p class="ap-help">
                    Check every theme location where this menu should appear.
                    You can also assign menus from the
                    <a href="<?php echo ap_esc_url(AP_Admin::url('nav-menus.php', ['tab' => 'locations'])); ?>">Manage Locations</a>
                    tab (useful when one menu is shared or you need a quick overview).
                </p>
                <fieldset class="ap-menu-locations-checks">
                    <legend class="screen-reader-text">Theme locations for this menu</legend>
                    <?php foreach ($locations as $loc => $desc) :
                        $isAssignedHere = isset($assignments[$loc]) && $assignments[$loc] === $editSlug;
                        $otherSlug = isset($assignments[$loc]) && $assignments[$loc] !== $editSlug
                            ? (string) $assignments[$loc]
                            : '';
                        $otherName = ($otherSlug !== '' && isset($menus[$otherSlug]))
                            ? (string) $menus[$otherSlug]['name']
                            : $otherSlug;
                        $help = $locationHelp[$loc] ?? '';
                        $fieldId = 'location_' . $loc;
                        ?>
                        <p class="ap-menu-location-row">
                            <label for="<?php echo ap_esc_attr($fieldId); ?>">
                                <input type="checkbox" name="<?php echo ap_esc_attr($fieldId); ?>"
                                    id="<?php echo ap_esc_attr($fieldId); ?>" value="1"
                                    <?php echo $isAssignedHere ? 'checked' : ''; ?>>
                                <strong><?php echo ap_esc_html($desc !== '' ? $desc : $loc); ?></strong>
                                <code><?php echo ap_esc_html($loc); ?></code>
                            </label>
                            <?php if ($help !== '') : ?>
                                <span class="ap-help ap-menu-location-help"><?php echo ap_esc_html($help); ?></span>
                            <?php endif; ?>
                            <?php if ($otherSlug !== '') : ?>
                                <span class="ap-help ap-menu-location-conflict">
                                    Currently assigned to “<?php echo ap_esc_html($otherName); ?>”.
                                    Checking this box will move the location to this menu.
                                </span>
                            <?php endif; ?>
                        </p>
                    <?php endforeach; ?>
                </fieldset>

                <p class="ap-form-actions">
                    <button type="submit" class="button button-primary">Save Menu</button>
                </p>
            </form>

            <?php if ($currentMenu['items'] !== []) : ?>
                <?php foreach ($currentMenu['items'] as $i => $item) : ?>
                    <?php if ($i > 0) : ?>
                        <form method="post" id="ap-menu-move-<?php echo (int) $i; ?>-up"
                            action="<?php echo ap_esc_url(AP_Admin::url('nav-menus.php', ['menu' => $editSlug])); ?>"
                            class="ap-hidden-form" hidden>
                            <?php echo ap_nonce_field('ap_nav_menus', '_ap_nonce', false); ?>
                            <input type="hidden" name="ap_menu_action" value="move">
                            <input type="hidden" name="item_index" value="<?php echo (int) $i; ?>">
                            <input type="hidden" name="direction" value="up">
                        </form>
                    <?php endif; ?>
                    <?php if ($i < count($currentMenu['items']) - 1) : ?>
                        <form method="post" id="ap-menu-move-<?php echo (int) $i; ?>-down"
                            action="<?php echo ap_esc_url(AP_Admin::url('nav-menus.php', ['menu' => $editSlug])); ?>"
                            class="ap-hidden-form" hidden>
                            <?php echo ap_nonce_field('ap_nav_menus', '_ap_nonce', false); ?>
                            <input type="hidden" name="ap_menu_action" value="move">
                            <input type="hidden" name="item_index" value="<?php echo (int) $i; ?>">
                            <input type="hidden" name="direction" value="down">
                        </form>
                    <?php endif; ?>
                    <form method="post" id="ap-menu-remove-<?php echo (int) $i; ?>"
                        action="<?php echo ap_esc_url(AP_Admin::url('nav-menus.php', ['menu' => $editSlug])); ?>"
                        class="ap-hidden-form" hidden>
                        <?php echo ap_nonce_field('ap_nav_menus', '_ap_nonce', false); ?>
                        <input type="hidden" name="ap_menu_action" value="remove_item">
                        <input type="hidden" name="item_index" value="<?php echo (int) $i; ?>">
                    </form>
                <?php endforeach; ?>
            <?php endif; ?>

            <form method="post" action="<?php echo ap_esc_url(AP_Admin::url('nav-menus.php', ['menu' => $editSlug])); ?>"
                class="ap-form ap-form--danger" onsubmit="return confirm('Delete this menu?');">
                <?php echo ap_nonce_field('ap_nav_menus', '_ap_nonce', false); ?>
                <input type="hidden" name="ap_menu_action" value="delete">
                <button type="submit" class="button">Delete Menu</button>
            </form>
        <?php endif; ?>
    </section>
</div>

<?php endif; // edit vs locations tab ?>

<?php
require __DIR__ . '/admin-footer.php';
