<?php

/**
 * Topic email notification storage: site options, user master, subscriptions.
 *
 * Three gates (all default off) control reply mail. This class owns the
 * **site** options, the per-user master usermeta, and `{prefix}topic_subscriptions`
 * rows (Subscribe / Unsubscribe). Unread tracking (`topic_track` / `forum_track`)
 * is a different table and is not reused here.
 *
 * | Key                            | Default | Meaning                                      |
 * |--------------------------------|---------|----------------------------------------------|
 * | `forum_topic_notify_enabled`   | `'0'`   | Site master (Settings → Forums): allow topic email notifications |
 * | `forum_notify_max_per_minute`  | `4`     | Own send cap (does not consume rate_limit_mail) |
 * | usermeta `forum_notify_email`  | `'0'`   | User master: email me about subscribed topics |
 *
 * Missing options and missing usermeta are treated as these defaults so
 * upgrades never start sending until an administrator turns the site
 * switch on **and** the member opts in.
 *
 * Subscription rows drop when the member unsubscribes, the user is deleted,
 * or the topic is hard-deleted. Soft-delete leaves watches in place.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * Site options, per-user master, and per-topic subscription rows.
 */
class AP_Forum_Notify
{
    /**
     * Site master switch. Stored as '1' / '0'. Missing or '0' = off.
     */
    public const OPTION_ENABLED = 'forum_topic_notify_enabled';

    /**
     * Own per-minute send cap. Stored as a decimal string. Default 4.
     * Does not share the verification / reset / test `rate_limit_mail` bucket.
     */
    public const OPTION_MAX_PER_MINUTE = 'forum_notify_max_per_minute';

    /**
     * Per-user master. Stored as '1' / '0'. Missing or '0' = off.
     * New users are seeded to `'0'`; upgrades with no row are treated as off.
     */
    public const META_NOTIFY_EMAIL = 'forum_notify_email';

    /** Default for {@see OPTION_ENABLED}: no chrome, enqueue, or send. */
    public const DEFAULT_ENABLED = false;

    /** Default mails per minute when the option is missing or invalid. */
    public const DEFAULT_MAX_PER_MINUTE = 4;

    /** Default for {@see META_NOTIFY_EMAIL}: no mail until the member opts in. */
    public const DEFAULT_USER_ENABLED = false;

    /** Minimum allowed per-minute cap (0 / negative fall back to default). */
    public const MIN_PER_MINUTE = 1;

    /** Soft ceiling so a crafted value cannot enqueue unbounded SMTP. */
    public const MAX_PER_MINUTE = 60;

    /**
     * Cron hook for a queued reply notify (`topic_id`, `reply_post_id`).
     * Worker delivery is a later increment; enqueue still no-ops when the
     * site master is off.
     */
    public const CRON_HOOK = 'ap_forum_topic_notify';

    /**
     * Whether the site allows topic email notifications.
     *
     * Default is **off** when the option is missing.
     */
    public static function isEnabled(?AP_DB $db = null): bool
    {
        $raw = strtolower(trim(self::optionValue(
            self::OPTION_ENABLED,
            self::DEFAULT_ENABLED ? '1' : '0',
            $db
        )));

        return !in_array($raw, ['0', 'false', 'no', 'off', ''], true);
    }

    /**
     * Whether Subscribe / notify chrome may render.
     *
     * Site master off → no chrome. Later increments also require a logged-in
     * viewer with `view_forum`; this method is the site gate only.
     */
    public static function shouldShowChrome(?AP_DB $db = null): bool
    {
        return self::isEnabled($db);
    }

    /**
     * Queue notify work for an approved reply. Site master off → no enqueue
     * (does not schedule {@see AP_Cron}).
     *
     * Does not send mail in this request. A duplicate `(topic, reply)` pair
     * that is already scheduled is treated as success.
     */
    public static function enqueueReply(int $topicId, int $replyPostId, ?AP_DB $db = null): bool
    {
        if ($topicId < 1 || $replyPostId < 1) {
            return false;
        }
        if (!self::isEnabled($db)) {
            return false;
        }

        $args = [$topicId, $replyPostId];
        if (class_exists('AP_Cron', false)) {
            if (AP_Cron::nextScheduled(self::CRON_HOOK, $args, $db) !== false) {
                return true;
            }

            return AP_Cron::scheduleSingle(time(), self::CRON_HOOK, $args, $db);
        }
        if (function_exists('ap_schedule_single_event')) {
            return ap_schedule_single_event(time(), self::CRON_HOOK, $args, $db);
        }

        return false;
    }

