"""
Smoke tests for rate limiting, login protection, and upload hardening.

Runnable via:
  pytest tests/test_rate_limit.py -v
"""

from __future__ import annotations

import shutil
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
RATE_LIMIT = ROOT / "ap-includes" / "class-ap-rate-limit.php"
SESSION = ROOT / "ap-includes" / "class-ap-session.php"
REGISTRATION = ROOT / "ap-includes" / "class-ap-registration.php"
MEDIA = ROOT / "ap-includes" / "class-ap-media.php"
FUNCTIONS = ROOT / "ap-includes" / "functions.php"
BOOTSTRAP = ROOT / "ap-includes" / "bootstrap.php"
INSTALLER = ROOT / "ap-includes" / "class-ap-installer.php"
LOGIN = ROOT / "ap-admin" / "login.php"
PHPUNIT = ROOT / "tests" / "Security" / "RateLimitTest.php"


def _php_bin() -> str:
    return shutil.which("php") or "php"


def test_core_files_exist() -> None:
    for path in (
        RATE_LIMIT,
        SESSION,
        REGISTRATION,
        MEDIA,
        FUNCTIONS,
        BOOTSTRAP,
        INSTALLER,
        LOGIN,
        PHPUNIT,
    ):
        assert path.is_file(), f"Missing {path.relative_to(ROOT)}"


def test_rate_limit_api_surface() -> None:
    src = RATE_LIMIT.read_text(encoding="utf-8")
    for needle in (
        "class AP_Rate_Limit",
        "ACTION_LOGIN",
        "ACTION_REGISTER",
        "ACTION_PASSWORD_RESET",
        "ACTION_UPLOAD",
        "function isLimited",
        "function check",
        "function hit",
        "function clear",
        "function clientIp",
        "function checkLogin",
        "function recordFailedLogin",
        "function clearLogin",
        "function lockoutMessage",
        "function identityBucket",
        "function ipBucket",
    ):
        assert needle in src, f"Expected {needle!r} in class-ap-rate-limit.php"


def test_functions_and_bootstrap_wire_rate_limit() -> None:
    fn = FUNCTIONS.read_text(encoding="utf-8")
    for needle in (
        "function ap_client_ip",
        "function ap_rate_limit_check",
        "function ap_rate_limit_hit",
        "function ap_rate_limit_clear",
        "function ap_check_login_rate_limit",
        "function ap_get_last_login_error",
    ):
        assert needle in fn, f"Expected {needle!r} in functions.php"

    boot = BOOTSTRAP.read_text(encoding="utf-8")
    assert "class-ap-rate-limit.php" in boot

    installer = INSTALLER.read_text(encoding="utf-8")
    assert "rate_limit_login_max" in installer
    assert "rate_limit_upload_max" in installer


def test_session_and_login_ui_use_protection() -> None:
    session = SESSION.read_text(encoding="utf-8")
    assert "AP_Rate_Limit::checkLogin" in session
    assert "recordFailedLogin" in session
    assert "getLastLoginError" in session

    login = LOGIN.read_text(encoding="utf-8")
    assert "getLastLoginError" in login
    assert "rate_limited" in login


def test_registration_and_media_wire_limits() -> None:
    reg = REGISTRATION.read_text(encoding="utf-8")
    assert "ACTION_REGISTER" in reg
    assert "ACTION_PASSWORD_RESET" in reg

    media = MEDIA.read_text(encoding="utf-8")
    assert "ACTION_UPLOAD" in media
    assert "scanSvgSafety" in media
    assert "isRasterImageExt" in media
    assert "skip_rate_limit" in media


def test_phpunit_rate_limit_suite() -> None:
    """Run RateLimitTest when vendor/phpunit is available."""
    phpunit = ROOT / "vendor" / "bin" / "phpunit"
    if not phpunit.is_file():
        # Fall back to a direct require smoke of the class.
        code = (
            "<?php\ndeclare(strict_types=1);\n"
            f"require {repr(str(RATE_LIMIT))};\n"
            "if (!class_exists('AP_Rate_Limit')) { fwrite(STDERR, \"missing\\n\"); exit(1); }\n"
            "echo AP_Rate_Limit::ACTION_LOGIN, PHP_EOL;\n"
        )
        proc = subprocess.run(
            [_php_bin(), "-r", code],
            cwd=str(ROOT),
            capture_output=True,
            text=True,
            check=False,
        )
        assert proc.returncode == 0, proc.stderr
        assert "login" in proc.stdout
        return

    proc = subprocess.run(
        [_php_bin(), str(phpunit), "tests/Security/RateLimitTest.php", "--colors=never"],
        cwd=str(ROOT),
        capture_output=True,
        text=True,
        check=False,
        timeout=120,
    )
    assert proc.returncode == 0, proc.stdout + "\n" + proc.stderr
