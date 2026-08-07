<?php

/**
 * Tests for shipped migration 0012 — topic type enum + backfill.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Database;

use AP_DB;
use AP_Forum;
use AP_Migrator;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_Migrator::class)]
final class TopicTypeEnumMigrationTest extends TestCase
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
        require_once $this->root . '/ap-includes/class-ap-forum.php';

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
        $path = AP_Migrator::defaultMigrationsPath() . '/0012_topic_type_enum.php';
        $this->assertFileIsReadable($path);
        $src = (string) file_get_contents($path);
        $this->assertStringContainsString('standard', $src);
        $this->assertStringContainsString('announcement', $src);
        $this->assertStringContainsString('rules', $src);
        $this->assertStringContainsString('normal', $src);
        $this->assertGreaterThanOrEqual(12, (int) AP_DB_VERSION);
        $this->assertGreaterThanOrEqual(12, AP_Migrator::codeTargetVersion());
    }

    public function testMigrateAppliesVersion12(): void
    {
        $applied = $this->migrator->migrate();
        $this->assertGreaterThanOrEqual(12, count($applied));
        $this->assertSame(12, $applied[11]['version']);
        $this->assertStringContainsString(
            'topic type',
            strtolower($applied[11]['description'])
        );
        $this->assertSame(12, $this->migrator->getCurrentVersion());
        $this->assertFalse($this->migrator->needsMigration());
        $this->assertSame([], $this->migrator->migrate());
    }

    public function testBackfillRemapsLegacyTopicTypes(): void
    {
        // Apply through v11 so topics table exists with legacy default labels.
        $this->migrator->migrate(11);

        $this->assertSame(1, $this->db->insert('forums', [
            'parent_id' => 0,
            'forum_name' => 'Type board',
            'forum_slug' => 'type-board',
            'forum_desc' => '',
            'forum_type' => 'forum',
            'forum_status' => 'open',
            'forum_order' => 0,
            'topic_count' => 0,
            'post_count' => 0,
            'last_post_id' => 0,
            'last_poster_id' => 0,
            'last_post_time' => '1970-01-01 00:00:00',
            'last_topic_id' => 0,
        ]));
        $forumId = (int) $this->db->lastInsertId();
        $this->assertGreaterThan(0, $forumId);

        $seed = [
            'legacy-normal' => 'normal',
            'legacy-announce' => 'announce',
            'legacy-global' => 'global',
            'legacy-sticky' => 'sticky',
            'legacy-empty' => '',
            'legacy-junk' => 'wat',
        ];

        foreach ($seed as $slug => $type) {
            $this->assertSame(1, $this->db->insert('topics', [
                'forum_id' => $forumId,
                'topic_title' => $slug,
                'topic_slug' => $slug,
                'topic_poster' => 1,
                'topic_status' => 'open',
                'topic_type' => $type,
                'topic_approved' => 1,
                'topic_views' => 0,
                'reply_count' => 0,
                'first_post_id' => 0,
                'last_post_id' => 0,
                'last_poster_id' => 0,
                'topic_time' => '2026-01-01 00:00:00',
                'topic_modified' => '2026-01-01 00:00:00',
                'topic_last_post_time' => '2026-01-01 00:00:00',
            ]));
            $this->assertGreaterThan(0, (int) $this->db->lastInsertId());
        }

        // Apply v12 backfill only.
        $applied = $this->migrator->migrate(12);
        $this->assertCount(1, $applied);
        $this->assertSame(12, $applied[0]['version']);

        $table = $this->db->quoteIdentifier($this->db->table('topics'));
        $bySlug = static function (AP_DB $db, string $table, string $slug): string {
            return (string) $db->getVar(
                "SELECT topic_type FROM {$table} WHERE topic_slug = ?",
                [$slug]
            );
        };

        $this->assertSame('standard', $bySlug($this->db, $table, 'legacy-normal'));
        $this->assertSame('announcement', $bySlug($this->db, $table, 'legacy-announce'));
        $this->assertSame('announcement', $bySlug($this->db, $table, 'legacy-global'));
        $this->assertSame('sticky', $bySlug($this->db, $table, 'legacy-sticky'));
        $this->assertSame('standard', $bySlug($this->db, $table, 'legacy-empty'));
        $this->assertSame('standard', $bySlug($this->db, $table, 'legacy-junk'));
    }

    public function testCreateTopicPersistsCanonicalTypes(): void
    {
        $this->migrator->migrate();

        $forumId = AP_Forum::insertForum([
            'forum_name' => 'Canonical types',
        ], $this->db);
        $this->assertGreaterThan(0, $forumId);

        $cases = [
            'standard' => 'standard',
            'sticky' => 'sticky',
            'announcement' => 'announcement',
            'rules' => 'rules',
            // Legacy inputs normalize on write.
            'normal' => 'standard',
            'announce' => 'announcement',
            'global' => 'announcement',
            'info' => 'rules',
        ];

        foreach ($cases as $input => $expected) {
            $id = AP_Forum::createTopic([
                'forum_id' => $forumId,
                'topic_title' => 'Type ' . $input,
                'content' => 'Body for ' . $input,
                'topic_type' => $input,
            ], $this->db);
            $this->assertGreaterThan(0, $id, "create failed for input {$input}");
            $topic = AP_Forum::getTopic($id, $this->db);
            $this->assertNotNull($topic);
            $this->assertSame($expected, (string) $topic->topic_type, "input={$input}");

            // Raw DB value must be canonical (not legacy alias).
            $raw = (string) $this->db->getVar(
                'SELECT topic_type FROM ' . $this->db->quoteIdentifier($this->db->table('topics'))
                . ' WHERE topic_id = ?',
                [$id]
            );
            $this->assertSame($expected, $raw, "raw DB for input={$input}");
        }
    }
}
