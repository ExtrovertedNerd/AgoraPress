"""
Smoke tests for AgoraPress plugin zip uploader.

Runnable via:
  pytest tests/test_plugin_uploader.py -v
"""

from __future__ import annotations

import shutil
import subprocess
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
INSTALLER = ROOT / "ap-includes" / "class-ap-plugin-installer.php"
PLUGINS_ADMIN = ROOT / "ap-admin" / "plugins.php"
FUNCTIONS = ROOT / "ap-includes" / "functions.php"
BOOTSTRAP = ROOT / "ap-includes" / "bootstrap.php"
STRUCTURE = ROOT / "tests" / "Structure" / "assert-structure.php"
PHPUNIT = ROOT / "tests" / "Plugin" / "PluginInstallerTest.php"


def _php_bin() -> str:
    return shutil.which("php") or "php"


def test_installer_and_admin_files_exist() -> None:
    assert INSTALLER.is_file(), "Missing class-ap-plugin-installer.php"
    assert PLUGINS_ADMIN.is_file(), "Missing ap-admin/plugins.php"
    assert PHPUNIT.is_file(), "Missing PluginInstallerTest.php"


def test_installer_class_api() -> None:
    src = INSTALLER.read_text(encoding="utf-8")
    for needle in (
        "class AP_Plugin_Installer",
        "function installFromZip",
        "function handleUpload",
        "function deletePlugin",
        "function maxUploadBytes",
        "Plugin Name",
        "ZipArchive",
        "overwrite",
        "is_folder",
    ):
        assert needle in src, f"Expected {needle!r} in class-ap-plugin-installer.php"


def test_admin_plugins_screen() -> None:
    src = PLUGINS_ADMIN.read_text(encoding="utf-8")
    for needle in (
        "requireCapability('activate_plugins')",
        "install_plugins",
        "AP_Plugin_Installer",
        "plugin-upload",
        "pluginzip",
        "'action' => $act",
        "action' => 'delete'",
        "overwrite",
    ):
        assert needle in src, f"Expected {needle!r} in plugins.php"


def test_functions_expose_helpers() -> None:
    src = FUNCTIONS.read_text(encoding="utf-8")
    for needle in (
        "function ap_install_plugin_from_zip",
        "function ap_upload_plugin",
        "function ap_delete_plugin",
    ):
        assert needle in src, f"Expected {needle!r} in functions.php"


def test_bootstrap_loads_installer() -> None:
    src = BOOTSTRAP.read_text(encoding="utf-8")
    assert "class-ap-plugin-installer.php" in src


def test_structure_lists_installer() -> None:
    src = STRUCTURE.read_text(encoding="utf-8")
    assert "class-ap-plugin-installer.php" in src


def test_php_install_plugin_zip_roundtrip() -> None:
    """End-to-end: build a plugin zip and install via PHP."""
    php = _php_bin()
    script = r"""<?php
declare(strict_types=1);
$root = getenv('AP_ROOT') ?: getcwd();
require_once $root . '/ap-includes/class-ap-plugin.php';
require_once $root . '/ap-includes/class-ap-plugin-installer.php';

if (!class_exists('ZipArchive')) {
    fwrite(STDERR, "SKIP no ZipArchive\n");
    exit(0);
}

$work = sys_get_temp_dir() . '/ap-pu-' . uniqid('', true);
$plugins = $work . '/plugins';
$pack = $work . '/pack/smoke-plugin';
@mkdir($pack, 0700, true);
@mkdir($plugins, 0700, true);
file_put_contents($pack . '/smoke-plugin.php', "<?php\n/**\n * Plugin Name: Smoke Plugin\n * Version: 1.2.3\n * Author: CI\n */\n");

$zipPath = $work . '/smoke-plugin.zip';
$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "zip open failed\n");
    exit(1);
}
$zip->addFile($pack . '/smoke-plugin.php', 'smoke-plugin/smoke-plugin.php');
$zip->close();

AP_Plugin::setPluginsRootOverride($plugins);
$result = AP_Plugin_Installer::installFromZip($zipPath, ['plugins_root' => $plugins]);
if (!$result['ok']) {
    fwrite(STDERR, implode('; ', $result['errors']) . "\n");
    exit(2);
}
if ($result['plugin'] !== 'smoke-plugin/smoke-plugin.php') {
    fwrite(STDERR, "bad plugin " . $result['plugin'] . "\n");
    exit(3);
}
if (!is_file($plugins . '/smoke-plugin/smoke-plugin.php')) {
    fwrite(STDERR, "missing installed plugin file\n");
    exit(4);
}

// Missing header rejection
$badDir = $work . '/pack/noheader';
@mkdir($badDir, 0700, true);
file_put_contents($badDir . '/noheader.php', "<?php\n// nope\n");
$bzip = $work . '/noheader.zip';
$z2 = new ZipArchive();
$z2->open($bzip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$z2->addFile($badDir . '/noheader.php', 'noheader/noheader.php');
$z2->close();
$bad = AP_Plugin_Installer::installFromZip($bzip, ['plugins_root' => $plugins]);
if ($bad['ok']) {
    fwrite(STDERR, "plugin without Plugin Name should be rejected\n");
    exit(5);
}

function rrmdir(string $d): void {
    if (!is_dir($d)) {
        return;
    }
    foreach (scandir($d) ?: [] as $e) {
        if ($e === '.' || $e === '..') {
            continue;
        }
        $p = $d . '/' . $e;
        is_dir($p) ? rrmdir($p) : @unlink($p);
    }
    @rmdir($d);
}
rrmdir($work);
echo "OK\n";
exit(0);
"""
    with tempfile.TemporaryDirectory() as tmp:
        script_path = Path(tmp) / "plugin_upload_smoke.php"
        script_path.write_text(script, encoding="utf-8")
        env = dict(**{k: v for k, v in __import__("os").environ.items()})
        env["AP_ROOT"] = str(ROOT)
        proc = subprocess.run(
            [php, str(script_path)],
            cwd=str(ROOT),
            env=env,
            capture_output=True,
            text=True,
            timeout=60,
        )
    assert proc.returncode == 0, f"stdout={proc.stdout!r} stderr={proc.stderr!r}"
    assert "OK" in proc.stdout
