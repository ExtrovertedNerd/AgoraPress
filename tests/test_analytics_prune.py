"""
Smoke tests for analytics retention prune + cron (AP_Analytics).

Runnable via:
  pytest tests/test_analytics_prune.py -v
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
PHPUNIT = ROOT / "tests" / "Database" / "AnalyticsPruneTest.php"


def _php_bin() -> str:
    return shutil.which("php") or "php"


def test_prune_files_exist() -> None:
    for path in (ANALYTICS, BOOTSTRAP, FUNCTIONS, PHPUNIT):
        assert path.is_file(), f"Missing {path.relative_to(ROOT)}"


def test_class_exposes_prune_api() -> None:
    src = ANALYTICS.read_text(encoding="utf-8")
    for needle in (
        "function prune",
        "function registerCron",
        "function ensurePruneScheduled",
        "function pruneCutoff",
        "CRON_HOOK",
        "CRON_RECURRENCE",
        "ap_analytics_prune",
        "ap_analytics_pruned",
        "analytics_hits",
        "analytics_daily",
        "hit_time",
        "retention",
    ):
        assert needle in src, f"AP_Analytics missing {needle!r}"

    assert "CRON_HOOK = 'ap_analytics_prune'" in src or 'CRON_HOOK = "ap_analytics_prune"' in src
    assert "CRON_RECURRENCE = 'daily'" in src or 'CRON_RECURRENCE = "daily"' in src


def test_bootstrap_registers_cron_before_spawn() -> None:
    bootstrap = BOOTSTRAP.read_text(encoding="utf-8")
    assert "AP_Analytics::registerCron" in bootstrap
    assert "AP_Cron::spawn" in bootstrap
    # registerCron must appear before spawn so a due prune can fire same request.
    assert bootstrap.index("AP_Analytics::registerCron") < bootstrap.index("AP_Cron::spawn")


def test_functions_wrappers() -> None:
    functions = FUNCTIONS.read_text(encoding="utf-8")
    assert "function ap_analytics_prune" in functions
    assert "function ap_analytics_ensure_prune_scheduled" in functions


def test_schema_doc_mentions_prune_cron() -> None:
    text = SCHEMA_DOC.read_text(encoding="utf-8").lower()
    assert "ap_analytics_prune" in text
    assert "retention" in text
    assert "prune" in text


def test_phpunit_analytics_prune() -> None:
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
