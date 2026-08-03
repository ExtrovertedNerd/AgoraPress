<?php

/**
 * AgoraPress escaping and sanitization helpers.
 *
 * Central implementation for output escaping (HTML, attributes, URLs, JS, XML)
 * and input sanitization (text fields, email, keys, filenames, colors, etc.).
 * Procedural wrappers live in functions.php (`ap_esc_*` / `ap_sanitize_*`).
 *
 * Design goals (SPEC §5 Security Model):
 * - Escape on output by context (never rely on “trusted” DB alone)
 * - Sanitize on input for storage / comparison
 * - Reject dangerous URL schemes (javascript:, data:, vbscript:, …)
 * - UTF-8 safe; strip null bytes and control characters where appropriate
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * Escaping and sanitization utilities.
 */
class AP_Formatting
{
    /**
     * Protocols allowed in {@see escUrl()} / {@see escUrlRaw()} by default.
     *
     * @return list<string>
     */
    public static function allowedProtocols(): array
    {
        $protocols = ['http', 'https', 'mailto', 'ftp', 'ftps', 'tel', 'sms'];

        if (function_exists('ap_apply_filters')) {
            /** @var list<string> $protocols */
            $protocols = ap_apply_filters('ap_allowed_protocols', $protocols);
        }

        return $protocols;
    }

    // -------------------------------------------------------------------------
    // Output escaping
    // -------------------------------------------------------------------------

