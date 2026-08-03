<?php

/**
 * Integration: roles/capabilities gate content + forum actions on one install.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Integration;

use AP_DB;
use AP_Forum;
use AP_Migrator;
use AP_Options;
use AP_Post;
use AP_Roles;
use AP_User;
use PDO;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class RolesCapsContentTest extends TestCase
{
    private string $root;

    private AP_DB $db;

    private int $adminId = 0;

    private int $subscriberId = 0;

    private int $authorId = 0;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        require_once $this->root . '/ap-includes/version.php';
        require_once $this->root . '/ap-includes/class-ap-db.php';
        require_once $this->root . '/ap-includes/class-ap-migrator.php';
        require_once $this->root . '/ap-includes/class-ap-options.php';
        require_once $this->root . '/ap-includes/class-ap-user.php';
        require_once $this->root . '/ap-includes/class-ap-roles.php';
        require_once $this->root . '/ap-includes/class-ap-post.php';
        require_once $this->root . '/ap-includes/class-ap-forum.php';
        require_once $this->root . '/ap-includes/hooks.php';
        require_once $this->root . '/ap-includes/functions.php';

        if (function_exists('ap_reset_hooks')) {
            ap_reset_hooks();
        }
        AP_Post::resetRegistry();
        AP_Options::flushCache();
        AP_Roles::flushCache();

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
        AP_Roles::ensureDefaults($this->db);
        AP_Options::update('blogname', 'Caps Coexist', $this->db);
        AP_Options::update(AP_Options::MODULE_BLOG, '1', $this->db);
        AP_Options::update(AP_Options::MODULE_STATIC_PAGES, '1', $this->db);
        AP_Options::update(AP_Options::MODULE_FORUM, '1', $this->db);

        $admin = AP_User::create([
            'user_login' => 'cap_admin',
            'user_email' => 'cap_admin@example.test',
            'user_pass' => 'AdminPass-1!',
            'role' => 'administrator',
        ], $this->db);
        $this->assertTrue($admin['ok'] ?? false, implode('; ', $admin['errors'] ?? []));
        $this->adminId = (int) $admin['id'];

        $author = AP_User::create([
            'user_login' => 'cap_author',
            'user_email' => 'cap_author@example.test',
            'user_pass' => 'AuthorPass-1!',
            'role' => 'author',
        ], $this->db);
        $this->assertTrue($author['ok'] ?? false);
        $this->authorId = (int) $author['id'];

        $sub = AP_User::create([
            'user_login' => 'cap_sub',
            'user_email' => 'cap_sub@example.test',
            'user_pass' => 'SubPass-1!',
            'role' => 'subscriber',
        ], $this->db);
        $this->assertTrue($sub['ok'] ?? false);
        $this->subscriberId = (int) $sub['id'];
    }

    protected function tearDown(): void
    {
        AP_Post::resetRegistry();
        AP_Options::flushCache();
        AP_Roles::flushCache();
        if (function_exists('ap_reset_hooks')) {
            ap_reset_hooks();
        }
        unset($GLOBALS['apdb']);
    }

    public function testRoleCapabilitiesDifferAcrossRoles(): void
    {
        $this->assertTrue(AP_Roles::userCan($this->adminId, 'manage_options', null, $this->db));
        $this->assertTrue(AP_Roles::userCan($this->adminId, 'edit_posts', null, $this->db));
        $this->assertTrue(AP_Roles::userCan($this->adminId, 'publish_posts', null, $this->db));

        $this->assertFalse(AP_Roles::userCan($this->authorId, 'manage_options', null, $this->db));
        $this->assertTrue(AP_Roles::userCan($this->authorId, 'edit_posts', null, $this->db));
        $this->assertTrue(AP_Roles::userCan($this->authorId, 'publish_posts', null, $this->db));

        $this->assertFalse(AP_Roles::userCan($this->subscriberId, 'edit_posts', null, $this->db));
        $this->assertFalse(AP_Roles::userCan($this->subscriberId, 'publish_posts', null, $this->db));
        $this->assertFalse(AP_Roles::userCan($this->subscriberId, 'manage_options', null, $this->db));
    }

    public function testAuthorCanCreateBlogPostSubscriberCannotPublishCap(): void
    {
        $this->assertTrue(AP_Roles::userCan($this->authorId, 'publish_posts', null, $this->db));
        $this->assertFalse(AP_Roles::userCan($this->subscriberId, 'publish_posts', null, $this->db));

        $postId = AP_Post::insert([
            'post_title' => 'Author Post',
            'post_content' => 'From author',
            'post_status' => 'publish',
            'post_type' => 'post',
            'post_author' => $this->authorId,
        ], $this->db);
        $this->assertGreaterThan(0, $postId);

        $post = AP_Post::get($postId, $this->db);
        $this->assertNotNull($post);
        $this->assertSame($this->authorId, (int) $post->post_author);
        $this->assertSame('publish', $post->post_status);
    }

    public function testSharedUserCanPostInForum(): void
    {
        $forumId = AP_Forum::insertForum([
            'forum_name' => 'Caps Forum',
            'forum_type' => 'forum',
        ], $this->db);
        $this->assertGreaterThan(0, $forumId);

        $topicId = AP_Forum::createTopic([
            'forum_id' => $forumId,
            'topic_title' => 'Author topic',
            'content' => 'Forum body from author',
            'poster_id' => $this->authorId,
        ], $this->db);
        $this->assertGreaterThan(0, $topicId);

        $topic = AP_Forum::getTopic($topicId, $this->db);
        $this->assertNotNull($topic);
        $this->assertSame($this->authorId, (int) $topic->topic_poster);

        // Same user id owns both blog author and forum poster.
        $user = AP_User::getById($this->authorId, $this->db);
        $this->assertNotNull($user);
        $this->assertSame('cap_author', $user->user_login);
    }

    public function testAdminCanManageForumStructure(): void
    {
        $this->assertTrue(AP_Roles::userCan($this->adminId, 'manage_options', null, $this->db));

        $forumId = AP_Forum::insertForum([
            'forum_name' => 'Admin Board',
            'forum_type' => 'forum',
        ], $this->db);
        $this->assertGreaterThan(0, $forumId);

        $ok = AP_Forum::updateForum($forumId, ['forum_name' => 'Renamed Board'], $this->db);
        $this->assertTrue($ok);
        $forum = AP_Forum::getForum($forumId, $this->db);
        $this->assertNotNull($forum);
        $this->assertSame('Renamed Board', $forum->forum_name);
    }
}
