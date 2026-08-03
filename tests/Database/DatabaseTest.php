<?php

/**
 * Tests for AP_DB — PDO abstraction, prepared statements only.
 *
 * Uses in-memory SQLite so the suite runs without MySQL.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Database;

use AP_DB;
use AP_DB_Exception;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_DB::class)]
final class DatabaseTest extends TestCase
{
    private string $root;

    private AP_DB $db;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        $classFile = $this->root . '/ap-includes/class-ap-db.php';
        $this->assertFileIsReadable($classFile);
        require_once $classFile;

        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');

        $this->db->query(
            'CREATE TABLE ap_options (
                option_id INTEGER PRIMARY KEY AUTOINCREMENT,
                option_name TEXT NOT NULL UNIQUE,
                option_value TEXT NOT NULL
            )'
        );
    }

    public function testClassAndHelperAreAvailable(): void
    {
        $this->assertTrue(class_exists('AP_DB', false));
        $this->assertTrue(class_exists('AP_DB_Exception', false));
        $this->assertTrue(function_exists('ap_db'));
    }

    public function testDriverAndPrefix(): void
    {
        $this->assertSame('sqlite', $this->db->getDriver());
        $this->assertSame('ap_', $this->db->getPrefix());
        $this->assertSame('ap_', $this->db->prefix);
        $this->assertSame('ap_options', $this->db->table('options'));
        $this->assertSame('ap_options', $this->db->options);
        $this->assertSame('ap_users', $this->db->users);
    }

    public function testTableRejectsUnsafeNames(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->db->table('options; DROP TABLE users--');
    }

    public function testBuildDsnMysql(): void
    {
        $dsn = AP_DB::buildDsn('mysql', 'agorapress', '127.0.0.1:3307', 'utf8mb4');
        $this->assertStringContainsString('mysql:host=127.0.0.1', $dsn);
        $this->assertStringContainsString('port=3307', $dsn);
        $this->assertStringContainsString('dbname=agorapress', $dsn);
        $this->assertStringContainsString('charset=utf8mb4', $dsn);
    }

    public function testBuildDsnSqliteMemory(): void
    {
        $this->assertSame('sqlite::memory:', AP_DB::buildDsn('sqlite', ':memory:'));
        $this->assertSame('sqlite::memory:', AP_DB::buildDsn('sqlite', ''));
    }

    public function testBuildDsnSqlitePath(): void
    {
        $this->assertSame(
            'sqlite:/var/data/site.sqlite',
            AP_DB::buildDsn('sqlite', '/var/data/site.sqlite')
        );
    }

    public function testBuildDsnPgsql(): void
    {
        $dsn = AP_DB::buildDsn('pgsql', 'agorapress', 'localhost:5432');
        $this->assertStringContainsString('pgsql:host=localhost', $dsn);
        $this->assertStringContainsString('port=5432', $dsn);
        $this->assertStringContainsString('dbname=agorapress', $dsn);
    }

    public function testBuildDsnRejectsUnknownDriver(): void
    {
        $this->expectException(AP_DB_Exception::class);
        AP_DB::buildDsn('oracle', 'x');
    }

    public function testInsertGetVarGetRowGetResultsPrepared(): void
    {
        $rows = $this->db->insert('options', [
            'option_name' => 'siteurl',
            'option_value' => 'https://example.com',
        ]);
        $this->assertSame(1, $rows);
        $this->assertNotSame('0', $this->db->lastInsertId());

        $value = $this->db->getVar(
            'SELECT option_value FROM ap_options WHERE option_name = ?',
            ['siteurl']
        );
        $this->assertSame('https://example.com', $value);

        $row = $this->db->getRow(
            'SELECT option_name, option_value FROM ap_options WHERE option_name = ?',
            ['siteurl']
        );
        $this->assertIsObject($row);
        $this->assertSame('siteurl', $row->option_name);
        $this->assertSame('https://example.com', $row->option_value);

        $this->db->insert('options', [
            'option_name' => 'blogname',
            'option_value' => 'AgoraPress',
        ]);

        $all = $this->db->getResults(
            'SELECT option_name FROM ap_options ORDER BY option_name'
        );
        $this->assertCount(2, $all);
        $this->assertSame('blogname', $all[0]->option_name);

        $names = $this->db->getCol(
            'SELECT option_name FROM ap_options ORDER BY option_name'
        );
        $this->assertSame(['blogname', 'siteurl'], $names);
    }

    public function testUpdateAndDelete(): void
    {
        $this->db->insert('options', [
            'option_name' => 'timezone',
            'option_value' => 'UTC',
        ]);

        $updated = $this->db->update(
            'options',
            ['option_value' => 'America/New_York'],
            ['option_name' => 'timezone']
        );
        $this->assertSame(1, $updated);
        $this->assertSame(
            'America/New_York',
            $this->db->getVar(
                'SELECT option_value FROM ap_options WHERE option_name = ?',
                ['timezone']
            )
        );

        $deleted = $this->db->delete('options', ['option_name' => 'timezone']);
        $this->assertSame(1, $deleted);
        $this->assertNull(
            $this->db->getVar(
                'SELECT option_value FROM ap_options WHERE option_name = ?',
                ['timezone']
            )
        );
    }

    public function testInsertRejectsEmptyData(): void
    {
        $this->assertFalse($this->db->insert('options', []));
        $this->assertNotNull($this->db->lastError());
    }

    public function testUpdateRejectsEmptyWhere(): void
    {
        $this->assertFalse($this->db->update('options', ['option_value' => 'x'], []));
    }

    public function testDeleteRejectsEmptyWhere(): void
    {
        $this->assertFalse($this->db->delete('options', []));
    }

    public function testNamedParameters(): void
    {
        $this->db->insert('options', [
            'option_name' => 'home',
            'option_value' => 'https://home.test',
        ]);

        $value = $this->db->getVar(
            'SELECT option_value FROM ap_options WHERE option_name = :name',
            [':name' => 'home']
        );
        $this->assertSame('https://home.test', $value);
    }

    public function testTransactions(): void
    {
        $this->assertFalse($this->db->inTransaction());
        $this->assertTrue($this->db->beginTransaction());
        $this->assertTrue($this->db->inTransaction());

        $this->db->insert('options', [
            'option_name' => 'tx_key',
            'option_value' => 'pending',
        ]);
        $this->assertTrue($this->db->rollBack());
        $this->assertFalse($this->db->inTransaction());
        $this->assertNull(
            $this->db->getVar(
                'SELECT option_value FROM ap_options WHERE option_name = ?',
                ['tx_key']
            )
        );

        $this->db->beginTransaction();
        $this->db->insert('options', [
            'option_name' => 'tx_key',
            'option_value' => 'committed',
        ]);
        $this->assertTrue($this->db->commit());
        $this->assertSame(
            'committed',
            $this->db->getVar(
                'SELECT option_value FROM ap_options WHERE option_name = ?',
                ['tx_key']
            )
        );
    }

    public function testQueryFailureSetsLastError(): void
    {
        $result = $this->db->query('SELECT * FROM ap_does_not_exist');
        $this->assertFalse($result);
        $this->assertNotNull($this->db->lastError());
        $this->assertNotSame('', $this->db->lastError());
    }

    public function testFromConfigRequiresConstants(): void
    {
        // Ensure AP_DB_DRIVER is not defined in this process, or skip if it is.
        if (defined('AP_DB_DRIVER') || defined('AP_DB_NAME')) {
            $this->markTestSkipped('DB config constants already defined in this process');
        }

        $this->expectException(AP_DB_Exception::class);
        AP_DB::fromConfig();
    }

    public function testCreatePdoSqliteMemory(): void
    {
        $pdo = AP_DB::createPdo('sqlite', ':memory:');
        $this->assertInstanceOf(PDO::class, $pdo);
        $stmt = $pdo->query('SELECT 1');
        $this->assertNotFalse($stmt);
        $this->assertSame(1, (int) $stmt->fetchColumn());
    }

    public function testApDbHelperUsesGlobalInstance(): void
    {
        $previous = $GLOBALS['apdb'] ?? null;
        try {
            $GLOBALS['apdb'] = $this->db;
            $resolved = ap_db();
            $this->assertSame($this->db, $resolved);
        } finally {
            if ($previous === null) {
                unset($GLOBALS['apdb']);
            } else {
                $GLOBALS['apdb'] = $previous;
            }
        }
    }

    public function testBootstrapLoadsDbClassWithoutConnecting(): void
    {
        // When ap-config.php is missing, bootstrap exits before DB load.
        // When present with sample config, class must load without forcing a connect.
        $configPath = $this->root . '/ap-config.php';
        $created = false;

        if (!is_readable($configPath)) {
            $sample = $this->root . '/ap-config-sample.php';
            $this->assertFileIsReadable($sample);
            $this->assertTrue(copy($sample, $configPath));
            $created = true;
        }

        $tmpScript = sys_get_temp_dir() . '/apdb-bootstrap-' . uniqid('', true) . '.php';

        try {
            $root = $this->root . '/';
            $code = "<?php\ndeclare(strict_types=1);\n"
                . "define('AP_ABSPATH', " . var_export($root, true) . ");\n"
                . "require AP_ABSPATH . 'ap-includes/bootstrap.php';\n"
                . "ap_bootstrap();\n"
                . "echo class_exists('AP_DB', false) ? \"AP_DB_OK\\n\" : \"AP_DB_MISSING\\n\";\n"
                . "echo function_exists('ap_db') ? \"AP_DB_FN_OK\\n\" : \"AP_DB_FN_MISSING\\n\";\n"
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
            $this->assertStringContainsString('AP_DB_OK', $body);
            $this->assertStringContainsString('AP_DB_FN_OK', $body);
            $this->assertStringContainsString('LAZY', $body);
            $this->assertStringNotContainsString('Fatal error', $body);
        } finally {
            if (is_file($tmpScript)) {
                unlink($tmpScript);
            }
            if ($created && is_file($configPath)) {
                unlink($configPath);
            }
        }
    }

    public function testAssocFetchMode(): void
    {
        $this->db->insert('options', [
            'option_name' => 'assoc_test',
            'option_value' => 'yes',
        ]);

        $row = $this->db->getRow(
            'SELECT option_name, option_value FROM ap_options WHERE option_name = ?',
            ['assoc_test'],
            PDO::FETCH_ASSOC
        );
        $this->assertIsArray($row);
        $this->assertSame('assoc_test', $row['option_name']);
    }
}
