<?php

/**
 * AgoraPress forum core — hierarchy, topics, posts/replies.
 *
 * Dedicated tables (migration 0005) for performance while sharing users,
 * capabilities, options, and media with the CMS core.
 *
 * Forum types: category | forum | link
 * Forum status: open | closed | hidden
 * Topic status: open | locked | moved | deleted
 * Topic type: standard | sticky | announcement | rules
 *
 * ## Post-count definition (SPEC §C — pick one, stay consistent)
 *
 * **Posts = opening posts + replies** (not replies-only). Everywhere UI or
 * denormalized counters expose a “Posts” total, count each approved
 * `forum_posts` row under a visible topic:
 *
 * | Surface                         | Source                                              |
 * |---------------------------------|-----------------------------------------------------|
 * | Board footer “Total Posts”      | {@see AP_Forum_Stats::getTotalPosts()}              |
 * | Forum row Posts column          | `forums.post_count` via {@see forumToDisplayRow()}  |
 * | Topic list Posts column         | `reply_count + 1` via {@see topicToDisplayRow()}    |
 * | Denormalized `forums.post_count`| Maintained by {@see bumpForumStats()} / adjust…     |
 *
 * Soft-deleted / unapproved topics and unapproved posts are excluded.
 * `topics.reply_count` remains replies-only (excludes the opening post).
 * Per-user `forum_posts` usermeta is “approved posts authored” (profile /
 * author pane), not a board-footer aggregate.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * Forum subsystem — hierarchy, topics, posts/replies.
 */
class AP_Forum
{
    // -------------------------------------------------------------------------
    // Constants
    // -------------------------------------------------------------------------

    public const FORUM_TYPE_CATEGORY = 'category';

    public const FORUM_TYPE_FORUM = 'forum';

    public const FORUM_TYPE_LINK = 'link';

    public const FORUM_STATUS_OPEN = 'open';

    public const FORUM_STATUS_CLOSED = 'closed';

    public const FORUM_STATUS_HIDDEN = 'hidden';

    public const TOPIC_STATUS_OPEN = 'open';

    public const TOPIC_STATUS_LOCKED = 'locked';

    public const TOPIC_STATUS_MOVED = 'moved';

    public const TOPIC_STATUS_DELETED = 'deleted';

    /** Default topic — ordinary thread. */
    public const TOPIC_TYPE_STANDARD = 'standard';

    public const TOPIC_TYPE_STICKY = 'sticky';

    public const TOPIC_TYPE_ANNOUNCEMENT = 'announcement';

    /** Sticky-style info / forum rules thread. */
    public const TOPIC_TYPE_RULES = 'rules';

    /**
     * @deprecated Use {@see TOPIC_TYPE_STANDARD}. Alias for legacy code/plugins.
     */
    public const TOPIC_TYPE_NORMAL = self::TOPIC_TYPE_STANDARD;

    /**
     * @deprecated Use {@see TOPIC_TYPE_ANNOUNCEMENT}. Alias for legacy code/plugins.
     */
    public const TOPIC_TYPE_ANNOUNCE = self::TOPIC_TYPE_ANNOUNCEMENT;

    /**
     * @deprecated phpBB global announce maps to announcement.
     *             Use {@see TOPIC_TYPE_ANNOUNCEMENT}.
     */
    public const TOPIC_TYPE_GLOBAL = self::TOPIC_TYPE_ANNOUNCEMENT;

    /** Epoch placeholder used when no last activity exists. */
    public const EMPTY_DATETIME = '1970-01-01 00:00:00';

    // -------------------------------------------------------------------------
    // Table helpers
    // -------------------------------------------------------------------------

    /**
     * Unprefixed base names for dedicated forum tables (SPEC §4).
     *
     * Prefer {@see ap_forum_base_tables()} when the config helpers are loaded.
     *
     * @return list<string>
     */
    public static function baseTables(): array
    {
        if (function_exists('ap_forum_base_tables')) {
            return ap_forum_base_tables();
        }

        return [
            'forums',
            'topics',
            'forum_posts',
            'forum_attachments',
            'groups',
            'group_members',
            'forum_permissions',
            'messages',
            'ranks',
            'reports',
            'warnings',
            'bans',
            'online',
            'topic_track',
            'forum_track',
            'forum_post_likes',
        ];
    }

    /**
     * Fully prefixed forum table map for a connection (or site default prefix).
     *
     * @return array<string, string> base => prefixed name
     */
    public static function tables(?AP_DB $db = null): array
    {
        if ($db instanceof AP_DB) {
            $map = [];
            foreach (self::baseTables() as $base) {
                $map[$base] = $db->table($base);
            }

            return $map;
        }

        if (function_exists('ap_prefixed_tables')) {
            $all = ap_prefixed_tables();
            $map = [];
            foreach (self::baseTables() as $base) {
                if (isset($all[$base])) {
                    $map[$base] = $all[$base];
                }
            }

            return $map;
        }

        $prefix = function_exists('ap_get_table_prefix')
            ? ap_get_table_prefix()
            : (defined('AP_TABLE_PREFIX') ? (string) AP_TABLE_PREFIX : 'ap_');

        $map = [];
        foreach (self::baseTables() as $base) {
            $map[$base] = $prefix . $base;
        }

        return $map;
    }

    // -------------------------------------------------------------------------
    // Normalization
    // -------------------------------------------------------------------------

    /**
     * @return list<string>
     */
    public static function forumTypes(): array
    {
        return [
            self::FORUM_TYPE_CATEGORY,
            self::FORUM_TYPE_FORUM,
            self::FORUM_TYPE_LINK,
        ];
    }

    /**
     * @return list<string>
     */
    public static function forumStatuses(): array
    {
        return [
            self::FORUM_STATUS_OPEN,
            self::FORUM_STATUS_CLOSED,
            self::FORUM_STATUS_HIDDEN,
        ];
    }

    /**
     * @return list<string>
     */
    public static function topicStatuses(): array
    {
        return [
            self::TOPIC_STATUS_OPEN,
            self::TOPIC_STATUS_LOCKED,
            self::TOPIC_STATUS_MOVED,
            self::TOPIC_STATUS_DELETED,
        ];
    }

    /**
     * @return list<string>
     */
    public static function topicTypes(): array
    {
        return [
            self::TOPIC_TYPE_STANDARD,
            self::TOPIC_TYPE_STICKY,
            self::TOPIC_TYPE_ANNOUNCEMENT,
            self::TOPIC_TYPE_RULES,
        ];
    }

    public static function normalizeForumType(string $type): string
    {
        $type = self::sanitizeKey($type);

        return in_array($type, self::forumTypes(), true) ? $type : self::FORUM_TYPE_FORUM;
    }

    public static function normalizeForumStatus(string $status): string
    {
        $status = self::sanitizeKey($status);

        return in_array($status, self::forumStatuses(), true) ? $status : self::FORUM_STATUS_OPEN;
    }

    public static function normalizeTopicStatus(string $status): string
    {
        $status = self::sanitizeKey($status);

        return in_array($status, self::topicStatuses(), true) ? $status : self::TOPIC_STATUS_OPEN;
    }

    public static function normalizeTopicType(string $type): string
    {
        $type = self::sanitizeKey($type);

        // Legacy labels (pre-SPEC) and soft aliases.
        $aliases = [
            'normal' => self::TOPIC_TYPE_STANDARD,
            'announce' => self::TOPIC_TYPE_ANNOUNCEMENT,
            'global' => self::TOPIC_TYPE_ANNOUNCEMENT,
            'info' => self::TOPIC_TYPE_RULES,
        ];
        if (isset($aliases[$type])) {
            $type = $aliases[$type];
        }

        return in_array($type, self::topicTypes(), true)
            ? $type
            : self::TOPIC_TYPE_STANDARD;
    }

    public static function isTopicLocked(object|string $topicOrStatus): bool
    {
        $status = is_object($topicOrStatus)
            ? (string) ($topicOrStatus->topic_status ?? '')
            : (string) $topicOrStatus;

        return self::normalizeTopicStatus($status) === self::TOPIC_STATUS_LOCKED;
    }

    /**
     * Whether a forum (or raw status) is closed — no new topics accepted.
     *
     * Themes map closed forums to locked icons / badges (SPEC A2 “Locked”).
     */
    public static function isForumClosed(object|string $forumOrStatus): bool
    {
        $status = is_object($forumOrStatus)
            ? (string) ($forumOrStatus->forum_status ?? '')
            : (string) $forumOrStatus;

        return self::normalizeForumStatus($status) === self::FORUM_STATUS_CLOSED;
    }

    public static function isTopicSticky(object|string $topicOrType): bool
    {
        $type = is_object($topicOrType)
            ? (string) ($topicOrType->topic_type ?? '')
            : (string) $topicOrType;
        $type = self::normalizeTopicType($type);

        // Sticky, announcement, and rules float above standard topics.
        return in_array($type, [
            self::TOPIC_TYPE_STICKY,
            self::TOPIC_TYPE_ANNOUNCEMENT,
            self::TOPIC_TYPE_RULES,
        ], true);
    }

    /**
     * Board-index / forum-list icon key (type only; pair with is_unread for variants).
     *
     * Values: standard | locked | link
     * Themes map these to assets/CSS (e.g. ap-forum-icon--locked).
     */
    public static function forumIconType(object|string $forumOrType, ?string $status = null): string
    {
        if (is_object($forumOrType)) {
            $type = (string) ($forumOrType->forum_type ?? self::FORUM_TYPE_FORUM);
            $status = (string) ($forumOrType->forum_status ?? self::FORUM_STATUS_OPEN);
        } else {
            $type = (string) $forumOrType;
            $status = (string) ($status ?? self::FORUM_STATUS_OPEN);
        }

        $type = self::normalizeForumType($type);
        if ($type === self::FORUM_TYPE_LINK) {
            return 'link';
        }
        if (self::normalizeForumStatus($status) === self::FORUM_STATUS_CLOSED) {
            return 'locked';
        }

        return 'standard';
    }

    /**
     * Topic-list icon key (type + locked override; pair with is_unread for variants).
     *
     * Values: standard | sticky | announcement | rules | locked
     * Locked status wins so closed threads are obvious even when sticky/announce.
     */
    public static function topicIconType(object|string $topicOrType, ?string $status = null): string
    {
        if (is_object($topicOrType)) {
            $type = (string) ($topicOrType->topic_type ?? self::TOPIC_TYPE_STANDARD);
            $status = (string) ($topicOrType->topic_status ?? self::TOPIC_STATUS_OPEN);
        } else {
            $type = (string) $topicOrType;
            $status = (string) ($status ?? self::TOPIC_STATUS_OPEN);
        }

        if (self::normalizeTopicStatus($status) === self::TOPIC_STATUS_LOCKED) {
            return 'locked';
        }

        return self::normalizeTopicType($type);
    }

    // -------------------------------------------------------------------------
    // Forums — hierarchy CRUD
    // -------------------------------------------------------------------------

    /**
     * Fetch a forum by ID.
     */
    public static function getForum(int $id, ?AP_DB $db = null): ?object
    {
        if ($id < 1) {
            return null;
        }

        $db = self::resolveDb($db);
        $table = $db->quoteIdentifier($db->table('forums'));
        $row = $db->getRow(
            'SELECT * FROM ' . $table . ' WHERE ' . $db->quoteIdentifier('forum_id') . ' = ?',
            [$id]
        );

        return $row !== null ? self::normalizeForumRow($row) : null;
    }

    /**
     * Fetch a forum by slug (first match; slugs unique among siblings recommended).
     */
    public static function getForumBySlug(string $slug, ?AP_DB $db = null): ?object
    {
        $slug = self::sanitizeSlug($slug);
        if ($slug === '') {
            return null;
        }

        $db = self::resolveDb($db);
        $table = $db->quoteIdentifier($db->table('forums'));
        $row = $db->getRow(
            'SELECT * FROM ' . $table . ' WHERE ' . $db->quoteIdentifier('forum_slug') . ' = ? LIMIT 1',
            [$slug]
        );

        return $row !== null ? self::normalizeForumRow($row) : null;
    }

    /**
     * Insert a forum or category. Returns new forum_id or 0 on failure.
     *
     * @param array<string, mixed> $data Keys: forum_name (required), forum_slug, forum_desc,
     *                                   parent_id, forum_type, forum_status, forum_order
     */
    public static function insertForum(array $data, ?AP_DB $db = null): int
    {
        $db = self::resolveDb($db);

        $name = trim((string) ($data['forum_name'] ?? $data['name'] ?? ''));
        if ($name === '') {
            return 0;
        }
        $name = str_replace("\0", '', $name);
        if (function_exists('mb_substr')) {
            $name = mb_substr($name, 0, 255);
        } else {
            $name = substr($name, 0, 255);
        }

        $parentId = max(0, (int) ($data['parent_id'] ?? 0));
        if ($parentId > 0 && self::getForum($parentId, $db) === null) {
            return 0;
        }

        $type = self::normalizeForumType((string) ($data['forum_type'] ?? $data['type'] ?? self::FORUM_TYPE_FORUM));
        $status = self::normalizeForumStatus((string) ($data['forum_status'] ?? $data['status'] ?? self::FORUM_STATUS_OPEN));
        $desc = (string) ($data['forum_desc'] ?? $data['description'] ?? '');
        $desc = str_replace("\0", '', $desc);
        $order = (int) ($data['forum_order'] ?? $data['order'] ?? 0);

        $slugSource = (string) ($data['forum_slug'] ?? $data['slug'] ?? $name);
        $slug = self::sanitizeSlug($slugSource);
        if ($slug === '') {
            $slug = 'forum';
        }
        $slug = self::uniqueForumSlug($slug, 0, $parentId, $db);

        $row = [
            'parent_id' => $parentId,
            'forum_type' => $type,
            'forum_status' => $status,
            'forum_name' => $name,
            'forum_slug' => $slug,
            'forum_desc' => $desc,
            'forum_order' => $order,
            'topic_count' => 0,
            'post_count' => 0,
            'last_post_id' => 0,
            'last_poster_id' => 0,
            'last_post_time' => self::EMPTY_DATETIME,
            'last_topic_id' => 0,
        ];

        if (function_exists('ap_do_action')) {
            ap_do_action('ap_pre_forum_insert', $row);
        }

        $result = $db->insert('forums', $row);
        if ($result === false) {
            return 0;
        }

        $id = (int) $db->lastInsertId();
        if ($id < 1) {
            return 0;
        }

        if (function_exists('ap_do_action')) {
            ap_do_action('ap_forum_inserted', $id, self::getForum($id, $db));
        }

        return $id;
    }

    /**
     * Update a forum. Returns true on success.
     *
     * @param array<string, mixed> $data
     */
    public static function updateForum(int $id, array $data, ?AP_DB $db = null): bool
    {
        if ($id < 1 || $data === []) {
            return false;
        }

        $db = self::resolveDb($db);
        $existing = self::getForum($id, $db);
        if ($existing === null) {
            return false;
        }

        $update = [];

        if (array_key_exists('forum_name', $data) || array_key_exists('name', $data)) {
            $name = trim((string) ($data['forum_name'] ?? $data['name'] ?? ''));
            if ($name === '') {
                return false;
            }
            $name = str_replace("\0", '', $name);
            if (function_exists('mb_substr')) {
                $name = mb_substr($name, 0, 255);
            } else {
                $name = substr($name, 0, 255);
            }
            $update['forum_name'] = $name;
        }

        if (array_key_exists('forum_desc', $data) || array_key_exists('description', $data)) {
            $update['forum_desc'] = str_replace(
                "\0",
                '',
                (string) ($data['forum_desc'] ?? $data['description'] ?? '')
            );
        }

        if (array_key_exists('forum_type', $data) || array_key_exists('type', $data)) {
            $update['forum_type'] = self::normalizeForumType(
                (string) ($data['forum_type'] ?? $data['type'] ?? self::FORUM_TYPE_FORUM)
            );
        }

        if (array_key_exists('forum_status', $data) || array_key_exists('status', $data)) {
            $update['forum_status'] = self::normalizeForumStatus(
                (string) ($data['forum_status'] ?? $data['status'] ?? self::FORUM_STATUS_OPEN)
            );
        }

        if (array_key_exists('forum_order', $data) || array_key_exists('order', $data)) {
            $update['forum_order'] = (int) ($data['forum_order'] ?? $data['order'] ?? 0);
        }

        $parentId = (int) $existing->parent_id;
        if (array_key_exists('parent_id', $data)) {
            $parentId = max(0, (int) $data['parent_id']);
            if ($parentId === $id) {
                return false;
            }
            if ($parentId > 0) {
                if (self::getForum($parentId, $db) === null) {
                    return false;
                }
                if (self::wouldCreateForumCycle($id, $parentId, $db)) {
                    return false;
                }
            }
            $update['parent_id'] = $parentId;
        }

        if (array_key_exists('forum_slug', $data) || array_key_exists('slug', $data)) {
            $slugSource = (string) ($data['forum_slug'] ?? $data['slug'] ?? '');
            $slug = self::sanitizeSlug($slugSource);
            if ($slug === '') {
                $slug = self::sanitizeSlug((string) ($update['forum_name'] ?? $existing->forum_name));
            }
            if ($slug === '') {
                $slug = 'forum';
            }
            $update['forum_slug'] = self::uniqueForumSlug($slug, $id, $parentId, $db);
        } elseif (isset($update['parent_id']) && (int) $update['parent_id'] !== (int) $existing->parent_id) {
            // Re-unique slug under new parent if parent moved.
            $update['forum_slug'] = self::uniqueForumSlug(
                (string) $existing->forum_slug,
                $id,
                $parentId,
                $db
            );
        }

        if ($update === []) {
            return true;
        }

        $result = $db->update('forums', $update, ['forum_id' => $id]);
        if ($result === false) {
            return false;
        }

        if (function_exists('ap_do_action')) {
            ap_do_action('ap_forum_updated', $id, self::getForum($id, $db));
        }

        return true;
    }

