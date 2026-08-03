<?php

/**
 * Assert the basic PHPUnit / static-analysis skeleton is present and runnable.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Phpunit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class PhpunitSkeletonTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testPhpunitXmlDistExists(): void
    {
        $path = $this->root . '/phpunit.xml.dist';
        $this->assertFileIsReadable($path, 'phpunit.xml.dist must exist at project root');
    }

    public function testPhpunitBootstrapExists(): void
    {
        $path = $this->root . '/tests/bootstrap.php';
        $this->assertFileIsReadable($path, 'tests/bootstrap.php must exist');
    }

    public function testPhpunitXmlIsValidAndWiresBootstrap(): void
    {
        $path = $this->root . '/phpunit.xml.dist';
        $raw = file_get_contents($path);
        $this->assertNotFalse($raw);

        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($raw);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $this->assertNotFalse($xml, 'phpunit.xml.dist must be well-formed XML');
        $this->assertSame([], $errors);

        $bootstrap = (string) ($xml['bootstrap'] ?? '');
        $this->assertSame(
            'tests/bootstrap.php',
            $bootstrap,
            'phpunit.xml.dist must bootstrap tests/bootstrap.php'
        );

        $suiteDirs = [];
        foreach ($xml->testsuites->testsuite as $suite) {
            foreach ($suite->directory as $dir) {
                $suiteDirs[] = (string) $dir;
            }
        }
        $this->assertContains('tests', $suiteDirs, 'Testsuite must scan tests/');

        $sourceDirs = [];
        if (isset($xml->source->include->directory)) {
            foreach ($xml->source->include->directory as $dir) {
                $sourceDirs[] = (string) $dir;
            }
        }
        $this->assertContains(
            'ap-includes',
            $sourceDirs,
            'Coverage source should include ap-includes'
        );
    }

    public function testComposerScriptsIncludeTestAndCs(): void
    {
        $path = $this->root . '/composer.json';
        $raw = file_get_contents($path);
        $this->assertNotFalse($raw);
        $composer = json_decode($raw, true);
        $this->assertIsArray($composer);

        $scripts = $composer['scripts'] ?? [];
        $this->assertIsArray($scripts);
        $this->assertArrayHasKey('test', $scripts);
        $this->assertArrayHasKey('cs', $scripts);
        $this->assertArrayHasKey('cs:check', $scripts);
    }

    public function testStaticAnalysisConfigPresent(): void
    {
        // Style: PHP_CodeSniffer (PSR-12 adapted). Types: PHPStan level 3.
        $this->assertFileIsReadable($this->root . '/phpcs.xml.dist');
        $this->assertFileIsReadable($this->root . '/phpstan.neon.dist');
        $this->assertFileIsReadable($this->root . '/CODING_STANDARDS.md');
        $this->assertFileIsReadable($this->root . '/tests/phpstan-bootstrap.php');
    }

    public function testPhpunitBinaryRunsListTestsWhenAvailable(): void
    {
        $phpunit = $this->root . '/vendor/bin/phpunit';
        if (!is_file($phpunit)) {
            $this->markTestSkipped('vendor/bin/phpunit not installed (run composer install)');
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

        $this->assertSame(
            0,
            $exit,
            "phpunit --list-tests failed:\n{$body}"
        );
        $this->assertStringContainsString(
            'PhpunitSkeletonTest',
            $body,
            'PHPUnit should discover this skeleton test class'
        );
    }

    public function testGitignoreIgnoresPhpunitAndPhpcsCaches(): void
    {
        $path = $this->root . '/.gitignore';
        $raw = file_get_contents($path);
        $this->assertNotFalse($raw);

        $this->assertTrue(
            str_contains($raw, '.phpunit.cache')
            || str_contains($raw, '.phpunit.cache/'),
            '.gitignore must ignore PHPUnit cache directory'
        );
        $this->assertTrue(
            str_contains($raw, '.phpcs.cache')
            || str_contains($raw, '*.cache'),
            '.gitignore must ignore PHPCS cache'
        );
    }
}
