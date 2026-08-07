<?php

/**
 * Tests for AP_Migrator — versioned schema migrations.
 *
 * Uses in-memory SQLite and a temporary migrations directory.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Database;

use AP_DB;
use AP_Migrator;
use AP_Migrator_Exception;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_Migrator::class)]
final class MigratorTest extends TestCase
{
    private string $root;

    private AP_DB $db;

    private string $migrationsDir;

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

        $this->migrationsDir = sys_get_temp_dir() . '/ap-migrations-' . uniqid('', true);
        $this->assertTrue(mkdir($this->migrationsDir, 0700, true));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->migrationsDir)) {
            foreach (glob($this->migrationsDir . '/*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($this->migrationsDir);
        }
    }

    private function migrator(): AP_Migrator
    {
        return new AP_Migrator($this->db, $this->migrationsDir);
    }

    /**
     * Write a simple migration that creates a marker table for its version.
     */
    private function writeMigration(int $version, string $slug, string $description = ''): void
    {
        if ($description === '') {
            $description = "Migration {$version}";
        }
        $table = 'marker_' . $version;
        $code = <<<PHP
<?php
declare(strict_types=1);

return new class implements AP_Migration {
    public function version(): int
    {
        return {$version};
    }

    public function description(): string
    {
        return {$this->exportString($description)};
    }

    public function up(AP_DB \$db): void
    {
        \$name = \$db->table({$this->exportString($table)});
        \$q = \$db->quoteIdentifier(\$name);
        \$ok = \$db->query("CREATE TABLE {\$q} (id INTEGER PRIMARY KEY, note TEXT)");
        if (\$ok === false) {
            throw new RuntimeException('create marker failed: ' . (\$db->lastError() ?? ''));
        }
        \$db->insert({$this->exportString($table)}, ['note' => {$this->exportString('v' . $version)}]);
    }
};

PHP;
        $padded = str_pad((string) $version, 4, '0', STR_PAD_LEFT);
        $path = $this->migrationsDir . '/' . $padded . '_' . $slug . '.php';
        $this->assertNotFalse(file_put_contents($path, $code));
    }

    private function exportString(string $value): string
    {
        return var_export($value, true);
    }

    public function testClassesAndHelperAvailable(): void
    {
        $this->assertTrue(interface_exists('AP_Migration', false));
        $this->assertTrue(class_exists('AP_Migrator', false));
        $this->assertTrue(class_exists('AP_Migrator_Exception', false));
        $this->assertTrue(function_exists('ap_migrator'));
    }

    public function testEmptyDatabaseIsVersionZero(): void
    {
        $m = $this->migrator();
        $this->assertSame(0, $m->getCurrentVersion());
        $this->assertSame([], $m->getAppliedVersions());
        $this->assertFalse($m->needsMigration());
        $this->assertSame([], $m->pending());
        $this->assertSame('ap_schema_migrations', $m->registryTable());
        $this->assertSame('ap_schema_migrations', $this->db->schema_migrations);
    }

    public function testDiscoverAndApplyPendingMigrations(): void
    {
        $this->writeMigration(1, 'first', 'Create first marker');
        $this->writeMigration(2, 'second', 'Create second marker');

        $m = $this->migrator();
        $this->assertTrue($m->needsMigration());
        $this->assertCount(2, $m->pending());
        $this->assertSame(2, $m->getAvailableTargetVersion());

        $applied = $m->migrate();
        $this->assertCount(2, $applied);
        $this->assertSame(1, $applied[0]['version']);
        $this->assertSame('Create first marker', $applied[0]['description']);
        $this->assertSame(2, $applied[1]['version']);

        $this->assertSame(2, $m->getCurrentVersion());
        $this->assertSame([1, 2], $m->getAppliedVersions());
        $this->assertFalse($m->needsMigration());
        $this->assertSame([], $m->migrate());

        $note = $this->db->getVar(
            'SELECT note FROM ' . $this->db->quoteIdentifier($this->db->table('marker_1'))
        );
        $this->assertSame('v1', $note);
        $note2 = $this->db->getVar(
            'SELECT note FROM ' . $this->db->quoteIdentifier($this->db->table('marker_2'))
        );
        $this->assertSame('v2', $note2);
    }

    public function testMigrateToCeilingStopsEarly(): void
    {
        $this->writeMigration(1, 'one');
        $this->writeMigration(2, 'two');
        $this->writeMigration(3, 'three');

        $m = $this->migrator();
        $applied = $m->migrate(2);
        $this->assertCount(2, $applied);
        $this->assertSame(2, $m->getCurrentVersion());
        $this->assertTrue($m->needsMigration());
        $this->assertCount(1, $m->pending());

        $applied = $m->migrate();
        $this->assertCount(1, $applied);
        $this->assertSame(3, $applied[0]['version']);
        $this->assertSame(3, $m->getCurrentVersion());
    }

    public function testFailedMigrationIsNotRecorded(): void
    {
        $this->writeMigration(1, 'ok');

        $failCode = <<<'PHP'
<?php
declare(strict_types=1);

return new class implements AP_Migration {
    public function version(): int
    {
        return 2;
    }

    public function description(): string
    {
        return 'Always fails';
    }

    public function up(AP_DB $db): void
    {
        throw new RuntimeException('intentional failure');
    }
};

PHP;
        file_put_contents($this->migrationsDir . '/0002_fail.php', $failCode);

        $m = $this->migrator();
        try {
            $m->migrate();
            $this->fail('Expected AP_Migrator_Exception');
        } catch (AP_Migrator_Exception $e) {
            $this->assertStringContainsString('Migration 2 failed', $e->getMessage());
            $this->assertStringContainsString('intentional failure', $e->getMessage());
        }

        $this->assertSame(1, $m->getCurrentVersion());
        $this->assertSame([1], $m->getAppliedVersions());
        $this->assertTrue($m->needsMigration());
    }

    public function testVersionMismatchInFileThrows(): void
    {
        $code = <<<'PHP'
<?php
declare(strict_types=1);

return new class implements AP_Migration {
    public function version(): int
    {
        return 99;
    }

    public function description(): string
    {
        return 'bad';
    }

    public function up(AP_DB $db): void
    {
    }
};

PHP;
        file_put_contents($this->migrationsDir . '/0001_mismatch.php', $code);

        $m = $this->migrator();
        $this->expectException(AP_Migrator_Exception::class);
        $this->expectExceptionMessage('version mismatch');
        $m->discover();
    }

    public function testCustomPrefixOnRegistry(): void
    {
        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $db = AP_DB::fromPdo($pdo, 'sqlite', 'site_');
        $this->writeMigration(1, 'pfx');

        $m = new AP_Migrator($db, $this->migrationsDir);
        $this->assertSame('site_schema_migrations', $m->registryTable());
        $m->migrate();
        $this->assertSame(1, $m->getCurrentVersion());

        $exists = $db->getVar(
            "SELECT name FROM sqlite_master WHERE type='table' AND name = ?",
            ['site_schema_migrations']
        );
        $this->assertSame('site_schema_migrations', $exists);
    }

    public function testDefaultMigrationsPathExists(): void
    {
        $path = AP_Migrator::defaultMigrationsPath();
        $this->assertDirectoryExists($path);
        $this->assertStringContainsString('schema/migrations', $path);
    }

    public function testCodeTargetVersionMatchesConstant(): void
    {
        $this->assertSame((int) AP_DB_VERSION, AP_Migrator::codeTargetVersion());
    }

    public function testApMigratorHelperUsesProvidedDb(): void
    {
        $m = ap_migrator($this->db);
        $this->assertInstanceOf(AP_Migrator::class, $m);
        $this->assertSame(
            AP_Migrator::defaultMigrationsPath(),
            $m->getMigrationsPath()
        );
    }

    public function testBootstrapLoadsMigratorWithoutConnecting(): void
    {
        $configPath = $this->root . '/ap-config.php';
        $created = false;

        if (!is_readable($configPath)) {
            $sample = $this->root . '/ap-config-sample.php';
            $this->assertFileIsReadable($sample);
            $this->assertTrue(copy($sample, $configPath));
            $created = true;
        }

        $tmpScript = sys_get_temp_dir() . '/apmig-bootstrap-' . uniqid('', true) . '.php';

        try {
            $root = $this->root . '/';
            $code = "<?php\ndeclare(strict_types=1);\n"
                . "define('AP_ABSPATH', " . var_export($root, true) . ");\n"
                . "require AP_ABSPATH . 'ap-includes/bootstrap.php';\n"
                . "ap_bootstrap();\n"
                . "echo class_exists('AP_Migrator', false) ? \"MIG_OK\\n\" : \"MIG_MISSING\\n\";\n"
                . "echo interface_exists('AP_Migration', false) ? \"IF_OK\\n\" : \"IF_MISSING\\n\";\n"
                . "echo function_exists('ap_migrator') ? \"FN_OK\\n\" : \"FN_MISSING\\n\";\n"
                . "\$connected = isset(\$GLOBALS['apdb']) && \$GLOBALS['apdb'] instanceof AP_DB;\n"
                . "echo \$connected ? \"CONNECTED\\n\" : \"LAZY\\n\";\n";
            file_put_contents($tmpScript, $code);

            $php = PHP_BINARY !== '' ? PHP_BINARY : 'php';
            $cmd = escapeshellarg($php)
                . ' -d display_errors=1 -d error_reporting=E_ALL '
                . escapeshellarg($tmpScript)
                . ' 2>&1';

            $output = [];
            $exit = 0;
            exec($cmd, $output, $exit);
            $body = implode("\n", $output);

            $this->assertSame(0, $exit, "Output:\n{$body}");
            $this->assertStringContainsString('MIG_OK', $body);
            $this->assertStringContainsString('IF_OK', $body);
            $this->assertStringContainsString('FN_OK', $body);
            $this->assertStringContainsString('LAZY', $body);
        } finally {
            if (is_file($tmpScript)) {
                unlink($tmpScript);
            }
            if ($created && is_file($configPath)) {
                unlink($configPath);
            }
        }
    }

    public function testShippedCoreOptionsUsersMigrationIsDiscoverable(): void
    {
        $m = new AP_Migrator($this->db, AP_Migrator::defaultMigrationsPath());
        $found = $m->discover();
        $this->assertNotSame([], $found);
        $this->assertGreaterThanOrEqual(12, $m->getAvailableTargetVersion());
        $this->assertSame(12, (int) AP_DB_VERSION);
        $this->assertSame(1, $found[0]->version());
        $this->assertStringContainsString('options', $found[0]->description());
        $this->assertSame(2, $found[1]->version());
        $this->assertStringContainsString('posts', $found[1]->description());
        $this->assertSame(3, $found[2]->version());
        $this->assertStringContainsString('term', $found[2]->description());
        $this->assertSame(4, $found[3]->version());
        $this->assertStringContainsString('comment', $found[3]->description());
        $this->assertSame(5, $found[4]->version());
        $this->assertStringContainsString('forum', strtolower($found[4]->description()));
        $this->assertSame(6, $found[5]->version());
        $this->assertStringContainsString('attachment', strtolower($found[5]->description()));
        $this->assertSame(7, $found[6]->version());
        $this->assertStringContainsString('permission', strtolower($found[6]->description()));
        $this->assertSame(8, $found[7]->version());
        $this->assertStringContainsString('moderation', strtolower($found[7]->description()));
        $this->assertSame(9, $found[8]->version());
        $this->assertStringContainsString('unread', strtolower($found[8]->description()));
        $this->assertSame(10, $found[9]->version());
        $this->assertStringContainsString('analytics', strtolower($found[9]->description()));
        $this->assertSame(11, $found[10]->version());
        $this->assertStringContainsString('like', strtolower($found[10]->description()));
        $this->assertSame(12, $found[11]->version());
        $this->assertStringContainsString(
            'topic type',
            strtolower($found[11]->description())
        );
    }
}
