<?php

/**
 * Tests for ap-config-sample.php shape and loadability (Phase 0).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Config;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class ConfigSampleTest extends TestCase
{
    private string $root;

    private string $samplePath;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        $this->samplePath = $this->root . '/ap-config-sample.php';
        $this->assertFileIsReadable($this->samplePath);
    }

    public function testSampleDeclaresStrictTypes(): void
    {
        $src = (string) file_get_contents($this->samplePath);
        $this->assertStringContainsString('declare(strict_types=1);', $src);
    }

    public function testSampleSetsDefaultTablePrefix(): void
    {
        $src = (string) file_get_contents($this->samplePath);
        $this->assertMatchesRegularExpression(
            '/\$table_prefix\s*=\s*[\'"]ap_[\'"]\s*;/',
            $src,
            'Sample must default $table_prefix to ap_ (SPEC)'
        );
    }

    /**
     * @return list<array{0: string}>
     */
    public static function requiredConstantProvider(): array
    {
        return [
            ['AP_DB_DRIVER'],
            ['AP_DB_NAME'],
            ['AP_DB_USER'],
            ['AP_DB_PASSWORD'],
            ['AP_DB_HOST'],
            ['AP_DB_CHARSET'],
            ['AP_DB_COLLATE'],
            ['AP_AUTH_KEY'],
            ['AP_SECURE_AUTH_KEY'],
            ['AP_LOGGED_IN_KEY'],
            ['AP_NONCE_KEY'],
            ['AP_AUTH_SALT'],
            ['AP_SECURE_AUTH_SALT'],
            ['AP_LOGGED_IN_SALT'],
            ['AP_NONCE_SALT'],
            ['AP_DEBUG'],
            ['AP_DEBUG_DISPLAY'],
            ['AP_DEBUG_LOG'],
        ];
    }

    #[DataProvider('requiredConstantProvider')]
    public function testSampleDefinesConstant(string $name): void
    {
        $src = (string) file_get_contents($this->samplePath);
        $this->assertMatchesRegularExpression(
            '/define\s*\(\s*[\'"]' . preg_quote($name, '/') . '[\'"]/',
            $src,
            "Sample must define {$name}"
        );
    }

    public function testSampleCharsetAndCollateMatchSpec(): void
    {
        $src = (string) file_get_contents($this->samplePath);
        $this->assertMatchesRegularExpression(
            "/define\s*\(\s*['\"]AP_DB_CHARSET['\"]\s*,\s*['\"]utf8mb4['\"]/",
            $src
        );
        $this->assertMatchesRegularExpression(
            "/define\s*\(\s*['\"]AP_DB_COLLATE['\"]\s*,\s*['\"]utf8mb4_unicode_ci['\"]/",
            $src
        );
    }

    public function testSampleDefaultDriverIsMysql(): void
    {
        $src = (string) file_get_contents($this->samplePath);
        $this->assertMatchesRegularExpression(
            "/define\s*\(\s*['\"]AP_DB_DRIVER['\"]\s*,\s*['\"]mysql['\"]/",
            $src,
            'Primary database is MySQL (SPEC)'
        );
    }

    public function testNoTelemetryConstant(): void
    {
        $src = (string) file_get_contents($this->samplePath);
        $this->assertStringNotContainsString(
            'AP_TELEMETRY',
            $src,
            'There is no AP_TELEMETRY constant (constitution / SPEC privacy)'
        );
    }

    public function testDebugOffByDefault(): void
    {
        $src = (string) file_get_contents($this->samplePath);
        $this->assertMatchesRegularExpression(
            "/define\s*\(\s*['\"]AP_DEBUG['\"]\s*,\s*false\s*\)/",
            $src
        );
    }

    public function testSampleDefinesApAbspathSafely(): void
    {
        $src = (string) file_get_contents($this->samplePath);
        $this->assertStringContainsString("if (!defined('AP_ABSPATH'))", $src);
        $this->assertStringContainsString("define('AP_ABSPATH'", $src);
    }

    public function testSampleIsValidPhpAndLoadableInIsolation(): void
    {
        // Load a copy under a unique process so constants do not collide with
        // other tests in the same PHPUnit process that may already define them.
        $php = PHP_BINARY !== '' ? PHP_BINARY : 'php';
        // php -r does not accept a leading <?php tag.
        $script = <<<'PHP'
declare(strict_types=1);
$sample = $argv[1];
require $sample;
$required = [
    'AP_DB_DRIVER', 'AP_DB_NAME', 'AP_DB_USER', 'AP_DB_PASSWORD',
    'AP_DB_HOST', 'AP_DB_CHARSET', 'AP_DB_COLLATE',
    'AP_AUTH_KEY', 'AP_SECURE_AUTH_KEY', 'AP_LOGGED_IN_KEY', 'AP_NONCE_KEY',
    'AP_AUTH_SALT', 'AP_SECURE_AUTH_SALT', 'AP_LOGGED_IN_SALT', 'AP_NONCE_SALT',
    'AP_DEBUG', 'AP_DEBUG_DISPLAY', 'AP_DEBUG_LOG', 'AP_ABSPATH',
];
foreach ($required as $c) {
    if (!defined($c)) {
        fwrite(STDERR, "missing constant: {$c}\n");
        exit(2);
    }
}
if (defined('AP_TELEMETRY')) {
    fwrite(STDERR, "AP_TELEMETRY must not exist\n");
    exit(2);
}
if (!isset($table_prefix) || $table_prefix !== 'ap_') {
    fwrite(STDERR, "table_prefix must be ap_\n");
    exit(3);
}
if (AP_DB_DRIVER !== 'mysql') {
    fwrite(STDERR, "default driver must be mysql\n");
    exit(4);
}
if (AP_DB_CHARSET !== 'utf8mb4' || AP_DB_COLLATE !== 'utf8mb4_unicode_ci') {
    fwrite(STDERR, "charset/collate mismatch\n");
    exit(5);
}
if (AP_DEBUG !== false) {
    fwrite(STDERR, "debug must default false\n");
    exit(6);
}
if (!str_ends_with(AP_ABSPATH, '/')) {
    fwrite(STDERR, "AP_ABSPATH should end with /\n");
    exit(7);
}
echo "ok\n";
exit(0);
PHP;

        $cmd = escapeshellarg($php)
            . ' -d display_errors=1 -d error_reporting=E_ALL -r '
            . escapeshellarg($script)
            . ' -- '
            . escapeshellarg($this->samplePath)
            . ' 2>&1';

        $output = [];
        $exit = 0;
        exec($cmd, $output, $exit);
        $body = implode("\n", $output);

        $this->assertSame(0, $exit, "Sample load failed:\n{$body}");
        $this->assertStringContainsString('ok', $body);
    }

    public function testSampleDocumentsSupportedDrivers(): void
    {
        $src = (string) file_get_contents($this->samplePath);
        $this->assertStringContainsString('sqlite', $src);
        $this->assertStringContainsString('pgsql', $src);
        $this->assertStringContainsString('mysql', $src);
    }

    public function testSampleIsNotRealConfigFilename(): void
    {
        // Guard: we ship the sample, never a committed ap-config.php secret file.
        $this->assertStringEndsWith('ap-config-sample.php', $this->samplePath);
        $this->assertFileDoesNotExist(
            // If a real config is present locally that is fine for dev machines;
            // this only asserts the sample itself is not named ap-config.php.
            dirname($this->samplePath) . '/ap-config-sample.php.bak'
        );
        $this->assertSame('ap-config-sample.php', basename($this->samplePath));
    }
}
