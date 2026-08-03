"""
Smoke tests for phpBB importer (AP_Phpbb_Importer).

Runnable via:
  pytest tests/test_phpbb_importer.py -v
"""

from __future__ import annotations

import shutil
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
IMPORTER = ROOT / "ap-includes" / "class-ap-phpbb-importer.php"
ADMIN_SCREEN = ROOT / "ap-admin" / "import.php"
FUNCTIONS = ROOT / "ap-includes" / "functions.php"
BOOTSTRAP = ROOT / "ap-includes" / "bootstrap.php"
STRUCTURE = ROOT / "tests" / "Structure" / "assert-structure.php"
PHPUNIT = ROOT / "tests" / "Import" / "PhpbbImporterTest.php"
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
        "class AP_Phpbb_Importer",
        "function importFromFile",
        "function importFromString",
        "function importFromArray",
        "function importFromDatabase",
        "function handleUpload",
        "function parseJson",
        "function isPhpbbJson",
        "function extractFromPdo",
        "function cleanPostText",
        "META_PHPBB_USER_ID",
        "JSON_FORMAT",
        "DEFAULT_MAX_BYTES",
        "agorapress-phpbb-export",
        "ap_phpbb_imported",
        "ap_phpbb_max_bytes",
    ):
        assert needle in src, f"AP_Phpbb_Importer missing {needle!r}"


def test_admin_import_screen() -> None:
    src = ADMIN_SCREEN.read_text(encoding="utf-8")
    for needle in (
        "requireCapability('import')",
        "ap_import_wxr_upload",
        "ap_import_phpbb_upload",
        "ap_import_phpbb_database",
        "import-wxr",
        "import-phpbb-json",
        "import-phpbb-db",
        "WordPress",
        "phpBB",
        "import_users",
        "import_forums",
        "import_topics",
        "import_posts",
        "skip_bots",
        "phpbb_prefix",
        'enctype="multipart/form-data"',
        'name="phpbb"',
        'name="wxr"',
    ):
        assert needle in src, f"import.php missing {needle!r}"
    assert "Coming soon" not in src or "phpBB" in src


def test_functions_expose_helpers() -> None:
    src = FUNCTIONS.read_text(encoding="utf-8")
    for needle in (
        "function ap_import_phpbb",
        "function ap_import_phpbb_string",
        "function ap_import_phpbb_database",
        "function ap_import_phpbb_upload",
        "function ap_is_phpbb_json",
        "function ap_parse_phpbb_json",
        "function ap_clean_phpbb_post_text",
    ):
        assert needle in src, f"functions.php missing {needle!r}"


def test_bootstrap_loads_importer() -> None:
    src = BOOTSTRAP.read_text(encoding="utf-8")
    assert "class-ap-phpbb-importer.php" in src


def test_structure_lists_importer() -> None:
    src = STRUCTURE.read_text(encoding="utf-8")
    assert "class-ap-phpbb-importer.php" in src


def test_changelog_and_readme_mention_phpbb() -> None:
    changelog = CHANGELOG.read_text(encoding="utf-8")
    assert "phpBB" in changelog

    readme = README.read_text(encoding="utf-8")
    assert "phpBB" in readme
    # Should no longer say only "planned" without an importer mention of Tools
    assert "Import" in readme or "importer" in readme.lower()


def test_phpunit_phpbb_importer() -> None:
    php = _php_bin()
    vendor_phpunit = ROOT / "vendor" / "bin" / "phpunit"
    assert vendor_phpunit.is_file(), "PHPUnit not installed (composer install)"

    proc = subprocess.run(
        [php, str(vendor_phpunit), str(PHPUNIT), "--colors=never"],
        cwd=str(ROOT),
        capture_output=True,
        text=True,
        timeout=120,
        check=False,
    )
    assert proc.returncode == 0, (
        f"PHPUnit phpBB tests failed (exit {proc.returncode}):\n"
        f"{proc.stdout}\n{proc.stderr}"
    )


def test_php_parse_and_import_roundtrip() -> None:
    """Lightweight end-to-end without full PHPUnit runner."""
    import os

    php = _php_bin()
    script = r"""
declare(strict_types=1);
$root = getenv('AP_ROOT') ?: getcwd();
require_once $root . '/ap-includes/hooks.php';
require_once $root . '/ap-includes/class-ap-db.php';
require_once $root . '/ap-includes/class-ap-migrator.php';
require_once $root . '/ap-includes/class-ap-options.php';
require_once $root . '/ap-includes/class-ap-user.php';
require_once $root . '/ap-includes/class-ap-roles.php';
require_once $root . '/ap-includes/class-ap-content-format.php';
require_once $root . '/ap-includes/class-ap-forum.php';
require_once $root . '/ap-includes/class-ap-formatting.php';
require_once $root . '/ap-includes/class-ap-phpbb-importer.php';
require_once $root . '/ap-includes/functions.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');
$m = new AP_Migrator($db, AP_Migrator::defaultMigrationsPath());
$m->migrate();
AP_Roles::ensureDefaults($db);

$json = json_encode([
    'format' => AP_Phpbb_Importer::JSON_FORMAT,
    'version' => 1,
    'source' => ['board_name' => 'Roundtrip', 'phpbb_version' => '3.3.0'],
    'users' => [
        ['user_id' => 2, 'username' => 'dave', 'user_email' => 'dave@example.com', 'user_type' => 0],
    ],
    'forums' => [
        ['forum_id' => 1, 'parent_id' => 0, 'forum_name' => 'Chat', 'forum_type' => 1, 'forum_status' => 0, 'left_id' => 1],
    ],
    'topics' => [
        ['topic_id' => 1, 'forum_id' => 1, 'topic_title' => 'Hi', 'topic_poster' => 2, 'topic_time' => 1600000000,
         'topic_views' => 3, 'topic_status' => 0, 'topic_type' => 0, 'topic_first_post_id' => 1, 'topic_visibility' => 1],
    ],
    'posts' => [
        ['post_id' => 1, 'topic_id' => 1, 'forum_id' => 1, 'poster_id' => 2, 'post_time' => 1600000000,
         'post_subject' => 'Hi', 'post_text' => '[b:zz11aa]Body[/b:zz11aa]', 'bbcode_uid' => 'zz11aa', 'post_visibility' => 1],
    ],
], JSON_THROW_ON_ERROR);

$result = ap_import_phpbb_string($json, $db);
if (empty($result['ok'])) {
    fwrite(STDERR, implode('; ', $result['errors']) . "\n");
    exit(1);
}
if ((int)$result['users'] !== 1 || (int)$result['forums'] !== 1 || (int)$result['topics'] !== 1 || (int)$result['posts'] < 1) {
    fwrite(STDERR, "Unexpected counts: " . json_encode($result) . "\n");
    exit(2);
}
$user = AP_User::getByLogin('dave', $db);
if ($user === null) {
    fwrite(STDERR, "User not found\n");
    exit(3);
}
$clean = ap_clean_phpbb_post_text('[url=https://x:uid]y[/url:uid]', 'uid');
if (!str_contains($clean, '[url=https://x]y[/url]')) {
    fwrite(STDERR, "clean failed: $clean\n");
    exit(4);
}
echo "OK\n";
"""
    proc = subprocess.run(
        [php, "-r", script],
        cwd=str(ROOT),
        capture_output=True,
        text=True,
        timeout=60,
        check=False,
        env={**os.environ, "AP_ROOT": str(ROOT)},
    )
    assert proc.returncode == 0, (
        f"Roundtrip failed (exit {proc.returncode}):\n{proc.stdout}\n{proc.stderr}"
    )
    assert "OK" in proc.stdout
