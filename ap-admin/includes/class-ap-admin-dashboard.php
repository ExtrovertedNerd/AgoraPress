<?php

/**
 * Admin dashboard home — At a Glance stats, Activity feed, Quick Draft.
 *
 * Module-aware: Blog / Static Pages / Forum toggles hide related widgets.
 * Forum topic/post counts are omitted until dedicated forum tables exist.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * Dashboard data + Quick Draft save helpers.
 */
class AP_Admin_Dashboard
{
    /** Default number of recent items in Activity widgets. */
    public const ACTIVITY_LIMIT = 5;

    /**
     * Count posts of a type grouped by post_status.
     *
     * @return array<string, int> status => count
     */
    public static function countPostsByStatus(string $postType = 'post', ?AP_DB $db = null): array
    {
        $db = self::resolveDb($db);
        $postType = AP_Admin::resolvePostType($postType, 'post');

        try {
            $table = $db->quoteIdentifier($db->table('posts'));
            $rows = $db->getResults(
                'SELECT ' . $db->quoteIdentifier('post_status') . ' AS st, COUNT(*) AS cnt FROM '
                . $table . ' WHERE ' . $db->quoteIdentifier('post_type') . ' = ? GROUP BY '
                . $db->quoteIdentifier('post_status'),
                [$postType]
            );
        } catch (Throwable) {
            return [];
        }

        $counts = [];
        if (!is_array($rows)) {
            return $counts;
        }
        foreach ($rows as $row) {
            $data = is_array($row) ? $row : get_object_vars($row);
            $st = (string) ($data['st'] ?? '');
            if ($st === '' || $st === 'auto-draft') {
                continue;
            }
            $counts[$st] = (int) ($data['cnt'] ?? 0);
        }

        return $counts;
    }

    /**
     * Sum of non-trash, non-auto-draft statuses for a type (admin “total”).
     *
     * @param array<string, int> $byStatus
     */
    public static function totalFromStatusCounts(array $byStatus): int
    {
        $total = 0;
        foreach ($byStatus as $status => $count) {
            if ($status === 'trash' || $status === 'auto-draft' || $status === 'inherit') {
                continue;
            }
            $total += (int) $count;
        }

        return $total;
    }

