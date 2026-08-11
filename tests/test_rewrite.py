"""
Smoke tests for AgoraPress AP_Rewrite (permalinks / rewrite rules).

Runnable via:
  pytest tests/test_rewrite.py -v
"""

from __future__ import annotations

import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
REWRITE_CLASS = ROOT / "ap-includes" / "class-ap-rewrite.php"
FUNCTIONS = ROOT / "ap-includes" / "functions.php"
BOOTSTRAP = ROOT / "ap-includes" / "bootstrap.php"
STRUCTURE = ROOT / "tests" / "Structure" / "assert-structure.php"
HTACCESS = ROOT / ".htaccess"
NGINX = ROOT / "docker" / "nginx.conf.example"
INDEX = ROOT / "index.php"


def _php_bin() -> str:
    return shutil.which("php") or "php"


def test_rewrite_files_exist() -> None:
    assert REWRITE_CLASS.is_file(), "Missing class-ap-rewrite.php"
    assert HTACCESS.is_file()
    assert NGINX.is_file()


def test_rewrite_class_defines_core_api() -> None:
    src = REWRITE_CLASS.read_text(encoding="utf-8")
    for needle in (
        "class AP_Rewrite",
        "function usingPermalinks",
        "function getStructure",
        "function setStructure",
        "function generateRules",
        "function flushRules",
        "function parseRequest",
        "function parseFromGlobals",
        "function getPermalink",
        "function getPageLink",
        "function getTermLink",
        "function getAuthorLink",
        "function getSearchLink",
        "function homeUrl",
        "function apacheRewriteBlock",
        "STRUCTURE_POST_NAME",
        "STRUCTURE_DAY_NAME",
        "STRUCTURE_NUMERIC",
        "%postname%",
        "category_base",
        "tag_base",
    ):
        assert needle in src, f"Expected {needle!r} in class-ap-rewrite.php"


def test_functions_expose_rewrite_helpers() -> None:
    src = FUNCTIONS.read_text(encoding="utf-8")
    for needle in (
        "function ap_using_permalinks",
        "function ap_get_permalink_structure",
        "function ap_set_permalink_structure",
        "function ap_flush_rewrite_rules",
        "function ap_parse_request",
        "function ap_home_url",
        "function ap_site_url",
        "function ap_get_permalink",
        "function ap_get_page_link",
        "function ap_get_term_link",
        "function ap_get_author_link",
        "function ap_get_search_link",
    ):
        assert needle in src, f"Expected {needle!r} in functions.php"


def test_bootstrap_loads_rewrite_class() -> None:
    src = BOOTSTRAP.read_text(encoding="utf-8")
    assert "class-ap-rewrite.php" in src


def test_structure_assert_lists_rewrite_class() -> None:
    src = STRUCTURE.read_text(encoding="utf-8")
    assert "class-ap-rewrite.php" in src


def test_index_parses_request() -> None:
    src = INDEX.read_text(encoding="utf-8")
    assert "ap_parse_request" in src
    assert "queryFromVars" in src or "AP_Rewrite" in src


def test_htaccess_and_nginx_front_controller() -> None:
    ht = HTACCESS.read_text(encoding="utf-8")
    assert "RewriteEngine On" in ht
    assert "index.php" in ht
    assert "sqlite" in ht.lower()
    assert "ap-includes" in ht
    # Existing files (manual root favicon.ico) are not rewritten to index.php.
    assert "REQUEST_FILENAME} !-f" in ht
    assert "favicon.ico" in ht
    nginx = NGINX.read_text(encoding="utf-8")
    assert "try_files" in nginx
    assert "try_files $uri" in nginx
    assert "index.php" in nginx
    assert "sqlite" in nginx.lower()
    assert "favicon.ico" in nginx


