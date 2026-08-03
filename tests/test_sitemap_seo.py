"""
Smoke tests for XML sitemaps, canonicals, and Open Graph.

Runnable via:
  pytest tests/test_sitemap_seo.py -v
"""

from __future__ import annotations

import shutil
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SITEMAP = ROOT / "ap-includes" / "class-ap-sitemap.php"
SEO = ROOT / "ap-includes" / "class-ap-seo.php"
REWRITE = ROOT / "ap-includes" / "class-ap-rewrite.php"
FUNCTIONS = ROOT / "ap-includes" / "functions.php"
BOOTSTRAP = ROOT / "ap-includes" / "bootstrap.php"
INDEX = ROOT / "index.php"
INSTALLER = ROOT / "ap-includes" / "class-ap-installer.php"
STRUCTURE = ROOT / "tests" / "Structure" / "assert-structure.php"
PHPUNIT_TEST = ROOT / "tests" / "Seo" / "SitemapSeoTest.php"
CHANGELOG = ROOT / "CHANGELOG.md"


def _php_bin() -> str:
    return shutil.which("php") or "php"


def test_core_files_exist() -> None:
    for path in (SITEMAP, SEO, REWRITE, FUNCTIONS, BOOTSTRAP, INDEX, PHPUNIT_TEST):
        assert path.is_file(), f"Missing {path.name}"


def test_sitemap_api_surface() -> None:
    src = SITEMAP.read_text(encoding="utf-8")
    for needle in (
        "class AP_Sitemap",
        "function isSitemapRequest",
        "function isRobotsRequest",
        "function buildIndex",
        "function buildProvider",
        "function buildRobots",
        "function getSitemapLink",
        "function serve",
        "sitemapindex",
        "urlset",
        "blog_public",
        "sitemap_enabled",
    ):
        assert needle in src, f"Missing in AP_Sitemap: {needle}"


def test_seo_api_surface() -> None:
    src = SEO.read_text(encoding="utf-8")
    for needle in (
        "class AP_Seo",
        "function getCanonicalUrl",
        "function getOpenGraphMeta",
        "function printHeadTags",
        "rel=\"canonical\"",
        "og:title",
        "og:type",
        "og:url",
        "twitter:card",
        "open_graph_enabled",
    ):
        assert needle in src, f"Missing in AP_Seo: {needle}"


def test_rewrite_and_front_controller_wired() -> None:
    rewrite = REWRITE.read_text(encoding="utf-8")
    assert "sitemap\\.xml" in rewrite or "sitemap\\.xml$" in rewrite
    assert "robots\\.txt" in rewrite or "robots\\.txt$" in rewrite
    assert "function matchSeoPath" in rewrite
    assert "function getSitemapLink" in rewrite

    index = INDEX.read_text(encoding="utf-8")
    assert "AP_Sitemap" in index
    assert "isSitemapRequest" in index

    bootstrap = BOOTSTRAP.read_text(encoding="utf-8")
    assert "class-ap-sitemap.php" in bootstrap
    assert "class-ap-seo.php" in bootstrap
    assert "AP_Seo::register" in bootstrap


def test_procedural_helpers() -> None:
    src = FUNCTIONS.read_text(encoding="utf-8")
    for needle in (
        "function ap_get_sitemap_link",
        "function ap_sitemaps_enabled",
        "function ap_get_canonical_url",
        "function ap_get_open_graph_meta",
        "function ap_is_blog_public",
    ):
        assert needle in src, f"Missing helper: {needle}"


def test_installer_seeds_seo_options() -> None:
    src = INSTALLER.read_text(encoding="utf-8")
    assert "'blog_public'" in src or '"blog_public"' in src
    assert "sitemap_enabled" in src
    assert "open_graph_enabled" in src


def test_structure_lists_seo_files() -> None:
    src = STRUCTURE.read_text(encoding="utf-8")
    assert "class-ap-sitemap.php" in src
    assert "class-ap-seo.php" in src


def test_changelog_mentions_sitemaps() -> None:
    text = CHANGELOG.read_text(encoding="utf-8")
    assert "sitemap" in text.lower()
    assert "open graph" in text.lower() or "Open Graph" in text


def test_php_syntax() -> None:
    for path in (SITEMAP, SEO, PHPUNIT_TEST):
        r = subprocess.run(
            [_php_bin(), "-l", str(path)],
            capture_output=True,
            text=True,
            check=False,
        )
        assert r.returncode == 0, f"php -l failed for {path.name}: {r.stderr or r.stdout}"


def test_phpunit_sitemap_seo() -> None:
    phpunit = ROOT / "vendor" / "bin" / "phpunit"
    if not phpunit.is_file():
        # Fall back to composer script if present.
        r = subprocess.run(
            [_php_bin(), str(ROOT / "vendor" / "phpunit" / "phpunit" / "phpunit"), str(PHPUNIT_TEST)],
            cwd=str(ROOT),
            capture_output=True,
            text=True,
            check=False,
        )
        if r.returncode != 0 and "Could not open" in (r.stderr or ""):
            return  # PHPUnit not installed in this environment
        assert r.returncode == 0, r.stdout + r.stderr
        return

    r = subprocess.run(
        [str(phpunit), str(PHPUNIT_TEST), "--colors=never"],
        cwd=str(ROOT),
        capture_output=True,
        text=True,
        check=False,
    )
    assert r.returncode == 0, r.stdout + r.stderr


if __name__ == "__main__":
    # Allow `python tests/test_sitemap_seo.py` quick path.
    failed = 0
    for name, fn in list(globals().items()):
        if name.startswith("test_") and callable(fn):
            try:
                fn()
                print(f"OK  {name}")
            except AssertionError as e:
                failed += 1
                print(f"FAIL {name}: {e}", file=sys.stderr)
    sys.exit(1 if failed else 0)