    /**
     * At a Glance aggregate for the dashboard.
     *
     * Keys are always present; module-off sections use empty counts / zero.
     *
     * @return array{
     *   modules: array{blog: bool, static_pages: bool, forum: bool},
     *   posts: array{by_status: array<string, int>, publish: int, draft: int, pending: int, total: int},
     *   pages: array{by_status: array<string, int>, publish: int, draft: int, pending: int, total: int},
     *   comments: array{by_status: array<string, int>, approved: int, pending: int, spam: int, total: int},
     *   users: int,
     *   forum: array{forums: int, topics: int, posts: int, pending: int, open_reports: int}|null
     * }
     */
    public static function getAtAGlance(?AP_DB $db = null): array
    {
        $db = self::resolveDb($db);

        $blog = self::isModuleEnabled('blog', $db);
        $pages = self::isModuleEnabled('static_pages', $db);
        $forum = self::isModuleEnabled('forum', $db);

        $postCounts = $blog ? self::countPostsByStatus('post', $db) : [];
        $pageCounts = $pages ? self::countPostsByStatus('page', $db) : [];

        $commentByStatus = [];
        $commentApproved = 0;
        $commentPending = 0;
        $commentSpam = 0;
        $commentTotal = 0;
        if ($blog && class_exists('AP_Comment', false)) {
            try {
                $commentByStatus = AP_Comment::countByStatus(null, $db);
                $commentApproved = (int) ($commentByStatus[AP_Comment::STATUS_APPROVED] ?? 0);
                $commentPending = (int) ($commentByStatus[AP_Comment::STATUS_HOLD] ?? 0);
                $commentSpam = (int) ($commentByStatus[AP_Comment::STATUS_SPAM] ?? 0);
                // “Total” for glance = approved + pending (not spam/trash).
                $commentTotal = $commentApproved + $commentPending;
            } catch (Throwable) {
                $commentByStatus = [];
            }
        }

        $userCount = 0;
        try {
            if (class_exists('AP_User', false)) {
                $userCount = AP_User::count([], $db);
            }
        } catch (Throwable) {
            $userCount = 0;
        }

        $forumStats = null;
        if ($forum && class_exists('AP_Forum', false)) {
            try {
                $forums = AP_Forum::getForums(['include_hidden' => true], $db);
                $topicCount = AP_Forum::countTopicsQuery(['include_deleted' => false], $db);
                $pendingTopics = AP_Forum::countPendingTopics([], $db);
                $pendingPosts = class_exists('AP_Forum', false)
                    ? AP_Forum::countPendingPosts([], $db)
                    : 0;
                $openReports = class_exists('AP_Forum_Moderation', false)
                    ? AP_Forum_Moderation::countReports([
                        'status' => AP_Forum_Moderation::REPORT_STATUS_OPEN,
                    ], $db)
                    : 0;
                $postCount = 0;
                foreach ($forums as $f) {
                    $postCount += (int) ($f->post_count ?? 0);
                }
                $forumStats = [
                    'forums' => count($forums),
                    'topics' => $topicCount,
                    'posts' => $postCount,
                    'pending' => $pendingTopics + $pendingPosts,
                    'open_reports' => $openReports,
                ];
            } catch (Throwable) {
                $forumStats = [
                    'forums' => 0,
                    'topics' => 0,
                    'posts' => 0,
                    'pending' => 0,
                    'open_reports' => 0,
                ];
            }
        }

        return [
            'modules' => [
                'blog' => $blog,
                'static_pages' => $pages,
                'forum' => $forum,
            ],
            'posts' => self::summarizePostCounts($postCounts),
            'pages' => self::summarizePostCounts($pageCounts),
            'comments' => [
                'by_status' => $commentByStatus,
                'approved' => $commentApproved,
                'pending' => $commentPending,
                'spam' => $commentSpam,
                'total' => $commentTotal,
            ],
            'users' => $userCount,
            'forum' => $forumStats,
        ];
    }

