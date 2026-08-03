"""
Smoke tests for AgoraPress post statuses, types, and hierarchical pages.

Runnable via:
  pytest tests/test_post_model.py -v
"""

from __future__ import annotations

import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
POST_CLASS = ROOT / "ap-includes" / "class-ap-post.php"
FUNCTIONS = ROOT / "ap-includes" / "functions.php"
BOOTSTRAP = ROOT / "ap-includes" / "bootstrap.php"
STRUCTURE = ROOT / "tests" / "Structure" / "assert-structure.php"


def _php_bin() -> str:
    return shutil.which("php") or "php"


def test_post_files_exist() -> None:
    assert POST_CLASS.is_file(), "Missing class-ap-post.php"
    assert FUNCTIONS.is_file(), "Missing functions.php"


def test_post_class_defines_core_api() -> None:
    src = POST_CLASS.read_text(encoding="utf-8")
    for needle in (
        "class AP_Post",
        "function registerStatus",
        "function registerType",
        "function ensureBuiltins",
        "function insert",
        "function update",
        "function trash",
        "function untrash",
        "function delete",
        "function getChildren",
        "function getAncestorIds",
        "function getPagePath",
        "function getTree",
        "function wouldCreateCycle",
        "function sanitizeSlug",
        "function uniqueSlug",
        "function typeIsHierarchical",
        "function saveRevision",
        "function autosave",
        "function getRevisions",
        "function restoreRevision",
        "function getAutosave",
        "REVISION_FIELDS",
        "'publish'",
        "'page'",
        "post_parent",
    ):
        assert needle in src, f"Expected {needle!r} in class-ap-post.php"


def test_functions_expose_post_helpers() -> None:
    src = FUNCTIONS.read_text(encoding="utf-8")
    for needle in (
        "function ap_register_post_status",
        "function ap_register_post_type",
        "function ap_insert_post",
        "function ap_update_post",
        "function ap_get_post",
        "function ap_trash_post",
        "function ap_delete_post",
        "function ap_get_page_path",
        "function ap_get_page_tree",
        "function ap_is_post_type_hierarchical",
        "function ap_sanitize_title",
        "function ap_get_post_meta",
        "function ap_save_post_revision",
        "function ap_autosave_post",
        "function ap_get_post_revisions",
        "function ap_restore_post_revision",
        "function ap_get_post_autosave",
    ):
        assert needle in src, f"Expected {needle!r} in functions.php"


def test_bootstrap_loads_post_class() -> None:
    src = BOOTSTRAP.read_text(encoding="utf-8")
    assert "class-ap-post.php" in src
    assert "AP_Post::ensureBuiltins" in src


def test_structure_assert_lists_post_class() -> None:
    src = STRUCTURE.read_text(encoding="utf-8")
    assert "class-ap-post.php" in src


def test_insert_hierarchy_and_statuses_via_php() -> None:
    """Runtime: insert page hierarchy, statuses, cycle prevention."""
    root = str(ROOT)
    code = (
        "<?php\ndeclare(strict_types=1);\n"
        f"$root = {repr(root)};\n"
        "require $root . '/ap-includes/version.php';\n"
        "require $root . '/ap-includes/class-ap-db.php';\n"
        "require $root . '/ap-includes/class-ap-migrator.php';\n"
        "require $root . '/ap-includes/class-ap-post.php';\n"
        "AP_Post::resetRegistry();\n"
        "$pdo = new PDO('sqlite::memory:', null, null, [\n"
        "  PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,\n"
        "  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,\n"
        "]);\n"
        "$db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');\n"
        "(new AP_Migrator($db, AP_Migrator::defaultMigrationsPath()))->migrate();\n"
        "if (!AP_Post::statusExists('publish') || !AP_Post::typeIsHierarchical('page')) {\n"
        "  fwrite(STDERR, \"builtins missing\\n\"); exit(1);\n"
        "}\n"
        "$rootId = AP_Post::insert([\n"
        "  'post_title' => 'Parent', 'post_type' => 'page', 'post_status' => 'publish',\n"
        "], $db);\n"
        "$childId = AP_Post::insert([\n"
        "  'post_title' => 'Child', 'post_type' => 'page', 'post_status' => 'publish',\n"
        "  'post_parent' => $rootId,\n"
        "], $db);\n"
        "if ($rootId < 1 || $childId < 1) { fwrite(STDERR, \"insert failed\\n\"); exit(1); }\n"
        "$path = AP_Post::getPagePath($childId, $db);\n"
        "if ($path !== 'parent/child') { fwrite(STDERR, \"path=$path\\n\"); exit(1); }\n"
        "if (!AP_Post::wouldCreateCycle($rootId, $childId, $db)) {\n"
        "  fwrite(STDERR, \"cycle not detected\\n\"); exit(1);\n"
        "}\n"
        "if (AP_Post::update($rootId, ['post_parent' => $childId], $db)) {\n"
        "  fwrite(STDERR, \"cycle allowed\\n\"); exit(1);\n"
        "}\n"
        "AP_Post::trash($childId, $db);\n"
        "if (AP_Post::get($childId, $db)->post_status !== 'trash') {\n"
        "  fwrite(STDERR, \"trash failed\\n\"); exit(1);\n"
        "}\n"
        "AP_Post::untrash($childId, $db);\n"
        "if (AP_Post::get($childId, $db)->post_status !== 'publish') {\n"
        "  fwrite(STDERR, \"untrash failed\\n\"); exit(1);\n"
        "}\n"
        "$postId = AP_Post::insert([\n"
        "  'post_title' => 'Blog', 'post_type' => 'post', 'post_status' => 'draft',\n"
        "  'post_content' => 'v1',\n"
        "], $db);\n"
        "if (AP_Post::get($postId, $db)->post_status !== 'draft') {\n"
        "  fwrite(STDERR, \"draft failed\\n\"); exit(1);\n"
        "}\n"
        "AP_Post::update($postId, ['post_content' => 'v2'], $db);\n"
        "if (AP_Post::countRevisions($postId, false, $db) !== 1) {\n"
        "  fwrite(STDERR, \"revision not created\\n\"); exit(1);\n"
        "}\n"
        "$as = AP_Post::autosave($postId, ['post_content' => 'wip'], 1, $db);\n"
        "if ($as < 1 || !AP_Post::isAutosave($as, $db)) {\n"
        "  fwrite(STDERR, \"autosave failed\\n\"); exit(1);\n"
        "}\n"
        "if (AP_Post::get($postId, $db)->post_content !== 'v2') {\n"
        "  fwrite(STDERR, \"autosave mutated parent\\n\"); exit(1);\n"
        "}\n"
        "echo \"OK\\n\";\n"
    )
    with tempfile.NamedTemporaryFile("w", suffix=".php", delete=False) as fh:
        fh.write(code)
        path = fh.name
    try:
        result = subprocess.run(
            [_php_bin(), "-d", "display_errors=1", path],
            cwd=str(ROOT),
            capture_output=True,
            text=True,
            check=False,
        )
    finally:
        Path(path).unlink(missing_ok=True)

    combined = (result.stdout or "") + (result.stderr or "")
    assert result.returncode == 0, combined
    assert "OK" in (result.stdout or "")


if __name__ == "__main__":
    sys.exit(__import__("pytest").main([__file__, "-v"]))
