<?php

/**
 * Assert composer.json stays minimal: no production packages, PHP 8.2+, PHPUnit only.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Composer;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class ComposerJsonTest extends TestCase
{
    private string $root;

    /** @var array<string, mixed> */
    private array $composer;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        $path = $this->root . '/composer.json';
        $this->assertFileIsReadable($path, 'composer.json must exist and be readable');

        $raw = file_get_contents($path);
        $this->assertNotFalse($raw);
        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded, 'composer.json must be valid JSON');
        $this->assertSame(JSON_ERROR_NONE, json_last_error());
        $this->composer = $decoded;
    }

    public function testPackageMetadata(): void
    {
        $this->assertSame('extrovertednerd/agorapress', $this->composer['name'] ?? null);
        $this->assertSame('project', $this->composer['type'] ?? null);
        $this->assertSame('GPL-2.0-or-later', $this->composer['license'] ?? null);
        $this->assertArrayHasKey('description', $this->composer);
        $this->assertNotSame('', trim((string) $this->composer['description']));
    }

    public function testPhpVersionConstraintMatchesSpec(): void
    {
        $require = $this->composer['require'] ?? [];
        $this->assertIsArray($require);
        $this->assertArrayHasKey('php', $require);

        $constraint = (string) $require['php'];
        // SPEC: PHP 8.2 or higher
        $this->assertMatchesRegularExpression(
            '/^>=\s*8\.2(\.0)?$|^\\^8\.2/',
            $constraint,
            "PHP constraint must require 8.2+: got {$constraint}"
        );
    }

    public function testRequiredExtensionsFromSpec(): void
    {
        $require = $this->composer['require'] ?? [];
        $this->assertIsArray($require);

        // SPEC §1 required extensions that are unambiguous (no OR alternatives).
        $requiredExts = [
            'ext-pdo',
            'ext-mbstring',
            'ext-json',
            'ext-curl',
            'ext-fileinfo',
            'ext-zip',
        ];

        foreach ($requiredExts as $ext) {
            $this->assertArrayHasKey(
                $ext,
                $require,
                "composer.json require must declare {$ext} (SPEC §1)"
            );
        }
    }

    public function testNoProductionComposerPackages(): void
    {
        $require = $this->composer['require'] ?? [];
        $this->assertIsArray($require);

        foreach (array_keys($require) as $name) {
            $this->assertTrue(
                $name === 'php' || str_starts_with((string) $name, 'ext-'),
                "Production require must stay minimal (php + ext-* only); found package: {$name}"
            );
        }
    }

    public function testRequireDevContainsPhpunitAndCodingStandards(): void
    {
        $requireDev = $this->composer['require-dev'] ?? [];
        $this->assertIsArray($requireDev);
        $this->assertArrayHasKey('phpunit/phpunit', $requireDev);
        $this->assertArrayHasKey(
            'squizlabs/php_codesniffer',
            $requireDev,
            'require-dev must include PHPCS for PSR-12 adapted coding standards'
        );

        // Minimal: only tooling we actually run in CI / local scripts.
        $allowed = [
            'phpunit/phpunit',
            'squizlabs/php_codesniffer',
        ];
        foreach (array_keys($requireDev) as $name) {
            $this->assertContains(
                $name,
                $allowed,
                "Unexpected require-dev package: {$name}"
            );
        }

        $constraint = (string) $requireDev['phpunit/phpunit'];
        $this->assertMatchesRegularExpression(
            '/\^11(\.0)?/',
            $constraint,
            "phpunit constraint should target 11.x: got {$constraint}"
        );
    }

    public function testOptionalDriversAndImagingAreSuggestedNotRequired(): void
    {
        $require = $this->composer['require'] ?? [];
        $suggest = $this->composer['suggest'] ?? [];
        $this->assertIsArray($require);
        $this->assertIsArray($suggest);

        // OR alternatives from SPEC must not be hard-required (would break alternate stacks).
        $optional = [
            'ext-pdo_mysql',
            'ext-pdo_sqlite',
            'ext-pdo_pgsql',
            'ext-gd',
            'ext-imagick',
            'ext-intl',
        ];

        foreach ($optional as $ext) {
            $this->assertArrayNotHasKey(
                $ext,
                $require,
                "{$ext} must not be hard-required (optional / OR alternative)"
            );
            $this->assertArrayHasKey(
                $ext,
                $suggest,
                "{$ext} should be listed under suggest"
            );
        }
    }

    public function testAutoloadMapsCoreIncludes(): void
    {
        $autoload = $this->composer['autoload'] ?? [];
        $this->assertIsArray($autoload);
        $classmap = $autoload['classmap'] ?? [];
        $this->assertIsArray($classmap);
        $this->assertContains('ap-includes/', $classmap);
    }

    public function testAutoloadDevMapsTestsNamespace(): void
    {
        $autoloadDev = $this->composer['autoload-dev'] ?? [];
        $this->assertIsArray($autoloadDev);
        $psr4 = $autoloadDev['psr-4'] ?? [];
        $this->assertIsArray($psr4);
        $this->assertSame('tests/', $psr4['AgoraPress\\Tests\\'] ?? null);
    }

    public function testScriptsIncludeTestTargets(): void
    {
        $scripts = $this->composer['scripts'] ?? [];
        $this->assertIsArray($scripts);
        $this->assertArrayHasKey('test', $scripts);
        $this->assertArrayHasKey('test:structure', $scripts);
    }

    public function testScriptsIncludeCodingStandardsTargets(): void
    {
        $scripts = $this->composer['scripts'] ?? [];
        $this->assertIsArray($scripts);
        $this->assertArrayHasKey('cs', $scripts);
        $this->assertArrayHasKey('cs:check', $scripts);
        $this->assertArrayHasKey('cs:fix', $scripts);
    }
}
