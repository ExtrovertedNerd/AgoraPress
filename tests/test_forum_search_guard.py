"""
Smoke tests for forum search, flood control, anti-spam, and post approval.

Runnable via:
  pytest tests/test_forum_search_guard.py -v
"""

from __future__ import annotations

import shutil
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
GUARD = ROOT / "ap-includes" / "class-ap-forum-guard.php"
FORUM = ROOT / "ap-includes" / "class-ap-forum.php"
FRONT = ROOT / "ap-includes" / "class-ap-forum-front.php"
MOD = ROOT / "ap-includes" / "class-ap-forum-moderation.php"
FUNCTIONS = ROOT / "ap-includes" / "functions.php"
BOOTSTRAP = ROOT / "ap-includes" / "bootstrap.php"
INSTALLER = ROOT / "ap-includes" / "class-ap-installer.php"
REWRITE = ROOT / "ap-includes" / "class-ap-rewrite.php"
AGORA = ROOT / "ap-content" / "themes" / "agora"
PHPUNIT = ROOT / "tests" / "Forum" / "ForumSearchGuardTest.php"


def _php_bin() -> str:
    return shutil.which("php") or "php"


def test_guard_and_search_files_exist() -> None:
    assert GUARD.is_file()
    assert PHPUNIT.is_file()
    assert (AGORA / "forum-search.php").is_file()


def test_guard_class_api() -> None:
    src = GUARD.read_text(encoding="utf-8")
    for needle in (
        "class AP_Forum_Guard",
        "OPTION_FLOOD_INTERVAL",
        "OPTION_REQUIRE_APPROVAL",
        "OPTION_SPAM_BLACKLIST",
        "OPTION_SPAM_MAX_LINKS",
        "OPTION_SEARCH_ENABLED",
        "function isFlooding",
        "function secondsUntilAllowed",
        "function runSpamChecks",
        "function registerSpamChecker",
        "function evaluate",
        "function requiresApproval",
        "function isSearchEnabled",
    ):
        assert needle in src, f"missing {needle}"


def test_forum_search_and_pending_api() -> None:
    src = FORUM.read_text(encoding="utf-8")
    for needle in (
        "function search",
        "function searchTopics",
        "function searchPosts",
        "function searchUrl",
        "function getPendingTopics",
        "function getPendingPosts",
        "function countPendingTopics",
        "function countPendingPosts",
        "check_guard",
        "forumIdsAllowedForSearch",
        "check_permissions",
    ):
        assert needle in src, f"missing {needle} in AP_Forum"


def test_moderation_approve_api() -> None:
    src = MOD.read_text(encoding="utf-8")
    for needle in (
        "function approveTopic",
        "function unapproveTopic",
        "function approvePost",
        "function unapprovePost",
    ):
        assert needle in src, f"missing {needle}"


def test_procedural_helpers_and_bootstrap() -> None:
    functions = FUNCTIONS.read_text(encoding="utf-8")
    for needle in (
        "function ap_forum_search",
        "function ap_forum_search_url",
        "function ap_forum_is_flooding",
        "function ap_forum_flood_retry_after",
        "function ap_forum_guard_evaluate",
        "function ap_register_forum_spam_checker",
        "function ap_get_pending_topics",
        "function ap_get_pending_forum_posts",
        "function ap_approve_topic",
        "function ap_unapprove_topic",
        "function ap_approve_forum_post",
        "function ap_unapprove_forum_post",
    ):
        assert needle in functions, f"missing {needle}"

    boot = BOOTSTRAP.read_text(encoding="utf-8")
    assert "class-ap-forum-guard.php" in boot

    installer = INSTALLER.read_text(encoding="utf-8")
    for opt in (
        "forum_flood_interval",
        "forum_posts_require_approval",
        "forum_spam_blacklist",
        "forum_spam_max_links",
        "forum_search_enabled",
    ):
        assert opt in installer, f"installer should seed {opt}"


def test_rewrite_and_front_search() -> None:
    rewrite = REWRITE.read_text(encoding="utf-8")
    assert "forums/search" in rewrite
    assert "ap_forum_view=search" in rewrite
    assert "forum_s" in rewrite

    front = FRONT.read_text(encoding="utf-8")
    assert "check_guard" in front
    assert "topic_pending" in front
    assert "reply_pending" in front
    assert "function searchForQuery" in front
    assert "forum_search_results" in front

    search_tpl = (AGORA / "forum-search.php").read_text(encoding="utf-8")
    assert "forum_s" in search_tpl
    assert "ap-forum-search" in search_tpl

    style = (AGORA / "style.css").read_text(encoding="utf-8")
    assert "ap-forum-search-results" in style
    assert "ap-forum-search-result__snippet" in style


def test_phpunit_forum_search_guard_passes() -> None:
    cmd = [
        _php_bin(),
        str(ROOT / "vendor" / "bin" / "phpunit"),
        "--configuration",
        str(ROOT / "phpunit.xml.dist"),
        str(PHPUNIT),
    ]
    result = subprocess.run(cmd, cwd=str(ROOT), capture_output=True, text=True, timeout=120)
    assert result.returncode == 0, result.stdout + "\n" + result.stderr
