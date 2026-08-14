"""
Smoke tests for the AgoraPress web installer.

Runnable via:
  pytest tests/test_installer.py -v
"""

from __future__ import annotations

import shutil
import subprocess
import sys
from pathlib import Path

import pytest

ROOT = Path(__file__).resolve().parents[1]
INSTALL = ROOT / "install" / "index.php"
REQUIREMENTS = ROOT / "ap-includes" / "class-ap-requirements.php"
INSTALLER = ROOT / "ap-includes" / "class-ap-installer.php"
BOOTSTRAP = ROOT / "ap-includes" / "bootstrap.php"
CONFIG = ROOT / "ap-config.php"


def _php_bin() -> str:
    return shutil.which("php") or "php"


def test_installer_files_exist() -> None:
    assert INSTALL.is_file(), "Missing install/index.php"
    assert REQUIREMENTS.is_file(), "Missing class-ap-requirements.php"
    assert INSTALLER.is_file(), "Missing class-ap-installer.php"
    assert (ROOT / "install" / "cli.php").is_file(), "Missing install/cli.php"
    assert (ROOT / "ap-includes" / "class-ap-cli-install.php").is_file()


def test_installer_supports_optional_sample_content() -> None:
    inst = INSTALLER.read_text(encoding="utf-8")
    assert "sample_content" in inst
    assert "AP_Sample_Content" in inst
    ui = INSTALL.read_text(encoding="utf-8")
    assert 'name="sample_content"' in ui
    assert "Add sample content" in ui
    sample = ROOT / "ap-includes" / "class-ap-sample-content.php"
    assert sample.is_file()
    assert "class AP_Sample_Content" in sample.read_text(encoding="utf-8")


def test_installer_classes_define_expected_api() -> None:
    req = REQUIREMENTS.read_text(encoding="utf-8")
    assert "class AP_Requirements" in req
    assert "function check" in req
    assert "function allRequiredPassed" in req

    inst = INSTALLER.read_text(encoding="utf-8")
    assert "class AP_Installer" in inst
    assert "function generateSalts" in inst
    assert "function generateConfigPhp" in inst
    assert "function hashPassword" in inst
    assert "function run" in inst
    assert "function configExists" in inst
    assert "function alreadyInstalledMessage" in inst
    # Password hashing lives on AP_User (Argon2id); installer delegates.
    assert "AP_User::hashPassword" in inst
    # writeConfigFile must refuse overwrite (already-installed protection).
    assert "alreadyInstalledMessage" in inst


def test_installer_css_keeps_field_text_readable_in_dark_mode() -> None:
    src = INSTALL.read_text(encoding="utf-8")
    for needle in (
        "color-scheme: light",
        "color-scheme: dark",
        "-webkit-text-fill-color: var(--fg)",
        "input:-webkit-autofill",
        "--accent-text",
        "color-scheme: inherit",
        "select, textarea",
        "select option",
    ):
        assert needle in src, f"Expected {needle!r} in install/index.php"
    # Advertising both schemes at once lets the UA paint white control text
    # onto author light field backgrounds during form steps.
    assert "color-scheme: light dark" not in src


def test_install_index_mentions_steps() -> None:
    src = INSTALL.read_text(encoding="utf-8")
    for needle in (
        "requirements",
        "database",
        "site",
        "AP_Installer::run",
        "AP_Requirements::check",
        "Already installed",
        "AP_Installer::configExists",
        "http_response_code(403)",
    ):
        assert needle in src, f"Expected {needle!r} in install/index.php"


def test_install_blocks_when_config_exists() -> None:
    """Web installer shows Already installed and does not run requirements when config exists."""
    created = False
    if not CONFIG.is_file():
        CONFIG.write_text("<?php\n// temporary for already-installed smoke test\n", encoding="utf-8")
        created = True

    try:
        result = subprocess.run(
            [
                _php_bin(),
                "-d",
                "display_errors=1",
                "-d",
                "error_reporting=E_ALL",
                str(INSTALL),
            ],
            cwd=str(ROOT),
            capture_output=True,
            text=True,
            check=False,
        )
        combined = (result.stdout or "") + (result.stderr or "")
        assert result.returncode == 0, f"installer exit {result.returncode}:\n{combined}"
        assert "Already installed" in combined
        assert "ap-config.php" in combined
        assert "will not overwrite" in combined
        assert "Server requirements" not in combined
        assert "Fatal error" not in combined
    finally:
        if created and CONFIG.is_file():
            CONFIG.unlink()


def test_bootstrap_links_to_installer() -> None:
    src = BOOTSTRAP.read_text(encoding="utf-8")
    assert "function ap_install_url_path" in src
    assert "install/" in src
    assert "Run the web installer" in src


def test_install_cli_renders_when_not_installed() -> None:
    if CONFIG.is_file():
        pytest.skip("ap-config.php present; cannot exercise fresh installer UI")

    result = subprocess.run(
        [
            _php_bin(),
            "-d",
            "display_errors=1",
            "-d",
            "error_reporting=E_ALL",
            str(INSTALL),
        ],
        cwd=str(ROOT),
        capture_output=True,
        text=True,
        check=False,
    )
    combined = (result.stdout or "") + (result.stderr or "")
    assert result.returncode == 0, f"installer exit {result.returncode}:\n{combined}"
    assert "AgoraPress installer" in combined
    assert "Server requirements" in combined
    assert "Fatal error" not in combined
    assert "Parse error" not in combined


if __name__ == "__main__":
    sys.exit(pytest.main([__file__, "-v"]))
