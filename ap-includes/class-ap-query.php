<?php

/**
 * AgoraPress content query (WP_Query-inspired).
 *
 * Builds prepared SQL against {prefix}posts for the main loop and secondary
 * queries. Familiar surface for classic WordPress developers: query vars,
 * have_posts / the_post loop, pagination, and common conditionals.
 *
 * Not a fork of WP_Query — a clean rewrite of the subset needed for Phase 2
 * (blog index, singles, pages, author archives, search, category/tag archives,
 * admin lists). Full nested meta_query lands later.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * Content query engine against the posts table.
 *
 * Typical use:
 * ```php
 * $q = new AP_Query(['post_type' => 'post', 'posts_per_page' => 10]);
 * while ($q->have_posts()) {
 *     $q->the_post();
 *     echo $q->post->post_title;
 * }
 * ```
 */
class AP_Query
{
    /** @var array<string, mixed> Raw query args as passed in. */
    public array $query = [];

    /** @var array<string, mixed> Normalized query vars used for SQL. */
    public array $query_vars = [];

    /** @var list<AP_Post|int> Matched posts (AP_Post objects, or IDs when fields=ids). */
    public array $posts = [];

    /** Number of posts in $posts for the current page. */
    public int $post_count = 0;

    /** Total matching rows before LIMIT (for pagination). */
    public int $found_posts = 0;

    /** Ceiling of found_posts / posts_per_page (0 when not paging). */
    public int $max_num_pages = 0;

    /** Loop index; -1 before the first the_post(). */
    public int $current_post = -1;

    /** Current post in the loop (set by the_post()). */
    public ?AP_Post $post = null;

    /** Whether the loop is currently iterating. */
    public bool $in_the_loop = false;

    // -------------------------------------------------------------------------
    // Conditionals (set during parse / get_posts)
    // -------------------------------------------------------------------------

    public bool $is_single = false;

    public bool $is_preview = false;

    public bool $is_page = false;

    public bool $is_archive = false;

    public bool $is_date = false;

    public bool $is_year = false;

    public bool $is_month = false;

    public bool $is_day = false;

    public bool $is_author = false;

    public bool $is_search = false;

    public bool $is_home = false;

    /** Site front (static page or posts index). */
    public bool $is_front_page = false;

    /** Blog posts index when using a static front page (page_for_posts). */
    public bool $is_posts_page = false;

    /** Syndication feed request (RSS/Atom). */
    public bool $is_feed = false;

    public bool $is_404 = false;

    public bool $is_singular = false;

    public bool $is_post_type_archive = false;

    public bool $is_category = false;

    public bool $is_tag = false;

    public bool $is_tax = false;

    /** Last generated SELECT SQL (debug / tests). */
    public string $request = '';

    private ?AP_DB $db = null;

    /** @var array<string, mixed> Default query vars. */
    private static array $defaultVars = [
        'p' => 0,
        'page_id' => 0,
        'name' => '',
        'pagename' => '',
        'post_type' => 'post',
        'post_status' => 'publish',
        'post_parent' => '',
        'post_parent__in' => [],
        'post_parent__not_in' => [],
        'post__in' => [],
        'post__not_in' => [],
        'author' => 0,
        'author__in' => [],
        'author__not_in' => [],
        'author_name' => '',
        's' => '',
        'exact' => false,
        'sentence' => false,
        'year' => 0,
        'monthnum' => 0,
        'day' => 0,
        'orderby' => 'date',
        'order' => 'DESC',
        'posts_per_page' => 10,
        'offset' => -1,
        'paged' => 1,
        'page' => 1,
        'nopaging' => false,
        'fields' => '',
        'meta_key' => '',
        'meta_value' => '',
        'meta_compare' => '=',
        // Taxonomy (categories / tags / custom)
        'cat' => 0,
        'category_name' => '',
        'category__in' => [],
        'category__not_in' => [],
        'category__and' => [],
        'tag' => '',
        'tag_id' => 0,
        'tag__in' => [],
        'tag__not_in' => [],
        'tag__and' => [],
        'tag_slug__in' => [],
        'tax_query' => [],
        'ignore_sticky_posts' => true,
        'suppress_filters' => false,
        'no_found_rows' => false,
        // Reading / front-page markers (set by rewrite layer or callers).
        'ap_is_front_page' => false,
        'ap_is_posts_page' => false,
        'feed' => '',
    ];

    /**
     * @param array<string, mixed>|string $query Query vars array or query-string style.
     */
    public function __construct(array|string $query = '', ?AP_DB $db = null)
    {
        $this->db = $db;
        if ($query !== '' && $query !== []) {
            $this->query($query);
        }
    }

    /**
     * Parse query vars and fetch matching posts.
     *
     * @param array<string, mixed>|string $query
     *
     * @return list<AP_Post|int>
     */
    public function query(array|string $query): array
    {
        $this->init();
        $this->query = is_array($query) ? $query : [];
        $this->parseQuery($query);

        return $this->getPosts();
    }

    /**
     * Reset result state (keeps defaults for a fresh query).
     */
    public function init(): void
    {
        $this->posts = [];
        $this->post_count = 0;
        $this->found_posts = 0;
        $this->max_num_pages = 0;
        $this->current_post = -1;
        $this->post = null;
        $this->in_the_loop = false;
        $this->request = '';
        $this->resetConditionals();
    }

