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
    /**
     * Persona names, private mail hosts, credential stores, and Addons skins.
     *
     * @var list<string>
     */
    private const PRIVATE_MARKERS = [
        'Roland',
        'stallboy',
        'mail.0shits.com',
        '0shits.com',
        'KeePass',
        'Stalwart',
        'Jarvis',
        'BlindVault',
        'MensBS',
        'AgoraPress_Addons',
    ];

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
        foreach (['Roland', 'stallboy', 'mail.0shits.com', 'KeePass', 'Stalwart'] as $banned) {
            $this->assertStringNotContainsStringIgnoringCase(
                $banned,
                $index,
                "docs/README.md must not contain private marker: {$banned}"
            );
        }
    }

    public function testIndexMarkdownLinksEveryGuide(): void
    {
        $index = $this->readDoc('README.md');
        $files = glob($this->docsRoot . '/*.md') ?: [];
        $this->assertNotSame([], $files, 'docs/ should contain markdown guides');
        foreach ($files as $path) {
            $name = basename($path);
            if ($name === 'README.md') {
                continue;
            }
            $this->assertMatchesRegularExpression(
                '/\]\(' . preg_quote($name, '/') . '(?:#[^)]*)?\)/',
                $index,
                "docs/README.md should markdown-link {$name}"
            );
        }
    }

    public function testIndexCommandMapNamesEveryBuiltinGroup(): void
    {
        $index = $this->readDoc('README.md');
        $this->assertMatchesRegularExpression('/(?im)^##\s+Quick command map\s*$/', $index);
        $this->assertMatchesRegularExpression('/(?im)^##\s+Not in core\s*$/', $index);

        $matched = preg_match(
            '/(?im)^##\s+Quick command map\s*$.*?```(?:text)?\n(.*?)```/s',
            $index,
            $parts
        );
        $this->assertSame(1, $matched, 'docs/README.md should have a Quick command map fenced block');
        $block = $parts[1];

        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/ap-includes/class-ap-cli.php');
        $start = strpos($src, 'public static function ensureBuiltins()');
        $this->assertNotFalse($start, 'AP_Cli::ensureBuiltins() not found');
        preg_match_all("/self::addCommand\(\s*'([a-z0-9-]+)'/", substr($src, $start), $found);
        $this->assertNotSame([], $found[1], 'No addCommand() names found in ensureBuiltins()');
        foreach (array_unique($found[1]) as $group) {
            $this->assertStringContainsString(
                'php ap-cli ' . $group,
                $block,
                "docs/README.md command map should name builtin group {$group}"
            );
        }

        foreach (['--path', '--url', '--skip-plugins', '--skip-themes'] as $flag) {
            $this->assertStringContainsString(
                $flag,
                $index,
                "docs/README.md should name global flag {$flag}"
            );
        }
        $this->assertStringContainsString('php install/cli.php', $block);
        foreach (
            [
                'php ap-cli plugin install',
                'php ap-cli theme install',
                'php ap-cli core update',
                'php ap-cli user update',
                'php ap-cli user delete',
                'php ap-cli post delete',
                'php ap-cli module',
                'php ap-cli forum',
            ] as $invented
        ) {
            $this->assertStringNotContainsString(
                $invented,
                $block,
                "command map must not list invented verb: {$invented}"
            );
        }
    }

    public function testIndexAsBuiltSurfaces(): void
    {
        $index = $this->readDoc('README.md');
        foreach (
            [
                '0.3.9-beta',
                'AP_DB_VERSION',
                'one documentation tree',
                'docs/index.md',
                'public-safe',
                'do not invent',
                'not in core',
                '/ap-admin/',
                '/ap-json/',
                'rest_api_enabled',
                'analytics_enabled',
                'ap_register_admin_page',
                'try_files $uri $uri/ /index.php?$args',
                'class-ap-analytics.php',
                'class-ap-roles.php',
                'class-ap-rewrite.php',
                'class-ap-cli-install.php',
                '0012_topic_type_enum.php',
                'plugin install',
                'theme install',
                'core update',
                'user update',
                'post delete',
                'php ap-cli module',
                'php ap-cli forum',
                'no site identity',
                'administrator',
                'subscriber',
                'standard',
                'announcement',
                'rules',
                'agorapress.extrovertednerd.com',
                '/var/www/agorapress',
                'session.save_path',
                'example.com',
                'admin@example.com',
                'noreply@example.com',
                'smtp.example.com',
            ] as $needle
        ) {
            $this->assertStringContainsStringIgnoringCase(
                $needle,
                $index,
                "docs/README.md should mention: {$needle}"
            );
        }

        $offset = stripos($index, '## Not in core');
        $this->assertNotFalse($offset, 'docs/README.md must have a Not in core section');
        $notCore = substr($index, $offset);
        foreach (
            [
                'plugin install',
                'theme install',
                'core update',
                'user update',
                'post delete',
                'php ap-cli module',
                'php ap-cli forum',
                'docs/index.md',
                'Gutenberg',
            ] as $invented
        ) {
            $this->assertStringContainsStringIgnoringCase(
                $invented,
                $notCore,
                "docs/README.md Not in core should name {$invented}"
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
        foreach (['Roland', 'stallboy', 'mail.0shits.com', 'KeePass', 'Stalwart'] as $banned) {
            $this->assertStringNotContainsStringIgnoringCase(
                $banned,
                $text,
                "docs/bot_handbook.md must not contain private marker: {$banned}"
            );
        }
        $this->assertStringNotContainsStringIgnoringCase(
            'stallboy',
            $text,
            'docs/bot_handbook.md must not name private accounts'
        );
    }

    /**
     * SPEC §11: operating model for this product (not a copy of Heph Agent API).
     */
    public function testBotHandbookCoversOperatingModel(): void
    {
        $text = $this->readDoc('bot_handbook.md');
        foreach (
            [
                '/(?im)^##\s+How to use these docs\s*$/',
                '/(?im)^##\s+Public-safe rule\s*$/',
                '/(?im)^##\s+Do not invent surfaces\s*$/',
                '/(?im)^##\s+When to say \*\*not in core\*\*\s*$/',
                '/(?im)^##\s+Diagnose from generic symptoms\s*$/',
                '/(?im)^##\s+File a Heph bug\s*$/',
                '/(?im)^##\s+Close the customer loop\s*$/',
            ] as $pattern
        ) {
            $this->assertMatchesRegularExpression(
                $pattern,
                $text,
                "docs/bot_handbook.md missing heading matching: {$pattern}"
            );
        }

        foreach (
            [
                '0.3.9-beta',
                'AP_DB_VERSION',
                'README.md',
                'docs/index.md',
                'one documentation tree',
                'features_and_functions.md',
                'do not invent',
                'not in core',
                'public-safe',
                'never write',
                'agorapress.extrovertednerd.com',
                'version.json',
                'example.com',
                'admin@example.com',
                'noreply@example.com',
                'smtp.example.com',
                '/var/www/agorapress',
                'session.save_path',
                'php-fpm',
                'mechanism',
                'troubleshooting.md',
                'view_site_health',
                'php ap-cli site health',
                'php ap-cli option',
                'ap_module_forum',
                'php ap-cli module',
                'php ap-cli forum',
                'php ap-cli core update',
                'php ap-cli plugin install',
                'how do I turn forums on',
                '/2026/09/03/hello-world/',
                'try_files $uri $uri/ /index.php?$args',
                'does core send telemetry',
                'AP_TELEMETRY',
                'is Gutenberg coming',
                'Full Site Editing',
                'SaaS',
                'marketplace',
                'PHP 8.2',
                'theme.json',
                'HaulTN',
                'Logos',
                'Themis',
                'rest_api_enabled',
                'rest_disabled',
                '?rest_route=',
                'analytics_enabled',
                'AgoraPress',
                'agent_api.md',
                'do not duplicate',
                'job id',
                'Cited: docs/',
                'ap_do_action',
                'ap_apply_filters',
                'ap_cli_init',
                'editor.md',
                'compatibility.md',
                'plugins.md',
                'themes.md',
                'site-icon.md',
            ] as $needle
        ) {
            $this->assertStringContainsStringIgnoringCase(
                $needle,
                $text,
                "docs/bot_handbook.md should mention: {$needle}"
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
                '/(?im)^##\s+Schema\s*$/',
                '/(?im)^##\s+Roles and capabilities\s*$/',
                '/(?im)^##\s+Forum topic types\s*$/',
                '/(?im)^##\s+`ap-cli` verbs\s*$/',
                '/(?im)^##\s+REST resources/',
                '/(?im)^##\s+Admin screens/',
                '/(?im)^##\s+Default Agora schemes\s*$/',
                '/(?im)^##\s+Visual editor\s*$/',
                '/(?im)^##\s+Install and updates\s*$/',
                '/(?im)^##\s+Rewrites\s*$/',
                '/(?im)^##\s+Hooks\s*$/',
                '/(?im)^##\s+Not in core\s*$/',
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
                'schema_migrations',
                '0012_topic_type_enum.php',
                'hall_of_fame_status',
                'rate_limit_login_max',
                'forum_topics_per_page',
                'forum_attachment_max_per_post',
                'AP_TELEMETRY',
                'rest_disabled',
                'core update',
                'AP_CLI_SKIP_THEMES',
                'ap_user_roles',
                'forum_access_level',
                'php ap-cli module',
                'php ap-cli forum',
                'php ap-cli role',
                'Users → Ban',
                'try_files $uri $uri/ /index.php?$args',
                'EXIT_NOT_INSTALLED',
                'DONATION_URL',
                'ap_core_base_tables',
                'ap_forum_base_tables',
                'no site identity',
                '/%year%/%monthnum%/%day%/%postname%/',
                '/%year%/%monthnum%/%postname%/',
                '/archives/%post_id%',
                '/%postname%/',
                'Month and name',
                'Post name',
                'ap_mail_send',
                'ap_user_created',
                'ap_reserved_usernames',
                'ap_registration_captcha_mode',
                'forum_group_only',
                'This group only',
                'group_only',
                'forum_access_groups',
                'rest_cannot_view',
                'ap_form_ticket',
                'ap_hp',
                'wp_mail',
                'PHPMailer',
                'hCaptcha',
                'AP_MAIL_FROM_EMAIL',
                'AP_SMTP_HOST',
            ] as $needle
        ) {
            $this->assertStringContainsString(
                $needle,
                $text,
                "features_and_functions.md should mention: {$needle}"
            );
        }

        $cliSrc = (string) file_get_contents(dirname(__DIR__, 2) . '/ap-includes/class-ap-cli.php');
        $this->assertNotFalse($cliSrc);
        $start = strpos($cliSrc, 'public static function ensureBuiltins()');
        $this->assertNotFalse($start, 'AP_Cli::ensureBuiltins() not found');
        preg_match_all("/self::addCommand\(\s*'([a-z0-9-]+)'/", substr($cliSrc, $start), $cliGroups);
        $this->assertNotSame([], $cliGroups[1], 'No addCommand() names found in ensureBuiltins()');
        foreach (array_unique($cliGroups[1]) as $group) {
            $this->assertMatchesRegularExpression(
                '/`?' . preg_quote($group, '/') . '`?/',
                $text,
                "features_and_functions.md should name ap-cli group: {$group}"
            );
        }

        $adminDir = dirname(__DIR__, 2) . '/ap-admin';
        $skipScreens = ['admin-bootstrap.php', 'admin-header.php', 'admin-footer.php'];
        $screens = glob($adminDir . '/*.php') ?: [];
        $this->assertNotSame([], $screens, 'no ap-admin/*.php screens found');
        foreach ($screens as $screenPath) {
            $screen = basename($screenPath);
            if (in_array($screen, $skipScreens, true)) {
                continue;
            }
            $this->assertStringContainsString(
                $screen,
                $text,
                "features_and_functions.md should name ACP screen: {$screen}"
            );
        }

        $settingsSrc = (string) file_get_contents(dirname(__DIR__, 2) . '/ap-includes/class-ap-settings.php');
        $this->assertNotFalse($settingsSrc);
        preg_match_all("/self::registerSetting\(\s*'[^']+',\s*'([A-Za-z][A-Za-z0-9_]*)'/", $settingsSrc, $settingNames);
        preg_match_all(
            '/foreach\s*\(\s*\[(.*?)\]\s*as\s+\$\w+(?:\s*=>\s*\$\w+)?\s*\)\s*\{\s*self::registerSetting/s',
            $settingsSrc,
            $foreachBlocks
        );
        $optionNames = $settingNames[1];
        foreach ($foreachBlocks[1] as $block) {
            preg_match_all("/'([A-Za-z][A-Za-z0-9_]*)'/", $block, $fromBlock);
            $optionNames = array_merge($optionNames, $fromBlock[1]);
        }
        $optionNames = array_values(array_unique($optionNames));
        $this->assertNotSame([], $optionNames, 'No Settings API option names found in AP_Settings::registerCore()');
        foreach ($optionNames as $option) {
            $this->assertStringContainsString(
                $option,
                $text,
                "features_and_functions.md should name Settings API option: {$option}"
            );
        }

        $restSrc = (string) file_get_contents(dirname(__DIR__, 2) . '/ap-includes/class-ap-rest.php');
        $this->assertNotFalse($restSrc);
        $restStart = strpos($restSrc, 'private static function registerBuiltins()');
        $this->assertNotFalse($restStart, 'AP_Rest::registerBuiltins() not found');
        preg_match_all(
            "/self::registerRoute\(\s*(?:self::NAMESPACE|'')\s*,\s*'([^']+)'/",
            substr($restSrc, $restStart),
            $restPaths
        );
        $resources = [];
        foreach ($restPaths[1] as $path) {
            $trimmed = trim($path, '/');
            if ($trimmed === '') {
                continue;
            }
            $resources[] = explode('/', $trimmed)[0];
        }
        $resources = array_values(array_unique($resources));
        $this->assertNotSame([], $resources, 'No REST resources found in registerBuiltins()');
        foreach ($resources as $resource) {
            $this->assertStringContainsString(
                '/ap/v1/' . $resource,
                $text,
                "features_and_functions.md should name REST resource /ap/v1/{$resource}"
            );
        }

        $rewriteSrc = (string) file_get_contents(dirname(__DIR__, 2) . '/ap-includes/class-ap-rewrite.php');
        $this->assertNotFalse($rewriteSrc);
        preg_match_all("/public const STRUCTURE_\w+ = '([^']*)';/", $rewriteSrc, $structures);
        $this->assertNotSame([], $structures[1], 'AP_Rewrite STRUCTURE_* constants not found');
        foreach ($structures[1] as $structure) {
            if ($structure === '') {
                continue;
            }
            $this->assertStringContainsString(
                $structure,
                $text,
                "features_and_functions.md should name permalink structure {$structure}"
            );
        }

        $loadConfig = (string) file_get_contents(dirname(__DIR__, 2) . '/ap-includes/load-config.php');
        foreach (['ap_core_base_tables', 'ap_forum_base_tables'] as $func) {
            $this->assertSame(
                1,
                preg_match('/function ' . preg_quote($func, '/') . '\(\): array\s*\{(.*?)\n\}/s', $loadConfig, $body),
                $func . '() not found'
            );
            preg_match_all("/'([a-z0-9_]+)'/", $body[1], $tables);
            $this->assertNotSame([], $tables[1], $func . '() listed no string names');
            foreach ($tables[1] as $table) {
                $this->assertStringContainsString(
                    '`' . $table . '`',
                    $text,
                    "features_and_functions.md should name schema table: {$table}"
                );
            }
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

        foreach (['Roland', 'stallboy', 'mail.0shits.com', 'KeePass', 'Stalwart'] as $banned) {
            $this->assertStringNotContainsStringIgnoringCase(
                $banned,
                $text,
                "docs/features_and_functions.md must not contain private marker: {$banned}"
            );
        }
    }

    public function testCatalogCoversMailRegisterGateAndGroupOnly(): void
    {
        $text = $this->readDoc('features_and_functions.md');
        foreach ($this->mailOverrideConstantsFromPhp() as $const) {
            $this->assertStringContainsString(
                $const,
                $text,
                "docs/features_and_functions.md must name mail override constant {$const}"
            );
        }
        foreach (
            [
                'ap_mail_send',
                'wp_mail',
                'options-mail.php',
                'mail_from_email',
                'mail_transport',
                'smtp_encryption',
                'mail_last_error',
                'rate_limit_mail_max',
                'ap_user_created',
                'ap_reserved_usernames',
                'ap_registration_captcha_mode',
                'ap_registration_captcha_challenge',
                'ap_registration_verify_captcha',
                'ap_registration_captcha_fields',
                'registration_captcha',
                'reserved_usernames',
                'ap_form_ticket',
                'ap_hp',
                'ap_guard_ack',
                'register-guard.js',
                'This group only',
                'group_only',
                'forum_group_only',
                'forum_access_groups',
                'rest_cannot_view',
                'PHPMailer',
                'hCaptcha',
                'Turnstile',
            ] as $needle
        ) {
            $this->assertStringContainsString(
                $needle,
                $text,
                "docs/features_and_functions.md missing: {$needle}"
            );
        }
    }

    public function testCatalogCoversDefaultCategoryPreviewAndEditor(): void
    {
        $text = $this->readDoc('features_and_functions.md');
        foreach (
            [
                'agora_visitor_color_preview',
                'Allow visitors to preview color schemes',
                '?agora_scheme=',
                'SameSite=Lax',
                'agora-scheme-preview',
                'site-header__inner',
                'agora_get_color_scheme',
                'agora_get_stored_color_scheme',
                'agora_filter_color_scheme',
                'hostname special case',
                'Set as default',
                'This is the default category. Set another category as default first.',
                'ensureDefaultCategory',
                'sanitizeDefaultCategory',
                'action=set-default',
                'set-default-tag-',
                '<option value="0">',
                'ap_get_the_category_list',
                'Posted in , ,',
                'living category term id',
                'AP_Editor',
                '--ap-editor-bg',
                '--ap-editor-fg',
                '--ap-editor-surface',
                '--ap-editor-border',
                '--ap-on-accent',
                'color-scheme: inherit',
                'color-scheme: dark',
                'Canvas',
                'CanvasText',
                'Field',
                'FieldText',
                'button { color: inherit }',
                'data-ap-color-mode',
                'ap-includes/css/ap-editor.css',
                'agora-mode-dark',
                'private hosts',
                'persona mailboxes',
                'live fleet inventory',
                'example.com',
            ] as $needle
        ) {
            $this->assertStringContainsString(
                $needle,
                $text,
                "docs/features_and_functions.md missing: {$needle}"
            );
        }
        $this->assertNoPrivateMarkers('features_and_functions.md', $text);
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
                '0.3.9-beta',
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
                '0.3.9-beta',
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

    public function testEditorDocCoversContrastContract(): void
    {
        $text = $this->readDoc('editor.md');
        foreach (
            [
                'Contrast contract',
                'color-scheme: inherit',
                'color-scheme: dark',
                'agora-mode-dark',
                'data-ap-color-mode',
                '--ap-editor-bg',
                '--ap-editor-fg',
                '--ap-editor-surface',
                '--ap-editor-border',
                '--ap-on-accent',
                'Canvas',
                'CanvasText',
                'Field',
                'FieldText',
                'button { color: inherit }',
                'ap-includes/css/ap-editor.css',
                'Unicode',
                'admin.css',
                'example.com',
                'private hosts',
                'persona mailboxes',
                'live fleet inventory',
            ] as $needle
        ) {
            $this->assertStringContainsString(
                $needle,
                $text,
                "docs/editor.md must document contrast contract: {$needle}"
            );
        }
        $this->assertNoPrivateMarkers('editor.md', $text);
        $this->assertStringNotContainsStringIgnoringCase(
            'agorapress.extrovertednerd.com',
            $text,
            'docs/editor.md must not hardcode a product hostname'
        );
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
                '0.3.9-beta',
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
                '0.3.9-beta',
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
                'ap_reserved_usernames',
                'ap_user_created',
                'ap_mail_send',
                'ap_registration_captcha_mode',
                'ap_registration_captcha_challenge',
                'ap_registration_verify_captcha',
                'ap_registration_captcha_fields',
                'That username is not available.',
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

    public function testHooksDocCoversMailRegisterAndCaptcha(): void
    {
        $doc = $this->readDoc('hooks.md');
        $selected = explode('## Grep for the rest', $doc, 2)[0];
        $this->assertNotSame('', trim($selected), 'hooks.md must have a selected-hooks section');

        foreach (
            [
                'ap_mail_send',
                'ap_user_created',
                'ap_reserved_usernames',
                'ap_registration_captcha_mode',
                'ap_registration_captcha_challenge',
                'ap_registration_verify_captcha',
                'ap_registration_captcha_fields',
            ] as $name
        ) {
            $this->assertStringContainsString(
                '`' . $name . '`',
                $selected,
                "hooks.md selected table must name {$name}"
            );
            $foundRow = false;
            foreach (preg_split('/\R/', $selected) as $line) {
                if (str_starts_with(ltrim((string) $line), '|') && str_contains((string) $line, '`' . $name . '`')) {
                    $foundRow = true;
                    break;
                }
            }
            $this->assertTrue(
                $foundRow,
                "hooks.md must list {$name} as a selected hook row, not only a grep note"
            );
        }

        $lower = strtolower($selected);
        foreach (
            [
                'that username is not available.',
                'accepted_args',
                'status_pending',
                'registration_captcha',
                'hcaptcha',
                'turnstile',
                'text/plain',
                'no valid recipients',
                'rate limit blocks',
                'plugin-supplied',
            ] as $phrase
        ) {
            $this->assertStringContainsString(
                $phrase,
                $lower,
                "hooks.md mail/register section missing: {$phrase}"
            );
        }
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
        $this->assertStringContainsString('0.3.9-beta', $index);
        $this->assertStringContainsString('AP_Analytics', $index);
        $this->assertStringContainsString('class-ap-analytics.php', $index);
        $this->assertStringContainsString('AP_Forum_Like', $index);
    }

    public function testThemesDocCoversHierarchy(): void
    {
        $text = $this->readDoc('themes.md');
        foreach (
            [
                '0.3.9-beta',
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

    public function testThemesDocCoversSchemesPreviewAndEditor(): void
    {
        $text = $this->readDoc('themes.md');
        foreach (
            [
                'marble',
                'parchment',
                'cloud',
                'obsidian',
                'midnight',
                'charcoal',
                'agora_color_scheme',
                'agora_visitor_color_preview',
                'Allow visitors to preview color schemes',
                '?agora_scheme=',
                'agora_get_color_scheme',
                'agora_get_stored_color_scheme',
                'agora_get_color_schemes',
                'agora-scheme-preview',
                'site-header__inner',
                'SameSite=Lax',
                'color-scheme',
                'color-scheme: inherit',
                'color-scheme: dark',
                '--ap-editor-bg',
                '--ap-editor-fg',
                '--ap-editor-surface',
                '--ap-editor-border',
                '--ap-on-accent',
                'Canvas',
                'CanvasText',
                'Field',
                'FieldText',
                'agora_filter_color_scheme',
                'hostname special case',
                'button { color: inherit }',
                'ap-includes/css/ap-editor.css',
                'data-ap-color-mode',
                'agora-mode-dark',
                'example.com',
                'private hosts',
                'persona mailboxes',
                'live fleet inventory',
            ] as $needle
        ) {
            $this->assertStringContainsString(
                $needle,
                $text,
                "docs/themes.md must document schemes/preview/editor: {$needle}"
            );
        }
        $this->assertNoPrivateMarkers('themes.md', $text);
        $this->assertStringNotContainsStringIgnoringCase(
            'agorapress.extrovertednerd.com',
            $text,
            'docs/themes.md must not document a product-host gate for scheme preview'
        );
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
                '0.3.9-beta',
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
                '0.3.9-beta',
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
                'wp_mail',
                'AP_Mail::send',
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
                '0.3.9-beta',
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
        $root = dirname(__DIR__, 2);
        require_once $root . '/ap-includes/class-ap-cli-install.php';

        $text = $this->readDoc('install.md');
        foreach (
            [
                '/install/',
                'php install/cli.php',
                'AP_ADMIN_PASSWORD',
                'AP_DB_PASSWORD',
                'docker compose',
                'docker compose exec',
                'ap-config-sample.php',
                'ap-config.php',
                'ap-content/',
                'uploads',
                'Settings → Modules',
                'Permalinks',
                'Site Health',
                'analytics_enabled',
                'require_email_verification',
                'version_check_enabled',
                'AP_DB_VERSION',
                'session.save_path',
                '/ap-admin/',
                '0.3.9-beta',
                'HTTP 403',
                'HTTP 503',
                '-----BEGIN AP-CONFIG-----',
                'pdo_mysql',
                '?step=requirements',
                '?step=database',
                '?step=site',
                '?step=run',
                '?step=done',
            ] as $needle
        ) {
            $this->assertStringContainsStringIgnoringCase(
                $needle,
                $text,
                "install.md should mention: {$needle}"
            );
        }
        foreach (\AP_Cli_Install::KNOWN_OPTIONS as $option) {
            $this->assertStringContainsString(
                '--' . $option,
                $text,
                "install.md must name CLI installer flag --{$option} (AP_Cli_Install::KNOWN_OPTIONS)"
            );
        }
        foreach (['EXIT_OK', 'EXIT_USAGE', 'EXIT_REQUIREMENTS', 'EXIT_INSTALL'] as $constant) {
            $this->assertStringContainsString(
                $constant,
                $text,
                "install.md should name CLI installer exit constant {$constant}"
            );
        }
        $this->assertTrue(
            str_contains($text, 'exit `0`')
            || str_contains($text, 'Exit codes')
            || str_contains($text, 'exit 0'),
            'install.md should document CLI installer exit codes'
        );
        foreach (['Roland', 'stallboy', 'mail.0shits.com', 'KeePass', 'Stalwart'] as $banned) {
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
                '0.3.9-beta',
                'AP_DB_VERSION',
                'Apache vs Nginx',
                '/ap-json/',
                '/forums/search/',
                'query-string vars only',
                'does **not** write',
                'RewriteCond %{REQUEST_FILENAME} !-f',
                'RewriteRule . /index.php',
                '0 rule(s)',
                'not in core',
                'docker/apache-vhost.conf',
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
        foreach (['Roland', 'stallboy', 'mail.0shits.com', 'KeePass', 'Stalwart'] as $banned) {
            $this->assertStringNotContainsStringIgnoringCase(
                $banned,
                $text,
                "docs/rewrites.md must not contain private marker: {$banned}"
            );
        }
    }

    public function testUpdatesDocCoversUpdaterSurfaces(): void
    {
        $root = dirname(__DIR__, 2);
        require_once $root . '/ap-includes/class-ap-version-check.php';
        require_once $root . '/ap-includes/class-ap-core-updater.php';

        $text = $this->readDoc('updates.md');
        foreach (
            [
                'version.json',
                'Tools → Update Core',
                'php bin/package-release.php',
                'php ap-cli core check-update',
                'php ap-cli core version',
                'php ap-cli db migrate',
                'ap-content/uploads/',
                'ap-content/plugins/',
                'ap-content/mu-plugins/',
                'custom themes',
                'no site identity',
                'sha256',
                'AgoraPress-{version}.zip',
                'version_check_enabled',
                'update_core',
                'AP_Core_Updater',
                'AP_Version_Check',
                'sendsSiteIdentity',
                'maybeQueueAdminNotice',
                '--force',
                'not in core',
                '0.3.9-beta',
                'AP_DB_VERSION',
                'ZipArchive',
                'set_time_limit',
                'VersionCheck; no-site-id',
                'CoreUpdater; no-site-id',
                'ap-content/themes/agora',
                'Files were updated but database migration failed',
            ] as $needle
        ) {
            $this->assertStringContainsStringIgnoringCase(
                $needle,
                $text,
                "updates.md should mention: {$needle}"
            );
        }
        $this->assertStringContainsString(
            \AP_Version_Check::DEFAULT_ENDPOINT,
            $text,
            'updates.md must quote AP_Version_Check::DEFAULT_ENDPOINT'
        );
        foreach (\AP_Core_Updater::PRESERVE_EXACT as $path) {
            $this->assertStringContainsString(
                $path,
                $text,
                "updates.md must name preserve path {$path} (AP_Core_Updater::PRESERVE_EXACT)"
            );
        }
        $this->assertStringContainsString('install/', $text);
        $this->assertStringContainsStringIgnoringCase('php ap-cli core update', $text);
        $this->assertStringContainsStringIgnoringCase('**not** a cron event', $text);
        $this->assertStringContainsStringIgnoringCase('**Not** followed', $text);

        $script = (string) file_get_contents($root . '/bin/package-release.php');
        $this->assertNotSame('', $script);
        foreach (['--output-dir=', '--version=', '--prefix=', '--dry-run', '--json', '--help'] as $flag) {
            $this->assertStringContainsString(
                $flag,
                $script,
                "bin/package-release.php should parse {$flag}"
            );
            $docFlag = rtrim($flag, '=');
            $this->assertStringContainsString(
                $docFlag,
                $text,
                "updates.md must name package-release flag {$docFlag}"
            );
        }
        foreach (['Roland', 'stallboy', 'mail.0shits.com', 'KeePass', 'Stalwart'] as $banned) {
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
                '0.3.9-beta',
                'AP_DB_VERSION',
                'AP_CLI_SKIP_PLUGINS',
                'AP_CLI_SKIP_THEMES',
                'scheme://',
                'always exits `0`',
                'Usage: option get <name>',
                'Option not found',
                'not reachable',
                'check_update',
                '--theme=',
                '--key=',
                'toPublicArray',
                'compact',
                'PHP 8.2',
                'That username is not available.',
            ] as $needle
        ) {
            $this->assertStringContainsStringIgnoringCase(
                $needle,
                $text,
                "cli.md should mention: {$needle}"
            );
        }
        $this->assertStringContainsString('php ap-cli core update', $text);
        $this->assertStringContainsString('`--format=json` always exits `0`', $text);
        foreach (['Roland', 'stallboy', 'mail.0shits.com', 'KeePass', 'Stalwart'] as $banned) {
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
                'options-mail.php',
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
                'Settings → Mail',
                'Tools → Update Core',
                'WXR',
                'phpBB',
                'session.save_path',
                'not in core',
                'Gutenberg',
                'marketplace',
                '0.3.9-beta',
                'AP_DB_VERSION',
                'user-edit.php?user_id=',
                'The requested admin page was not found.',
                'Read only (members)',
                'AP_ADMIN',
                'maybeQueueAdminNotice',
                'COLOR_MODE_META',
                '40 MiB',
                'plugin-upload',
                'theme-upload',
                'hall-of-fame-dismiss',
                'usesInstallerPings',
                'phpbb-json',
                'phpbb-db',
                'blog_public',
                'sitemap_enabled',
                'open_graph_enabled',
                'noindex, nofollow',
                'DEFAULT_MAX_BYTES',
                'That username is not available.',
                'Activate account',
                'ap_activate_account',
                'activate-user-',
                '{siteurl}/ap-admin/login.php',
                'admin-resend',
                'ap_form_ticket',
                'ap_guard_ack',
                'register-guard.js',
                'smtp.example.com',
                'noreply@example.com',
                'AP_MAIL_FROM_EMAIL',
                'AP_SMTP_HOST',
                'PHPMailer',
                'hCaptcha',
                'Human check',
                'This group only',
                'text/plain',
                'Could not complete registration. Please try again.',
                'Your account was created, but the verification email could not be sent.',
                'verification_resent',
                'Set as default',
                'This is the default category. Set another category as default first.',
                'default_category',
                'ensureDefaultCategory',
                'Default Post Category',
                'set-default-tag-',
            ] as $needle
        ) {
            $this->assertStringContainsStringIgnoringCase(
                $needle,
                $text,
                "admin.md should mention: {$needle}"
            );
        }
        $this->assertStringContainsString('AP_Admin::COLOR_MODE_META', $text);
        foreach (['Roland', 'stallboy', 'mail.0shits.com', 'KeePass', 'Stalwart'] as $banned) {
            $this->assertStringNotContainsStringIgnoringCase(
                $banned,
                $text,
                "docs/admin.md must not contain private marker: {$banned}"
            );
        }
    }

    public function testAdminDocListsLockedReservedLoginsAndMailConstants(): void
    {
        $text = $this->readDoc('admin.md');
        $lower = strtolower($text);
        foreach ($this->reservedLoginsFromPhp() as $login) {
            $this->assertStringContainsString(
                strtolower((string) $login),
                $lower,
                "docs/admin.md must list locked reserved login {$login}"
            );
        }
        foreach ($this->mailOverrideConstantsFromPhp() as $const) {
            $this->assertStringContainsString(
                (string) $const,
                $text,
                "docs/admin.md must name mail override constant {$const}"
            );
        }
    }

    public function testAdminDocCoversDefaultCategory(): void
    {
        $text = $this->readDoc('admin.md');
        foreach (
            [
                'Set as default',
                'This is the default category. Set another category as default first.',
                'default_category',
                'ensureDefaultCategory',
                'setDefaultCategory',
                'sanitizeDefaultCategory',
                'set-default-tag-',
                'action=set-default',
                'Uncategorized',
                'uncategorized',
                'Default Post Category',
                '— Default',
                'Last remaining',
                'will move to',
                'default_category_set',
                'default_category_delete_blocked',
                'Default category updated.',
                'Could not delete the term.',
                '— Uncategorized / site default —',
                '<option value="0">',
                'AP_Admin_Terms',
                'AP_Taxonomy',
                'bulk-tags',
                'options-writing.php',
                'edit-tags.php',
                'manage_categories',
                'example.com',
                'private hosts',
                'persona mailboxes',
                'live fleet inventory',
            ] as $needle
        ) {
            $this->assertStringContainsString(
                $needle,
                $text,
                "docs/admin.md must document default category: {$needle}"
            );
        }
        $this->assertNoPrivateMarkers('admin.md', $text);
    }

    public function testForumsDocCoversModuleSurfaces(): void
    {
        $text = $this->readDoc('forums.md');
        foreach (
            [
                '0.3.9-beta',
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
                'forum_access_level',
                'rest_forum_invalid_id',
                'rest_topic_invalid_id',
                'Forum module is disabled.',
                'The Forum module is disabled. Enable it under Settings → Modules.',
                'ap_forum_notice',
                'ap_forum_empty_state_html',
                'ap_mark_all_forums_read',
                'forum_last_mark',
                'members_readonly',
                'forum_slug',
                'topic_slug',
                'approve_topic',
                'moveTopic',
                'status=open',
                'does **not** read',
                'Mark all as read',
                'Log in to like posts.',
                '10485760',
                'ap_forum_session',
                'This group only',
                'group_only',
                'forum_group_only',
                'forum_access_groups',
                'rest_cannot_view',
                'You cannot view this.',
                'ap_forum_cannot_view',
                '/forums/feed/',
                'getListableForums',
                'no public Join',
            ] as $needle
        ) {
            $this->assertStringContainsStringIgnoringCase(
                $needle,
                $text,
                "forums.md should mention: {$needle}"
            );
        }
        foreach (['Roland', 'stallboy', 'mail.0shits.com', 'KeePass', 'Stalwart'] as $banned) {
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
                '0.3.9-beta',
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
                'php ap-cli user get',
                'STATUS_PENDING',
                'requireLogin',
                'forum_access_level',
                'addUserCap',
                'ap_add_user_cap',
                'ap_user_can_post_reply',
                'example.com',
                'That username is not available.',
                'Activate account',
                'activate-user-',
            ] as $needle
        ) {
            $this->assertStringContainsStringIgnoringCase(
                $needle,
                $text,
                "roles.md should mention: {$needle}"
            );
        }
        foreach (['Roland', 'stallboy', 'mail.0shits.com', 'KeePass', 'Stalwart'] as $banned) {
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
                '0.3.9-beta',
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
                'Untitled',
                'ap_module_static_pages',
                'ap_module_forum',
                'X-AP-Total',
                'X-WP-Nonce',
                'ap_rest_namespaces',
                'ap_rest_prepare_post',
                'No forum ACL',
                'namespace index',
                'user_status',
                'this page only',
            ] as $needle
        ) {
            $this->assertStringContainsStringIgnoringCase(
                $needle,
                $text,
                "rest.md should mention: {$needle}"
            );
        }
        foreach (['Roland', 'stallboy', 'mail.0shits.com', 'KeePass', 'Stalwart'] as $banned) {
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
                '0.3.9-beta',
                'AP_DB_VERSION',
                'view_site_health',
                'query(',
                'HTTP_CLIENT_IP',
                'X-WP-Nonce',
                'last 12 hex',
                'AP_TRUST_PROXY',
                'That username is not available.',
                'activatePendingUser',
                'anti-squat',
                'AP_Admin::url()',
                '{siteurl}/ap-admin/login.php',
                'PHPMailer',
                'hCaptcha',
                'Turnstile',
                'ap_form_ticket',
                'ap_hp',
                'smtp.example.com',
                'noreply@example.com',
                'AP_MAIL_FROM_EMAIL',
                'AP_SMTP_HOST',
                'AP_SMTP_PASS',
                'Human check',
                'stream_socket_client',
                'Settings → Mail',
                'options-mail.php',
                'text/plain',
                'ap_reserved_usernames',
                'ap_user_created',
                'Could not complete registration. Please try again.',
                'AUTH PLAIN',
                'register-guard.js',
                'ap_guard_ack',
                'Silas',
                'not 2FA',
            ] as $needle
        ) {
            $this->assertStringContainsStringIgnoringCase(
                $needle,
                $text,
                "security.md should mention: {$needle}"
            );
        }
        foreach (['Roland', 'stallboy', 'mail.0shits.com', 'KeePass', 'Stalwart'] as $banned) {
            $this->assertStringNotContainsStringIgnoringCase(
                $banned,
                $text,
                "docs/security.md must not contain private marker: {$banned}"
            );
        }
    }

    public function testSecurityDocListsMailRegisterGateAndReservedNames(): void
    {
        $text = $this->readDoc('security.md');
        $lower = strtolower($text);
        foreach ($this->reservedLoginsFromPhp() as $login) {
            $this->assertStringContainsString(
                strtolower((string) $login),
                $lower,
                "docs/security.md must list locked reserved login {$login}"
            );
        }
        foreach ($this->mailOverrideConstantsFromPhp() as $const) {
            $this->assertStringContainsString(
                (string) $const,
                $text,
                "docs/security.md must name mail override constant {$const}"
            );
        }
        $this->assertStringContainsStringIgnoringCase('anti-squat', $text);
        $this->assertStringContainsStringIgnoringCase('not 2FA', $text);
        $this->assertStringContainsStringIgnoringCase('PHPMailer', $text);
        $this->assertStringContainsStringIgnoringCase('hCaptcha', $text);
        $this->assertStringContainsStringIgnoringCase('Turnstile', $text);
        $this->assertStringContainsStringIgnoringCase('stream_socket_client', $text);
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
                'Invalid security token',
                'ap-content/uploads/',
                'Site Icon',
                'Site icon must be a raster image',
                'Settings → Modules',
                'ap_module_static_pages',
                'ap_module_blog',
                'ap_module_forum',
                'The forum module is currently disabled',
                'The Forum module is disabled. Enable it under Settings → Modules.',
                'compatibility.md',
                'Block / FSE',
                'rest_api_enabled',
                'php ap-cli option set rest_api_enabled',
                '/ap-json/',
                '?rest_route=',
                'rest_disabled',
                'rest_no_route',
                'rest_module_disabled',
                'rest_cookie_invalid_nonce',
                'rest_not_logged_in',
                'X-WP-Nonce',
                '0.3.2',
                '0.3.6',
                'Edit User',
                'getById',
                'comment_ok',
                'comment_error',
                'Site Health',
                'view_site_health',
                'rate_limited',
                'require_email_verification',
                'mail_last_error',
                'Mail not arriving',
                'does **not** send',
                'admin-login',
                'AP_Session',
                'Too many failed login attempts',
                'ap-content/debug.log',
                'Files were updated but database migration failed',
                '.maintenance',
                'docker/apache-vhost.conf',
                'AllowOverride All',
                'query-string vars only',
                'not in core',
                '0.3.9-beta',
                'AP_DB_VERSION',
                'Activate account',
                'Resend verification',
                'spam folder',
                'smtp.example.com',
                'noreply@example.com',
                'You cannot view this.',
                'This group only',
                'group_only',
                'view_forum',
                'Members only',
                'rest_cannot_view',
                'forum_group_only',
                '24 hours',
                'login.php?action=resend',
                'stream_socket_client',
                'check your email',
                'forum_allow_guest_viewing',
                'Cannot delete Uncategorized',
                'Set as default',
                'This is the default category. Set another category as default first.',
                'Editor toolbar invisible',
                'color-scheme: dark',
                '--ap-editor-bg',
            ] as $needle
        ) {
            $this->assertStringContainsStringIgnoringCase(
                $needle,
                $text,
                "troubleshooting.md should mention: {$needle}"
            );
        }
        foreach (['Roland', 'stallboy', 'mail.0shits.com', 'KeePass', 'Stalwart'] as $banned) {
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

    public function testTroubleshootingCoversVerificationMailAndGroupAcl(): void
    {
        $text = $this->readDoc('troubleshooting.md');
        $this->assertMatchesRegularExpression('/(?im)^##\\s+Mail not arriving\\s*$/', $text);
        $this->assertMatchesRegularExpression('/(?im)^##\\s+Group board invisible\\s*$/', $text);
        $this->assertStringContainsStringIgnoringCase('verification mail never arrives', $text);
        $this->assertStringContainsStringIgnoringCase('spam', $text);
        $this->assertStringContainsStringIgnoringCase('smtp', $text);
        $this->assertStringContainsStringIgnoringCase('does **not** print', $text);
        $this->assertStringContainsStringIgnoringCase('check your email', $text);
        $this->assertStringContainsString('You cannot view this.', $text);
        $this->assertStringContainsString('This group only', $text);
        $this->assertStringContainsString('`group_only`', $text);
        $this->assertStringContainsString('`view_forum`', $text);
        $this->assertStringContainsStringIgnoringCase('Members only', $text);
        $this->assertStringContainsStringIgnoringCase('every logged-in', $text);
        $this->assertStringContainsString('rest_cannot_view', $text);
        $this->assertStringContainsString('moderate_forums', $text);
        $this->assertStringContainsString('manage_forums', $text);
        $this->assertStringContainsStringIgnoringCase('no public Join', $text);
        $this->assertStringContainsString('smtp.example.com', $text);
        $this->assertStringContainsString('noreply@example.com', $text);
        foreach (['Roland', 'stallboy', 'mail.0shits.com', 'KeePass', 'Stalwart'] as $banned) {
            $this->assertStringNotContainsStringIgnoringCase(
                $banned,
                $text,
                "docs/troubleshooting.md must not contain private marker: {$banned}"
            );
        }
    }

    public function testTroubleshootingCoversUncategorizedDeleteAndEditorContrast(): void
    {
        $text = $this->readDoc('troubleshooting.md');
        $this->assertMatchesRegularExpression('/(?im)^##\\s+Cannot delete Uncategorized\\s*$/', $text);
        $this->assertMatchesRegularExpression(
            '/(?im)^##\\s+Editor toolbar invisible on a dark theme\\s*$/',
            $text
        );
        foreach (
            [
                'Cannot delete Uncategorized',
                'Set as default',
                'This is the default category. Set another category as default first.',
                'Could not delete the term.',
                'default_category_delete_blocked',
                'last remaining',
                'Settings → Writing',
                'Posts → Categories',
                '— Uncategorized / site default —',
                '<option value="0">',
                'ensureDefaultCategory',
                'set-default-tag-',
                'Editor toolbar invisible',
                'color-scheme: dark',
                'color-scheme: inherit',
                '--ap-editor-bg',
                '--ap-editor-fg',
                '--ap-editor-surface',
                'Canvas',
                'CanvasText',
                'Field',
                'FieldText',
                'button { color: inherit }',
                'ap-includes/css/ap-editor.css',
                'AP_Editor',
                'agora-mode-dark',
                'data-ap-color-mode',
                'admin.css',
                'editor.md',
                'example.com',
                'private hosts',
                'persona mailboxes',
                'live fleet inventory',
            ] as $needle
        ) {
            $this->assertStringContainsString(
                $needle,
                $text,
                "troubleshooting.md must document Uncategorized delete / editor contrast: {$needle}"
            );
        }
        $this->assertNoPrivateMarkers('troubleshooting.md', $text);
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

        $table = $this->rootReadmeDocumentationTable($readme);
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
                $table,
                "README Documentation table should link to {$link}"
            );
        }

        $files = glob($this->docsRoot . '/*.md') ?: [];
        sort($files, SORT_STRING);
        foreach ($files as $path) {
            $target = 'docs/' . basename($path);
            $this->assertStringContainsString(
                '](' . $target . ')',
                $table,
                "README Documentation table should list every docs/*.md guide: {$target}"
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
        $handbooks = glob($this->docsRoot . '/*handbook*') ?: [];
        $names = array_map('basename', $handbooks);
        sort($names, SORT_STRING);
        $this->assertSame(
            ['bot_handbook.md'],
            $names,
            'One trusted-agent handbook only: docs/bot_handbook.md'
        );
    }

    private function rootReadmeDocumentationTable(string $readme): string
    {
        $matched = preg_match(
            '/(?im)^##\s+Documentation\s*$/m',
            $readme,
            $heading,
            PREG_OFFSET_CAPTURE
        );
        $this->assertSame(1, $matched, 'README should have a Documentation section');
        $start = $heading[0][1] + strlen($heading[0][0]);
        $rest = substr($readme, $start);
        if (preg_match('/(?im)^##\s+/m', $rest, $next, PREG_OFFSET_CAPTURE) === 1) {
            $section = substr($rest, 0, $next[0][1]);
        } else {
            $section = $rest;
        }

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

    /**
     * @return list<string>
     */
    private function reservedLoginsFromPhp(): array
    {
        $regSrc = (string) file_get_contents(
            dirname(__DIR__, 2) . '/ap-includes/class-ap-registration.php'
        );
        $this->assertNotSame('', $regSrc);
        $this->assertSame(
            1,
            preg_match('/public const RESERVED_LOGINS = \[(.*?)\];/s', $regSrc, $m)
        );
        preg_match_all("/'([^']+)'/", $m[1], $logins);
        $this->assertNotSame([], $logins[1], 'RESERVED_LOGINS should parse');

        return $logins[1];
    }

    /**
     * @return list<string>
     */
    private function mailOverrideConstantsFromPhp(): array
    {
        $mailSrc = (string) file_get_contents(
            dirname(__DIR__, 2) . '/ap-includes/class-ap-mail.php'
        );
        $this->assertNotSame('', $mailSrc);
        $start = strpos($mailSrc, 'function configConstantName');
        $this->assertNotFalse($start);
        $end = strpos($mailSrc, 'default => null', $start);
        $this->assertNotFalse($end);
        $block = substr($mailSrc, $start, $end - $start);
        preg_match_all("/'(AP_(?:MAIL|SMTP)_[A-Z_]+)'/", $block, $consts);
        $this->assertNotSame([], $consts[1], 'mail override constants should parse');

        return $consts[1];
    }

    private function readDoc(string $relative): string
    {
        $path = $this->docsRoot . '/' . $relative;
        $this->assertFileIsReadable($path);
        $contents = file_get_contents($path);
        $this->assertNotFalse($contents);

        return $contents;
    }

    private function assertNoPrivateMarkers(string $relative, string $text): void
    {
        foreach (self::PRIVATE_MARKERS as $banned) {
            $this->assertStringNotContainsStringIgnoringCase(
                $banned,
                $text,
                "docs/{$relative} must not contain private marker: {$banned}"
            );
        }
    }
}
