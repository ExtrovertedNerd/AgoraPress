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
    assert "define('AP_DB_VERSION', '10')" in src


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


def test_shipped_posts_postmeta_migration_exists() -> None:
    mig = MIGRATIONS_DIR / "0002_core_posts_postmeta.php"
    assert mig.is_file(), "Missing 0002_core_posts_postmeta.php"
    src = mig.read_text(encoding="utf-8")
    for needle in (
        "AP_Migration_0002_Core_Posts_Postmeta",
        "posts",
        "postmeta",
        "post_title",
        "post_status",
        "post_type",
        "post_parent",
        "meta_id",
        "post_id",
        "ENGINE=InnoDB",
        "BIGSERIAL",
        "AUTOINCREMENT",
    ):
        assert needle in src, f"Expected {needle} in posts/postmeta migration"


def test_shipped_terms_taxonomies_migration_exists() -> None:
    mig = MIGRATIONS_DIR / "0003_core_terms_taxonomies.php"
    assert mig.is_file(), "Missing 0003_core_terms_taxonomies.php"
    src = mig.read_text(encoding="utf-8")
    for needle in (
        "AP_Migration_0003_Core_Terms_Taxonomies",
        "terms",
        "term_taxonomy",
        "term_relationships",
        "term_id",
        "object_id",
        "ENGINE=InnoDB",
        "BIGSERIAL",
        "AUTOINCREMENT",
    ):
        assert needle in src, f"Expected {needle} in terms/taxonomies migration"


def test_shipped_comments_commentmeta_migration_exists() -> None:
    mig = MIGRATIONS_DIR / "0004_core_comments_commentmeta.php"
    assert mig.is_file(), "Missing 0004_core_comments_commentmeta.php"
    src = mig.read_text(encoding="utf-8")
    for needle in (
        "AP_Migration_0004_Core_Comments_Commentmeta",
        "comments",
        "commentmeta",
        "comment_ID",
        "comment_post_ID",
        "comment_approved",
        "meta_id",
        "ENGINE=InnoDB",
        "BIGSERIAL",
        "AUTOINCREMENT",
    ):
        assert needle in src, f"Expected {needle} in comments/commentmeta migration"


def test_shipped_forum_tables_migration_exists() -> None:
    mig = MIGRATIONS_DIR / "0005_forum_tables.php"
    assert mig.is_file(), "Missing 0005_forum_tables.php"
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
        "forum_id",
        "topic_id",
        "post_id",
        "ENGINE=InnoDB",
        "BIGSERIAL",
        "AUTOINCREMENT",
    ):
        assert needle in src, f"Expected {needle} in forum tables migration"


