<?php

/**
 * Restore the newest plugin backup, and list installed plugins.
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

#[CoversClass(AP_Plugin_Installer::class)]
#[CoversClass(AP_Plugin_Install_Result::class)]
#[CoversClass(AP_Plugin_Install_Error::class)]
final class PluginInstallRestoreListTest extends TestCase
{
    private const OLDER = '20200101000000000001-aaaaaaa1';

    private const NEWER = '20240101000000000002-bbbbbbb2';

    private string $root;

    private string $workDir;

    private string $contentDir;

    private string $pluginsRoot;

    private ?AP_DB $db = null;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        require_once $this->root . '/ap-includes/class-ap-plugin-installer.php';
        require_once $this->root . '/ap-includes/hooks.php';
        require_once $this->root . '/ap-includes/functions.php';
        ap_reset_hooks();
        if (class_exists(AP_Plugin::class, false)) {
            AP_Plugin::reset();
        }
        $this->setRestoreFailure(null);

        $this->workDir = sys_get_temp_dir() . '/ap-plugin-restore-' . uniqid('', true);
        $this->assertTrue(mkdir($this->workDir, 0700, true));
        $workReal = realpath($this->workDir);
        $this->assertNotFalse($workReal);
        $this->workDir = $workReal;
        $this->contentDir = $this->workDir . '/content';
        $this->pluginsRoot = $this->contentDir . '/plugins';
        $this->assertTrue(mkdir($this->pluginsRoot, 0700, true));
        file_put_contents($this->pluginsRoot . '/.keep', 'stay');
    }

    protected function tearDown(): void
    {
        $this->setRestoreFailure(null);
        ap_reset_hooks();
        if (class_exists(AP_Plugin::class, false)) {
            AP_Plugin::reset();
        }
        if (class_exists(AP_Options::class, false)) {
            AP_Options::flushCache();
        }
        unset($GLOBALS['apdb']);
        $this->db = null;
        $this->removeDir($this->workDir);
    }

    public function testInvalidSlugIsRejected(): void
    {
        $this->seedFolder('sample', [
            'sample.php' => $this->pluginPhp('Sample', '2.0.0'),
        ]);
        $before = (string) file_get_contents($this->pluginsRoot . '/sample/sample.php');

        foreach (['', ' ', '../sample', 'Sample', 'a/b', 'slug.php', '-bad', 'has space', "nul\0slug"] as $slug) {
            $error = AP_Plugin_Installer::restorePrevious($slug, $this->opts());
            $this->assertInstanceOf(AP_Plugin_Install_Error::class, $error);
            $this->assertSame(AP_Plugin_Install_Error::INVALID_SLUG, $error->code());
            $this->assertNotSame('', $error->message());
        }

        $this->assertSame($before, (string) file_get_contents($this->pluginsRoot . '/sample/sample.php'));
        $this->assertDirectoryDoesNotExist($this->backupRoot());
    }

    public function testNoBackupIsRejected(): void
    {
        $live = $this->pluginPhp('Sample', '2.0.0');
        $this->seedFolder('sample', ['sample.php' => $live]);

        $error = AP_Plugin_Installer::restorePrevious('sample', $this->opts());

        $this->assertInstanceOf(AP_Plugin_Install_Error::class, $error);
        $this->assertSame(AP_Plugin_Install_Error::NO_BACKUP, $error->code());
        $this->assertStringContainsString('sample', $error->message());
        $this->assertFalse($error->toArray()['ok']);
        $this->assertSame($live, (string) file_get_contents($this->pluginsRoot . '/sample/sample.php'));
        $this->assertDirectoryDoesNotExist($this->backupRoot());
    }

    public function testRestoreFolderSwapsNewestBackupAndCanSwapBack(): void
    {
        $files = $this->seedSampleHistory();

        $result = AP_Plugin_Installer::restorePrevious(' sample ', $this->opts());

        $this->assertInstanceOf(AP_Plugin_Install_Result::class, $result);
        $this->assertSame('sample', $result->slug());
        $this->assertSame('Sample', $result->name());
        $this->assertSame('1.0.0', $result->version());
        $this->assertSame('2.0.0', $result->previousVersion());
        $this->assertSame(AP_Plugin_Install_Result::ACTION_RESTORED, $result->action());
        $this->assertFalse($result->activated());
        $this->assertSame('sample/sample.php', $result->plugin());
        $this->assertSame(AP_Plugin_Install_Result::ACTION_RESTORED, $result->toArray()['action']);
        $this->assertFalse($result->toArray()['activated']);

        $backup = $result->backupPath();
        $this->assertIsString($backup);
        $this->assertSame($backup, $result->toArray()['backup_path']);
        $this->assertMatchesRegularExpression('/\A[0-9]{20}-[a-f0-9]{8}\z/', basename($backup));
        $this->assertSame($files['live'], (string) file_get_contents($backup . '/sample/sample.php'));
        $this->assertSame("shipped\n", (string) file_get_contents($backup . '/sample/readme.txt'));
        $this->assertFileDoesNotExist($backup . '/sample/legacy.txt');
        $this->assertDirectoryDoesNotExist($this->backupRoot() . '/sample/' . self::NEWER);

        $live = $this->pluginsRoot . '/sample';
        $this->assertSame($files['backup'], (string) file_get_contents($live . '/sample.php'));
        $this->assertSame("keep-me\n", (string) file_get_contents($live . '/legacy.txt'));
        $this->assertSame(
            "<?php\n// old helper\n",
            (string) file_get_contents($live . '/includes/helper.php')
        );
        $this->assertFileDoesNotExist($live . '/readme.txt');
        $this->assertSame(
            $files['older'],
            (string) file_get_contents($this->backupRoot() . '/sample/' . self::OLDER . '/sample/sample.php')
        );
        $this->assertSame(
            $this->pluginPhp('Other', '0.1.0'),
            (string) file_get_contents($this->pluginsRoot . '/other/other.php')
        );
        $this->assertSame(
            $this->pluginPhp('Other', '0.0.1'),
            (string) file_get_contents($this->backupRoot() . '/other/' . self::OLDER . '/other/other.php')
        );
        $this->assertSame('stay', (string) file_get_contents($this->pluginsRoot . '/.keep'));
        $this->assertSame([basename($backup), self::OLDER], $this->stampNames('sample'));
        $this->assertDirectoryDoesNotExist($this->contentDir . '/.ap-plugin-staging');

        $again = ap_restore_previous_plugin('sample', $this->opts());

        $this->assertInstanceOf(AP_Plugin_Install_Result::class, $again);
        $this->assertSame('2.0.0', $again->version());
        $this->assertSame('1.0.0', $again->previousVersion());
        $this->assertSame(AP_Plugin_Install_Result::ACTION_RESTORED, $again->action());
        $this->assertSame($files['live'], (string) file_get_contents($live . '/sample.php'));
        $this->assertSame("shipped\n", (string) file_get_contents($live . '/readme.txt'));
        $this->assertFileDoesNotExist($live . '/legacy.txt');
        $secondBackup = $again->backupPath();
        $this->assertIsString($secondBackup);
        $this->assertSame($files['backup'], (string) file_get_contents($secondBackup . '/sample/sample.php'));
        $this->assertSame("keep-me\n", (string) file_get_contents($secondBackup . '/sample/legacy.txt'));
        $this->assertSame(
            $files['older'],
            (string) file_get_contents($this->backupRoot() . '/sample/' . self::OLDER . '/sample/sample.php')
        );
    }

    public function testRestoreFlatFile(): void
    {
        $live = $this->pluginPhp('Hello Flat', '2.0.0');
        $previous = $this->pluginPhp('Hello Flat', '1.0.0');
        file_put_contents($this->pluginsRoot . '/hello.php', $live);
        file_put_contents($this->pluginsRoot . '/notes.txt', "sibling\n");
        $this->seedFlatBackup('hello', self::NEWER, $previous);

        $result = ap_restore_previous_plugin('hello', $this->opts());

        $this->assertInstanceOf(AP_Plugin_Install_Result::class, $result);
        $this->assertSame('hello', $result->slug());
        $this->assertSame('Hello Flat', $result->name());
        $this->assertSame('1.0.0', $result->version());
        $this->assertSame('2.0.0', $result->previousVersion());
        $this->assertSame('hello.php', $result->plugin());
        $this->assertFalse($result->activated());
        $this->assertSame($previous, (string) file_get_contents($this->pluginsRoot . '/hello.php'));
        $this->assertSame("sibling\n", (string) file_get_contents($this->pluginsRoot . '/notes.txt'));
        $backup = $result->backupPath();
        $this->assertIsString($backup);
        $this->assertSame($live, (string) file_get_contents($backup . '/hello.php'));
        $this->assertDirectoryDoesNotExist($backup . '/hello');
        $this->assertDirectoryDoesNotExist($this->backupRoot() . '/hello/' . self::NEWER);
    }

    public function testRestoreWhenPluginIsMissingConsumesBackup(): void
    {
        $previous = $this->pluginPhp('Sample', '1.0.0');
        $older = $this->pluginPhp('Sample', '0.9.0');
        $this->seedFolderBackup('sample', self::NEWER, [
            'sample.php' => $previous,
            'legacy.txt' => "keep-me\n",
        ]);
        $this->seedFolderBackup('sample', self::OLDER, [
            'sample.php' => $older,
        ]);

        $result = AP_Plugin_Installer::restorePrevious('sample', $this->opts());

        $this->assertInstanceOf(AP_Plugin_Install_Result::class, $result);
        $this->assertSame('1.0.0', $result->version());
        $this->assertNull($result->previousVersion());
        $this->assertNull($result->backupPath());
        $this->assertFalse($result->activated());
        $this->assertSame(AP_Plugin_Install_Result::ACTION_RESTORED, $result->action());
        $this->assertSame($previous, (string) file_get_contents($this->pluginsRoot . '/sample/sample.php'));
        $this->assertSame("keep-me\n", (string) file_get_contents($this->pluginsRoot . '/sample/legacy.txt'));
        $this->assertSame([self::OLDER], $this->stampNames('sample'));
        $this->assertSame(
            $older,
            (string) file_get_contents($this->backupRoot() . '/sample/' . self::OLDER . '/sample/sample.php')
        );
    }

    public function testUnsafeNewestBackupIsNotReplacedByAnOlderOne(): void
    {
        $live = $this->pluginPhp('Sample', '3.0.0');
        $this->seedFolder('sample', ['sample.php' => $live]);
        $older = $this->pluginPhp('Sample', '0.9.0');
        $this->seedFolderBackup('sample', self::OLDER, ['sample.php' => $older]);
        $bad = $this->backupRoot() . '/sample/' . self::NEWER;
        $this->assertTrue(mkdir($bad, 0700, true));
        file_put_contents($bad . '/notes.txt', "not a plugin\n");

        $error = AP_Plugin_Installer::restorePrevious('sample', $this->opts());

        $this->assertInstanceOf(AP_Plugin_Install_Error::class, $error);
        $this->assertSame(AP_Plugin_Install_Error::RESTORE_FAILED, $error->code());
        $this->assertStringContainsString('left in place', $error->message());
        $this->assertSame($live, (string) file_get_contents($this->pluginsRoot . '/sample/sample.php'));
        $this->assertSame([self::NEWER, self::OLDER], $this->stampNames('sample'));
        $this->assertSame("not a plugin\n", (string) file_get_contents($bad . '/notes.txt'));
        $this->assertSame(
            $older,
            (string) file_get_contents($this->backupRoot() . '/sample/' . self::OLDER . '/sample/sample.php')
        );
    }

    public function testSymlinkInsideBackupIsRejected(): void
    {
        $live = $this->pluginPhp('Sample', '2.0.0');
        $previous = $this->pluginPhp('Sample', '1.0.0');
        $this->seedFolder('sample', ['sample.php' => $live]);
        $stamp = $this->seedFolderBackup('sample', self::NEWER, ['sample.php' => $previous]);
        $outside = $this->workDir . '/outside.txt';
        file_put_contents($outside, "secret\n");
        $this->assertTrue(symlink($outside, $stamp . '/sample/link.php'));

        $error = AP_Plugin_Installer::restorePrevious('sample', $this->opts());

        $this->assertInstanceOf(AP_Plugin_Install_Error::class, $error);
        $this->assertSame(AP_Plugin_Install_Error::RESTORE_FAILED, $error->code());
        $this->assertStringContainsString('left in place', $error->message());
        $this->assertSame($live, (string) file_get_contents($this->pluginsRoot . '/sample/sample.php'));
        $this->assertFileDoesNotExist($this->pluginsRoot . '/sample/link.php');
        $this->assertTrue(is_link($stamp . '/sample/link.php'));
        $this->assertSame($previous, (string) file_get_contents($stamp . '/sample/sample.php'));
    }

    public function testBothShapesAndSymlinkDestinationAreRejected(): void
    {
        $folder = $this->pluginPhp('Sample', '2.0.0');
        $flat = $this->pluginPhp('Sample File', '2.0.0');
        $previous = $this->pluginPhp('Sample', '1.0.0');
        $this->seedFolder('sample', ['sample.php' => $folder]);
        file_put_contents($this->pluginsRoot . '/sample.php', $flat);
        $this->seedFolderBackup('sample', self::NEWER, ['sample.php' => $previous]);

        $both = AP_Plugin_Installer::restorePrevious('sample', $this->opts());

        $this->assertInstanceOf(AP_Plugin_Install_Error::class, $both);
        $this->assertSame(AP_Plugin_Install_Error::RESTORE_FAILED, $both->code());
        $this->assertStringContainsString('Nothing was changed', $both->message());
        $this->assertSame($folder, (string) file_get_contents($this->pluginsRoot . '/sample/sample.php'));
        $this->assertSame($flat, (string) file_get_contents($this->pluginsRoot . '/sample.php'));
        $this->assertSame([self::NEWER], $this->stampNames('sample'));

        unlink($this->pluginsRoot . '/sample.php');
        $real = $this->workDir . '/real-sample';
        $this->assertTrue(mkdir($real, 0700, true));
        file_put_contents($real . '/sample.php', $folder);
        $this->removeDir($this->pluginsRoot . '/sample');
        $this->assertTrue(symlink($real, $this->pluginsRoot . '/sample'));

        $linked = AP_Plugin_Installer::restorePrevious('sample', $this->opts());

        $this->assertInstanceOf(AP_Plugin_Install_Error::class, $linked);
        $this->assertSame(AP_Plugin_Install_Error::RESTORE_FAILED, $linked->code());
        $this->assertStringContainsString('left unchanged', $linked->message());
        $this->assertTrue(is_link($this->pluginsRoot . '/sample'));
        $this->assertSame($folder, (string) file_get_contents($real . '/sample.php'));
        $this->assertSame(
            $previous,
            (string) file_get_contents($this->backupRoot() . '/sample/' . self::NEWER . '/sample/sample.php')
        );
    }

    public function testFailedRestorePutsTheInstalledPluginBack(): void
    {
        foreach (['after_backup', 'after_swap'] as $point) {
            $this->removeDir($this->pluginsRoot);
            $this->removeDir($this->backupRoot());
            $this->assertTrue(mkdir($this->pluginsRoot, 0700, true));
            file_put_contents($this->pluginsRoot . '/.keep', 'stay');
            $files = $this->seedSampleHistory();
            $this->setRestoreFailure($point);

            $error = AP_Plugin_Installer::restorePrevious('sample', $this->opts());

            $this->assertInstanceOf(AP_Plugin_Install_Error::class, $error, $point);
            $this->assertSame(AP_Plugin_Install_Error::RESTORE_FAILED, $error->code(), $point);
            $this->assertStringContainsString('left unchanged', $error->message(), $point);
            $live = $this->pluginsRoot . '/sample';
            $this->assertSame($files['live'], (string) file_get_contents($live . '/sample.php'), $point);
            $this->assertSame("shipped\n", (string) file_get_contents($live . '/readme.txt'), $point);
            $this->assertFileDoesNotExist($live . '/legacy.txt');
            $this->assertSame([self::NEWER, self::OLDER], $this->stampNames('sample'), $point);
            $this->assertSame(
                $files['backup'],
                (string) file_get_contents(
                    $this->backupRoot() . '/sample/' . self::NEWER . '/sample/sample.php'
                ),
                $point
            );
            $this->assertSame('stay', (string) file_get_contents($this->pluginsRoot . '/.keep'), $point);
        }
    }

    public function testRestorePrunesToTheFilterCap(): void
    {
        $seen = [];
        ap_add_filter(
            'ap_plugin_zip_max_backups',
            static function (int $max, string $slug) use (&$seen): int {
                $seen[] = [$max, $slug];

                return 1;
            },
            10,
            2
        );
        $files = $this->seedSampleHistory();

        $result = AP_Plugin_Installer::restorePrevious('sample', $this->opts());

        $this->assertInstanceOf(AP_Plugin_Install_Result::class, $result);
        $this->assertSame('1.0.0', $result->version());
        $stamps = $this->stampNames('sample');
        $this->assertCount(1, $stamps);
        $backup = $result->backupPath();
        $this->assertIsString($backup);
        $this->assertSame($this->backupRoot() . '/sample/' . $stamps[0], $backup);
        $this->assertSame($files['live'], (string) file_get_contents($backup . '/sample/sample.php'));
        $this->assertSame("shipped\n", (string) file_get_contents($backup . '/sample/readme.txt'));
        $this->assertDirectoryDoesNotExist($this->backupRoot() . '/sample/' . self::OLDER);
        $this->assertDirectoryDoesNotExist($this->backupRoot() . '/sample/' . self::NEWER);
        $this->assertSame(
            [[AP_Plugin_Installer::DEFAULT_MAX_BACKUPS, 'sample']],
            $seen
        );
        $this->assertSame(
            $this->pluginPhp('Other', '0.0.1'),
            (string) file_get_contents($this->backupRoot() . '/other/' . self::OLDER . '/other/other.php')
        );
    }

    public function testListInstalledReportsSlugNameVersionAndSkipsUnsafeRows(): void
    {
        $this->seedFolder('zeta', [
            'zeta.php' => $this->pluginPhp('Zeta', '3.0.0'),
            'extra.php' => $this->pluginPhp('Not The Main', '9.9.9'),
        ]);
        $this->seedFolder('alpha', [
            'alpha.php' => $this->pluginPhp('Alpha', '1.2.0'),
        ]);
        $this->seedFolder('dual', [
            'a.php' => $this->pluginPhp('A', '1.0.0'),
            'b.php' => $this->pluginPhp('B', '1.0.0'),
        ]);
        $this->seedFolder('Sample', [
            'Sample.php' => $this->pluginPhp('Upper', '1.0.0'),
        ]);
        file_put_contents($this->pluginsRoot . '/hello.php', $this->pluginPhp('Hello Flat', '0.4.0'));
        file_put_contents($this->pluginsRoot . '/readme.txt', "notes\n");
        file_put_contents(
            $this->pluginsRoot . '/nameless.php',
            "<?php\n/**\n * Plugin Name: Nameless\n */\n"
        );
        file_put_contents($this->pluginsRoot . '/.hidden.php', $this->pluginPhp('Hidden', '1.0.0'));
        $outside = $this->workDir . '/outside-plugin';
        $this->assertTrue(mkdir($outside, 0700, true));
        file_put_contents($outside . '/linked.php', $this->pluginPhp('Linked', '8.0.0'));
        $this->assertTrue(symlink($outside, $this->pluginsRoot . '/linked'));
        $this->assertTrue(symlink($outside . '/linked.php', $this->pluginsRoot . '/ghost.php'));

        $listed = AP_Plugin_Installer::listInstalled($this->opts());

        $this->assertSame($listed, ap_list_installed_plugins($this->opts()));
        $this->assertSame(
            [
                ['slug' => 'alpha', 'name' => 'Alpha', 'version' => '1.2.0', 'active' => false],
                ['slug' => 'hello', 'name' => 'Hello Flat', 'version' => '0.4.0', 'active' => false],
                ['slug' => 'zeta', 'name' => 'Zeta', 'version' => '3.0.0', 'active' => false],
            ],
            $listed
        );
        foreach ($listed as $row) {
            $this->assertSame(['slug', 'name', 'version', 'active'], array_keys($row));
        }
        $this->assertSame(
            [],
            AP_Plugin_Installer::listInstalled([
                'plugins_root' => $this->workDir . '/missing-plugins',
            ])
        );
    }

    public function testActiveFlagFollowsTheLivePluginsRoot(): void
    {
        $this->bootDatabase();
        $this->seedFolder('sample', [
            'sample.php' => $this->pluginPhp('Sample', '2.0.0'),
        ]);
        file_put_contents($this->pluginsRoot . '/hello.php', $this->pluginPhp('Hello Flat', '1.0.0'));
        $this->seedFolder('other', [
            'other.php' => $this->pluginPhp('Other', '0.1.0'),
        ]);
        AP_Options::update('active_plugins', ['hello.php', 'sample/sample.php'], $this->db);

        $listed = AP_Plugin_Installer::listInstalled($this->opts());

        $this->assertSame(
            [
                ['slug' => 'hello', 'name' => 'Hello Flat', 'version' => '1.0.0', 'active' => true],
                ['slug' => 'other', 'name' => 'Other', 'version' => '0.1.0', 'active' => false],
                ['slug' => 'sample', 'name' => 'Sample', 'version' => '2.0.0', 'active' => true],
            ],
            $listed
        );

        $copy = $this->workDir . '/copy-plugins';
        $this->assertTrue(mkdir($copy . '/sample', 0700, true));
        file_put_contents($copy . '/sample/sample.php', $this->pluginPhp('Sample', '2.0.0'));
        file_put_contents($copy . '/hello.php', $this->pluginPhp('Hello Flat', '1.0.0'));
        $elsewhere = AP_Plugin_Installer::listInstalled(['plugins_root' => $copy]);
        $this->assertSame(
            [
                ['slug' => 'hello', 'name' => 'Hello Flat', 'version' => '1.0.0', 'active' => false],
                ['slug' => 'sample', 'name' => 'Sample', 'version' => '2.0.0', 'active' => false],
            ],
            $elsewhere
        );
    }

    public function testActivePluginStaysActiveAndInactiveStaysInactive(): void
    {
        $this->bootDatabase();
        $activated = [];
        $upgraded = [];
        ap_add_action('ap_activate_plugin', static function (string $plugin) use (&$activated): void {
            $activated[] = $plugin;
        }, 10, 1);
        $live = $this->pluginPhp('Sample', '2.0.0');
        $previous = $this->pluginPhp('Sample', '1.0.0');
        $this->seedFolder('sample', ['sample.php' => $live]);
        $this->seedFolder('other', [
            'other.php' => $this->pluginPhp('Other', '0.1.0'),
        ]);
        $this->seedFolderBackup('sample', self::NEWER, ['sample.php' => $previous]);
        AP_Options::update('active_plugins', ['other/other.php', 'sample/sample.php'], $this->db);
        AP_Plugin::registerUpgradeHook(
            $this->pluginsRoot . '/sample/sample.php',
            static function (string $plugin, ?string $from, string $to) use (&$upgraded): void {
                $upgraded[] = [$plugin, $from, $to];
            }
        );

        $result = AP_Plugin_Installer::restorePrevious('sample', $this->opts());

        $this->assertInstanceOf(AP_Plugin_Install_Result::class, $result);
        $this->assertTrue($result->activated());
        $this->assertSame('1.0.0', $result->version());
        $this->assertSame('2.0.0', $result->previousVersion());
        $this->assertSame($previous, (string) file_get_contents($this->pluginsRoot . '/sample/sample.php'));
        $this->assertSame([], $activated);
        $this->assertSame([], $upgraded);
        $this->assertSame(
            ['other/other.php', 'sample/sample.php'],
            AP_Plugin::getActivePlugins($this->db, false)
        );
        $this->assertSame(
            [
                ['slug' => 'other', 'name' => 'Other', 'version' => '0.1.0', 'active' => true],
                ['slug' => 'sample', 'name' => 'Sample', 'version' => '1.0.0', 'active' => true],
            ],
            ap_list_installed_plugins($this->opts())
        );

        AP_Options::update('active_plugins', ['other/other.php'], $this->db);
        $this->seedFolder('sample', [
            'sample.php' => $live,
            'readme.txt' => "shipped\n",
        ]);
        $this->seedFolderBackup('quiet', self::NEWER, [
            'quiet.php' => $this->pluginPhp('Quiet', '1.0.0'),
        ]);
        $this->seedFolder('quiet', [
            'quiet.php' => $this->pluginPhp('Quiet', '2.0.0'),
        ]);

        $quiet = AP_Plugin_Installer::restorePrevious('quiet', $this->opts());

        $this->assertInstanceOf(AP_Plugin_Install_Result::class, $quiet);
        $this->assertFalse($quiet->activated());
        $this->assertSame('1.0.0', $quiet->version());
        $this->assertSame(
            ['other/other.php'],
            AP_Plugin::getActivePlugins($this->db, false)
        );
        $this->assertFalse(AP_Plugin::isActive('quiet/quiet.php', $this->db));
        $this->assertTrue(AP_Plugin::isActive('other/other.php', $this->db));
    }

    public function testRestoreRetargetsTheActiveBasename(): void
    {
        $this->bootDatabase();
        $this->seedFolder('sample', [
            'sample.php' => $this->pluginPhp('Sample', '2.0.0'),
        ]);
        $this->seedFolder('other', [
            'other.php' => $this->pluginPhp('Other', '0.1.0'),
        ]);
        $this->seedFolderBackup('sample', self::NEWER, [
            'notes.php' => $this->pluginPhp('Sample', '1.0.0'),
        ]);
        AP_Options::update('active_plugins', ['other/other.php', 'sample/sample.php'], $this->db);

        $result = AP_Plugin_Installer::restorePrevious('sample', $this->opts());

        $this->assertInstanceOf(AP_Plugin_Install_Result::class, $result);
        $this->assertTrue($result->activated());
        $this->assertSame('sample/notes.php', $result->plugin());
        $this->assertSame('1.0.0', $result->version());
        $this->assertSame('2.0.0', $result->previousVersion());
        $this->assertFileDoesNotExist($this->pluginsRoot . '/sample/sample.php');
        $this->assertSame(
            $this->pluginPhp('Sample', '1.0.0'),
            (string) file_get_contents($this->pluginsRoot . '/sample/notes.php')
        );
        $this->assertSame(
            ['other/other.php', 'sample/notes.php'],
            AP_Plugin::getActivePlugins($this->db, false)
        );
        $this->assertTrue(AP_Plugin::isActive('sample/notes.php', $this->db));
        $this->assertFalse(AP_Plugin::isActive('sample/sample.php', $this->db));
        $this->assertTrue(AP_Plugin::isActive('other/other.php', $this->db));
    }

    /**
     * @return array{live: string, backup: string, older: string}
     */
    private function seedSampleHistory(): array
    {
        $live = $this->pluginPhp('Sample', '2.0.0');
        $backup = $this->pluginPhp('Sample', '1.0.0');
        $older = $this->pluginPhp('Sample', '0.9.0');
        $this->seedFolder('sample', [
            'sample.php' => $live,
            'readme.txt' => "shipped\n",
        ]);
        $this->seedFolder('other', [
            'other.php' => $this->pluginPhp('Other', '0.1.0'),
        ]);
        $this->seedFolderBackup('sample', self::NEWER, [
            'sample.php' => $backup,
            'legacy.txt' => "keep-me\n",
            'includes/helper.php' => "<?php\n// old helper\n",
        ]);
        $this->seedFolderBackup('sample', self::OLDER, [
            'sample.php' => $older,
        ]);
        $this->seedFolderBackup('other', self::OLDER, [
            'other.php' => $this->pluginPhp('Other', '0.0.1'),
        ]);

        return [
            'live' => $live,
            'backup' => $backup,
            'older' => $older,
        ];
    }

    /**
     * @param array<string, string> $files Paths relative to the plugin folder.
     */
    private function seedFolder(string $slug, array $files): void
    {
        $this->writeFiles($this->pluginsRoot . '/' . $slug, $files);
    }

    /**
     * @param array<string, string> $files Paths relative to the backed-up plugin folder.
     */
    private function seedFolderBackup(string $slug, string $stamp, array $files): string
    {
        $dir = $this->backupRoot() . '/' . $slug . '/' . $stamp . '/' . $slug;
        $this->writeFiles($dir, $files);

        return $this->backupRoot() . '/' . $slug . '/' . $stamp;
    }

    private function seedFlatBackup(string $slug, string $stamp, string $contents): string
    {
        $dir = $this->backupRoot() . '/' . $slug . '/' . $stamp;
        $this->assertTrue(mkdir($dir, 0700, true));
        $this->assertNotFalse(file_put_contents($dir . '/' . $slug . '.php', $contents));

        return $dir;
    }

    /**
     * @param array<string, string> $files
     */
    private function writeFiles(string $root, array $files): void
    {
        foreach ($files as $rel => $contents) {
            $path = $root . '/' . $rel;
            $parent = dirname($path);
            if (!is_dir($parent) && !mkdir($parent, 0700, true) && !is_dir($parent)) {
                $this->fail('Could not create ' . $parent);
            }
            $this->assertNotFalse(file_put_contents($path, $contents));
        }
    }

    private function backupRoot(): string
    {
        return $this->contentDir . '/.ap-plugin-backups';
    }

    /**
     * Newest stamp first.
     *
     * @return list<string>
     */
    private function stampNames(string $slug): array
    {
        $dir = $this->backupRoot() . '/' . $slug;
        $entries = scandir($dir);
        $this->assertIsArray($entries);
        $names = [];
        foreach ($entries as $entry) {
            if (preg_match('/\A[0-9]{20}-[a-f0-9]{8}\z/', $entry) === 1) {
                $names[] = $entry;
            }
        }
        rsort($names, SORT_STRING);

        return array_values($names);
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

    private function bootDatabase(): void
    {
        require_once $this->root . '/ap-includes/version.php';
        require_once $this->root . '/ap-includes/class-ap-db.php';
        require_once $this->root . '/ap-includes/class-ap-migrator.php';
        require_once $this->root . '/ap-includes/class-ap-options.php';
        require_once $this->root . '/ap-includes/class-ap-plugin.php';

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
        AP_Plugin::setPluginsRootOverride($this->pluginsRoot);
    }

    private function setRestoreFailure(?string $point): void
    {
        $property = new ReflectionProperty(AP_Plugin_Installer::class, 'restoreFailurePoint');
        $property->setValue(null, $point);
    }

    private function pluginPhp(string $name, string $version): string
    {
        return "<?php\n/**\n * Plugin Name: {$name}\n * Version: {$version}\n */\n";
    }

    private function removeDir(string $dir): void
    {
        if (is_link($dir)) {
            unlink($dir);

            return;
        }
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
            if (is_link($path) || !is_dir($path)) {
                unlink($path);
                continue;
            }
            $this->removeDir($path);
        }
        rmdir($dir);
    }
}
