<?php

/**
 * AgoraPress roles and capabilities.
 *
 * WordPress-inspired role map stored in the `ap_user_roles` option, with per-user
 * role assignment in usermeta key `ap_capabilities` (array of role_slug => true,
 * plus optional direct capability grants). Extendable for forums and plugins.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * Roles registry, assignment, and capability checks.
 */
class AP_Roles
{
    /** Option name holding the role → capabilities map. */
    public const OPTION_ROLES = 'ap_user_roles';

    /** Usermeta key: role membership + optional direct caps. */
    public const META_CAPABILITIES = 'ap_capabilities';

    /** Usermeta key: numeric user level (legacy WP-style convenience). */
    public const META_USER_LEVEL = 'ap_user_level';

    /** Option for default role assigned to new registrants. */
    public const OPTION_DEFAULT_ROLE = 'default_role';

    /** @var array<string, array{name: string, capabilities: array<string, bool>}>|null */
    private static ?array $rolesCache = null;

    /** @var array<int, array<string, bool>> user_id => allcaps */
    private static array $userCapsCache = [];

    /** @var array<int, list<string>> user_id => role slugs */
    private static array $userRolesCache = [];

    // -------------------------------------------------------------------------
    // Defaults
    // -------------------------------------------------------------------------

    /**
     * Built-in role definitions (slug => name + capabilities).
     *
     * Administrator receives every primitive capability listed in
     * {@see self::allPrimitiveCapabilities()}. Other roles get curated subsets.
     * Forum-specific capabilities will be layered on when the forum module lands.
     *
     * @return array<string, array{name: string, capabilities: array<string, bool>, level: int}>
     */
    public static function defaultRoleDefinitions(): array
    {
        $all = [];
        foreach (self::allPrimitiveCapabilities() as $cap) {
            $all[$cap] = true;
        }

        $subscriber = [
            'read' => true,
        ];

        $contributor = $subscriber + [
            'edit_posts' => true,
            'delete_posts' => true,
        ];

        $author = $contributor + [
            'publish_posts' => true,
            'edit_published_posts' => true,
            'delete_published_posts' => true,
            'upload_files' => true,
        ];

        $editor = $author + [
            'edit_others_posts' => true,
            'edit_private_posts' => true,
            'read_private_posts' => true,
            'delete_others_posts' => true,
            'delete_private_posts' => true,
            'edit_pages' => true,
            'edit_others_pages' => true,
            'edit_published_pages' => true,
            'publish_pages' => true,
            'delete_pages' => true,
            'delete_others_pages' => true,
            'delete_published_pages' => true,
            'delete_private_pages' => true,
            'edit_private_pages' => true,
            'read_private_pages' => true,
            'manage_categories' => true,
            'moderate_comments' => true,
            'edit_comment' => true,
        ];

        return [
            'administrator' => [
                'name' => 'Administrator',
                'capabilities' => $all,
                'level' => 10,
            ],
            'editor' => [
                'name' => 'Editor',
                'capabilities' => $editor,
                'level' => 7,
            ],
            'author' => [
                'name' => 'Author',
                'capabilities' => $author,
                'level' => 2,
            ],
            'contributor' => [
                'name' => 'Contributor',
                'capabilities' => $contributor,
                'level' => 1,
            ],
            'subscriber' => [
                'name' => 'Subscriber',
                'capabilities' => $subscriber,
                'level' => 0,
            ],
        ];
    }

