<?php

/**
 * SPEC-minimum split-topic tests: selected posts on the new topic,
 * original keeps at least one post, last-post correct.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Forum;

use AP_DB;
use AP_Forum;
use AP_Forum_Moderation;
use AP_Migrator;
use AP_Roles;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_Forum_Moderation::class)]
final class ForumSplitTopicTest extends TestCase
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
        require_once $this->root . '/ap-includes/class-ap-roles.php';
        require_once $this->root . '/ap-includes/class-ap-forum.php';
        require_once $this->root . '/ap-includes/class-ap-forum-moderation.php';
        require_once $this->root . '/ap-includes/functions.php';

        AP_Roles::flushCache();

        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');

        $migrator = new AP_Migrator($this->db, AP_Migrator::defaultMigrationsPath());
        $migrator->migrate();

        $GLOBALS['apdb'] = $this->db;
        AP_Roles::ensureDefaults($this->db);
    }

    protected function tearDown(): void
    {
        AP_Roles::flushCache();
        unset($GLOBALS['apdb']);
    }

    /**
     * SPEC: selected posts on the new topic (ids kept; retargeted).
     */
    public function testSplitTopicMovesSelectedPostsOntoNewTopic(): void
    {
        $forumId = AP_Forum::insertForum(['forum_name' => 'Split Posts'], $this->db);
        $this->assertGreaterThan(0, $forumId);

        $topicId = AP_Forum::createTopic([
            'forum_id' => $forumId,
            'topic_title' => 'Original thread',
            'content' => 'OP stays',
            'poster_id' => 11,
        ], $this->db);
        $r1 = AP_Forum::createReply([
            'topic_id' => $topicId,
            'content' => 'Move me',
            'poster_id' => 12,
        ], $this->db);
        $r2 = AP_Forum::createReply([
            'topic_id' => $topicId,
            'content' => 'Move me too',
            'poster_id' => 13,
        ], $this->db);
        $stay = AP_Forum::createReply([
            'topic_id' => $topicId,
            'content' => 'Stay behind',
            'poster_id' => 14,
        ], $this->db);
        $this->assertGreaterThan(0, $topicId);
        $this->assertGreaterThan(0, $r1);
        $this->assertGreaterThan(0, $r2);
        $this->assertGreaterThan(0, $stay);

        $opId = (int) (AP_Forum::getTopic($topicId, $this->db)?->first_post_id ?? 0);
        $this->assertGreaterThan(0, $opId);

        $newId = AP_Forum_Moderation::splitTopic($topicId, [$r1, $r2], [
            'title' => 'Split off',
        ], $this->db);
        $this->assertGreaterThan(0, $newId);
        $this->assertNotSame($topicId, $newId);

        $newPosts = AP_Forum::getPosts($newId, ['approved_only' => false], $this->db);
        $this->assertCount(2, $newPosts);
        $newIds = array_map(static fn ($p) => (int) $p->post_id, $newPosts);
        $this->assertContains($r1, $newIds);
        $this->assertContains($r2, $newIds);
        $this->assertNotContains($opId, $newIds);
        $this->assertNotContains($stay, $newIds);
        foreach ($newPosts as $post) {
            $this->assertSame($newId, (int) $post->topic_id);
            $this->assertSame($forumId, (int) $post->forum_id);
        }

        $origPosts = AP_Forum::getPosts($topicId, ['approved_only' => false], $this->db);
        $origIds = array_map(static fn ($p) => (int) $p->post_id, $origPosts);
        $this->assertContains($opId, $origIds);
        $this->assertContains($stay, $origIds);
        $this->assertNotContains($r1, $origIds);
        $this->assertNotContains($r2, $origIds);

        $new = AP_Forum::getTopic($newId, $this->db);
        $this->assertNotNull($new);
        $this->assertSame('Split off', $new->topic_title);
        $this->assertSame($r1, (int) $new->first_post_id);
        $this->assertSame($r2, (int) $new->last_post_id);
        $this->assertSame(1, (int) $new->reply_count);
        $this->assertNotSame(AP_Forum::TOPIC_STATUS_MOVED, (string) $new->topic_status);
    }

    /**
     * SPEC: original keeps ≥1 post; splitting every post is refused.
     */
    public function testSplitTopicKeepsAtLeastOnePostOnOriginal(): void
    {
        $forumId = AP_Forum::insertForum(['forum_name' => 'Split Keep One'], $this->db);
        $topicId = AP_Forum::createTopic([
            'forum_id' => $forumId,
            'topic_title' => 'Must keep a post',
            'content' => 'OP',
            'poster_id' => 21,
        ], $this->db);
        $r1 = AP_Forum::createReply([
            'topic_id' => $topicId,
            'content' => 'Reply one',
            'poster_id' => 22,
        ], $this->db);
        $r2 = AP_Forum::createReply([
            'topic_id' => $topicId,
            'content' => 'Reply two',
            'poster_id' => 23,
        ], $this->db);
        $this->assertGreaterThan(0, $topicId);
        $this->assertGreaterThan(0, $r1);
        $this->assertGreaterThan(0, $r2);

        $opId = (int) (AP_Forum::getTopic($topicId, $this->db)?->first_post_id ?? 0);
        $this->assertGreaterThan(0, $opId);

        $this->assertSame(0, AP_Forum_Moderation::splitTopic($topicId, [$opId, $r1, $r2], [
            'title' => 'Should fail',
        ], $this->db));
        $this->assertCount(3, AP_Forum::getPosts($topicId, ['approved_only' => false], $this->db));
        $this->assertSame(1, $this->topicCount($forumId));

        $newId = AP_Forum_Moderation::splitTopic($topicId, [$r1, $r2], [
            'title' => 'Offshoot',
        ], $this->db);
        $this->assertGreaterThan(0, $newId);

        $origPosts = AP_Forum::getPosts($topicId, ['approved_only' => false], $this->db);
        $this->assertGreaterThanOrEqual(1, count($origPosts));
        $this->assertCount(1, $origPosts);
        $this->assertSame($opId, (int) $origPosts[0]->post_id);
        $this->assertSame($topicId, (int) $origPosts[0]->topic_id);

        $orig = AP_Forum::getTopic($topicId, $this->db);
        $this->assertNotNull($orig);
        $this->assertSame($opId, (int) $orig->first_post_id);
        $this->assertSame($opId, (int) $orig->last_post_id);
        $this->assertSame(0, (int) $orig->reply_count);
        $this->assertNotNull(AP_Forum::getTopic($newId, $this->db));
        $this->assertSame(2, $this->topicCount($forumId));
    }

    /**
     * SPEC: last-post recount after split (same forum, non-last posts, cross forum).
     */
    public function testSplitTopicRefreshesLastPost(): void
    {
        $forumId = AP_Forum::insertForum(['forum_name' => 'Split Last Same'], $this->db);
        $this->assertGreaterThan(0, $forumId);

        $olderId = AP_Forum::createTopic([
            'forum_id' => $forumId,
            'topic_title' => 'Older stay',
            'content' => 'Older OP',
            'poster_id' => 31,
        ], $this->db);
        $sourceId = AP_Forum::createTopic([
            'forum_id' => $forumId,
            'topic_title' => 'Split source',
            'content' => 'Source OP',
            'poster_id' => 32,
        ], $this->db);
        $r1 = AP_Forum::createReply([
            'topic_id' => $sourceId,
            'content' => 'Middle',
            'poster_id' => 33,
        ], $this->db);
        $r2 = AP_Forum::createReply([
            'topic_id' => $sourceId,
            'content' => 'Newest',
            'poster_id' => 34,
        ], $this->db);
        $this->assertGreaterThan(0, $olderId);
        $this->assertGreaterThan(0, $sourceId);
        $this->assertGreaterThan(0, $r1);
        $this->assertGreaterThan(0, $r2);

        $before = AP_Forum::getForum($forumId, $this->db);
        $this->assertSame($sourceId, (int) ($before?->last_topic_id ?? 0));
        $this->assertSame($r2, (int) ($before?->last_post_id ?? 0));

        $newId = AP_Forum_Moderation::splitTopic($sourceId, [$r1, $r2], [
            'title' => 'Newest offshoot',
        ], $this->db);
        $this->assertGreaterThan(0, $newId);
        $this->assertSameLastPost($forumId, $newId, $r2, 34, 'Newest offshoot');
        $this->assertLastPostDoesNotPointAt($forumId, $olderId, 'Older stay');

        $keepNewestForum = AP_Forum::insertForum(['forum_name' => 'Split Last Keep'], $this->db);
        $keepSourceId = AP_Forum::createTopic([
            'forum_id' => $keepNewestForum,
            'topic_title' => 'Keep newest here',
            'content' => 'Keep OP',
            'poster_id' => 41,
        ], $this->db);
        $mid = AP_Forum::createReply([
            'topic_id' => $keepSourceId,
            'content' => 'Split this mid post',
            'poster_id' => 42,
        ], $this->db);
        $newest = AP_Forum::createReply([
            'topic_id' => $keepSourceId,
            'content' => 'Stays as last',
            'poster_id' => 43,
        ], $this->db);
        $this->assertGreaterThan(0, $keepSourceId);
        $this->assertGreaterThan(0, $mid);
        $this->assertGreaterThan(0, $newest);

        $offshootId = AP_Forum_Moderation::splitTopic($keepSourceId, [$mid], [
            'title' => 'Mid offshoot',
        ], $this->db);
        $this->assertGreaterThan(0, $offshootId);
        $this->assertSameLastPost($keepNewestForum, $keepSourceId, $newest, 43, 'Keep newest here');
        $this->assertLastPostDoesNotPointAt($keepNewestForum, $offshootId, 'Mid offshoot');

        $sourceForumId = AP_Forum::insertForum(['forum_name' => 'Split Last Src'], $this->db);
        $destForumId = AP_Forum::insertForum(['forum_name' => 'Split Last Dest'], $this->db);
        $this->assertGreaterThan(0, $sourceForumId);
        $this->assertGreaterThan(0, $destForumId);

        $stayId = AP_Forum::createTopic([
            'forum_id' => $sourceForumId,
            'topic_title' => 'Stays on source',
            'content' => 'Stay OP',
            'poster_id' => 51,
        ], $this->db);
        $crossSourceId = AP_Forum::createTopic([
            'forum_id' => $sourceForumId,
            'topic_title' => 'Cross split source',
            'content' => 'Cross OP',
            'poster_id' => 52,
        ], $this->db);
        $crossReply = AP_Forum::createReply([
            'topic_id' => $crossSourceId,
            'content' => 'Cross newest',
            'poster_id' => 53,
        ], $this->db);
        $this->assertGreaterThan(0, $stayId);
        $this->assertGreaterThan(0, $crossSourceId);
        $this->assertGreaterThan(0, $crossReply);

        $crossOpId = (int) (AP_Forum::getTopic($crossSourceId, $this->db)?->first_post_id ?? 0);
        $this->assertGreaterThan(0, $crossOpId);
        $sourceBefore = AP_Forum::getForum($sourceForumId, $this->db);
        $this->assertSame($crossSourceId, (int) ($sourceBefore?->last_topic_id ?? 0));
        $this->assertSame($crossReply, (int) ($sourceBefore?->last_post_id ?? 0));

        $crossNewId = AP_Forum_Moderation::splitTopic($crossSourceId, [$crossReply], [
            'title' => 'Cross offshoot',
            'forum_id' => $destForumId,
        ], $this->db);
        $this->assertGreaterThan(0, $crossNewId);

        $this->assertSameLastPost($sourceForumId, $crossSourceId, $crossOpId, 52, 'Cross split source');
        $this->assertLastPostDoesNotPointAt($sourceForumId, $crossNewId, 'Cross offshoot');
        $this->assertLastPostDoesNotPointAt($sourceForumId, $stayId, 'Stays on source');
        $this->assertSameLastPost($destForumId, $crossNewId, $crossReply, 53, 'Cross offshoot');
        $this->assertLastPostDoesNotPointAt($destForumId, $crossSourceId, 'Cross split source');
        $this->assertEmptyLastPostDoesNotApply($destForumId);
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
    }

    private function assertLastPostDoesNotPointAt(int $forumId, int $otherTopicId, string $otherTitle): void
    {
        $forum = AP_Forum::getForum($forumId, $this->db);
        $this->assertNotNull($forum);
        $this->assertNotSame($otherTopicId, (int) $forum->last_topic_id);

        $row = AP_Forum::forumToDisplayRow($forum, $this->db);
        $last = $row['last_post'] ?? null;
        $this->assertIsArray($last);
        $this->assertNotSame($otherTopicId, (int) ($last['topic_id'] ?? 0));
        $this->assertNotSame($otherTitle, (string) ($last['title'] ?? ''));
        $url = (string) ($last['url'] ?? '');
        $this->assertStringNotContainsString('topic_id=' . $otherTopicId, $url);
    }

    private function assertEmptyLastPostDoesNotApply(int $forumId): void
    {
        $forum = AP_Forum::getForum($forumId, $this->db);
        $this->assertNotNull($forum);
        $this->assertGreaterThan(0, (int) $forum->last_post_id);
        $this->assertGreaterThan(0, (int) $forum->last_topic_id);
        $row = AP_Forum::forumToDisplayRow($forum, $this->db);
        $this->assertIsArray($row['last_post'] ?? null);
        $this->assertNotSame('', (string) ($row['last_post']['url'] ?? ''));
        $this->assertNotSame('', (string) ($row['last_post']['title'] ?? ''));
    }

    private function topicCount(int $forumId): int
    {
        return (int) $this->db->getVar(
            'SELECT COUNT(*) FROM '
            . $this->db->quoteIdentifier($this->db->table('topics'))
            . ' WHERE ' . $this->db->quoteIdentifier('forum_id') . ' = ?',
            [$forumId]
        );
    }
}
