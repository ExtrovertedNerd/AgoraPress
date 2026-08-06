"""
Smoke tests for ACP Analytics screen (capability-gated reports).

Covers: screen file, menu wiring, manage_options gate, summary/top paths/
referrers/daily markup, CSS, and AdminAnalyticsTest PHPUnit suite.

Runnable via:
  pytest tests/test_admin_analytics.py -v
"""

from __future__ import annotations

import shutil
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
ADMIN = ROOT / "ap-admin"
SCREEN = ADMIN / "analytics.php"
HELPER = ADMIN / "includes" / "class-ap-admin-analytics.php"
ADMIN_CLASS = ADMIN / "includes" / "class-ap-admin.php"
BOOTSTRAP = ADMIN / "admin-bootstrap.php"
CSS = ADMIN / "css" / "admin.css"
PHPUNIT = ROOT / "tests" / "Admin" / "AdminAnalyticsTest.php"


def _php_bin() -> str:
    return shutil.which("php") or "php"


def test_analytics_files_exist() -> None:
    for path in (SCREEN, HELPER, ADMIN_CLASS, BOOTSTRAP, CSS, PHPUNIT):
        assert path.is_file(), f"Missing {path.relative_to(ROOT)}"


def test_screen_markup_and_cap() -> None:
    src = SCREEN.read_text(encoding="utf-8")
    for needle in (
        "requireCapability",
        "AP_Admin_Analytics::CAPABILITY",
        "getReport",
        "Pageviews",
        "Top paths",
        "Top referrers",
        "Daily pageviews",
        "ap-analytics-stat",
        "ap-analytics-grid",
        "ap-analytics-daily",
        "Collection is off",
        # Dedicated Analytics settings section
        "ap-analytics-settings",
        "Analytics settings",
        'name="analytics_enabled"',
        'name="analytics_retention_days"',
        "Enable pageview collection",
        "Save Analytics Settings",
        "saveSettingsFromPost",
        "#ap-analytics-settings",
        # Empty states (disabled / no data)
        "emptyStateKind",
        "emptyStateFor",
        "renderEmptyState",
        "ap-analytics-empty-banner",
        "ap-analytics-disabled-notice",
        "Collection is off",
        "Counts stay at zero until",
        # Days window tabs + privacy intro + report widgets
        "ap-analytics-days-tabs",
        "AP_Admin_Analytics::ALLOWED_DAYS",
        "Report window",
        "ap-analytics-intro",
        "AP_Admin_Analytics::PRIVACY_INTRO",
        "AP_Admin_Analytics::PRIVACY_COLLECTION_HELP",
        "ap-analytics-collection-help",
        'role="note"',
        "Last 7 days",
        "Last 30 days",
        "ap-analytics-path",
        "ap-analytics-referrer",
        "ap-analytics-bar",
    ):
        assert needle in src, f"analytics.php missing {needle!r}"


def test_helper_api() -> None:
    src = HELPER.read_text(encoding="utf-8")
    for needle in (
        "class AP_Admin_Analytics",
        "const CAPABILITY",
        "manage_options",
        "const NONCE_ACTION",
        "const SETTINGS_SUBMIT",
        "const ALLOWED_DAYS",
        "const DEFAULT_DAYS",
        "const DEFAULT_TOP_LIMIT",
        "const PRIVACY_INTRO",
        "const PRIVACY_COLLECTION_HELP",
        "never sent to third parties",
        "Hall of Fame",
        "version check",
        "No third-party scripts",
        "function getReport",
        "function sanitizeDays",
        "function maxDailyHits",
        "function truncateLabel",
        "function currentUserCanView",
        "function isSettingsPost",
        "function saveSettingsFromPost",
        "function emptyStateKind",
        "function emptyStateFor",
        "function renderEmptyState",
        "top_paths",
        "top_referrers",
        "last_7_days",
        "last_30_days",
        "disabled_no_data",
        "enabled_no_data",
        "disabled_with_history",
        "has_data",
    ):
        assert needle in src, f"AP_Admin_Analytics missing {needle!r}"


def test_menu_and_screen_map() -> None:
    src = ADMIN_CLASS.read_text(encoding="utf-8")
    assert "'analytics.php' => 'manage_options'" in src or '"analytics.php" => "manage_options"' in src
    assert "'id' => 'analytics'" in src or '"id" => "analytics"' in src
    assert "analytics.php" in src
    assert "'section' => 'tools'" in src


def test_bootstrap_loads_helper() -> None:
    src = BOOTSTRAP.read_text(encoding="utf-8")
    assert "class-ap-admin-analytics.php" in src


def test_analytics_css() -> None:
    css = CSS.read_text(encoding="utf-8")
    for needle in (
        "ap-analytics-stat-list",
        "ap-analytics-grid",
        "ap-analytics-days-tabs",
        "ap-analytics-bar",
        "ap-analytics-table",
        "ap-analytics-settings",
        "ap-analytics-empty",
        "ap-analytics-empty-banner",
        "ap-analytics-summary-hint",
    ):
        assert needle in css, f"admin.css missing {needle!r}"


def test_admin_flash_message_analytics_saved() -> None:
    src = ADMIN_CLASS.read_text(encoding="utf-8")
    assert "analytics_saved" in src
    assert "Analytics settings saved" in src


def test_phpunit_covers_acceptance_and_days_window() -> None:
    """AdminAnalyticsTest includes SPEC acceptance + days-window cases."""
    src = PHPUNIT.read_text(encoding="utf-8")
    for needle in (
        "testAcpAcceptanceEnableRecordReportDisable",
        "testGetReportDaysWindowFiltersTopLists",
        "testScreenHasDaysWindowTabsAndPrivacyIntro",
        "testPrivacyHelpTextLocalOnlyNoThirdPartyNotHofOrVersionCheck",
        "testAllowedDaysConstant",
        "testSaveSettingsFromPostSubscriberMessageKey",
        "testGetReportWithHits",
        "testSaveSettingsFromPostEnablesAndSetsRetention",
        "testEmptyStateKind",
        "testAdminCanManageOptionsSubscriberCannot",
    ):
        assert needle in src, f"AdminAnalyticsTest missing {needle!r}"


def test_phpunit_admin_analytics() -> None:
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
    result = subprocess.run(cmd, cwd=str(ROOT), capture_output=True, text=True, check=False)
    combined = (result.stdout or "") + (result.stderr or "")
    if result.returncode != 0:
        sys.stdout.write(result.stdout or "")
        sys.stderr.write(result.stderr or "")
    assert result.returncode == 0, f"AdminAnalyticsTest failed:\n{combined}"
