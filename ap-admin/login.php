<?php

/**
 * Admin login, registration, email verification, and password reset.
 *
 * Actions (via ?action=):
 * - login (default)
 * - logout
 * - register
 * - lostpassword
 * - rp | resetpass  (set new password with key)
 * - verifyemail
 *
 * @package AgoraPress
 */

declare(strict_types=1);

$ap_admin_skip_auth = true;
require __DIR__ . '/admin-bootstrap.php';

$action = (string) ($_REQUEST['action'] ?? 'login');
// Aliases.
if ($action === 'resetpass') {
    $action = 'rp';
}
$allowed = ['login', 'logout', 'register', 'lostpassword', 'rp', 'verifyemail'];
if (!in_array($action, $allowed, true)) {
    $action = 'login';
}

$redirectTo = AP_Admin::sanitizeRedirect((string) ($_REQUEST['redirect_to'] ?? AP_Admin::url('index.php')));

if ($action === 'logout') {
    // CSRF-protect logout so a third-party page cannot force-sign-out a user.
    $logoutNonce = (string) ($_REQUEST['_ap_nonce'] ?? $_REQUEST['_wpnonce'] ?? '');
    $uid = ap_get_current_user_id();
    if ($logoutNonce === '' || !ap_check_nonce($logoutNonce, 'log-out', $uid > 0 ? $uid : null)) {
        AP_Admin::redirect(AP_Admin::url('login.php', ['message' => 'nonce']));
    }
    ap_logout();
    AP_Admin::redirect(AP_Admin::url('login.php', ['loggedout' => '1']));
}

// Already logged in → dashboard (except verification links).
if ($action !== 'verifyemail' && ap_is_user_logged_in()) {
    AP_Admin::redirect($redirectTo);
}

$errors = [];
$messages = [];
$canRegister = ap_users_can_register();

// ---------------------------------------------------------------------------
// POST handlers
// ---------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $nonce = (string) ($_POST['_ap_nonce'] ?? '');

    if ($action === 'login') {
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
                    // Distinguish pending verification when possible (same generic otherwise).
                    $pending = AP_User::getByLogin($login);
                    if ($pending === null && str_contains($login, '@')) {
                        $pending = AP_User::getByEmail($login);
                    }
                    if (
                        $pending !== null
                        && $pending->user_status === AP_Registration::STATUS_PENDING
                        && AP_User::checkPassword($password, $pending->user_pass)
                    ) {
                        $errors[] = 'Please verify your email address before logging in.'
                            . ' Check your inbox for the confirmation link.';
                    } else {
                        $errors[] = 'Invalid username or password.';
                    }
                } else {
                    AP_Admin::redirect($redirectTo);
                }
            }
        }
    } elseif ($action === 'register') {
        if (!$canRegister) {
            $errors[] = 'Registration is currently closed.';
        } elseif (!ap_check_nonce($nonce, 'admin-register', 0)) {
            $errors[] = 'Security check failed. Please try again.';
        } else {
            $password = (string) ($_POST['user_pass'] ?? '');
            $password2 = (string) ($_POST['user_pass2'] ?? '');
            if ($password !== $password2) {
                $errors[] = 'Passwords do not match.';
            } else {
                $result = ap_register_user([
                    'user_login' => (string) ($_POST['user_login'] ?? ''),
                    'user_email' => (string) ($_POST['user_email'] ?? ''),
                    'user_pass' => $password,
                    'display_name' => (string) ($_POST['display_name'] ?? ''),
                ]);
                if (!$result['ok']) {
                    $errors = array_merge($errors, $result['errors']);
                } elseif ($result['needs_verification']) {
                    AP_Admin::redirect(AP_Admin::url('login.php', [
                        'checkemail' => 'confirm',
                    ]));
                } else {
                    AP_Admin::redirect(AP_Admin::url('login.php', [
                        'registered' => '1',
                    ]));
                }
            }
        }
    } elseif ($action === 'lostpassword') {
        if (!ap_check_nonce($nonce, 'admin-lostpassword', 0)) {
            $errors[] = 'Security check failed. Please try again.';
        } else {
            $loginOrEmail = trim((string) ($_POST['user_login'] ?? ''));
            $result = ap_request_password_reset($loginOrEmail);
            if (!$result['ok']) {
                $errors = array_merge($errors, $result['errors']);
            } else {
                AP_Admin::redirect(AP_Admin::url('login.php', [
                    'checkemail' => 'confirm_reset',
                ]));
            }
        }
    } elseif ($action === 'rp') {
        if (!ap_check_nonce($nonce, 'admin-resetpass', 0)) {
            $errors[] = 'Security check failed. Please try again.';
        } else {
            $login = trim((string) ($_POST['login'] ?? $_GET['login'] ?? ''));
            $key = trim((string) ($_POST['key'] ?? $_GET['key'] ?? ''));
            $pass1 = (string) ($_POST['pass1'] ?? '');
            $pass2 = (string) ($_POST['pass2'] ?? '');
            if ($pass1 !== $pass2) {
                $errors[] = 'Passwords do not match.';
            } else {
                $result = ap_reset_password($login, $key, $pass1);
                if (!$result['ok']) {
                    $errors = array_merge($errors, $result['errors']);
                } else {
                    AP_Admin::redirect(AP_Admin::url('login.php', [
                        'password' => 'changed',
                    ]));
                }
            }
        }
    }
}

