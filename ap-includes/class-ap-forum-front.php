<?php

/**
 * AgoraPress forum front-end — routing context, forms, template helpers.
 *
 * Bridges rewrite query vars → theme templates (forum.php, forum-view.php, topic.php)
 * and handles create-topic / reply POSTs with nonces and ACL.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * Public forum views and state-changing form handlers.
 */
class AP_Forum_Front
{
    public const ACTION_NEW_TOPIC = 'ap_forum_new_topic';

    public const ACTION_REPLY = 'ap_forum_reply';

    /** @var array<string, mixed> Flash notice for the next render (same request). */
    private static array $notice = [];

    /**
     * Whether the forum module is enabled.
     */
    public static function isModuleEnabled(?AP_DB $db = null): bool
    {
        if (function_exists('ap_is_module_enabled')) {
            return ap_is_module_enabled('forum', $db);
        }
        if (class_exists('AP_Options', false)) {
            return AP_Options::isModuleEnabled('forum', $db);
        }

        return true;
    }

    /**
     * Whether the given (or current) query is a forum front-end request.
     */
    public static function isForumRequest(?AP_Query $query = null): bool
    {
        $view = self::viewFromQuery($query);

        return $view !== '';
    }

    /**
     * Active forum view slug: index | forum | topic (empty when not forum).
     */
    public static function viewFromQuery(?AP_Query $query = null): string
    {
        if (!$query instanceof AP_Query && isset($GLOBALS['ap_query']) && $GLOBALS['ap_query'] instanceof AP_Query) {
            $query = $GLOBALS['ap_query'];
        }
        if (!$query instanceof AP_Query) {
            return '';
        }

        $view = strtolower(trim((string) $query->get('ap_forum_view', '')));
        if ($view === '' && (int) $query->get('topic_id', 0) > 0) {
            $view = 'topic';
        } elseif ($view === '' && (int) $query->get('forum_id', 0) > 0) {
            $view = 'forum';
        } elseif ($view === '') {
            $flag = $query->get('ap_forum', null);
            if ($flag !== null && $flag !== '' && $flag !== false && $flag !== 0 && $flag !== '0') {
                $view = 'index';
            }
        }

        $view = preg_replace('/[^a-z0-9\-]/', '', $view) ?? '';
        if (!in_array($view, ['index', 'forum', 'topic', 'search'], true)) {
            return '';
        }

        return $view;
    }

