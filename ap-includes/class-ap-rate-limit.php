<?php

/**
 * AgoraPress rate limiting and login protection.
 *
 * Transient-backed sliding windows with optional lockout after max attempts.
 * Used for login brute-force protection, registration / password-reset floods,
 * upload throttling, and outbound mail. Keys are hashed so sensitive
 * identifiers are never stored in plain form.
 *
 * Options (optional overrides; defaults are secure for shared hosting):
 * - rate_limit_{action}_max       max attempts in the window
 * - rate_limit_{action}_window    window length in seconds
 * - rate_limit_{action}_lockout   lockout length after max is exceeded
 *
 * Actions: {@see self::ACTION_LOGIN}, {@see self::ACTION_REGISTER},
 * {@see self::ACTION_PASSWORD_RESET}, {@see self::ACTION_UPLOAD},
 * {@see self::ACTION_MAIL}, {@see self::ACTION_CURRENT_PASSWORD}.
 *
 * Failed current-password checks reuse this transient store (no separate
 * table). They are counted per user id and per IP, with their own action so
 * they do not consume login buckets. Defaults are 5 failures / 15 minutes.
 * Override with `rate_limit_current_password_max` / `_window` / `_lockout`
 * or the `ap_current_password_rate_limits` filter.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * Generic rate limiter + auth-facing helpers.
 */
class AP_Rate_Limit
{
    public const ACTION_LOGIN = 'login';

    public const ACTION_REGISTER = 'register';

    public const ACTION_PASSWORD_RESET = 'password_reset';

    public const ACTION_UPLOAD = 'upload';

    /** Outbound mail (IP + recipient). Applied in {@see AP_Mail::send()}. */
    public const ACTION_MAIL = 'mail';

    /**
     * Failed current-password checks on the signed-in user's own profile.
     * Buckets are {@see userBucket()} and {@see ipBucket()}.
     */
    public const ACTION_CURRENT_PASSWORD = 'current_password';

    /**
     * Filter for {@see ACTION_CURRENT_PASSWORD} limits.
     * Callbacks receive `array{max, window, lockout}` and the user id (0 when
     * the limit is read outside a per-user check).
     */
    public const FILTER_CURRENT_PASSWORD_LIMITS = 'ap_current_password_rate_limits';

    /**
     * Start of the profile lockout sentence. The wait, in minutes, follows.
     */
    public const CURRENT_PASSWORD_NOTICE_PREFIX = 'Too many attempts, try again in ';

    /** Transient key prefix (kept short for Options name limits). */
    private const TRANSIENT_PREFIX = 'ap_rl_';

    /**
     * Default limits per action: max attempts, window seconds, lockout seconds.
     *
     * @var array<string, array{max: int, window: int, lockout: int}>
     */
    private static array $defaults = [
        self::ACTION_LOGIN => [
            'max' => 5,
            'window' => 900,
            'lockout' => 900,
        ],
        self::ACTION_REGISTER => [
            'max' => 5,
            'window' => 3600,
            'lockout' => 3600,
        ],
        self::ACTION_PASSWORD_RESET => [
            'max' => 5,
            'window' => 3600,
            'lockout' => 1800,
        ],
        self::ACTION_UPLOAD => [
            'max' => 40,
            'window' => 600,
            'lockout' => 300,
        ],
        self::ACTION_MAIL => [
            'max' => 20,
            'window' => 3600,
            'lockout' => 3600,
        ],
        self::ACTION_CURRENT_PASSWORD => [
            'max' => 5,
            'window' => 900,
            'lockout' => 900,
        ],
    ];

    /**
     * Last evaluation for callers that need a message after a blocked attempt.
     *
     * @var array{
     *   action: string,
     *   bucket: string,
     *   allowed: bool,
     *   attempts: int,
     *   limit: int,
     *   remaining: int,
     *   retry_after: int,
     *   locked: bool
     * }|null
     */
    private static ?array $lastResult = null;

    /**
     * Runtime overrides for tests (action => config partial).
     *
     * @var array<string, array{max?: int, window?: int, lockout?: int}>
     */
    private static array $testOverrides = [];

