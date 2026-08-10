<?php

/**
 * Assert README.md contains vision summary and quick-start (Phase 0).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Readme;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class ReadmeTest extends TestCase
{
    private string $readme;

    protected function setUp(): void
    {
        $path = dirname(__DIR__, 2) . '/README.md';
        $this->assertFileIsReadable($path, 'README.md must exist');
        $contents = file_get_contents($path);
        $this->assertNotFalse($contents);
        $this->readme = $contents;
    }

    public function testReadmeIsNotAStub(): void
    {
        $nonEmpty = array_filter(
            explode("\n", $this->readme),
            static fn(string $line): bool => trim($line) !== ''
        );
        $this->assertGreaterThanOrEqual(
            40,
            count($nonEmpty),
            'README must be a full vision + quick-start document'
        );
        $this->assertGreaterThanOrEqual(2000, strlen($this->readme));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function requiredHeadingProvider(): array
    {
        return [
            'title' => ['/(?im)^#\s+AgoraPress\s*$/'],
            'vision' => ['/(?im)^##\s+Vision summary\s*$/'],
            'requirements' => ['/(?im)^##\s+Requirements\s*$/'],
            'quick start' => ['/(?im)^##\s+Quick start\s*$/'],
            'layout' => ['/(?im)^##\s+Project layout\s*$/'],
            'development' => ['/(?im)^##\s+Development\s*$/'],
            'license' => ['/(?im)^##\s+License\s*$/'],
        ];
    }

    #[DataProvider('requiredHeadingProvider')]
    public function testRequiredHeading(string $pattern): void
    {
        $this->assertMatchesRegularExpression(
            $pattern,
            $this->readme,
            "Expected heading matching: {$pattern}"
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function requiredPhraseProvider(): array
    {
        return [
            'free forever' => ['free forever'],
            'no telemetry' => ['no telemetry'],
            'WP theme compatibility' => ['Classic WordPress Theme Compatibility'],
            'static pages' => ['Static Pages'],
            'blog' => ['Blog'],
            'forum' => ['Forum'],
            'docker compose' => ['docker compose'],
            'config sample' => ['ap-config-sample.php'],
            'table prefix' => ['ap_'],
            'php version' => ['PHP 8.2'],
            'license short' => ['GPLv2'],
            'beta version' => ['0.3.3-beta'],
            'analytics screen' => ['Tools → Analytics'],
            'analytics option' => ['analytics_enabled'],
        ];
    }

    #[DataProvider('requiredPhraseProvider')]
    public function testRequiredPhrase(string $phrase): void
    {
        $this->assertStringContainsStringIgnoringCase(
            $phrase,
            $this->readme,
            "Expected phrase in README: {$phrase}"
        );
    }

    public function testQuickStartMentionsLocalhostPort(): void
    {
        $this->assertTrue(
            str_contains($this->readme, 'localhost:8080')
            || str_contains($this->readme, 'http://localhost:8080'),
            'Quick start should mention localhost:8080'
        );
    }

    public function testQuickStartMentionsDockerComposeUp(): void
    {
        $this->assertMatchesRegularExpression(
            '/docker\s+compose\s+up/i',
            $this->readme,
            'Quick start should show docker compose up'
        );
    }

    public function testMentionsThreeModules(): void
    {
        $lower = strtolower($this->readme);
        foreach (['static pages', 'blog', 'forum'] as $module) {
            $this->assertStringContainsString(
                $module,
                $lower,
                "Module toggle mentioned: {$module}"
            );
        }
    }

    public function testMentionsLocalAnalyticsAndSchemaVersion(): void
    {
        $lower = strtolower($this->readme);
        $this->assertStringContainsString('0.3.3-beta', $this->readme);
        $this->assertMatchesRegularExpression('/AP_DB_VERSION/i', $this->readme);
        // Schema target tracks AP_DB_VERSION (12: topic type enum + backfill).
        $this->assertMatchesRegularExpression('/\b12\b/', $this->readme);
        $this->assertTrue(
            str_contains($lower, 'local analytics') || str_contains($lower, 'tools → analytics'),
            'README should document Tools → Analytics / local analytics'
        );
        $this->assertTrue(
            str_contains($lower, 'off by default')
            || str_contains($lower, 'default off')
            || str_contains($lower, 'analytics_enabled'),
            'README should note analytics is opt-in / off by default'
        );
    }
}
