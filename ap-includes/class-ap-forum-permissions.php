<?php

/**
 * AgoraPress granular per-forum permissions (group × forum ACL).
 *
 * Rows in `{prefix}forum_permissions`:
 * - forum_id = 0 → global defaults
 * - forum_id > 0 → forum-specific override
 * - perm_setting: 1 = allow, 0 = deny
 *
 * Resolution for a user on a forum:
 * 1. Core cap `manage_forums` always allows.
 * 2. Collect effective groups (explicit membership + virtual system groups).
 * 3. For each group, forum-specific row overrides global (forum_id=0).
 * 4. Deny from any group wins; else allow if any group allows; else false.
 *
 * Integrated with {@see AP_Roles} (manage_forums / moderate_forums) and
 * {@see AP_Group} system groups.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * Forum ACL API.
 */
class AP_Forum_Permissions
{
    // -------------------------------------------------------------------------
    // Permission names
    // -------------------------------------------------------------------------

    public const PERM_VIEW = 'view_forum';

    public const PERM_READ = 'read_forum';

    public const PERM_POST_TOPICS = 'post_topics';

    public const PERM_POST_REPLIES = 'post_replies';

    public const PERM_EDIT_OWN = 'edit_own';

    public const PERM_DELETE_OWN = 'delete_own';

    public const PERM_ATTACH = 'attach_files';

    public const PERM_MODERATE = 'moderate_forum';

    public const PERM_STICKY = 'sticky_topics';

    public const PERM_ANNOUNCE = 'announce_topics';

    public const PERM_LOCK = 'lock_topics';

    public const PERM_MOVE = 'move_topics';

    public const SETTING_DENY = 0;

    public const SETTING_ALLOW = 1;

    /** Global defaults forum_id. */
    public const FORUM_GLOBAL = 0;

    /** @var array<string, array<int, array<int, int>>>|null cacheKey => forum_id => group_id => setting */
    private static ?array $matrixCache = null;

    /** @var array<string, bool> userId:forumId:perm => result */
    private static array $userCanCache = [];

    // -------------------------------------------------------------------------
    // Catalog
    // -------------------------------------------------------------------------

    /**
     * All known forum permission keys.
     *
     * @return list<string>
     */
    public static function allPermissions(): array
    {
        return [
            self::PERM_VIEW,
            self::PERM_READ,
            self::PERM_POST_TOPICS,
            self::PERM_POST_REPLIES,
            self::PERM_EDIT_OWN,
            self::PERM_DELETE_OWN,
            self::PERM_ATTACH,
            self::PERM_MODERATE,
            self::PERM_STICKY,
            self::PERM_ANNOUNCE,
            self::PERM_LOCK,
            self::PERM_MOVE,
        ];
    }

    /**
     * Human-readable labels for admin UIs.
     *
     * @return array<string, string>
     */
    public static function permissionLabels(): array
    {
        return [
            self::PERM_VIEW => 'View forum',
            self::PERM_READ => 'Read topics',
            self::PERM_POST_TOPICS => 'Create topics',
            self::PERM_POST_REPLIES => 'Post replies',
            self::PERM_EDIT_OWN => 'Edit own posts',
            self::PERM_DELETE_OWN => 'Delete own posts',
            self::PERM_ATTACH => 'Attach files',
            self::PERM_MODERATE => 'Moderate forum',
            self::PERM_STICKY => 'Sticky topics',
            self::PERM_ANNOUNCE => 'Announce topics',
            self::PERM_LOCK => 'Lock topics',
            self::PERM_MOVE => 'Move topics',
        ];
    }

    /**
     * Moderation-related permissions (also granted by moderate_forums core cap).
     *
     * @return list<string>
     */
    public static function moderationPermissions(): array
    {
        return [
            self::PERM_MODERATE,
            self::PERM_STICKY,
            self::PERM_ANNOUNCE,
            self::PERM_LOCK,
            self::PERM_MOVE,
            self::PERM_EDIT_OWN,
            self::PERM_DELETE_OWN,
        ];
    }

    public static function normalizePermission(string $perm): string
    {
        $perm = strtolower(trim($perm));
        $perm = preg_replace('/[^a-z0-9_]/', '', $perm) ?? '';
        if ($perm === '' || !in_array($perm, self::allPermissions(), true)) {
            return '';
        }

        return $perm;
    }

