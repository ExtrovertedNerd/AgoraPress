<?php

/**
 * Forum / community activity counters (phpBB-style profile + board footer).
 *
 * ## Per-user stats (author panel / profile)
 *
 * Fast path: denormalized usermeta, kept in sync on post create/delete and likes.
 * Slow path / repair: live SQL aggregates via {@see queryLikesGiven()},
 * {@see queryLikesReceived()}, {@see queryLikesAggregatesForUsers()}, and
 * {@see rebuildLikeCounts()}.
 *
 * Topic view author panels should use {@see getUsersStats()} (one usermeta query
 * for all distinct posters) rather than per-post {@see getUserStats()} calls.
 *
 * | Meta key                 | Meaning                              |
 * |--------------------------|--------------------------------------|
 * | forum_posts              | Approved forum posts authored        |
 * | forum_likes_given        | Likes this user has cast             |
 * | forum_likes_received     | Likes on this user's posts           |
 * | comment_count (optional) | Blog comments (when maintained)      |
 *
 * ## Board-level aggregates (forum footer)
 *
 * {@see getBoardStats()} / {@see getTotalTopics()} / {@see getTotalPosts()} /
 * {@see getTotalMembers()} for the board index footer:
 * “Total Topics: N · Total Posts: N · Total Members: N”.
 *
 * Definitions (SPEC §C — keep UI and denormalized `forums.*_count` aligned):
 * - **Topics**: approved topics with `topic_status` ≠ deleted (board-wide).
 * - **Posts**: approved `forum_posts` rows (**opening posts + replies**) whose
 *   parent topic is approved and not soft-deleted. **Not “replies only”.**
 *   Same definition as forum-row `post_count` and topic-row `posts`
 *   (`reply_count + 1`). Canonical write-up: {@see AP_Forum} class docblock
 *   (“Post-count definition”).
 * - **Members**: registered users in the `users` table (guests are not counted).
 *
 * Live SQL COUNTs only — no object cache / transient lag. Local DB only; no telemetry.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * Forum activity statistics helpers (per-user and board-level).
 */
class AP_Forum_Stats
{
    public const META_FORUM_POSTS = 'forum_posts';

    public const META_LIKES_GIVEN = 'forum_likes_given';

    public const META_LIKES_RECEIVED = 'forum_likes_received';

    public const META_COMMENT_COUNT = 'comment_count';

    /**
     * Keys loaded for author-panel / profile stats.
     *
     * @var list<string>
     */
    private const STAT_META_KEYS = [
        self::META_FORUM_POSTS,
        self::META_LIKES_GIVEN,
        self::META_LIKES_RECEIVED,
        self::META_COMMENT_COUNT,
    ];

    /**
     * Zeroed stats shape for guests / missing users.
     *
     * @return array{
     *   forum_posts: int,
     *   forum_likes_given: int,
     *   forum_likes_received: int,
     *   comments: int
     * }
     */
    public static function emptyStats(): array
    {
        return [
            'forum_posts' => 0,
            'forum_likes_given' => 0,
            'forum_likes_received' => 0,
            'comments' => 0,
        ];
    }

    /**
     * Zeroed board footer shape (Total Topics · Total Posts · Total Members).
     *
     * @return array{topics: int, posts: int, members: int}
     */
    public static function emptyBoardStats(): array
    {
        return [
            'topics' => 0,
            'posts' => 0,
            'members' => 0,
        ];
    }

    /**
     * Board-level aggregates for the forum footer (board index).
     *
     * See class docblock for topic / post / member definitions. Three live
     * queries (topics, posts, users); no caching lag.
     *
     * @return array{topics: int, posts: int, members: int}
     */
    public static function getBoardStats(?AP_DB $db = null): array
    {
        try {
            $db = self::resolveDb($db);
        } catch (Throwable) {
            return self::emptyBoardStats();
        }

        return [
            'topics' => self::getTotalTopics($db),
            'posts' => self::getTotalPosts($db),
            'members' => self::getTotalMembers($db),
        ];
    }

