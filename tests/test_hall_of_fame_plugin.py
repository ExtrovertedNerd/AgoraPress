"""
Smoke tests for the project-site Hall of Fame plugin handshake + DB store.

Runnable via:
  pytest tests/test_hall_of_fame_plugin.py -v
"""

from __future__ import annotations

import shutil
import subprocess
from pathlib import Path

import pytest

ROOT = Path(__file__).resolve().parents[1]
PLUGIN = ROOT / "ap-content" / "plugins" / "agorapress-hall-of-fame"
MAIN = PLUGIN / "agorapress-hall-of-fame.php"
STORE = PLUGIN / "includes" / "class-hof-store.php"
API = PLUGIN / "includes" / "class-hof-api.php"
PUBLIC = PLUGIN / "includes" / "class-hof-public.php"
PHPUNIT = ROOT / "tests" / "Plugin" / "HallOfFamePluginTest.php"

# Project-site directory listing plugin — lives in AgoraPress_Addons, not core.
pytestmark = pytest.mark.skipif(
    not MAIN.is_file(),
    reason="agorapress-hall-of-fame is a project-site addon, not shipped in AgoraPress core",
)


def _php_bin() -> str:
    return shutil.which("php") or "php"


def test_plugin_files_exist() -> None:
    for path in (MAIN, STORE, API, PUBLIC, PHPUNIT):
        assert path.is_file(), f"Missing {path.relative_to(ROOT)}"


def test_plugin_implements_handshake_and_db() -> None:
    main = MAIN.read_text(encoding="utf-8")
    assert "AP_Hof_Store::ensureSchema" in main
    assert "AP_Hof_Api::register" in main

    store = STORE.read_text(encoding="utf-8")
    for needle in (
        "hof_entries",
        "hof_challenges",
        "token_hash",
        "function createChallenge",
        "function addVerified",
        "CREATE TABLE",
    ):
        assert needle in store, f"store missing {needle!r}"
    # Direct join must not insert a member anymore.
    assert "entries.json" in store  # legacy import only

    api = API.read_text(encoding="utf-8")
    for needle in (
        "challenge",
        "verify",
        "proof_url",
        "assertSafeProofUrl",
        "/api/hall-of-fame",
        "hof/v1",
    ):
        assert needle in api, f"api missing {needle!r}"


def test_phpunit_plugin_handshake() -> None:
    phpunit = ROOT / "vendor" / "bin" / "phpunit"
    if not phpunit.is_file():
        return
    proc = subprocess.run(
        [
            _php_bin(),
            str(phpunit),
            "--configuration",
            str(ROOT / "phpunit.xml.dist"),
            str(PHPUNIT),
        ],
        cwd=str(ROOT),
        capture_output=True,
        text=True,
        timeout=60,
    )
    assert proc.returncode == 0, f"stdout={proc.stdout}\nstderr={proc.stderr}"
