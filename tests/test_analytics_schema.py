"""
Smoke tests for local analytics schema (migration 0010).

Runnable via:
  pytest tests/test_analytics_schema.py -v
"""

from __future__ import annotations

import re
import shutil
import subprocess
import textwrap
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MIGRATIONS = ROOT / "ap-includes" / "schema" / "migrations"
VERSION = ROOT / "ap-includes" / "version.php"
DB_CLASS = ROOT / "ap-includes" / "class-ap-db.php"
MIGRATOR = ROOT / "ap-includes" / "class-ap-migrator.php"
LOAD_CONFIG = ROOT / "ap-includes" / "load-config.php"
SCHEMA_DOC = ROOT / "docs" / "schema.md"


def _php_bin() -> str:
    return shutil.which("php") or "php"


def test_migration_file_exists() -> None:
    assert (MIGRATIONS / "0010_analytics_tables.php").is_file()


def test_db_version_is_ten() -> None:
    src = VERSION.read_text(encoding="utf-8")
    assert "define('AP_DB_VERSION', '10')" in src
    assert "Version 10" in src
    assert "analytics_hits" in src
    assert "analytics_daily" in src


def test_migration_0010_surface() -> None:
    mig = MIGRATIONS / "0010_analytics_tables.php"
    src = mig.read_text(encoding="utf-8")
    for needle in (
        "AP_Migration_0010_Analytics_Tables",
        "analytics_hits",
        "analytics_daily",
        "hit_id",
        "hit_time",
        "path",
        "object_id",
        "status_code",
        "referrer",
        "ua_class",
        "is_admin",
        "hits",
        "PRIMARY KEY",
        "ENGINE=InnoDB",
        "BIGSERIAL",
        "AUTOINCREMENT",
        "pgsqlStatements",
        "sqliteStatements",
        "mysqlStatements",
    ):
        assert needle in src, f"Expected {needle} in 0010 migration"


def test_base_tables_and_db_properties() -> None:
    load = LOAD_CONFIG.read_text(encoding="utf-8")
    assert "analytics_hits" in load
    assert "analytics_daily" in load
    db = DB_CLASS.read_text(encoding="utf-8")
    assert "public string $analytics_hits" in db
    assert "public string $analytics_daily" in db


def test_schema_doc_documents_analytics() -> None:
    text = SCHEMA_DOC.read_text(encoding="utf-8").lower()
    assert "analytics_hits" in text
    assert "analytics_daily" in text
    assert "0010" in text


def test_migration_applies_in_sqlite() -> None:
    php = textwrap.dedent(
        f"""
        declare(strict_types=1);
        require_once {str(VERSION)!r};
        require_once {str(DB_CLASS)!r};
        require_once {str(MIGRATOR)!r};
        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');
        $m = new AP_Migrator($db, AP_Migrator::defaultMigrationsPath());
        if ((int) AP_DB_VERSION < 10) {{
            fwrite(STDERR, "AP_DB_VERSION expected >= 10\\n");
            exit(1);
        }}
        $applied = $m->migrate();
        if ($m->getCurrentVersion() < 10) {{
            fwrite(STDERR, "version too low\\n");
            exit(2);
        }}
        $versions = array_column($applied, 'version');
        if (!in_array(10, $versions, true) && $m->getCurrentVersion() < 10) {{
            fwrite(STDERR, "migration 10 missing\\n");
            exit(3);
        }}
        foreach (['ap_analytics_hits', 'ap_analytics_daily'] as $t) {{
            $name = $db->getVar(
                "SELECT name FROM sqlite_master WHERE type='table' AND name=?",
                [$t]
            );
            if ($name !== $t) {{
                fwrite(STDERR, "missing table $t\\n");
                exit(4);
            }}
        }}
        if ($db->analytics_hits !== 'ap_analytics_hits'
            || $db->analytics_daily !== 'ap_analytics_daily'
        ) {{
            fwrite(STDERR, "db properties mismatch\\n");
            exit(5);
        }}
        // CRUD smoke: insert hit + daily rollup row.
        $n = $db->insert('analytics_hits', [
            'hit_time' => '2026-08-05 12:00:00',
            'path' => '/hello',
            'object_id' => 1,
            'status_code' => 200,
            'referrer' => 'https://example.com/',
            'ua_class' => 'browser',
            'is_admin' => 0,
        ]);
        if ($n !== 1) {{
            fwrite(STDERR, "hit insert failed\\n");
            exit(6);
        }}
        $path = $db->getVar(
            'SELECT path FROM ' . $db->quoteIdentifier($db->analytics_hits)
            . ' WHERE path = ?',
            ['/hello']
        );
        if ($path !== '/hello') {{
            fwrite(STDERR, "hit path mismatch\\n");
            exit(7);
        }}
        $n = $db->insert('analytics_daily', [
            'day' => '2026-08-05',
            'path' => '/hello',
            'object_id' => 1,
            'hits' => 3,
        ]);
        if ($n !== 1) {{
            fwrite(STDERR, "daily insert failed\\n");
            exit(8);
        }}
        $hits = $db->getVar(
            'SELECT hits FROM ' . $db->quoteIdentifier($db->analytics_daily)
            . ' WHERE day = ? AND path = ? AND object_id = ?',
            ['2026-08-05', '/hello', 1]
        );
        if ((int) $hits !== 3) {{
            fwrite(STDERR, "daily hits mismatch\\n");
            exit(9);
        }}
        if ($m->migrate() !== []) {{
            fwrite(STDERR, "not idempotent\\n");
            exit(10);
        }}
        echo "ok";
        """
    )
    result = subprocess.run(
        [_php_bin(), "-r", php],
        capture_output=True,
        text=True,
        cwd=str(ROOT),
        check=False,
    )
    assert result.returncode == 0, result.stderr + result.stdout
    assert "ok" in result.stdout


def test_phpunit_suite_file_exists() -> None:
    assert (ROOT / "tests" / "Database" / "AnalyticsTablesMigrationTest.php").is_file()
