<?php

/**
 * AgoraPress content formatting: BBCode + Markdown + limited safe HTML.
 *
 * Converts user-authored forum (and general) markup into safe HTML for
 * display. No external dependencies — pure PHP 8.2+.
 *
 * Default pipeline ({@see format()} mode `auto`):
 * 1. Protect code islands (BBCode [code], Markdown fences / inline code)
 * 2. Convert BBCode tags to HTML
 * 3. Convert a Markdown subset to HTML
 * 4. Sanitize with a whitelist kses (allowed tags/attrs, safe URLs only)
 * 5. Apply `ap_format_content` filter when hooks are available
 *
 * Modes: auto | bbcode | markdown | html | plain
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * Content formatter — BBCode, Markdown, and safe HTML sanitization.
 */
class AP_Content_Format
{
    public const MODE_AUTO = 'auto';

    public const MODE_BBCODE = 'bbcode';

    public const MODE_MARKDOWN = 'markdown';

    public const MODE_HTML = 'html';

    public const MODE_PLAIN = 'plain';

    /** @var list<string> */
    private static array $placeholders = [];

    /**
     * Format content for safe HTML display.
     *
     * @param array<string, mixed> $args {
     *     @type string $mode      auto|bbcode|markdown|html|plain (default auto)
     *     @type string $context   Optional context label (e.g. forum) for filters
     *     @type bool   $nl2br     Convert remaining newlines (default true for plain remnants)
     * }
     */
    public static function format(string $content, array $args = []): string
    {
        $content = str_replace("\0", '', $content);
        if ($content === '') {
            return '';
        }

        // Visual editors often insert &nbsp; / U+00A0 between punctuation and
        // emoji; treat them as ordinary spaces so display never shows the entity.
        $content = self::normalizeNbsp($content);

        $mode = self::normalizeMode((string) ($args['mode'] ?? self::MODE_AUTO));
        $context = (string) ($args['context'] ?? '');

        $html = match ($mode) {
            self::MODE_PLAIN => self::escapePlain($content),
            self::MODE_HTML => self::kses($content),
            self::MODE_BBCODE => self::pipelineBbcodeOnly($content),
            self::MODE_MARKDOWN => self::pipelineMarkdownOnly($content),
            default => self::pipelineAuto($content),
        };

        // Second pass after escape pipelines (e.g. &nbsp; → &amp;nbsp;).
        $html = self::normalizeNbsp($html);

        if (function_exists('ap_apply_filters')) {
            /** @var string $html */
            $html = ap_apply_filters('ap_format_content', $html, $content, $mode, $context, $args);
        }

        return $html;
    }

    /**
     * Convert non-breaking spaces (Unicode + HTML entities) to regular spaces.
     *
     * Handles named/numeric entities and double-encoded forms produced when
     * contenteditable HTML is run through htmlspecialchars in auto mode.
     */
    public static function normalizeNbsp(string $text): string
    {
        if ($text === '') {
            return '';
        }

        // UTF-8 non-breaking space (U+00A0).
        $text = str_replace("\xC2\xA0", ' ', $text);

        // Double-encoded entity first, then single.
        $text = preg_replace('/&amp;nbsp;/i', ' ', $text) ?? $text;
        $text = preg_replace('/&nbsp;|&#0*160;|&#x0*a0;/i', ' ', $text) ?? $text;

        return $text;
    }

    /**
     * Convert BBCode to HTML (does not kses; use format() for safe output).
     */
    public static function bbcodeToHtml(string $text): string
    {
        $text = str_replace("\0", '', $text);
        if ($text === '') {
            return '';
        }

        self::$placeholders = [];
        $text = self::protectBbcodeCode($text);
        $text = self::convertBbcode($text);
        $text = self::restorePlaceholders($text);
        self::$placeholders = [];

        return $text;
    }

    /**
     * Convert a Markdown subset to HTML (does not kses; use format() for safe output).
     */
    public static function markdownToHtml(string $text): string
    {
        $text = str_replace("\0", '', $text);
        if ($text === '') {
            return '';
        }

        self::$placeholders = [];
        $text = self::protectMarkdownCode($text);
        $text = self::convertMarkdown($text);
        $text = self::restorePlaceholders($text);
        self::$placeholders = [];

        return $text;
    }

