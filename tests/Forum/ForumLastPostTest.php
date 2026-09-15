<?php

/**
 * Last-post recount: refreshForumLastPost skips deleted/unapproved
 * topics and posts; empty boards clear last_* columns. The board-index
 * Last Post renderer recounts stale pointers and never prints a deleted topic.
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
        $this->assertRemainingLastPostIsNotDeletedTopic($board);
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
        $this->assertRemainingLastPostIsNotDeletedTopic($board);
    }

    public function testSoftDeleteBothTopicsClearsLastPost(): void
    {
        $board = $this->twoTopicBoard();

        $this->assertTrue(AP_Forum::deleteTopic($board['newerId'], false, $this->db));
        $this->assertTrue(AP_Forum::deleteTopic($board['olderId'], false, $this->db));
        $this->assertEmptyLastPost($board['forumId'], $board);
    }

    public function testForceDeleteBothTopicsClearsLastPost(): void
    {
        $board = $this->twoTopicBoard();

        $this->assertTrue(AP_Forum::deleteTopic($board['newerId'], true, $this->db));
        $this->assertTrue(AP_Forum::deleteTopic($board['olderId'], true, $this->db));
        $this->assertEmptyLastPost($board['forumId'], $board);
    }

    /**
     * SPEC: two topics, delete newest → older remains; delete both → empty,
     * no dead permalink.
     */
    public function testTwoTopicsDeleteNewestThenBothLeavesOlderThenEmptyWithoutDeadPermalink(): void
    {
        $this->assertDeleteNewestThenBothClearsPermalink(false);
    }

    /**
     * SPEC: two topics, force-delete newest → older remains; delete both →
     * empty, no dead permalink.
     */
    public function testTwoTopicsForceDeleteNewestThenBothLeavesOlderThenEmptyWithoutDeadPermalink(): void
    {
        $this->assertDeleteNewestThenBothClearsPermalink(true);
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

    public function testRefreshIgnoresDeletedTopic(): void
    {
        $board = $this->twoTopicBoard();
        $this->db->update(
            'topics',
            ['topic_status' => AP_Forum::TOPIC_STATUS_DELETED],
            ['topic_id' => $board['newerId']]
        );

        AP_Forum::refreshForumLastPost($board['forumId'], $this->db);
        $this->assertSameLastPost(
            $board['forumId'],
            $board['olderId'],
            $board['olderPostId'],
            $board['olderPosterId'],
            'Older thread'
        );
    }

    public function testRefreshIgnoresUnapprovedTopic(): void
    {
        $board = $this->twoTopicBoard();
        $this->db->update(
            'topics',
            ['topic_approved' => 0],
            ['topic_id' => $board['newerId']]
        );

        AP_Forum::refreshForumLastPost($board['forumId'], $this->db);
        $this->assertSameLastPost(
            $board['forumId'],
            $board['olderId'],
            $board['olderPostId'],
            $board['olderPosterId'],
            'Older thread'
        );
    }

    public function testRefreshIgnoresUnapprovedPost(): void
    {
        $board = $this->twoTopicBoard();
        $newer = AP_Forum::getTopic($board['newerId'], $this->db);
        $this->assertNotNull($newer);
        $openingId = (int) $newer->first_post_id;
        $opening = AP_Forum::getPost($openingId, $this->db);
        $this->assertNotNull($opening);

        $this->db->update(
            'forum_posts',
            ['post_approved' => 0],
            ['post_id' => $board['newerPostId']]
        );

        AP_Forum::refreshForumLastPost($board['forumId'], $this->db);
        $this->assertSameLastPost(
            $board['forumId'],
            $board['newerId'],
            $openingId,
            (int) $opening->poster_id,
            'Newer thread'
        );
    }

    public function testRefreshKeepsLockedTopicAsLastPost(): void
    {
        $board = $this->twoTopicBoard();
        $this->db->update(
            'topics',
            ['topic_status' => AP_Forum::TOPIC_STATUS_LOCKED],
            ['topic_id' => $board['newerId']]
        );

        AP_Forum::refreshForumLastPost($board['forumId'], $this->db);
        $this->assertSameLastPost(
            $board['forumId'],
            $board['newerId'],
            $board['newerPostId'],
            $board['newerPosterId'],
            'Newer thread'
        );
    }

    public function testRefreshEmptyBoardClearsLastPostColumns(): void
    {
        $forumId = AP_Forum::insertForum(['forum_name' => 'Empty Last Post'], $this->db);
        $this->assertGreaterThan(0, $forumId);
        $this->plantArbitraryLastPost($forumId, 99, 88, 77, '2026-01-02 03:04:05');

        AP_Forum::refreshForumLastPost($forumId, $this->db);
        $this->assertEmptyLastPost($forumId);
    }

    public function testRefreshOnlyHiddenContentClearsLastPostColumns(): void
    {
        $board = $this->twoTopicBoard();
        $this->db->update(
            'topics',
            ['topic_status' => AP_Forum::TOPIC_STATUS_DELETED],
            ['topic_id' => $board['newerId']]
        );
        $this->db->update(
            'topics',
            ['topic_approved' => 0],
            ['topic_id' => $board['olderId']]
        );

        AP_Forum::refreshForumLastPost($board['forumId'], $this->db);
        $this->assertEmptyLastPost($board['forumId'], $board);
    }

    public function testRefreshOnlyUnapprovedPostsClearsLastPostColumns(): void
    {
        $forumId = AP_Forum::insertForum(['forum_name' => 'Unapproved Posts Board'], $this->db);
        $this->assertGreaterThan(0, $forumId);
        $topicId = AP_Forum::createTopic([
            'forum_id' => $forumId,
            'topic_title' => 'Held thread',
            'content' => 'Opening post',
            'poster_id' => 41,
        ], $this->db);
        $this->assertGreaterThan(0, $topicId);
        $replyId = AP_Forum::createReply([
            'topic_id' => $topicId,
            'content' => 'Held reply',
            'poster_id' => 42,
        ], $this->db);
        $this->assertGreaterThan(0, $replyId);

        $this->db->query(
            'UPDATE ' . $this->db->quoteIdentifier($this->db->table('forum_posts'))
            . ' SET ' . $this->db->quoteIdentifier('post_approved') . ' = 0 WHERE '
            . $this->db->quoteIdentifier('topic_id') . ' = ?',
            [$topicId]
        );

        AP_Forum::refreshForumLastPost($forumId, $this->db);
        $this->assertEmptyLastPost($forumId);
    }

    public function testDisplayRowRecountsStaleDeletedNewestLeavesOlder(): void
    {
        $board = $this->twoTopicBoard();
        $this->db->update(
            'topics',
            ['topic_status' => AP_Forum::TOPIC_STATUS_DELETED],
            ['topic_id' => $board['newerId']]
        );
        $this->plantStaleLastPost($board);

        $forum = AP_Forum::getForum($board['forumId'], $this->db);
        $this->assertNotNull($forum);
        $this->assertSame($board['newerId'], (int) $forum->last_topic_id);

        $preload = AP_Forum::buildForumRowPreload([$forum], $this->db);
        $this->assertArrayHasKey($board['newerId'], $preload['topics']);
        $this->assertSame(
            AP_Forum::TOPIC_STATUS_DELETED,
            (string) $preload['topics'][$board['newerId']]->topic_status
        );

        $row = AP_Forum::forumToDisplayRow($forum, $this->db, $preload);
        $last = $row['last_post'] ?? null;
        $this->assertIsArray($last);
        $this->assertSame('Older thread', (string) $last['title']);
        $this->assertSame($board['olderId'], (int) $last['topic_id']);
        $this->assertSame($board['olderPostId'], (int) $last['post_id']);
        $this->assertStringContainsString('#post-' . $board['olderPostId'], (string) $last['url']);
        $this->assertLastPostDoesNotPointAt(
            $last,
            $board['newerId'],
            $board['newerPostId'],
            'Newer thread',
            $board['newerSlug']
        );

        $this->assertSameLastPost(
            $board['forumId'],
            $board['olderId'],
            $board['olderPostId'],
            $board['olderPosterId'],
            'Older thread'
        );
    }

    public function testDisplayRowRecountsStaleWhenBothDeletedShowsEmpty(): void
    {
        $board = $this->twoTopicBoard();
        $this->db->update(
            'topics',
            ['topic_status' => AP_Forum::TOPIC_STATUS_DELETED],
            ['topic_id' => $board['newerId']]
        );
        $this->db->update(
            'topics',
            ['topic_status' => AP_Forum::TOPIC_STATUS_DELETED],
            ['topic_id' => $board['olderId']]
        );
        $this->plantStaleLastPost($board);

        $forum = AP_Forum::getForum($board['forumId'], $this->db);
        $this->assertNotNull($forum);
        $this->assertSame($board['newerId'], (int) $forum->last_topic_id);

        $row = AP_Forum::forumToDisplayRow($forum, $this->db);
        $this->assertNull($row['last_post']);
        $this->assertSame('', (string) ($row['last_post']['title'] ?? ''));
        $this->assertSame('', (string) ($row['last_post']['url'] ?? ''));

        $this->assertEmptyLastPost($board['forumId'], $board);
    }

    public function testDisplayRowRecountsMissingLastTopicLeavesOlder(): void
    {
        $board = $this->twoTopicBoard();
        $this->assertTrue(AP_Forum::deleteTopic($board['newerId'], true, $this->db));
        $this->plantStaleLastPost($board);

        $forum = AP_Forum::getForum($board['forumId'], $this->db);
        $this->assertNotNull($forum);
        $this->assertSame($board['newerId'], (int) $forum->last_topic_id);
        $this->assertNull(AP_Forum::getTopic($board['newerId'], $this->db));

        $row = AP_Forum::forumToDisplayRow($forum, $this->db);
        $last = $row['last_post'] ?? null;
        $this->assertIsArray($last);
        $this->assertSame('Older thread', (string) $last['title']);
        $this->assertLastPostDoesNotPointAt(
            $last,
            $board['newerId'],
            $board['newerPostId'],
            'Newer thread',
            $board['newerSlug']
        );

        $this->assertSameLastPost(
            $board['forumId'],
            $board['olderId'],
            $board['olderPostId'],
            $board['olderPosterId'],
            'Older thread'
        );
    }

    public function testDisplayRowEmptyWhenStalePointerTopicsAreGone(): void
    {
        $board = $this->twoTopicBoard();
        $this->assertTrue(AP_Forum::deleteTopic($board['newerId'], true, $this->db));
        $this->assertTrue(AP_Forum::deleteTopic($board['olderId'], true, $this->db));
        $this->plantStaleLastPost($board);

        $forum = AP_Forum::getForum($board['forumId'], $this->db);
        $this->assertNotNull($forum);

        $row = AP_Forum::forumToDisplayRow($forum, $this->db);
        $this->assertNull($row['last_post']);
        $this->assertSame('', (string) ($row['last_post']['url'] ?? ''));

        $this->assertEmptyLastPost($board['forumId'], $board);
    }

    public function testDisplayRowRecountsStaleUnapprovedTopic(): void
    {
        $board = $this->twoTopicBoard();
        $this->db->update(
            'topics',
            ['topic_approved' => 0],
            ['topic_id' => $board['newerId']]
        );
        $this->plantStaleLastPost($board);

        $forum = AP_Forum::getForum($board['forumId'], $this->db);
        $this->assertNotNull($forum);

        $row = AP_Forum::forumToDisplayRow($forum, $this->db);
        $last = $row['last_post'] ?? null;
        $this->assertIsArray($last);
        $this->assertSame('Older thread', (string) $last['title']);
        $this->assertLastPostDoesNotPointAt(
            $last,
            $board['newerId'],
            $board['newerPostId'],
            'Newer thread',
            $board['newerSlug']
        );

        $this->assertSameLastPost(
            $board['forumId'],
            $board['olderId'],
            $board['olderPostId'],
            $board['olderPosterId'],
            'Older thread'
        );
    }

    public function testIndexDataLastPostNeverPrintsDeletedTopic(): void
    {
        $board = $this->twoTopicBoard();
        $this->db->update(
            'topics',
            ['topic_status' => AP_Forum::TOPIC_STATUS_DELETED],
            ['topic_id' => $board['newerId']]
        );
        $this->plantStaleLastPost($board);

        $index = AP_Forum::getIndexData($this->db);
        $found = null;
        foreach ($index as $cat) {
            foreach ($cat['forums'] ?? [] as $f) {
                if ((int) ($f['id'] ?? 0) === $board['forumId']) {
                    $found = $f;
                    break 2;
                }
            }
        }
        $this->assertIsArray($found);
        $last = $found['last_post'] ?? null;
        $this->assertIsArray($last);
        $this->assertSame('Older thread', (string) $last['title']);
        $this->assertLastPostDoesNotPointAt(
            $last,
            $board['newerId'],
            $board['newerPostId'],
            'Newer thread',
            $board['newerSlug']
        );

        $this->plantStaleLastPost($board);
        $this->db->update(
            'topics',
            ['topic_status' => AP_Forum::TOPIC_STATUS_DELETED],
            ['topic_id' => $board['olderId']]
        );

        $emptyIndex = AP_Forum::getIndexData($this->db);
        $emptyFound = null;
        foreach ($emptyIndex as $cat) {
            foreach ($cat['forums'] ?? [] as $f) {
                if ((int) ($f['id'] ?? 0) === $board['forumId']) {
                    $emptyFound = $f;
                    break 2;
                }
            }
        }
        $this->assertIsArray($emptyFound);
        $this->assertNull($emptyFound['last_post'] ?? null);
        $this->assertSame('', (string) ($emptyFound['last_post']['url'] ?? ''));
        $this->assertSame('', (string) ($emptyFound['last_post']['title'] ?? ''));
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
     *   olderSlug: string,
     *   newerSlug: string,
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
            'olderSlug' => (string) $older->topic_slug,
            'newerSlug' => (string) $newer->topic_slug,
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
        $this->plantArbitraryLastPost(
            $board['forumId'],
            $board['newerPostId'],
            $board['newerId'],
            $board['newerPosterId'],
            $board['newerPostTime']
        );
    }

    private function plantArbitraryLastPost(
        int $forumId,
        int $postId,
        int $topicId,
        int $posterId,
        string $postTime
    ): void {
        $this->db->update('forums', [
            'last_post_id' => $postId,
            'last_poster_id' => $posterId,
            'last_post_time' => $postTime,
            'last_topic_id' => $topicId,
        ], ['forum_id' => $forumId]);
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
        $url = (string) $last['url'];
        $this->assertStringContainsString('#post-' . $postId, $url);
        $this->assertStringContainsString('topic_id=' . $topicId, $url);

        $html = $this->renderLastPostCellHtml($last);
        $this->assertStringContainsString($expectedTitle, $html);
        $this->assertStringContainsString('href=', $html);
        $this->assertStringContainsString('#post-' . $postId, $html);
        $this->assertStringContainsString('topic_id=' . $topicId, $html);
    }

    /**
     * @param array{
     *   olderId?: int,
     *   newerId?: int,
     *   olderPostId?: int,
     *   newerPostId?: int,
     *   olderSlug?: string,
     *   newerSlug?: string
     * }|null $board
     */
    private function assertEmptyLastPost(int $forumId, ?array $board = null): void
    {
        $forum = AP_Forum::getForum($forumId, $this->db);
        $this->assertNotNull($forum);
        $this->assertSame(0, (int) $forum->last_post_id);
        $this->assertSame(0, (int) $forum->last_topic_id);
        $this->assertSame(0, (int) $forum->last_poster_id);
        $this->assertSame(AP_Forum::EMPTY_DATETIME, (string) $forum->last_post_time);

        $row = AP_Forum::forumToDisplayRow($forum, $this->db);
        $this->assertNull($row['last_post']);
        $this->assertSame('', (string) ($row['last_post']['title'] ?? ''));
        $this->assertSame('', (string) ($row['last_post']['url'] ?? ''));

        $html = $this->renderLastPostCellHtml(null);
        $this->assertStringContainsString('No posts', $html);
        $this->assertStringNotContainsString('<a', $html);
        $this->assertStringNotContainsString('href=', $html);

        if ($board !== null) {
            $this->assertLastPostCellHasNoDeadPermalink($html, $board);
            $indexRow = $this->indexForumRow($forumId);
            $this->assertNull($indexRow['last_post'] ?? null);
            $this->assertSame('', (string) ($indexRow['last_post']['title'] ?? ''));
            $this->assertSame('', (string) ($indexRow['last_post']['url'] ?? ''));
            $this->assertLastPostCellHasNoDeadPermalink(
                $this->renderLastPostCellHtml(
                    is_array($indexRow['last_post'] ?? null) ? $indexRow['last_post'] : null
                ),
                $board
            );
        }
    }

    /**
     * @param array{
     *   forumId: int,
     *   olderId: int,
     *   newerId: int,
     *   olderPostId: int,
     *   newerPostId: int,
     *   olderPosterId: int,
     *   newerPosterId: int,
     *   olderSlug: string,
     *   newerSlug: string
     * } $board
     */
    private function assertDeleteNewestThenBothClearsPermalink(bool $force): void
    {
        $board = $this->twoTopicBoard();

        $this->assertTrue(AP_Forum::deleteTopic($board['newerId'], $force, $this->db));
        $this->assertSameLastPost(
            $board['forumId'],
            $board['olderId'],
            $board['olderPostId'],
            $board['olderPosterId'],
            'Older thread'
        );
        $this->assertRemainingLastPostIsNotDeletedTopic($board);

        $this->assertTrue(AP_Forum::deleteTopic($board['olderId'], $force, $this->db));
        $this->assertEmptyLastPost($board['forumId'], $board);
    }

    /**
     * @param array{
     *   forumId: int,
     *   olderId: int,
     *   newerId: int,
     *   olderPostId: int,
     *   newerPostId: int,
     *   olderSlug: string,
     *   newerSlug: string
     * } $board
     */
    private function assertRemainingLastPostIsNotDeletedTopic(array $board): void
    {
        $forum = AP_Forum::getForum($board['forumId'], $this->db);
        $this->assertNotNull($forum);
        $row = AP_Forum::forumToDisplayRow($forum, $this->db);
        $last = $row['last_post'] ?? null;
        $this->assertIsArray($last);
        $this->assertLastPostDoesNotPointAt(
            $last,
            $board['newerId'],
            $board['newerPostId'],
            'Newer thread',
            $board['newerSlug']
        );

        $html = $this->renderLastPostCellHtml($last);
        $this->assertStringContainsString('Older thread', $html);
        $this->assertStringContainsString('href=', $html);
        $this->assertStringContainsString('topic_id=' . $board['olderId'], $html);
        $this->assertStringContainsString('#post-' . $board['olderPostId'], $html);
        $this->assertStringNotContainsString('Newer thread', $html);
        $this->assertStringNotContainsString('topic_id=' . $board['newerId'], $html);
        $this->assertStringNotContainsString('#post-' . $board['newerPostId'], $html);
        if ($board['newerSlug'] !== '') {
            $this->assertStringNotContainsString('topic/' . $board['newerSlug'], $html);
        }

        $indexRow = $this->indexForumRow($board['forumId']);
        $indexLast = $indexRow['last_post'] ?? null;
        $this->assertIsArray($indexLast);
        $this->assertSame('Older thread', (string) $indexLast['title']);
        $this->assertLastPostDoesNotPointAt(
            $indexLast,
            $board['newerId'],
            $board['newerPostId'],
            'Newer thread',
            $board['newerSlug']
        );
    }

    /**
     * Board-index Last Post cell (Agora forum.php: title permalink or empty placeholders).
     *
     * @param array<string, mixed>|null $last
     */
    private function renderLastPostCellHtml(?array $last): string
    {
        if ($last === null) {
            return ap_forum_empty_last_post_html();
        }

        $title = (string) ($last['title'] ?? '');
        $url = (string) ($last['url'] ?? '');
        if ($title !== '' && $url !== '') {
            return '<span class="ap-forum-last-post__title"><a href="'
                . $url . '">' . $title . '</a></span>';
        }
        if ($title !== '') {
            return '<span class="ap-forum-last-post__title">' . $title . '</span>';
        }

        return ap_forum_empty_last_post_html();
    }

    /**
     * @return array<string, mixed>
     */
    private function indexForumRow(int $forumId): array
    {
        $index = AP_Forum::getIndexData($this->db);
        foreach ($index as $cat) {
            foreach ($cat['forums'] ?? [] as $forum) {
                if ((int) ($forum['id'] ?? 0) === $forumId) {
                    $this->assertIsArray($forum);

                    return $forum;
                }
            }
        }
        $this->fail('Forum ' . $forumId . ' missing from board index');
    }

    /**
     * @param array{
     *   olderId?: int,
     *   newerId?: int,
     *   olderPostId?: int,
     *   newerPostId?: int,
     *   olderSlug?: string,
     *   newerSlug?: string
     * } $board
     */
    private function assertLastPostCellHasNoDeadPermalink(string $html, array $board): void
    {
        $this->assertStringNotContainsString('<a', $html);
        $this->assertStringNotContainsString('href=', $html);
        $this->assertStringNotContainsString('Older thread', $html);
        $this->assertStringNotContainsString('Newer thread', $html);
        foreach (['olderId', 'newerId'] as $key) {
            $topicId = (int) ($board[$key] ?? 0);
            if ($topicId > 0) {
                $this->assertStringNotContainsString('topic_id=' . $topicId, $html);
            }
        }
        foreach (['olderPostId', 'newerPostId'] as $key) {
            $postId = (int) ($board[$key] ?? 0);
            if ($postId > 0) {
                $this->assertStringNotContainsString('#post-' . $postId, $html);
            }
        }
        foreach (['olderSlug', 'newerSlug'] as $key) {
            $slug = (string) ($board[$key] ?? '');
            if ($slug !== '') {
                $this->assertStringNotContainsString('topic/' . $slug, $html);
            }
        }
    }

    /**
     * @param array<string, mixed> $last
     */
    private function assertLastPostDoesNotPointAt(
        array $last,
        int $topicId,
        int $postId,
        string $title,
        string $slug = ''
    ): void {
        $this->assertNotSame($title, (string) ($last['title'] ?? ''));
        $this->assertNotSame($topicId, (int) ($last['topic_id'] ?? 0));
        $this->assertNotSame($postId, (int) ($last['post_id'] ?? 0));
        $url = (string) ($last['url'] ?? '');
        $this->assertStringNotContainsString('topic_id=' . $topicId, $url);
        $this->assertStringNotContainsString('#post-' . $postId, $url);
        if ($slug !== '') {
            $this->assertStringNotContainsString('topic/' . $slug, $url);
            $this->assertStringNotContainsString('/topic/' . rawurlencode($slug), $url);
        }
    }
}
