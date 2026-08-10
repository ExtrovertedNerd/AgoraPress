"""
Smoke tests for AP_Admin_Menu registry store (plugin ACP pages).

Runnable via:
  pytest tests/test_admin_menu_registry.py -v
"""

from __future__ import annotations

import shutil
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MENU_CLASS = ROOT / "ap-includes" / "class-ap-admin-menu.php"
BOOTSTRAP = ROOT / "ap-includes" / "bootstrap.php"
PHPUNIT = ROOT / "tests" / "Admin" / "AdminMenuRegistryTest.php"


def _php_bin() -> str:
    return shutil.which("php") or "php"


def test_admin_menu_files_exist() -> None:
    assert MENU_CLASS.is_file()
    assert PHPUNIT.is_file()


def test_admin_menu_class_surface() -> None:
    src = MENU_CLASS.read_text(encoding="utf-8")
    for needle in (
        "class AP_Admin_Menu",
        "function register",
        "function get",
        "function all",
        "function allSorted",
        "function forPlugin",
        "function exists",
        "function remove",
        "function reset",
        "function sanitizeId",
        "function sanitizeParent",
        "function isValidCallback",
        "function allowedParents",
        "function mapWpParent",
        "function wpParentMap",
        "function normalizeCallback",
        "function registerFromWp",
        "function wpHookName",
        "class AP_Admin_String_Callback",
        "DEFAULT_CAPABILITY",
        "DEFAULT_POSITION",
        "private static array $pages",
    ):
        assert needle in src, f"Expected {needle!r} in class-ap-admin-menu.php"


def test_ap_register_admin_page_function_exists() -> None:
    funcs = (ROOT / "ap-includes" / "functions.php").read_text(encoding="utf-8")
    assert "function ap_register_admin_page" in funcs
    assert "AP_Admin_Menu::register" in funcs


def test_ap_get_admin_page_list_helpers_exist() -> None:
    funcs = (ROOT / "ap-includes" / "functions.php").read_text(encoding="utf-8")
    for needle in (
        "function ap_get_admin_page",
        "function ap_get_admin_pages",
        "function ap_get_admin_pages_sorted",
        "function ap_get_admin_pages_for_plugin",
        "AP_Admin_Menu::get",
        "AP_Admin_Menu::all",
        "AP_Admin_Menu::allSorted",
        "AP_Admin_Menu::forPlugin",
    ):
        assert needle in funcs, f"Expected {needle!r} in functions.php"


def test_wp_admin_menu_page_shims_exist() -> None:
    """Phase 4: add_options_page / add_menu_page / add_submenu_page / add_plugins_page."""
    funcs = (ROOT / "ap-includes" / "functions.php").read_text(encoding="utf-8")
    for needle in (
        "function add_options_page",
        "function add_menu_page",
        "function add_submenu_page",
        "function add_plugins_page",
        "AP_Admin_Menu::registerFromWp",
        "AP_Admin_Menu::mapWpParent",
        "options-general.php",
        "plugins.php",
    ):
        assert needle in funcs, f"Expected {needle!r} in functions.php"

    menu = MENU_CLASS.read_text(encoding="utf-8")
    for needle in (
        "options-general.php",
        "plugins.php",
        "tools.php",
        "options-forums.php",
        "update-core.php",
        "function mapWpParent",
        "function wpParentMap",
        "function normalizeCallback",
    ):
        assert needle in menu, f"Expected {needle!r} in class-ap-admin-menu.php"


def test_bootstrap_loads_admin_menu() -> None:
    src = BOOTSTRAP.read_text(encoding="utf-8")
    assert "class-ap-admin-menu.php" in src


def test_admin_bootstrap_fires_admin_menu_hooks() -> None:
    """Phase 4: admin_menu / ap_admin_menu fire on authenticated admin bootstrap."""
    admin_boot = ROOT / "ap-admin" / "admin-bootstrap.php"
    admin_class = ROOT / "ap-admin" / "includes" / "class-ap-admin.php"
    assert admin_boot.is_file()
    assert admin_class.is_file()

    boot = admin_boot.read_text(encoding="utf-8")
    assert "AP_Admin::fireAdminMenu()" in boot
    # After auth gate, not on login skip-auth path alone.
    assert "empty($ap_admin_skip_auth)" in boot
    assert boot.index("empty($ap_admin_skip_auth)") < boot.index("AP_Admin::fireAdminMenu()")

    src = admin_class.read_text(encoding="utf-8")
    for needle in (
        "function fireAdminMenu",
        "ap_admin_menu",
        "admin_menu",
        "ADMIN_MENU_HOOK",
        "ADMIN_MENU_HOOK_WP",
    ):
        assert needle in src, f"Expected {needle!r} in class-ap-admin.php"


def test_phpunit_covers_register_duplicate_missing_callback() -> None:
    """Phase 1 acceptance: register, duplicate id, missing callback."""
    src = PHPUNIT.read_text(encoding="utf-8")
    for method in (
        "testRegisterStoresNormalizedPage",
        "testRegisterRejectsDuplicateId",
        "testRegisterRejectsDuplicateIdAfterSanitize",
        "testRegisterRejectsMissingCallback",
        "testFailedRegistrationDoesNotOccupyId",
        "testProceduralRegisterAdminPage",
        "testProceduralRegisterRejectsDuplicateId",
        "testProceduralRegisterRejectsMissingCallback",
        "testWpParentMapCoversCoreSections",
        "testMapWpParent",
        "testNormalizeCallback",
        "testStringCallbackWrapperLateBinding",
        "testRegisterAcceptsStringFunctionName",
        "testAddOptionsPageShim",
        "testAddPluginsPageShim",
        "testAddMenuPageShim",
        "testAddSubmenuPageShimMapsParent",
        "testWpShimsRejectInvalidInput",
        "testWpShimsViaAdminMenuHook",
    ):
        assert f"function {method}" in src, f"Expected {method} in AdminMenuRegistryTest"


def test_phpunit_admin_menu_registry() -> None:
    cmd = [
        _php_bin(),
        str(ROOT / "vendor" / "bin" / "phpunit"),
        "--colors=never",
        str(PHPUNIT),
    ]
    result = subprocess.run(
        cmd,
        cwd=ROOT,
        capture_output=True,
        text=True,
        check=False,
    )
    if result.returncode != 0:
        sys.stderr.write(result.stdout)
        sys.stderr.write(result.stderr)
    assert result.returncode == 0, "AdminMenuRegistryTest failed"