    /**
     * When true, rate limiting is disabled (unit tests that need unlimited hits).
     */
    private static bool $disabled = false;

    /**
     * User id passed to {@see FILTER_CURRENT_PASSWORD_LIMITS} during a check.
     */
    private static int $currentPasswordUserId = 0;

    /**
     * Re-entry guard so a limits filter cannot recurse through {@see getLimits()}.
     */
    private static bool $applyingCurrentPasswordFilter = false;

    // -------------------------------------------------------------------------
    // Test helpers
    // -------------------------------------------------------------------------

    /**
     * Disable rate limiting entirely (tests).
     */
    public static function disable(): void
    {
        self::$disabled = true;
    }

    /**
     * Re-enable rate limiting after {@see disable()}.
     */
    public static function enable(): void
    {
        self::$disabled = false;
    }

    /**
     * Whether the limiter is currently disabled.
     */
    public static function isDisabled(): bool
    {
        return self::$disabled;
    }

    /**
     * Override limits for one action (tests). Pass null to clear that action.
     *
     * @param array{max?: int, window?: int, lockout?: int}|null $config
     */
    public static function setTestLimits(string $action, ?array $config): void
    {
        $action = self::normalizeAction($action);
        if ($config === null) {
            unset(self::$testOverrides[$action]);

            return;
        }
        self::$testOverrides[$action] = $config;
    }

    /**
     * Clear all test overrides and re-enable.
     */
    public static function resetTestState(): void
    {
        self::$testOverrides = [];
        self::$disabled = false;
        self::$lastResult = null;
        self::$currentPasswordUserId = 0;
        self::$applyingCurrentPasswordFilter = false;
    }

    // -------------------------------------------------------------------------
    // Limits
    // -------------------------------------------------------------------------

    /**
     * Resolved max / window / lockout for an action.
     *
     * @return array{max: int, window: int, lockout: int}
     */
    public static function getLimits(string $action, ?AP_DB $db = null): array
    {
        $action = self::normalizeAction($action);
        $base = self::$defaults[$action] ?? [
            'max' => 10,
            'window' => 600,
            'lockout' => 600,
        ];

        if (isset(self::$testOverrides[$action])) {
            $base = array_merge($base, self::$testOverrides[$action]);
        }

        $max = self::optionInt('rate_limit_' . $action . '_max', $base['max'], $db);
        $window = self::optionInt('rate_limit_' . $action . '_window', $base['window'], $db);
        $lockout = self::optionInt('rate_limit_' . $action . '_lockout', $base['lockout'], $db);

        $limits = [
            'max' => max(1, min(1000, $max)),
            'window' => max(30, min(86400, $window)),
            'lockout' => max(0, min(86400, $lockout)),
        ];

        if ($action === self::ACTION_CURRENT_PASSWORD) {
            return self::applyCurrentPasswordLimitFilter($limits);
        }

        return $limits;
    }

    /**
     * Whether the bucket is currently blocked.
     */
    public static function isLimited(string $action, string $bucket = '', ?AP_DB $db = null): bool
    {
        return !self::check($action, $bucket, $db)['allowed'];
    }

    /**
     * Seconds until the bucket may try again (0 when free).
     */
    public static function retryAfter(string $action, string $bucket = '', ?AP_DB $db = null): int
    {
        return self::check($action, $bucket, $db)['retry_after'];
    }

