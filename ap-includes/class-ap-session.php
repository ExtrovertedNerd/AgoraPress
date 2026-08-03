<?php

/**
 * AgoraPress session handling — signed auth cookies + session tokens.
 *
 * After {@see AP_User::authenticate()}, call {@see AP_Session::setAuthCookie()}
 * (or {@see AP_Session::login()}) to establish a logged-in session. Cookies are
 * HMAC-SHA256 signed with AP_LOGGED_IN_KEY + AP_LOGGED_IN_SALT and bound to a
 * per-session token stored in usermeta (so logout / password change can revoke
 * sessions server-side).
 *
 * Cookie value format: user_id|expiration|token|hmac
 *
 * @package AgoraPress
 */

declare(strict_types=1);

// Duration helpers when not already defined by a later constants file.
if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}
if (!defined('YEAR_IN_SECONDS')) {
    define('YEAR_IN_SECONDS', 365 * DAY_IN_SECONDS);
}

/**
 * Auth cookie and session-token manager.
 */
class AP_Session
{
    /** Usermeta key holding the JSON session token map. */
    public const META_SESSION_TOKENS = 'session_tokens';

    /** Default cookie lifetime when “remember me” is off (2 days). */
    public const LIFETIME_DEFAULT = 2 * 86400;

    /** Cookie lifetime when “remember me” is on (14 days). */
    public const LIFETIME_REMEMBER = 14 * 86400;

    /** @var array<string, string>|null When non-null, cookie I/O uses this bag (tests). */
    private static ?array $testCookies = null;

    /** @var AP_User|false|null Request-local current user cache (false = not resolved). */
    private static AP_User|false|null $currentUser = false;

    /** @var string|null Token from the validated cookie for this request (for logout). */
    private static ?string $currentToken = null;

    /**
     * Enable in-memory cookie bag (no setcookie / $_COOKIE). Used by unit tests.
     *
     * @param array<string, string> $cookies Initial cookie name => value map.
     */
    public static function enableTestMode(array $cookies = []): void
    {
        self::$testCookies = $cookies;
        self::resetCurrentUser();
    }

    /**
     * Disable test mode and clear the cookie bag.
     */
    public static function disableTestMode(): void
    {
        self::$testCookies = null;
        self::resetCurrentUser();
    }

    /**
     * Whether cookie I/O is using the test bag.
     */
    public static function isTestMode(): bool
    {
        return self::$testCookies !== null;
    }

    /**
     * Cookies written while test mode is active.
     *
     * @return array<string, string>
     */
    public static function getTestCookies(): array
    {
        return self::$testCookies ?? [];
    }

    /**
     * Drop the request-local current-user cache (after login/logout or in tests).
     */
    public static function resetCurrentUser(): void
    {
        self::$currentUser = false;
        self::$currentToken = null;
    }

    /**
     * Name of the logged-in auth cookie.
     *
     * Includes a short hash of the logged-in key so distinct installs do not
     * collide when sharing a parent domain (cookie path still defaults to /).
     */
    public static function cookieName(): string
    {
        $material = self::loggedInKey() . self::loggedInSalt();
        if ($material === '') {
            $material = 'agorapress';
        }

        return 'ap_logged_in_' . substr(hash('sha256', $material), 0, 12);
    }

    /**
     * Cookie path (site-wide).
     */
    public static function cookiePath(): string
    {
        return '/';
    }

    /**
     * Absolute expiration unix timestamp for a new cookie.
     */
    public static function cookieExpiration(bool $remember = false): int
    {
        $lifetime = $remember ? self::LIFETIME_REMEMBER : self::LIFETIME_DEFAULT;

        return time() + $lifetime;
    }

    /**
     * Authenticate credentials and establish a session cookie on success.
     *
     * @return AP_User|null Authenticated user, or null on failure.
     */
    public static function login(
        string $loginOrEmail,
        string $password,
        bool $remember = false,
        ?AP_DB $db = null
    ): ?AP_User {
        $user = AP_User::authenticate($loginOrEmail, $password, $db);
        if ($user === null) {
            return null;
        }

        if (!self::setAuthCookie($user->ID, $remember, $db, $user)) {
            return null;
        }

        return $user;
    }

