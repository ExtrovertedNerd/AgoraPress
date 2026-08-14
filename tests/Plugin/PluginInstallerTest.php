<?php

/**
 * Tests for AP_Plugin_Installer — plugin zip upload.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Plugin;

use AP_DB;
use AP_Migrator;
use AP_Options;
use AP_Plugin;
use AP_Plugin_Installer;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ZipArchive;

#[CoversClass(AP_Plugin_Installer::class)]
final class PluginInstallerTest extends TestCase
{
    private string $root;

    private string $tempPlugins;

    private string $workDir;

    private AP_DB $db;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        require_once $this->root . '/ap-includes/version.php';
        require_once $this->root . '/ap-includes/hooks.php';
        require_once $this->root . '/ap-includes/class-ap-db.php';
        require_once $this->root . '/ap-includes/class-ap-migrator.php';
        require_once $this->root . '/ap-includes/class-ap-options.php';
        require_once $this->root . '/ap-includes/class-ap-plugin.php';
        require_once $this->root . '/ap-includes/class-ap-plugin-installer.php';
        require_once $this->root . '/ap-includes/functions.php';

        if (function_exists('ap_reset_hooks')) {
            ap_reset_hooks();
        }
        AP_Plugin::reset();
        AP_Options::flushCache();

        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');
        (new AP_Migrator($this->db, AP_Migrator::defaultMigrationsPath()))->migrate();
        $GLOBALS['apdb'] = $this->db;

        $this->db->insert('options', [
            'option_name' => 'active_plugins',
            'option_value' => '[]',
            'autoload' => 'yes',
        ]);

        $this->tempPlugins = sys_get_temp_dir() . '/ap-plugin-install-dest-' . uniqid('', true);
        $this->workDir = sys_get_temp_dir() . '/ap-plugin-install-work-' . uniqid('', true);
        $this->assertTrue(mkdir($this->tempPlugins, 0700, true));
        $this->assertTrue(mkdir($this->workDir, 0700, true));
        AP_Plugin::setPluginsRootOverride($this->tempPlugins);
    }

    protected function tearDown(): void
    {
        if (function_exists('ap_reset_hooks')) {
            ap_reset_hooks();
        }
        AP_Plugin::reset();
        AP_Options::flushCache();
        unset($GLOBALS['apdb']);
        $this->removeDir($this->tempPlugins);
        $this->removeDir($this->workDir);
    }

    public function testInstallFolderPluginFromZip(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $zip = $this->buildFolderPluginZip('classic-upload', [
            'Plugin Name: Classic Upload',
            'Description: A test plugin',
            'Version: 1.0.0',
            'Author: Tester',
        ]);

        $result = AP_Plugin_Installer::installFromZip($zip, [
            'plugins_root' => $this->tempPlugins,
        ]);

        $this->assertTrue($result['ok'], implode('; ', $result['errors']));
        $this->assertSame('classic-upload', $result['slug']);
        $this->assertSame('classic-upload/classic-upload.php', $result['plugin']);
        $this->assertTrue($result['is_folder']);
        $this->assertFileExists($this->tempPlugins . '/classic-upload/classic-upload.php');
        $this->assertSame('Classic Upload', $result['headers']['Plugin Name'] ?? '');

        $plugins = AP_Plugin::listPlugins();
        $this->assertArrayHasKey('classic-upload/classic-upload.php', $plugins);

        $activated = AP_Plugin::activate('classic-upload/classic-upload.php', $this->db);
        $this->assertTrue($activated['ok'], implode('; ', $activated['errors']));
        $this->assertTrue(AP_Plugin::isActive('classic-upload/classic-upload.php', $this->db));
    }

    public function testInstallSingleFilePluginFromZipRoot(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $zipPath = $this->workDir . '/hello.zip';
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFromString(
            'hello.php',
            "<?php\n/**\n * Plugin Name: Hello Flat\n * Version: 0.1\n */\n"
        );
        $zip->close();

        $result = AP_Plugin_Installer::installFromZip($zipPath, [
            'plugins_root' => $this->tempPlugins,
        ]);

        $this->assertTrue($result['ok'], implode('; ', $result['errors']));
        $this->assertSame('hello', $result['slug']);
        $this->assertSame('hello.php', $result['plugin']);
        $this->assertFalse($result['is_folder']);
        $this->assertFileExists($this->tempPlugins . '/hello.php');
    }

    public function testRejectsMissingPluginName(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $src = $this->workDir . '/noname';
        mkdir($src, 0700, true);
        file_put_contents($src . '/noname.php', "<?php\n// no headers\n");

        $zipPath = $this->workDir . '/noname.zip';
        $this->zipDirectory($src, $zipPath, 'noname');

        $result = AP_Plugin_Installer::installFromZip($zipPath, [
            'plugins_root' => $this->tempPlugins,
        ]);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Plugin Name', implode(' ', $result['errors']));
    }

    public function testOverwriteRequiresFlag(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $zip = $this->buildFolderPluginZip('twice-plugin', [
            'Plugin Name: Twice Plugin',
            'Version: 1.0',
        ]);

        $first = AP_Plugin_Installer::installFromZip($zip, [
            'plugins_root' => $this->tempPlugins,
        ]);
        $this->assertTrue($first['ok'], implode('; ', $first['errors']));

        $zip2 = $this->buildFolderPluginZip('twice-plugin', [
            'Plugin Name: Twice Plugin',
            'Version: 2.0',
        ]);
        $second = AP_Plugin_Installer::installFromZip($zip2, [
            'plugins_root' => $this->tempPlugins,
        ]);
        $this->assertFalse($second['ok']);

        $third = AP_Plugin_Installer::installFromZip($zip2, [
            'plugins_root' => $this->tempPlugins,
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

        $zip = $this->buildFolderPluginZip('upload-mode', [
            'Plugin Name: Upload Mode',
            'Version: 1.0',
        ]);

        $result = AP_Plugin_Installer::handleUpload([
            'name' => 'upload-mode.zip',
            'type' => 'application/zip',
            'tmp_name' => $zip,
            'error' => UPLOAD_ERR_NO_FILE,
            'size' => filesize($zip),
        ], [
            'plugins_root' => $this->tempPlugins,
            'test_mode' => true,
        ]);
        $this->assertFalse($result['ok']);

        $result = AP_Plugin_Installer::handleUpload([
            'name' => 'upload-mode.zip',
            'type' => 'application/zip',
            'tmp_name' => $zip,
            'error' => UPLOAD_ERR_OK,
            'size' => (int) filesize($zip),
        ], [
            'plugins_root' => $this->tempPlugins,
            'test_mode' => true,
        ]);
        $this->assertTrue($result['ok'], implode('; ', $result['errors']));
        $this->assertSame('upload-mode', $result['slug']);
    }

    public function testRejectsNonZipExtension(): void
    {
        $txt = $this->workDir . '/not-a-plugin.txt';
        file_put_contents($txt, 'hello');

        $result = AP_Plugin_Installer::handleUpload([
            'name' => 'not-a-plugin.txt',
            'type' => 'text/plain',
            'tmp_name' => $txt,
            'error' => UPLOAD_ERR_OK,
            'size' => 5,
        ], [
            'plugins_root' => $this->tempPlugins,
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
            'evil/evil.php',
            "<?php\n/**\n * Plugin Name: Evil\n * Version: 1\n */\n"
        );
        $zip->addFromString('../outside.php', "<?php\n// pwned\n");
        $zip->close();

        $result = AP_Plugin_Installer::installFromZip($zipPath, [
            'plugins_root' => $this->tempPlugins,
        ]);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('unsafe', strtolower(implode(' ', $result['errors'])));
    }

    public function testDeletePluginAndProtectActive(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $zip = $this->buildFolderPluginZip('doomed', [
            'Plugin Name: Doomed',
            'Version: 1.0',
        ]);
        $install = AP_Plugin_Installer::installFromZip($zip, [
            'plugins_root' => $this->tempPlugins,
        ]);
        $this->assertTrue($install['ok'], implode('; ', $install['errors']));

        $activated = AP_Plugin::activate('doomed/doomed.php', $this->db);
        $this->assertTrue($activated['ok'], implode('; ', $activated['errors']));
        $delActive = AP_Plugin_Installer::deletePlugin('doomed/doomed.php', $this->db);
        $this->assertFalse($delActive['ok']);

        $deactivated = AP_Plugin::deactivate('doomed/doomed.php', $this->db);
        $this->assertTrue($deactivated['ok'], implode('; ', $deactivated['errors']));
        $del = AP_Plugin_Installer::deletePlugin('doomed/doomed.php', $this->db);
        $this->assertTrue($del['ok'], implode('; ', $del['errors']));
        $this->assertDirectoryDoesNotExist($this->tempPlugins . '/doomed');
    }

    public function testRejectsDeeplyNestedPlugin(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $zipPath = $this->workDir . '/deep.zip';
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFromString(
            'wrap/nested/deep.php',
            "<?php\n/**\n * Plugin Name: Too Deep\n * Version: 1\n */\n"
        );
        $zip->close();

        $result = AP_Plugin_Installer::installFromZip($zipPath, [
            'plugins_root' => $this->tempPlugins,
        ]);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('deeply', strtolower(implode(' ', $result['errors'])));
    }

    public function testProceduralHelpersExist(): void
    {
        $this->assertTrue(function_exists('ap_install_plugin_from_zip'));
        $this->assertTrue(function_exists('ap_upload_plugin'));
        $this->assertTrue(function_exists('ap_delete_plugin'));
    }

    public function testAdminPluginsScreenHasUploader(): void
    {
        $path = $this->root . '/ap-admin/plugins.php';
        $this->assertFileExists($path);
        $src = (string) file_get_contents($path);
        $this->assertStringContainsString("requireCapability('activate_plugins')", $src);
        $this->assertStringContainsString('AP_Plugin_Installer', $src);
        $this->assertStringContainsString('plugin-upload', $src);
        $this->assertStringContainsString('install_plugins', $src);
        $this->assertStringContainsString('pluginzip', $src);
    }

    /**
     * @param list<string> $headerLines
     */
    private function buildFolderPluginZip(string $slug, array $headerLines): string
    {
        $dir = $this->workDir . '/' . $slug;
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $php = "<?php\n/**\n * " . implode("\n * ", $headerLines) . "\n */\n";
        file_put_contents($dir . '/' . $slug . '.php', $php);
        file_put_contents($dir . '/readme.txt', "Test plugin {$slug}\n");

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
