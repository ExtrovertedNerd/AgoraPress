<?php

/**
 * Charter presence tests for public docs (humans + Grok Bot).
 *
 * Small guards so a required guide cannot disappear silently. Topic depth
 * lives in DeveloperDocsTest; this class maps 1:1 to SPEC / Phase 5 TODO.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Docs;

use AP_Cli;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class DocsPresenceTest extends TestCase
{
    private string $root;

    private string $docsRoot;

    /**
     * New operator/agent guides this charter added (SPEC “required new guides”).
     *
     * @var list<string>
     */
    private const REQUIRED_NEW_PATHS = [
        'install.md',
        'updates.md',
        'rewrites.md',
        'cli.md',
        'admin.md',
        'forums.md',
        'rest.md',
        'roles.md',
        'security.md',
        'troubleshooting.md',
        'bot_handbook.md',
        'features_and_functions.md',
    ];

    /**
     * Private markers that must not appear in any public docs/*.md file.
     *
     * @var list<string>
     */
    private const PRIVATE_MARKERS = [
        'Roland',
        'stallboy@',
        'mail.0shits.com',
        'KeePass',
        'Stalwart',
    ];

    /**
     * Catalog-or-linked-guide tokens from Phase 0 inventory.
     *
     * @var list<string>
     */
    private const CATALOG_TOKENS = [
        'AP_DB_VERSION',
        '/ap-admin/',
        '/ap-json/',
        'rest_api_enabled',
        'analytics_enabled',
        'ap_register_admin_page',
    ];

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        $this->docsRoot = $this->root . '/docs';
        $this->assertDirectoryExists($this->docsRoot, 'docs/ directory must exist');
        require_once $this->root . '/ap-includes/class-ap-cli.php';
        AP_Cli::reset();
    }

    protected function tearDown(): void
    {
        AP_Cli::reset();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function requiredNewPathProvider(): array
    {
        $out = [];
        foreach (self::REQUIRED_NEW_PATHS as $name) {
            $out[$name] = [$name];
        }

        return $out;
    }

    #[DataProvider('requiredNewPathProvider')]
    public function testRequiredNewPathExistsAndIsNonEmpty(string $relative): void
    {
        $path = $this->docsRoot . '/' . $relative;
        $this->assertFileIsReadable($path, "Missing required guide: docs/{$relative}");
        $contents = file_get_contents($path);
        $this->assertNotFalse($contents);
        $this->assertNotSame(
            '',
            trim($contents),
            "docs/{$relative} must be non-empty"
        );
    }

    public function testDocsIndexLinksEveryNewGuide(): void
    {
        $index = $this->readDoc('README.md');
        foreach (self::REQUIRED_NEW_PATHS as $name) {
            $this->assertMatchesRegularExpression(
                '/\]\(' . preg_quote($name, '/') . '(?:#[^)]*)?\)/',
                $index,
                "docs/README.md should markdown-link {$name}"
            );
        }
    }

    public function testBotHandbookStatesPublicSafeRuleAndDoNotInventSurfaces(): void
    {
        $text = $this->readDoc('bot_handbook.md');
        $lower = strtolower($text);
        $this->assertTrue(
            str_contains($lower, 'public-safe')
            || str_contains($lower, 'this repository is **public**')
            || str_contains($lower, 'this repository is public'),
            'docs/bot_handbook.md should state the public-safe / public-repository rule'
        );
        $this->assertTrue(
            str_contains($lower, 'do not invent surfaces')
            || str_contains($lower, 'do **not invent** surfaces'),
            'docs/bot_handbook.md should say do not invent surfaces'
        );
        $this->assertStringContainsStringIgnoringCase('never write', $text);
        $this->assertStringContainsStringIgnoringCase('not in core', $text);
    }

    public function testRewritesDocContainsShippedTryFilesPattern(): void
    {
        $text = $this->readDoc('rewrites.md');
        $this->assertStringContainsString(
            'try_files $uri $uri/ /index.php',
            $text,
            'docs/rewrites.md must contain the shipped nginx try_files pattern'
        );
        $this->assertStringContainsString(
            'try_files $uri $uri/ /index.php?$args',
            $text,
            'docs/rewrites.md should quote docker/nginx.conf.example including $args'
        );
    }

    public function testCliDocNamesEveryBuiltinCommandGroup(): void
    {
        AP_Cli::ensureBuiltins();
        $groups = AP_Cli::listCommands();
        $this->assertNotSame([], $groups, 'AP_Cli must register built-in command groups');

        $cli = $this->readDoc('cli.md');
        foreach ($groups as $group) {
            $this->assertMatchesRegularExpression(
                '/^\|\s+`' . preg_quote($group, '/') . '`\s+\|/m',
                $cli,
                "docs/cli.md must name ap-cli group `{$group}` in the built-in table"
            );
        }
    }

    public function testCatalogOrLinkedGuidesMentionRequiredTokens(): void
    {
        $catalog = $this->readDoc('features_and_functions.md');
        foreach (self::CATALOG_TOKENS as $token) {
            $this->assertStringContainsString(
                $token,
                $catalog,
                "Catalog (features_and_functions.md) should mention: {$token}"
            );
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function docsMarkdownFileProvider(): array
    {
        $dir = dirname(__DIR__, 2) . '/docs';
        $out = [];
        $files = glob($dir . '/*.md') ?: [];
        sort($files, SORT_STRING);
        foreach ($files as $path) {
            $name = basename($path);
            $out[$name] = [$name];
        }

        return $out;
    }

    #[DataProvider('docsMarkdownFileProvider')]
    public function testDocsFileContainsNoPrivateMarkers(string $relative): void
    {
        $text = $this->readDoc($relative);
        foreach (self::PRIVATE_MARKERS as $banned) {
            $this->assertStringNotContainsStringIgnoringCase(
                $banned,
                $text,
                "docs/{$relative} must not contain private marker: {$banned}"
            );
        }
    }

    public function testNoParallelDocsIndex(): void
    {
        $this->assertFileDoesNotExist(
            $this->docsRoot . '/index.md',
            'One public index only: docs/README.md (do not also create docs/index.md)'
        );
    }

    private function readDoc(string $relative): string
    {
        $path = $this->docsRoot . '/' . $relative;
        $this->assertFileIsReadable($path, "Missing docs/{$relative}");
        $contents = file_get_contents($path);
        $this->assertNotFalse($contents);

        return $contents;
    }
}
