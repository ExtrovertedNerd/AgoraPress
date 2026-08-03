"""
Smoke tests for WordPress WXR importer (AP_Wxr_Importer).

Runnable via:
  pytest tests/test_wxr_importer.py -v
"""

from __future__ import annotations

import shutil
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
IMPORTER = ROOT / "ap-includes" / "class-ap-wxr-importer.php"
ADMIN_SCREEN = ROOT / "ap-admin" / "import.php"
FUNCTIONS = ROOT / "ap-includes" / "functions.php"
BOOTSTRAP = ROOT / "ap-includes" / "bootstrap.php"
ADMIN_CLASS = ROOT / "ap-admin" / "includes" / "class-ap-admin.php"
ROLES = ROOT / "ap-includes" / "class-ap-roles.php"
STRUCTURE = ROOT / "tests" / "Structure" / "assert-structure.php"
PHPUNIT = ROOT / "tests" / "Import" / "WxrImporterTest.php"
CHANGELOG = ROOT / "CHANGELOG.md"
README = ROOT / "README.md"


def _php_bin() -> str:
    return shutil.which("php") or "php"


def test_importer_files_exist() -> None:
    for path in (IMPORTER, ADMIN_SCREEN, PHPUNIT, BOOTSTRAP, FUNCTIONS):
        assert path.is_file(), f"Missing {path.relative_to(ROOT)}"


def test_importer_class_api() -> None:
    src = IMPORTER.read_text(encoding="utf-8")
    for needle in (
        "class AP_Wxr_Importer",
        "function importFromFile",
        "function importFromString",
        "function handleUpload",
        "function parse",
        "function isWxr",
        "META_WXR_POST_ID",
        "META_ATTACHMENT_URL",
        "DEFAULT_MAX_BYTES",
        "SKIP_POST_TYPES",
        "wordpress.org/export",
        "wxr_version",
        "ap_wxr_imported",
    ):
        assert needle in src, f"AP_Wxr_Importer missing {needle!r}"


def test_admin_import_screen() -> None:
    src = ADMIN_SCREEN.read_text(encoding="utf-8")
    for needle in (
        "requireCapability('import')",
        "ap_import_wxr_upload",
        "import-wxr",
        "WordPress",
        "WXR",
        "import_authors",
        "import_comments",
        "import_attachments",
        "phpBB",
        'enctype="multipart/form-data"',
        'name="wxr"',
    ):
        assert needle in src, f"import.php missing {needle!r}"


def test_functions_expose_helpers() -> None:
    src = FUNCTIONS.read_text(encoding="utf-8")
    for needle in (
        "function ap_import_wxr",
        "function ap_import_wxr_string",
        "function ap_import_wxr_upload",
        "function ap_is_wxr",
        "function ap_parse_wxr",
    ):
        assert needle in src, f"functions.php missing {needle!r}"


def test_bootstrap_loads_importer() -> None:
    src = BOOTSTRAP.read_text(encoding="utf-8")
    assert "class-ap-wxr-importer.php" in src


def test_admin_menu_and_import_cap() -> None:
    admin = ADMIN_CLASS.read_text(encoding="utf-8")
    assert "import.php" in admin
    assert "'id' => 'import'" in admin or '"id" => "import"' in admin

    roles = ROLES.read_text(encoding="utf-8")
    assert "'import'" in roles or '"import"' in roles
    assert "'export'" in roles or '"export"' in roles


def test_structure_lists_importer() -> None:
    src = STRUCTURE.read_text(encoding="utf-8")
    assert "class-ap-wxr-importer.php" in src
    assert "ap-admin/import.php" in src


def test_changelog_and_readme_mention_wxr() -> None:
    changelog = CHANGELOG.read_text(encoding="utf-8")
    assert "WXR" in changelog or "wxr" in changelog.lower()

    readme = README.read_text(encoding="utf-8")
    assert "WXR" in readme or "WordPress" in readme


def test_phpunit_wxr_importer() -> None:
    php = _php_bin()
    vendor_phpunit = ROOT / "vendor" / "bin" / "phpunit"
    if vendor_phpunit.is_file():
        cmd = [php, str(vendor_phpunit), str(PHPUNIT), "--colors=never"]
    else:
        cmd = [php, str(PHPUNIT)]
        # Direct require won't run PHPUnit — skip if no vendor.
        assert vendor_phpunit.is_file(), "PHPUnit not installed (composer install)"

    proc = subprocess.run(
        cmd,
        cwd=str(ROOT),
        capture_output=True,
        text=True,
        timeout=120,
        check=False,
    )
    assert proc.returncode == 0, (
        f"PHPUnit WXR tests failed (exit {proc.returncode}):\n"
        f"{proc.stdout}\n{proc.stderr}"
    )


