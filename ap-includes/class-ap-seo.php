<?php

/**
 * AgoraPress SEO head tags: canonical URLs and Open Graph.
 *
 * Hooks into `ap_head` (via {@see AP_Seo::register()}) to print:
 *   - <link rel="canonical" href="…">
 *   - Open Graph meta (og:title, og:type, og:url, og:description, og:site_name, og:image)
 *   - Optional Twitter Card tags (summary / summary_large_image)
 *   - robots noindex when blog_public is off
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * Canonical + Open Graph meta for front-end HTML responses.
 */
class AP_Seo
{
    /** Option: enable Open Graph tags (default on). */
    public const OPTION_OG_ENABLED = 'open_graph_enabled';

    /** Whether head tags were registered this request. */
    private static bool $registered = false;

    /**
     * Register ap_head printers (idempotent).
     */
    public static function register(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;

        if (!function_exists('ap_add_action')) {
            return;
        }

        ap_add_action('ap_head', [self::class, 'printHeadTags'], 1);
    }

    /**
     * Reset registration flag (tests).
     */
    public static function reset(): void
    {
        self::$registered = false;
    }

    /**
     * Print canonical, robots, and Open Graph tags for the main query.
     */
    public static function printHeadTags(): void
    {
        $db = self::resolveDb(null);
        $query = self::mainQuery();

        // Discourage indexing when the site is private.
        if (class_exists('AP_Sitemap', false) && !AP_Sitemap::isPublic($db)) {
            echo '<meta name="robots" content="noindex, nofollow">' . "\n";
        }

        $canonical = self::getCanonicalUrl($query, $db);
        if ($canonical !== '') {
            $href = self::escUrl($canonical);
            echo '<link rel="canonical" href="' . $href . '">' . "\n";
        }

        if (!self::isOpenGraphEnabled($db)) {
            return;
        }

        $meta = self::getOpenGraphMeta($query, $db);
        foreach ($meta as $property => $content) {
            if ($content === '') {
                continue;
            }
            $prop = self::escAttr($property);
            $val = self::escAttr($content);
            // Twitter uses name=; Open Graph uses property=.
            if (str_starts_with($property, 'twitter:')) {
                echo '<meta name="' . $prop . '" content="' . $val . '">' . "\n";
            } else {
                echo '<meta property="' . $prop . '" content="' . $val . '">' . "\n";
            }
        }
    }

    /**
     * Whether Open Graph output is enabled.
     */
    public static function isOpenGraphEnabled(?AP_DB $db = null): bool
    {
        $enabled = true;
        if (class_exists('AP_Options', false)) {
            $enabled = (string) AP_Options::get(self::OPTION_OG_ENABLED, '1', $db) !== '0';
        }
        if (function_exists('ap_apply_filters')) {
            $enabled = (bool) ap_apply_filters('ap_open_graph_enabled', $enabled, $db);
        }

        return $enabled;
    }

    /**
     * Canonical URL for the current (or given) query.
     */
    public static function getCanonicalUrl(?AP_Query $query = null, ?AP_DB $db = null): string
    {
        $query = $query ?? self::mainQuery();
        $db = self::resolveDb($db);
        $url = '';

        if ($query instanceof AP_Query) {
            if (!empty($query->is_404)) {
                $url = '';
            } elseif (!empty($query->is_singular) && $query->post instanceof AP_Post) {
                $url = self::postPermalink($query->post, $db);
                $page = max(1, (int) ($query->query_vars['page'] ?? 1));
                if ($page > 1 && $url !== '') {
                    $url = self::appendPagination($url, $page, $db);
                }
            } elseif (!empty($query->is_front_page) || (!empty($query->is_home) && empty($query->is_posts_page))) {
                $url = self::homeUrl($db) . '/';
            } elseif (!empty($query->is_posts_page)) {
                $pageId = 0;
                if (class_exists('AP_Options', false)) {
                    $pageId = (int) AP_Options::get('page_for_posts', 0, $db);
                }
                if ($pageId > 0 && class_exists('AP_Post', false)) {
                    $pagePost = AP_Post::get($pageId, $db);
                    if ($pagePost instanceof AP_Post) {
                        $url = self::postPermalink($pagePost, $db);
                    }
                }
                if ($url === '') {
                    $url = self::homeUrl($db) . '/';
                }
            } elseif (!empty($query->is_category) || !empty($query->is_tag) || !empty($query->is_tax)) {
                $url = self::termCanonical($query, $db);
            } elseif (!empty($query->is_author)) {
                $author = (string) ($query->query_vars['author_name'] ?? '');
                if ($author !== '' && class_exists('AP_Rewrite', false)) {
                    $url = AP_Rewrite::getAuthorLink($author, $db);
                }
            } elseif (!empty($query->is_search)) {
                // Prefer not to canonicalize search (thin/duplicate); leave empty.
                $url = '';
            } elseif (
                class_exists('AP_Forum_Front', false)
                && method_exists('AP_Forum_Front', 'isForumRequest')
                && AP_Forum_Front::isForumRequest($query)
            ) {
                $url = self::forumCanonical($query, $db);
            } elseif (!empty($query->is_home)) {
                $url = self::homeUrl($db) . '/';
            }

            // Archive pagination (blog index, tax, author, date).
            if ($url !== '' && empty($query->is_singular)) {
                $paged = max(1, (int) ($query->query_vars['paged'] ?? 1));
                if ($paged > 1) {
                    $url = self::appendPagination($url, $paged, $db);
                }
            }
        }

        if ($url === '') {
            // Fallback: current home (avoid empty on unknown views that still render).
            if ($query instanceof AP_Query && empty($query->is_404) && empty($query->is_search)) {
                $url = self::homeUrl($db) . '/';
            }
        }

        if (function_exists('ap_apply_filters')) {
            $filtered = ap_apply_filters('ap_canonical_url', $url, $query, $db);
            if (is_string($filtered)) {
                $url = $filtered;
            }
        }

        return $url;
    }

