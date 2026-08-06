<?php

/**
 * Local privacy-respecting site analytics (config + server-side recorder).
 *
 * Server-side collection for admin reports only. Data stays in the site
 * database under the configured table prefix. No third-party scripts,
 * pixels, or external endpoints. Not related to Hall of Fame or the
 * public version check.
 *
 * Site options (Options API / `{prefix}options`):
 *
 * | Option                      | Default | Meaning                                      |
 * |-----------------------------|---------|----------------------------------------------|
 * | `analytics_enabled`         | off (`0`) | Master switch for hit collection             |
 * | `analytics_retention_days`  | `90`    | Days of hit history kept before prune/cron   |
 *
 * **Default is off** so fresh and upgraded sites never record page views
 * until an administrator explicitly enables collection in ACP. That matches
 * AgoraPress privacy posture (no telemetry / no unexpected data collection).
 * When enabled, hits remain local-only.
 *
 * ## Server-side recorder (v1)
 *
 * Pure server-side logging via a request shutdown hook (no front-end JS).
 * When enabled, public GET/HEAD page views are written to `{prefix}analytics_hits`.
 *
 * **Skipped by default**
 * - Collection disabled (`analytics_enabled` off)
 * - CLI / `AP_CLI`
 * - Admin area (`AP_ADMIN` or path under `/ap-admin/`)
 * - Feeds, REST (`/ap-json/`), sitemaps, robots.txt
 * - Obvious bots (coarse UA class)
 * - Logged-in users with `manage_options` (clean public pageview counts;
 *   override with filter `ap_analytics_exclude_admins`)
 * - HTTP `DNT: 1` (Do Not Track) when present
 * - Non-GET/HEAD methods
 *
 * **404s:** recorded by default with `status_code=404` (useful for broken-link
 * reports). Disable via filter `ap_analytics_record_404` → false.
 *
 * **Admin browsing the public site:** excluded when they have `manage_options`.
 * If a hit is still recorded (filter override), `is_admin` is set to 1.
 *
 * ## Retention prune (cron)
 *
 * Daily pseudo-cron hook `ap_analytics_prune` deletes rows older than
 * `analytics_retention_days` from both `analytics_hits` (`hit_time`) and
 * `analytics_daily` (`day`). Runs even when collection is disabled so storage
 * still shrinks after an admin turns analytics off. Registered via
 * {@see registerCron()} from bootstrap (before `AP_Cron::spawn()`).
 *
 * ## Aggregation helpers (ACP reports)
 *
 * Read-only queries over `analytics_hits` (and optional daily rollup writes):
 * {@see countHits()}, {@see getSummary()}, {@see getTopPaths()},
 * {@see getTopReferrers()}, {@see getDailyTotals()}, {@see rollupDaily()}.
 * ACP screen: Tools → Analytics (`ap-admin/analytics.php`, `manage_options`).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * Analytics configuration and server-side hit collection.
 */
class AP_Analytics
{
    /**
     * Option: master collection switch.
     * Stored as '1' / '0'. Default missing or '0' = disabled (no hits written).
     */
    public const OPTION_ENABLED = 'analytics_enabled';

    /**
     * Option: retention window in whole days for raw hits (and prune cutoff).
     * Stored as a decimal string. Default 90 when missing or invalid.
     */
    public const OPTION_RETENTION_DAYS = 'analytics_retention_days';

    /** Default for {@see OPTION_ENABLED}: collection off until admin opts in. */
    public const DEFAULT_ENABLED = false;

    /** Default retention in days when option missing or invalid. */
    public const DEFAULT_RETENTION_DAYS = 90;

    /** Minimum allowed retention (days). */
    public const MIN_RETENTION_DAYS = 1;

    /** Maximum allowed retention (days) — ~10 years; keeps prune bounded. */
    public const MAX_RETENTION_DAYS = 3650;

    /** Schema max length for path / referrer columns. */
    public const MAX_PATH_LENGTH = 512;

    public const MAX_REFERRER_LENGTH = 512;

    /** Coarse user-agent classes (not full UA storage). */
    public const UA_BROWSER = 'browser';

    public const UA_BOT = 'bot';

    public const UA_OTHER = 'other';

    /**
     * Cron hook name for retention prune (daily recurrence).
     * Fired by {@see AP_Cron::runDue()} / spawn.
     */
    public const CRON_HOOK = 'ap_analytics_prune';

    /** Recurrence schedule slug for the prune event. */
    public const CRON_RECURRENCE = 'daily';

    /** Whether the shutdown recorder was registered this process. */
    private static bool $shutdownRegistered = false;

    /** Whether the prune cron action was registered this process. */
    private static bool $cronHookRegistered = false;

    /** Whether a hit was already written this request (at most one). */
    private static bool $recordedThisRequest = false;

    // -------------------------------------------------------------------------
    // Config
    // -------------------------------------------------------------------------

    /**
     * Whether hit collection is enabled for this site.
     *
     * Default is **off** when the option is missing (privacy / opt-in).
     * Filter: `ap_analytics_enabled`.
     */
    public static function isEnabled(?AP_DB $db = null): bool
    {
        $enabled = self::DEFAULT_ENABLED;
        if (class_exists('AP_Options', false)) {
            $default = self::DEFAULT_ENABLED ? '1' : '0';
            $raw = strtolower(trim((string) AP_Options::get(self::OPTION_ENABLED, $default, $db)));
            $enabled = !in_array($raw, ['0', 'false', 'no', 'off', ''], true);
        }

        if (function_exists('ap_apply_filters')) {
            return (bool) ap_apply_filters('ap_analytics_enabled', $enabled, $db);
        }

        return $enabled;
    }

    /**
     * Retention window in whole days (clamped). Default 90.
     *
     * Filter: `ap_analytics_retention_days`.
     */
    public static function getRetentionDays(?AP_DB $db = null): int
    {
        $days = self::DEFAULT_RETENTION_DAYS;
        if (class_exists('AP_Options', false)) {
            $raw = AP_Options::get(self::OPTION_RETENTION_DAYS, (string) self::DEFAULT_RETENTION_DAYS, $db);
            $days = self::sanitizeRetentionDays($raw);
        }

        if (function_exists('ap_apply_filters')) {
            $filtered = ap_apply_filters('ap_analytics_retention_days', $days, $db);
            $days = self::sanitizeRetentionDays($filtered);
        }

        return $days;
    }

