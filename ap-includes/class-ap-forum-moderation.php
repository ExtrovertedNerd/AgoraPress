<?php

/**
 * AgoraPress full forum moderation tools.
 *
 * Covers FEATURES §7 moderation surface:
 * - Edit (via {@see AP_Forum::updatePost()} / {@see AP_Forum::updateTopic()})
 * - Soft-delete / restore topics and posts
 * - Lock / unlock, sticky / announce helpers
 * - Move, merge, and split topics
 * - User reports ({prefix}reports, migration 0005)
 * - Warnings ({prefix}warnings, migration 0008)
 * - Bans / suspensions ({prefix}bans, migration 0008; shared with core users)
 *
 * Capability checks use {@see AP_Forum_Permissions::userCanModerate()} when a
 * moderator user id is supplied. Callers may pass moderator_id = 0 to skip ACL
 * (installers, CLI, tests) — UI layers must always pass the acting user.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * Forum moderation operations API.
 */
class AP_Forum_Moderation
{
    // -------------------------------------------------------------------------
    // Constants — reports
    // -------------------------------------------------------------------------

    public const REPORT_TYPE_POST = 'post';

    public const REPORT_TYPE_TOPIC = 'topic';

    public const REPORT_TYPE_USER = 'user';

    public const REPORT_TYPE_MESSAGE = 'message';

    public const REPORT_STATUS_OPEN = 'open';

    public const REPORT_STATUS_CLOSED = 'closed';

    public const REPORT_STATUS_DISMISSED = 'dismissed';

    // -------------------------------------------------------------------------
    // Constants — warnings
    // -------------------------------------------------------------------------

    public const WARNING_STATUS_ACTIVE = 'active';

    public const WARNING_STATUS_EXPIRED = 'expired';

    public const WARNING_STATUS_REVOKED = 'revoked';

    // -------------------------------------------------------------------------
    // Constants — bans
    // -------------------------------------------------------------------------

    public const BAN_TYPE_USER = 'user';

    public const BAN_TYPE_IP = 'ip';

    public const BAN_TYPE_EMAIL = 'email';

    public const BAN_STATUS_ACTIVE = 'active';

    public const BAN_STATUS_EXPIRED = 'expired';

    public const BAN_STATUS_LIFTED = 'lifted';

    /** user_status value used when an account is banned/suspended. */
    public const USER_STATUS_BANNED = 1;

    /** Epoch / empty datetime placeholder (matches forum tables). */
    public const EMPTY_DATETIME = '1970-01-01 00:00:00';

    // -------------------------------------------------------------------------
    // Topic moderation
    // -------------------------------------------------------------------------

    /**
     * Lock a topic (no further replies from non-mods).
     */
    public static function lockTopic(int $topicId, int $moderatorId = 0, ?AP_DB $db = null): bool
    {
        return self::setTopicStatus($topicId, AP_Forum::TOPIC_STATUS_LOCKED, $moderatorId, $db);
    }

    /**
     * Unlock a locked topic.
     */
    public static function unlockTopic(int $topicId, int $moderatorId = 0, ?AP_DB $db = null): bool
    {
        $db = self::resolveDb($db);
        $topic = AP_Forum::getTopic($topicId, $db);
        if ($topic === null) {
            return false;
        }
        if ((string) $topic->topic_status === AP_Forum::TOPIC_STATUS_DELETED) {
            return false;
        }

        return self::setTopicStatus($topicId, AP_Forum::TOPIC_STATUS_OPEN, $moderatorId, $db);
    }

    /**
     * Soft-delete a topic (status=deleted). Reversible via {@see restoreTopic()}.
     */
    public static function softDeleteTopic(int $topicId, int $moderatorId = 0, ?AP_DB $db = null): bool
    {
        $db = self::resolveDb($db);
        $topic = AP_Forum::getTopic($topicId, $db);
        if ($topic === null) {
            return false;
        }
        if (!self::moderatorMayAct($moderatorId, (int) $topic->forum_id, $db)) {
            return false;
        }

        $ok = AP_Forum::deleteTopic($topicId, false, $db);
        if ($ok && function_exists('ap_do_action')) {
            ap_do_action('ap_moderation_topic_soft_deleted', $topicId, $moderatorId);
        }

        return $ok;
    }

    /**
     * Restore a soft-deleted topic to open (and re-count forum stats).
     */
    public static function restoreTopic(int $topicId, int $moderatorId = 0, ?AP_DB $db = null): bool
    {
        if ($topicId < 1) {
            return false;
        }

        $db = self::resolveDb($db);
        $topic = AP_Forum::getTopic($topicId, $db);
        if ($topic === null) {
            return false;
        }
        if ((string) $topic->topic_status !== AP_Forum::TOPIC_STATUS_DELETED) {
            return true;
        }
        if (!self::moderatorMayAct($moderatorId, (int) $topic->forum_id, $db)) {
            return false;
        }

        $ok = $db->update('topics', [
            'topic_status' => AP_Forum::TOPIC_STATUS_OPEN,
            'topic_modified' => self::nowLocal(),
        ], ['topic_id' => $topicId]);
        if ($ok === false) {
            return false;
        }

        if ((int) $topic->topic_approved === 1) {
            $postCount = AP_Forum::countPosts($topicId, ['approved_only' => true], $db);
            self::adjustForumStats((int) $topic->forum_id, 1, $postCount, $db);
            self::refreshForumLastPost((int) $topic->forum_id, $db);
        }

        if (function_exists('ap_do_action')) {
            ap_do_action('ap_moderation_topic_restored', $topicId, $moderatorId);
        }

        return true;
    }

    /**
     * Permanently delete a topic and its posts.
     */
    public static function forceDeleteTopic(int $topicId, int $moderatorId = 0, ?AP_DB $db = null): bool
    {
        $db = self::resolveDb($db);
        $topic = AP_Forum::getTopic($topicId, $db);
        if ($topic === null) {
            return false;
        }
        if (!self::moderatorMayAct($moderatorId, (int) $topic->forum_id, $db)) {
            return false;
        }

        return AP_Forum::deleteTopic($topicId, true, $db);
    }

    /**
     * Set topic type (normal | sticky | announce | global).
     */
    public static function setTopicType(
        int $topicId,
        string $type,
        int $moderatorId = 0,
        ?AP_DB $db = null
    ): bool {
        $db = self::resolveDb($db);
        $topic = AP_Forum::getTopic($topicId, $db);
        if ($topic === null) {
            return false;
        }
        if (!self::moderatorMayAct($moderatorId, (int) $topic->forum_id, $db)) {
            return false;
        }

        return AP_Forum::updateTopic($topicId, ['topic_type' => $type], $db);
    }

    /**
     * Move a topic to another forum, adjusting counters on both sides.
     */
    public static function moveTopic(
        int $topicId,
        int $newForumId,
        int $moderatorId = 0,
        ?AP_DB $db = null
    ): bool {
        if ($topicId < 1 || $newForumId < 1) {
            return false;
        }

        $db = self::resolveDb($db);
        $topic = AP_Forum::getTopic($topicId, $db);
        if ($topic === null) {
            return false;
        }

        $oldForumId = (int) $topic->forum_id;
        if ($oldForumId === $newForumId) {
            return true;
        }

        $newForum = AP_Forum::getForum($newForumId, $db);
        if ($newForum === null || (string) $newForum->forum_type === AP_Forum::FORUM_TYPE_CATEGORY) {
            return false;
        }

        // Need moderate rights on source and destination.
        if (
            !self::moderatorMayAct($moderatorId, $oldForumId, $db)
            || !self::moderatorMayAct($moderatorId, $newForumId, $db)
        ) {
            return false;
        }

        $wasCounted = (int) $topic->topic_approved === 1
            && (string) $topic->topic_status !== AP_Forum::TOPIC_STATUS_DELETED;
        $postCount = $wasCounted
            ? AP_Forum::countPosts($topicId, ['approved_only' => true], $db)
            : 0;

        if (!AP_Forum::updateTopic($topicId, ['forum_id' => $newForumId], $db)) {
            return false;
        }

        if ($wasCounted) {
            self::adjustForumStats($oldForumId, -1, -$postCount, $db);
            self::adjustForumStats($newForumId, 1, $postCount, $db);
            self::refreshForumLastPost($oldForumId, $db);
            self::refreshForumLastPost($newForumId, $db);
        }

        if (function_exists('ap_do_action')) {
            ap_do_action('ap_moderation_topic_moved', $topicId, $oldForumId, $newForumId, $moderatorId);
        }

        return true;
    }

