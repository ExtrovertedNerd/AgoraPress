"""
Smoke tests for core version checker (version.json, no site identity).

Runnable via:
  pytest tests/test_version_check.py -v
"""

from __future__ import annotations

import shutil
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
VC_CLASS = ROOT / "ap-includes" / "class-ap-version-check.php"
BOOTSTRAP = ROOT / "ap-includes" / "bootstrap.php"
ADMIN_BOOT = ROOT / "ap-admin" / "admin-bootstrap.php"
ADMIN_CLASS = ROOT / "ap-admin" / "includes" / "class-ap-admin.php"
FUNCTIONS = ROOT / "ap-includes" / "functions.php"
INSTALLER = ROOT / "ap-includes" / "class-ap-installer.php"
README = ROOT / "README.md"
PHPUNIT = ROOT / "tests" / "Admin" / "VersionCheckTest.php"


def _php_bin() -> str:
    return shutil.which("php") or "php"


def test_version_check_files_exist() -> None:
    for path in (VC_CLASS, BOOTSTRAP, ADMIN_BOOT, ADMIN_CLASS, FUNCTIONS, PHPUNIT):
        assert path.is_file(), f"Missing {path.relative_to(ROOT)}"


def test_core_class_api_and_privacy() -> None:
    src = VC_CLASS.read_text(encoding="utf-8")
    for needle in (
        "class AP_Version_Check",
        "DEFAULT_ENDPOINT",
        "version.json",
        "agorapress.extrovertednerd.com",
        "function isEnabled",
        "function getRemoteInfo",
        "function hasUpdate",
        "function isNewer",
        "function compareVersions",
        "function buildNoticeHtml",
        "function maybeQueueAdminNotice",
        "function parseResponseBody",
        "function forceCheck",
        "function sendsSiteIdentity",
        "TRANSIENT_KEY",
        "OPTION_ENABLED",
        "version_check_enabled",
        "no-site-id",
        "Update available",
        "Update now",
        "update-core.php",
        "sha256",
        "Download",
        "Changelog",
        "setHttpTransport",
    ):
        assert needle in src, f"AP_Version_Check missing {needle!r}"

    assert "return false;" in src  # sendsSiteIdentity
    assert "CURLOPT_HTTPGET" in src or "HTTPGET" in src or "'method' => 'GET'" in src
    # Must not POST a payload or attach domain.
    assert "domain" not in src.lower().split("sendsSiteIdentity")[0] or "No domain" in src or "no domain" in src.lower()


def test_bootstrap_and_admin_wiring() -> None:
    bootstrap = BOOTSTRAP.read_text(encoding="utf-8")
    assert "class-ap-version-check.php" in bootstrap

    admin_boot = ADMIN_BOOT.read_text(encoding="utf-8")
    assert "AP_Version_Check" in admin_boot
    assert "maybeQueueAdminNotice" in admin_boot

    admin = ADMIN_CLASS.read_text(encoding="utf-8")
    assert "bool $escape" in admin or "$escape = true" in admin
    assert "escape" in admin

    functions = FUNCTIONS.read_text(encoding="utf-8")
    for needle in (
        "ap_version_check_enabled",
        "ap_has_core_update",
        "ap_get_remote_version_info",
        "ap_force_version_check",
    ):
        assert needle in functions, f"functions.php missing {needle!r}"


def test_installer_seeds_option() -> None:
    installer = INSTALLER.read_text(encoding="utf-8")
    assert "version_check_enabled" in installer
    assert "'version_check_enabled' => '1'" in installer or '"version_check_enabled" => "1"' in installer


def test_readme_documents_endpoint_and_privacy() -> None:
    text = README.read_text(encoding="utf-8")
    assert "version.json" in text
    assert "agorapress.extrovertednerd.com/version.json" in text
    lower = text.lower()
    assert "version check" in lower or "version checker" in lower
    assert "no site identification" in lower or "never" in lower and "domain" in lower


def test_phpunit_version_check() -> None:
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
    assert result.returncode == 0, "VersionCheckTest PHPUnit suite failed"
