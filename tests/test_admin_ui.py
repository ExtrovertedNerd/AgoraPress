"""
Smoke tests for clean responsive admin UI shell.

Runnable via:
  pytest tests/test_admin_ui.py -v
"""

from __future__ import annotations

import shutil
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
ADMIN = ROOT / "ap-admin"
HEADER = ADMIN / "admin-header.php"
FOOTER = ADMIN / "admin-footer.php"
CSS = ADMIN / "css" / "admin.css"
ADMIN_CLASS = ADMIN / "includes" / "class-ap-admin.php"
PHPUNIT = ROOT / "tests" / "Admin" / "AdminUiTest.php"


def _php_bin() -> str:
    return shutil.which("php") or "php"


def test_admin_ui_files_exist() -> None:
    for path in (HEADER, FOOTER, CSS, ADMIN_CLASS, PHPUNIT):
        assert path.is_file(), f"Missing {path.relative_to(ROOT)}"


def test_header_responsive_shell() -> None:
    src = HEADER.read_text(encoding="utf-8")
    for needle in (
        'name="viewport"',
        "skip-link",
        "ap-menu-toggle",
        'aria-controls="ap-admin-menu"',
        "Visit Site",
        "ap-visit-site",
        "ap-admin-menu-backdrop",
        "AP_Admin::siteName",
        "AP_Admin::homeUrl",
        "menuSectionLabel",
        "ap_nonce_url",
        "log-out",
    ):
        assert needle in src, f"Header missing {needle!r}"


def test_footer_menu_script() -> None:
    src = FOOTER.read_text(encoding="utf-8")
    for needle in (
        "ap-menu-open",
        "ap-menu-toggle",
        "Escape",
        "ap-footer-version",
        "Thank you for creating with",
    ):
        assert needle in src, f"Footer missing {needle!r}"


def test_admin_css_responsive_rules() -> None:
    css = CSS.read_text(encoding="utf-8")
    for needle in (
        "prefers-color-scheme: dark",
        "@media (max-width: 782px)",
        "ap-menu-toggle",
        "ap-menu-open",
        "ap-admin-menu-backdrop",
        "position: sticky",
        "overflow-x: auto",
        "ap-dashboard-cards",
        "prefers-reduced-motion",
        "@media print",
    ):
        assert needle in css, f"admin.css missing {needle!r}"


def test_admin_helpers_in_class() -> None:
    src = ADMIN_CLASS.read_text(encoding="utf-8")
    for needle in (
        "function siteName",
        "function homeUrl",
        "function menuSectionLabel",
        "'section' => 'content'",
        "'section' => 'settings'",
        "'section' => 'appearance'",
    ):
        assert needle in src, f"AP_Admin missing {needle!r}"


def test_phpunit_admin_ui() -> None:
    phpunit = ROOT / "vendor" / "bin" / "phpunit"
    if not phpunit.is_file():
        # Fallback: structure-only assertions already covered above.
        return
    cmd = [
        _php_bin(),
        str(phpunit),
        "--configuration",
        str(ROOT / "phpunit.xml.dist"),
        str(PHPUNIT),
    ]
    result = subprocess.run(cmd, cwd=str(ROOT), capture_output=True, text=True)
    if result.returncode != 0:
        sys.stdout.write(result.stdout)
        sys.stderr.write(result.stderr)
    assert result.returncode == 0, "AdminUiTest PHPUnit suite failed"
