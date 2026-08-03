"""
Smoke tests for AgoraPress taxonomies (categories, tags, custom).

Runnable via:
  pytest tests/test_taxonomies.py -v
"""

from __future__ import annotations

import shutil
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
INCLUDES = ROOT / "ap-includes"
ADMIN = ROOT / "ap-admin"
FUNCTIONS = ROOT / "ap-includes" / "functions.php"
BOOTSTRAP = ROOT / "ap-includes" / "bootstrap.php"
VERSION = ROOT / "ap-includes" / "version.php"
MIGRATIONS = ROOT / "ap-includes" / "schema" / "migrations"
STRUCTURE = ROOT / "tests" / "Structure" / "assert-structure.php"


def _php_bin() -> str:
    return shutil.which("php") or "php"


def test_taxonomy_files_exist() -> None:
    required = [
        INCLUDES / "class-ap-taxonomy.php",
        MIGRATIONS / "0003_core_terms_taxonomies.php",
        ADMIN / "edit-tags.php",
        ADMIN / "includes" / "class-ap-admin-terms.php",
        ROOT / "tests" / "Taxonomy" / "TaxonomyTest.php",
        ROOT / "tests" / "Database" / "TermsTaxonomyMigrationTest.php",
    ]
    for path in required:
        assert path.is_file(), f"Missing {path.relative_to(ROOT)}"


def test_taxonomy_class_api_surface() -> None:
    src = (INCLUDES / "class-ap-taxonomy.php").read_text(encoding="utf-8")
    for needle in (
        "class AP_Taxonomy",
        "function register",
        "function ensureBuiltins",
        "function insertTerm",
        "function updateTerm",
        "function deleteTerm",
        "function getTerm",
        "function getTermBySlug",
        "function getTerms",
        "function getTermTree",
        "function setObjectTerms",
        "function getObjectTerms",
        "function removeObjectTerms",
        "function getObjectsInTerm",
        "function ensureDefaultCategory",
        "function uniqueTermSlug",
        "'category'",
        "'post_tag'",
        "UNCATEGORIZED_SLUG",
    ):
        assert needle in src, f"Expected {needle!r} in AP_Taxonomy"


def test_procedural_taxonomy_helpers() -> None:
    src = FUNCTIONS.read_text(encoding="utf-8")
    for fn in (
        "function ap_register_taxonomy",
        "function ap_get_taxonomy",
        "function ap_taxonomy_exists",
        "function ap_insert_term",
        "function ap_update_term",
        "function ap_delete_term",
        "function ap_get_term",
        "function ap_get_terms",
        "function ap_set_object_terms",
        "function ap_get_object_terms",
        "function ap_set_post_categories",
        "function ap_set_post_tags",
        "function ap_get_post_categories",
        "function ap_get_post_tags",
        "function ap_ensure_default_category",
    ):
        assert fn in src, f"Expected {fn!r} in functions.php"


def test_bootstrap_loads_taxonomy() -> None:
    src = BOOTSTRAP.read_text(encoding="utf-8")
    assert "class-ap-taxonomy.php" in src
    assert "AP_Taxonomy::ensureBuiltins" in src


def test_db_version_is_at_least_three() -> None:
    src = VERSION.read_text(encoding="utf-8")
    # Taxonomies ship at schema v3; later migrations may bump further.
    assert "AP_DB_VERSION" in src
    import re

    m = re.search(r"define\('AP_DB_VERSION',\s*'(\d+)'\)", src)
    assert m is not None
    assert int(m.group(1)) >= 3


def test_migration_0003_surface() -> None:
    mig = MIGRATIONS / "0003_core_terms_taxonomies.php"
    src = mig.read_text(encoding="utf-8")
    for needle in (
        "AP_Migration_0003_Core_Terms_Taxonomies",
        "terms",
        "term_taxonomy",
        "term_relationships",
        "term_id",
        "taxonomy",
        "object_id",
        "ENGINE=InnoDB",
        "BIGSERIAL",
        "AUTOINCREMENT",
    ):
        assert needle in src, f"Expected {needle} in 0003 migration"


def test_query_supports_taxonomy_vars() -> None:
    src = (INCLUDES / "class-ap-query.php").read_text(encoding="utf-8")
    for needle in (
        "'cat'",
        "'category_name'",
        "'category__in'",
        "'tag'",
        "'tag_id'",
        "'tax_query'",
        "is_category",
        "is_tag",
        "is_tax",
        "buildTaxonomyWhere",
    ):
        assert needle in src, f"Expected {needle!r} in AP_Query"


def test_admin_terms_surface() -> None:
    terms = (ADMIN / "includes" / "class-ap-admin-terms.php").read_text(
        encoding="utf-8"
    )
    for needle in (
        "class AP_Admin_Terms",
        "function save",
        "function delete",
        "function bulkDelete",
        "function renderAddForm",
        "function renderEditForm",
        "function renderListTable",
        "function renderCategoryChecklist",
        "function renderTagsInput",
    ):
        assert needle in terms, f"Expected {needle!r} in admin terms"

    edit = (ADMIN / "edit-tags.php").read_text(encoding="utf-8")
    assert "AP_Admin_Terms" in edit
    assert "taxonomy" in edit

    menu = (ADMIN / "includes" / "class-ap-admin.php").read_text(encoding="utf-8")
    assert "categories" in menu
    assert "edit-tags.php" in menu


def test_structure_script_lists_taxonomy() -> None:
    src = STRUCTURE.read_text(encoding="utf-8")
    assert "class-ap-taxonomy.php" in src
    assert "edit-tags.php" in src
    assert "class-ap-admin-terms.php" in src


def test_phpunit_taxonomy_suite_runs() -> None:
    """Run TaxonomyTest via PHPUnit when available."""
    phpunit = ROOT / "vendor" / "bin" / "phpunit"
    if not phpunit.is_file():
        return
    proc = subprocess.run(
        [
            _php_bin(),
            str(phpunit),
            "--configuration",
            str(ROOT / "phpunit.xml.dist"),
            str(ROOT / "tests" / "Taxonomy" / "TaxonomyTest.php"),
            str(ROOT / "tests" / "Database" / "TermsTaxonomyMigrationTest.php"),
        ],
        cwd=str(ROOT),
        capture_output=True,
        text=True,
        timeout=120,
    )
    if proc.returncode != 0:
        sys.stderr.write(proc.stdout + "\n" + proc.stderr)
    assert proc.returncode == 0, "Taxonomy PHPUnit suite failed"
