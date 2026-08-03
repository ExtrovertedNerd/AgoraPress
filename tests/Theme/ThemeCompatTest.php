<?php

/**
 * Tests for the Classic WordPress Theme Compatibility Layer.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Theme;

use AP_DB;
use AP_Migrator;
use AP_Post;
use AP_Query;
use AP_Theme;
use AP_Theme_Compat;
use AP_Theme_Converter;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_Theme_Compat::class)]
#[CoversClass(AP_Theme_Converter::class)]
final class ThemeCompatTest extends TestCase
{
    private string $root;

    private string $tempThemes;

    private AP_DB $db;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        require_once $this->root . '/ap-includes/version.php';
        require_once $this->root . '/ap-includes/hooks.php';
        require_once $this->root . '/ap-includes/class-ap-db.php';
        require_once $this->root . '/ap-includes/class-ap-migrator.php';
        require_once $this->root . '/ap-includes/class-ap-options.php';
        require_once $this->root . '/ap-includes/class-ap-post.php';
        require_once $this->root . '/ap-includes/class-ap-query.php';
        require_once $this->root . '/ap-includes/class-ap-theme.php';
        require_once $this->root . '/ap-includes/class-ap-assets.php';
        require_once $this->root . '/ap-includes/functions.php';
        require_once $this->root . '/ap-includes/template-tags.php';
        require_once $this->root . '/ap-includes/compatibility/load.php';

        if (function_exists('ap_reset_hooks')) {
            ap_reset_hooks();
        }
        AP_Post::resetRegistry();
        AP_Theme::reset();
        AP_Theme_Compat::reset();
        unset($GLOBALS['ap_query'], $GLOBALS['ap_post'], $GLOBALS['ap_theme_support']);

        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');
        (new AP_Migrator($this->db, AP_Migrator::defaultMigrationsPath()))->migrate();
        AP_Post::ensureBuiltins();
        $GLOBALS['apdb'] = $this->db;

        foreach (
            [
                'home' => 'https://example.test',
                'siteurl' => 'https://example.test',
                'blogname' => 'Compat Site',
                'stylesheet' => 'agora',
                'template' => 'agora',
            ] as $name => $value
        ) {
            $this->db->insert('options', [
                'option_name' => $name,
                'option_value' => $value,
                'autoload' => 'yes',
            ]);
        }

        $this->tempThemes = sys_get_temp_dir() . '/ap-theme-compat-' . uniqid('', true);
        $this->assertTrue(mkdir($this->tempThemes, 0700, true));
        AP_Theme::setThemesRootOverride($this->tempThemes);
    }

    protected function tearDown(): void
    {
        if (function_exists('ap_reset_hooks')) {
            ap_reset_hooks();
        }
        AP_Post::resetRegistry();
        AP_Theme::reset();
        AP_Theme_Compat::reset();
        unset($GLOBALS['ap_query'], $GLOBALS['ap_post'], $GLOBALS['apdb'], $GLOBALS['ap_theme_support']);
        $this->removeDir($this->tempThemes);
    }

    public function testHookMapCommonWpHooks(): void
    {
        $this->assertSame('ap_enqueue_scripts', AP_Theme_Compat::mapHook('wp_enqueue_scripts'));
        $this->assertSame('ap_after_setup_theme', AP_Theme_Compat::mapHook('after_setup_theme'));
        $this->assertSame('ap_head', AP_Theme_Compat::mapHook('wp_head'));
        $this->assertSame('ap_footer', AP_Theme_Compat::mapHook('wp_footer'));
        $this->assertSame('ap_the_content', AP_Theme_Compat::mapHook('the_content'));
        $this->assertSame('custom_hook', AP_Theme_Compat::mapHook('custom_hook'));
    }

    public function testAgoraThemeDoesNotAutoEnableCompat(): void
    {
        $this->seedClassicTheme('agora', 'Agora');
        AP_Theme::setActiveOverride('agora', 'agora');
        AP_Theme_Compat::setActiveOverride(null);

        $this->assertFalse(AP_Theme_Compat::shouldEnableForTheme('agora', $this->db));
        $this->assertFalse(AP_Theme_Compat::isActive($this->db));
    }

    public function testClassicThemeAutoEnablesCompat(): void
    {
        $this->seedClassicTheme('classic-one', 'Classic One');
        AP_Theme::setActiveOverride('classic-one', 'classic-one');
        AP_Theme_Compat::setActiveOverride(null);

        $this->assertTrue(AP_Theme_Compat::shouldEnableForTheme('classic-one', $this->db));
        $this->assertTrue(AP_Theme_Compat::isActive($this->db));
    }

    public function testModeOffDisablesCompat(): void
    {
        $this->seedClassicTheme('classic-off', 'Classic Off');
        $this->assertTrue(AP_Theme_Compat::setMode('classic-off', 'off', $this->db));
        $this->assertSame('off', AP_Theme_Compat::getMode('classic-off', $this->db));
        $this->assertFalse(AP_Theme_Compat::shouldEnableForTheme('classic-off', $this->db));
    }

    public function testModeOnForcesCompat(): void
    {
        // Even without headers-only stub: force on for agora-like slug in temp root.
        $this->seedClassicTheme('forced-on', 'Forced');
        $this->assertTrue(AP_Theme_Compat::setMode('forced-on', 'on', $this->db));
        $this->assertTrue(AP_Theme_Compat::shouldEnableForTheme('forced-on', $this->db));
    }

    public function testBlockThemeDetectedAndNotAutoEnabled(): void
    {
        $dir = $this->tempThemes . '/blocky';
        $this->assertTrue(mkdir($dir, 0700, true));
        file_put_contents(
            $dir . '/style.css',
            "/*\nTheme Name: Blocky\nVersion: 1.0\n*/\n"
        );
        file_put_contents($dir . '/theme.json', "{\"version\": 2}\n");
        file_put_contents($dir . '/index.php', "<?php\n// unused in FSE\n");

        $this->assertTrue(AP_Theme_Compat::isBlockTheme('blocky'));
        $this->assertFalse(AP_Theme_Compat::shouldEnableForTheme('blocky', $this->db));
        $this->assertFalse(AP_Theme_Compat::isClassicTheme('blocky'));
    }

    public function testEnsureLoadedDefinesWpTemplateTags(): void
    {
        AP_Theme_Compat::ensureLoaded(true, $this->db);

        $this->assertTrue(function_exists('have_posts'));
        $this->assertTrue(function_exists('the_title'));
        $this->assertTrue(function_exists('the_content'));
        $this->assertTrue(function_exists('get_header'));
        $this->assertTrue(function_exists('wp_enqueue_style'));
        $this->assertTrue(function_exists('add_action'));
        $this->assertTrue(function_exists('esc_html'));
        $this->assertTrue(function_exists('is_home'));
        $this->assertTrue(function_exists('body_class'));
        $this->assertTrue(function_exists('get_stylesheet_uri'));
        $this->assertTrue(AP_Theme_Compat::shimsLoaded());
    }

    public function testWpShimsAliasToApHelpers(): void
    {
        AP_Theme_Compat::ensureLoaded(true, $this->db);

        $postId = AP_Post::insert([
            'post_title' => 'Hello Compat',
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_name' => 'hello-compat',
            'post_content' => "Line one\nLine two",
        ], $this->db);
        $post = AP_Post::get($postId, $this->db);
        $this->assertInstanceOf(AP_Post::class, $post);
        $GLOBALS['ap_post'] = $post;

        $this->assertSame('Hello Compat', get_the_title());
        $this->assertSame('Hello Compat', ap_get_the_title());

        ob_start();
        the_title();
        $out = (string) ob_get_clean();
        $this->assertStringContainsString('Hello Compat', $out);

        $this->assertSame('Compat Site', get_bloginfo('name'));
        $this->assertSame(esc_html('<b>x</b>'), htmlspecialchars('<b>x</b>', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }

    public function testAddActionMapsWpEnqueueScripts(): void
    {
        AP_Theme_Compat::ensureLoaded(true, $this->db);
        $called = false;
        add_action('wp_enqueue_scripts', static function () use (&$called): void {
            $called = true;
        });
        $this->assertTrue((bool) has_action('wp_enqueue_scripts'));
        // Mapped hook fires under ap_enqueue_scripts.
        ap_do_action('ap_enqueue_scripts');
        $this->assertTrue($called);
    }

    public function testConditionalsFromMainQuery(): void
    {
        AP_Theme_Compat::ensureLoaded(true, $this->db);

        $home = new AP_Query([
            'post_type' => 'post',
            'posts_per_page' => 5,
        ], $this->db);
        $GLOBALS['ap_query'] = $home;

        $this->assertTrue(is_home());
        $this->assertTrue(is_front_page());
        $this->assertFalse(is_single());
        $this->assertFalse(is_404());

        $missing = new AP_Query(['p' => 999999], $this->db);
        $GLOBALS['ap_query'] = $missing;
        $this->assertTrue(is_404());
        $this->assertFalse(is_home());
    }

    public function testSafeLoadFunctionsPhpUsesShims(): void
    {
        $this->seedClassicTheme('shim-theme', 'Shim Theme');
        $fn = $this->tempThemes . '/shim-theme/functions.php';
        file_put_contents(
            $fn,
            "<?php\ndeclare(strict_types=1);\n"
            . "add_action('after_setup_theme', static function (): void {\n"
            . "  add_theme_support('title-tag');\n"
            . "  register_nav_menus(['primary' => 'Primary']);\n"
            . "});\n"
            . "add_action('wp_enqueue_scripts', static function (): void {\n"
            . "  wp_enqueue_style('shim-theme', get_stylesheet_uri(), [], '1.0');\n"
            . "});\n"
        );

        AP_Theme::setActiveOverride('shim-theme', 'shim-theme');
        AP_Theme_Compat::setActiveOverride(null);
        AP_Theme::setup($this->db);

        $this->assertTrue(AP_Theme_Compat::shimsLoaded());
        $this->assertTrue(current_theme_supports('title-tag'));
        // after_setup_theme maps to ap_after_setup_theme (fired at end of setup).
        // Enqueue callback registered on mapped hook.
        $this->assertNotFalse(has_action('wp_enqueue_scripts'));
    }

    public function testConverterAnalyzesClassicTheme(): void
    {
        $dir = $this->tempThemes . '/report-me';
        $this->assertTrue(mkdir($dir, 0700, true));
        file_put_contents(
            $dir . '/style.css',
            "/*\nTheme Name: Report Me\nAuthor: Tester\nVersion: 1.2.3\n*/\nbody{}\n"
        );
        file_put_contents($dir . '/index.php', "<?php\nget_header();\nwhile (have_posts()) { the_post(); the_title(); }\nget_footer();\n");
        file_put_contents($dir . '/header.php', "<?php\nwp_head();\n");
        file_put_contents($dir . '/footer.php', "<?php\nwp_footer();\n");
        file_put_contents(
            $dir . '/functions.php',
            "<?php\nadd_action('wp_enqueue_scripts', 'report_me_assets');\nfunction report_me_assets() {\n  wp_enqueue_style('report-me', get_stylesheet_uri());\n}\n"
        );

        $report = AP_Theme_Converter::analyzePath($dir);
        $this->assertTrue($report['exists']);
        $this->assertTrue($report['classic']);
        $this->assertFalse($report['block']);
        $this->assertTrue($report['supported']);
        $this->assertSame('Report Me', $report['headers']['Theme Name'] ?? null);
        $this->assertContains('have_posts', $report['shimmed_used']);
        $this->assertContains('wp_enqueue_style', $report['shimmed_used']);
        $this->assertGreaterThan(40, $report['score']);

        $text = AP_Theme_Converter::formatReport($report);
        $this->assertStringContainsString('Classic PHP theme: yes', $text);
        $this->assertStringContainsString('Compatibility score:', $text);
    }

    public function testConverterFlagsBlockTheme(): void
    {
        $dir = $this->tempThemes . '/fse-theme';
        $this->assertTrue(mkdir($dir . '/templates', 0700, true));
        file_put_contents($dir . '/style.css', "/*\nTheme Name: FSE\n*/\n");
        file_put_contents($dir . '/theme.json', "{}\n");
        file_put_contents($dir . '/templates/index.html', "<!-- wp:paragraph -->\n");

        $report = AP_Theme_Converter::analyzePath($dir);
        $this->assertTrue($report['block']);
        $this->assertFalse($report['supported']);
        $this->assertStringContainsString('Block', AP_Theme_Converter::formatReport($report));
    }

    public function testConverterCliExitCodes(): void
    {
        $dir = $this->tempThemes . '/cli-classic';
        $this->assertTrue(mkdir($dir, 0700, true));
        file_put_contents($dir . '/style.css', "/*\nTheme Name: CLI Classic\n*/\n");
        file_put_contents($dir . '/index.php', "<?php\n// index\n");

        ob_start();
        $code = AP_Theme_Converter::runCli(['cli-convert.php', $dir]);
        $out = (string) ob_get_clean();
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Compatibility score:', $out);

        ob_start();
        $codeMissing = AP_Theme_Converter::runCli(['cli-convert.php', $dir . '/nope']);
        ob_end_clean();
        $this->assertSame(2, $codeMissing);

        ob_start();
        $codeHelp = AP_Theme_Converter::runCli(['cli-convert.php', '--help']);
        $help = (string) ob_get_clean();
        $this->assertSame(0, $codeHelp);
        $this->assertStringContainsString('Usage:', $help);
    }

    public function testProceduralHelpers(): void
    {
        $this->assertTrue(function_exists('ap_load_theme_compat'));
        $this->assertTrue(ap_load_theme_compat(true, $this->db));
        $this->assertTrue(function_exists('ap_theme_compat_available'));
        $this->assertTrue(ap_theme_compat_available());

        $dir = $this->tempThemes . '/analyze-me';
        $this->assertTrue(mkdir($dir, 0700, true));
        file_put_contents($dir . '/style.css', "/*\nTheme Name: Analyze Me\n*/\n");
        file_put_contents($dir . '/index.php', "<?php\n");
        $report = ap_analyze_wp_theme($dir);
        $this->assertTrue($report['classic'] ?? false);
    }

    public function testGetStylesheetUriPointsAtStyleCss(): void
    {
        $this->seedClassicTheme('uri-theme', 'URI Theme');
        AP_Theme::setActiveOverride('uri-theme', 'uri-theme');
        AP_Theme_Compat::ensureLoaded(true, $this->db);

        $uri = get_stylesheet_uri();
        $this->assertStringEndsWith('/style.css', $uri);
        $this->assertStringContainsString('uri-theme', $uri);
    }

    public function testPostClassAndLanguageAttributes(): void
    {
        AP_Theme_Compat::ensureLoaded(true, $this->db);
        $postId = AP_Post::insert([
            'post_title' => 'Classy',
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_content' => 'x',
        ], $this->db);
        $post = AP_Post::get($postId, $this->db);
        $GLOBALS['ap_post'] = $post;

        $classes = get_post_class('extra-class');
        $this->assertContains('post', $classes);
        $this->assertContains('post-' . $postId, $classes);
        $this->assertContains('extra-class', $classes);

        ob_start();
        language_attributes();
        $attrs = (string) ob_get_clean();
        $this->assertStringContainsString('lang=', $attrs);
    }

    /**
     * Seed a minimal classic theme under the temp themes root.
     */
    private function seedClassicTheme(string $slug, string $name): void
    {
        $dir = $this->tempThemes . '/' . $slug;
        if (!is_dir($dir)) {
            $this->assertTrue(mkdir($dir, 0700, true));
        }
        file_put_contents(
            $dir . '/style.css',
            "/*\nTheme Name: {$name}\nVersion: 1.0\n*/\nbody { color: #111; }\n"
        );
        file_put_contents(
            $dir . '/index.php',
            "<?php\nif (function_exists('get_header')) { get_header(); }\n"
            . "if (function_exists('have_posts')) { while (have_posts()) { the_post(); the_title(); } }\n"
            . "if (function_exists('get_footer')) { get_footer(); }\n"
        );
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if (!is_array($items)) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
