<?php

/**
 * Tests for AP_Site_Health — status checks, info, cache cleanup.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Admin;

use AP_DB;
use AP_Migrator;
use AP_Options;
use AP_Roles;
use AP_Site_Health;
use AP_Transient;
use AP_User;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_Site_Health::class)]
final class SiteHealthTest extends TestCase
{
    private string $root;

    private AP_DB $db;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        require_once $this->root . '/ap-includes/version.php';
        require_once $this->root . '/ap-includes/hooks.php';
        require_once $this->root . '/ap-includes/class-ap-db.php';
        require_once $this->root . '/ap-includes/class-ap-migrator.php';
        require_once $this->root . '/ap-includes/class-ap-options.php';
        require_once $this->root . '/ap-includes/class-ap-user.php';
        require_once $this->root . '/ap-includes/class-ap-session.php';
        require_once $this->root . '/ap-includes/class-ap-roles.php';
        require_once $this->root . '/ap-includes/class-ap-transient.php';
        require_once $this->root . '/ap-includes/class-ap-object-cache.php';
        require_once $this->root . '/ap-includes/object-cache-default.php';
        require_once $this->root . '/ap-includes/class-ap-requirements.php';
        require_once $this->root . '/ap-includes/class-ap-version-check.php';
        require_once $this->root . '/ap-includes/class-ap-privacy.php';
        require_once $this->root . '/ap-includes/class-ap-site-health.php';
        require_once $this->root . '/ap-includes/functions.php';

        if (!defined('AP_AUTH_KEY')) {
            define('AP_AUTH_KEY', 'test-auth-key-' . str_repeat('a', 32));
        }
        if (!defined('AP_SECURE_AUTH_KEY')) {
            define('AP_SECURE_AUTH_KEY', 'test-secure-auth-key-' . str_repeat('b', 32));
        }
        if (!defined('AP_LOGGED_IN_KEY')) {
            define('AP_LOGGED_IN_KEY', 'test-logged-in-key-' . str_repeat('c', 32));
        }
        if (!defined('AP_NONCE_KEY')) {
            define('AP_NONCE_KEY', 'test-nonce-key-' . str_repeat('d', 32));
        }
        if (!defined('AP_AUTH_SALT')) {
            define('AP_AUTH_SALT', 'test-auth-salt-' . str_repeat('e', 32));
        }
        if (!defined('AP_SECURE_AUTH_SALT')) {
            define('AP_SECURE_AUTH_SALT', 'test-secure-auth-salt-' . str_repeat('f', 32));
        }
        if (!defined('AP_LOGGED_IN_SALT')) {
            define('AP_LOGGED_IN_SALT', 'test-logged-in-salt-' . str_repeat('g', 32));
        }
        if (!defined('AP_NONCE_SALT')) {
            define('AP_NONCE_SALT', 'test-nonce-salt-' . str_repeat('h', 32));
        }
        if (!defined('AP_DEBUG')) {
            define('AP_DEBUG', false);
        }
        if (!defined('AP_TELEMETRY')) {
            define('AP_TELEMETRY', false);
        }

        AP_Roles::flushCache();
        AP_Options::flushCache();

        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');

        $migrator = new AP_Migrator($this->db, AP_Migrator::defaultMigrationsPath());
        $migrator->migrate();
        AP_Roles::ensureDefaults($this->db);

        // Seed options used by checks / info.
        AP_Options::update('blogname', 'Health Test Site', $this->db);
        AP_Options::update('siteurl', 'https://example.test', $this->db);
        AP_Options::update('home', 'https://example.test', $this->db);
        AP_Options::update('admin_email', 'admin@example.test', $this->db);
        AP_Options::update(AP_Options::MODULE_BLOG, '1', $this->db);
        AP_Options::update(AP_Options::MODULE_STATIC_PAGES, '1', $this->db);
        AP_Options::update(AP_Options::MODULE_FORUM, '1', $this->db);
        AP_Options::update('wp_page_for_privacy_policy', '0', $this->db);
    }

    protected function tearDown(): void
    {
        AP_Roles::flushCache();
        AP_Options::flushCache();
    }

    public function testGetChecksReturnsStructuredRows(): void
    {
        $checks = AP_Site_Health::getChecks($this->db, $this->root . '/');
        $this->assertNotSame([], $checks);

        $ids = [];
        foreach ($checks as $check) {
            $this->assertArrayHasKey('id', $check);
            $this->assertArrayHasKey('label', $check);
            $this->assertArrayHasKey('status', $check);
            $this->assertArrayHasKey('message', $check);
            $this->assertContains(
                $check['status'],
                [AP_Site_Health::STATUS_GOOD, AP_Site_Health::STATUS_RECOMMENDED, AP_Site_Health::STATUS_CRITICAL]
            );
            $ids[] = $check['id'];
        }

        $this->assertContains('php_version', $ids);
        $this->assertContains('database', $ids);
        $this->assertContains('schema', $ids);
        $this->assertContains('salts', $ids);
        $this->assertContains('telemetry', $ids);
        $this->assertContains('https', $ids);
        $this->assertContains('modules', $ids);
        $this->assertContains('privacy_policy', $ids);
        $this->assertContains('autoload_options', $ids);
        $this->assertContains('php_memory', $ids);
        $this->assertContains('page_cache', $ids);
    }

    public function testPhpAndDatabaseChecksPassInTestEnv(): void
    {
        $checks = AP_Site_Health::getChecks($this->db, $this->root . '/');
        $byId = [];
        foreach ($checks as $check) {
            $byId[$check['id']] = $check;
        }

        $this->assertSame(AP_Site_Health::STATUS_GOOD, $byId['php_version']['status']);
        $this->assertSame(AP_Site_Health::STATUS_GOOD, $byId['database']['status']);
        $this->assertSame(AP_Site_Health::STATUS_GOOD, $byId['schema']['status']);
        $this->assertSame(AP_Site_Health::STATUS_GOOD, $byId['salts']['status']);
        $this->assertSame(AP_Site_Health::STATUS_GOOD, $byId['telemetry']['status']);
        $this->assertSame(AP_Site_Health::STATUS_GOOD, $byId['https']['status']);
        $this->assertSame(AP_Site_Health::STATUS_GOOD, $byId['modules']['status']);
        // Privacy policy intentionally unset → recommended.
        $this->assertSame(AP_Site_Health::STATUS_RECOMMENDED, $byId['privacy_policy']['status']);
    }

    public function testSummaryAndOverallStatus(): void
    {
        $checks = [
            ['status' => 'good'],
            ['status' => 'good'],
            ['status' => 'recommended'],
            ['status' => 'critical'],
        ];
        $summary = AP_Site_Health::getSummary($checks);
        $this->assertSame(2, $summary['good']);
        $this->assertSame(1, $summary['recommended']);
        $this->assertSame(1, $summary['critical']);
        $this->assertSame(4, $summary['total']);
        $this->assertSame(AP_Site_Health::STATUS_CRITICAL, AP_Site_Health::getOverallStatus($checks));

        $onlyRec = [
            ['status' => 'good'],
            ['status' => 'recommended'],
        ];
        $this->assertSame(AP_Site_Health::STATUS_RECOMMENDED, AP_Site_Health::getOverallStatus($onlyRec));
        $this->assertSame(
            AP_Site_Health::STATUS_GOOD,
            AP_Site_Health::getOverallStatus([['status' => 'good']])
        );
    }

    public function testGetInfoAndInfoText(): void
    {
        $info = AP_Site_Health::getInfo($this->db, $this->root . '/');
        $this->assertArrayHasKey('agorapress', $info);
        $this->assertArrayHasKey('server', $info);
        $this->assertArrayHasKey('performance', $info);
        $this->assertArrayHasKey('database', $info);
        $this->assertArrayHasKey('constants', $info);

        $text = AP_Site_Health::getInfoText($this->db, $this->root . '/');
        $this->assertStringContainsString('AgoraPress Site Health', $text);
        $this->assertStringContainsString('PHP version', $text);
        $this->assertStringContainsString('Performance', $text);
        $this->assertStringContainsString('Health Test Site', $text);
        // Must not leak salt values.
        $this->assertStringNotContainsString(str_repeat('a', 32), $text);
        $this->assertStringContainsString('redacted', $text);
    }

    public function testDeleteExpiredTransients(): void
    {
        AP_Options::update('_transient_timeout_ap_sh_old', (string) (time() - 60), $this->db, 'no');
        AP_Options::update('_transient_ap_sh_old', 'stale', $this->db, 'no');
        AP_Options::update('_transient_timeout_ap_sh_live', (string) (time() + 3600), $this->db, 'no');
        AP_Options::update('_transient_ap_sh_live', 'fresh', $this->db, 'no');

        $n = AP_Site_Health::deleteExpiredTransients($this->db);
        $this->assertSame(1, $n);
        $this->assertFalse(AP_Options::get('_transient_ap_sh_old', false, $this->db));
        $this->assertSame('fresh', AP_Options::get('_transient_ap_sh_live', false, $this->db));
    }

    public function testClearCaches(): void
    {
        AP_Options::update('_transient_timeout_ap_sh_x', (string) (time() - 10), $this->db, 'no');
        AP_Options::update('_transient_ap_sh_x', 'gone', $this->db, 'no');

        $result = AP_Site_Health::clearCaches($this->db);
        $this->assertTrue($result['ok']);
        $this->assertSame(1, $result['expired_transients']);
        $this->assertStringContainsString('Cleared', $result['message']);
    }

    public function testCoreUpdateUsesCacheOnly(): void
    {
        // Seed a cached remote version newer than AP_VERSION.
        AP_Transient::set(
            'ap_version_check',
            [
                'ok' => true,
                'version' => '99.0.0',
                'download_url' => 'https://example.test/pkg.zip',
                'changelog_url' => '',
                'sha256' => '',
                'checked_at' => time(),
            ],
            3600,
            $this->db
        );

        $checks = AP_Site_Health::getChecks($this->db, $this->root . '/');
        $core = null;
        foreach ($checks as $check) {
            if ($check['id'] === 'core_update') {
                $core = $check;
                break;
            }
        }
        $this->assertNotNull($core);
        $this->assertSame(AP_Site_Health::STATUS_RECOMMENDED, $core['status']);
        $this->assertStringContainsString('99.0.0', $core['message']);
    }

    public function testModulesCriticalWhenAllOff(): void
    {
        AP_Options::update(AP_Options::MODULE_BLOG, '0', $this->db);
        AP_Options::update(AP_Options::MODULE_STATIC_PAGES, '0', $this->db);
        AP_Options::update(AP_Options::MODULE_FORUM, '0', $this->db);

        $checks = AP_Site_Health::getChecks($this->db, $this->root . '/');
        $modules = null;
        foreach ($checks as $check) {
            if ($check['id'] === 'modules') {
                $modules = $check;
                break;
            }
        }
        $this->assertNotNull($modules);
        $this->assertSame(AP_Site_Health::STATUS_CRITICAL, $modules['status']);
    }

    public function testAdministratorHasViewSiteHealthCap(): void
    {
        $created = AP_User::create([
            'user_login' => 'healthadmin',
            'user_email' => 'healthadmin@example.test',
            'password' => 'password123',
            'role' => 'administrator',
        ], $this->db);
        $this->assertTrue($created['ok']);
        $this->assertTrue(AP_Roles::userCan($created['id'], 'view_site_health', null, $this->db));

        $sub = AP_User::create([
            'user_login' => 'healthsub',
            'user_email' => 'healthsub@example.test',
            'password' => 'password123',
            'role' => 'subscriber',
        ], $this->db);
        $this->assertTrue($sub['ok']);
        $this->assertFalse(AP_Roles::userCan($sub['id'], 'view_site_health', null, $this->db));
    }

    public function testProceduralWrappers(): void
    {
        $checks = ap_get_site_health_checks($this->db, $this->root . '/');
        $this->assertNotSame([], $checks);
        $summary = ap_get_site_health_summary($checks, $this->db);
        $this->assertArrayHasKey('total', $summary);
        $status = ap_get_site_health_status($checks, $this->db);
        $this->assertContains($status, ['good', 'recommended', 'critical']);
        $info = ap_get_site_health_info($this->db, $this->root . '/');
        $this->assertArrayHasKey('server', $info);
        $text = ap_get_site_health_info_text($this->db, $this->root . '/');
        $this->assertNotSame('', $text);
        $clear = ap_clear_site_health_caches($this->db);
        $this->assertTrue($clear['ok']);
    }

    public function testFilterExtendsChecks(): void
    {
        ap_add_filter('ap_site_health_checks', static function (array $checks): array {
            $checks[] = [
                'id' => 'custom_plugin_check',
                'label' => 'Custom',
                'status' => 'good',
                'message' => 'Plugin OK',
            ];

            return $checks;
        });

        $checks = AP_Site_Health::getChecks($this->db, $this->root . '/');
        $ids = array_column($checks, 'id');
        $this->assertContains('custom_plugin_check', $ids);

        ap_remove_all_filters('ap_site_health_checks');
    }

    public function testStatusLabelAndFormatBytes(): void
    {
        $this->assertSame('Good', AP_Site_Health::statusLabel('good'));
        $this->assertSame('Critical', AP_Site_Health::statusLabel('critical'));
        $this->assertSame('Recommended', AP_Site_Health::statusLabel('recommended'));
        $this->assertSame('512 B', AP_Site_Health::formatBytes(512));
        $this->assertStringContainsString('KiB', AP_Site_Health::formatBytes(2048));
    }

    public function testNormalizeStatus(): void
    {
        $this->assertSame('good', AP_Site_Health::normalizeStatus('GOOD'));
        $this->assertSame('critical', AP_Site_Health::normalizeStatus('critical'));
        $this->assertSame('good', AP_Site_Health::normalizeStatus('bogus'));
    }
}
