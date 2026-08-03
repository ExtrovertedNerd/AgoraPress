<?php

/**
 * Tests for configurable table prefix support (AP_DB + config helpers).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Database;

use AP_DB;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_DB::class)]
final class TablePrefixTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        require_once $this->root . '/ap-includes/load-config.php';
        require_once $this->root . '/ap-includes/class-ap-db.php';
    }

    private function sqliteDb(string $prefix): AP_DB
    {
        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        return AP_DB::fromPdo($pdo, 'sqlite', $prefix);
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function prefixProvider(): array
    {
        return [
            ['ap_', 'ap_'],
            ['myblog_', 'myblog_'],
            ['  custom  ', 'custom'],
            ['bad-prefix!', 'badprefix'],
            ['', 'ap_'],
            ['9x_', 'ap_9x_'],
        ];
    }

    #[DataProvider('prefixProvider')]
    public function testConstructorNormalizesPrefix(string $input, string $expected): void
    {
        $db = $this->sqliteDb($input);
        $this->assertSame($expected, $db->getPrefix());
        $this->assertSame($expected, $db->prefix);
        $this->assertSame($expected . 'options', $db->table('options'));
        $this->assertSame($expected . 'options', $db->options);
        $this->assertSame($expected . 'users', $db->users);
    }

    public function testPublicTablePropertiesMatchTablesMap(): void
    {
        $db = $this->sqliteDb('site2_');
        $map = $db->tables();

        $this->assertArrayHasKey('options', $map);
        $this->assertArrayHasKey('forums', $map);
        $this->assertSame('site2_options', $map['options']);
        $this->assertSame('site2_forums', $map['forums']);
        $this->assertSame($map['options'], $db->options);
        $this->assertSame($map['users'], $db->users);
        $this->assertSame($map['posts'], $db->posts);
        $this->assertSame($map['forums'], $db->forums);
        $this->assertSame($map['topics'], $db->topics);
        $this->assertSame($map['forum_posts'], $db->forum_posts);
    }

    public function testSetPrefixRefreshesProperties(): void
    {
        $db = $this->sqliteDb('ap_');
        $this->assertSame('ap_options', $db->options);

        $db->setPrefix('other_');
        $this->assertSame('other_', $db->getPrefix());
        $this->assertSame('other_options', $db->options);
        $this->assertSame('other_users', $db->users);
        $this->assertSame('other_posts', $db->table('posts'));
    }

    public function testCustomPrefixCrudRoundTrip(): void
    {
        $prefix = 'xyz_';
        $db = $this->sqliteDb($prefix);
        $table = $db->table('options');
        $this->assertSame('xyz_options', $table);

        $created = $db->query(
            'CREATE TABLE ' . $db->quoteIdentifier($table) . ' (
                option_id INTEGER PRIMARY KEY AUTOINCREMENT,
                option_name TEXT NOT NULL UNIQUE,
                option_value TEXT NOT NULL
            )'
        );
        $this->assertNotFalse($created);

        $this->assertSame(1, $db->insert('options', [
            'option_name' => 'blogname',
            'option_value' => 'Custom Prefix Site',
        ]));

        $value = $db->getVar(
            'SELECT option_value FROM ' . $db->quoteIdentifier($db->options)
            . ' WHERE option_name = ?',
            ['blogname']
        );
        $this->assertSame('Custom Prefix Site', $value);

        $this->assertSame(1, $db->update(
            'options',
            ['option_value' => 'Renamed'],
            ['option_name' => 'blogname']
        ));
        $this->assertSame(
            'Renamed',
            $db->getVar(
                'SELECT option_value FROM ' . $db->quoteIdentifier($db->options)
                . ' WHERE option_name = ?',
                ['blogname']
            )
        );

        $this->assertSame(1, $db->delete('options', ['option_name' => 'blogname']));
        $this->assertNull(
            $db->getVar(
                'SELECT option_value FROM ' . $db->quoteIdentifier($db->options)
                . ' WHERE option_name = ?',
                ['blogname']
            )
        );
    }

    public function testDefaultPrefixProperties(): void
    {
        $db = $this->sqliteDb('ap_');
        $this->assertSame('ap_options', $db->options);
        $this->assertSame('ap_usermeta', $db->usermeta);
        $this->assertSame('ap_term_taxonomy', $db->term_taxonomy);
        $this->assertSame('ap_term_relationships', $db->term_relationships);
        $this->assertSame('ap_commentmeta', $db->commentmeta);
        $this->assertSame('ap_group_members', $db->group_members);
    }

    public function testNormalizePrefixStaticDelegatesToHelper(): void
    {
        $this->assertSame('ap_', AP_DB::normalizePrefix(''));
        $this->assertSame('my_', AP_DB::normalizePrefix('my_'));
        $this->assertSame('clean', AP_DB::normalizePrefix('clean!@#'));
    }

    public function testIsSafeIdentifier(): void
    {
        $this->assertTrue(AP_DB::isSafeIdentifier('options'));
        $this->assertTrue(AP_DB::isSafeIdentifier('_private'));
        $this->assertFalse(AP_DB::isSafeIdentifier('options;drop'));
        $this->assertFalse(AP_DB::isSafeIdentifier(''));
        $this->assertFalse(AP_DB::isSafeIdentifier('1abc'));
    }

    public function testKnownBaseTablesIncludeCoreAndForums(): void
    {
        $names = AP_DB::knownBaseTables();
        $this->assertContains('schema_migrations', $names);
        $this->assertContains('options', $names);
        $this->assertContains('users', $names);
        $this->assertContains('posts', $names);
        $this->assertContains('forums', $names);
        $this->assertContains('topics', $names);
        $this->assertContains('forum_posts', $names);
    }

    public function testFromConfigUsesCustomPrefixInIsolation(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'appfx');
        $this->assertNotFalse($tmp);

        $config = "<?php\ndeclare(strict_types=1);\n"
            . "define('AP_DB_DRIVER', 'sqlite');\n"
            . "define('AP_DB_NAME', ':memory:');\n"
            . "define('AP_DB_USER', '');\n"
            . "define('AP_DB_PASSWORD', '');\n"
            . "define('AP_DB_HOST', 'localhost');\n"
            . "define('AP_AUTH_KEY', 'k1');\n"
            . "define('AP_SECURE_AUTH_KEY', 'k2');\n"
            . "define('AP_LOGGED_IN_KEY', 'k3');\n"
            . "define('AP_NONCE_KEY', 'k4');\n"
            . "define('AP_AUTH_SALT', 's1');\n"
            . "define('AP_SECURE_AUTH_SALT', 's2');\n"
            . "define('AP_LOGGED_IN_SALT', 's3');\n"
            . "define('AP_NONCE_SALT', 's4');\n"
            . "\$table_prefix = 'custom_';\n";
        file_put_contents($tmp, $config);

        $abs = var_export($this->root . '/', true);
        $load = var_export($this->root . '/ap-includes/load-config.php', true);
        $dbClass = var_export($this->root . '/ap-includes/class-ap-db.php', true);
        $cfg = var_export($tmp, true);

        $script = "declare(strict_types=1);\n"
            . "define('AP_ABSPATH', {$abs});\n"
            . "require {$load};\n"
            . "require {$dbClass};\n"
            . "if (!ap_load_config({$cfg}, false)) {\n"
            . "  fwrite(STDERR, \"load fail\\n\"); exit(2);\n"
            . "}\n"
            . "\$db = AP_DB::fromConfig();\n"
            . "if (\$db->getPrefix() !== 'custom_') {\n"
            . "  fwrite(STDERR, 'pfx=' . \$db->getPrefix() . \"\\n\"); exit(3);\n"
            . "}\n"
            . "if (\$db->options !== 'custom_options'"
            . " || \$db->table('users') !== 'custom_users') {\n"
            . "  fwrite(STDERR, 'tables mismatch\\n'); exit(4);\n"
            . "}\n"
            . "echo \"ok\\n\"; exit(0);\n";

        try {
            $php = PHP_BINARY !== '' ? PHP_BINARY : 'php';
            $cmd = escapeshellarg($php)
                . ' -d display_errors=1 -d error_reporting=E_ALL -r '
                . escapeshellarg($script)
                . ' 2>&1';
            $output = [];
            $exit = 0;
            exec($cmd, $output, $exit);
            $body = implode("\n", $output);
            $this->assertSame(0, $exit, $body);
            $this->assertStringContainsString('ok', $body);
        } finally {
            if (is_file($tmp)) {
                unlink($tmp);
            }
        }
    }

    public function testEnvTablePrefixWhenConfigOmitsVariable(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'apenv');
        $this->assertNotFalse($tmp);

        // No $table_prefix assignment — finalize should honor AP_TABLE_PREFIX env.
        $config = "<?php\ndeclare(strict_types=1);\n"
            . "define('AP_DB_DRIVER', 'sqlite');\n"
            . "define('AP_DB_NAME', ':memory:');\n"
            . "define('AP_DB_USER', '');\n"
            . "define('AP_DB_PASSWORD', '');\n"
            . "define('AP_DB_HOST', 'localhost');\n"
            . "define('AP_AUTH_KEY', 'k1');\n"
            . "define('AP_SECURE_AUTH_KEY', 'k2');\n"
            . "define('AP_LOGGED_IN_KEY', 'k3');\n"
            . "define('AP_NONCE_KEY', 'k4');\n"
            . "define('AP_AUTH_SALT', 's1');\n"
            . "define('AP_SECURE_AUTH_SALT', 's2');\n"
            . "define('AP_LOGGED_IN_SALT', 's3');\n"
            . "define('AP_NONCE_SALT', 's4');\n";
        file_put_contents($tmp, $config);

        $abs = var_export($this->root . '/', true);
        $load = var_export($this->root . '/ap-includes/load-config.php', true);
        $cfg = var_export($tmp, true);

        $script = "declare(strict_types=1);\n"
            . "putenv('AP_TABLE_PREFIX=envpfx_');\n"
            . "\$_ENV['AP_TABLE_PREFIX'] = 'envpfx_';\n"
            . "define('AP_ABSPATH', {$abs});\n"
            . "require {$load};\n"
            . "if (!ap_load_config({$cfg}, false)) {\n"
            . "  fwrite(STDERR, \"load fail\\n\"); exit(2);\n"
            . "}\n"
            . "if (ap_get_table_prefix() !== 'envpfx_'"
            . " || AP_TABLE_PREFIX !== 'envpfx_') {\n"
            . "  fwrite(STDERR, 'got ' . ap_get_table_prefix() . \"\\n\");\n"
            . "  exit(3);\n"
            . "}\n"
            . "echo \"ok\\n\"; exit(0);\n";

        try {
            $php = PHP_BINARY !== '' ? PHP_BINARY : 'php';
            $cmd = escapeshellarg($php)
                . ' -d display_errors=1 -d error_reporting=E_ALL -r '
                . escapeshellarg($script)
                . ' 2>&1';
            $output = [];
            $exit = 0;
            exec($cmd, $output, $exit);
            $body = implode("\n", $output);
            $this->assertSame(0, $exit, $body);
            $this->assertStringContainsString('ok', $body);
        } finally {
            if (is_file($tmp)) {
                unlink($tmp);
            }
        }
    }
}
