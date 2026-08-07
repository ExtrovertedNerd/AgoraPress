<?php

/**
 * AgoraPress user model and authentication helpers.
 *
 * Password hashing uses Argon2id when the PHP runtime supports it
 * (PASSWORD_ARGON2ID), falling back to PASSWORD_DEFAULT otherwise.
 * Credential checks use password_verify() and prefer a constant-time
 * failure path when the account does not exist.
 *
 * Session / cookie login lives in {@see AP_Session}. Password changes revoke
 * all stored session tokens when the session layer is loaded.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * User row model + password authentication against {prefix}users.
 */
class AP_User
{
    /** @var int User ID (0 = not persisted / empty). */
    public int $ID = 0;

    public string $user_login = '';

    public string $user_pass = '';

    public string $user_nicename = '';

    public string $user_email = '';

    public string $user_url = '';

    public string $user_registered = '';

    public string $user_activation_key = '';

    public int $user_status = 0;

    public string $display_name = '';

    /**
     * Preferred password algorithm: Argon2id when available.
     */
    public static function passwordAlgo(): string|int
    {
        if (defined('PASSWORD_ARGON2ID')) {
            return PASSWORD_ARGON2ID;
        }

        return PASSWORD_DEFAULT;
    }

    /**
     * Hash a password with Argon2id when available, otherwise PASSWORD_DEFAULT.
     *
     * @throws RuntimeException When password_hash() fails.
     */
    public static function hashPassword(string $password): string
    {
        $hash = password_hash($password, self::passwordAlgo());
        if (is_string($hash) && $hash !== '') {
            return $hash;
        }

        // Rare: algo unsupported at hash time — fall back once.
        if (self::passwordAlgo() !== PASSWORD_DEFAULT) {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            if (is_string($hash) && $hash !== '') {
                return $hash;
            }
        }

        throw new RuntimeException('password_hash() failed.');
    }

    /**
     * Verify a plain password against a stored hash.
     *
     * Empty passwords never match (even if the hash is empty).
     */
    public static function checkPassword(string $password, string $hash): bool
    {
        if ($password === '' || $hash === '') {
            return false;
        }

        return password_verify($password, $hash);
    }

    /**
     * Whether the stored hash should be rehashed with the preferred algorithm.
     */
    public static function passwordNeedsRehash(string $hash): bool
    {
        if ($hash === '') {
            return true;
        }

        return password_needs_rehash($hash, self::passwordAlgo());
    }

    /**
     * Build a user instance from a DB row object or associative array.
     *
     * @param object|array<string, mixed> $row
     */
    public static function fromRow(object|array $row): self
    {
        $data = is_array($row) ? $row : get_object_vars($row);
        $user = new self();

        if (isset($data['ID'])) {
            $user->ID = (int) $data['ID'];
        } elseif (isset($data['id'])) {
            // PostgreSQL unquoted fold / drivers that lowercase.
            $user->ID = (int) $data['id'];
        }

        $user->user_login = (string) ($data['user_login'] ?? '');
        $user->user_pass = (string) ($data['user_pass'] ?? '');
        $user->user_nicename = (string) ($data['user_nicename'] ?? '');
        $user->user_email = (string) ($data['user_email'] ?? '');
        $user->user_url = (string) ($data['user_url'] ?? '');
        $user->user_registered = (string) ($data['user_registered'] ?? '');
        $user->user_activation_key = (string) ($data['user_activation_key'] ?? '');
        $user->user_status = (int) ($data['user_status'] ?? 0);
        $user->display_name = (string) ($data['display_name'] ?? '');

        return $user;
    }

    /**
     * Resolve the DB handle (explicit argument or global ap_db()).
     */
    private static function resolveDb(?AP_DB $db): AP_DB
    {
        if ($db instanceof AP_DB) {
            return $db;
        }

        if (function_exists('ap_db')) {
            return ap_db();
        }

        throw new RuntimeException('No database connection available for user lookup.');
    }

    /**
     * Columns selected for user rows.
     */
    private static function selectColumns(AP_DB $db): string
    {
        return self::selectColumnsPrefixed($db, '');
    }

    /**
     * Columns selected for user rows, optionally table-qualified (e.g. "u").
     */
    private static function selectColumnsPrefixed(AP_DB $db, string $alias = ''): string
    {
        $cols = [
            'ID',
            'user_login',
            'user_pass',
            'user_nicename',
            'user_email',
            'user_url',
            'user_registered',
            'user_activation_key',
            'user_status',
            'display_name',
        ];

        $prefix = $alias !== '' ? $alias . '.' : '';
        $quoted = [];
        foreach ($cols as $col) {
            $quoted[] = $prefix . $db->quoteIdentifier($col);
        }

        return implode(', ', $quoted);
    }

    /**
     * Fetch a user by primary key.
     */
    public static function getById(int $id, ?AP_DB $db = null): ?self
    {
        if ($id < 1) {
            return null;
        }

        $db = self::resolveDb($db);
        $table = $db->quoteIdentifier($db->table('users'));
        $row = $db->getRow(
            'SELECT ' . self::selectColumns($db) . ' FROM ' . $table
            . ' WHERE ' . $db->quoteIdentifier('ID') . ' = ? LIMIT 1',
            [$id]
        );

        return $row === null ? null : self::fromRow($row);
    }

