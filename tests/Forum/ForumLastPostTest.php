<?php

/**
 * Last-post recount after topic delete (soft and force).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Forum;

use AP_DB;
use AP_Forum;
use AP_Migrator;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_Forum::class)]
final class ForumLastPostTest extends TestCase
{
    private string $root;

    private AP_DB $db;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        require_once $this->root . '/ap-includes/version.php';
        require_once $this->root . '/ap-includes/class-ap-db.php';
        require_once $this->root . '/ap-includes/class-ap-migrator.php';
        require_once $this->root . '/ap-includes/class-ap-forum.php';
        require_once $this->root . '/ap-includes/functions.php';

        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');

        $migrator = new AP_Migrator($this->db, AP_Migrator::defaultMigrationsPath());
        $migrator->migrate();
    }

    public function testSoftDeleteNewestTopicLeavesOlderLastPost(): void
    {
        $board = $this->twoTopicBoard();

        $this->assertTrue(AP_Forum::deleteTopic($board['newerId'], false, $this->db));
        $this->assertSameLastPost(
            $board['forumId'],
            $board['olderId'],
            $board['olderPostId'],
            $board['olderPosterId'],
            'Older thread'
        );
        $this->assertSame('deleted', AP_Forum::getTopic($board['newerId'], $this->db)?->topic_status);
    }

    public function testForceDeleteNewestTopicLeavesOlderLastPost(): void
    {
        $board = $this->twoTopicBoard();

        $this->assertTrue(AP_Forum::deleteTopic($board['newerId'], true, $this->db));
        $this->assertNull(AP_Forum::getTopic($board['newerId'], $this->db));
        $this->assertSameLastPost(
            $board['forumId'],
            $board['olderId'],
            $board['olderPostId'],
            $board['olderPosterId'],
            'Older thread'
        );
    }

    public function testSoftDeleteBothTopicsClearsLastPost(): void
    {
        $board = $this->twoTopicBoard();

        $this->assertTrue(AP_Forum::deleteTopic($board['newerId'], false, $this->db));
        $this->assertTrue(AP_Forum::deleteTopic($board['olderId'], false, $this->db));
        $this->assertEmptyLastPost($board['forumId']);
    }

    public function testForceDeleteBothTopicsClearsLastPost(): void
    {
        $board = $this->twoTopicBoard();

        $this->assertTrue(AP_Forum::deleteTopic($board['newerId'], true, $this->db));
        $this->assertTrue(AP_Forum::deleteTopic($board['olderId'], true, $this->db));
        $this->assertEmptyLastPost($board['forumId']);
    }

    public function testDeleteNonLastTopicLeavesRealLastPost(): void
    {
        $board = $this->twoTopicBoard();

        $this->assertTrue(AP_Forum::deleteTopic($board['olderId'], false, $this->db));
        $this->assertSameLastPost(
            $board['forumId'],
            $board['newerId'],
            $board['newerPostId'],
            $board['newerPosterId'],
            'Newer thread'
        );

        $this->assertTrue(AP_Forum::deleteTopic($board['olderId'], true, $this->db));
        $this->assertSameLastPost(
            $board['forumId'],
            $board['newerId'],
            $board['newerPostId'],
            $board['newerPosterId'],
            'Newer thread'
        );
    }

    public function testForceDeleteAlreadySoftDeletedTopicStillRefreshesLastPost(): void
    {
        $board = $this->twoTopicBoard();

        $this->assertTrue(AP_Forum::deleteTopic($board['newerId'], false, $this->db));
        $this->assertSameLastPost(
            $board['forumId'],
            $board['olderId'],
            $board['olderPostId'],
            $board['olderPosterId'],
            'Older thread'
        );

        // Simulate a stale pointer left by the pre-recount helper.
        $this->plantStaleLastPost($board);

        $this->assertTrue(AP_Forum::deleteTopic($board['newerId'], true, $this->db));
        $this->assertSameLastPost(
            $board['forumId'],
            $board['olderId'],
            $board['olderPostId'],
            $board['olderPosterId'],
            'Older thread'
        );
    }

    public function testSoftDeleteAlreadyDeletedTopicStillRefreshesLastPost(): void
    {
        $board = $this->twoTopicBoard();

        $this->assertTrue(AP_Forum::deleteTopic($board['newerId'], false, $this->db));
        $this->plantStaleLastPost($board);

        $this->assertTrue(AP_Forum::deleteTopic($board['newerId'], false, $this->db));
        $this->assertSameLastPost(
            $board['forumId'],
            $board['olderId'],
            $board['olderPostId'],
            $board['olderPosterId'],
            'Older thread'
        );
    }

    public function testDeleteUnapprovedTopicStillRefreshesLastPost(): void
    {
        $board = $this->twoTopicBoard();

        $pendingId = AP_Forum::createTopic([
            'forum_id' => $board['forumId'],
            'topic_title' => 'Pending thread',
            'content' => 'Not yet approved',
            'poster_id' => 31,
            'topic_approved' => 0,
        ], $this->db);
        $this->assertGreaterThan(0, $pendingId);
        $pending = AP_Forum::getTopic($pendingId, $this->db);
        $this->assertNotNull($pending);

        $this->assertSameLastPost(
            $board['forumId'],
            $board['newerId'],
            $board['newerPostId'],
            $board['newerPosterId'],
            'Newer thread'
        );

        $this->db->update('forums', [
            'last_post_id' => (int) $pending->last_post_id,
            'last_poster_id' => (int) $pending->last_poster_id,
            'last_post_time' => (string) $pending->topic_last_post_time,
            'last_topic_id' => $pendingId,
        ], ['forum_id' => $board['forumId']]);

        $this->assertTrue(AP_Forum::deleteTopic($pendingId, false, $this->db));
        $this->assertSameLastPost(
            $board['forumId'],
            $board['newerId'],
            $board['newerPostId'],
            $board['newerPosterId'],
            'Newer thread'
        );

        $this->db->update('forums', [
            'last_post_id' => (int) $pending->last_post_id,
            'last_poster_id' => (int) $pending->last_poster_id,
            'last_post_time' => (string) $pending->topic_last_post_time,
            'last_topic_id' => $pendingId,
        ], ['forum_id' => $board['forumId']]);

        $this->assertTrue(AP_Forum::deleteTopic($pendingId, true, $this->db));
        $this->assertSameLastPost(
            $board['forumId'],
            $board['newerId'],
            $board['newerPostId'],
            $board['newerPosterId'],
            'Newer thread'
        );
    }

    /**
     * @return array{
     *   forumId: int,
     *   olderId: int,
     *   newerId: int,
     *   olderPostId: int,
     *   newerPostId: int,
     *   olderPosterId: int,
     *   newerPosterId: int,
     *   newerPostTime: string
     * }
     */
    private function twoTopicBoard(): array
    {
        $forumId = AP_Forum::insertForum(['forum_name' => 'Last Post Board'], $this->db);
        $this->assertGreaterThan(0, $forumId);

        $olderId = AP_Forum::createTopic([
            'forum_id' => $forumId,
            'topic_title' => 'Older thread',
            'content' => 'Older opening post',
            'poster_id' => 11,
        ], $this->db);
        $this->assertGreaterThan(0, $olderId);
        $olderReply = AP_Forum::createReply([
            'topic_id' => $olderId,
            'content' => 'Older reply',
            'poster_id' => 12,
        ], $this->db);
        $this->assertGreaterThan(0, $olderReply);

        $newerId = AP_Forum::createTopic([
            'forum_id' => $forumId,
            'topic_title' => 'Newer thread',
            'content' => 'Newer opening post',
            'poster_id' => 21,
        ], $this->db);
        $this->assertGreaterThan(0, $newerId);
        $newerReply = AP_Forum::createReply([
            'topic_id' => $newerId,
            'content' => 'Newer reply',
            'poster_id' => 22,
        ], $this->db);
        $this->assertGreaterThan(0, $newerReply);

        $older = AP_Forum::getTopic($olderId, $this->db);
        $newer = AP_Forum::getTopic($newerId, $this->db);
        $this->assertNotNull($older);
        $this->assertNotNull($newer);

        $forum = AP_Forum::getForum($forumId, $this->db);
        $this->assertSame($newerId, (int) $forum?->last_topic_id);
        $this->assertSame($newerReply, (int) $forum?->last_post_id);

        return [
            'forumId' => $forumId,
            'olderId' => $olderId,
            'newerId' => $newerId,
            'olderPostId' => $olderReply,
            'newerPostId' => $newerReply,
            'olderPosterId' => 12,
            'newerPosterId' => 22,
            'newerPostTime' => (string) $newer->topic_last_post_time,
        ];
    }

    /**
     * @param array{
     *   forumId: int,
     *   newerId: int,
     *   newerPostId: int,
     *   newerPosterId: int,
     *   newerPostTime: string
     * } $board
     */
    private function plantStaleLastPost(array $board): void
    {
        $this->db->update('forums', [
            'last_post_id' => $board['newerPostId'],
            'last_poster_id' => $board['newerPosterId'],
            'last_post_time' => $board['newerPostTime'],
            'last_topic_id' => $board['newerId'],
        ], ['forum_id' => $board['forumId']]);
    }

    private function assertSameLastPost(
        int $forumId,
        int $topicId,
        int $postId,
        int $posterId,
        string $expectedTitle
    ): void {
        $forum = AP_Forum::getForum($forumId, $this->db);
        $this->assertNotNull($forum);
        $this->assertSame($topicId, (int) $forum->last_topic_id);
        $this->assertSame($postId, (int) $forum->last_post_id);
        $this->assertSame($posterId, (int) $forum->last_poster_id);
        $this->assertNotSame(AP_Forum::EMPTY_DATETIME, (string) $forum->last_post_time);

        $row = AP_Forum::forumToDisplayRow($forum, $this->db);
        $last = $row['last_post'] ?? null;
        $this->assertIsArray($last);
        $this->assertSame($topicId, (int) $last['topic_id']);
        $this->assertSame($postId, (int) $last['post_id']);
        $this->assertSame($expectedTitle, (string) $last['title']);
        $this->assertStringContainsString('#post-' . $postId, (string) $last['url']);
    }

    private function assertEmptyLastPost(int $forumId): void
    {
        $forum = AP_Forum::getForum($forumId, $this->db);
        $this->assertNotNull($forum);
        $this->assertSame(0, (int) $forum->last_post_id);
        $this->assertSame(0, (int) $forum->last_topic_id);
        $this->assertSame(0, (int) $forum->last_poster_id);
        $this->assertSame(AP_Forum::EMPTY_DATETIME, (string) $forum->last_post_time);

        $row = AP_Forum::forumToDisplayRow($forum, $this->db);
        $this->assertNull($row['last_post']);
    }
}