    /**
     * Recent published content for Activity (posts and/or pages by module).
     *
     * @return list<AP_Post>
     */
    public static function getRecentContent(int $limit = self::ACTIVITY_LIMIT, ?AP_DB $db = null): array
    {
        $db = self::resolveDb($db);
        $limit = max(1, min(50, $limit));

        $types = [];
        if (self::isModuleEnabled('blog', $db)) {
            $types[] = 'post';
        }
        if (self::isModuleEnabled('static_pages', $db)) {
            $types[] = 'page';
        }
        if ($types === [] || !class_exists('AP_Post', false)) {
            return [];
        }

        try {
            AP_Post::ensureBuiltins();

            return AP_Post::query([
                'post_type' => $types,
                'post_status' => 'publish',
                'orderby' => 'post_date',
                'order' => 'DESC',
                'limit' => $limit,
            ], $db);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Recent comments for Activity (all statuses except trash by default).
     *
     * @return list<AP_Comment>
     */
    public static function getRecentComments(int $limit = self::ACTIVITY_LIMIT, ?AP_DB $db = null): array
    {
        $db = self::resolveDb($db);
        $limit = max(1, min(50, $limit));

        if (!self::isModuleEnabled('blog', $db) || !class_exists('AP_Comment', false)) {
            return [];
        }

        try {
            return AP_Comment::query([
                'status' => [
                    AP_Comment::STATUS_APPROVED,
                    AP_Comment::STATUS_HOLD,
                ],
                'orderby' => 'date',
                'order' => 'DESC',
                'limit' => $limit,
            ], $db);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Save a Quick Draft from the dashboard widget.
     *
     * Creates a draft post with title + content. Requires edit_posts and blog module.
     *
     * @param array<string, mixed> $input Typically $_POST.
     *
     * @return array{ok: bool, id: int, message_key: string, errors: list<string>, post: ?AP_Post}
     */
    public static function saveQuickDraft(array $input, int $userId, ?AP_DB $db = null): array
    {
        $db = self::resolveDb($db);
        $empty = [
            'ok' => false,
            'id' => 0,
            'message_key' => 'error',
            'errors' => [],
            'post' => null,
        ];

        if (!self::isModuleEnabled('blog', $db)) {
            $empty['errors'][] = 'The Blog module is disabled.';

            return $empty;
        }

        $nonce = (string) ($input['_ap_nonce'] ?? '');
        if (!function_exists('ap_check_nonce') || !ap_check_nonce($nonce, 'quick-draft', $userId > 0 ? $userId : null)) {
            $empty['message_key'] = 'nonce';
            $empty['errors'][] = 'Security check failed. Please reload and try again.';

            return $empty;
        }

        if ($userId < 1 || !AP_Admin::userCan($userId, 'edit_posts', null, $db)) {
            $empty['errors'][] = 'You do not have permission to create posts.';

            return $empty;
        }

        $title = function_exists('ap_sanitize_text_field')
            ? ap_sanitize_text_field((string) ($input['post_title'] ?? ''))
            : trim((string) ($input['post_title'] ?? ''));
        $content = (string) ($input['post_content'] ?? '');
        $content = str_replace("\0", '', $content);

        if ($title === '' && trim($content) === '') {
            $empty['errors'][] = 'Please enter a title or some content for the draft.';

            return $empty;
        }

        if ($title === '') {
            $title = 'Untitled';
        }

        if (!class_exists('AP_Post', false)) {
            $empty['errors'][] = 'Post system is not available.';

            return $empty;
        }

        try {
            AP_Post::ensureBuiltins();
            $id = AP_Post::insert([
                'post_title' => $title,
                'post_content' => $content,
                'post_status' => 'draft',
                'post_type' => 'post',
                'post_author' => $userId,
            ], $db);
        } catch (Throwable $e) {
            $empty['errors'][] = 'Could not save draft.';

            return $empty;
        }

        if ($id < 1) {
            $empty['errors'][] = 'Could not save draft.';

            return $empty;
        }

        $post = AP_Post::get($id, $db);

        return [
            'ok' => true,
            'id' => $id,
            'message_key' => 'draft_saved',
            'errors' => [],
            'post' => $post,
        ];
    }

    /**
     * Whether the current user may use the Quick Draft widget.
     */
    public static function canQuickDraft(int $userId, ?AP_DB $db = null): bool
    {
        $db = self::resolveDb($db);
        if ($userId < 1 || !self::isModuleEnabled('blog', $db)) {
            return false;
        }

        return AP_Admin::userCan($userId, 'edit_posts', null, $db);
    }

    /**
     * @param array<string, int> $byStatus
     *
     * @return array{by_status: array<string, int>, publish: int, draft: int, pending: int, total: int}
     */
    private static function summarizePostCounts(array $byStatus): array
    {
        return [
            'by_status' => $byStatus,
            'publish' => (int) ($byStatus['publish'] ?? 0),
            'draft' => (int) ($byStatus['draft'] ?? 0),
            'pending' => (int) ($byStatus['pending'] ?? 0),
            'total' => self::totalFromStatusCounts($byStatus),
        ];
    }

    private static function isModuleEnabled(string $module, ?AP_DB $db): bool
    {
        if (function_exists('ap_is_module_enabled')) {
            return ap_is_module_enabled($module, $db);
        }
        if (class_exists('AP_Options', false)) {
            return AP_Options::isModuleEnabled($module, $db);
        }

        return true;
    }

    private static function resolveDb(?AP_DB $db): AP_DB
    {
        if ($db instanceof AP_DB) {
            return $db;
        }

        return ap_db();
    }
}
