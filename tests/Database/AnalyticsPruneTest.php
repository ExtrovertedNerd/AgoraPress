<?php

/**
 * Tests for analytics retention prune + cron wiring (AP_Analytics).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Database;

use AP_Analytics;
use AP_Cron;
use AP_DB;
use AP_Migrator;
use AP_Options;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_Analytics::class)]
final class AnalyticsPruneTest extends TestCase
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
        require_once $this->root . '/ap-includes/class-ap-cron.php';
        require_once $this->root . '/ap-includes/class-ap-analytics.php';
        require_once $this->root . '/ap-includes/functions.php';

        AP_Options::flushCache();
        AP_Cron::reset();
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

        // Clean cron option for isolation.
        AP_Options::update(AP_Cron::OPTION, ['version' => 2], $this->db);
        AP_Options::flushCache();

        // Cron action resolves DB via $GLOBALS['apdb'] / ap_db().
        $GLOBALS['apdb'] = $this->db;
    }

    protected function tearDown(): void
    {
        AP_Analytics::resetRequestState();
        AP_Cron::reset();
        AP_Options::flushCache();
        if (function_exists('ap_reset_hooks')) {
            ap_reset_hooks();
        }
        unset($GLOBALS['apdb']);
    }

    private function insertHit(string $hitTime, string $path = '/old'): int
    {
        $result = $this->db->insert('analytics_hits', [
            'hit_time' => $hitTime,
            'path' => $path,
            'object_id' => 0,
            'status_code' => 200,
            'referrer' => '',
            'ua_class' => 'browser',
            'is_admin' => 0,
        ]);
        $this->assertNotFalse($result);

        return (int) $this->db->lastInsertId();
    }

    private function insertDaily(string $day, string $path = '/old', int $hits = 1): void
    {
        $result = $this->db->insert('analytics_daily', [
            'day' => $day,
            'path' => $path,
            'object_id' => 0,
            'hits' => $hits,
        ]);
        $this->assertNotFalse($result);
    }

    private function countHits(): int
    {
        return (int) $this->db->getVar(
            'SELECT COUNT(*) FROM ' . $this->db->quoteIdentifier($this->db->analytics_hits)
        );
    }

    private function countDaily(): int
    {
        return (int) $this->db->getVar(
            'SELECT COUNT(*) FROM ' . $this->db->quoteIdentifier($this->db->analytics_daily)
        );
    }

    public function testPruneRemovesOldHitsAndDaily(): void
    {
        // Fixed "now": 2026-08-05 12:00:00 → 90-day cutoff ≈ 2026-05-07 12:00:00
        $now = strtotime('2026-08-05 12:00:00');
        $this->assertNotFalse($now);

        $this->insertHit('2026-04-01 10:00:00', '/ancient');
        $this->insertHit('2026-05-01 10:00:00', '/stale');
        $this->insertHit('2026-07-01 10:00:00', '/recent');
        $this->insertHit('2026-08-05 11:00:00', '/today');

        $this->insertDaily('2026-04-01', '/ancient', 5);
        $this->insertDaily('2026-05-01', '/stale', 3);
        $this->insertDaily('2026-07-01', '/recent', 2);
        $this->insertDaily('2026-08-05', '/today', 1);

        $this->assertSame(4, $this->countHits());
        $this->assertSame(4, $this->countDaily());

        $deleted = AP_Analytics::prune($this->db, [
            'retention_days' => 90,
            'now' => $now,
        ]);

        // Hits: ancient + stale (hit_time < 2026-05-07 12:00:00) → 2
        // Daily: day < 2026-05-07 → 2026-04-01, 2026-05-01 → 2
        $this->assertSame(4, $deleted);
        $this->assertSame(2, $this->countHits());
        $this->assertSame(2, $this->countDaily());

        $paths = $this->db->getCol(
            'SELECT path FROM ' . $this->db->quoteIdentifier($this->db->analytics_hits)
            . ' ORDER BY path'
        );
        $this->assertSame(['/recent', '/today'], $paths);

        $days = $this->db->getCol(
            'SELECT day FROM ' . $this->db->quoteIdentifier($this->db->analytics_daily)
            . ' ORDER BY day'
        );
        $this->assertSame(['2026-07-01', '2026-08-05'], $days);
    }

    public function testPruneUsesSiteRetentionOption(): void
    {
        AP_Options::update(AP_Analytics::OPTION_RETENTION_DAYS, '7', $this->db);
        AP_Options::flushCache();

        $now = strtotime('2026-08-05 12:00:00');
        $this->assertNotFalse($now);

        // 10 days old → past 7-day retention
        $this->insertHit('2026-07-26 12:00:00', '/old');
        // 3 days old → keep
        $this->insertHit('2026-08-02 12:00:00', '/keep');

        $deleted = AP_Analytics::prune($this->db, ['now' => $now]);
        $this->assertSame(1, $deleted);
        $this->assertSame(1, $this->countHits());

        $path = $this->db->getVar(
            'SELECT path FROM ' . $this->db->quoteIdentifier($this->db->analytics_hits)
        );
        $this->assertSame('/keep', $path);
    }

    public function testPruneRunsWhenCollectionDisabled(): void
    {
        // Default analytics_enabled is off — prune must still free storage.
        $this->assertFalse(AP_Analytics::isEnabled($this->db));

        $now = strtotime('2026-08-05 12:00:00');
        $this->assertNotFalse($now);
        $this->insertHit('2020-01-01 00:00:00', '/ancient');

        $deleted = AP_Analytics::prune($this->db, [
            'retention_days' => 30,
            'now' => $now,
        ]);
        $this->assertSame(1, $deleted);
        $this->assertSame(0, $this->countHits());
    }

    public function testPruneSelectiveTables(): void
    {
        $now = strtotime('2026-08-05 12:00:00');
        $this->assertNotFalse($now);
        $this->insertHit('2020-01-01 00:00:00', '/h');
        $this->insertDaily('2020-01-01', '/d', 1);

        $deleted = AP_Analytics::prune($this->db, [
            'retention_days' => 30,
            'now' => $now,
            'prune_hits' => true,
            'prune_daily' => false,
        ]);
        $this->assertSame(1, $deleted);
        $this->assertSame(0, $this->countHits());
        $this->assertSame(1, $this->countDaily());
    }

    public function testPruneNothingWhenAllFresh(): void
    {
        $now = strtotime('2026-08-05 12:00:00');
        $this->assertNotFalse($now);
        $this->insertHit('2026-08-04 12:00:00', '/fresh');
        $this->insertDaily('2026-08-04', '/fresh', 1);

        $deleted = AP_Analytics::prune($this->db, [
            'retention_days' => 90,
            'now' => $now,
        ]);
        $this->assertSame(0, $deleted);
        $this->assertSame(1, $this->countHits());
        $this->assertSame(1, $this->countDaily());
    }

    public function testPruneCutoffHelper(): void
    {
        $now = strtotime('2026-08-05 12:00:00');
        $this->assertNotFalse($now);
        $cut = AP_Analytics::pruneCutoff(90, $now);
        $this->assertSame(90, $cut['retention_days']);
        $this->assertSame('2026-05-07 12:00:00', $cut['cutoff_datetime']);
        $this->assertSame('2026-05-07', $cut['cutoff_day']);
        $this->assertSame($now, $cut['now']);
    }

    public function testPruneFiresAction(): void
    {
        $log = [];
        ap_add_action('ap_analytics_pruned', static function ($deleted, $days, $meta) use (&$log): void {
            $log[] = [(int) $deleted, (int) $days, is_array($meta) ? ($meta['cutoff_day'] ?? '') : ''];
        }, 10, 3);

        $now = strtotime('2026-08-05 12:00:00');
        $this->assertNotFalse($now);
        $this->insertHit('2020-01-01 00:00:00', '/x');

        AP_Analytics::prune($this->db, ['retention_days' => 10, 'now' => $now]);
        $this->assertCount(1, $log);
        $this->assertSame(1, $log[0][0]);
        $this->assertSame(10, $log[0][1]);
        $this->assertSame('2026-07-26', $log[0][2]);
    }

    public function testEnsurePruneScheduledAndRegisterCron(): void
    {
        $this->assertFalse(AP_Cron::nextScheduled(AP_Analytics::CRON_HOOK, [], $this->db));
        $this->assertFalse(AP_Analytics::isCronRegistered());

        $this->assertTrue(AP_Analytics::ensurePruneScheduled($this->db));
        $next = AP_Cron::nextScheduled(AP_Analytics::CRON_HOOK, [], $this->db);
        $this->assertNotFalse($next);
        $this->assertGreaterThan(time(), (int) $next);

        // Second call is a no-op (already scheduled).
        $this->assertFalse(AP_Analytics::ensurePruneScheduled($this->db));

        AP_Analytics::registerCron($this->db);
        $this->assertTrue(AP_Analytics::isCronRegistered());
        // Still only one scheduled event.
        $this->assertSame($next, AP_Cron::nextScheduled(AP_Analytics::CRON_HOOK, [], $this->db));
    }

    public function testCronHookRunsPrune(): void
    {
        $now = strtotime('2026-08-05 12:00:00');
        $this->assertNotFalse($now);
        $this->insertHit('2020-01-01 00:00:00', '/gone');
        $this->insertHit('2026-08-04 00:00:00', '/keep');

        AP_Analytics::registerCron($this->db);
        $this->assertTrue(AP_Analytics::isCronRegistered());

        // Force retention short enough that 2020 hit is removed; pass via filter.
        ap_add_filter('ap_analytics_prune_args', static function (array $args) use ($now): array {
            $args['retention_days'] = 30;
            $args['now'] = $now;

            return $args;
        });

        // Fire the hook the same way AP_Cron does.
        ap_do_action(AP_Analytics::CRON_HOOK);

        $this->assertSame(1, $this->countHits());
        $path = $this->db->getVar(
            'SELECT path FROM ' . $this->db->quoteIdentifier($this->db->analytics_hits)
        );
        $this->assertSame('/keep', $path);
    }

    public function testCronRunDueFiresScheduledPrune(): void
    {
        $now = strtotime('2026-08-05 12:00:00');
        $this->assertNotFalse($now);
        $this->insertHit('2020-06-01 00:00:00', '/stale');

        AP_Options::update(AP_Analytics::OPTION_RETENTION_DAYS, '30', $this->db);
        AP_Options::flushCache();

        // Override now for prune only; schedule due immediately.
        ap_add_filter('ap_analytics_prune_args', static function (array $args) use ($now): array {
            $args['now'] = $now;

            return $args;
        });

        AP_Analytics::registerCron($this->db);
        // Reschedule as already-due.
        AP_Cron::clearHook(AP_Analytics::CRON_HOOK, null, $this->db);
        $this->assertTrue(
            AP_Cron::scheduleEvent(time() - 10, 'daily', AP_Analytics::CRON_HOOK, [], $this->db)
        );

        $fired = AP_Cron::runDue($this->db);
        $this->assertGreaterThanOrEqual(1, $fired);
        $this->assertSame(0, $this->countHits());

        // Recurring: still scheduled for the future.
        $next = AP_Cron::nextScheduled(AP_Analytics::CRON_HOOK, [], $this->db);
        $this->assertNotFalse($next);
        $this->assertGreaterThan(time(), (int) $next);
    }

    public function testProceduralWrappers(): void
    {
        $now = strtotime('2026-08-05 12:00:00');
        $this->assertNotFalse($now);
        $this->insertHit('2020-01-01 00:00:00', '/x');

        $deleted = ap_analytics_prune($this->db, [
            'retention_days' => 7,
            'now' => $now,
        ]);
        $this->assertSame(1, $deleted);

        AP_Cron::clearHook(AP_Analytics::CRON_HOOK, null, $this->db);
        $this->assertTrue(ap_analytics_ensure_prune_scheduled($this->db));
        $this->assertNotFalse(AP_Cron::nextScheduled(AP_Analytics::CRON_HOOK, [], $this->db));
    }

    public function testCronHookConstant(): void
    {
        $this->assertSame('ap_analytics_prune', AP_Analytics::CRON_HOOK);
        $this->assertSame('daily', AP_Analytics::CRON_RECURRENCE);
    }
}
