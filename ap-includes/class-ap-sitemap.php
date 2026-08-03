<?php

/**
 * AgoraPress XML sitemaps and virtual robots.txt.
 *
 * Public endpoints:
 *   - /sitemap.xml or ?sitemap=index — sitemap index
 *   - /sitemap-{type}.xml or /sitemap-{type}-{page}.xml — provider lists
 *   - /robots.txt or ?robots=1 — robots.txt with Sitemap: line when public
 *
 * Providers (module-aware): posts, pages, categories, tags, forums, topics.
 * Max URLs per sitemap: 2000. Respects blog_public (discourage search engines).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * Build and emit XML sitemaps + robots.txt.
 */
class AP_Sitemap
{
    /** Max URL entries per child sitemap (sitemap protocol soft limit is 50k). */
    public const MAX_URLS = 2000;

    /** Option: site is indexable by search engines (1) or discouraged (0). */
    public const OPTION_BLOG_PUBLIC = 'blog_public';

    /** Option: enable XML sitemaps (default on). */
    public const OPTION_SITEMAP_ENABLED = 'sitemap_enabled';

    /**
     * Known provider slugs.
     *
     * @var list<string>
     */
    public const PROVIDERS = [
        'posts',
        'pages',
        'categories',
        'tags',
        'forums',
        'topics',
    ];

    /**
     * Whether sitemap generation is enabled (option + filter).
     */
    public static function isEnabled(?AP_DB $db = null): bool
    {
        $enabled = true;
        if (class_exists('AP_Options', false)) {
            $enabled = (string) AP_Options::get(self::OPTION_SITEMAP_ENABLED, '1', $db) !== '0';
        }
        if (function_exists('ap_apply_filters')) {
            $enabled = (bool) ap_apply_filters('ap_sitemaps_enabled', $enabled, $db);
        }

        return $enabled;
    }

    /**
     * Whether the site allows search-engine indexing (blog_public).
     */
    public static function isPublic(?AP_DB $db = null): bool
    {
        if (class_exists('AP_Options', false)) {
            return (string) AP_Options::get(self::OPTION_BLOG_PUBLIC, '1', $db) !== '0';
        }

        return true;
    }

    /**
     * Whether the given rewrite/query vars request a sitemap.
     *
     * @param array<string, mixed> $vars
     */
    public static function isSitemapRequest(array $vars): bool
    {
        if (!isset($vars['sitemap'])) {
            return false;
        }
        $s = $vars['sitemap'];
        if (is_bool($s)) {
            return $s;
        }
        if (is_string($s)) {
            return trim($s) !== '';
        }

        return false;
    }

    /**
     * Whether the request is for robots.txt.
     *
     * @param array<string, mixed> $vars
     */
    public static function isRobotsRequest(array $vars): bool
    {
        if (!isset($vars['robots'])) {
            return false;
        }
        $r = $vars['robots'];
        if (is_bool($r)) {
            return $r;
        }
        if (is_string($r) || is_int($r)) {
            return (string) $r !== '' && (string) $r !== '0';
        }

        return false;
    }

    /**
     * Public sitemap URL for a provider (or index when $type is empty / index).
     *
     * Pretty paths (/sitemap.xml, /sitemap-posts.xml) are preferred when permalinks
     * are on; plain query-string URLs work without pretty permalinks. Path-based
     * sitemap routes are still recognized by AP_Rewrite even in plain mode.
     */
    public static function getSitemapLink(string $type = 'index', int $page = 1, ?AP_DB $db = null): string
    {
        $type = self::normalizeType($type);
        $page = max(1, $page);
        $pretty = class_exists('AP_Rewrite', false) && AP_Rewrite::usingPermalinks($db);

        if ($type === 'index') {
            return $pretty
                ? self::homeUrl($db) . '/sitemap.xml'
                : self::homeUrl($db) . '/?sitemap=index';
        }

        if (!$pretty) {
            $qs = 'sitemap=' . rawurlencode($type);
            if ($page > 1) {
                $qs .= '&sitemap_page=' . $page;
            }

            return self::homeUrl($db) . '/?' . $qs;
        }

        $suffix = $page > 1 ? '-' . $page : '';

        return self::homeUrl($db) . '/sitemap-' . $type . $suffix . '.xml';
    }

