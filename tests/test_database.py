"""
Smoke tests for AP_DB database abstraction (PDO, prepared statements).

Runnable via:
  pytest tests/test_database.py -v
"""

from __future__ import annotations

import shutil
import subprocess
import textwrap
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CLASS_FILE = ROOT / "ap-includes" / "class-ap-db.php"
EXCEPTION_FILE = ROOT / "ap-includes" / "class-ap-db-exception.php"
BOOTSTRAP = ROOT / "ap-includes" / "bootstrap.php"


def _php_bin() -> str:
    return shutil.which("php") or "php"


def test_class_file_exists() -> None:
    assert CLASS_FILE.is_file(), "Missing ap-includes/class-ap-db.php"
    assert EXCEPTION_FILE.is_file(), "Missing ap-includes/class-ap-db-exception.php"


def test_class_declares_public_api() -> None:
    src = CLASS_FILE.read_text(encoding="utf-8")
    for needle in (
        "class AP_DB",
        "function ap_db",
        "function fromConfig",
        "function fromPdo",
        "function buildDsn",
        "function createPdo",
        "function query",
        "function getVar",
        "function getRow",
        "function getCol",
        "function getResults",
        "function insert",
        "function update",
        "function delete",
        "function setPrefix",
        "function tables",
        "function normalizePrefix",
        "function resolveConfiguredPrefix",
        "ATTR_EMULATE_PREPARES",
        "class-ap-db-exception.php",
    ):
        assert needle in src, f"Expected {needle} in class-ap-db.php"

    exc = EXCEPTION_FILE.read_text(encoding="utf-8")
    assert "class AP_DB_Exception" in exc

    # Prepared statements only — no legacy mysql_* / mysqli API.
    assert "mysqli_" not in src
    assert "mysql_query" not in src
    assert "PDO" in src


def test_bootstrap_requires_db_class() -> None:
    src = BOOTSTRAP.read_text(encoding="utf-8")
    assert "class-ap-db.php" in src


def test_sqlite_roundtrip_via_php() -> None:
    """Isolated process: insert/select through AP_DB on in-memory SQLite."""
    script = textwrap.dedent(
        f"""
        declare(strict_types=1);
        require {repr(str(CLASS_FILE))};
        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');
        $db->query('CREATE TABLE ap_options (
            option_id INTEGER PRIMARY KEY AUTOINCREMENT,
            option_name TEXT NOT NULL,
            option_value TEXT NOT NULL
        )');
        $n = $db->insert('options', [
            'option_name' => 'siteurl',
            'option_value' => 'https://example.com',
        ]);
        if ($n !== 1) {{
            fwrite(STDERR, "insert failed\\n");
            exit(2);
        }}
        $v = $db->getVar(
            'SELECT option_value FROM ap_options WHERE option_name = ?',
            ['siteurl']
        );
        if ($v !== 'https://example.com') {{
            fwrite(STDERR, "getVar failed: " . var_export($v, true) . "\\n");
            exit(3);
        }}
        if ($db->table('options') !== 'ap_options') {{
            fwrite(STDERR, "prefix failed\\n");
            exit(4);
        }}
        if ($db->options !== 'ap_options') {{
            fwrite(STDERR, "options property failed\\n");
            exit(5);
        }}
        echo "ok\\n";
        exit(0);
        """
    )

    result = subprocess.run(
        [
            _php_bin(),
            "-d",
            "display_errors=1",
            "-d",
            "error_reporting=E_ALL",
            "-r",
            script,
        ],
        cwd=str(ROOT),
        capture_output=True,
        text=True,
        check=False,
    )
    combined = (result.stdout or "") + (result.stderr or "")
    assert result.returncode == 0, f"PHP AP_DB roundtrip failed:\n{combined}"
    assert "ok" in (result.stdout or "")


def test_custom_table_prefix_roundtrip_via_php() -> None:
    """Custom prefix must apply to table() and insert/select paths."""
    script = textwrap.dedent(
        f"""
        declare(strict_types=1);
        require {repr(str(CLASS_FILE))};
        $db = AP_DB::fromPdo(new PDO('sqlite::memory:'), 'sqlite', 'myblog_');
        if ($db->getPrefix() !== 'myblog_' || $db->options !== 'myblog_options') {{
            fwrite(STDERR, "prefix props failed\\n");
            exit(2);
        }}
        $db->query('CREATE TABLE myblog_options (
            option_id INTEGER PRIMARY KEY AUTOINCREMENT,
            option_name TEXT NOT NULL,
            option_value TEXT NOT NULL
        )');
        $db->insert('options', [
            'option_name' => 'siteurl',
            'option_value' => 'https://example.test',
        ]);
        $v = $db->getVar(
            'SELECT option_value FROM myblog_options WHERE option_name = ?',
            ['siteurl']
        );
        if ($v !== 'https://example.test') {{
            fwrite(STDERR, "value=" . var_export($v, true) . "\\n");
            exit(3);
        }}
        $db->setPrefix('other_');
        if ($db->table('posts') !== 'other_posts' || $db->posts !== 'other_posts') {{
            fwrite(STDERR, "setPrefix failed\\n");
            exit(4);
        }}
        echo "ok\\n";
        exit(0);
        """
    )

    result = subprocess.run(
        [
            _php_bin(),
            "-d",
            "display_errors=1",
            "-d",
            "error_reporting=E_ALL",
            "-r",
            script,
        ],
        cwd=str(ROOT),
        capture_output=True,
        text=True,
        check=False,
    )
    combined = (result.stdout or "") + (result.stderr or "")
    assert result.returncode == 0, f"custom prefix failed:\n{combined}"
    assert "ok" in (result.stdout or "")
