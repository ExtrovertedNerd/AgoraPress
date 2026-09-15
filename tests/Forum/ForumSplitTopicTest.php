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
        $this->assertSame(4, $this->postCountForTopic($topicId));

        $newId = AP_Forum_Moderation::splitTopic($topicId, [$r1, $r2], [
            'title' => 'Split off',
        ], $this->db);
        $this->assertGreaterThan(0, $newId);
        $this->assertNotSame($topicId, $newId);

        $newPosts = AP_Forum::getPosts($newId, ['approved_only' => false], $this->db);
        $this->assertCount(2, $newPosts);
        $this->assertSame(2, $this->postCountForTopic($newId));
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
        $this->assertCount(2, $origPosts);
        $this->assertSame(2, $this->postCountForTopic($topicId));
        $origIds = array_map(static fn ($p) => (int) $p->post_id, $origPosts);
        $this->assertContains($opId, $origIds);
        $this->assertContains($stay, $origIds);
        $this->assertNotContains($r1, $origIds);
        $this->assertNotContains($r2, $origIds);
        foreach ($origPosts as $post) {
            $this->assertSame($topicId, (int) $post->topic_id);
            $this->assertSame($forumId, (int) $post->forum_id);
        }

        $this->assertSame(4, $this->postCountForTopic($topicId) + $this->postCountForTopic($newId));
        $this->assertSame(0, $this->topicCountByStatus(AP_Forum::TOPIC_STATUS_MOVED));

        $orig = AP_Forum::getTopic($topicId, $this->db);
        $this->assertNotNull($orig);
        $this->assertSame($opId, (int) $orig->first_post_id);
        $this->assertSame($stay, (int) $orig->last_post_id);
        $this->assertSame(1, (int) $orig->reply_count);
        $this->assertSame('Original thread', (string) $orig->topic_title);

        $new = AP_Forum::getTopic($newId, $this->db);
        $this->assertNotNull($new);
        $this->assertSame('Split off', $new->topic_title);
        $this->assertSame($r1, (int) $new->first_post_id);
        $this->assertSame($r2, (int) $new->last_post_id);
        $this->assertSame(1, (int) $new->reply_count);
        $this->assertNotSame(AP_Forum::TOPIC_STATUS_MOVED, (string) $new->topic_status);
        $this->assertNotSame((string) $orig->topic_slug, (string) $new->topic_slug);
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

        $this->assertSame(0, AP_Forum_Moderation::splitTopic($topicId, [], [
            'title' => 'Should fail empty',
        ], $this->db));
        $this->assertSame(0, AP_Forum_Moderation::splitTopic($topicId, [0, -1], [
            'title' => 'Should fail junk',
        ], $this->db));
        $this->assertSame(0, AP_Forum_Moderation::splitTopic($topicId, [$opId, $r1, $r2], [
            'title' => 'Should fail',
        ], $this->db));
        $this->assertCount(3, AP_Forum::getPosts($topicId, ['approved_only' => false], $this->db));
        $this->assertSame(1, $this->topicCount($forumId));

        $soloForumId = AP_Forum::insertForum(['forum_name' => 'Split Solo'], $this->db);
        $soloId = AP_Forum::createTopic([
            'forum_id' => $soloForumId,
            'topic_title' => 'Only the OP',
            'content' => 'Solo OP',
            'poster_id' => 24,
        ], $this->db);
        $this->assertGreaterThan(0, $soloId);
        $soloOpId = (int) (AP_Forum::getTopic($soloId, $this->db)?->first_post_id ?? 0);
        $this->assertGreaterThan(0, $soloOpId);
        $this->assertSame(0, AP_Forum_Moderation::splitTopic($soloId, [$soloOpId], [
            'title' => 'Cannot empty a single-post topic',
        ], $this->db));
        $this->assertCount(1, AP_Forum::getPosts($soloId, ['approved_only' => false], $this->db));
        $this->assertSame(1, $this->topicCount($soloForumId));
        $this->assertNotNull(AP_Forum::getTopic($soloId, $this->db));

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

        $opForumId = AP_Forum::insertForum(['forum_name' => 'Split Keep Replies'], $this->db);
        $opSourceId = AP_Forum::createTopic([
            'forum_id' => $opForumId,
            'topic_title' => 'OP moves away',
            'content' => 'Opening that leaves',
            'poster_id' => 25,
        ], $this->db);
        $keepA = AP_Forum::createReply([
            'topic_id' => $opSourceId,
            'content' => 'Keep A',
            'poster_id' => 26,
        ], $this->db);
        $keepB = AP_Forum::createReply([
            'topic_id' => $opSourceId,
            'content' => 'Keep B',
            'poster_id' => 27,
        ], $this->db);
        $this->assertGreaterThan(0, $opSourceId);
        $this->assertGreaterThan(0, $keepA);
        $this->assertGreaterThan(0, $keepB);
        $movedOpId = (int) (AP_Forum::getTopic($opSourceId, $this->db)?->first_post_id ?? 0);
        $this->assertGreaterThan(0, $movedOpId);

        $opOffshootId = AP_Forum_Moderation::splitTopic($opSourceId, [$movedOpId], [
            'title' => 'Opening offshoot',
        ], $this->db);
        $this->assertGreaterThan(0, $opOffshootId);

        $kept = AP_Forum::getPosts($opSourceId, ['approved_only' => false], $this->db);
        $this->assertGreaterThanOrEqual(1, count($kept));
        $this->assertCount(2, $kept);
        $keptIds = array_map(static fn ($p) => (int) $p->post_id, $kept);
        $this->assertContains($keepA, $keptIds);
        $this->assertContains($keepB, $keptIds);
        $this->assertNotContains($movedOpId, $keptIds);

        $keptTopic = AP_Forum::getTopic($opSourceId, $this->db);
        $this->assertNotNull($keptTopic);
        $this->assertSame($keepA, (int) $keptTopic->first_post_id);
        $this->assertSame($keepB, (int) $keptTopic->last_post_id);
        $this->assertSame(1, (int) $keptTopic->reply_count);

        $opOffshoot = AP_Forum::getTopic($opOffshootId, $this->db);
        $this->assertNotNull($opOffshoot);
        $this->assertSame($movedOpId, (int) $opOffshoot->first_post_id);
        $this->assertSame($movedOpId, (int) $opOffshoot->last_post_id);
        $this->assertCount(1, AP_Forum::getPosts($opOffshootId, ['approved_only' => false], $this->db));
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

        $older = AP_Forum::getTopic($olderId, $this->db);
        $source = AP_Forum::getTopic($sourceId, $this->db);
        $this->assertNotNull($older);
        $this->assertNotNull($source);
        $olderSlug = (string) $older->topic_slug;
        $sourceSlug = (string) $source->topic_slug;
        $sourceOpId = (int) $source->first_post_id;
        $this->assertGreaterThan(0, $sourceOpId);

        $before = AP_Forum::getForum($forumId, $this->db);
        $this->assertSame($sourceId, (int) ($before?->last_topic_id ?? 0));
        $this->assertSame($r2, (int) ($before?->last_post_id ?? 0));

        $newId = AP_Forum_Moderation::splitTopic($sourceId, [$r1, $r2], [
            'title' => 'Newest offshoot',
        ], $this->db);
        $this->assertGreaterThan(0, $newId);

        $origAfter = AP_Forum::getTopic($sourceId, $this->db);
        $this->assertNotNull($origAfter);
        $this->assertSame($sourceOpId, (int) $origAfter->last_post_id);
        $this->assertSame(0, (int) $origAfter->reply_count);

        $this->assertSameLastPost($forumId, $newId, $r2, 34, 'Newest offshoot');
        $this->assertLastPostDoesNotPointAt($forumId, $olderId, (int) $older->last_post_id, 'Older stay', $olderSlug);
        $this->assertLastPostDoesNotPointAt($forumId, $sourceId, $sourceOpId, 'Split source', $sourceSlug);

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

        $keepAfter = AP_Forum::getTopic($keepSourceId, $this->db);
        $this->assertNotNull($keepAfter);
        $this->assertSame($newest, (int) $keepAfter->last_post_id);

        $offshoot = AP_Forum::getTopic($offshootId, $this->db);
        $this->assertNotNull($offshoot);
        $offshootSlug = (string) $offshoot->topic_slug;

        $this->assertSameLastPost($keepNewestForum, $keepSourceId, $newest, 43, 'Keep newest here');
        $this->assertLastPostDoesNotPointAt(
            $keepNewestForum,
            $offshootId,
            $mid,
            'Mid offshoot',
            $offshootSlug
        );

        $sourceForumId = AP_Forum::insertForum(['forum_name' => 'Split Last Src'], $this->db);
        $destForumId = AP_Forum::insertForum(['forum_name' => 'Split Last Dest'], $this->db);
        $this->assertGreaterThan(0, $sourceForumId);
        $this->assertGreaterThan(0, $destForumId);
        $this->assertEmptyLastPost($destForumId);

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

        $stay = AP_Forum::getTopic($stayId, $this->db);
        $crossSource = AP_Forum::getTopic($crossSourceId, $this->db);
        $this->assertNotNull($stay);
        $this->assertNotNull($crossSource);
        $staySlug = (string) $stay->topic_slug;
        $crossSlug = (string) $crossSource->topic_slug;
        $crossOpId = (int) $crossSource->first_post_id;
        $this->assertGreaterThan(0, $crossOpId);
        $sourceBefore = AP_Forum::getForum($sourceForumId, $this->db);
        $this->assertSame($crossSourceId, (int) ($sourceBefore?->last_topic_id ?? 0));
        $this->assertSame($crossReply, (int) ($sourceBefore?->last_post_id ?? 0));

        $crossNewId = AP_Forum_Moderation::splitTopic($crossSourceId, [$crossReply], [
            'title' => 'Cross offshoot',
            'forum_id' => $destForumId,
        ], $this->db);
        $this->assertGreaterThan(0, $crossNewId);

        $crossNew = AP_Forum::getTopic($crossNewId, $this->db);
        $this->assertNotNull($crossNew);
        $crossNewSlug = (string) $crossNew->topic_slug;
        $this->assertNotSame($crossSlug, $crossNewSlug);

        $this->assertSameLastPost($sourceForumId, $crossSourceId, $crossOpId, 52, 'Cross split source');
        $this->assertLastPostDoesNotPointAt(
            $sourceForumId,
            $crossNewId,
            $crossReply,
            'Cross offshoot',
            $crossNewSlug
        );
        $this->assertLastPostDoesNotPointAt(
            $sourceForumId,
            $stayId,
            (int) $stay->last_post_id,
            'Stays on source',
            $staySlug
        );
        $this->assertSameLastPost($destForumId, $crossNewId, $crossReply, 53, 'Cross offshoot');
        $this->assertLastPostDoesNotPointAt(
            $destForumId,
            $crossSourceId,
            $crossOpId,
            'Cross split source',
            $crossSlug
        );

        $busyDestId = AP_Forum::insertForum(['forum_name' => 'Split Last Busy Dest'], $this->db);
        $busySrcId = AP_Forum::insertForum(['forum_name' => 'Split Last Busy Src'], $this->db);
        $this->assertGreaterThan(0, $busyDestId);
        $this->assertGreaterThan(0, $busySrcId);

        $destStayId = AP_Forum::createTopic([
            'forum_id' => $busyDestId,
            'topic_title' => 'Dest older stay',
            'content' => 'Dest older OP',
            'poster_id' => 61,
        ], $this->db);
        $busySourceId = AP_Forum::createTopic([
            'forum_id' => $busySrcId,
            'topic_title' => 'Busy source',
            'content' => 'Busy OP',
            'poster_id' => 62,
        ], $this->db);
        $busyReply = AP_Forum::createReply([
            'topic_id' => $busySourceId,
            'content' => 'Busy newest',
            'poster_id' => 63,
        ], $this->db);
        $this->assertGreaterThan(0, $destStayId);
        $this->assertGreaterThan(0, $busySourceId);
        $this->assertGreaterThan(0, $busyReply);

        $destStay = AP_Forum::getTopic($destStayId, $this->db);
        $busySource = AP_Forum::getTopic($busySourceId, $this->db);
        $this->assertNotNull($destStay);
        $this->assertNotNull($busySource);
        $destStaySlug = (string) $destStay->topic_slug;
        $busyOpId = (int) $busySource->first_post_id;
        $this->assertGreaterThan(0, $busyOpId);
        $this->assertSameLastPost(
            $busyDestId,
            $destStayId,
            (int) $destStay->last_post_id,
            61,
            'Dest older stay'
        );

        $busyNewId = AP_Forum_Moderation::splitTopic($busySourceId, [$busyReply], [
            'title' => 'Busy offshoot',
            'forum_id' => $busyDestId,
        ], $this->db);
        $this->assertGreaterThan(0, $busyNewId);

        $this->assertSameLastPost($busyDestId, $busyNewId, $busyReply, 63, 'Busy offshoot');
        $this->assertLastPostDoesNotPointAt(
            $busyDestId,
            $destStayId,
            (int) $destStay->last_post_id,
            'Dest older stay',
            $destStaySlug
        );
        $this->assertSameLastPost($busySrcId, $busySourceId, $busyOpId, 62, 'Busy source');
        $this->assertLastPostDoesNotPointAt(
            $busySrcId,
            $busyNewId,
            $busyReply,
            'Busy offshoot',
            (string) (AP_Forum::getTopic($busyNewId, $this->db)?->topic_slug ?? '')
        );
        $this->assertLastPostDoesNotPointAt(
            $busySrcId,
            $destStayId,
            (int) $destStay->last_post_id,
            'Dest older stay',
            $destStaySlug
        );
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
        $this->assertNotSame('', $url);
        $this->assertNotSame('', (string) $last['title']);
    }

    private function assertLastPostDoesNotPointAt(
        int $forumId,
        int $otherTopicId,
        int $otherPostId,
        string $otherTitle,
        string $otherSlug
    ): void {
        $forum = AP_Forum::getForum($forumId, $this->db);
        $this->assertNotNull($forum);
        $this->assertNotSame($otherTopicId, (int) $forum->last_topic_id);

        $row = AP_Forum::forumToDisplayRow($forum, $this->db);
        $last = $row['last_post'] ?? null;
        if ($last === null) {
            $this->assertSame('', (string) ($row['last_post']['title'] ?? ''));
            $this->assertSame('', (string) ($row['last_post']['url'] ?? ''));

            return;
        }

        $this->assertNotSame($otherTopicId, (int) ($last['topic_id'] ?? 0));
        $this->assertNotSame($otherTitle, (string) ($last['title'] ?? ''));
        $url = (string) ($last['url'] ?? '');
        $this->assertStringNotContainsString('topic_id=' . $otherTopicId, $url);
        if ($otherSlug !== '') {
            $this->assertStringNotContainsString('topic/' . $otherSlug, $url);
        }
        if ((int) ($last['post_id'] ?? 0) === $otherPostId) {
            $this->assertSame((int) $forum->last_topic_id, (int) ($last['topic_id'] ?? 0));
            $this->assertNotSame($otherTopicId, (int) ($last['topic_id'] ?? 0));
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

    private function topicCount(int $forumId): int
    {
        return (int) $this->db->getVar(
            'SELECT COUNT(*) FROM '
            . $this->db->quoteIdentifier($this->db->table('topics'))
            . ' WHERE ' . $this->db->quoteIdentifier('forum_id') . ' = ?',
            [$forumId]
        );
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
}
