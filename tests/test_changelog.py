"""
Smoke tests for CHANGELOG.md (Keep a Changelog + SemVer, concise).

Historical notes (0.3.8-beta through 0.2.0-beta) live in CHANGELOG-archive.md.
The size cap applies only to the living CHANGELOG.md.

Runnable via:
  pytest tests/test_changelog.py -v
"""

from __future__ import annotations

import re
from pathlib import Path

import pytest

ROOT = Path(__file__).resolve().parents[1]
CHANGELOG = ROOT / "CHANGELOG.md"
ARCHIVE = ROOT / "CHANGELOG-archive.md"
VERSION_PHP = ROOT / "ap-includes" / "version.php"

REQUIRED_PATTERNS = (
    r"(?im)^#\s+Changelog\s*$",
    r"(?im)^##\s+\[Unreleased\]\s*$",
    r"(?im)^###\s+Added\s*$",
    r"keepachangelog\.com",
    r"semver\.org",
    r"Semantic Versioning",
)

# Living-file product surface — MVP phrases moved with 0.2.0-beta to the archive.
REQUIRED_PHRASES = (
    "ap-includes",
    "AP_VERSION",
    "forum",
    "no telemetry",
    "CHANGELOG-archive.md",
)

ARCHIVE_PHRASES = (
    "ap-config-sample.php",
    "docker-compose.yml",
    "phpunit.xml.dist",
    "GPLv2",
    "installer",
    "admin",
)

PRIVATE_MARKERS = (
    "roland",
    "stallboy",
    "mail.0shits.com",
    "keepass",
    "stalwart",
    "jarvis",
    "blindvault",
    "mensbs",
    "agorapress_addons",
)


@pytest.fixture(scope="module")
def changelog_text() -> str:
    assert CHANGELOG.is_file(), "Missing CHANGELOG.md"
    return CHANGELOG.read_text(encoding="utf-8")


@pytest.fixture(scope="module")
def archive_text() -> str:
    assert ARCHIVE.is_file(), "Missing CHANGELOG-archive.md"
    return ARCHIVE.read_text(encoding="utf-8")


def test_changelog_exists() -> None:
    assert CHANGELOG.is_file()


def test_archive_exists() -> None:
    assert ARCHIVE.is_file()


def test_changelog_is_not_a_stub(changelog_text: str) -> None:
    lines = [ln for ln in changelog_text.splitlines() if ln.strip()]
    assert len(lines) >= 15, f"CHANGELOG too short ({len(lines)} non-empty lines)"
    assert len(changelog_text) >= 800, "CHANGELOG should list major product surface"
    # Size cap applies only to the living CHANGELOG.md, not the archive.
    assert len(changelog_text) < 16000, "CHANGELOG should stay concise"


def test_archive_is_not_size_capped(archive_text: str) -> None:
    assert len(archive_text) >= 800, "CHANGELOG-archive.md should keep historical notes"
    assert re.search(r"(?im)^#\s+Changelog archive\s*$", archive_text)


def test_living_changelog_points_at_archive(changelog_text: str) -> None:
    assert "CHANGELOG-archive.md" in changelog_text
    assert "0.3.8-beta" in changelog_text
    assert "0.2.0-beta" in changelog_text
    assert not re.search(r"(?im)^##\s+\[0\.3\.8-beta\]", changelog_text)
    assert not re.search(r"(?im)^##\s+\[0\.2\.0-beta\]", changelog_text)


@pytest.mark.parametrize("pattern", REQUIRED_PATTERNS)
def test_required_pattern(changelog_text: str, pattern: str) -> None:
    assert re.search(pattern, changelog_text), f"Expected pattern: {pattern}"


@pytest.mark.parametrize("phrase", REQUIRED_PHRASES)
def test_required_phrase(changelog_text: str, phrase: str) -> None:
    assert phrase.lower() in changelog_text.lower(), f"Expected phrase in CHANGELOG: {phrase}"


@pytest.mark.parametrize("phrase", ARCHIVE_PHRASES)
def test_archive_required_phrase(archive_text: str, phrase: str) -> None:
    assert phrase.lower() in archive_text.lower(), (
        f"Expected phrase in CHANGELOG-archive.md: {phrase}"
    )


def test_no_placeholder_release_date(changelog_text: str, archive_text: str) -> None:
    for label, text in (("CHANGELOG.md", changelog_text), ("CHANGELOG-archive.md", archive_text)):
        assert not re.search(
            r"(?im)^##\s+\[[^\]]+\]\s+-\s+YYYY-MM-DD\s*$",
            text,
        ), f"Remove placeholder release dates (YYYY-MM-DD) in {label}"


