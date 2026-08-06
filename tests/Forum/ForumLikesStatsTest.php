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
        require_once $this->root . '/ap-includes/hooks.php';
        require_once $this->root . '/ap-includes/functions.php';

        if (function_exists('ap_reset_hooks')) {
            ap_reset_hooks();
        }
        AP_Forum_Permissions::flushCache();
        AP_Forum_Stats::registerHooks();

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
        $recv = AP_Forum_Stats::getUserStats($this->authorId, $this->db);
        $this->assertSame(1, $recv['forum_likes_received']);

        $r2 = AP_Forum_Like::toggle($this->replyId, $this->otherId, $this->db);
        $this->assertTrue($r2['ok']);
        $this->assertFalse($r2['liked']);
        $this->assertSame(0, $r2['count']);
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
}
