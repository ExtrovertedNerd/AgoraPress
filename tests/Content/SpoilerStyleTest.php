<?php

/**
 * Core spoiler CSS contract: closed body unreadable, open follows color-scheme,
 * native keyboard, no images.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Content;

use AP_Assets;
use AP_Content_Format;
use AP_Editor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_Content_Format::class)]
final class SpoilerStyleTest extends TestCase
{
    private string $root;

    private string $cssPath;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        $this->cssPath = $this->root . '/ap-includes/css/ap-spoiler.css';
        require_once $this->root . '/ap-includes/version.php';
        require_once $this->root . '/ap-includes/hooks.php';
        require_once $this->root . '/ap-includes/class-ap-assets.php';
        require_once $this->root . '/ap-includes/class-ap-content-format.php';
        require_once $this->root . '/ap-includes/class-ap-editor.php';
        require_once $this->root . '/ap-includes/functions.php';
        AP_Content_Format::resetAssets();
        AP_Editor::reset();
        AP_Assets::reset();
        if (function_exists('ap_reset_hooks')) {
            ap_reset_hooks();
        }
    }

    protected function tearDown(): void
    {
        AP_Content_Format::resetAssets();
        AP_Editor::reset();
        AP_Assets::reset();
        if (function_exists('ap_reset_hooks')) {
            ap_reset_hooks();
        }
    }

    public function testSpoilerStylesheetExists(): void
    {
        $this->assertFileIsReadable($this->cssPath);
    }

    public function testClosedBodyIsUnreadable(): void
    {
        $css = $this->spoilerCss();
        $closed = $this->firstCssBlock($css, '.ap-spoiler:not([open]) > .ap-spoiler__body');
        $this->assertMatchesRegularExpression('/display:\s*none/i', $closed);
        $this->assertStringContainsString('!important', $closed);

        $closedPseudo = $this->firstCssBlock($css, '.ap-spoiler:not([open])::details-content');
        $this->assertMatchesRegularExpression('/display:\s*none/i', $closedPseudo);
        $this->assertStringContainsString('!important', $closedPseudo);

        // Reveal is native <details open>, not hover.
        $this->assertDoesNotMatchRegularExpression(
            '/\.ap-spoiler[^{]*:hover[^{]*\.ap-spoiler__body/',
            $css
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\.ap-spoiler__body[^{]*:hover/',
            $css
        );
    }

    public function testOpenBodyFollowsColorScheme(): void
    {
        $css = $this->spoilerCss();
        $wrap = $this->firstCssBlock($css, '.ap-spoiler');
        $this->assertStringContainsString('color-scheme: inherit', $wrap);
        $this->assertStringContainsString('color: inherit', $wrap);
        $this->assertStringContainsString('background: transparent', $wrap);

        $open = $this->firstCssBlock($css, '.ap-spoiler[open] > .ap-spoiler__body');
        $this->assertStringContainsString('color-scheme: inherit', $open);
        $this->assertStringContainsString('color: inherit', $open);
        $this->assertStringContainsString('background: transparent', $open);

        $openPseudo = $this->firstCssBlock($css, '.ap-spoiler[open]::details-content');
        $this->assertStringContainsString('color-scheme: inherit', $openPseudo);
        $this->assertStringContainsString('color: inherit', $openPseudo);
        $this->assertStringContainsString('background: transparent', $openPseudo);

        $this->assertMatchesRegularExpression(
            '/html\s+\.ap-spoiler\s*,[\s\S]*?body\s+\.ap-spoiler\s*\{[^}]*color-scheme:\s*inherit/',
            $css
        );
        // Must not force dark on every document — light pages stay inherit.
        $this->assertDoesNotMatchRegularExpression(
            '/html\s+\.ap-spoiler\s*,[\s\S]*?body\s+\.ap-spoiler\s*\{[^}]*color-scheme:\s*dark/',
            $css
        );
        $this->assertMatchesRegularExpression(
            '/html\.agora-mode-dark\s+\.ap-spoiler\s*,[\s\S]*?'
            . 'body\.agora-mode-dark\s+\.ap-spoiler\s*,[\s\S]*?'
            . 'html\[data-ap-color-mode="dark"\]\s+\.ap-spoiler\s*,[\s\S]*?'
            . '\{[^}]*color-scheme:\s*dark/',
            $css
        );
        // Page color-scheme, not the OS preference.
        $this->assertStringNotContainsString('prefers-color-scheme', $css);
        $this->assertStringNotContainsString('color-scheme: light dark', $css);
    }

    public function testSummaryKeepsNativeKeyboardAffordance(): void
    {
        $css = $this->spoilerCss();
        $summary = $this->firstCssBlock($css, '.ap-spoiler__summary');
        $this->assertStringContainsString('display: list-item', $summary);
        $this->assertStringContainsString('list-style-type: disclosure-closed', $summary);
        $this->assertStringContainsString('cursor: pointer', $summary);
        $openSummary = $this->firstCssBlock($css, '.ap-spoiler[open] > .ap-spoiler__summary');
        $this->assertStringContainsString('list-style-type: disclosure-open', $openSummary);
        $this->assertStringNotContainsString('display: none', $summary);
        $this->assertStringNotContainsString('pointer-events: none', $summary);

        $focus = $this->firstCssBlock($css, '.ap-spoiler__summary:focus-visible');
        $this->assertMatchesRegularExpression('/outline:\s*2px\s+solid\s+currentColor/', $focus);

        $this->assertStringNotContainsString('tabindex', $css);
        $this->assertDoesNotMatchRegularExpression(
            '/\.ap-spoiler__summary[^{]*\{[^}]*outline:\s*none/',
            $css
        );
        $this->assertDoesNotMatchRegularExpression('/list-style(?:-type)?:\s*none/', $css);
        $this->assertDoesNotMatchRegularExpression(
            '/::(?:-webkit-details-)?marker[^{]*\{[^}]*display:\s*none/',
            $css
        );
        $this->assertFileDoesNotExist($this->root . '/ap-includes/js/ap-spoiler.js');
    }

    public function testStylesheetHasNoImages(): void
    {
        $css = preg_replace('/\/\*.*?\*\//s', '', $this->spoilerCss()) ?? '';
        $this->assertDoesNotMatchRegularExpression('/url\s*\(/i', $css);
        $this->assertStringNotContainsString('background-image', $css);
        $this->assertDoesNotMatchRegularExpression('/data:image/i', $css);
        $this->assertDoesNotMatchRegularExpression('/\.(png|gif|jpe?g|svg|webp)\b/i', $css);
        $this->assertStringNotContainsString('list-style-image', $css);
    }

    public function testRegisterAssetsEnqueuesOnApHead(): void
    {
        AP_Content_Format::registerAssets();
        $priority = ap_has_action('ap_enqueue_scripts', [AP_Content_Format::class, 'enqueueAssets']);
        $this->assertSame(20, $priority, 'Must print after default theme enqueue (priority 10)');

        ob_start();
        ap_head();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('ap-spoiler.css', $html);
        $this->assertStringContainsString('id="ap-spoiler-css"', $html);
        $this->assertTrue(AP_Content_Format::styleWasEnqueued());
        $this->assertTrue(ap_style_is(AP_Content_Format::STYLE_HANDLE, 'done'));
    }

    public function testPrintStyleIsIdempotent(): void
    {
        ob_start();
        AP_Content_Format::printStyle();
        $first = (string) ob_get_clean();
        $this->assertStringContainsString('ap-spoiler.css', $first);
        $this->assertStringContainsString('id="ap-spoiler-css"', $first);

        ob_start();
        AP_Content_Format::printStyle();
        $second = (string) ob_get_clean();
        $this->assertSame('', $second);
    }

    public function testPrintStyleNoopsAfterHeadEnqueue(): void
    {
        AP_Content_Format::registerAssets();
        ob_start();
        ap_head();
        $head = (string) ob_get_clean();
        $this->assertStringContainsString('ap-spoiler.css', $head);

        ob_start();
        AP_Content_Format::printStyle();
        $again = (string) ob_get_clean();
        $this->assertSame('', $again);
    }

    public function testEditorPrintsSpoilerStylesheet(): void
    {
        ob_start();
        AP_Editor::printAssets();
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('ap-spoiler.css', $html);
        $this->assertStringContainsString('ap-editor.css', $html);
        $this->assertTrue(AP_Content_Format::styleWasEnqueued());
    }

    public function testDarkFixtureUsesCoreCssWithoutAgora(): void
    {
        $html = $this->fixtureHtml('spoiler-dark-no-agora.html');
        $this->assertMatchesRegularExpression(
            '/html,\s*body\s*\{[^}]*color-scheme:\s*dark/',
            $html
        );
        $this->assertStringContainsString('color: #e8eaed', $html);
        $this->assertStringContainsString('details > * { display: block; }', $html);
        $this->assertStringContainsString('summary { display: block; outline: none; }', $html);
        $this->assertStringContainsString('ap-spoiler.css', $html);
        $this->assertSame(1, substr_count($html, 'rel="stylesheet"'));
        $this->assertStringContainsString('data-ap-spoiler-fixture="closed-dark"', $html);
        $this->assertStringContainsString('data-ap-spoiler-fixture="open-dark"', $html);
        $this->assertStringContainsString('class="ap-spoiler__summary"', $html);
        $this->assertStringContainsString('secret plot', $html);
        $this->assertStringContainsString('revealed plot', $html);
        $this->assertStringNotContainsString('agora/style.css', $html);
        $this->assertDoesNotMatchRegularExpression(
            '/<(?:html|body)\b[^>]*agora-mode-dark/',
            $html
        );
        $this->assertDoesNotMatchRegularExpression('/--ap-(?:surface|text|fg|bg)\s*:/', $html);
        $this->assertDoesNotMatchRegularExpression('/<script\b/i', $html);
    }

    public function testLightFixtureUsesCoreCssWithoutAgora(): void
    {
        $html = $this->fixtureHtml('spoiler-light-no-agora.html');
        $this->assertMatchesRegularExpression(
            '/html,\s*body\s*\{[^}]*color-scheme:\s*light/',
            $html
        );
        $this->assertStringContainsString('color: #1c1f26', $html);
        $this->assertStringContainsString('ap-spoiler.css', $html);
        $this->assertSame(1, substr_count($html, 'rel="stylesheet"'));
        $this->assertStringContainsString('data-ap-spoiler-fixture="closed-light"', $html);
        $this->assertStringContainsString('data-ap-spoiler-fixture="open-light"', $html);
        $this->assertStringNotContainsString('agora/style.css', $html);
        $this->assertDoesNotMatchRegularExpression('/<script\b/i', $html);
        $this->assertDoesNotMatchRegularExpression(
            '/html\s+\.ap-spoiler\s*,[\s\S]*?body\s+\.ap-spoiler\s*\{[^}]*color-scheme:\s*dark/',
            $this->spoilerCss()
        );
    }

    private function spoilerCss(): string
    {
        return (string) file_get_contents($this->cssPath);
    }

    private function fixtureHtml(string $name): string
    {
        $path = $this->root . '/tests/Content/fixtures/' . $name;
        $this->assertFileIsReadable($path);

        return (string) file_get_contents($path);
    }

    private function firstCssBlock(string $css, string $selector): string
    {
        $quoted = preg_quote($selector, '/');
        if (!preg_match('/^' . $quoted . '\s*\{([^{}]+)\}/m', $css, $match)) {
            $this->fail('Missing CSS block for ' . $selector);
        }

        return $match[1];
    }
}
