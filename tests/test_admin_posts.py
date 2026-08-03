"""
Smoke tests for AgoraPress admin posts/pages list + edit screens.

Runnable via:
  pytest tests/test_admin_posts.py -v
"""

from __future__ import annotations

import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
ADMIN = ROOT / "ap-admin"
INCLUDES = ROOT / "ap-includes"
STRUCTURE = ROOT / "tests" / "Structure" / "assert-structure.php"
BOOTSTRAP = ROOT / "ap-includes" / "bootstrap.php"
FUNCTIONS = ROOT / "ap-includes" / "functions.php"


def _php_bin() -> str:
    return shutil.which("php") or "php"


def test_admin_files_exist() -> None:
    required = [
        ADMIN / "index.php",
        ADMIN / "edit.php",
        ADMIN / "post.php",
        ADMIN / "post-new.php",
        ADMIN / "revision.php",
        ADMIN / "login.php",
        ADMIN / "admin-bootstrap.php",
        ADMIN / "admin-header.php",
        ADMIN / "admin-footer.php",
        ADMIN / "css" / "admin.css",
        ADMIN / "includes" / "class-ap-admin.php",
        ADMIN / "includes" / "class-ap-posts-list-table.php",
        ADMIN / "includes" / "class-ap-admin-post-edit.php",
        INCLUDES / "class-ap-nonce.php",
    ]
    for path in required:
        assert path.is_file(), f"Missing {path.relative_to(ROOT)}"


def test_admin_classes_define_core_api() -> None:
    list_src = (ADMIN / "includes" / "class-ap-posts-list-table.php").read_text(
        encoding="utf-8"
    )
    for needle in (
        "class AP_Posts_List_Table",
        "function prepareItems",
        "function processBulkAction",
        "function render",
        "function getColumns",
        "function getBulkActions",
        "function getViews",
    ):
        assert needle in list_src, f"Expected {needle!r} in list table"

    edit_src = (ADMIN / "includes" / "class-ap-admin-post-edit.php").read_text(
        encoding="utf-8"
    )
    for needle in (
        "class AP_Admin_Post_Edit",
        "function save",
        "function renderForm",
        "function processRowAction",
        "function processRestoreRevision",
        "function processDeleteRevision",
        "function renderRevisionsList",
        "autosave",
        "post_parent",
        "page_template",
        "visibility",
    ):
        assert needle in edit_src, f"Expected {needle!r} in edit class"

    admin_src = (ADMIN / "includes" / "class-ap-admin.php").read_text(encoding="utf-8")
    for needle in (
        "class AP_Admin",
        "function requireLogin",
        "function requireCapability",
        "function userCan",
        "function editCapabilityForPostType",
        "function screenCapabilities",
        "function menuItems",
        "function url",
        "function sanitizeRedirect",
    ):
        assert needle in admin_src, f"Expected {needle!r} in AP_Admin"


def test_functions_expose_nonce_and_escape_helpers() -> None:
    src = FUNCTIONS.read_text(encoding="utf-8")
    for needle in (
        "function ap_esc_html",
        "function ap_esc_attr",
        "function ap_sanitize_text_field",
        "function ap_create_nonce",
        "function ap_verify_nonce",
        "function ap_check_nonce",
        "function ap_nonce_field",
        "function ap_nonce_url",
        "function ap_verify_request_nonce",
        "function ap_check_request_nonce",
    ):
        assert needle in src, f"Expected {needle!r} in functions.php"


def test_bootstrap_loads_nonce_class() -> None:
    src = BOOTSTRAP.read_text(encoding="utf-8")
    assert "class-ap-nonce.php" in src


def test_structure_assert_lists_admin_screens() -> None:
    src = STRUCTURE.read_text(encoding="utf-8")
    for needle in (
        "ap-admin/edit.php",
        "ap-admin/post.php",
        "ap-admin/post-new.php",
        "class-ap-posts-list-table.php",
        "class-ap-nonce.php",
    ):
        assert needle in src, f"Expected {needle!r} in assert-structure.php"