    /**
     * Inspect a bucket without recording a hit.
     *
     * @return array{
     *   action: string,
     *   bucket: string,
     *   allowed: bool,
     *   attempts: int,
     *   limit: int,
     *   remaining: int,
     *   retry_after: int,
     *   locked: bool
     * }
     */
    public static function check(string $action, string $bucket = '', ?AP_DB $db = null): array
    {
        $action = self::normalizeAction($action);
        $bucket = self::normalizeBucket($bucket);
        $limits = self::getLimits($action, $db);

        $result = [
            'action' => $action,
            'bucket' => $bucket,
            'allowed' => true,
            'attempts' => 0,
            'limit' => $limits['max'],
            'remaining' => $limits['max'],
            'retry_after' => 0,
            'locked' => false,
        ];

        if (self::$disabled) {
            self::$lastResult = $result;

            return $result;
        }

        $state = self::readState($action, $bucket, $db);
        $now = time();

        if ($state['locked_until'] > $now) {
            $result['allowed'] = false;
            $result['locked'] = true;
            $result['attempts'] = $state['attempts'];
            $result['remaining'] = 0;
            $result['retry_after'] = max(1, $state['locked_until'] - $now);
            self::$lastResult = $result;

            return $result;
        }

        // Expired lock or window → treat as fresh.
        if (
            $state['window_start'] < 1
            || ($now - $state['window_start']) >= $limits['window']
        ) {
            $result['attempts'] = 0;
            $result['remaining'] = $limits['max'];
            self::$lastResult = $result;

            return $result;
        }

        $result['attempts'] = $state['attempts'];
        $result['remaining'] = max(0, $limits['max'] - $state['attempts']);
        if ($state['attempts'] >= $limits['max']) {
            $result['allowed'] = false;
            $result['remaining'] = 0;
            // Soft block until window ends when lockout is 0.
            $until = $state['window_start'] + $limits['window'];
            if ($state['locked_until'] > $now) {
                $until = $state['locked_until'];
                $result['locked'] = true;
            }
            $result['retry_after'] = max(1, $until - $now);
        }

        self::$lastResult = $result;

        return $result;
    }

    /**
     * Record one attempt. Returns the post-hit check result.
     *
     * When the attempt count reaches max, applies lockout (if configured).
     *
     * @return array{
     *   action: string,
     *   bucket: string,
     *   allowed: bool,
     *   attempts: int,
     *   limit: int,
     *   remaining: int,
     *   retry_after: int,
     *   locked: bool
     * }
     */
    public static function hit(string $action, string $bucket = '', ?AP_DB $db = null): array
    {
        $action = self::normalizeAction($action);
        $bucket = self::normalizeBucket($bucket);
        $limits = self::getLimits($action, $db);

        if (self::$disabled) {
            return self::check($action, $bucket, $db);
        }

        // Already locked — do not extend / reset counters on extra hits.
        $pre = self::check($action, $bucket, $db);
        if (!$pre['allowed'] && $pre['locked']) {
            return $pre;
        }

        $now = time();
        $state = self::readState($action, $bucket, $db);

        if (
            $state['window_start'] < 1
            || ($now - $state['window_start']) >= $limits['window']
        ) {
            $state = [
                'attempts' => 0,
                'window_start' => $now,
                'locked_until' => 0,
            ];
        }

        $state['attempts']++;

        if ($state['attempts'] >= $limits['max']) {
            $lockout = $limits['lockout'] > 0 ? $limits['lockout'] : $limits['window'];
            $state['locked_until'] = $now + $lockout;
        }

        self::writeState($action, $bucket, $state, $limits, $db);

        return self::check($action, $bucket, $db);
    }

    /**
     * Clear counters for a bucket (e.g. successful login).
     */
    public static function clear(string $action, string $bucket = '', ?AP_DB $db = null): bool
    {
        $action = self::normalizeAction($action);
        $bucket = self::normalizeBucket($bucket);
        $name = self::transientName($action, $bucket);

        if (!class_exists('AP_Transient', false)) {
            return true;
        }

        return AP_Transient::delete($name, $db);
    }

    /**
     * Most recent check/hit result (tests / UI).
     *
     * @return array<string, mixed>|null
     */
    public static function getLastResult(): ?array
    {
        return self::$lastResult;
    }

    // -------------------------------------------------------------------------
    // Client identity helpers
    // -------------------------------------------------------------------------

    /**
     * Best-effort client IP for rate buckets.
     *
     * Uses REMOTE_ADDR by default (cannot be spoofed by the client without a
     * proxy). When {@see AP_TRUST_PROXY} is true, also consults
     * X-Forwarded-For / X-Real-IP (first public hop).
     */
    public static function clientIp(): string
    {
        $trustProxy = defined('AP_TRUST_PROXY') && AP_TRUST_PROXY;

        if ($trustProxy) {
            foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP'] as $key) {
                if (empty($_SERVER[$key]) || !is_string($_SERVER[$key])) {
                    continue;
                }
                $raw = $_SERVER[$key];
                if (str_contains($raw, ',')) {
                    $raw = trim(explode(',', $raw)[0]);
                }
                $ip = self::sanitizeIp($raw);
                if ($ip !== '') {
                    return $ip;
                }
            }
        }

