"""
Smoke tests for the AP_Assets enqueue API and its PHPUnit suite.

Runnable via:
  pytest tests/test_assets.py -v
"""

from __future__ import annotations

from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
ASSETS = ROOT / "ap-includes" / "class-ap-assets.php"
FUNCTIONS = ROOT / "ap-includes" / "functions.php"
PHPUNIT = ROOT / "tests" / "Assets" / "AssetsTest.php"


def test_assets_core_files_exist() -> None:
    assert ASSETS.is_file(), "Missing class-ap-assets.php"
    assert FUNCTIONS.is_file(), "Missing functions.php"
    assert PHPUNIT.is_file(), "Missing AssetsTest.php"


def test_assets_api_surface() -> None:
    src = ASSETS.read_text(encoding="utf-8")
    for needle in (
        "class AP_Assets",
        "function registerStyle",
        "function enqueueStyle",
        "function registerScript",
        "function enqueueScript",
        "function printStyles",
        "function printScripts",
        "function resolveOrder",
        "function addInlineStyle",
        "function addInlineScript",
        "function setScriptStrategy",
    ):
        assert needle in src, f"Expected {needle!r} in class-ap-assets.php"

    fn = FUNCTIONS.read_text(encoding="utf-8")
    for needle in (
        "function ap_enqueue_style",
        "function ap_enqueue_script",
        "function ap_print_styles",
        "function ap_print_scripts",
        "function ap_register_style",
        "function ap_register_script",
    ):
        assert needle in fn, f"Expected {needle!r} in functions.php"


def test_assets_phpunit_suite_mentions_deps() -> None:
    src = PHPUNIT.read_text(encoding="utf-8")
    assert "testStyleDependencyOrder" in src
    assert "testCircularStyleDependenciesDoNotFatal" in src
    assert "CoversClass" in src
