<?php

/**
 * Forum topics list + bulk moderation (`forum-topics.php`).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

require __DIR__ . '/admin-bootstrap.php';

AP_Admin::requireCapability('moderate_forums');

if (!AP_Options::isModuleEnabled('forum')) {
    AP_Admin::denyAccess('The Forum module is disabled. Enable it under Settings → Modules.');
}

$listTable = new AP_Forum_Topics_List_Table();

// Single-row actions via GET.
$rowAction = (string) ($_GET['action'] ?? '');
if (
    in_array($rowAction, [
        'lock', 'unlock', 'sticky', 'unsticky', 'approve', 'unapprove',
        'trash', 'soft_delete', 'restore', 'delete',
    ], true)
    && (isset($_GET['topic']) || isset($_GET['t']))
) {
    $result = $listTable->processRowAction($_GET);
    $redirect = AP_Admin::url('forum-topics.php', array_filter([
        'topic_status' => (string) ($_GET['topic_status'] ?? '') ?: null,
        'forum_id' => ((int) ($_GET['forum_id'] ?? 0)) > 0 ? (int) $_GET['forum_id'] : null,
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
        $redirect = AP_Admin::url('forum-topics.php', array_filter([
            'topic_status' => (string) ($_POST['topic_status'] ?? $_GET['topic_status'] ?? '') ?: null,
            'forum_id' => ((int) ($_POST['forum_id'] ?? $_GET['forum_id'] ?? 0)) > 0
                ? (int) ($_POST['forum_id'] ?? $_GET['forum_id'] ?? 0)
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

$ap_admin_title = 'Topics';
$ap_admin_screen = 'forum-topics';
$ap_admin_body_class = 'ap-forum-topics-php';

require __DIR__ . '/admin-header.php';
?>
<div class="ap-page-header">
    <h1>Topics</h1>
</div>

<p class="ap-help">Moderate topics across all forums: lock, sticky, soft-delete, approve.</p>

<div class="ap-list-toolbar">
    <?php echo $listTable->renderViews(); ?>
    <?php echo $listTable->renderSearchBox(); ?>
</div>

<?php echo $listTable->render(); ?>

<?php
require __DIR__ . '/admin-footer.php';
