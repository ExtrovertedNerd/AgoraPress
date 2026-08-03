"""
Smoke tests for AgoraPress full action/filter hook system.

Runnable via:
  pytest tests/test_hooks.py -v
"""

from __future__ import annotations

import shutil
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
HOOKS = ROOT / "ap-includes" / "hooks.php"
HOOKS_CLASS = ROOT / "ap-includes" / "class-ap-hooks.php"
HOOK_CLASS = ROOT / "ap-includes" / "class-ap-hook.php"
BOOTSTRAP = ROOT / "ap-includes" / "bootstrap.php"
PHPUNIT = ROOT / "tests" / "Hooks" / "HooksTest.php"


def _php_bin() -> str:
    return shutil.which("php") or "php"


def test_hooks_files_exist() -> None:
    assert HOOKS.is_file(), "Missing hooks.php"
    assert HOOKS_CLASS.is_file(), "Missing class-ap-hooks.php"
    assert HOOK_CLASS.is_file(), "Missing class-ap-hook.php"
    assert PHPUNIT.is_file(), "Missing HooksTest.php"


def test_hooks_class_api() -> None:
    hook_src = HOOK_CLASS.read_text(encoding="utf-8")
    for needle in (
        "class AP_Hook",
        "function add",
        "function remove",
        "function removeAll",
        "function has",
        "function applyFilters",
        "function doAction",
    ):
        assert needle in hook_src, f"Expected {needle!r} in class-ap-hook.php"

    registry = HOOKS_CLASS.read_text(encoding="utf-8")
    for needle in (
        "class AP_Hooks",
        "function callbackId",
        "function reset",
        "class-ap-hook.php",
    ):
        assert needle in registry, f"Expected {needle!r} in class-ap-hooks.php"


def test_hooks_procedural_api() -> None:
    src = HOOKS.read_text(encoding="utf-8")
    for needle in (
        "function ap_add_action",
        "function ap_do_action",
        "function ap_do_action_ref_array",
        "function ap_remove_action",
        "function ap_remove_all_actions",
        "function ap_has_action",
        "function ap_did_action",
        "function ap_current_action",
        "function ap_doing_action",
        "function ap_add_filter",
        "function ap_apply_filters",
        "function ap_apply_filters_ref_array",
        "function ap_remove_filter",
        "function ap_remove_all_filters",
        "function ap_has_filter",
        "function ap_current_filter",
        "function ap_doing_filter",
        "function ap_reset_hooks",
        "function ap_hook_callback_id",
        "class-ap-hooks.php",
    ):
        assert needle in src, f"Expected {needle!r} in hooks.php"


def test_bootstrap_loads_hooks() -> None:
    src = BOOTSTRAP.read_text(encoding="utf-8")
    assert "hooks.php" in src
    assert "ap_do_action" in src or "ap_loaded" in src


def test_phpunit_hooks() -> None:
    result = subprocess.run(
        [
            _php_bin(),
            str(ROOT / "vendor" / "bin" / "phpunit"),
            "--configuration",
            str(ROOT / "phpunit.xml.dist"),
            str(PHPUNIT),
        ],
        cwd=str(ROOT),
        capture_output=True,
        text=True,
        check=False,
    )
    if result.returncode != 0:
        sys.stderr.write(result.stdout)
        sys.stderr.write(result.stderr)
    assert result.returncode == 0, "HooksTest failed"
