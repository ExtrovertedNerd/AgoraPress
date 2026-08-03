"""
Smoke tests for MU plugins, Shortcodes, Cron, Transients, and Settings wiring.

Runnable via:
  pytest tests/test_mu_plugins_shortcodes_cron_transients.py -v
"""

from __future__ import annotations

import shutil
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PLUGIN = ROOT / "ap-includes" / "class-ap-plugin.php"
TRANSIENT = ROOT / "ap-includes" / "class-ap-transient.php"
SHORTCODE = ROOT / "ap-includes" / "class-ap-shortcode.php"
CRON = ROOT / "ap-includes" / "class-ap-cron.php"
SETTINGS = ROOT / "ap-includes" / "class-ap-settings.php"
OPTIONS = ROOT / "ap-includes" / "class-ap-options.php"
FUNCTIONS = ROOT / "ap-includes" / "functions.php"
BOOTSTRAP = ROOT / "ap-includes" / "bootstrap.php"
ADMIN_PLUGINS = ROOT / "ap-admin" / "plugins.php"
STRUCTURE = ROOT / "tests" / "Structure" / "assert-structure.php"
MU_DIR = ROOT / "ap-content" / "mu-plugins"

PHPUNIT_TESTS = [
    ROOT / "tests" / "Plugin" / "MuPluginTest.php",
    ROOT / "tests" / "Plugin" / "ShortcodeTest.php",
    ROOT / "tests" / "Options" / "TransientTest.php",
    ROOT / "tests" / "Options" / "CronTest.php",
    ROOT / "tests" / "Options" / "SettingsApiTest.php",
]


def _php_bin() -> str:
    return shutil.which("php") or "php"


def test_core_files_exist() -> None:
    for path in (PLUGIN, TRANSIENT, SHORTCODE, CRON, SETTINGS, OPTIONS):
        assert path.is_file(), f"Missing {path.name}"
    assert MU_DIR.is_dir()
    for path in PHPUNIT_TESTS:
        assert path.is_file(), f"Missing {path.name}"


def test_mu_plugin_api_surface() -> None:
    src = PLUGIN.read_text(encoding="utf-8")
    for needle in (
        "function muPluginsRoot",
        "function listMuPlugins",
        "function loadMuPlugins",
        "function isMuLoaded",
        "function setMuPluginsRootOverride",
        "ap_mu_plugins_loaded",
    ):
        assert needle in src, f"Expected {needle!r} in class-ap-plugin.php"

    fn = FUNCTIONS.read_text(encoding="utf-8")
    for needle in (
        "function ap_get_mu_plugins",
        "function ap_get_mu_plugins_dir",
        "function ap_load_mu_plugins",
        "function ap_is_mu_plugin_loaded",
    ):
        assert needle in fn, f"Expected {needle!r} in functions.php"


def test_transient_api_surface() -> None:
    src = TRANSIENT.read_text(encoding="utf-8")
    for needle in (
        "class AP_Transient",
        "function get",
        "function set",
        "function delete",
        "_transient_",
    ):
        assert needle in src, f"Expected {needle!r} in class-ap-transient.php"

    fn = FUNCTIONS.read_text(encoding="utf-8")
    for needle in (
        "function ap_get_transient",
        "function ap_set_transient",
        "function ap_delete_transient",
    ):
        assert needle in fn, f"Expected {needle!r} in functions.php"


def test_shortcode_api_surface() -> None:
    src = SHORTCODE.read_text(encoding="utf-8")
    for needle in (
        "class AP_Shortcode",
        "function add",
        "function remove",
        "function doShortcode",
        "function formatContent",
        "function strip",
        "function parseAtts",
        "function registerCore",
    ):
        assert needle in src, f"Expected {needle!r} in class-ap-shortcode.php"

    fn = FUNCTIONS.read_text(encoding="utf-8")
    for needle in (
        "function ap_add_shortcode",
        "function ap_remove_shortcode",
        "function ap_do_shortcode",
        "function ap_strip_shortcodes",
        "function ap_has_shortcode",
        "function ap_shortcode_exists",
        "function ap_shortcode_parse_atts",
        "function ap_shortcode_atts",
    ):
        assert needle in fn, f"Expected {needle!r} in functions.php"


def test_cron_api_surface() -> None:
    src = CRON.read_text(encoding="utf-8")
    for needle in (
        "class AP_Cron",
        "function scheduleEvent",
        "function scheduleSingle",
        "function unschedule",
        "function clearHook",
        "function nextScheduled",
        "function runDue",
        "function spawn",
        "function schedules",
    ):
        assert needle in src, f"Expected {needle!r} in class-ap-cron.php"

    fn = FUNCTIONS.read_text(encoding="utf-8")
    for needle in (
        "function ap_schedule_event",
        "function ap_schedule_single_event",
        "function ap_unschedule_event",
        "function ap_clear_scheduled_hook",
        "function ap_next_scheduled",
        "function ap_cron_run_due",
        "function ap_spawn_cron",
        "function ap_get_cron_schedules",
    ):
        assert needle in fn, f"Expected {needle!r} in functions.php"


def test_bootstrap_wires_all() -> None:
    src = BOOTSTRAP.read_text(encoding="utf-8")
    for needle in (
        "class-ap-transient.php",
        "class-ap-shortcode.php",
        "class-ap-cron.php",
        "loadMuPlugins",
        "loadActivePlugins",
        "registerCore",
        "AP_Shortcode::registerCore",
        "AP_Cron::spawn",
        "ap_the_content",
    ):
        assert needle in src, f"Expected {needle!r} in bootstrap.php"


def test_admin_lists_mu_plugins() -> None:
    src = ADMIN_PLUGINS.read_text(encoding="utf-8")
    assert "ap_get_mu_plugins" in src
    assert "Must-Use" in src or "Must-use" in src or "must-use" in src.lower()


def test_structure_lists_new_classes() -> None:
    src = STRUCTURE.read_text(encoding="utf-8")
    for name in (
        "class-ap-transient.php",
        "class-ap-shortcode.php",
        "class-ap-cron.php",
        "class-ap-settings.php",
        "class-ap-options.php",
    ):
        assert name in src


def test_phpunit_suite() -> None:
    cmd = [
        _php_bin(),
        str(ROOT / "vendor" / "bin" / "phpunit"),
        "--configuration",
        str(ROOT / "phpunit.xml.dist"),
        "--colors=never",
    ] + [str(p) for p in PHPUNIT_TESTS]
    proc = subprocess.run(cmd, cwd=ROOT, capture_output=True, text=True)
    if proc.returncode != 0:
        sys.stdout.write(proc.stdout)
        sys.stderr.write(proc.stderr)
    assert proc.returncode == 0, "PHPUnit suite for MU/shortcode/cron/transients/settings failed"
