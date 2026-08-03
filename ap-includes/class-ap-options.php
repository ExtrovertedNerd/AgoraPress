<?php

/**
 * AgoraPress Options API.
 *
 * Thin key/value layer over `{prefix}options`. Values are stored as strings;
 * arrays/objects are JSON-encoded. Familiar surface for classic WP developers
 * (`get_option` / `update_option` style) without forking WordPress.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * Site options read/write with optional request-local cache.
 */
class AP_Options
{
    /** @var array<string, mixed> Request-local cache (name => value|null sentinel). */
    private static array $cache = [];

    /** Sentinel for "not found" vs cached false/null. */
    private const MISS = "\0ap_opt_miss";

    /**
     * Read an option.
     *
     * @param mixed $default Returned when missing.
     *
     * @return mixed
     */
    public static function get(string $name, mixed $default = false, ?AP_DB $db = null): mixed
    {
        $name = self::normalizeName($name);
        if ($name === '') {
            return $default;
        }

        if (array_key_exists($name, self::$cache)) {
            $cached = self::$cache[$name];

            return $cached === self::MISS ? $default : $cached;
        }

        $db = self::resolveDb($db);
        if ($db === null) {
            return $default;
        }

        try {
            $raw = $db->getVar(
                'SELECT option_value FROM ' . $db->quoteIdentifier($db->table('options'))
                . ' WHERE option_name = ? LIMIT 1',
                [$name]
            );
        } catch (Throwable) {
            self::$cache[$name] = self::MISS;

            return $default;
        }

        if ($raw === null) {
            self::$cache[$name] = self::MISS;

            return $default;
        }

        $value = self::maybeDecode((string) $raw);
        self::$cache[$name] = $value;

        return $value;
    }

    /**
     * Insert or update an option.
     *
     * @param mixed $value Scalar, array, or object (JSON for non-scalars).
     */
    public static function update(string $name, mixed $value, ?AP_DB $db = null, string $autoload = 'yes'): bool
    {
        $name = self::normalizeName($name);
        if ($name === '') {
            return false;
        }

        $db = self::resolveDb($db);
        if ($db === null) {
            return false;
        }

        $stored = self::maybeEncode($value);
        $autoload = $autoload === 'no' ? 'no' : 'yes';

        try {
            $existing = $db->getVar(
                'SELECT option_id FROM ' . $db->quoteIdentifier($db->table('options'))
                . ' WHERE option_name = ? LIMIT 1',
                [$name]
            );

            if ($existing !== null) {
                $ok = $db->update(
                    'options',
                    ['option_value' => $stored, 'autoload' => $autoload],
                    ['option_name' => $name]
                ) !== false;
            } else {
                $ok = $db->insert('options', [
                    'option_name' => $name,
                    'option_value' => $stored,
                    'autoload' => $autoload,
                ]) !== false;
            }
        } catch (Throwable) {
            return false;
        }

        if ($ok) {
            self::$cache[$name] = $value;
        }

        return $ok;
    }

    /**
     * Insert only when the option does not already exist.
     *
     * @param mixed $value
     */
    public static function add(string $name, mixed $value, ?AP_DB $db = null, string $autoload = 'yes'): bool
    {
        $name = self::normalizeName($name);
        if ($name === '') {
            return false;
        }

        $db = self::resolveDb($db);
        if ($db === null) {
            return false;
        }

        try {
            $existing = $db->getVar(
                'SELECT option_id FROM ' . $db->quoteIdentifier($db->table('options'))
                . ' WHERE option_name = ? LIMIT 1',
                [$name]
            );
            if ($existing !== null) {
                return false;
            }

            $ok = $db->insert('options', [
                'option_name' => $name,
                'option_value' => self::maybeEncode($value),
                'autoload' => $autoload === 'no' ? 'no' : 'yes',
            ]) !== false;
        } catch (Throwable) {
            return false;
        }

        if ($ok) {
            self::$cache[$name] = $value;
        }

        return $ok;
    }

    /**
     * Delete an option by name.
     */
    public static function delete(string $name, ?AP_DB $db = null): bool
    {
        $name = self::normalizeName($name);
        if ($name === '') {
            return false;
        }

        $db = self::resolveDb($db);
        if ($db === null) {
            return false;
        }

        try {
            $ok = $db->delete('options', ['option_name' => $name]) !== false;
        } catch (Throwable) {
            return false;
        }

        if ($ok) {
            unset(self::$cache[$name]);
        }

        return $ok;
    }

