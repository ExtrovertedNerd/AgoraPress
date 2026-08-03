"""
Smoke tests for the AgoraPress ap-cli tool.

Runnable via:
  pytest tests/test_cli_tool.py -v
"""

from __future__ import annotations

import shutil
import subprocess
from pathlib import Path

import pytest

ROOT = Path(__file__).resolve().parents[1]
CLI = ROOT / "ap-cli"
CLI_CLASS = ROOT / "ap-includes" / "class-ap-cli.php"
BOOTSTRAP = ROOT / "ap-includes" / "bootstrap.php"


def _php_bin() -> str:
    return shutil.which("php") or "php"


def test_cli_tool_files_exist() -> None:
    assert CLI.is_file(), "Missing ap-cli entry script"
    assert CLI_CLASS.is_file(), "Missing class-ap-cli.php"


def test_cli_class_defines_expected_api() -> None:
    src = CLI_CLASS.read_text(encoding="utf-8")
    assert "class AP_Cli" in src
    assert "function parseArgv" in src
    assert "function runFromArgv" in src
    assert "function addCommand" in src
    assert "EXIT_OK" in src
    assert "EXIT_NOT_INSTALLED" in src
    assert "cmdPlugin" in src
    assert "cmdOption" in src
    assert "cmdDb" in src
    assert "cmdUser" in src
    assert "cmdCron" in src
    assert "cmdSite" in src
    assert "ap_cli_init" in src


def test_entry_script_is_cli() -> None:
    src = CLI.read_text(encoding="utf-8")
    assert src.startswith("#!/usr/bin/env php")
    assert "AP_CLI" in src
    assert "AP_Cli::runFromArgv" in src
    assert "PHP_SAPI" in src


def test_bootstrap_respects_cli_flags() -> None:
    src = BOOTSTRAP.read_text(encoding="utf-8")
    assert "AP_CLI_SKIP_PLUGINS" in src
    assert "AP_CLI" in src


def test_cli_help_exit_zero() -> None:
    proc = subprocess.run(
        [_php_bin(), str(CLI), "--help"],
        cwd=str(ROOT),
        capture_output=True,
        text=True,
        check=False,
    )
    assert proc.returncode == 0
    combined = proc.stdout + proc.stderr
    assert "AgoraPress CLI" in combined
    assert "plugin" in combined
    assert "option" in combined
    assert "db" in combined


def test_cli_version() -> None:
    proc = subprocess.run(
        [_php_bin(), str(CLI), "version"],
        cwd=str(ROOT),
        capture_output=True,
        text=True,
        check=False,
    )
    assert proc.returncode == 0
    assert "AgoraPress" in proc.stdout
    assert "PHP" in proc.stdout


def test_cli_info() -> None:
    proc = subprocess.run(
        [_php_bin(), str(CLI), "cli", "info"],
        cwd=str(ROOT),
        capture_output=True,
        text=True,
        check=False,
    )
    assert proc.returncode == 0
    assert "php:" in proc.stdout
    assert "installed:" in proc.stdout


def test_cli_unknown_command() -> None:
    proc = subprocess.run(
        [_php_bin(), str(CLI), "definitely-not-a-command"],
        cwd=str(ROOT),
        capture_output=True,
        text=True,
        check=False,
    )
    assert proc.returncode == 1
    assert "Unknown command" in (proc.stdout + proc.stderr)


def test_cli_plugin_list_not_installed() -> None:
    if (ROOT / "ap-config.php").is_file():
        pytest.skip("ap-config.php present; cannot assert not-installed exit")
    proc = subprocess.run(
        [_php_bin(), str(CLI), "plugin", "list"],
        cwd=str(ROOT),
        capture_output=True,
        text=True,
        check=False,
    )
    assert proc.returncode == 3
    assert "not installed" in (proc.stdout + proc.stderr).lower()


def test_cli_invalid_path(tmp_path: Path) -> None:
    proc = subprocess.run(
        [_php_bin(), str(CLI), f"--path={tmp_path}", "plugin", "list"],
        cwd=str(ROOT),
        capture_output=True,
        text=True,
        check=False,
    )
    assert proc.returncode == 2
    assert "bootstrap" in (proc.stdout + proc.stderr).lower()


def test_cli_help_plugin() -> None:
    proc = subprocess.run(
        [_php_bin(), str(CLI), "help", "plugin"],
        cwd=str(ROOT),
        capture_output=True,
        text=True,
        check=False,
    )
    assert proc.returncode == 0
    assert "plugin" in proc.stdout.lower()
    assert "activate" in proc.stdout.lower()
