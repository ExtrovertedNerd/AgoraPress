<?php

/**
 * AgoraPress forum unread tracking.
 *
 * Uses:
 * - `{prefix}topic_track`  — per-user last-read time for a topic (migration 0009)
 * - `{prefix}forum_track`  — per-user mark-as-read for a whole forum
 * - usermeta `forum_last_mark` — global “mark all as read” watermark
 *
 * A topic is unread when topic_last_post_time is strictly after the user’s
 * effective mark time:
 *   max(topic_track.mark_time, forum_track.mark_time, forum_last_mark, epoch)
 *
 * Guests do not persist unread state (always “read” for API purposes).
 *
 * Option: forum_unread_tracking_enabled (default 1).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * Forum topic / forum unread tracking API.
 */
class AP_Forum_Read
{
    public const OPTION_ENABLED = 'forum_unread_tracking_enabled';

    /** Usermeta key for global mark-all-read watermark. */
    public const META_LAST_MARK = 'forum_last_mark';

    public const EMPTY_DATETIME = '1970-01-01 00:00:00';

    // -------------------------------------------------------------------------
    // Settings
    // -------------------------------------------------------------------------

    /**
     * Whether unread tracking is enabled site-wide.
     */
    public static function isEnabled(?AP_DB $db = null): bool
    {
        $raw = self::optionValue(self::OPTION_ENABLED, '1', $db);
        $raw = strtolower(trim($raw));

        return !in_array($raw, ['0', 'false', 'no', 'off', ''], true);
    }

    /**
     * Whether the forum module is enabled.
     */
    public static function isForumModuleEnabled(?AP_DB $db = null): bool
    {
        if (function_exists('ap_is_module_enabled')) {
            return ap_is_module_enabled('forum', $db);
        }
        if (class_exists('AP_Options', false) && method_exists('AP_Options', 'isModuleEnabled')) {
            return AP_Options::isModuleEnabled('forum', $db);
        }

        return true;
    }

    /**
     * Whether unread tracking is available (toggle + forum module).
     */
    public static function isAvailable(?AP_DB $db = null): bool
    {
        return self::isEnabled($db) && self::isForumModuleEnabled($db);
    }

    // -------------------------------------------------------------------------
    // Mark as read
    // -------------------------------------------------------------------------

    /**
     * Mark a topic as read up to now (or an explicit time).
     *
     * @param array<string, mixed> $args mark_time (mysql datetime), check_enabled
     */
    public static function markTopicRead(
        int $userId,
        int $topicId,
        ?AP_DB $db = null,
        array $args = []
    ): bool {
        if ($userId < 1 || $topicId < 1) {
            return false;
        }
        $db = self::resolveDb($db);
        $checkEnabled = !array_key_exists('check_enabled', $args) || !empty($args['check_enabled']);
        if ($checkEnabled && !self::isAvailable($db)) {
            return false;
        }

        $topic = self::getTopicRow($topicId, $db);
        if ($topic === null) {
            return false;
        }

        $forumId = (int) ($topic->forum_id ?? 0);
        $markTime = self::normalizeMarkTime((string) ($args['mark_time'] ?? ''));

        return self::upsertTopicTrack($userId, $topicId, $forumId, $markTime, $db);
    }

    /**
     * Mark an entire forum as read (sets forum_track watermark).
     *
     * @param array<string, mixed> $args mark_time, check_enabled, clear_topics (bool, default true)
     *                                   — remove per-topic tracks for this forum after marking
     */
    public static function markForumRead(
        int $userId,
        int $forumId,
        ?AP_DB $db = null,
        array $args = []
    ): bool {
        if ($userId < 1 || $forumId < 1) {
            return false;
        }
        $db = self::resolveDb($db);
        $checkEnabled = !array_key_exists('check_enabled', $args) || !empty($args['check_enabled']);
        if ($checkEnabled && !self::isAvailable($db)) {
            return false;
        }

        if (class_exists('AP_Forum', false)) {
            if (AP_Forum::getForum($forumId, $db) === null) {
                return false;
            }
        }

        $markTime = self::normalizeMarkTime((string) ($args['mark_time'] ?? ''));
        if (!self::upsertForumTrack($userId, $forumId, $markTime, $db)) {
            return false;
        }

        $clearTopics = !array_key_exists('clear_topics', $args) || !empty($args['clear_topics']);
        if ($clearTopics) {
            self::clearTopicTracksForForum($userId, $forumId, $db);
        }

        return true;
    }

