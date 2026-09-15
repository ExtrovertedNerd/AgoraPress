<?php

/**
 * SPEC-minimum merge-topic tests: posts on target, source gone,
 * subscriptions retargeted, last-post correct.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Forum;

use AP_DB;
use AP_Forum;
use AP_Forum_Moderation;
use AP_Forum_Notify;
use AP_Migrator;
use AP_Roles;
use AP_User;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_Forum_Moderation::class)]
final class ForumMergeTopicTest extends TestCase
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
        require_once $this->root . '/ap-includes/class-ap-forum-notify.php';
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
     * SPEC: Merge posts on target (ids kept; source posts retargeted).
     */
    public function testMergeTopicsMovesPostsOntoTarget(): void
    {
        $sourceForumId = AP_Forum::insertForum(['forum_name' => 'Merge Posts Source'], $this->db);
        $targetForumId = AP_Forum::insertForum(['forum_name' => 'Merge Posts Target'], $this->db);
        $this->assertGreaterThan(0, $sourceForumId);
        $this->assertGreaterThan(0, $targetForumId);

        $targetId = AP_Forum::createTopic([
            'forum_id' => $targetForumId,
            'topic_title' => 'Keep as target',
            'content' => 'Target OP',
            'poster_id' => 41,
        ], $this->db);
        $sourceId = AP_Forum::createTopic([
            'forum_id' => $sourceForumId,
            'topic_title' => 'Absorb me',
            'content' => 'Source OP',
            'poster_id' => 42,
        ], $this->db);
        $sourceReplyId = AP_Forum::createReply([
            'topic_id' => $sourceId,
            'content' => 'Source reply',
            'poster_id' => 43,
        ], $this->db);
        $this->assertGreaterThan(0, $targetId);
        $this->assertGreaterThan(0, $sourceId);
        $this->assertGreaterThan(0, $sourceReplyId);

        $targetOpId = (int) (AP_Forum::getTopic($targetId, $this->db)?->first_post_id ?? 0);
        $sourceOpId = (int) (AP_Forum::getTopic($sourceId, $this->db)?->first_post_id ?? 0);
        $this->assertGreaterThan(0, $targetOpId);
        $this->assertGreaterThan(0, $sourceOpId);

        $this->assertTrue(AP_Forum_Moderation::mergeTopics($sourceId, $targetId, 0, $this->db));

        $posts = AP_Forum::getPosts($targetId, ['approved_only' => false], $this->db);
        $this->assertCount(3, $posts);
        $ids = array_map(static fn ($p) => (int) $p->post_id, $posts);
        $this->assertContains($targetOpId, $ids);
        $this->assertContains($sourceOpId, $ids);
        $this->assertContains($sourceReplyId, $ids);
        foreach ($posts as $post) {
            $this->assertSame($targetId, (int) $post->topic_id);
            $this->assertSame($targetForumId, (int) $post->forum_id);
        }

        $this->assertSame([], AP_Forum::getPosts($sourceId, ['approved_only' => false], $this->db));
        $this->assertSame(0, $this->postCountForTopic($sourceId));

        $target = AP_Forum::getTopic($targetId, $this->db);
        $this->assertSame($targetOpId, (int) ($target?->first_post_id ?? 0));
        $this->assertSame($sourceReplyId, (int) ($target?->last_post_id ?? 0));
        $this->assertSame(2, (int) ($target?->reply_count ?? -1));
    }

    /**
     * SPEC: Merge source gone (no shadow / moved stub).
     */
    public function testMergeTopicsRemovesSourceTopic(): void
    {
        $forumId = AP_Forum::insertForum(['forum_name' => 'Merge Source Gone'], $this->db);
        $this->assertGreaterThan(0, $forumId);

        $targetId = AP_Forum::createTopic([
            'forum_id' => $forumId,
            'topic_title' => 'Keep',
            'content' => 'Target OP',
        ], $this->db);
        $sourceId = AP_Forum::createTopic([
            'forum_id' => $forumId,
            'topic_title' => 'Absorb',
            'content' => 'Source OP',
        ], $this->db);
        $this->assertGreaterThan(0, $targetId);
        $this->assertGreaterThan(0, $sourceId);

        $sourceSlug = (string) (AP_Forum::getTopic($sourceId, $this->db)?->topic_slug ?? '');
        $this->assertNotSame('', $sourceSlug);

        $this->assertTrue(AP_Forum_Moderation::mergeTopics($sourceId, $targetId, 0, $this->db));

        $this->assertNull(AP_Forum::getTopic($sourceId, $this->db));
        $this->assertNull($this->topicRow($sourceId));
        $this->assertSame(0, $this->topicCountByStatus(AP_Forum::TOPIC_STATUS_MOVED));
        $this->assertSame(0, $this->postCountForTopic($sourceId));

        $target = AP_Forum::getTopic($targetId, $this->db);
        $this->assertNotNull($target);
        $this->assertSame($targetId, (int) $target->topic_id);
        $this->assertSame($forumId, (int) $target->forum_id);
        $this->assertNotSame(AP_Forum::TOPIC_STATUS_MOVED, (string) ($target->topic_status ?? ''));
        $this->assertNotSame($sourceSlug, (string) ($target->topic_slug ?? ''));
    }

    /**
     * SPEC: Merge subscriptions retargeted (duplicates dropped).
     */
    public function testMergeTopicsRetargetsSubscriptions(): void
    {
        $forumId = AP_Forum::insertForum(['forum_name' => 'Merge Subs'], $this->db);
        $sourceId = AP_Forum::createTopic([
            'forum_id' => $forumId,
            'topic_title' => 'Source watches',
            'content' => 'Source OP',
        ], $this->db);
        $targetId = AP_Forum::createTopic([
            'forum_id' => $forumId,
            'topic_title' => 'Target watches',
            'content' => 'Target OP',
        ], $this->db);
        $otherId = AP_Forum::createTopic([
            'forum_id' => $forumId,
            'topic_title' => 'Unrelated watches',
            'content' => 'Other OP',
        ], $this->db);
        $this->assertGreaterThan(0, $sourceId);
        $this->assertGreaterThan(0, $targetId);
        $this->assertGreaterThan(0, $otherId);

        $dupId = $this->createUser('merge_spec_dup', 'merge_spec_dup@example.test');
        $srcOnlyId = $this->createUser('merge_spec_src', 'merge_spec_src@example.test');
        $tgtOnlyId = $this->createUser('merge_spec_tgt', 'merge_spec_tgt@example.test');
        $otherUserId = $this->createUser('merge_spec_other', 'merge_spec_other@example.test');
        $this->assertTrue(AP_Forum_Notify::subscribe($dupId, $sourceId, $this->db));
        $this->assertTrue(AP_Forum_Notify::subscribe($dupId, $targetId, $this->db));
        $this->assertTrue(AP_Forum_Notify::subscribe($srcOnlyId, $sourceId, $this->db));
        $this->assertTrue(AP_Forum_Notify::subscribe($tgtOnlyId, $targetId, $this->db));
        $this->assertTrue(AP_Forum_Notify::subscribe($otherUserId, $otherId, $this->db));

        $this->assertTrue(AP_Forum_Moderation::mergeTopics($sourceId, $targetId, 0, $this->db));

        $this->assertFalse(AP_Forum_Notify::isSubscribed($dupId, $sourceId, $this->db));
        $this->assertTrue(AP_Forum_Notify::isSubscribed($dupId, $targetId, $this->db));
        $this->assertTrue(AP_Forum_Notify::isSubscribed($srcOnlyId, $targetId, $this->db));
        $this->assertFalse(AP_Forum_Notify::isSubscribed($srcOnlyId, $sourceId, $this->db));
        $this->assertTrue(AP_Forum_Notify::isSubscribed($tgtOnlyId, $targetId, $this->db));
        $this->assertTrue(AP_Forum_Notify::isSubscribed($otherUserId, $otherId, $this->db));
        $this->assertFalse(AP_Forum_Notify::isSubscribed($otherUserId, $targetId, $this->db));
        $this->assertSame(0, $this->subscriptionCount($sourceId));
        $this->assertSame(3, $this->subscriptionCount($targetId));
        $this->assertSame(1, $this->subscriptionCount($otherId));
        $this->assertSame(0, $this->duplicateSubscriptionUserCount($targetId));
        $this->assertSame(0, $this->usersWatchingBoth($sourceId, $targetId));
    }

    /**
     * SPEC: Merge last-post correct (same forum, cross-forum, empty source board).
     */
    public function testMergeTopicsRefreshesLastPost(): void
    {
        $forumId = AP_Forum::insertForum(['forum_name' => 'Merge Last Same'], $this->db);
        $this->assertGreaterThan(0, $forumId);

        $targetId = AP_Forum::createTopic([
            'forum_id' => $forumId,
            'topic_title' => 'Older keep',
            'content' => 'Older OP',
            'poster_id' => 51,
        ], $this->db);
        $sourceId = AP_Forum::createTopic([
            'forum_id' => $forumId,
            'topic_title' => 'Newer absorb',
            'content' => 'Newer OP',
            'poster_id' => 52,
        ], $this->db);
        $sourceReplyId = AP_Forum::createReply([
            'topic_id' => $sourceId,
            'content' => 'Newest reply',
            'poster_id' => 53,
        ], $this->db);
        $this->assertGreaterThan(0, $targetId);
        $this->assertGreaterThan(0, $sourceId);
        $this->assertGreaterThan(0, $sourceReplyId);

        $source = AP_Forum::getTopic($sourceId, $this->db);
        $this->assertNotNull($source);
        $sourceSlug = (string) $source->topic_slug;
        $before = AP_Forum::getForum($forumId, $this->db);
        $this->assertSame($sourceId, (int) ($before?->last_topic_id ?? 0));
        $this->assertSame($sourceReplyId, (int) ($before?->last_post_id ?? 0));

        $this->assertTrue(AP_Forum_Moderation::mergeTopics($sourceId, $targetId, 0, $this->db));

        $this->assertSameLastPost($forumId, $targetId, $sourceReplyId, 53, 'Older keep');
        $this->assertLastPostDoesNotPointAt(
            $forumId,
            $sourceId,
            $sourceReplyId,
            'Newer absorb',
            $sourceSlug
        );

        $sourceForumId = AP_Forum::insertForum(['forum_name' => 'Merge Last Src Board'], $this->db);
        $targetForumId = AP_Forum::insertForum(['forum_name' => 'Merge Last Tgt Board'], $this->db);
        $this->assertGreaterThan(0, $sourceForumId);
        $this->assertGreaterThan(0, $targetForumId);

        $stayId = AP_Forum::createTopic([
            'forum_id' => $sourceForumId,
            'topic_title' => 'Stays on source',
            'content' => 'Stay OP',
            'poster_id' => 61,
        ], $this->db);
        $crossTargetId = AP_Forum::createTopic([
            'forum_id' => $targetForumId,
            'topic_title' => 'Cross keep',
            'content' => 'Cross target OP',
            'poster_id' => 62,
        ], $this->db);
        $crossSourceId = AP_Forum::createTopic([
            'forum_id' => $sourceForumId,
            'topic_title' => 'Cross absorb',
            'content' => 'Cross source OP',
            'poster_id' => 63,
        ], $this->db);
        $crossReplyId = AP_Forum::createReply([
            'topic_id' => $crossSourceId,
            'content' => 'Cross newest',
            'poster_id' => 64,
        ], $this->db);
        $this->assertGreaterThan(0, $stayId);
        $this->assertGreaterThan(0, $crossTargetId);
        $this->assertGreaterThan(0, $crossSourceId);
        $this->assertGreaterThan(0, $crossReplyId);

        $stayPostId = (int) (AP_Forum::getTopic($stayId, $this->db)?->last_post_id ?? 0);
        $this->assertGreaterThan(0, $stayPostId);
        $crossSource = AP_Forum::getTopic($crossSourceId, $this->db);
        $this->assertNotNull($crossSource);
        $crossSlug = (string) $crossSource->topic_slug;

        $sourceBefore = AP_Forum::getForum($sourceForumId, $this->db);
        $this->assertSame($crossSourceId, (int) ($sourceBefore?->last_topic_id ?? 0));
        $this->assertSame($crossReplyId, (int) ($sourceBefore?->last_post_id ?? 0));

        $this->assertTrue(
            AP_Forum_Moderation::mergeTopics($crossSourceId, $crossTargetId, 0, $this->db)
        );

        $this->assertSameLastPost($sourceForumId, $stayId, $stayPostId, 61, 'Stays on source');
        $this->assertLastPostDoesNotPointAt(
            $sourceForumId,
            $crossSourceId,
            $crossReplyId,
            'Cross absorb',
            $crossSlug
        );
        $this->assertSameLastPost($targetForumId, $crossTargetId, $crossReplyId, 64, 'Cross keep');
        $this->assertLastPostDoesNotPointAt(
            $targetForumId,
            $crossSourceId,
            $crossReplyId,
            'Cross absorb',
            $crossSlug
        );

        $emptySourceId = AP_Forum::insertForum(['forum_name' => 'Merge Last Empty Src'], $this->db);
        $emptyTargetId = AP_Forum::insertForum(['forum_name' => 'Merge Last Empty Tgt'], $this->db);
        $this->assertGreaterThan(0, $emptySourceId);
        $this->assertGreaterThan(0, $emptyTargetId);

        $emptyKeepId = AP_Forum::createTopic([
            'forum_id' => $emptyTargetId,
            'topic_title' => 'Empty keep',
            'content' => 'Empty target OP',
            'poster_id' => 71,
        ], $this->db);
        $emptyAbsorbId = AP_Forum::createTopic([
            'forum_id' => $emptySourceId,
            'topic_title' => 'Empty absorb',
            'content' => 'Empty source OP',
            'poster_id' => 72,
        ], $this->db);
        $emptyReplyId = AP_Forum::createReply([
            'topic_id' => $emptyAbsorbId,
            'content' => 'Empty newest',
            'poster_id' => 73,
        ], $this->db);
        $this->assertGreaterThan(0, $emptyKeepId);
        $this->assertGreaterThan(0, $emptyAbsorbId);
        $this->assertGreaterThan(0, $emptyReplyId);

        $emptySlug = (string) (AP_Forum::getTopic($emptyAbsorbId, $this->db)?->topic_slug ?? '');
        $this->assertTrue(
            AP_Forum_Moderation::mergeTopics($emptyAbsorbId, $emptyKeepId, 0, $this->db)
        );

        $this->assertEmptyLastPost($emptySourceId);
        $this->assertSameLastPost($emptyTargetId, $emptyKeepId, $emptyReplyId, 73, 'Empty keep');
        $this->assertLastPostDoesNotPointAt(
            $emptyTargetId,
            $emptyAbsorbId,
            $emptyReplyId,
            'Empty absorb',
            $emptySlug
        );
        $emptySourceRow = AP_Forum::forumToDisplayRow(
            AP_Forum::getForum($emptySourceId, $this->db),
            $this->db
        );
        $this->assertNull($emptySourceRow['last_post'] ?? null);
        $this->assertSame('', (string) ($emptySourceRow['last_post']['url'] ?? ''));
        $this->assertSame('', (string) ($emptySourceRow['last_post']['title'] ?? ''));
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

    private function assertLastPostDoesNotPointAt(
        int $forumId,
        int $goneTopicId,
        int $gonePostId,
        string $goneTitle,
        string $goneSlug
    ): void {
        $forum = AP_Forum::getForum($forumId, $this->db);
        $this->assertNotNull($forum);
        $this->assertNotSame($goneTopicId, (int) $forum->last_topic_id);

        $row = AP_Forum::forumToDisplayRow($forum, $this->db);
        $last = $row['last_post'] ?? null;
        if ($last === null) {
            $this->assertSame('', (string) ($row['last_post']['title'] ?? ''));
            $this->assertSame('', (string) ($row['last_post']['url'] ?? ''));

            return;
        }

        $this->assertNotSame($goneTopicId, (int) ($last['topic_id'] ?? 0));
        $this->assertNotSame($goneTitle, (string) ($last['title'] ?? ''));
        $url = (string) ($last['url'] ?? '');
        $this->assertStringNotContainsString('topic_id=' . $goneTopicId, $url);
        if ($goneSlug !== '') {
            $this->assertStringNotContainsString('topic/' . $goneSlug, $url);
        }
        if ((int) ($last['post_id'] ?? 0) === $gonePostId) {
            $this->assertSame((int) $forum->last_topic_id, (int) ($last['topic_id'] ?? 0));
            $this->assertNotSame($goneTopicId, (int) ($last['topic_id'] ?? 0));
        }
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
        $this->assertSame('', (string) ($row['last_post']['title'] ?? ''));
        $this->assertSame('', (string) ($row['last_post']['url'] ?? ''));
    }

    private function postCountForTopic(int $topicId): int
    {
        return (int) $this->db->getVar(
            'SELECT COUNT(*) FROM '
            . $this->db->quoteIdentifier($this->db->table('forum_posts'))
            . ' WHERE ' . $this->db->quoteIdentifier('topic_id') . ' = ?',
            [$topicId]
        );
    }

    private function topicRow(int $topicId): ?object
    {
        $row = $this->db->getRow(
            'SELECT * FROM '
            . $this->db->quoteIdentifier($this->db->table('topics'))
            . ' WHERE ' . $this->db->quoteIdentifier('topic_id') . ' = ?',
            [$topicId]
        );

        return is_object($row) ? $row : null;
    }

    private function topicCountByStatus(string $status): int
    {
        return (int) $this->db->getVar(
            'SELECT COUNT(*) FROM '
            . $this->db->quoteIdentifier($this->db->table('topics'))
            . ' WHERE ' . $this->db->quoteIdentifier('topic_status') . ' = ?',
            [$status]
        );
    }

    private function subscriptionCount(int $topicId): int
    {
        return (int) $this->db->getVar(
            'SELECT COUNT(*) FROM '
            . $this->db->quoteIdentifier($this->db->table('topic_subscriptions'))
            . ' WHERE ' . $this->db->quoteIdentifier('topic_id') . ' = ?',
            [$topicId]
        );
    }

    private function duplicateSubscriptionUserCount(int $topicId): int
    {
        $table = $this->db->quoteIdentifier($this->db->table('topic_subscriptions'));
        $userCol = $this->db->quoteIdentifier('user_id');
        $topicCol = $this->db->quoteIdentifier('topic_id');
        $n = $this->db->getVar(
            'SELECT COUNT(*) FROM ('
            . 'SELECT ' . $userCol . ' FROM ' . $table
            . ' WHERE ' . $topicCol . ' = ?'
            . ' GROUP BY ' . $userCol . ' HAVING COUNT(*) > 1'
            . ') AS ap_dup_subs',
            [$topicId]
        );

        return (int) $n;
    }

    private function usersWatchingBoth(int $sourceTopicId, int $targetTopicId): int
    {
        $table = $this->db->quoteIdentifier($this->db->table('topic_subscriptions'));
        $userCol = $this->db->quoteIdentifier('user_id');
        $topicCol = $this->db->quoteIdentifier('topic_id');
        $n = $this->db->getVar(
            'SELECT COUNT(*) FROM ' . $table . ' AS s'
            . ' INNER JOIN ' . $table . ' AS t ON s.' . $userCol . ' = t.' . $userCol
            . ' WHERE s.' . $topicCol . ' = ? AND t.' . $topicCol . ' = ?',
            [$sourceTopicId, $targetTopicId]
        );

        return (int) $n;
    }

    private function createUser(string $login, string $email): int
    {
        $created = AP_User::create([
            'user_login' => $login,
            'user_email' => $email,
            'user_pass' => 'Password123!',
            'display_name' => $login,
            'role' => 'subscriber',
        ], $this->db);
        $this->assertTrue($created['ok'] ?? false, implode('; ', $created['errors'] ?? ['create failed']));
        $id = (int) ($created['id'] ?? 0);
        $this->assertGreaterThan(0, $id);

        return $id;
    }
}
