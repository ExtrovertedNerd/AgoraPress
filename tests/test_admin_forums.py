"""
Smoke tests for AgoraPress admin forum and moderation screens.

Runnable via:
  pytest tests/test_admin_forums.py -v
"""

from __future__ import annotations

import shutil
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
ADMIN = ROOT / "ap-admin"
INCLUDES = ROOT / "ap-includes"
PHPUNIT = ROOT / "tests" / "Admin" / "AdminForumsTest.php"
STRUCTURE = ROOT / "tests" / "Structure" / "assert-structure.php"


def _php_bin() -> str:
    return shutil.which("php") or "php"


def test_admin_forum_files_exist() -> None:
    required = [
        ADMIN / "forums.php",
        ADMIN / "forum-edit.php",
        ADMIN / "forum-topics.php",
        ADMIN / "forum-moderation.php",
        ADMIN / "forum-groups.php",
        ADMIN / "options-forums.php",
        ADMIN / "includes" / "class-ap-forums-list-table.php",
        ADMIN / "includes" / "class-ap-admin-forum-edit.php",
        ADMIN / "includes" / "class-ap-forum-topics-list-table.php",
        ADMIN / "includes" / "class-ap-forum-moderation-queue.php",
        ADMIN / "includes" / "class-ap-admin-forum-groups.php",
        INCLUDES / "class-ap-forum.php",
        INCLUDES / "class-ap-forum-moderation.php",
        INCLUDES / "class-ap-group.php",
        PHPUNIT,
    ]
    for path in required:
        assert path.is_file(), f"Missing {path.relative_to(ROOT)}"


def test_admin_forum_screens_gate_caps_and_module() -> None:
    checks = {
        "forums.php": ("manage_forums", "AP_Forums_List_Table"),
        "forum-edit.php": ("manage_forums", "AP_Admin_Forum_Edit"),
        "forum-topics.php": ("moderate_forums", "AP_Forum_Topics_List_Table"),
        "forum-moderation.php": ("moderate_forums", "AP_Forum_Moderation_Queue"),
        "forum-groups.php": ("manage_forums", "AP_Admin_Forum_Groups"),
        "options-forums.php": ("manage_options", "updateForumSettings"),
    }
    for file, (cap, needle) in checks.items():
        src = (ADMIN / file).read_text(encoding="utf-8")
        assert "requireCapability" in src, f"{file} missing requireCapability"
        assert cap in src, f"{file} missing cap {cap!r}"
        assert "isModuleEnabled" in src and "forum" in src, f"{file} should gate forum module"
        assert needle in src, f"{file} missing {needle!r}"


def test_admin_forum_classes_define_core_api() -> None:
    list_src = (ADMIN / "includes" / "class-ap-forums-list-table.php").read_text(
        encoding="utf-8"
    )
    for needle in (
        "class AP_Forums_List_Table",
        "function prepareItems",
        "function processBulkAction",
        "function render",
        "function getColumns",
        "function getBulkActions",
    ):
        assert needle in list_src, f"Expected {needle!r} in forums list table"

    edit_src = (ADMIN / "includes" / "class-ap-admin-forum-edit.php").read_text(
        encoding="utf-8"
    )
    for needle in (
        "class AP_Admin_Forum_Edit",
        "function save",
        "function delete",
        "function renderForm",
        "function renderPermissionsFieldset",
        "function parentOptions",
        "manage_forums",
        "forum_access_level",
        "AP_Forum_Permissions",
        "Visibility",
    ):
        assert needle in edit_src, f"Expected {needle!r} in forum edit class"

    topics_src = (ADMIN / "includes" / "class-ap-forum-topics-list-table.php").read_text(
        encoding="utf-8"
    )
    for needle in (
        "class AP_Forum_Topics_List_Table",
        "function processBulkAction",
        "function processRowAction",
        "function getViews",
        "lockTopic",
        "approveTopic",
        "softDeleteTopic",
    ):
        assert needle in topics_src, f"Expected {needle!r} in topics list table"

    queue_src = (ADMIN / "includes" / "class-ap-forum-moderation-queue.php").read_text(
        encoding="utf-8"
    )
    for needle in (
        "class AP_Forum_Moderation_Queue",
        "function prepare",
        "function processAction",
        "function processBulk",
        "function render",
        "getPendingTopics",
        "queryReports",
        "approveTopic",
        "resolveReport",
    ):
        assert needle in queue_src, f"Expected {needle!r} in moderation queue"

    groups_src = (ADMIN / "includes" / "class-ap-admin-forum-groups.php").read_text(
        encoding="utf-8"
    )
    for needle in (
        "class AP_Admin_Forum_Groups",
        "function prepareItems",
        "function save",
        "function delete",
        "function renderList",
        "function renderForm",
        "ensureSystemGroups",
    ):
        assert needle in groups_src, f"Expected {needle!r} in forum groups class"


