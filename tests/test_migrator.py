"""
Smoke tests for versioned schema / migration system (AP_Migrator).

Runnable via:
  pytest tests/test_migrator.py -v
"""

from __future__ import annotations

import shutil
import subprocess
import textwrap
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MIGRATOR = ROOT / "ap-includes" / "class-ap-migrator.php"
MIGRATION_IF = ROOT / "ap-includes" / "class-ap-migration.php"
MIGRATOR_EXC = ROOT / "ap-includes" / "class-ap-migrator-exception.php"
MIGRATIONS_DIR = ROOT / "ap-includes" / "schema" / "migrations"
DB_CLASS = ROOT / "ap-includes" / "class-ap-db.php"
BOOTSTRAP = ROOT / "ap-includes" / "bootstrap.php"
VERSION = ROOT / "ap-includes" / "version.php"


def _php_bin() -> str:
    return shutil.which("php") or "php"


def test_migrator_files_exist() -> None:
    assert MIGRATOR.is_file(), "Missing class-ap-migrator.php"
    assert MIGRATION_IF.is_file(), "Missing class-ap-migration.php"
    assert MIGRATOR_EXC.is_file(), "Missing class-ap-migrator-exception.php"
    assert MIGRATIONS_DIR.is_dir(), "Missing schema/migrations directory"


def test_migrator_declares_public_api() -> None:
    src = MIGRATOR.read_text(encoding="utf-8")
    for needle in (
        "class AP_Migrator",
        "function ap_migrator",
        "function ensureRegistry",
        "function getCurrentVersion",
        "function getAppliedVersions",
        "function discover",
        "function pending",
        "function migrate",
        "function needsMigration",
        "REGISTRY_BASE",
        "schema_migrations",
    ):
        assert needle in src, f"Expected {needle} in class-ap-migrator.php"

    iface = MIGRATION_IF.read_text(encoding="utf-8")
    assert "interface AP_Migration" in iface
    assert "function version" in iface
    assert "function description" in iface
    assert "function up" in iface


def test_bootstrap_requires_migrator() -> None:
    src = BOOTSTRAP.read_text(encoding="utf-8")
    assert "class-ap-migrator.php" in src


def test_db_version_constant_is_integer_string() -> None:
    src = VERSION.read_text(encoding="utf-8")
    assert "AP_DB_VERSION" in src
    assert "define('AP_DB_VERSION'" in src
    assert "define('AP_DB_VERSION', '1')" in src


def test_shipped_core_options_users_migration_exists() -> None:
    mig = MIGRATIONS_DIR / "0001_core_options_users.php"
    assert mig.is_file(), "Missing 0001_core_options_users.php"
    src = mig.read_text(encoding="utf-8")
    for needle in (
        "AP_Migration_0001_Core_Options_Users",
        "options",
        "users",
        "usermeta",
        "option_name",
        "user_login",
        "user_pass",
        "umeta_id",
        "ENGINE=InnoDB",
        "BIGSERIAL",
        "AUTOINCREMENT",
    ):
        assert needle in src, f"Expected {needle} in core migration"


def test_shipped_core_migration_applies_via_php() -> None:
    """Apply real shipped 0001 migration on in-memory SQLite."""
    script = textwrap.dedent(
        f"""
        declare(strict_types=1);
        require {repr(str(VERSION))};
        require {repr(str(DB_CLASS))};
        require {repr(str(MIGRATOR))};
        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');
        $m = new AP_Migrator($db, AP_Migrator::defaultMigrationsPath());
        if ((int) AP_DB_VERSION < 1) {{
            fwrite(STDERR, "AP_DB_VERSION too low\\n");
            exit(2);
        }}
        if (!$m->needsMigration()) {{
            fwrite(STDERR, "expected pending migration\\n");
            exit(3);
        }}
        $applied = $m->migrate();
        if ($applied === [] || (int) $applied[0]['version'] !== 1) {{
            fwrite(STDERR, "version 1 not applied\\n");
            exit(4);
        }}
        foreach (['ap_options', 'ap_users', 'ap_usermeta'] as $t) {{
            $name = $db->getVar(
                "SELECT name FROM sqlite_master WHERE type='table' AND name = ?",
                [$t]
            );
            if ($name !== $t) {{
                fwrite(STDERR, "missing table $t\\n");
                exit(5);
            }}
        }}
        $db->insert('options', [
            'option_name' => 'blogname',
            'option_value' => 'CoreMig',
            'autoload' => 'yes',
        ]);
        $v = $db->getVar(
            'SELECT option_value FROM ' . $db->quoteIdentifier($db->options)
            . ' WHERE option_name = ?',
            ['blogname']
        );
        if ($v !== 'CoreMig') {{
            fwrite(STDERR, "option value mismatch\\n");
            exit(6);
        }}
        if ($m->migrate() !== []) {{
            fwrite(STDERR, "not idempotent\\n");
            exit(7);
        }}
        echo "core_ok\\n";
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
    assert result.returncode == 0, f"core migration failed:\n{combined}"
    assert "core_ok" in (result.stdout or "")


def test_migrate_two_steps_via_php(tmp_path: Path) -> None:
    """Isolated process: apply two migrations on in-memory SQLite."""
    mig_dir = tmp_path / "migrations"
    mig_dir.mkdir()

    for version, slug in ((1, "alpha"), (2, "beta")):
        padded = f"{version:04d}"
        (mig_dir / f"{padded}_{slug}.php").write_text(
            textwrap.dedent(
                f"""\
                <?php
                declare(strict_types=1);
                return new class implements AP_Migration {{
                    public function version(): int {{ return {version}; }}
                    public function description(): string {{ return "step {version}"; }}
                    public function up(AP_DB $db): void {{
                        $t = $db->table("step_{version}");
                        $q = $db->quoteIdentifier($t);
                        $db->query("CREATE TABLE $q (id INTEGER PRIMARY KEY, v TEXT)");
                        $db->insert("step_{version}", ["v" => "ok{version}"]);
                    }}
                }};
                """
            ),
            encoding="utf-8",
        )

    script = textwrap.dedent(
        f"""
        declare(strict_types=1);
        require {repr(str(DB_CLASS))};
        require {repr(str(MIGRATOR))};
        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');
        $m = new AP_Migrator($db, {repr(str(mig_dir))});
        if ($m->getCurrentVersion() !== 0) {{
            fwrite(STDERR, "expected 0\\n");
            exit(2);
        }}
        $applied = $m->migrate();
        if (count($applied) !== 2) {{
            fwrite(STDERR, "applied count\\n");
            exit(3);
        }}
        if ($m->getCurrentVersion() !== 2) {{
            fwrite(STDERR, "version=" . $m->getCurrentVersion() . "\\n");
            exit(4);
        }}
        $v = $db->getVar(
            'SELECT v FROM ' . $db->quoteIdentifier($db->table('step_2'))
        );
        if ($v !== 'ok2') {{
            fwrite(STDERR, "value=" . var_export($v, true) . "\\n");
            exit(5);
        }}
        // Idempotent.
        if ($m->migrate() !== []) {{
            fwrite(STDERR, "not idempotent\\n");
            exit(6);
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
    assert result.returncode == 0, f"migrator roundtrip failed:\n{combined}"
    assert "ok" in (result.stdout or "")


if __name__ == "__main__":
    raise SystemExit(__import__("pytest").main([__file__, "-v"]))