    /**
     * Normalize a retention value to an int in [MIN, MAX], defaulting to 90.
     */
    public static function sanitizeRetentionDays(mixed $value): int
    {
        if (is_bool($value)) {
            return self::DEFAULT_RETENTION_DAYS;
        }
        if (is_string($value)) {
            $value = trim($value);
            if ($value === '' || !is_numeric($value)) {
                return self::DEFAULT_RETENTION_DAYS;
            }
        }
        if (!is_numeric($value)) {
            return self::DEFAULT_RETENTION_DAYS;
        }

        $n = (int) $value;
        if ($n < self::MIN_RETENTION_DAYS) {
            return self::DEFAULT_RETENTION_DAYS;
        }

        return min(self::MAX_RETENTION_DAYS, $n);
    }

    /**
     * Normalize an enable flag to '1' or '0' for storage.
     */
    public static function sanitizeEnabled(mixed $value): string
    {
        if ($value === true || $value === 1 || $value === '1') {
            return '1';
        }
        if (is_string($value)) {
            $v = strtolower(trim($value));
            if (in_array($v, ['1', 'true', 'yes', 'on'], true)) {
                return '1';
            }
        }

        return '0';
    }

    /**
     * Persist analytics settings (enable + retention). Omitted keys are left unchanged.
     *
     * @param array{analytics_enabled?: mixed, analytics_retention_days?: mixed, enabled?: mixed, retention_days?: mixed} $settings
     */
    public static function updateSettings(array $settings, ?AP_DB $db = null): bool
    {
        if (!class_exists('AP_Options', false)) {
            return false;
        }

        $ok = true;
        $enabledKey = array_key_exists(self::OPTION_ENABLED, $settings)
            ? self::OPTION_ENABLED
            : (array_key_exists('enabled', $settings) ? 'enabled' : null);
        if ($enabledKey !== null) {
            $ok = AP_Options::update(
                self::OPTION_ENABLED,
                self::sanitizeEnabled($settings[$enabledKey]),
                $db
            ) && $ok;
        }

        $retentionKey = array_key_exists(self::OPTION_RETENTION_DAYS, $settings)
            ? self::OPTION_RETENTION_DAYS
            : (array_key_exists('retention_days', $settings) ? 'retention_days' : null);
        if ($retentionKey !== null) {
            $days = self::sanitizeRetentionDays($settings[$retentionKey]);
            $ok = AP_Options::update(
                self::OPTION_RETENTION_DAYS,
                (string) $days,
                $db
            ) && $ok;
        }

        return $ok;
    }

    /**
     * Default option map for installers / docs (name => stored string value).
     *
     * @return array<string, string>
     */
    public static function defaultOptionMap(): array
    {
        return [
            self::OPTION_ENABLED => self::DEFAULT_ENABLED ? '1' : '0',
            self::OPTION_RETENTION_DAYS => (string) self::DEFAULT_RETENTION_DAYS,
        ];
    }

    // -------------------------------------------------------------------------
    // Request recorder
    // -------------------------------------------------------------------------

    /**
     * Register the shutdown hook that records a public page view.
     *
     * Safe to call multiple times (idempotent). No-op on CLI. Always registers
     * the hook even when collection is currently disabled so enabling mid-life
     * without redeploy works; {@see maybeRecordCurrentRequest()} re-checks
     * the option at record time (cheap option read from autoload cache).
     */
    public static function register(): void
    {
        if (self::$shutdownRegistered) {
            return;
        }
        self::$shutdownRegistered = true;

        if (self::isCliContext()) {
            return;
        }

        register_shutdown_function(static function (): void {
            try {
                self::maybeRecordCurrentRequest();
            } catch (Throwable) {
                // Analytics must never break the request or leak fatals on shutdown.
            }
        });
    }

    /**
     * Whether the shutdown recorder has been registered this process.
     */
    public static function isRegistered(): bool
    {
        return self::$shutdownRegistered;
    }

    /**
     * Reset per-request / process flags (tests only).
     *
     * @internal
     */
    public static function resetRequestState(): void
    {
        self::$recordedThisRequest = false;
        self::$shutdownRegistered = false;
        self::$cronHookRegistered = false;
    }

    // -------------------------------------------------------------------------
    // Retention prune + cron
    // -------------------------------------------------------------------------

    /**
     * Register the prune cron action and ensure a daily event is scheduled.
     *
     * Idempotent. Safe to call on every request. Does not require analytics
     * collection to be enabled (stale rows should still age out).
     *
     * Call before {@see AP_Cron::spawn()} so a due prune can fire same request.
     */
    public static function registerCron(?AP_DB $db = null): void
    {
        if (!self::$cronHookRegistered) {
            self::$cronHookRegistered = true;
            if (function_exists('ap_add_action')) {
                ap_add_action(self::CRON_HOOK, static function (): void {
                    try {
                        self::prune();
                    } catch (Throwable) {
                        // Prune must never break the request or cron loop.
                    }
                });
            }
        }

        self::ensurePruneScheduled($db);
    }

    /**
     * Whether the prune cron action has been registered this process.
     */
    public static function isCronRegistered(): bool
    {
        return self::$cronHookRegistered;
    }