def test_rewrite_runtime_via_php() -> None:
    """Runtime: structure presets, parse, permalink, hierarchical page."""
    root = str(ROOT)
    code = (
        "<?php\ndeclare(strict_types=1);\n"
        f"$root = {repr(root)};\n"
        "require $root . '/ap-includes/version.php';\n"
        "require $root . '/ap-includes/class-ap-db.php';\n"
        "require $root . '/ap-includes/class-ap-migrator.php';\n"
        "require $root . '/ap-includes/class-ap-post.php';\n"
        "require $root . '/ap-includes/class-ap-taxonomy.php';\n"
        "require $root . '/ap-includes/class-ap-query.php';\n"
        "require $root . '/ap-includes/class-ap-rewrite.php';\n"
        "require $root . '/ap-includes/functions.php';\n"
        "AP_Post::resetRegistry();\n"
        "AP_Taxonomy::resetRegistry();\n"
        "AP_Rewrite::resetCache();\n"
        "$pdo = new PDO('sqlite::memory:', null, null, [\n"
        "  PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,\n"
        "  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,\n"
        "]);\n"
        "$db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');\n"
        "(new AP_Migrator($db, AP_Migrator::defaultMigrationsPath()))->migrate();\n"
        "AP_Post::ensureBuiltins();\n"
        "AP_Taxonomy::ensureBuiltins();\n"
        "$db->insert('options', ['option_name'=>'home','option_value'=>'https://ex.test','autoload'=>'yes']);\n"
        "$db->insert('options', ['option_name'=>'siteurl','option_value'=>'https://ex.test','autoload'=>'yes']);\n"
        "$db->insert('options', ['option_name'=>'permalink_structure','option_value'=>'','autoload'=>'yes']);\n"
        "if (AP_Rewrite::usingPermalinks($db)) { fwrite(STDERR, \"plain default fail\\n\"); exit(1); }\n"
        "$id = AP_Post::insert([\n"
        "  'post_title'=>'Hello','post_type'=>'post','post_status'=>'publish',\n"
        "  'post_name'=>'hello','post_date'=>'2020-08-03 12:00:00',\n"
        "], $db);\n"
        "$plain = AP_Rewrite::getPermalink($id, $db);\n"
        "if ($plain !== 'https://ex.test/?p=' . $id) {\n"
        "  fwrite(STDERR, \"plain permalink fail: $plain\\n\"); exit(1);\n"
        "}\n"
        "AP_Rewrite::setStructure(AP_Rewrite::STRUCTURE_POST_NAME, $db);\n"
        "if (!AP_Rewrite::usingPermalinks($db)) { fwrite(STDERR, \"pretty fail\\n\"); exit(1); }\n"
        "$pretty = AP_Rewrite::getPermalink($id, $db);\n"
        "if ($pretty !== 'https://ex.test/hello/') {\n"
        "  fwrite(STDERR, \"pretty permalink fail: $pretty\\n\"); exit(1);\n"
        "}\n"
        "$vars = AP_Rewrite::parseRequest('hello', [], $db);\n"
        "if (($vars['name'] ?? '') !== 'hello') {\n"
        "  fwrite(STDERR, 'parse fail: ' . json_encode($vars) . \"\\n\"); exit(1);\n"
        "}\n"
        "$q = AP_Rewrite::queryFromVars($vars, $db);\n"
        "if ($q->post_count !== 1 || $q->posts[0]->ID !== $id) {\n"
        "  fwrite(STDERR, \"queryFromVars fail\\n\"); exit(1);\n"
        "}\n"
        "AP_Rewrite::setStructure(AP_Rewrite::STRUCTURE_DAY_NAME, $db);\n"
        "$day = AP_Rewrite::getPermalink($id, $db);\n"
        "if ($day !== 'https://ex.test/2020/08/03/hello/') {\n"
        "  fwrite(STDERR, \"day structure fail: $day\\n\"); exit(1);\n"
        "}\n"
        "$parent = AP_Post::insert([\n"
        "  'post_title'=>'Parent','post_type'=>'page','post_status'=>'publish','post_name'=>'parent',\n"
        "], $db);\n"
        "$child = AP_Post::insert([\n"
        "  'post_title'=>'Child','post_type'=>'page','post_status'=>'publish',\n"
        "  'post_name'=>'child','post_parent'=>$parent,\n"
        "], $db);\n"
        "AP_Rewrite::setStructure(AP_Rewrite::STRUCTURE_POST_NAME, $db);\n"
        "$pageUrl = AP_Rewrite::getPageLink($child, $db);\n"
        "if ($pageUrl !== 'https://ex.test/parent/child/') {\n"
        "  fwrite(STDERR, \"page link fail: $pageUrl\\n\"); exit(1);\n"
        "}\n"
        "$pvars = AP_Rewrite::parseRequest('parent/child', [], $db);\n"
        "if (($pvars['pagename'] ?? '') !== 'parent/child') {\n"
        "  fwrite(STDERR, 'pagename parse fail: ' . json_encode($pvars) . \"\\n\"); exit(1);\n"
        "}\n"
        "if (ap_home_url('', $db) !== 'https://ex.test') {\n"
        "  fwrite(STDERR, \"home_url fail\\n\"); exit(1);\n"
        "}\n"
        "if (!ap_using_permalinks($db)) { fwrite(STDERR, \"helper fail\\n\"); exit(1); }\n"
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
