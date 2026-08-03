<?php

/**
 * Add New User (`user-new.php`).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

require __DIR__ . '/admin-bootstrap.php';

AP_Admin::requireCapability('create_users');

$actorId = ap_get_current_user_id();
$extra = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $result = AP_Admin_User_Edit::save($_POST, $actorId, 'create');
    if ($result['ok']) {
        AP_Admin::redirect(AP_Admin::url('user-edit.php', [
            'user_id' => $result['id'],
            'message' => $result['message_key'],
        ]));
    }
    foreach ($result['errors'] as $err) {
        AP_Admin::addNotice($err, 'error');
    }
    $extra = [
        'user_login' => (string) ($_POST['user_login'] ?? ''),
        'user_email' => (string) ($_POST['user_email'] ?? ''),
        'user_url' => (string) ($_POST['user_url'] ?? ''),
        'display_name' => (string) ($_POST['display_name'] ?? ''),
        'first_name' => (string) ($_POST['first_name'] ?? ''),
        'last_name' => (string) ($_POST['last_name'] ?? ''),
        'nickname' => (string) ($_POST['nickname'] ?? ''),
        'description' => (string) ($_POST['description'] ?? ''),
        'role' => (string) ($_POST['role'] ?? ''),
    ];
}

AP_Admin::consumeQueryNotice();

$ap_admin_title = 'Add New User';
$ap_admin_screen = 'users';
$ap_admin_body_class = 'ap-user-new-php';

require __DIR__ . '/admin-header.php';
?>
<div class="ap-page-header">
    <h1>Add New User</h1>
    <a class="button" href="<?php echo ap_esc_url(AP_Admin::url('users.php')); ?>">← All Users</a>
</div>

<?php
echo AP_Admin_User_Edit::renderForm(null, 'create', $actorId, $extra);
require __DIR__ . '/admin-footer.php';
