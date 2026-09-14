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
NOTIFY_CLASS = ROOT / "ap-includes" / "class-ap-forum-notify.php"
USER_CLASS = ROOT / "ap-includes" / "class-ap-user.php"
OPTIONS_CLASS = ROOT / "ap-includes" / "class-ap-options.php"
FUNCTIONS = ROOT / "ap-includes" / "functions.php"
PHPUNIT = ROOT / "tests" / "Database" / "TopicSubscriptionsMigrationTest.php"
PHPUNIT_SUBS = ROOT / "tests" / "Forum" / "ForumNotifySubscriptionsTest.php"


def _php_bin() -> str:
    return shutil.which("php") or "php"


def test_migration_file_exists() -> None:
    assert (MIGRATIONS / "0013_topic_subscriptions.php").is_file()


def test_db_version_includes_topic_subscriptions() -> None:
    src = VERSION.read_text(encoding="utf-8")
    m = re.search(r"define\('AP_DB_VERSION',\s*'(\d+)'\)", src)
    assert m is not None
    assert int(m.group(1)) == 13
    assert "Version 13" in src
    assert "topic_subscriptions" in src
    assert "topic_track / forum_track unchanged" in src


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
        "PRIMARY KEY (user_id, topic_id)",
        "PRIMARY KEY (`user_id`, `topic_id`)",
        "topic_subscriptions_topic_id",
        "IF NOT EXISTS",
        "ENGINE=InnoDB",
        "pgsqlStatements",
        "sqliteStatements",
        "mysqlStatements",
    ):
        assert needle in src, f"Expected {needle!r} in 0013 migration"
    assert "CREATE TABLE IF NOT EXISTS" in src
    assert "topic_track" not in src
    assert "forum_track" not in src


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
        $ddl = (string) $db->getVar(
            "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?",
            ['ap_topic_subscriptions']
        );
        if (!str_contains($ddl, 'PRIMARY KEY (user_id, topic_id)')) {{
            fwrite(STDERR, "composite primary key missing after 12->13\\n");
            exit(6);
        }}
        $table = $db->quoteIdentifier($db->table('topic_subscriptions'));
        $ok = $db->insert('topic_subscriptions', [
            'user_id' => 4,
            'topic_id' => 8,
            'created_at' => '2026-09-13 10:00:00',
        ]);
        if ($ok !== 1) {{
            fwrite(STDERR, "insert pair failed\\n");
            exit(7);
        }}
        $dup = $db->insert('topic_subscriptions', [
            'user_id' => 4,
            'topic_id' => 8,
            'created_at' => '2026-09-13 10:01:00',
        ]);
        if ($dup !== false) {{
            fwrite(STDERR, "unique (user_id, topic_id) not enforced\\n");
            exit(8);
        }}
        $err = strtolower((string) $db->lastError());
        if ($err === '' || (!str_contains($err, 'unique') && !str_contains($err, 'constraint'))) {{
            fwrite(STDERR, "duplicate insert lastError=" . (string) $db->lastError() . "\\n");
            exit(9);
        }}
        $n = (int) $db->getVar(
            "SELECT COUNT(*) FROM {{$table}} WHERE user_id = ? AND topic_id = ?",
            [4, 8]
        );
        if ($n !== 1) {{
            fwrite(STDERR, "duplicate insert created a second row\\n");
            exit(10);
        }}
        $sibTopic = $db->insert('topic_subscriptions', [
            'user_id' => 4,
            'topic_id' => 9,
            'created_at' => '2026-09-13 10:02:00',
        ]);
        $sibUser = $db->insert('topic_subscriptions', [
            'user_id' => 5,
            'topic_id' => 8,
            'created_at' => '2026-09-13 10:03:00',
        ]);
        if ($sibTopic !== 1 || $sibUser !== 1) {{
            fwrite(STDERR, "sibling pairs should insert\\n");
            exit(11);
        }}
        $del = $db->delete('topic_subscriptions', [
            'user_id' => 4,
            'topic_id' => 8,
        ]);
        if ($del !== 1) {{
            fwrite(STDERR, "delete pair failed\\n");
            exit(12);
        }}
        $gone = $db->getVar(
            "SELECT user_id FROM {{$table}} WHERE user_id = ? AND topic_id = ?",
            [4, 8]
        );
        if ($gone !== null) {{
            fwrite(STDERR, "row remained after delete\\n");
            exit(13);
        }}
        $left = (int) $db->getVar("SELECT COUNT(*) FROM {{$table}}");
        if ($left !== 2) {{
            fwrite(STDERR, "sibling rows lost after delete count=$left\\n");
            exit(14);
        }}
        $migration = require {str(MIGRATIONS / "0013_topic_subscriptions.php")!r};
        $migration->up($db);
        if ($m->migrate() !== []) {{
            fwrite(STDERR, "migrator not idempotent after 13\\n");
            exit(15);
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


def test_schema_13_leaves_unread_track_tables_unchanged() -> None:
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
        if ((int) AP_DB_VERSION !== 13) {{
            fwrite(STDERR, "AP_DB_VERSION expected 13\\n");
            exit(1);
        }}
        $m->migrate(12);
        $trackSql = $db->insert('topic_track', [
            'user_id' => 5,
            'topic_id' => 9,
            'forum_id' => 2,
            'mark_time' => '2026-09-13 08:00:00',
        ]);
        $forumSql = $db->insert('forum_track', [
            'user_id' => 5,
            'forum_id' => 2,
            'mark_time' => '2026-09-13 08:15:00',
        ]);
        if ($trackSql !== 1 || $forumSql !== 1) {{
            fwrite(STDERR, "seed unread rows failed\\n");
            exit(2);
        }}
        $snap = static function (AP_DB $db): string {{
            $rows = $db->getResults(
                "SELECT name, type, IFNULL(sql, '') AS sql FROM sqlite_master"
                . " WHERE tbl_name IN ('ap_topic_track', 'ap_forum_track')"
                . " ORDER BY tbl_name, type, name"
            );
            $out = '';
            foreach ($rows as $row) {{
                $out .= $row->name . '|' . $row->type . '|' . $row->sql . "\\n";
            }}
            return $out;
        }};
        $before = $snap($db);
        $applied = $m->migrate(13);
        if (count($applied) !== 1 || (int) $applied[0]['version'] !== 13) {{
            fwrite(STDERR, "v13 not applied cleanly\\n");
            exit(3);
        }}
        if ($snap($db) !== $before) {{
            fwrite(STDERR, "unread track schema changed at 13\\n");
            exit(4);
        }}
        $topic = $db->getRow(
            "SELECT mark_time FROM " . $db->quoteIdentifier($db->topic_track)
            . " WHERE user_id = ? AND topic_id = ?",
            [5, 9]
        );
        $forum = $db->getRow(
            "SELECT mark_time FROM " . $db->quoteIdentifier($db->forum_track)
            . " WHERE user_id = ? AND forum_id = ?",
            [5, 2]
        );
        if ($topic === null || (string) $topic->mark_time !== '2026-09-13 08:00:00') {{
            fwrite(STDERR, "topic_track row lost\\n");
            exit(5);
        }}
        if ($forum === null || (string) $forum->mark_time !== '2026-09-13 08:15:00') {{
            fwrite(STDERR, "forum_track row lost\\n");
            exit(6);
        }}
        echo "unread_track_unchanged_ok\\n";
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
    assert result.returncode == 0, f"unread track invariant failed:\n{combined}"
    assert "unread_track_unchanged_ok" in (result.stdout or "")


def test_phpunit_topic_subscriptions_migration_suite_runs() -> None:
    phpunit = ROOT / "vendor" / "bin" / "phpunit"
    if not phpunit.is_file():
        return
    cmd = [
        _php_bin(),
        str(phpunit),
        "--configuration",
        str(ROOT / "phpunit.xml.dist"),
        str(PHPUNIT),
        str(PHPUNIT_SUBS),
    ]
    result = subprocess.run(cmd, cwd=str(ROOT), capture_output=True, text=True, check=False)
    combined = (result.stdout or "") + (result.stderr or "")
    assert result.returncode == 0, f"phpunit topic subscriptions failed:\n{combined}"
    assert PHPUNIT.is_file()
    assert PHPUNIT_SUBS.is_file()
    src = PHPUNIT.read_text(encoding="utf-8")
    assert "function testMigrateFromSchema12CreatesSubscriptionsTable" in src
    assert "function testUniqueConstraintAndAddRemovePair" in src
    subs = PHPUNIT_SUBS.read_text(encoding="utf-8")
    assert "function testSubscribeUnsubscribeAddsAndRemovesOnePair" in subs
    assert "function testSubscribeFormHtmlHelper" in subs
    assert "function testSubscribeFormHtmlAutoGatesWhenShowOmitted" in subs
    assert "function testViewerMaySubscribeRequiresSiteOnLoginAndViewForum" in subs
    assert "function testViewerMaySubscribeWhenMembersReadonly" in subs
    assert "function testViewerMaySubscribeHonorsGroupOnlyViewForum" in subs
    assert "function testComposeCheckboxHtmlDefaultOffAndGated" in subs
    assert "function testWantsNotifyOnComposeDefaultOff" in subs
    assert "function testMaybeSubscribeFromComposeNeverAutoWatches" in subs
    assert "function testEnableUserNotifyOnSubscribeFlipsOnceAndRejectsGuest" in subs
    assert "function testEnableUserNotifyOnSubscribeTreatsMissingMetaAsOff" in subs
    assert "function testStorageSubscribeDoesNotFlipUserMaster" in subs


def test_subscribe_unsubscribe_helpers_add_remove_one_pair() -> None:
    php = textwrap.dedent(
        f"""
        declare(strict_types=1);
        require_once {str(VERSION)!r};
        require_once {str(LOAD_CONFIG)!r};
        require_once {str(DB_CLASS)!r};
        require_once {str(MIGRATOR)!r};
        require_once {str(NOTIFY_CLASS)!r};
        require_once {str(USER_CLASS)!r};
        require_once {str(OPTIONS_CLASS)!r};
        require_once {str(FORUM_CLASS)!r};
        require_once {str(FUNCTIONS)!r};
        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');
        $m = new AP_Migrator($db, AP_Migrator::defaultMigrationsPath());
        $m->migrate();
        $created = AP_User::create([
            'user_login' => 'subhelper',
            'user_email' => 'subhelper@example.com',
            'user_pass' => 'securepass0',
        ], $db);
        if (empty($created['ok'])) {{
            fwrite(STDERR, "user create failed\\n");
            exit(1);
        }}
        $userId = (int) $created['id'];
        $forumId = AP_Forum::insertForum(['forum_name' => 'Helper Forum'], $db);
        $topicId = AP_Forum::createTopic([
            'forum_id' => $forumId,
            'topic_title' => 'Helper topic',
            'content' => 'First',
        ], $db);
        if ($userId < 1 || $forumId < 1 || $topicId < 1) {{
            fwrite(STDERR, "fixture ids invalid\\n");
            exit(2);
        }}
        if (AP_Forum_Notify::isSubscribed($userId, $topicId, $db)) {{
            fwrite(STDERR, "already subscribed\\n");
            exit(3);
        }}
        if (!AP_Forum_Notify::subscribe($userId, $topicId, $db)) {{
            fwrite(STDERR, "subscribe failed\\n");
            exit(4);
        }}
        if (!AP_Forum_Notify::subscribe($userId, $topicId, $db)) {{
            fwrite(STDERR, "idempotent subscribe failed\\n");
            exit(5);
        }}
        $n = (int) $db->getVar(
            "SELECT COUNT(*) FROM " . $db->quoteIdentifier($db->table('topic_subscriptions'))
            . " WHERE user_id = ? AND topic_id = ?",
            [$userId, $topicId]
        );
        if ($n !== 1) {{
            fwrite(STDERR, "unique pair not one row\\n");
            exit(6);
        }}
        if (!AP_Forum_Notify::unsubscribe($userId, $topicId, $db)) {{
            fwrite(STDERR, "unsubscribe failed\\n");
            exit(7);
        }}
        if (AP_Forum_Notify::isSubscribed($userId, $topicId, $db)) {{
            fwrite(STDERR, "row remained after unsubscribe\\n");
            exit(8);
        }}
        echo "subscribe_helpers_ok\\n";
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
    assert result.returncode == 0, f"subscribe helpers failed:\n{combined}"
    assert "subscribe_helpers_ok" in (result.stdout or "")


if __name__ == "__main__":
    raise SystemExit(__import__("pytest").main([__file__, "-v"]))
