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

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

// Single-row actions via GET (Move without dest shows a destination picker).
$rowAction = (string) ($_GET['action'] ?? '');
if (
    $method !== 'POST'
    && in_array($rowAction, [
        'lock', 'unlock', 'sticky', 'unsticky', 'approve', 'unapprove',
        'trash', 'soft_delete', 'restore', 'delete', 'move',
    ], true)
    && (isset($_GET['topic']) || isset($_GET['t']))
) {
    $destId = (int) ($_GET['dest_forum_id'] ?? 0);
    if ($rowAction === 'move' && $destId < 1) {
        $prepared = $listTable->prepareRowMovePicker($_GET);
        if (!$prepared['ok']) {
            $redirect = AP_Admin::url('forum-topics.php', array_filter([
                'topic_status' => (string) ($_GET['topic_status'] ?? '') ?: null,
                'forum_id' => ((int) ($_GET['forum_id'] ?? 0)) > 0 ? (int) $_GET['forum_id'] : null,
                'message' => $prepared['message_key'] !== '' ? $prepared['message_key'] : 'error',
            ]));
            AP_Admin::redirect($redirect);
        }
    } else {
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
}

// Bulk actions via POST. Row Move posts a scalar topic id.
if ($method === 'POST') {
    $postAction = (string) ($_POST['action'] ?? $_POST['action2'] ?? '-1');
    $rawTopic = $_POST['topic'] ?? $_POST['topic_ids'] ?? null;
    if ($postAction === 'move' && !is_array($rawTopic)) {
        $result = $listTable->processRowAction($_POST);
        $redirect = AP_Admin::url('forum-topics.php', array_filter([
            'topic_status' => (string) ($_POST['topic_status'] ?? $_GET['topic_status'] ?? '') ?: null,
            'forum_id' => ((int) ($_POST['forum_id'] ?? $_GET['forum_id'] ?? 0)) > 0
                ? (int) ($_POST['forum_id'] ?? $_GET['forum_id'] ?? 0)
                : null,
            'message' => $result['message_key'] !== ''
                ? $result['message_key']
                : ($result['ok'] ? 'updated' : 'error'),
        ]));
        AP_Admin::redirect($redirect);
    }

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

<p class="ap-help">Moderate topics across all forums: lock, sticky, move, soft-delete, approve.</p>

<div class="ap-list-toolbar">
    <?php echo $listTable->renderViews(); ?>
    <?php echo $listTable->renderSearchBox(); ?>
</div>

<?php echo $listTable->render(); ?>

<?php
require __DIR__ . '/admin-footer.php';
