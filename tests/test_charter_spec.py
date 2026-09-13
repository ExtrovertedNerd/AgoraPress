"""
SPEC-minimum tests for the 0.3.9-beta charter must exist and pass.

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
        "tests/Taxonomy/TaxonomyTest.php",
        "testEnsureDefaultCategoryCreatesUncategorizedWhenNoLivingDefault",
    ),
    (
        "tests/Taxonomy/TaxonomyTest.php",
        "testEnsureDefaultCategoryDoesNotClobberLivingDefault",
    ),
    (
        "tests/Taxonomy/TaxonomyTest.php",
        "testUncategorizedIsDeletableOnceItIsNotTheDefault",
    ),
    (
        "tests/Taxonomy/TaxonomyTest.php",
        "testLastRemainingCategoryCannotBeDeleted",
    ),
    (
        "tests/Taxonomy/TaxonomyTest.php",
        "testCurrentDefaultCannotBeDeletedWhenAnotherCategoryExists",
    ),
    (
        "tests/Admin/AdminTermsTest.php",
        "testSetAsDefaultThenUncategorizedDeleteReassignsOrphans",
    ),
    (
        "tests/Admin/AdminTermsTest.php",
        "testRowDeleteOfDefaultCategoryReturnsHonestMessage",
    ),
    (
        "tests/Options/SettingsApiTest.php",
        "testUpdateWritingSettingsPersistsLivingTermId",
    ),
    (
        "tests/Options/SettingsApiTest.php",
        "testUpdateWritingSettingsZeroResolvesToLivingTermId",
    ),
    (
        "tests/Options/SettingsApiTest.php",
        "testWritingDefaultThenUncategorizedDeleteReassignsOrphans",
    ),
    (
        "tests/Template/TemplateTagsTest.php",
        "testCategoryListSkipsEmptyNames",
    ),
    (
        "tests/Template/TemplateTagsTest.php",
        "testCategoryListIsEmptyWhenAllNamesAreEmpty",
    ),
    (
        "tests/Theme/AgoraThemeTest.php",
        "testVisitorPreviewControlAbsentByDefault",
    ),
    (
        "tests/Theme/AgoraThemeTest.php",
        "testVisitorPreviewQueryAppliesMidnightWithoutWritingOption",
    ),
    (
        "tests/Theme/AgoraThemeTest.php",
        "testPreviewCookieWinsOverSiteOption",
    ),
    (
        "tests/Theme/AgoraThemeTest.php",
        "testInvalidPreviewSlugIgnoredThenOptionThenMarble",
    ),
    (
        "tests/Theme/AgoraThemeTest.php",
        "testDefaultSchemeIsMarble",
    ),
    (
        "tests/Theme/AgoraThemeTest.php",
        "testSixSchemesKeepEditorContrastWithoutAddons",
    ),
    (
        "tests/Editor/EditorTest.php",
        "testContrastFixtureWithoutAgoraStylesheet",
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
