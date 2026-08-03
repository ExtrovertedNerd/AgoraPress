<?php

/**
 * AgoraPress post model — statuses, types, CRUD, hierarchical pages.
 *
 * WP-inspired (not a fork). Built-in types: post, page, revision, attachment.
 * Built-in statuses: publish, draft, pending, private, future, trash, auto-draft,
 * inherit. Lightweight custom post types and custom statuses are registerable.
 *
 * Hierarchical types (page by default) use post_parent + menu_order. Cycle
 * prevention runs on parent assignment. Soft-delete uses status=trash and
 * stores the previous status in postmeta for untrash.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * Content row model + post type / status registry against {prefix}posts.
 */
class AP_Post
{
    /** Meta key storing the status before trash. */
    public const TRASH_STATUS_META = '_ap_trash_meta_status';

    /** Meta key for page template slug (theme template files later). */
    public const PAGE_TEMPLATE_META = '_ap_page_template';

    /** Meta key for sticky flag (site-wide sticky list also lands later). */
    public const STICKY_META = '_ap_sticky';

    /**
     * Meta key: whether a published page may appear in automatic navigation
     * (fallback primary bar, Pages widget, Menus “Pages” picker).
     * Stored as '1' / '0'. Missing meta means show (default on).
     */
    public const SHOW_IN_NAV_META = '_ap_show_in_nav';

    /**
     * Fields stored on each revision / autosave snapshot (WP-aligned).
     *
     * @var list<string>
     */
    public const REVISION_FIELDS = ['post_title', 'post_content', 'post_excerpt'];

    /**
     * Default maximum non-autosave revisions kept per parent (0 = unlimited).
     * Overridable per call via prune args or AP_POST_REVISIONS when defined.
     */
    public const DEFAULT_MAX_REVISIONS = 25;

    /** @var array<string, array<string, mixed>> Registered post statuses. */
    private static array $statuses = [];

    /** @var array<string, array<string, mixed>> Registered post types. */
    private static array $types = [];

    /** @var bool Whether built-ins have been registered this process. */
    private static bool $builtinsRegistered = false;

    /** @var int Post ID (0 = not persisted). */
    public int $ID = 0;

    public int $post_author = 0;

    public string $post_date = '';

    public string $post_date_gmt = '';

    public string $post_content = '';

    public string $post_title = '';

    public string $post_excerpt = '';

    public string $post_status = 'draft';

    public string $comment_status = 'open';

    public string $ping_status = 'open';

    public string $post_password = '';

    public string $post_name = '';

    public string $to_ping = '';

    public string $pinged = '';

    public string $post_modified = '';

    public string $post_modified_gmt = '';

    public string $post_content_filtered = '';

    public int $post_parent = 0;

    public string $guid = '';

    public int $menu_order = 0;

    public string $post_type = 'post';

    public string $post_mime_type = '';

    public int $comment_count = 0;

    // -------------------------------------------------------------------------
    // Status registry
    // -------------------------------------------------------------------------

    /**
     * Ensure built-in statuses and types are registered.
     */
    public static function ensureBuiltins(): void
    {
        if (self::$builtinsRegistered) {
            return;
        }

        self::registerBuiltinStatuses();
        self::registerBuiltinTypes();
        self::$builtinsRegistered = true;
    }

    /**
     * Clear registries (tests only). Re-registers builtins on next ensure.
     */
    public static function resetRegistry(): void
    {
        self::$statuses = [];
        self::$types = [];
        self::$builtinsRegistered = false;
    }

