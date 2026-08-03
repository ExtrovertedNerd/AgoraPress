<?php

/**
 * All Users list table (`users.php`).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

require __DIR__ . '/admin-bootstrap.php';

AP_Admin::requireCapability('list_users');

$userId = ap_get_current_user_id();
$listTable = new AP_Users_List_Table();

// Single-row delete via GET.
$rowAction = (string) ($_GET['action'] ?? '');
if ($rowAction === 'delete' && isset($_GET['user'])) {
    $result = $listTable->processRowAction($_GET, $userId);
    $redirect = AP_Admin::url('users.php', array_filter([
        'role' => (string) ($_GET['role'] ?? '') ?: null,
        'message' => $result['message_key'] !== ''
            ? $result['message_key']
            : ($result['ok'] ? 'user_deleted' : 'error'),
    ]));
    AP_Admin::redirect($redirect);
}

// Bulk actions via POST.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $result = $listTable->processBulkAction($_POST, $userId);
    if ($result['message_key'] !== '' || $result['ok']) {
        $redirect = AP_Admin::url('users.php', array_filter([
            'role' => (string) ($_POST['role'] ?? $_GET['role'] ?? '') ?: null,
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

$ap_admin_title = 'Users';
$ap_admin_screen = 'users';
$ap_admin_body_class = 'ap-users-php';

require __DIR__ . '/admin-header.php';
?>
<div class="ap-page-header">
    <h1>Users</h1>
    <?php if (AP_Admin::currentUserCan('create_users')) : ?>
        <a class="button button-primary" href="<?php echo ap_esc_url(AP_Admin::url('user-new.php')); ?>">
            Add New
        </a>
    <?php endif; ?>
</div>

<div class="ap-list-toolbar">
    <?php echo $listTable->renderViews(); ?>
    <?php echo $listTable->renderSearchBox(); ?>
</div>

<?php echo $listTable->render(); ?>

<?php
require __DIR__ . '/admin-footer.php';
