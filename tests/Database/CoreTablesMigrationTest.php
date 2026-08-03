<?php

/**
 * Tests for shipped migration 0001 — ap_options, ap_users, ap_usermeta.
 *
 * Applies the real migration directory against in-memory SQLite and exercises
 * CRUD + prefix handling on the three core tables.
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
final class CoreTablesMigrationTest extends TestCase
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
        $path = AP_Migrator::defaultMigrationsPath() . '/0001_core_options_users.php';
        $this->assertFileIsReadable($path);
        $this->assertSame(1, (int) AP_DB_VERSION);
        $this->assertGreaterThanOrEqual(1, AP_Migrator::codeTargetVersion());
    }

    public function testMigrateCreatesOptionsUsersUsermeta(): void
    {
        $this->assertTrue($this->migrator->needsMigration());
        $this->assertSame(0, $this->migrator->getCurrentVersion());

        $applied = $this->migrator->migrate();
        $this->assertNotSame([], $applied);
        $this->assertSame(1, $applied[0]['version']);
        $this->assertSame(1, $this->migrator->getCurrentVersion());
        $this->assertFalse($this->migrator->needsMigration());
        $this->assertSame([], $this->migrator->migrate());

        foreach (['ap_options', 'ap_users', 'ap_usermeta'] as $table) {
            $name = $this->db->getVar(
                "SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?",
                [$table]
            );
            $this->assertSame($table, $name, "Expected table {$table}");
        }

        $this->assertSame('ap_options', $this->db->options);
        $this->assertSame('ap_users', $this->db->users);
        $this->assertSame('ap_usermeta', $this->db->usermeta);
    }

    public function testOptionsUsersUsermetaCrudRoundTrip(): void
    {
        $this->migrator->migrate();

        $this->assertSame(1, $this->db->insert('options', [
            'option_name' => 'blogname',
            'option_value' => 'AgoraPress Test',
            'autoload' => 'yes',
        ]));
        $this->assertSame(
            'AgoraPress Test',
            $this->db->getVar(
                'SELECT option_value FROM ' . $this->db->quoteIdentifier($this->db->options)
                . ' WHERE option_name = ?',
                ['blogname']
            )
        );

        $this->assertSame(1, $this->db->insert('users', [
            'user_login' => 'admin',
            'user_pass' => '$argon2id$example',
            'user_nicename' => 'admin',
            'user_email' => 'admin@example.com',
            'display_name' => 'Site Admin',
        ]));
        $userId = (int) $this->db->lastInsertId();
        $this->assertGreaterThan(0, $userId);

        $user = $this->db->getRow(
            'SELECT user_login, user_email, display_name FROM '
            . $this->db->quoteIdentifier($this->db->users)
            . ' WHERE ID = ?',
            [$userId]
        );
        $this->assertNotNull($user);
        $this->assertSame('admin', $user->user_login);
        $this->assertSame('admin@example.com', $user->user_email);
        $this->assertSame('Site Admin', $user->display_name);

        $this->assertSame(1, $this->db->insert('usermeta', [
            'user_id' => $userId,
            'meta_key' => 'nickname',
            'meta_value' => 'Ada',
        ]));
        $this->assertSame(
            'Ada',
            $this->db->getVar(
                'SELECT meta_value FROM ' . $this->db->quoteIdentifier($this->db->usermeta)
                . ' WHERE user_id = ? AND meta_key = ?',
                [$userId, 'nickname']
            )
        );

        $this->assertSame(1, $this->db->update(
            'options',
            ['option_value' => 'Renamed Site'],
            ['option_name' => 'blogname']
        ));
        $this->assertSame(
            'Renamed Site',
            $this->db->getVar(
                'SELECT option_value FROM ' . $this->db->quoteIdentifier($this->db->options)
                . ' WHERE option_name = ?',
                ['blogname']
            )
        );

        $this->assertSame(1, $this->db->delete('usermeta', [
            'user_id' => $userId,
            'meta_key' => 'nickname',
        ]));
        $this->assertNull(
            $this->db->getVar(
                'SELECT meta_value FROM ' . $this->db->quoteIdentifier($this->db->usermeta)
                . ' WHERE user_id = ? AND meta_key = ?',
                [$userId, 'nickname']
            )
        );
    }

    public function testCustomTablePrefixOnCoreTables(): void
    {
        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $db = AP_DB::fromPdo($pdo, 'sqlite', 'site_');
        $m = new AP_Migrator($db, AP_Migrator::defaultMigrationsPath());
        $m->migrate();

        $this->assertSame('site_options', $db->options);
        $this->assertSame('site_users', $db->users);
        $this->assertSame('site_usermeta', $db->usermeta);

        foreach (['site_options', 'site_users', 'site_usermeta', 'site_schema_migrations'] as $table) {
            $name = $db->getVar(
                "SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?",
                [$table]
            );
            $this->assertSame($table, $name);
        }

        $this->assertSame(1, $db->insert('options', [
            'option_name' => 'siteurl',
            'option_value' => 'https://example.test',
            'autoload' => 'yes',
        ]));
        $this->assertSame(
            'https://example.test',
            $db->getVar(
                'SELECT option_value FROM ' . $db->quoteIdentifier($db->options)
                . ' WHERE option_name = ?',
                ['siteurl']
            )
        );
    }

    public function testOptionsOptionNameIsUnique(): void
    {
        $this->migrator->migrate();

        $this->db->insert('options', [
            'option_name' => 'unique_key',
            'option_value' => 'one',
            'autoload' => 'yes',
        ]);

        $result = $this->db->insert('options', [
            'option_name' => 'unique_key',
            'option_value' => 'two',
            'autoload' => 'yes',
        ]);
        $this->assertFalse($result);
    }

    public function testMysqlAndPgsqlDdlBranchesAreNonEmpty(): void
    {
        // Ensure multi-driver SQL is present without needing live MySQL/PG.
        $path = AP_Migrator::defaultMigrationsPath() . '/0001_core_options_users.php';
        $src = (string) file_get_contents($path);
        $this->assertStringContainsString('ENGINE=InnoDB', $src);
        $this->assertStringContainsString('utf8mb4_unicode_ci', $src);
        $this->assertStringContainsString('BIGSERIAL', $src);
        $this->assertStringContainsString('AUTOINCREMENT', $src);
        $this->assertStringContainsString('option_name', $src);
        $this->assertStringContainsString('user_login', $src);
        $this->assertStringContainsString('umeta_id', $src);
        $this->assertStringContainsString('user_pass', $src);
        $this->assertStringContainsString('autoload', $src);
    }
}
