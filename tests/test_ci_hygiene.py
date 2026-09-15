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
PHPSTAN_NEON = ROOT / "phpstan.neon.dist"
PHPCS_XML = ROOT / "phpcs.xml.dist"
FIXTURES = ROOT / "tests" / "Integration" / "fixtures"


def _paths_from_fixture(name: str) -> list[str]:
    text = (FIXTURES / name).read_text(encoding="utf-8")
    paths = [
        line.strip()
        for line in text.splitlines()
        if line.strip() and not line.strip().startswith("#")
    ]
    assert paths, f"{name} must list last-release tests"
    return paths


def test_github_workflow_hard_fails_phpunit_phpcs_phpstan() -> None:
    assert CI_YML.is_file(), "Missing .github/workflows/ci.yml"
    text = CI_YML.read_text(encoding="utf-8")
    assert re.search(r"continue-on-error", text, flags=re.I) is None
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


def test_does_not_delete_preexisting_phpunit_suites() -> None:
    missing = [
        relative
        for relative in _paths_from_fixture("last-release-phpunit-suites.txt")
        if not (ROOT / relative).is_file()
    ]
    assert missing == [], (
        "Do not delete pre-existing PHPUnit suites to look green: "
        + ", ".join(missing)
    )


def test_does_not_delete_preexisting_pytest_files() -> None:
    missing = [
        relative
        for relative in _paths_from_fixture("last-release-pytest-files.txt")
        if not (ROOT / relative).is_file()
    ]
    assert missing == [], (
        "Do not delete pre-existing pytest files to look green: "
        + ", ".join(missing)
    )


def test_last_release_fixture_cannot_shrink_or_empty_suites() -> None:
    phpunit = _paths_from_fixture("last-release-phpunit-suites.txt")
    pytest_files = _paths_from_fixture("last-release-pytest-files.txt")
    assert phpunit == list(dict.fromkeys(phpunit)), (
        "Do not pad the last-release PHPUnit lock with duplicate rows"
    )
    assert pytest_files == list(dict.fromkeys(pytest_files)), (
        "Do not pad the last-release pytest lock with duplicate rows"
    )
    assert len(phpunit) >= 127, (
        "Do not shrink the PHPUnit lock (v0.3.10-beta + 0.3.11 charter) to look green"
    )
    assert len(pytest_files) >= 102, (
        "Do not shrink the pytest lock (v0.3.10-beta + 0.3.11 charter) to look green"
    )
    for required in (
        "tests/Comment/CommentsTemplateTest.php",
        "tests/Content/SpoilerStyleTest.php",
        "tests/Database/TopicSubscriptionsMigrationTest.php",
        "tests/Forum/ForumNotifyConfigTest.php",
        "tests/Forum/ForumNotifyEnqueueTest.php",
        "tests/Forum/ForumNotifySubscriptionsTest.php",
        "tests/Forum/ForumNotifyWorkerTest.php",
        "tests/Forum/ForumLastPostTest.php",
        "tests/Forum/ForumMoveTopicTest.php",
        "tests/Forum/ForumMergeTopicTest.php",
        "tests/Forum/ForumSplitTopicTest.php",
        "tests/Forum/ForumReportPostTest.php",
    ):
        assert required in phpunit, (
            f"Do not drop PHPUnit suite {required} to look green"
        )
    for required in (
        "tests/test_forum_notify_config.py",
        "tests/test_forum_notify_enqueue.py",
        "tests/test_forum_notify_worker.py",
        "tests/test_topic_subscriptions.py",
        "tests/test_forum_last_post.py",
        "tests/test_forum_move_topic.py",
        "tests/test_forum_merge_topic.py",
        "tests/test_forum_split_topic.py",
        "tests/test_forum_report_post.py",
    ):
        assert required in pytest_files, (
            f"Do not drop pytest file {required} to look green"
        )
    for relative in phpunit:
        src = (ROOT / relative).read_text(encoding="utf-8")
        assert re.search(r"function\s+test", src, flags=re.I), (
            f"Do not empty last-release suite {relative} to look green"
        )
    for relative in pytest_files:
        src = (ROOT / relative).read_text(encoding="utf-8")
        assert re.search(r"^def test_", src, flags=re.M), (
            f"Do not empty last-release pytest file {relative} to look green"
        )


def test_phpstan_does_not_hide_core_or_raise_level_to_look_green() -> None:
    text = PHPSTAN_NEON.read_text(encoding="utf-8")
    assert re.search(r"^    ignoreErrors:", text, flags=re.M) is None, (
        "Do not add PHPStan ignoreErrors as a substitute CI-green charter"
    )
    assert "reportUnmatchedIgnoredErrors: true" in text
    assert "- ap-includes\n" in text
    assert "- ap-includes/compatibility/*" in text
    assert "class-ap-forum-notify" not in text


def test_phpcs_does_not_exclude_product_paths_to_look_green() -> None:
    text = PHPCS_XML.read_text(encoding="utf-8")
    assert "<exclude-pattern>*/vendor/*</exclude-pattern>" in text
    assert "<exclude-pattern>*/ap-content/*</exclude-pattern>" in text
    assert "<exclude-pattern>*/.hephaestus/*</exclude-pattern>" in text
    assert "<exclude-pattern>*/node_modules/*</exclude-pattern>" in text
    assert "ap-includes/*" not in text
    assert "*/tests/*" not in text


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
