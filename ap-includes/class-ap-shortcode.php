<?php

/**
 * AgoraPress Shortcode API.
 *
 * Register callbacks for `[tag]` / `[tag attr="val"]` / `[tag]content[/tag]`
 * and expand them in post content. Escaped shortcodes use double brackets
 * (`[[tag]]` → `[tag]`).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * Shortcode registry and content processor.
 */
class AP_Shortcode
{
    /**
     * Registered tags: tag => callback.
     *
     * Callback signature: function(array $atts, ?string $content, string $tag): string
     *
     * @var array<string, callable>
     */
    private static array $tags = [];

    /**
     * Register a shortcode handler.
     *
     * @param callable $callback Receives ($atts, $content, $tag).
     */
    public static function add(string $tag, callable $callback): void
    {
        $tag = self::normalizeTag($tag);
        if ($tag === '') {
            return;
        }
        self::$tags[$tag] = $callback;
    }

    /**
     * Remove a shortcode handler.
     */
    public static function remove(string $tag): void
    {
        $tag = self::normalizeTag($tag);
        unset(self::$tags[$tag]);
    }

    /**
     * Whether a tag is registered.
     */
    public static function exists(string $tag): bool
    {
        $tag = self::normalizeTag($tag);

        return $tag !== '' && isset(self::$tags[$tag]);
    }

    /**
     * Registered tag names.
     *
     * @return list<string>
     */
    public static function tags(): array
    {
        $keys = array_keys(self::$tags);
        sort($keys);

        return $keys;
    }

    /**
     * Expand all registered shortcodes in $content.
     *
     * Handlers may return HTML; callers that display user content should use
     * formatContent() so surrounding text is escaped while shortcode output
     * is left intact.
     */
    public static function doShortcode(string $content): string
    {
        if ($content === '' || self::$tags === []) {
            return self::unescapeEscaped($content);
        }

        // Protect [[escaped]] forms first.
        $content = self::protectEscaped($content);

        $pattern = self::getRegex();
        if ($pattern === '') {
            return self::restoreEscaped(self::unescapeEscaped($content));
        }

        $content = (string) preg_replace_callback(
            $pattern,
            [self::class, 'renderMatch'],
            $content
        );

        return self::restoreEscaped($content);
    }

    /**
     * Format content for display: expand shortcodes, escape plain text, nl2br.
     *
     * Shortcode callback output is treated as trusted HTML (handlers should
     * escape attributes and untrusted user input themselves).
     */
    public static function formatContent(string $content): string
    {
        if ($content === '') {
            return '';
        }

        if (self::$tags === [] || !self::contentHasShortcode($content)) {
            return self::escapePlain(self::unescapeEscaped($content));
        }

        $placeholders = [];
        $i = 0;

        // Protect escaped shortcodes so they render as literal [tag].
        $content = self::protectEscaped($content);

        $pattern = self::getRegex();
        if ($pattern === '') {
            return self::escapePlain(self::restoreEscaped(self::unescapeEscaped($content)));
        }

        $withTokens = (string) preg_replace_callback(
            $pattern,
            static function (array $m) use (&$placeholders, &$i): string {
                $html = self::renderMatch($m);
                $token = 'APSC' . $i . 'Z' . dechex(crc32($html . $i)) . 'END';
                $placeholders[$token] = $html;
                $i++;

                return $token;
            },
            $content
        );

        $withTokens = self::restoreEscaped($withTokens);
        $escaped = self::escapePlain($withTokens);

        if ($placeholders !== []) {
            $escaped = str_replace(array_keys($placeholders), array_values($placeholders), $escaped);
        }

        return $escaped;
    }

    /**
     * Strip all registered shortcodes from content (leave inner content of enclosures).
     */
    public static function strip(string $content): string
    {
        if ($content === '' || self::$tags === []) {
            return self::unescapeEscaped($content);
        }

        $content = self::protectEscaped($content);
        $pattern = self::getRegex();
        if ($pattern === '') {
            return self::restoreEscaped(self::unescapeEscaped($content));
        }

        $content = (string) preg_replace_callback(
            $pattern,
            static function (array $m): string {
                // Self-closing / no content.
                if (($m[5] ?? '') === '' && ($m[6] ?? '/') === '/') {
                    return '';
                }
                // Enclosure: keep inner content (strip nested later via recursion).
                $inner = (string) ($m[5] ?? '');

                return $inner;
            },
            $content
        );

        // Nested shortcodes: strip again if any remain.
        if (self::contentHasShortcode($content)) {
            $content = self::strip($content);
        }

        return self::restoreEscaped($content);
    }

    /**
     * Whether content contains a specific registered shortcode.
     */
    public static function has(string $content, string $tag): bool
    {
        $tag = self::normalizeTag($tag);
        if ($tag === '' || $content === '') {
            return false;
        }

        return (bool) preg_match(
            '/\[' . preg_quote($tag, '/') . '(?:[\s\]\/]|$)/i',
            $content
        );
    }

