"""
Confirm coding standards config (PSR-12 adapted) is present and coherent.

Runnable via:
  pytest tests/test_coding_standards_config.py -v
"""

from __future__ import annotations

import re
import xml.etree.ElementTree as ET
from pathlib import Path

import pytest

ROOT = Path(__file__).resolve().parents[1]
PHPCS_PATH = ROOT / "phpcs.xml.dist"
DOCS_PATH = ROOT / "CODING_STANDARDS.md"


def test_phpcs_xml_dist_exists() -> None:
    assert PHPCS_PATH.is_file(), "Missing phpcs.xml.dist"


def test_coding_standards_doc_exists() -> None:
    assert DOCS_PATH.is_file(), "Missing CODING_STANDARDS.md"


def test_phpcs_ruleset_valid_xml_and_psr12() -> None:
    tree = ET.parse(PHPCS_PATH)
    root = tree.getroot()
    assert root.tag == "ruleset"
    assert root.attrib.get("name") == "AgoraPress"

    refs = [rule.attrib.get("ref", "") for rule in root.findall("rule")]
    assert "PSR12" in refs
    assert "Generic.PHP.RequireStrictTypes" in refs


def test_phpcs_ruleset_excludes_wp_hybrid_conflicts() -> None:
    text = PHPCS_PATH.read_text(encoding="utf-8")
    required = (
        "PSR1.Classes.ClassDeclaration.MissingNamespace",
        "Squiz.Classes.ValidClassName",
        "PSR1.Files.SideEffects",
    )
    for sniff in required:
        assert sniff in text, f"Expected exclude of {sniff}"


def test_phpcs_ruleset_scans_core_paths() -> None:
    tree = ET.parse(PHPCS_PATH)
    root = tree.getroot()
    files = [node.text for node in root.findall("file") if node.text]
    for expected in (
        "ap-includes",
        "ap-admin",
        "tests",
        "index.php",
        "ap-config-sample.php",
    ):
        assert expected in files


def test_coding_standards_doc_content() -> None:
    text = DOCS_PATH.read_text(encoding="utf-8")
    assert "PSR-12" in text
    assert "strict_types" in text
    assert re.search(r"\bAP_\b", text) or "AP_" in text
    assert "ap_" in text
    assert "composer cs" in text
