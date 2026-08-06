<?php

/**
 * Forum post likes (thumbs-up) for registered users.
 *
 * One like per user per post. Denormalized on forum_posts.like_count and
 * usermeta counters via {@see AP_Forum_Stats}.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * Like / unlike forum posts; query counts and membership.
 */
class AP_Forum_Like
{
    /** Usermeta: total likes this user has given. */
    public const META_LIKES_GIVEN = 'forum_likes_given';

    /** Usermeta: total likes this user's posts have received. */
    public const META_LIKES_RECEIVED = 'forum_likes_received';

    /**
     * Whether the user has liked the post.
     */
    public static function userLiked(int $userId, int $postId, ?AP_DB $db = null): bool
    {
        if ($userId < 1 || $postId < 1) {
            return false;
        }
        $db = self::resolveDb($db);
        $table = $db->quoteIdentifier($db->table('forum_post_likes'));
        $n = (int) $db->getVar(
            'SELECT COUNT(*) FROM ' . $table
            . ' WHERE ' . $db->quoteIdentifier('post_id') . ' = ?'
            . ' AND ' . $db->quoteIdentifier('user_id') . ' = ?',
            [$postId, $userId]
        );

        return $n > 0;
    }

    /**
     * Like count for a post (prefers denormalized column).
     */
    public static function countForPost(int $postId, ?AP_DB $db = null): int
    {
        if ($postId < 1) {
            return 0;
        }
        $db = self::resolveDb($db);
        if (class_exists('AP_Forum', false)) {
            $post = AP_Forum::getPost($postId, $db);
            if ($post !== null && isset($post->like_count)) {
                return max(0, (int) $post->like_count);
            }
        }
        $table = $db->quoteIdentifier($db->table('forum_post_likes'));

        return max(0, (int) $db->getVar(
            'SELECT COUNT(*) FROM ' . $table
            . ' WHERE ' . $db->quoteIdentifier('post_id') . ' = ?',
            [$postId]
        ));
    }

    /**
     * Toggle like for the current (or given) user. Returns new state.
     *
     * @return array{ok: bool, liked: bool, count: int, error: string}
     */
    public static function toggle(int $postId, int $userId = 0, ?AP_DB $db = null): array
    {
        $db = self::resolveDb($db);
        if ($userId < 1 && function_exists('ap_get_current_user_id')) {
            $userId = (int) ap_get_current_user_id($db);
        }
        if ($userId < 1) {
            return ['ok' => false, 'liked' => false, 'count' => 0, 'error' => 'login_required'];
        }
        if ($postId < 1 || !class_exists('AP_Forum', false)) {
            return ['ok' => false, 'liked' => false, 'count' => 0, 'error' => 'invalid_post'];
        }

        $post = AP_Forum::getPost($postId, $db);
        if ($post === null || (int) ($post->post_approved ?? 0) !== 1) {
            return ['ok' => false, 'liked' => false, 'count' => 0, 'error' => 'invalid_post'];
        }

        $forumId = (int) $post->forum_id;
        if (class_exists('AP_Forum_Permissions', false)
            && !AP_Forum_Permissions::userCanViewForum($userId, $forumId, $db)
        ) {
            return ['ok' => false, 'liked' => false, 'count' => 0, 'error' => 'forbidden'];
        }

        if (self::userLiked($userId, $postId, $db)) {
            $ok = self::unlike($postId, $userId, $db);
            $count = self::countForPost($postId, $db);

            return [
                'ok' => $ok,
                'liked' => false,
                'count' => $count,
                'error' => $ok ? '' : 'unlike_failed',
            ];
        }

        $ok = self::like($postId, $userId, $db);
        $count = self::countForPost($postId, $db);

        return [
            'ok' => $ok,
            'liked' => $ok,
            'count' => $count,
            'error' => $ok ? '' : 'like_failed',
        ];
    }