    /**
     * Primitive (non-meta) capabilities known to core.
     *
     * Meta capabilities such as `edit_post` are mapped via {@see self::mapMetaCap()}.
     *
     * @return list<string>
     */
    public static function allPrimitiveCapabilities(): array
    {
        return [
            // Dashboard / general
            'read',
            'manage_options',
            // Users
            'list_users',
            'create_users',
            'edit_users',
            'delete_users',
            'promote_users',
            // Posts
            'edit_posts',
            'edit_others_posts',
            'edit_published_posts',
            'publish_posts',
            'delete_posts',
            'delete_others_posts',
            'delete_published_posts',
            'delete_private_posts',
            'edit_private_posts',
            'read_private_posts',
            // Pages
            'edit_pages',
            'edit_others_pages',
            'edit_published_pages',
            'publish_pages',
            'delete_pages',
            'delete_others_pages',
            'delete_published_pages',
            'delete_private_pages',
            'edit_private_pages',
            'read_private_pages',
            // Media
            'upload_files',
            // Comments
            'moderate_comments',
            'edit_comment',
            // Taxonomies
            'manage_categories',
            // Appearance (foundation for Phase 4)
            'switch_themes',
            'edit_themes',
            'edit_theme_options',
            'install_themes',
            'update_themes',
            'delete_themes',
            // Plugins (foundation for Phase 4)
            'activate_plugins',
            'edit_plugins',
            'install_plugins',
            'update_plugins',
            'delete_plugins',
            // Updates
            'update_core',
            // Forum stubs (extendable; granted only to administrator for now)
            'moderate_forums',
            'manage_forums',
        ];
    }

    /**
     * Numeric level for a built-in role (0–10). Unknown roles return 0.
     */
    public static function roleLevel(string $role): int
    {
        $defs = self::defaultRoleDefinitions();
        $role = self::normalizeRoleSlug($role);
        if ($role === '' || !isset($defs[$role])) {
            return 0;
        }

        return (int) $defs[$role]['level'];
    }

    /**
     * Ensure the roles option exists with defaults (idempotent).
     *
     * Safe to call from installer and bootstrap. Does not overwrite an existing
     * non-empty roles map (plugins/admin may have customized it).
     */
    public static function ensureDefaults(?AP_DB $db = null): void
    {
        $db = self::resolveDb($db);
        $existing = self::readRolesOption($db);
        if ($existing !== []) {
            // Still warm cache from DB.
            self::$rolesCache = $existing;

            return;
        }

        $stored = [];
        foreach (self::defaultRoleDefinitions() as $slug => $def) {
            $stored[$slug] = [
                'name' => $def['name'],
                'capabilities' => $def['capabilities'],
            ];
        }
        self::writeRolesOption($stored, $db);
        self::$rolesCache = $stored;

        // Default registration role when missing.
        if (self::getOptionRaw(self::OPTION_DEFAULT_ROLE, $db) === null) {
            self::setOptionRaw(self::OPTION_DEFAULT_ROLE, 'subscriber', $db);
        }
    }

    // -------------------------------------------------------------------------
    // Role registry
    // -------------------------------------------------------------------------

    /**
     * All roles (slug => [name, capabilities]).
     *
     * @return array<string, array{name: string, capabilities: array<string, bool>}>
     */
    public static function getRoles(?AP_DB $db = null): array
    {
        if (self::$rolesCache !== null) {
            return self::$rolesCache;
        }

        $db = self::resolveDb($db);
        $roles = self::readRolesOption($db);
        if ($roles === []) {
            self::ensureDefaults($db);
            $roles = self::$rolesCache ?? [];
        }

        self::$rolesCache = $roles;

        return $roles;
    }

    /**
     * Single role definition, or null when unknown.
     *
     * @return array{name: string, capabilities: array<string, bool>}|null
     */
    public static function getRole(string $role, ?AP_DB $db = null): ?array
    {
        $role = self::normalizeRoleSlug($role);
        if ($role === '') {
            return null;
        }
        $roles = self::getRoles($db);

        return $roles[$role] ?? null;
    }

    /**
     * Whether a role slug is registered.
     */
    public static function roleExists(string $role, ?AP_DB $db = null): bool
    {
        return self::getRole($role, $db) !== null;
    }

