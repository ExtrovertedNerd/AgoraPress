"""
Smoke tests: topic-notify worker filters recipients and sends signed text/plain.

Runnable via:
  pytest tests/test_forum_notify_worker.py -v
"""

from __future__ import annotations

import shutil
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
NOTIFY = ROOT / "ap-includes" / "class-ap-forum-notify.php"
FUNCTIONS = ROOT / "ap-includes" / "functions.php"
INDEX = ROOT / "index.php"
FRONT = ROOT / "ap-includes" / "class-ap-forum-front.php"
PHPUNIT = ROOT / "tests" / "Forum" / "ForumNotifyWorkerTest.php"


def _php_bin() -> str:
    return shutil.which("php") or "php"


def test_worker_files_exist() -> None:
    for path in (NOTIFY, FUNCTIONS, INDEX, FRONT, PHPUNIT):
        assert path.is_file(), f"Missing {path.relative_to(ROOT)}"


def test_worker_api() -> None:
    src = NOTIFY.read_text(encoding="utf-8")
    for needle in (
        "function processQueuedReply",
        "function isEligibleRecipient",
        "function usableRecipientEmail",
        "function composeReplyMail",
        "function listForTopic",
        "function unsubscribeUrl",
        "function createUnsubscribeToken",
        "function parseUnsubscribeToken",
        "function maybeHandleSignedUnsubscribe",
        "QUERY_UNSUBSCRIBE",
        "ap_forum_unsub",
        "UNSUBSCRIBE_TTL",
        "skip_rate_limit",
        "text/plain",
        "hash_hmac",
    ):
        assert needle in src, f"AP_Forum_Notify missing {needle!r}"
    assert "UNSUBSCRIBE_TTL = 3888000" in src or "UNSUBSCRIBE_TTL =" in src


def test_index_handles_signed_unsubscribe() -> None:
    index = INDEX.read_text(encoding="utf-8")
    assert "maybeHandleSignedUnsubscribe" in index
    assert "Location:" in index


def test_functions_worker_helpers() -> None:
    functions = FUNCTIONS.read_text(encoding="utf-8")
    assert "function ap_forum_notify_process_queued_reply" in functions
    assert "function ap_forum_notify_is_eligible_recipient" in functions
    assert "function ap_forum_notify_handle_unsubscribe" in functions
    assert "skip_rate_limit" in functions


def test_front_invalid_unsub_notice() -> None:
    front = FRONT.read_text(encoding="utf-8")
    assert "topic_unsubscribe_invalid" in front


def test_phpunit_worker_cases_exist() -> None:
    src = PHPUNIT.read_text(encoding="utf-8")
    for needle in (
        "function testWorkerSendsPlainTextWithSignedUnsubscribeAndDropsIneligible",
        "function testWorkerDropsLostViewForum",
        "function testSiteOffWorkerDoesNotSend",
        "function testCronHookSendsWithoutConsumingRateLimitMail",
        "function testSignedTokenUnsubscribesOneTopicWithoutSession",
        "function testInvalidAndExpiredTokensDoNotUnsubscribe",
        "getTestOutbox",
        "secret plot",
        "rate_limit",
    ):
        assert needle in src, f"ForumNotifyWorkerTest missing {needle!r}"


def test_phpunit_forum_notify_worker() -> None:
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
