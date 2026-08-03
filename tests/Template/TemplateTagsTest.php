<?php

/**
 * Tests for front-end template tags (the_title, body_class, bloginfo, …).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Template;

use AP_DB;
use AP_Migrator;
use AP_Options;
use AP_Post;
use AP_Query;
use AP_User;
use PDO;
use PHPUnit\Framework\TestCase;

final class TemplateTagsTest extends TestCase
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
        require_once $this->root . '/ap-includes/class-ap-query.php';
        require_once $this->root . '/ap-includes/functions.php';
        require_once $this->root . '/ap-includes/template-tags.php';

        AP_Post::resetRegistry();
        AP_Options::flushCache();
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
            'option_name' => 'blogname',
            'option_value' => 'Template Tag Site',
            'autoload' => 'yes',
        ]);
        $this->db->insert('options', [
            'option_name' => 'blogdescription',
            'option_value' => 'A test tagline',
            'autoload' => 'yes',
        ]);
        $this->db->insert('options', [
            'option_name' => 'date_format',
            'option_value' => 'Y-m-d',
            'autoload' => 'yes',
        ]);
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

        $GLOBALS['apdb'] = $this->db;
    }

    protected function tearDown(): void
    {
        AP_Post::resetRegistry();
        AP_Options::flushCache();
        unset($GLOBALS['ap_query'], $GLOBALS['ap_post'], $GLOBALS['apdb']);
    }

    public function testTitleContentExcerptAndId(): void
    {
        $id = AP_Post::insert([
            'post_title' => 'Hello <World>',
            'post_content' => "Line one.\n\nLine two with more words than needed for an excerpt test pad pad pad pad pad pad pad pad pad pad pad pad pad pad pad pad pad pad pad pad pad pad pad pad pad pad pad pad pad pad pad pad pad pad pad pad pad pad pad pad pad pad pad pad pad pad pad pad pad pad.",
            'post_excerpt' => '',
            'post_status' => 'publish',
            'post_type' => 'post',
        ], $this->db);
        $this->assertGreaterThan(0, $id);

        $post = AP_Post::get($id, $this->db);
        $this->assertInstanceOf(AP_Post::class, $post);
        $GLOBALS['ap_post'] = $post;

        $this->assertSame($id, ap_get_the_ID());
        $this->assertSame('Hello <World>', ap_get_the_title());
        $this->assertStringContainsString('Line one', ap_get_the_content());

        $excerpt = ap_get_the_excerpt(null, 10);
        $this->assertNotSame('', $excerpt);
        $this->assertStringEndsWith('…', $excerpt);

        ob_start();
        ap_the_title('<h1>', '</h1>');
        $out = (string) ob_get_clean();
        $this->assertStringContainsString('<h1>', $out);
        $this->assertStringContainsString('Hello &lt;World&gt;', $out);
        $this->assertStringNotContainsString('<World>', $out);
    }

    public function testPermalinkAndDate(): void
    {
        $id = AP_Post::insert([
            'post_title' => 'Dated',
            'post_content' => 'Body',
            'post_status' => 'publish',
            'post_type' => 'post',
            'post_date' => '2026-03-15 10:30:00',
        ], $this->db);
        $post = AP_Post::get($id, $this->db);
        $GLOBALS['ap_post'] = $post;

        $link = ap_get_the_permalink(null, $this->db);
        $this->assertStringContainsString((string) $id, $link);

        $this->assertSame('2026-03-15', ap_get_the_date('', null, $this->db));
        $this->assertSame('15/03/2026', ap_get_the_date('d/m/Y', null, $this->db));
    }

    public function testAuthorDisplayName(): void
    {
        $hash = AP_User::hashPassword('Str0ng!Pass');
        $this->db->insert('users', [
            'user_login' => 'author1',
            'user_pass' => $hash,
            'user_nicename' => 'author1',
            'user_email' => 'author1@example.test',
            'user_url' => '',
            'user_registered' => gmdate('Y-m-d H:i:s'),
            'user_activation_key' => '',
            'user_status' => 0,
            'display_name' => 'Ada Lovelace',
        ]);
        $userId = (int) $this->db->lastInsertId();
        $this->assertGreaterThan(0, $userId);

        $id = AP_Post::insert([
            'post_title' => 'By Ada',
            'post_content' => 'x',
            'post_status' => 'publish',
            'post_author' => $userId,
        ], $this->db);
        $GLOBALS['ap_post'] = AP_Post::get($id, $this->db);

        $this->assertSame('Ada Lovelace', ap_get_the_author(null, $this->db));
    }

    public function testBloginfoAndBodyClass(): void
    {
        $this->assertSame('Template Tag Site', ap_get_bloginfo('name', $this->db));
        $this->assertSame('A test tagline', ap_get_bloginfo('description', $this->db));
        $this->assertSame('UTF-8', ap_get_bloginfo('charset', $this->db));
        $this->assertNotSame('', ap_get_bloginfo('rss2_url', $this->db));
        $this->assertNotSame('', ap_get_bloginfo('atom_url', $this->db));

        $q = new AP_Query([
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => 5,
            'ap_is_front_page' => true,
        ], $this->db);
        $GLOBALS['ap_query'] = $q;

        $classes = ap_get_body_class('extra-class', $q);
        $this->assertContains('agorapress', $classes);
        $this->assertContains('home', $classes);
        $this->assertContains('front-page', $classes);
        $this->assertContains('blog', $classes);
        $this->assertContains('extra-class', $classes);

        // Non-alphanumeric characters (including spaces) are stripped.
        $this->assertSame('singlepost', ap_sanitize_html_class('Single Post!'));
        $this->assertSame('single-post', ap_sanitize_html_class('single-post'));
    }

    public function testBodyClassSingularPage(): void
    {
        $pageId = AP_Post::insert([
            'post_title' => 'About',
            'post_content' => 'About us',
            'post_status' => 'publish',
            'post_type' => 'page',
        ], $this->db);

        $q = new AP_Query([
            'page_id' => $pageId,
            'post_type' => 'page',
            'ap_is_front_page' => true,
        ], $this->db);
        $this->assertTrue($q->is_page);
        $this->assertTrue($q->is_front_page);

        $classes = ap_get_body_class([], $q);
        $this->assertContains('front-page', $classes);
        $this->assertContains('page', $classes);
        $this->assertContains('page-id-' . $pageId, $classes);
        $this->assertContains('singular', $classes);
    }
}
