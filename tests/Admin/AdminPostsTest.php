<?php

/**
 * Tests for admin posts/pages list table and edit save logic.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Admin;

use AP_Admin;
use AP_Admin_Post_Edit;
use AP_DB;
use AP_Migrator;
use AP_Nonce;
use AP_Post;
use AP_Posts_List_Table;
use AP_Taxonomy;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_Posts_List_Table::class)]
#[CoversClass(AP_Admin_Post_Edit::class)]
#[CoversClass(AP_Nonce::class)]
final class AdminPostsTest extends TestCase
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
        require_once $this->root . '/ap-includes/class-ap-user.php';
        require_once $this->root . '/ap-includes/class-ap-session.php';
        require_once $this->root . '/ap-includes/class-ap-nonce.php';
        require_once $this->root . '/ap-includes/functions.php';
        require_once $this->root . '/ap-admin/includes/class-ap-admin.php';
        require_once $this->root . '/ap-admin/includes/class-ap-posts-list-table.php';
        require_once $this->root . '/ap-admin/includes/class-ap-admin-post-edit.php';
        require_once $this->root . '/ap-admin/includes/class-ap-admin-terms.php';

        if (!defined('AP_NONCE_KEY')) {
            define('AP_NONCE_KEY', 'test-nonce-key-' . str_repeat('n', 32));
        }
        if (!defined('AP_NONCE_SALT')) {
            define('AP_NONCE_SALT', 'test-nonce-salt-' . str_repeat('s', 32));
        }
        if (!defined('AP_LOGGED_IN_KEY')) {
            define('AP_LOGGED_IN_KEY', 'test-logged-in-key-' . str_repeat('a', 32));
        }
        if (!defined('AP_LOGGED_IN_SALT')) {
            define('AP_LOGGED_IN_SALT', 'test-logged-in-salt-' . str_repeat('b', 32));
        }

        AP_Post::resetRegistry();
        AP_Taxonomy::resetRegistry();
        AP_Admin::clearNotices();

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
        AP_Admin::clearNotices();
    }

    public function testNonceCreateAndVerify(): void
    {
        $nonce = ap_create_nonce('unit-test', 7);
        $this->assertNotSame('', $nonce);
        $this->assertTrue(ap_check_nonce($nonce, 'unit-test', 7));
        $this->assertFalse(ap_check_nonce($nonce, 'other-action', 7));
        $this->assertFalse(ap_check_nonce($nonce, 'unit-test', 8));
        $this->assertFalse(ap_check_nonce('bogus', 'unit-test', 7));

        $field = ap_nonce_field('unit-test', '_ap_nonce', false, 7);
        $this->assertStringContainsString('name="_ap_nonce"', $field);
        $this->assertStringContainsString($nonce, $field);
    }

    public function testEscapingHelpers(): void
    {
        $this->assertSame('a &amp; b', ap_esc_html('a & b'));
        $this->assertSame('&quot;x&quot;', ap_esc_attr('"x"'));
        $this->assertSame('Hello x', ap_sanitize_text_field("  Hello \n <b>x</b> "));
        $this->assertStringNotContainsString('<script>', ap_strip_all_tags('<script>alert(1)</script>hi'));
        $this->assertStringContainsString('hi', ap_strip_all_tags('<script>alert(1)</script>hi'));
    }

    public function testListTableLoadsPostsAndStatusViews(): void
    {
        AP_Post::insert([
            'post_title' => 'Published One',
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_author' => 1,
        ], $this->db);
        AP_Post::insert([
            'post_title' => 'Draft Two',
            'post_type' => 'post',
            'post_status' => 'draft',
            'post_author' => 1,
        ], $this->db);
        AP_Post::insert([
            'post_title' => 'Trashed Three',
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_author' => 1,
        ], $this->db);
        $trashId = (int) $this->db->getVar(
            'SELECT ID FROM ' . $this->db->quoteIdentifier($this->db->table('posts'))
            . ' WHERE post_title = ?',
            ['Trashed Three']
        );
        AP_Post::trash($trashId, $this->db);

        $table = new AP_Posts_List_Table('post', $this->db);
        $table->prepareItems(['post_status' => 'all']);

        $this->assertSame(2, $table->totalItems);
        $titles = array_map(static fn (AP_Post $p): string => $p->post_title, $table->items);
        $this->assertContains('Published One', $titles);
        $this->assertContains('Draft Two', $titles);
        $this->assertNotContains('Trashed Three', $titles);

        $this->assertSame(1, $table->statusCounts['publish'] ?? 0);
        $this->assertSame(1, $table->statusCounts['draft'] ?? 0);
        $this->assertSame(1, $table->statusCounts['trash'] ?? 0);

        $html = $table->render();
        $this->assertStringContainsString('Published One', $html);
        $this->assertStringContainsString('name="post[]"', $html);
        $this->assertStringContainsString('Move to Trash', $html);

        $views = $table->renderViews();
        $this->assertStringContainsString('All', $views);
        $this->assertStringContainsString('Trash', $views);

        $table->prepareItems(['post_status' => 'trash']);
        $this->assertSame(1, $table->totalItems);
        $this->assertSame('Trashed Three', $table->items[0]->post_title);
    }

    public function testListTableSearchAndPagination(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            AP_Post::insert([
                'post_title' => "Post {$i}",
                'post_content' => $i === 3 ? 'unique-search-token' : 'other',
                'post_type' => 'post',
                'post_status' => 'publish',
            ], $this->db);
        }

        $table = new AP_Posts_List_Table('post', $this->db);
        $table->prepareItems(['s' => 'unique-search-token', 'per_page' => 10]);
        $this->assertSame(1, $table->totalItems);
        $this->assertSame('Post 3', $table->items[0]->post_title);

        $table->prepareItems(['per_page' => 2, 'paged' => 2]);
        $this->assertSame(5, $table->totalItems);
        $this->assertSame(3, $table->totalPages);
        $this->assertCount(2, $table->items);
    }

    public function testListTableCategoryFilterAndColumns(): void
    {
        $cat = AP_Taxonomy::insertTerm('News', 'category', [], $this->db);
        $this->assertIsArray($cat);
        $catId = (int) $cat['term_id'];

        $p1 = AP_Post::insert([
            'post_title' => 'In News',
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_author' => 1,
        ], $this->db);
        $p2 = AP_Post::insert([
            'post_title' => 'Other Cat',
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_author' => 1,
        ], $this->db);
        AP_Taxonomy::setObjectTerms($p1, [$catId], 'category', false, $this->db);
        AP_Taxonomy::setObjectTerms($p2, [AP_Taxonomy::getDefaultCategoryId($this->db)], 'category', false, $this->db);
        AP_Taxonomy::setObjectTerms($p1, ['alpha'], 'post_tag', false, $this->db);

        $table = new AP_Posts_List_Table('post', $this->db);
        $table->prepareItems(['cat' => $catId]);
        $this->assertSame($catId, $table->catId);
        $this->assertSame(1, $table->totalItems);
        $this->assertSame('In News', $table->items[0]->post_title);

        $cols = $table->getColumns();
        $this->assertArrayHasKey('categories', $cols);
        $this->assertArrayHasKey('tags', $cols);

        $html = $table->render();
        $this->assertStringContainsString('name="cat"', $html);
        $this->assertStringContainsString('All Categories', $html);
        $this->assertStringContainsString('News', $html);
        $this->assertStringContainsString('alpha', $html);

        $filter = $table->renderCategoryFilter();
        $this->assertStringContainsString('ap-cat-filter', $filter);
        $this->assertStringContainsString('selected', $filter);
    }

    public function testSavePostTaxonomies(): void
    {
        $cat = AP_Taxonomy::insertTerm('Tech', 'category', [], $this->db);
        $catId = (int) $cat['term_id'];
        $nonce = ap_create_nonce('new-post', 1);
        $result = AP_Admin_Post_Edit::save([
            'post_title' => 'Taxed Post',
            'post_content' => 'Body',
            'post_status' => 'publish',
            'post_type' => 'post',
            'post_category' => [$catId],
            'tax_input' => ['post_tag' => 'php, sqlite'],
            '_ap_nonce' => $nonce,
        ], 1, $this->db);

        $this->assertTrue($result['ok']);
        $id = (int) $result['id'];
        $this->assertSame(
            ['Tech'],
            AP_Taxonomy::getObjectTerms($id, 'category', ['fields' => 'names'], $this->db)
        );
        $this->assertEqualsCanonicalizing(
            ['php', 'sqlite'],
            AP_Taxonomy::getObjectTerms($id, 'post_tag', ['fields' => 'names'], $this->db)
        );

        $editHtml = AP_Admin_Post_Edit::renderForm(
            AP_Post::get($id, $this->db),
            'post',
            1,
            $this->db
        );
        $this->assertStringContainsString('post_category[]', $editHtml);
        $this->assertStringContainsString('tax_input[post_tag]', $editHtml);
        $this->assertStringContainsString('Tech', $editHtml);
    }

    public function testBulkTrashAndUntrash(): void
    {
        $id1 = AP_Post::insert([
            'post_title' => 'Bulk A',
            'post_type' => 'post',
            'post_status' => 'publish',
        ], $this->db);
        $id2 = AP_Post::insert([
            'post_title' => 'Bulk B',
            'post_type' => 'post',
            'post_status' => 'publish',
        ], $this->db);

        $table = new AP_Posts_List_Table('post', $this->db);
        $nonce = ap_create_nonce('bulk-posts');
        $result = $table->processBulkAction([
            'action' => 'trash',
            '_ap_nonce' => $nonce,
            'post' => [$id1, $id2],
        ]);
        $this->assertTrue($result['ok']);
        $this->assertSame(2, $result['count']);
        $this->assertSame('bulk_trashed', $result['message_key']);
        $this->assertSame('trash', AP_Post::get($id1, $this->db)?->post_status);
        $this->assertSame('trash', AP_Post::get($id2, $this->db)?->post_status);

        $nonce2 = ap_create_nonce('bulk-posts');
        $result2 = $table->processBulkAction([
            'action' => 'untrash',
            '_ap_nonce' => $nonce2,
            'post' => [$id1],
        ]);
        $this->assertTrue($result2['ok']);
        $this->assertSame('publish', AP_Post::get($id1, $this->db)?->post_status);
    }

    public function testBulkRejectsBadNonce(): void
    {
        $id = AP_Post::insert([
            'post_title' => 'No',
            'post_type' => 'post',
            'post_status' => 'publish',
        ], $this->db);
        $table = new AP_Posts_List_Table('post', $this->db);
        $result = $table->processBulkAction([
            'action' => 'trash',
            '_ap_nonce' => 'invalid',
            'post' => [$id],
        ]);
        $this->assertFalse($result['ok']);
        $this->assertSame('nonce', $result['message_key']);
        $this->assertSame('publish', AP_Post::get($id, $this->db)?->post_status);
    }

    public function testSaveCreatesAndUpdatesPost(): void
    {
        $nonce = ap_create_nonce('new-post', 1);
        $result = AP_Admin_Post_Edit::save([
            'post_title' => 'Hello Admin',
            'post_content' => '<p>Body</p>',
            'post_status' => 'draft',
            'post_type' => 'post',
            'save_action' => 'publish',
            'visibility' => 'public',
            'comment_status' => 'open',
            '_ap_nonce' => $nonce,
        ], 1, $this->db);

        $this->assertTrue($result['ok']);
        $this->assertGreaterThan(0, $result['id']);
        $this->assertSame('created', $result['message_key']);
        $post = AP_Post::get($result['id'], $this->db);
        $this->assertNotNull($post);
        $this->assertSame('Hello Admin', $post->post_title);
        $this->assertSame('publish', $post->post_status);
        $this->assertSame('open', $post->comment_status);

        $updateNonce = ap_create_nonce('update-post-' . $result['id'], 1);
        $result2 = AP_Admin_Post_Edit::save([
            'post_ID' => $result['id'],
            'post_title' => 'Hello Updated',
            'post_content' => 'New body',
            'post_status' => 'draft',
            'post_type' => 'post',
            'save_action' => 'draft',
            'visibility' => 'public',
            '_ap_nonce' => $updateNonce,
        ], 1, $this->db);

        $this->assertTrue($result2['ok']);
        $this->assertSame('updated', $result2['message_key']);
        $updated = AP_Post::get($result['id'], $this->db);
        $this->assertSame('Hello Updated', $updated?->post_title);
        $this->assertSame('draft', $updated?->post_status);
    }

    public function testSavePageWithParentAndTemplate(): void
    {
        $parentId = AP_Post::insert([
            'post_title' => 'Parent Page',
            'post_type' => 'page',
            'post_status' => 'publish',
        ], $this->db);

        $nonce = ap_create_nonce('new-post', 1);
        $result = AP_Admin_Post_Edit::save([
            'post_title' => 'Child Page',
            'post_content' => 'Child body',
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_parent' => $parentId,
            'menu_order' => 5,
            'page_template' => 'full-width',
            'save_action' => 'publish',
            'visibility' => 'public',
            '_ap_nonce' => $nonce,
        ], 1, $this->db);

        $this->assertTrue($result['ok'], implode('; ', $result['errors']));
        $page = AP_Post::get($result['id'], $this->db);
        $this->assertNotNull($page);
        $this->assertSame('page', $page->post_type);
        $this->assertSame($parentId, $page->post_parent);
        $this->assertSame(5, $page->menu_order);
        $this->assertSame('full-width', AP_Post::getPageTemplate($result['id'], $this->db));
        $this->assertSame('parent-page/child-page', AP_Post::getPagePath($result['id'], $this->db));
    }

    public function testSavePasswordProtectedAndPrivate(): void
    {
        $nonce = ap_create_nonce('new-post', 1);
        $result = AP_Admin_Post_Edit::save([
            'post_title' => 'Secret',
            'post_content' => 'shh',
            'post_type' => 'post',
            'post_status' => 'publish',
            'visibility' => 'password',
            'post_password' => 's3cret',
            'save_action' => 'publish',
            '_ap_nonce' => $nonce,
        ], 1, $this->db);
        $this->assertTrue($result['ok']);
        $post = AP_Post::get($result['id'], $this->db);
        $this->assertSame('s3cret', $post?->post_password);

        $nonce2 = ap_create_nonce('update-post-' . $result['id'], 1);
        $result2 = AP_Admin_Post_Edit::save([
            'post_ID' => $result['id'],
            'post_title' => 'Secret',
            'post_content' => 'shh',
            'post_type' => 'post',
            'post_status' => 'publish',
            'visibility' => 'private',
            'save_action' => 'publish',
            '_ap_nonce' => $nonce2,
        ], 1, $this->db);
        $this->assertTrue($result2['ok']);
        $post2 = AP_Post::get($result['id'], $this->db);
        $this->assertSame('private', $post2?->post_status);
        $this->assertSame('', $post2?->post_password);
    }

    public function testRowActionTrash(): void
    {
        $id = AP_Post::insert([
            'post_title' => 'Row trash me',
            'post_type' => 'post',
            'post_status' => 'publish',
        ], $this->db);
        $nonce = ap_create_nonce('post-row-' . $id);
        $result = AP_Admin_Post_Edit::processRowAction([
            'action' => 'trash',
            'post' => $id,
            '_ap_nonce' => $nonce,
        ], $this->db);
        $this->assertTrue($result['ok']);
        $this->assertSame('trashed', $result['message_key']);
        $this->assertSame('trash', AP_Post::get($id, $this->db)?->post_status);
    }

    public function testRenderFormContainsFields(): void
    {
        $html = AP_Admin_Post_Edit::renderForm(null, 'post', 1, $this->db);
        $this->assertStringContainsString('name="post_title"', $html);
        $this->assertStringContainsString('name="post_content"', $html);
        $this->assertStringContainsString('name="post_status"', $html);
        $this->assertStringContainsString('name="visibility"', $html);
        $this->assertStringContainsString('name="sticky"', $html);
        $this->assertStringContainsString('_ap_nonce', $html);

        $pageHtml = AP_Admin_Post_Edit::renderForm(null, 'page', 1, $this->db);
        $this->assertStringContainsString('name="post_parent"', $pageHtml);
        $this->assertStringContainsString('name="menu_order"', $pageHtml);
        $this->assertStringContainsString('Page Attributes', $pageHtml);
    }

    public function testPageListTableColumns(): void
    {
        AP_Post::insert([
            'post_title' => 'About',
            'post_type' => 'page',
            'post_status' => 'publish',
            'menu_order' => 2,
        ], $this->db);

        $table = new AP_Posts_List_Table('page', $this->db);
        $table->prepareItems([]);
        $cols = $table->getColumns();
        $this->assertArrayHasKey('order', $cols);
        $this->assertArrayNotHasKey('comments', $cols);
        $html = $table->render();
        $this->assertStringContainsString('About', $html);
        $this->assertStringContainsString('Order', $html);
    }

    public function testAutosaveDoesNotChangeParent(): void
    {
        $id = AP_Post::insert([
            'post_title' => 'Published',
            'post_content' => 'Live body',
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_author' => 1,
        ], $this->db);

        $nonce = ap_create_nonce('update-post-' . $id, 1);
        $result = AP_Admin_Post_Edit::save([
            'post_ID' => $id,
            'post_title' => 'In progress title',
            'post_content' => 'In progress body',
            'post_type' => 'post',
            'save_action' => 'autosave',
            '_ap_nonce' => $nonce,
        ], 1, $this->db);

        $this->assertTrue($result['ok']);
        $this->assertSame('autosaved', $result['message_key']);
        $this->assertArrayHasKey('revision_id', $result);
        $this->assertGreaterThan(0, $result['revision_id'] ?? 0);

        $parent = AP_Post::get($id, $this->db);
        $this->assertSame('Published', $parent?->post_title);
        $this->assertSame('Live body', $parent?->post_content);

        $autosave = AP_Post::getAutosave($id, 1, $this->db);
        $this->assertNotNull($autosave);
        $this->assertSame('In progress title', $autosave->post_title);
    }

    public function testSaveUpdateCreatesRevisionAndClearsAutosave(): void
    {
        $id = AP_Post::insert([
            'post_title' => 'V1',
            'post_content' => 'C1',
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_author' => 1,
        ], $this->db);
        AP_Post::autosave($id, [
            'post_title' => 'Drafting',
            'post_content' => 'WIP',
        ], 1, $this->db);
        $this->assertNotNull(AP_Post::getAutosave($id, 1, $this->db));

        $nonce = ap_create_nonce('update-post-' . $id, 1);
        $result = AP_Admin_Post_Edit::save([
            'post_ID' => $id,
            'post_title' => 'V2',
            'post_content' => 'C2',
            'post_type' => 'post',
            'post_status' => 'publish',
            'save_action' => 'publish',
            'visibility' => 'public',
            '_ap_nonce' => $nonce,
        ], 1, $this->db);

        $this->assertTrue($result['ok']);
        $this->assertSame('V2', AP_Post::get($id, $this->db)?->post_title);
        $this->assertSame(1, AP_Post::countRevisions($id, false, $this->db));
        $this->assertNull(AP_Post::getAutosave($id, 1, $this->db));
    }

    public function testRestoreAndDeleteRevisionActions(): void
    {
        $id = AP_Post::insert([
            'post_title' => 'Current',
            'post_content' => 'Now',
            'post_type' => 'post',
            'post_status' => 'publish',
        ], $this->db);
        AP_Post::update($id, [
            'post_title' => 'Later',
            'post_content' => 'Then',
        ], $this->db);
        $revisions = AP_Post::getRevisions($id, [], $this->db);
        $this->assertNotEmpty($revisions);
        $revId = $revisions[0]->ID;

        $restore = AP_Admin_Post_Edit::processRestoreRevision([
            'revision' => $revId,
            '_ap_nonce' => ap_create_nonce('restore-revision-' . $revId),
        ], $this->db);
        $this->assertTrue($restore['ok']);
        $this->assertSame('revision_restored', $restore['message_key']);
        $this->assertSame('Current', AP_Post::get($id, $this->db)?->post_title);

        $revisions = AP_Post::getRevisions($id, [], $this->db);
        $deleteId = $revisions[0]->ID;
        $delete = AP_Admin_Post_Edit::processDeleteRevision([
            'revision' => $deleteId,
            '_ap_nonce' => ap_create_nonce('delete-revision-' . $deleteId),
        ], $this->db);
        $this->assertTrue($delete['ok']);
        $this->assertSame('revision_deleted', $delete['message_key']);
        $this->assertNull(AP_Post::get($deleteId, $this->db));
    }

    public function testRenderFormShowsRevisionsMetabox(): void
    {
        $id = AP_Post::insert([
            'post_title' => 'With revs',
            'post_content' => 'A',
            'post_type' => 'post',
            'post_status' => 'publish',
        ], $this->db);
        AP_Post::update($id, ['post_content' => 'B'], $this->db);
        $post = AP_Post::get($id, $this->db);
        $this->assertNotNull($post);

        $html = AP_Admin_Post_Edit::renderForm($post, 'post', 1, $this->db);
        $this->assertStringContainsString('Revisions', $html);
        $this->assertStringContainsString('Browse history', $html);
        $this->assertStringContainsString('value="autosave"', $html);

        $list = AP_Admin_Post_Edit::renderRevisionsList($post, 1, $this->db);
        $this->assertStringContainsString('Restore', $list);
        $this->assertStringContainsString('ap-revisions-list', $list);
    }

    public function testAdminFilesExist(): void
    {
        $paths = [
            'ap-admin/index.php',
            'ap-admin/edit.php',
            'ap-admin/post.php',
            'ap-admin/post-new.php',
            'ap-admin/revision.php',
            'ap-admin/login.php',
            'ap-admin/admin-bootstrap.php',
            'ap-admin/admin-header.php',
            'ap-admin/admin-footer.php',
            'ap-admin/css/admin.css',
            'ap-admin/includes/class-ap-admin.php',
            'ap-admin/includes/class-ap-posts-list-table.php',
            'ap-admin/includes/class-ap-admin-post-edit.php',
            'ap-includes/class-ap-nonce.php',
        ];
        foreach ($paths as $rel) {
            $this->assertFileExists($this->root . '/' . $rel, "Missing {$rel}");
        }
    }

    public function testSanitizeRedirectBlocksOpenRedirect(): void
    {
        $safe = AP_Admin::sanitizeRedirect('https://evil.example/phish');
        $this->assertStringNotContainsString('evil.example', $safe);

        $rel = AP_Admin::sanitizeRedirect('/ap-admin/edit.php?post_type=post');
        $this->assertSame('/ap-admin/edit.php?post_type=post', $rel);
    }

    public function testMenuItems(): void
    {
        $items = AP_Admin::menuItems('posts');
        $ids = array_column($items, 'id');
        $this->assertContains('dashboard', $ids);
        $this->assertContains('posts', $ids);
        $this->assertContains('pages', $ids);
        foreach ($items as $item) {
            if ($item['id'] === 'posts') {
                $this->assertTrue($item['active']);
            }
        }
    }
}
