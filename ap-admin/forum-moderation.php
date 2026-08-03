<?php

/**
 * Forum moderation queue: pending content + reports (`forum-moderation.php`).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

require __DIR__ . '/admin-bootstrap.php';

AP_Admin::requireCapability('moderate_forums');

if (!AP_Options::isModuleEnabled('forum')) {
    AP_Admin::denyAccess('The Forum module is disabled. Enable it under Settings → Modules.');
}

$queue = new AP_Forum_Moderation_Queue();

// Single-row actions via GET.
$rowAction = (string) ($_GET['action'] ?? '');
if (
    in_array($rowAction, [
        'approve_topic', 'trash_topic', 'reject_topic',
        'approve_post', 'trash_post', 'reject_post',
        'resolve_report', 'dismiss_report', 'reopen_report',
    ], true)
) {
    $result = $queue->processAction($_GET);
    $redirect = AP_Admin::url('forum-moderation.php', array_filter([
        'view' => (string) ($_GET['view'] ?? 'pending') ?: null,
        'message' => $result['message_key'] !== ''
            ? $result['message_key']
            : ($result['ok'] ? 'updated' : 'error'),
    ]));
    AP_Admin::redirect($redirect);
}

// Bulk via POST.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $result = $queue->processBulk($_POST);
    if ($result['message_key'] !== '' || $result['ok']) {
        $redirect = AP_Admin::url('forum-moderation.php', array_filter([
            'view' => (string) ($_POST['view'] ?? $_GET['view'] ?? 'pending') ?: null,
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
$queue->prepare($_GET);

$ap_admin_title = 'Moderation';
$ap_admin_screen = 'forum-moderation';
$ap_admin_body_class = 'ap-forum-moderation-php';

require __DIR__ . '/admin-header.php';
?>
<div class="ap-page-header">
    <h1>Moderation</h1>
</div>

<p class="ap-help">Approve pending topics and replies, and handle user reports.</p>

<div class="ap-list-toolbar">
    <?php echo $queue->renderViews(); ?>
</div>

<?php echo $queue->render(); ?>

<?php
require __DIR__ . '/admin-footer.php';
