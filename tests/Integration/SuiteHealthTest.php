<?php

/**
 * Meta tests: critical PHPUnit suites exist and the runner discovers them.
 *
 * Keeps Phase 7 “unit / integration tests” honest — a missing suite file or
 * a broken phpunit.xml.dist fails CI before product regressions hide.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class SuiteHealthTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    /**
     * Critical product areas that must keep dedicated PHPUnit coverage.
     *
     * @return list<array{0: string}>
     */
    public static function criticalSuiteProvider(): array
    {
        return [
            ['tests/Database/DatabaseTest.php'],
            ['tests/Database/MigratorTest.php'],
            ['tests/Hooks/HooksTest.php'],
            ['tests/Security/FormattingTest.php'],
            ['tests/Security/NonceTest.php'],
            ['tests/Security/RateLimitTest.php'],
            ['tests/User/UserAuthTest.php'],
            ['tests/User/RolesTest.php'],
            ['tests/Post/PostModelTest.php'],
            ['tests/Query/QueryTest.php'],
            ['tests/Forum/ForumModelTest.php'],
            ['tests/Forum/ForumModerationTest.php'],
            ['tests/Theme/ThemeCompatTest.php'],
            ['tests/Vision/VisionComplianceTest.php'],
            ['tests/Plugin/PluginApiTest.php'],
            ['tests/Rest/RestApiTest.php'],
            ['tests/Cli/CliToolTest.php'],
            ['tests/Import/WxrImporterTest.php'],
            ['tests/Import/PhpbbImporterTest.php'],
            ['tests/Options/PageCacheTest.php'],
            ['tests/Assets/AssetsTest.php'],
            ['tests/Security/MailTest.php'],
            ['tests/Install/SampleContentTest.php'],
            ['tests/Release/PackageReleaseTest.php'],
            ['tests/Changelog/ChangelogTest.php'],
            ['tests/Integration/ContentCoexistenceTest.php'],
            ['tests/Integration/RolesCapsContentTest.php'],
        ];
    }

    #[DataProvider('criticalSuiteProvider')]
    public function testCriticalSuiteFileExists(string $relative): void
    {
        $path = $this->root . '/' . $relative;
        $this->assertFileIsReadable($path, "Missing critical suite: {$relative}");
    }

    public function testPhpunitDiscoversIntegrationAndAssetsSuites(): void
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
        $this->assertStringContainsString('ContentCoexistenceTest', $body);
        $this->assertStringContainsString('RolesCapsContentTest', $body);
        $this->assertStringContainsString('AssetsTest', $body);
        $this->assertStringContainsString('SuiteHealthTest', $body);
        $this->assertStringContainsString('PageCacheTest', $body);
        $this->assertStringContainsString('MailTest', $body);
    }

    public function testComposerTestScriptPointsAtPhpunit(): void
    {
        $raw = file_get_contents($this->root . '/composer.json');
        $this->assertNotFalse($raw);
        $composer = json_decode($raw, true);
        $this->assertIsArray($composer);
        $this->assertArrayHasKey('test', $composer['scripts'] ?? []);
        $this->assertStringContainsString('phpunit', (string) $composer['scripts']['test']);
    }
}