    /**
     * Display names for all roles.
     *
     * @return array<string, string> slug => name
     */
    public static function getRoleNames(?AP_DB $db = null): array
    {
        $names = [];
        foreach (self::getRoles($db) as $slug => $def) {
            $names[$slug] = (string) ($def['name'] ?? $slug);
        }

        return $names;
    }

    /**
     * Register a new role. Returns false when the slug is empty or already exists.
     *
     * @param array<string, bool> $capabilities
     */
    public static function addRole(
        string $role,
        string $displayName,
        array $capabilities = [],
        ?AP_DB $db = null
    ): bool {
        $role = self::normalizeRoleSlug($role);
        if ($role === '') {
            return false;
        }

        $db = self::resolveDb($db);
        $roles = self::getRoles($db);
        if (isset($roles[$role])) {
            return false;
        }

        $caps = [];
        foreach ($capabilities as $cap => $grant) {
            $cap = self::normalizeCap((string) $cap);
            if ($cap !== '') {
                $caps[$cap] = (bool) $grant;
            }
        }

        $roles[$role] = [
            'name' => $displayName !== '' ? $displayName : $role,
            'capabilities' => $caps,
        ];

        return self::persistRoles($roles, $db);
    }

    /**
     * Remove a role from the registry. Does not rewrite existing user meta
     * (orphaned role keys are ignored by capability checks).
     */
    public static function removeRole(string $role, ?AP_DB $db = null): bool
    {
        $role = self::normalizeRoleSlug($role);
        if ($role === '' || $role === 'administrator') {
            // Never remove the built-in administrator role via API.
            return false;
        }

        $db = self::resolveDb($db);
        $roles = self::getRoles($db);
        if (!isset($roles[$role])) {
            return false;
        }

        unset($roles[$role]);

        return self::persistRoles($roles, $db);
    }

    /**
     * Grant or deny a capability on a role.
     */
    public static function addCap(
        string $role,
        string $cap,
        bool $grant = true,
        ?AP_DB $db = null
    ): bool {
        $role = self::normalizeRoleSlug($role);
        $cap = self::normalizeCap($cap);
        if ($role === '' || $cap === '') {
            return false;
        }

        $db = self::resolveDb($db);
        $roles = self::getRoles($db);
        if (!isset($roles[$role])) {
            return false;
        }

        $roles[$role]['capabilities'][$cap] = $grant;

        return self::persistRoles($roles, $db);
    }

    /**
     * Remove a capability key from a role definition.
     */
    public static function removeCap(string $role, string $cap, ?AP_DB $db = null): bool
    {
        $role = self::normalizeRoleSlug($role);
        $cap = self::normalizeCap($cap);
        if ($role === '' || $cap === '') {
            return false;
        }

        $db = self::resolveDb($db);
        $roles = self::getRoles($db);
        if (!isset($roles[$role]['capabilities'][$cap])) {
            return false;
        }

        unset($roles[$role]['capabilities'][$cap]);

        return self::persistRoles($roles, $db);
    }

    // -------------------------------------------------------------------------
    // User assignment
    // -------------------------------------------------------------------------

    /**
     * Role slugs assigned to a user (registered roles only).
     *
     * @return list<string>
     */
    public static function getUserRoles(int $userId, ?AP_DB $db = null): array
    {
        if ($userId < 1) {
            return [];
        }

        if (isset(self::$userRolesCache[$userId])) {
            return self::$userRolesCache[$userId];
        }

        $db = self::resolveDb($db);
        $raw = self::getUserCapabilitiesMeta($userId, $db);
        $rolesMap = self::getRoles($db);
        $assigned = [];
        foreach ($raw as $key => $granted) {
            if (!$granted) {
                continue;
            }
            $slug = self::normalizeRoleSlug((string) $key);
            if ($slug !== '' && isset($rolesMap[$slug])) {
                $assigned[] = $slug;
            }
        }

        self::$userRolesCache[$userId] = $assigned;

        return $assigned;
    }

