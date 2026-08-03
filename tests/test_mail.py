"""
Smoke tests for AP_Mail unit coverage.

Runnable via:
  pytest tests/test_mail.py -v
"""

from __future__ import annotations

import shutil
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MAIL_CLASS = ROOT / "ap-includes" / "class-ap-mail.php"
MAIL_TEST = ROOT / "tests" / "Security" / "MailTest.php"


def _php_bin() -> str:
    return shutil.which("php") or "php"


def test_mail_files_exist() -> None:
    assert MAIL_CLASS.is_file(), "Missing class-ap-mail.php"
    assert MAIL_TEST.is_file(), "Missing MailTest.php"


def test_mail_api_surface() -> None:
    src = MAIL_CLASS.read_text(encoding="utf-8")
    for needle in (
        "class AP_Mail",
        "enableTestMode",
        "disableTestMode",
        "getTestOutbox",
        "clearTestOutbox",
        "function send",
        "sanitizeHeaderValue",
        "X-Mailer",
    ):
        assert needle in src, f"Expected {needle!r} in class-ap-mail.php"


def test_mail_phpunit_suite() -> None:
    phpunit = ROOT / "vendor" / "bin" / "phpunit"
    if not phpunit.is_file():
        return
    result = subprocess.run(
        [_php_bin(), str(phpunit), str(MAIL_TEST), "--colors=never"],
        cwd=ROOT,
        capture_output=True,
        text=True,
        check=False,
    )
    assert result.returncode == 0, (
        f"PHPUnit failed (exit {result.returncode}):\n"
        f"{result.stdout}\n{result.stderr}"
    )
