"""
Smoke tests for AgoraPress registration, email verification, and password reset.

Runnable via:
  pytest tests/test_registration.py -v
"""

from __future__ import annotations

import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
REG_CLASS = ROOT / "ap-includes" / "class-ap-registration.php"
MAIL_CLASS = ROOT / "ap-includes" / "class-ap-mail.php"
FUNCTIONS = ROOT / "ap-includes" / "functions.php"
BOOTSTRAP = ROOT / "ap-includes" / "bootstrap.php"
LOGIN = ROOT / "ap-admin" / "login.php"
INSTALLER = ROOT / "ap-includes" / "class-ap-installer.php"


def _php_bin() -> str:
    return shutil.which("php") or "php"


def test_registration_files_exist() -> None:
    assert REG_CLASS.is_file(), "Missing class-ap-registration.php"
    assert MAIL_CLASS.is_file(), "Missing class-ap-mail.php"
    assert LOGIN.is_file()


def test_registration_class_defines_api() -> None:
    src = REG_CLASS.read_text(encoding="utf-8")
    for needle in (
        "class AP_Registration",
        "STATUS_PENDING",
        "function register",
        "function verifyEmail",
        "function requestPasswordReset",
        "function checkPasswordResetKey",
        "function resetPassword",
        "function resendVerification",
        "function resendVerificationForUser",
        "function activatePendingUser",
        "function userAwaitsVerification",
        "function issueKey",
        "function validateKey",
        "mail_sent",
        "function usersCanRegister",
        "function requireEmailVerification",
        "function captchaMode",
        "function isCaptchaEnabled",
        "function createMathChallenge",
        "function verifyCaptcha",
        "function createFormTicket",
        "function formTicketForDisplay",
        "function verifyFormGate",
        "RESERVED_LOGINS",
        "OPTION_RESERVED_USERNAMES",
        "function isReservedLogin",
        "function reservedLogins",
        "function extraReservedLogins",
        "function parseReservedUsernameList",
        "ap_reserved_usernames",
        "USERNAME_UNAVAILABLE_MESSAGE",
        "That username is not available.",
        "function reservedLoginUnavailableMessage",
        "function publicUsernameErrors",
        "MIN_FILL_SECONDS",
        "FORM_TICKET_TTL",
        "ap_form_ticket",
        "CAPTCHA_OFF",
        "CAPTCHA_MATH",
        "CAPTCHA_GUARD",
        "createGuardChallenge",
        "GUARD_POW_PREFIX",
        "ap_guard_ack",
        "registration_captcha",
        "PURPOSE_ACTIVATE",
        "PURPOSE_RESET",
        "hash_hmac",
        "This link expires in 24 hours.",
        "spam folder",
        "sending server may be new",
        "function mailLinkNotice",
    ):
        assert needle in src, f"Expected {needle!r} in class-ap-registration.php"
    assert "That username is not available." in src
    const_line = next(
        ln for ln in src.splitlines() if "USERNAME_UNAVAILABLE_MESSAGE" in ln and "=" in ln
    )
    assert "reserved" not in const_line.lower()


def test_mail_class_defines_api() -> None:
    src = MAIL_CLASS.read_text(encoding="utf-8")
    for needle in (
        "class AP_Mail",
        "function send",
        "function enableTestMode",
        "function getTestOutbox",
        "function failNextForTests",
    ):
        assert needle in src, f"Expected {needle!r} in class-ap-mail.php"


def test_functions_expose_registration_helpers() -> None:
    src = FUNCTIONS.read_text(encoding="utf-8")
    for needle in (
        "function ap_users_can_register",
        "function ap_require_email_verification",
        "function ap_registration_captcha_mode",
        "function ap_registration_captcha_enabled",
        "function ap_registration_create_captcha",
        "function ap_registration_verify_captcha",
        "function ap_registration_create_form_ticket",
        "function ap_registration_form_ticket_for_display",
        "function ap_registration_verify_form_gate",
        "function ap_is_reserved_login",
        "function ap_register_user",
        "function ap_verify_user_email",
        "function ap_request_password_reset",
        "function ap_check_password_reset_key",
        "function ap_reset_password",
        "function ap_resend_user_verification",
        "function ap_activate_pending_user",
        "function ap_mail",
    ):
        assert needle in src, f"Expected {needle!r} in functions.php"


