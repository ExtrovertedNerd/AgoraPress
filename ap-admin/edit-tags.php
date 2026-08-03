<?php

/**
 * Admin: manage taxonomy terms (Categories, Tags, custom taxonomies).
 *
 * Query: ?taxonomy=category|post_tag
 * Actions: add-tag, editedtag, delete, bulk delete.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

require_once __DIR__ . '/admin-bootstrap.php';

AP_Admin::requireCapability('manage_categories');

AP_Admin::consumeQueryNotice();

$taxonomy = AP_Admin_Terms::resolveTaxonomy((string) ($_REQUEST['taxonomy'] ?? 'category'));
$userId = ap_get_current_user_id();
$db = ap_db();
AP_Taxonomy::ensureBuiltins();
if ($taxonomy === 'category') {
    AP_Taxonomy::ensureDefaultCategory($db);
}

$action = (string) ($_REQUEST['action'] ?? $_REQUEST['action2'] ?? '');
// Prefer non-empty action over action2 bulk default.
if ($action === '-1' || $action === '') {
    $action = (string) ($_REQUEST['action2'] ?? '');
}
if ($action === '-1') {
    $action = '';
}

// --- Row delete via GET ---
if ($action === 'delete' && isset($_GET['tag_ID'])) {
    $result = AP_Admin_Terms::delete(
        (int) $_GET['tag_ID'],
        $taxonomy,
        $userId,
        (string) ($_GET['_ap_nonce'] ?? ''),
        $db
    );
    AP_Admin::redirect(AP_Admin::url('edit-tags.php', [
        'taxonomy' => $taxonomy,
        'message' => $result['message_key'],
    ]));
}

// --- Bulk delete via POST ---
if (
    ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
    && $action === 'delete'
    && isset($_POST['delete_tags'])
    && is_array($_POST['delete_tags'])
) {
    $ids = array_map('intval', $_POST['delete_tags']);
    $result = AP_Admin_Terms::bulkDelete(
        $ids,
        $taxonomy,
        $userId,
        (string) ($_POST['_ap_nonce'] ?? ''),
        $db
    );
    AP_Admin::redirect(AP_Admin::url('edit-tags.php', [
        'taxonomy' => $taxonomy,
        'message' => $result['message_key'],
        'count' => $result['count'],
    ]));
}

// --- Add / update term ---
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $postAction = (string) ($_POST['action'] ?? '');
    if ($postAction === 'add-tag' || $postAction === 'editedtag') {
        $result = AP_Admin_Terms::save($_POST, $userId, $db);
        if ($result['ok']) {
            AP_Admin::redirect(AP_Admin::url('edit-tags.php', [
                'taxonomy' => $taxonomy,
                'message' => $result['message_key'],
            ]));
        }
        foreach ($result['errors'] as $err) {
            AP_Admin::addNotice($err, 'error');
        }
        if ($result['message_key'] === 'nonce') {
            AP_Admin::addNotice('Security check failed. Please reload and try again.', 'error');
        }
    }
}

// --- Edit screen ---
$editId = (int) ($_GET['tag_ID'] ?? 0);
$editing = null;
if (($action === 'edit' || isset($_GET['tag_ID'])) && $editId > 0 && !isset($_POST['action'])) {
    $editing = AP_Taxonomy::getTerm($editId, $taxonomy, $db);
    if ($editing === null) {
        AP_Admin::addNotice('That term could not be found.', 'error');
        $editId = 0;
    }
}

$label = AP_Admin_Terms::taxonomyLabel($taxonomy, false);
$singular = AP_Admin_Terms::taxonomyLabel($taxonomy, true);
$ap_admin_title = $editing !== null ? ('Edit ' . $singular) : $label;
$ap_admin_screen = $taxonomy === 'post_tag' ? 'tags' : ($taxonomy === 'category' ? 'categories' : 'posts');
$ap_admin_body_class = 'ap-edit-tags taxonomy-' . $taxonomy;

require __DIR__ . '/admin-header.php';
?>
<div class="ap-wrap">
    <h1 class="ap-page-title"><?php echo ap_esc_html($ap_admin_title); ?></h1>

    <?php if ($editing !== null) : ?>
        <?php echo AP_Admin_Terms::renderEditForm($editing, $taxonomy, $userId, $db); ?>
    <?php else : ?>
        <div class="ap-terms-layout">
            <div class="ap-terms-col-add">
                <?php echo AP_Admin_Terms::renderAddForm($taxonomy, $userId, $db); ?>
            </div>
            <div class="ap-terms-col-list">
                <?php echo AP_Admin_Terms::renderListTable($taxonomy, $_GET, $userId, $db); ?>
            </div>
        </div>
    <?php endif; ?>
</div>
<?php
require __DIR__ . '/admin-footer.php';
