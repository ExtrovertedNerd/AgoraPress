<?php

/**
 * Tests for the Categories / Tags list table (edit-tags.php).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Admin;

use AP_Admin;
use AP_Admin_Terms;
use AP_DB;
use AP_Migrator;
use AP_Options;
use AP_Post;
use AP_Roles;
use AP_Taxonomy;
use AP_User;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_Admin_Terms::class)]
final class AdminTermsTest extends TestCase
{
    private string $root;

    private AP_DB $db;

    private int $actorId = 0;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        require_once $this->root . '/ap-includes/version.php';
        require_once $this->root . '/ap-includes/class-ap-db.php';
        require_once $this->root . '/ap-includes/class-ap-migrator.php';
        require_once $this->root . '/ap-includes/class-ap-options.php';
        require_once $this->root . '/ap-includes/class-ap-user.php';
        require_once $this->root . '/ap-includes/class-ap-session.php';
        require_once $this->root . '/ap-includes/class-ap-roles.php';
        require_once $this->root . '/ap-includes/class-ap-nonce.php';
        require_once $this->root . '/ap-includes/class-ap-post.php';
        require_once $this->root . '/ap-includes/class-ap-taxonomy.php';
        require_once $this->root . '/ap-includes/functions.php';
        require_once $this->root . '/ap-admin/includes/class-ap-admin.php';
        require_once $this->root . '/ap-admin/includes/class-ap-admin-terms.php';

        if (!defined('AP_NONCE_KEY')) {
            define('AP_NONCE_KEY', 'test-nonce-key-' . str_repeat('t', 32));
        }
        if (!defined('AP_NONCE_SALT')) {
            define('AP_NONCE_SALT', 'test-nonce-salt-' . str_repeat('u', 32));
        }
        if (!defined('AP_LOGGED_IN_KEY')) {
            define('AP_LOGGED_IN_KEY', 'test-logged-in-key-' . str_repeat('v', 32));
        }
        if (!defined('AP_LOGGED_IN_SALT')) {
            define('AP_LOGGED_IN_SALT', 'test-logged-in-salt-' . str_repeat('w', 32));
        }

        AP_Roles::flushCache();
        AP_Options::flushCache();
        AP_Post::resetRegistry();
        AP_Taxonomy::resetRegistry();
        AP_Admin::clearNotices();

        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');
        $GLOBALS['apdb'] = $this->db;

        $migrator = new AP_Migrator($this->db, AP_Migrator::defaultMigrationsPath());
        $migrator->migrate();
        AP_Roles::ensureDefaults($this->db);
        AP_Post::ensureBuiltins();
        AP_Taxonomy::ensureBuiltins();

        $admin = AP_User::create([
            'user_login' => 'termsadmin',
            'user_email' => 'termsadmin@example.test',
            'password' => 'password123',
            'role' => 'administrator',
        ], $this->db);
        $this->actorId = (int) $admin['id'];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['apdb']);
        AP_Roles::flushCache();
        AP_Options::flushCache();
        AP_Post::resetRegistry();
        AP_Taxonomy::resetRegistry();
        AP_Admin::clearNotices();
    }

    public function testDefaultBadgeStaysOnCurrentDefaultCategory(): void
    {
        $uncatId = AP_Taxonomy::ensureDefaultCategory($this->db);
        $this->assertGreaterThan(0, $uncatId);

        $news = AP_Taxonomy::insertTerm('News', 'category', [], $this->db);
        $this->assertIsArray($news);
        $newsId = (int) $news['term_id'];

        $html = AP_Admin_Terms::renderListTable('category', [], $this->actorId, $this->db);
        $this->assertSame(1, substr_count($html, '— Default'));
        $this->assertStringContainsString(
            '<span class="ap-muted">— Default</span>',
            $this->rowHtml($html, $uncatId)
        );
        $this->assertStringNotContainsString('— Default', $this->rowHtml($html, $newsId));

        AP_Options::update('default_category', (string) $newsId, $this->db);
        $this->assertSame($newsId, AP_Taxonomy::getDefaultCategoryId($this->db));

        $moved = AP_Admin_Terms::renderListTable('category', [], $this->actorId, $this->db);
        $this->assertSame(1, substr_count($moved, '— Default'));
        $this->assertStringContainsString(
            '<span class="ap-muted">— Default</span>',
            $this->rowHtml($moved, $newsId)
        );
        $this->assertStringNotContainsString('— Default', $this->rowHtml($moved, $uncatId));
    }

    public function testSetAsDefaultRowActionUpdatesDefaultCategory(): void
    {
        $uncatId = AP_Taxonomy::ensureDefaultCategory($this->db);
        $uncat = AP_Taxonomy::getTerm($uncatId, 'category', $this->db);
        $this->assertNotNull($uncat);
        $this->assertSame('uncategorized', (string) $uncat->slug);

        $news = AP_Taxonomy::insertTerm('News', 'category', [], $this->db);
        $this->assertIsArray($news);
        $newsId = (int) $news['term_id'];
        $this->assertSame($uncatId, AP_Taxonomy::getDefaultCategoryId($this->db));
        $this->assertNotSame($newsId, AP_Taxonomy::getDefaultCategoryId($this->db));

        $nonce = ap_create_nonce('set-default-tag-' . $newsId, $this->actorId);
        $result = AP_Admin_Terms::setDefault(
            $newsId,
            'category',
            $this->actorId,
            $nonce,
            $this->db
        );

        $this->assertTrue($result['ok']);
        $this->assertSame('default_category_set', $result['message_key']);
        $this->assertSame($newsId, AP_Taxonomy::getDefaultCategoryId($this->db));
        $this->assertSame((string) $newsId, (string) AP_Options::get('default_category', '0', $this->db));
        $this->assertNotNull(AP_Taxonomy::getTerm($uncatId, 'category', $this->db));
    }

    public function testSetAsDefaultMakesPreviousDefaultDeletable(): void
    {
        $uncatId = AP_Taxonomy::ensureDefaultCategory($this->db);
        $uncat = AP_Taxonomy::getTerm($uncatId, 'category', $this->db);
        $this->assertNotNull($uncat);
        $this->assertSame('uncategorized', (string) $uncat->slug);
        $this->assertSame('Uncategorized', (string) $uncat->name);

        $news = AP_Taxonomy::insertTerm('News', 'category', [], $this->db);
        $this->assertIsArray($news);
        $newsId = (int) $news['term_id'];

        $before = AP_Admin_Terms::renderListTable('category', [], $this->actorId, $this->db);
        $uncatBefore = $this->rowHtml($before, $uncatId);
        $newsBefore = $this->rowHtml($before, $newsId);
        $this->assertFalse($this->rowHasDeleteLink($uncatBefore));
        $this->assertStringNotContainsString('class="submitdelete"', $uncatBefore);
        $this->assertStringNotContainsString('>Delete</a>', $uncatBefore);
        $this->assertFalse($this->rowHasSetAsDefault($uncatBefore));
        $this->assertTrue($this->rowExplainsHiddenDelete($uncatBefore));
        $this->assertTrue($this->rowHasDeleteLink($newsBefore));
        $this->assertStringContainsString('class="submitdelete"', $newsBefore);
        $this->assertStringContainsString('>Delete</a>', $newsBefore);
        $this->assertTrue($this->rowHasSetAsDefault($newsBefore));
        $this->assertFalse($this->rowExplainsHiddenDelete($newsBefore));

        $nonce = ap_create_nonce('set-default-tag-' . $newsId, $this->actorId);
        $result = AP_Admin_Terms::setDefault(
            $newsId,
            'category',
            $this->actorId,
            $nonce,
            $this->db
        );
        $this->assertTrue($result['ok']);
        $this->assertSame($newsId, AP_Taxonomy::getDefaultCategoryId($this->db));

        $after = AP_Admin_Terms::renderListTable('category', [], $this->actorId, $this->db);
        $uncatAfter = $this->rowHtml($after, $uncatId);
        $newsAfter = $this->rowHtml($after, $newsId);
        $this->assertStringNotContainsString('— Default', $uncatAfter);
        $this->assertTrue($this->rowHasDeleteLink($uncatAfter));
        $this->assertStringContainsString('class="submitdelete"', $uncatAfter);
        $this->assertStringContainsString('>Delete</a>', $uncatAfter);
        $this->assertTrue($this->rowHasSetAsDefault($uncatAfter));
        $this->assertFalse($this->rowExplainsHiddenDelete($uncatAfter));
        $this->assertStringContainsString(
            '<span class="ap-muted">— Default</span>',
            $newsAfter
        );
        $this->assertFalse($this->rowHasDeleteLink($newsAfter));
        $this->assertStringNotContainsString('class="submitdelete"', $newsAfter);
        $this->assertStringNotContainsString('>Delete</a>', $newsAfter);
        $this->assertFalse($this->rowHasSetAsDefault($newsAfter));
        $this->assertTrue($this->rowExplainsHiddenDelete($newsAfter));

        $this->assertTrue(AP_Taxonomy::deleteTerm($uncatId, 'category', $this->db));
        $this->assertNull(AP_Taxonomy::getTerm($uncatId, 'category', $this->db));
        $this->assertSame($newsId, AP_Taxonomy::getDefaultCategoryId($this->db));
    }

    public function testSetAsDefaultThenUncategorizedDeleteReassignsOrphans(): void
    {
        $uncatId = AP_Taxonomy::ensureDefaultCategory($this->db);
        $news = AP_Taxonomy::insertTerm('News', 'category', [], $this->db);
        $this->assertIsArray($news);
        $newsId = (int) $news['term_id'];

        $orphanId = AP_Post::insert([
            'post_title' => 'Only uncategorized',
            'post_status' => 'publish',
            'post_type' => 'post',
            'post_author' => $this->actorId,
        ], $this->db);
        $this->assertGreaterThan(0, $orphanId);
        AP_Taxonomy::setObjectTerms($orphanId, [$uncatId], 'category', false, $this->db);

        $setNonce = ap_create_nonce('set-default-tag-' . $newsId, $this->actorId);
        $set = AP_Admin_Terms::setDefault(
            $newsId,
            'category',
            $this->actorId,
            $setNonce,
            $this->db
        );
        $this->assertTrue($set['ok']);
        $this->assertSame($newsId, AP_Taxonomy::getDefaultCategoryId($this->db));

        $deleteNonce = ap_create_nonce('delete-tag-' . $uncatId, $this->actorId);
        $deleted = AP_Admin_Terms::delete(
            $uncatId,
            'category',
            $this->actorId,
            $deleteNonce,
            $this->db
        );
        $this->assertTrue($deleted['ok']);
        $this->assertSame('term_deleted', $deleted['message_key']);
        $this->assertNull(AP_Taxonomy::getTerm($uncatId, 'category', $this->db));
        $this->assertSame(
            [$newsId],
            AP_Taxonomy::getObjectTerms($orphanId, 'category', ['fields' => 'ids'], $this->db)
        );
        $this->assertSame($newsId, AP_Taxonomy::getDefaultCategoryId($this->db));
    }

    public function testSetAsDefaultRequiresNonce(): void
    {
        AP_Taxonomy::ensureDefaultCategory($this->db);
        $news = AP_Taxonomy::insertTerm('News', 'category', [], $this->db);
        $this->assertIsArray($news);
        $newsId = (int) $news['term_id'];
        $uncatId = AP_Taxonomy::getDefaultCategoryId($this->db);

        $result = AP_Admin_Terms::setDefault(
            $newsId,
            'category',
            $this->actorId,
            'not-a-nonce',
            $this->db
        );
        $this->assertFalse($result['ok']);
        $this->assertSame('nonce', $result['message_key']);
        $this->assertSame($uncatId, AP_Taxonomy::getDefaultCategoryId($this->db));
    }

    public function testSetAsDefaultRequiresManageCategories(): void
    {
        AP_Taxonomy::ensureDefaultCategory($this->db);
        $news = AP_Taxonomy::insertTerm('News', 'category', [], $this->db);
        $this->assertIsArray($news);
        $newsId = (int) $news['term_id'];
        $uncatId = AP_Taxonomy::getDefaultCategoryId($this->db);

        $author = AP_User::create([
            'user_login' => 'termsauthor',
            'user_email' => 'termsauthor@example.test',
            'password' => 'password123',
            'role' => 'author',
        ], $this->db);
        $this->assertTrue($author['ok'], implode('; ', $author['errors'] ?? []));
        $authorId = (int) $author['id'];
        $nonce = ap_create_nonce('set-default-tag-' . $newsId, $authorId);
        $result = AP_Admin_Terms::setDefault(
            $newsId,
            'category',
            $authorId,
            $nonce,
            $this->db
        );

        $this->assertFalse($result['ok']);
        $this->assertSame('error', $result['message_key']);
        $this->assertSame($uncatId, AP_Taxonomy::getDefaultCategoryId($this->db));
    }

    public function testDefaultBadgeIsAbsentFromTagsList(): void
    {
        AP_Taxonomy::ensureDefaultCategory($this->db);
        $tag = AP_Taxonomy::insertTerm('Hello', 'post_tag', [], $this->db);
        $this->assertIsArray($tag);

        $html = AP_Admin_Terms::renderListTable('post_tag', [], $this->actorId, $this->db);
        $this->assertStringContainsString('Hello', $html);
        $this->assertStringNotContainsString('— Default', $html);
        $this->assertStringNotContainsString('Set as default', $html);
        $this->assertStringNotContainsString('action=set-default', $html);
        $this->assertStringNotContainsString(AP_Admin_Terms::DEFAULT_CATEGORY_DELETE_BLOCKED, $html);
        $this->assertStringNotContainsString('Settings → Writing', $html);
    }

    public function testDefaultCategoryRowExplainsWhyDeleteIsHidden(): void
    {
        $uncatId = AP_Taxonomy::ensureDefaultCategory($this->db);
        $news = AP_Taxonomy::insertTerm('News', 'category', [], $this->db);
        $this->assertIsArray($news);
        $newsId = (int) $news['term_id'];

        $html = AP_Admin_Terms::renderListTable('category', [], $this->actorId, $this->db);
        $uncatRow = $this->rowHtml($html, $uncatId);
        $newsRow = $this->rowHtml($html, $newsId);

        $this->assertFalse($this->rowHasDeleteLink($uncatRow));
        $this->assertTrue($this->rowExplainsHiddenDelete($uncatRow));
        $this->assertTrue($this->rowHasDeleteLink($newsRow));
        $this->assertFalse($this->rowExplainsHiddenDelete($newsRow));
        $this->assertStringNotContainsString(
            AP_Admin_Terms::DEFAULT_CATEGORY_DELETE_BLOCKED,
            $newsRow
        );
    }

    public function testRowDeleteOfDefaultCategoryReturnsHonestMessage(): void
    {
        $uncatId = AP_Taxonomy::ensureDefaultCategory($this->db);
        $news = AP_Taxonomy::insertTerm('News', 'category', [], $this->db);
        $this->assertIsArray($news);
        $this->assertSame($uncatId, AP_Taxonomy::getDefaultCategoryId($this->db));

        $nonce = ap_create_nonce('delete-tag-' . $uncatId, $this->actorId);
        $result = AP_Admin_Terms::delete(
            $uncatId,
            'category',
            $this->actorId,
            $nonce,
            $this->db
        );

        $this->assertFalse($result['ok']);
        $this->assertSame(
            AP_Admin_Terms::DEFAULT_CATEGORY_DELETE_BLOCKED_KEY,
            $result['message_key']
        );
        $this->assertNotSame('error', $result['message_key']);
        $this->assertNotNull(AP_Taxonomy::getTerm($uncatId, 'category', $this->db));
        $this->assertSame($uncatId, AP_Taxonomy::getDefaultCategoryId($this->db));
    }

    public function testBulkDeleteOfDefaultCategoryReturnsHonestMessage(): void
    {
        $uncatId = AP_Taxonomy::ensureDefaultCategory($this->db);
        $news = AP_Taxonomy::insertTerm('News', 'category', [], $this->db);
        $this->assertIsArray($news);

        $nonce = ap_create_nonce('bulk-tags', $this->actorId);
        $result = AP_Admin_Terms::bulkDelete(
            [$uncatId],
            'category',
            $this->actorId,
            $nonce,
            $this->db
        );

        $this->assertFalse($result['ok']);
        $this->assertSame(0, $result['count']);
        $this->assertSame(
            AP_Admin_Terms::DEFAULT_CATEGORY_DELETE_BLOCKED_KEY,
            $result['message_key']
        );
        $this->assertNotSame('error', $result['message_key']);
        $this->assertNotNull(AP_Taxonomy::getTerm($uncatId, 'category', $this->db));
        $this->assertSame($uncatId, AP_Taxonomy::getDefaultCategoryId($this->db));
    }

    public function testBulkDeleteSkipsDefaultAndDeletesOthers(): void
    {
        $uncatId = AP_Taxonomy::ensureDefaultCategory($this->db);
        $news = AP_Taxonomy::insertTerm('News', 'category', [], $this->db);
        $this->assertIsArray($news);
        $newsId = (int) $news['term_id'];
        $sports = AP_Taxonomy::insertTerm('Sports', 'category', [], $this->db);
        $this->assertIsArray($sports);
        $sportsId = (int) $sports['term_id'];

        $nonce = ap_create_nonce('bulk-tags', $this->actorId);
        $result = AP_Admin_Terms::bulkDelete(
            [$uncatId, $newsId],
            'category',
            $this->actorId,
            $nonce,
            $this->db
        );

        $this->assertTrue($result['ok']);
        $this->assertSame(1, $result['count']);
        $this->assertSame('bulk_term_deleted', $result['message_key']);
        $this->assertNotNull(AP_Taxonomy::getTerm($uncatId, 'category', $this->db));
        $this->assertNull(AP_Taxonomy::getTerm($newsId, 'category', $this->db));
        $this->assertNotNull(AP_Taxonomy::getTerm($sportsId, 'category', $this->db));
        $this->assertSame($uncatId, AP_Taxonomy::getDefaultCategoryId($this->db));
    }

    public function testRowDeleteOfPromotedDefaultReturnsHonestMessage(): void
    {
        $uncatId = AP_Taxonomy::ensureDefaultCategory($this->db);
        $news = AP_Taxonomy::insertTerm('News', 'category', [], $this->db);
        $this->assertIsArray($news);
        $newsId = (int) $news['term_id'];
        $this->assertTrue(AP_Taxonomy::setDefaultCategory($newsId, $this->db));

        $blockedNonce = ap_create_nonce('delete-tag-' . $newsId, $this->actorId);
        $blocked = AP_Admin_Terms::delete(
            $newsId,
            'category',
            $this->actorId,
            $blockedNonce,
            $this->db
        );
        $this->assertFalse($blocked['ok']);
        $this->assertSame(
            AP_Admin_Terms::DEFAULT_CATEGORY_DELETE_BLOCKED_KEY,
            $blocked['message_key']
        );
        $this->assertNotNull(AP_Taxonomy::getTerm($newsId, 'category', $this->db));

        $uncatNonce = ap_create_nonce('delete-tag-' . $uncatId, $this->actorId);
        $deleted = AP_Admin_Terms::delete(
            $uncatId,
            'category',
            $this->actorId,
            $uncatNonce,
            $this->db
        );
        $this->assertTrue($deleted['ok']);
        $this->assertSame('term_deleted', $deleted['message_key']);
        $this->assertNull(AP_Taxonomy::getTerm($uncatId, 'category', $this->db));
        $this->assertSame($newsId, AP_Taxonomy::getDefaultCategoryId($this->db));
    }

    public function testRowDeleteOfNonDefaultCategorySucceeds(): void
    {
        $uncatId = AP_Taxonomy::ensureDefaultCategory($this->db);
        $news = AP_Taxonomy::insertTerm('News', 'category', [], $this->db);
        $this->assertIsArray($news);
        $newsId = (int) $news['term_id'];

        $nonce = ap_create_nonce('delete-tag-' . $newsId, $this->actorId);
        $result = AP_Admin_Terms::delete(
            $newsId,
            'category',
            $this->actorId,
            $nonce,
            $this->db
        );

        $this->assertTrue($result['ok']);
        $this->assertSame('term_deleted', $result['message_key']);
        $this->assertNull(AP_Taxonomy::getTerm($newsId, 'category', $this->db));
        $this->assertSame($uncatId, AP_Taxonomy::getDefaultCategoryId($this->db));
    }

    public function testPostsMoveConfirmMessageCopy(): void
    {
        $this->assertSame('', AP_Admin_Terms::postsMoveConfirmMessage(0, 'Uncategorized'));
        $this->assertSame('', AP_Admin_Terms::postsMoveConfirmMessage(2, ''));
        $this->assertSame('', AP_Admin_Terms::postsMoveConfirmMessage(2, '   '));
        $this->assertSame('', AP_Admin_Terms::postsMoveConfirmMessage(-1, 'News'));
        $this->assertSame(
            '1 post will move to Uncategorized.',
            AP_Admin_Terms::postsMoveConfirmMessage(1, 'Uncategorized')
        );
        $this->assertSame(
            '3 posts will move to News.',
            AP_Admin_Terms::postsMoveConfirmMessage(3, 'News')
        );
        $this->assertSame(
            "2 posts will move to O'Brien.",
            AP_Admin_Terms::postsMoveConfirmMessage(2, "O'Brien")
        );
    }

    public function testDeleteLinkConfirmsWhenCategoryHasPosts(): void
    {
        $uncatId = AP_Taxonomy::ensureDefaultCategory($this->db);
        $news = AP_Taxonomy::insertTerm('News', 'category', [], $this->db);
        $this->assertIsArray($news);
        $newsId = (int) $news['term_id'];
        $this->insertPostInCategory($newsId, 'Only news one');
        $this->insertPostInCategory($newsId, 'Only news two');

        $html = AP_Admin_Terms::renderListTable('category', [], $this->actorId, $this->db);
        $newsRow = $this->rowHtml($html, $newsId);
        $uncatRow = $this->rowHtml($html, $uncatId);
        $expected = AP_Admin_Terms::postsMoveConfirmMessage(2, 'Uncategorized');

        $this->assertSame('2 posts will move to Uncategorized.', $expected);
        $this->assertTrue($this->rowHasDeleteLink($newsRow));
        $this->assertTrue($this->rowHasMoveConfirm($newsRow, $expected));
        $this->assertFalse($this->rowHasDeleteLink($uncatRow));
        $this->assertFalse($this->rowHasMoveConfirm($uncatRow, $expected));
        $this->assertStringNotContainsString('will move to', $uncatRow);
    }

    public function testDeleteLinkOmitsConfirmWhenCategoryHasNoPosts(): void
    {
        AP_Taxonomy::ensureDefaultCategory($this->db);
        $news = AP_Taxonomy::insertTerm('News', 'category', [], $this->db);
        $this->assertIsArray($news);
        $newsId = (int) $news['term_id'];

        $html = AP_Admin_Terms::renderListTable('category', [], $this->actorId, $this->db);
        $newsRow = $this->rowHtml($html, $newsId);

        $this->assertTrue($this->rowHasDeleteLink($newsRow));
        $this->assertStringNotContainsString('onclick=', $newsRow);
        $this->assertStringNotContainsString('will move to', $newsRow);
    }

    public function testDeleteLinkConfirmsSingularAndUsesNewDefaultName(): void
    {
        $uncatId = AP_Taxonomy::ensureDefaultCategory($this->db);
        $news = AP_Taxonomy::insertTerm("Ken's Picks", 'category', [], $this->db);
        $this->assertIsArray($news);
        $newsId = (int) $news['term_id'];
        $this->assertTrue(AP_Taxonomy::setDefaultCategory($newsId, $this->db));
        $this->insertPostInCategory($uncatId, 'Only uncategorized');

        $html = AP_Admin_Terms::renderListTable('category', [], $this->actorId, $this->db);
        $uncatRow = $this->rowHtml($html, $uncatId);
        $newsRow = $this->rowHtml($html, $newsId);
        $expected = AP_Admin_Terms::postsMoveConfirmMessage(1, "Ken's Picks");

        $this->assertSame("1 post will move to Ken's Picks.", $expected);
        $this->assertTrue($this->rowHasDeleteLink($uncatRow));
        $this->assertTrue($this->rowHasMoveConfirm($uncatRow, $expected));
        $this->assertFalse($this->rowHasDeleteLink($newsRow));
        $this->assertStringNotContainsString('will move to', $newsRow);
        $this->assertStringNotContainsString('onclick=', $newsRow);
    }

    public function testTagDeleteLinkDoesNotConfirmCategoryReassign(): void
    {
        AP_Taxonomy::ensureDefaultCategory($this->db);
        $tag = AP_Taxonomy::insertTerm('Hello', 'post_tag', [], $this->db);
        $this->assertIsArray($tag);
        $tagId = (int) $tag['term_id'];
        $postId = AP_Post::insert([
            'post_title' => 'Tagged',
            'post_status' => 'publish',
            'post_type' => 'post',
            'post_author' => $this->actorId,
        ], $this->db);
        $this->assertGreaterThan(0, $postId);
        AP_Taxonomy::setObjectTerms($postId, [$tagId], 'post_tag', false, $this->db);

        $html = AP_Admin_Terms::renderListTable('post_tag', [], $this->actorId, $this->db);
        $tagRow = $this->rowHtml($html, $tagId);
        $this->assertTrue($this->rowHasDeleteLink($tagRow));
        $this->assertStringNotContainsString('will move to', $tagRow);
        $this->assertStringNotContainsString('onclick=', $tagRow);
    }

    public function testFailedDefaultDeleteNoticeMatchesRowCopy(): void
    {
        $_GET['message'] = AP_Admin_Terms::DEFAULT_CATEGORY_DELETE_BLOCKED_KEY;
        AP_Admin::consumeQueryNotice();
        $notices = AP_Admin::getNotices();
        unset($_GET['message']);

        $this->assertNotEmpty($notices);
        $this->assertSame(
            AP_Admin_Terms::DEFAULT_CATEGORY_DELETE_BLOCKED,
            $notices[0]['message']
        );
        $this->assertSame('error', $notices[0]['type']);
        $this->assertNotSame(
            'Something went wrong. Please try again.',
            $notices[0]['message']
        );
        $this->assertStringNotContainsString(
            'Could not delete the term',
            $notices[0]['message']
        );

        AP_Admin::clearNotices();
    }

    private function insertPostInCategory(int $termId, string $title): int
    {
        $postId = AP_Post::insert([
            'post_title' => $title,
            'post_status' => 'publish',
            'post_type' => 'post',
            'post_author' => $this->actorId,
        ], $this->db);
        $this->assertGreaterThan(0, $postId);
        AP_Taxonomy::setObjectTerms($postId, [$termId], 'category', false, $this->db);

        return $postId;
    }

    private function rowHtml(string $html, int $termId): string
    {
        $ok = preg_match(
            '/<tr id="tag-' . $termId . '">.*?<\/tr>/s',
            $html,
            $match
        );
        $this->assertSame(1, $ok, 'Missing list row for term ' . $termId);

        return $match[0];
    }

    private function rowHasDeleteLink(string $row): bool
    {
        return str_contains($row, 'class="submitdelete"') && str_contains($row, '>Delete</a>');
    }

    private function rowHasMoveConfirm(string $row, string $message): bool
    {
        $onclick = 'onclick="return confirm(\'' . ap_esc_js($message) . '\');"';

        return $this->rowHasDeleteLink($row)
            && str_contains($row, $onclick)
            && str_contains($row, 'will move to');
    }

    private function rowHasSetAsDefault(string $row): bool
    {
        return str_contains($row, 'Set as default')
            && str_contains($row, 'action=set-default');
    }

    private function rowExplainsHiddenDelete(string $row): bool
    {
        $writingUrl = AP_Admin::url('options-writing.php');

        return str_contains($row, 'class="delete-disabled ap-muted"')
            && str_contains($row, AP_Admin_Terms::DEFAULT_CATEGORY_DELETE_BLOCKED)
            && str_contains($row, 'Settings → Writing')
            && str_contains($row, 'href="' . $writingUrl . '"')
            && !$this->rowHasDeleteLink($row);
    }
}
