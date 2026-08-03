"""
Smoke tests for user groups + granular per-forum permissions (schema v7).

Runnable via:
  pytest tests/test_forum_groups_permissions.py -v
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
GROUP_CLASS = ROOT / "ap-includes" / "class-ap-group.php"
PERM_CLASS = ROOT / "ap-includes" / "class-ap-forum-permissions.php"
FORUM_CLASS = ROOT / "ap-includes" / "class-ap-forum.php"
LOAD_CONFIG = ROOT / "ap-includes" / "load-config.php"
FUNCTIONS = ROOT / "ap-includes" / "functions.php"
BOOTSTRAP = ROOT / "ap-includes" / "bootstrap.php"
INSTALLER = ROOT / "ap-includes" / "class-ap-installer.php"


def _php_bin() -> str:
    return shutil.which("php") or "php"


def test_migration_and_classes_exist() -> None:
    assert (MIGRATIONS / "0007_forum_permissions.php").is_file()
    assert GROUP_CLASS.is_file()
    assert PERM_CLASS.is_file()


def test_db_version_at_least_seven() -> None:
    src = VERSION.read_text(encoding="utf-8")
    m = re.search(r"define\('AP_DB_VERSION',\s*'(\d+)'\)", src)
    assert m is not None
    assert int(m.group(1)) >= 7


def test_migration_0007_surface() -> None:
    mig = MIGRATIONS / "0007_forum_permissions.php"
    src = mig.read_text(encoding="utf-8")
    for needle in (
        "AP_Migration_0007_Forum_Permissions",
        "forum_permissions",
        "permission_id",
        "perm_name",
        "perm_setting",
        "forum_group_perm",
        "ENGINE=InnoDB",
        "BIGSERIAL",
        "AUTOINCREMENT",
    ):
        assert needle in src, f"Expected {needle} in 0007 migration"


def test_group_class_api() -> None:
    src = GROUP_CLASS.read_text(encoding="utf-8")
    for needle in (
        "class AP_Group",
        "function ensureSystemGroups",
        "function create",
        "function addMember",
        "function removeMember",
        "function getUserGroups",
        "function getEffectiveGroupIds",
        "SLUG_GUESTS",
        "SLUG_REGISTERED",
        "SLUG_ADMINISTRATORS",
        "SLUG_GLOBAL_MODERATORS",
    ):
        assert needle in src, f"Expected {needle} in AP_Group"


def test_permissions_class_api() -> None:
    src = PERM_CLASS.read_text(encoding="utf-8")
    for needle in (
        "class AP_Forum_Permissions",
        "function ensureDefaults",
        "function setPermission",
        "function userCan",
        "function getUserPermissions",
        "PERM_VIEW",
        "PERM_POST_TOPICS",
        "PERM_MODERATE",
        "FORUM_GLOBAL",
    ):
        assert needle in src, f"Expected {needle} in AP_Forum_Permissions"


def test_wired_into_bootstrap_functions_tables() -> None:
    boot = BOOTSTRAP.read_text(encoding="utf-8")
    assert "class-ap-group.php" in boot
    assert "class-ap-forum-permissions.php" in boot

    fn = FUNCTIONS.read_text(encoding="utf-8")
    for needle in (
        "function ap_create_group",
        "function ap_add_group_member",
        "function ap_user_can_forum",
        "function ap_ensure_forum_permission_defaults",
        "function ap_set_forum_permission",
    ):
        assert needle in fn, f"Expected {needle} in functions.php"

    lc = LOAD_CONFIG.read_text(encoding="utf-8")
    assert "forum_permissions" in lc

    forum = FORUM_CLASS.read_text(encoding="utf-8")
    assert "forum_permissions" in forum

    installer = INSTALLER.read_text(encoding="utf-8")
    assert "ensureSystemGroups" in installer
    assert "ensureDefaults" in installer


def test_migration_applies_and_acl_works_via_php() -> None:
    script = textwrap.dedent(
        f"""
        declare(strict_types=1);
        require {repr(str(VERSION))};
        require {repr(str(DB_CLASS))};
        require {repr(str(MIGRATOR))};
        require {repr(str(ROOT / "ap-includes/class-ap-options.php"))};
        require {repr(str(ROOT / "ap-includes/class-ap-roles.php"))};
        require {repr(str(ROOT / "ap-includes/class-ap-forum.php"))};
        require {repr(str(GROUP_CLASS))};
        require {repr(str(PERM_CLASS))};
        require {repr(str(ROOT / "ap-includes/functions.php"))};

        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');
        $m = new AP_Migrator($db, AP_Migrator::defaultMigrationsPath());
        if ((int) AP_DB_VERSION < 7) {{
            fwrite(STDERR, "AP_DB_VERSION expected >= 7\\n");
            exit(2);
        }}
        $applied = $m->migrate();
        if (count($applied) < 7 || (int) $applied[6]['version'] !== 7) {{
            fwrite(STDERR, "version 7 not applied\\n");
            exit(3);
        }}
        $name = $db->getVar(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?",
            ['ap_forum_permissions']
        );
        if ($name !== 'ap_forum_permissions') {{
            fwrite(STDERR, "forum_permissions table missing\\n");
            exit(4);
        }}
        AP_Roles::ensureDefaults($db);
        AP_Forum_Permissions::ensureDefaults($db);
        $forumId = AP_Forum::insertForum(['forum_name' => 'Smoke'], $db);
        if ($forumId < 1) {{
            fwrite(STDERR, "forum insert failed\\n");
            exit(5);
        }}
        if (!AP_Forum_Permissions::userCan(0, $forumId, 'view_forum', $db)) {{
            fwrite(STDERR, "guest cannot view\\n");
            exit(6);
        }}
        if (AP_Forum_Permissions::userCan(0, $forumId, 'post_topics', $db)) {{
            fwrite(STDERR, "guest should not post\\n");
            exit(7);
        }}
        $g = AP_Group::create(['group_name' => 'Smoke VIP'], $db);
        if ($g < 1) {{
            fwrite(STDERR, "group create failed\\n");
            exit(8);
        }}
        echo "ok\\n";
        """
    )
    proc = subprocess.run(
        [_php_bin(), "-r", script],
        cwd=str(ROOT),
        capture_output=True,
        text=True,
        check=False,
    )
    assert proc.returncode == 0, proc.stderr + proc.stdout
    assert "ok" in proc.stdout