    /**
     * Resolve slugs → IDs and attach template metadata to query args.
     *
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    public static function enrichQueryArgs(array $args, ?AP_DB $db = null): array
    {
        if (!self::isModuleEnabled($db)) {
            $args['ap_forum_disabled'] = true;
            $args['ap_forum_view'] = (string) ($args['ap_forum_view'] ?? 'index');

            return $args;
        }

        if (!class_exists('AP_Forum', false)) {
            return $args;
        }

        $view = strtolower(trim((string) ($args['ap_forum_view'] ?? '')));
        if ($view === '') {
            return $args;
        }

        $paged = max(1, (int) ($args['paged'] ?? 1));
        $args['paged'] = $paged;

        if ($view === 'index') {
            $args['ap_forum'] = '1';
            $args['forum_name'] = 'Forums';
            $args['forum_url'] = AP_Forum::forumsIndexUrl();
            $args['forum_search_url'] = AP_Forum::searchUrl('', $db);
            $args['forum_search_enabled'] = !class_exists('AP_Forum_Guard', false)
                || AP_Forum_Guard::isSearchEnabled($db);

            return $args;
        }

        if ($view === 'search') {
            $term = trim((string) ($args['forum_s'] ?? $args['s'] ?? ''));
            $args['forum_s'] = $term;
            $args['s'] = $term;
            $args['forum_name'] = 'Forum search';
            $args['forum_url'] = AP_Forum::searchUrl($term, $db);
            $args['forum_search_url'] = AP_Forum::searchUrl('', $db);
            $args['forum_search_enabled'] = !class_exists('AP_Forum_Guard', false)
                || AP_Forum_Guard::isSearchEnabled($db);
            if ($args['forum_search_enabled'] && $term !== '') {
                $perPage = 20;
                if (function_exists('ap_apply_filters')) {
                    $filtered = ap_apply_filters('ap_forum_search_per_page', $perPage);
                    if (is_int($filtered) || is_numeric($filtered)) {
                        $perPage = max(1, min(100, (int) $filtered));
                    }
                }
                $searchArgs = [
                    'type' => 'all',
                    'per_page' => $perPage,
                    'page' => max(1, (int) ($args['paged'] ?? 1)),
                    'approved_only' => true,
                    'check_permissions' => true,
                    'user_id' => self::currentUserId($db),
                ];
                $forumId = (int) ($args['forum_id'] ?? 0);
                if ($forumId > 0) {
                    $searchArgs['forum_id'] = $forumId;
                }
                $search = AP_Forum::search($term, $searchArgs, $db);
                $args['forum_search_total'] = (int) ($search['total'] ?? 0);
                $args['forum_search_results'] = $search['results'] ?? [];
            } else {
                $args['forum_search_total'] = 0;
                $args['forum_search_results'] = [];
            }

            return $args;
        }

        if ($view === 'forum') {
            $forum = self::resolveForum($args, $db);
            if ($forum === null) {
                $args['ap_forum_not_found'] = true;
                $args['is_404'] = true;

                return $args;
            }
            $forumId = (int) $forum->forum_id;
            $args['forum_id'] = $forumId;
            $args['forum_slug'] = (string) ($forum->forum_slug ?? '');
            $args['forum_name'] = (string) ($forum->forum_name ?? 'Forum');
            $args['forum_url'] = AP_Forum::forumUrl($forum);
            $args['forum_desc'] = (string) ($forum->forum_desc ?? '');
            $args['forum_status'] = (string) ($forum->forum_status ?? 'open');
            $args['forum_closed'] = (string) ($forum->forum_status ?? '') === AP_Forum::FORUM_STATUS_CLOSED;
            $userId = self::currentUserId($db);
            $args['can_post_topic'] = self::userCanPostTopic($userId, $forumId, $db);

            return $args;
        }

        if ($view === 'topic') {
            $topic = self::resolveTopic($args, $db);
            if ($topic === null) {
                $args['ap_forum_not_found'] = true;
                $args['is_404'] = true;

                return $args;
            }
            $topicId = (int) $topic->topic_id;
            $forumId = (int) $topic->forum_id;
            $args['topic_id'] = $topicId;
            $args['topic_slug'] = (string) ($topic->topic_slug ?? '');
            $args['topic_title'] = (string) ($topic->topic_title ?? 'Topic');
            $args['topic_locked'] = AP_Forum::isTopicLocked($topic);
            $args['forum_id'] = $forumId;

            $forum = AP_Forum::getForum($forumId, $db);
            if ($forum !== null) {
                $args['forum_name'] = (string) ($forum->forum_name ?? 'Forum');
                $args['forum_url'] = AP_Forum::forumUrl($forum);
                $args['forum_slug'] = (string) ($forum->forum_slug ?? '');
            } else {
                $args['forum_name'] = 'Forum';
                $args['forum_url'] = AP_Forum::forumsIndexUrl();
            }

            $userId = self::currentUserId($db);
            $args['can_reply'] = !$args['topic_locked']
                && self::userCanReply($userId, $forumId, $db);

            return $args;
        }

        return $args;
    }

    /**
     * Apply enrichments onto an existing AP_Query (after construction).
     */
    public static function applyToQuery(AP_Query $query, ?AP_DB $db = null): void
    {
        $view = self::viewFromQuery($query);
        if ($view === '') {
            return;
        }

        $args = $query->query_vars;
        $args['ap_forum_view'] = $view;
        $enriched = self::enrichQueryArgs($args, $db);
        foreach ($enriched as $key => $value) {
            $query->set($key, $value);
        }

        if (!empty($enriched['is_404']) || !empty($enriched['ap_forum_not_found'])) {
            $query->is_404 = true;
            $query->is_home = false;
            $query->is_front_page = false;
        } else {
            $query->is_home = false;
            $query->is_front_page = false;
            $query->is_404 = false;
        }

        // Topic view side effects: views + unread mark.
        if ($view === 'topic' && empty($enriched['ap_forum_not_found'])) {
            $topicId = (int) ($enriched['topic_id'] ?? 0);
            if ($topicId > 0 && class_exists('AP_Forum', false)) {
                try {
                    AP_Forum::incrementTopicViews($topicId, $db);
                } catch (Throwable) {
                    // non-fatal
                }
            }
            $userId = self::currentUserId($db);
            if (
                $userId > 0
                && $topicId > 0
                && class_exists('AP_Forum_Read', false)
            ) {
                try {
                    AP_Forum_Read::markTopicRead($userId, $topicId, $db);
                } catch (Throwable) {
                    // non-fatal
                }
            }
        }

        // Presence tracking on forum pages.
        if (class_exists('AP_Online', false) && empty($enriched['ap_forum_disabled'])) {
            try {
                $context = [
                    'page' => match ($view) {
                        'topic' => '/topic/' . (int) ($enriched['topic_id'] ?? 0),
                        'forum' => '/forums/' . (int) ($enriched['forum_id'] ?? 0),
                        default => '/forums/',
                    },
                    'forum_id' => (int) ($enriched['forum_id'] ?? 0),
                    'topic_id' => (int) ($enriched['topic_id'] ?? 0),
                ];
                AP_Online::trackCurrent($context, $db);
            } catch (Throwable) {
                // optional
            }
        }
    }

