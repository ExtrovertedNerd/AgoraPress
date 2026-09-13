<?php

/**
 * Lock GitHub CI as hard-fail. Do not mute jobs or hide tests to look green.
 *
 * This is not a charter to drive PHPCS warnings to zero. Line-length remains
 * advisory (phpcs.xml.dist ignore_warnings_on_exit). Pre-existing skips stay.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class CiHygieneTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testGithubWorkflowHardFailsPhpunitPhpcsPhpstan(): void
    {
        $path = $this->root . '/.github/workflows/ci.yml';
        $this->assertFileIsReadable($path, 'Missing .github/workflows/ci.yml');
        $raw = (string) file_get_contents($path);

        $this->assertDoesNotMatchRegularExpression(
            '/continue-on-error\s*:\s*true/i',
            $raw,
            'CI must not soft-fail jobs without tracked debt'
        );
        $this->assertStringContainsString('composer test', $raw);
        $this->assertStringContainsString('composer cs:check', $raw);
        $this->assertStringContainsString('composer analyse', $raw);
        $this->assertStringContainsString('"8.2"', $raw);
        $this->assertStringContainsString('"8.3"', $raw);
        $this->assertStringContainsString('"8.4"', $raw);
    }

    public function testPhpunitKeepsFailOnRiskyAndDoesNotExcludeTests(): void
    {
        $path = $this->root . '/phpunit.xml.dist';
        $this->assertFileIsReadable($path);
        $raw = (string) file_get_contents($path);

        $this->assertStringContainsString('failOnRisky="true"', $raw);
        $this->assertStringContainsString('failOnWarning="true"', $raw);
        $this->assertStringNotContainsString(
            '<exclude',
            $raw,
            'phpunit.xml.dist must not exclude suites to look green'
        );
        $this->assertStringContainsString(
            '<directory suffix="Test.php">tests</directory>',
            $raw
        );
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function specMinimumCaseProvider(): array
    {
        return CharterSpecTest::specMinimumCaseProvider();
    }

    #[DataProvider('specMinimumCaseProvider')]
    public function testCharterSpecMethodIsNotSkipped(string $relative, string $method): void
    {
        $path = $this->root . '/' . $relative;
        $this->assertFileIsReadable($path, "Missing charter suite: {$relative}");
        $src = (string) file_get_contents($path);
        $this->assertStringContainsString('function ' . $method, $src);

        $quoted = preg_quote($method, '/');
        $matched = preg_match(
            '/function\s+' . $quoted . '\s*\([^)]*\)\s*(?::\s*\w+\s*)?\{(.*?)'
            . '(?=\n    public function |\n\}\s*\z)/s',
            $src,
            $m
        );
        $this->assertSame(1, $matched, "Could not isolate {$method} in {$relative}");
        $this->assertStringNotContainsString(
            'markTestSkipped',
            $m[1],
            "{$relative}::{$method} must not skip to look green"
        );
    }
}