def test_admin_menu_and_bootstrap_wire_forums() -> None:
    admin_src = (ADMIN / "includes" / "class-ap-admin.php").read_text(encoding="utf-8")
    for needle in (
        "'forums.php' => 'manage_forums'",
        "'forum-topics.php' => 'moderate_forums'",
        "'forum-moderation.php' => 'moderate_forums'",
        "'forum-groups.php' => 'manage_forums'",
        "'options-forums.php' => 'manage_options'",
        "'id' => 'forums'",
        "'id' => 'forum-topics'",
        "'id' => 'forum-moderation'",
        "'id' => 'forum-groups'",
        "'id' => 'options-forums'",
        "'module' => 'forum'",
        "forum_created",
        "bulk_topic_locked",
        "report_resolved",
        "forums_saved",
    ):
        assert needle in admin_src, f"class-ap-admin.php missing {needle!r}"

    boot = (ADMIN / "admin-bootstrap.php").read_text(encoding="utf-8")
    for needle in (
        "class-ap-forums-list-table.php",
        "class-ap-admin-forum-edit.php",
        "class-ap-forum-topics-list-table.php",
        "class-ap-forum-moderation-queue.php",
        "class-ap-admin-forum-groups.php",
    ):
        assert needle in boot, f"admin-bootstrap.php missing {needle!r}"


def test_options_forums_screen_fields() -> None:
    src = (ADMIN / "options-forums.php").read_text(encoding="utf-8")
    for needle in (
        "forum_topics_per_page",
        "forum_posts_per_page",
        "forum_allow_guest_viewing",
        "forum_private_messaging_enabled",
        "forum_attachments_enabled",
        "forum_flood_interval",
        "forum_posts_require_approval",
        "forum_spam_blacklist",
        "forum_search_enabled",
        "AP_Settings::settingsFields('forums')",
    ):
        assert needle in src, f"options-forums.php missing {needle!r}"


def test_structure_asserts_forum_admin_files() -> None:
    src = STRUCTURE.read_text(encoding="utf-8")
    for needle in (
        "ap-admin/forums.php",
        "ap-admin/forum-edit.php",
        "ap-admin/forum-topics.php",
        "ap-admin/forum-moderation.php",
        "ap-admin/forum-groups.php",
        "ap-admin/options-forums.php",
        "ap-admin/includes/class-ap-forums-list-table.php",
        "ap-admin/includes/class-ap-forum-moderation-queue.php",
    ):
        assert needle in src, f"assert-structure.php missing {needle!r}"


def test_phpunit_admin_forums_passes() -> None:
    phpunit = ROOT / "vendor" / "bin" / "phpunit"
    if not phpunit.is_file():
        # Fall back to composer script path if vendor layout differs.
        phpunit = ROOT / "vendor" / "phpunit" / "phpunit" / "phpunit"
    assert phpunit.is_file() or (ROOT / "vendor" / "bin" / "phpunit").is_file()

    cmd = [
        _php_bin(),
        str(ROOT / "vendor" / "bin" / "phpunit"),
        "--configuration",
        str(ROOT / "phpunit.xml.dist"),
        str(PHPUNIT),
    ]
    proc = subprocess.run(
        cmd,
        cwd=str(ROOT),
        capture_output=True,
        text=True,
        timeout=120,
    )
    if proc.returncode != 0:
        sys.stderr.write(proc.stdout)
        sys.stderr.write(proc.stderr)
    assert proc.returncode == 0, "AdminForumsTest PHPUnit suite failed"
