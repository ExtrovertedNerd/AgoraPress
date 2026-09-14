"""
Smoke tests for AgoraPress comments + moderation.

Runnable via:
  pytest tests/test_comments.py -v
"""

from __future__ import annotations

import shutil
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
INCLUDES = ROOT / "ap-includes"
ADMIN = ROOT / "ap-admin"
FUNCTIONS = ROOT / "ap-includes" / "functions.php"
BOOTSTRAP = ROOT / "ap-includes" / "bootstrap.php"
VERSION = ROOT / "ap-includes" / "version.php"
MIGRATIONS = ROOT / "ap-includes" / "schema" / "migrations"
STRUCTURE = ROOT / "tests" / "Structure" / "assert-structure.php"


def _php_bin() -> str:
    return shutil.which("php") or "php"


def test_comment_files_exist() -> None:
    required = [
        INCLUDES / "class-ap-comment.php",
        INCLUDES / "theme-compat" / "comments.php",
        ROOT / "ap-content" / "themes" / "agora" / "comments.php",
        MIGRATIONS / "0004_core_comments_commentmeta.php",
        ADMIN / "edit-comments.php",
        ADMIN / "includes" / "class-ap-comments-list-table.php",
        ROOT / "tests" / "Comment" / "CommentModelTest.php",
        ROOT / "tests" / "Comment" / "CommentsTemplateTest.php",
        ROOT / "tests" / "Database" / "CommentsCommentmetaMigrationTest.php",
        ROOT / "tests" / "Admin" / "AdminCommentsTest.php",
    ]
    for path in required:
        assert path.is_file(), f"Missing {path.relative_to(ROOT)}"


def test_comment_class_api_surface() -> None:
    src = (INCLUDES / "class-ap-comment.php").read_text(encoding="utf-8")
    for needle in (
        "class AP_Comment",
        "STATUS_APPROVED",
        "STATUS_HOLD",
        "STATUS_SPAM",
        "STATUS_TRASH",
        "function insert",
        "function update",
        "function delete",
        "function approve",
        "function unapprove",
        "function spam",
        "function unspam",
        "function trash",
        "function untrash",
        "function query",
        "function getTree",
        "function getByPost",
        "function updateCommentCount",
        "function registerSpamChecker",
        "function runSpamChecks",
        "function countByStatus",
        "function getMeta",
        "function updateMeta",
        "comment_parent",
    ):
        assert needle in src, f"Expected {needle!r} in AP_Comment"


def test_procedural_comment_helpers() -> None:
    src = FUNCTIONS.read_text(encoding="utf-8")
    for fn in (
        "function ap_get_comment",
        "function ap_insert_comment",
        "function ap_update_comment",
        "function ap_trash_comment",
        "function ap_untrash_comment",
        "function ap_delete_comment",
        "function ap_approve_comment",
        "function ap_unapprove_comment",
        "function ap_spam_comment",
        "function ap_unspam_comment",
        "function ap_set_comment_status",
        "function ap_get_comments",
        "function ap_count_comments",
        "function ap_get_post_comments",
        "function ap_get_comment_tree",
        "function ap_update_comment_count",
        "function ap_register_comment_spam_checker",
        "function ap_get_comment_meta",
        "function ap_update_comment_meta",
        "function ap_delete_comment_meta",
    ):
        assert fn in src, f"Expected {fn!r} in functions.php"

    tags = (ROOT / "ap-includes" / "template-tags.php").read_text(encoding="utf-8")
    for fn in (
        "function ap_comments_template",
        "function ap_locate_comments_template",
        "function ap_comments_compat_file",
    ):
        assert fn in tags, f"Expected {fn!r} in template-tags.php"

    content_start = tags.index("function ap_the_content")
    content_end = tags.index("function ap_get_the_excerpt")
    content_fn = tags[content_start:content_end]
    assert "ap_comments_template" not in content_fn

    single = (ROOT / "ap-content" / "themes" / "agora" / "single.php").read_text(
        encoding="utf-8"
    )
    assert single.count("ap_comments_template(") == 1
    assert "ap_comment_action" not in single
    comments = (ROOT / "ap-content" / "themes" / "agora" / "comments.php").read_text(
        encoding="utf-8"
    )
    assert comments.count('value="ap_comment_post"') == 1


def test_compat_comments_fallback_surface() -> None:
    src = (INCLUDES / "theme-compat" / "comments.php").read_text(encoding="utf-8")
    for needle in (
        "ap-comments--compat",
        "AP_Comment::getByPost",
        "Comments are closed",
        "Log in",
        "to leave a comment",
        "Leave a comment",
        'name="ap_comment_action"',
        "ap_comment_post",
        "comment_post_ID",
        "ap_editor(",
        "modeForContext('comment')",
        "comment_registration",
        "ap_handle_comment_form_post",
    ):
        assert needle in src, f"Expected {needle!r} in theme-compat/comments.php"


