<?php

/**
 * Tests for analytics aggregation helpers (AP_Analytics).
 *
 * Covers countHits, getSummary, getTopPaths, getTopReferrers,
 * getDailyTotals, rollupDaily, and procedural wrappers.
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
final class AnalyticsAggregationTest extends TestCase
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
        AP_Analytics::resetRequestState();
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

    protected function tearDown(): void
    {
        AP_Analytics::resetRequestState();
        AP_Options::flushCache();
        if (function_exists('ap_reset_hooks')) {
            ap_reset_hooks();
        }
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function insertHit(string $hitTime, string $path = '/', array $extra = []): int
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

        return (int) $this->db->lastInsertId();
    }

    public function testCountHitsEmptyAndFilters(): void
    {
        $this->assertSame(0, AP_Analytics::countHits($this->db));

        $this->insertHit('2026-08-05 10:00:00', '/a');
        $this->insertHit('2026-08-05 11:00:00', '/a', ['object_id' => 3]);
        $this->insertHit('2026-08-04 09:00:00', '/b', ['status_code' => 404]);
        $this->insertHit('2026-08-05 12:00:00', '/admin-flag', ['is_admin' => 1]);

        // Default excludes is_admin=1.
        $this->assertSame(3, AP_Analytics::countHits($this->db));
        $this->assertSame(4, AP_Analytics::countHits($this->db, ['exclude_admin' => false]));

        $this->assertSame(2, AP_Analytics::countHits($this->db, ['path' => '/a']));
        $this->assertSame(1, AP_Analytics::countHits($this->db, [
            'path' => '/a',
            'object_id' => 3,
        ]));
        $this->assertSame(1, AP_Analytics::countHits($this->db, ['status_code' => 404]));
        $this->assertSame(2, AP_Analytics::countHits($this->db, ['day' => '2026-08-05']));
        $this->assertSame(1, AP_Analytics::countHits($this->db, [
            'since' => '2026-08-04 00:00:00',
            'until' => '2026-08-05 00:00:00',
        ]));
    }

    public function testGetSummaryBuckets(): void
    {
        $now = strtotime('2026-08-05 15:30:00');
        $this->assertNotFalse($now);

        // Today.
        $this->insertHit('2026-08-05 01:00:00', '/t1');
        $this->insertHit('2026-08-05 14:00:00', '/t2');
        // Within last 7 days but not today.
        $this->insertHit('2026-08-01 12:00:00', '/w');
        // Within last 30 days but outside 7.
        $this->insertHit('2026-07-20 12:00:00', '/m');
        // Older than 30 days.
        $this->insertHit('2026-06-01 12:00:00', '/old');
        // Admin today — excluded from summary.
        $this->insertHit('2026-08-05 10:00:00', '/adm', ['is_admin' => 1]);

        $summary = AP_Analytics::getSummary($this->db, $now);
        $this->assertSame(2, $summary['today']);
        $this->assertSame(3, $summary['last_7_days']); // t1, t2, w
        $this->assertSame(4, $summary['last_30_days']); // + m
    }

    public function testGetTopPaths(): void
    {
        $this->insertHit('2026-08-05 10:00:00', '/popular');
        $this->insertHit('2026-08-05 11:00:00', '/popular');
        $this->insertHit('2026-08-05 12:00:00', '/popular', ['object_id' => 9]);
        $this->insertHit('2026-08-05 13:00:00', '/other');
        $this->insertHit('2026-08-04 10:00:00', '/popular'); // previous day

        $top = AP_Analytics::getTopPaths($this->db, [
            'day' => '2026-08-05',
            'limit' => 10,
        ]);
        $this->assertNotEmpty($top);
        $this->assertSame('/popular', $top[0]['path']);
        $this->assertSame(0, $top[0]['object_id']);
        $this->assertSame(2, $top[0]['hits']);

        $paths = array_column($top, 'path');
        $this->assertContains('/other', $paths);
        $this->assertContains('/popular', $paths);
    }

    public function testGetTopReferrers(): void
    {
        $this->insertHit('2026-08-05 10:00:00', '/a', ['referrer' => 'https://news.example/']);
        $this->insertHit('2026-08-05 11:00:00', '/b', ['referrer' => 'https://news.example/']);
        $this->insertHit('2026-08-05 12:00:00', '/c', ['referrer' => 'https://blog.example/']);
        $this->insertHit('2026-08-05 13:00:00', '/d', ['referrer' => '']); // ignored

        $top = AP_Analytics::getTopReferrers($this->db, ['limit' => 5]);
        $this->assertCount(2, $top);
        $this->assertSame('https://news.example/', $top[0]['referrer']);
        $this->assertSame(2, $top[0]['hits']);
        $this->assertSame('https://blog.example/', $top[1]['referrer']);
        $this->assertSame(1, $top[1]['hits']);
    }

    public function testGetDailyTotalsFillsGaps(): void
    {
        $now = strtotime('2026-08-05 12:00:00');
        $this->assertNotFalse($now);

        $this->insertHit('2026-08-05 10:00:00', '/a');
        $this->insertHit('2026-08-05 11:00:00', '/b');
        $this->insertHit('2026-08-03 10:00:00', '/c');

        $days = AP_Analytics::getDailyTotals($this->db, [
            'days' => 5,
            'now' => $now,
            'fill_gaps' => true,
        ]);

        $this->assertCount(5, $days);
        $this->assertSame('2026-08-01', $days[0]['day']);
        $this->assertSame(0, $days[0]['hits']);
        $this->assertSame('2026-08-03', $days[2]['day']);
        $this->assertSame(1, $days[2]['hits']);
        $this->assertSame('2026-08-05', $days[4]['day']);
        $this->assertSame(2, $days[4]['hits']);

        $sparse = AP_Analytics::getDailyTotals($this->db, [
            'days' => 5,
            'now' => $now,
            'fill_gaps' => false,
        ]);
        $this->assertCount(2, $sparse);
    }

    public function testRollupDailyWritesAndIsIdempotent(): void
    {
        $this->insertHit('2026-08-05 10:00:00', '/hello', ['object_id' => 1]);
        $this->insertHit('2026-08-05 11:00:00', '/hello', ['object_id' => 1]);
        $this->insertHit('2026-08-05 12:00:00', '/about');
        $this->insertHit('2026-08-04 09:00:00', '/hello', ['object_id' => 1]);

        $written = AP_Analytics::rollupDaily($this->db, [
            'day' => '2026-08-05',
        ]);
        $this->assertSame(2, $written);

        $dailyCount = (int) $this->db->getVar(
            'SELECT COUNT(*) FROM ' . $this->db->quoteIdentifier($this->db->analytics_daily)
        );
        $this->assertSame(2, $dailyCount);

        $helloHits = (int) $this->db->getVar(
            'SELECT hits FROM ' . $this->db->quoteIdentifier($this->db->analytics_daily)
            . ' WHERE day = ? AND path = ? AND object_id = ?',
            ['2026-08-05', '/hello', 1]
        );
        $this->assertSame(2, $helloHits);

        // Re-run replaces same day rows (still 2).
        $written2 = AP_Analytics::rollupDaily($this->db, ['day' => '2026-08-05']);
        $this->assertSame(2, $written2);
        $dailyCount2 = (int) $this->db->getVar(
            'SELECT COUNT(*) FROM ' . $this->db->quoteIdentifier($this->db->analytics_daily)
        );
        $this->assertSame(2, $dailyCount2);
    }

    public function testDayBoundsHelper(): void
    {
        $bounds = AP_Analytics::dayBounds('2026-08-05');
        $this->assertSame('2026-08-05 00:00:00', $bounds['since']);
        $this->assertSame('2026-08-06 00:00:00', $bounds['until']);
    }

    public function testAggregationWorksWhenCollectionDisabled(): void
    {
        // Reports must still read historical data when analytics_enabled is off.
        $this->assertFalse(AP_Analytics::isEnabled($this->db));
        $this->insertHit('2026-08-05 10:00:00', '/kept');

        $this->assertSame(1, AP_Analytics::countHits($this->db));
        $summary = AP_Analytics::getSummary($this->db, strtotime('2026-08-05 12:00:00') ?: null);
        $this->assertSame(1, $summary['today']);
    }

    public function testProceduralWrappers(): void
    {
        $this->insertHit('2026-08-05 10:00:00', '/via-helper', [
            'referrer' => 'https://ref.example/',
        ]);
        $this->insertHit('2026-08-05 11:00:00', '/via-helper');

        $this->assertSame(2, ap_analytics_count_hits($this->db, ['day' => '2026-08-05']));

        $now = strtotime('2026-08-05 12:00:00');
        $this->assertNotFalse($now);
        $summary = ap_analytics_summary($this->db, $now);
        $this->assertSame(2, $summary['today']);

        $top = ap_analytics_top_paths($this->db, ['limit' => 5]);
        $this->assertSame('/via-helper', $top[0]['path']);
        $this->assertSame(2, $top[0]['hits']);

        $refs = ap_analytics_top_referrers($this->db, ['limit' => 5]);
        $this->assertSame('https://ref.example/', $refs[0]['referrer']);

        $daily = ap_analytics_daily_totals($this->db, [
            'days' => 1,
            'now' => $now,
        ]);
        $this->assertSame('2026-08-05', $daily[0]['day']);
        $this->assertSame(2, $daily[0]['hits']);

        $n = ap_analytics_rollup_daily($this->db, ['day' => '2026-08-05']);
        $this->assertSame(1, $n);
    }

    public function testRecordDisableAndAggregateTogether(): void
    {
        // Acceptance-style: enable → record → disable → no new hits → aggregate still works.
        AP_Options::update(AP_Analytics::OPTION_ENABLED, '1', $this->db);
        AP_Options::flushCache();
        $this->assertTrue(AP_Analytics::isEnabled($this->db));

        $id = AP_Analytics::recordHit([
            'path' => '/post-one',
            'status_code' => 200,
            'ua_class' => 'browser',
            'hit_time' => '2026-08-05 10:00:00',
            'referrer' => 'https://source.example/',
        ], $this->db);
        $this->assertGreaterThan(0, $id);

        AP_Options::update(AP_Analytics::OPTION_ENABLED, '0', $this->db);
        AP_Options::flushCache();
        $this->assertFalse(AP_Analytics::isEnabled($this->db));

        $skipped = AP_Analytics::recordHit([
            'path' => '/should-not-write',
            'status_code' => 200,
            'hit_time' => '2026-08-05 11:00:00',
        ], $this->db);
        $this->assertSame(0, $skipped);

        $this->assertSame(1, AP_Analytics::countHits($this->db));
        $top = AP_Analytics::getTopPaths($this->db);
        $this->assertSame('/post-one', $top[0]['path']);
    }
}
