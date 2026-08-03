"""
Smoke tests for forum attachments (schema v6 + AP_Forum_Attachment).

Runnable via:
  pytest tests/test_forum_attachments.py -v
"""

from __future__ import annotations

import re
import shutil
import subprocess
import textwrap
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MIGRATIONS = ROOT / "ap-includes" / "schema" / "migrations"
VERSION = ROOT / "ap-includes" / "version.php"
DB_CLASS = ROOT / "ap-includes" / "class-ap-db.php"
MIGRATOR = ROOT / "ap-includes" / "class-ap-migrator.php"
FORUM_CLASS = ROOT / "ap-includes" / "class-ap-forum.php"
ATTACH_CLASS = ROOT / "ap-includes" / "class-ap-forum-attachment.php"
LOAD_CONFIG = ROOT / "ap-includes" / "load-config.php"
FUNCTIONS = ROOT / "ap-includes" / "functions.php"
BOOTSTRAP = ROOT / "ap-includes" / "bootstrap.php"
INSTALLER = ROOT / "ap-includes" / "class-ap-installer.php"


def _php_bin() -> str:
    return shutil.which("php") or "php"


def test_migration_and_class_exist() -> None:
    assert (MIGRATIONS / "0006_forum_attachments.php").is_file()
    assert ATTACH_CLASS.is_file()


def test_db_version_at_least_six() -> None:
    src = VERSION.read_text(encoding="utf-8")
    m = re.search(r"define\('AP_DB_VERSION',\s*'(\d+)'\)", src)
    assert m is not None
    assert int(m.group(1)) >= 6


def test_migration_0006_surface() -> None:
    mig = MIGRATIONS / "0006_forum_attachments.php"
    src = mig.read_text(encoding="utf-8")
    for needle in (
        "AP_Migration_0006_Forum_Attachments",
        "forum_attachments",
        "attach_id",
        "media_id",
        "filesize",
        "is_orphan",
        "download_count",
        "ENGINE=InnoDB",
        "BIGSERIAL",
        "AUTOINCREMENT",
    ):
        assert needle in src, f"Expected {needle} in 0006 migration"


def test_attachment_class_api() -> None:
    src = ATTACH_CLASS.read_text(encoding="utf-8")
    for needle in (
        "class AP_Forum_Attachment",
        "function isEnabled",
        "function maxSizeBytes",
        "function allowedExtensions",
        "function maxPerPost",
        "function userQuotaBytes",
        "function userUsageBytes",
        "function canUpload",
        "function handleUpload",
        "function attachMedia",
        "function assignToPost",
        "function getForPost",
        "function delete",
        "function deleteForPost",
        "function deleteForTopic",
        "OPTION_ENABLED",
        "OPTION_USER_QUOTA",
    ):
        assert needle in src, f"Expected {needle!r} in class-ap-forum-attachment.php"


def test_functions_and_bootstrap() -> None:
    fn = FUNCTIONS.read_text(encoding="utf-8")
    for needle in (
        "function ap_handle_forum_attachment_upload",
        "function ap_get_forum_attachments",
        "function ap_delete_forum_attachment",
        "function ap_forum_attachments_enabled",
        "function ap_can_upload_forum_attachment",
        "function ap_assign_forum_attachments",
    ):
        assert needle in fn, f"Expected {needle!r} in functions.php"

    boot = BOOTSTRAP.read_text(encoding="utf-8")
    assert "class-ap-forum-attachment.php" in boot

    load = LOAD_CONFIG.read_text(encoding="utf-8")
    assert "forum_attachments" in load

    forum = FORUM_CLASS.read_text(encoding="utf-8")
    assert "forum_attachments" in forum
    assert "AP_Forum_Attachment" in forum

    installer = INSTALLER.read_text(encoding="utf-8")
    assert "forum_attachments_enabled" in installer
    assert "forum_attachment_user_quota" in installer


def test_phpunit_forum_attachment_suite_runs() -> None:
    result = subprocess.run(
        [
            _php_bin(),
            "vendor/bin/phpunit",
            "-c",
            "phpunit.xml.dist",
            "tests/Forum/ForumAttachmentTest.php",
        ],
        cwd=str(ROOT),
        capture_output=True,
        text=True,
        check=False,
    )
    combined = (result.stdout or "") + (result.stderr or "")
    assert result.returncode == 0, f"PHPUnit forum attachments failed:\n{combined}"


def test_forum_attachment_migration_applies_via_php() -> None:
    script = textwrap.dedent(
        f"""
        declare(strict_types=1);
        require {repr(str(VERSION))};
        require {repr(str(LOAD_CONFIG))};
        require {repr(str(DB_CLASS))};
        require {repr(str(MIGRATOR))};
        require {repr(str(FORUM_CLASS))};
        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');
        $m = new AP_Migrator($db, AP_Migrator::defaultMigrationsPath());
        $applied = $m->migrate();
        if ((int) AP_DB_VERSION < 6) {{
            fwrite(STDERR, "AP_DB_VERSION expected >= 6\\n");
            exit(1);
        }}
        if ($m->getCurrentVersion() < 6) {{
            fwrite(STDERR, "schema version < 6\\n");
            exit(1);
        }}
        $name = $db->getVar(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?",
            ['ap_forum_attachments']
        );
        if ($name !== 'ap_forum_attachments') {{
            fwrite(STDERR, "table missing\\n");
            exit(1);
        }}
        if (!in_array('forum_attachments', AP_Forum::baseTables(), true)) {{
            fwrite(STDERR, "baseTables missing forum_attachments\\n");
            exit(1);
        }}
        echo "ok\\n";
        """
    )
    result = subprocess.run(
        [_php_bin(), "-r", script],
        cwd=str(ROOT),
        capture_output=True,
        text=True,
        check=False,
    )
    combined = (result.stdout or "") + (result.stderr or "")
    assert result.returncode == 0, combined
    assert "ok" in (result.stdout or "")