    /**
     * Clear the request-local cache (tests / after bulk writes).
     */
    public static function flushCache(): void
    {
        self::$cache = [];
    }

    // -------------------------------------------------------------------------
    // Reading / front-page helpers
    // -------------------------------------------------------------------------

    /**
     * Whether the site front shows latest posts or a static page.
     *
     * @return 'posts'|'page'
     */
    public static function showOnFront(?AP_DB $db = null): string
    {
        $raw = (string) self::get('show_on_front', 'posts', $db);

        return $raw === 'page' ? 'page' : 'posts';
    }

    /**
     * Static front page ID (0 when not used).
     */
    public static function pageOnFront(?AP_DB $db = null): int
    {
        return max(0, (int) self::get('page_on_front', 0, $db));
    }

    /**
     * Posts page ID when a static front is used (0 when unused).
     */
    public static function pageForPosts(?AP_DB $db = null): int
    {
        return max(0, (int) self::get('page_for_posts', 0, $db));
    }

    /**
     * Blog index page size.
     */
    public static function postsPerPage(?AP_DB $db = null): int
    {
        $n = (int) self::get('posts_per_page', 10, $db);

        return $n > 0 ? $n : 10;
    }

    /**
     * Syndication feed item count.
     */
    public static function postsPerRss(?AP_DB $db = null): int
    {
        $n = (int) self::get('posts_per_rss', 10, $db);

        return $n > 0 ? $n : 10;
    }

    /**
     * Whether feed items use excerpts (true) or full text (false).
     */
    public static function rssUseExcerpt(?AP_DB $db = null): bool
    {
        return (string) self::get('rss_use_excerpt', '0', $db) === '1';
    }

    /**
     * Persist Reading settings (front page + feed).
     *
     * @param array{
     *   show_on_front?: string,
     *   page_on_front?: int,
     *   page_for_posts?: int,
     *   posts_per_page?: int,
     *   posts_per_rss?: int,
     *   rss_use_excerpt?: bool|int|string
     * } $settings
     */
    public static function updateReadingSettings(array $settings, ?AP_DB $db = null): bool
    {
        $show = isset($settings['show_on_front']) && (string) $settings['show_on_front'] === 'page'
            ? 'page'
            : 'posts';
        $pageOnFront = max(0, (int) ($settings['page_on_front'] ?? 0));
        $pageForPosts = max(0, (int) ($settings['page_for_posts'] ?? 0));

        // Homepage and posts page must not be the same page.
        if ($show === 'page' && $pageOnFront > 0 && $pageOnFront === $pageForPosts) {
            $pageForPosts = 0;
        }

        if ($show === 'posts') {
            $pageOnFront = 0;
            // Keep page_for_posts only when useful; clear when showing posts on front.
            $pageForPosts = 0;
        }

        $postsPerPage = max(1, min(100, (int) ($settings['posts_per_page'] ?? 10)));
        $postsPerRss = max(1, min(100, (int) ($settings['posts_per_rss'] ?? 10)));
        $excerptRaw = $settings['rss_use_excerpt'] ?? '0';
        $rssExcerpt = ($excerptRaw === true || $excerptRaw === 1 || $excerptRaw === '1') ? '1' : '0';

        $ok = true;
        $ok = self::update('show_on_front', $show, $db) && $ok;
        $ok = self::update('page_on_front', (string) $pageOnFront, $db) && $ok;
        $ok = self::update('page_for_posts', (string) $pageForPosts, $db) && $ok;
        $ok = self::update('posts_per_page', (string) $postsPerPage, $db) && $ok;
        $ok = self::update('posts_per_rss', (string) $postsPerRss, $db) && $ok;
        $ok = self::update('rss_use_excerpt', $rssExcerpt, $db) && $ok;

        return $ok;
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    private static function normalizeName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || strlen($name) > 191) {
            return '';
        }

        return $name;
    }

    /**
     * @return mixed
     */
    private static function maybeDecode(string $raw): mixed
    {
        $trim = ltrim($raw);
        if ($trim !== '' && ($trim[0] === '{' || $trim[0] === '[')) {
            $decoded = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        return $raw;
    }

    private static function maybeEncode(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if ($value === null) {
            return '';
        }

        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return is_string($json) ? $json : '';
    }

    private static function resolveDb(?AP_DB $db): ?AP_DB
    {
        if ($db instanceof AP_DB) {
            return $db;
        }
        if (function_exists('ap_db')) {
            try {
                return ap_db();
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }
}
