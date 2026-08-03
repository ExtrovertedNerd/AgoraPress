<?php

/**
 * Tests for must-use plugin discovery and loading.
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
final class MuPluginTest extends TestCase
{
    private string $root;

    private string $tempMu;

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

        $this->tempMu = sys_get_temp_dir() . '/ap-mu-' . uniqid('', true);
        $this->assertTrue(mkdir($this->tempMu, 0700, true));
        AP_Plugin::setMuPluginsRootOverride($this->tempMu);
    }

    protected function tearDown(): void
    {
        if (function_exists('ap_reset_hooks')) {
            ap_reset_hooks();
        }
        AP_Plugin::reset();
        AP_Options::flushCache();
        unset($GLOBALS['apdb']);
        $this->removeDir($this->tempMu);
    }

    public function testListAndLoadMuPlugins(): void
    {
        file_put_contents(
            $this->tempMu . '/always-on.php',
            "<?php\n/**\n * Plugin Name: Always On\n * Description: MU demo\n * Version: 1.0\n */\n"
            . "\$GLOBALS['ap_mu_always_on'] = true;\n"
        );
        // No header still loads (filename becomes Plugin Name).
        file_put_contents(
            $this->tempMu . '/bare.php',
            "<?php\n\$GLOBALS['ap_mu_bare'] = true;\n"
        );
        // Subdirectory PHP is ignored (WP-style root-only).
        $this->assertTrue(mkdir($this->tempMu . '/nested', 0700, true));
        file_put_contents(
            $this->tempMu . '/nested/skip.php',
            "<?php\n/**\n * Plugin Name: Nested\n */\n\$GLOBALS['ap_mu_nested'] = true;\n"
        );

        $list = AP_Plugin::listMuPlugins();
        $this->assertArrayHasKey('always-on.php', $list);
        $this->assertArrayHasKey('bare.php', $list);
        $this->assertArrayNotHasKey('nested/skip.php', $list);
        $this->assertSame('Always On', $list['always-on.php']['Plugin Name']);
        $this->assertSame('bare', $list['bare.php']['Plugin Name']);
        $this->assertSame('1', $list['always-on.php']['MustUse']);

        $this->assertSame($list, ap_get_mu_plugins());
        $this->assertSame($this->tempMu, ap_get_mu_plugins_dir());

        $log = [];
        ap_add_action('ap_mu_plugins_loaded', static function () use (&$log): void {
            $log[] = 'mu_loaded';
        });

        AP_Plugin::loadMuPlugins();
        $this->assertTrue($GLOBALS['ap_mu_always_on'] ?? false);
        $this->assertTrue($GLOBALS['ap_mu_bare'] ?? false);
        $this->assertArrayNotHasKey('ap_mu_nested', $GLOBALS);
        $this->assertTrue(AP_Plugin::isMuLoaded('always-on.php'));
        $this->assertTrue(ap_is_mu_plugin_loaded('bare.php'));
        $this->assertSame(['mu_loaded'], $log);

        // Second call is a no-op.
        unset($GLOBALS['ap_mu_always_on'], $GLOBALS['ap_mu_bare']);
        AP_Plugin::loadMuPlugins();
        $this->assertArrayNotHasKey('ap_mu_always_on', $GLOBALS);
    }

    public function testMuPluginPathRejectsTraversal(): void
    {
        $this->assertSame('', AP_Plugin::muPluginPath('../evil.php'));
        $this->assertSame('', AP_Plugin::muPluginPath('dir/file.php'));
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