    /**
     * Mark all forums/topics as read for a user (global watermark).
     *
     * @param array<string, mixed> $args mark_time, check_enabled, clear_tracks (bool, default true)
     */
    public static function markAllRead(int $userId, ?AP_DB $db = null, array $args = []): bool
    {
        if ($userId < 1) {
            return false;
        }
        $db = self::resolveDb($db);
        $checkEnabled = !array_key_exists('check_enabled', $args) || !empty($args['check_enabled']);
        if ($checkEnabled && !self::isAvailable($db)) {
            return false;
        }

        $markTime = self::normalizeMarkTime((string) ($args['mark_time'] ?? ''));
        if (!self::setGlobalLastMark($userId, $markTime, $db)) {
            return false;
        }

        $clear = !array_key_exists('clear_tracks', $args) || !empty($args['clear_tracks']);
        if ($clear) {
            $db->delete('topic_track', ['user_id' => $userId]);
            $db->delete('forum_track', ['user_id' => $userId]);
        }

        return true;
    }

    // -------------------------------------------------------------------------
    // Read state queries
    // -------------------------------------------------------------------------

    /**
     * Global last-mark watermark for a user (or EMPTY_DATETIME).
     */
    public static function getGlobalLastMark(int $userId, ?AP_DB $db = null): string
    {
        if ($userId < 1) {
            return self::EMPTY_DATETIME;
        }
        $db = self::resolveDb($db);
        $raw = null;
        if (class_exists('AP_User', false)) {
            $raw = AP_User::getMeta($userId, self::META_LAST_MARK, $db);
        }
        if (($raw === null || $raw === '') && function_exists('ap_get_user_meta')) {
            $raw = ap_get_user_meta($userId, self::META_LAST_MARK, $db);
        }
        if ($raw === null || $raw === '') {
            return self::EMPTY_DATETIME;
        }
        $normalized = self::normalizeMarkTime((string) $raw, false);
        if ($normalized === '') {
            return self::EMPTY_DATETIME;
        }

        return $normalized;
    }

    /**
     * Per-topic track mark_time, or null if none.
     */
    public static function getTopicMarkTime(int $userId, int $topicId, ?AP_DB $db = null): ?string
    {
        if ($userId < 1 || $topicId < 1) {
            return null;
        }
        $db = self::resolveDb($db);
        $table = $db->quoteIdentifier($db->table('topic_track'));
        $raw = $db->getVar(
            'SELECT mark_time FROM ' . $table
            . ' WHERE ' . $db->quoteIdentifier('user_id') . ' = ?'
            . ' AND ' . $db->quoteIdentifier('topic_id') . ' = ?'
            . ' LIMIT 1',
            [$userId, $topicId]
        );
        if ($raw === null || $raw === '') {
            return null;
        }

        return self::normalizeMarkTime((string) $raw, false) ?: null;
    }

    /**
     * Per-forum track mark_time, or null if none.
     */
    public static function getForumMarkTime(int $userId, int $forumId, ?AP_DB $db = null): ?string
    {
        if ($userId < 1 || $forumId < 1) {
            return null;
        }
        $db = self::resolveDb($db);
        $table = $db->quoteIdentifier($db->table('forum_track'));
        $raw = $db->getVar(
            'SELECT mark_time FROM ' . $table
            . ' WHERE ' . $db->quoteIdentifier('user_id') . ' = ?'
            . ' AND ' . $db->quoteIdentifier('forum_id') . ' = ?'
            . ' LIMIT 1',
            [$userId, $forumId]
        );
        if ($raw === null || $raw === '') {
            return null;
        }

        return self::normalizeMarkTime((string) $raw, false) ?: null;
    }

