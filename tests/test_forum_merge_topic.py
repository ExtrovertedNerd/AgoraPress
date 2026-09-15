"""
SPEC-minimum merge-topic tests: posts on target; source gone;
subs retargeted; last-post correct.

Runnable via:
  pytest tests/test_forum_merge_topic.py -v
"""

from __future__ import annotations

import shutil
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PHPUNIT = ROOT / "tests" / "Forum" / "ForumMergeTopicTest.php"
MOD_CLASS = ROOT / "ap-includes" / "class-ap-forum-moderation.php"


def _php_bin() -> str:
    return shutil.which("php") or "php"


def test_phpunit_covers_merge_spec_cases() -> None:
    src = PHPUNIT.read_text(encoding="utf-8")
    for needle in (
        "function testMergeTopicsMovesPostsOntoTarget",
        "function testMergeTopicsRemovesSourceTopic",
        "function testMergeTopicsRetargetsSubscriptions",
        "function testMergeTopicsRefreshesLastPost",
        "getPosts",
        "first_post_id",
        "last_post_id",
        "TOPIC_STATUS_MOVED",
        "isSubscribed",
        "topic_subscriptions",
        "last_topic_id",
        "last_poster_id",
        "EMPTY_DATETIME",
        "forumToDisplayRow",
        "assertLastPostDoesNotPointAt",
        "assertEmptyLastPost",
    ):
        assert needle in src, f"Expected {needle!r} in ForumMergeTopicTest.php"


def test_merge_topics_retargets_subs_and_refreshes_last_post() -> None:
    mod = MOD_CLASS.read_text(encoding="utf-8")
    start = mod.index("public static function mergeTopics")
    end = mod.index("public static function retargetTopicSubscriptions")
    body = mod[start:end]
    assert "retargetTopicSubscriptions($sourceTopicId, $targetTopicId, $db)" in body
    assert "delete('topics', ['topic_id' => $sourceTopicId])" in body
    assert "refreshForumLastPost($sourceForum, $db)" in body
    assert "refreshForumLastPost($targetForum, $db)" in body
    assert "TOPIC_STATUS_MOVED" not in body
    assert "insert('topics'" not in body

    retarget_start = mod.index("public static function retargetTopicSubscriptions")
    retarget_end = mod.index("public static function splitTopic")
    retarget = mod[retarget_start:retarget_end]
    assert "topic_subscriptions" in retarget
    assert "['topic_id' => $targetTopicId]" in retarget
    assert "delete('topic_subscriptions', ['topic_id' => $sourceTopicId])" in retarget


def test_phpunit_forum_merge_topic_suite_runs() -> None:
    result = subprocess.run(
        [
            _php_bin(),
            "vendor/bin/phpunit",
            "-c",
            "phpunit.xml.dist",
            "tests/Forum/ForumMergeTopicTest.php",
        ],
        cwd=str(ROOT),
        capture_output=True,
        text=True,
        check=False,
    )
    combined = (result.stdout or "") + (result.stderr or "")
    assert result.returncode == 0, f"PHPUnit forum merge-topic failed:\n{combined}"
