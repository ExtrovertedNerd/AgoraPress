<?php

/**
 * Lightweight visual WYSIWYG editor.
 *
 * Shows formatted content while editing (contenteditable surface) and submits
 * sanitized HTML via a hidden textarea. Progressive enhancement: without JS
 * the plain textarea remains usable. Shared by Post/Page admin, comments, and
 * forum topic/reply forms. Includes a built-in Unicode emoji picker (no CDN).
 *
 * Not a block / Gutenberg editor. Soft asset budgets (guarded by tests):
 * JS ≤ 48 KiB, CSS ≤ 24 KiB.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * Classic visual WYSIWYG editor (toolbar + contenteditable + textarea).
 *
 * Architecture is intentionally fixed to {@see AP_Editor::ARCHITECTURE_CLASSIC}.
 * Full block / Gutenberg editors are a non-goal for core (see FEATURES.md).
 */
class AP_Editor
{
    /** Visual / WYSIWYG mode — stores HTML, edits with formatted preview. */
    public const MODE_VISUAL = 'visual';

    /** @deprecated Prefer visual; kept for legacy content conversion. */
    public const MODE_MARKDOWN = 'markdown';

    /** @deprecated Prefer visual; kept for legacy content conversion. */
    public const MODE_BBCODE = 'bbcode';

    /**
     * Text / HTML source mode — raw HTML in the textarea (no contenteditable surface).
     * Useful for long crypto addresses, embeds, and fine-grained markup.
     */
    public const MODE_HTML = 'html';

    /** Stable architecture id: classic visual editor (never "blocks"). */
    public const ARCHITECTURE_CLASSIC = 'classic';

    public const HANDLE_STYLE = 'ap-editor';

    public const HANDLE_SCRIPT = 'ap-editor';

    /** Soft upper bound for ap-editor.js (bytes). Guard tests enforce this. */
    public const MAX_JS_BYTES = 49152;

    /** Soft upper bound for ap-editor.css (bytes). Guard tests enforce this. */
    public const MAX_CSS_BYTES = 24576;

    /** @var bool Whether assets were printed this request (admin print path). */
    private static bool $assetsPrinted = false;

    /** @var bool Whether assets were enqueued via AP_Assets this request. */
    private static bool $assetsEnqueued = false;

    /**
     * Editor architecture id. Always classic in core — block mode is unsupported.
     */
    public static function architecture(): string
    {
        return self::ARCHITECTURE_CLASSIC;
    }

    /**
     * Whether this is a block / Gutenberg-style editor.
     *
     * Always false in core. Plugins must not rebrand {@see AP_Editor} as a
     * block editor; ship a separate package if they need blocks.
     */
    public static function isBlockEditor(): bool
    {
        return false;
    }

    /**
     * Whether the core editor follows the lightweight classic design.
     */
    public static function isLightweight(): bool
    {
        return !self::isBlockEditor()
            && self::architecture() === self::ARCHITECTURE_CLASSIC;
    }

    /**
     * Whether the editor is the visual WYSIWYG surface (not a block canvas).
     */
    public static function isVisual(): bool
    {
        return true;
    }

    /**
     * Supported editor modes (never includes a "blocks" mode).
     *
     * Visual is the default WYSIWYG surface. Markdown / BBCode / HTML remain
     * for legacy content conversion and filters.
     *
     * @return list<string>
     */
    public static function modes(): array
    {
        return [
            self::MODE_VISUAL,
            self::MODE_MARKDOWN,
            self::MODE_BBCODE,
            self::MODE_HTML,
        ];
    }

    public static function normalizeMode(string $mode): string
    {
        $mode = strtolower(trim($mode));
        // Map legacy aliases to visual WYSIWYG.
        if (in_array($mode, ['wysiwyg', 'rich', 'richtext', 'rte'], true)) {
            return self::MODE_VISUAL;
        }

        return in_array($mode, self::modes(), true) ? $mode : self::MODE_VISUAL;
    }