    /**
     * Effective mark time for a topic (max of topic, forum, and global marks).
     */
    public static function getEffectiveTopicMark(
        int $userId,
        int $topicId,
        int $forumId = 0,
        ?AP_DB $db = null
    ): string {
        if ($userId < 1) {
            return self::EMPTY_DATETIME;
        }
        $db = self::resolveDb($db);

        if ($forumId < 1) {
            $topic = self::getTopicRow($topicId, $db);
            $forumId = $topic !== null ? (int) ($topic->forum_id ?? 0) : 0;
        }

        $candidates = [self::getGlobalLastMark($userId, $db)];
        $topicMark = self::getTopicMarkTime($userId, $topicId, $db);
        if ($topicMark !== null) {
            $candidates[] = $topicMark;
        }
        if ($forumId > 0) {
            $forumMark = self::getForumMarkTime($userId, $forumId, $db);
            if ($forumMark !== null) {
                $candidates[] = $forumMark;
            }
        }

        return self::maxDatetime($candidates);
    }

    /**
     * Whether a topic has newer posts than the user’s effective mark.
     *
     * Guests and disabled tracking always return false (not unread).
     *
     * @param object|int $topic Topic row or topic_id
     */
    public static function isTopicUnread(int $userId, object|int $topic, ?AP_DB $db = null): bool
    {
        if ($userId < 1) {
            return false;
        }
        $db = self::resolveDb($db);
        if (!self::isAvailable($db)) {
            return false;
        }

        $row = is_object($topic) ? $topic : self::getTopicRow((int) $topic, $db);
        if ($row === null) {
            return false;
        }

        $topicId = (int) ($row->topic_id ?? 0);
        $forumId = (int) ($row->forum_id ?? 0);
        $lastPost = (string) ($row->topic_last_post_time ?? self::EMPTY_DATETIME);
        if ($lastPost === '' || $lastPost === self::EMPTY_DATETIME) {
            return false;
        }

        // Soft-deleted topics are not “unread”.
        $status = (string) ($row->topic_status ?? '');
        if ($status === 'deleted') {
            return false;
        }

        $mark = self::getEffectiveTopicMark($userId, $topicId, $forumId, $db);

        return self::compareDatetime($lastPost, $mark) > 0;
    }

