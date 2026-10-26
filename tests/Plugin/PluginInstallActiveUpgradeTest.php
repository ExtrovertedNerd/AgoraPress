<?php

/**
 * Active plugins stay active when a zip upgrade replaces their files.
 *
 * The upgrade routine registered on the plugin runs when core has one.
 * A plugin that was not active stays inactive, and a failed swap does not
 * run the routine or drop the active flag.
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
#[CoversClass(AP_Plugin_Install_Result::class)]
#[CoversClass(AP_Plugin_Install_Error::class)]
final class PluginInstallActiveUpgradeTest extends TestCase
{
    private string $root;

    private string $workDir;

    private string $contentDir;

    private string $pluginsRoot;

    private string $dbPath;

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
        $this->setUpgradeFailure(null);
        unset($GLOBALS['ap_active_upgrade_loaded']);

        $this->workDir = sys_get_temp_dir() . '/ap-plugin-active-upgrade-' . uniqid('', true);
        $this->contentDir = $this->workDir . '/content';
        $this->pluginsRoot = $this->contentDir . '/plugins';
        $this->assertTrue(mkdir($this->pluginsRoot, 0700, true));
        $this->dbPath = $this->workDir . '/site.sqlite';
        $pdo = new PDO('sqlite:' . $this->dbPath, null, null, [
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
        $this->setUpgradeFailure(null);
        ap_reset_hooks();
        AP_Plugin::reset();
        AP_Options::flushCache();
        unset($GLOBALS['apdb'], $GLOBALS['ap_active_upgrade_loaded']);
        $this->removeDir($this->workDir);
    }

    public function testInactivePluginStaysInactiveAndSkipsUpgradeRoutine(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $marker = $this->workDir . '/marker.txt';
        $this->seedFolder('sample', [
            'sample.php' => $this->pluginSource('Sample', '1.0.0', $marker, 'stale-upgrade'),
        ]);
        $this->seedFolder('other', [
            'other.php' => $this->pluginSource('Other', '0.1.0', $marker, ''),
        ]);
        $zip = $this->zipWith([
            'sample/sample.php' => $this->pluginSource('Sample', '2.0.0', $marker, 'record'),
        ]);

        $result = AP_Plugin_Installer::install($zip, $this->opts(true));

        $this->assertInstanceOf(AP_Plugin_Install_Result::class, $result);
        $this->assertSame(AP_Plugin_Install_Result::ACTION_UPGRADED, $result->action());
        $this->assertFalse($result->activated());
        $this->assertFalse(AP_Plugin::isActive('sample/sample.php', $this->db));
        $this->assertSame([], AP_Plugin::getActivePlugins($this->db, false));
        $this->assertFileDoesNotExist($marker);
        $this->assertArrayNotHasKey('ap_active_upgrade_loaded', $GLOBALS);
        $this->assertSame([], $this->pendingUpgrades());
        $this->assertStringContainsString(
            'Version: 2.0.0',
            (string) file_get_contents($this->pluginsRoot . '/sample/sample.php')
        );
    }

    public function testActivePluginStaysActiveAndRunsUpgradeRoutineImmediately(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $marker = $this->workDir . '/marker.txt';
        $this->seedFolder('sample', [
            'sample.php' => $this->pluginSource('Sample', '1.0.0', $marker, 'stale-upgrade'),
        ]);
        $this->seedFolder('other', [
            'other.php' => $this->pluginSource('Other', '0.1.0', $marker, ''),
        ]);
        AP_Options::update('active_plugins', ['other/other.php', 'sample/sample.php'], $this->db);
        $zip = $this->zipWith([
            'sample/sample.php' => $this->pluginSource('Sample', '2.0.0', $marker, 'record'),
        ]);

        $result = AP_Plugin_Installer::install($zip, $this->opts(true));

        $this->assertInstanceOf(AP_Plugin_Install_Result::class, $result);
        $this->assertSame('2.0.0', $result->version());
        $this->assertSame('1.0.0', $result->previousVersion());
        $this->assertSame(AP_Plugin_Install_Result::ACTION_UPGRADED, $result->action());
        $this->assertFalse($result->activated());
        $this->assertTrue(AP_Plugin::isActive('sample/sample.php', $this->db));
        $this->assertTrue(AP_Plugin::isActive('other/other.php', $this->db));
        $this->assertSame(
            ['other/other.php', 'sample/sample.php'],
            AP_Plugin::getActivePlugins($this->db, false)
        );
        $this->assertSame('sample/sample.php|1.0.0|2.0.0', (string) file_get_contents($marker));
        $this->assertSame('loaded', $GLOBALS['ap_active_upgrade_loaded'] ?? null);
        $this->assertSame([], $this->pendingUpgrades());
        $backup = $result->backupPath();
        $this->assertIsString($backup);
        $this->assertStringContainsString('Version: 1.0.0', (string) file_get_contents($backup . '/sample/sample.php'));
        $this->assertStringNotContainsString('deactivated', (string) file_get_contents($marker));
        $this->assertStringNotContainsString('activated', (string) file_get_contents($marker));
    }

    public function testAlreadyLoadedPluginDefersUpgradeUntilNextLoad(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $marker = $this->workDir . '/marker.txt';
        $this->seedFolder('sample', [
            'sample.php' => $this->pluginSource('Sample', '1.0.0', $marker, 'stale-upgrade'),
        ]);
        $activated = AP_Plugin::activate('sample/sample.php', $this->db);
        $this->assertTrue($activated['ok'], implode('; ', $activated['errors']));
        $this->assertSame('activated', (string) file_get_contents($marker));
        $this->assertTrue(AP_Plugin::isLoaded('sample/sample.php'));

        $zip = $this->zipWith([
            'sample/sample.php' => $this->pluginSource('Sample', '2.0.0', $marker, 'append'),
        ]);
        $result = AP_Plugin_Installer::install($zip, $this->opts(true));

        $this->assertInstanceOf(AP_Plugin_Install_Result::class, $result);
        $this->assertFalse($result->activated());
        $this->assertTrue(AP_Plugin::isActive('sample/sample.php', $this->db));
        $this->assertSame('activated', (string) file_get_contents($marker));
        $this->assertArrayNotHasKey('ap_active_upgrade_loaded', $GLOBALS);
        $this->assertSame(
            ['sample/sample.php' => ['from' => '1.0.0', 'to' => '2.0.0']],
            $this->pendingUpgrades()
        );

        AP_Plugin::loadActivePlugins($this->db);
        $this->assertSame('activated', (string) file_get_contents($marker));
        $this->assertSame(
            ['sample/sample.php' => ['from' => '1.0.0', 'to' => '2.0.0']],
            $this->pendingUpgrades()
        );

        $this->runNextPluginLoad();
        AP_Options::flushCache();
        $this->assertSame("activated1.0.0>2.0.0\n", (string) file_get_contents($marker));
        $this->assertSame([], $this->pendingUpgrades());
        $this->assertTrue(AP_Plugin::isActive('sample/sample.php', $this->db));

        $this->runNextPluginLoad();
        AP_Options::flushCache();
        $this->assertSame("activated1.0.0>2.0.0\n", (string) file_get_contents($marker));
        $this->assertSame([], $this->pendingUpgrades());
    }

    public function testDeferredUpgradeIsSkippedWhenPluginIsDeactivatedFirst(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $marker = $this->workDir . '/marker.txt';
        $this->seedFolder('sample', [
            'sample.php' => $this->pluginSource('Sample', '1.0.0', $marker, 'activate-only'),
        ]);
        $activated = AP_Plugin::activate('sample/sample.php', $this->db);
        $this->assertTrue($activated['ok'], implode('; ', $activated['errors']));

        $zip = $this->zipWith([
            'sample/sample.php' => $this->pluginSource('Sample', '2.0.0', $marker, 'append'),
        ]);
        $result = AP_Plugin_Installer::install($zip, $this->opts(true));
        $this->assertInstanceOf(AP_Plugin_Install_Result::class, $result);
        $this->assertSame('activated', (string) file_get_contents($marker));
        $this->assertNotSame([], $this->pendingUpgrades());

        $deactivated = AP_Plugin::deactivate('sample/sample.php', $this->db);
        $this->assertTrue($deactivated['ok'], implode('; ', $deactivated['errors']));
        $this->assertFalse(AP_Plugin::isActive('sample/sample.php', $this->db));

        AP_Plugin::reset();
        AP_Plugin::setPluginsRootOverride($this->pluginsRoot);
        AP_Options::flushCache();
        AP_Plugin::loadActivePlugins($this->db);

        $this->assertSame('activated', (string) file_get_contents($marker));
        $this->assertSame([], $this->pendingUpgrades());
        $this->assertFalse(AP_Plugin::isActive('sample/sample.php', $this->db));
    }

    public function testRenamedMainFileStaysActiveWithoutDeactivationHooks(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $marker = $this->workDir . '/marker.txt';
        $this->seedFolder('sample', [
            'legacy.php' => $this->pluginSource('Sample', '1.2.3', $marker, 'deactivate-only'),
        ]);
        $this->seedFolder('other', [
            'other.php' => $this->pluginSource('Other', '0.1.0', $marker, ''),
        ]);
        $activated = AP_Plugin::activate('sample/legacy.php', $this->db);
        $this->assertTrue($activated['ok'], implode('; ', $activated['errors']));
        AP_Options::update(
            'active_plugins',
            ['other/other.php', 'sample/legacy.php'],
            $this->db
        );
        $this->assertSame('activated', (string) file_get_contents($marker));

        $zip = $this->zipWith([
            'sample/sample.php' => $this->pluginSource('Sample', '2.0.0', $marker, 'record'),
        ]);
        $result = AP_Plugin_Installer::install($zip, $this->opts(true));

        $this->assertInstanceOf(AP_Plugin_Install_Result::class, $result);
        $this->assertSame('sample/sample.php', $result->plugin());
        $this->assertFalse($result->activated());
        $this->assertSame('sample/sample.php|1.2.3|2.0.0', (string) file_get_contents($marker));
        $this->assertFalse(AP_Plugin::isActive('sample/legacy.php', $this->db));
        $this->assertTrue(AP_Plugin::isActive('sample/sample.php', $this->db));
        $this->assertTrue(AP_Plugin::isActive('other/other.php', $this->db));
        $this->assertSame(
            ['other/other.php', 'sample/sample.php'],
            AP_Plugin::getActivePlugins($this->db, true)
        );
        $this->assertFileDoesNotExist($this->pluginsRoot . '/sample/legacy.php');
    }

    public function testActiveUpgradeWithoutVersionHeaderPassesNullPreviousVersion(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $marker = $this->workDir . '/marker.txt';
        $this->seedFolder('sample', [
            'sample.php' => "<?php\n/**\n * Plugin Name: Sample\n */\n",
        ]);
        AP_Options::update('active_plugins', ['sample/sample.php'], $this->db);
        $zip = $this->zipWith([
            'sample/sample.php' => $this->pluginSource('Sample', '2.0.0', $marker, 'record'),
        ]);

        $result = AP_Plugin_Installer::install($zip, $this->opts(true));

        $this->assertInstanceOf(AP_Plugin_Install_Result::class, $result);
        $this->assertNull($result->previousVersion());
        $this->assertTrue(AP_Plugin::isActive('sample/sample.php', $this->db));
        $this->assertSame('sample/sample.php||2.0.0', (string) file_get_contents($marker));
    }

    public function testFlatActivePluginStaysActive(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $marker = $this->workDir . '/marker.txt';
        file_put_contents(
            $this->pluginsRoot . '/hello.php',
            $this->pluginSource('Hello', '1.0.0', $marker, '')
        );
        file_put_contents(
            $this->pluginsRoot . '/notes.txt',
            "sibling\n"
        );
        AP_Options::update('active_plugins', ['hello.php', 'other.php'], $this->db);
        $zip = $this->zipWith([
            'hello.php' => $this->pluginSource('Hello', '3.1.0', $marker, 'record'),
        ]);

        $result = AP_Plugin_Installer::install($zip, $this->opts(true));

        $this->assertInstanceOf(AP_Plugin_Install_Result::class, $result);
        $this->assertSame('hello.php', $result->plugin());
        $this->assertFalse($result->activated());
        $this->assertSame('hello.php|1.0.0|3.1.0', (string) file_get_contents($marker));
        $this->assertSame(['hello.php', 'other.php'], AP_Plugin::getActivePlugins($this->db, false));
        $this->assertSame("sibling\n", (string) file_get_contents($this->pluginsRoot . '/notes.txt'));
    }

    public function testUpgradeHookFailureLeavesPluginActive(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $marker = $this->workDir . '/marker.txt';
        $this->seedFolder('sample', [
            'sample.php' => $this->pluginSource('Sample', '1.0.0', $marker, ''),
        ]);
        AP_Options::update('active_plugins', ['sample/sample.php'], $this->db);
        $zip = $this->zipWith([
            'sample/sample.php' => $this->pluginSource('Sample', '2.0.0', $marker, 'throw'),
        ]);

        $result = AP_Plugin_Installer::install($zip, $this->opts(true));

        $this->assertInstanceOf(AP_Plugin_Install_Result::class, $result);
        $this->assertSame('2.0.0', $result->version());
        $this->assertTrue(AP_Plugin::isActive('sample/sample.php', $this->db));
        $this->assertSame('ran', (string) file_get_contents($marker));
        $this->assertStringContainsString(
            'Version: 2.0.0',
            (string) file_get_contents($this->pluginsRoot . '/sample/sample.php')
        );
    }

    public function testFailedSwapKeepsActivePluginAndSkipsUpgradeRoutine(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $marker = $this->workDir . '/marker.txt';
        $original = $this->pluginSource('Sample', '1.0.0', $marker, 'stale-upgrade');
        $this->seedFolder('sample', ['sample.php' => $original]);
        AP_Options::update('active_plugins', ['sample/sample.php'], $this->db);
        $zip = $this->zipWith([
            'sample/sample.php' => $this->pluginSource('Sample', '2.0.0', $marker, 'record'),
        ]);
        $this->setUpgradeFailure('after_swap');

        $error = AP_Plugin_Installer::install($zip, $this->opts(true));

        $this->assertInstanceOf(AP_Plugin_Install_Error::class, $error);
        $this->assertSame(AP_Plugin_Install_Error::INSTALL_FAILED, $error->code());
        $this->assertTrue(AP_Plugin::isActive('sample/sample.php', $this->db));
        $this->assertSame(['sample/sample.php'], AP_Plugin::getActivePlugins($this->db, false));
        $this->assertSame($original, (string) file_get_contents($this->pluginsRoot . '/sample/sample.php'));
        $this->assertFileDoesNotExist($marker);
        $this->assertSame([], $this->pendingUpgrades());
        $this->assertArrayNotHasKey('ap_active_upgrade_loaded', $GLOBALS);
    }

    public function testUpgradeOfAnotherPluginsRootDoesNotTouchTheLiveActivePlugin(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $marker = $this->workDir . '/marker.txt';
        $liveOriginal = $this->pluginSource('Sample', '1.0.0', $marker, 'stale-upgrade');
        $this->seedFolder('sample', ['sample.php' => $liveOriginal]);
        AP_Options::update('active_plugins', ['sample/sample.php'], $this->db);

        $foreignContent = $this->workDir . '/foreign-content';
        $foreignPlugins = $foreignContent . '/plugins';
        $this->assertTrue(mkdir($foreignPlugins . '/sample', 0700, true));
        file_put_contents(
            $foreignPlugins . '/sample/sample.php',
            $this->pluginSource('Sample', '1.0.0', $marker, '')
        );
        $zip = $this->zipWith([
            'sample/sample.php' => $this->pluginSource('Sample', '9.0.0', $marker, 'record'),
        ]);

        $result = AP_Plugin_Installer::install($zip, [
            'overwrite' => true,
            'plugins_root' => $foreignPlugins,
            'content_dir' => $foreignContent,
        ]);

        $this->assertInstanceOf(AP_Plugin_Install_Result::class, $result);
        $this->assertSame('9.0.0', $result->version());
        $this->assertFalse($result->activated());
        $this->assertSame($liveOriginal, (string) file_get_contents($this->pluginsRoot . '/sample/sample.php'));
        $this->assertStringContainsString(
            'Version: 9.0.0',
            (string) file_get_contents($foreignPlugins . '/sample/sample.php')
        );
        $this->assertSame(['sample/sample.php'], AP_Plugin::getActivePlugins($this->db, false));
        $this->assertFileDoesNotExist($marker);
        $this->assertSame([], $this->pendingUpgrades());
        $this->assertArrayNotHasKey('ap_active_upgrade_loaded', $GLOBALS);
    }

    public function testDirectUpgradeDoesNothingWhenThePluginIsInactive(): void
    {
        $marker = $this->workDir . '/marker.txt';
        $this->seedFolder('sample', [
            'sample.php' => $this->pluginSource('Sample', '1.0.0', $marker, 'record'),
        ]);

        $result = AP_Plugin::upgrade('sample/sample.php', '0.9.0', '1.0.0', $this->db);

        $this->assertTrue($result['ok']);
        $this->assertFalse($result['ran']);
        $this->assertFalse($result['deferred']);
        $this->assertSame([], $result['errors']);
        $this->assertFalse(AP_Plugin::isActive('sample/sample.php', $this->db));
        $this->assertFileDoesNotExist($marker);
        $this->assertArrayNotHasKey('ap_active_upgrade_loaded', $GLOBALS);
    }

    /**
     * Load active plugins in a fresh PHP process.
     *
     * include_once will not execute a file this process already included, even
     * after the upgrade replaced that path. The next request is a new process.
     */
    private function runNextPluginLoad(): void
    {
        $script = $this->workDir . '/next-load.php';
        $root = var_export($this->root, true);
        $plugins = var_export($this->pluginsRoot, true);
        $dbPath = var_export($this->dbPath, true);
        file_put_contents($script, <<<PHP
<?php
declare(strict_types=1);
require {$root} . '/ap-includes/version.php';
require {$root} . '/ap-includes/hooks.php';
require {$root} . '/ap-includes/class-ap-db.php';
require {$root} . '/ap-includes/class-ap-migrator.php';
require {$root} . '/ap-includes/class-ap-options.php';
require {$root} . '/ap-includes/class-ap-plugin.php';
require {$root} . '/ap-includes/functions.php';
\$pdo = new PDO('sqlite:' . {$dbPath}, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
\$db = AP_DB::fromPdo(\$pdo, 'sqlite', 'ap_');
\$GLOBALS['apdb'] = \$db;
AP_Plugin::setPluginsRootOverride({$plugins});
AP_Plugin::loadActivePlugins(\$db);
PHP);
        $php = PHP_BINARY;
        $command = escapeshellarg($php) . ' ' . escapeshellarg($script);
        $output = [];
        $exitCode = 0;
        exec($command . ' 2>&1', $output, $exitCode);
        $this->assertSame(0, $exitCode, implode("\n", $output));
    }

    /**
     * @return array<string, array{from: string|null, to: string}>
     */
    private function pendingUpgrades(): array
    {
        $value = AP_Options::get(AP_Plugin::OPTION_PENDING_UPGRADES, [], $this->db);
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $plugin => $job) {
            if (!is_string($plugin) || !is_array($job)) {
                continue;
            }
            $from = $job['from'] ?? null;
            $out[$plugin] = [
                'from' => is_string($from) ? $from : null,
                'to' => (string) ($job['to'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * @param 'record'|'append'|'throw'|'stale-upgrade'|'activate-only'|'deactivate-only'|'' $mode
     */
    private function pluginSource(string $name, string $version, string $marker, string $mode): string
    {
        $exported = var_export($marker, true);
        $versionLine = $version !== '' ? " * Version: {$version}\n" : '';
        $source = "<?php\n/**\n * Plugin Name: {$name}\n{$versionLine} */\n";
        if ($mode === 'record' || $mode === 'append' || $mode === 'throw') {
            $source .= "\$GLOBALS['ap_active_upgrade_loaded'] = 'loaded';\n";
        }
        if ($mode === 'activate-only' || $mode === 'deactivate-only' || $mode === 'stale-upgrade') {
            $source .= "ap_register_activation_hook(__FILE__, static function (): void {\n"
                . "    file_put_contents({$exported}, 'activated');\n"
                . "});\n";
        }
        if ($mode === 'deactivate-only') {
            $source .= "ap_register_deactivation_hook(__FILE__, static function (): void {\n"
                . "    file_put_contents({$exported}, 'deactivated');\n"
                . "});\n";
        }
        if ($mode === 'stale-upgrade') {
            $source .= "ap_register_upgrade_hook(__FILE__, static function (): void {\n"
                . "    file_put_contents({$exported}, 'stale-upgrade');\n"
                . "});\n";
        }
        if ($mode === 'record') {
            $source .= "ap_register_activation_hook(__FILE__, static function (): void {\n"
                . "    file_put_contents({$exported}, 'activated-new');\n"
                . "});\n";
            $source .= "ap_register_deactivation_hook(__FILE__, static function (): void {\n"
                . "    file_put_contents({$exported}, 'deactivated');\n"
                . "});\n";
            $source .= "ap_register_upgrade_hook(__FILE__, static function (\n"
                . "    string \$plugin,\n"
                . "    ?string \$from,\n"
                . "    string \$to\n"
                . "): void {\n"
                . "    file_put_contents({$exported}, \$plugin . '|' . (\$from ?? '') . '|' . \$to);\n"
                . "});\n";
        }
        if ($mode === 'append') {
            $source .= "ap_register_upgrade_hook(__FILE__, static function (\n"
                . "    string \$plugin,\n"
                . "    ?string \$from,\n"
                . "    string \$to\n"
                . "): void {\n"
                . "    file_put_contents({$exported}, (\$from ?? '') . '>' . \$to . \"\\n\", FILE_APPEND);\n"
                . "});\n";
        }
        if ($mode === 'throw') {
            $source .= "ap_register_upgrade_hook(__FILE__, static function (): void {\n"
                . "    file_put_contents({$exported}, 'ran');\n"
                . "    throw new \\RuntimeException('upgrade failed');\n"
                . "});\n";
        }

        return $source;
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
