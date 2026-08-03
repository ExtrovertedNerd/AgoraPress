<?php

/**
 * Appearance — Menus.
 *
 * Create a menu, add custom links / pages / posts / categories, assign to
 * theme locations (Primary, Footer, …).
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

// Which menu is being edited?
$editSlug = isset($_GET['menu']) ? preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $_GET['menu'])) ?? '' : '';
if ($editSlug === '' && $menus !== []) {
    $editSlug = (string) array_key_first($menus);
}
$currentMenu = $editSlug !== '' ? ($menus[$editSlug] ?? null) : null;

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
    } elseif ($action === 'save' && $editSlug !== '') {
        $name = ap_sanitize_text_field((string) ($_POST['menu_name'] ?? ''));
        $items = [];
        $titles = $_POST['item_title'] ?? [];
        $types = $_POST['item_type'] ?? [];
        $urls = $_POST['item_url'] ?? [];
        $objectIds = $_POST['item_object_id'] ?? [];
        if (is_array($titles)) {
            foreach ($titles as $idx => $title) {
                $type = is_array($types) ? (string) ($types[$idx] ?? 'custom') : 'custom';
                $url = is_array($urls) ? (string) ($urls[$idx] ?? '') : '';
                $oid = is_array($objectIds) ? (int) ($objectIds[$idx] ?? 0) : 0;
                $items[] = [
                    'type' => $type,
                    'title' => ap_sanitize_text_field((string) $title),
                    'url' => ap_sanitize_text_field($url),
                    'object_id' => $oid,
                ];
            }
        }
        // New custom link from the "Add item" box.
        $newTitle = ap_sanitize_text_field((string) ($_POST['new_item_title'] ?? ''));
        $newUrl = ap_sanitize_text_field((string) ($_POST['new_item_url'] ?? ''));
        $newType = ap_sanitize_text_field((string) ($_POST['new_item_type'] ?? 'custom'));
        $newOid = (int) ($_POST['new_item_object_id'] ?? 0);
        if ($newTitle !== '' || $newUrl !== '' || $newOid > 0) {
            $items[] = [
                'type' => $newType !== '' ? $newType : 'custom',
                'title' => $newTitle,
                'url' => $newUrl,
                'object_id' => $newOid,
            ];
        }
        if (AP_Nav_Menu::saveMenu($editSlug, $name !== '' ? $name : $editSlug, $items, $db)) {
            // Location assignments.
            $locMap = $assignments;
            foreach ($locations as $loc => $_desc) {
                $field = 'location_' . $loc;
                if (!empty($_POST[$field]) && (string) $_POST[$field] === '1') {
                    $locMap[$loc] = $editSlug;
                } elseif (isset($locMap[$loc]) && $locMap[$loc] === $editSlug) {
                    unset($locMap[$loc]);
                }
            }
            AP_Nav_Menu::setLocationAssignments($locMap, $db);
            AP_Admin::redirect(AP_Admin::url('nav-menus.php', ['menu' => $editSlug, 'message' => 'menu_saved']));
        }
        AP_Admin::addNotice('Could not save the menu.', 'error');
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
$pages = AP_Post::query([
    'post_type' => 'page',
    'post_status' => 'publish',
    'limit' => 50,
    'orderby' => 'post_title',
    'order' => 'ASC',
], $db);
$posts = AP_Post::query([
    'post_type' => 'post',
    'post_status' => 'publish',
    'limit' => 20,
    'orderby' => 'post_date',
    'order' => 'DESC',
], $db);
$categories = class_exists('AP_Taxonomy', false)
    ? AP_Taxonomy::getTerms('category', ['hide_empty' => false, 'number' => 50], $db)
    : [];

$ap_admin_title = 'Menus';
$ap_admin_screen = 'nav-menus';
require __DIR__ . '/admin-header.php';
?>
<div class="ap-page-header">
    <h1>Menus</h1>
</div>

<p>Build navigation menus and assign them to theme locations (Primary, Footer, etc.).</p>

<div class="ap-menus-layout">
    <aside class="ap-menus-sidebar">
        <h2>Your Menus</h2>
        <ul class="ap-menu-list">
            <?php foreach ($menus as $slug => $menu) : ?>
                <li class="<?php echo $slug === $editSlug ? 'current' : ''; ?>">
                    <a href="<?php echo ap_esc_url(AP_Admin::url('nav-menus.php', ['menu' => $slug])); ?>">
                        <?php echo ap_esc_html($menu['name']); ?>
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
            <form method="post" action="<?php echo ap_esc_url(AP_Admin::url('nav-menus.php', ['menu' => $editSlug])); ?>" class="ap-form">
                <?php echo ap_nonce_field('ap_nav_menus', '_ap_nonce', false); ?>
                <input type="hidden" name="ap_menu_action" value="save">

                <p class="ap-field">
                    <label for="menu_name">Menu Name</label>
                    <input type="text" name="menu_name" id="menu_name" required maxlength="100"
                        value="<?php echo ap_esc_attr($currentMenu['name']); ?>">
                </p>

                <h2>Menu structure</h2>
                <?php if ($currentMenu['items'] === []) : ?>
                    <p class="ap-help">No items yet. Add a custom link or content below, then save.</p>
                <?php else : ?>
                    <ol class="ap-menu-items">
                        <?php foreach ($currentMenu['items'] as $i => $item) : ?>
                            <li>
                                <input type="hidden" name="item_type[<?php echo (int) $i; ?>]"
                                    value="<?php echo ap_esc_attr((string) $item['type']); ?>">
                                <input type="hidden" name="item_object_id[<?php echo (int) $i; ?>]"
                                    value="<?php echo (int) ($item['object_id'] ?? 0); ?>">
                                <input type="text" name="item_title[<?php echo (int) $i; ?>]"
                                    value="<?php echo ap_esc_attr((string) ($item['title'] ?? '')); ?>"
                                    aria-label="Item title">
                                <?php if (($item['type'] ?? '') === 'custom') : ?>
                                    <input type="url" name="item_url[<?php echo (int) $i; ?>]"
                                        value="<?php echo ap_esc_attr((string) ($item['url'] ?? '')); ?>"
                                        aria-label="Item URL" placeholder="https://">
                                <?php else : ?>
                                    <input type="hidden" name="item_url[<?php echo (int) $i; ?>]" value="">
                                    <span class="ap-help">
                                        <?php echo ap_esc_html((string) $item['type']); ?>
                                        #<?php echo (int) ($item['object_id'] ?? 0); ?>
                                    </span>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                    <p class="ap-help">To remove an item, clear its title and save. Reorder by editing later (drag-and-drop lands later).</p>
                <?php endif; ?>

                <h2>Add item</h2>
                <div class="ap-menu-add">
                    <p class="ap-field">
                        <label for="new_item_type">Type</label>
                        <select name="new_item_type" id="new_item_type">
                            <option value="custom">Custom link</option>
                            <option value="page">Page</option>
                            <option value="post">Post</option>
                            <option value="category">Category</option>
                        </select>
                    </p>
                    <p class="ap-field">
                        <label for="new_item_title">Label</label>
                        <input type="text" name="new_item_title" id="new_item_title" maxlength="200">
                    </p>
                    <p class="ap-field">
                        <label for="new_item_url">URL (custom links)</label>
                        <input type="text" name="new_item_url" id="new_item_url" placeholder="/ or https://">
                    </p>
                    <p class="ap-field">
                        <label for="new_item_object_id">Object ID (page / post / category)</label>
                        <input type="number" name="new_item_object_id" id="new_item_object_id" min="0" value="0">
                    </p>
                    <?php if ($pages !== []) : ?>
                        <p class="ap-help">
                            Pages:
                            <?php foreach ($pages as $p) : ?>
                                <?php if (!$p instanceof AP_Post) {
                                    continue;
                                } ?>
                                <code><?php echo (int) $p->ID; ?></code>
                                <?php echo ap_esc_html((string) $p->post_title); ?>;
                            <?php endforeach; ?>
                        </p>
                    <?php endif; ?>
                    <?php if ($posts !== []) : ?>
                        <p class="ap-help">
                            Recent posts:
                            <?php foreach ($posts as $p) : ?>
                                <?php if (!$p instanceof AP_Post) {
                                    continue;
                                } ?>
                                <code><?php echo (int) $p->ID; ?></code>
                                <?php echo ap_esc_html((string) $p->post_title); ?>;
                            <?php endforeach; ?>
                        </p>
                    <?php endif; ?>
                    <?php if (is_array($categories) && $categories !== []) : ?>
                        <p class="ap-help">
                            Categories:
                            <?php foreach ($categories as $term) : ?>
                                <?php if (!is_object($term)) {
                                    continue;
                                } ?>
                                <code><?php echo (int) ($term->term_id ?? 0); ?></code>
                                <?php echo ap_esc_html((string) ($term->name ?? '')); ?>;
                            <?php endforeach; ?>
                        </p>
                    <?php endif; ?>
                </div>

                <h2>Display location</h2>
                <?php foreach ($locations as $loc => $desc) : ?>
                    <p>
                        <label>
                            <input type="checkbox" name="location_<?php echo ap_esc_attr($loc); ?>" value="1"
                                <?php echo (isset($assignments[$loc]) && $assignments[$loc] === $editSlug) ? 'checked' : ''; ?>>
                            <?php echo ap_esc_html($desc !== '' ? $desc : $loc); ?>
                            <code><?php echo ap_esc_html($loc); ?></code>
                        </label>
                    </p>
                <?php endforeach; ?>

                <p class="ap-form-actions">
                    <button type="submit" class="button button-primary">Save Menu</button>
                </p>
            </form>

            <form method="post" action="<?php echo ap_esc_url(AP_Admin::url('nav-menus.php', ['menu' => $editSlug])); ?>"
                class="ap-form ap-form--danger" onsubmit="return confirm('Delete this menu?');">
                <?php echo ap_nonce_field('ap_nav_menus', '_ap_nonce', false); ?>
                <input type="hidden" name="ap_menu_action" value="delete">
                <button type="submit" class="button">Delete Menu</button>
            </form>
        <?php endif; ?>
    </section>
</div>

<?php
require __DIR__ . '/admin-footer.php';
