"""
Smoke tests for private messaging (AP_Private_Message).

Runnable via:
  pytest tests/test_private_messaging.py -v
"""

from __future__ import annotations

import shutil
import subprocess
import textwrap
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MIGRATIONS = ROOT / "ap-includes" / "schema" / "migrations"
PM_CLASS = ROOT / "ap-includes" / "class-ap-private-message.php"
FUNCTIONS = ROOT / "ap-includes" / "functions.php"
BOOTSTRAP = ROOT / "ap-includes" / "bootstrap.php"
INSTALLER = ROOT / "ap-includes" / "class-ap-installer.php"
LOAD_CONFIG = ROOT / "ap-includes" / "load-config.php"


def _php_bin() -> str:
    return shutil.which("php") or "php"


def test_pm_class_exists() -> None:
    assert PM_CLASS.is_file()


def test_messages_table_in_migration_0005() -> None:
    src = (MIGRATIONS / "0005_forum_tables.php").read_text(encoding="utf-8")
    for needle in (
        "messages",
        "message_id",
        "sender_id",
        "recipient_id",
        "parent_id",
        "message_content",
        "sender_deleted",
        "recipient_deleted",
        "read_at",
    ):
        assert needle in src, f"Expected {needle} in 0005 messages schema"


def test_pm_class_api() -> None:
    src = PM_CLASS.read_text(encoding="utf-8")
    for needle in (
        "class AP_Private_Message",
        "OPTION_ENABLED",
        "forum_private_messaging_enabled",
        "function isEnabled",
        "function isAvailable",
        "function userCanSend",
        "function userCanView",
        "function send",
        "function reply",
        "function get",
        "function getForUser",
        "function getInbox",
        "function getOutbox",
        "function getUnread",
        "function countUnread",
        "function getThread",
        "function markRead",
        "function markUnread",
        "function deleteForUser",
        "function forceDelete",
        "function query",
        "function formatContent",
        "function getFolderDisplay",
        "function getThreadDisplay",
        "FOLDER_INBOX",
        "FOLDER_OUTBOX",
    ):
        assert needle in src, f"Expected {needle} in AP_Private_Message"


def test_wired_into_bootstrap_functions_installer() -> None:
    boot = BOOTSTRAP.read_text(encoding="utf-8")
    assert "class-ap-private-message.php" in boot

    funcs = FUNCTIONS.read_text(encoding="utf-8")
    for needle in (
        "function ap_private_messaging_enabled",
        "function ap_user_can_send_pm",
        "function ap_send_private_message",
        "function ap_reply_private_message",
        "function ap_get_private_message",
        "function ap_get_pm_inbox",
        "function ap_get_pm_outbox",
        "function ap_count_unread_pms",
        "function ap_get_pm_thread",
        "function ap_mark_pm_read",
        "function ap_delete_private_message",
        "function ap_get_pm_folder_display",
        "function ap_format_private_message",
    ):
        assert needle in funcs, f"Expected {needle} in functions.php"

    inst = INSTALLER.read_text(encoding="utf-8")
    assert "forum_private_messaging_enabled" in inst

    lc = LOAD_CONFIG.read_text(encoding="utf-8")
    assert "messages" in lc


def test_phpunit_pm_smoke_passes() -> None:
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
        require_once {str(ROOT / "ap-includes" / "class-ap-content-format.php")!r};
        require_once {str(ROOT / "ap-includes" / "class-ap-private-message.php")!r};
        require_once {str(ROOT / "ap-includes" / "functions.php")!r};

        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
        ]);
        $db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');
        $m = new AP_Migrator($db, AP_Migrator::defaultMigrationsPath());
        $m->migrate();

        AP_Options::update(AP_Private_Message::OPTION_ENABLED, '1', $db);
        AP_Options::update('ap_module_forum', '1', $db);
        AP_Roles::flushCache();
        AP_Roles::ensureDefaults($db);

        $a = AP_User::create([
            'user_login' => 'pma',
            'user_email' => 'pma@example.test',
            'user_pass' => 'password12345',
        ], $db);
        $b = AP_User::create([
            'user_login' => 'pmb',
            'user_email' => 'pmb@example.test',
            'user_pass' => 'password12345',
        ], $db);
        if (empty($a['ok']) || empty($b['ok'])) {{
            fwrite(STDERR, "user create failed\\n");
            exit(1);
        }}
        $alice = (int) $a['id'];
        $bob = (int) $b['id'];
        AP_Roles::setUserRole($alice, 'subscriber', $db);
        AP_Roles::setUserRole($bob, 'subscriber', $db);

        if (!function_exists('ap_send_private_message') || !function_exists('ap_count_unread_pms')) {{
            fwrite(STDERR, "helpers missing\\n");
            exit(1);
        }}

        $id = ap_send_private_message([
            'sender_id' => $alice,
            'recipient_id' => $bob,
            'subject' => 'Hi',
            'content' => 'Private body',
        ], $db);
        if ($id < 1) {{
            fwrite(STDERR, "send failed\\n");
            exit(1);
        }}
        if (ap_count_unread_pms($bob, $db) !== 1) {{
            fwrite(STDERR, "unread count wrong\\n");
            exit(1);
        }}
        $reply = ap_reply_private_message([
            'sender_id' => $bob,
            'parent_id' => $id,
            'content' => 'Reply',
        ], $db);
        if ($reply < 1) {{
            fwrite(STDERR, "reply failed\\n");
            exit(1);
        }}
        $thread = ap_get_pm_thread($id, $alice, $db);
        if (count($thread) !== 2) {{
            fwrite(STDERR, "thread size wrong\\n");
            exit(1);
        }}
        if (!ap_mark_pm_read($id, $bob, $db)) {{
            fwrite(STDERR, "mark read failed\\n");
            exit(1);
        }}
        if (!ap_delete_private_message($id, $bob, $db)) {{
            fwrite(STDERR, "delete failed\\n");
            exit(1);
        }}
        if (!AP_Private_Message::isAvailable($db)) {{
            fwrite(STDERR, "pm not available\\n");
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
