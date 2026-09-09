"""
Smoke tests for AgoraPress basic authentication (Argon2id / AP_User).

Runnable via:
  pytest tests/test_user_auth.py -v
"""

from __future__ import annotations

import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
USER_CLASS = ROOT / "ap-includes" / "class-ap-user.php"
FUNCTIONS = ROOT / "ap-includes" / "functions.php"
INSTALLER = ROOT / "ap-includes" / "class-ap-installer.php"
BOOTSTRAP = ROOT / "ap-includes" / "bootstrap.php"


def _php_bin() -> str:
    return shutil.which("php") or "php"


def test_user_auth_files_exist() -> None:
    assert USER_CLASS.is_file(), "Missing class-ap-user.php"
    assert FUNCTIONS.is_file(), "Missing functions.php"


def test_user_class_defines_auth_api() -> None:
    src = USER_CLASS.read_text(encoding="utf-8")
    for needle in (
        "class AP_User",
        "function hashPassword",
        "function checkPassword",
        "function passwordNeedsRehash",
        "function authenticate",
        "function getById",
        "function getByLogin",
        "function getByEmail",
        "function getByCaseInsensitiveColumn",
        "ap_user_created",
        "LOWER(",
        "PASSWORD_ARGON2ID",
        "password_hash",
        "password_verify",
    ):
        assert needle in src, f"Expected {needle!r} in class-ap-user.php"


def test_functions_expose_procedural_helpers() -> None:
    src = FUNCTIONS.read_text(encoding="utf-8")
    for needle in (
        "function ap_hash_password",
        "function ap_check_password",
        "function ap_password_needs_rehash",
        "function ap_authenticate",
        "function ap_get_user_by",
    ):
        assert needle in src, f"Expected {needle!r} in functions.php"


def test_installer_delegates_hash_to_user() -> None:
    src = INSTALLER.read_text(encoding="utf-8")
    assert "AP_User::hashPassword" in src
    assert "class-ap-user.php" in src


def test_bootstrap_loads_user_class() -> None:
    src = BOOTSTRAP.read_text(encoding="utf-8")
    assert "class-ap-user.php" in src
    assert "functions.php" in src


def test_hash_and_verify_via_php() -> None:
    """Runtime check: preferred hash verifies; wrong password fails."""
    code = (
        "<?php\ndeclare(strict_types=1);\n"
        f"require {repr(str(USER_CLASS))};\n"
        "$hash = AP_User::hashPassword('pytest-secret');\n"
        "if (!is_string($hash) || $hash === '') { fwrite(STDERR, \"empty\\n\"); exit(1); }\n"
        "if (!AP_User::checkPassword('pytest-secret', $hash)) { fwrite(STDERR, \"verify\\n\"); exit(2); }\n"
        "if (AP_User::checkPassword('wrong', $hash)) { fwrite(STDERR, \"wrong\\n\"); exit(3); }\n"
        "if (defined('PASSWORD_ARGON2ID') && stripos($hash, 'argon2') === false) {\n"
        "  fwrite(STDERR, \"algo\\n\"); exit(4);\n"
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
            capture_output=True,
            text=True,
            check=False,
        )
    finally:
        Path(path).unlink(missing_ok=True)

    combined = (result.stdout or "") + (result.stderr or "")
    assert result.returncode == 0, combined
    assert "OK" in (result.stdout or "")


