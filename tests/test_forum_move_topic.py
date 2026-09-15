"""
SPEC-minimum move-topic tests: dest counters; category refused;
missing dest cap refused; slug unchanged.

Runnable via:
  pytest tests/test_forum_move_topic.py -v
"""

from __future__ import annotations

import shutil
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PHPUNIT = ROOT / "tests" / "Forum" / "ForumMoveTopicTest.php"
MOD_CLASS = ROOT / "ap-includes" / "class-ap-forum-moderation.php"
FORUM_CLASS = ROOT / "ap-includes" / "class-ap-forum.php"


def _php_bin() -> str:
    return shutil.which("php") or "php"


def test_phpunit_covers_move_spec_cases() -> None:
    src = PHPUNIT.read_text(encoding="utf-8")
    for needle in (
        "function testMoveTopicUpdatesDestCounters",
        "function testMoveTopicRefusesCategory",
        "function testMoveTopicRefusesMissingDestCap",
        "function testMoveTopicKeepsSlugUnchanged",
        "topic_count",
        "post_count",
        "last_topic_id",
        "last_post_id",
        "last_poster_id",
        "FORUM_TYPE_CATEGORY",
        "PERM_MODERATE",
        "userCanModerate",
        "topic_slug",
        "keep-this-slug",
        "TOPIC_STATUS_MOVED",
    ):
        assert needle in src, f"Expected {needle!r} in ForumMoveTopicTest.php"


def test_move_topic_keeps_slug_and_refuses_category() -> None:
    mod = MOD_CLASS.read_text(encoding="utf-8")
    start = mod.index("public static function moveTopic")
    end = mod.index("public static function userCanMoveTopic")
    body = mod[start:end]
    assert "FORUM_TYPE_CATEGORY" in body
    assert "moderatorMayAct($moderatorId, $oldForumId" in body
    assert "moderatorMayAct($moderatorId, $newForumId" in body
    assert "adjustForumStats($oldForumId, -1, -$postCount, $db)" in body
    assert "adjustForumStats($newForumId, 1, $postCount, $db)" in body
    assert "refreshForumLastPost($oldForumId, $db)" in body
    assert "refreshForumLastPost($newForumId, $db)" in body

    forum = FORUM_CLASS.read_text(encoding="utf-8")
    update_start = forum.index("public static function updateTopic")
    update_end = forum.index("public static function deleteTopic")
    update = forum[update_start:update_end]
    assert "keeps the existing slug" in update
    assert "uniqueTopicSlug" in update
    assert "isset($update['topic_title'])" in update


def test_phpunit_forum_move_topic_suite_runs() -> None:
    result = subprocess.run(
        [
            _php_bin(),
            "vendor/bin/phpunit",
            "-c",
            "phpunit.xml.dist",
            "tests/Forum/ForumMoveTopicTest.php",
        ],
        cwd=str(ROOT),
        capture_output=True,
        text=True,
        check=False,
    )
    combined = (result.stdout or "") + (result.stderr or "")
    assert result.returncode == 0, f"PHPUnit forum move-topic failed:\n{combined}"
