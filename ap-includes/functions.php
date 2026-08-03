<?php

/**
 * AgoraPress core helper functions.
 *
 * Hybrid procedural surface familiar to classic WordPress developers.
 * Class implementations live in class-ap-*.php; thin ap_* wrappers here.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * Hash a password with Argon2id when available.
 *
 * @see AP_User::hashPassword()
 */
function ap_hash_password(string $password): string
{
    return AP_User::hashPassword($password);
}

/**
 * Verify a plain password against a stored hash.
 *
 * @see AP_User::checkPassword()
 */
function ap_check_password(string $password, string $hash): bool
{
    return AP_User::checkPassword($password, $hash);
}

/**
 * Whether a stored hash should be upgraded to the preferred algorithm.
 *
 * @see AP_User::passwordNeedsRehash()
 */
function ap_password_needs_rehash(string $hash): bool
{
    return AP_User::passwordNeedsRehash($hash);
}

/**
 * Authenticate a user by login or email + password.
 *
 * Returns an AP_User on success, or null on failure. Does not start a
 * session or set cookies — use {@see ap_login()} for that.
 *
 * @see AP_User::authenticate()
 */
function ap_authenticate(string $loginOrEmail, string $password, ?AP_DB $db = null): ?AP_User
{
    return AP_User::authenticate($loginOrEmail, $password, $db);
}

/**
 * Load a user by field: id | login | email | slug.
 *
 * @see AP_User::getBy()
 */
function ap_get_user_by(string $field, string|int $value, ?AP_DB $db = null): ?AP_User
{
    return AP_User::getBy($field, $value, $db);
}

/**
 * Authenticate and establish a signed session cookie on success.
 *
 * @see AP_Session::login()
 */
function ap_login(
    string $loginOrEmail,
    string $password,
    bool $remember = false,
    ?AP_DB $db = null
): ?AP_User {
    return AP_Session::login($loginOrEmail, $password, $remember, $db);
}

/**
 * Destroy the current session token and clear the auth cookie.
 *
 * @see AP_Session::logout()
 */
function ap_logout(?AP_DB $db = null): void
{
    AP_Session::logout($db);
}

/**
 * Issue a session cookie for an already-authenticated user id.
 *
 * @see AP_Session::setAuthCookie()
 */
function ap_set_auth_cookie(int $userId, bool $remember = false, ?AP_DB $db = null): bool
{
    return AP_Session::setAuthCookie($userId, $remember, $db);
}

/**
 * Clear the auth cookie in the browser (does not revoke other devices).
 *
 * @see AP_Session::clearAuthCookie()
 */
function ap_clear_auth_cookie(): void
{
    AP_Session::clearAuthCookie();
}

/**
 * Whether the current request is logged in via a valid auth cookie.
 *
 * @see AP_Session::isLoggedIn()
 */
function ap_is_user_logged_in(?AP_DB $db = null): bool
{
    return AP_Session::isLoggedIn($db);
}

/**
 * Current user id from a valid auth cookie, or 0.
 *
 * @see AP_Session::getCurrentUserId()
 */
function ap_get_current_user_id(?AP_DB $db = null): int
{
    return AP_Session::getCurrentUserId($db);
}

/**
 * Current AP_User from a valid auth cookie, or null when guest.
 *
 * @see AP_Session::getCurrentUser()
 */
function ap_get_current_user(?AP_DB $db = null): ?AP_User
{
    return AP_Session::getCurrentUser($db);
}

/**
 * Name of the logged-in auth cookie for this install.
 *
 * @see AP_Session::cookieName()
 */
function ap_auth_cookie_name(): string
{
    return AP_Session::cookieName();
}

/**
 * Revoke every session token for a user (e.g. after password change).
 *
 * @see AP_Session::destroyAllSessionTokens()
 */
function ap_destroy_user_sessions(int $userId, ?AP_DB $db = null): void
{
    AP_Session::destroyAllSessionTokens($userId, $db);
}
