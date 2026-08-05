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

    /** Whether {@see loadAutoloaded()} has run this request. */
    private static bool $autoloaded = false;

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
     * Prime the request-local cache with every `autoload = yes` option in one query.
     *
     * Call once early in bootstrap so hot paths (site name, modules, permalinks)
     * avoid per-option SELECT round-trips. Safe to call multiple times — second
     * call is a no-op unless {@see flushCache()} ran.
     *
     * @return int Number of options loaded into cache (0 when already primed or no DB).
     */
    public static function loadAutoloaded(?AP_DB $db = null): int
    {
        if (self::$autoloaded) {
            return 0;
        }

        $db = self::resolveDb($db);
        if ($db === null) {
            return 0;
        }

        try {
            $rows = $db->getResults(
                'SELECT option_name, option_value FROM ' . $db->quoteIdentifier($db->table('options'))
                . ' WHERE autoload = ?',
                ['yes']
            );
        } catch (Throwable) {
            // Table may not exist yet (install / migration). Mark primed to avoid hammering.
            self::$autoloaded = true;

            return 0;
        }

        self::$autoloaded = true;
        if (!is_array($rows) || $rows === []) {
            return 0;
        }

        $loaded = 0;
        foreach ($rows as $row) {
            $data = is_array($row) ? $row : get_object_vars($row);
            $name = self::normalizeName((string) ($data['option_name'] ?? ''));
            if ($name === '') {
                continue;
            }
            // Do not overwrite values already set this request (e.g. tests / early writes).
            if (array_key_exists($name, self::$cache)) {
                continue;
            }
            self::$cache[$name] = self::maybeDecode((string) ($data['option_value'] ?? ''));
            $loaded++;
        }

        return $loaded;
    }

    /**
     * Whether autoload priming has run this request.
     */
    public static function isAutoloaded(): bool
    {
        return self::$autoloaded;
    }

    /**
     * Aggregate size of autoloaded options (performance budget).
     *
     * @return array{count: int, bytes: int}
     */
    public static function getAutoloadStats(?AP_DB $db = null): array
    {
        $db = self::resolveDb($db);
        if ($db === null) {
            return ['count' => 0, 'bytes' => 0];
        }

        try {
            $table = $db->quoteIdentifier($db->table('options'));
            // LENGTH works on MySQL/MariaDB/SQLite/PostgreSQL for byte-ish size of text.
            $row = $db->getRow(
                'SELECT COUNT(*) AS cnt, COALESCE(SUM(LENGTH(option_value)), 0) AS bytes'
                . ' FROM ' . $table . ' WHERE autoload = ?',
                ['yes'],
                PDO::FETCH_ASSOC
            );
        } catch (Throwable) {
            return ['count' => 0, 'bytes' => 0];
        }

        if (!is_array($row)) {
            return ['count' => 0, 'bytes' => 0];
        }

        return [
            'count' => max(0, (int) ($row['cnt'] ?? 0)),
            'bytes' => max(0, (int) ($row['bytes'] ?? 0)),
        ];
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
        self::$autoloaded = false;
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
    // Modules (Static Pages / Blog / Forum)
    // -------------------------------------------------------------------------

    /** Option keys for the three independent module toggles. */
    public const MODULE_STATIC_PAGES = 'ap_module_static_pages';

    public const MODULE_BLOG = 'ap_module_blog';

    public const MODULE_FORUM = 'ap_module_forum';

    /**
     * Known module slugs → option names.
     *
     * @return array<string, string>
     */
    public static function moduleOptionMap(): array
    {
        return [
            'static_pages' => self::MODULE_STATIC_PAGES,
            'blog' => self::MODULE_BLOG,
            'forum' => self::MODULE_FORUM,
        ];
    }

    /**
     * Whether a core module is enabled (default true when option missing).
     *
     * @param string $module static_pages|blog|forum (or full option name)
     */
    public static function isModuleEnabled(string $module, ?AP_DB $db = null): bool
    {
        $map = self::moduleOptionMap();
        $option = $map[$module] ?? $module;
        if (!in_array($option, array_values($map), true)) {
            return true;
        }
        $raw = strtolower(trim((string) self::get($option, '1', $db)));

        return !in_array($raw, ['0', 'false', 'no', 'off', ''], true);
    }

    /**
     * Persist module toggles. At least one module must remain enabled.
     *
     * @param array{static_pages?: bool|string|int, blog?: bool|string|int, forum?: bool|string|int} $modules
     *
     * @return bool False when validation fails (all off) or a write fails.
     */
    public static function updateModules(array $modules, ?AP_DB $db = null): bool
    {
        $values = [];
        foreach (self::moduleOptionMap() as $slug => $option) {
            $raw = $modules[$slug] ?? $modules[$option] ?? null;
            $on = ($raw === true || $raw === 1 || $raw === '1' || $raw === 'on' || $raw === 'yes');
            // When key absent entirely, preserve current (form checkboxes omit unchecked).
            if ($raw === null && !array_key_exists($slug, $modules) && !array_key_exists($option, $modules)) {
                $on = self::isModuleEnabled($slug, $db);
            }
            $values[$option] = $on ? '1' : '0';
        }

        if (!in_array('1', array_values($values), true)) {
            return false;
        }

        $ok = true;
        foreach ($values as $option => $value) {
            $ok = self::update($option, $value, $db) && $ok;
        }

        return $ok;
    }

    // -------------------------------------------------------------------------
    // General settings helpers
    // -------------------------------------------------------------------------

    /**
     * Persist General settings (site identity, membership, locale/time).
     *
     * @param array<string, mixed> $settings
     */
    public static function updateGeneralSettings(array $settings, ?AP_DB $db = null): bool
    {
        if (class_exists('AP_Settings', false)) {
            // Prefer Settings API sanitizers when available.
            $map = [
                'blogname', 'blogdescription', 'siteurl', 'home', 'admin_email',
                'users_can_register', 'require_email_verification', 'registration_captcha',
                'default_role',
                'timezone_string', 'WPLANG', 'date_format', 'time_format', 'start_of_week',
            ];
            $input = [];
            foreach ($map as $key) {
                if (array_key_exists($key, $settings)) {
                    $input[$key] = $settings[$key];
                }
            }
            // Ensure checkboxes default to off when omitted.
            foreach (['users_can_register', 'require_email_verification'] as $cb) {
                if (!array_key_exists($cb, $input)) {
                    $input[$cb] = '0';
                }
            }
            if (!array_key_exists('registration_captcha', $input)) {
                $input['registration_captcha'] = 'off';
            }

            return AP_Settings::save('general', $input, $db);
        }

        $ok = true;
        if (isset($settings['blogname'])) {
            $ok = self::update('blogname', trim(strip_tags((string) $settings['blogname'])), $db) && $ok;
        }
        if (isset($settings['blogdescription'])) {
            $ok = self::update('blogdescription', trim(strip_tags((string) $settings['blogdescription'])), $db) && $ok;
        }
        if (isset($settings['admin_email'])) {
            $email = strtolower(trim((string) $settings['admin_email']));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $ok = self::update('admin_email', $email, $db) && $ok;
            }
        }
        foreach (['siteurl', 'home'] as $urlKey) {
            if (!isset($settings[$urlKey])) {
                continue;
            }
            $url = rtrim(trim((string) $settings[$urlKey]), '/');
            if ($url !== '' && preg_match('#^https?://#i', $url) === 1) {
                $ok = self::update($urlKey, $url, $db) && $ok;
            }
        }
        $ok = self::update(
            'users_can_register',
            self::truthy($settings['users_can_register'] ?? '0') ? '1' : '0',
            $db
        ) && $ok;
        $ok = self::update(
            'require_email_verification',
            self::truthy($settings['require_email_verification'] ?? '0') ? '1' : '0',
            $db
        ) && $ok;
        $cap = strtolower(trim((string) ($settings['registration_captcha'] ?? 'off')));
        if ($cap === '' || $cap === '0' || $cap === 'false' || $cap === 'no' || $cap === 'disabled') {
            $cap = 'off';
        } elseif ($cap === '1' || $cap === 'true' || $cap === 'yes' || $cap === 'on') {
            $cap = 'math';
        }
        if (!in_array($cap, ['off', 'math'], true)) {
            $cap = 'off';
        }
        $ok = self::update('registration_captcha', $cap, $db) && $ok;
        if (isset($settings['default_role'])) {
            $role = strtolower(preg_replace('/[^a-z0-9_\-]/', '', (string) $settings['default_role']) ?? '');
            if ($role !== '' && $role !== 'administrator') {
                $ok = self::update('default_role', $role, $db) && $ok;
            }
        }
        if (isset($settings['timezone_string'])) {
            $tz = trim((string) $settings['timezone_string']);
            $ok = self::update('timezone_string', $tz !== '' ? $tz : 'UTC', $db) && $ok;
        }
        if (array_key_exists('WPLANG', $settings)) {
            $locale = trim((string) $settings['WPLANG']);
            if ($locale !== '' && class_exists('AP_L10n', false)) {
                $locale = AP_L10n::sanitizeLocale($locale);
            } elseif ($locale !== '') {
                $locale = str_replace('-', '_', $locale);
                if (preg_match('/^[a-zA-Z]{2,3}(?:_[a-zA-Z]{2}|_[0-9]{3})?$/', $locale) !== 1) {
                    $locale = '';
                }
            }
            $ok = self::update('WPLANG', $locale, $db) && $ok;
        }
        if (isset($settings['date_format'])) {
            $f = trim((string) $settings['date_format']);
            $ok = self::update('date_format', $f !== '' ? $f : 'Y-m-d', $db) && $ok;
        }
        if (isset($settings['time_format'])) {
            $f = trim((string) $settings['time_format']);
            $ok = self::update('time_format', $f !== '' ? $f : 'H:i', $db) && $ok;
        }
        if (isset($settings['start_of_week'])) {
            $ok = self::update('start_of_week', (string) max(0, min(6, (int) $settings['start_of_week'])), $db) && $ok;
        }

        return $ok;
    }

    /**
     * Persist Discussion settings (comments + avatars).
     *
     * @param array<string, mixed> $settings
     */
    public static function updateDiscussionSettings(array $settings, ?AP_DB $db = null): bool
    {
        if (class_exists('AP_Settings', false)) {
            $keys = [
                'default_comment_status', 'require_name_email', 'comment_moderation',
                'comment_registration', 'close_comments_for_old_posts', 'close_comments_days_old',
                'thread_comments', 'thread_comments_depth', 'show_avatars', 'avatar_default',
                'avatar_rating',
            ];
            $input = [];
            foreach ($keys as $key) {
                if (array_key_exists($key, $settings)) {
                    $input[$key] = $settings[$key];
                }
            }
            foreach (
                [
                    'require_name_email', 'comment_moderation', 'comment_registration',
                    'close_comments_for_old_posts', 'thread_comments', 'show_avatars',
                ] as $cb
            ) {
                if (!array_key_exists($cb, $input)) {
                    $input[$cb] = '0';
                }
            }

            return AP_Settings::save('discussion', $input, $db);
        }

        return false;
    }

    /**
     * Persist Media settings (image sizes + organize uploads).
     *
     * @param array<string, mixed> $settings
     */
    public static function updateMediaSettings(array $settings, ?AP_DB $db = null): bool
    {
        if (class_exists('AP_Settings', false)) {
            $keys = [
                'thumbnail_size_w', 'thumbnail_size_h', 'thumbnail_crop',
                'medium_size_w', 'medium_size_h', 'large_size_w', 'large_size_h',
                'max_image_display_width',
                'uploads_use_yearmonth_folders',
            ];
            $input = [];
            foreach ($keys as $key) {
                if (array_key_exists($key, $settings)) {
                    $input[$key] = $settings[$key];
                }
            }
            foreach (['thumbnail_crop', 'uploads_use_yearmonth_folders'] as $cb) {
                if (!array_key_exists($cb, $input)) {
                    $input[$cb] = '0';
                }
            }

            return AP_Settings::save('media', $input, $db);
        }

        return false;
    }

    /**
     * Persist Writing settings.
     *
     * @param array<string, mixed> $settings
     */
    public static function updateWritingSettings(array $settings, ?AP_DB $db = null): bool
    {
        if (class_exists('AP_Settings', false)) {
            $keys = ['default_category', 'use_smilies', 'default_comment_status'];
            $input = [];
            foreach ($keys as $key) {
                if (array_key_exists($key, $settings)) {
                    $input[$key] = $settings[$key];
                }
            }
            if (!array_key_exists('use_smilies', $input)) {
                $input['use_smilies'] = '0';
            }

            return AP_Settings::save('writing', $input, $db);
        }

        return false;
    }

    /**
     * Persist Forum settings (display, guests, features, attachments, moderation).
     *
     * @param array<string, mixed> $settings
     */
    public static function updateForumSettings(array $settings, ?AP_DB $db = null): bool
    {
        if (class_exists('AP_Settings', false)) {
            $keys = [
                'forum_topics_per_page',
                'forum_posts_per_page',
                'forum_allow_guest_viewing',
                'forum_allow_guest_posting',
                'forum_private_messaging_enabled',
                'forum_attachments_enabled',
                'forum_attachment_max_size',
                'forum_attachment_allowed_types',
                'forum_flood_interval',
                'forum_posts_require_approval',
                'forum_spam_blacklist',
                'forum_spam_max_links',
                'forum_search_enabled',
                'forum_online_enabled',
                'forum_unread_tracking_enabled',
            ];
            $input = [];
            foreach ($keys as $key) {
                if (array_key_exists($key, $settings)) {
                    $input[$key] = $settings[$key];
                }
            }
            foreach (
                [
                    'forum_allow_guest_viewing',
                    'forum_allow_guest_posting',
                    'forum_private_messaging_enabled',
                    'forum_attachments_enabled',
                    'forum_posts_require_approval',
                    'forum_search_enabled',
                    'forum_online_enabled',
                    'forum_unread_tracking_enabled',
                ] as $cb
            ) {
                if (!array_key_exists($cb, $input)) {
                    $input[$cb] = '0';
                }
            }

            return AP_Settings::save('forums', $input, $db);
        }

        return false;
    }

    /**
     * Persist Permalink structure + optional bases; flushes rewrite rules.
     *
     * @param array{permalink_structure?: string, category_base?: string, tag_base?: string} $settings
     */
    public static function updatePermalinkSettings(array $settings, ?AP_DB $db = null): bool
    {
        $structure = (string) ($settings['permalink_structure'] ?? '');
        $categoryBase = (string) ($settings['category_base'] ?? '');
        $tagBase = (string) ($settings['tag_base'] ?? '');

        if (class_exists('AP_Rewrite', false)) {
            $ok = AP_Rewrite::setStructure($structure, $db);
            $ok = AP_Rewrite::setCategoryBase($categoryBase, $db) && $ok;
            $ok = AP_Rewrite::setTagBase($tagBase, $db) && $ok;

            return $ok;
        }

        $ok = self::update('permalink_structure', $structure, $db);
        $ok = self::update('category_base', $categoryBase, $db) && $ok;
        $ok = self::update('tag_base', $tagBase, $db) && $ok;

        return $ok;
    }

    private static function truthy(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1'
            || $value === 'on' || $value === 'yes' || $value === 'true';
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