    /**
     * Handle POST create-topic / reply when present.
     *
     * @param array<string, mixed>|null $post Typically $_POST.
     *
     * @return string|null Redirect URL after success, or null when no action / stay on page.
     */
    public static function handlePost(?array $post = null, ?AP_DB $db = null): ?string
    {
        $post = $post ?? $_POST;
        if ($post === [] || !isset($post['ap_forum_action'])) {
            return null;
        }

        if (!self::isModuleEnabled($db)) {
            self::$notice = [
                'type' => 'error',
                'message' => 'The forum module is disabled.',
            ];

            return null;
        }

        $action = (string) $post['ap_forum_action'];
        if ($action === self::ACTION_NEW_TOPIC) {
            return self::handleNewTopic($post, $db);
        }
        if ($action === self::ACTION_REPLY) {
            return self::handleReply($post, $db);
        }

        return null;
    }

    /**
     * Flash notice for templates (same request after failed POST, or redirect query).
     *
     * @return array{type: string, message: string}|null
     */
    public static function getNotice(): ?array
    {
        if (self::$notice !== []) {
            return [
                'type' => (string) (self::$notice['type'] ?? 'info'),
                'message' => (string) (self::$notice['message'] ?? ''),
            ];
        }

        if (isset($_GET['ap_forum_notice']) && is_string($_GET['ap_forum_notice'])) {
            $code = preg_replace('/[^a-z0-9_\-]/', '', strtolower($_GET['ap_forum_notice'])) ?? '';
            $map = [
                'topic_created' => ['type' => 'success', 'message' => 'Topic created.'],
                'topic_pending' => [
                    'type' => 'success',
                    'message' => 'Your topic was submitted and is awaiting moderation.',
                ],
                'reply_posted' => ['type' => 'success', 'message' => 'Reply posted.'],
                'reply_pending' => [
                    'type' => 'success',
                    'message' => 'Your reply was submitted and is awaiting moderation.',
                ],
                'flood' => [
                    'type' => 'error',
                    'message' => 'You are posting too quickly. Please wait a moment and try again.',
                ],
                'spam' => [
                    'type' => 'error',
                    'message' => 'Your post was rejected by the spam filter.',
                ],
                'login_required' => ['type' => 'error', 'message' => 'You must be logged in to post.'],
                'permission' => ['type' => 'error', 'message' => 'You do not have permission to do that.'],
                'locked' => ['type' => 'error', 'message' => 'This topic is locked.'],
                'invalid' => ['type' => 'error', 'message' => 'Please check your input and try again.'],
                'nonce' => ['type' => 'error', 'message' => 'Security check failed. Please try again.'],
            ];
            if (isset($map[$code])) {
                return $map[$code];
            }
        }

        return null;
    }

