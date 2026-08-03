"""
Smoke tests for Page Cache hooks + advanced-cache drop-in support.

Runnable via:
  pytest tests/test_page_cache.py -v
"""

from __future__ import annotations

import shutil
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PAGE_CACHE = ROOT / "ap-includes" / "class-ap-page-cache.php"
FUNCTIONS = ROOT / "ap-includes" / "functions.php"
BOOTSTRAP = ROOT / "ap-includes" / "bootstrap.php"
CONFIG_SAMPLE = ROOT / "ap-config-sample.php"
STRUCTURE = ROOT / "tests" / "Structure" / "assert-structure.php"
PHPUNIT_TEST = ROOT / "tests" / "Options" / "PageCacheTest.php"


def _php_bin() -> str:
    return shutil.which("php") or "php"


def test_core_files_exist() -> None:
    for path in (PAGE_CACHE, FUNCTIONS, BOOTSTRAP, CONFIG_SAMPLE, PHPUNIT_TEST):
        assert path.is_file(), f"Missing {path.name}"


def test_page_cache_api_surface() -> None:
    src = PAGE_CACHE.read_text(encoding="utf-8")
    for needle in (
        "class AP_Page_Cache",
        "function ap_start_page_cache",
        "function ap_page_cache_dropin_path",
        "function ap_register_page_cache_invalidation",
        "function ap_reset_page_cache",
        "advanced-cache.php",
        "ap_page_cache_flush",
        "ap_page_cache_purge_url",
        "ap_page_cache_purge_post",
        "ap_clean_post_cache",
        "registerInvalidationHooks",
        "shouldCacheRequest",
    ):
        assert needle in src, f"Expected {needle!r} in class-ap-page-cache.php"

    fn = FUNCTIONS.read_text(encoding="utf-8")
    for needle in (
        "function ap_page_cache_enabled",
        "function ap_using_page_cache",
        "function ap_should_cache_request",
        "function ap_skip_page_cache",
        "function ap_clean_page_cache",
        "function ap_clean_post_cache",
        "function ap_clean_topic_cache",
        "function ap_clean_forum_cache",
        "function ap_nocache_headers",
        "function ap_page_cache_skipped",
    ):
        assert needle in fn, f"Expected {needle!r} in functions.php"

    boot = BOOTSTRAP.read_text(encoding="utf-8")
    assert "class-ap-page-cache.php" in boot
    assert "ap_start_page_cache" in boot
    assert "registerInvalidationHooks" in boot

    sample = CONFIG_SAMPLE.read_text(encoding="utf-8")
    assert "AP_CACHE" in sample
    assert "advanced-cache.php" in sample


def test_structure_assert_includes_page_cache() -> None:
    src = STRUCTURE.read_text(encoding="utf-8")
    assert "class-ap-page-cache.php" in src


def test_post_lifecycle_actions_present() -> None:
    post = (ROOT / "ap-includes" / "class-ap-post.php").read_text(encoding="utf-8")
    for hook in (
        "ap_post_inserted",
        "ap_post_updated",
        "ap_post_trashed",
        "ap_post_untrashed",
        "ap_post_deleted",
    ):
        assert hook in post, f"Expected {hook!r} in class-ap-post.php"

    comment = (ROOT / "ap-includes" / "class-ap-comment.php").read_text(encoding="utf-8")
    for hook in ("ap_comment_updated", "ap_comment_deleted", "ap_comment_status_changed"):
        assert hook in comment, f"Expected {hook!r} in class-ap-comment.php"


def test_phpunit_page_cache() -> None:
    phpunit = ROOT / "vendor" / "bin" / "phpunit"
    if not phpunit.is_file():
        composer = shutil.which("composer")
        if composer:
            subprocess.run(
                [composer, "install", "--no-interaction", "--quiet"],
                cwd=ROOT,
                check=False,
            )
    assert phpunit.is_file() or (ROOT / "vendor" / "bin" / "phpunit").is_file(), (
        "PHPUnit not installed; run composer install"
    )
    phpunit_bin = phpunit if phpunit.is_file() else ROOT / "vendor" / "bin" / "phpunit"
    result = subprocess.run(
        [_php_bin(), str(phpunit_bin), str(PHPUNIT_TEST), "--colors=never"],
        cwd=ROOT,
        capture_output=True,
        text=True,
        check=False,
    )
    assert result.returncode == 0, (
        f"PHPUnit failed (exit {result.returncode}):\n"
        f"{result.stdout}\n{result.stderr}"
    )
