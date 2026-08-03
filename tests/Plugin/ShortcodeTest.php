<?php

/**
 * Tests for Shortcode API (AP_Shortcode).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Plugin;

use AP_Shortcode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_Shortcode::class)]
final class ShortcodeTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        require_once $this->root . '/ap-includes/class-ap-shortcode.php';
        require_once $this->root . '/ap-includes/functions.php';

        AP_Shortcode::flush();
    }

    protected function tearDown(): void
    {
        AP_Shortcode::flush();
    }

    public function testAddDoAndRemove(): void
    {
        AP_Shortcode::add('hello', static function (array $atts, ?string $content, string $tag): string {
            $name = $atts['name'] ?? 'world';

            return 'Hi ' . $name;
        });

        $this->assertTrue(AP_Shortcode::exists('hello'));
        $this->assertSame('Hi world', AP_Shortcode::doShortcode('[hello]'));
        $this->assertSame('Hi Ada', AP_Shortcode::doShortcode('[hello name="Ada"]'));
        $this->assertSame('Prefix Hi world suffix', AP_Shortcode::doShortcode('Prefix [hello] suffix'));

        AP_Shortcode::remove('hello');
        $this->assertFalse(AP_Shortcode::exists('hello'));
        $this->assertSame('[hello]', AP_Shortcode::doShortcode('[hello]'));
    }

    public function testEnclosureAndNesting(): void
    {
        AP_Shortcode::add('wrap', static function (array $atts, ?string $content): string {
            return '<b>' . (string) $content . '</b>';
        });
        AP_Shortcode::add('em', static function (array $atts, ?string $content): string {
            return '<i>' . (string) $content . '</i>';
        });

        $out = AP_Shortcode::doShortcode('[wrap]plain [em]inner[/em][/wrap]');
        $this->assertSame('<b>plain <i>inner</i></b>', $out);
    }

    public function testEscapedShortcodes(): void
    {
        AP_Shortcode::add('x', static fn (): string => 'EXPANDED');
        $this->assertSame('[x]', AP_Shortcode::doShortcode('[[x]]'));
        $this->assertSame('EXPANDED', AP_Shortcode::doShortcode('[x]'));
    }

    public function testFormatContentEscapesPlainKeepsHtml(): void
    {
        AP_Shortcode::add('badge', static function (): string {
            return '<span class="ok">OK</span>';
        });

        $out = AP_Shortcode::formatContent("Hello <script> & [badge]\nline2");
        $this->assertStringContainsString('<span class="ok">OK</span>', $out);
        $this->assertStringContainsString('&lt;script&gt;', $out);
        $this->assertStringContainsString('<br>', $out);
        $this->assertStringNotContainsString('<script>', $out);
    }

    public function testStripAndHas(): void
    {
        AP_Shortcode::add('a', static fn (): string => 'A');
        AP_Shortcode::add('b', static function (array $atts, ?string $content): string {
            return (string) $content;
        });

        $this->assertTrue(AP_Shortcode::has('xx [a] yy', 'a'));
        $this->assertFalse(AP_Shortcode::has('xx [a] yy', 'b'));
        $this->assertSame('keep me', AP_Shortcode::strip('[b]keep me[/b]'));
        $this->assertSame('leftright', AP_Shortcode::strip('left[a]right'));
        $this->assertStringNotContainsString('[a]', AP_Shortcode::strip('left [a] right'));
    }

    public function testParseAttsAndAttsHelper(): void
    {
        $atts = AP_Shortcode::parseAtts('id="12" class=\'box\' flag bare');
        $this->assertSame('12', $atts['id']);
        $this->assertSame('box', $atts['class']);
        $this->assertContains('flag', $atts);
        $this->assertContains('bare', $atts);

        $merged = AP_Shortcode::atts(
            ['id' => '0', 'class' => 'default'],
            ['id' => '9', 'extra' => 'nope']
        );
        $this->assertSame(['id' => '9', 'class' => 'default'], $merged);
    }

    public function testCoreShortcodesAndProceduralApi(): void
    {
        AP_Shortcode::registerCore();
        $this->assertTrue(ap_shortcode_exists('year'));
        $this->assertSame(date('Y'), ap_do_shortcode('[year]'));

        ap_add_shortcode('twice', static function (array $atts, ?string $content): string {
            return str_repeat((string) $content, 2);
        });
        $this->assertSame('haha', ap_do_shortcode('[twice]ha[/twice]'));
        $this->assertTrue(ap_has_shortcode('[twice]x[/twice]', 'twice'));
        $this->assertSame('x', ap_strip_shortcodes('[twice]x[/twice]'));
        ap_remove_shortcode('twice');
        $this->assertFalse(ap_shortcode_exists('twice'));
    }
}