        if (!empty($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR'])) {
            return self::sanitizeIp($_SERVER['REMOTE_ADDR']);
        }

        return '';
    }

    /**
     * Normalize a login/email for identity buckets (lowercase, trimmed, hashed).
     */
    public static function identityBucket(string $loginOrEmail): string
    {
        $id = strtolower(trim($loginOrEmail));
        if ($id === '') {
            return 'id:empty';
        }
        // Hash so raw emails/logins never sit in option names / values long-term.
        return 'id:' . hash('sha256', $id);
    }

    /**
     * IP bucket key (hashed when non-empty).
     */
    public static function ipBucket(string $ip = ''): string
    {
        $ip = $ip !== '' ? self::sanitizeIp($ip) : self::clientIp();
        if ($ip === '') {
            return 'ip:unknown';
        }

        return 'ip:' . hash('sha256', $ip);
    }

    /**
     * User-id bucket for authenticated actions (uploads).
     */
    public static function userBucket(int $userId): string
    {
        if ($userId < 1) {
            return 'user:0';
        }

        return 'user:' . $userId;
    }

    /**
     * Human-readable lockout message for forms.
     */
    public static function lockoutMessage(int $retryAfter, string $context = 'try again'): string
    {
        $retryAfter = max(1, $retryAfter);
        if ($retryAfter < 60) {
            $when = $retryAfter . ' second' . ($retryAfter === 1 ? '' : 's');
        } elseif ($retryAfter < 3600) {
            $mins = (int) ceil($retryAfter / 60);
            $when = $mins . ' minute' . ($mins === 1 ? '' : 's');
        } else {
            $hours = (int) ceil($retryAfter / 3600);
            $when = $hours . ' hour' . ($hours === 1 ? '' : 's');
        }

        return 'Too many attempts. Please ' . $context . ' in ' . $when . '.';
    }

    /**
     * Validate / sanitize an IP string.
     */
    public static function sanitizeIp(string $ip): string
    {
        $ip = trim($ip);
        if ($ip === '') {
            return '';
        }
        // Strip zone id (fe80::1%eth0).
        if (str_contains($ip, '%')) {
            $ip = explode('%', $ip, 2)[0];
        }
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return '';
        }

