<?php

/**
 * AP-013 comment pagination and AP-015 batched list lookups.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Performance;

use AP_Comment;
use AP_Comment_Pager;
use AP_DB;
use AP_List_Prefetch;
use AP_Migrator;
use AP_Options;
use AP_Post;
use AP_Taxonomy;
use AP_User;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_List_Prefetch::class)]
#[CoversClass(AP_Comment_Pager::class)]
final class ListPrefetchTest extends TestCase
{
    private string $root;

    private AP_DB $db;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        foreach (
            [
                'version', 'hooks', 'class-ap-db', 'class-ap-migrator', 'class-ap-options', 'class-ap-user',
                'class-ap-post', 'class-ap-taxonomy', 'class-ap-comment', 'class-ap-list-prefetch',
                'class-ap-comment-pager', 'functions',
            ] as $f
        ) {
            require_once $this->root . '/ap-includes/' . $f . '.php';
        }
        AP_Options::flushCache();
        AP_Post::resetRegistry();
        AP_Taxonomy::resetRegistry();
        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');
        (new AP_Migrator($this->db, AP_Migrator::defaultMigrationsPath()))->migrate();
        $GLOBALS['apdb'] = $this->db;
        AP_Post::ensureBuiltins();
        AP_Taxonomy::ensureBuiltins();
    }

    protected function tearDown(): void
    {
        AP_Options::flushCache();
        AP_Post::resetRegistry();
        AP_Taxonomy::resetRegistry();
        unset($GLOBALS['apdb']);
    }

    public function testPostCountsMatchPerUserCountInOneQuery(): void
    {
        $a = $this->user('alice');
        $b = $this->user('bob');
        $c = $this->user('carol');
        foreach ([[$a, 'publish'], [$a, 'draft'], [$a, 'trash'], [$b, 'publish']] as [$uid, $status]) {
            $this->post('P' . $uid . $status, $status, $uid);
        }
        $this->db->resetQueryLog();
        $before = $this->db->getNumQueries();
        $counts = AP_List_Prefetch::postCounts([$a, $b, $c, $a], $this->db);
        $this->assertSame(1, $this->db->getNumQueries() - $before, 'one query');
        foreach ([$a, $b, $c] as $uid) {
            $this->assertSame(AP_User::countPosts($uid, $this->db), $counts[$uid] ?? -1, "count for {$uid}");
        }
        $this->assertSame([], AP_List_Prefetch::postCounts([], $this->db));
    }

    public function testTermNamesMatchGetObjectTermsInOneQuery(): void
    {
        $p1 = $this->post('One', 'publish', 0);
        $p2 = $this->post('Two', 'publish', 0);
        $p3 = $this->post('Three', 'publish', 0);
        AP_Taxonomy::setObjectTerms($p1, ['zeta', 'alpha'], 'post_tag', false, $this->db);
        AP_Taxonomy::setObjectTerms($p2, ['alpha'], 'post_tag', false, $this->db);
        $before = $this->db->getNumQueries();
        $names = AP_List_Prefetch::termNames([$p1, $p2, $p3], 'post_tag', $this->db);
        $this->assertSame(1, $this->db->getNumQueries() - $before, 'one query');
        foreach ([$p1, $p2, $p3] as $pid) {
            $expected = AP_Taxonomy::getObjectTerms($pid, 'post_tag', ['fields' => 'names'], $this->db);
            $this->assertSame(array_values($expected), $names[$pid] ?? [], "terms for {$pid}");
        }
        $this->assertSame([], AP_List_Prefetch::termNames([], 'post_tag', $this->db));
    }

    public function testUsersLoadsManyInOneQuery(): void
    {
        $a = $this->user('dora');
        $b = $this->user('ed');
        $before = $this->db->getNumQueries();
        $users = AP_List_Prefetch::users([$a, $b, 999, $a], $this->db);
        $this->assertSame(1, $this->db->getNumQueries() - $before);
        $this->assertSame([$a, $b], array_keys($users));
        $this->assertInstanceOf(AP_User::class, $users[$a]);
        $this->assertSame('dora', $users[$a]->user_login);
        $this->assertSame([], AP_List_Prefetch::users([], $this->db));
    }

    public function testCommentPagerPagesApprovedCommentsOldestFirst(): void
    {
        $pid = $this->post('Talk', 'publish', 0);
        for ($i = 1; $i <= 7; $i++) {
            AP_Comment::insert([
                'comment_post_ID' => $pid,
                'comment_author' => 'c' . $i,
                'comment_content' => 'Comment ' . $i,
                'comment_approved' => '1',
                'comment_date' => sprintf('2026-01-01 00:00:%02d', $i),
                'comment_date_gmt' => sprintf('2026-01-01 00:00:%02d', $i),
            ], $this->db, ['check_open' => false]);
        }
        AP_Comment::insert([
            'comment_post_ID' => $pid, 'comment_author' => 'spam', 'comment_content' => 'held',
            'comment_approved' => '0',
        ], $this->db, ['check_open' => false]);

        $p1 = AP_Comment_Pager::page($pid, 1, 3, $this->db);
        $this->assertSame(7, $p1['total']);
        $this->assertSame(3, $p1['pages']);
        $this->assertSame(1, $p1['page']);
        $this->assertSame(['Comment 1', 'Comment 2', 'Comment 3'], $this->contents($p1['comments']));

        $p3 = AP_Comment_Pager::page($pid, 3, 3, $this->db);
        $this->assertSame(['Comment 7'], $this->contents($p3['comments']));

        $clamped = AP_Comment_Pager::page($pid, 99, 3, $this->db);
        $this->assertSame(3, $clamped['page']);
        $this->assertSame(['Comment 7'], $this->contents($clamped['comments']));
        $this->assertSame(1, AP_Comment_Pager::page($pid, -5, 3, $this->db)['page']);

        $all = AP_Comment_Pager::page($pid, 2, 0, $this->db);
        $this->assertSame(1, $all['pages']);
        $this->assertCount(7, $all['comments']);

        $none = AP_Comment_Pager::page($this->post('Quiet', 'publish', 0), 1, 3, $this->db);
        $this->assertSame(0, $none['total']);
        $this->assertSame(1, $none['pages']);
        $this->assertSame([], $none['comments']);
    }

    public function testPerPageOptionDefaultsTo50(): void
    {
        $this->assertSame(50, AP_Comment_Pager::perPage($this->db));
        AP_Options::update('comments_per_page', '5', $this->db);
        AP_Options::flushCache();
        $this->assertSame(5, AP_Comment_Pager::perPage($this->db));
        AP_Options::update('comments_per_page', '-3', $this->db);
        AP_Options::flushCache();
        $this->assertSame(0, AP_Comment_Pager::perPage($this->db));
    }

    public function testAgoraThemeAndListTablesUseBatchedHelpers(): void
    {
        $theme = (string) file_get_contents($this->root . '/ap-content/themes/agora/comments.php');
        $this->assertStringContainsString('AP_Comment_Pager::page(', $theme);
        $this->assertStringContainsString('cpage', $theme);
        $posts = (string) file_get_contents($this->root . '/ap-admin/includes/class-ap-posts-list-table.php');
        $this->assertStringContainsString('AP_List_Prefetch::termNames(', $posts);
        $this->assertStringContainsString('AP_List_Prefetch::users(', $posts);
        $users = (string) file_get_contents($this->root . '/ap-admin/includes/class-ap-users-list-table.php');
        $this->assertStringContainsString('AP_List_Prefetch::postCounts(', $users);
    }

    /**
     * @param list<AP_Comment> $comments
     *
     * @return list<string>
     */
    private function contents(array $comments): array
    {
        return array_map(static fn (AP_Comment $c): string => $c->comment_content, $comments);
    }

    private function user(string $login): int
    {
        $this->db->insert('users', [
            'user_login' => $login, 'user_pass' => 'x', 'user_nicename' => $login,
            'user_email' => $login . '@example.test', 'user_url' => '',
            'user_registered' => gmdate('Y-m-d H:i:s'), 'user_activation_key' => '',
            'user_status' => 0, 'display_name' => $login,
        ]);

        return (int) $this->db->lastInsertId();
    }

    private function post(string $title, string $status, int $author): int
    {
        return AP_Post::insert([
            'post_title' => $title, 'post_content' => 'x', 'post_status' => $status,
            'post_type' => 'post', 'post_author' => $author,
        ], $this->db);
    }
}
