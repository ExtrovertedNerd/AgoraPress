"""
Smoke tests for AgoraPress front-end template loader + hierarchy.

Runnable via:
  pytest tests/test_theme_loader.py -v
"""

from __future__ import annotations

import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
THEME_CLASS = ROOT / "ap-includes" / "class-ap-theme.php"
FUNCTIONS = ROOT / "ap-includes" / "functions.php"
BOOTSTRAP = ROOT / "ap-includes" / "bootstrap.php"
INDEX = ROOT / "index.php"
STRUCTURE = ROOT / "tests" / "Structure" / "assert-structure.php"
AGORA = ROOT / "ap-content" / "themes" / "agora"


def _php_bin() -> str:
    return shutil.which("php") or "php"


def test_theme_files_exist() -> None:
    assert THEME_CLASS.is_file(), "Missing class-ap-theme.php"
    assert AGORA.is_dir()
    assert (AGORA / "style.css").is_file()
    assert (AGORA / "index.php").is_file()
    assert (AGORA / "single.php").is_file()
    assert (AGORA / "page.php").is_file()
    assert (AGORA / "home.php").is_file()
    assert (AGORA / "404.php").is_file()
    assert (AGORA / "functions.php").is_file()


def test_theme_class_defines_core_api() -> None:
    src = THEME_CLASS.read_text(encoding="utf-8")
    for needle in (
        "class AP_Theme",
        "function getHierarchy",
        "function locateTemplate",
        "function loadTemplate",
        "function render",
        "function setup",
        "function getStylesheet",
        "function getTemplate",
        "function getHeader",
        "function getFooter",
        "function listThemes",
        "function parseStyleCss",
        "function getPageTemplates",
        "function isChildTheme",
        "function getStyleCssUri",
        "function getScreenshotPath",
        "function isValidTheme",
        "DEFAULT_SLUG",
        "home.php",
        "single.php",
        "page.php",
        "404.php",
    ):
        assert needle in src, f"Expected {needle!r} in class-ap-theme.php"


def test_functions_expose_theme_helpers() -> None:
    src = FUNCTIONS.read_text(encoding="utf-8")
    for needle in (
        "function ap_get_stylesheet",
        "function ap_get_template",
        "function ap_get_stylesheet_directory",
        "function ap_get_template_directory",
        "function ap_get_template_hierarchy",
        "function ap_locate_template",
        "function ap_get_header",
        "function ap_get_footer",
        "function ap_template_loader",
        "function ap_switch_theme",
        "function ap_get_themes",
        "function ap_is_child_theme",
        "function ap_get_style_css_uri",
        "function ap_enqueue_style",
        "function ap_enqueue_script",
        "function ap_register_style",
        "function ap_register_script",
        "function ap_head",
        "function ap_footer",
        "function ap_print_styles",
        "function ap_print_scripts",
    ):
        assert needle in src, f"Expected {needle!r} in functions.php"


def test_assets_class_exists() -> None:
    assets = ROOT / "ap-includes" / "class-ap-assets.php"
    assert assets.is_file()
    src = assets.read_text(encoding="utf-8")
    for needle in (
        "class AP_Assets",
        "function registerStyle",
        "function enqueueStyle",
        "function registerScript",
        "function enqueueScript",
        "function printStyles",
        "function printScripts",
        "function addInlineStyle",
        "function addInlineScript",
    ):
        assert needle in src, f"Expected {needle!r} in class-ap-assets.php"


def test_hooks_api_exists() -> None:
    hooks = ROOT / "ap-includes" / "hooks.php"
    src = hooks.read_text(encoding="utf-8")
    for needle in (
        "function ap_add_action",
        "function ap_do_action",
        "function ap_do_action_ref_array",
        "function ap_add_filter",
        "function ap_apply_filters",
        "function ap_apply_filters_ref_array",
        "function ap_remove_action",
        "function ap_remove_filter",
        "function ap_remove_all_actions",
        "function ap_remove_all_filters",
        "function ap_has_action",
        "function ap_has_filter",
        "function ap_did_action",
        "function ap_current_filter",
        "function ap_doing_filter",
        "class-ap-hooks.php",
    ):
        assert needle in src, f"Expected {needle!r} in hooks.php"


def test_bootstrap_loads_theme_class() -> None:
    src = BOOTSTRAP.read_text(encoding="utf-8")
    assert "class-ap-theme.php" in src
    assert "class-ap-assets.php" in src


def test_index_runs_template_loader() -> None:
    src = INDEX.read_text(encoding="utf-8")
    assert "ap_template_loader" in src or "AP_Theme::render" in src


def test_structure_assert_lists_theme_class() -> None:
    src = STRUCTURE.read_text(encoding="utf-8")
    assert "class-ap-theme.php" in src


