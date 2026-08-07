<?php

/**
 * Profile (`profile.php`) — current user account settings.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

require __DIR__ . '/admin-bootstrap.php';

// Profile: any logged-in user with `read` may edit their own account.
AP_Admin::requireCapability('read');

$actorId = ap_get_current_user_id();
$user = ap_get_current_user();
if ($actorId < 1 || $user === null) {
    AP_Admin::denyAccess('You must be logged in to view your profile.');
}

$extra = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $result = AP_Admin_User_Edit::save($_POST, $actorId, 'profile', null, $_FILES);
    if ($result['ok']) {
        // Password change may have invalidated the session; re-auth is required later
        // Password change already revokes sessions via AP_User::updatePassword.
        if (!ap_is_user_logged_in()) {
            AP_Admin::redirect(AP_Admin::url('login.php', [
                'redirect_to' => AP_Admin::url('profile.php'),
                'message' => 'profile_updated',
            ]));
        }
        AP_Admin::redirect(AP_Admin::url('profile.php', [
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
        'location' => (string) ($_POST['location'] ?? ''),
        'signature' => (string) ($_POST['signature'] ?? ''),
    ];
    $user->user_email = ap_sanitize_text_field($extra['user_email']);
    $user->user_url = (string) $extra['user_url'];
    $user->display_name = ap_sanitize_text_field($extra['display_name']);
}

AP_Admin::consumeQueryNotice();

$ap_admin_title = 'Profile';
$ap_admin_screen = 'profile';
$ap_admin_body_class = 'ap-profile-php';

require __DIR__ . '/admin-header.php';
?>
<div class="ap-page-header">
    <h1>Profile</h1>
</div>

<?php
echo AP_Admin_User_Edit::renderForm($user, 'profile', $actorId, $extra);
require __DIR__ . '/admin-footer.php';