        return $ip;
    }

    // -------------------------------------------------------------------------
    // Auth-facing composite checks
    // -------------------------------------------------------------------------

    /**
     * Whether login is blocked for this IP and/or identity (either bucket).
     *
     * @return array{allowed: bool, retry_after: int, message: string}
     */
    public static function checkLogin(string $loginOrEmail = '', string $ip = '', ?AP_DB $db = null): array
    {
        $ipKey = self::ipBucket($ip);
        $idKey = self::identityBucket($loginOrEmail);

        $ipCheck = self::check(self::ACTION_LOGIN, $ipKey, $db);
        $idCheck = $loginOrEmail !== ''
            ? self::check(self::ACTION_LOGIN, $idKey, $db)
            : ['allowed' => true, 'retry_after' => 0];

        if ($ipCheck['allowed'] && $idCheck['allowed']) {
            return ['allowed' => true, 'retry_after' => 0, 'message' => ''];
        }

        $retry = max((int) $ipCheck['retry_after'], (int) $idCheck['retry_after']);

        return [
            'allowed' => false,
            'retry_after' => $retry,
            'message' => self::lockoutMessage($retry, 'try signing in again'),
        ];
    }

    /**
     * Record a failed login against IP + identity buckets.
     *
     * @return array{allowed: bool, retry_after: int, message: string}
     */
    public static function recordFailedLogin(
        string $loginOrEmail = '',
        string $ip = '',
        ?AP_DB $db = null
    ): array {
        $ipKey = self::ipBucket($ip);
        $afterIp = self::hit(self::ACTION_LOGIN, $ipKey, $db);

        $afterId = ['allowed' => true, 'retry_after' => 0];
        if (trim($loginOrEmail) !== '') {
            $afterId = self::hit(self::ACTION_LOGIN, self::identityBucket($loginOrEmail), $db);
        }

        if ($afterIp['allowed'] && $afterId['allowed']) {
            return ['allowed' => true, 'retry_after' => 0, 'message' => ''];
        }

        $retry = max((int) $afterIp['retry_after'], (int) $afterId['retry_after']);

        return [
            'allowed' => false,
            'retry_after' => $retry,
            'message' => self::lockoutMessage($retry, 'try signing in again'),
        ];
    }

    /**
     * Clear login failure counters after a successful authentication.
     */
    public static function clearLogin(string $loginOrEmail = '', string $ip = '', ?AP_DB $db = null): void
    {
        self::clear(self::ACTION_LOGIN, self::ipBucket($ip), $db);
        if (trim($loginOrEmail) !== '') {
            self::clear(self::ACTION_LOGIN, self::identityBucket($loginOrEmail), $db);
        }
    }

    /**
     * Whether current-password checks are blocked for this user and/or IP.
     *
     * Either bucket blocks. Storage is the same transient store as login.
     *
     * @return array{allowed: bool, retry_after: int, message: string}
     */
    public static function checkCurrentPassword(int $userId, string $ip = '', ?AP_DB $db = null): array
    {
        return self::withCurrentPasswordUser(
            $userId,
            static fn (): array => self::evaluateCurrentPassword($userId, $ip, $db, false)
        );
    }

    /**
     * Record one failed current-password check against the user and IP buckets.
     *
     * A correct password must call {@see clearCurrentPassword()} instead.
     * The submitted password is not stored.
     *
     * @return array{allowed: bool, retry_after: int, message: string}
     */
    public static function recordFailedCurrentPassword(int $userId, string $ip = '', ?AP_DB $db = null): array
    {
        return self::withCurrentPasswordUser(
            $userId,
            static fn (): array => self::evaluateCurrentPassword($userId, $ip, $db, true)
        );
    }

    /**
     * Clear current-password failure counters after a successful check.
     */
    public static function clearCurrentPassword(int $userId, string $ip = '', ?AP_DB $db = null): void
    {
        self::clear(self::ACTION_CURRENT_PASSWORD, self::ipBucket($ip), $db);
        if ($userId > 0) {
            self::clear(self::ACTION_CURRENT_PASSWORD, self::userBucket($userId), $db);
        }
    }

    /**
     * Profile notice for a current-password lockout. Wait is always in minutes.
     */
    public static function currentPasswordLockoutMessage(int $retryAfter): string
    {
        $minutes = max(1, (int) ceil(max(1, $retryAfter) / 60));
        $unit = $minutes === 1 ? 'minute' : 'minutes';

        return self::CURRENT_PASSWORD_NOTICE_PREFIX . $minutes . ' ' . $unit . '.';
    }

    /**
     * Whether outbound mail is blocked for this IP and/or recipient.
     *
     * Either bucket can block (one client flooding SMTP, or one inbox being
     * targeted). {@see AP_Mail::send()} is the only core caller.
     *
     * @param string|list<string> $recipients
     *
     * @return array{allowed: bool, retry_after: int, message: string}
     */
    public static function checkMail(
        string|array $recipients = '',
        string $ip = '',
        ?AP_DB $db = null
    ): array {
        $ipCheck = self::check(self::ACTION_MAIL, self::ipBucket($ip), $db);
        $retry = (int) $ipCheck['retry_after'];
        $allowed = (bool) $ipCheck['allowed'];

        foreach (self::mailRecipientList($recipients) as $email) {
            $idCheck = self::check(self::ACTION_MAIL, self::identityBucket($email), $db);
            if (!$idCheck['allowed']) {
                $allowed = false;
                $retry = max($retry, (int) $idCheck['retry_after']);
            }
        }

        if ($allowed) {
            return ['allowed' => true, 'retry_after' => 0, 'message' => ''];
        }

        return [
            'allowed' => false,
            'retry_after' => $retry,
            'message' => self::lockoutMessage($retry, 'try again'),
        ];
    }

    /**
     * Record one outbound-mail attempt against IP + recipient buckets.
     *
     * @param string|list<string> $recipients
     *
     * @return array{allowed: bool, retry_after: int, message: string}
     */
    public static function recordMail(
        string|array $recipients = '',
        string $ip = '',
        ?AP_DB $db = null
    ): array {
        $afterIp = self::hit(self::ACTION_MAIL, self::ipBucket($ip), $db);
        $retry = (int) $afterIp['retry_after'];
        $allowed = (bool) $afterIp['allowed'];

        foreach (self::mailRecipientList($recipients) as $email) {
            $afterId = self::hit(self::ACTION_MAIL, self::identityBucket($email), $db);
            if (!$afterId['allowed']) {
                $allowed = false;
                $retry = max($retry, (int) $afterId['retry_after']);
            }
        }

        if ($allowed) {
            return ['allowed' => true, 'retry_after' => 0, 'message' => ''];
        }

        return [
            'allowed' => false,
            'retry_after' => $retry,
            'message' => self::lockoutMessage($retry, 'try again'),
        ];
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * Run a current-password limit lookup with the user id visible to filters.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private static function withCurrentPasswordUser(int $userId, callable $callback): mixed
    {
        $previous = self::$currentPasswordUserId;
        self::$currentPasswordUserId = max(0, $userId);
        try {
            return $callback();
        } finally {
            self::$currentPasswordUserId = $previous;
        }
    }

    /**
     * Inspect or record current-password buckets (user id and IP).
     *
     * @return array{allowed: bool, retry_after: int, message: string}
     */
    private static function evaluateCurrentPassword(
        int $userId,
        string $ip,
        ?AP_DB $db,
        bool $record
    ): array {
        $action = self::ACTION_CURRENT_PASSWORD;
        $ipKey = self::ipBucket($ip);
        if ($record) {
            $ipResult = self::hit($action, $ipKey, $db);
            $userResult = $userId > 0
                ? self::hit($action, self::userBucket($userId), $db)
                : ['allowed' => true, 'retry_after' => 0];
        } else {
            $ipResult = self::check($action, $ipKey, $db);
            $userResult = $userId > 0
                ? self::check($action, self::userBucket($userId), $db)
                : ['allowed' => true, 'retry_after' => 0];
        }

        if ($ipResult['allowed'] && $userResult['allowed']) {
            return ['allowed' => true, 'retry_after' => 0, 'message' => ''];
        }

        $retry = max((int) $ipResult['retry_after'], (int) $userResult['retry_after']);

        return [
            'allowed' => false,
            'retry_after' => $retry,
            'message' => self::currentPasswordLockoutMessage($retry),
        ];
    }

    /**
     * Let plugins replace current-password max / window / lockout.
     *
     * The hook name is a literal so it stays discoverable. Invalid returns
     * are ignored. Each numeric field is clamped; a non-numeric field keeps
     * the resolved value.
     *
     * @param array{max: int, window: int, lockout: int} $limits
     *
     * @return array{max: int, window: int, lockout: int}
     */
    private static function applyCurrentPasswordLimitFilter(array $limits): array
    {
        if (self::$applyingCurrentPasswordFilter || !function_exists('ap_apply_filters')) {
            return $limits;
        }

        self::$applyingCurrentPasswordFilter = true;
        try {
            // Literal hook name (kept equal to FILTER_CURRENT_PASSWORD_LIMITS).
            if (self::FILTER_CURRENT_PASSWORD_LIMITS !== 'ap_current_password_rate_limits') {
                return $limits;
            }
            $filtered = ap_apply_filters(
                'ap_current_password_rate_limits',
                $limits,
                self::$currentPasswordUserId
            );
        } finally {
            self::$applyingCurrentPasswordFilter = false;
        }

        if (!is_array($filtered)) {
            return $limits;
        }

        return [
            'max' => self::boundedLimit($filtered['max'] ?? null, $limits['max'], 1, 1000),
            'window' => self::boundedLimit($filtered['window'] ?? null, $limits['window'], 30, 86400),
            'lockout' => self::boundedLimit($filtered['lockout'] ?? null, $limits['lockout'], 0, 86400),
        ];
    }

    /**
     * Clamp one filtered limit field, or keep the fallback when it is not numeric.
     */
    private static function boundedLimit(mixed $value, int $fallback, int $min, int $max): int
    {
        if (!is_numeric($value)) {
            return $fallback;
        }

        return max($min, min($max, (int) $value));
    }

    /**
     * @return array{attempts: int, window_start: int, locked_until: int}
     */
    private static function readState(string $action, string $bucket, ?AP_DB $db): array
    {
        $empty = ['attempts' => 0, 'window_start' => 0, 'locked_until' => 0];
        if (!class_exists('AP_Transient', false)) {
            return $empty;
        }

        $raw = AP_Transient::get(self::transientName($action, $bucket), false, $db);
        if (!is_array($raw)) {
            return $empty;
        }

        return [
            'attempts' => max(0, (int) ($raw['attempts'] ?? 0)),
            'window_start' => max(0, (int) ($raw['window_start'] ?? 0)),
            'locked_until' => max(0, (int) ($raw['locked_until'] ?? 0)),
        ];
    }

    /**
     * @param array{attempts: int, window_start: int, locked_until: int} $state
     * @param array{max: int, window: int, lockout: int}                 $limits
     */
    private static function writeState(
        string $action,
        string $bucket,
        array $state,
        array $limits,
        ?AP_DB $db
    ): void {
        if (!class_exists('AP_Transient', false)) {
            return;
        }

        // Keep the transient at least until lockout/window ends (+ small grace).
        $ttl = max($limits['window'], $limits['lockout']) + 60;
        if ($state['locked_until'] > 0) {
            $ttl = max($ttl, ($state['locked_until'] - time()) + 60);
        }

        AP_Transient::set(
            self::transientName($action, $bucket),
            [
                'attempts' => (int) $state['attempts'],
                'window_start' => (int) $state['window_start'],
                'locked_until' => (int) $state['locked_until'],
            ],
            max(60, $ttl),
            $db
        );
    }

    private static function transientName(string $action, string $bucket): string
    {
        // Hash the full key so option names stay short and opaque.
        $hash = hash('sha256', $action . '|' . $bucket);

        return self::TRANSIENT_PREFIX . substr($hash, 0, 40);
    }

    /**
     * Unique trimmed recipient addresses for mail buckets.
     *
     * @param string|list<string> $recipients
     *
     * @return list<string>
     */
    private static function mailRecipientList(string|array $recipients): array
    {
        $raw = is_array($recipients) ? $recipients : [$recipients];
        $out = [];
        foreach ($raw as $email) {
            $email = strtolower(trim((string) $email));
            if ($email === '' || isset($out[$email])) {
                continue;
            }
            $out[$email] = $email;
        }

        return array_values($out);
    }

    private static function normalizeAction(string $action): string
    {
        $action = strtolower(trim($action));
        $action = preg_replace('/[^a-z0-9_]+/', '_', $action) ?? 'default';
        $action = trim($action, '_');

        return $action !== '' ? $action : 'default';
    }

    private static function normalizeBucket(string $bucket): string
    {
        $bucket = trim($bucket);
        if ($bucket === '') {
            return 'default';
        }
        // Cap length; already-hashed buckets are short.
        if (strlen($bucket) > 200) {
            return 'b:' . hash('sha256', $bucket);
        }

        return $bucket;
    }

    private static function optionInt(string $name, int $default, ?AP_DB $db): int
    {
        $raw = null;
        if (class_exists('AP_Options', false)) {
            $raw = AP_Options::get($name, null, $db);
        } elseif (function_exists('ap_get_option')) {
            $raw = ap_get_option($name, null, $db);
        }
        if ($raw === null || $raw === '' || !is_numeric($raw)) {
            return $default;
        }

        return (int) $raw;
    }
}
