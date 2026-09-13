"""
CI must stay hard-fail. Do not mute jobs or hide tests to look green.

Not a charter to zero PHPCS warnings. Line-length stays advisory.

Runnable via:
  pytest tests/test_ci_hygiene.py -v
"""

from __future__ import annotations

import re
from pathlib import Path

from test_charter_spec import SPEC_PHPUNIT

ROOT = Path(__file__).resolve().parents[1]
CI_YML = ROOT / ".github" / "workflows" / "ci.yml"
PHPUNIT_XML = ROOT / "phpunit.xml.dist"


def test_github_workflow_hard_fails_phpunit_phpcs_phpstan() -> None:
    assert CI_YML.is_file(), "Missing .github/workflows/ci.yml"
    text = CI_YML.read_text(encoding="utf-8")
    assert re.search(r"continue-on-error\s*:\s*true", text, flags=re.I) is None
    assert "composer test" in text
    assert "composer cs:check" in text
    assert "composer analyse" in text
    assert '"8.2"' in text
    assert '"8.3"' in text
    assert '"8.4"' in text


def test_phpunit_keeps_fail_on_risky_and_does_not_exclude_tests() -> None:
    text = PHPUNIT_XML.read_text(encoding="utf-8")
    assert 'failOnRisky="true"' in text
    assert 'failOnWarning="true"' in text
    assert "<exclude" not in text
    assert '<directory suffix="Test.php">tests</directory>' in text


def test_charter_spec_methods_are_not_skipped() -> None:
    for relative, method in SPEC_PHPUNIT:
        path = ROOT / relative
        src = path.read_text(encoding="utf-8")
        assert f"function {method}" in src
        match = re.search(
            rf"function\s+{re.escape(method)}\s*\([^)]*\)\s*(?::\s*\w+\s*)?\{{(.*?)"
            rf"(?=\n    public function |\n\}}\s*\Z)",
            src,
            flags=re.S,
        )
        assert match, f"Could not isolate {method} in {relative}"
        assert "markTestSkipped" not in match.group(1), (
            f"{relative}::{method} must not skip to look green"
        )
