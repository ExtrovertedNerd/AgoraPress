<?php

/**
 * Lightweight classic editor — formatting toolbar for textareas.
 *
 * Not a block editor and never will be in core. Content is always a plain
 * `<textarea>`; buttons insert Markdown, BBCode, or HTML around the selection
 * (Quicktags-style). Progressive enhancement only: with JS disabled the
 * textarea still submits. Shared by Post/Page admin, comments, and forum
 * topic/reply forms. Includes a built-in Unicode emoji picker (no CDN, no
 * image sprites). No jQuery, no contenteditable, no block tree.
 *
 * Soft asset budgets (guarded by tests): JS ≤ 32 KiB, CSS ≤ 16 KiB.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * Classic WYSIWYG-style formatting toolbar + textarea renderer.
 *
 * Architecture is intentionally fixed to {@see AP_Editor::ARCHITECTURE_CLASSIC}.
 * Full block / Gutenberg editors are a non-goal for core (see FEATURES.md).
 */
class AP_Editor
{
    public const MODE_MARKDOWN = 'markdown';

    public const MODE_BBCODE = 'bbcode';

    public const MODE_HTML = 'html';

    /** Stable architecture id: classic textarea + toolbar (never "blocks"). */
    public const ARCHITECTURE_CLASSIC = 'classic';

    public const HANDLE_STYLE = 'ap-editor';

    public const HANDLE_SCRIPT = 'ap-editor';

    /** Soft upper bound for ap-editor.js (bytes). Guard tests enforce this. */
    public const MAX_JS_BYTES = 32768;

    /** Soft upper bound for ap-editor.css (bytes). Guard tests enforce this. */
    public const MAX_CSS_BYTES = 16384;

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
     * Supported markup modes (never includes a "blocks" mode).
     *
     * @return list<string>
     */
    public static function modes(): array
    {
        return [self::MODE_MARKDOWN, self::MODE_BBCODE, self::MODE_HTML];
    }

    public static function normalizeMode(string $mode): string
    {
        $mode = strtolower(trim($mode));

        return in_array($mode, self::modes(), true) ? $mode : self::MODE_MARKDOWN;
    }

