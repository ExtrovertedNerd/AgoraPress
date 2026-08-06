<?php

/**
 * Forum / community activity counters for users (phpBB-style profile stats).
 *
 * Stored in usermeta and kept in sync on post create/delete and likes.
 *
 * | Meta key                 | Meaning                              |
 * |--------------------------|--------------------------------------|
 * | forum_posts              | Approved forum posts authored        |
 * | forum_likes_given        | Likes this user has cast             |
 * | forum_likes_received     | Likes on this user's posts           |
 * | comment_count (optional) | Blog comments (when maintained)      |
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * User activity statistics helpers.
 */
class AP_Forum_Stats
{
    public const META_FORUM_POSTS = 'forum_posts';

    public const META_LIKES_GIVEN = 'forum_likes_given';

    public const META_LIKES_RECEIVED = 'forum_likes_received';

    public const META_COMMENT_COUNT = 'comment_count';

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
            return [
                'forum_posts' => 0,
                'forum_likes_given' => 0,
                'forum_likes_received' => 0,
                'comments' => 0,
            ];
        }

        return [
            'forum_posts' => self::getMetaInt($userId, self::META_FORUM_POSTS, $db),
            'forum_likes_given' => self::getMetaInt($userId, self::META_LIKES_GIVEN, $db),
            'forum_likes_received' => self::getMetaInt($userId, self::META_LIKES_RECEIVED, $db),
            'comments' => self::getMetaInt($userId, self::META_COMMENT_COUNT, $db),
        ];
    }

    public static function getForumPostCount(int $userId, ?AP_DB $db = null): int
    {
        return self::getMetaInt($userId, self::META_FORUM_POSTS, $db);
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
        $db = self::resolveDb($db);
        $likes = $db->quoteIdentifier($db->table('forum_post_likes'));
        $posts = $db->quoteIdentifier($db->table('forum_posts'));

        $given = (int) $db->getVar(
            'SELECT COUNT(*) FROM ' . $likes
            . ' WHERE ' . $db->quoteIdentifier('user_id') . ' = ?',
            [$userId]
        );
        $received = (int) $db->getVar(
            'SELECT COUNT(*) FROM ' . $likes . ' l'
            . ' INNER JOIN ' . $posts . ' p ON p.' . $db->quoteIdentifier('post_id')
            . ' = l.' . $db->quoteIdentifier('post_id')
            . ' WHERE p.' . $db->quoteIdentifier('poster_id') . ' = ?',
            [$userId]
        );

        self::setMetaInt($userId, self::META_LIKES_GIVEN, $given, $db);
        self::setMetaInt($userId, self::META_LIKES_RECEIVED, $received, $db);

        return ['given' => $given, 'received' => $received];
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
