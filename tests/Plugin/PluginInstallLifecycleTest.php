<?php

/**
 * Before/after actions for plugin install, upgrade, activate, deactivate, and restore.
 *
 * Limit filters still apply on those paths. A listener exception does not cancel
 * the operation. After does not run when a failed upgrade or restore is rolled back.
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
use ReflectionProperty;
use ZipArchive;

#[CoversClass(AP_Plugin_Installer::class)]
#[CoversClass(AP_Plugin::class)]
final class PluginInstallLifecycleTest extends TestCase
{
    private string $root;

    private string $workDir;

    private string $contentDir;

    private string $pluginsRoot;

    private ?AP_DB $db = null;

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
        $this->setUpgradeFailure(null);
        $this->setRestoreFailure(null);

        $this->workDir = sys_get_temp_dir() . '/ap-plugin-lifecycle-' . uniqid('', true);
        $this->assertTrue(mkdir($this->workDir, 0700, true));
        $workReal = realpath($this->workDir);
        $this->assertNotFalse($workReal);
        $this->workDir = $workReal;
        $this->contentDir = $this->workDir . '/content';
        $this->pluginsRoot = $this->contentDir . '/plugins';
        $this->assertTrue(mkdir($this->pluginsRoot, 0700, true));
    }

    protected function tearDown(): void
    {
        $this->setUpgradeFailure(null);
        $this->setRestoreFailure(null);
        ap_reset_hooks();
        AP_Plugin::reset();
        AP_Options::flushCache();
        unset($GLOBALS['apdb']);
        $this->removeDir($this->workDir);
    }

    public function testNewInstallFiresBeforeThenAfterAndConsultsZipLimits(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $limits = [
            'bytes' => [],
            'entries' => [],
            'uncompressed' => [],
            'backups' => [],
        ];
        $this->watchLimits($limits);
        $log = [];
        $destMissing = null;
        $pluginsRoot = $this->pluginsRoot;
        $this->watchLifecycle($log);
        ap_add_action('ap_plugin_before_install', static function () use (&$destMissing, $pluginsRoot): void {
            $destMissing = !is_dir($pluginsRoot . '/sample');
        });

        $result = AP_Plugin_Installer::install($this->sampleZip('1.0.0'), $this->opts());

        $this->assertInstanceOf(AP_Plugin_Install_Result::class, $result);
        $this->assertTrue($destMissing);
        $this->assertSame(
            ['ap_plugin_before_install', 'ap_plugin_after_install'],
            array_column($log, 0)
        );
        $this->assertLifecycleContext($log[0][1], 'installed', '1.0.0', null, false, null);
        $this->assertLifecycleContext($log[1][1], 'installed', '1.0.0', null, false, null);
        $this->assertSame('sample/sample.php', $log[1][1]['plugin']);
        $this->assertFileExists($this->pluginsRoot . '/sample/sample.php');
        $this->assertSame([], $limits['backups']);
        $this->assertNotSame([], $limits['bytes']);
        $this->assertSame(AP_Plugin_Installer::DEFAULT_VALIDATE_MAX_BYTES, $limits['bytes'][0][0]);
        $this->assertSame(AP_Plugin_Installer::DEFAULT_MAX_ENTRIES, $limits['entries'][0][0]);
        $this->assertSame(
            AP_Plugin_Installer::DEFAULT_MAX_UNCOMPRESSED_BYTES,
            $limits['uncompressed'][0][0]
        );
        $zip = $limits['bytes'][0][1];
        $this->assertIsString($zip);
        $this->assertSame($zip, $limits['entries'][0][1]);
        $this->assertSame($zip, $limits['uncompressed'][0][1]);
    }

    public function testOversizeFilterRejectsBeforeAnyLifecycleAction(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $log = [];
        $this->watchLifecycle($log);
        ap_add_filter('ap_plugin_zip_max_bytes', static function (): int {
            return 1;
        });

        $error = ap_install_plugin($this->sampleZip('1.0.0'), $this->opts());

        $this->assertInstanceOf(AP_Plugin_Install_Error::class, $error);
        $this->assertSame(AP_Plugin_Install_Error::OVERSIZE, $error->code());
        $this->assertSame([], $log);
        $this->assertDirectoryDoesNotExist($this->pluginsRoot . '/sample');
    }

    public function testRefusedOverwriteAndValidateDoNotFireLifecycleActions(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $this->seedFolder('sample', ['sample.php' => $this->pluginPhp('Sample', '1.0.0')]);
        $log = [];
        $this->watchLifecycle($log);
        $zip = $this->sampleZip('2.0.0');

        $refused = AP_Plugin_Installer::install($zip, $this->opts());
        $validated = AP_Plugin_Installer::validate($zip, $this->opts());
        $missing = AP_Plugin_Installer::install($this->workDir . '/missing.zip', $this->opts());

        $this->assertSame(AP_Plugin_Install_Error::ALREADY_INSTALLED, $refused->code());
        $this->assertInstanceOf(AP_Plugin_Install_Result::class, $validated);
        $this->assertSame(AP_Plugin_Install_Result::ACTION_UPGRADED, $validated->action());
        $this->assertSame(AP_Plugin_Install_Error::MISSING_FILE, $missing->code());
        $this->assertSame([], $log);
        $this->assertStringContainsString('Version: 1.0.0', (string) file_get_contents(
            $this->pluginsRoot . '/sample/sample.php'
        ));
    }

    public function testUpgradeAndRestoreFireInOrderAndRollbackSkipsAfter(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $limits = [
            'bytes' => [],
            'entries' => [],
            'uncompressed' => [],
            'backups' => [],
        ];
        $this->watchLimits($limits);
        $log = [];
        $this->watchLifecycle($log);
        $stillOld = null;
        ap_add_action('ap_plugin_before_upgrade', function () use (&$stillOld): void {
            $stillOld = str_contains(
                (string) file_get_contents($this->pluginsRoot . '/sample/sample.php'),
                'Version: 1.0.0'
            );
        });

        $this->assertInstanceOf(
            AP_Plugin_Install_Result::class,
            AP_Plugin_Installer::install($this->sampleZip('1.0.0'), $this->opts())
        );
        $log = [];
        $upgraded = AP_Plugin_Installer::install($this->sampleZip('2.0.0'), $this->opts(true));

        $this->assertInstanceOf(AP_Plugin_Install_Result::class, $upgraded);
        $this->assertTrue($stillOld);
        $this->assertSame(
            ['ap_plugin_before_upgrade', 'ap_plugin_after_upgrade'],
            array_column($log, 0)
        );
        $backup = $upgraded->backupPath();
        $this->assertIsString($backup);
        $this->assertLifecycleContext($log[0][1], 'upgraded', '2.0.0', '1.0.0', false, null);
        $this->assertLifecycleContext($log[1][1], 'upgraded', '2.0.0', '1.0.0', false, $backup);
        $this->assertSame([[AP_Plugin_Installer::DEFAULT_MAX_BACKUPS, 'sample']], $limits['backups']);

        $log = [];
        $this->setUpgradeFailure('after_backup');
        $failed = AP_Plugin_Installer::install($this->sampleZip('3.0.0'), $this->opts(true));
        $this->assertInstanceOf(AP_Plugin_Install_Error::class, $failed);
        $this->assertSame(['ap_plugin_before_upgrade'], array_column($log, 0));
        $this->assertStringContainsString('Version: 2.0.0', (string) file_get_contents(
            $this->pluginsRoot . '/sample/sample.php'
        ));

        $log = [];
        $stillNew = null;
        ap_add_action('ap_plugin_before_restore', function () use (&$stillNew): void {
            $stillNew = str_contains(
                (string) file_get_contents($this->pluginsRoot . '/sample/sample.php'),
                'Version: 2.0.0'
            );
        });
        $this->setRestoreFailure('after_backup');
        $rolled = AP_Plugin_Installer::restorePrevious('sample', $this->opts());
        $this->assertInstanceOf(AP_Plugin_Install_Error::class, $rolled);
        $this->assertTrue($stillNew);
        $this->assertSame(['ap_plugin_before_restore'], array_column($log, 0));
        $this->assertLifecycleContext($log[0][1], 'restored', '1.0.0', '2.0.0', false, null);
        $this->assertStringContainsString('Version: 2.0.0', (string) file_get_contents(
            $this->pluginsRoot . '/sample/sample.php'
        ));

        $log = [];
        $limits['backups'] = [];
        $restored = ap_restore_previous_plugin('sample', $this->opts());
        $this->assertInstanceOf(AP_Plugin_Install_Result::class, $restored);
        $this->assertSame(AP_Plugin_Install_Result::ACTION_RESTORED, $restored->action());
        $this->assertSame('1.0.0', $restored->version());
        $this->assertSame(
            ['ap_plugin_before_restore', 'ap_plugin_after_restore'],
            array_column($log, 0)
        );
        $displaced = $restored->backupPath();
        $this->assertIsString($displaced);
        $this->assertLifecycleContext($log[1][1], 'restored', '1.0.0', '2.0.0', false, $displaced);
        $this->assertSame([[AP_Plugin_Installer::DEFAULT_MAX_BACKUPS, 'sample']], $limits['backups']);
        $this->assertStringContainsString('Version: 1.0.0', (string) file_get_contents(
            $this->pluginsRoot . '/sample/sample.php'
        ));
    }

    public function testThrowingInstallListenerDoesNotCancelTheInstall(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $log = [];
        $this->watchLifecycle($log);
        ap_add_action('ap_plugin_before_install', static function (): void {
            throw new \RuntimeException('listener failed');
        }, 20, 1);
        ap_add_action('ap_plugin_after_install', static function (): void {
            throw new \RuntimeException('after failed');
        }, 20, 1);

        $result = AP_Plugin_Installer::install($this->sampleZip('1.0.0'), $this->opts());

        $this->assertInstanceOf(AP_Plugin_Install_Result::class, $result);
        $this->assertSame('1.0.0', $result->version());
        $this->assertSame(
            ['ap_plugin_before_install', 'ap_plugin_after_install'],
            array_column($log, 0)
        );
        $this->assertFileExists($this->pluginsRoot . '/sample/sample.php');
    }

    public function testActivateAndDeactivateFireBeforeAndAfter(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $this->bootDatabase();
        $log = [];
        $this->watchLifecycle($log);
        ap_add_action('ap_activate_plugin', static function (string $plugin) use (&$log): void {
            $log[] = ['ap_activate_plugin', $plugin];
        }, 10, 1);
        ap_add_action('ap_deactivate_plugin', static function (string $plugin) use (&$log): void {
            $log[] = ['ap_deactivate_plugin', $plugin];
        }, 10, 1);

        $result = AP_Plugin_Installer::install($this->sampleZip('1.4.0'), $this->opts(activate: true));

        $this->assertInstanceOf(AP_Plugin_Install_Result::class, $result);
        $this->assertTrue($result->activated());
        $this->assertSame(
            [
                'ap_plugin_before_install',
                'ap_plugin_before_activate',
                'ap_activate_plugin',
                'ap_plugin_after_activate',
                'ap_plugin_after_install',
            ],
            array_column($log, 0)
        );
        $this->assertSame('sample/sample.php', $log[1][1]);
        $this->assertSame('sample/sample.php', $log[2][1]);
        $this->assertSame('sample/sample.php', $log[3][1]);
        $this->assertLifecycleContext($log[4][1], 'installed', '1.4.0', null, true, null);
        $this->assertTrue(AP_Plugin::isActive('sample/sample.php', $this->db));

        $log = [];
        $again = ap_activate_plugin('sample/sample.php', $this->db);
        $this->assertTrue($again['ok']);
        $this->assertSame([], $log);

        $log = [];
        $off = ap_deactivate_plugin('sample/sample.php', $this->db);
        $this->assertTrue($off['ok']);
        $this->assertSame(
            [
                'ap_plugin_before_deactivate',
                'ap_deactivate_plugin',
                'ap_plugin_after_deactivate',
            ],
            array_column($log, 0)
        );
        $this->assertSame('sample/sample.php', $log[0][1]);
        $this->assertFalse(AP_Plugin::isActive('sample/sample.php', $this->db));

        $log = [];
        $quiet = AP_Plugin::deactivate('sample/sample.php', $this->db);
        $this->assertTrue($quiet['ok']);
        $this->assertSame([], $log);

        $log = [];
        ap_add_action('ap_plugin_before_activate', static function (): void {
            throw new \RuntimeException('activate listener failed');
        }, 20, 1);
        $forced = ap_activate_plugin('sample/sample.php', $this->db);
        $this->assertTrue($forced['ok']);
        $this->assertSame(
            ['ap_plugin_before_activate', 'ap_activate_plugin', 'ap_plugin_after_activate'],
            array_column($log, 0)
        );
        $this->assertTrue(AP_Plugin::isActive('sample/sample.php', $this->db));
    }

    public function testActivationFailureFiresInstallAfterAndNotActivateAfter(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $this->bootDatabase();
        $log = [];
        $this->watchLifecycle($log);
        ap_add_action('ap_activate_plugin', static function (string $plugin) use (&$log): void {
            $log[] = ['ap_activate_plugin', $plugin];
        }, 10, 1);
        $zip = $this->zipWith([
            'future/future.php' => $this->pluginPhp('Future', '3.0.0', " * Requires PHP: 99.0\n"),
        ]);

        $error = AP_Plugin_Installer::install($zip, $this->opts(activate: true));

        $this->assertInstanceOf(AP_Plugin_Install_Error::class, $error);
        $this->assertSame(AP_Plugin_Install_Error::ACTIVATION_FAILED, $error->code());
        $this->assertSame(
            ['ap_plugin_before_install', 'ap_plugin_after_install'],
            array_column($log, 0)
        );
        $this->assertLifecycleContext(
            $log[1][1],
            'installed',
            '3.0.0',
            null,
            false,
            null,
            'future',
            'Future',
            'future/future.php'
        );
        $this->assertFileExists($this->pluginsRoot . '/future/future.php');
        $this->assertFalse(AP_Plugin::isActive('future/future.php', $this->db));

        $log = [];
        $boom = $this->zipWith([
            'boom/boom.php' => $this->pluginPhp('Boom', '1.2.3') . "throw new \\RuntimeException('boom on load');\n",
        ]);
        $loadError = AP_Plugin_Installer::install($boom, $this->opts(activate: true));
        $this->assertSame(AP_Plugin_Install_Error::ACTIVATION_FAILED, $loadError->code());
        $this->assertSame(
            [
                'ap_plugin_before_install',
                'ap_plugin_before_activate',
                'ap_plugin_after_install',
            ],
            array_column($log, 0)
        );
        $this->assertSame('boom/boom.php', $log[1][1]);
        $this->assertFalse($log[2][1]['activated']);
        $this->assertFalse(AP_Plugin::isActive('boom/boom.php', $this->db));
    }

    public function testActiveUpgradeDoesNotFireActivateActions(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $this->bootDatabase();
        $this->seedFolder('sample', ['sample.php' => $this->pluginPhp('Sample', '1.0.0')]);
        AP_Options::update('active_plugins', ['sample/sample.php'], $this->db);
        $log = [];
        $this->watchLifecycle($log);

        $result = AP_Plugin_Installer::install($this->sampleZip('2.0.0'), $this->opts(true, true));

        $this->assertInstanceOf(AP_Plugin_Install_Result::class, $result);
        $this->assertTrue($result->activated());
        $this->assertSame(
            ['ap_plugin_before_upgrade', 'ap_plugin_after_upgrade'],
            array_column($log, 0)
        );
        $this->assertLifecycleContext(
            $log[1][1],
            'upgraded',
            '2.0.0',
            '1.0.0',
            true,
            $result->backupPath()
        );
        $this->assertTrue(AP_Plugin::isActive('sample/sample.php', $this->db));
    }

    /**
     * @param list<array{0: string, 1: mixed}> $log
     */
    private function watchLifecycle(array &$log): void
    {
        $hooks = [
            'ap_plugin_before_install',
            'ap_plugin_after_install',
            'ap_plugin_before_upgrade',
            'ap_plugin_after_upgrade',
            'ap_plugin_before_activate',
            'ap_plugin_after_activate',
            'ap_plugin_before_deactivate',
            'ap_plugin_after_deactivate',
            'ap_plugin_before_restore',
            'ap_plugin_after_restore',
        ];
        foreach ($hooks as $hook) {
            ap_add_action($hook, static function (mixed $arg) use (&$log, $hook): void {
                $log[] = [$hook, $arg];
            });
        }
    }

    /**
     * @param array{
     *   bytes: list<array{0: mixed, 1: mixed}>,
     *   entries: list<array{0: mixed, 1: mixed}>,
     *   uncompressed: list<array{0: mixed, 1: mixed}>,
     *   backups: list<array{0: mixed, 1: mixed}>
     * } $seen
     */
    private function watchLimits(array &$seen): void
    {
        ap_add_filter('ap_plugin_zip_max_bytes', static function (mixed $cap, mixed $path) use (&$seen): mixed {
            $seen['bytes'][] = [$cap, $path];

            return $cap;
        }, 10, 2);
        ap_add_filter('ap_plugin_zip_max_entries', static function (mixed $cap, mixed $path) use (&$seen): mixed {
            $seen['entries'][] = [$cap, $path];

            return $cap;
        }, 10, 2);
        ap_add_filter(
            'ap_plugin_zip_max_uncompressed_bytes',
            static function (mixed $cap, mixed $path) use (&$seen): mixed {
                $seen['uncompressed'][] = [$cap, $path];

                return $cap;
            },
            10,
            2
        );
        ap_add_filter('ap_plugin_zip_max_backups', static function (mixed $cap, mixed $slug) use (&$seen): mixed {
            $seen['backups'][] = [$cap, $slug];

            return $cap;
        }, 10, 2);
    }

    /**
     * @param mixed $context
     */
    private function assertLifecycleContext(
        mixed $context,
        string $action,
        string $version,
        ?string $previous,
        bool $activated,
        ?string $backup,
        string $slug = 'sample',
        string $name = 'Sample',
        string $plugin = 'sample/sample.php'
    ): void {
        $this->assertIsArray($context);
        $this->assertSame(
            [
                'slug',
                'name',
                'version',
                'previous_version',
                'plugin',
                'action',
                'backup_path',
                'activated',
            ],
            array_keys($context)
        );
        $this->assertSame($slug, $context['slug']);
        $this->assertSame($name, $context['name']);
        $this->assertSame($version, $context['version']);
        $this->assertSame($previous, $context['previous_version']);
        $this->assertSame($plugin, $context['plugin']);
        $this->assertSame($action, $context['action']);
        $this->assertSame($backup, $context['backup_path']);
        $this->assertSame($activated, $context['activated']);
    }

    private function bootDatabase(): void
    {
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

    private function setUpgradeFailure(?string $point): void
    {
        $property = new ReflectionProperty(AP_Plugin_Installer::class, 'upgradeFailurePoint');
        $property->setValue(null, $point);
    }

    private function setRestoreFailure(?string $point): void
    {
        $property = new ReflectionProperty(AP_Plugin_Installer::class, 'restoreFailurePoint');
        $property->setValue(null, $point);
    }

    /**
     * @return array{plugins_root: string, content_dir: string, overwrite?: true, activate?: true}
     */
    private function opts(bool $overwrite = false, bool $activate = false): array
    {
        $opts = [
            'plugins_root' => $this->pluginsRoot,
            'content_dir' => $this->contentDir,
        ];
        if ($overwrite) {
            $opts['overwrite'] = true;
        }
        if ($activate) {
            $opts['activate'] = true;
        }

        return $opts;
    }

    private function sampleZip(string $version): string
    {
        return $this->zipWith([
            'sample/sample.php' => $this->pluginPhp('Sample', $version),
            'sample/readme.txt' => "notes\n",
        ]);
    }

    private function pluginPhp(string $name, string $version, string $extra = ''): string
    {
        return "<?php\n/**\n * Plugin Name: {$name}\n * Version: {$version}\n{$extra} */\n";
    }

    /**
     * @param array<string, string> $files
     */
    private function seedFolder(string $slug, array $files): void
    {
        foreach ($files as $rel => $contents) {
            $path = $this->pluginsRoot . '/' . $slug . '/' . $rel;
            $parent = dirname($path);
            if (!is_dir($parent)) {
                $this->assertTrue(mkdir($parent, 0700, true));
            }
            $this->assertNotFalse(file_put_contents($path, $contents));
        }
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