    /**
     * Delete a forum. Fails when it has child forums or topics (unless force).
     *
     * When force=true, recursively deletes child forums and permanently removes
     * topics/posts under this forum.
     */
    public static function deleteForum(int $id, bool $force = false, ?AP_DB $db = null): bool
    {
        if ($id < 1) {
            return false;
        }

        $db = self::resolveDb($db);
        $existing = self::getForum($id, $db);
        if ($existing === null) {
            return false;
        }

        $children = self::getChildForums($id, [], $db);
        $topicCount = self::countTopics($id, ['include_deleted' => true], $db);

        if (!$force && ($children !== [] || $topicCount > 0)) {
            return false;
        }

        if ($force) {
            foreach ($children as $child) {
                if (!self::deleteForum((int) $child->forum_id, true, $db)) {
                    return false;
                }
            }
            $topics = self::getTopics($id, [
                'include_deleted' => true,
                'per_page' => 10000,
                'approved_only' => false,
            ], $db);
            foreach ($topics as $topic) {
                self::deleteTopic((int) $topic->topic_id, true, $db);
            }
        }

        $result = $db->delete('forums', ['forum_id' => $id]);
        if ($result === false) {
            return false;
        }

        // Drop per-forum ACL rows (never touches blog post/page visibility).
        if (class_exists('AP_Forum_Permissions', false)) {
            AP_Forum_Permissions::deleteForForum($id, $db);
        }

        if (function_exists('ap_do_action')) {
            ap_do_action('ap_forum_deleted', $id);
        }

        return true;
    }

    /**
     * Direct children of a forum (or root when parent_id=0), ordered by forum_order.
     *
     * @param array<string, mixed> $args Keys: type, status, include_hidden (bool)
     *
     * @return list<object>
     */
    public static function getChildForums(int $parentId = 0, array $args = [], ?AP_DB $db = null): array
    {
        $db = self::resolveDb($db);
        $table = $db->quoteIdentifier($db->table('forums'));
        $sql = 'SELECT * FROM ' . $table . ' WHERE ' . $db->quoteIdentifier('parent_id') . ' = ?';
        $params = [max(0, $parentId)];

        if (!empty($args['type'])) {
            $sql .= ' AND ' . $db->quoteIdentifier('forum_type') . ' = ?';
            $params[] = self::normalizeForumType((string) $args['type']);
        }

        $includeHidden = !empty($args['include_hidden']);
        if (!$includeHidden) {
            if (!empty($args['status'])) {
                $sql .= ' AND ' . $db->quoteIdentifier('forum_status') . ' = ?';
                $params[] = self::normalizeForumStatus((string) $args['status']);
            } else {
                $sql .= ' AND ' . $db->quoteIdentifier('forum_status') . ' != ?';
                $params[] = self::FORUM_STATUS_HIDDEN;
            }
        } elseif (!empty($args['status'])) {
            $sql .= ' AND ' . $db->quoteIdentifier('forum_status') . ' = ?';
            $params[] = self::normalizeForumStatus((string) $args['status']);
        }

        $sql .= ' ORDER BY ' . $db->quoteIdentifier('forum_order') . ' ASC, '
            . $db->quoteIdentifier('forum_id') . ' ASC';

        $rows = $db->getResults($sql, $params);
        $out = [];
        foreach ($rows as $row) {
            $out[] = self::normalizeForumRow($row);
        }

        return $out;
    }

    /**
     * Nested forum hierarchy for index / admin tree.
     *
     * Each node: ['forum' => object, 'children' => list of nodes]
     *
     * @param array<string, mixed> $args Passed to getChildForums at each level
     *
     * @return list<array{forum: object, children: list}>
     */
    public static function getHierarchy(int $parentId = 0, array $args = [], ?AP_DB $db = null, int $depth = 0): array
    {
        if ($depth > 50) {
            return [];
        }

        $db = self::resolveDb($db);
        $children = self::getChildForums($parentId, $args, $db);
        $tree = [];
        foreach ($children as $forum) {
            $tree[] = [
                'forum' => $forum,
                'children' => self::getHierarchy((int) $forum->forum_id, $args, $db, $depth + 1),
            ];
        }

        return $tree;
    }

    /**
     * Ancestor chain from root to parent of $forumId (excluding self).
     *
     * @return list<object>
     */
    public static function getForumAncestors(int $forumId, ?AP_DB $db = null): array
    {
        $db = self::resolveDb($db);
        $chain = [];
        $current = self::getForum($forumId, $db);
        if ($current === null) {
            return [];
        }
        $parentId = (int) $current->parent_id;
        $guard = 0;
        while ($parentId > 0 && $guard < 100) {
            $parent = self::getForum($parentId, $db);
            if ($parent === null) {
                break;
            }
            array_unshift($chain, $parent);
            $parentId = (int) $parent->parent_id;
            $guard++;
        }

        return $chain;
    }

    /**
     * Flat list of forums matching filters.
     *
     * @param array<string, mixed> $args Keys: parent_id, type, status, include_hidden, search, per_page, page
     *
     * @return list<object>
     */
    public static function getForums(array $args = [], ?AP_DB $db = null): array
    {
        $db = self::resolveDb($db);
        $table = $db->quoteIdentifier($db->table('forums'));
        $sql = 'SELECT * FROM ' . $table . ' WHERE 1=1';
        $params = [];

        if (array_key_exists('parent_id', $args)) {
            $sql .= ' AND ' . $db->quoteIdentifier('parent_id') . ' = ?';
            $params[] = max(0, (int) $args['parent_id']);
        }

        if (!empty($args['type'])) {
            $sql .= ' AND ' . $db->quoteIdentifier('forum_type') . ' = ?';
            $params[] = self::normalizeForumType((string) $args['type']);
        }

        $includeHidden = !empty($args['include_hidden']);
        if (!$includeHidden) {
            if (!empty($args['status'])) {
                $sql .= ' AND ' . $db->quoteIdentifier('forum_status') . ' = ?';
                $params[] = self::normalizeForumStatus((string) $args['status']);
            } else {
                $sql .= ' AND ' . $db->quoteIdentifier('forum_status') . ' != ?';
                $params[] = self::FORUM_STATUS_HIDDEN;
            }
        } elseif (!empty($args['status'])) {
            $sql .= ' AND ' . $db->quoteIdentifier('forum_status') . ' = ?';
            $params[] = self::normalizeForumStatus((string) $args['status']);
        }

        if (!empty($args['search']) && is_string($args['search'])) {
            $like = '%' . self::escapeLike(trim($args['search'])) . '%';
            $sql .= ' AND (' . $db->quoteIdentifier('forum_name') . ' LIKE ? OR '
                . $db->quoteIdentifier('forum_desc') . ' LIKE ?)';
            $params[] = $like;
            $params[] = $like;
        }

        $sql .= ' ORDER BY ' . $db->quoteIdentifier('parent_id') . ' ASC, '
            . $db->quoteIdentifier('forum_order') . ' ASC, '
            . $db->quoteIdentifier('forum_id') . ' ASC';

        $perPage = max(0, (int) ($args['per_page'] ?? 0));
        $page = max(1, (int) ($args['page'] ?? 1));
        if ($perPage > 0) {
            $offset = ($page - 1) * $perPage;
            $sql .= ' LIMIT ' . $perPage . ' OFFSET ' . $offset;
        }

        $rows = $db->getResults($sql, $params);
        $out = [];
        foreach ($rows as $row) {
            $out[] = self::normalizeForumRow($row);
        }

        return $out;
    }

    /**
     * Index-oriented structure: categories (or root forums) with nested forums.
     *
     * Matches Agora theme expectations:
     * list of ['name' => string, 'forums' => list of display rows]
     *
     * Each forum row includes SPEC A4 payload fields via {@see forumToDisplayRow()}:
     * icon_type, is_unread, topic/post counts, last_post (title, author, time, url).
     *
     * When unread tracking is available, each forum row includes `is_unread`
     * for the current user (or `user_id` from $args). Guests get is_unread=false.
     *
     * Performance (board index — no N+1):
     * - One child-forum query per category (forum + link types filtered in PHP).
     * - Last-topic rows + author display names batch-loaded via
     *   {@see buildForumRowPreload()} (2 queries total for titles/authors).
     * - Unread flags via a single {@see AP_Forum_Read::annotateForums()} pass
     *   (bulk forum/topic marks + candidate topics; not per-forum isForumUnread).
     *
     * @param array<string, mixed> $args Optional: user_id (int; default current user)
     *
     * @return list<array{name: string, forums: list<array<string, mixed>>}>
     */
    public static function getIndexData(?AP_DB $db = null, array $args = []): array
    {
        $db = self::resolveDb($db);
        $roots = self::getChildForums(0, [], $db);
        $categories = [];
        $orphanForums = [];
        /** @var list<object> $allForumObjects */
        $allForumObjects = [];

        foreach ($roots as $root) {
            $type = (string) $root->forum_type;
            if ($type === self::FORUM_TYPE_CATEGORY) {
                // One child query per category (forum + link); filter in PHP.
                $children = self::getChildForums((int) $root->forum_id, [], $db);
                $forums = [];
                foreach ($children as $child) {
                    $childType = (string) ($child->forum_type ?? '');
                    if ($childType === self::FORUM_TYPE_FORUM || $childType === self::FORUM_TYPE_LINK) {
                        $forums[] = $child;
                    }
                }
                $allForumObjects = array_merge($allForumObjects, $forums);
                $categories[] = [
                    'name' => (string) $root->forum_name,
                    'forums' => $forums, // objects; converted after batch preload
                ];
            } elseif ($type === self::FORUM_TYPE_FORUM || $type === self::FORUM_TYPE_LINK) {
                $orphanForums[] = $root;
                $allForumObjects[] = $root;
            }
        }

        // Batch last-topic titles + author display names (no per-row SELECT).
        $preload = self::buildForumRowPreload($allForumObjects, $db);

        foreach ($categories as $i => $category) {
            $objects = is_array($category['forums'] ?? null) ? $category['forums'] : [];
            $rows = [];
            foreach ($objects as $forumObj) {
                if (is_object($forumObj)) {
                    $rows[] = self::forumToDisplayRow($forumObj, $db, $preload);
                }
            }
            $categories[$i]['forums'] = $rows;
        }

        if ($orphanForums !== []) {
            $orphanRows = [];
            foreach ($orphanForums as $forumObj) {
                $orphanRows[] = self::forumToDisplayRow($forumObj, $db, $preload);
            }
            $categories[] = [
                'name' => 'Forums',
                'forums' => $orphanRows,
            ];
        }

        // Single annotate pass for the whole board (O(1) mark/topic queries).
        $userId = self::resolveViewerUserId($args, $db);
        if (class_exists('AP_Forum_Read', false)) {
            $flat = [];
            /** @var list<array{0: int, 1: int}> $positions */
            $positions = [];
            foreach ($categories as $i => $category) {
                $forums = is_array($category['forums'] ?? null) ? $category['forums'] : [];
                foreach ($forums as $j => $forum) {
                    if (is_array($forum)) {
                        $positions[] = [$i, $j];
                        $flat[] = $forum;
                    }
                }
            }
            if ($flat !== []) {
                $annotated = AP_Forum_Read::annotateForums($userId, $flat, $db);
                foreach ($positions as $k => $pos) {
                    if (isset($annotated[$k]) && is_array($annotated[$k])) {
                        $categories[$pos[0]]['forums'][$pos[1]] = $annotated[$k];
                    }
                }
            }
        } else {
            foreach ($categories as $i => $category) {
                $forums = is_array($category['forums'] ?? null) ? $category['forums'] : [];
                foreach ($forums as $j => $forum) {
                    if (is_array($forum)) {
                        $forums[$j]['is_unread'] = false;
                    }
                }
                $categories[$i]['forums'] = $forums;
            }
        }

        return $categories;
    }

    // -------------------------------------------------------------------------
    // Topics
    // -------------------------------------------------------------------------

    /**
     * Fetch a topic by ID.
     */
    public static function getTopic(int $id, ?AP_DB $db = null): ?object
    {
        if ($id < 1) {
            return null;
        }

        $db = self::resolveDb($db);
        $table = $db->quoteIdentifier($db->table('topics'));
        $row = $db->getRow(
            'SELECT * FROM ' . $table . ' WHERE ' . $db->quoteIdentifier('topic_id') . ' = ?',
            [$id]
        );

        return $row !== null ? self::normalizeTopicRow($row) : null;
    }

    /**
     * Fetch a topic by slug within a forum.
     */
    public static function getTopicBySlug(string $slug, int $forumId = 0, ?AP_DB $db = null): ?object
    {
        $slug = self::sanitizeSlug($slug);
        if ($slug === '') {
            return null;
        }

        $db = self::resolveDb($db);
        $table = $db->quoteIdentifier($db->table('topics'));
        $sql = 'SELECT * FROM ' . $table . ' WHERE ' . $db->quoteIdentifier('topic_slug') . ' = ?';
        $params = [$slug];
        if ($forumId > 0) {
            $sql .= ' AND ' . $db->quoteIdentifier('forum_id') . ' = ?';
            $params[] = $forumId;
        }
        $sql .= ' LIMIT 1';
        $row = $db->getRow($sql, $params);

        return $row !== null ? self::normalizeTopicRow($row) : null;
    }

