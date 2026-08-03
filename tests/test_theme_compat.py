"""
Smoke tests for Classic WordPress Theme Compatibility Layer.

Runnable via:
  pytest tests/test_theme_compat.py -v
"""

from __future__ import annotations

import json
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
COMPAT = ROOT / "ap-includes" / "compatibility"
CLI = COMPAT / "cli-convert.php"
BOOTSTRAP = ROOT / "ap-includes" / "bootstrap.php"
FUNCTIONS = ROOT / "ap-includes" / "functions.php"
STRUCTURE = ROOT / "tests" / "Structure" / "assert-structure.php"


def _php_bin() -> str:
    return shutil.which("php") or "php"


def test_compat_files_exist() -> None:
    assert COMPAT.is_dir()
    for name in (
        "load.php",
        "class-ap-theme-compat.php",
        "class-ap-theme-converter.php",
        "functions-shim.php",
        "template-tags.php",
        "cli-convert.php",
    ):
        assert (COMPAT / name).is_file(), f"Missing {name}"


def test_compat_class_api_surface() -> None:
    src = (COMPAT / "class-ap-theme-compat.php").read_text(encoding="utf-8")
    for needle in (
        "class AP_Theme_Compat",
        "function ensureLoaded",
        "function shouldEnableForTheme",
        "function isBlockTheme",
        "function isClassicTheme",
        "function mapHook",
        "function safeLoadFunctionsPhp",
        "function beforeThemeSetup",
        "function getMode",
        "function setMode",
        "OPTION_MODE_MAP",
        "MODE_AUTO",
        "wp_enqueue_scripts",
        "ap_enqueue_scripts",
    ):
        assert needle in src, f"Expected {needle!r} in class-ap-theme-compat.php"


def test_converter_class_api_surface() -> None:
    src = (COMPAT / "class-ap-theme-converter.php").read_text(encoding="utf-8")
    for needle in (
        "class AP_Theme_Converter",
        "function analyzePath",
        "function formatReport",
        "function conversionTips",
        "function runCli",
    ):
        assert needle in src, f"Expected {needle!r} in class-ap-theme-converter.php"


def test_shim_defines_common_wp_symbols() -> None:
    fn = (COMPAT / "functions-shim.php").read_text(encoding="utf-8")
    tags = (COMPAT / "template-tags.php").read_text(encoding="utf-8")
    combined = fn + "\n" + tags
    for needle in (
        "function add_action",
        "function add_filter",
        "function apply_filters",
        "function wp_enqueue_style",
        "function wp_enqueue_script",
        "function wp_head",
        "function wp_footer",
        "function get_stylesheet_uri",
        "function get_header",
        "function get_footer",
        "function get_template_part",
        "function have_posts",
        "function the_post",
        "function the_title",
        "function the_content",
        "function body_class",
        "function post_class",
        "function is_home",
        "function is_single",
        "function is_page",
        "function is_404",
        "function language_attributes",
        "function esc_html",
        "function home_url",
        "function register_nav_menus",
        "function add_theme_support",
    ):
        assert needle in combined, f"Expected {needle!r} in compatibility shims"


def test_bootstrap_loads_compat() -> None:
    src = BOOTSTRAP.read_text(encoding="utf-8")
    assert "compatibility/load.php" in src


def test_theme_setup_mentions_compat() -> None:
    src = (ROOT / "ap-includes" / "class-ap-theme.php").read_text(encoding="utf-8")
    assert "AP_Theme_Compat" in src
    assert "beforeThemeSetup" in src or "safeLoadFunctionsPhp" in src


def test_functions_expose_compat_helpers() -> None:
    src = FUNCTIONS.read_text(encoding="utf-8")
    for needle in (
        "function ap_load_theme_compat",
        "function ap_is_block_theme",
        "function ap_analyze_wp_theme",
    ):
        assert needle in src, f"Expected {needle!r} in functions.php"


def test_structure_lists_compatibility_dir() -> None:
    src = STRUCTURE.read_text(encoding="utf-8")
    assert "ap-includes/compatibility" in src