    /**
     * Primary (first) role for a user, or empty string.
     */
    public static function getUserRole(int $userId, ?AP_DB $db = null): string
    {
        $roles = self::getUserRoles($userId, $db);

        return $roles[0] ?? '';
    }

    /**
     * Replace the user's roles with a single role.
     */
    public static function setUserRole(int $userId, string $role, ?AP_DB $db = null): bool
    {
        if ($userId < 1) {
            return false;
        }

        $role = self::normalizeRoleSlug($role);
        $db = self::resolveDb($db);
        if ($role === '' || !self::roleExists($role, $db)) {
            return false;
        }

        $meta = [ $role => true ];
        $ok = self::updateUserCapabilitiesMeta($userId, $meta, $db);
        if ($ok) {
            self::updateUserLevelMeta($userId, self::roleLevel($role), $db);
            unset(self::$userCapsCache[$userId], self::$userRolesCache[$userId]);
        }

        return $ok;
    }

    /**
     * Add a role without removing existing ones.
     */
    public static function addUserRole(int $userId, string $role, ?AP_DB $db = null): bool
    {
        if ($userId < 1) {
            return false;
        }

        $role = self::normalizeRoleSlug($role);
        $db = self::resolveDb($db);
        if ($role === '' || !self::roleExists($role, $db)) {
            return false;
        }

        $meta = self::getUserCapabilitiesMeta($userId, $db);
        $meta[$role] = true;
        $ok = self::updateUserCapabilitiesMeta($userId, $meta, $db);
        if ($ok) {
            unset(self::$userRolesCache[$userId], self::$userCapsCache[$userId]);
            $level = 0;
            foreach (self::getUserRoles($userId, $db) as $r) {
                $level = max($level, self::roleLevel($r));
            }
            self::updateUserLevelMeta($userId, $level, $db);
        }

        return $ok;
    }

    /**
     * Remove one role from a user. Leaves other roles and direct caps intact.
     */
    public static function removeUserRole(int $userId, string $role, ?AP_DB $db = null): bool
    {
        if ($userId < 1) {
            return false;
        }

        $role = self::normalizeRoleSlug($role);
        if ($role === '') {
            return false;
        }

        $db = self::resolveDb($db);
        $meta = self::getUserCapabilitiesMeta($userId, $db);
        if (!isset($meta[$role])) {
            return false;
        }

        unset($meta[$role]);
        $ok = self::updateUserCapabilitiesMeta($userId, $meta, $db);
        if ($ok) {
            unset(self::$userRolesCache[$userId], self::$userCapsCache[$userId]);
            $level = 0;
            foreach (self::getUserRoles($userId, $db) as $r) {
                $level = max($level, self::roleLevel($r));
            }
            self::updateUserLevelMeta($userId, $level, $db);
        }

        return $ok;
    }

    /**
     * Grant a direct capability to a user (independent of roles).
     */
    public static function addUserCap(int $userId, string $cap, bool $grant = true, ?AP_DB $db = null): bool
    {
        if ($userId < 1) {
            return false;
        }
        $cap = self::normalizeCap($cap);
        if ($cap === '') {
            return false;
        }

        $db = self::resolveDb($db);
        $meta = self::getUserCapabilitiesMeta($userId, $db);
        $meta[$cap] = $grant;
        $ok = self::updateUserCapabilitiesMeta($userId, $meta, $db);
        if ($ok) {
            unset(self::$userCapsCache[$userId]);
        }

        return $ok;
    }

    /**
     * Remove a direct capability key from a user's meta (not role-derived).
     */
    public static function removeUserCap(int $userId, string $cap, ?AP_DB $db = null): bool
    {
        if ($userId < 1) {
            return false;
        }
        $cap = self::normalizeCap($cap);
        if ($cap === '') {
            return false;
        }

        $db = self::resolveDb($db);
        $meta = self::getUserCapabilitiesMeta($userId, $db);
        if (!array_key_exists($cap, $meta)) {
            return false;
        }
        // Do not strip registered role keys via this helper.
        if (self::roleExists($cap, $db)) {
            return false;
        }

        unset($meta[$cap]);
        $ok = self::updateUserCapabilitiesMeta($userId, $meta, $db);
        if ($ok) {
            unset(self::$userCapsCache[$userId]);
        }

        return $ok;
    }

