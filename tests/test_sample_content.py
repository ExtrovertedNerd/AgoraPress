"""
Smoke tests for optional sample content (installer seeder).

Runnable via:
  pytest tests/test_sample_content.py -v
"""

from __future__ import annotations

from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SAMPLE = ROOT / "ap-includes" / "class-ap-sample-content.php"
INSTALLER = ROOT / "ap-includes" / "class-ap-installer.php"
CLI = ROOT / "ap-includes" / "class-ap-cli-install.php"
INSTALL_UI = ROOT / "install" / "index.php"
STRUCTURE = ROOT / "tests" / "Structure" / "assert-structure.php"


def test_sample_content_class_exists() -> None:
    assert SAMPLE.is_file(), "Missing class-ap-sample-content.php"
    src = SAMPLE.read_text(encoding="utf-8")
    assert "class AP_Sample_Content" in src
    assert "function seed" in src
    assert "function isInstalled" in src
    assert "hello-world" in src
    assert "sample_content_installed" in src
    assert "_ap_sample_content" in src
    assert "declare(strict_types=1)" in src


def test_installer_accepts_sample_content_option() -> None:
    src = INSTALLER.read_text(encoding="utf-8")
    assert "sample_content" in src
    assert "AP_Sample_Content" in src
    assert "class-ap-sample-content.php" in src


def test_cli_install_documents_sample_content_flag() -> None:
    src = CLI.read_text(encoding="utf-8")
    assert "sample-content" in src
    assert "no-sample-content" in src
    assert "sample_content" in src


def test_web_installer_exposes_sample_content_checkbox() -> None:
    src = INSTALL_UI.read_text(encoding="utf-8")
    assert 'name="sample_content"' in src
    assert "Add sample content" in src
    assert "ap_install_sample_content" in src


def test_structure_lists_sample_content_class() -> None:
    src = STRUCTURE.read_text(encoding="utf-8")
    assert "class-ap-sample-content.php" in src
