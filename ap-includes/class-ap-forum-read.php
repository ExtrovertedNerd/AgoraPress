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
     * Mark a topic as read when a logged-in user views posts (topic view side effect).
     *
     * Existing rules (SPEC B1 + topic_track watermark model):
     * - Guests never persist marks (no-op).
     * - Respects forum_unread_tracking_enabled + forum module (via isAvailable).
     * - First-unread post id is resolved *before* the mark advances so templates
     *   can render a “First unread post” jump on the same request.
     * - mark_time defaults to the latest approved post on the *viewed page*
     *   (page + per_page). Viewing page 1 of a multi-page topic does not claim
     *   later pages as read. When page/per_page are omitted, uses the topic’s
     *   last post time (or now if unknown).
     * - Soft-deleted topics are not marked.
     * - Marks only move forward (see markTopicRead / upsertTopicTrack).
     *
     * @param array<string, mixed> $args page (1-based), per_page, mark_time (override),
     *                                   check_enabled
     *
     * @return array{
     *   first_unread_post_id: int,
     *   marked: bool,
     *   mark_time: string
     * }
     */
    public static function markTopicReadOnView(
        int $userId,
        int $topicId,
        ?AP_DB $db = null,
        array $args = []
    ): array {
        $empty = [
            'first_unread_post_id' => 0,
            'marked' => false,
            'mark_time' => self::EMPTY_DATETIME,
        ];

        if ($userId < 1 || $topicId < 1) {
            return $empty;
        }

        $db = self::resolveDb($db);
        $checkEnabled = !array_key_exists('check_enabled', $args) || !empty($args['check_enabled']);
        if ($checkEnabled && !self::isAvailable($db)) {
            return $empty;
        }

        $topic = self::getTopicRow($topicId, $db);
        if ($topic === null) {
            return $empty;
        }

        $status = (string) ($topic->topic_status ?? '');
        if ($status === 'deleted') {
            return $empty;
        }

        // Resolve jump target before advancing the watermark.
        $firstUnreadId = self::getFirstUnreadPostId($userId, $topic, $db);

        $markTime = '';
        if (isset($args['mark_time']) && is_string($args['mark_time']) && trim($args['mark_time']) !== '') {
            $markTime = self::normalizeMarkTime(trim($args['mark_time']), false);
        }

        if ($markTime === '') {
            $markTime = self::resolveViewedPageMarkTime($topicId, $topic, $args, $db);
        }

        // Empty page / no posts: still expose first-unread, but do not write a track.
        if ($markTime === '' || $markTime === self::EMPTY_DATETIME) {
            return [
                'first_unread_post_id' => $firstUnreadId,
                'marked' => false,
                'mark_time' => self::EMPTY_DATETIME,
            ];
        }

        $marked = self::markTopicRead($userId, $topicId, $db, [
            'mark_time' => $markTime,
            'check_enabled' => $checkEnabled,
        ]);

        return [
            'first_unread_post_id' => $firstUnreadId,
            'marked' => $marked,
            'mark_time' => $markTime,
        ];
    }

    /**
     * Latest approved post_time on the viewed page (or topic last post).
     *
     * Returns EMPTY_DATETIME when the page has no posts (caller skips write).
     *
     * @param array<string, mixed> $args page, per_page
     * @param object               $topic Topic row
     */
    private static function resolveViewedPageMarkTime(
        int $topicId,
        object $topic,
        array $args,
        AP_DB $db
    ): string {
        $perPage = isset($args['per_page']) ? max(0, (int) $args['per_page']) : 0;
        $page = isset($args['page']) ? max(1, (int) $args['page']) : 1;

        // Explicit pagination: mark only through posts on this page.
        if ($perPage > 0 && class_exists('AP_Forum', false) && method_exists('AP_Forum', 'getPosts')) {
            $posts = AP_Forum::getPosts($topicId, [
                'per_page' => $perPage,
                'page' => $page,
                'approved_only' => true,
                'order' => 'ASC',
            ], $db);
            if ($posts !== []) {
                $last = $posts[array_key_last($posts)];
                $time = self::normalizeMarkTime((string) ($last->post_time ?? ''), false);
                if ($time !== '') {
                    return $time;
                }
            }

            // Empty page: do not invent a mark from "now" (would hide later posts).
            return self::EMPTY_DATETIME;
        }

        // Whole-topic view (no page window): use denormalized last post time.
        $lastPost = self::normalizeMarkTime(
            (string) ($topic->topic_last_post_time ?? ''),
            false
        );
        if ($lastPost !== '' && $lastPost !== self::EMPTY_DATETIME) {
            return $lastPost;
        }

        return self::nowLocal();
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
     * Accepts raw topic objects or display-row arrays. When rows only have
     * `id` (display shape), loads topic metadata as needed. Preloads per-topic
     * track marks for the user to limit N+1 queries on list views.
     *
     * @param list<object|array<string, mixed>> $topics
     *
     * @return list<array<string, mixed>>
     */
    public static function annotateTopics(int $userId, array $topics, ?AP_DB $db = null): array
    {
        $db = self::resolveDb($db);
        $available = $userId > 0 && self::isAvailable($db);

        $resolved = [];
        $topicIds = [];
        $forumIds = [];
        foreach ($topics as $topic) {
            if (is_array($topic)) {
                $row = $topic;
            } else {
                $row = (array) $topic;
            }

            $topicId = (int) ($row['topic_id'] ?? $row['id'] ?? 0);
            $forumId = (int) ($row['forum_id'] ?? 0);
            $lastPost = (string) ($row['topic_last_post_time'] ?? $row['last_date'] ?? '');
            $status = (string) ($row['topic_status'] ?? $row['status'] ?? '');

            // Display rows lack last-post/status; hydrate from topics table when needed.
            if ($available && $topicId > 0 && ($lastPost === '' || $status === '' || $forumId < 1)) {
                $full = self::getTopicRow($topicId, $db);
                if ($full !== null) {
                    $forumId = $forumId > 0 ? $forumId : (int) ($full->forum_id ?? 0);
                    $lastPost = $lastPost !== '' ? $lastPost : (string) ($full->topic_last_post_time ?? '');
                    $status = $status !== '' ? $status : (string) ($full->topic_status ?? '');
                    $row['topic_id'] = $topicId;
                    $row['forum_id'] = $forumId;
                    $row['topic_last_post_time'] = $lastPost;
                    $row['topic_status'] = $status;
                    $row['topic_approved'] = (int) ($full->topic_approved ?? 1);
                }
            }

            if ($topicId > 0) {
                $topicIds[] = $topicId;
            }
            if ($forumId > 0) {
                $forumIds[] = $forumId;
            }

            $resolved[] = [
                'row' => $row,
                'topic_id' => $topicId,
                'forum_id' => $forumId,
                'last_post' => $lastPost,
                'status' => $status,
            ];
        }

        $topicMarks = [];
        $forumMarks = [];
        $globalMark = self::EMPTY_DATETIME;
        if ($available) {
            $globalMark = self::getGlobalLastMark($userId, $db);
            $topicMarks = self::getTopicMarksBulk($userId, $topicIds, $db);
            $forumMarks = self::getForumMarksBulk($userId, $forumIds, $db);
        }

        $out = [];
        foreach ($resolved as $item) {
            $row = $item['row'];
            $isUnread = false;
            if ($available && $item['topic_id'] > 0) {
                $isUnread = self::isTopicUnreadWithMarks(
                    $item['topic_id'],
                    $item['forum_id'],
                    $item['last_post'],
                    $item['status'],
                    $globalMark,
                    $topicMarks[$item['topic_id']] ?? null,
                    $item['forum_id'] > 0 ? ($forumMarks[$item['forum_id']] ?? null) : null
                );
            }
            $row['is_unread'] = $isUnread;
            $out[] = $row;
        }

        return $out;
    }

    /**
     * Annotate forum display rows (or objects) with is_unread for a user.
     *
     * Forum rollup: unread when any visible topic is newer than the user’s
     * effective marks (topic_track / forum_track / forum_last_mark).
     *
     * Board-index hot path: bulk-loads forum last_post times, forum_track marks,
     * candidate topics, and topic_track marks so query count is O(1) in the
     * number of forums (not per-forum {@see isForumUnread()}).
     *
     * @param list<object|array<string, mixed>> $forums
     *
     * @return list<array<string, mixed>>
     */
    public static function annotateForums(int $userId, array $forums, ?AP_DB $db = null): array
    {
        $db = self::resolveDb($db);
        $available = $userId > 0 && self::isAvailable($db);

        /** @var list<array{row: array<string, mixed>, forum_id: int, last_post: string}> $items */
        $items = [];
        $forumIds = [];
        $missingLastPost = [];

        foreach ($forums as $forum) {
            $row = is_array($forum) ? $forum : (array) $forum;
            $forumId = (int) ($row['forum_id'] ?? $row['id'] ?? 0);
            $lastPost = self::extractForumLastPostTime($row);
            if ($forumId > 0) {
                $forumIds[] = $forumId;
                if ($lastPost === '' && $available) {
                    $missingLastPost[$forumId] = true;
                }
            }
            $items[] = [
                'row' => $row,
                'forum_id' => $forumId,
                'last_post' => $lastPost,
            ];
        }

        if (!$available) {
            $out = [];
            foreach ($items as $item) {
                $row = $item['row'];
                $row['is_unread'] = false;
                $out[] = $row;
            }

            return $out;
        }

        // One SELECT for forums missing last_post_time (partial display rows).
        if ($missingLastPost !== []) {
            $hydrated = self::getForumLastPostTimesBulk(array_keys($missingLastPost), $db);
            foreach ($items as $i => $item) {
                $fid = $item['forum_id'];
                if ($fid > 0 && $item['last_post'] === '' && isset($hydrated[$fid])) {
                    $items[$i]['last_post'] = $hydrated[$fid];
                }
            }
        }

        $globalMark = self::getGlobalLastMark($userId, $db);
        $forumMarks = self::getForumMarksBulk($userId, $forumIds, $db);

        // Forums whose last activity is after forum/global mark need a topic scan
        // (individual topic_track rows may still cover every topic).
        $needScanIds = [];
        foreach ($items as $item) {
            $fid = $item['forum_id'];
            $lastPost = $item['last_post'];
            if ($fid < 1 || $lastPost === '' || $lastPost === self::EMPTY_DATETIME) {
                continue;
            }
            $forumMark = $forumMarks[$fid] ?? null;
            $base = self::maxDatetime(array_filter([
                $globalMark,
                $forumMark ?? self::EMPTY_DATETIME,
            ]));
            if (self::compareDatetime($lastPost, $base) > 0) {
                $needScanIds[$fid] = $fid;
            }
        }

        $topicsByForum = [];
        $topicMarks = [];
        if ($needScanIds !== []) {
            $topicsByForum = self::getForumTopicCandidatesBulk(array_values($needScanIds), $db);
            $topicIds = [];
            foreach ($topicsByForum as $topicList) {
                foreach ($topicList as $topic) {
                    $tid = (int) ($topic->topic_id ?? 0);
                    if ($tid > 0) {
                        $topicIds[] = $tid;
                    }
                }
            }
            $topicMarks = self::getTopicMarksBulk($userId, $topicIds, $db);
        }

        $out = [];
        foreach ($items as $item) {
            $row = $item['row'];
            $fid = $item['forum_id'];
            $isUnread = false;

            if ($fid > 0 && isset($needScanIds[$fid])) {
                $forumMark = $forumMarks[$fid] ?? null;
                foreach ($topicsByForum[$fid] ?? [] as $topic) {
                    $tid = (int) ($topic->topic_id ?? 0);
                    $tLast = (string) ($topic->topic_last_post_time ?? '');
                    $status = (string) ($topic->topic_status ?? '');
                    if (self::isTopicUnreadWithMarks(
                        $tid,
                        $fid,
                        $tLast,
                        $status,
                        $globalMark,
                        $topicMarks[$tid] ?? null,
                        $forumMark
                    )) {
                        $isUnread = true;
                        break;
                    }
                }
            }

            $row['is_unread'] = $isUnread;
            $out[] = $row;
        }

        return $out;
    }

    /**
     * First approved post in a topic the user has not read (post_time > mark).
     *
     * Returns null for guests, disabled tracking, fully-read topics, or missing data.
     * Call this *before* markTopicRead() on topic view so the link remains useful.
     *
     * @param object|int $topic Topic row or topic_id
     */
    public static function getFirstUnreadPost(int $userId, object|int $topic, ?AP_DB $db = null): ?object
    {
        if ($userId < 1) {
            return null;
        }
        $db = self::resolveDb($db);
        if (!self::isAvailable($db)) {
            return null;
        }

        $row = is_object($topic) ? $topic : self::getTopicRow((int) $topic, $db);
        if ($row === null) {
            return null;
        }

        if (!self::isTopicUnread($userId, $row, $db)) {
            return null;
        }

        $topicId = (int) ($row->topic_id ?? 0);
        $forumId = (int) ($row->forum_id ?? 0);
        if ($topicId < 1) {
            return null;
        }

        $mark = self::getEffectiveTopicMark($userId, $topicId, $forumId, $db);
        $postsTable = $db->quoteIdentifier($db->table('forum_posts'));
        $post = $db->getRow(
            'SELECT * FROM ' . $postsTable
            . ' WHERE ' . $db->quoteIdentifier('topic_id') . ' = ?'
            . ' AND ' . $db->quoteIdentifier('post_approved') . ' = 1'
            . ' AND ' . $db->quoteIdentifier('post_time') . ' > ?'
            . ' ORDER BY ' . $db->quoteIdentifier('post_time') . ' ASC, '
            . $db->quoteIdentifier('post_id') . ' ASC'
            . ' LIMIT 1',
            [$topicId, $mark]
        );

        if ($post === null) {
            return null;
        }

        if (class_exists('AP_Forum', false) && method_exists('AP_Forum', 'getPost')) {
            $normalized = AP_Forum::getPost((int) ($post->post_id ?? 0), $db);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return $post;
    }

    /**
     * First unread post_id for a topic, or 0 when none / guest / disabled.
     *
     * @param object|int $topic Topic row or topic_id
     */
    public static function getFirstUnreadPostId(int $userId, object|int $topic, ?AP_DB $db = null): int
    {
        $post = self::getFirstUnreadPost($userId, $topic, $db);
        if ($post === null) {
            return 0;
        }

        return (int) ($post->post_id ?? $post->id ?? 0);
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

    /**
     * Topic unread using preloaded mark strings (avoids per-row SELECT).
     */
    private static function isTopicUnreadWithMarks(
        int $topicId,
        int $forumId,
        string $lastPost,
        string $status,
        string $globalMark,
        ?string $topicMark,
        ?string $forumMark
    ): bool {
        if ($topicId < 1) {
            return false;
        }
        if ($lastPost === '' || $lastPost === self::EMPTY_DATETIME) {
            return false;
        }
        if ($status === 'deleted') {
            return false;
        }

        $candidates = [$globalMark !== '' ? $globalMark : self::EMPTY_DATETIME];
        if ($topicMark !== null && $topicMark !== '') {
            $candidates[] = $topicMark;
        }
        if ($forumMark !== null && $forumMark !== '') {
            $candidates[] = $forumMark;
        }
        $mark = self::maxDatetime($candidates);

        return self::compareDatetime($lastPost, $mark) > 0;
    }

    /**
     * @param list<int> $topicIds
     *
     * @return array<int, string> topic_id => mark_time
     */
    private static function getTopicMarksBulk(int $userId, array $topicIds, AP_DB $db): array
    {
        $topicIds = array_values(array_unique(array_filter(
            array_map(static fn ($id): int => (int) $id, $topicIds),
            static fn (int $id): bool => $id > 0
        )));
        if ($userId < 1 || $topicIds === []) {
            return [];
        }

        $table = $db->quoteIdentifier($db->table('topic_track'));
        $placeholders = implode(', ', array_fill(0, count($topicIds), '?'));
        $params = array_merge([$userId], $topicIds);
        $rows = $db->getResults(
            'SELECT ' . $db->quoteIdentifier('topic_id') . ', ' . $db->quoteIdentifier('mark_time')
            . ' FROM ' . $table
            . ' WHERE ' . $db->quoteIdentifier('user_id') . ' = ?'
            . ' AND ' . $db->quoteIdentifier('topic_id') . ' IN (' . $placeholders . ')',
            $params
        );

        $out = [];
        foreach ($rows as $row) {
            $tid = (int) ($row->topic_id ?? 0);
            $mark = self::normalizeMarkTime((string) ($row->mark_time ?? ''), false);
            if ($tid > 0 && $mark !== '') {
                $out[$tid] = $mark;
            }
        }

        return $out;
    }

    /**
     * @param list<int> $forumIds
     *
     * @return array<int, string> forum_id => mark_time
     */
    private static function getForumMarksBulk(int $userId, array $forumIds, AP_DB $db): array
    {
        $forumIds = array_values(array_unique(array_filter(
            array_map(static fn ($id): int => (int) $id, $forumIds),
            static fn (int $id): bool => $id > 0
        )));
        if ($userId < 1 || $forumIds === []) {
            return [];
        }

        $table = $db->quoteIdentifier($db->table('forum_track'));
        $placeholders = implode(', ', array_fill(0, count($forumIds), '?'));
        $params = array_merge([$userId], $forumIds);
        $rows = $db->getResults(
            'SELECT ' . $db->quoteIdentifier('forum_id') . ', ' . $db->quoteIdentifier('mark_time')
            . ' FROM ' . $table
            . ' WHERE ' . $db->quoteIdentifier('user_id') . ' = ?'
            . ' AND ' . $db->quoteIdentifier('forum_id') . ' IN (' . $placeholders . ')',
            $params
        );

        $out = [];
        foreach ($rows as $row) {
            $fid = (int) ($row->forum_id ?? 0);
            $mark = self::normalizeMarkTime((string) ($row->mark_time ?? ''), false);
            if ($fid > 0 && $mark !== '') {
                $out[$fid] = $mark;
            }
        }

        return $out;
    }

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

    /**
     * Last-post timestamp from a forum display row or raw forum fields.
     *
     * @param array<string, mixed> $row
     */
    private static function extractForumLastPostTime(array $row): string
    {
        $direct = (string) ($row['last_post_time'] ?? '');
        if ($direct !== '' && $direct !== self::EMPTY_DATETIME) {
            return self::normalizeMarkTime($direct, false) ?: $direct;
        }

        $nested = $row['last_post'] ?? null;
        if (is_array($nested)) {
            $time = (string) ($nested['time'] ?? $nested['date'] ?? '');
            if ($time !== '' && $time !== self::EMPTY_DATETIME) {
                return self::normalizeMarkTime($time, false) ?: $time;
            }
        }

        return '';
    }

    /**
     * @param list<int> $forumIds
     *
     * @return array<int, string> forum_id => last_post_time (normalized; missing/empty omitted)
     */
    private static function getForumLastPostTimesBulk(array $forumIds, AP_DB $db): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn ($id): int => (int) $id, $forumIds),
            static fn (int $id): bool => $id > 0
        )));
        if ($ids === []) {
            return [];
        }

        $table = $db->quoteIdentifier($db->table('forums'));
        $ph = implode(', ', array_fill(0, count($ids), '?'));
        try {
            $rows = $db->getResults(
                'SELECT ' . $db->quoteIdentifier('forum_id') . ', '
                . $db->quoteIdentifier('last_post_time')
                . ' FROM ' . $table
                . ' WHERE ' . $db->quoteIdentifier('forum_id') . ' IN (' . $ph . ')',
                $ids
            );
        } catch (Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $fid = (int) (is_object($row) ? ($row->forum_id ?? 0) : ($row['forum_id'] ?? 0));
            $raw = (string) (is_object($row) ? ($row->last_post_time ?? '') : ($row['last_post_time'] ?? ''));
            if ($fid < 1 || $raw === '' || $raw === self::EMPTY_DATETIME) {
                continue;
            }
            $normalized = self::normalizeMarkTime($raw, false);
            $out[$fid] = $normalized !== '' ? $normalized : $raw;
        }

        return $out;
    }

    /**
     * Approved, non-deleted topics for many forums (board-index unread scan).
     *
     * Caps total rows to keep memory bounded on huge boards; newest activity first.
     *
     * @param list<int> $forumIds
     *
     * @return array<int, list<object>> forum_id => topic rows
     */
    private static function getForumTopicCandidatesBulk(array $forumIds, AP_DB $db, int $limit = 2000): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn ($id): int => (int) $id, $forumIds),
            static fn (int $id): bool => $id > 0
        )));
        $out = [];
        foreach ($ids as $id) {
            $out[$id] = [];
        }
        if ($ids === []) {
            return $out;
        }

        $topicsTable = $db->quoteIdentifier($db->table('topics'));
        $ph = implode(', ', array_fill(0, count($ids), '?'));
        $cap = max(1, min(5000, $limit));
        $params = array_merge($ids, ['deleted']);

        try {
            $rows = $db->getResults(
                'SELECT ' . $db->quoteIdentifier('topic_id') . ', '
                . $db->quoteIdentifier('forum_id') . ', '
                . $db->quoteIdentifier('topic_last_post_time') . ', '
                . $db->quoteIdentifier('topic_status')
                . ' FROM ' . $topicsTable
                . ' WHERE ' . $db->quoteIdentifier('forum_id') . ' IN (' . $ph . ')'
                . ' AND ' . $db->quoteIdentifier('topic_status') . ' != ?'
                . ' AND ' . $db->quoteIdentifier('topic_approved') . ' = 1'
                . ' ORDER BY ' . $db->quoteIdentifier('topic_last_post_time') . ' DESC'
                . ' LIMIT ' . $cap,
                $params
            );
        } catch (Throwable) {
            return $out;
        }

        foreach ($rows as $row) {
            $fid = (int) (is_object($row) ? ($row->forum_id ?? 0) : ($row['forum_id'] ?? 0));
            if ($fid > 0 && isset($out[$fid])) {
                $out[$fid][] = is_object($row) ? $row : (object) $row;
            }
        }

        return $out;
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