    /**
     * Open Graph (+ Twitter) meta map for the current view.
     *
     * @return array<string, string> property/name => content
     */
    public static function getOpenGraphMeta(?AP_Query $query = null, ?AP_DB $db = null): array
    {
        $query = $query ?? self::mainQuery();
        $db = self::resolveDb($db);

        $siteName = self::siteTitle($db);
        $siteDesc = self::siteDescription($db);
        $canonical = self::getCanonicalUrl($query, $db);
        $title = $siteName;
        $description = $siteDesc;
        $type = 'website';
        $image = '';
        $extra = [];

        if ($query instanceof AP_Query && !empty($query->is_singular) && $query->post instanceof AP_Post) {
            $post = $query->post;
            $postTitle = trim((string) $post->post_title);
            if ($postTitle !== '') {
                $title = $postTitle;
                if ($siteName !== '' && !str_contains($postTitle, $siteName)) {
                    $title = $postTitle . ' — ' . $siteName;
                }
            }
            $description = self::excerptForMeta($post);
            $type = ((string) $post->post_type === 'page') ? 'website' : 'article';
            $image = self::featuredImageUrl($post, $db);

            if ($type === 'article') {
                $pub = self::w3cDate((string) $post->post_date_gmt, (string) $post->post_date);
                $mod = self::w3cDate((string) $post->post_modified_gmt, (string) $post->post_modified);
                if ($pub !== '') {
                    $extra['article:published_time'] = $pub;
                }
                if ($mod !== '') {
                    $extra['article:modified_time'] = $mod;
                }
            }
        } elseif (
            $query instanceof AP_Query
            && class_exists('AP_Forum_Front', false)
            && method_exists('AP_Forum_Front', 'isForumRequest')
            && AP_Forum_Front::isForumRequest($query)
        ) {
            $type = 'website';
            $forumTitle = self::forumTitle($query);
            if ($forumTitle !== '') {
                $title = $forumTitle . ($siteName !== '' ? ' — ' . $siteName : '');
            }
        } elseif ($query instanceof AP_Query && !empty($query->is_search)) {
            $s = trim((string) ($query->query_vars['s'] ?? ''));
            $title = $s !== '' ? ('Search: ' . $s . ' — ' . $siteName) : ('Search — ' . $siteName);
        } elseif ($query instanceof AP_Query && (!empty($query->is_category) || !empty($query->is_tag))) {
            $termName = self::queriedTermName($query);
            if ($termName !== '') {
                $title = $termName . ' — ' . $siteName;
            }
        }

        $meta = [
            'og:title' => $title !== '' ? $title : $siteName,
            'og:type' => $type,
            'og:url' => $canonical,
            'og:description' => $description,
            'og:site_name' => $siteName,
            'og:locale' => function_exists('ap_get_og_locale')
                ? ap_get_og_locale()
                : (class_exists('AP_L10n', false) ? AP_L10n::localeToOgLocale() : 'en_US'),
        ];
        if ($image !== '') {
            $meta['og:image'] = $image;
        }
        foreach ($extra as $k => $v) {
            $meta[$k] = $v;
        }

        // Twitter Cards (lightweight companion to OG).
        $meta['twitter:card'] = $image !== '' ? 'summary_large_image' : 'summary';
        $meta['twitter:title'] = $meta['og:title'];
        if ($description !== '') {
            $meta['twitter:description'] = $description;
        }
        if ($image !== '') {
            $meta['twitter:image'] = $image;
        }

        if (function_exists('ap_apply_filters')) {
            $filtered = ap_apply_filters('ap_open_graph_meta', $meta, $query, $db);
            if (is_array($filtered)) {
                $clean = [];
                foreach ($filtered as $k => $v) {
                    if (is_string($k) && (is_string($v) || is_numeric($v))) {
                        $clean[$k] = (string) $v;
                    }
                }
                $meta = $clean;
            }
        }

        return $meta;
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    private static function mainQuery(): ?AP_Query
    {
        if (isset($GLOBALS['ap_query']) && $GLOBALS['ap_query'] instanceof AP_Query) {
            return $GLOBALS['ap_query'];
        }

        return null;
    }

    private static function postPermalink(AP_Post $post, ?AP_DB $db): string
    {
        if (function_exists('ap_get_permalink')) {
            return ap_get_permalink($post, $db);
        }
        if (class_exists('AP_Rewrite', false)) {
            return AP_Rewrite::getPermalink($post, $db);
        }

        return self::homeUrl($db) . '/?p=' . (int) $post->ID;
    }

    private static function termCanonical(AP_Query $query, ?AP_DB $db): string
    {
        if (!class_exists('AP_Rewrite', false) || !class_exists('AP_Taxonomy', false)) {
            return '';
        }
        $tax = '';
        $slug = '';
        if (!empty($query->is_category)) {
            $tax = 'category';
            $slug = (string) ($query->query_vars['category_name'] ?? '');
            $catId = (int) ($query->query_vars['cat'] ?? 0);
            if ($slug === '' && $catId > 0) {
                $term = AP_Taxonomy::getTerm($catId, 'category', $db);
                if (is_object($term)) {
                    return AP_Rewrite::getTermLink($term, 'category', $db);
                }
            }
        } elseif (!empty($query->is_tag)) {
            $tax = 'post_tag';
            $slug = (string) ($query->query_vars['tag'] ?? '');
            $tagId = (int) ($query->query_vars['tag_id'] ?? 0);
            if ($slug === '' && $tagId > 0) {
                $term = AP_Taxonomy::getTerm($tagId, 'post_tag', $db);
                if (is_object($term)) {
                    return AP_Rewrite::getTermLink($term, 'post_tag', $db);
                }
            }
        } else {
            return '';
        }

        if ($slug === '') {
            return '';
        }

        $term = AP_Taxonomy::getTermBySlug($slug, $tax, $db);
        if (!is_object($term)) {
            return '';
        }

        return AP_Rewrite::getTermLink($term, $tax, $db);
    }

    private static function forumCanonical(AP_Query $query, ?AP_DB $db): string
    {
        unset($db);
        if (!class_exists('AP_Forum', false)) {
            return '';
        }
        $view = (string) ($query->query_vars['ap_forum_view'] ?? '');
        if ($view === 'topic' || !empty($query->query_vars['topic_id']) || !empty($query->query_vars['topic_slug'])) {
            $topic = null;
            if (!empty($query->query_vars['topic_id'])) {
                $topic = AP_Forum::getTopic((int) $query->query_vars['topic_id']);
            } elseif (!empty($query->query_vars['topic_slug']) && method_exists('AP_Forum', 'getTopicBySlug')) {
                $topic = AP_Forum::getTopicBySlug((string) $query->query_vars['topic_slug']);
            }
            // Template may attach topic object on query.
            if ($topic === null && isset($query->query_vars['ap_topic']) && is_object($query->query_vars['ap_topic'])) {
                $topic = $query->query_vars['ap_topic'];
            }
            if (is_object($topic)) {
                return AP_Forum::topicUrl($topic);
            }
        }
        if ($view === 'forum' || !empty($query->query_vars['forum_id']) || !empty($query->query_vars['forum_slug'])) {
            $forum = null;
            if (!empty($query->query_vars['forum_id'])) {
                $forum = AP_Forum::getForum((int) $query->query_vars['forum_id']);
            } elseif (!empty($query->query_vars['forum_slug'])) {
                $forum = AP_Forum::getForumBySlug((string) $query->query_vars['forum_slug']);
            }
            if (isset($query->query_vars['ap_forum_obj']) && is_object($query->query_vars['ap_forum_obj'])) {
                $forum = $query->query_vars['ap_forum_obj'];
            }
            if (is_object($forum)) {
                return AP_Forum::forumUrl($forum);
            }
        }
        if ($view === 'index' || $view === '' || $view === 'search') {
            // Search has no stable canonical; index does.
            if ($view === 'search') {
                return '';
            }

            return AP_Forum::forumsIndexUrl();
        }

        return AP_Forum::forumsIndexUrl();
    }

    private static function forumTitle(AP_Query $query): string
    {
        if (isset($query->query_vars['ap_topic']) && is_object($query->query_vars['ap_topic'])) {
            $t = $query->query_vars['ap_topic'];
            if (isset($t->topic_title)) {
                return (string) $t->topic_title;
            }
        }
        if (isset($query->query_vars['ap_forum_obj']) && is_object($query->query_vars['ap_forum_obj'])) {
            $f = $query->query_vars['ap_forum_obj'];
            if (isset($f->forum_name)) {
                return (string) $f->forum_name;
            }
        }
        $view = (string) ($query->query_vars['ap_forum_view'] ?? 'index');
        if ($view === 'index') {
            return 'Forums';
        }

        return '';
    }

    private static function queriedTermName(AP_Query $query): string
    {
        if (!class_exists('AP_Taxonomy', false)) {
            return '';
        }
        if (!empty($query->query_vars['category_name'])) {
            $term = AP_Taxonomy::getTermBySlug((string) $query->query_vars['category_name'], 'category');
            if (is_object($term) && isset($term->name)) {
                return (string) $term->name;
            }
        }
        if (!empty($query->query_vars['tag'])) {
            $term = AP_Taxonomy::getTermBySlug((string) $query->query_vars['tag'], 'post_tag');
            if (is_object($term) && isset($term->name)) {
                return (string) $term->name;
            }
        }

        return '';
    }

    private static function featuredImageUrl(AP_Post $post, ?AP_DB $db): string
    {
        if (!class_exists('AP_Post', false) || !class_exists('AP_Media', false)) {
            return '';
        }
        $thumbId = AP_Post::getMeta((int) $post->ID, '_thumbnail_id', true, $db);
        if ($thumbId === null || $thumbId === '' || (int) $thumbId < 1) {
            return '';
        }
        $url = AP_Media::getAttachmentUrl((int) $thumbId, $db);

        return is_string($url) ? $url : '';
    }

    private static function excerptForMeta(AP_Post $post): string
    {
        $excerpt = trim((string) $post->post_excerpt);
        if ($excerpt === '') {
            $excerpt = trim(strip_tags((string) $post->post_content));
        }
        if ($excerpt === '') {
            return '';
        }
        // Collapse whitespace and limit length for meta descriptions.
        $excerpt = preg_replace('/\s+/u', ' ', $excerpt) ?? $excerpt;
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($excerpt) > 300) {
                $excerpt = rtrim(mb_substr($excerpt, 0, 297)) . '…';
            }
        } elseif (strlen($excerpt) > 300) {
            $excerpt = rtrim(substr($excerpt, 0, 297)) . '…';
        }