    /**
     * Formatting button definitions for the visual toolbar.
     *
     * Each button: id, label, title, and visual command instructions consumed
     * by ap-editor.js (document.execCommand / selection helpers).
     *
     * @return list<array<string, mixed>>
     */
    public static function buttons(string $mode = self::MODE_VISUAL): array
    {
        $mode = self::normalizeMode($mode);

        // All modes share the visual toolbar — editing is always WYSIWYG.
        // Mode only affects how existing stored content is converted for display.
        unset($mode);

        $defs = [
            ['id' => 'bold', 'label' => 'B', 'title' => 'Bold', 'cmd' => 'visual', 'visual' => 'bold'],
            ['id' => 'italic', 'label' => 'I', 'title' => 'Italic', 'cmd' => 'visual', 'visual' => 'italic'],
            ['id' => 'underline', 'label' => 'U', 'title' => 'Underline', 'cmd' => 'visual', 'visual' => 'underline'],
            ['id' => 'strike', 'label' => 'S', 'title' => 'Strikethrough', 'cmd' => 'visual', 'visual' => 'strikeThrough'],
            ['id' => 'link', 'label' => 'Link', 'title' => 'Insert link', 'cmd' => 'visual-link'],
            ['id' => 'unlink', 'label' => 'Unlink', 'title' => 'Remove link', 'cmd' => 'visual-unlink'],
            ['id' => 'h2', 'label' => 'H2', 'title' => 'Heading 2', 'cmd' => 'visual-block', 'block' => 'h2'],
            ['id' => 'h3', 'label' => 'H3', 'title' => 'Heading 3', 'cmd' => 'visual-block', 'block' => 'h3'],
            ['id' => 'quote', 'label' => 'Quote', 'title' => 'Blockquote', 'cmd' => 'visual-block', 'block' => 'blockquote'],
            ['id' => 'code', 'label' => 'Code', 'title' => 'Inline code', 'cmd' => 'visual-code'],
            ['id' => 'ul', 'label' => '• List', 'title' => 'Bullet list', 'cmd' => 'visual', 'visual' => 'insertUnorderedList'],
            ['id' => 'ol', 'label' => '1. List', 'title' => 'Numbered list', 'cmd' => 'visual', 'visual' => 'insertOrderedList'],
            ['id' => 'hr', 'label' => '—', 'title' => 'Horizontal rule', 'cmd' => 'visual', 'visual' => 'insertHorizontalRule'],
            ['id' => 'img', 'label' => 'Img', 'title' => 'Insert image', 'cmd' => 'visual-img'],
            [
                'id' => 'emoji',
                'label' => '😀',
                'title' => 'Insert emoji',
                'cmd' => 'emoji-picker',
            ],
        ];

        if (function_exists('ap_apply_filters')) {
            /** @var list<array<string, mixed>> $defs */
            $defs = ap_apply_filters('ap_editor_buttons', $defs, self::MODE_VISUAL);
        }

        return $defs;
    }