def test_bootstrap_loads_registration_classes() -> None:
    src = BOOTSTRAP.read_text(encoding="utf-8")
    assert "class-ap-mail.php" in src
    assert "class-ap-registration.php" in src


def test_login_handles_register_and_reset_actions() -> None:
    src = LOGIN.read_text(encoding="utf-8")
    for needle in (
        "register",
        "lostpassword",
        "verifyemail",
        "ap_register_user",
        "ap_request_password_reset",
        "ap_reset_password",
        "ap_verify_user_email",
        "ap_resend_user_verification",
        "resend",
        "mail_sent",
        "Resend verification",
        "confirm_reset",
        "use Resend verification",
        "captcha_answer",
        "captcha_token",
        "ap_hp",
        "ap_form_ticket",
        "ap_guard_ack",
        "ap-human-check",
        "Human check",
        "register-guard.js",
        "formTicketForDisplay",
        "ap_registration_captcha_enabled",
        "empty($result['mail_sent'])",
        "Please check your email to verify your account",
        "publicUsernameErrors",
    ):
        assert needle in src, f"Expected {needle!r} in login.php"
    confirm_if = src.find("if ($checkEmail === 'confirm')")
    flash = src.find("Please check your email to verify your account")
    mail_sent = src.find("empty($result['mail_sent'])")
    confirm_redirect = src.find("'checkemail' => 'confirm'")
    assert confirm_if != -1 and flash != -1
    assert confirm_if < flash
    assert mail_sent != -1 and confirm_redirect != -1
    assert mail_sent < confirm_redirect
    assert "reserved" not in src.lower()


def test_register_guard_js_is_first_party() -> None:
    js = ROOT / "ap-admin" / "js" / "register-guard.js"
    assert js.is_file()
    src = js.read_text(encoding="utf-8")
    assert "crypto.subtle" in src
    assert "SHA-256" in src
    assert "pow:" in src
    assert "ap-register-guard" in src
    lower = src.lower()
    for banned in ("recaptcha", "hcaptcha", "turnstile", "googleapis", "cloudflare"):
        assert banned not in lower, f"third-party widget reference {banned!r}"


def test_general_settings_exposes_registration_captcha() -> None:
    general = ROOT / "ap-admin" / "options-general.php"
    src = general.read_text(encoding="utf-8")
    assert "registration_captcha" in src
    assert "Human check — math question" in src
    assert "Human check — checkbox card" in src
    assert 'value="guard"' in src
    assert "short-lived form ticket" in src
    assert "hidden honeypot" in src
    assert "Turnstile" in src
    assert 'name="reserved_usernames"' in src
    assert "One username per line" in src
    assert "That username is not available." in src
    assert "does not say a name is reserved" in src
    assert "Users → Add" in src
    assert "ap-cli user create" in src


def test_settings_sanitizer_accepts_guard() -> None:
    settings = (ROOT / "ap-includes" / "class-ap-settings.php").read_text(encoding="utf-8")
    options = (ROOT / "ap-includes" / "class-ap-options.php").read_text(encoding="utf-8")
    assert "function sanitizeRegistrationCaptcha" in settings
    assert "sanitizeRegistrationCaptcha" in settings
    assert "['off', 'math', 'guard']" in settings
    assert "['off', 'math', 'guard']" in options
    assert "ap_registration_captcha_mode" in settings
    assert "ap_registration_verify_captcha" in settings


def test_installer_seeds_registration_captcha_off() -> None:
    src = INSTALLER.read_text(encoding="utf-8")
    assert "registration_captcha" in src
    assert "'registration_captcha' => 'off'" in src
    assert "reserved_usernames" in src
    assert "'reserved_usernames' => ''" in src


def test_installer_seeds_email_verification_option() -> None:
    src = INSTALLER.read_text(encoding="utf-8")
    assert "require_email_verification" in src
    assert "users_can_register" in src


