<?php

/**
 * AgoraPress comments — nested threads, moderation, pluggable spam hooks.
 *
 * WP-inspired (not a fork). Rows live in {prefix}comments; metadata in
 * {prefix}commentmeta. Statuses stored in comment_approved:
 *   '1' (approved), '0' (pending/hold), 'spam', 'trash'.
 *
 * Nesting via comment_parent. Approved count is mirrored on posts.comment_count.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * Comment model + moderation helpers against {prefix}comments.
 */
class AP_Comment
{
    /** Approved (public). */
    public const STATUS_APPROVED = '1';

    /** Awaiting moderation. */
    public const STATUS_HOLD = '0';

    /** Marked as spam. */
    public const STATUS_SPAM = 'spam';

    /** Soft-deleted. */
    public const STATUS_TRASH = 'trash';

    /** Meta key storing status before trash. */
    public const TRASH_STATUS_META = '_ap_trash_meta_status';

    /** Default comment type. */
    public const TYPE_COMMENT = 'comment';

    /**
     * Registered spam checkers: callable(array $data): bool|string
     * Return true/'spam' to mark spam, false/'1'/'0' for non-spam (status override).
     *
     * @var list<callable>
     */
    private static array $spamCheckers = [];

    /** @var int Comment ID (0 = not persisted). */
    public int $comment_ID = 0;

    public int $comment_post_ID = 0;

    public string $comment_author = '';

    public string $comment_author_email = '';

    public string $comment_author_url = '';

    public string $comment_author_IP = '';

    public string $comment_date = '';

    public string $comment_date_gmt = '';

    public string $comment_content = '';

    public int $comment_karma = 0;

    /** @var string '1'|'0'|'spam'|'trash' */
    public string $comment_approved = self::STATUS_APPROVED;

    public string $comment_agent = '';

    public string $comment_type = self::TYPE_COMMENT;

    public int $comment_parent = 0;

    public int $user_id = 0;

    // -------------------------------------------------------------------------
    // Status helpers
    // -------------------------------------------------------------------------

    /**
     * Normalize a status token to stored comment_approved value.
     */
    public static function normalizeStatus(string $status): string
    {
        $status = strtolower(trim($status));

        return match ($status) {
            '1', 'approve', 'approved' => self::STATUS_APPROVED,
            '0', 'hold', 'pending', 'unapproved', 'moderation' => self::STATUS_HOLD,
            'spam' => self::STATUS_SPAM,
            'trash' => self::STATUS_TRASH,
            default => self::STATUS_HOLD,
        };
    }

    /**
     * Whether $status is a known stored approval value.
     */
    public static function isValidStatus(string $status): bool
    {
        return in_array($status, [
            self::STATUS_APPROVED,
            self::STATUS_HOLD,
            self::STATUS_SPAM,
            self::STATUS_TRASH,
        ], true);
    }

    public static function isApproved(self|string $commentOrStatus): bool
    {
        $status = $commentOrStatus instanceof self
            ? $commentOrStatus->comment_approved
            : $commentOrStatus;

        return $status === self::STATUS_APPROVED;
    }

    public static function isPending(self|string $commentOrStatus): bool
    {
        $status = $commentOrStatus instanceof self
            ? $commentOrStatus->comment_approved
            : $commentOrStatus;

        return $status === self::STATUS_HOLD;
    }

    public static function isSpam(self|string $commentOrStatus): bool
    {
        $status = $commentOrStatus instanceof self
            ? $commentOrStatus->comment_approved
            : $commentOrStatus;

        return $status === self::STATUS_SPAM;
    }

    public static function isTrash(self|string $commentOrStatus): bool
    {
        $status = $commentOrStatus instanceof self
            ? $commentOrStatus->comment_approved
            : $commentOrStatus;

        return $status === self::STATUS_TRASH;
    }

    /**
     * Human label for a status.
     */
    public static function statusLabel(string $status): string
    {
        return match (self::normalizeStatus($status)) {
            self::STATUS_APPROVED => 'Approved',
            self::STATUS_HOLD => 'Pending',
            self::STATUS_SPAM => 'Spam',
            self::STATUS_TRASH => 'Trash',
            default => $status,
        };
    }

    // -------------------------------------------------------------------------
    // Pluggable spam hooks
    // -------------------------------------------------------------------------

    /**
     * Register a spam checker callback.
     *
     * Signature: function (array $commentData): bool|string
     * - true or 'spam' → mark as spam
     * - false → leave status unchanged (not spam)
     * - '1' / '0' / 'approve' / 'hold' → override approval status
     *
     * @param callable(array<string, mixed>): (bool|string) $callback
     */
    public static function registerSpamChecker(callable $callback): void
    {
        self::$spamCheckers[] = $callback;
    }

