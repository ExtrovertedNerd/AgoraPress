"""
Smoke tests for AgoraPress classic WP theme zip uploader.

Runnable via:
  pytest tests/test_theme_uploader.py -v
"""

from __future__ import annotations

import shutil
import subprocess
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
INSTALLER = ROOT / "ap-includes" / "class-ap-theme-installer.php"
THEMES_ADMIN = ROOT / "ap-admin" / "themes.php"
FUNCTIONS = ROOT / "ap-includes" / "functions.php"
BOOTSTRAP = ROOT / "ap-includes" / "bootstrap.php"
STRUCTURE = ROOT / "tests" / "Structure" / "assert-structure.php"
PHPUNIT = ROOT / "tests" / "Theme" / "ThemeInstallerTest.php"


def _php_bin() -> str:
    return shutil.which("php") or "php"


def test_installer_and_admin_files_exist() -> None:
    assert INSTALLER.is_file(), "Missing class-ap-theme-installer.php"
    assert THEMES_ADMIN.is_file(), "Missing ap-admin/themes.php"
    assert PHPUNIT.is_file(), "Missing ThemeInstallerTest.php"


def test_installer_class_api() -> None:
    src = INSTALLER.read_text(encoding="utf-8")
    for needle in (
        "class AP_Theme_Installer",
        "function installFromZip",
        "function handleUpload",
        "function deleteTheme",
        "function maxUploadBytes",
        "Theme Name",
        "ZipArchive",
        "allow_block",
        "overwrite",
        "is_block",
        "PROTECTED_SLUGS",
    ):
        assert needle in src, f"Expected {needle!r} in class-ap-theme-installer.php"


def test_admin_themes_screen() -> None:
    src = THEMES_ADMIN.read_text(encoding="utf-8")
    for needle in (
        "requireCapability('switch_themes')",
        "install_themes",
        "AP_Theme_Installer",
        "theme-upload",
        "themezip",
        "Classic WordPress Theme Compatibility",
        "action' => 'activate'",
        "overwrite",
    ):
        assert needle in src, f"Expected {needle!r} in themes.php"


def test_functions_expose_helpers() -> None:
    src = FUNCTIONS.read_text(encoding="utf-8")
    for needle in (
        "function ap_install_theme_from_zip",
        "function ap_upload_theme",
        "function ap_delete_theme",
    ):
        assert needle in src, f"Expected {needle!r} in functions.php"


def test_bootstrap_loads_installer() -> None:
    src = BOOTSTRAP.read_text(encoding="utf-8")
    assert "class-ap-theme-installer.php" in src


def test_structure_lists_installer_and_themes_admin() -> None:
    src = STRUCTURE.read_text(encoding="utf-8")
    assert "class-ap-theme-installer.php" in src
    assert "ap-admin/themes.php" in src


def test_php_install_classic_zip_roundtrip() -> None:
    """End-to-end: build a classic theme zip and install via PHP."""
    php = _php_bin()
    script = """<?php
declare(strict_types=1);
$root = getenv('AP_ROOT') ?: getcwd();
require_once $root . '/ap-includes/class-ap-theme.php';
require_once $root . '/ap-includes/class-ap-theme-installer.php';

if (!class_exists('ZipArchive')) {
    fwrite(STDERR, "SKIP no ZipArchive\\n");
    exit(0);
}

$work = sys_get_temp_dir() . '/ap-tu-' . uniqid('', true);
$themes = $work . '/themes';
$pack = $work . '/pack/smoke-classic';
@mkdir($pack, 0700, true);
@mkdir($themes, 0700, true);
file_put_contents($pack . '/style.css', "/*\\nTheme Name: Smoke Classic\\nVersion: 1.2.3\\nAuthor: CI\\n*/\\nbody{}\\n");
file_put_contents($pack . '/index.php', "<?php echo 'ok';\\n");

$zipPath = $work . '/smoke-classic.zip';
$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "zip open failed\\n");
    exit(1);
}
$zip->addFile($pack . '/style.css', 'smoke-classic/style.css');
$zip->addFile($pack . '/index.php', 'smoke-classic/index.php');
$zip->close();

AP_Theme::setThemesRootOverride($themes);
$result = AP_Theme_Installer::installFromZip($zipPath, ['themes_root' => $themes]);
if (!$result['ok']) {
    fwrite(STDERR, implode('; ', $result['errors']) . "\\n");
    exit(2);
}
if ($result['slug'] !== 'smoke-classic') {
    fwrite(STDERR, "bad slug " . $result['slug'] . "\\n");
    exit(3);
}
if (!is_file($themes . '/smoke-classic/style.css')) {
    fwrite(STDERR, "missing installed style.css\\n");
    exit(4);
}

// Block theme rejection
$blockDir = $work . '/pack/blocky';
@mkdir($blockDir . '/templates', 0700, true);
file_put_contents($blockDir . '/style.css', "/*\\nTheme Name: Blocky\\nVersion: 1\\n*/\\n");
file_put_contents($blockDir . '/theme.json', '{"version":2}' . "\\n");
file_put_contents($blockDir . '/templates/index.html', "<!-- -->\\n");
$bzip = $work . '/blocky.zip';
$z2 = new ZipArchive();
$z2->open($bzip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$z2->addFile($blockDir . '/style.css', 'blocky/style.css');
$z2->addFile($blockDir . '/theme.json', 'blocky/theme.json');
$z2->addFile($blockDir . '/templates/index.html', 'blocky/templates/index.html');
$z2->close();
$bad = AP_Theme_Installer::installFromZip($bzip, ['themes_root' => $themes]);
if ($bad['ok'] || !$bad['is_block']) {
    fwrite(STDERR, "block theme should be rejected\\n");
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
echo "OK\\n";
exit(0);
"""
    with tempfile.TemporaryDirectory() as tmp:
        script_path = Path(tmp) / "theme_upload_smoke.php"
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
