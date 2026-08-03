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


if __name__ == "__main__":
    sys.exit(__import__("pytest").main([__file__, "-v"]))
