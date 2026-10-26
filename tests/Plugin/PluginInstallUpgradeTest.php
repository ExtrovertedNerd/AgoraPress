<?php

/**
 * Tests for AP_Plugin_Installer::install() when the slug already exists.
 *
 * Overwrite must be boolean true. A successful upgrade stores the previous
 * plugin in a timestamped backup, swaps the new payload into place, and keeps
 * only the newest N backups. A failed swap puts the previous plugin back.
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
use ReflectionProperty;
use ZipArchive;

#[CoversClass(AP_Plugin_Installer::class)]
#[CoversClass(AP_Plugin_Install_Result::class)]
#[CoversClass(AP_Plugin_Install_Error::class)]
final class PluginInstallUpgradeTest extends TestCase
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
        $this->setUpgradeFailure(null);

        $this->workDir = sys_get_temp_dir() . '/ap-plugin-upgrade-' . uniqid('', true);
        $this->contentDir = $this->workDir . '/content';
        $this->pluginsRoot = $this->contentDir . '/plugins';
        $this->assertTrue(mkdir($this->pluginsRoot, 0700, true));
        file_put_contents($this->pluginsRoot . '/.keep', 'stay');
    }

    protected function tearDown(): void
    {
        $this->setUpgradeFailure(null);
        ap_reset_hooks();
        if (is_dir($this->pluginsRoot)) {
            @chmod($this->pluginsRoot, 0700);
        }
        $this->removeDir($this->workDir);
    }

    public function testOverwriteMustBeBooleanTrue(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $dir = $this->pluginsRoot . '/sample';
        $this->assertTrue(mkdir($dir, 0700, true));
        $original = $this->pluginPhp('Sample', '1.0.0');
        file_put_contents($dir . '/sample.php', $original);
        file_put_contents($dir . '/legacy.txt', "keep-me\n");
        $zip = $this->zipWith([
            'sample/sample.php' => $this->pluginPhp('Sample', '2.0.0'),
            'sample/readme.txt' => "replacement\n",
        ]);

        foreach ([false, 1, 'true'] as $overwrite) {
            $error = AP_Plugin_Installer::install($zip, $this->opts() + ['overwrite' => $overwrite]);
            $this->assertInstanceOf(AP_Plugin_Install_Error::class, $error);
            $this->assertSame(AP_Plugin_Install_Error::ALREADY_INSTALLED, $error->code());
            $this->assertStringContainsString('left unchanged', strtolower($error->message()));
            $this->assertSame($original, (string) file_get_contents($dir . '/sample.php'));
            $this->assertSame("keep-me\n", (string) file_get_contents($dir . '/legacy.txt'));
            $this->assertFileDoesNotExist($dir . '/readme.txt');
        }

        $this->assertDirectoryDoesNotExist($this->backupRoot());
        $this->assertStagingGone();
    }

    public function testUpgradeFolderMakesTimestampedBackupAndSwaps(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $original = $this->pluginPhp('Sample', '1.0.0');
        $replacement = $this->pluginPhp('Sample', '2.4.0');
        $this->seedFolder('sample', [
            'sample.php' => $original,
            'legacy.txt' => "keep-me\n",
            'includes/helper.php' => "<?php\n// old helper\n",
        ]);
        $this->seedFolder('other', [
            'other.php' => $this->pluginPhp('Other', '0.1.0'),
        ]);
        $zip = $this->zipWith([
            'sample/sample.php' => $replacement,
            'sample/readme.txt' => "shipped\n",
        ]);

        $validated = AP_Plugin_Installer::validate($zip, $this->opts());
        $this->assertInstanceOf(AP_Plugin_Install_Result::class, $validated);
        $this->assertSame(AP_Plugin_Install_Result::ACTION_UPGRADED, $validated->action());
        $this->assertNull($validated->backupPath());
        $this->assertDirectoryDoesNotExist($this->backupRoot());
        $this->assertSame($original, (string) file_get_contents($this->pluginsRoot . '/sample/sample.php'));

        $result = AP_Plugin_Installer::install($zip, $this->opts(true));

        $this->assertInstanceOf(AP_Plugin_Install_Result::class, $result);
        $this->assertSame('sample', $result->slug());
        $this->assertSame('Sample', $result->name());
        $this->assertSame('2.4.0', $result->version());
        $this->assertSame('1.0.0', $result->previousVersion());
        $this->assertSame(AP_Plugin_Install_Result::ACTION_UPGRADED, $result->action());
        $this->assertFalse($result->activated());
        $this->assertSame('sample/sample.php', $result->plugin());

        $backup = $result->backupPath();
        $this->assertIsString($backup);
        $this->assertSame($backup, $result->toArray()['backup_path']);
        $this->assertSame(AP_Plugin_Install_Result::ACTION_UPGRADED, $result->toArray()['action']);
        $this->assertMatchesRegularExpression('/\A[0-9]{20}-[a-f0-9]{8}\z/', basename($backup));
        $this->assertSame('sample', basename(dirname($backup)));
        $this->assertSame('.ap-plugin-backups', basename(dirname($backup, 2)));
        $this->assertSame(0700, fileperms($backup) & 0777);
        $this->assertSame(0700, fileperms(dirname($backup)) & 0777);
        $this->assertSame(0700, fileperms($this->backupRoot()) & 0777);

        $deny = $this->backupRoot() . '/.htaccess';
        $this->assertFileExists($deny);
        $this->assertStringContainsString('Require all denied', (string) file_get_contents($deny));

        $backed = $backup . '/sample';
        $this->assertSame($original, (string) file_get_contents($backed . '/sample.php'));
        $this->assertSame("keep-me\n", (string) file_get_contents($backed . '/legacy.txt'));
        $this->assertSame(
            "<?php\n// old helper\n",
            (string) file_get_contents($backed . '/includes/helper.php')
        );
        $this->assertFileDoesNotExist($backed . '/readme.txt');

        $live = $this->pluginsRoot . '/sample';
        $this->assertSame($replacement, (string) file_get_contents($live . '/sample.php'));
        $this->assertSame("shipped\n", (string) file_get_contents($live . '/readme.txt'));
        $this->assertFileDoesNotExist($live . '/legacy.txt');
        $this->assertFileDoesNotExist($live . '/includes/helper.php');
        $this->assertFalse(is_link($live));
        $this->assertSame(
            $this->pluginPhp('Other', '0.1.0'),
            (string) file_get_contents($this->pluginsRoot . '/other/other.php')
        );
        $this->assertSame('stay', (string) file_get_contents($this->pluginsRoot . '/.keep'));
        $this->assertBackupStaysOutsidePlugins($backup);
        $this->assertSame(['.', '..', '.ap-plugin-backups', 'plugins'], $this->listDir($this->contentDir));
        $this->assertStagingGone();
    }

    public function testUpgradeFlatFileMakesTimestampedBackup(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $original = $this->pluginPhp('Hello Flat', '1.0.0');
        $replacement = $this->pluginPhp('Hello Flat', '2.0.0');
        file_put_contents($this->pluginsRoot . '/hello.php', $original);
        file_put_contents($this->pluginsRoot . '/notes.txt', "sibling\n");
        $zip = $this->zipWith([
            'hello.php' => $replacement,
        ]);

        $result = AP_Plugin_Installer::install($zip, $this->opts(true));

        $this->assertInstanceOf(AP_Plugin_Install_Result::class, $result);
        $this->assertSame('hello', $result->slug());
        $this->assertSame('2.0.0', $result->version());
        $this->assertSame('1.0.0', $result->previousVersion());
        $this->assertSame(AP_Plugin_Install_Result::ACTION_UPGRADED, $result->action());
        $this->assertSame('hello.php', $result->plugin());
        $this->assertFalse($result->activated());
        $backup = $result->backupPath();
        $this->assertIsString($backup);
        $this->assertSame($original, (string) file_get_contents($backup . '/hello.php'));
        $this->assertDirectoryDoesNotExist($backup . '/hello');
        $this->assertSame($replacement, (string) file_get_contents($this->pluginsRoot . '/hello.php'));
        $this->assertSame("sibling\n", (string) file_get_contents($this->pluginsRoot . '/notes.txt'));
        $this->assertDirectoryDoesNotExist($this->pluginsRoot . '/hello');
        $this->assertBackupStaysOutsidePlugins($backup);
        $this->assertStagingGone();
    }

    public function testNewSlugWithOverwriteTrueDoesNotCreateABackup(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $zip = $this->zipWith([
            'fresh/fresh.php' => $this->pluginPhp('Fresh', '1.0.0'),
        ]);

        $result = AP_Plugin_Installer::install($zip, $this->opts(true));

        $this->assertInstanceOf(AP_Plugin_Install_Result::class, $result);
        $this->assertSame(AP_Plugin_Install_Result::ACTION_INSTALLED, $result->action());
        $this->assertNull($result->previousVersion());
        $this->assertNull($result->backupPath());
        $this->assertFileExists($this->pluginsRoot . '/fresh/fresh.php');
        $this->assertDirectoryDoesNotExist($this->backupRoot());
        $this->assertStagingGone();
    }

    public function testFolderWithoutVersionHeaderStillBacksUp(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $original = "<?php\n// old body\n";
        $this->seedFolder('sample', [
            'sample.php' => $original,
            'legacy.txt' => "keep-me\n",
        ]);
        $replacement = $this->pluginPhp('Sample', '3.0.0');
        $zip = $this->zipWith([
            'sample/sample.php' => $replacement,
        ]);

        $result = AP_Plugin_Installer::install($zip, $this->opts(true));

        $this->assertInstanceOf(AP_Plugin_Install_Result::class, $result);
        $this->assertNull($result->previousVersion());
        $this->assertSame(AP_Plugin_Install_Result::ACTION_UPGRADED, $result->action());
        $backup = $result->backupPath();
        $this->assertIsString($backup);
        $this->assertSame($original, (string) file_get_contents($backup . '/sample/sample.php'));
        $this->assertSame("keep-me\n", (string) file_get_contents($backup . '/sample/legacy.txt'));
        $this->assertSame($replacement, (string) file_get_contents($this->pluginsRoot . '/sample/sample.php'));
        $this->assertFileDoesNotExist($this->pluginsRoot . '/sample/legacy.txt');
        $this->assertStagingGone();
    }

    public function testRepeatedUpgradesPruneToTheFilterCap(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $seen = [];
        ap_add_filter(
            'ap_plugin_zip_max_backups',
            static function (int $max, string $slug) use (&$seen): int {
                $seen[] = [$max, $slug];

                return 2;
            },
            10,
            2
        );

        $versions = [
            '1.0.0' => $this->pluginPhp('Sample', '1.0.0'),
            '2.0.0' => $this->pluginPhp('Sample', '2.0.0'),
            '3.0.0' => $this->pluginPhp('Sample', '3.0.0'),
            '4.0.0' => $this->pluginPhp('Sample', '4.0.0'),
        ];
        $first = AP_Plugin_Installer::install($this->zipWith([
            'sample/sample.php' => $versions['1.0.0'],
        ]), $this->opts(true));
        $this->assertInstanceOf(AP_Plugin_Install_Result::class, $first);
        $this->assertSame(AP_Plugin_Install_Result::ACTION_INSTALLED, $first->action());
        $this->assertDirectoryDoesNotExist($this->backupRoot());

        $second = AP_Plugin_Installer::install($this->zipWith([
            'sample/sample.php' => $versions['2.0.0'],
        ]), $this->opts(true));
        $this->assertInstanceOf(AP_Plugin_Install_Result::class, $second);
        $this->assertSame('1.0.0', $second->previousVersion());
        $this->assertCount(1, $this->stamps('sample'));
        $this->assertSame(
            $versions['1.0.0'],
            (string) file_get_contents($this->newestStamp('sample') . '/sample/sample.php')
        );

        $third = AP_Plugin_Installer::install($this->zipWith([
            'sample/sample.php' => $versions['3.0.0'],
        ]), $this->opts(true));
        $this->assertInstanceOf(AP_Plugin_Install_Result::class, $third);
        $this->assertCount(2, $this->stamps('sample'));

        $fourth = AP_Plugin_Installer::install($this->zipWith([
            'sample/sample.php' => $versions['4.0.0'],
        ]), $this->opts(true));
        $this->assertInstanceOf(AP_Plugin_Install_Result::class, $fourth);
        $this->assertSame('4.0.0', $fourth->version());
        $this->assertSame('3.0.0', $fourth->previousVersion());
        $this->assertSame(
            $versions['4.0.0'],
            (string) file_get_contents($this->pluginsRoot . '/sample/sample.php')
        );

        $stamps = $this->stamps('sample');
        $this->assertCount(2, $stamps);
        $this->assertSame(
            $versions['3.0.0'],
            (string) file_get_contents($stamps[0] . '/sample/sample.php')
        );
        $this->assertSame(
            $versions['2.0.0'],
            (string) file_get_contents($stamps[1] . '/sample/sample.php')
        );
        $this->assertSame($fourth->backupPath(), $stamps[0]);
        $this->assertSame(
            [
                [AP_Plugin_Installer::DEFAULT_MAX_BACKUPS, 'sample'],
                [AP_Plugin_Installer::DEFAULT_MAX_BACKUPS, 'sample'],
                [AP_Plugin_Installer::DEFAULT_MAX_BACKUPS, 'sample'],
            ],
            $seen
        );
        $this->assertStagingGone();
    }

    public function testNonPositiveBackupFilterKeepsDefaultAndOtherSlugs(): void
    {
        $rejected = [0, -3, false, null, '', '0', 'nope', 1.5, '5 backups'];
        foreach ($rejected as $bad) {
            ap_reset_hooks();
            ap_add_filter(
                'ap_plugin_zip_max_backups',
                static function () use ($bad): mixed {
                    return $bad;
                }
            );
            $this->assertSame(
                AP_Plugin_Installer::DEFAULT_MAX_BACKUPS,
                AP_Plugin_Installer::maxBackups('sample'),
                'filter value should be ignored: ' . var_export($bad, true)
            );
        }

        ap_reset_hooks();
        ap_add_filter(
            'ap_plugin_zip_max_backups',
            static function (): string {
                return '2';
            }
        );
        $this->assertSame(2, AP_Plugin_Installer::maxBackups('sample'));
        ap_reset_hooks();
        $this->assertSame(AP_Plugin_Installer::DEFAULT_MAX_BACKUPS, AP_Plugin_Installer::maxBackups('sample'));

        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $oldStamps = [
            '20180101000000000000-aaaaaaa1',
            '20190101000000000000-aaaaaaa2',
            '20200101000000000000-aaaaaaa3',
            '20210101000000000000-aaaaaaa4',
            '20220101000000000000-aaaaaaa5',
            '20230101000000000000-aaaaaaa6',
        ];
        foreach ($oldStamps as $stamp) {
            $this->seedStamp('sample', $stamp);
        }
        $this->seedStamp('other-plugin', '20170101000000000000-bbbbbbb1');
        $junk = $this->backupRoot() . '/sample/keep-me';
        $this->assertTrue(mkdir($junk, 0700, true));
        file_put_contents($junk . '/note.txt', "manual\n");
        file_put_contents($this->backupRoot() . '/sample/readme.txt', "not a stamp\n");

        $original = $this->pluginPhp('Sample', '1.0.0');
        $this->seedFolder('sample', ['sample.php' => $original]);
        ap_add_filter(
            'ap_plugin_zip_max_backups',
            static function (): int {
                return 0;
            }
        );
        $result = AP_Plugin_Installer::install($this->zipWith([
            'sample/sample.php' => $this->pluginPhp('Sample', '9.0.0'),
        ]), $this->opts(true));

        $this->assertInstanceOf(AP_Plugin_Install_Result::class, $result);
        $stamps = $this->stamps('sample');
        $this->assertCount(AP_Plugin_Installer::DEFAULT_MAX_BACKUPS, $stamps);
        $this->assertSame($result->backupPath(), $stamps[0]);
        $this->assertSame($original, (string) file_get_contents($stamps[0] . '/sample/sample.php'));
        $this->assertDirectoryDoesNotExist($this->backupRoot() . '/sample/' . $oldStamps[0]);
        $this->assertDirectoryDoesNotExist($this->backupRoot() . '/sample/' . $oldStamps[1]);
        $this->assertDirectoryExists($this->backupRoot() . '/sample/' . $oldStamps[2]);
        $this->assertDirectoryExists($this->backupRoot() . '/other-plugin/20170101000000000000-bbbbbbb1');
        $this->assertSame("manual\n", (string) file_get_contents($junk . '/note.txt'));
        $this->assertSame("not a stamp\n", (string) file_get_contents($this->backupRoot() . '/sample/readme.txt'));
        $this->assertStagingGone();
    }

    public function testBackupFailureLeavesThePluginUnchanged(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $original = $this->pluginPhp('Sample', '1.0.0');
        $this->seedFolder('sample', [
            'sample.php' => $original,
            'legacy.txt' => "keep-me\n",
        ]);
        file_put_contents($this->backupRoot(), 'not a directory');
        $zip = $this->zipWith([
            'sample/sample.php' => $this->pluginPhp('Sample', '2.0.0'),
            'sample/readme.txt' => "nope\n",
        ]);

        $error = AP_Plugin_Installer::install($zip, $this->opts(true));

        $this->assertInstanceOf(AP_Plugin_Install_Error::class, $error);
        $this->assertSame(AP_Plugin_Install_Error::BACKUP_FAILED, $error->code());
        $this->assertStringContainsString('left unchanged', strtolower($error->message()));
        $this->assertSame($original, (string) file_get_contents($this->pluginsRoot . '/sample/sample.php'));
        $this->assertSame("keep-me\n", (string) file_get_contents($this->pluginsRoot . '/sample/legacy.txt'));
        $this->assertFileDoesNotExist($this->pluginsRoot . '/sample/readme.txt');
        $this->assertSame('not a directory', (string) file_get_contents($this->backupRoot()));
        $this->assertStagingGone();
    }

    public function testUnwritablePluginsDirectoryDoesNotBackup(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $original = $this->pluginPhp('Sample', '1.0.0');
        $this->seedFolder('sample', ['sample.php' => $original]);
        $this->assertTrue(chmod($this->pluginsRoot, 0555));
        clearstatcache(true, $this->pluginsRoot);
        if (is_writable($this->pluginsRoot)) {
            chmod($this->pluginsRoot, 0700);
            $this->markTestSkipped('directory mode is not enforced for this user');
        }

        try {
            $error = AP_Plugin_Installer::install($this->zipWith([
                'sample/sample.php' => $this->pluginPhp('Sample', '2.0.0'),
            ]), $this->opts(true));
        } finally {
            chmod($this->pluginsRoot, 0700);
        }

        $this->assertInstanceOf(AP_Plugin_Install_Error::class, $error);
        $this->assertSame(AP_Plugin_Install_Error::INSTALL_FAILED, $error->code());
        $this->assertStringContainsString('not writable', strtolower($error->message()));
        $this->assertSame($original, (string) file_get_contents($this->pluginsRoot . '/sample/sample.php'));
        $this->assertDirectoryDoesNotExist($this->backupRoot());
        $this->assertStagingGone();
    }

    public function testSymlinkedSlugIsNotReplaced(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $target = $this->workDir . '/outside-plugin';
        $this->assertTrue(mkdir($target, 0700, true));
        $original = $this->pluginPhp('Sample', '1.0.0');
        file_put_contents($target . '/sample.php', $original);
        $link = $this->pluginsRoot . '/sample';
        if (!@symlink($target, $link)) {
            $this->markTestSkipped('symlink() is not available');
        }

        $error = AP_Plugin_Installer::install($this->zipWith([
            'sample/sample.php' => $this->pluginPhp('Sample', '2.0.0'),
            'sample/readme.txt' => "nope\n",
        ]), $this->opts(true));

        $this->assertInstanceOf(AP_Plugin_Install_Error::class, $error);
        $this->assertSame(AP_Plugin_Install_Error::INSTALL_FAILED, $error->code());
        $this->assertStringContainsString('symlink', strtolower($error->message()));
        $this->assertTrue(is_link($link));
        $this->assertSame($original, (string) file_get_contents($target . '/sample.php'));
        $this->assertFileDoesNotExist($target . '/readme.txt');
        $this->assertDirectoryDoesNotExist($this->backupRoot());
        $this->assertStagingGone();
    }

    public function testBlockingFileIsNotRemovedWhenOverwriteIsTrue(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $blocker = $this->pluginsRoot . '/blocked';
        file_put_contents($blocker, 'not a plugin directory');
        $error = AP_Plugin_Installer::install($this->zipWith([
            'blocked/blocked.php' => $this->pluginPhp('Blocked', '1.0.0'),
        ]), $this->opts(true));

        $this->assertInstanceOf(AP_Plugin_Install_Error::class, $error);
        $this->assertSame(AP_Plugin_Install_Error::ALREADY_INSTALLED, $error->code());
        $this->assertSame('not a plugin directory', (string) file_get_contents($blocker));
        $this->assertFalse(is_dir($blocker));
        $this->assertDirectoryDoesNotExist($this->backupRoot());
        $this->assertStagingGone();
    }

    public function testFailedSwapRestoresThePreviousPlugin(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $original = $this->pluginPhp('Sample', '1.0.0');
        $this->seedFolder('sample', [
            'sample.php' => $original,
            'legacy.txt' => "keep-me\n",
            'includes/helper.php' => "<?php\n// old helper\n",
        ]);
        $this->seedFolder('other', [
            'other.php' => $this->pluginPhp('Other', '0.1.0'),
        ]);
        $kept = $this->seedStamp('sample', '20180101000000000000-aaaaaaa1');
        file_put_contents($kept . '/marker.txt', "old-backup\n");
        $this->setUpgradeFailure('after_swap');

        $error = AP_Plugin_Installer::install($this->zipWith([
            'sample/sample.php' => $this->pluginPhp('Sample', '2.0.0'),
            'sample/readme.txt' => "shipped\n",
        ]), $this->opts(true));

        $this->assertInstanceOf(AP_Plugin_Install_Error::class, $error);
        $this->assertSame(AP_Plugin_Install_Error::INSTALL_FAILED, $error->code());
        $this->assertStringContainsString('restored', strtolower($error->message()));
        $live = $this->pluginsRoot . '/sample';
        $this->assertSame($original, (string) file_get_contents($live . '/sample.php'));
        $this->assertSame("keep-me\n", (string) file_get_contents($live . '/legacy.txt'));
        $this->assertSame(
            "<?php\n// old helper\n",
            (string) file_get_contents($live . '/includes/helper.php')
        );
        $this->assertFileDoesNotExist($live . '/readme.txt');
        $this->assertSame(['20180101000000000000-aaaaaaa1'], $this->stampNames('sample'));
        $this->assertSame("old-backup\n", (string) file_get_contents($kept . '/marker.txt'));
        $this->assertSame(
            $this->pluginPhp('Other', '0.1.0'),
            (string) file_get_contents($this->pluginsRoot . '/other/other.php')
        );
        $this->assertStagingGone();

        $result = AP_Plugin_Installer::install($this->zipWith([
            'sample/sample.php' => $this->pluginPhp('Sample', '2.0.0'),
            'sample/readme.txt' => "shipped\n",
        ]), $this->opts(true));
        $this->assertInstanceOf(AP_Plugin_Install_Result::class, $result);
        $this->assertSame('2.0.0', $result->version());
        $this->assertSame('1.0.0', $result->previousVersion());
        $this->assertSame("shipped\n", (string) file_get_contents($live . '/readme.txt'));
        $this->assertFileDoesNotExist($live . '/legacy.txt');
        $this->assertContains('20180101000000000000-aaaaaaa1', $this->stampNames('sample'));
        $this->assertStagingGone();
    }

    public function testFailureBeforeSwapRestoresThePreviousPlugin(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $original = $this->pluginPhp('Hello Flat', '1.0.0');
        file_put_contents($this->pluginsRoot . '/hello.php', $original);
        $this->setUpgradeFailure('after_backup');

        $error = AP_Plugin_Installer::install($this->zipWith([
            'hello.php' => $this->pluginPhp('Hello Flat', '9.0.0'),
        ]), $this->opts(true));

        $this->assertInstanceOf(AP_Plugin_Install_Error::class, $error);
        $this->assertSame(AP_Plugin_Install_Error::INSTALL_FAILED, $error->code());
        $this->assertStringContainsString('restored', strtolower($error->message()));
        $this->assertSame($original, (string) file_get_contents($this->pluginsRoot . '/hello.php'));
        $this->assertFileDoesNotExist($this->pluginsRoot . '/hello.php.bak');
        $this->assertDirectoryDoesNotExist($this->backupRoot());
        $this->assertStagingGone();
    }

    public function testUpgradeCopiesWhenRenameCrossesFilesystems(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $plugins = '/dev/shm/ap-plugin-xdev-' . bin2hex(random_bytes(4));
        try {
            if (!@mkdir($plugins, 0700, true)) {
                $this->markTestSkipped('could not create a plugins directory on /dev/shm');
            }
            $contentStat = stat($this->contentDir);
            $pluginsStat = stat($plugins);
            $this->assertIsArray($contentStat);
            $this->assertIsArray($pluginsStat);
            if ($contentStat['dev'] === $pluginsStat['dev']) {
                $this->markTestSkipped('content and plugins are on the same filesystem');
            }

            $original = $this->pluginPhp('Sample', '1.0.0');
            $replacement = $this->pluginPhp('Sample', '2.0.0');
            $this->assertTrue(mkdir($plugins . '/sample/includes', 0700, true));
            file_put_contents($plugins . '/sample/sample.php', $original);
            file_put_contents($plugins . '/sample/legacy.txt', "keep-me\n");
            file_put_contents($plugins . '/.keep', 'stay');

            $result = AP_Plugin_Installer::install($this->zipWith([
                'sample/sample.php' => $replacement,
                'sample/readme.txt' => "shipped\n",
            ]), [
                'plugins_root' => $plugins,
                'content_dir' => $this->contentDir,
                'overwrite' => true,
            ]);

            $this->assertInstanceOf(AP_Plugin_Install_Result::class, $result);
            $this->assertSame(AP_Plugin_Install_Result::ACTION_UPGRADED, $result->action());
            $this->assertSame('1.0.0', $result->previousVersion());
            $backup = $result->backupPath();
            $this->assertIsString($backup);
            $this->assertStringStartsWith($this->contentDir . '/', $backup);
            $this->assertSame($original, (string) file_get_contents($backup . '/sample/sample.php'));
            $this->assertSame("keep-me\n", (string) file_get_contents($backup . '/sample/legacy.txt'));
            $this->assertSame($replacement, (string) file_get_contents($plugins . '/sample/sample.php'));
            $this->assertSame("shipped\n", (string) file_get_contents($plugins . '/sample/readme.txt'));
            $this->assertFileDoesNotExist($plugins . '/sample/legacy.txt');
            $this->assertSame('stay', (string) file_get_contents($plugins . '/.keep'));
            $pluginsReal = realpath($plugins);
            $backupReal = realpath($backup);
            $this->assertNotFalse($pluginsReal);
            $this->assertNotFalse($backupReal);
            $this->assertNotSame($contentStat['dev'], $pluginsStat['dev']);
            $this->assertFileDoesNotExist($plugins . '/sample/sample.php.bak');
            $this->assertStagingGone();
        } finally {
            $this->removeDir($plugins);
        }
    }

    public function testProceduralHelperUpgradesWhenOverwriteIsTrue(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $original = $this->pluginPhp('Wrapped', '1.0.0');
        $this->seedFolder('wrapped', ['wrapped.php' => $original]);
        $zip = $this->zipWith([
            'wrapped/wrapped.php' => $this->pluginPhp('Wrapped', '1.1.0'),
        ]);

        $error = ap_install_plugin($zip, $this->opts());
        $this->assertInstanceOf(AP_Plugin_Install_Error::class, $error);
        $this->assertSame(AP_Plugin_Install_Error::ALREADY_INSTALLED, $error->code());
        $this->assertSame($original, (string) file_get_contents($this->pluginsRoot . '/wrapped/wrapped.php'));

        $result = ap_install_plugin($zip, $this->opts(true));
        $this->assertInstanceOf(AP_Plugin_Install_Result::class, $result);
        $this->assertSame(AP_Plugin_Install_Result::ACTION_UPGRADED, $result->action());
        $this->assertSame('1.0.0', $result->previousVersion());
        $this->assertSame('1.1.0', $result->version());
        $backup = $result->backupPath();
        $this->assertIsString($backup);
        $this->assertSame($original, (string) file_get_contents($backup . '/wrapped/wrapped.php'));
        $this->assertStagingGone();
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

    private function seedStamp(string $slug, string $stamp): string
    {
        $dir = $this->backupRoot() . '/' . $slug . '/' . $stamp;
        $this->assertTrue(mkdir($dir, 0700, true));

        return $dir;
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
    private function stamps(string $slug): array
    {
        $names = $this->stampNames($slug);
        $paths = [];
        foreach ($names as $name) {
            $paths[] = $this->backupRoot() . '/' . $slug . '/' . $name;
        }

        return $paths;
    }

    /**
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

    private function newestStamp(string $slug): string
    {
        $stamps = $this->stamps($slug);
        $this->assertNotSame([], $stamps);

        return $stamps[0];
    }

    private function assertBackupStaysOutsidePlugins(string $backup): void
    {
        $backupReal = realpath($backup);
        $pluginsReal = realpath($this->pluginsRoot);
        $contentReal = realpath($this->contentDir);
        $this->assertNotFalse($backupReal);
        $this->assertNotFalse($pluginsReal);
        $this->assertNotFalse($contentReal);
        $this->assertStringStartsWith($contentReal . DIRECTORY_SEPARATOR, $backupReal);
        $this->assertStringContainsString('/.ap-plugin-backups/', str_replace('\\', '/', $backupReal));
        $this->assertFalse(str_starts_with($backupReal, $pluginsReal . DIRECTORY_SEPARATOR));
        $live = realpath($this->pluginsRoot . '/' . basename(dirname($backup)));
        if ($live !== false) {
            $this->assertStringStartsWith($pluginsReal . DIRECTORY_SEPARATOR, $live);
        }
    }

    private function assertStagingGone(): void
    {
        $this->assertDirectoryDoesNotExist($this->contentDir . '/.ap-plugin-staging');
        $this->assertDirectoryDoesNotExist($this->pluginsRoot . '/.ap-plugin-staging');
    }

    /**
     * @return array{plugins_root: string, content_dir: string, overwrite?: true}
     */
    private function opts(bool $overwrite = false): array
    {
        $opts = [
            'plugins_root' => $this->pluginsRoot,
            'content_dir' => $this->contentDir,
        ];
        if ($overwrite) {
            $opts['overwrite'] = true;
        }

        return $opts;
    }

    private function setUpgradeFailure(?string $point): void
    {
        $property = new ReflectionProperty(AP_Plugin_Installer::class, 'upgradeFailurePoint');
        $property->setValue(null, $point);
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