    /**
     * Destroy the current session token (if any) and clear the auth cookie.
     */
    public static function logout(?AP_DB $db = null): void
    {
        $userId = self::getCurrentUserId($db);
        $token = self::$currentToken;

        if ($userId > 0 && is_string($token) && $token !== '') {
            self::destroySessionToken($userId, $token, $db);
        }

        self::clearAuthCookie();
        self::resetCurrentUser();
    }

    /**
     * Issue a session token and set the signed auth cookie for a user id.
     *
     * @param AP_User|null $user Optional preloaded user (avoids a second DB hit).
     */
    public static function setAuthCookie(
        int $userId,
        bool $remember = false,
        ?AP_DB $db = null,
        ?AP_User $user = null
    ): bool {
        if ($userId < 1) {
            return false;
        }

        $user ??= AP_User::getById($userId, $db);
        if ($user === null || $user->user_status !== 0) {
            return false;
        }

        $expiration = self::cookieExpiration($remember);
        $token = self::createSessionToken($user->ID, $expiration, $db);
        if ($token === '') {
            return false;
        }

        $cookie = self::generateAuthCookie($user, $expiration, $token);
        self::writeCookie(self::cookieName(), $cookie, $expiration);
        self::resetCurrentUser();
        self::$currentUser = $user;
        self::$currentToken = $token;

        return true;
    }

    /**
     * Expire the auth cookie in the browser (does not touch usermeta tokens).
     */
    public static function clearAuthCookie(): void
    {
        $name = self::cookieName();
        self::writeCookie($name, ' ', time() - YEAR_IN_SECONDS);
        if (self::$testCookies !== null) {
            unset(self::$testCookies[$name]);
        }
    }

    /**
     * Whether the current request has a valid auth cookie for an active user.
     */
    public static function isLoggedIn(?AP_DB $db = null): bool
    {
        return self::getCurrentUserId($db) > 0;
    }

    /**
     * Current user id from a valid auth cookie, or 0.
     */
    public static function getCurrentUserId(?AP_DB $db = null): int
    {
        $user = self::getCurrentUser($db);

        return $user !== null ? $user->ID : 0;
    }

    /**
     * Current AP_User from a valid auth cookie, or null when guest / invalid.
     */
    public static function getCurrentUser(?AP_DB $db = null): ?AP_User
    {
        if (self::$currentUser !== false) {
            return self::$currentUser;
        }

        $cookie = self::readCookie(self::cookieName());
        $user = self::validateAuthCookie($cookie, $db);
        self::$currentUser = $user;

        return $user;
    }

    /**
     * Build a signed cookie value (does not send headers).
     */
    public static function generateAuthCookie(AP_User $user, int $expiration, string $token): string
    {
        $userId = (string) $user->ID;
        $passFrag = self::passwordFragment($user->user_pass);
        $payload = $userId . '|' . $expiration . '|' . $token . '|' . $user->user_login . '|' . $passFrag;
        $hmac = hash_hmac('sha256', $payload, self::hmacKey());

        return $userId . '|' . $expiration . '|' . $token . '|' . $hmac;
    }

    /**
     * Validate a cookie string and return the user when the session is good.
     *
     * Checks structure, expiration, HMAC (including password fragment), active
     * account status, and presence of the session token in usermeta.
     */
    public static function validateAuthCookie(?string $cookie, ?AP_DB $db = null): ?AP_User
    {
        if ($cookie === null || $cookie === '' || $cookie === ' ') {
            return null;
        }

        $parts = explode('|', $cookie);
        if (count($parts) !== 4) {
            return null;
        }

        [$userIdRaw, $expirationRaw, $token, $hmac] = $parts;
        if ($userIdRaw === '' || $expirationRaw === '' || $token === '' || $hmac === '') {
            return null;
        }

        if (!ctype_digit($userIdRaw) || !ctype_digit($expirationRaw)) {
            return null;
        }

        $userId = (int) $userIdRaw;
        $expiration = (int) $expirationRaw;

        if ($userId < 1 || $expiration < time()) {
            return null;
        }

        // Reject oversized tokens (defense in depth).
        if (strlen($token) > 128 || strlen($hmac) > 128) {
            return null;
        }

        $user = AP_User::getById($userId, $db);
        if ($user === null || $user->user_status !== 0) {
            return null;
        }

        $passFrag = self::passwordFragment($user->user_pass);
        $payload = $userIdRaw . '|' . $expirationRaw . '|' . $token . '|' . $user->user_login . '|' . $passFrag;
        $expected = hash_hmac('sha256', $payload, self::hmacKey());

        if (!hash_equals($expected, $hmac)) {
            return null;
        }

        if (!self::sessionTokenIsValid($userId, $token, $db)) {
            return null;
        }

        self::$currentToken = $token;

        return $user;
    }

