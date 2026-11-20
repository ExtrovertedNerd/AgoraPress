<?php

/**
 * AP-010 scheduled publish, AP-011 private posts, AP-012 draft preview.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Post;

use AP_Admin_Post_Edit;
use AP_DB;
use AP_Migrator;
use AP_Options;
use AP_Post;
use AP_Post_Visibility;
use AP_Query;
use AP_Rewrite;
use AP_Roles;
use AP_Taxonomy;
use AP_User;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_Post::class)]
#[CoversClass(AP_Rewrite::class)]
#[CoversClass(AP_Post_Visibility::class)]
final class PostStatusVisibilityTest extends TestCase
{
    private string $root;

    private AP_DB $db;

    /** @var array<string, int> */
    private array $users = [];

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        require_once $this->root . '/ap-includes/version.php';
        require_once $this->root . '/ap-includes/class-ap-db.php';
        require_once $this->root . '/ap-includes/class-ap-migrator.php';
        require_once $this->root . '/ap-includes/class-ap-options.php';
        require_once $this->root . '/ap-includes/class-ap-user.php';
        require_once $this->root . '/ap-includes/class-ap-post.php';
        require_once $this->root . '/ap-includes/class-ap-post-visibility.php';
        require_once $this->root . '/ap-includes/class-ap-comment.php';
        require_once $this->root . '/ap-includes/class-ap-roles.php';
        require_once $this->root . '/ap-includes/class-ap-taxonomy.php';
        require_once $this->root . '/ap-includes/class-ap-query.php';
        require_once $this->root . '/ap-includes/class-ap-rewrite.php';
        require_once $this->root . '/ap-includes/functions.php';

        AP_Roles::flushCache();
        AP_Options::flushCache();
        AP_Post::resetRegistry();
        AP_Taxonomy::resetRegistry();
        AP_Rewrite::resetCache();

        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');
        (new AP_Migrator($this->db, AP_Migrator::defaultMigrationsPath()))->migrate();
        AP_Post::ensureBuiltins();
        AP_Taxonomy::ensureBuiltins();
        AP_Roles::ensureDefaults($this->db);

        foreach (['home' => 'https://example.test', 'siteurl' => 'https://example.test', 'permalink_structure' => ''] as $k => $v) {
            $this->db->insert('options', ['option_name' => $k, 'option_value' => $v, 'autoload' => 'yes']);
        }

        foreach (['administrator', 'editor', 'author', 'subscriber'] as $role) {
            $this->users[$role] = $this->createUser('u_' . $role, $role);
        }
        $this->users['author2'] = $this->createUser('u_author2', 'author');
    }

    protected function tearDown(): void
    {
        AP_Roles::flushCache();
        AP_Options::flushCache();
        AP_Post::resetRegistry();
        AP_Taxonomy::resetRegistry();
        AP_Rewrite::resetCache();
        unset($GLOBALS['ap_query'], $GLOBALS['ap_post']);
    }

    public function testDueScheduledPostsPublishAndLaterOnesStay(): void
    {
        $soon = $this->insertPost('Soon', 'publish', gmdate('Y-m-d H:i:s', time() + 3600));
        $later = $this->insertPost('Later', 'publish', gmdate('Y-m-d H:i:s', time() + 86400 * 3));
        $this->assertSame('future', AP_Post::get($soon, $this->db)?->post_status);
        $this->assertSame('future', AP_Post::get($later, $this->db)?->post_status);

        $this->assertSame(0, AP_Post::publishDueScheduled($this->db, time()));
        $this->assertSame(1, AP_Post::publishDueScheduled($this->db, time() + 7200));
        $this->assertSame('publish', AP_Post::get($soon, $this->db)?->post_status);
        $this->assertSame('future', AP_Post::get($later, $this->db)?->post_status);
        $this->assertSame(0, AP_Post::publishDueScheduled($this->db, time() + 7200), 'idempotent');

        $q = AP_Rewrite::queryFromVars(['p' => $soon], $this->db, 0);
        $this->assertFalse($q->is_404);
        $this->assertSame(1, $q->post_count);
    }

    public function testPublishDueScheduledLeavesOtherStatusesAlone(): void
    {
        $draft = $this->insertPost('Draft', 'draft', gmdate('Y-m-d H:i:s', time() - 60));
        $this->assertSame(0, AP_Post::publishDueScheduled($this->db, time() + 999999));
        $this->assertSame('draft', AP_Post::get($draft, $this->db)?->post_status);
    }

    public function testUserCanViewSingularMatrix(): void
    {
        $author = $this->users['author'];
        $pub = AP_Post::get($this->insertPost('Pub', 'publish', null, $author), $this->db);
        $priv = AP_Post::get($this->insertPost('Priv', 'private', null, $author), $this->db);
        $draft = AP_Post::get($this->insertPost('Draft', 'draft', null, $author), $this->db);
        $pending = AP_Post::get($this->insertPost('Pend', 'pending', null, $author), $this->db);
        $trash = AP_Post::get($this->insertPost('Trash', 'trash', null, $author), $this->db);
        $this->assertNotNull($pub);
        $this->assertNotNull($priv);
        $this->assertNotNull($draft);
        $this->assertNotNull($pending);
        $this->assertNotNull($trash);

        $can = fn (AP_Post $p, string $who, bool $preview): bool
            => AP_Post::userCanViewSingular($p, $who === 'guest' ? 0 : $this->users[$who], $preview, $this->db);

        foreach (['guest', 'subscriber', 'author2', 'author', 'editor', 'administrator'] as $who) {
            $this->assertTrue($can($pub, $who, false), "publish visible to {$who}");
            $this->assertTrue($can($pub, $who, true), "publish visible to {$who} with preview flag");
            $this->assertFalse($can($trash, $who, true), "trash hidden from {$who}");
        }

        foreach (['guest' => false, 'subscriber' => false, 'author2' => false, 'author' => true, 'editor' => true, 'administrator' => true] as $who => $ok) {
            $this->assertSame($ok, $can($priv, $who, false), "private for {$who}");
            $this->assertSame($ok, $can($draft, $who, true), "draft preview for {$who}");
            $this->assertSame($ok, $can($pending, $who, true), "pending preview for {$who}");
            $this->assertFalse($can($draft, $who, false), "draft without preview hidden from {$who}");
            $this->assertFalse($can($pending, $who, false), "pending without preview hidden from {$who}");
        }
    }

    public function testMainQueryShowsPrivatePostOnlyToAllowedUsers(): void
    {
        $id = $this->insertPost('Secret', 'private', null, $this->users['author']);

        $guest = AP_Rewrite::queryFromVars(['p' => $id], $this->db, 0);
        $this->assertTrue($guest->is_404);
        $this->assertSame(0, $guest->post_count);

        $sub = AP_Rewrite::queryFromVars(['p' => $id], $this->db, $this->users['subscriber']);
        $this->assertTrue($sub->is_404);

        foreach (['administrator', 'editor', 'author'] as $who) {
            $q = AP_Rewrite::queryFromVars(['p' => $id], $this->db, $this->users[$who]);
            $this->assertFalse($q->is_404, "private 404 for {$who}");
            $this->assertSame(1, $q->post_count);
            $this->assertSame($id, $q->post?->ID);
            $this->assertFalse($q->is_preview);
        }
    }

    public function testPreviewOfDraftAndPending(): void
    {
        $draft = $this->insertPost('Draft', 'draft', null, $this->users['author']);
        $pending = $this->insertPost('Pend', 'pending', null, $this->users['author']);

        foreach ([$draft, $pending] as $id) {
            $noPreview = AP_Rewrite::queryFromVars(['p' => $id], $this->db, $this->users['administrator']);
            $this->assertTrue($noPreview->is_404, 'no preview flag means 404');

            $guest = AP_Rewrite::queryFromVars(['p' => $id, 'preview' => 'true'], $this->db, 0);
            $this->assertTrue($guest->is_404);
            $this->assertSame(0, $guest->post_count);

            $other = AP_Rewrite::queryFromVars(['p' => $id, 'preview' => 'true'], $this->db, $this->users['author2']);
            $this->assertTrue($other->is_404);

            foreach (['author', 'editor', 'administrator'] as $who) {
                $q = AP_Rewrite::queryFromVars(['p' => $id, 'preview' => 'true'], $this->db, $this->users[$who]);
                $this->assertFalse($q->is_404, "preview 404 for {$who}");
                $this->assertSame(1, $q->post_count);
                $this->assertSame($id, $q->post?->ID);
                $this->assertTrue($q->is_preview);
            }
        }
    }

    public function testPublishedPostAndHomeLoopUnchanged(): void
    {
        $pub = $this->insertPost('Pub', 'publish', null, $this->users['author']);
        $this->insertPost('Priv', 'private', null, $this->users['author']);
        $this->insertPost('Draft', 'draft', null, $this->users['author']);

        $q = AP_Rewrite::queryFromVars(['p' => $pub], $this->db, 0);
        $this->assertFalse($q->is_404);
        $this->assertFalse($q->is_preview);

        $missing = AP_Rewrite::queryFromVars(['p' => 999999, 'preview' => 'true'], $this->db, $this->users['administrator']);
        $this->assertTrue($missing->is_404);

        $home = AP_Rewrite::queryFromVars([], $this->db, $this->users['administrator']);
        $ids = array_map(static fn ($p) => $p instanceof AP_Post ? $p->ID : (int) $p, $home->posts);
        $this->assertSame([$pub], $ids, 'home loop stays publish-only even for admin');
    }

    public function testPreviewUrlAndEditFormButton(): void
    {
        require_once $this->root . '/ap-admin/includes/class-ap-admin-post-edit.php';
        $id = $this->insertPost('Draft', 'draft', null, $this->users['author']);
        $post = AP_Post::get($id, $this->db);
        $this->assertNotNull($post);
        $url = AP_Admin_Post_Edit::previewUrl($post, $this->db);
        $this->assertStringStartsWith('https://example.test', $url);
        $this->assertStringContainsString('p=' . $id, $url);
        $this->assertStringContainsString('preview=true', $url);

        $src = (string) file_get_contents($this->root . '/ap-admin/includes/class-ap-admin-post-edit.php');
        $this->assertStringContainsString('self::previewUrl(', $src);
        $this->assertStringContainsString('>Preview</a>', $src);
    }

    public function testBootstrapRunsScheduledPublisherAndIndexUsesMainQuery(): void
    {
        $boot = (string) file_get_contents($this->root . '/ap-includes/bootstrap.php');
        $this->assertStringContainsString('AP_Post::publishDueScheduled(', $boot);
        $index = (string) file_get_contents($this->root . '/index.php');
        $this->assertStringContainsString('AP_Rewrite::queryFromVars($apRewriteVars)', $index);
        $this->assertStringContainsString('AP_Page_Cache::skipRequest()', $index, 'non-public singulars must never be page-cached');
    }

    private function createUser(string $login, string $role): int
    {
        $this->db->insert('users', [
            'user_login' => $login,
            'user_pass' => AP_User::hashPassword('pass-' . $login),
            'user_nicename' => $login,
            'user_email' => $login . '@example.test',
            'user_url' => '',
            'user_registered' => gmdate('Y-m-d H:i:s'),
            'user_activation_key' => '',
            'user_status' => 0,
            'display_name' => $login,
        ]);
        $id = (int) $this->db->lastInsertId();
        $this->assertTrue(AP_Roles::setUserRole($id, $role, $this->db));

        return $id;
    }

    private function insertPost(string $title, string $status, ?string $date = null, int $author = 0): int
    {
        $data = [
            'post_title' => $title,
            'post_content' => 'Body of ' . $title,
            'post_status' => $status,
            'post_type' => 'post',
            'post_author' => $author,
        ];
        if ($date !== null) {
            $data['post_date'] = $date;
            $data['post_date_gmt'] = $date;
        }
        $id = AP_Post::insert($data, $this->db);
        $this->assertGreaterThan(0, $id);

        return $id;
    }
}
