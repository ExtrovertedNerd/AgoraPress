<?php

/**
 * Posts / pages list table (`edit.php?post_type=post|page`).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

require __DIR__ . '/admin-bootstrap.php';

$postType = AP_Admin::resolvePostType((string) ($_REQUEST['post_type'] ?? 'post'), 'post');
AP_Admin::requireCapability(AP_Admin::editCapabilityForPostType($postType));
$listTable = new AP_Posts_List_Table($postType);

// Single-row actions via GET (trash / untrash / delete).
$rowAction = (string) ($_GET['action'] ?? '');
if (in_array($rowAction, ['trash', 'untrash', 'delete'], true) && isset($_GET['post'])) {
    $result = AP_Admin_Post_Edit::processRowAction($_GET);
    $redirect = AP_Admin::url('edit.php', array_filter([
        'post_type' => $postType,
        'post_status' => (string) ($_GET['post_status'] ?? '') ?: null,
        'message' => $result['message_key'] !== '' ? $result['message_key'] : ($result['ok'] ? 'updated' : 'error'),
    ]));
    AP_Admin::redirect($redirect);
}

// Bulk actions via POST.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $result = $listTable->processBulkAction($_POST);
    if ($result['message_key'] !== '' || $result['ok']) {
        $redirect = AP_Admin::url('edit.php', array_filter([
            'post_type' => $postType,
            'post_status' => (string) ($_POST['post_status'] ?? $_GET['post_status'] ?? '') ?: null,
            'message' => $result['message_key'] !== '' ? $result['message_key'] : 'error',
            'count' => $result['count'] > 0 ? $result['count'] : null,
        ]));
        AP_Admin::redirect($redirect);
    }
    foreach ($result['errors'] as $err) {
        AP_Admin::addNotice($err, 'error');
    }
}

AP_Admin::consumeQueryNotice();
$listTable->prepareItems($_GET);

$plural = AP_Admin::postTypeLabel($postType, false);
$singular = AP_Admin::postTypeLabel($postType, true);
$ap_admin_title = $plural;
$ap_admin_screen = $postType === 'page' ? 'pages' : 'posts';
$ap_admin_body_class = 'ap-edit-php post-type-' . $postType;

require __DIR__ . '/admin-header.php';
?>
<div class="ap-page-header">
    <h1><?php echo ap_esc_html($plural); ?></h1>
    <?php $newUrl = AP_Admin::url('post-new.php', ['post_type' => $postType]); ?>
    <a class="button button-primary" href="<?php echo ap_esc_url($newUrl); ?>">
        Add New <?php echo ap_esc_html($singular); ?>
    </a>
</div>

<div class="ap-list-toolbar">
    <?php echo $listTable->renderViews(); ?>
    <?php echo $listTable->renderSearchBox(); ?>
</div>

<?php echo $listTable->render(); ?>

<?php
require __DIR__ . '/admin-footer.php';
