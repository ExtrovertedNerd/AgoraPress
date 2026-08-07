"""
Smoke tests for topic type enum migration (schema v12) + normalize helpers.

Runnable via:
  pytest tests/test_topic_type_enum.py -v
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
FORUM_CLASS = ROOT / "ap-includes" / "class-ap-forum.php"
SCHEMA_DOC = ROOT / "docs" / "schema.md"


def _php_bin() -> str:
    return shutil.which("php") or "php"


def test_migration_file_exists() -> None:
    assert (MIGRATIONS / "0012_topic_type_enum.php").is_file()


def test_db_version_includes_topic_type() -> None:
    src = VERSION.read_text(encoding="utf-8")
    m = re.search(r"define\('AP_DB_VERSION',\s*'(\d+)'\)", src)
    assert m is not None
    assert int(m.group(1)) >= 12
    assert "Version 12" in src
    assert "standard" in src
    assert "announcement" in src
    assert "rules" in src


def test_migration_0012_surface() -> None:
    mig = MIGRATIONS / "0012_topic_type_enum.php"
    src = mig.read_text(encoding="utf-8")
    for needle in (
        "AP_Migration_0012_Topic_Type_Enum",
        "topic_type",
        "standard",
        "sticky",
        "announcement",
        "rules",
        "normal",
        "announce",
        "global",
        "backfill",
    ):
        assert needle in src, f"Expected {needle!r} in 0012 migration"


def test_topic_type_wired_to_create_edit_permissions() -> None:
    """SPEC A2: create/edit + sticky/announce caps wired to topic type."""
    front = (ROOT / "ap-includes" / "class-ap-forum-front.php").read_text(encoding="utf-8")
    perms = (ROOT / "ap-includes" / "class-ap-forum-permissions.php").read_text(encoding="utf-8")
    functions = (ROOT / "ap-includes" / "functions.php").read_text(encoding="utf-8")
    for needle in (
        "ACTION_SET_TOPIC_TYPE",
        "topic_type",
        "userCanSetTopicType",
        "allowed_topic_types",
    ):
        assert needle in front, f"missing {needle} in front"
    for needle in (
        "userCanSticky",
        "userCanAnnounce",
        "userCanSetTopicType",
        "allowedTopicTypesForCreate",
        "allowedTopicTypesForEdit",
    ):
        assert needle in perms, f"missing {needle} in permissions"
    for needle in (
        "ap_forum_topic_type_select_html",
        "ap_forum_allowed_topic_types_for_create",
        "ap_forum_topic_type_label",
    ):
        assert needle in functions, f"missing {needle} in functions"


def test_forum_class_topic_type_api() -> None:
    src = FORUM_CLASS.read_text(encoding="utf-8")
    for needle in (
        "TOPIC_TYPE_STANDARD",
        "TOPIC_TYPE_STICKY",
        "TOPIC_TYPE_ANNOUNCEMENT",
        "TOPIC_TYPE_RULES",
        "function normalizeTopicType",
        "function topicTypes",
        "function topicIconType",
        "function forumIconType",
        "function forumToDisplayRow",
        "function topicToDisplayRow",
        "function buildForumRowPreload",
        "function buildForumLastPostPayload",
    ):
        assert needle in src, f"Expected {needle!r} in class-ap-forum.php"


def test_schema_doc_documents_topic_type() -> None:
    text = SCHEMA_DOC.read_text(encoding="utf-8").lower()
    assert "topic_type" in text
    assert "standard" in text
    assert "announcement" in text
    assert "rules" in text


def test_migration_applies_and_backfills_in_sqlite() -> None:
    php = textwrap.dedent(
        f"""
        declare(strict_types=1);
        require_once {str(VERSION)!r};
        require_once {str(DB_CLASS)!r};
        require_once {str(MIGRATOR)!r};
        require_once {str(FORUM_CLASS)!r};
        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');
        $m = new AP_Migrator($db, AP_Migrator::defaultMigrationsPath());
        if ((int) AP_DB_VERSION < 12) {{
            fwrite(STDERR, "AP_DB_VERSION expected >= 12\\n");
            exit(1);
        }}
        $m->migrate(11);

        $db->insert('forums', [
            'parent_id' => 0,
            'forum_name' => 'Types',
            'forum_slug' => 'types',
            'forum_desc' => '',
            'forum_type' => 'forum',
            'forum_status' => 'open',
            'forum_order' => 0,
            'topic_count' => 0,
            'post_count' => 0,
            'last_post_id' => 0,
            'last_poster_id' => 0,
            'last_post_time' => '1970-01-01 00:00:00',
            'last_topic_id' => 0,
        ]);
        $forumId = (int) $db->lastInsertId();
        $seed = [
            'legacy-normal' => 'normal',
            'legacy-announce' => 'announce',
            'legacy-global' => 'global',
            'legacy-sticky' => 'sticky',
            'legacy-junk' => 'wat',
        ];
        foreach ($seed as $slug => $type) {{
            $db->insert('topics', [
                'forum_id' => $forumId,
                'topic_title' => $slug,
                'topic_slug' => $slug,
                'topic_poster' => 1,
                'topic_status' => 'open',
                'topic_type' => $type,
                'topic_approved' => 1,
                'topic_views' => 0,
                'reply_count' => 0,
                'first_post_id' => 0,
                'last_post_id' => 0,
                'last_poster_id' => 0,
                'topic_time' => '2026-01-01 00:00:00',
                'topic_modified' => '2026-01-01 00:00:00',
                'topic_last_post_time' => '2026-01-01 00:00:00',
            ]);
        }}

        $applied = $m->migrate(12);
        if (count($applied) !== 1 || (int) $applied[0]['version'] !== 12) {{
            fwrite(STDERR, "v12 not applied cleanly\\n");
            exit(2);
        }}
        $table = $db->quoteIdentifier($db->table('topics'));
        $expect = [
            'legacy-normal' => 'standard',
            'legacy-announce' => 'announcement',
            'legacy-global' => 'announcement',
            'legacy-sticky' => 'sticky',
            'legacy-junk' => 'standard',
        ];
        foreach ($expect as $slug => $want) {{
            $got = (string) $db->getVar(
                "SELECT topic_type FROM {{$table}} WHERE topic_slug = ?",
                [$slug]
            );
            if ($got !== $want) {{
                fwrite(STDERR, "backfill fail $slug got=$got want=$want\\n");
                exit(3);
            }}
        }}
        if (AP_Forum::normalizeTopicType('info') !== 'rules') {{
            fwrite(STDERR, "normalize info\\n");
            exit(4);
        }}
        echo "topic_type_ok\\n";
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
    assert result.returncode == 0, f"topic type migration failed:\n{combined}"
    assert "topic_type_ok" in (result.stdout or "")


def test_phpunit_topic_type_migration_suite_runs() -> None:
    result = subprocess.run(
        [
            _php_bin(),
            "vendor/bin/phpunit",
            "-c",
            "phpunit.xml.dist",
            "tests/Database/TopicTypeEnumMigrationTest.php",
        ],
        cwd=str(ROOT),
        capture_output=True,
        text=True,
        check=False,
    )
    combined = (result.stdout or "") + (result.stderr or "")
    assert result.returncode == 0, f"PHPUnit topic type migration failed:\n{combined}"


if __name__ == "__main__":
    raise SystemExit(__import__("pytest").main([__file__, "-v"]))
