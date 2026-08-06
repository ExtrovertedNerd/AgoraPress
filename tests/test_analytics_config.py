"""
Smoke tests for analytics config options (AP_Analytics).

Runnable via:
  pytest tests/test_analytics_config.py -v
"""

from __future__ import annotations

import shutil
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
ANALYTICS = ROOT / "ap-includes" / "class-ap-analytics.php"
INSTALLER = ROOT / "ap-includes" / "class-ap-installer.php"
BOOTSTRAP = ROOT / "ap-includes" / "bootstrap.php"
FUNCTIONS = ROOT / "ap-includes" / "functions.php"
SCHEMA_DOC = ROOT / "docs" / "schema.md"
STRUCTURE = ROOT / "tests" / "Structure" / "assert-structure.php"
PHPUNIT = ROOT / "tests" / "Database" / "AnalyticsConfigTest.php"


def _php_bin() -> str:
    return shutil.which("php") or "php"


def test_analytics_config_files_exist() -> None:
    for path in (ANALYTICS, INSTALLER, BOOTSTRAP, FUNCTIONS, PHPUNIT):
        assert path.is_file(), f"Missing {path.relative_to(ROOT)}"


def test_class_api_and_defaults() -> None:
    src = ANALYTICS.read_text(encoding="utf-8")
    for needle in (
        "class AP_Analytics",
        "OPTION_ENABLED",
        "OPTION_RETENTION_DAYS",
        "analytics_enabled",
        "analytics_retention_days",
        "DEFAULT_ENABLED",
        "DEFAULT_RETENTION_DAYS",
        "function isEnabled",
        "function getRetentionDays",
        "function sanitizeRetentionDays",
        "function sanitizeEnabled",
        "function updateSettings",
        "function defaultOptionMap",
        "function recordHit",
        "function shouldRecordRequest",
        "function maybeRecordCurrentRequest",
        "function register",
        "ap_analytics_enabled",
        "ap_analytics_retention_days",
        "opt-in",
        "90",
    ):
        assert needle in src, f"AP_Analytics missing {needle!r}"

    # Default collection off (privacy).
    assert "DEFAULT_ENABLED = false" in src or "DEFAULT_ENABLED = false;" in src
    assert "DEFAULT_RETENTION_DAYS = 90" in src


def test_bootstrap_and_functions_wiring() -> None:
    bootstrap = BOOTSTRAP.read_text(encoding="utf-8")
    assert "class-ap-analytics.php" in bootstrap

    functions = FUNCTIONS.read_text(encoding="utf-8")
    assert "function ap_analytics_enabled" in functions
    assert "function ap_analytics_retention_days" in functions
    assert "function ap_analytics_should_record" in functions
    assert "function ap_analytics_maybe_record" in functions
    assert "function ap_analytics_record_hit" in functions


def test_installer_seeds_options_default_off() -> None:
    installer = INSTALLER.read_text(encoding="utf-8")
    assert "analytics_enabled" in installer
    assert "analytics_retention_days" in installer
    assert "'analytics_enabled' => '0'" in installer or '"analytics_enabled" => "0"' in installer
    assert (
        "'analytics_retention_days' => '90'" in installer
        or '"analytics_retention_days" => "90"' in installer
    )


def test_schema_doc_documents_defaults() -> None:
    text = SCHEMA_DOC.read_text(encoding="utf-8").lower()
    assert "analytics_enabled" in text
    assert "analytics_retention_days" in text
    assert "90" in text
    # Default off documented.
    assert "off" in text or "opt-in" in text


def test_structure_assert_lists_analytics_class() -> None:
    src = STRUCTURE.read_text(encoding="utf-8")
    assert "class-ap-analytics.php" in src


def test_phpunit_analytics_config() -> None:
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
