<?php

/**
 * Tests for shipped migration 0005 — dedicated forum tables.
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
final class ForumTablesMigrationTest extends TestCase
{
    private string $root;

    private AP_DB $db;

    private AP_Migrator $migrator;

    /** @var list<string> */
    private const FORUM_TABLES = [
        'ap_forums',
        'ap_topics',
        'ap_forum_posts',
        'ap_groups',
        'ap_group_members',
        'ap_messages',
        'ap_ranks',
        'ap_reports',
        'ap_online',
    ];

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
        $path = AP_Migrator::defaultMigrationsPath() . '/0005_forum_tables.php';
        $this->assertFileIsReadable($path);
        // Forum base tables ship at schema v5; later migrations may bump AP_DB_VERSION.
        $this->assertGreaterThanOrEqual(5, (int) AP_DB_VERSION);
        $this->assertGreaterThanOrEqual(5, AP_Migrator::codeTargetVersion());
    }

    public function testMigrateCreatesForumTables(): void
    {
        $this->assertTrue($this->migrator->needsMigration());
        $applied = $this->migrator->migrate();
        $this->assertGreaterThanOrEqual(5, count($applied));
        $this->assertSame(1, $applied[0]['version']);
        $this->assertSame(5, $applied[4]['version']);
        $this->assertStringContainsString('forum', strtolower($applied[4]['description']));
        $this->assertGreaterThanOrEqual(5, $this->migrator->getCurrentVersion());
        $this->assertFalse($this->migrator->needsMigration());

        foreach (self::FORUM_TABLES as $table) {
            $name = $this->db->getVar(
                "SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?",
                [$table]
            );
            $this->assertSame($table, $name, "Expected table {$table}");
        }

        $this->assertSame('ap_forums', $this->db->forums);
        $this->assertSame('ap_topics', $this->db->topics);
        $this->assertSame('ap_forum_posts', $this->db->forum_posts);
        $this->assertSame('ap_groups', $this->db->groups);
        $this->assertSame('ap_group_members', $this->db->group_members);
        $this->assertSame('ap_messages', $this->db->messages);
        $this->assertSame('ap_ranks', $this->db->ranks);
        $this->assertSame('ap_reports', $this->db->reports);
        $this->assertSame('ap_online', $this->db->online);
    }

    public function testForumHierarchyTopicPostCrudRoundTrip(): void
    {
        $this->migrator->migrate();

        $this->assertSame(1, $this->db->insert('forums', [
            'parent_id' => 0,
            'forum_type' => 'category',
            'forum_status' => 'open',
            'forum_name' => 'General',
            'forum_slug' => 'general',
            'forum_desc' => 'Root category',
            'forum_order' => 1,
            'topic_count' => 0,
            'post_count' => 0,
            'last_post_id' => 0,
            'last_poster_id' => 0,
            'last_post_time' => '1970-01-01 00:00:00',
            'last_topic_id' => 0,
        ]));
        $categoryId = (int) $this->db->lastInsertId();
        $this->assertGreaterThan(0, $categoryId);

        $this->assertSame(1, $this->db->insert('forums', [
            'parent_id' => $categoryId,
            'forum_type' => 'forum',
            'forum_status' => 'open',
            'forum_name' => 'Announcements',
            'forum_slug' => 'announcements',
            'forum_desc' => 'Site news',
            'forum_order' => 0,
            'topic_count' => 0,
            'post_count' => 0,
            'last_post_id' => 0,
            'last_poster_id' => 0,
            'last_post_time' => '1970-01-01 00:00:00',
            'last_topic_id' => 0,
        ]));
        $forumId = (int) $this->db->lastInsertId();

        $this->assertSame(1, $this->db->insert('topics', [
            'forum_id' => $forumId,
            'topic_title' => 'Welcome',
            'topic_slug' => 'welcome',
            'topic_poster' => 1,
            'topic_status' => 'open',
            'topic_type' => 'sticky',
            'topic_approved' => 1,
            'topic_views' => 0,
            'reply_count' => 0,
            'first_post_id' => 0,
            'last_post_id' => 0,
            'last_poster_id' => 0,
            'topic_time' => '2026-08-03 12:00:00',
            'topic_modified' => '2026-08-03 12:00:00',
            'topic_last_post_time' => '2026-08-03 12:00:00',
        ]));
        $topicId = (int) $this->db->lastInsertId();
        $this->assertGreaterThan(0, $topicId);

        $this->assertSame(1, $this->db->insert('forum_posts', [
            'topic_id' => $topicId,
            'forum_id' => $forumId,
            'poster_id' => 1,
            'post_subject' => 'Welcome',
            'post_content' => 'Hello agora',
            'post_content_filtered' => '',
            'poster_ip' => '127.0.0.1',
            'post_time' => '2026-08-03 12:00:00',
            'post_modified' => '2026-08-03 12:00:00',
            'post_approved' => 1,
            'post_reported' => 0,
            'post_edit_reason' => '',
            'post_edit_user' => 0,
            'post_edit_time' => '1970-01-01 00:00:00',
            'post_edit_count' => 0,
            'post_position' => 1,
        ]));
        $postId = (int) $this->db->lastInsertId();
        $this->assertGreaterThan(0, $postId);

        $this->db->update(
            'topics',
            [
                'first_post_id' => $postId,
                'last_post_id' => $postId,
                'last_poster_id' => 1,
            ],
            ['topic_id' => $topicId]
        );
        $this->db->update(
            'forums',
            [
                'topic_count' => 1,
                'post_count' => 1,
                'last_post_id' => $postId,
                'last_poster_id' => 1,
                'last_post_time' => '2026-08-03 12:00:00',
                'last_topic_id' => $topicId,
            ],
            ['forum_id' => $forumId]
        );

        $row = $this->db->getRow(
            'SELECT f.forum_name, t.topic_title, t.topic_type, p.post_content'
            . ' FROM ' . $this->db->quoteIdentifier($this->db->forum_posts) . ' p'
            . ' INNER JOIN ' . $this->db->quoteIdentifier($this->db->topics) . ' t'
            . ' ON p.topic_id = t.topic_id'
            . ' INNER JOIN ' . $this->db->quoteIdentifier($this->db->forums) . ' f'
            . ' ON t.forum_id = f.forum_id'
            . ' WHERE p.post_id = ?',
            [$postId]
        );
        $this->assertNotNull($row);
        $this->assertSame('Announcements', $row->forum_name);
        $this->assertSame('Welcome', $row->topic_title);
        $this->assertSame('sticky', $row->topic_type);
        $this->assertSame('Hello agora', $row->post_content);
    }

    public function testGroupsMessagesRanksReportsOnlineCrud(): void
    {
        $this->migrator->migrate();

        $this->assertSame(1, $this->db->insert('groups', [
            'group_name' => 'Moderators',
            'group_slug' => 'moderators',
            'group_desc' => 'Forum mods',
            'group_type' => 'closed',
            'member_count' => 0,
            'created_at' => '2026-08-03 10:00:00',
        ]));
        $groupId = (int) $this->db->lastInsertId();

        $this->assertSame(1, $this->db->insert('group_members', [
            'group_id' => $groupId,
            'user_id' => 2,
            'member_role' => 'leader',
            'joined_at' => '2026-08-03 10:05:00',
        ]));

        $this->assertSame(1, $this->db->insert('messages', [
            'sender_id' => 1,
            'recipient_id' => 2,
            'parent_id' => 0,
            'subject' => 'Hello',
            'message_content' => 'Private note',
            'sent_at' => '2026-08-03 11:00:00',
            'read_at' => null,
            'sender_deleted' => 0,
            'recipient_deleted' => 0,
        ]));
        $msgId = (int) $this->db->lastInsertId();
        $this->assertGreaterThan(0, $msgId);

        $this->assertSame(1, $this->db->insert('ranks', [
            'rank_title' => 'Newbie',
            'rank_min_posts' => 0,
            'rank_special' => 0,
            'rank_image' => '',
            'rank_order' => 1,
        ]));

        $this->assertSame(1, $this->db->insert('reports', [
            'reporter_id' => 3,
            'report_type' => 'post',
            'report_object_id' => 99,
            'report_reason' => 'spam',
            'report_details' => 'Looks like spam',
            'report_status' => 'open',
            'reported_at' => '2026-08-03 12:00:00',
            'resolved_at' => null,
            'resolved_by' => 0,
        ]));

        $this->assertSame(1, $this->db->insert('online', [
            'user_id' => 1,
            'session_key' => 'abc123session',
            'session_ip' => '127.0.0.1',
            'session_time' => '2026-08-03 12:30:00',
            'session_page' => '/forums/',
            'session_forum_id' => 0,
            'session_topic_id' => 0,
            'guest_name' => '',
        ]));

        $member = $this->db->getRow(
            'SELECT g.group_name, m.member_role FROM '
            . $this->db->quoteIdentifier($this->db->group_members) . ' m'
            . ' INNER JOIN ' . $this->db->quoteIdentifier($this->db->groups) . ' g'
            . ' ON m.group_id = g.group_id WHERE m.user_id = ?',
            [2]
        );
        $this->assertNotNull($member);
        $this->assertSame('Moderators', $member->group_name);
        $this->assertSame('leader', $member->member_role);

        $this->assertSame(
            'Private note',
            $this->db->getVar(
                'SELECT message_content FROM '
                . $this->db->quoteIdentifier($this->db->messages)
                . ' WHERE message_id = ?',
                [$msgId]
            )
        );
        $this->assertSame(
            'Newbie',
            $this->db->getVar(
                'SELECT rank_title FROM ' . $this->db->quoteIdentifier($this->db->ranks)
                . ' WHERE rank_min_posts = ?',
                [0]
            )
        );
        $this->assertSame(
            'open',
            $this->db->getVar(
                'SELECT report_status FROM ' . $this->db->quoteIdentifier($this->db->reports)
                . ' WHERE report_object_id = ?',
                [99]
            )
        );
        $this->assertSame(
            '1',
            (string) $this->db->getVar(
                'SELECT user_id FROM ' . $this->db->quoteIdentifier($this->db->online)
                . ' WHERE session_key = ?',
                ['abc123session']
            )
        );
    }

    public function testApForumBaseTablesAndTablesHelper(): void
    {
        $bases = AP_Forum::baseTables();
        $this->assertContains('forums', $bases);
        $this->assertContains('topics', $bases);
        $this->assertContains('forum_posts', $bases);
        $this->assertContains('online', $bases);
        $this->assertSame(ap_forum_base_tables(), $bases);

        $map = AP_Forum::tables($this->db);
        $this->assertSame('ap_forums', $map['forums']);
        $this->assertSame('ap_forum_posts', $map['forum_posts']);
        $this->assertSame('ap_group_members', $map['group_members']);
    }

    public function testCustomPrefixCreatesPrefixedForumTables(): void
    {
        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $db = AP_DB::fromPdo($pdo, 'sqlite', 'site_');
        $migrator = new AP_Migrator($db, AP_Migrator::defaultMigrationsPath());
        $migrator->migrate();

        foreach (
            [
                'site_forums',
                'site_topics',
                'site_forum_posts',
                'site_groups',
                'site_group_members',
                'site_messages',
                'site_ranks',
                'site_reports',
                'site_online',
            ] as $table
        ) {
            $name = $db->getVar(
                "SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?",
                [$table]
            );
            $this->assertSame($table, $name, "Expected table {$table}");
        }

        $this->assertSame('site_forums', $db->forums);
        $this->assertSame(1, $db->insert('forums', [
            'parent_id' => 0,
            'forum_type' => 'forum',
            'forum_status' => 'open',
            'forum_name' => 'Main',
            'forum_slug' => 'main',
            'forum_desc' => '',
            'forum_order' => 0,
            'topic_count' => 0,
            'post_count' => 0,
            'last_post_id' => 0,
            'last_poster_id' => 0,
            'last_post_time' => '1970-01-01 00:00:00',
            'last_topic_id' => 0,
        ]));
    }
}
