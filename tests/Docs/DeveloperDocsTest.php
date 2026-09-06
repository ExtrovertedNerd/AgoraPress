<?php

/**
 * Assert developer documentation suite exists and covers required topics.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Docs;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class DeveloperDocsTest extends TestCase
{
    private string $docsRoot;

    protected function setUp(): void
    {
        $this->docsRoot = dirname(__DIR__, 2) . '/docs';
        $this->assertDirectoryExists($this->docsRoot, 'docs/ directory must exist');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function requiredDocFileProvider(): array
    {
        return [
            'index' => ['README.md'],
            'hooks' => ['hooks.md'],
            'themes' => ['themes.md'],
            'plugins' => ['plugins.md'],
            'editor' => ['editor.md'],
            'site icon' => ['site-icon.md'],
            'compatibility' => ['compatibility.md'],
            'schema' => ['schema.md'],
            'vision compliance' => ['vision-compliance.md'],
        ];
    }

    #[DataProvider('requiredDocFileProvider')]
    public function testRequiredDocFileExists(string $relative): void
    {
        $path = $this->docsRoot . '/' . $relative;
        $this->assertFileIsReadable($path, "Missing developer doc: docs/{$relative}");
        $contents = file_get_contents($path);
        $this->assertNotFalse($contents);
        $this->assertGreaterThan(
            800,
            strlen($contents),
            "docs/{$relative} should be a substantial guide, not a stub"
        );
    }

    public function testIndexLinksAllGuides(): void
    {
        $index = $this->readDoc('README.md');
        foreach (
            [
                'hooks.md',
                'themes.md',
                'plugins.md',
                'editor.md',
                'site-icon.md',
                'compatibility.md',
                'schema.md',
                'vision-compliance.md',
                'install.md',
                'updates.md',
                'rewrites.md',
                'cli.md',
                'admin.md',
                'forums.md',
                'roles.md',
                'rest.md',
                'security.md',
                'troubleshooting.md',
                'bot_handbook.md',
                'features_and_functions.md',
            ] as $link
        ) {
            $this->assertStringContainsString(
                $link,
                $index,
                "docs/README.md should link to {$link}"
            );
        }
    }

    public function testIndexIsAudienceIndex(): void
    {
        $index = $this->readDoc('README.md');
        $this->assertMatchesRegularExpression(
            '/(?im)^#\s+AgoraPress documentation index\s*$/',
            $index
        );
        $this->assertMatchesRegularExpression('/(?im)^##\s+By audience\s*$/', $index);
        $this->assertMatchesRegularExpression('/(?im)^##\s+Quick mental model\s*$/', $index);
        $this->assertMatchesRegularExpression('/(?im)^##\s+Feature map/', $index);
        $this->assertMatchesRegularExpression('/(?im)^##\s+Source map\s*$/', $index);
        foreach (
            [
                '/(?im)^###\s+New operators\s*$/',
                '/(?im)^###\s+Day-to-day operators\s*$/',
                '/(?im)^###\s+Theme & plugin authors\s*$/',
                '/(?im)^###\s+Trusted agent/',
                '/(?im)^###\s+Developers\s*$/',
            ] as $pattern
        ) {
            $this->assertMatchesRegularExpression(
                $pattern,
                $index,
                "Expected audience heading matching: {$pattern}"
            );
        }
    }

    public function testIndexStatesPublicSafeRule(): void
    {
        $index = $this->readDoc('README.md');
        $lower = strtolower($index);
        $this->assertTrue(
            str_contains($lower, 'public-safe')
            || str_contains($lower, 'this repository is **public**')
            || str_contains($lower, 'this repository is public'),
            'docs/README.md should state the public-safe / public-repository rule'
        );
        $this->assertStringContainsStringIgnoringCase('never write', $index);
        $this->assertStringContainsStringIgnoringCase('do not invent', $index);
        $this->assertStringContainsStringIgnoringCase('not in core', $index);
        $this->assertStringContainsString('bot_handbook.md', $index);
        foreach (['Roland', 'stallboy@', 'mail.0shits.com', 'KeePass', 'Stalwart'] as $banned) {
            $this->assertStringNotContainsStringIgnoringCase(
                $banned,
                $index,
                "docs/README.md must not contain private marker: {$banned}"
            );
        }
    }

    public function testBotHandbookStatesPublicSafeRule(): void
    {
        $text = $this->readDoc('bot_handbook.md');
        $this->assertGreaterThan(
            800,
            strlen($text),
            'docs/bot_handbook.md should state the public-safe rule, not be a stub'
        );
        $lower = strtolower($text);
        $this->assertTrue(
            str_contains($lower, 'public-safe')
            || str_contains($lower, 'this repository is **public**')
            || str_contains($lower, 'this repository is public'),
            'docs/bot_handbook.md should state the public-safe / public-repository rule'
        );
        $this->assertStringContainsStringIgnoringCase('never write', $text);
        $this->assertStringContainsStringIgnoringCase('do not invent', $text);
        $this->assertStringContainsStringIgnoringCase('not in core', $text);
        $this->assertTrue(
            str_contains($lower, 'do not invent surfaces')
            || str_contains($lower, 'do **not invent**'),
            'docs/bot_handbook.md should say do not invent surfaces'
        );
        foreach (['Roland', 'stallboy@', 'mail.0shits.com', 'KeePass', 'Stalwart'] as $banned) {
            $this->assertStringNotContainsStringIgnoringCase(
                $banned,
                $text,
                "docs/bot_handbook.md must not contain private marker: {$banned}"
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

    public function testSiteIconDocCoversFaviconPack(): void
    {
        $text = $this->readDoc('site-icon.md');
        foreach (
            [
                'site_icon',
                'Settings → General',
                'AP_Media',
                'generateSiteIconSizes',
                'SITE_ICON_SIZES',
                '32',
                '180',
                '192',
                '512',
                'ico',
                'ap_head',
                'apple-touch-icon',
                'ap_site_icon_meta_tags',
                'favicon.ico',
                'manage_options',
                'GD',
                'Imagick',
            ] as $needle
        ) {
            $this->assertStringContainsStringIgnoringCase(
                $needle,
                $text,
                "site-icon.md should mention: {$needle}"
            );
        }
    }

    public function testEditorDocCoversClassicLightweightContract(): void
    {
        $text = $this->readDoc('editor.md');
        foreach (
            [
                'AP_Editor',
                'classic',
                'visual',
                'textarea',
                'contenteditable',
                'non-goal',
                'block',
                'ap_editor',
                'no jQuery',
                'AP_Content_Format',
            ] as $needle
        ) {
            $this->assertStringContainsStringIgnoringCase(
                $needle,
                $text,
                "editor.md should mention: {$needle}"
            );
        }
    }

    public function testVisionComplianceDocCoversPrinciplesAndDeviations(): void
    {
        $text = $this->readDoc('vision-compliance.md');
        foreach (
            [
                'Free forever',
                'No telemetry',
                'Classic WordPress Theme Compatibility',
                'Intentional deviations',
                'Three independent modules',
                '0.2.1-beta',
                'Local analytics',
                'analytics_enabled',
            ] as $needle
        ) {
            $this->assertStringContainsStringIgnoringCase(
                $needle,
                $text,
                "vision-compliance.md should mention: {$needle}"
            );
        }
    }

    public function testHooksDocCoversApiAndLifecycle(): void
    {
        $text = $this->readDoc('hooks.md');
        foreach (
            [
                'ap_add_action',
                'ap_do_action',
                'ap_add_filter',
                'ap_apply_filters',
                'ap_plugins_loaded',
                'ap_loaded',
                'ap_after_setup_theme',
                'ap_enqueue_scripts',
                'priority',
                'ap_analytics_should_record',
                'ap_analytics_prune',
            ] as $needle
        ) {
            $this->assertStringContainsStringIgnoringCase(
                $needle,
                $text,
                "hooks.md should mention: {$needle}"
            );
        }
    }

    public function testDocsIndexReflects031BetaAndAnalytics(): void
    {
        $index = $this->readDoc('README.md');
        $this->assertStringContainsString('0.3.6-beta', $index);
        $this->assertStringContainsString('AP_Analytics', $index);
        $this->assertStringContainsString('class-ap-analytics.php', $index);
        $this->assertStringContainsString('AP_Forum_Like', $index);
    }

    public function testThemesDocCoversHierarchy(): void
    {
        $text = $this->readDoc('themes.md');
        foreach (
            [
                'style.css',
                'index.php',
                'Template',
                'child',
                'ap_template_hierarchy',
                'front-page.php',
                'single.php',
                'ap_enqueue_scripts',
                'agora',
            ] as $needle
        ) {
            $this->assertStringContainsStringIgnoringCase(
                $needle,
                $text,
                "themes.md should mention: {$needle}"
            );
        }
    }

    public function testPluginsDocCoversApi(): void
    {
        $text = $this->readDoc('plugins.md');
        foreach (
            [
                'Plugin Name',
                'active_plugins',
                'ap_activate_plugin',
                'ap_register_activation_hook',
                'mu-plugins',
                'ap_add_shortcode',
                'ap_register_setting',
                'ap_rest_api_init',
                // ACP admin page registration (settings screens in the Control Panel)
                'ap_register_admin_page',
                'admin.php?page=',
                'ap_admin_menu',
                'add_options_page',
                'manage_options',
                'AP_Admin_Menu',
                'AP_Admin::pageUrl',
            ] as $needle
        ) {
            $this->assertStringContainsStringIgnoringCase(
                $needle,
                $text,
                "plugins.md should mention: {$needle}"
            );
        }
    }

    public function testCompatibilityDocCoversLayer(): void
    {
        $text = $this->readDoc('compatibility.md');
        foreach (
            [
                'Classic WordPress',
                'functions-shim',
                'wp_enqueue_scripts',
                'ap_enqueue_scripts',
                'theme.json',
                'auto',
                'cli-convert',
                'block',
            ] as $needle
        ) {
            $this->assertStringContainsStringIgnoringCase(
                $needle,
                $text,
                "compatibility.md should mention: {$needle}"
            );
        }
    }

    public function testSchemaDocCoversCoreAndForumTables(): void
    {
        $text = $this->readDoc('schema.md');
        foreach (
            [
                'AP_DB_VERSION',
                'schema_migrations',
                'options',
                'users',
                'posts',
                'postmeta',
                'terms',
                'comments',
                'forums',
                'topics',
                'forum_posts',
                'forum_permissions',
                'topic_track',
                'utf8mb4',
                'ap_',
            ] as $needle
        ) {
            $this->assertStringContainsStringIgnoringCase(
                $needle,
                $text,
                "schema.md should mention: {$needle}"
            );
        }
    }

    public function testReadmeLinksDeveloperDocs(): void
    {
        $readmePath = dirname(__DIR__, 2) . '/README.md';
        $this->assertFileIsReadable($readmePath);
        $readme = (string) file_get_contents($readmePath);
        $this->assertStringContainsString('docs/README.md', $readme);
        $this->assertStringContainsString('docs/hooks.md', $readme);
        $this->assertMatchesRegularExpression(
            '/(?im)^##\s+Documentation\s*$/',
            $readme,
            'README should have a Documentation section'
        );
        $this->assertStringContainsStringIgnoringCase('human landing page', $readme);
        $this->assertStringContainsStringIgnoringCase('second handbook', $readme);
        foreach (
            [
                'docs/README.md',
                'docs/install.md',
                'docs/rewrites.md',
                'docs/updates.md',
                'docs/cli.md',
                'docs/admin.md',
                'docs/forums.md',
                'docs/roles.md',
                'docs/rest.md',
                'docs/security.md',
                'docs/troubleshooting.md',
                'docs/bot_handbook.md',
                'docs/features_and_functions.md',
                'docs/hooks.md',
                'docs/themes.md',
                'docs/plugins.md',
                'docs/editor.md',
                'docs/site-icon.md',
                'docs/compatibility.md',
                'docs/schema.md',
                'docs/vision-compliance.md',
            ] as $link
        ) {
            $this->assertStringContainsString(
                '](' . $link . ')',
                $readme,
                "README Documentation table should link to {$link}"
            );
        }
    }

    public function testReadmeIsNotASecondHandbook(): void
    {
        $readmePath = dirname(__DIR__, 2) . '/README.md';
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
    }

    private function readDoc(string $relative): string
    {
        $path = $this->docsRoot . '/' . $relative;
        $this->assertFileIsReadable($path);
        $contents = file_get_contents($path);
        $this->assertNotFalse($contents);

        return $contents;
    }
}