    // -------------------------------------------------------------------------
    // Defaults seed
    // -------------------------------------------------------------------------

    /**
     * Seed system groups + global default ACL (idempotent).
     *
     * Does not overwrite existing permission rows for a group × perm pair.
     */
    public static function ensureDefaults(?AP_DB $db = null): void
    {
        $db = self::resolveDb($db);

        if (!class_exists('AP_Group', false)) {
            return;
        }

        $groupIds = AP_Group::ensureSystemGroups($db);
        if ($groupIds === []) {
            return;
        }

        $guest = $groupIds[AP_Group::SLUG_GUESTS] ?? 0;
        $registered = $groupIds[AP_Group::SLUG_REGISTERED] ?? 0;
        $admins = $groupIds[AP_Group::SLUG_ADMINISTRATORS] ?? 0;
        $mods = $groupIds[AP_Group::SLUG_GLOBAL_MODERATORS] ?? 0;

        $guestPerms = [self::PERM_VIEW, self::PERM_READ];
        $registeredPerms = [
            self::PERM_VIEW,
            self::PERM_READ,
            self::PERM_POST_TOPICS,
            self::PERM_POST_REPLIES,
            self::PERM_EDIT_OWN,
            self::PERM_DELETE_OWN,
            self::PERM_ATTACH,
        ];
        $modPerms = array_values(array_unique(array_merge(
            $registeredPerms,
            self::moderationPermissions()
        )));
        $adminPerms = self::allPermissions();

        if ($guest > 0) {
            self::seedGroupDefaults(self::FORUM_GLOBAL, $guest, $guestPerms, $db);
        }
        if ($registered > 0) {
            self::seedGroupDefaults(self::FORUM_GLOBAL, $registered, $registeredPerms, $db);
        }
        if ($mods > 0) {
            self::seedGroupDefaults(self::FORUM_GLOBAL, $mods, $modPerms, $db);
        }
        if ($admins > 0) {
            self::seedGroupDefaults(self::FORUM_GLOBAL, $admins, $adminPerms, $db);
        }
    }

    /**
     * @param list<string> $perms
     */
    private static function seedGroupDefaults(int $forumId, int $groupId, array $perms, AP_DB $db): void
    {
        foreach ($perms as $perm) {
            $perm = self::normalizePermission($perm);
            if ($perm === '') {
                continue;
            }
            if (self::getRawSetting($forumId, $groupId, $perm, $db) !== null) {
                continue;
            }
            self::setPermission($forumId, $groupId, $perm, true, $db);
        }
    }

    // -------------------------------------------------------------------------
    // ACL CRUD
    // -------------------------------------------------------------------------

    /**
     * Set a single permission (allow or deny). Returns true on success.
     */
    public static function setPermission(
        int $forumId,
        int $groupId,
        string $perm,
        bool $allow,
        ?AP_DB $db = null
    ): bool {
        $forumId = max(0, $forumId);
        if ($groupId < 1) {
            return false;
        }
        $perm = self::normalizePermission($perm);
        if ($perm === '') {
            return false;
        }

        $db = self::resolveDb($db);
        $setting = $allow ? self::SETTING_ALLOW : self::SETTING_DENY;

        $existing = self::getRawSetting($forumId, $groupId, $perm, $db);
        if ($existing !== null) {
            if ($existing === $setting) {
                return true;
            }
            $ok = $db->update(
                'forum_permissions',
                ['perm_setting' => $setting],
                [
                    'forum_id' => $forumId,
                    'group_id' => $groupId,
                    'perm_name' => $perm,
                ]
            );
        } else {
            $ok = $db->insert('forum_permissions', [
                'forum_id' => $forumId,
                'group_id' => $groupId,
                'perm_name' => $perm,
                'perm_setting' => $setting,
            ]);
        }

        if ($ok === false) {
            return false;
        }

        self::flushCache();

        if (function_exists('ap_do_action')) {
            ap_do_action('ap_forum_permission_set', $forumId, $groupId, $perm, $allow);
        }

        return true;
    }

