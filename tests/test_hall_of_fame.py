"""
Smoke tests for Hall of Fame voluntary domain registration (no telemetry).

Runnable via:
  pytest tests/test_hall_of_fame.py -v
"""

from __future__ import annotations

import shutil
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
HOF_CLASS = ROOT / "ap-includes" / "class-ap-hall-of-fame.php"
HOF_SCREEN = ROOT / "ap-admin" / "options-hall-of-fame.php"
DASHBOARD = ROOT / "ap-admin" / "index.php"
FOOTER = ROOT / "ap-admin" / "admin-footer.php"
ADMIN_CLASS = ROOT / "ap-admin" / "includes" / "class-ap-admin.php"
BOOTSTRAP = ROOT / "ap-includes" / "bootstrap.php"
INSTALLER = ROOT / "ap-includes" / "class-ap-installer.php"
INSTALL_UI = ROOT / "install" / "index.php"
FUNCTIONS = ROOT / "ap-includes" / "functions.php"
PHPUNIT = ROOT / "tests" / "Admin" / "HallOfFameTest.php"


def _php_bin() -> str:
    return shutil.which("php") or "php"


def test_hall_of_fame_files_exist() -> None:
    for path in (HOF_CLASS, HOF_SCREEN, DASHBOARD, FOOTER, ADMIN_CLASS, PHPUNIT):
        assert path.is_file(), f"Missing {path.relative_to(ROOT)}"


def test_core_class_privacy_api() -> None:
    src = HOF_CLASS.read_text(encoding="utf-8")
    for needle in (
        "class AP_Hall_Of_Fame",
        "function isJoined",
        "function join",
        "function leave",
        "function dismissPrompt",
        "function buildPayload",
        "function usesInstallerPings",
        "function isTelemetry",
        "function normalizeDomain",
        "function shouldShowPrompt",
        "DEFAULT_ENDPOINT",
        "no telemetry",
        "domain only",
    ):
        assert needle in src, f"AP_Hall_Of_Fame missing {needle!r}"


def test_admin_screen_and_menu() -> None:
    screen = HOF_SCREEN.read_text(encoding="utf-8")
    for needle in (
        "Join the Hall of Fame",
        "Leave Hall of Fame",
        "No installer pings",
        "show_donation_button",
        "manage_options",
        "AP_Hall_Of_Fame::join",
        "voluntary",
    ):
        assert needle in screen, f"options-hall-of-fame.php missing {needle!r}"

    admin = ADMIN_CLASS.read_text(encoding="utf-8")
    assert "options-hall-of-fame" in admin
    assert "hall_of_fame_joined" in admin
    assert "'Hall of Fame'" in admin or '"Hall of Fame"' in admin


def test_dashboard_prompt_and_footer() -> None:
    dash = DASHBOARD.read_text(encoding="utf-8")
    assert "Join the Hall of Fame" in dash
    assert "shouldShowPrompt" in dash
    assert "hof-dismiss" in dash

    footer = FOOTER.read_text(encoding="utf-8")
    assert "showDonationButton" in footer
    assert "ap-footer-donate" in footer
    assert "no telemetry by default" in footer


def test_bootstrap_and_helpers() -> None:
    assert "class-ap-hall-of-fame.php" in BOOTSTRAP.read_text(encoding="utf-8")
    functions = FUNCTIONS.read_text(encoding="utf-8")
    for needle in (
        "ap_hall_of_fame_is_joined",
        "ap_hall_of_fame_status",
        "ap_show_donation_button",
        "ap_hall_of_fame_domain",
    ):
        assert needle in functions, f"functions.php missing {needle!r}"


def test_installer_has_no_phone_home() -> None:
    installer = INSTALLER.read_text(encoding="utf-8")
    assert "AP_Hall_Of_Fame" not in installer
    assert "agorapress.extrovertednerd.com/api" not in installer
    assert "'hall_of_fame_status' => ''" in installer
    assert "'show_donation_button' => '1'" in installer

    web = INSTALL_UI.read_text(encoding="utf-8")
    assert "no telemetry" in web.lower()
    assert "AP_Hall_Of_Fame" not in web


def test_phpunit_hall_of_fame() -> None:
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
    assert result.returncode == 0, "HallOfFameTest PHPUnit suite failed"