    /**
     * Merge source topic into target topic (all posts move; source is force-removed).
     *
     * @return bool True when merge completed.
     */
    public static function mergeTopics(
        int $sourceTopicId,
        int $targetTopicId,
        int $moderatorId = 0,
        ?AP_DB $db = null
    ): bool {
        if ($sourceTopicId < 1 || $targetTopicId < 1 || $sourceTopicId === $targetTopicId) {
            return false;
        }

        $db = self::resolveDb($db);
        $source = AP_Forum::getTopic($sourceTopicId, $db);
        $target = AP_Forum::getTopic($targetTopicId, $db);
        if ($source === null || $target === null) {
            return false;
        }

        $sourceForum = (int) $source->forum_id;
        $targetForum = (int) $target->forum_id;

        if (
            !self::moderatorMayAct($moderatorId, $sourceForum, $db)
            || !self::moderatorMayAct($moderatorId, $targetForum, $db)
        ) {
            return false;
        }

        $postsTable = $db->quoteIdentifier($db->table('forum_posts'));
        $sourceCounted = (int) $source->topic_approved === 1
            && (string) $source->topic_status !== AP_Forum::TOPIC_STATUS_DELETED;
        $sourceApprovedPosts = $sourceCounted
            ? AP_Forum::countPosts($sourceTopicId, ['approved_only' => true], $db)
            : 0;

        // Reassign all posts to the target topic/forum.
        $moved = $db->query(
            'UPDATE ' . $postsTable
            . ' SET ' . $db->quoteIdentifier('topic_id') . ' = ?,'
            . ' ' . $db->quoteIdentifier('forum_id') . ' = ?'
            . ' WHERE ' . $db->quoteIdentifier('topic_id') . ' = ?',
            [$targetTopicId, $targetForum, $sourceTopicId]
        );
        if ($moved === false) {
            return false;
        }

        // Keep first_post_id as the earliest approved post when target was empty-ish.
        self::recalculateTopicFromPosts($targetTopicId, $db);

        // Remove the empty source topic row (posts already moved — avoid double-decrement).
        $db->delete('topics', ['topic_id' => $sourceTopicId]);

        if ($sourceCounted) {
            // Source forum loses the topic (+ its posts if different forum).
            if ($sourceForum === $targetForum) {
                self::adjustForumStats($sourceForum, -1, 0, $db);
            } else {
                self::adjustForumStats($sourceForum, -1, -$sourceApprovedPosts, $db);
                // Target forum gains only the posts (topic already exists there).
                self::adjustForumStats($targetForum, 0, $sourceApprovedPosts, $db);
            }
            self::refreshForumLastPost($sourceForum, $db);
            self::refreshForumLastPost($targetForum, $db);
        }

        if (function_exists('ap_do_action')) {
            ap_do_action('ap_moderation_topics_merged', $sourceTopicId, $targetTopicId, $moderatorId);
        }

        return true;
    }

    /**
     * Split selected posts into a new topic.
     *
     * The earliest selected post becomes the new topic's first post. At least
     * one post must remain in the original topic.
     *
     * @param list<int>            $postIds
     * @param array<string, mixed> $args    Keys: title, forum_id, poster_id, moderator_id
     *
     * @return int New topic_id or 0 on failure.
     */
    public static function splitTopic(
        int $sourceTopicId,
        array $postIds,
        array $args = [],
        ?AP_DB $db = null
    ): int {
        if ($sourceTopicId < 1) {
            return 0;
        }

        $postIds = array_values(array_unique(array_filter(
            array_map(static fn ($id): int => (int) $id, $postIds),
            static fn (int $id): bool => $id > 0
        )));
        if ($postIds === []) {
            return 0;
        }

        $db = self::resolveDb($db);
        $source = AP_Forum::getTopic($sourceTopicId, $db);
        if ($source === null) {
            return 0;
        }

        $moderatorId = (int) ($args['moderator_id'] ?? 0);
        $sourceForum = (int) $source->forum_id;
        $newForumId = max(0, (int) ($args['forum_id'] ?? $sourceForum));
        if ($newForumId < 1) {
            $newForumId = $sourceForum;
        }

        $newForum = AP_Forum::getForum($newForumId, $db);
        if ($newForum === null || (string) $newForum->forum_type === AP_Forum::FORUM_TYPE_CATEGORY) {
            return 0;
        }

        if (
            !self::moderatorMayAct($moderatorId, $sourceForum, $db)
            || !self::moderatorMayAct($moderatorId, $newForumId, $db)
        ) {
            return 0;
        }

        // Load selected posts that belong to the source topic, ordered by time.
        $placeholders = implode(',', array_fill(0, count($postIds), '?'));
        $postsTable = $db->quoteIdentifier($db->table('forum_posts'));
        $rows = $db->getResults(
            'SELECT * FROM ' . $postsTable
            . ' WHERE ' . $db->quoteIdentifier('topic_id') . ' = ?'
            . ' AND ' . $db->quoteIdentifier('post_id') . ' IN (' . $placeholders . ')'
            . ' ORDER BY ' . $db->quoteIdentifier('post_time') . ' ASC, '
            . $db->quoteIdentifier('post_id') . ' ASC',
            array_merge([$sourceTopicId], $postIds)
        );
        if ($rows === [] || count($rows) !== count($postIds)) {
            return 0;
        }

        $totalInSource = AP_Forum::countPosts($sourceTopicId, ['approved_only' => false], $db);
        if ($totalInSource <= count($postIds)) {
            // Must leave at least one post in the original topic.
            return 0;
        }

        $firstPost = $rows[0];
        $title = trim((string) ($args['title'] ?? $args['topic_title'] ?? ''));
        if ($title === '') {
            $title = trim((string) ($firstPost->post_subject ?? ''));
        }
        if ($title === '') {
            $title = (string) $source->topic_title;
        }
        if (function_exists('mb_substr')) {
            $title = mb_substr($title, 0, 255);
        } else {
            $title = substr($title, 0, 255);
        }

        $posterId = max(0, (int) ($args['poster_id'] ?? $firstPost->poster_id ?? 0));
        $now = self::nowLocal();
        $slug = AP_Forum::sanitizeSlug($title);
        if ($slug === '') {
            $slug = 'topic';
        }
        $slug = AP_Forum::uniqueTopicSlug($slug, $newForumId, 0, $db);

        $topicRow = [
            'forum_id' => $newForumId,
            'topic_title' => $title,
            'topic_slug' => $slug,
            'topic_poster' => $posterId,
            'topic_status' => AP_Forum::TOPIC_STATUS_OPEN,
            'topic_type' => AP_Forum::TOPIC_TYPE_NORMAL,
            'topic_approved' => 1,
            'topic_views' => 0,
            'reply_count' => 0,
            'first_post_id' => 0,
            'last_post_id' => 0,
            'last_poster_id' => 0,
            'topic_time' => (string) ($firstPost->post_time ?? $now),
            'topic_modified' => $now,
            'topic_last_post_time' => (string) ($firstPost->post_time ?? $now),
        ];

        if ($db->insert('topics', $topicRow) === false) {
            return 0;
        }
        $newTopicId = (int) $db->lastInsertId();
        if ($newTopicId < 1) {
            return 0;
        }

        $movedIds = [];
        $approvedMoved = 0;
        foreach ($rows as $row) {
            $pid = (int) $row->post_id;
            $wasApproved = (int) ($row->post_approved ?? 0) === 1;
            $db->update('forum_posts', [
                'topic_id' => $newTopicId,
                'forum_id' => $newForumId,
            ], ['post_id' => $pid]);
            $movedIds[] = $pid;
            if ($wasApproved) {
                $approvedMoved++;
            }
        }

        self::recalculateTopicFromPosts($sourceTopicId, $db);
        self::recalculateTopicFromPosts($newTopicId, $db);

        // Forum counters: new topic +1; posts move if forum changed.
        if ($sourceForum === $newForumId) {
            self::adjustForumStats($sourceForum, 1, 0, $db);
        } else {
            self::adjustForumStats($sourceForum, 0, -$approvedMoved, $db);
            self::adjustForumStats($newForumId, 1, $approvedMoved, $db);
        }
        self::refreshForumLastPost($sourceForum, $db);
        self::refreshForumLastPost($newForumId, $db);

        if (function_exists('ap_do_action')) {
            ap_do_action('ap_moderation_topic_split', $sourceTopicId, $newTopicId, $movedIds, $moderatorId);
        }

        return $newTopicId;
    }

