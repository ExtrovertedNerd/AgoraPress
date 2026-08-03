<?php

/**
 * AgoraPress forum flood control, anti-spam, and post approval.
 *
 * Options (seeded by installer):
 * - forum_flood_interval          seconds between posts (0 = off; default 30)
 * - forum_posts_require_approval  when truthy, new posts are pending unless exempt
 * - forum_spam_blacklist          comma/newline keywords → reject as spam
 * - forum_spam_max_links          link count over this → pending (0 = off; default 5)
 * - forum_search_enabled          toggle for public forum search (default on)
 *
 * Pluggable spam checkers: {@see registerSpamChecker()}. CAPTCHA and third-party
 * filters plug in via checkers or the `ap_pre_forum_post_status` filter.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * Pre-post guards: flood interval, spam checks, approval resolution.
 */
class AP_Forum_Guard
{
    public const OPTION_FLOOD_INTERVAL = 'forum_flood_interval';

    public const OPTION_REQUIRE_APPROVAL = 'forum_posts_require_approval';

    public const OPTION_SPAM_BLACKLIST = 'forum_spam_blacklist';

    public const OPTION_SPAM_MAX_LINKS = 'forum_spam_max_links';

    public const OPTION_SEARCH_ENABLED = 'forum_search_enabled';

    /** Default seconds between posts for non-exempt users. */
    public const DEFAULT_FLOOD_INTERVAL = 30;

    /** Default max http(s) links before forcing pending. 0 disables. */
    public const DEFAULT_SPAM_MAX_LINKS = 5;

    /** Status codes returned by {@see evaluate()}. */
    public const STATUS_OK = 'ok';

    public const STATUS_FLOOD = 'flood';

    public const STATUS_SPAM = 'spam';

    public const STATUS_REJECT = 'reject';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVE = 'approve';

    /**
     * Registered spam checkers: callable(array $data): bool|string|null
     *
     * Return values:
     * - true / 'spam' → reject as spam
     * - 'reject' → generic reject
     * - 'pending' / 'hold' / '0' → force unapproved
     * - 'approve' / '1' / 'ok' → force approved
     * - false / null → no opinion
     *
     * @var list<callable>
     */
    private static array $spamCheckers = [];

    /**
     * Last evaluation result (for front-end notices / tests).
     *
     * @var array<string, mixed>
     */
    private static array $lastResult = [];

    // -------------------------------------------------------------------------
    // Settings
    // -------------------------------------------------------------------------

    /**
     * Flood interval in seconds (0 = disabled).
     */
    public static function getFloodInterval(?AP_DB $db = null): int
    {
        $raw = self::optionValue(self::OPTION_FLOOD_INTERVAL, (string) self::DEFAULT_FLOOD_INTERVAL, $db);
        if (!is_numeric($raw)) {
            return self::DEFAULT_FLOOD_INTERVAL;
        }
        $n = (int) $raw;
        if ($n < 0) {
            return 0;
        }

        // Soft cap 1 hour — site can still set 0 to disable.
        return min(3600, $n);
    }

