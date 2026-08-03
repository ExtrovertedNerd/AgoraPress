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
        MIGRATIONS / "0004_core_comments_commentmeta.php",
        ADMIN / "edit-comments.php",
        ADMIN / "includes" / "class-ap-comments-list-table.php",
        ROOT / "tests" / "Comment" / "CommentModelTest.php",
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


def test_bootstrap_loads_comment() -> None:
    src = BOOTSTRAP.read_text(encoding="utf-8")
    assert "class-ap-comment.php" in src


def test_db_version_is_four() -> None:
    src = VERSION.read_text(encoding="utf-8")
    assert "define('AP_DB_VERSION', '4')" in src


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
