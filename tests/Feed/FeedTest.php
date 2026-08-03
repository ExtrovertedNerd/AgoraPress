<?php

/**
 * Tests for AP_Feed — RSS 2.0 and Atom syndication.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Feed;

use AP_DB;
use AP_Feed;
use AP_Migrator;
use AP_Options;
use AP_Post;
use AP_Rewrite;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_Feed::class)]
final class FeedTest extends TestCase
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
        require_once $this->root . '/ap-includes/class-ap-feed.php';
        require_once $this->root . '/ap-includes/functions.php';

        AP_Post::resetRegistry();
        AP_Options::flushCache();
        AP_Rewrite::resetCache();

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
            'option_value' => 'Feed Test Site',
            'autoload' => 'yes',
        ]);
        $this->db->insert('options', [
            'option_name' => 'blogdescription',
            'option_value' => 'Syndication unit tests',
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
        $this->db->insert('options', [
            'option_name' => 'permalink_structure',
            'option_value' => '',
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
        unset($GLOBALS['apdb']);
    }

    public function testNormalizeTypeAndIsFeedRequest(): void
    {
        $this->assertSame('rss2', AP_Feed::normalizeType(''));
        $this->assertSame('rss2', AP_Feed::normalizeType('rss'));
        $this->assertSame('rss2', AP_Feed::normalizeType('feed'));
        $this->assertSame('atom', AP_Feed::normalizeType('atom'));
        $this->assertSame('rss2', AP_Feed::normalizeType('unknown'));

        $this->assertTrue(AP_Feed::isFeedRequest(['feed' => 'rss2']));
        $this->assertTrue(AP_Feed::isFeedRequest(['feed' => 'atom']));
        $this->assertFalse(AP_Feed::isFeedRequest([]));
        $this->assertFalse(AP_Feed::isFeedRequest(['feed' => '']));
    }

    public function testBuildRss2IncludesPublishedPosts(): void
    {
        AP_Post::insert([
            'post_title' => 'First Post',
            'post_content' => '<p>Hello <strong>world</strong></p>',
            'post_excerpt' => 'Short summary',
            'post_status' => 'publish',
            'post_type' => 'post',
            'post_date' => '2026-01-10 12:00:00',
            'post_date_gmt' => '2026-01-10 12:00:00',
        ], $this->db);
        AP_Post::insert([
            'post_title' => 'Draft Hidden',
            'post_content' => 'Should not appear',
            'post_status' => 'draft',
            'post_type' => 'post',
        ], $this->db);

        $xml = AP_Feed::buildRss2($this->db);

        $this->assertStringContainsString('<?xml version="1.0"', $xml);
        $this->assertStringContainsString('<rss version="2.0"', $xml);
        $this->assertStringContainsString('<title>Feed Test Site</title>', $xml);
        $this->assertStringContainsString('First Post', $xml);
        $this->assertStringContainsString('Short summary', $xml);
        $this->assertStringContainsString('<content:encoded>', $xml);
        $this->assertStringNotContainsString('Draft Hidden', $xml);
        $this->assertStringContainsString('application/rss+xml', $xml);
    }

    public function testRssRespectsExcerptSettingAndLimit(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            AP_Post::insert([
                'post_title' => "Post {$i}",
                'post_content' => "Full content for post {$i} with enough text.",
                'post_status' => 'publish',
                'post_type' => 'post',
                'post_date' => sprintf('2026-02-%02d 10:00:00', $i),
                'post_date_gmt' => sprintf('2026-02-%02d 10:00:00', $i),
            ], $this->db);
        }

        AP_Options::update('posts_per_rss', '2', $this->db);
        AP_Options::update('rss_use_excerpt', '1', $this->db);
        AP_Options::flushCache();

        $xml = AP_Feed::buildRss2($this->db);
        $this->assertSame(2, substr_count($xml, '<item>'));
        $this->assertStringNotContainsString('<content:encoded>', $xml);
        $this->assertStringContainsString('Post 5', $xml);
        $this->assertStringContainsString('Post 4', $xml);
        $this->assertStringNotContainsString('Post 1', $xml);
    }

    public function testBuildAtom(): void
    {
        AP_Post::insert([
            'post_title' => 'Atom Entry',
            'post_content' => 'Atom body text',
            'post_status' => 'publish',
            'post_type' => 'post',
            'post_date' => '2026-04-01 08:00:00',
            'post_date_gmt' => '2026-04-01 08:00:00',
        ], $this->db);

        $xml = AP_Feed::buildAtom($this->db);
        $this->assertStringContainsString('<feed xmlns="http://www.w3.org/2005/Atom">', $xml);
        $this->assertStringContainsString('<title>Feed Test Site</title>', $xml);
        $this->assertStringContainsString('Atom Entry', $xml);
        $this->assertStringContainsString('<entry>', $xml);
        $this->assertStringContainsString('AgoraPress', $xml);
    }

    public function testServeWithoutExit(): void
    {
        AP_Post::insert([
            'post_title' => 'Served',
            'post_content' => 'Body',
            'post_status' => 'publish',
            'post_type' => 'post',
        ], $this->db);

        ob_start();
        $xml = AP_Feed::serve(['feed' => 'atom'], $this->db, false);
        $echoed = (string) ob_get_clean();

        $this->assertSame($xml, $echoed);
        $this->assertStringContainsString('<feed', $xml);
        $this->assertStringContainsString('Served', $xml);
    }

    public function testRewriteParsesFeedPaths(): void
    {
        $plain = AP_Rewrite::parseRequest('', ['feed' => 'rss2'], $this->db);
        $this->assertArrayHasKey('feed', $plain);
        $this->assertSame('rss2', $plain['feed']);

        AP_Rewrite::setStructure(AP_Rewrite::STRUCTURE_POST_NAME, $this->db);
        $pretty = AP_Rewrite::parseRequest('feed', [], $this->db);
        $this->assertTrue(AP_Feed::isFeedRequest($pretty));
        $this->assertSame('rss2', $pretty['feed'] ?? null);

        $atom = AP_Rewrite::parseRequest('feed/atom', [], $this->db);
        $this->assertSame('atom', $atom['feed'] ?? null);

        $link = AP_Rewrite::getFeedLink('rss2', $this->db);
        $this->assertStringContainsString('/feed/', $link);
        $this->assertStringContainsString('https://example.test', $link);
    }

    public function testProceduralFeedLinkHelper(): void
    {
        $url = ap_get_feed_link('atom', $this->db);
        $this->assertStringContainsString('atom', $url);
    }
}