    /**
     * Whether a forum has any unread topics for the user.
     *
     * Uses denormalized last_post_time when available, falling back to a topic scan.
     */
    public static function isForumUnread(int $userId, int $forumId, ?AP_DB $db = null): bool
    {
        if ($userId < 1 || $forumId < 1) {
            return false;
        }
        $db = self::resolveDb($db);
        if (!self::isAvailable($db)) {
            return false;
        }

        $forum = null;
        if (class_exists('AP_Forum', false)) {
            $forum = AP_Forum::getForum($forumId, $db);
        }
        if ($forum === null) {
            $ft = $db->quoteIdentifier($db->table('forums'));
            $forum = $db->getRow(
                'SELECT * FROM ' . $ft . ' WHERE ' . $db->quoteIdentifier('forum_id') . ' = ?',
                [$forumId]
            );
        }
        if ($forum === null) {
            return false;
        }

        $lastPost = (string) ($forum->last_post_time ?? self::EMPTY_DATETIME);
        if ($lastPost === '' || $lastPost === self::EMPTY_DATETIME) {
            return false;
        }

        $global = self::getGlobalLastMark($userId, $db);
        $forumMark = self::getForumMarkTime($userId, $forumId, $db);
        $base = self::maxDatetime(array_filter([$global, $forumMark ?? self::EMPTY_DATETIME]));

        // Fast path: forum last activity not after forum/global mark AND no
        // topic tracks needed only when base covers last_post — still must
        // check topics that may have been read partially after newer posts.
        if (self::compareDatetime($lastPost, $base) <= 0) {
            // Forum-level mark covers last post; only unread if a topic was
            // tracked earlier and got a newer reply after its track but the
            // forum last_post is still <= base — impossible if base is max of
            // forum mark. Safe to say read.
            return false;
        }

        // There is activity after the forum/global mark — verify at least one
        // non-deleted approved topic is effectively unread.
        $topics = self::getForumTopicCandidates($forumId, $db);
        foreach ($topics as $topic) {
            if (self::isTopicUnread($userId, $topic, $db)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Count unread topics in a forum for a user.
     *
     * @param array<string, mixed> $args limit (cap scan, default 500)
     */
    public static function countUnreadTopicsInForum(
        int $userId,
        int $forumId,
        ?AP_DB $db = null,
        array $args = []
    ): int {
        if ($userId < 1 || $forumId < 1) {
            return 0;
        }
        $db = self::resolveDb($db);
        if (!self::isAvailable($db)) {
            return 0;
        }

        $limit = isset($args['limit']) ? max(1, min(2000, (int) $args['limit'])) : 500;
        $topics = self::getForumTopicCandidates($forumId, $db, $limit);
        $count = 0;
        foreach ($topics as $topic) {
            if (self::isTopicUnread($userId, $topic, $db)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * List unread topics for a user (optionally scoped to a forum).
     *
     * @param array<string, mixed> $args forum_id, limit (default 50), offset
     *
     * @return list<object> Topic rows (raw/normalized fields from topics table)
     */
    public static function getUnreadTopics(int $userId, array $args = [], ?AP_DB $db = null): array
    {
        if ($userId < 1) {
            return [];
        }
        $db = self::resolveDb($db);
        if (!self::isAvailable($db)) {
            return [];
        }

        $forumId = isset($args['forum_id']) ? max(0, (int) $args['forum_id']) : 0;
        $limit = isset($args['limit']) ? max(1, min(200, (int) $args['limit'])) : 50;
        $offset = isset($args['offset']) ? max(0, (int) $args['offset']) : 0;
        // Scan a wider window then filter — accurate enough for MVP lists.
        $scanLimit = min(2000, max($limit + $offset, $limit * 10));

        $topicsTable = $db->quoteIdentifier($db->table('topics'));
        $sql = 'SELECT * FROM ' . $topicsTable
            . ' WHERE ' . $db->quoteIdentifier('topic_status') . ' != ?'
            . ' AND ' . $db->quoteIdentifier('topic_approved') . ' = 1';
        $params = ['deleted'];

        if ($forumId > 0) {
            $sql .= ' AND ' . $db->quoteIdentifier('forum_id') . ' = ?';
            $params[] = $forumId;
        }

        $sql .= ' ORDER BY ' . $db->quoteIdentifier('topic_last_post_time') . ' DESC'
            . ' LIMIT ' . $scanLimit;

        $rows = $db->getResults($sql, $params);
        $unread = [];
        foreach ($rows as $row) {
            if (self::isTopicUnread($userId, $row, $db)) {
                $unread[] = $row;
            }
        }

        if ($offset > 0) {
            $unread = array_slice($unread, $offset);
        }

        return array_slice($unread, 0, $limit);
    }

    /**
     * Annotate topic rows with is_unread for a user.
     *
     * @param list<object|array<string, mixed>> $topics
     *
     * @return list<array<string, mixed>>
     */
    public static function annotateTopics(int $userId, array $topics, ?AP_DB $db = null): array
    {
        $db = self::resolveDb($db);
        $out = [];
        foreach ($topics as $topic) {
            if (is_array($topic)) {
                $obj = (object) $topic;
                $row = $topic;
            } else {
                $obj = $topic;
                $row = (array) $topic;
            }
            $row['is_unread'] = $userId > 0 && self::isTopicUnread($userId, $obj, $db);
            $out[] = $row;
        }

        return $out;
    }

    /**
     * Theme-friendly unread summary for a user.
     *
     * @param array<string, mixed> $args forum_id, limit
     *
     * @return array{
     *   enabled: bool,
     *   user_id: int,
     *   global_last_mark: string,
     *   unread_topics: list<object>,
     *   unread_count: int
     * }
     */
    public static function getUnreadSummary(int $userId, array $args = [], ?AP_DB $db = null): array
    {
        $db = self::resolveDb($db);
        $enabled = self::isAvailable($db) && $userId > 0;
        if (!$enabled) {
            return [
                'enabled' => false,
                'user_id' => $userId,
                'global_last_mark' => self::EMPTY_DATETIME,
                'unread_topics' => [],
                'unread_count' => 0,
            ];
        }

        $topics = self::getUnreadTopics($userId, $args, $db);

        return [
            'enabled' => true,
            'user_id' => $userId,
            'global_last_mark' => self::getGlobalLastMark($userId, $db),
            'unread_topics' => $topics,
            'unread_count' => count($topics),
        ];
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    private static function upsertTopicTrack(
        int $userId,
        int $topicId,
        int $forumId,
        string $markTime,
        AP_DB $db
    ): bool {
        $existing = self::getTopicMarkTime($userId, $topicId, $db);
        if ($existing !== null) {
            // Only move the mark forward.
            if (self::compareDatetime($markTime, $existing) < 0) {
                $markTime = $existing;
            }
            $ok = $db->update(
                'topic_track',
                [
                    'forum_id' => $forumId,
                    'mark_time' => $markTime,
                ],
                [
                    'user_id' => $userId,
                    'topic_id' => $topicId,
                ]
            );

            return $ok !== false;
        }

        $result = $db->insert('topic_track', [
            'user_id' => $userId,
            'topic_id' => $topicId,
            'forum_id' => $forumId,
            'mark_time' => $markTime,
        ]);

        if ($result === false) {
            // Race: update instead.
            $ok = $db->update(
                'topic_track',
                [
                    'forum_id' => $forumId,
                    'mark_time' => $markTime,
                ],
                [
                    'user_id' => $userId,
                    'topic_id' => $topicId,
                ]
            );

            return $ok !== false;
        }

        return true;
    }

    private static function upsertForumTrack(
        int $userId,
        int $forumId,
        string $markTime,
        AP_DB $db
    ): bool {
        $existing = self::getForumMarkTime($userId, $forumId, $db);
        if ($existing !== null) {
            if (self::compareDatetime($markTime, $existing) < 0) {
                $markTime = $existing;
            }
            $ok = $db->update(
                'forum_track',
                ['mark_time' => $markTime],
                [
                    'user_id' => $userId,
                    'forum_id' => $forumId,
                ]
            );

            return $ok !== false;
        }

        $result = $db->insert('forum_track', [
            'user_id' => $userId,
            'forum_id' => $forumId,
            'mark_time' => $markTime,
        ]);

        if ($result === false) {
            $ok = $db->update(
                'forum_track',
                ['mark_time' => $markTime],
                [
                    'user_id' => $userId,
                    'forum_id' => $forumId,
                ]
            );

            return $ok !== false;
        }

        return true;
    }

    private static function clearTopicTracksForForum(int $userId, int $forumId, AP_DB $db): void
    {
        $db->delete('topic_track', [
            'user_id' => $userId,
            'forum_id' => $forumId,
        ]);
    }

    private static function setGlobalLastMark(int $userId, string $markTime, AP_DB $db): bool
    {
        if (class_exists('AP_User', false)) {
            return AP_User::updateMeta($userId, self::META_LAST_MARK, $markTime, $db);
        }
        if (function_exists('ap_update_user_meta')) {
            return (bool) ap_update_user_meta($userId, self::META_LAST_MARK, $markTime, $db);
        }

        // Fallback: write usermeta directly.
        $existing = $db->getVar(
            'SELECT umeta_id FROM ' . $db->quoteIdentifier($db->table('usermeta'))
            . ' WHERE user_id = ? AND meta_key = ? LIMIT 1',
            [$userId, self::META_LAST_MARK]
        );
        if ($existing !== null && $existing !== false && (string) $existing !== '') {
            return $db->update(
                'usermeta',
                ['meta_value' => $markTime],
                [
                    'user_id' => $userId,
                    'meta_key' => self::META_LAST_MARK,
                ]
            ) !== false;
        }

        return $db->insert('usermeta', [
            'user_id' => $userId,
            'meta_key' => self::META_LAST_MARK,
            'meta_value' => $markTime,
        ]) !== false;
    }

    /**
     * @return list<object>
     */
    private static function getForumTopicCandidates(int $forumId, AP_DB $db, int $limit = 500): array
    {
        $topicsTable = $db->quoteIdentifier($db->table('topics'));
        $sql = 'SELECT * FROM ' . $topicsTable
            . ' WHERE ' . $db->quoteIdentifier('forum_id') . ' = ?'
            . ' AND ' . $db->quoteIdentifier('topic_status') . ' != ?'
            . ' AND ' . $db->quoteIdentifier('topic_approved') . ' = 1'
            . ' ORDER BY ' . $db->quoteIdentifier('topic_last_post_time') . ' DESC'
            . ' LIMIT ' . max(1, min(2000, $limit));

        /** @var list<object> $rows */
        $rows = $db->getResults($sql, [$forumId, 'deleted']);

        return $rows;
    }

    private static function getTopicRow(int $topicId, AP_DB $db): ?object
    {
        if ($topicId < 1) {
            return null;
        }
        if (class_exists('AP_Forum', false) && method_exists('AP_Forum', 'getTopic')) {
            $topic = AP_Forum::getTopic($topicId, $db);
            if ($topic !== null) {
                return $topic;
            }
        }
        $table = $db->quoteIdentifier($db->table('topics'));
        $row = $db->getRow(
            'SELECT * FROM ' . $table . ' WHERE ' . $db->quoteIdentifier('topic_id') . ' = ?',
            [$topicId]
        );

        return $row;
    }

    /**
     * @param list<string> $values
     */
    private static function maxDatetime(array $values): string
    {
        $best = self::EMPTY_DATETIME;
        foreach ($values as $v) {
            if (!is_string($v) || $v === '') {
                continue;
            }
            if (self::compareDatetime($v, $best) > 0) {
                $best = $v;
            }
        }

        return $best;
    }

    /**
     * Compare two MySQL-style datetimes. Returns -1 / 0 / 1.
     */
    private static function compareDatetime(string $a, string $b): int
    {
        $ta = strtotime($a);
        $tb = strtotime($b);
        if ($ta === false) {
            $ta = 0;
        }
        if ($tb === false) {
            $tb = 0;
        }

        return $ta <=> $tb;
    }

    /**
     * @param bool $defaultNow When empty and true, return now; when false, return ''
     */
    private static function normalizeMarkTime(string $markTime, bool $defaultNow = true): string
    {
        $markTime = trim($markTime);
        if ($markTime === '') {
            return $defaultNow ? self::nowLocal() : '';
        }
        $ts = strtotime($markTime);
        if ($ts === false) {
            return $defaultNow ? self::nowLocal() : '';
        }

        return date('Y-m-d H:i:s', $ts);
    }

    private static function nowLocal(): string
    {
        if (function_exists('ap_current_time')) {
            return (string) ap_current_time('mysql');
        }

        return date('Y-m-d H:i:s');
    }

    private static function optionValue(string $name, string $default, ?AP_DB $db): string
    {
        if (class_exists('AP_Options', false)) {
            return (string) AP_Options::get($name, $default, $db);
        }
        if (function_exists('ap_get_option')) {
            return (string) ap_get_option($name, $default, $db);
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

        throw new RuntimeException('Database connection is not available for forum read tracking.');
    }
}
