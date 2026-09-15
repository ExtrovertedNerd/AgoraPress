"""
SPEC-minimum tests for the 0.3.10-beta charter plus 0.3.11-beta report
cases (one open report; guest cannot; duplicate refused) must exist and pass.

Runnable via:
  pytest tests/test_charter_spec.py -v
"""

from __future__ import annotations

import re
import shutil
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

# SPEC “Tests (minimum)” → suite file + method.
SPEC_PHPUNIT: list[tuple[str, str]] = [
    (
        "tests/Content/ContentFormatTest.php",
        "testSpoilerFormatMarkupToDetails",
    ),
    (
        "tests/Content/ContentFormatTest.php",
        "testSpoilerEmptyBodyRendersNothing",
    ),
    (
        "tests/Content/ContentFormatTest.php",
        "testSpoilerDefaultLabelAndTitleAttribute",
    ),
    (
        "tests/Template/TemplateTagsTest.php",
        "testExcerptStripsSpoilerInnerText",
    ),
    (
        "tests/Feed/FeedTest.php",
        "testRssAndAtomStripSpoilerInnerText",
    ),
    (
        "tests/Editor/EditorTest.php",
        "testSpoilerButtonWrapsVisualAndInsertsShortcodeInText",
    ),
    (
        "tests/Editor/EditorTest.php",
        "testSpoilerToolbarIsSharedOnPostPageCommentForum",
    ),
    (
        "tests/Admin/AdminPostsTest.php",
        "testCreateAsOtherAuthor",
    ),
    (
        "tests/Admin/AdminPostsTest.php",
        "testUpdateAsOtherAuthor",
    ),
    (
        "tests/Admin/AdminPostsTest.php",
        "testNoCapCannotReassignAuthor",
    ),
    (
        "tests/Admin/AdminPostsTest.php",
        "testCraftedPostAuthorIgnored",
    ),
    (
        "tests/Forum/ForumNotifyEnqueueTest.php",
        "testSiteOffReplyPostDoesNotEnqueue",
    ),
    (
        "tests/Forum/ForumNotifyWorkerTest.php",
        "testUserOffWorkerDoesNotSend",
    ),
    (
        "tests/Forum/ForumNotifyWorkerTest.php",
        "testPosterExcludedFromWorkerMail",
    ),
    (
        "tests/Forum/ForumNotifyWorkerTest.php",
        "testWorkerDropsLostViewForum",
    ),
    (
        "tests/Database/TopicSubscriptionsMigrationTest.php",
        "testUniqueConstraintAndAddRemovePair",
    ),
    (
        "tests/Forum/ForumNotifyWorkerTest.php",
        "testSignedTokenUnsubscribesOneTopicWithoutSession",
    ),
    (
        "tests/Forum/ForumNotifyWorkerTest.php",
        "testWorkerLeavesRateLimitMailAlone",
    ),
    (
        "tests/Database/TopicSubscriptionsMigrationTest.php",
        "testMigrateFromSchema12CreatesSubscriptionsTable",
    ),
    (
        "tests/Database/TopicSubscriptionsMigrationTest.php",
        "testUpIsIdempotentWhenTableAlreadyExists",
    ),
    (
        "tests/Comment/CommentsTemplateTest.php",
        "testNoThemeFileStillRendersFallbackForm",
    ),
    (
        "tests/Comment/CommentsTemplateTest.php",
        "testNonSingularPrintsEmptyCommentsMarkup",
    ),
    (
        "tests/Comment/CommentsTemplateTest.php",
        "testAgoraSingleRendersExactlyOneCommentForm",
    ),
    (
        "tests/Forum/ForumReportPostTest.php",
        "testLoggedInMemberCreatesOneOpenPostReport",
    ),
    (
        "tests/Forum/ForumReportPostTest.php",
        "testGuestCannotReportPost",
    ),
    (
        "tests/Forum/ForumReportPostTest.php",
        "testDuplicateOpenReportRefused",
    ),
]

CHARTER_SUITES = sorted({relative for relative, _ in SPEC_PHPUNIT})
CHARTER_META = "tests/Integration/CharterSpecTest.php"


def _php_bin() -> str:
    return shutil.which("php") or "php"


def _spec_phpunit_filter() -> str:
    """Match SPEC methods exactly; do not also run *Extra siblings."""
    methods = sorted({method for _, method in SPEC_PHPUNIT})
    exact = "|".join(re.escape(name) + "$" for name in methods)
    return (
        "(?:"
        + exact
        + "|testSpecMinimumCaseExists|testPhpunitDiscoversSpecMinimumCases)"
    )


def test_charter_spec_phpunit_methods_exist() -> None:
    for relative, method in SPEC_PHPUNIT:
        path = ROOT / relative
        assert path.is_file(), f"Missing {relative}"
        src = path.read_text(encoding="utf-8")
        needle = f"function {method}"
        assert needle in src, f"Expected {needle!r} in {relative}"
    meta = ROOT / CHARTER_META
    assert meta.is_file(), f"Missing {CHARTER_META}"
    src = meta.read_text(encoding="utf-8")
    assert "function testSpecMinimumCaseExists" in src
    assert "function testPhpunitDiscoversSpecMinimumCases" in src


def test_charter_spec_phpunit_suites_pass() -> None:
    phpunit = ROOT / "vendor" / "bin" / "phpunit"
    if not phpunit.is_file():
        return
    cmd = [
        _php_bin(),
        str(phpunit),
        "--configuration",
        str(ROOT / "phpunit.xml.dist"),
        "--colors=never",
        "--filter",
        _spec_phpunit_filter(),
        *[str(ROOT / relative) for relative in CHARTER_SUITES],
        str(ROOT / CHARTER_META),
    ]
    proc = subprocess.run(
        cmd,
        cwd=str(ROOT),
        capture_output=True,
        text=True,
        timeout=180,
    )
    if proc.returncode != 0:
        sys.stderr.write(proc.stdout + "\n" + proc.stderr)
    assert proc.returncode == 0, "Charter SPEC PHPUnit suites failed"
    summary = re.search(r"^OK \((\d+) tests?", proc.stdout, flags=re.M)
    assert summary, "Charter PHPUnit produced no OK summary:\n" + proc.stdout
    ran = int(summary.group(1))
    # SPEC methods + CharterSpecTest existence rows + discovery check.
    assert ran >= len(SPEC_PHPUNIT), (
        f"Expected at least {len(SPEC_PHPUNIT)} charter tests, ran {ran}"
    )