    /**
     * Parse shortcode attribute string into an associative array.
     *
     * Supports attr="value", attr='value', attr=value, and positional values.
     *
     * @return array<string, string>
     */
    public static function parseAtts(string $text): array
    {
        $atts = [];
        $pattern = '/([\w-]+)\s*=\s*"([^"]*)"|([\w-]+)\s*=\s*\'([^\']*)\'|([\w-]+)\s*=\s*([^\s\'"\]]+)|"([^"]*)"|\'([^\']*)\'|(\S+)/';
        if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER) === false) {
            return [];
        }

        foreach ($matches as $m) {
            if (($m[1] ?? '') !== '') {
                $atts[strtolower($m[1])] = self::decodeAttr($m[2]);
            } elseif (($m[3] ?? '') !== '') {
                $atts[strtolower($m[3])] = self::decodeAttr($m[4]);
            } elseif (($m[5] ?? '') !== '') {
                $atts[strtolower($m[5])] = self::decodeAttr($m[6]);
            } elseif (($m[7] ?? '') !== '') {
                $atts[] = self::decodeAttr($m[7]);
            } elseif (($m[8] ?? '') !== '') {
                $atts[] = self::decodeAttr($m[8]);
            } elseif (($m[9] ?? '') !== '') {
                $atts[] = self::decodeAttr($m[9]);
            }
        }

        return $atts;
    }

    /**
     * Merge user attributes with defaults (string keys only for defaults).
     *
     * @param array<string, string> $pairs   Defaults.
     * @param array<string, string> $atts    User attributes.
     *
     * @return array<string, string>
     */
    public static function atts(array $pairs, array $atts): array
    {
        $out = $pairs;
        foreach ($atts as $key => $value) {
            if (is_string($key) && array_key_exists($key, $pairs)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * Clear registry (unit tests).
     */
    public static function flush(): void
    {
        self::$tags = [];
    }

    /**
     * Register built-in shortcodes (idempotent).
     */
    public static function registerCore(): void
    {
        if (!self::exists('year')) {
            self::add('year', static function (): string {
                return date('Y');
            });
        }
        if (!self::exists('site_name')) {
            self::add('site_name', static function (): string {
                $name = 'AgoraPress';
                if (function_exists('ap_get_option')) {
                    $name = (string) ap_get_option('blogname', $name);
                } elseif (class_exists('AP_Options', false)) {
                    $name = (string) AP_Options::get('blogname', $name);
                }

                return function_exists('ap_esc_html')
                    ? ap_esc_html($name)
                    : htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            });
        }
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * @param array<int, string> $m
     */
    private static function renderMatch(array $m): string
    {
        // $m[1] optional escape [, $m[2] tag, $m[3] atts/self-close, $m[5] content, $m[6] /
        if (($m[1] ?? '') === '[' && ($m[6] ?? '') === ']') {
            // Fully escaped [[tag]] — should have been protected; fallback.
            return substr($m[0], 1, -1);
        }

        $tag = strtolower((string) ($m[2] ?? ''));
        if ($tag === '' || !isset(self::$tags[$tag])) {
            return (string) ($m[0] ?? '');
        }

        $attrString = trim((string) ($m[3] ?? ''));
        // Strip trailing slash from self-closing form.
        $attrString = rtrim($attrString);
        if (str_ends_with($attrString, '/')) {
            $attrString = rtrim(substr($attrString, 0, -1));
        }

        $atts = $attrString !== '' ? self::parseAtts($attrString) : [];
        $inner = array_key_exists(5, $m) && $m[5] !== '' ? (string) $m[5] : null;

        // Nested shortcodes inside enclosures.
        if ($inner !== null && $inner !== '' && self::contentHasShortcode($inner)) {
            $inner = self::doShortcode($inner);
        }

        try {
            $result = call_user_func(self::$tags[$tag], $atts, $inner, $tag);
        } catch (Throwable) {
            return '';
        }

        return is_string($result) || is_numeric($result) ? (string) $result : '';
    }

    private static function getRegex(): string
    {
        $tags = array_keys(self::$tags);
        if ($tags === []) {
            return '';
        }

        $names = array_map(static fn (string $t): string => preg_quote($t, '/'), $tags);
        $tagList = implode('|', $names);

        // Groups: 1=escape [, 2=tag, 3=attrs+/, 5=content, 6=closing /
        // Matches [tag], [tag /], [tag a="b"], [tag]x[/tag]
        return '/\[(\[?)('
            . $tagList
            . ')(?![\w-])([^\]\/]*(?:\/(?!\])[^\]\/]*)*?)(?:(\/)\]|\](?:([^\[]*+(?:\[(?!\/\2\])[^\[]*+)*+)\[\/\2\])?)(\]?)/s';
    }

    private static function contentHasShortcode(string $content): bool
    {
        foreach (array_keys(self::$tags) as $tag) {
            if (self::has($content, $tag)) {
                return true;
            }
        }

        return false;
    }

    private static function normalizeTag(string $tag): string
    {
        $tag = strtolower(trim($tag));
        if ($tag === '' || preg_match('/^[a-z][a-z0-9_-]*$/', $tag) !== 1) {
            return '';
        }

        return $tag;
    }

    private static function decodeAttr(string $value): string
    {
        return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private static function escapePlain(string $content): string
    {
        $escaped = function_exists('ap_esc_html')
            ? ap_esc_html($content)
            : htmlspecialchars($content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return nl2br($escaped, false);
    }

    /**
     * Temporarily replace [[...]] with placeholders so they are not expanded.
     */
    private static function protectEscaped(string $content): string
    {
        return (string) preg_replace_callback(
            '/\[\[([^\]]+)\]\]/',
            static function (array $m): string {
                return 'APESCX' . base64_encode($m[1]) . 'XEND';
            },
            $content
        );
    }

    private static function restoreEscaped(string $content): string
    {
        return (string) preg_replace_callback(
            '/APESCX([A-Za-z0-9+\/=]+)XEND/',
            static function (array $m): string {
                $decoded = base64_decode($m[1], true);
                if (!is_string($decoded)) {
                    return $m[0];
                }

                return '[' . $decoded . ']';
            },
            $content
        );
    }

    /**
     * Convert leftover [[tag]] forms to [tag] when no protection ran.
     */
    private static function unescapeEscaped(string $content): string
    {
        return (string) preg_replace('/\[\[([^\]]+)\]\]/', '[$1]', $content);
    }
}