def test_cli_convert_help() -> None:
    proc = subprocess.run(
        [_php_bin(), str(CLI), "--help"],
        cwd=str(ROOT),
        capture_output=True,
        text=True,
        check=False,
    )
    assert proc.returncode == 0, proc.stderr
    assert "Usage:" in proc.stdout
    assert "classic" in proc.stdout.lower() or "Compatibility" in proc.stdout


def test_cli_convert_classic_theme_runtime() -> None:
    with tempfile.TemporaryDirectory(prefix="ap-compat-py-") as tmp:
        theme = Path(tmp) / "smoke-classic"
        theme.mkdir()
        (theme / "style.css").write_text(
            "/*\nTheme Name: Smoke Classic\nVersion: 0.1\n*/\n",
            encoding="utf-8",
        )
        (theme / "index.php").write_text(
            "<?php\nget_header();\nwhile (have_posts()) { the_post(); the_title(); }\nget_footer();\n",
            encoding="utf-8",
        )
        (theme / "functions.php").write_text(
            "<?php\nadd_action('wp_enqueue_scripts', function () {\n"
            "  wp_enqueue_style('smoke', get_stylesheet_uri());\n"
            "});\n",
            encoding="utf-8",
        )

        proc = subprocess.run(
            [_php_bin(), str(CLI), str(theme)],
            cwd=str(ROOT),
            capture_output=True,
            text=True,
            check=False,
        )
        assert proc.returncode == 0, proc.stdout + proc.stderr
        assert "Smoke Classic" in proc.stdout
        assert "Classic PHP theme: yes" in proc.stdout
        assert "Supported by compat layer: yes" in proc.stdout

        proc_json = subprocess.run(
            [_php_bin(), str(CLI), str(theme), "--json"],
            cwd=str(ROOT),
            capture_output=True,
            text=True,
            check=False,
        )
        assert proc_json.returncode == 0, proc_json.stderr
        data = json.loads(proc_json.stdout)
        assert data["classic"] is True
        assert data["block"] is False
        assert data["supported"] is True
        assert data["headers"]["Theme Name"] == "Smoke Classic"


def test_cli_convert_block_theme_exit() -> None:
    with tempfile.TemporaryDirectory(prefix="ap-compat-block-") as tmp:
        theme = Path(tmp) / "block-theme"
        theme.mkdir()
        (theme / "style.css").write_text("/*\nTheme Name: Blocky\n*/\n", encoding="utf-8")
        (theme / "theme.json").write_text('{"version":2}\n', encoding="utf-8")
        proc = subprocess.run(
            [_php_bin(), str(CLI), str(theme)],
            cwd=str(ROOT),
            capture_output=True,
            text=True,
            check=False,
        )
        assert proc.returncode == 3
        assert "Block" in proc.stdout or "block" in proc.stdout.lower()


def test_runtime_shims_via_php() -> None:
    root = str(ROOT)
    code = f"""<?php
declare(strict_types=1);
$root = {root!r};
require_once $root . '/ap-includes/hooks.php';
require_once $root . '/ap-includes/class-ap-theme.php';
require_once $root . '/ap-includes/functions.php';
require_once $root . '/ap-includes/template-tags.php';
require_once $root . '/ap-includes/compatibility/load.php';
AP_Theme_Compat::ensureLoaded(true);
$ok = function_exists('have_posts')
    && function_exists('wp_enqueue_style')
    && function_exists('get_header')
    && AP_Theme_Compat::mapHook('wp_enqueue_scripts') === 'ap_enqueue_scripts';
$called = false;
add_action('wp_enqueue_scripts', static function () use (&$called) {{ $called = true; }});
ap_do_action('ap_enqueue_scripts');
echo ($ok && $called) ? "OK\\n" : "FAIL\\n";
"""
    proc = subprocess.run(
        [_php_bin()],
        input=code,
        cwd=str(ROOT),
        capture_output=True,
        text=True,
        check=False,
    )
    assert proc.returncode == 0, proc.stderr
    assert proc.stdout.strip() == "OK"