def test_php_parse_and_import_roundtrip() -> None:
    """Lightweight end-to-end without full PHPUnit runner."""
    import os
    import tempfile

    php = _php_bin()
    script = """<?php
declare(strict_types=1);
$root = getenv('AP_ROOT') ?: getcwd();
require_once $root . '/ap-includes/hooks.php';
require_once $root . '/ap-includes/class-ap-db.php';
require_once $root . '/ap-includes/class-ap-migrator.php';
require_once $root . '/ap-includes/class-ap-options.php';
require_once $root . '/ap-includes/class-ap-user.php';
require_once $root . '/ap-includes/class-ap-roles.php';
require_once $root . '/ap-includes/class-ap-post.php';
require_once $root . '/ap-includes/class-ap-taxonomy.php';
require_once $root . '/ap-includes/class-ap-comment.php';
require_once $root . '/ap-includes/class-ap-formatting.php';
require_once $root . '/ap-includes/class-ap-wxr-importer.php';
require_once $root . '/ap-includes/functions.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');
$m = new AP_Migrator($db, AP_Migrator::defaultMigrationsPath());
$m->migrate();
AP_Roles::ensureDefaults($db);

$xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8" ?>
<rss version="2.0"
	xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/"
	xmlns:content="http://purl.org/rss/1.0/modules/content/"
	xmlns:dc="http://purl.org/dc/elements/1.1/"
	xmlns:wp="http://wordpress.org/export/1.2/">
<channel>
	<title>Smoke</title>
	<link>https://ex.test</link>
	<wp:wxr_version>1.2</wp:wxr_version>
	<wp:base_blog_url>https://ex.test</wp:base_blog_url>
	<wp:author>
		<wp:author_id>1</wp:author_id>
		<wp:author_login><![CDATA[smoke]]></wp:author_login>
		<wp:author_email><![CDATA[smoke@ex.test]]></wp:author_email>
		<wp:author_display_name><![CDATA[Smoke]]></wp:author_display_name>
		<wp:author_first_name><![CDATA[]]></wp:author_first_name>
		<wp:author_last_name><![CDATA[]]></wp:author_last_name>
	</wp:author>
	<item>
		<title><![CDATA[Smoke Post]]></title>
		<link>https://ex.test/smoke/</link>
		<dc:creator><![CDATA[smoke]]></dc:creator>
		<guid isPermaLink="false">https://ex.test/?p=1</guid>
		<content:encoded><![CDATA[Hello WXR]]></content:encoded>
		<excerpt:encoded><![CDATA[]]></excerpt:encoded>
		<wp:post_id>1</wp:post_id>
		<wp:post_date><![CDATA[2024-01-01 00:00:00]]></wp:post_date>
		<wp:post_date_gmt><![CDATA[2024-01-01 00:00:00]]></wp:post_date_gmt>
		<wp:comment_status><![CDATA[open]]></wp:comment_status>
		<wp:ping_status><![CDATA[closed]]></wp:ping_status>
		<wp:post_name><![CDATA[smoke-post]]></wp:post_name>
		<wp:status><![CDATA[publish]]></wp:status>
		<wp:post_parent>0</wp:post_parent>
		<wp:menu_order>0</wp:menu_order>
		<wp:post_type><![CDATA[post]]></wp:post_type>
		<wp:post_password><![CDATA[]]></wp:post_password>
		<wp:is_sticky>0</wp:is_sticky>
	</item>
</channel>
</rss>
XML;

if (!AP_Wxr_Importer::isWxr($xml)) {
    fwrite(STDERR, "FAIL isWxr\\n");
    exit(1);
}
$r = AP_Wxr_Importer::importFromString($xml, $db);
if (empty($r['ok'])) {
    fwrite(STDERR, "FAIL import: " . implode('; ', $r['errors']) . "\\n");
    exit(1);
}
if ((int)$r['posts'] !== 1 || (int)$r['authors'] !== 1) {
    fwrite(STDERR, "FAIL counts posts={$r['posts']} authors={$r['authors']}\\n");
    exit(1);
}
$post = AP_Post::getBySlug('smoke-post', 'post', $db);
if ($post === null || !str_contains($post->post_content, 'Hello WXR')) {
    fwrite(STDERR, "FAIL post content\\n");
    exit(1);
}
echo "OK\\n";
exit(0);
"""
    env = os.environ.copy()
    env["AP_ROOT"] = str(ROOT)
    with tempfile.NamedTemporaryFile("w", suffix=".php", delete=False, encoding="utf-8") as fh:
        fh.write(script)
        path = fh.name
    try:
        proc = subprocess.run(
            [php, path],
            cwd=str(ROOT),
            env=env,
            capture_output=True,
            text=True,
            timeout=60,
            check=False,
        )
    finally:
        Path(path).unlink(missing_ok=True)

    assert proc.returncode == 0, f"PHP roundtrip failed:\n{proc.stdout}\n{proc.stderr}"
    assert "OK" in proc.stdout
