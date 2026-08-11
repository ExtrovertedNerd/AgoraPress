"""
Smoke tests for Settings API + core settings screens.

Runnable via:
  pytest tests/test_settings_api.py -v
"""

from __future__ import annotations

import shutil
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SETTINGS = ROOT / "ap-includes" / "class-ap-settings.php"
OPTIONS = ROOT / "ap-includes" / "class-ap-options.php"
FUNCTIONS = ROOT / "ap-includes" / "functions.php"
BOOTSTRAP = ROOT / "ap-includes" / "bootstrap.php"
ADMIN = ROOT / "ap-admin"
STRUCTURE = ROOT / "tests" / "Structure" / "assert-structure.php"
PHPUNIT = ROOT / "tests" / "Options" / "SettingsApiTest.php"

SCREENS = [
    "options-general.php",
    "options-modules.php",
    "options-writing.php",
    "options-reading.php",
    "options-discussion.php",
    "options-media.php",
    "options-permalink.php",
]


def _php_bin() -> str:
    return shutil.which("php") or "php"


def test_core_files_exist() -> None:
    assert SETTINGS.is_file()
    assert OPTIONS.is_file()
    assert PHPUNIT.is_file()
    for name in SCREENS:
        assert (ADMIN / name).is_file(), f"Missing {name}"


def test_settings_api_surface() -> None:
    src = SETTINGS.read_text(encoding="utf-8")
    for needle in (
        "class AP_Settings",
        "function registerSetting",
        "function addSection",
        "function addField",
        "function settingsFields",
        "function doSections",
        "function doFields",
        "function save",
        "function registerCore",
        "function sanitizeCheckbox",
        "function sanitizeUrlOption",
    ):
        assert needle in src, f"Expected {needle!r} in class-ap-settings.php"


def test_options_module_helpers() -> None:
    src = OPTIONS.read_text(encoding="utf-8")
    for needle in (
        "function isModuleEnabled",
        "function updateModules",
        "function updateGeneralSettings",
        "function updateDiscussionSettings",
        "function updateMediaSettings",
        "function updateWritingSettings",
        "function updatePermalinkSettings",
        "function siteIcon",
        "MODULE_STATIC_PAGES",
        "MODULE_BLOG",
        "MODULE_FORUM",
    ):
        assert needle in src, f"Expected {needle!r} in class-ap-options.php"


def test_site_icon_on_general_settings() -> None:
    settings = SETTINGS.read_text(encoding="utf-8")
    options = OPTIONS.read_text(encoding="utf-8")
    general = (ADMIN / "options-general.php").read_text(encoding="utf-8")
    installer = (ROOT / "ap-includes" / "class-ap-installer.php").read_text(encoding="utf-8")

    assert "site_icon" in settings
    assert "registerSetting('general', 'site_icon'" in settings
    # site_icon = 0 leaves manual root favicon.ico as passive browser fallback.
    assert "favicon.ico" in settings
    assert "site_icon" in options
    assert "function siteIcon" in options
    # Media picker (upload / library / preview / remove), not a bare numeric field.
    assert "renderSiteIconField" in general
    assert "processSiteIconSave" in general
    assert "multipart/form-data" in general
    assert "'site_icon'" in installer
    # Save: manage_options page gate + processSiteIconSave (nonce + cap).
    assert "requireCapability('manage_options')" in general
    assert "message_key'] === 'nonce'" in general
    assert "message_key'] === 'cap'" in general

    media_admin = (ADMIN / "includes" / "class-ap-admin-media.php").read_text(encoding="utf-8")
    assert "function renderSiteIconField" in media_admin
    assert "function resolveSiteIconInput" in media_admin
    assert "function processSiteIconSave" in media_admin
    assert "function userCanManageSiteIcon" in media_admin
    assert "SITE_ICON_CAPABILITY" in media_admin
    assert "SITE_ICON_NONCE_ACTION" in media_admin
    assert "manage_options" in media_admin
    assert "ap_settings_general" in media_admin
    assert "site_icon_upload" in media_admin
    assert "remove_site_icon" in media_admin

    # Phase 2: derivatives generated on set/change (32/180/192/512 + ico/fallback).
    media = (ROOT / "ap-includes" / "class-ap-media.php").read_text(encoding="utf-8")
    assert "SITE_ICON_SIZES" in media
    assert "function generateSiteIconSizes" in media
    assert "function writePngAsIco" in media or "writeSiteIconIco" in media
    assert "ensureSiteIconDerivatives" in options
    # Phase 2: cleanup on remove or replace (old pack deleted; original media kept).
    assert "function cleanupSiteIconDerivatives" in media
    assert "function applySiteIconChange" in options
    assert "cleanupSiteIconDerivatives" in options


def test_procedural_wrappers() -> None:
    src = FUNCTIONS.read_text(encoding="utf-8")
    for needle in (
        "function ap_register_setting",
        "function ap_add_settings_section",
        "function ap_add_settings_field",
        "function ap_settings_fields",
        "function ap_do_settings_sections",
        "function ap_is_module_enabled",
    ):
        assert needle in src, f"Expected {needle!r} in functions.php"


def test_admin_screens_gate() -> None:
    for name in SCREENS:
        src = (ADMIN / name).read_text(encoding="utf-8")
        assert "requireCapability" in src
        assert "manage_options" in src


def test_bootstrap_wires_settings() -> None:
    src = BOOTSTRAP.read_text(encoding="utf-8")
    assert "class-ap-settings.php" in src
    assert "registerCore" in src


def test_structure_includes_settings() -> None:
    src = STRUCTURE.read_text(encoding="utf-8")
    assert "class-ap-settings.php" in src
    for name in SCREENS:
        assert name in src


def test_phpunit_settings_api() -> None:
    cmd = [
        _php_bin(),
        str(ROOT / "vendor" / "bin" / "phpunit"),
        "--colors=never",
        str(PHPUNIT),
    ]
    proc = subprocess.run(cmd, cwd=ROOT, capture_output=True, text=True)
    if proc.returncode != 0:
        sys.stdout.write(proc.stdout)
        sys.stderr.write(proc.stderr)
    assert proc.returncode == 0, "SettingsApiTest PHPUnit failed"
