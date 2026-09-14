"""
Smoke tests for topic_subscriptions migration (schema v13).

Runnable via:
  pytest tests/test_topic_subscriptions.py -v
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
FORUM_CLASS = ROOT / "ap-includes" / "class-ap-forum.php"


def _php_bin() -> str:
    return shutil.which("php") or "php"


def test_migration_file_exists() -> None:
    assert (MIGRATIONS / "0013_topic_subscriptions.php").is_file()


def test_db_version_includes_topic_subscriptions() -> None:
    src = VERSION.read_text(encoding="utf-8")
    m = re.search(r"define\('AP_DB_VERSION',\s*'(\d+)'\)", src)
    assert m is not None
    assert int(m.group(1)) >= 13
    assert "Version 13" in src
    assert "topic_subscriptions" in src


def test_migration_0013_surface() -> None:
    mig = MIGRATIONS / "0013_topic_subscriptions.php"
    src = mig.read_text(encoding="utf-8")
    for needle in (
        "AP_Migration_0013_Topic_Subscriptions",
        "topic_subscriptions",
        "user_id",
        "topic_id",
        "created_at",
        "PRIMARY KEY",
        "IF NOT EXISTS",
        "ENGINE=InnoDB",
        "pgsqlStatements",
        "sqliteStatements",
        "mysqlStatements",
    ):
        assert needle in src, f"Expected {needle!r} in 0013 migration"
    assert "CREATE TABLE IF NOT EXISTS" in src


def test_base_tables_and_db_properties() -> None:
    load = LOAD_CONFIG.read_text(encoding="utf-8")
    assert "topic_subscriptions" in load
    assert "topic_track" in load
    db = DB_CLASS.read_text(encoding="utf-8")
    assert "public string $topic_subscriptions" in db
    forum = FORUM_CLASS.read_text(encoding="utf-8")
    assert "topic_subscriptions" in forum


def test_migrate_from_12_unique_and_add_remove() -> None:
    php = textwrap.dedent(
        f"""
        declare(strict_types=1);
        require_once {str(VERSION)!r};
        require_once {str(LOAD_CONFIG)!r};
        require_once {str(DB_CLASS)!r};
        require_once {str(MIGRATOR)!r};
        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');
        $m = new AP_Migrator($db, AP_Migrator::defaultMigrationsPath());
        if ((int) AP_DB_VERSION < 13) {{
            fwrite(STDERR, "AP_DB_VERSION expected >= 13\\n");
            exit(1);
        }}
        $m->migrate(12);
        if ($m->getCurrentVersion() !== 12) {{
            fwrite(STDERR, "expected schema 12 before 0013\\n");
            exit(2);
        }}
        $missing = $db->getVar(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?",
            ['ap_topic_subscriptions']
        );
        if ($missing !== null) {{
            fwrite(STDERR, "subscriptions table existed at schema 12\\n");
            exit(3);
        }}
        $track = $db->getVar(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?",
            ['ap_topic_track']
        );
        if ($track !== 'ap_topic_track') {{
            fwrite(STDERR, "topic_track missing at schema 12\\n");
            exit(4);
        }}
        $applied = $m->migrate(13);
        if (count($applied) !== 1 || (int) $applied[0]['version'] !== 13) {{
            fwrite(STDERR, "v13 not applied cleanly\\n");
            exit(5);
        }}
        $table = $db->quoteIdentifier($db->table('topic_subscriptions'));
        $ok = $db->insert('topic_subscriptions', [
            'user_id' => 4,
            'topic_id' => 8,
            'created_at' => '2026-09-13 10:00:00',
        ]);
        if ($ok !== 1) {{
            fwrite(STDERR, "insert pair failed\\n");
            exit(6);
        }}
        $dup = $db->insert('topic_subscriptions', [
            'user_id' => 4,
            'topic_id' => 8,
            'created_at' => '2026-09-13 10:01:00',
        ]);
        if ($dup !== false) {{
            fwrite(STDERR, "unique (user_id, topic_id) not enforced\\n");
            exit(7);
        }}
        $del = $db->delete('topic_subscriptions', [
            'user_id' => 4,
            'topic_id' => 8,
        ]);
        if ($del !== 1) {{
            fwrite(STDERR, "delete pair failed\\n");
            exit(8);
        }}
        $gone = $db->getVar(
            "SELECT user_id FROM {{$table}} WHERE user_id = ? AND topic_id = ?",
            [4, 8]
        );
        if ($gone !== null) {{
            fwrite(STDERR, "row remained after delete\\n");
            exit(9);
        }}
        $migration = require {str(MIGRATIONS / "0013_topic_subscriptions.php")!r};
        $migration->up($db);
        if ($m->migrate() !== []) {{
            fwrite(STDERR, "migrator not idempotent after 13\\n");
            exit(10);
        }}
        echo "topic_subscriptions_ok\\n";
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
            php,
        ],
        cwd=str(ROOT),
        capture_output=True,
        text=True,
        check=False,
    )
    combined = (result.stdout or "") + (result.stderr or "")
    assert result.returncode == 0, f"topic_subscriptions migration failed:\n{combined}"
    assert "topic_subscriptions_ok" in (result.stdout or "")


if __name__ == "__main__":
    raise SystemExit(__import__("pytest").main([__file__, "-v"]))
