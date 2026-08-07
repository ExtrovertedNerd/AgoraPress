"""
Smoke tests for performance + accessibility audit work.

Runnable via:
  pytest tests/test_performance_a11y.py -v
"""

from __future__ import annotations

import shutil
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
OPTIONS = ROOT / "ap-includes" / "class-ap-options.php"
DB = ROOT / "ap-includes" / "class-ap-db.php"
ASSETS = ROOT / "ap-includes" / "class-ap-assets.php"
FUNCTIONS = ROOT / "ap-includes" / "functions.php"
SITE_HEALTH = ROOT / "ap-includes" / "class-ap-site-health.php"
BOOTSTRAP = ROOT / "ap-includes" / "bootstrap.php"
HEADER = ROOT / "ap-content" / "themes" / "agora" / "header.php"
FOOTER = ROOT / "ap-content" / "themes" / "agora" / "footer.php"
STYLE = ROOT / "ap-content" / "themes" / "agora" / "style.css"
ADMIN_CLASS = ROOT / "ap-admin" / "includes" / "class-ap-admin.php"
ADMIN_HEADER = ROOT / "ap-admin" / "admin-header.php"
POSTS_TABLE = ROOT / "ap-admin" / "includes" / "class-ap-posts-list-table.php"
CONFIG_SAMPLE = ROOT / "ap-config-sample.php"
AUTOLOAD_TEST = ROOT / "tests" / "Options" / "AutoloadOptionsTest.php"
PERF_PHPUNIT = ROOT / "tests" / "Performance" / "PerformanceA11yTest.php"
CHANGELOG = ROOT / "CHANGELOG.md"


def _php_bin() -> str:
    return shutil.which("php") or "php"


def test_performance_a11y_files_exist() -> None:
    for path in (
        OPTIONS,
        DB,
        ASSETS,
        FUNCTIONS,
        SITE_HEALTH,
        BOOTSTRAP,
        HEADER,
        FOOTER,
        STYLE,
        ADMIN_CLASS,
        ADMIN_HEADER,
        POSTS_TABLE,
        CONFIG_SAMPLE,
        AUTOLOAD_TEST,
        PERF_PHPUNIT,
    ):
        assert path.is_file(), f"Missing {path.relative_to(ROOT)}"


def test_options_autoload_api() -> None:
    src = OPTIONS.read_text(encoding="utf-8")
    for needle in (
        "function loadAutoloaded",
        "function getAutoloadStats",
        "function isAutoloaded",
        "autoload = ?",
        "$autoloaded",
    ):
        assert needle in src, f"Expected {needle!r} in class-ap-options.php"

    fn = FUNCTIONS.read_text(encoding="utf-8")
    assert "function ap_load_autoloaded_options" in fn
    assert "function ap_get_autoload_option_stats" in fn


def test_db_query_counters() -> None:
    src = DB.read_text(encoding="utf-8")
    for needle in (
        "public int $num_queries",
        "function getNumQueries",
        "function getTotalQueryTime",
        "function getQueries",
        "function resetQueryLog",
        "function shouldSaveQueries",
        "AP_SAVEQUERIES",
        "AP_DEBUG_QUERIES",
    ):
        assert needle in src, f"Expected {needle!r} in class-ap-db.php"


def test_script_strategy_and_resource_hints() -> None:
    assets = ASSETS.read_text(encoding="utf-8")
    for needle in (
        "function setScriptStrategy",
        "function getScriptStrategy",
        "function parseScriptArgs",
        "sanitizeStrategy",
        "defer",
        "async",
    ):
        assert needle in assets, f"Expected {needle!r} in class-ap-assets.php"

    fn = FUNCTIONS.read_text(encoding="utf-8")
    assert "function ap_print_resource_hints" in fn
    assert "ap_resource_hints" in fn
    assert "function ap_script_add_data" in fn
    assert "function ap_get_script_strategy" in fn


def test_site_health_performance_checks() -> None:
    src = SITE_HEALTH.read_text(encoding="utf-8")
    for needle in (
        "checkPageCache",
        "checkAutoloadOptions",
        "checkPhpMemory",
        "infoPerformance",
        "autoload_options",
        "php_memory",
        "page_cache",
        "'performance'",
    ):
        assert needle in src, f"Expected {needle!r} in class-ap-site-health.php"


def test_bootstrap_primes_autoload() -> None:
    src = BOOTSTRAP.read_text(encoding="utf-8")
    assert "loadAutoloaded" in src


def test_theme_landmarks_and_contrast() -> None:
    header = HEADER.read_text(encoding="utf-8")
    assert 'role="banner"' in header
    assert 'role="main"' in header
    assert "skip-link" in header

    footer = FOOTER.read_text(encoding="utf-8")
    assert 'role="contentinfo"' in footer

    css = STYLE.read_text(encoding="utf-8")
    assert "prefers-reduced-motion" in css
    assert "prefers-contrast" in css
    assert "focus-visible" in css
    # Forum a11y hardening: focus rings + unread contrast without opacity-on-read.
    assert ".ap-btn:focus-visible" in css
    assert "--ap-forum-unread-bar-width" in css
    assert ".ap-forum-row--unread" in css


def test_admin_a11y_notices_and_pagination() -> None:
    admin = ADMIN_CLASS.read_text(encoding="utf-8")
    assert 'role="' in admin
    assert "alert" in admin
    assert "status" in admin

    header = ADMIN_HEADER.read_text(encoding="utf-8")
    assert 'role="main"' in header
    assert "skip-link" in header

    posts = POSTS_TABLE.read_text(encoding="utf-8")
    assert 'aria-label="Posts pagination"' in posts
    assert "aria-label=\"Previous page\"" in posts or "aria-label='Previous page'" in posts or 'aria-label="Previous page"' in posts


def test_config_sample_documents_savequeries() -> None:
    src = CONFIG_SAMPLE.read_text(encoding="utf-8")
    assert "AP_SAVEQUERIES" in src
    assert "AP_DEBUG_QUERIES" in src


def test_changelog_mentions_audit() -> None:
    text = CHANGELOG.read_text(encoding="utf-8")
    assert "Performance" in text or "performance" in text
    assert "accessibility" in text.lower() or "Accessibility" in text


def test_phpunit_performance_a11y_suite() -> None:
    """Run the focused PHPUnit suite when phpunit is available."""
    phpunit = ROOT / "vendor" / "bin" / "phpunit"
    if not phpunit.is_file():
        return
    proc = subprocess.run(
        [
            _php_bin(),
            str(phpunit),
            "--configuration",
            str(ROOT / "phpunit.xml.dist"),
            str(AUTOLOAD_TEST),
            str(PERF_PHPUNIT),
        ],
        cwd=str(ROOT),
        capture_output=True,
        text=True,
        timeout=120,
    )
    assert proc.returncode == 0, proc.stdout + "\n" + proc.stderr
