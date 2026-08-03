<?php

/**
 * Tests for shipped migration 0003 — ap_terms, ap_term_taxonomy, ap_term_relationships.
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
final class TermsTaxonomyMigrationTest extends TestCase
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
        $path = AP_Migrator::defaultMigrationsPath() . '/0003_core_terms_taxonomies.php';
        $this->assertFileIsReadable($path);
        // Taxonomies ship at schema v3; later migrations may bump AP_DB_VERSION.
        $this->assertGreaterThanOrEqual(3, (int) AP_DB_VERSION);
        $this->assertGreaterThanOrEqual(3, AP_Migrator::codeTargetVersion());
    }

    public function testMigrateCreatesTermsTables(): void
    {
        $this->assertTrue($this->migrator->needsMigration());
        $applied = $this->migrator->migrate();
        $this->assertGreaterThanOrEqual(3, count($applied));
        $this->assertSame(1, $applied[0]['version']);
        $this->assertSame(2, $applied[1]['version']);
        $this->assertSame(3, $applied[2]['version']);
        $this->assertStringContainsString('term', $applied[2]['description']);
        // Full migrate applies all shipped versions (terms = 3; later migrations may follow).
        $this->assertGreaterThanOrEqual(3, $this->migrator->getCurrentVersion());
        $this->assertFalse($this->migrator->needsMigration());

        foreach (['ap_terms', 'ap_term_taxonomy', 'ap_term_relationships'] as $table) {
            $name = $this->db->getVar(
                "SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?",
                [$table]
            );
            $this->assertSame($table, $name, "Expected table {$table}");
        }

        $this->assertSame('ap_terms', $this->db->terms);
        $this->assertSame('ap_term_taxonomy', $this->db->term_taxonomy);
        $this->assertSame('ap_term_relationships', $this->db->term_relationships);
    }

    public function testTermsTaxonomyCrudRoundTrip(): void
    {
        $this->migrator->migrate();

        $this->assertSame(1, $this->db->insert('terms', [
            'name' => 'News',
            'slug' => 'news',
            'term_group' => 0,
        ]));
        $termId = (int) $this->db->lastInsertId();
        $this->assertGreaterThan(0, $termId);

        $this->assertSame(1, $this->db->insert('term_taxonomy', [
            'term_id' => $termId,
            'taxonomy' => 'category',
            'description' => 'News posts',
            'parent' => 0,
            'count' => 0,
        ]));
        $ttId = (int) $this->db->lastInsertId();
        $this->assertGreaterThan(0, $ttId);

        $this->assertSame(1, $this->db->insert('term_relationships', [
            'object_id' => 42,
            'term_taxonomy_id' => $ttId,
            'term_order' => 0,
        ]));

        $row = $this->db->getRow(
            'SELECT t.name, tt.taxonomy, tr.object_id FROM ap_terms t'
            . ' INNER JOIN ap_term_taxonomy tt ON t.term_id = tt.term_id'
            . ' INNER JOIN ap_term_relationships tr ON tt.term_taxonomy_id = tr.term_taxonomy_id'
            . ' WHERE t.term_id = ?',
            [$termId]
        );
        $this->assertNotNull($row);
        $this->assertSame('News', $row->name);
        $this->assertSame('category', $row->taxonomy);
        $this->assertSame(42, (int) $row->object_id);
    }

    public function testCustomPrefixOnTermsTables(): void
    {
        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $db = AP_DB::fromPdo($pdo, 'sqlite', 'site2_');
        $m = new AP_Migrator($db, AP_Migrator::defaultMigrationsPath());
        $m->migrate();

        foreach (['site2_terms', 'site2_term_taxonomy', 'site2_term_relationships'] as $table) {
            $name = $db->getVar(
                "SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?",
                [$table]
            );
            $this->assertSame($table, $name);
        }
        $this->assertSame('site2_terms', $db->terms);
    }
}
