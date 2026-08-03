<?php

/**
 * AgoraPress user groups (forum permission foundation).
 *
 * Groups live in `{prefix}groups` with membership in `{prefix}group_members`.
 * System groups (guests, registered, administrators, global_moderators) are
 * seeded on install and participate in per-forum ACL resolution via
 * {@see AP_Forum_Permissions}. Guests/registered/administrators/global mods
 * also resolve as virtual memberships when checking permissions.
 *
 * Group types: open | closed | hidden | system
 * Member roles: member | moderator | leader
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * User group registry and membership API.
 */
class AP_Group
{
    // -------------------------------------------------------------------------
    // Constants
    // -------------------------------------------------------------------------

    public const TYPE_OPEN = 'open';

    public const TYPE_CLOSED = 'closed';

    public const TYPE_HIDDEN = 'hidden';

    public const TYPE_SYSTEM = 'system';

    public const ROLE_MEMBER = 'member';

    public const ROLE_MODERATOR = 'moderator';

    public const ROLE_LEADER = 'leader';

    /** System group slug: not logged in. */
    public const SLUG_GUESTS = 'guests';

    /** System group slug: any logged-in user. */
    public const SLUG_REGISTERED = 'registered';

    /** System group slug: site administrators (manage_forums / administrator role). */
    public const SLUG_ADMINISTRATORS = 'administrators';

    /** System group slug: global forum moderators (moderate_forums). */
    public const SLUG_GLOBAL_MODERATORS = 'global_moderators';

    /** @var array<int, object>|null Request-local group cache by id. */
    private static ?array $groupById = null;

    /** @var array<string, object>|null Request-local group cache by slug. */
    private static ?array $groupBySlug = null;

    // -------------------------------------------------------------------------
    // Types / roles
    // -------------------------------------------------------------------------

    /**
     * @return list<string>
     */
    public static function groupTypes(): array
    {
        return [
            self::TYPE_OPEN,
            self::TYPE_CLOSED,
            self::TYPE_HIDDEN,
            self::TYPE_SYSTEM,
        ];
    }

    /**
     * @return list<string>
     */
    public static function memberRoles(): array
    {
        return [
            self::ROLE_MEMBER,
            self::ROLE_MODERATOR,
            self::ROLE_LEADER,
        ];
    }

    public static function normalizeGroupType(string $type): string
    {
        $type = self::sanitizeKey($type);

        return in_array($type, self::groupTypes(), true) ? $type : self::TYPE_OPEN;
    }

    public static function normalizeMemberRole(string $role): string
    {
        $role = self::sanitizeKey($role);

        return in_array($role, self::memberRoles(), true) ? $role : self::ROLE_MEMBER;
    }

