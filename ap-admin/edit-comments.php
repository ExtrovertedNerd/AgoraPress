<?php

/**
 * Comments moderation list (`edit-comments.php`).
 *
 * Status views: All / Pending / Approved / Spam / Trash.
 * Bulk + row actions: Approve, Unapprove, Spam, Trash, Delete.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

require __DIR__ . '/admin-bootstrap.php';

AP_Admin::requireCapability('moderate_comments');

$listTable = new AP_Comments_List_Table();

// Single-row actions via GET.
$rowAction = (string) ($_GET['action'] ?? '');
if (
    in_array($rowAction, ['approve', 'unapprove', 'spam', 'unspam', 'trash', 'untrash', 'delete'], true)
    && (isset($_GET['c']) || isset($_GET['comment']))
) {
    $result = $listTable->processRowAction($_GET);
    $redirect = AP_Admin::url('edit-comments.php', array_filter([
        'comment_status' => (string) ($_GET['comment_status'] ?? '') ?: null,
        'p' => ((int) ($_GET['p'] ?? 0)) > 0 ? (int) $_GET['p'] : null,
        'message' => $result['message_key'] !== ''
            ? $result['message_key']
            : ($result['ok'] ? 'updated' : 'error'),
    ]));
    AP_Admin::redirect($redirect);
}

// Bulk actions via POST.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $result = $listTable->processBulkAction($_POST);
    if ($result['message_key'] !== '' || $result['ok']) {
        $redirect = AP_Admin::url('edit-comments.php', array_filter([
            'comment_status' => (string) ($_POST['comment_status'] ?? $_GET['comment_status'] ?? '') ?: null,
            'p' => ((int) ($_POST['p'] ?? $_GET['p'] ?? 0)) > 0
                ? (int) ($_POST['p'] ?? $_GET['p'] ?? 0)
                : null,
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

$ap_admin_title = 'Comments';
$ap_admin_screen = 'comments';
$ap_admin_body_class = 'ap-edit-comments-php';

require __DIR__ . '/admin-header.php';
?>
<div class="ap-page-header">
    <h1>Comments</h1>
</div>

<div class="ap-list-toolbar">
    <?php echo $listTable->renderViews(); ?>
    <?php echo $listTable->renderSearchBox(); ?>
</div>

<?php echo $listTable->render(); ?>

<?php
require __DIR__ . '/admin-footer.php';