def test_register_verify_reset_via_php() -> None:
    """End-to-end runtime check on SQLite in-memory."""
    code = (
        "<?php\ndeclare(strict_types=1);\n"
        "$root = getenv('AP_ROOT') ?: '';\n"
        "require_once $root . '/ap-includes/version.php';\n"
        "require_once $root . '/ap-includes/class-ap-db.php';\n"
        "require_once $root . '/ap-includes/class-ap-migrator.php';\n"
        "require_once $root . '/ap-includes/class-ap-options.php';\n"
        "require_once $root . '/ap-includes/class-ap-user.php';\n"
        "require_once $root . '/ap-includes/class-ap-session.php';\n"
        "require_once $root . '/ap-includes/class-ap-roles.php';\n"
        "require_once $root . '/ap-includes/class-ap-mail.php';\n"
        "require_once $root . '/ap-includes/class-ap-registration.php';\n"
        "require_once $root . '/ap-includes/functions.php';\n"
        "if (!defined('AP_AUTH_KEY')) define('AP_AUTH_KEY', 'k' . str_repeat('1', 40));\n"
        "if (!defined('AP_AUTH_SALT')) define('AP_AUTH_SALT', 's' . str_repeat('2', 40));\n"
        "if (!defined('AP_SITEURL')) define('AP_SITEURL', 'https://example.test');\n"
        "$pdo = new PDO('sqlite::memory:');\n"
        "$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);\n"
        "$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_OBJ);\n"
        "$db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');\n"
        "$m = new AP_Migrator($db, AP_Migrator::defaultMigrationsPath());\n"
        "$m->migrate();\n"
        "AP_Roles::ensureDefaults($db);\n"
        "AP_Options::flushCache();\n"
        "foreach ([\n"
        "  'users_can_register' => '1',\n"
        "  'require_email_verification' => '1',\n"
        "  'default_role' => 'subscriber',\n"
        "  'blogname' => 'PyTest',\n"
        "  'admin_email' => 'admin@example.test',\n"
        "] as $n => $v) { AP_Options::update($n, $v, $db); }\n"
        "AP_Mail::enableTestMode();\n"
        "$ticket = AP_Registration::createFormTicket(time() - AP_Registration::MIN_FILL_SECONDS);\n"
        "$reg = AP_Registration::register([\n"
        "  'user_login' => 'pytestuser',\n"
        "  'user_email' => 'pytest@example.test',\n"
        "  'user_pass' => 'pytest-secret-1',\n"
        "  'ap_form_ticket' => $ticket['token'],\n"
        "  'ap_hp' => '',\n"
        "], $db);\n"
        "if (!$reg['ok']) { fwrite(STDERR, 'reg: ' . implode(',', $reg['errors']) . \"\\n\"); exit(1); }\n"
        "if (!$reg['needs_verification']) { fwrite(STDERR, \"expected verification\\n\"); exit(2); }\n"
        "if (AP_User::authenticate('pytestuser', 'pytest-secret-1', $db) !== null) {\n"
        "  fwrite(STDERR, \"pending should not auth\\n\"); exit(3);\n"
        "}\n"
        "$ver = AP_Registration::verifyEmail('pytestuser', $reg['plain_key'], $db);\n"
        "if (!$ver['ok']) { fwrite(STDERR, \"verify failed\\n\"); exit(4); }\n"
        "if (AP_User::authenticate('pytestuser', 'pytest-secret-1', $db) === null) {\n"
        "  fwrite(STDERR, \"post-verify auth failed\\n\"); exit(5);\n"
        "}\n"
        "$req = AP_Registration::requestPasswordReset('pytestuser', $db);\n"
        "if (!$req['sent']) { fwrite(STDERR, \"reset not sent\\n\"); exit(6); }\n"
        "$rp = AP_Registration::resetPassword('pytestuser', $req['plain_key'], 'pytest-secret-2', $db);\n"
        "if (!$rp['ok']) { fwrite(STDERR, \"reset failed\\n\"); exit(7); }\n"
        "if (AP_User::authenticate('pytestuser', 'pytest-secret-2', $db) === null) {\n"
        "  fwrite(STDERR, \"new password auth failed\\n\"); exit(8);\n"
        "}\n"
        "AP_Mail::failNextForTests('SMTP down');\n"
        "$failTicket = AP_Registration::createFormTicket(time() - AP_Registration::MIN_FILL_SECONDS);\n"
        "$fail = AP_Registration::register([\n"
        "  'user_login' => 'mailfail',\n"
        "  'user_email' => 'mailfail@example.test',\n"
        "  'user_pass' => 'pytest-secret-1',\n"
        "  'ap_form_ticket' => $failTicket['token'],\n"
        "  'ap_hp' => '',\n"
        "], $db);\n"
        "if (!$fail['ok']) { fwrite(STDERR, 'failreg: ' . implode(',', $fail['errors']) . \"\\n\"); exit(9); }\n"
        "if (!empty($fail['mail_sent'])) { fwrite(STDERR, \"mail_sent should be false\\n\"); exit(10); }\n"
        "$failBlob = strtolower(implode(' ', $fail['errors']));\n"
        "if (str_contains($failBlob, 'check your email')) {\n"
        "  fwrite(STDERR, \"failed send claimed check your email\\n\"); exit(14);\n"
        "}\n"
        "$kept = AP_User::getByLogin('mailfail', $db);\n"
        "if ($kept === null || (int) $kept->user_status !== AP_Registration::STATUS_PENDING) {\n"
        "  fwrite(STDERR, \"pending user not kept\\n\"); exit(11);\n"
        "}\n"
        "AP_User::create([\n"
        "  'user_login' => 'resetfail',\n"
        "  'user_email' => 'resetfail@example.test',\n"
        "  'user_pass' => 'pytest-secret-1',\n"
        "  'user_status' => 0,\n"
        "  'role' => 'subscriber',\n"
        "], $db);\n"
        "AP_Mail::failNextForTests('SMTP down');\n"
        "$resetFail = AP_Registration::requestPasswordReset('resetfail', $db);\n"
        "if ($resetFail['ok'] || !empty($resetFail['sent'])) {\n"
        "  fwrite(STDERR, \"reset claimed success on send failure\\n\"); exit(12);\n"
        "}\n"
        "$resend = AP_Registration::resendVerification('mailfail@example.test', $db);\n"
        "if (!$resend['ok'] || empty($resend['sent'])) {\n"
        "  fwrite(STDERR, 'resend: ' . implode(',', $resend['errors']) . \"\\n\"); exit(13);\n"
        "}\n"
        "$wantUnavailable = AP_Registration::USERNAME_UNAVAILABLE_MESSAGE;\n"
        "if ($wantUnavailable !== 'That username is not available.') {\n"
        "  fwrite(STDERR, 'const copy: ' . $wantUnavailable . \"\\n\"); exit(20);\n"
        "}\n"
        "if (str_contains(strtolower($wantUnavailable), 'reserved')) {\n"
        "  fwrite(STDERR, \"const says reserved\\n\"); exit(21);\n"
        "}\n"
        "$reservedTicket = AP_Registration::createFormTicket(time() - AP_Registration::MIN_FILL_SECONDS);\n"
        "$reserved = AP_Registration::register([\n"
        "  'user_login' => 'Admin',\n"
        "  'user_email' => 'admin-public@example.test',\n"
        "  'user_pass' => 'pytest-secret-1',\n"
        "  'ap_form_ticket' => $reservedTicket['token'],\n"
        "  'ap_hp' => '',\n"
        "], $db);\n"
        "if ($reserved['ok']) { fwrite(STDERR, \"reserved admin registered\\n\"); exit(15); }\n"
        "$reservedBlob = implode(' ', $reserved['errors']);\n"
        "if ($reserved['errors'] !== [$wantUnavailable]) {\n"
        "  fwrite(STDERR, 'reserved copy: ' . $reservedBlob . \"\\n\"); exit(16);\n"
        "}\n"
        "if (str_contains(strtolower($reservedBlob), 'reserved')) {\n"
        "  fwrite(STDERR, \"reserved leak\\n\"); exit(17);\n"
        "}\n"
        "if (AP_User::getByLogin('admin', $db) !== null) {\n"
        "  fwrite(STDERR, \"admin row created from public register\\n\"); exit(18);\n"
        "}\n"
        "$takenTicket = AP_Registration::createFormTicket(time() - AP_Registration::MIN_FILL_SECONDS);\n"
        "$taken = AP_Registration::register([\n"
        "  'user_login' => 'pytestuser',\n"
        "  'user_email' => 'pytest-taken@example.test',\n"
        "  'user_pass' => 'pytest-secret-1',\n"
        "  'ap_form_ticket' => $takenTicket['token'],\n"
        "  'ap_hp' => '',\n"
        "], $db);\n"
        "if ($taken['ok'] || $taken['errors'] !== [$wantUnavailable]) {\n"
        "  fwrite(STDERR, 'taken copy: ' . implode(' ', $taken['errors']) . \"\\n\"); exit(22);\n"
        "}\n"
        "if (str_contains(strtolower(implode(' ', $taken['errors'])), 'reserved')) {\n"
        "  fwrite(STDERR, \"taken leak reserved\\n\"); exit(23);\n"
        "}\n"
        "$staff = AP_User::create([\n"
        "  'user_login' => 'admin',\n"
        "  'user_email' => 'admin-staff@example.test',\n"
        "  'user_pass' => 'pytest-secret-1',\n"
        "  'role' => 'administrator',\n"
        "], $db);\n"
        "if (!$staff['ok']) { fwrite(STDERR, 'staff: ' . implode(',', $staff['errors']) . \"\\n\"); exit(19); }\n"
        "$silas = AP_User::create([\n"
        "  'user_login' => 'Silas',\n"
        "  'user_email' => 'silas-unique@example.test',\n"
        "  'user_pass' => 'pytest-secret-1',\n"
        "], $db);\n"
        "if (!$silas['ok']) { fwrite(STDERR, 'silas: ' . implode(',', $silas['errors']) . \"\\n\"); exit(24); }\n"
        "if (($silas['user']->user_login ?? '') !== 'Silas') {\n"
        "  fwrite(STDERR, \"stored login case changed\\n\"); exit(25);\n"
        "}\n"
        "$silasDup = AP_User::create([\n"
        "  'user_login' => 'silas',\n"
        "  'user_email' => 'silas-dup@example.test',\n"
        "  'user_pass' => 'pytest-secret-1',\n"
        "], $db);\n"
        "if ($silasDup['ok'] || !in_array('That username is already registered.', $silasDup['errors'], true)) {\n"
        "  fwrite(STDERR, 'silas dup: ' . implode(',', $silasDup['errors']) . \"\\n\"); exit(26);\n"
        "}\n"
        "$emailDup = AP_User::create([\n"
        "  'user_login' => 'silas2',\n"
        "  'user_email' => 'Silas-unique@example.test',\n"
        "  'user_pass' => 'pytest-secret-1',\n"
        "], $db);\n"
        "if ($emailDup['ok'] || !in_array('That email address is already registered.', $emailDup['errors'], true)) {\n"
        "  fwrite(STDERR, 'email dup: ' . implode(',', $emailDup['errors']) . \"\\n\"); exit(27);\n"
        "}\n"
        "$silasTicket = AP_Registration::createFormTicket(time() - AP_Registration::MIN_FILL_SECONDS);\n"
        "$silasPub = AP_Registration::register([\n"
        "  'user_login' => 'silas',\n"
        "  'user_email' => 'silas-public@example.test',\n"
        "  'user_pass' => 'pytest-secret-1',\n"
        "  'ap_form_ticket' => $silasTicket['token'],\n"
        "  'ap_hp' => '',\n"
        "], $db);\n"
        "if ($silasPub['ok'] || $silasPub['errors'] !== [$wantUnavailable]) {\n"
        "  fwrite(STDERR, 'silas public: ' . implode(' ', $silasPub['errors']) . \"\\n\"); exit(28);\n"
        "}\n"
        "echo \"OK\\n\";\n"
    )
    with tempfile.NamedTemporaryFile("w", suffix=".php", delete=False) as fh:
        fh.write(code)
        path = fh.name
    try:
        result = subprocess.run(
            [_php_bin(), "-d", "display_errors=1", path],
            cwd=str(ROOT),
            env={**dict(**{k: v for k, v in __import__("os").environ.items()}), "AP_ROOT": str(ROOT)},
            capture_output=True,
            text=True,
            check=False,
        )
    finally:
        Path(path).unlink(missing_ok=True)

    combined = (result.stdout or "") + (result.stderr or "")
    assert result.returncode == 0, combined
    assert "OK" in (result.stdout or "")


