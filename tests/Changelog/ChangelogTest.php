<?php

/**
 * Assert CHANGELOG.md follows Keep a Changelog + SemVer and stays readable.
 *
 * Historical notes (0.3.8-beta through 0.2.0-beta) live in CHANGELOG-archive.md.
 * The size cap applies only to the living CHANGELOG.md.
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

    private string $archive;

    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        $path = $this->root . '/CHANGELOG.md';
        $this->assertFileIsReadable($path, 'CHANGELOG.md must exist');
        $contents = file_get_contents($path);
        $this->assertNotFalse($contents);
        $this->changelog = $contents;

        $archivePath = $this->root . '/CHANGELOG-archive.md';
        $this->assertFileIsReadable($archivePath, 'CHANGELOG-archive.md must exist');
        $archive = file_get_contents($archivePath);
        $this->assertNotFalse($archive);
        $this->archive = $archive;
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
            'CHANGELOG must document Unreleased work'
        );
        $this->assertGreaterThanOrEqual(800, strlen($this->changelog));
        // Stay useful: reject the old multi-thousand-line implementation diary.
        // Size cap applies only to the living CHANGELOG.md, not the archive.
        $this->assertLessThan(
            14000,
            strlen($this->changelog),
            'CHANGELOG should stay concise (under ~14 KiB of prose)'
        );
    }

    public function testArchiveExistsWithoutLivingSizeCap(): void
    {
        $this->assertGreaterThan(
            800,
            strlen($this->archive),
            'CHANGELOG-archive.md should keep historical release notes'
        );
        $this->assertMatchesRegularExpression(
            '/(?im)^#\s+Changelog archive\s*$/',
            $this->archive
        );
    }

    public function testLivingChangelogPointsAtArchive(): void
    {
        $this->assertStringContainsString('CHANGELOG-archive.md', $this->changelog);
        $this->assertStringContainsString('0.3.8-beta', $this->changelog);
        $this->assertStringContainsString('0.2.0-beta', $this->changelog);
        $this->assertDoesNotMatchRegularExpression(
            '/(?im)^##\s+\[0\.3\.8-beta\]/',
            $this->changelog,
            '0.3.8-beta belongs in CHANGELOG-archive.md'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/(?im)^##\s+\[0\.2\.0-beta\]/',
            $this->changelog,
            '0.2.0-beta belongs in CHANGELOG-archive.md'
        );
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
     * High-level product surface on the living file.
     *
     * @return array<string, array{0: string}>
     */
    public static function requiredPhraseProvider(): array
    {
        return [
            'includes' => ['ap-includes'],
            'version const' => ['AP_VERSION'],
            'forums' => ['forum'],
            'no telemetry' => ['no telemetry'],
            'archive pointer' => ['CHANGELOG-archive.md'],
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

    /**
     * MVP / early-beta surface that now lives in the archive.
     *
     * @return array<string, array{0: string}>
     */
    public static function archivePhraseProvider(): array
    {
        return [
            'config sample' => ['ap-config-sample.php'],
            'docker compose' => ['docker-compose.yml'],
            'phpunit' => ['phpunit.xml.dist'],
            'license' => ['GPLv2'],
            'installer' => ['installer'],
            'admin' => ['admin'],
        ];
    }

    #[DataProvider('archivePhraseProvider')]
    public function testArchiveRequiredPhrase(string $phrase): void
    {
        $this->assertStringContainsStringIgnoringCase(
            $phrase,
            $this->archive,
            "Expected phrase in CHANGELOG-archive.md: {$phrase}"
        );
    }

    public function testNoPlaceholderReleaseDate(): void
    {
        $this->assertDoesNotMatchRegularExpression(
            '/(?im)^##\s+\[[^\]]+\]\s+-\s+YYYY-MM-DD\s*$/',
            $this->changelog,
            'Remove placeholder release dates (YYYY-MM-DD) until a real release is cut'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/(?im)^##\s+\[[^\]]+\]\s+-\s+YYYY-MM-DD\s*$/',
            $this->archive,
            'Archive must not keep placeholder release dates (YYYY-MM-DD)'
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
        $this->assertStringContainsString(
            $apVersion,
            $this->changelog,
            "CHANGELOG should mention current AP_VERSION ({$apVersion})"
        );
        if (str_contains(strtolower($apVersion), 'dev')) {
            $this->assertMatchesRegularExpression(
                '/(?i)0\\.1\\.\\d+-dev|no tagged public release|unreleased/',
                $this->changelog,
                'While AP_VERSION is a -dev build, CHANGELOG should note unreleased / pre-release status'
            );
        }
    }

    public function test020BetaDocumentsLocalAnalytics(): void
    {
        $this->assertMatchesRegularExpression(
            '/(?im)^##\s+\[0\.2\.0-beta\]/',
            $this->archive
        );
        $lower = strtolower($this->archive);
        $this->assertStringContainsString('analytics', $lower);
        $this->assertStringContainsString('analytics_enabled', $lower);
        $this->assertTrue(
            str_contains($lower, 'analytics_hits') || str_contains($lower, 'analytics_daily'),
            'CHANGELOG 0.2.0-beta should mention analytics tables'
        );
    }

    public function test021BetaDocumentsForumLikesAndThemeOptions(): void
    {
        $this->assertMatchesRegularExpression(
            '/(?im)^##\s+\[0\.2\.1-beta\]/',
            $this->archive
        );
        $lower = strtolower($this->archive);
        $this->assertStringContainsString('forum_post_likes', $lower);
        $this->assertStringContainsString('like_count', $lower);
        $this->assertTrue(
            str_contains($lower, 'theme_mod') || str_contains($lower, 'theme options'),
            'CHANGELOG 0.2.1-beta should mention Theme Options / theme_mods'
        );
    }

    public function testUnreleasedSectionRemains(): void
    {
        $matched = preg_match(
            '/(?ims)^##\s+\[Unreleased\]\s*\n(.*?)(?=^##\s+\[|\z)/',
            $this->changelog,
            $m
        );
        $this->assertSame(1, $matched, 'Missing ## [Unreleased] body');
        $body = $m[1];
        $this->assertMatchesRegularExpression('/(?im)^###\s+Added\s*$/', $body);
        $this->assertMatchesRegularExpression('/(?im)^###\s+Changed\s*$/', $body);
    }

    public function test037BetaDocumentsCharterAndDocsPass(): void
    {
        $matched = preg_match(
            '/(?ims)^##\s+\[0\.3\.7-beta\][^\n]*\n(.*?)(?=^##\s+\[|\z)/',
            $this->archive,
            $m
        );
        $this->assertSame(1, $matched, 'Missing ## [0.3.7-beta] body');
        $body = $m[1];
        $this->assertMatchesRegularExpression('/(?im)^###\s+Added\s*$/', $body);
        $this->assertMatchesRegularExpression('/(?im)^###\s+Changed\s*$/', $body);
        $lower = strtolower($body);
        $this->assertStringContainsString('documentation pass', $lower);
        $this->assertStringContainsString('0.3.7-beta', $body);
        $this->assertStringContainsString('docs/readme.md', $lower);
        $this->assertStringContainsString('audience index', $lower);
        $this->assertStringContainsString('docs/index.md', $lower);
        foreach (
            [
                'docs/install.md',
                'docs/updates.md',
                'docs/rewrites.md',
                'docs/cli.md',
                'docs/admin.md',
                'docs/forums.md',
                'docs/roles.md',
                'docs/rest.md',
                'docs/security.md',
                'docs/troubleshooting.md',
                'docs/bot_handbook.md',
                'docs/features_and_functions.md',
            ] as $path
        ) {
            $this->assertStringContainsString(
                strtolower($path),
                $lower,
                "[0.3.7-beta] should mention {$path}"
            );
        }
    }

    public function testChangelogContainsNoPrivateMarkers(): void
    {
        foreach ([$this->changelog, $this->archive] as $text) {
            foreach (
                [
                    'Roland',
                    'stallboy',
                    'mail.0shits.com',
                    'KeePass',
                    'Stalwart',
                    'Jarvis',
                    'BlindVault',
                    'MensBS',
                    'AgoraPress_Addons',
                ] as $banned
            ) {
                $this->assertStringNotContainsStringIgnoringCase(
                    $banned,
                    $text,
                    'Changelog files must not contain private marker: ' . $banned
                );
            }
        }
    }

    public function test038BetaDocumentsAbsoluteMailLinks(): void
    {
        $matched = preg_match(
            '/(?ims)^##\s+\[0\.3\.8-beta\][^\n]*\n(.*?)(?=^##\s+\[|\z)/',
            $this->archive,
            $m
        );
        $this->assertSame(1, $matched, 'Missing ## [0.3.8-beta] body');
        $body = $m[1];
        $lower = strtolower($body);
        $this->assertStringContainsString('loginactionurl', $lower);
        $this->assertStringContainsString('ap_admin::url', $lower);
        $this->assertStringContainsString('siteurl', $lower);
        $this->assertStringContainsString('ap-admin/login.php', $lower);
        $this->assertStringContainsString('verifyemail', $lower);
        $this->assertTrue(
            str_contains($lower, 'verification') && str_contains($lower, 'reset'),
            '[0.3.8-beta] should mention verification and reset emails'
        );
    }

    public function testCurrentApVersionIs0310Beta(): void
    {
        $versionPath = $this->root . '/ap-includes/version.php';
        $this->assertFileIsReadable($versionPath);
        $versionPhp = file_get_contents($versionPath);
        $this->assertNotFalse($versionPhp);
        $matched = preg_match(
            "/define\\s*\\(\\s*['\"]AP_VERSION['\"]\\s*,\\s*['\"]([^'\"]+)['\"]\\s*\\)/",
            $versionPhp,
            $m
        );
        $this->assertSame(1, $matched, 'ap-includes/version.php should define AP_VERSION');
        $this->assertSame(
            '0.3.10-beta',
            $m[1],
            'AP_VERSION must be 0.3.10-beta for this release'
        );
    }
}
