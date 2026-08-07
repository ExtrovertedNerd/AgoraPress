"""
Smoke tests for Phase 1 board helpers (stats, row payload, read markers).

Runnable via:
  pytest tests/test_forum_board_helpers.py -v
"""

from __future__ import annotations

import shutil
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
FORUM_CLASS = ROOT / "ap-includes" / "class-ap-forum.php"
STATS_CLASS = ROOT / "ap-includes" / "class-ap-forum-stats.php"
READ_CLASS = ROOT / "ap-includes" / "class-ap-forum-read.php"
FUNCTIONS = ROOT / "ap-includes" / "functions.php"
MIGRATIONS = ROOT / "ap-includes" / "schema" / "migrations"


def _php_bin() -> str:
    return shutil.which("php") or "php"


def test_phase1_migration_and_helper_files_exist() -> None:
    assert (MIGRATIONS / "0011_forum_likes_stats.php").is_file()
    assert (MIGRATIONS / "0012_topic_type_enum.php").is_file()
    assert FORUM_CLASS.is_file()
    assert STATS_CLASS.is_file()
    assert READ_CLASS.is_file()


def test_forum_stats_board_api_surface() -> None:
    src = STATS_CLASS.read_text(encoding="utf-8")
    for needle in (
        "class AP_Forum_Stats",
        "function getBoardStats",
        "function getTotalTopics",
        "function getTotalPosts",
        "function getTotalMembers",
        "function getBoardStatsFromForumCounters",
        "function emptyBoardStats",
        "function getAuthorPanelStats",
        "function getAuthorPanelStatsForUsers",
        "function queryLikesGiven",
        "function queryLikesReceived",
        "Total Topics",
        "Total Posts",
        "Total Members",
    ):
        assert needle in src, f"Expected {needle!r} in class-ap-forum-stats.php"


def test_forum_row_and_icon_helpers_surface() -> None:
    src = FORUM_CLASS.read_text(encoding="utf-8")
    for needle in (
        "function forumToDisplayRow",
        "function topicToDisplayRow",
        "function buildForumLastPostPayload",
        "function buildForumRowPreload",
        "function getAuthorDisplayNames",
        "function getTopicsByIds",
        "function forumIconType",
        "function topicIconType",
        "function isForumClosed",
        "function getIndexData",
        "no N+1",
        "function buildQuoteMarkup",
        "function getQuoteMarkupForPost",
        "can_quote",
        "icon_type",
        "is_unread",
        "is_closed",
        "is_empty",
        "last_post",
    ):
        assert needle in src, f"Expected {needle!r} in class-ap-forum.php"


def test_quote_helpers_in_functions_php() -> None:
    src = FUNCTIONS.read_text(encoding="utf-8")
    assert "function ap_forum_build_quote_markup" in src
    assert "function ap_forum_quote_for_post" in src


def test_read_marker_helpers_surface() -> None:
    src = READ_CLASS.read_text(encoding="utf-8")
    for needle in (
        "class AP_Forum_Read",
        "function markTopicRead",
        "function isTopicUnread",
        "function isForumUnread",
        "function getFirstUnreadPost",
        "function getFirstUnreadPostId",
        "function annotateForums",
        "function annotateTopics",
        "getForumTopicCandidatesBulk",
        "getForumLastPostTimesBulk",
        "Board-index hot path",
    ):
        assert needle in src, f"Expected {needle!r} in class-ap-forum-read.php"


def test_functions_expose_display_helpers() -> None:
    src = FUNCTIONS.read_text(encoding="utf-8")
    for needle in (
        "function ap_forum_to_display_row",
        "function ap_forum_icon_type",
        "function ap_topic_icon_type",
        "function ap_forum_row_icon_html",
        "function ap_forum_row_icon_types",
        "function ap_forum_normalize_icon_type",
        "function ap_forum_icon_type_label",
        "function ap_forum_row_read_state",
        "function ap_forum_row_classes",
        "function ap_is_forum_closed",
        "function ap_forum_empty_last_post_html",
        "function ap_forum_empty_state_html",
        "function ap_get_forum_board_stats",
        "function ap_forum_board_stats_footer_html",
        "ap-forum-footer",
        "ap-board-stats",
        "Total Topics:",
        "Total Posts:",
        "Total Members:",
        "ap-forum-row--unread",
        "ap-forum-row--read",
        "ap-forum-row--neutral",
        "ap-forum-row--locked",
        "ap-forum-row--empty",
        "ap-forum-empty",
        "ap-forum-icon--read",
        "function ap_forum_post_url",
        "function ap_get_first_unread_post",
        "function ap_get_first_unread_post_id",
        "function ap_forum_first_unread_link_html",
        "function ap_is_topic_unread",
        "function ap_is_forum_unread",
        "function ap_annotate_forums_unread",
        "function ap_get_forum_index_data",
    ):
        assert needle in src, f"Expected {needle!r} in functions.php"


def test_phpunit_board_helpers_suite_runs() -> None:
    result = subprocess.run(
        [
            _php_bin(),
            "vendor/bin/phpunit",
            "-c",
            "phpunit.xml.dist",
            "tests/Forum/ForumBoardHelpersTest.php",
            "tests/Database/TopicTypeEnumMigrationTest.php",
        ],
        cwd=str(ROOT),
        capture_output=True,
        text=True,
        check=False,
    )
    combined = (result.stdout or "") + (result.stderr or "")
    assert result.returncode == 0, f"PHPUnit board helpers failed:\n{combined}"


if __name__ == "__main__":
    raise SystemExit(__import__("pytest").main([__file__, "-v"]))
