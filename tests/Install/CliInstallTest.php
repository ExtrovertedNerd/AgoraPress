<?php

/**
 * Tests for AP_Cli_Install (CLI install path).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Install;

use AP_Cli_Install;
use AP_DB;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_Cli_Install::class)]
final class CliInstallTest extends TestCase
{
    private string $root;

    private string $tempDir;

    /** @var list<string> */
    private array $stdout = [];

    /** @var list<string> */
    private array $stderr = [];

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        require_once $this->root . '/ap-includes/class-ap-cli-install.php';
        AP_Cli_Install::ensureDependencies();

        $this->tempDir = sys_get_temp_dir() . '/ap-cli-install-' . uniqid('', true);
        $this->assertTrue(mkdir($this->tempDir, 0700, true));
        $this->stdout = [];
        $this->stderr = [];
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

    /**
     * @return callable(string): void
     */
    private function captureOut(): callable
    {
        return function (string $line): void {
            $this->stdout[] = $line;
        };
    }

    /**
     * @return callable(string): void
     */
    private function captureErr(): callable
    {
        return function (string $line): void {
            $this->stderr[] = $line;
        };
    }

    public function testUsageMentionsRequiredFlags(): void
    {
        $text = AP_Cli_Install::usage('install/cli.php');
        $this->assertStringContainsString('--site-title', $text);
        $this->assertStringContainsString('--db-driver', $text);
        $this->assertStringContainsString('--admin-password', $text);
        $this->assertStringContainsString('sqlite', $text);
    }

    public function testParseArgvHelp(): void
    {
        $parsed = AP_Cli_Install::parseArgv(['cli.php', '--help'], $this->root . '/');
        $this->assertTrue($parsed['ok']);
        $this->assertTrue($parsed['help']);
        $this->assertSame([], $parsed['errors']);
    }

    public function testParseArgvMissingRequired(): void
    {
        $parsed = AP_Cli_Install::parseArgv(
            ['cli.php', '--db-driver=sqlite'],
            $this->root . '/'
        );
        $this->assertFalse($parsed['ok']);
        $this->assertNotSame([], $parsed['errors']);
        $joined = implode(' ', $parsed['errors']);
        $this->assertStringContainsString('--site-title', $joined);
        $this->assertStringContainsString('--admin-password', $joined);
    }

    public function testParseArgvEqualsAndSpaceForms(): void
    {
        $parsed = AP_Cli_Install::parseArgv(
            [
                'cli.php',
                '--db-driver',
                'sqlite',
                '--site-title=Demo Site',
                '--site-url=https://example.com',
                '--admin-user=admin',
                '--admin-email=admin@example.com',
                '--admin-password=password123',
                '--table-prefix=demo_',
            ],
            $this->root . '/'
        );
        $this->assertTrue($parsed['ok'], implode('; ', $parsed['errors']));
        $this->assertFalse($parsed['help']);
        $this->assertSame('sqlite', $parsed['options']['db_driver']);
        $this->assertSame('Demo Site', $parsed['options']['site_title']);
        $this->assertSame('demo_', $parsed['options']['table_prefix']);
        $this->assertStringContainsString(
            'database.sqlite',
            $parsed['options']['db_name']
        );
    }

    public function testParseArgvUnknownOption(): void
    {
        $parsed = AP_Cli_Install::parseArgv(
            ['cli.php', '--not-a-real-flag=1', '--site-title=X'],
            $this->root . '/'
        );
        $this->assertFalse($parsed['ok']);
        $this->assertStringContainsString(
            'Unknown option',
            implode(' ', $parsed['errors'])
        );
    }

    public function testRunFromArgvHelpExitsZero(): void
    {
        $code = AP_Cli_Install::runFromArgv(
            ['cli.php', '-h'],
            $this->captureOut(),
            $this->captureErr(),
            $this->root . '/'
        );
        $this->assertSame(AP_Cli_Install::EXIT_OK, $code);
        $this->assertStringContainsString(
            'AgoraPress CLI installer',
            implode("\n", $this->stdout)
        );
    }

    public function testRunFromArgvUsageErrorExitsOne(): void
    {
        $code = AP_Cli_Install::runFromArgv(
            ['cli.php'],
            $this->captureOut(),
            $this->captureErr(),
            $this->root . '/'
        );
        $this->assertSame(AP_Cli_Install::EXIT_USAGE, $code);
        $this->assertNotSame([], $this->stderr);
    }

    public function testFullSqliteInstallViaExecute(): void
    {
        $sqlite = $this->tempDir . '/site.sqlite';
        $config = $this->tempDir . '/ap-config.php';

        $code = AP_Cli_Install::execute(
            [
                'db_driver' => 'sqlite',
                'db_name' => $sqlite,
                'db_user' => '',
                'db_password' => '',
                'db_host' => '',
                'db_charset' => 'utf8mb4',
                'table_prefix' => 'ap_',
                'site_title' => 'CLI Install Site',
                'site_url' => 'https://cli.example.test',
                'admin_user' => 'cliadmin',
                'admin_email' => 'cli@example.test',
                'admin_password' => 'securepass99',
                'config_path' => $config,
                'skip_requirements' => true,
            ],
            $this->captureOut(),
            $this->captureErr(),
            $this->root . '/'
        );

        $this->assertSame(
            AP_Cli_Install::EXIT_OK,
            $code,
            "stdout:\n" . implode("\n", $this->stdout)
            . "\nstderr:\n" . implode("\n", $this->stderr)
        );
        $this->assertFileIsReadable($config);
        $this->assertFileExists($sqlite);
        $this->assertStringContainsString('Installation complete', implode("\n", $this->stdout));

        $pdo = new PDO('sqlite:' . $sqlite, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $apdb = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');
        $blogname = $apdb->getVar(
            'SELECT option_value FROM ' . $apdb->quoteIdentifier($apdb->options)
            . ' WHERE option_name = ?',
            ['blogname']
        );
        $this->assertSame('CLI Install Site', $blogname);

        $login = $apdb->getVar(
            'SELECT user_login FROM ' . $apdb->quoteIdentifier($apdb->users)
            . ' WHERE user_email = ?',
            ['cli@example.test']
        );
        $this->assertSame('cliadmin', $login);
    }

    public function testExecuteRefusesExistingConfig(): void
    {
        $sqlite = $this->tempDir . '/exists.sqlite';
        $config = $this->tempDir . '/ap-config.php';
        file_put_contents($config, "<?php\n// existing\n");

        $code = AP_Cli_Install::execute(
            [
                'db_driver' => 'sqlite',
                'db_name' => $sqlite,
                'db_user' => '',
                'db_password' => '',
                'db_host' => '',
                'db_charset' => 'utf8mb4',
                'table_prefix' => 'ap_',
                'site_title' => 'X',
                'site_url' => 'https://example.com',
                'admin_user' => 'admin',
                'admin_email' => 'a@example.com',
                'admin_password' => 'password123',
                'config_path' => $config,
                'skip_requirements' => true,
            ],
            $this->captureOut(),
            $this->captureErr(),
            $this->root . '/'
        );

        $this->assertSame(AP_Cli_Install::EXIT_INSTALL, $code);
        $combinedErr = implode(' ', $this->stderr);
        $this->assertStringContainsString('already exists', $combinedErr);
        $this->assertStringContainsString('reinstall', $combinedErr);
        // Early exit: must not attempt connection / requirements work.
        $this->assertStringNotContainsString('Connecting and installing', implode(' ', $this->stdout));
        $this->assertStringNotContainsString('Checking requirements', implode(' ', $this->stdout));
        // Original config must be untouched.
        $this->assertStringContainsString('// existing', (string) file_get_contents($config));
    }

    public function testEntryScriptExistsAndIsCliOnly(): void
    {
        $script = $this->root . '/install/cli.php';
        $this->assertFileIsReadable($script);
        $src = (string) file_get_contents($script);
        $this->assertStringContainsString('AP_Cli_Install::runFromArgv', $src);
        $this->assertStringContainsString('PHP_SAPI', $src);
    }

    public function testEntryScriptHelpSubprocess(): void
    {
        $script = $this->root . '/install/cli.php';
        $php = PHP_BINARY !== '' ? PHP_BINARY : 'php';
        $cmd = escapeshellarg($php) . ' ' . escapeshellarg($script) . ' --help 2>&1';
        $output = [];
        $exit = 0;
        exec($cmd, $output, $exit);
        $this->assertSame(0, $exit, implode("\n", $output));
        $combined = implode("\n", $output);
        $this->assertStringContainsString('AgoraPress CLI installer', $combined);
        $this->assertStringContainsString('--db-driver', $combined);
    }

    public function testEntryScriptFullSqliteSubprocess(): void
    {
        $sqlite = $this->tempDir . '/sub.sqlite';
        $config = $this->tempDir . '/sub-config.php';
        $php = PHP_BINARY !== '' ? PHP_BINARY : 'php';
        $script = $this->root . '/install/cli.php';

        $args = [
            $php,
            $script,
            '--db-driver=sqlite',
            '--db-name=' . $sqlite,
            '--site-title=Subprocess Site',
            '--site-url=https://sub.example.test',
            '--admin-user=subadmin',
            '--admin-email=sub@example.test',
            '--admin-password=subprocess99',
            '--config-path=' . $config,
            '--skip-requirements',
        ];
        $cmd = implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
        $output = [];
        $exit = 0;
        exec($cmd, $output, $exit);

        $this->assertSame(0, $exit, implode("\n", $output));
        $this->assertFileIsReadable($config);
        $this->assertStringContainsString('Installation complete', implode("\n", $output));
        $this->assertStringContainsString("define('AP_DB_DRIVER', 'sqlite')", (string) file_get_contents($config));
    }
}