    /**
     * Schedule the daily prune event if not already present.
     *
     * @return bool True when a new event was scheduled; false if already present or unavailable.
     */
    public static function ensurePruneScheduled(?AP_DB $db = null): bool
    {
        if (!class_exists('AP_Cron', false) || !class_exists('AP_Options', false)) {
            return false;
        }

        try {
            if (AP_Cron::nextScheduled(self::CRON_HOOK, [], $db) !== false) {
                return false;
            }

            // First run shortly after first request so installs do not wait a full day.
            $timestamp = time() + 60;

            return AP_Cron::scheduleEvent(
                $timestamp,
                self::CRON_RECURRENCE,
                self::CRON_HOOK,
                [],
                $db
            );
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Delete analytics rows older than the retention window.
     *
     * Removes raw hits (`hit_time` &lt; cutoff) and daily rollups (`day` &lt; cutoff date).
     * Runs regardless of {@see isEnabled()} so turning collection off still frees storage.
     *
     * Filter: `ap_analytics_prune_args` on the effective args map before delete.
     * Action: `ap_analytics_pruned` after a successful run (deleted count, days, db).
     *
     * @param array<string, mixed> $args {
     *     @type int  $retention_days Override retention (clamped). Default: site option.
     *     @type int  $now            Unix timestamp for cutoff math (tests). Default: time().
     *     @type bool $prune_hits     Delete from analytics_hits (default true).
     *     @type bool $prune_daily    Delete from analytics_daily (default true).
     * }
     *
     * @return int Total rows deleted (hits + daily). 0 when nothing removed / no DB.
     */
    public static function prune(?AP_DB $db = null, array $args = []): int
    {
        $db = self::resolveDb($db);
        if ($db === null) {
            return 0;
        }

        if (function_exists('ap_apply_filters')) {
            $filtered = ap_apply_filters('ap_analytics_prune_args', $args, $db);
            if (is_array($filtered)) {
                $args = $filtered;
            }
        }

        $days = array_key_exists('retention_days', $args)
            ? self::sanitizeRetentionDays($args['retention_days'])
            : self::getRetentionDays($db);

        $pruneHits = !array_key_exists('prune_hits', $args) || !empty($args['prune_hits']);
        $pruneDaily = !array_key_exists('prune_daily', $args) || !empty($args['prune_daily']);

        $now = isset($args['now']) ? (int) $args['now'] : time();
        if ($now <= 0) {
            $now = time();
        }

        $cutoffTs = $now - ($days * 86400);
        $cutoffDateTime = date('Y-m-d H:i:s', $cutoffTs);
        $cutoffDay = date('Y-m-d', $cutoffTs);

        $deleted = 0;

        if ($pruneHits) {
            $deleted += self::deleteOlderThan($db, 'analytics_hits', 'hit_time', $cutoffDateTime);
        }
        if ($pruneDaily) {
            $deleted += self::deleteOlderThan($db, 'analytics_daily', 'day', $cutoffDay);
        }

        if (function_exists('ap_do_action')) {
            try {
                ap_do_action('ap_analytics_pruned', $deleted, $days, [
                    'cutoff_datetime' => $cutoffDateTime,
                    'cutoff_day' => $cutoffDay,
                ], $db);
            } catch (Throwable) {
                // Hooks must not undo prune.
            }
        }

        return $deleted;
    }

    /**
     * Compute the prune cutoffs for a retention window (for tests / ACP messaging).
     *
     * @return array{retention_days: int, cutoff_datetime: string, cutoff_day: string, now: int}
     */
    public static function pruneCutoff(int $retentionDays = 0, ?int $now = null): array
    {
        $days = $retentionDays > 0
            ? self::sanitizeRetentionDays($retentionDays)
            : self::DEFAULT_RETENTION_DAYS;
        $now = $now ?? time();
        if ($now <= 0) {
            $now = time();
        }
        $cutoffTs = $now - ($days * 86400);

        return [
            'retention_days' => $days,
            'cutoff_datetime' => date('Y-m-d H:i:s', $cutoffTs),
            'cutoff_day' => date('Y-m-d', $cutoffTs),
            'now' => $now,
        ];
    }

    /**
     * DELETE FROM {table} WHERE {column} < ? (prepared). Returns rows affected.
     */
    private static function deleteOlderThan(AP_DB $db, string $table, string $column, string $cutoff): int
    {
        try {
            $tableSql = $db->quoteIdentifier($db->table($table));
            $colSql = $db->quoteIdentifier($column);
            $stmt = $db->query(
                'DELETE FROM ' . $tableSql . ' WHERE ' . $colSql . ' < ?',
                [$cutoff]
            );
            if ($stmt === false) {
                return 0;
            }

            return max(0, $db->rowsAffected());
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Record the current request when collection is enabled and the request
     * is a public page view. Returns the new hit_id, or 0 when skipped / failed.
     */
    public static function maybeRecordCurrentRequest(?AP_DB $db = null): int
    {
        if (self::$recordedThisRequest) {
            return 0;
        }

        if (!self::shouldRecordRequest($db)) {
            return 0;
        }

        $data = self::buildHitFromCurrentRequest($db);
        if ($data === null) {
            return 0;
        }

        return self::recordHit($data, $db, ['check_enabled' => false, 'check_should' => false]);
    }

    /**
     * Whether the current request should be recorded as a page view.
     *
     * Filter: `ap_analytics_should_record` (bool, $db).
     */
    public static function shouldRecordRequest(?AP_DB $db = null): bool
    {
        $should = true;

        if (!self::isEnabled($db)) {
            $should = false;
        }

        if (self::isCliContext()) {
            $should = false;
        }

        if (defined('AP_ADMIN') && AP_ADMIN) {
            $should = false;
        }

        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method !== '' && $method !== 'GET' && $method !== 'HEAD') {
            $should = false;
        }

        if (self::isDoNotTrack()) {
            $should = false;
        }

        $path = self::currentRequestPath();
        if (self::isAdminPath($path)) {
            $should = false;
        }
        if (self::isNonContentPath($path)) {
            $should = false;
        }

        // Feeds / REST / sitemaps from rewrite globals when available.
        if (self::isNonContentRequestFromGlobals()) {
            $should = false;
        }

        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $uaClass = self::classifyUserAgent($ua);
        if ($uaClass === self::UA_BOT) {
            $should = false;
        }

        // Logged-in site admins browsing the public front: exclude by default.
        $excludeAdmins = true;
        if (function_exists('ap_apply_filters')) {
            $excludeAdmins = (bool) ap_apply_filters('ap_analytics_exclude_admins', $excludeAdmins, $db);
        }
        if ($excludeAdmins && self::currentUserIsAdmin($db)) {
            $should = false;
        }

        // 404 policy (default: record).
        $status = self::currentStatusCode();
        if ($status === 404) {
            $record404 = true;
            if (function_exists('ap_apply_filters')) {
                $record404 = (bool) ap_apply_filters('ap_analytics_record_404', $record404, $db);
            }
            if (!$record404) {
                $should = false;
            }
        }

        if (function_exists('ap_apply_filters')) {
            $should = (bool) ap_apply_filters('ap_analytics_should_record', $should, $db);
        }

        return $should;
    }

    /**
     * Insert a hit row. Returns hit_id on success, 0 on failure / disabled.
     *
     * @param array<string, mixed> $data Keys: path, object_id, status_code, referrer,
     *                                   ua_class, is_admin, hit_time (optional).
     * @param array<string, mixed> $args check_enabled (default true), check_should (default false)
     */
    public static function recordHit(array $data, ?AP_DB $db = null, array $args = []): int
    {
        $checkEnabled = !array_key_exists('check_enabled', $args) || !empty($args['check_enabled']);
        $checkShould = !empty($args['check_should']);

        if ($checkEnabled && !self::isEnabled($db)) {
            return 0;
        }
        if ($checkShould && !self::shouldRecordRequest($db)) {
            return 0;
        }

        $db = self::resolveDb($db);
        if ($db === null) {
            return 0;
        }

        $path = self::normalizePath((string) ($data['path'] ?? '/'));
        $objectId = max(0, (int) ($data['object_id'] ?? 0));
        $status = (int) ($data['status_code'] ?? 200);
        if ($status < 100 || $status > 599) {
            $status = 200;
        }
        $referrer = self::truncateReferrer((string) ($data['referrer'] ?? ''));
        $uaClass = self::sanitizeUaClass((string) ($data['ua_class'] ?? self::UA_OTHER));
        $isAdmin = !empty($data['is_admin']) ? 1 : 0;
        $hitTime = (string) ($data['hit_time'] ?? '');
        if ($hitTime === '' || !preg_match('/^\d{4}-\d{2}-\d{2}/', $hitTime)) {
            $hitTime = self::nowLocal();
        }

        try {
            $result = $db->insert('analytics_hits', [
                'hit_time' => $hitTime,
                'path' => $path,
                'object_id' => $objectId,
                'status_code' => $status,
                'referrer' => $referrer,
                'ua_class' => $uaClass,
                'is_admin' => $isAdmin,
            ]);
        } catch (Throwable) {
            return 0;
        }

        if ($result === false) {
            return 0;
        }

        self::$recordedThisRequest = true;
        $id = (int) $db->lastInsertId();

        if (function_exists('ap_do_action') && $id > 0) {
            try {
                ap_do_action('ap_analytics_hit_recorded', $id, [
                    'path' => $path,
                    'object_id' => $objectId,
                    'status_code' => $status,
                    'ua_class' => $uaClass,
                    'is_admin' => $isAdmin,
                ], $db);
            } catch (Throwable) {
                // Hooks must not undo a successful write.
            }
        }

        return $id > 0 ? $id : 0;
    }

    /**
     * Build hit fields from the current HTTP request + main query (if any).
     *
     * @return array<string, mixed>|null Null when path cannot be resolved.
     */
    public static function buildHitFromCurrentRequest(?AP_DB $db = null): ?array
    {
        $path = self::currentRequestPath();
        if ($path === '') {
            $path = '/';
        }

        $objectId = 0;
        if (function_exists('ap_get_queried_post')) {
            try {
                $post = ap_get_queried_post();
                if (is_object($post) && isset($post->ID)) {
                    $objectId = max(0, (int) $post->ID);
                }
            } catch (Throwable) {
                $objectId = 0;
            }
        }

        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $referrer = (string) ($_SERVER['HTTP_REFERER'] ?? '');

        return [
            'path' => self::normalizePath($path),
            'object_id' => $objectId,
            'status_code' => self::currentStatusCode(),
            'referrer' => self::truncateReferrer($referrer),
            'ua_class' => self::classifyUserAgent($ua),
            'is_admin' => self::currentUserIsAdmin($db) ? 1 : 0,
            'hit_time' => self::nowLocal(),
        ];
    }

    /**
     * Coarse UA classification: browser | bot | other.
     *
     * Does not store the full user-agent string. Filter: `ap_analytics_ua_class`.
     */
    public static function classifyUserAgent(string $ua): string
    {
        $ua = trim($ua);
        if ($ua === '') {
            $class = self::UA_OTHER;
        } else {
            $lower = strtolower($ua);
            // Obvious bots / scanners / library clients.
            $botNeedles = [
                'bot',
                'spider',
                'crawl',
                'slurp',
                'mediapartners-google',
                'facebookexternalhit',
                'facebot',
                'ia_archiver',
                'wget',
                'curl/',
                'python-requests',
                'python-urllib',
                'libwww-perl',
                'httpclient',
                'java/',
                'php/',
                'scrapy',
                'headlesschrome',
                'pingdom',
                'uptimerobot',
                'monitor',
                'preview',
            ];
            $isBot = false;
            foreach ($botNeedles as $needle) {
                if (str_contains($lower, $needle)) {
                    $isBot = true;
                    break;
                }
            }
            if ($isBot) {
                $class = self::UA_BOT;
            } elseif (
                str_contains($lower, 'mozilla')
                || str_contains($lower, 'chrome')
                || str_contains($lower, 'safari')
                || str_contains($lower, 'firefox')
                || str_contains($lower, 'edg/')
                || str_contains($lower, 'opera')
                || str_contains($lower, 'msie')
                || str_contains($lower, 'trident/')
            ) {
                $class = self::UA_BROWSER;
            } else {
                $class = self::UA_OTHER;
            }
        }

        if (function_exists('ap_apply_filters')) {
            $filtered = (string) ap_apply_filters('ap_analytics_ua_class', $class, $ua);
            $class = self::sanitizeUaClass($filtered);
        }

        return $class;
    }

    /**
     * Normalize a request path for storage (leading slash, no query string, max length).
     */
    public static function normalizePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '/';
        }

        // Strip scheme/host if a full URL was passed.
        if (str_contains($path, '://')) {
            $parsed = parse_url($path, PHP_URL_PATH);
            $path = is_string($parsed) ? $parsed : '/';
        }

        // Drop query/fragment if still present.
        $q = strpos($path, '?');
        if ($q !== false) {
            $path = substr($path, 0, $q);
        }
        $h = strpos($path, '#');
        if ($h !== false) {
            $path = substr($path, 0, $h);
        }

        $path = str_replace("\0", '', $path);
        $path = rawurldecode($path);
        // Collapse duplicate slashes (keep leading).
        $path = (string) preg_replace('#/{2,}#', '/', $path);
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . ltrim($path, '/');
        }

        // Strip trailing slash except for root.
        if (strlen($path) > 1) {
            $path = rtrim($path, '/');
        }

        return self::truncateUtf8($path, self::MAX_PATH_LENGTH);
    }

