"""
Smoke tests for one-click core auto-update (AP_Core_Updater).

Runnable via:
  pytest tests/test_core_updater.py -v
"""

from __future__ import annotations

import shutil
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
UPDATER = ROOT / "ap-includes" / "class-ap-core-updater.php"
VERSION_CHECK = ROOT / "ap-includes" / "class-ap-version-check.php"
BOOTSTRAP = ROOT / "ap-includes" / "bootstrap.php"
FUNCTIONS = ROOT / "ap-includes" / "functions.php"
ADMIN_SCREEN = ROOT / "ap-admin" / "update-core.php"
ADMIN_CLASS = ROOT / "ap-admin" / "includes" / "class-ap-admin.php"
PHPUNIT = ROOT / "tests" / "Admin" / "CoreUpdaterTest.php"
README = ROOT / "README.md"
CHANGELOG = ROOT / "CHANGELOG.md"
STRUCTURE = ROOT / "tests" / "Structure" / "assert-structure.php"


def _php_bin() -> str:
    return shutil.which("php") or "php"


def test_core_updater_files_exist() -> None:
    for path in (UPDATER, VERSION_CHECK, BOOTSTRAP, FUNCTIONS, ADMIN_SCREEN, PHPUNIT):
        assert path.is_file(), f"Missing {path.relative_to(ROOT)}"


def test_updater_class_api() -> None:
    src = UPDATER.read_text(encoding="utf-8")
    for needle in (
        "class AP_Core_Updater",
        "function canUpdate",
        "function run",
        "function enableMaintenance",
        "function disableMaintenance",
        "function isMaintenanceMode",
        "function shouldApplyRelative",
        "function detectPackageRoot",
        "function verifySha256",
        "function sendsSiteIdentity",
        "function setHttpTransport",
        "no-site-id",
        "ap-config.php",
        "ap-content/uploads",
        "ap-content/plugins",
        "ap-content/themes/agora",
        "MAINTENANCE_FILE",
        "DEFAULT_MAX_BYTES",
    ):
        assert needle in src, f"AP_Core_Updater missing {needle!r}"

    assert "return false;" in src  # sendsSiteIdentity


def test_version_check_links_update_now_and_sha256() -> None:
    src = VERSION_CHECK.read_text(encoding="utf-8")
    assert "Update now" in src
    assert "update-core.php" in src
    assert "sha256" in src
    assert "normalizeSha256" in src or "package_sha256" in src
    assert "AP_Core_Updater" in src


def test_bootstrap_and_functions_wiring() -> None:
    bootstrap = BOOTSTRAP.read_text(encoding="utf-8")
    assert "class-ap-core-updater.php" in bootstrap
    assert "isMaintenanceMode" in bootstrap or "AP_Core_Updater" in bootstrap

    functions = FUNCTIONS.read_text(encoding="utf-8")
    for needle in (
        "ap_can_core_update",
        "ap_run_core_update",
        "ap_is_maintenance_mode",
    ):
        assert needle in functions, f"functions.php missing {needle!r}"


def test_admin_screen_and_menu() -> None:
    screen = ADMIN_SCREEN.read_text(encoding="utf-8")
    assert "update_core" in screen
    assert "ap_run_core_update" in screen
    assert "One-click" in screen or "one-click" in screen.lower()
    assert "ap_update_action" in screen
    assert "_ap_nonce" in screen

    admin = ADMIN_CLASS.read_text(encoding="utf-8")
    assert "update-core.php" in admin
    assert "'update_core'" in admin or '"update_core"' in admin
    assert "update-core" in admin


def test_structure_lists_updater() -> None:
    src = STRUCTURE.read_text(encoding="utf-8")
    assert "class-ap-core-updater.php" in src
    assert "update-core.php" in src


def test_docs_mention_auto_update() -> None:
    readme = README.read_text(encoding="utf-8").lower()
    assert "one-click" in readme or "auto-update" in readme or "update core" in readme

    changelog = CHANGELOG.read_text(encoding="utf-8")
    assert "AP_Core_Updater" in changelog or "one-click" in changelog.lower()


def test_phpunit_core_updater() -> None:
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
    assert result.returncode == 0, "CoreUpdaterTest PHPUnit suite failed"
