<?php

/**
 * Admin login / logout.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

$ap_admin_skip_auth = true;
require __DIR__ . '/admin-bootstrap.php';

$action = (string) ($_REQUEST['action'] ?? 'login');
$redirectTo = AP_Admin::sanitizeRedirect((string) ($_REQUEST['redirect_to'] ?? AP_Admin::url('index.php')));

if ($action === 'logout') {
    // Optional nonce later; logging out is idempotent and low risk.
    ap_logout();
    AP_Admin::redirect(AP_Admin::url('login.php', ['loggedout' => '1']));
}

// Already logged in → dashboard (or redirect_to).
if (ap_is_user_logged_in()) {
    AP_Admin::redirect($redirectTo);
}

$errors = [];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $nonce = (string) ($_POST['_ap_nonce'] ?? '');
    if (!ap_check_nonce($nonce, 'admin-login', 0)) {
        $errors[] = 'Security check failed. Please try again.';
    } else {
        $login = trim((string) ($_POST['log'] ?? ''));
        $password = (string) ($_POST['pwd'] ?? '');
        $remember = !empty($_POST['rememberme']);
        if ($login === '' || $password === '') {
            $errors[] = 'Username and password are required.';
        } else {
            $user = ap_login($login, $password, $remember);
            if ($user === null) {
                $errors[] = 'Invalid username or password.';
            } else {
                AP_Admin::redirect($redirectTo);
            }
        }
    }
}

$loggedOut = isset($_GET['loggedout']);
$version = defined('AP_VERSION') ? (string) AP_VERSION : '';
$cssUrl = AP_Admin::url('css/admin.css');

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Log In ‹ AgoraPress</title>
    <link rel="stylesheet" href="<?php echo ap_esc_url($cssUrl); ?>?v=<?php echo ap_esc_attr($version); ?>">
</head>
<body class="ap-admin ap-admin-login">
    <main class="ap-login-box">
        <h1>AgoraPress</h1>
        <?php if ($loggedOut) : ?>
            <div class="ap-notice ap-notice--success">You are now logged out.</div>
        <?php endif; ?>
        <?php foreach ($errors as $err) : ?>
            <div class="ap-notice ap-notice--error"><?php echo ap_esc_html($err); ?></div>
        <?php endforeach; ?>
        <form method="post" action="<?php echo ap_esc_url(AP_Admin::url('login.php')); ?>">
            <?php echo ap_nonce_field('admin-login', '_ap_nonce', false, 0); ?>
            <input type="hidden" name="redirect_to" value="<?php echo ap_esc_attr($redirectTo); ?>" />
            <div class="ap-field">
                <label for="user_login">Username or Email</label>
                <input type="text" name="log" id="user_login" autocomplete="username" required
                       value="<?php echo ap_esc_attr((string) ($_POST['log'] ?? '')); ?>" />
            </div>
            <div class="ap-field">
                <label for="user_pass">Password</label>
                <input type="password" name="pwd" id="user_pass" autocomplete="current-password" required />
            </div>
            <div class="ap-field">
                <label class="ap-remember">
                    <input type="checkbox" name="rememberme" value="1" />
                    Remember me
                </label>
            </div>
            <button type="submit" class="button button-primary">Log In</button>
        </form>
    </main>
</body>
</html>