// ---------------------------------------------------------------------------
// GET handlers (email verification)
// ---------------------------------------------------------------------------
if ($action === 'verifyemail' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    $login = trim((string) ($_GET['login'] ?? ''));
    $key = trim((string) ($_GET['key'] ?? ''));
    $result = ap_verify_user_email($login, $key);
    if ($result['ok']) {
        AP_Admin::redirect(AP_Admin::url('login.php', ['verified' => '1']));
    }
    $errors = array_merge($errors, $result['errors']);
}

// Flash / query messages for the login form.
$loggedOut = isset($_GET['loggedout']);
$checkEmail = (string) ($_GET['checkemail'] ?? '');
$registered = isset($_GET['registered']);
$verified = isset($_GET['verified']);
$passwordChanged = isset($_GET['password']) && (string) $_GET['password'] === 'changed';
$nonceFailed = isset($_GET['message']) && (string) $_GET['message'] === 'nonce';

if ($nonceFailed) {
    $errors[] = 'Security check failed. Please use the Log out link from the admin bar.';
}
if ($loggedOut) {
    $messages[] = 'You are now logged out.';
}
if ($checkEmail === 'confirm') {
    $messages[] = 'Registration complete. Please check your email to verify your account'
        . ' before logging in.';
}
if ($checkEmail === 'confirm_reset') {
    $messages[] = 'If an account exists for that username or email, you will receive'
        . ' password reset instructions shortly.';
}
if ($registered) {
    $messages[] = 'Registration complete. You may now log in.';
}
if ($verified) {
    $messages[] = 'Your email has been verified. You may now log in.';
}
if ($passwordChanged) {
    $messages[] = 'Your password has been changed. You may now log in.';
}

// Reset form: validate key on GET so we can show errors early.
$rpLogin = trim((string) ($_REQUEST['login'] ?? ''));
$rpKey = trim((string) ($_REQUEST['key'] ?? ''));
$rpUser = null;
if ($action === 'rp') {
    $rpUser = ap_check_password_reset_key($rpLogin, $rpKey);
    if ($rpUser === null && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        $errors[] = 'This password reset link is invalid or has expired.';
    }
}

$version = defined('AP_VERSION') ? (string) AP_VERSION : '';
$cssUrl = AP_Admin::url('css/admin.css');

$pageTitle = match ($action) {
    'register' => 'Register',
    'lostpassword' => 'Lost Password',
    'rp' => 'Reset Password',
    'verifyemail' => 'Verify Email',
    default => 'Log In',
};

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?php echo ap_esc_html($pageTitle); ?> ‹ AgoraPress</title>
    <link rel="stylesheet" href="<?php echo ap_esc_url($cssUrl); ?>?v=<?php echo ap_esc_attr($version); ?>">
