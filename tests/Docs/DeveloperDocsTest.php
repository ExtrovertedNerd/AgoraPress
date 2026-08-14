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
            ] as $link
        ) {
            $this->assertStringContainsString(
                $link,
                $index,
                "docs/README.md should link to {$link}"
            );
        }
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
        $this->assertStringContainsString('0.3.5-beta', $index);
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
            '/(?im)^##\s+Developer documentation\s*$/',
            $readme,
            'README should have a Developer documentation section'
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