    /**
     * Add a like (no-op if already liked).
     */
    public static function like(int $postId, int $userId, ?AP_DB $db = null): bool
    {
        if ($postId < 1 || $userId < 1) {
            return false;
        }
        $db = self::resolveDb($db);
        if (self::userLiked($userId, $postId, $db)) {
            return true;
        }

        $post = class_exists('AP_Forum', false) ? AP_Forum::getPost($postId, $db) : null;
        if ($post === null) {
            return false;
        }

        $now = gmdate('Y-m-d H:i:s');
        $ok = $db->insert('forum_post_likes', [
            'post_id' => $postId,
            'user_id' => $userId,
            'created_at' => $now,
        ]);
        if ($ok === false) {
            return false;
        }

        self::adjustPostLikeCount($postId, 1, $db);
        if (class_exists('AP_Forum_Stats', false)) {
            AP_Forum_Stats::incrementLikesGiven($userId, 1, $db);
            $authorId = (int) ($post->poster_id ?? 0);
            if ($authorId > 0) {
                AP_Forum_Stats::incrementLikesReceived($authorId, 1, $db);
            }
        }

        if (function_exists('ap_do_action')) {
            ap_do_action('ap_forum_post_liked', $postId, $userId);
        }

        return true;
    }

    /**
     * Remove a like (no-op if not liked).
     */
    public static function unlike(int $postId, int $userId, ?AP_DB $db = null): bool
    {
        if ($postId < 1 || $userId < 1) {
            return false;
        }
        $db = self::resolveDb($db);
        if (!self::userLiked($userId, $postId, $db)) {
            return true;
        }

        $post = class_exists('AP_Forum', false) ? AP_Forum::getPost($postId, $db) : null;
        $ok = $db->delete('forum_post_likes', [
            'post_id' => $postId,
            'user_id' => $userId,
        ]);
        if ($ok === false) {
            return false;
        }

        self::adjustPostLikeCount($postId, -1, $db);
        if (class_exists('AP_Forum_Stats', false)) {
            AP_Forum_Stats::incrementLikesGiven($userId, -1, $db);
            $authorId = $post !== null ? (int) ($post->poster_id ?? 0) : 0;
            if ($authorId > 0) {
                AP_Forum_Stats::incrementLikesReceived($authorId, -1, $db);
            }
        }

        if (function_exists('ap_do_action')) {
            ap_do_action('ap_forum_post_unliked', $postId, $userId);
        }

        return true;
    }

    /**
     * Map of post_id => liked for a user (batch).
     *
     * @param list<int> $postIds
     *
     * @return array<int, bool>
     */
    public static function likedMapForUser(int $userId, array $postIds, ?AP_DB $db = null): array
    {
        $out = [];
        foreach ($postIds as $id) {
            $out[(int) $id] = false;
        }
        if ($userId < 1 || $postIds === []) {
            return $out;
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $postIds), static fn (int $i): bool => $i > 0)));
        if ($ids === []) {
            return $out;
        }
        $db = self::resolveDb($db);
        $table = $db->quoteIdentifier($db->table('forum_post_likes'));
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $params = array_merge([$userId], $ids);
        $rows = $db->getResults(
            'SELECT ' . $db->quoteIdentifier('post_id') . ' FROM ' . $table
            . ' WHERE ' . $db->quoteIdentifier('user_id') . ' = ?'
            . ' AND ' . $db->quoteIdentifier('post_id') . ' IN (' . $placeholders . ')',
            $params
        );
        foreach ($rows as $row) {
            $pid = (int) (is_object($row) ? ($row->post_id ?? 0) : ($row['post_id'] ?? 0));
            if ($pid > 0) {
                $out[$pid] = true;
            }
        }

        return $out;
    }

    private static function adjustPostLikeCount(int $postId, int $delta, AP_DB $db): void
    {
        $post = class_exists('AP_Forum', false) ? AP_Forum::getPost($postId, $db) : null;
        $current = $post !== null ? max(0, (int) ($post->like_count ?? 0)) : 0;
        $next = max(0, $current + $delta);
        $db->update('forum_posts', ['like_count' => $next], ['post_id' => $postId]);
    }

    private static function resolveDb(?AP_DB $db): AP_DB
    {
        if ($db instanceof AP_DB) {
            return $db;
        }
        if (function_exists('ap_db')) {
            return ap_db();
        }
        throw new RuntimeException('Database not available for forum likes.');
    }
}
