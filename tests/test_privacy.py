"""
Smoke tests for privacy tools (export / erase personal data).

Runnable via:
  pytest tests/test_privacy.py -v
"""

from __future__ import annotations

import shutil
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PRIVACY = ROOT / "ap-includes" / "class-ap-privacy.php"
OPTIONS = ROOT / "ap-admin" / "options-privacy.php"
EXPORT = ROOT / "ap-admin" / "export-personal-data.php"
ERASE = ROOT / "ap-admin" / "erase-personal-data.php"
FUNCTIONS = ROOT / "ap-includes" / "functions.php"
BOOTSTRAP = ROOT / "ap-includes" / "bootstrap.php"
ADMIN_CLASS = ROOT / "ap-admin" / "includes" / "class-ap-admin.php"
ROLES = ROOT / "ap-includes" / "class-ap-roles.php"
STRUCTURE = ROOT / "tests" / "Structure" / "assert-structure.php"
PHPUNIT = ROOT / "tests" / "Security" / "PrivacyTest.php"
CHANGELOG = ROOT / "CHANGELOG.md"


def _php_bin() -> str:
    return shutil.which("php") or "php"


def test_privacy_files_exist() -> None:
    for path in (PRIVACY, OPTIONS, EXPORT, ERASE, PHPUNIT, BOOTSTRAP, FUNCTIONS):
        assert path.is_file(), f"Missing {path.relative_to(ROOT)}"


def test_privacy_class_api() -> None:
    src = PRIVACY.read_text(encoding="utf-8")
    for needle in (
        "class AP_Privacy",
        "function exportPersonalData",
        "function exportPersonalDataJson",
        "function erasePersonalData",
        "function getPrivacyPolicyPageId",
        "function setPrivacyPolicyPageId",
        "function resolveUser",
        "function isSoleAdministrator",
        "OPTION_POLICY_PAGE",
        "ap_privacy_export_data",
        "ap_privacy_erase_data",
        "agorapress-personal-data-export",
        "Deleted User",
    ):
        assert needle in src, f"Expected {needle!r} in class-ap-privacy.php"


def test_procedural_wrappers() -> None:
    src = FUNCTIONS.read_text(encoding="utf-8")
    for needle in (
        "function ap_export_personal_data",
        "function ap_export_personal_data_json",
        "function ap_erase_personal_data",
        "function ap_get_privacy_policy_page_id",
        "function ap_set_privacy_policy_page_id",
        "function ap_get_privacy_policy_url",
        "function ap_privacy_resolve_user",
    ):
        assert needle in src, f"Expected {needle!r} in functions.php"


def test_bootstrap_loads_privacy() -> None:
    src = BOOTSTRAP.read_text(encoding="utf-8")
    assert "class-ap-privacy.php" in src


def test_roles_include_privacy_caps() -> None:
    src = ROLES.read_text(encoding="utf-8")
    for cap in (
        "manage_privacy_options",
        "export_others_personal_data",
        "erase_others_personal_data",
    ):
        assert cap in src, f"Expected capability {cap!r} in roles"


def test_admin_menu_and_screens() -> None:
    admin = ADMIN_CLASS.read_text(encoding="utf-8")
    for needle in (
        "export-personal-data.php",
        "erase-personal-data.php",
        "options-privacy.php",
        "export_others_personal_data",
        "erase_others_personal_data",
        "manage_privacy_options",
        "Export Personal Data",
        "Erase Personal Data",
        "privacy_saved",
    ):
        assert needle in admin, f"Expected {needle!r} in class-ap-admin.php"

    for path, caps in (
        (OPTIONS, ("manage_privacy_options", "requireLogin", "wp_page_for_privacy_policy")),
        (EXPORT, ("export_others_personal_data", "export-personal-data", "Download JSON")),
        (ERASE, ("erase_others_personal_data", "erase-personal-data", "confirm_erase")),
    ):
        src = path.read_text(encoding="utf-8")
        for cap in caps:
            assert cap in src, f"{path.name} missing {cap!r}"


def test_structure_lists_privacy() -> None:
    src = STRUCTURE.read_text(encoding="utf-8")
    for path in (
        "ap-includes/class-ap-privacy.php",
        "ap-admin/options-privacy.php",
        "ap-admin/export-personal-data.php",
        "ap-admin/erase-personal-data.php",
    ):
        assert path in src, f"Structure assert missing {path}"


def test_changelog_mentions_privacy() -> None:
    text = CHANGELOG.read_text(encoding="utf-8")
    assert "Privacy" in text or "privacy" in text
    assert "AP_Privacy" in text or "personal data" in text.lower()


def test_phpunit_privacy_suite() -> None:
    cmd = [
        _php_bin(),
        str(ROOT / "vendor" / "bin" / "phpunit"),
        "--configuration",
        str(ROOT / "phpunit.xml.dist"),
        str(PHPUNIT),
    ]
    proc = subprocess.run(cmd, cwd=ROOT, capture_output=True, text=True, timeout=120)
    assert proc.returncode == 0, proc.stdout + "\n" + proc.stderr
