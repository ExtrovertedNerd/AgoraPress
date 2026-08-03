"""
Smoke tests for full forum moderation tools (schema v8).

Runnable via:
  pytest tests/test_forum_moderation.py -v
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
MOD_CLASS = ROOT / "ap-includes" / "class-ap-forum-moderation.php"
FORUM_CLASS = ROOT / "ap-includes" / "class-ap-forum.php"
LOAD_CONFIG = ROOT / "ap-includes" / "load-config.php"
FUNCTIONS = ROOT / "ap-includes" / "functions.php"
BOOTSTRAP = ROOT / "ap-includes" / "bootstrap.php"


def _php_bin() -> str:
    return shutil.which("php") or "php"


def test_migration_and_class_exist() -> None:
    assert (MIGRATIONS / "0008_forum_moderation.php").is_file()
    assert MOD_CLASS.is_file()


def test_db_version_at_least_eight() -> None:
    src = VERSION.read_text(encoding="utf-8")
    # Moderation tables landed at v8; later migrations may bump further.
    assert "AP_DB_VERSION" in src
    assert "Version 8" in src
    m = re.search(r"define\('AP_DB_VERSION',\s*'(\d+)'\)", src)
    assert m is not None
    assert int(m.group(1)) >= 8


def test_migration_0008_surface() -> None:
    mig = MIGRATIONS / "0008_forum_moderation.php"
    src = mig.read_text(encoding="utf-8")
    for needle in (
        "AP_Migration_0008_Forum_Moderation",
        "warnings",
        "bans",
        "warning_id",
        "ban_id",
        "ban_type",
        "ENGINE=InnoDB",
        "BIGSERIAL",
        "AUTOINCREMENT",
    ):
        assert needle in src, f"Expected {needle} in 0008 migration"


def test_moderation_class_api() -> None:
    src = MOD_CLASS.read_text(encoding="utf-8")
    for needle in (
        "class AP_Forum_Moderation",
        "function lockTopic",
        "function unlockTopic",
        "function softDeleteTopic",
        "function restoreTopic",
        "function moveTopic",
        "function mergeTopics",
        "function splitTopic",
        "function softDeletePost",
        "function restorePost",
        "function editPost",
        "function createReport",
        "function resolveReport",
        "function dismissReport",
        "function issueWarning",
        "function revokeWarning",
        "function banUser",
        "function suspendUser",
        "function unbanUser",
        "function isUserBanned",
        "function banIp",
        "function isIpBanned",
        "REPORT_STATUS_OPEN",
        "BAN_TYPE_USER",
        "WARNING_STATUS_ACTIVE",
    ):
        assert needle in src, f"Expected {needle} in AP_Forum_Moderation"


def test_wired_into_bootstrap_functions_tables() -> None:
    boot = BOOTSTRAP.read_text(encoding="utf-8")
    assert "class-ap-forum-moderation.php" in boot

    funcs = FUNCTIONS.read_text(encoding="utf-8")
    for needle in (
        "function ap_lock_topic",
        "function ap_move_topic",
        "function ap_merge_topics",
        "function ap_split_topic",
        "function ap_soft_delete_topic",
        "function ap_restore_topic",
        "function ap_create_report",
        "function ap_issue_warning",
        "function ap_ban_user",
        "function ap_unban_user",
        "function ap_is_user_banned",
    ):
        assert needle in funcs, f"Expected {needle} in functions.php"

    lc = LOAD_CONFIG.read_text(encoding="utf-8")
    assert "warnings" in lc
    assert "bans" in lc

    forum = FORUM_CLASS.read_text(encoding="utf-8")
    assert "'warnings'" in forum or '"warnings"' in forum
    assert "'bans'" in forum or '"bans"' in forum

    db = DB_CLASS.read_text(encoding="utf-8")
    assert "public string $warnings" in db
    assert "public string $bans" in db


def test_phpunit_moderation_passes() -> None:
    script = textwrap.dedent(
        f"""
        declare(strict_types=1);
        require_once {str(ROOT / "ap-includes" / "version.php")!r};
        require_once {str(ROOT / "ap-includes" / "class-ap-db.php")!r};
        require_once {str(ROOT / "ap-includes" / "class-ap-migrator.php")!r};
        require_once {str(ROOT / "ap-includes" / "class-ap-options.php")!r};
        require_once {str(ROOT / "ap-includes" / "class-ap-user.php")!r};
        require_once {str(ROOT / "ap-includes" / "class-ap-roles.php")!r};
        require_once {str(ROOT / "ap-includes" / "class-ap-forum.php")!r};
        require_once {str(ROOT / "ap-includes" / "class-ap-forum-moderation.php")!r};
        require_once {str(ROOT / "ap-includes" / "functions.php")!r};

        if ((int) AP_DB_VERSION < 8) {{
            fwrite(STDERR, "AP_DB_VERSION expected >= 8\\n");
            exit(1);
        }}

        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
        ]);
        $db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');
        $m = new AP_Migrator($db, AP_Migrator::defaultMigrationsPath());
        $applied = $m->migrate();
        if ($m->getCurrentVersion() < 8) {{
            fwrite(STDERR, "schema version too low\\n");
            exit(1);
        }}

        foreach (['ap_warnings', 'ap_bans', 'ap_reports'] as $t) {{
            $name = $db->getVar(
                "SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?",
                [$t]
            );
            if ($name !== $t) {{
                fwrite(STDERR, "missing table $t\\n");
                exit(1);
            }}
        }}

        $forumId = AP_Forum::insertForum(['forum_name' => 'Smoke'], $db);
        $topicId = AP_Forum::createTopic([
            'forum_id' => $forumId,
            'topic_title' => 'T',
            'content' => 'Body',
        ], $db);
        if (!AP_Forum_Moderation::lockTopic($topicId, 0, $db)) {{
            fwrite(STDERR, "lock failed\\n");
            exit(1);
        }}
        $b = AP_Forum::insertForum(['forum_name' => 'Dest'], $db);
        if (!AP_Forum_Moderation::moveTopic($topicId, $b, 0, $db)) {{
            fwrite(STDERR, "move failed\\n");
            exit(1);
        }}
        $rid = AP_Forum_Moderation::createReport([
            'reporter_id' => 1,
            'type' => 'topic',
            'object_id' => $topicId,
            'reason' => 'test',
        ], $db);
        if ($rid < 1) {{
            fwrite(STDERR, "report failed\\n");
            exit(1);
        }}
        $wid = AP_Forum_Moderation::issueWarning([
            'user_id' => 3,
            'issuer_id' => 1,
            'reason' => 'note',
        ], $db);
        if ($wid < 1) {{
            fwrite(STDERR, "warning failed\\n");
            exit(1);
        }}
        $user = AP_User::create([
            'user_login' => 'smokeban',
            'user_email' => 'smoke@example.test',
            'user_pass' => 'password123',
        ], $db);
        if (empty($user['ok'])) {{
            fwrite(STDERR, "user create failed\\n");
            exit(1);
        }}
        $banId = AP_Forum_Moderation::banUser((int) $user['id'], ['reason' => 'x'], $db);
        if ($banId < 1 || !AP_Forum_Moderation::isUserBanned((int) $user['id'], $db)) {{
            fwrite(STDERR, "ban failed\\n");
            exit(1);
        }}
        if (!function_exists('ap_merge_topics') || !function_exists('ap_split_topic')) {{
            fwrite(STDERR, "helpers missing\\n");
            exit(1);
        }}
        echo "OK\\n";
        """
    )
    proc = subprocess.run(
        [_php_bin(), "-d", "display_errors=1", "-r", script],
        capture_output=True,
        text=True,
        cwd=str(ROOT),
        check=False,
    )
    assert proc.returncode == 0, proc.stderr + proc.stdout
    assert "OK" in proc.stdout