    /**
     * @param array{type?: string, message?: string}|null $notice
     */
    public static function setNotice(?array $notice): void
    {
        self::$notice = $notice ?? [];
    }

    /**
     * Whether the user may start a topic in the forum.
     */
    public static function userCanPostTopic(int $userId, int $forumId, ?AP_DB $db = null): bool
    {
        if ($forumId < 1) {
            return false;
        }
        if (class_exists('AP_Forum_Permissions', false)) {
            return AP_Forum_Permissions::userCanPostTopic($userId, $forumId, $db);
        }
        // Without ACL layer: logged-in users may post.
        return $userId > 0;
    }

    /**
     * Whether the user may reply in the forum.
     */
    public static function userCanReply(int $userId, int $forumId, ?AP_DB $db = null): bool
    {
        if ($forumId < 1) {
            return false;
        }
        if (class_exists('AP_Forum_Permissions', false)) {
            return AP_Forum_Permissions::userCanPostReply($userId, $forumId, $db);
        }

        return $userId > 0;
    }

    /**
     * Per-page topic list for the current forum view (uses query paged).
     *
     * @return list<array<string, mixed>>
     */
    public static function topicsForQuery(?AP_Query $query = null, ?AP_DB $db = null): array
    {
        if (!$query instanceof AP_Query && isset($GLOBALS['ap_query']) && $GLOBALS['ap_query'] instanceof AP_Query) {
            $query = $GLOBALS['ap_query'];
        }
        $forumId = $query instanceof AP_Query ? (int) $query->get('forum_id', 0) : 0;
        if ($forumId < 1 || !class_exists('AP_Forum', false)) {
            return [];
        }
        $page = $query instanceof AP_Query ? max(1, (int) $query->get('paged', 1)) : 1;
        $perPage = 20;
        if (function_exists('ap_apply_filters')) {
            $filtered = ap_apply_filters('ap_forum_topics_per_page', $perPage);
            if (is_int($filtered) || is_numeric($filtered)) {
                $perPage = max(1, min(100, (int) $filtered));
            }
        }

        return AP_Forum::getTopicsDisplayData($forumId, [
            'per_page' => $perPage,
            'page' => $page,
        ], $db);
    }

