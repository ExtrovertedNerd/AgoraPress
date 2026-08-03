<?php

/**
 * Assert CHANGELOG.md follows Keep a Changelog + SemVer (Phase 0).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Changelog;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class ChangelogTest extends TestCase
{
    private string $changelog;

    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        $path = $this->root . '/CHANGELOG.md';
        $this->assertFileIsReadable($path, 'CHANGELOG.md must exist');
        $contents = file_get_contents($path);
        $this->assertNotFalse($contents);
        $this->changelog = $contents;
    }

    public function testChangelogIsNotAStub(): void
    {
        $nonEmpty = array_filter(
            explode("\n", $this->changelog),
            static fn(string $line): bool => trim($line) !== ''
        );
        $this->assertGreaterThanOrEqual(
            15,
            count($nonEmpty),
            'CHANGELOG must document Unreleased scaffold work'
        );
        $this->assertGreaterThanOrEqual(800, strlen($this->changelog));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function requiredPatternProvider(): array
    {
        return [
            'title' => ['/(?im)^#\s+Changelog\s*$/'],
            'unreleased' => ['/(?im)^##\s+\[Unreleased\]\s*$/'],
            'added' => ['/(?im)^###\s+Added\s*$/'],
            'keep a changelog' => ['/keepachangelog\.com/i'],
            'semver' => ['/semver\.org/i'],
            'semantic versioning' => ['/Semantic Versioning/i'],
        ];
    }

    #[DataProvider('requiredPatternProvider')]
    public function testRequiredPattern(string $pattern): void
    {
        $this->assertMatchesRegularExpression(
            $pattern,
            $this->changelog,
            "Expected pattern: {$pattern}"
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function requiredPhraseProvider(): array
    {
        return [
            'config sample' => ['ap-config-sample.php'],
            'docker compose' => ['docker-compose.yml'],
            'phpunit' => ['phpunit.xml.dist'],
            'includes' => ['ap-includes'],
            'license' => ['GPLv2'],
            'version const' => ['AP_VERSION'],
            // Final Review completeness: major Phase 4–6 systems must stay documented.
            'i18n class' => ['AP_L10n'],
            'gettext' => ['gettext'],
            'object cache' => ['AP_Object_Cache'],
            'object-cache drop-in' => ['object-cache.php'],
            'forum moderation' => ['AP_Forum_Moderation'],
            'shortcode API' => ['AP_Shortcode'],
            'cron API' => ['AP_Cron'],
            'transients API' => ['AP_Transient'],
            'must-use plugins' => ['mu-plugins'],
            'content query' => ['AP_Query'],
            'options API' => ['AP_Options'],
            'admin color modes' => ['prefers-color-scheme'],
        ];
    }

    #[DataProvider('requiredPhraseProvider')]
    public function testRequiredPhrase(string $phrase): void
    {
        $this->assertStringContainsStringIgnoringCase(
            $phrase,
            $this->changelog,
            "Expected phrase in CHANGELOG: {$phrase}"
        );
    }

    public function testNoPlaceholderReleaseDate(): void
    {
        $this->assertDoesNotMatchRegularExpression(
            '/(?im)^##\s+\[[^\]]+\]\s+-\s+YYYY-MM-DD\s*$/',
            $this->changelog,
            'Remove placeholder release dates (YYYY-MM-DD) until a real release is cut'
        );
    }

    public function testUnreleasedComesBeforeAnyVersionedSection(): void
    {
        $this->assertMatchesRegularExpression(
            '/(?im)^##\s+\[Unreleased\]\s*$/',
            $this->changelog
        );

        $hasVersion = preg_match(
            '/(?im)^##\s+\[\d+\.\d+\.\d+[^\]]*\]\s+-\s+\d{4}-\d{2}-\d{2}\s*$/',
            $this->changelog,
            $versionMatch,
            PREG_OFFSET_CAPTURE
        );
        if ($hasVersion === 1) {
            preg_match(
                '/(?im)^##\s+\[Unreleased\]\s*$/',
                $this->changelog,
                $unreleasedMatch,
                PREG_OFFSET_CAPTURE
            );
            $this->assertLessThan(
                $versionMatch[0][1],
                $unreleasedMatch[0][1],
                '[Unreleased] must appear before the first dated version section'
            );
        }
    }

    public function testMentionsCoreVersionConstantAndDevStatus(): void
    {
        $this->assertStringContainsString('AP_VERSION', $this->changelog);

        $versionPath = $this->root . '/ap-includes/version.php';
        if (!is_readable($versionPath)) {
            return;
        }

        $versionPhp = file_get_contents($versionPath);
        $this->assertNotFalse($versionPhp);
        $this->assertMatchesRegularExpression(
            "/define\\s*\\(\\s*['\"]AP_VERSION['\"]\\s*,\\s*['\"]([^'\"]+)['\"]\\s*\\)/",
            $versionPhp,
            'ap-includes/version.php should define AP_VERSION'
        );

        $matched = preg_match(
            "/define\\s*\\(\\s*['\"]AP_VERSION['\"]\\s*,\\s*['\"]([^'\"]+)['\"]\\s*\\)/",
            $versionPhp,
            $m
        );
        if ($matched !== 1) {
            return;
        }

        $apVersion = $m[1];
        if (str_contains(strtolower($apVersion), 'dev')) {
            $this->assertMatchesRegularExpression(
                '/(?i)0\\.1\\.0-dev|no tagged public release|unreleased/',
                $this->changelog,
                'While AP_VERSION is a -dev build, CHANGELOG should note unreleased / pre-release status'
            );
        }
    }
}
