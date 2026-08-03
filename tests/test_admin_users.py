"""
Smoke tests for AgoraPress admin users list / edit / profile screens.

Runnable via:
  pytest tests/test_admin_users.py -v
"""

from __future__ import annotations

import shutil
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
ADMIN = ROOT / "ap-admin"
INCLUDES = ROOT / "ap-includes"
STRUCTURE = ROOT / "tests" / "Structure" / "assert-structure.php"


def _php_bin() -> str:
    return shutil.which("php") or "php"


def test_admin_user_files_exist() -> None:
    required = [
        ADMIN / "users.php",
        ADMIN / "user-new.php",
        ADMIN / "user-edit.php",
        ADMIN / "profile.php",
        ADMIN / "includes" / "class-ap-users-list-table.php",
        ADMIN / "includes" / "class-ap-admin-user-edit.php",
        INCLUDES / "class-ap-user.php",
    ]
    for path in required:
        assert path.is_file(), f"Missing {path.relative_to(ROOT)}"


def test_user_class_defines_crud_api() -> None:
    src = (INCLUDES / "class-ap-user.php").read_text(encoding="utf-8")
    for needle in (
        "class AP_User",
        "function create",
        "function update",
        "function delete",
        "function query",
        "function count",
        "function getMeta",
        "function updateMeta",
        "function deleteMeta",
        "function generatePassword",
        "function isLastAdministrator",
        "function getProfileMeta",
        "function countByRole",
        "function countPosts",
    ):
        assert needle in src, f"Expected {needle!r} in AP_User"


def test_users_list_table_api() -> None:
    src = (ADMIN / "includes" / "class-ap-users-list-table.php").read_text(
        encoding="utf-8"
    )
    for needle in (
        "class AP_Users_List_Table",
        "function prepareItems",
        "function processBulkAction",
        "function processRowAction",
        "function render",
        "function getColumns",
        "function getBulkActions",
        "function getViews",
    ):
        assert needle in src, f"Expected {needle!r} in users list table"


def test_admin_user_edit_api() -> None:
    src = (ADMIN / "includes" / "class-ap-admin-user-edit.php").read_text(
        encoding="utf-8"
    )
    for needle in (
        "class AP_Admin_User_Edit",
        "function save",
        "function renderForm",
        "function passwordErrors",
        "create-user",
        "update-user-",
        "update-profile-",
        "profile",
    ):
        assert needle in src, f"Expected {needle!r} in admin user edit"


def test_screens_require_caps_and_use_classes() -> None:
    users = (ADMIN / "users.php").read_text(encoding="utf-8")
    assert "list_users" in users
    assert "AP_Users_List_Table" in users

    user_new = (ADMIN / "user-new.php").read_text(encoding="utf-8")
    assert "create_users" in user_new
    assert "AP_Admin_User_Edit" in user_new

    user_edit = (ADMIN / "user-edit.php").read_text(encoding="utf-8")
    assert "edit_users" in user_edit
    assert "AP_Admin_User_Edit" in user_edit

    profile = (ADMIN / "profile.php").read_text(encoding="utf-8")
    assert "profile" in profile.lower()
    assert "AP_Admin_User_Edit" in profile


def test_admin_menu_includes_users() -> None:
    src = (ADMIN / "includes" / "class-ap-admin.php").read_text(encoding="utf-8")
    assert "'id' => 'users'" in src or '"id" => "users"' in src
    assert "users.php" in src
    assert "profile.php" in src
    assert "user_created" in src
    assert "profile_updated" in src


def test_bootstrap_loads_user_admin_classes() -> None:
    src = (ADMIN / "admin-bootstrap.php").read_text(encoding="utf-8")
    assert "class-ap-users-list-table.php" in src
    assert "class-ap-admin-user-edit.php" in src


def test_procedural_helpers_exist() -> None:
    src = (INCLUDES / "functions.php").read_text(encoding="utf-8")
    for needle in (
        "function ap_create_user",
        "function ap_update_user",
        "function ap_delete_user",
        "function ap_get_users",
        "function ap_count_users",
        "function ap_get_user_meta",
        "function ap_update_user_meta",
        "function ap_generate_password",
    ):
        assert needle in src, f"Expected {needle!r} in functions.php"


def test_structure_script_includes_user_screens() -> None:
    src = STRUCTURE.read_text(encoding="utf-8")
    for path in (
        "ap-admin/users.php",
        "ap-admin/user-new.php",
        "ap-admin/user-edit.php",
        "ap-admin/profile.php",
        "ap-admin/includes/class-ap-users-list-table.php",
        "ap-admin/includes/class-ap-admin-user-edit.php",
    ):
        assert path in src, f"Structure script missing {path}"


def test_php_syntax_of_user_admin_files() -> None:
    php = _php_bin()
    files = [
        ADMIN / "users.php",
        ADMIN / "user-new.php",
        ADMIN / "user-edit.php",
        ADMIN / "profile.php",
        ADMIN / "includes" / "class-ap-users-list-table.php",
        ADMIN / "includes" / "class-ap-admin-user-edit.php",
        INCLUDES / "class-ap-user.php",
    ]
    for path in files:
        proc = subprocess.run(
            [php, "-l", str(path)],
            capture_output=True,
            text=True,
            check=False,
        )
        assert proc.returncode == 0, f"php -l failed for {path.name}: {proc.stderr or proc.stdout}"


def test_phpunit_admin_users_when_available() -> None:
    phpunit = ROOT / "vendor" / "bin" / "phpunit"
    if not phpunit.is_file():
        return
    proc = subprocess.run(
        [str(phpunit), "tests/Admin/AdminUsersTest.php", "--colors=never"],
        cwd=str(ROOT),
        capture_output=True,
        text=True,
        check=False,
    )
    if proc.returncode != 0:
        sys.stderr.write(proc.stdout + "\n" + proc.stderr)
    assert proc.returncode == 0, "AdminUsersTest PHPUnit suite failed"
