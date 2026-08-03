<?php

/**
 * Tests for AP_Installer (config generation, seed, full SQLite install).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Install;

use AP_DB;
use AP_Installer;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_Installer::class)]
final class InstallerTest extends TestCase
{
    private string $root;

    private string $tempDir;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        require_once $this->root . '/ap-includes/version.php';
        require_once $this->root . '/ap-includes/load-config.php';
        require_once $this->root . '/ap-includes/class-ap-db.php';
        require_once $this->root . '/ap-includes/class-ap-migrator.php';
        require_once $this->root . '/ap-includes/class-ap-installer.php';

        $this->tempDir = sys_get_temp_dir() . '/ap-install-' . uniqid('', true);
        $this->assertTrue(mkdir($this->tempDir, 0700, true));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->tempDir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tempDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            if ($file->isDir()) {
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
        }
        @rmdir($this->tempDir);
    }

    public function testGenerateSaltsHasAllKeysAndUniqueValues(): void
    {
        $salts = AP_Installer::generateSalts();
        foreach (AP_Installer::SALT_KEYS as $key) {
            $this->assertArrayHasKey($key, $salts);
            $this->assertGreaterThanOrEqual(32, strlen($salts[$key]));
        }
        $this->assertCount(count(AP_Installer::SALT_KEYS), array_unique(array_values($salts)));
    }

    public function testHashPasswordVerifiesWithPasswordVerify(): void
    {
        $hash = AP_Installer::hashPassword('correct horse battery staple');
        $this->assertNotSame('', $hash);
        $this->assertTrue(password_verify('correct horse battery staple', $hash));
        $this->assertFalse(password_verify('wrong', $hash));
        // Prefer Argon2id when the runtime supports it.
        if (defined('PASSWORD_ARGON2ID')) {
            $this->assertStringContainsString('argon2', strtolower($hash));
        }
    }

    public function testValidateDatabaseInputRejectsEmptyMysql(): void
    {
        $errors = AP_Installer::validateDatabaseInput([
            'driver' => 'mysql',
            'name' => '',
            'user' => '',
            'host' => '',
            'prefix' => 'ap_',
        ]);
        $this->assertNotSame([], $errors);
    }

    public function testValidateDatabaseInputAcceptsSqlitePathOnly(): void
    {
        $errors = AP_Installer::validateDatabaseInput([
            'driver' => 'sqlite',
            'name' => $this->tempDir . '/site.sqlite',
            'prefix' => 'ap_',
        ]);
        $this->assertSame([], $errors);
    }

    public function testValidateSiteAndAdmin(): void
    {
        $ok = AP_Installer::validateSiteAndAdmin(
            ['title' => 'Demo', 'url' => 'https://example.com'],
            [
                'username' => 'admin',
                'email' => 'admin@example.com',
                'password' => 'password123',
                'password_confirm' => 'password123',
            ]
        );
        $this->assertSame([], $ok);

        $bad = AP_Installer::validateSiteAndAdmin(
            ['title' => '', 'url' => 'not-a-url'],
            [
                'username' => 'ab',
                'email' => 'nope',
                'password' => 'short',
                'password_confirm' => 'other',
            ]
        );
        $this->assertGreaterThanOrEqual(4, count($bad));
    }

    public function testGenerateConfigPhpContainsDefinesAndPrefix(): void
    {
        $salts = [];
        foreach (AP_Installer::SALT_KEYS as $i => $key) {
            $salts[$key] = 'salt-value-' . $i;
        }

        $php = AP_Installer::generateConfigPhp([
            'driver' => 'mysql',
            'name' => 'agorapress',
            'user' => 'ap_user',
            'password' => "s'ecret",
            'host' => 'localhost',
            'prefix' => 'ap_',
        ], $salts);

        $this->assertStringContainsString("define('AP_DB_DRIVER', 'mysql')", $php);
        $this->assertStringContainsString("define('AP_DB_NAME', 'agorapress')", $php);
        $this->assertStringContainsString("define('AP_DB_USER', 'ap_user')", $php);
        $this->assertStringContainsString('$table_prefix = \'ap_\'', $php);
        $this->assertStringContainsString("define('AP_TELEMETRY', false)", $php);
        $this->assertStringContainsString("define('AP_AUTH_KEY', 'salt-value-0')", $php);
        // Password with quote must be safely exported.
        $this->assertStringContainsString('AP_DB_PASSWORD', $php);
        $this->assertStringNotContainsString("define('AP_DB_PASSWORD', 's'ecret')", $php);

        // Generated PHP must parse.
        $tokens = @token_get_all($php);
        $this->assertIsArray($tokens);
        $this->assertNotSame([], $tokens);
    }

    public function testWriteConfigFileAtomic(): void
    {
        $path = $this->tempDir . '/ap-config.php';
        $result = AP_Installer::writeConfigFile($path, "<?php\n// test\n");
        $this->assertTrue($result);
        $this->assertFileExists($path);
        $this->assertStringContainsString('// test', (string) file_get_contents($path));
    }

    public function testWriteConfigFileRefusesOverwrite(): void
    {
        $path = $this->tempDir . '/ap-config.php';
        file_put_contents($path, "<?php\n// original\n");

        $result = AP_Installer::writeConfigFile($path, "<?php\n// should not write\n");
        $this->assertIsString($result);
        $this->assertStringContainsString('already exists', $result);
        $this->assertStringContainsString('// original', (string) file_get_contents($path));
        $this->assertStringNotContainsString('should not write', (string) file_get_contents($path));
    }

    public function testConfigExistsHelper(): void
    {
        $missing = $this->tempDir . '/no-such-config.php';
        $this->assertFalse(AP_Installer::configExists($missing));
        $this->assertFalse(AP_Installer::configExists(''));

        $path = $this->tempDir . '/present.php';
        file_put_contents($path, "<?php\n");
        $this->assertTrue(AP_Installer::configExists($path));
        $this->assertStringContainsString('already exists', AP_Installer::alreadyInstalledMessage($path));
    }

    public function testTestConnectionSqliteSucceeds(): void
    {
        $sqlite = $this->tempDir . '/conn.sqlite';
        $err = AP_Installer::testConnection([
            'driver' => 'sqlite',
            'name' => $sqlite,
        ]);
        $this->assertNull($err);
        $this->assertFileExists($sqlite);
    }

    public function testFullInstallAgainstSqlite(): void
    {
        $sqlite = $this->tempDir . '/install.sqlite';
        $configPath = $this->tempDir . '/ap-config.php';

        $db = [
            'driver' => 'sqlite',
            'name' => $sqlite,
            'user' => '',
            'password' => '',
            'host' => '',
            'prefix' => 'ap_',
        ];
        $site = [
            'title' => 'Install Test Site',
            'url' => 'https://example.test',
        ];
        $admin = [
            'username' => 'siteadmin',
            'email' => 'admin@example.test',
            'password' => 'securepass99',
        ];

        $result = AP_Installer::run($db, $site, $admin, $configPath);

        $this->assertTrue($result['ok'], implode('; ', $result['errors']));
        $this->assertTrue($result['config_written']);
        $this->assertFileIsReadable($configPath);
        $this->assertGreaterThan(0, (int) $result['admin_id']);
        $this->assertNotSame([], $result['migrations']);

        // Config defines load without fatal.
        $php = PHP_BINARY !== '' ? PHP_BINARY : 'php';
        $cmd = escapeshellarg($php)
            . ' -r '
            . escapeshellarg('require ' . var_export($configPath, true) . '; echo AP_DB_DRIVER, "|", AP_DB_NAME;')
            . ' 2>&1';
        $output = [];
        $exit = 0;
        exec($cmd, $output, $exit);
        $this->assertSame(0, $exit, implode("\n", $output));
        $this->assertStringContainsString('sqlite|', implode("\n", $output));

        // Tables + seed data.
        $pdo = new PDO('sqlite:' . $sqlite, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $apdb = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');

        $blogname = $apdb->getVar(
            'SELECT option_value FROM ' . $apdb->quoteIdentifier($apdb->options)
            . ' WHERE option_name = ?',
            ['blogname']
        );
        $this->assertSame('Install Test Site', $blogname);

        $user = $apdb->getRow(
            'SELECT user_login, user_email, user_pass FROM '
            . $apdb->quoteIdentifier($apdb->users)
            . ' WHERE ID = ?',
            [(int) $result['admin_id']]
        );
        $this->assertNotNull($user);
        $this->assertSame('siteadmin', $user->user_login);
        $this->assertSame('admin@example.test', $user->user_email);
        $this->assertTrue(password_verify('securepass99', (string) $user->user_pass));

        $caps = $apdb->getVar(
            'SELECT meta_value FROM ' . $apdb->quoteIdentifier($apdb->usermeta)
            . ' WHERE user_id = ? AND meta_key = ?',
            [(int) $result['admin_id'], 'ap_capabilities']
        );
        $this->assertIsString($caps);
        $this->assertStringContainsString('administrator', (string) $caps);
    }

    public function testRunRefusesExistingConfig(): void
    {
        $sqlite = $this->tempDir . '/exists.sqlite';
        $configPath = $this->tempDir . '/ap-config.php';
        file_put_contents($configPath, "<?php\n// existing\n");

        $result = AP_Installer::run(
            [
                'driver' => 'sqlite',
                'name' => $sqlite,
                'prefix' => 'ap_',
            ],
            ['title' => 'X', 'url' => 'https://example.com'],
            [
                'username' => 'admin',
                'email' => 'a@example.com',
                'password' => 'password123',
            ],
            $configPath
        );

        $this->assertFalse($result['ok']);
        $this->assertNotSame([], $result['errors']);
        $this->assertStringContainsString('already exists', $result['errors'][0]);
    }

    public function testDefaultSqlitePathEndsWithDatabaseFile(): void
    {
        $path = AP_Installer::defaultSqlitePath($this->root . '/');
        $this->assertStringEndsWith('ap-content/database.sqlite', $path);
    }

    public function testNormalizePrefixDefaults(): void
    {
        $this->assertSame('ap_', AP_Installer::normalizePrefix(''));
        $this->assertSame('my_', AP_Installer::normalizePrefix('my_'));
    }
}
