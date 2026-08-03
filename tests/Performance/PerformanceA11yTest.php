<?php

/**
 * Performance + accessibility audit regression tests.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Performance;

use AP_Admin;
use AP_Assets;
use AP_DB;
use AP_Migrator;
use AP_Options;
use AP_Site_Health;
use PDO;
use PHPUnit\Framework\TestCase;

final class PerformanceA11yTest extends TestCase
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
        require_once $this->root . '/ap-includes/class-ap-assets.php';
        require_once $this->root . '/ap-includes/class-ap-object-cache.php';
        require_once $this->root . '/ap-includes/object-cache-default.php';
        require_once $this->root . '/ap-includes/class-ap-requirements.php';
        require_once $this->root . '/ap-includes/class-ap-site-health.php';
        require_once $this->root . '/ap-includes/class-ap-roles.php';
        require_once $this->root . '/ap-includes/functions.php';
        require_once $this->root . '/ap-admin/includes/class-ap-admin.php';

        if (!defined('AP_DEBUG')) {
            define('AP_DEBUG', false);
        }
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

        AP_Options::flushCache();
        AP_Assets::reset();
        if (function_exists('ap_reset_hooks')) {
            ap_reset_hooks();
        }
        AP_Admin::clearNotices();

        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');
        $migrator = new AP_Migrator($this->db, AP_Migrator::defaultMigrationsPath());
        $migrator->migrate();

        AP_Options::update('blogname', 'Perf Site', $this->db);
        AP_Options::update('siteurl', 'https://example.test', $this->db);
        AP_Options::update('home', 'https://example.test', $this->db);
        AP_Options::update('admin_email', 'admin@example.test', $this->db);
        AP_Options::update(AP_Options::MODULE_BLOG, '1', $this->db);
        AP_Options::update(AP_Options::MODULE_STATIC_PAGES, '1', $this->db);
        AP_Options::update(AP_Options::MODULE_FORUM, '1', $this->db);
    }

    protected function tearDown(): void
    {
        AP_Options::flushCache();
        AP_Assets::reset();
        AP_Admin::clearNotices();
        if (function_exists('ap_reset_hooks')) {
            ap_reset_hooks();
        }
    }

    public function testDbCountsQueries(): void
    {
        $this->db->resetQueryLog();
        $this->assertSame(0, $this->db->getNumQueries());
        $this->db->getVar('SELECT 1');
        $this->db->getVar('SELECT 2');
        $this->assertSame(2, $this->db->getNumQueries());
        $this->assertGreaterThanOrEqual(0.0, $this->db->getTotalQueryTime());
        // Without SAVEQUERIES, log stays empty.
        $this->assertSame([], $this->db->getQueries());
    }

    public function testScriptDeferStrategyPrintsAttribute(): void
    {
        AP_Assets::reset();
        $this->assertTrue(
            ap_enqueue_script(
                'defer-me',
                'https://example.test/defer.js',
                [],
                '1',
                ['in_footer' => true, 'strategy' => 'defer']
            )
        );
        $this->assertSame('defer', ap_get_script_strategy('defer-me'));

        ob_start();
        ap_print_scripts(true);
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('defer.js', $html);
        $this->assertMatchesRegularExpression('/<script[^>]+defer/i', $html);

        AP_Assets::reset();
        ap_register_script('async-me', 'https://example.test/async.js', [], '1', false);
        $this->assertTrue(ap_script_add_data('async-me', 'strategy', 'async'));
        ap_enqueue_script('async-me');
        ob_start();
        ap_print_scripts(false);
        $asyncHtml = (string) ob_get_clean();
        $this->assertMatchesRegularExpression('/<script[^>]+async/i', $asyncHtml);
    }

    public function testResourceHintsFilter(): void
    {
        if (function_exists('ap_reset_hooks')) {
            ap_reset_hooks();
        }
        ap_add_filter(
            'ap_resource_hints',
            static function (array $urls, string $relation): array {
                if ($relation === 'dns-prefetch') {
                    $urls[] = 'https://cdn.example.test';
                }

                return $urls;
            },
            10,
            2
        );

        ob_start();
        ap_print_resource_hints();
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('rel="dns-prefetch"', $html);
        $this->assertStringContainsString('cdn.example.test', $html);
    }

    public function testSiteHealthIncludesPerformanceChecks(): void
    {
        $checks = AP_Site_Health::getChecks($this->db, $this->root . '/');
        $ids = array_column($checks, 'id');
        $this->assertContains('autoload_options', $ids);
        $this->assertContains('php_memory', $ids);
        $this->assertContains('page_cache', $ids);
        $this->assertContains('object_cache', $ids);

        $byId = [];
        foreach ($checks as $check) {
            $byId[$check['id']] = $check;
        }
        $this->assertSame(AP_Site_Health::STATUS_GOOD, $byId['autoload_options']['status']);
        $this->assertContains(
            $byId['php_memory']['status'],
            [AP_Site_Health::STATUS_GOOD, AP_Site_Health::STATUS_RECOMMENDED, AP_Site_Health::STATUS_CRITICAL]
        );
        $this->assertSame(AP_Site_Health::STATUS_GOOD, $byId['page_cache']['status']);

        $info = AP_Site_Health::getInfo($this->db, $this->root . '/');
        $this->assertArrayHasKey('performance', $info);
        $text = AP_Site_Health::getInfoText($this->db, $this->root . '/');
        $this->assertStringContainsString('Performance', $text);
        $this->assertStringContainsString('Autoloaded options', $text);
    }

    public function testAdminNoticesUseAlertRoleForErrors(): void
    {
        AP_Admin::clearNotices();
        AP_Admin::addNotice('All good', 'success');
        AP_Admin::addNotice('Broken thing', 'error');
        AP_Admin::addNotice('Heads up', 'warning');
        $html = AP_Admin::renderNotices();
        $this->assertStringContainsString('role="status"', $html);
        $this->assertStringContainsString('role="alert"', $html);
        $this->assertStringContainsString('ap-notice--error', $html);
        $this->assertStringContainsString('Broken thing', $html);
    }

    public function testThemeLandmarksInTemplates(): void
    {
        $header = (string) file_get_contents($this->root . '/ap-content/themes/agora/header.php');
        $footer = (string) file_get_contents($this->root . '/ap-content/themes/agora/footer.php');
        $css = (string) file_get_contents($this->root . '/ap-content/themes/agora/style.css');

        $this->assertStringContainsString('role="banner"', $header);
        $this->assertStringContainsString('role="main"', $header);
        $this->assertStringContainsString('skip-link', $header);
        $this->assertStringContainsString('role="contentinfo"', $footer);
        $this->assertStringContainsString('prefers-contrast', $css);
        $this->assertStringContainsString('prefers-reduced-motion', $css);
        $this->assertStringContainsString('focus-visible', $css);
    }

    public function testAdminMainLandmarkAndConfigSample(): void
    {
        $adminHeader = (string) file_get_contents($this->root . '/ap-admin/admin-header.php');
        $this->assertStringContainsString('role="main"', $adminHeader);
        $this->assertStringContainsString('skip-link', $adminHeader);

        $sample = (string) file_get_contents($this->root . '/ap-config-sample.php');
        $this->assertStringContainsString("define('AP_SAVEQUERIES'", $sample);
        $this->assertStringContainsString("define('AP_DEBUG_QUERIES'", $sample);

        $postsTable = (string) file_get_contents(
            $this->root . '/ap-admin/includes/class-ap-posts-list-table.php'
        );
        $this->assertStringContainsString('aria-label="Posts pagination"', $postsTable);
    }

    public function testParseIniBytesViaMemoryCheckMessage(): void
    {
        // formatBytes / memory check should always produce a structured row.
        $checks = AP_Site_Health::getChecks($this->db, $this->root . '/');
        $memory = null;
        foreach ($checks as $check) {
            if ($check['id'] === 'php_memory') {
                $memory = $check;
                break;
            }
        }
        $this->assertNotNull($memory);
        $this->assertStringContainsString('memory_limit', strtolower($memory['message']));
    }
}
