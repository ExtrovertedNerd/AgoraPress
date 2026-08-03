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
}
