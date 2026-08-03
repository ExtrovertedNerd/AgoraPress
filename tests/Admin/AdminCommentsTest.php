<?php

/**
 * Tests for admin comments list table / moderation actions.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Admin;

use AP_Admin;
use AP_Comment;
use AP_Comments_List_Table;
use AP_DB;
use AP_Migrator;
use AP_Nonce;
use AP_Post;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_Comments_List_Table::class)]
final class AdminCommentsTest extends TestCase
{
    private string $root;

    private AP_DB $db;

    private int $userId;

    private int $postId;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        require_once $this->root . '/ap-includes/version.php';
        require_once $this->root . '/ap-includes/class-ap-db.php';
        require_once $this->root . '/ap-includes/class-ap-migrator.php';
        require_once $this->root . '/ap-includes/class-ap-user.php';
        require_once $this->root . '/ap-includes/class-ap-session.php';
        require_once $this->root . '/ap-includes/class-ap-post.php';
        require_once $this->root . '/ap-includes/class-ap-comment.php';
        require_once $this->root . '/ap-includes/class-ap-nonce.php';
        require_once $this->root . '/ap-includes/functions.php';
        require_once $this->root . '/ap-admin/includes/class-ap-admin.php';
        require_once $this->root . '/ap-admin/includes/class-ap-comments-list-table.php';

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

        AP_Post::resetRegistry();
        AP_Comment::resetSpamCheckers();
        AP_Admin::clearNotices();

        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');
        $migrator = new AP_Migrator($this->db, AP_Migrator::defaultMigrationsPath());
        $migrator->migrate();
        AP_Post::ensureBuiltins();

        $this->userId = 1;

        $this->postId = AP_Post::insert([
            'post_title' => 'Discuss me',
            'post_content' => 'Body',
            'post_status' => 'publish',
            'comment_status' => 'open',
            'post_author' => $this->userId,
        ], $this->db);
    }

    protected function tearDown(): void
    {
        AP_Post::resetRegistry();
        AP_Comment::resetSpamCheckers();
        AP_Admin::clearNotices();
    }

    public function testMenuIncludesComments(): void
    {
        $items = AP_Admin::menuItems('comments');
        $ids = array_column($items, 'id');
        $this->assertContains('comments', $ids);
        $comments = null;
        foreach ($items as $item) {
            if ($item['id'] === 'comments') {
                $comments = $item;
                break;
            }
        }
        $this->assertNotNull($comments);
        $this->assertTrue($comments['active']);
        $this->assertStringContainsString('edit-comments.php', $comments['url']);
    }

    public function testPrepareItemsFiltersByStatus(): void
    {
        AP_Comment::insert([
            'comment_post_ID' => $this->postId,
            'comment_author' => 'A',
            'comment_content' => 'Approved one',
            'comment_approved' => '1',
        ], $this->db);
        AP_Comment::insert([
            'comment_post_ID' => $this->postId,
            'comment_author' => 'B',
            'comment_content' => 'Pending one',
            'comment_approved' => '0',
        ], $this->db);

        $table = new AP_Comments_List_Table($this->db);
        $table->prepareItems(['comment_status' => 'moderated']);
        $this->assertCount(1, $table->items);
        $this->assertSame('B', $table->items[0]->comment_author);
        $this->assertSame(1, $table->statusCounts['0'] ?? 0);
        $this->assertSame(1, $table->statusCounts['1'] ?? 0);
    }

    public function testBulkApproveAndSpam(): void
    {
        $id1 = AP_Comment::insert([
            'comment_post_ID' => $this->postId,
            'comment_author' => 'One',
            'comment_content' => 'Pending A',
            'comment_approved' => '0',
        ], $this->db);
        $id2 = AP_Comment::insert([
            'comment_post_ID' => $this->postId,
            'comment_author' => 'Two',
            'comment_content' => 'Pending B',
            'comment_approved' => '0',
        ], $this->db);

        // Nonces match processBulkAction (no session → user id 0).
        $nonce = ap_create_nonce('bulk-comments', 0);
        $table = new AP_Comments_List_Table($this->db);
        $result = $table->processBulkAction([
            'action' => 'approve',
            '_ap_nonce' => $nonce,
            'comment' => [$id1, $id2],
        ]);
        $this->assertTrue($result['ok']);
        $this->assertSame(2, $result['count']);
        $this->assertSame('bulk_comment_approved', $result['message_key']);
        $this->assertSame('1', AP_Comment::get($id1, $this->db)?->comment_approved);

        $nonce2 = ap_create_nonce('bulk-comments', 0);
        $result2 = $table->processBulkAction([
            'action' => 'spam',
            '_ap_nonce' => $nonce2,
            'comment' => [$id1],
        ]);
        $this->assertTrue($result2['ok']);
        $this->assertSame('spam', AP_Comment::get($id1, $this->db)?->comment_approved);
    }

    public function testRowActionApproveRequiresNonce(): void
    {
        $id = AP_Comment::insert([
            'comment_post_ID' => $this->postId,
            'comment_author' => 'Row',
            'comment_content' => 'Pending row',
            'comment_approved' => '0',
        ], $this->db);

        $table = new AP_Comments_List_Table($this->db);
        $bad = $table->processRowAction([
            'action' => 'approve',
            'c' => $id,
            '_ap_nonce' => 'invalid',
        ]);
        $this->assertFalse($bad['ok']);
        $this->assertSame('nonce', $bad['message_key']);

        $nonce = ap_create_nonce('comment-approve-' . $id, 0);
        $ok = $table->processRowAction([
            'action' => 'approve',
            'c' => $id,
            '_ap_nonce' => $nonce,
        ]);
        $this->assertTrue($ok['ok']);
        $this->assertSame('comment_approved', $ok['message_key']);
        $this->assertSame('1', AP_Comment::get($id, $this->db)?->comment_approved);
    }

    public function testRenderIncludesTableMarkup(): void
    {
        AP_Comment::insert([
            'comment_post_ID' => $this->postId,
            'comment_author' => 'Render',
            'comment_content' => 'Hello render test',
            'comment_approved' => '1',
        ], $this->db);

        $table = new AP_Comments_List_Table($this->db);
        $table->prepareItems(['comment_status' => 'all']);
        $html = $table->render();
        $this->assertStringContainsString('ap-list-table', $html);
        $this->assertStringContainsString('Hello render test', $html);
        $this->assertStringContainsString('Render', $html);
        $this->assertStringContainsString('_ap_nonce', $html);

        $views = $table->renderViews();
        $this->assertStringContainsString('Pending', $views);
        $this->assertStringContainsString('Approved', $views);
    }

    public function testConsumeQueryNoticeForComments(): void
    {
        $_GET['message'] = 'comment_approved';
        AP_Admin::consumeQueryNotice();
        $notices = AP_Admin::getNotices();
        $this->assertNotEmpty($notices);
        $this->assertStringContainsString('approved', strtolower($notices[0]['message']));
        unset($_GET['message']);
        AP_Admin::clearNotices();
    }
}