def test_unreleased_comes_before_any_versioned_section(changelog_text: str) -> None:
    unreleased = re.search(r"(?im)^##\s+\[Unreleased\]\s*$", changelog_text)
    assert unreleased, "Missing ## [Unreleased]"
    versioned = re.search(
        r"(?im)^##\s+\[\d+\.\d+\.\d+[^\]]*\]\s+-\s+\d{4}-\d{2}-\d{2}\s*$",
        changelog_text,
    )
    if versioned:
        assert unreleased.start() < versioned.start(), (
            "[Unreleased] must appear before the first dated version section"
        )


def test_mentions_core_version_constant(changelog_text: str) -> None:
    assert "AP_VERSION" in changelog_text
    if VERSION_PHP.is_file():
        version_php = VERSION_PHP.read_text(encoding="utf-8")
        match = re.search(
            r"define\s*\(\s*['\"]AP_VERSION['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)",
            version_php,
        )
        assert match, "ap-includes/version.php should define AP_VERSION"
        ap_version = match.group(1)
        assert ap_version in changelog_text, (
            f"CHANGELOG should mention current AP_VERSION ({ap_version})"
        )
        if "-dev" in ap_version or "dev" in ap_version.lower():
            assert re.search(
                r"(?i)0\.1\.\d+-dev|no tagged public release|unreleased",
                changelog_text,
            ), (
                "While AP_VERSION is a -dev build, CHANGELOG should note unreleased / pre-release status"
            )


def test_020_beta_documents_local_analytics(archive_text: str) -> None:
    assert re.search(r"(?im)^##\s+\[0\.2\.0-beta\]", archive_text)
    lower = archive_text.lower()
    assert "local" in lower and "analytics" in lower
    assert "analytics_enabled" in lower
    assert "analytics_hits" in lower or "analytics_daily" in lower


UNRELEASED_DOC_PATHS = (
    "docs/install.md",
    "docs/updates.md",
    "docs/rewrites.md",
    "docs/cli.md",
    "docs/admin.md",
    "docs/forums.md",
    "docs/roles.md",
    "docs/rest.md",
    "docs/security.md",
    "docs/troubleshooting.md",
    "docs/bot_handbook.md",
    "docs/features_and_functions.md",
)


def _unreleased_body(changelog_text: str) -> str:
    match = re.search(
        r"(?ims)^##\s+\[Unreleased\]\s*\n(.*?)(?=^##\s+\[|\Z)",
        changelog_text,
    )
    assert match, "Missing ## [Unreleased] body"
    return match.group(1)


def test_unreleased_section_remains(changelog_text: str) -> None:
    body = _unreleased_body(changelog_text)
    assert re.search(r"(?im)^###\s+Added\s*$", body)
    assert re.search(r"(?im)^###\s+Changed\s*$", body)


def test_0313_beta_documents_plugin_zip_installer(changelog_text: str) -> None:
    heading = re.search(
        r"(?im)^##\s+\[0\.3\.13-beta\]\s+-\s+2026-10-31\s*$",
        changelog_text,
    )
    assert heading, "Missing dated ## [0.3.13-beta] - 2026-10-31 heading"
    body = _section_body(changelog_text, "0.3.13-beta")
    assert re.search(r"(?im)^###\s+Package\s*$", body)
    assert re.search(r"(?im)^###\s+Added\s*$", body)
    assert re.search(r"(?im)^###\s+Changed\s*$", body)
    assert "0.3.13-beta" in body
    assert "package-release.php" in body.lower()
    lower = body.lower()
    for phrase in (
        "ap_plugin_installer",
        "ap_plugin_install_result",
        "ap_plugin_install_error",
        "ap_validate_plugin_zip",
        "ap_install_plugin",
        "ap_restore_previous_plugin",
        "ap_list_installed_plugins",
        "install_plugins",
        "expected_sha256",
        "overwrite",
        "activate after install",
        "plugin-upload",
        "pluginzip",
        "plugin-overwrite",
        "restore-plugin_",
        ".ap-plugin-staging",
        ".ap-plugin-backups",
        "ap_plugin_zip_max_bytes",
        "ap_plugin_zip_max_entries",
        "ap_plugin_zip_max_uncompressed_bytes",
        "ap_plugin_zip_max_backups",
        "ap_plugin_before_install",
        "ap_plugin_after_install",
        "ap_plugin_before_upgrade",
        "ap_plugin_after_upgrade",
        "ap_plugin_before_restore",
        "ap_plugin_after_restore",
        "ap_plugin_before_activate",
        "ap_plugin_after_activate",
        "ap_plugin_before_deactivate",
        "ap_plugin_after_deactivate",
        "ap_plugin_installed",
        "installfromzip",
        "default_max_bytes",
        "20 mib",
        "40 mib",
        "128 mib",
        "5000",
        "plugin name",
        "version",
        "symlink",
        "magic bytes",
        "docs/admin.md",
        "docs/plugins.md",
        "ap_db_version",
        "no telemetry",
        "php ap-cli plugin install",
    ):
        assert phrase in lower, f"[0.3.13-beta] should mention {phrase}"
    assert "**14**" in body or " 14" in body


