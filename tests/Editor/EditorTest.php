<?php

/**
 * Tests for the lightweight visual WYSIWYG editor (AP_Editor).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Editor;

use AP_Content_Format;
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
        require_once $this->root . '/ap-includes/class-ap-content-format.php';
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

    public function testIsClassicLightweightVisualNotBlockEditor(): void
    {
        $this->assertSame(AP_Editor::ARCHITECTURE_CLASSIC, AP_Editor::architecture());
        $this->assertSame('classic', AP_Editor::architecture());
        $this->assertFalse(AP_Editor::isBlockEditor());
        $this->assertTrue(AP_Editor::isLightweight());
        $this->assertTrue(AP_Editor::isVisual());
        // Modes never include a blocks / gutenberg surface.
        $modes = AP_Editor::modes();
        $this->assertContains('visual', $modes);
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
        $this->assertSame(['visual', 'markdown', 'bbcode', 'html'], AP_Editor::modes());
        $this->assertSame('visual', AP_Editor::normalizeMode('VISUAL'));
        $this->assertSame('visual', AP_Editor::normalizeMode('wysiwyg'));
        $this->assertSame('markdown', AP_Editor::normalizeMode('MARKDOWN'));
        $this->assertSame('bbcode', AP_Editor::normalizeMode('bbcode'));
        $this->assertSame('html', AP_Editor::normalizeMode('html'));
        $this->assertSame('visual', AP_Editor::normalizeMode('unknown'));
        // Explicit block-ish strings must not become a block mode.
        $this->assertSame('visual', AP_Editor::normalizeMode('blocks'));
        $this->assertSame('visual', AP_Editor::normalizeMode('gutenberg'));
    }

    public function testModeForContextIsVisualEverywhere(): void
    {
        $this->assertSame('visual', AP_Editor::modeForContext('post'));
        $this->assertSame('visual', AP_Editor::modeForContext('page'));
        $this->assertSame('visual', AP_Editor::modeForContext('comment'));
        $this->assertSame('visual', AP_Editor::modeForContext('forum'));
        $this->assertSame('visual', AP_Editor::modeForContext('topic'));
        $this->assertSame('visual', AP_Editor::modeForContext('reply'));
    }

    public function testVisualButtonsIncludeCoreFormatting(): void
    {
        $ids = array_column(AP_Editor::buttons('visual'), 'id');
        foreach (['bold', 'italic', 'underline', 'link', 'h2', 'h3', 'quote', 'ul', 'ol', 'code', 'hr', 'emoji'] as $need) {
            $this->assertContains($need, $ids, "Missing visual button {$need}");
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

    public function testValueToHtmlFormatsMarkdownAndBbcode(): void
    {
        $md = AP_Editor::valueToHtml('Hello **world**', 'markdown');
        $this->assertStringContainsString('<strong>world</strong>', $md);
        $this->assertStringNotContainsString('**world**', $md);

        $bb = AP_Editor::valueToHtml('Hello [b]world[/b]', 'bbcode');
        $this->assertStringContainsString('<strong>world</strong>', $bb);
        $this->assertStringNotContainsString('[b]', $bb);

        $html = AP_Editor::valueToHtml('<p>Hello <em>world</em></p>', 'visual');
        $this->assertStringContainsString('<em>world</em>', $html);
    }

    public function testRenderVisualEditorMarkup(): void
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
        $this->assertStringContainsString('data-ap-editor-mode="visual"', $html);
        $this->assertStringContainsString('name="post_content"', $html);
        $this->assertStringContainsString('id="content"', $html);
        // Surface shows formatted output, not raw markdown characters.
        $this->assertStringContainsString('data-ap-editor-surface', $html);
        $this->assertStringContainsString('<strong>world</strong>', $html);
        $this->assertStringNotContainsString('**world**', $html);
        // Textarea remains for form submit / no-JS.
        $this->assertMatchesRegularExpression('/<textarea\b[^>]*\bdata-ap-editor="1"/', $html);
        $this->assertStringNotContainsString('data-ap-block', $html);
        $this->assertStringNotContainsString('wp-block', $html);
        $this->assertStringContainsString('data-ap-editor-cmd="visual"', $html);
        $this->assertStringContainsString('data-ap-editor-visual="bold"', $html);
        $this->assertStringContainsString('data-ap-editor-cmd="visual-link"', $html);
        $this->assertStringContainsString('data-ap-editor-cmd="emoji-picker"', $html);
        $this->assertStringContainsString('data-ap-editor-emoji-picker', $html);
        $this->assertStringContainsString('data-ap-emoji="1"', $html);
        $this->assertStringContainsString('aria-haspopup="dialog"', $html);
        // Assets printed once with the control.
        $this->assertStringContainsString('ap-editor.css', $html);
        $this->assertStringContainsString('ap-editor.js', $html);
        $this->assertTrue(AP_Editor::assetsWereEnqueued());
    }

    public function testRenderBbcodeLegacyConvertsOnSurface(): void
    {
        $html = AP_Editor::render([
            'id' => 'reply',
            'name' => 'reply_body',
            'value' => '[b]Bold[/b]',
            'mode' => 'bbcode',
            'required' => true,
        ]);

        $this->assertStringContainsString('data-ap-editor-mode="visual"', $html);
        $this->assertStringContainsString('<strong>Bold</strong>', $html);
        $this->assertStringNotContainsString('[b]Bold[/b]', $html);
        $this->assertStringContainsString('required', $html);
        $this->assertStringContainsString('Visual', $html);
        $this->assertStringContainsString('data-ap-editor-mode-switch', $html);
        $this->assertStringContainsString('data-ap-editor-set-mode="visual"', $html);
        $this->assertStringContainsString('data-ap-editor-set-mode="html"', $html);
        $this->assertStringContainsString('Text', $html);
    }

    public function testRenderHasVisualTextModeSwitcher(): void
    {
        $html = AP_Editor::render([
            'id' => 'post_content',
            'name' => 'post_content',
            'value' => '<p>Hello</p>',
            'mode' => 'visual',
        ]);
        $this->assertStringContainsString('data-ap-editor-mode-switch', $html);
        $this->assertStringContainsString('data-ap-editor-set-mode="html"', $html);
        $this->assertStringContainsString('aria-label="Editor mode"', $html);
        // Text mode label for raw HTML / source editing.
        $this->assertMatchesRegularExpression(
            '/data-ap-editor-set-mode="html"[^>]*>\s*Text\s*</',
            $html
        );
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
            'mode' => 'visual',
        ]);
        $this->assertStringContainsString('ap-editor__toolbar', $html);
        $this->assertStringContainsString('data-ap-editor-surface', $html);
    }

    public function testJsIsVanillaVisualNoHeavyRuntimes(): void
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
        // Visual WYSIWYG surface.
        $this->assertStringContainsString('contenteditable', strtolower($js));
        $this->assertStringContainsString('data-ap-editor-surface', $js);
        $this->assertStringContainsString('execCommand', $js);
        // Visual | Text (HTML source) mode switcher.
        $this->assertStringContainsString('setMode', $js);
        $this->assertStringContainsString('data-ap-editor-set-mode', $js);
        $this->assertStringContainsString('ap-editor--html-active', $js);
        // No heavy third-party / block stacks.
        $lower = strtolower($js);
        $this->assertStringNotContainsString('prosemirror', $lower);
        $this->assertStringNotContainsString('tinymce', $lower);
        $this->assertStringNotContainsString('quill', $lower);
        $this->assertStringNotContainsString('gutenberg', $lower);
        $this->assertStringNotContainsString('wp.blocks', $lower);
        $this->assertStringNotContainsString('createblock', $lower);
        $this->assertStringNotContainsString('lexical', $lower);
    }

    public function testCssHasToolbarAndSurfaceRules(): void
    {
        $css = (string) file_get_contents($this->root . '/ap-includes/css/ap-editor.css');
        $this->assertStringContainsString('.ap-editor__toolbar', $css);
        $this->assertStringContainsString('.ap-editor__btn', $css);
        $this->assertStringContainsString('.ap-editor__surface', $css);
        $this->assertStringContainsString('.ap-editor__emoji-picker', $css);
        $this->assertStringContainsString('.ap-editor__emoji-btn', $css);
        $this->assertStringContainsString('prefers-reduced-motion', $css);
        $this->assertStringContainsString('.ap-editor__mode-switch', $css);
        $this->assertStringContainsString('.ap-editor--html-active', $css);
    }

    public function testCssInheritsColorSchemeAndPublishesEditorTokens(): void
    {
        $css = $this->editorCss();
        $wrap = $this->firstCssBlock($css, '.ap-editor');
        $this->assertStringContainsString('color-scheme: inherit', $wrap);
        $this->assertStringContainsString('--ap-editor-bg', $wrap);
        $this->assertStringContainsString('--ap-editor-fg', $wrap);
        $this->assertStringContainsString('--ap-editor-border', $wrap);
        $this->assertStringContainsString('--ap-editor-surface', $wrap);
        $this->assertStringContainsString('Canvas', $wrap);
        $this->assertStringContainsString('CanvasText', $wrap);
        $this->assertStringContainsString('Field', $wrap);
        $this->assertStringContainsString('FieldText', $wrap);

        $header = substr($css, 0, (int) strpos($css, '.ap-editor {'));
        $this->assertStringContainsString('--ap-editor-bg', $header);
        $this->assertStringContainsString('--ap-editor-fg', $header);
        $this->assertStringContainsString('--ap-editor-border', $header);
        $this->assertStringContainsString('--ap-editor-surface', $header);
        $this->assertStringContainsString('--ap-on-accent', $header);
    }

    public function testCssPairsToolbarSurfaceAndButtons(): void
    {
        $css = $this->editorCss();
        $this->assertLessThanOrEqual(
            AP_Editor::MAX_CSS_BYTES,
            (int) filesize($this->root . '/ap-includes/css/ap-editor.css'),
            'pairing fallbacks must stay inside AP_Editor::MAX_CSS_BYTES'
        );

        $wrap = $this->firstCssBlock($css, '.ap-editor');
        $toolbar = $this->firstCssBlock($css, '.ap-editor__toolbar');
        $surface = $this->firstCssBlock($css, '.ap-editor__surface');
        $textarea = $this->firstCssBlock($css, '.ap-editor__textarea');
        $modeSwitch = $this->firstCssBlock($css, '.ap-editor__mode-switch');
        $picker = $this->firstCssBlock($css, '.ap-editor__emoji-picker');
        $btn = $this->firstCssBlock($css, '.ap-editor__btn');
        $modeBtn = $this->firstCssBlock($css, '.ap-editor__mode-btn');
        $emojiBtn = $this->firstCssBlock($css, '.ap-editor__emoji-btn');
        $emojiClose = $this->firstCssBlock($css, '.ap-editor__emoji-close');

        $this->assertMatchesRegularExpression(
            '/--ap-editor-chrome-bg:\s*var\(--ap-editor-bg,.*Canvas\)/',
            $wrap
        );
        $this->assertMatchesRegularExpression(
            '/--ap-editor-chrome-fg:\s*var\(--ap-editor-fg,.*CanvasText\)/',
            $wrap
        );
        $this->assertMatchesRegularExpression(
            '/--ap-editor-field-bg:\s*var\(--ap-editor-surface,.*Field\)/',
            $wrap
        );
        $this->assertMatchesRegularExpression(
            '/--ap-editor-field-fg:\s*var\(--ap-editor-fg,.*FieldText\)/',
            $wrap
        );
        $this->assertMatchesRegularExpression(
            '/--ap-editor-line:\s*var\(--ap-editor-border,.*currentColor/',
            $wrap
        );

        $this->assertStringContainsString('background: var(--ap-editor-chrome-bg, Canvas)', $toolbar);
        $this->assertStringContainsString('color: var(--ap-editor-chrome-fg, CanvasText)', $toolbar);
        $this->assertStringContainsString('background: var(--ap-editor-field-bg, Field)', $surface);
        $this->assertStringContainsString('color: var(--ap-editor-field-fg, FieldText)', $surface);
        $this->assertStringContainsString('background: var(--ap-editor-field-bg, Field)', $textarea);
        $this->assertStringContainsString('color: var(--ap-editor-field-fg, FieldText)', $textarea);
        $this->assertStringContainsString('background: var(--ap-editor-field-bg, Field)', $modeSwitch);
        $this->assertStringContainsString('color: var(--ap-editor-field-fg, FieldText)', $modeSwitch);
        $this->assertStringContainsString('background: var(--ap-editor-field-bg, Field)', $picker);
        $this->assertStringContainsString('color: var(--ap-editor-field-fg, FieldText)', $picker);

        foreach ([$btn, $modeBtn, $emojiBtn, $emojiClose] as $control) {
            $this->assertStringContainsString('background: transparent', $control);
            $this->assertStringContainsString('color: currentColor', $control);
        }

        $active = $this->firstCssBlock($css, '.ap-editor__mode-btn.is-active');
        $this->assertStringContainsString('background: var(--ap-primary, #1a5fb4)', $active);
        $this->assertStringContainsString('color: var(--ap-on-accent, #fff)', $active);
    }

    public function testCssDarkChromeDoesNotDependOnlyOnAgoraOrApTokens(): void
    {
        $css = $this->editorCss();
        $wrap = $this->firstCssBlock($css, '.ap-editor');
        $toolbar = $this->firstCssBlock($css, '.ap-editor__toolbar');
        $surface = $this->firstCssBlock($css, '.ap-editor__surface');

        $this->assertStringContainsString('color-scheme: inherit', $wrap);
        $this->assertDoesNotMatchRegularExpression('/#[0-9a-fA-F]{3,8}/', $wrap);
        $this->assertMatchesRegularExpression(
            '/--ap-editor-chrome-bg:\s*var\([^;]*Canvas\)/',
            $wrap
        );
        $this->assertMatchesRegularExpression(
            '/--ap-editor-chrome-fg:\s*var\([^;]*CanvasText\)/',
            $wrap
        );
        $this->assertMatchesRegularExpression(
            '/--ap-editor-field-bg:\s*var\([^;]*Field\)/',
            $wrap
        );
        $this->assertMatchesRegularExpression(
            '/--ap-editor-field-fg:\s*var\([^;]*FieldText\)/',
            $wrap
        );

        $this->assertStringContainsString('background: var(--ap-editor-chrome-bg, Canvas)', $toolbar);
        $this->assertStringContainsString('color: var(--ap-editor-chrome-fg, CanvasText)', $toolbar);
        $this->assertStringContainsString('color-scheme: inherit', $toolbar);
        $this->assertStringNotContainsString('#eef0f3', $toolbar);
        $this->assertStringNotContainsString('#fff', $toolbar);
        $this->assertStringNotContainsString('#1a1a1a', $toolbar);

        $this->assertStringContainsString('background: var(--ap-editor-field-bg, Field)', $surface);
        $this->assertStringContainsString('color: var(--ap-editor-field-fg, FieldText)', $surface);
        $this->assertStringContainsString('color-scheme: inherit', $surface);

        // Inherit + system colors live on .ap-editor itself. Agora dark is an
        // extra host lock, not the only path to dark chrome.
        $stripped = (string) preg_replace(
            '/body\.agora-mode-dark[\s\S]*?\{[\s\S]*?\}/',
            '',
            $css
        );
        $strippedWrap = $this->firstCssBlock($stripped, '.ap-editor');
        $this->assertStringContainsString('color-scheme: inherit', $strippedWrap);
        $this->assertStringContainsString('Canvas', $strippedWrap);
        $this->assertStringContainsString('CanvasText', $strippedWrap);
        $this->assertStringContainsString('Field', $strippedWrap);
        $this->assertStringContainsString('FieldText', $strippedWrap);
        $this->assertStringContainsString(
            'background: var(--ap-editor-chrome-bg, Canvas)',
            $this->firstCssBlock($stripped, '.ap-editor__toolbar')
        );
        $this->assertDoesNotMatchRegularExpression(
            '/body\.agora-mode-dark[^{]*\.ap-editor__(?:toolbar|btn|surface)[^{]*\{/',
            $css
        );
    }

    public function testCssIsolatesButtonsFromThemeInherit(): void
    {
        $css = $this->editorCss();
        $btn = $this->firstCssBlock($css, '.ap-editor__btn');
        $this->assertStringContainsString('color: currentColor', $btn);
        $this->assertStringContainsString('background: transparent', $btn);

        $this->assertStringContainsString(
            '.ap-editor button.ap-editor__btn',
            $css
        );
        $this->assertStringContainsString(
            '.ap-editor button.ap-editor__mode-btn',
            $css
        );
        $this->assertStringContainsString(
            '.ap-editor button.ap-editor__emoji-btn',
            $css
        );
        $this->assertStringContainsString(
            '.ap-editor button.ap-editor__emoji-close',
            $css
        );
        $this->assertStringContainsString('.ap-editor .ap-editor__toolbar', $css);

        // Isolation must not use inherit/currentColor — those re-open page text.
        $this->assertMatchesRegularExpression(
            '/\.ap-editor button\.ap-editor__btn\s*\{[^}]*color:\s*var\(--ap-editor-chrome-fg/',
            $css
        );
        $this->assertMatchesRegularExpression(
            '/\.ap-editor button\.ap-editor__mode-btn\s*,[\s\S]*?\{\s*color:\s*var\(--ap-editor-field-fg/',
            $css
        );
        $isolate = $this->ruleContaining($css, '.ap-editor button.ap-editor__btn');
        $this->assertStringContainsString('--ap-editor-chrome-fg', $isolate);
        $this->assertStringContainsString('CanvasText', $isolate);
        $this->assertStringNotContainsString('currentColor', $isolate);
        $this->assertStringNotContainsString('inherit', $isolate);
        $fieldIsolate = $this->ruleContaining($css, '.ap-editor button.ap-editor__mode-btn');
        $this->assertStringContainsString('--ap-editor-field-fg', $fieldIsolate);
        $this->assertStringContainsString('FieldText', $fieldIsolate);
        $this->assertStringNotContainsString('currentColor', $fieldIsolate);
        $this->assertStringNotContainsString('inherit', $fieldIsolate);

        // Isolation (0,2,1) would override .ap-editor__mode-btn.is-active (0,2,0).
        // Agora dark schemes remap --ap-primary to a light accent; --ap-on-accent
        // keeps the chip readable, with #fff for themes that do not set it.
        $this->assertMatchesRegularExpression(
            '/\.ap-editor button\.ap-editor__mode-btn\.is-active\s*\{[^}]*'
            . 'color:\s*var\(--ap-on-accent,\s*#fff\)/',
            $css
        );
    }

    public function testInheritBleachFixtureLocksChromeForeground(): void
    {
        $path = $this->root . '/tests/Editor/fixtures/editor-button-inherit-light-toolbar.html';
        $this->assertFileIsReadable($path);
        $html = (string) file_get_contents($path);

        $this->assertStringContainsString('button { color: inherit; }', $html);
        $this->assertStringContainsString('.ap-editor__toolbar { color: inherit; }', $html);
        $this->assertStringContainsString('color: #e8eaed', $html);
        $this->assertStringContainsString('ap-editor.css', $html);
        $this->assertStringContainsString('ap-editor__toolbar', $html);
        $this->assertStringContainsString('ap-editor__btn', $html);
        $this->assertStringNotContainsString('color-scheme: dark', $html);
        $this->assertStringNotContainsString('agora/style.css', $html);
        $this->assertDoesNotMatchRegularExpression('/--ap-(?:surface|text|fg|bg)\s*:/', $html);

        $css = $this->editorCss();
        $isolate = $this->ruleContaining($css, '.ap-editor button.ap-editor__btn');
        $toolbarLock = $this->firstCssBlock($css, '.ap-editor .ap-editor__toolbar');
        $toolbar = $this->firstCssBlock($css, '.ap-editor__toolbar');
        $this->assertStringContainsString('--ap-editor-chrome-fg', $isolate);
        $this->assertStringNotContainsString('inherit', $isolate);
        $this->assertStringContainsString('--ap-editor-chrome-fg', $toolbarLock);
        $this->assertStringNotContainsString('inherit', $toolbarLock);
        $this->assertStringContainsString('background: var(--ap-editor-chrome-bg, Canvas)', $toolbar);
        $this->assertStringContainsString('color: var(--ap-editor-chrome-fg, CanvasText)', $toolbar);
    }

    public function testCssHonorsHtmlBodyColorSchemeAndKnownDarkHosts(): void
    {
        $css = $this->editorCss();
        $wrap = $this->firstCssBlock($css, '.ap-editor');
        $toolbar = $this->firstCssBlock($css, '.ap-editor__toolbar');

        // html/body { color-scheme: dark } reaches chrome via inherit.
        $this->assertStringContainsString('color-scheme: inherit', $wrap);
        $this->assertStringContainsString('color-scheme: inherit', $toolbar);
        $this->assertStringContainsString('html .ap-editor', $css);
        $this->assertStringContainsString('body .ap-editor', $css);
        $this->assertMatchesRegularExpression(
            '/html\s+\.ap-editor\s*,[\s\S]*?body\s+\.ap-editor\s*\{[^}]*color-scheme:\s*inherit/',
            $css
        );
        // Must not force dark on every document — light pages stay inherit.
        $this->assertDoesNotMatchRegularExpression(
            '/html\s+\.ap-editor\s*,[\s\S]*?body\s+\.ap-editor\s*\{[^}]*color-scheme:\s*dark/',
            $css
        );

        // Agora dark mode (class on body; html listed for the same lock).
        $this->assertStringContainsString('html.agora-mode-dark .ap-editor', $css);
        $this->assertStringContainsString('body.agora-mode-dark .ap-editor', $css);

        // ACP already sets data-ap-color-mode on <html>; honor body / any ancestor too.
        $this->assertStringContainsString('html[data-ap-color-mode="dark"] .ap-editor', $css);
        $this->assertStringContainsString('body[data-ap-color-mode="dark"] .ap-editor', $css);
        $this->assertStringContainsString('[data-ap-color-mode="dark"] .ap-editor', $css);

        $this->assertMatchesRegularExpression(
            '/html\.agora-mode-dark\s+\.ap-editor\s*,[\s\S]*?'
            . 'body\.agora-mode-dark\s+\.ap-editor\s*,[\s\S]*?'
            . 'html\[data-ap-color-mode="dark"\]\s+\.ap-editor\s*,[\s\S]*?'
            . '\{[^}]*color-scheme:\s*dark/',
            $css
        );

        $adminCss = (string) file_get_contents($this->root . '/ap-admin/css/admin.css');
        $this->assertStringContainsString('html[data-ap-color-mode="dark"]', $adminCss);
        $this->assertMatchesRegularExpression(
            '/html\[data-ap-color-mode="dark"\]\s*\{[\s\S]*?color-scheme:\s*dark/',
            $adminCss
        );
    }

    /**
     * Fixture: color-scheme:dark + light text, no Agora stylesheet.
     * Computed tokens are Canvas / CanvasText (readable toolbar pair).
     *
     * Follow-on: named Addons skins that still force a light toolbar hex
     * (or omit color-scheme) need theme CSS. Do not add site-specific
     * rules to ap-editor.css.
     */
    public function testContrastFixtureWithoutAgoraStylesheet(): void
    {
        $path = $this->root . '/tests/Editor/fixtures/editor-contrast-dark.html';
        $this->assertFileIsReadable($path);
        $html = (string) file_get_contents($path);

        $this->assertMatchesRegularExpression(
            '/html,\s*body\s*\{[^}]*color-scheme:\s*dark/',
            $html
        );
        $this->assertMatchesRegularExpression('/html,\s*body\s*\{[^}]*background:\s*#12141a/', $html);
        $this->assertStringContainsString('color-scheme: dark', $html);
        $this->assertStringContainsString('color: #e8eaed', $html);
        $this->assertStringContainsString('button { color: inherit; }', $html);
        $this->assertStringContainsString('ap-editor.css', $html);
        $this->assertSame(1, substr_count($html, 'rel="stylesheet"'));
        $this->assertStringContainsString('ap-editor__toolbar', $html);
        $this->assertStringContainsString('ap-editor__btn', $html);
        $this->assertStringContainsString('ap-editor__surface', $html);
        $this->assertStringContainsString('data-ap-editor-contrast-fixture="dark-no-agora"', $html);
        $this->assertStringNotContainsString('agora/style.css', $html);
        $this->assertStringNotContainsString('themes/agora', $html);
        $this->assertStringNotContainsString('agora-mode-dark', $html);
        $this->assertDoesNotMatchRegularExpression('/--ap-(?:surface|text|fg|bg)\s*:/', $html);
        $this->assertDoesNotMatchRegularExpression('/--ap-editor-(?:bg|fg|surface|border)\s*:/', $html);

        // Page itself is dark + light text (WCAG AA). The old light toolbar
        // hex against that page color is the unreadable failure mode.
        $this->assertGreaterThan(0.6, $this->relativeLuminance('#e8eaed'));
        $this->assertLessThan(0.25, $this->relativeLuminance('#12141a'));
        $this->assertGreaterThanOrEqual(
            4.5,
            $this->contrastRatio('#e8eaed', '#12141a'),
            'fixture page text vs page background must meet WCAG AA'
        );
        $this->assertLessThan(
            4.5,
            $this->contrastRatio('#e8eaed', '#eef0f3'),
            'legacy light toolbar #eef0f3 vs page text is the bleach failure'
        );

        $css = $this->editorCss();
        foreach (['Jarvis', 'BlindVault', 'MensBS', 'AgoraPress_Addons'] as $skin) {
            $this->assertStringNotContainsString($skin, $css);
            $this->assertStringNotContainsString($skin, $html);
        }

        $wrap = $this->firstCssBlock($css, '.ap-editor');
        $toolbar = $this->firstCssBlock($css, '.ap-editor__toolbar');
        $btn = $this->firstCssBlock($css, '.ap-editor__btn');
        $isolate = $this->ruleContaining($css, '.ap-editor button.ap-editor__btn');
        $tokens = $this->cssCustomProperties($wrap);

        $this->assertStringContainsString('color-scheme: inherit', $wrap);
        $this->assertArrayHasKey('--ap-editor-chrome-bg', $tokens);
        $this->assertArrayHasKey('--ap-editor-chrome-fg', $tokens);

        // No Agora / --ap-* on the fixture → last-resort system pair.
        $chromeBg = $this->resolveCssVar($tokens['--ap-editor-chrome-bg'], $tokens);
        $chromeFg = $this->resolveCssVar($tokens['--ap-editor-chrome-fg'], $tokens);
        $this->assertSame('Canvas', $chromeBg);
        $this->assertSame('CanvasText', $chromeFg);

        $this->assertStringContainsString('background: var(--ap-editor-chrome-bg, Canvas)', $toolbar);
        $this->assertStringContainsString('color: var(--ap-editor-chrome-fg, CanvasText)', $toolbar);
        $this->assertStringContainsString('color-scheme: inherit', $toolbar);
        $this->assertStringNotContainsString('#eef0f3', $toolbar);
        $this->assertStringContainsString('color: currentColor', $btn);

        $toolbarBg = $this->resolveCssVar(
            $this->cssDeclaration($toolbar, 'background'),
            $tokens
        );
        $toolbarFg = $this->resolveCssVar(
            $this->cssDeclaration($toolbar, 'color'),
            $tokens
        );
        $buttonFg = $this->resolveCssVar(
            $this->cssDeclaration($isolate, 'color'),
            $tokens
        );
        $this->assertSame('Canvas', $toolbarBg);
        $this->assertSame('CanvasText', $toolbarFg);
        $this->assertSame(
            'CanvasText',
            $buttonFg,
            'toolbar labels must use CanvasText, not inherited page color'
        );
        $this->assertSame(
            $toolbarFg,
            $buttonFg,
            'button color vs toolbar background is the Canvas/CanvasText pair'
        );
        $this->assertNotSame(
            $toolbarBg,
            $buttonFg,
            'toolbar background and button color must be a contrasting pair'
        );
    }

    public function testContentFormatAvailableForDisplay(): void
    {
        $this->assertTrue(class_exists(AP_Content_Format::class, false));
        $out = AP_Content_Format::format('**bold** and [b]bb[/b]');
        $this->assertStringContainsString('<strong>bold</strong>', $out);
        $this->assertStringContainsString('<strong>bb</strong>', $out);
    }

    private function editorCss(): string
    {
        return (string) file_get_contents($this->root . '/ap-includes/css/ap-editor.css');
    }

    private function firstCssBlock(string $css, string $selector): string
    {
        $quoted = preg_quote($selector, '/');
        if (!preg_match('/^' . $quoted . '\s*\{([^{}]+)\}/m', $css, $match)) {
            $this->fail('Missing CSS block for ' . $selector);
        }

        return $match[1];
    }

    /**
     * Declarations of the rule that includes $selector (comma groups allowed).
     */
    private function ruleContaining(string $css, string $selector): string
    {
        $quoted = preg_quote($selector, '/');
        if (!preg_match('/(?<![a-zA-Z0-9_-])' . $quoted . '(?![a-zA-Z0-9_-])[^{]*\{([^{}]+)\}/', $css, $match)) {
            $this->fail('Missing CSS rule containing ' . $selector);
        }

        return $match[1];
    }

    /**
     * Custom properties declared in a single CSS block.
     *
     * @return array<string, string>
     */
    private function cssCustomProperties(string $block): array
    {
        $out = [];
        if (
            preg_match_all(
                '/(--[A-Za-z0-9-]+)\s*:\s*([^;]+);/',
                $block,
                $matches,
                PREG_SET_ORDER
            ) === false
        ) {
            return $out;
        }
        foreach ($matches as $match) {
            $out[$match[1]] = trim($match[2]);
        }

        return $out;
    }

    private function cssDeclaration(string $block, string $property): string
    {
        $quoted = preg_quote($property, '/');
        if (!preg_match('/(?:^|;)\s*' . $quoted . '\s*:\s*([^;]+);/', ';' . $block, $match)) {
            $this->fail('Missing CSS declaration ' . $property);
        }

        return trim($match[1]);
    }

    /**
     * Walk var(--name, fallback) until a concrete value. Unset names use the
     * fallback (the dark fixture defines no --ap-* tokens).
     *
     * @param array<string, string> $defined
     * @param array<string, true>   $visiting
     */
    private function resolveCssVar(string $value, array $defined, array $visiting = []): string
    {
        $value = trim(rtrim(trim($value), ';'));
        if (
            !preg_match(
                '/^var\(\s*(--[A-Za-z0-9-]+)\s*(?:,\s*(.*))?\)$/s',
                $value,
                $match
            )
        ) {
            return $value;
        }

        $name = $match[1];
        $fallback = isset($match[2]) ? trim($match[2]) : '';
        if (isset($visiting[$name])) {
            return $fallback !== ''
                ? $this->resolveCssVar($fallback, $defined, $visiting)
                : $name;
        }
        $visiting[$name] = true;
        if (array_key_exists($name, $defined)) {
            return $this->resolveCssVar($defined[$name], $defined, $visiting);
        }
        if ($fallback !== '') {
            return $this->resolveCssVar($fallback, $defined, $visiting);
        }

        return $name;
    }

    private function relativeLuminance(string $hex): float
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        $channels = [
            hexdec(substr($hex, 0, 2)) / 255.0,
            hexdec(substr($hex, 2, 2)) / 255.0,
            hexdec(substr($hex, 4, 2)) / 255.0,
        ];
        foreach ($channels as $i => $channel) {
            $channels[$i] = $channel <= 0.04045
                ? $channel / 12.92
                : (($channel + 0.055) / 1.055) ** 2.4;
        }

        return (0.2126 * $channels[0]) + (0.7152 * $channels[1]) + (0.0722 * $channels[2]);
    }

    private function contrastRatio(string $a, string $b): float
    {
        $l1 = $this->relativeLuminance($a);
        $l2 = $this->relativeLuminance($b);
        $hi = max($l1, $l2);
        $lo = min($l1, $l2);

        return ($hi + 0.05) / ($lo + 0.05);
    }
}