    /**
     * Total board topics: approved, not soft-deleted.
     */
    public static function getTotalTopics(?AP_DB $db = null): int
    {
        try {
            $db = self::resolveDb($db);
            $table = $db->quoteIdentifier($db->table('topics'));
            $statusCol = $db->quoteIdentifier('topic_status');
            $approvedCol = $db->quoteIdentifier('topic_approved');

            $n = (int) $db->getVar(
                'SELECT COUNT(*) FROM ' . $table
                . ' WHERE ' . $approvedCol . ' = 1'
                . ' AND ' . $statusCol . ' != ?',
                [class_exists('AP_Forum', false) ? AP_Forum::TOPIC_STATUS_DELETED : 'deleted']
            );

            return max(0, $n);
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Total board posts: approved opening posts + replies under visible topics.
     *
     * Posts whose parent topic is soft-deleted or unapproved are excluded so
     * the total stays consistent with denormalized `forums.post_count` sums.
     */
    public static function getTotalPosts(?AP_DB $db = null): int
    {
        try {
            $db = self::resolveDb($db);
            $posts = $db->quoteIdentifier($db->table('forum_posts'));
            $topics = $db->quoteIdentifier($db->table('topics'));
            $deleted = class_exists('AP_Forum', false) ? AP_Forum::TOPIC_STATUS_DELETED : 'deleted';

            $n = (int) $db->getVar(
                'SELECT COUNT(*) FROM ' . $posts . ' p'
                . ' INNER JOIN ' . $topics . ' t ON t.' . $db->quoteIdentifier('topic_id')
                . ' = p.' . $db->quoteIdentifier('topic_id')
                . ' WHERE p.' . $db->quoteIdentifier('post_approved') . ' = 1'
                . ' AND t.' . $db->quoteIdentifier('topic_approved') . ' = 1'
                . ' AND t.' . $db->quoteIdentifier('topic_status') . ' != ?',
                [$deleted]
            );

            return max(0, $n);
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Total registered members (all rows in `users`). Guests are not counted.
     */
    public static function getTotalMembers(?AP_DB $db = null): int
    {
        try {
            if (class_exists('AP_User', false)) {
                return max(0, AP_User::count([], $db));
            }
            $db = self::resolveDb($db);
            $table = $db->quoteIdentifier($db->table('users'));

            return max(0, (int) $db->getVar('SELECT COUNT(*) FROM ' . $table));
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Cheap board totals from denormalized `forums.topic_count` / `post_count`.
     *
     * Prefer {@see getBoardStats()} for ground-truth live counts. Use this when
     * the index page already loads forum rows and counters are known healthy
     * (may lag only if denormalized stats were corrupted; not a time-based cache).
     *
     * @return array{topics: int, posts: int, members: int}
     */
    public static function getBoardStatsFromForumCounters(?AP_DB $db = null): array
    {
        $members = self::getTotalMembers($db);
        try {
            $db = self::resolveDb($db);
            $table = $db->quoteIdentifier($db->table('forums'));
            $row = $db->getRow(
                'SELECT COALESCE(SUM(' . $db->quoteIdentifier('topic_count') . '), 0) AS topics,'
                . ' COALESCE(SUM(' . $db->quoteIdentifier('post_count') . '), 0) AS posts'
                . ' FROM ' . $table
            );
            $topics = (int) (is_object($row) ? ($row->topics ?? 0) : ($row['topics'] ?? 0));
            $posts = (int) (is_object($row) ? ($row->posts ?? 0) : ($row['posts'] ?? 0));

            return [
                'topics' => max(0, $topics),
                'posts' => max(0, $posts),
                'members' => $members,
            ];
        } catch (Throwable) {
            return [
                'topics' => 0,
                'posts' => 0,
                'members' => $members,
            ];
        }
    }

    /**
     * Author-panel subset of {@see getUserStats()} (posts + likes given/received).
     *
     * @return array{
     *   forum_posts: int,
     *   forum_likes_given: int,
     *   forum_likes_received: int
     * }
     */
    public static function getAuthorPanelStats(int $userId, ?AP_DB $db = null): array
    {
        $full = self::getUserStats($userId, $db);

        return [
            'forum_posts' => (int) ($full['forum_posts'] ?? 0),
            'forum_likes_given' => (int) ($full['forum_likes_given'] ?? 0),
            'forum_likes_received' => (int) ($full['forum_likes_received'] ?? 0),
        ];
    }

    /**
     * @return array{
     *   forum_posts: int,
     *   forum_likes_given: int,
     *   forum_likes_received: int,
     *   comments: int
     * }
     */
    public static function getUserStats(int $userId, ?AP_DB $db = null): array
    {
        if ($userId < 1) {
            return self::emptyStats();
        }

        $batch = self::getUsersStats([$userId], $db);

        return $batch[$userId] ?? self::emptyStats();
    }

    /**
     * Batch-load denormalized stats for many users (topic author pane, no N+1).
     *
     * One usermeta SELECT for all users and stat keys. Missing keys default to 0.
     *
     * @param list<int> $userIds
     *
     * @return array<int, array{
     *   forum_posts: int,
     *   forum_likes_given: int,
     *   forum_likes_received: int,
     *   comments: int
     * }>
     */
    public static function getUsersStats(array $userIds, ?AP_DB $db = null): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn ($id): int => (int) $id, $userIds),
            static fn (int $id): bool => $id > 0
        )));

        $out = [];
        foreach ($ids as $id) {
            $out[$id] = self::emptyStats();
        }
        if ($ids === []) {
            return $out;
        }

        $db = self::resolveDb($db);
        $table = $db->quoteIdentifier($db->table('usermeta'));
        $idPh = implode(', ', array_fill(0, count($ids), '?'));
        $keys = self::STAT_META_KEYS;
        $keyPh = implode(', ', array_fill(0, count($keys), '?'));
        $params = array_merge($ids, $keys);

        try {
            $rows = $db->getResults(
                'SELECT ' . $db->quoteIdentifier('user_id') . ', '
                . $db->quoteIdentifier('meta_key') . ', '
                . $db->quoteIdentifier('meta_value')
                . ' FROM ' . $table
                . ' WHERE ' . $db->quoteIdentifier('user_id') . ' IN (' . $idPh . ')'
                . ' AND ' . $db->quoteIdentifier('meta_key') . ' IN (' . $keyPh . ')',
                $params
            );
        } catch (Throwable) {
            // Fall back to per-user meta reads if batch query fails.
            foreach ($ids as $id) {
                $out[$id] = [
                    'forum_posts' => self::getMetaInt($id, self::META_FORUM_POSTS, $db),
                    'forum_likes_given' => self::getMetaInt($id, self::META_LIKES_GIVEN, $db),
                    'forum_likes_received' => self::getMetaInt($id, self::META_LIKES_RECEIVED, $db),
                    'comments' => self::getMetaInt($id, self::META_COMMENT_COUNT, $db),
                ];
            }

            return $out;
        }

        foreach ($rows as $row) {
            $uid = (int) (is_object($row) ? ($row->user_id ?? 0) : ($row['user_id'] ?? 0));
            $key = (string) (is_object($row) ? ($row->meta_key ?? '') : ($row['meta_key'] ?? ''));
            $raw = is_object($row) ? ($row->meta_value ?? null) : ($row['meta_value'] ?? null);
            if ($uid < 1 || !isset($out[$uid]) || $key === '') {
                continue;
            }
            $value = max(0, (int) $raw);
            match ($key) {
                self::META_FORUM_POSTS => $out[$uid]['forum_posts'] = $value,
                self::META_LIKES_GIVEN => $out[$uid]['forum_likes_given'] = $value,
                self::META_LIKES_RECEIVED => $out[$uid]['forum_likes_received'] = $value,
                self::META_COMMENT_COUNT => $out[$uid]['comments'] = $value,
                default => null,
            };
        }

        return $out;
    }

    /**
     * Author-panel subset for many users (batch).
     *
     * @param list<int> $userIds
     *
     * @return array<int, array{
     *   forum_posts: int,
     *   forum_likes_given: int,
     *   forum_likes_received: int
     * }>
     */
    public static function getAuthorPanelStatsForUsers(array $userIds, ?AP_DB $db = null): array
    {
        $full = self::getUsersStats($userIds, $db);
        $out = [];
        foreach ($full as $uid => $stats) {
            $out[(int) $uid] = [
                'forum_posts' => (int) ($stats['forum_posts'] ?? 0),
                'forum_likes_given' => (int) ($stats['forum_likes_given'] ?? 0),
                'forum_likes_received' => (int) ($stats['forum_likes_received'] ?? 0),
            ];
        }

        return $out;
    }

    public static function getForumPostCount(int $userId, ?AP_DB $db = null): int
    {
        return self::getMetaInt($userId, self::META_FORUM_POSTS, $db);
    }

    public static function getLikesGiven(int $userId, ?AP_DB $db = null): int
    {
        return self::getMetaInt($userId, self::META_LIKES_GIVEN, $db);
    }

    public static function getLikesReceived(int $userId, ?AP_DB $db = null): int
    {
        return self::getMetaInt($userId, self::META_LIKES_RECEIVED, $db);
    }

    public static function incrementForumPosts(int $userId, int $delta = 1, ?AP_DB $db = null): void
    {
        self::incrementMeta($userId, self::META_FORUM_POSTS, $delta, $db);
    }

    public static function incrementLikesGiven(int $userId, int $delta = 1, ?AP_DB $db = null): void
    {
        self::incrementMeta($userId, self::META_LIKES_GIVEN, $delta, $db);
    }

    public static function incrementLikesReceived(int $userId, int $delta = 1, ?AP_DB $db = null): void
    {
        self::incrementMeta($userId, self::META_LIKES_RECEIVED, $delta, $db);
    }

    public static function incrementComments(int $userId, int $delta = 1, ?AP_DB $db = null): void
    {
        self::incrementMeta($userId, self::META_COMMENT_COUNT, $delta, $db);
    }

    /**
     * Live COUNT of likes this user has cast (ground truth from forum_post_likes).
     */
    public static function queryLikesGiven(int $userId, ?AP_DB $db = null): int
    {
        if ($userId < 1) {
            return 0;
        }
        $db = self::resolveDb($db);
        $likes = $db->quoteIdentifier($db->table('forum_post_likes'));

        return max(0, (int) $db->getVar(
            'SELECT COUNT(*) FROM ' . $likes
            . ' WHERE ' . $db->quoteIdentifier('user_id') . ' = ?',
            [$userId]
        ));
    }

    /**
     * Live COUNT of likes on this user's posts (ground truth; join forum_posts).
     */
    public static function queryLikesReceived(int $userId, ?AP_DB $db = null): int
    {
        if ($userId < 1) {
            return 0;
        }
        $db = self::resolveDb($db);
        $likes = $db->quoteIdentifier($db->table('forum_post_likes'));
        $posts = $db->quoteIdentifier($db->table('forum_posts'));

        return max(0, (int) $db->getVar(
            'SELECT COUNT(*) FROM ' . $likes . ' l'
            . ' INNER JOIN ' . $posts . ' p ON p.' . $db->quoteIdentifier('post_id')
            . ' = l.' . $db->quoteIdentifier('post_id')
            . ' WHERE p.' . $db->quoteIdentifier('poster_id') . ' = ?',
            [$userId]
        ));
    }

    /**
     * Live like given/received aggregates for many users (2 GROUP BY queries).
     *
     * Prefer {@see getUsersStats()} for the author panel hot path; use this for
     * repair, admin tools, or when usermeta may be missing/stale.
     *
     * @param list<int> $userIds
     *
     * @return array<int, array{given: int, received: int}>
     */
    public static function queryLikesAggregatesForUsers(array $userIds, ?AP_DB $db = null): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn ($id): int => (int) $id, $userIds),
            static fn (int $id): bool => $id > 0
        )));

        $out = [];
        foreach ($ids as $id) {
            $out[$id] = ['given' => 0, 'received' => 0];
        }
        if ($ids === []) {
            return $out;
        }

        $db = self::resolveDb($db);
        $likes = $db->quoteIdentifier($db->table('forum_post_likes'));
        $posts = $db->quoteIdentifier($db->table('forum_posts'));
        $ph = implode(', ', array_fill(0, count($ids), '?'));

        $givenRows = $db->getResults(
            'SELECT ' . $db->quoteIdentifier('user_id') . ' AS uid, COUNT(*) AS cnt'
            . ' FROM ' . $likes
            . ' WHERE ' . $db->quoteIdentifier('user_id') . ' IN (' . $ph . ')'
            . ' GROUP BY ' . $db->quoteIdentifier('user_id'),
            $ids
        );
        foreach ($givenRows as $row) {
            $uid = (int) (is_object($row) ? ($row->uid ?? 0) : ($row['uid'] ?? 0));
            $cnt = (int) (is_object($row) ? ($row->cnt ?? 0) : ($row['cnt'] ?? 0));
            if ($uid > 0 && isset($out[$uid])) {
                $out[$uid]['given'] = max(0, $cnt);
            }
        }

        $recvRows = $db->getResults(
            'SELECT p.' . $db->quoteIdentifier('poster_id') . ' AS uid, COUNT(*) AS cnt'
            . ' FROM ' . $likes . ' l'
            . ' INNER JOIN ' . $posts . ' p ON p.' . $db->quoteIdentifier('post_id')
            . ' = l.' . $db->quoteIdentifier('post_id')
            . ' WHERE p.' . $db->quoteIdentifier('poster_id') . ' IN (' . $ph . ')'
            . ' GROUP BY p.' . $db->quoteIdentifier('poster_id'),
            $ids
        );
        foreach ($recvRows as $row) {
            $uid = (int) (is_object($row) ? ($row->uid ?? 0) : ($row['uid'] ?? 0));
            $cnt = (int) (is_object($row) ? ($row->cnt ?? 0) : ($row['cnt'] ?? 0));
            if ($uid > 0 && isset($out[$uid])) {
                $out[$uid]['received'] = max(0, $cnt);
            }
        }

        return $out;
    }

    /**
     * Recompute forum_posts from the database (repair / migration).
     */
    public static function rebuildForumPostCount(int $userId, ?AP_DB $db = null): int
    {
        if ($userId < 1 || !class_exists('AP_Forum', false)) {
            return 0;
        }
        $db = self::resolveDb($db);
        $table = $db->quoteIdentifier($db->table('forum_posts'));
        $n = (int) $db->getVar(
            'SELECT COUNT(*) FROM ' . $table
            . ' WHERE ' . $db->quoteIdentifier('poster_id') . ' = ?'
            . ' AND ' . $db->quoteIdentifier('post_approved') . ' = 1',
            [$userId]
        );
        self::setMetaInt($userId, self::META_FORUM_POSTS, $n, $db);

        return $n;
    }

    /**
     * Recompute like counters for a user from forum_post_likes.
     *
     * @return array{given: int, received: int}
     */
    public static function rebuildLikeCounts(int $userId, ?AP_DB $db = null): array
    {
        if ($userId < 1) {
            return ['given' => 0, 'received' => 0];
        }
        $given = self::queryLikesGiven($userId, $db);
        $received = self::queryLikesReceived($userId, $db);

        self::setMetaInt($userId, self::META_LIKES_GIVEN, $given, $db);
        self::setMetaInt($userId, self::META_LIKES_RECEIVED, $received, $db);

        return ['given' => $given, 'received' => $received];
    }

    /**
     * Recompute like counters for many users from live aggregates.
     *
     * @param list<int> $userIds
     *
     * @return array<int, array{given: int, received: int}>
     */
    public static function rebuildLikeCountsForUsers(array $userIds, ?AP_DB $db = null): array
    {
        $db = self::resolveDb($db);
        $live = self::queryLikesAggregatesForUsers($userIds, $db);
        foreach ($live as $uid => $counts) {
            self::setMetaInt((int) $uid, self::META_LIKES_GIVEN, (int) $counts['given'], $db);
            self::setMetaInt((int) $uid, self::META_LIKES_RECEIVED, (int) $counts['received'], $db);
        }

        return $live;
    }

    /**
     * Hook: after a forum post is inserted (approved only).
     */
    public static function onPostInserted(int $postId, ?object $post = null, ?AP_DB $db = null): void
    {
        if ($post === null && class_exists('AP_Forum', false)) {
            $post = AP_Forum::getPost($postId, $db);
        }
        if ($post === null || (int) ($post->post_approved ?? 0) !== 1) {
            return;
        }
        $uid = (int) ($post->poster_id ?? 0);
        if ($uid > 0) {
            self::incrementForumPosts($uid, 1, $db);
        }
    }

    /**
     * Hook: post became unapproved (soft-delete / moderation hide).
     */
    public static function onPostUnapproved(int $postId, ?object $post = null, ?AP_DB $db = null): void
    {
        if ($post === null && class_exists('AP_Forum', false)) {
            $post = AP_Forum::getPost($postId, $db);
        }
        if ($post === null) {
            return;
        }
        $uid = (int) ($post->poster_id ?? 0);
        if ($uid > 0) {
            self::incrementForumPosts($uid, -1, $db);
        }
    }

    /**
     * Hook: post became approved again.
     */
    public static function onPostApproved(int $postId, ?object $post = null, ?AP_DB $db = null): void
    {
        self::onPostInserted($postId, $post, $db);
    }

    /**
     * Hook: hard-deleted post (pass pre-delete post row when available).
     */
    public static function onPostDeleted(int $postId, ?object $post = null, ?AP_DB $db = null): void
    {
        if ($post === null) {
            return;
        }
        if ((int) ($post->post_approved ?? 0) !== 1) {
            return;
        }
        $uid = (int) ($post->poster_id ?? 0);
        if ($uid > 0) {
            self::incrementForumPosts($uid, -1, $db);
        }
    }

    /**
     * Stable callables for {@see registerHooks()} (same instances for has_action checks).
     *
     * @var array<string, callable>|null
     */
    private static ?array $hookCallbacks = null;

    /**
     * Hook registration for bootstrap.
     *
     * Safe to call after {@see ap_reset_hooks()} (tests); re-registers when the
     * action table was cleared. Idempotent within one hook-registry lifetime
     * (checks our own callback, not merely whether the hook name has any listener).
     */
    public static function registerHooks(): void
    {
        if (!function_exists('ap_add_action')) {
            return;
        }
        if (self::$hookCallbacks === null) {
            self::$hookCallbacks = [
                'inserted' => static function (int $postId, $post = null): void {
                    self::onPostInserted($postId, is_object($post) ? $post : null);
                },
                'unapproved' => static function (int $postId, $post = null): void {
                    self::onPostUnapproved($postId, is_object($post) ? $post : null);
                },
                'approved' => static function (int $postId, $post = null): void {
                    self::onPostApproved($postId, is_object($post) ? $post : null);
                },
                'deleted' => static function (int $postId, $post = null): void {
                    self::onPostDeleted($postId, is_object($post) ? $post : null);
                },
            ];
        }
        if (function_exists('ap_has_action')
            && ap_has_action('ap_forum_post_inserted', self::$hookCallbacks['inserted'])
        ) {
            return;
        }
        ap_add_action('ap_forum_post_inserted', self::$hookCallbacks['inserted'], 10, 2);
        ap_add_action('ap_forum_post_unapproved', self::$hookCallbacks['unapproved'], 10, 2);
        ap_add_action('ap_forum_post_approved', self::$hookCallbacks['approved'], 10, 2);
        ap_add_action('ap_forum_post_deleted', self::$hookCallbacks['deleted'], 10, 2);
    }

    private static function getMetaInt(int $userId, string $key, ?AP_DB $db): int
    {
        if ($userId < 1 || !class_exists('AP_User', false)) {
            return 0;
        }
        try {
            $raw = AP_User::getMeta($userId, $key, $db);
        } catch (Throwable) {
            return 0;
        }
        if ($raw === null || $raw === '') {
            return 0;
        }

        return max(0, (int) $raw);
    }

    private static function setMetaInt(int $userId, string $key, int $value, ?AP_DB $db): void
    {
        if ($userId < 1 || !class_exists('AP_User', false)) {
            return;
        }
        try {
            AP_User::updateMeta($userId, $key, (string) max(0, $value), $db);
        } catch (Throwable) {
            // non-fatal
        }
    }

    private static function incrementMeta(int $userId, string $key, int $delta, ?AP_DB $db): void
    {
        if ($userId < 1 || $delta === 0) {
            return;
        }
        $current = self::getMetaInt($userId, $key, $db);
        self::setMetaInt($userId, $key, $current + $delta, $db);
    }

    private static function resolveDb(?AP_DB $db): AP_DB
    {
        if ($db instanceof AP_DB) {
            return $db;
        }
        if (function_exists('ap_db')) {
            return ap_db();
        }
        throw new RuntimeException('Database not available for forum stats.');
    }
}