    /**
     * Remove a permission row (revert to inherit / no setting).
     */
    public static function removePermission(
        int $forumId,
        int $groupId,
        string $perm,
        ?AP_DB $db = null
    ): bool {
        $forumId = max(0, $forumId);
        if ($groupId < 1) {
            return false;
        }
        $perm = self::normalizePermission($perm);
        if ($perm === '') {
            return false;
        }

        $db = self::resolveDb($db);
        $ok = $db->delete('forum_permissions', [
            'forum_id' => $forumId,
            'group_id' => $groupId,
            'perm_name' => $perm,
        ]);
        if ($ok === false) {
            return false;
        }

        self::flushCache();

        return true;
    }

    /**
     * Replace all permission rows for a group on a forum.
     *
     * @param array<string, bool> $permissions perm_name => allow
     */
    public static function setGroupPermissions(
        int $forumId,
        int $groupId,
        array $permissions,
        ?AP_DB $db = null
    ): bool {
        $forumId = max(0, $forumId);
        if ($groupId < 1) {
            return false;
        }

        $db = self::resolveDb($db);

        // Clear existing rows for this forum+group, then insert.
        $db->delete('forum_permissions', [
            'forum_id' => $forumId,
            'group_id' => $groupId,
        ]);
        self::flushCache();

        $ok = true;
        foreach ($permissions as $perm => $allow) {
            $perm = self::normalizePermission((string) $perm);
            if ($perm === '') {
                continue;
            }
            if (!self::setPermission($forumId, $groupId, $perm, (bool) $allow, $db)) {
                $ok = false;
            }
        }

        return $ok;
    }

    /**
     * Permission map for one group on one forum (only stored rows).
     *
     * @return array<string, bool> perm => allow
     */
    public static function getGroupPermissions(int $forumId, int $groupId, ?AP_DB $db = null): array
    {
        $forumId = max(0, $forumId);
        if ($groupId < 1) {
            return [];
        }

        $db = self::resolveDb($db);
        $table = $db->quoteIdentifier($db->table('forum_permissions'));
        $rows = $db->getResults(
            'SELECT ' . $db->quoteIdentifier('perm_name') . ', '
            . $db->quoteIdentifier('perm_setting')
            . ' FROM ' . $table
            . ' WHERE ' . $db->quoteIdentifier('forum_id') . ' = ?'
            . ' AND ' . $db->quoteIdentifier('group_id') . ' = ?',
            [$forumId, $groupId]
        );

        $out = [];
        foreach ($rows as $row) {
            $perm = self::normalizePermission((string) ($row->perm_name ?? ''));
            if ($perm === '') {
                continue;
            }
            $out[$perm] = ((int) ($row->perm_setting ?? 0)) === self::SETTING_ALLOW;
        }

        return $out;
    }

    /**
     * Full matrix for a forum: group_id => (perm => allow).
     * When $includeGlobal is true, merges global defaults under forum-specific rows.
     *
     * @return array<int, array<string, bool>>
     */
    public static function getForumMatrix(
        int $forumId,
        bool $includeGlobal = true,
        ?AP_DB $db = null
    ): array {
        $forumId = max(0, $forumId);
        $db = self::resolveDb($db);

        $matrix = [];

        if ($includeGlobal && $forumId !== self::FORUM_GLOBAL) {
            foreach (self::loadRows(self::FORUM_GLOBAL, $db) as $row) {
                $gid = (int) $row->group_id;
                $perm = self::normalizePermission((string) $row->perm_name);
                if ($gid < 1 || $perm === '') {
                    continue;
                }
                $matrix[$gid][$perm] = ((int) $row->perm_setting) === self::SETTING_ALLOW;
            }
        }

        foreach (self::loadRows($forumId, $db) as $row) {
            $gid = (int) $row->group_id;
            $perm = self::normalizePermission((string) $row->perm_name);
            if ($gid < 1 || $perm === '') {
                continue;
            }
            $matrix[$gid][$perm] = ((int) $row->perm_setting) === self::SETTING_ALLOW;
        }

        return $matrix;
    }

    /**
     * Delete all ACL rows for a forum (not global).
     */
    public static function deleteForForum(int $forumId, ?AP_DB $db = null): bool
    {
        if ($forumId < 1) {
            return false;
        }

        $db = self::resolveDb($db);
        $ok = $db->delete('forum_permissions', ['forum_id' => $forumId]);
        self::flushCache();

        return $ok !== false;
    }

    // -------------------------------------------------------------------------
    // Resolution
    // -------------------------------------------------------------------------

