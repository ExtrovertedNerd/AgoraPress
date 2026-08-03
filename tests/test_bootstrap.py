"""
Smoke tests for index.php bootstrap: fails gracefully when not installed.

Runnable via:
  pytest tests/test_bootstrap.py -v
"""

from __future__ import annotations

import shutil
import subprocess
import sys
from pathlib import Path

import pytest

ROOT = Path(__file__).resolve().parents[1]
INDEX = ROOT / "index.php"
BOOTSTRAP = ROOT / "ap-includes" / "bootstrap.php"
VERSION = ROOT / "ap-includes" / "version.php"
CONFIG = ROOT / "ap-config.php"


def _php_bin() -> str:
    return shutil.which("php") or "php"


def test_bootstrap_files_exist() -> None:
    assert INDEX.is_file(), "Missing index.php"
    assert BOOTSTRAP.is_file(), "Missing ap-includes/bootstrap.php"
    assert VERSION.is_file(), "Missing ap-includes/version.php"


def test_index_not_installed_is_graceful() -> None:
    """Without ap-config.php, index.php must print a friendly page and exit 0."""
    if CONFIG.is_file():
        pytest.skip("ap-config.php present; cannot exercise not-installed path")

    result = subprocess.run(
        [
            _php_bin(),
            "-d",
            "display_errors=1",
            "-d",
            "error_reporting=E_ALL",
            str(INDEX),
        ],
        cwd=str(ROOT),
        capture_output=True,
        text=True,
        check=False,
    )

    combined = (result.stdout or "") + (result.stderr or "")
    assert result.returncode == 0, f"expected graceful exit 0, got {result.returncode}:\n{combined}"
    assert "AgoraPress is not installed" in combined
    assert "ap-config.php" in combined
    assert "install/" in combined
    assert "Run the web installer" in combined
    assert "Fatal error" not in combined
    assert "Parse error" not in combined
    assert "Uncaught" not in combined


def test_bootstrap_defines_install_helpers() -> None:
    """bootstrap.php exposes ap_is_installed and related helpers."""
    src = BOOTSTRAP.read_text(encoding="utf-8")
    for needle in (
        "function ap_is_installed",
        "function ap_get_not_installed_html",
        "function ap_bootstrap",
        "function ap_php_version_is_supported",
        "function ap_install_url_path",
    ):
        assert needle in src, f"Expected {needle} in bootstrap.php"


def test_version_php_defines_ap_version() -> None:
    src = VERSION.read_text(encoding="utf-8")
    assert "AP_VERSION" in src
    assert "declare(strict_types=1)" in src


if __name__ == "__main__":
    sys.exit(pytest.main([__file__, "-v"]))