def test_0313_beta_documents_profile_reauthentication(changelog_text: str) -> None:
    body = _section_body(changelog_text, "0.3.13-beta")
    assert re.search(r"(?im)^###\s+Security\s*$", body), (
        "[0.3.13-beta] should have a Security heading"
    )
    security = re.search(
        r"(?ims)^###\s+Security\s*\n(.*?)(?=^###\s+|\Z)",
        body,
    )
    assert security, "Missing ### Security body"
    section = security.group(1)
    lower = section.lower()
    for phrase in (
        "current password",
        "current_password",
        "autocomplete=\"current-password\"",
        "password_verify",
        "enter your current password to change your password or email.",
        "your current password is incorrect.",
        "too many attempts, try again in n minutes.",
        "rate_limit_current_password_max",
        "rate_limit_current_password_window",
        "rate_limit_current_password_lockout",
        "ap_current_password_rate_limits",
        "edit_users",
        "activity_log",
        "user_email",
        "remember-me",
        "ap_account_notice",
        "ap_password_changed",
        "ap_email_changed",
        "self",
        "admin",
        "docs/security.md",
        "profile-re-authentication",
    ):
        assert phrase in lower, f"[0.3.13-beta] Security should mention {phrase}"


def test_0313_beta_documents_security_fixes(changelog_text: str) -> None:
    body = _section_body(changelog_text, "0.3.13-beta")
    assert re.search(r"(?im)^###\s+Security\s*$", body)
    assert re.search(r"(?im)^###\s+Fixed\s*$", body)
    fixed = re.search(
        r"(?ims)^###\s+Fixed\s*\n(.*?)(?=^###\s+|^##\s+\[|\Z)",
        body,
    )
    assert fixed, "Missing ### Fixed body"
    security = re.search(
        r"(?ims)^###\s+Security\s*\n(.*?)(?=^###\s+|\Z)",
        body,
    )
    assert security, "Missing ### Security body"
    for section, label in ((fixed.group(1), "Fixed"), (security.group(1), "Security")):
        lower = section.lower()
        assert "ap-001" in lower, f"[0.3.13-beta] {label} should mention AP-001"
        assert "ap-002" in lower, f"[0.3.13-beta] {label} should mention AP-002"
        assert "ap-003" in lower, f"[0.3.13-beta] {label} should mention AP-003"
        assert "login lockout" in lower or "login" in lower
        assert "javascript:" in lower
        assert "script-src 'none'" in lower
        assert "nosniff" in lower
        assert "password-protected" in lower or "password protected" in lower
        assert "json-ld" in lower
        assert "sitemap" in lower


def _section_body(text: str, version: str) -> str:
    match = re.search(
        rf"(?ims)^##\s+\[{re.escape(version)}\][^\n]*\n(.*?)(?=^##\s+\[|\Z)",
        text,
    )
    assert match, f"Missing ## [{version}] body"
    return match.group(1)


def test_037_beta_documents_charter_and_docs_pass(archive_text: str) -> None:
    body = _section_body(archive_text, "0.3.7-beta")
    assert re.search(r"(?im)^###\s+Added\s*$", body)
    assert re.search(r"(?im)^###\s+Changed\s*$", body)
    lower = body.lower()
    assert "documentation pass" in lower
    assert "0.3.7-beta" in body
    assert "docs/readme.md" in lower
    assert "audience index" in lower
    assert "docs/index.md" in lower
    for path in UNRELEASED_DOC_PATHS:
        assert path.lower() in lower, f"[0.3.7-beta] should mention {path}"


def test_changelog_contains_no_private_markers(
    changelog_text: str, archive_text: str
) -> None:
    for label, text in (("CHANGELOG.md", changelog_text), ("CHANGELOG-archive.md", archive_text)):
        lower = text.lower()
        for banned in PRIVATE_MARKERS:
            assert banned not in lower, f"{label} must not contain private marker: {banned}"


