<?php

/**
 * Tests for AP_Post — statuses, types, CRUD, hierarchical pages.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Post;

use AP_DB;
use AP_Migrator;
use AP_Post;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_Post::class)]
final class PostModelTest extends TestCase
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
        require_once $this->root . '/ap-includes/functions.php';

        AP_Post::resetRegistry();

        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');

        $migrator = new AP_Migrator($this->db, AP_Migrator::defaultMigrationsPath());
        $migrator->migrate();
    }

    protected function tearDown(): void
    {
        AP_Post::resetRegistry();
    }

    public function testBuiltinStatusesAndTypes(): void
    {
        AP_Post::ensureBuiltins();

        foreach (['publish', 'draft', 'pending', 'private', 'future', 'trash', 'auto-draft', 'inherit'] as $status) {
            $this->assertTrue(AP_Post::statusExists($status), "Missing status {$status}");
        }
        $this->assertTrue(AP_Post::isPublicStatus('publish'));
        $this->assertFalse(AP_Post::isPublicStatus('draft'));

        foreach (['post', 'page', 'revision', 'attachment'] as $type) {
            $this->assertTrue(AP_Post::typeExists($type), "Missing type {$type}");
        }
        $this->assertTrue(AP_Post::typeIsHierarchical('page'));
        $this->assertFalse(AP_Post::typeIsHierarchical('post'));
        $this->assertTrue(AP_Post::typeSupports('post', 'title'));
        $this->assertTrue(AP_Post::typeSupports('page', 'page-attributes'));
        $this->assertFalse(AP_Post::typeSupports('revision', 'editor'));
    }

    public function testCustomStatusAndTypeRegistration(): void
    {
        AP_Post::registerStatus('archived', [
            'label' => 'Archived',
            'public' => false,
            'protected' => true,
        ]);
        $this->assertTrue(AP_Post::statusExists('archived'));
        $obj = AP_Post::getStatusObject('archived');
        $this->assertNotNull($obj);
        $this->assertSame('Archived', $obj['label']);

        AP_Post::registerType('book', [
            'label' => 'Books',
            'public' => true,
            'hierarchical' => false,
            'supports' => ['title', 'editor', 'thumbnail'],
            'has_archive' => true,
        ]);
        $this->assertTrue(AP_Post::typeExists('book'));
        $this->assertTrue(AP_Post::typeSupports('book', 'thumbnail'));
        $this->assertFalse(AP_Post::typeIsHierarchical('book'));
    }

    public function testInsertAndGetPost(): void
    {
        $id = AP_Post::insert([
            'post_title' => 'Hello Agora',
            'post_content' => 'Body text.',
            'post_status' => 'publish',
            'post_type' => 'post',
            'post_author' => 1,
        ], $this->db);

        $this->assertGreaterThan(0, $id);

        $post = AP_Post::get($id, $this->db);
        $this->assertNotNull($post);
        $this->assertSame('Hello Agora', $post->post_title);
        $this->assertSame('publish', $post->post_status);
        $this->assertSame('post', $post->post_type);
        $this->assertSame('hello-agora', $post->post_name);
        $this->assertSame(1, $post->post_author);
        $this->assertTrue($post->isPubliclyViewable());
        $this->assertStringContainsString((string) $id, $post->guid !== '' ? $post->guid : '?p=' . $id);

        // Reload for guid (updated after insert).
        $post = AP_Post::get($id, $this->db);
        $this->assertNotNull($post);
        $this->assertSame('?p=' . $id, $post->guid);

        $bySlug = AP_Post::getBySlug('hello-agora', 'post', $this->db);
        $this->assertNotNull($bySlug);
        $this->assertSame($id, $bySlug->ID);
    }

    public function testRejectsUnknownTypeAndStatusWhenStrict(): void
    {
        $this->assertSame(0, AP_Post::insert([
            'post_title' => 'Nope',
            'post_type' => 'not-a-real-type',
            'post_status' => 'publish',
        ], $this->db));

        $this->assertSame(0, AP_Post::insert([
            'post_title' => 'Nope',
            'post_type' => 'post',
            'post_status' => 'not-a-status',
        ], $this->db));
    }

    public function testUniqueSlugAmongSameType(): void
    {
        $a = AP_Post::insert([
            'post_title' => 'Same Title',
            'post_status' => 'publish',
            'post_type' => 'post',
        ], $this->db);
        $b = AP_Post::insert([
            'post_title' => 'Same Title',
            'post_status' => 'publish',
            'post_type' => 'post',
        ], $this->db);

        $this->assertGreaterThan(0, $a);
        $this->assertGreaterThan(0, $b);

        $postA = AP_Post::get($a, $this->db);
        $postB = AP_Post::get($b, $this->db);
        $this->assertNotNull($postA);
        $this->assertNotNull($postB);
        $this->assertSame('same-title', $postA->post_name);
        $this->assertSame('same-title-2', $postB->post_name);
    }

    public function testPasswordProtectedAndSticky(): void
    {
        $id = AP_Post::insert([
            'post_title' => 'Secret',
            'post_status' => 'publish',
            'post_password' => 's3cret',
            'sticky' => true,
        ], $this->db);

        $post = AP_Post::get($id, $this->db);
        $this->assertNotNull($post);
        $this->assertTrue($post->isPasswordProtected());
        $this->assertTrue($post->isSticky($this->db));
        $this->assertTrue($post->isPubliclyViewable());

        AP_Post::setSticky($id, false, $this->db);
        $this->assertFalse(AP_Post::get($id, $this->db)?->isSticky($this->db));
    }

    public function testUpdateAndFutureScheduling(): void
    {
        $id = AP_Post::insert([
            'post_title' => 'Drafty',
            'post_status' => 'draft',
        ], $this->db);
        $this->assertGreaterThan(0, $id);

        $future = date('Y-m-d H:i:s', time() + 86400);
        $ok = AP_Post::update($id, [
            'post_title' => 'Scheduled Post',
            'post_status' => 'publish',
            'post_date' => $future,
        ], $this->db);
        $this->assertTrue($ok);

        $post = AP_Post::get($id, $this->db);
        $this->assertNotNull($post);
        $this->assertSame('Scheduled Post', $post->post_title);
        $this->assertSame('future', $post->post_status);
        $this->assertSame('scheduled-post', $post->post_name);
    }

    public function testTrashUntrashAndForceDelete(): void
    {
        $id = AP_Post::insert([
            'post_title' => 'Trash me',
            'post_status' => 'publish',
        ], $this->db);

        $this->assertTrue(AP_Post::trash($id, $this->db));
        $post = AP_Post::get($id, $this->db);
        $this->assertNotNull($post);
        $this->assertSame('trash', $post->post_status);
        $this->assertSame(
            'publish',
            AP_Post::getMeta($id, AP_Post::TRASH_STATUS_META, true, $this->db)
        );

        $this->assertTrue(AP_Post::untrash($id, $this->db));
        $post = AP_Post::get($id, $this->db);
        $this->assertNotNull($post);
        $this->assertSame('publish', $post->post_status);
        $this->assertNull(AP_Post::getMeta($id, AP_Post::TRASH_STATUS_META, true, $this->db));

        $this->assertTrue(AP_Post::delete($id, false, $this->db));
        $this->assertSame('trash', AP_Post::get($id, $this->db)?->post_status);

        $this->assertTrue(AP_Post::delete($id, true, $this->db));
        $this->assertNull(AP_Post::get($id, $this->db));
    }

    public function testHierarchicalPagesParentPathAndTree(): void
    {
        $rootId = AP_Post::insert([
            'post_title' => 'About',
            'post_type' => 'page',
            'post_status' => 'publish',
            'menu_order' => 1,
            'page_template' => 'templates/about.php',
        ], $this->db);
        $this->assertGreaterThan(0, $rootId);

        $childId = AP_Post::insert([
            'post_title' => 'Team',
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_parent' => $rootId,
            'menu_order' => 1,
        ], $this->db);
        $this->assertGreaterThan(0, $childId);

        $grandId = AP_Post::insert([
            'post_title' => 'Alice',
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_parent' => $childId,
            'menu_order' => 1,
        ], $this->db);
        $this->assertGreaterThan(0, $grandId);

        $child = AP_Post::get($childId, $this->db);
        $this->assertNotNull($child);
        $this->assertSame($rootId, $child->post_parent);
        $this->assertTrue($child->isHierarchical());

        $parent = $child->getParent($this->db);
        $this->assertNotNull($parent);
        $this->assertSame($rootId, $parent->ID);

        $this->assertSame([$rootId], AP_Post::getAncestorIds($childId, $this->db));
        $this->assertSame([$childId, $rootId], AP_Post::getAncestorIds($grandId, $this->db));
        $this->assertSame('about/team/alice', AP_Post::getPagePath($grandId, $this->db));

        $children = AP_Post::getChildren($rootId, [], $this->db);
        $this->assertCount(1, $children);
        $this->assertSame($childId, $children[0]->ID);

        $tree = AP_Post::getTree(['post_type' => 'page', 'post_status' => 'publish'], $this->db);
        $this->assertCount(1, $tree);
        $this->assertSame($rootId, $tree[0]['post']->ID);
        $this->assertCount(1, $tree[0]['children']);
        $this->assertSame($childId, $tree[0]['children'][0]['post']->ID);
        $this->assertSame($grandId, $tree[0]['children'][0]['children'][0]['post']->ID);

        $this->assertSame('templates/about.php', AP_Post::getPageTemplate($rootId, $this->db));
        $this->assertSame('default', AP_Post::getPageTemplate($childId, $this->db));
    }

    public function testHierarchyCyclePrevention(): void
    {
        $a = AP_Post::insert([
            'post_title' => 'A',
            'post_type' => 'page',
            'post_status' => 'publish',
        ], $this->db);
        $b = AP_Post::insert([
            'post_title' => 'B',
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_parent' => $a,
        ], $this->db);
        $c = AP_Post::insert([
            'post_title' => 'C',
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_parent' => $b,
        ], $this->db);

        $this->assertTrue(AP_Post::wouldCreateCycle($a, $c, $this->db));
        $this->assertTrue(AP_Post::wouldCreateCycle($a, $a, $this->db));
        $this->assertFalse(AP_Post::wouldCreateCycle($c, $a, $this->db));

        // Setting A's parent to C (descendant) must fail.
        $this->assertFalse(AP_Post::update($a, ['post_parent' => $c], $this->db));
        $this->assertSame(0, AP_Post::get($a, $this->db)?->post_parent);

        // Self-parent rejected.
        $this->assertFalse(AP_Post::update($a, ['post_parent' => $a], $this->db));
    }

    public function testPageParentMustBeSameType(): void
    {
        $postId = AP_Post::insert([
            'post_title' => 'Blog Post',
            'post_type' => 'post',
            'post_status' => 'publish',
        ], $this->db);

        $pageId = AP_Post::insert([
            'post_title' => 'Child of post',
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_parent' => $postId,
        ], $this->db);

        $this->assertSame(0, $pageId);
    }

    public function testNonHierarchicalIgnoresParent(): void
    {
        $parent = AP_Post::insert([
            'post_title' => 'Parent Post',
            'post_type' => 'post',
            'post_status' => 'publish',
        ], $this->db);

        $child = AP_Post::insert([
            'post_title' => 'Child Post',
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_parent' => $parent,
        ], $this->db);

        $post = AP_Post::get($child, $this->db);
        $this->assertNotNull($post);
        $this->assertSame(0, $post->post_parent);
    }

    public function testHierarchicalSlugUniquePerParent(): void
    {
        $root1 = AP_Post::insert([
            'post_title' => 'Section',
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_name' => 'section-a',
        ], $this->db);
        $root2 = AP_Post::insert([
            'post_title' => 'Other',
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_name' => 'section-b',
        ], $this->db);

        $c1 = AP_Post::insert([
            'post_title' => 'Intro',
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_parent' => $root1,
        ], $this->db);
        $c2 = AP_Post::insert([
            'post_title' => 'Intro',
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_parent' => $root2,
        ], $this->db);

        $this->assertSame('intro', AP_Post::get($c1, $this->db)?->post_name);
        $this->assertSame('intro', AP_Post::get($c2, $this->db)?->post_name);

        $c1b = AP_Post::insert([
            'post_title' => 'Intro',
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_parent' => $root1,
        ], $this->db);
        $this->assertSame('intro-2', AP_Post::get($c1b, $this->db)?->post_name);
    }

    public function testForceDeleteReparentsChildren(): void
    {
        $root = AP_Post::insert([
            'post_title' => 'Root',
            'post_type' => 'page',
            'post_status' => 'publish',
        ], $this->db);
        $mid = AP_Post::insert([
            'post_title' => 'Mid',
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_parent' => $root,
        ], $this->db);
        $leaf = AP_Post::insert([
            'post_title' => 'Leaf',
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_parent' => $mid,
        ], $this->db);

        $this->assertTrue(AP_Post::delete($mid, true, $this->db));
        $this->assertNull(AP_Post::get($mid, $this->db));
        $this->assertSame($root, AP_Post::get($leaf, $this->db)?->post_parent);
    }

    public function testMetaHelpers(): void
    {
        $id = AP_Post::insert([
            'post_title' => 'Meta Post',
            'post_status' => 'publish',
            'meta' => [
                'views' => '10',
                'color' => 'blue',
            ],
        ], $this->db);

        $this->assertSame('10', AP_Post::getMeta($id, 'views', true, $this->db));
        $this->assertTrue(AP_Post::updateMeta($id, 'views', '11', $this->db));
        $this->assertSame('11', AP_Post::getMeta($id, 'views', true, $this->db));
        $this->assertTrue(AP_Post::deleteMeta($id, 'color', $this->db));
        $this->assertNull(AP_Post::getMeta($id, 'color', true, $this->db));
    }

    public function testQueryFilters(): void
    {
        AP_Post::insert([
            'post_title' => 'Pub',
            'post_status' => 'publish',
            'post_author' => 1,
        ], $this->db);
        AP_Post::insert([
            'post_title' => 'Draft',
            'post_status' => 'draft',
            'post_author' => 1,
        ], $this->db);
        AP_Post::insert([
            'post_title' => 'Other Author',
            'post_status' => 'publish',
            'post_author' => 2,
        ], $this->db);

        $published = AP_Post::query([
            'post_status' => 'publish',
            'limit' => 0,
        ], $this->db);
        $this->assertCount(2, $published);

        $byAuthor = AP_Post::query([
            'post_status' => 'publish',
            'post_author' => 1,
            'limit' => 0,
        ], $this->db);
        $this->assertCount(1, $byAuthor);
        $this->assertSame('Pub', $byAuthor[0]->post_title);
    }

    public function testSanitizeSlug(): void
    {
        $this->assertSame('hello-world', AP_Post::sanitizeSlug('Hello World!'));
        $this->assertSame('foo-bar', AP_Post::sanitizeSlug('  Foo   Bar  '));
        $this->assertSame('', AP_Post::sanitizeSlug('@@@'));
    }

    public function testProceduralHelpers(): void
    {
        $this->assertTrue(function_exists('ap_register_post_type'));
        $this->assertTrue(function_exists('ap_insert_post'));
        $this->assertTrue(function_exists('ap_get_post'));
        $this->assertTrue(function_exists('ap_get_page_path'));
        $this->assertTrue(function_exists('ap_get_page_tree'));

        $this->assertTrue(ap_post_type_exists('page'));
        $this->assertTrue(ap_is_post_type_hierarchical('page'));
        $this->assertTrue(ap_post_status_exists('publish'));

        $id = ap_insert_post([
            'post_title' => 'Via Helpers',
            'post_type' => 'page',
            'post_status' => 'publish',
        ], $this->db);
        $this->assertGreaterThan(0, $id);

        $post = ap_get_post($id, $this->db);
        $this->assertNotNull($post);
        $this->assertSame('via-helpers', $post->post_name);
        $this->assertSame('via-helpers', ap_get_page_path($id, $this->db));

        ap_register_post_type('event', [
            'label' => 'Events',
            'public' => true,
            'hierarchical' => false,
        ]);
        $this->assertTrue(ap_post_type_exists('event'));

        $this->assertSame('hello-world', ap_sanitize_title('Hello World'));
    }

    public function testPageCommentStatusDefaultsClosed(): void
    {
        $pageId = AP_Post::insert([
            'post_title' => 'Quiet Page',
            'post_type' => 'page',
            'post_status' => 'publish',
        ], $this->db);
        $postId = AP_Post::insert([
            'post_title' => 'Chatty Post',
            'post_type' => 'post',
            'post_status' => 'publish',
        ], $this->db);

        $this->assertSame('closed', AP_Post::get($pageId, $this->db)?->comment_status);
        $this->assertSame('open', AP_Post::get($postId, $this->db)?->comment_status);
    }

    public function testUpdateCreatesRevisionWhenContentChanges(): void
    {
        $id = AP_Post::insert([
            'post_title' => 'Original Title',
            'post_content' => 'Original body',
            'post_status' => 'publish',
            'post_type' => 'post',
            'post_author' => 1,
        ], $this->db);
        $this->assertGreaterThan(0, $id);
        $this->assertSame(0, AP_Post::countRevisions($id, false, $this->db));

        $this->assertTrue(AP_Post::update($id, [
            'post_title' => 'Updated Title',
            'post_content' => 'Updated body',
        ], $this->db));

        $this->assertSame(1, AP_Post::countRevisions($id, false, $this->db));
        $revisions = AP_Post::getRevisions($id, [], $this->db);
        $this->assertCount(1, $revisions);
        $this->assertTrue(AP_Post::isRevision($revisions[0]));
        $this->assertFalse(AP_Post::isAutosave($revisions[0]));
        $this->assertSame('Original Title', $revisions[0]->post_title);
        $this->assertSame('Original body', $revisions[0]->post_content);
        $this->assertSame('inherit', $revisions[0]->post_status);
        $this->assertSame($id, $revisions[0]->post_parent);

        $post = AP_Post::get($id, $this->db);
        $this->assertNotNull($post);
        $this->assertSame('Updated Title', $post->post_title);
        $this->assertSame('Updated body', $post->post_content);
    }

    public function testUpdateSkipsRevisionWhenUnchangedOrDisabled(): void
    {
        $id = AP_Post::insert([
            'post_title' => 'Stable',
            'post_content' => 'Body',
            'post_status' => 'publish',
        ], $this->db);

        AP_Post::update($id, [
            'post_title' => 'Stable',
            'post_content' => 'Body',
            'comment_status' => 'closed',
        ], $this->db);
        $this->assertSame(0, AP_Post::countRevisions($id, false, $this->db));

        AP_Post::update($id, [
            'post_title' => 'Changed',
        ], $this->db, ['create_revision' => false]);
        $this->assertSame(0, AP_Post::countRevisions($id, false, $this->db));
    }

    public function testAutosaveCreateUpdateAndRestore(): void
    {
        $id = AP_Post::insert([
            'post_title' => 'Live',
            'post_content' => 'Live content',
            'post_status' => 'publish',
            'post_author' => 3,
        ], $this->db);

        $autosaveId = AP_Post::autosave($id, [
            'post_title' => 'Drafting',
            'post_content' => 'Work in progress',
        ], 3, $this->db);
        $this->assertGreaterThan(0, $autosaveId);

        $autosave = AP_Post::getAutosave($id, 3, $this->db);
        $this->assertNotNull($autosave);
        $this->assertSame($autosaveId, $autosave->ID);
        $this->assertTrue(AP_Post::isAutosave($autosave));
        $this->assertSame('Drafting', $autosave->post_title);
        // Parent unchanged.
        $this->assertSame('Live', AP_Post::get($id, $this->db)?->post_title);
        // Autosaves are excluded from default revision counts.
        $this->assertSame(0, AP_Post::countRevisions($id, false, $this->db));
        $this->assertSame(1, AP_Post::countRevisions($id, true, $this->db));

        // Second autosave updates the same row.
        $again = AP_Post::autosave($id, [
            'post_title' => 'Drafting 2',
            'post_content' => 'Still drafting',
        ], 3, $this->db);
        $this->assertSame($autosaveId, $again);
        $this->assertSame('Drafting 2', AP_Post::getAutosave($id, 3, $this->db)?->post_title);

        // Restore autosave onto parent (also creates a revision of "Live").
        $this->assertTrue(AP_Post::restoreRevision($autosaveId, $this->db));
        $restored = AP_Post::get($id, $this->db);
        $this->assertNotNull($restored);
        $this->assertSame('Drafting 2', $restored->post_title);
        $this->assertSame('Still drafting', $restored->post_content);
        $this->assertGreaterThanOrEqual(1, AP_Post::countRevisions($id, false, $this->db));
    }

    public function testRestoreRevisionAndPrune(): void
    {
        $id = AP_Post::insert([
            'post_title' => 'V1',
            'post_content' => 'C1',
            'post_status' => 'publish',
        ], $this->db);

        AP_Post::update($id, ['post_title' => 'V2', 'post_content' => 'C2'], $this->db);
        AP_Post::update($id, ['post_title' => 'V3', 'post_content' => 'C3'], $this->db);
        AP_Post::update($id, ['post_title' => 'V4', 'post_content' => 'C4'], $this->db);
        $this->assertSame(3, AP_Post::countRevisions($id, false, $this->db));

        $revisions = AP_Post::getRevisions($id, [], $this->db);
        // Newest revision snapshot is V3 (state before last update to V4).
        $this->assertSame('V3', $revisions[0]->post_title);

        $this->assertTrue(AP_Post::restoreRevision($revisions[0]->ID, $this->db));
        $this->assertSame('V3', AP_Post::get($id, $this->db)?->post_title);

        $deleted = AP_Post::pruneRevisions($id, 2, $this->db);
        $this->assertGreaterThan(0, $deleted);
        $this->assertLessThanOrEqual(2, AP_Post::countRevisions($id, false, $this->db));
    }

    public function testForceDeleteRemovesRevisions(): void
    {
        $id = AP_Post::insert([
            'post_title' => 'Gone',
            'post_content' => 'Body',
            'post_status' => 'publish',
        ], $this->db);
        AP_Post::update($id, ['post_content' => 'Changed'], $this->db);
        AP_Post::autosave($id, ['post_content' => 'Autosave'], 1, $this->db);
        $this->assertGreaterThan(0, AP_Post::countRevisions($id, true, $this->db));

        $this->assertTrue(AP_Post::delete($id, true, $this->db));
        $this->assertNull(AP_Post::get($id, $this->db));
        $this->assertSame(0, AP_Post::countRevisions($id, true, $this->db));

        // No orphan revision rows with that parent.
        $orphans = (int) $this->db->getVar(
            'SELECT COUNT(*) FROM ' . $this->db->quoteIdentifier($this->db->table('posts'))
            . ' WHERE post_parent = ? AND post_type = ?',
            [$id, 'revision']
        );
        $this->assertSame(0, $orphans);
    }

    public function testManualSaveRevisionAndDelete(): void
    {
        $id = AP_Post::insert([
            'post_title' => 'Snap',
            'post_content' => 'Shot',
            'post_status' => 'draft',
        ], $this->db);

        $revId = AP_Post::saveRevision($id, $this->db, ['author' => 9]);
        $this->assertGreaterThan(0, $revId);
        $rev = AP_Post::get($revId, $this->db);
        $this->assertNotNull($rev);
        $this->assertSame(9, $rev->post_author);
        $this->assertSame('Snap', $rev->post_title);
        $this->assertSame($id, AP_Post::getRevisionParent($revId, $this->db)?->ID);

        $this->assertTrue(AP_Post::deleteRevision($revId, $this->db));
        $this->assertNull(AP_Post::get($revId, $this->db));
    }

    public function testPageSupportsRevisionsAndHelpers(): void
    {
        $this->assertTrue(AP_Post::typeSupportsRevisions('page'));
        $this->assertTrue(AP_Post::typeSupportsRevisions('post'));
        $this->assertFalse(AP_Post::typeSupportsRevisions('revision'));

        $id = AP_Post::insert([
            'post_title' => 'Page One',
            'post_content' => 'A',
            'post_type' => 'page',
            'post_status' => 'publish',
        ], $this->db);
        AP_Post::update($id, ['post_content' => 'B'], $this->db);
        $this->assertSame(1, ap_count_post_revisions($id, false, $this->db));
        $this->assertTrue(ap_post_type_supports_revisions('page'));
    }
}