</head>
<body class="ap-admin ap-admin-login">
    <main class="ap-login-box">
        <h1>AgoraPress</h1>
        <?php foreach ($messages as $msg) : ?>
            <div class="ap-notice ap-notice--success"><?php echo ap_esc_html($msg); ?></div>
        <?php endforeach; ?>
        <?php foreach ($errors as $err) : ?>
            <div class="ap-notice ap-notice--error"><?php echo ap_esc_html($err); ?></div>
        <?php endforeach; ?>

        <?php if ($action === 'login') : ?>
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
            <p class="ap-login-links">
                <a href="<?php echo ap_esc_url(AP_Admin::url('login.php', ['action' => 'lostpassword'])); ?>">Lost your password?</a>
                <?php if ($canRegister) : ?>
                    <span class="ap-login-sep">|</span>
                    <a href="<?php echo ap_esc_url(AP_Admin::url('login.php', ['action' => 'register'])); ?>">Register</a>
                <?php endif; ?>
            </p>

        <?php elseif ($action === 'register') : ?>
            <?php if (!$canRegister) : ?>
                <p>Registration is currently closed.</p>
                <p class="ap-login-links">
                    <a href="<?php echo ap_esc_url(AP_Admin::url('login.php')); ?>">← Back to log in</a>
                </p>
            <?php else : ?>
                <form method="post" action="<?php echo ap_esc_url(AP_Admin::url('login.php', ['action' => 'register'])); ?>">
                    <?php echo ap_nonce_field('admin-register', '_ap_nonce', false, 0); ?>
                    <div class="ap-field">
                        <label for="reg_user_login">Username</label>
                        <input type="text" name="user_login" id="reg_user_login" autocomplete="username" required
                               value="<?php echo ap_esc_attr((string) ($_POST['user_login'] ?? '')); ?>" />
                    </div>
                    <div class="ap-field">
                        <label for="reg_user_email">Email</label>
                        <input type="email" name="user_email" id="reg_user_email" autocomplete="email" required
                               value="<?php echo ap_esc_attr((string) ($_POST['user_email'] ?? '')); ?>" />
                    </div>
                    <div class="ap-field">
                        <label for="reg_display_name">Display name <span class="ap-optional">(optional)</span></label>
                        <input type="text" name="display_name" id="reg_display_name" autocomplete="name"
                               value="<?php echo ap_esc_attr((string) ($_POST['display_name'] ?? '')); ?>" />
                    </div>
                    <div class="ap-field">
                        <label for="reg_user_pass">Password</label>
                        <input type="password" name="user_pass" id="reg_user_pass" autocomplete="new-password" required minlength="8" />
                    </div>
                    <div class="ap-field">
                        <label for="reg_user_pass2">Confirm password</label>
                        <input type="password" name="user_pass2" id="reg_user_pass2" autocomplete="new-password" required minlength="8" />
                    </div>
                    <button type="submit" class="button button-primary">Register</button>
                </form>
                <p class="ap-login-links">
                    <a href="<?php echo ap_esc_url(AP_Admin::url('login.php')); ?>">← Back to log in</a>
                </p>
            <?php endif; ?>

        <?php elseif ($action === 'lostpassword') : ?>
            <p class="ap-login-hint">Enter your username or email and we will send reset instructions if an account exists.</p>
            <form method="post" action="<?php echo ap_esc_url(AP_Admin::url('login.php', ['action' => 'lostpassword'])); ?>">
                <?php echo ap_nonce_field('admin-lostpassword', '_ap_nonce', false, 0); ?>
                <div class="ap-field">
                    <label for="lost_user_login">Username or Email</label>
                    <input type="text" name="user_login" id="lost_user_login" autocomplete="username" required
                           value="<?php echo ap_esc_attr((string) ($_POST['user_login'] ?? '')); ?>" />
                </div>
                <button type="submit" class="button button-primary">Get New Password</button>
            </form>
            <p class="ap-login-links">
                <a href="<?php echo ap_esc_url(AP_Admin::url('login.php')); ?>">← Back to log in</a>
                <?php if ($canRegister) : ?>
                    <span class="ap-login-sep">|</span>
                    <a href="<?php echo ap_esc_url(AP_Admin::url('login.php', ['action' => 'register'])); ?>">Register</a>
                <?php endif; ?>
            </p>

        <?php elseif ($action === 'rp') : ?>
            <?php if ($rpUser !== null) : ?>
                <form method="post" action="<?php echo ap_esc_url(AP_Admin::url('login.php', [
                    'action' => 'rp',
                    'login' => $rpLogin,
                    'key' => $rpKey,
                ])); ?>">
                    <?php echo ap_nonce_field('admin-resetpass', '_ap_nonce', false, 0); ?>
                    <input type="hidden" name="login" value="<?php echo ap_esc_attr($rpLogin); ?>" />
                    <input type="hidden" name="key" value="<?php echo ap_esc_attr($rpKey); ?>" />
                    <div class="ap-field">
                        <label for="pass1">New password</label>
                        <input type="password" name="pass1" id="pass1" autocomplete="new-password" required minlength="8" />
                    </div>
                    <div class="ap-field">
                        <label for="pass2">Confirm new password</label>
                        <input type="password" name="pass2" id="pass2" autocomplete="new-password" required minlength="8" />
                    </div>
                    <button type="submit" class="button button-primary">Reset Password</button>
                </form>
            <?php endif; ?>
            <p class="ap-login-links">
                <a href="<?php echo ap_esc_url(AP_Admin::url('login.php')); ?>">← Back to log in</a>
            </p>

        <?php else : /* verifyemail failure path (success redirects) */ ?>
            <p class="ap-login-links">
                <a href="<?php echo ap_esc_url(AP_Admin::url('login.php')); ?>">← Back to log in</a>
            </p>
        <?php endif; ?>
    </main>
</body>
</html>