    // -------------------------------------------------------------------------
    // Post moderation
    // -------------------------------------------------------------------------

    /**
     * Soft-delete a forum post by unapproving it (hidden, retained).
     * First post of a topic soft-deletes the whole topic instead.
     */
    public static function softDeletePost(
        int $postId,
        int $moderatorId = 0,
        string $reason = '',
        ?AP_DB $db = null
    ): bool {
        if ($postId < 1) {
            return false;
        }

        $db = self::resolveDb($db);
        $post = AP_Forum::getPost($postId, $db);
        if ($post === null) {
            return false;
        }
        if (!self::moderatorMayAct($moderatorId, (int) $post->forum_id, $db)) {
            return false;
        }

        $topic = AP_Forum::getTopic((int) $post->topic_id, $db);
        if ($topic !== null && (int) $topic->first_post_id === $postId) {
            return self::softDeleteTopic((int) $topic->topic_id, $moderatorId, $db);
        }

        if ((int) $post->post_approved === 0) {
            return true;
        }

        $data = [
            'post_approved' => 0,
            'post_edit_user' => max(0, $moderatorId),
        ];
        if ($reason !== '') {
            $data['post_edit_reason'] = $reason;
        }

        $ok = AP_Forum::updatePost($postId, $data, $db);
        if ($ok && function_exists('ap_do_action')) {
            ap_do_action('ap_moderation_post_soft_deleted', $postId, $moderatorId);
        }

        return $ok;
    }

    /**
     * Restore a soft-deleted (unapproved) post.
     */
    public static function restorePost(int $postId, int $moderatorId = 0, ?AP_DB $db = null): bool
    {
        if ($postId < 1) {
            return false;
        }

        $db = self::resolveDb($db);
        $post = AP_Forum::getPost($postId, $db);
        if ($post === null) {
            return false;
        }
        if (!self::moderatorMayAct($moderatorId, (int) $post->forum_id, $db)) {
            return false;
        }
        if ((int) $post->post_approved === 1) {
            return true;
        }

        $ok = AP_Forum::updatePost($postId, [
            'post_approved' => 1,
            'post_edit_user' => max(0, $moderatorId),
            'post_edit_reason' => 'restored',
        ], $db);
        if ($ok && function_exists('ap_do_action')) {
            ap_do_action('ap_moderation_post_restored', $postId, $moderatorId);
        }

        return $ok;
    }

    /**
     * Approve a pending post.
     */
    public static function approvePost(int $postId, int $moderatorId = 0, ?AP_DB $db = null): bool
    {
        return self::restorePost($postId, $moderatorId, $db);
    }

    /**
     * Unapprove a post (hold for moderation) without treating it as soft-delete of the topic.
     */
    public static function unapprovePost(int $postId, int $moderatorId = 0, ?AP_DB $db = null): bool
    {
        if ($postId < 1) {
            return false;
        }

        $db = self::resolveDb($db);
        $post = AP_Forum::getPost($postId, $db);
        if ($post === null) {
            return false;
        }
        if (!self::moderatorMayAct($moderatorId, (int) $post->forum_id, $db)) {
            return false;
        }
        if ((int) $post->post_approved === 0) {
            return true;
        }

        $topic = AP_Forum::getTopic((int) $post->topic_id, $db);
        // First post unapprove → unapprove whole topic.
        if ($topic !== null && (int) $topic->first_post_id === $postId) {
            return self::unapproveTopic((int) $topic->topic_id, $moderatorId, $db);
        }

        $ok = AP_Forum::updatePost($postId, [
            'post_approved' => 0,
            'post_edit_user' => max(0, $moderatorId),
            'post_edit_reason' => 'unapproved',
        ], $db);
        if ($ok && function_exists('ap_do_action')) {
            ap_do_action('ap_moderation_post_unapproved', $postId, $moderatorId);
        }

        return $ok;
    }

    /**
     * Approve a pending topic (and its first post). Bumps forum stats when newly approved.
     */
    public static function approveTopic(int $topicId, int $moderatorId = 0, ?AP_DB $db = null): bool
    {
        if ($topicId < 1) {
            return false;
        }

        $db = self::resolveDb($db);
        $topic = AP_Forum::getTopic($topicId, $db);
        if ($topic === null) {
            return false;
        }
        if (!self::moderatorMayAct($moderatorId, (int) $topic->forum_id, $db)) {
            return false;
        }
        if ((string) $topic->topic_status === AP_Forum::TOPIC_STATUS_DELETED) {
            return false;
        }

        $wasApproved = (int) $topic->topic_approved === 1;
        if (!$wasApproved) {
            $ok = AP_Forum::updateTopic($topicId, ['topic_approved' => 1], $db);
            if (!$ok) {
                return false;
            }
        }

        $firstId = (int) $topic->first_post_id;
        if ($firstId > 0) {
            $first = AP_Forum::getPost($firstId, $db);
            if ($first !== null && (int) $first->post_approved !== 1) {
                // updatePost skips first-post counter path; set flag directly then bump if needed.
                $db->update('forum_posts', [
                    'post_approved' => 1,
                    'post_edit_user' => max(0, $moderatorId),
                    'post_edit_reason' => 'approved',
                ], ['post_id' => $firstId]);
            }
        }

        if (!$wasApproved) {
            // Recount after first-post approval so reply_count / last_post match.
            self::recalculateTopicFromPosts($topicId, $db);
            $postCount = AP_Forum::countPosts($topicId, ['approved_only' => true], $db);
            self::adjustForumStats((int) $topic->forum_id, 1, max(1, $postCount), $db);
            self::refreshForumLastPost((int) $topic->forum_id, $db);
        }

        if (function_exists('ap_do_action')) {
            ap_do_action('ap_moderation_topic_approved', $topicId, $moderatorId);
        }

        return true;
    }

    /**
     * Hold a topic for moderation (topic_approved = 0; first post unapproved).
     * Removes it from public lists and forum counters.
     */
    public static function unapproveTopic(int $topicId, int $moderatorId = 0, ?AP_DB $db = null): bool
    {
        if ($topicId < 1) {
            return false;
        }

        $db = self::resolveDb($db);
        $topic = AP_Forum::getTopic($topicId, $db);
        if ($topic === null) {
            return false;
        }
        if (!self::moderatorMayAct($moderatorId, (int) $topic->forum_id, $db)) {
            return false;
        }
        if ((string) $topic->topic_status === AP_Forum::TOPIC_STATUS_DELETED) {
            return false;
        }

        $wasApproved = (int) $topic->topic_approved === 1;
        if ($wasApproved) {
            $postCount = AP_Forum::countPosts($topicId, ['approved_only' => true], $db);
            $ok = AP_Forum::updateTopic($topicId, ['topic_approved' => 0], $db);
            if (!$ok) {
                return false;
            }
            self::adjustForumStats((int) $topic->forum_id, -1, -$postCount, $db);
            self::refreshForumLastPost((int) $topic->forum_id, $db);
        } elseif ((int) $topic->topic_approved === 0) {
            // already pending
        }

        $firstId = (int) $topic->first_post_id;
        if ($firstId > 0) {
            $first = AP_Forum::getPost($firstId, $db);
            if ($first !== null && (int) $first->post_approved === 1) {
                $db->update('forum_posts', [
                    'post_approved' => 0,
                    'post_edit_user' => max(0, $moderatorId),
                    'post_edit_reason' => 'unapproved',
                ], ['post_id' => $firstId]);
            }
        }

        if (function_exists('ap_do_action')) {
            ap_do_action('ap_moderation_topic_unapproved', $topicId, $moderatorId);
        }

        return true;
    }

