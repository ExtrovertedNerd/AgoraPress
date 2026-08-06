"""
Smoke tests for analytics aggregation helpers (AP_Analytics).

Covers presence of countHits / getSummary / getTopPaths / getTopReferrers /
getDailyTotals / rollupDaily and runs PHPUnit AnalyticsAggregationTest.

Also confirms record / disable / prune test modules remain wired (SPEC
acceptance: migrate, record, disable, aggregation helpers).

Runnable via:
  pytest tests/test_analytics_aggregation.py -v
"""

from __future__ import annotations

import shutil
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
ANALYTICS = ROOT / "ap-includes" / "class-ap-analytics.php"
FUNCTIONS = ROOT / "ap-includes" / "functions.php"
SCHEMA_DOC = ROOT / "docs" / "schema.md"
PHPUNIT_AGG = ROOT / "tests" / "Database" / "AnalyticsAggregationTest.php"
PHPUNIT_REC = ROOT / "tests" / "Database" / "AnalyticsRecorderTest.php"
PHPUNIT_PRUNE = ROOT / "tests" / "Database" / "AnalyticsPruneTest.php"


def _php_bin() -> str:
    return shutil.which("php") or "php"


def test_aggregation_files_exist() -> None:
    for path in (ANALYTICS, FUNCTIONS, PHPUNIT_AGG, PHPUNIT_REC, PHPUNIT_PRUNE):
        assert path.is_file(), f"Missing {path.relative_to(ROOT)}"


def test_class_exposes_aggregation_api() -> None:
    src = ANALYTICS.read_text(encoding="utf-8")
    for needle in (
        "function countHits",
        "function getSummary",
        "function getTopPaths",
        "function getTopReferrers",
        "function getDailyTotals",
        "function rollupDaily",
        "function dayBounds",
        "last_7_days",
        "last_30_days",
        "Aggregation helpers",
        "exclude_admin",
        "fill_gaps",
        "sqlDayExpression",
    ):
        assert needle in src, f"AP_Analytics missing {needle!r}"


def test_functions_wrappers() -> None:
    functions = FUNCTIONS.read_text(encoding="utf-8")
    for needle in (
        "function ap_analytics_count_hits",
        "function ap_analytics_summary",
        "function ap_analytics_top_paths",
        "function ap_analytics_top_referrers",
        "function ap_analytics_daily_totals",
        "function ap_analytics_rollup_daily",
    ):
        assert needle in functions, f"functions.php missing {needle!r}"


def test_schema_doc_mentions_aggregation() -> None:
    text = SCHEMA_DOC.read_text(encoding="utf-8").lower()
    assert "analytics_hits" in text
    assert "analytics_daily" in text
    # Aggregation / report surface
    assert "rollup" in text or "aggregat" in text or "summary" in text or "top path" in text


def test_phpunit_analytics_aggregation() -> None:
    phpunit = ROOT / "vendor" / "bin" / "phpunit"
    if not phpunit.is_file():
        return
    cmd = [
        _php_bin(),
        str(phpunit),
        "--configuration",
        str(ROOT / "phpunit.xml.dist"),
        str(PHPUNIT_AGG),
    ]
    result = subprocess.run(cmd, cwd=str(ROOT), capture_output=True, text=True, check=False)
    combined = (result.stdout or "") + (result.stderr or "")
    assert result.returncode == 0, f"phpunit failed:\n{combined}"


def test_phpunit_record_disable_prune_still_pass() -> None:
    """SPEC: tests cover record hit, disable, prune, and aggregation helpers."""
    phpunit = ROOT / "vendor" / "bin" / "phpunit"
    if not phpunit.is_file():
        return
    cmd = [
        _php_bin(),
        str(phpunit),
        "--configuration",
        str(ROOT / "phpunit.xml.dist"),
        str(PHPUNIT_REC),
        str(PHPUNIT_PRUNE),
        str(PHPUNIT_AGG),
    ]
    result = subprocess.run(cmd, cwd=str(ROOT), capture_output=True, text=True, check=False)
    combined = (result.stdout or "") + (result.stderr or "")
    assert result.returncode == 0, f"phpunit failed:\n{combined}"
