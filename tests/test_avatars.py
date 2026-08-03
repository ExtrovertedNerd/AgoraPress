"""
Smoke tests for AgoraPress avatars (local upload + Gravatar).

Runnable via:
  pytest tests/test_avatars.py -v
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


def test_avatar_files_exist() -> None:
    required = [
        INCLUDES / "class-ap-avatar.php",
        ROOT / "tests" / "User" / "AvatarTest.php",
    ]
    for path in required:
        assert path.is_file(), f"Missing {path.relative_to(ROOT)}"


def test_avatar_class_api_surface() -> None:
    src = (INCLUDES / "class-ap-avatar.php").read_text(encoding="utf-8")
    for needle in (
        "class AP_Avatar",
        "META_AVATAR",
        "OPTION_SHOW",
        "OPTION_DEFAULT",
        "OPTION_RATING",
        "function isEnabled",
        "function getDefault",
        "function getRating",
        "function getLocalAttachmentId",
        "function setLocalAttachmentId",
        "function deleteLocal",
        "function upload",
        "function getData",
        "function getUrl",
        "function getHtml",
        "function gravatarHash",
        "function gravatarUrl",
        "function mysteryDataUri",
        "show_avatars",
        "avatar_default",
        "avatar_rating",
    ):
        assert needle in src, f"Expected {needle!r} in AP_Avatar"


def test_procedural_helpers_exist() -> None:
    src = (INCLUDES / "functions.php").read_text(encoding="utf-8")
    for needle in (
        "function ap_show_avatars",
        "function ap_get_avatar_url",
        "function ap_get_avatar",
        "function ap_get_avatar_data",
        "function ap_upload_user_avatar",
        "function ap_delete_user_avatar",
    ):
        assert needle in src, f"Expected {needle!r} in functions.php"


def test_template_tags_author_avatar() -> None:
    src = (INCLUDES / "template-tags.php").read_text(encoding="utf-8")
    assert "function ap_get_the_author_avatar" in src
    assert "function ap_the_author_avatar" in src


def test_bootstrap_loads_avatar() -> None:
    src = (INCLUDES / "bootstrap.php").read_text(encoding="utf-8")
    assert "class-ap-avatar.php" in src


def test_installer_seeds_avatar_options() -> None:
    src = (INCLUDES / "class-ap-installer.php").read_text(encoding="utf-8")
    assert "'show_avatars'" in src or '"show_avatars"' in src
    assert "'avatar_default'" in src or '"avatar_default"' in src
    assert "'avatar_rating'" in src or '"avatar_rating"' in src


def test_admin_user_edit_avatar_ui() -> None:
    src = (ADMIN / "includes" / "class-ap-admin-user-edit.php").read_text(
        encoding="utf-8"
    )
    for needle in (
        "enctype=\"multipart/form-data\"",
        "ap_avatar",
        "remove_avatar",
        "renderAvatarFieldset",
        "processAvatar",
        "AP_Avatar",
    ):
        assert needle in src, f"Expected {needle!r} in admin user edit"


def test_profile_and_user_edit_pass_files() -> None:
    profile = (ADMIN / "profile.php").read_text(encoding="utf-8")
    assert "$_FILES" in profile
    user_edit = (ADMIN / "user-edit.php").read_text(encoding="utf-8")
    assert "$_FILES" in user_edit


def test_structure_script_includes_avatar() -> None:
    src = STRUCTURE.read_text(encoding="utf-8")
    assert "ap-includes/class-ap-avatar.php" in src


def test_php_syntax_of_avatar_files() -> None:
    php = _php_bin()
    files = [
        INCLUDES / "class-ap-avatar.php",
        ADMIN / "includes" / "class-ap-admin-user-edit.php",
        ADMIN / "profile.php",
        ADMIN / "user-edit.php",
        INCLUDES / "functions.php",
        INCLUDES / "template-tags.php",
    ]
    for path in files:
        proc = subprocess.run(
            [php, "-l", str(path)],
            capture_output=True,
            text=True,
            check=False,
        )
        assert proc.returncode == 0, (
            f"php -l failed for {path.name}: {proc.stderr or proc.stdout}"
        )


def test_phpunit_avatar_when_available() -> None:
    phpunit = ROOT / "vendor" / "bin" / "phpunit"
    if not phpunit.is_file():
        return
    proc = subprocess.run(
        [str(phpunit), "tests/User/AvatarTest.php", "--colors=never"],
        cwd=str(ROOT),
        capture_output=True,
        text=True,
        check=False,
    )
    if proc.returncode != 0:
        sys.stderr.write(proc.stdout + "\n" + proc.stderr)
    assert proc.returncode == 0, "AvatarTest PHPUnit suite failed"
