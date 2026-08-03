"""
Smoke tests for Phase 7 integration suites.

Runnable via:
  pytest tests/test_integration_suite.py -v
"""

from __future__ import annotations

from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
COEXIST = ROOT / "tests" / "Integration" / "ContentCoexistenceTest.php"
ROLES_CAPS = ROOT / "tests" / "Integration" / "RolesCapsContentTest.php"
HEALTH = ROOT / "tests" / "Integration" / "SuiteHealthTest.php"
PHPUNIT_XML = ROOT / "phpunit.xml.dist"


def test_integration_suite_files_exist() -> None:
    assert COEXIST.is_file(), "Missing ContentCoexistenceTest.php"
    assert ROLES_CAPS.is_file(), "Missing RolesCapsContentTest.php"
    assert HEALTH.is_file(), "Missing SuiteHealthTest.php"
    assert PHPUNIT_XML.is_file()


def test_content_coexistence_covers_modules() -> None:
    src = COEXIST.read_text(encoding="utf-8")
    for needle in (
        "testBlogPageCommentAndForumShareSchema",
        "testModuleTogglesIndependentCombinations",
        "AP_Forum",
        "AP_Post",
        "AP_Comment",
        "updateModules",
    ):
        assert needle in src, f"Expected {needle!r} in ContentCoexistenceTest"


def test_roles_caps_content_integration() -> None:
    src = ROLES_CAPS.read_text(encoding="utf-8")
    for needle in (
        "testRoleCapabilitiesDifferAcrossRoles",
        "testAuthorCanCreateBlogPostSubscriberCannotPublishCap",
        "testSharedUserCanPostInForum",
        "AP_Roles",
        "AP_Forum",
        "publish_posts",
    ):
        assert needle in src, f"Expected {needle!r} in RolesCapsContentTest"


def test_suite_health_lists_critical_areas() -> None:
    src = HEALTH.read_text(encoding="utf-8")
    for needle in (
        "ForumModelTest",
        "ThemeCompatTest",
        "RestApiTest",
        "WxrImporterTest",
        "AssetsTest",
        "MailTest",
        "ContentCoexistenceTest",
        "RolesCapsContentTest",
    ):
        assert needle in src, f"Expected {needle!r} in SuiteHealthTest"
