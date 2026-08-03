"""
Smoke tests for Site Health (status checks + system info).

Runnable via:
  pytest tests/test_site_health.py -v
"""

from __future__ import annotations

import shutil
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SITE_HEALTH = ROOT / "ap-includes" / "class-ap-site-health.php"
ADMIN_SCREEN = ROOT / "ap-admin" / "site-health.php"
FUNCTIONS = ROOT / "ap-includes" / "functions.php"
BOOTSTRAP = ROOT / "ap-includes" / "bootstrap.php"
ADMIN_CLASS = ROOT / "ap-admin" / "includes" / "class-ap-admin.php"
ROLES = ROOT / "ap-includes" / "class-ap-roles.php"
STRUCTURE = ROOT / "tests" / "Structure" / "assert-structure.php"
PHPUNIT = ROOT / "tests" / "Admin" / "SiteHealthTest.php"
CHANGELOG = ROOT / "CHANGELOG.md"
ADMIN_CSS = ROOT / "ap-admin" / "css" / "admin.css"


def _php_bin() -> str:
    return shutil.which("php") or "php"


def test_site_health_files_exist() -> None:
    for path in (SITE_HEALTH, ADMIN_SCREEN, PHPUNIT, BOOTSTRAP, FUNCTIONS, ADMIN_CSS):
        assert path.is_file(), f"Missing {path.relative_to(ROOT)}"


def test_site_health_class_api() -> None:
    src = SITE_HEALTH.read_text(encoding="utf-8")
    for needle in (
        "class AP_Site_Health",
        "function getChecks",
        "function getSummary",
        "function getOverallStatus",
        "function getInfo",
        "function getInfoText",
        "function clearCaches",
        "function deleteExpiredTransients",
        "STATUS_GOOD",
        "STATUS_RECOMMENDED",
        "STATUS_CRITICAL",
        "ap_site_health_checks",
        "ap_site_health_info",
        "DEFAULT_SALT_PLACEHOLDER",
        "checkAutoloadOptions",
        "checkPhpMemory",
        "checkPageCache",
        "infoPerformance",
    ):
        assert needle in src, f"Expected {needle!r} in class-ap-site-health.php"


def test_procedural_wrappers() -> None:
    src = FUNCTIONS.read_text(encoding="utf-8")
    for needle in (
        "function ap_get_site_health_checks",
        "function ap_get_site_health_summary",
        "function ap_get_site_health_status",
        "function ap_get_site_health_info",
        "function ap_get_site_health_info_text",
        "function ap_clear_site_health_caches",
    ):
        assert needle in src, f"Expected {needle!r} in functions.php"


def test_bootstrap_loads_site_health() -> None:
    src = BOOTSTRAP.read_text(encoding="utf-8")
    assert "class-ap-site-health.php" in src
    assert "class-ap-requirements.php" in src


def test_roles_include_view_site_health() -> None:
    src = ROLES.read_text(encoding="utf-8")
    assert "view_site_health" in src


def test_admin_menu_and_screen() -> None:
    admin = ADMIN_CLASS.read_text(encoding="utf-8")
    for needle in (
        "site-health.php",
        "view_site_health",
        "Site Health",
        "'id' => 'site-health'",
    ):
        assert needle in admin, f"Expected {needle!r} in class-ap-admin.php"

    screen = ADMIN_SCREEN.read_text(encoding="utf-8")
    for needle in (
        "view_site_health",
        "site-health-clear-caches",
        "ap_get_site_health_checks",
        "Clear caches",
        "System information",
        "tab",
    ):
        assert needle in screen, f"site-health.php missing {needle!r}"


def test_admin_css_has_site_health_styles() -> None:
    css = ADMIN_CSS.read_text(encoding="utf-8")
    for needle in (
        "ap-site-health-badge",
        "ap-site-health-tabs",
        "ap-site-health-info-text",
    ):
        assert needle in css, f"admin.css missing {needle!r}"


def test_structure_lists_site_health() -> None:
    src = STRUCTURE.read_text(encoding="utf-8")
    for path in (
        "ap-includes/class-ap-site-health.php",
        "ap-admin/site-health.php",
    ):
        assert path in src, f"Structure assert missing {path}"


def test_changelog_mentions_site_health() -> None:
    text = CHANGELOG.read_text(encoding="utf-8")
    assert "Site Health" in text or "site health" in text.lower()
    assert "AP_Site_Health" in text


def test_phpunit_site_health_suite() -> None:
    cmd = [
        _php_bin(),
        str(ROOT / "vendor" / "bin" / "phpunit"),
        "--configuration",
        str(ROOT / "phpunit.xml.dist"),
        str(PHPUNIT),
    ]
    proc = subprocess.run(cmd, cwd=ROOT, capture_output=True, text=True, timeout=120)
    assert proc.returncode == 0, proc.stdout + "\n" + proc.stderr
