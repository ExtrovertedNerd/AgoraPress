"""
Smoke tests for server-side analytics recorder (AP_Analytics).

Runnable via:
  pytest tests/test_analytics_recorder.py -v
"""

from __future__ import annotations

import shutil
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
ANALYTICS = ROOT / "ap-includes" / "class-ap-analytics.php"
BOOTSTRAP = ROOT / "ap-includes" / "bootstrap.php"
FUNCTIONS = ROOT / "ap-includes" / "functions.php"
SCHEMA_DOC = ROOT / "docs" / "schema.md"
PHPUNIT = ROOT / "tests" / "Database" / "AnalyticsRecorderTest.php"


def _php_bin() -> str:
    return shutil.which("php") or "php"


def test_recorder_files_exist() -> None:
    for path in (ANALYTICS, BOOTSTRAP, FUNCTIONS, PHPUNIT):
        assert path.is_file(), f"Missing {path.relative_to(ROOT)}"


def test_class_exposes_recorder_api() -> None:
    src = ANALYTICS.read_text(encoding="utf-8")
    for needle in (
        "function register",
        "function maybeRecordCurrentRequest",
        "function shouldRecordRequest",
        "function recordHit",
        "function classifyUserAgent",
        "function normalizePath",
        "function buildHitFromCurrentRequest",
        "UA_BROWSER",
        "UA_BOT",
        "UA_OTHER",
        "ap_analytics_should_record",
        "ap_analytics_exclude_admins",
        "ap_analytics_record_404",
        "register_shutdown_function",
        "ap-admin",
        "Do Not Track",
        "manage_options",
    ):
        assert needle in src, f"AP_Analytics missing {needle!r}"


def test_bootstrap_registers_recorder() -> None:
    bootstrap = BOOTSTRAP.read_text(encoding="utf-8")
    assert "AP_Analytics::register" in bootstrap
    assert "class-ap-analytics.php" in bootstrap


def test_functions_wrappers() -> None:
    functions = FUNCTIONS.read_text(encoding="utf-8")
    assert "function ap_analytics_should_record" in functions
    assert "function ap_analytics_maybe_record" in functions
    assert "function ap_analytics_record_hit" in functions


def test_schema_doc_mentions_recorder() -> None:
    text = SCHEMA_DOC.read_text(encoding="utf-8").lower()
    assert "shutdown" in text or "server-side" in text
    assert "ap-admin" in text
    assert "analytics_enabled" in text


def test_phpunit_analytics_recorder() -> None:
    phpunit = ROOT / "vendor" / "bin" / "phpunit"
    if not phpunit.is_file():
        return
    cmd = [
        _php_bin(),
        str(phpunit),
        "--configuration",
        str(ROOT / "phpunit.xml.dist"),
        str(PHPUNIT),
    ]
    result = subprocess.run(cmd, cwd=str(ROOT), capture_output=True, text=True, check=False)
    combined = (result.stdout or "") + (result.stderr or "")
    assert result.returncode == 0, f"phpunit failed:\n{combined}"