    /**
     * Truncate referrer for storage (empty when missing).
     */
    public static function truncateReferrer(string $referrer): string
    {
        $referrer = trim(str_replace("\0", '', $referrer));
        if ($referrer === '') {
            return '';
        }

        return self::truncateUtf8($referrer, self::MAX_REFERRER_LENGTH);
    }

    /**
     * @internal Sanitize ua_class to allowed values.
     */
    public static function sanitizeUaClass(string $class): string
    {
        $class = strtolower(trim($class));
        if (in_array($class, [self::UA_BROWSER, self::UA_BOT, self::UA_OTHER], true)) {
            return $class;
        }

        return self::UA_OTHER;
    }

    // -------------------------------------------------------------------------
    // Aggregation helpers (ACP reports)
    // -------------------------------------------------------------------------

    /**
     * Count raw hits matching optional filters.
     *
     * Args (all optional):
     * - `since` / `until` — inclusive lower / exclusive upper `Y-m-d H:i:s` on hit_time
     * - `day` — calendar day `Y-m-d` (sets since/until for that day)
     * - `path` — exact path match after normalize
     * - `object_id` — content ID
     * - `status_code` — HTTP status
     * - `ua_class` — browser|bot|other
     * - `exclude_admin` — when true (default), omit is_admin=1 rows
     * - `now` — unix timestamp used only when computing relative ranges elsewhere
     *
     * @param array<string, mixed> $args
     */
    public static function countHits(?AP_DB $db = null, array $args = []): int
    {
        $db = self::resolveDb($db);
        if ($db === null) {
            return 0;
        }

        $args = self::normalizeReportArgs($args);
        [$sql, $params] = self::buildHitsWhere($db, $args, 'COUNT(*)');

        try {
            $n = $db->getVar($sql, $params);
        } catch (Throwable) {
            return 0;
        }

        return max(0, (int) $n);
    }

