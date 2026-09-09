"""
Smoke tests for CHANGELOG.md (Keep a Changelog + SemVer, concise).

Runnable via:
  pytest tests/test_changelog.py -v
"""

from __future__ import annotations

import re
from pathlib import Path

import pytest

ROOT = Path(__file__).resolve().parents[1]
CHANGELOG = ROOT / "CHANGELOG.md"
VERSION_PHP = ROOT / "ap-includes" / "version.php"

REQUIRED_PATTERNS = (
    r"(?im)^#\s+Changelog\s*$",
    r"(?im)^##\s+\[Unreleased\]\s*$",
    r"(?im)^###\s+Added\s*$",
    r"keepachangelog\.com",
    r"semver\.org",
    r"Semantic Versioning",
)

# High-level product surface only — not every implementation class.
REQUIRED_PHRASES = (
    "ap-config-sample.php",
    "docker-compose.yml",
    "phpunit.xml.dist",
    "ap-includes",
    "GPLv2",
    "AP_VERSION",
    "installer",
    "forum",
    "admin",
    "no telemetry",
)


@pytest.fixture(scope="module")
def changelog_text() -> str:
    assert CHANGELOG.is_file(), "Missing CHANGELOG.md"
    return CHANGELOG.read_text(encoding="utf-8")


def test_changelog_exists() -> None:
    assert CHANGELOG.is_file()


def test_changelog_is_not_a_stub(changelog_text: str) -> None:
    lines = [ln for ln in changelog_text.splitlines() if ln.strip()]
    assert len(lines) >= 15, f"CHANGELOG too short ({len(lines)} non-empty lines)"
    assert len(changelog_text) >= 800, "CHANGELOG should list major product surface"
    assert len(changelog_text) < 12000, "CHANGELOG should stay concise"


@pytest.mark.parametrize("pattern", REQUIRED_PATTERNS)
def test_required_pattern(changelog_text: str, pattern: str) -> None:
    assert re.search(pattern, changelog_text), f"Expected pattern: {pattern}"


@pytest.mark.parametrize("phrase", REQUIRED_PHRASES)
def test_required_phrase(changelog_text: str, phrase: str) -> None:
    assert phrase.lower() in changelog_text.lower(), f"Expected phrase in CHANGELOG: {phrase}"


def test_no_placeholder_release_date(changelog_text: str) -> None:
    assert not re.search(
        r"(?im)^##\s+\[[^\]]+\]\s+-\s+YYYY-MM-DD\s*$",
        changelog_text,
    ), "Remove placeholder release dates (YYYY-MM-DD) until a real release is cut"


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


def test_020_beta_documents_local_analytics(changelog_text: str) -> None:
    assert re.search(r"(?im)^##\s+\[0\.2\.0-beta\]", changelog_text)
    lower = changelog_text.lower()
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


def _037_beta_body(changelog_text: str) -> str:
    match = re.search(
        r"(?ims)^##\s+\[0\.3\.7-beta\][^\n]*\n(.*?)(?=^##\s+\[|\Z)",
        changelog_text,
    )
    assert match, "Missing ## [0.3.7-beta] body"
    return match.group(1)


def test_037_beta_documents_charter_and_docs_pass(changelog_text: str) -> None:
    body = _037_beta_body(changelog_text)
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


def test_changelog_contains_no_private_markers(changelog_text: str) -> None:
    lower = changelog_text.lower()
    for banned in ("roland", "stallboy", "mail.0shits.com", "keepass", "stalwart"):
        assert banned not in lower, (
            f"CHANGELOG.md must not contain private marker: {banned}"
        )


def test_current_ap_version_is_037_beta() -> None:
    version_php = VERSION_PHP.read_text(encoding="utf-8")
    match = re.search(
        r"define\s*\(\s*['\"]AP_VERSION['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)",
        version_php,
    )
    assert match, "ap-includes/version.php should define AP_VERSION"
    assert match.group(1) == "0.3.7-beta", (
        "AP_VERSION must be 0.3.7-beta for this release "
        f"(found {match.group(1)})"
    )
