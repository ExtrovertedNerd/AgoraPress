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
        "function issueKey",
        "function validateKey",
        "function usersCanRegister",
        "function requireEmailVerification",
        "function captchaMode",
        "function isCaptchaEnabled",
        "function createMathChallenge",
        "function verifyCaptcha",
        "CAPTCHA_OFF",
        "CAPTCHA_MATH",
        "registration_captcha",
        "PURPOSE_ACTIVATE",
        "PURPOSE_RESET",
        "hash_hmac",
    ):
        assert needle in src, f"Expected {needle!r} in class-ap-registration.php"


def test_mail_class_defines_api() -> None:
    src = MAIL_CLASS.read_text(encoding="utf-8")
    for needle in (
        "class AP_Mail",
        "function send",
        "function enableTestMode",
        "function getTestOutbox",
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
        "function ap_register_user",
        "function ap_verify_user_email",
        "function ap_request_password_reset",
        "function ap_check_password_reset_key",
        "function ap_reset_password",
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
        "captcha_answer",
        "captcha_token",
        "ap_hp",
        "ap_registration_captcha_enabled",
    ):
        assert needle in src, f"Expected {needle!r} in login.php"


def test_general_settings_exposes_registration_captcha() -> None:
    general = ROOT / "ap-admin" / "options-general.php"
    src = general.read_text(encoding="utf-8")
    assert "registration_captcha" in src
    assert "Simple math question" in src


def test_installer_seeds_registration_captcha_off() -> None:
    src = INSTALLER.read_text(encoding="utf-8")
    assert "registration_captcha" in src
    assert "'registration_captcha' => 'off'" in src


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
        "$reg = AP_Registration::register([\n"
        "  'user_login' => 'pytestuser',\n"
        "  'user_email' => 'pytest@example.test',\n"
        "  'user_pass' => 'pytest-secret-1',\n"
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
