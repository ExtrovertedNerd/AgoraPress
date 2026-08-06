"""
Smoke tests for who’s online + forum unread tracking (schema v9).

Runnable via:
  pytest tests/test_online_unread.py -v
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
ONLINE_CLASS = ROOT / "ap-includes" / "class-ap-online.php"
READ_CLASS = ROOT / "ap-includes" / "class-ap-forum-read.php"
FORUM_CLASS = ROOT / "ap-includes" / "class-ap-forum.php"
LOAD_CONFIG = ROOT / "ap-includes" / "load-config.php"
FUNCTIONS = ROOT / "ap-includes" / "functions.php"
BOOTSTRAP = ROOT / "ap-includes" / "bootstrap.php"
INSTALLER = ROOT / "ap-includes" / "class-ap-installer.php"


def _php_bin() -> str:
    return shutil.which("php") or "php"


def test_migration_and_classes_exist() -> None:
    assert (MIGRATIONS / "0009_forum_online_unread.php").is_file()
    assert ONLINE_CLASS.is_file()
    assert READ_CLASS.is_file()


def test_db_version_includes_unread_schema() -> None:
    src = VERSION.read_text(encoding="utf-8")
    m = re.search(r"define\('AP_DB_VERSION',\s*'(\d+)'\)", src)
    assert m is not None and int(m.group(1)) >= 9
    assert "Version 9" in src


def test_migration_0009_surface() -> None:
    mig = MIGRATIONS / "0009_forum_online_unread.php"
    src = mig.read_text(encoding="utf-8")
    for needle in (
        "AP_Migration_0009_Forum_Online_Unread",
        "topic_track",
        "forum_track",
        "mark_time",
        "user_id",
        "topic_id",
        "forum_id",
        "PRIMARY KEY",
        "ENGINE=InnoDB",
        "TIMESTAMP",
        "pgsqlStatements",
        "sqliteStatements",
        "mysqlStatements",
    ):
        assert needle in src, f"Expected {needle} in 0009 migration"


def test_online_class_api() -> None:
    src = ONLINE_CLASS.read_text(encoding="utf-8")
    for needle in (
        "class AP_Online",
        "OPTION_ENABLED",
        "forum_online_enabled",
        "OPTION_WINDOW",
        "function isEnabled",
        "function isAvailable",
        "function track",
        "function trackCurrent",
        "function remove",
        "function removeUser",
        "function prune",
        "function isUserOnline",
        "function getOnlineUsers",
        "function getOnlineGuests",
        "function countOnline",
        "function getDisplay",
        "function resolveSessionKey",
    ):
        assert needle in src, f"Expected {needle} in AP_Online"


def test_forum_read_class_api() -> None:
    src = READ_CLASS.read_text(encoding="utf-8")
    for needle in (
        "class AP_Forum_Read",
        "OPTION_ENABLED",
        "forum_unread_tracking_enabled",
        "META_LAST_MARK",
        "function markTopicRead",
        "function markForumRead",
        "function markAllRead",
        "function isTopicUnread",
        "function isForumUnread",
        "function getUnreadTopics",
        "function countUnreadTopicsInForum",
        "function annotateTopics",
        "function getUnreadSummary",
        "function getEffectiveTopicMark",
    ):
        assert needle in src, f"Expected {needle} in AP_Forum_Read"


def test_base_tables_and_db_properties() -> None:
    load = LOAD_CONFIG.read_text(encoding="utf-8")
    assert "topic_track" in load
    assert "forum_track" in load
    db = DB_CLASS.read_text(encoding="utf-8")
    assert "public string $topic_track" in db
    assert "public string $forum_track" in db
    forum = FORUM_CLASS.read_text(encoding="utf-8")
    assert "topic_track" in forum
    assert "forum_track" in forum


def test_bootstrap_and_functions() -> None:
    boot = BOOTSTRAP.read_text(encoding="utf-8")
    assert "class-ap-online.php" in boot
    assert "class-ap-forum-read.php" in boot
    fn = FUNCTIONS.read_text(encoding="utf-8")
    for needle in (
        "function ap_online_enabled",
        "function ap_track_online",
        "function ap_get_online_users",
        "function ap_count_online",
        "function ap_get_online_display",
        "function ap_forum_unread_tracking_enabled",
        "function ap_mark_topic_read",
        "function ap_mark_forum_read",
        "function ap_mark_all_forums_read",
        "function ap_is_topic_unread",
        "function ap_is_forum_unread",
        "function ap_get_unread_topics",
        "function ap_get_unread_summary",
    ):
        assert needle in fn, f"Expected {needle} in functions.php"


def test_installer_seeds_options() -> None:
    src = INSTALLER.read_text(encoding="utf-8")
    assert "forum_online_enabled" in src
    assert "forum_online_window" in src
    assert "forum_unread_tracking_enabled" in src


def test_migration_applies_in_sqlite() -> None:
    php = textwrap.dedent(
        f"""
        declare(strict_types=1);
        require_once {str(ROOT / "ap-includes" / "version.php")!r};
        require_once {str(ROOT / "ap-includes" / "class-ap-db.php")!r};
        require_once {str(ROOT / "ap-includes" / "class-ap-migrator.php")!r};
        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
        ]);
        $db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');
        $m = new AP_Migrator($db, AP_Migrator::defaultMigrationsPath());
        $m->migrate();
        if ($m->getCurrentVersion() < 9) {{
            fwrite(STDERR, "version too low\\n");
            exit(1);
        }}
        foreach (['ap_online', 'ap_topic_track', 'ap_forum_track'] as $t) {{
            $name = $db->getVar(
                "SELECT name FROM sqlite_master WHERE type='table' AND name=?",
                [$t]
            );
            if ($name !== $t) {{
                fwrite(STDERR, "missing table\\n");
                exit(2);
            }}
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
    assert (ROOT / "tests" / "Forum" / "OnlineUnreadTest.php").is_file()