    /**
     * Formatting button definitions for a mode.
     *
     * Each button: id, label, title, and mode-specific insert instructions
     * consumed by ap-editor.js (wrap / prefix / block / link / hr).
     *
     * @return list<array<string, mixed>>
     */
    public static function buttons(string $mode = self::MODE_MARKDOWN): array
    {
        $mode = self::normalizeMode($mode);

        $defs = match ($mode) {
            self::MODE_BBCODE => [
                ['id' => 'bold', 'label' => 'B', 'title' => 'Bold', 'cmd' => 'wrap', 'before' => '[b]', 'after' => '[/b]', 'placeholder' => 'bold text'],
                ['id' => 'italic', 'label' => 'I', 'title' => 'Italic', 'cmd' => 'wrap', 'before' => '[i]', 'after' => '[/i]', 'placeholder' => 'italic text'],
                ['id' => 'underline', 'label' => 'U', 'title' => 'Underline', 'cmd' => 'wrap', 'before' => '[u]', 'after' => '[/u]', 'placeholder' => 'underlined text'],
                ['id' => 'strike', 'label' => 'S', 'title' => 'Strikethrough', 'cmd' => 'wrap', 'before' => '[s]', 'after' => '[/s]', 'placeholder' => 'struck text'],
                ['id' => 'link', 'label' => 'Link', 'title' => 'Insert link', 'cmd' => 'link', 'before' => '[url=%url%]', 'after' => '[/url]', 'placeholder' => 'link text'],
                ['id' => 'quote', 'label' => 'Quote', 'title' => 'Blockquote', 'cmd' => 'wrap', 'before' => "[quote]\n", 'after' => "\n[/quote]", 'placeholder' => 'quoted text'],
                ['id' => 'code', 'label' => 'Code', 'title' => 'Code block', 'cmd' => 'wrap', 'before' => "[code]\n", 'after' => "\n[/code]", 'placeholder' => 'code'],
                ['id' => 'ul', 'label' => '• List', 'title' => 'Bullet list', 'cmd' => 'bbcode-list', 'ordered' => false],
                ['id' => 'ol', 'label' => '1. List', 'title' => 'Numbered list', 'cmd' => 'bbcode-list', 'ordered' => true],
                ['id' => 'img', 'label' => 'Img', 'title' => 'Insert image', 'cmd' => 'img', 'template' => '[img]%url%[/img]'],
            ],
            self::MODE_HTML => [
                ['id' => 'bold', 'label' => 'B', 'title' => 'Bold', 'cmd' => 'wrap', 'before' => '<strong>', 'after' => '</strong>', 'placeholder' => 'bold text'],
                ['id' => 'italic', 'label' => 'I', 'title' => 'Italic', 'cmd' => 'wrap', 'before' => '<em>', 'after' => '</em>', 'placeholder' => 'italic text'],
                ['id' => 'underline', 'label' => 'U', 'title' => 'Underline', 'cmd' => 'wrap', 'before' => '<u>', 'after' => '</u>', 'placeholder' => 'underlined text'],
                ['id' => 'strike', 'label' => 'S', 'title' => 'Strikethrough', 'cmd' => 'wrap', 'before' => '<del>', 'after' => '</del>', 'placeholder' => 'struck text'],
                ['id' => 'link', 'label' => 'Link', 'title' => 'Insert link', 'cmd' => 'link', 'before' => '<a href="%url%">', 'after' => '</a>', 'placeholder' => 'link text'],
                ['id' => 'h2', 'label' => 'H2', 'title' => 'Heading 2', 'cmd' => 'wrap', 'before' => '<h2>', 'after' => '</h2>', 'placeholder' => 'Heading'],
                ['id' => 'h3', 'label' => 'H3', 'title' => 'Heading 3', 'cmd' => 'wrap', 'before' => '<h3>', 'after' => '</h3>', 'placeholder' => 'Heading'],
                ['id' => 'quote', 'label' => 'Quote', 'title' => 'Blockquote', 'cmd' => 'wrap', 'before' => "<blockquote>\n", 'after' => "\n</blockquote>", 'placeholder' => 'quoted text'],
                ['id' => 'code', 'label' => 'Code', 'title' => 'Inline code', 'cmd' => 'wrap', 'before' => '<code>', 'after' => '</code>', 'placeholder' => 'code'],
                ['id' => 'ul', 'label' => '• List', 'title' => 'Bullet list', 'cmd' => 'html-list', 'ordered' => false],
                ['id' => 'ol', 'label' => '1. List', 'title' => 'Numbered list', 'cmd' => 'html-list', 'ordered' => true],
                ['id' => 'hr', 'label' => '—', 'title' => 'Horizontal rule', 'cmd' => 'insert', 'text' => "\n<hr>\n"],
            ],
            default => [ // markdown
                ['id' => 'bold', 'label' => 'B', 'title' => 'Bold', 'cmd' => 'wrap', 'before' => '**', 'after' => '**', 'placeholder' => 'bold text'],
                ['id' => 'italic', 'label' => 'I', 'title' => 'Italic', 'cmd' => 'wrap', 'before' => '*', 'after' => '*', 'placeholder' => 'italic text'],
                ['id' => 'strike', 'label' => 'S', 'title' => 'Strikethrough', 'cmd' => 'wrap', 'before' => '~~', 'after' => '~~', 'placeholder' => 'struck text'],
                ['id' => 'link', 'label' => 'Link', 'title' => 'Insert link', 'cmd' => 'md-link', 'placeholder' => 'link text'],
                ['id' => 'h2', 'label' => 'H2', 'title' => 'Heading 2', 'cmd' => 'prefix-line', 'prefix' => '## ', 'placeholder' => 'Heading'],
                ['id' => 'h3', 'label' => 'H3', 'title' => 'Heading 3', 'cmd' => 'prefix-line', 'prefix' => '### ', 'placeholder' => 'Heading'],
                ['id' => 'quote', 'label' => 'Quote', 'title' => 'Blockquote', 'cmd' => 'prefix-lines', 'prefix' => '> ', 'placeholder' => 'quoted text'],
                ['id' => 'code', 'label' => 'Code', 'title' => 'Inline code', 'cmd' => 'wrap', 'before' => '`', 'after' => '`', 'placeholder' => 'code'],
                ['id' => 'ul', 'label' => '• List', 'title' => 'Bullet list', 'cmd' => 'prefix-lines', 'prefix' => '- ', 'placeholder' => 'list item'],
                ['id' => 'ol', 'label' => '1. List', 'title' => 'Numbered list', 'cmd' => 'prefix-lines', 'prefix' => '1. ', 'placeholder' => 'list item'],
                ['id' => 'hr', 'label' => '—', 'title' => 'Horizontal rule', 'cmd' => 'insert', 'text' => "\n---\n"],
            ],
        };

        // Emoji picker is available in every markup mode (inserts raw Unicode).
        $defs[] = [
            'id' => 'emoji',
            'label' => '😀',
            'title' => 'Insert emoji',
            'cmd' => 'emoji-picker',
        ];

        if (function_exists('ap_apply_filters')) {
            /** @var list<array<string, mixed>> $defs */
            $defs = ap_apply_filters('ap_editor_buttons', $defs, $mode);
        }

        return $defs;
    }

