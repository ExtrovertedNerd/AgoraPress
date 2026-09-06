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
            'install' => ['install.md'],
            'rewrites' => ['rewrites.md'],
            'updates' => ['updates.md'],
            'cli' => ['cli.md'],
            'admin' => ['admin.md'],
            'forums' => ['forums.md'],
            'roles' => ['roles.md'],
            'rest' => ['rest.md'],
            'security' => ['security.md'],
            'troubleshooting' => ['troubleshooting.md'],
            'bot handbook' => ['bot_handbook.md'],
            'features and functions' => ['features_and_functions.md'],
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

    public function testFeaturesAndFunctionsCatalogIsTablesLookup(): void
    {
        $text = $this->readDoc('features_and_functions.md');
        $this->assertGreaterThan(
            800,
            strlen($text),
            'docs/features_and_functions.md should be a lookup catalog, not a stub'
        );

        foreach (
            [
                '/(?im)^##\s+Modules\s*$/',
                '/(?im)^##\s+Operator-facing options\s*$/',
                '/(?im)^##\s+Roles and capabilities\s*$/',
                '/(?im)^##\s+`ap-cli` verbs\s*$/',
                '/(?im)^##\s+REST resources/',
                '/(?im)^##\s+Admin screens/',
                '/(?im)^##\s+Default Agora schemes\s*$/',
                '/(?im)^##\s+Hooks\s*$/',
            ] as $pattern
        ) {
            $this->assertMatchesRegularExpression(
                $pattern,
                $text,
                "features_and_functions.md missing required table heading: {$pattern}"
            );
        }

        foreach (
            [
                'AP_DB_VERSION',
                '/ap-admin/',
                '/ap-json/',
                'rest_api_enabled',
                'analytics_enabled',
                'ap_register_admin_page',
                'ap_module_static_pages',
                'ap_module_blog',
                'ap_module_forum',
                'administrator',
                'editor',
                'author',
                'contributor',
                'subscriber',
                'edit_own_comments',
                'delete_own_comments',
                'cli info',
                'core check-update',
                'db migrate',
                '/ap/v1/posts',
                'marble',
                'parchment',
                'cloud',
                'obsidian',
                'midnight',
                'charcoal',
                'agora_color_scheme',
                'not in core',
                'hooks.md',
                'roles.md',
                'cli.md',
                'rest.md',
                'admin.md',
                'themes.md',
                'plugins.md',
                'forums.md',
            ] as $needle
        ) {
            $this->assertStringContainsString(
                $needle,
                $text,
                "features_and_functions.md should mention: {$needle}"
            );
        }

        foreach (
            [
                'help', 'version', 'cli', 'core', 'db', 'option', 'plugin',
                'theme', 'user', 'post', 'cache', 'cron', 'rewrite', 'site',
            ] as $group
        ) {
            $this->assertMatchesRegularExpression(
                '/`?' . preg_quote($group, '/') . '`?/',
                $text,
                "features_and_functions.md should name ap-cli group: {$group}"
            );
        }

        foreach (
            [
                'login.php', 'index.php', 'edit.php', 'post.php', 'post-new.php',
                'revision.php', 'edit-comments.php', 'comment.php', 'edit-tags.php',
                'media.php', 'media-new.php', 'upload.php', 'nav-menus.php',
                'widgets.php', 'themes.php', 'theme-options.php', 'plugins.php',
                'users.php', 'user-new.php', 'user-edit.php', 'profile.php',
                'forums.php', 'forum-edit.php', 'forum-groups.php',
                'forum-moderation.php', 'forum-topics.php', 'options-general.php',
                'options-writing.php', 'options-reading.php', 'options-discussion.php',
                'options-media.php', 'options-permalink.php', 'options-privacy.php',
                'options-modules.php', 'options-forums.php', 'options-hall-of-fame.php',
                'analytics.php', 'site-health.php', 'update-core.php', 'import.php',
                'export-personal-data.php', 'erase-personal-data.php', 'admin.php',
            ] as $screen
        ) {
            $this->assertStringContainsString(
                $screen,
                $text,
                "features_and_functions.md should name ACP screen: {$screen}"
            );
        }

        $this->assertStringContainsString('GET only', $text);
        $this->assertStringContainsString('No install/zip', $text);
        $this->assertStringContainsString('encyclopedia', $text);

        foreach (preg_split('/\R/', $text) as $line) {
            $stripped = ltrim($line);
            if ($stripped === '' || str_starts_with($stripped, '#') || str_starts_with($stripped, '|')) {
                continue;
            }
            $this->fail(
                'docs/features_and_functions.md must be tables only; leftover prose: ' . $line
            );
        }

        foreach (['Roland', 'stallboy@', 'mail.0shits.com', 'KeePass', 'Stalwart'] as $banned) {
            $this->assertStringNotContainsStringIgnoringCase(
                $banned,
                $text,
                "docs/features_and_functions.md must not contain private marker: {$banned}"
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
                '0.3.6-beta',
                'AP_DB_VERSION',
                'rewrites.md',
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
                '0.3.6-beta',
                'AP_DB_VERSION',
                'admin.md',
                'forums.md',
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
                '0.3.6-beta',
                'AP_DB_VERSION',
                'Local analytics',
                'analytics_enabled',
                'ap_register_admin_page',
                'schema.md',
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
                '0.3.6-beta',
                'AP_DB_VERSION',
                'ap_add_action',
                'ap_do_action',
                'ap_add_filter',
                'ap_apply_filters',
                'ap_plugins_loaded',
                'ap_mu_plugins_loaded',
                'ap_loaded',
                'ap_after_setup_theme',
                'ap_enqueue_scripts',
                'priority',
                'ap_analytics_should_record',
                'ap_analytics_prune',
                'grep',
                'encyclopedia',
                'grep for the rest',
                'ap_admin_menu',
                'ap_theme_options_register',
                'ap_rest_enabled',
                'ap_plugin_installed',
                'ap_moderation_topic_soft_deleted',
                'map target',
                'user_has_cap',
            ] as $needle
        ) {
            $this->assertStringContainsStringIgnoringCase(
                $needle,
                $text,
                "hooks.md should mention: {$needle}"
            );
        }
        $this->assertTrue(
            str_contains(strtolower($text), 'native core does **not** fire')
            || str_contains(strtolower($text), 'native core does not fire'),
            'hooks.md should say native core does not fire ap_init / ap_template_redirect'
        );
        $this->assertTrue(
            str_contains(strtolower($text), '**no** `user_has_cap`')
            || str_contains(strtolower($text), 'no `user_has_cap`'),
            'hooks.md must not invent user_has_cap as a core hook'
        );
    }

    public function testHooksDocSelectedNamesExistInCode(): void
    {
        $doc = $this->readDoc('hooks.md');
        preg_match_all('/`(ap_[a-z0-9_]+)`/', $doc, $matches);
        $mentioned = array_unique($matches[1] ?? []);

        $literals = $this->literalHookNamesFromProduct();
        $compat = $this->compatHookMapValues();
        $api = [
            'ap_add_action', 'ap_do_action', 'ap_do_action_ref_array',
            'ap_remove_action', 'ap_remove_all_actions', 'ap_has_action',
            'ap_did_action', 'ap_current_action', 'ap_doing_action',
            'ap_add_filter', 'ap_apply_filters', 'ap_apply_filters_ref_array',
            'ap_remove_filter', 'ap_remove_all_filters', 'ap_has_filter',
            'ap_current_filter', 'ap_doing_filter', 'ap_reset_hooks',
            'ap_register_admin_page', 'ap_add_cap', 'ap_get_the_excerpt',
            'ap_nav_menu', 'ap_print_styles', 'ap_print_scripts',
        ];
        $allowed = array_fill_keys(array_merge($literals, $compat, $api), true);

        $invented = [];
        foreach ($mentioned as $name) {
            if (isset($allowed[$name])) {
                continue;
            }
            if (str_ends_with($name, '_')) {
                $prefixOk = false;
                foreach ($literals as $existing) {
                    if (str_starts_with($existing, $name)) {
                        $prefixOk = true;
                        break;
                    }
                }
                if ($prefixOk) {
                    continue;
                }
            }
            $invented[] = $name;
        }
        $this->assertSame(
            [],
            $invented,
            'docs/hooks.md must not invent hook names; grep ap_do_action / ap_apply_filters for the rest'
        );

        $selected = explode('## Grep for the rest', $doc, 2)[0];
        foreach (['ap_excerpt_length', 'ap_excerpt_more'] as $bogus) {
            foreach (preg_split('/\R/', $selected) as $line) {
                if (!str_contains($line, $bogus) || !str_starts_with(ltrim($line), '|')) {
                    continue;
                }
                $lower = strtolower($line);
                $this->assertTrue(
                    str_contains($lower, 'map') || str_contains($lower, 'compat') || str_contains($lower, 'not'),
                    "hooks.md must not list {$bogus} as a native selected hook: {$line}"
                );
            }
        }

        $this->assertGreaterThan(
            count(array_diff(array_intersect($mentioned, $literals), $api)) + 20,
            count($literals),
            'hooks.md should stay selected + grep for the rest, not dump every core hook'
        );
    }

    /**
     * @return list<string>
     */
    private function literalHookNamesFromProduct(): array
    {
        $names = [];
        $call = '/ap_(?:do_action(?:_ref_array)?|apply_filters(?:_ref_array)?)\s*\(\s*'
            . '(?:self::[A-Z0-9_]+,\s*)?[\'"]([a-zA-Z0-9_]+)[\'"]/';
        $const = '/(?:ADMIN_MENU_HOOK|CRON_HOOK)\s*=\s*[\'"]([a-zA-Z0-9_]+)[\'"]/';
        $root = dirname(__DIR__, 2);
        foreach (['ap-includes', 'ap-admin'] as $folder) {
            $dir = $root . '/' . $folder;
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $file) {
                if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                    continue;
                }
                $text = (string) file_get_contents($file->getPathname());
                if (preg_match_all($call, $text, $m) > 0) {
                    foreach ($m[1] as $name) {
                        $names[$name] = true;
                    }
                }
                if (preg_match_all($const, $text, $m) > 0) {
                    foreach ($m[1] as $name) {
                        $names[$name] = true;
                    }
                }
            }
        }

        return array_keys($names);
    }

    /**
     * @return list<string>
     */
    private function compatHookMapValues(): array
    {
        $path = dirname(__DIR__, 2) . '/ap-includes/compatibility/class-ap-theme-compat.php';
        $text = (string) file_get_contents($path);
        preg_match_all("/'[a-z0-9_]+'\\s*=>\\s*'(ap_[a-z0-9_]+)'/", $text, $m);

        return $m[1] ?? [];
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
                '0.3.6-beta',
                'AP_DB_VERSION',
                'style.css',
                'index.php',
                'Template',
                'child',
                'ap_template_hierarchy',
                'front-page.php',
                'single.php',
                'ap_enqueue_scripts',
                'agora',
                'php ap-cli theme install',
                'admin.md',
                'cli.md',
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
                '0.3.6-beta',
                'AP_DB_VERSION',
                'ap_register_admin_page',
                'php ap-cli plugin install',
                'ap_plugin_installed',
                'rest_api_enabled',
                'GET-only',
                'rest.md',
                'admin.md',
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
                '0.3.6-beta',
                'AP_DB_VERSION',
                'Classic WordPress',
                'functions-shim',
                'wp_enqueue_scripts',
                'ap_enqueue_scripts',
                'theme.json',
                'auto',
                'cli-convert',
                'block',
                'troubleshooting.md',
                'try_files $uri $uri/ /index.php?$args',
                'rewrites.md',
                'ap_init',
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
                '0.3.6-beta',
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
                '0012_topic_type_enum.php',
                'No new table',
                'enum backfill',
                'standard',
                'announcement',
                'rules',
                'backfill',
            ] as $needle
        ) {
            $this->assertStringContainsStringIgnoringCase(
                $needle,
                $text,
                "schema.md should mention: {$needle}"
            );
        }
    }

    public function testIntegratorDocsFillListedGaps(): void
    {
        $schema = $this->readDoc('schema.md');
        $this->assertStringContainsString('0012_topic_type_enum.php', $schema);
        $this->assertStringContainsString('No new table', $schema);
        $this->assertStringContainsStringIgnoringCase('enum backfill', $schema);

        $plugins = $this->readDoc('plugins.md');
        $this->assertStringContainsString('rest_api_enabled', $plugins);
        $this->assertStringContainsString('GET-only', $plugins);
        $this->assertStringContainsString('ap_register_admin_page', $plugins);
        $this->assertStringContainsString('admin.md', $plugins);

        $hooks = $this->readDoc('hooks.md');
        $this->assertStringContainsStringIgnoringCase('grep', $hooks);
        $this->assertStringContainsStringIgnoringCase('encyclopedia', $hooks);
        $this->assertStringContainsStringIgnoringCase('grep for the rest', $hooks);
        $this->assertStringContainsString('ap_moderation_topic_soft_deleted', $hooks);
        $this->assertStringContainsString('ap_mu_plugins_loaded', $hooks);
        $this->assertTrue(
            str_contains($hooks, '**no** `user_has_cap`')
            || str_contains($hooks, 'no `user_has_cap`'),
            'hooks.md must not invent user_has_cap as a core hook'
        );

        $rest = $this->readDoc('rest.md');
        $this->assertStringContainsString('ACP Settings screen', $rest);
        $this->assertStringContainsString('rest_api_enabled', $rest);

        $compat = $this->readDoc('compatibility.md');
        $this->assertStringContainsString('try_files $uri $uri/ /index.php?$args', $compat);
    }

    public function testPluginZipInstallerAndAdminPageStayInPluginsDoc(): void
    {
        $plugins = $this->readDoc('plugins.md');
        $admin = $this->readDoc('admin.md');

        $this->assertMatchesRegularExpression('/(?im)^##\s+Plugin installer\s*$/', $plugins);
        $this->assertMatchesRegularExpression('/(?im)^##\s+Admin pages/', $plugins);
        foreach (
            [
                'ap_register_admin_page',
                'AP_Plugin_Installer',
                'ap_install_plugin_from_zip',
                'DEFAULT_MAX_BYTES',
                'plugin-upload',
                'install_plugins',
                'ZipArchive',
                'add_options_page',
                'ap_admin_menu',
                '40 MiB',
            ] as $needle
        ) {
            $this->assertStringContainsString(
                $needle,
                $plugins,
                "plugins.md is the canonical home, missing: {$needle}"
            );
        }

        $this->assertMatchesRegularExpression('/(?im)^##\s+Plugin zip installer\s*$/', $admin);
        $this->assertMatchesRegularExpression('/(?im)^##\s+Plugin-registered ACP pages\s*$/', $admin);
        $this->assertStringContainsString('plugins.md#plugin-installer', $admin);
        $this->assertStringContainsString(
            'plugins.md#admin-pages-settings-screens-in-the-acp',
            $admin
        );
        $this->assertStringContainsString('ap_register_admin_page', $admin);
        $this->assertStringContainsString('AP_Plugin_Installer', $admin);
    }

    public function testInstallDocCoversInstallerSurfaces(): void
    {
        $text = $this->readDoc('install.md');
        foreach (
            [
                '/install/',
                'php install/cli.php',
                '--db-driver',
                '--site-title',
                '--site-url',
                '--admin-user',
                '--admin-email',
                '--admin-password',
                '--table-prefix',
                '--config-path',
                '--skip-requirements',
                '--sample-content',
                '--no-sample-content',
                'AP_ADMIN_PASSWORD',
                'AP_DB_PASSWORD',
                'docker compose',
                'ap-config-sample.php',
                'ap-config.php',
                'ap-content/',
                'uploads',
                'Settings → Modules',
                'Permalinks',
                'Site Health',
                'analytics_enabled',
                'AP_DB_VERSION',
                'session.save_path',
                '/ap-admin/',
                '0.3.6-beta',
            ] as $needle
        ) {
            $this->assertStringContainsStringIgnoringCase(
                $needle,
                $text,
                "install.md should mention: {$needle}"
            );
        }
        $this->assertStringContainsString('EXIT_OK', $text);
        $this->assertTrue(
            str_contains($text, 'exit `0`')
            || str_contains($text, 'Exit codes')
            || str_contains($text, 'exit 0'),
            'install.md should document CLI installer exit codes'
        );
        foreach (['Roland', 'stallboy@', 'mail.0shits.com', 'KeePass', 'Stalwart'] as $banned) {
            $this->assertStringNotContainsStringIgnoringCase(
                $banned,
                $text,
                "docs/install.md must not contain private marker: {$banned}"
            );
        }
    }

    public function testRewritesDocCoversFrontController(): void
    {
        $text = $this->readDoc('rewrites.md');
        foreach (
            [
                'try_files $uri $uri/ /index.php',
                'try_files $uri $uri/ /index.php?$args',
                '.htaccess',
                'docker/nginx.conf.example',
                'index.php',
                'front controller',
                '?p=',
                '?page_id=',
                'Day and name',
                '/YYYY/MM/DD/',
                '/slug/',
                'php ap-cli rewrite flush',
                'permalink_structure',
                'Settings → Permalinks',
                'favicon.ico',
                'AllowOverride All',
                'mod_rewrite',
                'AP_Rewrite',
                'rewrite_rules',
                '0.3.6-beta',
                'AP_DB_VERSION',
            ] as $needle
        ) {
            $this->assertStringContainsStringIgnoringCase(
                $needle,
                $text,
                "rewrites.md should mention: {$needle}"
            );
        }
        $this->assertStringContainsString(
            'try_files $uri $uri/ /index.php',
            $text,
            'docs/rewrites.md must contain the shipped nginx try_files pattern'
        );
        foreach (['Roland', 'stallboy@', 'mail.0shits.com', 'KeePass', 'Stalwart'] as $banned) {
            $this->assertStringNotContainsStringIgnoringCase(
                $banned,
                $text,
                "docs/rewrites.md must not contain private marker: {$banned}"
            );
        }
    }

    public function testUpdatesDocCoversUpdaterSurfaces(): void
    {
        $text = $this->readDoc('updates.md');
        foreach (
            [
                'version.json',
                'https://agorapress.extrovertednerd.com/version.json',
                'Tools → Update Core',
                'php bin/package-release.php',
                'php ap-cli core check-update',
                'php ap-cli db migrate',
                'ap-config.php',
                'ap-config-sample.php',
                'install/',
                'ap-content/uploads/',
                'ap-content/plugins/',
                'custom themes',
                'no site identity',
                'sha256',
                'AgoraPress-{version}.zip',
                'version_check_enabled',
                'update_core',
                'AP_Core_Updater',
                'AP_Version_Check',
                '--force',
                'not in core',
                '0.3.6-beta',
                'AP_DB_VERSION',
                'ZipArchive',
                '.maintenance',
            ] as $needle
        ) {
            $this->assertStringContainsStringIgnoringCase(
                $needle,
                $text,
                "updates.md should mention: {$needle}"
            );
        }
        foreach (['Roland', 'stallboy@', 'mail.0shits.com', 'KeePass', 'Stalwart'] as $banned) {
            $this->assertStringNotContainsStringIgnoringCase(
                $banned,
                $text,
                "docs/updates.md must not contain private marker: {$banned}"
            );
        }
    }

    public function testCliDocCoversBuiltInCommandGroups(): void
    {
        $text = $this->readDoc('cli.md');
        foreach (
            [
                'php ap-cli --help',
                '--path',
                '--url',
                '--skip-plugins',
                '--skip-themes',
                'EXIT_OK',
                'EXIT_USAGE',
                'EXIT_ERROR',
                'EXIT_NOT_INSTALLED',
                'php install/cli.php',
                'help',
                'version',
                'cli info',
                'core check-update',
                'core version',
                'db check',
                'db migrate',
                'option get',
                'option set',
                'option delete',
                'option list',
                'plugin list',
                'plugin activate',
                'plugin deactivate',
                'theme list',
                'theme activate',
                'user list',
                'user get',
                'user create',
                'post list',
                'post get',
                'post create',
                'post update',
                'cache flush',
                'cron event list',
                'cron event run',
                'rewrite flush',
                'site health',
                '--file',
                'remote URLs',
                'stream wrappers',
                'draft',
                'publish',
                'AP_USER_PASSWORD',
                'ap_cli_init',
                'not in core',
                '0.3.6-beta',
                'AP_DB_VERSION',
            ] as $needle
        ) {
            $this->assertStringContainsStringIgnoringCase(
                $needle,
                $text,
                "cli.md should mention: {$needle}"
            );
        }
        $this->assertStringContainsString('php ap-cli core update', $text);
        foreach (['Roland', 'stallboy@', 'mail.0shits.com', 'KeePass', 'Stalwart'] as $banned) {
            $this->assertStringNotContainsStringIgnoringCase(
                $banned,
                $text,
                "docs/cli.md must not contain private marker: {$banned}"
            );
        }
    }

    public function testAdminDocCoversAcpAllowlist(): void
    {
        $text = $this->readDoc('admin.md');
        foreach (
            [
                '/ap-admin/',
                'login.php',
                'index.php',
                'edit.php',
                'post.php',
                'post-new.php',
                'revision.php',
                'edit-comments.php',
                'comment.php',
                'edit-tags.php',
                'media.php',
                'media-new.php',
                'upload.php',
                'nav-menus.php',
                'widgets.php',
                'themes.php',
                'theme-options.php',
                'plugins.php',
                'users.php',
                'user-new.php',
                'user-edit.php',
                'profile.php',
                'forums.php',
                'forum-edit.php',
                'forum-groups.php',
                'forum-moderation.php',
                'forum-topics.php',
                'options-general.php',
                'options-writing.php',
                'options-reading.php',
                'options-discussion.php',
                'options-media.php',
                'options-permalink.php',
                'options-privacy.php',
                'options-modules.php',
                'options-forums.php',
                'options-hall-of-fame.php',
                'analytics.php',
                'site-health.php',
                'update-core.php',
                'import.php',
                'export-personal-data.php',
                'erase-personal-data.php',
                'admin.php?page=',
                'ap_register_admin_page',
                'AP_Admin_Menu',
                'AP_Plugin_Installer',
                'Plugin Name',
                'ZipArchive',
                'install_plugins',
                'activate_plugins',
                'Hall of Fame',
                'handshake',
                'agorapress-hof-',
                'hall_of_fame_status',
                'challenge',
                'proof',
                'Donate',
                'paywall',
                'https://agorapress.extrovertednerd.com/donate',
                'manage_options',
                'manage_forums',
                'moderate_forums',
                'update_core',
                'view_site_health',
                'analytics_enabled',
                'rest_api_enabled',
                'site_icon',
                'Settings → Modules',
                'Settings → General',
                'Tools → Update Core',
                'WXR',
                'phpBB',
                'session.save_path',
                'not in core',
                'Gutenberg',
                'marketplace',
                '0.3.6-beta',
                'AP_DB_VERSION',
            ] as $needle
        ) {
            $this->assertStringContainsStringIgnoringCase(
                $needle,
                $text,
                "admin.md should mention: {$needle}"
            );
        }
        foreach (['Roland', 'stallboy@', 'mail.0shits.com', 'KeePass', 'Stalwart'] as $banned) {
            $this->assertStringNotContainsStringIgnoringCase(
                $banned,
                $text,
                "docs/admin.md must not contain private marker: {$banned}"
            );
        }
    }

    public function testForumsDocCoversModuleSurfaces(): void
    {
        $text = $this->readDoc('forums.md');
        foreach (
            [
                '0.3.6-beta',
                'AP_DB_VERSION',
                'ap_module_forum',
                'Settings → Modules',
                'AP_Forum',
                'AP_Forum_Front',
                'AP_Forum_Permissions',
                'AP_Forum_Moderation',
                'AP_Forum_Like',
                'AP_Forum_Stats',
                'AP_Forum_Guard',
                'AP_Forum_Read',
                'AP_Forum_Attachment',
                'AP_Group',
                'AP_Private_Message',
                'AP_Online',
                '/forums/',
                '/topic/',
                'ap_forum_view',
                'forum.php',
                'forum-view.php',
                'topic.php',
                'forum-search.php',
                'category',
                'standard',
                'sticky',
                'announcement',
                'rules',
                'two-pane',
                'ap-forum-post--two-pane',
                'forum_post_likes',
                'like_count',
                'manage_forums',
                'moderate_forums',
                'view_forum',
                'Public',
                'Members only',
                'forum_flood_interval',
                'forum_search_enabled',
                'forum_online_enabled',
                'forum_unread_tracking_enabled',
                'forum_private_messaging_enabled',
                'forum_attachments_enabled',
                'topic_track',
                'forum_track',
                'Total Topics',
                'rest_module_disabled',
                '/ap-json/ap/v1/forums',
                'php ap-cli forum',
                'not in core',
                'options-forums.php',
                'forum-moderation.php',
                'forum-groups.php',
            ] as $needle
        ) {
            $this->assertStringContainsStringIgnoringCase(
                $needle,
                $text,
                "forums.md should mention: {$needle}"
            );
        }
        foreach (['Roland', 'stallboy@', 'mail.0shits.com', 'KeePass', 'Stalwart'] as $banned) {
            $this->assertStringNotContainsStringIgnoringCase(
                $banned,
                $text,
                "docs/forums.md must not contain private marker: {$banned}"
            );
        }
    }

    public function testRolesDocCoversCapabilitySurfaces(): void
    {
        $text = $this->readDoc('roles.md');
        foreach (
            [
                '0.3.6-beta',
                'AP_DB_VERSION',
                'AP_Roles',
                'ap_user_roles',
                'ap_capabilities',
                'ap_user_level',
                'default_role',
                'administrator',
                'editor',
                'author',
                'contributor',
                'subscriber',
                'read',
                'manage_options',
                'list_users',
                'promote_users',
                'edit_posts',
                'upload_files',
                'moderate_comments',
                'edit_own_comments',
                'delete_own_comments',
                'manage_categories',
                'moderate_forums',
                'manage_forums',
                'view_site_health',
                'export_others_personal_data',
                'edit_comment',
                'delete_comment',
                'mapMetaCap',
                'ap_user_can',
                'ap_current_user_can',
                'ap_add_role',
                'ap_add_cap',
                'AP_Forum_Permissions',
                'AP_Group',
                'view_forum',
                'edit_own',
                'delete_own',
                'guests',
                'registered',
                'global_moderators',
                'user_has_cap',
                'php ap-cli role',
                'not in core',
                'users.php',
                'user-edit.php',
                'php ap-cli user create',
                'example.com',
            ] as $needle
        ) {
            $this->assertStringContainsStringIgnoringCase(
                $needle,
                $text,
                "roles.md should mention: {$needle}"
            );
        }
        foreach (['Roland', 'stallboy@', 'mail.0shits.com', 'KeePass', 'Stalwart'] as $banned) {
            $this->assertStringNotContainsStringIgnoringCase(
                $banned,
                $text,
                "docs/roles.md must not contain private marker: {$banned}"
            );
        }
    }

    public function testRestDocCoversApiSurfaces(): void
    {
        $text = $this->readDoc('rest.md');
        foreach (
            [
                '0.3.6-beta',
                'AP_DB_VERSION',
                '/ap-json/',
                '?rest_route=',
                'ap/v1',
                'rest_api_enabled',
                'X-AP-Nonce',
                '_ap_nonce',
                'ap_rest',
                'HTTP Basic',
                'cookie',
                'AP_Rest',
                'rest_disabled',
                'rest_no_route',
                'rest_module_disabled',
                'rest_cookie_invalid_nonce',
                'rest_not_logged_in',
                'php ap-cli option set rest_api_enabled',
                '/ap/v1/posts',
                '/ap/v1/pages',
                '/ap/v1/comments',
                '/ap/v1/users',
                '/ap/v1/categories',
                '/ap/v1/tags',
                '/ap/v1/forums',
                '/ap/v1/topics',
                'POST',
                'PUT',
                'PATCH',
                'DELETE',
                'ap_rest_api_init',
                'ap_register_rest_route',
                'ap_create_rest_nonce',
                'ap_module_blog',
                'edit_posts',
                'list_users',
                'try_files $uri $uri/ /index.php?$args',
                'application/json',
                'not in core',
                'Application Passwords',
                'OAuth',
                'JWT',
                'example.com',
                'admin@example.com',
            ] as $needle
        ) {
            $this->assertStringContainsStringIgnoringCase(
                $needle,
                $text,
                "rest.md should mention: {$needle}"
            );
        }
        foreach (['Roland', 'stallboy@', 'mail.0shits.com', 'KeePass', 'Stalwart'] as $banned) {
            $this->assertStringNotContainsStringIgnoringCase(
                $banned,
                $text,
                "docs/rest.md must not contain private marker: {$banned}"
            );
        }
    }

    public function testSecurityDocCoversHardeningSurfaces(): void
    {
        $text = $this->readDoc('security.md');
        foreach (
            [
                'prepared statements',
                'AP_DB',
                'PDO',
                'AP_Nonce',
                '_ap_nonce',
                'X-AP-Nonce',
                'Argon2id',
                'PASSWORD_ARGON2ID',
                'rate_limit',
                'session.save_path',
                'php-fpm',
                '770',
                'AP_TELEMETRY',
                'no-site-id',
                'version.json',
                'Hall of Fame',
                'export-personal-data',
                'erase-personal-data',
                'wp_page_for_privacy_policy',
                'analytics_enabled',
                'rest_api_enabled',
                'ap-config.php',
                '.env',
                'sqlite',
                'ap-includes',
                '.htaccess',
                'docker/nginx.conf.example',
                'try_files $uri $uri/ /index.php?$args',
                'AP_LOGGED_IN_KEY',
                'AP_NONCE_SALT',
                'not in core',
                '2FA',
                '0.3.6-beta',
                'AP_DB_VERSION',
            ] as $needle
        ) {
            $this->assertStringContainsStringIgnoringCase(
                $needle,
                $text,
                "security.md should mention: {$needle}"
            );
        }
        foreach (['Roland', 'stallboy@', 'mail.0shits.com', 'KeePass', 'Stalwart'] as $banned) {
            $this->assertStringNotContainsStringIgnoringCase(
                $banned,
                $text,
                "docs/security.md must not contain private marker: {$banned}"
            );
        }
    }

    public function testTroubleshootingDocCoversSymptomList(): void
    {
        $text = $this->readDoc('troubleshooting.md');
        foreach (
            [
                'try_files $uri $uri/ /index.php',
                'try_files $uri $uri/ /index.php?$args',
                '?p=',
                'mod_rewrite',
                'php ap-cli rewrite flush',
                'session.save_path',
                'php-fpm',
                'security token',
                'ap-content/uploads/',
                'Site Icon',
                'Settings → Modules',
                'ap_module_static_pages',
                'ap_module_blog',
                'ap_module_forum',
                'compatibility.md',
                'Block / FSE',
                'rest_api_enabled',
                '/ap-json/',
                '?rest_route=',
                'rest_disabled',
                '0.3.2',
                '0.3.6',
                'Edit User',
                'getById',
                'comment_ok',
                'Site Health',
                'not in core',
                '0.3.6-beta',
                'AP_DB_VERSION',
            ] as $needle
        ) {
            $this->assertStringContainsStringIgnoringCase(
                $needle,
                $text,
                "troubleshooting.md should mention: {$needle}"
            );
        }
        foreach (['Roland', 'stallboy@', 'mail.0shits.com', 'KeePass', 'Stalwart'] as $banned) {
            $this->assertStringNotContainsStringIgnoringCase(
                $banned,
                $text,
                "docs/troubleshooting.md must not contain private marker: {$banned}"
            );
        }
        $this->assertStringNotContainsStringIgnoringCase(
            'stallboy',
            $text,
            'docs/troubleshooting.md must not name private accounts'
        );
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
