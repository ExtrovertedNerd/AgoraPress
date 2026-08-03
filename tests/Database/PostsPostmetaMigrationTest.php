<?php

/**
 * Tests for shipped migration 0002 — ap_posts, ap_postmeta.
 *
 * Applies the real migration directory against in-memory SQLite and exercises
 * CRUD + prefix handling on posts / postmeta (after 0001 core tables).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Database;

use AP_DB;
use AP_Migrator;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_Migrator::class)]
final class PostsPostmetaMigrationTest extends TestCase
{
    private string $root;

    private AP_DB $db;

    private AP_Migrator $migrator;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        require_once $this->root . '/ap-includes/version.php';
        require_once $this->root . '/ap-includes/class-ap-db.php';
        require_once $this->root . '/ap-includes/class-ap-migrator.php';

        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');
        $this->migrator = new AP_Migrator(
            $this->db,
            AP_Migrator::defaultMigrationsPath()
        );
    }

    public function testMigrationFileExistsAndVersionMatchesConstant(): void
    {
        $path = AP_Migrator::defaultMigrationsPath() . '/0002_core_posts_postmeta.php';
        $this->assertFileIsReadable($path);
        // Latest shipped schema is ≥ 2 (posts/postmeta); may be higher as later migrations land.
        $this->assertGreaterThanOrEqual(2, (int) AP_DB_VERSION);
        $this->assertGreaterThanOrEqual(2, AP_Migrator::codeTargetVersion());
    }

    public function testMigrateCreatesPostsAndPostmeta(): void
    {
        $this->assertTrue($this->migrator->needsMigration());
        $this->assertSame(0, $this->migrator->getCurrentVersion());

        $applied = $this->migrator->migrate();
        $this->assertNotSame([], $applied);
        $this->assertGreaterThanOrEqual(2, count($applied));
        $this->assertSame(1, $applied[0]['version']);
        $this->assertSame(2, $applied[1]['version']);
        $this->assertStringContainsString('posts', $applied[1]['description']);
        $this->assertGreaterThanOrEqual(2, $this->migrator->getCurrentVersion());
        $this->assertFalse($this->migrator->needsMigration());
        $this->assertSame([], $this->migrator->migrate());

        foreach (['ap_posts', 'ap_postmeta'] as $table) {
            $name = $this->db->getVar(
                "SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?",
                [$table]
            );
            $this->assertSame($table, $name, "Expected table {$table}");
        }

        // Prior core tables still present.
        foreach (['ap_options', 'ap_users', 'ap_usermeta'] as $table) {
            $name = $this->db->getVar(
                "SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?",
                [$table]
            );
            $this->assertSame($table, $name, "Expected table {$table}");
        }

        $this->assertSame('ap_posts', $this->db->posts);
        $this->assertSame('ap_postmeta', $this->db->postmeta);
    }

    public function testPostsAndPostmetaCrudRoundTrip(): void
    {
        $this->migrator->migrate();

        $this->assertSame(1, $this->db->insert('posts', [
            'post_author' => 1,
            'post_content' => 'Hello from AgoraPress.',
            'post_title' => 'First Post',
            'post_excerpt' => 'A short excerpt.',
            'post_status' => 'publish',
            'comment_status' => 'open',
            'ping_status' => 'closed',
            'post_password' => '',
            'post_name' => 'first-post',
            'to_ping' => '',
            'pinged' => '',
            'post_content_filtered' => '',
            'post_parent' => 0,
            'guid' => 'https://example.test/?p=1',
            'menu_order' => 0,
            'post_type' => 'post',
            'post_mime_type' => '',
            'comment_count' => 0,
        ]));
        $postId = (int) $this->db->lastInsertId();
        $this->assertGreaterThan(0, $postId);

        $post = $this->db->getRow(
            'SELECT post_title, post_status, post_type, post_name, post_author FROM '
            . $this->db->quoteIdentifier($this->db->posts)
            . ' WHERE ID = ?',
            [$postId]
        );
        $this->assertNotNull($post);
        $this->assertSame('First Post', $post->post_title);
        $this->assertSame('publish', $post->post_status);
        $this->assertSame('post', $post->post_type);
        $this->assertSame('first-post', $post->post_name);
        $this->assertSame(1, (int) $post->post_author);

        // Hierarchical page with password + draft status.
        $this->assertSame(1, $this->db->insert('posts', [
            'post_author' => 1,
            'post_content' => 'Page body',
            'post_title' => 'About',
            'post_excerpt' => '',
            'post_status' => 'draft',
            'comment_status' => 'closed',
            'ping_status' => 'closed',
            'post_password' => 'secret',
            'post_name' => 'about',
            'to_ping' => '',
            'pinged' => '',
            'post_content_filtered' => '',
            'post_parent' => $postId,
            'guid' => 'https://example.test/?page_id=2',
            'menu_order' => 1,
            'post_type' => 'page',
            'post_mime_type' => '',
            'comment_count' => 0,
        ]));
        $pageId = (int) $this->db->lastInsertId();
        $page = $this->db->getRow(
            'SELECT post_type, post_status, post_password, post_parent, menu_order FROM '
            . $this->db->quoteIdentifier($this->db->posts)
            . ' WHERE ID = ?',
            [$pageId]
        );
        $this->assertNotNull($page);
        $this->assertSame('page', $page->post_type);
        $this->assertSame('draft', $page->post_status);
        $this->assertSame('secret', $page->post_password);
        $this->assertSame($postId, (int) $page->post_parent);
        $this->assertSame(1, (int) $page->menu_order);

        // Meta: sticky flag + custom key (WP-style sticky uses options later; meta works for CPTs).
        $this->assertSame(1, $this->db->insert('postmeta', [
            'post_id' => $postId,
            'meta_key' => '_thumbnail_id',
            'meta_value' => '42',
        ]));
        $this->assertSame(1, $this->db->insert('postmeta', [
            'post_id' => $postId,
            'meta_key' => 'views',
            'meta_value' => '10',
        ]));
        $this->assertSame(
            '42',
            $this->db->getVar(
                'SELECT meta_value FROM ' . $this->db->quoteIdentifier($this->db->postmeta)
                . ' WHERE post_id = ? AND meta_key = ?',
                [$postId, '_thumbnail_id']
            )
        );

        $this->assertSame(1, $this->db->update(
            'posts',
            ['post_status' => 'private', 'post_title' => 'First Post (private)'],
            ['ID' => $postId]
        ));
        $this->assertSame(
            'private',
            $this->db->getVar(
                'SELECT post_status FROM ' . $this->db->quoteIdentifier($this->db->posts)
                . ' WHERE ID = ?',
                [$postId]
            )
        );

        $this->assertSame(1, $this->db->update(
            'postmeta',
            ['meta_value' => '99'],
            ['post_id' => $postId, 'meta_key' => 'views']
        ));
        $this->assertSame(
            '99',
            $this->db->getVar(
                'SELECT meta_value FROM ' . $this->db->quoteIdentifier($this->db->postmeta)
                . ' WHERE post_id = ? AND meta_key = ?',
                [$postId, 'views']
            )
        );

        $this->assertSame(1, $this->db->delete('postmeta', [
            'post_id' => $postId,
            'meta_key' => '_thumbnail_id',
        ]));
        $this->assertNull(
            $this->db->getVar(
                'SELECT meta_value FROM ' . $this->db->quoteIdentifier($this->db->postmeta)
                . ' WHERE post_id = ? AND meta_key = ?',
                [$postId, '_thumbnail_id']
            )
        );
    }

    public function testCustomTablePrefixOnPostsTables(): void
    {
        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $db = AP_DB::fromPdo($pdo, 'sqlite', 'site_');
        $m = new AP_Migrator($db, AP_Migrator::defaultMigrationsPath());
        $m->migrate();

        $this->assertSame('site_posts', $db->posts);
        $this->assertSame('site_postmeta', $db->postmeta);

        foreach (['site_posts', 'site_postmeta', 'site_schema_migrations'] as $table) {
            $name = $db->getVar(
                "SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?",
                [$table]
            );
            $this->assertSame($table, $name);
        }

        $this->assertSame(1, $db->insert('posts', [
            'post_author' => 1,
            'post_content' => 'Prefixed',
            'post_title' => 'Prefixed Post',
            'post_excerpt' => '',
            'post_status' => 'publish',
            'comment_status' => 'open',
            'ping_status' => 'open',
            'post_password' => '',
            'post_name' => 'prefixed-post',
            'to_ping' => '',
            'pinged' => '',
            'post_content_filtered' => '',
            'post_parent' => 0,
            'guid' => '',
            'menu_order' => 0,
            'post_type' => 'post',
            'post_mime_type' => '',
            'comment_count' => 0,
        ]));
        $this->assertSame(
            'Prefixed Post',
            $db->getVar(
                'SELECT post_title FROM ' . $db->quoteIdentifier($db->posts)
                . ' WHERE post_name = ?',
                ['prefixed-post']
            )
        );
    }

    public function testMysqlAndPgsqlDdlBranchesAreNonEmpty(): void
    {
        $path = AP_Migrator::defaultMigrationsPath() . '/0002_core_posts_postmeta.php';
        $src = (string) file_get_contents($path);
        $this->assertStringContainsString('ENGINE=InnoDB', $src);
        $this->assertStringContainsString('utf8mb4_unicode_ci', $src);
        $this->assertStringContainsString('BIGSERIAL', $src);
        $this->assertStringContainsString('AUTOINCREMENT', $src);
        $this->assertStringContainsString('post_title', $src);
        $this->assertStringContainsString('post_status', $src);
        $this->assertStringContainsString('post_type', $src);
        $this->assertStringContainsString('post_parent', $src);
        $this->assertStringContainsString('meta_id', $src);
        $this->assertStringContainsString('post_id', $src);
        $this->assertStringContainsString('type_status_date', $src);
    }
}
