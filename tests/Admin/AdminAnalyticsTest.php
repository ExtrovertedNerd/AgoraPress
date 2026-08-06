<?php

/**
 * Tests for ACP Analytics screen helpers and wiring.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Admin;

use AP_Admin;
use AP_Admin_Analytics;
use AP_Analytics;
use AP_DB;
use AP_Migrator;
use AP_Options;
use AP_Roles;
use AP_User;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_Admin_Analytics::class)]
final class AdminAnalyticsTest extends TestCase
{
    private string $root;

    private AP_DB $db;

    private int $adminId = 0;

    private int $subscriberId = 0;

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
        require_once $this->root . '/ap-includes/class-ap-analytics.php';
        require_once $this->root . '/ap-includes/class-ap-nonce.php';
        require_once $this->root . '/ap-includes/functions.php';
        require_once $this->root . '/ap-admin/includes/class-ap-admin.php';
        require_once $this->root . '/ap-admin/includes/class-ap-admin-analytics.php';

        if (!defined('AP_NONCE_KEY')) {
            define('AP_NONCE_KEY', 'test-nonce-key-' . str_repeat('a', 32));
        }
        if (!defined('AP_NONCE_SALT')) {
            define('AP_NONCE_SALT', 'test-nonce-salt-' . str_repeat('b', 32));
        }
        if (!defined('AP_LOGGED_IN_KEY')) {
            define('AP_LOGGED_IN_KEY', 'test-logged-in-key-' . str_repeat('c', 32));
        }
        if (!defined('AP_LOGGED_IN_SALT')) {
            define('AP_LOGGED_IN_SALT', 'test-logged-in-salt-' . str_repeat('d', 32));
        }

        AP_Roles::flushCache();
        AP_Options::flushCache();
        AP_Analytics::resetRequestState();
        AP_Admin::clearNotices();
        if (function_exists('ap_reset_hooks')) {
            ap_reset_hooks();
        }

        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');
        $migrator = new AP_Migrator($this->db, AP_Migrator::defaultMigrationsPath());
        $migrator->migrate();
        AP_Roles::ensureDefaults($this->db);

        $admin = AP_User::create([
            'user_login' => 'analyticsadmin',
            'user_email' => 'analyticsadmin@example.test',
            'password' => 'password123',
            'role' => 'administrator',
        ], $this->db);
        $this->adminId = (int) $admin['id'];

        $sub = AP_User::create([
            'user_login' => 'analyticssub',
            'user_email' => 'analyticssub@example.test',
            'password' => 'password123',
            'role' => 'subscriber',
        ], $this->db);
        $this->subscriberId = (int) $sub['id'];
    }

    protected function tearDown(): void
    {
        AP_Roles::flushCache();
        AP_Options::flushCache();
        AP_Analytics::resetRequestState();
        AP_Admin::clearNotices();
        if (function_exists('ap_reset_hooks')) {
            ap_reset_hooks();
        }
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function insertHit(string $hitTime, string $path = '/', array $extra = []): void
    {
        $data = array_merge([
            'hit_time' => $hitTime,
            'path' => $path,
            'object_id' => 0,
            'status_code' => 200,
            'referrer' => '',
            'ua_class' => 'browser',
            'is_admin' => 0,
        ], $extra);
        $result = $this->db->insert('analytics_hits', $data);
        $this->assertNotFalse($result);
    }

    public function testScreenFilesExist(): void
    {
        $this->assertFileIsReadable($this->root . '/ap-admin/analytics.php');
        $this->assertFileIsReadable($this->root . '/ap-admin/includes/class-ap-admin-analytics.php');
    }

    public function testScreenGatesWithManageOptions(): void
    {
        $src = (string) file_get_contents($this->root . '/ap-admin/analytics.php');
        $this->assertStringContainsString('requireCapability', $src);
        $this->assertStringContainsString('AP_Admin_Analytics::CAPABILITY', $src);
        $this->assertStringContainsString('getReport', $src);
        $this->assertStringContainsString('Top paths', $src);
        $this->assertStringContainsString('Top referrers', $src);
        $this->assertStringContainsString('Daily pageviews', $src);
        $this->assertStringContainsString('Pageviews', $src);
        $this->assertStringContainsString('ap-analytics-stat', $src);
        // Privacy posture copy (constants rendered on screen).
        $this->assertStringContainsString('AP_Admin_Analytics::PRIVACY_INTRO', $src);
        $this->assertStringContainsString('AP_Admin_Analytics::PRIVACY_COLLECTION_HELP', $src);
        $this->assertStringContainsString('ap-analytics-intro', $src);
        $this->assertStringContainsString('ap-analytics-collection-help', $src);
    }

    /**
     * Product polish: brief help must state local-only storage, no third party,
     * and that analytics is not Hall of Fame / version-check.
     */
    public function testPrivacyHelpTextLocalOnlyNoThirdPartyNotHofOrVersionCheck(): void
    {
        $intro = AP_Admin_Analytics::PRIVACY_INTRO;
        $help = AP_Admin_Analytics::PRIVACY_COLLECTION_HELP;

        foreach ([$intro, $help] as $text) {
            $lower = strtolower($text);
            // Local-only / site database.
            $this->assertTrue(
                str_contains($lower, 'local') || str_contains($lower, 'this site'),
                'Help should mention local / this site'
            );
            $this->assertStringContainsString('database', $lower);
            // No third party.
            $this->assertTrue(
                str_contains($lower, 'third part') || str_contains($lower, 'third-party'),
                'Help should mention no third parties'
            );
            // Not Hall of Fame / version check.
            $this->assertStringContainsString('hall of fame', $lower);
            $this->assertStringContainsString('version check', $lower);
        }

        $this->assertStringContainsString('never sent to third parties', strtolower($intro));
        $this->assertStringContainsString('no third-party scripts', strtolower($help));
        $this->assertStringContainsString('pixels', strtolower($help));
        $this->assertStringContainsString('external analytics endpoints', strtolower($help));
        $this->assertStringContainsString('not hall of fame', strtolower($help));
        $this->assertStringContainsString('not the public version check', strtolower($help));

        // Screen wires the constants (not hard-coded duplicate prose alone).
        $src = (string) file_get_contents($this->root . '/ap-admin/analytics.php');
        $this->assertStringContainsString('PRIVACY_INTRO', $src);
        $this->assertStringContainsString('PRIVACY_COLLECTION_HELP', $src);
        $this->assertStringContainsString('role="note"', $src);
    }

    public function testScreenHasAnalyticsSettingsForm(): void
    {
        $src = (string) file_get_contents($this->root . '/ap-admin/analytics.php');
        $this->assertStringContainsString('ap-analytics-settings', $src);
        $this->assertStringContainsString('Analytics settings', $src);
        $this->assertStringContainsString('name="analytics_enabled"', $src);
        $this->assertStringContainsString('name="analytics_retention_days"', $src);
        $this->assertStringContainsString('Enable pageview collection', $src);
        $this->assertStringContainsString('Save Analytics Settings', $src);
        $this->assertStringContainsString('saveSettingsFromPost', $src);
        $this->assertStringContainsString('AP_Admin_Analytics::NONCE_ACTION', $src);
        $this->assertStringContainsString('AP_Admin_Analytics::SETTINGS_SUBMIT', $src);
        // Empty/disabled state links to the on-page settings section.
        $this->assertStringContainsString('#ap-analytics-settings', $src);
    }

    public function testScreenHasEmptyStateMarkup(): void
    {
        $src = (string) file_get_contents($this->root . '/ap-admin/analytics.php');
        $this->assertStringContainsString('emptyStateKind', $src);
        $this->assertStringContainsString('emptyStateFor', $src);
        $this->assertStringContainsString('renderEmptyState', $src);
        $this->assertStringContainsString('ap-analytics-empty-banner', $src);
        $this->assertStringContainsString('ap-analytics-disabled-notice', $src);
        $this->assertStringContainsString('ap-analytics-summary-hint', $src);
        $this->assertStringContainsString('ap-analytics-summary--empty', $src);
        $this->assertStringContainsString('Collection is off', $src);
        $this->assertStringContainsString('Nothing is stored until you opt in', $src);
        $this->assertStringContainsString('Existing history still shows below', $src);
        $this->assertStringContainsString('Counts stay at zero until', $src);
    }

    public function testScreenHasDaysWindowTabsAndPrivacyIntro(): void
    {
        $src = (string) file_get_contents($this->root . '/ap-admin/analytics.php');
        $this->assertStringContainsString('ap-analytics-days-tabs', $src);
        $this->assertStringContainsString('Report window', $src);
        $this->assertStringContainsString('AP_Admin_Analytics::ALLOWED_DAYS', $src);
        $this->assertStringContainsString('ap-analytics-intro', $src);
        $this->assertStringContainsString('AP_Admin_Analytics::PRIVACY_INTRO', $src);
        $this->assertStringContainsString('AP_Admin_Analytics::PRIVACY_COLLECTION_HELP', $src);
        // Summary + tables surface SPEC widgets.
        $this->assertStringContainsString('Today', $src);
        $this->assertStringContainsString('Last 7 days', $src);
        $this->assertStringContainsString('Last 30 days', $src);
        $this->assertStringContainsString('ap-analytics-path', $src);
        $this->assertStringContainsString('ap-analytics-referrer', $src);
        $this->assertStringContainsString('ap-analytics-bar', $src);
    }

    public function testAllowedDaysConstant(): void
    {
        $this->assertSame([7, 14, 30, 90], AP_Admin_Analytics::ALLOWED_DAYS);
        $this->assertSame(30, AP_Admin_Analytics::DEFAULT_DAYS);
        $this->assertSame(10, AP_Admin_Analytics::DEFAULT_TOP_LIMIT);
        $this->assertSame('manage_options', AP_Admin_Analytics::CAPABILITY);
        $this->assertSame('ap_analytics_settings', AP_Admin_Analytics::NONCE_ACTION);
        $this->assertSame('ap_save_analytics', AP_Admin_Analytics::SETTINGS_SUBMIT);
        $this->assertNotSame('', AP_Admin_Analytics::PRIVACY_INTRO);
        $this->assertNotSame('', AP_Admin_Analytics::PRIVACY_COLLECTION_HELP);
    }

    public function testEmptyStateKind(): void
    {
        $this->assertSame('disabled_no_data', AP_Admin_Analytics::emptyStateKind(false, false));
        $this->assertSame('enabled_no_data', AP_Admin_Analytics::emptyStateKind(true, false));
        $this->assertSame('disabled_with_history', AP_Admin_Analytics::emptyStateKind(false, true));
        $this->assertSame('has_data', AP_Admin_Analytics::emptyStateKind(true, true));
    }

    public function testEmptyStateForDisabledNoData(): void
    {
        $page = AP_Admin_Analytics::emptyStateFor('page', false, false, 30, true);
        $this->assertSame('disabled_no_data', $page['kind']);
        $this->assertSame('No data yet', $page['title']);
        $this->assertStringContainsString('Collection is off', $page['message']);
        $this->assertTrue($page['show_settings_link']);

        $paths = AP_Admin_Analytics::emptyStateFor('paths', false, false, 30, true);
        $this->assertSame('disabled_no_data', $paths['kind']);
        $this->assertStringContainsString('Turn on collection', $paths['message']);
        $this->assertTrue($paths['show_settings_link']);

        $daily = AP_Admin_Analytics::emptyStateFor('daily', false, false, 7, true);
        $this->assertStringContainsString('No data yet', $daily['message']);
        $this->assertTrue($daily['show_settings_link']);
    }

    public function testEmptyStateForEnabledNoData(): void
    {
        $page = AP_Admin_Analytics::emptyStateFor('page', true, false, 30, true);
        $this->assertSame('enabled_no_data', $page['kind']);
        $this->assertSame('Waiting for pageviews', $page['title']);
        $this->assertStringContainsString('Collection is on', $page['message']);
        $this->assertFalse($page['show_settings_link']);

        $paths = AP_Admin_Analytics::emptyStateFor('paths', true, false, 14, true);
        $this->assertSame('enabled_no_data', $paths['kind']);
        $this->assertStringContainsString('no pageviews have been recorded yet', $paths['message']);
    }

    public function testEmptyStateForWindowEmptyWithHistory(): void
    {
        $paths = AP_Admin_Analytics::emptyStateFor('paths', true, true, 7, true);
        $this->assertSame('has_data', $paths['kind']);
        $this->assertSame('No activity in this window', $paths['title']);
        $this->assertStringContainsString('last 7 days', $paths['message']);
        $this->assertFalse($paths['show_settings_link']);

        $refs = AP_Admin_Analytics::emptyStateFor('referrers', false, true, 30, true);
        $this->assertSame('disabled_with_history', $refs['kind']);
        $this->assertStringContainsString('No referrers recorded', $refs['message']);
    }

    public function testEmptyStateForNonEmptyListIsBlank(): void
    {
        $state = AP_Admin_Analytics::emptyStateFor('paths', false, false, 30, false);
        $this->assertSame('', $state['message']);
        $this->assertSame('', $state['title']);
        $this->assertFalse($state['show_settings_link']);
    }

    public function testRenderEmptyStateHtml(): void
    {
        $state = AP_Admin_Analytics::emptyStateFor('page', false, false, 30, true);
        $html = AP_Admin_Analytics::renderEmptyState($state, 'ap-analytics-empty--banner');
        $this->assertStringContainsString('ap-analytics-empty', $html);
        $this->assertStringContainsString('ap-analytics-empty--disabled_no_data', $html);
        $this->assertStringContainsString('ap-analytics-empty--banner', $html);
        $this->assertStringContainsString('role="status"', $html);
        $this->assertStringContainsString('data-empty-kind="disabled_no_data"', $html);
        $this->assertStringContainsString('No data yet', $html);
        $this->assertStringContainsString('#ap-analytics-settings', $html);
        $this->assertStringContainsString('Open Analytics settings', $html);
        // Escaped output — no raw angle brackets from user content (message is fixed).
        $this->assertStringNotContainsString('<script', $html);

        $blank = AP_Admin_Analytics::renderEmptyState(['message' => '']);
        $this->assertSame('', $blank);
    }

    public function testScreenCapabilitiesMapIncludesAnalytics(): void
    {
        $map = AP_Admin::screenCapabilities();
        $this->assertArrayHasKey('analytics.php', $map);
        $this->assertSame('manage_options', $map['analytics.php']);
        $this->assertSame('manage_options', AP_Admin_Analytics::CAPABILITY);
    }

    public function testMenuItemPresent(): void
    {
        $items = AP_Admin::menuItems('analytics');
        $byId = [];
        foreach ($items as $item) {
            $byId[$item['id']] = $item;
        }
        $this->assertArrayHasKey('analytics', $byId);
        $this->assertSame('Analytics', $byId['analytics']['label']);
        $this->assertSame('tools', $byId['analytics']['section']);
        $this->assertSame('manage_options', $byId['analytics']['cap']);
        $this->assertTrue($byId['analytics']['active']);
        $this->assertStringContainsString('analytics.php', $byId['analytics']['url']);
    }

    public function testBootstrapLoadsAdminAnalytics(): void
    {
        $src = (string) file_get_contents($this->root . '/ap-admin/admin-bootstrap.php');
        $this->assertStringContainsString('class-ap-admin-analytics.php', $src);
    }

    public function testSanitizeDays(): void
    {
        $this->assertSame(30, AP_Admin_Analytics::sanitizeDays(null));
        $this->assertSame(30, AP_Admin_Analytics::sanitizeDays(''));
        $this->assertSame(30, AP_Admin_Analytics::sanitizeDays(15));
        $this->assertSame(7, AP_Admin_Analytics::sanitizeDays(7));
        $this->assertSame(14, AP_Admin_Analytics::sanitizeDays('14'));
        $this->assertSame(30, AP_Admin_Analytics::sanitizeDays(30));
        $this->assertSame(90, AP_Admin_Analytics::sanitizeDays(90));
    }

    public function testTruncateLabel(): void
    {
        $this->assertSame('—', AP_Admin_Analytics::truncateLabel(''));
        $this->assertSame('/about', AP_Admin_Analytics::truncateLabel('/about'));
        $long = str_repeat('a', 80);
        $out = AP_Admin_Analytics::truncateLabel($long, 20);
        $charLen = function_exists('mb_strlen')
            ? mb_strlen($out, 'UTF-8')
            : strlen($out);
        $this->assertLessThanOrEqual(20, $charLen);
        $this->assertStringEndsWith('…', $out);
    }

    public function testMaxDailyHits(): void
    {
        $this->assertSame(0, AP_Admin_Analytics::maxDailyHits([]));
        $this->assertSame(12, AP_Admin_Analytics::maxDailyHits([
            ['day' => '2026-08-01', 'hits' => 3],
            ['day' => '2026-08-02', 'hits' => 12],
            ['day' => '2026-08-03', 'hits' => 0],
        ]));
    }

    public function testGetReportEmpty(): void
    {
        AP_Options::update(AP_Analytics::OPTION_ENABLED, '0', $this->db);
        $report = AP_Admin_Analytics::getReport($this->db, [
            'days' => 7,
            'now' => strtotime('2026-08-05 15:00:00'),
        ]);

        $this->assertFalse($report['enabled']);
        $this->assertSame(7, $report['days']);
        $this->assertFalse($report['has_hits']);
        $this->assertSame(0, $report['summary']['today']);
        $this->assertSame(0, $report['summary']['last_7_days']);
        $this->assertSame(0, $report['summary']['last_30_days']);
        $this->assertSame([], $report['top_paths']);
        $this->assertSame([], $report['top_referrers']);
        $this->assertCount(7, $report['daily']);
    }

    public function testGetReportWithHits(): void
    {
        AP_Options::update(AP_Analytics::OPTION_ENABLED, '1', $this->db);
        AP_Options::update(AP_Analytics::OPTION_RETENTION_DAYS, '60', $this->db);

        $now = strtotime('2026-08-05 15:00:00');
        $this->insertHit('2026-08-05 10:00:00', '/hello', [
            'referrer' => 'https://example.com/',
        ]);
        $this->insertHit('2026-08-05 11:00:00', '/hello', [
            'referrer' => 'https://example.com/',
        ]);
        $this->insertHit('2026-08-04 09:00:00', '/about', [
            'referrer' => 'https://news.test/story',
        ]);
        $this->insertHit('2026-08-01 08:00:00', '/old');
        // Admin-flagged hit excluded from reports by default.
        $this->insertHit('2026-08-05 12:00:00', '/secret', ['is_admin' => 1]);

        $report = AP_Admin_Analytics::getReport($this->db, [
            'days' => 7,
            'now' => $now,
            'limit' => 5,
        ]);

        $this->assertTrue($report['enabled']);
        $this->assertSame(60, $report['retention_days']);
        $this->assertTrue($report['has_hits']);
        $this->assertSame(2, $report['summary']['today']);
        $this->assertSame(4, $report['summary']['last_7_days']);
        $this->assertSame(4, $report['summary']['last_30_days']);

        $this->assertNotEmpty($report['top_paths']);
        $this->assertSame('/hello', $report['top_paths'][0]['path']);
        $this->assertSame(2, $report['top_paths'][0]['hits']);

        $this->assertNotEmpty($report['top_referrers']);
        $this->assertSame('https://example.com/', $report['top_referrers'][0]['referrer']);
        $this->assertSame(2, $report['top_referrers'][0]['hits']);

        $this->assertCount(7, $report['daily']);
        $byDay = [];
        foreach ($report['daily'] as $row) {
            $byDay[$row['day']] = $row['hits'];
        }
        $this->assertSame(2, $byDay['2026-08-05'] ?? 0);
        $this->assertSame(1, $byDay['2026-08-04'] ?? 0);
        $this->assertSame(1, $byDay['2026-08-01'] ?? 0);
        $this->assertSame(0, $byDay['2026-08-03'] ?? -1);
    }

    public function testAdminCanManageOptionsSubscriberCannot(): void
    {
        $this->assertTrue(AP_Roles::userCan($this->adminId, 'manage_options', null, $this->db));
        $this->assertFalse(AP_Roles::userCan($this->subscriberId, 'manage_options', null, $this->db));
    }

    public function testCssHasAnalyticsRules(): void
    {
        $css = (string) file_get_contents($this->root . '/ap-admin/css/admin.css');
        foreach (
            [
                'ap-analytics-stat-list',
                'ap-analytics-grid',
                'ap-analytics-days-tabs',
                'ap-analytics-bar',
                'ap-analytics-table',
                'ap-analytics-settings',
                'ap-analytics-empty',
                'ap-analytics-empty-banner',
                'ap-analytics-summary-hint',
            ] as $needle
        ) {
            $this->assertStringContainsString($needle, $css, "CSS missing {$needle}");
        }
    }

    public function testIsSettingsPost(): void
    {
        $this->assertFalse(AP_Admin_Analytics::isSettingsPost([], ['REQUEST_METHOD' => 'GET']));
        $this->assertFalse(AP_Admin_Analytics::isSettingsPost([], ['REQUEST_METHOD' => 'POST']));
        $this->assertTrue(AP_Admin_Analytics::isSettingsPost(
            [AP_Admin_Analytics::SETTINGS_SUBMIT => '1'],
            ['REQUEST_METHOD' => 'POST']
        ));
        $this->assertTrue(AP_Admin_Analytics::isSettingsPost(
            ['ap_settings_submit' => '1'],
            ['REQUEST_METHOD' => 'POST']
        ));
    }

    public function testSaveSettingsFromPostEnablesAndSetsRetention(): void
    {
        AP_Options::update(AP_Analytics::OPTION_ENABLED, '0', $this->db);
        AP_Options::update(AP_Analytics::OPTION_RETENTION_DAYS, '90', $this->db);

        $nonce = ap_create_nonce(AP_Admin_Analytics::NONCE_ACTION, $this->adminId);
        $result = AP_Admin_Analytics::saveSettingsFromPost([
            '_ap_nonce' => $nonce,
            AP_Admin_Analytics::SETTINGS_SUBMIT => '1',
            'analytics_enabled' => '1',
            'analytics_retention_days' => '45',
        ], $this->adminId, $this->db);

        $this->assertTrue($result['ok']);
        $this->assertSame('analytics_saved', $result['message_key']);
        $this->assertTrue(AP_Analytics::isEnabled($this->db));
        $this->assertSame(45, AP_Analytics::getRetentionDays($this->db));
        $this->assertTrue($result['enabled'] ?? false);
        $this->assertSame(45, $result['retention_days'] ?? 0);
    }

    public function testSaveSettingsFromPostDisablesWhenCheckboxAbsent(): void
    {
        AP_Options::update(AP_Analytics::OPTION_ENABLED, '1', $this->db);
        AP_Options::update(AP_Analytics::OPTION_RETENTION_DAYS, '30', $this->db);

        $nonce = ap_create_nonce(AP_Admin_Analytics::NONCE_ACTION, $this->adminId);
        // No analytics_enabled key → off (unchecked checkbox).
        $result = AP_Admin_Analytics::saveSettingsFromPost([
            '_ap_nonce' => $nonce,
            AP_Admin_Analytics::SETTINGS_SUBMIT => '1',
            'analytics_retention_days' => '60',
        ], $this->adminId, $this->db);

        $this->assertTrue($result['ok']);
        $this->assertFalse(AP_Analytics::isEnabled($this->db));
        $this->assertSame(60, AP_Analytics::getRetentionDays($this->db));
    }

    public function testSaveSettingsFromPostRejectsBadNonce(): void
    {
        AP_Options::update(AP_Analytics::OPTION_ENABLED, '0', $this->db);

        $result = AP_Admin_Analytics::saveSettingsFromPost([
            '_ap_nonce' => 'not-a-valid-nonce',
            AP_Admin_Analytics::SETTINGS_SUBMIT => '1',
            'analytics_enabled' => '1',
            'analytics_retention_days' => '30',
        ], $this->adminId, $this->db);

        $this->assertFalse($result['ok']);
        $this->assertSame('nonce', $result['message_key']);
        $this->assertFalse(AP_Analytics::isEnabled($this->db));
    }

    public function testSaveSettingsFromPostRejectsSubscriber(): void
    {
        AP_Options::update(AP_Analytics::OPTION_ENABLED, '0', $this->db);

        $nonce = ap_create_nonce(AP_Admin_Analytics::NONCE_ACTION, $this->subscriberId);
        $result = AP_Admin_Analytics::saveSettingsFromPost([
            '_ap_nonce' => $nonce,
            AP_Admin_Analytics::SETTINGS_SUBMIT => '1',
            'analytics_enabled' => '1',
            'analytics_retention_days' => '30',
        ], $this->subscriberId, $this->db);

        $this->assertFalse($result['ok']);
        $this->assertFalse(AP_Analytics::isEnabled($this->db));
    }

    public function testSaveSettingsClampsInvalidRetention(): void
    {
        $nonce = ap_create_nonce(AP_Admin_Analytics::NONCE_ACTION, $this->adminId);
        $result = AP_Admin_Analytics::saveSettingsFromPost([
            '_ap_nonce' => $nonce,
            AP_Admin_Analytics::SETTINGS_SUBMIT => '1',
            'analytics_enabled' => '1',
            'analytics_retention_days' => '0',
        ], $this->adminId, $this->db);

        $this->assertTrue($result['ok']);
        // 0 is invalid → default 90 via sanitizeRetentionDays.
        $this->assertSame(90, AP_Analytics::getRetentionDays($this->db));
    }

    public function testConsumeQueryNoticeIncludesAnalyticsSaved(): void
    {
        $_GET['message'] = 'analytics_saved';
        AP_Admin::clearNotices();
        AP_Admin::consumeQueryNotice();
        $notices = AP_Admin::getNotices();
        $this->assertNotEmpty($notices);
        $found = false;
        foreach ($notices as $notice) {
            if (str_contains((string) ($notice['message'] ?? ''), 'Analytics settings saved')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Expected analytics_saved flash notice');
        unset($_GET['message']);
        AP_Admin::clearNotices();
    }

    /**
     * SPEC acceptance via ACP helpers: enable → record public hits → report
     * shows counts → disable → no new writes; history still readable.
     */
    public function testAcpAcceptanceEnableRecordReportDisable(): void
    {
        AP_Options::update(AP_Analytics::OPTION_ENABLED, '0', $this->db);
        AP_Options::update(AP_Analytics::OPTION_RETENTION_DAYS, '90', $this->db);

        $empty = AP_Admin_Analytics::getReport($this->db, [
            'days' => 7,
            'now' => strtotime('2026-08-05 15:00:00'),
        ]);
        $this->assertFalse($empty['enabled']);
        $this->assertFalse($empty['has_hits']);
        $this->assertSame(0, $empty['summary']['today']);

        // Disabled → recorder must not write.
        $skipped = AP_Analytics::recordHit([
            'path' => '/before-enable',
            'status_code' => 200,
            'hit_time' => '2026-08-05 09:00:00',
            'ua_class' => 'browser',
        ], $this->db);
        $this->assertSame(0, $skipped);

        // Admin saves settings (enable + retention).
        $nonce = ap_create_nonce(AP_Admin_Analytics::NONCE_ACTION, $this->adminId);
        $saved = AP_Admin_Analytics::saveSettingsFromPost([
            '_ap_nonce' => $nonce,
            AP_Admin_Analytics::SETTINGS_SUBMIT => '1',
            'analytics_enabled' => '1',
            'analytics_retention_days' => '90',
        ], $this->adminId, $this->db);
        $this->assertTrue($saved['ok']);
        $this->assertTrue(AP_Analytics::isEnabled($this->db));

        $id1 = AP_Analytics::recordHit([
            'path' => '/hello-world',
            'status_code' => 200,
            'hit_time' => '2026-08-05 10:00:00',
            'referrer' => 'https://search.example/',
            'ua_class' => 'browser',
        ], $this->db);
        $id2 = AP_Analytics::recordHit([
            'path' => '/hello-world',
            'status_code' => 200,
            'hit_time' => '2026-08-05 11:00:00',
            'referrer' => 'https://search.example/',
            'ua_class' => 'browser',
        ], $this->db);
        $id3 = AP_Analytics::recordHit([
            'path' => '/about',
            'status_code' => 200,
            'hit_time' => '2026-08-04 12:00:00',
            'ua_class' => 'browser',
        ], $this->db);
        $this->assertGreaterThan(0, $id1);
        $this->assertGreaterThan(0, $id2);
        $this->assertGreaterThan(0, $id3);

        $report = AP_Admin_Analytics::getReport($this->db, [
            'days' => 7,
            'now' => strtotime('2026-08-05 15:00:00'),
            'limit' => 10,
        ]);
        $this->assertTrue($report['enabled']);
        $this->assertTrue($report['has_hits']);
        $this->assertSame(2, $report['summary']['today']);
        $this->assertSame(3, $report['summary']['last_7_days']);
        $this->assertSame('/hello-world', $report['top_paths'][0]['path']);
        $this->assertSame(2, $report['top_paths'][0]['hits']);
        $this->assertSame('https://search.example/', $report['top_referrers'][0]['referrer']);

        $emptyKind = AP_Admin_Analytics::emptyStateKind($report['enabled'], $report['has_hits']);
        $this->assertSame('has_data', $emptyKind);

        // Disable via settings — no further hits; history remains in report.
        $nonce2 = ap_create_nonce(AP_Admin_Analytics::NONCE_ACTION, $this->adminId);
        $disabled = AP_Admin_Analytics::saveSettingsFromPost([
            '_ap_nonce' => $nonce2,
            AP_Admin_Analytics::SETTINGS_SUBMIT => '1',
            'analytics_retention_days' => '90',
        ], $this->adminId, $this->db);
        $this->assertTrue($disabled['ok']);
        $this->assertFalse(AP_Analytics::isEnabled($this->db));

        $noWrite = AP_Analytics::recordHit([
            'path' => '/after-disable',
            'status_code' => 200,
            'hit_time' => '2026-08-05 16:00:00',
        ], $this->db);
        $this->assertSame(0, $noWrite);

        $history = AP_Admin_Analytics::getReport($this->db, [
            'days' => 7,
            'now' => strtotime('2026-08-05 17:00:00'),
        ]);
        $this->assertFalse($history['enabled']);
        $this->assertTrue($history['has_hits']);
        $this->assertSame(2, $history['summary']['today']);
        $this->assertSame(3, $history['summary']['last_7_days']);
        $this->assertSame(
            'disabled_with_history',
            AP_Admin_Analytics::emptyStateKind($history['enabled'], $history['has_hits'])
        );
    }

    public function testGetReportDaysWindowFiltersTopLists(): void
    {
        AP_Options::update(AP_Analytics::OPTION_ENABLED, '1', $this->db);
        $now = strtotime('2026-08-05 15:00:00');
        // Inside 7-day window.
        $this->insertHit('2026-08-04 10:00:00', '/recent');
        // Outside 7 days but inside 30 days.
        $this->insertHit('2026-07-20 10:00:00', '/older');

        $week = AP_Admin_Analytics::getReport($this->db, ['days' => 7, 'now' => $now]);
        $month = AP_Admin_Analytics::getReport($this->db, ['days' => 30, 'now' => $now]);

        $this->assertSame(7, $week['days']);
        $this->assertSame(30, $month['days']);
        $this->assertCount(1, $week['top_paths']);
        $this->assertSame('/recent', $week['top_paths'][0]['path']);
        $this->assertCount(7, $week['daily']);

        $paths30 = array_column($month['top_paths'], 'path');
        $this->assertContains('/recent', $paths30);
        $this->assertContains('/older', $paths30);
        $this->assertCount(30, $month['daily']);
    }

    public function testSaveSettingsFromPostSubscriberMessageKey(): void
    {
        $nonce = ap_create_nonce(AP_Admin_Analytics::NONCE_ACTION, $this->subscriberId);
        $result = AP_Admin_Analytics::saveSettingsFromPost([
            '_ap_nonce' => $nonce,
            AP_Admin_Analytics::SETTINGS_SUBMIT => '1',
            'analytics_enabled' => '1',
            'analytics_retention_days' => '30',
        ], $this->subscriberId, $this->db);

        $this->assertFalse($result['ok']);
        $this->assertSame('error', $result['message_key']);
        $this->assertStringContainsString('permission', $result['error']);
    }
}