def test_shipped_core_migration_applies_via_php() -> None:
    """Apply real shipped migrations (0001–0006) on in-memory SQLite."""
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
        if ((int) AP_DB_VERSION < 10) {{
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
        if (count($applied) < 2 || (int) $applied[1]['version'] !== 2) {{
            fwrite(STDERR, "version 2 not applied\\n");
            exit(4);
        }}
        if (count($applied) < 3 || (int) $applied[2]['version'] !== 3) {{
            fwrite(STDERR, "version 3 not applied\\n");
            exit(4);
        }}
        if (count($applied) < 4 || (int) $applied[3]['version'] !== 4) {{
            fwrite(STDERR, "version 4 not applied\\n");
            exit(4);
        }}
        if (count($applied) < 5 || (int) $applied[4]['version'] !== 5) {{
            fwrite(STDERR, "version 5 not applied\\n");
            exit(4);
        }}
        if (count($applied) < 6 || (int) $applied[5]['version'] !== 6) {{
            fwrite(STDERR, "version 6 not applied\\n");
            exit(4);
        }}
        if (count($applied) < 7 || (int) $applied[6]['version'] !== 7) {{
            fwrite(STDERR, "version 7 not applied\\n");
            exit(4);
        }}
        if (count($applied) < 8 || (int) $applied[7]['version'] !== 8) {{
            fwrite(STDERR, "version 8 not applied\\n");
            exit(4);
        }}
        if (count($applied) < 9 || (int) $applied[8]['version'] !== 9) {{
            fwrite(STDERR, "version 9 not applied\\n");
            exit(4);
        }}
        if (count($applied) < 10 || (int) $applied[9]['version'] !== 10) {{
            fwrite(STDERR, "version 10 not applied\\n");
            exit(4);
        }}
        foreach ([
            'ap_options', 'ap_users', 'ap_usermeta', 'ap_posts', 'ap_postmeta',
            'ap_terms', 'ap_term_taxonomy', 'ap_term_relationships',
            'ap_comments', 'ap_commentmeta',
            'ap_forums', 'ap_topics', 'ap_forum_posts', 'ap_forum_attachments',
            'ap_groups', 'ap_group_members', 'ap_forum_permissions',
            'ap_messages', 'ap_ranks',
            'ap_reports', 'ap_warnings', 'ap_bans', 'ap_online',
            'ap_topic_track', 'ap_forum_track',
            'ap_analytics_hits', 'ap_analytics_daily',
        ] as $t) {{
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
        $db->insert('posts', [
            'post_author' => 1,
            'post_content' => 'Body',
            'post_title' => 'Hello',
            'post_excerpt' => '',
            'post_status' => 'publish',
            'comment_status' => 'open',
            'ping_status' => 'open',
            'post_password' => '',
            'post_name' => 'hello',
            'to_ping' => '',
            'pinged' => '',
            'post_content_filtered' => '',
            'post_parent' => 0,
            'guid' => '',
            'menu_order' => 0,
            'post_type' => 'post',
            'post_mime_type' => '',
            'comment_count' => 0,
        ]);
        $title = $db->getVar(
            'SELECT post_title FROM ' . $db->quoteIdentifier($db->posts)
            . ' WHERE post_name = ?',
            ['hello']
        );
        if ($title !== 'Hello') {{
            fwrite(STDERR, "post title mismatch\\n");
            exit(8);
        }}
        $db->insert('terms', [
            'name' => 'News',
            'slug' => 'news',
            'term_group' => 0,
        ]);
        $termId = (int) $db->lastInsertId();
        if ($termId < 1) {{
            fwrite(STDERR, "term insert failed\\n");
            exit(10);
        }}
        $db->insert('term_taxonomy', [
            'term_id' => $termId,
            'taxonomy' => 'category',
            'description' => '',
            'parent' => 0,
            'count' => 0,
        ]);
        $ttId = (int) $db->lastInsertId();
        $db->insert('term_relationships', [
            'object_id' => 1,
            'term_taxonomy_id' => $ttId,
            'term_order' => 0,
        ]);
        $slug = $db->getVar(
            'SELECT slug FROM ' . $db->quoteIdentifier($db->terms)
            . ' WHERE term_id = ?',
            [$termId]
        );
        if ($slug !== 'news') {{
            fwrite(STDERR, "term slug mismatch\\n");
            exit(11);
        }}
        $db->insert('comments', [
            'comment_post_ID' => 1,
            'comment_author' => 'Tester',
            'comment_author_email' => 't@example.com',
            'comment_author_url' => '',
            'comment_author_IP' => '127.0.0.1',
            'comment_date' => '2026-08-03 12:00:00',
            'comment_date_gmt' => '2026-08-03 12:00:00',
            'comment_content' => 'Hi',
            'comment_karma' => 0,
            'comment_approved' => '1',
            'comment_agent' => '',
            'comment_type' => 'comment',
            'comment_parent' => 0,
            'user_id' => 0,
        ]);
        $cid = (int) $db->lastInsertId();
        if ($cid < 1) {{
            fwrite(STDERR, "comment insert failed\\n");
            exit(12);
        }}
        $db->insert('commentmeta', [
            'comment_id' => $cid,
            'meta_key' => 'source',
            'meta_value' => 'migtest',
        ]);
        $cauthor = $db->getVar(
            'SELECT comment_author FROM ' . $db->quoteIdentifier($db->comments)
            . ' WHERE ' . $db->quoteIdentifier('comment_ID') . ' = ?',
            [$cid]
        );
        if ($cauthor !== 'Tester') {{
            fwrite(STDERR, "comment author mismatch\\n");
            exit(13);
        }}
        $db->insert('forums', [
            'parent_id' => 0,
            'forum_type' => 'forum',
            'forum_status' => 'open',
            'forum_name' => 'General',
            'forum_slug' => 'general',
            'forum_desc' => '',
            'forum_order' => 0,
            'topic_count' => 0,
            'post_count' => 0,
            'last_post_id' => 0,
            'last_poster_id' => 0,
            'last_post_time' => '1970-01-01 00:00:00',
            'last_topic_id' => 0,
        ]);
        $fid = (int) $db->lastInsertId();
        if ($fid < 1) {{
            fwrite(STDERR, "forum insert failed\\n");
            exit(14);
        }}
        $fname = $db->getVar(
            'SELECT forum_name FROM ' . $db->quoteIdentifier($db->forums)
            . ' WHERE forum_id = ?',
            [$fid]
        );
        if ($fname !== 'General') {{
            fwrite(STDERR, "forum name mismatch\\n");
            exit(15);
        }}
        if ($m->getCurrentVersion() !== 10) {{
            fwrite(STDERR, "current version not 10\\n");
            exit(9);
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