        return $excerpt;
    }

    private static function appendPagination(string $url, int $page, ?AP_DB $db): string
    {
        if ($page < 2) {
            return $url;
        }
        $pretty = class_exists('AP_Rewrite', false) && AP_Rewrite::usingPermalinks($db);
        if ($pretty) {
            $url = rtrim($url, '/') . '/page/' . $page . '/';

            return $url;
        }
        $sep = str_contains($url, '?') ? '&' : '?';

        return $url . $sep . 'paged=' . $page;
    }

    private static function siteTitle(?AP_DB $db): string
    {
        if (class_exists('AP_Options', false)) {
            $t = (string) AP_Options::get('blogname', 'AgoraPress', $db);

            return $t !== '' ? $t : 'AgoraPress';
        }

        return 'AgoraPress';
    }

    private static function siteDescription(?AP_DB $db): string
    {
        if (class_exists('AP_Options', false)) {
            return (string) AP_Options::get('blogdescription', '', $db);
        }

        return '';
    }

    private static function homeUrl(?AP_DB $db): string
    {
        if (class_exists('AP_Rewrite', false)) {
            return rtrim(AP_Rewrite::homeUrl('', $db), '/');
        }
        if (defined('AP_SITEURL')) {
            return rtrim((string) AP_SITEURL, '/');
        }

        return '';
    }

    private static function w3cDate(string $gmt, string $local): string
    {
        $src = $gmt !== '' && $gmt !== '0000-00-00 00:00:00' ? $gmt : $local;
        if ($src === '') {
            return '';
        }
        $ts = strtotime($src . (str_contains($src, 'GMT') || str_ends_with($src, 'Z') ? '' : ' UTC'));
        if ($ts === false) {
            $ts = strtotime($src);
        }

        return $ts !== false ? gmdate('Y-m-d\TH:i:s\Z', $ts) : '';
    }

    private static function escUrl(string $url): string
    {
        if (function_exists('ap_esc_url')) {
            return ap_esc_url($url);
        }

        return htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function escAttr(string $text): string
    {
        if (function_exists('ap_esc_attr')) {
            return ap_esc_attr($text);
        }

        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function resolveDb(?AP_DB $db): ?AP_DB
    {
        if ($db instanceof AP_DB) {
            return $db;
        }
        if (isset($GLOBALS['apdb']) && $GLOBALS['apdb'] instanceof AP_DB) {
            return $GLOBALS['apdb'];
        }
        if (function_exists('ap_db')) {
            try {
                return ap_db();
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }
}
