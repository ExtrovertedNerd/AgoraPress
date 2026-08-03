"""
Smoke tests for gettext i18n + RTL support.

Runnable via:
  pytest tests/test_i18n.py -v
"""

from __future__ import annotations

import shutil
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
L10N = ROOT / "ap-includes" / "class-ap-l10n.php"
FUNCTIONS = ROOT / "ap-includes" / "functions.php"
BOOTSTRAP = ROOT / "ap-includes" / "bootstrap.php"
ADMIN_HEADER = ROOT / "ap-admin" / "admin-header.php"
THEME_HEADER = ROOT / "ap-content" / "themes" / "agora" / "header.php"
ADMIN_CSS = ROOT / "ap-admin" / "css" / "admin.css"
THEME_CSS = ROOT / "ap-content" / "themes" / "agora" / "style.css"
OPTIONS_GENERAL = ROOT / "ap-admin" / "options-general.php"
PHPUNIT = ROOT / "tests" / "I18n" / "L10nTest.php"
LANG_DIR = ROOT / "ap-content" / "languages"


def _php_bin() -> str:
    return shutil.which("php") or "php"


def test_i18n_core_files_exist() -> None:
    for path in (
        L10N,
        FUNCTIONS,
        BOOTSTRAP,
        ADMIN_HEADER,
        THEME_HEADER,
        ADMIN_CSS,
        THEME_CSS,
        OPTIONS_GENERAL,
        PHPUNIT,
        LANG_DIR,
    ):
        assert path.exists(), f"Missing {path.relative_to(ROOT)}"


def test_l10n_class_api_surface() -> None:
    src = L10N.read_text(encoding="utf-8")
    for needle in (
        "class AP_L10n",
        "function determineLocale",
        "function getLocale",
        "function setLocale",
        "function isRtl",
        "function textDirection",
        "function languageAttributes",
        "function loadTextdomain",
        "function loadDefaultTextdomain",
        "function loadPluginTextdomain",
        "function loadThemeTextdomain",
        "function translate",
        "function translateWithContext",
        "function translatePlural",
        "function writeMoFile",
        "function parseMoFile",
        "OPTION_WPLANG",
        "rtlLanguages",
    ):
        assert needle in src, f"Expected {needle!r} in class-ap-l10n.php"


def test_functions_api_surface() -> None:
    fn = FUNCTIONS.read_text(encoding="utf-8")
    for needle in (
        "function ap__",
        "function ap_e",
        "function ap_n",
        "function ap_x",
        "function ap_nx",
        "function ap_esc_html__",
        "function ap_esc_attr__",
        "function ap_get_locale",
        "function ap_is_rtl",
        "function ap_get_language_attributes",
        "function ap_load_textdomain",
        "function ap_load_default_textdomain",
        "class-ap-l10n.php",
    ):
        assert needle in fn, f"Expected {needle!r} in functions.php"


def test_bootstrap_loads_l10n() -> None:
    src = BOOTSTRAP.read_text(encoding="utf-8")
    assert "class-ap-l10n.php" in src
    assert "loadDefaultTextdomain" in src


def test_html_roots_emit_dir() -> None:
    admin = ADMIN_HEADER.read_text(encoding="utf-8")
    assert 'dir="' in admin or "dir=" in admin
    assert "ap_get_html_lang" in admin or "htmlLang" in admin

    theme = THEME_HEADER.read_text(encoding="utf-8")
    assert "dir=" in theme
    assert "textDir" in theme or "ap_get_text_direction" in theme


def test_rtl_css_present() -> None:
    admin_css = ADMIN_CSS.read_text(encoding="utf-8")
    assert 'dir="rtl"' in admin_css or "[dir=\"rtl\"]" in admin_css or 'html[dir="rtl"]' in admin_css

    theme_css = THEME_CSS.read_text(encoding="utf-8")
    assert 'html[dir="rtl"]' in theme_css or "[dir=\"rtl\"]" in theme_css


def test_site_language_setting_ui() -> None:
    src = OPTIONS_GENERAL.read_text(encoding="utf-8")
    assert 'name="WPLANG"' in src
    assert "Site Language" in src
    assert "availableLocales" in src or "localeChoices" in src


def test_phpunit_l10n_passes() -> None:
    phpunit = ROOT / "vendor" / "bin" / "phpunit"
    if not phpunit.is_file():
        # Fall back to composer script path.
        phpunit_bin = shutil.which("phpunit")
        assert phpunit_bin, "phpunit not available"
        cmd = [phpunit_bin, str(PHPUNIT)]
    else:
        cmd = [_php_bin(), str(phpunit), str(PHPUNIT)]

    result = subprocess.run(
        cmd,
        cwd=str(ROOT),
        capture_output=True,
        text=True,
        timeout=120,
        check=False,
    )
    assert result.returncode == 0, (
        f"L10nTest failed:\nSTDOUT:\n{result.stdout}\nSTDERR:\n{result.stderr}"
    )
