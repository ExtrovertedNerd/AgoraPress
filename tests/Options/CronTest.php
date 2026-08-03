<?php

/**
 * Tests for Cron API (AP_Cron).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Options;

use AP_Cron;
use AP_DB;
use AP_Migrator;
use AP_Options;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_Cron::class)]
final class CronTest extends TestCase
{
    private string $root;

    private AP_DB $db;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        require_once $this->root . '/ap-includes/hooks.php';
        require_once $this->root . '/ap-includes/class-ap-db.php';
        require_once $this->root . '/ap-includes/class-ap-migrator.php';
        require_once $this->root . '/ap-includes/class-ap-options.php';
        require_once $this->root . '/ap-includes/class-ap-cron.php';
        require_once $this->root . '/ap-includes/functions.php';

        if (function_exists('ap_reset_hooks')) {
            ap_reset_hooks();
        }
        AP_Options::flushCache();
        AP_Cron::reset();

        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');
        (new AP_Migrator($this->db, AP_Migrator::defaultMigrationsPath()))->migrate();
        $GLOBALS['apdb'] = $this->db;
    }

    protected function tearDown(): void
    {
        if (function_exists('ap_reset_hooks')) {
            ap_reset_hooks();
        }
        AP_Options::flushCache();
        AP_Cron::reset();
        unset($GLOBALS['apdb']);
    }

    public function testSchedulesIncludeBuiltins(): void
    {
        $schedules = AP_Cron::schedules();
        $this->assertArrayHasKey('hourly', $schedules);
        $this->assertArrayHasKey('daily', $schedules);
        $this->assertSame(3600, $schedules['hourly']['interval']);
        $this->assertSame(86400, $schedules['daily']['interval']);
    }

    public function testSingleEventRunsOnce(): void
    {
        $log = [];
        ap_add_action('ap_test_cron_once', static function (string $msg) use (&$log): void {
            $log[] = $msg;
        }, 10, 1);

        $ts = time() - 10;
        $this->assertTrue(AP_Cron::scheduleSingle($ts, 'ap_test_cron_once', ['ping'], $this->db));
        $this->assertSame($ts, AP_Cron::nextScheduled('ap_test_cron_once', ['ping'], $this->db));

        // Duplicate schedule rejected.
        $this->assertFalse(AP_Cron::scheduleSingle($ts + 5, 'ap_test_cron_once', ['ping'], $this->db));

        $fired = AP_Cron::runDue($this->db, time());
        $this->assertSame(1, $fired);
        $this->assertSame(['ping'], $log);
        $this->assertFalse(AP_Cron::nextScheduled('ap_test_cron_once', ['ping'], $this->db));

        // Nothing left.
        $this->assertSame(0, AP_Cron::runDue($this->db, time()));
    }

    public function testRecurringReschedules(): void
    {
        $count = 0;
        ap_add_action('ap_test_cron_hourly', static function () use (&$count): void {
            $count++;
        });

        $ts = time() - 5;
        $this->assertTrue(AP_Cron::scheduleEvent($ts, 'hourly', 'ap_test_cron_hourly', [], $this->db));
        $this->assertSame(1, AP_Cron::runDue($this->db, time()));
        $this->assertSame(1, $count);

        $next = AP_Cron::nextScheduled('ap_test_cron_hourly', [], $this->db);
        $this->assertIsInt($next);
        $this->assertGreaterThan(time(), $next);
        $this->assertLessThanOrEqual(time() + 3600 + 5, $next);
    }

    public function testUnscheduleAndClear(): void
    {
        $ts = time() + 100;
        AP_Cron::scheduleSingle($ts, 'ap_clear_me', ['a'], $this->db);
        AP_Cron::scheduleSingle($ts + 50, 'ap_clear_me', ['b'], $this->db);
        AP_Cron::scheduleSingle($ts + 100, 'ap_other', [], $this->db);

        $this->assertTrue(AP_Cron::unschedule($ts, 'ap_clear_me', ['a'], $this->db));
        $this->assertFalse(AP_Cron::nextScheduled('ap_clear_me', ['a'], $this->db));
        $this->assertIsInt(AP_Cron::nextScheduled('ap_clear_me', ['b'], $this->db));

        $removed = AP_Cron::clearHook('ap_clear_me', null, $this->db);
        $this->assertSame(1, $removed);
        $this->assertFalse(AP_Cron::nextScheduled('ap_clear_me', ['b'], $this->db));
        $this->assertIsInt(AP_Cron::nextScheduled('ap_other', [], $this->db));
    }

    public function testSpawnOnlyWhenDue(): void
    {
        $ran = 0;
        ap_add_action('ap_spawn_test', static function () use (&$ran): void {
            $ran++;
        });

        AP_Cron::scheduleSingle(time() + 3600, 'ap_spawn_test', [], $this->db);
        $this->assertSame(0, AP_Cron::spawn($this->db));
        $this->assertSame(0, $ran);

        AP_Cron::clearHook('ap_spawn_test', null, $this->db);
        AP_Cron::scheduleSingle(time() - 1, 'ap_spawn_test', [], $this->db);
        $this->assertSame(1, AP_Cron::spawn($this->db));
        $this->assertSame(1, $ran);
    }

    public function testProceduralHelpers(): void
    {
        $this->assertArrayHasKey('daily', ap_get_cron_schedules());

        $log = [];
        ap_add_action('ap_proc_cron', static function () use (&$log): void {
            $log[] = 1;
        });

        $ts = time() - 1;
        $this->assertTrue(ap_schedule_single_event($ts, 'ap_proc_cron', [], $this->db));
        $this->assertSame($ts, ap_next_scheduled('ap_proc_cron', [], $this->db));
        $this->assertSame(1, ap_cron_run_due($this->db));
        $this->assertSame([1], $log);

        $this->assertTrue(ap_schedule_event(time() + 100, 'daily', 'ap_proc_cron', [], $this->db));
        $this->assertSame(1, ap_clear_scheduled_hook('ap_proc_cron', null, $this->db));
        $this->assertFalse(ap_next_scheduled('ap_proc_cron', [], $this->db));
    }
}