    /**
     * Whitelist HTML tags/attributes and scrub unsafe URLs/events.
     *
     * @param array<string, array<string, bool>>|null $allowed null = default allow-list
     */
    public static function kses(string $html, ?array $allowed = null): string
    {
        $html = str_replace("\0", '', $html);
        if ($html === '') {
            return '';
        }

        $allowed = $allowed ?? self::allowedTags();
        if (function_exists('ap_apply_filters')) {
            /** @var array<string, array<string, bool>> $allowed */
            $allowed = ap_apply_filters('ap_allowed_html', $allowed);
        }

        // Remove dangerous elements entirely (including contents for script/style).
        $block = 'script|style|iframe|object|embed|form|input|button|textarea'
            . '|select|option|meta|link|base|svg|math';
        $html = preg_replace(
            '@<(' . $block . ')(\s[^>]*)?>.*?</\1\s*>@is',
            '',
            $html
        ) ?? $html;
        $html = preg_replace(
            '@<(' . $block . ')(\s[^>]*)?/?>@is',
            '',
            $html
        ) ?? $html;

        // Strip HTML comments (can hide IE conditionals / breakouts).
        $html = preg_replace('/<!--.*?-->/s', '', $html) ?? $html;

        $allowedLower = [];
        foreach ($allowed as $tag => $attrs) {
            $allowedLower[strtolower((string) $tag)] = is_array($attrs) ? $attrs : [];
        }

        $result = preg_replace_callback(
            '/<\/?([a-zA-Z][a-zA-Z0-9]*)\b([^>]*)>/',
            static function (array $m) use ($allowedLower): string {
                $full = $m[0];
                $tag = strtolower($m[1]);
                $isClose = str_starts_with($full, '</');

                if (!isset($allowedLower[$tag])) {
                    return '';
                }

                if ($isClose) {
                    return '</' . $tag . '>';
                }

                $attrSpec = $allowedLower[$tag];
                $rawAttrs = (string) ($m[2] ?? '');
                $selfClosing = str_ends_with(rtrim($rawAttrs), '/');
                $rawAttrs = rtrim($rawAttrs);
                if ($selfClosing) {
                    $rawAttrs = rtrim(substr($rawAttrs, 0, -1));
                }

                $cleanAttrs = self::filterAttributes($tag, $rawAttrs, $attrSpec);
                $void = in_array($tag, ['br', 'hr', 'img'], true);

                if ($void || $selfClosing) {
                    return '<' . $tag . $cleanAttrs . '>';
                }

                return '<' . $tag . $cleanAttrs . '>';
            },
            $html
        );

        return is_string($result) ? $result : '';
    }

    /**
     * Default allowed tags and attributes for forum/blog user content.
     *
     * @return array<string, array<string, bool>>
     */
    public static function allowedTags(): array
    {
        $common = ['class' => true, 'id' => true, 'title' => true];

        return [
            'a' => $common + ['href' => true, 'rel' => true, 'target' => true],
            'abbr' => $common + ['title' => true],
            'b' => $common,
            'blockquote' => $common + ['cite' => true],
            'br' => [],
            'cite' => $common,
            'code' => $common,
            'del' => $common,
            'details' => $common + ['open' => true],
            'em' => $common,
            'h1' => $common,
            'h2' => $common,
            'h3' => $common,
            'h4' => $common,
            'h5' => $common,
            'h6' => $common,
            'hr' => $common,
            'i' => $common,
            'img' => $common + [
                'src' => true,
                'alt' => true,
                'width' => true,
                'height' => true,
                'loading' => true,
            ],
            'ins' => $common,
            'kbd' => $common,
            'li' => $common,
            'mark' => $common,
            'ol' => $common + ['start' => true, 'type' => true],
            'p' => $common,
            'pre' => $common,
            'q' => $common + ['cite' => true],
            's' => $common,
            'samp' => $common,
            'span' => $common,
            'strong' => $common,
            'sub' => $common,
            'summary' => $common,
            'sup' => $common,
            'u' => $common,
            'ul' => $common,
        ];
    }

