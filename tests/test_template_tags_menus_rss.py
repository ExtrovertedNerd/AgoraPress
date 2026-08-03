"""
Smoke tests for template tags, menus, RSS/Atom feeds, and front-page settings.

Runnable via:
  pytest tests/test_template_tags_menus_rss.py -v
"""

from __future__ import annotations

import shutil
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
TEMPLATE_TAGS = ROOT / "ap-includes" / "template-tags.php"
NAV_MENU = ROOT / "ap-includes" / "class-ap-nav-menu.php"
FEED = ROOT / "ap-includes" / "class-ap-feed.php"
OPTIONS = ROOT / "ap-includes" / "class-ap-options.php"
FUNCTIONS = ROOT / "ap-includes" / "functions.php"
BOOTSTRAP = ROOT / "ap-includes" / "bootstrap.php"
INDEX = ROOT / "index.php"
NAV_ADMIN = ROOT / "ap-admin" / "nav-menus.php"
READING_ADMIN = ROOT / "ap-admin" / "options-reading.php"
FRONT_PAGE = ROOT / "ap-content" / "themes" / "agora" / "front-page.php"
HEADER = ROOT / "ap-content" / "themes" / "agora" / "header.php"
FOOTER = ROOT / "ap-content" / "themes" / "agora" / "footer.php"
STRUCTURE = ROOT / "tests" / "Structure" / "assert-structure.php"


def _php_bin() -> str:
    return shutil.which("php") or "php"


def test_core_files_exist() -> None:
    for path in (
        TEMPLATE_TAGS,
        NAV_MENU,
        FEED,
        OPTIONS,
        NAV_ADMIN,
        READING_ADMIN,
        FRONT_PAGE,
        HEADER,
        FOOTER,
    ):
        assert path.is_file(), f"Missing {path.relative_to(ROOT)}"


def test_template_tags_api() -> None:
    src = TEMPLATE_TAGS.read_text(encoding="utf-8")
    for needle in (
        "function ap_get_the_title",
        "function ap_the_title",
        "function ap_get_the_content",
        "function ap_the_content",
        "function ap_get_the_excerpt",
        "function ap_get_the_permalink",
        "function ap_get_the_date",
        "function ap_get_the_author",
        "function ap_get_bloginfo",
        "function ap_get_body_class",
        "function ap_body_class",
        "function ap_sanitize_html_class",
        "rss2_url",
        "front-page",
    ):
        assert needle in src, f"Expected {needle!r} in template-tags.php"


def test_nav_menu_api() -> None:
    src = NAV_MENU.read_text(encoding="utf-8")
    for needle in (
        "class AP_Nav_Menu",
        "function registerLocation",
        "function registerLocations",
        "function saveMenu",
        "function deleteMenu",
        "function hasNavMenu",
        "function render",
        "function itemUrl",
        "function itemTitle",
        "function isItemVisible",
        "function getPublishedPages",
        "function fallbackPrimary",
        "function fallbackFooter",
        "function getUsefulLinks",
        "function usefulLinkTypes",
        "privacy_policy",
        "function locationsFromAdminPost",
        "function mergeMenuLocationCheckboxes",
        "function getLocationsForMenu",
        "OPTION_MENUS",
        "OPTION_LOCATIONS",
        "theme_location",
        "menu-item-type-page",
        "add_useful",
    ):
        assert needle in src, f"Expected {needle!r} in class-ap-nav-menu.php"

    fn = FUNCTIONS.read_text(encoding="utf-8")
    for needle in (
        "function ap_register_nav_menu",
        "function ap_register_nav_menus",
        "function ap_has_nav_menu",
        "function ap_nav_menu",
        "function ap_nav_menu_fallback_primary",
        "function ap_nav_menu_fallback_footer",
        "function ap_save_nav_menu",
        "function ap_set_nav_menu_locations",
    ):
        assert needle in fn, f"Expected {needle!r} in functions.php"

    admin = NAV_ADMIN.read_text(encoding="utf-8")
    for needle in (
        "save_locations",
        "Manage Locations",
        "menu_location",
        "Display location",
        "tab",
        "locations",
        "add_page",
        "add_useful",
        "Useful links",
        "Login / Account",
        "Privacy Policy",
        "post_type' => 'page'",
        "post_status' => 'publish'",
    ):
        assert needle in admin, f"Expected {needle!r} in nav-menus.php"

    footer = FOOTER.read_text(encoding="utf-8")
    assert "fallbackFooter" in footer or "ap_nav_menu_fallback_footer" in footer
    assert "theme_location" in footer
    assert "footer" in footer