def test_login_and_email_uniqueness_is_case_insensitive_via_php() -> None:
    """Silas and silas collide; stored case is kept; legacy rows are not merged."""
    user_class = str(USER_CLASS)
    version = str(ROOT / "ap-includes" / "version.php")
    db_class = str(ROOT / "ap-includes" / "class-ap-db.php")
    migrator = str(ROOT / "ap-includes" / "class-ap-migrator.php")
    options = str(ROOT / "ap-includes" / "class-ap-options.php")
    functions = str(FUNCTIONS)
    code = (
        "<?php\ndeclare(strict_types=1);\n"
        f"require_once {repr(version)};\n"
        f"require_once {repr(db_class)};\n"
        f"require_once {repr(migrator)};\n"
        f"require_once {repr(options)};\n"
        f"require_once {repr(user_class)};\n"
        f"require_once {repr(functions)};\n"
        "$pdo = new PDO('sqlite::memory:', null, null, [\n"
        "  PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,\n"
        "  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,\n"
        "  PDO::ATTR_EMULATE_PREPARES => false,\n"
        "]);\n"
        "$db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');\n"
        "$m = new AP_Migrator($db, AP_Migrator::defaultMigrationsPath());\n"
        "$m->migrate();\n"
        "$first = AP_User::create([\n"
        "  'user_login' => 'Silas',\n"
        "  'user_email' => 'silas@example.test',\n"
        "  'user_pass' => 'securepass0',\n"
        "], $db);\n"
        "if (!$first['ok']) { fwrite(STDERR, implode(',', $first['errors']) . \"\\n\"); exit(1); }\n"
        "if ($first['user']->user_login !== 'Silas') { fwrite(STDERR, \"case folded\\n\"); exit(2); }\n"
        "$dup = AP_User::create([\n"
        "  'user_login' => 'silas',\n"
        "  'user_email' => 'silas-other@example.test',\n"
        "  'user_pass' => 'securepass0',\n"
        "], $db);\n"
        "if ($dup['ok'] || !in_array('That username is already registered.', $dup['errors'], true)) {\n"
        "  fwrite(STDERR, 'dup: ' . implode(',', $dup['errors']) . \"\\n\"); exit(3);\n"
        "}\n"
        "$emailDup = AP_User::create([\n"
        "  'user_login' => 'silas2',\n"
        "  'user_email' => 'Silas@example.test',\n"
        "  'user_pass' => 'securepass0',\n"
        "], $db);\n"
        "if ($emailDup['ok'] || !in_array('That email address is already registered.', $emailDup['errors'], true)) {\n"
        "  fwrite(STDERR, 'email: ' . implode(',', $emailDup['errors']) . \"\\n\"); exit(4);\n"
        "}\n"
        "if (AP_User::getByLogin('SILAS', $db)?->ID !== $first['id']) {\n"
        "  fwrite(STDERR, \"ci lookup failed\\n\"); exit(5);\n"
        "}\n"
        "$hash = AP_User::hashPassword('securepass0');\n"
        "$now = gmdate('Y-m-d H:i:s');\n"
        "$db->insert('users', [\n"
        "  'user_login' => 'LegacyA',\n"
        "  'user_pass' => $hash,\n"
        "  'user_nicename' => 'legacya',\n"
        "  'user_email' => 'legacy-a@example.test',\n"
        "  'user_url' => '',\n"
        "  'user_registered' => $now,\n"
        "  'user_activation_key' => '',\n"
        "  'user_status' => 0,\n"
        "  'display_name' => 'LegacyA',\n"
        "]);\n"
        "$idA = (int) $db->lastInsertId();\n"
        "$db->insert('users', [\n"
        "  'user_login' => 'legacya',\n"
        "  'user_pass' => $hash,\n"
        "  'user_nicename' => 'legacya-2',\n"
        "  'user_email' => 'legacy-b@example.test',\n"
        "  'user_url' => '',\n"
        "  'user_registered' => $now,\n"
        "  'user_activation_key' => '',\n"
        "  'user_status' => 0,\n"
        "  'display_name' => 'legacya',\n"
        "]);\n"
        "$idB = (int) $db->lastInsertId();\n"
        "if (AP_User::getByLogin('LegacyA', $db)?->ID !== $idA) { fwrite(STDERR, \"exact A\\n\"); exit(6); }\n"
        "if (AP_User::getByLogin('legacya', $db)?->ID !== $idB) { fwrite(STDERR, \"exact B\\n\"); exit(7); }\n"
        "$merge = AP_User::create([\n"
        "  'user_login' => 'LEGACYA',\n"
        "  'user_email' => 'legacy-new@example.test',\n"
        "  'user_pass' => 'securepass0',\n"
        "], $db);\n"
        "if ($merge['ok']) { fwrite(STDERR, \"merged new row\\n\"); exit(8); }\n"
        "if (AP_User::getById($idA, $db)?->user_login !== 'LegacyA') { fwrite(STDERR, \"row A changed\\n\"); exit(9); }\n"
        "if (AP_User::getById($idB, $db)?->user_login !== 'legacya') { fwrite(STDERR, \"row B changed\\n\"); exit(10); }\n"
        "echo \"OK\\n\";\n"
    )
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