    /**
     * Outbound notify choke point. Site master off → no send (does not call
     * {@see AP_Mail::send()} and does not consume `rate_limit_mail`).
     *
     * Delivery (digest, unsubscribe token, own per-minute cap) is the mail
     * worker increment. Do not call {@see AP_Mail::send()} from here until
     * that worker owns the cap — {@see AP_Mail::send()} consumes the
     * verification / reset / test bucket.
     *
     * @param string|list<string>   $to
     * @param array<string, string> $headers
     */
    public static function send(
        string|array $to,
        string $subject,
        string $message,
        array $headers = [],
        ?AP_DB $db = null
    ): bool {
        if (!self::isEnabled($db)) {
            return false;
        }

        $recipients = is_array($to) ? $to : [$to];
        $hasRecipient = false;
        foreach ($recipients as $addr) {
            if (is_string($addr) && trim($addr) !== '') {
                $hasRecipient = true;
                break;
            }
        }
        if (!$hasRecipient || trim($subject) === '' || $message === '') {
            return false;
        }

        // Signature reserved for the worker. $headers is unused until then.
        unset($headers);

        return false;
    }

    /**
     * Per-minute send cap (clamped). Default 4.
     */
    public static function getMaxPerMinute(?AP_DB $db = null): int
    {
        $raw = self::optionValue(
            self::OPTION_MAX_PER_MINUTE,
            (string) self::DEFAULT_MAX_PER_MINUTE,
            $db
        );

        return self::sanitizeMaxPerMinute($raw);
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
     * Normalize a per-minute cap. Invalid / out of range → default 4.
     */
    public static function sanitizeMaxPerMinute(mixed $value): int
    {
        if (is_bool($value)) {
            return self::DEFAULT_MAX_PER_MINUTE;
        }
        if (is_string($value)) {
            $value = trim($value);
            if ($value === '' || !is_numeric($value)) {
                return self::DEFAULT_MAX_PER_MINUTE;
            }
        }
        if (!is_numeric($value)) {
            return self::DEFAULT_MAX_PER_MINUTE;
        }

        $n = (int) $value;
        if ($n < self::MIN_PER_MINUTE) {
            return self::DEFAULT_MAX_PER_MINUTE;
        }

        return min(self::MAX_PER_MINUTE, $n);
    }

    /**
     * Whether the user wants email about topics they subscribe to.
     *
     * Default is **off** when the usermeta row is missing or empty.
     * Guests (user id below 1) are always off.
     */
    public static function isUserNotifyEnabled(int $userId, ?AP_DB $db = null): bool
    {
        return self::userNotifyStoredValue($userId, $db) === '1';
    }

    /**
     * Stored `'1'` / `'0'` for the user master. Missing meta → `'0'`.
     */
    public static function userNotifyStoredValue(int $userId, ?AP_DB $db = null): string
    {
        if ($userId < 1) {
            return self::DEFAULT_USER_ENABLED ? '1' : '0';
        }

        $raw = self::userMetaValue($userId, $db);
        if ($raw === null || $raw === '') {
            return self::DEFAULT_USER_ENABLED ? '1' : '0';
        }

        return self::sanitizeEnabled($raw);
    }

    /**
     * Persist the user master switch as `'1'` or `'0'`.
     */
    public static function setUserNotifyEnabled(int $userId, mixed $value, ?AP_DB $db = null): bool
    {
        if ($userId < 1) {
            return false;
        }

        return self::writeUserMeta($userId, self::sanitizeEnabled($value), $db);
    }

    /**
     * Insert `'0'` when the key is missing. Does not overwrite a stored value.
     */
    public static function seedUserDefault(int $userId, ?AP_DB $db = null): bool
    {
        if ($userId < 1) {
            return false;
        }

        $existing = self::userMetaValue($userId, $db);
        if ($existing !== null && $existing !== '') {
            return true;
        }

        return self::writeUserMeta(
            $userId,
            self::DEFAULT_USER_ENABLED ? '1' : '0',
            $db
        );
    }

    /**
     * Persist notify site options. Omitted keys are left unchanged.
     *
     * @param array{
     *   forum_topic_notify_enabled?: mixed,
     *   forum_notify_max_per_minute?: mixed,
     *   enabled?: mixed,
     *   max_per_minute?: mixed
     * } $settings
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

        $capKey = array_key_exists(self::OPTION_MAX_PER_MINUTE, $settings)
            ? self::OPTION_MAX_PER_MINUTE
            : (array_key_exists('max_per_minute', $settings) ? 'max_per_minute' : null);
        if ($capKey !== null) {
            $ok = AP_Options::update(
                self::OPTION_MAX_PER_MINUTE,
                (string) self::sanitizeMaxPerMinute($settings[$capKey]),
                $db
            ) && $ok;
        }

        return $ok;
    }

    /**
     * Insert the two options when missing. Does not overwrite stored values.
     */
    public static function seedDefaults(AP_DB $db): void
    {
        foreach (self::defaultOptionMap() as $name => $value) {
            if (class_exists('AP_Options', false)) {
                AP_Options::add($name, $value, $db, 'yes');
                continue;
            }

            $table = $db->quoteIdentifier($db->table('options'));
            $existing = $db->getVar(
                'SELECT option_id FROM ' . $table . ' WHERE option_name = ? LIMIT 1',
                [$name]
            );
            if ($existing !== null && $existing !== '') {
                continue;
            }

            $ok = $db->insert('options', [
                'option_name' => $name,
                'option_value' => $value,
                'autoload' => 'yes',
            ]);
            if ($ok === false) {
                $again = $db->getVar(
                    'SELECT option_id FROM ' . $table . ' WHERE option_name = ? LIMIT 1',
                    [$name]
                );
                if ($again !== null && $again !== '') {
                    continue;
                }
                throw new RuntimeException(
                    'Failed to seed forum notify option ' . $name . ': '
                    . ($db->lastError() ?? 'unknown error')
                );
            }
        }
    }

    /**
     * Default option map for installer / migration (name => stored string).
     *
     * @return array<string, string>
     */
    public static function defaultOptionMap(): array
    {
        return [
            self::OPTION_ENABLED => self::DEFAULT_ENABLED ? '1' : '0',
            self::OPTION_MAX_PER_MINUTE => (string) self::DEFAULT_MAX_PER_MINUTE,
        ];
    }

    // -------------------------------------------------------------------------
    // Per-topic subscriptions ({prefix}topic_subscriptions)
    // -------------------------------------------------------------------------

    /**
     * Whether the user is subscribed to the topic.
     *
     * Guests (user id below 1) are never subscribed.
     */
    public static function isSubscribed(int $userId, int $topicId, ?AP_DB $db = null): bool
    {
        if ($userId < 1 || $topicId < 1) {
            return false;
        }

        $db = self::resolveDb($db);

        try {
            $table = $db->quoteIdentifier($db->table('topic_subscriptions'));
            $n = (int) $db->getVar(
                'SELECT COUNT(*) FROM ' . $table
                . ' WHERE ' . $db->quoteIdentifier('user_id') . ' = ?'
                . ' AND ' . $db->quoteIdentifier('topic_id') . ' = ?',
                [$userId, $topicId]
            );
        } catch (Throwable) {
            return false;
        }

        return $n > 0;
    }

    /**
     * Watch a topic (idempotent). Unique `(user_id, topic_id)`.
     *
     * Does not flip the user master, does not auto-watch on reply, and does
     * not write unread `topic_track` rows. Guests and missing user/topic ids
     * are rejected.
     */
    public static function subscribe(int $userId, int $topicId, ?AP_DB $db = null): bool
    {
        if ($userId < 1 || $topicId < 1) {
            return false;
        }

        $db = self::resolveDb($db);
        if (self::isSubscribed($userId, $topicId, $db)) {
            return true;
        }
        if (!self::userExists($userId, $db) || !self::topicExists($topicId, $db)) {
            return false;
        }

        $ok = $db->insert('topic_subscriptions', [
            'user_id' => $userId,
            'topic_id' => $topicId,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        if ($ok !== false) {
            return true;
        }

        // Unique race: another request inserted the same pair.
        return self::isSubscribed($userId, $topicId, $db);
    }

    /**
     * Drop one `(user, topic)` watch (idempotent). Missing rows succeed.
     */
    public static function unsubscribe(int $userId, int $topicId, ?AP_DB $db = null): bool
    {
        if ($userId < 1 || $topicId < 1) {
            return false;
        }

        $db = self::resolveDb($db);

        try {
            $ok = $db->delete('topic_subscriptions', [
                'user_id' => $userId,
                'topic_id' => $topicId,
            ]);
        } catch (Throwable) {
            return false;
        }

        return $ok !== false;
    }

    /**
     * Subscription rows for a user, oldest first.
     *
     * @return list<object>
     */
    public static function listForUser(int $userId, ?AP_DB $db = null): array
    {
        if ($userId < 1) {
            return [];
        }

        $db = self::resolveDb($db);

        try {
            $table = $db->quoteIdentifier($db->table('topic_subscriptions'));
            $rows = $db->getResults(
                'SELECT * FROM ' . $table
                . ' WHERE ' . $db->quoteIdentifier('user_id') . ' = ?'
                . ' ORDER BY ' . $db->quoteIdentifier('created_at') . ' ASC, '
                . $db->quoteIdentifier('topic_id') . ' ASC',
                [$userId]
            );
        } catch (Throwable) {
            return [];
        }

        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (is_object($row)) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * Subscriptions for a user with topic titles (profile / account list).
     *
     * Oldest first. Missing topics keep a fallback title so the member can
     * still unsubscribe an orphaned row.
     *
     * @return list<array{
     *   user_id: int,
     *   topic_id: int,
     *   created_at: string,
     *   topic_title: string,
     *   topic_url: string
     * }>
     */
    public static function listForUserWithTitles(int $userId, ?AP_DB $db = null): array
    {
        $rows = self::listForUser($userId, $db);
        if ($rows === []) {
            return [];
        }

        $ids = [];
        foreach ($rows as $row) {
            $tid = (int) ($row->topic_id ?? 0);
            if ($tid > 0) {
                $ids[] = $tid;
            }
        }

        $topics = [];
        if ($ids !== [] && class_exists('AP_Forum', false)) {
            $topics = AP_Forum::getTopicsByIds($ids, $db);
        }

        $out = [];
        foreach ($rows as $row) {
            $topicId = (int) ($row->topic_id ?? 0);
            if ($topicId < 1) {
                continue;
            }
            $topic = $topics[$topicId] ?? null;
            $title = '';
            if (is_object($topic)) {
                $title = trim((string) ($topic->topic_title ?? ''));
            }
            if ($title === '') {
                $title = 'Topic #' . $topicId;
            }
            $url = '';
            if (class_exists('AP_Forum', false)) {
                $url = AP_Forum::topicUrl(is_object($topic) ? $topic : $topicId);
            }
            $out[] = [
                'user_id' => (int) ($row->user_id ?? $userId),
                'topic_id' => $topicId,
                'created_at' => (string) ($row->created_at ?? ''),
                'topic_title' => $title,
                'topic_url' => $url,
            ];
        }

        return $out;
    }

    /**
     * Drop every watch for a user (account delete). Returns rows removed.
     */
    public static function deleteForUser(int $userId, ?AP_DB $db = null): int
    {
        if ($userId < 1) {
            return 0;
        }

        return self::deleteWhere(['user_id' => $userId], $db);
    }

    /**
     * Drop every watch for a topic (hard-delete). Returns rows removed.
     */
    public static function deleteForTopic(int $topicId, ?AP_DB $db = null): int
    {
        if ($topicId < 1) {
            return 0;
        }

        return self::deleteWhere(['topic_id' => $topicId], $db);
    }

    /**
     * @param array<string, int> $where
     */
    private static function deleteWhere(array $where, ?AP_DB $db): int
    {
        $db = self::resolveDb($db);

        try {
            $ok = $db->delete('topic_subscriptions', $where);
        } catch (Throwable) {
            return 0;
        }

        return $ok === false ? 0 : max(0, $ok);
    }

    private static function userExists(int $userId, AP_DB $db): bool
    {
        if (class_exists('AP_User', false)) {
            return AP_User::getById($userId, $db) !== null;
        }

        try {
            $raw = $db->getVar(
                'SELECT ID FROM ' . $db->quoteIdentifier($db->table('users'))
                . ' WHERE ID = ? LIMIT 1',
                [$userId]
            );
        } catch (Throwable) {
            return false;
        }

        return $raw !== null && $raw !== '' && (int) $raw === $userId;
    }

    private static function topicExists(int $topicId, AP_DB $db): bool
    {
        if (class_exists('AP_Forum', false)) {
            return AP_Forum::getTopic($topicId, $db) !== null;
        }

        try {
            $raw = $db->getVar(
                'SELECT topic_id FROM ' . $db->quoteIdentifier($db->table('topics'))
                . ' WHERE topic_id = ? LIMIT 1',
                [$topicId]
            );
        } catch (Throwable) {
            return false;
        }

        return $raw !== null && $raw !== '' && (int) $raw === $topicId;
    }

    private static function resolveDb(?AP_DB $db): AP_DB
    {
        if ($db instanceof AP_DB) {
            return $db;
        }
        if (function_exists('ap_db')) {
            return ap_db();
        }

        throw new RuntimeException('Database not available for topic notify.');
    }

    private static function optionValue(string $name, string $default, ?AP_DB $db): string
    {
        if (class_exists('AP_Options', false)) {
            $val = AP_Options::get($name, $default, $db);
            if (is_bool($val)) {
                return $val ? '1' : '0';
            }
            if (is_scalar($val)) {
                return (string) $val;
            }
        }
        if (function_exists('ap_get_option')) {
            $val = ap_get_option($name, $default, $db);
            if (is_bool($val)) {
                return $val ? '1' : '0';
            }
            if (is_scalar($val)) {
                return (string) $val;
            }
        }

        return $default;
    }

    private static function userMetaValue(int $userId, ?AP_DB $db): ?string
    {
        if (class_exists('AP_User', false)) {
            return AP_User::getMeta($userId, self::META_NOTIFY_EMAIL, $db);
        }
        if ($db === null) {
            return null;
        }

        try {
            $raw = $db->getVar(
                'SELECT meta_value FROM ' . $db->quoteIdentifier($db->table('usermeta'))
                . ' WHERE user_id = ? AND meta_key = ? LIMIT 1',
                [$userId, self::META_NOTIFY_EMAIL]
            );
        } catch (Throwable) {
            return null;
        }

        if ($raw === null) {
            return null;
        }

        return (string) $raw;
    }

    private static function writeUserMeta(int $userId, string $value, ?AP_DB $db): bool
    {
        if (class_exists('AP_User', false)) {
            return AP_User::updateMeta($userId, self::META_NOTIFY_EMAIL, $value, $db);
        }
        if ($db === null) {
            return false;
        }

        try {
            $table = $db->quoteIdentifier($db->table('usermeta'));
            $existing = $db->getVar(
                'SELECT umeta_id FROM ' . $table
                . ' WHERE user_id = ? AND meta_key = ? LIMIT 1',
                [$userId, self::META_NOTIFY_EMAIL]
            );
            if ($existing !== null && $existing !== '') {
                return $db->update(
                    'usermeta',
                    ['meta_value' => $value],
                    [
                        'user_id' => $userId,
                        'meta_key' => self::META_NOTIFY_EMAIL,
                    ]
                ) !== false;
            }

            return $db->insert('usermeta', [
                'user_id' => $userId,
                'meta_key' => self::META_NOTIFY_EMAIL,
                'meta_value' => $value,
            ]) !== false;
        } catch (Throwable) {
            return false;
        }
    }
}