    /**
     * Pageview summary buckets for ACP widgets.
     *
     * Counts non-admin hits for today, last 7 calendar days (including today),
     * and last 30 calendar days (including today). Bounds are local server time.
     *
     * @return array{today: int, last_7_days: int, last_30_days: int}
     */
    public static function getSummary(?AP_DB $db = null, ?int $now = null): array
    {
        $db = self::resolveDb($db);
        $empty = ['today' => 0, 'last_7_days' => 0, 'last_30_days' => 0];
        if ($db === null) {
            return $empty;
        }

        $now = ($now !== null && $now > 0) ? $now : time();
        $todayStart = date('Y-m-d 00:00:00', $now);
        $tomorrowStart = date('Y-m-d 00:00:00', $now + 86400);
        $day7Start = date('Y-m-d 00:00:00', $now - (6 * 86400));
        $day30Start = date('Y-m-d 00:00:00', $now - (29 * 86400));

        return [
            'today' => self::countHits($db, [
                'since' => $todayStart,
                'until' => $tomorrowStart,
                'exclude_admin' => true,
            ]),
            'last_7_days' => self::countHits($db, [
                'since' => $day7Start,
                'until' => $tomorrowStart,
                'exclude_admin' => true,
            ]),
            'last_30_days' => self::countHits($db, [
                'since' => $day30Start,
                'until' => $tomorrowStart,
                'exclude_admin' => true,
            ]),
        ];
    }

    /**
     * Top paths by hit count (for ACP "top pages" table).
     *
     * Args: since, until, day, limit (default 10, max 100), exclude_admin (default true).
     *
     * @param array<string, mixed> $args
     *
     * @return list<array{path: string, object_id: int, hits: int}>
     */
    public static function getTopPaths(?AP_DB $db = null, array $args = []): array
    {
        $db = self::resolveDb($db);
        if ($db === null) {
            return [];
        }

        $args = self::normalizeReportArgs($args);
        $limit = self::clampLimit($args['limit'] ?? 10, 10, 100);

        $table = $db->quoteIdentifier($db->table('analytics_hits'));
        $pathCol = $db->quoteIdentifier('path');
        $objCol = $db->quoteIdentifier('object_id');
        [$whereSql, $params] = self::buildHitsWhereClause($db, $args);

        $sql = 'SELECT ' . $pathCol . ' AS path, ' . $objCol . ' AS object_id, COUNT(*) AS hits'
            . ' FROM ' . $table
            . $whereSql
            . ' GROUP BY ' . $pathCol . ', ' . $objCol
            . ' ORDER BY hits DESC, ' . $pathCol . ' ASC'
            . ' LIMIT ' . $limit;

        try {
            $rows = $db->getResults($sql, $params);
        } catch (Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'path' => (string) ($row->path ?? ''),
                'object_id' => max(0, (int) ($row->object_id ?? 0)),
                'hits' => max(0, (int) ($row->hits ?? 0)),
            ];
        }

