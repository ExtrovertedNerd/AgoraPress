<?php

/**
 * Phase 1 (phpBB-parity) — tests for migrations and board helpers.
 *
 * Covers the data-layer acceptance criteria:
 * - Schema ≥ 12 (topic type enum migration)
 * - Topic type normalize / icons
 * - Board aggregates (topics · posts · members)
 * - Forum row payload (icon, counts, last_post, unread)
 * - Read markers + first-unread helpers
 * - Author-panel like aggregates
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Forum;

use AP_DB;
use AP_Forum;
use AP_Forum_Like;
use AP_Forum_Read;
use AP_Forum_Stats;
use AP_Migrator;
use AP_Options;
use AP_Roles;
use AP_User;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_Forum::class)]
#[CoversClass(AP_Forum_Stats::class)]
#[CoversClass(AP_Forum_Read::class)]
final class ForumBoardHelpersTest extends TestCase
{
    private string $root;

    private AP_DB $db;

    private int $authorId = 0;

    private int $readerId = 0;

    private int $forumId = 0;

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
        require_once $this->root . '/ap-includes/class-ap-forum-read.php';
        require_once $this->root . '/ap-includes/class-ap-forum-like.php';
        require_once $this->root . '/ap-includes/class-ap-forum-stats.php';
        require_once $this->root . '/ap-includes/hooks.php';
        require_once $this->root . '/ap-includes/functions.php';

        if (function_exists('ap_reset_hooks')) {
            ap_reset_hooks();
        }
        AP_Forum_Stats::registerHooks();

        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');
        $GLOBALS['apdb'] = $this->db;

        $applied = (new AP_Migrator(
            $this->db,
            AP_Migrator::defaultMigrationsPath()
        ))->migrate();
        $this->assertGreaterThanOrEqual(12, count($applied));
        $this->assertSame(12, (int) AP_DB_VERSION);

        AP_Roles::ensureDefaults($this->db);
        AP_Options::update(AP_Forum_Read::OPTION_ENABLED, '1', $this->db);
        AP_Options::update('ap_module_forum', '1', $this->db);

        $this->authorId = $this->createUser('board_author', 'Board Author');
        $this->readerId = $this->createUser('board_reader', 'Board Reader');

        $this->forumId = AP_Forum::insertForum([
            'forum_name' => 'Board Helpers Forum',
            'forum_slug' => 'board-helpers',
            'forum_type' => 'forum',
            'forum_status' => 'open',
            'forum_desc' => 'Phase 1 helper fixtures',
        ], $this->db);
        $this->assertGreaterThan(0, $this->forumId);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['apdb']);
    }

    private function createUser(string $login, string $display): int
    {
        $result = AP_User::create([
            'user_login' => $login,
            'user_email' => $login . '@example.test',
            'user_pass' => 'SecurePass99!',
            'display_name' => $display,
            'role' => 'subscriber',
        ], $this->db);
        $this->assertTrue(!empty($result['ok']), implode(' ', $result['errors'] ?? []));

        return (int) $result['id'];
    }

    // -------------------------------------------------------------------------
    // Migrations / schema surface
    // -------------------------------------------------------------------------

    public function testSchemaVersionAndTopicTypeMigrationPresent(): void
    {
        $path = AP_Migrator::defaultMigrationsPath() . '/0012_topic_type_enum.php';
        $this->assertFileIsReadable($path);
        $src = (string) file_get_contents($path);
        $this->assertStringContainsString('standard', $src);
        $this->assertStringContainsString('announcement', $src);
        $this->assertStringContainsString('rules', $src);
        $this->assertStringContainsString('normal', $src);

        $this->assertContains('topic_track', AP_Forum::baseTables());
        $this->assertContains('forum_track', AP_Forum::baseTables());
        $this->assertContains('forum_post_likes', AP_Forum::baseTables());

        $migrator = new AP_Migrator($this->db, AP_Migrator::defaultMigrationsPath());
        $this->assertSame(12, $migrator->getCurrentVersion());
        $this->assertFalse($migrator->needsMigration());
    }

    // -------------------------------------------------------------------------
    // Topic type helpers
    // -------------------------------------------------------------------------

    public function testTopicTypeHelpersAndIcons(): void
    {
        $this->assertSame(
            ['standard', 'sticky', 'announcement', 'rules'],
            AP_Forum::topicTypes()
        );

        $cases = [
            'standard' => 'standard',
            'sticky' => 'sticky',
            'announcement' => 'announcement',
            'rules' => 'rules',
            'normal' => 'standard',
            'announce' => 'announcement',
            'global' => 'announcement',
            'info' => 'rules',
            'wat' => 'standard',
        ];
        foreach ($cases as $input => $expected) {
            $this->assertSame($expected, AP_Forum::normalizeTopicType($input), "input={$input}");
        }

        $this->assertSame('standard', AP_Forum::topicIconType('standard', 'open'));
        $this->assertSame('sticky', AP_Forum::topicIconType('sticky', 'open'));
        $this->assertSame('announcement', AP_Forum::topicIconType('announcement', 'open'));
        $this->assertSame('rules', AP_Forum::topicIconType('rules', 'open'));
        $this->assertSame('locked', AP_Forum::topicIconType('sticky', 'locked'));
        $this->assertSame('locked', ap_topic_icon_type('rules', 'locked'));

        $this->assertSame('standard', AP_Forum::forumIconType('forum', 'open'));
        $this->assertSame('locked', AP_Forum::forumIconType('forum', 'closed'));
        $this->assertSame('link', AP_Forum::forumIconType('link', 'open'));
        $this->assertSame('locked', ap_forum_icon_type('forum', 'closed'));
    }

    public function testForumRowIconHtmlMarkupAndNormalization(): void
    {
        $this->assertSame(
            ['standard', 'sticky', 'announcement', 'rules', 'locked', 'link'],
            ap_forum_row_icon_types()
        );
        $this->assertSame('standard', ap_forum_normalize_icon_type('nope'));
        $this->assertSame('sticky', ap_forum_normalize_icon_type('STICKY'));
        $this->assertSame('Announcement', ap_forum_icon_type_label('announcement'));

        $html = ap_forum_row_icon_html('sticky', ['unread' => true]);
        $this->assertStringContainsString('ap-forum-row__icon', $html);
        $this->assertStringContainsString('ap-forum-icon--sticky', $html);
        $this->assertStringContainsString('ap-forum-icon--unread', $html);
        $this->assertStringContainsString('screen-reader-text', $html);
        $this->assertStringContainsString('Sticky (unread)', $html);
        $this->assertStringContainsString('aria-hidden="true"', $html);

        $fallback = ap_forum_row_icon_html('not-a-type');
        $this->assertStringContainsString('ap-forum-icon--standard', $fallback);
        $this->assertStringNotContainsString('ap-forum-icon--not-a-type', $fallback);
    }

    public function testForumRowReadStateAndClasses(): void
    {
        // Guests / tracking off → neutral (do not claim personal read state).
        $this->assertSame('neutral', ap_forum_row_read_state([
            'is_unread' => false,
            'tracking' => false,
        ]));
        $this->assertSame('neutral', ap_forum_row_read_state([
            'user_id' => 0,
            'is_unread' => false,
        ]));

        // Explicit unread always wins.
        $this->assertSame('unread', ap_forum_row_read_state([
            'is_unread' => true,
            'tracking' => false,
        ]));
        $this->assertSame('unread', ap_forum_row_read_state([
            'unread' => true,
            'tracking' => true,
        ]));

        // Logged-in + fully read.
        $this->assertSame('read', ap_forum_row_read_state([
            'is_unread' => false,
            'tracking' => true,
        ]));
        $this->assertSame('read', ap_forum_row_read_state([
            'user_id' => $this->readerId,
            'is_unread' => false,
        ]));

        $unreadClasses = ap_forum_row_classes([
            'is_unread' => true,
            'tracking' => true,
            'icon_type' => 'sticky',
        ]);
        $this->assertStringContainsString('ap-forum-row', $unreadClasses);
        $this->assertStringContainsString('ap-forum-row--unread', $unreadClasses);
        $this->assertStringContainsString('ap-forum-list__item--unread', $unreadClasses);
        $this->assertStringContainsString('ap-forum-row--icon-sticky', $unreadClasses);
        $this->assertStringNotContainsString('ap-forum-row--read', $unreadClasses);
        $this->assertStringNotContainsString('ap-forum-row--neutral', $unreadClasses);

        $readClasses = ap_forum_row_classes([
            'is_unread' => false,
            'tracking' => true,
        ]);
        $this->assertStringContainsString('ap-forum-row--read', $readClasses);
        $this->assertStringNotContainsString('ap-forum-row--unread', $readClasses);
        $this->assertStringNotContainsString('ap-forum-list__item--unread', $readClasses);

        $neutralClasses = ap_forum_row_classes([
            'is_unread' => false,
            'tracking' => false,
        ]);
        $this->assertStringContainsString('ap-forum-row--neutral', $neutralClasses);
        $this->assertStringNotContainsString('ap-forum-row--unread', $neutralClasses);
        $this->assertStringNotContainsString('ap-forum-row--read', $neutralClasses);

        $topicClasses = ap_forum_row_classes([
            'is_unread' => true,
            'tracking' => true,
            'topic' => true,
            'icon_type' => 'announcement',
        ]);
        $this->assertStringContainsString('ap-forum-row--topic', $topicClasses);
        $this->assertStringContainsString('ap-forum-list__item--topic', $topicClasses);
        $this->assertStringContainsString('ap-forum-row--icon-announcement', $topicClasses);

        // Locked + empty row affordances (closed forum / empty forum).
        $lockedClasses = ap_forum_row_classes([
            'is_unread' => false,
            'tracking' => true,
            'is_closed' => true,
            'is_empty' => true,
            'icon_type' => 'locked',
        ]);
        $this->assertStringContainsString('ap-forum-row--locked', $lockedClasses);
        $this->assertStringContainsString('ap-forum-row--empty', $lockedClasses);
        $this->assertStringContainsString('ap-forum-row--icon-locked', $lockedClasses);

        $topicLockedClasses = ap_forum_row_classes([
            'topic' => true,
            'locked' => true,
            'tracking' => true,
        ]);
        $this->assertStringContainsString('ap-forum-row--locked', $topicLockedClasses);
        $this->assertStringContainsString('ap-forum-row--topic', $topicLockedClasses);

        // Icon variants: unread / read / neutral.
        $unreadIcon = ap_forum_row_icon_html('rules', [
            'is_unread' => true,
            'tracking' => true,
        ]);
        $this->assertStringContainsString('ap-forum-icon--rules', $unreadIcon);
        $this->assertStringContainsString('ap-forum-icon--unread', $unreadIcon);
        $this->assertStringNotContainsString('ap-forum-icon--read', $unreadIcon);
        $this->assertStringContainsString('Rules (unread)', $unreadIcon);

        $readIcon = ap_forum_row_icon_html('locked', [
            'is_unread' => false,
            'tracking' => true,
        ]);
        $this->assertStringContainsString('ap-forum-icon--locked', $readIcon);
        $this->assertStringContainsString('ap-forum-icon--read', $readIcon);
        $this->assertStringNotContainsString('ap-forum-icon--unread', $readIcon);
        $this->assertStringContainsString('Locked (read)', $readIcon);

        $neutralIcon = ap_forum_row_icon_html('standard', [
            'is_unread' => false,
            'tracking' => false,
        ]);
        $this->assertStringContainsString('ap-forum-icon--standard', $neutralIcon);
        $this->assertStringNotContainsString('ap-forum-icon--unread', $neutralIcon);
        $this->assertStringNotContainsString('ap-forum-icon--read', $neutralIcon);
        $this->assertStringContainsString('>Standard<', $neutralIcon);
    }

    public function testCreateTopicPersistsCanonicalTypesAndDisplayIcons(): void
    {
        foreach (['standard', 'sticky', 'announcement', 'rules'] as $type) {
            $id = AP_Forum::createTopic([
                'forum_id' => $this->forumId,
                'topic_title' => 'Type ' . $type,
                'content' => 'Body ' . $type,
                'topic_type' => $type,
                'poster_id' => $this->authorId,
            ], $this->db);
            $this->assertGreaterThan(0, $id);
            $topic = AP_Forum::getTopic($id, $this->db);
            $this->assertNotNull($topic);
            $this->assertSame($type, (string) $topic->topic_type);

            $row = AP_Forum::topicToDisplayRow($topic, $this->db);
            $this->assertSame($type, $row['type']);
            $this->assertSame($type, $row['icon_type']);
            $this->assertFalse($row['is_unread']);
            $this->assertIsArray($row['last_post']);
            $this->assertSame('Type ' . $type, $row['last_post']['title']);
        }

        // Locked overrides type for icon.
        $lockedId = AP_Forum::createTopic([
            'forum_id' => $this->forumId,
            'topic_title' => 'Locked sticky',
            'content' => 'Closed',
            'topic_type' => 'sticky',
            'topic_status' => 'locked',
            'poster_id' => $this->authorId,
        ], $this->db);
        $locked = AP_Forum::getTopic($lockedId, $this->db);
        $this->assertNotNull($locked);
        $this->assertSame('locked', AP_Forum::topicToDisplayRow($locked, $this->db)['icon_type']);
    }

    // -------------------------------------------------------------------------
    // Board-level aggregates
    // -------------------------------------------------------------------------

    public function testBoardStatsEmptyThenPopulated(): void
    {
        // Fresh forum only — no topics yet; members from setUp users.
        $emptyish = AP_Forum_Stats::getBoardStats($this->db);
        $this->assertSame(0, $emptyish['topics']);
        $this->assertSame(0, $emptyish['posts']);
        $this->assertSame(2, $emptyish['members']);
        $this->assertSame(
            ['topics' => 0, 'posts' => 0, 'members' => 0],
            AP_Forum_Stats::emptyBoardStats()
        );

        $topicId = AP_Forum::createTopic([
            'forum_id' => $this->forumId,
            'topic_title' => 'Stats topic',
            'content' => 'Opening post',
            'poster_id' => $this->authorId,
        ], $this->db);
        $this->assertGreaterThan(0, $topicId);
        $replyId = AP_Forum::createReply([
            'topic_id' => $topicId,
            'content' => 'A reply',
            'poster_id' => $this->readerId,
        ], $this->db);
        $this->assertGreaterThan(0, $replyId);

        $stats = AP_Forum_Stats::getBoardStats($this->db);
        $this->assertSame(1, $stats['topics']);
        $this->assertSame(2, $stats['posts']);
        $this->assertSame(2, $stats['members']);
        $this->assertSame(1, AP_Forum_Stats::getTotalTopics($this->db));
        $this->assertSame(2, AP_Forum_Stats::getTotalPosts($this->db));
        $this->assertSame(2, AP_Forum_Stats::getTotalMembers($this->db));

        $fromCounters = AP_Forum_Stats::getBoardStatsFromForumCounters($this->db);
        $this->assertSame(1, $fromCounters['topics']);
        $this->assertSame(2, $fromCounters['posts']);
        $this->assertSame(2, $fromCounters['members']);
    }

    public function testBoardStatsExcludeSoftDeletedAndUnapproved(): void
    {
        $visible = AP_Forum::createTopic([
            'forum_id' => $this->forumId,
            'topic_title' => 'Visible',
            'content' => 'OK',
            'poster_id' => $this->authorId,
        ], $this->db);
        $this->assertGreaterThan(0, $visible);

        $unapproved = AP_Forum::createTopic([
            'forum_id' => $this->forumId,
            'topic_title' => 'Pending',
            'content' => 'Needs review',
            'poster_id' => $this->authorId,
            'topic_approved' => 0,
        ], $this->db);
        $this->assertGreaterThan(0, $unapproved);

        $stats = AP_Forum_Stats::getBoardStats($this->db);
        $this->assertSame(1, $stats['topics']);
        // Only the approved topic's OP counts.
        $this->assertSame(1, $stats['posts']);

        $this->assertTrue(AP_Forum::deleteTopic($visible, false, $this->db));
        $after = AP_Forum_Stats::getBoardStats($this->db);
        $this->assertSame(0, $after['topics']);
        $this->assertSame(0, $after['posts']);
        $this->assertSame(2, $after['members']);
    }

    /**
     * SPEC §C: Posts = opening posts + replies (not replies-only), consistently
     * across board footer, forum-row post_count, and topic-row posts.
     */
    public function testPostCountDefinitionIsOpeningPlusRepliesEverywhere(): void
    {
        $topicId = AP_Forum::createTopic([
            'forum_id' => $this->forumId,
            'topic_title' => 'Post-count definition topic',
            'content' => 'Opening post only',
            'poster_id' => $this->authorId,
        ], $this->db);
        $this->assertGreaterThan(0, $topicId);

        // New topic: 1 post (OP), 0 replies.
        $topic = AP_Forum::getTopic($topicId, $this->db);
        $this->assertNotNull($topic);
        $this->assertSame(0, (int) $topic->reply_count);
        $topicRow = AP_Forum::topicToDisplayRow($topic, $this->db);
        $this->assertSame(0, $topicRow['replies']);
        $this->assertSame(1, $topicRow['posts']);
        $this->assertSame(1, $topicRow['post_count']);

        $forum = AP_Forum::getForum($this->forumId, $this->db);
        $this->assertNotNull($forum);
        $this->assertSame(1, (int) $forum->post_count);
        $forumRow = AP_Forum::forumToDisplayRow($forum, $this->db);
        $this->assertSame(1, $forumRow['posts']);
        $this->assertSame(1, $forumRow['post_count']);

        $board = AP_Forum_Stats::getBoardStats($this->db);
        $this->assertSame(1, $board['topics']);
        $this->assertSame(1, $board['posts']);

        // One reply → posts become 2 everywhere (still not “replies only” = 1).
        $replyId = AP_Forum::createReply([
            'topic_id' => $topicId,
            'content' => 'A reply',
            'poster_id' => $this->readerId,
        ], $this->db);
        $this->assertGreaterThan(0, $replyId);

        $topic = AP_Forum::getTopic($topicId, $this->db);
        $this->assertNotNull($topic);
        $this->assertSame(1, (int) $topic->reply_count);
        $topicRow = AP_Forum::topicToDisplayRow($topic, $this->db);
        $this->assertSame(1, $topicRow['replies']);
        $this->assertSame(2, $topicRow['posts']);
        $this->assertSame(2, $topicRow['post_count']);

        $forum = AP_Forum::getForum($this->forumId, $this->db);
        $this->assertNotNull($forum);
        $this->assertSame(2, (int) $forum->post_count);
        $forumRow = AP_Forum::forumToDisplayRow($forum, $this->db);
        $this->assertSame(2, $forumRow['posts']);
        $this->assertSame(2, $forumRow['post_count']);

        $board = AP_Forum_Stats::getBoardStats($this->db);
        $this->assertSame(1, $board['topics']);
        $this->assertSame(2, $board['posts']);
        $fromCounters = AP_Forum_Stats::getBoardStatsFromForumCounters($this->db);
        $this->assertSame(2, $fromCounters['posts']);
        $this->assertSame($board['posts'], $fromCounters['posts']);
    }

    public function testBoardStatsFooterHtmlAndWrapper(): void
    {
        $topicId = AP_Forum::createTopic([
            'forum_id' => $this->forumId,
            'topic_title' => 'Footer stats topic',
            'content' => 'Opening',
            'poster_id' => $this->authorId,
        ], $this->db);
        $this->assertGreaterThan(0, $topicId);
        AP_Forum::createReply([
            'topic_id' => $topicId,
            'content' => 'Reply for post count',
            'poster_id' => $this->readerId,
        ], $this->db);

        // Wrapper mirrors class API (posts = OP + replies).
        $viaFn = ap_get_forum_board_stats($this->db);
        $this->assertSame(1, $viaFn['topics']);
        $this->assertSame(2, $viaFn['posts']);
        $this->assertSame(2, $viaFn['members']);

        $html = ap_forum_board_stats_footer_html([], $this->db);
        $this->assertStringContainsString('ap-forum-footer', $html);
        $this->assertStringContainsString('ap-board-stats', $html);
        $this->assertStringContainsString('role="contentinfo"', $html);
        $this->assertStringContainsString('aria-label="Board statistics"', $html);
        $this->assertStringContainsString('Total Topics:', $html);
        $this->assertStringContainsString('Total Posts:', $html);
        $this->assertStringContainsString('Total Members:', $html);
        $this->assertStringContainsString('data-stat="topics"', $html);
        $this->assertStringContainsString('data-stat="posts"', $html);
        $this->assertStringContainsString('data-stat="members"', $html);
        $this->assertStringContainsString('ap-board-stats__sep', $html);
        // Values: 1 topic, 2 posts (OP+reply), 2 members from setUp.
        $this->assertMatchesRegularExpression(
            '/data-stat="topics"[^>]*>\s*1\s*</',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/data-stat="posts"[^>]*>\s*2\s*</',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/data-stat="members"[^>]*>\s*2\s*</',
            $html
        );

        // Precomputed stats path (no DB hit).
        $pre = ap_forum_board_stats_footer_html([
            'topics' => 9,
            'posts' => 42,
            'members' => 7,
            'class' => 'ap-board-stats--test',
        ]);
        $this->assertStringContainsString('ap-board-stats--test', $pre);
        $this->assertMatchesRegularExpression('/data-stat="topics"[^>]*>\s*9\s*</', $pre);
        $this->assertMatchesRegularExpression('/data-stat="posts"[^>]*>\s*42\s*</', $pre);
        $this->assertMatchesRegularExpression('/data-stat="members"[^>]*>\s*7\s*</', $pre);
    }

    // -------------------------------------------------------------------------
    // Forum row payload + preload
    // -------------------------------------------------------------------------

    public function testForumRowPayloadAndPreload(): void
    {
        $forum = AP_Forum::getForum($this->forumId, $this->db);
        $this->assertNotNull($forum);

        $empty = AP_Forum::forumToDisplayRow($forum, $this->db);
        $this->assertSame('standard', $empty['icon_type']);
        $this->assertFalse($empty['is_unread']);
        $this->assertFalse($empty['is_closed']);
        $this->assertFalse($empty['is_locked']);
        $this->assertTrue($empty['is_empty']);
        $this->assertSame(0, $empty['topics']);
        $this->assertSame(0, $empty['posts']);
        $this->assertNull($empty['last_post']);
        $this->assertSame('Phase 1 helper fixtures', $empty['description']);
        $this->assertFalse(AP_Forum::isForumClosed($forum));
        $this->assertFalse(ap_is_forum_closed($forum));

        $topicId = AP_Forum::createTopic([
            'forum_id' => $this->forumId,
            'topic_title' => 'Last-post payload thread',
            'content' => 'OP body',
            'poster_id' => $this->authorId,
        ], $this->db);
        $replyId = AP_Forum::createReply([
            'topic_id' => $topicId,
            'content' => 'Latest reply',
            'poster_id' => $this->readerId,
        ], $this->db);
        $this->assertGreaterThan(0, $replyId);

        $forum = AP_Forum::getForum($this->forumId, $this->db);
        $this->assertNotNull($forum);

        $preload = AP_Forum::buildForumRowPreload([$forum], $this->db);
        $this->assertArrayHasKey('topics', $preload);
        $this->assertArrayHasKey('authors', $preload);
        $this->assertArrayHasKey($topicId, $preload['topics']);
        $this->assertSame(
            'Last-post payload thread',
            (string) $preload['topics'][$topicId]->topic_title
        );
        $this->assertSame('Board Reader', $preload['authors'][$this->readerId] ?? null);

        $row = AP_Forum::forumToDisplayRow($forum, $this->db, $preload);
        $this->assertSame(1, $row['topic_count']);
        $this->assertSame(2, $row['post_count']);
        $this->assertSame(1, $row['topics']);
        $this->assertSame(2, $row['posts']);
        $this->assertFalse($row['is_empty']);
        $this->assertFalse($row['is_closed']);
        $this->assertIsArray($row['last_post']);
        $this->assertSame('Last-post payload thread', $row['last_post']['title']);
        $this->assertSame('Board Reader', $row['last_post']['author']);
        $this->assertNotSame('', (string) $row['last_post']['time']);
        $this->assertSame($row['last_post']['time'], $row['last_post']['date']);
        $this->assertStringContainsString('#post-' . $replyId, (string) $row['last_post']['url']);
        $this->assertSame($replyId, (int) $row['last_post']['post_id']);
        $this->assertSame($topicId, (int) $row['last_post']['topic_id']);
        $this->assertSame($this->readerId, (int) $row['last_post']['author_id']);

        $viaHelper = ap_forum_to_display_row($forum, $this->db, $preload);
        $this->assertSame($row['last_post']['title'], $viaHelper['last_post']['title'] ?? null);
        $this->assertSame($row['icon_type'], $viaHelper['icon_type']);
    }

    public function testClosedForumRowAndEmptyStateHelpers(): void
    {
        $closedId = AP_Forum::insertForum([
            'forum_name' => 'Archive Only',
            'forum_slug' => 'archive-only',
            'forum_type' => 'forum',
            'forum_status' => 'closed',
            'forum_desc' => 'No new topics',
        ], $this->db);
        $this->assertGreaterThan(0, $closedId);

        $closed = AP_Forum::getForum($closedId, $this->db);
        $this->assertNotNull($closed);
        $this->assertTrue(AP_Forum::isForumClosed($closed));
        $this->assertTrue(ap_is_forum_closed($closed));
        $this->assertTrue(ap_is_forum_closed('closed'));
        $this->assertFalse(ap_is_forum_closed('open'));

        $row = AP_Forum::forumToDisplayRow($closed, $this->db);
        $this->assertSame('locked', $row['icon_type']);
        $this->assertTrue($row['is_closed']);
        $this->assertTrue($row['is_locked']);
        $this->assertTrue($row['is_empty']);
        $this->assertNull($row['last_post']);
        $this->assertSame(0, $row['topics']);
        $this->assertSame(0, $row['posts']);

        $lastHtml = ap_forum_empty_last_post_html();
        $this->assertStringContainsString('No posts', $lastHtml);
        $this->assertStringContainsString('ap-forum-last-post__title', $lastHtml);
        $this->assertStringContainsString('ap-forum-list__empty', $lastHtml);
        $this->assertStringContainsString('—', $lastHtml);

        $boardEmpty = ap_forum_empty_state_html('board_empty');
        $this->assertStringContainsString('ap-forum-empty--board_empty', $boardEmpty);
        $this->assertStringContainsString('No forums have been created yet.', $boardEmpty);
        $this->assertStringContainsString('role="status"', $boardEmpty);

        $forumEmptyOpen = ap_forum_empty_state_html('forum_empty', [
            'can_post' => true,
            'cta_url' => '#new-topic',
        ]);
        $this->assertStringContainsString('ap-forum-empty--forum_empty', $forumEmptyOpen);
        $this->assertStringContainsString('No topics yet in this forum.', $forumEmptyOpen);
        $this->assertStringContainsString('Start the first topic', $forumEmptyOpen);
        $this->assertStringContainsString('href="#new-topic"', $forumEmptyOpen);
        $this->assertStringNotContainsString('Be the first to start a conversation.', $forumEmptyOpen);

        $forumEmptyGuest = ap_forum_empty_state_html('forum_empty', ['can_post' => false]);
        $this->assertStringContainsString('Be the first to start a conversation.', $forumEmptyGuest);
        $this->assertStringNotContainsString('Start the first topic', $forumEmptyGuest);

        $closedEmpty = ap_forum_empty_state_html('forum_empty_closed');
        $this->assertStringContainsString('ap-forum-empty--forum_empty_closed', $closedEmpty);
        $this->assertStringContainsString('closed and has no topics', $closedEmpty);

        $closedBanner = ap_forum_empty_state_html('forum_closed');
        $this->assertStringContainsString('New topics are not accepted', $closedBanner);

        $topicLocked = ap_forum_empty_state_html('topic_locked');
        $this->assertStringContainsString('ap-forum-empty--topic_locked', $topicLocked);
        $this->assertStringContainsString('This topic is locked', $topicLocked);

        $notFound = ap_forum_empty_state_html('forum_not_found', [
            'back_url' => '/forums/',
        ]);
        $this->assertStringContainsString('Forum not found.', $notFound);
        $this->assertStringContainsString('href="/forums/"', $notFound);
        $this->assertStringContainsString('Back to forums', $notFound);
    }

    public function testIndexDataAnnotatesUnreadForLoggedInUser(): void
    {
        $topicId = AP_Forum::createTopic([
            'forum_id' => $this->forumId,
            'topic_title' => 'Unread me',
            'content' => 'New content',
            'poster_id' => $this->authorId,
        ], $this->db);
        $this->assertGreaterThan(0, $topicId);

        $index = AP_Forum::getIndexData($this->db, ['user_id' => $this->readerId]);
        $found = $this->findForumRow($index, $this->forumId);
        $this->assertNotNull($found);
        $this->assertTrue($found['is_unread']);
        $this->assertSame('standard', $found['icon_type']);
        $this->assertSame(1, (int) $found['topic_count']);
        $this->assertIsArray($found['last_post'] ?? null);

        AP_Forum_Read::markTopicRead($this->readerId, $topicId, $this->db);
        $indexRead = AP_Forum::getIndexData($this->db, ['user_id' => $this->readerId]);
        $foundRead = $this->findForumRow($indexRead, $this->forumId);
        $this->assertNotNull($foundRead);
        $this->assertFalse($foundRead['is_unread']);

        // Guests stay neutral (not claimed unread).
        $guestIndex = AP_Forum::getIndexData($this->db, ['user_id' => 0]);
        $guestRow = $this->findForumRow($guestIndex, $this->forumId);
        $this->assertNotNull($guestRow);
        $this->assertFalse($guestRow['is_unread']);
    }

    /**
     * @param list<array{name?: string, forums?: list<array<string, mixed>>}> $index
     *
     * @return array<string, mixed>|null
     */
    private function findForumRow(array $index, int $forumId): ?array
    {
        foreach ($index as $cat) {
            foreach ($cat['forums'] ?? [] as $f) {
                if ((int) ($f['id'] ?? 0) === $forumId) {
                    return $f;
                }
            }
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Read markers / first unread
    // -------------------------------------------------------------------------

    public function testFirstUnreadPostHelper(): void
    {
        $topicId = AP_Forum::createTopic([
            'forum_id' => $this->forumId,
            'topic_title' => 'First unread chain',
            'content' => 'OP',
            'poster_id' => $this->authorId,
        ], $this->db);
        $topic = AP_Forum::getTopic($topicId, $this->db);
        $this->assertNotNull($topic);
        $firstPostId = (int) $topic->first_post_id;
        $this->assertGreaterThan(0, $firstPostId);

        // Never visited: first post is first unread.
        $this->assertSame(
            $firstPostId,
            AP_Forum_Read::getFirstUnreadPostId($this->readerId, $topicId, $this->db)
        );
        $this->assertSame(
            $firstPostId,
            ap_get_first_unread_post_id($this->readerId, $topicId, $this->db)
        );

        $op = AP_Forum::getPost($firstPostId, $this->db);
        $this->assertNotNull($op);
        $opTime = (string) $op->post_time;

        // Mark as of OP time (fully read until a newer reply lands).
        $this->assertTrue(AP_Forum_Read::markTopicRead($this->readerId, $topicId, $this->db, [
            'mark_time' => $opTime,
        ]));
        $this->assertSame(0, AP_Forum_Read::getFirstUnreadPostId($this->readerId, $topicId, $this->db));

        $replyId = AP_Forum::createReply([
            'topic_id' => $topicId,
            'content' => 'New after mark',
            'poster_id' => $this->authorId,
        ], $this->db);
        $this->assertGreaterThan(0, $replyId);

        // Same-second clocks are common in tests — force reply strictly after OP mark.
        $opTs = strtotime($opTime) ?: time();
        $replyTime = date('Y-m-d H:i:s', $opTs + 60);
        $this->db->update(
            'forum_posts',
            ['post_time' => $replyTime],
            ['post_id' => $replyId]
        );
        $this->db->update(
            'topics',
            ['topic_last_post_time' => $replyTime],
            ['topic_id' => $topicId]
        );

        $this->assertSame(
            $replyId,
            AP_Forum_Read::getFirstUnreadPostId($this->readerId, $topicId, $this->db)
        );
        $post = ap_get_first_unread_post($this->readerId, $topicId, $this->db);
        $this->assertNotNull($post);
        $this->assertSame($replyId, (int) $post->post_id);

        // Guests: no first-unread jump.
        $this->assertSame(0, AP_Forum_Read::getFirstUnreadPostId(0, $topicId, $this->db));

        // Fully read after marking through the reply.
        $this->assertTrue(AP_Forum_Read::markTopicRead($this->readerId, $topicId, $this->db, [
            'mark_time' => $replyTime,
        ]));
        $this->assertSame(0, AP_Forum_Read::getFirstUnreadPostId($this->readerId, $topicId, $this->db));
    }

    public function testFirstUnreadLinkHtmlHelper(): void
    {
        $this->assertSame('', ap_forum_first_unread_link_html(0));
        $this->assertSame('', ap_forum_first_unread_link_html(-1));

        $html = ap_forum_first_unread_link_html(42);
        $this->assertStringContainsString('aria-label="First unread post"', $html);
        $this->assertStringContainsString('ap-forum-first-unread-wrap', $html);
        $this->assertStringContainsString('ap-forum-first-unread', $html);
        $this->assertStringContainsString('href="#post-42"', $html);
        $this->assertStringContainsString('First unread post', $html);

        $custom = ap_forum_first_unread_link_html(7, [
            'label' => 'Jump to unread',
            'href' => '/topic/x/#post-7',
            'class' => 'is-active',
            'wrap_class' => 'ap-forum-first-unread-wrap--toolbar',
        ]);
        $this->assertStringContainsString('Jump to unread', $custom);
        $this->assertStringContainsString('href="/topic/x/#post-7"', $custom);
        $this->assertStringContainsString('is-active', $custom);
        $this->assertStringContainsString('ap-forum-first-unread-wrap--toolbar', $custom);
    }

    // -------------------------------------------------------------------------
    // Author-panel like aggregates
    // -------------------------------------------------------------------------

    public function testAuthorPanelLikeAggregates(): void
    {
        $topicId = AP_Forum::createTopic([
            'forum_id' => $this->forumId,
            'topic_title' => 'Like panel',
            'content' => 'OP for likes',
            'poster_id' => $this->authorId,
        ], $this->db);
        $topic = AP_Forum::getTopic($topicId, $this->db);
        $this->assertNotNull($topic);
        $postId = (int) $topic->first_post_id;

        $toggle = AP_Forum_Like::toggle($postId, $this->readerId, $this->db);
        $this->assertTrue($toggle['ok']);
        $this->assertTrue($toggle['liked']);

        $panel = AP_Forum_Stats::getAuthorPanelStats($this->authorId, $this->db);
        $this->assertArrayHasKey('forum_posts', $panel);
        $this->assertArrayHasKey('forum_likes_given', $panel);
        $this->assertArrayHasKey('forum_likes_received', $panel);
        $this->assertSame(1, $panel['forum_likes_received']);
        $this->assertGreaterThanOrEqual(1, $panel['forum_posts']);

        $giver = AP_Forum_Stats::getAuthorPanelStats($this->readerId, $this->db);
        $this->assertSame(1, $giver['forum_likes_given']);
        $this->assertSame(0, $giver['forum_likes_received']);

        $batch = AP_Forum_Stats::getAuthorPanelStatsForUsers(
            [$this->authorId, $this->readerId],
            $this->db
        );
        $this->assertSame(1, $batch[$this->authorId]['forum_likes_received']);
        $this->assertSame(1, $batch[$this->readerId]['forum_likes_given']);

        // Live SQL matches denormalized meta.
        $this->assertSame(1, AP_Forum_Stats::queryLikesReceived($this->authorId, $this->db));
        $this->assertSame(1, AP_Forum_Stats::queryLikesGiven($this->readerId, $this->db));
    }

    // -------------------------------------------------------------------------
    // Board-index performance (no N+1)
    // -------------------------------------------------------------------------

    /**
     * getIndexData query count must stay O(1) in forum count (not per-row SELECTs
     * for last-post authors or isForumUnread).
     */
    public function testBoardIndexQueryCountDoesNotScaleWithForumCount(): void
    {
        $catId = AP_Forum::insertForum([
            'forum_name' => 'Perf Category',
            'forum_slug' => 'perf-category',
            'forum_type' => 'category',
            'forum_status' => 'open',
            'forum_order' => 1,
        ], $this->db);
        $this->assertGreaterThan(0, $catId);

        $forumCount = 12;
        $forumIds = [];
        for ($i = 1; $i <= $forumCount; $i++) {
            $fid = AP_Forum::insertForum([
                'forum_name' => 'Perf Forum ' . $i,
                'forum_slug' => 'perf-forum-' . $i,
                'forum_type' => 'forum',
                'forum_status' => 'open',
                'parent_id' => $catId,
                'forum_order' => $i,
            ], $this->db);
            $this->assertGreaterThan(0, $fid);
            $forumIds[] = $fid;

            $poster = ($i % 2 === 0) ? $this->readerId : $this->authorId;
            $tid = AP_Forum::createTopic([
                'forum_id' => $fid,
                'topic_title' => 'Perf topic ' . $i,
                'content' => 'Body ' . $i,
                'poster_id' => $poster,
            ], $this->db);
            $this->assertGreaterThan(0, $tid);
        }

        // Warm option / module caches so availability checks do not dominate.
        $this->assertTrue(AP_Forum_Read::isAvailable($this->db));
        AP_Forum::getIndexData($this->db, ['user_id' => $this->readerId]);

        $this->db->resetQueryLog();
        $index = AP_Forum::getIndexData($this->db, ['user_id' => $this->readerId]);
        $queries = $this->db->getNumQueries();

        $found = 0;
        foreach ($index as $cat) {
            foreach ($cat['forums'] ?? [] as $row) {
                if (in_array((int) ($row['id'] ?? 0), $forumIds, true)) {
                    $found++;
                    $this->assertTrue(
                        !empty($row['is_unread']),
                        'Perf forum should be unread for reader'
                    );
                    $this->assertIsArray($row['last_post'] ?? null);
                    $this->assertNotSame('', (string) ($row['last_post']['author'] ?? ''));
                }
            }
        }
        $this->assertSame($forumCount, $found);

        // Absolute ceiling: well below N * k (e.g. N forums × ~5 queries).
        $this->assertLessThan(
            40,
            $queries,
            'Board index should use a bounded query count, got ' . $queries
        );
        // Must not grow ~linearly with forum count (author / unread N+1).
        $this->assertLessThan(
            $forumCount,
            $queries,
            'Query count must be sub-linear in forum count (got ' . $queries
            . ' for ' . $forumCount . ' forums)'
        );

        // Doubling forums must not double queries (compare delta on a second board).
        $extra = 12;
        for ($i = $forumCount + 1; $i <= $forumCount + $extra; $i++) {
            $fid = AP_Forum::insertForum([
                'forum_name' => 'Perf Forum ' . $i,
                'forum_slug' => 'perf-forum-' . $i,
                'forum_type' => 'forum',
                'forum_status' => 'open',
                'parent_id' => $catId,
                'forum_order' => $i,
            ], $this->db);
            AP_Forum::createTopic([
                'forum_id' => $fid,
                'topic_title' => 'Perf topic ' . $i,
                'content' => 'Body ' . $i,
                'poster_id' => $this->authorId,
            ], $this->db);
        }

        $this->db->resetQueryLog();
        AP_Forum::getIndexData($this->db, ['user_id' => $this->readerId]);
        $queries2 = $this->db->getNumQueries();

        $this->assertLessThan(
            40,
            $queries2,
            'Larger board must stay within the same query budget, got ' . $queries2
        );
        // Second pass should not scale with the +12 forums (allow small jitter).
        $this->assertLessThanOrEqual(
            $queries + 5,
            $queries2,
            'Query count should stay flat when forum count grows (q1='
            . $queries . ', q2=' . $queries2 . ')'
        );
    }

    public function testAnnotateForumsBulkMatchesSingleForumUnread(): void
    {
        $forumB = AP_Forum::insertForum([
            'forum_name' => 'Second forum',
            'forum_slug' => 'second-forum-bulk',
            'forum_type' => 'forum',
            'forum_status' => 'open',
        ], $this->db);
        $this->assertGreaterThan(0, $forumB);

        $t1 = AP_Forum::createTopic([
            'forum_id' => $this->forumId,
            'topic_title' => 'Bulk A',
            'content' => 'A',
            'poster_id' => $this->authorId,
        ], $this->db);
        $t2 = AP_Forum::createTopic([
            'forum_id' => $forumB,
            'topic_title' => 'Bulk B',
            'content' => 'B',
            'poster_id' => $this->authorId,
        ], $this->db);
        $this->assertGreaterThan(0, $t1);
        $this->assertGreaterThan(0, $t2);

        AP_Forum_Read::markTopicRead($this->readerId, $t1, $this->db);

        $rows = [
            ['id' => $this->forumId, 'name' => 'A'],
            ['id' => $forumB, 'name' => 'B'],
        ];
        $annotated = AP_Forum_Read::annotateForums($this->readerId, $rows, $this->db);
        $this->assertFalse($annotated[0]['is_unread']);
        $this->assertTrue($annotated[1]['is_unread']);
        $this->assertFalse(AP_Forum_Read::isForumUnread($this->readerId, $this->forumId, $this->db));
        $this->assertTrue(AP_Forum_Read::isForumUnread($this->readerId, $forumB, $this->db));
    }

    public function testGetAuthorDisplayNamesIsSingleQuery(): void
    {
        $ids = [$this->authorId, $this->readerId];
        $this->db->resetQueryLog();
        $names = AP_Forum::getAuthorDisplayNames($ids, $this->db);
        $n = $this->db->getNumQueries();

        $this->assertSame('Board Author', $names[$this->authorId] ?? null);
        $this->assertSame('Board Reader', $names[$this->readerId] ?? null);
        $this->assertSame(1, $n, 'Author display names should use one IN query');
    }
}