    /**
     * Emit sitemap (or robots) and stop PHP.
     *
     * @param array<string, mixed> $vars
     *
     * @return never|string When $exit is false, returns the body.
     */
    public static function serve(array $vars = [], ?AP_DB $db = null, bool $exit = true): string
    {
        if (self::isRobotsRequest($vars)) {
            $body = self::buildRobots($db);
            if (!headers_sent()) {
                header('Content-Type: text/plain; charset=UTF-8');
                header('X-Content-Type-Options: nosniff');
                http_response_code(200);
            }
            echo $body;
            if ($exit) {
                exit(0);
            }

            return $body;
        }

        if (!self::isEnabled($db) || !self::isPublic($db)) {
            $body = self::disabledBody();
            if (!headers_sent()) {
                header('Content-Type: text/plain; charset=UTF-8');
                header('X-Robots-Tag: noindex');
                http_response_code(404);
            }
            echo $body;
            if ($exit) {
                exit(0);
            }

            return $body;
        }

        $type = self::normalizeType(isset($vars['sitemap']) ? (string) $vars['sitemap'] : 'index');
        $page = isset($vars['sitemap_page']) ? max(1, (int) $vars['sitemap_page']) : 1;

        $xml = $type === 'index'
            ? self::buildIndex($db)
            : self::buildProvider($type, $page, $db);

        if (!headers_sent()) {
            header('Content-Type: application/xml; charset=UTF-8');
            header('X-Content-Type-Options: nosniff');
            http_response_code(200);
        }

        echo $xml;

        if ($exit) {
            exit(0);
        }

        return $xml;
    }

