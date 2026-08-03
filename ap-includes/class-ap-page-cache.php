<?php

/**
 * AgoraPress Page Cache hooks & advanced-cache drop-in support.
 *
 * Core does not ship a full-page HTML store. It provides:
 *   - Optional early `advanced-cache.php` drop-in (when AP_CACHE is true)
 *   - Request suitability helpers (`ap_should_cache_request`)
 *   - Invalidation API + actions for plugins / reverse proxies
 *   - Automatic purge signals on posts, comments, forums, and related changes
 *
 * Public surface (WP-inspired, ap_ prefix):
 *   ap_start_page_cache / ap_page_cache_enabled / ap_using_page_cache
 *   ap_should_cache_request / ap_skip_page_cache / ap_page_cache_skipped
 *   ap_clean_page_cache / ap_clean_post_cache / ap_clean_topic_cache
 *   ap_clean_forum_cache / ap_nocache_headers
 *
 * Actions for cache backends:
 *   ap_page_cache_started, ap_page_cache_flush, ap_page_cache_purge_url,
 *   ap_page_cache_purge_post, ap_page_cache_purge_topic, ap_page_cache_purge_forum,
 *   ap_clean_post_cache
 *
 * Filters:
 *   ap_page_cache_enabled, ap_should_cache_request,
 *   ap_page_cache_urls_for_post, ap_page_cache_skip_logged_in
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * Page cache orchestration (hooks + drop-in loader + purge helpers).
 */
class AP_Page_Cache
{
    /** Whether the advanced-cache drop-in was included this request. */
    private static bool $dropinLoaded = false;

    /** Whether start() has run. */
    private static bool $started = false;

    /** Whether invalidation action listeners were registered. */
    private static bool $invalidationRegistered = false;

    /** Request-local “do not cache this response” flag. */
    private static bool $skipRequest = false;

    /**
     * Start page-cache support: load advanced-cache drop-in when AP_CACHE is on.
     *
     * Safe to call once per request (idempotent). The drop-in may serve a
     * cached response and exit; core never implements the store itself.
     */
    public static function start(): void
    {
        if (self::$started) {
            return;
        }
        self::$started = true;

        if (self::isEnabled()) {
            $dropin = self::dropinPath();
            if ($dropin !== '' && is_readable($dropin)) {
                self::$dropinLoaded = true;
                include_once $dropin;
            }
        }

        if (function_exists('ap_do_action')) {
            ap_do_action('ap_page_cache_started', self::$dropinLoaded, self::isEnabled());
        }
    }

    /**
     * Whether page caching is enabled (AP_CACHE constant + filter).
     */
    public static function isEnabled(): bool
    {
        $enabled = defined('AP_CACHE') && AP_CACHE;

        if (function_exists('ap_apply_filters')) {
            $enabled = (bool) ap_apply_filters('ap_page_cache_enabled', $enabled);
        }

        return $enabled;
    }

    /**
     * Whether an advanced-cache drop-in was loaded this request.
     */
    public static function usingDropin(): bool
    {
        return self::$dropinLoaded;
    }

    /**
     * Absolute path to advanced-cache.php, or empty when content dir unknown.
     */
    public static function dropinPath(): string
    {
        if (defined('AP_CONTENT_DIR') && is_string(AP_CONTENT_DIR) && AP_CONTENT_DIR !== '') {
            return rtrim(str_replace('\\', '/', AP_CONTENT_DIR), '/') . '/advanced-cache.php';
        }

        if (defined('AP_ABSPATH')) {
            return rtrim(str_replace('\\', '/', (string) AP_ABSPATH), '/') . '/ap-content/advanced-cache.php';
        }

        return '';
    }

    /**
     * Mark the current request as non-cacheable (plugins, forms, personalised UI).
     */
    public static function skipRequest(): void
    {
        self::$skipRequest = true;
    }

    /**
     * Whether the current request was marked non-cacheable.
     */
    public static function requestSkipped(): bool
    {
        return self::$skipRequest;
    }

