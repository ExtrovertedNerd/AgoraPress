<?php

/**
 * Tests for plugin discovery, headers, activation, and loading.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Plugin;

use AP_DB;
use AP_Migrator;
use AP_Options;
use AP_Plugin;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_Plugin::class)]
final class PluginApiTest extends TestCase
{
    private string $root;

    private string $tempPlugins;

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

        $this->tempPlugins = sys_get_temp_dir() . '/ap-plugins-' . uniqid('', true);
        $this->assertTrue(mkdir($this->tempPlugins, 0700, true));
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
    }

    public function testParsePluginFileKnownHeaders(): void
    {
        $path = $this->tempPlugins . '/sample.php';
        file_put_contents(
            $path,
            "<?php\n/**\n * Plugin Name: Sample Plugin\n * Plugin URI: https://example.test/p\n"
            . " * Description: Does a thing\n * Version: 1.2.3\n * Author: Testers\n"
            . " * Author URI: https://example.test\n * License: GPLv2\n * Text Domain: sample\n"
            . " * Requires PHP: 8.2\n */\n"
        );

        $headers = AP_Plugin::parsePluginFile($path);
        $this->assertSame('Sample Plugin', $headers['Plugin Name']);
        $this->assertSame('https://example.test/p', $headers['Plugin URI']);
        $this->assertSame('Does a thing', $headers['Description']);
        $this->assertSame('1.2.3', $headers['Version']);
        $this->assertSame('Testers', $headers['Author']);
        $this->assertSame('https://example.test', $headers['Author URI']);
        $this->assertSame('GPLv2', $headers['License']);
        $this->assertSame('sample', $headers['Text Domain']);
        $this->assertSame('8.2', $headers['Requires PHP']);
    }

    public function testDiscoverySingleFileAndFolderPlugins(): void
    {
        file_put_contents(
            $this->tempPlugins . '/hello.php',
            "<?php\n/**\n * Plugin Name: Hello Dolly\n * Version: 1.0\n */\n"
        );
        $this->assertTrue(mkdir($this->tempPlugins . '/demo-plugin', 0700, true));
        file_put_contents(
            $this->tempPlugins . '/demo-plugin/demo-plugin.php',
            "<?php\n/**\n * Plugin Name: Demo Plugin\n * Description: Folder form\n * Version: 0.1\n */\n"
        );
        // No header → ignored.
        file_put_contents($this->tempPlugins . '/no-header.php', "<?php\necho 'x';\n");
        // Nested deeper than one level → ignored.
        $this->assertTrue(mkdir($this->tempPlugins . '/demo-plugin/lib', 0700, true));
        file_put_contents(
            $this->tempPlugins . '/demo-plugin/lib/helper.php',
            "<?php\n/**\n * Plugin Name: Nested Ignored\n */\n"
        );

        $plugins = AP_Plugin::listPlugins();
        $this->assertArrayHasKey('hello.php', $plugins);
        $this->assertArrayHasKey('demo-plugin/demo-plugin.php', $plugins);
        $this->assertArrayNotHasKey('no-header.php', $plugins);
        $this->assertArrayNotHasKey('demo-plugin/lib/helper.php', $plugins);
        $this->assertSame('Hello Dolly', $plugins['hello.php']['Plugin Name']);
        $this->assertSame('Demo Plugin', $plugins['demo-plugin/demo-plugin.php']['Plugin Name']);
        $this->assertSame('hello', $plugins['hello.php']['Slug']);
        $this->assertSame('demo-plugin', $plugins['demo-plugin/demo-plugin.php']['Slug']);
    }

    public function testActivateDeactivateAndLoad(): void
    {
        $marker = $this->tempPlugins . '/_marker.txt';
        if (is_file($marker)) {
            unlink($marker);
        }

        file_put_contents(
            $this->tempPlugins . '/counter.php',
            "<?php\n/**\n * Plugin Name: Counter\n * Version: 1.0\n */\n"
            . "if (!defined('AP_COUNTER_PLUGIN_LOADED')) {\n"
            . "    define('AP_COUNTER_PLUGIN_LOADED', true);\n"
            . "}\n"
            . "ap_register_activation_hook(__FILE__, static function (): void {\n"
            . "    file_put_contents(" . var_export($marker, true) . ", 'activated');\n"
            . "});\n"
            . "ap_register_deactivation_hook(__FILE__, static function (): void {\n"
            . "    file_put_contents(" . var_export($marker, true) . ", 'deactivated');\n"
            . "});\n"
        );

        $this->assertFalse(AP_Plugin::isActive('counter.php', $this->db));
        $result = AP_Plugin::activate('counter.php', $this->db);
        $this->assertTrue($result['ok'], implode('; ', $result['errors']));
        $this->assertTrue(AP_Plugin::isActive('counter.php', $this->db));
        $this->assertTrue(ap_is_plugin_active('counter.php', $this->db));
        $this->assertTrue(defined('AP_COUNTER_PLUGIN_LOADED'));
        $this->assertSame('activated', (string) file_get_contents($marker));
        $this->assertContains('counter.php', ap_get_active_plugins($this->db));

        $activeRaw = AP_Options::get('active_plugins', [], $this->db);
        $this->assertIsArray($activeRaw);
        $this->assertContains('counter.php', $activeRaw);

        $result = AP_Plugin::deactivate('counter.php', $this->db);
        $this->assertTrue($result['ok']);
        $this->assertFalse(AP_Plugin::isActive('counter.php', $this->db));
        $this->assertSame('deactivated', (string) file_get_contents($marker));
    }

    public function testLoadActivePluginsOnRequest(): void
    {
        file_put_contents(
            $this->tempPlugins . '/flag.php',
            "<?php\n/**\n * Plugin Name: Flag\n */\n"
            . "\$GLOBALS['ap_flag_plugin_ran'] = true;\n"
            . "if (function_exists('ap_add_action')) {\n"
            . "    ap_add_action('ap_plugins_loaded', static function (): void {\n"
            . "        \$GLOBALS['ap_flag_plugins_loaded'] = true;\n"
            . "    });\n"
            . "}\n"
        );

        AP_Options::update('active_plugins', ['flag.php'], $this->db);
        AP_Plugin::reset();
        AP_Plugin::setPluginsRootOverride($this->tempPlugins);

        $this->assertArrayNotHasKey('ap_flag_plugin_ran', $GLOBALS);
        AP_Plugin::loadActivePlugins($this->db);
        $this->assertTrue($GLOBALS['ap_flag_plugin_ran'] ?? false);
        $this->assertTrue($GLOBALS['ap_flag_plugins_loaded'] ?? false);
        $this->assertTrue(AP_Plugin::isLoaded('flag.php'));

        // Second call is a no-op for the load gate.
        unset($GLOBALS['ap_flag_plugin_ran'], $GLOBALS['ap_flag_plugins_loaded']);
        AP_Plugin::loadActivePlugins($this->db);
        $this->assertArrayNotHasKey('ap_flag_plugin_ran', $GLOBALS);
    }

    public function testRejectsPathTraversalAndMissingHeaders(): void
    {
        $this->assertFalse(AP_Plugin::isValidPlugin('../evil.php'));
        $this->assertFalse(AP_Plugin::isValidPlugin('/etc/passwd.php'));
        $this->assertSame('', AP_Plugin::pluginBasename('../x.php'));

        $bad = AP_Plugin::activate('missing.php', $this->db);
        $this->assertFalse($bad['ok']);

        file_put_contents($this->tempPlugins . '/orphan.php', "<?php\n// no headers\n");
        $this->assertNull(AP_Plugin::getPluginHeaders('orphan.php'));
        $this->assertFalse(ap_activate_plugin('orphan.php', $this->db)['ok']);
    }

    public function testRequiresPhpVersionGate(): void
    {
        file_put_contents(
            $this->tempPlugins . '/future.php',
            "<?php\n/**\n * Plugin Name: Future\n * Requires PHP: 99.0\n */\n"
        );
        $result = AP_Plugin::activate('future.php', $this->db);
        $this->assertFalse($result['ok']);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('PHP 99.0', $result['errors'][0]);
    }

    public function testPluginBasenameAndPaths(): void
    {
        $this->assertTrue(mkdir($this->tempPlugins . '/pack', 0700, true));
        $main = $this->tempPlugins . '/pack/pack.php';
        file_put_contents(
            $main,
            "<?php\n/**\n * Plugin Name: Pack\n */\n"
        );

        $this->assertSame('pack/pack.php', AP_Plugin::pluginBasename($main));
        $this->assertSame('pack/pack.php', ap_plugin_basename($main));
        $this->assertSame($main, AP_Plugin::pluginPath('pack/pack.php'));
        $this->assertSame($this->tempPlugins . '/pack', AP_Plugin::pluginDir('pack/pack.php'));
        $this->assertStringContainsString('/plugins/pack', AP_Plugin::pluginUrl('pack/pack.php', 'assets/x.css'));
    }

    public function testProceduralHelpersMirrorClass(): void
    {
        file_put_contents(
            $this->tempPlugins . '/hello.php',
            "<?php\n/**\n * Plugin Name: Hello\n * Version: 2.0\n */\n"
        );

        $list = ap_get_plugins();
        $this->assertArrayHasKey('hello.php', $list);
        $data = ap_get_plugin_data('hello.php');
        $this->assertIsArray($data);
        $this->assertSame('Hello', $data['Plugin Name']);
        $this->assertSame($this->tempPlugins, ap_get_plugins_dir());

        $this->assertTrue(ap_activate_plugin('hello.php', $this->db)['ok']);
        $this->assertTrue(ap_is_plugin_active('hello.php', $this->db));
        $this->assertTrue(ap_deactivate_plugin('hello.php', $this->db)['ok']);
        $this->assertFalse(ap_is_plugin_active('hello.php', $this->db));
    }

    public function testActivationFiresActionHooks(): void
    {
        $log = [];
        ap_add_action('ap_activate_plugin', static function (string $plugin) use (&$log): void {
            $log[] = 'activate:' . $plugin;
        }, 10, 1);
        ap_add_action('ap_deactivate_plugin', static function (string $plugin) use (&$log): void {
            $log[] = 'deactivate:' . $plugin;
        }, 10, 1);

        file_put_contents(
            $this->tempPlugins . '/hooky.php',
            "<?php\n/**\n * Plugin Name: Hooky\n */\n"
        );

        AP_Plugin::activate('hooky.php', $this->db);
        AP_Plugin::deactivate('hooky.php', $this->db);

        $this->assertSame(['activate:hooky.php', 'deactivate:hooky.php'], $log);
    }

    /**
     * @param non-empty-string $dir
     */
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
            $path = $file->getPathname();
            if ($file->isDir()) {
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
