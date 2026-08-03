"""
Smoke tests for AgoraPress session handling (AP_Session / auth cookies).

Runnable via:
  pytest tests/test_session.py -v
"""

from __future__ import annotations

import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SESSION_CLASS = ROOT / "ap-includes" / "class-ap-session.php"
USER_CLASS = ROOT / "ap-includes" / "class-ap-user.php"
FUNCTIONS = ROOT / "ap-includes" / "functions.php"
BOOTSTRAP = ROOT / "ap-includes" / "bootstrap.php"


def _php_bin() -> str:
    return shutil.which("php") or "php"


def test_session_files_exist() -> None:
    assert SESSION_CLASS.is_file(), "Missing class-ap-session.php"
    assert USER_CLASS.is_file()
    assert FUNCTIONS.is_file()


def test_session_class_defines_api() -> None:
    src = SESSION_CLASS.read_text(encoding="utf-8")
    for needle in (
        "class AP_Session",
        "function login",
        "function logout",
        "function setAuthCookie",
        "function clearAuthCookie",
        "function generateAuthCookie",
        "function validateAuthCookie",
        "function createSessionToken",
        "function destroySessionToken",
        "function destroyAllSessionTokens",
        "function getCurrentUser",
        "function isLoggedIn",
        "hash_hmac",
        "session_tokens",
    ):
        assert needle in src, f"Expected {needle!r} in class-ap-session.php"


def test_functions_expose_session_helpers() -> None:
    src = FUNCTIONS.read_text(encoding="utf-8")
    for needle in (
        "function ap_login",
        "function ap_logout",
        "function ap_set_auth_cookie",
        "function ap_clear_auth_cookie",
        "function ap_is_user_logged_in",
        "function ap_get_current_user",
        "function ap_get_current_user_id",
        "function ap_auth_cookie_name",
        "function ap_destroy_user_sessions",
    ):
        assert needle in src, f"Expected {needle!r} in functions.php"


def test_bootstrap_loads_session_class() -> None:
    src = BOOTSTRAP.read_text(encoding="utf-8")
    assert "class-ap-session.php" in src
    assert "class-ap-user.php" in src


def test_password_change_revokes_sessions_in_user_class() -> None:
    src = USER_CLASS.read_text(encoding="utf-8")
    assert "destroyAllSessionTokens" in src
    assert "AP_Session" in src


def test_generate_and_validate_cookie_via_php() -> None:
    """Runtime: login cookie round-trip validates; tampered HMAC fails."""
    code = f"""<?php
declare(strict_types=1);
require {str(USER_CLASS)!r};
require {str(SESSION_CLASS)!r};
require {str(ROOT / 'ap-includes' / 'class-ap-db.php')!r};
require {str(ROOT / 'ap-includes' / 'class-ap-migrator.php')!r};

if (!defined('AP_LOGGED_IN_KEY')) {{
    define('AP_LOGGED_IN_KEY', 'pytest-logged-in-key-' . str_repeat('x', 24));
}}
if (!defined('AP_LOGGED_IN_SALT')) {{
    define('AP_LOGGED_IN_SALT', 'pytest-logged-in-salt-' . str_repeat('y', 24));
}}

$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');
$migrator = new AP_Migrator($db, AP_Migrator::defaultMigrationsPath());
$migrator->migrate();

AP_Session::enableTestMode();
$hash = AP_User::hashPassword('pytest-session');
$db->insert('users', [
    'user_login' => 'pytestuser',
    'user_pass' => $hash,
    'user_nicename' => 'pytestuser',
    'user_email' => 'pytest@example.test',
    'user_url' => '',
    'user_registered' => gmdate('Y-m-d H:i:s'),
    'user_activation_key' => '',
    'user_status' => 0,
    'display_name' => 'Pytest',
]);
$id = (int) $db->lastInsertId();
$user = AP_User::getById($id, $db);
if ($user === null) {{ fwrite(STDERR, "user\\n"); exit(1); }}

$logged = AP_Session::login('pytestuser', 'pytest-session', false, $db);
if ($logged === null || $logged->ID !== $id) {{ fwrite(STDERR, "login\\n"); exit(2); }}
if (!AP_Session::isLoggedIn($db)) {{ fwrite(STDERR, "logged_in\\n"); exit(3); }}

$cookie = AP_Session::getTestCookies()[AP_Session::cookieName()] ?? '';
if ($cookie === '') {{ fwrite(STDERR, "cookie\\n"); exit(4); }}

$ok = AP_Session::validateAuthCookie($cookie, $db);
if ($ok === null || $ok->ID !== $id) {{ fwrite(STDERR, "validate\\n"); exit(5); }}

$bad = substr($cookie, 0, -8) . 'ffffffff';
if (AP_Session::validateAuthCookie($bad, $db) !== null) {{ fwrite(STDERR, "tamper\\n"); exit(6); }}

AP_Session::logout($db);
if (AP_Session::isLoggedIn($db)) {{ fwrite(STDERR, "logout\\n"); exit(7); }}
if (AP_Session::validateAuthCookie($cookie, $db) !== null) {{ fwrite(STDERR, "revoke\\n"); exit(8); }}

echo "OK\\n";
"""
    with tempfile.NamedTemporaryFile("w", suffix=".php", delete=False) as fh:
        fh.write(code)
        path = fh.name
    try:
        result = subprocess.run(
            [_php_bin(), "-d", "display_errors=1", path],
            cwd=str(ROOT),
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
