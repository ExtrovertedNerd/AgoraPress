<?php

/**
 * Tests for AP_Theme_Installer — classic WP theme zip upload.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Theme;

use AP_DB;
use AP_Migrator;
use AP_Theme;
use AP_Theme_Installer;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ZipArchive;

#[CoversClass(AP_Theme_Installer::class)]
final class ThemeInstallerTest extends TestCase
{
    private string $root;

    private string $tempThemes;

    private string $workDir;

    private AP_DB $db;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        require_once $this->root . '/ap-includes/version.php';
        require_once $this->root . '/ap-includes/hooks.php';
        require_once $this->root . '/ap-includes/class-ap-db.php';
        require_once $this->root . '/ap-includes/class-ap-migrator.php';
        require_once $this->root . '/ap-includes/class-ap-theme.php';
        require_once $this->root . '/ap-includes/class-ap-theme-installer.php';
        require_once $this->root . '/ap-includes/compatibility/load.php';
        require_once $this->root . '/ap-includes/functions.php';

        if (function_exists('ap_reset_hooks')) {
            ap_reset_hooks();
        }
        AP_Theme::reset();

        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');
        (new AP_Migrator($this->db, AP_Migrator::defaultMigrationsPath()))->migrate();

        foreach (
            [
                'home' => 'https://example.test',
                'siteurl' => 'https://example.test',
                'blogname' => 'Theme Installer Site',
                'stylesheet' => 'agora',
                'template' => 'agora',
            ] as $name => $value
        ) {
            $this->db->insert('options', [
                'option_name' => $name,
                'option_value' => $value,
                'autoload' => 'yes',
            ]);
        }

        $this->tempThemes = sys_get_temp_dir() . '/ap-theme-install-dest-' . uniqid('', true);
        $this->workDir = sys_get_temp_dir() . '/ap-theme-install-work-' . uniqid('', true);
        $this->assertTrue(mkdir($this->tempThemes, 0700, true));
        $this->assertTrue(mkdir($this->workDir, 0700, true));
        AP_Theme::setThemesRootOverride($this->tempThemes);
        $GLOBALS['apdb'] = $this->db;
    }

    protected function tearDown(): void
    {
        if (function_exists('ap_reset_hooks')) {
            ap_reset_hooks();
        }
        AP_Theme::reset();
        unset($GLOBALS['apdb']);
        $this->removeDir($this->tempThemes);
        $this->removeDir($this->workDir);
    }

    public function testInstallClassicThemeFromFolderZip(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $zip = $this->buildClassicThemeZip('classic-upload', [
            'Theme Name: Classic Upload',
            'Description: A test classic theme',
            'Version: 1.0.0',
            'Author: Tester',
        ]);

        $result = AP_Theme_Installer::installFromZip($zip, [
            'themes_root' => $this->tempThemes,
        ]);

        $this->assertTrue($result['ok'], implode('; ', $result['errors']));
        $this->assertSame('classic-upload', $result['slug']);
        $this->assertTrue($result['is_classic']);
        $this->assertFalse($result['is_block']);
        $this->assertFalse($result['is_child']);
        $this->assertFileExists($this->tempThemes . '/classic-upload/style.css');
        $this->assertFileExists($this->tempThemes . '/classic-upload/index.php');
        $this->assertSame('Classic Upload', $result['headers']['Theme Name'] ?? '');

        $themes = AP_Theme::listThemes();
        $this->assertArrayHasKey('classic-upload', $themes);

        $this->assertTrue(AP_Theme::setActive('classic-upload', null, $this->db));
        $this->assertSame('classic-upload', AP_Theme::getStylesheet($this->db));
    }

    public function testInstallFromZipRootStyleCss(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $src = $this->workDir . '/flat-theme';
        mkdir($src, 0700, true);
        file_put_contents(
            $src . '/style.css',
            "/*\nTheme Name: Flat Root Theme\nVersion: 0.1\n*/\nbody{}\n"
        );
        file_put_contents($src . '/index.php', "<?php\necho 'flat';\n");

        $zipPath = $this->workDir . '/flat.zip';
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFile($src . '/style.css', 'style.css');
        $zip->addFile($src . '/index.php', 'index.php');
        $zip->close();

        $result = AP_Theme_Installer::installFromZip($zipPath, [
            'themes_root' => $this->tempThemes,
        ]);

        $this->assertTrue($result['ok'], implode('; ', $result['errors']));
        $this->assertSame('flat-root-theme', $result['slug']);
        $this->assertDirectoryExists($this->tempThemes . '/flat-root-theme');
    }

    public function testRejectsBlockThemeZip(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $src = $this->workDir . '/block-pack';
        mkdir($src . '/blocky', 0700, true);
        mkdir($src . '/blocky/templates', 0700, true);
        file_put_contents(
            $src . '/blocky/style.css',
            "/*\nTheme Name: Blocky\nVersion: 1.0\n*/\n"
        );
        file_put_contents($src . '/blocky/theme.json', "{\"version\":2}\n");
        file_put_contents($src . '/blocky/templates/index.html', "<!-- wp:html -->\n");

        $zipPath = $this->workDir . '/blocky.zip';
        $this->zipDirectory($src . '/blocky', $zipPath, 'blocky');

        $result = AP_Theme_Installer::installFromZip($zipPath, [
            'themes_root' => $this->tempThemes,
        ]);

        $this->assertFalse($result['ok']);
        $this->assertTrue($result['is_block']);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('block', strtolower(implode(' ', $result['errors'])));
        $this->assertDirectoryDoesNotExist($this->tempThemes . '/blocky');
    }

    public function testRejectsMissingThemeName(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $src = $this->workDir . '/no-name';
        mkdir($src . '/noname', 0700, true);
        file_put_contents($src . '/noname/style.css', "/* no headers */\nbody{}\n");
        file_put_contents($src . '/noname/index.php', "<?php\n");

        $zipPath = $this->workDir . '/noname.zip';
        $this->zipDirectory($src . '/noname', $zipPath, 'noname');

        $result = AP_Theme_Installer::installFromZip($zipPath, [
            'themes_root' => $this->tempThemes,
        ]);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Theme Name', implode(' ', $result['errors']));
    }

    public function testRejectsParentWithoutIndex(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $src = $this->workDir . '/no-index';
        mkdir($src . '/noindex', 0700, true);
        file_put_contents(
            $src . '/noindex/style.css',
            "/*\nTheme Name: No Index\nVersion: 1\n*/\n"
        );

        $zipPath = $this->workDir . '/noindex.zip';
        $this->zipDirectory($src . '/noindex', $zipPath, 'noindex');

        $result = AP_Theme_Installer::installFromZip($zipPath, [
            'themes_root' => $this->tempThemes,
        ]);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('index.php', implode(' ', $result['errors']));
    }

    public function testOverwriteRequiresFlag(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $zip = $this->buildClassicThemeZip('twice-theme', [
            'Theme Name: Twice Theme',
            'Version: 1.0',
        ]);

        $first = AP_Theme_Installer::installFromZip($zip, [
            'themes_root' => $this->tempThemes,
        ]);
        $this->assertTrue($first['ok'], implode('; ', $first['errors']));

        $zip2 = $this->buildClassicThemeZip('twice-theme', [
            'Theme Name: Twice Theme',
            'Version: 2.0',
        ]);
        $second = AP_Theme_Installer::installFromZip($zip2, [
            'themes_root' => $this->tempThemes,
        ]);
        $this->assertFalse($second['ok']);

        $third = AP_Theme_Installer::installFromZip($zip2, [
            'themes_root' => $this->tempThemes,
            'overwrite' => true,
        ]);
        $this->assertTrue($third['ok'], implode('; ', $third['errors']));
        $this->assertTrue($third['overwritten']);
        $this->assertSame('2.0', $third['headers']['Version'] ?? '');
    }

    public function testHandleUploadTestMode(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $zip = $this->buildClassicThemeZip('upload-mode', [
            'Theme Name: Upload Mode',
            'Version: 1.0',
        ]);

        $result = AP_Theme_Installer::handleUpload([
            'name' => 'upload-mode.zip',
            'type' => 'application/zip',
            'tmp_name' => $zip,
            'error' => UPLOAD_ERR_NO_FILE,
            'size' => filesize($zip),
        ], [
            'themes_root' => $this->tempThemes,
            'test_mode' => true,
        ]);
        // error code NO_FILE should fail before test_mode matters.
        $this->assertFalse($result['ok']);

        $result = AP_Theme_Installer::handleUpload([
            'name' => 'upload-mode.zip',
            'type' => 'application/zip',
            'tmp_name' => $zip,
            'error' => UPLOAD_ERR_OK,
            'size' => (int) filesize($zip),
        ], [
            'themes_root' => $this->tempThemes,
            'test_mode' => true,
        ]);
        $this->assertTrue($result['ok'], implode('; ', $result['errors']));
        $this->assertSame('upload-mode', $result['slug']);
    }

    public function testRejectsNonZipExtension(): void
    {
        $txt = $this->workDir . '/not-a-theme.txt';
        file_put_contents($txt, 'hello');

        $result = AP_Theme_Installer::handleUpload([
            'name' => 'not-a-theme.txt',
            'type' => 'text/plain',
            'tmp_name' => $txt,
            'error' => UPLOAD_ERR_OK,
            'size' => 5,
        ], [
            'themes_root' => $this->tempThemes,
            'test_mode' => true,
        ]);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('.zip', implode(' ', $result['errors']));
    }

    public function testPathTraversalRejected(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $zipPath = $this->workDir . '/evil.zip';
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFromString(
            'evil/style.css',
            "/*\nTheme Name: Evil\nVersion: 1\n*/\n"
        );
        $zip->addFromString('evil/index.php', "<?php\n");
        $zip->addFromString('../outside.txt', "pwned\n");
        $zip->close();

        $result = AP_Theme_Installer::installFromZip($zipPath, [
            'themes_root' => $this->tempThemes,
        ]);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('unsafe', strtolower(implode(' ', $result['errors'])));
    }

    public function testDeleteThemeAndProtectActiveAndAgora(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        // Seed a fake agora so protected slug check works even with override root.
        mkdir($this->tempThemes . '/agora', 0700, true);
        file_put_contents(
            $this->tempThemes . '/agora/style.css',
            "/*\nTheme Name: Agora\nVersion: 1\n*/\n"
        );
        file_put_contents($this->tempThemes . '/agora/index.php', "<?php\n");

        $zip = $this->buildClassicThemeZip('doomed', [
            'Theme Name: Doomed',
            'Version: 1.0',
        ]);
        $install = AP_Theme_Installer::installFromZip($zip, [
            'themes_root' => $this->tempThemes,
        ]);
        $this->assertTrue($install['ok'], implode('; ', $install['errors']));

        $delAgora = AP_Theme_Installer::deleteTheme('agora', $this->db);
        $this->assertFalse($delAgora['ok']);

        AP_Theme::setActive('doomed', null, $this->db);
        $delActive = AP_Theme_Installer::deleteTheme('doomed', $this->db);
        $this->assertFalse($delActive['ok']);

        AP_Theme::setActive('agora', 'agora', $this->db);
        $del = AP_Theme_Installer::deleteTheme('doomed', $this->db);
        $this->assertTrue($del['ok'], implode('; ', $del['errors']));
        $this->assertDirectoryDoesNotExist($this->tempThemes . '/doomed');
    }

    public function testChildThemeInstallWarnsMissingParent(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $src = $this->workDir . '/child-src';
        mkdir($src . '/child-of-missing', 0700, true);
        file_put_contents(
            $src . '/child-of-missing/style.css',
            "/*\nTheme Name: Child Of Missing\nTemplate: missing-parent\nVersion: 1\n*/\n"
        );
        // Children may omit index.php.

        $zipPath = $this->workDir . '/child.zip';
        $this->zipDirectory($src . '/child-of-missing', $zipPath, 'child-of-missing');

        $result = AP_Theme_Installer::installFromZip($zipPath, [
            'themes_root' => $this->tempThemes,
        ]);
        $this->assertTrue($result['ok'], implode('; ', $result['errors']));
        $this->assertTrue($result['is_child']);
        $this->assertNotEmpty($result['warnings']);
        $this->assertStringContainsString('missing-parent', implode(' ', $result['warnings']));
    }

    public function testProceduralHelpersExist(): void
    {
        $this->assertTrue(function_exists('ap_install_theme_from_zip'));
        $this->assertTrue(function_exists('ap_upload_theme'));
        $this->assertTrue(function_exists('ap_delete_theme'));
    }

    public function testAdminThemesScreenExists(): void
    {
        $path = $this->root . '/ap-admin/themes.php';
        $this->assertFileExists($path);
        $src = (string) file_get_contents($path);
        $this->assertStringContainsString("requireCapability('switch_themes')", $src);
        $this->assertStringContainsString('AP_Theme_Installer', $src);
        $this->assertStringContainsString('theme-upload', $src);
        $this->assertStringContainsString('Classic WordPress Theme Compatibility', $src);
    }

    /**
     * @param list<string> $headerLines Lines inside the style.css comment block (without Theme Name ok).
     */
    private function buildClassicThemeZip(string $slug, array $headerLines): string
    {
        $dir = $this->workDir . '/' . $slug;
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $css = "/*\n" . implode("\n", $headerLines) . "\n*/\nbody { color: #111; }\n";
        file_put_contents($dir . '/style.css', $css);
        file_put_contents($dir . '/index.php', "<?php\necho 'theme " . $slug . "';\n");
        file_put_contents($dir . '/functions.php', "<?php\n// classic theme\n");

        $zipPath = $this->workDir . '/' . $slug . '.zip';
        $this->zipDirectory($dir, $zipPath, $slug);

        return $zipPath;
    }

    private function zipDirectory(string $sourceDir, string $zipPath, string $rootName): void
    {
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile()) {
                continue;
            }
            $full = $file->getPathname();
            $rel = ltrim(str_replace('\\', '/', substr($full, strlen($sourceDir))), '/');
            $zip->addFile($full, $rootName . '/' . $rel);
        }
        $zip->close();
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isDir()) {
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
        }
        @rmdir($dir);
    }
}