    /**
     * @return list<array{slug: string, name: string, desc: string}>
     */
    public static function systemGroupDefinitions(): array
    {
        return [
            [
                'slug' => self::SLUG_GUESTS,
                'name' => 'Guests',
                'desc' => 'Visitors who are not logged in.',
            ],
            [
                'slug' => self::SLUG_REGISTERED,
                'name' => 'Registered Users',
                'desc' => 'All logged-in members (virtual membership).',
            ],
            [
                'slug' => self::SLUG_ADMINISTRATORS,
                'name' => 'Administrators',
                'desc' => 'Users with the administrator role or manage_forums capability.',
            ],
            [
                'slug' => self::SLUG_GLOBAL_MODERATORS,
                'name' => 'Global Moderators',
                'desc' => 'Users with the moderate_forums capability.',
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // System groups / defaults
    // -------------------------------------------------------------------------

    /**
     * Ensure built-in system groups exist (idempotent).
     *
     * @return array<string, int> slug => group_id
     */
    public static function ensureSystemGroups(?AP_DB $db = null): array
    {
        $db = self::resolveDb($db);
        $ids = [];

        foreach (self::systemGroupDefinitions() as $def) {
            $existing = self::getBySlug($def['slug'], $db);
            if ($existing !== null) {
                $ids[$def['slug']] = (int) $existing->group_id;
                continue;
            }

            $id = self::create([
                'group_name' => $def['name'],
                'group_slug' => $def['slug'],
                'group_desc' => $def['desc'],
                'group_type' => self::TYPE_SYSTEM,
            ], $db);

            if ($id > 0) {
                $ids[$def['slug']] = $id;
            }
        }

        return $ids;
    }

    // -------------------------------------------------------------------------
    // CRUD
    // -------------------------------------------------------------------------

    /**
     * Fetch a group by ID.
     */
    public static function get(int $id, ?AP_DB $db = null): ?object
    {
        if ($id < 1) {
            return null;
        }

        if (self::$groupById !== null && isset(self::$groupById[$id])) {
            return self::$groupById[$id];
        }

        $db = self::resolveDb($db);
        $table = $db->quoteIdentifier($db->table('groups'));
        $row = $db->getRow(
            'SELECT * FROM ' . $table . ' WHERE ' . $db->quoteIdentifier('group_id') . ' = ?',
            [$id]
        );

        if ($row === null) {
            return null;
        }

        $row = self::normalizeRow($row);
        self::cacheGroup($row);

        return $row;
    }

    /**
     * Fetch a group by slug.
     */
    public static function getBySlug(string $slug, ?AP_DB $db = null): ?object
    {
        $slug = self::sanitizeSlug($slug);
        if ($slug === '') {
            return null;
        }

        if (self::$groupBySlug !== null && isset(self::$groupBySlug[$slug])) {
            return self::$groupBySlug[$slug];
        }

        $db = self::resolveDb($db);
        $table = $db->quoteIdentifier($db->table('groups'));
        $row = $db->getRow(
            'SELECT * FROM ' . $table . ' WHERE ' . $db->quoteIdentifier('group_slug') . ' = ? LIMIT 1',
            [$slug]
        );

        if ($row === null) {
            return null;
        }

        $row = self::normalizeRow($row);
        self::cacheGroup($row);

        return $row;
    }

    /**
     * Create a group. Returns new group_id or 0 on failure.
     *
     * @param array<string, mixed> $data group_name (required), group_slug, group_desc, group_type
     */
    public static function create(array $data, ?AP_DB $db = null): int
    {
        $db = self::resolveDb($db);

        $name = trim((string) ($data['group_name'] ?? $data['name'] ?? ''));
        if ($name === '') {
            return 0;
        }
        $name = self::clipString($name, 255);

        $type = self::normalizeGroupType((string) ($data['group_type'] ?? $data['type'] ?? self::TYPE_OPEN));
        $desc = str_replace("\0", '', (string) ($data['group_desc'] ?? $data['description'] ?? ''));

        $slugSource = (string) ($data['group_slug'] ?? $data['slug'] ?? $name);
        $slug = self::sanitizeSlug($slugSource);
        if ($slug === '') {
            $slug = 'group';
        }
        $slug = self::uniqueSlug($slug, 0, $db);

        $now = self::now();
        $row = [
            'group_name' => $name,
            'group_slug' => $slug,
            'group_desc' => $desc,
            'group_type' => $type,
            'member_count' => 0,
            'created_at' => $now,
        ];

        if (function_exists('ap_do_action')) {
            ap_do_action('ap_pre_group_create', $row);
        }

        $result = $db->insert('groups', $row);
        if ($result === false) {
            return 0;
        }

        $id = (int) $db->lastInsertId();
        if ($id < 1) {
            return 0;
        }

        self::flushCache();

        if (function_exists('ap_do_action')) {
            ap_do_action('ap_group_created', $id, self::get($id, $db));
        }

        return $id;
    }

    /**
     * Update a group. Returns true on success.
     *
     * @param array<string, mixed> $data
     */
    public static function update(int $id, array $data, ?AP_DB $db = null): bool
    {
        if ($id < 1 || $data === []) {
            return false;
        }

        $db = self::resolveDb($db);
        $existing = self::get($id, $db);
        if ($existing === null) {
            return false;
        }

        // System groups: allow name/desc edits, not type/slug demotion.
        $isSystem = (string) $existing->group_type === self::TYPE_SYSTEM
            || self::isSystemSlug((string) $existing->group_slug);

        $update = [];

        if (array_key_exists('group_name', $data) || array_key_exists('name', $data)) {
            $name = trim((string) ($data['group_name'] ?? $data['name'] ?? ''));
            if ($name === '') {
                return false;
            }
            $update['group_name'] = self::clipString($name, 255);
        }

        if (array_key_exists('group_desc', $data) || array_key_exists('description', $data)) {
            $update['group_desc'] = str_replace(
                "\0",
                '',
                (string) ($data['group_desc'] ?? $data['description'] ?? '')
            );
        }

        if (!$isSystem && (array_key_exists('group_type', $data) || array_key_exists('type', $data))) {
            $update['group_type'] = self::normalizeGroupType(
                (string) ($data['group_type'] ?? $data['type'] ?? self::TYPE_OPEN)
            );
            // Never promote arbitrary groups to system via update.
            if ($update['group_type'] === self::TYPE_SYSTEM) {
                $update['group_type'] = self::TYPE_OPEN;
            }
        }

        if (!$isSystem && (array_key_exists('group_slug', $data) || array_key_exists('slug', $data))) {
            $slugSource = (string) ($data['group_slug'] ?? $data['slug'] ?? '');
            $slug = self::sanitizeSlug($slugSource);
            if ($slug === '') {
                $slug = self::sanitizeSlug((string) ($update['group_name'] ?? $existing->group_name));
            }
            if ($slug === '') {
                $slug = 'group';
            }
            // Block renaming onto reserved system slugs.
            if (self::isSystemSlug($slug)) {
                return false;
            }
            $update['group_slug'] = self::uniqueSlug($slug, $id, $db);
        }

        if ($update === []) {
            return true;
        }

        if (function_exists('ap_do_action')) {
            ap_do_action('ap_pre_group_update', $id, $update);
        }

        $ok = $db->update('groups', $update, ['group_id' => $id]);
        if ($ok === false) {
            return false;
        }

        self::flushCache();

        if (function_exists('ap_do_action')) {
            ap_do_action('ap_group_updated', $id, self::get($id, $db));
        }

        return true;
    }

    /**
     * Delete a group and its memberships. System groups cannot be deleted.
     * Forum permission rows for the group are also removed when the table exists.
     */
    public static function delete(int $id, ?AP_DB $db = null): bool
    {
        if ($id < 1) {
            return false;
        }

        $db = self::resolveDb($db);
        $group = self::get($id, $db);
        if ($group === null) {
            return false;
        }

        if (
            (string) $group->group_type === self::TYPE_SYSTEM
            || self::isSystemSlug((string) $group->group_slug)
        ) {
            return false;
        }

        if (function_exists('ap_do_action')) {
            ap_do_action('ap_pre_group_delete', $id, $group);
        }

        $db->delete('group_members', ['group_id' => $id]);

        // Drop ACL rows for this group when the permissions table is present.
        try {
            $permTable = $db->table('forum_permissions');
            if ($permTable !== '') {
                $db->delete('forum_permissions', ['group_id' => $id]);
            }
        } catch (Throwable) {
            // Table may not exist on partial schemas; ignore.
        }

        $ok = $db->delete('groups', ['group_id' => $id]);
        if ($ok === false) {
            return false;
        }

        self::flushCache();
        if (class_exists('AP_Forum_Permissions', false)) {
            AP_Forum_Permissions::flushCache();
        }

        if (function_exists('ap_do_action')) {
            ap_do_action('ap_group_deleted', $id);
        }

        return true;
    }

    /**
     * List groups.
     *
     * @param array<string, mixed> $args type, search, exclude_system, orderby, order, limit, offset
     *
     * @return list<object>
     */
    public static function query(array $args = [], ?AP_DB $db = null): array
    {
        $db = self::resolveDb($db);
        $table = $db->quoteIdentifier($db->table('groups'));

        $where = [];
        $params = [];

        if (!empty($args['type'])) {
            $type = self::normalizeGroupType((string) $args['type']);
            $where[] = $db->quoteIdentifier('group_type') . ' = ?';
            $params[] = $type;
        }

        if (!empty($args['exclude_system'])) {
            $where[] = $db->quoteIdentifier('group_type') . ' <> ?';
            $params[] = self::TYPE_SYSTEM;
        }

        if (!empty($args['search'])) {
            $like = '%' . self::escapeLike((string) $args['search']) . '%';
            $where[] = '('
                . $db->quoteIdentifier('group_name') . ' LIKE ? OR '
                . $db->quoteIdentifier('group_slug') . ' LIKE ? OR '
                . $db->quoteIdentifier('group_desc') . ' LIKE ?'
                . ')';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $sql = 'SELECT * FROM ' . $table;
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $orderby = strtolower((string) ($args['orderby'] ?? 'name'));
        $orderCol = match ($orderby) {
            'slug' => 'group_slug',
            'type' => 'group_type',
            'members', 'member_count' => 'member_count',
            'created', 'created_at' => 'created_at',
            'id', 'group_id' => 'group_id',
            default => 'group_name',
        };
        $order = strtoupper((string) ($args['order'] ?? 'ASC')) === 'DESC' ? 'DESC' : 'ASC';
        $sql .= ' ORDER BY ' . $db->quoteIdentifier($orderCol) . ' ' . $order;

        $limit = isset($args['limit']) ? max(0, (int) $args['limit']) : 0;
        $offset = isset($args['offset']) ? max(0, (int) $args['offset']) : 0;
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit;
            if ($offset > 0) {
                $sql .= ' OFFSET ' . $offset;
            }
        }

        $rows = $db->getResults($sql, $params);
        $out = [];
        foreach ($rows as $row) {
            $norm = self::normalizeRow($row);
            self::cacheGroup($norm);
            $out[] = $norm;
        }

        return $out;
    }

    /**
     * Count groups matching query args (ignores limit/offset).
     *
     * @param array<string, mixed> $args
     */
    public static function count(array $args = [], ?AP_DB $db = null): int
    {
        $db = self::resolveDb($db);
        $table = $db->quoteIdentifier($db->table('groups'));

        $where = [];
        $params = [];

        if (!empty($args['type'])) {
            $where[] = $db->quoteIdentifier('group_type') . ' = ?';
            $params[] = self::normalizeGroupType((string) $args['type']);
        }
        if (!empty($args['exclude_system'])) {
            $where[] = $db->quoteIdentifier('group_type') . ' <> ?';
            $params[] = self::TYPE_SYSTEM;
        }
        if (!empty($args['search'])) {
            $like = '%' . self::escapeLike((string) $args['search']) . '%';
            $where[] = '('
                . $db->quoteIdentifier('group_name') . ' LIKE ? OR '
                . $db->quoteIdentifier('group_slug') . ' LIKE ? OR '
                . $db->quoteIdentifier('group_desc') . ' LIKE ?'
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

    // -------------------------------------------------------------------------
    // Membership
    // -------------------------------------------------------------------------

    /**
     * Add a user to a group. Returns membership_id or 0 on failure.
     * Re-adding updates the member role.
     */
    public static function addMember(
        int $groupId,
        int $userId,
        string $role = self::ROLE_MEMBER,
        ?AP_DB $db = null
    ): int {
        if ($groupId < 1 || $userId < 1) {
            return 0;
        }

        $db = self::resolveDb($db);
        $group = self::get($groupId, $db);
        if ($group === null) {
            return 0;
        }

        // Guests system group has no real members.
        if ((string) $group->group_slug === self::SLUG_GUESTS) {
            return 0;
        }

        $role = self::normalizeMemberRole($role);
        $existing = self::getMembership($groupId, $userId, $db);
        if ($existing !== null) {
            if ((string) $existing->member_role !== $role) {
                $db->update(
                    'group_members',
                    ['member_role' => $role],
                    [
                        'group_id' => $groupId,
                        'user_id' => $userId,
                    ]
                );
            }

            return (int) $existing->membership_id;
        }

        $ok = $db->insert('group_members', [
            'group_id' => $groupId,
            'user_id' => $userId,
            'member_role' => $role,
            'joined_at' => self::now(),
        ]);
        if ($ok === false) {
            return 0;
        }

        $membershipId = (int) $db->lastInsertId();
        self::recountMembers($groupId, $db);
        if (class_exists('AP_Forum_Permissions', false)) {
            AP_Forum_Permissions::flushUserCache($userId);
        }

        if (function_exists('ap_do_action')) {
            ap_do_action('ap_group_member_added', $groupId, $userId, $role);
        }

        return $membershipId;
    }

    /**
     * Remove a user from a group.
     */
    public static function removeMember(int $groupId, int $userId, ?AP_DB $db = null): bool
    {
        if ($groupId < 1 || $userId < 1) {
            return false;
        }

        $db = self::resolveDb($db);
        $existing = self::getMembership($groupId, $userId, $db);
        if ($existing === null) {
            return false;
        }

        $ok = $db->delete('group_members', [
            'group_id' => $groupId,
            'user_id' => $userId,
        ]);
        if ($ok === false) {
            return false;
        }

        self::recountMembers($groupId, $db);
        if (class_exists('AP_Forum_Permissions', false)) {
            AP_Forum_Permissions::flushUserCache($userId);
        }

        if (function_exists('ap_do_action')) {
            ap_do_action('ap_group_member_removed', $groupId, $userId);
        }

        return true;
    }

    /**
     * Set a member's role within a group.
     */
    public static function setMemberRole(
        int $groupId,
        int $userId,
        string $role,
        ?AP_DB $db = null
    ): bool {
        if ($groupId < 1 || $userId < 1) {
            return false;
        }

        $db = self::resolveDb($db);
        $existing = self::getMembership($groupId, $userId, $db);
        if ($existing === null) {
            return false;
        }

        $role = self::normalizeMemberRole($role);
        $ok = $db->update(
            'group_members',
            ['member_role' => $role],
            [
                'group_id' => $groupId,
                'user_id' => $userId,
            ]
        );

        return $ok !== false;
    }

    /**
     * Whether a user is an explicit member of a group (not virtual).
     */
    public static function isMember(int $groupId, int $userId, ?AP_DB $db = null): bool
    {
        return self::getMembership($groupId, $userId, $db) !== null;
    }

    /**
     * Membership row or null.
     */
    public static function getMembership(int $groupId, int $userId, ?AP_DB $db = null): ?object
    {
        if ($groupId < 1 || $userId < 1) {
            return null;
        }

        $db = self::resolveDb($db);
        $table = $db->quoteIdentifier($db->table('group_members'));
        $row = $db->getRow(
            'SELECT * FROM ' . $table
            . ' WHERE ' . $db->quoteIdentifier('group_id') . ' = ?'
            . ' AND ' . $db->quoteIdentifier('user_id') . ' = ? LIMIT 1',
            [$groupId, $userId]
        );

        if ($row === null) {
            return null;
        }

        $row->membership_id = (int) ($row->membership_id ?? 0);
        $row->group_id = (int) ($row->group_id ?? 0);
        $row->user_id = (int) ($row->user_id ?? 0);
        $row->member_role = self::normalizeMemberRole((string) ($row->member_role ?? self::ROLE_MEMBER));

        return $row;
    }

    /**
     * Members of a group.
     *
     * @param array<string, mixed> $args role, limit, offset, order
     *
     * @return list<object>
     */
    public static function getMembers(int $groupId, array $args = [], ?AP_DB $db = null): array
    {
        if ($groupId < 1) {
            return [];
        }

        $db = self::resolveDb($db);
        $table = $db->quoteIdentifier($db->table('group_members'));

        $where = [$db->quoteIdentifier('group_id') . ' = ?'];
        $params = [$groupId];

        if (!empty($args['role'])) {
            $where[] = $db->quoteIdentifier('member_role') . ' = ?';
            $params[] = self::normalizeMemberRole((string) $args['role']);
        }

        $sql = 'SELECT * FROM ' . $table . ' WHERE ' . implode(' AND ', $where);
        $order = strtoupper((string) ($args['order'] ?? 'ASC')) === 'DESC' ? 'DESC' : 'ASC';
        $sql .= ' ORDER BY ' . $db->quoteIdentifier('joined_at') . ' ' . $order
            . ', ' . $db->quoteIdentifier('membership_id') . ' ' . $order;

        $limit = isset($args['limit']) ? max(0, (int) $args['limit']) : 0;
        $offset = isset($args['offset']) ? max(0, (int) $args['offset']) : 0;
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit;
            if ($offset > 0) {
                $sql .= ' OFFSET ' . $offset;
            }
        }

        $rows = $db->getResults($sql, $params);
        $out = [];
        foreach ($rows as $row) {
            $row->membership_id = (int) ($row->membership_id ?? 0);
            $row->group_id = (int) ($row->group_id ?? 0);
            $row->user_id = (int) ($row->user_id ?? 0);
            $row->member_role = self::normalizeMemberRole((string) ($row->member_role ?? self::ROLE_MEMBER));
            $out[] = $row;
        }

        return $out;
    }

    /**
     * Groups a user belongs to (explicit membership only).
     *
     * @return list<object> Group rows with member_role attached
     */
    public static function getUserGroups(int $userId, ?AP_DB $db = null): array
    {
        if ($userId < 1) {
            return [];
        }

        $db = self::resolveDb($db);
        $g = $db->quoteIdentifier($db->table('groups'));
        $m = $db->quoteIdentifier($db->table('group_members'));

        $sql = 'SELECT g.*, m.member_role, m.joined_at AS membership_joined_at, m.membership_id'
            . ' FROM ' . $m . ' m'
            . ' INNER JOIN ' . $g . ' g ON g.' . $db->quoteIdentifier('group_id')
            . ' = m.' . $db->quoteIdentifier('group_id')
            . ' WHERE m.' . $db->quoteIdentifier('user_id') . ' = ?'
            . ' ORDER BY g.' . $db->quoteIdentifier('group_name') . ' ASC';

        $rows = $db->getResults($sql, [$userId]);
        $out = [];
        foreach ($rows as $row) {
            $norm = self::normalizeRow($row);
            $norm->member_role = self::normalizeMemberRole((string) ($row->member_role ?? self::ROLE_MEMBER));
            $norm->membership_id = (int) ($row->membership_id ?? 0);
            $out[] = $norm;
        }

        return $out;
    }

    /**
     * Effective group IDs for permission checks (explicit + virtual system groups).
     *
     * @return list<int>
     */
    public static function getEffectiveGroupIds(int $userId, ?AP_DB $db = null): array
    {
        $db = self::resolveDb($db);
        self::ensureSystemGroups($db);

        $ids = [];
        $add = static function (string $slug) use (&$ids, $db): void {
            $g = self::getBySlug($slug, $db);
            if ($g !== null) {
                $ids[] = (int) $g->group_id;
            }
        };

        if ($userId < 1) {
            $add(self::SLUG_GUESTS);

            return array_values(array_unique($ids));
        }

        $add(self::SLUG_REGISTERED);

        // Explicit memberships.
        foreach (self::getUserGroups($userId, $db) as $group) {
            $ids[] = (int) $group->group_id;
        }

        // Virtual: administrators (role or manage_forums).
        if (self::userIsAdministrator($userId, $db)) {
            $add(self::SLUG_ADMINISTRATORS);
        }

        // Virtual: global moderators (moderate_forums, unless already admin-only path).
        if (self::userIsGlobalModerator($userId, $db)) {
            $add(self::SLUG_GLOBAL_MODERATORS);
        }

        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
        sort($ids);

        return $ids;
    }

    /**
     * Whether the user maps to the administrators system group.
     */
    public static function userIsAdministrator(int $userId, ?AP_DB $db = null): bool
    {
        if ($userId < 1) {
            return false;
        }

        if (class_exists('AP_Roles', false)) {
            if (AP_Roles::userCan($userId, 'manage_forums', null, $db)) {
                return true;
            }
            $roles = AP_Roles::getUserRoles($userId, $db);
            if (in_array('administrator', $roles, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the user maps to the global_moderators system group.
     */
    public static function userIsGlobalModerator(int $userId, ?AP_DB $db = null): bool
    {
        if ($userId < 1) {
            return false;
        }

        if (class_exists('AP_Roles', false)) {
            return AP_Roles::userCan($userId, 'moderate_forums', null, $db)
                || AP_Roles::userCan($userId, 'manage_forums', null, $db);
        }

        return false;
    }

    // -------------------------------------------------------------------------
    // Cache
    // -------------------------------------------------------------------------

    public static function flushCache(): void
    {
        self::$groupById = null;
        self::$groupBySlug = null;
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    private static function resolveDb(?AP_DB $db): AP_DB
    {
        if ($db instanceof AP_DB) {
            return $db;
        }
        if (function_exists('ap_db')) {
            return ap_db();
        }

        throw new RuntimeException('Database connection is not available.');
    }

    private static function isSystemSlug(string $slug): bool
    {
        $slug = self::sanitizeSlug($slug);

        return in_array($slug, [
            self::SLUG_GUESTS,
            self::SLUG_REGISTERED,
            self::SLUG_ADMINISTRATORS,
            self::SLUG_GLOBAL_MODERATORS,
        ], true);
    }

    private static function normalizeRow(object $row): object
    {
        $row->group_id = (int) ($row->group_id ?? 0);
        $row->group_name = (string) ($row->group_name ?? '');
        $row->group_slug = (string) ($row->group_slug ?? '');
        $row->group_desc = (string) ($row->group_desc ?? '');
        $row->group_type = self::normalizeGroupType((string) ($row->group_type ?? self::TYPE_OPEN));
        $row->member_count = (int) ($row->member_count ?? 0);
        $row->created_at = (string) ($row->created_at ?? '');

        return $row;
    }

    private static function cacheGroup(object $row): void
    {
        if (self::$groupById === null) {
            self::$groupById = [];
        }
        if (self::$groupBySlug === null) {
            self::$groupBySlug = [];
        }
        $id = (int) ($row->group_id ?? 0);
        $slug = (string) ($row->group_slug ?? '');
        if ($id > 0) {
            self::$groupById[$id] = $row;
        }
        if ($slug !== '') {
            self::$groupBySlug[$slug] = $row;
        }
    }

    private static function recountMembers(int $groupId, AP_DB $db): void
    {
        $table = $db->quoteIdentifier($db->table('group_members'));
        $count = (int) $db->getVar(
            'SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $db->quoteIdentifier('group_id') . ' = ?',
            [$groupId]
        );
        $db->update('groups', ['member_count' => $count], ['group_id' => $groupId]);
        self::flushCache();
    }

    private static function uniqueSlug(string $slug, int $excludeId, AP_DB $db): string
    {
        $base = $slug;
        $n = 2;
        while (true) {
            $existing = self::getBySlug($slug, $db);
            if ($existing === null || (int) $existing->group_id === $excludeId) {
                return $slug;
            }
            $slug = $base . '-' . $n;
            ++$n;
            if ($n > 1000) {
                return $base . '-' . bin2hex(random_bytes(3));
            }
        }
    }

    private static function sanitizeKey(string $key): string
    {
        $key = strtolower(trim($key));
        $key = preg_replace('/[^a-z0-9_\-]/', '', $key) ?? '';

        return $key;
    }

    private static function sanitizeSlug(string $slug): string
    {
        $slug = strtolower(trim($slug));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');
        if (function_exists('mb_substr')) {
            $slug = mb_substr($slug, 0, 200);
        } else {
            $slug = substr($slug, 0, 200);
        }

        return $slug;
    }

    private static function clipString(string $value, int $max): string
    {
        $value = str_replace("\0", '', $value);
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $max);
        }

        return substr($value, 0, $max);
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    private static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
