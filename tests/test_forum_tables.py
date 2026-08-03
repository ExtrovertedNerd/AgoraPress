"""
Smoke tests for dedicated forum tables migration (schema v5).

Runnable via:
  pytest tests/test_forum_tables.py -v
"""

from __future__ import annotations

import shutil
import subprocess
import textwrap
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MIGRATIONS = ROOT / "ap-includes" / "schema" / "migrations"
VERSION = ROOT / "ap-includes" / "version.php"
DB_CLASS = ROOT / "ap-includes" / "class-ap-db.php"
MIGRATOR = ROOT / "ap-includes" / "class-ap-migrator.php"
FORUM_CLASS = ROOT / "ap-includes" / "class-ap-forum.php"
LOAD_CONFIG = ROOT / "ap-includes" / "load-config.php"


def _php_bin() -> str:
    return shutil.which("php") or "php"


def test_migration_and_forum_class_exist() -> None:
    assert (MIGRATIONS / "0005_forum_tables.php").is_file()
    assert FORUM_CLASS.is_file()


def test_db_version_at_least_five() -> None:
    src = VERSION.read_text(encoding="utf-8")
    # Forum base tables landed at v5; later migrations (e.g. attachments) may bump further.
    assert "AP_DB_VERSION" in src
    import re

    m = re.search(r"define\('AP_DB_VERSION',\s*'(\d+)'\)", src)
    assert m is not None
    assert int(m.group(1)) >= 5


def test_migration_0005_surface() -> None:
    mig = MIGRATIONS / "0005_forum_tables.php"
    src = mig.read_text(encoding="utf-8")
    for needle in (
        "AP_Migration_0005_Forum_Tables",
        "forums",
        "topics",
        "forum_posts",
        "groups",
        "group_members",
        "messages",
        "ranks",
        "reports",
        "online",
        "forum_type",
        "topic_status",
        "topic_type",
        "post_approved",
        "member_role",
        "session_key",
        "ENGINE=InnoDB",
        "BIGSERIAL",
        "AUTOINCREMENT",
    ):
        assert needle in src, f"Expected {needle} in 0005 migration"


def test_ap_forum_documents_base_tables() -> None:
    src = FORUM_CLASS.read_text(encoding="utf-8")
    assert "class AP_Forum" in src
    assert "function baseTables" in src
    assert "function tables" in src


def test_forum_base_tables_in_load_config() -> None:
    src = LOAD_CONFIG.read_text(encoding="utf-8")
    assert "function ap_forum_base_tables" in src
    for name in (
        "forums",
        "topics",
        "forum_posts",
        "forum_attachments",
        "groups",
        "group_members",
        "messages",
        "ranks",
        "reports",
        "online",
    ):
        assert f"'{name}'" in src or f'"{name}"' in src


def test_phpunit_forum_tables_suite_runs() -> None:
    result = subprocess.run(
        [
            _php_bin(),
            "vendor/bin/phpunit",
            "-c",
            "phpunit.xml.dist",
            "tests/Database/ForumTablesMigrationTest.php",
        ],
        cwd=str(ROOT),
        capture_output=True,
        text=True,
        check=False,
    )
    combined = (result.stdout or "") + (result.stderr or "")
    assert result.returncode == 0, f"PHPUnit forum tables failed:\n{combined}"


def test_forum_migration_applies_via_php() -> None:
    script = textwrap.dedent(
        f"""
        declare(strict_types=1);
        require {repr(str(VERSION))};
        require {repr(str(LOAD_CONFIG))};
        require {repr(str(DB_CLASS))};
        require {repr(str(MIGRATOR))};
        require {repr(str(FORUM_CLASS))};
        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');
        $m = new AP_Migrator($db, AP_Migrator::defaultMigrationsPath());
        $applied = $m->migrate();
        if (count($applied) < 5 || (int) $applied[4]['version'] !== 5) {{
            fwrite(STDERR, "forum migration not applied\\n");
            exit(2);
        }}
        foreach (AP_Forum::baseTables() as $base) {{
            $t = $db->table($base);
            $name = $db->getVar(
                "SELECT name FROM sqlite_master WHERE type='table' AND name = ?",
                [$t]
            );
            if ($name !== $t) {{
                fwrite(STDERR, "missing $t\\n");
                exit(3);
            }}
        }}
        echo "forum_ok\\n";
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
    assert result.returncode == 0, f"forum migration failed:\n{combined}"
    assert "forum_ok" in (result.stdout or "")


if __name__ == "__main__":
    raise SystemExit(__import__("pytest").main([__file__, "-v"]))