    /**
     * Escape text for HTML body content.
     */
    public static function escHtml(string $text): string
    {
        return htmlspecialchars(self::checkUtf8($text), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Escape text for HTML attribute values (double- or single-quoted).
     */
    public static function escAttr(string $text): string
    {
        return htmlspecialchars(self::checkUtf8($text), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Escape text for use inside a &lt;textarea&gt; (same encoding as HTML body).
     */
    public static function escTextarea(string $text): string
    {
        return self::escHtml($text);
    }

    /**
     * Escape a URL for use in href/src attributes (or other HTML contexts).
     *
     * Rejects empty results, control characters, and disallowed schemes
     * (e.g. javascript:, data:). Relative paths, fragments, and query-only
     * URLs are allowed. When $display is true (default), the result is also
     * HTML-entity encoded for safe attribute embedding.
     *
     * @param list<string>|null $protocols Allowed schemes; null = {@see allowedProtocols()}
     */
    public static function escUrl(string $url, ?array $protocols = null, bool $display = true): string
    {
        $clean = self::cleanUrl($url, $protocols);
        if ($clean === '') {
            return '';
        }

        if ($display) {
            return htmlspecialchars($clean, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        return $clean;
    }

    /**
     * Sanitize a URL for storage / redirects / HTTP clients (no HTML entity encoding).
     *
     * @param list<string>|null $protocols
     */
    public static function escUrlRaw(string $url, ?array $protocols = null): string
    {
        return self::escUrl($url, $protocols, false);
    }

    /**
     * Escape text for embedding inside a JavaScript string literal.
     *
     * Produces a sequence safe between single or double quotes (no surrounding quotes).
     */
    public static function escJs(string $text): string
    {
        $text = self::checkUtf8($text);
        $json = json_encode(
            $text,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
        );
        if (!is_string($json) || $json === '') {
            return '';
        }

        // Strip surrounding JSON quotes.
        return substr($json, 1, -1);
    }

    /**
     * Escape text for XML / RSS / Atom character data and attributes.
     */
    public static function escXml(string $text): string
    {
        return htmlspecialchars(self::checkUtf8($text), ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    // -------------------------------------------------------------------------
    // Input sanitization
    // -------------------------------------------------------------------------

    /**
     * Sanitize a single-line text field: strip tags, collapse whitespace, trim.
     */
    public static function sanitizeTextField(string $value): string
    {
        $value = self::stripAllTags($value);
        $value = self::checkUtf8($value);
        $value = preg_replace('/[\r\n\t\0]+/', ' ', $value) ?? $value;
        $value = preg_replace('/[ ]{2,}/', ' ', $value) ?? $value;

        return trim($value);
    }

    /**
     * Sanitize multiline text: strip tags, keep newlines, trim ends.
     */
    public static function sanitizeTextareaField(string $value): string
    {
        $value = self::stripAllTags($value);
        $value = self::checkUtf8($value);
        $value = str_replace("\0", '', $value);

        return trim($value);
    }

    /**
     * Sanitize an email address (empty string when invalid).
     */
    public static function sanitizeEmail(string $email): string
    {
        $email = trim(self::checkUtf8($email));
        $email = str_replace(["\r", "\n", "\0", "\t"], '', $email);
        if ($email === '') {
            return '';
        }

        $filtered = filter_var($email, FILTER_SANITIZE_EMAIL);
        if (!is_string($filtered) || $filtered === '') {
            return '';
        }

        if (filter_var($filtered, FILTER_VALIDATE_EMAIL) === false) {
            return '';
        }

        return strtolower($filtered);
    }

    /**
     * Sanitize a key: lowercase alphanumeric, underscores, and hyphens only.
     */
    public static function sanitizeKey(string $key): string
    {
        $key = strtolower(self::checkUtf8($key));
        $key = preg_replace('/[^a-z0-9_\-]/', '', $key) ?? '';

        return $key;
    }

    /**
     * Sanitize a single HTML class token (lowercase; letters, digits, _ -).
     */
    public static function sanitizeHtmlClass(string $class): string
    {
        $class = strtolower(trim(self::checkUtf8($class)));
        $class = preg_replace('/[^a-z0-9_\-]/', '', $class) ?? '';

        return $class;
    }

    /**
     * Sanitize a client-supplied filename (basename only; keeps a safe extension).
     */
    public static function sanitizeFileName(string $filename): string
    {
        $filename = str_replace(['\\', "\0"], ['/', ''], self::checkUtf8($filename));
        $filename = basename($filename);
        $filename = preg_replace('/[\x00-\x1F\x7F]/', '', $filename) ?? $filename;
        // Spaces and runs of unsafe chars → hyphens.
        $filename = preg_replace('/[^\w.\-]+/u', '-', $filename) ?? $filename;
        $filename = preg_replace('/\-+/', '-', $filename) ?? $filename;
        $filename = trim($filename, '.-');

        if ($filename === '' || $filename === '.' || $filename === '..') {
            return 'file';
        }

        // Block double extensions that hide scripts (e.g. evil.php.jpg handled by media).
        // Here we only produce a safe basename shape.
        return $filename;
    }

    /**
     * Sanitize a hex color (#rgb or #rrggbb). Returns empty string when invalid.
     */
    public static function sanitizeHexColor(string $color): string
    {
        $color = trim(self::checkUtf8($color));
        if ($color === '') {
            return '';
        }
        if ($color[0] !== '#') {
            $color = '#' . $color;
        }
        if (preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $color) !== 1) {
            return '';
        }

        return strtolower($color);
    }

    /**
     * Sanitize a username (login): strip tags, remove invalid characters.
     *
     * Strict mode keeps only [a-z0-9 _.\-@] after lowercasing.
     */
    public static function sanitizeUser(string $username, bool $strict = false): string
    {
        $username = self::stripAllTags($username);
        $username = self::checkUtf8($username);
        $username = self::removeAccents($username);
        $username = preg_replace('/[\r\n\t\0]+/', '', $username) ?? $username;
        $username = trim($username);

        if ($strict) {
            $username = strtolower($username);
            $username = preg_replace('/[^a-z0-9 _.\-@]/', '', $username) ?? $username;
            $username = preg_replace('/\s+/', ' ', $username) ?? $username;
            $username = trim($username);
        }

        return $username;
    }

    /**
     * Non-negative integer (0 when value is not a positive numeric string/int).
     */
    public static function absint(mixed $value): int
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if (is_int($value)) {
            return $value < 0 ? 0 : $value;
        }
        if (is_float($value)) {
            if (!is_finite($value) || $value < 0) {
                return 0;
            }

            return (int) floor($value);
        }
        if (is_string($value)) {
            $value = trim($value);
            if ($value === '' || !is_numeric($value)) {
                return 0;
            }
            $int = (int) $value;

            return $int < 0 ? 0 : $int;
        }

        return 0;
    }

    /**
     * Strip HTML tags and script/style blocks.
     */
    public static function stripAllTags(string $value, bool $removeBreaks = false): string
    {
        $value = self::checkUtf8($value);
        $value = preg_replace('@<(script|style)[^>]*?>.*?</\1>@si', '', $value) ?? $value;
        $value = strip_tags($value);
        if ($removeBreaks) {
            $value = preg_replace('/[\r\n\t ]+/', ' ', $value) ?? $value;
        }

        return $value;
    }

    /**
     * Whether a URL is safe for href/src in general HTML (delegates to content format when present).
     */
    public static function isSafeUrl(string $url): bool
    {
        if (class_exists('AP_Content_Format', false)) {
            return AP_Content_Format::isSafeUrl($url);
        }

        return self::cleanUrl($url) !== '';
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * Clean and validate a URL; return '' when unsafe / empty.
     *
     * @param list<string>|null $protocols
     */
    public static function cleanUrl(string $url, ?array $protocols = null): string
    {
        $url = self::checkUtf8($url);
        $url = trim($url);
        $url = str_replace(["\r", "\n", "\t", "\0", ' '], '', $url);

        if ($url === '') {
            return '';
        }

        // Normalize common entity / whitespace tricks before scheme detection.
        $decoded = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $decoded = preg_replace('/\s+/', '', $decoded) ?? $decoded;
        $probe = strtolower($decoded);

        // Explicitly block dangerous schemes (even if they appear without a proper colon form).
        if (preg_match('/^(?:javascript|vbscript|data)\s*:/i', $probe) === 1) {
            return '';
        }

        $protocols = $protocols ?? self::allowedProtocols();
        $protocols = array_map('strtolower', $protocols);

        // Absolute or scheme-relative.
        if (preg_match('#^([a-z][a-z0-9+.\-]*):#i', $decoded, $m) === 1) {
            $scheme = strtolower($m[1]);
            if (!in_array($scheme, $protocols, true)) {
                return '';
            }
            // Prefer the original trimmed form with control chars already stripped.
            $url = self::stripUrlUnsafeChars($url);

            return $url;
        }

        // Protocol-relative //host/path
        if (str_starts_with($decoded, '//')) {
            return self::stripUrlUnsafeChars($url);
        }

        // Relative path, query, or fragment.
        if (
            str_starts_with($decoded, '/')
            || str_starts_with($decoded, '?')
            || str_starts_with($decoded, '#')
            || str_starts_with($decoded, './')
            || str_starts_with($decoded, '../')
            || preg_match('#^[a-zA-Z0-9_@%+\-.,~]+#', $decoded) === 1
        ) {
            // Still reject if it smuggles a scheme later (rare) or leading javascript word.
            if (preg_match('/^(javascript|vbscript|data)\b/i', $probe) === 1) {
                return '';
            }

            return self::stripUrlUnsafeChars($url);
        }

        return '';
    }

    /**
     * Ensure string is valid UTF-8; replace invalid sequences.
     */
    public static function checkUtf8(string $text): string
    {
        if ($text === '') {
            return '';
        }

        // Remove null bytes early.
        if (str_contains($text, "\0")) {
            $text = str_replace("\0", '', $text);
        }

        if (function_exists('mb_check_encoding') && !mb_check_encoding($text, 'UTF-8')) {
            if (function_exists('mb_convert_encoding')) {
                $converted = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
                if (is_string($converted)) {
                    return $converted;
                }
            }

            return iconv('UTF-8', 'UTF-8//IGNORE', $text) ?: '';
        }

        return $text;
    }

    /**
     * Strip characters that must never appear in a URL attribute.
     */
    private static function stripUrlUnsafeChars(string $url): string
    {
        $url = str_replace(["\r", "\n", "\t", "\0", '"', "'", '<', '>', ' '], '', $url);

        return $url;
    }

    /**
     * Best-effort accent stripping (intl Transliterator or iconv); identity fallback.
     */
    private static function removeAccents(string $text): string
    {
        if ($text === '') {
            return '';
        }

        if (function_exists('transliterator_transliterate')) {
            $out = transliterator_transliterate('Any-Latin; Latin-ASCII', $text);
            if (is_string($out) && $out !== '') {
                return $out;
            }
        }

        if (function_exists('iconv')) {
            $out = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
            if (is_string($out) && $out !== '') {
                return $out;
            }
        }

        return $text;
    }
}
