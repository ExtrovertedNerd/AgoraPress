<?php

/**
 * Tests for ap-includes/load-config.php (Phase 1 config loading).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Config;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class ConfigLoadTest extends TestCase
{
    private string $root;

    private string $loadConfigPath;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        $this->loadConfigPath = $this->root . '/ap-includes/load-config.php';
        $this->assertFileIsReadable($this->loadConfigPath);

        if (!defined('AP_ABSPATH')) {
            define('AP_ABSPATH', $this->root . '/');
        }

        require_once $this->root . '/ap-includes/version.php';
        require_once $this->loadConfigPath;
    }

    public function testLoadConfigFileExists(): void
    {
        $this->assertFileExists($this->loadConfigPath);
    }

    public function testRequiredConstantsListIsNonEmpty(): void
    {
        $required = ap_required_config_constants();
        $this->assertNotEmpty($required);
        $this->assertContains('AP_DB_DRIVER', $required);
        $this->assertContains('AP_DB_NAME', $required);
        $this->assertContains('AP_AUTH_KEY', $required);
        $this->assertContains('AP_NONCE_SALT', $required);
        // Optional defaults are not in the required list.
        $this->assertNotContains('AP_DEBUG', $required);
    }

    public function testSupportedDriversMatchSpec(): void
    {
        $drivers = ap_supported_db_drivers();
        $this->assertSame(['mysql', 'sqlite', 'pgsql'], $drivers);
    }

    public function testDefaultTablePrefixConstantHelper(): void
    {
        $this->assertSame('ap_', ap_default_table_prefix());
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function tablePrefixProvider(): array
    {
        return [
            ['ap_', 'ap_'],
            ['  ap_  ', 'ap_'],
            ['my_site_', 'my_site_'],
            ['wp', 'wp'],
            ['bad-prefix!', 'badprefix'],
            ['', 'ap_'],
            ['!!!', 'ap_'],
            ['9lives_', 'ap_9lives_'],
        ];
    }

    #[DataProvider('tablePrefixProvider')]
    public function testNormalizeTablePrefix(string $input, string $expected): void
    {
        $this->assertSame($expected, ap_normalize_table_prefix($input));
    }

    public function testCoreAndForumBaseTablesMatchSpec(): void
    {
        $core = ap_core_base_tables();
        $this->assertContains('schema_migrations', $core);
        $this->assertContains('options', $core);
        $this->assertContains('users', $core);
        $this->assertContains('usermeta', $core);
        $this->assertContains('posts', $core);
        $this->assertContains('comments', $core);
        $this->assertContains('analytics_hits', $core);
        $this->assertContains('analytics_daily', $core);
        $this->assertNotContains('forums', $core);

        $forum = ap_forum_base_tables();
        $this->assertContains('forums', $forum);
        $this->assertContains('topics', $forum);
        $this->assertContains('forum_posts', $forum);
        $this->assertNotContains('options', $forum);

        $all = ap_all_base_tables();
        $this->assertContains('options', $all);
        $this->assertContains('forums', $all);
        $this->assertSame(count($all), count(array_unique($all)));
    }

    public function testPrefixedTableHelpersUseActivePrefix(): void
    {
        // Without config load, default prefix is ap_.
        if (!defined('AP_TABLE_PREFIX')) {
            $this->assertSame('ap_', ap_get_table_prefix());
            $this->assertSame('ap_options', ap_prefixed_table('options'));
            $map = ap_prefixed_tables();
            $this->assertSame('ap_users', $map['users']);
            $this->assertSame('ap_forums', $map['forums']);
        } else {
            $pfx = ap_get_table_prefix();
            $this->assertSame($pfx . 'options', ap_prefixed_table('options'));
        }
    }

    public function testDefaultConfigConstantsIncludeCharsetAndDebug(): void
    {
        $defaults = ap_default_config_constants();
        $this->assertSame('utf8mb4', $defaults['AP_DB_CHARSET']);
        $this->assertSame('utf8mb4_unicode_ci', $defaults['AP_DB_COLLATE']);
        $this->assertFalse($defaults['AP_DEBUG']);
        $this->assertArrayNotHasKey('AP_TELEMETRY', $defaults);
    }

    public function testInvalidConfigHtmlListsMissingConstants(): void
    {
        $html = ap_get_invalid_config_html(['AP_DB_NAME', 'AP_AUTH_KEY'], null);

        $this->assertStringContainsString('<!DOCTYPE html>', $html);
        $this->assertStringContainsString('Configuration error', $html);
        $this->assertStringContainsString('AP_DB_NAME', $html);
        $this->assertStringContainsString('AP_AUTH_KEY', $html);
        $this->assertStringContainsString('ap-config-sample.php', $html);
        $this->assertStringNotContainsString('<?php', $html);
    }

    public function testInvalidConfigHtmlIncludesDriverError(): void
    {
        $html = ap_get_invalid_config_html([], 'Unsupported AP_DB_DRIVER value "oracle".');

        $this->assertStringContainsString('oracle', $html);
        $this->assertStringContainsString('Unsupported AP_DB_DRIVER', $html);
    }

    public function testSampleConfigLoadsSuccessfullyInIsolation(): void
    {
        $sample = $this->root . '/ap-config-sample.php';
        $this->assertFileIsReadable($sample);

        $result = $this->runLoadConfigScript($sample, <<<'PHP'
$ok = ap_load_config($argv[1], false);
if ($ok !== true) {
    fwrite(STDERR, "load returned false\n");
    exit(2);
}
if (!ap_config_is_loaded()) {
    fwrite(STDERR, "AP_CONFIG_LOADED not set\n");
    exit(3);
}
if (!defined('AP_TABLE_PREFIX') || AP_TABLE_PREFIX !== 'ap_') {
    fwrite(STDERR, "AP_TABLE_PREFIX mismatch\n");
    exit(4);
}
if (ap_get_table_prefix() !== 'ap_') {
    fwrite(STDERR, "ap_get_table_prefix mismatch\n");
    exit(5);
}
if (!defined('AP_CONTENT_DIR') || !str_contains(AP_CONTENT_DIR, 'ap-content')) {
    fwrite(STDERR, "AP_CONTENT_DIR missing\n");
    exit(6);
}
if (!defined('AP_PLUGIN_DIR') || !defined('AP_THEME_DIR') || !defined('AP_UPLOADS_DIR')) {
    fwrite(STDERR, "path constants missing\n");
    exit(7);
}
if (!defined('AP_DB_CHARSET') || AP_DB_CHARSET !== 'utf8mb4') {
    fwrite(STDERR, "charset default failed\n");
    exit(8);
}
if (defined('AP_TELEMETRY')) {
    fwrite(STDERR, "AP_TELEMETRY must not exist\n");
    exit(9);
}
// Idempotent second call.
if (ap_load_config($argv[1], false) !== true) {
    fwrite(STDERR, "second load failed\n");
    exit(10);
}
echo "ok\n";
exit(0);
PHP);

        $this->assertSame(0, $result['exit'], $result['body']);
        $this->assertStringContainsString('ok', $result['body']);
    }

    public function testIncompleteConfigFailsWithoutExitWhenRequested(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'apcfg');
        $this->assertNotFalse($tmp);
        // Minimal incomplete config (no required defines).
        file_put_contents($tmp, "<?php\ndeclare(strict_types=1);\n\$table_prefix = 'ap_';\n");

        try {
            $result = $this->runLoadConfigScript($tmp, <<<'PHP'
$ok = ap_load_config($argv[1], false);
if ($ok !== false) {
    fwrite(STDERR, "expected false for incomplete config\n");
    exit(2);
}
if (ap_config_is_loaded()) {
    fwrite(STDERR, "should not mark loaded\n");
    exit(3);
}
$missing = ap_missing_config_constants();
if ($missing === []) {
    fwrite(STDERR, "expected missing constants\n");
    exit(4);
}
echo "ok\n";
exit(0);
PHP);

            $this->assertSame(0, $result['exit'], $result['body']);
            $this->assertStringContainsString('ok', $result['body']);
        } finally {
            if (is_file($tmp)) {
                unlink($tmp);
            }
        }
    }

    public function testInvalidDriverRejected(): void
    {
        $tmp = $this->writeMinimalConfig([
            'AP_DB_DRIVER' => 'oracle',
        ]);

        try {
            $result = $this->runLoadConfigScript($tmp, <<<'PHP'
$ok = ap_load_config($argv[1], false);
if ($ok !== false) {
    fwrite(STDERR, "expected false for bad driver\n");
    exit(2);
}
echo "ok\n";
exit(0);
PHP);

            $this->assertSame(0, $result['exit'], $result['body']);
            $this->assertStringContainsString('ok', $result['body']);
        } finally {
            if (is_file($tmp)) {
                unlink($tmp);
            }
        }
    }

    public function testCustomTablePrefixNormalizedAndExposed(): void
    {
        $tmp = $this->writeMinimalConfig([
            'AP_DB_DRIVER' => 'sqlite',
        ], 'myblog_');

        try {
            $result = $this->runLoadConfigScript($tmp, <<<'PHP'
$ok = ap_load_config($argv[1], false);
if ($ok !== true) {
    fwrite(STDERR, "load failed\n");
    exit(2);
}
if (AP_TABLE_PREFIX !== 'myblog_' || ap_get_table_prefix() !== 'myblog_') {
    fwrite(STDERR, "prefix=" . ap_get_table_prefix() . "\n");
    exit(3);
}
echo "ok\n";
exit(0);
PHP);

            $this->assertSame(0, $result['exit'], $result['body']);
            $this->assertStringContainsString('ok', $result['body']);
        } finally {
            if (is_file($tmp)) {
                unlink($tmp);
            }
        }
    }

    public function testIndexWithValidConfigDoesNotShowConfigError(): void
    {
        $configPath = $this->root . '/ap-config.php';
        $created = false;

        if (!is_readable($configPath)) {
            $ok = copy($this->root . '/ap-config-sample.php', $configPath);
            $this->assertTrue($ok);
            $created = true;
        }

        try {
            $php = PHP_BINARY !== '' ? PHP_BINARY : 'php';
            $index = $this->root . '/index.php';
            $cmd = escapeshellarg($php)
                . ' -d display_errors=1 -d error_reporting=E_ALL '
                . escapeshellarg($index)
                . ' 2>&1';

            $output = [];
            $exit = 0;
            exec($cmd, $output, $exit);
            $body = implode("\n", $output);

            $this->assertSame(0, $exit, "Output:\n{$body}");
            $this->assertStringNotContainsString('Configuration error', $body);
            $this->assertStringNotContainsString('AgoraPress is not installed', $body);
            $this->assertStringNotContainsString('Fatal error', $body);
        } finally {
            if ($created && is_file($configPath)) {
                unlink($configPath);
            }
        }
    }

    public function testBootstrapRequiresLoadConfig(): void
    {
        $src = (string) file_get_contents($this->root . '/ap-includes/bootstrap.php');
        $this->assertStringContainsString('load-config.php', $src);
        $this->assertStringContainsString('ap_load_config', $src);
    }

    /**
     * Write a minimal valid-shaped config file for isolated load tests.
     *
     * @param array<string, string> $overrides Constant name => PHP value literal-ish string.
     */
    private function writeMinimalConfig(array $overrides = [], string $tablePrefix = 'ap_'): string
    {
        $defaults = [
            'AP_DB_DRIVER' => 'mysql',
            'AP_DB_NAME' => 'test_db',
            'AP_DB_USER' => 'test_user',
            'AP_DB_PASSWORD' => 'test_pass',
            'AP_DB_HOST' => 'localhost',
            'AP_AUTH_KEY' => 'k1',
            'AP_SECURE_AUTH_KEY' => 'k2',
            'AP_LOGGED_IN_KEY' => 'k3',
            'AP_NONCE_KEY' => 'k4',
            'AP_AUTH_SALT' => 's1',
            'AP_SECURE_AUTH_SALT' => 's2',
            'AP_LOGGED_IN_SALT' => 's3',
            'AP_NONCE_SALT' => 's4',
        ];
        $values = array_merge($defaults, $overrides);

        $lines = ["<?php", "declare(strict_types=1);", ""];
        foreach ($values as $name => $value) {
            $lines[] = "define('{$name}', " . var_export($value, true) . ');';
        }
        $lines[] = '$table_prefix = ' . var_export($tablePrefix, true) . ';';
        $lines[] = '';

        $tmp = tempnam(sys_get_temp_dir(), 'apcfg');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, implode("\n", $lines));

        return $tmp;
    }

    /**
     * Run a PHP snippet with load-config.php required, passing $configPath as $argv[1].
     *
     * @return array{exit: int, body: string}
     */
    private function runLoadConfigScript(string $configPath, string $body): array
    {
        $php = PHP_BINARY !== '' ? PHP_BINARY : 'php';
        $absPath = var_export($this->root . '/', true);
        $versionPath = var_export($this->root . '/ap-includes/version.php', true);
        $loadPath = var_export($this->loadConfigPath, true);

        $script = "declare(strict_types=1);\n"
            . "define('AP_ABSPATH', {$absPath});\n"
            . "require {$versionPath};\n"
            . "require {$loadPath};\n"
            . $body;

        $cmd = escapeshellarg($php)
            . ' -d display_errors=1 -d error_reporting=E_ALL -r '
            . escapeshellarg($script)
            . ' -- '
            . escapeshellarg($configPath)
            . ' 2>&1';

        $output = [];
        $exit = 0;
        exec($cmd, $output, $exit);

        return [
            'exit' => $exit,
            'body' => implode("\n", $output),
        ];
    }
}
