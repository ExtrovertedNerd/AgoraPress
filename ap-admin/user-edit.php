<?php

/**
 * Edit User (`user-edit.php?user_id=ID`).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

require __DIR__ . '/admin-bootstrap.php';

$actorId = ap_get_current_user_id();
$targetId = (int) ($_REQUEST['user_id'] ?? $_REQUEST['user'] ?? 0);

if ($targetId < 1) {
    AP_Admin::redirect(AP_Admin::url('users.php', ['message' => 'not_found']));
}

// Own profile shortcut when the user lacks edit_users.
if ($targetId === $actorId && !AP_Admin::currentUserCan('edit_users')) {
    AP_Admin::redirect(AP_Admin::url('profile.php'));
}

AP_Admin::requireCapability('edit_users');

$user = AP_User::getById($targetId);
if ($user === null) {
    AP_Admin::redirect(AP_Admin::url('users.php', ['message' => 'not_found']));
}

$extra = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $result = AP_Admin_User_Edit::save($_POST, $actorId, 'update', null, $_FILES);
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
        'user_email' => (string) ($_POST['user_email'] ?? $user->user_email),
        'user_url' => (string) ($_POST['user_url'] ?? $user->user_url),
        'display_name' => (string) ($_POST['display_name'] ?? $user->display_name),
        'first_name' => (string) ($_POST['first_name'] ?? ''),
        'last_name' => (string) ($_POST['last_name'] ?? ''),
        'nickname' => (string) ($_POST['nickname'] ?? ''),
        'description' => (string) ($_POST['description'] ?? ''),
        'role' => (string) ($_POST['role'] ?? ''),
    ];
    // Keep object fields in sync for redisplay.
    $user->user_email = ap_sanitize_text_field($extra['user_email']);
    $user->user_url = (string) $extra['user_url'];
    $user->display_name = ap_sanitize_text_field($extra['display_name']);
}

AP_Admin::consumeQueryNotice();

$ap_admin_title = 'Edit User';
$ap_admin_screen = 'users';
$ap_admin_body_class = 'ap-user-edit-php';

require __DIR__ . '/admin-header.php';
?>
<div class="ap-page-header">
    <h1>Edit User</h1>
    <a class="button" href="<?php echo ap_esc_url(AP_Admin::url('users.php')); ?>">← All Users</a>
    <?php if (AP_Admin::currentUserCan('create_users')) : ?>
        <a class="button button-primary" href="<?php echo ap_esc_url(AP_Admin::url('user-new.php')); ?>">
            Add New
        </a>
    <?php endif; ?>
</div>

<?php
echo AP_Admin_User_Edit::renderForm($user, 'update', $actorId, $extra);
require __DIR__ . '/admin-footer.php';
