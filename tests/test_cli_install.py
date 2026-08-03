"""
Smoke tests for the AgoraPress CLI installer.

Runnable via:
  pytest tests/test_cli_install.py -v
"""

from __future__ import annotations

import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

import pytest

ROOT = Path(__file__).resolve().parents[1]
CLI = ROOT / "install" / "cli.php"
CLI_CLASS = ROOT / "ap-includes" / "class-ap-cli-install.php"
INSTALLER = ROOT / "ap-includes" / "class-ap-installer.php"


def _php_bin() -> str:
    return shutil.which("php") or "php"


def test_cli_install_files_exist() -> None:
    assert CLI.is_file(), "Missing install/cli.php"
    assert CLI_CLASS.is_file(), "Missing class-ap-cli-install.php"


def test_cli_class_defines_expected_api() -> None:
    src = CLI_CLASS.read_text(encoding="utf-8")
    assert "class AP_Cli_Install" in src
    assert "function parseArgv" in src
    assert "function execute" in src
    assert "function runFromArgv" in src
    assert "EXIT_OK" in src
    assert "AP_Installer::run" in src


def test_installer_mentions_cli_path() -> None:
    src = INSTALLER.read_text(encoding="utf-8")
    assert "CLI" in src or "cli" in src


def test_cli_help_exits_zero() -> None:
    result = subprocess.run(
        [_php_bin(), str(CLI), "--help"],
        cwd=str(ROOT),
        capture_output=True,
        text=True,
        check=False,
    )
    combined = (result.stdout or "") + (result.stderr or "")
    assert result.returncode == 0, f"exit {result.returncode}:\n{combined}"
    assert "AgoraPress CLI installer" in combined
    assert "--site-title" in combined
    assert "--db-driver" in combined
    assert "--sample-content" in combined
    assert "Fatal error" not in combined
    assert "Parse error" not in combined


def test_cli_missing_args_exits_nonzero() -> None:
    result = subprocess.run(
        [_php_bin(), str(CLI)],
        cwd=str(ROOT),
        capture_output=True,
        text=True,
        check=False,
    )
    assert result.returncode != 0
    combined = (result.stdout or "") + (result.stderr or "")
    assert "Missing required" in combined or "Usage" in combined or "site-title" in combined


def test_cli_sqlite_install_end_to_end() -> None:
    with tempfile.TemporaryDirectory(prefix="ap-cli-") as tmp:
        tmp_path = Path(tmp)
        sqlite = tmp_path / "site.sqlite"
        config = tmp_path / "ap-config.php"
        result = subprocess.run(
            [
                _php_bin(),
                str(CLI),
                "--db-driver=sqlite",
                f"--db-name={sqlite}",
                "--site-title=Pytest CLI Site",
                "--site-url=https://pytest.example.test",
                "--admin-user=pytestadmin",
                "--admin-email=pytest@example.test",
                "--admin-password=pytestpass99",
                f"--config-path={config}",
                "--skip-requirements",
            ],
            cwd=str(ROOT),
            capture_output=True,
            text=True,
            check=False,
        )
        combined = (result.stdout or "") + (result.stderr or "")
        assert result.returncode == 0, f"exit {result.returncode}:\n{combined}"
        assert "Installation complete" in combined
        assert config.is_file()
        cfg = config.read_text(encoding="utf-8")
        assert "AP_DB_DRIVER" in cfg
        assert "sqlite" in cfg
        assert sqlite.is_file()


if __name__ == "__main__":
    sys.exit(pytest.main([__file__, "-v"]))