    /**
     * Create a random session token, store its hash in usermeta, return the raw token.
     */
    public static function createSessionToken(int $userId, int $expiration, ?AP_DB $db = null): string
    {
        if ($userId < 1) {
            return '';
        }

        try {
            $token = bin2hex(random_bytes(32));
        } catch (Throwable) {
            $token = hash('sha256', uniqid((string) $userId, true) . microtime(true));
        }

        $sessions = self::getSessionTokens($userId, $db);
        $sessions = self::pruneExpiredSessions($sessions);
        $sessions[self::hashToken($token)] = [
            'expiration' => $expiration,
            'login' => time(),
        ];
        self::saveSessionTokens($userId, $sessions, $db);

        return $token;
    }

    /**
     * Remove one session token for a user.
     */
    public static function destroySessionToken(int $userId, string $token, ?AP_DB $db = null): void
    {
        if ($userId < 1 || $token === '') {
            return;
        }

        $sessions = self::getSessionTokens($userId, $db);
        $hash = self::hashToken($token);
        if (!isset($sessions[$hash])) {
            return;
        }

        unset($sessions[$hash]);
        self::saveSessionTokens($userId, $sessions, $db);
    }

    /**
     * Revoke every session for a user (e.g. after password change).
     */
    public static function destroyAllSessionTokens(int $userId, ?AP_DB $db = null): void
    {
        if ($userId < 1) {
            return;
        }

        self::saveSessionTokens($userId, [], $db);
    }

    /**
     * Whether a raw session token is registered and not expired for the user.
     */
    public static function sessionTokenIsValid(int $userId, string $token, ?AP_DB $db = null): bool
    {
        if ($userId < 1 || $token === '') {
            return false;
        }

        $sessions = self::getSessionTokens($userId, $db);
        $hash = self::hashToken($token);
        if (!isset($sessions[$hash]) || !is_array($sessions[$hash])) {
            return false;
        }

        $expiration = (int) ($sessions[$hash]['expiration'] ?? 0);

        return $expiration >= time();
    }

    /**
     * Fragment of the password hash mixed into the cookie HMAC so a password
     * change invalidates outstanding cookies even before token cleanup runs.
     */
    public static function passwordFragment(string $userPass): string
    {
        if ($userPass === '') {
            return 'none';
        }

        // Skip algo header when present ($argon2id$… / $2y$…) — take mid-slice.
        if (strlen($userPass) >= 12) {
            return substr($userPass, 8, 4);
        }

        return substr(hash('sha256', $userPass), 0, 4);
    }