    /**
     * Edit post content as a moderator (records edit_user / reason).
     *
     * @param array<string, mixed> $data content, subject, edit_reason
     */
    public static function editPost(
        int $postId,
        array $data,
        int $moderatorId = 0,
        ?AP_DB $db = null
    ): bool {
        if ($postId < 1) {
            return false;
        }

        $db = self::resolveDb($db);
        $post = AP_Forum::getPost($postId, $db);
        if ($post === null) {
            return false;
        }
        if (!self::moderatorMayAct($moderatorId, (int) $post->forum_id, $db)) {
            return false;
        }

        if (
            $moderatorId > 0 && !array_key_exists('post_edit_user', $data)
            && !array_key_exists('edit_user', $data)
        ) {
            $data['post_edit_user'] = $moderatorId;
        }

        return AP_Forum::updatePost($postId, $data, $db);
    }

    // -------------------------------------------------------------------------
    // Reports
    // -------------------------------------------------------------------------

    /**
     * @return list<string>
     */
    public static function reportTypes(): array
    {
        return [
            self::REPORT_TYPE_POST,
            self::REPORT_TYPE_TOPIC,
            self::REPORT_TYPE_USER,
            self::REPORT_TYPE_MESSAGE,
        ];
    }

    /**
     * @return list<string>
     */
    public static function reportStatuses(): array
    {
        return [
            self::REPORT_STATUS_OPEN,
            self::REPORT_STATUS_CLOSED,
            self::REPORT_STATUS_DISMISSED,
        ];
    }

    public static function normalizeReportType(string $type): string
    {
        $type = self::sanitizeKey($type);

        return in_array($type, self::reportTypes(), true) ? $type : self::REPORT_TYPE_POST;
    }

    public static function normalizeReportStatus(string $status): string
    {
        $status = self::sanitizeKey($status);

        return in_array($status, self::reportStatuses(), true) ? $status : self::REPORT_STATUS_OPEN;
    }

    /**
     * File a moderation report.
     *
     * @param array<string, mixed> $data Keys: reporter_id, report_type, report_object_id,
     *                                   report_reason, report_details
     *
     * @return int New report_id or 0.
     */
    public static function createReport(array $data, ?AP_DB $db = null): int
    {
        $db = self::resolveDb($db);

        $type = self::normalizeReportType((string) ($data['report_type'] ?? $data['type'] ?? self::REPORT_TYPE_POST));
        $objectId = max(0, (int) ($data['report_object_id'] ?? $data['object_id'] ?? 0));
        $reporterId = max(0, (int) ($data['reporter_id'] ?? 0));
        $reason = trim((string) ($data['report_reason'] ?? $data['reason'] ?? ''));
        $details = (string) ($data['report_details'] ?? $data['details'] ?? '');

        if ($objectId < 1) {
            return 0;
        }
        if (function_exists('mb_substr')) {
            $reason = mb_substr($reason, 0, 255);
        } else {
            $reason = substr($reason, 0, 255);
        }

        $now = self::nowLocal();
        $row = [
            'reporter_id' => $reporterId,
            'report_type' => $type,
            'report_object_id' => $objectId,
            'report_reason' => $reason,
            'report_details' => $details,
            'report_status' => self::REPORT_STATUS_OPEN,
            'reported_at' => $now,
            'resolved_at' => null,
            'resolved_by' => 0,
        ];

        if ($db->insert('reports', $row) === false) {
            return 0;
        }
        $id = (int) $db->lastInsertId();
        if ($id < 1) {
            return 0;
        }

        if ($type === self::REPORT_TYPE_POST) {
            $db->update('forum_posts', ['post_reported' => 1], ['post_id' => $objectId]);
        }

        if (function_exists('ap_do_action')) {
            ap_do_action('ap_report_created', $id, self::getReport($id, $db));
        }

        return $id;
    }

    public static function getReport(int $id, ?AP_DB $db = null): ?object
    {
        if ($id < 1) {
            return null;
        }

        $db = self::resolveDb($db);
        $table = $db->quoteIdentifier($db->table('reports'));
        $row = $db->getRow(
            'SELECT * FROM ' . $table . ' WHERE ' . $db->quoteIdentifier('report_id') . ' = ?',
            [$id]
        );

        return $row === null ? null : self::normalizeReportRow($row);
    }

    /**
     * @param array<string, mixed> $args Keys: status, type, object_id, reporter_id,
     *                                   per_page, page, order (ASC|DESC)
     *
     * @return list<object>
     */
    public static function queryReports(array $args = [], ?AP_DB $db = null): array
    {
        $db = self::resolveDb($db);
        $table = $db->quoteIdentifier($db->table('reports'));
        $sql = 'SELECT * FROM ' . $table . ' WHERE 1=1';
        $params = [];

        if (isset($args['status']) && (string) $args['status'] !== '') {
            $sql .= ' AND ' . $db->quoteIdentifier('report_status') . ' = ?';
            $params[] = self::normalizeReportStatus((string) $args['status']);
        }
        if (isset($args['type']) && (string) $args['type'] !== '') {
            $sql .= ' AND ' . $db->quoteIdentifier('report_type') . ' = ?';
            $params[] = self::normalizeReportType((string) $args['type']);
        }
        if (isset($args['object_id'])) {
            $sql .= ' AND ' . $db->quoteIdentifier('report_object_id') . ' = ?';
            $params[] = (int) $args['object_id'];
        }
        if (isset($args['reporter_id'])) {
            $sql .= ' AND ' . $db->quoteIdentifier('reporter_id') . ' = ?';
            $params[] = (int) $args['reporter_id'];
        }

        $order = strtoupper((string) ($args['order'] ?? 'DESC'));
        if ($order !== 'ASC') {
            $order = 'DESC';
        }
        $sql .= ' ORDER BY ' . $db->quoteIdentifier('reported_at') . ' ' . $order . ', '
            . $db->quoteIdentifier('report_id') . ' ' . $order;

        $perPage = max(0, (int) ($args['per_page'] ?? 0));
        $page = max(1, (int) ($args['page'] ?? 1));
        if ($perPage > 0) {
            $offset = ($page - 1) * $perPage;
            $sql .= ' LIMIT ' . $perPage . ' OFFSET ' . $offset;
        }

        $out = [];
        foreach ($db->getResults($sql, $params) as $row) {
            $out[] = self::normalizeReportRow($row);
        }

        return $out;
    }

    /**
     * Count reports matching filters.
     *
     * @param array<string, mixed> $args status, type
     */
    public static function countReports(array $args = [], ?AP_DB $db = null): int
    {
        $db = self::resolveDb($db);
        $table = $db->quoteIdentifier($db->table('reports'));
        $sql = 'SELECT COUNT(*) FROM ' . $table . ' WHERE 1=1';
        $params = [];

        if (isset($args['status']) && (string) $args['status'] !== '') {
            $sql .= ' AND ' . $db->quoteIdentifier('report_status') . ' = ?';
            $params[] = self::normalizeReportStatus((string) $args['status']);
        }
        if (isset($args['type']) && (string) $args['type'] !== '') {
            $sql .= ' AND ' . $db->quoteIdentifier('report_type') . ' = ?';
            $params[] = self::normalizeReportType((string) $args['type']);
        }

        return (int) $db->getVar($sql, $params);
    }

    /**
     * Resolve (close) a report.
     */
    public static function resolveReport(int $reportId, int $resolvedBy = 0, ?AP_DB $db = null): bool
    {
        return self::setReportStatus($reportId, self::REPORT_STATUS_CLOSED, $resolvedBy, $db);
    }

    /**
     * Dismiss a report without action.
     */
    public static function dismissReport(int $reportId, int $resolvedBy = 0, ?AP_DB $db = null): bool
    {
        return self::setReportStatus($reportId, self::REPORT_STATUS_DISMISSED, $resolvedBy, $db);
    }

