<?php

/**
 * Tests for forum likes, edit/delete permissions, and user stats.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Forum;

use AP_DB;
use AP_Forum;
use AP_Forum_Front;
use AP_Forum_Like;
use AP_Forum_Permissions;
use AP_Forum_Stats;
use AP_Group;
use AP_Migrator;
use AP_Roles;
use AP_Session;
use AP_User;
use PDO;
use PHPUnit\Framework\TestCase;

final class ForumLikesStatsTest extends TestCase
{
    private string $root;

    private AP_DB $db;

    private int $authorId = 0;

    private int $otherId = 0;

    private int $modId = 0;

    private int $forumId = 0;

    private int $topicId = 0;

    private int $replyId = 0;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        require_once $this->root . '/ap-includes/version.php';
        require_once $this->root . '/ap-includes/class-ap-db.php';
        require_once $this->root . '/ap-includes/class-ap-migrator.php';
        require_once $this->root . '/ap-includes/class-ap-options.php';
        require_once $this->root . '/ap-includes/class-ap-user.php';
        require_once $this->root . '/ap-includes/class-ap-roles.php';
        require_once $this->root . '/ap-includes/class-ap-group.php';
        require_once $this->root . '/ap-includes/class-ap-forum.php';
        require_once $this->root . '/ap-includes/class-ap-forum-permissions.php';
        require_once $this->root . '/ap-includes/class-ap-forum-moderation.php';
        require_once $this->root . '/ap-includes/class-ap-forum-like.php';
        require_once $this->root . '/ap-includes/class-ap-forum-stats.php';
        require_once $this->root . '/ap-includes/class-ap-forum-front.php';
        require_once $this->root . '/ap-includes/class-ap-content-format.php';
        require_once $this->root . '/ap-includes/class-ap-session.php';
        require_once $this->root . '/ap-includes/hooks.php';
        require_once $this->root . '/ap-includes/functions.php';

        if (!defined('AP_LOGGED_IN_KEY')) {
            define('AP_LOGGED_IN_KEY', 'test-logged-in-key');
        }
        if (!defined('AP_LOGGED_IN_SALT')) {
            define('AP_LOGGED_IN_SALT', 'test-logged-in-salt');
        }
        if (!defined('AP_AUTH_KEY')) {
            define('AP_AUTH_KEY', 'test-auth-key');
        }
        if (!defined('AP_AUTH_SALT')) {
            define('AP_AUTH_SALT', 'test-auth-salt');
        }
        if (!defined('AP_NONCE_KEY')) {
            define('AP_NONCE_KEY', 'test-nonce-key');
        }
        if (!defined('AP_NONCE_SALT')) {
            define('AP_NONCE_SALT', 'test-nonce-salt');
        }

        if (function_exists('ap_reset_hooks')) {
            ap_reset_hooks();
        }
        AP_Forum_Permissions::flushCache();
        AP_Forum_Stats::registerHooks();
        AP_Session::enableTestMode();
        AP_Session::resetCurrentUser();

        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');
        (new AP_Migrator($this->db, AP_Migrator::defaultMigrationsPath()))->migrate();
        $GLOBALS['apdb'] = $this->db;

        AP_Roles::ensureDefaults($this->db);
        if (method_exists('AP_Group', 'ensureDefaults')) {
            AP_Group::ensureDefaults($this->db);
        }
        if (method_exists('AP_Forum_Permissions', 'ensureDefaults')) {
            AP_Forum_Permissions::ensureDefaults($this->db);
        }

        $this->authorId = $this->createUser('poster', 'subscriber');
        $this->otherId = $this->createUser('liker', 'subscriber');
        $this->modId = $this->createUser('moddy', 'administrator');

        $this->forumId = AP_Forum::insertForum([
            'forum_name' => 'General',
            'forum_slug' => 'general',
            'forum_type' => 'forum',
            'forum_status' => 'open',
        ], $this->db);
        $this->assertGreaterThan(0, $this->forumId);

        $this->topicId = AP_Forum::createTopic([
            'forum_id' => $this->forumId,
            'topic_title' => 'Hello likes',
            'content' => 'First post body',
            'poster_id' => $this->authorId,
        ], $this->db);
        $this->assertGreaterThan(0, $this->topicId);

        $this->replyId = AP_Forum::createReply([
            'topic_id' => $this->topicId,
            'content' => 'A reply',
            'poster_id' => $this->authorId,
        ], $this->db);
        $this->assertGreaterThan(0, $this->replyId);
    }

    protected function tearDown(): void
    {
        AP_Session::resetCurrentUser();
        AP_Session::disableTestMode();
        AP_Forum_Permissions::flushCache();
        unset($GLOBALS['apdb']);
    }

    private function createUser(string $login, string $role): int
    {
        $result = AP_User::create([
            'user_login' => $login,
            'user_email' => $login . '@example.test',
            'user_pass' => 'SecurePass99!',
            'role' => $role,
            'display_name' => ucfirst($login),
        ], $this->db);
        $this->assertTrue(!empty($result['ok']), implode(' ', $result['errors'] ?? []));

        return (int) $result['id'];
    }

    public function testSchemaHasLikesTableAndLikeCount(): void
    {
        $this->assertTrue(defined('AP_DB_VERSION'));
        $this->assertGreaterThanOrEqual(11, (int) AP_DB_VERSION);
        $this->assertContains('forum_post_likes', AP_Forum::baseTables());
        $post = AP_Forum::getPost($this->replyId, $this->db);
        $this->assertNotNull($post);
        $this->assertSame(0, (int) ($post->like_count ?? 0));
    }

    public function testLikeUnlikeAndStats(): void
    {
        $r = AP_Forum_Like::toggle($this->replyId, $this->otherId, $this->db);
        $this->assertTrue($r['ok']);
        $this->assertTrue($r['liked']);
        $this->assertSame(1, $r['count']);
        $this->assertTrue(AP_Forum_Like::userLiked($this->otherId, $this->replyId, $this->db));

        $post = AP_Forum::getPost($this->replyId, $this->db);
        $this->assertSame(1, (int) ($post->like_count ?? 0));

        $given = AP_Forum_Stats::getUserStats($this->otherId, $this->db);
        $this->assertSame(1, $given['forum_likes_given']);
        $this->assertSame(1, AP_Forum_Stats::getLikesGiven($this->otherId, $this->db));
        $recv = AP_Forum_Stats::getUserStats($this->authorId, $this->db);
        $this->assertSame(1, $recv['forum_likes_received']);
        $this->assertSame(1, AP_Forum_Stats::getLikesReceived($this->authorId, $this->db));

        // Live SQL aggregates must match denormalized usermeta after like/unlike.
        $this->assertSame(1, AP_Forum_Stats::queryLikesGiven($this->otherId, $this->db));
        $this->assertSame(1, AP_Forum_Stats::queryLikesReceived($this->authorId, $this->db));

        $r2 = AP_Forum_Like::toggle($this->replyId, $this->otherId, $this->db);
        $this->assertTrue($r2['ok']);
        $this->assertFalse($r2['liked']);
        $this->assertSame(0, $r2['count']);
        $this->assertSame(0, AP_Forum_Stats::getLikesGiven($this->otherId, $this->db));
        $this->assertSame(0, AP_Forum_Stats::getLikesReceived($this->authorId, $this->db));
    }

    public function testBatchUsersStatsAndLiveAggregates(): void
    {
        // Liker likes author's reply; author likes nothing yet.
        AP_Forum_Like::like($this->replyId, $this->otherId, $this->db);

        // Second liker on the first post of the topic.
        $liker2 = $this->createUser('liker2', 'subscriber');
        $firstPostId = 0;
        $posts = AP_Forum::getPosts($this->topicId, ['per_page' => 50], $this->db);
        foreach ($posts as $p) {
            if ((int) ($p->post_position ?? 0) === 1 || $firstPostId === 0) {
                $firstPostId = (int) $p->post_id;
                break;
            }
        }
        $this->assertGreaterThan(0, $firstPostId);
        AP_Forum_Like::like($firstPostId, $liker2, $this->db);

        $batch = AP_Forum_Stats::getUsersStats(
            [$this->authorId, $this->otherId, $liker2, 0, -1],
            $this->db
        );
        $this->assertArrayHasKey($this->authorId, $batch);
        $this->assertArrayHasKey($this->otherId, $batch);
        $this->assertArrayHasKey($liker2, $batch);
        $this->assertSame(2, $batch[$this->authorId]['forum_likes_received']);
        $this->assertSame(0, $batch[$this->authorId]['forum_likes_given']);
        $this->assertSame(1, $batch[$this->otherId]['forum_likes_given']);
        $this->assertSame(0, $batch[$this->otherId]['forum_likes_received']);
        $this->assertSame(1, $batch[$liker2]['forum_likes_given']);
        $this->assertSame(2, $batch[$this->authorId]['forum_posts']);

        $panel = AP_Forum_Stats::getAuthorPanelStatsForUsers(
            [$this->authorId, $this->otherId],
            $this->db
        );
        $this->assertSame(2, $panel[$this->authorId]['forum_likes_received']);
        $this->assertSame(0, $panel[$this->authorId]['forum_likes_given']);
        $this->assertSame(1, $panel[$this->otherId]['forum_likes_given']);
        $this->assertArrayNotHasKey('comments', $panel[$this->authorId]);

        $live = AP_Forum_Stats::queryLikesAggregatesForUsers(
            [$this->authorId, $this->otherId, $liker2],
            $this->db
        );
        $this->assertSame(2, $live[$this->authorId]['received']);
        $this->assertSame(0, $live[$this->authorId]['given']);
        $this->assertSame(1, $live[$this->otherId]['given']);
        $this->assertSame(1, $live[$liker2]['given']);

        // Corrupt usermeta then rebuild from live aggregates.
        AP_User::updateMeta($this->authorId, AP_Forum_Stats::META_LIKES_RECEIVED, '99', $this->db);
        $this->assertSame(99, AP_Forum_Stats::getLikesReceived($this->authorId, $this->db));
        $rebuilt = AP_Forum_Stats::rebuildLikeCountsForUsers(
            [$this->authorId, $this->otherId, $liker2],
            $this->db
        );
        $this->assertSame(2, $rebuilt[$this->authorId]['received']);
        $this->assertSame(2, AP_Forum_Stats::getLikesReceived($this->authorId, $this->db));
        $this->assertSame(1, AP_Forum_Stats::getLikesGiven($this->otherId, $this->db));
    }

    public function testAuthorCanEditOwnReply(): void
    {
        $post = AP_Forum::getPost($this->replyId, $this->db);
        $this->assertTrue(AP_Forum::userCanEditPost($this->authorId, $post, $this->db));
        $this->assertFalse(AP_Forum::userCanEditPost($this->otherId, $post, $this->db));
        $this->assertTrue(AP_Forum::userCanEditPost($this->modId, $post, $this->db));

        $ok = AP_Forum::updatePost($this->replyId, [
            'content' => 'Edited reply',
            'post_edit_user' => $this->authorId,
        ], $this->db);
        $this->assertTrue($ok);
        $updated = AP_Forum::getPost($this->replyId, $this->db);
        $this->assertStringContainsString('Edited', (string) $updated->post_content);
        $this->assertGreaterThan(0, (int) $updated->post_edit_count);
    }

    public function testBuildQuoteMarkupAndFromPost(): void
    {
        $markup = AP_Forum::buildQuoteMarkup('Ada', "Hello\nworld");
        $this->assertStringStartsWith('[quote=Ada]', $markup);
        $this->assertStringContainsString("Hello\nworld", $markup);
        $this->assertStringEndsWith("[/quote]\n\n", $markup);

        // Strip unsafe attribute characters from author.
        $safe = AP_Forum::buildQuoteMarkup('Evil]name[x', 'body');
        $this->assertStringStartsWith('[quote=Evilnamex]', $safe);

        // Soft-cap long bodies.
        $long = str_repeat('x', 2500);
        $capped = AP_Forum::buildQuoteMarkup('Bob', $long, 100);
        $this->assertStringContainsString('…', $capped);
        $this->assertLessThan(200, strlen($capped));

        $fromPost = AP_Forum::getQuoteMarkupForPost($this->replyId, $this->db);
        $this->assertStringContainsString('[quote=', $fromPost);
        $this->assertStringContainsString('A reply', $fromPost);
        $this->assertStringContainsString('Poster', $fromPost); // display_name from createUser

        $this->assertSame('', AP_Forum::getQuoteMarkupForPost(0, $this->db));
        $this->assertSame('', AP_Forum::getQuoteMarkupForPost(999999, $this->db));

        if (function_exists('ap_forum_build_quote_markup')) {
            $this->assertStringContainsString('[quote=Zed]', ap_forum_build_quote_markup('Zed', 'hi'));
        }
        if (function_exists('ap_forum_quote_for_post')) {
            $this->assertStringContainsString('A reply', ap_forum_quote_for_post($this->replyId, $this->db));
        }
    }

    public function testDisplayDataActionFlagsRespectCaps(): void
    {
        // Guest viewer: no quote/like/edit.
        $guestRows = AP_Forum::getPostsDisplayData($this->topicId, ['per_page' => 50], $this->db);
        $this->assertNotEmpty($guestRows);
        foreach ($guestRows as $row) {
            $this->assertFalse((bool) ($row['can_quote'] ?? false), 'guest cannot quote');
            $this->assertFalse((bool) ($row['can_like'] ?? false), 'guest cannot like');
            $this->assertFalse((bool) ($row['can_edit'] ?? false), 'guest cannot edit');
        }

        // Logged-in member who can reply: quote + like; edit only own posts.
        if (class_exists('AP_Session', false)) {
            AP_Session::enableTestMode();
            AP_Session::resetCurrentUser();
            $this->assertTrue(AP_Session::setAuthCookie($this->otherId, false, $this->db));
        }

        $memberRows = AP_Forum::getPostsDisplayData($this->topicId, ['per_page' => 50], $this->db);
        $replyRow = null;
        foreach ($memberRows as $row) {
            $this->assertTrue((bool) ($row['can_quote'] ?? false), 'member with reply cap can quote');
            $this->assertTrue((bool) ($row['can_like'] ?? false), 'member can like');
            if ((int) ($row['id'] ?? 0) === $this->replyId) {
                $replyRow = $row;
            }
        }
        $this->assertIsArray($replyRow);
        // otherId did not author the reply → cannot edit/delete.
        $this->assertFalse((bool) ($replyRow['can_edit'] ?? false));
        $this->assertFalse((bool) ($replyRow['can_delete'] ?? false));

        // Author can edit own reply.
        if (class_exists('AP_Session', false)) {
            AP_Session::resetCurrentUser();
            $this->assertTrue(AP_Session::setAuthCookie($this->authorId, false, $this->db));
        }
        $authorRows = AP_Forum::getPostsDisplayData($this->topicId, ['per_page' => 50], $this->db);
        foreach ($authorRows as $row) {
            if ((int) ($row['id'] ?? 0) === $this->replyId) {
                $this->assertTrue((bool) ($row['can_edit'] ?? false));
                $this->assertTrue((bool) ($row['can_quote'] ?? false));
                $this->assertTrue((bool) ($row['can_like'] ?? false));
            }
        }

        // Locked topic: no quote.
        AP_Forum::updateTopic($this->topicId, ['topic_status' => AP_Forum::TOPIC_STATUS_LOCKED], $this->db);
        if (class_exists('AP_Session', false)) {
            AP_Session::resetCurrentUser();
            AP_Session::setAuthCookie($this->otherId, false, $this->db);
        }
        $lockedRows = AP_Forum::getPostsDisplayData($this->topicId, ['per_page' => 50], $this->db);
        foreach ($lockedRows as $row) {
            $this->assertFalse((bool) ($row['can_quote'] ?? false), 'locked topic blocks quote');
        }
        // Restore open for later tests if any.
        AP_Forum::updateTopic($this->topicId, ['topic_status' => AP_Forum::TOPIC_STATUS_OPEN], $this->db);

        if (class_exists('AP_Session', false)) {
            AP_Session::resetCurrentUser();
            AP_Session::disableTestMode();
        }
    }

    public function testDisplayDataIncludesActionsAndLikes(): void
    {
        AP_Forum_Like::like($this->replyId, $this->otherId, $this->db);
        // Simulate viewer = other
        if (class_exists('AP_Session', false)) {
            // postToDisplayData uses ap_get_current_user_id — may be 0 in unit tests
        }
        $rows = AP_Forum::getPostsDisplayData($this->topicId, ['per_page' => 50], $this->db);
        $this->assertNotEmpty($rows);
        $found = null;
        foreach ($rows as $row) {
            if ((int) ($row['id'] ?? 0) === $this->replyId) {
                $found = $row;
                break;
            }
        }
        $this->assertIsArray($found);
        $this->assertSame(1, (int) ($found['like_count'] ?? 0));
        $this->assertArrayHasKey('can_edit', $found);
        $this->assertArrayHasKey('can_delete', $found);
        $this->assertArrayHasKey('author_stats', $found);
        $stats = $found['author_stats'];
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('forum_posts', $stats);
        $this->assertArrayHasKey('forum_likes_given', $stats);
        $this->assertArrayHasKey('forum_likes_received', $stats);
        $this->assertSame(1, (int) $stats['forum_likes_received']);
        $this->assertSame(0, (int) $stats['forum_likes_given']);
        $this->assertSame(2, (int) $stats['forum_posts']);
        // Two-pane author pane hooks (role / profile / avatar / joined / location).
        $this->assertArrayHasKey('role', $found);
        $this->assertArrayHasKey('author_url', $found);
        $this->assertArrayHasKey('avatar_html', $found);
        $this->assertArrayHasKey('author_id', $found);
        $this->assertArrayHasKey('joined', $found);
        $this->assertArrayHasKey('location', $found);
        $this->assertArrayHasKey('signature', $found);
        $this->assertArrayHasKey('signature_html', $found);
        $this->assertGreaterThan(0, (int) $found['author_id']);
        // Joined is the registration timestamp for registered posters.
        $this->assertNotSame('', (string) $found['joined']);
        // Location / signature empty by default (omit in UI when empty).
        $this->assertSame('', (string) $found['location']);
        $this->assertSame('', (string) $found['signature']);
        $this->assertSame('', (string) $found['signature_html']);

        AP_User::updateMeta($this->authorId, 'location', 'Athens, GR', $this->db);
        $rowsWithLoc = AP_Forum::getPostsDisplayData($this->topicId, ['per_page' => 50], $this->db);
        $foundLoc = null;
        foreach ($rowsWithLoc as $row) {
            if ((int) ($row['id'] ?? 0) === $this->replyId) {
                $foundLoc = $row;
                break;
            }
        }
        $this->assertIsArray($foundLoc);
        $this->assertSame('Athens, GR', (string) ($foundLoc['location'] ?? ''));

        // Preloaded path used by getPostsDisplayData must match single-row path.
        $single = AP_Forum::postToDisplayRow(
            AP_Forum::getPost($this->replyId, $this->db),
            2,
            $this->db
        );
        $this->assertSame(
            (int) $stats['forum_likes_received'],
            (int) $single['author_stats']['forum_likes_received']
        );
        $this->assertSame(
            (int) $stats['forum_likes_given'],
            (int) $single['author_stats']['forum_likes_given']
        );
        $this->assertSame((string) $found['joined'], (string) $single['joined']);
        $this->assertSame('Athens, GR', (string) $single['location']);
    }

    public function testSignatureShownWhenEnabledAndPresent(): void
    {
        $this->assertTrue(AP_Forum::signaturesEnabled($this->db));

        AP_User::updateMeta($this->authorId, 'signature', 'With **style** from Athens.', $this->db);

        $rows = AP_Forum::getPostsDisplayData($this->topicId, ['per_page' => 50], $this->db);
        $found = null;
        foreach ($rows as $row) {
            if ((int) ($row['id'] ?? 0) === $this->replyId) {
                $found = $row;
                break;
            }
        }
        $this->assertIsArray($found);
        $this->assertSame('With **style** from Athens.', (string) ($found['signature'] ?? ''));
        $this->assertNotSame('', (string) ($found['signature_html'] ?? ''));
        $this->assertStringContainsString('Athens', (string) $found['signature_html']);

        $single = AP_Forum::postToDisplayRow(
            AP_Forum::getPost($this->replyId, $this->db),
            2,
            $this->db
        );
        $this->assertSame('With **style** from Athens.', (string) $single['signature']);
        $this->assertNotSame('', (string) $single['signature_html']);
    }

    public function testSignatureHiddenWhenGloballyDisabled(): void
    {
        AP_User::updateMeta($this->authorId, 'signature', 'Should not appear.', $this->db);
        if (function_exists('ap_update_option')) {
            ap_update_option('forum_signatures_enabled', '0', $this->db);
        } else {
            AP_Options::update('forum_signatures_enabled', '0', $this->db);
        }
        $this->assertFalse(AP_Forum::signaturesEnabled($this->db));

        $rows = AP_Forum::getPostsDisplayData($this->topicId, ['per_page' => 50], $this->db);
        $found = null;
        foreach ($rows as $row) {
            if ((int) ($row['id'] ?? 0) === $this->replyId) {
                $found = $row;
                break;
            }
        }
        $this->assertIsArray($found);
        $this->assertSame('', (string) ($found['signature'] ?? 'x'));
        $this->assertSame('', (string) ($found['signature_html'] ?? 'x'));

        $single = AP_Forum::postToDisplayRow(
            AP_Forum::getPost($this->replyId, $this->db),
            2,
            $this->db
        );
        $this->assertSame('', (string) $single['signature']);
        $this->assertSame('', (string) $single['signature_html']);

        // Restore default for later tests in this class.
        if (function_exists('ap_update_option')) {
            ap_update_option('forum_signatures_enabled', '1', $this->db);
        } else {
            AP_Options::update('forum_signatures_enabled', '1', $this->db);
        }
    }

    public function testAuthorPaneOmitsEmptyLocationAndGuestJoined(): void
    {
        // Guest-shaped post row: no poster user → empty joined/location/signature.
        $guestPost = (object) [
            'post_id' => 999001,
            'topic_id' => $this->topicId,
            'forum_id' => $this->forumId,
            'poster_id' => 0,
            'post_time' => '2026-08-01 12:00:00',
            'post_content' => 'Guest says hi',
            'post_content_filtered' => '',
            'post_subject' => '',
            'post_position' => 99,
            'post_approved' => 1,
            'post_edit_count' => 0,
            'post_edit_time' => '',
            'like_count' => 0,
        ];
        $row = AP_Forum::postToDisplayRow($guestPost, 99, $this->db);
        $this->assertSame(0, (int) $row['author_id']);
        $this->assertSame('', (string) $row['joined']);
        $this->assertSame('', (string) $row['location']);
        $this->assertSame('', (string) $row['signature']);
        $this->assertSame('', (string) $row['signature_html']);
        $this->assertSame('Guest', (string) $row['author']);
    }

    public function testEmptyStatsAndGuestPanel(): void
    {
        $empty = AP_Forum_Stats::emptyStats();
        $this->assertSame(0, $empty['forum_likes_given']);
        $this->assertSame(0, $empty['forum_likes_received']);
        $this->assertSame(0, AP_Forum_Stats::queryLikesGiven(0, $this->db));
        $this->assertSame(0, AP_Forum_Stats::queryLikesReceived(0, $this->db));
        $this->assertSame([], AP_Forum_Stats::getUsersStats([], $this->db));
        $this->assertSame([], AP_Forum_Stats::queryLikesAggregatesForUsers([], $this->db));
    }

    public function testForumPostCountIncrementsOnReply(): void
    {
        // First post (topic) + reply should both have fired stats hooks if registered.
        $stats = AP_Forum_Stats::getUserStats($this->authorId, $this->db);
        $this->assertSame(2, $stats['forum_posts']);
        $rebuilt = AP_Forum_Stats::rebuildForumPostCount($this->authorId, $this->db);
        $this->assertSame(2, $rebuilt);
    }

    public function testSoftDeleteDecrementsPostCount(): void
    {
        $before = AP_Forum_Stats::getUserStats($this->authorId, $this->db);
        $this->assertSame(2, $before['forum_posts']);

        $ok = AP_Forum::updatePost($this->replyId, [
            'post_approved' => 0,
            'post_edit_user' => $this->modId,
            'edit_reason' => 'test soft delete',
        ], $this->db);
        $this->assertTrue($ok);

        $after = AP_Forum_Stats::getUserStats($this->authorId, $this->db);
        $this->assertSame(1, $after['forum_posts']);
    }

    public function testMigrationFileExists(): void
    {
        $path = $this->root . '/ap-includes/schema/migrations/0011_forum_likes_stats.php';
        $this->assertFileIsReadable($path);
        $src = (string) file_get_contents($path);
        $this->assertStringContainsString('forum_post_likes', $src);
        $this->assertStringContainsString('like_count', $src);
    }

    public function testBoardStatsEmptyShape(): void
    {
        $empty = AP_Forum_Stats::emptyBoardStats();
        $this->assertSame(['topics' => 0, 'posts' => 0, 'members' => 0], $empty);
    }

    public function testBoardLevelAggregates(): void
    {
        // setUp: 1 topic + 1 reply (2 posts), 3 users (author, liker, mod).
        $stats = AP_Forum_Stats::getBoardStats($this->db);
        $this->assertSame(1, $stats['topics']);
        $this->assertSame(2, $stats['posts']);
        $this->assertSame(3, $stats['members']);

        $this->assertSame(1, AP_Forum_Stats::getTotalTopics($this->db));
        $this->assertSame(2, AP_Forum_Stats::getTotalPosts($this->db));
        $this->assertSame(3, AP_Forum_Stats::getTotalMembers($this->db));

        // Denormalized forum counters should agree while stats are healthy.
        $fromRows = AP_Forum_Stats::getBoardStatsFromForumCounters($this->db);
        $this->assertSame(1, $fromRows['topics']);
        $this->assertSame(2, $fromRows['posts']);
        $this->assertSame(3, $fromRows['members']);

        $forum = AP_Forum::getForum($this->forumId, $this->db);
        $this->assertNotNull($forum);
        $this->assertSame(1, (int) $forum->topic_count);
        $this->assertSame(2, (int) $forum->post_count);
    }

    public function testBoardStatsExcludeSoftDeletedTopic(): void
    {
        $before = AP_Forum_Stats::getBoardStats($this->db);
        $this->assertSame(1, $before['topics']);
        $this->assertSame(2, $before['posts']);

        $ok = AP_Forum::deleteTopic($this->topicId, false, $this->db);
        $this->assertTrue($ok);

        $after = AP_Forum_Stats::getBoardStats($this->db);
        $this->assertSame(0, $after['topics']);
        $this->assertSame(0, $after['posts']);
        // Soft-delete does not remove users.
        $this->assertSame(3, $after['members']);

        $fromRows = AP_Forum_Stats::getBoardStatsFromForumCounters($this->db);
        $this->assertSame(0, $fromRows['topics']);
        $this->assertSame(0, $fromRows['posts']);
    }

    public function testBoardStatsCountAdditionalTopicAndMember(): void
    {
        $newUser = $this->createUser('newbie', 'subscriber');
        $topic2 = AP_Forum::createTopic([
            'forum_id' => $this->forumId,
            'topic_title' => 'Second topic',
            'content' => 'Another OP',
            'poster_id' => $newUser,
        ], $this->db);
        $this->assertGreaterThan(0, $topic2);

        $stats = AP_Forum_Stats::getBoardStats($this->db);
        $this->assertSame(2, $stats['topics']);
        // Original 2 posts + 1 new OP.
        $this->assertSame(3, $stats['posts']);
        $this->assertSame(4, $stats['members']);
    }
}