    /**
     * Curated Unicode emoji set for the picker (grouped by category).
     *
     * @return array<string, list<string>> category label => emoji characters
     */
    public static function emojis(): array
    {
        $groups = [
            'Smileys' => [
                '😀', '😃', '😄', '😁', '😅', '😂', '🤣', '😊', '😇', '🙂',
                '😉', '😍', '🥰', '😘', '😋', '😜', '😎', '🤩', '🥳', '😏',
                '😒', '🙄', '😔', '😪', '😴', '😷', '🤒', '🤢', '🥵', '🥶',
                '🤯', '🤠', '🥺', '😢', '😭', '😤', '😠', '🤬', '😈', '💀',
                '☠️', '💩', '🤡', '👻', '👽', '🤖', '😺', '😻',
            ],
            'Gestures' => [
                '👋', '🤚', '✋', '🖖', '👌', '✌️', '🤞', '🤟', '🤘', '🤙',
                '👈', '👉', '👆', '👇', '👍', '👎', '✊', '👊', '👏', '🙌',
                '👐', '🤲', '🤝', '🙏', '💪', '👀', '👅', '👄', '💋',
            ],
            'Hearts' => [
                '❤️', '🧡', '💛', '💚', '💙', '💜', '🖤', '🤍', '🤎', '💔',
                '💕', '💞', '💓', '💗', '💖', '💘', '💝', '💯', '💢', '💥',
                '💫', '💦', '💨', '💬', '💭', '💤',
            ],
            'Nature' => [
                '🐶', '🐱', '🐭', '🐹', '🐰', '🦊', '🐻', '🐼', '🐨', '🐯',
                '🦁', '🐮', '🐷', '🐸', '🐵', '🦄', '🐝', '🦋', '🐢', '🐍',
                '🐙', '🐠', '🐬', '🐳', '🌲', '🌴', '🌵', '🍀', '🍁', '🍄',
                '🌹', '🌸', '🌻', '🌙', '⭐', '🌟', '✨', '⚡', '🔥', '🌈',
                '☀️', '⛅', '☁️', '🌧️', '❄️', '⛄', '☔', '🌊',
            ],
            'Food' => [
                '🍎', '🍊', '🍋', '🍌', '🍉', '🍇', '🍓', '🍒', '🍑', '🍍',
                '🥝', '🍅', '🥑', '🌶️', '🌽', '🍞', '🧀', '🍳', '🍔', '🍟',
                '🍕', '🌮', '🌯', '🥗', '🍝', '🍜', '🍣', '🍦', '🧁', '🎂',
                '🍪', '🍩', '🍫', '🍿', '☕', '🍵', '🧋', '🍺', '🍻', '🍷',
                '🍸', '🍾', '🧊',
            ],
            'Activities' => [
                '⚽', '🏀', '🏈', '⚾', '🎾', '🏐', '🎱', '🏓', '⛳', '🏆',
                '🥇', '🥈', '🥉', '🎯', '🎮', '🎲', '♟️', '🧩', '🎨', '🎬',
                '🎤', '🎧', '🎹', '🎸', '🎺', '🎻', '🎭', '🏊', '🚴', '🏋️',
            ],
            'Travel' => [
                '🚗', '🚕', '🚌', '🚑', '🚒', '🚓', '🚲', '🛵', '✈️', '🚀',
                '🚁', '⛵', '🚢', '⚓', '⛽', '🚧', '🏠', '🏢', '🏥', '🏫',
                '⛪', '🕌', '🗽', '🗼', '🏰', '🎡', '🏖️', '🏝️', '🌋', '⛺',
                '🌅', '🌃', '🌌', '🗺️',
            ],
            'Objects' => [
                '⌚', '📱', '💻', '⌨️', '📷', '🎥', '📺', '💡', '🔋', '🔌',
                '💰', '💳', '💎', '🔧', '🔨', '⚙️', '🔑', '🔒', '🔓', '🎁',
                '🎈', '🎉', '✉️', '📦', '📚', '📖', '✏️', '📝', '📌', '🔍',
                '💊', '🧸',
            ],
            'Symbols' => [
                '✅', '❌', '❓', '❗', '➕', '➖', '✖️', '✔️', '💯', '🔴',
                '🟠', '🟡', '🟢', '🔵', '🟣', '⚫', '⚪', '🔺', '🔻', '⬛',
                '⬜', '🔈', '🔔', '🎵', '🎶', '♻️', '⚠️', '⛔', '🚫', '⬆️',
                '➡️', '⬇️', '⬅️', '🔄', '©️', '®️', '™️', '♠️', '♥️', '♦️',
                '♣️', '🏳️', '🏴', '🏁', '🏳️‍🌈', '🏴‍☠️',
            ],
        ];

        if (function_exists('ap_apply_filters')) {
            /** @var array<string, list<string>> $groups */
            $groups = ap_apply_filters('ap_editor_emojis', $groups);
        }

        return is_array($groups) ? $groups : [];
    }