    /**
     * Re-open a closed/dismissed report.
     */
    public static function reopenReport(int $reportId, ?AP_DB $db = null): bool
    {
        if ($reportId < 1) {
            return false;
        }

        $db = self::resolveDb($db);
        $report = self::getReport($reportId, $db);
        if ($report === null) {
            return false;
        }

        $ok = $db->update('reports', [
            'report_status' => self::REPORT_STATUS_OPEN,
            'resolved_at' => null,
            'resolved_by' => 0,
        ], ['report_id' => $reportId]);

        return $ok !== false;
    }

    // -------------------------------------------------------------------------
    // Warnings
    // -------------------------------------------------------------------------

    /**
     * @return list<string>
     */
    public static function warningStatuses(): array
    {
        return [
            self::WARNING_STATUS_ACTIVE,
            self::WARNING_STATUS_EXPIRED,
            self::WARNING_STATUS_REVOKED,
        ];
    }

    public static function normalizeWarningStatus(string $status): string
    {
        $status = self::sanitizeKey($status);

        return in_array($status, self::warningStatuses(), true)
            ? $status
            : self::WARNING_STATUS_ACTIVE;
    }

    /**
     * Issue a warning to a user.
     *
     * @param array<string, mixed> $data Keys: user_id, issuer_id, warning_reason,
     *                                   warning_notes, related_type, related_id, expires_at
     *
     * @return int warning_id or 0.
     */
    public static function issueWarning(array $data, ?AP_DB $db = null): int
    {
        $db = self::resolveDb($db);

        $userId = max(0, (int) ($data['user_id'] ?? 0));
        if ($userId < 1) {
            return 0;
        }

        $issuerId = max(0, (int) ($data['issuer_id'] ?? 0));
        $reason = trim((string) ($data['warning_reason'] ?? $data['reason'] ?? ''));
        $notes = (string) ($data['warning_notes'] ?? $data['notes'] ?? '');
        $relatedType = self::sanitizeKey((string) ($data['related_type'] ?? ''));
        $relatedId = max(0, (int) ($data['related_id'] ?? 0));
        $expiresAt = $data['expires_at'] ?? null;
        if ($expiresAt !== null && $expiresAt !== '') {
            $expiresAt = (string) $expiresAt;
        } else {
            $expiresAt = null;
        }

        if (function_exists('mb_substr')) {
            $reason = mb_substr($reason, 0, 255);
        } else {
            $reason = substr($reason, 0, 255);
        }

        $row = [
            'user_id' => $userId,
            'issuer_id' => $issuerId,
            'warning_reason' => $reason,
            'warning_notes' => $notes,
            'related_type' => $relatedType,
            'related_id' => $relatedId,
            'warning_status' => self::WARNING_STATUS_ACTIVE,
            'warned_at' => self::nowLocal(),
            'expires_at' => $expiresAt,
            'revoked_at' => null,
            'revoked_by' => 0,
        ];

        if ($db->insert('warnings', $row) === false) {
            return 0;
        }
        $id = (int) $db->lastInsertId();
        if ($id < 1) {
            return 0;
        }

        if (function_exists('ap_do_action')) {
            ap_do_action('ap_warning_issued', $id, self::getWarning($id, $db));
        }

        return $id;
    }

    public static function getWarning(int $id, ?AP_DB $db = null): ?object
    {
        if ($id < 1) {
            return null;
        }

        $db = self::resolveDb($db);
        $table = $db->quoteIdentifier($db->table('warnings'));
        $row = $db->getRow(
            'SELECT * FROM ' . $table . ' WHERE ' . $db->quoteIdentifier('warning_id') . ' = ?',
            [$id]
        );

        return $row === null ? null : self::normalizeWarningRow($row);
    }

    /**
     * @param array<string, mixed> $args Keys: status, issuer_id, per_page, page
     *
     * @return list<object>
     */
    public static function getUserWarnings(int $userId, array $args = [], ?AP_DB $db = null): array
    {
        if ($userId < 1) {
            return [];
        }

        $db = self::resolveDb($db);
        $table = $db->quoteIdentifier($db->table('warnings'));
        $sql = 'SELECT * FROM ' . $table . ' WHERE ' . $db->quoteIdentifier('user_id') . ' = ?';
        $params = [$userId];

        if (isset($args['status']) && (string) $args['status'] !== '') {
            $sql .= ' AND ' . $db->quoteIdentifier('warning_status') . ' = ?';
            $params[] = self::normalizeWarningStatus((string) $args['status']);
        }

        $sql .= ' ORDER BY ' . $db->quoteIdentifier('warned_at') . ' DESC, '
            . $db->quoteIdentifier('warning_id') . ' DESC';

        $perPage = max(0, (int) ($args['per_page'] ?? 0));
        $page = max(1, (int) ($args['page'] ?? 1));
        if ($perPage > 0) {
            $offset = ($page - 1) * $perPage;
            $sql .= ' LIMIT ' . $perPage . ' OFFSET ' . $offset;
        }

        $out = [];
        foreach ($db->getResults($sql, $params) as $row) {
            $out[] = self::normalizeWarningRow($row);
        }

        return $out;
    }

    public static function countUserWarnings(
        int $userId,
        string $status = self::WARNING_STATUS_ACTIVE,
        ?AP_DB $db = null
    ): int {
        if ($userId < 1) {
            return 0;
        }

        $db = self::resolveDb($db);
        $table = $db->quoteIdentifier($db->table('warnings'));
        $sql = 'SELECT COUNT(*) FROM ' . $table
            . ' WHERE ' . $db->quoteIdentifier('user_id') . ' = ?';
        $params = [$userId];
        if ($status !== '') {
            $sql .= ' AND ' . $db->quoteIdentifier('warning_status') . ' = ?';
            $params[] = self::normalizeWarningStatus($status);
        }

        return (int) $db->getVar($sql, $params);
    }

    /**
     * Revoke an active warning.
     */
    public static function revokeWarning(int $warningId, int $revokedBy = 0, ?AP_DB $db = null): bool
    {
        if ($warningId < 1) {
            return false;
        }

        $db = self::resolveDb($db);
        $warning = self::getWarning($warningId, $db);
        if ($warning === null) {
            return false;
        }
        if ((string) $warning->warning_status === self::WARNING_STATUS_REVOKED) {
            return true;
        }

        $ok = $db->update('warnings', [
            'warning_status' => self::WARNING_STATUS_REVOKED,
            'revoked_at' => self::nowLocal(),
            'revoked_by' => max(0, $revokedBy),
        ], ['warning_id' => $warningId]);

        if ($ok !== false && function_exists('ap_do_action')) {
            ap_do_action('ap_warning_revoked', $warningId, $revokedBy);
        }

        return $ok !== false;
    }

    // -------------------------------------------------------------------------
    // Bans / suspensions
    // -------------------------------------------------------------------------

    /**
     * @return list<string>
     */
    public static function banTypes(): array
    {
        return [
            self::BAN_TYPE_USER,
            self::BAN_TYPE_IP,
            self::BAN_TYPE_EMAIL,
        ];
    }

    /**
     * @return list<string>
     */
    public static function banStatuses(): array
    {
        return [
            self::BAN_STATUS_ACTIVE,
            self::BAN_STATUS_EXPIRED,
            self::BAN_STATUS_LIFTED,
        ];
    }

    public static function normalizeBanType(string $type): string
    {
        $type = self::sanitizeKey($type);

        return in_array($type, self::banTypes(), true) ? $type : self::BAN_TYPE_USER;
    }

    public static function normalizeBanStatus(string $status): string
    {
        $status = self::sanitizeKey($status);

        return in_array($status, self::banStatuses(), true) ? $status : self::BAN_STATUS_ACTIVE;
    }