    /**
     * Whether all new posts require moderator approval by default.
     */
    public static function requiresApproval(?AP_DB $db = null): bool
    {
        $raw = strtolower(trim(self::optionValue(self::OPTION_REQUIRE_APPROVAL, '0', $db)));

        return in_array($raw, ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Whether forum search is enabled.
     */
    public static function isSearchEnabled(?AP_DB $db = null): bool
    {
        $raw = strtolower(trim(self::optionValue(self::OPTION_SEARCH_ENABLED, '1', $db)));

        return !in_array($raw, ['0', 'false', 'no', 'off', ''], true);
    }

    /**
     * Max links before content is forced pending (0 = off).
     */
    public static function getSpamMaxLinks(?AP_DB $db = null): int
    {
        $raw = self::optionValue(self::OPTION_SPAM_MAX_LINKS, (string) self::DEFAULT_SPAM_MAX_LINKS, $db);
        if (!is_numeric($raw)) {
            return self::DEFAULT_SPAM_MAX_LINKS;
        }

        return max(0, min(100, (int) $raw));
    }

    /**
     * Blacklist keywords (lowercase trimmed non-empty).
     *
     * @return list<string>
     */
    public static function getSpamBlacklist(?AP_DB $db = null): array
    {
        $raw = self::optionValue(self::OPTION_SPAM_BLACKLIST, '', $db);
        if ($raw === '') {
            return [];
        }
        $parts = preg_split('/[\s,;|]+/', $raw) ?: [];
        $out = [];
        foreach ($parts as $p) {
            $p = strtolower(trim((string) $p));
            if ($p !== '') {
                $out[] = $p;
            }
        }

        return array_values(array_unique($out));
    }

    // -------------------------------------------------------------------------
    // Flood control
    // -------------------------------------------------------------------------

    /**
     * Whether the user (or IP) is currently flood-limited.
     */
    public static function isFlooding(int $userId = 0, string $ip = '', ?AP_DB $db = null): bool
    {
        return self::secondsUntilAllowed($userId, $ip, $db) > 0;
    }

    /**
     * Seconds remaining before another post is allowed (0 = free to post).
     */
    public static function secondsUntilAllowed(int $userId = 0, string $ip = '', ?AP_DB $db = null): int
    {
        $interval = self::getFloodInterval($db);
        if ($interval < 1) {
            return 0;
        }
        if (self::isExemptFromFlood($userId, $db)) {
            return 0;
        }

        $last = self::getLastPostTime($userId, $ip, $db);
        if ($last === null || $last === '') {
            return 0;
        }

        $lastTs = strtotime($last);
        if ($lastTs === false) {
            return 0;
        }
        $elapsed = time() - $lastTs;
        if ($elapsed >= $interval) {
            return 0;
        }

        return max(1, $interval - $elapsed);
    }

    /**
     * Timestamp of the most recent forum post by user and/or IP.
     */
    public static function getLastPostTime(int $userId = 0, string $ip = '', ?AP_DB $db = null): ?string
    {
        $db = self::resolveDb($db);
        $table = $db->quoteIdentifier($db->table('forum_posts'));
        $where = [];
        $params = [];

        if ($userId > 0) {
            $where[] = $db->quoteIdentifier('poster_id') . ' = ?';
            $params[] = $userId;
        }
        $ip = trim($ip);
        if ($ip !== '') {
            $where[] = $db->quoteIdentifier('poster_ip') . ' = ?';
            $params[] = $ip;
        }
        if ($where === []) {
            return null;
        }

        // Prefer user match when both provided (OR would be too aggressive for shared IPs).
        if ($userId > 0) {
            $sql = 'SELECT ' . $db->quoteIdentifier('post_time') . ' FROM ' . $table
                . ' WHERE ' . $db->quoteIdentifier('poster_id') . ' = ?'
                . ' ORDER BY ' . $db->quoteIdentifier('post_time') . ' DESC, '
                . $db->quoteIdentifier('post_id') . ' DESC LIMIT 1';
            $params = [$userId];
        } else {
            $sql = 'SELECT ' . $db->quoteIdentifier('post_time') . ' FROM ' . $table
                . ' WHERE ' . $db->quoteIdentifier('poster_ip') . ' = ?'
                . ' ORDER BY ' . $db->quoteIdentifier('post_time') . ' DESC, '
                . $db->quoteIdentifier('post_id') . ' DESC LIMIT 1';
            $params = [$ip];
        }

        $val = $db->getVar($sql, $params);

        return $val !== null && $val !== '' ? (string) $val : null;
    }

    /**
     * Moderators / manage_forums skip flood control.
     */
    public static function isExemptFromFlood(int $userId, ?AP_DB $db = null): bool
    {
        if ($userId < 1) {
            return false;
        }
        if (function_exists('ap_user_can')) {
            if (
                ap_user_can($userId, 'manage_forums', null, $db)
                || ap_user_can($userId, 'moderate_forums', null, $db)
            ) {
                return true;
            }
        }
        if (class_exists('AP_Roles', false)) {
            try {
                if (
                    AP_Roles::userCan($userId, 'manage_forums', $db)
                    || AP_Roles::userCan($userId, 'moderate_forums', $db)
                ) {
                    return true;
                }
            } catch (Throwable) {
                // ignore
            }
        }

        return false;
    }

    // -------------------------------------------------------------------------
    // Approval
    // -------------------------------------------------------------------------

    /**
     * Whether a user's posts should start as pending (site setting + caps).
     */
    public static function userRequiresApproval(int $userId, ?AP_DB $db = null): bool
    {
        if (!self::requiresApproval($db)) {
            return false;
        }
        if (self::isExemptFromApproval($userId, $db)) {
            return false;
        }

        return true;
    }

    /**
     * Moderators / manage_forums skip the approval queue.
     */
    public static function isExemptFromApproval(int $userId, ?AP_DB $db = null): bool
    {
        return self::isExemptFromFlood($userId, $db);
    }

    // -------------------------------------------------------------------------
    // Anti-spam (pluggable)
    // -------------------------------------------------------------------------

    /**
     * Register a spam checker callback.
     *
     * @param callable(array<string, mixed>): (bool|string|null) $callback
     */
    public static function registerSpamChecker(callable $callback): void
    {
        self::$spamCheckers[] = $callback;
    }

    /**
     * Clear registered spam checkers (tests only).
     */
    public static function resetSpamCheckers(): void
    {
        self::$spamCheckers = [];
    }

    /**
     * Run spam checkers + built-ins. Returns a status code or null (no opinion).
     *
     * @param array<string, mixed> $data Keys: content, title/subject, poster_id, poster_ip, forum_id, …
     */
    public static function runSpamChecks(array $data, ?AP_DB $db = null): ?string
    {
        // Built-in blacklist (hard reject).
        $blacklistHit = self::checkBlacklist($data, $db);
        if ($blacklistHit !== null) {
            return $blacklistHit;
        }

        foreach (self::$spamCheckers as $checker) {
            $result = $checker($data);
            $normalized = self::normalizeCheckerResult($result);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        if (function_exists('ap_apply_filters')) {
            $filtered = ap_apply_filters('ap_pre_forum_post_status', null, $data);
            $normalized = self::normalizeCheckerResult($filtered);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        // Built-in max-links → pending (soft).
        $links = self::checkMaxLinks($data, $db);
        if ($links !== null) {
            return $links;
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Unified evaluation
    // -------------------------------------------------------------------------

    /**
     * Evaluate whether a topic/reply may be posted and at what approval level.
     *
     * @param array<string, mixed> $data Keys:
     *   type (topic|reply), content, title/subject, poster_id / user_id, poster_ip,
     *   forum_id, topic_id; optional explicit topic_approved / post_approved
     *
     * @return array{
     *   allowed: bool,
     *   approved: int,
     *   status: string,
     *   code: string,
     *   message: string,
     *   retry_after: int
     * }
     */
    public static function evaluate(array $data, ?AP_DB $db = null): array
    {
        $userId = max(0, (int) ($data['poster_id'] ?? $data['user_id'] ?? $data['topic_poster'] ?? 0));
        $ip = trim((string) ($data['poster_ip'] ?? ''));
        $content = trim((string) ($data['content'] ?? $data['post_content'] ?? $data['body'] ?? ''));
        $title = trim((string) ($data['title'] ?? $data['topic_title'] ?? $data['post_subject'] ?? $data['subject'] ?? ''));

        $result = [
            'allowed' => true,
            'approved' => 1,
            'status' => self::STATUS_APPROVE,
            'code' => self::STATUS_OK,
            'message' => '',
            'retry_after' => 0,
        ];

        // Explicit approval from caller wins after spam/flood unless reject.
        $explicitApproved = null;
        if (array_key_exists('topic_approved', $data)) {
            $explicitApproved = (int) $data['topic_approved'] ? 1 : 0;
        } elseif (array_key_exists('post_approved', $data)) {
            $explicitApproved = (int) $data['post_approved'] ? 1 : 0;
        }

        // Flood.
        $wait = self::secondsUntilAllowed($userId, $ip, $db);
        if ($wait > 0) {
            $result['allowed'] = false;
            $result['approved'] = 0;
            $result['status'] = self::STATUS_FLOOD;
            $result['code'] = self::STATUS_FLOOD;
            $result['retry_after'] = $wait;
            $result['message'] = sprintf(
                'Please wait %d second%s before posting again.',
                $wait,
                $wait === 1 ? '' : 's'
            );
            self::$lastResult = $result;

            return $result;
        }

        // Spam / filters.
        $checkData = array_merge($data, [
            'content' => $content,
            'title' => $title,
            'poster_id' => $userId,
            'poster_ip' => $ip,
        ]);
        $spam = self::runSpamChecks($checkData, $db);
        if ($spam === self::STATUS_SPAM || $spam === self::STATUS_REJECT) {
            $result['allowed'] = false;
            $result['approved'] = 0;
            $result['status'] = $spam;
            $result['code'] = $spam;
            $result['message'] = $spam === self::STATUS_SPAM
                ? 'Your post was rejected by the spam filter.'
                : 'Your post could not be accepted.';
            self::$lastResult = $result;

            return $result;
        }

        $approved = 1;
        if ($spam === self::STATUS_PENDING) {
            $approved = 0;
            $result['status'] = self::STATUS_PENDING;
            $result['code'] = self::STATUS_PENDING;
            $result['message'] = 'Your post is awaiting moderation.';
        } elseif (self::userRequiresApproval($userId, $db)) {
            $approved = 0;
            $result['status'] = self::STATUS_PENDING;
            $result['code'] = self::STATUS_PENDING;
            $result['message'] = 'Your post is awaiting moderation.';
        }

        if ($spam === self::STATUS_APPROVE) {
            $approved = 1;
            $result['status'] = self::STATUS_APPROVE;
            $result['code'] = self::STATUS_OK;
            $result['message'] = '';
        }

        if ($explicitApproved !== null) {
            $approved = $explicitApproved;
            if ($approved === 0 && $result['code'] === self::STATUS_OK) {
                $result['status'] = self::STATUS_PENDING;
                $result['code'] = self::STATUS_PENDING;
                $result['message'] = 'Your post is awaiting moderation.';
            } elseif ($approved === 1) {
                $result['status'] = self::STATUS_APPROVE;
                $result['code'] = self::STATUS_OK;
                $result['message'] = '';
            }
        }

        $result['approved'] = $approved;
        self::$lastResult = $result;

        return $result;
    }

    /**
     * Last {@see evaluate()} / guard result.
     *
     * @return array<string, mixed>
     */
    public static function getLastResult(): array
    {
        return self::$lastResult;
    }

    /**
     * Reset last result (tests).
     */
    public static function resetLastResult(): void
    {
        self::$lastResult = [];
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $data
     */
    private static function checkBlacklist(array $data, ?AP_DB $db): ?string
    {
        $words = self::getSpamBlacklist($db);
        if ($words === []) {
            return null;
        }
        $hay = strtolower(
            trim((string) ($data['title'] ?? '')) . "\n"
            . trim((string) ($data['content'] ?? ''))
        );
        foreach ($words as $word) {
            if ($word !== '' && str_contains($hay, $word)) {
                return self::STATUS_SPAM;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function checkMaxLinks(array $data, ?AP_DB $db): ?string
    {
        $max = self::getSpamMaxLinks($db);
        if ($max < 1) {
            return null;
        }
        $userId = max(0, (int) ($data['poster_id'] ?? 0));
        if (self::isExemptFromApproval($userId, $db)) {
            return null;
        }
        $text = (string) ($data['title'] ?? '') . "\n" . (string) ($data['content'] ?? '');
        $count = preg_match_all('#https?://#i', $text) ?: 0;
        if ($count > $max) {
            return self::STATUS_PENDING;
        }

        return null;
    }

    private static function normalizeCheckerResult(mixed $result): ?string
    {
        if ($result === true || $result === 'spam') {
            return self::STATUS_SPAM;
        }
        if ($result === false || $result === null || $result === '') {
            return null;
        }
        if (!is_string($result) && !is_int($result)) {
            return null;
        }
        $s = strtolower(trim((string) $result));

        return match ($s) {
            'spam' => self::STATUS_SPAM,
            'reject', 'block', 'deny' => self::STATUS_REJECT,
            'pending', 'hold', '0', 'moderation', 'unapproved' => self::STATUS_PENDING,
            'approve', 'approved', '1', 'ok', 'allow' => self::STATUS_APPROVE,
            default => null,
        };
    }

    private static function optionValue(string $name, string $default, ?AP_DB $db): string
    {
        if (class_exists('AP_Options', false)) {
            try {
                $val = AP_Options::get($name, $default, $db);
                if (is_bool($val)) {
                    return $val ? '1' : '0';
                }
                if (is_scalar($val)) {
                    return (string) $val;
                }
            } catch (Throwable) {
                return $default;
            }
        }

        return $default;
    }

    private static function resolveDb(?AP_DB $db): AP_DB
    {
        if ($db instanceof AP_DB) {
            return $db;
        }
        if (function_exists('ap_db')) {
            return ap_db();
        }

        throw new RuntimeException('No database connection available for forum guard.');
    }
}
