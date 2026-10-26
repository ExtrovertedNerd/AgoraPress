<?php

/**
 * The install `activate` option turns a plugin on after its files are in place.
 *
 * Activation failure leaves those files installed and the plugin inactive,
 * with a message that says so. A plugin that was already active stays active.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Plugin;

use AP_DB;
use AP_Migrator;
use AP_Options;
use AP_Plugin;
use AP_Plugin_Install_Error;
use AP_Plugin_Install_Result;
use AP_Plugin_Installer;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ZipArchive;

#[CoversClass(AP_Plugin_Installer::class)]
#[CoversClass(AP_Plugin_Install_Result::class)]
#[CoversClass(AP_Plugin_Install_Error::class)]
final class PluginInstallActivateTest extends TestCase
{
    private string $root;

    private string $workDir;

    private string $contentDir;

    private string $pluginsRoot;

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
        require_once $this->root . '/ap-includes/class-ap-plugin-install-result.php';
        require_once $this->root . '/ap-includes/class-ap-plugin-installer.php';
        require_once $this->root . '/ap-includes/functions.php';

        ap_reset_hooks();
        AP_Plugin::reset();
        AP_Options::flushCache();

        $this->workDir = sys_get_temp_dir() . '/ap-plugin-activate-' . uniqid('', true);
        $this->assertTrue(mkdir($this->workDir, 0700, true));
        $workReal = realpath($this->workDir);
        $this->assertNotFalse($workReal);
        $this->workDir = $workReal;
        $this->contentDir = $this->workDir . '/content';
        $this->pluginsRoot = $this->contentDir . '/plugins';
        $this->assertTrue(mkdir($this->pluginsRoot, 0700, true));

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
        AP_Plugin::setPluginsRootOverride($this->pluginsRoot);
    }

    protected function tearDown(): void
    {
        ap_reset_hooks();
        AP_Plugin::reset();
        AP_Options::flushCache();
        unset($GLOBALS['apdb']);
        $this->removeDir($this->workDir);
    }

    public function testActivateTrueActivatesANewFolderPlugin(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $marker = $this->workDir . '/marker.txt';
        $seen = [];
        ap_add_action('ap_activate_plugin', static function (string $plugin) use (&$seen): void {
            $seen[] = $plugin;
        }, 10, 1);
        $this->seedFolder('other', [
            'other.php' => $this->pluginPhp('Other', '0.1.0'),
        ]);
        AP_Options::update('active_plugins', ['other/other.php'], $this->db);
        $zip = $this->zipWith([
            'demo-notes/demo-notes.php' => $this->pluginPhp(
                'Demo Notes',
                '1.4.2',
                '',
                $this->activationHook($marker, 'activated')
            ),
            'demo-notes/readme.txt' => "shipped\n",
        ]);

        $result = AP_Plugin_Installer::install($zip, $this->opts(activate: true));

        $this->assertInstanceOf(AP_Plugin_Install_Result::class, $result);
        $this->assertSame('demo-notes', $result->slug());
        $this->assertSame('Demo Notes', $result->name());
        $this->assertSame('1.4.2', $result->version());
        $this->assertNull($result->previousVersion());
        $this->assertSame(AP_Plugin_Install_Result::ACTION_INSTALLED, $result->action());
        $this->assertNull($result->backupPath());
        $this->assertTrue($result->activated());
        $this->assertSame('demo-notes/demo-notes.php', $result->plugin());
        $this->assertTrue($result->toArray()['activated']);
        $this->assertSame('activated', (string) file_get_contents($marker));
        $this->assertSame(['demo-notes/demo-notes.php'], $seen);
        $this->assertSame(
            ['demo-notes/demo-notes.php', 'other/other.php'],
            AP_Plugin::getActivePlugins($this->db, false)
        );
        $this->assertTrue(AP_Plugin::isActive('demo-notes/demo-notes.php', $this->db));
        $this->assertTrue(AP_Plugin::isActive('other/other.php', $this->db));
        $this->assertSame("shipped\n", (string) file_get_contents($this->pluginsRoot . '/demo-notes/readme.txt'));
        $this->assertStagingGone();
    }

    public function testActivateTrueActivatesANewFlatPlugin(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $marker = $this->workDir . '/flat-marker.txt';
        $zip = $this->zipWith([
            'Hello.php' => $this->pluginPhp('Hello Flat', '0.2.0', '', $this->activationHook($marker, 'flat')),
        ]);

        $result = ap_install_plugin($zip, $this->opts(activate: true));

        $this->assertInstanceOf(AP_Plugin_Install_Result::class, $result);
        $this->assertSame('hello', $result->slug());
        $this->assertSame('hello.php', $result->plugin());
        $this->assertTrue($result->activated());
        $this->assertSame('flat', (string) file_get_contents($marker));
        $this->assertSame(['hello.php'], AP_Plugin::getActivePlugins($this->db, false));
        $this->assertFileExists($this->pluginsRoot . '/hello.php');
        $this->assertStagingGone();
    }

    public function testOnlyBooleanTrueActivates(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $cases = [
            'off' => false,
            'one' => 1,
            'text' => 'yes',
        ];
        foreach ($cases as $slug => $activate) {
            $zip = $this->zipWith([
                $slug . '/' . $slug . '.php' => $this->pluginPhp($slug, '1.0.0'),
            ]);
            $result = AP_Plugin_Installer::install($zip, $this->opts(activate: $activate));

            $this->assertInstanceOf(AP_Plugin_Install_Result::class, $result, $slug);
            $this->assertFalse($result->activated(), $slug);
            $this->assertFalse(AP_Plugin::isActive($slug . '/' . $slug . '.php', $this->db), $slug);
            $this->assertFileExists($this->pluginsRoot . '/' . $slug . '/' . $slug . '.php');
        }

        $this->assertSame([], AP_Plugin::getActivePlugins($this->db, false));
    }

    public function testActivationFailureLeavesTheNewPluginInstalledAndInactive(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $seen = [];
        ap_add_action('ap_activate_plugin', static function (string $plugin) use (&$seen): void {
            $seen[] = $plugin;
        }, 10, 1);
        $this->seedFolder('other', [
            'other.php' => $this->pluginPhp('Other', '0.1.0'),
        ]);
        AP_Options::update('active_plugins', ['other/other.php'], $this->db);
        $main = $this->pluginPhp('Future Notes', '3.1.0', " * Requires PHP: 99.0\n");
        $zip = $this->zipWith([
            'future-notes/future-notes.php' => $main,
        ]);

        $error = AP_Plugin_Installer::install($zip, $this->opts(activate: true));

        $this->assertInstanceOf(AP_Plugin_Install_Error::class, $error);
        $this->assertSame(AP_Plugin_Install_Error::ACTIVATION_FAILED, $error->code());
        $message = $error->message();
        $this->assertStringContainsString('Future Notes', $message);
        $this->assertStringContainsString('3.1.0', $message);
        $this->assertStringContainsString('future-notes/future-notes.php', $message);
        $this->assertStringContainsString('was installed', $message);
        $this->assertStringContainsString('remains inactive', $message);
        $this->assertStringContainsString('PHP 99.0', $message);
        $this->assertSame(
            [
                'ok' => false,
                'code' => AP_Plugin_Install_Error::ACTIVATION_FAILED,
                'message' => $message,
            ],
            $error->toArray()
        );
        $installed = $this->pluginsRoot . '/future-notes/future-notes.php';
        $this->assertSame($main, (string) file_get_contents($installed));
        $this->assertFalse(AP_Plugin::isActive('future-notes/future-notes.php', $this->db));
        $this->assertSame(['other/other.php'], AP_Plugin::getActivePlugins($this->db, false));
        $this->assertSame([], $seen);
        $this->assertStagingGone();
        $this->assertDirectoryDoesNotExist($this->contentDir . '/.ap-plugin-backups');
    }

    public function testLoadFailureLeavesThePluginInstalledAndInactive(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $body = "throw new \\RuntimeException('boom on load');\n";
        $zip = $this->zipWith([
            'boom/boom.php' => $this->pluginPhp('Boom', '1.2.3', '', $body),
        ]);

        $error = AP_Plugin_Installer::install($zip, $this->opts(activate: true));

        $this->assertInstanceOf(AP_Plugin_Install_Error::class, $error);
        $this->assertSame(AP_Plugin_Install_Error::ACTIVATION_FAILED, $error->code());
        $this->assertStringContainsString('remains inactive', $error->message());
        $this->assertStringContainsString('Failed to load the plugin file.', $error->message());
        $this->assertStringContainsString('Boom', $error->message());
        $this->assertFileExists($this->pluginsRoot . '/boom/boom.php');
        $this->assertSame([], AP_Plugin::getActivePlugins($this->db, false));
        $this->assertFalse(AP_Plugin::isLoaded('boom/boom.php'));
        $this->assertStagingGone();
    }

    public function testActivationFailureOnUpgradeKeepsTheNewVersionAndBackup(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $this->seedFolder('sample', [
            'sample.php' => $this->pluginPhp('Sample', '1.0.0'),
        ]);
        $zip = $this->zipWith([
            'sample/sample.php' => $this->pluginPhp('Sample', '2.0.0', " * Requires PHP: 99.0\n"),
        ]);

        $error = AP_Plugin_Installer::install($zip, $this->opts(overwrite: true, activate: true));

        $this->assertInstanceOf(AP_Plugin_Install_Error::class, $error);
        $this->assertSame(AP_Plugin_Install_Error::ACTIVATION_FAILED, $error->code());
        $this->assertStringContainsString('was upgraded', $error->message());
        $this->assertStringContainsString('remains inactive', $error->message());
        $this->assertStringContainsString('PHP 99.0', $error->message());
        $live = (string) file_get_contents($this->pluginsRoot . '/sample/sample.php');
        $this->assertStringContainsString('Version: 2.0.0', $live);
        $this->assertStringContainsString('Requires PHP: 99.0', $live);
        $this->assertFalse(AP_Plugin::isActive('sample/sample.php', $this->db));
        $this->assertSame([], AP_Plugin::getActivePlugins($this->db, false));
        $backedUp = $this->backupFile('sample', 'sample/sample.php');
        $this->assertNotNull($backedUp);
        $this->assertStringContainsString('Version: 1.0.0', (string) file_get_contents($backedUp));
        $this->assertStagingGone();
    }

    public function testAlreadyActivePluginStaysActiveWhenActivateIsRequested(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $marker = $this->workDir . '/upgrade-marker.txt';
        $this->seedFolder('sample', [
            'sample.php' => $this->pluginPhp('Sample', '1.0.0'),
        ]);
        AP_Options::update('active_plugins', ['sample/sample.php'], $this->db);
        $body = "ap_register_activation_hook(__FILE__, static function (): void {\n"
            . '    file_put_contents(' . var_export($marker, true) . ", 'activated');\n"
            . "});\n"
            . "ap_register_upgrade_hook(__FILE__, static function (\n"
            . "    string \$plugin,\n"
            . "    ?string \$from,\n"
            . "    string \$to\n"
            . "): void {\n"
            . '    file_put_contents(' . var_export($marker, true) . ", 'upgraded:' . (\$from ?? '') . ':' . \$to);\n"
            . "});\n";
        $zip = $this->zipWith([
            'sample/sample.php' => $this->pluginPhp('Sample', '2.0.0', " * Requires PHP: 99.0\n", $body),
        ]);

        $result = AP_Plugin_Installer::install($zip, $this->opts(overwrite: true, activate: true));

        $this->assertInstanceOf(AP_Plugin_Install_Result::class, $result);
        $this->assertSame(AP_Plugin_Install_Result::ACTION_UPGRADED, $result->action());
        $this->assertSame('2.0.0', $result->version());
        $this->assertSame('1.0.0', $result->previousVersion());
        $this->assertTrue($result->activated());
        $this->assertTrue(AP_Plugin::isActive('sample/sample.php', $this->db));
        $this->assertSame(['sample/sample.php'], AP_Plugin::getActivePlugins($this->db, false));
        $this->assertSame('upgraded:1.0.0:2.0.0', (string) file_get_contents($marker));
        $this->assertStringNotContainsString('activated', (string) file_get_contents($marker));
        $this->assertStringContainsString(
            'Version: 2.0.0',
            (string) file_get_contents($this->pluginsRoot . '/sample/sample.php')
        );
    }

    public function testActivateDoesNotRunWhenOverwriteIsRefused(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $original = $this->pluginPhp('Sample', '1.0.0');
        $this->seedFolder('sample', ['sample.php' => $original]);
        $zip = $this->zipWith([
            'sample/sample.php' => $this->pluginPhp('Sample', '2.0.0'),
        ]);

        $error = AP_Plugin_Installer::install($zip, $this->opts(activate: true));

        $this->assertInstanceOf(AP_Plugin_Install_Error::class, $error);
        $this->assertSame(AP_Plugin_Install_Error::ALREADY_INSTALLED, $error->code());
        $this->assertSame($original, (string) file_get_contents($this->pluginsRoot . '/sample/sample.php'));
        $this->assertSame([], AP_Plugin::getActivePlugins($this->db, false));
        $this->assertStagingGone();
    }

    public function testValidateDoesNotActivate(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $zip = $this->zipWith([
            'preview/preview.php' => $this->pluginPhp('Preview', '1.0.0'),
        ]);

        $result = AP_Plugin_Installer::validate($zip, $this->opts(activate: true));

        $this->assertInstanceOf(AP_Plugin_Install_Result::class, $result);
        $this->assertFalse($result->activated());
        $this->assertSame(AP_Plugin_Install_Result::ACTION_INSTALLED, $result->action());
        $this->assertDirectoryDoesNotExist($this->pluginsRoot . '/preview');
        $this->assertSame([], AP_Plugin::getActivePlugins($this->db, false));
        $this->assertStagingGone();
    }

    public function testActivateOutsideTheActivePluginsDirectoryDoesNotChangeTheActiveList(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $liveOriginal = $this->pluginPhp('Sample', '1.0.0');
        $this->seedFolder('sample', ['sample.php' => $liveOriginal]);
        AP_Options::update('active_plugins', ['sample/sample.php'], $this->db);

        $foreignContent = $this->workDir . '/foreign-content';
        $foreignPlugins = $foreignContent . '/plugins';
        $this->assertTrue(mkdir($foreignPlugins . '/sample', 0700, true));
        file_put_contents($foreignPlugins . '/sample/sample.php', $this->pluginPhp('Sample', '1.0.0'));
        $zip = $this->zipWith([
            'sample/sample.php' => $this->pluginPhp('Sample', '9.0.0'),
        ]);

        $error = AP_Plugin_Installer::install($zip, [
            'overwrite' => true,
            'activate' => true,
            'plugins_root' => $foreignPlugins,
            'content_dir' => $foreignContent,
        ]);

        $this->assertInstanceOf(AP_Plugin_Install_Error::class, $error);
        $this->assertSame(AP_Plugin_Install_Error::ACTIVATION_FAILED, $error->code());
        $this->assertStringContainsString('was upgraded', $error->message());
        $this->assertStringContainsString('not the active plugins directory', $error->message());
        $this->assertStringContainsString('left unchanged', $error->message());
        $this->assertStringNotContainsString('remains inactive', $error->message());
        $this->assertSame($liveOriginal, (string) file_get_contents($this->pluginsRoot . '/sample/sample.php'));
        $this->assertStringContainsString(
            'Version: 9.0.0',
            (string) file_get_contents($foreignPlugins . '/sample/sample.php')
        );
        $this->assertSame(['sample/sample.php'], AP_Plugin::getActivePlugins($this->db, false));
        $this->assertTrue(AP_Plugin::isActive('sample/sample.php', $this->db));
    }

    private function activationHook(string $marker, string $text): string
    {
        return "ap_register_activation_hook(__FILE__, static function (): void {\n"
            . '    file_put_contents(' . var_export($marker, true) . ', ' . var_export($text, true) . ");\n"
            . "});\n";
    }

    private function pluginPhp(string $name, string $version, string $extra = '', string $body = ''): string
    {
        return "<?php\n/**\n * Plugin Name: {$name}\n * Version: {$version}\n{$extra} */\n{$body}";
    }

    /**
     * @param array<string, string> $files Paths relative to the plugin folder.
     */
    private function seedFolder(string $slug, array $files): void
    {
        foreach ($files as $rel => $contents) {
            $path = $this->pluginsRoot . '/' . $slug . '/' . $rel;
            $parent = dirname($path);
            if (!is_dir($parent)) {
                $this->assertTrue(mkdir($parent, 0700, true));
            }
            file_put_contents($path, $contents);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function opts(mixed $activate = null, bool $overwrite = false): array
    {
        $opts = [
            'plugins_root' => $this->pluginsRoot,
            'content_dir' => $this->contentDir,
        ];
        if ($overwrite) {
            $opts['overwrite'] = true;
        }
        if ($activate !== null) {
            $opts['activate'] = $activate;
        }

        return $opts;
    }

    private function assertStagingGone(): void
    {
        $this->assertDirectoryDoesNotExist($this->contentDir . '/.ap-plugin-staging');
        $this->assertDirectoryDoesNotExist($this->pluginsRoot . '/.ap-plugin-staging');
    }

    private function backupFile(string $slug, string $relative): ?string
    {
        $root = $this->contentDir . '/.ap-plugin-backups/' . $slug;
        if (!is_dir($root)) {
            return null;
        }
        $stamps = scandir($root);
        if ($stamps === false) {
            return null;
        }
        rsort($stamps);
        foreach ($stamps as $stamp) {
            if ($stamp === '.' || $stamp === '..') {
                continue;
            }
            $path = $root . '/' . $stamp . '/' . $relative;
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
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