    /**
     * Ban or suspend a user account.
     *
     * Permanent ban when expires_at is empty/null. Temporary suspension when set.
     * Also sets users.user_status so login is blocked while active.
     *
     * @param array<string, mixed> $data Keys: reason, notes, banned_by, expires_at
     *
     * @return int ban_id or 0.
     */
    public static function banUser(int $userId, array $data = [], ?AP_DB $db = null): int
    {
        if ($userId < 1) {
            return 0;
        }

        $db = self::resolveDb($db);

        // Lift any existing active user bans first (single active ban row).
        self::liftActiveUserBans($userId, (int) ($data['banned_by'] ?? 0), $db);

        $reason = trim((string) ($data['ban_reason'] ?? $data['reason'] ?? ''));
        $notes = (string) ($data['ban_notes'] ?? $data['notes'] ?? '');
        $bannedBy = max(0, (int) ($data['banned_by'] ?? 0));
        $expiresAt = $data['expires_at'] ?? null;
        if ($expiresAt !== null && $expiresAt !== '') {
            $expiresAt = (string) $expiresAt;
        } else {
            $expiresAt = null;
        }

        if (function_exists('mb_substr')) {
            $reason = mb_substr($reason, 0, 255);
        } else {
            $reason = substr($reason, 0, 255);
        }

        $row = [
            'ban_type' => self::BAN_TYPE_USER,
            'ban_value' => (string) $userId,
            'user_id' => $userId,
            'ban_reason' => $reason,
            'ban_notes' => $notes,
            'banned_by' => $bannedBy,
            'ban_status' => self::BAN_STATUS_ACTIVE,
            'banned_at' => self::nowLocal(),
            'expires_at' => $expiresAt,
            'lifted_at' => null,
            'lifted_by' => 0,
        ];

        if ($db->insert('bans', $row) === false) {
            return 0;
        }
        $banId = (int) $db->lastInsertId();
        if ($banId < 1) {
            return 0;
        }

        self::setUserBannedStatus($userId, true, $db);

        if (function_exists('ap_do_action')) {
            ap_do_action('ap_user_banned', $userId, $banId, $expiresAt);
        }

        return $banId;
    }

    /**
     * Suspend a user until $expiresAt (alias of temporary ban).
     */
    public static function suspendUser(
        int $userId,
        string $expiresAt,
        array $data = [],
        ?AP_DB $db = null
    ): int {
        $data['expires_at'] = $expiresAt;

        return self::banUser($userId, $data, $db);
    }

    /**
     * Ban by IP address.
     *
     * @param array<string, mixed> $data
     */
    public static function banIp(string $ip, array $data = [], ?AP_DB $db = null): int
    {
        $ip = trim($ip);
        if ($ip === '') {
            return 0;
        }

        return self::createBan(self::BAN_TYPE_IP, $ip, 0, $data, $db);
    }

    /**
     * Ban by email address.
     *
     * @param array<string, mixed> $data
     */
    public static function banEmail(string $email, array $data = [], ?AP_DB $db = null): int
    {
        $email = strtolower(trim($email));
        if ($email === '' || !str_contains($email, '@')) {
            return 0;
        }

        return self::createBan(self::BAN_TYPE_EMAIL, $email, 0, $data, $db);
    }

    public static function getBan(int $banId, ?AP_DB $db = null): ?object
    {
        if ($banId < 1) {
            return null;
        }

        $db = self::resolveDb($db);
        $table = $db->quoteIdentifier($db->table('bans'));
        $row = $db->getRow(
            'SELECT * FROM ' . $table . ' WHERE ' . $db->quoteIdentifier('ban_id') . ' = ?',
            [$banId]
        );

        return $row === null ? null : self::normalizeBanRow($row);
    }

    /**
     * Active ban for a user (if any). Auto-expires past-due rows.
     */
    public static function getActiveUserBan(int $userId, ?AP_DB $db = null): ?object
    {
        if ($userId < 1) {
            return null;
        }

        $db = self::resolveDb($db);
        self::expireDueBans($userId, $db);

        $table = $db->quoteIdentifier($db->table('bans'));
        $row = $db->getRow(
            'SELECT * FROM ' . $table
            . ' WHERE ' . $db->quoteIdentifier('user_id') . ' = ?'
            . ' AND ' . $db->quoteIdentifier('ban_type') . ' = ?'
            . ' AND ' . $db->quoteIdentifier('ban_status') . ' = ?'
            . ' ORDER BY ' . $db->quoteIdentifier('ban_id') . ' DESC LIMIT 1',
            [$userId, self::BAN_TYPE_USER, self::BAN_STATUS_ACTIVE]
        );

        return $row === null ? null : self::normalizeBanRow($row);
    }

    /**
     * Whether a user currently has an active ban/suspension.
     */
    public static function isUserBanned(int $userId, ?AP_DB $db = null): bool
    {
        return self::getActiveUserBan($userId, $db) !== null;
    }

    /**
     * Whether an IP is banned.
     */
    public static function isIpBanned(string $ip, ?AP_DB $db = null): bool
    {
        $ip = trim($ip);
        if ($ip === '') {
            return false;
        }

        $db = self::resolveDb($db);
        self::expireDueBans(0, $db, self::BAN_TYPE_IP, $ip);

        $table = $db->quoteIdentifier($db->table('bans'));
        $id = $db->getVar(
            'SELECT ' . $db->quoteIdentifier('ban_id') . ' FROM ' . $table
            . ' WHERE ' . $db->quoteIdentifier('ban_type') . ' = ?'
            . ' AND ' . $db->quoteIdentifier('ban_value') . ' = ?'
            . ' AND ' . $db->quoteIdentifier('ban_status') . ' = ?'
            . ' LIMIT 1',
            [self::BAN_TYPE_IP, $ip, self::BAN_STATUS_ACTIVE]
        );

        return $id !== null && (int) $id > 0;
    }

    /**
     * Lift a specific ban (or all active bans for a user when ban_id is 0).
     */
    public static function liftBan(int $banId, int $liftedBy = 0, ?AP_DB $db = null): bool
    {
        if ($banId < 1) {
            return false;
        }

        $db = self::resolveDb($db);
        $ban = self::getBan($banId, $db);
        if ($ban === null) {
            return false;
        }
        if ((string) $ban->ban_status !== self::BAN_STATUS_ACTIVE) {
            return true;
        }

        $ok = $db->update('bans', [
            'ban_status' => self::BAN_STATUS_LIFTED,
            'lifted_at' => self::nowLocal(),
            'lifted_by' => max(0, $liftedBy),
        ], ['ban_id' => $banId]);
        if ($ok === false) {
            return false;
        }

        if ((string) $ban->ban_type === self::BAN_TYPE_USER && (int) $ban->user_id > 0) {
            if (!self::isUserBanned((int) $ban->user_id, $db)) {
                self::setUserBannedStatus((int) $ban->user_id, false, $db);
            }
        }

        if (function_exists('ap_do_action')) {
            ap_do_action('ap_ban_lifted', $banId, $liftedBy);
        }

        return true;
    }

    /**
     * Unban a user (lift all active user bans).
     */
    public static function unbanUser(int $userId, int $liftedBy = 0, ?AP_DB $db = null): bool
    {
        if ($userId < 1) {
            return false;
        }

        $db = self::resolveDb($db);
        self::liftActiveUserBans($userId, $liftedBy, $db);
        self::setUserBannedStatus($userId, false, $db);

        if (function_exists('ap_do_action')) {
            ap_do_action('ap_user_unbanned', $userId, $liftedBy);
        }

        return true;
    }

