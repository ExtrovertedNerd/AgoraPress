"""
Smoke tests for README.md (vision summary + quick-start).

Runnable via:
  pytest tests/test_readme.py -v
"""

from __future__ import annotations

import re
from pathlib import Path

import pytest

ROOT = Path(__file__).resolve().parents[1]
README = ROOT / "README.md"

# Headings / sections expected for Phase 0 acceptance of this task.
REQUIRED_HEADINGS = (
    r"(?im)^#\s+AgoraPress\s*$",
    r"(?im)^##\s+Vision summary\s*$",
    r"(?im)^##\s+Requirements\s*$",
    r"(?im)^##\s+Quick start\s*$",
    r"(?im)^##\s+Project layout\s*$",
    r"(?im)^##\s+Development\s*$",
    r"(?im)^##\s+License\s*$",
)

# Vision / product pillars that must be discoverable from the public README.
REQUIRED_PHRASES = (
    "free forever",
    "no telemetry",
    "Classic WordPress Theme Compatibility",
    "Static Pages",
    "Blog",
    "Forum",
    "docker compose",
    "ap-config-sample.php",
    "ap_",
    "PHP 8.2",
    "GPLv2",
    "0.3.4-beta",
    "Tools → Analytics",
    "analytics_enabled",
)


@pytest.fixture(scope="module")
def readme_text() -> str:
    assert README.is_file(), "Missing README.md"
    return README.read_text(encoding="utf-8")


def test_readme_exists() -> None:
    assert README.is_file()


def test_readme_is_not_a_stub(readme_text: str) -> None:
    """README must be a real vision + quick-start doc, not a one-liner."""
    lines = [ln for ln in readme_text.splitlines() if ln.strip()]
    assert len(lines) >= 40, f"README too short ({len(lines)} non-empty lines)"
    assert len(readme_text) >= 2000, "README expected to include vision summary and quick-start"


@pytest.mark.parametrize("pattern", REQUIRED_HEADINGS)
def test_required_heading(readme_text: str, pattern: str) -> None:
    assert re.search(pattern, readme_text), f"Expected heading matching: {pattern}"


@pytest.mark.parametrize("phrase", REQUIRED_PHRASES)
def test_required_phrase(readme_text: str, phrase: str) -> None:
    assert phrase.lower() in readme_text.lower(), f"Expected phrase in README: {phrase}"


def test_quick_start_mentions_localhost_port(readme_text: str) -> None:
    assert "localhost:8080" in readme_text or "http://localhost:8080" in readme_text


def test_quick_start_mentions_docker_compose_up(readme_text: str) -> None:
    assert re.search(r"docker\s+compose\s+up", readme_text, re.I), (
        "Quick start should show docker compose up"
    )


def test_mentions_table_prefix_default(readme_text: str) -> None:
    assert re.search(r"\bap_\b", readme_text), "Default table prefix ap_ should appear"


def test_mentions_three_modules(readme_text: str) -> None:
    text = readme_text.lower()
    for module in ("static pages", "blog", "forum"):
        assert module in text, f"Module toggle mentioned: {module}"


def test_mentions_local_analytics_and_schema_version(readme_text: str) -> None:
    """Current beta docs: opt-in local analytics + schema AP_DB_VERSION ≥ 10."""
    lower = readme_text.lower()
    assert "0.3.4-beta" in readme_text
    assert "ap_db_version" in lower or "AP_DB_VERSION" in readme_text
    # Schema target must match shipped AP_DB_VERSION (currently 12: topic type enum).
    assert re.search(r"\b12\b", readme_text), "Schema target 12 should appear"
    assert "local analytics" in lower or "tools → analytics" in lower
    assert "off by default" in lower or "default off" in lower or "analytics_enabled" in lower
    assert "no third-party" in lower or "third-party" in lower
