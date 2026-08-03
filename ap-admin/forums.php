<?php

/**
 * Forums hierarchy list (`forums.php`).
 *
 * Visible when the Forum module is enabled. Requires manage_forums.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

require __DIR__ . '/admin-bootstrap.php';

AP_Admin::requireCapability('manage_forums');

if (!AP_Options::isModuleEnabled('forum')) {
    AP_Admin::denyAccess('The Forum module is disabled. Enable it under Settings → Modules.');
}

$listTable = new AP_Forums_List_Table();

// Single-row delete via GET.
$rowAction = (string) ($_GET['action'] ?? '');
if ($rowAction === 'delete' && isset($_GET['forum'])) {
    $result = AP_Admin_Forum_Edit::delete($_GET);
    AP_Admin::redirect(AP_Admin::url('forums.php', array_filter([
        'message' => $result['message_key'] !== ''
            ? $result['message_key']
            : ($result['ok'] ? 'forum_deleted' : 'error'),
    ])));
}

// Bulk actions via POST.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $result = $listTable->processBulkAction($_POST);
    if ($result['message_key'] !== '' || $result['ok']) {
        AP_Admin::redirect(AP_Admin::url('forums.php', array_filter([
            'message' => $result['message_key'] !== '' ? $result['message_key'] : 'error',
            'count' => $result['count'] > 0 ? $result['count'] : null,
        ])));
    }
    foreach ($result['errors'] as $err) {
        AP_Admin::addNotice($err, 'error');
    }
}

AP_Admin::consumeQueryNotice();
$listTable->prepareItems($_GET);

$ap_admin_title = 'Forums';
$ap_admin_screen = 'forums';
$ap_admin_body_class = 'ap-forums-php';

require __DIR__ . '/admin-header.php';
?>
<div class="ap-page-header">
    <h1>Forums</h1>
    <a class="button button-primary" href="<?php echo ap_esc_url(AP_Admin::url('forum-edit.php')); ?>">
        Add New Forum
    </a>
</div>

<p class="ap-help">
    Manage categories and forums. Categories group forums; set parent, order, and status per board.
</p>

<div class="ap-list-toolbar">
    <?php echo $listTable->renderSearchBox(); ?>
</div>

<?php echo $listTable->render(); ?>

<?php
require __DIR__ . '/admin-footer.php';
