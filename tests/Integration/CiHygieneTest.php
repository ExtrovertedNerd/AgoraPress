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
            '/continue-on-error/i',
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

    public function testDoesNotDeletePreexistingPhpunitSuites(): void
    {
        $missing = $this->missingPathsFromFixture('last-release-phpunit-suites.txt');
        $this->assertSame(
            [],
            $missing,
            'Do not delete pre-existing PHPUnit suites to look green'
        );
    }

    public function testDoesNotDeletePreexistingPytestFiles(): void
    {
        $missing = $this->missingPathsFromFixture('last-release-pytest-files.txt');
        $this->assertSame(
            [],
            $missing,
            'Do not delete pre-existing pytest files to look green'
        );
    }

    public function testLastReleaseFixtureCannotShrinkOrEmptySuites(): void
    {
        $phpunit = $this->pathsFromFixture('last-release-phpunit-suites.txt');
        $pytest = $this->pathsFromFixture('last-release-pytest-files.txt');

        $this->assertSame(
            $phpunit,
            array_values(array_unique($phpunit)),
            'Do not pad the last-release PHPUnit lock with duplicate rows'
        );
        $this->assertSame(
            $pytest,
            array_values(array_unique($pytest)),
            'Do not pad the last-release pytest lock with duplicate rows'
        );
        $this->assertGreaterThanOrEqual(
            115,
            count($phpunit),
            'Do not shrink the v0.3.9-beta PHPUnit lock to look green'
        );
        $this->assertGreaterThanOrEqual(
            93,
            count($pytest),
            'Do not shrink the v0.3.9-beta pytest lock to look green'
        );

        foreach ($phpunit as $relative) {
            $src = (string) file_get_contents($this->root . '/' . $relative);
            $this->assertMatchesRegularExpression(
                '/function\s+test/i',
                $src,
                "Do not empty last-release suite {$relative} to look green"
            );
        }
        foreach ($pytest as $relative) {
            $src = (string) file_get_contents($this->root . '/' . $relative);
            $this->assertMatchesRegularExpression(
                '/^def test_/m',
                $src,
                "Do not empty last-release pytest file {$relative} to look green"
            );
        }
    }

    public function testPhpunitStillDiscoversLastReleaseSuites(): void
    {
        $phpunit = $this->root . '/vendor/bin/phpunit';
        if (!is_file($phpunit)) {
            $this->markTestSkipped('vendor/bin/phpunit not installed');
        }

        $config = $this->root . '/phpunit.xml.dist';
        $cmd = escapeshellarg(PHP_BINARY !== '' ? PHP_BINARY : 'php')
            . ' ' . escapeshellarg($phpunit)
            . ' --configuration=' . escapeshellarg($config)
            . ' --list-tests'
            . ' 2>&1';

        $output = [];
        $exit = 0;
        exec($cmd, $output, $exit);
        $body = implode("\n", $output);

        $this->assertSame(0, $exit, "phpunit --list-tests failed:\n{$body}");
        foreach ($this->pathsFromFixture('last-release-phpunit-suites.txt') as $relative) {
            $class = basename($relative, '.php');
            $this->assertStringContainsString(
                $class,
                $body,
                "PHPUnit must still discover last-release suite {$relative}"
            );
        }
    }

    public function testPhpstanDoesNotHideCoreOrRaiseLevelToLookGreen(): void
    {
        $path = $this->root . '/phpstan.neon.dist';
        $raw = (string) file_get_contents($path);

        $this->assertDoesNotMatchRegularExpression(
            '/^    ignoreErrors:/m',
            $raw,
            'Do not add PHPStan ignoreErrors as a substitute CI-green charter'
        );
        $this->assertStringContainsString('reportUnmatchedIgnoredErrors: true', $raw);
        $this->assertStringContainsString("- ap-includes\n", $raw);
        $this->assertStringContainsString('- ap-includes/compatibility/*', $raw);
        $this->assertStringNotContainsString(
            'class-ap-forum-notify',
            $raw,
            'Do not exclude charter files from PHPStan to look green'
        );
    }

    public function testPhpcsDoesNotExcludeProductPathsToLookGreen(): void
    {
        $path = $this->root . '/phpcs.xml.dist';
        $raw = (string) file_get_contents($path);

        $this->assertStringContainsString('<exclude-pattern>*/vendor/*</exclude-pattern>', $raw);
        $this->assertStringContainsString('<exclude-pattern>*/ap-content/*</exclude-pattern>', $raw);
        $this->assertStringContainsString('<exclude-pattern>*/.hephaestus/*</exclude-pattern>', $raw);
        $this->assertStringContainsString('<exclude-pattern>*/node_modules/*</exclude-pattern>', $raw);
        $this->assertStringNotContainsString(
            'ap-includes/*',
            $raw,
            'Do not exclude ap-includes from PHPCS to look green'
        );
        $this->assertStringNotContainsString(
            '*/tests/*',
            $raw,
            'Do not exclude tests from PHPCS to look green'
        );
    }

    /**
     * @return list<string>
     */
    private function pathsFromFixture(string $name): array
    {
        $path = $this->root . '/tests/Integration/fixtures/' . $name;
        $this->assertFileIsReadable($path, 'Missing CI hygiene fixture: ' . $name);
        $lines = preg_split("/\R/", (string) file_get_contents($path)) ?: [];
        $relativePaths = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $relativePaths[] = $line;
        }
        $this->assertNotEmpty($relativePaths, $name . ' must list last-release tests');

        return $relativePaths;
    }

    /**
     * @return list<string>
     */
    private function missingPathsFromFixture(string $name): array
    {
        $missing = [];
        foreach ($this->pathsFromFixture($name) as $relative) {
            if (!is_file($this->root . '/' . $relative)) {
                $missing[] = $relative;
            }
        }

        return $missing;
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
