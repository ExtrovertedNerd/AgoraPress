<?php

/**
 * Unit tests for AP_Assets — enqueue, dependency order, inline assets.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Assets;

use AP_Assets;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_Assets::class)]
final class AssetsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        require_once $this->root . '/ap-includes/class-ap-assets.php';
        require_once $this->root . '/ap-includes/functions.php';
        AP_Assets::reset();
    }

    protected function tearDown(): void
    {
        AP_Assets::reset();
    }

    public function testStyleDependencyOrder(): void
    {
        $this->assertTrue(ap_register_style('base', 'https://example.test/base.css', [], '1'));
        $this->assertTrue(ap_register_style('theme', 'https://example.test/theme.css', ['base'], '1'));
        $this->assertTrue(ap_enqueue_style('theme'));

        ob_start();
        ap_print_styles();
        $html = (string) ob_get_clean();

        $basePos = strpos($html, 'base.css');
        $themePos = strpos($html, 'theme.css');
        $this->assertNotFalse($basePos);
        $this->assertNotFalse($themePos);
        $this->assertLessThan($themePos, $basePos, 'Dependencies must print before dependents');
    }

    public function testScriptDependencyOrderAndFooterSplit(): void
    {
        // Footer script depending on a head-only lib: deps print with the
        // dependent group when the head group has nothing enqueued alone.
        ap_register_script('lib', 'https://example.test/lib.js', [], '1', false);
        ap_register_script('app', 'https://example.test/app.js', ['lib'], '1', true);
        ap_enqueue_script('app');

        ob_start();
        ap_print_scripts(false);
        $head = (string) ob_get_clean();

        ob_start();
        ap_print_scripts(true);
        $footer = (string) ob_get_clean();

        // No head-only enqueues → head print is empty.
        $this->assertSame('', $head);
        // Footer print pulls in head deps first, then the footer script.
        $libPos = strpos($footer, 'lib.js');
        $appPos = strpos($footer, 'app.js');
        $this->assertNotFalse($libPos);
        $this->assertNotFalse($appPos);
        $this->assertLessThan($appPos, $libPos);
    }

    public function testCircularStyleDependenciesDoNotFatal(): void
    {
        ap_register_style('a', 'https://example.test/a.css', ['b'], '1');
        ap_register_style('b', 'https://example.test/b.css', ['a'], '1');
        ap_enqueue_style('a');

        ob_start();
        ap_print_styles();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('a.css', $html);
        $this->assertStringContainsString('b.css', $html);
    }

    public function testInlineStyleAndScript(): void
    {
        ap_register_style('site', 'https://example.test/site.css', [], '1');
        ap_enqueue_style('site');
        $this->assertTrue(ap_add_inline_style('site', '.x{color:red}'));
        $this->assertFalse(ap_add_inline_style('missing', '.y{}'));

        ob_start();
        ap_print_styles();
        $styles = (string) ob_get_clean();
        $this->assertStringContainsString('site.css', $styles);
        $this->assertStringContainsString('.x{color:red}', $styles);

        ap_register_script('boot', 'https://example.test/boot.js', [], '1', false);
        ap_enqueue_script('boot');
        $this->assertTrue(ap_add_inline_script('boot', 'window.AP=1;', 'before'));
        $this->assertTrue(ap_add_inline_script('boot', 'window.AP.ready=1;', 'after'));

        ob_start();
        ap_print_scripts(false);
        $scripts = (string) ob_get_clean();
        $this->assertStringContainsString('window.AP=1;', $scripts);
        $this->assertStringContainsString('boot.js', $scripts);
        $this->assertStringContainsString('window.AP.ready=1;', $scripts);
    }

    public function testDequeueAndDeregister(): void
    {
        ap_enqueue_style('gone', 'https://example.test/gone.css', [], '1');
        ap_dequeue_style('gone');

        ob_start();
        ap_print_styles();
        $html = (string) ob_get_clean();
        $this->assertStringNotContainsString('gone.css', $html);

        ap_register_script('tmp', 'https://example.test/tmp.js', [], '1');
        ap_enqueue_script('tmp');
        ap_deregister_script('tmp');
        $this->assertFalse(ap_enqueue_script('tmp'));
    }

    public function testRejectsEmptyAndInvalidHandles(): void
    {
        $this->assertFalse(ap_register_style('', 'https://example.test/x.css'));
        $this->assertFalse(ap_enqueue_style('not-registered'));
        $this->assertFalse(ap_register_script('!!!', 'https://example.test/x.js'));
    }

    public function testPrintStylesIsIdempotent(): void
    {
        ap_enqueue_style('once', 'https://example.test/once.css', [], '1');

        ob_start();
        ap_print_styles();
        $first = (string) ob_get_clean();
        $this->assertStringContainsString('once.css', $first);

        ob_start();
        ap_print_styles();
        $second = (string) ob_get_clean();
        $this->assertSame('', $second);
    }
}
