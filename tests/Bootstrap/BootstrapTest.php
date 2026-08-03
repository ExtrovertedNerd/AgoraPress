<?php

/**
 * Tests for install detection and graceful not-installed bootstrap.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Bootstrap;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class BootstrapTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        $bootstrap = $this->root . '/ap-includes/bootstrap.php';
        $this->assertFileIsReadable($bootstrap);

        if (!defined('AP_ABSPATH')) {
            define('AP_ABSPATH', $this->root . '/');
        }

        require_once $bootstrap;
    }

    public function testVersionConstantIsDefined(): void
    {
        $this->assertTrue(defined('AP_VERSION'));
        $this->assertNotSame('', (string) AP_VERSION);
        $this->assertTrue(defined('AP_DB_VERSION'));
    }

    public function testConfigPathPointsAtRootApConfig(): void
    {
        $this->assertSame(
            $this->root . '/ap-config.php',
            ap_config_path()
        );
    }

    public function testIsInstalledFalseWhenConfigMissing(): void
    {
        $missing = sys_get_temp_dir() . '/agorapress-no-config-' . uniqid('', true) . '.php';
        if (is_file($missing)) {
            unlink($missing);
        }

        $this->assertFalse(ap_is_installed($missing));
    }

    public function testIsInstalledTrueWhenConfigReadable(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'apcfg');
        $this->assertNotFalse($path);
        file_put_contents($path, "<?php\n// test config\n");

        try {
            $this->assertTrue(ap_is_installed($path));
        } finally {
            unlink($path);
        }
    }

    public function testIsInstalledUsesProjectConfigWhenPresent(): void
    {
        $projectConfig = $this->root . '/ap-config.php';
        $expected = is_readable($projectConfig);
        $this->assertSame($expected, ap_is_installed());
    }

    public function testPhpVersionCheckMatchesRuntime(): void
    {
        $this->assertSame(
            PHP_VERSION_ID >= 80200,
            ap_php_version_is_supported()
        );
        // PHPUnit itself requires modern PHP; this suite runs on 8.2+.
        $this->assertTrue(ap_php_version_is_supported());
    }

    public function testNotInstalledHtmlIsSafeDocument(): void
    {
        $html = ap_get_not_installed_html();

        $this->assertStringContainsString('<!DOCTYPE html>', $html);
        $this->assertStringContainsString('lang="en"', $html);
        $this->assertStringContainsString('AgoraPress is not installed', $html);
        $this->assertStringContainsString('ap-config.php', $html);
        $this->assertStringContainsString('ap-config-sample.php', $html);
        $this->assertStringContainsString('charset="utf-8"', $html);
        $this->assertStringContainsString('install/', $html);
        $this->assertStringContainsString('Run the web installer', $html);
        // No raw PHP leakage.
        $this->assertStringNotContainsString('<?php', $html);
    }

    public function testPhpUnsupportedHtmlMentionsVersions(): void
    {
        $html = ap_get_php_unsupported_html('8.2', '7.4.0');

        $this->assertStringContainsString('PHP version not supported', $html);
        $this->assertStringContainsString('8.2', $html);
        $this->assertStringContainsString('7.4.0', $html);
        $this->assertStringNotContainsString('<?php', $html);
    }

    public function testIndexFailsGracefullyWhenNotInstalled(): void
    {
        // Skip only if a real local install config is present (developer machine).
        if (is_readable($this->root . '/ap-config.php')) {
            $this->markTestSkipped('ap-config.php present; cannot exercise not-installed path');
        }

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

        $this->assertSame(
            0,
            $exit,
            "index.php should exit gracefully (0), not fatally. Output:\n{$body}"
        );
        $this->assertStringContainsString('AgoraPress is not installed', $body);
        $this->assertStringContainsString('ap-config.php', $body);
        $this->assertStringNotContainsString('Fatal error', $body);
        $this->assertStringNotContainsString('Parse error', $body);
        $this->assertStringNotContainsString('Uncaught', $body);
    }

    public function testIndexLoadsInstalledConfigWithoutFatal(): void
    {
        $configPath = $this->root . '/ap-config.php';
        $created = false;

        if (!is_readable($configPath)) {
            // Minimal config that matches ap-config-sample shape (no DB connect yet).
            $sample = $this->root . '/ap-config-sample.php';
            $this->assertFileIsReadable($sample);
            $ok = copy($sample, $configPath);
            $this->assertTrue($ok, 'Failed to create temporary ap-config.php for test');
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

            $this->assertSame(
                0,
                $exit,
                "Installed path should not fatal. Output:\n{$body}"
            );
            $this->assertStringNotContainsString('Fatal error', $body);
            $this->assertStringNotContainsString('Parse error', $body);
            $this->assertStringNotContainsString('Uncaught', $body);
            // Should not show the not-installed screen when config exists.
            $this->assertStringNotContainsString('AgoraPress is not installed', $body);
        } finally {
            if ($created && is_file($configPath)) {
                unlink($configPath);
            }
        }
    }
}
