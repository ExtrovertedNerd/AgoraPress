"""
Smoke tests for Settings API + core settings screens.

Runnable via:
  pytest tests/test_settings_api.py -v
"""

from __future__ import annotations

import shutil
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SETTINGS = ROOT / "ap-includes" / "class-ap-settings.php"
OPTIONS = ROOT / "ap-includes" / "class-ap-options.php"
FUNCTIONS = ROOT / "ap-includes" / "functions.php"
BOOTSTRAP = ROOT / "ap-includes" / "bootstrap.php"
ADMIN = ROOT / "ap-admin"
STRUCTURE = ROOT / "tests" / "Structure" / "assert-structure.php"
PHPUNIT = ROOT / "tests" / "Options" / "SettingsApiTest.php"

SCREENS = [
    "options-general.php",
    "options-mail.php",
    "options-modules.php",
    "options-writing.php",
    "options-reading.php",
    "options-discussion.php",
    "options-media.php",
    "options-permalink.php",
]


def _php_bin() -> str:
    return shutil.which("php") or "php"


def test_core_files_exist() -> None:
    assert SETTINGS.is_file()
    assert OPTIONS.is_file()
    assert PHPUNIT.is_file()
    for name in SCREENS:
        assert (ADMIN / name).is_file(), f"Missing {name}"


def test_settings_api_surface() -> None:
    src = SETTINGS.read_text(encoding="utf-8")
    for needle in (
        "class AP_Settings",
        "function registerSetting",
        "function addSection",
        "function addField",
        "function settingsFields",
        "function doSections",
        "function doFields",
        "function save",
        "function registerCore",
        "function sanitizeCheckbox",
        "function sanitizeUrlOption",
        "function sanitizeRegistrationCaptcha",
        "function sanitizeReservedUsernames",
        "function sanitizeDefaultCategory",
        "reserved_usernames",
        "['off', 'math', 'guard']",
    ):
        assert needle in src, f"Expected {needle!r} in class-ap-settings.php"


def test_options_module_helpers() -> None:
    src = OPTIONS.read_text(encoding="utf-8")
    for needle in (
        "function isModuleEnabled",
        "function updateModules",
        "function updateGeneralSettings",
        "function updateDiscussionSettings",
        "function updateMediaSettings",
        "function updateWritingSettings",
        "function updateMailSettings",
        "function updatePermalinkSettings",
        "'off', 'math', 'guard'",
        "reserved_usernames",
        "function siteIcon",
        "MODULE_STATIC_PAGES",
        "MODULE_BLOG",
        "MODULE_FORUM",
    ):
        assert needle in src, f"Expected {needle!r} in class-ap-options.php"


def test_site_icon_on_general_settings() -> None:
    settings = SETTINGS.read_text(encoding="utf-8")
    options = OPTIONS.read_text(encoding="utf-8")
    general = (ADMIN / "options-general.php").read_text(encoding="utf-8")
    installer = (ROOT / "ap-includes" / "class-ap-installer.php").read_text(encoding="utf-8")

    assert "site_icon" in settings
    assert "registerSetting('general', 'site_icon'" in settings
    # site_icon = 0 leaves manual root favicon.ico as passive browser fallback.
    assert "favicon.ico" in settings
    assert "site_icon" in options
    assert "function siteIcon" in options
    # Media picker (upload / library / preview / remove), not a bare numeric field.
    assert "renderSiteIconField" in general
    assert "processSiteIconSave" in general
    assert "multipart/form-data" in general
    assert "'site_icon'" in installer
    # Save: manage_options page gate + processSiteIconSave (nonce + cap).
    assert "requireCapability('manage_options')" in general
    assert "message_key'] === 'nonce'" in general
    assert "message_key'] === 'cap'" in general

    media_admin = (ADMIN / "includes" / "class-ap-admin-media.php").read_text(encoding="utf-8")
    assert "function renderSiteIconField" in media_admin
    assert "function resolveSiteIconInput" in media_admin
    assert "function processSiteIconSave" in media_admin
    assert "function userCanManageSiteIcon" in media_admin
    assert "SITE_ICON_CAPABILITY" in media_admin
    assert "SITE_ICON_NONCE_ACTION" in media_admin
    assert "manage_options" in media_admin
    assert "ap_settings_general" in media_admin
    assert "site_icon_upload" in media_admin
    assert "remove_site_icon" in media_admin

    # Phase 2: derivatives generated on set/change (32/180/192/512 + ico/fallback).
    media = (ROOT / "ap-includes" / "class-ap-media.php").read_text(encoding="utf-8")
    assert "SITE_ICON_SIZES" in media
    assert "function generateSiteIconSizes" in media
    assert "function writePngAsIco" in media or "writeSiteIconIco" in media
    assert "ensureSiteIconDerivatives" in options
    # Phase 2: cleanup on remove or replace (old pack deleted; original media kept).
    assert "function cleanupSiteIconDerivatives" in media
    assert "function applySiteIconChange" in options
    assert "cleanupSiteIconDerivatives" in options