    /**
     * Create a topic with its first post. Returns topic_id or 0 on failure.
     *
     * @param array<string, mixed> $data Keys: forum_id, topic_title, content (required),
     *                                   topic_poster / poster_id, topic_type, topic_status,
     *                                   topic_approved, poster_ip, post_subject
     * @param array<string, mixed> $args Options:
     *                                   check_open (bool, default true),
     *                                   check_permissions (bool, default false) — when true,
     *                                   enforces {@see AP_Forum_Permissions} for post_topics
     *                                   and sticky/announce when those topic types are used,
     *                                   check_guard (bool, default false) — flood / spam / approval
     *                                   via {@see AP_Forum_Guard}
     */
    public static function createTopic(array $data, ?AP_DB $db = null, array $args = []): int
    {
        $db = self::resolveDb($db);
        $checkOpen = !array_key_exists('check_open', $args) || !empty($args['check_open']);
        $checkPermissions = !empty($args['check_permissions']);
        $checkGuard = !empty($args['check_guard']);

        $forumId = (int) ($data['forum_id'] ?? 0);
        if ($forumId < 1) {
            return 0;
        }

        $forum = self::getForum($forumId, $db);
        if ($forum === null) {
            return 0;
        }
        if ((string) $forum->forum_type === self::FORUM_TYPE_CATEGORY) {
            return 0;
        }
        if ($checkOpen && (string) $forum->forum_status !== self::FORUM_STATUS_OPEN) {
            return 0;
        }

        $title = trim((string) ($data['topic_title'] ?? $data['title'] ?? ''));
        if ($title === '') {
            return 0;
        }
        $title = str_replace("\0", '', $title);
        if (function_exists('mb_substr')) {
            $title = mb_substr($title, 0, 255);
        } else {
            $title = substr($title, 0, 255);
        }

        $content = trim((string) ($data['content'] ?? $data['post_content'] ?? $data['body'] ?? ''));
        if ($content === '') {
            return 0;
        }
        $content = str_replace("\0", '', $content);

        $posterId = max(0, (int) ($data['topic_poster'] ?? $data['poster_id'] ?? $data['user_id'] ?? 0));
        $type = self::normalizeTopicType((string) ($data['topic_type'] ?? $data['type'] ?? self::TOPIC_TYPE_STANDARD));
        $status = self::normalizeTopicStatus((string) ($data['topic_status'] ?? $data['status'] ?? self::TOPIC_STATUS_OPEN));
        $approved = array_key_exists('topic_approved', $data)
            ? ((int) $data['topic_approved'] ? 1 : 0)
            : 1;

        if ($checkGuard && class_exists('AP_Forum_Guard', false)) {
            $guardData = [
                'type' => 'topic',
                'forum_id' => $forumId,
                'topic_title' => $title,
                'title' => $title,
                'content' => $content,
                'poster_id' => $posterId,
                'poster_ip' => trim((string) ($data['poster_ip'] ?? '')),
            ];
            if (array_key_exists('topic_approved', $data)) {
                $guardData['topic_approved'] = $approved;
            }
            $guard = AP_Forum_Guard::evaluate($guardData, $db);
            if (empty($guard['allowed'])) {
                return 0;
            }
            if (!array_key_exists('topic_approved', $data)) {
                $approved = (int) ($guard['approved'] ?? 1) ? 1 : 0;
            }
        }

        if (
            $checkPermissions
            && class_exists('AP_Forum_Permissions', false)
            && !self::userMayCreateTopic($posterId, $forumId, $type, $db)
        ) {
            return 0;
        }

        $slugSource = (string) ($data['topic_slug'] ?? $data['slug'] ?? $title);
        $slug = self::sanitizeSlug($slugSource);
        if ($slug === '') {
            $slug = 'topic';
        }
        $slug = self::uniqueTopicSlug($slug, $forumId, 0, $db);

        $now = self::nowLocal();
        $posterIp = trim((string) ($data['poster_ip'] ?? ''));
        $subject = trim((string) ($data['post_subject'] ?? $title));
        if (function_exists('mb_substr')) {
            $subject = mb_substr($subject, 0, 255);
        } else {
            $subject = substr($subject, 0, 255);
        }

        $started = false;
        try {
            if (!$db->inTransaction()) {
                $db->beginTransaction();
                $started = true;
            }

            $topicRow = [
                'forum_id' => $forumId,
                'topic_title' => $title,
                'topic_slug' => $slug,
                'topic_poster' => $posterId,
                'topic_status' => $status,
                'topic_type' => $type,
                'topic_approved' => $approved,
                'topic_views' => 0,
                'reply_count' => 0,
                'first_post_id' => 0,
                'last_post_id' => 0,
                'last_poster_id' => $posterId,
                'topic_time' => $now,
                'topic_modified' => $now,
                'topic_last_post_time' => $now,
            ];

            if (function_exists('ap_do_action')) {
                ap_do_action('ap_pre_topic_insert', $topicRow);
            }

            $result = $db->insert('topics', $topicRow);
            if ($result === false) {
                if ($started) {
                    $db->rollBack();
                }

                return 0;
            }

            $topicId = (int) $db->lastInsertId();
            if ($topicId < 1) {
                if ($started) {
                    $db->rollBack();
                }

                return 0;
            }

            $postRow = [
                'topic_id' => $topicId,
                'forum_id' => $forumId,
                'poster_id' => $posterId,
                'post_subject' => $subject,
                'post_content' => $content,
                'post_content_filtered' => self::filteredContent(
                    $content,
                    array_key_exists('post_content_filtered', $data)
                        ? (string) $data['post_content_filtered']
                        : null
                ),
                'poster_ip' => $posterIp,
                'post_time' => $now,
                'post_modified' => $now,
                'post_approved' => $approved,
                'post_reported' => 0,
                'post_edit_reason' => '',
                'post_edit_user' => 0,
                'post_edit_time' => self::EMPTY_DATETIME,
                'post_edit_count' => 0,
                'post_position' => 1,
                'like_count' => 0,
            ];

            $postResult = $db->insert('forum_posts', $postRow);
            if ($postResult === false) {
                unset($postRow['like_count']);
                $postResult = $db->insert('forum_posts', $postRow);
            }
            if ($postResult === false) {
                if ($started) {
                    $db->rollBack();
                }

                return 0;
            }

            $postId = (int) $db->lastInsertId();
            if ($postId < 1) {
                if ($started) {
                    $db->rollBack();
                }

                return 0;
            }

            $db->update('topics', [
                'first_post_id' => $postId,
                'last_post_id' => $postId,
                'last_poster_id' => $posterId,
                'topic_last_post_time' => $now,
            ], ['topic_id' => $topicId]);

            if ($approved === 1) {
                self::bumpForumStats($forumId, 1, 1, $postId, $posterId, $now, $topicId, $db);
            }

            if ($started) {
                $db->commit();
            }

            if (function_exists('ap_do_action')) {
                ap_do_action('ap_forum_post_inserted', $postId, self::getPost($postId, $db));
            }
        } catch (Throwable $e) {
            if ($started) {
                try {
                    $db->rollBack();
                } catch (Throwable) {
                    // ignore
                }
            }

            return 0;
        }

        if (function_exists('ap_do_action')) {
            ap_do_action('ap_topic_created', $topicId, self::getTopic($topicId, $db));
        }

        return $topicId;
    }

    /**
     * Update topic metadata (not first-post content).
     *
     * @param array<string, mixed> $data
     */
    public static function updateTopic(int $id, array $data, ?AP_DB $db = null): bool
    {
        if ($id < 1 || $data === []) {
            return false;
        }

        $db = self::resolveDb($db);
        $existing = self::getTopic($id, $db);
        if ($existing === null) {
            return false;
        }

        $update = [];
        $forumId = (int) $existing->forum_id;

        if (array_key_exists('topic_title', $data) || array_key_exists('title', $data)) {
            $title = trim((string) ($data['topic_title'] ?? $data['title'] ?? ''));
            if ($title === '') {
                return false;
            }
            $title = str_replace("\0", '', $title);
            if (function_exists('mb_substr')) {
                $title = mb_substr($title, 0, 255);
            } else {
                $title = substr($title, 0, 255);
            }
            $update['topic_title'] = $title;
        }

        if (array_key_exists('topic_status', $data) || array_key_exists('status', $data)) {
            $update['topic_status'] = self::normalizeTopicStatus(
                (string) ($data['topic_status'] ?? $data['status'] ?? self::TOPIC_STATUS_OPEN)
            );
        }

        if (array_key_exists('topic_type', $data) || array_key_exists('type', $data)) {
            $update['topic_type'] = self::normalizeTopicType(
                (string) ($data['topic_type'] ?? $data['type'] ?? self::TOPIC_TYPE_STANDARD)
            );
        }

        if (array_key_exists('topic_approved', $data)) {
            $update['topic_approved'] = (int) $data['topic_approved'] ? 1 : 0;
        }

        if (array_key_exists('forum_id', $data)) {
            $newForum = max(0, (int) $data['forum_id']);
            if ($newForum < 1 || self::getForum($newForum, $db) === null) {
                return false;
            }
            $forum = self::getForum($newForum, $db);
            if ($forum !== null && (string) $forum->forum_type === self::FORUM_TYPE_CATEGORY) {
                return false;
            }
            $update['forum_id'] = $newForum;
            $forumId = $newForum;
        }

        if (
            array_key_exists('topic_slug', $data) || array_key_exists('slug', $data)
            || isset($update['forum_id']) || isset($update['topic_title'])
        ) {
            $slugSource = (string) ($data['topic_slug'] ?? $data['slug']
                ?? $update['topic_title'] ?? $existing->topic_title);
            $slug = self::sanitizeSlug($slugSource);
            if ($slug === '') {
                $slug = 'topic';
            }
            $update['topic_slug'] = self::uniqueTopicSlug($slug, $forumId, $id, $db);
        }

        $update['topic_modified'] = self::nowLocal();

        $result = $db->update('topics', $update, ['topic_id' => $id]);
        if ($result === false) {
            return false;
        }

        // Keep posts.forum_id in sync when topic moved.
        if (isset($update['forum_id'])) {
            $postsTable = $db->quoteIdentifier($db->table('forum_posts'));
            $db->query(
                'UPDATE ' . $postsTable . ' SET ' . $db->quoteIdentifier('forum_id') . ' = ? WHERE '
                . $db->quoteIdentifier('topic_id') . ' = ?',
                [(int) $update['forum_id'], $id]
            );
        }

        if (function_exists('ap_do_action')) {
            ap_do_action('ap_topic_updated', $id, self::getTopic($id, $db));
        }

        return true;
    }

    /**
     * Soft-delete (status=deleted) or force-delete a topic and its posts.
     */
    public static function deleteTopic(int $id, bool $force = false, ?AP_DB $db = null): bool
    {
        if ($id < 1) {
            return false;
        }

        $db = self::resolveDb($db);
        $topic = self::getTopic($id, $db);
        if ($topic === null) {
            return false;
        }

        $forumId = (int) $topic->forum_id;
        $wasApproved = (int) $topic->topic_approved === 1
            && (string) $topic->topic_status !== self::TOPIC_STATUS_DELETED;

        if (!$force) {
            if ((string) $topic->topic_status === self::TOPIC_STATUS_DELETED) {
                return true;
            }
            $ok = $db->update('topics', [
                'topic_status' => self::TOPIC_STATUS_DELETED,
                'topic_modified' => self::nowLocal(),
            ], ['topic_id' => $id]);
            if ($ok === false) {
                return false;
            }
            if ($wasApproved) {
                $postCount = self::countPosts($id, ['approved_only' => true], $db);
                self::adjustForumStats($forumId, -1, -$postCount, $db);
                self::refreshForumLastPost($forumId, $db);
            }

            if (function_exists('ap_do_action')) {
                ap_do_action('ap_topic_deleted', $id, false);
            }

            return true;
        }

        // Force: remove attachments, posts, then topic.
        if (class_exists('AP_Forum_Attachment', false)) {
            AP_Forum_Attachment::deleteForTopic($id, true, $db);
        }

        $postsTable = $db->quoteIdentifier($db->table('forum_posts'));
        $db->query(
            'DELETE FROM ' . $postsTable . ' WHERE ' . $db->quoteIdentifier('topic_id') . ' = ?',
            [$id]
        );
        $result = $db->delete('topics', ['topic_id' => $id]);
        if ($result === false) {
            return false;
        }

        if ($wasApproved) {
            $replyCount = (int) $topic->reply_count;
            // topic_count -1, post_count -(replies + first post)
            self::adjustForumStats($forumId, -1, -($replyCount + 1), $db);
            self::refreshForumLastPost($forumId, $db);
        }

        if (function_exists('ap_do_action')) {
            ap_do_action('ap_topic_deleted', $id, true);
        }

        return true;
    }

    /**
     * Increment view counter for a topic.
     */
    public static function incrementTopicViews(int $id, ?AP_DB $db = null): bool
    {
        if ($id < 1) {
            return false;
        }

        $db = self::resolveDb($db);
        $table = $db->quoteIdentifier($db->table('topics'));
        $stmt = $db->query(
            'UPDATE ' . $table . ' SET ' . $db->quoteIdentifier('topic_views') . ' = '
            . $db->quoteIdentifier('topic_views') . ' + 1 WHERE '
            . $db->quoteIdentifier('topic_id') . ' = ?',
            [$id]
        );

        return $stmt !== false;
    }

    /**
     * List topics in a forum.
     *
     * Ordering: global/announce/sticky first, then by last post time desc.
     *
     * @param array<string, mixed> $args Keys: per_page, page, include_deleted, approved_only,
     *                                   status, type, orderby, order
     *
     * @return list<object>
     */
    public static function getTopics(int $forumId, array $args = [], ?AP_DB $db = null): array
    {
        if ($forumId < 1) {
            return [];
        }

        $db = self::resolveDb($db);
        $table = $db->quoteIdentifier($db->table('topics'));
        $sql = 'SELECT * FROM ' . $table . ' WHERE ' . $db->quoteIdentifier('forum_id') . ' = ?';
        $params = [$forumId];

        $includeDeleted = !empty($args['include_deleted']);
        if (!$includeDeleted) {
            $sql .= ' AND ' . $db->quoteIdentifier('topic_status') . ' != ?';
            $params[] = self::TOPIC_STATUS_DELETED;
        }

        $approvedOnly = !array_key_exists('approved_only', $args) || !empty($args['approved_only']);
        if ($approvedOnly) {
            $sql .= ' AND ' . $db->quoteIdentifier('topic_approved') . ' = 1';
        }

        if (!empty($args['status'])) {
            $sql .= ' AND ' . $db->quoteIdentifier('topic_status') . ' = ?';
            $params[] = self::normalizeTopicStatus((string) $args['status']);
        }

        if (!empty($args['type'])) {
            $sql .= ' AND ' . $db->quoteIdentifier('topic_type') . ' = ?';
            $params[] = self::normalizeTopicType((string) $args['type']);
        }

        // Announcement / rules / sticky float above standard.
        $sql .= ' ORDER BY CASE ' . $db->quoteIdentifier('topic_type')
            . " WHEN 'announcement' THEN 0 WHEN 'rules' THEN 1 WHEN 'sticky' THEN 2 ELSE 3 END ASC, "
            . $db->quoteIdentifier('topic_last_post_time') . ' DESC, '
            . $db->quoteIdentifier('topic_id') . ' DESC';

        $perPage = max(0, (int) ($args['per_page'] ?? 0));
        $page = max(1, (int) ($args['page'] ?? 1));
        if ($perPage > 0) {
            $offset = ($page - 1) * $perPage;
            $sql .= ' LIMIT ' . $perPage . ' OFFSET ' . $offset;
        }

        $rows = $db->getResults($sql, $params);
        $out = [];
        foreach ($rows as $row) {
            $out[] = self::normalizeTopicRow($row);
        }

        return $out;
    }

    /**
     * Count topics in a forum.
     *
     * @param array<string, mixed> $args include_deleted, approved_only
     */
    public static function countTopics(int $forumId, array $args = [], ?AP_DB $db = null): int
    {
        if ($forumId < 1) {
            return 0;
        }

        $db = self::resolveDb($db);
        $table = $db->quoteIdentifier($db->table('topics'));
        $sql = 'SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $db->quoteIdentifier('forum_id') . ' = ?';
        $params = [$forumId];

        if (empty($args['include_deleted'])) {
            $sql .= ' AND ' . $db->quoteIdentifier('topic_status') . ' != ?';
            $params[] = self::TOPIC_STATUS_DELETED;
        }

        $approvedOnly = !array_key_exists('approved_only', $args) || !empty($args['approved_only']);
        if ($approvedOnly) {
            $sql .= ' AND ' . $db->quoteIdentifier('topic_approved') . ' = 1';
        }

        return (int) $db->getVar($sql, $params);
    }

