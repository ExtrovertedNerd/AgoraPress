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

    public function testPhpcsLineLengthWarningsRemainAdvisory(): void
    {
        $path = $this->root . '/phpcs.xml.dist';
        $this->assertFileIsReadable($path);
        $raw = (string) file_get_contents($path);

        $this->assertMatchesRegularExpression(
            '/<config\s+name="ignore_warnings_on_exit"\s+value="1"\s*\/>/',
            $raw,
            'Advisory PHPCS warnings must not fail CI (not a zero-warning charter)'
        );
        $this->assertStringContainsString('Generic.Files.LineLength', $raw);
        $this->assertStringContainsString(
            'lineLimit',
            $raw,
            'Line-length stays a warning with a limit, not a hard error charter'
        );
    }

    public function testPhpstanLevelIsNotRaisedAsACiGreenCharter(): void
    {
        $path = $this->root . '/phpstan.neon.dist';
        $this->assertFileIsReadable($path);
        $raw = (string) file_get_contents($path);

        $this->assertMatchesRegularExpression(
            '/^    level:\s*3\s*$/m',
            $raw,
            'Do not raise PHPStan level as a substitute CI-green charter'
        );
    }

    public function testComposerScriptsDoNotSwallowFailuresOrHideSuites(): void
    {
        $path = $this->root . '/composer.json';
        $this->assertFileIsReadable($path);
        $composer = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($composer);
        $scripts = $composer['scripts'] ?? [];
        $this->assertIsArray($scripts);

        foreach (['test', 'cs:check', 'analyse'] as $name) {
            $this->assertArrayHasKey($name, $scripts, "Missing composer script {$name}");
            $cmd = $scripts[$name];
            if (is_array($cmd)) {
                $cmd = implode("\n", $cmd);
            }
            $this->assertIsString($cmd, "composer script {$name} must be a string or list");
            $this->assertStringNotContainsString(
                '|| true',
                $cmd,
                "composer {$name} must not swallow a non-zero exit"
            );
            $this->assertStringNotContainsString(
                '--filter',
                $cmd,
                "composer {$name} must not hide suites behind --filter"
            );
            $this->assertDoesNotMatchRegularExpression(
                '/--exclude[^-]/',
                $cmd,
                "composer {$name} must not exclude tests to look green"
            );
        }

        $this->assertSame(
            'phpunit',
            $scripts['test'],
            'composer test must run the full PHPUnit suite'
        );
        $this->assertSame(
            'phpcs',
            $scripts['cs:check'],
            'composer cs:check must run phpcs (warnings stay advisory via phpcs.xml.dist)'
        );
        $this->assertStringContainsString('phpstan analyse', (string) $scripts['analyse']);
    }

    public function testGithubWorkflowRunStepsDoNotSwallowFailures(): void
    {
        $path = $this->root . '/.github/workflows/ci.yml';
        $raw = (string) file_get_contents($path);

        $this->assertStringNotContainsString(
            '|| true',
            $raw,
            'CI run steps must not swallow failures with || true'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/composer test\s+--/',
            $raw,
            'CI must not pass extra phpunit flags that hide suites'
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
