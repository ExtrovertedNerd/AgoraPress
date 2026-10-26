<?php

/**
 * Tests for shipped migration 0014 — activity_log.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Database;

use AP_Activity_Log;
use AP_DB;
use AP_Migrator;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_Migrator::class)]
#[CoversClass(AP_Activity_Log::class)]
final class ActivityLogMigrationTest extends TestCase
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
        require_once $this->root . '/ap-includes/class-ap-rate-limit.php';
        require_once $this->root . '/ap-includes/class-ap-activity-log.php';

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

    protected function tearDown(): void
    {
        unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_X_REAL_IP']);
    }

    public function testMigrationFileExistsAndVersionMatchesConstant(): void
    {
        $path = AP_Migrator::defaultMigrationsPath() . '/0014_activity_log.php';
        $this->assertFileIsReadable($path);
        $src = (string) file_get_contents($path);
        $this->assertStringContainsString('activity_log', $src);
        $this->assertStringContainsString('actor_id', $src);
        $this->assertStringContainsString('target_id', $src);
        $this->assertStringContainsString('field_name', $src);
        $this->assertStringContainsString('created_at', $src);
        $this->assertStringContainsString('ip', $src);
        $this->assertStringContainsString('IF NOT EXISTS', $src);
        $this->assertStringNotContainsString('user_pass', $src);
        $this->assertSame(14, (int) AP_DB_VERSION);
        $this->assertSame(14, AP_Migrator::codeTargetVersion());
    }

    public function testBaseTablesIncludeActivityLogOnCoreNotForums(): void
    {
        $core = ap_core_base_tables();
        $this->assertContains('activity_log', $core);
        $this->assertNotContains('activity_log', ap_forum_base_tables());
        $this->assertContains('activity_log', ap_all_base_tables());
    }

    public function testMigrateFromSchema13CreatesActivityLog(): void
    {
        $applied13 = $this->migrator->migrate(13);
        $this->assertGreaterThanOrEqual(13, count($applied13));
        $this->assertSame(13, $this->migrator->getCurrentVersion());
        $this->assertNull($this->sqliteTableName('ap_activity_log'));
        $this->assertTrue($this->migrator->needsMigration());

        $applied14 = $this->migrator->migrate();
        $this->assertCount(1, $applied14);
        $this->assertSame(14, $applied14[0]['version']);
        $this->assertStringContainsString('activity', strtolower($applied14[0]['description']));
        $this->assertSame(14, $this->migrator->getCurrentVersion());
        $this->assertSame((int) AP_DB_VERSION, $this->migrator->getCurrentVersion());
        $this->assertFalse($this->migrator->needsMigration());
        $this->assertSame([], $this->migrator->migrate());

        $this->assertSame('ap_activity_log', $this->sqliteTableName('ap_activity_log'));
        $this->assertSame('ap_activity_log', $this->db->activity_log);

        $ddl = (string) $this->db->getVar(
            "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?",
            ['ap_activity_log']
        );
        $this->assertStringContainsString('actor_id', $ddl);
        $this->assertStringContainsString('target_id', $ddl);
        $this->assertStringContainsString('field_name', $ddl);
        $this->assertStringContainsString('created_at', $ddl);
        $this->assertStringContainsString('ip', $ddl);
        $this->assertStringNotContainsString('password', strtolower($ddl));
        $this->assertStringNotContainsString('user_pass', strtolower($ddl));
        $this->assertStringNotContainsString('user_email', strtolower($ddl));
    }

    public function testUpIsIdempotentWhenTableAlreadyExists(): void
    {
        $this->migrator->migrate();
        $this->assertTrue(AP_Activity_Log::record(
            4,
            9,
            AP_Activity_Log::FIELD_EMAIL,
            $this->db,
            '203.0.113.9'
        ));

        $path = AP_Migrator::defaultMigrationsPath() . '/0014_activity_log.php';
        $migration = require $path;
        $this->assertInstanceOf(\AP_Migration::class, $migration);
        $migration->up($this->db);

        $rows = AP_Activity_Log::forTarget(9, $this->db);
        $this->assertCount(1, $rows);
        $this->assertSame(AP_Activity_Log::FIELD_EMAIL, (string) $rows[0]->field_name);
    }

    public function testRecordStoresFieldNameActorTargetTimeAndIpNeverASecret(): void
    {
        $this->migrator->migrate();
        $secret = 'reset-password-1';
        $address = 'member-new@example.test';

        $_SERVER['REMOTE_ADDR'] = '203.0.113.50';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.20';

        $this->assertFalse(AP_Activity_Log::record(0, 2, AP_Activity_Log::FIELD_PASSWORD, $this->db));
        $this->assertFalse(AP_Activity_Log::record(2, 2, AP_Activity_Log::FIELD_PASSWORD, $this->db));
        $this->assertFalse(AP_Activity_Log::record(1, 2, $secret, $this->db));
        $this->assertFalse(AP_Activity_Log::record(1, 2, $address, $this->db));
        $this->assertFalse(AP_Activity_Log::record(1, 2, 'Password', $this->db));
        $this->assertSame([], AP_Activity_Log::forTarget(2, $this->db));

        $before = time();
        $this->assertTrue(AP_Activity_Log::record(
            1,
            2,
            AP_Activity_Log::FIELD_PASSWORD,
            $this->db,
            $secret
        ));
        $this->assertTrue(AP_Activity_Log::record(
            1,
            2,
            AP_Activity_Log::FIELD_EMAIL,
            $this->db
        ));

        $rows = AP_Activity_Log::forTarget(2, $this->db);
        $this->assertCount(2, $rows);
        $this->assertSame(1, (int) $rows[0]->actor_id);
        $this->assertSame(2, (int) $rows[0]->target_id);
        $this->assertSame(AP_Activity_Log::FIELD_PASSWORD, (string) $rows[0]->field_name);
        $this->assertSame('', (string) $rows[0]->ip);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
            (string) $rows[0]->created_at
        );
        $stamp = strtotime((string) $rows[0]->created_at . ' UTC');
        $this->assertNotFalse($stamp);
        $this->assertLessThanOrEqual(5, abs(time() - $stamp));
        $this->assertGreaterThanOrEqual($before - 1, $stamp);

        $this->assertSame(AP_Activity_Log::FIELD_EMAIL, (string) $rows[1]->field_name);
        $this->assertSame('203.0.113.50', (string) $rows[1]->ip);
        $this->assertNotSame('198.51.100.20', (string) $rows[1]->ip);

        $encoded = json_encode($rows);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString($secret, $encoded);
        $this->assertStringNotContainsString($address, $encoded);

        $this->assertTrue(AP_Activity_Log::record(
            3,
            8,
            AP_Activity_Log::FIELD_PASSWORD,
            $this->db,
            '2001:db8::1'
        ));
        $ipv6 = AP_Activity_Log::forTarget(8, $this->db);
        $this->assertCount(1, $ipv6);
        $this->assertSame('2001:db8::1', (string) $ipv6[0]->ip);
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

        $this->assertSame('site_activity_log', $db->activity_log);
        $name = $db->getVar(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?",
            ['site_activity_log']
        );
        $this->assertSame('site_activity_log', $name);
        $this->assertTrue(AP_Activity_Log::record(5, 6, AP_Activity_Log::FIELD_PASSWORD, $db, '203.0.113.5'));
        $rows = AP_Activity_Log::forTarget(6, $db);
        $this->assertCount(1, $rows);
        $this->assertSame(5, (int) $rows[0]->actor_id);
    }

    private function sqliteTableName(string $name): ?string
    {
        $found = $this->db->getVar(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?",
            [$name]
        );

        return $found === null || $found === '' ? null : (string) $found;
    }
}
