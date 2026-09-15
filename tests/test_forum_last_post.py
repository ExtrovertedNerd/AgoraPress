"""
Smoke tests: deleteTopic always recounts the source forum last-post.

Runnable via:
  pytest tests/test_forum_last_post.py -v
"""

from __future__ import annotations

import shutil
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
FORUM_CLASS = ROOT / "ap-includes" / "class-ap-forum.php"
MOD_CLASS = ROOT / "ap-includes" / "class-ap-forum-moderation.php"


def _php_bin() -> str:
    return shutil.which("php") or "php"


def test_delete_topic_always_refreshes_source_forum_last_post() -> None:
    src = FORUM_CLASS.read_text(encoding="utf-8")
    start = src.index("public static function deleteTopic")
    end = src.index("public static function incrementTopicViews")
    body = src[start:end]
    soft, force = body.split("// Force:", 1)

    assert "self::refreshForumLastPost($forumId, $db);" in soft
    assert "self::refreshForumLastPost($forumId, $db);" in force
    assert soft.rindex("self::refreshForumLastPost") > soft.rindex("if ($wasApproved)")
    assert force.rindex("self::refreshForumLastPost") > force.rindex("if ($wasApproved)")
    assert "TOPIC_STATUS_DELETED" in soft
    assert "self::refreshForumLastPost($forumId, $db);" in soft.split("TOPIC_STATUS_DELETED", 1)[1]


def test_forum_refresh_helper_is_public_and_skips_deleted() -> None:
    src = FORUM_CLASS.read_text(encoding="utf-8")
    start = src.index("public static function refreshForumLastPost")
    end = src.index("private static function onPostUnapproved")
    body = src[start:end]
    assert "INNER JOIN" in body
    assert "topic_approved" in body
    assert "post_approved" in body
    assert "TOPIC_STATUS_DELETED" in body
    assert "last_topic_id" in body
    assert "last_poster_id" in body
    assert "last_post_time" in body
    assert "last_post_id" in body

    empty_start = body.index("if ($row === null)")
    empty_end = body.index("return;", empty_start)
    empty = body[empty_start:empty_end]
    assert "'last_post_id' => 0" in empty
    assert "'last_topic_id' => 0" in empty
    assert "'last_poster_id' => 0" in empty
    assert "EMPTY_DATETIME" in empty


def test_last_post_payload_heals_stale_deleted_pointer() -> None:
    src = FORUM_CLASS.read_text(encoding="utf-8")
    start = src.index("public static function buildForumLastPostPayload")
    end = src.index("public static function postUrl")
    body = src[start:end]
    assert "TOPIC_STATUS_DELETED" in body
    assert "refreshForumLastPost" in body
    assert "topic_approved" in body
    assert "post_approved" in body
    assert "Topic row missing but id known" not in body
    assert "self::topicUrl($topicId)" not in body
    assert "allowRecount" in body


def test_moderation_delegates_to_forum_refresh_helper() -> None:
    src = MOD_CLASS.read_text(encoding="utf-8")
    start = src.index("private static function refreshForumLastPost")
    end = src.index("private static function normalizeReportRow")
    body = src[start:end]
    assert "AP_Forum::refreshForumLastPost($forumId, $db);" in body
    assert "SELECT p.*" not in body


def test_phpunit_covers_two_topic_delete_last_post_cases() -> None:
    src = (ROOT / "tests" / "Forum" / "ForumLastPostTest.php").read_text(encoding="utf-8")
    for needle in (
        "testSoftDeleteNewestTopicLeavesOlderLastPost",
        "testForceDeleteNewestTopicLeavesOlderLastPost",
        "testSoftDeleteBothTopicsClearsLastPost",
        "testForceDeleteBothTopicsClearsLastPost",
        "testTwoTopicsDeleteNewestThenBothLeavesOlderThenEmptyWithoutDeadPermalink",
        "testTwoTopicsForceDeleteNewestThenBothLeavesOlderThenEmptyWithoutDeadPermalink",
        "assertRemainingLastPostIsNotDeletedTopic",
        "assertLastPostCellHasNoDeadPermalink",
        "ap_forum_empty_last_post_html",
        "No posts",
        "href=",
        "topic_id=",
    ):
        assert needle in src, f"Expected {needle!r} in ForumLastPostTest.php"


def test_phpunit_forum_last_post_suite_runs() -> None:
    result = subprocess.run(
        [
            _php_bin(),
            "vendor/bin/phpunit",
            "-c",
            "phpunit.xml.dist",
            "tests/Forum/ForumLastPostTest.php",
        ],
        cwd=str(ROOT),
        capture_output=True,
        text=True,
        check=False,
    )
    combined = (result.stdout or "") + (result.stderr or "")
    assert result.returncode == 0, f"PHPUnit forum last-post failed:\n{combined}"