def test_agora_style_css_headers() -> None:
    css = (AGORA / "style.css").read_text(encoding="utf-8")
    assert "Theme Name: Agora" in css
    # Six color schemes are defined as body.agora-scheme-* selectors.
    for slug in ("marble", "parchment", "cloud", "obsidian", "midnight", "charcoal"):
        assert f"agora-scheme-{slug}" in css


def test_theme_runtime_via_php() -> None:
    """Runtime: hierarchy, locate (child/parent), render blog index."""
    root = str(ROOT)
    code = (
        "<?php\ndeclare(strict_types=1);\n"
        f"$root = {repr(root)};\n"
        "require $root . '/ap-includes/version.php';\n"
        "require $root . '/ap-includes/class-ap-db.php';\n"
        "require $root . '/ap-includes/class-ap-migrator.php';\n"
        "require $root . '/ap-includes/class-ap-post.php';\n"
        "require $root . '/ap-includes/class-ap-query.php';\n"
        "require $root . '/ap-includes/hooks.php';\n"
        "require $root . '/ap-includes/class-ap-theme.php';\n"
        "require $root . '/ap-includes/class-ap-assets.php';\n"
        "require $root . '/ap-includes/functions.php';\n"
        "if (function_exists('ap_reset_hooks')) { ap_reset_hooks(); }\n"
        "AP_Post::resetRegistry();\n"
        "AP_Theme::reset();\n"
        "AP_Assets::reset();\n"
        "$pdo = new PDO('sqlite::memory:', null, null, [\n"
        "  PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,\n"
        "  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,\n"
        "]);\n"
        "$db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');\n"
        "(new AP_Migrator($db, AP_Migrator::defaultMigrationsPath()))->migrate();\n"
        "AP_Post::ensureBuiltins();\n"
        "$db->insert('options', ['option_name'=>'home','option_value'=>'https://example.test','autoload'=>'yes']);\n"
        "$db->insert('options', ['option_name'=>'siteurl','option_value'=>'https://example.test','autoload'=>'yes']);\n"
        "$db->insert('options', ['option_name'=>'blogname','option_value'=>'Py Theme','autoload'=>'yes']);\n"
        "$db->insert('options', ['option_name'=>'stylesheet','option_value'=>'agora','autoload'=>'yes']);\n"
        "$db->insert('options', ['option_name'=>'template','option_value'=>'agora','autoload'=>'yes']);\n"
        "$GLOBALS['apdb'] = $db;\n"
        "AP_Theme::setThemesRootOverride($root . '/ap-content/themes');\n"
        "AP_Theme::setActiveOverride('agora', 'agora');\n"
        "$postId = AP_Post::insert([\n"
        "  'post_title' => 'Py Post', 'post_type' => 'post',\n"
        "  'post_status' => 'publish', 'post_content' => 'py body',\n"
        "  'post_name' => 'py-post',\n"
        "], $db);\n"
        "$single = new AP_Query(['p' => $postId], $db);\n"
        "$h = AP_Theme::getHierarchy($single, $db);\n"
        "if ($h[0] !== 'single-post-py-post.php' || !in_array('single.php', $h, true)) {\n"
        "  fwrite(STDERR, 'hierarchy fail: ' . json_encode($h) . \"\\n\"); exit(1);\n"
        "}\n"
        "$located = AP_Theme::locateTemplate($h, false, true, [], $db);\n"
        "if ($located === '' || !str_ends_with($located, '/single.php')) {\n"
        "  fwrite(STDERR, \"locate fail: $located\\n\"); exit(1);\n"
        "}\n"
        "$home = new AP_Query(['post_type' => 'post', 'posts_per_page' => 10], $db);\n"
        "ob_start(); AP_Theme::render($home, $db); $html = ob_get_clean();\n"
        "if (strpos($html, 'Py Post') === false || strpos($html, 'py body') === false) {\n"
        "  fwrite(STDERR, \"render fail\\n$html\\n\"); exit(1);\n"
        "}\n"
        "if (strpos($html, 'agora-theme') === false) {\n"
        "  fwrite(STDERR, \"missing theme body class\\n\"); exit(1);\n"
        "}\n"
        "if (ap_get_stylesheet($db) !== 'agora') { fwrite(STDERR, \"stylesheet helper\\n\"); exit(1); }\n"
        "echo \"OK\\n\";\n"
    )
    with tempfile.NamedTemporaryFile("w", suffix=".php", delete=False) as fh:
        fh.write(code)
        path = fh.name
    try:
        result = subprocess.run(
            [_php_bin(), "-d", "display_errors=1", path],
            cwd=str(ROOT),
            capture_output=True,
            text=True,
            check=False,
        )
    finally:
        Path(path).unlink(missing_ok=True)

    combined = (result.stdout or "") + (result.stderr or "")
    assert result.returncode == 0, combined
    assert "OK" in (result.stdout or "")


if __name__ == "__main__":
    sys.exit(__import__("pytest").main([__file__, "-v"]))