    /**
     * Whether this request is a candidate for full-page caching.
     *
     * Defaults: only GET/HEAD, not admin, not CLI, not maintenance, not when
     * AP_DONOTCACHEPAGE is defined, not when skipRequest() was called, and
     * (by default) not for logged-in users. Fully filterable.
     */
    public static function shouldCacheRequest(): bool
    {
        $should = true;

        if (self::$skipRequest) {
            $should = false;
        }

        if (defined('AP_DONOTCACHEPAGE') && AP_DONOTCACHEPAGE) {
            $should = false;
        }

        if (defined('AP_ADMIN') && AP_ADMIN) {
            $should = false;
        }

        if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
            $should = false;
        }

        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method !== '' && $method !== 'GET' && $method !== 'HEAD') {
            $should = false;
        }

        if (
            class_exists('AP_Core_Updater', false)
            && method_exists('AP_Core_Updater', 'isMaintenanceMode')
            && AP_Core_Updater::isMaintenanceMode()
        ) {
            $should = false;
        }

        $skipLoggedIn = true;
        if (function_exists('ap_apply_filters')) {
            $skipLoggedIn = (bool) ap_apply_filters('ap_page_cache_skip_logged_in', $skipLoggedIn);
        }
        if ($skipLoggedIn && function_exists('ap_is_user_logged_in') && ap_is_user_logged_in()) {
            $should = false;
        }

        if (function_exists('ap_apply_filters')) {
            $should = (bool) ap_apply_filters('ap_should_cache_request', $should);
        }

        return $should;
    }

    /**
     * Send Cache-Control / Pragma / Expires headers that discourage caching.
     */
    public static function nocacheHeaders(): void
    {
        if (headers_sent()) {
            return;
        }

        header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: Wed, 11 Jan 1984 05:00:00 GMT');

        if (function_exists('ap_do_action')) {
            ap_do_action('ap_nocache_headers');
        }
    }

    /**
     * Flush the entire page cache (or a single URL when provided).
     *
     * @param string|null $url Absolute or site-relative URL; null = full flush.
     */
    public static function clean(?string $url = null): void
    {
        if ($url === null || trim($url) === '') {
            if (function_exists('ap_do_action')) {
                ap_do_action('ap_page_cache_flush');
            }

            return;
        }

        self::purgeUrl($url);
    }

    /**
     * Purge one URL from the page cache.
     */
    public static function purgeUrl(string $url): void
    {
        $url = trim($url);
        if ($url === '') {
            return;
        }

        if (function_exists('ap_do_action')) {
            ap_do_action('ap_page_cache_purge_url', $url);
        }
    }

    /**
     * Invalidate page cache entries related to a post/page (and home/archives).
     *
     * Fires `ap_clean_post_cache` then `ap_page_cache_purge_post` with the
     * resolved URL list so backends can purge selectively or fall back to flush.
     */
    public static function cleanPost(int $postId): void
    {
        if ($postId < 1) {
            return;
        }

        if (function_exists('ap_do_action')) {
            ap_do_action('ap_clean_post_cache', $postId);
        }

        $urls = self::urlsForPost($postId);

        if (function_exists('ap_do_action')) {
            ap_do_action('ap_page_cache_purge_post', $postId, $urls);
        }

        foreach ($urls as $url) {
            self::purgeUrl($url);
        }
    }

    /**
     * Invalidate page cache for a forum topic (and parent forum index).
     */
    public static function cleanTopic(int $topicId, ?int $forumId = null): void
    {
        if ($topicId < 1) {
            return;
        }

        $urls = [];

        if (function_exists('ap_topic_url')) {
            $topicUrl = (string) ap_topic_url($topicId);
            if ($topicUrl !== '') {
                $urls[] = $topicUrl;
            }
        }

        if ($forumId !== null && $forumId > 0) {
            self::cleanForum($forumId);
        } elseif (
            $forumId === null
            && class_exists('AP_Forum', false)
            && method_exists('AP_Forum', 'getTopic')
        ) {
            $topic = AP_Forum::getTopic($topicId);
            if (is_object($topic) && isset($topic->forum_id)) {
                self::cleanForum((int) $topic->forum_id);
            }
        }

        if (function_exists('ap_forums_url')) {
            $index = (string) ap_forums_url();
            if ($index !== '') {
                $urls[] = $index;
            }
        }

        $urls = array_values(array_unique(array_filter($urls, static fn ($u) => is_string($u) && $u !== '')));

        if (function_exists('ap_do_action')) {
            ap_do_action('ap_page_cache_purge_topic', $topicId, $urls);
        }

        foreach ($urls as $url) {
            self::purgeUrl($url);
        }
    }

    /**
     * Invalidate page cache for a forum listing.
     */
    public static function cleanForum(int $forumId): void
    {
        if ($forumId < 1) {
            return;
        }

        $urls = [];

        if (function_exists('ap_forum_url')) {
            $forumUrl = (string) ap_forum_url($forumId);
            if ($forumUrl !== '') {
                $urls[] = $forumUrl;
            }
        }

        if (function_exists('ap_forums_url')) {
            $index = (string) ap_forums_url();
            if ($index !== '') {
                $urls[] = $index;
            }
        }

        $urls = array_values(array_unique(array_filter($urls, static fn ($u) => is_string($u) && $u !== '')));

        if (function_exists('ap_do_action')) {
            ap_do_action('ap_page_cache_purge_forum', $forumId, $urls);
        }

        foreach ($urls as $url) {
            self::purgeUrl($url);
        }
    }

    /**
     * URLs that should be purged when a post changes.
     *
     * @return list<string>
     */
    public static function urlsForPost(int $postId): array
    {
        $urls = [];

        if ($postId > 0 && class_exists('AP_Post', false)) {
            $post = AP_Post::get($postId);
            if (
                $post !== null
                && function_exists('ap_get_permalink')
                && class_exists('AP_Rewrite', false)
            ) {
                try {
                    $permalink = (string) ap_get_permalink($post);
                    if ($permalink !== '') {
                        $urls[] = $permalink;
                    }
                } catch (Throwable) {
                    // Partial bootstrap / missing rewrite — fall through to ?p=.
                }
            }
            // Always include the stable plain permalink form.
            if (function_exists('ap_home_url')) {
                $urls[] = ap_home_url('/?p=' . $postId);
            }
        }

        // Blog / front indexes often list the post.
        if (function_exists('ap_home_url')) {
            $home = ap_home_url('/');
            if (is_string($home) && $home !== '') {
                $urls[] = $home;
            }
        }

        if (function_exists('ap_apply_filters')) {
            $filtered = ap_apply_filters('ap_page_cache_urls_for_post', $urls, $postId);
            if (is_array($filtered)) {
                $urls = [];
                foreach ($filtered as $u) {
                    if (is_string($u) && trim($u) !== '') {
                        $urls[] = trim($u);
                    }
                }
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * Register automatic invalidation on content lifecycle actions.
     *
     * Idempotent. Call after the hook system is available (bootstrap).
     */
    public static function registerInvalidationHooks(): void
    {
        if (self::$invalidationRegistered || !function_exists('ap_add_action')) {
            return;
        }
        self::$invalidationRegistered = true;

        // Blog posts / pages.
        ap_add_action('ap_post_inserted', [self::class, 'onPostChanged'], 10, 1);
        ap_add_action('ap_post_updated', [self::class, 'onPostChanged'], 10, 1);
        ap_add_action('ap_post_trashed', [self::class, 'onPostChanged'], 10, 1);
        ap_add_action('ap_post_untrashed', [self::class, 'onPostChanged'], 10, 1);
        ap_add_action('ap_post_deleted', [self::class, 'onPostChanged'], 10, 1);

        // Comments affect the parent post page.
        ap_add_action('ap_comment_inserted', [self::class, 'onCommentInserted'], 10, 2);
        ap_add_action('ap_comment_updated', [self::class, 'onCommentChanged'], 10, 2);
        ap_add_action('ap_comment_deleted', [self::class, 'onCommentDeleted'], 10, 2);
        ap_add_action('ap_comment_status_changed', [self::class, 'onCommentChanged'], 10, 2);

        // Forums / topics / replies.
        ap_add_action('ap_forum_inserted', [self::class, 'onForumChanged'], 10, 1);
        ap_add_action('ap_forum_updated', [self::class, 'onForumChanged'], 10, 1);
        ap_add_action('ap_forum_deleted', [self::class, 'onForumDeleted'], 10, 1);
        ap_add_action('ap_topic_created', [self::class, 'onTopicChanged'], 10, 2);
        ap_add_action('ap_topic_updated', [self::class, 'onTopicChanged'], 10, 2);
        ap_add_action('ap_topic_deleted', [self::class, 'onTopicDeleted'], 10, 2);
        ap_add_action('ap_forum_post_inserted', [self::class, 'onForumPostChanged'], 10, 2);
        ap_add_action('ap_forum_post_updated', [self::class, 'onForumPostChanged'], 10, 2);
        ap_add_action('ap_forum_post_deleted', [self::class, 'onForumPostDeleted'], 10, 1);
        ap_add_action('ap_moderation_topic_soft_deleted', [self::class, 'onTopicDeleted'], 10, 1);
        ap_add_action('ap_moderation_topic_restored', [self::class, 'onTopicIdOnly'], 10, 1);
        ap_add_action('ap_moderation_post_soft_deleted', [self::class, 'onForumPostDeleted'], 10, 1);
        ap_add_action('ap_moderation_post_restored', [self::class, 'onForumPostDeleted'], 10, 1);
        ap_add_action('ap_moderation_topic_approved', [self::class, 'onTopicIdOnly'], 10, 1);
        ap_add_action('ap_moderation_topic_unapproved', [self::class, 'onTopicIdOnly'], 10, 1);

        // Theme / plugin changes often alter every public page.
        ap_add_action('ap_switch_theme', [self::class, 'onGlobalLayoutChange'], 10, 0);
        ap_add_action('ap_activate_plugin', [self::class, 'onGlobalLayoutChange'], 10, 0);
        ap_add_action('ap_deactivate_plugin', [self::class, 'onGlobalLayoutChange'], 10, 0);
    }

    /**
     * Reset static state (tests).
     */
    public static function reset(): void
    {
        self::$dropinLoaded = false;
        self::$started = false;
        self::$invalidationRegistered = false;
        self::$skipRequest = false;
    }

    // -------------------------------------------------------------------------
    // Action callbacks
    // -------------------------------------------------------------------------

    /**
     * @param int|object $postIdOrPost
     */
    public static function onPostChanged(mixed $postIdOrPost): void
    {
        $id = self::resolveId($postIdOrPost);
        if ($id > 0) {
            self::cleanPost($id);
        }
    }

    /**
     * @param int         $commentId
     * @param object|null $comment
     */
    public static function onCommentInserted(int $commentId, mixed $comment = null): void
    {
        unset($commentId);
        $postId = self::commentPostId($comment);
        if ($postId > 0) {
            self::cleanPost($postId);
        }
    }

    /**
     * @param int         $commentId
     * @param object|null $comment
     */
    public static function onCommentChanged(int $commentId, mixed $comment = null): void
    {
        self::onCommentInserted($commentId, $comment);
    }

    /**
     * @param int         $commentId
     * @param int|object  $postIdOrComment  Post ID or deleted comment object.
     */
    public static function onCommentDeleted(int $commentId, mixed $postIdOrComment = null): void
    {
        unset($commentId);
        if (is_int($postIdOrComment) || (is_string($postIdOrComment) && ctype_digit($postIdOrComment))) {
            $postId = (int) $postIdOrComment;
        } else {
            $postId = self::commentPostId($postIdOrComment);
        }
        if ($postId > 0) {
            self::cleanPost($postId);
        }
    }

    /**
     * @param int|object $forumIdOrForum
     */
    public static function onForumChanged(mixed $forumIdOrForum): void
    {
        $id = self::resolveId($forumIdOrForum);
        if ($id > 0) {
            self::cleanForum($id);
        }
    }

    public static function onForumDeleted(int $forumId): void
    {
        if ($forumId > 0) {
            // Forum row may be gone; still fire purge with known ID + index.
            if (function_exists('ap_do_action')) {
                ap_do_action('ap_page_cache_purge_forum', $forumId, []);
            }
            if (function_exists('ap_forums_url')) {
                self::purgeUrl((string) ap_forums_url());
            }
        }
    }

    /**
     * @param int         $topicId
     * @param object|null $topic
     */
    public static function onTopicChanged(int $topicId, mixed $topic = null): void
    {
        $forumId = null;
        if (is_object($topic) && isset($topic->forum_id)) {
            $forumId = (int) $topic->forum_id;
        }
        self::cleanTopic($topicId, $forumId);
    }

    /**
     * @param int  $topicId
     * @param bool $force
     */
    public static function onTopicDeleted(int $topicId, mixed $force = null): void
    {
        unset($force);
        self::cleanTopic($topicId, null);
    }

    public static function onTopicIdOnly(int $topicId): void
    {
        self::cleanTopic($topicId, null);
    }

    /**
     * @param int         $postId  Forum post (reply) ID.
     * @param object|null $post
     */
    public static function onForumPostChanged(int $postId, mixed $post = null): void
    {
        unset($postId);
        $topicId = 0;
        $forumId = null;
        if (is_object($post)) {
            if (isset($post->topic_id)) {
                $topicId = (int) $post->topic_id;
            }
            if (isset($post->forum_id)) {
                $forumId = (int) $post->forum_id;
            }
        }
        if ($topicId > 0) {
            self::cleanTopic($topicId, $forumId);
        }
    }

    public static function onForumPostDeleted(int $postId): void
    {
        if (
            $postId > 0
            && class_exists('AP_Forum', false)
            && method_exists('AP_Forum', 'getPost')
        ) {
            $post = AP_Forum::getPost($postId);
            if (is_object($post) && isset($post->topic_id)) {
                $forumId = isset($post->forum_id) ? (int) $post->forum_id : null;
                self::cleanTopic((int) $post->topic_id, $forumId);

                return;
            }
        }

        // Soft-deleted or already gone: flush whole page cache as a safe fallback
        // is too aggressive; signal a generic topic-unknown purge via full flush
        // only when we truly cannot resolve context.
        if (function_exists('ap_do_action')) {
            ap_do_action('ap_page_cache_purge_forum_post', $postId);
        }
    }

    public static function onGlobalLayoutChange(): void
    {
        self::clean(null);
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    private static function resolveId(mixed $idOrObject): int
    {
        if (is_int($idOrObject)) {
            return $idOrObject;
        }
        if (is_string($idOrObject) && ctype_digit($idOrObject)) {
            return (int) $idOrObject;
        }
        if (is_object($idOrObject)) {
            if (isset($idOrObject->ID)) {
                return (int) $idOrObject->ID;
            }
            if (isset($idOrObject->id)) {
                return (int) $idOrObject->id;
            }
            if (isset($idOrObject->forum_id) && !isset($idOrObject->topic_id)) {
                return (int) $idOrObject->forum_id;
            }
        }

        return 0;
    }

    private static function commentPostId(mixed $comment): int
    {
        if (is_object($comment) && isset($comment->comment_post_ID)) {
            return (int) $comment->comment_post_ID;
        }

        return 0;
    }
}

// -----------------------------------------------------------------------------
// Early bootstrap helpers (available before functions.php loads)
// -----------------------------------------------------------------------------

/**
 * Start page-cache support (advanced-cache drop-in when AP_CACHE is true).
 */
function ap_start_page_cache(): void
{
    AP_Page_Cache::start();
}

/**
 * Absolute path to advanced-cache.php (or empty).
 */
function ap_page_cache_dropin_path(): string
{
    return AP_Page_Cache::dropinPath();
}

/**
 * Register automatic invalidation listeners (also done from bootstrap).
 */
function ap_register_page_cache_invalidation(): void
{
    AP_Page_Cache::registerInvalidationHooks();
}

/**
 * Reset page-cache static state (tests).
 */
function ap_reset_page_cache(): void
{
    AP_Page_Cache::reset();
}
