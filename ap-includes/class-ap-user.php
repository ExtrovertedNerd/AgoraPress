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

        $quoted = [];
        foreach ($cols as $col) {
            $quoted[] = $db->quoteIdentifier($col);
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
}
