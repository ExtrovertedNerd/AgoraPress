<?php

/**
 * Tests for AP_Comment — nested comments, moderation, spam hooks, count sync.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Comment;

use AP_Comment;
use AP_DB;
use AP_Migrator;
use AP_Post;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_Comment::class)]
final class CommentModelTest extends TestCase
{
    private string $root;

    private AP_DB $db;

    private int $postId;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        require_once $this->root . '/ap-includes/version.php';
        require_once $this->root . '/ap-includes/class-ap-db.php';
        require_once $this->root . '/ap-includes/class-ap-migrator.php';
        require_once $this->root . '/ap-includes/class-ap-post.php';
        require_once $this->root . '/ap-includes/class-ap-comment.php';
        require_once $this->root . '/ap-includes/functions.php';

        AP_Post::resetRegistry();
        AP_Comment::resetSpamCheckers();

        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');

        $migrator = new AP_Migrator($this->db, AP_Migrator::defaultMigrationsPath());
        $migrator->migrate();
        AP_Post::ensureBuiltins();

        $this->postId = AP_Post::insert([
            'post_title' => 'Hello Comments',
            'post_content' => 'Body',
            'post_status' => 'publish',
            'post_type' => 'post',
            'comment_status' => 'open',
            'post_author' => 1,
        ], $this->db);
        $this->assertGreaterThan(0, $this->postId);
    }

    protected function tearDown(): void
    {
        AP_Post::resetRegistry();
        AP_Comment::resetSpamCheckers();
    }

    public function testStatusNormalization(): void
    {
        $this->assertSame('1', AP_Comment::normalizeStatus('approve'));
        $this->assertSame('1', AP_Comment::normalizeStatus('approved'));
        $this->assertSame('0', AP_Comment::normalizeStatus('hold'));
        $this->assertSame('0', AP_Comment::normalizeStatus('pending'));
        $this->assertSame('spam', AP_Comment::normalizeStatus('spam'));
        $this->assertSame('trash', AP_Comment::normalizeStatus('trash'));
        $this->assertTrue(AP_Comment::isApproved('1'));
        $this->assertTrue(AP_Comment::isPending('0'));
        $this->assertTrue(AP_Comment::isSpam('spam'));
        $this->assertTrue(AP_Comment::isTrash('trash'));
        $this->assertSame('Approved', AP_Comment::statusLabel('1'));
        $this->assertSame('Pending', AP_Comment::statusLabel('0'));
    }

    public function testInsertAndGetComment(): void
    {
        $id = AP_Comment::insert([
            'comment_post_ID' => $this->postId,
            'comment_author' => 'Alice',
            'comment_author_email' => 'alice@example.com',
            'comment_content' => 'Nice post!',
            'comment_approved' => '1',
            'user_id' => 0,
        ], $this->db);

        $this->assertGreaterThan(0, $id);
        $comment = AP_Comment::get($id, $this->db);
        $this->assertNotNull($comment);
        $this->assertSame('Alice', $comment->comment_author);
        $this->assertSame('alice@example.com', $comment->comment_author_email);
        $this->assertSame('Nice post!', $comment->comment_content);
        $this->assertSame('1', $comment->comment_approved);
        $this->assertSame($this->postId, $comment->comment_post_ID);
        $this->assertSame('comment', $comment->comment_type);
    }

    public function testInsertNormalizesNbspToRegularSpace(): void
    {
        require_once $this->root . '/ap-includes/class-ap-content-format.php';
        $id = AP_Comment::insert([
            'comment_post_ID' => $this->postId,
            'comment_author' => 'Nbsp',
            'comment_content' => 'Enthusiastic.&nbsp;😏',
            'comment_approved' => '1',
        ], $this->db);
        $this->assertGreaterThan(0, $id);
        $comment = AP_Comment::get($id, $this->db);
        $this->assertNotNull($comment);
        $this->assertSame('Enthusiastic. 😏', $comment->comment_content);
        $this->assertStringNotContainsString('&nbsp;', $comment->comment_content);
    }

    public function testGuestDefaultsToPendingLoggedInToApproved(): void
    {
        $guestId = AP_Comment::insert([
            'comment_post_ID' => $this->postId,
            'comment_author' => 'Guest',
            'comment_content' => 'From a guest',
            'user_id' => 0,
        ], $this->db);
        $this->assertSame('0', AP_Comment::get($guestId, $this->db)?->comment_approved);

        $userId = AP_Comment::insert([
            'comment_post_ID' => $this->postId,
            'comment_author' => 'Member',
            'comment_content' => 'From a member',
            'user_id' => 5,
        ], $this->db);
        $this->assertSame('1', AP_Comment::get($userId, $this->db)?->comment_approved);
    }

    public function testCommentModerationOptionHoldsLoggedInUsers(): void
    {
        require_once $this->root . '/ap-includes/class-ap-options.php';
        \AP_Options::flushCache();
        \AP_Options::update('comment_moderation', '1', $this->db);

        $id = AP_Comment::insert([
            'comment_post_ID' => $this->postId,
            'comment_author' => 'Member',
            'comment_content' => 'Should still need approval',
            'user_id' => 9,
        ], $this->db);
        $this->assertGreaterThan(0, $id);
        $this->assertSame('0', AP_Comment::get($id, $this->db)?->comment_approved);

        // Explicit approved still wins when caller sets comment_approved.
        $forced = AP_Comment::insert([
            'comment_post_ID' => $this->postId,
            'comment_author' => 'Mod',
            'comment_content' => 'Forced approve',
            'comment_approved' => '1',
            'user_id' => 9,
        ], $this->db);
        $this->assertSame('1', AP_Comment::get($forced, $this->db)?->comment_approved);

        \AP_Options::update('comment_moderation', '0', $this->db);
        \AP_Options::flushCache();
    }

    public function testFormPostRedirectSignalsPendingForGuests(): void
    {
        require_once $this->root . '/ap-includes/class-ap-options.php';
        require_once $this->root . '/ap-includes/class-ap-nonce.php';
        require_once $this->root . '/ap-includes/class-ap-rewrite.php';
        if (!defined('AP_NONCE_KEY')) {
            define('AP_NONCE_KEY', 'test-nonce-key-' . str_repeat('c', 32));
        }
        if (!defined('AP_NONCE_SALT')) {
            define('AP_NONCE_SALT', 'test-nonce-salt-' . str_repeat('d', 32));
        }

        \AP_Options::update('home', 'https://example.test', $this->db);
        \AP_Options::update('siteurl', 'https://example.test', $this->db);

        $nonce = ap_create_nonce('ap-comment-post-' . $this->postId, null);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'ap_comment_action' => 'ap_comment_post',
            'comment_post_ID' => $this->postId,
            'comment_parent' => 0,
            '_ap_nonce' => $nonce,
            'author' => 'FormGuest',
            'email' => 'formguest@example.test',
            'comment' => 'Hello from the form',
        ];

        $redirect = ap_handle_comment_form_post($this->db);
        $this->assertStringContainsString('comment_ok=pending', $redirect);
        $this->assertMatchesRegularExpression('/#comment-\d+/', $redirect);

        $pending = AP_Comment::query([
            'post_id' => $this->postId,
            'status' => 'hold',
        ], $this->db);
        $this->assertNotEmpty($pending);
        $this->assertSame('FormGuest', $pending[0]->comment_author);

        unset($_POST);
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }

    public function testFormPostLoggedInDoesNotThrowAndApproves(): void
    {
        require_once $this->root . '/ap-includes/class-ap-options.php';
        require_once $this->root . '/ap-includes/class-ap-user.php';
        require_once $this->root . '/ap-includes/class-ap-session.php';
        require_once $this->root . '/ap-includes/class-ap-roles.php';
        require_once $this->root . '/ap-includes/class-ap-nonce.php';
        require_once $this->root . '/ap-includes/class-ap-rewrite.php';
        if (!defined('AP_NONCE_KEY')) {
            define('AP_NONCE_KEY', 'test-nonce-key-' . str_repeat('c', 32));
        }
        if (!defined('AP_NONCE_SALT')) {
            define('AP_NONCE_SALT', 'test-nonce-salt-' . str_repeat('d', 32));
        }
        if (!defined('AP_LOGGED_IN_KEY')) {
            define('AP_LOGGED_IN_KEY', 'test-logged-in-key-' . str_repeat('e', 32));
        }
        if (!defined('AP_LOGGED_IN_SALT')) {
            define('AP_LOGGED_IN_SALT', 'test-logged-in-salt-' . str_repeat('f', 32));
        }

        \AP_Roles::ensureDefaults($this->db);
        \AP_Options::update('home', 'https://example.test', $this->db);
        \AP_Options::update('siteurl', 'https://example.test', $this->db);

        $created = \AP_User::create([
            'user_login' => 'commentadmin',
            'user_email' => 'commentadmin@example.test',
            'password' => 'password12345',
            'role' => 'administrator',
            'display_name' => 'Comment Admin',
        ], $this->db);
        $uid = (int) $created['id'];
        $this->assertGreaterThan(0, $uid);

        \AP_Session::enableTestMode([]);
        \AP_Session::setAuthCookie($uid, true, $this->db);
        $this->assertSame($uid, ap_get_current_user_id($this->db));

        // Regression: handler must not call AP_User::get() (does not exist).
        $this->assertFalse(method_exists(\AP_User::class, 'get'));
        $this->assertTrue(method_exists(\AP_User::class, 'getById'));

        $nonce = ap_create_nonce('ap-comment-post-' . $this->postId, $uid);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'ap_comment_action' => 'ap_comment_post',
            'comment_post_ID' => $this->postId,
            'comment_parent' => 0,
            '_ap_nonce' => $nonce,
            'comment' => 'Posted while logged in as admin',
        ];

        $redirect = ap_handle_comment_form_post($this->db);
        $this->assertStringContainsString('comment_ok=1', $redirect);

        $approved = AP_Comment::query([
            'post_id' => $this->postId,
            'status' => 'approve',
        ], $this->db);
        $this->assertNotEmpty($approved);
        $this->assertSame('Comment Admin', $approved[0]->comment_author);
        $this->assertSame($uid, $approved[0]->user_id);
        $this->assertSame('1', $approved[0]->comment_approved);

        \AP_Session::disableTestMode();
        unset($_POST);
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }

    public function testRejectsEmptyContentAndClosedComments(): void
    {
        $this->assertSame(0, AP_Comment::insert([
            'comment_post_ID' => $this->postId,
            'comment_author' => 'X',
            'comment_content' => '   ',
        ], $this->db));

        $closedId = AP_Post::insert([
            'post_title' => 'Closed',
            'post_content' => 'No comments',
            'post_status' => 'publish',
            'comment_status' => 'closed',
        ], $this->db);
        $this->assertSame(0, AP_Comment::insert([
            'comment_post_ID' => $closedId,
            'comment_author' => 'X',
            'comment_content' => 'Should fail',
        ], $this->db));

        // Bypass open check when args say so.
        $forced = AP_Comment::insert([
            'comment_post_ID' => $closedId,
            'comment_author' => 'Mod',
            'comment_content' => 'Admin forced',
            'comment_approved' => '1',
        ], $this->db, ['check_open' => false]);
        $this->assertGreaterThan(0, $forced);
    }

    public function testNestedCommentsAndTree(): void
    {
        $parentId = AP_Comment::insert([
            'comment_post_ID' => $this->postId,
            'comment_author' => 'Parent',
            'comment_content' => 'Top level',
            'comment_approved' => '1',
        ], $this->db);
        $childId = AP_Comment::insert([
            'comment_post_ID' => $this->postId,
            'comment_author' => 'Child',
            'comment_content' => 'Reply',
            'comment_approved' => '1',
            'comment_parent' => $parentId,
        ], $this->db);
        $this->assertGreaterThan(0, $childId);

        $child = AP_Comment::get($childId, $this->db);
        $this->assertSame($parentId, $child?->comment_parent);

        // Parent on wrong post rejected.
        $otherPost = AP_Post::insert([
            'post_title' => 'Other',
            'post_content' => 'x',
            'post_status' => 'publish',
            'comment_status' => 'open',
        ], $this->db);
        $this->assertSame(0, AP_Comment::insert([
            'comment_post_ID' => $otherPost,
            'comment_author' => 'Bad',
            'comment_content' => 'Cross-post parent',
            'comment_parent' => $parentId,
            'comment_approved' => '1',
        ], $this->db));

        $tree = AP_Comment::getTree($this->postId, [], $this->db);
        $this->assertCount(1, $tree);
        $this->assertSame($parentId, $tree[0]['comment']->comment_ID);
        $this->assertCount(1, $tree[0]['children']);
        $this->assertSame($childId, $tree[0]['children'][0]['comment']->comment_ID);
    }

    public function testModerationActionsAndCountSync(): void
    {
        $id = AP_Comment::insert([
            'comment_post_ID' => $this->postId,
            'comment_author' => 'Mod',
            'comment_content' => 'Needs review',
            'comment_approved' => '0',
        ], $this->db);

        $post = AP_Post::get($this->postId, $this->db);
        $this->assertSame(0, $post?->comment_count);

        $this->assertTrue(AP_Comment::approve($id, $this->db));
        $post = AP_Post::get($this->postId, $this->db);
        $this->assertSame(1, $post?->comment_count);
        $this->assertSame('1', AP_Comment::get($id, $this->db)?->comment_approved);

        $this->assertTrue(AP_Comment::unapprove($id, $this->db));
        $post = AP_Post::get($this->postId, $this->db);
        $this->assertSame(0, $post?->comment_count);

        $this->assertTrue(AP_Comment::approve($id, $this->db));
        $this->assertTrue(AP_Comment::spam($id, $this->db));
        $post = AP_Post::get($this->postId, $this->db);
        $this->assertSame(0, $post?->comment_count);
        $this->assertSame('spam', AP_Comment::get($id, $this->db)?->comment_approved);

        $this->assertTrue(AP_Comment::unspam($id, $this->db));
        $this->assertSame('0', AP_Comment::get($id, $this->db)?->comment_approved);

        $this->assertTrue(AP_Comment::approve($id, $this->db));
        $this->assertTrue(AP_Comment::trash($id, $this->db));
        $this->assertSame('trash', AP_Comment::get($id, $this->db)?->comment_approved);
        $post = AP_Post::get($this->postId, $this->db);
        $this->assertSame(0, $post?->comment_count);

        $this->assertTrue(AP_Comment::untrash($id, $this->db));
        // Restored to previous approved status.
        $this->assertSame('1', AP_Comment::get($id, $this->db)?->comment_approved);
        $post = AP_Post::get($this->postId, $this->db);
        $this->assertSame(1, $post?->comment_count);
    }

    public function testDeleteForceAndReparentChildren(): void
    {
        $parentId = AP_Comment::insert([
            'comment_post_ID' => $this->postId,
            'comment_author' => 'P',
            'comment_content' => 'Parent',
            'comment_approved' => '1',
        ], $this->db);
        $childId = AP_Comment::insert([
            'comment_post_ID' => $this->postId,
            'comment_author' => 'C',
            'comment_content' => 'Child',
            'comment_approved' => '1',
            'comment_parent' => $parentId,
        ], $this->db);

        // Soft delete.
        $this->assertTrue(AP_Comment::delete($parentId, false, $this->db));
        $this->assertSame('trash', AP_Comment::get($parentId, $this->db)?->comment_approved);

        // Force delete re-parents child.
        $this->assertTrue(AP_Comment::delete($parentId, true, $this->db));
        $this->assertNull(AP_Comment::get($parentId, $this->db));
        $this->assertSame(0, AP_Comment::get($childId, $this->db)?->comment_parent);

        $post = AP_Post::get($this->postId, $this->db);
        $this->assertSame(1, $post?->comment_count);
    }

    public function testQueryFilterByStatusAndSearch(): void
    {
        AP_Comment::insert([
            'comment_post_ID' => $this->postId,
            'comment_author' => 'Alice',
            'comment_content' => 'Unique zebra phrase',
            'comment_approved' => '1',
        ], $this->db);
        AP_Comment::insert([
            'comment_post_ID' => $this->postId,
            'comment_author' => 'Bob',
            'comment_content' => 'Pending thought',
            'comment_approved' => '0',
        ], $this->db);
        AP_Comment::insert([
            'comment_post_ID' => $this->postId,
            'comment_author' => 'Spammer',
            'comment_content' => 'Buy now',
            'comment_approved' => 'spam',
        ], $this->db);

        $approved = AP_Comment::query([
            'post_id' => $this->postId,
            'status' => 'approve',
        ], $this->db);
        $this->assertCount(1, $approved);

        $pending = AP_Comment::query([
            'post_id' => $this->postId,
            'status' => 'hold',
        ], $this->db);
        $this->assertCount(1, $pending);
        $this->assertSame('Bob', $pending[0]->comment_author);

        $found = AP_Comment::query([
            'post_id' => $this->postId,
            'status' => 'all',
            'search' => 'zebra',
        ], $this->db);
        $this->assertCount(1, $found);
        $this->assertSame('Alice', $found[0]->comment_author);

        $counts = AP_Comment::countByStatus($this->postId, $this->db);
        $this->assertSame(1, $counts['1']);
        $this->assertSame(1, $counts['0']);
        $this->assertSame(1, $counts['spam']);
    }

    public function testSpamCheckerHook(): void
    {
        AP_Comment::registerSpamChecker(static function (array $data): bool|string {
            if (str_contains((string) ($data['comment_content'] ?? ''), 'viagra')) {
                return 'spam';
            }

            return false;
        });

        $id = AP_Comment::insert([
            'comment_post_ID' => $this->postId,
            'comment_author' => 'Bot',
            'comment_content' => 'Buy viagra cheap',
            'comment_approved' => '1',
            'user_id' => 1,
        ], $this->db);
        $this->assertSame('spam', AP_Comment::get($id, $this->db)?->comment_approved);

        $clean = AP_Comment::insert([
            'comment_post_ID' => $this->postId,
            'comment_author' => 'Human',
            'comment_content' => 'Great article',
            'user_id' => 1,
        ], $this->db);
        $this->assertSame('1', AP_Comment::get($clean, $this->db)?->comment_approved);
    }

    public function testCommentMeta(): void
    {
        $id = AP_Comment::insert([
            'comment_post_ID' => $this->postId,
            'comment_author' => 'Meta',
            'comment_content' => 'With meta',
            'comment_approved' => '1',
            'meta' => [
                'rating' => '5',
            ],
        ], $this->db);

        $this->assertSame('5', AP_Comment::getMeta($id, 'rating', true, $this->db));
        $this->assertTrue(AP_Comment::updateMeta($id, 'rating', '4', $this->db));
        $this->assertSame('4', AP_Comment::getMeta($id, 'rating', true, $this->db));
        $this->assertTrue(AP_Comment::deleteMeta($id, 'rating', $this->db));
        $this->assertNull(AP_Comment::getMeta($id, 'rating', true, $this->db));
    }

    public function testProceduralHelpers(): void
    {
        $id = ap_insert_comment([
            'comment_post_ID' => $this->postId,
            'comment_author' => 'Proc',
            'comment_content' => 'Via helpers',
            'comment_approved' => '0',
        ], $this->db);
        $this->assertGreaterThan(0, $id);

        $this->assertTrue(ap_approve_comment($id, $this->db));
        $this->assertSame('1', ap_get_comment($id, $this->db)?->comment_approved);

        $list = ap_get_post_comments($this->postId, [], $this->db);
        $this->assertNotEmpty($list);

        $this->assertTrue(ap_spam_comment($id, $this->db));
        $this->assertTrue(ap_unspam_comment($id, $this->db));
        $this->assertTrue(ap_approve_comment($id, $this->db));
        $this->assertTrue(ap_trash_comment($id, $this->db));
        $this->assertTrue(ap_untrash_comment($id, $this->db));
        // Restored to approved → count should be 1.
        $this->assertSame(1, ap_update_comment_count($this->postId, $this->db));
    }

    public function testUpdateContentAndCyclePrevention(): void
    {
        $a = AP_Comment::insert([
            'comment_post_ID' => $this->postId,
            'comment_author' => 'A',
            'comment_content' => 'First',
            'comment_approved' => '1',
        ], $this->db);
        $b = AP_Comment::insert([
            'comment_post_ID' => $this->postId,
            'comment_author' => 'B',
            'comment_content' => 'Second',
            'comment_approved' => '1',
            'comment_parent' => $a,
        ], $this->db);

        $this->assertTrue(AP_Comment::update($a, ['comment_content' => 'Updated first'], $this->db));
        $this->assertSame('Updated first', AP_Comment::get($a, $this->db)?->comment_content);

        // A → B would cycle (B is child of A).
        $this->assertFalse(AP_Comment::update($a, ['comment_parent' => $b], $this->db));
    }
}
