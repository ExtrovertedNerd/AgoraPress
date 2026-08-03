"""
Smoke tests for the Widgets / modular area system.

Runnable via:
  pytest tests/test_widgets.py -v
"""

from __future__ import annotations

import shutil
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
WIDGETS = ROOT / "ap-includes" / "class-ap-widgets.php"
FUNCTIONS = ROOT / "ap-includes" / "functions.php"
BOOTSTRAP = ROOT / "ap-includes" / "bootstrap.php"
WIDGETS_ADMIN = ROOT / "ap-admin" / "widgets.php"
INSTALLER = ROOT / "ap-includes" / "class-ap-installer.php"
AGORA_FUNCTIONS = ROOT / "ap-content" / "themes" / "agora" / "functions.php"
AGORA_SIDEBAR = ROOT / "ap-content" / "themes" / "agora" / "sidebar.php"
AGORA_HEADER = ROOT / "ap-content" / "themes" / "agora" / "header.php"
AGORA_FOOTER = ROOT / "ap-content" / "themes" / "agora" / "footer.php"
STRUCTURE = ROOT / "tests" / "Structure" / "assert-structure.php"
PHPUNIT_TEST = ROOT / "tests" / "Widget" / "WidgetsTest.php"


def _php_bin() -> str:
    return shutil.which("php") or "php"


def test_core_files_exist() -> None:
    for path in (
        WIDGETS,
        WIDGETS_ADMIN,
        AGORA_SIDEBAR,
        PHPUNIT_TEST,
    ):
        assert path.is_file(), f"Missing {path.relative_to(ROOT)}"


def test_widgets_api_surface() -> None:
    src = WIDGETS.read_text(encoding="utf-8")
    for needle in (
        "class AP_Widgets",
        "function registerSidebar",
        "function registerWidget",
        "function registerCore",
        "function getSidebarsWidgets",
        "function setSidebarsWidgets",
        "function isActiveSidebar",
        "function dynamicSidebar",
        "function addWidget",
        "function removeWidget",
        "function moveWidget",
        "function parseWidgetId",
        "renderTextWidget",
        "renderRecentPostsWidget",
        "renderCategoriesWidget",
        "renderSearchWidget",
        "renderPagesWidget",
        "renderNavMenuWidget",
        "sidebars_widgets",
        "ap_inactive_widgets",
    ):
        assert needle in src, f"Expected {needle!r} in class-ap-widgets.php"


def test_procedural_helpers() -> None:
    src = FUNCTIONS.read_text(encoding="utf-8")
    for needle in (
        "function ap_register_sidebar",
        "function ap_register_sidebars",
        "function ap_register_widget",
        "function ap_is_active_sidebar",
        "function ap_dynamic_sidebar",
        "function ap_get_sidebars",
    ):
        assert needle in src, f"Expected {needle!r} in functions.php"


def test_bootstrap_loads_widgets() -> None:
    boot = BOOTSTRAP.read_text(encoding="utf-8")
    assert "class-ap-widgets.php" in boot
    assert "AP_Widgets::registerCore" in boot


def test_admin_widgets_screen() -> None:
    src = WIDGETS_ADMIN.read_text(encoding="utf-8")
    for needle in (
        "edit_theme_options",
        "requireCapability",
        "ap_widgets",
        "AP_Widgets",
        "ap_widget_action",
    ):
        assert needle in src, f"Expected {needle!r} in widgets.php"


def test_installer_seeds_sidebars_option() -> None:
    src = INSTALLER.read_text(encoding="utf-8")
    assert "sidebars_widgets" in src


def test_agora_registers_sidebars() -> None:
    fn = AGORA_FUNCTIONS.read_text(encoding="utf-8")
    assert "sidebar-1" in fn
    assert "footer-1" in fn
    assert "ap_register_sidebar" in fn or "registerSidebar" in fn

    sidebar = AGORA_SIDEBAR.read_text(encoding="utf-8")
    assert "sidebar-1" in sidebar
    assert "ap_dynamic_sidebar" in sidebar or "dynamicSidebar" in sidebar

    header = AGORA_HEADER.read_text(encoding="utf-8")
    assert "site-content" in header
    assert "content-area" in header

    footer = AGORA_FOOTER.read_text(encoding="utf-8")
    assert "footer-1" in footer
    assert "ap_get_sidebar" in footer or "getSidebar" in footer


def test_structure_assert_includes_widgets() -> None:
    src = STRUCTURE.read_text(encoding="utf-8")
    assert "class-ap-widgets.php" in src
    assert "widgets.php" in src
    assert "sidebar.php" in src


def test_phpunit_widgets_pass() -> None:
    phpunit = ROOT / "vendor" / "bin" / "phpunit"
    if not phpunit.is_file():
        # Fall back to composer script if present.
        result = subprocess.run(
            [_php_bin(), str(PHPUNIT_TEST)],
            cwd=str(ROOT),
            capture_output=True,
            text=True,
            check=False,
        )
        # Direct PHPUnit file is not a standalone script — require vendor.
        assert phpunit.is_file() or "OK" in (result.stdout + result.stderr), (
            "PHPUnit binary missing; install composer deps"
        )
        return

    result = subprocess.run(
        [str(phpunit), "tests/Widget/WidgetsTest.php", "--colors=never"],
        cwd=str(ROOT),
        capture_output=True,
        text=True,
        check=False,
    )
    out = result.stdout + result.stderr
    assert result.returncode == 0, out
    assert "FAILURES" not in out
    assert "ERRORS" not in out