    /**
     * Clear registered spam checkers (tests only).
     */
    public static function resetSpamCheckers(): void
    {
        self::$spamCheckers = [];
    }

    /**
     * Run spam checkers against comment data; returns suggested status or null.
     *
     * @param array<string, mixed> $data
     */
    public static function runSpamChecks(array $data): ?string
    {
        foreach (self::$spamCheckers as $checker) {
            $result = $checker($data);
            if ($result === true || $result === 'spam') {
                return self::STATUS_SPAM;
            }
            if (is_string($result) && $result !== '') {
                $normalized = self::normalizeStatus($result);
                if (self::isValidStatus($normalized)) {
                    return $normalized;
                }
            }
        }

        // Full hook system (Phase 4): allow filters when present.
        if (function_exists('ap_apply_filters')) {
            $filtered = ap_apply_filters('ap_pre_comment_approved', null, $data);
            if ($filtered === true || $filtered === 'spam') {
                return self::STATUS_SPAM;
            }
            if (is_string($filtered) && $filtered !== '') {
                $normalized = self::normalizeStatus($filtered);
                if (self::isValidStatus($normalized)) {
                    return $normalized;
                }
            }
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // CRUD
    // -------------------------------------------------------------------------

    /**
     * Fetch a comment by ID.
     */
    public static function get(int $id, ?AP_DB $db = null): ?self
    {
        if ($id < 1) {
            return null;
        }

        $db = self::resolveDb($db);
        $table = $db->quoteIdentifier($db->table('comments'));
        $row = $db->getRow(
            'SELECT * FROM ' . $table . ' WHERE ' . $db->quoteIdentifier('comment_ID') . ' = ?',
            [$id]
        );

        return $row !== null ? self::fromRow($row) : null;
    }

    /**
     * Insert a comment. Returns new comment_ID or 0 on failure.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $args Options:
     *   - check_open (bool, default true): reject when post comments closed
     *   - run_spam (bool, default true): run pluggable spam checkers
     *   - update_count (bool, default true): refresh posts.comment_count
     */
    public static function insert(array $data, ?AP_DB $db = null, array $args = []): int
    {
        $db = self::resolveDb($db);
        $checkOpen = !array_key_exists('check_open', $args) || !empty($args['check_open']);
        $runSpam = !array_key_exists('run_spam', $args) || !empty($args['run_spam']);
        $updateCount = !array_key_exists('update_count', $args) || !empty($args['update_count']);

        $postId = (int) ($data['comment_post_ID'] ?? $data['post_ID'] ?? 0);
        if ($postId < 1) {
            return 0;
        }

        $content = trim((string) ($data['comment_content'] ?? ''));
        if ($content === '') {
            return 0;
        }
        $content = str_replace("\0", '', $content);

        // Parent post must exist.
        $post = null;
        if (class_exists('AP_Post', false)) {
            $post = AP_Post::get($postId, $db);
            if ($post === null) {
                return 0;
            }
            if ($checkOpen && $post->comment_status !== 'open') {
                return 0;
            }
        } else {
            $postsTable = $db->quoteIdentifier($db->table('posts'));
            $exists = $db->getVar(
                'SELECT ID FROM ' . $postsTable . ' WHERE ' . $db->quoteIdentifier('ID') . ' = ?',
                [$postId]
            );
            if ($exists === null || $exists === false) {
                return 0;
            }
        }

        $parent = max(0, (int) ($data['comment_parent'] ?? 0));
        if ($parent > 0) {
            $parentComment = self::get($parent, $db);
            if ($parentComment === null || $parentComment->comment_post_ID !== $postId) {
                return 0;
            }
        }

        $userId = max(0, (int) ($data['user_id'] ?? 0));
        $author = trim((string) ($data['comment_author'] ?? ''));
        $email = trim((string) ($data['comment_author_email'] ?? ''));
        $url = trim((string) ($data['comment_author_url'] ?? ''));
        $ip = trim((string) ($data['comment_author_IP'] ?? $data['comment_author_ip'] ?? ''));
        $agent = trim((string) ($data['comment_agent'] ?? ''));
        $type = self::sanitizeKey((string) ($data['comment_type'] ?? self::TYPE_COMMENT));
        if ($type === '') {
            $type = self::TYPE_COMMENT;
        }

        // Default approval: logged-in users approve; guests hold.
        if (isset($data['comment_approved'])) {
            $approved = self::normalizeStatus((string) $data['comment_approved']);
        } else {
            $approved = $userId > 0 ? self::STATUS_APPROVED : self::STATUS_HOLD;
        }

        $now = self::nowLocal();
        $nowGmt = self::nowGmt();
        $date = (string) ($data['comment_date'] ?? $now);
        $dateGmt = (string) ($data['comment_date_gmt'] ?? '');
        if ($dateGmt === '') {
            $dateGmt = self::localToGmt($date) ?? $nowGmt;
        }

        $row = [
            'comment_post_ID' => $postId,
            'comment_author' => $author,
            'comment_author_email' => $email,
            'comment_author_url' => $url,
            'comment_author_IP' => $ip,
            'comment_date' => $date,
            'comment_date_gmt' => $dateGmt,
            'comment_content' => $content,
            'comment_karma' => (int) ($data['comment_karma'] ?? 0),
            'comment_approved' => $approved,
            'comment_agent' => $agent,
            'comment_type' => $type,
            'comment_parent' => $parent,
            'user_id' => $userId,
        ];

        if ($runSpam) {
            $spamStatus = self::runSpamChecks($row);
            if ($spamStatus !== null) {
                $row['comment_approved'] = $spamStatus;
            }
        }

        if (function_exists('ap_do_action')) {
            ap_do_action('ap_pre_comment_insert', $row);
        }

        $result = $db->insert('comments', $row);
        if ($result === false) {
            return 0;
        }

        $id = (int) $db->lastInsertId();
        if ($id < 1) {
            return 0;
        }

        if (!empty($data['meta']) && is_array($data['meta'])) {
            foreach ($data['meta'] as $metaKey => $metaValue) {
                if (!is_string($metaKey) || $metaKey === '') {
                    continue;
                }
                self::updateMeta(
                    $id,
                    $metaKey,
                    is_scalar($metaValue) || $metaValue === null
                        ? (string) ($metaValue ?? '')
                        : (string) json_encode($metaValue),
                    $db
                );
            }
        }

        if ($updateCount) {
            self::updateCommentCount($postId, $db);
        }

        if (function_exists('ap_do_action')) {
            ap_do_action('ap_comment_inserted', $id, self::get($id, $db));
        }

        return $id;
    }

    /**
     * Update a comment. Returns true on success.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $args Options: update_count (bool, default true)
     */
    public static function update(int $id, array $data, ?AP_DB $db = null, array $args = []): bool
    {
        if ($id < 1 || $data === []) {
            return false;
        }

        $db = self::resolveDb($db);
        $existing = self::get($id, $db);
        if ($existing === null) {
            return false;
        }

        $updateCount = !array_key_exists('update_count', $args) || !empty($args['update_count']);
        $update = [];

        $stringCols = [
            'comment_author',
            'comment_author_email',
            'comment_author_url',
            'comment_author_IP',
            'comment_date',
            'comment_date_gmt',
            'comment_content',
            'comment_agent',
            'comment_type',
        ];
        foreach ($stringCols as $col) {
            if (array_key_exists($col, $data)) {
                $update[$col] = (string) $data[$col];
            }
        }
        // Accept lowercase IP alias.
        if (array_key_exists('comment_author_ip', $data) && !isset($update['comment_author_IP'])) {
            $update['comment_author_IP'] = (string) $data['comment_author_ip'];
        }

        if (array_key_exists('comment_karma', $data)) {
            $update['comment_karma'] = (int) $data['comment_karma'];
        }
        if (array_key_exists('comment_parent', $data)) {
            $parent = max(0, (int) $data['comment_parent']);
            if ($parent === $id) {
                return false;
            }
            if ($parent > 0) {
                $parentComment = self::get($parent, $db);
                if ($parentComment === null) {
                    return false;
                }
                $postId = (int) ($data['comment_post_ID'] ?? $existing->comment_post_ID);
                if ($parentComment->comment_post_ID !== $postId) {
                    return false;
                }
                if (self::wouldCreateCycle($id, $parent, $db)) {
                    return false;
                }
            }
            $update['comment_parent'] = $parent;
        }
        if (array_key_exists('user_id', $data)) {
            $update['user_id'] = max(0, (int) $data['user_id']);
        }
        if (array_key_exists('comment_post_ID', $data) || array_key_exists('post_ID', $data)) {
            $update['comment_post_ID'] = max(0, (int) ($data['comment_post_ID'] ?? $data['post_ID'] ?? 0));
        }
        if (array_key_exists('comment_approved', $data)) {
            $update['comment_approved'] = self::normalizeStatus((string) $data['comment_approved']);
        }
        if (array_key_exists('comment_content', $data)) {
            $content = trim((string) $data['comment_content']);
            if ($content === '') {
                return false;
            }
            $update['comment_content'] = str_replace("\0", '', $content);
        }

        if ($update === []) {
            return true;
        }

        $result = $db->update('comments', $update, ['comment_ID' => $id]);
        if ($result === false) {
            return false;
        }

        if (!empty($data['meta']) && is_array($data['meta'])) {
            foreach ($data['meta'] as $metaKey => $metaValue) {
                if (!is_string($metaKey) || $metaKey === '') {
                    continue;
                }
                self::updateMeta(
                    $id,
                    $metaKey,
                    is_scalar($metaValue) || $metaValue === null
                        ? (string) ($metaValue ?? '')
                        : (string) json_encode($metaValue),
                    $db
                );
            }
        }

        if ($updateCount) {
            $postIds = [$existing->comment_post_ID];
            if (isset($update['comment_post_ID']) && (int) $update['comment_post_ID'] !== $existing->comment_post_ID) {
                $postIds[] = (int) $update['comment_post_ID'];
            }
            // Recount when approval status or post assignment changes.
            if (
                isset($update['comment_approved'])
                || isset($update['comment_post_ID'])
            ) {
                foreach (array_unique($postIds) as $pid) {
                    if ($pid > 0) {
                        self::updateCommentCount($pid, $db);
                    }
                }
            }
        }

        $updated = self::get($id, $db);
        if (function_exists('ap_do_action')) {
            ap_do_action('ap_comment_updated', $id, $updated);
            if (isset($update['comment_approved'])) {
                ap_do_action(
                    'ap_comment_status_changed',
                    $id,
                    $updated,
                    (string) $existing->comment_approved,
                    (string) $update['comment_approved']
                );
            }
        }

        return true;
    }

    /**
     * Approve a comment.
     */
    public static function approve(int $id, ?AP_DB $db = null): bool
    {
        return self::setStatus($id, self::STATUS_APPROVED, $db);
    }

    /**
     * Unapprove (hold for moderation).
     */
    public static function unapprove(int $id, ?AP_DB $db = null): bool
    {
        return self::setStatus($id, self::STATUS_HOLD, $db);
    }

    /**
     * Mark as spam.
     */
    public static function spam(int $id, ?AP_DB $db = null): bool
    {
        return self::setStatus($id, self::STATUS_SPAM, $db);
    }

    /**
     * Remove spam flag → pending (or previous non-spam if we only know spam).
     */
    public static function unspam(int $id, ?AP_DB $db = null): bool
    {
        $db = self::resolveDb($db);
        $comment = self::get($id, $db);
        if ($comment === null || $comment->comment_approved !== self::STATUS_SPAM) {
            return false;
        }

        return self::setStatus($id, self::STATUS_HOLD, $db);
    }

    /**
     * Soft-delete to trash; remembers previous status in meta.
     */
    public static function trash(int $id, ?AP_DB $db = null): bool
    {
        $db = self::resolveDb($db);
        $comment = self::get($id, $db);
        if ($comment === null || $comment->comment_approved === self::STATUS_TRASH) {
            return false;
        }

        self::updateMeta($id, self::TRASH_STATUS_META, $comment->comment_approved, $db);

        return self::setStatus($id, self::STATUS_TRASH, $db);
    }

    /**
     * Restore from trash to previous status (or pending).
     */
    public static function untrash(int $id, ?AP_DB $db = null): bool
    {
        $db = self::resolveDb($db);
        $comment = self::get($id, $db);
        if ($comment === null || $comment->comment_approved !== self::STATUS_TRASH) {
            return false;
        }

        $previous = self::getMeta($id, self::TRASH_STATUS_META, true, $db);
        if (!is_string($previous) || $previous === '' || $previous === self::STATUS_TRASH) {
            $previous = self::STATUS_HOLD;
        } else {
            $previous = self::normalizeStatus($previous);
            if (!self::isValidStatus($previous) || $previous === self::STATUS_TRASH) {
                $previous = self::STATUS_HOLD;
            }
        }

        $ok = self::setStatus($id, $previous, $db);
        if ($ok) {
            self::deleteMeta($id, self::TRASH_STATUS_META, $db);
        }

        return $ok;
    }

    /**
     * Delete a comment. Soft-deletes to trash unless $force is true.
     * Force-delete also removes commentmeta and re-parents children to the
     * deleted comment's parent.
     */
    public static function delete(int $id, bool $force = false, ?AP_DB $db = null): bool
    {
        if ($id < 1) {
            return false;
        }

        $db = self::resolveDb($db);
        $comment = self::get($id, $db);
        if ($comment === null) {
            return false;
        }

        if (!$force) {
            if ($comment->comment_approved === self::STATUS_TRASH) {
                $force = true;
            } else {
                return self::trash($id, $db);
            }
        }

        // Re-parent children.
        $table = $db->quoteIdentifier($db->table('comments'));
        $db->query(
            'UPDATE ' . $table
            . ' SET ' . $db->quoteIdentifier('comment_parent') . ' = ?'
            . ' WHERE ' . $db->quoteIdentifier('comment_parent') . ' = ?',
            [$comment->comment_parent, $id]
        );

        // Drop meta.
        $metaTable = $db->quoteIdentifier($db->table('commentmeta'));
        $db->query(
            'DELETE FROM ' . $metaTable . ' WHERE ' . $db->quoteIdentifier('comment_id') . ' = ?',
            [$id]
        );

        $result = $db->delete('comments', ['comment_ID' => $id]);
        if ($result === false) {
            return false;
        }

        self::updateCommentCount($comment->comment_post_ID, $db);

        if (function_exists('ap_do_action')) {
            ap_do_action('ap_comment_deleted', $id, $comment->comment_post_ID, $comment);
        }

        return true;
    }

    /**
     * Set comment_approved (moderation status).
     */
    public static function setStatus(int $id, string $status, ?AP_DB $db = null): bool
    {
        $status = self::normalizeStatus($status);
        if (!self::isValidStatus($status)) {
            return false;
        }

        return self::update($id, ['comment_approved' => $status], $db);
    }

    // -------------------------------------------------------------------------
    // Query / trees
    // -------------------------------------------------------------------------

    /**
     * List comments with filters.
     *
     * Args:
     * - post_id / post__in: int|list
     * - status: all|approve|hold|spam|trash|string|list (default approve for public)
     * - parent: int (exact parent; omit for any)
     * - parent__in: list of parent IDs
     * - type: comment type (default any)
     * - user_id: int
     * - search / s: search author/email/content
     * - orderby: date|id|parent|karma (default date)
     * - order: ASC|DESC (default DESC)
     * - limit / number: int (default 0 = no limit for model; admin passes per_page)
     * - offset / paged: pagination
     * - hierarchical: bool — when true and post_id set, returns only top-level
     *   (parent=0) unless parent is specified
     *
     * @param array<string, mixed> $args
     *
     * @return list<self>
     */
    public static function query(array $args = [], ?AP_DB $db = null): array
    {
        $db = self::resolveDb($db);
        $table = $db->quoteIdentifier($db->table('comments'));
        $where = [];
        $params = [];

        $postId = (int) ($args['post_id'] ?? $args['comment_post_ID'] ?? 0);
        if ($postId > 0) {
            $where[] = $db->quoteIdentifier('comment_post_ID') . ' = ?';
            $params[] = $postId;
        } elseif (!empty($args['post__in']) && is_array($args['post__in'])) {
            $ids = array_values(array_filter(
                array_map('intval', $args['post__in']),
                static fn (int $i): bool => $i > 0
            ));
            if ($ids !== []) {
                $placeholders = implode(', ', array_fill(0, count($ids), '?'));
                $where[] = $db->quoteIdentifier('comment_post_ID') . ' IN (' . $placeholders . ')';
                foreach ($ids as $i) {
                    $params[] = $i;
                }
            }
        }

        $statusArg = $args['status'] ?? $args['comment_approved'] ?? 'approve';
        if ($statusArg !== 'all' && $statusArg !== '') {
            $statuses = is_array($statusArg) ? $statusArg : [$statusArg];
            $normalized = [];
            foreach ($statuses as $s) {
                $normalized[] = self::normalizeStatus((string) $s);
            }
            $normalized = array_values(array_unique($normalized));
            if (count($normalized) === 1) {
                $where[] = $db->quoteIdentifier('comment_approved') . ' = ?';
                $params[] = $normalized[0];
            } elseif ($normalized !== []) {
                $placeholders = implode(', ', array_fill(0, count($normalized), '?'));
                $where[] = $db->quoteIdentifier('comment_approved') . ' IN (' . $placeholders . ')';
                foreach ($normalized as $s) {
                    $params[] = $s;
                }
            }
        }

        if (array_key_exists('parent', $args)) {
            $where[] = $db->quoteIdentifier('comment_parent') . ' = ?';
            $params[] = max(0, (int) $args['parent']);
        } elseif (!empty($args['hierarchical']) && $postId > 0) {
            $where[] = $db->quoteIdentifier('comment_parent') . ' = 0';
        }

        if (!empty($args['parent__in']) && is_array($args['parent__in'])) {
            $pids = array_values(array_map('intval', $args['parent__in']));
            if ($pids !== []) {
                $placeholders = implode(', ', array_fill(0, count($pids), '?'));
                $where[] = $db->quoteIdentifier('comment_parent') . ' IN (' . $placeholders . ')';
                foreach ($pids as $p) {
                    $params[] = $p;
                }
            }
        }

        if (isset($args['type']) && (string) $args['type'] !== '' && (string) $args['type'] !== 'all') {
            $where[] = $db->quoteIdentifier('comment_type') . ' = ?';
            $params[] = self::sanitizeKey((string) $args['type']);
        }

        if (isset($args['user_id']) && (int) $args['user_id'] > 0) {
            $where[] = $db->quoteIdentifier('user_id') . ' = ?';
            $params[] = (int) $args['user_id'];
        }

        $search = trim((string) ($args['search'] ?? $args['s'] ?? ''));
        if ($search !== '') {
            $like = '%' . self::escapeLike($search) . '%';
            $where[] = '('
                . $db->quoteIdentifier('comment_author') . ' LIKE ? OR '
                . $db->quoteIdentifier('comment_author_email') . ' LIKE ? OR '
                . $db->quoteIdentifier('comment_content') . ' LIKE ?'
                . ')';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $sql = 'SELECT * FROM ' . $table;
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $orderby = strtolower((string) ($args['orderby'] ?? 'date'));
        $orderCol = match ($orderby) {
            'id' => 'comment_ID',
            'parent' => 'comment_parent',
            'karma' => 'comment_karma',
            default => 'comment_date_gmt',
        };
        $order = strtoupper((string) ($args['order'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
        $sql .= ' ORDER BY ' . $db->quoteIdentifier($orderCol) . ' ' . $order
            . ', ' . $db->quoteIdentifier('comment_ID') . ' ' . $order;

        $limit = (int) ($args['limit'] ?? $args['number'] ?? 0);
        $offset = (int) ($args['offset'] ?? 0);
        if ($offset < 1 && isset($args['paged'])) {
            $paged = max(1, (int) $args['paged']);
            $perPage = $limit > 0 ? $limit : 20;
            $offset = ($paged - 1) * $perPage;
            $limit = $perPage;
        }
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit;
            if ($offset > 0) {
                $sql .= ' OFFSET ' . $offset;
            }
        }

        $rows = $db->getResults($sql, $params);
        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $out[] = self::fromRow($row);
        }

        return $out;
    }

    /**
     * Count comments matching filters (same args as query, without limit).
     *
     * @param array<string, mixed> $args
     */
    public static function count(array $args = [], ?AP_DB $db = null): int
    {
        $db = self::resolveDb($db);
        $table = $db->quoteIdentifier($db->table('comments'));
        $where = [];
        $params = [];

        // Reuse query's filter building via a thin internal path.
        $args['limit'] = 0;
        $args['offset'] = 0;
        unset($args['paged'], $args['number']);

        $postId = (int) ($args['post_id'] ?? $args['comment_post_ID'] ?? 0);
        if ($postId > 0) {
            $where[] = $db->quoteIdentifier('comment_post_ID') . ' = ?';
            $params[] = $postId;
        }

        $statusArg = $args['status'] ?? $args['comment_approved'] ?? 'all';
        if ($statusArg !== 'all' && $statusArg !== '') {
            $statuses = is_array($statusArg) ? $statusArg : [$statusArg];
            $normalized = [];
            foreach ($statuses as $s) {
                $normalized[] = self::normalizeStatus((string) $s);
            }
            $normalized = array_values(array_unique($normalized));
            if (count($normalized) === 1) {
                $where[] = $db->quoteIdentifier('comment_approved') . ' = ?';
                $params[] = $normalized[0];
            } elseif ($normalized !== []) {
                $placeholders = implode(', ', array_fill(0, count($normalized), '?'));
                $where[] = $db->quoteIdentifier('comment_approved') . ' IN (' . $placeholders . ')';
                foreach ($normalized as $s) {
                    $params[] = $s;
                }
            }
        }

        if (array_key_exists('parent', $args)) {
            $where[] = $db->quoteIdentifier('comment_parent') . ' = ?';
            $params[] = max(0, (int) $args['parent']);
        }

        $search = trim((string) ($args['search'] ?? $args['s'] ?? ''));
        if ($search !== '') {
            $like = '%' . self::escapeLike($search) . '%';
            $where[] = '('
                . $db->quoteIdentifier('comment_author') . ' LIKE ? OR '
                . $db->quoteIdentifier('comment_author_email') . ' LIKE ? OR '
                . $db->quoteIdentifier('comment_content') . ' LIKE ?'
                . ')';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $sql = 'SELECT COUNT(*) FROM ' . $table;
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        return (int) $db->getVar($sql, $params);
    }

    /**
     * Status → count map (optionally for one post). Excludes nothing by default.
     *
     * @return array<string, int>
     */
    public static function countByStatus(?int $postId = null, ?AP_DB $db = null): array
    {
        $db = self::resolveDb($db);
        $table = $db->quoteIdentifier($db->table('comments'));
        $sql = 'SELECT ' . $db->quoteIdentifier('comment_approved') . ' AS status, COUNT(*) AS cnt'
            . ' FROM ' . $table;
        $params = [];
        if ($postId !== null && $postId > 0) {
            $sql .= ' WHERE ' . $db->quoteIdentifier('comment_post_ID') . ' = ?';
            $params[] = $postId;
        }
        $sql .= ' GROUP BY ' . $db->quoteIdentifier('comment_approved');

        $rows = $db->getResults($sql, $params);
        $out = [
            self::STATUS_APPROVED => 0,
            self::STATUS_HOLD => 0,
            self::STATUS_SPAM => 0,
            self::STATUS_TRASH => 0,
        ];
        if (!is_array($rows)) {
            return $out;
        }
        foreach ($rows as $row) {
            $status = (string) ($row->status ?? '');
            $out[$status] = (int) ($row->cnt ?? 0);
        }

        return $out;
    }

    /**
     * Nested tree of comments for a post (approved by default).
     *
     * Each node: ['comment' => AP_Comment, 'children' => list]
     *
     * @param array<string, mixed> $args Passed to query (status default approve)
     *
     * @return list<array{comment: self, children: list}>
     */
    public static function getTree(int $postId, array $args = [], ?AP_DB $db = null): array
    {
        if ($postId < 1) {
            return [];
        }

        $args['post_id'] = $postId;
        if (!isset($args['status'])) {
            $args['status'] = 'approve';
        }
        $args['order'] = $args['order'] ?? 'ASC';
        $args['orderby'] = $args['orderby'] ?? 'date';
        unset($args['hierarchical'], $args['parent'], $args['limit'], $args['number'], $args['paged']);

        $all = self::query($args, $db);
        /** @var array<int, array{comment: self, children: list}> $nodes */
        $nodes = [];
        foreach ($all as $c) {
            $nodes[$c->comment_ID] = ['comment' => $c, 'children' => []];
        }

        $roots = [];
        foreach ($nodes as $id => &$node) {
            $parent = $node['comment']->comment_parent;
            if ($parent > 0 && isset($nodes[$parent])) {
                $nodes[$parent]['children'][] = &$node;
            } else {
                $roots[] = &$node;
            }
        }
        unset($node);

        // Detach references for a clean return structure.
        return self::cloneTreeNodes($roots);
    }

    /**
     * Flat list of comments for a post (public-facing helper).
     *
     * @param array<string, mixed> $args
     *
     * @return list<self>
     */
    public static function getByPost(int $postId, array $args = [], ?AP_DB $db = null): array
    {
        if ($postId < 1) {
            return [];
        }
        $args['post_id'] = $postId;
        if (!isset($args['status'])) {
            $args['status'] = 'approve';
        }

        return self::query($args, $db);
    }

    /**
     * Recount approved comments on a post and write posts.comment_count.
     */
    public static function updateCommentCount(int $postId, ?AP_DB $db = null): int
    {
        if ($postId < 1) {
            return 0;
        }

        $db = self::resolveDb($db);
        $table = $db->quoteIdentifier($db->table('comments'));
        $count = (int) $db->getVar(
            'SELECT COUNT(*) FROM ' . $table
            . ' WHERE ' . $db->quoteIdentifier('comment_post_ID') . ' = ?'
            . ' AND ' . $db->quoteIdentifier('comment_approved') . ' = ?',
            [$postId, self::STATUS_APPROVED]
        );

        $postsTable = $db->quoteIdentifier($db->table('posts'));
        $db->query(
            'UPDATE ' . $postsTable
            . ' SET ' . $db->quoteIdentifier('comment_count') . ' = ?'
            . ' WHERE ' . $db->quoteIdentifier('ID') . ' = ?',
            [$count, $postId]
        );

        return $count;
    }

    // -------------------------------------------------------------------------
    // Meta
    // -------------------------------------------------------------------------

    /**
     * @return string|list<string>|null
     */
    public static function getMeta(
        int $commentId,
        string $key,
        bool $single = true,
        ?AP_DB $db = null
    ): string|array|null {
        if ($commentId < 1 || $key === '') {
            return $single ? null : [];
        }

        $db = self::resolveDb($db);
        $table = $db->quoteIdentifier($db->table('commentmeta'));

        if ($single) {
            $value = $db->getVar(
                'SELECT meta_value FROM ' . $table
                . ' WHERE ' . $db->quoteIdentifier('comment_id') . ' = ?'
                . ' AND ' . $db->quoteIdentifier('meta_key') . ' = ?'
                . ' LIMIT 1',
                [$commentId, $key]
            );

            return $value === null || $value === false ? null : (string) $value;
        }

        $rows = $db->getCol(
            'SELECT meta_value FROM ' . $table
            . ' WHERE ' . $db->quoteIdentifier('comment_id') . ' = ?'
            . ' AND ' . $db->quoteIdentifier('meta_key') . ' = ?',
            [$commentId, $key]
        );

        return is_array($rows) ? array_map('strval', $rows) : [];
    }

    public static function updateMeta(
        int $commentId,
        string $key,
        string $value,
        ?AP_DB $db = null
    ): bool {
        if ($commentId < 1 || $key === '') {
            return false;
        }

        $db = self::resolveDb($db);
        $existing = self::getMeta($commentId, $key, true, $db);
        if ($existing !== null) {
            $result = $db->update(
                'commentmeta',
                ['meta_value' => $value],
                ['comment_id' => $commentId, 'meta_key' => $key]
            );

            return $result !== false;
        }

        $result = $db->insert('commentmeta', [
            'comment_id' => $commentId,
            'meta_key' => $key,
            'meta_value' => $value,
        ]);

        return $result !== false;
    }

    public static function deleteMeta(int $commentId, string $key, ?AP_DB $db = null): bool
    {
        if ($commentId < 1 || $key === '') {
            return false;
        }

        $db = self::resolveDb($db);
        $result = $db->delete('commentmeta', [
            'comment_id' => $commentId,
            'meta_key' => $key,
        ]);

        return $result !== false;
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * @param object $row
     */
    public static function fromRow(object $row): self
    {
        $c = new self();
        $c->comment_ID = (int) ($row->comment_ID ?? $row->comment_id ?? 0);
        $c->comment_post_ID = (int) ($row->comment_post_ID ?? $row->comment_post_id ?? 0);
        $c->comment_author = (string) ($row->comment_author ?? '');
        $c->comment_author_email = (string) ($row->comment_author_email ?? '');
        $c->comment_author_url = (string) ($row->comment_author_url ?? '');
        $c->comment_author_IP = (string) ($row->comment_author_IP ?? $row->comment_author_ip ?? '');
        $c->comment_date = (string) ($row->comment_date ?? '');
        $c->comment_date_gmt = (string) ($row->comment_date_gmt ?? '');
        $c->comment_content = (string) ($row->comment_content ?? '');
        $c->comment_karma = (int) ($row->comment_karma ?? 0);
        $c->comment_approved = (string) ($row->comment_approved ?? self::STATUS_APPROVED);
        $c->comment_agent = (string) ($row->comment_agent ?? '');
        $c->comment_type = (string) ($row->comment_type ?? self::TYPE_COMMENT);
        $c->comment_parent = (int) ($row->comment_parent ?? 0);
        $c->user_id = (int) ($row->user_id ?? 0);

        return $c;
    }

    /**
     * @param list<array{comment: self, children: list}> $nodes
     *
     * @return list<array{comment: self, children: list}>
     */
    private static function cloneTreeNodes(array $nodes): array
    {
        $out = [];
        foreach ($nodes as $node) {
            $out[] = [
                'comment' => $node['comment'],
                'children' => self::cloneTreeNodes($node['children']),
            ];
        }

        return $out;
    }

    private static function wouldCreateCycle(int $id, int $parentId, AP_DB $db): bool
    {
        $seen = [$id => true];
        $current = $parentId;
        $guard = 0;
        while ($current > 0 && $guard < 100) {
            if (isset($seen[$current])) {
                return true;
            }
            $seen[$current] = true;
            $parent = self::get($current, $db);
            if ($parent === null) {
                break;
            }
            $current = $parent->comment_parent;
            $guard++;
        }

        return false;
    }

    private static function sanitizeKey(string $key): string
    {
        $key = strtolower($key);
        $key = preg_replace('/[^a-z0-9_\-]/', '', $key) ?? '';

        return $key;
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    private static function nowLocal(): string
    {
        return date('Y-m-d H:i:s');
    }

    private static function nowGmt(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    private static function localToGmt(string $local): ?string
    {
        $ts = strtotime($local);
        if ($ts === false) {
            return null;
        }

        return gmdate('Y-m-d H:i:s', $ts);
    }

    private static function resolveDb(?AP_DB $db): AP_DB
    {
        if ($db instanceof AP_DB) {
            return $db;
        }

        if (function_exists('ap_db')) {
            return ap_db();
        }

        throw new RuntimeException('No database connection available for comment operations.');
    }
}