    /**
     * Convert stored content to safe HTML for the visual surface / publish.
     *
     * Accepts legacy Markdown / BBCode as well as HTML from the visual editor.
     */
    public static function valueToHtml(string $value, string $legacyMode = self::MODE_VISUAL): string
    {
        $value = str_replace("\0", '', $value);
        if (trim($value) === '') {
            return '';
        }

        $legacyMode = self::normalizeMode($legacyMode);
        $formatMode = match ($legacyMode) {
            self::MODE_BBCODE => 'bbcode',
            self::MODE_MARKDOWN => 'markdown',
            self::MODE_HTML => 'html',
            default => 'auto',
        };

        if (class_exists('AP_Content_Format', false)) {
            return AP_Content_Format::format($value, [
                'mode' => $formatMode,
                'context' => 'editor',
            ]);
        }
        if (function_exists('ap_format_content')) {
            return ap_format_content($value, [
                'mode' => $formatMode,
                'context' => 'editor',
            ]);
        }

        // Last resort: escape plain text (no formatter loaded).
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Render visual editor control (toolbar + surface + textarea).
     *
     * @param array<string, mixed> $args {
     *     @type string $id          Textarea id (required for a11y).
     *     @type string $name        Textarea name attribute.
     *     @type string $value       Initial content (HTML, Markdown, or BBCode).
     *     @type string $mode        visual|markdown|bbcode|html (default visual).
     *     @type int    $rows        Rows hint for textarea / min-height (default 12).
     *     @type string $class       Extra textarea classes.
     *     @type string $placeholder Placeholder text.
     *     @type bool   $required    Whether required.
     *     @type bool   $toolbar     Show toolbar (default true).
     *     @type string $label       Visible label text (optional; empty = none).
     *     @type string $description Help text under the field.
     *     @type string $wrap_class  Extra classes on outer wrap.
     *     @type array<string, string> $attrs Extra textarea attributes (escaped).
     * }
     */
    public static function render(array $args = []): string
    {
        $id = trim((string) ($args['id'] ?? 'content'));
        if ($id === '') {
            $id = 'content';
        }
        $name = (string) ($args['name'] ?? $id);
        $value = (string) ($args['value'] ?? '');
        $mode = self::normalizeMode((string) ($args['mode'] ?? self::MODE_VISUAL));
        $rows = max(3, (int) ($args['rows'] ?? 12));
        $class = trim((string) ($args['class'] ?? 'large-text'));
        $placeholder = (string) ($args['placeholder'] ?? '');
        $required = !empty($args['required']);
        $toolbar = !array_key_exists('toolbar', $args) || !empty($args['toolbar']);
        $label = (string) ($args['label'] ?? '');
        $description = (string) ($args['description'] ?? '');
        $wrapClass = trim('ap-editor ap-editor--visual ' . (string) ($args['wrap_class'] ?? ''));
        /** @var array<string, string|int|bool> $extraAttrs */
        $extraAttrs = is_array($args['attrs'] ?? null) ? $args['attrs'] : [];

        // Prefer AP_Assets enqueue (head/footer). Always ensure a print fallback so
        // forms that render after ap_head still get CSS/JS (idempotent).
        self::ensureAssets();

        $esc = static function (string $s): string {
            return function_exists('ap_esc_html')
                ? ap_esc_html($s)
                : htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        };
        $escAttr = static function (string $s): string {
            return function_exists('ap_esc_attr')
                ? ap_esc_attr($s)
                : htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        };
        $escTa = static function (string $s): string {
            return function_exists('ap_esc_textarea')
                ? ap_esc_textarea($s)
                : htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        };

        // Convert legacy markup → HTML so the surface shows the published look.
        $htmlValue = self::valueToHtml($value, $mode);

        $html = '<div class="' . $escAttr($wrapClass) . '" data-ap-editor-wrap'
            . ' data-ap-editor-architecture="' . $escAttr(self::architecture()) . '"'
            . ' data-ap-editor-mode="' . $escAttr(self::MODE_VISUAL) . '">';

        if ($label !== '') {
            $html .= '<label class="ap-editor__label" for="' . $escAttr($id) . '">'
                . $esc($label) . '</label>';
        }

        if ($toolbar) {
            $html .= self::renderToolbar(self::MODE_VISUAL, $id);
        }

        // Visual surface: server-renders formatted HTML; hidden until JS enables
        // contenteditable (no-JS users edit the textarea only).
        $surfaceId = $id . '-visual';
        $minHeight = max(6, $rows) * 1.35;
        $html .= '<div class="ap-editor__surface" id="' . $escAttr($surfaceId) . '"'
            . ' data-ap-editor-surface="1"'
            . ' data-ap-editor-for="' . $escAttr($id) . '"'
            . ' style="min-height:' . $escAttr((string) $minHeight) . 'em"'
            . ' hidden';
        if ($placeholder !== '') {
            $html .= ' data-placeholder="' . $escAttr($placeholder) . '"';
        }
        // Content is already kses'd via valueToHtml; allow the formatted markup.
        $html .= '>' . $htmlValue . '</div>';

        // Textarea holds the value submitted with the form (HTML after visual edit).
        $taClass = trim('ap-editor__textarea ' . $class);
        $html .= '<textarea name="' . $escAttr($name) . '" id="' . $escAttr($id) . '" rows="'
            . $rows . '" class="' . $escAttr($taClass) . '" data-ap-editor="1" data-ap-editor-mode="'
            . $escAttr(self::MODE_VISUAL) . '"';
        if ($placeholder !== '') {
            $html .= ' placeholder="' . $escAttr($placeholder) . '"';
        }
        if ($required) {
            $html .= ' required';
        }
        foreach ($extraAttrs as $attr => $attrVal) {
            $attr = preg_replace('/[^a-zA-Z0-9_\-:]/', '', (string) $attr) ?? '';
            if ($attr === '' || $attr === 'id' || $attr === 'name' || $attr === 'class') {
                continue;
            }
            if ($attrVal === true || $attrVal === 1 || $attrVal === '1') {
                $html .= ' ' . $attr;
            } elseif ($attrVal === false || $attrVal === 0 || $attrVal === '0' || $attrVal === null) {
                continue;
            } else {
                $html .= ' ' . $attr . '="' . $escAttr((string) $attrVal) . '"';
            }
        }
        // Store HTML so no-JS submits the same formatted content.
        $html .= '>' . $escTa($htmlValue) . '</textarea>';

        if ($description !== '') {
            $html .= '<p class="description ap-editor__description">' . $esc($description) . '</p>';
        }

        $html .= '</div>';

        // Mid-body asset print when head enqueue already ran (or admin shell).
        // Idempotent: no double tags when printAssets was already called.
        if (!self::$assetsPrinted) {
            ob_start();
            self::printAssets();
            $html .= (string) ob_get_clean();
        }

        return $html;
    }

    /**
     * Render the formatting toolbar for an editor id.
     */
    public static function renderToolbar(string $mode, string $forId): string
    {
        $mode = self::normalizeMode($mode);
        $buttons = self::buttons($mode);

        $esc = static function (string $s): string {
            return function_exists('ap_esc_html')
                ? ap_esc_html($s)
                : htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        };
        $escAttr = static function (string $s): string {
            return function_exists('ap_esc_attr')
                ? ap_esc_attr($s)
                : htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        };

        $html = '<div class="ap-editor__toolbar" role="toolbar" aria-label="Formatting (Visual)"'
            . ' data-ap-editor-toolbar data-ap-editor-for="'
            . $escAttr($forId) . '" data-ap-editor-mode="' . $escAttr(self::MODE_VISUAL) . '">';

        foreach ($buttons as $btn) {
            if (!is_array($btn)) {
                continue;
            }
            $bid = (string) ($btn['id'] ?? '');
            $label = (string) ($btn['label'] ?? $bid);
            $title = (string) ($btn['title'] ?? $label);
            $cmd = (string) ($btn['cmd'] ?? 'visual');
            if ($bid === '' || $cmd === '') {
                continue;
            }

            $btnClass = 'ap-editor__btn ap-editor__btn--' . preg_replace('/[^a-z0-9\-]/', '', $bid);
            if ($bid === 'bold') {
                $btnClass .= ' ap-editor__btn--weight';
            } elseif ($bid === 'italic') {
                $btnClass .= ' ap-editor__btn--em';
            } elseif ($bid === 'underline') {
                $btnClass .= ' ap-editor__btn--underline';
            } elseif ($bid === 'strike') {
                $btnClass .= ' ap-editor__btn--strike';
            } elseif ($bid === 'emoji') {
                $btnClass .= ' ap-editor__btn--emoji';
            }

            $html .= '<button type="button" class="' . $escAttr($btnClass) . '"'
                . ' data-ap-editor-cmd="' . $escAttr($cmd) . '"'
                . ' data-ap-editor-btn="' . $escAttr($bid) . '"'
                . ' title="' . $escAttr($title) . '"'
                . ' aria-label="' . $escAttr($title) . '"';
            if ($cmd === 'emoji-picker') {
                $pickerId = 'ap-editor-emoji-' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $forId);
                $html .= ' aria-haspopup="dialog" aria-expanded="false" aria-controls="'
                    . $escAttr($pickerId) . '"';
            }

            foreach (['visual', 'block', 'text', 'placeholder'] as $key) {
                if (isset($btn[$key]) && is_string($btn[$key])) {
                    $html .= ' data-ap-editor-' . $key . '="' . $escAttr($btn[$key]) . '"';
                }
            }

            $html .= '>' . $esc($label) . '</button>';
        }

        // Visual | Text mode switcher (right side of toolbar).
        $html .= '<span class="ap-editor__mode-switch" role="group" aria-label="Editor mode"'
            . ' data-ap-editor-mode-switch>';
        $html .= '<button type="button" class="ap-editor__mode-btn is-active"'
            . ' data-ap-editor-set-mode="visual"'
            . ' aria-pressed="true"'
            . ' title="' . $escAttr('Visual editor') . '">'
            . $esc('Visual') . '</button>';
        $html .= '<button type="button" class="ap-editor__mode-btn"'
            . ' data-ap-editor-set-mode="html"'
            . ' aria-pressed="false"'
            . ' title="' . $escAttr('Text / HTML source') . '">'
            . $esc('Text') . '</button>';
        $html .= '</span>';
        $html .= '</div>';

        $html .= self::renderEmojiPicker($forId);

        return $html;
    }

