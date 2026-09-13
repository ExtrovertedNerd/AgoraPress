"""
CI must stay hard-fail. Do not mute jobs or hide tests to look green.

Not a charter to zero PHPCS warnings. Line-length stays advisory.

Runnable via:
  pytest tests/test_ci_hygiene.py -v
"""

from __future__ import annotations

import json
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


def test_phpcs_line_length_warnings_remain_advisory() -> None:
    path = ROOT / "phpcs.xml.dist"
    text = path.read_text(encoding="utf-8")
    assert re.search(
        r'<config\s+name="ignore_warnings_on_exit"\s+value="1"\s*/>',
        text,
    ), "Advisory PHPCS warnings must not fail CI"
    assert "Generic.Files.LineLength" in text
    assert "lineLimit" in text


def test_phpstan_level_is_not_raised_as_a_ci_green_charter() -> None:
    text = (ROOT / "phpstan.neon.dist").read_text(encoding="utf-8")
    assert re.search(r"^    level:\s*3\s*$", text, flags=re.M), (
        "Do not raise PHPStan level as a substitute CI-green charter"
    )


def test_composer_scripts_do_not_swallow_failures_or_hide_suites() -> None:
    composer = json.loads((ROOT / "composer.json").read_text(encoding="utf-8"))
    scripts = composer["scripts"]
    for name in ("test", "cs:check", "analyse"):
        cmd = scripts[name]
        if isinstance(cmd, list):
            cmd = "\n".join(cmd)
        assert "|| true" not in cmd, f"composer {name} must not swallow failures"
        assert "--filter" not in cmd, f"composer {name} must not hide suites"
        assert re.search(r"--exclude[^-]", cmd) is None, (
            f"composer {name} must not exclude tests to look green"
        )
    assert scripts["test"] == "phpunit"
    assert scripts["cs:check"] == "phpcs"
    assert "phpstan analyse" in scripts["analyse"]


def test_github_workflow_run_steps_do_not_swallow_failures() -> None:
    text = CI_YML.read_text(encoding="utf-8")
    assert "|| true" not in text
    assert re.search(r"composer test\s+--", text) is None


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
