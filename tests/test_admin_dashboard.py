"""
Smoke tests for admin dashboard home with stats.

Runnable via:
  pytest tests/test_admin_dashboard.py -v
"""

from __future__ import annotations

import shutil
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
ADMIN = ROOT / "ap-admin"
INDEX = ADMIN / "index.php"
DASHBOARD_CLASS = ADMIN / "includes" / "class-ap-admin-dashboard.php"
BOOTSTRAP = ADMIN / "admin-bootstrap.php"
CSS = ADMIN / "css" / "admin.css"
PHPUNIT = ROOT / "tests" / "Admin" / "AdminDashboardTest.php"


def _php_bin() -> str:
    return shutil.which("php") or "php"


def test_dashboard_files_exist() -> None:
    for path in (INDEX, DASHBOARD_CLASS, BOOTSTRAP, CSS, PHPUNIT):
        assert path.is_file(), f"Missing {path.relative_to(ROOT)}"


def test_dashboard_markup_and_helpers() -> None:
    src = INDEX.read_text(encoding="utf-8")
    for needle in (
        "At a Glance",
        "Activity",
        "Quick Draft",
        "AP_Admin_Dashboard",
        "getAtAGlance",
        "getRecentContent",
        "getRecentComments",
        "saveQuickDraft",
        "ap-dashboard-grid",
        "ap-glance-list",
        "ap_dashboard_action",
        "quick-draft",
    ):
        assert needle in src, f"index.php missing {needle!r}"


def test_dashboard_class_api() -> None:
    src = DASHBOARD_CLASS.read_text(encoding="utf-8")
    for needle in (
        "class AP_Admin_Dashboard",
        "function getAtAGlance",
        "function getRecentContent",
        "function getRecentComments",
        "function saveQuickDraft",
        "function countPostsByStatus",
        "function canQuickDraft",
        "function totalFromStatusCounts",
    ):
        assert needle in src, f"AP_Admin_Dashboard missing {needle!r}"


def test_bootstrap_loads_dashboard() -> None:
    src = BOOTSTRAP.read_text(encoding="utf-8")
    assert "class-ap-admin-dashboard.php" in src


def test_dashboard_css() -> None:
    css = CSS.read_text(encoding="utf-8")
    for needle in (
        "ap-dashboard-grid",
        "ap-glance-list",
        "ap-glance-count",
        "ap-activity-list",
        "ap-quick-draft-form",
        "ap-dashboard-cards",
    ):
        assert needle in css, f"admin.css missing {needle!r}"


def test_phpunit_admin_dashboard() -> None:
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
    result = subprocess.run(cmd, cwd=str(ROOT), capture_output=True, text=True)
    if result.returncode != 0:
        sys.stdout.write(result.stdout)
        sys.stderr.write(result.stderr)
    assert result.returncode == 0, "AdminDashboardTest PHPUnit suite failed"
