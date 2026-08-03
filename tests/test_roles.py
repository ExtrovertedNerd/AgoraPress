"""
Smoke tests for AgoraPress roles & capabilities (AP_Roles).

Runnable via:
  pytest tests/test_roles.py -v
"""

from __future__ import annotations

import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
ROLES_CLASS = ROOT / "ap-includes" / "class-ap-roles.php"
FUNCTIONS = ROOT / "ap-includes" / "functions.php"
INSTALLER = ROOT / "ap-includes" / "class-ap-installer.php"
BOOTSTRAP = ROOT / "ap-includes" / "bootstrap.php"
ADMIN = ROOT / "ap-admin" / "includes" / "class-ap-admin.php"


def _php_bin() -> str:
    return shutil.which("php") or "php"


def test_roles_files_exist() -> None:
    assert ROLES_CLASS.is_file(), "Missing class-ap-roles.php"
    assert FUNCTIONS.is_file(), "Missing functions.php"


def test_roles_class_defines_api() -> None:
    src = ROLES_CLASS.read_text(encoding="utf-8")
    for needle in (
        "class AP_Roles",
        "function ensureDefaults",
        "function getRoles",
        "function getRole",
        "function addRole",
        "function removeRole",
        "function addCap",
        "function removeCap",
        "function getUserRoles",
        "function setUserRole",
        "function getUserCapabilities",
        "function userCan",
        "function currentUserCan",
        "function mapMetaCap",
        "administrator",
        "subscriber",
        "ap_capabilities",
        "ap_user_roles",
    ):
        assert needle in src, f"Expected {needle!r} in class-ap-roles.php"


def test_functions_expose_role_helpers() -> None:
    src = FUNCTIONS.read_text(encoding="utf-8")
    for needle in (
        "function ap_user_can",
        "function ap_current_user_can",
        "function ap_get_roles",
        "function ap_get_role",
        "function ap_set_user_role",
        "function ap_get_user_roles",
        "function ap_map_meta_cap",
        "function ap_add_role",
        "function ap_ensure_roles",
    ):
        assert needle in src, f"Expected {needle!r} in functions.php"


def test_bootstrap_loads_roles() -> None:
    src = BOOTSTRAP.read_text(encoding="utf-8")
    assert "class-ap-roles.php" in src
    assert "AP_Roles::ensureDefaults" in src


def test_installer_seeds_roles() -> None:
    src = INSTALLER.read_text(encoding="utf-8")
    assert "AP_Roles::ensureDefaults" in src
    assert "AP_Roles::setUserRole" in src
    assert "default_role" in src


def test_admin_capability_gate() -> None:
    src = ADMIN.read_text(encoding="utf-8")
    assert "requireCapability" in src
    assert "currentUserCan" in src
    assert "denyAccess" in src
    assert "function userCan" in src
    assert "function screenCapabilities" in src
    assert "editCapabilityForPostType" in src


def test_roles_runtime_via_php() -> None:
    """In-memory SQLite: seed defaults, assign role, check caps."""
    code = f"""<?php
declare(strict_types=1);
require {repr(str(ROOT / 'ap-includes' / 'version.php'))};
require {repr(str(ROOT / 'ap-includes' / 'class-ap-db.php'))};
require {repr(str(ROOT / 'ap-includes' / 'class-ap-migrator.php'))};
require {repr(str(ROOT / 'ap-includes' / 'class-ap-options.php'))};
require {repr(str(ROOT / 'ap-includes' / 'class-ap-user.php'))};
require {repr(str(ROLES_CLASS))};
require {repr(str(FUNCTIONS))};

$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
]);
$db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');
$migrator = new AP_Migrator($db, AP_Migrator::defaultMigrationsPath());
$migrator->migrate();
AP_Roles::ensureDefaults($db);

if (!AP_Roles::roleExists('administrator', $db)) {{ fwrite(STDERR, "no admin role\\n"); exit(1); }}
if (!AP_Roles::roleExists('subscriber', $db)) {{ fwrite(STDERR, "no sub role\\n"); exit(2); }}

$db->insert('users', [
    'user_login' => 'pytestadmin',
    'user_pass' => AP_User::hashPassword('x'),
    'user_nicename' => 'pytestadmin',
    'user_email' => 'pytest@example.test',
    'user_url' => '',
    'user_registered' => gmdate('Y-m-d H:i:s'),
    'user_activation_key' => '',
    'user_status' => 0,
    'display_name' => 'Pytest',
]);
$id = (int) $db->lastInsertId();
if (!AP_Roles::setUserRole($id, 'author', $db)) {{ fwrite(STDERR, "set role\\n"); exit(3); }}
if (!AP_Roles::userCan($id, 'publish_posts', null, $db)) {{ fwrite(STDERR, "publish\\n"); exit(4); }}
if (AP_Roles::userCan($id, 'manage_options', null, $db)) {{ fwrite(STDERR, "manage leak\\n"); exit(5); }}
if (!ap_user_can($id, 'upload_files', null, $db)) {{ fwrite(STDERR, "upload\\n"); exit(6); }}
echo "OK\\n";
"""
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