    /**
     * Whether a user may perform a permission in a forum.
     *
     * @param int $userId 0 = guest
     */
    public static function userCan(
        int $userId,
        int $forumId,
        string $perm,
        ?AP_DB $db = null
    ): bool {
        $perm = self::normalizePermission($perm);
        if ($perm === '') {
            return false;
        }

        $forumId = max(0, $forumId);
        $cacheKey = $userId . ':' . $forumId . ':' . $perm;
        if (array_key_exists($cacheKey, self::$userCanCache)) {
            return self::$userCanCache[$cacheKey];
        }

        $db = self::resolveDb($db);

        // Superuser: manage_forums / administrator.
        if ($userId > 0 && class_exists('AP_Roles', false)) {
            if (AP_Roles::userCan($userId, 'manage_forums', null, $db)) {
                self::$userCanCache[$cacheKey] = true;

                return true;
            }

            // Site-wide moderators get moderation-family permissions everywhere.
            if (
                in_array($perm, self::moderationPermissions(), true)
                && AP_Roles::userCan($userId, 'moderate_forums', null, $db)
            ) {
                self::$userCanCache[$cacheKey] = true;

                return true;
            }
        }

        if (!class_exists('AP_Group', false)) {
            self::$userCanCache[$cacheKey] = false;

            return false;
        }

        // Ensure defaults exist so a fresh install has sensible ACL.
        self::ensureDefaults($db);

        $groupIds = AP_Group::getEffectiveGroupIds($userId, $db);
        if ($groupIds === []) {
            self::$userCanCache[$cacheKey] = false;

            return false;
        }

        $result = self::resolveForGroups($groupIds, $forumId, $perm, $db);
        self::$userCanCache[$cacheKey] = $result;

        return $result;
    }

    /**
     * Capability check for the currently logged-in user.
     */
    public static function currentUserCan(int $forumId, string $perm, ?AP_DB $db = null): bool
    {
        $userId = 0;
        if (function_exists('ap_get_current_user_id')) {
            $userId = ap_get_current_user_id($db);
        } elseif (class_exists('AP_Session', false)) {
            $userId = AP_Session::getCurrentUserId($db);
        }

        return self::userCan($userId, $forumId, $perm, $db);
    }

    /**
     * Effective permission map for a user on a forum (all known perms).
     *
     * @return array<string, bool>
     */
    public static function getUserPermissions(int $userId, int $forumId, ?AP_DB $db = null): array
    {
        $out = [];
        foreach (self::allPermissions() as $perm) {
            $out[$perm] = self::userCan($userId, $forumId, $perm, $db);
        }

        return $out;
    }

    /**
     * Whether the user may view the forum (status-aware helper).
     *
     * Hidden forums require view_forum; open/closed still need view_forum.
     * Closed does not block view/read by default.
     */
    public static function userCanViewForum(int $userId, int $forumId, ?AP_DB $db = null): bool
    {
        if ($forumId < 1) {
            return false;
        }

        $db = self::resolveDb($db);

        if (class_exists('AP_Forum', false)) {
            $forum = AP_Forum::getForum($forumId, $db);
            if ($forum === null) {
                return false;
            }
            // Categories are containers; view if any child would be viewable is left to UI.
            // Hidden forums still respect ACL (admins/groups with view).
        }

        return self::userCan($userId, $forumId, self::PERM_VIEW, $db);
    }

    /**
     * Whether the user may create a topic in the forum.
     */
    public static function userCanPostTopic(int $userId, int $forumId, ?AP_DB $db = null): bool
    {
        return self::userCan($userId, $forumId, self::PERM_POST_TOPICS, $db);
    }

    /**
     * Whether the user may reply in the forum.
     */
    public static function userCanPostReply(int $userId, int $forumId, ?AP_DB $db = null): bool
    {
        return self::userCan($userId, $forumId, self::PERM_POST_REPLIES, $db);
    }

    /**
     * Whether the user may moderate the forum.
     */
    public static function userCanModerate(int $userId, int $forumId, ?AP_DB $db = null): bool
    {
        return self::userCan($userId, $forumId, self::PERM_MODERATE, $db);
    }

    // -------------------------------------------------------------------------
    // Cache
    // -------------------------------------------------------------------------

    public static function flushCache(): void
    {
        self::$matrixCache = null;
        self::$userCanCache = [];
    }

