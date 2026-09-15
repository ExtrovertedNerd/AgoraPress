"""
SPEC-minimum front report tests: one open report; guest cannot;
duplicate open report refused.

Runnable via:
  pytest tests/test_forum_report_post.py -v
"""

from __future__ import annotations

import shutil
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PHPUNIT = ROOT / "tests" / "Forum" / "ForumReportPostTest.php"
MOD_CLASS = ROOT / "ap-includes" / "class-ap-forum-moderation.php"
FRONT = ROOT / "ap-includes" / "class-ap-forum-front.php"
FUNCTIONS = ROOT / "ap-includes" / "functions.php"
TOPIC = ROOT / "ap-content" / "themes" / "agora" / "topic.php"


def _php_bin() -> str:
    return shutil.which("php") or "php"


def test_phpunit_covers_report_spec_cases() -> None:
    src = PHPUNIT.read_text(encoding="utf-8")
    for needle in (
        "function testLoggedInMemberCreatesOneOpenPostReport",
        "function testGuestCannotReportPost",
        "function testDuplicateOpenReportRefused",
        "function testTwoUsersMayEachHaveOneOpenReportOnSamePost",
        "function testReportPostInsertIgnoresCraftedTypeAndStatus",
        "function testReasonRequired",
        "function testFailedInsertDoesNotClaimSuccess",
        "Could not submit the report.",
        "ForumReportInsertFailsDb",
        "ForumReportLastInsertIdZeroDb",
        "function testReportFloodGuard",
        "function testFloodOffAllowsRapidReportsOnDifferentPosts",
        "function testMissingViewForumRefused",
        "function testTopicViewShowsReportForLoggedInNotGuest",
        "function testOpenReportHidesFormForReporterNotOtherPostsOrUsers",
        "function testReportPostFormHtmlRequiresPostIdAndReason",
        "function rawReportRow",
        "ap_reports",
        "REPORT_STATUS_OPEN",
        "REPORT_TYPE_POST",
        "hasOpenReport",
        "isReportFlooding",
        "ap_forum_notice=post_reported",
        "You have already reported this post.",
        "You must be logged in to report posts.",
        "Please provide a reason for this report.",
    ):
        assert needle in src, f"Expected {needle!r} in ForumReportPostTest.php"


def test_report_post_inserts_open_post_row_and_guards() -> None:
    mod = MOD_CLASS.read_text(encoding="utf-8")
    start = mod.index("public static function createReport")
    end = mod.index("public static function getReport")
    body = mod[start:end]
    assert "REPORT_STATUS_OPEN" in body
    assert "hasOpenReport" in body
    assert "$reporterId < 1" in body
    assert "$reason === ''" in body
    assert "report_type" in body
    assert "$inserted === false" in body
    assert "(int) $inserted < 1" in body
    assert "lastInsertId" in body
    assert "ap_report_created" in body
    assert "function hasOpenReport" in mod
    assert "function openReportObjectIdSet" in mod
    assert "function isReportFlooding" in mod
    assert "function getLastReportTime" in mod

    front = FRONT.read_text(encoding="utf-8")
    assert "ACTION_REPORT_POST" in front
    assert "ap_forum_report_post" in front
    assert "function handleReportPost" in front
    assert "createReport" in front
    assert "REPORT_TYPE_POST" in front
    assert "REPORT_STATUS_OPEN" in front
    assert "post_reported" in front
    assert "isReportFlooding" in front
    assert "hasOpenReport" in front
    assert "You are reporting too quickly." in front
    assert "userCanViewForum" in front
    assert "Could not submit the report." in front
    assert "You must be logged in to report posts." in front
    assert "Please provide a reason for this report." in front
    handle = front.split("function handleReportPost", 1)[1].split(
        "function postIdsFromRequest", 1
    )[0]
    assert "$reportId < 1" in handle
    assert "getReport" in handle
    assert "ap_forum_notice=post_reported" in handle
    assert "Could not submit the report." in handle
    assert "AP_Forum_Moderation_Queue" not in handle
    assert "forum-moderation.php" not in handle
    assert "forum-reports.php" not in handle


def test_front_report_does_not_rebuild_acp_queue() -> None:
    """ACP Moderation already lists {prefix}reports — do not add a second queue."""
    assert not (ROOT / "ap-admin" / "forum-reports.php").exists()
    queue = (
        ROOT / "ap-admin" / "includes" / "class-ap-forum-moderation-queue.php"
    ).read_text(encoding="utf-8")
    assert "function queryReports" not in queue
    assert "AP_Forum_Moderation::queryReports" in queue
    assert "resolve_report" in queue
    assert "dismiss_report" in queue
    assert "reopen_report" in queue
    page = (ROOT / "ap-admin" / "forum-moderation.php").read_text(encoding="utf-8")
    assert "AP_Forum_Moderation_Queue" in page
    assert "'view' => 'reports'" in queue


def test_topic_view_report_form_logged_in_reason_required() -> None:
    topic = TOPIC.read_text(encoding="utf-8")
    functions = FUNCTIONS.read_text(encoding="utf-8")
    assert "can_report" in topic
    assert "ap_forum_report_post_form_html" in topic
    assert 'name="report_reason"' in topic
    assert "ap_forum_report_post" in topic
    assert ">Report</button>" in topic or ">$esc($buttonLabel)" in functions
    assert "function ap_forum_report_post_form_html" in functions
    assert "function ap_forum_is_report_flooding" in functions
    assert "function ap_forum_has_open_report" in functions
    helper = functions.split("function ap_forum_report_post_form_html", 1)[1].split(
        "function ap_forum_row_read_state", 1
    )[0]
    assert "required maxlength=" in helper
    assert "report_reason" in helper
    assert "ACTION_REPORT_POST" in helper or "ap_forum_report_post" in helper


def test_phpunit_forum_report_post_suite_runs() -> None:
    result = subprocess.run(
        [
            _php_bin(),
            "vendor/bin/phpunit",
            "-c",
            "phpunit.xml.dist",
            "tests/Forum/ForumReportPostTest.php",
        ],
        cwd=str(ROOT),
        capture_output=True,
        text=True,
        check=False,
        timeout=120,
    )
    combined = (result.stdout or "") + (result.stderr or "")
    assert result.returncode == 0, f"PHPUnit forum report-post failed:\n{combined}"
