"""
Permission matrix smoke checks (guest, member, mod, admin).

Runnable via:
  pytest tests/test_forum_permission_matrix.py -v
"""

from __future__ import annotations

import shutil
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PERM_CLASS = ROOT / "ap-includes" / "class-ap-forum-permissions.php"
FORUM_CLASS = ROOT / "ap-includes" / "class-ap-forum.php"
FRONT_CLASS = ROOT / "ap-includes" / "class-ap-forum-front.php"
PHPUNIT = ROOT / "tests" / "Forum" / "ForumPermissionMatrixTest.php"


def _php_bin() -> str:
    return shutil.which("php") or "php"


def test_permission_matrix_files_exist() -> None:
    assert PERM_CLASS.is_file()
    assert FORUM_CLASS.is_file()
    assert FRONT_CLASS.is_file()
    assert PHPUNIT.is_file()


def test_permission_matrix_api_surface() -> None:
    src = PERM_CLASS.read_text(encoding="utf-8")
    for needle in (
        "LEVEL_GUEST",
        "LEVEL_REGISTERED",
        "LEVEL_MODERATOR",
        "LEVEL_ADMINISTRATOR",
        "ACCESS_PUBLIC",
        "ACCESS_MEMBERS",
        "ACCESS_MODERATORS",
        "ACCESS_ADMINISTRATORS",
        "function getUserPermissions",
        "function userCan",
        "function userCanViewForum",
        "function userCanPostTopic",
        "function userCanPostReply",
        "function userCanModerate",
        "function userCanSticky",
        "function userCanAnnounce",
        "function allowedTopicTypesForCreate",
        "function applyAccessLevel",
        "function matrixForAccessLevel",
        "function baselinePermissionsForLevel",
    ):
        assert needle in src, f"Expected {needle!r} in class-ap-forum-permissions.php"

    forum = FORUM_CLASS.read_text(encoding="utf-8")
    for needle in (
        "can_quote",
        "can_like",
        "can_edit",
        "can_delete",
        "can_moderate",
        "function getPostsDisplayData",
        "function userCanEditPost",
        "function userCanDeletePost",
        "check_permissions",
    ):
        assert needle in forum, f"Expected {needle!r} in class-ap-forum.php"

    front = FRONT_CLASS.read_text(encoding="utf-8")
    for needle in (
        "can_post_topic",
        "can_reply",
        "can_moderate",
        "can_set_topic_type",
        "allowed_topic_types",
    ):
        assert needle in front, f"Expected {needle!r} in class-ap-forum-front.php"


def test_phpunit_defines_role_matrix_cases() -> None:
    src = PHPUNIT.read_text(encoding="utf-8")
    for needle in (
        "class ForumPermissionMatrixTest",
        "testPublicForumAclMatrixGuestMemberModAdmin",
        "testTopicTypeCapsMatrix",
        "testDisplayActionFlagsMatrix",
        "testFrontQueryFlagsMatrix",
        "testWritePathsHonorMatrix",
        "testMembersOnlyPresetMatrix",
        "testModeratorsOnlyAndAdministratorsOnlyMatrix",
        "'guest'",
        "'member'",
        "'mod'",
        "'admin'",
        "can_quote",
        "can_like",
        "can_moderate",
        "sticky",
        "announcement",
        "ACCESS_MEMBERS",
        "ACCESS_MODERATORS",
        "ACCESS_ADMINISTRATORS",
    ):
        assert needle in src, f"Expected {needle!r} in ForumPermissionMatrixTest.php"


def test_phpunit_permission_matrix_passes() -> None:
    result = subprocess.run(
        [
            _php_bin(),
            "vendor/bin/phpunit",
            "-c",
            "phpunit.xml.dist",
            "tests/Forum/ForumPermissionMatrixTest.php",
        ],
        cwd=str(ROOT),
        capture_output=True,
        text=True,
        check=False,
    )
    combined = (result.stdout or "") + (result.stderr or "")
    assert result.returncode == 0, f"PHPUnit permission matrix failed:\n{combined}"


if __name__ == "__main__":
    raise SystemExit(__import__("pytest").main([__file__, "-v"]))