    public static function flushUserCache(?int $userId = null): void
    {
        if ($userId === null) {
            self::$userCanCache = [];

            return;
        }
        $prefix = $userId . ':';
        foreach (array_keys(self::$userCanCache) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset(self::$userCanCache[$key]);
            }
        }
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

    /**
     * Resolve ACL across effective groups.
     *
     * - Explicit group deny always wins.
     * - Explicit group allow wins over virtual (guests/registered) deny — so a VIP
     *   group can open a forum that is closed to the general registered set.
     * - Virtual guests/registered deny applies when no explicit allow exists.
     * - Otherwise any allow (virtual or explicit) grants access.
     *
     * @param list<int> $groupIds
     */
    private static function resolveForGroups(
        array $groupIds,
        int $forumId,
        string $perm,
        AP_DB $db
    ): bool {
        $explicitAllow = false;
        $explicitDeny = false;
        $virtualAllow = false;
        $virtualDeny = false;

        foreach ($groupIds as $groupId) {
            $setting = self::effectiveSetting($forumId, $groupId, $perm, $db);
            if ($setting === null) {
                continue;
            }

            $virtual = self::isSoftVirtualGroup($groupId, $db);
            if ($setting === self::SETTING_DENY) {
                if ($virtual) {
                    $virtualDeny = true;
                } else {
                    $explicitDeny = true;
                }
            } elseif ($setting === self::SETTING_ALLOW) {
                if ($virtual) {
                    $virtualAllow = true;
                } else {
                    $explicitAllow = true;
                }
            }
        }

        if ($explicitDeny) {
            return false;
        }
        if ($explicitAllow) {
            return true;
        }
        if ($virtualDeny) {
            return false;
        }

        return $virtualAllow;
    }

    /**
     * Guests + registered are soft virtual groups: their deny can be overridden
     * by an allow on an explicit (custom or elevated system) group.
     */
    private static function isSoftVirtualGroup(int $groupId, AP_DB $db): bool
    {
        if (!class_exists('AP_Group', false)) {
            return false;
        }

        $group = AP_Group::get($groupId, $db);
        if ($group === null) {
            return false;
        }

        $slug = (string) ($group->group_slug ?? '');

        return $slug === AP_Group::SLUG_GUESTS || $slug === AP_Group::SLUG_REGISTERED;
    }

    /**
     * Forum-specific setting if present, else global; null if neither.
     */
    private static function effectiveSetting(
        int $forumId,
        int $groupId,
        string $perm,
        AP_DB $db
    ): ?int {
        if ($forumId > 0) {
            $local = self::getRawSetting($forumId, $groupId, $perm, $db);
            if ($local !== null) {
                return $local;
            }
        }

        return self::getRawSetting(self::FORUM_GLOBAL, $groupId, $perm, $db);
    }

    private static function getRawSetting(
        int $forumId,
        int $groupId,
        string $perm,
        AP_DB $db
    ): ?int {
        $map = self::loadMatrix($forumId, $db);
        if (!isset($map[$groupId][$perm])) {
            return null;
        }

        return $map[$groupId][$perm];
    }

    /**
     * @return array<int, array<string, int>> group_id => perm => setting
     */
    private static function loadMatrix(int $forumId, AP_DB $db): array
    {
        $key = (string) $forumId;
        if (self::$matrixCache !== null && isset(self::$matrixCache[$key])) {
            return self::$matrixCache[$key];
        }

        if (self::$matrixCache === null) {
            self::$matrixCache = [];
        }

        $map = [];
        foreach (self::loadRows($forumId, $db) as $row) {
            $gid = (int) ($row->group_id ?? 0);
            $perm = self::normalizePermission((string) ($row->perm_name ?? ''));
            if ($gid < 1 || $perm === '') {
                continue;
            }
            $map[$gid][$perm] = ((int) ($row->perm_setting ?? 0)) === self::SETTING_ALLOW
                ? self::SETTING_ALLOW
                : self::SETTING_DENY;
        }

        self::$matrixCache[$key] = $map;

        return $map;
    }

    /**
     * @return list<object>
     */
    private static function loadRows(int $forumId, AP_DB $db): array
    {
        $table = $db->quoteIdentifier($db->table('forum_permissions'));

        return $db->getResults(
            'SELECT * FROM ' . $table
            . ' WHERE ' . $db->quoteIdentifier('forum_id') . ' = ?',
            [$forumId]
        );
    }
}
