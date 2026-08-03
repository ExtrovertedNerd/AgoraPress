<?php

/**
 * Tests for AP_Sitemap (XML sitemaps + robots.txt) and AP_Seo (canonical + OG).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Seo;

use AP_DB;
use AP_Migrator;
use AP_Options;
use AP_Post;
use AP_Query;
use AP_Rewrite;
use AP_Seo;
use AP_Sitemap;
use AP_Taxonomy;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_Sitemap::class)]
#[CoversClass(AP_Seo::class)]
final class SitemapSeoTest extends TestCase
{
    private string $root;

    private AP_DB $db;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        require_once $this->root . '/ap-includes/version.php';
        require_once $this->root . '/ap-includes/hooks.php';
        require_once $this->root . '/ap-includes/class-ap-db.php';
        require_once $this->root . '/ap-includes/class-ap-migrator.php';
        require_once $this->root . '/ap-includes/class-ap-options.php';
        require_once $this->root . '/ap-includes/class-ap-post.php';
        require_once $this->root . '/ap-includes/class-ap-taxonomy.php';
        require_once $this->root . '/ap-includes/class-ap-query.php';
        require_once $this->root . '/ap-includes/class-ap-rewrite.php';
        require_once $this->root . '/ap-includes/class-ap-formatting.php';
        require_once $this->root . '/ap-includes/class-ap-sitemap.php';
        require_once $this->root . '/ap-includes/class-ap-seo.php';
        require_once $this->root . '/ap-includes/functions.php';

        AP_Post::resetRegistry();
        AP_Options::flushCache();
        AP_Rewrite::resetCache();
        AP_Seo::reset();
        if (function_exists('ap_reset_hooks')) {
            ap_reset_hooks();
        }

        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');

        $migrator = new AP_Migrator($this->db, AP_Migrator::defaultMigrationsPath());
        $migrator->migrate();
        AP_Post::ensureBuiltins();
        AP_Taxonomy::ensureBuiltins();

        $this->seedOption('blogname', 'SEO Test Site');
        $this->seedOption('blogdescription', 'Sitemap and Open Graph tests');
        $this->seedOption('home', 'https://example.test');
        $this->seedOption('siteurl', 'https://example.test');
        $this->seedOption('permalink_structure', '');
        $this->seedOption('blog_public', '1');
        $this->seedOption('sitemap_enabled', '1');
        $this->seedOption('open_graph_enabled', '1');
        $this->seedOption('ap_module_blog', '1');
        $this->seedOption('ap_module_static_pages', '1');
        $this->seedOption('ap_module_forum', '0');

        $GLOBALS['apdb'] = $this->db;
    }

    protected function tearDown(): void
    {
        AP_Post::resetRegistry();
        AP_Options::flushCache();
        AP_Rewrite::resetCache();
        AP_Seo::reset();
        if (function_exists('ap_reset_hooks')) {
            ap_reset_hooks();
        }
        unset($GLOBALS['apdb'], $GLOBALS['ap_query'], $GLOBALS['ap_post']);
    }

    private function seedOption(string $name, string $value): void
    {
        $this->db->insert('options', [
            'option_name' => $name,
            'option_value' => $value,
            'autoload' => 'yes',
        ]);
    }

    public function testIsSitemapAndRobotsRequest(): void
    {
        $this->assertTrue(AP_Sitemap::isSitemapRequest(['sitemap' => 'index']));
        $this->assertTrue(AP_Sitemap::isSitemapRequest(['sitemap' => 'posts']));
        $this->assertFalse(AP_Sitemap::isSitemapRequest([]));
        $this->assertFalse(AP_Sitemap::isSitemapRequest(['sitemap' => '']));

        $this->assertTrue(AP_Sitemap::isRobotsRequest(['robots' => '1']));
        $this->assertFalse(AP_Sitemap::isRobotsRequest([]));
        $this->assertFalse(AP_Sitemap::isRobotsRequest(['robots' => '0']));
    }

    public function testNormalizeTypeAndProviders(): void
    {
        $this->assertSame('index', AP_Sitemap::normalizeType(''));
        $this->assertSame('index', AP_Sitemap::normalizeType('sitemap'));
        $this->assertSame('posts', AP_Sitemap::normalizeType('posts'));

        $providers = AP_Sitemap::activeProviders($this->db);
        $this->assertContains('posts', $providers);
        $this->assertContains('pages', $providers);
        $this->assertContains('categories', $providers);
        $this->assertContains('tags', $providers);
        $this->assertNotContains('forums', $providers);
    }

    public function testBuildPostsSitemapIncludesPublishedOnly(): void
    {
        AP_Post::insert([
            'post_title' => 'Public Post',
            'post_content' => 'Hello SEO world with enough text for excerpts.',
            'post_status' => 'publish',
            'post_type' => 'post',
            'post_date' => '2026-03-01 10:00:00',
            'post_date_gmt' => '2026-03-01 10:00:00',
            'post_modified' => '2026-03-02 11:00:00',
            'post_modified_gmt' => '2026-03-02 11:00:00',
        ], $this->db);
        AP_Post::insert([
            'post_title' => 'Draft Post',
            'post_content' => 'Hidden',
            'post_status' => 'draft',
            'post_type' => 'post',
        ], $this->db);
        AP_Post::insert([
            'post_title' => 'Secret',
            'post_content' => 'Password',
            'post_status' => 'publish',
            'post_type' => 'post',
            'post_password' => 's3cret',
        ], $this->db);

        $xml = AP_Sitemap::buildProvider('posts', 1, $this->db);
        $this->assertStringContainsString('<?xml version="1.0"', $xml);
        $this->assertStringContainsString('<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">', $xml);
        $this->assertStringContainsString('p=', $xml);
        $this->assertStringContainsString('<lastmod>', $xml);
        $this->assertSame(1, substr_count($xml, '<url>'));
        $this->assertSame(1, AP_Sitemap::countProvider('posts', $this->db));
    }

    public function testBuildIndexListsProvidersWithContent(): void
    {
        AP_Post::insert([
            'post_title' => 'Index Post',
            'post_content' => 'Body',
            'post_status' => 'publish',
            'post_type' => 'post',
        ], $this->db);
        AP_Post::insert([
            'post_title' => 'About',
            'post_content' => 'Page body',
            'post_status' => 'publish',
            'post_type' => 'page',
        ], $this->db);

        $xml = AP_Sitemap::buildIndex($this->db);
        $this->assertStringContainsString('<sitemapindex', $xml);
        $this->assertStringContainsString('sitemap=posts', $xml);
        $this->assertStringContainsString('sitemap=pages', $xml);
        $this->assertStringContainsString('<loc>', $xml);
    }

    public function testCategoriesAndTagsInSitemap(): void
    {
        $cat = AP_Taxonomy::insertTerm('News', 'category', [], $this->db);
        $this->assertIsArray($cat);
        $tag = AP_Taxonomy::insertTerm('php', 'post_tag', [], $this->db);
        $this->assertIsArray($tag);

        $postId = AP_Post::insert([
            'post_title' => 'Tagged',
            'post_content' => 'Content',
            'post_status' => 'publish',
            'post_type' => 'post',
        ], $this->db);
        AP_Taxonomy::setObjectTerms($postId, [(int) $cat['term_id']], 'category', false, $this->db);
        AP_Taxonomy::setObjectTerms($postId, [(int) $tag['term_id']], 'post_tag', false, $this->db);

        $catXml = AP_Sitemap::buildProvider('categories', 1, $this->db);
        $this->assertStringContainsString('<url>', $catXml);
        $this->assertGreaterThanOrEqual(1, AP_Sitemap::countProvider('categories', $this->db));

        $tagXml = AP_Sitemap::buildProvider('tags', 1, $this->db);
        $this->assertStringContainsString('<url>', $tagXml);
    }

    public function testRobotsTxtIncludesSitemapWhenPublic(): void
    {
        $body = AP_Sitemap::buildRobots($this->db);
        $this->assertStringContainsString('User-agent: *', $body);
        $this->assertStringContainsString('Disallow: /ap-admin/', $body);
        $this->assertStringContainsString('Sitemap:', $body);
        $this->assertStringContainsString('sitemap=index', $body);

        AP_Options::update('blog_public', '0', $this->db);
        AP_Options::flushCache();
        $private = AP_Sitemap::buildRobots($this->db);
        $this->assertStringContainsString('Disallow: /', $private);
        $this->assertStringNotContainsString('Sitemap:', $private);
    }

    public function testDisabledSitemapReturns404Body(): void
    {
        AP_Options::update('sitemap_enabled', '0', $this->db);
        AP_Options::flushCache();
        $this->assertFalse(AP_Sitemap::isEnabled($this->db));

        ob_start();
        $body = AP_Sitemap::serve(['sitemap' => 'index'], $this->db, false);
        $echoed = (string) ob_get_clean();
        $this->assertSame($body, $echoed);
        $this->assertStringContainsString('disabled', strtolower($body));
    }

    public function testServeIndexWithoutExit(): void
    {
        AP_Post::insert([
            'post_title' => 'Served',
            'post_content' => 'Body',
            'post_status' => 'publish',
            'post_type' => 'post',
        ], $this->db);

        ob_start();
        $xml = AP_Sitemap::serve(['sitemap' => 'index'], $this->db, false);
        $echoed = (string) ob_get_clean();
        $this->assertSame($xml, $echoed);
        $this->assertStringContainsString('sitemapindex', $xml);
    }

    public function testRewriteParsesSitemapAndRobotsPaths(): void
    {
        // Plain mode still recognizes path-based SEO endpoints.
        $plain = AP_Rewrite::parseRequest('sitemap.xml', [], $this->db);
        $this->assertTrue(AP_Sitemap::isSitemapRequest($plain));
        $this->assertSame('index', $plain['sitemap'] ?? null);

        $posts = AP_Rewrite::parseRequest('sitemap-posts.xml', [], $this->db);
        $this->assertSame('posts', $posts['sitemap'] ?? null);

        $paged = AP_Rewrite::parseRequest('sitemap-posts-2.xml', [], $this->db);
        $this->assertSame('posts', $paged['sitemap'] ?? null);
        $this->assertSame(2, (int) ($paged['sitemap_page'] ?? 0));

        $robots = AP_Rewrite::parseRequest('robots.txt', [], $this->db);
        $this->assertTrue(AP_Sitemap::isRobotsRequest($robots));

        $qs = AP_Rewrite::parseRequest('', ['sitemap' => 'pages'], $this->db);
        $this->assertSame('pages', $qs['sitemap'] ?? null);

        AP_Rewrite::setStructure(AP_Rewrite::STRUCTURE_POST_NAME, $this->db);
        $pretty = AP_Rewrite::parseRequest('sitemap.xml', [], $this->db);
        $this->assertSame('index', $pretty['sitemap'] ?? null);

        $rules = AP_Rewrite::getRules($this->db);
        $this->assertArrayHasKey('sitemap\.xml$', $rules);
        $this->assertArrayHasKey('robots\.txt$', $rules);
    }

    public function testGetSitemapLinkPrettyAndPlain(): void
    {
        $plain = AP_Sitemap::getSitemapLink('index', 1, $this->db);
        $this->assertStringContainsString('sitemap=index', $plain);

        AP_Rewrite::setStructure(AP_Rewrite::STRUCTURE_POST_NAME, $this->db);
        $pretty = AP_Sitemap::getSitemapLink('index', 1, $this->db);
        $this->assertStringEndsWith('/sitemap.xml', $pretty);
        $posts = AP_Sitemap::getSitemapLink('posts', 1, $this->db);
        $this->assertStringContainsString('/sitemap-posts.xml', $posts);
        $page2 = AP_Sitemap::getSitemapLink('posts', 2, $this->db);
        $this->assertStringContainsString('/sitemap-posts-2.xml', $page2);
    }

    public function testCanonicalForSingularAndHome(): void
    {
        $id = AP_Post::insert([
            'post_title' => 'Canonical Post',
            'post_content' => 'Body for canonical tests.',
            'post_status' => 'publish',
            'post_type' => 'post',
            'post_name' => 'canonical-post',
        ], $this->db);

        $post = AP_Post::get($id, $this->db);
        $this->assertInstanceOf(AP_Post::class, $post);

        $q = new AP_Query([
            'p' => $id,
            'post_type' => 'post',
            'post_status' => 'publish',
        ], $this->db);
        $this->assertTrue($q->is_singular);
        $this->assertInstanceOf(AP_Post::class, $q->post);

        $canonical = AP_Seo::getCanonicalUrl($q, $this->db);
        $this->assertNotSame('', $canonical);
        $this->assertStringContainsString('example.test', $canonical);

        $homeQ = new AP_Query([
            'post_type' => 'post',
            'post_status' => 'publish',
            'ap_is_front_page' => true,
        ], $this->db);
        // Force front-page flags if query didn't set them from vars alone.
        $homeQ->is_front_page = true;
        $homeQ->is_home = true;
        $homeCanon = AP_Seo::getCanonicalUrl($homeQ, $this->db);
        $this->assertStringContainsString('example.test', $homeCanon);
    }

    public function testOpenGraphMetaForPost(): void
    {
        $id = AP_Post::insert([
            'post_title' => 'OG Article',
            'post_content' => 'Open Graph body with a decent length for description generation.',
            'post_excerpt' => 'Short OG summary',
            'post_status' => 'publish',
            'post_type' => 'post',
            'post_date' => '2026-05-01 12:00:00',
            'post_date_gmt' => '2026-05-01 12:00:00',
        ], $this->db);

        $q = new AP_Query(['p' => $id], $this->db);
        $meta = AP_Seo::getOpenGraphMeta($q, $this->db);

        $this->assertArrayHasKey('og:title', $meta);
        $this->assertStringContainsString('OG Article', $meta['og:title']);
        $this->assertSame('article', $meta['og:type']);
        $this->assertArrayHasKey('og:url', $meta);
        $this->assertSame('Short OG summary', $meta['og:description']);
        $this->assertSame('SEO Test Site', $meta['og:site_name']);
        $this->assertArrayHasKey('article:published_time', $meta);
        $this->assertArrayHasKey('twitter:card', $meta);
        $this->assertSame('summary', $meta['twitter:card']);
    }

    public function testPrintHeadTagsOutputsCanonicalAndOg(): void
    {
        $id = AP_Post::insert([
            'post_title' => 'Head Tags',
            'post_content' => 'Content',
            'post_status' => 'publish',
            'post_type' => 'post',
        ], $this->db);

        $q = new AP_Query(['p' => $id], $this->db);
        $GLOBALS['ap_query'] = $q;

        AP_Seo::register();
        ob_start();
        AP_Seo::printHeadTags();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('rel="canonical"', $html);
        $this->assertStringContainsString('property="og:title"', $html);
        $this->assertStringContainsString('property="og:type"', $html);
        $this->assertStringContainsString('name="twitter:card"', $html);
    }

    public function testNoindexWhenBlogNotPublic(): void
    {
        AP_Options::update('blog_public', '0', $this->db);
        AP_Options::flushCache();

        $q = new AP_Query(['post_type' => 'post'], $this->db);
        $q->is_front_page = true;
        $q->is_home = true;
        $GLOBALS['ap_query'] = $q;

        ob_start();
        AP_Seo::printHeadTags();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('noindex', $html);
    }

    public function testProceduralHelpers(): void
    {
        $this->assertTrue(function_exists('ap_get_sitemap_link'));
        $this->assertTrue(function_exists('ap_sitemaps_enabled'));
        $this->assertTrue(function_exists('ap_get_canonical_url'));
        $this->assertTrue(function_exists('ap_get_open_graph_meta'));
        $this->assertTrue(function_exists('ap_is_blog_public'));

        $this->assertTrue(ap_sitemaps_enabled($this->db));
        $this->assertTrue(ap_is_blog_public($this->db));
        $link = ap_get_sitemap_link('index', 1, $this->db);
        $this->assertStringContainsString('sitemap', $link);
    }

    public function testMatchSeoPathHelper(): void
    {
        $this->assertSame(['sitemap' => 'index'], AP_Rewrite::matchSeoPath('sitemap.xml'));
        $this->assertSame(['robots' => '1'], AP_Rewrite::matchSeoPath('robots.txt'));
        $this->assertNull(AP_Rewrite::matchSeoPath('hello-world'));
    }
}