def test_register_gate_reserved_names_and_hooks_via_php() -> None:
    """Reserved names, Admin/admin collide, fail-closed gate, pending hook, captcha hooks."""
    code = (
        "<?php\ndeclare(strict_types=1);\n"
        "$root = getenv('AP_ROOT') ?: '';\n"
        "require_once $root . '/ap-includes/version.php';\n"
        "require_once $root . '/ap-includes/class-ap-db.php';\n"
        "require_once $root . '/ap-includes/class-ap-migrator.php';\n"
        "require_once $root . '/ap-includes/class-ap-options.php';\n"
        "require_once $root . '/ap-includes/class-ap-user.php';\n"
        "require_once $root . '/ap-includes/class-ap-session.php';\n"
        "require_once $root . '/ap-includes/class-ap-roles.php';\n"
        "require_once $root . '/ap-includes/class-ap-mail.php';\n"
        "require_once $root . '/ap-includes/class-ap-registration.php';\n"
        "require_once $root . '/ap-includes/functions.php';\n"
        "require_once $root . '/ap-includes/hooks.php';\n"
        "if (!defined('AP_AUTH_KEY')) define('AP_AUTH_KEY', 'k' . str_repeat('1', 40));\n"
        "if (!defined('AP_AUTH_SALT')) define('AP_AUTH_SALT', 's' . str_repeat('2', 40));\n"
        "if (!defined('AP_SITEURL')) define('AP_SITEURL', 'https://example.test');\n"
        "$pdo = new PDO('sqlite::memory:');\n"
        "$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);\n"
        "$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_OBJ);\n"
        "$db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');\n"
        "$m = new AP_Migrator($db, AP_Migrator::defaultMigrationsPath());\n"
        "$m->migrate();\n"
        "AP_Roles::ensureDefaults($db);\n"
        "AP_Options::flushCache();\n"
        "foreach ([\n"
        "  'users_can_register' => '1',\n"
        "  'require_email_verification' => '1',\n"
        "  'default_role' => 'subscriber',\n"
        "  'blogname' => 'PyTest',\n"
        "  'admin_email' => 'admin@example.test',\n"
        "] as $n => $v) { AP_Options::update($n, $v, $db); }\n"
        "AP_Mail::enableTestMode();\n"
        "ap_reset_hooks();\n"
        "$generic = 'Could not complete registration. Please try again.';\n"
        "$base = [\n"
        "  'user_login' => 'gatebot',\n"
        "  'user_email' => 'gatebot@example.test',\n"
        "  'user_pass' => 'pytest-secret-1',\n"
        "];\n"
        "$naked = AP_Registration::register($base, $db);\n"
        "if ($naked['ok'] || $naked['errors'] !== [$generic]) {\n"
        "  fwrite(STDERR, 'naked: ' . implode(' ', $naked['errors']) . \"\\n\"); exit(1);\n"
        "}\n"
        "if (AP_User::getByLogin('gatebot', $db) !== null) { fwrite(STDERR, \"naked row\\n\"); exit(2); }\n"
        "$hpTicket = AP_Registration::createFormTicket(time() - AP_Registration::MIN_FILL_SECONDS);\n"
        "$hp = AP_Registration::register(array_merge($base, [\n"
        "  'user_login' => 'gatehp',\n"
        "  'user_email' => 'gatehp@example.test',\n"
        "  'ap_form_ticket' => $hpTicket['token'],\n"
        "  'ap_hp' => 'http://spam.example',\n"
        "]), $db);\n"
        "if ($hp['ok'] || $hp['errors'] !== [$generic] || AP_User::getByLogin('gatehp', $db) !== null) {\n"
        "  fwrite(STDERR, 'hp: ' . implode(' ', $hp['errors']) . \"\\n\"); exit(3);\n"
        "}\n"
        "$fresh = AP_Registration::createFormTicket(time());\n"
        "$tooFast = AP_Registration::register($base + [\n"
        "  'ap_form_ticket' => $fresh['token'],\n"
        "  'ap_hp' => '',\n"
        "], $db);\n"
        "if ($tooFast['ok'] || $tooFast['errors'] !== [$generic]) {\n"
        "  fwrite(STDERR, 'fast: ' . implode(' ', $tooFast['errors']) . \"\\n\"); exit(4);\n"
        "}\n"
        "$badTicket = AP_Registration::register($base + [\n"
        "  'ap_form_ticket' => 'not-a-ticket',\n"
        "  'ap_hp' => '',\n"
        "], $db);\n"
        "if ($badTicket['ok'] || $badTicket['errors'] !== [$generic]) {\n"
        "  fwrite(STDERR, 'ticket: ' . implode(' ', $badTicket['errors']) . \"\\n\"); exit(5);\n"
        "}\n"
        "$wantUnavailable = AP_Registration::USERNAME_UNAVAILABLE_MESSAGE;\n"
        "$reservedTicket = AP_Registration::createFormTicket(time() - AP_Registration::MIN_FILL_SECONDS);\n"
        "$reserved = AP_Registration::register([\n"
        "  'user_login' => 'Admin',\n"
        "  'user_email' => 'admin-public@example.test',\n"
        "  'user_pass' => 'pytest-secret-1',\n"
        "  'ap_form_ticket' => $reservedTicket['token'],\n"
        "  'ap_hp' => '',\n"
        "], $db);\n"
        "if ($reserved['ok'] || $reserved['errors'] !== [$wantUnavailable]) {\n"
        "  fwrite(STDERR, 'reserved: ' . implode(' ', $reserved['errors']) . \"\\n\"); exit(6);\n"
        "}\n"
        "if (str_contains(strtolower(implode(' ', $reserved['errors'])), 'reserved')) {\n"
        "  fwrite(STDERR, \"reserved leak\\n\"); exit(7);\n"
        "}\n"
        "$staff = AP_User::create([\n"
        "  'user_login' => 'admin',\n"
        "  'user_email' => 'admin-staff@example.test',\n"
        "  'user_pass' => 'pytest-secret-1',\n"
        "  'role' => 'administrator',\n"
        "], $db);\n"
        "if (!$staff['ok']) { fwrite(STDERR, 'staff: ' . implode(',', $staff['errors']) . \"\\n\"); exit(8); }\n"
        "if (($staff['user']->user_login ?? '') !== 'admin') { fwrite(STDERR, \"staff case\\n\"); exit(9); }\n"
        "$dup = AP_User::create([\n"
        "  'user_login' => 'Admin',\n"
        "  'user_email' => 'admin-case@example.test',\n"
        "  'user_pass' => 'pytest-secret-1',\n"
        "], $db);\n"
        "if ($dup['ok'] || !in_array('That username is already registered.', $dup['errors'], true)) {\n"
        "  fwrite(STDERR, 'collide: ' . implode(',', $dup['errors']) . \"\\n\"); exit(10);\n"
        "}\n"
        "if (AP_User::getByLogin('Admin', $db)?->ID !== $staff['id']) {\n"
        "  fwrite(STDERR, \"collide lookup\\n\"); exit(11);\n"
        "}\n"
        "if (AP_User::getByLogin('Admin', $db)?->user_login !== 'admin') {\n"
        "  fwrite(STDERR, \"stored case changed\\n\"); exit(12);\n"
        "}\n"
        "if (AP_User::getByEmail('admin-case@example.test', $db) !== null) {\n"
        "  fwrite(STDERR, \"dup row created\\n\"); exit(13);\n"
        "}\n"
        "ap_reset_hooks();\n"
        "$seen = [];\n"
        "ap_add_action('ap_user_created', static function (int $id, string $login, string $email, int $status) use (&$seen): void {\n"
        "  $seen[] = compact('id', 'login', 'email', 'status');\n"
        "}, 10, 4);\n"
        "$pendingTicket = AP_Registration::createFormTicket(time() - AP_Registration::MIN_FILL_SECONDS);\n"
        "$pending = AP_Registration::register([\n"
        "  'user_login' => 'pendinghook',\n"
        "  'user_email' => 'pendinghook@example.test',\n"
        "  'user_pass' => 'pytest-secret-1',\n"
        "  'ap_form_ticket' => $pendingTicket['token'],\n"
        "  'ap_hp' => '',\n"
        "], $db);\n"
        "if (!$pending['ok']) { fwrite(STDERR, 'pending: ' . implode(',', $pending['errors']) . \"\\n\"); exit(14); }\n"
        "if ((int) $pending['user']->user_status !== AP_Registration::STATUS_PENDING) {\n"
        "  fwrite(STDERR, \"not pending\\n\"); exit(15);\n"
        "}\n"
        "if (count($seen) !== 1 || $seen[0]['id'] !== $pending['id'] || $seen[0]['status'] !== AP_Registration::STATUS_PENDING) {\n"
        "  fwrite(STDERR, \"hook payload\\n\"); exit(16);\n"
        "}\n"
        "if ($seen[0]['login'] !== 'pendinghook' || $seen[0]['email'] !== 'pendinghook@example.test') {\n"
        "  fwrite(STDERR, \"hook login/email\\n\"); exit(17);\n"
        "}\n"
        "if (ap_did_action('ap_user_created') !== 1) { fwrite(STDERR, \"did_action\\n\"); exit(18); }\n"
        "ap_reset_hooks();\n"
        "AP_Options::update('registration_captcha', 'math', $db);\n"
        "ap_add_filter('ap_registration_captcha_mode', static function (string $mode): string { return $mode; });\n"
        "ap_add_filter('ap_registration_captcha_challenge', static function (array $challenge, string $mode): array {\n"
        "  if ($mode === 'math') { $challenge['legend'] = 'hooked-math'; }\n"
        "  return $challenge;\n"
        "}, 10, 2);\n"
        "ap_add_filter('ap_registration_verify_captcha', static function (array $result, array $data, string $mode): array {\n"
        "  if ($mode === 'math' && ($data['captcha_answer'] ?? '') === 'plugin-ok') {\n"
        "    return ['ok' => true, 'errors' => []];\n"
        "  }\n"
        "  return $result;\n"
        "}, 10, 3);\n"
        "if (ap_registration_captcha_mode($db) !== 'math') { fwrite(STDERR, \"mode\\n\"); exit(19); }\n"
        "$challenge = AP_Registration::createCaptchaChallenge($db);\n"
        "if (($challenge['mode'] ?? '') !== 'math' || ($challenge['legend'] ?? '') !== 'hooked-math') {\n"
        "  fwrite(STDERR, \"challenge hook\\n\"); exit(20);\n"
        "}\n"
        "$hooked = AP_Registration::verifyCaptcha([\n"
        "  'captcha_answer' => 'plugin-ok',\n"
        "  'captcha_token' => 'unused',\n"
        "  'ap_hp' => '',\n"
        "], $db);\n"
        "if (!$hooked['ok']) { fwrite(STDERR, 'verify hook: ' . implode(',', $hooked['errors']) . \"\\n\"); exit(21); }\n"
        "$sum = (int) ($challenge['a'] ?? 0) + (int) ($challenge['b'] ?? 0);\n"
        "$real = AP_Registration::verifyCaptcha([\n"
        "  'captcha_answer' => (string) $sum,\n"
        "  'captcha_token' => (string) ($challenge['token'] ?? ''),\n"
        "  'ap_hp' => '',\n"
        "], $db);\n"
        "if (!$real['ok']) { fwrite(STDERR, 'math still works: ' . implode(',', $real['errors']) . \"\\n\"); exit(22); }\n"
        "echo \"OK\\n\";\n"
    )
    with tempfile.NamedTemporaryFile("w", suffix=".php", delete=False) as fh:
        fh.write(code)
        path = fh.name
    try:
        result = subprocess.run(
            [_php_bin(), "-d", "display_errors=1", path],
            cwd=str(ROOT),
            env={**dict(**{k: v for k, v in __import__("os").environ.items()}), "AP_ROOT": str(ROOT)},
            capture_output=True,
            text=True,
            check=False,
        )
    finally:
        Path(path).unlink(missing_ok=True)

    combined = (result.stdout or "") + (result.stderr or "")
    assert result.returncode == 0, combined
    assert "OK" in (result.stdout or "")


if __name__ == "__main__":
    sys.exit(__import__("pytest").main([__file__, "-v"]))
