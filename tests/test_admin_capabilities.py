"""
Smoke tests for admin capability gates on all screens.

Runnable via:
  pytest tests/test_admin_capabilities.py -v
"""

from __future__ import annotations

import shutil
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
ADMIN = ROOT / "ap-admin"
ADMIN_CLASS = ADMIN / "includes" / "class-ap-admin.php"
PHPUNIT = ROOT / "tests" / "Admin" / "AdminCapabilityTest.php"


def _php_bin() -> str:
    return shutil.which("php") or "php"


def test_admin_capability_helpers_exist() -> None:
    src = ADMIN_CLASS.read_text(encoding="utf-8")
    for needle in (
        "function requireCapability",
        "function currentUserCan",
        "function userCan",
        "function editCapabilityForPostType",
        "function editMetaCapForPostType",
        "function deleteMetaCapForPostType",
        "function publishCapabilityForPostType",
        "function screenCapabilities",
        "denyAccess",
    ):
        assert needle in src, f"Expected {needle!r} in class-ap-admin.php"


def test_screens_gate_with_require_capability() -> None:
    screens = {
        "index.php": "requireCapability('read')",
        "profile.php": "requireCapability('read')",
        "edit.php": "editCapabilityForPostType",
        "post-new.php": "editCapabilityForPostType",
        "post.php": "editMetaCapForPostType",
        "revision.php": "editMetaCapForPostType",
        "edit-comments.php": "moderate_comments",
        "edit-tags.php": "manage_categories",
        "upload.php": "upload_files",
        "media.php": "upload_files",
        "media-new.php": "upload_files",
        "themes.php": "switch_themes",
        "theme-options.php": "edit_theme_options",
        "nav-menus.php": "edit_theme_options",
        "widgets.php": "edit_theme_options",
        "plugins.php": "activate_plugins",
        "options-general.php": "manage_options",
        "options-modules.php": "manage_options",
        "options-writing.php": "manage_options",
        "options-reading.php": "manage_options",
        "options-discussion.php": "manage_options",
        "options-media.php": "manage_options",
        "options-permalink.php": "manage_options",
        "users.php": "list_users",
        "user-new.php": "create_users",
        "user-edit.php": "edit_users",
    }
    for name, cap_hint in screens.items():
        src = (ADMIN / name).read_text(encoding="utf-8")
        assert "requireCapability" in src, f"{name} missing requireCapability"
        assert cap_hint in src, f"{name} missing capability hint {cap_hint!r}"


def test_handlers_check_permissions() -> None:
    checks = {
        ADMIN / "includes" / "class-ap-admin-post-edit.php": [
            "userCan",
            "permission",
            "publishCapabilityForPostType",
        ],
        ADMIN / "includes" / "class-ap-posts-list-table.php": [
            "userCan",
            "deleteMetaCapForPostType",
        ],
        ADMIN / "includes" / "class-ap-admin-media.php": [
            "upload_files",
            "userCan",
        ],
        ADMIN / "includes" / "class-ap-media-list-table.php": [
            "upload_files",
            "delete_post",
        ],
        ADMIN / "includes" / "class-ap-comments-list-table.php": [
            "moderate_comments",
        ],
        ADMIN / "includes" / "class-ap-admin-terms.php": [
            "manage_categories",
        ],
        ADMIN / "includes" / "class-ap-admin-user-edit.php": [
            "create_users",
            "edit_users",
            "promote_users",
        ],
        ADMIN / "includes" / "class-ap-users-list-table.php": [
            "delete_users",
            "promote_users",
        ],
    }
    for path, needles in checks.items():
        src = path.read_text(encoding="utf-8")
        for needle in needles:
            assert needle in src, f"Expected {needle!r} in {path.name}"


def test_phpunit_capability_suite_exists() -> None:
    assert PHPUNIT.is_file(), "Missing AdminCapabilityTest.php"
    src = PHPUNIT.read_text(encoding="utf-8")
    assert "AdminCapabilityTest" in src
    assert "testSubscriberCannotCreatePost" in src
    assert "testContributorPublishDowngradesToPending" in src


def test_admin_capability_runtime_via_phpunit() -> None:
    """Run the focused PHPUnit file when vendor is present."""
    phpunit = ROOT / "vendor" / "bin" / "phpunit"
    if not phpunit.is_file():
        return
    result = subprocess.run(
        [
            _php_bin(),
            str(phpunit),
            "--configuration",
            str(ROOT / "phpunit.xml.dist"),
            str(PHPUNIT),
        ],
        cwd=str(ROOT),
        capture_output=True,
        text=True,
        check=False,
    )
    combined = (result.stdout or "") + (result.stderr or "")
    assert result.returncode == 0, combined


if __name__ == "__main__":
    sys.exit(__import__("pytest").main([__file__, "-v"]))
