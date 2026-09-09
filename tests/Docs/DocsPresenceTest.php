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
        'stallboy',
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
        'options-mail.php',
        'ap_mail_send',
        'ap_user_created',
        'ap_reserved_usernames',
        'forum_group_only',
        'group_only',
        'This group only',
        'AP_MAIL_TRANSPORT',
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

        $files = glob($this->docsRoot . '/*.md') ?: [];
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

        $this->assertMatchesRegularExpression('/(?im)^##\s+By audience\s*$/', $index);
        $this->assertMatchesRegularExpression('/(?im)^##\s+Not in core\s*$/', $index);
        $this->assertMatchesRegularExpression('/(?im)^##\s+Quick command map\s*$/', $index);

        AP_Cli::ensureBuiltins();
        foreach (AP_Cli::listCommands() as $group) {
            $this->assertStringContainsString(
                'php ap-cli ' . $group,
                $index,
                "docs/README.md should name builtin ap-cli group {$group}"
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
        $this->assertMatchesRegularExpression('/(?im)^##\s+How to use these docs\s*$/', $text);
        $this->assertMatchesRegularExpression('/(?im)^##\s+Public-safe rule\s*$/', $text);
        $this->assertMatchesRegularExpression('/(?im)^##\s+Do not invent surfaces\s*$/', $text);
        $this->assertMatchesRegularExpression('/(?im)^##\s+When to say \*\*not in core\*\*\s*$/', $text);
        $this->assertMatchesRegularExpression('/(?im)^##\s+Diagnose from generic symptoms\s*$/', $text);
        $this->assertMatchesRegularExpression('/(?im)^##\s+File a Heph bug\s*$/', $text);
        $this->assertMatchesRegularExpression('/(?im)^##\s+Close the customer loop\s*$/', $text);
        $this->assertStringContainsStringIgnoringCase('README.md', $text);
        $this->assertStringContainsString('troubleshooting.md', $text);
        $this->assertStringContainsStringIgnoringCase('registry name **AgoraPress**', $text);
        $this->assertStringContainsString('agent_api.md', $text);
        $this->assertStringContainsStringIgnoringCase('do not duplicate', $text);
        $this->assertStringContainsStringIgnoringCase('job id', $text);
        $this->assertStringContainsStringIgnoringCase('HaulTN', $text);
        $this->assertStringContainsStringIgnoringCase('Gutenberg', $text);
        $this->assertStringContainsStringIgnoringCase('AP_TELEMETRY', $text);
        foreach (self::PRIVATE_MARKERS as $banned) {
            $this->assertStringNotContainsStringIgnoringCase(
                $banned,
                $text,
                "docs/bot_handbook.md must not contain private marker: {$banned}"
            );
        }
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

        $nginxPath = $this->root . '/docker/nginx.conf.example';
        $htPath = $this->root . '/.htaccess';
        $this->assertFileIsReadable($nginxPath);
        $this->assertFileIsReadable($htPath);
        $nginx = (string) file_get_contents($nginxPath);
        $htaccess = (string) file_get_contents($htPath);

        $this->assertStringContainsString('try_files $uri $uri/ /index.php?$args;', $nginx);
        $this->assertStringContainsString('try_files $uri $uri/ /index.php?$args;', $text);
        $this->assertStringContainsString('RewriteCond %{REQUEST_FILENAME} !-f', $htaccess);
        $this->assertStringContainsString('RewriteCond %{REQUEST_FILENAME} !-f', $text);
        $this->assertStringContainsString('RewriteRule . /index.php [L]', $htaccess);
        $this->assertStringContainsString('RewriteRule . /index.php [L]', $text);
        $this->assertStringContainsString('favicon.ico', $htaccess);
        $this->assertStringContainsString('favicon.ico', $text);
        $this->assertStringContainsStringIgnoringCase('php ap-cli rewrite flush', $text);
        $this->assertStringContainsStringIgnoringCase('does **not** write', $text);
        $this->assertStringContainsString('?p=', $text);
        $this->assertStringContainsString('?page_id=', $text);
        $this->assertStringContainsString('/slug/', $text);
        $this->assertStringContainsStringIgnoringCase('/YYYY/MM/DD/', $text);
    }

    /**
     * SPEC: updates.md names version.json, one-click Update Core (no site
     * identity), package-release.php, check-update / db migrate, and the
     * skip list from AP_Core_Updater.
     */
    public function testUpdatesDocCoversPreserveListAndPublicEndpoint(): void
    {
        require_once $this->root . '/ap-includes/class-ap-version-check.php';
        require_once $this->root . '/ap-includes/class-ap-core-updater.php';

        $text = $this->readDoc('updates.md');
        $this->assertStringContainsString(\AP_Version_Check::DEFAULT_ENDPOINT, $text);
        $this->assertStringContainsStringIgnoringCase('Tools → Update Core', $text);
        $this->assertStringContainsStringIgnoringCase('no site identity', $text);
        $this->assertStringContainsString('php bin/package-release.php', $text);
        $this->assertStringContainsString('php ap-cli core check-update', $text);
        $this->assertStringContainsString('php ap-cli db migrate', $text);
        $this->assertStringContainsStringIgnoringCase('php ap-cli core update', $text);
        $this->assertStringContainsStringIgnoringCase('not in core', $text);

        foreach (\AP_Core_Updater::PRESERVE_EXACT as $path) {
            $this->assertStringContainsString(
                $path,
                $text,
                "docs/updates.md must name preserve path {$path}"
            );
        }
        foreach (
            [
                'install/',
                'ap-content/uploads/',
                'ap-content/plugins/',
                'ap-content/mu-plugins/',
            ] as $path
        ) {
            $this->assertStringContainsString(
                $path,
                $text,
                "docs/updates.md must name skip path {$path}"
            );
        }
        $this->assertStringContainsString('ap-content/themes/agora', $text);
        $this->assertStringContainsString('VersionCheck; no-site-id', $text);
        $this->assertStringContainsString('CoreUpdater; no-site-id', $text);
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

    /**
     * SPEC: cli.md is the cookbook for every built-in group, global flags,
     * exit codes, local-file-only post --file, and create defaults.
     */
    public function testCliDocCookbookMatchesApCliAsBuilt(): void
    {
        $cli = $this->readDoc('cli.md');
        $src = (string) file_get_contents($this->root . '/ap-includes/class-ap-cli.php');
        $bootstrap = (string) file_get_contents($this->root . '/ap-includes/bootstrap.php');
        $this->assertNotSame('', $src);
        $this->assertNotSame('', $bootstrap);

        $this->assertStringContainsString('AP_CLI_SKIP_PLUGINS', $bootstrap);
        $this->assertStringNotContainsString('AP_CLI_SKIP_THEMES', $bootstrap);
        $this->assertStringContainsString('AP_CLI_SKIP_THEMES', $src);
        $this->assertStringContainsString('AP_CLI_SKIP_THEMES', $cli);
        $this->assertStringContainsStringIgnoringCase('reserved', $cli);
        $this->assertStringContainsString('AP_CLI_SKIP_PLUGINS', $cli);

        foreach (
            [
                'EXIT_OK',
                'EXIT_USAGE',
                'EXIT_ERROR',
                'EXIT_NOT_INSTALLED',
                '--path',
                '--url',
                '--skip-plugins',
                '--skip-themes',
                'php ap-cli core update',
                'php install/cli.php',
                'AP_USER_PASSWORD',
                'ap_cli_init',
                'scheme://',
                'remote URLs and stream wrappers are not allowed',
                'draft',
                'publish',
                'check-update',
                '--force',
                'cron event list',
                'cron event run',
                'rewrite flush',
                'site health',
                'not in core',
            ] as $needle
        ) {
            $this->assertStringContainsStringIgnoringCase(
                $needle,
                $cli,
                "docs/cli.md should mention: {$needle}"
            );
        }

        $this->assertStringContainsString('always exits `0`', $cli);
        $this->assertStringContainsString('Usage: option get <name>', $cli);
        $this->assertStringContainsString('Option not found', $cli);
        $this->assertStringContainsString('not reachable', $cli);
        $this->assertStringContainsString('does **not** boot', $cli);
        $this->assertStringContainsString('`--format=json` always exits `0`', $cli);
        $this->assertStringContainsStringIgnoringCase('compact', $cli);
        $this->assertStringContainsString('--theme=', $cli);
        $this->assertStringContainsString('--key=', $cli);
        $this->assertStringContainsString('check_update', $cli);

        $this->assertStringContainsString('const EXIT_OK = 0', $src);
        $this->assertStringContainsString('const EXIT_USAGE = 1', $src);
        $this->assertStringContainsString('const EXIT_ERROR = 2', $src);
        $this->assertStringContainsString('const EXIT_NOT_INSTALLED = 3', $src);
        $this->assertStringContainsString("status = \$type === 'page' ? 'publish' : 'draft'", $src);
        $this->assertStringContainsString('remote URLs and stream wrappers are not allowed', $src);
        $this->assertStringContainsString('getenv(\'AP_USER_PASSWORD\')', $src);
        $this->assertStringContainsString("return self::EXIT_OK;", $src);
        $this->assertStringContainsString('JSON_PRETTY_PRINT', $src);
    }

    /**
     * SPEC: admin.md maps /ap-admin/ as built (Phase 0 allowlist, zip
     * installer pointer, Hall of Fame handshake, donation never a paywall).
     */
    public function testAdminDocMatchesAcpAsBuilt(): void
    {
        $text = $this->readDoc('admin.md');
        $adminDir = $this->root . '/ap-admin';
        $this->assertDirectoryExists($adminDir);

        $chrome = [
            'admin-bootstrap.php',
            'admin-header.php',
            'admin-footer.php',
        ];
        $entryScripts = glob($adminDir . '/*.php') ?: [];
        $this->assertNotSame([], $entryScripts, 'ap-admin/ should contain entry scripts');
        foreach ($entryScripts as $path) {
            $basename = basename($path);
            if (in_array($basename, $chrome, true)) {
                continue;
            }
            $this->assertStringContainsString(
                $basename,
                $text,
                "docs/admin.md must name ACP entry script {$basename}"
            );
        }

        $adminSrc = (string) file_get_contents($adminDir . '/includes/class-ap-admin.php');
        $this->assertNotSame('', $adminSrc);
        $this->assertStringContainsString("'index.php' => 'read'", $adminSrc);
        preg_match_all("/'([a-z0-9.-]+\\.php)' => '/", $adminSrc, $capMatches);
        $this->assertNotSame([], $capMatches[1], 'AP_Admin::screenCapabilities() keys should parse');
        foreach ($capMatches[1] as $basename) {
            $this->assertStringContainsString(
                $basename,
                $text,
                "docs/admin.md must name screenCapabilities key {$basename}"
            );
        }

        require_once $this->root . '/ap-includes/class-ap-hall-of-fame.php';
        require_once $this->root . '/ap-includes/class-ap-plugin-installer.php';
        require_once $this->root . '/ap-includes/class-ap-admin-menu.php';
        require_once $this->root . '/ap-admin/includes/class-ap-admin-analytics.php';

        $this->assertStringContainsString(\AP_Hall_Of_Fame::DEFAULT_ENDPOINT, $text);
        $this->assertStringContainsString(\AP_Hall_Of_Fame::DONATION_URL, $text);
        $this->assertStringContainsString(\AP_Hall_Of_Fame::PUBLIC_PAGE_URL, $text);
        $this->assertStringContainsString(\AP_Hall_Of_Fame::OPTION_STATUS, $text);
        $this->assertStringContainsString(\AP_Hall_Of_Fame::NONCE_JOIN, $text);
        $this->assertStringContainsString(\AP_Hall_Of_Fame::NONCE_LEAVE, $text);
        $this->assertStringContainsString(\AP_Hall_Of_Fame::NONCE_DISMISS, $text);
        $this->assertFalse(\AP_Hall_Of_Fame::usesInstallerPings());
        $this->assertStringContainsString('usesInstallerPings', $text);

        $this->assertSame(41943040, \AP_Plugin_Installer::DEFAULT_MAX_BYTES);
        $this->assertStringContainsString('40 MiB', $text);
        $this->assertStringContainsString('plugin-upload', $text);
        $this->assertSame('manage_options', \AP_Admin_Menu::DEFAULT_CAPABILITY);
        $this->assertContains('settings', \AP_Admin_Menu::allowedParents());
        $this->assertContains('plugins', \AP_Admin_Menu::allowedParents());
        $this->assertContains('tools', \AP_Admin_Menu::allowedParents());
        $this->assertSame('manage_options', \AP_Admin_Analytics::CAPABILITY);
        $this->assertSame(30, \AP_Admin_Analytics::DEFAULT_DAYS);
        $this->assertSame([7, 14, 30, 90], \AP_Admin_Analytics::ALLOWED_DAYS);

        foreach (
            [
                'user-edit.php?user_id=',
                'The requested admin page was not found.',
                'Read only (members)',
                'AP_ADMIN',
                'ap_admin_menu',
                'admin_menu',
                'maybeQueueAdminNotice',
                'noindex, nofollow',
                'blog_public',
                'sitemap_enabled',
                'open_graph_enabled',
                'phpbb-json',
                'phpbb-db',
                'paywall',
                'not in core',
                'admin-resend',
                'smtp.example.com',
                'noreply@example.com',
                'AP_MAIL_FROM_EMAIL',
                'AP_SMTP_HOST',
                'This group only',
                'register-guard.js',
            ] as $needle
        ) {
            $this->assertStringContainsString(
                $needle,
                $text,
                "docs/admin.md should mention: {$needle}"
            );
        }
    }

    /**
     * SPEC: forums.md is the operator + integrator guide for the first-class
     * forum module as built (hierarchy, types, ACL, PMs, module-off).
     */
    public function testForumsDocMatchesModuleAsBuilt(): void
    {
        $text = $this->readDoc('forums.md');
        $includes = $this->root . '/ap-includes';

        $frontConsts = $this->phpStringConsts($includes . '/class-ap-forum-front.php');
        foreach ($frontConsts as $name => $value) {
            if (str_starts_with($name, 'ACTION_')) {
                $this->assertStringContainsString(
                    $value,
                    $text,
                    "docs/forums.md must name AP_Forum_Front::{$name} ({$value})"
                );
            }
        }

        $permConsts = $this->phpStringConsts($includes . '/class-ap-forum-permissions.php');
        foreach ($permConsts as $name => $value) {
            if (str_starts_with($name, 'PERM_') || str_starts_with($name, 'ACCESS_')) {
                $this->assertStringContainsString(
                    $value,
                    $text,
                    "docs/forums.md must name AP_Forum_Permissions::{$name} ({$value})"
                );
            }
        }

        $forumConsts = $this->phpStringConsts($includes . '/class-ap-forum.php');
        $skipTopicAliases = [
            'TOPIC_TYPE_NORMAL' => true,
            'TOPIC_TYPE_ANNOUNCE' => true,
            'TOPIC_TYPE_GLOBAL' => true,
        ];
        foreach ($forumConsts as $name => $value) {
            if (
                str_starts_with($name, 'FORUM_TYPE_')
                || str_starts_with($name, 'FORUM_STATUS_')
                || str_starts_with($name, 'TOPIC_TYPE_')
            ) {
                if (isset($skipTopicAliases[$name])) {
                    continue;
                }
                $this->assertStringContainsString(
                    $value,
                    $text,
                    "docs/forums.md must name AP_Forum::{$name} ({$value})"
                );
            }
        }

        $groupConsts = $this->phpStringConsts($includes . '/class-ap-group.php');
        foreach ($groupConsts as $name => $value) {
            if (str_starts_with($name, 'SLUG_')) {
                $this->assertStringContainsString(
                    $value,
                    $text,
                    "docs/forums.md must name AP_Group::{$name} ({$value})"
                );
            }
        }

        $onlineConsts = $this->phpStringConsts($includes . '/class-ap-online.php');
        $this->assertArrayHasKey('GUEST_COOKIE', $onlineConsts);
        $this->assertStringContainsString($onlineConsts['GUEST_COOKIE'], $text);
        $onlineInts = $this->phpIntConsts($includes . '/class-ap-online.php');
        $this->assertStringContainsString((string) $onlineInts['DEFAULT_WINDOW'], $text);
        $this->assertStringContainsString((string) $onlineInts['MIN_WINDOW'], $text);
        $this->assertStringContainsString((string) $onlineInts['MAX_WINDOW'], $text);

        $readConsts = $this->phpStringConsts($includes . '/class-ap-forum-read.php');
        $this->assertStringContainsString($readConsts['META_LAST_MARK'], $text);
        $this->assertStringContainsString($readConsts['OPTION_ENABLED'], $text);

        $guardInts = $this->phpIntConsts($includes . '/class-ap-forum-guard.php');
        $this->assertStringContainsString((string) $guardInts['DEFAULT_FLOOD_INTERVAL'], $text);
        $this->assertStringContainsString((string) $guardInts['DEFAULT_SPAM_MAX_LINKS'], $text);

        $attachInts = $this->phpIntConsts($includes . '/class-ap-forum-attachment.php');
        $this->assertStringContainsString((string) $attachInts['DEFAULT_MAX_SIZE'], $text);
        $this->assertStringContainsString((string) $attachInts['DEFAULT_MAX_PER_POST'], $text);
        $this->assertStringContainsString((string) $attachInts['DEFAULT_USER_QUOTA'], $text);

        $restSrc = (string) file_get_contents($includes . '/class-ap-rest.php');
        $this->assertNotSame('', $restSrc);
        $this->assertSame(1, preg_match(
            '/public static function prepareForum\(.*?\n        return \[(.*?)\n        \];/s',
            $restSrc,
            $forumPayload
        ));
        $this->assertSame(1, preg_match(
            '/public static function prepareTopic\(.*?\n        return \[(.*?)\n        \];/s',
            $restSrc,
            $topicPayload
        ));
        preg_match_all("/'([a-z_]+)'\s*=>/", $forumPayload[1], $forumKeys);
        preg_match_all("/'([a-z_]+)'\s*=>/", $topicPayload[1], $topicKeys);
        foreach ($forumKeys[1] as $key) {
            $this->assertStringContainsString(
                '`' . $key . '`',
                $text,
                "docs/forums.md must name REST forum payload key {$key}"
            );
        }
        foreach ($topicKeys[1] as $key) {
            $this->assertStringContainsString(
                '`' . $key . '`',
                $text,
                "docs/forums.md must name REST topic payload key {$key}"
            );
        }

        $admin = $this->root . '/ap-admin';
        foreach ($this->phpActionAllowlist($admin . '/forum-topics.php') as $action) {
            $this->assertStringContainsString(
                '`' . $action . '`',
                $text,
                "docs/forums.md must name Topics row action {$action}"
            );
        }
        foreach ($this->phpActionAllowlist($admin . '/forum-moderation.php') as $action) {
            $this->assertStringContainsString(
                '`' . $action . '`',
                $text,
                "docs/forums.md must name Moderation row action {$action}"
            );
        }

        $deny = 'The Forum module is disabled. Enable it under Settings → Modules.';
        $this->assertStringContainsString($deny, $text);
        foreach (
            [
                'forums.php',
                'forum-topics.php',
                'forum-moderation.php',
                'forum-groups.php',
                'options-forums.php',
            ] as $script
        ) {
            $src = (string) file_get_contents($admin . '/' . $script);
            $this->assertStringContainsString(
                $deny,
                $src,
                "{$script} should deny with the documented Forum-module-off message"
            );
        }

        $functions = (string) file_get_contents($includes . '/functions.php');
        $this->assertSame(1, preg_match(
            '/function ap_forum_empty_state_html.*?\n    \$allowed = \[(.*?)\];/s',
            $functions,
            $emptyMatch
        ));
        preg_match_all("/'([a-z_]+)'/", $emptyMatch[1], $kinds);
        foreach ($kinds[1] as $kind) {
            $this->assertStringContainsString(
                '`' . $kind . '`',
                $text,
                "docs/forums.md must name empty-state kind {$kind}"
            );
        }

        foreach (
            [
                'forum_access_level',
                'rest_forum_invalid_id',
                'rest_topic_invalid_id',
                'Forum module is disabled.',
                'ap_forum_notice',
                'ap_mark_all_forums_read',
                'does **not** read',
                'Mark all as read',
                'status=open',
                'not in core',
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
            $this->assertStringContainsString(
                $needle,
                $text,
                "docs/forums.md should mention: {$needle}"
            );
        }
    }

    /**
     * @return array<string, string>
     */
    private function phpStringConsts(string $path): array
    {
        $this->assertFileIsReadable($path);
        $src = (string) file_get_contents($path);
        preg_match_all("/public const ([A-Z0-9_]+) = '([^']+)'/", $src, $matches, PREG_SET_ORDER);
        $out = [];
        foreach ($matches as $row) {
            $out[$row[1]] = $row[2];
        }
        $this->assertNotSame([], $out, 'No string constants in ' . basename($path));

        return $out;
    }

    /**
     * @return array<string, int>
     */
    private function phpIntConsts(string $path): array
    {
        $this->assertFileIsReadable($path);
        $src = (string) file_get_contents($path);
        preg_match_all('/public const ([A-Z0-9_]+) = (\d+)/', $src, $matches, PREG_SET_ORDER);
        $out = [];
        foreach ($matches as $row) {
            $out[$row[1]] = (int) $row[2];
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function phpActionAllowlist(string $path): array
    {
        $this->assertFileIsReadable($path);
        $src = (string) file_get_contents($path);
        $this->assertSame(
            1,
            preg_match('/in_array\(\$rowAction, \[(.*?)\], true\)/s', $src, $match),
            'row-action allowlist not found in ' . basename($path)
        );
        preg_match_all("/'([a-z_]+)'/", $match[1], $names);
        $this->assertNotSame([], $names[1], 'No row actions in ' . basename($path));

        return $names[1];
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

    /**
     * Public landing files (not only docs/*.md) must stay secret-safe.
     * Generic mail examples are the only SMTP host / from-address samples.
     *
     * @return array<string, array{0: string}>
     */
    public static function publicProductFileProvider(): array
    {
        return [
            'README.md' => ['README.md'],
            'CHANGELOG.md' => ['CHANGELOG.md'],
            'ap-config-sample.php' => ['ap-config-sample.php'],
        ];
    }

    #[DataProvider('publicProductFileProvider')]
    public function testPublicProductFileContainsNoPrivateMarkers(string $relative): void
    {
        $path = $this->root . '/' . $relative;
        $this->assertFileIsReadable($path, "Missing public file: {$relative}");
        $text = (string) file_get_contents($path);
        foreach (self::PRIVATE_MARKERS as $banned) {
            $this->assertStringNotContainsStringIgnoringCase(
                $banned,
                $text,
                "{$relative} must not contain private marker: {$banned}"
            );
        }
    }

    public function testPublicSafeMailExamplesAppearInOperatorDocs(): void
    {
        foreach (
            [
                'docs/README.md',
                'docs/bot_handbook.md',
                'docs/admin.md',
                'docs/security.md',
                'docs/install.md',
                'docs/troubleshooting.md',
                'docs/cli.md',
                'ap-config-sample.php',
            ] as $relative
        ) {
            $path = $this->root . '/' . $relative;
            $this->assertFileIsReadable($path, "Missing {$relative}");
            $text = (string) file_get_contents($path);
            $this->assertStringContainsString(
                'smtp.example.com',
                $text,
                "{$relative} should use generic SMTP host smtp.example.com"
            );
            $this->assertStringContainsString(
                'noreply@example.com',
                $text,
                "{$relative} should use generic from-address noreply@example.com"
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
        $this->assertStringContainsString('view_site_health', $security);
        $this->assertStringContainsString('session.save_path', $security);
        $this->assertStringContainsStringIgnoringCase('php-fpm', $security);

        $editor = $this->readDoc('editor.md');
        $this->assertStringContainsStringIgnoringCase('Gutenberg', $editor);
        $this->assertStringContainsStringIgnoringCase('not in core', $editor);
        $this->assertStringContainsStringIgnoringCase('non-goal', $editor);
    }

    /**
     * SPEC success: no public landing doc names a private host, mailbox, or
     * Addons skin. Charter guides are scanned in
     * testDocsFileContainsNoPrivateMarkers; README / CHANGELOG / sample in
     * testPublicProductFileContainsNoPrivateMarkers.
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
