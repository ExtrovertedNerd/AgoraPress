"""
Smoke tests for the default Agora theme: 6 color schemes + theme options.

Runnable via:
  pytest tests/test_agora_theme.py -v
"""

from __future__ import annotations

import re
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
AGORA = ROOT / "ap-content" / "themes" / "agora"
STYLE = AGORA / "style.css"
FUNCTIONS = AGORA / "functions.php"
HEADER = AGORA / "header.php"
THEME_OPTIONS = ROOT / "ap-admin" / "theme-options.php"
INSTALLER = ROOT / "ap-includes" / "class-ap-installer.php"
ADMIN = ROOT / "ap-admin" / "includes" / "class-ap-admin.php"

SCHEMES = ("marble", "parchment", "cloud", "obsidian", "midnight", "charcoal")
LIGHT = ("marble", "parchment", "cloud")
DARK = ("obsidian", "midnight", "charcoal")


def _php_bin() -> str:
    return shutil.which("php") or "php"


def test_agora_theme_files_exist() -> None:
    assert STYLE.is_file()
    assert FUNCTIONS.is_file()
    assert HEADER.is_file()
    assert THEME_OPTIONS.is_file()
    assert (AGORA / "index.php").is_file()
    assert (AGORA / "single.php").is_file()
    assert (AGORA / "page.php").is_file()


def test_style_css_has_six_scheme_selectors_and_no_images() -> None:
    css = STYLE.read_text(encoding="utf-8")
    assert "Theme Name: Agora" in css
    for slug in SCHEMES:
        assert f"agora-scheme-{slug}" in css, f"missing CSS for {slug}"

    # Lightweight / image-free: no url(...) image references.
    assert not re.search(
        r"url\s*\(\s*['\"]?(?:https?:|data:image|[^)]+\.(?:png|jpe?g|gif|webp|svg))",
        css,
        flags=re.I,
    )


def test_functions_define_scheme_api() -> None:
    src = FUNCTIONS.read_text(encoding="utf-8")
    for needle in (
        "function agora_get_color_schemes",
        "function agora_get_color_scheme",
        "function agora_set_color_scheme",
        "function agora_body_class",
        "function agora_sanitize_color_scheme",
        "AGORA_COLOR_SCHEME_OPTION",
        "AGORA_DEFAULT_COLOR_SCHEME",
        "marble",
        "parchment",
        "cloud",
        "obsidian",
        "midnight",
        "charcoal",
    ):
        assert needle in src, f"Expected {needle!r} in functions.php"


def test_header_applies_body_class_and_color_scheme_meta() -> None:
    src = HEADER.read_text(encoding="utf-8")
    assert "agora_body_class" in src
    assert "color-scheme" in src
    assert "skip-link" in src


def test_theme_options_admin_and_menu() -> None:
    opts = THEME_OPTIONS.read_text(encoding="utf-8")
    assert "agora_color_scheme" in opts
    assert "agora_set_color_scheme" in opts
    assert "Theme Options" in opts

    admin = ADMIN.read_text(encoding="utf-8")
    assert "theme-options" in admin
    assert "theme-options.php" in admin
    assert "theme_options_saved" in admin


def test_installer_seeds_marble_default() -> None:
    src = INSTALLER.read_text(encoding="utf-8")
    assert "agora_color_scheme" in src
    assert "'marble'" in src


def test_color_scheme_runtime_via_php() -> None:
    """Runtime: six schemes, set/get, body class, render with scheme."""
    root = str(ROOT)
    code = (
        "<?php\ndeclare(strict_types=1);\n"
        f"$root = {repr(root)};\n"
        "require $root . '/ap-includes/version.php';\n"
        "require $root . '/ap-includes/class-ap-db.php';\n"
        "require $root . '/ap-includes/class-ap-migrator.php';\n"
        "require $root . '/ap-includes/class-ap-post.php';\n"
        "require $root . '/ap-includes/class-ap-query.php';\n"
        "require $root . '/ap-includes/class-ap-theme.php';\n"
        "require $root . '/ap-includes/functions.php';\n"
        "AP_Post::resetRegistry();\n"
        "AP_Theme::reset();\n"
        "$pdo = new PDO('sqlite::memory:', null, null, [\n"
        "  PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,\n"
        "  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,\n"
        "]);\n"
        "$db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');\n"
        "(new AP_Migrator($db, AP_Migrator::defaultMigrationsPath()))->migrate();\n"
        "AP_Post::ensureBuiltins();\n"
        "foreach (['home'=>'https://example.test','siteurl'=>'https://example.test',\n"
        "  'stylesheet'=>'agora','template'=>'agora','blogname'=>'Scheme Test'] as $n=>$v) {\n"
        "  $db->insert('options', ['option_name'=>$n,'option_value'=>$v,'autoload'=>'yes']);\n"
        "}\n"
        "$GLOBALS['apdb'] = $db;\n"
        "AP_Theme::setThemesRootOverride($root . '/ap-content/themes');\n"
        "AP_Theme::setActiveOverride('agora', 'agora');\n"
        "AP_Theme::setup($db);\n"
        "if (!function_exists('agora_get_color_schemes')) { fwrite(STDERR,\"no api\\n\"); exit(1); }\n"
        "$schemes = agora_get_color_schemes();\n"
        "if (count($schemes) !== 6) { fwrite(STDERR,\"count\\n\"); exit(1); }\n"
        "$expected = ['marble','parchment','cloud','obsidian','midnight','charcoal'];\n"
        "if (array_keys($schemes) !== $expected) { fwrite(STDERR,\"keys\\n\"); exit(1); }\n"
        "$light = $dark = 0;\n"
        "foreach ($schemes as $m) { if ($m['mode']==='light') $light++; elseif ($m['mode']==='dark') $dark++; }\n"
        "if ($light !== 3 || $dark !== 3) { fwrite(STDERR,\"modes\\n\"); exit(1); }\n"
        "if (agora_get_color_scheme($db) !== 'marble') { fwrite(STDERR,\"default\\n\"); exit(1); }\n"
        "if (!agora_set_color_scheme('midnight', $db)) { fwrite(STDERR,\"set\\n\"); exit(1); }\n"
        "if (agora_get_color_scheme($db) !== 'midnight') { fwrite(STDERR,\"get\\n\"); exit(1); }\n"
        "if (agora_set_color_scheme('nope', $db)) { fwrite(STDERR,\"invalid ok\\n\"); exit(1); }\n"
        "$cls = agora_body_class($db);\n"
        "if (!str_contains($cls, 'agora-scheme-midnight') || !str_contains($cls, 'agora-mode-dark')) {\n"
        "  fwrite(STDERR,\"body $cls\\n\"); exit(1);\n"
        "}\n"
        "AP_Post::insert(['post_title'=>'S','post_type'=>'post','post_status'=>'publish',\n"
        "  'post_content'=>'c'], $db);\n"
        "$q = new AP_Query(['post_type'=>'post','posts_per_page'=>5], $db);\n"
        "ob_start(); AP_Theme::render($q, $db); $html = ob_get_clean();\n"
        "if (!str_contains($html, 'agora-scheme-midnight') || !str_contains($html, 'S')) {\n"
        "  fwrite(STDERR,\"render\\n\"); exit(1);\n"
        "}\n"
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
