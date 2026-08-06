<?php

/**
 * Tests for shipped migration 0010 — analytics_hits + analytics_daily.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Database;

use AP_DB;
use AP_Migrator;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_Migrator::class)]
final class AnalyticsTablesMigrationTest extends TestCase
{
    private string $root;

    private AP_DB $db;

    private AP_Migrator $migrator;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        require_once $this->root . '/ap-includes/version.php';
        require_once $this->root . '/ap-includes/load-config.php';
        require_once $this->root . '/ap-includes/class-ap-db.php';
        require_once $this->root . '/ap-includes/class-ap-migrator.php';

        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');
        $this->migrator = new AP_Migrator(
            $this->db,
            AP_Migrator::defaultMigrationsPath()
        );
    }

    public function testMigrationFileExistsAndVersionMatchesConstant(): void
    {
        $path = AP_Migrator::defaultMigrationsPath() . '/0010_analytics_tables.php';
        $this->assertFileIsReadable($path);
        $this->assertGreaterThanOrEqual(10, (int) AP_DB_VERSION);
        $this->assertGreaterThanOrEqual(10, AP_Migrator::codeTargetVersion());
    }

    public function testBaseTablesIncludeAnalytics(): void
    {
        $core = ap_core_base_tables();
        $this->assertContains('analytics_hits', $core);
        $this->assertContains('analytics_daily', $core);
        $all = ap_all_base_tables();
        $this->assertContains('analytics_hits', $all);
        $this->assertContains('analytics_daily', $all);
    }

    public function testMigrateCreatesAnalyticsTables(): void
    {
        $this->assertTrue($this->migrator->needsMigration());
        $applied = $this->migrator->migrate();
        $this->assertGreaterThanOrEqual(10, count($applied));
        $this->assertSame(1, $applied[0]['version']);
        $this->assertSame(10, $applied[9]['version']);
        $this->assertStringContainsString(
            'analytics',
            strtolower($applied[9]['description'])
        );
        $this->assertSame(10, $this->migrator->getCurrentVersion());
        $this->assertFalse($this->migrator->needsMigration());
        $this->assertSame([], $this->migrator->migrate());

        foreach (['ap_analytics_hits', 'ap_analytics_daily'] as $table) {
            $name = $this->db->getVar(
                "SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?",
                [$table]
            );
            $this->assertSame($table, $name, "Expected table {$table}");
        }

        $this->assertSame('ap_analytics_hits', $this->db->analytics_hits);
        $this->assertSame('ap_analytics_daily', $this->db->analytics_daily);
    }

    public function testHitsAndDailyCrudRoundTrip(): void
    {
        $this->migrator->migrate();

        $this->assertSame(1, $this->db->insert('analytics_hits', [
            'hit_time' => '2026-08-05 10:00:00',
            'path' => '/about',
            'object_id' => 42,
            'status_code' => 200,
            'referrer' => 'https://ref.example/',
            'ua_class' => 'browser',
            'is_admin' => 0,
        ]));
        $hitId = (int) $this->db->lastInsertId();
        $this->assertGreaterThan(0, $hitId);

        $row = $this->db->getRow(
            'SELECT path, object_id, status_code, ua_class, is_admin FROM '
            . $this->db->quoteIdentifier($this->db->analytics_hits)
            . ' WHERE hit_id = ?',
            [$hitId]
        );
        $this->assertNotNull($row);
        $this->assertSame('/about', $row->path);
        $this->assertSame(42, (int) $row->object_id);
        $this->assertSame(200, (int) $row->status_code);
        $this->assertSame('browser', $row->ua_class);
        $this->assertSame(0, (int) $row->is_admin);

        $this->assertSame(1, $this->db->insert('analytics_daily', [
            'day' => '2026-08-05',
            'path' => '/about',
            'object_id' => 42,
            'hits' => 5,
        ]));
        $hits = $this->db->getVar(
            'SELECT hits FROM ' . $this->db->quoteIdentifier($this->db->analytics_daily)
            . ' WHERE day = ? AND path = ? AND object_id = ?',
            ['2026-08-05', '/about', 42]
        );
        $this->assertSame(5, (int) $hits);

        // Upsert-style update of rollup count.
        $this->assertSame(1, $this->db->update(
            'analytics_daily',
            ['hits' => 6],
            ['day' => '2026-08-05', 'path' => '/about', 'object_id' => 42]
        ));
        $hits = $this->db->getVar(
            'SELECT hits FROM ' . $this->db->quoteIdentifier($this->db->analytics_daily)
            . ' WHERE day = ? AND path = ? AND object_id = ?',
            ['2026-08-05', '/about', 42]
        );
        $this->assertSame(6, (int) $hits);

        // Retention-style delete by hit_time.
        $this->assertSame(1, $this->db->delete(
            'analytics_hits',
            ['hit_id' => $hitId]
        ));
        $gone = $this->db->getVar(
            'SELECT hit_id FROM ' . $this->db->quoteIdentifier($this->db->analytics_hits)
            . ' WHERE hit_id = ?',
            [$hitId]
        );
        $this->assertNull($gone);
    }

    public function testCustomPrefixIsHonored(): void
    {
        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $db = AP_DB::fromPdo($pdo, 'sqlite', 'site_');
        $migrator = new AP_Migrator($db, AP_Migrator::defaultMigrationsPath());
        $migrator->migrate();

        $this->assertSame('site_analytics_hits', $db->analytics_hits);
        $this->assertSame('site_analytics_daily', $db->analytics_daily);

        foreach (['site_analytics_hits', 'site_analytics_daily'] as $table) {
            $name = $db->getVar(
                "SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?",
                [$table]
            );
            $this->assertSame($table, $name);
        }
    }
}