def test_bootstrap_loads_comment() -> None:
    src = BOOTSTRAP.read_text(encoding="utf-8")
    assert "class-ap-comment.php" in src


def test_db_version_is_at_least_four() -> None:
    src = VERSION.read_text(encoding="utf-8")
    # Comments ship at schema v4; later migrations may bump further.
    assert "AP_DB_VERSION" in src
    import re

    m = re.search(r"define\('AP_DB_VERSION',\s*'(\d+)'\)", src)
    assert m is not None
    assert int(m.group(1)) >= 4


def test_migration_0004_surface() -> None:
    mig = MIGRATIONS / "0004_core_comments_commentmeta.php"
    src = mig.read_text(encoding="utf-8")
    for needle in (
        "AP_Migration_0004_Core_Comments_Commentmeta",
        "comments",
        "commentmeta",
        "comment_ID",
        "comment_post_ID",
        "comment_approved",
        "comment_parent",
        "ENGINE=InnoDB",
        "BIGSERIAL",
        "AUTOINCREMENT",
    ):
        assert needle in src, f"Expected {needle} in 0004 migration"


def test_admin_comments_surface() -> None:
    table = (ADMIN / "includes" / "class-ap-comments-list-table.php").read_text(
        encoding="utf-8"
    )
    for needle in (
        "class AP_Comments_List_Table",
        "function prepareItems",
        "function processBulkAction",
        "function processRowAction",
        "function renderViews",
        "function renderSearchBox",
        "function render",
        "approve",
        "unapprove",
        "spam",
        "trash",
    ):
        assert needle in table, f"Expected {needle!r} in comments list table"

    edit = (ADMIN / "edit-comments.php").read_text(encoding="utf-8")
    assert "AP_Comments_List_Table" in edit
    assert "comment_status" in edit

    menu = (ADMIN / "includes" / "class-ap-admin.php").read_text(encoding="utf-8")
    assert "comments" in menu
    assert "edit-comments.php" in menu
    assert "comment_approved" in menu or "bulk_comment_approved" in menu

    boot = (ADMIN / "admin-bootstrap.php").read_text(encoding="utf-8")
    assert "class-ap-comments-list-table.php" in boot


def test_structure_script_lists_comments() -> None:
    src = STRUCTURE.read_text(encoding="utf-8")
    assert "class-ap-comment.php" in src
    assert "edit-comments.php" in src
    assert "class-ap-comments-list-table.php" in src
    assert "theme-compat/comments.php" in src


# SPEC “Tests (minimum)” for ap_comments_template().
SPEC_COMMENTS_TEMPLATE_PHPUNIT = [
    "testNoThemeFileStillRendersFallbackForm",
    "testNonSingularPrintsEmptyCommentsMarkup",
    "testAgoraSingleRendersExactlyOneCommentForm",
]


def test_phpunit_comments_template_spec_cases_exist() -> None:
    """SPEC: no theme file → fallback form; non-singular → empty; Agora single one form."""
    src = (ROOT / "tests" / "Comment" / "CommentsTemplateTest.php").read_text(
        encoding="utf-8"
    )
    for method in SPEC_COMMENTS_TEMPLATE_PHPUNIT:
        needle = f"function {method}"
        assert needle in src, f"Expected {needle!r} in CommentsTemplateTest.php"


def test_phpunit_comments_suite_runs() -> None:
    """Run comment-related PHPUnit tests when available."""
    phpunit = ROOT / "vendor" / "bin" / "phpunit"
    if not phpunit.is_file():
        return
    proc = subprocess.run(
        [
            _php_bin(),
            str(phpunit),
            "--configuration",
            str(ROOT / "phpunit.xml.dist"),
            str(ROOT / "tests" / "Comment" / "CommentModelTest.php"),
            str(ROOT / "tests" / "Comment" / "CommentsTemplateTest.php"),
            str(ROOT / "tests" / "Database" / "CommentsCommentmetaMigrationTest.php"),
            str(ROOT / "tests" / "Admin" / "AdminCommentsTest.php"),
        ],
        cwd=str(ROOT),
        capture_output=True,
        text=True,
        timeout=120,
    )
    if proc.returncode != 0:
        sys.stderr.write(proc.stdout + "\n" + proc.stderr)
    assert proc.returncode == 0, "Comments PHPUnit suite failed"