def test_feed_api() -> None:
    src = FEED.read_text(encoding="utf-8")
    for needle in (
        "class AP_Feed",
        "TYPE_RSS2",
        "TYPE_ATOM",
        "function normalizeType",
        "function isFeedRequest",
        "function serve",
        "function buildRss2",
        "function buildAtom",
        "function fetchPosts",
        "posts_per_rss",
        "rss_use_excerpt",
    ):
        assert needle in src, f"Expected {needle!r} in class-ap-feed.php"

    fn = FUNCTIONS.read_text(encoding="utf-8")
    assert "function ap_get_feed_link" in fn


def test_reading_settings_api() -> None:
    src = OPTIONS.read_text(encoding="utf-8")
    for needle in (
        "function showOnFront",
        "function pageOnFront",
        "function pageForPosts",
        "function postsPerPage",
        "function postsPerRss",
        "function rssUseExcerpt",
        "function updateReadingSettings",
        "show_on_front",
        "page_on_front",
        "page_for_posts",
    ):
        assert needle in src, f"Expected {needle!r} in class-ap-options.php"

    admin = READING_ADMIN.read_text(encoding="utf-8")
    assert "updateReadingSettings" in admin
    assert "show_on_front" in admin
    assert "posts_per_rss" in admin


def test_bootstrap_and_front_controller_wire_features() -> None:
    boot = BOOTSTRAP.read_text(encoding="utf-8")
    assert "class-ap-options.php" in boot
    assert "class-ap-nav-menu.php" in boot
    assert "class-ap-feed.php" in boot
    assert "template-tags.php" in boot

    index = INDEX.read_text(encoding="utf-8")
    assert "AP_Feed::isFeedRequest" in index
    assert "AP_Feed::serve" in index


def test_theme_uses_menus_and_feeds() -> None:
    header = HEADER.read_text(encoding="utf-8")
    assert "ap_nav_menu" in header
    assert "fallback_cb" in header or "fallbackPrimary" in header
    assert "application/rss+xml" in header
    assert "primary" in header

    footer = FOOTER.read_text(encoding="utf-8")
    assert "footer" in footer
    assert "ap_nav_menu" in footer
    assert "ap_get_feed_link" in footer

    front = FRONT_PAGE.read_text(encoding="utf-8")
    assert "is_front_page" in front


def test_structure_assert_includes_new_paths() -> None:
    src = STRUCTURE.read_text(encoding="utf-8")
    for needle in (
        "template-tags.php",
        "class-ap-nav-menu.php",
        "class-ap-feed.php",
        "nav-menus.php",
        "options-reading.php",
        "front-page.php",
    ):
        assert needle in src, f"Expected {needle!r} in assert-structure.php"


def test_phpunit_new_suites_pass() -> None:
    phpunit = ROOT / "vendor" / "bin" / "phpunit"
    if not phpunit.is_file():
        # Composer deps may be absent in some environments.
        return
    result = subprocess.run(
        [
            str(phpunit),
            "--filter",
            "TemplateTagsTest|NavMenuTest|FeedTest|ReadingSettingsTest|AgoraThemeTest",
        ],
        cwd=ROOT,
        capture_output=True,
        text=True,
        check=False,
    )
    if result.returncode != 0:
        sys.stderr.write(result.stdout)
        sys.stderr.write(result.stderr)
    assert result.returncode == 0, "PHPUnit template/menu/feed/reading tests failed"