    /**
     * @return array<string, array{expiration: int, login?: int}>
     */
    public static function getSessionTokens(int $userId, ?AP_DB $db = null): array
    {
        $raw = self::getUserMeta($userId, self::META_SESSION_TOKENS, $db);
        if ($raw === null || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $hash => $data) {
            if (!is_string($hash) || $hash === '' || !is_array($data)) {
                continue;
            }
            $out[$hash] = [
                'expiration' => (int) ($data['expiration'] ?? 0),
                'login' => (int) ($data['login'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * @param array<string, array{expiration: int, login?: int}> $sessions
     */
    public static function saveSessionTokens(int $userId, array $sessions, ?AP_DB $db = null): void
    {
        if ($sessions === []) {
            self::deleteUserMeta($userId, self::META_SESSION_TOKENS, $db);

            return;
        }

        $json = json_encode($sessions, JSON_THROW_ON_ERROR);
        self::updateUserMeta($userId, self::META_SESSION_TOKENS, $json, $db);
    }

    /**
     * @param array<string, array{expiration: int, login?: int}> $sessions
     *
     * @return array<string, array{expiration: int, login?: int}>
     */
    private static function pruneExpiredSessions(array $sessions): array
    {
        $now = time();
        foreach ($sessions as $hash => $data) {
            if ((int) ($data['expiration'] ?? 0) < $now) {
                unset($sessions[$hash]);
            }
        }

        // Cap stored sessions per user to limit usermeta growth.
        if (count($sessions) > 50) {
            uasort(
                $sessions,
                static fn (array $a, array $b): int => ((int) ($a['login'] ?? 0)) <=> ((int) ($b['login'] ?? 0))
            );
            $sessions = array_slice($sessions, -50, null, true);
        }

        return $sessions;
    }

    private static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    private static function hmacKey(): string
    {
        return self::loggedInKey() . self::loggedInSalt();
    }

    private static function loggedInKey(): string
    {
        return defined('AP_LOGGED_IN_KEY') ? (string) AP_LOGGED_IN_KEY : '';
    }

    private static function loggedInSalt(): string
    {
        return defined('AP_LOGGED_IN_SALT') ? (string) AP_LOGGED_IN_SALT : '';
    }

    private static function resolveDb(?AP_DB $db): AP_DB
    {
        if ($db instanceof AP_DB) {
            return $db;
        }

        if (function_exists('ap_db')) {
            return ap_db();
        }

        throw new RuntimeException('No database connection available for session handling.');
    }

    private static function getUserMeta(int $userId, string $metaKey, ?AP_DB $db = null): ?string
    {
        $db = self::resolveDb($db);
        $table = $db->quoteIdentifier($db->table('usermeta'));
        $value = $db->getVar(
            'SELECT ' . $db->quoteIdentifier('meta_value')
            . ' FROM ' . $table
            . ' WHERE ' . $db->quoteIdentifier('user_id') . ' = ?'
            . ' AND ' . $db->quoteIdentifier('meta_key') . ' = ?'
            . ' LIMIT 1',
            [$userId, $metaKey]
        );

        if ($value === null) {
            return null;
        }

        return (string) $value;
    }

    private static function updateUserMeta(int $userId, string $metaKey, string $metaValue, ?AP_DB $db = null): void
    {
        $db = self::resolveDb($db);
        $existing = self::getUserMeta($userId, $metaKey, $db);

        if ($existing === null) {
            $db->insert('usermeta', [
                'user_id' => $userId,
                'meta_key' => $metaKey,
                'meta_value' => $metaValue,
            ]);

            return;
        }

        $db->update(
            'usermeta',
            ['meta_value' => $metaValue],
            [
                'user_id' => $userId,
                'meta_key' => $metaKey,
            ]
        );
    }

    private static function deleteUserMeta(int $userId, string $metaKey, ?AP_DB $db = null): void
    {
        $db = self::resolveDb($db);
        $db->delete('usermeta', [
            'user_id' => $userId,
            'meta_key' => $metaKey,
        ]);
    }

    private static function readCookie(string $name): ?string
    {
        if (self::$testCookies !== null) {
            return self::$testCookies[$name] ?? null;
        }

        if (!isset($_COOKIE[$name])) {
            return null;
        }

        $value = $_COOKIE[$name];

        return is_string($value) ? $value : null;
    }

    private static function writeCookie(string $name, string $value, int $expires): void
    {
        if (self::$testCookies !== null) {
            if ($expires < time() || $value === ' ' || $value === '') {
                unset(self::$testCookies[$name]);
            } else {
                self::$testCookies[$name] = $value;
            }

            return;
        }

        if (headers_sent()) {
            return;
        }

        $options = [
            'expires' => $expires,
            'path' => self::cookiePath(),
            'secure' => self::isSsl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ];

        setcookie($name, $value, $options);

        if ($expires >= time() && $value !== ' ' && $value !== '') {
            $_COOKIE[$name] = $value;
        } else {
            unset($_COOKIE[$name]);
        }
    }

    private static function isSsl(): bool
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }

        if (isset($_SERVER['SERVER_PORT']) && (string) $_SERVER['SERVER_PORT'] === '443') {
            return true;
        }

        if (
            !empty($_SERVER['HTTP_X_FORWARDED_PROTO'])
            && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https'
        ) {
            return true;
        }

        return false;
    }
}
