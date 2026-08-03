<?php

/**
 * Tests for the lightweight classic editor (AP_Editor).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Editor;

use AP_Editor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_Editor::class)]
final class EditorTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        require_once $this->root . '/ap-includes/version.php';
        require_once $this->root . '/ap-includes/class-ap-formatting.php';
        require_once $this->root . '/ap-includes/class-ap-editor.php';
        require_once $this->root . '/ap-includes/functions.php';
        AP_Editor::reset();
    }

    protected function tearDown(): void
    {
        AP_Editor::reset();
    }

    public function testAssetFilesExist(): void
    {
        $this->assertFileIsReadable($this->root . '/ap-includes/class-ap-editor.php');
        $this->assertFileIsReadable($this->root . '/ap-includes/css/ap-editor.css');
        $this->assertFileIsReadable($this->root . '/ap-includes/js/ap-editor.js');
    }

    public function testIsClassicLightweightNotBlockEditor(): void
    {
        $this->assertSame(AP_Editor::ARCHITECTURE_CLASSIC, AP_Editor::architecture());
        $this->assertSame('classic', AP_Editor::architecture());
        $this->assertFalse(AP_Editor::isBlockEditor());
        $this->assertTrue(AP_Editor::isLightweight());
        // Modes never include a blocks / gutenberg surface.
        $modes = AP_Editor::modes();
        $this->assertSame(['markdown', 'bbcode', 'html'], $modes);
        $this->assertNotContains('blocks', $modes);
        $this->assertNotContains('gutenberg', $modes);
        $this->assertNotContains('block', $modes);
    }

    public function testNoBlockEditorPackagesInCore(): void
    {
        $this->assertDirectoryDoesNotExist($this->root . '/ap-includes/blocks');
        $this->assertDirectoryDoesNotExist($this->root . '/ap-includes/gutenberg');
        $this->assertDirectoryDoesNotExist($this->root . '/ap-includes/block-editor');
        $this->assertFileDoesNotExist($this->root . '/ap-includes/class-ap-block-editor.php');
        $this->assertFileDoesNotExist($this->root . '/ap-includes/js/block-editor.js');
        $this->assertFileDoesNotExist($this->root . '/ap-includes/js/blocks.js');
        $this->assertFileDoesNotExist($this->root . '/ap-admin/js/gutenberg.js');
    }

    public function testAssetBudgetsStayLightweight(): void
    {
        $jsPath = $this->root . '/ap-includes/js/ap-editor.js';
        $cssPath = $this->root . '/ap-includes/css/ap-editor.css';
        $jsSize = (int) filesize($jsPath);
        $cssSize = (int) filesize($cssPath);
        $this->assertGreaterThan(500, $jsSize, 'JS should not be empty');
        $this->assertGreaterThan(200, $cssSize, 'CSS should not be empty');
        $this->assertLessThanOrEqual(
            AP_Editor::MAX_JS_BYTES,
            $jsSize,
            'ap-editor.js exceeds lightweight budget of ' . AP_Editor::MAX_JS_BYTES . ' bytes'
        );
        $this->assertLessThanOrEqual(
            AP_Editor::MAX_CSS_BYTES,
            $cssSize,
            'ap-editor.css exceeds lightweight budget of ' . AP_Editor::MAX_CSS_BYTES . ' bytes'
        );
    }

    public function testModesAndNormalize(): void
    {
        $this->assertSame(['markdown', 'bbcode', 'html'], AP_Editor::modes());
        $this->assertSame('markdown', AP_Editor::normalizeMode('MARKDOWN'));
        $this->assertSame('bbcode', AP_Editor::normalizeMode('bbcode'));
        $this->assertSame('html', AP_Editor::normalizeMode('html'));
        $this->assertSame('markdown', AP_Editor::normalizeMode('unknown'));
        // Explicit block-ish strings must not become a block mode.
        $this->assertSame('markdown', AP_Editor::normalizeMode('blocks'));
        $this->assertSame('markdown', AP_Editor::normalizeMode('gutenberg'));
    }

    public function testModeForContext(): void
    {
        $this->assertSame('markdown', AP_Editor::modeForContext('post'));
        $this->assertSame('markdown', AP_Editor::modeForContext('page'));
        $this->assertSame('markdown', AP_Editor::modeForContext('comment'));
        $this->assertSame('bbcode', AP_Editor::modeForContext('forum'));
        $this->assertSame('bbcode', AP_Editor::modeForContext('topic'));
        $this->assertSame('bbcode', AP_Editor::modeForContext('reply'));
    }

    public function testMarkdownButtonsIncludeCoreFormatting(): void
    {
        $ids = array_column(AP_Editor::buttons('markdown'), 'id');
        foreach (['bold', 'italic', 'link', 'h2', 'h3', 'quote', 'ul', 'ol', 'code', 'hr', 'emoji'] as $need) {
            $this->assertContains($need, $ids, "Missing markdown button {$need}");
        }
    }

    public function testBbcodeButtonsIncludeCoreFormatting(): void
    {
        $ids = array_column(AP_Editor::buttons('bbcode'), 'id');
        foreach (['bold', 'italic', 'underline', 'link', 'quote', 'ul', 'ol', 'code', 'emoji'] as $need) {
            $this->assertContains($need, $ids, "Missing bbcode button {$need}");
        }
    }

    public function testHtmlButtonsIncludeCoreFormatting(): void
    {
        $ids = array_column(AP_Editor::buttons('html'), 'id');
        foreach (['bold', 'italic', 'link', 'h2', 'quote', 'ul', 'ol', 'emoji'] as $need) {
            $this->assertContains($need, $ids, "Missing html button {$need}");
        }
    }

    public function testEmojiCatalogIsGroupedAndNonEmpty(): void
    {
        $groups = AP_Editor::emojis();
        $this->assertNotEmpty($groups);
        $this->assertArrayHasKey('Smileys', $groups);
        $total = 0;
        foreach ($groups as $label => $chars) {
            $this->assertIsString($label);
            $this->assertIsArray($chars);
            $this->assertNotEmpty($chars, "Category {$label} should not be empty");
            foreach ($chars as $char) {
                $this->assertIsString($char);
                $this->assertNotSame('', $char);
                $total++;
            }
        }
        $this->assertGreaterThanOrEqual(50, $total);
        $this->assertLessThanOrEqual(500, $total);
    }

    public function testRenderMarkdownEditorMarkup(): void
    {
        $html = AP_Editor::render([
            'id' => 'content',
            'name' => 'post_content',
            'value' => 'Hello **world**',
            'mode' => 'markdown',
            'rows' => 10,
            'label' => 'Content',
        ]);

        $this->assertStringContainsString('data-ap-editor-wrap', $html);
        $this->assertStringContainsString('data-ap-editor-architecture="classic"', $html);
        $this->assertStringContainsString('ap-editor__toolbar', $html);
        $this->assertStringContainsString('data-ap-editor-mode="markdown"', $html);
        $this->assertStringContainsString('name="post_content"', $html);
        $this->assertStringContainsString('id="content"', $html);
        $this->assertStringContainsString('Hello **world**', $html);
        // Plain textarea only — never contenteditable / block canvas.
        $this->assertMatchesRegularExpression('/<textarea\b[^>]*\bdata-ap-editor="1"/', $html);
        $this->assertStringNotContainsString('contenteditable', strtolower($html));
        $this->assertStringNotContainsString('data-ap-block', $html);
        $this->assertStringNotContainsString('wp-block', $html);
        $this->assertStringContainsString('data-ap-editor-cmd="wrap"', $html);
        $this->assertStringContainsString('data-ap-editor-before="**"', $html);
        $this->assertStringContainsString('data-ap-editor-cmd="md-link"', $html);
        $this->assertStringContainsString('data-ap-editor-cmd="prefix-line"', $html);
        $this->assertStringContainsString('data-ap-editor-cmd="emoji-picker"', $html);
        $this->assertStringContainsString('data-ap-editor-emoji-picker', $html);
        $this->assertStringContainsString('data-ap-emoji="1"', $html);
        $this->assertStringContainsString('aria-haspopup="dialog"', $html);
        // Assets printed once with the control.
        $this->assertStringContainsString('ap-editor.css', $html);
        $this->assertStringContainsString('ap-editor.js', $html);
        $this->assertTrue(AP_Editor::assetsWereEnqueued());
    }

    public function testRenderBbcodeEditorMarkup(): void
    {
        $html = AP_Editor::render([
            'id' => 'reply',
            'name' => 'reply_body',
            'mode' => 'bbcode',
            'required' => true,
        ]);

        $this->assertStringContainsString('data-ap-editor-mode="bbcode"', $html);
        $this->assertStringContainsString('data-ap-editor-before="[b]"', $html);
        $this->assertStringContainsString('data-ap-editor-cmd="bbcode-list"', $html);
        $this->assertStringContainsString('data-ap-editor-cmd="emoji-picker"', $html);
        $this->assertStringContainsString('data-ap-editor-emoji-picker', $html);
        $this->assertStringContainsString('required', $html);
        $this->assertStringContainsString('BBCode', $html);
    }

    public function testRenderEmojiPickerStandalone(): void
    {
        $html = AP_Editor::renderEmojiPicker('content');
        $this->assertStringContainsString('id="ap-editor-emoji-content"', $html);
        $this->assertStringContainsString('data-ap-editor-for="content"', $html);
        $this->assertStringContainsString('role="dialog"', $html);
        $this->assertStringContainsString('hidden', $html);
        $this->assertStringContainsString('data-ap-editor-cmd="insert"', $html);
        $this->assertStringContainsString('data-ap-editor-emoji-close', $html);
        // At least one known emoji from the Smileys group.
        $this->assertStringContainsString('😀', $html);
    }

    public function testRenderIdempotentAssets(): void
    {
        $a = AP_Editor::render(['id' => 'a', 'name' => 'a']);
        $b = AP_Editor::render(['id' => 'b', 'name' => 'b']);
        $this->assertStringContainsString('ap-editor.css', $a);
        // Second render must not re-print link/script tags.
        $this->assertStringNotContainsString('ap-editor.css', $b);
        $this->assertStringContainsString('id="b"', $b);
    }

    public function testProceduralHelpers(): void
    {
        $this->assertTrue(function_exists('ap_editor'));
        $this->assertTrue(function_exists('ap_the_editor'));
        $this->assertTrue(function_exists('ap_enqueue_editor'));
        $this->assertTrue(function_exists('ap_print_editor_assets'));

        $html = ap_editor([
            'id' => 'x',
            'name' => 'x',
            'mode' => 'markdown',
        ]);
        $this->assertStringContainsString('ap-editor__toolbar', $html);
    }

    public function testJsIsVanillaNoJquery(): void
    {
        $js = (string) file_get_contents($this->root . '/ap-includes/js/ap-editor.js');
        // No jQuery library usage (ignore the word in comments).
        $this->assertDoesNotMatchRegularExpression('/\$\s*\(|jQuery\s*\(/', $js);
        $this->assertStringNotContainsString('jquery.min', $js);
        $this->assertStringContainsString('data-ap-editor-cmd', $js);
        $this->assertStringContainsString('window.AP_Editor', $js);
        $this->assertStringContainsString('emoji-picker', $js);
        $this->assertStringContainsString('bindEmojiPicker', $js);
        $this->assertStringContainsString('closeAllPickers', $js);
        $this->assertStringContainsString('Escape', $js);
        // Operate on textarea value only — no rich contenteditable / block model.
        $this->assertStringContainsString('TEXTAREA', $js);
        $lower = strtolower($js);
        $this->assertStringNotContainsString('contenteditable', $lower);
        $this->assertStringNotContainsString('document.execcommand', $lower);
        $this->assertStringNotContainsString('prosemirror', $lower);
        $this->assertStringNotContainsString('tinymce', $lower);
        $this->assertStringNotContainsString('quill', $lower);
        $this->assertStringNotContainsString('gutenberg', $lower);
        $this->assertStringNotContainsString('wp.blocks', $lower);
        $this->assertStringNotContainsString('createblock', $lower);
    }

    public function testCssHasToolbarRules(): void
    {
        $css = (string) file_get_contents($this->root . '/ap-includes/css/ap-editor.css');
        $this->assertStringContainsString('.ap-editor__toolbar', $css);
        $this->assertStringContainsString('.ap-editor__btn', $css);
        $this->assertStringContainsString('.ap-editor__emoji-picker', $css);
        $this->assertStringContainsString('.ap-editor__emoji-btn', $css);
        $this->assertStringContainsString('prefers-reduced-motion', $css);
    }
}
