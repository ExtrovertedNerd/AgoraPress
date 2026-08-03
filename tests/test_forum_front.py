"""
Smoke tests for forum front-end templates, rewrite routes, and form handlers.

Runnable via:
  pytest tests/test_forum_front.py -v
"""

from __future__ import annotations

import shutil
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
REWRITE = ROOT / "ap-includes" / "class-ap-rewrite.php"
FORUM = ROOT / "ap-includes" / "class-ap-forum.php"
FRONT = ROOT / "ap-includes" / "class-ap-forum-front.php"
FUNCTIONS = ROOT / "ap-includes" / "functions.php"
BOOTSTRAP = ROOT / "ap-includes" / "bootstrap.php"
INDEX = ROOT / "index.php"
AGORA = ROOT / "ap-content" / "themes" / "agora"
PHPUNIT = ROOT / "tests" / "Forum" / "ForumFrontTest.php"


def _php_bin() -> str:
    return shutil.which("php") or "php"


def test_forum_front_files_exist() -> None:
    assert FRONT.is_file()
    assert PHPUNIT.is_file()
    for name in ("forum.php", "forum-view.php", "topic.php"):
        assert (AGORA / name).is_file(), f"missing {name}"


def test_rewrite_contains_forum_routes() -> None:
    src = REWRITE.read_text(encoding="utf-8")
    assert "forums/?$" in src
    assert "ap_forum_view=index" in src
    assert "forum_slug" in src
    assert "topic_slug" in src
    assert "AP_Forum_Front::enrichQueryArgs" in src


def test_forum_front_class_api() -> None:
    src = FRONT.read_text(encoding="utf-8")
    for needle in (
        "class AP_Forum_Front",
        "function enrichQueryArgs",
        "function handlePost",
        "function applyToQuery",
        "ACTION_NEW_TOPIC",
        "ACTION_REPLY",
    ):
        assert needle in src, f"missing {needle}"


def test_forum_url_helpers_and_bootstrap() -> None:
    forum = FORUM.read_text(encoding="utf-8")
    assert "function forumsIndexUrl" in forum
    functions = FUNCTIONS.read_text(encoding="utf-8")
    assert "function ap_forums_url" in functions
    assert "function ap_handle_forum_front_post" in functions
    boot = BOOTSTRAP.read_text(encoding="utf-8")
    assert "class-ap-forum-front.php" in boot
    index = INDEX.read_text(encoding="utf-8")
    assert "AP_Forum_Front" in index
    assert "handlePost" in index


def test_agora_templates_have_live_forms() -> None:
    view = (AGORA / "forum-view.php").read_text(encoding="utf-8")
    topic = (AGORA / "topic.php").read_text(encoding="utf-8")
    assert 'name="ap_forum_action"' in view
    assert "ap_forum_new_topic" in view
    assert 'name="topic_title"' in view
    assert 'name="ap_forum_action"' in topic
    assert "ap_forum_reply" in topic
    assert "ap-forum-attachments" in topic
    style = (AGORA / "style.css").read_text(encoding="utf-8")
    assert ".ap-forum-notice" in style


def test_phpunit_forum_front_passes() -> None:
    cmd = [
        _php_bin(),
        str(ROOT / "vendor" / "bin" / "phpunit"),
        "--configuration",
        str(ROOT / "phpunit.xml.dist"),
        str(PHPUNIT),
    ]
    result = subprocess.run(cmd, cwd=str(ROOT), capture_output=True, text=True, timeout=120)
    assert result.returncode == 0, result.stdout + "\n" + result.stderr
