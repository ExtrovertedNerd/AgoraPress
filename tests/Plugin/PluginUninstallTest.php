<?php

/**
 * AP-029: Delete runs a plugin's uninstall.php before removing its files.
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
use AP_Plugin_Uninstaller;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_Plugin_Uninstaller::class)]
#[RunTestsInSeparateProcesses]
final class PluginUninstallTest extends TestCase
{
    private string $root;

    private string $plugins;

    private AP_DB $db;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        if (!defined('AP_ABSPATH')) {
            define('AP_ABSPATH', $this->root . '/');
        }
        require_once $this->root . '/ap-includes/version.php';
        require_once $this->root . '/ap-includes/hooks.php';
        require_once $this->root . '/ap-includes/class-ap-db.php';
        require_once $this->root . '/ap-includes/class-ap-migrator.php';
        require_once $this->root . '/ap-includes/class-ap-options.php';
        require_once $this->root . '/ap-includes/class-ap-plugin.php';
        require_once $this->root . '/ap-includes/class-ap-plugin-uninstaller.php';
        require_once $this->root . '/ap-includes/class-ap-plugin-installer.php';
        require_once $this->root . '/ap-includes/functions.php';

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
        $this->db->insert('options', ['option_name' => 'active_plugins', 'option_value' => '[]', 'autoload' => 'yes']);

        $this->plugins = sys_get_temp_dir() . '/ap-uninstall-' . uniqid('', true);
        mkdir($this->plugins, 0700, true);
        AP_Plugin::setPluginsRootOverride($this->plugins);
    }

    protected function tearDown(): void
    {
        AP_Plugin::reset();
        AP_Options::flushCache();
        unset($GLOBALS['apdb']);
        if (is_dir($this->plugins)) {
            exec('rm -rf ' . escapeshellarg($this->plugins));
        }
    }

    public function testDeleteRunsUninstallWithGuardConstantThenRemovesFiles(): void
    {
        $marker = $this->plugins . '/../' . basename($this->plugins) . '-marker.txt';
        $this->makePlugin('tidy', "<?php\nif (!defined('AP_UNINSTALL_PLUGIN')) { exit; }\n"
            . "ap_update_option('tidy_seen', AP_UNINSTALL_PLUGIN);\n"
            . 'file_put_contents(' . var_export($marker, true) . ", 'ran');\n");
        ap_update_option('tidy_data', 'keep-me-until-uninstall');

        $del = AP_Plugin_Installer::deletePlugin('tidy/tidy.php', $this->db);
        $this->assertTrue($del['ok'], implode('; ', $del['errors']));
        $this->assertDirectoryDoesNotExist($this->plugins . '/tidy');
        $this->assertFileExists($marker);
        @unlink($marker);
        AP_Options::flushCache();
        $this->assertSame('tidy/tidy.php', AP_Options::get('tidy_seen', false, $this->db));
    }

    public function testUninstallErrorStillDeletesAndReportsWarning(): void
    {
        $this->makePlugin('broken', "<?php\nthrow new RuntimeException('boom');\n");
        $del = AP_Plugin_Installer::deletePlugin('broken/broken.php', $this->db);
        $this->assertTrue($del['ok']);
        $this->assertDirectoryDoesNotExist($this->plugins . '/broken');
        $this->assertArrayHasKey('warnings', $del);
        $this->assertNotSame([], $del['warnings']);
        $this->assertStringContainsString('uninstall.php', implode(' ', $del['warnings']));
    }

    public function testActivePluginIsNotUninstalled(): void
    {
        $marker = sys_get_temp_dir() . '/ap-uninstall-active-' . uniqid('', true);
        $this->makePlugin('busy', '<?php file_put_contents(' . var_export($marker, true) . ", 'ran');\n");
        $this->assertTrue(AP_Plugin::activate('busy/busy.php', $this->db)['ok']);
        $del = AP_Plugin_Installer::deletePlugin('busy/busy.php', $this->db);
        $this->assertFalse($del['ok']);
        $this->assertFileDoesNotExist($marker);
        $this->assertDirectoryExists($this->plugins . '/busy');
    }

    public function testPluginWithoutUninstallAndSingleFilePlugin(): void
    {
        $this->makePlugin('plain', null);
        $this->assertTrue(AP_Plugin_Installer::deletePlugin('plain/plain.php', $this->db)['ok']);
        $this->assertDirectoryDoesNotExist($this->plugins . '/plain');

        file_put_contents($this->plugins . '/solo.php', "<?php\n/**\n * Plugin Name: Solo\n */\n");
        $this->assertTrue(AP_Plugin_Installer::deletePlugin('solo.php', $this->db)['ok']);
        $this->assertFileDoesNotExist($this->plugins . '/solo.php');
    }

    public function testUninstallerRefusesFileOutsidePluginDir(): void
    {
        $this->makePlugin('sneaky', null);
        $outside = sys_get_temp_dir() . '/ap-uninstall-outside-' . uniqid('', true) . '.php';
        $marker = $outside . '.ran';
        file_put_contents($outside, '<?php file_put_contents(' . var_export($marker, true) . ", 'ran');\n");
        symlink($outside, $this->plugins . '/sneaky/uninstall.php');
        $res = AP_Plugin_Uninstaller::run($this->plugins . '/sneaky', 'sneaky/sneaky.php');
        $this->assertFalse($res['ran']);
        $this->assertFileDoesNotExist($marker);
        @unlink($outside);

        $none = AP_Plugin_Uninstaller::run($this->plugins . '/missing-dir', 'missing-dir/x.php');
        $this->assertFalse($none['ran']);
        $this->assertSame('', $none['error']);
    }

    private function makePlugin(string $slug, ?string $uninstall): void
    {
        mkdir($this->plugins . '/' . $slug, 0700, true);
        file_put_contents(
            $this->plugins . '/' . $slug . '/' . $slug . '.php',
            "<?php\n/**\n * Plugin Name: " . ucfirst($slug) . "\n * Version: 1.0\n */\n"
        );
        if ($uninstall !== null) {
            file_put_contents($this->plugins . '/' . $slug . '/uninstall.php', $uninstall);
        }
    }
}
