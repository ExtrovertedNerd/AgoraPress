<?php

/**
 * Tests for shipped migration 0013 — topic_subscriptions.
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
final class TopicSubscriptionsMigrationTest extends TestCase
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
        $path = AP_Migrator::defaultMigrationsPath() . '/0013_topic_subscriptions.php';
        $this->assertFileIsReadable($path);
        $src = (string) file_get_contents($path);
        $this->assertStringContainsString('topic_subscriptions', $src);
        $this->assertStringContainsString('user_id', $src);
        $this->assertStringContainsString('topic_id', $src);
        $this->assertStringContainsString('created_at', $src);
        $this->assertStringContainsString('IF NOT EXISTS', $src);
        $this->assertStringNotContainsString("table('topic_track')", $src);
        $this->assertStringNotContainsString('table("topic_track")', $src);
        $this->assertGreaterThanOrEqual(13, (int) AP_DB_VERSION);
        $this->assertGreaterThanOrEqual(13, AP_Migrator::codeTargetVersion());
    }

    public function testBaseTablesIncludeSubscriptionsAndKeepUnreadTables(): void
    {
        $forum = ap_forum_base_tables();
        $this->assertContains('topic_subscriptions', $forum);
        $this->assertContains('topic_track', $forum);
        $this->assertContains('forum_track', $forum);
        $all = ap_all_base_tables();
        $this->assertContains('topic_subscriptions', $all);
        $this->assertContains('topic_track', $all);
    }

    public function testMigrateFromSchema12CreatesSubscriptionsTable(): void
    {
        $applied12 = $this->migrator->migrate(12);
        $this->assertGreaterThanOrEqual(12, count($applied12));
        $this->assertSame(12, $this->migrator->getCurrentVersion());
        $this->assertNull($this->sqliteTableName('ap_topic_subscriptions'));
        $this->assertSame('ap_topic_track', $this->sqliteTableName('ap_topic_track'));
        $this->assertSame('ap_forum_track', $this->sqliteTableName('ap_forum_track'));

        $applied13 = $this->migrator->migrate(13);
        $this->assertCount(1, $applied13);
        $this->assertSame(13, $applied13[0]['version']);
        $this->assertStringContainsString(
            'subscription',
            strtolower($applied13[0]['description'])
        );
        $this->assertSame(13, $this->migrator->getCurrentVersion());
        $this->assertFalse($this->migrator->needsMigration());
        $this->assertSame([], $this->migrator->migrate());

        $this->assertSame(
            'ap_topic_subscriptions',
            $this->sqliteTableName('ap_topic_subscriptions')
        );
        $this->assertSame('ap_topic_subscriptions', $this->db->topic_subscriptions);
        $this->assertSame('ap_topic_track', $this->db->topic_track);
        $this->assertSame('ap_forum_track', $this->db->forum_track);

        $ddl = (string) $this->db->getVar(
            "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?",
            ['ap_topic_subscriptions']
        );
        $this->assertStringContainsString('user_id', $ddl);
        $this->assertStringContainsString('topic_id', $ddl);
        $this->assertStringContainsString('created_at', $ddl);
        $this->assertStringContainsString('PRIMARY KEY', $ddl);

        $topicIdIndex = $this->db->getVar(
            "SELECT name FROM sqlite_master WHERE type = 'index'"
            . " AND tbl_name = ? AND sql LIKE ?",
            ['ap_topic_subscriptions', '%topic_id%']
        );
        $this->assertNotNull($topicIdIndex);
        $this->assertStringContainsString('topic_id', (string) $topicIdIndex);
    }

    public function testUniqueConstraintAndAddRemovePair(): void
    {
        $this->migrator->migrate();

        $this->assertSame(1, $this->db->insert('topic_subscriptions', [
            'user_id' => 7,
            'topic_id' => 42,
            'created_at' => '2026-09-13 12:00:00',
        ]));

        $row = $this->db->getRow(
            'SELECT user_id, topic_id, created_at FROM '
            . $this->db->quoteIdentifier($this->db->topic_subscriptions)
            . ' WHERE user_id = ? AND topic_id = ?',
            [7, 42]
        );
        $this->assertNotNull($row);
        $this->assertSame(7, (int) $row->user_id);
        $this->assertSame(42, (int) $row->topic_id);
        $this->assertSame('2026-09-13 12:00:00', (string) $row->created_at);

        $dup = $this->db->insert('topic_subscriptions', [
            'user_id' => 7,
            'topic_id' => 42,
            'created_at' => '2026-09-13 12:05:00',
        ]);
        $this->assertFalse($dup);

        $this->assertSame(1, $this->db->insert('topic_subscriptions', [
            'user_id' => 7,
            'topic_id' => 43,
            'created_at' => '2026-09-13 12:06:00',
        ]));
        $this->assertSame(1, $this->db->insert('topic_subscriptions', [
            'user_id' => 8,
            'topic_id' => 42,
            'created_at' => '2026-09-13 12:07:00',
        ]));

        $this->assertSame(1, $this->db->delete('topic_subscriptions', [
            'user_id' => 7,
            'topic_id' => 42,
        ]));
        $gone = $this->db->getVar(
            'SELECT user_id FROM '
            . $this->db->quoteIdentifier($this->db->topic_subscriptions)
            . ' WHERE user_id = ? AND topic_id = ?',
            [7, 42]
        );
        $this->assertNull($gone);

        $remaining = $this->db->getVar(
            'SELECT COUNT(*) FROM '
            . $this->db->quoteIdentifier($this->db->topic_subscriptions)
        );
        $this->assertSame(2, (int) $remaining);
    }

    public function testUpIsIdempotentWhenTableAlreadyExists(): void
    {
        $this->migrator->migrate();
        $this->assertSame(1, $this->db->insert('topic_subscriptions', [
            'user_id' => 1,
            'topic_id' => 9,
            'created_at' => '2026-09-13 08:00:00',
        ]));

        $path = AP_Migrator::defaultMigrationsPath() . '/0013_topic_subscriptions.php';
        $migration = require $path;
        $this->assertInstanceOf(\AP_Migration::class, $migration);
        $migration->up($this->db);

        $this->assertSame(
            'ap_topic_subscriptions',
            $this->sqliteTableName('ap_topic_subscriptions')
        );
        $kept = $this->db->getVar(
            'SELECT topic_id FROM '
            . $this->db->quoteIdentifier($this->db->topic_subscriptions)
            . ' WHERE user_id = ? AND topic_id = ?',
            [1, 9]
        );
        $this->assertSame(9, (int) $kept);
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

        $this->assertSame('site_topic_subscriptions', $db->topic_subscriptions);
        $this->assertSame('site_topic_track', $db->topic_track);
        $name = $db->getVar(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?",
            ['site_topic_subscriptions']
        );
        $this->assertSame('site_topic_subscriptions', $name);

        $this->assertSame(1, $db->insert('topic_subscriptions', [
            'user_id' => 3,
            'topic_id' => 11,
            'created_at' => '2026-09-13 09:00:00',
        ]));
    }

    private function sqliteTableName(string $table): ?string
    {
        $name = $this->db->getVar(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?",
            [$table]
        );

        return $name === null || $name === '' ? null : (string) $name;
    }
}
