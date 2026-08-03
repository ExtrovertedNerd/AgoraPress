<?php

/**
 * Integration: blog posts, pages, comments, and forum topics share one install.
 *
 * Exercises the three independent modules on a single SQLite schema so a
 * regression that breaks cross-module table usage fails loudly.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Integration;

use AP_Comment;
use AP_DB;
use AP_Forum;
use AP_Migrator;
use AP_Options;
use AP_Post;
use AP_Query;
use AP_User;
use PDO;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class ContentCoexistenceTest extends TestCase
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
        require_once $this->root . '/ap-includes/class-ap-post.php';
        require_once $this->root . '/ap-includes/class-ap-comment.php';
        require_once $this->root . '/ap-includes/class-ap-query.php';
        require_once $this->root . '/ap-includes/class-ap-forum.php';
        require_once $this->root . '/ap-includes/hooks.php';
        require_once $this->root . '/ap-includes/functions.php';

        if (function_exists('ap_reset_hooks')) {
            ap_reset_hooks();
        }
        AP_Post::resetRegistry();
        AP_Options::flushCache();

        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');
        $GLOBALS['apdb'] = $this->db;

        $migrator = new AP_Migrator($this->db, AP_Migrator::defaultMigrationsPath());
        $migrator->migrate();

        AP_Post::ensureBuiltins();
        AP_Options::update('blogname', 'Coexist Site', $this->db);
        AP_Options::update('siteurl', 'https://example.test', $this->db);
        AP_Options::update('home', 'https://example.test', $this->db);
        AP_Options::update(AP_Options::MODULE_BLOG, '1', $this->db);
        AP_Options::update(AP_Options::MODULE_STATIC_PAGES, '1', $this->db);
        AP_Options::update(AP_Options::MODULE_FORUM, '1', $this->db);
    }

    protected function tearDown(): void
    {
        AP_Post::resetRegistry();
        AP_Options::flushCache();
        if (function_exists('ap_reset_hooks')) {
            ap_reset_hooks();
        }
        unset($GLOBALS['apdb'], $GLOBALS['ap_query'], $GLOBALS['ap_post']);
    }

    public function testBlogPageCommentAndForumShareSchema(): void
    {
        $created = AP_User::create([
            'user_login' => 'author1',
            'user_email' => 'author1@example.test',
            'user_pass' => 'secure-pass-1',
            'display_name' => 'Author One',
        ], $this->db);
        $this->assertTrue($created['ok'] ?? false, implode('; ', $created['errors'] ?? []));
        $userId = (int) ($created['id'] ?? 0);
        $this->assertGreaterThan(0, $userId);

        $postId = AP_Post::insert([
            'post_title' => 'Hello Blog',
            'post_content' => 'Blog body',
            'post_status' => 'publish',
            'post_type' => 'post',
            'post_name' => 'hello-blog',
            'post_author' => $userId,
            'comment_status' => 'open',
        ], $this->db);
        $this->assertGreaterThan(0, $postId);

        $pageId = AP_Post::insert([
            'post_title' => 'About',
            'post_content' => 'About page',
            'post_status' => 'publish',
            'post_type' => 'page',
            'post_name' => 'about',
            'post_author' => $userId,
        ], $this->db);
        $this->assertGreaterThan(0, $pageId);

        $commentId = AP_Comment::insert([
            'comment_post_ID' => $postId,
            'comment_author' => 'Visitor',
            'comment_author_email' => 'visitor@example.test',
            'comment_content' => 'Nice post',
            'comment_approved' => '1',
            'user_id' => 0,
        ], $this->db);
        $this->assertGreaterThan(0, $commentId);

        $forumId = AP_Forum::insertForum([
            'forum_name' => 'General',
            'forum_type' => 'forum',
            'forum_desc' => 'Main board',
        ], $this->db);
        $this->assertGreaterThan(0, $forumId);

        $topicId = AP_Forum::createTopic([
            'forum_id' => $forumId,
            'topic_title' => 'Welcome',
            'content' => 'First forum post',
            'poster_id' => $userId,
        ], $this->db);
        $this->assertGreaterThan(0, $topicId);

        // Blog query must not surface pages or forum tables.
        $q = new AP_Query([
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => 10,
        ], $this->db);
        $this->assertSame(1, $q->found_posts);
        $this->assertSame($postId, (int) $q->posts[0]->ID);

        $page = AP_Post::get($pageId, $this->db);
        $this->assertNotNull($page);
        $this->assertSame('page', $page->post_type);

        $comments = AP_Comment::getByPost($postId, ['status' => 'approve'], $this->db);
        $this->assertCount(1, $comments);
        $this->assertSame('Nice post', $comments[0]->comment_content);

        $topic = AP_Forum::getTopic($topicId, $this->db);
        $this->assertNotNull($topic);
        $this->assertSame('Welcome', $topic->topic_title);
        $this->assertSame($forumId, (int) $topic->forum_id);

        $first = AP_Forum::getPost((int) $topic->first_post_id, $this->db);
        $this->assertNotNull($first);
        $this->assertStringContainsString('First forum post', (string) $first->post_content);

        // Shared users table: forum poster resolves to the blog author.
        $author = AP_User::getById($userId, $this->db);
        $this->assertNotNull($author);
        $this->assertSame('author1', $author->user_login);
        $this->assertSame($userId, (int) $topic->topic_poster);
    }

    public function testModuleTogglesIndependentCombinations(): void
    {
        $combos = [
            ['static_pages' => true, 'blog' => true, 'forum' => true],
            ['static_pages' => true, 'blog' => true, 'forum' => false],
            ['static_pages' => true, 'blog' => false, 'forum' => true],
            ['static_pages' => false, 'blog' => true, 'forum' => true],
            ['static_pages' => true, 'blog' => false, 'forum' => false],
            ['static_pages' => false, 'blog' => true, 'forum' => false],
            ['static_pages' => false, 'blog' => false, 'forum' => true],
        ];

        foreach ($combos as $modules) {
            $ok = AP_Options::updateModules($modules, $this->db);
            $this->assertTrue($ok, 'Failed for ' . json_encode($modules));
            foreach ($modules as $slug => $on) {
                $this->assertSame(
                    $on,
                    AP_Options::isModuleEnabled($slug, $this->db),
                    "Module {$slug} mismatch for " . json_encode($modules)
                );
            }
        }

        $this->assertFalse(AP_Options::updateModules([
            'static_pages' => false,
            'blog' => false,
            'forum' => false,
        ], $this->db));
        // Last valid combo (forum only) must still be active.
        $this->assertTrue(AP_Options::isModuleEnabled('forum', $this->db));
    }
}
