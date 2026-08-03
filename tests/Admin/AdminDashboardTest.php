<?php

/**
 * Tests for admin dashboard home stats (At a Glance, Activity, Quick Draft).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Admin;

use AP_Admin;
use AP_Admin_Dashboard;
use AP_Comment;
use AP_DB;
use AP_Migrator;
use AP_Nonce;
use AP_Options;
use AP_Post;
use AP_Roles;
use AP_User;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_Admin_Dashboard::class)]
final class AdminDashboardTest extends TestCase
{
    private string $root;

    private AP_DB $db;

    private int $adminId = 0;

    private int $subscriberId = 0;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        require_once $this->root . '/ap-includes/version.php';
        require_once $this->root . '/ap-includes/class-ap-db.php';
        require_once $this->root . '/ap-includes/class-ap-migrator.php';
        require_once $this->root . '/ap-includes/class-ap-options.php';
        require_once $this->root . '/ap-includes/class-ap-post.php';
        require_once $this->root . '/ap-includes/class-ap-comment.php';
        require_once $this->root . '/ap-includes/class-ap-user.php';
        require_once $this->root . '/ap-includes/class-ap-session.php';
        require_once $this->root . '/ap-includes/class-ap-roles.php';
        require_once $this->root . '/ap-includes/class-ap-nonce.php';
        require_once $this->root . '/ap-includes/functions.php';
        require_once $this->root . '/ap-admin/includes/class-ap-admin.php';
        require_once $this->root . '/ap-admin/includes/class-ap-admin-dashboard.php';

        if (!defined('AP_NONCE_KEY')) {
            define('AP_NONCE_KEY', 'test-nonce-key-' . str_repeat('d', 32));
        }
        if (!defined('AP_NONCE_SALT')) {
            define('AP_NONCE_SALT', 'test-nonce-salt-' . str_repeat('e', 32));
        }
        if (!defined('AP_LOGGED_IN_KEY')) {
            define('AP_LOGGED_IN_KEY', 'test-logged-in-key-' . str_repeat('f', 32));
        }
        if (!defined('AP_LOGGED_IN_SALT')) {
            define('AP_LOGGED_IN_SALT', 'test-logged-in-salt-' . str_repeat('g', 32));
        }

        AP_Roles::flushCache();
        AP_Options::flushCache();
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
        AP_Roles::ensureDefaults($this->db);
        AP_Post::ensureBuiltins();

        // Module defaults on.
        AP_Options::update(AP_Options::MODULE_BLOG, '1', $this->db);
        AP_Options::update(AP_Options::MODULE_STATIC_PAGES, '1', $this->db);
        AP_Options::update(AP_Options::MODULE_FORUM, '1', $this->db);

        $admin = AP_User::create([
            'user_login' => 'dashadmin',
            'user_email' => 'dashadmin@example.test',
            'password' => 'password123',
            'role' => 'administrator',
        ], $this->db);
        $this->adminId = (int) $admin['id'];

        $sub = AP_User::create([
            'user_login' => 'dashsub',
            'user_email' => 'dashsub@example.test',
            'password' => 'password123',
            'role' => 'subscriber',
        ], $this->db);
        $this->subscriberId = (int) $sub['id'];
    }

    protected function tearDown(): void
    {
        AP_Roles::flushCache();
        AP_Options::flushCache();
        AP_Post::resetRegistry();
        AP_Comment::resetSpamCheckers();
        AP_Admin::clearNotices();
    }

    public function testCountPostsByStatusAndTotal(): void
    {
        AP_Post::insert([
            'post_title' => 'Pub',
            'post_status' => 'publish',
            'post_type' => 'post',
            'post_author' => $this->adminId,
        ], $this->db);
        AP_Post::insert([
            'post_title' => 'Draft',
            'post_status' => 'draft',
            'post_type' => 'post',
            'post_author' => $this->adminId,
        ], $this->db);
        AP_Post::insert([
            'post_title' => 'Trash',
            'post_status' => 'trash',
            'post_type' => 'post',
            'post_author' => $this->adminId,
        ], $this->db);
        AP_Post::insert([
            'post_title' => 'A Page',
            'post_status' => 'publish',
            'post_type' => 'page',
            'post_author' => $this->adminId,
        ], $this->db);

        $byStatus = AP_Admin_Dashboard::countPostsByStatus('post', $this->db);
        $this->assertSame(1, $byStatus['publish'] ?? 0);
        $this->assertSame(1, $byStatus['draft'] ?? 0);
        $this->assertSame(1, $byStatus['trash'] ?? 0);

        $total = AP_Admin_Dashboard::totalFromStatusCounts($byStatus);
        // trash excluded
        $this->assertSame(2, $total);

        $pageCounts = AP_Admin_Dashboard::countPostsByStatus('page', $this->db);
        $this->assertSame(1, $pageCounts['publish'] ?? 0);
    }

    public function testAtAGlanceCounts(): void
    {
        $postId = AP_Post::insert([
            'post_title' => 'Hello',
            'post_status' => 'publish',
            'post_type' => 'post',
            'post_author' => $this->adminId,
            'comment_status' => 'open',
        ], $this->db);
        AP_Post::insert([
            'post_title' => 'Drafty',
            'post_status' => 'draft',
            'post_type' => 'post',
            'post_author' => $this->adminId,
        ], $this->db);
        AP_Post::insert([
            'post_title' => 'About',
            'post_status' => 'publish',
            'post_type' => 'page',
            'post_author' => $this->adminId,
        ], $this->db);

        AP_Comment::insert([
            'comment_post_ID' => $postId,
            'comment_author' => 'Alice',
            'comment_author_email' => 'alice@example.test',
            'comment_content' => 'Nice post',
            'comment_approved' => AP_Comment::STATUS_APPROVED,
        ], $this->db);
        AP_Comment::insert([
            'comment_post_ID' => $postId,
            'comment_author' => 'Bob',
            'comment_author_email' => 'bob@example.test',
            'comment_content' => 'Pending me',
            'comment_approved' => AP_Comment::STATUS_HOLD,
        ], $this->db);

        $glance = AP_Admin_Dashboard::getAtAGlance($this->db);

        $this->assertTrue($glance['modules']['blog']);
        $this->assertTrue($glance['modules']['static_pages']);
        $this->assertSame(1, $glance['posts']['publish']);
        $this->assertSame(1, $glance['posts']['draft']);
        $this->assertSame(1, $glance['pages']['publish']);
        $this->assertSame(1, $glance['comments']['approved']);
        $this->assertSame(1, $glance['comments']['pending']);
        $this->assertSame(2, $glance['comments']['total']);
        $this->assertSame(2, $glance['users']); // admin + subscriber
        $this->assertNull($glance['forum']);
    }

    public function testAtAGlanceRespectsModuleToggles(): void
    {
        AP_Post::insert([
            'post_title' => 'Hidden when blog off',
            'post_status' => 'publish',
            'post_type' => 'post',
            'post_author' => $this->adminId,
        ], $this->db);
        AP_Post::insert([
            'post_title' => 'Still a page',
            'post_status' => 'publish',
            'post_type' => 'page',
            'post_author' => $this->adminId,
        ], $this->db);

        AP_Options::updateModules([
            'blog' => false,
            'static_pages' => true,
            'forum' => false,
        ], $this->db);

        $glance = AP_Admin_Dashboard::getAtAGlance($this->db);
        $this->assertFalse($glance['modules']['blog']);
        $this->assertTrue($glance['modules']['static_pages']);
        $this->assertSame(0, $glance['posts']['publish']);
        $this->assertSame(0, $glance['posts']['total']);
        $this->assertSame(1, $glance['pages']['publish']);
        $this->assertSame(0, $glance['comments']['total']);
    }

    public function testRecentContentAndComments(): void
    {
        $postId = AP_Post::insert([
            'post_title' => 'Recent A',
            'post_status' => 'publish',
            'post_type' => 'post',
            'post_author' => $this->adminId,
            'comment_status' => 'open',
        ], $this->db);
        AP_Post::insert([
            'post_title' => 'Draft skip',
            'post_status' => 'draft',
            'post_type' => 'post',
            'post_author' => $this->adminId,
        ], $this->db);
        AP_Post::insert([
            'post_title' => 'Page B',
            'post_status' => 'publish',
            'post_type' => 'page',
            'post_author' => $this->adminId,
        ], $this->db);

        $recent = AP_Admin_Dashboard::getRecentContent(10, $this->db);
        $titles = array_map(static fn (AP_Post $p): string => $p->post_title, $recent);
        $this->assertContains('Recent A', $titles);
        $this->assertContains('Page B', $titles);
        $this->assertNotContains('Draft skip', $titles);

        AP_Comment::insert([
            'comment_post_ID' => $postId,
            'comment_author' => 'Carol',
            'comment_author_email' => 'carol@example.test',
            'comment_content' => 'Hello world comment',
            'comment_approved' => AP_Comment::STATUS_APPROVED,
        ], $this->db);

        $comments = AP_Admin_Dashboard::getRecentComments(5, $this->db);
        $this->assertCount(1, $comments);
        $this->assertSame('Carol', $comments[0]->comment_author);
    }

    public function testRecentContentEmptyWhenModulesOff(): void
    {
        AP_Post::insert([
            'post_title' => 'Orphan content',
            'post_status' => 'publish',
            'post_type' => 'post',
            'post_author' => $this->adminId,
        ], $this->db);

        // Keep static_pages on so at least one module remains valid.
        AP_Options::updateModules([
            'blog' => false,
            'static_pages' => true,
            'forum' => false,
        ], $this->db);

        $this->assertSame([], AP_Admin_Dashboard::getRecentComments(5, $this->db));

        // Blog off → no posts in recent; pages only.
        $recent = AP_Admin_Dashboard::getRecentContent(5, $this->db);
        foreach ($recent as $item) {
            $this->assertSame('page', $item->post_type);
        }
    }

    public function testQuickDraftSavesDraftPost(): void
    {
        $nonce = ap_create_nonce('quick-draft', $this->adminId);
        $result = AP_Admin_Dashboard::saveQuickDraft([
            '_ap_nonce' => $nonce,
            'post_title' => 'Quick idea',
            'post_content' => 'Some notes',
        ], $this->adminId, $this->db);

        $this->assertTrue($result['ok']);
        $this->assertGreaterThan(0, $result['id']);
        $this->assertSame('draft_saved', $result['message_key']);
        $this->assertInstanceOf(AP_Post::class, $result['post']);
        $this->assertSame('draft', $result['post']->post_status);
        $this->assertSame('post', $result['post']->post_type);
        $this->assertSame('Quick idea', $result['post']->post_title);
        $this->assertSame('Some notes', $result['post']->post_content);
    }

    public function testQuickDraftUntitledWhenTitleEmpty(): void
    {
        $nonce = ap_create_nonce('quick-draft', $this->adminId);
        $result = AP_Admin_Dashboard::saveQuickDraft([
            '_ap_nonce' => $nonce,
            'post_title' => '',
            'post_content' => 'Body only',
        ], $this->adminId, $this->db);

        $this->assertTrue($result['ok']);
        $this->assertSame('Untitled', $result['post']->post_title);
    }

    public function testQuickDraftRejectsEmptyAndBadNonceAndCaps(): void
    {
        $nonce = ap_create_nonce('quick-draft', $this->adminId);

        $empty = AP_Admin_Dashboard::saveQuickDraft([
            '_ap_nonce' => $nonce,
            'post_title' => '',
            'post_content' => '',
        ], $this->adminId, $this->db);
        $this->assertFalse($empty['ok']);
        $this->assertNotEmpty($empty['errors']);

        $badNonce = AP_Admin_Dashboard::saveQuickDraft([
            '_ap_nonce' => 'nope',
            'post_title' => 'X',
            'post_content' => 'Y',
        ], $this->adminId, $this->db);
        $this->assertFalse($badNonce['ok']);
        $this->assertSame('nonce', $badNonce['message_key']);

        $subNonce = ap_create_nonce('quick-draft', $this->subscriberId);
        $noCap = AP_Admin_Dashboard::saveQuickDraft([
            '_ap_nonce' => $subNonce,
            'post_title' => 'Nope',
            'post_content' => 'Denied',
        ], $this->subscriberId, $this->db);
        $this->assertFalse($noCap['ok']);
    }

    public function testQuickDraftBlockedWhenBlogOff(): void
    {
        AP_Options::updateModules([
            'blog' => false,
            'static_pages' => true,
            'forum' => false,
        ], $this->db);

        $this->assertFalse(AP_Admin_Dashboard::canQuickDraft($this->adminId, $this->db));

        $nonce = ap_create_nonce('quick-draft', $this->adminId);
        $result = AP_Admin_Dashboard::saveQuickDraft([
            '_ap_nonce' => $nonce,
            'post_title' => 'Blocked',
            'post_content' => 'x',
        ], $this->adminId, $this->db);
        $this->assertFalse($result['ok']);
    }

    public function testCanQuickDraftForRoles(): void
    {
        $this->assertTrue(AP_Admin_Dashboard::canQuickDraft($this->adminId, $this->db));
        $this->assertFalse(AP_Admin_Dashboard::canQuickDraft($this->subscriberId, $this->db));
        $this->assertFalse(AP_Admin_Dashboard::canQuickDraft(0, $this->db));
    }

    public function testDashboardScreenFilesExist(): void
    {
        $this->assertFileIsReadable($this->root . '/ap-admin/index.php');
        $this->assertFileIsReadable($this->root . '/ap-admin/includes/class-ap-admin-dashboard.php');

        $src = (string) file_get_contents($this->root . '/ap-admin/index.php');
        $this->assertStringContainsString('At a Glance', $src);
        $this->assertStringContainsString('Activity', $src);
        $this->assertStringContainsString('Quick Draft', $src);
        $this->assertStringContainsString('AP_Admin_Dashboard::getAtAGlance', $src);
        $this->assertStringContainsString('ap_dashboard_action', $src);

        $bootstrap = (string) file_get_contents($this->root . '/ap-admin/admin-bootstrap.php');
        $this->assertStringContainsString('class-ap-admin-dashboard.php', $bootstrap);

        $admin = (string) file_get_contents($this->root . '/ap-admin/includes/class-ap-admin.php');
        $this->assertStringContainsString("'draft_saved'", $admin);
    }
}
