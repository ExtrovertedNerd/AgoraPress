"""
Smoke tests for the ACP admin router (admin.php?page=).

Runnable via:
  pytest tests/test_admin_router.py -v
"""

from __future__ import annotations

import shutil
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
ADMIN_PHP = ROOT / "ap-admin" / "admin.php"
ADMIN_CLASS = ROOT / "ap-admin" / "includes" / "class-ap-admin.php"
PHPUNIT = ROOT / "tests" / "Admin" / "AdminRouterTest.php"
LOGOS_DEMO = ROOT / "ap-content" / "plugins" / "logos" / "logos.php"


def _php_bin() -> str:
    return shutil.which("php") or "php"


def test_admin_router_files_exist() -> None:
    assert ADMIN_PHP.is_file()
    assert ADMIN_CLASS.is_file()
    assert PHPUNIT.is_file()


def test_logos_demo_plugin_ships_for_manual_smoke() -> None:
    """Phase 3 smoke: sample Logos plugin registers ACP page (sidebar + list link)."""
    assert LOGOS_DEMO.is_file(), "Missing ap-content/plugins/logos/logos.php"
    src = LOGOS_DEMO.read_text(encoding="utf-8")
    for needle in (
        "Plugin Name: Logos",
        "ap_register_admin_page",
        "'id' => 'logos'",
        "'parent' => 'settings'",
        "'menu' => 'Logos'",
        "'plugin'",
        "logos_render_settings",
        "function logos_render_settings",
    ):
        assert needle in src, f"Expected {needle!r} in logos demo plugin"


def test_admin_php_router_surface() -> None:
    src = ADMIN_PHP.read_text(encoding="utf-8")
    for needle in (
        "admin-bootstrap.php",
        "resolveRequestedAdminPage",
        "requireCapability",
        "capabilityForRegisteredPage",
        "registeredPageScreenContext",
        "notFound",
        "unknownAdminPageMessage",
        "invokeAdminPageCallback",
        "admin-header.php",
        "admin-footer.php",
        "$ap_admin_screen",
        "$ap_admin_title",
    ):
        assert needle in src, f"Expected {needle!r} in admin.php"

    # Order checks on executable body only (docblock also names some helpers).
    body_start = src.index("require_once __DIR__ . '/admin-bootstrap.php'")
    body = src[body_start:]
    # Unknown page → safe 404 before chrome/callback.
    assert body.index("resolveRequestedAdminPage") < body.index("AP_Admin::notFound")
    assert body.index("AP_Admin::notFound") < body.index("AP_Admin::requireCapability")
    # Shell pipeline: cap gate then header then callback then footer.
    assert body.index("AP_Admin::requireCapability") < body.index("admin-header.php")
    assert body.index("admin-header.php") < body.index("invokeAdminPageCallback")
    assert body.index("invokeAdminPageCallback") < body.index("admin-footer.php")

    # No arbitrary path execution from query args.
    assert "include $_GET" not in src
    assert "require $_GET" not in src
    assert "include $page" not in src
    assert "require $page" not in src


def test_admin_class_router_helpers() -> None:
    src = ADMIN_CLASS.read_text(encoding="utf-8")
    for needle in (
        "function pageUrl",
        "function sanitizePageSlug",
        "function requestPageSlug",
        "function getRegisteredAdminPage",
        "function resolveRequestedAdminPage",
        "function unknownAdminPageMessage",
        "function resolveAdminPageCallback",
        "function invokeAdminPageCallback",
        "function capabilityForRegisteredPage",
        "function registeredPageScreenContext",
        "function registeredScreenCapabilities",
        "function capabilityForScreen",
        "function isMenuItemActive",
        "function fireAdminMenu",
        "ADMIN_MENU_HOOK",
        "ADMIN_MENU_HOOK_WP",
        "function applyMenuActiveState",
        "function menuSectionForRegisteredParent",
        "function registeredMenuItems",
        "function mergeRegisteredMenuItems",
        "function isRegisteredPagePluginActive",
        "function normalizePluginBasename",
        "function viewerCanAccessRegisteredPage",
        "function pluginSettingsActionLinks",
        "function pluginSettingsActionLink",
        "function notFoundHtml",
        "function notFound",
        "http_response_code(404)",
        "admin.php → capability from AP_Admin_Menu",
        "mergeRegisteredMenuItems",
    ):
        assert needle in src, f"Expected {needle!r} in class-ap-admin.php"


def test_phpunit_covers_router_cap_gate() -> None:
    """Phase 2 acceptance: login/cap/chrome/callback + unknown page safety."""
    src = PHPUNIT.read_text(encoding="utf-8")
    for method in (
        "testAdminPhpIsRegistryOnlyRouter",
        "testAdminPhpShellPipelineOrder",
        "testPageUrlBuildsAdminPhpQuery",
        "testGetRegisteredAdminPageAllowlistOnly",
        "testResolveAndInvokeAdminPageCallback",
        "testRegisteredPageCapabilityUsedForGate",
        "testRegisteredPageScreenContext",
        "testRegisteredScreenCapabilitiesFromRegistry",
        "testCapabilityForScreenStaticAndRegistered",
        "testIsMenuItemActiveAndApplyMenuActiveState",
        "testMenuActiveStateWiresRegisteredPageScreenId",
        "testMenuSectionForRegisteredParentMapping",
        "testRegisteredMenuItemsFromRegistry",
        "testMenuItemsMergesRegistryByParentSection",
        "testMenuItemsDoesNotOverrideCoreIdsWithRegistry",
        "testMergeRegisteredMenuItemsIsNoopWithoutRegistry",
        "testIsRegisteredPagePluginActiveWithoutPluginKey",
        "testRegisteredMenuItemsHidesInactivePluginPages",
        "testMenuItemsHidesInactivePluginLinkedPages",
        "testPluginSettingsActionLinksEmptyWithoutRegistry",
        "testPluginSettingsActionLinksRequireActivePlugin",
        "testPluginSettingsActionLinksNormalizeBasename",
        "testPluginsPhpRendersSettingsActionLink",
        "testLogosDemoPluginRegistersSidebarAndPluginsListLink",
        "testNotFoundMethodExistsAndUses404",
        "testResolveRequestedAdminPageUnknownIsNull",
        "testUnknownAdminPageMessageIsStaticAndSafe",
        "testNotFoundHtmlEscapesMessageAndOmitsRequestInput",
        "testUnknownPageDoesNotInvokeCallback",
        "testAdminPhpUnknownPageGateBeforeChromeAndCallback",
        "testEndToEndRegisterLookupUrlAndInvoke",
        "testInvalidCallbackDoesNotThrowAndReturnsFalse",
    ):
        assert f"function {method}" in src, f"Expected {method} in AdminRouterTest"


def test_phpunit_admin_router() -> None:
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
    assert result.returncode == 0, "AdminRouterTest failed"
