<?php

/**
 * Tests for AP_Core_Updater (one-click core auto-update).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Admin;

use AP_Admin;
use AP_Core_Updater;
use AP_DB;
use AP_Migrator;
use AP_Options;
use AP_Roles;
use AP_Transient;
use AP_User;
use AP_Version_Check;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ZipArchive;

#[CoversClass(AP_Core_Updater::class)]
final class CoreUpdaterTest extends TestCase
{
    private string $root;

    private string $workDir;

    private AP_DB $db;

    /** @var list<array{method: string, url: string}> */
    private array $httpCalls = [];

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        require_once $this->root . '/ap-includes/version.php';
        require_once $this->root . '/ap-includes/class-ap-db.php';
        require_once $this->root . '/ap-includes/class-ap-migrator.php';
        require_once $this->root . '/ap-includes/class-ap-options.php';
        require_once $this->root . '/ap-includes/class-ap-transient.php';
        require_once $this->root . '/ap-includes/class-ap-user.php';
        require_once $this->root . '/ap-includes/class-ap-session.php';
        require_once $this->root . '/ap-includes/class-ap-roles.php';
        require_once $this->root . '/ap-includes/class-ap-nonce.php';
        require_once $this->root . '/ap-includes/class-ap-version-check.php';
        require_once $this->root . '/ap-includes/class-ap-core-updater.php';
        require_once $this->root . '/ap-includes/functions.php';
        require_once $this->root . '/ap-admin/includes/class-ap-admin.php';

        if (!defined('AP_NONCE_KEY')) {
            define('AP_NONCE_KEY', 'test-nonce-key-' . str_repeat('u', 32));
        }
        if (!defined('AP_NONCE_SALT')) {
            define('AP_NONCE_SALT', 'test-nonce-salt-' . str_repeat('p', 32));
        }
        if (!defined('AP_LOGGED_IN_KEY')) {
            define('AP_LOGGED_IN_KEY', 'test-logged-in-key-' . str_repeat('d', 32));
        }
        if (!defined('AP_LOGGED_IN_SALT')) {
            define('AP_LOGGED_IN_SALT', 'test-logged-in-salt-' . str_repeat('a', 32));
        }

        AP_Roles::flushCache();
        AP_Options::flushCache();
        AP_Version_Check::resetHttpTransport();
        AP_Core_Updater::resetHttpTransport();
        AP_Admin::clearNotices();
        $this->httpCalls = [];

        $this->workDir = sys_get_temp_dir() . '/ap-core-updater-test-' . bin2hex(random_bytes(6));
        mkdir($this->workDir, 0700, true);

        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');
        $migrator = new AP_Migrator($this->db, AP_Migrator::defaultMigrationsPath());
        $migrator->migrate();
        AP_Roles::ensureDefaults($this->db);

        AP_Options::update(AP_Version_Check::OPTION_ENABLED, '1', $this->db);
    }

    protected function tearDown(): void
    {
        AP_Version_Check::resetHttpTransport();
        AP_Core_Updater::resetHttpTransport();
        AP_Roles::flushCache();
        AP_Options::flushCache();
        AP_Admin::clearNotices();
        $this->removeDir($this->workDir);
    }

    public function testPrivacyInvariant(): void
    {
        $this->assertFalse(AP_Core_Updater::sendsSiteIdentity());
    }

    public function testShouldApplyRelativePreservesUserContent(): void
    {
        $this->assertFalse(AP_Core_Updater::shouldApplyRelative('ap-config.php'));
        $this->assertFalse(AP_Core_Updater::shouldApplyRelative('.maintenance'));
        $this->assertFalse(AP_Core_Updater::shouldApplyRelative('ap-content/uploads/2026/x.jpg'));
        $this->assertFalse(AP_Core_Updater::shouldApplyRelative('ap-content/plugins/my-plugin/plugin.php'));
        $this->assertFalse(AP_Core_Updater::shouldApplyRelative('ap-content/mu-plugins/must.php'));
        $this->assertFalse(AP_Core_Updater::shouldApplyRelative('ap-content/themes/custom/style.css'));
        $this->assertFalse(AP_Core_Updater::shouldApplyRelative('../etc/passwd'));

        $this->assertTrue(AP_Core_Updater::shouldApplyRelative('ap-includes/class-ap-db.php'));
        $this->assertTrue(AP_Core_Updater::shouldApplyRelative('ap-admin/index.php'));
        $this->assertTrue(AP_Core_Updater::shouldApplyRelative('index.php'));
        $this->assertTrue(AP_Core_Updater::shouldApplyRelative('ap-content/themes/agora/style.css'));
        $this->assertTrue(AP_Core_Updater::shouldApplyRelative('ap-content/themes/agora/index.php'));
    }

    public function testMaintenanceModeLifecycle(): void
    {
        $site = $this->workDir . '/site';
        mkdir($site, 0700, true);

        $this->assertFalse(AP_Core_Updater::isMaintenanceMode($site));
        $this->assertTrue(AP_Core_Updater::enableMaintenance($site));
        $this->assertTrue(AP_Core_Updater::isMaintenanceMode($site));
        $this->assertFileExists($site . '/.maintenance');
        $html = AP_Core_Updater::maintenanceHtml();
        $this->assertStringContainsString('Briefly unavailable', $html);
        $this->assertTrue(AP_Core_Updater::disableMaintenance($site));
        $this->assertFalse(AP_Core_Updater::isMaintenanceMode($site));
    }

    public function testVerifySha256(): void
    {
        $file = $this->workDir . '/pkg.bin';
        file_put_contents($file, 'hello-agorapress');
        $hash = hash_file('sha256', $file);
        $this->assertIsString($hash);

        $ok = AP_Core_Updater::verifySha256($file, $hash);
        $this->assertTrue($ok['ok']);

        $bad = AP_Core_Updater::verifySha256($file, str_repeat('a', 64));
        $this->assertFalse($bad['ok']);
        $this->assertStringContainsString('checksum', strtolower($bad['error']));
    }

    public function testDetectPackageRootFolderLayout(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $pkg = $this->buildMinimalPackageDir('1.2.3');
        $stage = $this->workDir . '/stage-folder';
        mkdir($stage, 0700, true);
        // Nested folder layout: stage/AgoraPress-1.2.3/...
        $nested = $stage . '/AgoraPress-1.2.3';
        $this->copyDir($pkg, $nested);

        $detected = AP_Core_Updater::detectPackageRoot($stage);
        $this->assertTrue($detected['ok']);
        $this->assertSame('1.2.3', $detected['package_version']);
        $this->assertSame($nested, $detected['package_root']);
    }

    public function testRunFromLocalPackageAppliesFilesAndPreservesConfig(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $site = $this->workDir . '/live-site';
        $this->seedFakeSite($site, '0.1.0-dev');

        // User content that must survive.
        file_put_contents($site . '/ap-config.php', "<?php\n// secret site config\n");
        if (!is_dir($site . '/ap-content/uploads')) {
            mkdir($site . '/ap-content/uploads', 0700, true);
        }
        file_put_contents($site . '/ap-content/uploads/keep.txt', 'user-upload');
        if (!is_dir($site . '/ap-content/plugins/keepme')) {
            mkdir($site . '/ap-content/plugins/keepme', 0700, true);
        }
        file_put_contents($site . '/ap-content/plugins/keepme/plugin.php', '<?php // keep');
        if (!is_dir($site . '/ap-content/themes/custom')) {
            mkdir($site . '/ap-content/themes/custom', 0700, true);
        }
        file_put_contents($site . '/ap-content/themes/custom/style.css', '/* custom */');

        $pkgDir = $this->buildMinimalPackageDir('9.9.9');
        // Package tries to overwrite config + custom theme (must be ignored).
        file_put_contents($pkgDir . '/ap-config.php', "<?php\n// evil\n");
        if (!is_dir($pkgDir . '/ap-content/themes/custom')) {
            mkdir($pkgDir . '/ap-content/themes/custom', 0700, true);
        }
        file_put_contents($pkgDir . '/ap-content/themes/custom/style.css', '/* pwned */');
        // Default theme update.
        if (!is_dir($pkgDir . '/ap-content/themes/agora')) {
            mkdir($pkgDir . '/ap-content/themes/agora', 0700, true);
        }
        file_put_contents($pkgDir . '/ap-content/themes/agora/style.css', "/* agora 9.9.9 */\n");
        // Core file change.
        file_put_contents(
            $pkgDir . '/ap-includes/marker.php',
            "<?php\n// updated marker\n"
        );

        $zipPath = $this->workDir . '/release-9.9.9.zip';
        $this->zipDirectory($pkgDir, $zipPath, 'AgoraPress-9.9.9');

        $result = AP_Core_Updater::run($this->db, [
            'abspath' => $site,
            'package_path' => $zipPath,
            'expected_version' => '9.9.9',
            'skip_migrate' => true,
        ]);

        $this->assertTrue($result['ok'], implode(' ', $result['errors']));
        $this->assertSame('9.9.9', $result['package_version']);
        $this->assertSame('9.9.9', $result['to_version']);
        $this->assertGreaterThan(0, $result['files_applied']);
        $this->assertFalse(AP_Core_Updater::isMaintenanceMode($site));

        // Preserved.
        $this->assertStringContainsString('secret site config', (string) file_get_contents($site . '/ap-config.php'));
        $this->assertSame('user-upload', (string) file_get_contents($site . '/ap-content/uploads/keep.txt'));
        $this->assertSame('<?php // keep', (string) file_get_contents($site . '/ap-content/plugins/keepme/plugin.php'));
        $this->assertSame('/* custom */', (string) file_get_contents($site . '/ap-content/themes/custom/style.css'));

        // Applied.
        $this->assertFileExists($site . '/ap-includes/marker.php');
        $this->assertStringContainsString('updated marker', (string) file_get_contents($site . '/ap-includes/marker.php'));
        $this->assertStringContainsString('agora 9.9.9', (string) file_get_contents($site . '/ap-content/themes/agora/style.css'));
        $this->assertStringContainsString('9.9.9', (string) file_get_contents($site . '/ap-includes/version.php'));
    }

    public function testRunDownloadsViaTransportAndVerifiesSha256(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $site = $this->workDir . '/dl-site';
        $this->seedFakeSite($site, '0.1.0-dev');

        $pkgDir = $this->buildMinimalPackageDir('2.0.0');
        file_put_contents($pkgDir . '/ap-includes/new-file.php', "<?php\n// new\n");
        $zipPath = $this->workDir . '/dl-2.0.0.zip';
        $this->zipDirectory($pkgDir, $zipPath, 'AgoraPress-2.0.0');
        $zipBody = (string) file_get_contents($zipPath);
        $sha = hash('sha256', $zipBody);

        // version.json + package download via injected transports on both classes.
        $versionBody = json_encode([
            'version' => '2.0.0',
            'download_url' => 'https://agorapress.extrovertednerd.com/download/2.0.0.zip',
            'changelog_url' => 'https://agorapress.extrovertednerd.com/changelog',
            'sha256' => $sha,
        ], JSON_UNESCAPED_SLASHES);
        $this->assertNotFalse($versionBody);

        AP_Version_Check::setHttpTransport(function (string $method, string $url) use ($versionBody): array {
            $this->httpCalls[] = ['method' => $method, 'url' => $url];

            return [
                'ok' => true,
                'status' => 200,
                'body' => (string) $versionBody,
                'error' => '',
            ];
        });

        AP_Core_Updater::setHttpTransport(function (string $method, string $url) use ($zipBody): array {
            $this->httpCalls[] = ['method' => $method, 'url' => $url];
            $this->assertSame('GET', $method);
            $this->assertStringNotContainsString('domain', strtolower($url));

            return [
                'ok' => true,
                'status' => 200,
                'body' => $zipBody,
                'error' => '',
            ];
        });

        // Prime remote info cache for canUpdate / run.
        AP_Transient::delete(AP_Version_Check::TRANSIENT_KEY, $this->db);
        $info = AP_Version_Check::getRemoteInfo($this->db, true);
        $this->assertTrue($info['ok']);
        $this->assertSame($sha, $info['sha256']);

        $can = AP_Core_Updater::canUpdate($this->db);
        $this->assertTrue($can['has_update']);
        $this->assertTrue($can['can_update'], implode(' ', $can['errors']));

        $result = AP_Core_Updater::run($this->db, [
            'abspath' => $site,
            'skip_migrate' => true,
        ]);

        $this->assertTrue($result['ok'], implode(' ', $result['errors']));
        $this->assertSame('2.0.0', $result['to_version']);
        $this->assertFileExists($site . '/ap-includes/new-file.php');
        $this->assertContains('verify_sha256', $result['steps']);
        $this->assertContains('download', $result['steps']);

        // Download URL must not include site identity.
        $dlCalls = array_filter(
            $this->httpCalls,
            static fn (array $c): bool => str_contains($c['url'], 'download')
        );
        $this->assertNotEmpty($dlCalls);
    }

    public function testChecksumMismatchAborts(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $site = $this->workDir . '/bad-sha-site';
        $this->seedFakeSite($site, '0.1.0-dev');

        $pkgDir = $this->buildMinimalPackageDir('3.0.0');
        $zipPath = $this->workDir . '/bad-sha.zip';
        $this->zipDirectory($pkgDir, $zipPath, 'AgoraPress-3.0.0');

        $result = AP_Core_Updater::run($this->db, [
            'abspath' => $site,
            'package_path' => $zipPath,
            'expected_version' => '3.0.0',
            'sha256' => str_repeat('0', 64),
            'skip_migrate' => true,
        ]);

        $this->assertFalse($result['ok']);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('checksum', strtolower(implode(' ', $result['errors'])));
        // Site version file unchanged.
        $this->assertStringContainsString('0.1.0-dev', (string) file_get_contents($site . '/ap-includes/version.php'));
    }

    public function testRejectsNonCoreZip(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $site = $this->workDir . '/junk-site';
        $this->seedFakeSite($site, '0.1.0-dev');

        $zipPath = $this->workDir . '/junk.zip';
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFromString('readme.txt', "not agora\n");
        $zip->close();

        $result = AP_Core_Updater::run($this->db, [
            'abspath' => $site,
            'package_path' => $zipPath,
            'expected_version' => '9.0.0',
            'skip_migrate' => true,
        ]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('AgoraPress', implode(' ', $result['errors']));
    }

    public function testParseResponseBodyIncludesSha256(): void
    {
        $parsed = AP_Version_Check::parseResponseBody(json_encode([
            'version' => '1.0.0',
            'download_url' => 'https://example.com/a.zip',
            'sha256' => 'SHA256:' . str_repeat('ab', 32),
        ], JSON_THROW_ON_ERROR));

        $this->assertTrue($parsed['ok']);
        $this->assertSame(str_repeat('ab', 32), $parsed['sha256']);
    }

    public function testNoticeHtmlIncludesUpdateNow(): void
    {
        // buildNoticeHtml does not require AP_ADMIN (do not define it — constants are process-global).
        $body = json_encode([
            'version' => '99.0.0',
            'download_url' => 'https://example.com/dl.zip',
            'changelog_url' => 'https://example.com/cl',
        ], JSON_UNESCAPED_SLASHES);
        $this->assertNotFalse($body);

        AP_Version_Check::setHttpTransport(static function () use ($body): array {
            return [
                'ok' => true,
                'status' => 200,
                'body' => (string) $body,
                'error' => '',
            ];
        });

        $html = AP_Version_Check::buildNoticeHtml($this->db);
        $this->assertStringContainsString('Update available', $html);
        $this->assertStringContainsString('Update now', $html);
        $this->assertStringContainsString('update-core.php', $html);
        $this->assertStringContainsString('Download', $html);
    }

    public function testProceduralHelpers(): void
    {
        $this->assertTrue(function_exists('ap_can_core_update'));
        $this->assertTrue(function_exists('ap_run_core_update'));
        $this->assertTrue(function_exists('ap_is_maintenance_mode'));

        $can = ap_can_core_update($this->db);
        $this->assertArrayHasKey('can_update', $can);
        $this->assertArrayHasKey('checks', $can);
    }

    public function testAdminUpdateCoreScreenExistsAndCaps(): void
    {
        $screen = $this->root . '/ap-admin/update-core.php';
        $this->assertFileExists($screen);
        $src = (string) file_get_contents($screen);
        $this->assertStringContainsString("requireCapability('update_core')", $src);
        $this->assertStringContainsString('ap_run_core_update', $src);
        $this->assertStringContainsString('ap_update_action', $src);

        $map = AP_Admin::screenCapabilities();
        $this->assertSame('update_core', $map['update-core.php'] ?? null);

        $menu = AP_Admin::menuItems('update-core');
        $ids = array_column($menu, 'id');
        $this->assertContains('update-core', $ids);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function seedFakeSite(string $site, string $version): void
    {
        $dirs = [
            $site,
            $site . '/ap-includes',
            $site . '/ap-admin',
            $site . '/ap-content/themes/agora',
            $site . '/ap-content/plugins',
            $site . '/ap-content/uploads',
        ];
        foreach ($dirs as $d) {
            if (!is_dir($d) && !@mkdir($d, 0700, true) && !is_dir($d)) {
                $this->fail('Could not create test site directory: ' . $d);
            }
        }
        file_put_contents($site . '/index.php', "<?php\n// front\n");
        file_put_contents(
            $site . '/ap-includes/version.php',
            "<?php\ndefine('AP_VERSION', '{$version}');\ndefine('AP_DB_VERSION', '9');\n"
        );
        file_put_contents($site . '/ap-admin/index.php', "<?php\n// admin\n");
        file_put_contents($site . '/ap-content/themes/agora/style.css', "/* agora old */\n");
    }

    private function buildMinimalPackageDir(string $version): string
    {
        $dir = $this->workDir . '/pkg-' . preg_replace('/[^0-9A-Za-z.\-]+/', '-', $version);
        if (is_dir($dir)) {
            $this->removeDir($dir);
        }
        mkdir($dir . '/ap-includes', 0700, true);
        mkdir($dir . '/ap-admin', 0700, true);
        file_put_contents($dir . '/index.php', "<?php\n// package front {$version}\n");
        file_put_contents(
            $dir . '/ap-includes/version.php',
            "<?php\ndefine('AP_VERSION', '{$version}');\ndefine('AP_DB_VERSION', '9');\n"
        );
        file_put_contents($dir . '/ap-admin/index.php', "<?php\n// package admin {$version}\n");
        file_put_contents($dir . '/LICENSE', "GPL\n");

        return $dir;
    }

    private function zipDirectory(string $sourceDir, string $zipPath, string $rootName): void
    {
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $sourceDir = rtrim(str_replace('\\', '/', $sourceDir), '/');
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $fileInfo) {
            /** @var \SplFileInfo $fileInfo */
            if (!$fileInfo->isFile()) {
                continue;
            }
            $abs = str_replace('\\', '/', $fileInfo->getPathname());
            $rel = substr($abs, strlen($sourceDir) + 1);
            $zip->addFile($abs, $rootName . '/' . $rel);
        }
        $zip->close();
    }

    private function copyDir(string $src, string $dest): void
    {
        if (!is_dir($dest)) {
            mkdir($dest, 0700, true);
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        $src = rtrim(str_replace('\\', '/', $src), '/');
        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            $abs = str_replace('\\', '/', $item->getPathname());
            $rel = substr($abs, strlen($src) + 1);
            $target = $dest . '/' . $rel;
            if ($item->isDir()) {
                if (!is_dir($target)) {
                    mkdir($target, 0700, true);
                }
            } else {
                $parent = dirname($target);
                if (!is_dir($parent)) {
                    mkdir($parent, 0700, true);
                }
                copy($abs, $target);
            }
        }
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $entries = scandir($dir);
        if (!is_array($entries)) {
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
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
