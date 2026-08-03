"""
Smoke tests for ap-config-sample.php (Phase 0 sample configuration).

Runnable via:
  pytest tests/test_config_sample.py -v
"""

from __future__ import annotations

import re
import shutil
import subprocess
import sys
from pathlib import Path

import pytest

ROOT = Path(__file__).resolve().parents[1]
SAMPLE = ROOT / "ap-config-sample.php"

REQUIRED_CONSTANTS = (
    "AP_DB_DRIVER",
    "AP_DB_NAME",
    "AP_DB_USER",
    "AP_DB_PASSWORD",
    "AP_DB_HOST",
    "AP_DB_CHARSET",
    "AP_DB_COLLATE",
    "AP_AUTH_KEY",
    "AP_SECURE_AUTH_KEY",
    "AP_LOGGED_IN_KEY",
    "AP_NONCE_KEY",
    "AP_AUTH_SALT",
    "AP_SECURE_AUTH_SALT",
    "AP_LOGGED_IN_SALT",
    "AP_NONCE_SALT",
    "AP_DEBUG",
    "AP_DEBUG_DISPLAY",
    "AP_DEBUG_LOG",
    "AP_TELEMETRY",
)


def _php_bin() -> str:
    return shutil.which("php") or "php"


@pytest.fixture(scope="module")
def sample_text() -> str:
    assert SAMPLE.is_file(), "Missing ap-config-sample.php"
    return SAMPLE.read_text(encoding="utf-8")


def test_sample_exists() -> None:
    assert SAMPLE.is_file()


def test_strict_types(sample_text: str) -> None:
    assert "declare(strict_types=1);" in sample_text


def test_table_prefix_default(sample_text: str) -> None:
    assert re.search(r"\$table_prefix\s*=\s*['\"]ap_['\"]", sample_text), (
        "ap-config-sample.php should set $table_prefix = 'ap_'"
    )


@pytest.mark.parametrize("name", REQUIRED_CONSTANTS)
def test_defines_constant(sample_text: str, name: str) -> None:
    assert re.search(
        rf"define\s*\(\s*['\"]{re.escape(name)}['\"]",
        sample_text,
    ), f"Expected define for {name}"


def test_charset_and_collate(sample_text: str) -> None:
    assert re.search(
        r"define\s*\(\s*['\"]AP_DB_CHARSET['\"]\s*,\s*['\"]utf8mb4['\"]",
        sample_text,
    )
    assert re.search(
        r"define\s*\(\s*['\"]AP_DB_COLLATE['\"]\s*,\s*['\"]utf8mb4_unicode_ci['\"]",
        sample_text,
    )


def test_default_driver_mysql(sample_text: str) -> None:
    assert re.search(
        r"define\s*\(\s*['\"]AP_DB_DRIVER['\"]\s*,\s*['\"]mysql['\"]",
        sample_text,
    )


def test_telemetry_and_debug_off(sample_text: str) -> None:
    assert re.search(
        r"define\s*\(\s*['\"]AP_TELEMETRY['\"]\s*,\s*false\s*\)",
        sample_text,
    )
    assert re.search(
        r"define\s*\(\s*['\"]AP_DEBUG['\"]\s*,\s*false\s*\)",
        sample_text,
    )


def test_abspath_guard(sample_text: str) -> None:
    assert "if (!defined('AP_ABSPATH'))" in sample_text
    assert "define('AP_ABSPATH'" in sample_text


def test_documents_multi_db_drivers(sample_text: str) -> None:
    lower = sample_text.lower()
    for driver in ("mysql", "sqlite", "pgsql"):
        assert driver in lower, f"Sample should document {driver}"


def test_sample_loads_cleanly() -> None:
    """Require sample in an isolated PHP process; assert constants and prefix."""
    php_script = r"""
declare(strict_types=1);
$sample = $argv[1];
require $sample;
$need = [
    'AP_DB_DRIVER','AP_DB_NAME','AP_DB_USER','AP_DB_PASSWORD',
    'AP_DB_HOST','AP_DB_CHARSET','AP_DB_COLLATE',
    'AP_AUTH_KEY','AP_SECURE_AUTH_KEY','AP_LOGGED_IN_KEY','AP_NONCE_KEY',
    'AP_AUTH_SALT','AP_SECURE_AUTH_SALT','AP_LOGGED_IN_SALT','AP_NONCE_SALT',
    'AP_DEBUG','AP_DEBUG_DISPLAY','AP_DEBUG_LOG','AP_TELEMETRY','AP_ABSPATH',
];
foreach ($need as $c) {
    if (!defined($c)) { fwrite(STDERR, "missing $c\n"); exit(2); }
}
if (!isset($table_prefix) || $table_prefix !== 'ap_') { exit(3); }
if (AP_DB_DRIVER !== 'mysql') { exit(4); }
if (AP_TELEMETRY !== false || AP_DEBUG !== false) { exit(5); }
echo "ok\n";
"""
    result = subprocess.run(
        [
            _php_bin(),
            "-d",
            "display_errors=1",
            "-d",
            "error_reporting=E_ALL",
            "-r",
            php_script,
            "--",
            str(SAMPLE),
        ],
        cwd=str(ROOT),
        capture_output=True,
        text=True,
        check=False,
    )
    combined = (result.stdout or "") + (result.stderr or "")
    assert result.returncode == 0, f"sample load failed:\n{combined}"
    assert "ok" in (result.stdout or "")


if __name__ == "__main__":
    sys.exit(pytest.main([__file__, "-v"]))