    /**
     * Normalize and store query vars; set is_* flags from vars (before SQL).
     *
     * @param array<string, mixed>|string $query
     */
    public function parseQuery(array|string $query = ''): void
    {
        if (is_string($query) && $query !== '') {
            $parsed = [];
            parse_str($query, $parsed);
            $query = $parsed;
        }

        if (!is_array($query)) {
            $query = [];
        }

        $this->query = $query;
        $this->query_vars = array_merge(self::$defaultVars, $query);

        // Normalize aliases.
        if (isset($query['numberposts']) && !isset($query['posts_per_page'])) {
            $this->query_vars['posts_per_page'] = (int) $query['numberposts'];
        }
        if (isset($query['limit']) && !isset($query['posts_per_page']) && !isset($query['numberposts'])) {
            $this->query_vars['posts_per_page'] = (int) $query['limit'];
        }
        if (isset($query['showposts']) && !isset($query['posts_per_page'])) {
            $this->query_vars['posts_per_page'] = (int) $query['showposts'];
        }
        if (isset($query['include']) && is_array($query['include']) && empty($this->query_vars['post__in'])) {
            $this->query_vars['post__in'] = $query['include'];
        }
        if (isset($query['exclude']) && is_array($query['exclude']) && empty($this->query_vars['post__not_in'])) {
            $this->query_vars['post__not_in'] = $query['exclude'];
        }

        $this->query_vars['p'] = (int) $this->query_vars['p'];
        $this->query_vars['page_id'] = (int) $this->query_vars['page_id'];
        $this->query_vars['author'] = (int) $this->query_vars['author'];
        $this->query_vars['posts_per_page'] = (int) $this->query_vars['posts_per_page'];
        $this->query_vars['paged'] = max(1, (int) $this->query_vars['paged']);
        $this->query_vars['page'] = max(1, (int) $this->query_vars['page']);
        $this->query_vars['offset'] = (int) $this->query_vars['offset'];
        $this->query_vars['year'] = (int) $this->query_vars['year'];
        $this->query_vars['monthnum'] = (int) $this->query_vars['monthnum'];
        $this->query_vars['day'] = (int) $this->query_vars['day'];
        $this->query_vars['name'] = is_string($this->query_vars['name'])
            ? trim($this->query_vars['name'])
            : '';
        $this->query_vars['pagename'] = is_string($this->query_vars['pagename'])
            ? trim($this->query_vars['pagename'], '/')
            : '';
        $this->query_vars['s'] = is_string($this->query_vars['s'])
            ? trim($this->query_vars['s'])
            : '';
        $this->query_vars['order'] = strtoupper((string) $this->query_vars['order']) === 'ASC' ? 'ASC' : 'DESC';
        $this->query_vars['nopaging'] = !empty($this->query_vars['nopaging'])
            || (int) $this->query_vars['posts_per_page'] === -1;
        $this->query_vars['no_found_rows'] = !empty($this->query_vars['no_found_rows']);

        $this->query_vars['post__in'] = $this->normalizeIdList($this->query_vars['post__in']);
        $this->query_vars['post__not_in'] = $this->normalizeIdList($this->query_vars['post__not_in']);
        $this->query_vars['author__in'] = $this->normalizeIdList($this->query_vars['author__in']);
        $this->query_vars['author__not_in'] = $this->normalizeIdList($this->query_vars['author__not_in']);
        $this->query_vars['post_parent__in'] = $this->normalizeIdList($this->query_vars['post_parent__in']);
        $this->query_vars['post_parent__not_in'] = $this->normalizeIdList($this->query_vars['post_parent__not_in']);

        $this->query_vars['cat'] = (int) $this->query_vars['cat'];
        $this->query_vars['tag_id'] = (int) $this->query_vars['tag_id'];
        $this->query_vars['category_name'] = is_string($this->query_vars['category_name'])
            ? trim($this->query_vars['category_name'], '/')
            : '';
        $this->query_vars['tag'] = is_string($this->query_vars['tag'])
            ? trim((string) $this->query_vars['tag'])
            : '';
        $this->query_vars['category__in'] = $this->normalizeIdList($this->query_vars['category__in']);
        $this->query_vars['category__not_in'] = $this->normalizeIdList($this->query_vars['category__not_in']);
        $this->query_vars['category__and'] = $this->normalizeIdList($this->query_vars['category__and']);
        $this->query_vars['tag__in'] = $this->normalizeIdList($this->query_vars['tag__in']);
        $this->query_vars['tag__not_in'] = $this->normalizeIdList($this->query_vars['tag__not_in']);
        $this->query_vars['tag__and'] = $this->normalizeIdList($this->query_vars['tag__and']);
        $this->query_vars['tag_slug__in'] = $this->normalizeSlugList($this->query_vars['tag_slug__in']);
        if (!is_array($this->query_vars['tax_query'])) {
            $this->query_vars['tax_query'] = [];
        }

        $this->fillQueryFlags();
    }

    /**
     * Get a query var.
     */
    public function get(string $queryVar, mixed $default = ''): mixed
    {
        return array_key_exists($queryVar, $this->query_vars)
            ? $this->query_vars[$queryVar]
            : $default;
    }

    /**
     * Set a query var (does not re-run the query).
     */
    public function set(string $queryVar, mixed $value): void
    {
        $this->query_vars[$queryVar] = $value;
    }

