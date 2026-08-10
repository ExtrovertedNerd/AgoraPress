"""
Smoke tests for AgoraPress plugin discovery, headers, and activation.

Runnable via:
  pytest tests/test_plugins.py -v
"""

from __future__ import annotations

import shutil
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PLUGIN_CLASS = ROOT / "ap-includes" / "class-ap-plugin.php"
FUNCTIONS = ROOT / "ap-includes" / "functions.php"
BOOTSTRAP = ROOT / "ap-includes" / "bootstrap.php"
INSTALLER = ROOT / "ap-includes" / "class-ap-installer.php"
ADMIN_PLUGINS = ROOT / "ap-admin" / "plugins.php"
ADMIN_CLASS = ROOT / "ap-admin" / "includes" / "class-ap-admin.php"
PHPUNIT = ROOT / "tests" / "Plugin" / "PluginApiTest.php"
PLUGINS_DIR = ROOT / "ap-content" / "plugins"


def _php_bin() -> str:
    return shutil.which("php") or "php"


def test_plugin_files_exist() -> None:
    assert PLUGIN_CLASS.is_file(), "Missing class-ap-plugin.php"
    assert ADMIN_PLUGINS.is_file(), "Missing ap-admin/plugins.php"
    assert PHPUNIT.is_file()
    assert PLUGINS_DIR.is_dir()


def test_plugin_class_defines_core_api() -> None:
    src = PLUGIN_CLASS.read_text(encoding="utf-8")
    for needle in (
        "class AP_Plugin",
        "function pluginsRoot",
        "function muPluginsRoot",
        "function listPlugins",
        "function listMuPlugins",
        "function parsePluginFile",
        "function getPluginHeaders",
        "function isValidPlugin",
        "function getActivePlugins",
        "function isActive",
        "function activate",
        "function deactivate",
        "function loadActivePlugins",
        "function loadMuPlugins",
        "function registerActivationHook",
        "function registerDeactivationHook",
        "function pluginBasename",
        "OPTION_ACTIVE",
        "Plugin Name",
        "active_plugins",
    ):
        assert needle in src, f"Expected {needle!r} in class-ap-plugin.php"


def test_functions_expose_plugin_helpers() -> None:
    src = FUNCTIONS.read_text(encoding="utf-8")
    for needle in (
        "function ap_get_plugins",
        "function ap_get_plugin_data",
        "function ap_get_active_plugins",
        "function ap_is_plugin_active",
        "function ap_activate_plugin",
        "function ap_deactivate_plugin",
        "function ap_plugin_basename",
        "function ap_register_activation_hook",
        "function ap_register_deactivation_hook",
        "function ap_load_active_plugins",
        "function ap_get_plugins_dir",
        "function ap_plugin_url",
    ):
        assert needle in src, f"Expected {needle!r} in functions.php"


def test_bootstrap_loads_plugins() -> None:
    src = BOOTSTRAP.read_text(encoding="utf-8")
    assert "class-ap-plugin.php" in src
    assert "loadActivePlugins" in src
    assert "loadMuPlugins" in src


def test_installer_seeds_active_plugins() -> None:
    src = INSTALLER.read_text(encoding="utf-8")
    assert "active_plugins" in src


def test_admin_plugins_screen_and_menu() -> None:
    screen = ADMIN_PLUGINS.read_text(encoding="utf-8")
    assert "activate_plugins" in screen
    assert "requireCapability" in screen
    assert "ap_activate_plugin" in screen
    assert "ap_deactivate_plugin" in screen
    assert "ap_nonce_url" in screen or "ap_check_nonce" in screen
    assert "ap_get_mu_plugins" in screen
    # Settings action → registry router (admin.php?page=), only when active.
    assert "pluginSettingsActionLink" in screen
    assert "ap-plugin-settings-link" in screen

    admin = ADMIN_CLASS.read_text(encoding="utf-8")
    assert "plugins.php" in admin
    assert "'activate_plugins'" in admin or '"activate_plugins"' in admin
    assert "Installed Plugins" in admin
    assert "function pluginSettingsActionLink" in admin
    assert "function pluginSettingsActionLinks" in admin


def test_phpunit_plugin_api() -> None:
    result = subprocess.run(
        [
            _php_bin(),
            str(ROOT / "vendor" / "bin" / "phpunit"),
            "--configuration",
            str(ROOT / "phpunit.xml.dist"),
            str(PHPUNIT),
        ],
        cwd=str(ROOT),
        capture_output=True,
        text=True,
        check=False,
    )
    if result.returncode != 0:
        sys.stderr.write(result.stdout)
        sys.stderr.write(result.stderr)
    assert result.returncode == 0, "PluginApiTest failed"
