<?php

/**
 * Tests for AP_Rewrite — permalinks and rewrite rules.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Rewrite;

use AP_DB;
use AP_Migrator;
use AP_Post;
use AP_Query;
use AP_Rewrite;
use AP_Taxonomy;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_Rewrite::class)]
final class RewriteTest extends TestCase
{
    private string $root;

    private AP_DB $db;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        require_once $this->root . '/ap-includes/version.php';
        require_once $this->root . '/ap-includes/class-ap-db.php';
        require_once $this->root . '/ap-includes/class-ap-migrator.php';
        require_once $this->root . '/ap-includes/class-ap-post.php';
        require_once $this->root . '/ap-includes/class-ap-taxonomy.php';
        require_once $this->root . '/ap-includes/class-ap-query.php';
        require_once $this->root . '/ap-includes/class-ap-rewrite.php';
        require_once $this->root . '/ap-includes/functions.php';

        AP_Post::resetRegistry();
        AP_Taxonomy::resetRegistry();
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
        AP_Taxonomy::ensureBuiltins();

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
    }

    protected function tearDown(): void
    {
        AP_Post::resetRegistry();
        AP_Taxonomy::resetRegistry();
        AP_Rewrite::resetCache();
        unset($GLOBALS['ap_query'], $GLOBALS['ap_post']);
    }

    public function testPlainPermalinkForPostAndPage(): void
    {
        $this->assertFalse(AP_Rewrite::usingPermalinks($this->db));

        $postId = AP_Post::insert([
            'post_title' => 'Hello',
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_name' => 'hello',
            'post_date' => '2026-08-03 12:00:00',
        ], $this->db);
        $pageId = AP_Post::insert([
            'post_title' => 'About',
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_name' => 'about',
        ], $this->db);

        $this->assertSame(
            'https://example.test/?p=' . $postId,
            AP_Rewrite::getPermalink($postId, $this->db)
        );
        $this->assertSame(
            'https://example.test/?page_id=' . $pageId,
            AP_Rewrite::getPageLink($pageId, $this->db)
        );
        $this->assertSame(
            'https://example.test/?page_id=' . $pageId,
            ap_get_permalink($pageId, $this->db)
        );
    }

    public function testPostNameStructurePermalinkAndParse(): void
    {
        AP_Rewrite::setStructure(AP_Rewrite::STRUCTURE_POST_NAME, $this->db);
        $this->assertTrue(AP_Rewrite::usingPermalinks($this->db));
        $this->assertSame('/%postname%/', AP_Rewrite::getStructure($this->db));

        $postId = AP_Post::insert([
            'post_title' => 'Hello World',
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_name' => 'hello-world',
            'post_date' => '2020-08-03 12:00:00',
        ], $this->db);

        $this->assertSame(
            'https://example.test/hello-world/',
            AP_Rewrite::getPermalink($postId, $this->db)
        );

        $vars = AP_Rewrite::parseRequest('hello-world', [], $this->db);
        $this->assertSame('hello-world', $vars['name'] ?? null);

        $q = AP_Rewrite::queryFromVars($vars, $this->db);
        $this->assertSame(1, $q->post_count);
        $this->assertSame($postId, $q->posts[0]->ID);
        $this->assertTrue($q->is_single || $q->is_singular);
    }

    public function testDayAndNameStructure(): void
    {
        AP_Rewrite::setStructure(AP_Rewrite::STRUCTURE_DAY_NAME, $this->db);

        $postId = AP_Post::insert([
            'post_title' => 'Dated',
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_name' => 'dated-post',
            'post_date' => '2020-08-03 09:30:00',
        ], $this->db);

        $url = AP_Rewrite::getPermalink($postId, $this->db);
        $this->assertSame('https://example.test/2020/08/03/dated-post/', $url);

        $vars = AP_Rewrite::parseRequest('2020/08/03/dated-post', [], $this->db);
        $this->assertSame(2020, (int) ($vars['year'] ?? 0));
        $this->assertSame(8, (int) ($vars['monthnum'] ?? 0));
        $this->assertSame(3, (int) ($vars['day'] ?? 0));
        $this->assertSame('dated-post', $vars['name'] ?? null);
    }

    public function testNumericStructure(): void
    {
        AP_Rewrite::setStructure(AP_Rewrite::STRUCTURE_NUMERIC, $this->db);

        $postId = AP_Post::insert([
            'post_title' => 'Num',
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_name' => 'num',
        ], $this->db);

        $this->assertSame(
            'https://example.test/archives/' . $postId . '/',
            AP_Rewrite::getPermalink($postId, $this->db)
        );

        $vars = AP_Rewrite::parseRequest('archives/' . $postId, [], $this->db);
        $this->assertSame($postId, (int) ($vars['p'] ?? 0));
    }

    public function testMonthAndNameStructure(): void
    {
        AP_Rewrite::setStructure(AP_Rewrite::STRUCTURE_MONTH_NAME, $this->db);

        $postId = AP_Post::insert([
            'post_title' => 'Monthly',
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_name' => 'monthly',
            'post_date' => '2020-07-15 10:00:00',
        ], $this->db);

        $this->assertSame(
            'https://example.test/2020/07/monthly/',
            AP_Rewrite::getPermalink($postId, $this->db)
        );
    }

    public function testHierarchicalPagePermalink(): void
    {
        AP_Rewrite::setStructure(AP_Rewrite::STRUCTURE_POST_NAME, $this->db);

        $parent = AP_Post::insert([
            'post_title' => 'Parent',
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_name' => 'parent',
        ], $this->db);
        $child = AP_Post::insert([
            'post_title' => 'Child',
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_name' => 'child',
            'post_parent' => $parent,
        ], $this->db);

        $this->assertSame(
            'https://example.test/parent/child/',
            AP_Rewrite::getPageLink($child, $this->db)
        );

        $vars = AP_Rewrite::parseRequest('parent/child', [], $this->db);
        $this->assertSame('parent/child', $vars['pagename'] ?? null);

        $q = AP_Rewrite::queryFromVars($vars, $this->db);
        $this->assertSame(1, $q->post_count);
        $this->assertSame($child, $q->posts[0]->ID);
        $this->assertTrue($q->is_page);
    }

    public function testCategoryAndTagLinksAndParse(): void
    {
        AP_Rewrite::setStructure(AP_Rewrite::STRUCTURE_POST_NAME, $this->db);
        AP_Taxonomy::ensureDefaultCategory($this->db);

        $cat = AP_Taxonomy::insertTerm('News', 'category', [], $this->db);
        $this->assertIsArray($cat);
        $tag = AP_Taxonomy::insertTerm('php', 'post_tag', [], $this->db);
        $this->assertIsArray($tag);

        $catTerm = AP_Taxonomy::getTerm((int) $cat['term_id'], 'category', $this->db);
        $tagTerm = AP_Taxonomy::getTerm((int) $tag['term_id'], 'post_tag', $this->db);
        $this->assertNotNull($catTerm);
        $this->assertNotNull($tagTerm);

        $this->assertSame(
            'https://example.test/category/' . $catTerm->slug . '/',
            AP_Rewrite::getTermLink($catTerm, 'category', $this->db)
        );
        $this->assertSame(
            'https://example.test/tag/' . $tagTerm->slug . '/',
            AP_Rewrite::getTermLink($tagTerm, 'post_tag', $this->db)
        );

        $catVars = AP_Rewrite::parseRequest('category/' . $catTerm->slug, [], $this->db);
        $this->assertSame($catTerm->slug, $catVars['category_name'] ?? null);

        $tagVars = AP_Rewrite::parseRequest('tag/' . $tagTerm->slug . '/page/2', [], $this->db);
        $this->assertSame($tagTerm->slug, $tagVars['tag'] ?? null);
        $this->assertSame(2, (int) ($tagVars['paged'] ?? 0));
    }

    public function testCustomCategoryBase(): void
    {
        AP_Rewrite::setStructure(AP_Rewrite::STRUCTURE_POST_NAME, $this->db);
        AP_Rewrite::setCategoryBase('topics', $this->db);

        $this->assertSame('topics', AP_Rewrite::getCategoryBase($this->db));

        $vars = AP_Rewrite::parseRequest('topics/science', [], $this->db);
        $this->assertSame('science', $vars['category_name'] ?? null);
    }

    public function testAuthorAndSearchLinks(): void
    {
        AP_Rewrite::setStructure(AP_Rewrite::STRUCTURE_POST_NAME, $this->db);

        $this->assertSame(
            'https://example.test/author/alice/',
            AP_Rewrite::getAuthorLink('alice', $this->db)
        );
        $this->assertSame(
            'https://example.test/search/hello%20world/',
            AP_Rewrite::getSearchLink('hello world', $this->db)
        );

        $authorVars = AP_Rewrite::parseRequest('author/alice', [], $this->db);
        $this->assertSame('alice', $authorVars['author_name'] ?? null);

        $searchVars = AP_Rewrite::parseRequest('search/hello%20world', [], $this->db);
        $this->assertSame('hello world', $searchVars['s'] ?? null);
    }

    public function testPlainModeParsesQueryString(): void
    {
        $this->assertFalse(AP_Rewrite::usingPermalinks($this->db));

        $vars = AP_Rewrite::parseRequest('', ['p' => '42', 'preview' => '1'], $this->db);
        $this->assertSame(42, $vars['p'] ?? null);
        $this->assertSame('1', $vars['preview'] ?? null);

        $home = AP_Rewrite::parseRequest('', [], $this->db);
        $this->assertSame('post', $home['post_type'] ?? null);
    }

    public function testDateArchiveParse(): void
    {
        AP_Rewrite::setStructure(AP_Rewrite::STRUCTURE_POST_NAME, $this->db);

        $vars = AP_Rewrite::parseRequest('2020/08', [], $this->db);
        $this->assertSame(2020, (int) ($vars['year'] ?? 0));
        $this->assertSame(8, (int) ($vars['monthnum'] ?? 0));
        $this->assertArrayNotHasKey('name', $vars);
    }

    public function testFlushRulesPersists(): void
    {
        AP_Rewrite::setStructure(AP_Rewrite::STRUCTURE_POST_NAME, $this->db);
        $rules = AP_Rewrite::getRules($this->db);
        $this->assertNotEmpty($rules);

        AP_Rewrite::resetCache();
        $loaded = AP_Rewrite::getRules($this->db);
        $this->assertSame($rules, $loaded);
    }

    public function testCommonStructuresAndNormalize(): void
    {
        $common = AP_Rewrite::commonStructures();
        $this->assertArrayHasKey('Post name', $common);
        $this->assertSame('/%postname%/', $common['Post name']);

        $this->assertSame(
            '/%year%/%monthnum%/%postname%/',
            AP_Rewrite::normalizeStructure('%year%/%monthnum%/%postname%')
        );
        $this->assertSame('', AP_Rewrite::normalizeStructure(''));
        $this->assertSame('', AP_Rewrite::normalizeStructure('/'));
    }

    public function testApacheAndNginxSnippets(): void
    {
        $apache = AP_Rewrite::apacheRewriteBlock('/');
        $this->assertStringContainsString('RewriteEngine On', $apache);
        $this->assertStringContainsString('index.php', $apache);

        $nginx = AP_Rewrite::nginxTryFilesSnippet();
        $this->assertStringContainsString('try_files', $nginx);
    }

    public function testHomeUrlAndSiteUrl(): void
    {
        $this->assertSame('https://example.test', AP_Rewrite::homeUrl('', $this->db));
        $this->assertSame(
            'https://example.test/foo/bar',
            AP_Rewrite::homeUrl('/foo/bar', $this->db)
        );
        $this->assertSame(
            'https://example.test/?s=x',
            AP_Rewrite::homeUrl('?s=x', $this->db)
        );
        $this->assertSame('https://example.test', AP_Rewrite::siteUrl('', $this->db));
    }

    public function testSubdirectoryHomePathStripping(): void
    {
        $this->db->update(
            'options',
            ['option_value' => 'https://example.test/blog'],
            ['option_name' => 'home']
        );
        AP_Rewrite::setStructure(AP_Rewrite::STRUCTURE_POST_NAME, $this->db);

        $vars = AP_Rewrite::parseRequest('/blog/hello-world', [], $this->db);
        $this->assertSame('hello-world', $vars['name'] ?? null);
    }

    public function testProceduralHelpers(): void
    {
        AP_Rewrite::setStructure(AP_Rewrite::STRUCTURE_POST_NAME, $this->db);
        $this->assertTrue(ap_using_permalinks($this->db));
        $this->assertSame('/%postname%/', ap_get_permalink_structure($this->db));

        $id = AP_Post::insert([
            'post_title' => 'Helpers',
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_name' => 'helpers',
        ], $this->db);

        $this->assertSame(
            'https://example.test/helpers/',
            ap_get_permalink($id, $this->db)
        );
        $this->assertSame(
            'https://example.test',
            ap_home_url('', $this->db)
        );

        $vars = ap_parse_request('helpers', [], $this->db);
        $this->assertSame('helpers', $vars['name'] ?? null);
    }

    public function testQueryFromVarsBuildsMainLoop(): void
    {
        AP_Rewrite::setStructure(AP_Rewrite::STRUCTURE_POST_NAME, $this->db);
        $id = AP_Post::insert([
            'post_title' => 'Loop',
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_name' => 'loop-me',
            'post_content' => 'body',
        ], $this->db);

        $vars = AP_Rewrite::parseRequest('loop-me', [], $this->db);
        $q = AP_Rewrite::queryFromVars($vars, $this->db);
        ap_set_query($q);
        $this->assertTrue(ap_have_posts());
        ap_the_post();
        $this->assertSame($id, ap_get_queried_post()?->ID);
    }

    public function testToQueryArgsMapsPage(): void
    {
        $args = AP_Rewrite::toQueryArgs(['pagename' => 'about']);
        $this->assertSame('about', $args['pagename']);
        $this->assertSame('page', $args['post_type']);
    }

    public function testRootHtaccessStillFrontController(): void
    {
        $ht = (string) file_get_contents($this->root . '/.htaccess');
        $this->assertStringContainsString('RewriteEngine On', $ht);
        $this->assertStringContainsString('index.php', $ht);
        $this->assertStringContainsString('REQUEST_FILENAME', $ht);
    }
}
