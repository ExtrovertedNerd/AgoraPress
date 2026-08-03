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
        "MODULE_STATIC_PAGES",
        "MODULE_BLOG",
        "MODULE_FORUM",
    ):
        assert needle in src, f"Expected {needle!r} in class-ap-options.php"


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
