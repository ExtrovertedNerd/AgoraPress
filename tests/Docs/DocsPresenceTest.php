<?php

/**
 * Charter presence tests for public docs (humans + Grok Bot).
 *
 * Small guards so a required guide cannot disappear silently. Topic depth
 * lives in DeveloperDocsTest; this class maps 1:1 to SPEC / Phase 5 TODO
 * plus SPEC success-criteria locks (Phase 6).
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

    public function testRootReadmeDocumentationTableListsEveryGuide(): void
    {
        $section = $this->rootReadmeDocumentationSection();
        $lower = strtolower($section);
        $this->assertStringContainsString(
            'human landing page',
            $lower,
            'Root README Documentation section should call this file the human landing page'
        );
        $this->assertStringContainsString(
            'second handbook',
            $lower,
            'Root README Documentation section should say it is not a second handbook'
        );
        $this->assertStringContainsString('docs/index.md', $lower);
        $this->assertStringContainsString('docs/bot_handbook.md', $lower);

        $table = $this->markdownTableText($section);
        $this->assertMatchesRegularExpression(
            '/(?i)\|\s*guide\s*\|\s*topic\s*\|/',
            $table,
            'README Documentation table should have Guide / Topic columns'
        );

        $files = glob($this->docsRoot . '/*.md') ?: [];
        $this->assertNotSame([], $files, 'docs/ should contain markdown guides');
        sort($files, SORT_STRING);
        foreach ($files as $path) {
            $target = 'docs/' . basename($path);
            $this->assertStringContainsString(
                '](' . $target . ')',
                $table,
                "README Documentation table should link to {$target}"
            );
        }

        foreach (self::REQUIRED_NEW_PATHS as $name) {
            $target = 'docs/' . $name;
            $this->assertStringContainsString(
                '](' . $target . ')',
                $table,
                "README Documentation table should list required new guide {$target}"
            );
        }
    }

    public function testRootReadmeIsNotASecondHandbook(): void
    {
        $readmePath = $this->root . '/README.md';
        $this->assertFileIsReadable($readmePath);
        $readme = (string) file_get_contents($readmePath);
        $this->assertDoesNotMatchRegularExpression(
            '/(?im)^##\s+By audience\s*$/',
            $readme,
            'Audience index belongs in docs/README.md, not the root landing page'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/(?im)^###\s+New operators\s*$/',
            $readme
        );
        $this->assertDoesNotMatchRegularExpression(
            '/(?im)^###\s+Trusted agent/',
            $readme
        );

        $this->assertFileDoesNotExist($this->docsRoot . '/index.md');
        $handbooks = glob($this->docsRoot . '/*handbook*') ?: [];
        $names = array_map('basename', $handbooks);
        sort($names, SORT_STRING);
        $this->assertSame(
            ['bot_handbook.md'],
            $names,
            'One trusted-agent handbook only: docs/bot_handbook.md'
        );
    }

    /**
     * SPEC success: a stranger can install via Docker or /install/ from
     * README + docs/install.md.
     */
    public function testSpecSuccessInstallFromReadmeAndInstallGuide(): void
    {
        $readmePath = $this->root . '/README.md';
        $this->assertFileIsReadable($readmePath);
        $readme = (string) file_get_contents($readmePath);
        $install = $this->readDoc('install.md');

        foreach ([$readme, $install] as $text) {
            $this->assertStringContainsString('docker compose', $text);
            $this->assertStringContainsString('/install/', $text);
            $this->assertStringContainsString('docker-compose.yml', $text);
        }
        $this->assertStringContainsString('php install/cli.php', $install);
        $this->assertStringContainsString('ap-config-sample.php', $install);
        $this->assertStringContainsString('http://localhost:8080/install/', $readme);
    }

    /**
     * SPEC success: an nginx 404 on /slug/ is diagnosable from rewrites.md
     * + troubleshooting.md.
     */
    public function testSpecSuccessPrettyPermalink404Diagnosable(): void
    {
        $rewrites = $this->readDoc('rewrites.md');
        $trouble = $this->readDoc('troubleshooting.md');
        foreach ([$rewrites, $trouble] as $text) {
            $this->assertStringContainsString('try_files $uri $uri/ /index.php?$args', $text);
            $this->assertStringContainsString('/slug/', $text);
            $this->assertStringContainsString('?p=', $text);
        }
        $this->assertStringContainsStringIgnoringCase('404', $trouble);
        $this->assertStringContainsString('mod_rewrite', $trouble);
        $this->assertStringContainsString('nginx', strtolower($trouble));
    }

    /**
     * SPEC success: php ap-cli --help and docs/cli.md agree on built-ins
     * and global flags (usage() is what --help prints).
     */
    public function testSpecSuccessCliHelpAgreesWithCliDoc(): void
    {
        AP_Cli::ensureBuiltins();
        $help = AP_Cli::usage('ap-cli');
        $cli = $this->readDoc('cli.md');
        $groups = AP_Cli::listCommands();
        $this->assertNotSame([], $groups, 'AP_Cli must register built-in command groups');

        foreach ($groups as $group) {
            $this->assertMatchesRegularExpression(
                '/^\s+' . preg_quote($group, '/') . '\s+/m',
                $help,
                "php ap-cli --help should list {$group}"
            );
            $this->assertMatchesRegularExpression(
                '/^\|\s+`' . preg_quote($group, '/') . '`\s+\|/m',
                $cli,
                "docs/cli.md must name ap-cli group `{$group}` in the built-in table"
            );
        }
        foreach (['--path', '--url', '--skip-plugins', '--skip-themes'] as $flag) {
            $this->assertStringContainsString($flag, $help);
            $this->assertStringContainsString($flag, $cli);
        }
        $this->assertStringContainsString('php install/cli.php --help', $help);
        $this->assertStringContainsString('Exit codes:', $help);
        $this->assertStringContainsString('Exit codes', $cli);
    }

    /**
     * SPEC success: a Bot can answer the four charter questions from docs/
     * alone.
     */
    public function testSpecSuccessBotCanAnswerCharterQuestions(): void
    {
        $handbook = $this->readDoc('bot_handbook.md');
        $this->assertStringContainsStringIgnoringCase('how do I turn forums on', $handbook);
        $this->assertStringContainsString('/2026/09/03/hello-world/', $handbook);
        $this->assertStringContainsStringIgnoringCase('does core send telemetry', $handbook);
        $this->assertStringContainsStringIgnoringCase('is Gutenberg coming', $handbook);

        $forums = $this->readDoc('forums.md');
        $this->assertStringContainsString('ap_module_forum', $forums);
        $this->assertStringContainsString('Settings → Modules', $forums);
        $this->assertStringContainsString('php ap-cli option', $forums);

        $trouble = $this->readDoc('troubleshooting.md');
        $this->assertStringContainsString('try_files $uri $uri/ /index.php?$args', $trouble);
        $this->assertStringContainsString('?p=', $trouble);

        $security = $this->readDoc('security.md');
        $this->assertStringContainsString('AP_TELEMETRY', $security);
        $this->assertStringContainsStringIgnoringCase('no telemetry', $security);
        $this->assertStringContainsString('no-site-id', $security);

        $editor = $this->readDoc('editor.md');
        $this->assertStringContainsStringIgnoringCase('Gutenberg', $editor);
        $this->assertStringContainsStringIgnoringCase('not in core', $editor);
        $this->assertStringContainsStringIgnoringCase('non-goal', $editor);
    }

    /**
     * SPEC success: no public landing doc names a private host, mailbox, or
     * Addons skin. Charter new guides are scanned in
     * testDocsFileContainsNoPrivateMarkers. Historical CHANGELOG [0.3.6-beta]
     * fixture wording is deferred (SPEC: do not rewrite changelog history).
     */
    public function testSpecSuccessRootReadmeHasNoPrivateMarkers(): void
    {
        $readmePath = $this->root . '/README.md';
        $this->assertFileIsReadable($readmePath);
        $readme = (string) file_get_contents($readmePath);
        foreach (self::PRIVATE_MARKERS as $banned) {
            $this->assertStringNotContainsStringIgnoringCase(
                $banned,
                $readme,
                "README.md must not contain private marker: {$banned}"
            );
        }
    }

    private function rootReadmeDocumentationSection(): string
    {
        $path = $this->root . '/README.md';
        $this->assertFileIsReadable($path, 'Missing root README.md');
        $text = file_get_contents($path);
        $this->assertNotFalse($text);
        $matched = preg_match(
            '/(?im)^##\s+Documentation\s*$/m',
            $text,
            $heading,
            PREG_OFFSET_CAPTURE
        );
        $this->assertSame(1, $matched, 'Root README.md must have a ## Documentation heading');
        $start = $heading[0][1] + strlen($heading[0][0]);
        $rest = substr($text, $start);
        if (preg_match('/(?im)^##\s+/m', $rest, $next, PREG_OFFSET_CAPTURE) === 1) {
            return substr($rest, 0, $next[0][1]);
        }

        return $rest;
    }

    private function markdownTableText(string $section): string
    {
        $lines = [];
        foreach (preg_split('/\R/', $section) as $line) {
            if (str_starts_with($line, '|')) {
                $lines[] = $line;
            }
        }
        $this->assertNotSame(
            [],
            $lines,
            'README Documentation section must contain a markdown table'
        );

        return implode("\n", $lines);
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