    /**
     * Register a post status.
     *
     * Args (all optional): label, public, internal, protected, private,
     * exclude_from_search, show_in_admin_all_list, show_in_admin_status_list.
     *
     * @param array<string, mixed> $args
     */
    public static function registerStatus(string $status, array $args = []): void
    {
        $status = self::sanitizeKey($status);
        if ($status === '') {
            return;
        }

        $defaults = [
            'label' => $status,
            'public' => false,
            'internal' => false,
            'protected' => false,
            'private' => false,
            'exclude_from_search' => true,
            'show_in_admin_all_list' => true,
            'show_in_admin_status_list' => true,
        ];

        self::$statuses[$status] = array_merge($defaults, $args, ['name' => $status]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function getStatusObject(string $status): ?array
    {
        self::ensureBuiltins();
        $status = self::sanitizeKey($status);

        return self::$statuses[$status] ?? null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function getStatuses(): array
    {
        self::ensureBuiltins();

        return self::$statuses;
    }

    public static function statusExists(string $status): bool
    {
        self::ensureBuiltins();

        return isset(self::$statuses[self::sanitizeKey($status)]);
    }

    /**
     * Whether content with this status is publicly listable without auth.
     */
    public static function isPublicStatus(string $status): bool
    {
        $obj = self::getStatusObject($status);

        return $obj !== null && !empty($obj['public']);
    }

    /**
     * Built-in statuses (WP-aligned names).
     */
    public static function registerBuiltinStatuses(): void
    {
        self::registerStatus('publish', [
            'label' => 'Published',
            'public' => true,
            'exclude_from_search' => false,
        ]);
        self::registerStatus('draft', [
            'label' => 'Draft',
            'protected' => true,
        ]);
        self::registerStatus('pending', [
            'label' => 'Pending Review',
            'protected' => true,
        ]);
        self::registerStatus('private', [
            'label' => 'Private',
            'private' => true,
            'exclude_from_search' => true,
        ]);
        self::registerStatus('future', [
            'label' => 'Scheduled',
            'protected' => true,
        ]);
        self::registerStatus('trash', [
            'label' => 'Trash',
            'internal' => true,
            'show_in_admin_all_list' => false,
        ]);
        self::registerStatus('auto-draft', [
            'label' => 'Auto Draft',
            'internal' => true,
            'show_in_admin_all_list' => false,
            'show_in_admin_status_list' => false,
        ]);
        self::registerStatus('inherit', [
            'label' => 'Inherit',
            'internal' => true,
            'exclude_from_search' => false,
            'show_in_admin_all_list' => false,
            'show_in_admin_status_list' => false,
        ]);
    }

    // -------------------------------------------------------------------------
    // Type registry
    // -------------------------------------------------------------------------

    /**
     * Register a post type (built-in or lightweight CPT).
     *
     * Args: label, public, hierarchical, has_archive, supports (list),
     * exclude_from_search, publicly_queryable, show_ui, delete_with_user,
     * capability_type, rewrite (bool|array), menu_position.
     *
     * @param array<string, mixed> $args
     */
    public static function registerType(string $type, array $args = []): void
    {
        $type = self::sanitizeKey($type);
        if ($type === '') {
            return;
        }

        $defaults = [
            'label' => $type,
            'public' => false,
            'hierarchical' => false,
            'has_archive' => false,
            'supports' => ['title', 'editor'],
            'exclude_from_search' => null,
            'publicly_queryable' => null,
            'show_ui' => null,
            'delete_with_user' => null,
            'capability_type' => 'post',
            'rewrite' => true,
            'menu_position' => null,
        ];

        $merged = array_merge($defaults, $args, ['name' => $type]);

        // Derive null public-facing flags from public.
        $public = !empty($merged['public']);
        if ($merged['exclude_from_search'] === null) {
            $merged['exclude_from_search'] = !$public;
        }
        if ($merged['publicly_queryable'] === null) {
            $merged['publicly_queryable'] = $public;
        }
        if ($merged['show_ui'] === null) {
            $merged['show_ui'] = $public;
        }
        if (!is_array($merged['supports'])) {
            $merged['supports'] = ['title', 'editor'];
        }

        self::$types[$type] = $merged;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function getTypeObject(string $type): ?array
    {
        self::ensureBuiltins();
        $type = self::sanitizeKey($type);

        return self::$types[$type] ?? null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function getTypes(): array
    {
        self::ensureBuiltins();

        return self::$types;
    }

    public static function typeExists(string $type): bool
    {
        self::ensureBuiltins();

        return isset(self::$types[self::sanitizeKey($type)]);
    }

    public static function typeIsHierarchical(string $type): bool
    {
        $obj = self::getTypeObject($type);

        return $obj !== null && !empty($obj['hierarchical']);
    }

    /**
     * Whether a type supports a feature (title, editor, thumbnail, …).
     */
    public static function typeSupports(string $type, string $feature): bool
    {
        $obj = self::getTypeObject($type);
        if ($obj === null) {
            return false;
        }

        $supports = $obj['supports'] ?? [];
        if (!is_array($supports)) {
            return false;
        }

        return in_array($feature, $supports, true);
    }

    /**
     * Built-in post types.
     */
    public static function registerBuiltinTypes(): void
    {
        self::registerType('post', [
            'label' => 'Posts',
            'public' => true,
            'hierarchical' => false,
            'has_archive' => true,
            'supports' => ['title', 'editor', 'author', 'excerpt', 'comments', 'revisions', 'thumbnail'],
            'menu_position' => 5,
        ]);
        self::registerType('page', [
            'label' => 'Pages',
            'public' => true,
            'hierarchical' => true,
            'has_archive' => false,
            'supports' => ['title', 'editor', 'author', 'page-attributes', 'revisions', 'thumbnail'],
            'menu_position' => 20,
            'capability_type' => 'page',
        ]);
        self::registerType('revision', [
            'label' => 'Revisions',
            'public' => false,
            'hierarchical' => false,
            'supports' => [],
            'exclude_from_search' => true,
            'publicly_queryable' => false,
            'show_ui' => false,
        ]);
        self::registerType('attachment', [
            'label' => 'Media',
            'public' => true,
            'hierarchical' => false,
            'supports' => ['title', 'author'],
            'exclude_from_search' => true,
            'show_ui' => false, // Managed via Media Library (upload.php), not edit.php.
            'capability_type' => 'post',
        ]);
    }

    // -------------------------------------------------------------------------
    // Row hydration
    // -------------------------------------------------------------------------

    /**
     * Build a post instance from a DB row object or associative array.
     *
     * @param object|array<string, mixed> $row
     */
    public static function fromRow(object|array $row): self
    {
        $data = is_array($row) ? $row : get_object_vars($row);
        $post = new self();

        if (isset($data['ID'])) {
            $post->ID = (int) $data['ID'];
        } elseif (isset($data['id'])) {
            $post->ID = (int) $data['id'];
        }

        $post->post_author = (int) ($data['post_author'] ?? 0);
        $post->post_date = (string) ($data['post_date'] ?? '');
        $post->post_date_gmt = (string) ($data['post_date_gmt'] ?? '');
        $post->post_content = (string) ($data['post_content'] ?? '');
        $post->post_title = (string) ($data['post_title'] ?? '');
        $post->post_excerpt = (string) ($data['post_excerpt'] ?? '');
        $post->post_status = (string) ($data['post_status'] ?? 'draft');
        $post->comment_status = (string) ($data['comment_status'] ?? 'open');
        $post->ping_status = (string) ($data['ping_status'] ?? 'open');
        $post->post_password = (string) ($data['post_password'] ?? '');
        $post->post_name = (string) ($data['post_name'] ?? '');
        $post->to_ping = (string) ($data['to_ping'] ?? '');
        $post->pinged = (string) ($data['pinged'] ?? '');
        $post->post_modified = (string) ($data['post_modified'] ?? '');
        $post->post_modified_gmt = (string) ($data['post_modified_gmt'] ?? '');
        $post->post_content_filtered = (string) ($data['post_content_filtered'] ?? '');
        $post->post_parent = (int) ($data['post_parent'] ?? 0);
        $post->guid = (string) ($data['guid'] ?? '');
        $post->menu_order = (int) ($data['menu_order'] ?? 0);
        $post->post_type = (string) ($data['post_type'] ?? 'post');
        $post->post_mime_type = (string) ($data['post_mime_type'] ?? '');
        $post->comment_count = (int) ($data['comment_count'] ?? 0);

        return $post;
    }

    public function exists(): bool
    {
        return $this->ID > 0;
    }

    public function isHierarchical(): bool
    {
        return self::typeIsHierarchical($this->post_type);
    }

    public function isPasswordProtected(): bool
    {
        return $this->post_password !== '';
    }

    public function isSticky(?AP_DB $db = null): bool
    {
        if ($this->ID < 1) {
            return false;
        }

        return self::getMeta($this->ID, self::STICKY_META, true, $db) === '1';
    }

    /**
     * Whether this post is publicly viewable without elevated privileges.
     *
     * Password-protected published posts still count as publicly “viewable”
     * (content is gated separately). Trash / draft / private are not.
     */
    public function isPubliclyViewable(): bool
    {
        if ($this->ID < 1) {
            return false;
        }

        if (!self::typeExists($this->post_type)) {
            return false;
        }

        $type = self::getTypeObject($this->post_type);
        if ($type === null || empty($type['publicly_queryable'])) {
            return false;
        }

        return self::isPublicStatus($this->post_status);
    }

    // -------------------------------------------------------------------------
    // Lookup
    // -------------------------------------------------------------------------

    /**
     * Fetch a post by primary key.
     */
    public static function get(int $id, ?AP_DB $db = null): ?self
    {
        if ($id < 1) {
            return null;
        }

        $db = self::resolveDb($db);
        $table = $db->quoteIdentifier($db->table('posts'));
        $row = $db->getRow(
            'SELECT ' . self::selectColumns($db) . ' FROM ' . $table
            . ' WHERE ' . $db->quoteIdentifier('ID') . ' = ? LIMIT 1',
            [$id]
        );

        return $row === null ? null : self::fromRow($row);
    }

    /**
     * Fetch by slug (post_name) and optional type.
     *
     * When $type is empty, the first matching non-revision/non-trash row wins
     * (ordered by ID ascending for stability).
     */
    public static function getBySlug(
        string $slug,
        string $type = '',
        ?AP_DB $db = null
    ): ?self {
        $slug = trim($slug);
        if ($slug === '') {
            return null;
        }

        $db = self::resolveDb($db);
        $table = $db->quoteIdentifier($db->table('posts'));
        $sql = 'SELECT ' . self::selectColumns($db) . ' FROM ' . $table
            . ' WHERE ' . $db->quoteIdentifier('post_name') . ' = ?';
        $params = [$slug];

        if ($type !== '') {
            $sql .= ' AND ' . $db->quoteIdentifier('post_type') . ' = ?';
            $params[] = self::sanitizeKey($type);
        }

        $sql .= ' ORDER BY ' . $db->quoteIdentifier('ID') . ' ASC LIMIT 1';

        $row = $db->getRow($sql, $params);

        return $row === null ? null : self::fromRow($row);
    }

    /**
     * List posts matching simple filters (not a full query engine — see AP_Query).
     *
     * Supported args:
     * - post_type (string|list, default post)
     * - post_status (string|list, default publish)
     * - post_parent (int|null)
     * - post_author (int)
     * - orderby (post_date|post_title|menu_order|ID|post_modified)
     * - order (ASC|DESC)
     * - limit (int, default 10; 0 = no limit)
     * - offset (int)
     * - exclude (list of IDs)
     *
     * @param array<string, mixed> $args
     *
     * @return list<self>
     */
    public static function query(array $args = [], ?AP_DB $db = null): array
    {
        $db = self::resolveDb($db);
        $table = $db->quoteIdentifier($db->table('posts'));

        $types = self::normalizeListArg($args['post_type'] ?? 'post');
        $statuses = self::normalizeListArg($args['post_status'] ?? 'publish');
        $orderby = (string) ($args['orderby'] ?? 'post_date');
        $allowedOrderby = ['post_date', 'post_title', 'menu_order', 'ID', 'post_modified', 'post_name'];
        if (!in_array($orderby, $allowedOrderby, true)) {
            $orderby = 'post_date';
        }
        $order = strtoupper((string) ($args['order'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
        $limit = isset($args['limit']) ? (int) $args['limit'] : 10;
        $offset = max(0, (int) ($args['offset'] ?? 0));

        $where = [];
        $params = [];

        if ($types !== []) {
            $placeholders = implode(', ', array_fill(0, count($types), '?'));
            $where[] = $db->quoteIdentifier('post_type') . ' IN (' . $placeholders . ')';
            foreach ($types as $t) {
                $params[] = $t;
            }
        }

        if ($statuses !== []) {
            $placeholders = implode(', ', array_fill(0, count($statuses), '?'));
            $where[] = $db->quoteIdentifier('post_status') . ' IN (' . $placeholders . ')';
            foreach ($statuses as $s) {
                $params[] = $s;
            }
        }

        if (array_key_exists('post_parent', $args) && $args['post_parent'] !== null) {
            $where[] = $db->quoteIdentifier('post_parent') . ' = ?';
            $params[] = (int) $args['post_parent'];
        }

        if (isset($args['post_author']) && (int) $args['post_author'] > 0) {
            $where[] = $db->quoteIdentifier('post_author') . ' = ?';
            $params[] = (int) $args['post_author'];
        }

        if (!empty($args['exclude']) && is_array($args['exclude'])) {
            $excludeIds = array_map('intval', $args['exclude']);
            $exclude = array_values(array_filter(
                $excludeIds,
                static fn (int $id): bool => $id > 0
            ));
            if ($exclude !== []) {
                $placeholders = implode(', ', array_fill(0, count($exclude), '?'));
                $where[] = $db->quoteIdentifier('ID') . ' NOT IN (' . $placeholders . ')';
                foreach ($exclude as $id) {
                    $params[] = $id;
                }
            }
        }

        $sql = 'SELECT ' . self::selectColumns($db) . ' FROM ' . $table;
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY ' . $db->quoteIdentifier($orderby) . ' ' . $order
            . ', ' . $db->quoteIdentifier('ID') . ' ' . $order;

        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit;
            if ($offset > 0) {
                $sql .= ' OFFSET ' . $offset;
            }
        }

        $rows = $db->getResults($sql, $params);
        $posts = [];
        foreach ($rows as $row) {
            $posts[] = self::fromRow($row);
        }

        return $posts;
    }

    // -------------------------------------------------------------------------
    // Create / update / trash / delete
    // -------------------------------------------------------------------------

    /**
     * Insert a post. Returns the new ID, or 0 on failure.
     *
     * Unknown statuses/types are rejected unless $args['strict'] is false
     * (default true). Hierarchical types validate post_parent.
     *
     * @param array<string, mixed> $data Column overrides (post_* keys).
     * @param array<string, mixed> $args Options: strict (bool).
     */
    public static function insert(array $data, ?AP_DB $db = null, array $args = []): int
    {
        self::ensureBuiltins();
        $db = self::resolveDb($db);
        $strict = !array_key_exists('strict', $args) || !empty($args['strict']);

        $type = self::sanitizeKey((string) ($data['post_type'] ?? 'post'));
        if ($type === '') {
            $type = 'post';
        }
        if ($strict && !self::typeExists($type)) {
            return 0;
        }

        $status = self::sanitizeKey((string) ($data['post_status'] ?? 'draft'));
        if ($status === '') {
            $status = 'draft';
        }
        if ($strict && !self::statusExists($status)) {
            return 0;
        }

        $now = self::nowLocal();
        $nowGmt = self::nowGmt();

        $title = (string) ($data['post_title'] ?? '');
        $slugSource = (string) ($data['post_name'] ?? $title);
        $slug = self::sanitizeSlug($slugSource);
        if ($slug === '' && $title !== '') {
            $slug = self::sanitizeSlug($title);
        }
        if ($slug === '') {
            $slug = 'post';
        }

        $parent = (int) ($data['post_parent'] ?? 0);
        if ($parent < 0) {
            $parent = 0;
        }
        if ($parent > 0) {
            if (!self::typeIsHierarchical($type) && $type !== 'revision' && $type !== 'attachment') {
                // Non-hierarchical types (except revision/attachment) ignore parent.
                $parent = 0;
            } else {
                $parentPost = self::get($parent, $db);
                if ($parentPost === null) {
                    return 0;
                }
                // Pages should parent under same type when hierarchical.
                if (self::typeIsHierarchical($type) && $parentPost->post_type !== $type) {
                    return 0;
                }
            }
        }

        // Future: if publish date is in the future and status is publish, schedule.
        $postDate = (string) ($data['post_date'] ?? $now);
        $postDateGmt = (string) ($data['post_date_gmt'] ?? '');
        if ($postDateGmt === '') {
            $postDateGmt = self::localToGmt($postDate) ?? $nowGmt;
        }
        if ($status === 'publish' && self::isDatetimeInFuture($postDate)) {
            $status = 'future';
        }

        $slug = self::uniqueSlug($slug, $type, 0, $parent, $db);

        $row = [
            'post_author' => (int) ($data['post_author'] ?? 0),
            'post_date' => $postDate,
            'post_date_gmt' => $postDateGmt,
            'post_content' => (string) ($data['post_content'] ?? ''),
            'post_title' => $title,
            'post_excerpt' => (string) ($data['post_excerpt'] ?? ''),
            'post_status' => $status,
            'comment_status' => (string) ($data['comment_status'] ?? self::defaultCommentStatus($type)),
            'ping_status' => (string) ($data['ping_status'] ?? 'open'),
            'post_password' => (string) ($data['post_password'] ?? ''),
            'post_name' => $slug,
            'to_ping' => (string) ($data['to_ping'] ?? ''),
            'pinged' => (string) ($data['pinged'] ?? ''),
            'post_modified' => (string) ($data['post_modified'] ?? $now),
            'post_modified_gmt' => (string) ($data['post_modified_gmt'] ?? $nowGmt),
            'post_content_filtered' => (string) ($data['post_content_filtered'] ?? ''),
            'post_parent' => $parent,
            'guid' => (string) ($data['guid'] ?? ''),
            'menu_order' => (int) ($data['menu_order'] ?? 0),
            'post_type' => $type,
            'post_mime_type' => (string) ($data['post_mime_type'] ?? ''),
            'comment_count' => (int) ($data['comment_count'] ?? 0),
        ];

        $result = $db->insert('posts', $row);
        if ($result === false) {
            return 0;
        }

        $id = (int) $db->lastInsertId();
        if ($id < 1) {
            return 0;
        }

        // Immutable identity GUID (WP-style): first public locator, not updated when
        // permalink structure changes. Prefer ap_get_permalink() for display links.
        if ($row['guid'] === '') {
            $db->update('posts', ['guid' => '?p=' . $id], ['ID' => $id]);
        }

        if (!empty($data['meta']) && is_array($data['meta'])) {
            foreach ($data['meta'] as $metaKey => $metaValue) {
                if (!is_string($metaKey) || $metaKey === '') {
                    continue;
                }
                self::updateMeta($id, $metaKey, is_scalar($metaValue) || $metaValue === null
                    ? (string) ($metaValue ?? '')
                    : (string) json_encode($metaValue), $db);
            }
        }

        if (array_key_exists('page_template', $data) && is_string($data['page_template'])) {
            self::setPageTemplate($id, $data['page_template'], $db);
        }

        if (!empty($data['sticky'])) {
            self::setSticky($id, true, $db);
        }

        if (array_key_exists('show_in_nav', $data) && $type === 'page') {
            self::setShowInNav($id, (bool) $data['show_in_nav'], $db);
        }

        if (function_exists('ap_do_action')) {
            ap_do_action('ap_post_inserted', $id, self::get($id, $db));
        }

        return $id;
    }

    /**
     * Update an existing post. Returns true when the write succeeded.
     *
     * When the post type supports revisions and revision-tracked fields change,
     * a snapshot of the previous state is stored first (unless
     * $args['create_revision'] is false).
     *
     * @param array<string, mixed> $data Column overrides.
     * @param array<string, mixed> $args Options: strict (bool), create_revision (bool).
     */
    public static function update(int $id, array $data, ?AP_DB $db = null, array $args = []): bool
    {
        if ($id < 1 || $data === []) {
            return false;
        }

        self::ensureBuiltins();
        $db = self::resolveDb($db);
        $strict = !array_key_exists('strict', $args) || !empty($args['strict']);

        $existing = self::get($id, $db);
        if ($existing === null) {
            return false;
        }

        $type = $existing->post_type;
        if (isset($data['post_type'])) {
            $type = self::sanitizeKey((string) $data['post_type']);
            if ($strict && !self::typeExists($type)) {
                return false;
            }
        }

        $status = $existing->post_status;
        if (isset($data['post_status'])) {
            $status = self::sanitizeKey((string) $data['post_status']);
            if ($strict && !self::statusExists($status)) {
                return false;
            }
        }

        $parent = $existing->post_parent;
        if (array_key_exists('post_parent', $data)) {
            $parent = (int) $data['post_parent'];
            if ($parent < 0) {
                $parent = 0;
            }
            if ($parent === $id) {
                return false;
            }
            if ($parent > 0) {
                if (self::wouldCreateCycle($id, $parent, $db)) {
                    return false;
                }
                if (self::typeIsHierarchical($type)) {
                    $parentPost = self::get($parent, $db);
                    if ($parentPost === null || $parentPost->post_type !== $type) {
                        return false;
                    }
                } elseif ($type !== 'revision' && $type !== 'attachment') {
                    $parent = 0;
                }
            }
        }

        // Snapshot previous content before applying revision-tracked changes.
        $createRevision = !array_key_exists('create_revision', $args) || !empty($args['create_revision']);
        if (
            $createRevision
            && $type !== 'revision'
            && self::typeSupports($existing->post_type, 'revisions')
            && self::shouldSaveRevision($existing, $data)
        ) {
            self::saveRevision($id, $db, [
                'author' => (int) ($data['post_author'] ?? $existing->post_author),
            ]);
        }

        $now = self::nowLocal();
        $nowGmt = self::nowGmt();

        $update = [];
        $map = [
            'post_author', 'post_date', 'post_date_gmt', 'post_content', 'post_title',
            'post_excerpt', 'comment_status', 'ping_status', 'post_password',
            'to_ping', 'pinged', 'post_content_filtered', 'guid', 'menu_order',
            'post_mime_type', 'comment_count',
        ];
        foreach ($map as $col) {
            if (array_key_exists($col, $data)) {
                $update[$col] = $data[$col];
            }
        }

        $update['post_type'] = $type;
        $update['post_status'] = $status;
        $update['post_parent'] = $parent;

        // Auto-schedule when publishing with a future date.
        $checkDate = (string) ($update['post_date'] ?? $existing->post_date);
        if ($status === 'publish' && self::isDatetimeInFuture($checkDate)) {
            $update['post_status'] = 'future';
            $status = 'future';
        }

        // Slug uniqueness when title/name/parent/type change.
        // Revisions keep a stable post_name (…-revision-v1 / …-autosave-N);
        // only recompute when post_name is supplied explicitly.
        $slug = $existing->post_name;
        $isRevisionType = $type === 'revision' || $existing->post_type === 'revision';
        $slugFieldsChanged = isset($data['post_name'])
            || (!$isRevisionType && (
                isset($data['post_title'])
                || isset($data['post_parent'])
                || isset($data['post_type'])
            ));
        if ($slugFieldsChanged) {
            if (isset($data['post_name'])) {
                $slugSource = (string) $data['post_name'];
            } elseif (isset($data['post_title'])) {
                $slugSource = (string) $data['post_title'];
            } else {
                $slugSource = $existing->post_name;
            }
            if ($slugSource === '' && isset($data['post_title'])) {
                $slugSource = (string) $data['post_title'];
            }
            $slug = self::sanitizeSlug(
                $slugSource !== '' ? $slugSource : $existing->post_name
            );
            if ($slug === '') {
                $slug = 'post-' . $id;
            }
            $slug = self::uniqueSlug($slug, $type, $id, $parent, $db);
        }
        $update['post_name'] = $slug;

        if (!isset($data['post_modified'])) {
            $update['post_modified'] = $now;
        } else {
            $update['post_modified'] = (string) $data['post_modified'];
        }
        if (!isset($data['post_modified_gmt'])) {
            $update['post_modified_gmt'] = $nowGmt;
        } else {
            $update['post_modified_gmt'] = (string) $data['post_modified_gmt'];
        }

        // Coerce ints.
        foreach (['post_author', 'menu_order', 'comment_count', 'post_parent'] as $intCol) {
            if (isset($update[$intCol])) {
                $update[$intCol] = (int) $update[$intCol];
            }
        }

        $result = $db->update('posts', $update, ['ID' => $id]);
        if ($result === false) {
            return false;
        }

        if (!empty($data['meta']) && is_array($data['meta'])) {
            foreach ($data['meta'] as $metaKey => $metaValue) {
                if (!is_string($metaKey) || $metaKey === '') {
                    continue;
                }
                self::updateMeta($id, $metaKey, is_scalar($metaValue) || $metaValue === null
                    ? (string) ($metaValue ?? '')
                    : (string) json_encode($metaValue), $db);
            }
        }

        if (array_key_exists('page_template', $data) && is_string($data['page_template'])) {
            self::setPageTemplate($id, $data['page_template'], $db);
        }

        if (array_key_exists('sticky', $data)) {
            self::setSticky($id, !empty($data['sticky']), $db);
        }

        if (array_key_exists('show_in_nav', $data)) {
            self::setShowInNav($id, (bool) $data['show_in_nav'], $db);
        }

        if (function_exists('ap_do_action')) {
            ap_do_action('ap_post_updated', $id, self::get($id, $db), $existing);
        }

        return true;
    }

    /**
     * Soft-delete: set status to trash and remember the previous status.
     */
    public static function trash(int $id, ?AP_DB $db = null): bool
    {
        $db = self::resolveDb($db);
        $post = self::get($id, $db);
        if ($post === null || $post->post_status === 'trash') {
            return false;
        }

        self::updateMeta($id, self::TRASH_STATUS_META, $post->post_status, $db);

        $result = $db->update(
            'posts',
            [
                'post_status' => 'trash',
                'post_modified' => self::nowLocal(),
                'post_modified_gmt' => self::nowGmt(),
            ],
            ['ID' => $id]
        );

        if ($result === false) {
            return false;
        }

        if (function_exists('ap_do_action')) {
            ap_do_action('ap_post_trashed', $id, $post);
        }

        return true;
    }

    /**
     * Restore a trashed post to its previous status (or draft).
     */
    public static function untrash(int $id, ?AP_DB $db = null): bool
    {
        $db = self::resolveDb($db);
        $post = self::get($id, $db);
        if ($post === null || $post->post_status !== 'trash') {
            return false;
        }

        $previous = self::getMeta($id, self::TRASH_STATUS_META, true, $db);
        if (!is_string($previous) || $previous === '' || $previous === 'trash' || !self::statusExists($previous)) {
            $previous = 'draft';
        }

        $result = $db->update(
            'posts',
            [
                'post_status' => $previous,
                'post_modified' => self::nowLocal(),
                'post_modified_gmt' => self::nowGmt(),
            ],
            ['ID' => $id]
        );

        if ($result === false) {
            return false;
        }

        self::deleteMeta($id, self::TRASH_STATUS_META, $db);

        if (function_exists('ap_do_action')) {
            ap_do_action('ap_post_untrashed', $id, self::get($id, $db));
        }

        return true;
    }

    /**
     * Delete a post. Soft-deletes to trash unless $force is true.
     * Force-delete also removes postmeta for that post.
     */
    public static function delete(int $id, bool $force = false, ?AP_DB $db = null): bool
    {
        if ($id < 1) {
            return false;
        }

        $db = self::resolveDb($db);
        $post = self::get($id, $db);
        if ($post === null) {
            return false;
        }

        if (!$force) {
            if ($post->post_status === 'trash') {
                // Second delete from trash = permanent.
                $force = true;
            } else {
                return self::trash($id, $db);
            }
        }

        // Re-parent hierarchical children to the deleted post's parent.
        if (self::typeIsHierarchical($post->post_type)) {
            $children = self::getChildren($id, ['post_status' => [], 'limit' => 0], $db);
            foreach ($children as $child) {
                $db->update(
                    'posts',
                    ['post_parent' => $post->post_parent],
                    ['ID' => $child->ID]
                );
            }
        }

        // Drop revisions / autosaves that belong to this parent.
        if ($post->post_type !== 'revision') {
            self::deleteRevisionsForParent($id, $db);
        }

        // When force-deleting an attachment via the post API, remove the file.
        // AP_Media::deleteAttachment() also unlinks; this covers ap_delete_post().
        if ($post->post_type === 'attachment' && class_exists('AP_Media', false)) {
            $attached = AP_Media::getAttachedFile($id, $db);
            if ($attached !== '' && is_file($attached)) {
                $base = realpath(AP_Media::basedir());
                $real = realpath($attached);
                if ($base !== false && $real !== false && str_starts_with($real, $base)) {
                    @unlink($real);
                }
            }
        }

        $db->delete('postmeta', ['post_id' => $id]);
        $result = $db->delete('posts', ['ID' => $id]);

        if ($result !== false && $result > 0) {
            if (function_exists('ap_do_action')) {
                ap_do_action('ap_post_deleted', $id, $post);
            }

            return true;
        }

        return false;
    }

    // -------------------------------------------------------------------------
    // Revisions & autosave
    // -------------------------------------------------------------------------

    /**
     * Whether a post type opts into the revisions feature.
     */
    public static function typeSupportsRevisions(string $type): bool
    {
        return self::typeSupports($type, 'revisions');
    }

    /**
     * Whether the given post (or ID) is a revision row.
     */
    public static function isRevision(self|int $post, ?AP_DB $db = null): bool
    {
        if (is_int($post)) {
            if ($post < 1) {
                return false;
            }
            $obj = self::get($post, $db);

            return $obj !== null && $obj->post_type === 'revision';
        }

        return $post->post_type === 'revision';
    }

    /**
     * Whether a revision row is an autosave (slug ends with -autosave-N).
     */
    public static function isAutosave(self|int $post, ?AP_DB $db = null): bool
    {
        $obj = is_int($post) ? self::get($post, $db) : $post;
        if ($obj === null || $obj->post_type !== 'revision') {
            return false;
        }

        return (bool) preg_match('/-autosave-\d+$/', $obj->post_name);
    }

    /**
     * Parent post of a revision, or null.
     */
    public static function getRevisionParent(int $revisionId, ?AP_DB $db = null): ?self
    {
        $revision = self::get($revisionId, $db);
        if ($revision === null || $revision->post_type !== 'revision' || $revision->post_parent < 1) {
            return null;
        }

        return self::get($revision->post_parent, $db);
    }

    /**
     * Whether proposed $data would change any revision-tracked field on $post.
     *
     * @param array<string, mixed> $data
     */
    public static function fieldsDifferForRevision(self $post, array $data): bool
    {
        foreach (self::REVISION_FIELDS as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            $new = (string) $data[$field];
            $old = (string) $post->{$field};
            if ($new !== $old) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether an update should create a revision snapshot of the current row.
     *
     * Skips auto-drafts and empty brand-new posts (no prior content to keep).
     *
     * @param array<string, mixed> $data Incoming update fields.
     */
    public static function shouldSaveRevision(self $post, array $data): bool
    {
        if ($post->ID < 1 || $post->post_type === 'revision') {
            return false;
        }
        if ($post->post_status === 'auto-draft') {
            return false;
        }
        // Nothing meaningful stored yet — first real save has no prior revision.
        if (
            $post->post_title === ''
            && $post->post_content === ''
            && $post->post_excerpt === ''
        ) {
            return false;
        }

        return self::fieldsDifferForRevision($post, $data);
    }

    /**
     * Store a revision snapshot of the current post (or of $args['fields']).
     *
     * Returns the new revision ID, or 0 on failure / skip.
     *
     * @param array<string, mixed> $args Options:
     *   - autosave (bool): mark as autosave for one author
     *   - author (int): revision author (defaults to parent author)
     *   - fields (array): override title/content/excerpt instead of parent row
     *   - max_revisions (int|null): prune after insert; null uses default
     */
    public static function saveRevision(int $postId, ?AP_DB $db = null, array $args = []): int
    {
        if ($postId < 1) {
            return 0;
        }

        self::ensureBuiltins();
        $db = self::resolveDb($db);
        $parent = self::get($postId, $db);
        if ($parent === null || $parent->post_type === 'revision') {
            return 0;
        }
        if (!self::typeSupports($parent->post_type, 'revisions')) {
            return 0;
        }

        $autosave = !empty($args['autosave']);
        $author = array_key_exists('author', $args)
            ? (int) $args['author']
            : $parent->post_author;
        if ($author < 0) {
            $author = 0;
        }

        $fields = [];
        if (!empty($args['fields']) && is_array($args['fields'])) {
            foreach (self::REVISION_FIELDS as $field) {
                if (array_key_exists($field, $args['fields'])) {
                    $fields[$field] = (string) $args['fields'][$field];
                } else {
                    $fields[$field] = (string) $parent->{$field};
                }
            }
        } else {
            foreach (self::REVISION_FIELDS as $field) {
                $fields[$field] = (string) $parent->{$field};
            }
        }

        if ($autosave) {
            return self::putAutosave($parent, $fields, $author, $db);
        }

        $slugBase = $postId . '-revision-v1';
        $revisionId = self::insert([
            'post_author' => $author,
            'post_content' => $fields['post_content'],
            'post_title' => $fields['post_title'],
            'post_excerpt' => $fields['post_excerpt'],
            'post_status' => 'inherit',
            'post_type' => 'revision',
            'post_name' => $slugBase,
            'post_parent' => $postId,
            'comment_status' => 'closed',
            'ping_status' => 'closed',
            'post_password' => '',
        ], $db, ['strict' => true]);

        if ($revisionId < 1) {
            return 0;
        }

        $max = array_key_exists('max_revisions', $args)
            ? (int) $args['max_revisions']
            : self::maxRevisionsLimit();
        if ($max > 0) {
            self::pruneRevisions($postId, $max, $db);
        }

        return $revisionId;
    }

    /**
     * Create or update the single autosave revision for a post + author.
     *
     * Does not modify the parent post. Returns autosave revision ID, or 0.
     *
     * @param array<string, mixed> $data May include post_title, post_content, post_excerpt.
     */
    public static function autosave(
        int $postId,
        array $data,
        int $userId = 0,
        ?AP_DB $db = null
    ): int {
        if ($postId < 1) {
            return 0;
        }

        self::ensureBuiltins();
        $db = self::resolveDb($db);
        $parent = self::get($postId, $db);
        if ($parent === null || $parent->post_type === 'revision') {
            return 0;
        }
        if (!self::typeSupports($parent->post_type, 'revisions')) {
            return 0;
        }

        if ($userId < 1) {
            $userId = $parent->post_author > 0 ? $parent->post_author : 0;
        }

        $fields = [];
        foreach (self::REVISION_FIELDS as $field) {
            $fields[$field] = array_key_exists($field, $data)
                ? (string) $data[$field]
                : (string) $parent->{$field};
        }

        // No-op when nothing changed vs parent and no existing autosave to refresh.
        $existingAutosave = self::getAutosave($postId, $userId, $db);
        if (
            $existingAutosave === null
            && $fields['post_title'] === $parent->post_title
            && $fields['post_content'] === $parent->post_content
            && $fields['post_excerpt'] === $parent->post_excerpt
        ) {
            return 0;
        }

        return self::putAutosave($parent, $fields, $userId, $db);
    }

    /**
     * Fetch the autosave revision for a parent post (optionally for one author).
     *
     * When $userId is 0, returns the most recent autosave for any author.
     */
    public static function getAutosave(int $postId, int $userId = 0, ?AP_DB $db = null): ?self
    {
        if ($postId < 1) {
            return null;
        }

        $db = self::resolveDb($db);
        $table = $db->quoteIdentifier($db->table('posts'));

        // userId > 0: that author's autosave. userId === 0: most recent any author.
        if ($userId > 0) {
            return self::getAutosaveBySlug($postId, self::autosaveSlug($postId, $userId), $db);
        }

        $row = $db->getRow(
            'SELECT ' . self::selectColumns($db) . ' FROM ' . $table
            . ' WHERE ' . $db->quoteIdentifier('post_parent') . ' = ?'
            . ' AND ' . $db->quoteIdentifier('post_type') . ' = ?'
            . ' AND ' . $db->quoteIdentifier('post_name') . ' LIKE ?'
            . ' ORDER BY ' . $db->quoteIdentifier('post_modified') . ' DESC'
            . ', ' . $db->quoteIdentifier('ID') . ' DESC LIMIT 1',
            [$postId, 'revision', $postId . '-autosave-%']
        );

        return $row === null ? null : self::fromRow($row);
    }

    /**
     * List non-autosave revisions for a parent post (newest first).
     *
     * Args: include_autosaves (bool, default false), limit (int, default 0 = all),
     * offset (int).
     *
     * @param array<string, mixed> $args
     *
     * @return list<self>
     */
    public static function getRevisions(int $postId, array $args = [], ?AP_DB $db = null): array
    {
        if ($postId < 1) {
            return [];
        }

        $db = self::resolveDb($db);
        $includeAutosaves = !empty($args['include_autosaves']);
        $limit = isset($args['limit']) ? max(0, (int) $args['limit']) : 0;
        $offset = max(0, (int) ($args['offset'] ?? 0));

        $table = $db->quoteIdentifier($db->table('posts'));
        $sql = 'SELECT ' . self::selectColumns($db) . ' FROM ' . $table
            . ' WHERE ' . $db->quoteIdentifier('post_parent') . ' = ?'
            . ' AND ' . $db->quoteIdentifier('post_type') . ' = ?';
        $params = [$postId, 'revision'];

        if (!$includeAutosaves) {
            $sql .= ' AND ' . $db->quoteIdentifier('post_name') . ' NOT LIKE ?';
            $params[] = $postId . '-autosave-%';
        }

        $sql .= ' ORDER BY ' . $db->quoteIdentifier('post_modified') . ' DESC'
            . ', ' . $db->quoteIdentifier('ID') . ' DESC';

        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit;
            if ($offset > 0) {
                $sql .= ' OFFSET ' . $offset;
            }
        }

        $rows = $db->getResults($sql, $params);
        $out = [];
        foreach ($rows as $row) {
            $out[] = self::fromRow($row);
        }

        return $out;
    }

    /**
     * Count non-autosave revisions for a parent (or all when include_autosaves).
     */
    public static function countRevisions(
        int $postId,
        bool $includeAutosaves = false,
        ?AP_DB $db = null
    ): int {
        if ($postId < 1) {
            return 0;
        }

        $db = self::resolveDb($db);
        $table = $db->quoteIdentifier($db->table('posts'));
        $sql = 'SELECT COUNT(*) FROM ' . $table
            . ' WHERE ' . $db->quoteIdentifier('post_parent') . ' = ?'
            . ' AND ' . $db->quoteIdentifier('post_type') . ' = ?';
        $params = [$postId, 'revision'];

        if (!$includeAutosaves) {
            $sql .= ' AND ' . $db->quoteIdentifier('post_name') . ' NOT LIKE ?';
            $params[] = $postId . '-autosave-%';
        }

        return (int) $db->getVar($sql, $params);
    }

    /**
     * Restore a revision onto its parent (creates a new revision of current first).
     */
    public static function restoreRevision(int $revisionId, ?AP_DB $db = null): bool
    {
        if ($revisionId < 1) {
            return false;
        }

        $db = self::resolveDb($db);
        $revision = self::get($revisionId, $db);
        if ($revision === null || $revision->post_type !== 'revision') {
            return false;
        }
        if (self::isAutosave($revision)) {
            // Restoring autosave is allowed — it is still a content snapshot.
        }

        $parentId = $revision->post_parent;
        $parent = self::get($parentId, $db);
        if ($parent === null || $parent->post_type === 'revision') {
            return false;
        }

        $data = [];
        foreach (self::REVISION_FIELDS as $field) {
            $data[$field] = $revision->{$field};
        }

        // update() will snapshot current parent when fields differ.
        return self::update($parentId, $data, $db);
    }

    /**
     * Permanently delete a single revision row.
     */
    public static function deleteRevision(int $revisionId, ?AP_DB $db = null): bool
    {
        if ($revisionId < 1) {
            return false;
        }

        $db = self::resolveDb($db);
        $revision = self::get($revisionId, $db);
        if ($revision === null || $revision->post_type !== 'revision') {
            return false;
        }

        $db->delete('postmeta', ['post_id' => $revisionId]);
        $result = $db->delete('posts', ['ID' => $revisionId]);

        return $result !== false && $result > 0;
    }

    /**
     * Keep only the newest $keep non-autosave revisions for a parent.
     *
     * @return int Number of revisions deleted.
     */
    public static function pruneRevisions(int $postId, int $keep, ?AP_DB $db = null): int
    {
        if ($postId < 1 || $keep < 0) {
            return 0;
        }
        if ($keep === 0) {
            // 0 means unlimited — nothing to prune.
            return 0;
        }

        $db = self::resolveDb($db);
        $revisions = self::getRevisions($postId, ['include_autosaves' => false, 'limit' => 0], $db);
        if (count($revisions) <= $keep) {
            return 0;
        }

        $toDelete = array_slice($revisions, $keep);
        $deleted = 0;
        foreach ($toDelete as $rev) {
            if (self::deleteRevision($rev->ID, $db)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Configured max revisions (AP_POST_REVISIONS constant or DEFAULT_MAX_REVISIONS).
     *
     * Define AP_POST_REVISIONS as int: positive = keep N, 0 = unlimited, false/negative treated as 0.
     */
    public static function maxRevisionsLimit(): int
    {
        if (defined('AP_POST_REVISIONS')) {
            $value = constant('AP_POST_REVISIONS');
            if ($value === false || $value === null) {
                return 0;
            }
            $n = (int) $value;

            return $n < 0 ? 0 : $n;
        }

        return self::DEFAULT_MAX_REVISIONS;
    }

    /**
     * Insert or update the autosave row for parent + author.
     *
     * @param array<string, string> $fields
     */
    private static function putAutosave(self $parent, array $fields, int $author, AP_DB $db): int
    {
        $postId = $parent->ID;
        $userKey = max(0, $author);
        $slug = self::autosaveSlug($postId, $userKey);
        // Always resolve by exact slug so author 0 does not match another user's autosave.
        $existing = self::getAutosaveBySlug($postId, $slug, $db);

        if ($existing !== null) {
            $ok = self::update($existing->ID, [
                'post_title' => $fields['post_title'],
                'post_content' => $fields['post_content'],
                'post_excerpt' => $fields['post_excerpt'],
                'post_author' => $author,
                'post_status' => 'inherit',
            ], $db, ['create_revision' => false, 'strict' => true]);

            return $ok ? $existing->ID : 0;
        }

        return self::insert([
            'post_author' => $author,
            'post_content' => $fields['post_content'],
            'post_title' => $fields['post_title'],
            'post_excerpt' => $fields['post_excerpt'],
            'post_status' => 'inherit',
            'post_type' => 'revision',
            'post_name' => $slug,
            'post_parent' => $postId,
            'comment_status' => 'closed',
            'ping_status' => 'closed',
            'post_password' => '',
        ], $db, ['strict' => true]);
    }

    /**
     * Load autosave by exact post_name.
     */
    private static function getAutosaveBySlug(int $postId, string $slug, AP_DB $db): ?self
    {
        $table = $db->quoteIdentifier($db->table('posts'));
        $row = $db->getRow(
            'SELECT ' . self::selectColumns($db) . ' FROM ' . $table
            . ' WHERE ' . $db->quoteIdentifier('post_parent') . ' = ?'
            . ' AND ' . $db->quoteIdentifier('post_type') . ' = ?'
            . ' AND ' . $db->quoteIdentifier('post_name') . ' = ?'
            . ' LIMIT 1',
            [$postId, 'revision', $slug]
        );

        return $row === null ? null : self::fromRow($row);
    }

    private static function autosaveSlug(int $postId, int $userId): string
    {
        return $postId . '-autosave-' . max(0, $userId);
    }

    /**
     * Force-delete all revision rows (and their meta) for a parent post.
     */
    private static function deleteRevisionsForParent(int $parentId, AP_DB $db): void
    {
        $table = $db->quoteIdentifier($db->table('posts'));
        $ids = $db->getCol(
            'SELECT ' . $db->quoteIdentifier('ID') . ' FROM ' . $table
            . ' WHERE ' . $db->quoteIdentifier('post_parent') . ' = ?'
            . ' AND ' . $db->quoteIdentifier('post_type') . ' = ?',
            [$parentId, 'revision']
        );

        foreach ($ids as $rawId) {
            $revId = (int) $rawId;
            if ($revId < 1) {
                continue;
            }
            $db->delete('postmeta', ['post_id' => $revId]);
            $db->delete('posts', ['ID' => $revId]);
        }
    }

    // -------------------------------------------------------------------------
    // Hierarchy
    // -------------------------------------------------------------------------

    /**
     * Direct children of a parent post.
     *
     * @param array<string, mixed> $args Passed to query(); defaults post_status=any public-ish.
     *
     * @return list<self>
     */
    public static function getChildren(int $parentId, array $args = [], ?AP_DB $db = null): array
    {
        if ($parentId < 0) {
            return [];
        }

        $args['post_parent'] = $parentId;
        if (!isset($args['post_type'])) {
            // Infer type from parent when possible.
            $parent = self::get($parentId, $db);
            $args['post_type'] = $parent !== null ? $parent->post_type : 'page';
        }
        if (!array_key_exists('post_status', $args)) {
            $args['post_status'] = ['publish', 'private', 'draft', 'pending', 'future'];
        } elseif ($args['post_status'] === [] || $args['post_status'] === 'any') {
            // Empty list / 'any' = no status filter.
            unset($args['post_status']);
            // Rebuild via raw query without status constraint.
            return self::query(array_merge($args, [
                'post_status' => array_keys(self::getStatuses()),
            ]), $db);
        }
        if (!isset($args['orderby'])) {
            $args['orderby'] = 'menu_order';
        }
        if (!isset($args['order'])) {
            $args['order'] = 'ASC';
        }
        if (!isset($args['limit'])) {
            $args['limit'] = 0;
        }

        return self::query($args, $db);
    }

    /**
     * Ancestor IDs from immediate parent up to the root (excluding self).
     *
     * @return list<int>
     */
    public static function getAncestorIds(int $id, ?AP_DB $db = null): array
    {
        $db = self::resolveDb($db);
        $ancestors = [];
        $current = self::get($id, $db);
        $guard = 0;
        while ($current !== null && $current->post_parent > 0 && $guard < 100) {
            $parentId = $current->post_parent;
            if (in_array($parentId, $ancestors, true)) {
                break;
            }
            $ancestors[] = $parentId;
            $current = self::get($parentId, $db);
            $guard++;
        }

        return $ancestors;
    }

    /**
     * Ancestor post objects (parent → root).
     *
     * @return list<self>
     */
    public static function getAncestors(int $id, ?AP_DB $db = null): array
    {
        $db = self::resolveDb($db);
        $posts = [];
        foreach (self::getAncestorIds($id, $db) as $ancestorId) {
            $post = self::get($ancestorId, $db);
            if ($post !== null) {
                $posts[] = $post;
            }
        }

        return $posts;
    }

    /**
     * Hierarchical path of slugs: grandparent/parent/child.
     */
    public static function getPagePath(int $id, ?AP_DB $db = null): string
    {
        $db = self::resolveDb($db);
        $post = self::get($id, $db);
        if ($post === null) {
            return '';
        }

        $parts = [$post->post_name];
        foreach (self::getAncestors($id, $db) as $ancestor) {
            array_unshift($parts, $ancestor->post_name);
        }

        return implode('/', array_filter($parts, static fn (string $p): bool => $p !== ''));
    }

    /**
     * Whether assigning $newParent as parent of $id would create a cycle.
     */
    public static function wouldCreateCycle(int $id, int $newParent, ?AP_DB $db = null): bool
    {
        if ($id < 1 || $newParent < 1) {
            return false;
        }
        if ($id === $newParent) {
            return true;
        }

        $db = self::resolveDb($db);
        // If $id is an ancestor of $newParent, cycle.
        $ancestors = self::getAncestorIds($newParent, $db);

        return in_array($id, $ancestors, true);
    }

    /**
     * Nested page tree for hierarchical types.
     *
     * Each node: ['post' => AP_Post, 'children' => list<node>].
     *
     * @param array<string, mixed> $args post_type (default page), post_status, …
     *
     * @return list<array{post: self, children: list<array<string, mixed>>}>
     */
    public static function getTree(array $args = [], ?AP_DB $db = null): array
    {
        $db = self::resolveDb($db);
        $type = self::sanitizeKey((string) ($args['post_type'] ?? 'page'));
        if ($type === '') {
            $type = 'page';
        }

        $queryArgs = $args;
        $queryArgs['post_type'] = $type;
        $queryArgs['limit'] = 0;
        if (!isset($queryArgs['orderby'])) {
            $queryArgs['orderby'] = 'menu_order';
        }
        if (!isset($queryArgs['order'])) {
            $queryArgs['order'] = 'ASC';
        }
        if (!isset($queryArgs['post_status'])) {
            $queryArgs['post_status'] = 'publish';
        }
        // Load all matching rows (any parent).
        unset($queryArgs['post_parent']);

        $all = self::query($queryArgs, $db);
        /** @var array<int, list<self>> $byParent */
        $byParent = [];
        foreach ($all as $post) {
            $byParent[$post->post_parent][] = $post;
        }

        $build = static function (int $parentId) use (&$build, $byParent): array {
            $nodes = [];
            foreach ($byParent[$parentId] ?? [] as $post) {
                $nodes[] = [
                    'post' => $post,
                    'children' => $build($post->ID),
                ];
            }

            return $nodes;
        };

        $rootParent = array_key_exists('post_parent', $args) ? (int) $args['post_parent'] : 0;

        return $build($rootParent);
    }

    /**
     * Instance helper: parent post or null.
     */
    public function getParent(?AP_DB $db = null): ?self
    {
        if ($this->post_parent < 1) {
            return null;
        }

        return self::get($this->post_parent, $db);
    }

    // -------------------------------------------------------------------------
    // Postmeta
    // -------------------------------------------------------------------------

    /**
     * Read meta. When $single is true, returns the first value (string|null).
     * When false, returns list of all values for the key.
     *
     * @return ($single is true ? string|null : list<string>)
     */
    public static function getMeta(
        int $postId,
        string $key,
        bool $single = true,
        ?AP_DB $db = null
    ): string|array|null {
        if ($postId < 1 || $key === '') {
            return $single ? null : [];
        }

        $db = self::resolveDb($db);
        $table = $db->quoteIdentifier($db->table('postmeta'));

        if ($single) {
            $value = $db->getVar(
                'SELECT meta_value FROM ' . $table
                . ' WHERE post_id = ? AND meta_key = ? ORDER BY meta_id ASC LIMIT 1',
                [$postId, $key]
            );

            return $value === null ? null : (string) $value;
        }

        $cols = $db->getCol(
            'SELECT meta_value FROM ' . $table
            . ' WHERE post_id = ? AND meta_key = ? ORDER BY meta_id ASC',
            [$postId, $key]
        );

        return array_map(static fn (mixed $v): string => (string) $v, $cols);
    }

    /**
     * Insert or update a single meta value for a key (replaces first row).
     */
    public static function updateMeta(
        int $postId,
        string $key,
        string $value,
        ?AP_DB $db = null
    ): bool {
        if ($postId < 1 || $key === '') {
            return false;
        }

        $db = self::resolveDb($db);
        $table = $db->quoteIdentifier($db->table('postmeta'));
        $metaId = $db->getVar(
            'SELECT meta_id FROM ' . $table
            . ' WHERE post_id = ? AND meta_key = ? ORDER BY meta_id ASC LIMIT 1',
            [$postId, $key]
        );

        if ($metaId !== null) {
            $result = $db->update(
                'postmeta',
                ['meta_value' => $value],
                ['meta_id' => (int) $metaId]
            );

            return $result !== false;
        }

        $result = $db->insert('postmeta', [
            'post_id' => $postId,
            'meta_key' => $key,
            'meta_value' => $value,
        ]);

        return $result !== false;
    }

    /**
     * Delete meta for a key (all rows), or a specific value when provided.
     */
    public static function deleteMeta(
        int $postId,
        string $key,
        ?AP_DB $db = null,
        ?string $value = null
    ): bool {
        if ($postId < 1 || $key === '') {
            return false;
        }

        $db = self::resolveDb($db);
        $where = ['post_id' => $postId, 'meta_key' => $key];
        if ($value !== null) {
            $where['meta_value'] = $value;
        }

        $result = $db->delete('postmeta', $where);

        return $result !== false;
    }

    public static function setPageTemplate(int $postId, string $template, ?AP_DB $db = null): bool
    {
        $template = trim($template);
        if ($template === '' || $template === 'default') {
            return self::deleteMeta($postId, self::PAGE_TEMPLATE_META, $db);
        }

        return self::updateMeta($postId, self::PAGE_TEMPLATE_META, $template, $db);
    }

    public static function getPageTemplate(int $postId, ?AP_DB $db = null): string
    {
        $value = self::getMeta($postId, self::PAGE_TEMPLATE_META, true, $db);

        return is_string($value) && $value !== '' ? $value : 'default';
    }

    public static function setSticky(int $postId, bool $sticky, ?AP_DB $db = null): bool
    {
        if ($sticky) {
            return self::updateMeta($postId, self::STICKY_META, '1', $db);
        }

        return self::deleteMeta($postId, self::STICKY_META, $db);
    }

    /**
     * Whether a page should appear in automatic navigation lists.
     *
     * Default is true when meta is missing (backward compatible for existing pages).
     * Explicit '0' / empty hides the page from fallback nav, Pages widget, and the
     * Appearance → Menus “Pages” picker. Custom menu items already assigned stay
     * until the admin removes them.
     */
    public static function showsInNav(int $postId, ?AP_DB $db = null): bool
    {
        if ($postId < 1) {
            return true;
        }
        $value = self::getMeta($postId, self::SHOW_IN_NAV_META, true, $db);
        if ($value === null || $value === false || $value === '') {
            return true;
        }

        return (string) $value !== '0' && (string) $value !== 'false';
    }

    /**
     * Persist the “Show in navigation” flag for a page.
     *
     * When true, meta is deleted (default-on). When false, stores '0'.
     */
    public static function setShowInNav(int $postId, bool $show, ?AP_DB $db = null): bool
    {
        if ($postId < 1) {
            return false;
        }
        if ($show) {
            return self::deleteMeta($postId, self::SHOW_IN_NAV_META, $db);
        }

        return self::updateMeta($postId, self::SHOW_IN_NAV_META, '0', $db);
    }

    // -------------------------------------------------------------------------
    // Slug / date helpers
    // -------------------------------------------------------------------------

    /**
     * Sanitize a title into a URL slug (ASCII-ish, lowercase, hyphens).
     */
    public static function sanitizeSlug(string $title): string
    {
        $title = trim($title);
        if ($title === '') {
            return '';
        }

        if (function_exists('mb_strtolower')) {
            $title = mb_strtolower($title, 'UTF-8');
        } else {
            $title = strtolower($title);
        }

        // Transliterate common accents when iconv is available.
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title);
            if (is_string($converted) && $converted !== '') {
                $title = strtolower($converted);
            }
        }

        $title = preg_replace('/[^a-z0-9]+/', '-', $title) ?? '';
        $title = trim($title, '-');

        // Cap length similar to post_name column usage.
        if (strlen($title) > 200) {
            $title = substr($title, 0, 200);
            $title = rtrim($title, '-');
        }

        return $title;
    }

    /**
     * Ensure slug is unique among the same post_type (and parent for hierarchical).
     */
    public static function uniqueSlug(
        string $slug,
        string $type,
        int $excludeId = 0,
        int $parent = 0,
        ?AP_DB $db = null
    ): string {
        $slug = self::sanitizeSlug($slug);
        if ($slug === '') {
            $slug = 'post';
        }

        $db = self::resolveDb($db);
        $table = $db->quoteIdentifier($db->table('posts'));
        $type = self::sanitizeKey($type);
        $hierarchical = self::typeIsHierarchical($type);

        $base = $slug;
        $suffix = 2;
        while (true) {
            $sql = 'SELECT ' . $db->quoteIdentifier('ID') . ' FROM ' . $table
                . ' WHERE ' . $db->quoteIdentifier('post_name') . ' = ?'
                . ' AND ' . $db->quoteIdentifier('post_type') . ' = ?';
            $params = [$slug, $type];

            if ($hierarchical) {
                $sql .= ' AND ' . $db->quoteIdentifier('post_parent') . ' = ?';
                $params[] = $parent;
            }

            if ($excludeId > 0) {
                $sql .= ' AND ' . $db->quoteIdentifier('ID') . ' != ?';
                $params[] = $excludeId;
            }

            $sql .= ' LIMIT 1';
            $found = $db->getVar($sql, $params);
            if ($found === null) {
                return $slug;
            }

            $slug = $base . '-' . $suffix;
            $suffix++;
            if ($suffix > 1000) {
                $slug = $base . '-' . bin2hex(random_bytes(3));

                return $slug;
            }
        }
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * @return list<string>
     */
    private static function normalizeListArg(mixed $value): array
    {
        if ($value === null || $value === '' || $value === 'any') {
            return [];
        }
        if (is_string($value)) {
            return [self::sanitizeKey($value)];
        }
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (!is_string($item) && !is_int($item)) {
                continue;
            }
            $key = self::sanitizeKey((string) $item);
            if ($key !== '') {
                $out[] = $key;
            }
        }

        return array_values(array_unique($out));
    }

    private static function sanitizeKey(string $key): string
    {
        $key = strtolower(trim($key));
        $key = preg_replace('/[^a-z0-9_\-]/', '', $key) ?? '';

        return $key;
    }

    private static function defaultCommentStatus(string $type): string
    {
        return $type === 'page' ? 'closed' : 'open';
    }

    private static function nowLocal(): string
    {
        return date('Y-m-d H:i:s');
    }

    private static function nowGmt(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    /**
     * Best-effort local → GMT using the default timezone offset.
     */
    private static function localToGmt(string $local): ?string
    {
        $ts = strtotime($local);
        if ($ts === false) {
            return null;
        }

        return gmdate('Y-m-d H:i:s', $ts);
    }

    private static function isDatetimeInFuture(string $datetime): bool
    {
        $ts = strtotime($datetime);
        if ($ts === false) {
            return false;
        }

        return $ts > time();
    }

    /**
     * Columns selected for post rows.
     */
    private static function selectColumns(AP_DB $db): string
    {
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
            $quoted[] = $db->quoteIdentifier($col);
        }

        return implode(', ', $quoted);
    }

    private static function resolveDb(?AP_DB $db): AP_DB
    {
        if ($db instanceof AP_DB) {
            return $db;
        }

        if (function_exists('ap_db')) {
            return ap_db();
        }

        throw new RuntimeException('No database connection available for post operations.');
    }
}
