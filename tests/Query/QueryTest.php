<?php

/**
 * Tests for AP_Query — WP_Query-inspired content query.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Query;

use AP_DB;
use AP_Migrator;
use AP_Post;
use AP_Query;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_Query::class)]
final class QueryTest extends TestCase
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
        require_once $this->root . '/ap-includes/class-ap-query.php';
        require_once $this->root . '/ap-includes/functions.php';

        AP_Post::resetRegistry();
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
    }

    protected function tearDown(): void
    {
        AP_Post::resetRegistry();
        unset($GLOBALS['ap_query'], $GLOBALS['ap_post']);
    }

    public function testBasicPostTypeAndStatus(): void
    {
        $this->insertPost('Alpha', 'publish');
        $this->insertPost('Beta', 'draft');
        $this->insertPost('Gamma', 'publish');

        $q = new AP_Query([
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => 10,
            'orderby' => 'title',
            'order' => 'ASC',
        ], $this->db);

        $this->assertSame(2, $q->post_count);
        $this->assertSame(2, $q->found_posts);
        $this->assertSame('Alpha', $q->posts[0]->post_title);
        $this->assertSame('Gamma', $q->posts[1]->post_title);
        $this->assertTrue($q->is_home);
    }

    public function testPagination(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->insertPost("Post {$i}", 'publish');
        }

        $q = new AP_Query([
            'post_type' => 'post',
            'posts_per_page' => 2,
            'paged' => 2,
            'orderby' => 'ID',
            'order' => 'ASC',
        ], $this->db);

        $this->assertSame(2, $q->post_count);
        $this->assertSame(5, $q->found_posts);
        $this->assertSame(3, $q->max_num_pages);
        $this->assertSame('Post 3', $q->posts[0]->post_title);
        $this->assertSame('Post 4', $q->posts[1]->post_title);
    }

    public function testLoopApi(): void
    {
        $this->insertPost('One', 'publish');
        $this->insertPost('Two', 'publish');

        $q = new AP_Query([
            'post_type' => 'post',
            'orderby' => 'ID',
            'order' => 'ASC',
            'posts_per_page' => 10,
        ], $this->db);

        $titles = [];
        while ($q->havePosts()) {
            $q->thePost();
            $this->assertTrue($q->in_the_loop);
            $this->assertInstanceOf(AP_Post::class, $q->post);
            $titles[] = $q->post->post_title;
        }

        $this->assertSame(['One', 'Two'], $titles);
        // End-of-loop rewinds (WP-style) so a second pass can re-iterate.
        $this->assertSame(-1, $q->current_post);
        $again = [];
        while ($q->havePosts()) {
            $q->thePost();
            $again[] = $q->post->post_title;
        }
        $this->assertSame($titles, $again);
    }

    public function testSingularByIdAndSlug(): void
    {
        $id = $this->insertPost('Hello World', 'publish', ['post_name' => 'hello-world']);

        $byId = new AP_Query(['p' => $id], $this->db);
        $this->assertSame(1, $byId->post_count);
        $this->assertTrue($byId->is_single);
        $this->assertTrue($byId->is_singular);
        $this->assertSame('Hello World', $byId->posts[0]->post_title);

        $byName = new AP_Query(['name' => 'hello-world', 'post_type' => 'post'], $this->db);
        $this->assertSame(1, $byName->post_count);
        $this->assertSame($id, $byName->posts[0]->ID);
    }

    public function testPageByIdAndPagenamePath(): void
    {
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
        $this->assertGreaterThan(0, $parent);
        $this->assertGreaterThan(0, $child);

        $q = new AP_Query(['pagename' => 'parent/child'], $this->db);
        $this->assertSame(1, $q->post_count);
        $this->assertTrue($q->is_page);
        $this->assertTrue($q->is_singular);
        $this->assertSame($child, $q->posts[0]->ID);

        $missing = new AP_Query(['pagename' => 'no/such/page'], $this->db);
        $this->assertSame(0, $missing->post_count);
        $this->assertTrue($missing->is_404);
    }

    public function testSearch(): void
    {
        $this->insertPost('Apple Pie Recipe', 'publish', ['post_content' => 'flour sugar']);
        $this->insertPost('Orange Juice', 'publish', ['post_content' => 'citrus']);
        $this->insertPost('Banana Bread', 'publish', ['post_content' => 'apple cinnamon']);

        $q = new AP_Query([
            's' => 'apple',
            'post_type' => 'post',
            'posts_per_page' => 10,
        ], $this->db);

        $this->assertTrue($q->is_search);
        $this->assertSame(2, $q->post_count);
        $titles = array_map(static fn (AP_Post $p): string => $p->post_title, $q->posts);
        $this->assertContains('Apple Pie Recipe', $titles);
        $this->assertContains('Banana Bread', $titles);
    }

    public function testAuthorAndExclude(): void
    {
        $a = $this->insertPost('By Author 1', 'publish', ['post_author' => 1]);
        $this->insertPost('By Author 2', 'publish', ['post_author' => 2]);
        $this->insertPost('Also Author 1', 'publish', ['post_author' => 1]);

        $q = new AP_Query([
            'author' => 1,
            'post__not_in' => [$a],
            'posts_per_page' => 10,
            'orderby' => 'ID',
            'order' => 'ASC',
        ], $this->db);

        $this->assertTrue($q->is_author);
        $this->assertSame(1, $q->post_count);
        $this->assertSame('Also Author 1', $q->posts[0]->post_title);
    }

    public function testPostInOrder(): void
    {
        $id1 = $this->insertPost('First', 'publish');
        $id2 = $this->insertPost('Second', 'publish');
        $id3 = $this->insertPost('Third', 'publish');

        $q = new AP_Query([
            'post__in' => [$id3, $id1, $id2],
            'orderby' => 'post__in',
            'posts_per_page' => 10,
        ], $this->db);

        $this->assertSame(3, $q->post_count);
        $this->assertSame($id3, $q->posts[0]->ID);
        $this->assertSame($id1, $q->posts[1]->ID);
        $this->assertSame($id2, $q->posts[2]->ID);
    }

    public function testFieldsIdsAndNopaging(): void
    {
        $ids = [];
        for ($i = 0; $i < 4; $i++) {
            $ids[] = $this->insertPost("N{$i}", 'publish');
        }

        $q = new AP_Query([
            'post_type' => 'post',
            'fields' => 'ids',
            'nopaging' => true,
            'orderby' => 'ID',
            'order' => 'ASC',
        ], $this->db);

        $this->assertSame($ids, $q->posts);
        $this->assertSame(4, $q->found_posts);
    }

    public function testMetaKeyValue(): void
    {
        $sticky = $this->insertPost('Sticky', 'publish');
        $this->insertPost('Normal', 'publish');
        AP_Post::updateMeta($sticky, AP_Post::STICKY_META, '1', $this->db);

        $q = new AP_Query([
            'post_type' => 'post',
            'meta_key' => AP_Post::STICKY_META,
            'meta_value' => '1',
            'posts_per_page' => 10,
        ], $this->db);

        $this->assertSame(1, $q->post_count);
        $this->assertSame($sticky, $q->posts[0]->ID);
    }

    public function testDateQuery(): void
    {
        $this->insertPost('Jan', 'publish', ['post_date' => '2024-01-15 12:00:00']);
        $this->insertPost('Mar', 'publish', ['post_date' => '2024-03-10 08:00:00']);
        $this->insertPost('Next Year', 'publish', ['post_date' => '2025-01-01 00:00:00']);

        $year = new AP_Query([
            'year' => 2024,
            'posts_per_page' => 10,
            'orderby' => 'date',
            'order' => 'ASC',
        ], $this->db);
        $this->assertTrue($year->is_date);
        $this->assertTrue($year->is_year);
        $this->assertSame(2, $year->post_count);

        $month = new AP_Query([
            'year' => 2024,
            'monthnum' => 3,
            'posts_per_page' => 10,
        ], $this->db);
        $this->assertTrue($month->is_month);
        $this->assertSame(1, $month->post_count);
        $this->assertSame('Mar', $month->posts[0]->post_title);
    }

    public function testAnyStatusAndMultipleTypes(): void
    {
        $this->insertPost('Pub', 'publish');
        $this->insertPost('Dr', 'draft');
        AP_Post::insert([
            'post_title' => 'A Page',
            'post_type' => 'page',
            'post_status' => 'publish',
        ], $this->db);

        $q = new AP_Query([
            'post_type' => ['post', 'page'],
            'post_status' => 'any',
            'posts_per_page' => 20,
            'orderby' => 'ID',
            'order' => 'ASC',
        ], $this->db);

        $this->assertSame(3, $q->post_count);
    }

    public function testPostParentFilter(): void
    {
        $parent = AP_Post::insert([
            'post_title' => 'Root',
            'post_type' => 'page',
            'post_status' => 'publish',
        ], $this->db);
        AP_Post::insert([
            'post_title' => 'Kid',
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_parent' => $parent,
        ], $this->db);
        AP_Post::insert([
            'post_title' => 'Other Root',
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_parent' => 0,
        ], $this->db);

        $q = new AP_Query([
            'post_type' => 'page',
            'post_parent' => $parent,
            'posts_per_page' => 10,
        ], $this->db);

        $this->assertSame(1, $q->post_count);
        $this->assertSame('Kid', $q->posts[0]->post_title);
    }

    public function testProceduralHelpers(): void
    {
        $this->insertPost('Helper Post', 'publish');

        $q = ap_query([
            'post_type' => 'post',
            'posts_per_page' => 5,
        ], $this->db);
        ap_set_query($q);

        $this->assertTrue(ap_have_posts());
        ap_the_post();
        $post = ap_get_queried_post();
        $this->assertInstanceOf(AP_Post::class, $post);
        $this->assertSame('Helper Post', $post->post_title);
        $this->assertSame($post, $GLOBALS['ap_post']);
    }

    public function testParseQueryString(): void
    {
        $this->insertPost('QS', 'publish');
        $q = new AP_Query('post_type=post&posts_per_page=5', $this->db);
        $this->assertSame(1, $q->post_count);
        $this->assertSame(5, (int) $q->get('posts_per_page'));
    }

    public function testSingular404(): void
    {
        $q = new AP_Query(['p' => 99999], $this->db);
        $this->assertSame(0, $q->post_count);
        $this->assertTrue($q->is_singular);
        $this->assertTrue($q->is_404);
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function insertPost(string $title, string $status, array $extra = []): int
    {
        $data = array_merge([
            'post_title' => $title,
            'post_type' => 'post',
            'post_status' => $status,
        ], $extra);

        $id = AP_Post::insert($data, $this->db);
        $this->assertGreaterThan(0, $id, "Failed to insert post {$title}");

        // Allow overriding post_date after insert (insert always sets "now").
        if (isset($extra['post_date'])) {
            $this->db->update('posts', [
                'post_date' => $extra['post_date'],
                'post_date_gmt' => $extra['post_date'],
            ], ['ID' => $id]);
        }

        return $id;
    }
}