def test_procedural_wrappers() -> None:
    src = FUNCTIONS.read_text(encoding="utf-8")
    for needle in (
        "function ap_register_setting",
        "function ap_add_settings_section",
        "function ap_add_settings_field",
        "function ap_settings_fields",
        "function ap_do_settings_sections",
        "function ap_is_module_enabled",
    ):
        assert needle in src, f"Expected {needle!r} in functions.php"


def test_admin_screens_gate() -> None:
    for name in SCREENS:
        src = (ADMIN / name).read_text(encoding="utf-8")
        assert "requireCapability" in src
        assert "manage_options" in src


def test_mail_settings_screen() -> None:
    mail = (ADMIN / "options-mail.php").read_text(encoding="utf-8")
    for needle in (
        "isSaveRequest('mail')",
        "settingsFields('mail')",
        "updateMailSettings",
        "mail_from_name",
        "mail_from_email",
        "mail_reply_to",
        "mail_transport",
        "smtp_host",
        "smtp_port",
        "smtp_encryption",
        'value="none"',
        'value="tls"',
        'value="ssl"',
        "smtp_user",
        "smtp_pass",
        "Send test email to admin_email",
        "ap_mail_send_test",
        "storedLastError",
        "autocomplete=\"new-password\"",
    ):
        assert needle in mail, f"options-mail.php missing {needle!r}"
    assert "isSaveRequest('general')" not in mail
    general = (ADMIN / "options-general.php").read_text(encoding="utf-8")
    assert "mail_from_email" not in general
    assert "smtp_host" not in general
    settings = SETTINGS.read_text(encoding="utf-8")
    assert "registerSetting('mail', 'mail_from_email'" in settings
    assert "registerSetting('general', 'mail_from_email'" not in settings
    assert "registerSetting('mail', 'smtp_pass'" in settings
    smtp_block = settings.split("registerSetting('mail', 'smtp_pass'", 1)[1]
    assert "'autoload' => 'no'" in smtp_block.split("registerSetting(", 1)[0]
    phpunit = PHPUNIT.read_text(encoding="utf-8")
    assert "testSendTestToAdminRecordsLastErrorOnFailure" in phpunit
    assert "SMTP handshake failed." in phpunit
    assert "mail_last_error" in phpunit


def test_writing_default_category_lists_real_terms() -> None:
    writing = (ADMIN / "options-writing.php").read_text(encoding="utf-8")
    settings = SETTINGS.read_text(encoding="utf-8")
    phpunit = PHPUNIT.read_text(encoding="utf-8")

    assert 'name="default_category"' in writing
    assert "ensureDefaultCategory" in writing
    assert "— Uncategorized / site default —" not in writing
    assert '<option value="0">' not in writing
    assert "function sanitizeDefaultCategory" in settings
    assert "registerSetting('writing', 'default_category'" in settings
    assert "sanitizeDefaultCategory" in settings
    assert "testUpdateWritingSettingsPersistsLivingTermId" in phpunit
    assert "testUpdateWritingSettingsZeroResolvesToLivingTermId" in phpunit
    assert "testUpdateWritingSettingsDeadTermResolvesToLivingTermId" in phpunit
    assert "testUpdateWritingSettingsZeroDoesNotClobberLivingDefault" in phpunit
    assert "testWritingLoadResolvesZeroAndPersistsLivingTermId" in phpunit
    assert "testWritingLoadResolvesDeadTermAndPersistsLivingTermId" in phpunit


def test_reserved_usernames_option() -> None:
    settings = SETTINGS.read_text(encoding="utf-8")
    options = OPTIONS.read_text(encoding="utf-8")
    general = (ADMIN / "options-general.php").read_text(encoding="utf-8")
    installer = (ROOT / "ap-includes" / "class-ap-installer.php").read_text(encoding="utf-8")

    assert "registerSetting('general', 'reserved_usernames'" in settings
    assert "function sanitizeReservedUsernames" in settings
    assert "reserved_usernames" in options
    assert 'name="reserved_usernames"' in general
    assert "<textarea" in general
    assert "One username per line" in general
    assert "That username is not available." in general
    assert "does not say a name is reserved" in general
    assert "Users → Add" in general
    assert "ap-cli user create" in general
    assert "'reserved_usernames' => ''" in installer


def test_bootstrap_wires_settings() -> None:
    src = BOOTSTRAP.read_text(encoding="utf-8")
    assert "class-ap-settings.php" in src
    assert "registerCore" in src


def test_structure_includes_settings() -> None:
    src = STRUCTURE.read_text(encoding="utf-8")
    assert "class-ap-settings.php" in src
    for name in SCREENS:
        assert name in src


def test_phpunit_settings_api() -> None:
    cmd = [
        _php_bin(),
        str(ROOT / "vendor" / "bin" / "phpunit"),
        "--colors=never",
        str(PHPUNIT),
    ]
    proc = subprocess.run(cmd, cwd=ROOT, capture_output=True, text=True)
    if proc.returncode != 0:
        sys.stdout.write(proc.stdout)
        sys.stderr.write(proc.stderr)
    assert proc.returncode == 0, "SettingsApiTest PHPUnit failed"
