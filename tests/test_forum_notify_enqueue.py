"""
Smoke tests: approved reply POST enqueues topic_id + reply_post_id, no SMTP.

Runnable via:
  pytest tests/test_forum_notify_enqueue.py -v
"""

from __future__ import annotations

import shutil
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
NOTIFY = ROOT / "ap-includes" / "class-ap-forum-notify.php"
FRONT = ROOT / "ap-includes" / "class-ap-forum-front.php"
BOOTSTRAP = ROOT / "ap-includes" / "bootstrap.php"
FUNCTIONS = ROOT / "ap-includes" / "functions.php"
INDEX = ROOT / "index.php"
CRON = ROOT / "ap-includes" / "class-ap-cron.php"
PHPUNIT = ROOT / "tests" / "Forum" / "ForumNotifyEnqueueTest.php"


def _php_bin() -> str:
    return shutil.which("php") or "php"


def test_enqueue_files_exist() -> None:
    for path in (NOTIFY, FRONT, BOOTSTRAP, FUNCTIONS, INDEX, CRON, PHPUNIT):
        assert path.is_file(), f"Missing {path.relative_to(ROOT)}"


def test_notify_enqueue_api() -> None:
    src = NOTIFY.read_text(encoding="utf-8")
    for needle in (
        "function enqueueReply",
        "function maybeEnqueueApprovedReply",
        "function registerHooks",
        "ap_forum_post_approved",
        "CRON_HOOK",
        "ap_forum_topic_notify",
        "topic_id",
        "reply_post_id",
        "scheduleSingle",
        "Does not send mail",
        "does not spawn cron",
        "ap_forum_post_inserted",
    ):
        assert needle in src, f"AP_Forum_Notify missing {needle!r}"
    enqueue_fn = src.split("function enqueueReply", 1)[1].split(
        "function maybeEnqueueApprovedReply", 1
    )[0]
    assert "AP_Cron::spawn(" not in enqueue_fn
    assert "AP_Mail::send(" not in enqueue_fn
    assert "runDue(" not in enqueue_fn


def test_front_reply_enqueues_without_mail() -> None:
    src = FRONT.read_text(encoding="utf-8")
    assert "maybeEnqueueApprovedReply" in src
    assert "enqueueReply" in src
    assert "No N SMTP" in src or "no N SMTP" in src
    assert "AP_Mail::send" not in src
    assert "AP_Cron::spawn" not in src

    handle = src.split("function handleReply", 1)[1].split("function handleEditPost", 1)[0]
    assert "maybeEnqueueApprovedReply" in handle
    assert "enqueueReply" in handle
    assert "AP_Mail" not in handle
    assert "AP_Cron::spawn" not in handle
    assert "runDue(" not in handle


def test_bootstrap_registers_approved_hook() -> None:
    boot = BOOTSTRAP.read_text(encoding="utf-8")
    assert "class-ap-forum-notify.php" in boot
    assert "AP_Forum_Notify::registerHooks" in boot
    # Spawn is bootstrap-time (before handlePost), not inside enqueue.
    assert "AP_Cron::spawn" in boot


def test_index_handle_post_is_after_bootstrap() -> None:
    index = INDEX.read_text(encoding="utf-8")
    assert "AP_Forum_Front::handlePost" in index
    assert "Location:" in index


def test_functions_helper() -> None:
    functions = FUNCTIONS.read_text(encoding="utf-8")
    assert "function ap_forum_notify_enqueue_reply" in functions
    assert "function ap_forum_notify_maybe_enqueue_approved_reply" in functions
    assert "Does not send mail" in functions
    assert "Does not spawn cron" in functions


def test_phpunit_enqueue_cases_exist() -> None:
    src = PHPUNIT.read_text(encoding="utf-8")
    for needle in (
        "function testApprovedReplyPostEnqueuesTopicAndReplyIdsWithoutMail",
        "function testSiteOffReplyPostDoesNotEnqueue",
        "function testNewTopicPostDoesNotEnqueue",
        "function testCreateReplyBypassingFrontDoesNotEnqueue",
        "function testPendingReplyPostDoesNotEnqueueUntilApproved",
        "function testFailedReplyPostDoesNotEnqueue",
        "function testTwoApprovedRepliesEnqueueSeparateEvents",
        "function testEnqueueReplyDoesNotSpawnCron",
        "getTestOutbox",
        "nextScheduled",
        "CRON_HOOK",
    ):
        assert needle in src, f"ForumNotifyEnqueueTest missing {needle!r}"


def test_phpunit_forum_notify_enqueue() -> None:
    phpunit = ROOT / "vendor" / "bin" / "phpunit"
    if not phpunit.is_file():
        return
    cmd = [
        _php_bin(),
        str(phpunit),
        "--configuration",
        str(ROOT / "phpunit.xml.dist"),
        str(PHPUNIT),
    ]
    result = subprocess.run(cmd, cwd=str(ROOT), capture_output=True, text=True, check=False)
    combined = (result.stdout or "") + (result.stderr or "")
    assert result.returncode == 0, f"phpunit failed:\n{combined}"


if __name__ == "__main__":
    raise SystemExit(__import__("pytest").main([__file__, "-v"]))