    /**
     * Posts for the current topic view.
     *
     * @return list<array<string, mixed>>
     */
    public static function postsForQuery(?AP_Query $query = null, ?AP_DB $db = null): array
    {
        if (!$query instanceof AP_Query && isset($GLOBALS['ap_query']) && $GLOBALS['ap_query'] instanceof AP_Query) {
            $query = $GLOBALS['ap_query'];
        }
        $topicId = $query instanceof AP_Query ? (int) $query->get('topic_id', 0) : 0;
        if ($topicId < 1 || !class_exists('AP_Forum', false)) {
            return [];
        }
        $page = $query instanceof AP_Query ? max(1, (int) $query->get('paged', 1)) : 1;
        $perPage = 20;
        if (function_exists('ap_apply_filters')) {
            $filtered = ap_apply_filters('ap_forum_posts_per_page', $perPage);
            if (is_int($filtered) || is_numeric($filtered)) {
                $perPage = max(1, min(100, (int) $filtered));
            }
        }

        return AP_Forum::getPostsDisplayData($topicId, [
            'per_page' => $perPage,
            'page' => $page,
        ], $db);
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $args
     */
    private static function resolveForum(array $args, ?AP_DB $db): ?object
    {
        $id = (int) ($args['forum_id'] ?? 0);
        if ($id > 0) {
            return AP_Forum::getForum($id, $db);
        }
        $slug = trim((string) ($args['forum_slug'] ?? ''));
        if ($slug !== '') {
            return AP_Forum::getForumBySlug($slug, $db);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $args
     */
    private static function resolveTopic(array $args, ?AP_DB $db): ?object
    {
        $id = (int) ($args['topic_id'] ?? 0);
        $topic = null;
        if ($id > 0) {
            $topic = AP_Forum::getTopic($id, $db);
        } else {
            $slug = trim((string) ($args['topic_slug'] ?? ''));
            $forumId = (int) ($args['forum_id'] ?? 0);
            if ($slug !== '') {
                $topic = AP_Forum::getTopicBySlug($slug, $forumId, $db);
            }
        }
        if ($topic === null) {
            return null;
        }
        if ((string) ($topic->topic_status ?? '') === AP_Forum::TOPIC_STATUS_DELETED) {
            return null;
        }
        // Hide unapproved topics from public view (author + mods still allowed).
        if ((int) ($topic->topic_approved ?? 1) !== 1) {
            $userId = self::currentUserId($db);
            $posterId = (int) ($topic->topic_poster ?? 0);
            $canSee = $userId > 0 && ($userId === $posterId || self::userCanModerate((int) $topic->forum_id, $userId, $db));
            if (!$canSee) {
                return null;
            }
        }

        return $topic;
    }

    private static function userCanModerate(int $forumId, int $userId, ?AP_DB $db): bool
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
        if (class_exists('AP_Forum_Permissions', false)) {
            return AP_Forum_Permissions::userCanModerate($userId, $forumId, $db);
        }

        return false;
    }

    /**
     * @param array<string, mixed> $post
     */
    private static function handleNewTopic(array $post, ?AP_DB $db): ?string
    {
        $forumId = (int) ($post['forum_id'] ?? 0);
        $nonce = (string) ($post['_ap_nonce'] ?? $post['_wpnonce'] ?? '');
        $action = self::ACTION_NEW_TOPIC . '_' . $forumId;

        if (!self::verifyNonce($nonce, $action)) {
            self::$notice = ['type' => 'error', 'message' => 'Security check failed. Please try again.'];

            return null;
        }

        $userId = self::currentUserId($db);
        if ($userId < 1) {
            self::$notice = ['type' => 'error', 'message' => 'You must be logged in to post.'];

            return null;
        }

        if (!self::userCanPostTopic($userId, $forumId, $db)) {
            self::$notice = ['type' => 'error', 'message' => 'You do not have permission to create topics here.'];

            return null;
        }

        $title = trim((string) ($post['topic_title'] ?? ''));
        $body = trim((string) ($post['topic_body'] ?? $post['content'] ?? ''));
        if ($title === '' || $body === '') {
            self::$notice = ['type' => 'error', 'message' => 'Subject and message are required.'];

            return null;
        }

        if (!class_exists('AP_Forum', false)) {
            return null;
        }

        $topicId = AP_Forum::createTopic([
            'forum_id' => $forumId,
            'topic_title' => $title,
            'content' => $body,
            'poster_id' => $userId,
            'poster_ip' => self::clientIp(),
        ], $db, [
            'check_open' => true,
            'check_permissions' => true,
            'check_guard' => true,
        ]);

        if ($topicId < 1) {
            $guard = class_exists('AP_Forum_Guard', false) ? AP_Forum_Guard::getLastResult() : [];
            $code = (string) ($guard['code'] ?? '');
            if ($code === 'flood') {
                self::$notice = [
                    'type' => 'error',
                    'message' => (string) ($guard['message'] ?? 'Please wait before posting again.'),
                ];
            } elseif ($code === 'spam' || $code === 'reject') {
                self::$notice = [
                    'type' => 'error',
                    'message' => (string) ($guard['message'] ?? 'Your post was rejected by the spam filter.'),
                ];
            } else {
                self::$notice = [
                    'type' => 'error',
                    'message' => 'Could not create the topic. The forum may be closed.',
                ];
            }

            return null;
        }

        $created = AP_Forum::getTopic($topicId, $db);
        $pending = $created !== null && (int) ($created->topic_approved ?? 1) !== 1;
        $notice = $pending ? 'topic_pending' : 'topic_created';

        // Pending topics stay on the forum view (not publicly listed yet).
        if ($pending) {
            $forum = AP_Forum::getForum($forumId, $db);
            $url = $forum !== null ? AP_Forum::forumUrl($forum) : AP_Forum::forumsIndexUrl();
        } else {
            $url = AP_Forum::topicUrl($created ?? $topicId);
        }
        $sep = str_contains($url, '?') ? '&' : '?';

        return $url . $sep . 'ap_forum_notice=' . $notice;
    }

    /**
     * @param array<string, mixed> $post
     */
    private static function handleReply(array $post, ?AP_DB $db): ?string
    {
        $topicId = (int) ($post['topic_id'] ?? 0);
        $nonce = (string) ($post['_ap_nonce'] ?? $post['_wpnonce'] ?? '');
        $action = self::ACTION_REPLY . '_' . $topicId;

        if (!self::verifyNonce($nonce, $action)) {
            self::$notice = ['type' => 'error', 'message' => 'Security check failed. Please try again.'];

            return null;
        }

        $userId = self::currentUserId($db);
        if ($userId < 1) {
            self::$notice = ['type' => 'error', 'message' => 'You must be logged in to post.'];

            return null;
        }

        if (!class_exists('AP_Forum', false)) {
            return null;
        }

        $topic = AP_Forum::getTopic($topicId, $db);
        if ($topic === null) {
            self::$notice = ['type' => 'error', 'message' => 'Topic not found.'];

            return null;
        }

        if (AP_Forum::isTopicLocked($topic)) {
            self::$notice = ['type' => 'error', 'message' => 'This topic is locked.'];

            return null;
        }

        $forumId = (int) $topic->forum_id;
        if (!self::userCanReply($userId, $forumId, $db)) {
            self::$notice = ['type' => 'error', 'message' => 'You do not have permission to reply here.'];

            return null;
        }

        $body = trim((string) ($post['reply_body'] ?? $post['content'] ?? ''));
        if ($body === '') {
            self::$notice = ['type' => 'error', 'message' => 'Message is required.'];

            return null;
        }

        $postId = AP_Forum::createReply([
            'topic_id' => $topicId,
            'content' => $body,
            'poster_id' => $userId,
            'poster_ip' => self::clientIp(),
        ], $db, [
            'check_open' => true,
            'check_permissions' => true,
            'check_guard' => true,
        ]);

        if ($postId < 1) {
            $guard = class_exists('AP_Forum_Guard', false) ? AP_Forum_Guard::getLastResult() : [];
            $code = (string) ($guard['code'] ?? '');
            if ($code === 'flood') {
                self::$notice = [
                    'type' => 'error',
                    'message' => (string) ($guard['message'] ?? 'Please wait before posting again.'),
                ];
            } elseif ($code === 'spam' || $code === 'reject') {
                self::$notice = [
                    'type' => 'error',
                    'message' => (string) ($guard['message'] ?? 'Your post was rejected by the spam filter.'),
                ];
            } else {
                self::$notice = ['type' => 'error', 'message' => 'Could not post the reply.'];
            }

            return null;
        }

        $createdPost = AP_Forum::getPost($postId, $db);
        $pending = $createdPost !== null && (int) ($createdPost->post_approved ?? 1) !== 1;
        $notice = $pending ? 'reply_pending' : 'reply_posted';
        $url = AP_Forum::topicUrl($topic);
        $sep = str_contains($url, '?') ? '&' : '?';
        $hash = $pending ? '' : '#post-' . $postId;

        return $url . $sep . 'ap_forum_notice=' . $notice . $hash;
    }

    /**
     * Search term from the current (or given) query.
     */
    public static function searchTermFromQuery(?AP_Query $query = null): string
    {
        if (!$query instanceof AP_Query && isset($GLOBALS['ap_query']) && $GLOBALS['ap_query'] instanceof AP_Query) {
            $query = $GLOBALS['ap_query'];
        }
        if (!$query instanceof AP_Query) {
            return '';
        }
        $term = trim((string) $query->get('forum_s', ''));
        if ($term === '') {
            $term = trim((string) $query->get('s', ''));
        }

        return $term;
    }

    /**
     * Run forum search for templates / front controller.
     *
     * @return array{
     *   query: string,
     *   total: int,
     *   topics: list<object>,
     *   posts: list<object>,
     *   results: list<array<string, mixed>>
     * }
     */
    public static function searchForQuery(?AP_Query $query = null, ?AP_DB $db = null): array
    {
        $term = self::searchTermFromQuery($query);
        $empty = [
            'query' => $term,
            'total' => 0,
            'topics' => [],
            'posts' => [],
            'results' => [],
        ];
        if ($term === '' || !class_exists('AP_Forum', false)) {
            return $empty;
        }
        if (class_exists('AP_Forum_Guard', false) && !AP_Forum_Guard::isSearchEnabled($db)) {
            return $empty;
        }

        $page = 1;
        $forumId = 0;
        if ($query instanceof AP_Query) {
            $page = max(1, (int) $query->get('paged', 1));
            $forumId = (int) $query->get('forum_id', 0);
        }
        $perPage = 20;
        if (function_exists('ap_apply_filters')) {
            $filtered = ap_apply_filters('ap_forum_search_per_page', $perPage);
            if (is_int($filtered) || is_numeric($filtered)) {
                $perPage = max(1, min(100, (int) $filtered));
            }
        }

        $args = [
            'type' => 'all',
            'per_page' => $perPage,
            'page' => $page,
            'approved_only' => true,
            'check_permissions' => true,
            'user_id' => self::currentUserId($db),
        ];
        if ($forumId > 0) {
            $args['forum_id'] = $forumId;
        }

        return AP_Forum::search($term, $args, $db);
    }

    private static function verifyNonce(string $nonce, string $action): bool
    {
        if ($nonce === '') {
            return false;
        }
        if (function_exists('ap_check_nonce')) {
            return ap_check_nonce($nonce, $action);
        }
        if (class_exists('AP_Nonce', false)) {
            return AP_Nonce::check($nonce, $action);
        }

        // Tests without nonce layer: allow empty-salt environments only when salts missing.
        return defined('AP_NONCE_KEY') === false;
    }

    private static function currentUserId(?AP_DB $db): int
    {
        if (function_exists('ap_get_current_user_id')) {
            return (int) ap_get_current_user_id($db);
        }
        if (class_exists('AP_Session', false)) {
            try {
                return (int) AP_Session::getCurrentUserId($db);
            } catch (Throwable) {
                return 0;
            }
        }

        return 0;
    }

    private static function clientIp(): string
    {
        $keys = ['HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];
        foreach ($keys as $key) {
            if (!empty($_SERVER[$key]) && is_string($_SERVER[$key])) {
                $raw = $_SERVER[$key];
                if (str_contains($raw, ',')) {
                    $raw = trim(explode(',', $raw)[0]);
                }
                if (filter_var($raw, FILTER_VALIDATE_IP)) {
                    return $raw;
                }
            }
        }

        return '';
    }
}
