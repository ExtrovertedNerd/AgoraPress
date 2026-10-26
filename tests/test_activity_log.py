"""
Smoke tests for the admin credential activity log (migration 0014).

An administrator who changes another user's password or email is recorded
with actor id, target id, field name, UTC time, and IP. The password is not
stored.

Runnable via:
  pytest tests/test_activity_log.py -v
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
RATE_LIMIT = ROOT / "ap-includes" / "class-ap-rate-limit.php"
LOG_CLASS = ROOT / "ap-includes" / "class-ap-activity-log.php"
USER_EDIT = ROOT / "ap-admin" / "includes" / "class-ap-admin-user-edit.php"
BOOTSTRAP = ROOT / "ap-includes" / "bootstrap.php"
SCHEMA_DOC = ROOT / "docs" / "schema.md"
STRUCTURE = ROOT / "tests" / "Structure" / "assert-structure.php"
PHPUNIT = ROOT / "tests" / "Database" / "ActivityLogMigrationTest.php"


def _php_bin() -> str:
    return shutil.which("php") or "php"


def test_migration_file_exists() -> None:
    assert (MIGRATIONS / "0014_activity_log.php").is_file()


def test_db_version_includes_activity_log() -> None:
    src = VERSION.read_text(encoding="utf-8")
    match = re.search(r"define\('AP_DB_VERSION',\s*'(\d+)'\)", src)
    assert match is not None
    assert int(match.group(1)) == 14
    assert "Version 14" in src
    assert "activity_log" in src
    assert "The password is not stored" in src


def test_base_tables_class_and_docs() -> None:
    load = LOAD_CONFIG.read_text(encoding="utf-8")
    core = load.split("function ap_forum_base_tables", 1)[0]
    assert "'activity_log'" in core
    forum = load.split("function ap_forum_base_tables", 1)[1]
    assert "'activity_log'" not in forum.split("function ap_all_base_tables", 1)[0]

    db = DB_CLASS.read_text(encoding="utf-8")
    assert "public string $activity_log" in db

    log = LOG_CLASS.read_text(encoding="utf-8")
    for needle in (
        "class AP_Activity_Log",
        "FIELD_PASSWORD = 'password'",
        "FIELD_EMAIL = 'user_email'",
        "function record",
        "function forTarget",
    ):
        assert needle in log, f"Expected {needle!r} in activity log class"
    assert "user_pass" not in log

    schema = SCHEMA_DOC.read_text(encoding="utf-8")
    assert "0014_activity_log.php" in schema
    assert "### activity_log" in schema
    assert "No password, hash, or address column." in schema

    boot = BOOTSTRAP.read_text(encoding="utf-8")
    assert "class-ap-activity-log.php" in boot
    assert STRUCTURE.read_text(encoding="utf-8").count("class-ap-activity-log.php") >= 1

    edit = USER_EDIT.read_text(encoding="utf-8")
    assert "AP_Activity_Log::record" in edit
    assert "AP_Activity_Log::FIELD_PASSWORD" in edit
    assert "AP_Activity_Log::FIELD_EMAIL" in edit
    assert "The password and the new address are not stored." in edit


def test_record_stores_field_name_never_the_secret() -> None:
    php = textwrap.dedent(
        f"""
        declare(strict_types=1);
        require_once {str(VERSION)!r};
        require_once {str(LOAD_CONFIG)!r};
        require_once {str(DB_CLASS)!r};
        require_once {str(MIGRATOR)!r};
        require_once {str(RATE_LIMIT)!r};
        require_once {str(LOG_CLASS)!r};
        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');
        $m = new AP_Migrator($db, AP_Migrator::defaultMigrationsPath());
        if ((int) AP_DB_VERSION !== 14) {{
            fwrite(STDERR, "AP_DB_VERSION expected 14\\n");
            exit(1);
        }}
        $m->migrate(13);
        if ($m->getCurrentVersion() !== 13) {{
            fwrite(STDERR, "expected schema 13 before activity_log\\n");
            exit(2);
        }}
        $early = $db->getVar(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?",
            ['ap_activity_log']
        );
        if ($early !== null) {{
            fwrite(STDERR, "activity_log existed at schema 13\\n");
            exit(3);
        }}
        $applied = $m->migrate();
        if (count($applied) !== 1 || (int) $applied[0]['version'] !== 14) {{
            fwrite(STDERR, "v14 not applied cleanly\\n");
            exit(4);
        }}
        if ($db->activity_log !== 'ap_activity_log') {{
            fwrite(STDERR, "activity_log property mismatch\\n");
            exit(5);
        }}
        $ddl = strtolower((string) $db->getVar(
            "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?",
            ['ap_activity_log']
        ));
        foreach (['actor_id', 'target_id', 'field_name', 'created_at', 'ip'] as $col) {{
            if (!str_contains($ddl, $col)) {{
                fwrite(STDERR, "ddl missing $col\\n");
                exit(6);
            }}
        }}
        if (str_contains($ddl, 'password') || str_contains($ddl, 'user_pass') || str_contains($ddl, 'user_email')) {{
            fwrite(STDERR, "ddl stores a secret or address column\\n");
            exit(7);
        }}
        $secret = 'reset-password-1';
        $address = 'member-new@example.test';
        $_SERVER['REMOTE_ADDR'] = '203.0.113.50';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.20';
        if (AP_Activity_Log::record(0, 2, AP_Activity_Log::FIELD_PASSWORD, $db) !== false) {{
            fwrite(STDERR, "missing actor was stored\\n");
            exit(8);
        }}
        if (AP_Activity_Log::record(2, 2, AP_Activity_Log::FIELD_PASSWORD, $db) !== false) {{
            fwrite(STDERR, "self edit was stored\\n");
            exit(9);
        }}
        if (AP_Activity_Log::record(1, 2, $secret, $db) !== false) {{
            fwrite(STDERR, "secret field name was stored\\n");
            exit(10);
        }}
        if (AP_Activity_Log::record(1, 2, $address, $db) !== false) {{
            fwrite(STDERR, "address field name was stored\\n");
            exit(11);
        }}
        if (AP_Activity_Log::record(1, 2, AP_Activity_Log::FIELD_PASSWORD, $db, $secret) !== true) {{
            fwrite(STDERR, "password field row failed\\n");
            exit(12);
        }}
        if (AP_Activity_Log::record(1, 2, AP_Activity_Log::FIELD_EMAIL, $db) !== true) {{
            fwrite(STDERR, "email field row failed\\n");
            exit(13);
        }}
        $rows = AP_Activity_Log::forTarget(2, $db);
        if (count($rows) !== 2) {{
            fwrite(STDERR, "expected 2 rows\\n");
            exit(14);
        }}
        if ((int) $rows[0]->actor_id !== 1 || (int) $rows[0]->target_id !== 2) {{
            fwrite(STDERR, "actor or target mismatch\\n");
            exit(15);
        }}
        if ((string) $rows[0]->field_name !== AP_Activity_Log::FIELD_PASSWORD || (string) $rows[0]->ip !== '') {{
            fwrite(STDERR, "password row stored an ip or the wrong field\\n");
            exit(16);
        }}
        if ((string) $rows[1]->field_name !== AP_Activity_Log::FIELD_EMAIL || (string) $rows[1]->ip !== '203.0.113.50') {{
            fwrite(STDERR, "email row ip mismatch\\n");
            exit(17);
        }}
        if (!preg_match('/^\\d{{4}}-\\d{{2}}-\\d{{2}} \\d{{2}}:\\d{{2}}:\\d{{2}}$/', (string) $rows[0]->created_at)) {{
            fwrite(STDERR, "created_at is not UTC datetime\\n");
            exit(18);
        }}
        $encoded = (string) json_encode($rows);
        if (str_contains($encoded, $secret) || str_contains($encoded, $address) || str_contains($encoded, '198.51.100.20')) {{
            fwrite(STDERR, "log leaked a secret, address, or forwarded ip\\n");
            exit(19);
        }}
        $migration = require {str(MIGRATIONS / "0014_activity_log.php")!r};
        $migration->up($db);
        if (count(AP_Activity_Log::forTarget(2, $db)) !== 2) {{
            fwrite(STDERR, "re-running 0014 changed stored rows\\n");
            exit(20);
        }}
        if ($m->migrate() !== []) {{
            fwrite(STDERR, "migrator not idempotent after 14\\n");
            exit(21);
        }}
        echo "activity_log_ok\\n";
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
    assert result.returncode == 0, f"activity_log smoke failed:\n{combined}"
    assert "activity_log_ok" in (result.stdout or "")


def test_phpunit_activity_log_migration_suite_runs() -> None:
    phpunit = ROOT / "vendor" / "bin" / "phpunit"
    if not phpunit.is_file():
        return
    result = subprocess.run(
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
        check=False,
    )
    combined = (result.stdout or "") + (result.stderr or "")
    assert result.returncode == 0, f"phpunit activity log failed:\n{combined}"
    assert PHPUNIT.is_file()
