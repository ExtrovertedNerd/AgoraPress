<?php

/**
 * AgoraPress permalinks and rewrite rules.
 *
 * Translates pretty URL paths into AP_Query vars and builds public links for
 * posts, pages, terms, authors, and search. Inspired by classic WordPress
 * rewrite tags — not a fork. The front controller (.htaccess / nginx) still
 * routes every unknown path to index.php; this class interprets the path.
 *
 * Structures (options-permalink.php):
 *   Plain            — empty string → ?p=123 / ?page_id=123
 *   Day and name     — /%year%/%monthnum%/%day%/%postname%/
 *   Month and name   — /%year%/%monthnum%/%postname%/
 *   Numeric          — /archives/%post_id%
 *   Post name        — /%postname%/
 *   Custom           — any combination of supported tags
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * Permalink structure, rule generation, request parsing, and link builders.
 */
class AP_Rewrite
{
    /** Empty structure = plain query-string permalinks. */
    public const STRUCTURE_PLAIN = '';

    public const STRUCTURE_DAY_NAME = '/%year%/%monthnum%/%day%/%postname%/';

    public const STRUCTURE_MONTH_NAME = '/%year%/%monthnum%/%postname%/';

    public const STRUCTURE_NUMERIC = '/archives/%post_id%';

    public const STRUCTURE_POST_NAME = '/%postname%/';

    public const OPTION_STRUCTURE = 'permalink_structure';

    public const OPTION_CATEGORY_BASE = 'category_base';

    public const OPTION_TAG_BASE = 'tag_base';

    public const OPTION_REWRITE_RULES = 'rewrite_rules';

    /** @var array<string, string> Tag token → capturing regex. */
    private const TAG_REGEX = [
        '%year%' => '([0-9]{4})',
        '%monthnum%' => '([0-9]{1,2})',
        '%day%' => '([0-9]{1,2})',
        '%hour%' => '([0-9]{1,2})',
        '%minute%' => '([0-9]{1,2})',
        '%second%' => '([0-9]{1,2})',
        '%post_id%' => '([0-9]+)',
        '%postname%' => '([^/]+)',
        '%category%' => '(.+?)',
        '%author%' => '([^/]+)',
    ];

    /** @var array<string, string> Tag token → query var name. */
    private const TAG_QUERY_VAR = [
        '%year%' => 'year',
        '%monthnum%' => 'monthnum',
        '%day%' => 'day',
        '%hour%' => 'hour',
        '%minute%' => 'minute',
        '%second%' => 'second',
        '%post_id%' => 'p',
        '%postname%' => 'name',
        '%category%' => 'category_name',
        '%author%' => 'author_name',
    ];

    /** @var array<string, string>|null regex => query template (e.g. name=$matches[1]) */
    private static ?array $rules = null;

    /** @var array<string, mixed> Last matched query vars from parseRequest(). */
    private static array $queryVars = [];

    /** Request path last fed to parseRequest() (no leading/trailing slash). */
    private static string $requestPath = '';

    /** Whether the last parse used pretty permalink matching. */
    private static bool $didMatch = false;

    // -------------------------------------------------------------------------
    // Structure / options
    // -------------------------------------------------------------------------

    /**
     * Common structure presets for the Permalinks settings screen.
     *
     * @return array<string, string> label => structure
     */
    public static function commonStructures(): array
    {
        return [
            'Plain' => self::STRUCTURE_PLAIN,
            'Day and name' => self::STRUCTURE_DAY_NAME,
            'Month and name' => self::STRUCTURE_MONTH_NAME,
            'Numeric' => self::STRUCTURE_NUMERIC,
            'Post name' => self::STRUCTURE_POST_NAME,
        ];
    }

    /**
     * Whether pretty permalinks are enabled (non-empty structure).
     */
    public static function usingPermalinks(?AP_DB $db = null): bool
    {
        return self::getStructure($db) !== '';
    }

    /**
     * Current permalink structure (may be empty for plain).
     */
    public static function getStructure(?AP_DB $db = null): string
    {
        return self::normalizeStructure(self::readOption(self::OPTION_STRUCTURE, '', $db));
    }

    /**
     * Persist a structure and flush rewrite rules.
     *
     * @return bool True when option write succeeded.
     */
    public static function setStructure(string $structure, ?AP_DB $db = null): bool
    {
        $structure = self::normalizeStructure($structure);
        $ok = self::writeOption(self::OPTION_STRUCTURE, $structure, $db);
        self::flushRules($db);

        return $ok;
    }

    /**
     * Category base path segment (default "category" when empty).
     */
    public static function getCategoryBase(?AP_DB $db = null): string
    {
        $base = trim(self::readOption(self::OPTION_CATEGORY_BASE, '', $db), '/');

        return $base !== '' ? self::sanitizeSlug($base) : 'category';
    }

    /**
     * Tag base path segment (default "tag" when empty).
     */
    public static function getTagBase(?AP_DB $db = null): string
    {
        $base = trim(self::readOption(self::OPTION_TAG_BASE, '', $db), '/');

        return $base !== '' ? self::sanitizeSlug($base) : 'tag';
    }

    /**
     * Set optional category base (empty → default "category" at read time).
     */
    public static function setCategoryBase(string $base, ?AP_DB $db = null): bool
    {
        $base = trim($base, '/');
        $ok = self::writeOption(
            self::OPTION_CATEGORY_BASE,
            $base === '' ? '' : self::sanitizeSlug($base),
            $db
        );
        self::flushRules($db);

        return $ok;
    }

    /**
     * Set optional tag base (empty → default "tag" at read time).
     */
    public static function setTagBase(string $base, ?AP_DB $db = null): bool
    {
        $base = trim($base, '/');
        $ok = self::writeOption(
            self::OPTION_TAG_BASE,
            $base === '' ? '' : self::sanitizeSlug($base),
            $db
        );
        self::flushRules($db);

        return $ok;
    }

