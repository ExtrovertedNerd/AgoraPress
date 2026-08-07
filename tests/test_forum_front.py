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
        "markTopicReadOnView",
        "ACTION_NEW_TOPIC",
        "ACTION_REPLY",
        "ACTION_SET_TOPIC_TYPE",
        "handleSetTopicType",
        "allowed_topic_types",
        "topic_type",
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
    assert "ap_forum_topic_type_select_html" in view
    assert "allowed_topic_types" in view
    assert 'name="ap_forum_action"' in topic
    assert "ap_forum_reply" in topic
    assert "ap-forum-attachments" in topic
    assert "ap_forum_set_topic_type" in topic
    assert "can_set_topic_type" in topic
    # SPEC B2 actions: Quote → Edit/mod → Like/Unlike
    assert "ap-forum-quote" in topic
    assert "ap_forum_like_post" in topic
    assert "ap_forum_edit_post" in topic
    assert "ap_forum_delete_post" in topic
    assert "quoteMarkup" in topic or "quote_markup" in topic or "$quoteMarkup" in topic
    assert 'name="reply_body"' in topic
    style = (AGORA / "style.css").read_text(encoding="utf-8")
    assert ".ap-forum-notice" in style
    assert ".ap-field--topic-type" in style
    assert ".ap-forum-post__actions" in style


def test_board_index_category_header_columns() -> None:
    """SPEC A3: category header is Title | Topics | Posts | Last Post labels."""
    forum = (AGORA / "forum.php").read_text(encoding="utf-8")
    for needle in (
        "ap-forum-cat-header",
        "ap-forum-cat-header__title",
        "ap-forum-cat-header__topics",
        "ap-forum-cat-header__posts",
        "ap-forum-cat-header__last",
        ">Title<",
        ">Topics<",
        ">Posts<",
        ">Last Post<",
    ):
        assert needle in forum, f"missing {needle!r} in forum.php"
    style = (AGORA / "style.css").read_text(encoding="utf-8")
    assert ".ap-forum-cat-header" in style
    assert "grid-column: 1 / span 2" in style


def test_board_index_footer_stats() -> None:
    """SPEC §C: board index footer Total Topics · Total Posts · Total Members."""
    forum = (AGORA / "forum.php").read_text(encoding="utf-8")
    style = (AGORA / "style.css").read_text(encoding="utf-8")
    functions = FUNCTIONS.read_text(encoding="utf-8")
    for needle in (
        "ap_forum_board_stats_footer_html",
        "ap-forum-footer",
        "ap-board-stats",
        "Total Topics",
        "Total Posts",
        "Total Members",
    ):
        assert needle in forum, f"missing {needle!r} in forum.php"
    for needle in (
        "function ap_get_forum_board_stats",
        "function ap_forum_board_stats_footer_html",
        "opening posts + replies",
        "ap-board-stats__label",
        "ap-board-stats__value",
        "ap-board-stats__sep",
    ):
        assert needle in functions, f"missing {needle!r} in functions.php"
    for needle in (
        ".ap-forum-footer",
        ".ap-board-stats",
        ".ap-board-stats__item",
        ".ap-board-stats__label",
        ".ap-board-stats__value",
        ".ap-board-stats__sep",
    ):
        assert needle in style, f"missing {needle!r} in style.css"


def test_board_forum_topic_rows_five_columns() -> None:
    """SPEC A4: Icon | Title | Topics | Posts | Last Post (3-line last post)."""
    forum = (AGORA / "forum.php").read_text(encoding="utf-8")
    view = (AGORA / "forum-view.php").read_text(encoding="utf-8")
    style = (AGORA / "style.css").read_text(encoding="utf-8")
    for src, label in ((forum, "forum.php"), (view, "forum-view.php")):
        for needle in (
            "ap-forum-row",
            "ap-forum-row__icon",
            "ap-forum-row__title",
            "ap-forum-row__topics",
            "ap-forum-row__posts",
            "ap-forum-row__last",
            "ap-forum-last-post",
            "ap-forum-last-post__title",
            "ap-forum-last-post__author",
            "ap-forum-last-post__time",
            "ap_forum_row_icon_html",
        ):
            assert needle in src, f"missing {needle!r} in {label}"
    # Topic lists: Topics N/A (em dash), posts = replies + 1.
    assert "Topics not applicable" in view
    assert "replies + 1" in view or "$replies + 1" in view
    for needle in (
        ".ap-forum-icon",
        ".ap-forum-icon--sticky",
        ".ap-forum-icon--locked",
        ".ap-forum-last-post__title",
        ".ap-forum-last-post__author",
        ".ap-forum-last-post__time",
        "--ap-forum-icon-col",
        "--ap-forum-stat-col",
        "--ap-forum-last-col",
        "minmax(0, 1fr)",
    ):
        assert needle in style, f"missing {needle!r} in style.css"


def test_empty_states_and_locked_forum_affordances() -> None:
    """Empty forums + closed/locked visual affordances (board index + forum view)."""
    forum = (AGORA / "forum.php").read_text(encoding="utf-8")
    view = (AGORA / "forum-view.php").read_text(encoding="utf-8")
    topic = (AGORA / "topic.php").read_text(encoding="utf-8")
    style = (AGORA / "style.css").read_text(encoding="utf-8")
    functions = FUNCTIONS.read_text(encoding="utf-8")

    for needle in (
        "function ap_forum_empty_state_html",
        "function ap_forum_empty_last_post_html",
        "function ap_is_forum_closed",
        "ap-forum-row--locked",
        "ap-forum-row--empty",
        "forum_empty_closed",
        "No posts",
    ):
        assert needle in functions, f"missing {needle!r} in functions.php"

    for needle in (
        "ap_forum_empty_state_html",
        "ap-forum-row--locked",
        "ap-forum-row--empty",
        "ap-badge--locked",
        "ap_forum_empty_last_post_html",
        "is_closed",
        "is_empty",
    ):
        assert needle in forum, f"missing {needle!r} in forum.php"

    for needle in (
        "ap_forum_empty_state_html",
        "forum_empty_closed",
        "forum_empty",
        "ap-forum__header--locked",
        "ap-badge--locked",
        "ap-forum-row--locked",
        "'locked' => $locked",
        "Start the first topic",
    ):
        assert needle in view, f"missing {needle!r} in forum-view.php"

    for needle in (
        "ap_forum_empty_state_html",
        "topic_locked",
        "topic_empty",
        "first_unread_post_id",
        "ap_forum_first_unread_link_html",
        "ap-forum-first-unread",
        "First unread post",
    ):
        assert needle in topic, f"missing {needle!r} in topic.php"

    for needle in (
        ".ap-forum-row--locked",
        ".ap-forum-row--empty",
        ".ap-forum-empty",
        ".ap-forum-empty--forum_closed",
        ".ap-forum-empty--inset",
        ".ap-forum-last-post--empty",
        ".ap-forum__header--locked",
    ):
        assert needle in style, f"missing {needle!r} in style.css"


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
