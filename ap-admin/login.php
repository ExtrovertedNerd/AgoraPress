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
                    // Prefer rate-limit / session messages from the login layer.
                    $loginError = class_exists('AP_Session', false)
                        ? AP_Session::getLastLoginError()
                        : null;
                    if (
                        is_array($loginError)
                        && ($loginError['code'] ?? '') === 'rate_limited'
                        && ($loginError['message'] ?? '') !== ''
                    ) {
                        $errors[] = (string) $loginError['message'];
                    } else {
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
                            $errors[] = is_array($loginError) && ($loginError['message'] ?? '') !== ''
                                ? (string) $loginError['message']
                                : 'Invalid username or password.';
                        }
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

$loginHtmlLang = function_exists('ap_get_html_lang') ? ap_get_html_lang() : 'en';
$loginTextDir = function_exists('ap_get_text_direction') ? ap_get_text_direction() : 'ltr';
$loginBodyClass = 'ap-admin ap-admin-login ' . ($loginTextDir === 'rtl' ? 'rtl' : 'ltr');
?><!DOCTYPE html>
<html
    lang="<?php echo ap_esc_attr($loginHtmlLang); ?>"
    dir="<?php echo ap_esc_attr($loginTextDir); ?>"
    data-ap-color-mode-pref="auto"
>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="color-scheme" content="light dark">
    <title><?php echo ap_esc_html($pageTitle); ?> ‹ AgoraPress</title>
    <link rel="stylesheet" href="<?php echo ap_esc_url($cssUrl); ?>?v=<?php echo ap_esc_attr($version); ?>">
    <script>
    (function () {
        try {
            var key = 'ap_admin_color_mode';
            var stored = null;
            try { stored = localStorage.getItem(key); } catch (e) {}
            var mode = (stored === 'light' || stored === 'dark' || stored === 'auto') ? stored : 'auto';
            document.documentElement.setAttribute('data-ap-color-mode', mode);
            var meta = document.querySelector('meta[name="color-scheme"]');
            if (meta) {
                meta.setAttribute('content', mode === 'auto' ? 'light dark' : mode);
            }
        } catch (e) {}
    })();
    </script>
</head>
<body class="<?php echo ap_esc_attr($loginBodyClass); ?>">
    <button
        type="button"
        class="ap-color-mode-toggle ap-login-color-toggle"
        id="ap-color-mode-toggle"
        aria-label="Color mode: System. Click to change."
        title="Color mode (System / Light / Dark)"
    >
        <svg class="ap-color-mode-icon ap-color-mode-icon--sun" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
             aria-hidden="true" focusable="false">
            <circle cx="12" cy="12" r="4"></circle>
            <path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"></path>
        </svg>
        <svg class="ap-color-mode-icon ap-color-mode-icon--moon" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
             aria-hidden="true" focusable="false">
            <path d="M21 14.5A8.5 8.5 0 1 1 9.5 3a7 7 0 0 0 11.5 11.5z"></path>
        </svg>
        <svg class="ap-color-mode-icon ap-color-mode-icon--auto" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
             aria-hidden="true" focusable="false">
            <rect x="2" y="4" width="20" height="14" rx="2"></rect>
            <path d="M8 21h8M12 18v3"></path>
        </svg>
    </button>
    <main class="ap-login-box">
        <h1>AgoraPress</h1>
        <p class="ap-login-tagline">Sign in to the control panel</p>
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
    <script>
    (function () {
        var COLOR_KEY = 'ap_admin_color_mode';
        var COLOR_ORDER = ['auto', 'light', 'dark'];
        var COLOR_LABELS = { auto: 'System', light: 'Light', dark: 'Dark' };

        function readMode() {
            var attr = document.documentElement.getAttribute('data-ap-color-mode');
            if (attr === 'light' || attr === 'dark' || attr === 'auto') {
                return attr;
            }
            try {
                var s = localStorage.getItem(COLOR_KEY);
                if (s === 'light' || s === 'dark' || s === 'auto') {
                    return s;
                }
            } catch (e) {}
            return 'auto';
        }

        function apply(mode) {
            if (COLOR_ORDER.indexOf(mode) === -1) {
                mode = 'auto';
            }
            document.documentElement.setAttribute('data-ap-color-mode', mode);
            try { localStorage.setItem(COLOR_KEY, mode); } catch (e) {}
            var meta = document.querySelector('meta[name="color-scheme"]');
            if (meta) {
                meta.setAttribute('content', mode === 'auto' ? 'light dark' : mode);
            }
            var btn = document.getElementById('ap-color-mode-toggle');
            if (btn) {
                var label = COLOR_LABELS[mode] || 'System';
                btn.setAttribute('aria-label', 'Color mode: ' + label + '. Click to change.');
                btn.setAttribute('title', 'Color mode: ' + label);
            }
        }

        apply(readMode());
        var toggle = document.getElementById('ap-color-mode-toggle');
        if (toggle) {
            toggle.addEventListener('click', function () {
                var i = COLOR_ORDER.indexOf(readMode());
                apply(COLOR_ORDER[(i < 0 ? 0 : i + 1) % COLOR_ORDER.length]);
            });
        }
    })();
    </script>
</body>
</html>