    /**
     * Normalize a structure: leading slash, no double slashes; tags preserved.
     */
    public static function normalizeStructure(string $structure): string
    {
        $structure = trim($structure);
        if ($structure === '') {
            return '';
        }

        // Strip query / host if a full URL was pasted by mistake.
        if (str_contains($structure, '://')) {
            $path = parse_url($structure, PHP_URL_PATH);
            $structure = is_string($path) ? $path : '';
        }

        $structure = str_replace('\\', '/', $structure);
        $structure = preg_replace('#/+#', '/', $structure) ?? $structure;
        $structure = '/' . trim($structure, '/');
        // Keep trailing slash for pretty structures (WP-style).
        if ($structure !== '/' && !str_ends_with($structure, '/')) {
            $structure .= '/';
        }
        if ($structure === '/') {
            return '';
        }

        return $structure;
    }

    // -------------------------------------------------------------------------
    // Rule generation
    // -------------------------------------------------------------------------

    /**
     * Build rewrite rules for the current structure and bases.
     *
     * Keys are regex patterns (no delimiters, matched against the path without
     * leading slash). Values are query templates using $matches[N].
     *
     * @return array<string, string>
     */
    public static function generateRules(?AP_DB $db = null): array
    {
        $structure = self::getStructure($db);
        if ($structure === '') {
            return [];
        }

        $rules = [];
        $catBase = preg_quote(self::getCategoryBase($db), '#');
        $tagBase = preg_quote(self::getTagBase($db), '#');

        // Category archive (+ pagination).
        $rules[$catBase . '/(.+?)/page/?([0-9]{1,})/?$'] = 'category_name=$matches[1]&paged=$matches[2]';
        $rules[$catBase . '/(.+?)/?$'] = 'category_name=$matches[1]';

        // Tag archive (+ pagination).
        $rules[$tagBase . '/([^/]+)/page/?([0-9]{1,})/?$'] = 'tag=$matches[1]&paged=$matches[2]';
        $rules[$tagBase . '/([^/]+)/?$'] = 'tag=$matches[1]';

        // Author archive.
        $rules['author/([^/]+)/page/?([0-9]{1,})/?$'] = 'author_name=$matches[1]&paged=$matches[2]';
        $rules['author/([^/]+)/?$'] = 'author_name=$matches[1]';

        // Search.
        $rules['search/(.+)/page/?([0-9]{1,})/?$'] = 's=$matches[1]&paged=$matches[2]';
        $rules['search/(.+)/?$'] = 's=$matches[1]';

        // Date archives (year / year-month / year-month-day) + pagination.
        $rules['([0-9]{4})/([0-9]{1,2})/([0-9]{1,2})/page/?([0-9]{1,})/?$'] =
            'year=$matches[1]&monthnum=$matches[2]&day=$matches[3]&paged=$matches[4]';
        $rules['([0-9]{4})/([0-9]{1,2})/page/?([0-9]{1,})/?$'] =
            'year=$matches[1]&monthnum=$matches[2]&paged=$matches[3]';
        $rules['([0-9]{4})/page/?([0-9]{1,})/?$'] = 'year=$matches[1]&paged=$matches[2]';
        $rules['([0-9]{4})/([0-9]{1,2})/([0-9]{1,2})/?$'] =
            'year=$matches[1]&monthnum=$matches[2]&day=$matches[3]';
        $rules['([0-9]{4})/([0-9]{1,2})/?$'] = 'year=$matches[1]&monthnum=$matches[2]';
        $rules['([0-9]{4})/?$'] = 'year=$matches[1]';

        // Blog index pagination.
        $rules['page/?([0-9]{1,})/?$'] = 'paged=$matches[1]';

        // Syndication feeds (site-wide).
        $rules['feed/(feed|rdf|rss|rss2|atom)/?$'] = 'feed=$matches[1]';
        $rules['feed/?$'] = 'feed=rss2';
        $rules['(feed|rdf|rss|rss2|atom)/?$'] = 'feed=$matches[1]';

        // XML sitemaps + robots.txt (before structure / page catch-all).
        $rules['sitemap\.xml$'] = 'sitemap=index';
        $rules['sitemap-([a-z0-9_\-]+)-([0-9]+)\.xml$'] = 'sitemap=$matches[1]&sitemap_page=$matches[2]';
        $rules['sitemap-([a-z0-9_\-]+)\.xml$'] = 'sitemap=$matches[1]';
        $rules['robots\.txt$'] = 'robots=1';

        // REST API (/ap-json/… — before structure / page catch-all).
        $rules['ap-json/?$'] = 'rest_route=/';
        $rules['ap-json/(.*)$'] = 'rest_route=/$matches[1]';

        // Forum front-end (before structure / page catch-all).
        // Matches AP_Forum::forumUrl / topicUrl / searchUrl:
        // /forums/, /forums/search/, /forums/{slug}/, /topic/{slug}/.
        $rules['forums/page/?([0-9]{1,})/?$'] = 'ap_forum_view=index&paged=$matches[1]';
        $rules['forums/?$'] = 'ap_forum_view=index';
        // Search must come before the generic forum-slug rule.
        $rules['forums/search/page/?([0-9]{1,})/?$'] = 'ap_forum_view=search&paged=$matches[1]';
        $rules['forums/search/(.+)/page/?([0-9]{1,})/?$'] =
            'ap_forum_view=search&forum_s=$matches[1]&paged=$matches[2]';
        $rules['forums/search/(.+)/?$'] = 'ap_forum_view=search&forum_s=$matches[1]';
        $rules['forums/search/?$'] = 'ap_forum_view=search';
        $rules['forums/([^/]+)/page/?([0-9]{1,})/?$'] =
            'ap_forum_view=forum&forum_slug=$matches[1]&paged=$matches[2]';
        $rules['forums/([^/]+)/?$'] = 'ap_forum_view=forum&forum_slug=$matches[1]';
        $rules['topic/([^/]+)/page/?([0-9]{1,})/?$'] =
            'ap_forum_view=topic&topic_slug=$matches[1]&paged=$matches[2]';
        $rules['topic/([^/]+)/?$'] = 'ap_forum_view=topic&topic_slug=$matches[1]';

        // Structure-derived single post rule (+ optional trailing page/N for multipage).
        $postRule = self::structureToRule($structure);
        if ($postRule !== null) {
            [$regex, $query] = $postRule;
            $rules[$regex . 'page/?([0-9]{1,})/?$'] = $query . '&page=$matches['
                . (substr_count($regex, '(') + 1) . ']';
            $rules[$regex . '?$'] = $query;
        }

        // Hierarchical pages (catch-all — after more specific rules).
        $rules['(.?.+?)/page/?([0-9]{1,})/?$'] = 'pagename=$matches[1]&page=$matches[2]';
        $rules['(.?.+?)/?$'] = 'pagename=$matches[1]';

        return $rules;
    }