    /**
     * Build sitemap index listing available provider sitemaps.
     */
    public static function buildIndex(?AP_DB $db = null): string
    {
        $entries = [];
        foreach (self::activeProviders($db) as $provider) {
            $total = self::countProvider($provider, $db);
            if ($total < 1) {
                continue;
            }
            $pages = max(1, (int) ceil($total / self::MAX_URLS));
            for ($p = 1; $p <= $pages; $p++) {
                $entries[] = [
                    'loc' => self::getSitemapLink($provider, $p, $db),
                    'lastmod' => self::providerLastmod($provider, $db),
                ];
            }
        }

        // Valid empty index when the site has no public URLs yet.
        if ($entries === []) {
            $entries[] = [
                'loc' => self::getSitemapLink('posts', 1, $db),
                'lastmod' => gmdate('Y-m-d\TH:i:s\Z'),
            ];
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($entries as $entry) {
            $xml .= "  <sitemap>\n";
            $xml .= '    <loc>' . self::xmlText($entry['loc']) . "</loc>\n";
            if ($entry['lastmod'] !== '') {
                $xml .= '    <lastmod>' . self::xmlText($entry['lastmod']) . "</lastmod>\n";
            }
            $xml .= "  </sitemap>\n";
        }
        $xml .= "</sitemapindex>\n";

        return $xml;
    }

    /**
     * Build a provider urlset for the given page.
     */
    public static function buildProvider(string $type, int $page = 1, ?AP_DB $db = null): string
    {
        $type = self::normalizeType($type);
        $page = max(1, $page);
        $urls = self::fetchProviderUrls($type, $page, $db);

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($urls as $row) {
            $xml .= "  <url>\n";
            $xml .= '    <loc>' . self::xmlText($row['loc']) . "</loc>\n";
            if (!empty($row['lastmod'])) {
                $xml .= '    <lastmod>' . self::xmlText((string) $row['lastmod']) . "</lastmod>\n";
            }
            $xml .= "  </url>\n";
        }
        $xml .= "</urlset>\n";

        return $xml;
    }

    /**
     * Build robots.txt body.
     */
    public static function buildRobots(?AP_DB $db = null): string
    {
        $lines = [];
        $lines[] = 'User-agent: *';

        if (!self::isPublic($db)) {
            $lines[] = 'Disallow: /';
        } else {
            $lines[] = 'Disallow: /ap-admin/';
            $lines[] = 'Disallow: /install/';
            $lines[] = 'Allow: /';
            if (self::isEnabled($db)) {
                $lines[] = '';
                $lines[] = 'Sitemap: ' . self::getSitemapLink('index', 1, $db);
            }
        }

        if (function_exists('ap_apply_filters')) {
            $filtered = ap_apply_filters('ap_robots_txt', implode("\n", $lines) . "\n", $db);
            if (is_string($filtered)) {
                return $filtered;
            }
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Provider slugs that should appear for the current module configuration.
     *
     * @return list<string>
     */
    public static function activeProviders(?AP_DB $db = null): array
    {
        $out = [];
        $blog = self::moduleOn('blog', $db);
        $pages = self::moduleOn('static_pages', $db);
        $forum = self::moduleOn('forum', $db);

        if ($blog) {
            $out[] = 'posts';
            $out[] = 'categories';
            $out[] = 'tags';
        }
        if ($pages) {
            $out[] = 'pages';
        }
        if ($forum) {
            $out[] = 'forums';
            $out[] = 'topics';
        }

        // At least one provider when all modules off is unlikely (installer requires ≥1).
        if ($out === []) {
            $out[] = 'posts';
        }

        if (function_exists('ap_apply_filters')) {
            $filtered = ap_apply_filters('ap_sitemap_providers', $out, $db);
            if (is_array($filtered)) {
                $clean = [];
                foreach ($filtered as $p) {
                    if (is_string($p) && $p !== '') {
                        $clean[] = self::normalizeType($p);
                    }
                }
                if ($clean !== []) {
                    return array_values(array_unique($clean));
                }
            }
        }

        return $out;
    }

    /**
     * Count URLs available for a provider.
     */
    public static function countProvider(string $type, ?AP_DB $db = null): int
    {
        $type = self::normalizeType($type);

        return match ($type) {
            'posts' => self::countPosts('post', $db),
            'pages' => self::countPosts('page', $db),
            'categories' => self::countTerms('category', $db),
            'tags' => self::countTerms('post_tag', $db),
            'forums' => self::countForums($db),
            'topics' => self::countTopics($db),
            default => 0,
        };
    }

    /**
     * @return list<array{loc: string, lastmod?: string}>
     */
    public static function fetchProviderUrls(string $type, int $page, ?AP_DB $db = null): array
    {
        $type = self::normalizeType($type);
        $page = max(1, $page);
        $offset = ($page - 1) * self::MAX_URLS;

        return match ($type) {
            'posts' => self::fetchPostUrls('post', $offset, self::MAX_URLS, $db),
            'pages' => self::fetchPostUrls('page', $offset, self::MAX_URLS, $db),
            'categories' => self::fetchTermUrls('category', $offset, self::MAX_URLS, $db),
            'tags' => self::fetchTermUrls('post_tag', $offset, self::MAX_URLS, $db),
            'forums' => self::fetchForumUrls($offset, self::MAX_URLS, $db),
            'topics' => self::fetchTopicUrls($offset, self::MAX_URLS, $db),
            default => [],
        };
    }

    /**
     * Normalize provider / index type slug.
     */
    public static function normalizeType(string $type): string
    {
        $type = strtolower(trim($type));
        if ($type === '' || $type === 'sitemap' || $type === 'index') {
            return 'index';
        }
        // Allow only known provider characters.
        $type = preg_replace('/[^a-z0-9_\-]/', '', $type) ?? '';
        if ($type === '') {
            return 'index';
        }

        return $type;
    }

    // -------------------------------------------------------------------------
    // Internals — counts & fetchers
    // -------------------------------------------------------------------------

    private static function countPosts(string $postType, ?AP_DB $db): int
    {
        $db = self::resolveDb($db);
        if ($db === null) {
            return 0;
        }
        try {
            $table = $db->quoteIdentifier($db->table('posts'));
            $n = $db->getVar(
                'SELECT COUNT(*) FROM ' . $table
                . ' WHERE post_type = ? AND post_status = ? AND (post_password = ? OR post_password IS NULL)',
                [$postType, 'publish', '']
            );

            return max(0, (int) $n);
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * @return list<array{loc: string, lastmod?: string}>
     */
    private static function fetchPostUrls(string $postType, int $offset, int $limit, ?AP_DB $db): array
    {
        if (!class_exists('AP_Query', false)) {
            return [];
        }
        $page = (int) floor($offset / max(1, $limit)) + 1;
        $q = new AP_Query([
            'post_type' => $postType,
            'post_status' => 'publish',
            'posts_per_page' => $limit,
            'paged' => $page,
            'orderby' => 'modified',
            'order' => 'DESC',
            'ignore_sticky_posts' => true,
            'no_found_rows' => true,
        ], $db);

        $out = [];
        foreach ($q->posts as $post) {
            if (!$post instanceof AP_Post) {
                continue;
            }
            // Skip password-protected content from sitemaps.
            if ((string) $post->post_password !== '') {
                continue;
            }
            $link = self::permalink($post, $db);
            if ($link === '') {
                continue;
            }
            $last = self::w3cDate((string) $post->post_modified_gmt, (string) $post->post_modified);
            $row = ['loc' => $link];
            if ($last !== '') {
                $row['lastmod'] = $last;
            }
            $out[] = $row;
        }

        return $out;
    }

    private static function countTerms(string $taxonomy, ?AP_DB $db): int
    {
        if (!class_exists('AP_Taxonomy', false)) {
            return 0;
        }
        $terms = AP_Taxonomy::getTerms($taxonomy, [
            'hide_empty' => true,
            'number' => 0,
        ], $db);

        return count($terms);
    }

    /**
     * @return list<array{loc: string, lastmod?: string}>
     */
    private static function fetchTermUrls(string $taxonomy, int $offset, int $limit, ?AP_DB $db): array
    {
        if (!class_exists('AP_Taxonomy', false)) {
            return [];
        }
        $terms = AP_Taxonomy::getTerms($taxonomy, [
            'hide_empty' => true,
            'number' => $limit,
            'offset' => $offset,
            'orderby' => 'name',
            'order' => 'ASC',
        ], $db);

        $out = [];
        foreach ($terms as $term) {
            if (!is_object($term)) {
                continue;
            }
            $link = '';
            if (class_exists('AP_Rewrite', false)) {
                $link = AP_Rewrite::getTermLink($term, $taxonomy, $db);
            }
            if ($link === '') {
                continue;
            }
            $out[] = ['loc' => $link];
        }

        return $out;
    }

    private static function countForums(?AP_DB $db): int
    {
        if (!class_exists('AP_Forum', false)) {
            return 0;
        }
        try {
            // Default excludes hidden; include open + closed public forums.
            $forums = AP_Forum::getForums([], $db);

            return is_array($forums) ? count($forums) : 0;
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * @return list<array{loc: string, lastmod?: string}>
     */
    private static function fetchForumUrls(int $offset, int $limit, ?AP_DB $db): array
    {
        if (!class_exists('AP_Forum', false)) {
            return [];
        }
        try {
            $forums = AP_Forum::getForums([], $db);
            if (!is_array($forums)) {
                return [];
            }
            $rows = [];
            if ($offset === 0) {
                $index = AP_Forum::forumsIndexUrl();
                if ($index !== '') {
                    $rows[] = ['loc' => $index];
                }
            }
            $start = $offset === 0 ? 0 : $offset;
            // Page 1 reserves one slot for the forums index when present.
            $take = $offset === 0 ? max(0, $limit - count($rows)) : $limit;
            $slice = array_slice($forums, $start, $take);
            foreach ($slice as $forum) {
                if (!is_object($forum)) {
                    continue;
                }
                $type = isset($forum->forum_type) ? (string) $forum->forum_type : 'forum';
                if ($type === 'link') {
                    continue;
                }
                $url = AP_Forum::forumUrl($forum);
                if ($url !== '') {
                    $rows[] = ['loc' => $url];
                }
            }

            return $rows;
        } catch (Throwable) {
            return [];
        }
    }

    private static function countTopics(?AP_DB $db): int
    {
        if (!class_exists('AP_Forum', false) || !class_exists('AP_DB', false)) {
            return 0;
        }
        try {
            $db = self::resolveDb($db);
            if ($db === null) {
                return 0;
            }
            $table = $db->quoteIdentifier($db->table('topics'));
            $n = $db->getVar(
                'SELECT COUNT(*) FROM ' . $table
                . ' WHERE topic_approved = 1 AND topic_status != ?',
                ['deleted']
            );

            return max(0, (int) $n);
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * @return list<array{loc: string, lastmod?: string}>
     */
    private static function fetchTopicUrls(int $offset, int $limit, ?AP_DB $db): array
    {
        if (!class_exists('AP_Forum', false)) {
            return [];
        }
        try {
            $page = (int) floor($offset / max(1, $limit)) + 1;
            $topics = AP_Forum::queryTopics([
                'approved_only' => true,
                'include_deleted' => false,
                'per_page' => $limit,
                'page' => $page,
                'orderby' => 'last_post',
                'order' => 'DESC',
            ], $db);
            $out = [];
            foreach ($topics as $topic) {
                if (!is_object($topic)) {
                    continue;
                }
                $url = AP_Forum::topicUrl($topic);
                if ($url === '') {
                    continue;
                }
                $last = '';
                if (isset($topic->topic_last_post_time) && (string) $topic->topic_last_post_time !== '') {
                    $last = self::w3cDate((string) $topic->topic_last_post_time, (string) $topic->topic_last_post_time);
                } elseif (isset($topic->topic_time)) {
                    $last = self::w3cDate((string) $topic->topic_time, (string) $topic->topic_time);
                }
                $row = ['loc' => $url];
                if ($last !== '') {
                    $row['lastmod'] = $last;
                }
                $out[] = $row;
            }

            return $out;
        } catch (Throwable) {
            return [];
        }
    }

    private static function providerLastmod(string $provider, ?AP_DB $db): string
    {
        // Lightweight: current UTC time is acceptable for index lastmod.
        unset($provider, $db);

        return gmdate('Y-m-d\TH:i:s\Z');
    }

    private static function moduleOn(string $module, ?AP_DB $db): bool
    {
        if (class_exists('AP_Options', false) && method_exists('AP_Options', 'isModuleEnabled')) {
            return AP_Options::isModuleEnabled($module, $db);
        }
        if (function_exists('ap_is_module_enabled')) {
            return ap_is_module_enabled($module, $db);
        }

        return true;
    }

    private static function permalink(AP_Post $post, ?AP_DB $db): string
    {
        if (function_exists('ap_get_permalink') && class_exists('AP_Rewrite', false)) {
            return ap_get_permalink($post, $db);
        }
        if (class_exists('AP_Rewrite', false)) {
            return AP_Rewrite::getPermalink($post, $db);
        }

        return self::homeUrl($db) . '/?p=' . (int) $post->ID;
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

    private static function xmlText(string $text): string
    {
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function disabledBody(): string
    {
        return "Sitemap is disabled or this site discourages search engines.\n";
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
