"""
Smoke tests for AgoraPress Media Library.

Runnable via:
  pytest tests/test_media_library.py -v
"""

from __future__ import annotations

import shutil
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
ADMIN = ROOT / "ap-admin"
INCLUDES = ROOT / "ap-includes"
FUNCTIONS = ROOT / "ap-includes" / "functions.php"
BOOTSTRAP = ROOT / "ap-includes" / "bootstrap.php"
STRUCTURE = ROOT / "tests" / "Structure" / "assert-structure.php"


def _php_bin() -> str:
    return shutil.which("php") or "php"


def test_media_files_exist() -> None:
    required = [
        INCLUDES / "class-ap-media.php",
        ADMIN / "upload.php",
        ADMIN / "media.php",
        ADMIN / "media-new.php",
        ADMIN / "includes" / "class-ap-media-list-table.php",
        ADMIN / "includes" / "class-ap-admin-media.php",
        ROOT / "tests" / "Media" / "MediaLibraryTest.php",
    ]
    for path in required:
        assert path.is_file(), f"Missing {path.relative_to(ROOT)}"


def test_media_class_api_surface() -> None:
    src = (INCLUDES / "class-ap-media.php").read_text(encoding="utf-8")
    for needle in (
        "class AP_Media",
        "function handleUpload",
        "function insertAttachment",
        "function deleteAttachment",
        "function updateAttachment",
        "function getAttachedFile",
        "function getAttachmentUrl",
        "function getAltText",
        "function setAltText",
        "function getMetadata",
        "function uploadDir",
        "function checkFileType",
        "function query",
        "function mimeTypeCounts",
        "function gdAvailable",
        "function imagickAvailable",
        "function imageEditorAvailable",
        "function editImage",
        "function resampleFile",
        "function generateIntermediateSizes",
        "function generateSiteIconSizes",
        "function cleanupSiteIconDerivatives",
        "function getSiteIconSizes",
        "function getSiteIconUrl",
        "function getSiteIconPath",
        "function getSiteIconMetaTags",
        "function printSiteIconTags",
        "function registerSiteIconTags",
        "SITE_ICON_SIZES",
        "function maxDisplayWidth",
        "function printContentImageCss",
        "OPTION_MAX_DISPLAY_WIDTH",
        "ATTACHED_FILE_META",
        "ALT_META",
    ):
        assert needle in src, f"Expected {needle!r} in AP_Media"

    # Passive root favicon.ico when site_icon is unset (no synthetic link tags).
    assert "favicon.ico" in src
    assert "Do not emit a synthetic /favicon.ico link tag" in src
    assert "registerSiteIconTags" in BOOTSTRAP.read_text(encoding="utf-8")


def test_media_admin_api_surface() -> None:
    list_src = (ADMIN / "includes" / "class-ap-media-list-table.php").read_text(
        encoding="utf-8"
    )
    for needle in (
        "class AP_Media_List_Table",
        "function prepareItems",
        "function processBulkAction",
        "function processRowAction",
        "function render",
        "function getViews",
    ):
        assert needle in list_src, f"Expected {needle!r} in media list table"

    edit_src = (ADMIN / "includes" / "class-ap-admin-media.php").read_text(
        encoding="utf-8"
    )
    for needle in (
        "class AP_Admin_Media",
        "function processUpload",
        "function save",
        "function renderUploadForm",
        "function renderEditForm",
        "function normalizeFilesArray",
        "image_scale_w",
        "edit_image",
        "Scale / crop",
    ):
        assert needle in edit_src, f"Expected {needle!r} in admin media class"


def test_procedural_media_helpers() -> None:
    src = FUNCTIONS.read_text(encoding="utf-8")
    for fn in (
        "function ap_handle_upload",
        "function ap_insert_attachment",
        "function ap_delete_attachment",
        "function ap_update_attachment",
        "function ap_get_attached_file",
        "function ap_get_attachment_url",
        "function ap_get_attachment_alt",
        "function ap_set_attachment_alt",
        "function ap_get_attachment_metadata",
        "function ap_upload_dir",
        "function ap_get_media",
        "function ap_check_filetype",
    ):
        assert fn in src, f"Missing helper {fn}"


def test_bootstrap_loads_media() -> None:
    src = BOOTSTRAP.read_text(encoding="utf-8")
    assert "class-ap-media.php" in src


def test_admin_menu_has_media() -> None:
    src = (ADMIN / "includes" / "class-ap-admin.php").read_text(encoding="utf-8")
    assert "'id' => 'media'" in src or '"id" => "media"' in src
    assert "upload.php" in src


def test_structure_assert_includes_media() -> None:
    src = STRUCTURE.read_text(encoding="utf-8")
    for needle in (
        "ap-includes/class-ap-media.php",
        "ap-admin/upload.php",
        "ap-admin/media.php",
        "ap-admin/includes/class-ap-media-list-table.php",
    ):
        assert needle in src, f"Structure assert missing {needle}"


def test_phpunit_media_suite() -> None:
    php = _php_bin()
    cmd = [
        php,
        str(ROOT / "vendor" / "bin" / "phpunit"),
        "--configuration",
        str(ROOT / "phpunit.xml.dist"),
        str(ROOT / "tests" / "Media" / "MediaLibraryTest.php"),
    ]
    if not (ROOT / "vendor" / "bin" / "phpunit").is_file():
        cmd = [php, str(ROOT / "vendor" / "phpunit" / "phpunit" / "phpunit"),
               "--configuration", str(ROOT / "phpunit.xml.dist"),
               str(ROOT / "tests" / "Media" / "MediaLibraryTest.php")]
    proc = subprocess.run(
        cmd,
        cwd=str(ROOT),
        capture_output=True,
        text=True,
        check=False,
    )
    if proc.returncode != 0:
        sys.stderr.write(proc.stdout + "\n" + proc.stderr)
    assert proc.returncode == 0, "MediaLibraryTest PHPUnit suite failed"