    /**
     * Execute the query and populate $posts / pagination fields.
     *
     * @return list<AP_Post|int>
     */
    public function getPosts(): array
    {
        if ($this->query_vars === []) {
            $this->parseQuery($this->query);
        }

        $db = $this->resolveDb();
        AP_Post::ensureBuiltins();

        // Resolve hierarchical pagename path to a page_id when needed.
        if ($this->query_vars['pagename'] !== '' && (int) $this->query_vars['page_id'] === 0) {
            $resolved = $this->resolvePagename($this->query_vars['pagename'], $db);
            if ($resolved > 0) {
                $this->query_vars['page_id'] = $resolved;
            } else {
                // Path not found — empty result, treat as 404 for singular page request.
                $this->posts = [];
                $this->post_count = 0;
                $this->found_posts = 0;
                $this->max_num_pages = 0;
                $this->is_404 = true;
                $this->request = '';

                return $this->posts;
            }
        }

        // Author by login/slug → author ID.
        if (
            $this->query_vars['author'] === 0
            && is_string($this->query_vars['author_name'])
            && $this->query_vars['author_name'] !== ''
            && class_exists('AP_User', false)
        ) {
            $user = AP_User::getBy('login', (string) $this->query_vars['author_name'], $db);
            if ($user === null) {
                $user = AP_User::getBy('slug', (string) $this->query_vars['author_name'], $db);
            }
            if ($user !== null) {
                $this->query_vars['author'] = $user->ID;
            } else {
                $this->posts = [];
                $this->post_count = 0;
                $this->found_posts = 0;
                $this->max_num_pages = 0;

                return $this->posts;
            }
        }

        [$where, $params, $join] = $this->buildWhere($db);
        $orderby = $this->buildOrderBy($db);
        [$limitSql, $limitParams] = $this->buildLimit();

        $postsTable = $db->quoteIdentifier($db->table('posts'));
        $idCol = $postsTable . '.' . $db->quoteIdentifier('ID');
        $fields = strtolower(trim((string) $this->query_vars['fields']));
        if ($fields === 'ids') {
            $select = ($join !== '' ? 'DISTINCT ' : '') . $idCol;
        } else {
            $select = $this->selectColumns($db);
        }

        $from = $postsTable;
        if ($join !== '') {
            $from .= ' ' . $join;
        }

        // found_posts via separate COUNT (portable; avoids SQL_CALC_FOUND_ROWS).
        if (!$this->query_vars['no_found_rows'] && !$this->query_vars['nopaging']) {
            $countSql = 'SELECT COUNT(DISTINCT ' . $idCol . ') FROM ' . $from;
            if ($where !== '') {
                $countSql .= ' WHERE ' . $where;
            }
            $count = $db->getVar($countSql, $params);
            $this->found_posts = max(0, (int) $count);
        }

        $sql = 'SELECT ' . $select . ' FROM ' . $from;
        if ($where !== '') {
            $sql .= ' WHERE ' . $where;
        }
        if ($fields !== 'ids' && $join !== '') {
            $sql .= ' GROUP BY ' . $idCol;
        }
        $sql .= ' ORDER BY ' . $orderby;
        $sql .= $limitSql;

        $allParams = array_merge($params, $limitParams);
        $this->request = $sql;

        if ($fields === 'ids') {
            $ids = $db->getCol($sql, $allParams);
            $this->posts = array_values(array_map(static fn ($id): int => (int) $id, $ids));
        } else {
            $rows = $db->getResults($sql, $allParams);
            $this->posts = [];
            foreach ($rows as $row) {
                $this->posts[] = AP_Post::fromRow($row);
            }
        }

        $this->post_count = count($this->posts);

        if ($this->query_vars['nopaging'] || $this->query_vars['no_found_rows']) {
            if ($this->found_posts === 0) {
                $this->found_posts = $this->post_count;
            }
            $this->max_num_pages = $this->post_count > 0 ? 1 : 0;
        } else {
            $perPage = max(1, (int) $this->query_vars['posts_per_page']);
            $this->max_num_pages = $this->found_posts > 0
                ? (int) ceil($this->found_posts / $perPage)
                : 0;
        }

        // Singular 404 when an explicit ID/slug was requested but nothing matched.
        if ($this->is_singular && $this->post_count === 0) {
            $this->is_404 = true;
        }

        // Seed current post without advancing the loop.
        if ($this->post_count > 0 && $this->posts[0] instanceof AP_Post) {
            $this->post = $this->posts[0];
        }

        return $this->posts;
    }

    /**
     * Whether there are more posts in the loop.
     */
    public function havePosts(): bool
    {
        if ($this->current_post + 1 < $this->post_count) {
            return true;
        }

        if ($this->current_post + 1 === $this->post_count && $this->post_count > 0) {
            // End of loop — mirror WP rewind-ready state.
            $this->current_post = -1;
            $this->in_the_loop = false;
        }

        $this->in_the_loop = false;

        return false;
    }

    /**
     * Advance the loop and set $this->post (and globals via helpers).
     */
    public function thePost(): void
    {
        $this->in_the_loop = true;
        $this->nextPost();
    }

    /**
     * Move to the next post without setting in_the_loop.
     */
    public function nextPost(): ?AP_Post
    {
        $this->current_post++;
        $item = $this->posts[$this->current_post] ?? null;

        if ($item instanceof AP_Post) {
            $this->post = $item;
        } elseif (is_int($item) && $item > 0) {
            $loaded = AP_Post::get($item, $this->resolveDb());
            $this->post = $loaded;
        } else {
            $this->post = null;
        }

        return $this->post;
    }

    /**
     * Reset loop cursor to the start.
     */
    public function rewindPosts(): void
    {
        $this->current_post = -1;
        if ($this->post_count > 0 && ($this->posts[0] ?? null) instanceof AP_Post) {
            $this->post = $this->posts[0];
        }
    }

    /**
     * Whether the query matched any posts.
     */
    public function haveResults(): bool
    {
        return $this->post_count > 0;
    }

    // -------------------------------------------------------------------------
    // SQL builders
    // -------------------------------------------------------------------------