    /**
     * Curated Unicode emoji set for the picker (grouped by category).
     *
     * Lightweight: no image sprites, no remote CDN. Characters insert as-is
     * into Markdown, BBCode, and HTML editors. Intentionally modest so every
     * editor instance stays small in HTML size.
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
     * Render toolbar + textarea editor control.
     *
     * @param array<string, mixed> $args {
     *     @type string $id          Textarea id (required for a11y).
     *     @type string $name        Textarea name attribute.
     *     @type string $value       Initial content.
     *     @type string $mode        markdown|bbcode|html (default markdown).
     *     @type int    $rows        Rows (default 12).
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
        $mode = self::normalizeMode((string) ($args['mode'] ?? self::MODE_MARKDOWN));
        $rows = max(3, (int) ($args['rows'] ?? 12));
        $class = trim((string) ($args['class'] ?? 'large-text'));
        $placeholder = (string) ($args['placeholder'] ?? '');
        $required = !empty($args['required']);
        $toolbar = !array_key_exists('toolbar', $args) || !empty($args['toolbar']);
        $label = (string) ($args['label'] ?? '');
        $description = (string) ($args['description'] ?? '');
        $wrapClass = trim('ap-editor ' . (string) ($args['wrap_class'] ?? ''));
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

        // Always classic: plain textarea + toolbar. Never contenteditable / blocks.
        $html = '<div class="' . $escAttr($wrapClass) . '" data-ap-editor-wrap'
            . ' data-ap-editor-architecture="' . $escAttr(self::architecture()) . '"'
            . ' data-ap-editor-mode="' . $escAttr($mode) . '">';

        if ($label !== '') {
            $html .= '<label class="ap-editor__label" for="' . $escAttr($id) . '">'
                . $esc($label) . '</label>';
        }

        if ($toolbar) {
            $html .= self::renderToolbar($mode, $id);
        }

        $taClass = trim('ap-editor__textarea ' . $class);
        $html .= '<textarea name="' . $escAttr($name) . '" id="' . $escAttr($id) . '" rows="'
            . $rows . '" class="' . $escAttr($taClass) . '" data-ap-editor="1" data-ap-editor-mode="'
            . $escAttr($mode) . '"';
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
        $html .= '>' . $escTa($value) . '</textarea>';

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
     * Render the formatting toolbar for a textarea id.
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

        $modeLabel = match ($mode) {
            self::MODE_BBCODE => 'BBCode',
            self::MODE_HTML => 'HTML',
            default => 'Markdown',
        };

        $html = '<div class="ap-editor__toolbar" role="toolbar" aria-label="Formatting ('
            . $escAttr($modeLabel) . ')" data-ap-editor-toolbar data-ap-editor-for="'
            . $escAttr($forId) . '" data-ap-editor-mode="' . $escAttr($mode) . '">';

        foreach ($buttons as $btn) {
            if (!is_array($btn)) {
                continue;
            }
            $bid = (string) ($btn['id'] ?? '');
            $label = (string) ($btn['label'] ?? $bid);
            $title = (string) ($btn['title'] ?? $label);
            $cmd = (string) ($btn['cmd'] ?? 'wrap');
            if ($bid === '' || $cmd === '') {
                continue;
            }

            $btnClass = 'ap-editor__btn ap-editor__btn--' . preg_replace('/[^a-z0-9\-]/', '', $bid);
            // Bold/italic labels get visual weight.
            if ($bid === 'bold') {
                $btnClass .= ' ap-editor__btn--weight';
            } elseif ($bid === 'italic') {
                $btnClass .= ' ap-editor__btn--em';
            } elseif ($bid === 'underline') {
                $btnClass .= ' ap-editor__btn--underline';
            } elseif ($bid === 'strike') {
                $btnClass .= ' ap-editor__btn--strike';
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

            foreach (
                [
                    'before', 'after', 'prefix', 'placeholder', 'text', 'template',
                ] as $key
            ) {
                if (isset($btn[$key]) && is_string($btn[$key])) {
                    $html .= ' data-ap-editor-' . $key . '="' . $escAttr($btn[$key]) . '"';
                }
            }
            if (array_key_exists('ordered', $btn)) {
                $html .= ' data-ap-editor-ordered="' . (!empty($btn['ordered']) ? '1' : '0') . '"';
            }

            $html .= '>' . $esc($label) . '</button>';
        }

        $html .= '<span class="ap-editor__mode-hint" aria-hidden="true">'
            . $esc($modeLabel) . '</span>';
        $html .= '</div>';

        // Emoji picker panel (toggled by the emoji toolbar button).
        $html .= self::renderEmojiPicker($forId);

        return $html;
    }

    /**
     * Render the emoji picker panel for a textarea id.
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
        $escAttr = static function (string $s): string {
            return function_exists('ap_esc_attr')
                ? ap_esc_attr($s)
                : htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        };

        $q = $ver !== '' ? '?v=' . rawurlencode($ver) : '';
        echo '<link rel="stylesheet" href="' . $escUrl($css) . $q
            . '" id="ap-editor-css">' . "\n";
        echo '<script src="' . $escUrl($js) . $q
            . '" id="ap-editor-js" defer></script>' . "\n";
        // Keep attribute escape available for future data attrs.
        unset($escAttr);
    }

    /**
     * Ensure assets will be available: enqueue on front-end when possible.
     * Actual link/script tags are also emitted once from {@see render()} via
     * {@see printAssets()} so toolbars work even when called after ap_head().
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
     * @param string $context post|page|comment|forum
     */
    public static function modeForContext(string $context): string
    {
        $context = strtolower(trim($context));
        $mode = match ($context) {
            'forum', 'topic', 'reply', 'forum_topic', 'forum_reply' => self::MODE_BBCODE,
            'html' => self::MODE_HTML,
            default => self::MODE_MARKDOWN,
        };

        if (function_exists('ap_apply_filters')) {
            /** @var string $mode */
            $mode = ap_apply_filters('ap_editor_mode', $mode, $context);
        }

        return self::normalizeMode(is_string($mode) ? $mode : self::MODE_MARKDOWN);
    }
}