    /**
     * Whether a URL is safe for href/src (http, https, mailto, relative).
     */
    public static function isSafeUrl(string $url): bool
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($url === '') {
            return false;
        }

        // Block control chars and whitespace injection.
        if (preg_match('/[\x00-\x1f\x7f]/', $url) === 1) {
            return false;
        }

        // Protocol-relative or absolute with scheme.
        if (preg_match('#^([a-z][a-z0-9+.\-]*):#i', $url, $m) === 1) {
            $scheme = strtolower($m[1]);

            return in_array($scheme, ['http', 'https', 'mailto'], true);
        }

        // Relative: path, query, hash, or protocol-relative //host (treat as https path intent).
        if (str_starts_with($url, '//')) {
            return true;
        }

        // Reject obvious path tricks that start with javascript-like junk without scheme.
        if (preg_match('/^(javascript|vbscript|data)\b/i', $url) === 1) {
            return false;
        }

        return true;
    }

    /**
     * Escape plain text for HTML body and convert newlines to <br>.
     */
    public static function escapePlain(string $text, bool $nl2br = true): string
    {
        $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        if ($nl2br) {
            $escaped = nl2br($escaped, false);
        }

        return $escaped;
    }

    /**
     * @return list<string>
     */
    public static function modes(): array
    {
        return [
            self::MODE_AUTO,
            self::MODE_BBCODE,
            self::MODE_MARKDOWN,
            self::MODE_HTML,
            self::MODE_PLAIN,
        ];
    }

    public static function normalizeMode(string $mode): string
    {
        $mode = strtolower(trim($mode));

        return in_array($mode, self::modes(), true) ? $mode : self::MODE_AUTO;
    }

    // -------------------------------------------------------------------------
    // Pipelines
    // -------------------------------------------------------------------------

    private static function pipelineAuto(string $content): string
    {
        self::$placeholders = [];
        $text = self::protectBbcodeCode($content);
        $text = self::protectMarkdownCode($text);
        $text = self::convertBbcode($text);
        $text = self::convertMarkdown($text);
        // Restore code islands before paragraph wrapping so <pre> is block-level.
        $text = self::restorePlaceholders($text);
        self::$placeholders = [];
        $text = self::wrapLooseParagraphs($text);

        return self::kses($text);
    }

    private static function pipelineBbcodeOnly(string $content): string
    {
        self::$placeholders = [];
        $text = self::protectBbcodeCode($content);
        $text = self::convertBbcode($text);
        $text = self::restorePlaceholders($text);
        self::$placeholders = [];
        // Escape any remaining raw HTML that was not produced by BBCode.
        $text = self::escapeOutsideTags($text);

        return self::kses($text);
    }

    private static function pipelineMarkdownOnly(string $content): string
    {
        self::$placeholders = [];
        $text = self::protectMarkdownCode($content);
        $text = self::convertMarkdown($text);
        $text = self::restorePlaceholders($text);
        self::$placeholders = [];
        $text = self::wrapLooseParagraphs($text);

        return self::kses($text);
    }

    // -------------------------------------------------------------------------
    // Code protection
    // -------------------------------------------------------------------------

    private static function storePlaceholder(string $html): string
    {
        $i = count(self::$placeholders);
        // Printable token (no NULs) so later kses/escapes cannot destroy it before restore.
        $token = '@@APCF' . $i . 'X' . dechex(crc32($html . (string) $i)) . 'END@@';
        self::$placeholders[$token] = $html;

        return $token;
    }

    private static function restorePlaceholders(string $text): string
    {
        if (self::$placeholders === []) {
            return $text;
        }

        // Multi-pass in case placeholders were nested (should not be).
        for ($n = 0; $n < 3; $n++) {
            $replaced = str_replace(array_keys(self::$placeholders), array_values(self::$placeholders), $text);
            if ($replaced === $text) {
                break;
            }
            $text = $replaced;
        }

        return $text;
    }

    private static function protectBbcodeCode(string $text): string
    {
        return (string) preg_replace_callback(
            '/\[code\](.*?)\[\/code\]/is',
            static function (array $m): string {
                $inner = htmlspecialchars((string) $m[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                // Preserve intentional newlines inside code; trim only one leading newline.
                if (str_starts_with($inner, "\n")) {
                    $inner = substr($inner, 1);
                }
                if (str_ends_with($inner, "\n")) {
                    $inner = substr($inner, 0, -1);
                }
                $html = '<pre class="ap-code"><code>' . $inner . '</code></pre>';

                return self::storePlaceholder($html);
            },
            $text
        );
    }

    private static function protectMarkdownCode(string $text): string
    {
        // Fenced code blocks ```lang\n...\n```
        $text = (string) preg_replace_callback(
            '/^```([a-zA-Z0-9_-]*)[ \t]*\R(.*?)(?:\R)?^```[ \t]*$/ms',
            static function (array $m): string {
                $lang = trim((string) $m[1]);
                $inner = htmlspecialchars((string) $m[2], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $class = $lang !== ''
                    ? ' class="language-' . htmlspecialchars($lang, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"'
                    : '';
                $html = '<pre class="ap-code"><code' . $class . '>' . $inner . '</code></pre>';

                return self::storePlaceholder($html);
            },
            $text
        );

        // Inline `code` (not spanning lines).
        $text = (string) preg_replace_callback(
            '/`([^`\n]+)`/',
            static function (array $m): string {
                $inner = htmlspecialchars((string) $m[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $html = '<code>' . $inner . '</code>';

                return self::storePlaceholder($html);
            },
            $text
        );

        return $text;
    }

    // -------------------------------------------------------------------------
    // BBCode
    // -------------------------------------------------------------------------

    private static function convertBbcode(string $text): string
    {
        // Simple paired tags (case-insensitive).
        $pairs = [
            'b' => 'strong',
            'i' => 'em',
            'u' => 'u',
            's' => 'del',
            'strike' => 'del',
            'center' => 'div class="ap-align-center"',
        ];
        foreach ($pairs as $bb => $htmlTag) {
            if (str_contains($htmlTag, ' ')) {
                // Tag with attributes (e.g. div class=...).
                $parts = explode(' ', $htmlTag, 2);
                $tag = $parts[0];
                $attrs = $parts[1] ?? '';
                $text = (string) preg_replace(
                    '/\[' . $bb . '\](.*?)\[\/' . $bb . '\]/is',
                    '<' . $tag . ' ' . $attrs . '>$1</' . $tag . '>',
                    $text
                );
            } else {
                $text = (string) preg_replace(
                    '/\[' . $bb . '\](.*?)\[\/' . $bb . '\]/is',
                    '<' . $htmlTag . '>$1</' . $htmlTag . '>',
                    $text
                );
            }
        }

        // [url]http...[/url]
        $text = (string) preg_replace_callback(
            '/\[url\]\s*(.*?)\s*\[\/url\]/is',
            static function (array $m): string {
                $url = trim(html_entity_decode((string) $m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if (!self::isSafeUrl($url)) {
                    return htmlspecialchars((string) $m[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                }
                $esc = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

                return '<a href="' . $esc . '" rel="nofollow ugc">' . $esc . '</a>';
            },
            $text
        );

        // [url=http...]text[/url]
        $text = (string) preg_replace_callback(
            '/\[url=([^\]]+)\](.*?)\[\/url\]/is',
            static function (array $m): string {
                $url = trim(html_entity_decode((string) $m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), " \t\"'");
                $label = (string) $m[2];
                if (!self::isSafeUrl($url)) {
                    return htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                }
                $esc = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

                return '<a href="' . $esc . '" rel="nofollow ugc">' . $label . '</a>';
            },
            $text
        );

        // [email]x@y[/email] and [email=x@y]label[/email]
        $text = (string) preg_replace_callback(
            '/\[email\]\s*(.*?)\s*\[\/email\]/is',
            static function (array $m): string {
                $addr = trim((string) $m[1]);
                if (!filter_var($addr, FILTER_VALIDATE_EMAIL)) {
                    return htmlspecialchars($addr, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                }
                $esc = htmlspecialchars($addr, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

                return '<a href="mailto:' . $esc . '">' . $esc . '</a>';
            },
            $text
        );
        $text = (string) preg_replace_callback(
            '/\[email=([^\]]+)\](.*?)\[\/email\]/is',
            static function (array $m): string {
                $addr = trim((string) $m[1], " \t\"'");
                $label = (string) $m[2];
                if (!filter_var($addr, FILTER_VALIDATE_EMAIL)) {
                    return htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                }
                $esc = htmlspecialchars($addr, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

                return '<a href="mailto:' . $esc . '">' . $label . '</a>';
            },
            $text
        );

        // [img]url[/img]
        $text = (string) preg_replace_callback(
            '/\[img\]\s*(.*?)\s*\[\/img\]/is',
            static function (array $m): string {
                $url = trim(html_entity_decode((string) $m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if (!self::isSafeUrl($url) || preg_match('#^mailto:#i', $url) === 1) {
                    return '';
                }
                $esc = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

                return '<img src="' . $esc . '" alt="">';
            },
            $text
        );

        // [quote] / [quote=author] / [quote="author"]
        $quoteCb = static function (array $m): string {
            $author = trim((string) ($m[1] ?? ''), " \t\"'");
            $inner = (string) ($m[2] ?? '');
            if ($author !== '') {
                $authorEsc = htmlspecialchars($author, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

                return '<blockquote class="ap-quote"><cite>' . $authorEsc . '</cite>' . $inner . '</blockquote>';
            }

            return '<blockquote class="ap-quote">' . $inner . '</blockquote>';
        };
        for ($i = 0; $i < 4; $i++) {
            $next = (string) preg_replace_callback(
                '/\[quote(?:=([^\]]+))?\](.*?)\[\/quote\]/is',
                $quoteCb,
                $text
            );
            if ($next === $text) {
                break;
            }
            $text = $next;
        }

        // [spoiler] / [spoiler=title]
        $text = (string) preg_replace_callback(
            '/\[spoiler(?:=([^\]]+))?\](.*?)\[\/spoiler\]/is',
            static function (array $m): string {
                $title = trim((string) ($m[1] ?? ''), " \t\"'");
                if ($title === '') {
                    $title = 'Spoiler';
                }
                $titleEsc = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $inner = (string) ($m[2] ?? '');

                return '<details class="ap-spoiler"><summary>' . $titleEsc . '</summary>' . $inner . '</details>';
            },
            $text
        );

        // [color=#hex|name]text[/color]
        $text = (string) preg_replace_callback(
            '/\[color=([^\]]+)\](.*?)\[\/color\]/is',
            static function (array $m): string {
                $color = trim((string) $m[1], " \t\"'");
                $inner = (string) $m[2];
                if (!self::isSafeColor($color)) {
                    return $inner;
                }
                $esc = htmlspecialchars($color, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

                return '<span style="color:' . $esc . '">' . $inner . '</span>';
            },
            $text
        );

        // [size=n] — map 1–7 (phpBB-style) or limited px to classes.
        $text = (string) preg_replace_callback(
            '/\[size=([^\]]+)\](.*?)\[\/size\]/is',
            static function (array $m): string {
                $size = trim((string) $m[1], " \t\"'");
                $inner = (string) $m[2];
                $class = self::sizeToClass($size);
                if ($class === '') {
                    return $inner;
                }

                return '<span class="' . $class . '">' . $inner . '</span>';
            },
            $text
        );

        // [list] / [list=1] with [*] items.
        $text = (string) preg_replace_callback(
            '/\[list(?:=([^\]]+))?\](.*?)\[\/list\]/is',
            static function (array $m): string {
                $type = trim((string) ($m[1] ?? ''), " \t\"'");
                $body = (string) ($m[2] ?? '');
                $items = preg_split('/\[\*\]/i', $body) ?: [];
                $lis = '';
                foreach ($items as $item) {
                    $item = trim($item);
                    if ($item === '') {
                        continue;
                    }
                    // Drop trailing newlines inside item.
                    $item = preg_replace('/\R+$/', '', $item) ?? $item;
                    $lis .= '<li>' . $item . '</li>';
                }
                if ($lis === '') {
                    return '';
                }
                if ($type !== '' && $type !== 'disc' && $type !== 'circle' && $type !== 'square') {
                    // Ordered list (1, a, A, i, I).
                    $olType = in_array($type, ['1', 'a', 'A', 'i', 'I'], true) ? $type : '1';

                    $typeAttr = htmlspecialchars($olType, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

                    return '<ol type="' . $typeAttr . '">' . $lis . '</ol>';
                }

                return '<ul>' . $lis . '</ul>';
            },
            $text
        );

        return $text;
    }

    private static function isSafeColor(string $color): bool
    {
        $color = trim($color);
        if ($color === '') {
            return false;
        }
        // #rgb #rrggbb
        if (preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $color) === 1) {
            return true;
        }
        // Named CSS colors (basic set).
        $named = [
            'black', 'silver', 'gray', 'grey', 'white', 'maroon', 'red', 'purple',
            'fuchsia', 'green', 'lime', 'olive', 'yellow', 'navy', 'blue', 'teal',
            'aqua', 'orange', 'navy', 'indigo', 'violet', 'pink', 'brown', 'cyan',
            'magenta', 'transparent',
        ];

        return in_array(strtolower($color), $named, true);
    }

    private static function sizeToClass(string $size): string
    {
        $size = trim($size);
        if ($size === '') {
            return '';
        }
        // phpBB 1–7 scale.
        if (preg_match('/^[1-7]$/', $size) === 1) {
            return 'ap-size-' . $size;
        }
        // Limited px.
        if (preg_match('/^(\d{1,2})px$/i', $size, $m) === 1) {
            $px = (int) $m[1];
            if ($px >= 8 && $px <= 36) {
                return 'ap-size-px-' . $px;
            }
        }

        return '';
    }

    // -------------------------------------------------------------------------
    // Markdown (lightweight subset)
    // -------------------------------------------------------------------------

    private static function convertMarkdown(string $text): string
    {
        // Horizontal rules.
        $text = (string) preg_replace('/^(?:-{3,}|\*{3,}|_{3,})[ \t]*$/m', '<hr>', $text);

        // ATX headings # .. ######
        $text = (string) preg_replace_callback(
            '/^(#{1,6})[ \t]+(.+?)[ \t]*#*[ \t]*$/m',
            static function (array $m): string {
                $level = strlen($m[1]);
                $inner = trim((string) $m[2]);

                return '<h' . $level . '>' . $inner . '</h' . $level . '>';
            },
            $text
        );

        // Blockquotes (single-level; consecutive > lines merge).
        $text = (string) preg_replace_callback(
            '/(?:^> ?.*(?:\R|$))+/m',
            static function (array $m): string {
                $block = (string) $m[0];
                $lines = preg_split('/\R/', rtrim($block, "\r\n")) ?: [];
                $inner = [];
                foreach ($lines as $line) {
                    $inner[] = preg_replace('/^>[ \t]?/', '', $line) ?? $line;
                }

                return '<blockquote>' . implode("\n", $inner) . '</blockquote>';
            },
            $text
        );

        // Unordered lists (- * +).
        $text = (string) preg_replace_callback(
            '/(?:^(?:[\-\*\+])[ \t]+.+(?:\R|$))+/m',
            static function (array $m): string {
                $lines = preg_split('/\R/', rtrim((string) $m[0], "\r\n")) ?: [];
                $lis = '';
                foreach ($lines as $line) {
                    if (preg_match('/^[\-\*\+][ \t]+(.+)$/', $line, $im) !== 1) {
                        continue;
                    }
                    $lis .= '<li>' . $im[1] . '</li>';
                }

                return $lis !== '' ? '<ul>' . $lis . '</ul>' : (string) $m[0];
            },
            $text
        );

        // Ordered lists (1. item).
        $text = (string) preg_replace_callback(
            '/(?:^(?:\d+)\.[ \t]+.+(?:\R|$))+/m',
            static function (array $m): string {
                $lines = preg_split('/\R/', rtrim((string) $m[0], "\r\n")) ?: [];
                $lis = '';
                foreach ($lines as $line) {
                    if (preg_match('/^\d+\.[ \t]+(.+)$/', $line, $im) !== 1) {
                        continue;
                    }
                    $lis .= '<li>' . $im[1] . '</li>';
                }

                return $lis !== '' ? '<ol>' . $lis . '</ol>' : (string) $m[0];
            },
            $text
        );

        // Images ![alt](url)
        $text = (string) preg_replace_callback(
            '/!\[([^\]]*)\]\(([^)\s]+)(?:\s+"([^"]*)")?\)/',
            static function (array $m): string {
                $alt = htmlspecialchars((string) $m[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $url = trim((string) $m[2]);
                if (!self::isSafeUrl($url) || preg_match('#^mailto:#i', $url) === 1) {
                    return $alt;
                }
                $esc = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $title = isset($m[3]) && $m[3] !== ''
                    ? ' title="' . htmlspecialchars((string) $m[3], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"'
                    : '';

                return '<img src="' . $esc . '" alt="' . $alt . '"' . $title . '>';
            },
            $text
        );

        // Links [text](url)
        $text = (string) preg_replace_callback(
            '/\[([^\]]+)\]\(([^)\s]+)(?:\s+"([^"]*)")?\)/',
            static function (array $m): string {
                $label = (string) $m[1];
                $url = trim((string) $m[2]);
                if (!self::isSafeUrl($url)) {
                    return htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                }
                $esc = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $title = isset($m[3]) && $m[3] !== ''
                    ? ' title="' . htmlspecialchars((string) $m[3], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"'
                    : '';

                return '<a href="' . $esc . '" rel="nofollow ugc"' . $title . '>' . $label . '</a>';
            },
            $text
        );

        // Bold **text** or __text__
        $text = (string) preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $text);
        $text = (string) preg_replace('/__(.+?)__/s', '<strong>$1</strong>', $text);

        // Italic *text* or _text_ (avoid matching inside words for _)
        $text = (string) preg_replace('/\*(?!\s)(.+?)(?<!\s)\*/s', '<em>$1</em>', $text);
        $text = (string) preg_replace('/(?<![A-Za-z0-9])_(?!\s)(.+?)(?<!\s)_(?![A-Za-z0-9])/s', '<em>$1</em>', $text);

        // Strikethrough ~~text~~
        $text = (string) preg_replace('/~~(.+?)~~/s', '<del>$1</del>', $text);

        return $text;
    }

    /**
     * Wrap consecutive non-block lines into <p> and turn single newlines into <br>.
     */
    private static function wrapLooseParagraphs(string $text): string
    {
        // Normalize line endings.
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        // Already mostly block-level? Split on blank lines.
        $blocks = preg_split('/\n{2,}/', $text) ?: [$text];
        $out = [];
        $blockTags = 'pre|blockquote|ul|ol|h[1-6]|hr|table|div|details|p';

        foreach ($blocks as $block) {
            $block = trim($block);
            if ($block === '') {
                continue;
            }
            // If block already starts with a block-level tag, leave it.
            if (preg_match('/^<\/?(?:' . $blockTags . ')\b/i', $block) === 1) {
                $out[] = $block;
                continue;
            }
            // Escape raw < that is not already a tag we will kses (leave existing tags).
            $escaped = self::escapeOutsideTags($block);
            $escaped = str_replace("\n", "<br>\n", $escaped);
            $out[] = '<p>' . $escaped . '</p>';
        }

        return implode("\n", $out);
    }

    /**
     * Escape text outside of existing HTML tags (for mixed markup).
     */
    private static function escapeOutsideTags(string $text): string
    {
        $parts = preg_split('/(<[^>]+>)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        $out = '';
        foreach ($parts as $i => $part) {
            if ($i % 2 === 1) {
                // Tag — keep as-is (kses will scrub later).
                $out .= $part;
            } else {
                $out .= htmlspecialchars($part, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
        }

        return $out;
    }

    // -------------------------------------------------------------------------
    // Attribute filtering
    // -------------------------------------------------------------------------

    /**
     * @param array<string, bool> $attrSpec
     */
    private static function filterAttributes(string $tag, string $rawAttrs, array $attrSpec): string
    {
        if ($rawAttrs === '' || $attrSpec === []) {
            return '';
        }

        $out = '';
        $attrPattern = '/([a-zA-Z_:][-a-zA-Z0-9_:.]*)\s*=\s*'
            . '(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+))/';
        $matched = preg_match_all($attrPattern, $rawAttrs, $matches, PREG_SET_ORDER);
        if ($matched === false || $matches === []) {
            // Boolean attributes without values (e.g. open on details).
            if (preg_match_all('/\b([a-zA-Z_:][-a-zA-Z0-9_:.]*)\b/', $rawAttrs, $bm) > 0) {
                foreach ($bm[1] as $name) {
                    $name = strtolower((string) $name);
                    if ($name === 'open' && isset($attrSpec['open'])) {
                        $out .= ' open';
                    }
                }
            }

            return $out;
        }

        foreach ($matches as $m) {
            $name = strtolower((string) $m[1]);
            $dq = (string) ($m[2] ?? '');
            $sq = (string) ($m[3] ?? '');
            $uq = (string) ($m[4] ?? '');
            $value = $dq !== '' ? $dq : ($sq !== '' ? $sq : $uq);

            // Never allow event handlers or style by default.
            if (str_starts_with($name, 'on') || $name === 'style' || $name === 'srcset') {
                // Allow style only for color on span from our BBCode converter:
                if ($name === 'style' && $tag === 'span' && self::isSafeInlineStyle($value)) {
                    $esc = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                    $out .= ' style="' . $esc . '"';
                }
                continue;
            }

            if (!isset($attrSpec[$name]) || !$attrSpec[$name]) {
                continue;
            }

            if (in_array($name, ['href', 'src', 'cite'], true)) {
                if (!self::isSafeUrl($value)) {
                    continue;
                }
                // img src: no mailto.
                if ($name === 'src' && preg_match('#^mailto:#i', $value) === 1) {
                    continue;
                }
            }

            if ($name === 'target') {
                // Only allow _blank; force rel safety later.
                if (strtolower($value) !== '_blank') {
                    continue;
                }
                $value = '_blank';
            }

            if ($name === 'class' || $name === 'id') {
                // Restrict to safe characters.
                if (preg_match('/[^a-zA-Z0-9_\-\s]/', $value) === 1) {
                    continue;
                }
            }

            if (in_array($name, ['width', 'height'], true) && preg_match('/^\d{1,4}%?$/', $value) !== 1) {
                continue;
            }

            $esc = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $out .= ' ' . $name . '="' . $esc . '"';
        }

        // If target=_blank, ensure rel has noopener noreferrer.
        if (str_contains($out, 'target="_blank"') && !str_contains($out, ' rel=')) {
            $out .= ' rel="noopener noreferrer"';
        } elseif (str_contains($out, 'target="_blank"') && preg_match('/\srel="([^"]*)"/', $out, $rm) === 1) {
            $rel = $rm[1];
            $parts = preg_split('/\s+/', $rel) ?: [];
            foreach (['noopener', 'noreferrer'] as $req) {
                if (!in_array($req, $parts, true)) {
                    $parts[] = $req;
                }
            }
            $out = (string) preg_replace(
                '/\srel="[^"]*"/',
                ' rel="' . htmlspecialchars(implode(' ', $parts), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"',
                $out,
                1
            );
        }

        return $out;
    }

    private static function isSafeInlineStyle(string $style): bool
    {
        $style = trim($style);
        // Only color: #hex or named.
        if (preg_match('/^color\s*:\s*([^;]+);?$/i', $style, $m) !== 1) {
            return false;
        }

        return self::isSafeColor(trim($m[1]));
    }
}
