<?php

/**
 * Tests for AP_Cli (ap-cli tool).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Cli;

use AP_Cli;
use AP_Cron;
use AP_DB;
use AP_Migrator;
use AP_Options;
use AP_Plugin;
use AP_Post;
use AP_Roles;
use AP_Theme;
use AP_User;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_Cli::class)]
final class CliToolTest extends TestCase
{
    private string $root;

    private string $tempDir;

    /** @var list<string> */
    private array $stdout = [];

    /** @var list<string> */
    private array $stderr = [];

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        require_once $this->root . '/ap-includes/class-ap-cli.php';
        AP_Cli::reset();
        AP_Cli::ensureBuiltins();

        $this->tempDir = sys_get_temp_dir() . '/ap-cli-tool-' . uniqid('', true);
        $this->assertTrue(mkdir($this->tempDir, 0700, true));
        $this->stdout = [];
        $this->stderr = [];
    }

    protected function tearDown(): void
    {
        AP_Cli::reset();
        if (class_exists('AP_Plugin', false)) {
            AP_Plugin::reset();
        }
        if (class_exists('AP_Options', false)) {
            AP_Options::flushCache();
        }
        if (class_exists('AP_Cron', false)) {
            AP_Cron::reset();
        }
        if (class_exists('AP_Theme', false)) {
            AP_Theme::reset();
        }
        if (class_exists('AP_Roles', false)) {
            AP_Roles::flushCache();
        }
        if (class_exists('AP_Post', false)) {
            AP_Post::resetRegistry();
        }
        unset($GLOBALS['apdb']);
        $this->removeDir($this->tempDir);
    }

    /**
     * @return callable(string): void
     */
    private function captureOut(): callable
    {
        return function (string $line): void {
            $this->stdout[] = $line;
        };
    }

    /**
     * @return callable(string): void
     */
    private function captureErr(): callable
    {
        return function (string $line): void {
            $this->stderr[] = $line;
        };
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            if ($file->isDir()) {
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
        }
        @rmdir($dir);
    }

    /**
     * Boot minimal core classes + SQLite for command-handler tests.
     */
    private function bootSqliteCore(): AP_DB
    {
        require_once $this->root . '/ap-includes/version.php';
        require_once $this->root . '/ap-includes/hooks.php';
        require_once $this->root . '/ap-includes/class-ap-db.php';
        require_once $this->root . '/ap-includes/class-ap-migrator.php';
        require_once $this->root . '/ap-includes/class-ap-options.php';
        require_once $this->root . '/ap-includes/class-ap-plugin.php';
        require_once $this->root . '/ap-includes/class-ap-user.php';
        require_once $this->root . '/ap-includes/class-ap-roles.php';
        require_once $this->root . '/ap-includes/class-ap-post.php';
        require_once $this->root . '/ap-includes/class-ap-theme.php';
        require_once $this->root . '/ap-includes/class-ap-cron.php';
        require_once $this->root . '/ap-includes/class-ap-formatting.php';
        require_once $this->root . '/ap-includes/functions.php';

        if (function_exists('ap_reset_hooks')) {
            ap_reset_hooks();
        }
        AP_Plugin::reset();
        AP_Options::flushCache();
        AP_Cron::reset();
        AP_Theme::reset();
        AP_Roles::flushCache();
        AP_Post::resetRegistry();
        AP_Post::ensureBuiltins();

        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');
        (new AP_Migrator($db, AP_Migrator::defaultMigrationsPath()))->migrate();
        $GLOBALS['apdb'] = $db;

        AP_Roles::ensureDefaults($db);
        $db->insert('options', [
            'option_name' => 'active_plugins',
            'option_value' => '[]',
            'autoload' => 'yes',
        ]);
        $db->insert('options', [
            'option_name' => 'blogname',
            'option_value' => 'CLI Test Site',
            'autoload' => 'yes',
        ]);
        $db->insert('options', [
            'option_name' => 'stylesheet',
            'option_value' => 'agora',
            'autoload' => 'yes',
        ]);
        $db->insert('options', [
            'option_name' => 'template',
            'option_value' => 'agora',
            'autoload' => 'yes',
        ]);

        return $db;
    }

    public function testUsageListsCoreCommands(): void
    {
        $text = AP_Cli::usage('ap-cli');
        $this->assertStringContainsString('AgoraPress CLI', $text);
        $this->assertStringContainsString('plugin', $text);
        $this->assertStringContainsString('option', $text);
        $this->assertStringContainsString('db', $text);
        $this->assertStringContainsString('post', $text);
        $this->assertStringContainsString('install/cli.php', $text);
    }

    public function testParseArgvHelpAndVersion(): void
    {
        $help = AP_Cli::parseArgv(['ap-cli', '--help'], $this->root . '/');
        $this->assertTrue($help['help']);

        $ver = AP_Cli::parseArgv(['ap-cli', '--version'], $this->root . '/');
        $this->assertTrue($ver['version']);

        $v = AP_Cli::parseArgv(['ap-cli', '-V'], $this->root . '/');
        $this->assertTrue($v['version']);
    }

    public function testParseArgvCommandAndAssoc(): void
    {
        $parsed = AP_Cli::parseArgv(
            ['ap-cli', 'option', 'get', 'blogname', '--format=json'],
            $this->root . '/'
        );
        $this->assertSame('option', $parsed['command']);
        $this->assertSame(['get', 'blogname'], $parsed['args']);
        $this->assertSame('json', $parsed['assoc']['format'] ?? null);
    }

    public function testParseArgvPathAndSkipPlugins(): void
    {
        $parsed = AP_Cli::parseArgv(
            ['ap-cli', '--path=' . $this->root, '--skip-plugins', 'plugin', 'list'],
            $this->root . '/'
        );
        $this->assertSame('plugin', $parsed['command']);
        $this->assertTrue($parsed['skip_plugins']);
        $this->assertStringContainsString('AgoraPress', $parsed['path']);
    }

    public function testRunHelpExitZero(): void
    {
        $code = AP_Cli::runFromArgv(
            ['ap-cli', 'help'],
            $this->captureOut(),
            $this->captureErr(),
            $this->root . '/'
        );
        $this->assertSame(AP_Cli::EXIT_OK, $code);
        $combined = implode("\n", $this->stdout);
        $this->assertStringContainsString('AgoraPress CLI', $combined);
        $this->assertStringContainsString('plugin', $combined);
    }

    public function testRunVersionWithoutInstall(): void
    {
        $code = AP_Cli::runFromArgv(
            ['ap-cli', 'version'],
            $this->captureOut(),
            $this->captureErr(),
            $this->root . '/'
        );
        $this->assertSame(AP_Cli::EXIT_OK, $code);
        $this->assertNotEmpty($this->stdout);
        $this->assertStringContainsString('AgoraPress', $this->stdout[0]);
        $this->assertStringContainsString('PHP', $this->stdout[0]);
    }

    public function testRunVersionFlag(): void
    {
        $code = AP_Cli::runFromArgv(
            ['ap-cli', '--version'],
            $this->captureOut(),
            $this->captureErr(),
            $this->root . '/'
        );
        $this->assertSame(AP_Cli::EXIT_OK, $code);
        $this->assertStringContainsString('AgoraPress', implode("\n", $this->stdout));
    }

    public function testRunCliInfo(): void
    {
        $code = AP_Cli::runFromArgv(
            ['ap-cli', 'cli', 'info'],
            $this->captureOut(),
            $this->captureErr(),
            $this->root . '/'
        );
        $this->assertSame(AP_Cli::EXIT_OK, $code);
        $combined = implode("\n", $this->stdout);
        $this->assertStringContainsString('php:', $combined);
        $this->assertStringContainsString('agorapress:', $combined);
        $this->assertStringContainsString('installed:', $combined);
    }

    public function testUnknownCommand(): void
    {
        $code = AP_Cli::runFromArgv(
            ['ap-cli', 'not-a-real-command'],
            $this->captureOut(),
            $this->captureErr(),
            $this->root . '/'
        );
        $this->assertSame(AP_Cli::EXIT_USAGE, $code);
        $this->assertStringContainsString('Unknown command', implode("\n", $this->stderr));
    }

    public function testPluginListRequiresInstall(): void
    {
        // Project root is a valid tree but has no committed ap-config.php.
        if (is_readable($this->root . '/ap-config.php')) {
            $this->markTestSkipped('ap-config.php present; cannot assert not-installed exit.');
        }
        $code = AP_Cli::runFromArgv(
            ['ap-cli', 'plugin', 'list'],
            $this->captureOut(),
            $this->captureErr(),
            $this->root . '/'
        );
        $this->assertSame(AP_Cli::EXIT_NOT_INSTALLED, $code);
        $this->assertStringContainsString('not installed', implode("\n", $this->stderr));
    }

    public function testInvalidPathMissingBootstrap(): void
    {
        $code = AP_Cli::runFromArgv(
            ['ap-cli', '--path=' . $this->tempDir, 'plugin', 'list'],
            $this->captureOut(),
            $this->captureErr(),
            $this->tempDir . '/'
        );
        $this->assertSame(AP_Cli::EXIT_ERROR, $code);
        $this->assertStringContainsString('bootstrap.php', implode("\n", $this->stderr));
    }

    public function testCommandHelpForPlugin(): void
    {
        $code = AP_Cli::runFromArgv(
            ['ap-cli', 'help', 'plugin'],
            $this->captureOut(),
            $this->captureErr(),
            $this->root . '/'
        );
        $this->assertSame(AP_Cli::EXIT_OK, $code);
        $combined = implode("\n", $this->stdout);
        $this->assertStringContainsString('plugin', $combined);
        $this->assertStringContainsString('activate', $combined);
    }

    public function testAddCommandRegistration(): void
    {
        AP_Cli::addCommand(
            'hello',
            static function (array $args, array $assoc, callable $out, callable $err): int {
                $out('hello ' . ($args[0] ?? 'world'));

                return AP_Cli::EXIT_OK;
            },
            'Say hello',
            'hello [<name>]',
            false
        );
        $this->assertTrue(AP_Cli::hasCommand('hello'));
        $code = AP_Cli::runFromArgv(
            ['ap-cli', 'hello', 'agora'],
            $this->captureOut(),
            $this->captureErr(),
            $this->root . '/'
        );
        $this->assertSame(AP_Cli::EXIT_OK, $code);
        $this->assertSame(['hello agora'], $this->stdout);
    }

    public function testOptionGetSetDelete(): void
    {
        $this->bootSqliteCore();
        $out = $this->captureOut();
        $err = $this->captureErr();

        $code = AP_Cli::cmdOption(['get', 'blogname'], [], $out, $err);
        $this->assertSame(AP_Cli::EXIT_OK, $code);
        $this->assertSame(['CLI Test Site'], $this->stdout);

        $this->stdout = [];
        $code = AP_Cli::cmdOption(['set', 'blogname', 'Renamed'], [], $out, $err);
        $this->assertSame(AP_Cli::EXIT_OK, $code);
        $this->assertSame('Renamed', AP_Options::get('blogname', '', $GLOBALS['apdb']));

        $this->stdout = [];
        $code = AP_Cli::cmdOption(['get', 'blogname'], [], $out, $err);
        $this->assertSame(['Renamed'], $this->stdout);

        $code = AP_Cli::cmdOption(['delete', 'blogname'], [], $out, $err);
        $this->assertSame(AP_Cli::EXIT_OK, $code);
        $this->assertSame("\0miss", AP_Options::get('blogname', "\0miss", $GLOBALS['apdb']));
    }

    public function testOptionGetMissing(): void
    {
        $this->bootSqliteCore();
        $code = AP_Cli::cmdOption(
            ['get', 'definitely_not_an_option_xyz'],
            [],
            $this->captureOut(),
            $this->captureErr()
        );
        $this->assertSame(AP_Cli::EXIT_ERROR, $code);
        $this->assertStringContainsString('not found', implode("\n", $this->stderr));
    }

    public function testOptionList(): void
    {
        $this->bootSqliteCore();
        $code = AP_Cli::cmdOption(
            ['list'],
            ['search' => 'blog'],
            $this->captureOut(),
            $this->captureErr()
        );
        $this->assertSame(AP_Cli::EXIT_OK, $code);
        $combined = implode("\n", $this->stdout);
        $this->assertStringContainsString('blogname', $combined);
    }

    public function testPluginListActivateDeactivate(): void
    {
        $this->bootSqliteCore();
        $pluginsRoot = $this->tempDir . '/plugins';
        $this->assertTrue(mkdir($pluginsRoot, 0700, true));
        AP_Plugin::setPluginsRootOverride($pluginsRoot);
        file_put_contents(
            $pluginsRoot . '/hello-cli.php',
            "<?php\n/**\n * Plugin Name: Hello CLI\n * Version: 1.0.0\n */\n"
        );

        $out = $this->captureOut();
        $err = $this->captureErr();

        $code = AP_Cli::cmdPlugin(['list'], [], $out, $err);
        $this->assertSame(AP_Cli::EXIT_OK, $code);
        $this->assertStringContainsString('hello-cli.php', implode("\n", $this->stdout));
        $this->assertStringContainsString('Hello CLI', implode("\n", $this->stdout));

        $this->stdout = [];
        $code = AP_Cli::cmdPlugin(['activate', 'hello-cli.php'], [], $out, $err);
        $this->assertSame(AP_Cli::EXIT_OK, $code, implode("\n", $this->stderr));
        $this->assertTrue(AP_Plugin::isActive('hello-cli.php', $GLOBALS['apdb']));

        $this->stdout = [];
        $code = AP_Cli::cmdPlugin(['deactivate', 'hello-cli.php'], [], $out, $err);
        $this->assertSame(AP_Cli::EXIT_OK, $code);
        $this->assertFalse(AP_Plugin::isActive('hello-cli.php', $GLOBALS['apdb']));
    }

    public function testDbCheck(): void
    {
        $this->bootSqliteCore();
        $code = AP_Cli::cmdDb(
            ['check'],
            [],
            $this->captureOut(),
            $this->captureErr()
        );
        $this->assertSame(AP_Cli::EXIT_OK, $code);
        $combined = implode("\n", $this->stdout);
        $this->assertStringContainsString('status: ok', $combined);
        $this->assertStringContainsString('driver: sqlite', $combined);
        $this->assertStringContainsString('needs_migration: no', $combined);
    }

    public function testUserCreateAndGet(): void
    {
        $this->bootSqliteCore();
        $out = $this->captureOut();
        $err = $this->captureErr();

        $code = AP_Cli::cmdUser(
            ['create'],
            [
                'user_login' => 'cliuser',
                'user_email' => 'cliuser@example.test',
                'user_pass' => 'securepass99',
                'role' => 'author',
            ],
            $out,
            $err
        );
        $this->assertSame(AP_Cli::EXIT_OK, $code, implode("\n", $this->stderr));
        $this->assertStringContainsString('User created', implode("\n", $this->stdout));

        $this->stdout = [];
        $code = AP_Cli::cmdUser(['get', 'cliuser'], [], $out, $err);
        $this->assertSame(AP_Cli::EXIT_OK, $code);
        $combined = implode("\n", $this->stdout);
        $this->assertStringContainsString('user_login: cliuser', $combined);
        $this->assertStringContainsString('cliuser@example.test', $combined);
        $this->assertStringContainsString('author', $combined);
    }

    public function testUserList(): void
    {
        $this->bootSqliteCore();
        AP_User::create([
            'user_login' => 'listme',
            'user_email' => 'listme@example.test',
            'user_pass' => 'securepass99',
            'role' => 'subscriber',
        ], $GLOBALS['apdb']);

        $code = AP_Cli::cmdUser(
            ['list'],
            [],
            $this->captureOut(),
            $this->captureErr()
        );
        $this->assertSame(AP_Cli::EXIT_OK, $code);
        $this->assertStringContainsString('listme', implode("\n", $this->stdout));
    }

    public function testCronEventListEmpty(): void
    {
        $this->bootSqliteCore();
        $code = AP_Cli::cmdCron(
            ['event', 'list'],
            [],
            $this->captureOut(),
            $this->captureErr()
        );
        $this->assertSame(AP_Cli::EXIT_OK, $code);
        $this->assertStringContainsString('no scheduled events', implode("\n", $this->stdout));
    }

    public function testCronEventRun(): void
    {
        $this->bootSqliteCore();
        $fired = 0;
        if (function_exists('ap_add_action')) {
            ap_add_action('ap_cli_test_cron', static function () use (&$fired): void {
                $fired++;
            });
        }
        AP_Cron::scheduleEvent(time() - 10, 'hourly', 'ap_cli_test_cron', [], $GLOBALS['apdb']);

        $code = AP_Cli::cmdCron(
            ['event', 'run'],
            [],
            $this->captureOut(),
            $this->captureErr()
        );
        $this->assertSame(AP_Cli::EXIT_OK, $code);
        $this->assertStringContainsString('Ran', implode("\n", $this->stdout));
        $this->assertGreaterThanOrEqual(1, $fired);
    }

    public function testCacheFlush(): void
    {
        $this->bootSqliteCore();
        // Object cache API may not be started; command should still succeed.
        $code = AP_Cli::cmdCache(
            ['flush'],
            [],
            $this->captureOut(),
            $this->captureErr()
        );
        $this->assertSame(AP_Cli::EXIT_OK, $code);
        $this->assertNotEmpty($this->stdout);
    }

    public function testPostCreateGetListUpdatePageAndPost(): void
    {
        $this->bootSqliteCore();
        $out = $this->captureOut();
        $err = $this->captureErr();

        $pageBody = $this->tempDir . '/about-body.html';
        file_put_contents($pageBody, "<p>About page body</p>\n");
        $postBody = $this->tempDir . '/hello-body.html';
        file_put_contents($postBody, "<p>Hello world</p>\n");

        // Create page (default status publish).
        $code = AP_Cli::cmdPost(
            ['create'],
            [
                'type' => 'page',
                'title' => 'About',
                'slug' => 'about',
                'file' => $pageBody,
            ],
            $out,
            $err
        );
        $this->assertSame(AP_Cli::EXIT_OK, $code, implode("\n", $this->stderr));
        $this->assertStringContainsString('Created page ID', implode("\n", $this->stdout));
        $this->assertStringContainsString('about', implode("\n", $this->stdout));

        // Create post (default status draft).
        $this->stdout = [];
        $this->stderr = [];
        $code = AP_Cli::cmdPost(
            ['create'],
            [
                'type' => 'post',
                'title' => 'Hello',
                'file' => $postBody,
            ],
            $out,
            $err
        );
        $this->assertSame(AP_Cli::EXIT_OK, $code, implode("\n", $this->stderr));
        $this->assertStringContainsString('Created post ID', implode("\n", $this->stdout));

        // List pages.
        $this->stdout = [];
        $code = AP_Cli::cmdPost(['list'], ['type' => 'page'], $out, $err);
        $this->assertSame(AP_Cli::EXIT_OK, $code);
        $list = implode("\n", $this->stdout);
        $this->assertStringContainsString("\tpage\tabout\tAbout\tpublish", $list);

        // List posts includes draft.
        $this->stdout = [];
        $code = AP_Cli::cmdPost(['list'], ['type' => 'post'], $out, $err);
        $this->assertSame(AP_Cli::EXIT_OK, $code);
        $this->assertStringContainsString("\tpost\t", implode("\n", $this->stdout));
        $this->assertStringContainsString("\tdraft", implode("\n", $this->stdout));
        $this->assertStringContainsString('Hello', implode("\n", $this->stdout));

        // Get by slug + type.
        $this->stdout = [];
        $code = AP_Cli::cmdPost(
            ['get'],
            ['slug' => 'about', 'type' => 'page'],
            $out,
            $err
        );
        $this->assertSame(AP_Cli::EXIT_OK, $code);
        $got = implode("\n", $this->stdout);
        $this->assertStringContainsString('post_type: page', $got);
        $this->assertStringContainsString('post_name: about', $got);
        $this->assertStringContainsString('post_title: About', $got);
        $this->assertStringContainsString('post_status: publish', $got);
        $this->assertStringContainsString('<p>About page body</p>', $got);

        // Update body by slug.
        $updatedBody = $this->tempDir . '/about-v2.html';
        file_put_contents($updatedBody, "<p>Updated about</p>\n");
        $this->stdout = [];
        $this->stderr = [];
        $code = AP_Cli::cmdPost(
            ['update'],
            [
                'slug' => 'about',
                'type' => 'page',
                'file' => $updatedBody,
            ],
            $out,
            $err
        );
        $this->assertSame(AP_Cli::EXIT_OK, $code, implode("\n", $this->stderr));
        $this->assertStringContainsString('Updated page ID', implode("\n", $this->stdout));

        $page = AP_Post::getBySlug('about', 'page', $GLOBALS['apdb']);
        $this->assertNotNull($page);
        $this->assertStringContainsString('Updated about', (string) $page->post_content);
        $this->assertSame('About', (string) $page->post_title);

        // Update title by id only (body preserved).
        $this->stdout = [];
        $code = AP_Cli::cmdPost(
            ['update'],
            [
                'id' => (string) $page->ID,
                'title' => 'About Us',
            ],
            $out,
            $err
        );
        $this->assertSame(AP_Cli::EXIT_OK, $code);
        $page = AP_Post::get((int) $page->ID, $GLOBALS['apdb']);
        $this->assertNotNull($page);
        $this->assertSame('About Us', (string) $page->post_title);
        $this->assertStringContainsString('Updated about', (string) $page->post_content);

        // Publish the draft post via --id.
        $this->stdout = [];
        $code = AP_Cli::cmdPost(['list'], ['type' => 'post'], $out, $err);
        $this->assertSame(AP_Cli::EXIT_OK, $code);
        $postLine = '';
        foreach ($this->stdout as $line) {
            if (str_contains($line, "\tpost\t") && str_contains($line, 'Hello')) {
                $postLine = $line;
                break;
            }
        }
        $this->assertNotSame('', $postLine);
        $postId = (int) explode("\t", $postLine)[0];
        $this->assertGreaterThan(0, $postId);

        $this->stdout = [];
        $code = AP_Cli::cmdPost(
            ['update'],
            ['id' => (string) $postId, 'status' => 'publish'],
            $out,
            $err
        );
        $this->assertSame(AP_Cli::EXIT_OK, $code);
        $post = AP_Post::get($postId, $GLOBALS['apdb']);
        $this->assertNotNull($post);
        $this->assertSame('publish', (string) $post->post_status);
        $this->assertStringContainsString('Hello world', (string) $post->post_content);
    }

    public function testPostGetMissingTargetFails(): void
    {
        $this->bootSqliteCore();
        $code = AP_Cli::cmdPost(
            ['get'],
            ['id' => '99999'],
            $this->captureOut(),
            $this->captureErr()
        );
        $this->assertSame(AP_Cli::EXIT_ERROR, $code);
        $this->assertStringContainsString('not found', implode("\n", $this->stderr));

        $this->stderr = [];
        $code = AP_Cli::cmdPost(
            ['update'],
            ['slug' => 'no-such-page', 'type' => 'page', 'title' => 'X'],
            $this->captureOut(),
            $this->captureErr()
        );
        $this->assertSame(AP_Cli::EXIT_ERROR, $code);
        $this->assertStringContainsString('not found', implode("\n", $this->stderr));

        $this->stderr = [];
        $code = AP_Cli::cmdPost(
            ['get'],
            [],
            $this->captureOut(),
            $this->captureErr()
        );
        $this->assertSame(AP_Cli::EXIT_USAGE, $code);
    }

    public function testPostBadFileFails(): void
    {
        $this->bootSqliteCore();
        $out = $this->captureOut();
        $err = $this->captureErr();

        // Remote URL rejected.
        $code = AP_Cli::cmdPost(
            ['create'],
            [
                'type' => 'page',
                'title' => 'Remote',
                'file' => 'https://example.test/body.html',
            ],
            $out,
            $err
        );
        $this->assertSame(AP_Cli::EXIT_ERROR, $code);
        $this->assertStringContainsString('URL', implode("\n", $this->stderr));

        // Missing path.
        $this->stderr = [];
        $code = AP_Cli::cmdPost(
            ['create'],
            [
                'type' => 'post',
                'title' => 'Missing file',
                'file' => $this->tempDir . '/does-not-exist.html',
            ],
            $out,
            $err
        );
        $this->assertSame(AP_Cli::EXIT_ERROR, $code);
        $this->assertStringContainsString('not a readable regular file', implode("\n", $this->stderr));

        // Directory rejected.
        $this->stderr = [];
        $code = AP_Cli::cmdPost(
            ['create'],
            [
                'type' => 'post',
                'title' => 'Dir file',
                'file' => $this->tempDir,
            ],
            $out,
            $err
        );
        $this->assertSame(AP_Cli::EXIT_ERROR, $code);
        $this->assertStringContainsString('directory', strtolower(implode("\n", $this->stderr)));

        // Create without title.
        $this->stderr = [];
        $code = AP_Cli::cmdPost(
            ['create'],
            ['type' => 'page'],
            $out,
            $err
        );
        $this->assertSame(AP_Cli::EXIT_USAGE, $code);
        $this->assertStringContainsString('Title is required', implode("\n", $this->stderr));
    }

    public function testPostCommandHelpMentionsSubcommands(): void
    {
        $code = AP_Cli::runFromArgv(
            ['ap-cli', 'help', 'post'],
            $this->captureOut(),
            $this->captureErr(),
            $this->root . '/'
        );
        $this->assertSame(AP_Cli::EXIT_OK, $code);
        $combined = implode("\n", $this->stdout);
        $this->assertStringContainsString('post', $combined);
        $this->assertStringContainsString('list', $combined);
        $this->assertStringContainsString('create', $combined);
        $this->assertStringContainsString('update', $combined);
    }

    public function testEntryScriptExistsAndIsCli(): void
    {
        $entry = $this->root . '/ap-cli';
        $this->assertFileIsReadable($entry);
        $src = (string) file_get_contents($entry);
        $this->assertStringContainsString('AP_Cli::runFromArgv', $src);
        $this->assertStringContainsString('AP_CLI', $src);
        $this->assertStringContainsString('#!/usr/bin/env php', $src);
        $this->assertStringContainsString('PHP_SAPI', $src);
        $this->assertStringContainsString('posts/pages', $src);
    }

    public function testProcessHelpViaPhp(): void
    {
        $script = $this->root . '/ap-cli';
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' --help';
        $output = [];
        $exit = 0;
        exec($cmd . ' 2>&1', $output, $exit);
        $this->assertSame(0, $exit);
        $combined = implode("\n", $output);
        $this->assertStringContainsString('AgoraPress CLI', $combined);
        $this->assertStringContainsString('plugin', $combined);
    }

    public function testProcessVersionViaPhp(): void
    {
        $script = $this->root . '/ap-cli';
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' version';
        $output = [];
        $exit = 0;
        exec($cmd . ' 2>&1', $output, $exit);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('AgoraPress', implode("\n", $output));
    }

    public function testProcessNotInstalledExitCode(): void
    {
        if (is_readable($this->root . '/ap-config.php')) {
            $this->markTestSkipped('ap-config.php present; cannot assert not-installed exit.');
        }
        $script = $this->root . '/ap-cli';
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' db check';
        $output = [];
        $exit = 0;
        exec($cmd . ' 2>&1', $output, $exit);
        $this->assertSame(AP_Cli::EXIT_NOT_INSTALLED, $exit);
        $this->assertStringContainsString('not installed', implode("\n", $output));
    }
}
