"""
Smoke tests for the AgoraPress lightweight REST API.

Runnable via:
  pytest tests/test_rest_api.py -v
"""

from __future__ import annotations

import shutil
import subprocess
from pathlib import Path

import pytest

ROOT = Path(__file__).resolve().parents[1]
REST_CLASS = ROOT / "ap-includes" / "class-ap-rest.php"
BOOTSTRAP = ROOT / "ap-includes" / "bootstrap.php"
INDEX = ROOT / "index.php"
PHPUNIT = ROOT / "tests" / "Rest" / "RestApiTest.php"


def _php_bin() -> str:
    return shutil.which("php") or "php"


def test_rest_files_exist() -> None:
    assert REST_CLASS.is_file(), "Missing class-ap-rest.php"
    assert PHPUNIT.is_file(), "Missing RestApiTest.php"


def test_rest_class_defines_expected_api() -> None:
    src = REST_CLASS.read_text(encoding="utf-8")
    assert "class AP_Rest" in src
    assert "function registerRoute" in src
    assert "function dispatch" in src
    assert "function serve" in src
    assert "function isRestRequest" in src
    assert "function matchRestPath" in src
    assert "function getUrl" in src
    assert "ap/v1" in src
    assert "ap-json" in src
    assert "handlePostsList" in src
    assert "handlePostCreate" in src
    assert "handleSettings" in src
    assert "handleForumsList" in src
    assert "ap_rest_api_init" in src
    assert "OPTION_ENABLED" in src
    assert "NONCE_ACTION" in src


def test_bootstrap_and_index_wire_rest() -> None:
    bootstrap = BOOTSTRAP.read_text(encoding="utf-8")
    assert "class-ap-rest.php" in bootstrap

    index = INDEX.read_text(encoding="utf-8")
    assert "AP_Rest" in index
    assert "isRestRequest" in index


def test_rewrite_has_rest_rules() -> None:
    rewrite = (ROOT / "ap-includes" / "class-ap-rewrite.php").read_text(encoding="utf-8")
    assert "ap-json" in rewrite
    assert "rest_route" in rewrite
    assert "matchRestPath" in rewrite
    assert "getRestLink" in rewrite


def test_procedural_helpers_exist() -> None:
    funcs = (ROOT / "ap-includes" / "functions.php").read_text(encoding="utf-8")
    assert "function ap_rest_enabled" in funcs
    assert "function ap_rest_url" in funcs
    assert "function ap_register_rest_route" in funcs
    assert "function ap_rest_dispatch" in funcs
    assert "function ap_create_rest_nonce" in funcs


def test_installer_seeds_rest_option() -> None:
    installer = (ROOT / "ap-includes" / "class-ap-installer.php").read_text(encoding="utf-8")
    assert "rest_api_enabled" in installer


def test_phpunit_rest_suite_passes() -> None:
    phpunit = ROOT / "vendor" / "bin" / "phpunit"
    if not phpunit.is_file():
        pytest.skip("phpunit not installed")
    proc = subprocess.run(
        [_php_bin(), str(phpunit), "tests/Rest/RestApiTest.php"],
        cwd=str(ROOT),
        capture_output=True,
        text=True,
        timeout=120,
    )
    assert proc.returncode == 0, proc.stdout + "\n" + proc.stderr