def test_list_and_save_via_php() -> None:
    """Runtime: list table + create post/page + bulk trash."""
    root = str(ROOT)
    code = (
        "<?php\ndeclare(strict_types=1);\n"
        f"$root = {repr(root)};\n"
        "require $root . '/ap-includes/version.php';\n"
        "require $root . '/ap-includes/class-ap-db.php';\n"
        "require $root . '/ap-includes/class-ap-migrator.php';\n"
        "require $root . '/ap-includes/class-ap-post.php';\n"
        "require $root . '/ap-includes/class-ap-query.php';\n"
        "require $root . '/ap-includes/class-ap-user.php';\n"
        "require $root . '/ap-includes/class-ap-session.php';\n"
        "require $root . '/ap-includes/class-ap-nonce.php';\n"
        "require $root . '/ap-includes/functions.php';\n"
        "require $root . '/ap-admin/includes/class-ap-admin.php';\n"
        "require $root . '/ap-admin/includes/class-ap-posts-list-table.php';\n"
        "require $root . '/ap-admin/includes/class-ap-admin-post-edit.php';\n"
        "if (!defined('AP_NONCE_KEY')) define('AP_NONCE_KEY', 'k' . str_repeat('x', 40));\n"
        "if (!defined('AP_NONCE_SALT')) define('AP_NONCE_SALT', 's' . str_repeat('y', 40));\n"
        "AP_Post::resetRegistry();\n"
        "$pdo = new PDO('sqlite::memory:', null, null, [\n"
        "  PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,\n"
        "  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,\n"
        "]);\n"
        "$db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');\n"
        "(new AP_Migrator($db, AP_Migrator::defaultMigrationsPath()))->migrate();\n"
        "AP_Post::ensureBuiltins();\n"
        "$nonce = ap_create_nonce('new-post', 1);\n"
        "$r = AP_Admin_Post_Edit::save([\n"
        "  'post_title' => 'Admin Post', 'post_content' => 'Hi',\n"
        "  'post_type' => 'post', 'post_status' => 'draft',\n"
        "  'save_action' => 'publish', 'visibility' => 'public',\n"
        "  '_ap_nonce' => $nonce,\n"
        "], 1, $db);\n"
        "if (!$r['ok'] || $r['id'] < 1) { fwrite(STDERR, 'save post failed\\n'); exit(1); }\n"
        "$pageNonce = ap_create_nonce('new-post', 1);\n"
        "$pr = AP_Admin_Post_Edit::save([\n"
        "  'post_title' => 'Admin Page', 'post_content' => 'Pg',\n"
        "  'post_type' => 'page', 'post_status' => 'publish',\n"
        "  'post_parent' => 0, 'menu_order' => 1, 'page_template' => 'default',\n"
        "  'save_action' => 'publish', 'visibility' => 'public',\n"
        "  '_ap_nonce' => $pageNonce,\n"
        "], 1, $db);\n"
        "if (!$pr['ok']) { fwrite(STDERR, 'save page failed\\n'); exit(1); }\n"
        "$table = new AP_Posts_List_Table('post', $db);\n"
        "$table->prepareItems([]);\n"
        "if ($table->totalItems < 1) { fwrite(STDERR, 'list empty\\n'); exit(1); }\n"
        "$html = $table->render();\n"
        "if (!str_contains($html, 'Admin Post')) { fwrite(STDERR, 'title missing\\n'); exit(1); }\n"
        "$bulkNonce = ap_create_nonce('bulk-posts');\n"
        "$br = $table->processBulkAction([\n"
        "  'action' => 'trash', '_ap_nonce' => $bulkNonce, 'post' => [$r['id']],\n"
        "]);\n"
        "if (!$br['ok']) { fwrite(STDERR, 'bulk trash failed\\n'); exit(1); }\n"
        "if (AP_Post::get($r['id'], $db)->post_status !== 'trash') {\n"
        "  fwrite(STDERR, 'not trashed\\n'); exit(1);\n"
        "}\n"
        "if (!ap_check_nonce(ap_create_nonce('x', 2), 'x', 2)) {\n"
        "  fwrite(STDERR, 'nonce fail\\n'); exit(1);\n"
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
