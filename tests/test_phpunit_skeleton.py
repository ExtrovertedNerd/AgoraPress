"""
Confirm the basic PHPUnit / static analysis skeleton is present and coherent.

Runnable via:
  pytest tests/test_phpunit_skeleton.py -v
"""

from __future__ import annotations

import re
import subprocess
import xml.etree.ElementTree as ET
from pathlib import Path

import pytest

ROOT = Path(__file__).resolve().parents[1]
PHPUNIT_XML = ROOT / "phpunit.xml.dist"
BOOTSTRAP = ROOT / "tests" / "bootstrap.php"
PHPCS_XML = ROOT / "phpcs.xml.dist"
COMPOSER = ROOT / "composer.json"
GITIGNORE = ROOT / ".gitignore"


def test_phpunit_xml_dist_exists() -> None:
    assert PHPUNIT_XML.is_file(), "Missing phpunit.xml.dist"


def test_phpunit_bootstrap_exists() -> None:
    assert BOOTSTRAP.is_file(), "Missing tests/bootstrap.php"


def test_phpunit_xml_is_valid_and_wires_bootstrap() -> None:
    tree = ET.parse(PHPUNIT_XML)
    root = tree.getroot()
    assert root.tag.endswith("phpunit") or root.tag == "phpunit"
    assert root.attrib.get("bootstrap") == "tests/bootstrap.php"

    dirs = [
        (node.text or "").strip()
        for node in root.iter()
        if node.tag.endswith("directory") or node.tag == "directory"
    ]
    assert "tests" in dirs
    assert "ap-includes" in dirs


def test_phpcs_static_analysis_config_exists() -> None:
    assert PHPCS_XML.is_file(), "Missing phpcs.xml.dist (static analysis)"
    assert (ROOT / "CODING_STANDARDS.md").is_file()


def test_composer_scripts_include_test_and_cs() -> None:
    import json

    data = json.loads(COMPOSER.read_text(encoding="utf-8"))
    scripts = data.get("scripts") or {}
    assert "test" in scripts
    assert "cs" in scripts
    assert "cs:check" in scripts


def test_gitignore_covers_tool_caches() -> None:
    text = GITIGNORE.read_text(encoding="utf-8")
    assert ".phpunit.cache" in text
    assert ".phpcs.cache" in text or "*.cache" in text


def test_phpunit_list_tests_when_vendor_present() -> None:
    phpunit = ROOT / "vendor" / "bin" / "phpunit"
    if not phpunit.is_file():
        pytest.skip("vendor/bin/phpunit not installed (run composer install)")

    result = subprocess.run(
        ["php", str(phpunit), f"--configuration={PHPUNIT_XML}", "--list-tests"],
        cwd=ROOT,
        capture_output=True,
        text=True,
        check=False,
    )
    assert result.returncode == 0, result.stdout + result.stderr
    combined = result.stdout + result.stderr
    assert re.search(r"PhpunitSkeletonTest", combined), combined
