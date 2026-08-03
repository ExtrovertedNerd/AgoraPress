"""
Confirm composer.json is valid JSON and stays on a minimal-deps footing.

Runnable via:
  pytest tests/test_composer_json.py -v
"""

from __future__ import annotations

import json
import re
from pathlib import Path

import pytest

ROOT = Path(__file__).resolve().parents[1]
COMPOSER_PATH = ROOT / "composer.json"

# SPEC §1 extensions that have no OR alternative (always required).
REQUIRED_EXTS = (
    "ext-pdo",
    "ext-mbstring",
    "ext-json",
    "ext-curl",
    "ext-fileinfo",
    "ext-zip",
)

# OR / optional extensions — suggest only, never hard-require.
OPTIONAL_EXTS = (
    "ext-pdo_mysql",
    "ext-pdo_sqlite",
    "ext-pdo_pgsql",
    "ext-gd",
    "ext-imagick",
    "ext-intl",
)


@pytest.fixture(scope="module")
def composer() -> dict:
    assert COMPOSER_PATH.is_file(), "Missing composer.json"
    data = json.loads(COMPOSER_PATH.read_text(encoding="utf-8"))
    assert isinstance(data, dict)
    return data


def test_composer_json_is_valid_object(composer: dict) -> None:
    assert composer["name"] == "extrovertednerd/agorapress"
    assert composer["type"] == "project"
    assert composer["license"] == "GPL-2.0-or-later"


def test_php_constraint_is_8_2_or_higher(composer: dict) -> None:
    require = composer.get("require") or {}
    constraint = require.get("php", "")
    assert re.match(r">=\s*8\.2", constraint), (
        f"PHP constraint must require 8.2+: got {constraint!r}"
    )


def test_no_production_packages(composer: dict) -> None:
    """Core stays pure PHP — production require is php + ext-* only."""
    require = composer.get("require") or {}
    for name in require:
        assert name == "php" or name.startswith("ext-"), (
            f"Unexpected production package: {name}"
        )


@pytest.mark.parametrize("ext", REQUIRED_EXTS)
def test_required_extension_declared(composer: dict, ext: str) -> None:
    require = composer.get("require") or {}
    assert ext in require, f"composer.json require must declare {ext}"


def test_require_dev_contains_phpunit_and_coding_standards(composer: dict) -> None:
    require_dev = composer.get("require-dev") or {}
    assert "phpunit/phpunit" in require_dev
    assert re.search(r"\^11", require_dev["phpunit/phpunit"])
    assert "squizlabs/php_codesniffer" in require_dev
    allowed = {"phpunit/phpunit", "squizlabs/php_codesniffer"}
    assert set(require_dev.keys()) <= allowed, (
        f"Unexpected require-dev packages: {set(require_dev) - allowed}"
    )


def test_scripts_include_coding_standards(composer: dict) -> None:
    scripts = composer.get("scripts") or {}
    assert "cs" in scripts
    assert "cs:check" in scripts
    assert "cs:fix" in scripts


@pytest.mark.parametrize("ext", OPTIONAL_EXTS)
def test_optional_extensions_suggested_not_required(composer: dict, ext: str) -> None:
    require = composer.get("require") or {}
    suggest = composer.get("suggest") or {}
    assert ext not in require, f"{ext} must not be hard-required"
    assert ext in suggest, f"{ext} should be under suggest"


def test_autoload_classmap_includes_ap_includes(composer: dict) -> None:
    classmap = (composer.get("autoload") or {}).get("classmap") or []
    assert "ap-includes/" in classmap
