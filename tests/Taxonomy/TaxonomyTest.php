<?php

/**
 * Tests for AP_Taxonomy — registry, term CRUD, relationships, default category.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Taxonomy;

use AP_DB;
use AP_Migrator;
use AP_Post;
use AP_Query;
use AP_Taxonomy;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_Taxonomy::class)]
final class TaxonomyTest extends TestCase
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
        require_once $this->root . '/ap-includes/functions.php';

        AP_Post::resetRegistry();
        AP_Taxonomy::resetRegistry();

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
    }

    protected function tearDown(): void
    {
        AP_Post::resetRegistry();
        AP_Taxonomy::resetRegistry();
    }

    public function testBuiltinTaxonomies(): void
    {
        $this->assertTrue(AP_Taxonomy::exists('category'));
        $this->assertTrue(AP_Taxonomy::exists('post_tag'));
        $this->assertTrue(AP_Taxonomy::isHierarchical('category'));
        $this->assertFalse(AP_Taxonomy::isHierarchical('post_tag'));
        $this->assertContains('category', AP_Taxonomy::getObjectTaxonomies('post'));
        $this->assertContains('post_tag', AP_Taxonomy::getObjectTaxonomies('post'));
        $this->assertNotContains('category', AP_Taxonomy::getObjectTaxonomies('page'));
    }

    public function testCustomTaxonomyRegistration(): void
    {
        AP_Taxonomy::register('genre', [
            'label' => 'Genres',
            'hierarchical' => true,
            'object_type' => ['book'],
        ]);
        $this->assertTrue(AP_Taxonomy::exists('genre'));
        $obj = AP_Taxonomy::getObject('genre');
        $this->assertNotNull($obj);
        $this->assertSame('Genres', $obj['label']);
        $this->assertTrue(AP_Taxonomy::isHierarchical('genre'));
        $this->assertSame(['genre'], AP_Taxonomy::getObjectTaxonomies('book'));
    }

    public function testInsertUpdateGetDeleteTerm(): void
    {
        $created = AP_Taxonomy::insertTerm('News', 'category', [
            'description' => 'Latest news',
        ], $this->db);
        $this->assertIsArray($created);
        $termId = (int) $created['term_id'];
        $this->assertGreaterThan(0, $termId);

        $term = AP_Taxonomy::getTerm($termId, 'category', $this->db);
        $this->assertNotNull($term);
        $this->assertSame('News', $term->name);
        $this->assertSame('news', $term->slug);
        $this->assertSame('Latest news', $term->description);

        $bySlug = AP_Taxonomy::getTermBySlug('news', 'category', $this->db);
        $this->assertNotNull($bySlug);
        $this->assertSame($termId, (int) $bySlug->term_id);

        $ok = AP_Taxonomy::updateTerm($termId, 'category', [
            'name' => 'Site News',
            'slug' => 'site-news',
            'description' => 'Updated',
        ], $this->db);
        $this->assertTrue($ok);
        $term = AP_Taxonomy::getTerm($termId, 'category', $this->db);
        $this->assertSame('Site News', $term->name);
        $this->assertSame('site-news', $term->slug);

        $this->assertTrue(AP_Taxonomy::deleteTerm($termId, 'category', $this->db));
        $this->assertNull(AP_Taxonomy::getTerm($termId, 'category', $this->db));
    }

    public function testHierarchicalParentAndCyclePrevention(): void
    {
        $parent = AP_Taxonomy::insertTerm('Parent', 'category', [], $this->db);
        $this->assertIsArray($parent);
        $parentId = (int) $parent['term_id'];

        $child = AP_Taxonomy::insertTerm('Child', 'category', [
            'parent' => $parentId,
        ], $this->db);
        $this->assertIsArray($child);
        $childId = (int) $child['term_id'];

        $term = AP_Taxonomy::getTerm($childId, 'category', $this->db);
        $this->assertSame($parentId, (int) $term->parent);
        $this->assertSame([$parentId], AP_Taxonomy::getAncestorIds($childId, 'category', $this->db));

        // Parent → child would cycle.
        $this->assertFalse(AP_Taxonomy::updateTerm($parentId, 'category', [
            'parent' => $childId,
        ], $this->db));
    }

    public function testDefaultCategoryCannotBeDeleted(): void
    {
        $defaultId = AP_Taxonomy::ensureDefaultCategory($this->db);
        $this->assertGreaterThan(0, $defaultId);
        $term = AP_Taxonomy::getTerm($defaultId, 'category', $this->db);
        $this->assertNotNull($term);
        $this->assertSame('uncategorized', $term->slug);
        $this->assertFalse(AP_Taxonomy::deleteTerm($defaultId, 'category', $this->db));
        $this->assertSame($defaultId, AP_Taxonomy::getDefaultCategoryId($this->db));
    }

    public function testObjectTermsAndCounts(): void
    {
        $postId = AP_Post::insert([
            'post_title' => 'Hello',
            'post_status' => 'publish',
            'post_type' => 'post',
            'post_author' => 1,
        ], $this->db);
        $this->assertGreaterThan(0, $postId);

        $cat = AP_Taxonomy::insertTerm('Tech', 'category', [], $this->db);
        $this->assertIsArray($cat);
        $catId = (int) $cat['term_id'];

        $set = AP_Taxonomy::setObjectTerms($postId, [$catId], 'category', false, $this->db);
        $this->assertSame([$catId], $set);

        $names = AP_Taxonomy::getObjectTerms($postId, 'category', ['fields' => 'names'], $this->db);
        $this->assertSame(['Tech'], $names);

        $term = AP_Taxonomy::getTerm($catId, 'category', $this->db);
        $this->assertSame(1, (int) $term->count);

        // Tags create on the fly from names.
        $tagIds = AP_Taxonomy::setObjectTerms($postId, ['php', 'sqlite'], 'post_tag', false, $this->db);
        $this->assertCount(2, $tagIds);
        $tagNames = AP_Taxonomy::getObjectTerms($postId, 'post_tag', ['fields' => 'names'], $this->db);
        $this->assertEqualsCanonicalizing(['php', 'sqlite'], $tagNames);

        // Empty category assignment falls back to default.
        AP_Taxonomy::setObjectTerms($postId, [], 'category', false, $this->db);
        $cats = AP_Taxonomy::getObjectTerms($postId, 'category', ['fields' => 'ids'], $this->db);
        $this->assertCount(1, $cats);
        $this->assertSame(AP_Taxonomy::getDefaultCategoryId($this->db), (int) $cats[0]);
    }

    public function testUniqueSlugsPerTaxonomy(): void
    {
        $a = AP_Taxonomy::insertTerm('Featured', 'category', ['slug' => 'featured'], $this->db);
        $b = AP_Taxonomy::insertTerm('Featured 2', 'category', ['slug' => 'featured'], $this->db);
        $this->assertIsArray($a);
        $this->assertIsArray($b);
        $termA = AP_Taxonomy::getTerm((int) $a['term_id'], 'category', $this->db);
        $termB = AP_Taxonomy::getTerm((int) $b['term_id'], 'category', $this->db);
        $this->assertSame('featured', $termA->slug);
        $this->assertSame('featured-2', $termB->slug);
    }

    public function testGetTermsAndTree(): void
    {
        $p = AP_Taxonomy::insertTerm('Root', 'category', [], $this->db);
        $parentId = (int) $p['term_id'];
        AP_Taxonomy::insertTerm('Leaf', 'category', ['parent' => $parentId], $this->db);

        $all = AP_Taxonomy::getTerms('category', ['hide_empty' => false], $this->db);
        $this->assertGreaterThanOrEqual(2, count($all));

        $tree = AP_Taxonomy::getTermTree('category', ['hide_empty' => false], 0, $this->db);
        $this->assertNotSame([], $tree);
        $foundRoot = false;
        foreach ($tree as $node) {
            if ((int) $node['term']->term_id === $parentId) {
                $foundRoot = true;
                $this->assertNotSame([], $node['children']);
            }
        }
        $this->assertTrue($foundRoot);
    }

    public function testQueryByCategoryAndTag(): void
    {
        $cat = AP_Taxonomy::insertTerm('Sports', 'category', [], $this->db);
        $catId = (int) $cat['term_id'];

        $p1 = AP_Post::insert([
            'post_title' => 'Match report',
            'post_status' => 'publish',
            'post_type' => 'post',
            'post_author' => 1,
        ], $this->db);
        $p2 = AP_Post::insert([
            'post_title' => 'Other post',
            'post_status' => 'publish',
            'post_type' => 'post',
            'post_author' => 1,
        ], $this->db);

        AP_Taxonomy::setObjectTerms($p1, [$catId], 'category', false, $this->db);
        AP_Taxonomy::setObjectTerms($p1, ['football'], 'post_tag', false, $this->db);
        AP_Taxonomy::ensureDefaultCategory($this->db);
        AP_Taxonomy::setObjectTerms($p2, [AP_Taxonomy::getDefaultCategoryId($this->db)], 'category', false, $this->db);

        $q = new AP_Query([
            'post_type' => 'post',
            'post_status' => 'publish',
            'cat' => $catId,
            'posts_per_page' => 10,
        ], $this->db);
        $this->assertTrue($q->is_category);
        $this->assertSame(1, $q->post_count);
        $this->assertSame($p1, $q->posts[0]->ID);

        $q2 = new AP_Query([
            'post_type' => 'post',
            'post_status' => 'publish',
            'tag' => 'football',
            'posts_per_page' => 10,
        ], $this->db);
        $this->assertTrue($q2->is_tag);
        $this->assertSame(1, $q2->post_count);
        $this->assertSame($p1, $q2->posts[0]->ID);

        $q3 = new AP_Query([
            'post_type' => 'post',
            'post_status' => 'publish',
            'category_name' => 'sports',
            'posts_per_page' => 10,
        ], $this->db);
        $this->assertSame(1, $q3->post_count);
    }

    public function testProceduralHelpers(): void
    {
        $this->assertTrue(ap_taxonomy_exists('category'));
        $created = ap_insert_term('Helpers', 'category', [], $this->db);
        $this->assertIsArray($created);
        $id = (int) $created['term_id'];
        $this->assertNotNull(ap_get_term($id, 'category', $this->db));

        $postId = ap_insert_post([
            'post_title' => 'Tagged',
            'post_status' => 'publish',
            'post_type' => 'post',
            'post_author' => 1,
        ], $this->db);
        ap_set_post_categories($postId, [$id], $this->db);
        ap_set_post_tags($postId, ['alpha', 'beta'], $this->db);
        $this->assertSame(['Helpers'], ap_get_post_categories($postId, ['fields' => 'names'], $this->db));
        $this->assertEqualsCanonicalizing(
            ['alpha', 'beta'],
            ap_get_post_tags($postId, ['fields' => 'names'], $this->db)
        );
    }

    public function testGetObjectsInTerm(): void
    {
        $cat = AP_Taxonomy::insertTerm('Shared', 'category', [], $this->db);
        $catId = (int) $cat['term_id'];
        $p1 = AP_Post::insert([
            'post_title' => 'A',
            'post_status' => 'publish',
            'post_type' => 'post',
        ], $this->db);
        $p2 = AP_Post::insert([
            'post_title' => 'B',
            'post_status' => 'publish',
            'post_type' => 'post',
        ], $this->db);
        AP_Taxonomy::setObjectTerms($p1, [$catId], 'category', false, $this->db);
        AP_Taxonomy::setObjectTerms($p2, [$catId], 'category', false, $this->db);

        $ids = AP_Taxonomy::getObjectsInTerm([$catId], ['taxonomy' => 'category'], $this->db);
        $this->assertEqualsCanonicalizing([$p1, $p2], $ids);
    }
}
