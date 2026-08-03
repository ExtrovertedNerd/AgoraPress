"""
Smoke tests for CSRF nonces on all forms.

Runnable via:
  pytest tests/test_nonces.py -v
"""

from __future__ import annotations

import re
import shutil
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
NONCE_CLASS = ROOT / "ap-includes" / "class-ap-nonce.php"
FUNCTIONS = ROOT / "ap-includes" / "functions.php"
LOGIN = ROOT / "ap-admin" / "login.php"
HEADER = ROOT / "ap-admin" / "admin-header.php"
INSTALL = ROOT / "install" / "index.php"
PHPUNIT = ROOT / "tests" / "Security" / "NonceTest.php"

SCAN_DIRS = ("ap-admin", "ap-includes", "ap-content", "install")
FORM_RE = re.compile(r'<form\b[^>]*\bmethod\s*=\s*["\']post["\'][^>]*>', re.I)
CSRF_RE = re.compile(r"_ap_nonce|ap_nonce_field|settingsFields|ap_csrf|ap_install_csrf")


def _php_bin() -> str:
    return shutil.which("php") or "php"


def test_nonce_core_files_exist() -> None:
    for path in (NONCE_CLASS, FUNCTIONS, PHPUNIT, LOGIN, HEADER, INSTALL):
        assert path.is_file(), f"Missing {path.relative_to(ROOT)}"


def test_nonce_api_surface() -> None:
    src = NONCE_CLASS.read_text(encoding="utf-8")
    for needle in (
        "class AP_Nonce",
        "function create",
        "function verify",
        "function check",
        "function field",
        "function url",
        "function verifyRequest",
        "TICK_SECONDS",
    ):
        assert needle in src, f"Expected {needle!r} in class-ap-nonce.php"

    fn = FUNCTIONS.read_text(encoding="utf-8")
    for needle in (
        "function ap_create_nonce",
        "function ap_verify_nonce",
        "function ap_check_nonce",
        "function ap_nonce_field",
        "function ap_nonce_url",
        "function ap_verify_request_nonce",
        "function ap_check_request_nonce",
    ):
        assert needle in fn, f"Expected {needle!r} in functions.php"


def test_logout_is_nonce_protected() -> None:
    header = HEADER.read_text(encoding="utf-8")
    assert "ap_nonce_url" in header
    assert "log-out" in header

    login = LOGIN.read_text(encoding="utf-8")
    assert "log-out" in login
    assert "ap_check_nonce" in login
    assert "Security check failed" in login or "message" in login


def test_installer_session_csrf() -> None:
    src = INSTALL.read_text(encoding="utf-8")
    assert "ap_install_csrf_token" in src
    assert "ap_install_csrf_ok" in src
    assert src.count('name="ap_csrf"') == 4


def test_every_post_form_has_csrf() -> None:
    missing: list[str] = []
    for rel in SCAN_DIRS:
        base = ROOT / rel
        if not base.is_dir():
            continue
        for path in base.rglob("*.php"):
            if "vendor" in path.parts or "tests" in path.parts:
                continue
            text = path.read_text(encoding="utf-8", errors="replace")
            for m in FORM_RE.finditer(text):
                window = text[m.start() : m.start() + 1500]
                if not CSRF_RE.search(window):
                    line = text.count("\n", 0, m.start()) + 1
                    missing.append(f"{path.relative_to(ROOT)}:{line}")
    assert missing == [], "POST forms missing CSRF:\n" + "\n".join(missing)


def test_phpunit_nonce() -> None:
    phpunit = ROOT / "vendor" / "bin" / "phpunit"
    if not phpunit.is_file():
        return
    cmd = [
        _php_bin(),
        str(phpunit),
        "--configuration",
        str(ROOT / "phpunit.xml.dist"),
        str(PHPUNIT),
    ]
    result = subprocess.run(
        cmd,
        cwd=str(ROOT),
        capture_output=True,
        text=True,
        check=False,
    )
    combined = (result.stdout or "") + (result.stderr or "")
    assert result.returncode == 0, combined