def test_038_beta_documents_absolute_mail_links(archive_text: str) -> None:
    body = _section_body(archive_text, "0.3.8-beta")
    lower = body.lower()
    assert "loginactionurl" in lower
    assert "ap_admin::url" in lower
    assert "siteurl" in lower
    assert "ap-admin/login.php" in lower
    assert "verifyemail" in lower
    assert "verification" in lower and "reset" in lower


def test_current_ap_version_is_0313_beta() -> None:
    version_php = VERSION_PHP.read_text(encoding="utf-8")
    match = re.search(
        r"define\s*\(\s*['\"]AP_VERSION['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)",
        version_php,
    )
    assert match, "ap-includes/version.php should define AP_VERSION"
    assert match.group(1) == "0.3.13-beta", (
        "AP_VERSION must be 0.3.13-beta for this release "
        f"(found {match.group(1)})"
    )


def test_0311_beta_documents_charter_surfaces(changelog_text: str) -> None:
    heading = re.search(
        r"(?im)^##\s+\[0\.3\.11-beta\]\s+-\s+\d{4}-\d{2}-\d{2}\s*$",
        changelog_text,
    )
    assert heading, "Missing dated ## [0.3.11-beta] heading"
    body = _section_body(changelog_text, "0.3.11-beta")
    assert re.search(r"(?im)^###\s+Added\s*$", body)
    assert re.search(r"(?im)^###\s+Changed\s*$", body)
    assert re.search(r"(?im)^###\s+Fixed\s*$", body)
    lower = body.lower()
    assert "ap_db_version" in lower
    assert "**13**" in body or " 13" in body
    assert "no telemetry" in lower
    assert "ap_forum_move_topic" in lower
    assert "ap_forum_merge_topic" in lower
    assert "ap_forum_split_topic" in lower
    assert "ap_forum_report_post" in lower
    assert "move_topics" in lower
    assert "moderate_forum" in lower
    assert "view_forum" in lower
    assert "mergetopics" in lower
    assert "topic_subscriptions" in lower
    assert "refreshforumlastpost" in lower
    assert "last_post_id" in lower
    assert "last_topic_id" in lower
    assert "last_poster_id" in lower
    assert "last_post_time" in lower
    assert "shadow" in lower
    assert "reports" in lower
    assert "guests" in lower
    assert "0.3.11-beta" in body
    assert "package-release.php" in lower


def test_0310_beta_documents_charter_surfaces(changelog_text: str) -> None:
    heading = re.search(
        r"(?im)^##\s+\[0\.3\.10-beta\]\s+-\s+\d{4}-\d{2}-\d{2}\s*$",
        changelog_text,
    )
    assert heading, "Missing dated ## [0.3.10-beta] heading"
    body = _section_body(changelog_text, "0.3.10-beta")
    assert re.search(r"(?im)^###\s+Added\s*$", body)
    assert re.search(r"(?im)^###\s+Changed\s*$", body)
    assert re.search(r"(?im)^###\s+Fixed\s*$", body)
    lower = body.lower()
    assert "ap_db_version" in lower
    assert "**13**" in body or " 13" in body
    assert "spoiler" in lower
    assert "ap-spoiler" in lower
    assert "edit_others_posts" in lower
    assert "edit_others_pages" in lower
    assert "topic_subscriptions" in lower
    assert "forum_notify_max_per_minute" in lower
    assert "rate_limit_mail" in lower
    assert "ap_comments_template" in lower
    assert "no auto-inject" in lower
    assert "no telemetry" in lower
    assert "post_author" in lower
    assert "topic_track" in lower
    assert "0.3.10-beta" in body


def test_039_beta_documents_charter_surfaces(changelog_text: str) -> None:
    heading = re.search(
        r"(?im)^##\s+\[0\.3\.9-beta\]\s+-\s+\d{4}-\d{2}-\d{2}\s*$",
        changelog_text,
    )
    assert heading, "Missing dated ## [0.3.9-beta] heading"
    body = _section_body(changelog_text, "0.3.9-beta")
    assert re.search(r"(?im)^###\s+Added\s*$", body)
    assert re.search(r"(?im)^###\s+Changed\s*$", body)
    assert re.search(r"(?im)^###\s+Fixed\s*$", body)
    lower = body.lower()
    assert "ap_db_version" in lower
    assert "**12**" in body or " 12" in body
    assert "set as default" in lower
    assert "agora_visitor_color_preview" in lower
    assert "agora_scheme" in lower
    assert "ensuredefaultcategory" in lower
    assert "default_category" in lower
    assert "color-scheme" in lower
    assert "posted in" in lower
    assert "uncategorized" in lower
    assert "no telemetry" in lower