    /**
     * List bans.
     *
     * @param array<string, mixed> $args Keys: status, type, user_id, per_page, page
     *
     * @return list<object>
     */
    public static function queryBans(array $args = [], ?AP_DB $db = null): array
    {
        $db = self::resolveDb($db);
        $table = $db->quoteIdentifier($db->table('bans'));
        $sql = 'SELECT * FROM ' . $table . ' WHERE 1=1';
        $params = [];

        if (isset($args['status']) && (string) $args['status'] !== '') {
            $sql .= ' AND ' . $db->quoteIdentifier('ban_status') . ' = ?';
            $params[] = self::normalizeBanStatus((string) $args['status']);
        }
        if (isset($args['type']) && (string) $args['type'] !== '') {
            $sql .= ' AND ' . $db->quoteIdentifier('ban_type') . ' = ?';
            $params[] = self::normalizeBanType((string) $args['type']);
        }
        if (isset($args['user_id'])) {
            $sql .= ' AND ' . $db->quoteIdentifier('user_id') . ' = ?';
            $params[] = (int) $args['user_id'];
        }

        $sql .= ' ORDER BY ' . $db->quoteIdentifier('banned_at') . ' DESC, '
            . $db->quoteIdentifier('ban_id') . ' DESC';

        $perPage = max(0, (int) ($args['per_page'] ?? 0));
        $page = max(1, (int) ($args['page'] ?? 1));
        if ($perPage > 0) {
            $offset = ($page - 1) * $perPage;
            $sql .= ' LIMIT ' . $perPage . ' OFFSET ' . $offset;
        }

        $out = [];
        foreach ($db->getResults($sql, $params) as $row) {
            $out[] = self::normalizeBanRow($row);
        }

        return $out;
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    private static function setTopicStatus(
        int $topicId,
        string $status,
        int $moderatorId,
        ?AP_DB $db
    ): bool {
        if ($topicId < 1) {
            return false;
        }

        $db = self::resolveDb($db);
        $topic = AP_Forum::getTopic($topicId, $db);
        if ($topic === null) {
            return false;
        }
        if (!self::moderatorMayAct($moderatorId, (int) $topic->forum_id, $db)) {
            return false;
        }

        return AP_Forum::updateTopic($topicId, ['topic_status' => $status], $db);
    }

    private static function setReportStatus(
        int $reportId,
        string $status,
        int $resolvedBy,
        ?AP_DB $db
    ): bool {
        if ($reportId < 1) {
            return false;
        }

        $db = self::resolveDb($db);
        $report = self::getReport($reportId, $db);
        if ($report === null) {
            return false;
        }

        $ok = $db->update('reports', [
            'report_status' => self::normalizeReportStatus($status),
            'resolved_at' => self::nowLocal(),
            'resolved_by' => max(0, $resolvedBy),
        ], ['report_id' => $reportId]);

        if ($ok !== false && function_exists('ap_do_action')) {
            ap_do_action('ap_report_status_changed', $reportId, $status, $resolvedBy);
        }

        return $ok !== false;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function createBan(
        string $type,
        string $value,
        int $userId,
        array $data,
        ?AP_DB $db
    ): int {
        $db = self::resolveDb($db);
        $type = self::normalizeBanType($type);
        $reason = trim((string) ($data['ban_reason'] ?? $data['reason'] ?? ''));
        $notes = (string) ($data['ban_notes'] ?? $data['notes'] ?? '');
        $bannedBy = max(0, (int) ($data['banned_by'] ?? 0));
        $expiresAt = $data['expires_at'] ?? null;
        if ($expiresAt !== null && $expiresAt !== '') {
            $expiresAt = (string) $expiresAt;
        } else {
            $expiresAt = null;
        }

        if (function_exists('mb_substr')) {
            $reason = mb_substr($reason, 0, 255);
            $value = mb_substr($value, 0, 255);
        } else {
            $reason = substr($reason, 0, 255);
            $value = substr($value, 0, 255);
        }

        $row = [
            'ban_type' => $type,
            'ban_value' => $value,
            'user_id' => max(0, $userId),
            'ban_reason' => $reason,
            'ban_notes' => $notes,
            'banned_by' => $bannedBy,
            'ban_status' => self::BAN_STATUS_ACTIVE,
            'banned_at' => self::nowLocal(),
            'expires_at' => $expiresAt,
            'lifted_at' => null,
            'lifted_by' => 0,
        ];

        if ($db->insert('bans', $row) === false) {
            return 0;
        }

        return (int) $db->lastInsertId();
    }

    private static function liftActiveUserBans(int $userId, int $liftedBy, AP_DB $db): void
    {
        $table = $db->quoteIdentifier($db->table('bans'));
        $rows = $db->getResults(
            'SELECT ' . $db->quoteIdentifier('ban_id') . ' FROM ' . $table
            . ' WHERE ' . $db->quoteIdentifier('user_id') . ' = ?'
            . ' AND ' . $db->quoteIdentifier('ban_type') . ' = ?'
            . ' AND ' . $db->quoteIdentifier('ban_status') . ' = ?',
            [$userId, self::BAN_TYPE_USER, self::BAN_STATUS_ACTIVE]
        );
        $now = self::nowLocal();
        foreach ($rows as $row) {
            $db->update('bans', [
                'ban_status' => self::BAN_STATUS_LIFTED,
                'lifted_at' => $now,
                'lifted_by' => max(0, $liftedBy),
            ], ['ban_id' => (int) $row->ban_id]);
        }
    }

    /**
     * Mark past-due active bans as expired.
     */
    private static function expireDueBans(
        int $userId = 0,
        ?AP_DB $db = null,
        string $type = '',
        string $value = ''
    ): void {
        $db = self::resolveDb($db);
        $table = $db->quoteIdentifier($db->table('bans'));
        $now = self::nowLocal();

        $sql = 'SELECT * FROM ' . $table
            . ' WHERE ' . $db->quoteIdentifier('ban_status') . ' = ?'
            . ' AND ' . $db->quoteIdentifier('expires_at') . ' IS NOT NULL'
            . ' AND ' . $db->quoteIdentifier('expires_at') . ' != \'\''
            . ' AND ' . $db->quoteIdentifier('expires_at') . ' <= ?';
        $params = [self::BAN_STATUS_ACTIVE, $now];

        if ($userId > 0) {
            $sql .= ' AND ' . $db->quoteIdentifier('user_id') . ' = ?';
            $params[] = $userId;
        }
        if ($type !== '') {
            $sql .= ' AND ' . $db->quoteIdentifier('ban_type') . ' = ?';
            $params[] = self::normalizeBanType($type);
        }
        if ($value !== '') {
            $sql .= ' AND ' . $db->quoteIdentifier('ban_value') . ' = ?';
            $params[] = $value;
        }

        foreach ($db->getResults($sql, $params) as $row) {
            $db->update('bans', [
                'ban_status' => self::BAN_STATUS_EXPIRED,
            ], ['ban_id' => (int) $row->ban_id]);

            if (
                (string) ($row->ban_type ?? '') === self::BAN_TYPE_USER
                && (int) ($row->user_id ?? 0) > 0
            ) {
                // Only clear account flag when no other active ban remains.
                $still = $db->getVar(
                    'SELECT ' . $db->quoteIdentifier('ban_id') . ' FROM ' . $table
                    . ' WHERE ' . $db->quoteIdentifier('user_id') . ' = ?'
                    . ' AND ' . $db->quoteIdentifier('ban_status') . ' = ?'
                    . ' AND ' . $db->quoteIdentifier('ban_id') . ' != ?'
                    . ' LIMIT 1',
                    [(int) $row->user_id, self::BAN_STATUS_ACTIVE, (int) $row->ban_id]
                );
                if ($still === null || (int) $still < 1) {
                    self::setUserBannedStatus((int) $row->user_id, false, $db);
                }
            }
        }
    }

    /**
     * Toggle users.user_status for login blocking when AP_User is available.
     */
    private static function setUserBannedStatus(int $userId, bool $banned, AP_DB $db): void
    {
        if ($userId < 1 || !class_exists('AP_User', false)) {
            // Fallback: direct update when user model not loaded.
            $db->update('users', [
                'user_status' => $banned ? self::USER_STATUS_BANNED : 0,
            ], ['ID' => $userId]);

            return;
        }

        AP_User::update($userId, [
            'user_status' => $banned ? self::USER_STATUS_BANNED : 0,
        ], $db);
    }

    /**
     * Rebuild topic counters / first / last from its posts.
     */
    private static function recalculateTopicFromPosts(int $topicId, AP_DB $db): void
    {
        $topic = AP_Forum::getTopic($topicId, $db);
        if ($topic === null) {
            return;
        }

        $postsTable = $db->quoteIdentifier($db->table('forum_posts'));

        $first = $db->getRow(
            'SELECT * FROM ' . $postsTable
            . ' WHERE ' . $db->quoteIdentifier('topic_id') . ' = ?'
            . ' ORDER BY ' . $db->quoteIdentifier('post_time') . ' ASC, '
            . $db->quoteIdentifier('post_id') . ' ASC LIMIT 1',
            [$topicId]
        );
        $last = $db->getRow(
            'SELECT * FROM ' . $postsTable
            . ' WHERE ' . $db->quoteIdentifier('topic_id') . ' = ?'
            . ' AND ' . $db->quoteIdentifier('post_approved') . ' = 1'
            . ' ORDER BY ' . $db->quoteIdentifier('post_time') . ' DESC, '
            . $db->quoteIdentifier('post_id') . ' DESC LIMIT 1',
            [$topicId]
        );
        $approvedCount = (int) $db->getVar(
            'SELECT COUNT(*) FROM ' . $postsTable
            . ' WHERE ' . $db->quoteIdentifier('topic_id') . ' = ?'
            . ' AND ' . $db->quoteIdentifier('post_approved') . ' = 1',
            [$topicId]
        );

        $firstCountsAsOp = $first !== null && (int) ($first->post_approved ?? 0) === 1;
        $update = [
            'reply_count' => max(0, $approvedCount - ($firstCountsAsOp ? 1 : 0)),
            'topic_modified' => self::nowLocal(),
        ];

        if ($first !== null) {
            $update['first_post_id'] = (int) $first->post_id;
            $update['topic_poster'] = (int) $first->poster_id;
            $update['topic_time'] = (string) $first->post_time;
            // Align first post subject with topic title when empty.
            if (
                trim((string) ($first->post_subject ?? '')) === ''
                && trim((string) $topic->topic_title) !== ''
            ) {
                $db->update('forum_posts', [
                    'post_subject' => (string) $topic->topic_title,
                ], ['post_id' => (int) $first->post_id]);
            }
        } else {
            $update['first_post_id'] = 0;
        }

        if ($last !== null) {
            $update['last_post_id'] = (int) $last->post_id;
            $update['last_poster_id'] = (int) $last->poster_id;
            $update['topic_last_post_time'] = (string) $last->post_time;
        } else {
            $update['last_post_id'] = 0;
            $update['last_poster_id'] = 0;
            $update['topic_last_post_time'] = self::EMPTY_DATETIME;
        }

        $db->update('topics', $update, ['topic_id' => $topicId]);
    }

    /**
     * Moderator gate: moderator_id 0 skips check (system / tests).
     */
    private static function moderatorMayAct(int $moderatorId, int $forumId, AP_DB $db): bool
    {
        if ($moderatorId < 1) {
            return true;
        }
        if ($forumId < 1) {
            return false;
        }
        if (!class_exists('AP_Forum_Permissions', false)) {
            return true;
        }

        return AP_Forum_Permissions::userCanModerate($moderatorId, $forumId, $db);
    }

    private static function adjustForumStats(int $forumId, int $topicDelta, int $postDelta, AP_DB $db): void
    {
        $forum = AP_Forum::getForum($forumId, $db);
        if ($forum === null) {
            return;
        }
        $db->update('forums', [
            'topic_count' => max(0, (int) $forum->topic_count + $topicDelta),
            'post_count' => max(0, (int) $forum->post_count + $postDelta),
        ], ['forum_id' => $forumId]);
    }

    private static function refreshForumLastPost(int $forumId, AP_DB $db): void
    {
        $table = $db->quoteIdentifier($db->table('forum_posts'));
        $topicsTable = $db->quoteIdentifier($db->table('topics'));
        $row = $db->getRow(
            'SELECT p.* FROM ' . $table . ' p'
            . ' INNER JOIN ' . $topicsTable . ' t ON t.' . $db->quoteIdentifier('topic_id')
            . ' = p.' . $db->quoteIdentifier('topic_id')
            . ' WHERE p.' . $db->quoteIdentifier('forum_id') . ' = ?'
            . ' AND p.' . $db->quoteIdentifier('post_approved') . ' = 1'
            . ' AND t.' . $db->quoteIdentifier('topic_status') . ' != ?'
            . ' ORDER BY p.' . $db->quoteIdentifier('post_time') . ' DESC, p.'
            . $db->quoteIdentifier('post_id') . ' DESC LIMIT 1',
            [$forumId, AP_Forum::TOPIC_STATUS_DELETED]
        );
        if ($row === null) {
            $db->update('forums', [
                'last_post_id' => 0,
                'last_poster_id' => 0,
                'last_post_time' => self::EMPTY_DATETIME,
                'last_topic_id' => 0,
            ], ['forum_id' => $forumId]);

            return;
        }
        $db->update('forums', [
            'last_post_id' => (int) $row->post_id,
            'last_poster_id' => (int) $row->poster_id,
            'last_post_time' => (string) $row->post_time,
            'last_topic_id' => (int) $row->topic_id,
        ], ['forum_id' => $forumId]);
    }

    private static function normalizeReportRow(object $row): object
    {
        $o = new stdClass();
        $o->report_id = (int) ($row->report_id ?? 0);
        $o->reporter_id = (int) ($row->reporter_id ?? 0);
        $o->report_type = (string) ($row->report_type ?? self::REPORT_TYPE_POST);
        $o->report_object_id = (int) ($row->report_object_id ?? 0);
        $o->report_reason = (string) ($row->report_reason ?? '');
        $o->report_details = (string) ($row->report_details ?? '');
        $o->report_status = (string) ($row->report_status ?? self::REPORT_STATUS_OPEN);
        $o->reported_at = (string) ($row->reported_at ?? '');
        $o->resolved_at = isset($row->resolved_at) && $row->resolved_at !== null
            ? (string) $row->resolved_at
            : null;
        $o->resolved_by = (int) ($row->resolved_by ?? 0);

        return $o;
    }

    private static function normalizeWarningRow(object $row): object
    {
        $o = new stdClass();
        $o->warning_id = (int) ($row->warning_id ?? 0);
        $o->user_id = (int) ($row->user_id ?? 0);
        $o->issuer_id = (int) ($row->issuer_id ?? 0);
        $o->warning_reason = (string) ($row->warning_reason ?? '');
        $o->warning_notes = (string) ($row->warning_notes ?? '');
        $o->related_type = (string) ($row->related_type ?? '');
        $o->related_id = (int) ($row->related_id ?? 0);
        $o->warning_status = (string) ($row->warning_status ?? self::WARNING_STATUS_ACTIVE);
        $o->warned_at = (string) ($row->warned_at ?? '');
        $o->expires_at = isset($row->expires_at) && $row->expires_at !== null
            ? (string) $row->expires_at
            : null;
        $o->revoked_at = isset($row->revoked_at) && $row->revoked_at !== null
            ? (string) $row->revoked_at
            : null;
        $o->revoked_by = (int) ($row->revoked_by ?? 0);

        return $o;
    }

    private static function normalizeBanRow(object $row): object
    {
        $o = new stdClass();
        $o->ban_id = (int) ($row->ban_id ?? 0);
        $o->ban_type = (string) ($row->ban_type ?? self::BAN_TYPE_USER);
        $o->ban_value = (string) ($row->ban_value ?? '');
        $o->user_id = (int) ($row->user_id ?? 0);
        $o->ban_reason = (string) ($row->ban_reason ?? '');
        $o->ban_notes = (string) ($row->ban_notes ?? '');
        $o->banned_by = (int) ($row->banned_by ?? 0);
        $o->ban_status = (string) ($row->ban_status ?? self::BAN_STATUS_ACTIVE);
        $o->banned_at = (string) ($row->banned_at ?? '');
        $o->expires_at = isset($row->expires_at) && $row->expires_at !== null
            ? (string) $row->expires_at
            : null;
        $o->lifted_at = isset($row->lifted_at) && $row->lifted_at !== null
            ? (string) $row->lifted_at
            : null;
        $o->lifted_by = (int) ($row->lifted_by ?? 0);

        return $o;
    }

    private static function sanitizeKey(string $key): string
    {
        $key = strtolower(trim($key));
        $key = preg_replace('/[^a-z0-9_\-]/', '', $key) ?? '';

        return $key;
    }

    private static function nowLocal(): string
    {
        return date('Y-m-d H:i:s');
    }

    private static function resolveDb(?AP_DB $db): AP_DB
    {
        if ($db instanceof AP_DB) {
            return $db;
        }

        if (function_exists('ap_db')) {
            return ap_db();
        }

        throw new RuntimeException('No database connection available for forum moderation.');
    }
}