    /**
     * Query topics across all forums (admin list tables).
     *
     * @param array<string, mixed> $args Keys: forum_id, status, type, include_deleted,
     *                                   approved_only (bool|null; null = any), pending_only,
     *                                   search, poster_id, per_page, page, orderby, order
     *
     * @return list<object>
     */
    public static function queryTopics(array $args = [], ?AP_DB $db = null): array
    {
        $db = self::resolveDb($db);
        $table = $db->quoteIdentifier($db->table('topics'));
        $sql = 'SELECT * FROM ' . $table . ' WHERE 1=1';
        $params = [];

        if (isset($args['forum_id']) && (int) $args['forum_id'] > 0) {
            $sql .= ' AND ' . $db->quoteIdentifier('forum_id') . ' = ?';
            $params[] = (int) $args['forum_id'];
        }

        if (!empty($args['pending_only'])) {
            $sql .= ' AND ' . $db->quoteIdentifier('topic_approved') . ' = 0';
        } elseif (array_key_exists('approved_only', $args) && $args['approved_only'] !== null) {
            if (!empty($args['approved_only'])) {
                $sql .= ' AND ' . $db->quoteIdentifier('topic_approved') . ' = 1';
            } else {
                $sql .= ' AND ' . $db->quoteIdentifier('topic_approved') . ' = 0';
            }
        }

        if (empty($args['include_deleted'])) {
            $sql .= ' AND ' . $db->quoteIdentifier('topic_status') . ' != ?';
            $params[] = self::TOPIC_STATUS_DELETED;
        } elseif (!empty($args['status'])) {
            // When including deleted and a specific status is requested, filter it.
        }

        if (!empty($args['status'])) {
            $sql .= ' AND ' . $db->quoteIdentifier('topic_status') . ' = ?';
            $params[] = self::normalizeTopicStatus((string) $args['status']);
        }

        if (!empty($args['type'])) {
            $sql .= ' AND ' . $db->quoteIdentifier('topic_type') . ' = ?';
            $params[] = self::normalizeTopicType((string) $args['type']);
        }

        if (isset($args['poster_id']) && (int) $args['poster_id'] > 0) {
            $sql .= ' AND ' . $db->quoteIdentifier('topic_poster') . ' = ?';
            $params[] = (int) $args['poster_id'];
        }

        if (!empty($args['search']) && is_string($args['search'])) {
            $like = '%' . self::escapeLike(trim($args['search'])) . '%';
            $sql .= ' AND (' . $db->quoteIdentifier('topic_title') . ' LIKE ? OR '
                . $db->quoteIdentifier('topic_slug') . ' LIKE ?)';
            $params[] = $like;
            $params[] = $like;
        }

        $orderby = strtolower((string) ($args['orderby'] ?? 'last_post'));
        $orderCol = match ($orderby) {
            'title' => 'topic_title',
            'time', 'created' => 'topic_time',
            'replies', 'posts' => 'topic_replies',
            'views' => 'topic_views',
            'id' => 'topic_id',
            'status' => 'topic_status',
            default => 'topic_last_post_time',
        };
        $order = strtoupper((string) ($args['order'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
        $sql .= ' ORDER BY ' . $db->quoteIdentifier($orderCol) . ' ' . $order
            . ', ' . $db->quoteIdentifier('topic_id') . ' ' . $order;

        $perPage = max(0, (int) ($args['per_page'] ?? $args['limit'] ?? 0));
        $page = max(1, (int) ($args['page'] ?? 1));
        if ($perPage > 0) {
            $offset = isset($args['offset'])
                ? max(0, (int) $args['offset'])
                : ($page - 1) * $perPage;
            $sql .= ' LIMIT ' . $perPage . ' OFFSET ' . $offset;
        }

        $rows = $db->getResults($sql, $params);
        $out = [];
        foreach ($rows as $row) {
            $out[] = self::normalizeTopicRow($row);
        }

        return $out;
    }

    /**
     * Count topics matching {@see queryTopics()} filters (ignores limit/page).
     *
     * @param array<string, mixed> $args Same filters as queryTopics
     */
    public static function countTopicsQuery(array $args = [], ?AP_DB $db = null): int
    {
        $db = self::resolveDb($db);
        $table = $db->quoteIdentifier($db->table('topics'));
        $sql = 'SELECT COUNT(*) FROM ' . $table . ' WHERE 1=1';
        $params = [];

        if (isset($args['forum_id']) && (int) $args['forum_id'] > 0) {
            $sql .= ' AND ' . $db->quoteIdentifier('forum_id') . ' = ?';
            $params[] = (int) $args['forum_id'];
        }

        if (!empty($args['pending_only'])) {
            $sql .= ' AND ' . $db->quoteIdentifier('topic_approved') . ' = 0';
        } elseif (array_key_exists('approved_only', $args) && $args['approved_only'] !== null) {
            if (!empty($args['approved_only'])) {
                $sql .= ' AND ' . $db->quoteIdentifier('topic_approved') . ' = 1';
            } else {
                $sql .= ' AND ' . $db->quoteIdentifier('topic_approved') . ' = 0';
            }
        }

        if (empty($args['include_deleted'])) {
            $sql .= ' AND ' . $db->quoteIdentifier('topic_status') . ' != ?';
            $params[] = self::TOPIC_STATUS_DELETED;
        }

        if (!empty($args['status'])) {
            $sql .= ' AND ' . $db->quoteIdentifier('topic_status') . ' = ?';
            $params[] = self::normalizeTopicStatus((string) $args['status']);
        }

        if (!empty($args['type'])) {
            $sql .= ' AND ' . $db->quoteIdentifier('topic_type') . ' = ?';
            $params[] = self::normalizeTopicType((string) $args['type']);
        }

        if (isset($args['poster_id']) && (int) $args['poster_id'] > 0) {
            $sql .= ' AND ' . $db->quoteIdentifier('topic_poster') . ' = ?';
            $params[] = (int) $args['poster_id'];
        }

        if (!empty($args['search']) && is_string($args['search'])) {
            $like = '%' . self::escapeLike(trim($args['search'])) . '%';
            $sql .= ' AND (' . $db->quoteIdentifier('topic_title') . ' LIKE ? OR '
                . $db->quoteIdentifier('topic_slug') . ' LIKE ?)';
            $params[] = $like;
            $params[] = $like;
        }

        return (int) $db->getVar($sql, $params);
    }

    /**
     * Theme-friendly topic list for a forum.
     *
     * Annotates each row with `is_unread` for the current user (or `user_id`
     * in $args) when unread tracking is available.
     *
     * @param array<string, mixed> $args Pass-through to getTopics; also user_id
     *
     * @return list<array<string, mixed>>
     */
    public static function getTopicsDisplayData(int $forumId, array $args = [], ?AP_DB $db = null): array
    {
        $db = self::resolveDb($db);
        $topics = self::getTopics($forumId, $args, $db);
        $out = [];
        foreach ($topics as $topic) {
            $out[] = self::topicToDisplayRow($topic, $db);
        }

        $userId = self::resolveViewerUserId($args, $db);
        if (class_exists('AP_Forum_Read', false)) {
            return AP_Forum_Read::annotateTopics($userId, $out, $db);
        }
        foreach ($out as $i => $row) {
            $out[$i]['is_unread'] = false;
        }

        return $out;
    }

    // -------------------------------------------------------------------------
    // Posts / replies
    // -------------------------------------------------------------------------

    /**
     * Fetch a forum post by ID.
     */
    public static function getPost(int $id, ?AP_DB $db = null): ?object
    {
        if ($id < 1) {
            return null;
        }

        $db = self::resolveDb($db);
        $table = $db->quoteIdentifier($db->table('forum_posts'));
        $row = $db->getRow(
            'SELECT * FROM ' . $table . ' WHERE ' . $db->quoteIdentifier('post_id') . ' = ?',
            [$id]
        );

        return $row !== null ? self::normalizePostRow($row) : null;
    }

    /**
     * Reply to an existing topic. Returns new post_id or 0 on failure.
     *
     * @param array<string, mixed> $data Keys: topic_id, content (required), poster_id, poster_ip,
     *                                   post_subject, post_approved
     * @param array<string, mixed> $args Options:
     *                                   check_open (bool, default true),
     *                                   check_permissions (bool, default false) — when true,
     *                                   enforces {@see AP_Forum_Permissions} post_replies,
     *                                   check_guard (bool, default false) — flood / spam / approval
     *                                   via {@see AP_Forum_Guard}
     */
    public static function createReply(array $data, ?AP_DB $db = null, array $args = []): int
    {
        $db = self::resolveDb($db);
        $checkOpen = !array_key_exists('check_open', $args) || !empty($args['check_open']);
        $checkPermissions = !empty($args['check_permissions']);
        $checkGuard = !empty($args['check_guard']);

        $topicId = (int) ($data['topic_id'] ?? 0);
        if ($topicId < 1) {
            return 0;
        }

        $topic = self::getTopic($topicId, $db);
        if ($topic === null) {
            return 0;
        }
        if ((string) $topic->topic_status === self::TOPIC_STATUS_DELETED) {
            return 0;
        }
        if ($checkOpen && self::isTopicLocked($topic)) {
            return 0;
        }

        $forumId = (int) $topic->forum_id;
        $forum = self::getForum($forumId, $db);
        if ($forum === null) {
            return 0;
        }
        if ($checkOpen && (string) $forum->forum_status !== self::FORUM_STATUS_OPEN) {
            return 0;
        }

        $content = trim((string) ($data['content'] ?? $data['post_content'] ?? $data['body'] ?? ''));
        if ($content === '') {
            return 0;
        }
        $content = str_replace("\0", '', $content);

        $posterId = max(0, (int) ($data['poster_id'] ?? $data['user_id'] ?? 0));
        $posterIp = trim((string) ($data['poster_ip'] ?? ''));
        $approved = array_key_exists('post_approved', $data)
            ? ((int) $data['post_approved'] ? 1 : 0)
            : 1;

        if ($checkGuard && class_exists('AP_Forum_Guard', false)) {
            $guardData = [
                'type' => 'reply',
                'forum_id' => $forumId,
                'topic_id' => $topicId,
                'title' => (string) ($data['post_subject'] ?? $topic->topic_title ?? ''),
                'content' => $content,
                'poster_id' => $posterId,
                'poster_ip' => $posterIp,
            ];
            if (array_key_exists('post_approved', $data)) {
                $guardData['post_approved'] = $approved;
            }
            $guard = AP_Forum_Guard::evaluate($guardData, $db);
            if (empty($guard['allowed'])) {
                return 0;
            }
            if (!array_key_exists('post_approved', $data)) {
                $approved = (int) ($guard['approved'] ?? 1) ? 1 : 0;
            }
        }

        if (
            $checkPermissions
            && class_exists('AP_Forum_Permissions', false)
            && !AP_Forum_Permissions::userCanPostReply($posterId, $forumId, $db)
        ) {
            return 0;
        }

        $subject = trim((string) ($data['post_subject'] ?? $topic->topic_title));
        if (function_exists('mb_substr')) {
            $subject = mb_substr($subject, 0, 255);
        } else {
            $subject = substr($subject, 0, 255);
        }

        $now = self::nowLocal();
        $position = self::nextPostPosition($topicId, $db);

        $postRow = [
            'topic_id' => $topicId,
            'forum_id' => $forumId,
            'poster_id' => $posterId,
            'post_subject' => $subject,
            'post_content' => $content,
            'post_content_filtered' => self::filteredContent(
                $content,
                array_key_exists('post_content_filtered', $data)
                    ? (string) $data['post_content_filtered']
                    : null
            ),
            'poster_ip' => $posterIp,
            'post_time' => $now,
            'post_modified' => $now,
            'post_approved' => $approved,
            'post_reported' => 0,
            'post_edit_reason' => '',
            'post_edit_user' => 0,
            'post_edit_time' => self::EMPTY_DATETIME,
            'post_edit_count' => 0,
            'post_position' => $position,
            'like_count' => 0,
        ];

        if (function_exists('ap_do_action')) {
            ap_do_action('ap_pre_forum_post_insert', $postRow);
        }

        $result = $db->insert('forum_posts', $postRow);
        if ($result === false) {
            // Retry without like_count if schema is pre-v11.
            unset($postRow['like_count']);
            $result = $db->insert('forum_posts', $postRow);
            if ($result === false) {
                return 0;
            }
        }

        $postId = (int) $db->lastInsertId();
        if ($postId < 1) {
            return 0;
        }

        if ($approved === 1) {
            $db->update('topics', [
                'reply_count' => (int) $topic->reply_count + 1,
                'last_post_id' => $postId,
                'last_poster_id' => $posterId,
                'topic_last_post_time' => $now,
                'topic_modified' => $now,
            ], ['topic_id' => $topicId]);

            self::bumpForumStats($forumId, 0, 1, $postId, $posterId, $now, $topicId, $db);
        }

        if (function_exists('ap_do_action')) {
            ap_do_action('ap_forum_post_inserted', $postId, self::getPost($postId, $db));
        }

        return $postId;
    }

    /**
     * Update a forum post.
     *
     * @param array<string, mixed> $data Keys: post_content, post_subject, post_approved,
     *                                   post_edit_reason, post_edit_user
     */
    public static function updatePost(int $id, array $data, ?AP_DB $db = null): bool
    {
        if ($id < 1 || $data === []) {
            return false;
        }

        $db = self::resolveDb($db);
        $existing = self::getPost($id, $db);
        if ($existing === null) {
            return false;
        }

        $update = [];
        $wasApproved = (int) $existing->post_approved === 1;

        $hasContent = array_key_exists('post_content', $data)
            || array_key_exists('content', $data)
            || array_key_exists('body', $data);
        if ($hasContent) {
            $content = trim((string) ($data['post_content'] ?? $data['content'] ?? $data['body'] ?? ''));
            if ($content === '') {
                return false;
            }
            $content = str_replace("\0", '', $content);
            $update['post_content'] = $content;
            // Re-render filtered HTML unless the caller supplies it explicitly.
            if (!array_key_exists('post_content_filtered', $data)) {
                $update['post_content_filtered'] = self::filteredContent($content, null);
            }
        }

        if (array_key_exists('post_content_filtered', $data)) {
            $update['post_content_filtered'] = (string) $data['post_content_filtered'];
        }

        if (array_key_exists('post_subject', $data) || array_key_exists('subject', $data)) {
            $subject = trim((string) ($data['post_subject'] ?? $data['subject'] ?? ''));
            if (function_exists('mb_substr')) {
                $subject = mb_substr($subject, 0, 255);
            } else {
                $subject = substr($subject, 0, 255);
            }
            $update['post_subject'] = $subject;
        }

        if (array_key_exists('post_approved', $data)) {
            $update['post_approved'] = (int) $data['post_approved'] ? 1 : 0;
        }

        $now = self::nowLocal();
        $update['post_modified'] = $now;
        $update['post_edit_count'] = (int) $existing->post_edit_count + 1;
        $update['post_edit_time'] = $now;

        if (array_key_exists('post_edit_reason', $data) || array_key_exists('edit_reason', $data)) {
            $reason = (string) ($data['post_edit_reason'] ?? $data['edit_reason'] ?? '');
            if (function_exists('mb_substr')) {
                $reason = mb_substr($reason, 0, 255);
            } else {
                $reason = substr($reason, 0, 255);
            }
            $update['post_edit_reason'] = $reason;
        }

        if (array_key_exists('post_edit_user', $data) || array_key_exists('edit_user', $data)) {
            $update['post_edit_user'] = max(0, (int) ($data['post_edit_user'] ?? $data['edit_user'] ?? 0));
        }

        $result = $db->update('forum_posts', $update, ['post_id' => $id]);
        if ($result === false) {
            return false;
        }

        // Approval flip: adjust counters.
        if (isset($update['post_approved'])) {
            $nowApproved = (int) $update['post_approved'] === 1;
            if ($wasApproved && !$nowApproved) {
                self::onPostUnapproved($existing, $db);
            } elseif (!$wasApproved && $nowApproved) {
                self::onPostApproved(self::getPost($id, $db), $db);
            }
        }

        if (function_exists('ap_do_action')) {
            ap_do_action('ap_forum_post_updated', $id, self::getPost($id, $db));
        }

        return true;
    }

    /**
     * Permanently delete a forum post. Cannot delete the first post of a topic
     * unless force=true (then the whole topic is force-deleted).
     */
    public static function deletePost(int $id, bool $force = false, ?AP_DB $db = null): bool
    {
        if ($id < 1) {
            return false;
        }

        $db = self::resolveDb($db);
        $post = self::getPost($id, $db);
        if ($post === null) {
            return false;
        }

        $topic = self::getTopic((int) $post->topic_id, $db);
        if ($topic !== null && (int) $topic->first_post_id === $id) {
            if (!$force) {
                return false;
            }

            return self::deleteTopic((int) $topic->topic_id, true, $db);
        }

        $wasApproved = (int) $post->post_approved === 1;

        if (class_exists('AP_Forum_Attachment', false)) {
            AP_Forum_Attachment::deleteForPost($id, true, $db);
        }

        // Drop likes for this post (best-effort; table may be absent pre-v11).
        try {
            $db->delete('forum_post_likes', ['post_id' => $id]);
        } catch (Throwable) {
            // ignore
        }

        $result = $db->delete('forum_posts', ['post_id' => $id]);
        if ($result === false) {
            return false;
        }

        if ($topic !== null && $wasApproved) {
            $replyCount = max(0, (int) $topic->reply_count - 1);
            $db->update('topics', [
                'reply_count' => $replyCount,
                'topic_modified' => self::nowLocal(),
            ], ['topic_id' => (int) $topic->topic_id]);
            self::adjustForumStats((int) $topic->forum_id, 0, -1, $db);
            self::refreshTopicLastPost((int) $topic->topic_id, $db);
            self::refreshForumLastPost((int) $topic->forum_id, $db);
        }

        if (function_exists('ap_do_action')) {
            ap_do_action('ap_forum_post_deleted', $id, $post);
        }

        return true;
    }

    /**
     * List posts in a topic (chronological by default).
     *
     * @param array<string, mixed> $args Keys: per_page, page, approved_only, order (ASC|DESC)
     *
     * @return list<object>
     */
    public static function getPosts(int $topicId, array $args = [], ?AP_DB $db = null): array
    {
        if ($topicId < 1) {
            return [];
        }

        $db = self::resolveDb($db);
        $table = $db->quoteIdentifier($db->table('forum_posts'));
        $sql = 'SELECT * FROM ' . $table . ' WHERE ' . $db->quoteIdentifier('topic_id') . ' = ?';
        $params = [$topicId];

        $approvedOnly = !array_key_exists('approved_only', $args) || !empty($args['approved_only']);
        if ($approvedOnly) {
            $sql .= ' AND ' . $db->quoteIdentifier('post_approved') . ' = 1';
        }

        $order = strtoupper((string) ($args['order'] ?? 'ASC'));
        if ($order !== 'DESC') {
            $order = 'ASC';
        }
        $sql .= ' ORDER BY ' . $db->quoteIdentifier('post_time') . ' ' . $order . ', '
            . $db->quoteIdentifier('post_id') . ' ' . $order;

        $perPage = max(0, (int) ($args['per_page'] ?? 0));
        $page = max(1, (int) ($args['page'] ?? 1));
        if ($perPage > 0) {
            $offset = ($page - 1) * $perPage;
            $sql .= ' LIMIT ' . $perPage . ' OFFSET ' . $offset;
        }

        $rows = $db->getResults($sql, $params);
        $out = [];
        foreach ($rows as $row) {
            $out[] = self::normalizePostRow($row);
        }

        return $out;
    }

    /**
     * Count posts in a topic.
     *
     * @param array<string, mixed> $args approved_only
     */
    public static function countPosts(int $topicId, array $args = [], ?AP_DB $db = null): int
    {
        if ($topicId < 1) {
            return 0;
        }

        $db = self::resolveDb($db);
        $table = $db->quoteIdentifier($db->table('forum_posts'));
        $sql = 'SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $db->quoteIdentifier('topic_id') . ' = ?';
        $params = [$topicId];

        $approvedOnly = !array_key_exists('approved_only', $args) || !empty($args['approved_only']);
        if ($approvedOnly) {
            $sql .= ' AND ' . $db->quoteIdentifier('post_approved') . ' = 1';
        }

        return (int) $db->getVar($sql, $params);
    }

    /**
     * Theme-friendly post list for a topic.
     *
     * Action flags (SPEC B2): can_quote, can_edit, can_delete, can_like, can_moderate.
     *
     * @return list<array<string, mixed>>
     */
    public static function getPostsDisplayData(int $topicId, array $args = [], ?AP_DB $db = null): array
    {
        $db = self::resolveDb($db);
        $posts = self::getPosts($topicId, $args, $db);
        $viewerId = 0;
        if (function_exists('ap_get_current_user_id')) {
            try {
                $viewerId = (int) ap_get_current_user_id($db);
            } catch (Throwable) {
                $viewerId = 0;
            }
        }
        $postIds = [];
        $authorIds = [];
        $forumIdForTopic = 0;
        foreach ($posts as $p) {
            $postIds[] = (int) $p->post_id;
            $aid = (int) ($p->poster_id ?? 0);
            if ($aid > 0) {
                $authorIds[] = $aid;
            }
            if ($forumIdForTopic < 1) {
                $forumIdForTopic = (int) ($p->forum_id ?? 0);
            }
        }
        $likedMap = [];
        if ($viewerId > 0 && class_exists('AP_Forum_Like', false) && $postIds !== []) {
            $likedMap = AP_Forum_Like::likedMapForUser($viewerId, $postIds, $db);
        }
        // One batch usermeta query for all distinct authors (posts + likes given/received).
        $authorStatsMap = [];
        if ($authorIds !== [] && class_exists('AP_Forum_Stats', false)) {
            $authorStatsMap = AP_Forum_Stats::getAuthorPanelStatsForUsers($authorIds, $db);
        }
        // Batch location + signature for author pane / post footer (SPEC B2) — avoid N+1.
        $locationMap = [];
        $signatureMap = [];
        $sigsEnabled = self::signaturesEnabled($db);
        if ($authorIds !== [] && class_exists('AP_User', false)) {
            try {
                $locationMap = AP_User::getMetaForUsers($authorIds, 'location', $db);
            } catch (Throwable) {
                $locationMap = [];
            }
            if ($sigsEnabled) {
                try {
                    $signatureMap = AP_User::getMetaForUsers($authorIds, 'signature', $db);
                } catch (Throwable) {
                    $signatureMap = [];
                }
            }
        }

        // Topic-level reply gate for Quote (SPEC B2): reply cap + not locked.
        $canQuote = false;
        if ($viewerId > 0 && $topicId > 0) {
            $topic = self::getTopic($topicId, $db);
            if ($topic !== null && !self::isTopicLocked($topic)) {
                $fid = (int) ($topic->forum_id ?? $forumIdForTopic);
                if ($fid > 0 && class_exists('AP_Forum_Permissions', false)) {
                    $canQuote = AP_Forum_Permissions::userCanPostReply($viewerId, $fid, $db);
                } elseif ($fid > 0) {
                    $canQuote = true;
                }
            }
        }

        // Like requires login + view on the forum (no separate like ACL).
        $canViewForum = $viewerId > 0;
        if ($viewerId > 0 && $forumIdForTopic > 0 && class_exists('AP_Forum_Permissions', false)) {
            $canViewForum = AP_Forum_Permissions::userCanViewForum($viewerId, $forumIdForTopic, $db);
        }

        $out = [];
        $n = 0;
        foreach ($posts as $post) {
            $n++;
            $aid = (int) ($post->poster_id ?? 0);
            $preloaded = $aid > 0 && isset($authorStatsMap[$aid]) ? $authorStatsMap[$aid] : null;
            if ($preloaded !== null && $aid > 0 && array_key_exists($aid, $locationMap)) {
                $preloaded['location'] = (string) $locationMap[$aid];
            } elseif ($preloaded === null && $aid > 0 && array_key_exists($aid, $locationMap)) {
                $preloaded = [
                    'forum_posts' => 0,
                    'forum_likes_given' => 0,
                    'forum_likes_received' => 0,
                    'location' => (string) $locationMap[$aid],
                ];
            }
            if ($aid > 0) {
                // Always set signature key when batching so postToDisplayRow skips per-user meta.
                if ($preloaded === null) {
                    $preloaded = [
                        'forum_posts' => 0,
                        'forum_likes_given' => 0,
                        'forum_likes_received' => 0,
                    ];
                }
                if ($sigsEnabled && array_key_exists($aid, $signatureMap)) {
                    $preloaded['signature'] = (string) $signatureMap[$aid];
                } else {
                    $preloaded['signature'] = '';
                }
            }
            $row = self::postToDisplayRow($post, $n, $db, $preloaded);
            $row['liked_by_me'] = !empty($likedMap[(int) $post->post_id]);
            $row['can_like'] = $viewerId > 0 && $canViewForum;
            $row['can_quote'] = $canQuote;
            $row['can_edit'] = self::userCanEditPost($viewerId, $post, $db);
            $row['can_delete'] = self::userCanDeletePost($viewerId, $post, $db);
            $row['can_moderate'] = $viewerId > 0 && class_exists('AP_Forum_Permissions', false)
                && AP_Forum_Permissions::userCanModerate($viewerId, (int) $post->forum_id, $db);
            $out[] = $row;
        }

        return $out;
    }

    /**
     * Build BBCode citation markup for starting a reply with a quote (SPEC B2).
     *
     * Soft-caps long bodies so the reply form stays usable. Author is sanitized
     * for use inside a [quote=…] attribute (no brackets/newlines).
     *
     * @param int $maxLen Max characters of body to include (0 = unlimited). Default 2000.
     */
    public static function buildQuoteMarkup(string $author, string $content, int $maxLen = 2000): string
    {
        $author = trim(str_replace(["\r", "\n", "\0", '[', ']', '"', "'"], '', $author));
        if ($author === '') {
            $author = 'Guest';
        }
        // Keep attribute compact for long display names.
        if (function_exists('mb_substr')) {
            if (mb_strlen($author) > 80) {
                $author = mb_substr($author, 0, 80);
            }
        } elseif (strlen($author) > 80) {
            $author = substr($author, 0, 80);
        }

        $content = str_replace("\0", '', $content);
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        $content = trim($content);
        if ($maxLen > 0) {
            if (function_exists('mb_strlen') && function_exists('mb_substr')) {
                if (mb_strlen($content) > $maxLen) {
                    $content = rtrim(mb_substr($content, 0, $maxLen)) . '…';
                }
            } elseif (strlen($content) > $maxLen) {
                $content = rtrim(substr($content, 0, $maxLen)) . '…';
            }
        }

        return '[quote=' . $author . ']' . $content . "[/quote]\n\n";
    }

    /**
     * Quote markup for a post, or empty string when the post is unavailable.
     *
     * Does not enforce reply permissions — callers should gate the Quote UI.
     */
    public static function getQuoteMarkupForPost(int $postId, ?AP_DB $db = null): string
    {
        if ($postId < 1) {
            return '';
        }
        $db = self::resolveDb($db);
        $post = self::getPost($postId, $db);
        if ($post === null || (int) ($post->post_approved ?? 0) !== 1) {
            return '';
        }
        $author = 'Guest';
        $posterId = (int) ($post->poster_id ?? 0);
        if ($posterId > 0 && class_exists('AP_User', false)) {
            $user = method_exists('AP_User', 'getById')
                ? AP_User::getById($posterId, $db)
                : null;
            if ($user !== null) {
                $name = trim((string) ($user->display_name ?? ''));
                if ($name === '') {
                    $name = trim((string) ($user->user_login ?? ''));
                }
                if ($name !== '') {
                    $author = $name;
                }
            }
        } elseif (!empty($post->poster_name)) {
            $author = (string) $post->poster_name;
        }

        return self::buildQuoteMarkup($author, (string) ($post->post_content ?? ''));
    }

    /**
     * Whether a user may edit a forum post (own post with edit_own, or moderator).
     */
    public static function userCanEditPost(int $userId, object|int $post, ?AP_DB $db = null): bool
    {
        if ($userId < 1) {
            return false;
        }
        $db = self::resolveDb($db);
        if (is_int($post)) {
            $post = self::getPost($post, $db);
        }
        if ($post === null) {
            return false;
        }
        $forumId = (int) ($post->forum_id ?? 0);
        if (function_exists('ap_user_can')) {
            if (ap_user_can($userId, 'manage_forums', null, $db)
                || ap_user_can($userId, 'moderate_forums', null, $db)
            ) {
                return true;
            }
        }
        if (class_exists('AP_Forum_Permissions', false)
            && AP_Forum_Permissions::userCanModerate($userId, $forumId, $db)
        ) {
            return true;
        }
        $isOwner = (int) ($post->poster_id ?? 0) === $userId;
        if (!$isOwner) {
            return false;
        }
        if (class_exists('AP_Forum_Permissions', false)) {
            return AP_Forum_Permissions::userCan($userId, $forumId, AP_Forum_Permissions::PERM_EDIT_OWN, $db);
        }

        return true;
    }

    /**
     * Whether a user may delete a forum post (own with delete_own, or moderator).
     */
    public static function userCanDeletePost(int $userId, object|int $post, ?AP_DB $db = null): bool
    {
        if ($userId < 1) {
            return false;
        }
        $db = self::resolveDb($db);
        if (is_int($post)) {
            $post = self::getPost($post, $db);
        }
        if ($post === null) {
            return false;
        }
        $forumId = (int) ($post->forum_id ?? 0);
        if (function_exists('ap_user_can')) {
            if (ap_user_can($userId, 'manage_forums', null, $db)
                || ap_user_can($userId, 'moderate_forums', null, $db)
            ) {
                return true;
            }
        }
        if (class_exists('AP_Forum_Permissions', false)
            && AP_Forum_Permissions::userCanModerate($userId, $forumId, $db)
        ) {
            return true;
        }
        $isOwner = (int) ($post->poster_id ?? 0) === $userId;
        if (!$isOwner) {
            return false;
        }
        if (class_exists('AP_Forum_Permissions', false)) {
            return AP_Forum_Permissions::userCan($userId, $forumId, AP_Forum_Permissions::PERM_DELETE_OWN, $db);
        }

        return true;
    }

    // -------------------------------------------------------------------------
    // Search
    // -------------------------------------------------------------------------

    /**
     * Search topics and/or posts by keyword (LIKE on title/subject/content).
     *
     * @param array<string, mixed> $args Keys:
     *   type (topics|posts|all, default all),
     *   forum_id (int, optional),
     *   approved_only (bool, default true),
     *   include_deleted (bool, default false),
     *   per_page, page,
     *   order (ASC|DESC on post/topic time, default DESC)
     *
     * @return array{
     *   query: string,
     *   total: int,
     *   topics: list<object>,
     *   posts: list<object>,
     *   results: list<array<string, mixed>>
     * }
     */
    public static function search(string $query, array $args = [], ?AP_DB $db = null): array
    {
        $db = self::resolveDb($db);
        $term = trim($query);
        $type = strtolower(trim((string) ($args['type'] ?? 'all')));
        if (!in_array($type, ['topics', 'posts', 'all'], true)) {
            $type = 'all';
        }

        $empty = [
            'query' => $term,
            'total' => 0,
            'topics' => [],
            'posts' => [],
            'results' => [],
        ];
        if ($term === '') {
            return $empty;
        }
        if (function_exists('mb_substr')) {
            $term = mb_substr($term, 0, 200);
        } else {
            $term = substr($term, 0, 200);
        }

        if (
            class_exists('AP_Forum_Guard', false)
            && !AP_Forum_Guard::isSearchEnabled($db)
            && empty($args['force'])
        ) {
            return $empty;
        }

        // ACL: empty allowed set means no readable forums for this user.
        $allowedForums = self::forumIdsAllowedForSearch($args, $db);
        if (is_array($allowedForums) && $allowedForums === []) {
            return $empty;
        }
        // Narrow a single forum_id request if the user cannot read it.
        if (
            is_array($allowedForums)
            && isset($args['forum_id'])
            && (int) $args['forum_id'] > 0
            && !in_array((int) $args['forum_id'], $allowedForums, true)
        ) {
            return $empty;
        }

        $fetchArgs = $args;
        if ($type === 'all') {
            // Fetch all matching rows, merge/sort, then paginate below.
            $fetchArgs['skip_limit'] = true;
            unset($fetchArgs['per_page'], $fetchArgs['page']);
        }

        $topics = [];
        $posts = [];
        if ($type === 'topics' || $type === 'all') {
            $topics = self::searchTopics($term, $fetchArgs, $db);
        }
        if ($type === 'posts' || $type === 'all') {
            $posts = self::searchPosts($term, $fetchArgs, $db);
        }

        $results = [];
        foreach ($topics as $topic) {
            $row = self::topicToDisplayRow($topic, $db);
            $row['result_type'] = 'topic';
            $row['snippet'] = (string) ($topic->topic_title ?? '');
            $results[] = $row;
        }
        foreach ($posts as $post) {
            $row = self::postToDisplayRow($post, 0, $db);
            $row['result_type'] = 'post';
            $snippet = (string) ($post->post_subject ?? '');
            if ($snippet === '') {
                $raw = (string) ($post->post_content ?? '');
                if (function_exists('mb_substr')) {
                    $snippet = mb_substr($raw, 0, 160);
                } else {
                    $snippet = substr($raw, 0, 160);
                }
            }
            $row['snippet'] = $snippet;
            $topic = self::getTopic((int) $post->topic_id, $db);
            $row['topic_title'] = $topic !== null ? (string) $topic->topic_title : '';
            $row['url'] = $topic !== null
                ? self::topicUrl($topic) . '#post-' . (int) $post->post_id
                : '';
            $results[] = $row;
        }

        // Sort combined results by date desc when type=all.
        if ($type === 'all' && count($results) > 1) {
            usort($results, static function (array $a, array $b): int {
                $da = (string) ($a['last_date'] ?? $a['date'] ?? '');
                $db_ = (string) ($b['last_date'] ?? $b['date'] ?? '');

                return $db_ <=> $da;
            });
        }

        $total = count($results);
        if ($type === 'all') {
            $perPage = max(0, (int) ($args['per_page'] ?? 0));
            $page = max(1, (int) ($args['page'] ?? 1));
            if ($perPage > 0) {
                $offset = ($page - 1) * $perPage;
                $results = array_slice($results, $offset, $perPage);
            }
        } else {
            $total = self::countSearch($term, $args, $db);
        }

        return [
            'query' => $term,
            'total' => $total,
            'topics' => $topics,
            'posts' => $posts,
            'results' => $results,
        ];
    }

    /**
     * Search topics by title (and optionally first-post content via join).
     *
     * @param array<string, mixed> $args forum_id, approved_only, include_deleted, per_page, page,
     *                                   check_permissions, user_id (ACL filter via {@see forumIdsAllowedForSearch()})
     *
     * @return list<object>
     */
    public static function searchTopics(string $query, array $args = [], ?AP_DB $db = null): array
    {
        $term = trim($query);
        if ($term === '') {
            return [];
        }

        $db = self::resolveDb($db);
        $allowedForums = self::forumIdsAllowedForSearch($args, $db);
        if (is_array($allowedForums) && $allowedForums === []) {
            return [];
        }

        $topics = $db->quoteIdentifier($db->table('topics'));
        $posts = $db->quoteIdentifier($db->table('forum_posts'));
        $like = '%' . self::escapeLike($term) . '%';

        $sql = 'SELECT DISTINCT t.* FROM ' . $topics . ' t'
            . ' LEFT JOIN ' . $posts . ' p ON p.' . $db->quoteIdentifier('topic_id')
            . ' = t.' . $db->quoteIdentifier('topic_id')
            . ' AND p.' . $db->quoteIdentifier('post_id')
            . ' = t.' . $db->quoteIdentifier('first_post_id')
            . ' WHERE (t.' . $db->quoteIdentifier('topic_title') . ' LIKE ?'
            . ' OR p.' . $db->quoteIdentifier('post_content') . ' LIKE ?)';
        $params = [$like, $like];

        if (isset($args['forum_id']) && (int) $args['forum_id'] > 0) {
            $forumId = (int) $args['forum_id'];
            if (is_array($allowedForums) && !in_array($forumId, $allowedForums, true)) {
                return [];
            }
            $sql .= ' AND t.' . $db->quoteIdentifier('forum_id') . ' = ?';
            $params[] = $forumId;
        } elseif (is_array($allowedForums)) {
            $placeholders = implode(', ', array_fill(0, count($allowedForums), '?'));
            $sql .= ' AND t.' . $db->quoteIdentifier('forum_id') . ' IN (' . $placeholders . ')';
            foreach ($allowedForums as $fid) {
                $params[] = $fid;
            }
        }

        if (empty($args['include_deleted'])) {
            $sql .= ' AND t.' . $db->quoteIdentifier('topic_status') . ' != ?';
            $params[] = self::TOPIC_STATUS_DELETED;
        }

        $approvedOnly = !array_key_exists('approved_only', $args) || !empty($args['approved_only']);
        if ($approvedOnly) {
            $sql .= ' AND t.' . $db->quoteIdentifier('topic_approved') . ' = 1';
        }

        $order = strtoupper((string) ($args['order'] ?? 'DESC'));
        if ($order !== 'ASC') {
            $order = 'DESC';
        }
        $sql .= ' ORDER BY t.' . $db->quoteIdentifier('topic_last_post_time') . ' ' . $order
            . ', t.' . $db->quoteIdentifier('topic_id') . ' ' . $order;

        // When type=all, pagination is applied after merge; skip here if skip_limit set.
        if (empty($args['skip_limit'])) {
            $perPage = max(0, (int) ($args['per_page'] ?? 0));
            $page = max(1, (int) ($args['page'] ?? 1));
            if ($perPage > 0) {
                $offset = ($page - 1) * $perPage;
                $sql .= ' LIMIT ' . $perPage . ' OFFSET ' . $offset;
            }
        }

        $rows = $db->getResults($sql, $params);
        $out = [];
        foreach ($rows as $row) {
            $out[] = self::normalizeTopicRow($row);
        }

        return $out;
    }

    /**
     * Search forum posts by subject/content.
     *
     * @param array<string, mixed> $args forum_id, topic_id, approved_only, per_page, page,
     *                                   check_permissions, user_id
     *
     * @return list<object>
     */
    public static function searchPosts(string $query, array $args = [], ?AP_DB $db = null): array
    {
        $term = trim($query);
        if ($term === '') {
            return [];
        }

        $db = self::resolveDb($db);
        $allowedForums = self::forumIdsAllowedForSearch($args, $db);
        if (is_array($allowedForums) && $allowedForums === []) {
            return [];
        }

        $table = $db->quoteIdentifier($db->table('forum_posts'));
        $topics = $db->quoteIdentifier($db->table('topics'));
        $like = '%' . self::escapeLike($term) . '%';

        $sql = 'SELECT p.* FROM ' . $table . ' p'
            . ' INNER JOIN ' . $topics . ' t ON t.' . $db->quoteIdentifier('topic_id')
            . ' = p.' . $db->quoteIdentifier('topic_id')
            . ' WHERE (p.' . $db->quoteIdentifier('post_subject') . ' LIKE ?'
            . ' OR p.' . $db->quoteIdentifier('post_content') . ' LIKE ?)';
        $params = [$like, $like];

        if (isset($args['forum_id']) && (int) $args['forum_id'] > 0) {
            $forumId = (int) $args['forum_id'];
            if (is_array($allowedForums) && !in_array($forumId, $allowedForums, true)) {
                return [];
            }
            $sql .= ' AND p.' . $db->quoteIdentifier('forum_id') . ' = ?';
            $params[] = $forumId;
        } elseif (is_array($allowedForums)) {
            $placeholders = implode(', ', array_fill(0, count($allowedForums), '?'));
            $sql .= ' AND p.' . $db->quoteIdentifier('forum_id') . ' IN (' . $placeholders . ')';
            foreach ($allowedForums as $fid) {
                $params[] = $fid;
            }
        }
        if (isset($args['topic_id']) && (int) $args['topic_id'] > 0) {
            $sql .= ' AND p.' . $db->quoteIdentifier('topic_id') . ' = ?';
            $params[] = (int) $args['topic_id'];
        }

        if (empty($args['include_deleted'])) {
            $sql .= ' AND t.' . $db->quoteIdentifier('topic_status') . ' != ?';
            $params[] = self::TOPIC_STATUS_DELETED;
        }

        $approvedOnly = !array_key_exists('approved_only', $args) || !empty($args['approved_only']);
        if ($approvedOnly) {
            $sql .= ' AND p.' . $db->quoteIdentifier('post_approved') . ' = 1'
                . ' AND t.' . $db->quoteIdentifier('topic_approved') . ' = 1';
        }

        $order = strtoupper((string) ($args['order'] ?? 'DESC'));
        if ($order !== 'ASC') {
            $order = 'DESC';
        }
        $sql .= ' ORDER BY p.' . $db->quoteIdentifier('post_time') . ' ' . $order
            . ', p.' . $db->quoteIdentifier('post_id') . ' ' . $order;

        if (empty($args['skip_limit'])) {
            $perPage = max(0, (int) ($args['per_page'] ?? 0));
            $page = max(1, (int) ($args['page'] ?? 1));
            if ($perPage > 0) {
                $offset = ($page - 1) * $perPage;
                $sql .= ' LIMIT ' . $perPage . ' OFFSET ' . $offset;
            }
        }

        $rows = $db->getResults($sql, $params);
        $out = [];
        foreach ($rows as $row) {
            $out[] = self::normalizePostRow($row);
        }

        return $out;
    }

    /**
     * Count search hits (topics + posts depending on type).
     *
     * @param array<string, mixed> $args type, forum_id, approved_only, include_deleted
     */
    public static function countSearch(string $query, array $args = [], ?AP_DB $db = null): int
    {
        $term = trim($query);
        if ($term === '') {
            return 0;
        }

        $type = strtolower(trim((string) ($args['type'] ?? 'all')));
        $countArgs = $args;
        $countArgs['skip_limit'] = true;
        unset($countArgs['per_page'], $countArgs['page']);

        $total = 0;
        if ($type === 'topics' || $type === 'all') {
            $total += count(self::searchTopics($term, $countArgs, $db));
        }
        if ($type === 'posts' || $type === 'all') {
            $total += count(self::searchPosts($term, $countArgs, $db));
        }

        return $total;
    }

    /**
     * Public search URL (pretty or plain).
     */
    public static function searchUrl(string $query = '', ?AP_DB $db = null): string
    {
        $home = self::homeBaseUrl();
        $pretty = class_exists('AP_Rewrite', false) && AP_Rewrite::usingPermalinks();
        $q = trim($query);
        if ($pretty) {
            if ($q !== '') {
                return $home . 'forums/search/' . rawurlencode($q) . '/';
            }

            return $home . 'forums/search/';
        }
        $base = $home . '?ap_forum_view=search';
        if ($q !== '') {
            return $base . '&forum_s=' . rawurlencode($q);
        }

        return $base;
    }

    // -------------------------------------------------------------------------
    // Approval queues
    // -------------------------------------------------------------------------

    /**
     * List topics awaiting approval (topic_approved = 0, not deleted).
     *
     * @param array<string, mixed> $args forum_id, poster_id, per_page, page
     *
     * @return list<object>
     */
    public static function getPendingTopics(array $args = [], ?AP_DB $db = null): array
    {
        $db = self::resolveDb($db);
        $table = $db->quoteIdentifier($db->table('topics'));
        $sql = 'SELECT * FROM ' . $table
            . ' WHERE ' . $db->quoteIdentifier('topic_approved') . ' = 0'
            . ' AND ' . $db->quoteIdentifier('topic_status') . ' != ?';
        $params = [self::TOPIC_STATUS_DELETED];

        if (isset($args['forum_id']) && (int) $args['forum_id'] > 0) {
            $sql .= ' AND ' . $db->quoteIdentifier('forum_id') . ' = ?';
            $params[] = (int) $args['forum_id'];
        }
        if (isset($args['poster_id']) && (int) $args['poster_id'] > 0) {
            $sql .= ' AND ' . $db->quoteIdentifier('topic_poster') . ' = ?';
            $params[] = (int) $args['poster_id'];
        }

        $sql .= ' ORDER BY ' . $db->quoteIdentifier('topic_time') . ' ASC, '
            . $db->quoteIdentifier('topic_id') . ' ASC';

        $perPage = max(0, (int) ($args['per_page'] ?? 0));
        $page = max(1, (int) ($args['page'] ?? 1));
        if ($perPage > 0) {
            $offset = ($page - 1) * $perPage;
            $sql .= ' LIMIT ' . $perPage . ' OFFSET ' . $offset;
        }

        $rows = $db->getResults($sql, $params);
        $out = [];
        foreach ($rows as $row) {
            $out[] = self::normalizeTopicRow($row);
        }

        return $out;
    }

    /**
     * Count pending topics.
     *
     * @param array<string, mixed> $args forum_id, poster_id
     */
    public static function countPendingTopics(array $args = [], ?AP_DB $db = null): int
    {
        $db = self::resolveDb($db);
        $table = $db->quoteIdentifier($db->table('topics'));
        $sql = 'SELECT COUNT(*) FROM ' . $table
            . ' WHERE ' . $db->quoteIdentifier('topic_approved') . ' = 0'
            . ' AND ' . $db->quoteIdentifier('topic_status') . ' != ?';
        $params = [self::TOPIC_STATUS_DELETED];

        if (isset($args['forum_id']) && (int) $args['forum_id'] > 0) {
            $sql .= ' AND ' . $db->quoteIdentifier('forum_id') . ' = ?';
            $params[] = (int) $args['forum_id'];
        }
        if (isset($args['poster_id']) && (int) $args['poster_id'] > 0) {
            $sql .= ' AND ' . $db->quoteIdentifier('topic_poster') . ' = ?';
            $params[] = (int) $args['poster_id'];
        }

        return (int) $db->getVar($sql, $params);
    }

    /**
     * List posts awaiting approval (post_approved = 0) that are not first posts of
     * already-pending topics when exclude_first_of_pending is true (default).
     *
     * @param array<string, mixed> $args forum_id, topic_id, poster_id, per_page, page,
     *                                   exclude_first_of_pending (bool, default true)
     *
     * @return list<object>
     */
    public static function getPendingPosts(array $args = [], ?AP_DB $db = null): array
    {
        $db = self::resolveDb($db);
        $posts = $db->quoteIdentifier($db->table('forum_posts'));
        $topics = $db->quoteIdentifier($db->table('topics'));

        $sql = 'SELECT p.* FROM ' . $posts . ' p'
            . ' INNER JOIN ' . $topics . ' t ON t.' . $db->quoteIdentifier('topic_id')
            . ' = p.' . $db->quoteIdentifier('topic_id')
            . ' WHERE p.' . $db->quoteIdentifier('post_approved') . ' = 0'
            . ' AND t.' . $db->quoteIdentifier('topic_status') . ' != ?';
        $params = [self::TOPIC_STATUS_DELETED];

        $excludeFirst = !array_key_exists('exclude_first_of_pending', $args)
            || !empty($args['exclude_first_of_pending']);
        if ($excludeFirst) {
            // Prefer listing replies; first posts of unapproved topics appear via getPendingTopics.
            $sql .= ' AND NOT (t.' . $db->quoteIdentifier('topic_approved') . ' = 0'
                . ' AND p.' . $db->quoteIdentifier('post_id')
                . ' = t.' . $db->quoteIdentifier('first_post_id') . ')';
        }

        if (isset($args['forum_id']) && (int) $args['forum_id'] > 0) {
            $sql .= ' AND p.' . $db->quoteIdentifier('forum_id') . ' = ?';
            $params[] = (int) $args['forum_id'];
        }
        if (isset($args['topic_id']) && (int) $args['topic_id'] > 0) {
            $sql .= ' AND p.' . $db->quoteIdentifier('topic_id') . ' = ?';
            $params[] = (int) $args['topic_id'];
        }
        if (isset($args['poster_id']) && (int) $args['poster_id'] > 0) {
            $sql .= ' AND p.' . $db->quoteIdentifier('poster_id') . ' = ?';
            $params[] = (int) $args['poster_id'];
        }

        $sql .= ' ORDER BY p.' . $db->quoteIdentifier('post_time') . ' ASC, '
            . 'p.' . $db->quoteIdentifier('post_id') . ' ASC';

        $perPage = max(0, (int) ($args['per_page'] ?? 0));
        $page = max(1, (int) ($args['page'] ?? 1));
        if ($perPage > 0) {
            $offset = ($page - 1) * $perPage;
            $sql .= ' LIMIT ' . $perPage . ' OFFSET ' . $offset;
        }

        $rows = $db->getResults($sql, $params);
        $out = [];
        foreach ($rows as $row) {
            $out[] = self::normalizePostRow($row);
        }

        return $out;
    }

    /**
     * Count pending posts (same filters as {@see getPendingPosts()}).
     *
     * @param array<string, mixed> $args
     */
    public static function countPendingPosts(array $args = [], ?AP_DB $db = null): int
    {
        $args['per_page'] = 0;
        unset($args['page']);

        return count(self::getPendingPosts($args, $db));
    }

    // -------------------------------------------------------------------------
    // Slug helpers
    // -------------------------------------------------------------------------

    public static function sanitizeSlug(string $title): string
    {
        // Prefer AP_Post when loaded (shared transliteration rules).
        // Do not call ap_sanitize_title() — it requires AP_Post and would fatally
        // error when only the forum layer is included (e.g. focused unit tests).
        if (class_exists('AP_Post', false) && method_exists('AP_Post', 'sanitizeSlug')) {
            return AP_Post::sanitizeSlug($title);
        }

        $title = trim($title);
        if ($title === '') {
            return '';
        }

        if (function_exists('mb_strtolower')) {
            $title = mb_strtolower($title, 'UTF-8');
        } else {
            $title = strtolower($title);
        }

        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title);
            if (is_string($converted) && $converted !== '') {
                $title = strtolower($converted);
            }
        }

        $title = preg_replace('/[^a-z0-9]+/', '-', $title) ?? '';
        $title = trim($title, '-');
        if (strlen($title) > 200) {
            $title = rtrim(substr($title, 0, 200), '-');
        }

        return $title;
    }

    public static function uniqueForumSlug(
        string $slug,
        int $excludeId = 0,
        int $parentId = 0,
        ?AP_DB $db = null
    ): string {
        $slug = self::sanitizeSlug($slug);
        if ($slug === '') {
            $slug = 'forum';
        }

        $db = self::resolveDb($db);
        $table = $db->quoteIdentifier($db->table('forums'));
        $base = $slug;
        $suffix = 2;
        while (true) {
            $sql = 'SELECT ' . $db->quoteIdentifier('forum_id') . ' FROM ' . $table
                . ' WHERE ' . $db->quoteIdentifier('forum_slug') . ' = ?'
                . ' AND ' . $db->quoteIdentifier('parent_id') . ' = ?';
            $params = [$slug, $parentId];
            if ($excludeId > 0) {
                $sql .= ' AND ' . $db->quoteIdentifier('forum_id') . ' != ?';
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
                return $base . '-' . bin2hex(random_bytes(3));
            }
        }
    }

    public static function uniqueTopicSlug(
        string $slug,
        int $forumId,
        int $excludeId = 0,
        ?AP_DB $db = null
    ): string {
        $slug = self::sanitizeSlug($slug);
        if ($slug === '') {
            $slug = 'topic';
        }

        $db = self::resolveDb($db);
        $table = $db->quoteIdentifier($db->table('topics'));
        $base = $slug;
        $suffix = 2;
        while (true) {
            $sql = 'SELECT ' . $db->quoteIdentifier('topic_id') . ' FROM ' . $table
                . ' WHERE ' . $db->quoteIdentifier('topic_slug') . ' = ?'
                . ' AND ' . $db->quoteIdentifier('forum_id') . ' = ?';
            $params = [$slug, $forumId];
            if ($excludeId > 0) {
                $sql .= ' AND ' . $db->quoteIdentifier('topic_id') . ' != ?';
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
                return $base . '-' . bin2hex(random_bytes(3));
            }
        }
    }

    // -------------------------------------------------------------------------
    // Display row helpers (theme layer)
    // -------------------------------------------------------------------------

    /**
     * Theme-friendly board-index forum row (SPEC §A2/A4).
     *
     * Payload keys:
     * - icon_type: standard | locked | link (pair with is_unread for variants)
     * - is_unread: bool (false until annotateForums / getIndexData)
     * - is_closed / is_locked: closed forum (no new topics; locked icon/badge)
     * - is_empty: no topics and no posts (themes show “No posts” last-post placeholders)
     * - topics / topic_count: denormalized approved topic count
     * - posts / post_count: denormalized **opening posts + replies** (not replies-only;
     *   same definition as board footer Total Posts — see class docblock)
     * - last_post: null when empty, else title, author, time, url (+ ids)
     *
     * @param array{
     *   topics?: array<int, object>,
     *   authors?: array<int, string>
     * } $preload Optional batch maps from {@see buildForumRowPreload()} (avoids N+1).
     *
     * @return array<string, mixed>
     */
    public static function forumToDisplayRow(object $forum, ?AP_DB $db = null, array $preload = []): array
    {
        $id = (int) $forum->forum_id;
        $url = self::forumUrl($forum);
        $topicCount = (int) ($forum->topic_count ?? 0);
        // post_count = approved OP + replies in this forum (not replies-only).
        $postCount = (int) ($forum->post_count ?? 0);
        $last = self::buildForumLastPostPayload($forum, $db, $preload);
        $isClosed = self::isForumClosed($forum);
        // Empty = no visible activity (counts zero; last_post is also null).
        $isEmpty = $topicCount === 0 && $postCount === 0;

        return [
            'id' => $id,
            'name' => (string) $forum->forum_name,
            'slug' => (string) $forum->forum_slug,
            'description' => (string) ($forum->forum_desc ?? ''),
            'url' => $url,
            // Counts (both aliases for theme / API convenience).
            'topics' => $topicCount,
            'topic_count' => $topicCount,
            'posts' => $postCount,
            'post_count' => $postCount,
            'type' => (string) $forum->forum_type,
            'status' => (string) $forum->forum_status,
            'icon_type' => self::forumIconType($forum),
            'last_post' => $last,
            // Closed forum affordances (SPEC A2 Locked). is_locked aliases is_closed.
            'is_closed' => $isClosed,
            'is_locked' => $isClosed,
            // Empty forum: themes render “No posts” / “—” last-post placeholders.
            'is_empty' => $isEmpty,
            // Default until annotateForums / getIndexData sets per-user state.
            // Guests and disabled tracking stay false (neutral / not unread).
            'is_unread' => false,
        ];
    }

    /**
     * Last-post block for a forum row: title, author, time, url (SPEC §A4 col 5).
     *
     * Empty forums return null so themes can render "No posts" / "—".
     *
     * @param array{
     *   topics?: array<int, object>,
     *   authors?: array<int, string>
     * } $preload
     *
     * @return array{
     *   title: string,
     *   author: string,
     *   time: string,
     *   date: string,
     *   url: string,
     *   post_id: int,
     *   topic_id: int,
     *   author_id: int
     * }|null
     */
    public static function buildForumLastPostPayload(
        object $forum,
        ?AP_DB $db = null,
        array $preload = []
    ): ?array {
        $postId = (int) ($forum->last_post_id ?? 0);
        if ($postId < 1) {
            return null;
        }

        $topicId = (int) ($forum->last_topic_id ?? 0);
        $authorId = (int) ($forum->last_poster_id ?? 0);
        $time = (string) ($forum->last_post_time ?? '');
        if ($time === self::EMPTY_DATETIME) {
            $time = '';
        }

        $title = '';
        $topic = null;
        if ($topicId > 0) {
            $topicMap = is_array($preload['topics'] ?? null) ? $preload['topics'] : [];
            if (isset($topicMap[$topicId]) && is_object($topicMap[$topicId])) {
                $topic = $topicMap[$topicId];
            } else {
                try {
                    $topic = self::getTopic($topicId, $db);
                } catch (Throwable) {
                    $topic = null;
                }
            }
            if ($topic !== null) {
                $title = (string) ($topic->topic_title ?? '');
            }
        }

        $author = '';
        $authorMap = is_array($preload['authors'] ?? null) ? $preload['authors'] : [];
        if ($authorId > 0 && isset($authorMap[$authorId]) && is_string($authorMap[$authorId])) {
            $author = $authorMap[$authorId];
        } elseif ($authorId > 0 && class_exists('AP_User', false)) {
            try {
                $user = AP_User::getById($authorId, $db);
                if ($user !== null) {
                    $author = (string) ($user->display_name ?? $user->user_login ?? '');
                }
            } catch (Throwable) {
                // User layer optional in isolated tests.
            }
        }

        $lastUrl = '';
        if ($topic !== null) {
            $lastUrl = self::postUrl($topic, $postId);
        } elseif ($topicId > 0) {
            // Topic row missing but id known — still produce a usable topic URL.
            $lastUrl = self::topicUrl($topicId);
            if ($postId > 0) {
                $lastUrl .= '#post-' . $postId;
            }
        }

        return [
            'title' => $title,
            'author' => $author,
            'time' => $time,
            // Alias used by older theme markup (agora forum.php).
            'date' => $time,
            'url' => $lastUrl,
            'post_id' => $postId,
            'topic_id' => $topicId,
            'author_id' => $authorId,
        ];
    }

    /**
     * Permalink to a forum post (topic URL + #post-{id} anchor).
     */
    public static function postUrl(object|int $topic, int $postId): string
    {
        $base = self::topicUrl($topic);
        if ($postId < 1) {
            return $base;
        }

        return $base . '#post-' . $postId;
    }

    /**
     * Batch-load last topics + author display names for forum row building.
     *
     * @param list<object> $forums
     *
     * @return array{topics: array<int, object>, authors: array<int, string>}
     */
    public static function buildForumRowPreload(array $forums, ?AP_DB $db = null): array
    {
        $topicIds = [];
        $authorIds = [];
        foreach ($forums as $forum) {
            if (!is_object($forum)) {
                continue;
            }
            $tid = (int) ($forum->last_topic_id ?? 0);
            $aid = (int) ($forum->last_poster_id ?? 0);
            if ($tid > 0) {
                $topicIds[$tid] = $tid;
            }
            if ($aid > 0) {
                $authorIds[$aid] = $aid;
            }
        }

        return [
            'topics' => self::getTopicsByIds(array_values($topicIds), $db),
            'authors' => self::getAuthorDisplayNames(array_values($authorIds), $db),
        ];
    }

    /**
     * @param list<int> $topicIds
     *
     * @return array<int, object>
     */
    public static function getTopicsByIds(array $topicIds, ?AP_DB $db = null): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn ($id): int => (int) $id, $topicIds),
            static fn (int $id): bool => $id > 0
        )));
        if ($ids === []) {
            return [];
        }

        try {
            $db = self::resolveDb($db);
        } catch (Throwable) {
            return [];
        }

        $table = $db->quoteIdentifier($db->table('topics'));
        $ph = implode(', ', array_fill(0, count($ids), '?'));
        try {
            $rows = $db->getResults(
                'SELECT * FROM ' . $table
                . ' WHERE ' . $db->quoteIdentifier('topic_id') . ' IN (' . $ph . ')',
                $ids
            );
        } catch (Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $normalized = self::normalizeTopicRow($row);
            $out[(int) $normalized->topic_id] = $normalized;
        }

        return $out;
    }

    /**
     * Batch-load display names for last-post authors (one SELECT; no N+1).
     *
     * @param list<int> $userIds
     *
     * @return array<int, string>
     */
    public static function getAuthorDisplayNames(array $userIds, ?AP_DB $db = null): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn ($id): int => (int) $id, $userIds),
            static fn (int $id): bool => $id > 0
        )));
        $out = [];
        if ($ids === []) {
            return $out;
        }

        try {
            $db = self::resolveDb($db);
        } catch (Throwable) {
            return $out;
        }

        // Prefer AP_User batch when available.
        if (class_exists('AP_User', false) && method_exists('AP_User', 'getDisplayNamesByIds')) {
            try {
                /** @var array<int, string> $names */
                $names = AP_User::getDisplayNamesByIds($ids, $db);

                return $names;
            } catch (Throwable) {
                // Fall through to direct SQL.
            }
        }

        try {
            $table = $db->quoteIdentifier($db->table('users'));
            $ph = implode(', ', array_fill(0, count($ids), '?'));
            $rows = $db->getResults(
                'SELECT ' . $db->quoteIdentifier('ID') . ', '
                . $db->quoteIdentifier('display_name') . ', '
                . $db->quoteIdentifier('user_login')
                . ' FROM ' . $table
                . ' WHERE ' . $db->quoteIdentifier('ID') . ' IN (' . $ph . ')',
                $ids
            );
        } catch (Throwable) {
            return $out;
        }

        foreach ($rows as $row) {
            $uid = (int) (is_object($row) ? ($row->ID ?? $row->id ?? 0) : ($row['ID'] ?? $row['id'] ?? 0));
            if ($uid < 1) {
                continue;
            }
            $display = (string) (is_object($row) ? ($row->display_name ?? '') : ($row['display_name'] ?? ''));
            $login = (string) (is_object($row) ? ($row->user_login ?? '') : ($row['user_login'] ?? ''));
            $out[$uid] = $display !== '' ? $display : $login;
        }

        return $out;
    }

    /**
     * Theme-friendly topic list row (SPEC §A2/A4).
     *
     * Count keys (consistent post definition — see class docblock):
     * - replies: `topics.reply_count` (replies only; excludes opening post)
     * - posts / post_count: opening post + replies (`reply_count + 1`) — same
     *   meaning as forum-row and board-footer “Posts” columns
     *
     * @return array<string, mixed>
     */
    public static function topicToDisplayRow(object $topic, ?AP_DB $db = null): array
    {
        $type = self::normalizeTopicType((string) $topic->topic_type);
        $status = (string) $topic->topic_status;
        $author = '';
        if (class_exists('AP_User', false) && (int) $topic->topic_poster > 0) {
            try {
                $user = AP_User::getById((int) $topic->topic_poster, $db);
                if ($user !== null) {
                    $author = (string) ($user->display_name ?? $user->user_login ?? '');
                }
            } catch (Throwable) {
                // optional
            }
        }
        $lastAuthor = '';
        if (class_exists('AP_User', false) && (int) $topic->last_poster_id > 0) {
            try {
                $user = AP_User::getById((int) $topic->last_poster_id, $db);
                if ($user !== null) {
                    $lastAuthor = (string) ($user->display_name ?? $user->user_login ?? '');
                }
            } catch (Throwable) {
                // optional
            }
        }

        $lastPostId = (int) ($topic->last_post_id ?? 0);
        $topicUrl = self::topicUrl($topic);
        $lastUrl = $lastPostId > 0 ? self::postUrl($topic, $lastPostId) : $topicUrl;
        $lastTime = (string) $topic->topic_last_post_time;
        $replyCount = max(0, (int) $topic->reply_count);
        // Posts = OP + replies (not replies-only). Matches forums.post_count / board footer.
        $postCount = $replyCount + 1;

        return [
            'id' => (int) $topic->topic_id,
            'forum_id' => (int) $topic->forum_id,
            'title' => (string) $topic->topic_title,
            'slug' => (string) $topic->topic_slug,
            'url' => $topicUrl,
            'author' => $author,
            'author_id' => (int) $topic->topic_poster,
            'replies' => $replyCount,
            // SPEC §C / board “Posts”: opening post + replies.
            'posts' => $postCount,
            'post_count' => $postCount,
            'views' => (int) $topic->topic_views,
            'sticky' => $type === self::TOPIC_TYPE_STICKY,
            'announcement' => $type === self::TOPIC_TYPE_ANNOUNCEMENT,
            'rules' => $type === self::TOPIC_TYPE_RULES,
            'locked' => $status === self::TOPIC_STATUS_LOCKED,
            'type' => $type,
            'status' => $status,
            'icon_type' => self::topicIconType($topic),
            'last_date' => $lastTime,
            'last_author' => $lastAuthor,
            'last_poster_id' => (int) $topic->last_poster_id,
            // Nested last_post for parity with forum rows (title / author / time / url).
            'last_post' => $lastPostId > 0 || $lastTime !== ''
                ? [
                    'title' => (string) $topic->topic_title,
                    'author' => $lastAuthor,
                    'time' => $lastTime,
                    'date' => $lastTime,
                    'url' => $lastUrl,
                    'post_id' => $lastPostId,
                    'topic_id' => (int) $topic->topic_id,
                    'author_id' => (int) $topic->last_poster_id,
                ]
                : null,
            // Default until annotateTopics / getTopicsDisplayData sets per-user state.
            'is_unread' => false,
        ];
    }

    /**
     * Whether forum signatures are enabled site-wide (Settings → Forums).
     * Default on when the option is missing (fresh installs / older DBs).
     */
    public static function signaturesEnabled(?AP_DB $db = null): bool
    {
        try {
            if (function_exists('ap_get_option')) {
                return (string) ap_get_option('forum_signatures_enabled', '1', $db) !== '0';
            }
            if (class_exists('AP_Options', false)) {
                return (string) AP_Options::get('forum_signatures_enabled', '1', $db) !== '0';
            }
        } catch (Throwable) {
            // fall through
        }

        return true;
    }

    /**
     * Safe HTML for a forum signature (same pipeline as post bodies; empty in → empty out).
     */
    public static function formatSignatureHtml(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }

        return self::displayHtml($raw, '');
    }

    /**
     * @param array{
     *   forum_posts?: int,
     *   forum_likes_given?: int,
     *   forum_likes_received?: int,
     *   location?: string,
     *   signature?: string
     * }|null $authorStatsPreload Optional batch-loaded author panel stats / location /
     *                              signature (avoids per-post meta queries).
     *
     * @return array<string, mixed>
     */
    public static function postToDisplayRow(
        object $post,
        int $number = 0,
        ?AP_DB $db = null,
        ?array $authorStatsPreload = null
    ): array {
        $author = 'Guest';
        $authorUrl = '';
        $role = '';
        $roleSlug = '';
        $avatarHtml = '';
        $joined = '';
        $location = '';
        $signature = '';
        $posterId = (int) ($post->poster_id ?? 0);
        if (class_exists('AP_User', false) && $posterId > 0) {
            try {
                $user = AP_User::getById($posterId, $db);
                if ($user !== null) {
                    $author = (string) ($user->display_name ?? $user->user_login ?? 'Guest');
                    $login = (string) ($user->user_login ?? '');
                    $nicename = (string) ($user->user_nicename ?? '');
                    $authorKey = $nicename !== '' ? $nicename : $login;
                    $joined = trim((string) ($user->user_registered ?? ''));
                    if ($authorKey !== '' && class_exists('AP_Rewrite', false)) {
                        try {
                            $authorUrl = (string) AP_Rewrite::getAuthorLink($authorKey, $db);
                        } catch (Throwable) {
                            $authorUrl = '';
                        }
                    }
                    if (function_exists('ap_get_user_role')) {
                        try {
                            $roleSlug = (string) ap_get_user_role($posterId, $db);
                        } catch (Throwable) {
                            $roleSlug = '';
                        }
                    } elseif (class_exists('AP_Roles', false)) {
                        try {
                            $roleSlug = (string) AP_Roles::getUserRole($posterId, $db);
                        } catch (Throwable) {
                            $roleSlug = '';
                        }
                    }
                    if ($roleSlug !== '' && class_exists('AP_Roles', false)) {
                        $def = AP_Roles::getRole($roleSlug, $db);
                        $role = is_array($def)
                            ? (string) ($def['name'] ?? $roleSlug)
                            : $roleSlug;
                    }
                    if (function_exists('ap_get_avatar')) {
                        try {
                            $avatarHtml = (string) ap_get_avatar($posterId, 96, '', $author, [], $db);
                        } catch (Throwable) {
                            $avatarHtml = '';
                        }
                    }
                }
            } catch (Throwable) {
                // optional
            }
        }

        $raw = (string) $post->post_content;
        $filtered = (string) ($post->post_content_filtered ?? '');
        $contentHtml = self::displayHtml($raw, $filtered);

        $attachments = [];
        if (class_exists('AP_Forum_Attachment', false)) {
            try {
                $attachments = AP_Forum_Attachment::getDisplayForPost((int) $post->post_id, $db);
            } catch (Throwable) {
                $attachments = [];
            }
        }

        $likeCount = (int) ($post->like_count ?? 0);
        // Author pane: post count + likes given + likes received (SPEC B2).
        $authorStats = [
            'forum_posts' => 0,
            'forum_likes_given' => 0,
            'forum_likes_received' => 0,
        ];
        if ($authorStatsPreload !== null) {
            $authorStats = [
                'forum_posts' => (int) ($authorStatsPreload['forum_posts'] ?? 0),
                'forum_likes_given' => (int) ($authorStatsPreload['forum_likes_given'] ?? 0),
                'forum_likes_received' => (int) ($authorStatsPreload['forum_likes_received'] ?? 0),
            ];
            if (array_key_exists('location', $authorStatsPreload)) {
                $location = trim((string) $authorStatsPreload['location']);
            }
            if (array_key_exists('signature', $authorStatsPreload)) {
                $signature = trim((string) $authorStatsPreload['signature']);
            }
        } elseif (class_exists('AP_Forum_Stats', false) && $posterId > 0) {
            $authorStats = AP_Forum_Stats::getAuthorPanelStats($posterId, $db);
        }
        // Location: prefer batch preload; otherwise single meta read (omit empty).
        if ($location === '' && $posterId > 0 && class_exists('AP_User', false)
            && ($authorStatsPreload === null || !array_key_exists('location', $authorStatsPreload))
        ) {
            try {
                $location = trim((string) (AP_User::getMeta($posterId, 'location', $db) ?? ''));
            } catch (Throwable) {
                $location = '';
            }
        }
        // Signature: only when site-wide enabled; empty when disabled or user has none (SPEC B2).
        $sigsEnabled = self::signaturesEnabled($db);
        if (!$sigsEnabled) {
            $signature = '';
        } elseif ($signature === '' && $posterId > 0 && class_exists('AP_User', false)
            && ($authorStatsPreload === null || !array_key_exists('signature', $authorStatsPreload))
        ) {
            try {
                $signature = trim((string) (AP_User::getMeta($posterId, 'signature', $db) ?? ''));
            } catch (Throwable) {
                $signature = '';
            }
        }
        $signatureHtml = $signature !== '' ? self::formatSignatureHtml($signature) : '';

        return [
            'id' => (int) $post->post_id,
            'topic_id' => (int) $post->topic_id,
            'forum_id' => (int) $post->forum_id,
            'author' => $author,
            'author_id' => $posterId,
            'author_url' => $authorUrl,
            'role' => $role,
            'role_slug' => $roleSlug,
            'avatar_html' => $avatarHtml,
            // SPEC B2 author pane: registration date + optional location.
            'joined' => $joined,
            'location' => $location,
            // SPEC B2 post footer: signature when enabled + present.
            'signature' => $signature,
            'signature_html' => $signatureHtml,
            'date' => (string) $post->post_time,
            'content' => $raw,
            'content_html' => $contentHtml,
            'subject' => (string) $post->post_subject,
            'number' => $number > 0 ? $number : (int) $post->post_position,
            'position' => (int) $post->post_position,
            'approved' => (int) $post->post_approved === 1,
            'edit_count' => (int) $post->post_edit_count,
            'edit_time' => (string) ($post->post_edit_time ?? ''),
            'like_count' => max(0, $likeCount),
            'liked_by_me' => false,
            'can_like' => false,
            'can_quote' => false,
            'can_edit' => false,
            'can_delete' => false,
            'can_moderate' => false,
            'author_stats' => $authorStats,
            'attachments' => $attachments,
        ];
    }

    /**
     * Build or accept pre-rendered HTML for storage in post_content_filtered.
     *
     * When $provided is null, content is formatted with the default auto pipeline
     * (BBCode + Markdown + limited safe HTML). An explicit empty string is kept.
     */
    public static function filteredContent(string $raw, ?string $provided = null): string
    {
        if ($provided !== null) {
            return $provided;
        }
        if (!class_exists('AP_Content_Format', false)) {
            return '';
        }

        return AP_Content_Format::format($raw, ['context' => 'forum', 'mode' => 'auto']);
    }

    /**
     * Safe HTML for theme display: prefer cached filtered body, re-kses, or format raw.
     */
    public static function displayHtml(string $raw, string $filtered = ''): string
    {
        if (!class_exists('AP_Content_Format', false)) {
            if (function_exists('ap_esc_html')) {
                return nl2br(ap_esc_html($raw), false);
            }

            return nl2br(htmlspecialchars($raw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false);
        }

        if ($filtered !== '') {
            // Re-sanitize cached HTML so stale/unsafe cache cannot render scripts.
            return AP_Content_Format::kses($filtered);
        }

        return AP_Content_Format::format($raw, ['context' => 'forum', 'mode' => 'auto']);
    }

    public static function forumUrl(object|int $forum): string
    {
        $id = is_object($forum) ? (int) $forum->forum_id : (int) $forum;
        $slug = is_object($forum) ? (string) ($forum->forum_slug ?? '') : '';
        $home = self::homeBaseUrl();
        $pretty = class_exists('AP_Rewrite', false) && AP_Rewrite::usingPermalinks();
        if ($pretty && $slug !== '') {
            return $home . 'forums/' . rawurlencode($slug) . '/';
        }

        return $home . '?ap_forum_view=forum&forum_id=' . $id;
    }

    public static function topicUrl(object|int $topic): string
    {
        $id = is_object($topic) ? (int) $topic->topic_id : (int) $topic;
        $slug = is_object($topic) ? (string) ($topic->topic_slug ?? '') : '';
        $home = self::homeBaseUrl();
        $pretty = class_exists('AP_Rewrite', false) && AP_Rewrite::usingPermalinks();
        if ($pretty && $slug !== '') {
            return $home . 'topic/' . rawurlencode($slug) . '/';
        }

        return $home . '?ap_forum_view=topic&topic_id=' . $id;
    }

    /**
     * Forum index URL (/forums/ or plain query).
     */
    public static function forumsIndexUrl(): string
    {
        $home = self::homeBaseUrl();
        $pretty = class_exists('AP_Rewrite', false) && AP_Rewrite::usingPermalinks();
        if ($pretty) {
            return $home . 'forums/';
        }

        return $home . '?ap_forum_view=index';
    }

    /**
     * Site home base ending with a single trailing slash.
     */
    private static function homeBaseUrl(): string
    {
        $home = '/';
        try {
            if (function_exists('ap_home_url') && class_exists('AP_Rewrite', false)) {
                $home = (string) ap_home_url('/');
            } elseif (function_exists('agora_home_url')) {
                $home = (string) agora_home_url('/');
            } elseif (defined('AP_HOME') && is_string(AP_HOME) && AP_HOME !== '') {
                $home = (string) AP_HOME;
            } elseif (defined('AP_SITEURL') && is_string(AP_SITEURL) && AP_SITEURL !== '') {
                $home = (string) AP_SITEURL;
            }
        } catch (Throwable) {
            $home = '/';
        }

        return rtrim($home, '/') . '/';
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * Human label for a canonical topic type (forms, badges, admin).
     */
    public static function topicTypeLabel(string $type): string
    {
        return match (self::normalizeTopicType($type)) {
            self::TOPIC_TYPE_STICKY => 'Sticky',
            self::TOPIC_TYPE_ANNOUNCEMENT => 'Announcement',
            self::TOPIC_TYPE_RULES => 'Rules',
            default => 'Standard',
        };
    }

    /**
     * ACL gate for createTopic when check_permissions is enabled.
     *
     * Requires post_topics plus sticky_topics / announce_topics for elevated types.
     */
    private static function userMayCreateTopic(int $userId, int $forumId, string $type, AP_DB $db): bool
    {
        if (!class_exists('AP_Forum_Permissions', false)) {
            return true;
        }

        if (!AP_Forum_Permissions::userCanPostTopic($userId, $forumId, $db)) {
            return false;
        }

        $type = self::normalizeTopicType($type);

        return AP_Forum_Permissions::userCanSetTopicType($userId, $forumId, $type, $db, null);
    }

    private static function wouldCreateForumCycle(int $id, int $parentId, AP_DB $db): bool
    {
        $seen = [$id => true];
        $current = $parentId;
        $guard = 0;
        while ($current > 0 && $guard < 100) {
            if (isset($seen[$current])) {
                return true;
            }
            $seen[$current] = true;
            $parent = self::getForum($current, $db);
            if ($parent === null) {
                break;
            }
            $current = (int) $parent->parent_id;
            $guard++;
        }

        return false;
    }

    private static function nextPostPosition(int $topicId, AP_DB $db): int
    {
        $table = $db->quoteIdentifier($db->table('forum_posts'));
        $max = $db->getVar(
            'SELECT MAX(' . $db->quoteIdentifier('post_position') . ') FROM ' . $table
            . ' WHERE ' . $db->quoteIdentifier('topic_id') . ' = ?',
            [$topicId]
        );

        return max(1, (int) $max + 1);
    }

    /**
     * Adjust denormalized forum counters and last-post pointers after create.
     *
     * $postDelta counts **posts** (opening posts + replies), not replies-only.
     * Creating a topic: topicDelta +1, postDelta +1 (the OP). Creating a reply:
     * topicDelta 0, postDelta +1. Soft-delete of a topic: postDelta = -(reply_count + 1).
     */
    private static function bumpForumStats(
        int $forumId,
        int $topicDelta,
        int $postDelta,
        int $lastPostId,
        int $lastPosterId,
        string $lastPostTime,
        int $lastTopicId,
        AP_DB $db
    ): void {
        $forum = self::getForum($forumId, $db);
        if ($forum === null) {
            return;
        }
        $db->update('forums', [
            'topic_count' => max(0, (int) $forum->topic_count + $topicDelta),
            // posts = OP + replies (see class docblock “Post-count definition”).
            'post_count' => max(0, (int) $forum->post_count + $postDelta),
            'last_post_id' => $lastPostId,
            'last_poster_id' => $lastPosterId,
            'last_post_time' => $lastPostTime,
            'last_topic_id' => $lastTopicId,
        ], ['forum_id' => $forumId]);
    }

    /**
     * Adjust topic/post counters without updating last-post pointers.
     *
     * $postDelta uses the same post definition as {@see bumpForumStats()}
     * (opening posts + replies, not replies-only).
     */
    private static function adjustForumStats(int $forumId, int $topicDelta, int $postDelta, AP_DB $db): void
    {
        $forum = self::getForum($forumId, $db);
        if ($forum === null) {
            return;
        }
        $db->update('forums', [
            'topic_count' => max(0, (int) $forum->topic_count + $topicDelta),
            // posts = OP + replies (see class docblock “Post-count definition”).
            'post_count' => max(0, (int) $forum->post_count + $postDelta),
        ], ['forum_id' => $forumId]);
    }

    private static function refreshTopicLastPost(int $topicId, AP_DB $db): void
    {
        $table = $db->quoteIdentifier($db->table('forum_posts'));
        $row = $db->getRow(
            'SELECT * FROM ' . $table
            . ' WHERE ' . $db->quoteIdentifier('topic_id') . ' = ?'
            . ' AND ' . $db->quoteIdentifier('post_approved') . ' = 1'
            . ' ORDER BY ' . $db->quoteIdentifier('post_time') . ' DESC, '
            . $db->quoteIdentifier('post_id') . ' DESC LIMIT 1',
            [$topicId]
        );
        if ($row === null) {
            $db->update('topics', [
                'last_post_id' => 0,
                'last_poster_id' => 0,
                'topic_last_post_time' => self::EMPTY_DATETIME,
            ], ['topic_id' => $topicId]);

            return;
        }
        $post = self::normalizePostRow($row);
        $db->update('topics', [
            'last_post_id' => (int) $post->post_id,
            'last_poster_id' => (int) $post->poster_id,
            'topic_last_post_time' => (string) $post->post_time,
        ], ['topic_id' => $topicId]);
    }

    private static function refreshForumLastPost(int $forumId, AP_DB $db): void
    {
        $table = $db->quoteIdentifier($db->table('forum_posts'));
        $row = $db->getRow(
            'SELECT * FROM ' . $table
            . ' WHERE ' . $db->quoteIdentifier('forum_id') . ' = ?'
            . ' AND ' . $db->quoteIdentifier('post_approved') . ' = 1'
            . ' ORDER BY ' . $db->quoteIdentifier('post_time') . ' DESC, '
            . $db->quoteIdentifier('post_id') . ' DESC LIMIT 1',
            [$forumId]
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
        $post = self::normalizePostRow($row);
        $db->update('forums', [
            'last_post_id' => (int) $post->post_id,
            'last_poster_id' => (int) $post->poster_id,
            'last_post_time' => (string) $post->post_time,
            'last_topic_id' => (int) $post->topic_id,
        ], ['forum_id' => $forumId]);
    }

    private static function onPostUnapproved(object $post, AP_DB $db): void
    {
        $topicId = (int) $post->topic_id;
        $topic = self::getTopic($topicId, $db);
        if ($topic === null) {
            if (function_exists('ap_do_action')) {
                ap_do_action('ap_forum_post_unapproved', (int) $post->post_id, $post);
            }

            return;
        }
        // First post unapproved: topic counters handled via topic_approved separately.
        if ((int) $topic->first_post_id !== (int) $post->post_id) {
            $replyCount = max(0, (int) $topic->reply_count - 1);
            $db->update('topics', [
                'reply_count' => $replyCount,
                'topic_modified' => self::nowLocal(),
            ], ['topic_id' => $topicId]);
            self::adjustForumStats((int) $topic->forum_id, 0, -1, $db);
            self::refreshTopicLastPost($topicId, $db);
            self::refreshForumLastPost((int) $topic->forum_id, $db);
        }
        if (function_exists('ap_do_action')) {
            ap_do_action('ap_forum_post_unapproved', (int) $post->post_id, $post);
        }
    }

    private static function onPostApproved(?object $post, AP_DB $db): void
    {
        if ($post === null) {
            return;
        }
        $topicId = (int) $post->topic_id;
        $topic = self::getTopic($topicId, $db);
        if ($topic !== null && (int) $topic->first_post_id !== (int) $post->post_id) {
            $db->update('topics', [
                'reply_count' => (int) $topic->reply_count + 1,
                'last_post_id' => (int) $post->post_id,
                'last_poster_id' => (int) $post->poster_id,
                'topic_last_post_time' => (string) $post->post_time,
                'topic_modified' => self::nowLocal(),
            ], ['topic_id' => $topicId]);
            self::bumpForumStats(
                (int) $topic->forum_id,
                0,
                1,
                (int) $post->post_id,
                (int) $post->poster_id,
                (string) $post->post_time,
                $topicId,
                $db
            );
        }
        if (function_exists('ap_do_action')) {
            ap_do_action('ap_forum_post_approved', (int) $post->post_id, $post);
        }
    }

    private static function normalizeForumRow(object $row): object
    {
        $o = new stdClass();
        $o->forum_id = (int) ($row->forum_id ?? 0);
        $o->parent_id = (int) ($row->parent_id ?? 0);
        $o->forum_type = (string) ($row->forum_type ?? self::FORUM_TYPE_FORUM);
        $o->forum_status = (string) ($row->forum_status ?? self::FORUM_STATUS_OPEN);
        $o->forum_name = (string) ($row->forum_name ?? '');
        $o->forum_slug = (string) ($row->forum_slug ?? '');
        $o->forum_desc = (string) ($row->forum_desc ?? '');
        $o->forum_order = (int) ($row->forum_order ?? 0);
        $o->topic_count = (int) ($row->topic_count ?? 0);
        $o->post_count = (int) ($row->post_count ?? 0);
        $o->last_post_id = (int) ($row->last_post_id ?? 0);
        $o->last_poster_id = (int) ($row->last_poster_id ?? 0);
        $o->last_post_time = (string) ($row->last_post_time ?? self::EMPTY_DATETIME);
        $o->last_topic_id = (int) ($row->last_topic_id ?? 0);

        return $o;
    }

    private static function normalizeTopicRow(object $row): object
    {
        $o = new stdClass();
        $o->topic_id = (int) ($row->topic_id ?? 0);
        $o->forum_id = (int) ($row->forum_id ?? 0);
        $o->topic_title = (string) ($row->topic_title ?? '');
        $o->topic_slug = (string) ($row->topic_slug ?? '');
        $o->topic_poster = (int) ($row->topic_poster ?? 0);
        $o->topic_status = (string) ($row->topic_status ?? self::TOPIC_STATUS_OPEN);
        $o->topic_type = self::normalizeTopicType((string) ($row->topic_type ?? self::TOPIC_TYPE_STANDARD));
        $o->topic_approved = (int) ($row->topic_approved ?? 1);
        $o->topic_views = (int) ($row->topic_views ?? 0);
        $o->reply_count = (int) ($row->reply_count ?? 0);
        $o->first_post_id = (int) ($row->first_post_id ?? 0);
        $o->last_post_id = (int) ($row->last_post_id ?? 0);
        $o->last_poster_id = (int) ($row->last_poster_id ?? 0);
        $o->topic_time = (string) ($row->topic_time ?? '');
        $o->topic_modified = (string) ($row->topic_modified ?? '');
        $o->topic_last_post_time = (string) ($row->topic_last_post_time ?? '');

        return $o;
    }

    private static function normalizePostRow(object $row): object
    {
        $o = new stdClass();
        $o->post_id = (int) ($row->post_id ?? 0);
        $o->topic_id = (int) ($row->topic_id ?? 0);
        $o->forum_id = (int) ($row->forum_id ?? 0);
        $o->poster_id = (int) ($row->poster_id ?? 0);
        $o->post_subject = (string) ($row->post_subject ?? '');
        $o->post_content = (string) ($row->post_content ?? '');
        $o->post_content_filtered = (string) ($row->post_content_filtered ?? '');
        $o->poster_ip = (string) ($row->poster_ip ?? '');
        $o->post_time = (string) ($row->post_time ?? '');
        $o->post_modified = (string) ($row->post_modified ?? '');
        $o->post_approved = (int) ($row->post_approved ?? 1);
        $o->post_reported = (int) ($row->post_reported ?? 0);
        $o->post_edit_reason = (string) ($row->post_edit_reason ?? '');
        $o->post_edit_user = (int) ($row->post_edit_user ?? 0);
        $o->post_edit_time = (string) ($row->post_edit_time ?? '');
        $o->post_edit_count = (int) ($row->post_edit_count ?? 0);
        $o->post_position = (int) ($row->post_position ?? 0);
        $o->like_count = (int) ($row->like_count ?? 0);

        return $o;
    }

    private static function sanitizeKey(string $key): string
    {
        $key = strtolower(trim($key));
        $key = preg_replace('/[^a-z0-9_\-]/', '', $key) ?? '';

        return $key;
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /**
     * Forums the searcher may read when permission checks are enabled.
     *
     * Returns null when ACL is not applied (no check_permissions / no AP_Forum_Permissions).
     * Returns an empty list when the user cannot read any forum.
     *
     * @param array<string, mixed> $args user_id, check_permissions
     *
     * @return list<int>|null
     */
    private static function forumIdsAllowedForSearch(array $args, AP_DB $db): ?array
    {
        if (empty($args['check_permissions'])) {
            return null;
        }
        if (!class_exists('AP_Forum_Permissions', false)) {
            return null;
        }

        $userId = max(0, (int) ($args['user_id'] ?? $args['poster_id'] ?? 0));

        // manage_forums sees everything (including hidden).
        if (
            $userId > 0
            && function_exists('ap_user_can')
            && ap_user_can($userId, 'manage_forums', null, $db)
        ) {
            return null;
        }
        if (
            $userId > 0
            && class_exists('AP_Roles', false)
            && AP_Roles::userCan($userId, 'manage_forums', null, $db)
        ) {
            return null;
        }

        $forums = self::getForums(['include_hidden' => true], $db);
        $allowed = [];
        foreach ($forums as $forum) {
            $fid = (int) ($forum->forum_id ?? 0);
            if ($fid < 1) {
                continue;
            }
            // Categories are containers; topics live on forum-type nodes.
            if ((string) ($forum->forum_type ?? '') === self::FORUM_TYPE_CATEGORY) {
                continue;
            }
            if (AP_Forum_Permissions::userCan($userId, $fid, AP_Forum_Permissions::PERM_READ, $db)) {
                $allowed[] = $fid;
            }
        }

        return $allowed;
    }

    private static function nowLocal(): string
    {
        return date('Y-m-d H:i:s');
    }

    /**
     * Resolve viewer for unread annotation: explicit user_id arg, else current user.
     *
     * @param array<string, mixed> $args
     */
    private static function resolveViewerUserId(array $args, ?AP_DB $db): int
    {
        if (array_key_exists('user_id', $args)) {
            return max(0, (int) $args['user_id']);
        }
        if (function_exists('ap_get_current_user_id')) {
            try {
                return max(0, (int) ap_get_current_user_id($db));
            } catch (Throwable) {
                return 0;
            }
        }

        return 0;
    }

    private static function resolveDb(?AP_DB $db): AP_DB
    {
        if ($db instanceof AP_DB) {
            return $db;
        }

        if (function_exists('ap_db')) {
            return ap_db();
        }

        throw new RuntimeException('No database connection available for forum operations.');
    }
}
