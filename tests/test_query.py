"""
Smoke tests for AgoraPress AP_Query (WP_Query-inspired content query).

Runnable via:
  pytest tests/test_query.py -v
"""

from __future__ import annotations

import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
QUERY_CLASS = ROOT / "ap-includes" / "class-ap-query.php"
FUNCTIONS = ROOT / "ap-includes" / "functions.php"
BOOTSTRAP = ROOT / "ap-includes" / "bootstrap.php"
STRUCTURE = ROOT / "tests" / "Structure" / "assert-structure.php"


def _php_bin() -> str:
    return shutil.which("php") or "php"


def test_query_files_exist() -> None:
    assert QUERY_CLASS.is_file(), "Missing class-ap-query.php"


def test_query_class_defines_core_api() -> None:
    src = QUERY_CLASS.read_text(encoding="utf-8")
    for needle in (
        "class AP_Query",
        "function query",
        "function parseQuery",
        "function getPosts",
        "function havePosts",
        "function thePost",
        "function rewindPosts",
        "function nextPost",
        "function getPosts",
        "posts_per_page",
        "found_posts",
        "max_num_pages",
        "is_single",
        "is_page",
        "is_search",
        "is_home",
        "is_404",
        "post__in",
        "meta_key",
    ):
        assert needle in src, f"Expected {needle!r} in class-ap-query.php"


def test_functions_expose_query_helpers() -> None:
    src = FUNCTIONS.read_text(encoding="utf-8")
    for needle in (
        "function ap_query",
        "function ap_set_query",
        "function ap_have_posts",
        "function ap_the_post",
        "function ap_rewind_posts",
        "function ap_get_queried_post",
    ):
        assert needle in src, f"Expected {needle!r} in functions.php"


def test_bootstrap_loads_query_class() -> None:
    src = BOOTSTRAP.read_text(encoding="utf-8")
    assert "class-ap-query.php" in src


def test_structure_assert_lists_query_class() -> None:
    src = STRUCTURE.read_text(encoding="utf-8")
    assert "class-ap-query.php" in src


def test_query_runtime_via_php() -> None:
    """Runtime: pagination, loop, search, pagename path."""
    root = str(ROOT)
    code = (
        "<?php\ndeclare(strict_types=1);\n"
        f"$root = {repr(root)};\n"
        "require $root . '/ap-includes/version.php';\n"
        "require $root . '/ap-includes/class-ap-db.php';\n"
        "require $root . '/ap-includes/class-ap-migrator.php';\n"
        "require $root . '/ap-includes/class-ap-post.php';\n"
        "require $root . '/ap-includes/class-ap-query.php';\n"
        "require $root . '/ap-includes/functions.php';\n"
        "AP_Post::resetRegistry();\n"
        "$pdo = new PDO('sqlite::memory:', null, null, [\n"
        "  PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,\n"
        "  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,\n"
        "]);\n"
        "$db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');\n"
        "(new AP_Migrator($db, AP_Migrator::defaultMigrationsPath()))->migrate();\n"
        "AP_Post::ensureBuiltins();\n"
        "for ($i = 1; $i <= 5; $i++) {\n"
        "  AP_Post::insert([\n"
        "    'post_title' => \"Post $i\", 'post_type' => 'post',\n"
        "    'post_status' => 'publish', 'post_content' => \"body apple $i\",\n"
        "  ], $db);\n"
        "}\n"
        "$q = new AP_Query([\n"
        "  'post_type' => 'post', 'posts_per_page' => 2, 'paged' => 2,\n"
        "  'orderby' => 'ID', 'order' => 'ASC',\n"
        "], $db);\n"
        "if ($q->post_count !== 2 || $q->found_posts !== 5 || $q->max_num_pages !== 3) {\n"
        "  fwrite(STDERR, \"pagination fail count={$q->post_count} found={$q->found_posts}\\n\");\n"
        "  exit(1);\n"
        "}\n"
        "$titles = [];\n"
        "while ($q->havePosts()) { $q->thePost(); $titles[] = $q->post->post_title; }\n"
        "if ($titles !== ['Post 3', 'Post 4']) {\n"
        "  fwrite(STDERR, 'loop fail: ' . json_encode($titles) . \"\\n\"); exit(1);\n"
        "}\n"
        "$search = new AP_Query(['s' => 'apple', 'posts_per_page' => 10], $db);\n"
        "if (!$search->is_search || $search->post_count !== 5) {\n"
        "  fwrite(STDERR, \"search fail {$search->post_count}\\n\"); exit(1);\n"
        "}\n"
        "$parent = AP_Post::insert([\n"
        "  'post_title' => 'Parent', 'post_type' => 'page', 'post_status' => 'publish',\n"
        "  'post_name' => 'parent',\n"
        "], $db);\n"
        "$child = AP_Post::insert([\n"
        "  'post_title' => 'Child', 'post_type' => 'page', 'post_status' => 'publish',\n"
        "  'post_name' => 'child', 'post_parent' => $parent,\n"
        "], $db);\n"
        "$pageQ = new AP_Query(['pagename' => 'parent/child'], $db);\n"
        "if ($pageQ->post_count !== 1 || $pageQ->posts[0]->ID !== $child || !$pageQ->is_page) {\n"
        "  fwrite(STDERR, \"pagename fail\\n\"); exit(1);\n"
        "}\n"
        "ap_set_query($q);\n"
        "ap_rewind_posts();\n"
        "if (!ap_have_posts()) { fwrite(STDERR, \"ap_have_posts fail\\n\"); exit(1); }\n"
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
