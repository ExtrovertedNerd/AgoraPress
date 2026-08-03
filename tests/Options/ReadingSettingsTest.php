<?php

/**
 * Tests for Reading / front-page settings (AP_Options + AP_Rewrite).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Options;

use AP_DB;
use AP_Migrator;
use AP_Options;
use AP_Post;
use AP_Query;
use AP_Rewrite;
use AP_Theme;
use PDO;
use PHPUnit\Framework\TestCase;

final class ReadingSettingsTest extends TestCase
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
        require_once $this->root . '/ap-includes/class-ap-post.php';
        require_once $this->root . '/ap-includes/class-ap-query.php';
        require_once $this->root . '/ap-includes/class-ap-rewrite.php';
        require_once $this->root . '/ap-includes/class-ap-theme.php';
        require_once $this->root . '/ap-includes/functions.php';

        AP_Post::resetRegistry();
        AP_Options::flushCache();
        AP_Rewrite::resetCache();
        AP_Theme::reset();
        unset($GLOBALS['ap_query'], $GLOBALS['ap_post']);

        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');

        $migrator = new AP_Migrator($this->db, AP_Migrator::defaultMigrationsPath());
        $migrator->migrate();
        AP_Post::ensureBuiltins();

        $this->db->insert('options', [
            'option_name' => 'home',
            'option_value' => 'https://example.test',
            'autoload' => 'yes',
        ]);
        $this->db->insert('options', [
            'option_name' => 'siteurl',
            'option_value' => 'https://example.test',
            'autoload' => 'yes',
        ]);
        $this->db->insert('options', [
            'option_name' => 'show_on_front',
            'option_value' => 'posts',
            'autoload' => 'yes',
        ]);
        $this->db->insert('options', [
            'option_name' => 'page_on_front',
            'option_value' => '0',
            'autoload' => 'yes',
        ]);
        $this->db->insert('options', [
            'option_name' => 'page_for_posts',
            'option_value' => '0',
            'autoload' => 'yes',
        ]);
        $this->db->insert('options', [
            'option_name' => 'posts_per_page',
            'option_value' => '10',
            'autoload' => 'yes',
        ]);
        $this->db->insert('options', [
            'option_name' => 'posts_per_rss',
            'option_value' => '10',
            'autoload' => 'yes',
        ]);
        $this->db->insert('options', [
            'option_name' => 'rss_use_excerpt',
            'option_value' => '0',
            'autoload' => 'yes',
        ]);

        $GLOBALS['apdb'] = $this->db;
    }

    protected function tearDown(): void
    {
        AP_Post::resetRegistry();
        AP_Options::flushCache();
        AP_Rewrite::resetCache();
        AP_Theme::reset();
        unset($GLOBALS['ap_query'], $GLOBALS['ap_post'], $GLOBALS['apdb']);
    }

    public function testDefaultReadingHelpers(): void
    {
        $this->assertSame('posts', AP_Options::showOnFront($this->db));
        $this->assertSame(0, AP_Options::pageOnFront($this->db));
        $this->assertSame(0, AP_Options::pageForPosts($this->db));
        $this->assertSame(10, AP_Options::postsPerPage($this->db));
        $this->assertSame(10, AP_Options::postsPerRss($this->db));
        $this->assertFalse(AP_Options::rssUseExcerpt($this->db));
    }

    public function testUpdateReadingSettingsPersistsAndClamps(): void
    {
        $homeId = AP_Post::insert([
            'post_title' => 'Welcome',
            'post_content' => 'Home page',
            'post_status' => 'publish',
            'post_type' => 'page',
            'post_name' => 'welcome',
        ], $this->db);
        $blogId = AP_Post::insert([
            'post_title' => 'Blog',
            'post_content' => 'Posts live here',
            'post_status' => 'publish',
            'post_type' => 'page',
            'post_name' => 'blog',
        ], $this->db);

        $ok = AP_Options::updateReadingSettings([
            'show_on_front' => 'page',
            'page_on_front' => $homeId,
            'page_for_posts' => $blogId,
            'posts_per_page' => 5,
            'posts_per_rss' => 3,
            'rss_use_excerpt' => '1',
        ], $this->db);
        $this->assertTrue($ok);

        AP_Options::flushCache();
        $this->assertSame('page', AP_Options::showOnFront($this->db));
        $this->assertSame($homeId, AP_Options::pageOnFront($this->db));
        $this->assertSame($blogId, AP_Options::pageForPosts($this->db));
        $this->assertSame(5, AP_Options::postsPerPage($this->db));
        $this->assertSame(3, AP_Options::postsPerRss($this->db));
        $this->assertTrue(AP_Options::rssUseExcerpt($this->db));

        // Same page for homepage and posts page → posts page cleared.
        AP_Options::updateReadingSettings([
            'show_on_front' => 'page',
            'page_on_front' => $homeId,
            'page_for_posts' => $homeId,
            'posts_per_page' => 999,
            'posts_per_rss' => 0,
            'rss_use_excerpt' => false,
        ], $this->db);
        AP_Options::flushCache();
        $this->assertSame(0, AP_Options::pageForPosts($this->db));
        $this->assertSame(100, AP_Options::postsPerPage($this->db));
        $this->assertSame(1, AP_Options::postsPerRss($this->db));
        $this->assertFalse(AP_Options::rssUseExcerpt($this->db));
    }

    public function testPostsOnFrontMapsHomeRequest(): void
    {
        AP_Options::updateReadingSettings([
            'show_on_front' => 'posts',
            'posts_per_page' => 7,
        ], $this->db);

        $args = AP_Rewrite::applyReadingSettings([], $this->db);
        $this->assertSame('post', $args['post_type']);
        $this->assertSame(7, $args['posts_per_page']);
        $this->assertTrue($args['ap_is_front_page']);

        $q = AP_Rewrite::queryFromVars([], $this->db);
        $this->assertTrue($q->is_front_page);
        $this->assertTrue($q->is_home);
        $this->assertFalse($q->is_page);
    }

    public function testStaticFrontPageAndPostsPage(): void
    {
        $homeId = AP_Post::insert([
            'post_title' => 'Static Home',
            'post_content' => 'Welcome home',
            'post_status' => 'publish',
            'post_type' => 'page',
            'post_name' => 'static-home',
        ], $this->db);
        $blogId = AP_Post::insert([
            'post_title' => 'News',
            'post_content' => 'Blog index page',
            'post_status' => 'publish',
            'post_type' => 'page',
            'post_name' => 'news',
        ], $this->db);
        AP_Post::insert([
            'post_title' => 'Hello World',
            'post_content' => 'First blog post',
            'post_status' => 'publish',
            'post_type' => 'post',
        ], $this->db);

        AP_Options::updateReadingSettings([
            'show_on_front' => 'page',
            'page_on_front' => $homeId,
            'page_for_posts' => $blogId,
            'posts_per_page' => 10,
        ], $this->db);

        // Front request → static page.
        $front = AP_Rewrite::queryFromVars([], $this->db);
        $this->assertTrue($front->is_front_page);
        $this->assertTrue($front->is_page);
        $this->assertFalse($front->is_home);
        $this->assertSame($homeId, (int) ($front->post->ID ?? 0));

        // Hierarchy prefers front-page.php for static front.
        $hierarchy = AP_Theme::getHierarchy($front);
        $this->assertContains('front-page.php', $hierarchy);

        // Posts page request → blog index, not the page singular.
        $postsPage = AP_Rewrite::queryFromVars(['page_id' => $blogId], $this->db);
        $this->assertTrue($postsPage->is_home);
        $this->assertTrue($postsPage->is_posts_page);
        $this->assertFalse($postsPage->is_front_page);
        $this->assertFalse($postsPage->is_page);
        $this->assertGreaterThanOrEqual(1, $postsPage->post_count);
        $this->assertSame('post', $postsPage->posts[0]->post_type ?? '');
    }

    public function testAdminReadingScreenExists(): void
    {
        $path = $this->root . '/ap-admin/options-reading.php';
        $this->assertFileIsReadable($path);
        $src = (string) file_get_contents($path);
        $this->assertStringContainsString('updateReadingSettings', $src);
        $this->assertStringContainsString('show_on_front', $src);
        $this->assertStringContainsString('page_on_front', $src);
        $this->assertStringContainsString('page_for_posts', $src);
        $this->assertStringContainsString('posts_per_rss', $src);
        $this->assertStringContainsString('rss_use_excerpt', $src);
    }
}
