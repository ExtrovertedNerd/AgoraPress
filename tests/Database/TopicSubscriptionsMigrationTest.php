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
        $this->assertStringContainsString('forum_topic_notify_enabled', $src);
        $this->assertStringContainsString('forum_notify_max_per_minute', $src);
        $this->assertStringNotContainsString('topic_track', $src);
        $this->assertStringNotContainsString('forum_track', $src);
        $this->assertSame(13, (int) AP_DB_VERSION);
        $this->assertSame(13, AP_Migrator::codeTargetVersion());
    }

    public function testDbVersionIs13AndUnreadTrackTablesStayUnreadTracking(): void
    {
        $this->assertSame(13, (int) AP_DB_VERSION);
        $this->assertSame(13, AP_Migrator::codeTargetVersion());

        $applied12 = $this->migrator->migrate(12);
        $this->assertGreaterThanOrEqual(12, count($applied12));
        $this->assertSame(12, $this->migrator->getCurrentVersion());

        $this->assertSame(1, $this->db->insert('topic_track', [
            'user_id' => 11,
            'topic_id' => 22,
            'forum_id' => 33,
            'mark_time' => '2026-09-13 12:00:00',
        ]));
        $this->assertSame(1, $this->db->insert('forum_track', [
            'user_id' => 11,
            'forum_id' => 33,
            'mark_time' => '2026-09-13 12:30:00',
        ]));

        $trackBefore = $this->unreadTrackSchemaSnapshot();
        $this->assertNotSame([], $trackBefore);

        $applied13 = $this->migrator->migrate(13);
        $this->assertCount(1, $applied13);
        $this->assertSame(13, $applied13[0]['version']);
        $this->assertSame(13, $this->migrator->getCurrentVersion());
        $this->assertFalse($this->migrator->needsMigration());

        $this->assertSame($trackBefore, $this->unreadTrackSchemaSnapshot());

        $topicRow = $this->db->getRow(
            'SELECT user_id, topic_id, forum_id, mark_time FROM '
            . $this->db->quoteIdentifier($this->db->topic_track)
            . ' WHERE user_id = ? AND topic_id = ?',
            [11, 22]
        );
        $this->assertNotNull($topicRow);
        $this->assertSame(11, (int) $topicRow->user_id);
        $this->assertSame(22, (int) $topicRow->topic_id);
        $this->assertSame(33, (int) $topicRow->forum_id);
        $this->assertSame('2026-09-13 12:00:00', (string) $topicRow->mark_time);

        $forumRow = $this->db->getRow(
            'SELECT user_id, forum_id, mark_time FROM '
            . $this->db->quoteIdentifier($this->db->forum_track)
            . ' WHERE user_id = ? AND forum_id = ?',
            [11, 33]
        );
        $this->assertNotNull($forumRow);
        $this->assertSame(11, (int) $forumRow->user_id);
        $this->assertSame(33, (int) $forumRow->forum_id);
        $this->assertSame('2026-09-13 12:30:00', (string) $forumRow->mark_time);

        $topicDdl = (string) $this->db->getVar(
            "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?",
            ['ap_topic_track']
        );
        $this->assertStringContainsString('user_id', $topicDdl);
        $this->assertStringContainsString('topic_id', $topicDdl);
        $this->assertStringContainsString('forum_id', $topicDdl);
        $this->assertStringContainsString('mark_time', $topicDdl);

        $forumDdl = (string) $this->db->getVar(
            "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?",
            ['ap_forum_track']
        );
        $this->assertStringContainsString('user_id', $forumDdl);
        $this->assertStringContainsString('forum_id', $forumDdl);
        $this->assertStringContainsString('mark_time', $forumDdl);
        $this->assertStringNotContainsString('created_at', $forumDdl);
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
        $this->assertStringContainsString('PRIMARY KEY (user_id, topic_id)', $ddl);

        $this->assertSame('0', $this->optionValue('forum_topic_notify_enabled'));
        $this->assertSame('4', $this->optionValue('forum_notify_max_per_minute'));

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
        $applied12 = $this->migrator->migrate(12);
        $this->assertGreaterThanOrEqual(12, count($applied12));
        $this->assertSame(12, $this->migrator->getCurrentVersion());
        $this->assertNull($this->sqliteTableName('ap_topic_subscriptions'));

        $applied13 = $this->migrator->migrate(13);
        $this->assertCount(1, $applied13);
        $this->assertSame(13, $applied13[0]['version']);
        $this->assertSame(13, $this->migrator->getCurrentVersion());

        $ddl = (string) $this->db->getVar(
            "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?",
            ['ap_topic_subscriptions']
        );
        $this->assertStringContainsString('PRIMARY KEY (user_id, topic_id)', $ddl);

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
        $err = strtolower((string) $this->db->lastError());
        $this->assertNotSame('', $err);
        $this->assertTrue(
            str_contains($err, 'unique') || str_contains($err, 'constraint'),
            'duplicate insert lastError=' . (string) $this->db->lastError()
        );
        $this->assertSame(
            1,
            (int) $this->db->getVar(
                'SELECT COUNT(*) FROM '
                . $this->db->quoteIdentifier($this->db->topic_subscriptions)
                . ' WHERE user_id = ? AND topic_id = ?',
                [7, 42]
            )
        );

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
        $this->assertSame(
            43,
            (int) $this->db->getVar(
                'SELECT topic_id FROM '
                . $this->db->quoteIdentifier($this->db->topic_subscriptions)
                . ' WHERE user_id = ? AND topic_id = ?',
                [7, 43]
            )
        );
        $this->assertSame(
            8,
            (int) $this->db->getVar(
                'SELECT user_id FROM '
                . $this->db->quoteIdentifier($this->db->topic_subscriptions)
                . ' WHERE user_id = ? AND topic_id = ?',
                [8, 42]
            )
        );
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
        $this->assertSame('0', $this->optionValue('forum_topic_notify_enabled'));
        $this->assertSame('4', $this->optionValue('forum_notify_max_per_minute'));
    }

    public function testMigrateFromSchema12SeedsNotifyOptionDefaultsWithoutOverwrite(): void
    {
        $this->migrator->migrate(12);
        $this->assertNull($this->optionValue('forum_topic_notify_enabled'));
        $this->assertNull($this->optionValue('forum_notify_max_per_minute'));

        $this->migrator->migrate(13);
        $this->assertSame('0', $this->optionValue('forum_topic_notify_enabled'));
        $this->assertSame('4', $this->optionValue('forum_notify_max_per_minute'));

        $this->assertNotFalse($this->db->update(
            'options',
            ['option_value' => '1'],
            ['option_name' => 'forum_topic_notify_enabled']
        ));
        $this->assertNotFalse($this->db->update(
            'options',
            ['option_value' => '9'],
            ['option_name' => 'forum_notify_max_per_minute']
        ));

        $path = AP_Migrator::defaultMigrationsPath() . '/0013_topic_subscriptions.php';
        $migration = require $path;
        $migration->up($this->db);

        $this->assertSame('1', $this->optionValue('forum_topic_notify_enabled'));
        $this->assertSame('9', $this->optionValue('forum_notify_max_per_minute'));
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

    /**
     * @return list<array{name: string, type: string, sql: ?string}>
     */
    private function unreadTrackSchemaSnapshot(): array
    {
        $rows = $this->db->getResults(
            "SELECT name, type, sql FROM sqlite_master"
            . " WHERE tbl_name IN ('ap_topic_track', 'ap_forum_track')"
            . " ORDER BY tbl_name, type, name"
        );

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'name' => (string) $row->name,
                'type' => (string) $row->type,
                'sql' => $row->sql === null ? null : (string) $row->sql,
            ];
        }

        return $out;
    }

    private function sqliteTableName(string $table): ?string
    {
        $name = $this->db->getVar(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?",
            [$table]
        );

        return $name === null || $name === '' ? null : (string) $name;
    }

    private function optionValue(string $name): ?string
    {
        $raw = $this->db->getVar(
            'SELECT option_value FROM ' . $this->db->quoteIdentifier($this->db->table('options'))
            . ' WHERE option_name = ? LIMIT 1',
            [$name]
        );

        if ($raw === null) {
            return null;
        }

        return (string) $raw;
    }
}