    /**
     * Batch-load display names for many user IDs (board index last-post; no N+1).
     *
     * Prefers display_name, falls back to user_login. Missing IDs are omitted.
     *
     * @param list<int> $userIds
     *
     * @return array<int, string> user_id => display label
     */
    public static function getDisplayNamesByIds(array $userIds, ?AP_DB $db = null): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn ($id): int => (int) $id, $userIds),
            static fn (int $id): bool => $id > 0
        )));
        if ($ids === []) {
            return [];
        }

        $db = self::resolveDb($db);
        $table = $db->quoteIdentifier($db->table('users'));
        $ph = implode(', ', array_fill(0, count($ids), '?'));
        $out = [];

        try {
            $rows = $db->getResults(
                'SELECT ' . $db->quoteIdentifier('ID') . ', '
                . $db->quoteIdentifier('display_name') . ', '
                . $db->quoteIdentifier('user_login')
                . ' FROM ' . $table
                . ' WHERE ' . $db->quoteIdentifier('ID') . ' IN (' . $ph . ')',
                $ids
            );
        } catch (Throwable) {
            foreach ($ids as $id) {
                try {
                    $user = self::getById($id, $db);
                    if ($user !== null) {
                        $label = (string) ($user->display_name ?? '');
                        if ($label === '') {
                            $label = (string) ($user->user_login ?? '');
                        }
                        if ($label !== '') {
                            $out[$id] = $label;
                        }
                    }
                } catch (Throwable) {
                    // skip
                }
            }

            return $out;
        }

        foreach ($rows as $row) {
            $uid = (int) (is_object($row) ? ($row->ID ?? $row->id ?? 0) : ($row['ID'] ?? $row['id'] ?? 0));
            if ($uid < 1) {
                continue;
            }
            $display = (string) (is_object($row) ? ($row->display_name ?? '') : ($row['display_name'] ?? ''));
            $login = (string) (is_object($row) ? ($row->user_login ?? '') : ($row['user_login'] ?? ''));
            $label = $display !== '' ? $display : $login;
            if ($label !== '') {
                $out[$uid] = $label;
            }
        }

        return $out;
    }

    /**
     * Fetch a user by login (case-sensitive match on stored value).
     */
    public static function getByLogin(string $login, ?AP_DB $db = null): ?self
    {
        $login = trim($login);
        if ($login === '') {
            return null;
        }

        $db = self::resolveDb($db);
        $table = $db->quoteIdentifier($db->table('users'));
        $row = $db->getRow(
            'SELECT ' . self::selectColumns($db) . ' FROM ' . $table
            . ' WHERE ' . $db->quoteIdentifier('user_login') . ' = ? LIMIT 1',
            [$login]
        );

        return $row === null ? null : self::fromRow($row);
    }

    /**
     * Fetch a user by email (exact match).
     */
    public static function getByEmail(string $email, ?AP_DB $db = null): ?self
    {
        $email = trim($email);
        if ($email === '') {
            return null;
        }

        $db = self::resolveDb($db);
        $table = $db->quoteIdentifier($db->table('users'));
        $row = $db->getRow(
            'SELECT ' . self::selectColumns($db) . ' FROM ' . $table
            . ' WHERE ' . $db->quoteIdentifier('user_email') . ' = ? LIMIT 1',
            [$email]
        );

        return $row === null ? null : self::fromRow($row);
    }

    /**
     * Fetch by field name: id | login | email | slug (nicename).
     */
    public static function getBy(string $field, string|int $value, ?AP_DB $db = null): ?self
    {
        $field = strtolower(trim($field));

        return match ($field) {
            'id' => self::getById((int) $value, $db),
            'login' => self::getByLogin((string) $value, $db),
            'email' => self::getByEmail((string) $value, $db),
            'slug', 'nicename' => self::getByNicename((string) $value, $db),
            default => null,
        };
    }

    /**
     * Fetch a user by nicename (URL slug).
     */
    public static function getByNicename(string $nicename, ?AP_DB $db = null): ?self
    {
        $nicename = trim($nicename);
        if ($nicename === '') {
            return null;
        }

        $db = self::resolveDb($db);
        $table = $db->quoteIdentifier($db->table('users'));
        $row = $db->getRow(
            'SELECT ' . self::selectColumns($db) . ' FROM ' . $table
            . ' WHERE ' . $db->quoteIdentifier('user_nicename') . ' = ? LIMIT 1',
            [$nicename]
        );

        return $row === null ? null : self::fromRow($row);
    }

    /**
     * Authenticate with username or email + password.
     *
     * Returns the user on success, or null when credentials are wrong,
     * the account is inactive (user_status !== 0), or inputs are empty.
     * Uses a dummy hash when the account is missing so failure cost is
     * similar to a failed password check.
     *
     * On success, rehashes the stored password when the algorithm params
     * are outdated (transparent upgrade path).
     */
    public static function authenticate(string $loginOrEmail, string $password, ?AP_DB $db = null): ?self
    {
        $loginOrEmail = trim($loginOrEmail);
        $db = self::resolveDb($db);

        if ($loginOrEmail === '' || $password === '') {
            self::checkPassword($password !== '' ? $password : 'x', self::dummyPasswordHash());

            return null;
        }

        $user = self::getByLogin($loginOrEmail, $db);
        if ($user === null && str_contains($loginOrEmail, '@')) {
            $user = self::getByEmail($loginOrEmail, $db);
        }

        $hash = ($user !== null && $user->user_pass !== '')
            ? $user->user_pass
            : self::dummyPasswordHash();

        if (!self::checkPassword($password, $hash)) {
            return null;
        }

        if ($user === null) {
            return null;
        }

        // Non-zero status reserved for disabled / not activated accounts.
        if ($user->user_status !== 0) {
            return null;
        }

        if (self::passwordNeedsRehash($user->user_pass)) {
            $user->updatePassword($password, $db);
        }

        return $user;
    }

    /**
     * Instance helper: verify a plain password against this user's hash.
     */
    public function verifyPassword(string $password): bool
    {
        return self::checkPassword($password, $this->user_pass);
    }

    /**
     * Persist a new password hash for this user.
     *
     * @return bool True when the DB update succeeded.
     */
    public function updatePassword(string $password, ?AP_DB $db = null): bool
    {
        if ($this->ID < 1) {
            return false;
        }

        $hash = self::hashPassword($password);
        $db = self::resolveDb($db);
        $result = $db->update(
            'users',
            ['user_pass' => $hash],
            ['ID' => $this->ID]
        );

        if ($result === false) {
            return false;
        }

        $this->user_pass = $hash;

        // Password change invalidates outstanding auth cookies / session tokens.
        if (class_exists('AP_Session', false)) {
            AP_Session::destroyAllSessionTokens($this->ID, $db);
            AP_Session::resetCurrentUser();
        }

        return true;
    }

    /**
     * Fixed-cost dummy hash used when the account is unknown.
     *
     * Generated once per process with the preferred algorithm so
     * password_verify() work is comparable to a real check.
     */
    private static function dummyPasswordHash(): string
    {
        static $dummy = null;
        if (!is_string($dummy) || $dummy === '') {
            $dummy = self::hashPassword('agorapress-auth-dummy-never-used');
        }

        return $dummy;
    }

    /**
     * Whether this instance represents a persisted user.
     */
    public function exists(): bool
    {
        return $this->ID > 0;
    }

    /**
     * Safe public fields (no password hash) for templates / APIs.
     *
     * @return array{
     *     ID: int,
     *     user_login: string,
     *     user_nicename: string,
     *     user_email: string,
     *     user_url: string,
     *     user_registered: string,
     *     user_status: int,
     *     display_name: string
     * }
     */
    public function toPublicArray(): array
    {
        return [
            'ID' => $this->ID,
            'user_login' => $this->user_login,
            'user_nicename' => $this->user_nicename,
            'user_email' => $this->user_email,
            'user_url' => $this->user_url,
            'user_registered' => $this->user_registered,
            'user_status' => $this->user_status,
            'display_name' => $this->display_name,
        ];
    }

    // -------------------------------------------------------------------------
    // Create / update / delete
    // -------------------------------------------------------------------------

    /**
     * Create a new user.
     *
     * Required keys: user_login, user_email, user_pass (or password).
     * Optional: display_name, user_url, user_nicename, user_status, role,
     * first_name, last_name, nickname, description.
     *
     * @param array<string, mixed> $data
     *
     * @return array{ok: bool, id: int, errors: list<string>, user: ?self}
     */
    public static function create(array $data, ?AP_DB $db = null): array
    {
        $db = self::resolveDb($db);

        $login = self::sanitizeUserLogin((string) ($data['user_login'] ?? ''));
        $email = self::sanitizeEmail((string) ($data['user_email'] ?? $data['email'] ?? ''));
        $password = (string) ($data['user_pass'] ?? $data['password'] ?? '');
        $url = self::sanitizeUrl((string) ($data['user_url'] ?? $data['url'] ?? ''));
        $display = ap_sanitize_text_field((string) ($data['display_name'] ?? ''));
        $status = (int) ($data['user_status'] ?? 0);
        $nicename = self::sanitizeNicename((string) ($data['user_nicename'] ?? $login));

        $errors = [];
        if ($login === '') {
            $errors[] = 'Username is required.';
        } elseif (strlen($login) > 60) {
            $errors[] = 'Username is too long (max 60 characters).';
        } elseif (self::getByLogin($login, $db) !== null) {
            $errors[] = 'That username is already registered.';
        }

        if ($email === '') {
            $errors[] = 'Email is required.';
        } elseif (!self::isValidEmail($email)) {
            $errors[] = 'Please enter a valid email address.';
        } elseif (self::getByEmail($email, $db) !== null) {
            $errors[] = 'That email address is already registered.';
        }

        if ($password === '') {
            $errors[] = 'Password is required.';
        } elseif (strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        }

        if ($errors !== []) {
            return ['ok' => false, 'id' => 0, 'errors' => $errors, 'user' => null];
        }

        if ($display === '') {
            $display = $login;
        }
        if ($nicename === '') {
            $nicename = self::sanitizeNicename($login);
        }
        $nicename = self::uniqueNicename($nicename, 0, $db);

        $hash = self::hashPassword($password);
        $registered = gmdate('Y-m-d H:i:s');

        $ok = $db->insert('users', [
            'user_login' => $login,
            'user_pass' => $hash,
            'user_nicename' => $nicename,
            'user_email' => $email,
            'user_url' => $url,
            'user_registered' => $registered,
            'user_activation_key' => '',
            'user_status' => $status,
            'display_name' => $display,
        ]);

        if ($ok === false) {
            return [
                'ok' => false,
                'id' => 0,
                'errors' => ['Could not create the user: ' . ($db->lastError() ?? 'unknown error')],
                'user' => null,
            ];
        }

        $id = (int) $db->lastInsertId();
        if ($id < 1) {
            return [
                'ok' => false,
                'id' => 0,
                'errors' => ['User insert did not return an ID.'],
                'user' => null,
            ];
        }

        // Profile meta.
        $nickname = ap_sanitize_text_field((string) ($data['nickname'] ?? $display));
        if ($nickname === '') {
            $nickname = $display;
        }
        self::updateMeta($id, 'nickname', $nickname, $db);
        self::updateMeta($id, 'first_name', ap_sanitize_text_field((string) ($data['first_name'] ?? '')), $db);
        self::updateMeta($id, 'last_name', ap_sanitize_text_field((string) ($data['last_name'] ?? '')), $db);
        self::updateMeta($id, 'description', ap_sanitize_textarea_field((string) ($data['description'] ?? '')), $db);
        self::updateMeta($id, 'location', ap_sanitize_text_field((string) ($data['location'] ?? '')), $db);
        if (array_key_exists('signature', $data)) {
            self::updateMeta($id, 'signature', self::sanitizeSignature((string) $data['signature']), $db);
        }

        // Role assignment.
        $role = trim((string) ($data['role'] ?? ''));
        if ($role === '') {
            if (function_exists('ap_get_option')) {
                $role = (string) ap_get_option('default_role', 'subscriber', $db);
            } elseif (class_exists('AP_Options', false)) {
                $role = (string) AP_Options::get('default_role', 'subscriber', $db);
            } else {
                $role = 'subscriber';
            }
        }
        if ($role !== '' && class_exists('AP_Roles', false)) {
            AP_Roles::ensureDefaults($db);
            if (!AP_Roles::roleExists($role, $db)) {
                $role = 'subscriber';
            }
            AP_Roles::setUserRole($id, $role, $db);
        }

        $user = self::getById($id, $db);

        return ['ok' => true, 'id' => $id, 'errors' => [], 'user' => $user];
    }

    /**
     * Update an existing user (core columns + optional profile meta + role).
     *
     * Username (user_login) is never changed after creation.
     * Pass user_pass / password only when changing the password.
     *
     * @param array<string, mixed> $data
     *
     * @return array{ok: bool, id: int, errors: list<string>, user: ?self}
     */
    public static function update(int $id, array $data, ?AP_DB $db = null): array
    {
        if ($id < 1) {
            return ['ok' => false, 'id' => 0, 'errors' => ['Invalid user ID.'], 'user' => null];
        }

        $db = self::resolveDb($db);
        $existing = self::getById($id, $db);
        if ($existing === null) {
            return ['ok' => false, 'id' => $id, 'errors' => ['User not found.'], 'user' => null];
        }

        $errors = [];
        $cols = [];

        if (array_key_exists('user_email', $data) || array_key_exists('email', $data)) {
            $email = self::sanitizeEmail((string) ($data['user_email'] ?? $data['email'] ?? ''));
            if ($email === '') {
                $errors[] = 'Email is required.';
            } elseif (!self::isValidEmail($email)) {
                $errors[] = 'Please enter a valid email address.';
            } else {
                $other = self::getByEmail($email, $db);
                if ($other !== null && $other->ID !== $id) {
                    $errors[] = 'That email address is already registered.';
                } else {
                    $cols['user_email'] = $email;
                }
            }
        }

        if (array_key_exists('user_url', $data) || array_key_exists('url', $data)) {
            $cols['user_url'] = self::sanitizeUrl((string) ($data['user_url'] ?? $data['url'] ?? ''));
        }

        if (array_key_exists('display_name', $data)) {
            $display = ap_sanitize_text_field((string) $data['display_name']);
            if ($display === '') {
                $display = $existing->user_login;
            }
            $cols['display_name'] = $display;
        }

        if (array_key_exists('user_nicename', $data)) {
            $nicename = self::sanitizeNicename((string) $data['user_nicename']);
            if ($nicename === '') {
                $nicename = self::sanitizeNicename($existing->user_login);
            }
            $cols['user_nicename'] = self::uniqueNicename($nicename, $id, $db);
        }

        if (array_key_exists('user_status', $data)) {
            $cols['user_status'] = (int) $data['user_status'];
        }

        $newPassword = null;
        if (array_key_exists('user_pass', $data) || array_key_exists('password', $data)) {
            $password = (string) ($data['user_pass'] ?? $data['password'] ?? '');
            // Empty password field means “leave unchanged”.
            if ($password !== '') {
                if (strlen($password) < 8) {
                    $errors[] = 'Password must be at least 8 characters.';
                } else {
                    $newPassword = $password;
                    $cols['user_pass'] = self::hashPassword($password);
                }
            }
        }

        if ($errors !== []) {
            return ['ok' => false, 'id' => $id, 'errors' => $errors, 'user' => $existing];
        }

        if ($cols !== []) {
            $result = $db->update('users', $cols, ['ID' => $id]);
            if ($result === false) {
                return [
                    'ok' => false,
                    'id' => $id,
                    'errors' => ['Could not update the user: ' . ($db->lastError() ?? 'unknown error')],
                    'user' => $existing,
                ];
            }
        }

        // Profile meta (only when keys present).
        foreach (['first_name', 'last_name', 'nickname', 'location'] as $metaKey) {
            if (array_key_exists($metaKey, $data)) {
                self::updateMeta($id, $metaKey, ap_sanitize_text_field((string) $data[$metaKey]), $db);
            }
        }
        if (array_key_exists('description', $data)) {
            self::updateMeta(
                $id,
                'description',
                ap_sanitize_textarea_field((string) $data['description']),
                $db
            );
        }
        if (array_key_exists('signature', $data)) {
            self::updateMeta($id, 'signature', self::sanitizeSignature((string) $data['signature']), $db);
        }

        // Role (when provided and roles API is available).
        if (array_key_exists('role', $data) && class_exists('AP_Roles', false)) {
            $role = trim((string) $data['role']);
            if ($role !== '') {
                AP_Roles::ensureDefaults($db);
                if (AP_Roles::roleExists($role, $db)) {
                    AP_Roles::setUserRole($id, $role, $db);
                }
            }
        }

        // Password change invalidates sessions (same as updatePassword).
        if ($newPassword !== null && class_exists('AP_Session', false)) {
            AP_Session::destroyAllSessionTokens($id, $db);
            if (function_exists('ap_get_current_user_id') && ap_get_current_user_id($db) === $id) {
                AP_Session::resetCurrentUser();
            }
        }

        $user = self::getById($id, $db);

        return ['ok' => true, 'id' => $id, 'errors' => [], 'user' => $user];
    }

    /**
     * Permanently delete a user and all usermeta.
     *
     * Does not reassign posts (caller may do that later). Returns false when
     * the user does not exist or the delete fails.
     */
    public static function delete(int $id, ?AP_DB $db = null): bool
    {
        if ($id < 1) {
            return false;
        }

        $db = self::resolveDb($db);
        $existing = self::getById($id, $db);
        if ($existing === null) {
            return false;
        }

        if (class_exists('AP_Session', false)) {
            AP_Session::destroyAllSessionTokens($id, $db);
        }

        // Drop local avatar file before wiping usermeta (meta holds the attachment id).
        if (class_exists('AP_Avatar', false)) {
            AP_Avatar::deleteLocal($id, true, $db);
        }

        self::deleteAllMeta($id, $db);

        $result = $db->delete('users', ['ID' => $id]);
        if ($result === false) {
            return false;
        }

        if (class_exists('AP_Roles', false)) {
            AP_Roles::flushCache();
        }

        return true;
    }

    /**
     * Query users with optional search, role filter, and pagination.
     *
     * @param array{
     *     search?: string,
     *     role?: string,
     *     orderby?: string,
     *     order?: string,
     *     number?: int,
     *     offset?: int,
     *     include?: list<int>,
     *     exclude?: list<int>
     * } $args
     *
     * @return list<self>
     */
    public static function query(array $args = [], ?AP_DB $db = null): array
    {
        $db = self::resolveDb($db);
        [$sql, $params] = self::buildQuerySql($args, false, $db);
        $rows = $db->getResults($sql, $params);
        if (!is_array($rows)) {
            return [];
        }

        $users = [];
        foreach ($rows as $row) {
            $users[] = self::fromRow($row);
        }

        return $users;
    }

    /**
     * Count users matching the same filters as {@see self::query()}.
     *
     * @param array<string, mixed> $args
     */
    public static function count(array $args = [], ?AP_DB $db = null): int
    {
        $db = self::resolveDb($db);
        [$sql, $params] = self::buildQuerySql($args, true, $db);
        $n = $db->getVar($sql, $params);

        return max(0, (int) $n);
    }

    /**
     * Count users per role (including those with no registered role as '').
     *
     * @return array<string, int> role slug => count; key '' = no role
     */
    public static function countByRole(?AP_DB $db = null): array
    {
        $db = self::resolveDb($db);
        $counts = ['' => 0];
        if (class_exists('AP_Roles', false)) {
            AP_Roles::ensureDefaults($db);
            foreach (array_keys(AP_Roles::getRoles($db)) as $slug) {
                $counts[(string) $slug] = 0;
            }
        }

        $users = self::query(['number' => 0], $db);
        foreach ($users as $user) {
            $role = '';
            if (class_exists('AP_Roles', false)) {
                $role = AP_Roles::getUserRole($user->ID, $db);
            }
            if (!isset($counts[$role])) {
                $counts[$role] = 0;
            }
            $counts[$role]++;
        }

        return $counts;
    }

    /**
     * Count published/public content posts authored by a user (not revisions).
     */
    public static function countPosts(int $userId, ?AP_DB $db = null): int
    {
        if ($userId < 1) {
            return 0;
        }

        $db = self::resolveDb($db);
        try {
            $table = $db->quoteIdentifier($db->table('posts'));
            $n = $db->getVar(
                'SELECT COUNT(*) FROM ' . $table
                . ' WHERE ' . $db->quoteIdentifier('post_author') . ' = ?'
                . ' AND ' . $db->quoteIdentifier('post_type') . ' IN (?, ?)'
                . ' AND ' . $db->quoteIdentifier('post_status') . ' NOT IN (?, ?, ?)',
                [$userId, 'post', 'page', 'trash', 'auto-draft', 'inherit']
            );

            return max(0, (int) $n);
        } catch (Throwable) {
            return 0;
        }
    }

    // -------------------------------------------------------------------------
    // Usermeta
    // -------------------------------------------------------------------------

    /**
     * Fetch a single usermeta value (first row for the key), or null.
     */
    public static function getMeta(int $userId, string $metaKey, ?AP_DB $db = null): ?string
    {
        if ($userId < 1 || $metaKey === '') {
            return null;
        }

        $db = self::resolveDb($db);
        try {
            $raw = $db->getVar(
                'SELECT meta_value FROM ' . $db->quoteIdentifier($db->table('usermeta'))
                . ' WHERE user_id = ? AND meta_key = ? LIMIT 1',
                [$userId, $metaKey]
            );
        } catch (Throwable) {
            return null;
        }

        if ($raw === null) {
            return null;
        }

        return (string) $raw;
    }

    /**
     * Insert or update a usermeta key.
     */
    public static function updateMeta(
        int $userId,
        string $metaKey,
        string $metaValue,
        ?AP_DB $db = null
    ): bool {
        if ($userId < 1 || $metaKey === '') {
            return false;
        }

        $db = self::resolveDb($db);
        try {
            $existing = $db->getVar(
                'SELECT umeta_id FROM ' . $db->quoteIdentifier($db->table('usermeta'))
                . ' WHERE user_id = ? AND meta_key = ? LIMIT 1',
                [$userId, $metaKey]
            );

            if ($existing !== null && $existing !== '') {
                return $db->update(
                    'usermeta',
                    ['meta_value' => $metaValue],
                    [
                        'user_id' => $userId,
                        'meta_key' => $metaKey,
                    ]
                ) !== false;
            }

            return $db->insert('usermeta', [
                'user_id' => $userId,
                'meta_key' => $metaKey,
                'meta_value' => $metaValue,
            ]) !== false;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Delete one usermeta key for a user.
     */
    public static function deleteMeta(int $userId, string $metaKey, ?AP_DB $db = null): bool
    {
        if ($userId < 1 || $metaKey === '') {
            return false;
        }

        $db = self::resolveDb($db);
        try {
            return $db->delete('usermeta', [
                'user_id' => $userId,
                'meta_key' => $metaKey,
            ]) !== false;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Delete all usermeta rows for a user.
     */
    public static function deleteAllMeta(int $userId, ?AP_DB $db = null): bool
    {
        if ($userId < 1) {
            return false;
        }

        $db = self::resolveDb($db);
        try {
            // Delete by user_id only.
            return $db->delete('usermeta', ['user_id' => $userId]) !== false;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Max stored length for forum signatures (user meta `signature`).
     * Soft cap keeps topic views compact; BBCode/Markdown still allowed within it.
     */
    public const SIGNATURE_MAX_LENGTH = 500;

    /**
     * Sanitize a forum signature for storage (textarea rules + length cap).
     */
    public static function sanitizeSignature(string $value): string
    {
        $value = function_exists('ap_sanitize_textarea_field')
            ? ap_sanitize_textarea_field($value)
            : trim(str_replace("\0", '', $value));
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $max = self::SIGNATURE_MAX_LENGTH;
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($value) > $max) {
                $value = mb_substr($value, 0, $max);
            }
        } elseif (strlen($value) > $max) {
            $value = substr($value, 0, $max);
        }

        return $value;
    }

    /**
     * Profile meta bag used by admin forms and public profile surfaces.
     *
     * `location` is optional free-text (city/region); shown in the forum topic
     * author pane when non-empty (SPEC B2).
     * `signature` is optional free-text shown under forum posts when the global
     * “enable signatures” setting is on and the value is non-empty (SPEC B2).
     *
     * @return array{
     *   first_name: string,
     *   last_name: string,
     *   nickname: string,
     *   description: string,
     *   location: string,
     *   signature: string
     * }
     */
    public static function getProfileMeta(int $userId, ?AP_DB $db = null): array
    {
        $db = self::resolveDb($db);

        return [
            'first_name' => (string) (self::getMeta($userId, 'first_name', $db) ?? ''),
            'last_name' => (string) (self::getMeta($userId, 'last_name', $db) ?? ''),
            'nickname' => (string) (self::getMeta($userId, 'nickname', $db) ?? ''),
            'description' => (string) (self::getMeta($userId, 'description', $db) ?? ''),
            'location' => (string) (self::getMeta($userId, 'location', $db) ?? ''),
            'signature' => (string) (self::getMeta($userId, 'signature', $db) ?? ''),
        ];
    }

    /**
     * Batch-load one usermeta key for many users (topic author pane; avoid N+1).
     *
     * @param list<int> $userIds
     *
     * @return array<int, string> user_id => meta value (missing keys omitted / empty string)
     */
    public static function getMetaForUsers(array $userIds, string $metaKey, ?AP_DB $db = null): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn ($id): int => (int) $id, $userIds),
            static fn (int $id): bool => $id > 0
        )));
        $metaKey = trim($metaKey);
        if ($ids === [] || $metaKey === '') {
            return [];
        }

        $db = self::resolveDb($db);
        $table = $db->quoteIdentifier($db->table('usermeta'));
        $idPh = implode(', ', array_fill(0, count($ids), '?'));
        $params = array_merge($ids, [$metaKey]);
        $out = [];
        foreach ($ids as $id) {
            $out[$id] = '';
        }

        try {
            $rows = $db->getResults(
                'SELECT ' . $db->quoteIdentifier('user_id') . ', '
                . $db->quoteIdentifier('meta_value')
                . ' FROM ' . $table
                . ' WHERE ' . $db->quoteIdentifier('user_id') . ' IN (' . $idPh . ')'
                . ' AND ' . $db->quoteIdentifier('meta_key') . ' = ?',
                $params
            );
        } catch (Throwable) {
            foreach ($ids as $id) {
                $out[$id] = (string) (self::getMeta($id, $metaKey, $db) ?? '');
            }

            return $out;
        }

        foreach ($rows as $row) {
            $uid = (int) (is_object($row) ? ($row->user_id ?? 0) : ($row['user_id'] ?? 0));
            $raw = is_object($row) ? ($row->meta_value ?? '') : ($row['meta_value'] ?? '');
            if ($uid > 0 && array_key_exists($uid, $out)) {
                $out[$uid] = (string) $raw;
            }
        }

        return $out;
    }

    // -------------------------------------------------------------------------
    // Sanitization / helpers
    // -------------------------------------------------------------------------

    /**
     * Sanitize a login name: strip tags, spaces → empty, allow a-z0-9 _ . @ - +
     * Lowercased for consistency.
     */
    public static function sanitizeUserLogin(string $login): string
    {
        $login = trim($login);
        $login = strip_tags($login);
        $login = preg_replace('/\s+/', '', $login) ?? '';
        // Disallow control chars and path-ish characters.
        $login = preg_replace('/[^\w.@+\-]/u', '', $login) ?? '';
        $login = trim($login);

        return $login;
    }

    /**
     * URL-safe nicename from a display string or login.
     */
    public static function sanitizeNicename(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9\-]+/', '-', $value) ?? '';
        $value = trim($value, '-');
        if (strlen($value) > 50) {
            $value = substr($value, 0, 50);
            $value = rtrim($value, '-');
        }

        return $value;
    }

    /**
     * Sanitize a website URL (empty allowed).
     */
    public static function sanitizeUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        // Add scheme when missing so filter_var accepts common inputs.
        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }
        $filtered = filter_var($url, FILTER_SANITIZE_URL);
        if (!is_string($filtered) || $filtered === '') {
            return '';
        }
        if (filter_var($filtered, FILTER_VALIDATE_URL) === false) {
            return '';
        }
        if (strlen($filtered) > 100) {
            $filtered = substr($filtered, 0, 100);
        }

        return $filtered;
    }

    /**
     * Normalize an email address.
     */
    public static function sanitizeEmail(string $email): string
    {
        $email = trim($email);
        $email = str_replace(["\r", "\n", "\0", ' '], '', $email);
        if (strlen($email) > 100) {
            $email = substr($email, 0, 100);
        }

        return $email;
    }

    /**
     * Whether the email looks valid.
     */
    public static function isValidEmail(string $email): bool
    {
        if ($email === '') {
            return false;
        }

        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Generate a random password (cryptographically secure when possible).
     */
    public static function generatePassword(int $length = 16): string
    {
        $length = max(8, min(64, $length));
        $alphabet = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#$%^&*';
        $max = strlen($alphabet) - 1;
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }

        return $out;
    }

    /**
     * Ensure nicename is unique (appends -2, -3, … when needed).
     */
    public static function uniqueNicename(string $nicename, int $excludeId = 0, ?AP_DB $db = null): string
    {
        $db = self::resolveDb($db);
        $base = $nicename !== '' ? $nicename : 'user';
        $candidate = $base;
        $n = 2;
        while (true) {
            $existing = self::getByNicename($candidate, $db);
            if ($existing === null || ($excludeId > 0 && $existing->ID === $excludeId)) {
                return $candidate;
            }
            $suffix = '-' . $n;
            $trimLen = 50 - strlen($suffix);
            $candidate = ($trimLen > 0 ? substr($base, 0, $trimLen) : 'user') . $suffix;
            $candidate = trim($candidate, '-');
            $n++;
            if ($n > 1000) {
                return $base . '-' . bin2hex(random_bytes(3));
            }
        }
    }

    /**
     * Whether the user is the last remaining administrator.
     */
    public static function isLastAdministrator(int $userId, ?AP_DB $db = null): bool
    {
        if ($userId < 1 || !class_exists('AP_Roles', false)) {
            return false;
        }

        $db = self::resolveDb($db);
        $role = AP_Roles::getUserRole($userId, $db);
        if ($role !== 'administrator') {
            return false;
        }

        $admins = 0;
        foreach (self::query(['role' => 'administrator', 'number' => 0], $db) as $user) {
            $admins++;
            if ($admins > 1) {
                return false;
            }
        }

        return $admins === 1;
    }

    /**
     * Build SELECT / COUNT SQL for user queries.
     *
     * @param array<string, mixed> $args
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private static function buildQuerySql(array $args, bool $countOnly, AP_DB $db): array
    {
        $users = $db->quoteIdentifier($db->table('users'));
        $idCol = $db->quoteIdentifier('ID');
        $loginCol = $db->quoteIdentifier('user_login');
        $emailCol = $db->quoteIdentifier('user_email');
        $displayCol = $db->quoteIdentifier('display_name');
        $registeredCol = $db->quoteIdentifier('user_registered');

        $role = trim((string) ($args['role'] ?? ''));
        $search = trim((string) ($args['search'] ?? ''));
        $include = $args['include'] ?? [];
        $exclude = $args['exclude'] ?? [];
        if (!is_array($include)) {
            $include = [];
        }
        if (!is_array($exclude)) {
            $exclude = [];
        }
        $include = array_values(array_filter(array_map('intval', $include), static fn (int $i): bool => $i > 0));
        $exclude = array_values(array_filter(array_map('intval', $exclude), static fn (int $i): bool => $i > 0));

        $joins = '';
        $where = ['1=1'];
        $params = [];

        if ($role !== '') {
            $usermeta = $db->quoteIdentifier($db->table('usermeta'));
            $metaKey = class_exists('AP_Roles', false)
                ? AP_Roles::META_CAPABILITIES
                : 'ap_capabilities';
            // Role membership is stored as a serialized map key; match quoted slug.
            $joins .= ' INNER JOIN ' . $usermeta . ' um_role ON um_role.user_id = u.' . $idCol
                . ' AND um_role.meta_key = ? AND um_role.meta_value LIKE ?';
            $params[] = $metaKey;
            // Role slug is a controlled token; strip LIKE wildcards from it.
            $roleToken = str_replace(['%', '_'], '', $role);
            $params[] = '%"' . $roleToken . '"%';
        }

        if ($search !== '') {
            // Treat %/_ as literals by stripping them (search still useful).
            $needle = str_replace(['%', '_'], '', $search);
            $like = '%' . $needle . '%';
            $where[] = '(u.' . $loginCol . ' LIKE ? OR u.' . $emailCol
                . ' LIKE ? OR u.' . $displayCol . ' LIKE ?)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        if ($include !== []) {
            $placeholders = implode(', ', array_fill(0, count($include), '?'));
            $where[] = 'u.' . $idCol . ' IN (' . $placeholders . ')';
            foreach ($include as $i) {
                $params[] = $i;
            }
        }

        if ($exclude !== []) {
            $placeholders = implode(', ', array_fill(0, count($exclude), '?'));
            $where[] = 'u.' . $idCol . ' NOT IN (' . $placeholders . ')';
            foreach ($exclude as $i) {
                $params[] = $i;
            }
        }

        $whereSql = implode(' AND ', $where);

        if ($countOnly) {
            $sql = 'SELECT COUNT(DISTINCT u.' . $idCol . ') FROM ' . $users . ' u' . $joins
                . ' WHERE ' . $whereSql;

            return [$sql, $params];
        }

        $orderby = strtolower((string) ($args['orderby'] ?? 'login'));
        $orderCol = match ($orderby) {
            'id' => 'u.' . $idCol,
            'email' => 'u.' . $emailCol,
            'registered', 'date' => 'u.' . $registeredCol,
            'display_name', 'name' => 'u.' . $displayCol,
            default => 'u.' . $loginCol,
        };
        $order = strtoupper((string) ($args['order'] ?? 'ASC')) === 'DESC' ? 'DESC' : 'ASC';

        $number = (int) ($args['number'] ?? 20);
        // number=0 means no LIMIT (all matching rows).
        $offset = max(0, (int) ($args['offset'] ?? 0));

        $sql = 'SELECT DISTINCT ' . self::selectColumnsPrefixed($db, 'u')
            . ' FROM ' . $users . ' u' . $joins
            . ' WHERE ' . $whereSql
            . ' ORDER BY ' . $orderCol . ' ' . $order;

        if ($number > 0) {
            $sql .= ' LIMIT ' . max(1, min(500, $number)) . ' OFFSET ' . $offset;
        }

        return [$sql, $params];
    }
}
