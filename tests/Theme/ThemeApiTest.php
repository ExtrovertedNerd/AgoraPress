<?php

/**
 * Tests for the native theme API: style.css, child themes, enqueue.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Theme;

use AP_Assets;
use AP_DB;
use AP_Migrator;
use AP_Post;
use AP_Query;
use AP_Theme;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_Theme::class)]
#[CoversClass(AP_Assets::class)]
final class ThemeApiTest extends TestCase
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
        require_once $this->root . '/ap-includes/class-ap-post.php';
        require_once $this->root . '/ap-includes/class-ap-query.php';
        require_once $this->root . '/ap-includes/class-ap-theme.php';
        require_once $this->root . '/ap-includes/class-ap-assets.php';
        require_once $this->root . '/ap-includes/functions.php';

        if (function_exists('ap_reset_hooks')) {
            ap_reset_hooks();
        }
        AP_Post::resetRegistry();
        AP_Theme::reset();
        AP_Assets::reset();
        unset($GLOBALS['ap_query'], $GLOBALS['ap_post']);

        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');
        (new AP_Migrator($this->db, AP_Migrator::defaultMigrationsPath()))->migrate();
        AP_Post::ensureBuiltins();

        foreach (
            [
                'home' => 'https://example.test',
                'siteurl' => 'https://example.test',
                'blogname' => 'Theme API Site',
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

        $this->tempThemes = sys_get_temp_dir() . '/ap-theme-api-' . uniqid('', true);
        $this->assertTrue(mkdir($this->tempThemes, 0700, true));
        $GLOBALS['apdb'] = $this->db;
    }

    protected function tearDown(): void
    {
        if (function_exists('ap_reset_hooks')) {
            ap_reset_hooks();
        }
        AP_Post::resetRegistry();
        AP_Theme::reset();
        AP_Assets::reset();
        unset($GLOBALS['ap_query'], $GLOBALS['ap_post'], $GLOBALS['apdb']);
        $this->removeDir($this->tempThemes);
    }

    public function testParseStyleCssKnownHeaders(): void
    {
        $path = $this->tempThemes . '/style.css';
        file_put_contents(
            $path,
            "/*\nTheme Name: Sample\nTheme URI: https://example.test/t\n"
            . "Author: Testers\nVersion: 1.2.3\nTemplate: parent-x\n"
            . "Text Domain: sample\nRequires PHP: 8.2\nLicense: GPLv2\n*/\nbody{}\n"
        );
        $headers = AP_Theme::parseStyleCss($path);
        $this->assertSame('Sample', $headers['Theme Name']);
        $this->assertSame('https://example.test/t', $headers['Theme URI']);
        $this->assertSame('Testers', $headers['Author']);
        $this->assertSame('1.2.3', $headers['Version']);
        $this->assertSame('parent-x', $headers['Template']);
        $this->assertSame('sample', $headers['Text Domain']);
        $this->assertSame('8.2', $headers['Requires PHP']);
        $this->assertSame('GPLv2', $headers['License']);
    }

    public function testChildThemeDiscoveryAndActivation(): void
    {
        $parent = $this->tempThemes . '/parent-theme';
        $child = $this->tempThemes . '/child-theme';
        $this->assertTrue(mkdir($parent, 0700, true));
        $this->assertTrue(mkdir($child, 0700, true));

        file_put_contents($parent . '/style.css', "/*\nTheme Name: Parent Theme\nVersion: 1.0\n*/\n");
        file_put_contents($parent . '/index.php', '<?php echo "P";');
        file_put_contents(
            $child . '/style.css',
            "/*\nTheme Name: Child Theme\nTemplate: parent-theme\nVersion: 1.0\n*/\n"
        );
        // Child may omit index.php (inherits parent).
        file_put_contents($parent . '/screenshot.png', 'not-a-real-png');

        AP_Theme::setThemesRootOverride($this->tempThemes);

        $themes = AP_Theme::listThemes();
        $this->assertArrayHasKey('parent-theme', $themes);
        $this->assertArrayHasKey('child-theme', $themes);
        $this->assertSame('0', $themes['parent-theme']['Is Child']);
        $this->assertSame('1', $themes['child-theme']['Is Child']);
        $this->assertSame('parent-theme', $themes['child-theme']['Parent']);
        $this->assertStringContainsString('screenshot.png', $themes['parent-theme']['Screenshot'] ?? '');

        $this->assertTrue(AP_Theme::setActive('child-theme', null, $this->db));
        $this->assertSame('child-theme', AP_Theme::getStylesheet($this->db));
        $this->assertSame('parent-theme', AP_Theme::getTemplate($this->db));
        $this->assertTrue(AP_Theme::isChildTheme($this->db));
        $this->assertTrue(ap_is_child_theme($this->db));
        $this->assertStringEndsWith('/style.css', ap_get_style_css_uri($this->db));
        $this->assertStringContainsString('/child-theme/style.css', ap_get_style_css_uri($this->db));
    }

    public function testInvalidChildRejected(): void
    {
        $orphan = $this->tempThemes . '/orphan-child';
        $this->assertTrue(mkdir($orphan, 0700, true));
        file_put_contents(
            $orphan . '/style.css',
            "/*\nTheme Name: Orphan\nTemplate: missing-parent\n*/\n"
        );

        AP_Theme::setThemesRootOverride($this->tempThemes);
        $this->assertFalse(AP_Theme::isValidTheme('orphan-child'));
        $this->assertArrayNotHasKey('orphan-child', AP_Theme::listThemes());
        $this->assertFalse(AP_Theme::setActive('orphan-child', null, $this->db));
    }

    public function testChildTemplateLocationAndFunctionsOrder(): void
    {
        $parent = $this->tempThemes . '/parent-theme';
        $child = $this->tempThemes . '/child-theme';
        $this->assertTrue(mkdir($parent, 0700, true));
        $this->assertTrue(mkdir($child, 0700, true));

        file_put_contents($parent . '/style.css', "/*\nTheme Name: Parent Theme\n*/\n");
        file_put_contents($child . '/style.css', "/*\nTheme Name: Child Theme\nTemplate: parent-theme\n*/\n");
        file_put_contents($parent . '/index.php', '<?php echo "PARENT_INDEX";');
        file_put_contents($parent . '/header.php', '<?php echo "PARENT_HDR";');
        file_put_contents($child . '/header.php', '<?php echo "CHILD_HDR";');
        file_put_contents(
            $parent . '/functions.php',
            '<?php declare(strict_types=1); function parent_theme_marker(): string { return "parent-fn"; }'
        );
        file_put_contents(
            $child . '/functions.php',
            '<?php declare(strict_types=1); function child_theme_marker(): string { return "child-fn"; }'
        );

        AP_Theme::setThemesRootOverride($this->tempThemes);
        AP_Theme::setActiveOverride('child-theme', 'parent-theme');
        AP_Theme::setup($this->db);

        $this->assertTrue(function_exists('parent_theme_marker'));
        $this->assertTrue(function_exists('child_theme_marker'));
        $this->assertSame('parent-fn', parent_theme_marker());
        $this->assertSame('child-fn', child_theme_marker());

        $this->assertSame(
            $child . '/header.php',
            AP_Theme::locateTemplate(['header.php'], false, true, [], $this->db)
        );
        $this->assertSame(
            $parent . '/index.php',
            AP_Theme::locateTemplate(['index.php'], false, true, [], $this->db)
        );
    }

    public function testEnqueueStyleDependencyOrderAndVersion(): void
    {
        AP_Assets::reset();
        $this->assertTrue(ap_register_style('base', 'https://example.test/base.css', [], '1.0'));
        $this->assertTrue(ap_enqueue_style('child', 'https://example.test/child.css', ['base'], '2.0'));
        // base not enqueued directly — pulled in as dependency.
        $this->assertTrue(ap_style_is('child', 'enqueued'));
        $this->assertTrue(ap_style_is('base', 'registered'));

        ob_start();
        ap_print_styles();
        $html = (string) ob_get_clean();

        $posBase = strpos($html, 'base.css');
        $posChild = strpos($html, 'child.css');
        $this->assertNotFalse($posBase);
        $this->assertNotFalse($posChild);
        $this->assertLessThan($posChild, $posBase);
        $this->assertStringContainsString('ver=1.0', $html);
        $this->assertStringContainsString('ver=2.0', $html);
        $this->assertTrue(ap_style_is('base', 'done'));
        $this->assertTrue(ap_style_is('child', 'done'));

        // Idempotent second print.
        ob_start();
        ap_print_styles();
        $this->assertSame('', (string) ob_get_clean());
    }

    public function testEnqueueScriptHeadVsFooterAndInline(): void
    {
        AP_Assets::reset();
        ap_enqueue_script('head-js', 'https://example.test/head.js', [], '1', false);
        ap_enqueue_script('foot-js', 'https://example.test/foot.js', ['head-js'], '1', true);
        ap_add_inline_script('foot-js', 'window.AP=1;', 'after');
        ap_add_inline_style('missing', 'body{}'); // not registered
        ap_register_style('theme', 'https://example.test/t.css');
        ap_enqueue_style('theme');
        ap_add_inline_style('theme', 'body{color:red}');

        ob_start();
        ap_print_styles();
        ap_print_scripts(false);
        $head = (string) ob_get_clean();
        $this->assertStringContainsString('head.js', $head);
        $this->assertStringNotContainsString('foot.js', $head);
        $this->assertStringContainsString('body{color:red}', $head);

        ob_start();
        ap_print_scripts(true);
        $foot = (string) ob_get_clean();
        $this->assertStringContainsString('foot.js', $foot);
        $this->assertStringContainsString('window.AP=1;', $foot);
        // Dependency already printed in head is not re-printed.
        $this->assertStringNotContainsString('head.js', $foot);
    }

    public function testApHeadFiresEnqueueAndPrints(): void
    {
        AP_Assets::reset();
        if (function_exists('ap_reset_hooks')) {
            ap_reset_hooks();
        }

        $ran = false;
        ap_add_action('ap_enqueue_scripts', static function () use (&$ran): void {
            $ran = true;
            ap_enqueue_style('from-hook', 'https://example.test/hook.css', [], false);
        });
        ap_add_action('ap_head', static function (): void {
            echo "<!--head-hook-->\n";
        });

        ob_start();
        ap_head();
        $html = (string) ob_get_clean();

        $this->assertTrue($ran);
        $this->assertStringContainsString('hook.css', $html);
        $this->assertStringContainsString('<!--head-hook-->', $html);
        $this->assertSame(1, ap_did_action('ap_enqueue_scripts'));
    }

    public function testAgoraRendersViaEnqueue(): void
    {
        if (function_exists('ap_reset_hooks')) {
            ap_reset_hooks();
        }
        AP_Assets::reset();
        AP_Theme::setThemesRootOverride($this->root . '/ap-content/themes');
        AP_Theme::setActiveOverride('agora', 'agora');

        AP_Post::insert([
            'post_title' => 'Enqueue Post',
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_content' => 'body text',
        ], $this->db);

        $query = new AP_Query(['post_type' => 'post', 'posts_per_page' => 5], $this->db);
        ob_start();
        AP_Theme::render($query, $this->db);
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('Enqueue Post', $html);
        $this->assertStringContainsString('style.css', $html);
        $this->assertStringContainsString('agora-style-css', $html);
        $this->assertTrue(ap_style_is('agora-style', 'done'));
    }

    public function testChildThemeEnqueuesParentThenChildStyle(): void
    {
        $parent = $this->tempThemes . '/parent-theme';
        $child = $this->tempThemes . '/child-theme';
        $this->assertTrue(mkdir($parent, 0700, true));
        $this->assertTrue(mkdir($child, 0700, true));

        file_put_contents(
            $parent . '/style.css',
            "/*\nTheme Name: Parent Theme\nVersion: 1.0\n*/\nbody{color:#111;}\n"
        );
        file_put_contents(
            $child . '/style.css',
            "/*\nTheme Name: Child Theme\nTemplate: parent-theme\nVersion: 1.0\n*/\n"
            . "body{color:#222;}\n"
        );
        file_put_contents(
            $parent . '/index.php',
            '<?php if (function_exists("ap_get_header")) { ap_get_header(); }'
            . ' else { echo "<!DOCTYPE html><html><head>";'
            . ' if (function_exists("ap_head")) { ap_head(); }'
            . ' echo "</head><body>"; } echo "CONTENT";'
            . ' if (function_exists("ap_get_footer")) { ap_get_footer(); }'
            . ' else { if (function_exists("ap_footer")) { ap_footer(); }'
            . ' echo "</body></html>"; }'
        );
        file_put_contents(
            $parent . '/header.php',
            '<?php echo "<!DOCTYPE html><html><head>";'
            . ' if (function_exists("ap_head")) { ap_head(); }'
            . ' echo "</head><body>";'
        );
        file_put_contents(
            $parent . '/footer.php',
            '<?php if (function_exists("ap_footer")) { ap_footer(); } echo "</body></html>";'
        );
        file_put_contents(
            $parent . '/functions.php',
            "<?php\ndeclare(strict_types=1);\n"
            . "function parent_theme_register_theme_hooks(): void {\n"
            . "  if (function_exists('ap_add_action')) {\n"
            . "    ap_add_action('ap_enqueue_scripts', 'parent_theme_enqueue_assets');\n"
            . "  }\n"
            . "}\n"
            . "function parent_theme_enqueue_assets(): void {\n"
            . "  if (!function_exists('ap_enqueue_style')) { return; }\n"
            . "  \$parent = function_exists('ap_get_template_uri') ? ap_get_template_uri() . '/style.css' : '';\n"
            . "  \$child = function_exists('ap_get_style_css_uri') ? ap_get_style_css_uri() : '';\n"
            . "  if (function_exists('ap_is_child_theme') && ap_is_child_theme()) {\n"
            . "    ap_enqueue_style('parent-style', \$parent, [], '1.0');\n"
            . "    ap_enqueue_style('child-style', \$child, ['parent-style'], '1.0');\n"
            . "  } else {\n"
            . "    ap_enqueue_style('parent-style', \$parent !== '' ? \$parent : \$child, [], '1.0');\n"
            . "  }\n"
            . "}\n"
            . "parent_theme_register_theme_hooks();\n"
        );

        if (function_exists('ap_reset_hooks')) {
            ap_reset_hooks();
        }
        AP_Assets::reset();
        AP_Theme::reset();
        AP_Theme::setThemesRootOverride($this->tempThemes);
        AP_Theme::setActiveOverride('child-theme', 'parent-theme');

        $query = new AP_Query(['post_type' => 'post', 'posts_per_page' => 1], $this->db);
        ob_start();
        AP_Theme::render($query, $this->db);
        $html = (string) ob_get_clean();

        $this->assertTrue(AP_Theme::isChildTheme($this->db));
        $posParent = strpos($html, 'parent-style-css');
        $posChild = strpos($html, 'child-style-css');
        $this->assertNotFalse($posParent, $html);
        $this->assertNotFalse($posChild, $html);
        $this->assertLessThan($posChild, $posParent);
        $this->assertStringContainsString('/parent-theme/style.css', $html);
        $this->assertStringContainsString('/child-theme/style.css', $html);
        $this->assertStringContainsString('CONTENT', $html);
    }

    public function testHooksPriorityAndFilter(): void
    {
        if (function_exists('ap_reset_hooks')) {
            ap_reset_hooks();
        }
        $order = [];
        ap_add_action('t', static function () use (&$order): void {
            $order[] = 'b';
        }, 20);
        ap_add_action('t', static function () use (&$order): void {
            $order[] = 'a';
        }, 5);
        ap_do_action('t');
        $this->assertSame(['a', 'b'], $order);

        ap_add_filter('f', static function (string $v): string {
            return $v . '-x';
        });
        ap_add_filter('f', static function (string $v): string {
            return $v . '-y';
        }, 11);
        $this->assertSame('v-x-y', ap_apply_filters('f', 'v'));
        $this->assertNotFalse(ap_has_filter('f'));
        $this->assertSame(2, ap_has_filter('f'));
        $this->assertNotFalse(ap_has_action('t'));
    }

    /**
     * @param non-empty-string $dir
     */
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
}
