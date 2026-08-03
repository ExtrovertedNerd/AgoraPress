<?php

/**
 * Tests for AP_Online (who’s online) and AP_Forum_Read (unread tracking).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Forum;

use AP_DB;
use AP_Forum;
use AP_Forum_Read;
use AP_Migrator;
use AP_Online;
use AP_Options;
use AP_User;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_Online::class)]
#[CoversClass(AP_Forum_Read::class)]
final class OnlineUnreadTest extends TestCase
{
    private string $root;

    private AP_DB $db;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        require_once $this->root . '/ap-includes/version.php';
        require_once $this->root . '/ap-includes/class-ap-db.php';
        require_once $this->root . '/ap-includes/class-ap-migrator.php';
        require_once $this->root . '/ap-includes/class-ap-options.php';
        require_once $this->root . '/ap-includes/class-ap-user.php';
        require_once $this->root . '/ap-includes/class-ap-forum.php';
        require_once $this->root . '/ap-includes/class-ap-online.php';
        require_once $this->root . '/ap-includes/class-ap-forum-read.php';
        require_once $this->root . '/ap-includes/functions.php';

        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');

        $migrator = new AP_Migrator($this->db, AP_Migrator::defaultMigrationsPath());
        $migrator->migrate();

        AP_Options::update(AP_Online::OPTION_ENABLED, '1', $this->db);
        AP_Options::update(AP_Online::OPTION_WINDOW, '900', $this->db);
        AP_Options::update(AP_Forum_Read::OPTION_ENABLED, '1', $this->db);
        AP_Options::update('ap_module_forum', '1', $this->db);
    }

    private function createUser(string $login): int
    {
        $result = AP_User::create([
            'user_login' => $login,
            'user_email' => $login . '@example.test',
            'user_pass' => 'password12345',
            'display_name' => ucfirst($login),
        ], $this->db);
        $this->assertTrue(!empty($result['ok']), $result['error'] ?? 'user create failed');

        return (int) $result['id'];
    }

    private function createForumWithTopic(string $title = 'Hello'): array
    {
        $forumId = AP_Forum::insertForum([
            'forum_name' => 'General Chat',
            'forum_type' => 'forum',
        ], $this->db);
        $this->assertGreaterThan(0, $forumId);

        $userId = $this->createUser('poster_' . substr(md5($title . microtime(true)), 0, 6));
        $topicId = AP_Forum::createTopic([
            'forum_id' => $forumId,
            'topic_title' => $title,
            'topic_poster' => $userId,
            'content' => 'First post body',
        ], $this->db);
        $this->assertGreaterThan(0, $topicId);

        return [
            'forum_id' => $forumId,
            'topic_id' => $topicId,
            'user_id' => $userId,
        ];
    }

    // -------------------------------------------------------------------------
    // Schema
    // -------------------------------------------------------------------------

    public function testSchemaTablesAndProperties(): void
    {
        foreach (['ap_online', 'ap_topic_track', 'ap_forum_track'] as $table) {
            $name = $this->db->getVar(
                "SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?",
                [$table]
            );
            $this->assertSame($table, $name, "Expected table {$table}");
        }
        $this->assertSame('ap_online', $this->db->online);
        $this->assertSame('ap_topic_track', $this->db->topic_track);
        $this->assertSame('ap_forum_track', $this->db->forum_track);
        $this->assertSame(9, (int) AP_DB_VERSION);
        $this->assertTrue(AP_Online::isAvailable($this->db));
        $this->assertTrue(AP_Forum_Read::isAvailable($this->db));
        $this->assertTrue(ap_online_enabled($this->db));
        $this->assertTrue(ap_forum_unread_tracking_enabled($this->db));
    }

    // -------------------------------------------------------------------------
    // Who’s online
    // -------------------------------------------------------------------------

    public function testTrackOnlineUsersAndGuests(): void
    {
        $alice = $this->createUser('alice_on');
        $bob = $this->createUser('bob_on');

        $id1 = AP_Online::track([
            'session_key' => 'sess_alice_1',
            'user_id' => $alice,
            'session_ip' => '127.0.0.1',
            'session_page' => '/forums/',
            'session_forum_id' => 3,
        ], $this->db);
        $this->assertGreaterThan(0, $id1);

        $id2 = AP_Online::track([
            'session_key' => 'sess_bob_1',
            'user_id' => $bob,
            'session_page' => '/forums/topic/1',
            'session_topic_id' => 1,
        ], $this->db);
        $this->assertGreaterThan(0, $id2);

        $guestId = AP_Online::track([
            'session_key' => 'sess_guest_1',
            'user_id' => 0,
            'guest_name' => 'Curious',
            'session_page' => '/forums/',
        ], $this->db);
        $this->assertGreaterThan(0, $guestId);

        $this->assertTrue(AP_Online::isUserOnline($alice, $this->db));
        $this->assertTrue(AP_Online::isUserOnline($bob, $this->db));
        $this->assertFalse(AP_Online::isUserOnline(99999, $this->db));

        $users = AP_Online::getOnlineUsers([], $this->db);
        $this->assertCount(2, $users);
        $userIds = array_map(static fn ($r) => (int) $r->user_id, $users);
        $this->assertContains($alice, $userIds);
        $this->assertContains($bob, $userIds);

        $this->assertSame(1, AP_Online::countOnlineGuests($this->db));
        $this->assertSame(3, AP_Online::countOnline($this->db));

        $display = AP_Online::getDisplay([], $this->db);
        $this->assertTrue($display['enabled']);
        $this->assertSame(2, $display['member_count']);
        $this->assertSame(1, $display['guest_count']);
        $this->assertSame(3, $display['total']);
        $this->assertNotEmpty($display['members'][0]['display_name']);
    }

    public function testTrackRefreshAndRemoveAndPrune(): void
    {
        $user = $this->createUser('refresh_on');
        $key = 'sess_refresh_1';

        $id = AP_Online::track([
            'session_key' => $key,
            'user_id' => $user,
            'session_page' => '/a',
        ], $this->db);
        $this->assertGreaterThan(0, $id);

        // Refresh same session — same online_id, updated page.
        $id2 = AP_Online::track([
            'session_key' => $key,
            'user_id' => $user,
            'session_page' => '/b',
            'session_forum_id' => 5,
        ], $this->db, ['prune' => false]);
        $this->assertSame($id, $id2);

        $row = AP_Online::getBySessionKey($key, $this->db);
        $this->assertNotNull($row);
        $this->assertSame('/b', $row->session_page);
        $this->assertSame(5, (int) $row->session_forum_id);

        // Duplicate user tabs: second key still counts once for isUserOnline.
        AP_Online::track([
            'session_key' => 'sess_refresh_2',
            'user_id' => $user,
        ], $this->db, ['prune' => false]);
        $this->assertCount(1, AP_Online::getOnlineUsers([], $this->db));

        $this->assertTrue(AP_Online::remove($key, $this->db));
        $this->assertNull(AP_Online::getBySessionKey($key, $this->db));

        AP_Online::removeUser($user, $this->db);
        $this->assertFalse(AP_Online::isUserOnline($user, $this->db));

        // Stale prune: insert old row via direct SQL then prune with short window.
        $this->db->insert('online', [
            'user_id' => $user,
            'session_key' => 'sess_stale',
            'session_ip' => '',
            'session_time' => '2000-01-01 00:00:00',
            'session_page' => '',
            'session_forum_id' => 0,
            'session_topic_id' => 0,
            'guest_name' => '',
        ]);
        $deleted = AP_Online::prune($this->db, ['window' => 60, 'check_enabled' => false]);
        $this->assertGreaterThanOrEqual(1, $deleted);
        $this->assertNull(AP_Online::getBySessionKey('sess_stale', $this->db));
    }

    public function testOnlineDisabledReturnsEmpty(): void
    {
        AP_Options::update(AP_Online::OPTION_ENABLED, '0', $this->db);
        $this->assertFalse(AP_Online::isAvailable($this->db));
        $this->assertSame(0, AP_Online::track([
            'session_key' => 'x',
            'user_id' => 1,
        ], $this->db));
        $this->assertSame([], AP_Online::getOnlineUsers([], $this->db));
        $this->assertSame(0, AP_Online::countOnline($this->db));
    }

    public function testProceduralOnlineHelpers(): void
    {
        $user = $this->createUser('proc_on');
        $id = ap_track_online([
            'session_key' => 'proc_sess',
            'user_id' => $user,
            'session_page' => '/forums/',
        ], $this->db);
        $this->assertGreaterThan(0, $id);
        $this->assertTrue(ap_is_user_online($user, $this->db));
        $this->assertGreaterThanOrEqual(1, ap_count_online($this->db));
        $display = ap_get_online_display([], $this->db);
        $this->assertTrue($display['enabled']);
        $this->assertTrue(ap_remove_online('proc_sess', $this->db));
    }

    // -------------------------------------------------------------------------
    // Unread tracking
    // -------------------------------------------------------------------------

    public function testTopicUnreadMarkReadAndReply(): void
    {
        $ctx = $this->createForumWithTopic('Unread topic');
        $reader = $this->createUser('reader1');
        $topicId = $ctx['topic_id'];
        $forumId = $ctx['forum_id'];

        // Fresh topic is unread for a different user.
        $this->assertTrue(AP_Forum_Read::isTopicUnread($reader, $topicId, $this->db));
        $this->assertTrue(AP_Forum_Read::isForumUnread($reader, $forumId, $this->db));

        $this->assertTrue(AP_Forum_Read::markTopicRead($reader, $topicId, $this->db));
        $this->assertFalse(AP_Forum_Read::isTopicUnread($reader, $topicId, $this->db));
        $this->assertFalse(AP_Forum_Read::isForumUnread($reader, $forumId, $this->db));

        // Simulate an older mark so a new reply is treated as unread without sleeping.
        $this->db->update(
            'topic_track',
            ['mark_time' => '2000-01-01 00:00:00'],
            ['user_id' => $reader, 'topic_id' => $topicId]
        );

        $replyId = AP_Forum::createReply([
            'topic_id' => $topicId,
            'poster_id' => $ctx['user_id'],
            'content' => 'A later reply',
        ], $this->db);
        $this->assertGreaterThan(0, $replyId);

        $topic = AP_Forum::getTopic($topicId, $this->db);
        $this->assertNotNull($topic);
        $this->assertTrue(AP_Forum_Read::isTopicUnread($reader, $topic, $this->db));

        $unread = AP_Forum_Read::getUnreadTopics($reader, ['forum_id' => $forumId], $this->db);
        $this->assertNotEmpty($unread);
        $this->assertSame($topicId, (int) $unread[0]->topic_id);

        $this->assertSame(1, AP_Forum_Read::countUnreadTopicsInForum($reader, $forumId, $this->db));
    }

    public function testMarkForumReadAndMarkAllRead(): void
    {
        $ctx1 = $this->createForumWithTopic('Topic A');
        $ctx2 = $this->createForumWithTopic('Topic B');
        // Same forum for both topics in second case — create second topic in first forum.
        $topic2 = AP_Forum::createTopic([
            'forum_id' => $ctx1['forum_id'],
            'topic_title' => 'Second topic',
            'topic_poster' => $ctx1['user_id'],
            'content' => 'Body two',
        ], $this->db);
        $this->assertGreaterThan(0, $topic2);

        $reader = $this->createUser('reader_all');

        $this->assertTrue(AP_Forum_Read::isTopicUnread($reader, $ctx1['topic_id'], $this->db));
        $this->assertTrue(AP_Forum_Read::isTopicUnread($reader, $topic2, $this->db));

        $this->assertTrue(AP_Forum_Read::markForumRead($reader, $ctx1['forum_id'], $this->db));
        $this->assertFalse(AP_Forum_Read::isTopicUnread($reader, $ctx1['topic_id'], $this->db));
        $this->assertFalse(AP_Forum_Read::isTopicUnread($reader, $topic2, $this->db));
        $this->assertFalse(AP_Forum_Read::isForumUnread($reader, $ctx1['forum_id'], $this->db));

        // Other forum still unread.
        $this->assertTrue(AP_Forum_Read::isTopicUnread($reader, $ctx2['topic_id'], $this->db));

        $this->assertTrue(AP_Forum_Read::markAllRead($reader, $this->db));
        $this->assertFalse(AP_Forum_Read::isTopicUnread($reader, $ctx2['topic_id'], $this->db));
        $this->assertNotSame(AP_Forum_Read::EMPTY_DATETIME, AP_Forum_Read::getGlobalLastMark($reader, $this->db));
    }

    public function testAnnotateTopicsAndSummaryAndGuests(): void
    {
        $ctx = $this->createForumWithTopic('Annotate me');
        $reader = $this->createUser('annotator');
        $topic = AP_Forum::getTopic($ctx['topic_id'], $this->db);
        $this->assertNotNull($topic);

        $annotated = AP_Forum_Read::annotateTopics($reader, [$topic], $this->db);
        $this->assertTrue($annotated[0]['is_unread']);

        AP_Forum_Read::markTopicRead($reader, $ctx['topic_id'], $this->db);
        $annotated = AP_Forum_Read::annotateTopics($reader, [$topic], $this->db);
        $this->assertFalse($annotated[0]['is_unread']);

        $summary = AP_Forum_Read::getUnreadSummary($reader, [], $this->db);
        $this->assertTrue($summary['enabled']);
        $this->assertSame(0, $summary['unread_count']);

        // Guests never see unread.
        $this->assertFalse(AP_Forum_Read::isTopicUnread(0, $ctx['topic_id'], $this->db));
        $this->assertSame([], AP_Forum_Read::getUnreadTopics(0, [], $this->db));
    }

    public function testUnreadDisabled(): void
    {
        $ctx = $this->createForumWithTopic('Disabled unread');
        $reader = $this->createUser('disabled_reader');
        AP_Options::update(AP_Forum_Read::OPTION_ENABLED, '0', $this->db);
        $this->assertFalse(AP_Forum_Read::isAvailable($this->db));
        $this->assertFalse(AP_Forum_Read::isTopicUnread($reader, $ctx['topic_id'], $this->db));
        $this->assertFalse(AP_Forum_Read::markTopicRead($reader, $ctx['topic_id'], $this->db));
    }

    public function testProceduralUnreadHelpers(): void
    {
        $ctx = $this->createForumWithTopic('Proc unread');
        $reader = $this->createUser('proc_reader');

        $this->assertTrue(ap_is_topic_unread($reader, $ctx['topic_id'], $this->db));
        $this->assertTrue(ap_mark_topic_read($reader, $ctx['topic_id'], $this->db));
        $this->assertFalse(ap_is_topic_unread($reader, $ctx['topic_id'], $this->db));

        // Force unread via older mark then mark forum.
        $this->db->update(
            'topic_track',
            ['mark_time' => '2000-01-01 00:00:00'],
            ['user_id' => $reader, 'topic_id' => $ctx['topic_id']]
        );
        // Topic last post is newer than 2000 — unread again.
        $this->assertTrue(ap_is_topic_unread($reader, $ctx['topic_id'], $this->db));

        $this->assertTrue(ap_mark_forum_read($reader, $ctx['forum_id'], $this->db));
        $this->assertFalse(ap_is_forum_unread($reader, $ctx['forum_id'], $this->db));

        $this->assertTrue(ap_mark_all_forums_read($reader, $this->db));
        $summary = ap_get_unread_summary($reader, [], $this->db);
        $this->assertTrue($summary['enabled']);
    }
}