    /**
     * @return array{0: string, 1: list<mixed>, 2: string} where, params, join
     */
    private function buildWhere(AP_DB $db): array
    {
        $postsTable = $db->quoteIdentifier($db->table('posts'));
        $where = [];
        $params = [];
        $join = '';

        // --- ID / slug singular ------------------------------------------------
        $p = (int) $this->query_vars['p'];
        $pageId = (int) $this->query_vars['page_id'];
        if ($p > 0) {
            $where[] = $postsTable . '.' . $db->quoteIdentifier('ID') . ' = ?';
            $params[] = $p;
        } elseif ($pageId > 0) {
            $where[] = $postsTable . '.' . $db->quoteIdentifier('ID') . ' = ?';
            $params[] = $pageId;
        }

        $name = (string) $this->query_vars['name'];
        if ($name !== '' && $p < 1 && $pageId < 1) {
            $where[] = $postsTable . '.' . $db->quoteIdentifier('post_name') . ' = ?';
            $params[] = $name;
        }

        // --- post__in / post__not_in ------------------------------------------
        $postIn = $this->query_vars['post__in'];
        if (is_array($postIn) && $postIn !== []) {
            $placeholders = implode(', ', array_fill(0, count($postIn), '?'));
            $where[] = $postsTable . '.' . $db->quoteIdentifier('ID') . ' IN (' . $placeholders . ')';
            foreach ($postIn as $id) {
                $params[] = $id;
            }
        }

        $postNotIn = $this->query_vars['post__not_in'];
        if (is_array($postNotIn) && $postNotIn !== []) {
            $placeholders = implode(', ', array_fill(0, count($postNotIn), '?'));
            $where[] = $postsTable . '.' . $db->quoteIdentifier('ID') . ' NOT IN (' . $placeholders . ')';
            foreach ($postNotIn as $id) {
                $params[] = $id;
            }
        }

        // --- post_type --------------------------------------------------------
        $types = $this->normalizeKeyList($this->query_vars['post_type'] ?? 'post');
        // When querying by page_id alone, default type may still be "post" — allow any
        // type for pure ID lookups so singles resolve regardless of default type.
        if ($p > 0 || $pageId > 0) {
            // Skip type constraint unless the caller explicitly passed post_type.
            if (!array_key_exists('post_type', $this->query)) {
                $types = [];
            }
        }
        if ($types !== []) {
            $placeholders = implode(', ', array_fill(0, count($types), '?'));
            $where[] = $postsTable . '.' . $db->quoteIdentifier('post_type') . ' IN (' . $placeholders . ')';
            foreach ($types as $t) {
                $params[] = $t;
            }
        }

        // --- post_status ------------------------------------------------------
        $statusArg = $this->query_vars['post_status'] ?? 'publish';
        if ($statusArg !== 'any' && $statusArg !== '' && $statusArg !== null) {
            $statuses = $this->normalizeKeyList($statusArg);
            if ($statuses !== []) {
                $placeholders = implode(', ', array_fill(0, count($statuses), '?'));
                $where[] = $postsTable . '.' . $db->quoteIdentifier('post_status')
                    . ' IN (' . $placeholders . ')';
                foreach ($statuses as $s) {
                    $params[] = $s;
                }
            }
        }

        // --- post_parent ------------------------------------------------------
        if ($this->query_vars['post_parent'] !== '' && $this->query_vars['post_parent'] !== null) {
            $where[] = $postsTable . '.' . $db->quoteIdentifier('post_parent') . ' = ?';
            $params[] = (int) $this->query_vars['post_parent'];
        }

        $parentIn = $this->query_vars['post_parent__in'];
        if (is_array($parentIn) && $parentIn !== []) {
            $placeholders = implode(', ', array_fill(0, count($parentIn), '?'));
            $where[] = $postsTable . '.' . $db->quoteIdentifier('post_parent')
                . ' IN (' . $placeholders . ')';
            foreach ($parentIn as $id) {
                $params[] = $id;
            }
        }

        $parentNotIn = $this->query_vars['post_parent__not_in'];
        if (is_array($parentNotIn) && $parentNotIn !== []) {
            $placeholders = implode(', ', array_fill(0, count($parentNotIn), '?'));
            $where[] = $postsTable . '.' . $db->quoteIdentifier('post_parent')
                . ' NOT IN (' . $placeholders . ')';
            foreach ($parentNotIn as $id) {
                $params[] = $id;
            }
        }

        // --- author -----------------------------------------------------------
        $author = (int) $this->query_vars['author'];
        if ($author > 0) {
            $where[] = $postsTable . '.' . $db->quoteIdentifier('post_author') . ' = ?';
            $params[] = $author;
        }

        $authorIn = $this->query_vars['author__in'];
        if (is_array($authorIn) && $authorIn !== []) {
            $placeholders = implode(', ', array_fill(0, count($authorIn), '?'));
            $where[] = $postsTable . '.' . $db->quoteIdentifier('post_author')
                . ' IN (' . $placeholders . ')';
            foreach ($authorIn as $id) {
                $params[] = $id;
            }
        }

        $authorNotIn = $this->query_vars['author__not_in'];
        if (is_array($authorNotIn) && $authorNotIn !== []) {
            $placeholders = implode(', ', array_fill(0, count($authorNotIn), '?'));
            $where[] = $postsTable . '.' . $db->quoteIdentifier('post_author')
                . ' NOT IN (' . $placeholders . ')';
            foreach ($authorNotIn as $id) {
                $params[] = $id;
            }
        }

        // --- search -----------------------------------------------------------
        $s = (string) $this->query_vars['s'];
        if ($s !== '') {
            $like = $this->searchLike($s);
            $where[] = '('
                . $postsTable . '.' . $db->quoteIdentifier('post_title') . ' LIKE ? OR '
                . $postsTable . '.' . $db->quoteIdentifier('post_content') . ' LIKE ? OR '
                . $postsTable . '.' . $db->quoteIdentifier('post_excerpt') . ' LIKE ?'
                . ')';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        // --- date (portable LIKE on Y-m-d H:i:s) ------------------------------
        $year = (int) $this->query_vars['year'];
        $month = (int) $this->query_vars['monthnum'];
        $day = (int) $this->query_vars['day'];
        if ($year > 0 || $month > 0 || $day > 0) {
            $prefix = '';
            if ($year > 0) {
                $prefix .= sprintf('%04d', $year);
            } else {
                $prefix .= '____';
            }
            if ($month > 0 || $day > 0) {
                $prefix .= '-' . ($month > 0 ? sprintf('%02d', $month) : '__');
            }
            if ($day > 0) {
                $prefix .= '-' . sprintf('%02d', $day);
            }
            $where[] = $postsTable . '.' . $db->quoteIdentifier('post_date') . ' LIKE ?';
            $params[] = $prefix . '%';
        }

        // --- simple meta_key / meta_value (JOIN params must precede WHERE) ----
        $metaKey = is_string($this->query_vars['meta_key'])
            ? (string) $this->query_vars['meta_key']
            : '';
        $joinParams = [];
        if ($metaKey !== '') {
            $metaTable = $db->quoteIdentifier($db->table('postmeta'));
            $join = 'INNER JOIN ' . $metaTable
                . ' ON ' . $postsTable . '.' . $db->quoteIdentifier('ID')
                . ' = ' . $metaTable . '.' . $db->quoteIdentifier('post_id')
                . ' AND ' . $metaTable . '.' . $db->quoteIdentifier('meta_key') . ' = ?';
            $joinParams[] = $metaKey;

            $metaValue = $this->query_vars['meta_value'];
            if ($metaValue !== '' && $metaValue !== null) {
                $compare = strtoupper(trim((string) $this->query_vars['meta_compare']));
                $allowed = ['=', '!=', '>', '>=', '<', '<=', 'LIKE', 'NOT LIKE'];
                if (!in_array($compare, $allowed, true)) {
                    $compare = '=';
                }
                $where[] = $metaTable . '.' . $db->quoteIdentifier('meta_value')
                    . ' ' . $compare . ' ?';
                $params[] = (string) $metaValue;
            }
        }

        // --- taxonomies (category / tag / tax_query) via EXISTS subqueries ----
        // EXISTS keeps join/param ordering simple and works on all drivers.
        $taxClauses = $this->buildTaxonomyWhere($db, $postsTable);
        foreach ($taxClauses as $clause) {
            $where[] = $clause['sql'];
            foreach ($clause['params'] as $p) {
                $params[] = $p;
            }
        }

        $whereSql = $where === [] ? '' : implode(' AND ', $where);
        $params = array_merge($joinParams, $params);

        return [$whereSql, $params, $join];
    }

    /**
     * Build WHERE fragments for category/tag/tax_query vars.
     *
     * @return list<array{sql: string, params: list<mixed>}>
     */
    private function buildTaxonomyWhere(AP_DB $db, string $postsTable): array
    {
        $clauses = [];
        $idCol = $postsTable . '.' . $db->quoteIdentifier('ID');

        // Convenience vars → internal tax_query shape.
        $taxQuery = is_array($this->query_vars['tax_query'])
            ? $this->query_vars['tax_query']
            : [];

        $cat = (int) $this->query_vars['cat'];
        if ($cat > 0) {
            $taxQuery[] = [
                'taxonomy' => 'category',
                'field' => 'term_id',
                'terms' => [$cat],
                'operator' => 'IN',
            ];
        } elseif ($cat < 0) {
            $taxQuery[] = [
                'taxonomy' => 'category',
                'field' => 'term_id',
                'terms' => [abs($cat)],
                'operator' => 'NOT IN',
            ];
        }

        $catName = (string) $this->query_vars['category_name'];
        if ($catName !== '') {
            // Last path segment is the leaf category slug.
            $parts = array_values(array_filter(explode('/', $catName), static fn ($p) => $p !== ''));
            $slug = $parts !== [] ? (string) end($parts) : $catName;
            $taxQuery[] = [
                'taxonomy' => 'category',
                'field' => 'slug',
                'terms' => [$slug],
                'operator' => 'IN',
            ];
        }

        $catIn = $this->query_vars['category__in'];
        if (is_array($catIn) && $catIn !== []) {
            $taxQuery[] = [
                'taxonomy' => 'category',
                'field' => 'term_id',
                'terms' => $catIn,
                'operator' => 'IN',
            ];
        }
        $catNotIn = $this->query_vars['category__not_in'];
        if (is_array($catNotIn) && $catNotIn !== []) {
            $taxQuery[] = [
                'taxonomy' => 'category',
                'field' => 'term_id',
                'terms' => $catNotIn,
                'operator' => 'NOT IN',
            ];
        }
        $catAnd = $this->query_vars['category__and'];
        if (is_array($catAnd) && $catAnd !== []) {
            $taxQuery[] = [
                'taxonomy' => 'category',
                'field' => 'term_id',
                'terms' => $catAnd,
                'operator' => 'AND',
            ];
        }

        $tagId = (int) $this->query_vars['tag_id'];
        if ($tagId > 0) {
            $taxQuery[] = [
                'taxonomy' => 'post_tag',
                'field' => 'term_id',
                'terms' => [$tagId],
                'operator' => 'IN',
            ];
        }

        $tag = (string) $this->query_vars['tag'];
        if ($tag !== '') {
            $slugs = preg_split('/[\s,]+/', $tag) ?: [];
            $slugs = array_values(array_filter(array_map('trim', $slugs)));
            if ($slugs !== []) {
                $taxQuery[] = [
                    'taxonomy' => 'post_tag',
                    'field' => 'slug',
                    'terms' => $slugs,
                    'operator' => count($slugs) > 1 ? 'AND' : 'IN',
                ];
            }
        }

        $tagIn = $this->query_vars['tag__in'];
        if (is_array($tagIn) && $tagIn !== []) {
            $taxQuery[] = [
                'taxonomy' => 'post_tag',
                'field' => 'term_id',
                'terms' => $tagIn,
                'operator' => 'IN',
            ];
        }
        $tagNotIn = $this->query_vars['tag__not_in'];
        if (is_array($tagNotIn) && $tagNotIn !== []) {
            $taxQuery[] = [
                'taxonomy' => 'post_tag',
                'field' => 'term_id',
                'terms' => $tagNotIn,
                'operator' => 'NOT IN',
            ];
        }
        $tagAnd = $this->query_vars['tag__and'];
        if (is_array($tagAnd) && $tagAnd !== []) {
            $taxQuery[] = [
                'taxonomy' => 'post_tag',
                'field' => 'term_id',
                'terms' => $tagAnd,
                'operator' => 'AND',
            ];
        }
        $tagSlugIn = $this->query_vars['tag_slug__in'];
        if (is_array($tagSlugIn) && $tagSlugIn !== []) {
            $taxQuery[] = [
                'taxonomy' => 'post_tag',
                'field' => 'slug',
                'terms' => $tagSlugIn,
                'operator' => 'IN',
            ];
        }

        $rel = $db->quoteIdentifier($db->table('term_relationships'));
        $tt = $db->quoteIdentifier($db->table('term_taxonomy'));
        $terms = $db->quoteIdentifier($db->table('terms'));

        foreach ($taxQuery as $clause) {
            if (!is_array($clause)) {
                continue;
            }
            // Skip relation keys / non-clauses.
            if (isset($clause['relation']) && !isset($clause['taxonomy'])) {
                continue;
            }
            $taxonomy = $this->sanitizeKey((string) ($clause['taxonomy'] ?? ''));
            if ($taxonomy === '') {
                continue;
            }
            $field = strtolower((string) ($clause['field'] ?? 'term_id'));
            if (!in_array($field, ['term_id', 'slug', 'name', 'term_taxonomy_id'], true)) {
                $field = 'term_id';
            }
            $operator = strtoupper((string) ($clause['operator'] ?? 'IN'));
            if (!in_array($operator, ['IN', 'NOT IN', 'AND'], true)) {
                $operator = 'IN';
            }
            $rawTerms = $clause['terms'] ?? [];
            if (!is_array($rawTerms)) {
                $rawTerms = [$rawTerms];
            }
            $rawTerms = array_values(array_filter($rawTerms, static fn ($t) => $t !== '' && $t !== null));
            if ($rawTerms === []) {
                continue;
            }

            if ($operator === 'AND') {
                // Each term must match (nested EXISTS).
                foreach ($rawTerms as $termVal) {
                    $built = $this->singleTaxExists(
                        $db,
                        $idCol,
                        $rel,
                        $tt,
                        $terms,
                        $taxonomy,
                        $field,
                        [$termVal],
                        'IN'
                    );
                    if ($built !== null) {
                        $clauses[] = $built;
                    }
                }
                continue;
            }

            $built = $this->singleTaxExists(
                $db,
                $idCol,
                $rel,
                $tt,
                $terms,
                $taxonomy,
                $field,
                $rawTerms,
                $operator
            );
            if ($built !== null) {
                $clauses[] = $built;
            }
        }

        return $clauses;
    }

    /**
     * @param list<mixed> $termValues
     *
     * @return array{sql: string, params: list<mixed>}|null
     */
    private function singleTaxExists(
        AP_DB $db,
        string $idCol,
        string $rel,
        string $tt,
        string $terms,
        string $taxonomy,
        string $field,
        array $termValues,
        string $operator
    ): ?array {
        $ph = implode(', ', array_fill(0, count($termValues), '?'));
        $params = [];

        if ($field === 'term_taxonomy_id') {
            $sub = 'SELECT 1 FROM ' . $rel . ' tr'
                . ' WHERE tr.object_id = ' . $idCol
                . ' AND tr.term_taxonomy_id IN (' . $ph . ')';
            foreach ($termValues as $v) {
                $params[] = (int) $v;
            }
        } elseif ($field === 'term_id') {
            $sub = 'SELECT 1 FROM ' . $rel . ' tr'
                . ' INNER JOIN ' . $tt . ' ttx ON tr.term_taxonomy_id = ttx.term_taxonomy_id'
                . ' WHERE tr.object_id = ' . $idCol
                . ' AND ttx.taxonomy = ?'
                . ' AND ttx.term_id IN (' . $ph . ')';
            $params[] = $taxonomy;
            foreach ($termValues as $v) {
                $params[] = (int) $v;
            }
        } else {
            // slug or name
            $col = $field === 'name' ? 'name' : 'slug';
            $sub = 'SELECT 1 FROM ' . $rel . ' tr'
                . ' INNER JOIN ' . $tt . ' ttx ON tr.term_taxonomy_id = ttx.term_taxonomy_id'
                . ' INNER JOIN ' . $terms . ' t ON ttx.term_id = t.term_id'
                . ' WHERE tr.object_id = ' . $idCol
                . ' AND ttx.taxonomy = ?'
                . ' AND t.' . $col . ' IN (' . $ph . ')';
            $params[] = $taxonomy;
            foreach ($termValues as $v) {
                $params[] = (string) $v;
            }
        }

        if ($operator === 'NOT IN') {
            return ['sql' => 'NOT EXISTS (' . $sub . ')', 'params' => $params];
        }

        return ['sql' => 'EXISTS (' . $sub . ')', 'params' => $params];
    }

    private function buildOrderBy(AP_DB $db): string
    {
        $postsTable = $db->quoteIdentifier($db->table('posts'));
        $order = $this->query_vars['order'] === 'ASC' ? 'ASC' : 'DESC';
        $orderby = $this->query_vars['orderby'];

        // post__in order: FIELD() is MySQL-only — emulate with CASE for portability.
        if ($orderby === 'post__in' || $orderby === 'post_in') {
            $ids = $this->query_vars['post__in'];
            if (is_array($ids) && $ids !== []) {
                $cases = [];
                $i = 1;
                foreach ($ids as $id) {
                    $cases[] = 'WHEN ' . (int) $id . ' THEN ' . $i;
                    $i++;
                }

                return 'CASE ' . $postsTable . '.' . $db->quoteIdentifier('ID') . ' '
                    . implode(' ', $cases)
                    . ' ELSE ' . $i . ' END ASC';
            }
            $orderby = 'date';
        }

        $map = [
            'date' => 'post_date',
            'post_date' => 'post_date',
            'title' => 'post_title',
            'post_title' => 'post_title',
            'modified' => 'post_modified',
            'post_modified' => 'post_modified',
            'menu_order' => 'menu_order',
            'parent' => 'post_parent',
            'post_parent' => 'post_parent',
            'ID' => 'ID',
            'id' => 'ID',
            'author' => 'post_author',
            'post_author' => 'post_author',
            'name' => 'post_name',
            'post_name' => 'post_name',
            'rand' => null,
            'random' => null,
            'none' => null,
            'comment_count' => 'comment_count',
        ];

        if (is_string($orderby) && str_contains($orderby, ' ')) {
            // Multi orderby: "title ASC, date DESC"
            $parts = [];
            foreach (explode(',', $orderby) as $chunk) {
                $chunk = trim($chunk);
                if ($chunk === '') {
                    continue;
                }
                $bits = preg_split('/\s+/', $chunk) ?: [];
                $col = $bits[0] ?? 'date';
                $dir = isset($bits[1]) && strtoupper($bits[1]) === 'ASC' ? 'ASC' : $order;
                $resolved = $map[$col] ?? (in_array($col, array_values(array_filter($map)), true) ? $col : 'post_date');
                if ($resolved === null) {
                    continue;
                }
                $parts[] = $postsTable . '.' . $db->quoteIdentifier($resolved) . ' ' . $dir;
            }
            if ($parts !== []) {
                return implode(', ', $parts)
                    . ', ' . $postsTable . '.' . $db->quoteIdentifier('ID') . ' ' . $order;
            }
            $orderby = 'date';
        }

        $key = is_string($orderby) ? $orderby : 'date';
        if ($key === 'rand' || $key === 'random') {
            return $this->randomOrderExpr($db);
        }
        if ($key === 'none') {
            return $postsTable . '.' . $db->quoteIdentifier('ID') . ' ASC';
        }

        $column = $map[$key] ?? 'post_date';
        if ($column === null) {
            $column = 'post_date';
        }

        return $postsTable . '.' . $db->quoteIdentifier($column) . ' ' . $order
            . ', ' . $postsTable . '.' . $db->quoteIdentifier('ID') . ' ' . $order;
    }

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    private function buildLimit(): array
    {
        if ($this->query_vars['nopaging']) {
            return ['', []];
        }

        $perPage = (int) $this->query_vars['posts_per_page'];
        if ($perPage < 1) {
            $perPage = 10;
        }

        $offset = (int) $this->query_vars['offset'];
        if ($offset < 0) {
            $paged = max(1, (int) $this->query_vars['paged']);
            $offset = ($paged - 1) * $perPage;
        }

        // LIMIT/OFFSET as integers only (not bound) — values are cast ints.
        return [' LIMIT ' . $perPage . ' OFFSET ' . $offset, []];
    }

    private function randomOrderExpr(AP_DB $db): string
    {
        return match ($db->getDriver()) {
            'mysql' => 'RAND()',
            'pgsql' => 'RANDOM()',
            default => 'RANDOM()', // sqlite
        };
    }

    private function selectColumns(AP_DB $db): string
    {
        $postsTable = $db->quoteIdentifier($db->table('posts'));
        $cols = [
            'ID',
            'post_author',
            'post_date',
            'post_date_gmt',
            'post_content',
            'post_title',
            'post_excerpt',
            'post_status',
            'comment_status',
            'ping_status',
            'post_password',
            'post_name',
            'to_ping',
            'pinged',
            'post_modified',
            'post_modified_gmt',
            'post_content_filtered',
            'post_parent',
            'guid',
            'menu_order',
            'post_type',
            'post_mime_type',
            'comment_count',
        ];

        $quoted = [];
        foreach ($cols as $col) {
            $quoted[] = $postsTable . '.' . $db->quoteIdentifier($col);
        }

        return implode(', ', $quoted);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function fillQueryFlags(): void
    {
        $this->resetConditionals();

        $qv = $this->query_vars;
        $hasP = (int) $qv['p'] > 0 || (int) $qv['page_id'] > 0
            || (string) $qv['name'] !== '' || (string) $qv['pagename'] !== '';

        if ((int) $qv['page_id'] > 0 || (string) $qv['pagename'] !== '') {
            $this->is_page = true;
            $this->is_singular = true;
            $this->is_single = false;
        } elseif ((int) $qv['p'] > 0 || (string) $qv['name'] !== '') {
            $this->is_single = true;
            $this->is_singular = true;
        }

        if ((string) $qv['s'] !== '') {
            $this->is_search = true;
        }

        if (
            (int) $qv['author'] > 0
            || (is_array($qv['author__in']) && $qv['author__in'] !== [])
            || (is_string($qv['author_name']) && $qv['author_name'] !== '')
        ) {
            $this->is_author = true;
            $this->is_archive = true;
        }

        if ((int) $qv['year'] > 0 || (int) $qv['monthnum'] > 0 || (int) $qv['day'] > 0) {
            $this->is_date = true;
            $this->is_archive = true;
            if ((int) $qv['day'] > 0) {
                $this->is_day = true;
            } elseif ((int) $qv['monthnum'] > 0) {
                $this->is_month = true;
            } else {
                $this->is_year = true;
            }
        }

        $hasCat = (int) $qv['cat'] !== 0
            || (string) $qv['category_name'] !== ''
            || (is_array($qv['category__in']) && $qv['category__in'] !== [])
            || (is_array($qv['category__and']) && $qv['category__and'] !== []);
        $hasTag = (int) $qv['tag_id'] > 0
            || (string) $qv['tag'] !== ''
            || (is_array($qv['tag__in']) && $qv['tag__in'] !== [])
            || (is_array($qv['tag__and']) && $qv['tag__and'] !== [])
            || (is_array($qv['tag_slug__in']) && $qv['tag_slug__in'] !== []);
        $hasTaxQuery = is_array($qv['tax_query']) && $qv['tax_query'] !== [];

        if ($hasCat) {
            $this->is_category = true;
            $this->is_tax = true;
            $this->is_archive = true;
        }
        if ($hasTag) {
            $this->is_tag = true;
            $this->is_tax = true;
            $this->is_archive = true;
        }
        if ($hasTaxQuery && !$this->is_category && !$this->is_tag) {
            $this->is_tax = true;
            $this->is_archive = true;
        }

        $types = $this->normalizeKeyList($qv['post_type'] ?? 'post');
        if (
            !$this->is_singular
            && !$this->is_search
            && !$this->is_author
            && !$this->is_date
            && !$this->is_tax
        ) {
            if ($types === ['post'] || $types === []) {
                $this->is_home = true;
            } elseif (count($types) === 1 && $types[0] !== 'post' && $types[0] !== 'page') {
                $this->is_post_type_archive = true;
                $this->is_archive = true;
            } elseif (count($types) === 1 && $types[0] === 'page' && !$hasP) {
                // Page list without singular id — not home.
                $this->is_archive = true;
            } else {
                $this->is_home = true;
            }
        }

        // Front page / posts page (Reading settings) — may be set via query vars.
        if (!empty($qv['ap_is_posts_page'])) {
            $this->is_posts_page = true;
            $this->is_home = true;
            $this->is_page = false;
            $this->is_singular = false;
            $this->is_single = false;
        }
        if (!empty($qv['ap_is_front_page'])) {
            $this->is_front_page = true;
            // Static front page: singular page that is also the site front.
            // Posts on front: blog home is the front page.
            if (!$this->is_page && $this->is_home) {
                $this->is_front_page = true;
            }
        } elseif ($this->is_home && !$this->is_posts_page) {
            // Default: blog posts on front when no static front page was requested.
            $this->is_front_page = true;
        }

        $feed = is_string($qv['feed'] ?? null) ? trim((string) $qv['feed']) : '';
        if ($feed !== '') {
            $this->is_feed = true;
        }
    }

    private function resetConditionals(): void
    {
        $this->is_single = false;
        $this->is_preview = false;
        $this->is_page = false;
        $this->is_archive = false;
        $this->is_date = false;
        $this->is_year = false;
        $this->is_month = false;
        $this->is_day = false;
        $this->is_author = false;
        $this->is_search = false;
        $this->is_category = false;
        $this->is_tag = false;
        $this->is_tax = false;
        $this->is_home = false;
        $this->is_front_page = false;
        $this->is_posts_page = false;
        $this->is_feed = false;
        $this->is_404 = false;
        $this->is_singular = false;
        $this->is_post_type_archive = false;
    }

    /**
     * Resolve a hierarchical page path (parent/child) to a page ID.
     */
    private function resolvePagename(string $path, AP_DB $db): int
    {
        $path = trim($path, '/');
        if ($path === '') {
            return 0;
        }

        $parts = array_values(array_filter(explode('/', $path), static fn (string $p): bool => $p !== ''));
        if ($parts === []) {
            return 0;
        }

        $parent = 0;
        $currentId = 0;
        foreach ($parts as $slug) {
            $table = $db->quoteIdentifier($db->table('posts'));
            $row = $db->getRow(
                'SELECT ' . $db->quoteIdentifier('ID') . ' FROM ' . $table
                . ' WHERE ' . $db->quoteIdentifier('post_name') . ' = ?'
                . ' AND ' . $db->quoteIdentifier('post_type') . ' = ?'
                . ' AND ' . $db->quoteIdentifier('post_parent') . ' = ?'
                . ' LIMIT 1',
                [$slug, 'page', $parent]
            );
            if ($row === null) {
                return 0;
            }
            $currentId = (int) (is_object($row) ? $row->ID : $row['ID']);
            $parent = $currentId;
        }

        return $currentId;
    }

    private function searchLike(string $s): string
    {
        // Escape LIKE wildcards in user input.
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);

        return '%' . $escaped . '%';
    }

    /**
     * @return list<int>
     */
    private function normalizeIdList(mixed $value): array
    {
        if ($value === null || $value === '' || $value === []) {
            return [];
        }
        if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
            $id = (int) $value;

            return $id > 0 ? [$id] : [];
        }
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            $id = (int) $item;
            if ($id > 0) {
                $out[] = $id;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @return list<string>
     */
    private function normalizeKeyList(mixed $value): array
    {
        if ($value === null || $value === '' || $value === 'any') {
            return [];
        }
        if (is_string($value)) {
            $key = $this->sanitizeKey($value);

            return $key !== '' ? [$key] : [];
        }
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (!is_string($item) && !is_int($item)) {
                continue;
            }
            $key = $this->sanitizeKey((string) $item);
            if ($key !== '') {
                $out[] = $key;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @return list<string>
     */
    private function normalizeSlugList(mixed $value): array
    {
        if ($value === null || $value === '' || $value === []) {
            return [];
        }
        if (is_string($value)) {
            $parts = preg_split('/[\s,]+/', $value) ?: [];
            $value = $parts;
        }
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (!is_string($item) && !is_int($item)) {
                continue;
            }
            $slug = strtolower(trim((string) $item));
            $slug = preg_replace('/[^a-z0-9_\-]/', '', $slug) ?? '';
            if ($slug !== '') {
                $out[] = $slug;
            }
        }

        return array_values(array_unique($out));
    }

    private function sanitizeKey(string $key): string
    {
        $key = strtolower($key);

        return (string) preg_replace('/[^a-z0-9_\-]/', '', $key);
    }

    private function resolveDb(): AP_DB
    {
        if ($this->db instanceof AP_DB) {
            return $this->db;
        }

        if (function_exists('ap_db')) {
            return ap_db();
        }

        throw new RuntimeException('No database connection available for AP_Query.');
    }
}
