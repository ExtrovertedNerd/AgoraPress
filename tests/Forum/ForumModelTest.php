<?php

/**
 * Tests for AP_Forum — hierarchy, topics, posts/replies.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Forum;

use AP_DB;
use AP_Forum;
use AP_Forum_Read;
use AP_Migrator;
use AP_Roles;
use AP_User;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_Forum::class)]
final class ForumModelTest extends TestCase
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

    public function testNormalizeConstants(): void
    {
        $this->assertSame('forum', AP_Forum::normalizeForumType('unknown'));
        $this->assertSame('category', AP_Forum::normalizeForumType('category'));
        $this->assertSame('open', AP_Forum::normalizeForumStatus('nope'));
        $this->assertSame('closed', AP_Forum::normalizeForumStatus('closed'));
        $this->assertSame('open', AP_Forum::normalizeTopicStatus('x'));
        $this->assertSame('locked', AP_Forum::normalizeTopicStatus('locked'));
        $this->assertSame('sticky', AP_Forum::normalizeTopicType('sticky'));
        $this->assertSame('standard', AP_Forum::normalizeTopicType('normal'));
        $this->assertSame('standard', AP_Forum::normalizeTopicType('standard'));
        $this->assertSame('announcement', AP_Forum::normalizeTopicType('announce'));
        $this->assertSame('announcement', AP_Forum::normalizeTopicType('announcement'));
        $this->assertSame('announcement', AP_Forum::normalizeTopicType('global'));
        $this->assertSame('rules', AP_Forum::normalizeTopicType('rules'));
        $this->assertSame('rules', AP_Forum::normalizeTopicType('info'));
        $this->assertSame(
            ['standard', 'sticky', 'announcement', 'rules'],
            AP_Forum::topicTypes()
        );
        $this->assertTrue(AP_Forum::isTopicLocked('locked'));
        $this->assertTrue(AP_Forum::isTopicSticky('announce'));
        $this->assertTrue(AP_Forum::isTopicSticky('rules'));
        $this->assertFalse(AP_Forum::isTopicSticky('normal'));
        $this->assertFalse(AP_Forum::isTopicSticky('standard'));
    }

    public function testForumHierarchyInsertAndTree(): void
    {
        $catId = AP_Forum::insertForum([
            'forum_name' => 'General',
            'forum_type' => 'category',
            'forum_desc' => 'Top category',
        ], $this->db);
        $this->assertGreaterThan(0, $catId);

        $forumId = AP_Forum::insertForum([
            'forum_name' => 'Introductions',
            'forum_type' => 'forum',
            'parent_id' => $catId,
            'forum_desc' => 'Say hello',
            'forum_order' => 1,
        ], $this->db);
        $this->assertGreaterThan(0, $forumId);

        $forum2 = AP_Forum::insertForum([
            'forum_name' => 'Off-topic',
            'parent_id' => $catId,
            'forum_order' => 2,
        ], $this->db);
        $this->assertGreaterThan(0, $forum2);

        $cat = AP_Forum::getForum($catId, $this->db);
        $this->assertNotNull($cat);
        $this->assertSame('General', $cat->forum_name);
        $this->assertSame('category', $cat->forum_type);
        $this->assertSame('general', $cat->forum_slug);

        $children = AP_Forum::getChildForums($catId, [], $this->db);
        $this->assertCount(2, $children);
        $this->assertSame('Introductions', $children[0]->forum_name);
        $this->assertSame('Off-topic', $children[1]->forum_name);

        $tree = AP_Forum::getHierarchy(0, [], $this->db);
        $this->assertCount(1, $tree);
        $this->assertSame('General', $tree[0]['forum']->forum_name);
        $this->assertCount(2, $tree[0]['children']);

        $ancestors = AP_Forum::getForumAncestors($forumId, $this->db);
        $this->assertCount(1, $ancestors);
        $this->assertSame($catId, (int) $ancestors[0]->forum_id);

        $index = AP_Forum::getIndexData($this->db);
        $this->assertCount(1, $index);
        $this->assertSame('General', $index[0]['name']);
        $this->assertCount(2, $index[0]['forums']);
        $this->assertSame('Introductions', $index[0]['forums'][0]['name']);
    }

    public function testUniqueForumSlugsAndCyclePrevention(): void
    {
        $a = AP_Forum::insertForum(['forum_name' => 'News'], $this->db);
        $b = AP_Forum::insertForum(['forum_name' => 'News', 'parent_id' => 0], $this->db);
        $this->assertGreaterThan(0, $a);
        $this->assertGreaterThan(0, $b);
        $fa = AP_Forum::getForum($a, $this->db);
        $fb = AP_Forum::getForum($b, $this->db);
        $this->assertSame('news', $fa?->forum_slug);
        $this->assertSame('news-2', $fb?->forum_slug);

        $child = AP_Forum::insertForum([
            'forum_name' => 'Child',
            'parent_id' => $a,
        ], $this->db);
        // Cycle: make A parent of Child's descendant — set A.parent = child
        $this->assertFalse(AP_Forum::updateForum($a, ['parent_id' => $child], $this->db));
        $this->assertFalse(AP_Forum::updateForum($a, ['parent_id' => $a], $this->db));
    }

    public function testCannotDeleteNonEmptyForumWithoutForce(): void
    {
        $forumId = AP_Forum::insertForum(['forum_name' => 'Busy'], $this->db);
        $topicId = AP_Forum::createTopic([
            'forum_id' => $forumId,
            'topic_title' => 'Hello',
            'content' => 'First post body',
            'poster_id' => 1,
        ], $this->db);
        $this->assertGreaterThan(0, $topicId);
        $this->assertFalse(AP_Forum::deleteForum($forumId, false, $this->db));
        $this->assertTrue(AP_Forum::deleteForum($forumId, true, $this->db));
        $this->assertNull(AP_Forum::getForum($forumId, $this->db));
        $this->assertNull(AP_Forum::getTopic($topicId, $this->db));
    }

    public function testCreateTopicAndReplyWithCounters(): void
    {
        $forumId = AP_Forum::insertForum([
            'forum_name' => 'General Chat',
            'forum_type' => 'forum',
        ], $this->db);

        $topicId = AP_Forum::createTopic([
            'forum_id' => $forumId,
            'topic_title' => 'Welcome everyone',
            'content' => 'Hello and welcome to the board.',
            'topic_poster' => 7,
            'poster_ip' => '127.0.0.1',
        ], $this->db);
        $this->assertGreaterThan(0, $topicId);

        $topic = AP_Forum::getTopic($topicId, $this->db);
        $this->assertNotNull($topic);
        $this->assertSame('Welcome everyone', $topic->topic_title);
        $this->assertSame('welcome-everyone', $topic->topic_slug);
        $this->assertSame(7, (int) $topic->topic_poster);
        $this->assertSame(0, (int) $topic->reply_count);
        $this->assertGreaterThan(0, (int) $topic->first_post_id);
        $this->assertSame((int) $topic->first_post_id, (int) $topic->last_post_id);

        $first = AP_Forum::getPost((int) $topic->first_post_id, $this->db);
        $this->assertNotNull($first);
        $this->assertSame('Hello and welcome to the board.', $first->post_content);
        $this->assertSame(1, (int) $first->post_position);
        $this->assertSame($topicId, (int) $first->topic_id);
        $this->assertSame($forumId, (int) $first->forum_id);

        $forum = AP_Forum::getForum($forumId, $this->db);
        $this->assertSame(1, (int) $forum?->topic_count);
        $this->assertSame(1, (int) $forum?->post_count);
        $this->assertSame((int) $first->post_id, (int) $forum?->last_post_id);
        $this->assertSame($topicId, (int) $forum?->last_topic_id);

        $replyId = AP_Forum::createReply([
            'topic_id' => $topicId,
            'content' => 'Glad to be here!',
            'poster_id' => 3,
        ], $this->db);
        $this->assertGreaterThan(0, $replyId);

        $topic = AP_Forum::getTopic($topicId, $this->db);
        $this->assertSame(1, (int) $topic?->reply_count);
        $this->assertSame($replyId, (int) $topic?->last_post_id);
        $this->assertSame(3, (int) $topic?->last_poster_id);

        $forum = AP_Forum::getForum($forumId, $this->db);
        $this->assertSame(1, (int) $forum?->topic_count);
        $this->assertSame(2, (int) $forum?->post_count);

        $posts = AP_Forum::getPosts($topicId, [], $this->db);
        $this->assertCount(2, $posts);
        $this->assertSame(1, (int) $posts[0]->post_position);
        $this->assertSame(2, (int) $posts[1]->post_position);

        $display = AP_Forum::getPostsDisplayData($topicId, [], $this->db);
        $this->assertCount(2, $display);
        $this->assertSame(1, $display[0]['number']);
        $this->assertSame('Glad to be here!', $display[1]['content']);
    }

    public function testRejectsEmptyContentAndCategoryAsForum(): void
    {
        $catId = AP_Forum::insertForum([
            'forum_name' => 'Cat',
            'forum_type' => 'category',
        ], $this->db);

        $this->assertSame(0, AP_Forum::createTopic([
            'forum_id' => $catId,
            'topic_title' => 'Nope',
            'content' => 'Body',
        ], $this->db));

        $forumId = AP_Forum::insertForum(['forum_name' => 'Real'], $this->db);
        $this->assertSame(0, AP_Forum::createTopic([
            'forum_id' => $forumId,
            'topic_title' => 'Empty body',
            'content' => '   ',
        ], $this->db));
        $this->assertSame(0, AP_Forum::createTopic([
            'forum_id' => $forumId,
            'topic_title' => '',
            'content' => 'Body',
        ], $this->db));
        $this->assertSame(0, AP_Forum::insertForum(['forum_name' => ''], $this->db));
    }

    public function testClosedForumAndLockedTopicBlockPosting(): void
    {
        $forumId = AP_Forum::insertForum([
            'forum_name' => 'Locked Area',
            'forum_status' => 'closed',
        ], $this->db);
        $this->assertSame(0, AP_Forum::createTopic([
            'forum_id' => $forumId,
            'topic_title' => 'Blocked',
            'content' => 'Should fail',
        ], $this->db));

        // Bypass check_open.
        $topicId = AP_Forum::createTopic([
            'forum_id' => $forumId,
            'topic_title' => 'Admin topic',
            'content' => 'Staff only seed',
            'topic_status' => 'locked',
        ], $this->db, ['check_open' => false]);
        $this->assertGreaterThan(0, $topicId);

        $this->assertSame(0, AP_Forum::createReply([
            'topic_id' => $topicId,
            'content' => 'Cannot reply to locked',
        ], $this->db));

        $replyId = AP_Forum::createReply([
            'topic_id' => $topicId,
            'content' => 'Moderator reply',
        ], $this->db, ['check_open' => false]);
        $this->assertGreaterThan(0, $replyId);
    }

    public function testStickyOrderingAndViews(): void
    {
        $forumId = AP_Forum::insertForum(['forum_name' => 'Board'], $this->db);

        $normal = AP_Forum::createTopic([
            'forum_id' => $forumId,
            'topic_title' => 'Normal topic',
            'content' => 'Normal body',
        ], $this->db);
        usleep(20000);
        $sticky = AP_Forum::createTopic([
            'forum_id' => $forumId,
            'topic_title' => 'Sticky topic',
            'content' => 'Sticky body',
            'topic_type' => 'sticky',
        ], $this->db);
        usleep(20000);
        $rules = AP_Forum::createTopic([
            'forum_id' => $forumId,
            'topic_title' => 'Rules topic',
            'content' => 'Rules body',
            'topic_type' => 'rules',
        ], $this->db);
        usleep(20000);
        $announce = AP_Forum::createTopic([
            'forum_id' => $forumId,
            'topic_title' => 'Announce topic',
            'content' => 'Announce body',
            'topic_type' => 'announcement',
        ], $this->db);

        $topics = AP_Forum::getTopics($forumId, [], $this->db);
        $this->assertCount(4, $topics);
        $this->assertSame($announce, (int) $topics[0]->topic_id);
        $this->assertSame('announcement', (string) $topics[0]->topic_type);
        $this->assertSame($rules, (int) $topics[1]->topic_id);
        $this->assertSame('rules', (string) $topics[1]->topic_type);
        $this->assertSame($sticky, (int) $topics[2]->topic_id);
        $this->assertSame($normal, (int) $topics[3]->topic_id);
        $this->assertSame('standard', (string) $topics[3]->topic_type);

        $this->assertTrue(AP_Forum::incrementTopicViews($normal, $this->db));
        $this->assertTrue(AP_Forum::incrementTopicViews($normal, $this->db));
        $t = AP_Forum::getTopic($normal, $this->db);
        $this->assertSame(2, (int) $t?->topic_views);

        $display = AP_Forum::getTopicsDisplayData($forumId, [], $this->db);
        $this->assertTrue($display[0]['announcement']);
        $this->assertTrue($display[1]['rules']);
        $this->assertTrue($display[2]['sticky']);
        $this->assertFalse($display[3]['sticky']);
        $this->assertFalse($display[3]['announcement']);
        $this->assertFalse($display[3]['rules']);
        $this->assertSame('standard', $display[3]['type']);
    }

    public function testSoftDeleteTopicAdjustsCounters(): void
    {
        $forumId = AP_Forum::insertForum(['forum_name' => 'Stats'], $this->db);
        $topicId = AP_Forum::createTopic([
            'forum_id' => $forumId,
            'topic_title' => 'Temp',
            'content' => 'First',
        ], $this->db);
        AP_Forum::createReply([
            'topic_id' => $topicId,
            'content' => 'Second',
        ], $this->db);

        $forum = AP_Forum::getForum($forumId, $this->db);
        $this->assertSame(1, (int) $forum?->topic_count);
        $this->assertSame(2, (int) $forum?->post_count);

        $this->assertTrue(AP_Forum::deleteTopic($topicId, false, $this->db));
        $topic = AP_Forum::getTopic($topicId, $this->db);
        $this->assertSame('deleted', $topic?->topic_status);

        $listed = AP_Forum::getTopics($forumId, [], $this->db);
        $this->assertCount(0, $listed);

        $forum = AP_Forum::getForum($forumId, $this->db);
        $this->assertSame(0, (int) $forum?->topic_count);
        $this->assertSame(0, (int) $forum?->post_count);
    }

    public function testUpdatePostAndDeleteReply(): void
    {
        $forumId = AP_Forum::insertForum(['forum_name' => 'Edit'], $this->db);
        $topicId = AP_Forum::createTopic([
            'forum_id' => $forumId,
            'topic_title' => 'Thread',
            'content' => 'Original OP',
        ], $this->db);
        $topic = AP_Forum::getTopic($topicId, $this->db);
        $firstId = (int) $topic?->first_post_id;

        $replyId = AP_Forum::createReply([
            'topic_id' => $topicId,
            'content' => 'Reply one',
            'poster_id' => 2,
        ], $this->db);

        $this->assertTrue(AP_Forum::updatePost($replyId, [
            'content' => 'Reply one (edited)',
            'edit_reason' => 'typo',
            'edit_user' => 2,
        ], $this->db));
        $post = AP_Forum::getPost($replyId, $this->db);
        $this->assertSame('Reply one (edited)', $post?->post_content);
        $this->assertSame(1, (int) $post?->post_edit_count);
        $this->assertSame('typo', $post?->post_edit_reason);

        // Cannot delete first post without force.
        $this->assertFalse(AP_Forum::deletePost($firstId, false, $this->db));
        $this->assertTrue(AP_Forum::deletePost($replyId, false, $this->db));
        $this->assertNull(AP_Forum::getPost($replyId, $this->db));

        $topic = AP_Forum::getTopic($topicId, $this->db);
        $this->assertSame(0, (int) $topic?->reply_count);
        $this->assertSame($firstId, (int) $topic?->last_post_id);

        $forum = AP_Forum::getForum($forumId, $this->db);
        $this->assertSame(1, (int) $forum?->post_count);
    }

    public function testUpdateTopicLockAndType(): void
    {
        $forumId = AP_Forum::insertForum(['forum_name' => 'Meta'], $this->db);
        $topicId = AP_Forum::createTopic([
            'forum_id' => $forumId,
            'topic_title' => 'Rename me',
            'content' => 'Body',
        ], $this->db);

        $this->assertTrue(AP_Forum::updateTopic($topicId, [
            'title' => 'Renamed',
            'status' => 'locked',
            'type' => 'sticky',
        ], $this->db));
        $topic = AP_Forum::getTopic($topicId, $this->db);
        $this->assertSame('Renamed', $topic?->topic_title);
        $this->assertSame('locked', $topic?->topic_status);
        $this->assertSame('sticky', $topic?->topic_type);
        $this->assertSame('renamed', $topic?->topic_slug);
    }

    public function testProceduralHelpers(): void
    {
        $catId = ap_insert_forum([
            'forum_name' => 'Helpers',
            'forum_type' => 'category',
        ], $this->db);
        $forumId = ap_insert_forum([
            'forum_name' => 'Helper Forum',
            'parent_id' => $catId,
        ], $this->db);
        $this->assertGreaterThan(0, $forumId);

        $topicId = ap_create_topic([
            'forum_id' => $forumId,
            'topic_title' => 'Via helper',
            'content' => 'Helper body',
            'poster_id' => 1,
        ], $this->db);
        $this->assertGreaterThan(0, $topicId);

        $replyId = ap_create_forum_reply([
            'topic_id' => $topicId,
            'content' => 'Helper reply',
        ], $this->db);
        $this->assertGreaterThan(0, $replyId);

        $this->assertNotNull(ap_get_forum($forumId, $this->db));
        $this->assertNotNull(ap_get_topic($topicId, $this->db));
        $this->assertNotNull(ap_get_forum_post($replyId, $this->db));
        $this->assertCount(1, ap_get_topics($forumId, [], $this->db));
        $this->assertCount(2, ap_get_forum_posts($topicId, [], $this->db));

        $index = ap_get_forum_index_data($this->db);
        $this->assertNotEmpty($index);
        $this->assertSame('Helpers', $index[0]['name']);
    }

    public function testHiddenForumsExcludedByDefault(): void
    {
        AP_Forum::insertForum([
            'forum_name' => 'Visible',
            'forum_status' => 'open',
        ], $this->db);
        AP_Forum::insertForum([
            'forum_name' => 'Secret',
            'forum_status' => 'hidden',
        ], $this->db);

        $visible = AP_Forum::getChildForums(0, [], $this->db);
        $this->assertCount(1, $visible);
        $this->assertSame('Visible', $visible[0]->forum_name);

        $all = AP_Forum::getChildForums(0, ['include_hidden' => true], $this->db);
        $this->assertCount(2, $all);
    }

    public function testForumIconTypes(): void
    {
        $this->assertSame('standard', AP_Forum::forumIconType('forum', 'open'));
        $this->assertSame('locked', AP_Forum::forumIconType('forum', 'closed'));
        $this->assertSame('link', AP_Forum::forumIconType('link', 'open'));
        $this->assertSame('link', AP_Forum::forumIconType('link', 'closed'));
        $this->assertSame('standard', ap_forum_icon_type('forum', 'open'));
        $this->assertSame('locked', ap_forum_icon_type('forum', 'closed'));

        $this->assertSame('standard', AP_Forum::topicIconType('standard', 'open'));
        $this->assertSame('sticky', AP_Forum::topicIconType('sticky', 'open'));
        $this->assertSame('announcement', AP_Forum::topicIconType('announcement', 'open'));
        $this->assertSame('rules', AP_Forum::topicIconType('rules', 'open'));
        $this->assertSame('locked', AP_Forum::topicIconType('sticky', 'locked'));
        $this->assertSame('locked', ap_topic_icon_type('standard', 'locked'));
    }

    public function testForumRowPayloadIconCountsLastPostAndUnread(): void
    {
        require_once $this->root . '/ap-includes/class-ap-user.php';
        require_once $this->root . '/ap-includes/class-ap-roles.php';
        require_once $this->root . '/ap-includes/class-ap-options.php';
        require_once $this->root . '/ap-includes/class-ap-forum-read.php';
        $GLOBALS['apdb'] = $this->db;

        if (method_exists('AP_Roles', 'ensureDefaults')) {
            AP_Roles::ensureDefaults($this->db);
        }

        $authorCreate = AP_User::create([
            'user_login' => 'row_author',
            'user_email' => 'row_author@example.com',
            'user_pass' => 'password-password-12',
            'display_name' => 'Row Author',
            'role' => 'subscriber',
        ], $this->db);
        $this->assertTrue(!empty($authorCreate['ok']), $authorCreate['error'] ?? 'author create failed');
        $authorId = (int) $authorCreate['id'];
        $this->assertGreaterThan(0, $authorId);

        $readerCreate = AP_User::create([
            'user_login' => 'row_reader',
            'user_email' => 'row_reader@example.com',
            'user_pass' => 'password-password-12',
            'display_name' => 'Row Reader',
            'role' => 'subscriber',
        ], $this->db);
        $this->assertTrue(!empty($readerCreate['ok']), $readerCreate['error'] ?? 'reader create failed');
        $readerId = (int) $readerCreate['id'];
        $this->assertGreaterThan(0, $readerId);

        $catId = AP_Forum::insertForum([
            'forum_name' => 'Payload Cat',
            'forum_type' => 'category',
        ], $this->db);
        $openId = AP_Forum::insertForum([
            'forum_name' => 'Open Lounge',
            'forum_type' => 'forum',
            'forum_status' => 'open',
            'parent_id' => $catId,
            'forum_desc' => 'Chat freely',
        ], $this->db);
        $closedId = AP_Forum::insertForum([
            'forum_name' => 'Archive',
            'forum_type' => 'forum',
            'forum_status' => 'closed',
            'parent_id' => $catId,
        ], $this->db);
        $linkId = AP_Forum::insertForum([
            'forum_name' => 'External',
            'forum_type' => 'link',
            'parent_id' => $catId,
        ], $this->db);
        $this->assertGreaterThan(0, $openId);
        $this->assertGreaterThan(0, $closedId);
        $this->assertGreaterThan(0, $linkId);

        // Empty open forum: null last_post, zero counts, standard icon.
        $emptyRow = AP_Forum::forumToDisplayRow(
            AP_Forum::getForum($openId, $this->db),
            $this->db
        );
        $this->assertSame('standard', $emptyRow['icon_type']);
        $this->assertFalse($emptyRow['is_unread']);
        $this->assertFalse($emptyRow['is_closed']);
        $this->assertTrue($emptyRow['is_empty']);
        $this->assertSame(0, $emptyRow['topics']);
        $this->assertSame(0, $emptyRow['topic_count']);
        $this->assertSame(0, $emptyRow['posts']);
        $this->assertSame(0, $emptyRow['post_count']);
        $this->assertNull($emptyRow['last_post']);

        $closedRow = AP_Forum::forumToDisplayRow(
            AP_Forum::getForum($closedId, $this->db),
            $this->db
        );
        $this->assertSame('locked', $closedRow['icon_type']);
        $this->assertTrue($closedRow['is_closed']);
        $this->assertTrue($closedRow['is_locked']);
        $this->assertTrue($closedRow['is_empty']);
        $this->assertTrue(AP_Forum::isForumClosed(AP_Forum::getForum($closedId, $this->db)));

        $linkRow = AP_Forum::forumToDisplayRow(
            AP_Forum::getForum($linkId, $this->db),
            $this->db
        );
        $this->assertSame('link', $linkRow['icon_type']);

        $topicId = AP_Forum::createTopic([
            'forum_id' => $openId,
            'topic_title' => 'Welcome payload thread',
            'content' => 'Opening body for last-post payload.',
            'poster_id' => $authorId,
        ], $this->db);
        $this->assertGreaterThan(0, $topicId);

        $replyId = AP_Forum::createReply([
            'topic_id' => $topicId,
            'content' => 'A reply that becomes last post.',
            'poster_id' => $authorId,
        ], $this->db);
        $this->assertGreaterThan(0, $replyId);

        $forum = AP_Forum::getForum($openId, $this->db);
        $this->assertNotNull($forum);
        $this->assertSame(1, (int) $forum->topic_count);
        $this->assertSame(2, (int) $forum->post_count);
        $this->assertSame($replyId, (int) $forum->last_post_id);

        $row = AP_Forum::forumToDisplayRow($forum, $this->db);
        $this->assertSame('standard', $row['icon_type']);
        $this->assertSame(1, $row['topics']);
        $this->assertSame(1, $row['topic_count']);
        $this->assertSame(2, $row['posts']);
        $this->assertSame(2, $row['post_count']);
        $this->assertFalse($row['is_unread']);
        $this->assertIsArray($row['last_post']);
        $this->assertSame('Welcome payload thread', $row['last_post']['title']);
        $this->assertSame('Row Author', $row['last_post']['author']);
        $this->assertNotSame('', (string) $row['last_post']['time']);
        $this->assertSame($row['last_post']['time'], $row['last_post']['date']);
        $this->assertStringContainsString('#post-' . $replyId, (string) $row['last_post']['url']);
        $this->assertSame($replyId, (int) $row['last_post']['post_id']);
        $this->assertSame($topicId, (int) $row['last_post']['topic_id']);
        $this->assertSame($authorId, (int) $row['last_post']['author_id']);

        // Procedural wrapper matches.
        $viaHelper = ap_forum_to_display_row($forum, $this->db);
        $this->assertSame($row['icon_type'], $viaHelper['icon_type']);
        $this->assertSame($row['last_post']['title'], $viaHelper['last_post']['title'] ?? null);
        $this->assertSame(
            AP_Forum::postUrl(AP_Forum::getTopic($topicId, $this->db), $replyId),
            ap_forum_post_url(AP_Forum::getTopic($topicId, $this->db), $replyId)
        );

        // Board index wires payload + unread annotation.
        $index = AP_Forum::getIndexData($this->db, ['user_id' => $readerId]);
        $this->assertNotEmpty($index);
        $found = null;
        foreach ($index as $cat) {
            foreach ($cat['forums'] as $f) {
                if ((int) ($f['id'] ?? 0) === $openId) {
                    $found = $f;
                    break 2;
                }
            }
        }
        $this->assertNotNull($found);
        $this->assertSame('standard', $found['icon_type']);
        $this->assertTrue($found['is_unread']);
        $this->assertSame(1, $found['topic_count']);
        $this->assertSame(2, $found['post_count']);
        $this->assertSame('Welcome payload thread', $found['last_post']['title'] ?? null);
        $this->assertSame('Row Author', $found['last_post']['author'] ?? null);
        $this->assertNotSame('', (string) ($found['last_post']['time'] ?? ''));
        $this->assertStringContainsString('#post-' . $replyId, (string) ($found['last_post']['url'] ?? ''));

        AP_Forum_Read::markTopicRead($readerId, $topicId, $this->db);
        $indexRead = AP_Forum::getIndexData($this->db, ['user_id' => $readerId]);
        $foundRead = null;
        foreach ($indexRead as $cat) {
            foreach ($cat['forums'] as $f) {
                if ((int) ($f['id'] ?? 0) === $openId) {
                    $foundRead = $f;
                    break 2;
                }
            }
        }
        $this->assertNotNull($foundRead);
        $this->assertFalse($foundRead['is_unread']);

        // Topic row also exposes icon_type + nested last_post.
        $topicRow = AP_Forum::topicToDisplayRow(
            AP_Forum::getTopic($topicId, $this->db),
            $this->db
        );
        $this->assertSame('standard', $topicRow['icon_type']);
        $this->assertIsArray($topicRow['last_post']);
        $this->assertSame('Welcome payload thread', $topicRow['last_post']['title']);
        $this->assertStringContainsString('#post-' . $replyId, (string) $topicRow['last_post']['url']);
    }
}