    /**
     * Effective capability map for a user (role caps + direct grants/denials).
     *
     * @return array<string, bool>
     */
    public static function getUserCapabilities(int $userId, ?AP_DB $db = null): array
    {
        if ($userId < 1) {
            return [];
        }

        if (isset(self::$userCapsCache[$userId])) {
            return self::$userCapsCache[$userId];
        }

        $db = self::resolveDb($db);
        $meta = self::getUserCapabilitiesMeta($userId, $db);
        $rolesMap = self::getRoles($db);
        $allcaps = [];

        foreach ($meta as $key => $granted) {
            $key = (string) $key;
            if (isset($rolesMap[$key]) && $granted) {
                foreach ($rolesMap[$key]['capabilities'] as $cap => $roleGrant) {
                    if ($roleGrant) {
                        $allcaps[$cap] = true;
                    } elseif (!isset($allcaps[$cap])) {
                        $allcaps[$cap] = false;
                    }
                }
            }
        }

        // Direct caps override role-derived ones.
        foreach ($meta as $key => $granted) {
            $key = (string) $key;
            if (isset($rolesMap[$key])) {
                continue;
            }
            $cap = self::normalizeCap($key);
            if ($cap !== '') {
                $allcaps[$cap] = (bool) $granted;
            }
        }

        self::$userCapsCache[$userId] = $allcaps;

        return $allcaps;
    }

    // -------------------------------------------------------------------------
    // Capability checks
    // -------------------------------------------------------------------------

