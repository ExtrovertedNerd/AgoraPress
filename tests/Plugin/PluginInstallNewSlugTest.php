<?php

/**
 * Tests for AP_Plugin_Installer::install() when the slug is new.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Plugin;

use AP_Plugin_Install_Error;
use AP_Plugin_Install_Result;
use AP_Plugin_Installer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ZipArchive;

#[CoversClass(AP_Plugin_Installer::class)]
#[CoversClass(AP_Plugin_Install_Result::class)]
#[CoversClass(AP_Plugin_Install_Error::class)]
final class PluginInstallNewSlugTest extends TestCase
{
    private string $root;

    private string $workDir;

    private string $contentDir;

    private string $pluginsRoot;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        require_once $this->root . '/ap-includes/class-ap-plugin-installer.php';
        require_once $this->root . '/ap-includes/functions.php';
        require_once $this->root . '/ap-includes/hooks.php';
        ap_reset_hooks();

        $this->workDir = sys_get_temp_dir() . '/ap-plugin-install-new-' . uniqid('', true);
        $this->contentDir = $this->workDir . '/content';
        $this->pluginsRoot = $this->contentDir . '/plugins';
        $this->assertTrue(mkdir($this->pluginsRoot, 0700, true));
        file_put_contents($this->pluginsRoot . '/.keep', 'stay');
    }

    protected function tearDown(): void
    {
        ap_reset_hooks();
        if (is_dir($this->pluginsRoot)) {
            @chmod($this->pluginsRoot, 0700);
        }
        $this->removeDir($this->workDir);
    }

    public function testInstallsNewFolderSlugFromPrivateStaging(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $main = $this->pluginPhp('Demo Notes', '1.4.2');
        $zip = $this->zipWith([
            'demo-notes/demo-notes.php' => $main,
            'demo-notes/readme.txt' => "shipped readme\n",
            'demo-notes/includes/helper.php' => "<?php\n// helper\n",
        ]);

        $validated = AP_Plugin_Installer::validate($zip, $this->opts());
        $this->assertInstanceOf(AP_Plugin_Install_Result::class, $validated);
        $this->assertDirectoryDoesNotExist($this->pluginsRoot . '/demo-notes');
        $this->assertStagingGone();

        $result = AP_Plugin_Installer::install($zip, $this->opts());

        $this->assertInstanceOf(AP_Plugin_Install_Result::class, $result);
        $this->assertSame('demo-notes', $result->slug());
        $this->assertSame('Demo Notes', $result->name());
        $this->assertSame('1.4.2', $result->version());
        $this->assertNull($result->previousVersion());
        $this->assertSame(AP_Plugin_Install_Result::ACTION_INSTALLED, $result->action());
        $this->assertNull($result->backupPath());
        $this->assertFalse($result->activated());
        $this->assertSame('demo-notes/demo-notes.php', $result->plugin());
        $this->assertSame($validated->slug(), $result->slug());
        $this->assertSame($validated->name(), $result->name());
        $this->assertSame($validated->version(), $result->version());
        $this->assertSame($validated->plugin(), $result->plugin());
        $this->assertSame(
            [
                'ok' => true,
                'slug' => 'demo-notes',
                'name' => 'Demo Notes',
                'version' => '1.4.2',
                'previous_version' => null,
                'action' => 'installed',
                'backup_path' => null,
                'activated' => false,
                'plugin' => 'demo-notes/demo-notes.php',
            ],
            $result->toArray()
        );

        $installed = $this->pluginsRoot . '/demo-notes/demo-notes.php';
        $this->assertSame($main, (string) file_get_contents($installed));
        $readme = $this->pluginsRoot . '/demo-notes/readme.txt';
        $this->assertSame("shipped readme\n", (string) file_get_contents($readme));
        $this->assertSame(
            "<?php\n// helper\n",
            (string) file_get_contents($this->pluginsRoot . '/demo-notes/includes/helper.php')
        );
        $this->assertFalse(is_link($installed));
        $this->assertFalse(is_link($this->pluginsRoot . '/demo-notes'));
        $real = realpath($installed);
        $pluginsReal = realpath($this->pluginsRoot);
        $this->assertNotFalse($real);
        $this->assertNotFalse($pluginsReal);
        $this->assertStringStartsWith($pluginsReal . DIRECTORY_SEPARATOR, $real);
        $this->assertStringNotContainsString('.ap-plugin-staging', str_replace('\\', '/', $real));
        $this->assertSame('stay', (string) file_get_contents($this->pluginsRoot . '/.keep'));
        $this->assertStagingGone();
        $this->assertSame(['.', '..', 'plugins'], $this->listDir($this->contentDir));
    }

    public function testInstallsNewFlatSlugAsASingleFile(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $main = $this->pluginPhp('Hello Flat', '0.2.0');
        $zip = $this->zipWith([
            'Hello.php' => $main,
            'notes.txt' => "not part of the plugin file\n",
        ]);

        $result = AP_Plugin_Installer::install($zip, $this->opts());

        $this->assertInstanceOf(AP_Plugin_Install_Result::class, $result);
        $this->assertSame('hello', $result->slug());
        $this->assertSame('Hello Flat', $result->name());
        $this->assertSame('0.2.0', $result->version());
        $this->assertNull($result->previousVersion());
        $this->assertSame(AP_Plugin_Install_Result::ACTION_INSTALLED, $result->action());
        $this->assertNull($result->backupPath());
        $this->assertFalse($result->activated());
        $this->assertSame('hello.php', $result->plugin());
        $this->assertSame($main, (string) file_get_contents($this->pluginsRoot . '/hello.php'));
        $this->assertFileDoesNotExist($this->pluginsRoot . '/Hello.php');
        $this->assertFileDoesNotExist($this->pluginsRoot . '/notes.txt');
        $this->assertDirectoryDoesNotExist($this->pluginsRoot . '/hello');
        $this->assertStagingGone();
    }

    public function testInstallCreatesAMissingPluginsDirectory(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $fresh = $this->contentDir . '/fresh-plugins';
        $this->assertDirectoryDoesNotExist($fresh);
        $zip = $this->zipWith([
            'fresh-slug/fresh-slug.php' => $this->pluginPhp('Fresh Slug', '1.0.0'),
        ]);

        $result = AP_Plugin_Installer::install($zip, [
            'plugins_root' => $fresh,
            'content_dir' => $this->contentDir,
        ]);

        $this->assertInstanceOf(AP_Plugin_Install_Result::class, $result);
        $this->assertSame('fresh-slug', $result->slug());
        $this->assertFileExists($fresh . '/fresh-slug/fresh-slug.php');
        $this->assertDirectoryDoesNotExist($this->contentDir . '/.ap-plugin-staging');
        $this->assertDirectoryDoesNotExist($fresh . '/.ap-plugin-staging');
    }

    public function testExistingFolderSlugIsLeftUnchanged(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $dir = $this->pluginsRoot . '/sample';
        $this->assertTrue(mkdir($dir, 0700, true));
        $original = $this->pluginPhp('Sample', '1.0.0');
        file_put_contents($dir . '/sample.php', $original);

        $zip = $this->zipWith([
            'sample/sample.php' => $this->pluginPhp('Sample', '2.0.0'),
            'sample/readme.txt' => "replacement\n",
        ]);

        $error = AP_Plugin_Installer::install($zip, $this->opts());

        $this->assertInstanceOf(AP_Plugin_Install_Error::class, $error);
        $this->assertSame(AP_Plugin_Install_Error::ALREADY_INSTALLED, $error->code());
        $this->assertStringContainsString('already installed', strtolower($error->message()));
        $this->assertStringContainsString('left unchanged', strtolower($error->message()));
        $this->assertFalse($error->toArray()['ok']);
        $this->assertSame($original, (string) file_get_contents($dir . '/sample.php'));
        $this->assertFileDoesNotExist($dir . '/readme.txt');
        $this->assertStagingGone();
    }

    public function testExistingFlatFileIsLeftUnchanged(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $path = $this->pluginsRoot . '/hello.php';
        $original = $this->pluginPhp('Hello Flat', '1.0.0');
        file_put_contents($path, $original);
        $zip = $this->zipWith([
            'hello.php' => $this->pluginPhp('Hello Flat', '9.0.0'),
        ]);

        $error = AP_Plugin_Installer::install($zip, $this->opts());

        $this->assertInstanceOf(AP_Plugin_Install_Error::class, $error);
        $this->assertSame(AP_Plugin_Install_Error::ALREADY_INSTALLED, $error->code());
        $this->assertSame($original, (string) file_get_contents($path));
        $this->assertStagingGone();
    }

    public function testFileOccupyingAFolderSlugIsLeftUnchanged(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $blocker = $this->pluginsRoot . '/blocked';
        file_put_contents($blocker, 'not a plugin directory');
        $zip = $this->zipWith([
            'blocked/blocked.php' => $this->pluginPhp('Blocked', '1.0.0'),
        ]);

        $error = AP_Plugin_Installer::install($zip, $this->opts());

        $this->assertInstanceOf(AP_Plugin_Install_Error::class, $error);
        $this->assertSame(AP_Plugin_Install_Error::ALREADY_INSTALLED, $error->code());
        $this->assertSame('not a plugin directory', (string) file_get_contents($blocker));
        $this->assertDirectoryDoesNotExist($this->pluginsRoot . '/blocked.php');
        $this->assertFileDoesNotExist($this->pluginsRoot . '/blocked/blocked.php');
        $this->assertStagingGone();
    }

    public function testRejectedZipDoesNotWriteAPlugin(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $before = $this->listDir($this->pluginsRoot);
        $notZip = $this->workDir . '/notes.zip';
        file_put_contents($notZip, 'this is not a zip archive');

        $error = AP_Plugin_Installer::install($notZip, $this->opts());
        $this->assertInstanceOf(AP_Plugin_Install_Error::class, $error);
        $this->assertSame(AP_Plugin_Install_Error::NOT_ZIP, $error->code());

        $missing = AP_Plugin_Installer::install($this->workDir . '/missing.zip', $this->opts());
        $this->assertInstanceOf(AP_Plugin_Install_Error::class, $missing);
        $this->assertSame(AP_Plugin_Install_Error::MISSING_FILE, $missing->code());

        $badHeader = $this->zipWith([
            'blank/blank.php' => "<?php\n// no header\n",
        ]);
        $headerError = AP_Plugin_Installer::install($badHeader, $this->opts());
        $this->assertInstanceOf(AP_Plugin_Install_Error::class, $headerError);
        $this->assertSame(AP_Plugin_Install_Error::MISSING_HEADER, $headerError->code());
        $this->assertDirectoryDoesNotExist($this->pluginsRoot . '/blank');

        $slip = $this->zipWith([
            'pkg/pkg.php' => $this->pluginPhp('Pkg', '1.0.0'),
            'pkg/../../outside.php' => "<?php\n// pwned\n",
        ]);
        $escape = dirname($this->workDir) . '/outside.php';
        $escapeBefore = is_file($escape);
        $slipError = AP_Plugin_Installer::install($slip, $this->opts());
        $this->assertInstanceOf(AP_Plugin_Install_Error::class, $slipError);
        $this->assertSame(AP_Plugin_Install_Error::UNSAFE_PATH, $slipError->code());
        $this->assertSame($escapeBefore, is_file($escape));
        $this->assertDirectoryDoesNotExist($this->pluginsRoot . '/pkg');

        $evil = $this->zipWith([
            'evil/evil.php' => $this->pluginPhp('Evil', '1.0.0'),
        ]);
        $checksum = AP_Plugin_Installer::install($evil, $this->opts() + [
            'expected_sha256' => str_repeat('ab', 32),
        ]);
        $this->assertInstanceOf(AP_Plugin_Install_Error::class, $checksum);
        $this->assertSame(AP_Plugin_Install_Error::SHA256_MISMATCH, $checksum->code());
        $this->assertDirectoryDoesNotExist($this->pluginsRoot . '/evil');

        $this->assertSame($before, $this->listDir($this->pluginsRoot));
        $this->assertStagingGone();
    }

    public function testMatchingSha256InstallsTheNewSlug(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $zip = $this->zipWith([
            'checksum/checksum.php' => $this->pluginPhp('Checksum', '3.1.0'),
        ]);
        $hash = hash_file('sha256', $zip);
        $this->assertIsString($hash);

        $result = AP_Plugin_Installer::install($zip, $this->opts() + [
            'expected_sha256' => 'sha256:' . strtoupper($hash),
        ]);

        $this->assertInstanceOf(AP_Plugin_Install_Result::class, $result);
        $this->assertSame('checksum', $result->slug());
        $this->assertSame('3.1.0', $result->version());
        $this->assertFileExists($this->pluginsRoot . '/checksum/checksum.php');
        $this->assertStagingGone();
    }

    public function testUnwritablePluginsDirectoryInstallsNothing(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $this->assertTrue(chmod($this->pluginsRoot, 0555));
        clearstatcache(true, $this->pluginsRoot);
        if (is_writable($this->pluginsRoot)) {
            chmod($this->pluginsRoot, 0700);
            $this->markTestSkipped('directory mode is not enforced for this user');
        }

        $before = $this->listDir($this->pluginsRoot);
        $zip = $this->zipWith([
            'locked/locked.php' => $this->pluginPhp('Locked', '1.0.0'),
        ]);

        $error = AP_Plugin_Installer::install($zip, $this->opts());

        $this->assertInstanceOf(AP_Plugin_Install_Error::class, $error);
        $this->assertSame(AP_Plugin_Install_Error::INSTALL_FAILED, $error->code());
        $this->assertStringContainsString('not writable', strtolower($error->message()));
        $this->assertTrue(chmod($this->pluginsRoot, 0700));
        $this->assertSame($before, $this->listDir($this->pluginsRoot));
        $this->assertDirectoryDoesNotExist($this->pluginsRoot . '/locked');
        $this->assertStagingGone();
    }

    public function testPluginsPathThatIsAFileIsNotReplaced(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $blocker = $this->contentDir . '/not-plugins';
        file_put_contents($blocker, 'keep');
        $zip = $this->zipWith([
            'orphan/orphan.php' => $this->pluginPhp('Orphan', '1.0.0'),
        ]);

        $error = AP_Plugin_Installer::install($zip, [
            'plugins_root' => $blocker,
            'content_dir' => $this->contentDir,
        ]);

        $this->assertInstanceOf(AP_Plugin_Install_Error::class, $error);
        $this->assertSame(AP_Plugin_Install_Error::INSTALL_FAILED, $error->code());
        $this->assertSame('keep', (string) file_get_contents($blocker));
        $this->assertDirectoryDoesNotExist($this->contentDir . '/.ap-plugin-staging');
        $this->assertSame(['.', '..', '.keep'], $this->listDir($this->pluginsRoot));
    }

    public function testProceduralHelperInstallsANewSlug(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $this->assertTrue(function_exists('ap_install_plugin'));
        $missing = ap_install_plugin($this->workDir . '/missing.zip', $this->opts());
        $this->assertInstanceOf(AP_Plugin_Install_Error::class, $missing);
        $this->assertSame(AP_Plugin_Install_Error::MISSING_FILE, $missing->code());

        $zip = $this->zipWith([
            'wrapped/wrapped.php' => $this->pluginPhp('Wrapped', '0.9.0'),
        ]);
        $result = ap_install_plugin($zip, $this->opts());

        $this->assertInstanceOf(AP_Plugin_Install_Result::class, $result);
        $this->assertSame('wrapped', $result->slug());
        $this->assertSame(AP_Plugin_Install_Result::ACTION_INSTALLED, $result->action());
        $this->assertFalse($result->activated());
        $this->assertFileExists($this->pluginsRoot . '/wrapped/wrapped.php');
        $this->assertStagingGone();
    }

    /**
     * @return array{plugins_root: string, content_dir: string}
     */
    private function opts(): array
    {
        return [
            'plugins_root' => $this->pluginsRoot,
            'content_dir' => $this->contentDir,
        ];
    }

    private function assertStagingGone(): void
    {
        $this->assertDirectoryDoesNotExist($this->contentDir . '/.ap-plugin-staging');
        $this->assertDirectoryDoesNotExist($this->pluginsRoot . '/.ap-plugin-staging');
    }

    /**
     * @return list<string>
     */
    private function listDir(string $dir): array
    {
        $entries = scandir($dir);
        $this->assertIsArray($entries);
        sort($entries);

        return array_values($entries);
    }

    private function pluginPhp(string $name, string $version): string
    {
        return "<?php\n/**\n * Plugin Name: {$name}\n * Version: {$version}\n */\n";
    }

    /**
     * @param array<string, string> $entries
     */
    private function zipWith(array $entries): string
    {
        $path = $this->workDir . '/pkg-' . bin2hex(random_bytes(4)) . '.zip';
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        foreach ($entries as $name => $contents) {
            $this->assertTrue($zip->addFromString($name, $contents), $name);
        }
        $zip->close();

        return $path;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $entries = scandir($dir);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                $this->removeDir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
