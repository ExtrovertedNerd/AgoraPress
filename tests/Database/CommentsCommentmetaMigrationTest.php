<?php

/**
 * Tests for shipped migration 0004 — ap_comments, ap_commentmeta.
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
final class CommentsCommentmetaMigrationTest extends TestCase
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
        $path = AP_Migrator::defaultMigrationsPath() . '/0004_core_comments_commentmeta.php';
        $this->assertFileIsReadable($path);
        $this->assertSame(4, (int) AP_DB_VERSION);
        $this->assertGreaterThanOrEqual(4, AP_Migrator::codeTargetVersion());
    }

    public function testMigrateCreatesCommentsTables(): void
    {
        $this->assertTrue($this->migrator->needsMigration());
        $applied = $this->migrator->migrate();
        $this->assertGreaterThanOrEqual(4, count($applied));
        $this->assertSame(1, $applied[0]['version']);
        $this->assertSame(2, $applied[1]['version']);
        $this->assertSame(3, $applied[2]['version']);
        $this->assertSame(4, $applied[3]['version']);
        $this->assertStringContainsString('comment', $applied[3]['description']);
        $this->assertSame(4, $this->migrator->getCurrentVersion());
        $this->assertFalse($this->migrator->needsMigration());

        foreach (['ap_comments', 'ap_commentmeta'] as $table) {
            $name = $this->db->getVar(
                "SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?",
                [$table]
            );
            $this->assertSame($table, $name, "Expected table {$table}");
        }

        $this->assertSame('ap_comments', $this->db->comments);
        $this->assertSame('ap_commentmeta', $this->db->commentmeta);
    }

    public function testCommentsCrudRoundTrip(): void
    {
        $this->migrator->migrate();

        $this->assertSame(1, $this->db->insert('comments', [
            'comment_post_ID' => 10,
            'comment_author' => 'Tester',
            'comment_author_email' => 't@example.com',
            'comment_author_url' => '',
            'comment_author_IP' => '127.0.0.1',
            'comment_date' => '2026-08-03 12:00:00',
            'comment_date_gmt' => '2026-08-03 12:00:00',
            'comment_content' => 'Hello world',
            'comment_karma' => 0,
            'comment_approved' => '1',
            'comment_agent' => 'PHPUnit',
            'comment_type' => 'comment',
            'comment_parent' => 0,
            'user_id' => 0,
        ]));
        $commentId = (int) $this->db->lastInsertId();
        $this->assertGreaterThan(0, $commentId);

        $this->assertSame(1, $this->db->insert('commentmeta', [
            'comment_id' => $commentId,
            'meta_key' => 'source',
            'meta_value' => 'test',
        ]));

        $row = $this->db->getRow(
            'SELECT c.comment_author, c.comment_content, m.meta_value FROM ap_comments c'
            . ' INNER JOIN ap_commentmeta m ON c.comment_ID = m.comment_id'
            . ' WHERE c.comment_ID = ?',
            [$commentId]
        );
        $this->assertNotNull($row);
        $this->assertSame('Tester', $row->comment_author);
        $this->assertSame('Hello world', $row->comment_content);
        $this->assertSame('test', $row->meta_value);
    }
}
