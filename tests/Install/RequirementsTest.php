<?php

/**
 * Tests for AP_Requirements (installer server checks).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Install;

use AP_Requirements;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_Requirements::class)]
final class RequirementsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        require_once $this->root . '/ap-includes/class-ap-requirements.php';
    }

    public function testCheckReturnsStructuredResults(): void
    {
        $checks = AP_Requirements::check($this->root . '/');
        $this->assertNotSame([], $checks);

        $ids = [];
        foreach ($checks as $check) {
            $this->assertArrayHasKey('id', $check);
            $this->assertArrayHasKey('label', $check);
            $this->assertArrayHasKey('ok', $check);
            $this->assertArrayHasKey('required', $check);
            $this->assertArrayHasKey('message', $check);
            $this->assertIsBool($check['ok']);
            $this->assertIsBool($check['required']);
            $ids[] = $check['id'];
        }

        $this->assertContains('php_version', $ids);
        $this->assertContains('pdo_driver', $ids);
        $this->assertContains('writable_config', $ids);
        $this->assertContains('writable_uploads', $ids);
        $this->assertContains('ext_pdo', $ids);
        $this->assertContains('ext_mbstring', $ids);
    }

    public function testPhpVersionCheckPassesOnModernPhp(): void
    {
        $checks = AP_Requirements::check($this->root . '/');
        $php = null;
        foreach ($checks as $check) {
            if ($check['id'] === 'php_version') {
                $php = $check;
                break;
            }
        }
        $this->assertNotNull($php);
        $this->assertTrue($php['ok']);
        $this->assertTrue($php['required']);
    }

    public function testAllRequiredPassedFalseWhenAnyRequiredFails(): void
    {
        $checks = [
            ['ok' => true, 'required' => true],
            ['ok' => false, 'required' => true],
            ['ok' => false, 'required' => false],
        ];
        $this->assertFalse(AP_Requirements::allRequiredPassed($checks));
    }

    public function testAllRequiredPassedIgnoresRecommendedFailures(): void
    {
        $checks = [
            ['ok' => true, 'required' => true],
            ['ok' => false, 'required' => false],
        ];
        $this->assertTrue(AP_Requirements::allRequiredPassed($checks));
    }

    public function testPathIsWritableForCreateOnTempFile(): void
    {
        $dir = sys_get_temp_dir();
        $path = $dir . '/ap-req-write-' . uniqid('', true) . '.php';
        if (is_file($path)) {
            unlink($path);
        }
        $this->assertTrue(AP_Requirements::pathIsWritableForCreate($path, $dir));
    }

    public function testRequiredExtensionsListIsStable(): void
    {
        $required = AP_Requirements::requiredExtensions();
        $this->assertArrayHasKey('pdo', $required);
        $this->assertArrayHasKey('mbstring', $required);
        $this->assertArrayHasKey('json', $required);
        $this->assertArrayHasKey('curl', $required);
        $this->assertArrayHasKey('fileinfo', $required);
        $this->assertArrayHasKey('zip', $required);
    }

    public function testEnsureUploadsDirectoryCreatesWhenContentIsWritable(): void
    {
        $site = sys_get_temp_dir() . '/ap-req-uploads-' . uniqid('', true);
        $content = $site . '/ap-content';
        $uploads = $content . '/uploads';
        $this->assertTrue(mkdir($content, 0700, true));
        $this->assertDirectoryDoesNotExist($uploads);

        try {
            $ok = AP_Requirements::ensureUploadsDirectory($site . '/');
            $this->assertTrue($ok);
            $this->assertDirectoryExists($uploads);
            $this->assertTrue(is_writable($uploads));
            $index = $uploads . '/index.php';
            $this->assertFileExists($index);
            $src = (string) file_get_contents($index);
            $this->assertStringContainsString('http_response_code(403)', $src);

            $again = AP_Requirements::ensureUploadsDirectory($site . '/');
            $this->assertTrue($again);
            $this->assertSame($src, (string) file_get_contents($index));

            $checks = AP_Requirements::check($site . '/');
            $uploadsCheck = null;
            foreach ($checks as $check) {
                if ($check['id'] === 'writable_uploads') {
                    $uploadsCheck = $check;
                    break;
                }
            }
            $this->assertNotNull($uploadsCheck);
            $this->assertTrue($uploadsCheck['ok']);
            $this->assertTrue($uploadsCheck['required']);
        } finally {
            if (is_file($uploads . '/index.php')) {
                @unlink($uploads . '/index.php');
            }
            if (is_dir($uploads)) {
                @rmdir($uploads);
            }
            if (is_dir($content)) {
                @rmdir($content);
            }
            if (is_dir($site)) {
                @rmdir($site);
            }
        }
    }

    public function testEnsureUploadsDirectoryFailsWhenContentIsMissing(): void
    {
        $site = sys_get_temp_dir() . '/ap-req-noconent-' . uniqid('', true);
        $this->assertTrue(mkdir($site, 0700, true));

        try {
            $this->assertFalse(AP_Requirements::ensureUploadsDirectory($site . '/'));
            $this->assertDirectoryDoesNotExist($site . '/ap-content/uploads');
        } finally {
            @rmdir($site);
        }
    }
}
