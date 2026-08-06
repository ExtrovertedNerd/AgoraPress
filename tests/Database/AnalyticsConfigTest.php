<?php

/**
 * Tests for analytics config options (AP_Analytics).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Database;

use AP_Analytics;
use AP_DB;
use AP_Migrator;
use AP_Options;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_Analytics::class)]
final class AnalyticsConfigTest extends TestCase
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
        require_once $this->root . '/ap-includes/class-ap-analytics.php';
        require_once $this->root . '/ap-includes/functions.php';

        AP_Options::flushCache();
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
    }

    public function testOptionConstantsAndDefaults(): void
    {
        $this->assertSame('analytics_enabled', AP_Analytics::OPTION_ENABLED);
        $this->assertSame('analytics_retention_days', AP_Analytics::OPTION_RETENTION_DAYS);
        $this->assertFalse(AP_Analytics::DEFAULT_ENABLED);
        $this->assertSame(90, AP_Analytics::DEFAULT_RETENTION_DAYS);
        $this->assertSame(1, AP_Analytics::MIN_RETENTION_DAYS);
        $this->assertSame(3650, AP_Analytics::MAX_RETENTION_DAYS);

        $map = AP_Analytics::defaultOptionMap();
        $this->assertSame('0', $map[AP_Analytics::OPTION_ENABLED]);
        $this->assertSame('90', $map[AP_Analytics::OPTION_RETENTION_DAYS]);
    }

    public function testDefaultIsDisabledWhenOptionMissing(): void
    {
        // No analytics_* rows seeded — privacy default: off.
        $this->assertFalse(AP_Analytics::isEnabled($this->db));
        $this->assertFalse(ap_analytics_enabled($this->db));
        $this->assertSame(90, AP_Analytics::getRetentionDays($this->db));
        $this->assertSame(90, ap_analytics_retention_days($this->db));
    }

    public function testEnabledWhenOptionIsOne(): void
    {
        AP_Options::update(AP_Analytics::OPTION_ENABLED, '1', $this->db);
        $this->assertTrue(AP_Analytics::isEnabled($this->db));
        $this->assertTrue(ap_analytics_enabled($this->db));

        AP_Options::update(AP_Analytics::OPTION_ENABLED, '0', $this->db);
        $this->assertFalse(AP_Analytics::isEnabled($this->db));
    }

    public function testRetentionDaysClampedAndDefaulted(): void
    {
        $this->assertSame(90, AP_Analytics::sanitizeRetentionDays(null));
        $this->assertSame(90, AP_Analytics::sanitizeRetentionDays(''));
        $this->assertSame(90, AP_Analytics::sanitizeRetentionDays('nope'));
        $this->assertSame(90, AP_Analytics::sanitizeRetentionDays(0));
        $this->assertSame(90, AP_Analytics::sanitizeRetentionDays(-5));
        $this->assertSame(1, AP_Analytics::sanitizeRetentionDays(1));
        $this->assertSame(30, AP_Analytics::sanitizeRetentionDays('30'));
        $this->assertSame(3650, AP_Analytics::sanitizeRetentionDays(99999));

        AP_Options::update(AP_Analytics::OPTION_RETENTION_DAYS, '30', $this->db);
        $this->assertSame(30, AP_Analytics::getRetentionDays($this->db));
        $this->assertSame(30, ap_analytics_retention_days($this->db));

        AP_Options::update(AP_Analytics::OPTION_RETENTION_DAYS, 'bogus', $this->db);
        $this->assertSame(90, AP_Analytics::getRetentionDays($this->db));
    }

    public function testSanitizeEnabled(): void
    {
        $this->assertSame('1', AP_Analytics::sanitizeEnabled(true));
        $this->assertSame('1', AP_Analytics::sanitizeEnabled('1'));
        $this->assertSame('1', AP_Analytics::sanitizeEnabled('on'));
        $this->assertSame('1', AP_Analytics::sanitizeEnabled('yes'));
        $this->assertSame('0', AP_Analytics::sanitizeEnabled(false));
        $this->assertSame('0', AP_Analytics::sanitizeEnabled('0'));
        $this->assertSame('0', AP_Analytics::sanitizeEnabled('off'));
        $this->assertSame('0', AP_Analytics::sanitizeEnabled(null));
    }

    public function testUpdateSettingsPersistsBothKeys(): void
    {
        $this->assertTrue(AP_Analytics::updateSettings([
            'analytics_enabled' => '1',
            'analytics_retention_days' => 45,
        ], $this->db));

        $this->assertTrue(AP_Analytics::isEnabled($this->db));
        $this->assertSame(45, AP_Analytics::getRetentionDays($this->db));
        $this->assertSame('1', (string) AP_Options::get(AP_Analytics::OPTION_ENABLED, 'x', $this->db));
        $this->assertSame('45', (string) AP_Options::get(AP_Analytics::OPTION_RETENTION_DAYS, 'x', $this->db));

        // Short keys also accepted; omitted keys leave prior values.
        $this->assertTrue(AP_Analytics::updateSettings([
            'enabled' => false,
        ], $this->db));
        $this->assertFalse(AP_Analytics::isEnabled($this->db));
        $this->assertSame(45, AP_Analytics::getRetentionDays($this->db));
    }

    public function testFiltersCanOverrideEnabledAndRetention(): void
    {
        AP_Options::update(AP_Analytics::OPTION_ENABLED, '0', $this->db);
        AP_Options::update(AP_Analytics::OPTION_RETENTION_DAYS, '90', $this->db);

        if (function_exists('ap_add_filter')) {
            ap_add_filter('ap_analytics_enabled', static fn (): bool => true);
            ap_add_filter('ap_analytics_retention_days', static fn (): int => 14);
        }

        $this->assertTrue(AP_Analytics::isEnabled($this->db));
        $this->assertSame(14, AP_Analytics::getRetentionDays($this->db));
    }
}
