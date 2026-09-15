"""
SPEC-minimum tests for the 0.3.10-beta charter plus 0.3.11-beta last-post,
move, merge, split, and report cases must exist and pass.

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
        "tests/Forum/ForumLastPostTest.php",
        "testTwoTopicsDeleteNewestThenBothLeavesOlderThenEmptyWithoutDeadPermalink",
    ),
    (
        "tests/Forum/ForumLastPostTest.php",
        "testTwoTopicsForceDeleteNewestThenBothLeavesOlderThenEmptyWithoutDeadPermalink",
    ),
    (
        "tests/Forum/ForumMoveTopicTest.php",
        "testMoveTopicUpdatesDestCounters",
    ),
    (
        "tests/Forum/ForumMoveTopicTest.php",
        "testMoveTopicRefusesCategory",
    ),
    (
        "tests/Forum/ForumMoveTopicTest.php",
        "testMoveTopicRefusesMissingDestCap",
    ),
    (
        "tests/Forum/ForumMoveTopicTest.php",
        "testMoveTopicKeepsSlugUnchanged",
    ),
    (
        "tests/Forum/ForumMergeTopicTest.php",
        "testMergeTopicsMovesPostsOntoTarget",
    ),
    (
        "tests/Forum/ForumMergeTopicTest.php",
        "testMergeTopicsRemovesSourceTopic",
    ),
    (
        "tests/Forum/ForumMergeTopicTest.php",
        "testMergeTopicsRetargetsSubscriptions",
    ),
    (
        "tests/Forum/ForumMergeTopicTest.php",
        "testMergeTopicsRefreshesLastPost",
    ),
    (
        "tests/Forum/ForumSplitTopicTest.php",
        "testSplitTopicMovesSelectedPostsOntoNewTopic",
    ),
    (
        "tests/Forum/ForumSplitTopicTest.php",
        "testSplitTopicKeepsAtLeastOnePostOnOriginal",
    ),
    (
        "tests/Forum/ForumSplitTopicTest.php",
        "testSplitTopicRefreshesLastPost",
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
    php_pairs = re.findall(
        r"'((?:tests/)[^']+Test\.php)',\s*'(test[A-Za-z0-9]+)'",
        src,
    )
    assert php_pairs == SPEC_PHPUNIT, (
        "tests/test_charter_spec.py SPEC_PHPUNIT must match "
        "CharterSpecTest::specMinimumCaseProvider"
    )


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
    # SPEC methods + one existence row per method + discovery check.
    expected = len(SPEC_PHPUNIT) * 2 + 1
    assert ran >= expected, (
        f"Expected at least {expected} charter tests "
        f"(SPEC methods + existence rows + discovery), ran {ran}"
    )
