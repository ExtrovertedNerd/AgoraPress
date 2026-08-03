"""
Smoke tests for Object Cache API + drop-in support.

Runnable via:
  pytest tests/test_object_cache.py -v
"""

from __future__ import annotations

import shutil
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
OBJECT_CACHE = ROOT / "ap-includes" / "class-ap-object-cache.php"
OBJECT_CACHE_DEFAULT = ROOT / "ap-includes" / "object-cache-default.php"
TRANSIENT = ROOT / "ap-includes" / "class-ap-transient.php"
FUNCTIONS = ROOT / "ap-includes" / "functions.php"
BOOTSTRAP = ROOT / "ap-includes" / "bootstrap.php"
STRUCTURE = ROOT / "tests" / "Structure" / "assert-structure.php"
PHPUNIT_TEST = ROOT / "tests" / "Options" / "ObjectCacheTest.php"


def _php_bin() -> str:
    return shutil.which("php") or "php"


def test_core_files_exist() -> None:
    for path in (
        OBJECT_CACHE,
        OBJECT_CACHE_DEFAULT,
        TRANSIENT,
        FUNCTIONS,
        BOOTSTRAP,
        PHPUNIT_TEST,
    ):
        assert path.is_file(), f"Missing {path.name}"


def test_object_cache_api_surface() -> None:
    src = OBJECT_CACHE.read_text(encoding="utf-8")
    for needle in (
        "class AP_Object_Cache",
        "function ap_start_object_cache",
        "function ap_using_ext_object_cache",
        "function ap_object_cache_dropin_path",
        "function ap_ensure_object_cache_api",
        "object-cache.php",
    ):
        assert needle in src, f"Expected {needle!r} in class-ap-object-cache.php"

    defaults = OBJECT_CACHE_DEFAULT.read_text(encoding="utf-8")
    for needle in (
        "function ap_cache_init",
        "function ap_cache_get",
        "function ap_cache_set",
        "function ap_cache_add",
        "function ap_cache_delete",
        "function ap_cache_flush",
        "function ap_cache_replace",
        "function ap_cache_incr",
        "function ap_cache_decr",
    ):
        assert needle in defaults, f"Expected {needle!r} in object-cache-default.php"

    fn = FUNCTIONS.read_text(encoding="utf-8")
    assert "function ap_using_object_cache" in fn

    boot = BOOTSTRAP.read_text(encoding="utf-8")
    assert "class-ap-object-cache.php" in boot
    assert "ap_start_object_cache" in boot

    transient = TRANSIENT.read_text(encoding="utf-8")
    assert "usesObjectCache" in transient
    assert "ap_using_ext_object_cache" in transient


def test_structure_assert_includes_object_cache() -> None:
    src = STRUCTURE.read_text(encoding="utf-8")
    assert "class-ap-object-cache.php" in src
    assert "object-cache-default.php" in src


def test_phpunit_object_cache() -> None:
    phpunit = ROOT / "vendor" / "bin" / "phpunit"
    if not phpunit.is_file():
        # Fall back to composer script path if vendor not installed.
        result = subprocess.run(
            [_php_bin(), "-r", "echo class_exists('PHPUnit\\\\Framework\\\\TestCase') ? '1' : '0';"],
            cwd=ROOT,
            capture_output=True,
            text=True,
            check=False,
        )
        if result.stdout.strip() != "1" and not phpunit.is_file():
            # Try running via composer
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
