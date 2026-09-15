"""
SPEC-minimum split-topic tests: selected posts on new topic; original
keeps ≥1; last-post correct.

Runnable via:
  pytest tests/test_forum_split_topic.py -v
"""

from __future__ import annotations

import shutil
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PHPUNIT = ROOT / "tests" / "Forum" / "ForumSplitTopicTest.php"
MOD_CLASS = ROOT / "ap-includes" / "class-ap-forum-moderation.php"
FRONT = ROOT / "ap-includes" / "class-ap-forum-front.php"


def _php_bin() -> str:
    return shutil.which("php") or "php"


def test_phpunit_covers_split_spec_cases() -> None:
    src = PHPUNIT.read_text(encoding="utf-8")
    for needle in (
        "function testSplitTopicMovesSelectedPostsOntoNewTopic",
        "function testSplitTopicKeepsAtLeastOnePostOnOriginal",
        "function testSplitTopicRefreshesLastPost",
        "getPosts",
        "first_post_id",
        "last_post_id",
        "TOPIC_STATUS_MOVED",
        "last_topic_id",
        "last_poster_id",
        "EMPTY_DATETIME",
        "forumToDisplayRow",
        "assertLastPostDoesNotPointAt",
        "keeps at least one",
    ):
        assert needle in src, f"Expected {needle!r} in ForumSplitTopicTest.php"


def test_split_topic_refreshes_last_post_and_leaves_one() -> None:
    mod = MOD_CLASS.read_text(encoding="utf-8")
    start = mod.index("public static function splitTopic")
    end = mod.index("// Post moderation")
    body = mod[start:end]
    assert "Must leave at least one post in the original topic" in body
    assert "refreshForumLastPost($sourceForum, $db)" in body
    assert "refreshForumLastPost($newForumId, $db)" in body
    assert "moderator_id" in body
    assert "TOPIC_STATUS_MOVED" not in body
    assert "function userCanSplitTopic" in mod
    assert "function listSplitDestinations" in mod

    front = FRONT.read_text(encoding="utf-8")
    assert "ACTION_SPLIT_TOPIC" in front
    assert "function handleSplitTopic" in front
    assert "splitTopic($topicId, $postIds" in front
    assert "'moderator_id' => $userId" in front
    assert "topic_split" in front
    assert "can_split_topic" in front
    assert "split_destinations" in front


def test_topic_view_split_form_has_checkboxes_title_and_optional_dest() -> None:
    topic = (ROOT / "ap-content" / "themes" / "agora" / "topic.php").read_text(encoding="utf-8")
    functions = (ROOT / "ap-includes" / "functions.php").read_text(encoding="utf-8")
    assert 'name="post_ids[]"' in topic
    assert "form=" in topic and "agora-split-topic-form" in topic
    assert "$topicPostCount >= 2" in topic
    assert "$splitPostCount >= 1" in topic
    assert "Leave at least one" in topic or "at least one post must remain" in topic.lower()
    assert 'href="#split"' in topic
    assert "ap_forum_split_topic_form_html" in topic
    assert "function ap_forum_split_topic_form_html" in functions
    helper = functions.split("function ap_forum_split_topic_form_html", 1)[1].split(
        "function ap_forum_row_read_state", 1
    )[0]
    assert "topic_title" in helper
    assert "dest_forum_id" in helper
    assert "$onlyCurrent" in helper
    assert "required maxlength=" in helper


def test_phpunit_forum_split_topic_suite_runs() -> None:
    result = subprocess.run(
        [
            _php_bin(),
            "vendor/bin/phpunit",
            "-c",
            "phpunit.xml.dist",
            "tests/Forum/ForumSplitTopicTest.php",
        ],
        cwd=str(ROOT),
        capture_output=True,
        text=True,
        check=False,
        timeout=120,
    )
    combined = (result.stdout or "") + (result.stderr or "")
    assert result.returncode == 0, f"PHPUnit forum split-topic failed:\n{combined}"