def test_create_fires_user_created_including_pending_via_php() -> None:
    """AP_User::create fires ap_user_created after insert, including STATUS_PENDING."""
    version = str(ROOT / "ap-includes" / "version.php")
    db_class = str(ROOT / "ap-includes" / "class-ap-db.php")
    migrator = str(ROOT / "ap-includes" / "class-ap-migrator.php")
    options = str(ROOT / "ap-includes" / "class-ap-options.php")
    user_class = str(USER_CLASS)
    functions = str(FUNCTIONS)
    hooks = str(ROOT / "ap-includes" / "hooks.php")
    registration = str(ROOT / "ap-includes" / "class-ap-registration.php")
    code = (
        "<?php\ndeclare(strict_types=1);\n"
        f"require_once {repr(version)};\n"
        f"require_once {repr(db_class)};\n"
        f"require_once {repr(migrator)};\n"
        f"require_once {repr(options)};\n"
        f"require_once {repr(user_class)};\n"
        f"require_once {repr(functions)};\n"
        f"require_once {repr(hooks)};\n"
        f"require_once {repr(registration)};\n"
        "$pdo = new PDO('sqlite::memory:', null, null, [\n"
        "  PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,\n"
        "  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,\n"
        "  PDO::ATTR_EMULATE_PREPARES => false,\n"
        "]);\n"
        "$db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');\n"
        "$m = new AP_Migrator($db, AP_Migrator::defaultMigrationsPath());\n"
        "$m->migrate();\n"
        "ap_reset_hooks();\n"
        "$seen = [];\n"
        "ap_add_action('ap_user_created', static function (int $id, string $login, string $email, int $status) use (&$seen): void {\n"
        "  $seen[] = compact('id', 'login', 'email', 'status');\n"
        "}, 10, 4);\n"
        "$pending = AP_User::create([\n"
        "  'user_login' => 'pendinghook',\n"
        "  'user_email' => 'pendinghook@example.test',\n"
        "  'user_pass' => 'securepass0',\n"
        "  'user_status' => AP_Registration::STATUS_PENDING,\n"
        "], $db);\n"
        "if (!$pending['ok']) { fwrite(STDERR, implode(',', $pending['errors']) . \"\\n\"); exit(1); }\n"
        "if ((int) $pending['user']->user_status !== AP_Registration::STATUS_PENDING) {\n"
        "  fwrite(STDERR, \"status not pending\\n\"); exit(2);\n"
        "}\n"
        "if (count($seen) !== 1 || $seen[0]['id'] !== $pending['id']) {\n"
        "  fwrite(STDERR, \"hook count/id\\n\"); exit(3);\n"
        "}\n"
        "if ($seen[0]['login'] !== 'pendinghook' || $seen[0]['email'] !== 'pendinghook@example.test') {\n"
        "  fwrite(STDERR, \"payload login/email\\n\"); exit(4);\n"
        "}\n"
        "if ($seen[0]['status'] !== AP_Registration::STATUS_PENDING) {\n"
        "  fwrite(STDERR, \"payload status\\n\"); exit(5);\n"
        "}\n"
        "if (ap_did_action('ap_user_created') !== 1) { fwrite(STDERR, \"did_action\\n\"); exit(6); }\n"
        "ap_reset_hooks();\n"
        "$seen = [];\n"
        "ap_add_action('ap_user_created', static function (int $id, string $login, string $email, int $status) use (&$seen): void {\n"
        "  $seen[] = compact('id', 'login', 'email', 'status');\n"
        "}, 10, 4);\n"
        "$failed = AP_User::create([\n"
        "  'user_login' => 'nope',\n"
        "  'user_email' => 'not-an-email',\n"
        "  'user_pass' => 'short',\n"
        "], $db);\n"
        "if ($failed['ok'] || $seen !== [] || ap_did_action('ap_user_created') !== 0) {\n"
        "  fwrite(STDERR, \"fired on failure\\n\"); exit(7);\n"
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