    /**
     * Render the emoji picker panel for an editor id.
     */
    public static function renderEmojiPicker(string $forId): string
    {
        $groups = self::emojis();
        if ($groups === []) {
            return '';
        }

        $esc = static function (string $s): string {
            return function_exists('ap_esc_html')
                ? ap_esc_html($s)
                : htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        };
        $escAttr = static function (string $s): string {
            return function_exists('ap_esc_attr')
                ? ap_esc_attr($s)
                : htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        };

        $pickerId = 'ap-editor-emoji-' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $forId);

        $html = '<div class="ap-editor__emoji-picker" id="' . $escAttr($pickerId) . '"'
            . ' data-ap-editor-emoji-picker data-ap-editor-for="' . $escAttr($forId) . '"'
            . ' role="dialog" aria-label="Emoji picker" hidden>';
        $html .= '<div class="ap-editor__emoji-picker-header">';
        $html .= '<span class="ap-editor__emoji-picker-title">' . $esc('Emoji') . '</span>';
        $html .= '<button type="button" class="ap-editor__emoji-close"'
            . ' data-ap-editor-emoji-close title="' . $escAttr('Close') . '"'
            . ' aria-label="' . $escAttr('Close emoji picker') . '">×</button>';
        $html .= '</div>';

        foreach ($groups as $category => $chars) {
            if (!is_string($category) || !is_array($chars) || $chars === []) {
                continue;
            }
            $html .= '<div class="ap-editor__emoji-group">';
            $html .= '<div class="ap-editor__emoji-group-label">' . $esc($category) . '</div>';
            $html .= '<div class="ap-editor__emoji-grid" role="group" aria-label="'
                . $escAttr($category) . '">';
            foreach ($chars as $char) {
                if (!is_string($char) || $char === '') {
                    continue;
                }
                $html .= '<button type="button" class="ap-editor__emoji-btn"'
                    . ' data-ap-editor-cmd="insert"'
                    . ' data-ap-editor-text="' . $escAttr($char) . '"'
                    . ' data-ap-emoji="1"'
                    . ' title="' . $escAttr($char) . '"'
                    . ' aria-label="' . $escAttr('Insert ' . $char) . '">'
                    . $esc($char) . '</button>';
            }
            $html .= '</div></div>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Absolute URL to an editor asset under ap-includes/.
     */
    public static function assetUrl(string $relative): string
    {
        $relative = ltrim(str_replace('\\', '/', $relative), '/');
        $path = 'ap-includes/' . $relative;
        if (function_exists('ap_site_url')) {
            return ap_site_url($path);
        }

        return '/' . $path;
    }

    /**
     * Register + enqueue editor CSS/JS via AP_Assets (front-end).
     */
    public static function enqueue(): void
    {
        if (self::$assetsEnqueued) {
            return;
        }
        self::$assetsEnqueued = true;

        $ver = defined('AP_VERSION') ? (string) AP_VERSION : false;
        $css = self::assetUrl('css/ap-editor.css');
        $js = self::assetUrl('js/ap-editor.js');

        if (function_exists('ap_enqueue_style')) {
            ap_enqueue_style(self::HANDLE_STYLE, $css, [], $ver);
        } elseif (class_exists('AP_Assets', false)) {
            AP_Assets::enqueueStyle(self::HANDLE_STYLE, $css, [], $ver);
        }

        if (function_exists('ap_enqueue_script')) {
            ap_enqueue_script(self::HANDLE_SCRIPT, $js, [], $ver, true);
        } elseif (class_exists('AP_Assets', false)) {
            AP_Assets::enqueueScript(self::HANDLE_SCRIPT, $js, [], $ver, true);
        }
    }

    /**
     * Print link + script tags once (admin screens that do not use AP_Assets print).
     */
    public static function printAssets(): void
    {
        if (self::$assetsPrinted) {
            return;
        }
        self::$assetsPrinted = true;

        $ver = defined('AP_VERSION') ? (string) AP_VERSION : '';
        $css = self::assetUrl('css/ap-editor.css');
        $js = self::assetUrl('js/ap-editor.js');
        $escUrl = static function (string $u): string {
            return function_exists('ap_esc_url')
                ? ap_esc_url($u)
                : htmlspecialchars($u, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        };

        $q = $ver !== '' ? '?v=' . rawurlencode($ver) : '';
        echo '<link rel="stylesheet" href="' . $escUrl($css) . $q
            . '" id="ap-editor-css">' . "\n";
        echo '<script src="' . $escUrl($js) . $q
            . '" id="ap-editor-js" defer></script>' . "\n";
    }

    /**
     * Ensure assets will be available: enqueue on front-end when possible.
     */
    public static function ensureAssets(): void
    {
        if (class_exists('AP_Assets', false) && !defined('AP_ADMIN')) {
            self::enqueue();
        }
    }

    /**
     * Whether assets have been enqueued or printed (for tests).
     */
    public static function assetsWereEnqueued(): bool
    {
        return self::$assetsEnqueued || self::$assetsPrinted;
    }

    /**
     * Reset static flags (tests).
     */
    public static function reset(): void
    {
        self::$assetsPrinted = false;
        self::$assetsEnqueued = false;
    }

    /**
     * Default mode for a context slug.
     *
     * All contexts use the visual WYSIWYG editor. Legacy mode labels are still
     * accepted by {@see normalizeMode()} for content conversion.
     *
     * @param string $context post|page|comment|forum|…
     */
    public static function modeForContext(string $context): string
    {
        $context = strtolower(trim($context));
        // Always visual WYSIWYG; context is kept for the filter signature.
        $mode = self::MODE_VISUAL;

        if (function_exists('ap_apply_filters')) {
            /** @var string $mode */
            $mode = ap_apply_filters('ap_editor_mode', $mode, $context);
        }

        return self::normalizeMode(is_string($mode) ? $mode : self::MODE_VISUAL);
    }
}