    /**
     * Whether a user has a capability (after meta-cap mapping).
     *
     * @param int|null $objectId Optional post/comment ID for meta capabilities.
     */
    public static function userCan(
        int $userId,
        string $capability,
        ?int $objectId = null,
        ?AP_DB $db = null
    ): bool {
        if ($userId < 1) {
            return false;
        }

        $capability = self::normalizeCap($capability);
        if ($capability === '' || $capability === 'do_not_allow') {
            return false;
        }

        $db = self::resolveDb($db);
        $required = self::mapMetaCap($capability, $userId, $objectId, $db);
        $allcaps = self::getUserCapabilities($userId, $db);

        foreach ($required as $cap) {
            $cap = self::normalizeCap($cap);
            if ($cap === 'do_not_allow' || $cap === '') {
                return false;
            }
            if (empty($allcaps[$cap])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Capability check for the currently logged-in user.
     *
     * @param int|null $objectId Optional post/comment ID for meta capabilities.
     */
    public static function currentUserCan(
        string $capability,
        ?int $objectId = null,
        ?AP_DB $db = null
    ): bool {
        $userId = 0;
        if (function_exists('ap_get_current_user_id')) {
            $userId = ap_get_current_user_id($db);
        } elseif (class_exists('AP_Session', false)) {
            $userId = AP_Session::getCurrentUserId($db);
        }

        return self::userCan($userId, $capability, $objectId, $db);
    }

    /**
     * Map a meta capability to one or more primitive capabilities.
     *
     * @return list<string>
     */
    public static function mapMetaCap(
        string $cap,
        int $userId,
        ?int $objectId = null,
        ?AP_DB $db = null
    ): array {
        $cap = self::normalizeCap($cap);
        if ($cap === '') {
            return ['do_not_allow'];
        }

        // Already primitive — pass through.
        $metaCaps = [
            'edit_post', 'delete_post', 'read_post',
            'edit_page', 'delete_page', 'read_page',
            'edit_comment',
        ];
        if (!in_array($cap, $metaCaps, true)) {
            return [$cap];
        }

        $db = self::resolveDb($db);
        $id = $objectId ?? 0;

        if ($cap === 'edit_comment') {
            return self::mapEditComment($userId, $id, $db);
        }

        $isPage = str_contains($cap, 'page');
        $action = str_starts_with($cap, 'delete_') ? 'delete'
            : (str_starts_with($cap, 'read_') ? 'read' : 'edit');

        return self::mapPostTypeMetaCap($action, $isPage ? 'page' : 'post', $userId, $id, $db);
    }

    /**
     * @return list<string>
     */
    private static function mapEditComment(int $userId, int $commentId, ?AP_DB $db): array
    {
        if ($commentId < 1) {
            return ['moderate_comments'];
        }

        // Without a loaded comment model, require moderate_comments for any edit.
        if (!class_exists('AP_Comment', false)) {
            return ['moderate_comments'];
        }

        try {
            $comment = AP_Comment::get($commentId, $db);
        } catch (Throwable) {
            return ['moderate_comments'];
        }

        if ($comment === null) {
            return ['do_not_allow'];
        }

        // Authors of the parent post with edit_posts may moderate their own threads lightly;
        // core keeps it simple: moderate_comments for all comment edits.
        return ['moderate_comments'];
    }

    /**
     * @return list<string>
     */
    private static function mapPostTypeMetaCap(
        string $action,
        string $type,
        int $userId,
        int $postId,
        ?AP_DB $db
    ): array {
        $plural = $type === 'page' ? 'pages' : 'posts';

        if ($postId < 1 || !class_exists('AP_Post', false)) {
            return match ($action) {
                'delete' => ['delete_' . $plural],
                'read' => ['read'],
                default => ['edit_' . $plural],
            };
        }

        try {
            $post = AP_Post::get($postId, $db);
        } catch (Throwable) {
            $post = null;
        }

        if ($post === null) {
            return ['do_not_allow'];
        }

        // Respect actual post type when row type differs from meta-cap family.
        $rowType = $post->post_type ?? $type;
        if ($rowType === 'page') {
            $plural = 'pages';
        } elseif ($rowType === 'post') {
            $plural = 'posts';
        } else {
            // Custom types: fall back to posts primitives for now.
            $plural = 'posts';
        }

        $authorId = (int) ($post->post_author ?? 0);
        $status = (string) ($post->post_status ?? 'draft');
        $isOwner = $authorId > 0 && $authorId === $userId;

        if ($action === 'read') {
            if ($status === 'private') {
                return $isOwner ? ['read'] : ['read_private_' . $plural];
            }

            return ['read'];
        }

        if ($action === 'delete') {
            if ($status === 'trash') {
                return $isOwner ? ['delete_' . $plural] : ['delete_others_' . $plural];
            }
            if ($status === 'private') {
                return $isOwner
                    ? ['delete_' . $plural, 'delete_private_' . $plural]
                    : ['delete_others_' . $plural, 'delete_private_' . $plural];
            }
            if ($status === 'publish') {
                return $isOwner
                    ? ['delete_published_' . $plural]
                    : ['delete_others_' . $plural, 'delete_published_' . $plural];
            }

            // Draft / pending / future / etc.
            return $isOwner ? ['delete_' . $plural] : ['delete_others_' . $plural];
        }

        // edit
        if ($status === 'private') {
            return $isOwner
                ? ['edit_' . $plural, 'edit_private_' . $plural]
                : ['edit_others_' . $plural, 'edit_private_' . $plural];
        }
        if ($status === 'publish') {
            return $isOwner
                ? ['edit_published_' . $plural]
                : ['edit_others_' . $plural, 'edit_published_' . $plural];
        }

        return $isOwner ? ['edit_' . $plural] : ['edit_others_' . $plural];
    }

    // -------------------------------------------------------------------------
    // Cache control
    // -------------------------------------------------------------------------

    /**
     * Drop request-local caches (tests / after bulk role edits).
     */
    public static function flushCache(): void
    {
        self::$rolesCache = null;
        self::$userCapsCache = [];
        self::$userRolesCache = [];
    }

    // -------------------------------------------------------------------------
    // Internals — storage
    // -------------------------------------------------------------------------

    private static function resolveDb(?AP_DB $db): ?AP_DB
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

    /**
     * @return array<string, array{name: string, capabilities: array<string, bool>}>
     */
    private static function readRolesOption(?AP_DB $db): array
    {
        $raw = null;
        if (class_exists('AP_Options', false) && $db !== null) {
            $raw = AP_Options::get(self::OPTION_ROLES, null, $db);
        } elseif ($db !== null) {
            $raw = self::getOptionRaw(self::OPTION_ROLES, $db);
            if (is_string($raw) && $raw !== '') {
                $raw = self::decodeStructured($raw);
            }
        }

        if (!is_array($raw) || $raw === []) {
            return [];
        }

        $out = [];
        foreach ($raw as $slug => $def) {
            $slug = self::normalizeRoleSlug((string) $slug);
            if ($slug === '' || !is_array($def)) {
                continue;
            }
            $name = (string) ($def['name'] ?? $slug);
            $capsIn = $def['capabilities'] ?? [];
            $caps = [];
            if (is_array($capsIn)) {
                foreach ($capsIn as $c => $grant) {
                    $c = self::normalizeCap((string) $c);
                    if ($c !== '') {
                        $caps[$c] = (bool) $grant;
                    }
                }
            }
            $out[$slug] = [
                'name' => $name,
                'capabilities' => $caps,
            ];
        }

        return $out;
    }

    /**
     * @param array<string, array{name: string, capabilities: array<string, bool>}> $roles
     */
    private static function writeRolesOption(array $roles, ?AP_DB $db): bool
    {
        if ($db === null) {
            return false;
        }

        if (class_exists('AP_Options', false)) {
            AP_Options::flushCache();

            return AP_Options::update(self::OPTION_ROLES, $roles, $db);
        }

        $json = json_encode($roles, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            return false;
        }

        return self::setOptionRaw(self::OPTION_ROLES, $json, $db);
    }

    /**
     * @param array<string, array{name: string, capabilities: array<string, bool>}> $roles
     */
    private static function persistRoles(array $roles, ?AP_DB $db): bool
    {
        $ok = self::writeRolesOption($roles, $db);
        if ($ok) {
            self::$rolesCache = $roles;
            // Role definition changes affect every user's effective caps.
            self::$userCapsCache = [];
        }

        return $ok;
    }

    /**
     * @return array<string, bool>
     */
    private static function getUserCapabilitiesMeta(int $userId, ?AP_DB $db): array
    {
        if ($db === null || $userId < 1) {
            return [];
        }

        try {
            $raw = $db->getVar(
                'SELECT meta_value FROM ' . $db->quoteIdentifier($db->table('usermeta'))
                . ' WHERE user_id = ? AND meta_key = ? LIMIT 1',
                [$userId, self::META_CAPABILITIES]
            );
        } catch (Throwable) {
            return [];
        }

        if ($raw === null || $raw === '') {
            return [];
        }

        $decoded = self::decodeStructured((string) $raw);
        if (!is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $k => $v) {
            $out[(string) $k] = (bool) $v;
        }

        return $out;
    }

    /**
     * @param array<string, bool> $meta
     */
    private static function updateUserCapabilitiesMeta(int $userId, array $meta, ?AP_DB $db): bool
    {
        if ($db === null || $userId < 1) {
            return false;
        }

        // Prefer PHP serialize for parity with installer seed + classic WP usermeta.
        $stored = serialize($meta);

        try {
            $existing = $db->getVar(
                'SELECT umeta_id FROM ' . $db->quoteIdentifier($db->table('usermeta'))
                . ' WHERE user_id = ? AND meta_key = ? LIMIT 1',
                [$userId, self::META_CAPABILITIES]
            );

            if ($existing !== null && $existing !== '') {
                return $db->update(
                    'usermeta',
                    ['meta_value' => $stored],
                    [
                        'user_id' => $userId,
                        'meta_key' => self::META_CAPABILITIES,
                    ]
                ) !== false;
            }

            return $db->insert('usermeta', [
                'user_id' => $userId,
                'meta_key' => self::META_CAPABILITIES,
                'meta_value' => $stored,
            ]) !== false;
        } catch (Throwable) {
            return false;
        }
    }

    private static function updateUserLevelMeta(int $userId, int $level, ?AP_DB $db): void
    {
        if ($db === null || $userId < 1) {
            return;
        }

        $level = max(0, min(10, $level));
        $stored = (string) $level;

        try {
            $existing = $db->getVar(
                'SELECT umeta_id FROM ' . $db->quoteIdentifier($db->table('usermeta'))
                . ' WHERE user_id = ? AND meta_key = ? LIMIT 1',
                [$userId, self::META_USER_LEVEL]
            );

            if ($existing !== null && $existing !== '') {
                $db->update(
                    'usermeta',
                    ['meta_value' => $stored],
                    [
                        'user_id' => $userId,
                        'meta_key' => self::META_USER_LEVEL,
                    ]
                );
            } else {
                $db->insert('usermeta', [
                    'user_id' => $userId,
                    'meta_key' => self::META_USER_LEVEL,
                    'meta_value' => $stored,
                ]);
            }
        } catch (Throwable) {
            // Non-fatal: level is advisory.
        }
    }

    private static function getOptionRaw(string $name, ?AP_DB $db): mixed
    {
        if ($db === null) {
            return null;
        }
        try {
            return $db->getVar(
                'SELECT option_value FROM ' . $db->quoteIdentifier($db->table('options'))
                . ' WHERE option_name = ? LIMIT 1',
                [$name]
            );
        } catch (Throwable) {
            return null;
        }
    }

    private static function setOptionRaw(string $name, string $value, ?AP_DB $db): bool
    {
        if ($db === null) {
            return false;
        }
        try {
            $existing = $db->getVar(
                'SELECT option_id FROM ' . $db->quoteIdentifier($db->table('options'))
                . ' WHERE option_name = ? LIMIT 1',
                [$name]
            );
            if ($existing !== null && $existing !== '') {
                return $db->update(
                    'options',
                    ['option_value' => $value, 'autoload' => 'yes'],
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

    /**
     * Decode JSON or PHP-serialized structured data.
     */
    private static function decodeStructured(string $raw): mixed
    {
        $trim = ltrim($raw);
        if ($trim === '') {
            return null;
        }

        if ($trim[0] === '{' || $trim[0] === '[') {
            $json = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $json;
            }
        }

        // PHP serialize (installer seed + classic WP usermeta).
        if ($trim[0] === 'a' || $trim[0] === 'O' || $trim[0] === 's') {
            // phpcs:ignore Generic.PHP.ForbiddenFunctions — controlled usermeta decode
            $data = @unserialize($raw, ['allowed_classes' => false]);
            if ($data !== false || $raw === 'b:0;') {
                return $data;
            }
        }

        return null;
    }

    private static function normalizeRoleSlug(string $role): string
    {
        $role = strtolower(trim($role));
        $role = preg_replace('/[^a-z0-9_\-]/', '', $role) ?? '';

        return $role;
    }

    private static function normalizeCap(string $cap): string
    {
        $cap = strtolower(trim($cap));
        // Capabilities use underscores (and rarely dashes); allow those only.
        $cap = preg_replace('/[^a-z0-9_\-]/', '', $cap) ?? '';

        return $cap;
    }
}