    /**
     * Regenerate rules and store them in the options table.
     *
     * @return array<string, string> Fresh rules.
     */
    public static function flushRules(?AP_DB $db = null): array
    {
        $rules = self::generateRules($db);
        self::$rules = $rules;

        $encoded = $rules === [] ? '' : (string) json_encode($rules, JSON_UNESCAPED_SLASHES);
        self::writeOption(self::OPTION_REWRITE_RULES, $encoded, $db);

        return $rules;
    }

    /**
     * Loaded or generated rewrite rules.
     *
     * @return array<string, string>
     */
    public static function getRules(?AP_DB $db = null, bool $forceRefresh = false): array
    {
        if (!$forceRefresh && self::$rules !== null) {
            return self::$rules;
        }

        if (!$forceRefresh) {
            $raw = self::readOption(self::OPTION_REWRITE_RULES, '', $db);
            if ($raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    /** @var array<string, string> $decoded */
                    $clean = [];
                    foreach ($decoded as $k => $v) {
                        if (is_string($k) && is_string($v)) {
                            $clean[$k] = $v;
                        }
                    }
                    self::$rules = $clean;

                    return self::$rules;
                }
            }
        }

        return self::flushRules($db);
    }

    /**
     * Clear in-memory rule cache (tests).
     */
    public static function resetCache(): void
    {
        self::$rules = null;
        self::$queryVars = [];
        self::$requestPath = '';
        self::$didMatch = false;
    }

    // -------------------------------------------------------------------------
    // Request parsing
    // -------------------------------------------------------------------------

    /**
     * Parse a request path (and optional GET vars) into query vars for AP_Query.
     *
     * @param string               $path  Path relative to home (e.g. "2026/08/hello-world").
     *                                    Leading/trailing slashes optional. Query string ignored.
     * @param array<string, mixed> $get   Extra query-string vars (typically $_GET).
     *
     * @return array<string, mixed>
     */
    public static function parseRequest(string $path = '', array $get = [], ?AP_DB $db = null): array
    {
        self::$didMatch = false;
        self::$queryVars = [];

        // Strip query string if a full URI fragment was passed.
        if (str_contains($path, '?')) {
            $path = (string) strstr($path, '?', true);
        }
        $path = rawurldecode($path);
        $path = str_replace('\\', '/', $path);
        $path = trim($path, '/');
        // Collapse // and strip home subdirectory prefix when present.
        $path = self::stripHomePath($path, $db);
        self::$requestPath = $path;

        $vars = [];

        // SEO endpoints (sitemap / robots) are recognized even when pretty
        // permalinks are off — crawlers expect /sitemap.xml and /robots.txt.
        $seoVars = self::matchSeoPath($path);
        if ($seoVars !== null) {
            foreach (self::mapPublicGetVars($get) as $key => $value) {
                if (!array_key_exists($key, $seoVars) || $seoVars[$key] === '' || $seoVars[$key] === 0) {
                    $seoVars[$key] = $value;
                }
            }
            self::$queryVars = $seoVars;
            self::$didMatch = true;

            return $seoVars;
        }

        // REST API (/ap-json/…) recognized even with plain permalinks.
        $restVars = self::matchRestPath($path);
        if ($restVars !== null) {
            foreach (self::mapPublicGetVars($get) as $key => $value) {
                if (!array_key_exists($key, $restVars) || $restVars[$key] === '' || $restVars[$key] === 0) {
                    $restVars[$key] = $value;
                }
            }
            self::$queryVars = $restVars;
            self::$didMatch = true;

            return $restVars;
        }

        if (!self::usingPermalinks($db) || $path === '') {
            // Plain mode or front page: query string only.
            $vars = self::mapPublicGetVars($get);
            if ($path === '' && $vars === []) {
                // Front page / blog home.
                $vars['post_type'] = 'post';
            }
            self::$queryVars = $vars;
            self::$didMatch = true;

            return $vars;
        }

        $rules = self::getRules($db);
        foreach ($rules as $regex => $queryTemplate) {
            // Anchor at start; rules already end with $ (end of path).
            $pattern = '#^' . $regex . '#';
            if (@preg_match($pattern, $path, $matches) !== 1) {
                continue;
            }
            $vars = self::expandQueryTemplate($queryTemplate, $matches);
            self::$didMatch = true;
            break;
        }

        // Overlay public GET vars (e.g. ?preview=1) without clobbering path matches
        // unless the GET key was not already set.
        foreach (self::mapPublicGetVars($get) as $key => $value) {
            if (!array_key_exists($key, $vars) || $vars[$key] === '' || $vars[$key] === 0) {
                $vars[$key] = $value;
            }
        }

        // Hierarchical page catch-all may also match post-name paths. Prefer
        // explicit single-post vars when structure matched; otherwise leave pagename.
        // When both name and pagename are empty after a failed match → empty home-ish.
        if (!self::$didMatch && $path !== '') {
            // No rule matched — treat as potential 404 path (pagename attempt).
            $vars['pagename'] = $path;
            self::$didMatch = false;
        }

        // Decode search term (+ and %20 already handled by rawurldecode of path).
        if (isset($vars['s']) && is_string($vars['s'])) {
            $vars['s'] = str_replace('+', ' ', $vars['s']);
        }
        if (isset($vars['forum_s']) && is_string($vars['forum_s'])) {
            $vars['forum_s'] = str_replace('+', ' ', $vars['forum_s']);
        }

        self::$queryVars = $vars;

        return $vars;
    }

    /**
     * Parse from typical PHP superglobals (REQUEST_URI + GET).
     *
     * @param array<string, mixed>|null $server Typically $_SERVER.
     * @param array<string, mixed>|null $get    Typically $_GET.
     *
     * @return array<string, mixed>
     */
    public static function parseFromGlobals(
        ?array $server = null,
        ?array $get = null,
        ?AP_DB $db = null
    ): array {
        $server = $server ?? $_SERVER;
        $get = $get ?? $_GET;

        $uri = '';
        if (isset($server['REQUEST_URI']) && is_string($server['REQUEST_URI'])) {
            $uri = $server['REQUEST_URI'];
        } elseif (isset($server['PATH_INFO']) && is_string($server['PATH_INFO'])) {
            $uri = $server['PATH_INFO'];
        }

        $path = (string) (parse_url($uri, PHP_URL_PATH) ?: '');

        return self::parseRequest($path, $get, $db);
    }

    /**
     * Query vars from the last parseRequest() call.
     *
     * @return array<string, mixed>
     */
    public static function getQueryVars(): array
    {
        return self::$queryVars;
    }

    /**
     * Path from the last parseRequest() (no leading/trailing slash).
     */
    public static function getRequestPath(): string
    {
        return self::$requestPath;
    }

    /**
     * Whether the last parse matched a rewrite rule (or plain mode).
     */
    public static function didMatch(): bool
    {
        return self::$didMatch;
    }

    /**
     * Build an AP_Query from the last (or provided) rewrite query vars.
     *
     * Applies Reading settings (static front page / posts page / posts_per_page).
     *
     * @param array<string, mixed>|null $vars
     */
    public static function queryFromVars(?array $vars = null, ?AP_DB $db = null): AP_Query
    {
        $vars = $vars ?? self::$queryVars;
        $args = self::toQueryArgs($vars, $db);

        return new AP_Query($args, $db);
    }

    /**
     * Normalize rewrite vars into AP_Query constructor args.
     *
     * When $db is provided, Reading options reshape the front page and posts page.
     *
     * @param array<string, mixed> $vars
     *
     * @return array<string, mixed>
     */
    public static function toQueryArgs(array $vars, ?AP_DB $db = null): array
    {
        $args = [];
        $intKeys = [
            'p', 'page_id', 'author', 'year', 'monthnum', 'day', 'paged', 'page', 'cat', 'tag_id',
            'forum_id', 'topic_id', 'sitemap_page',
        ];
        $stringKeys = [
            'name', 'pagename', 'author_name', 's', 'category_name', 'tag', 'post_type', 'post_status', 'feed',
            'ap_forum_view', 'forum_slug', 'topic_slug', 'ap_forum', 'forum_s',
            'sitemap', 'robots', 'rest_route',
        ];

        foreach ($intKeys as $key) {
            if (isset($vars[$key]) && $vars[$key] !== '' && $vars[$key] !== null) {
                $args[$key] = (int) $vars[$key];
            }
        }
        foreach ($stringKeys as $key) {
            if (isset($vars[$key]) && is_string($vars[$key]) && $vars[$key] !== '') {
                $args[$key] = $vars[$key];
            } elseif (isset($vars[$key]) && is_int($vars[$key]) && $vars[$key] !== 0) {
                // Plain mode may cast numeric-looking strings; keep ap_forum flag.
                $args[$key] = (string) $vars[$key];
            }
        }

        // Infer forum view from IDs / flags when explicit view is missing.
        if (empty($args['ap_forum_view'])) {
            if (!empty($args['topic_id']) || !empty($args['topic_slug'])) {
                $args['ap_forum_view'] = 'topic';
            } elseif (!empty($args['forum_id']) || !empty($args['forum_slug'])) {
                $args['ap_forum_view'] = 'forum';
            } elseif (!empty($args['ap_forum'])) {
                $args['ap_forum_view'] = 'index';
            }
        }

        // Forum front-end: skip blog Reading settings and avoid loading posts.
        if (!empty($args['ap_forum_view'])) {
            $args['no_found_rows'] = true;
            $args['nopaging'] = true;
            $args['posts_per_page'] = 1;
            // Empty blog loop — forum templates load dedicated data.
            if (empty($args['post__in'])) {
                $args['post__in'] = [0];
            }
            if (class_exists('AP_Forum_Front', false)) {
                $args = AP_Forum_Front::enrichQueryArgs($args, $db);
            }

            return $args;
        }

        // Singular page by page_id / pagename implies post_type page.
        if (
            (!empty($args['page_id']) || !empty($args['pagename']))
            && empty($args['p'])
            && empty($args['name'])
            && empty($args['post_type'])
        ) {
            $args['post_type'] = 'page';
        }

        // Singular post by p / name defaults to post (AP_Query default).
        if (!empty($args['p']) || !empty($args['name'])) {
            if (empty($args['post_type'])) {
                $args['post_type'] = 'any';
            }
        }

        // Apply Reading / front-page settings when this looks like a content request
        // (not a feed — feeds are handled separately).
        if (empty($args['feed'])) {
            $args = self::applyReadingSettings($args, $db);
        }

        return $args;
    }

    /**
     * Map empty home / posts-page requests onto Reading settings.
     *
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    public static function applyReadingSettings(array $args, ?AP_DB $db = null): array
    {
        if (!class_exists('AP_Options', false)) {
            return $args;
        }

        $show = AP_Options::showOnFront($db);
        $pageOnFront = AP_Options::pageOnFront($db);
        $pageForPosts = AP_Options::pageForPosts($db);
        $postsPerPage = AP_Options::postsPerPage($db);

        $isSingular = !empty($args['p']) || !empty($args['name'])
            || !empty($args['page_id']) || !empty($args['pagename']);
        $isArchive = !empty($args['s']) || !empty($args['author']) || !empty($args['author_name'])
            || !empty($args['year']) || !empty($args['cat']) || !empty($args['category_name'])
            || !empty($args['tag']) || !empty($args['tag_id']);

        // Detect posts page: requested page is page_for_posts.
        if ($pageForPosts > 0 && $show === 'page') {
            $requestedPageId = !empty($args['page_id']) ? (int) $args['page_id'] : 0;
            if ($requestedPageId === 0 && !empty($args['pagename']) && class_exists('AP_Post', false)) {
                $bySlug = AP_Post::getBySlug((string) $args['pagename'], 'page', $db);
                if ($bySlug instanceof AP_Post) {
                    $requestedPageId = (int) $bySlug->ID;
                }
            }
            if ($requestedPageId === $pageForPosts) {
                return [
                    'post_type' => 'post',
                    'post_status' => 'publish',
                    'posts_per_page' => $postsPerPage,
                    'paged' => max(1, (int) ($args['paged'] ?? 1)),
                    'ap_is_posts_page' => true,
                    'ap_is_front_page' => false,
                ];
            }
        }

        // Empty front request (no singular / archive selectors).
        $isHomeRequest = !$isSingular && !$isArchive
            && (empty($args['post_type']) || $args['post_type'] === 'post');

        if (!$isHomeRequest) {
            return $args;
        }

        if ($show === 'page' && $pageOnFront > 0) {
            return [
                'page_id' => $pageOnFront,
                'post_type' => 'page',
                'ap_is_front_page' => true,
                'ap_is_posts_page' => false,
            ];
        }

        // Latest posts on the front.
        $args['post_type'] = 'post';
        $args['posts_per_page'] = $postsPerPage;
        $args['ap_is_front_page'] = true;
        $args['ap_is_posts_page'] = false;
        if (empty($args['paged'])) {
            $args['paged'] = 1;
        }

        return $args;
    }

    // -------------------------------------------------------------------------
    // Link generation
    // -------------------------------------------------------------------------

    /**
     * Public home URL (no trailing slash by default).
     *
     * @param string $path Optional path or query to append.
     */
    public static function homeUrl(string $path = '', ?AP_DB $db = null): string
    {
        $home = self::readOption('home', '', $db);
        if ($home === '') {
            $home = self::readOption('siteurl', '', $db);
        }
        if ($home === '' && defined('AP_HOME') && is_string(AP_HOME) && AP_HOME !== '') {
            $home = (string) AP_HOME;
        }
        if ($home === '' && defined('AP_SITEURL') && is_string(AP_SITEURL) && AP_SITEURL !== '') {
            $home = (string) AP_SITEURL;
        }
        $home = rtrim($home, '/');

        if ($path === '') {
            return $home !== '' ? $home : '';
        }

        if (str_starts_with($path, '?')) {
            return ($home !== '' ? $home . '/' : '/') . ltrim($path, '/');
        }

        $path = '/' . ltrim($path, '/');

        return ($home !== '' ? $home : '') . $path;
    }

    /**
     * Site URL (core installation URL; usually same as home for single-site).
     */
    public static function siteUrl(string $path = '', ?AP_DB $db = null): string
    {
        $site = self::readOption('siteurl', '', $db);
        if ($site === '' && defined('AP_SITEURL') && is_string(AP_SITEURL) && AP_SITEURL !== '') {
            $site = (string) AP_SITEURL;
        }
        if ($site === '') {
            return self::homeUrl($path, $db);
        }
        $site = rtrim($site, '/');
        if ($path === '') {
            return $site;
        }
        if (str_starts_with($path, '?')) {
            return $site . '/' . ltrim($path, '/');
        }

        return $site . '/' . ltrim($path, '/');
    }

    /**
     * Permalink for a post or page (or empty string when missing).
     */
    public static function getPermalink(AP_Post|int $post, ?AP_DB $db = null): string
    {
        $db = self::resolveDbOptional($db);
        $obj = $post instanceof AP_Post ? $post : ($db !== null ? AP_Post::get((int) $post, $db) : null);
        if ($obj === null && $post instanceof AP_Post) {
            $obj = $post;
        }
        if ($obj === null) {
            return '';
        }

        // Drafts / private still get a usable link (plain or pretty with name).
        if ($obj->post_type === 'page') {
            return self::getPageLink($obj, $db);
        }

        if ($obj->post_type === 'attachment' && class_exists('AP_Media', false)) {
            $url = AP_Media::getAttachmentUrl($obj->ID, $db);
            if ($url !== '') {
                return $url;
            }
        }

        if (!self::usingPermalinks($db)) {
            return self::homeUrl('?p=' . $obj->ID, $db);
        }

        $structure = self::getStructure($db);
        $replacements = self::permalinkTokens($obj, $db);
        $path = str_replace(array_keys($replacements), array_values($replacements), $structure);
        $path = preg_replace('#/+#', '/', $path) ?? $path;

        return self::homeUrl($path, $db);
    }

    /**
     * Link for a hierarchical page.
     */
    public static function getPageLink(AP_Post|int $page, ?AP_DB $db = null): string
    {
        $db = self::resolveDbOptional($db);
        $obj = $page instanceof AP_Post ? $page : ($db !== null ? AP_Post::get((int) $page, $db) : null);
        if ($obj === null) {
            return '';
        }

        if (!self::usingPermalinks($db)) {
            return self::homeUrl('?page_id=' . $obj->ID, $db);
        }

        $path = '';
        if ($db !== null && class_exists('AP_Post', false)) {
            $path = AP_Post::getPagePath($obj->ID, $db);
        }
        if ($path === '') {
            $path = $obj->post_name;
        }
        if ($path === '') {
            return self::homeUrl('?page_id=' . $obj->ID, $db);
        }

        return self::homeUrl('/' . trim($path, '/') . '/', $db);
    }

    /**
     * Category or tag (or custom taxonomy) term archive link.
     */
    public static function getTermLink(object|int $term, string $taxonomy = '', ?AP_DB $db = null): string
    {
        $db = self::resolveDbOptional($db);
        if (is_int($term)) {
            if ($db === null || !class_exists('AP_Taxonomy', false)) {
                return '';
            }
            $term = AP_Taxonomy::getTerm($term, $taxonomy, $db);
            if ($term === null) {
                return '';
            }
        }

        $slug = isset($term->slug) ? (string) $term->slug : '';
        $tax = $taxonomy !== '' ? $taxonomy : (isset($term->taxonomy) ? (string) $term->taxonomy : '');
        if ($slug === '') {
            return '';
        }

        if (!self::usingPermalinks($db)) {
            if ($tax === 'category') {
                $id = isset($term->term_id) ? (int) $term->term_id : 0;

                return self::homeUrl($id > 0 ? '?cat=' . $id : '?category_name=' . rawurlencode($slug), $db);
            }
            if ($tax === 'post_tag') {
                return self::homeUrl('?tag=' . rawurlencode($slug), $db);
            }

            return self::homeUrl('?taxonomy=' . rawurlencode($tax) . '&term=' . rawurlencode($slug), $db);
        }

        if ($tax === 'category') {
            return self::homeUrl('/' . self::getCategoryBase($db) . '/' . $slug . '/', $db);
        }
        if ($tax === 'post_tag') {
            return self::homeUrl('/' . self::getTagBase($db) . '/' . $slug . '/', $db);
        }

        // Custom taxonomies: /{taxonomy}/{slug}/ until CPT rewrite lands.
        $base = self::sanitizeSlug($tax !== '' ? $tax : 'term');

        return self::homeUrl('/' . $base . '/' . $slug . '/', $db);
    }

    /**
     * Author archive permalink.
     */
    public static function getAuthorLink(string $authorName, ?AP_DB $db = null): string
    {
        $authorName = trim($authorName);
        if ($authorName === '') {
            return '';
        }

        if (!self::usingPermalinks($db)) {
            return self::homeUrl('?author_name=' . rawurlencode($authorName), $db);
        }

        return self::homeUrl('/author/' . rawurlencode($authorName) . '/', $db);
    }

    /**
     * Search results URL.
     */
    public static function getSearchLink(string $query, ?AP_DB $db = null): string
    {
        $query = trim($query);
        if (!self::usingPermalinks($db)) {
            return self::homeUrl('?s=' . rawurlencode($query), $db);
        }

        return self::homeUrl('/search/' . rawurlencode($query) . '/', $db);
    }

    /**
     * Public feed URL (pretty /feed/ or plain ?feed= when permalinks are off).
     */
    public static function getFeedLink(string $feed = 'rss2', ?AP_DB $db = null): string
    {
        $feed = self::sanitizeSlug($feed !== '' ? $feed : 'rss2');
        if (!self::usingPermalinks($db)) {
            return self::homeUrl('?feed=' . rawurlencode($feed), $db);
        }

        return self::homeUrl('/feed/' . ($feed === 'rss2' ? '' : $feed . '/'), $db);
    }

    /**
     * Public sitemap URL (index or provider).
     *
     * @see AP_Sitemap::getSitemapLink()
     */
    public static function getSitemapLink(string $type = 'index', int $page = 1, ?AP_DB $db = null): string
    {
        if (class_exists('AP_Sitemap', false)) {
            return AP_Sitemap::getSitemapLink($type, $page, $db);
        }
        if ($type === '' || $type === 'index') {
            return self::usingPermalinks($db)
                ? self::homeUrl('/sitemap.xml', $db)
                : self::homeUrl('?sitemap=index', $db);
        }

        return self::usingPermalinks($db)
            ? self::homeUrl('/sitemap-' . self::sanitizeSlug($type) . '.xml', $db)
            : self::homeUrl('?sitemap=' . rawurlencode(self::sanitizeSlug($type)), $db);
    }

    /**
     * Match sitemap / robots path segments (always on, even without pretty permalinks).
     *
     * @return array<string, mixed>|null
     */
    public static function matchSeoPath(string $path): ?array
    {
        $path = trim($path, '/');
        if ($path === '') {
            return null;
        }
        if (strcasecmp($path, 'robots.txt') === 0) {
            return ['robots' => '1'];
        }
        if (strcasecmp($path, 'sitemap.xml') === 0) {
            return ['sitemap' => 'index'];
        }
        if (preg_match('#^sitemap-([a-z0-9_\-]+)-([0-9]+)\.xml$#i', $path, $m) === 1) {
            return [
                'sitemap' => strtolower($m[1]),
                'sitemap_page' => (int) $m[2],
            ];
        }
        if (preg_match('#^sitemap-([a-z0-9_\-]+)\.xml$#i', $path, $m) === 1) {
            return ['sitemap' => strtolower($m[1])];
        }

        return null;
    }

    /**
     * Match REST API path segments (/ap-json/… — always on).
     *
     * @return array<string, mixed>|null
     */
    public static function matchRestPath(string $path): ?array
    {
        if (class_exists('AP_Rest', false)) {
            return AP_Rest::matchRestPath($path);
        }
        $path = trim($path, '/');
        if ($path === '') {
            return null;
        }
        if (strcasecmp($path, 'ap-json') === 0) {
            return ['rest_route' => '/'];
        }
        if (preg_match('#^ap-json/(.*)$#i', $path, $m) === 1) {
            $rest = '/' . trim(rawurldecode($m[1]), '/');

            return ['rest_route' => $rest === '/' ? '/' : $rest];
        }

        return null;
    }

    /**
     * Public REST API URL for a route (pretty /ap-json/… or plain ?rest_route=).
     */
    public static function getRestLink(string $route = '/', ?AP_DB $db = null): string
    {
        if (class_exists('AP_Rest', false)) {
            return AP_Rest::getUrl($route, $db);
        }
        $route = trim($route);
        if ($route === '') {
            $route = '/';
        }
        if ($route[0] !== '/') {
            $route = '/' . $route;
        }
        if (self::usingPermalinks($db)) {
            $path = '/ap-json/';
            if ($route !== '/') {
                $path .= ltrim($route, '/') . '/';
            }

            return self::homeUrl($path, $db);
        }

        return self::homeUrl('?rest_route=' . rawurlencode($route), $db);
    }

    // -------------------------------------------------------------------------
    // Server config snippets
    // -------------------------------------------------------------------------

    /**
     * Apache mod_rewrite block for the front controller (root .htaccess body).
     *
     * Pretty permalinks require this pattern; individual post rules live in PHP.
     */
    public static function apacheRewriteBlock(string $rewriteBase = '/'): string
    {
        $base = $rewriteBase === '' ? '/' : $rewriteBase;
        if ($base !== '/' && !str_starts_with($base, '/')) {
            $base = '/' . $base;
        }
        if ($base !== '/' && !str_ends_with($base, '/')) {
            $base .= '/';
        }

        return <<<HTACCESS
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteBase {$base}

    # Do not rewrite existing files or directories
    RewriteRule ^index\\.php\$ - [L]
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d

    # Front controller — PHP rewrite layer parses the path
    RewriteRule . {$base}index.php [L]
</IfModule>
HTACCESS;
    }

    /**
     * Example Nginx try_files snippet for pretty permalinks.
     */
    public static function nginxTryFilesSnippet(): string
    {
        return <<<'NGINX'
location / {
    try_files $uri $uri/ /index.php?$args;
}
NGINX;
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * Convert a permalink structure into [regex, queryTemplate] for singles.
     *
     * @return array{0: string, 1: string}|null
     */
    private static function structureToRule(string $structure): ?array
    {
        $structure = trim($structure, '/');
        if ($structure === '') {
            return null;
        }

        $tokens = [];
        $regexParts = [];
        $offset = 0;
        $length = strlen($structure);

        while ($offset < $length) {
            if (preg_match('/%[a-z0-9_]+%/', $structure, $m, PREG_OFFSET_CAPTURE, $offset) === 1) {
                $pos = (int) $m[0][1];
                $token = $m[0][0];
                if ($pos > $offset) {
                    $literal = substr($structure, $offset, $pos - $offset);
                    $regexParts[] = preg_quote($literal, '#');
                }
                if (!isset(self::TAG_REGEX[$token])) {
                    // Unknown tag — treat as literal.
                    $regexParts[] = preg_quote($token, '#');
                } else {
                    $regexParts[] = self::TAG_REGEX[$token];
                    $tokens[] = $token;
                }
                $offset = $pos + strlen($token);
            } else {
                $literal = substr($structure, $offset);
                $regexParts[] = preg_quote($literal, '#');
                break;
            }
        }

        $regex = implode('', $regexParts) . '/';
        $queryParts = [];
        foreach ($tokens as $i => $token) {
            $var = self::TAG_QUERY_VAR[$token] ?? null;
            if ($var === null) {
                continue;
            }
            $queryParts[] = $var . '=$matches[' . ($i + 1) . ']';
        }
        if ($queryParts === []) {
            return null;
        }

        return [$regex, implode('&', $queryParts)];
    }

    /**
     * @param array<int|string, string> $matches
     *
     * @return array<string, mixed>
     */
    private static function expandQueryTemplate(string $template, array $matches): array
    {
        $expanded = preg_replace_callback(
            '/\$matches\[(\d+)\]/',
            static function (array $m) use ($matches): string {
                $i = (int) $m[1];

                return isset($matches[$i]) ? (string) $matches[$i] : '';
            },
            $template
        );
        if (!is_string($expanded)) {
            $expanded = $template;
        }

        $parsed = [];
        parse_str($expanded, $parsed);

        $out = [];
        foreach ($parsed as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            if (is_array($value)) {
                continue;
            }
            $stringKeys = [
                'name', 'pagename', 's', 'tag', 'category_name', 'author_name',
                'ap_forum_view', 'forum_slug', 'topic_slug', 'ap_forum', 'forum_s',
                'rest_route', 'feed', 'sitemap',
            ];
            $keepString = in_array($key, $stringKeys, true);
            if (is_numeric($value) && !$keepString) {
                $out[$key] = str_contains((string) $value, '.')
                    ? (string) $value
                    : (int) $value;
            } else {
                $out[$key] = (string) $value;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $get
     *
     * @return array<string, mixed>
     */
    private static function mapPublicGetVars(array $get): array
    {
        $allowed = [
            'p' => 'int',
            'page_id' => 'int',
            'name' => 'string',
            'pagename' => 'string',
            'post_type' => 'string',
            'author' => 'int',
            'author_name' => 'string',
            's' => 'string',
            'year' => 'int',
            'monthnum' => 'int',
            'day' => 'int',
            'paged' => 'int',
            'page' => 'int',
            'cat' => 'int',
            'category_name' => 'string',
            'tag' => 'string',
            'tag_id' => 'int',
            'feed' => 'string',
            'preview' => 'string',
            // XML sitemaps + robots.txt.
            'sitemap' => 'string',
            'sitemap_page' => 'int',
            'robots' => 'string',
            // REST API (plain ?rest_route=/ap/v1/posts).
            'rest_route' => 'string',
            // Forum front-end (plain + pretty).
            'ap_forum' => 'string',
            'ap_forum_view' => 'string',
            'forum_id' => 'int',
            'topic_id' => 'int',
            'forum_slug' => 'string',
            'topic_slug' => 'string',
            'forum_s' => 'string',
        ];

        $out = [];
        foreach ($allowed as $key => $type) {
            if (!array_key_exists($key, $get)) {
                continue;
            }
            $raw = $get[$key];
            if (is_array($raw)) {
                continue;
            }
            if ($type === 'int') {
                $out[$key] = (int) $raw;
            } else {
                $out[$key] = is_string($raw) ? trim($raw) : (string) $raw;
            }
        }

        return $out;
    }

    /**
     * @return array<string, string>
     */
    private static function permalinkTokens(AP_Post $post, ?AP_DB $db): array
    {
        $date = $post->post_date !== '' ? $post->post_date : '1970-01-01 00:00:00';
        $ts = strtotime($date) ?: 0;

        $category = '';
        if ($db !== null && class_exists('AP_Taxonomy', false)) {
            $terms = AP_Taxonomy::getObjectTerms($post->ID, 'category', [], $db);
            if ($terms !== []) {
                $category = (string) ($terms[0]->slug ?? '');
            }
        }
        if ($category === '') {
            $category = 'uncategorized';
        }

        $author = 'author';
        if ($db !== null && $post->post_author > 0 && class_exists('AP_User', false)) {
            $user = AP_User::getBy('id', $post->post_author, $db);
            if ($user !== null) {
                $author = $user->user_nicename !== ''
                    ? $user->user_nicename
                    : $user->user_login;
            }
        }

        $name = $post->post_name !== '' ? $post->post_name : (string) $post->ID;

        return [
            '%year%' => date('Y', $ts),
            '%monthnum%' => date('m', $ts),
            '%day%' => date('d', $ts),
            '%hour%' => date('H', $ts),
            '%minute%' => date('i', $ts),
            '%second%' => date('s', $ts),
            '%post_id%' => (string) $post->ID,
            '%postname%' => $name,
            '%category%' => $category,
            '%author%' => $author,
        ];
    }

    /**
     * Remove the home URL path prefix from a request path when the site lives in a subdirectory.
     */
    private static function stripHomePath(string $path, ?AP_DB $db): string
    {
        $path = trim($path, '/');
        $home = self::readOption('home', '', $db);
        if ($home === '') {
            $home = self::readOption('siteurl', '', $db);
        }
        if ($home === '' && defined('AP_SITEURL') && is_string(AP_SITEURL)) {
            $home = (string) AP_SITEURL;
        }
        if ($home === '') {
            return $path;
        }
        $homePath = parse_url($home, PHP_URL_PATH);
        if (!is_string($homePath) || $homePath === '' || $homePath === '/') {
            return $path;
        }
        $homePath = trim($homePath, '/');
        if ($homePath !== '' && ($path === $homePath || str_starts_with($path, $homePath . '/'))) {
            $path = $path === $homePath ? '' : substr($path, strlen($homePath) + 1);
        }

        return trim((string) $path, '/');
    }

    private static function sanitizeSlug(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9_\\-\\/]+/', '-', $value) ?? $value;
        $value = preg_replace('/\\-+/', '-', $value) ?? $value;
        $value = trim($value, '-/');

        return $value;
    }

    private static function readOption(string $name, string $default = '', ?AP_DB $db = null): string
    {
        $db = self::resolveDbOptional($db);
        if ($db === null) {
            return $default;
        }
        try {
            $val = $db->getVar(
                'SELECT option_value FROM ' . $db->quoteIdentifier($db->table('options'))
                . ' WHERE option_name = ? LIMIT 1',
                [$name]
            );
        } catch (Throwable) {
            return $default;
        }

        return $val !== null ? (string) $val : $default;
    }

    private static function writeOption(string $name, string $value, ?AP_DB $db = null): bool
    {
        $db = self::resolveDbOptional($db);
        if ($db === null) {
            return false;
        }
        try {
            $existing = $db->getVar(
                'SELECT option_id FROM ' . $db->quoteIdentifier($db->table('options'))
                . ' WHERE option_name = ? LIMIT 1',
                [$name]
            );
            if ($existing !== null) {
                return $db->update(
                    'options',
                    ['option_value' => $value],
                    ['option_name' => $name]
                ) !== false;
            }

            return $db->insert('options', [
                'option_name' => $name,
                'option_value' => $value,
                'autoload' => 'yes',
            ]) !== false;
        } catch (Throwable) {
            return false;
        }
    }

    private static function resolveDbOptional(?AP_DB $db): ?AP_DB
    {
        if ($db instanceof AP_DB) {
            return $db;
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
