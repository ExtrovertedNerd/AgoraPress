<?php

/**
 * Smoke tests for install/index.php web UI entry.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Install;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class InstallUiTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testInstallEntryExists(): void
    {
        $this->assertFileIsReadable($this->root . '/install/index.php');
        $this->assertFileIsReadable($this->root . '/ap-includes/class-ap-requirements.php');
        $this->assertFileIsReadable($this->root . '/ap-includes/class-ap-installer.php');
    }

    public function testInstallIndexRendersRequirementsWhenNotInstalled(): void
    {
        if (is_readable($this->root . '/ap-config.php')) {
            $this->markTestSkipped('ap-config.php present; installer blocks as already installed');
        }

        $php = PHP_BINARY !== '' ? PHP_BINARY : 'php';
        $script = $this->root . '/install/index.php';
        $cmd = escapeshellarg($php)
            . ' -d display_errors=1 -d error_reporting=E_ALL '
            . escapeshellarg($script)
            . ' 2>&1';

        $output = [];
        $exit = 0;
        exec($cmd, $output, $exit);
        $body = implode("\n", $output);

        $this->assertSame(0, $exit, "install/index.php failed:\n{$body}");
        $this->assertStringContainsString('AgoraPress installer', $body);
        $this->assertStringContainsString('Server requirements', $body);
        $this->assertStringContainsString('PHP version', $body);
        $this->assertStringNotContainsString('Fatal error', $body);
        $this->assertStringNotContainsString('Parse error', $body);
        $this->assertStringNotContainsString('Uncaught', $body);
    }

    public function testInstallIndexBlocksWhenConfigExists(): void
    {
        $configPath = $this->root . '/ap-config.php';
        $created = false;
        if (!is_readable($configPath)) {
            file_put_contents($configPath, "<?php\n// temporary for install UI test\n");
            $created = true;
        }

        try {
            $php = PHP_BINARY !== '' ? PHP_BINARY : 'php';
            $script = $this->root . '/install/index.php';
            $cmd = escapeshellarg($php)
                . ' -d display_errors=1 -d error_reporting=E_ALL '
                . escapeshellarg($script)
                . ' 2>&1';

            $output = [];
            $exit = 0;
            exec($cmd, $output, $exit);
            $body = implode("\n", $output);

            $this->assertSame(0, $exit, "install/index.php failed:\n{$body}");
            $this->assertStringContainsString('Already installed', $body);
            $this->assertStringContainsString('ap-config.php', $body);
            $this->assertStringContainsString('will not overwrite', $body);
            $this->assertStringNotContainsString('Server requirements', $body);
            $this->assertStringNotContainsString('Fatal error', $body);
        } finally {
            if ($created && is_file($configPath)) {
                unlink($configPath);
            }
        }
    }

    public function testInstallIndexBlocksStepDoneWhenConfigExistsWithoutSession(): void
    {
        $configPath = $this->root . '/ap-config.php';
        $created = false;
        if (!is_readable($configPath)) {
            file_put_contents($configPath, "<?php\n// temporary for install UI step=done test\n");
            $created = true;
        }

        try {
            $php = PHP_BINARY !== '' ? PHP_BINARY : 'php';
            $script = $this->root . '/install/index.php';
            // Direct ?step=done visit without a just-finished install session.
            $code = '$_GET["step"]="done"; $_SERVER["REQUEST_METHOD"]="GET"; require '
                . var_export($script, true) . ';';
            $cmd = escapeshellarg($php)
                . ' -d display_errors=1 -d error_reporting=E_ALL -r '
                . escapeshellarg($code)
                . ' 2>&1';

            $output = [];
            $exit = 0;
            exec($cmd, $output, $exit);
            $body = implode("\n", $output);

            $this->assertSame(0, $exit, "install/index.php ?step=done failed:\n{$body}");
            $this->assertStringContainsString('Already installed', $body);
            $this->assertStringNotContainsString('Installation complete', $body);
            $this->assertStringNotContainsString('Fatal error', $body);
        } finally {
            if ($created && is_file($configPath)) {
                unlink($configPath);
            }
        }
    }

    public function testInstallerSourceHasAlreadyInstalledGuard(): void
    {
        $src = (string) file_get_contents($this->root . '/install/index.php');
        $this->assertStringContainsString('Already installed', $src);
        $this->assertStringContainsString('AP_Installer::configExists', $src);
        $this->assertStringContainsString('http_response_code(403)', $src);
        $this->assertStringContainsString('ap_install_success', $src);

        $installer = (string) file_get_contents($this->root . '/ap-includes/class-ap-installer.php');
        $this->assertStringContainsString('function configExists', $installer);
        $this->assertStringContainsString('function alreadyInstalledMessage', $installer);
    }

    public function testInstallerCssKeepsFieldTextReadableInDarkMode(): void
    {
        $src = (string) file_get_contents($this->root . '/install/index.php');

        $this->assertStringContainsString('color-scheme: light', $src);
        $this->assertStringContainsString('color-scheme: dark', $src);
        $this->assertStringContainsString('-webkit-text-fill-color: var(--fg)', $src);
        $this->assertStringContainsString('input:-webkit-autofill', $src);
        $this->assertStringContainsString('--accent-text', $src);
        $this->assertStringContainsString('color-scheme: inherit', $src);
        $this->assertStringContainsString('select, textarea', $src);
        $this->assertStringContainsString('select option', $src);
        // Advertising both schemes at once lets the UA paint white control
        // text onto author light field backgrounds during form steps.
        $this->assertStringNotContainsString('color-scheme: light dark', $src);
    }

    public function testNotInstalledPageLinksToInstaller(): void
    {
        if (!defined('AP_ABSPATH')) {
            define('AP_ABSPATH', $this->root . '/');
        }
        require_once $this->root . '/ap-includes/bootstrap.php';

        $html = ap_get_not_installed_html();
        $this->assertStringContainsString('install/', $html);
        $this->assertStringContainsString('Run the web installer', $html);
        $this->assertStringContainsString('requirements', $html);
    }
}