        return $out;
    }

    /**
     * Top non-empty referrers by hit count.
     *
     * Args: since, until, day, limit (default 10, max 100), exclude_admin (default true).
     *
     * @param array<string, mixed> $args
     *
     * @return list<array{referrer: string, hits: int}>
     */
    public static function getTopReferrers(?AP_DB $db = null, array $args = []): array
    {
        $db = self::resolveDb($db);
        if ($db === null) {
            return [];
        }

        $args = self::normalizeReportArgs($args);
        $limit = self::clampLimit($args['limit'] ?? 10, 10, 100);

        $table = $db->quoteIdentifier($db->table('analytics_hits'));
        $refCol = $db->quoteIdentifier('referrer');
        [$whereSql, $params] = self::buildHitsWhereClause($db, $args);

        // Only non-empty referrers.
        if ($whereSql === '') {
            $whereSql = ' WHERE ' . $refCol . " <> ''";
        } else {
            $whereSql .= ' AND ' . $refCol . " <> ''";
        }

        $sql = 'SELECT ' . $refCol . ' AS referrer, COUNT(*) AS hits'
            . ' FROM ' . $table
            . $whereSql
            . ' GROUP BY ' . $refCol
            . ' ORDER BY hits DESC, ' . $refCol . ' ASC'
            . ' LIMIT ' . $limit;

        try {
            $rows = $db->getResults($sql, $params);
        } catch (Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'referrer' => (string) ($row->referrer ?? ''),
                'hits' => max(0, (int) ($row->hits ?? 0)),
            ];
        }

        return $out;
    }

    /**
     * Per-day pageview totals for a crude chart / last-N-days table.
     *
     * Args:
     * - `days` — number of calendar days including today (default 30, max 366)
     * - `since` / `until` — override range (takes precedence over days when both set)
     * - `exclude_admin` — default true
     * - `now` — unix timestamp for relative "days" window
     * - `fill_gaps` — when true (default), include zero-hit days in range
     *
     * @param array<string, mixed> $args
     *
     * @return list<array{day: string, hits: int}>
     */
    public static function getDailyTotals(?AP_DB $db = null, array $args = []): array
    {
        $db = self::resolveDb($db);
        if ($db === null) {
            return [];
        }

        $args = self::normalizeReportArgs($args);
        $now = isset($args['now']) ? (int) $args['now'] : time();
        if ($now <= 0) {
            $now = time();
        }

        $fillGaps = !array_key_exists('fill_gaps', $args) || !empty($args['fill_gaps']);

        if (!isset($args['since']) || $args['since'] === '') {
            $nDays = self::clampLimit($args['days'] ?? 30, 30, 366);
            $args['since'] = date('Y-m-d 00:00:00', $now - (($nDays - 1) * 86400));
            $args['until'] = date('Y-m-d 00:00:00', $now + 86400);
        } elseif (!isset($args['until']) || $args['until'] === '') {
            $args['until'] = date('Y-m-d 00:00:00', $now + 86400);
        }

        $table = $db->quoteIdentifier($db->table('analytics_hits'));
        $dayExpr = self::sqlDayExpression($db, 'hit_time');
        [$whereSql, $params] = self::buildHitsWhereClause($db, $args);

        $sql = 'SELECT ' . $dayExpr . ' AS day, COUNT(*) AS hits'
            . ' FROM ' . $table
            . $whereSql
            . ' GROUP BY ' . $dayExpr
            . ' ORDER BY day ASC';

        try {
            $rows = $db->getResults($sql, $params);
        } catch (Throwable) {
            return [];
        }

        $byDay = [];
        foreach ($rows as $row) {
            $day = (string) ($row->day ?? '');
            if ($day === '') {
                continue;
            }
            // Normalize DATE objects / trailing time if any driver returns them.
            if (strlen($day) > 10) {
                $day = substr($day, 0, 10);
            }
            $byDay[$day] = max(0, (int) ($row->hits ?? 0));
        }

        if (!$fillGaps) {
            $out = [];
            foreach ($byDay as $day => $hits) {
                $out[] = ['day' => $day, 'hits' => $hits];
            }

            return $out;
        }

        $startDay = substr((string) $args['since'], 0, 10);
        $endExclusive = substr((string) $args['until'], 0, 10);
        $out = [];
        $ts = strtotime($startDay . ' 00:00:00');
        $endTs = strtotime($endExclusive . ' 00:00:00');
        if ($ts === false || $endTs === false) {
            foreach ($byDay as $day => $hits) {
                $out[] = ['day' => $day, 'hits' => $hits];
            }

            return $out;
        }

        // Safety: cap iteration.
        $maxSteps = 400;
        $steps = 0;
        while ($ts < $endTs && $steps < $maxSteps) {
            $day = date('Y-m-d', $ts);
            $out[] = [
                'day' => $day,
                'hits' => $byDay[$day] ?? 0,
            ];
            $ts += 86400;
            $steps++;
        }

        return $out;
    }

    /**
     * Aggregate raw hits into `analytics_daily` for a day range.
     *
     * Replaces daily rows for each calendar day in range (delete + insert by path/object).
     * Useful for cron or ACP "rebuild rollups". Does not require collection to be enabled.
     *
     * Args: since, until, day, days, now, exclude_admin (default false — rollups keep all hits).
     *
     * @param array<string, mixed> $args
     *
     * @return int Number of daily rows written.
     */
    public static function rollupDaily(?AP_DB $db = null, array $args = []): int
    {
        $db = self::resolveDb($db);
        if ($db === null) {
            return 0;
        }

        // Default: include admin hits in rollups (set before normalizeReportArgs).
        if (!array_key_exists('exclude_admin', $args)) {
            $args['exclude_admin'] = false;
        }

        $args = self::normalizeReportArgs($args);
        $now = isset($args['now']) ? (int) $args['now'] : time();
        if ($now <= 0) {
            $now = time();
        }

        // Default range: last 30 days including today (normalize may have set day bounds).
        if (!isset($args['since']) || $args['since'] === '') {
            $nDays = self::clampLimit($args['days'] ?? 30, 30, 366);
            $args['since'] = date('Y-m-d 00:00:00', $now - (($nDays - 1) * 86400));
            $args['until'] = date('Y-m-d 00:00:00', $now + 86400);
        } elseif (!isset($args['until']) || $args['until'] === '') {
            $args['until'] = date('Y-m-d 00:00:00', $now + 86400);
        }

        $table = $db->quoteIdentifier($db->table('analytics_hits'));
        $dayExpr = self::sqlDayExpression($db, 'hit_time');
        $pathCol = $db->quoteIdentifier('path');
        $objCol = $db->quoteIdentifier('object_id');
        [$whereSql, $params] = self::buildHitsWhereClause($db, $args);

        $sql = 'SELECT ' . $dayExpr . ' AS day, ' . $pathCol . ' AS path, '
            . $objCol . ' AS object_id, COUNT(*) AS hits'
            . ' FROM ' . $table
            . $whereSql
            . ' GROUP BY ' . $dayExpr . ', ' . $pathCol . ', ' . $objCol
            . ' ORDER BY day ASC, path ASC';

        try {
            $rows = $db->getResults($sql, $params);
        } catch (Throwable) {
            return 0;
        }

        $startDay = substr((string) $args['since'], 0, 10);
        $endExclusive = substr((string) $args['until'], 0, 10);

        // Clear existing daily rows in range so rebuild is idempotent.
        try {
            $dailyTable = $db->quoteIdentifier($db->table('analytics_daily'));
            $dayCol = $db->quoteIdentifier('day');
            $db->query(
                'DELETE FROM ' . $dailyTable . ' WHERE ' . $dayCol . ' >= ? AND ' . $dayCol . ' < ?',
                [$startDay, $endExclusive]
            );
        } catch (Throwable) {
            return 0;
        }

        $written = 0;
        foreach ($rows as $row) {
            $day = (string) ($row->day ?? '');
            if (strlen($day) > 10) {
                $day = substr($day, 0, 10);
            }
            if ($day === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
                continue;
            }
            $path = self::normalizePath((string) ($row->path ?? '/'));
            $objectId = max(0, (int) ($row->object_id ?? 0));
            $hits = max(0, (int) ($row->hits ?? 0));
            if ($hits < 1) {
                continue;
            }

            try {
                $result = $db->insert('analytics_daily', [
                    'day' => $day,
                    'path' => $path,
                    'object_id' => $objectId,
                    'hits' => $hits,
                ]);
            } catch (Throwable) {
                continue;
            }
            if ($result !== false) {
                $written++;
            }
        }

        if (function_exists('ap_do_action') && $written > 0) {
            try {
                ap_do_action('ap_analytics_rolled_up', $written, [
                    'since' => $args['since'],
                    'until' => $args['until'],
                ], $db);
            } catch (Throwable) {
                // Hooks must not undo rollup.
            }
        }

        return $written;
    }

    /**
     * Inclusive/exclusive datetime bounds for a calendar day (local time).
     *
     * @return array{since: string, until: string}
     */
    public static function dayBounds(string $day): array
    {
        $day = trim($day);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
            $day = date('Y-m-d');
        }
        $ts = strtotime($day . ' 00:00:00');
        if ($ts === false) {
            $ts = strtotime(date('Y-m-d') . ' 00:00:00') ?: time();
            $day = date('Y-m-d', $ts);
        }

        return [
            'since' => $day . ' 00:00:00',
            'until' => date('Y-m-d 00:00:00', $ts + 86400),
        ];
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    private static function resolveDb(?AP_DB $db): ?AP_DB
    {
        if ($db instanceof AP_DB) {
            return $db;
        }
        if (function_exists('ap_db')) {
            try {
                $resolved = ap_db();
                if ($resolved instanceof AP_DB) {
                    return $resolved;
                }
            } catch (Throwable) {
                return null;
            }
        }
        if (isset($GLOBALS['apdb']) && $GLOBALS['apdb'] instanceof AP_DB) {
            return $GLOBALS['apdb'];
        }

        return null;
    }

    private static function isCliContext(): bool
    {
        $cli = false;
        if (defined('AP_CLI') && AP_CLI) {
            $cli = true;
        } else {
            $sapi = PHP_SAPI;
            $cli = $sapi === 'cli' || $sapi === 'phpdbg';
        }

        // Tests may force a web context: filter `ap_analytics_cli_context`.
        if (function_exists('ap_apply_filters')) {
            return (bool) ap_apply_filters('ap_analytics_cli_context', $cli);
        }

        return $cli;
    }

    private static function isDoNotTrack(): bool
    {
        $dnt = $_SERVER['HTTP_DNT'] ?? null;
        if ($dnt === null || $dnt === '') {
            return false;
        }

        return (string) $dnt === '1';
    }

    private static function currentRequestPath(): string
    {
        $uri = '';
        if (isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI'])) {
            $uri = $_SERVER['REQUEST_URI'];
        } elseif (isset($_SERVER['SCRIPT_NAME']) && is_string($_SERVER['SCRIPT_NAME'])) {
            $uri = $_SERVER['SCRIPT_NAME'];
        }

        $path = (string) (parse_url($uri, PHP_URL_PATH) ?: '');
        if ($path === '' && $uri !== '' && !str_contains($uri, '://')) {
            $path = explode('?', $uri, 2)[0];
        }

        // Strip site home path prefix when available so stored paths are site-relative.
        if ($path !== '' && function_exists('ap_resolve_home_base')) {
            try {
                $home = (string) ap_resolve_home_base();
                $homePath = (string) (parse_url($home, PHP_URL_PATH) ?: '');
                $homePath = rtrim($homePath, '/');
                if ($homePath !== '' && $homePath !== '/' && str_starts_with($path, $homePath)) {
                    $path = substr($path, strlen($homePath)) ?: '/';
                }
            } catch (Throwable) {
                // Keep raw path.
            }
        }

        return self::normalizePath($path !== '' ? $path : '/');
    }

    private static function isAdminPath(string $path): bool
    {
        $path = strtolower($path);
        if (str_starts_with($path, '/ap-admin') || str_contains($path, '/ap-admin/')) {
            return true;
        }
        // Plain query-style install/admin entry points.
        if (str_contains($path, 'ap-admin')) {
            return true;
        }

        return false;
    }

    /**
     * Paths that are not human page views (feeds, API, sitemaps, install).
     */
    private static function isNonContentPath(string $path): bool
    {
        $path = strtolower($path);

        if ($path === '/robots.txt' || str_ends_with($path, '/robots.txt')) {
            return true;
        }
        // /sitemap.xml, /sitemap_index.xml, /wp-sitemap.xml, etc.
        if (str_contains($path, 'sitemap')) {
            return true;
        }
        if ($path === '/ap-json' || str_starts_with($path, '/ap-json/')) {
            return true;
        }
        // Exact /feed or /feed/… — not /feedback.
        if ($path === '/feed' || str_starts_with($path, '/feed/') || str_ends_with($path, '/feed')) {
            return true;
        }
        if ($path === '/install' || str_starts_with($path, '/install/')) {
            return true;
        }

        // Query-string style detected from REQUEST_URI query.
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        $query = (string) (parse_url($uri, PHP_URL_QUERY) ?: '');
        if ($query !== '') {
            parse_str($query, $q);
            if (isset($q['feed']) || isset($q['rest_route']) || isset($q['sitemap']) || isset($q['robots'])) {
                return true;
            }
        }

        return false;
    }

    private static function isNonContentRequestFromGlobals(): bool
    {
        $vars = null;
        if (isset($GLOBALS['ap_rewrite_vars']) && is_array($GLOBALS['ap_rewrite_vars'])) {
            $vars = $GLOBALS['ap_rewrite_vars'];
        }

        // index.php keeps $apRewriteVars locally; also peek main query flags.
        if (isset($GLOBALS['ap_query']) && is_object($GLOBALS['ap_query'])) {
            $q = $GLOBALS['ap_query'];
            if (isset($q->is_feed) && $q->is_feed) {
                return true;
            }
        }

        if (!is_array($vars)) {
            return false;
        }

        if (class_exists('AP_Feed', false) && method_exists('AP_Feed', 'isFeedRequest') && AP_Feed::isFeedRequest($vars)) {
            return true;
        }
        if (class_exists('AP_Rest', false) && method_exists('AP_Rest', 'isRestRequest') && AP_Rest::isRestRequest($vars)) {
            return true;
        }
        if (class_exists('AP_Sitemap', false)) {
            if (method_exists('AP_Sitemap', 'isSitemapRequest') && AP_Sitemap::isSitemapRequest($vars)) {
                return true;
            }
            if (method_exists('AP_Sitemap', 'isRobotsRequest') && AP_Sitemap::isRobotsRequest($vars)) {
                return true;
            }
        }

        return false;
    }

    private static function currentStatusCode(): int
    {
        if (isset($GLOBALS['ap_query']) && is_object($GLOBALS['ap_query'])) {
            $q = $GLOBALS['ap_query'];
            if (isset($q->is_404) && $q->is_404) {
                return 404;
            }
        }

        $code = http_response_code();
        if (is_int($code) && $code >= 100 && $code <= 599) {
            return $code;
        }

        return 200;
    }

    private static function currentUserIsAdmin(?AP_DB $db): bool
    {
        if (function_exists('ap_current_user_can')) {
            try {
                return ap_current_user_can('manage_options', $db);
            } catch (Throwable) {
                return false;
            }
        }
        if (class_exists('AP_Roles', false) && method_exists('AP_Roles', 'currentUserCan')) {
            try {
                return AP_Roles::currentUserCan('manage_options', $db);
            } catch (Throwable) {
                return false;
            }
        }

        return false;
    }

    private static function nowLocal(): string
    {
        return date('Y-m-d H:i:s');
    }

    private static function truncateUtf8(string $value, int $max): string
    {
        if ($max < 1) {
            return '';
        }
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($value, 'UTF-8') <= $max) {
                return $value;
            }

            return mb_substr($value, 0, $max, 'UTF-8');
        }
        if (strlen($value) <= $max) {
            return $value;
        }

        return substr($value, 0, $max);
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    private static function normalizeReportArgs(array $args): array
    {
        if (isset($args['day']) && is_string($args['day']) && $args['day'] !== ''
            && (!isset($args['since']) || $args['since'] === '')
        ) {
            $bounds = self::dayBounds($args['day']);
            $args['since'] = $bounds['since'];
            $args['until'] = $bounds['until'];
        }

        if (isset($args['path']) && is_string($args['path'])) {
            $args['path'] = self::normalizePath($args['path']);
        }

        if (isset($args['ua_class']) && is_string($args['ua_class'])) {
            $args['ua_class'] = self::sanitizeUaClass($args['ua_class']);
        }

        // Default for report reads: exclude admin-flagged hits.
        if (!array_key_exists('exclude_admin', $args)) {
            $args['exclude_admin'] = true;
        }

        return $args;
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array{0: string, 1: list<mixed>} Full SELECT SQL + params.
     */
    private static function buildHitsWhere(AP_DB $db, array $args, string $selectExpr): array
    {
        $table = $db->quoteIdentifier($db->table('analytics_hits'));
        [$whereSql, $params] = self::buildHitsWhereClause($db, $args);

        return ['SELECT ' . $selectExpr . ' FROM ' . $table . $whereSql, $params];
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array{0: string, 1: list<mixed>} WHERE clause (leading space + WHERE …) and params.
     */
    private static function buildHitsWhereClause(AP_DB $db, array $args): array
    {
        $parts = [];
        $params = [];

        if (isset($args['since']) && is_string($args['since']) && $args['since'] !== '') {
            $parts[] = $db->quoteIdentifier('hit_time') . ' >= ?';
            $params[] = $args['since'];
        }
        if (isset($args['until']) && is_string($args['until']) && $args['until'] !== '') {
            $parts[] = $db->quoteIdentifier('hit_time') . ' < ?';
            $params[] = $args['until'];
        }
        if (isset($args['path']) && is_string($args['path']) && $args['path'] !== '') {
            $parts[] = $db->quoteIdentifier('path') . ' = ?';
            $params[] = $args['path'];
        }
        if (array_key_exists('object_id', $args) && $args['object_id'] !== null && $args['object_id'] !== '') {
            $parts[] = $db->quoteIdentifier('object_id') . ' = ?';
            $params[] = max(0, (int) $args['object_id']);
        }
        if (isset($args['status_code']) && $args['status_code'] !== '' && $args['status_code'] !== null) {
            $parts[] = $db->quoteIdentifier('status_code') . ' = ?';
            $params[] = (int) $args['status_code'];
        }
        if (isset($args['ua_class']) && is_string($args['ua_class']) && $args['ua_class'] !== '') {
            $parts[] = $db->quoteIdentifier('ua_class') . ' = ?';
            $params[] = $args['ua_class'];
        }
        if (!empty($args['exclude_admin'])) {
            $parts[] = $db->quoteIdentifier('is_admin') . ' = 0';
        }

        if ($parts === []) {
            return ['', []];
        }

        return [' WHERE ' . implode(' AND ', $parts), $params];
    }

    /**
     * Driver-specific expression that yields YYYY-MM-DD from a datetime column.
     */
    private static function sqlDayExpression(AP_DB $db, string $column): string
    {
        $col = $db->quoteIdentifier($column);

        return match ($db->getDriver()) {
            'mysql' => 'DATE(' . $col . ')',
            'pgsql' => '(' . $col . ')::date',
            default => 'substr(' . $col . ', 1, 10)',
        };
    }

    private static function clampLimit(mixed $value, int $default, int $max): int
    {
        if (!is_numeric($value)) {
            return $default;
        }
        $n = (int) $value;
        if ($n < 1) {
            return $default;
        }

        return min($max, $n);
    }
}
