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
 * Create a user.
 *
 * @param array<string, mixed> $data
 *
 * @return array{ok: bool, id: int, errors: list<string>, user: ?AP_User}
 *
 * @see AP_User::create()
 */
function ap_create_user(array $data, ?AP_DB $db = null): array
{
    return AP_User::create($data, $db);
}

/**
 * Update a user.
 *
 * @param array<string, mixed> $data
 *
 * @return array{ok: bool, id: int, errors: list<string>, user: ?AP_User}
 *
 * @see AP_User::update()
 */
function ap_update_user(int $id, array $data, ?AP_DB $db = null): array
{
    return AP_User::update($id, $data, $db);
}

/**
 * Permanently delete a user and their usermeta.
 *
 * @see AP_User::delete()
 */
function ap_delete_user(int $id, ?AP_DB $db = null): bool
{
    return AP_User::delete($id, $db);
}

/**
 * Query users (search, role, pagination).
 *
 * @param array<string, mixed> $args
 *
 * @return list<AP_User>
 *
 * @see AP_User::query()
 */
function ap_get_users(array $args = [], ?AP_DB $db = null): array
{
    return AP_User::query($args, $db);
}

/**
 * Count users matching query args.
 *
 * @param array<string, mixed> $args
 *
 * @see AP_User::count()
 */
function ap_count_users(array $args = [], ?AP_DB $db = null): int
{
    return AP_User::count($args, $db);
}

/**
 * Read a usermeta value.
 *
 * @see AP_User::getMeta()
 */
function ap_get_user_meta(int $userId, string $metaKey, ?AP_DB $db = null): ?string
{
    return AP_User::getMeta($userId, $metaKey, $db);
}

/**
 * Insert or update a usermeta value.
 *
 * @see AP_User::updateMeta()
 */
function ap_update_user_meta(
    int $userId,
    string $metaKey,
    string $metaValue,
    ?AP_DB $db = null
): bool {
    return AP_User::updateMeta($userId, $metaKey, $metaValue, $db);
}

/**
 * Delete a usermeta key.
 *
 * @see AP_User::deleteMeta()
 */
function ap_delete_user_meta(int $userId, string $metaKey, ?AP_DB $db = null): bool
{
    return AP_User::deleteMeta($userId, $metaKey, $db);
}

/**
 * Generate a random password.
 *
 * @see AP_User::generatePassword()
 */
function ap_generate_password(int $length = 16): string
{
    return AP_User::generatePassword($length);
}

// -----------------------------------------------------------------------------
// Avatars (local upload + Gravatar)
// -----------------------------------------------------------------------------

/**
 * Whether site-wide avatars are enabled.
 *
 * @see AP_Avatar::isEnabled()
 */
function ap_show_avatars(?AP_DB $db = null): bool
{
    return class_exists('AP_Avatar', false) && AP_Avatar::isEnabled($db);
}

/**
 * Avatar image URL for a user ID, email, AP_User, or comment-like object.
 *
 * @param int|string|object    $idOrEmail
 * @param array<string, mixed> $args
 *
 * @see AP_Avatar::getUrl()
 */
function ap_get_avatar_url(
    int|string|object $idOrEmail,
    int $size = 96,
    array $args = [],
    ?AP_DB $db = null
): string {
    if (!class_exists('AP_Avatar', false)) {
        return '';
    }

    return AP_Avatar::getUrl($idOrEmail, $size, $args, $db);
}

/**
 * Escaped &lt;img&gt; HTML for an avatar (empty when avatars are disabled).
 *
 * @param int|string|object    $idOrEmail
 * @param array<string, mixed> $args
 *
 * @see AP_Avatar::getHtml()
 */
function ap_get_avatar(
    int|string|object $idOrEmail,
    int $size = 96,
    string $default = '',
    string $alt = '',
    array $args = [],
    ?AP_DB $db = null
): string {
    if (!class_exists('AP_Avatar', false)) {
        return '';
    }

    if ($default !== '') {
        $args['default'] = $default;
    }
    if ($alt !== '') {
        $args['alt'] = $alt;
    }

    return AP_Avatar::getHtml($idOrEmail, $size, $args, $db);
}

/**
 * Structured avatar data (url, size, source, …).
 *
 * @param int|string|object    $idOrEmail
 * @param array<string, mixed> $args
 *
 * @return array<string, mixed>
 *
 * @see AP_Avatar::getData()
 */
function ap_get_avatar_data(
    int|string|object $idOrEmail,
    int $size = 96,
    array $args = [],
    ?AP_DB $db = null
): array {
    if (!class_exists('AP_Avatar', false)) {
        return [
            'found' => false,
            'url' => '',
            'size' => $size,
            'width' => $size,
            'height' => $size,
            'alt' => '',
            'class' => 'avatar',
            'extra_attr' => '',
            'source' => 'none',
        ];
    }

    return AP_Avatar::getData($idOrEmail, $size, $args, $db);
}

/**
 * Upload a local avatar for a user (replaces any previous local avatar).
 *
 * @param array<string, mixed> $file $_FILES-style entry
 *
 * @return array{ok: bool, id: int, url: string, error: string}
 *
 * @see AP_Avatar::upload()
 */
function ap_upload_user_avatar(int $userId, array $file, ?AP_DB $db = null): array
{
    if (!class_exists('AP_Avatar', false)) {
        return ['ok' => false, 'id' => 0, 'url' => '', 'error' => 'Avatar layer not available.'];
    }

    return AP_Avatar::upload($userId, $file, $db);
}

/**
 * Remove a user's local avatar (and optionally the attachment file).
 *
 * @see AP_Avatar::deleteLocal()
 */
function ap_delete_user_avatar(int $userId, bool $deleteFile = true, ?AP_DB $db = null): bool
{
    if (!class_exists('AP_Avatar', false)) {
        return false;
    }

    return AP_Avatar::deleteLocal($userId, $deleteFile, $db);
}

// -----------------------------------------------------------------------------
// Registration, email verification, password reset
// -----------------------------------------------------------------------------

/**
 * Whether public registration is open.
 *
 * @see AP_Registration::usersCanRegister()
 */
function ap_users_can_register(?AP_DB $db = null): bool
{
    return AP_Registration::usersCanRegister($db);
}

/**
 * Whether new public accounts must verify email before logging in.
 *
 * @see AP_Registration::requireEmailVerification()
 */
function ap_require_email_verification(?AP_DB $db = null): bool
{
    return AP_Registration::requireEmailVerification($db);
}

/**
 * Registration CAPTCHA / anti-spam mode (`off` or `math`).
 *
 * @see AP_Registration::captchaMode()
 */
function ap_registration_captcha_mode(?AP_DB $db = null): string
{
    return AP_Registration::captchaMode($db);
}

/**
 * Whether optional registration CAPTCHA / anti-spam is enabled.
 *
 * @see AP_Registration::isCaptchaEnabled()
 */
function ap_registration_captcha_enabled(?AP_DB $db = null): bool
{
    return AP_Registration::isCaptchaEnabled($db);
}

/**
 * Build a CAPTCHA challenge for the registration form (empty when disabled).
 *
 * @return array<string, mixed>
 *
 * @see AP_Registration::createCaptchaChallenge()
 */
function ap_registration_create_captcha(?AP_DB $db = null): array
{
    return AP_Registration::createCaptchaChallenge($db);
}

/**
 * Verify registration CAPTCHA / honeypot fields from form data.
 *
 * @param array<string, mixed> $data
 *
 * @return array{ok: bool, errors: list<string>}
 *
 * @see AP_Registration::verifyCaptcha()
 */
function ap_registration_verify_captcha(array $data, ?AP_DB $db = null): array
{
    return AP_Registration::verifyCaptcha($data, $db);
}

/**
 * Register a public account (when users_can_register is enabled).
 *
 * @param array<string, mixed> $data
 *
 * @return array{
 *     ok: bool,
 *     id: int,
 *     errors: list<string>,
 *     user: ?AP_User,
 *     needs_verification: bool,
 *     plain_key: string
 * }
 *
 * @see AP_Registration::register()
 */
function ap_register_user(array $data, ?AP_DB $db = null): array
{
    return AP_Registration::register($data, $db);
}

/**
 * Confirm a registration email with login + key from the verification link.
 *
 * @return array{ok: bool, errors: list<string>, user: ?AP_User}
 *
 * @see AP_Registration::verifyEmail()
 */
function ap_verify_user_email(string $login, string $plainKey, ?AP_DB $db = null): array
{
    return AP_Registration::verifyEmail($login, $plainKey, $db);
}

/**
 * Request a password-reset email (generic success; does not leak account existence).
 *
 * @return array{
 *     ok: bool,
 *     errors: list<string>,
 *     sent: bool,
 *     plain_key: string,
 *     user: ?AP_User
 * }
 *
 * @see AP_Registration::requestPasswordReset()
 */
function ap_request_password_reset(string $loginOrEmail, ?AP_DB $db = null): array
{
    return AP_Registration::requestPasswordReset($loginOrEmail, $db);
}

/**
 * Validate a password-reset key; returns the user or null.
 *
 * @see AP_Registration::checkPasswordResetKey()
 */
function ap_check_password_reset_key(
    string $login,
    string $plainKey,
    ?AP_DB $db = null
): ?AP_User {
    return AP_Registration::checkPasswordResetKey($login, $plainKey, $db);
}

/**
 * Complete a password reset with a valid key.
 *
 * @return array{ok: bool, errors: list<string>, user: ?AP_User}
 *
 * @see AP_Registration::resetPassword()
 */
function ap_reset_password(
    string $login,
    string $plainKey,
    string $newPassword,
    ?AP_DB $db = null
): array {
    return AP_Registration::resetPassword($login, $plainKey, $newPassword, $db);
}

/**
 * Send an email (test-mode capture when AP_Mail::enableTestMode() is active).
 *
 * @param string|list<string>   $to
 * @param array<string, string> $headers
 *
 * @see AP_Mail::send()
 */
function ap_mail(
    string|array $to,
    string $subject,
    string $message,
    array $headers = []
): bool {
    return AP_Mail::send($to, $subject, $message, $headers);
}

/**
 * Authenticate and establish a signed session cookie on success.
 *
 * Applies rate limiting when {@see AP_Rate_Limit} is loaded. On failure see
 * {@see ap_get_last_login_error()}.
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
 * Details of the most recent failed {@see ap_login()} attempt.
 *
 * @return array{code: string, message: string, retry_after: int}|null
 *
 * @see AP_Session::getLastLoginError()
 */
function ap_get_last_login_error(): ?array
{
    if (!class_exists('AP_Session', false)) {
        return null;
    }

    return AP_Session::getLastLoginError();
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
 * Returns false when AP_Session is not loaded (partial bootstrap / unit tests).
 *
 * @see AP_Session::isLoggedIn()
 */
function ap_is_user_logged_in(?AP_DB $db = null): bool
{
    return class_exists('AP_Session', false) && AP_Session::isLoggedIn($db);
}

/**
 * Current user id from a valid auth cookie, or 0.
 *
 * @see AP_Session::getCurrentUserId()
 */
function ap_get_current_user_id(?AP_DB $db = null): int
{
    return class_exists('AP_Session', false) ? AP_Session::getCurrentUserId($db) : 0;
}

/**
 * Current AP_User from a valid auth cookie, or null when guest.
 *
 * @see AP_Session::getCurrentUser()
 */
function ap_get_current_user(?AP_DB $db = null): ?AP_User
{
    return class_exists('AP_Session', false) ? AP_Session::getCurrentUser($db) : null;
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

// -----------------------------------------------------------------------------
// Roles & capabilities
// -----------------------------------------------------------------------------

/**
 * Ensure default roles are seeded (idempotent).
 *
 * @see AP_Roles::ensureDefaults()
 */
function ap_ensure_roles(?AP_DB $db = null): void
{
    AP_Roles::ensureDefaults($db);
}

/**
 * All registered roles.
 *
 * @return array<string, array{name: string, capabilities: array<string, bool>}>
 *
 * @see AP_Roles::getRoles()
 */
function ap_get_roles(?AP_DB $db = null): array
{
    return AP_Roles::getRoles($db);
}

/**
 * Single role definition, or null.
 *
 * @return array{name: string, capabilities: array<string, bool>}|null
 *
 * @see AP_Roles::getRole()
 */
function ap_get_role(string $role, ?AP_DB $db = null): ?array
{
    return AP_Roles::getRole($role, $db);
}

/**
 * Whether a role slug is registered.
 *
 * @see AP_Roles::roleExists()
 */
function ap_role_exists(string $role, ?AP_DB $db = null): bool
{
    return AP_Roles::roleExists($role, $db);
}

/**
 * Role display names (slug => name).
 *
 * @return array<string, string>
 *
 * @see AP_Roles::getRoleNames()
 */
function ap_get_role_names(?AP_DB $db = null): array
{
    return AP_Roles::getRoleNames($db);
}

/**
 * Register a new role.
 *
 * @param array<string, bool> $capabilities
 *
 * @see AP_Roles::addRole()
 */
function ap_add_role(
    string $role,
    string $displayName,
    array $capabilities = [],
    ?AP_DB $db = null
): bool {
    return AP_Roles::addRole($role, $displayName, $capabilities, $db);
}

/**
 * Remove a role from the registry.
 *
 * @see AP_Roles::removeRole()
 */
function ap_remove_role(string $role, ?AP_DB $db = null): bool
{
    return AP_Roles::removeRole($role, $db);
}

/**
 * Grant a capability on a role.
 *
 * @see AP_Roles::addCap()
 */
function ap_add_cap(string $role, string $cap, bool $grant = true, ?AP_DB $db = null): bool
{
    return AP_Roles::addCap($role, $cap, $grant, $db);
}

/**
 * Remove a capability from a role.
 *
 * @see AP_Roles::removeCap()
 */
function ap_remove_cap(string $role, string $cap, ?AP_DB $db = null): bool
{
    return AP_Roles::removeCap($role, $cap, $db);
}

/**
 * Role slugs assigned to a user.
 *
 * @return list<string>
 *
 * @see AP_Roles::getUserRoles()
 */
function ap_get_user_roles(int $userId, ?AP_DB $db = null): array
{
    return AP_Roles::getUserRoles($userId, $db);
}

/**
 * Primary role slug for a user (first assigned), or empty string.
 *
 * @see AP_Roles::getUserRole()
 */
function ap_get_user_role(int $userId, ?AP_DB $db = null): string
{
    return AP_Roles::getUserRole($userId, $db);
}

/**
 * Replace a user's roles with a single role.
 *
 * @see AP_Roles::setUserRole()
 */
function ap_set_user_role(int $userId, string $role, ?AP_DB $db = null): bool
{
    return AP_Roles::setUserRole($userId, $role, $db);
}

/**
 * Add a role to a user without removing existing roles.
 *
 * @see AP_Roles::addUserRole()
 */
function ap_add_user_role(int $userId, string $role, ?AP_DB $db = null): bool
{
    return AP_Roles::addUserRole($userId, $role, $db);
}

/**
 * Remove one role from a user.
 *
 * @see AP_Roles::removeUserRole()
 */
function ap_remove_user_role(int $userId, string $role, ?AP_DB $db = null): bool
{
    return AP_Roles::removeUserRole($userId, $role, $db);
}

/**
 * Effective capabilities for a user.
 *
 * @return array<string, bool>
 *
 * @see AP_Roles::getUserCapabilities()
 */
function ap_get_user_capabilities(int $userId, ?AP_DB $db = null): array
{
    return AP_Roles::getUserCapabilities($userId, $db);
}

/**
 * Whether a user has a capability (meta-caps mapped).
 *
 * @see AP_Roles::userCan()
 */
function ap_user_can(
    int $userId,
    string $capability,
    ?int $objectId = null,
    ?AP_DB $db = null
): bool {
    return AP_Roles::userCan($userId, $capability, $objectId, $db);
}

/**
 * Whether the current user has a capability.
 *
 * @see AP_Roles::currentUserCan()
 */
function ap_current_user_can(
    string $capability,
    ?int $objectId = null,
    ?AP_DB $db = null
): bool {
    return AP_Roles::currentUserCan($capability, $objectId, $db);
}

/**
 * Map a meta capability to primitive capabilities.
 *
 * @return list<string>
 *
 * @see AP_Roles::mapMetaCap()
 */
function ap_map_meta_cap(
    string $cap,
    int $userId,
    ?int $objectId = null,
    ?AP_DB $db = null
): array {
    return AP_Roles::mapMetaCap($cap, $userId, $objectId, $db);
}

// -----------------------------------------------------------------------------
// Posts — statuses, types, CRUD, hierarchy
// -----------------------------------------------------------------------------

/**
 * Register a post status.
 *
 * @param array<string, mixed> $args
 *
 * @see AP_Post::registerStatus()
 */
function ap_register_post_status(string $status, array $args = []): void
{
    AP_Post::registerStatus($status, $args);
}

/**
 * Registered post status object, or null.
 *
 * @return array<string, mixed>|null
 *
 * @see AP_Post::getStatusObject()
 */
function ap_get_post_status_object(string $status): ?array
{
    return AP_Post::getStatusObject($status);
}

/**
 * All registered post statuses.
 *
 * @return array<string, array<string, mixed>>
 *
 * @see AP_Post::getStatuses()
 */
function ap_get_post_statuses(): array
{
    return AP_Post::getStatuses();
}

/**
 * Whether a post status is registered.
 *
 * @see AP_Post::statusExists()
 */
function ap_post_status_exists(string $status): bool
{
    return AP_Post::statusExists($status);
}

/**
 * Register a post type (built-in or lightweight CPT).
 *
 * @param array<string, mixed> $args
 *
 * @see AP_Post::registerType()
 */
function ap_register_post_type(string $type, array $args = []): void
{
    AP_Post::registerType($type, $args);
}

/**
 * Registered post type object, or null.
 *
 * @return array<string, mixed>|null
 *
 * @see AP_Post::getTypeObject()
 */
function ap_get_post_type_object(string $type): ?array
{
    return AP_Post::getTypeObject($type);
}

/**
 * All registered post types.
 *
 * @return array<string, array<string, mixed>>
 *
 * @see AP_Post::getTypes()
 */
function ap_get_post_types(): array
{
    return AP_Post::getTypes();
}

/**
 * Whether a post type is registered.
 *
 * @see AP_Post::typeExists()
 */
function ap_post_type_exists(string $type): bool
{
    return AP_Post::typeExists($type);
}

/**
 * Whether a post type is hierarchical (e.g. pages).
 *
 * @see AP_Post::typeIsHierarchical()
 */
function ap_is_post_type_hierarchical(string $type): bool
{
    return AP_Post::typeIsHierarchical($type);
}

/**
 * Whether a post type supports a feature.
 *
 * @see AP_Post::typeSupports()
 */
function ap_post_type_supports(string $type, string $feature): bool
{
    return AP_Post::typeSupports($type, $feature);
}

/**
 * Fetch a post by ID.
 *
 * @see AP_Post::get()
 */
function ap_get_post(int $id, ?AP_DB $db = null): ?AP_Post
{
    return AP_Post::get($id, $db);
}

/**
 * Fetch a post by slug and optional type.
 *
 * @see AP_Post::getBySlug()
 */
function ap_get_post_by_slug(string $slug, string $type = '', ?AP_DB $db = null): ?AP_Post
{
    return AP_Post::getBySlug($slug, $type, $db);
}

/**
 * Insert a post. Returns the new ID, or 0 on failure.
 *
 * @param array<string, mixed> $data
 * @param array<string, mixed> $args
 *
 * @see AP_Post::insert()
 */
function ap_insert_post(array $data, ?AP_DB $db = null, array $args = []): int
{
    return AP_Post::insert($data, $db, $args);
}

/**
 * Update a post by ID.
 *
 * @param array<string, mixed> $data
 * @param array<string, mixed> $args
 *
 * @see AP_Post::update()
 */
function ap_update_post(int $id, array $data, ?AP_DB $db = null, array $args = []): bool
{
    return AP_Post::update($id, $data, $db, $args);
}

/**
 * Soft-delete a post (status = trash).
 *
 * @see AP_Post::trash()
 */
function ap_trash_post(int $id, ?AP_DB $db = null): bool
{
    return AP_Post::trash($id, $db);
}

/**
 * Restore a trashed post.
 *
 * @see AP_Post::untrash()
 */
function ap_untrash_post(int $id, ?AP_DB $db = null): bool
{
    return AP_Post::untrash($id, $db);
}

/**
 * Delete a post (trash unless $force; permanent when force or already trash).
 *
 * @see AP_Post::delete()
 */
function ap_delete_post(int $id, bool $force = false, ?AP_DB $db = null): bool
{
    return AP_Post::delete($id, $force, $db);
}

/**
 * Whether a post type supports revisions.
 *
 * @see AP_Post::typeSupportsRevisions()
 */
function ap_post_type_supports_revisions(string $type): bool
{
    return AP_Post::typeSupportsRevisions($type);
}

/**
 * Whether the post (or ID) is a revision row.
 *
 * @see AP_Post::isRevision()
 */
function ap_is_revision(AP_Post|int $post, ?AP_DB $db = null): bool
{
    return AP_Post::isRevision($post, $db);
}

/**
 * Whether the post (or ID) is an autosave revision.
 *
 * @see AP_Post::isAutosave()
 */
function ap_is_autosave(AP_Post|int $post, ?AP_DB $db = null): bool
{
    return AP_Post::isAutosave($post, $db);
}

/**
 * Save a content revision of a post. Returns new revision ID or 0.
 *
 * @param array<string, mixed> $args
 *
 * @see AP_Post::saveRevision()
 */
function ap_save_post_revision(int $postId, ?AP_DB $db = null, array $args = []): int
{
    return AP_Post::saveRevision($postId, $db, $args);
}

/**
 * Create or update an autosave snapshot (does not change the parent post).
 *
 * @param array<string, mixed> $data post_title / post_content / post_excerpt
 *
 * @see AP_Post::autosave()
 */
function ap_autosave_post(int $postId, array $data, int $userId = 0, ?AP_DB $db = null): int
{
    return AP_Post::autosave($postId, $data, $userId, $db);
}

/**
 * Fetch autosave for a post (optional author filter).
 *
 * @see AP_Post::getAutosave()
 */
function ap_get_post_autosave(int $postId, int $userId = 0, ?AP_DB $db = null): ?AP_Post
{
    return AP_Post::getAutosave($postId, $userId, $db);
}

/**
 * List revisions for a parent post.
 *
 * @param array<string, mixed> $args
 *
 * @return list<AP_Post>
 *
 * @see AP_Post::getRevisions()
 */
function ap_get_post_revisions(int $postId, array $args = [], ?AP_DB $db = null): array
{
    return AP_Post::getRevisions($postId, $args, $db);
}

/**
 * Count revisions for a parent post.
 *
 * @see AP_Post::countRevisions()
 */
function ap_count_post_revisions(
    int $postId,
    bool $includeAutosaves = false,
    ?AP_DB $db = null
): int {
    return AP_Post::countRevisions($postId, $includeAutosaves, $db);
}

/**
 * Restore a revision onto its parent post.
 *
 * @see AP_Post::restoreRevision()
 */
function ap_restore_post_revision(int $revisionId, ?AP_DB $db = null): bool
{
    return AP_Post::restoreRevision($revisionId, $db);
}

/**
 * Permanently delete a single revision.
 *
 * @see AP_Post::deleteRevision()
 */
function ap_delete_post_revision(int $revisionId, ?AP_DB $db = null): bool
{
    return AP_Post::deleteRevision($revisionId, $db);
}

/**
 * Simple post list via {@see AP_Post::query()} (lightweight filters).
 *
 * For full WP_Query-style vars (pagination, search, meta, loop flags), use
 * {@see ap_query()} / {@see AP_Query}.
 *
 * @param array<string, mixed> $args
 *
 * @return list<AP_Post>
 *
 * @see AP_Post::query()
 * @see ap_query()
 */
function ap_get_posts(array $args = [], ?AP_DB $db = null): array
{
    return AP_Post::query($args, $db);
}

/**
 * Run a content query and return the AP_Query instance.
 *
 * When $args is null, returns the global main query (creating an empty one
 * if needed). When $args is an array, builds a new secondary query.
 *
 * @param array<string, mixed>|null $args Query vars (post_type, s, paged, …).
 *
 * @see AP_Query
 */
function ap_query(?array $args = null, ?AP_DB $db = null): AP_Query
{
    if ($args === null) {
        if (!isset($GLOBALS['ap_query']) || !$GLOBALS['ap_query'] instanceof AP_Query) {
            $GLOBALS['ap_query'] = new AP_Query([], $db);
        }

        return $GLOBALS['ap_query'];
    }

    $query = new AP_Query($args, $db);
    // Secondary queries do not replace the main global unless none exists yet.
    if (!isset($GLOBALS['ap_query']) || !$GLOBALS['ap_query'] instanceof AP_Query) {
        $GLOBALS['ap_query'] = $query;
    }

    return $query;
}

/**
 * Set (or replace) the global main query.
 */
function ap_set_query(AP_Query $query): void
{
    $GLOBALS['ap_query'] = $query;
}

/**
 * Whether the main query has more posts in the loop.
 *
 * @see AP_Query::havePosts()
 */
function ap_have_posts(): bool
{
    return ap_query()->havePosts();
}

/**
 * Advance the main query loop and set the global $ap_post.
 *
 * @see AP_Query::thePost()
 */
function ap_the_post(): void
{
    $q = ap_query();
    $q->thePost();
    $GLOBALS['ap_post'] = $q->post;
}

/**
 * Rewind the main query loop.
 *
 * @see AP_Query::rewindPosts()
 */
function ap_rewind_posts(): void
{
    ap_query()->rewindPosts();
}

/**
 * Current post in the main loop (or null).
 */
function ap_get_queried_post(): ?AP_Post
{
    $q = ap_query();

    return $q->post;
}

// -----------------------------------------------------------------------------
// Themes / template hierarchy
// -----------------------------------------------------------------------------

/**
 * Active theme slug (stylesheet directory).
 *
 * @see AP_Theme::getStylesheet()
 */
function ap_get_stylesheet(?AP_DB $db = null): string
{
    return AP_Theme::getStylesheet($db);
}

/**
 * Parent theme slug (template directory).
 *
 * @see AP_Theme::getTemplate()
 */
function ap_get_template(?AP_DB $db = null): string
{
    return AP_Theme::getTemplate($db);
}

/**
 * Absolute path to the active theme directory.
 *
 * @see AP_Theme::getStylesheetDirectory()
 */
function ap_get_stylesheet_directory(?AP_DB $db = null): string
{
    return AP_Theme::getStylesheetDirectory($db);
}

/**
 * Absolute path to the parent theme directory.
 *
 * @see AP_Theme::getTemplateDirectory()
 */
function ap_get_template_directory(?AP_DB $db = null): string
{
    return AP_Theme::getTemplateDirectory($db);
}

/**
 * Public URI for the active theme directory.
 *
 * @see AP_Theme::getStylesheetUri()
 */
function ap_get_stylesheet_uri(?AP_DB $db = null): string
{
    return AP_Theme::getStylesheetUri($db);
}

/**
 * Public URI for the parent theme directory.
 *
 * @see AP_Theme::getTemplateUri()
 */
function ap_get_template_uri(?AP_DB $db = null): string
{
    return AP_Theme::getTemplateUri($db);
}

/**
 * Public URI of the active theme's style.css (file, not directory).
 *
 * @see AP_Theme::getStyleCssUri()
 */
function ap_get_style_css_uri(?AP_DB $db = null): string
{
    return AP_Theme::getStyleCssUri($db);
}

/**
 * Whether the active theme is a child theme (stylesheet ≠ parent template).
 *
 * @see AP_Theme::isChildTheme()
 */
function ap_is_child_theme(?AP_DB $db = null): bool
{
    return AP_Theme::isChildTheme($db);
}

/**
 * style.css headers for a theme slug, or null.
 *
 * @return array<string, string>|null
 *
 * @see AP_Theme::getThemeHeaders()
 */
function ap_get_theme_headers(string $slug): ?array
{
    return AP_Theme::getThemeHeaders($slug);
}

/**
 * Screenshot URI for a theme slug (empty when none).
 *
 * @see AP_Theme::getScreenshotUri()
 */
function ap_get_theme_screenshot(string $slug, ?AP_DB $db = null): string
{
    return AP_Theme::getScreenshotUri($slug, $db);
}

/**
 * Ordered template hierarchy candidates for the main (or given) query.
 *
 * @return list<string>
 *
 * @see AP_Theme::getHierarchy()
 */
function ap_get_template_hierarchy(?AP_Query $query = null, ?AP_DB $db = null): array
{
    return AP_Theme::getHierarchy($query, $db);
}

/**
 * Locate a template in the active theme stack (child then parent).
 *
 * @param list<string>|string $templates
 * @param array<string, mixed> $args
 *
 * @see AP_Theme::locateTemplate()
 */
function ap_locate_template(
    array|string $templates,
    bool $load = false,
    bool $requireOnce = true,
    array $args = [],
    ?AP_DB $db = null
): string {
    return AP_Theme::locateTemplate($templates, $load, $requireOnce, $args, $db);
}

/**
 * Load header.php (or header-{$name}.php) from the active theme.
 *
 * @param array<string, mixed> $args
 *
 * @see AP_Theme::getHeader()
 */
function ap_get_header(string $name = '', array $args = [], ?AP_DB $db = null): void
{
    AP_Theme::getHeader($name, $args, $db);
}

/**
 * Load footer.php (or footer-{$name}.php) from the active theme.
 *
 * @param array<string, mixed> $args
 *
 * @see AP_Theme::getFooter()
 */
function ap_get_footer(string $name = '', array $args = [], ?AP_DB $db = null): void
{
    AP_Theme::getFooter($name, $args, $db);
}

/**
 * Load sidebar.php (or sidebar-{$name}.php) from the active theme.
 *
 * @param array<string, mixed> $args
 *
 * @see AP_Theme::getSidebar()
 */
function ap_get_sidebar(string $name = '', array $args = [], ?AP_DB $db = null): void
{
    AP_Theme::getSidebar($name, $args, $db);
}

/**
 * Set the active theme (writes stylesheet + template options).
 *
 * @see AP_Theme::setActive()
 */
function ap_switch_theme(string $stylesheet, ?string $template = null, ?AP_DB $db = null): bool
{
    return AP_Theme::setActive($stylesheet, $template, $db);
}

/**
 * List installed themes (style.css headers keyed by slug).
 *
 * @return array<string, array<string, string>>
 *
 * @see AP_Theme::listThemes()
 */
function ap_get_themes(): array
{
    return AP_Theme::listThemes();
}

/**
 * Read a theme mod for the active (or given) theme — WordPress get_theme_mod.
 *
 * @param mixed $default
 *
 * @return mixed
 *
 * @see AP_Theme::getMod()
 */
function ap_get_theme_mod(string $name, mixed $default = false, ?string $stylesheet = null, ?AP_DB $db = null): mixed
{
    return AP_Theme::getMod($name, $default, $stylesheet, $db);
}

/**
 * Write a theme mod — WordPress set_theme_mod.
 *
 * @param mixed $value
 *
 * @see AP_Theme::setMod()
 */
function ap_set_theme_mod(string $name, mixed $value, ?string $stylesheet = null, ?AP_DB $db = null): bool
{
    return AP_Theme::setMod($name, $value, $stylesheet, $db);
}

/**
 * Remove a theme mod — WordPress remove_theme_mod.
 *
 * @see AP_Theme::removeMod()
 */
function ap_remove_theme_mod(string $name, ?string $stylesheet = null, ?AP_DB $db = null): bool
{
    return AP_Theme::removeMod($name, $stylesheet, $db);
}

/**
 * All theme mods for the active (or given) theme.
 *
 * @return array<string, mixed>
 *
 * @see AP_Theme::getMods()
 */
function ap_get_theme_mods(?string $stylesheet = null, ?AP_DB $db = null): array
{
    return AP_Theme::getMods($stylesheet, $db);
}

/**
 * Install a classic theme from a zip file path.
 *
 * @param array<string, mixed> $args overwrite, allow_block, themes_root, slug
 *
 * @return array<string, mixed>
 *
 * @see AP_Theme_Installer::installFromZip()
 */
function ap_install_theme_from_zip(string $zipPath, array $args = []): array
{
    return AP_Theme_Installer::installFromZip($zipPath, $args);
}

/**
 * Handle a theme zip $_FILES upload and install.
 *
 * @param array<string, mixed> $file
 * @param array<string, mixed> $args
 *
 * @return array<string, mixed>
 *
 * @see AP_Theme_Installer::handleUpload()
 */
function ap_upload_theme(array $file, array $args = []): array
{
    return AP_Theme_Installer::handleUpload($file, $args);
}

/**
 * Delete an installed theme directory (not active or protected).
 *
 * @return array{ok: bool, slug: string, errors: list<string>}
 *
 * @see AP_Theme_Installer::deleteTheme()
 */
function ap_delete_theme(string $slug, ?AP_DB $db = null): array
{
    return AP_Theme_Installer::deleteTheme($slug, $db);
}

/**
 * Run the front-end template loader for the main query.
 *
 * @see AP_Theme::render()
 */
function ap_template_loader(?AP_Query $query = null, ?AP_DB $db = null): void
{
    AP_Theme::render($query, $db);
}

// -----------------------------------------------------------------------------
// Classic WordPress Theme Compatibility (thin wrappers; full API in load.php)
// -----------------------------------------------------------------------------

/**
 * Ensure classic WP theme shims are loaded.
 *
 * @see AP_Theme_Compat::ensureLoaded()
 */
function ap_load_theme_compat(bool $force = false, ?AP_DB $db = null): bool
{
    if (!class_exists('AP_Theme_Compat', false)) {
        $path = (defined('AP_ABSPATH') ? AP_ABSPATH : dirname(__DIR__) . '/')
            . 'ap-includes/compatibility/load.php';
        if (is_readable($path)) {
            require_once $path;
        }
    }
    if (!class_exists('AP_Theme_Compat', false)) {
        return false;
    }

    return AP_Theme_Compat::ensureLoaded($force, $db);
}

/**
 * Whether a theme slug is a block / FSE theme (out of scope for the shim layer).
 *
 * @see AP_Theme_Compat::isBlockTheme()
 */
function ap_is_block_theme(string $slug): bool
{
    return class_exists('AP_Theme_Compat', false) && AP_Theme_Compat::isBlockTheme($slug);
}

/**
 * Analyze a classic WP theme directory for AgoraPress compatibility.
 *
 * @return array<string, mixed>
 *
 * @see AP_Theme_Converter::analyzePath()
 */
function ap_analyze_wp_theme(string $path): array
{
    if (!class_exists('AP_Theme_Converter', false)) {
        ap_load_theme_compat(true);
    }
    if (!class_exists('AP_Theme_Converter', false)) {
        return [];
    }

    return AP_Theme_Converter::analyzePath($path);
}

// -----------------------------------------------------------------------------
// Plugins (discovery, headers, activation)
// -----------------------------------------------------------------------------

/**
 * Absolute path to the plugins directory (no trailing slash).
 *
 * @see AP_Plugin::pluginsRoot()
 */
function ap_get_plugins_dir(): string
{
    return AP_Plugin::pluginsRoot();
}

/**
 * List installed plugins (headers keyed by basename).
 *
 * @return array<string, array<string, string>>
 *
 * @see AP_Plugin::listPlugins()
 */
function ap_get_plugins(): array
{
    return AP_Plugin::listPlugins();
}

/**
 * Parsed headers for a plugin basename, or null.
 *
 * @return array<string, string>|null
 *
 * @see AP_Plugin::getPluginHeaders()
 */
function ap_get_plugin_data(string $plugin): ?array
{
    return AP_Plugin::getPluginHeaders($plugin);
}

/**
 * Active plugin basenames.
 *
 * @return list<string>
 *
 * @see AP_Plugin::getActivePlugins()
 */
function ap_get_active_plugins(?AP_DB $db = null): array
{
    return AP_Plugin::getActivePlugins($db);
}

/**
 * Whether a plugin basename is active.
 *
 * @see AP_Plugin::isActive()
 */
function ap_is_plugin_active(string $plugin, ?AP_DB $db = null): bool
{
    return AP_Plugin::isActive($plugin, $db);
}

/**
 * Activate a plugin (loads file, runs activation hooks, updates active list).
 *
 * @return array{ok: bool, errors: list<string>}
 *
 * @see AP_Plugin::activate()
 */
function ap_activate_plugin(string $plugin, ?AP_DB $db = null): array
{
    return AP_Plugin::activate($plugin, $db);
}

/**
 * Deactivate a plugin (runs deactivation hooks, updates active list).
 *
 * @return array{ok: bool, errors: list<string>}
 *
 * @see AP_Plugin::deactivate()
 */
function ap_deactivate_plugin(string $plugin, ?AP_DB $db = null): array
{
    return AP_Plugin::deactivate($plugin, $db);
}

/**
 * Plugin basename relative to the plugins root (from __FILE__ or relative path).
 *
 * @see AP_Plugin::pluginBasename()
 */
function ap_plugin_basename(string $file): string
{
    return AP_Plugin::pluginBasename($file);
}

/**
 * Absolute path to a plugin's main file.
 *
 * @see AP_Plugin::pluginPath()
 */
function ap_plugin_path(string $plugin): string
{
    return AP_Plugin::pluginPath($plugin);
}

/**
 * Absolute directory of a plugin (no trailing slash).
 *
 * @see AP_Plugin::pluginDir()
 */
function ap_plugin_dir(string $plugin): string
{
    return AP_Plugin::pluginDir($plugin);
}

/**
 * Public URI for a plugin path under the plugins directory.
 *
 * @see AP_Plugin::pluginUrl()
 */
function ap_plugin_url(string $plugin, string $path = '', ?AP_DB $db = null): string
{
    return AP_Plugin::pluginUrl($plugin, $path, $db);
}

/**
 * Register a callback for plugin activation.
 *
 * @param callable $callback
 *
 * @see AP_Plugin::registerActivationHook()
 */
function ap_register_activation_hook(string $file, callable $callback): void
{
    AP_Plugin::registerActivationHook($file, $callback);
}

/**
 * Register a callback for plugin deactivation.
 *
 * @param callable $callback
 *
 * @see AP_Plugin::registerDeactivationHook()
 */
function ap_register_deactivation_hook(string $file, callable $callback): void
{
    AP_Plugin::registerDeactivationHook($file, $callback);
}

/**
 * Load all active plugins (normally called from bootstrap).
 *
 * @see AP_Plugin::loadActivePlugins()
 */
function ap_load_active_plugins(?AP_DB $db = null): void
{
    AP_Plugin::loadActivePlugins($db);
}

/**
 * Absolute path to the must-use plugins directory (no trailing slash).
 *
 * @see AP_Plugin::muPluginsRoot()
 */
function ap_get_mu_plugins_dir(): string
{
    return AP_Plugin::muPluginsRoot();
}

/**
 * List must-use plugins (always-on root-level PHP files).
 *
 * @return array<string, array<string, string>>
 *
 * @see AP_Plugin::listMuPlugins()
 */
function ap_get_mu_plugins(): array
{
    return AP_Plugin::listMuPlugins();
}

/**
 * Load must-use plugins (normally called from bootstrap before regular plugins).
 *
 * @see AP_Plugin::loadMuPlugins()
 */
function ap_load_mu_plugins(): void
{
    AP_Plugin::loadMuPlugins();
}

/**
 * Whether a must-use plugin file was included this request.
 *
 * @see AP_Plugin::isMuLoaded()
 */
function ap_is_mu_plugin_loaded(string $plugin): bool
{
    return AP_Plugin::isMuLoaded($plugin);
}

// -----------------------------------------------------------------------------
// Plugin admin pages (ACP registry allowlist)
// -----------------------------------------------------------------------------

/**
 * Register a plugin (or core) admin page for the Control Panel.
 *
 * Pages appear under the ACP sidebar (when menu merge is wired) and load only
 * through the admin router (?page={id}) — never via arbitrary plugin paths.
 *
 * Required:
 * - id        Unique slug (sanitized to [a-z0-9_-]); used as ?page=
 * - callback  Callable or string function name / Class::method (strings are
 *             normalized to callable wrappers at registration)
 *
 * Optional:
 * - parent      settings | plugins | tools | '' (default placement)
 * - title       Document / screen title (defaults to menu or id)
 * - menu        Sidebar label (defaults to title or id)
 * - capability  Required cap (default manage_options)
 * - plugin      Plugin basename for Settings links on plugins.php
 * - position    Sort order (default 50)
 *
 * Returns false when id is missing/invalid, callback is missing/invalid, or
 * the id is already registered (first registration wins).
 *
 * @param array{
 *   id?: string,
 *   parent?: string,
 *   title?: string,
 *   menu?: string,
 *   capability?: string,
 *   callback?: callable|string,
 *   plugin?: string,
 *   position?: int|string
 * } $args
 *
 * @see AP_Admin_Menu::register()
 */
function ap_register_admin_page(array $args): bool
{
    if (!class_exists('AP_Admin_Menu', false)) {
        return false;
    }

    return AP_Admin_Menu::register($args);
}

/**
 * Fetch one registered ACP admin page by id.
 *
 * Returns null when the id is unknown, empty/invalid after sanitization, or
 * the admin menu class is not loaded.
 *
 * @return array{
 *   id: string,
 *   parent: string,
 *   title: string,
 *   menu: string,
 *   capability: string,
 *   callback: callable|string,
 *   plugin: string,
 *   position: int
 * }|null
 *
 * @see AP_Admin_Menu::get()
 * @see ap_register_admin_page()
 */
function ap_get_admin_page(string $id): ?array
{
    if (!class_exists('AP_Admin_Menu', false)) {
        return null;
    }

    return AP_Admin_Menu::get($id);
}

/**
 * All registered ACP admin pages (id-keyed, insertion order).
 *
 * Empty array when nothing is registered or the admin menu class is not loaded.
 * For menu rendering ordered by position, use {@see ap_get_admin_pages_sorted()}.
 *
 * @return array<string, array{
 *   id: string,
 *   parent: string,
 *   title: string,
 *   menu: string,
 *   capability: string,
 *   callback: callable|string,
 *   plugin: string,
 *   position: int
 * }>
 *
 * @see AP_Admin_Menu::all()
 * @see ap_register_admin_page()
 */
function ap_get_admin_pages(): array
{
    if (!class_exists('AP_Admin_Menu', false)) {
        return [];
    }

    return AP_Admin_Menu::all();
}

/**
 * Registered ACP admin pages sorted by position (stable by id for ties).
 *
 * @return list<array{
 *   id: string,
 *   parent: string,
 *   title: string,
 *   menu: string,
 *   capability: string,
 *   callback: callable|string,
 *   plugin: string,
 *   position: int
 * }>
 *
 * @see AP_Admin_Menu::allSorted()
 * @see ap_get_admin_pages()
 */
function ap_get_admin_pages_sorted(): array
{
    if (!class_exists('AP_Admin_Menu', false)) {
        return [];
    }

    return AP_Admin_Menu::allSorted();
}

/**
 * Pages registered for a plugin basename (Settings link lookup on plugins.php).
 *
 * @return list<array{
 *   id: string,
 *   parent: string,
 *   title: string,
 *   menu: string,
 *   capability: string,
 *   callback: callable|string,
 *   plugin: string,
 *   position: int
 * }>
 *
 * @see AP_Admin_Menu::forPlugin()
 */
function ap_get_admin_pages_for_plugin(string $pluginBasename): array
{
    if (!class_exists('AP_Admin_Menu', false)) {
        return [];
    }

    return AP_Admin_Menu::forPlugin($pluginBasename);
}

// -----------------------------------------------------------------------------
// WordPress-compatible admin menu page shims (thin wrappers → AP_Admin_Menu)
// -----------------------------------------------------------------------------

/**
 * Register a top-level ACP admin page (WordPress add_menu_page() shim).
 *
 * Maps to {@see ap_register_admin_page()} with parent '' (default Plugins section).
 * $icon_url is accepted for signature compatibility and ignored (no ACP icons yet).
 *
 * @param callable|string|array|null $callback
 * @param int|float|string|null      $position
 *
 * @return string|false Hook name on success, false on failure.
 *
 * @see AP_Admin_Menu::registerFromWp()
 * @see add_submenu_page()
 */
if (!function_exists('add_menu_page')) {
    function add_menu_page(
        string $page_title,
        string $menu_title,
        string $capability,
        string $menu_slug,
        mixed $callback = '',
        string $icon_url = '',
        int|float|string|null $position = null
    ): string|false {
        unset($icon_url); // Signature parity only.

        if (!class_exists('AP_Admin_Menu', false)) {
            return false;
        }

        return AP_Admin_Menu::registerFromWp(
            '',
            $page_title,
            $menu_title,
            $capability,
            $menu_slug,
            $callback,
            $position
        );
    }
}

/**
 * Register a submenu ACP admin page (WordPress add_submenu_page() shim).
 *
 * Parent is mapped via {@see AP_Admin_Menu::mapWpParent()} (e.g.
 * options-general.php → settings, plugins.php → plugins).
 *
 * @param callable|string|array|null $callback
 * @param int|float|string|null      $position
 *
 * @return string|false Hook name on success, false on failure.
 *
 * @see AP_Admin_Menu::mapWpParent()
 * @see AP_Admin_Menu::registerFromWp()
 */
if (!function_exists('add_submenu_page')) {
    function add_submenu_page(
        string $parent_slug,
        string $page_title,
        string $menu_title,
        string $capability,
        string $menu_slug,
        mixed $callback = '',
        int|float|string|null $position = null
    ): string|false {
        if (!class_exists('AP_Admin_Menu', false)) {
            return false;
        }

        $parent = AP_Admin_Menu::mapWpParent($parent_slug);

        return AP_Admin_Menu::registerFromWp(
            $parent,
            $page_title,
            $menu_title,
            $capability,
            $menu_slug,
            $callback,
            $position
        );
    }
}

/**
 * Register a Settings-section ACP page (WordPress add_options_page() shim).
 *
 * Equivalent to add_submenu_page('options-general.php', …) → parent settings.
 *
 * @param callable|string|array|null $callback
 * @param int|float|string|null      $position
 *
 * @return string|false Hook name on success, false on failure.
 *
 * @see add_submenu_page()
 */
if (!function_exists('add_options_page')) {
    function add_options_page(
        string $page_title,
        string $menu_title,
        string $capability,
        string $menu_slug,
        mixed $callback = '',
        int|float|string|null $position = null
    ): string|false {
        return add_submenu_page(
            'options-general.php',
            $page_title,
            $menu_title,
            $capability,
            $menu_slug,
            $callback,
            $position
        );
    }
}

/**
 * Register a Plugins-section ACP page (WordPress add_plugins_page() shim).
 *
 * Equivalent to add_submenu_page('plugins.php', …) → parent plugins.
 *
 * @param callable|string|array|null $callback
 * @param int|float|string|null      $position
 *
 * @return string|false Hook name on success, false on failure.
 *
 * @see add_submenu_page()
 */
if (!function_exists('add_plugins_page')) {
    function add_plugins_page(
        string $page_title,
        string $menu_title,
        string $capability,
        string $menu_slug,
        mixed $callback = '',
        int|float|string|null $position = null
    ): string|false {
        return add_submenu_page(
            'plugins.php',
            $page_title,
            $menu_title,
            $capability,
            $menu_slug,
            $callback,
            $position
        );
    }
}

// -----------------------------------------------------------------------------
// Object Cache API (wrappers; primary definitions live in class-ap-object-cache.php)
// -----------------------------------------------------------------------------

/**
 * Whether an external object-cache drop-in is active.
 *
 * @see ap_using_ext_object_cache()
 */
function ap_using_object_cache(): bool
{
    return function_exists('ap_using_ext_object_cache') && ap_using_ext_object_cache();
}

// -----------------------------------------------------------------------------
// Page Cache API (wrappers; primary definitions live in class-ap-page-cache.php)
// -----------------------------------------------------------------------------

/**
 * Whether page caching is enabled (AP_CACHE + filter).
 *
 * @see AP_Page_Cache::isEnabled()
 */
function ap_page_cache_enabled(): bool
{
    if (!class_exists('AP_Page_Cache', false)) {
        return defined('AP_CACHE') && AP_CACHE;
    }

    return AP_Page_Cache::isEnabled();
}

/**
 * Whether the advanced-cache drop-in was loaded this request.
 *
 * @see AP_Page_Cache::usingDropin()
 */
function ap_using_page_cache(): bool
{
    return class_exists('AP_Page_Cache', false) && AP_Page_Cache::usingDropin();
}

/**
 * Whether the current request is a candidate for full-page caching.
 *
 * @see AP_Page_Cache::shouldCacheRequest()
 */
function ap_should_cache_request(): bool
{
    if (!class_exists('AP_Page_Cache', false)) {
        return false;
    }

    return AP_Page_Cache::shouldCacheRequest();
}

/**
 * Mark the current request as non-cacheable.
 *
 * @see AP_Page_Cache::skipRequest()
 */
function ap_skip_page_cache(): void
{
    if (class_exists('AP_Page_Cache', false)) {
        AP_Page_Cache::skipRequest();
    }
}

/**
 * Whether ap_skip_page_cache() was called this request.
 *
 * @see AP_Page_Cache::requestSkipped()
 */
function ap_page_cache_skipped(): bool
{
    return class_exists('AP_Page_Cache', false) && AP_Page_Cache::requestSkipped();
}

/**
 * Flush the page cache, or purge one URL when given.
 *
 * @see AP_Page_Cache::clean()
 */
function ap_clean_page_cache(?string $url = null): void
{
    if (class_exists('AP_Page_Cache', false)) {
        AP_Page_Cache::clean($url);
    }
}

/**
 * Invalidate page cache entries related to a post/page.
 *
 * @see AP_Page_Cache::cleanPost()
 */
function ap_clean_post_cache(int $postId): void
{
    if (class_exists('AP_Page_Cache', false)) {
        AP_Page_Cache::cleanPost($postId);
    }
}

/**
 * Invalidate page cache entries related to a forum topic.
 *
 * @see AP_Page_Cache::cleanTopic()
 */
function ap_clean_topic_cache(int $topicId, ?int $forumId = null): void
{
    if (class_exists('AP_Page_Cache', false)) {
        AP_Page_Cache::cleanTopic($topicId, $forumId);
    }
}

/**
 * Invalidate page cache entries related to a forum.
 *
 * @see AP_Page_Cache::cleanForum()
 */
function ap_clean_forum_cache(int $forumId): void
{
    if (class_exists('AP_Page_Cache', false)) {
        AP_Page_Cache::cleanForum($forumId);
    }
}

/**
 * Send headers that discourage caching of the current response.
 *
 * @see AP_Page_Cache::nocacheHeaders()
 */
function ap_nocache_headers(): void
{
    if (class_exists('AP_Page_Cache', false)) {
        AP_Page_Cache::nocacheHeaders();
    }
}

// -----------------------------------------------------------------------------
// Transients API
// -----------------------------------------------------------------------------

/**
 * Read a transient (false when missing or expired).
 *
 * @param mixed $default
 *
 * @return mixed
 *
 * @see AP_Transient::get()
 */
function ap_get_transient(string $name, mixed $default = false, ?AP_DB $db = null): mixed
{
    if (!class_exists('AP_Transient', false)) {
        return $default;
    }

    return AP_Transient::get($name, $default, $db);
}

/**
 * Store a transient.
 *
 * @param mixed $value
 *
 * @see AP_Transient::set()
 */
function ap_set_transient(string $name, mixed $value, int $expiration = 0, ?AP_DB $db = null): bool
{
    if (!class_exists('AP_Transient', false)) {
        return false;
    }

    return AP_Transient::set($name, $value, $expiration, $db);
}

/**
 * Delete a transient.
 *
 * @see AP_Transient::delete()
 */
function ap_delete_transient(string $name, ?AP_DB $db = null): bool
{
    if (!class_exists('AP_Transient', false)) {
        return false;
    }

    return AP_Transient::delete($name, $db);
}

// -----------------------------------------------------------------------------
// Shortcode API
// -----------------------------------------------------------------------------

/**
 * Register a shortcode handler.
 *
 * @param callable $callback function(array $atts, ?string $content, string $tag): string
 *
 * @see AP_Shortcode::add()
 */
function ap_add_shortcode(string $tag, callable $callback): void
{
    if (!class_exists('AP_Shortcode', false)) {
        return;
    }
    AP_Shortcode::add($tag, $callback);
}

/**
 * Remove a shortcode handler.
 *
 * @see AP_Shortcode::remove()
 */
function ap_remove_shortcode(string $tag): void
{
    if (!class_exists('AP_Shortcode', false)) {
        return;
    }
    AP_Shortcode::remove($tag);
}

/**
 * Whether a shortcode tag is registered.
 *
 * @see AP_Shortcode::exists()
 */
function ap_shortcode_exists(string $tag): bool
{
    if (!class_exists('AP_Shortcode', false)) {
        return false;
    }

    return AP_Shortcode::exists($tag);
}

/**
 * Expand shortcodes in content.
 *
 * @see AP_Shortcode::doShortcode()
 */
function ap_do_shortcode(string $content): string
{
    if (!class_exists('AP_Shortcode', false)) {
        return $content;
    }

    return AP_Shortcode::doShortcode($content);
}

/**
 * Strip registered shortcodes from content.
 *
 * @see AP_Shortcode::strip()
 */
function ap_strip_shortcodes(string $content): string
{
    if (!class_exists('AP_Shortcode', false)) {
        return $content;
    }

    return AP_Shortcode::strip($content);
}

/**
 * Whether content contains a shortcode tag.
 *
 * @see AP_Shortcode::has()
 */
function ap_has_shortcode(string $content, string $tag): bool
{
    if (!class_exists('AP_Shortcode', false)) {
        return false;
    }

    return AP_Shortcode::has($content, $tag);
}

/**
 * Parse a shortcode attribute string.
 *
 * @return array<string, string>
 *
 * @see AP_Shortcode::parseAtts()
 */
function ap_shortcode_parse_atts(string $text): array
{
    if (!class_exists('AP_Shortcode', false)) {
        return [];
    }

    return AP_Shortcode::parseAtts($text);
}

/**
 * Combine shortcode defaults with user attributes.
 *
 * @param array<string, string> $pairs
 * @param array<string, string> $atts
 *
 * @return array<string, string>
 *
 * @see AP_Shortcode::atts()
 */
function ap_shortcode_atts(array $pairs, array $atts): array
{
    if (!class_exists('AP_Shortcode', false)) {
        return $pairs;
    }

    return AP_Shortcode::atts($pairs, $atts);
}

// -----------------------------------------------------------------------------
// Cron API
// -----------------------------------------------------------------------------

/**
 * Recurrence schedules (hourly, daily, …).
 *
 * @return array<string, array{interval: int, display: string}>
 *
 * @see AP_Cron::schedules()
 */
function ap_get_cron_schedules(): array
{
    if (!class_exists('AP_Cron', false)) {
        return [];
    }

    return AP_Cron::schedules();
}

/**
 * Schedule a recurring cron event.
 *
 * @param list<mixed> $args
 *
 * @see AP_Cron::scheduleEvent()
 */
function ap_schedule_event(
    int $timestamp,
    string $recurrence,
    string $hook,
    array $args = [],
    ?AP_DB $db = null
): bool {
    if (!class_exists('AP_Cron', false)) {
        return false;
    }

    return AP_Cron::scheduleEvent($timestamp, $recurrence, $hook, $args, $db);
}

/**
 * Schedule a one-time cron event.
 *
 * @param list<mixed> $args
 *
 * @see AP_Cron::scheduleSingle()
 */
function ap_schedule_single_event(
    int $timestamp,
    string $hook,
    array $args = [],
    ?AP_DB $db = null
): bool {
    if (!class_exists('AP_Cron', false)) {
        return false;
    }

    return AP_Cron::scheduleSingle($timestamp, $hook, $args, $db);
}

/**
 * Unschedule a specific cron event occurrence.
 *
 * @param list<mixed> $args
 *
 * @see AP_Cron::unschedule()
 */
function ap_unschedule_event(
    int $timestamp,
    string $hook,
    array $args = [],
    ?AP_DB $db = null
): bool {
    if (!class_exists('AP_Cron', false)) {
        return false;
    }

    return AP_Cron::unschedule($timestamp, $hook, $args, $db);
}

/**
 * Clear scheduled events for a hook.
 *
 * @param list<mixed>|null $args
 *
 * @see AP_Cron::clearHook()
 */
function ap_clear_scheduled_hook(string $hook, ?array $args = null, ?AP_DB $db = null): int
{
    if (!class_exists('AP_Cron', false)) {
        return 0;
    }

    return AP_Cron::clearHook($hook, $args, $db);
}

/**
 * Next scheduled timestamp for a hook, or false.
 *
 * @param list<mixed> $args
 *
 * @return int|false
 *
 * @see AP_Cron::nextScheduled()
 */
function ap_next_scheduled(string $hook, array $args = [], ?AP_DB $db = null): int|false
{
    if (!class_exists('AP_Cron', false)) {
        return false;
    }

    return AP_Cron::nextScheduled($hook, $args, $db);
}

/**
 * Run due cron events (bounded).
 *
 * @see AP_Cron::runDue()
 */
function ap_cron_run_due(?AP_DB $db = null, ?int $now = null): int
{
    if (!class_exists('AP_Cron', false)) {
        return 0;
    }

    return AP_Cron::runDue($db, $now);
}

/**
 * Spawn pseudo-cron if any event is due.
 *
 * @see AP_Cron::spawn()
 */
function ap_spawn_cron(?AP_DB $db = null): int
{
    if (!class_exists('AP_Cron', false)) {
        return 0;
    }

    return AP_Cron::spawn($db);
}

// -----------------------------------------------------------------------------
// Assets / enqueue (styles & scripts)
// -----------------------------------------------------------------------------

/**
 * Register a stylesheet.
 *
 * @param list<string> $deps
 * @param string|false|null $ver
 *
 * @see AP_Assets::registerStyle()
 */
function ap_register_style(
    string $handle,
    string $src = '',
    array $deps = [],
    string|bool|null $ver = false,
    string $media = 'all'
): bool {
    return AP_Assets::registerStyle($handle, $src, $deps, $ver, $media);
}

/**
 * Enqueue a stylesheet (registers when $src is non-empty).
 *
 * @param list<string> $deps
 * @param string|false|null $ver
 *
 * @see AP_Assets::enqueueStyle()
 */
function ap_enqueue_style(
    string $handle,
    string $src = '',
    array $deps = [],
    string|bool|null $ver = false,
    string $media = 'all'
): bool {
    return AP_Assets::enqueueStyle($handle, $src, $deps, $ver, $media);
}

/**
 * @see AP_Assets::dequeueStyle()
 */
function ap_dequeue_style(string $handle): void
{
    AP_Assets::dequeueStyle($handle);
}

/**
 * @see AP_Assets::deregisterStyle()
 */
function ap_deregister_style(string $handle): void
{
    AP_Assets::deregisterStyle($handle);
}

/**
 * @see AP_Assets::addInlineStyle()
 */
function ap_add_inline_style(string $handle, string $data): bool
{
    return AP_Assets::addInlineStyle($handle, $data);
}

/**
 * Register a script.
 *
 * @param list<string> $deps
 * @param string|false|null $ver
 *
 * @see AP_Assets::registerScript()
 */
function ap_register_script(
    string $handle,
    string $src = '',
    array $deps = [],
    string|bool|null $ver = false,
    bool|array $args = false
): bool {
    return AP_Assets::registerScript($handle, $src, $deps, $ver, $args);
}

/**
 * Enqueue a script (registers when $src is non-empty).
 *
 * Fifth argument: bool for footer, or `['in_footer' => bool, 'strategy' => 'defer'|'async']`.
 *
 * @param list<string> $deps
 * @param string|false|null $ver
 * @param bool|array{in_footer?: bool, strategy?: string} $args
 *
 * @see AP_Assets::enqueueScript()
 */
function ap_enqueue_script(
    string $handle,
    string $src = '',
    array $deps = [],
    string|bool|null $ver = false,
    bool|array $args = false
): bool {
    return AP_Assets::enqueueScript($handle, $src, $deps, $ver, $args);
}

/**
 * Set script loading strategy (`defer` / `async` / empty).
 *
 * @see AP_Assets::setScriptStrategy()
 */
function ap_script_add_data(string $handle, string $key, mixed $value): bool
{
    if ($key === 'strategy' && is_string($value)) {
        return AP_Assets::setScriptStrategy($handle, $value);
    }

    return false;
}

/**
 * @see AP_Assets::getScriptStrategy()
 */
function ap_get_script_strategy(string $handle): string
{
    return AP_Assets::getScriptStrategy($handle);
}

/**
 * @see AP_Assets::dequeueScript()
 */
function ap_dequeue_script(string $handle): void
{
    AP_Assets::dequeueScript($handle);
}

/**
 * @see AP_Assets::deregisterScript()
 */
function ap_deregister_script(string $handle): void
{
    AP_Assets::deregisterScript($handle);
}

/**
 * @see AP_Assets::addInlineScript()
 */
function ap_add_inline_script(string $handle, string $data, string $position = 'after'): bool
{
    return AP_Assets::addInlineScript($handle, $data, $position);
}

/**
 * Print enqueued styles (idempotent per request).
 *
 * @see AP_Assets::printStyles()
 */
function ap_print_styles(): void
{
    AP_Assets::printStyles();
}

/**
 * Print enqueued scripts for head ($footer=false) or footer ($footer=true).
 *
 * @see AP_Assets::printScripts()
 */
function ap_print_scripts(bool $footer = false): void
{
    AP_Assets::printScripts($footer);
}

/**
 * Whether a style is registered / enqueued / done.
 *
 * @param string $list registered|enqueued|queue|done
 *
 * @see AP_Assets::styleIs()
 */
function ap_style_is(string $handle, string $list = 'enqueued'): bool
{
    return AP_Assets::styleIs($handle, $list);
}

/**
 * Whether a script is registered / enqueued / done.
 *
 * @param string $list registered|enqueued|queue|done
 *
 * @see AP_Assets::scriptIs()
 */
function ap_script_is(string $handle, string $list = 'enqueued'): bool
{
    return AP_Assets::scriptIs($handle, $list);
}

/**
 * Front-end &lt;head&gt; pipeline: enqueue action, ap_head hooks, print styles + head scripts.
 *
 * Call from theme header.php after &lt;title&gt; (or equivalent).
 */
function ap_head(): void
{
    if (function_exists('ap_do_action')) {
        /**
         * Fires when scripts/styles should be enqueued for the front end.
         */
        ap_do_action('ap_enqueue_scripts');
        /**
         * Fires in the document head after enqueue and before assets print.
         */
        ap_do_action('ap_head');
    }
    // Early connection hints (dns-prefetch / preconnect / prefetch / prerender).
    ap_print_resource_hints();
    if (class_exists('AP_Assets', false)) {
        AP_Assets::printStyles();
        AP_Assets::printScripts(false);
    }
}

/**
 * Print resource hint link tags (performance).
 *
 * Plugins/themes filter `ap_resource_hints` with a list of URLs for each
 * relation type (dns-prefetch, preconnect, prefetch, prerender, preload).
 * Empty by default — core stays free of third-party network calls.
 */
function ap_print_resource_hints(): void
{
    $types = ['dns-prefetch', 'preconnect', 'prefetch', 'prerender', 'preload'];
    foreach ($types as $relation) {
        $urls = [];
        if (function_exists('ap_apply_filters')) {
            $filtered = ap_apply_filters('ap_resource_hints', $urls, $relation);
            if (is_array($filtered)) {
                $urls = $filtered;
            }
        }
        foreach ($urls as $url) {
            if (!is_string($url) || $url === '') {
                continue;
            }
            $href = function_exists('ap_esc_url')
                ? ap_esc_url($url)
                : htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $rel = function_exists('ap_esc_attr')
                ? ap_esc_attr($relation)
                : htmlspecialchars($relation, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            if ($href === '') {
                continue;
            }
            echo '<link rel="' . $rel . '" href="' . $href . '">' . "\n";
        }
    }
}

/**
 * Front-end footer pipeline: ap_footer hooks, then footer scripts.
 *
 * Call from theme footer.php before &lt;/body&gt;.
 */
function ap_footer(): void
{
    if (function_exists('ap_do_action')) {
        /**
         * Fires near the end of the document body.
         */
        ap_do_action('ap_footer');
    }
    if (class_exists('AP_Assets', false)) {
        AP_Assets::printScripts(true);
    }
}

// -----------------------------------------------------------------------------
// Permalinks / rewrite
// -----------------------------------------------------------------------------

/**
 * Whether pretty permalinks are enabled.
 *
 * @see AP_Rewrite::usingPermalinks()
 */
function ap_using_permalinks(?AP_DB $db = null): bool
{
    return AP_Rewrite::usingPermalinks($db);
}

/**
 * Current permalink structure (empty string = plain query args).
 *
 * @see AP_Rewrite::getStructure()
 */
function ap_get_permalink_structure(?AP_DB $db = null): string
{
    return AP_Rewrite::getStructure($db);
}

/**
 * Set permalink structure and flush rewrite rules.
 *
 * @see AP_Rewrite::setStructure()
 */
function ap_set_permalink_structure(string $structure, ?AP_DB $db = null): bool
{
    return AP_Rewrite::setStructure($structure, $db);
}

/**
 * Regenerate and store rewrite rules.
 *
 * @return array<string, string>
 *
 * @see AP_Rewrite::flushRules()
 */
function ap_flush_rewrite_rules(?AP_DB $db = null): array
{
    return AP_Rewrite::flushRules($db);
}

/**
 * Parse a path (and optional GET vars) into query vars.
 *
 * When both $path and $get are null (default), uses REQUEST_URI + $_GET.
 * Pass an explicit path string (including '') to parse without superglobals.
 *
 * @param string|null          $path Path relative to home, or null for globals.
 * @param array<string, mixed>|null $get Query string vars, or null for $_GET when using globals.
 *
 * @return array<string, mixed>
 *
 * @see AP_Rewrite::parseRequest()
 * @see AP_Rewrite::parseFromGlobals()
 */
function ap_parse_request(?string $path = null, ?array $get = null, ?AP_DB $db = null): array
{
    if ($path === null && $get === null) {
        return AP_Rewrite::parseFromGlobals(null, null, $db);
    }

    return AP_Rewrite::parseRequest($path ?? '', $get ?? [], $db);
}

/**
 * Home URL (option `home`, then `siteurl`, then AP_SITEURL).
 *
 * Safe when AP_Rewrite is not loaded (partial bootstrap / isolated tests):
 * falls back to options constants and path joining.
 *
 * @see AP_Rewrite::homeUrl()
 */
function ap_home_url(string $path = '', ?AP_DB $db = null): string
{
    if (class_exists('AP_Rewrite', false)) {
        return AP_Rewrite::homeUrl($path, $db);
    }

    return ap_url_join_base(ap_resolve_home_base($db), $path);
}

/**
 * Site URL (core install URL).
 *
 * Safe when AP_Rewrite is not loaded (partial bootstrap / isolated tests).
 *
 * @see AP_Rewrite::siteUrl()
 */
function ap_site_url(string $path = '', ?AP_DB $db = null): string
{
    if (class_exists('AP_Rewrite', false)) {
        return AP_Rewrite::siteUrl($path, $db);
    }

    $site = ap_resolve_site_base($db);
    if ($site === '') {
        return ap_home_url($path, $db);
    }

    return ap_url_join_base($site, $path);
}

/**
 * Resolve home base URL without AP_Rewrite (options → constants).
 *
 * @internal
 */
function ap_resolve_home_base(?AP_DB $db = null): string
{
    $home = ap_read_url_option('home', $db);
    if ($home === '') {
        $home = ap_read_url_option('siteurl', $db);
    }
    if ($home === '' && defined('AP_HOME') && is_string(AP_HOME) && AP_HOME !== '') {
        $home = (string) AP_HOME;
    }
    if ($home === '' && defined('AP_SITEURL') && is_string(AP_SITEURL) && AP_SITEURL !== '') {
        $home = (string) AP_SITEURL;
    }

    return rtrim($home, '/');
}

/**
 * Resolve site base URL without AP_Rewrite (option → constant).
 *
 * @internal
 */
function ap_resolve_site_base(?AP_DB $db = null): string
{
    $site = ap_read_url_option('siteurl', $db);
    if ($site === '' && defined('AP_SITEURL') && is_string(AP_SITEURL) && AP_SITEURL !== '') {
        $site = (string) AP_SITEURL;
    }

    return rtrim($site, '/');
}

/**
 * Read a URL-style option value from AP_Options or raw options table.
 *
 * @internal
 */
function ap_read_url_option(string $name, ?AP_DB $db = null): string
{
    $conn = $db;
    if (!$conn instanceof AP_DB && isset($GLOBALS['apdb']) && $GLOBALS['apdb'] instanceof AP_DB) {
        $conn = $GLOBALS['apdb'];
    }
    if (!$conn instanceof AP_DB) {
        return '';
    }

    try {
        if (class_exists('AP_Options', false)) {
            $val = AP_Options::get($name, '', $conn);

            return is_string($val) ? $val : '';
        }

        $val = $conn->getVar(
            'SELECT option_value FROM ' . $conn->quoteIdentifier($conn->table('options'))
            . ' WHERE option_name = ? LIMIT 1',
            [$name]
        );

        return is_string($val) ? $val : '';
    } catch (Throwable) {
        return '';
    }
}

/**
 * Join a base URL (no trailing slash) with a path or query string.
 *
 * @internal
 */
function ap_url_join_base(string $base, string $path): string
{
    if ($path === '') {
        return $base !== '' ? $base : '';
    }

    if (str_starts_with($path, '?')) {
        return ($base !== '' ? $base . '/' : '/') . ltrim($path, '/');
    }

    $path = '/' . ltrim($path, '/');

    return ($base !== '' ? $base : '') . $path;
}

/**
 * Public permalink for a post or page.
 *
 * @see AP_Rewrite::getPermalink()
 */
function ap_get_permalink(AP_Post|int $post, ?AP_DB $db = null): string
{
    return AP_Rewrite::getPermalink($post, $db);
}

/**
 * Permalink for a hierarchical page.
 *
 * @see AP_Rewrite::getPageLink()
 */
function ap_get_page_link(AP_Post|int $page, ?AP_DB $db = null): string
{
    return AP_Rewrite::getPageLink($page, $db);
}

/**
 * Term archive link (category / tag / custom).
 *
 * @see AP_Rewrite::getTermLink()
 */
function ap_get_term_link(object|int $term, string $taxonomy = '', ?AP_DB $db = null): string
{
    return AP_Rewrite::getTermLink($term, $taxonomy, $db);
}

/**
 * Author archive link.
 *
 * @see AP_Rewrite::getAuthorLink()
 */
function ap_get_author_link(string $authorName, ?AP_DB $db = null): string
{
    return AP_Rewrite::getAuthorLink($authorName, $db);
}

/**
 * Search results link.
 *
 * @see AP_Rewrite::getSearchLink()
 */
function ap_get_search_link(string $query, ?AP_DB $db = null): string
{
    return AP_Rewrite::getSearchLink($query, $db);
}

/**
 * Feed URL (rss2 or atom).
 *
 * @see AP_Rewrite::getFeedLink()
 */
function ap_get_feed_link(string $feed = 'rss2', ?AP_DB $db = null): string
{
    if (class_exists('AP_Rewrite', false)) {
        return AP_Rewrite::getFeedLink($feed, $db);
    }

    $feed = strtolower(trim($feed)) !== '' ? strtolower(trim($feed)) : 'rss2';

    return '/?feed=' . rawurlencode($feed);
}

/**
 * Public XML sitemap URL (index or provider slug).
 *
 * @see AP_Sitemap::getSitemapLink()
 */
function ap_get_sitemap_link(string $type = 'index', int $page = 1, ?AP_DB $db = null): string
{
    if (class_exists('AP_Sitemap', false)) {
        return AP_Sitemap::getSitemapLink($type, $page, $db);
    }

    return '/sitemap.xml';
}

/**
 * Whether XML sitemaps are enabled.
 *
 * @see AP_Sitemap::isEnabled()
 */
function ap_sitemaps_enabled(?AP_DB $db = null): bool
{
    return class_exists('AP_Sitemap', false) && AP_Sitemap::isEnabled($db);
}

/**
 * Canonical URL for the main query (or given query).
 *
 * @see AP_Seo::getCanonicalUrl()
 */
function ap_get_canonical_url(?AP_Query $query = null, ?AP_DB $db = null): string
{
    if (class_exists('AP_Seo', false)) {
        return AP_Seo::getCanonicalUrl($query, $db);
    }

    return '';
}

/**
 * Open Graph meta map for the main query.
 *
 * @return array<string, string>
 *
 * @see AP_Seo::getOpenGraphMeta()
 */
function ap_get_open_graph_meta(?AP_Query $query = null, ?AP_DB $db = null): array
{
    if (class_exists('AP_Seo', false)) {
        return AP_Seo::getOpenGraphMeta($query, $db);
    }

    return [];
}

/**
 * Whether the site encourages search-engine indexing (blog_public).
 */
function ap_is_blog_public(?AP_DB $db = null): bool
{
    if (class_exists('AP_Sitemap', false)) {
        return AP_Sitemap::isPublic($db);
    }
    if (class_exists('AP_Options', false)) {
        return (string) AP_Options::get('blog_public', '1', $db) !== '0';
    }

    return true;
}

// -----------------------------------------------------------------------------
// Options API
// -----------------------------------------------------------------------------

/**
 * Read a site option.
 *
 * @param mixed $default
 *
 * @return mixed
 *
 * @see AP_Options::get()
 */
function ap_get_option(string $name, mixed $default = false, ?AP_DB $db = null): mixed
{
    return AP_Options::get($name, $default, $db);
}

/**
 * Prime the options cache with all autoload=yes rows (one SELECT).
 *
 * @see AP_Options::loadAutoloaded()
 */
function ap_load_autoloaded_options(?AP_DB $db = null): int
{
    return AP_Options::loadAutoloaded($db);
}

/**
 * Autoload option count and payload size for performance budgets.
 *
 * @return array{count: int, bytes: int}
 *
 * @see AP_Options::getAutoloadStats()
 */
function ap_get_autoload_option_stats(?AP_DB $db = null): array
{
    return AP_Options::getAutoloadStats($db);
}

/**
 * Insert or update a site option.
 *
 * @param mixed $value
 *
 * @see AP_Options::update()
 */
function ap_update_option(string $name, mixed $value, ?AP_DB $db = null): bool
{
    return AP_Options::update($name, $value, $db);
}

/**
 * Add a site option only when it does not already exist.
 *
 * @param mixed $value
 *
 * @see AP_Options::add()
 */
function ap_add_option(string $name, mixed $value, ?AP_DB $db = null): bool
{
    return AP_Options::add($name, $value, $db);
}

/**
 * Delete a site option.
 *
 * @see AP_Options::delete()
 */
function ap_delete_option(string $name, ?AP_DB $db = null): bool
{
    return AP_Options::delete($name, $db);
}

// -----------------------------------------------------------------------------
// Hall of Fame (voluntary domain registration — not telemetry)
// -----------------------------------------------------------------------------

/**
 * Whether this site is registered in the public Hall of Fame.
 *
 * @see AP_Hall_Of_Fame::isJoined()
 */
function ap_hall_of_fame_is_joined(?AP_DB $db = null): bool
{
    return AP_Hall_Of_Fame::isJoined($db);
}

/**
 * Local Hall of Fame status snapshot.
 *
 * @return array{
 *   joined: bool,
 *   domain: string,
 *   token: string,
 *   joined_at: string,
 *   dismissed: bool
 * }
 *
 * @see AP_Hall_Of_Fame::getStatus()
 */
function ap_hall_of_fame_status(?AP_DB $db = null): array
{
    return AP_Hall_Of_Fame::getStatus($db);
}

/**
 * Domain that would be registered (from siteurl / home).
 *
 * @see AP_Hall_Of_Fame::resolveDomain()
 */
function ap_hall_of_fame_domain(?AP_DB $db = null): string
{
    return AP_Hall_Of_Fame::resolveDomain($db);
}

// -----------------------------------------------------------------------------
// Version check (public version.json — no site identification)
// -----------------------------------------------------------------------------

/**
 * Whether core version checks against the project endpoint are enabled.
 *
 * @see AP_Version_Check::isEnabled()
 */
function ap_version_check_enabled(?AP_DB $db = null): bool
{
    if (!class_exists('AP_Version_Check', false)) {
        return false;
    }

    return AP_Version_Check::isEnabled($db);
}

// -----------------------------------------------------------------------------
// Local analytics (site DB only — no third-party; default off)
// -----------------------------------------------------------------------------

/**
 * Whether local site analytics hit collection is enabled.
 *
 * Default is off (opt-in). Data never leaves the site database.
 *
 * @see AP_Analytics::isEnabled()
 */
function ap_analytics_enabled(?AP_DB $db = null): bool
{
    if (!class_exists('AP_Analytics', false)) {
        return false;
    }

    return AP_Analytics::isEnabled($db);
}

/**
 * Analytics hit retention window in whole days (default 90).
 *
 * @see AP_Analytics::getRetentionDays()
 */
function ap_analytics_retention_days(?AP_DB $db = null): int
{
    if (!class_exists('AP_Analytics', false)) {
        return 90;
    }

    return AP_Analytics::getRetentionDays($db);
}

/**
 * Whether the current request should be recorded as a public page view.
 *
 * @see AP_Analytics::shouldRecordRequest()
 */
function ap_analytics_should_record(?AP_DB $db = null): bool
{
    if (!class_exists('AP_Analytics', false)) {
        return false;
    }

    return AP_Analytics::shouldRecordRequest($db);
}

/**
 * Record a page-view hit when collection is enabled and the request qualifies.
 *
 * Returns the new hit_id, or 0 when skipped / disabled / failed.
 *
 * @see AP_Analytics::maybeRecordCurrentRequest()
 */
function ap_analytics_maybe_record(?AP_DB $db = null): int
{
    if (!class_exists('AP_Analytics', false)) {
        return 0;
    }

    return AP_Analytics::maybeRecordCurrentRequest($db);
}

/**
 * Insert an analytics hit row (respects analytics_enabled unless overridden in $args).
 *
 * @param array<string, mixed> $data path, object_id, status_code, referrer, ua_class, is_admin, hit_time
 * @param array<string, mixed> $args check_enabled (default true)
 *
 * @see AP_Analytics::recordHit()
 */
function ap_analytics_record_hit(array $data, ?AP_DB $db = null, array $args = []): int
{
    if (!class_exists('AP_Analytics', false)) {
        return 0;
    }

    return AP_Analytics::recordHit($data, $db, $args);
}

/**
 * Delete analytics hits and daily rollups older than the retention window.
 *
 * @param array<string, mixed> $args retention_days, now, prune_hits, prune_daily
 *
 * @return int Total rows deleted.
 *
 * @see AP_Analytics::prune()
 */
function ap_analytics_prune(?AP_DB $db = null, array $args = []): int
{
    if (!class_exists('AP_Analytics', false)) {
        return 0;
    }

    return AP_Analytics::prune($db, $args);
}

/**
 * Ensure the daily analytics prune cron event is scheduled.
 *
 * @see AP_Analytics::ensurePruneScheduled()
 */
function ap_analytics_ensure_prune_scheduled(?AP_DB $db = null): bool
{
    if (!class_exists('AP_Analytics', false)) {
        return false;
    }

    return AP_Analytics::ensurePruneScheduled($db);
}

/**
 * Count analytics hits matching optional filters (date range, path, etc.).
 *
 * @param array<string, mixed> $args
 *
 * @see AP_Analytics::countHits()
 */
function ap_analytics_count_hits(?AP_DB $db = null, array $args = []): int
{
    if (!class_exists('AP_Analytics', false)) {
        return 0;
    }

    return AP_Analytics::countHits($db, $args);
}

/**
 * Pageview summary: today / last 7 days / last 30 days.
 *
 * @return array{today: int, last_7_days: int, last_30_days: int}
 *
 * @see AP_Analytics::getSummary()
 */
function ap_analytics_summary(?AP_DB $db = null, ?int $now = null): array
{
    if (!class_exists('AP_Analytics', false)) {
        return ['today' => 0, 'last_7_days' => 0, 'last_30_days' => 0];
    }

    return AP_Analytics::getSummary($db, $now);
}

/**
 * Top paths by hit count for ACP reports.
 *
 * @param array<string, mixed> $args
 *
 * @return list<array{path: string, object_id: int, hits: int}>
 *
 * @see AP_Analytics::getTopPaths()
 */
function ap_analytics_top_paths(?AP_DB $db = null, array $args = []): array
{
    if (!class_exists('AP_Analytics', false)) {
        return [];
    }

    return AP_Analytics::getTopPaths($db, $args);
}

/**
 * Top referrers by hit count for ACP reports.
 *
 * @param array<string, mixed> $args
 *
 * @return list<array{referrer: string, hits: int}>
 *
 * @see AP_Analytics::getTopReferrers()
 */
function ap_analytics_top_referrers(?AP_DB $db = null, array $args = []): array
{
    if (!class_exists('AP_Analytics', false)) {
        return [];
    }

    return AP_Analytics::getTopReferrers($db, $args);
}

/**
 * Per-day pageview totals (crude chart / last-N-days table).
 *
 * @param array<string, mixed> $args
 *
 * @return list<array{day: string, hits: int}>
 *
 * @see AP_Analytics::getDailyTotals()
 */
function ap_analytics_daily_totals(?AP_DB $db = null, array $args = []): array
{
    if (!class_exists('AP_Analytics', false)) {
        return [];
    }

    return AP_Analytics::getDailyTotals($db, $args);
}

/**
 * Aggregate raw hits into analytics_daily for a day range.
 *
 * @param array<string, mixed> $args
 *
 * @return int Number of daily rows written.
 *
 * @see AP_Analytics::rollupDaily()
 */
function ap_analytics_rollup_daily(?AP_DB $db = null, array $args = []): int
{
    if (!class_exists('AP_Analytics', false)) {
        return 0;
    }

    return AP_Analytics::rollupDaily($db, $args);
}

/**
 * Whether a newer AgoraPress release is available (uses transient cache).
 *
 * @see AP_Version_Check::hasUpdate()
 */
function ap_has_core_update(?AP_DB $db = null): bool
{
    if (!class_exists('AP_Version_Check', false)) {
        return false;
    }

    return AP_Version_Check::hasUpdate($db);
}

/**
 * Cached remote version.json payload (or empty on failure / disabled).
 *
 * @return array{
 *   ok: bool,
 *   version: string,
 *   download_url: string,
 *   changelog_url: string,
 *   sha256: string,
 *   checked_at: int,
 *   from_cache: bool
 * }
 *
 * @see AP_Version_Check::getRemoteInfo()
 */
function ap_get_remote_version_info(?AP_DB $db = null, bool $force = false): array
{
    if (!class_exists('AP_Version_Check', false)) {
        return [
            'ok' => false,
            'version' => '',
            'download_url' => '',
            'changelog_url' => '',
            'sha256' => '',
            'checked_at' => 0,
            'from_cache' => false,
        ];
    }

    return AP_Version_Check::getRemoteInfo($db, $force);
}

/**
 * Force a fresh version.json fetch (clears the cache first).
 *
 * @return array{
 *   ok: bool,
 *   version: string,
 *   download_url: string,
 *   changelog_url: string,
 *   sha256: string,
 *   checked_at: int,
 *   from_cache: bool
 * }
 *
 * @see AP_Version_Check::forceCheck()
 */
function ap_force_version_check(?AP_DB $db = null): array
{
    if (!class_exists('AP_Version_Check', false)) {
        return [
            'ok' => false,
            'version' => '',
            'download_url' => '',
            'changelog_url' => '',
            'sha256' => '',
            'checked_at' => 0,
            'from_cache' => false,
        ];
    }

    return AP_Version_Check::forceCheck($db);
}

/**
 * Whether a one-click core update can run (pre-flight).
 *
 * @return array{
 *   ok: bool,
 *   can_update: bool,
 *   current_version: string,
 *   remote_version: string,
 *   download_url: string,
 *   changelog_url: string,
 *   sha256: string,
 *   has_update: bool,
 *   errors: list<string>,
 *   warnings: list<string>,
 *   checks: array<string, bool>
 * }
 *
 * @see AP_Core_Updater::canUpdate()
 */
function ap_can_core_update(?AP_DB $db = null): array
{
    if (!class_exists('AP_Core_Updater', false)) {
        return [
            'ok' => false,
            'can_update' => false,
            'current_version' => '',
            'remote_version' => '',
            'download_url' => '',
            'changelog_url' => '',
            'sha256' => '',
            'has_update' => false,
            'errors' => ['Core updater is not loaded.'],
            'warnings' => [],
            'checks' => [],
        ];
    }

    return AP_Core_Updater::canUpdate($db);
}

/**
 * Run the one-click core auto-update (download → verify → apply → migrate).
 *
 * @param array<string, mixed> $args See {@see AP_Core_Updater::run()}.
 *
 * @return array{
 *   ok: bool,
 *   from_version: string,
 *   to_version: string,
 *   files_applied: int,
 *   migrations: list<array{version: int, description: string}>,
 *   package_version: string,
 *   errors: list<string>,
 *   warnings: list<string>,
 *   steps: list<string>
 * }
 *
 * @see AP_Core_Updater::run()
 */
function ap_run_core_update(?AP_DB $db = null, array $args = []): array
{
    if (!class_exists('AP_Core_Updater', false)) {
        return [
            'ok' => false,
            'from_version' => '',
            'to_version' => '',
            'files_applied' => 0,
            'migrations' => [],
            'package_version' => '',
            'errors' => ['Core updater is not loaded.'],
            'warnings' => [],
            'steps' => [],
        ];
    }

    return AP_Core_Updater::run($db, $args);
}

/**
 * Whether the site is in auto-update maintenance mode.
 *
 * @see AP_Core_Updater::isMaintenanceMode()
 */
function ap_is_maintenance_mode(?string $abspath = null): bool
{
    if (!class_exists('AP_Core_Updater', false)) {
        return false;
    }

    return AP_Core_Updater::isMaintenanceMode($abspath);
}

// -----------------------------------------------------------------------------
// WordPress WXR importer
// -----------------------------------------------------------------------------

/**
 * Import a WordPress WXR export from a filesystem path.
 *
 * @param array<string, mixed> $args See {@see AP_Wxr_Importer::importFromFile()}.
 *
 * @return array<string, mixed>
 *
 * @see AP_Wxr_Importer::importFromFile()
 */
function ap_import_wxr(string $path, ?AP_DB $db = null, array $args = []): array
{
    if (!class_exists('AP_Wxr_Importer', false)) {
        return [
            'ok' => false,
            'errors' => ['WXR importer is not loaded.'],
            'warnings' => [],
            'authors' => 0,
            'posts' => 0,
            'pages' => 0,
            'comments' => 0,
            'skipped' => 0,
        ];
    }

    return AP_Wxr_Importer::importFromFile($path, $db, $args);
}

/**
 * Import a WordPress WXR export from an XML string.
 *
 * @param array<string, mixed> $args See {@see AP_Wxr_Importer::importFromString()}.
 *
 * @return array<string, mixed>
 *
 * @see AP_Wxr_Importer::importFromString()
 */
function ap_import_wxr_string(string $xml, ?AP_DB $db = null, array $args = []): array
{
    if (!class_exists('AP_Wxr_Importer', false)) {
        return [
            'ok' => false,
            'errors' => ['WXR importer is not loaded.'],
            'warnings' => [],
            'authors' => 0,
            'posts' => 0,
            'pages' => 0,
            'comments' => 0,
            'skipped' => 0,
        ];
    }

    return AP_Wxr_Importer::importFromString($xml, $db, $args);
}

/**
 * Handle a multipart WXR upload (typically $_FILES['wxr']).
 *
 * @param array<string, mixed> $file
 * @param array<string, mixed> $args
 *
 * @return array<string, mixed>
 *
 * @see AP_Wxr_Importer::handleUpload()
 */
function ap_import_wxr_upload(array $file, ?AP_DB $db = null, array $args = []): array
{
    if (!class_exists('AP_Wxr_Importer', false)) {
        return [
            'ok' => false,
            'errors' => ['WXR importer is not loaded.'],
            'warnings' => [],
        ];
    }

    return AP_Wxr_Importer::handleUpload($file, $db, $args);
}

/**
 * Whether a string looks like a WordPress WXR export.
 *
 * @see AP_Wxr_Importer::isWxr()
 */
function ap_is_wxr(string $xml): bool
{
    if (!class_exists('AP_Wxr_Importer', false)) {
        return false;
    }

    return AP_Wxr_Importer::isWxr($xml);
}

// -----------------------------------------------------------------------------
// Privacy tools (personal data export / erase)
// -----------------------------------------------------------------------------

/**
 * Privacy policy page ID (0 = none).
 *
 * @see AP_Privacy::getPrivacyPolicyPageId()
 */
function ap_get_privacy_policy_page_id(?AP_DB $db = null): int
{
    if (!class_exists('AP_Privacy', false)) {
        return 0;
    }

    return AP_Privacy::getPrivacyPolicyPageId($db);
}

/**
 * Set the privacy policy page ID (0 clears).
 *
 * @see AP_Privacy::setPrivacyPolicyPageId()
 */
function ap_set_privacy_policy_page_id(int $pageId, ?AP_DB $db = null): bool
{
    if (!class_exists('AP_Privacy', false)) {
        return false;
    }

    return AP_Privacy::setPrivacyPolicyPageId($pageId, $db);
}

/**
 * Public URL of the privacy policy page when configured.
 *
 * @see AP_Privacy::getPrivacyPolicyUrl()
 */
function ap_get_privacy_policy_url(?AP_DB $db = null): string
{
    if (!class_exists('AP_Privacy', false)) {
        return '';
    }

    return AP_Privacy::getPrivacyPolicyUrl($db);
}

/**
 * Export personal data for a user (structured package).
 *
 * @return array<string, mixed>
 *
 * @see AP_Privacy::exportPersonalData()
 */
function ap_export_personal_data(int $userId, ?AP_DB $db = null): array
{
    if (!class_exists('AP_Privacy', false)) {
        return [
            'ok' => false,
            'errors' => ['Privacy tools are not loaded.'],
            'user_id' => $userId,
            'groups' => [],
        ];
    }

    return AP_Privacy::exportPersonalData($userId, $db);
}

/**
 * Export personal data as a downloadable JSON document.
 *
 * @return array{ok: bool, errors: list<string>, json: string, filename: string, user_id: int}
 *
 * @see AP_Privacy::exportPersonalDataJson()
 */
function ap_export_personal_data_json(int $userId, ?AP_DB $db = null): array
{
    if (!class_exists('AP_Privacy', false)) {
        return [
            'ok' => false,
            'errors' => ['Privacy tools are not loaded.'],
            'json' => '',
            'filename' => '',
            'user_id' => $userId,
        ];
    }

    return AP_Privacy::exportPersonalDataJson($userId, $db);
}

/**
 * Erase personal data for a user (anonymize content + delete account).
 *
 * @param array<string, mixed> $args reassign (int) optional content owner.
 *
 * @return array<string, mixed>
 *
 * @see AP_Privacy::erasePersonalData()
 */
function ap_erase_personal_data(int $userId, array $args = [], ?AP_DB $db = null): array
{
    if (!class_exists('AP_Privacy', false)) {
        return [
            'ok' => false,
            'errors' => ['Privacy tools are not loaded.'],
            'warnings' => [],
            'user_id' => $userId,
            'counts' => [],
        ];
    }

    return AP_Privacy::erasePersonalData($userId, $args, $db);
}

// -----------------------------------------------------------------------------
// Site Health (status checks + system info)
// -----------------------------------------------------------------------------

/**
 * Run Site Health checks.
 *
 * @return list<array{id: string, label: string, status: string, message: string, badge?: string}>
 *
 * @see AP_Site_Health::getChecks()
 */
function ap_get_site_health_checks(?AP_DB $db = null, ?string $abspath = null): array
{
    if (!class_exists('AP_Site_Health', false)) {
        return [];
    }

    return AP_Site_Health::getChecks($db, $abspath);
}

/**
 * Site Health status summary (good / recommended / critical counts).
 *
 * @param list<array{status?: string}>|null $checks
 *
 * @return array{good: int, recommended: int, critical: int, total: int}
 *
 * @see AP_Site_Health::getSummary()
 */
function ap_get_site_health_summary(?array $checks = null, ?AP_DB $db = null): array
{
    if (!class_exists('AP_Site_Health', false)) {
        return ['good' => 0, 'recommended' => 0, 'critical' => 0, 'total' => 0];
    }

    return AP_Site_Health::getSummary($checks, $db);
}

/**
 * Overall Site Health status: good | recommended | critical.
 *
 * @param list<array{status?: string}>|null $checks
 *
 * @see AP_Site_Health::getOverallStatus()
 */
function ap_get_site_health_status(?array $checks = null, ?AP_DB $db = null): string
{
    if (!class_exists('AP_Site_Health', false)) {
        return 'good';
    }

    return AP_Site_Health::getOverallStatus($checks, $db);
}

/**
 * Structured system information for Site Health → Info.
 *
 * @return array<string, array{label: string, fields: list<array{label: string, value: string}>}>
 *
 * @see AP_Site_Health::getInfo()
 */
function ap_get_site_health_info(?AP_DB $db = null, ?string $abspath = null): array
{
    if (!class_exists('AP_Site_Health', false)) {
        return [];
    }

    return AP_Site_Health::getInfo($db, $abspath);
}

/**
 * Plain-text system information dump (support copy box).
 *
 * @see AP_Site_Health::getInfoText()
 */
function ap_get_site_health_info_text(?AP_DB $db = null, ?string $abspath = null): string
{
    if (!class_exists('AP_Site_Health', false)) {
        return '';
    }

    return AP_Site_Health::getInfoText($db, $abspath);
}

/**
 * Clear object cache + expired option-backed transients.
 *
 * @return array{ok: bool, object_cache: bool, expired_transients: int, message: string}
 *
 * @see AP_Site_Health::clearCaches()
 */
function ap_clear_site_health_caches(?AP_DB $db = null): array
{
    if (!class_exists('AP_Site_Health', false)) {
        return [
            'ok' => false,
            'object_cache' => false,
            'expired_transients' => 0,
            'message' => 'Site Health is not loaded.',
        ];
    }

    return AP_Site_Health::clearCaches($db);
}

/**
 * Resolve a user by ID, login, or email for privacy tools.
 *
 * @see AP_Privacy::resolveUser()
 */
function ap_privacy_resolve_user(string|int $identifier, ?AP_DB $db = null): ?AP_User
{
    if (!class_exists('AP_Privacy', false)) {
        return null;
    }

    return AP_Privacy::resolveUser($identifier, $db);
}

/**
 * Parse a WXR document without writing to the database.
 *
 * @return array<string, mixed>
 *
 * @see AP_Wxr_Importer::parse()
 */
function ap_parse_wxr(string $xml): array
{
    if (!class_exists('AP_Wxr_Importer', false)) {
        return ['errors' => ['WXR importer is not loaded.'], 'items' => []];
    }

    return AP_Wxr_Importer::parse($xml);
}

// -----------------------------------------------------------------------------
// phpBB importer
// -----------------------------------------------------------------------------

/**
 * Import a phpBB portable JSON export from a filesystem path.
 *
 * @param array<string, mixed> $args See {@see AP_Phpbb_Importer::importFromFile()}.
 *
 * @return array<string, mixed>
 *
 * @see AP_Phpbb_Importer::importFromFile()
 */
function ap_import_phpbb(string $path, ?AP_DB $db = null, array $args = []): array
{
    if (!class_exists('AP_Phpbb_Importer', false)) {
        return [
            'ok' => false,
            'errors' => ['phpBB importer is not loaded.'],
            'warnings' => [],
            'users' => 0,
            'forums' => 0,
            'topics' => 0,
            'posts' => 0,
            'skipped' => 0,
        ];
    }

    return AP_Phpbb_Importer::importFromFile($path, $db, $args);
}

/**
 * Import a phpBB portable JSON export from a string.
 *
 * @param array<string, mixed> $args See {@see AP_Phpbb_Importer::importFromString()}.
 *
 * @return array<string, mixed>
 *
 * @see AP_Phpbb_Importer::importFromString()
 */
function ap_import_phpbb_string(string $json, ?AP_DB $db = null, array $args = []): array
{
    if (!class_exists('AP_Phpbb_Importer', false)) {
        return [
            'ok' => false,
            'errors' => ['phpBB importer is not loaded.'],
            'warnings' => [],
            'users' => 0,
            'forums' => 0,
            'topics' => 0,
            'posts' => 0,
            'skipped' => 0,
        ];
    }

    return AP_Phpbb_Importer::importFromString($json, $db, $args);
}

/**
 * Import from a live phpBB database.
 *
 * @param array<string, mixed> $connection driver, host, name, user, password, table_prefix
 * @param array<string, mixed> $args
 *
 * @return array<string, mixed>
 *
 * @see AP_Phpbb_Importer::importFromDatabase()
 */
function ap_import_phpbb_database(array $connection, ?AP_DB $db = null, array $args = []): array
{
    if (!class_exists('AP_Phpbb_Importer', false)) {
        return [
            'ok' => false,
            'errors' => ['phpBB importer is not loaded.'],
            'warnings' => [],
        ];
    }

    return AP_Phpbb_Importer::importFromDatabase($connection, $db, $args);
}

/**
 * Handle a multipart phpBB JSON upload (typically $_FILES['phpbb']).
 *
 * @param array<string, mixed> $file
 * @param array<string, mixed> $args
 *
 * @return array<string, mixed>
 *
 * @see AP_Phpbb_Importer::handleUpload()
 */
function ap_import_phpbb_upload(array $file, ?AP_DB $db = null, array $args = []): array
{
    if (!class_exists('AP_Phpbb_Importer', false)) {
        return [
            'ok' => false,
            'errors' => ['phpBB importer is not loaded.'],
            'warnings' => [],
        ];
    }

    return AP_Phpbb_Importer::handleUpload($file, $db, $args);
}

/**
 * Whether a string looks like an AgoraPress phpBB JSON export.
 *
 * @see AP_Phpbb_Importer::isPhpbbJson()
 */
function ap_is_phpbb_json(string $json): bool
{
    if (!class_exists('AP_Phpbb_Importer', false)) {
        return false;
    }

    return AP_Phpbb_Importer::isPhpbbJson($json);
}

/**
 * Parse a phpBB JSON export without writing to the database.
 *
 * @return array<string, mixed>
 *
 * @see AP_Phpbb_Importer::parseJson()
 */
function ap_parse_phpbb_json(string $json): array
{
    if (!class_exists('AP_Phpbb_Importer', false)) {
        return ['errors' => ['phpBB importer is not loaded.'], 'users' => [], 'forums' => []];
    }

    return AP_Phpbb_Importer::parseJson($json);
}

/**
 * Clean phpBB post_text (strip BBCode UIDs, smiley comments).
 *
 * @see AP_Phpbb_Importer::cleanPostText()
 */
function ap_clean_phpbb_post_text(string $text, string $bbcodeUid = ''): string
{
    if (!class_exists('AP_Phpbb_Importer', false)) {
        return $text;
    }

    return AP_Phpbb_Importer::cleanPostText($text, $bbcodeUid);
}

// -----------------------------------------------------------------------------
// Settings API
// -----------------------------------------------------------------------------

/**
 * Register an option with a settings group.
 *
 * @param array<string, mixed> $args
 *
 * @see AP_Settings::registerSetting()
 */
function ap_register_setting(string $optionGroup, string $optionName, array $args = []): void
{
    if (!class_exists('AP_Settings', false)) {
        return;
    }
    AP_Settings::registerSetting($optionGroup, $optionName, $args);
}

/**
 * Add a settings section.
 *
 * @see AP_Settings::addSection()
 */
function ap_add_settings_section(
    string $id,
    string $title,
    ?callable $callback,
    string $page
): void {
    if (!class_exists('AP_Settings', false)) {
        return;
    }
    AP_Settings::addSection($id, $title, $callback, $page);
}

/**
 * Add a settings field.
 *
 * @param array<string, mixed> $args
 *
 * @see AP_Settings::addField()
 */
function ap_add_settings_field(
    string $id,
    string $title,
    ?callable $callback,
    string $page,
    string $section = 'default',
    array $args = []
): void {
    if (!class_exists('AP_Settings', false)) {
        return;
    }
    AP_Settings::addField($id, $title, $callback, $page, $section, $args);
}

/**
 * Output nonce + option_page hidden fields for a settings form.
 *
 * @see AP_Settings::settingsFields()
 */
function ap_settings_fields(string $optionGroup): void
{
    if (!class_exists('AP_Settings', false)) {
        return;
    }
    AP_Settings::settingsFields($optionGroup, true);
}

/**
 * Render all sections for a settings page.
 *
 * @see AP_Settings::doSections()
 */
function ap_do_settings_sections(string $page): void
{
    if (!class_exists('AP_Settings', false)) {
        return;
    }
    AP_Settings::doSections($page);
}

/**
 * Render fields for one section.
 *
 * @see AP_Settings::doFields()
 */
function ap_do_settings_fields(string $page, string $section): void
{
    if (!class_exists('AP_Settings', false)) {
        return;
    }
    AP_Settings::doFields($page, $section);
}

/**
 * Settings form submit button.
 *
 * @see AP_Settings::submitButton()
 */
function ap_settings_submit_button(string $text = 'Save Changes'): void
{
    if (!class_exists('AP_Settings', false)) {
        return;
    }
    AP_Settings::submitButton($text);
}

/**
 * Whether a core module is enabled.
 *
 * @param string $module static_pages|blog|forum
 *
 * @see AP_Options::isModuleEnabled()
 */
function ap_is_module_enabled(string $module, ?AP_DB $db = null): bool
{
    if (!class_exists('AP_Options', false)) {
        // Options layer not loaded — treat modules as enabled (installer / partial boot).
        return true;
    }

    return AP_Options::isModuleEnabled($module, $db);
}

// -----------------------------------------------------------------------------
// Navigation menus
// -----------------------------------------------------------------------------

/**
 * Register a theme nav menu location.
 *
 * @see AP_Nav_Menu::registerLocation()
 */
function ap_register_nav_menu(string $location, string $description = ''): void
{
    if (!class_exists('AP_Nav_Menu', false)) {
        return;
    }
    AP_Nav_Menu::registerLocation($location, $description);
}

/**
 * Register multiple theme nav menu locations.
 *
 * @param array<string, string> $locations
 *
 * @see AP_Nav_Menu::registerLocations()
 */
function ap_register_nav_menus(array $locations): void
{
    if (!class_exists('AP_Nav_Menu', false)) {
        return;
    }
    AP_Nav_Menu::registerLocations($locations);
}

/**
 * Whether a theme location has an assigned menu with items.
 *
 * @see AP_Nav_Menu::hasNavMenu()
 */
function ap_has_nav_menu(string $location, ?AP_DB $db = null): bool
{
    if (!class_exists('AP_Nav_Menu', false)) {
        return false;
    }

    return AP_Nav_Menu::hasNavMenu($location, $db);
}

/**
 * Render (or return) a navigation menu.
 *
 * @param array<string, mixed> $args
 *
 * @see AP_Nav_Menu::render()
 */
function ap_nav_menu(array $args = [], ?AP_DB $db = null): string
{
    if (!class_exists('AP_Nav_Menu', false)) {
        return '';
    }

    return AP_Nav_Menu::render($args, $db);
}

/**
 * Default primary-nav fallback: Home, published pages, optional Forums.
 *
 * Suitable as ap_nav_menu() fallback_cb so published static pages appear in
 * the navigation bar when no custom menu is assigned.
 *
 * @param array<string, mixed> $args
 *
 * @see AP_Nav_Menu::fallbackPrimary()
 */
function ap_nav_menu_fallback_primary(array $args = [], ?AP_DB $db = null): string
{
    if (!class_exists('AP_Nav_Menu', false)) {
        return '';
    }

    return AP_Nav_Menu::fallbackPrimary($args, $db);
}

/**
 * Default footer-nav fallback: Privacy Policy + Login/Account (+ Register).
 *
 * Suitable as ap_nav_menu() fallback_cb so utility links appear in the footer
 * when no custom menu is assigned.
 *
 * @param array<string, mixed> $args
 *
 * @see AP_Nav_Menu::fallbackFooter()
 */
function ap_nav_menu_fallback_footer(array $args = [], ?AP_DB $db = null): string
{
    if (!class_exists('AP_Nav_Menu', false)) {
        return '';
    }

    return AP_Nav_Menu::fallbackFooter($args, $db);
}

/**
 * Save a navigation menu (name + items).
 *
 * @param list<array<string, mixed>> $items
 *
 * @see AP_Nav_Menu::saveMenu()
 */
function ap_save_nav_menu(string $slug, string $name, array $items = [], ?AP_DB $db = null): bool
{
    if (!class_exists('AP_Nav_Menu', false)) {
        return false;
    }

    return AP_Nav_Menu::saveMenu($slug, $name, $items, $db);
}

/**
 * Get a navigation menu by slug.
 *
 * @return array{name: string, items: list<array<string, mixed>>}|null
 *
 * @see AP_Nav_Menu::getMenu()
 */
function ap_get_nav_menu(string $slug, ?AP_DB $db = null): ?array
{
    return AP_Nav_Menu::getMenu($slug, $db);
}

/**
 * Assign menus to theme locations.
 *
 * @param array<string, string> $map
 *
 * @see AP_Nav_Menu::setLocationAssignments()
 */
function ap_set_nav_menu_locations(array $map, ?AP_DB $db = null): bool
{
    return AP_Nav_Menu::setLocationAssignments($map, $db);
}

// -----------------------------------------------------------------------------
// Widgets / modular areas
// -----------------------------------------------------------------------------

/**
 * Register a modular area (sidebar).
 *
 * @param array<string, mixed> $args
 *
 * @see AP_Widgets::registerSidebar()
 */
function ap_register_sidebar(string $id, array $args = []): void
{
    if (!class_exists('AP_Widgets', false)) {
        return;
    }
    AP_Widgets::registerSidebar($id, $args);
}

/**
 * Register multiple sidebars.
 *
 * @param array<string, array<string, mixed>> $sidebars id => args
 */
function ap_register_sidebars(array $sidebars): void
{
    if (!class_exists('AP_Widgets', false)) {
        return;
    }
    foreach ($sidebars as $id => $args) {
        if (is_string($id)) {
            AP_Widgets::registerSidebar($id, is_array($args) ? $args : []);
        }
    }
}

/**
 * Register a widget type.
 *
 * @param array<string, mixed> $args
 *
 * @see AP_Widgets::registerWidget()
 */
function ap_register_widget(string $idBase, array $args = []): void
{
    if (!class_exists('AP_Widgets', false)) {
        return;
    }
    AP_Widgets::registerWidget($idBase, $args);
}

/**
 * Whether a sidebar has at least one assigned widget.
 *
 * @see AP_Widgets::isActiveSidebar()
 */
function ap_is_active_sidebar(string $sidebarId, ?AP_DB $db = null): bool
{
    if (!class_exists('AP_Widgets', false)) {
        return false;
    }

    return AP_Widgets::isActiveSidebar($sidebarId, $db);
}

/**
 * Render widgets in a modular area.
 *
 * @param array{echo?: bool} $args
 *
 * @see AP_Widgets::dynamicSidebar()
 */
function ap_dynamic_sidebar(string $sidebarId, array $args = [], ?AP_DB $db = null): string
{
    if (!class_exists('AP_Widgets', false)) {
        return '';
    }

    return AP_Widgets::dynamicSidebar($sidebarId, $args, $db);
}

/**
 * Registered sidebars (id => args).
 *
 * @return array<string, array<string, string>>
 *
 * @see AP_Widgets::getSidebars()
 */
function ap_get_sidebars(): array
{
    if (!class_exists('AP_Widgets', false)) {
        return [];
    }

    return AP_Widgets::getSidebars();
}

/**
 * Child posts of a parent.
 *
 * @param array<string, mixed> $args
 *
 * @return list<AP_Post>
 *
 * @see AP_Post::getChildren()
 */
function ap_get_post_children(int $parentId, array $args = [], ?AP_DB $db = null): array
{
    return AP_Post::getChildren($parentId, $args, $db);
}

/**
 * Ancestor IDs (parent → root).
 *
 * @return list<int>
 *
 * @see AP_Post::getAncestorIds()
 */
function ap_get_post_ancestor_ids(int $id, ?AP_DB $db = null): array
{
    return AP_Post::getAncestorIds($id, $db);
}

/**
 * Hierarchical page path of slugs (a/b/c).
 *
 * @see AP_Post::getPagePath()
 */
function ap_get_page_path(int $id, ?AP_DB $db = null): string
{
    return AP_Post::getPagePath($id, $db);
}

/**
 * Nested tree for hierarchical post types.
 *
 * @param array<string, mixed> $args
 *
 * @return list<array{post: AP_Post, children: list<array<string, mixed>>}>
 *
 * @see AP_Post::getTree()
 */
function ap_get_page_tree(array $args = [], ?AP_DB $db = null): array
{
    return AP_Post::getTree($args, $db);
}

/**
 * Sanitize a title into a URL slug.
 *
 * @see AP_Post::sanitizeSlug()
 */
function ap_sanitize_title(string $title): string
{
    return AP_Post::sanitizeSlug($title);
}

/**
 * Read post meta.
 *
 * @return ($single is true ? string|null : list<string>)
 *
 * @see AP_Post::getMeta()
 */
function ap_get_post_meta(
    int $postId,
    string $key,
    bool $single = true,
    ?AP_DB $db = null
): string|array|null {
    return AP_Post::getMeta($postId, $key, $single, $db);
}

/**
 * Update (or insert) post meta.
 *
 * @see AP_Post::updateMeta()
 */
function ap_update_post_meta(
    int $postId,
    string $key,
    string $value,
    ?AP_DB $db = null
): bool {
    return AP_Post::updateMeta($postId, $key, $value, $db);
}

/**
 * Delete post meta for a key.
 *
 * @see AP_Post::deleteMeta()
 */
function ap_delete_post_meta(int $postId, string $key, ?AP_DB $db = null): bool
{
    return AP_Post::deleteMeta($postId, $key, $db);
}

/**
 * Page template slug (default when unset).
 *
 * @see AP_Post::getPageTemplate()
 */
function ap_get_page_template(int $postId, ?AP_DB $db = null): string
{
    return AP_Post::getPageTemplate($postId, $db);
}

/**
 * Set page template slug.
 *
 * @see AP_Post::setPageTemplate()
 */
function ap_set_page_template(int $postId, string $template, ?AP_DB $db = null): bool
{
    return AP_Post::setPageTemplate($postId, $template, $db);
}

// -----------------------------------------------------------------------------
// Media library — uploads & attachments
// -----------------------------------------------------------------------------

/**
 * Handle a single $_FILES-style upload and create an attachment post.
 *
 * @param array<string, mixed> $file
 * @param array<string, mixed> $args
 *
 * @return array{ok: bool, id: int, file: string, url: string, type: string, error: string, post: ?AP_Post}
 *
 * @see AP_Media::handleUpload()
 */
function ap_handle_upload(array $file, array $args = [], ?AP_DB $db = null): array
{
    return AP_Media::handleUpload($file, $args, $db);
}

/**
 * Create an attachment for a file already under the uploads directory.
 *
 * @param array<string, mixed> $data
 *
 * @see AP_Media::insertAttachment()
 */
function ap_insert_attachment(array $data, ?AP_DB $db = null): int
{
    return AP_Media::insertAttachment($data, $db);
}

/**
 * Permanently delete an attachment post and its file.
 *
 * @see AP_Media::deleteAttachment()
 */
function ap_delete_attachment(int $id, ?AP_DB $db = null): bool
{
    return AP_Media::deleteAttachment($id, $db);
}

/**
 * Update attachment title / caption / description / alt text.
 *
 * @param array<string, mixed> $data
 *
 * @see AP_Media::updateAttachment()
 */
function ap_update_attachment(int $id, array $data, ?AP_DB $db = null): bool
{
    return AP_Media::updateAttachment($id, $data, $db);
}

/**
 * Absolute filesystem path for an attachment file.
 *
 * @see AP_Media::getAttachedFile()
 */
function ap_get_attached_file(int $id, ?AP_DB $db = null): string
{
    return AP_Media::getAttachedFile($id, $db);
}

/**
 * Public URL for an attachment file.
 *
 * @see AP_Media::getAttachmentUrl()
 */
function ap_get_attachment_url(int $id, ?AP_DB $db = null): string
{
    return AP_Media::getAttachmentUrl($id, $db);
}

/**
 * Image alt text for an attachment.
 *
 * @see AP_Media::getAltText()
 */
function ap_get_attachment_alt(int $id, ?AP_DB $db = null): string
{
    return AP_Media::getAltText($id, $db);
}

/**
 * Set image alt text for an attachment.
 *
 * @see AP_Media::setAltText()
 */
function ap_set_attachment_alt(int $id, string $alt, ?AP_DB $db = null): bool
{
    return AP_Media::setAltText($id, $alt, $db);
}

/**
 * Attachment metadata array (filesize, dimensions, …).
 *
 * @return array<string, mixed>
 *
 * @see AP_Media::getMetadata()
 */
function ap_get_attachment_metadata(int $id, ?AP_DB $db = null): array
{
    return AP_Media::getMetadata($id, $db);
}

/**
 * Upload directory paths and URLs for the current time (or $time).
 *
 * @return array{path: string, url: string, subdir: string, basedir: string, baseurl: string, error: string|false}
 *
 * @see AP_Media::uploadDir()
 */
function ap_upload_dir(?int $time = null): array
{
    return AP_Media::uploadDir($time);
}

/**
 * Query media library attachments.
 *
 * @param array<string, mixed> $args
 *
 * @return array{items: list<AP_Post>, total: int}
 *
 * @see AP_Media::query()
 */
function ap_get_media(array $args = [], ?AP_DB $db = null): array
{
    return AP_Media::query($args, $db);
}

/**
 * Whether a MIME type (or attachment post) is an image.
 *
 * @see AP_Media::isImage()
 */
function ap_attachment_is_image(AP_Post|string $postOrMime): bool
{
    return AP_Media::isImage($postOrMime);
}

/**
 * Validate a filename (and optional real path) against the upload allow-list.
 *
 * @return array{ok: bool, ext: string, type: string, error: string}
 *
 * @see AP_Media::checkFileType()
 */
function ap_check_filetype(string $filename, string $realPath = ''): array
{
    return AP_Media::checkFileType($filename, $realPath);
}

// -----------------------------------------------------------------------------
// Taxonomies (categories, tags, custom)
// -----------------------------------------------------------------------------

/**
 * Register a taxonomy.
 *
 * @param array<string, mixed> $args
 *
 * @see AP_Taxonomy::register()
 */
function ap_register_taxonomy(string $taxonomy, array $args = []): void
{
    AP_Taxonomy::register($taxonomy, $args);
}

/**
 * @return array<string, mixed>|null
 *
 * @see AP_Taxonomy::getObject()
 */
function ap_get_taxonomy(string $taxonomy): ?array
{
    return AP_Taxonomy::getObject($taxonomy);
}

/**
 * @return array<string, array<string, mixed>>
 *
 * @see AP_Taxonomy::getTaxonomies()
 */
function ap_get_taxonomies(): array
{
    return AP_Taxonomy::getTaxonomies();
}

/**
 * @see AP_Taxonomy::exists()
 */
function ap_taxonomy_exists(string $taxonomy): bool
{
    return AP_Taxonomy::exists($taxonomy);
}

/**
 * @see AP_Taxonomy::isHierarchical()
 */
function ap_is_taxonomy_hierarchical(string $taxonomy): bool
{
    return AP_Taxonomy::isHierarchical($taxonomy);
}

/**
 * Taxonomies for a post type.
 *
 * @return list<string>
 *
 * @see AP_Taxonomy::getObjectTaxonomies()
 */
function ap_get_object_taxonomies(string $postType): array
{
    return AP_Taxonomy::getObjectTaxonomies($postType);
}

/**
 * Insert a term.
 *
 * @param array<string, mixed> $args
 *
 * @return array{term_id: int, term_taxonomy_id: int}|int
 *
 * @see AP_Taxonomy::insertTerm()
 */
function ap_insert_term(
    string $name,
    string $taxonomy,
    array $args = [],
    ?AP_DB $db = null
): array|int {
    return AP_Taxonomy::insertTerm($name, $taxonomy, $args, $db);
}

/**
 * Update a term.
 *
 * @param array<string, mixed> $args
 *
 * @see AP_Taxonomy::updateTerm()
 */
function ap_update_term(
    int $termId,
    string $taxonomy,
    array $args,
    ?AP_DB $db = null
): bool {
    return AP_Taxonomy::updateTerm($termId, $taxonomy, $args, $db);
}

/**
 * Delete a term.
 *
 * @see AP_Taxonomy::deleteTerm()
 */
function ap_delete_term(int $termId, string $taxonomy, ?AP_DB $db = null): bool
{
    return AP_Taxonomy::deleteTerm($termId, $taxonomy, $db);
}

/**
 * Get a term by ID.
 *
 * @see AP_Taxonomy::getTerm()
 */
function ap_get_term(int $termId, string $taxonomy = '', ?AP_DB $db = null): ?object
{
    return AP_Taxonomy::getTerm($termId, $taxonomy, $db);
}

/**
 * Get a term by slug within a taxonomy.
 *
 * @see AP_Taxonomy::getTermBySlug()
 */
function ap_get_term_by_slug(string $slug, string $taxonomy, ?AP_DB $db = null): ?object
{
    return AP_Taxonomy::getTermBySlug($slug, $taxonomy, $db);
}

/**
 * List terms.
 *
 * @param array<string, mixed> $args
 *
 * @return list<object>|list<int>|list<string>|array<int, string>
 *
 * @see AP_Taxonomy::getTerms()
 */
function ap_get_terms(string $taxonomy, array $args = [], ?AP_DB $db = null): array
{
    return AP_Taxonomy::getTerms($taxonomy, $args, $db);
}

/**
 * Nested term tree (hierarchical taxonomies).
 *
 * @param array<string, mixed> $args
 *
 * @return list<array{term: object, children: list<array<string, mixed>>}>
 *
 * @see AP_Taxonomy::getTermTree()
 */
function ap_get_term_tree(
    string $taxonomy,
    array $args = [],
    int $parent = 0,
    ?AP_DB $db = null
): array {
    return AP_Taxonomy::getTermTree($taxonomy, $args, $parent, $db);
}

/**
 * Assign terms to a post/object.
 *
 * @param list<int|string>|int|string $terms
 *
 * @return list<int>
 *
 * @see AP_Taxonomy::setObjectTerms()
 */
function ap_set_object_terms(
    int $objectId,
    array|int|string $terms,
    string $taxonomy,
    bool $append = false,
    ?AP_DB $db = null
): array {
    return AP_Taxonomy::setObjectTerms($objectId, $terms, $taxonomy, $append, $db);
}

/**
 * Terms on a post/object.
 *
 * @param array<string, mixed> $args
 *
 * @return list<object>|list<int>|list<string>
 *
 * @see AP_Taxonomy::getObjectTerms()
 */
function ap_get_object_terms(
    int $objectId,
    string $taxonomy = '',
    array $args = [],
    ?AP_DB $db = null
): array {
    return AP_Taxonomy::getObjectTerms($objectId, $taxonomy, $args, $db);
}

/**
 * Remove terms from a post/object.
 *
 * @param list<int|string>|int|string $terms
 *
 * @see AP_Taxonomy::removeObjectTerms()
 */
function ap_remove_object_terms(
    int $objectId,
    array|int|string $terms,
    string $taxonomy,
    ?AP_DB $db = null
): bool {
    return AP_Taxonomy::removeObjectTerms($objectId, $terms, $taxonomy, $db);
}

/**
 * Object IDs that have given term IDs.
 *
 * @param list<int> $termIds
 * @param array<string, mixed> $args
 *
 * @return list<int>
 *
 * @see AP_Taxonomy::getObjectsInTerm()
 */
function ap_get_objects_in_term(array $termIds, array $args = [], ?AP_DB $db = null): array
{
    return AP_Taxonomy::getObjectsInTerm($termIds, $args, $db);
}

/**
 * Ensure default Uncategorized category exists; return its term_id.
 *
 * @see AP_Taxonomy::ensureDefaultCategory()
 */
function ap_ensure_default_category(?AP_DB $db = null): int
{
    return AP_Taxonomy::ensureDefaultCategory($db);
}

/**
 * Default category term_id.
 *
 * @see AP_Taxonomy::getDefaultCategoryId()
 */
function ap_get_default_category_id(?AP_DB $db = null): int
{
    return AP_Taxonomy::getDefaultCategoryId($db);
}

/**
 * Categories assigned to a post.
 *
 * @param array<string, mixed> $args
 *
 * @return list<object>|list<int>|list<string>
 */
function ap_get_post_categories(
    int $postId,
    array $args = [],
    ?AP_DB $db = null
): array {
    return AP_Taxonomy::getObjectTerms($postId, 'category', $args, $db);
}

/**
 * Tags assigned to a post.
 *
 * @param array<string, mixed> $args
 *
 * @return list<object>|list<int>|list<string>
 */
function ap_get_post_tags(int $postId, array $args = [], ?AP_DB $db = null): array
{
    return AP_Taxonomy::getObjectTerms($postId, 'post_tag', $args, $db);
}

/**
 * Set categories on a post (replaces existing).
 *
 * @param list<int|string>|int|string $categories
 *
 * @return list<int>
 */
function ap_set_post_categories(
    int $postId,
    array|int|string $categories,
    ?AP_DB $db = null
): array {
    return AP_Taxonomy::setObjectTerms($postId, $categories, 'category', false, $db);
}

/**
 * Set tags on a post (replaces existing; string names create tags).
 *
 * @param list<int|string>|int|string $tags
 *
 * @return list<int>
 */
function ap_set_post_tags(
    int $postId,
    array|int|string $tags,
    ?AP_DB $db = null
): array {
    return AP_Taxonomy::setObjectTerms($postId, $tags, 'post_tag', false, $db);
}

// -----------------------------------------------------------------------------
// Comments — nested threads, moderation, spam hooks
// -----------------------------------------------------------------------------

/**
 * Fetch a comment by ID.
 *
 * @see AP_Comment::get()
 */
function ap_get_comment(int $id, ?AP_DB $db = null): ?AP_Comment
{
    return AP_Comment::get($id, $db);
}

/**
 * Insert a comment. Returns new ID or 0 on failure.
 *
 * @param array<string, mixed> $data
 * @param array<string, mixed> $args
 *
 * @see AP_Comment::insert()
 */
function ap_insert_comment(array $data, ?AP_DB $db = null, array $args = []): int
{
    return AP_Comment::insert($data, $db, $args);
}

/**
 * Handle front-end comment form POST (ap_comment_action=ap_comment_post).
 *
 * Returns a redirect URL on success/handled error, or empty string when not a
 * comment form request.
 */
function ap_handle_comment_form_post(?AP_DB $db = null): string
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return '';
    }
    $action = (string) ($_POST['ap_comment_action'] ?? '');
    if ($action !== 'ap_comment_post') {
        return '';
    }

    $db = $db ?? (function_exists('ap_db') ? ap_db() : null);
    $postId = (int) ($_POST['comment_post_ID'] ?? $_POST['post_ID'] ?? 0);
    $redirectBase = '';
    if ($postId > 0 && function_exists('ap_get_permalink') && class_exists('AP_Post', false)) {
        $post = AP_Post::get($postId, $db);
        if ($post !== null) {
            $redirectBase = ap_get_permalink($post);
        }
    }
    if ($redirectBase === '' && function_exists('ap_home_url')) {
        $redirectBase = ap_home_url('/');
    }
    $fail = static function (string $code) use ($redirectBase): string {
        $sep = str_contains($redirectBase, '?') ? '&' : '?';

        return $redirectBase . $sep . 'comment_error=' . rawurlencode($code) . '#respond';
    };

    if ($postId < 1) {
        return $fail('invalid');
    }

    $userId = function_exists('ap_get_current_user_id') ? ap_get_current_user_id($db) : 0;
    $nonce = (string) ($_POST['_ap_nonce'] ?? '');
    $nonceAction = 'ap-comment-post-' . $postId;
    if (function_exists('ap_check_nonce') && !ap_check_nonce($nonce, $nonceAction, $userId > 0 ? $userId : null)) {
        return $fail('nonce');
    }

    // Registration required?
    $requireReg = false;
    if (class_exists('AP_Options', false)) {
        $requireReg = (string) AP_Options::get('comment_registration', '0', $db) === '1';
    }
    if ($requireReg && $userId < 1) {
        return $fail('login');
    }

    $content = trim((string) ($_POST['comment'] ?? $_POST['comment_content'] ?? ''));
    if ($content === '') {
        return $fail('empty');
    }

    $author = '';
    $email = '';
    $url = '';
    if ($userId > 0 && class_exists('AP_User', false)) {
        $user = AP_User::get($userId, $db);
        if ($user !== null) {
            $author = $user->display_name !== '' ? $user->display_name : $user->user_login;
            $email = (string) $user->user_email;
            $url = (string) ($user->user_url ?? '');
        }
    } else {
        $author = ap_sanitize_text_field((string) ($_POST['author'] ?? $_POST['comment_author'] ?? ''));
        $email = ap_sanitize_text_field((string) ($_POST['email'] ?? $_POST['comment_author_email'] ?? ''));
        $url = ap_sanitize_text_field((string) ($_POST['url'] ?? $_POST['comment_author_url'] ?? ''));
        $requireNameEmail = true;
        if (class_exists('AP_Options', false)) {
            $requireNameEmail = (string) AP_Options::get('require_name_email', '1', $db) === '1';
        }
        if ($requireNameEmail && ($author === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL))) {
            return $fail('identity');
        }
    }

    $parent = max(0, (int) ($_POST['comment_parent'] ?? 0));
    $ip = '';
    if (!empty($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR'])) {
        $ip = substr($_SERVER['REMOTE_ADDR'], 0, 100);
    }
    $agent = '';
    if (!empty($_SERVER['HTTP_USER_AGENT']) && is_string($_SERVER['HTTP_USER_AGENT'])) {
        $agent = substr($_SERVER['HTTP_USER_AGENT'], 0, 255);
    }

    $newId = ap_insert_comment([
        'comment_post_ID' => $postId,
        'comment_content' => $content,
        'comment_author' => $author,
        'comment_author_email' => $email,
        'comment_author_url' => $url,
        'comment_author_IP' => $ip,
        'comment_agent' => $agent,
        'comment_parent' => $parent,
        'user_id' => $userId,
    ], $db);

    if ($newId < 1) {
        return $fail('closed');
    }

    $sep = str_contains($redirectBase, '?') ? '&' : '?';

    return $redirectBase . $sep . 'comment_ok=1#comment-' . $newId;
}

/**
 * Update a comment.
 *
 * @param array<string, mixed> $data
 * @param array<string, mixed> $args
 *
 * @see AP_Comment::update()
 */
function ap_update_comment(int $id, array $data, ?AP_DB $db = null, array $args = []): bool
{
    return AP_Comment::update($id, $data, $db, $args);
}

/**
 * Soft-delete a comment (status = trash).
 *
 * @see AP_Comment::trash()
 */
function ap_trash_comment(int $id, ?AP_DB $db = null): bool
{
    return AP_Comment::trash($id, $db);
}

/**
 * Restore a trashed comment.
 *
 * @see AP_Comment::untrash()
 */
function ap_untrash_comment(int $id, ?AP_DB $db = null): bool
{
    return AP_Comment::untrash($id, $db);
}

/**
 * Delete a comment (trash unless $force; permanent when force or already trash).
 *
 * @see AP_Comment::delete()
 */
function ap_delete_comment(int $id, bool $force = false, ?AP_DB $db = null): bool
{
    return AP_Comment::delete($id, $force, $db);
}

/**
 * Approve a comment.
 *
 * @see AP_Comment::approve()
 */
function ap_approve_comment(int $id, ?AP_DB $db = null): bool
{
    return AP_Comment::approve($id, $db);
}

/**
 * Unapprove a comment (hold for moderation).
 *
 * @see AP_Comment::unapprove()
 */
function ap_unapprove_comment(int $id, ?AP_DB $db = null): bool
{
    return AP_Comment::unapprove($id, $db);
}

/**
 * Mark a comment as spam.
 *
 * @see AP_Comment::spam()
 */
function ap_spam_comment(int $id, ?AP_DB $db = null): bool
{
    return AP_Comment::spam($id, $db);
}

/**
 * Remove spam flag (back to pending).
 *
 * @see AP_Comment::unspam()
 */
function ap_unspam_comment(int $id, ?AP_DB $db = null): bool
{
    return AP_Comment::unspam($id, $db);
}

/**
 * Set comment moderation status.
 *
 * @see AP_Comment::setStatus()
 */
function ap_set_comment_status(int $id, string $status, ?AP_DB $db = null): bool
{
    return AP_Comment::setStatus($id, $status, $db);
}

/**
 * List comments with filters.
 *
 * @param array<string, mixed> $args
 *
 * @return list<AP_Comment>
 *
 * @see AP_Comment::query()
 */
function ap_get_comments(array $args = [], ?AP_DB $db = null): array
{
    return AP_Comment::query($args, $db);
}

/**
 * Count comments matching filters.
 *
 * @param array<string, mixed> $args
 *
 * @see AP_Comment::count()
 */
function ap_count_comments(array $args = [], ?AP_DB $db = null): int
{
    return AP_Comment::count($args, $db);
}

/**
 * Approved comments for a post (flat list).
 *
 * @param array<string, mixed> $args
 *
 * @return list<AP_Comment>
 *
 * @see AP_Comment::getByPost()
 */
function ap_get_post_comments(int $postId, array $args = [], ?AP_DB $db = null): array
{
    return AP_Comment::getByPost($postId, $args, $db);
}

/**
 * Nested comment tree for a post.
 *
 * @param array<string, mixed> $args
 *
 * @return list<array{comment: AP_Comment, children: list}>
 *
 * @see AP_Comment::getTree()
 */
function ap_get_comment_tree(int $postId, array $args = [], ?AP_DB $db = null): array
{
    return AP_Comment::getTree($postId, $args, $db);
}

/**
 * Recount approved comments on a post.
 *
 * @see AP_Comment::updateCommentCount()
 */
function ap_update_comment_count(int $postId, ?AP_DB $db = null): int
{
    return AP_Comment::updateCommentCount($postId, $db);
}

/**
 * Register a pluggable spam checker.
 *
 * @param callable(array<string, mixed>): (bool|string) $callback
 *
 * @see AP_Comment::registerSpamChecker()
 */
function ap_register_comment_spam_checker(callable $callback): void
{
    AP_Comment::registerSpamChecker($callback);
}

/**
 * @return string|list<string>|null
 *
 * @see AP_Comment::getMeta()
 */
function ap_get_comment_meta(
    int $commentId,
    string $key,
    bool $single = true,
    ?AP_DB $db = null
): string|array|null {
    return AP_Comment::getMeta($commentId, $key, $single, $db);
}

/**
 * @see AP_Comment::updateMeta()
 */
function ap_update_comment_meta(
    int $commentId,
    string $key,
    string $value,
    ?AP_DB $db = null
): bool {
    return AP_Comment::updateMeta($commentId, $key, $value, $db);
}

/**
 * @see AP_Comment::deleteMeta()
 */
function ap_delete_comment_meta(int $commentId, string $key, ?AP_DB $db = null): bool
{
    return AP_Comment::deleteMeta($commentId, $key, $db);
}

// -----------------------------------------------------------------------------
// Forums — hierarchy, topics, posts/replies
// -----------------------------------------------------------------------------

/**
 * Prefixed forum table map.
 *
 * @return array<string, string>
 *
 * @see AP_Forum::tables()
 */
function ap_forum_tables(?AP_DB $db = null): array
{
    return AP_Forum::tables($db);
}

/**
 * Fetch a forum by ID.
 *
 * @see AP_Forum::getForum()
 */
function ap_get_forum(int $id, ?AP_DB $db = null): ?object
{
    return AP_Forum::getForum($id, $db);
}

/**
 * Fetch a forum by slug.
 *
 * @see AP_Forum::getForumBySlug()
 */
function ap_get_forum_by_slug(string $slug, ?AP_DB $db = null): ?object
{
    return AP_Forum::getForumBySlug($slug, $db);
}

/**
 * Insert a forum or category. Returns new forum_id or 0.
 *
 * @param array<string, mixed> $data
 *
 * @see AP_Forum::insertForum()
 */
function ap_insert_forum(array $data, ?AP_DB $db = null): int
{
    return AP_Forum::insertForum($data, $db);
}

/**
 * Update a forum.
 *
 * @param array<string, mixed> $data
 *
 * @see AP_Forum::updateForum()
 */
function ap_update_forum(int $id, array $data, ?AP_DB $db = null): bool
{
    return AP_Forum::updateForum($id, $data, $db);
}

/**
 * Delete a forum (fails if non-empty unless force).
 *
 * @see AP_Forum::deleteForum()
 */
function ap_delete_forum(int $id, bool $force = false, ?AP_DB $db = null): bool
{
    return AP_Forum::deleteForum($id, $force, $db);
}

/**
 * Nested forum hierarchy.
 *
 * @param array<string, mixed> $args
 *
 * @return list<array{forum: object, children: list}>
 *
 * @see AP_Forum::getHierarchy()
 */
function ap_get_forum_hierarchy(int $parentId = 0, array $args = [], ?AP_DB $db = null): array
{
    return AP_Forum::getHierarchy($parentId, $args, $db);
}

/**
 * Child forums of a parent (or root when 0).
 *
 * @param array<string, mixed> $args
 *
 * @return list<object>
 *
 * @see AP_Forum::getChildForums()
 */
function ap_get_child_forums(int $parentId = 0, array $args = [], ?AP_DB $db = null): array
{
    return AP_Forum::getChildForums($parentId, $args, $db);
}

/**
 * Forum index display data for templates.
 *
 * @param array<string, mixed> $args Optional: user_id for unread annotation
 *
 * @return list<array{name: string, forums: list<array<string, mixed>>}>
 *
 * @see AP_Forum::getIndexData()
 */
function ap_get_forum_index_data(?AP_DB $db = null, array $args = []): array
{
    return AP_Forum::getIndexData($db, $args);
}

/**
 * Board-level aggregates for the forum footer (board index).
 *
 * Count definitions (SPEC §C — keep UI and denormalized counters aligned):
 * - **topics**: approved topics with status ≠ deleted (board-wide).
 * - **posts**: approved forum_posts (**opening posts + replies**) under approved,
 *   non-deleted topics. **Not “replies only”.** Same meaning as forum-row
 *   `post_count` and topic-row `posts` (`reply_count + 1`). See
 *   {@see AP_Forum} class docblock (“Post-count definition”).
 * - **members**: registered users (all rows in `users`; guests not counted).
 *
 * Live SQL COUNTs only (no transient / object-cache lag). Local DB; no telemetry.
 *
 * @return array{topics: int, posts: int, members: int}
 *
 * @see AP_Forum_Stats::getBoardStats()
 */
function ap_get_forum_board_stats(?AP_DB $db = null): array
{
    if (class_exists('AP_Forum_Stats', false)) {
        return AP_Forum_Stats::getBoardStats($db);
    }

    return ['topics' => 0, 'posts' => 0, 'members' => 0];
}

/**
 * Fetch a topic by ID.
 *
 * @see AP_Forum::getTopic()
 */
function ap_get_topic(int $id, ?AP_DB $db = null): ?object
{
    return AP_Forum::getTopic($id, $db);
}

/**
 * Create a topic with its first post. Returns topic_id or 0.
 *
 * @param array<string, mixed> $data
 * @param array<string, mixed> $args
 *
 * @see AP_Forum::createTopic()
 */
function ap_create_topic(array $data, ?AP_DB $db = null, array $args = []): int
{
    return AP_Forum::createTopic($data, $db, $args);
}

/**
 * Update topic metadata.
 *
 * @param array<string, mixed> $data
 *
 * @see AP_Forum::updateTopic()
 */
function ap_update_topic(int $id, array $data, ?AP_DB $db = null): bool
{
    return AP_Forum::updateTopic($id, $data, $db);
}

/**
 * Soft-delete or force-delete a topic.
 *
 * @see AP_Forum::deleteTopic()
 */
function ap_delete_topic(int $id, bool $force = false, ?AP_DB $db = null): bool
{
    return AP_Forum::deleteTopic($id, $force, $db);
}

/**
 * Topics in a forum (sticky/announce first).
 *
 * @param array<string, mixed> $args
 *
 * @return list<object>
 *
 * @see AP_Forum::getTopics()
 */
function ap_get_topics(int $forumId, array $args = [], ?AP_DB $db = null): array
{
    return AP_Forum::getTopics($forumId, $args, $db);
}

/**
 * Theme-friendly topic list for a forum.
 *
 * @param array<string, mixed> $args
 *
 * @return list<array<string, mixed>>
 *
 * @see AP_Forum::getTopicsDisplayData()
 */
function ap_get_forum_topics_data(int $forumId, array $args = [], ?AP_DB $db = null): array
{
    return AP_Forum::getTopicsDisplayData($forumId, $args, $db);
}

/**
 * Fetch a forum post by ID.
 *
 * @see AP_Forum::getPost()
 */
function ap_get_forum_post(int $id, ?AP_DB $db = null): ?object
{
    return AP_Forum::getPost($id, $db);
}

/**
 * Reply to a topic. Returns post_id or 0.
 *
 * @param array<string, mixed> $data
 * @param array<string, mixed> $args
 *
 * @see AP_Forum::createReply()
 */
function ap_create_forum_reply(array $data, ?AP_DB $db = null, array $args = []): int
{
    return AP_Forum::createReply($data, $db, $args);
}

/**
 * Update a forum post.
 *
 * @param array<string, mixed> $data
 *
 * @see AP_Forum::updatePost()
 */
function ap_update_forum_post(int $id, array $data, ?AP_DB $db = null): bool
{
    return AP_Forum::updatePost($id, $data, $db);
}

/**
 * Delete a forum post (first post requires force and deletes the topic).
 *
 * @see AP_Forum::deletePost()
 */
function ap_delete_forum_post(int $id, bool $force = false, ?AP_DB $db = null): bool
{
    return AP_Forum::deletePost($id, $force, $db);
}

/**
 * Posts in a topic.
 *
 * @param array<string, mixed> $args
 *
 * @return list<object>
 *
 * @see AP_Forum::getPosts()
 */
function ap_get_forum_posts(int $topicId, array $args = [], ?AP_DB $db = null): array
{
    return AP_Forum::getPosts($topicId, $args, $db);
}

/**
 * Theme-friendly post list for a topic.
 *
 * @param array<string, mixed> $args
 *
 * @return list<array<string, mixed>>
 *
 * @see AP_Forum::getPostsDisplayData()
 */
function ap_get_topic_posts_data(int $topicId, array $args = [], ?AP_DB $db = null): array
{
    return AP_Forum::getPostsDisplayData($topicId, $args, $db);
}

/**
 * Build BBCode quote markup for a reply citation (SPEC B2).
 *
 * @see AP_Forum::buildQuoteMarkup()
 */
function ap_forum_build_quote_markup(string $author, string $content, int $maxLen = 2000): string
{
    if (class_exists('AP_Forum', false)) {
        return AP_Forum::buildQuoteMarkup($author, $content, $maxLen);
    }
    $author = trim(str_replace(["\r", "\n", "\0", '[', ']', '"', "'"], '', $author));
    if ($author === '') {
        $author = 'Guest';
    }
    $content = trim(str_replace("\0", '', $content));

    return '[quote=' . $author . ']' . $content . "[/quote]\n\n";
}

/**
 * Quote markup for an approved forum post, or empty when unavailable.
 *
 * @see AP_Forum::getQuoteMarkupForPost()
 */
function ap_forum_quote_for_post(int $postId, ?AP_DB $db = null): string
{
    if (class_exists('AP_Forum', false)) {
        return AP_Forum::getQuoteMarkupForPost($postId, $db);
    }

    return '';
}

/**
 * Increment topic view counter.
 *
 * @see AP_Forum::incrementTopicViews()
 */
function ap_increment_topic_views(int $topicId, ?AP_DB $db = null): bool
{
    return AP_Forum::incrementTopicViews($topicId, $db);
}

/**
 * Forum index public URL.
 *
 * @see AP_Forum::forumsIndexUrl()
 */
function ap_forums_url(): string
{
    if (class_exists('AP_Forum', false)) {
        return AP_Forum::forumsIndexUrl();
    }
    if (function_exists('ap_home_url')) {
        return ap_home_url('/forums/');
    }

    return '/forums/';
}

/**
 * Public URL for a forum.
 *
 * @see AP_Forum::forumUrl()
 */
function ap_forum_url(object|int $forum): string
{
    if (class_exists('AP_Forum', false)) {
        return AP_Forum::forumUrl($forum);
    }
    $id = is_object($forum) ? (int) ($forum->forum_id ?? 0) : (int) $forum;

    return ap_forums_url() . ($id > 0 ? '?forum_id=' . $id : '');
}

/**
 * Public URL for a topic.
 *
 * @see AP_Forum::topicUrl()
 */
function ap_topic_url(object|int $topic): string
{
    if (class_exists('AP_Forum', false)) {
        return AP_Forum::topicUrl($topic);
    }
    $id = is_object($topic) ? (int) ($topic->topic_id ?? 0) : (int) $topic;
    if (function_exists('ap_home_url')) {
        return $id > 0 ? ap_home_url('/?topic_id=' . $id) : ap_home_url('/');
    }

    return $id > 0 ? '/?topic_id=' . $id : '/';
}

/**
 * Permalink to a forum post (topic URL + #post-{id}).
 *
 * @see AP_Forum::postUrl()
 */
function ap_forum_post_url(object|int $topic, int $postId): string
{
    if (class_exists('AP_Forum', false)) {
        return AP_Forum::postUrl($topic, $postId);
    }
    $base = ap_topic_url($topic);

    return $postId > 0 ? $base . '#post-' . $postId : $base;
}

/**
 * Board-index forum row icon key: standard | locked | link.
 *
 * @see AP_Forum::forumIconType()
 */
function ap_forum_icon_type(object|string $forumOrType, ?string $status = null): string
{
    if (class_exists('AP_Forum', false)) {
        return AP_Forum::forumIconType($forumOrType, $status);
    }

    return 'standard';
}

/**
 * Topic-list icon key: standard | sticky | announcement | rules | locked.
 *
 * @see AP_Forum::topicIconType()
 */
function ap_topic_icon_type(object|string $topicOrType, ?string $status = null): string
{
    if (class_exists('AP_Forum', false)) {
        return AP_Forum::topicIconType($topicOrType, $status);
    }

    return 'standard';
}

/**
 * Allowed board/topic row icon type keys (SPEC A2/A4).
 *
 * @return list<string>
 */
function ap_forum_row_icon_types(): array
{
    return ['standard', 'sticky', 'announcement', 'rules', 'locked', 'link'];
}

/**
 * Normalize a forum/topic icon_type for markup/CSS hooks.
 */
function ap_forum_normalize_icon_type(string $iconType): string
{
    $type = strtolower(trim($iconType));
    $allowed = ap_forum_row_icon_types();

    return in_array($type, $allowed, true) ? $type : 'standard';
}

/**
 * Human label for a row icon type (screen readers / titles).
 */
function ap_forum_icon_type_label(string $iconType): string
{
    return match (ap_forum_normalize_icon_type($iconType)) {
        'sticky' => 'Sticky',
        'announcement' => 'Announcement',
        'rules' => 'Rules',
        'locked' => 'Locked',
        'link' => 'Link forum',
        default => 'Standard',
    };
}

/**
 * Human label for a topic type (standard | sticky | announcement | rules).
 *
 * @see AP_Forum::topicTypeLabel()
 */
function ap_forum_topic_type_label(string $type): string
{
    if (class_exists('AP_Forum', false)) {
        return AP_Forum::topicTypeLabel($type);
    }

    return match (strtolower(trim($type))) {
        'sticky' => 'Sticky',
        'announcement', 'announce', 'global' => 'Announcement',
        'rules', 'info' => 'Rules',
        default => 'Standard',
    };
}

/**
 * Topic types the current (or given) user may choose when creating a topic.
 *
 * @return list<string>
 *
 * @see AP_Forum_Permissions::allowedTopicTypesForCreate()
 */
function ap_forum_allowed_topic_types_for_create(
    int $forumId,
    ?int $userId = null,
    ?AP_DB $db = null
): array {
    if ($forumId < 1 || !class_exists('AP_Forum_Permissions', false)) {
        return [];
    }
    if ($userId === null) {
        $userId = function_exists('ap_get_current_user_id')
            ? (int) ap_get_current_user_id($db)
            : 0;
    }

    return AP_Forum_Permissions::allowedTopicTypesForCreate($userId, $forumId, $db);
}

/**
 * Topic types the current (or given) user may set when editing a topic.
 *
 * @return list<string>
 *
 * @see AP_Forum_Permissions::allowedTopicTypesForEdit()
 */
function ap_forum_allowed_topic_types_for_edit(
    int $forumId,
    ?string $currentType = null,
    ?int $userId = null,
    ?AP_DB $db = null
): array {
    if ($forumId < 1 || !class_exists('AP_Forum_Permissions', false)) {
        return [];
    }
    if ($userId === null) {
        $userId = function_exists('ap_get_current_user_id')
            ? (int) ap_get_current_user_id($db)
            : 0;
    }

    return AP_Forum_Permissions::allowedTopicTypesForEdit($userId, $forumId, $currentType, $db);
}

/**
 * Render a topic-type &lt;select&gt; for create/edit forms (SPEC A2).
 *
 * Returns empty string when $types is empty or only one option and
 * `$args['hide_single']` is true (default for create forms with only standard).
 *
 * @param list<string>         $types  Allowed type keys
 * @param array<string, mixed> $args   id, name, selected, label, class, hide_single, required
 */
function ap_forum_topic_type_select_html(array $types, array $args = []): string
{
    $types = array_values(array_unique(array_filter(array_map(
        static function ($t): string {
            $t = is_string($t) ? strtolower(trim($t)) : '';
            if (class_exists('AP_Forum', false)) {
                return AP_Forum::normalizeTopicType($t);
            }

            return $t;
        },
        $types
    ))));

    if ($types === []) {
        return '';
    }

    $hideSingle = !array_key_exists('hide_single', $args) || !empty($args['hide_single']);
    if ($hideSingle && count($types) === 1 && $types[0] === 'standard') {
        return '';
    }

    $id = (string) ($args['id'] ?? 'ap-topic-type');
    $name = (string) ($args['name'] ?? 'topic_type');
    $selected = (string) ($args['selected'] ?? $types[0]);
    if (class_exists('AP_Forum', false)) {
        $selected = AP_Forum::normalizeTopicType($selected);
    }
    if (!in_array($selected, $types, true)) {
        $selected = $types[0];
    }
    $label = (string) ($args['label'] ?? 'Topic type');
    $class = trim((string) ($args['class'] ?? 'ap-field ap-field--topic-type'));
    $required = !empty($args['required']);

    $esc = static function (string $s): string {
        if (function_exists('agora_esc')) {
            return agora_esc($s);
        }
        if (function_exists('ap_esc_html')) {
            return ap_esc_html($s);
        }

        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    };
    $escAttr = static function (string $s): string {
        if (function_exists('agora_esc_attr')) {
            return agora_esc_attr($s);
        }
        if (function_exists('ap_esc_attr')) {
            return ap_esc_attr($s);
        }

        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    };

    $html = '<div class="' . $escAttr($class) . '">';
    $html .= '<label for="' . $escAttr($id) . '">' . $esc($label) . '</label>';
    $html .= '<select id="' . $escAttr($id) . '" name="' . $escAttr($name) . '"'
        . ($required ? ' required' : '') . '>';
    foreach ($types as $type) {
        $html .= '<option value="' . $escAttr($type) . '"'
            . ($type === $selected ? ' selected' : '') . '>'
            . $esc(ap_forum_topic_type_label($type))
            . '</option>';
    }
    $html .= '</select></div>';

    return $html;
}

/**
 * Read/unread visual state for a board or topic row (SPEC A1).
 *
 * Returns one of:
 * - `unread` — logged-in user has visible unread content
 * - `read`   — logged-in user; fully read (or tracking known empty)
 * - `neutral` — guest / tracking unavailable: no claim about personal state
 *
 * Guests intentionally use neutral (not “read”) so themes do not imply a
 * personal read marker. Prefer pairing with {@see ap_forum_row_classes()}.
 *
 * @param array{
 *   is_unread?: bool,
 *   unread?: bool,
 *   tracking?: bool,
 *   user_id?: int
 * } $args
 */
function ap_forum_row_read_state(array $args = []): string
{
    $isUnread = !empty($args['is_unread']) || !empty($args['unread']);

    // Explicit unread from the data layer always wins (never lie about unread).
    if ($isUnread) {
        return 'unread';
    }

    // Explicit tracking flag wins when provided; otherwise infer from viewer.
    if (array_key_exists('tracking', $args)) {
        $tracking = (bool) $args['tracking'];
    } else {
        $userId = array_key_exists('user_id', $args)
            ? (int) $args['user_id']
            : (function_exists('ap_get_current_user_id') ? (int) ap_get_current_user_id() : 0);
        $tracking = $userId > 0;
        if ($tracking && class_exists('AP_Forum_Read', false) && method_exists('AP_Forum_Read', 'isAvailable')) {
            $tracking = AP_Forum_Read::isAvailable();
        }
    }

    // Guests / tracking off: neutral (not “read”) — SPEC guest policy.
    if (!$tracking) {
        return 'neutral';
    }

    return 'read';
}

/**
 * Stable CSS classes for a forum/topic list row (SPEC A1 / A4).
 *
 * Always includes `ap-forum-row`. Adds:
 * - `ap-forum-row--unread` | `ap-forum-row--read` | `ap-forum-row--neutral`
 * - `ap-forum-list__item` (and `--unread` when unread) for legacy hooks
 * - `ap-forum-row--topic` / `ap-forum-list__item--topic` when topic list
 * - `ap-forum-row--locked` when forum closed or topic locked
 * - `ap-forum-row--empty` when forum has no topics/posts
 * - optional `ap-forum-row--icon-{type}` when icon_type is set
 *
 * @param array{
 *   is_unread?: bool,
 *   unread?: bool,
 *   tracking?: bool,
 *   user_id?: int,
 *   topic?: bool,
 *   locked?: bool,
 *   is_locked?: bool,
 *   is_closed?: bool,
 *   empty?: bool,
 *   is_empty?: bool,
 *   icon_type?: string,
 *   class?: string|list<string>
 * } $args
 */
function ap_forum_row_classes(array $args = []): string
{
    $state = ap_forum_row_read_state($args);
    $isTopic = !empty($args['topic']);
    $isLocked = !empty($args['locked'])
        || !empty($args['is_locked'])
        || !empty($args['is_closed']);
    $isEmpty = !empty($args['empty']) || !empty($args['is_empty']);

    $classes = ['ap-forum-list__item', 'ap-forum-row'];
    if ($isTopic) {
        $classes[] = 'ap-forum-list__item--topic';
        $classes[] = 'ap-forum-row--topic';
    }

    $classes[] = 'ap-forum-row--' . $state;
    if ($state === 'unread') {
        $classes[] = 'ap-forum-list__item--unread';
    }

    if ($isLocked) {
        $classes[] = 'ap-forum-row--locked';
    }
    if ($isEmpty) {
        $classes[] = 'ap-forum-row--empty';
    }

    if (!empty($args['icon_type']) && is_string($args['icon_type'])) {
        $icon = ap_forum_normalize_icon_type($args['icon_type']);
        $classes[] = 'ap-forum-row--icon-' . $icon;
    }

    if (!empty($args['class'])) {
        $extra = $args['class'];
        if (is_array($extra)) {
            foreach ($extra as $c) {
                if (is_string($c) && trim($c) !== '') {
                    $classes[] = trim($c);
                }
            }
        } elseif (is_string($extra) && trim($extra) !== '') {
            foreach (preg_split('/\s+/', trim($extra)) ?: [] as $c) {
                if ($c !== '') {
                    $classes[] = $c;
                }
            }
        }
    }

    // De-dupe while preserving order.
    $seen = [];
    $out = [];
    foreach ($classes as $c) {
        if (!isset($seen[$c])) {
            $seen[$c] = true;
            $out[] = $c;
        }
    }

    return implode(' ', $out);
}

/**
 * Whether a forum (or status string) is closed (no new topics).
 *
 * @see AP_Forum::isForumClosed()
 */
function ap_is_forum_closed(object|string $forumOrStatus): bool
{
    if (class_exists('AP_Forum', false)) {
        return AP_Forum::isForumClosed($forumOrStatus);
    }

    $status = is_object($forumOrStatus)
        ? (string) ($forumOrStatus->forum_status ?? '')
        : (string) $forumOrStatus;

    return strtolower(trim($status)) === 'closed';
}

/**
 * Three-line empty last-post column placeholders (SPEC A4: “No posts”, “—”).
 *
 * Use when a forum row has no last_post payload.
 */
function ap_forum_empty_last_post_html(): string
{
    return '<span class="ap-forum-last-post__title ap-forum-list__empty">No posts</span>'
        . '<span class="ap-forum-last-post__author ap-forum-list__empty" aria-hidden="true">—</span>'
        . '<span class="ap-forum-last-post__time ap-forum-list__empty" aria-hidden="true">—</span>';
}

/**
 * Forum board footer statistics markup (SPEC §C — not the site footer).
 *
 * Renders: “Total Topics: N · Total Posts: N · Total Members: N”
 *
 * Post count = approved opening posts + replies under visible topics (see
 * {@see ap_get_forum_board_stats()} / {@see AP_Forum_Stats} class docblock).
 * Counts are live DB aggregates with no cache lag.
 *
 * Stable classes for themes:
 * - `.ap-forum-footer` / `.ap-board-stats` (wrapper)
 * - `.ap-board-stats__item` / `__label` / `__value`
 * - `.ap-board-stats__sep` (middle-dot separators)
 *
 * @param array{
 *   topics?: int,
 *   posts?: int,
 *   members?: int,
 *   class?: string,
 *   stats?: array{topics?: int, posts?: int, members?: int}
 * } $args Precomputed stats optional; otherwise loads via {@see ap_get_forum_board_stats()}.
 * @param AP_DB|null $db
 */
function ap_forum_board_stats_footer_html(array $args = [], ?AP_DB $db = null): string
{
    $stats = null;
    if (isset($args['stats']) && is_array($args['stats'])) {
        $stats = $args['stats'];
    } elseif (isset($args['topics']) || isset($args['posts']) || isset($args['members'])) {
        $stats = $args;
    }
    if (!is_array($stats)) {
        $stats = ap_get_forum_board_stats($db);
    }

    $topics = max(0, (int) ($stats['topics'] ?? 0));
    $posts = max(0, (int) ($stats['posts'] ?? 0));
    $members = max(0, (int) ($stats['members'] ?? 0));

    $classes = ['ap-forum-footer', 'ap-board-stats'];
    if (!empty($args['class']) && is_string($args['class'])) {
        $extra = trim($args['class']);
        if ($extra !== '') {
            $classes[] = $extra;
        }
    }

    $esc = static function (string $s): string {
        return function_exists('ap_esc_html') ? ap_esc_html($s) : htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    };
    $escAttr = static function (string $s): string {
        return function_exists('ap_esc_attr') ? ap_esc_attr($s) : htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    };

    $item = static function (string $key, string $label, int $value) use ($esc, $escAttr): string {
        return '<span class="ap-board-stats__item ap-board-stats__item--' . $escAttr($key) . '">'
            . '<span class="ap-board-stats__label">' . $esc($label) . '</span>'
            . '<span class="ap-board-stats__value" data-stat="' . $escAttr($key) . '">'
            . $esc((string) $value)
            . '</span>'
            . '</span>';
    };

    $sep = '<span class="ap-board-stats__sep" aria-hidden="true"> · </span>';

    // Labels include trailing colon so themes can restyle label vs value independently.
    $html = '<footer class="' . $escAttr(implode(' ', $classes)) . '"'
        . ' role="contentinfo" aria-label="Board statistics">'
        . $item('topics', 'Total Topics:', $topics)
        . $sep
        . $item('posts', 'Total Posts:', $posts)
        . $sep
        . $item('members', 'Total Members:', $members)
        . '</footer>';

    if (function_exists('ap_apply_filters')) {
        $filtered = ap_apply_filters('ap_forum_board_stats_footer_html', $html, [
            'topics' => $topics,
            'posts' => $posts,
            'members' => $members,
        ]);
        if (is_string($filtered)) {
            return $filtered;
        }
    }

    return $html;
}

/**
 * Forum empty-state / locked affordance markup (stable hooks for themes).
 *
 * Kind keys (board index + forum/topic views):
 * - board_empty, category_empty
 * - forum_empty, forum_empty_closed, forum_closed
 * - forum_disabled, forum_not_found
 * - topic_empty, topic_locked
 *
 * Classes: `ap-empty ap-forum-empty ap-forum-empty--{kind}` (+ optional extras).
 *
 * @param array{
 *   class?: string,
 *   can_post?: bool,
 *   cta_url?: string,
 *   cta_label?: string,
 *   back_url?: string,
 *   back_label?: string
 * } $args
 */
function ap_forum_empty_state_html(string $kind, array $args = []): string
{
    $kind = strtolower(trim($kind));
    $allowed = [
        'board_empty',
        'category_empty',
        'forum_empty',
        'forum_empty_closed',
        'forum_closed',
        'forum_disabled',
        'forum_not_found',
        'topic_empty',
        'topic_locked',
    ];
    if (!in_array($kind, $allowed, true)) {
        $kind = 'board_empty';
    }

    $esc = static function (string $s): string {
        if (function_exists('ap_esc_html')) {
            return ap_esc_html($s);
        }
        if (function_exists('agora_esc')) {
            return agora_esc($s);
        }

        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    };
    $escAttr = static function (string $s): string {
        if (function_exists('ap_esc_attr')) {
            return ap_esc_attr($s);
        }
        if (function_exists('agora_esc_attr')) {
            return agora_esc_attr($s);
        }

        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    };
    $escUrl = static function (string $s): string {
        if (function_exists('ap_esc_url')) {
            return ap_esc_url($s);
        }
        if (function_exists('agora_esc_url')) {
            return agora_esc_url($s);
        }

        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    };

    $canPost = !empty($args['can_post']);
    $ctaUrl = isset($args['cta_url']) && is_string($args['cta_url']) ? $args['cta_url'] : '';
    $ctaLabel = isset($args['cta_label']) && is_string($args['cta_label']) && $args['cta_label'] !== ''
        ? $args['cta_label']
        : 'Start the first topic';
    $backUrl = isset($args['back_url']) && is_string($args['back_url']) ? $args['back_url'] : '';
    $backLabel = isset($args['back_label']) && is_string($args['back_label']) && $args['back_label'] !== ''
        ? $args['back_label']
        : 'Back to forums';

    $primary = match ($kind) {
        'board_empty' => 'No forums have been created yet.',
        'category_empty' => 'No forums in this category.',
        'forum_empty' => 'No topics yet in this forum.',
        'forum_empty_closed' => 'This forum is closed and has no topics yet.',
        'forum_closed' => 'This forum is closed. New topics are not accepted.',
        'forum_disabled' => 'The forum module is currently disabled.',
        'forum_not_found' => 'Forum not found.',
        'topic_empty' => 'No posts in this topic yet.',
        'topic_locked' => 'This topic is locked. New replies are not accepted.',
        default => 'Nothing to show here yet.',
    };

    $secondary = match ($kind) {
        'board_empty' => 'When an administrator adds categories and forums, they will appear here.',
        'forum_empty' => $canPost
            ? ''
            : 'Be the first to start a conversation.',
        default => '',
    };

    // Open empty forum with create permission: CTA instead of passive secondary.
    if ($kind === 'forum_empty' && $canPost && $ctaUrl === '') {
        $ctaUrl = '#new-topic';
    }

    $extraClass = isset($args['class']) && is_string($args['class']) ? trim($args['class']) : '';
    $classes = ['ap-empty', 'ap-forum-empty', 'ap-forum-empty--' . $kind];
    if ($extraClass !== '') {
        $classes[] = $extraClass;
    }

    $html = '<div class="' . $escAttr(implode(' ', $classes)) . '" role="status">';
    $html .= '<p>' . $esc($primary) . '</p>';
    if ($secondary !== '') {
        $html .= '<p>' . $esc($secondary) . '</p>';
    }
    if ($ctaUrl !== '' && in_array($kind, ['forum_empty'], true)) {
        $html .= '<p class="ap-forum-empty__cta"><a class="ap-btn" href="'
            . $escUrl($ctaUrl) . '">' . $esc($ctaLabel) . '</a></p>';
    }
    if ($backUrl !== '' && $kind === 'forum_not_found') {
        $html .= '<p class="ap-forum-empty__back"><a href="'
            . $escUrl($backUrl) . '">' . $esc($backLabel) . '</a></p>';
    }
    $html .= '</div>';

    return $html;
}

/**
 * Markup for board-index / topic-list row icon (SPEC A2 / A4 col 1).
 *
 * Visual glyph is CSS-driven (image-free). Classes:
 * - ap-forum-row__icon (column cell)
 * - ap-forum-icon ap-forum-icon--{type}
 * - ap-forum-icon--unread | ap-forum-icon--read | (none when neutral)
 *
 * Read vs unread variants: themes style `--unread` (accent / filled) vs
 * `--read` (muted) vs base/neutral (no personal-state claim for guests).
 *
 * @param array{
 *   unread?: bool,
 *   is_unread?: bool,
 *   tracking?: bool,
 *   user_id?: int,
 *   read_state?: string,
 *   class?: string,
 *   label?: string
 * } $args
 */
function ap_forum_row_icon_html(string $iconType, array $args = []): string
{
    $type = ap_forum_normalize_icon_type($iconType);

    if (!empty($args['read_state']) && is_string($args['read_state'])) {
        $state = strtolower(trim($args['read_state']));
        if (!in_array($state, ['unread', 'read', 'neutral'], true)) {
            $state = ap_forum_row_read_state($args);
        }
    } else {
        $state = ap_forum_row_read_state($args);
    }

    $label = isset($args['label']) && is_string($args['label']) && $args['label'] !== ''
        ? (string) $args['label']
        : ap_forum_icon_type_label($type) . match ($state) {
            'unread' => ' (unread)',
            'read' => ' (read)',
            default => '',
        };

    $classes = ['ap-forum-icon', 'ap-forum-icon--' . $type];
    if ($state === 'unread') {
        $classes[] = 'ap-forum-icon--unread';
    } elseif ($state === 'read') {
        $classes[] = 'ap-forum-icon--read';
    }
    if (!empty($args['class']) && is_string($args['class'])) {
        $extra = trim($args['class']);
        if ($extra !== '') {
            $classes[] = $extra;
        }
    }

    $classAttr = ap_esc_attr(implode(' ', $classes));
    $labelEsc = ap_esc_html($label);

    return '<span class="ap-forum-row__icon ap-forum-list__icon">'
        . '<span class="' . $classAttr . '" title="' . ap_esc_attr($label) . '" aria-hidden="true"></span>'
        . '<span class="screen-reader-text">' . $labelEsc . '</span>'
        . '</span>';
}

/**
 * Theme-friendly board-index forum row (icon, unread, counts, last_post).
 *
 * @param array<string, mixed> $preload Optional batch maps for last topic/authors
 *
 * @return array<string, mixed>
 *
 * @see AP_Forum::forumToDisplayRow()
 */
function ap_forum_to_display_row(object $forum, ?AP_DB $db = null, array $preload = []): array
{
    if (class_exists('AP_Forum', false)) {
        return AP_Forum::forumToDisplayRow($forum, $db, $preload);
    }

    $topics = (int) ($forum->topic_count ?? 0);
    $posts = (int) ($forum->post_count ?? 0);
    $status = (string) ($forum->forum_status ?? 'open');
    $isClosed = strtolower(trim($status)) === 'closed';

    return [
        'id' => (int) ($forum->forum_id ?? 0),
        'name' => (string) ($forum->forum_name ?? ''),
        'slug' => (string) ($forum->forum_slug ?? ''),
        'description' => (string) ($forum->forum_desc ?? ''),
        'url' => '',
        'topics' => $topics,
        'topic_count' => $topics,
        'posts' => $posts,
        'post_count' => $posts,
        'type' => (string) ($forum->forum_type ?? 'forum'),
        'status' => $status,
        'icon_type' => $isClosed ? 'locked' : 'standard',
        'last_post' => null,
        'is_closed' => $isClosed,
        'is_locked' => $isClosed,
        'is_empty' => $topics === 0 && $posts === 0,
        'is_unread' => false,
    ];
}

/**
 * Whether the current request is a forum front-end view.
 *
 * @see AP_Forum_Front::isForumRequest()
 */
function ap_is_forum_request(?AP_Query $query = null): bool
{
    return class_exists('AP_Forum_Front', false) && AP_Forum_Front::isForumRequest($query);
}

/**
 * Handle forum front-end POST actions (create topic / reply).
 *
 * @param array<string, mixed>|null $post
 *
 * @return string|null Redirect URL or null
 *
 * @see AP_Forum_Front::handlePost()
 */
function ap_handle_forum_front_post(?array $post = null, ?AP_DB $db = null): ?string
{
    if (!class_exists('AP_Forum_Front', false)) {
        return null;
    }

    return AP_Forum_Front::handlePost($post, $db);
}

/**
 * Flash / query-string notice for forum templates.
 *
 * @return array{type: string, message: string}|null
 *
 * @see AP_Forum_Front::getNotice()
 */
function ap_get_forum_notice(): ?array
{
    if (!class_exists('AP_Forum_Front', false)) {
        return null;
    }

    return AP_Forum_Front::getNotice();
}

/**
 * Search topics and posts.
 *
 * @param array<string, mixed> $args
 *
 * @return array{query: string, total: int, topics: list<object>, posts: list<object>, results: list<array<string, mixed>>}
 *
 * @see AP_Forum::search()
 */
function ap_forum_search(string $query, array $args = [], ?AP_DB $db = null): array
{
    if (!class_exists('AP_Forum', false)) {
        return [
            'query' => trim($query),
            'total' => 0,
            'topics' => [],
            'posts' => [],
            'results' => [],
        ];
    }

    return AP_Forum::search($query, $args, $db);
}

/**
 * Forum search results URL.
 *
 * @see AP_Forum::searchUrl()
 */
function ap_forum_search_url(string $query = ''): string
{
    if (class_exists('AP_Forum', false)) {
        return AP_Forum::searchUrl($query);
    }
    $base = function_exists('ap_forums_url') ? ap_forums_url() : '/forums/';
    $q = trim($query);
    if ($q === '') {
        return rtrim($base, '/') . (str_contains($base, '?') ? '&' : '/') . (str_contains($base, '?') ? 'ap_forum_view=search' : 'search/');
    }

    return function_exists('ap_home_url')
        ? ap_home_url('/forums/search/' . rawurlencode($q) . '/')
        : '/forums/search/' . rawurlencode($q) . '/';
}

/**
 * Whether the poster is currently flood-limited.
 *
 * @see AP_Forum_Guard::isFlooding()
 */
function ap_forum_is_flooding(int $userId = 0, string $ip = '', ?AP_DB $db = null): bool
{
    return class_exists('AP_Forum_Guard', false) && AP_Forum_Guard::isFlooding($userId, $ip, $db);
}

/**
 * Seconds until the user may post again (0 = allowed now).
 *
 * @see AP_Forum_Guard::secondsUntilAllowed()
 */
function ap_forum_flood_retry_after(int $userId = 0, string $ip = '', ?AP_DB $db = null): int
{
    return class_exists('AP_Forum_Guard', false)
        ? AP_Forum_Guard::secondsUntilAllowed($userId, $ip, $db)
        : 0;
}

/**
 * Evaluate flood / spam / approval for a prospective post.
 *
 * @param array<string, mixed> $data
 *
 * @return array<string, mixed>
 *
 * @see AP_Forum_Guard::evaluate()
 */
function ap_forum_guard_evaluate(array $data, ?AP_DB $db = null): array
{
    if (!class_exists('AP_Forum_Guard', false)) {
        return [
            'allowed' => true,
            'approved' => 1,
            'status' => 'approve',
            'code' => 'ok',
            'message' => '',
            'retry_after' => 0,
        ];
    }

    return AP_Forum_Guard::evaluate($data, $db);
}

/**
 * Register a pluggable forum spam checker.
 *
 * @param callable(array<string, mixed>): (bool|string|null) $callback
 *
 * @see AP_Forum_Guard::registerSpamChecker()
 */
function ap_register_forum_spam_checker(callable $callback): void
{
    if (class_exists('AP_Forum_Guard', false)) {
        AP_Forum_Guard::registerSpamChecker($callback);
    }
}

/**
 * Pending topics queue.
 *
 * @param array<string, mixed> $args
 *
 * @return list<object>
 *
 * @see AP_Forum::getPendingTopics()
 */
function ap_get_pending_topics(array $args = [], ?AP_DB $db = null): array
{
    return class_exists('AP_Forum', false) ? AP_Forum::getPendingTopics($args, $db) : [];
}

/**
 * Pending posts queue (replies).
 *
 * @param array<string, mixed> $args
 *
 * @return list<object>
 *
 * @see AP_Forum::getPendingPosts()
 */
function ap_get_pending_forum_posts(array $args = [], ?AP_DB $db = null): array
{
    return class_exists('AP_Forum', false) ? AP_Forum::getPendingPosts($args, $db) : [];
}

/**
 * Approve a pending topic.
 *
 * @see AP_Forum_Moderation::approveTopic()
 */
function ap_approve_topic(int $topicId, int $moderatorId = 0, ?AP_DB $db = null): bool
{
    return class_exists('AP_Forum_Moderation', false)
        && AP_Forum_Moderation::approveTopic($topicId, $moderatorId, $db);
}

/**
 * Hold a topic for moderation.
 *
 * @see AP_Forum_Moderation::unapproveTopic()
 */
function ap_unapprove_topic(int $topicId, int $moderatorId = 0, ?AP_DB $db = null): bool
{
    return class_exists('AP_Forum_Moderation', false)
        && AP_Forum_Moderation::unapproveTopic($topicId, $moderatorId, $db);
}

/**
 * Approve a pending forum post.
 *
 * @see AP_Forum_Moderation::approvePost()
 */
function ap_approve_forum_post(int $postId, int $moderatorId = 0, ?AP_DB $db = null): bool
{
    return class_exists('AP_Forum_Moderation', false)
        && AP_Forum_Moderation::approvePost($postId, $moderatorId, $db);
}

/**
 * Unapprove a forum post (hold).
 *
 * @see AP_Forum_Moderation::unapprovePost()
 */
function ap_unapprove_forum_post(int $postId, int $moderatorId = 0, ?AP_DB $db = null): bool
{
    return class_exists('AP_Forum_Moderation', false)
        && AP_Forum_Moderation::unapprovePost($postId, $moderatorId, $db);
}

// -----------------------------------------------------------------------------
// Forum attachments (media linked to posts, quotas)
// -----------------------------------------------------------------------------

/**
 * Whether forum attachments are enabled.
 *
 * @see AP_Forum_Attachment::isEnabled()
 */
function ap_forum_attachments_enabled(?AP_DB $db = null): bool
{
    return AP_Forum_Attachment::isEnabled($db);
}

/**
 * Handle a forum attachment upload ($_FILES-style array).
 *
 * @param array<string, mixed> $file
 * @param array<string, mixed> $args
 *
 * @return array{ok: bool, id: int, media_id: int, file: string, url: string, type: string, error: string, attachment: ?object}
 *
 * @see AP_Forum_Attachment::handleUpload()
 */
function ap_handle_forum_attachment_upload(array $file, array $args = [], ?AP_DB $db = null): array
{
    return AP_Forum_Attachment::handleUpload($file, $args, $db);
}

/**
 * Link an existing media library attachment to a forum post.
 *
 * @param array<string, mixed> $args
 *
 * @see AP_Forum_Attachment::attachMedia()
 */
function ap_attach_forum_media(int $mediaId, int $postId, array $args = [], ?AP_DB $db = null): int
{
    return AP_Forum_Attachment::attachMedia($mediaId, $postId, $args, $db);
}

/**
 * Assign orphan forum attachments to a post after create.
 *
 * @param list<int> $attachIds
 *
 * @see AP_Forum_Attachment::assignToPost()
 */
function ap_assign_forum_attachments(array $attachIds, int $postId, ?AP_DB $db = null): int
{
    return AP_Forum_Attachment::assignToPost($attachIds, $postId, $db);
}

/**
 * Fetch a forum attachment by ID.
 *
 * @see AP_Forum_Attachment::get()
 */
function ap_get_forum_attachment(int $attachId, ?AP_DB $db = null): ?object
{
    return AP_Forum_Attachment::get($attachId, $db);
}

/**
 * Attachments for a forum post.
 *
 * @return list<object>
 *
 * @see AP_Forum_Attachment::getForPost()
 */
function ap_get_forum_attachments(int $postId, ?AP_DB $db = null): array
{
    return AP_Forum_Attachment::getForPost($postId, $db);
}

/**
 * Theme-friendly attachment rows for a forum post.
 *
 * @return list<array<string, mixed>>
 *
 * @see AP_Forum_Attachment::getDisplayForPost()
 */
function ap_get_forum_attachments_display(int $postId, ?AP_DB $db = null): array
{
    return AP_Forum_Attachment::getDisplayForPost($postId, $db);
}

/**
 * Delete a forum attachment (and optionally its media file).
 *
 * @see AP_Forum_Attachment::delete()
 */
function ap_delete_forum_attachment(int $attachId, bool $deleteFile = true, ?AP_DB $db = null): bool
{
    return AP_Forum_Attachment::delete($attachId, $deleteFile, $db);
}

/**
 * Bytes used by a user's forum attachments.
 *
 * @see AP_Forum_Attachment::userUsageBytes()
 */
function ap_forum_attachment_user_usage(int $userId, ?AP_DB $db = null): int
{
    return AP_Forum_Attachment::userUsageBytes($userId, $db);
}

/**
 * Whether a user may upload a forum attachment of the given size.
 *
 * @return array{ok: bool, error: string}
 *
 * @see AP_Forum_Attachment::canUpload()
 */
function ap_can_upload_forum_attachment(
    int $userId,
    int $fileSize,
    ?int $postId = null,
    ?AP_DB $db = null
): array {
    return AP_Forum_Attachment::canUpload($userId, $fileSize, $postId, $db);
}

// -----------------------------------------------------------------------------
// User groups (forum permission foundation)
// -----------------------------------------------------------------------------

/**
 * Ensure built-in system groups exist.
 *
 * @return array<string, int>
 *
 * @see AP_Group::ensureSystemGroups()
 */
function ap_ensure_system_groups(?AP_DB $db = null): array
{
    return AP_Group::ensureSystemGroups($db);
}

/**
 * Fetch a group by ID.
 *
 * @see AP_Group::get()
 */
function ap_get_group(int $id, ?AP_DB $db = null): ?object
{
    return AP_Group::get($id, $db);
}

/**
 * Fetch a group by slug.
 *
 * @see AP_Group::getBySlug()
 */
function ap_get_group_by_slug(string $slug, ?AP_DB $db = null): ?object
{
    return AP_Group::getBySlug($slug, $db);
}

/**
 * Create a user group.
 *
 * @param array<string, mixed> $data
 *
 * @see AP_Group::create()
 */
function ap_create_group(array $data, ?AP_DB $db = null): int
{
    return AP_Group::create($data, $db);
}

/**
 * Update a user group.
 *
 * @param array<string, mixed> $data
 *
 * @see AP_Group::update()
 */
function ap_update_group(int $id, array $data, ?AP_DB $db = null): bool
{
    return AP_Group::update($id, $data, $db);
}

/**
 * Delete a user group (system groups cannot be deleted).
 *
 * @see AP_Group::delete()
 */
function ap_delete_group(int $id, ?AP_DB $db = null): bool
{
    return AP_Group::delete($id, $db);
}

/**
 * List groups.
 *
 * @param array<string, mixed> $args
 *
 * @return list<object>
 *
 * @see AP_Group::query()
 */
function ap_get_groups(array $args = [], ?AP_DB $db = null): array
{
    return AP_Group::query($args, $db);
}

/**
 * Add a user to a group.
 *
 * @see AP_Group::addMember()
 */
function ap_add_group_member(
    int $groupId,
    int $userId,
    string $role = 'member',
    ?AP_DB $db = null
): int {
    return AP_Group::addMember($groupId, $userId, $role, $db);
}

/**
 * Remove a user from a group.
 *
 * @see AP_Group::removeMember()
 */
function ap_remove_group_member(int $groupId, int $userId, ?AP_DB $db = null): bool
{
    return AP_Group::removeMember($groupId, $userId, $db);
}

/**
 * Groups a user belongs to (explicit membership).
 *
 * @return list<object>
 *
 * @see AP_Group::getUserGroups()
 */
function ap_get_user_groups(int $userId, ?AP_DB $db = null): array
{
    return AP_Group::getUserGroups($userId, $db);
}

/**
 * Members of a group.
 *
 * @param array<string, mixed> $args
 *
 * @return list<object>
 *
 * @see AP_Group::getMembers()
 */
function ap_get_group_members(int $groupId, array $args = [], ?AP_DB $db = null): array
{
    return AP_Group::getMembers($groupId, $args, $db);
}

/**
 * Effective group IDs for permission checks (explicit + virtual).
 *
 * @return list<int>
 *
 * @see AP_Group::getEffectiveGroupIds()
 */
function ap_get_effective_group_ids(int $userId, ?AP_DB $db = null): array
{
    return AP_Group::getEffectiveGroupIds($userId, $db);
}

// -----------------------------------------------------------------------------
// Granular per-forum permissions
// -----------------------------------------------------------------------------

/**
 * Seed system groups + global default forum ACL.
 *
 * @see AP_Forum_Permissions::ensureDefaults()
 */
function ap_ensure_forum_permission_defaults(?AP_DB $db = null): void
{
    AP_Forum_Permissions::ensureDefaults($db);
}

/**
 * Known forum permission keys.
 *
 * @return list<string>
 *
 * @see AP_Forum_Permissions::allPermissions()
 */
function ap_forum_permissions(): array
{
    return AP_Forum_Permissions::allPermissions();
}

/**
 * Set a group permission on a forum (forum_id 0 = global).
 *
 * @see AP_Forum_Permissions::setPermission()
 */
function ap_set_forum_permission(
    int $forumId,
    int $groupId,
    string $perm,
    bool $allow,
    ?AP_DB $db = null
): bool {
    return AP_Forum_Permissions::setPermission($forumId, $groupId, $perm, $allow, $db);
}

/**
 * Remove a stored forum permission row.
 *
 * @see AP_Forum_Permissions::removePermission()
 */
function ap_remove_forum_permission(
    int $forumId,
    int $groupId,
    string $perm,
    ?AP_DB $db = null
): bool {
    return AP_Forum_Permissions::removePermission($forumId, $groupId, $perm, $db);
}

/**
 * Permission map for one group on one forum.
 *
 * @return array<string, bool>
 *
 * @see AP_Forum_Permissions::getGroupPermissions()
 */
function ap_get_group_forum_permissions(int $forumId, int $groupId, ?AP_DB $db = null): array
{
    return AP_Forum_Permissions::getGroupPermissions($forumId, $groupId, $db);
}

/**
 * Whether a user may perform a permission in a forum.
 *
 * @see AP_Forum_Permissions::userCan()
 */
function ap_user_can_forum(
    int $userId,
    int $forumId,
    string $perm,
    ?AP_DB $db = null
): bool {
    return AP_Forum_Permissions::userCan($userId, $forumId, $perm, $db);
}

/**
 * Whether the current user may perform a permission in a forum.
 *
 * @see AP_Forum_Permissions::currentUserCan()
 */
function ap_current_user_can_forum(int $forumId, string $perm, ?AP_DB $db = null): bool
{
    return AP_Forum_Permissions::currentUserCan($forumId, $perm, $db);
}

/**
 * Effective permission map for a user on a forum.
 *
 * @return array<string, bool>
 *
 * @see AP_Forum_Permissions::getUserPermissions()
 */
function ap_get_user_forum_permissions(int $userId, int $forumId, ?AP_DB $db = null): array
{
    return AP_Forum_Permissions::getUserPermissions($userId, $forumId, $db);
}

/**
 * Whether a user may view a forum.
 *
 * @see AP_Forum_Permissions::userCanViewForum()
 */
function ap_user_can_view_forum(int $userId, int $forumId, ?AP_DB $db = null): bool
{
    return AP_Forum_Permissions::userCanViewForum($userId, $forumId, $db);
}

/**
 * Whether a user may create topics in a forum.
 *
 * @see AP_Forum_Permissions::userCanPostTopic()
 */
function ap_user_can_post_topic(int $userId, int $forumId, ?AP_DB $db = null): bool
{
    return AP_Forum_Permissions::userCanPostTopic($userId, $forumId, $db);
}

/**
 * Whether a user may reply in a forum.
 *
 * @see AP_Forum_Permissions::userCanPostReply()
 */
function ap_user_can_post_reply(int $userId, int $forumId, ?AP_DB $db = null): bool
{
    return AP_Forum_Permissions::userCanPostReply($userId, $forumId, $db);
}

/**
 * Whether a user may moderate a forum.
 *
 * @see AP_Forum_Permissions::userCanModerate()
 */
function ap_user_can_moderate_forum(int $userId, int $forumId, ?AP_DB $db = null): bool
{
    return AP_Forum_Permissions::userCanModerate($userId, $forumId, $db);
}

// -----------------------------------------------------------------------------
// Forum moderation — edit/soft-delete, move/merge/split, reports, warnings, bans
// -----------------------------------------------------------------------------

/**
 * Lock a forum topic.
 *
 * @see AP_Forum_Moderation::lockTopic()
 */
function ap_lock_topic(int $topicId, int $moderatorId = 0, ?AP_DB $db = null): bool
{
    return AP_Forum_Moderation::lockTopic($topicId, $moderatorId, $db);
}

/**
 * Unlock a forum topic.
 *
 * @see AP_Forum_Moderation::unlockTopic()
 */
function ap_unlock_topic(int $topicId, int $moderatorId = 0, ?AP_DB $db = null): bool
{
    return AP_Forum_Moderation::unlockTopic($topicId, $moderatorId, $db);
}

/**
 * Soft-delete a forum topic.
 *
 * @see AP_Forum_Moderation::softDeleteTopic()
 */
function ap_soft_delete_topic(int $topicId, int $moderatorId = 0, ?AP_DB $db = null): bool
{
    return AP_Forum_Moderation::softDeleteTopic($topicId, $moderatorId, $db);
}

/**
 * Restore a soft-deleted forum topic.
 *
 * @see AP_Forum_Moderation::restoreTopic()
 */
function ap_restore_topic(int $topicId, int $moderatorId = 0, ?AP_DB $db = null): bool
{
    return AP_Forum_Moderation::restoreTopic($topicId, $moderatorId, $db);
}

/**
 * Move a topic to another forum.
 *
 * @see AP_Forum_Moderation::moveTopic()
 */
function ap_move_topic(int $topicId, int $newForumId, int $moderatorId = 0, ?AP_DB $db = null): bool
{
    return AP_Forum_Moderation::moveTopic($topicId, $newForumId, $moderatorId, $db);
}

/**
 * Merge source topic into target topic.
 *
 * @see AP_Forum_Moderation::mergeTopics()
 */
function ap_merge_topics(
    int $sourceTopicId,
    int $targetTopicId,
    int $moderatorId = 0,
    ?AP_DB $db = null
): bool {
    return AP_Forum_Moderation::mergeTopics($sourceTopicId, $targetTopicId, $moderatorId, $db);
}

/**
 * Split selected posts into a new topic.
 *
 * @param list<int>            $postIds
 * @param array<string, mixed> $args
 *
 * @see AP_Forum_Moderation::splitTopic()
 */
function ap_split_topic(
    int $sourceTopicId,
    array $postIds,
    array $args = [],
    ?AP_DB $db = null
): int {
    return AP_Forum_Moderation::splitTopic($sourceTopicId, $postIds, $args, $db);
}

/**
 * Soft-delete a forum post (unapprove / hide).
 *
 * @see AP_Forum_Moderation::softDeletePost()
 */
function ap_soft_delete_forum_post(
    int $postId,
    int $moderatorId = 0,
    string $reason = '',
    ?AP_DB $db = null
): bool {
    return AP_Forum_Moderation::softDeletePost($postId, $moderatorId, $reason, $db);
}

/**
 * Restore a soft-deleted forum post.
 *
 * @see AP_Forum_Moderation::restorePost()
 */
function ap_restore_forum_post(int $postId, int $moderatorId = 0, ?AP_DB $db = null): bool
{
    return AP_Forum_Moderation::restorePost($postId, $moderatorId, $db);
}

/**
 * Moderator edit of a forum post.
 *
 * @param array<string, mixed> $data
 *
 * @see AP_Forum_Moderation::editPost()
 */
function ap_mod_edit_forum_post(
    int $postId,
    array $data,
    int $moderatorId = 0,
    ?AP_DB $db = null
): bool {
    return AP_Forum_Moderation::editPost($postId, $data, $moderatorId, $db);
}

/**
 * File a moderation report.
 *
 * @param array<string, mixed> $data
 *
 * @see AP_Forum_Moderation::createReport()
 */
function ap_create_report(array $data, ?AP_DB $db = null): int
{
    return AP_Forum_Moderation::createReport($data, $db);
}

/**
 * Get a report by id.
 *
 * @see AP_Forum_Moderation::getReport()
 */
function ap_get_report(int $id, ?AP_DB $db = null): ?object
{
    return AP_Forum_Moderation::getReport($id, $db);
}

/**
 * Query moderation reports.
 *
 * @param array<string, mixed> $args
 *
 * @return list<object>
 *
 * @see AP_Forum_Moderation::queryReports()
 */
function ap_get_reports(array $args = [], ?AP_DB $db = null): array
{
    return AP_Forum_Moderation::queryReports($args, $db);
}

/**
 * Resolve (close) a report.
 *
 * @see AP_Forum_Moderation::resolveReport()
 */
function ap_resolve_report(int $reportId, int $resolvedBy = 0, ?AP_DB $db = null): bool
{
    return AP_Forum_Moderation::resolveReport($reportId, $resolvedBy, $db);
}

/**
 * Dismiss a report.
 *
 * @see AP_Forum_Moderation::dismissReport()
 */
function ap_dismiss_report(int $reportId, int $resolvedBy = 0, ?AP_DB $db = null): bool
{
    return AP_Forum_Moderation::dismissReport($reportId, $resolvedBy, $db);
}

/**
 * Issue a user warning.
 *
 * @param array<string, mixed> $data
 *
 * @see AP_Forum_Moderation::issueWarning()
 */
function ap_issue_warning(array $data, ?AP_DB $db = null): int
{
    return AP_Forum_Moderation::issueWarning($data, $db);
}

/**
 * Warnings for a user.
 *
 * @param array<string, mixed> $args
 *
 * @return list<object>
 *
 * @see AP_Forum_Moderation::getUserWarnings()
 */
function ap_get_user_warnings(int $userId, array $args = [], ?AP_DB $db = null): array
{
    return AP_Forum_Moderation::getUserWarnings($userId, $args, $db);
}

/**
 * Revoke a warning.
 *
 * @see AP_Forum_Moderation::revokeWarning()
 */
function ap_revoke_warning(int $warningId, int $revokedBy = 0, ?AP_DB $db = null): bool
{
    return AP_Forum_Moderation::revokeWarning($warningId, $revokedBy, $db);
}

/**
 * Ban a user (optional expiry for suspension).
 *
 * @param array<string, mixed> $data
 *
 * @see AP_Forum_Moderation::banUser()
 */
function ap_ban_user(int $userId, array $data = [], ?AP_DB $db = null): int
{
    return AP_Forum_Moderation::banUser($userId, $data, $db);
}

/**
 * Suspend a user until a datetime.
 *
 * @param array<string, mixed> $data
 *
 * @see AP_Forum_Moderation::suspendUser()
 */
function ap_suspend_user(
    int $userId,
    string $expiresAt,
    array $data = [],
    ?AP_DB $db = null
): int {
    return AP_Forum_Moderation::suspendUser($userId, $expiresAt, $data, $db);
}

/**
 * Unban a user.
 *
 * @see AP_Forum_Moderation::unbanUser()
 */
function ap_unban_user(int $userId, int $liftedBy = 0, ?AP_DB $db = null): bool
{
    return AP_Forum_Moderation::unbanUser($userId, $liftedBy, $db);
}

/**
 * Whether a user is currently banned/suspended.
 *
 * @see AP_Forum_Moderation::isUserBanned()
 */
function ap_is_user_banned(int $userId, ?AP_DB $db = null): bool
{
    return AP_Forum_Moderation::isUserBanned($userId, $db);
}

/**
 * Ban an IP address.
 *
 * @param array<string, mixed> $data
 *
 * @see AP_Forum_Moderation::banIp()
 */
function ap_ban_ip(string $ip, array $data = [], ?AP_DB $db = null): int
{
    return AP_Forum_Moderation::banIp($ip, $data, $db);
}

/**
 * Whether an IP is banned.
 *
 * @see AP_Forum_Moderation::isIpBanned()
 */
function ap_is_ip_banned(string $ip, ?AP_DB $db = null): bool
{
    return AP_Forum_Moderation::isIpBanned($ip, $db);
}

// -----------------------------------------------------------------------------
// Private messaging (inbox / outbox / threads)
// -----------------------------------------------------------------------------

/**
 * Whether private messaging is enabled and the forum module is on.
 *
 * @see AP_Private_Message::isAvailable()
 */
function ap_private_messaging_enabled(?AP_DB $db = null): bool
{
    return AP_Private_Message::isAvailable($db);
}

/**
 * Whether a user may send private messages.
 *
 * @see AP_Private_Message::userCanSend()
 */
function ap_user_can_send_pm(int $userId, ?AP_DB $db = null): bool
{
    return AP_Private_Message::userCanSend($userId, $db);
}

/**
 * Send a private message. Returns new message_id or 0.
 *
 * @param array<string, mixed> $data
 * @param array<string, mixed> $args
 *
 * @see AP_Private_Message::send()
 */
function ap_send_private_message(array $data, ?AP_DB $db = null, array $args = []): int
{
    return AP_Private_Message::send($data, $db, $args);
}

/**
 * Reply in a PM thread.
 *
 * @param array<string, mixed> $data
 * @param array<string, mixed> $args
 *
 * @see AP_Private_Message::reply()
 */
function ap_reply_private_message(array $data, ?AP_DB $db = null, array $args = []): int
{
    return AP_Private_Message::reply($data, $db, $args);
}

/**
 * Fetch a private message by ID.
 *
 * @see AP_Private_Message::get()
 */
function ap_get_private_message(int $id, ?AP_DB $db = null): ?object
{
    return AP_Private_Message::get($id, $db);
}

/**
 * Fetch a private message only if the user may view it.
 *
 * @see AP_Private_Message::getForUser()
 */
function ap_get_private_message_for_user(int $id, int $userId, ?AP_DB $db = null): ?object
{
    return AP_Private_Message::getForUser($id, $userId, $db);
}

/**
 * Inbox messages for a user.
 *
 * @param array<string, mixed> $args
 *
 * @return list<object>
 *
 * @see AP_Private_Message::getInbox()
 */
function ap_get_pm_inbox(int $userId, array $args = [], ?AP_DB $db = null): array
{
    return AP_Private_Message::getInbox($userId, $args, $db);
}

/**
 * Outbox (sent) messages for a user.
 *
 * @param array<string, mixed> $args
 *
 * @return list<object>
 *
 * @see AP_Private_Message::getOutbox()
 */
function ap_get_pm_outbox(int $userId, array $args = [], ?AP_DB $db = null): array
{
    return AP_Private_Message::getOutbox($userId, $args, $db);
}

/**
 * Unread private messages for a user.
 *
 * @param array<string, mixed> $args
 *
 * @return list<object>
 *
 * @see AP_Private_Message::getUnread()
 */
function ap_get_pm_unread(int $userId, array $args = [], ?AP_DB $db = null): array
{
    return AP_Private_Message::getUnread($userId, $args, $db);
}

/**
 * Count unread private messages for a user.
 *
 * @see AP_Private_Message::countUnread()
 */
function ap_count_unread_pms(int $userId, ?AP_DB $db = null): int
{
    return AP_Private_Message::countUnread($userId, $db);
}

/**
 * Full conversation thread for a message.
 *
 * @return list<object>
 *
 * @see AP_Private_Message::getThread()
 */
function ap_get_pm_thread(int $messageId, int $userId = 0, ?AP_DB $db = null): array
{
    return AP_Private_Message::getThread($messageId, $userId, $db);
}

/**
 * Mark a private message as read.
 *
 * @see AP_Private_Message::markRead()
 */
function ap_mark_pm_read(int $messageId, int $userId = 0, ?AP_DB $db = null): bool
{
    return AP_Private_Message::markRead($messageId, $userId, $db);
}

/**
 * Mark a private message as unread.
 *
 * @see AP_Private_Message::markUnread()
 */
function ap_mark_pm_unread(int $messageId, int $userId = 0, ?AP_DB $db = null): bool
{
    return AP_Private_Message::markUnread($messageId, $userId, $db);
}

/**
 * Soft-delete a private message for one user (hard-purges when both sides delete).
 *
 * @param array<string, mixed> $args
 *
 * @see AP_Private_Message::deleteForUser()
 */
function ap_delete_private_message(
    int $messageId,
    int $userId,
    ?AP_DB $db = null,
    array $args = []
): bool {
    return AP_Private_Message::deleteForUser($messageId, $userId, $db, $args);
}

/**
 * Theme-friendly inbox or outbox rows.
 *
 * @param array<string, mixed> $args
 *
 * @return list<array<string, mixed>>
 *
 * @see AP_Private_Message::getFolderDisplay()
 */
function ap_get_pm_folder_display(
    int $userId,
    string $folder = 'inbox',
    array $args = [],
    ?AP_DB $db = null
): array {
    return AP_Private_Message::getFolderDisplay($userId, $folder, $args, $db);
}

/**
 * Theme-friendly thread rows.
 *
 * @return list<array<string, mixed>>
 *
 * @see AP_Private_Message::getThreadDisplay()
 */
function ap_get_pm_thread_display(int $messageId, int $userId = 0, ?AP_DB $db = null): array
{
    return AP_Private_Message::getThreadDisplay($messageId, $userId, $db);
}

/**
 * Format PM body to safe HTML.
 *
 * @param array<string, mixed> $formatArgs
 *
 * @see AP_Private_Message::formatContent()
 */
function ap_format_private_message(string $content, array $formatArgs = []): string
{
    return AP_Private_Message::formatContent($content, $formatArgs);
}

// -----------------------------------------------------------------------------
// Who’s online (presence)
// -----------------------------------------------------------------------------

/**
 * Whether who’s-online tracking is available.
 *
 * @see AP_Online::isAvailable()
 */
function ap_online_enabled(?AP_DB $db = null): bool
{
    return AP_Online::isAvailable($db);
}

/**
 * Record or refresh a presence row.
 *
 * @param array<string, mixed> $data
 * @param array<string, mixed> $args
 *
 * @see AP_Online::track()
 */
function ap_track_online(array $data, ?AP_DB $db = null, array $args = []): int
{
    return AP_Online::track($data, $db, $args);
}

/**
 * Track the current request presence.
 *
 * @param array<string, mixed> $context
 * @param array<string, mixed> $args
 *
 * @see AP_Online::trackCurrent()
 */
function ap_track_online_current(array $context = [], ?AP_DB $db = null, array $args = []): int
{
    return AP_Online::trackCurrent($context, $db, $args);
}

/**
 * Remove a presence row by session key.
 *
 * @see AP_Online::remove()
 */
function ap_remove_online(string $sessionKey, ?AP_DB $db = null): bool
{
    return AP_Online::remove($sessionKey, $db);
}

/**
 * Prune stale online rows.
 *
 * @param array<string, mixed> $args
 *
 * @see AP_Online::prune()
 */
function ap_prune_online(?AP_DB $db = null, array $args = []): int
{
    return AP_Online::prune($db, $args);
}

/**
 * Whether a user is currently online.
 *
 * @see AP_Online::isUserOnline()
 */
function ap_is_user_online(int $userId, ?AP_DB $db = null): bool
{
    return AP_Online::isUserOnline($userId, $db);
}

/**
 * Distinct logged-in users currently online.
 *
 * @param array<string, mixed> $args
 *
 * @return list<object>
 *
 * @see AP_Online::getOnlineUsers()
 */
function ap_get_online_users(array $args = [], ?AP_DB $db = null): array
{
    return AP_Online::getOnlineUsers($args, $db);
}

/**
 * Guest sessions currently online.
 *
 * @param array<string, mixed> $args
 *
 * @return list<object>
 *
 * @see AP_Online::getOnlineGuests()
 */
function ap_get_online_guests(array $args = [], ?AP_DB $db = null): array
{
    return AP_Online::getOnlineGuests($args, $db);
}

/**
 * Total online count (members + guests).
 *
 * @see AP_Online::countOnline()
 */
function ap_count_online(?AP_DB $db = null): int
{
    return AP_Online::countOnline($db);
}

/**
 * Theme-friendly who’s-online snapshot.
 *
 * @param array<string, mixed> $args
 *
 * @return array<string, mixed>
 *
 * @see AP_Online::getDisplay()
 */
function ap_get_online_display(array $args = [], ?AP_DB $db = null): array
{
    return AP_Online::getDisplay($args, $db);
}

// -----------------------------------------------------------------------------
// Forum unread tracking
// -----------------------------------------------------------------------------

/**
 * Whether forum unread tracking is available.
 *
 * @see AP_Forum_Read::isAvailable()
 */
function ap_forum_unread_tracking_enabled(?AP_DB $db = null): bool
{
    return AP_Forum_Read::isAvailable($db);
}

/**
 * Mark a topic as read for a user.
 *
 * @param array<string, mixed> $args
 *
 * @see AP_Forum_Read::markTopicRead()
 */
function ap_mark_topic_read(int $userId, int $topicId, ?AP_DB $db = null, array $args = []): bool
{
    return AP_Forum_Read::markTopicRead($userId, $topicId, $db, $args);
}

/**
 * Mark a topic as read on view (first-unread + page-aware watermark).
 *
 * @param array<string, mixed> $args page, per_page, mark_time, check_enabled
 *
 * @return array{first_unread_post_id: int, marked: bool, mark_time: string}
 *
 * @see AP_Forum_Read::markTopicReadOnView()
 */
function ap_mark_topic_read_on_view(
    int $userId,
    int $topicId,
    ?AP_DB $db = null,
    array $args = []
): array {
    return AP_Forum_Read::markTopicReadOnView($userId, $topicId, $db, $args);
}

/**
 * Mark a forum as read for a user.
 *
 * @param array<string, mixed> $args
 *
 * @see AP_Forum_Read::markForumRead()
 */
function ap_mark_forum_read(int $userId, int $forumId, ?AP_DB $db = null, array $args = []): bool
{
    return AP_Forum_Read::markForumRead($userId, $forumId, $db, $args);
}

/**
 * Mark all forums/topics as read for a user.
 *
 * @param array<string, mixed> $args
 *
 * @see AP_Forum_Read::markAllRead()
 */
function ap_mark_all_forums_read(int $userId, ?AP_DB $db = null, array $args = []): bool
{
    return AP_Forum_Read::markAllRead($userId, $db, $args);
}

/**
 * Whether a topic is unread for a user.
 *
 * @param object|int $topic
 *
 * @see AP_Forum_Read::isTopicUnread()
 */
function ap_is_topic_unread(int $userId, object|int $topic, ?AP_DB $db = null): bool
{
    return AP_Forum_Read::isTopicUnread($userId, $topic, $db);
}

/**
 * Whether a forum has unread topics for a user.
 *
 * @see AP_Forum_Read::isForumUnread()
 */
function ap_is_forum_unread(int $userId, int $forumId, ?AP_DB $db = null): bool
{
    return AP_Forum_Read::isForumUnread($userId, $forumId, $db);
}

/**
 * Unread topics for a user.
 *
 * @param array<string, mixed> $args
 *
 * @return list<object>
 *
 * @see AP_Forum_Read::getUnreadTopics()
 */
function ap_get_unread_topics(int $userId, array $args = [], ?AP_DB $db = null): array
{
    return AP_Forum_Read::getUnreadTopics($userId, $args, $db);
}

/**
 * Count unread topics in a forum.
 *
 * @param array<string, mixed> $args
 *
 * @see AP_Forum_Read::countUnreadTopicsInForum()
 */
function ap_count_unread_topics_in_forum(
    int $userId,
    int $forumId,
    ?AP_DB $db = null,
    array $args = []
): int {
    return AP_Forum_Read::countUnreadTopicsInForum($userId, $forumId, $db, $args);
}

/**
 * Annotate topic rows with is_unread.
 *
 * @param list<object|array<string, mixed>> $topics
 *
 * @return list<array<string, mixed>>
 *
 * @see AP_Forum_Read::annotateTopics()
 */
function ap_annotate_topics_unread(int $userId, array $topics, ?AP_DB $db = null): array
{
    return AP_Forum_Read::annotateTopics($userId, $topics, $db);
}

/**
 * Annotate forum rows with is_unread (forum rollup).
 *
 * @param list<object|array<string, mixed>> $forums
 *
 * @return list<array<string, mixed>>
 *
 * @see AP_Forum_Read::annotateForums()
 */
function ap_annotate_forums_unread(int $userId, array $forums, ?AP_DB $db = null): array
{
    return AP_Forum_Read::annotateForums($userId, $forums, $db);
}

/**
 * First unread post in a topic for a user (or null).
 *
 * @param object|int $topic
 *
 * @see AP_Forum_Read::getFirstUnreadPost()
 */
function ap_get_first_unread_post(int $userId, object|int $topic, ?AP_DB $db = null): ?object
{
    return AP_Forum_Read::getFirstUnreadPost($userId, $topic, $db);
}

/**
 * First unread post_id in a topic for a user (0 when none).
 *
 * @param object|int $topic
 *
 * @see AP_Forum_Read::getFirstUnreadPostId()
 */
function ap_get_first_unread_post_id(int $userId, object|int $topic, ?AP_DB $db = null): int
{
    return AP_Forum_Read::getFirstUnreadPostId($userId, $topic, $db);
}

/**
 * “First unread post” jump markup for topic view (SPEC B1).
 *
 * Returns empty string when $postId < 1 (guest, fully read, tracking off) so
 * themes can call it unconditionally and hide the control when not applicable.
 *
 * Prefer resolving the post id *before* markTopicRead() on topic view (see
 * {@see AP_Forum_Front::applyToQuery()} query var `first_unread_post_id`).
 *
 * @param array{
 *   label?: string,
 *   href?: string,
 *   class?: string,
 *   wrap_class?: string
 * } $args
 */
function ap_forum_first_unread_link_html(int $postId, array $args = []): string
{
    if ($postId < 1) {
        return '';
    }

    $label = isset($args['label']) && is_string($args['label']) && $args['label'] !== ''
        ? (string) $args['label']
        : 'First unread post';

    $href = isset($args['href']) && is_string($args['href']) && $args['href'] !== ''
        ? (string) $args['href']
        : '#post-' . $postId;

    $linkClasses = ['ap-forum-first-unread'];
    if (!empty($args['class']) && is_string($args['class'])) {
        $extra = trim($args['class']);
        if ($extra !== '') {
            $linkClasses[] = $extra;
        }
    }

    $wrapClasses = ['ap-forum-first-unread-wrap'];
    if (!empty($args['wrap_class']) && is_string($args['wrap_class'])) {
        $wrapExtra = trim($args['wrap_class']);
        if ($wrapExtra !== '') {
            $wrapClasses[] = $wrapExtra;
        }
    }

    return '<p class="' . ap_esc_attr(implode(' ', $wrapClasses)) . '">'
        . '<a class="' . ap_esc_attr(implode(' ', $linkClasses)) . '"'
        . ' href="' . ap_esc_url($href) . '"'
        . ' aria-label="' . ap_esc_attr($label) . '">'
        . ap_esc_html($label)
        . '</a>'
        . '</p>';
}

/**
 * Theme-friendly unread summary.
 *
 * @param array<string, mixed> $args
 *
 * @return array<string, mixed>
 *
 * @see AP_Forum_Read::getUnreadSummary()
 */
function ap_get_unread_summary(int $userId, array $args = [], ?AP_DB $db = null): array
{
    return AP_Forum_Read::getUnreadSummary($userId, $args, $db);
}

// -----------------------------------------------------------------------------
// Content formatting (BBCode + Markdown + limited safe HTML)
// -----------------------------------------------------------------------------

/**
 * Format user content for safe HTML display.
 *
 * Default mode `auto`: BBCode → Markdown → whitelist kses.
 *
 * @param array<string, mixed> $args mode, context, …
 *
 * @see AP_Content_Format::format()
 */
function ap_format_content(string $content, array $args = []): string
{
    if (!class_exists('AP_Content_Format', false)) {
        return ap_esc_html($content);
    }

    return AP_Content_Format::format($content, $args);
}

/**
 * Convert BBCode to HTML (not sanitized; prefer ap_format_content).
 *
 * @see AP_Content_Format::bbcodeToHtml()
 */
function ap_bbcode_to_html(string $text): string
{
    if (!class_exists('AP_Content_Format', false)) {
        return ap_esc_html($text);
    }

    return AP_Content_Format::bbcodeToHtml($text);
}

/**
 * Convert a Markdown subset to HTML (not sanitized; prefer ap_format_content).
 *
 * @see AP_Content_Format::markdownToHtml()
 */
function ap_markdown_to_html(string $text): string
{
    if (!class_exists('AP_Content_Format', false)) {
        return ap_esc_html($text);
    }

    return AP_Content_Format::markdownToHtml($text);
}

/**
 * Render a visual WYSIWYG editor (toolbar + contenteditable surface + textarea).
 *
 * @param array<string, mixed> $args See {@see AP_Editor::render()}.
 *
 * @see AP_Editor::render()
 */
function ap_editor(array $args = []): string
{
    if (!class_exists('AP_Editor', false)) {
        $id = (string) ($args['id'] ?? 'content');
        $name = (string) ($args['name'] ?? $id);
        $value = (string) ($args['value'] ?? '');
        $rows = max(3, (int) ($args['rows'] ?? 12));
        $class = trim((string) ($args['class'] ?? 'large-text'));
        $req = !empty($args['required']) ? ' required' : '';

        return '<textarea name="' . ap_esc_attr($name) . '" id="' . ap_esc_attr($id)
            . '" rows="' . $rows . '" class="' . ap_esc_attr($class) . '"' . $req . '>'
            . ap_esc_textarea($value) . '</textarea>';
    }

    return AP_Editor::render($args);
}

/**
 * Echo a visual WYSIWYG editor control.
 *
 * @param array<string, mixed> $args
 */
function ap_the_editor(array $args = []): void
{
    echo ap_editor($args);
}

/**
 * Enqueue visual editor CSS/JS (front-end asset pipeline).
 *
 * @see AP_Editor::enqueue()
 */
function ap_enqueue_editor(): void
{
    if (class_exists('AP_Editor', false)) {
        AP_Editor::enqueue();
    }
}

/**
 * Print visual editor CSS/JS tags once (admin screens).
 *
 * @see AP_Editor::printAssets()
 */
function ap_print_editor_assets(): void
{
    if (class_exists('AP_Editor', false)) {
        AP_Editor::printAssets();
    }
}

/**
 * Sanitize HTML to an allow-list of tags/attributes (kses).
 *
 * @param array<string, array<string, bool>>|null $allowed
 *
 * @see AP_Content_Format::kses()
 */
function ap_kses(string $html, ?array $allowed = null): string
{
    if (!class_exists('AP_Content_Format', false)) {
        return ap_esc_html($html);
    }

    return AP_Content_Format::kses($html, $allowed);
}

/**
 * Default allowed HTML tags for user content.
 *
 * @return array<string, array<string, bool>>
 *
 * @see AP_Content_Format::allowedTags()
 */
function ap_allowed_html(): array
{
    if (!class_exists('AP_Content_Format', false)) {
        return [];
    }

    return AP_Content_Format::allowedTags();
}

/**
 * Whether a URL is safe for href/src in formatted content.
 *
 * @see AP_Content_Format::isSafeUrl()
 */
function ap_is_safe_url(string $url): bool
{
    if (!class_exists('AP_Content_Format', false)) {
        return false;
    }

    return AP_Content_Format::isSafeUrl($url);
}

// -----------------------------------------------------------------------------
// Escaping & sanitization (output / input helpers)
// -----------------------------------------------------------------------------

// Ensure AP_Formatting is available when functions.php is loaded without full bootstrap.
if (!class_exists('AP_Formatting', false)) {
    require_once __DIR__ . '/class-ap-formatting.php';
}

/**
 * Escape for HTML body text.
 *
 * @see AP_Formatting::escHtml()
 */
function ap_esc_html(string $text): string
{
    return AP_Formatting::escHtml($text);
}

/**
 * Escape for HTML attribute values.
 *
 * @see AP_Formatting::escAttr()
 */
function ap_esc_attr(string $text): string
{
    return AP_Formatting::escAttr($text);
}

/**
 * Escape a URL for use in href/src (rejects javascript:/data:/etc.).
 *
 * @param list<string>|null $protocols Allowed schemes; null = defaults
 *
 * @see AP_Formatting::escUrl()
 */
function ap_esc_url(string $url, ?array $protocols = null): string
{
    return AP_Formatting::escUrl($url, $protocols, true);
}

/**
 * Sanitize a URL for storage / redirects (no HTML entity encoding).
 *
 * @param list<string>|null $protocols
 *
 * @see AP_Formatting::escUrlRaw()
 */
function ap_esc_url_raw(string $url, ?array $protocols = null): string
{
    return AP_Formatting::escUrlRaw($url, $protocols);
}

/**
 * Escape for HTML textarea content (same as esc_html; named for intent).
 *
 * @see AP_Formatting::escTextarea()
 */
function ap_esc_textarea(string $text): string
{
    return AP_Formatting::escTextarea($text);
}

/**
 * Escape text for embedding inside a JavaScript string literal.
 *
 * @see AP_Formatting::escJs()
 */
function ap_esc_js(string $text): string
{
    return AP_Formatting::escJs($text);
}

/**
 * Escape text for XML / RSS / Atom.
 *
 * @see AP_Formatting::escXml()
 */
function ap_esc_xml(string $text): string
{
    return AP_Formatting::escXml($text);
}

/**
 * Sanitize a single-line text field (strip tags, normalize whitespace).
 *
 * @see AP_Formatting::sanitizeTextField()
 */
function ap_sanitize_text_field(string $value): string
{
    return AP_Formatting::sanitizeTextField($value);
}

/**
 * Sanitize multiline text (strip tags, keep newlines).
 *
 * @see AP_Formatting::sanitizeTextareaField()
 */
function ap_sanitize_textarea_field(string $value): string
{
    return AP_Formatting::sanitizeTextareaField($value);
}

/**
 * Sanitize an email address (empty string when invalid).
 *
 * @see AP_Formatting::sanitizeEmail()
 */
function ap_sanitize_email(string $email): string
{
    return AP_Formatting::sanitizeEmail($email);
}

/**
 * Sanitize a key: lowercase alphanumeric, underscores, hyphens.
 *
 * @see AP_Formatting::sanitizeKey()
 */
function ap_sanitize_key(string $key): string
{
    return AP_Formatting::sanitizeKey($key);
}

/**
 * Sanitize a client filename to a safe basename.
 *
 * @see AP_Formatting::sanitizeFileName()
 */
function ap_sanitize_file_name(string $filename): string
{
    return AP_Formatting::sanitizeFileName($filename);
}

/**
 * Sanitize a hex color (#rgb / #rrggbb); empty when invalid.
 *
 * @see AP_Formatting::sanitizeHexColor()
 */
function ap_sanitize_hex_color(string $color): string
{
    return AP_Formatting::sanitizeHexColor($color);
}

/**
 * Sanitize a username / login.
 *
 * @see AP_Formatting::sanitizeUser()
 */
function ap_sanitize_user(string $username, bool $strict = false): string
{
    return AP_Formatting::sanitizeUser($username, $strict);
}

/**
 * Non-negative integer (0 when not a valid non-negative number).
 *
 * @see AP_Formatting::absint()
 */
function ap_absint(mixed $value): int
{
    return AP_Formatting::absint($value);
}

/**
 * Strip HTML tags (and script/style blocks) for sanitization.
 *
 * @see AP_Formatting::stripAllTags()
 */
function ap_strip_all_tags(string $value, bool $removeBreaks = false): string
{
    return AP_Formatting::stripAllTags($value, $removeBreaks);
}

/**
 * Allowed URL schemes for {@see ap_esc_url()}.
 *
 * @return list<string>
 *
 * @see AP_Formatting::allowedProtocols()
 */
function ap_allowed_protocols(): array
{
    return AP_Formatting::allowedProtocols();
}

// -----------------------------------------------------------------------------
// Rate limiting & login protection
// -----------------------------------------------------------------------------

/**
 * Best-effort client IP for rate buckets (REMOTE_ADDR; optional trusted proxy).
 *
 * @see AP_Rate_Limit::clientIp()
 */
function ap_client_ip(): string
{
    if (!class_exists('AP_Rate_Limit', false)) {
        if (!empty($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR'])) {
            $ip = trim($_SERVER['REMOTE_ADDR']);

            return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
        }

        return '';
    }

    return AP_Rate_Limit::clientIp();
}

/**
 * Whether a rate-limit bucket is currently blocked.
 *
 * @see AP_Rate_Limit::isLimited()
 */
function ap_rate_limit_is_limited(string $action, string $bucket = '', ?AP_DB $db = null): bool
{
    if (!class_exists('AP_Rate_Limit', false)) {
        return false;
    }

    return AP_Rate_Limit::isLimited($action, $bucket, $db);
}

/**
 * Inspect a rate-limit bucket without recording a hit.
 *
 * @return array{
 *   action: string,
 *   bucket: string,
 *   allowed: bool,
 *   attempts: int,
 *   limit: int,
 *   remaining: int,
 *   retry_after: int,
 *   locked: bool
 * }
 *
 * @see AP_Rate_Limit::check()
 */
function ap_rate_limit_check(string $action, string $bucket = '', ?AP_DB $db = null): array
{
    if (!class_exists('AP_Rate_Limit', false)) {
        return [
            'action' => $action,
            'bucket' => $bucket,
            'allowed' => true,
            'attempts' => 0,
            'limit' => 0,
            'remaining' => 0,
            'retry_after' => 0,
            'locked' => false,
        ];
    }

    return AP_Rate_Limit::check($action, $bucket, $db);
}

/**
 * Record one attempt against a rate-limit bucket.
 *
 * @return array<string, mixed>
 *
 * @see AP_Rate_Limit::hit()
 */
function ap_rate_limit_hit(string $action, string $bucket = '', ?AP_DB $db = null): array
{
    if (!class_exists('AP_Rate_Limit', false)) {
        return ap_rate_limit_check($action, $bucket, $db);
    }

    return AP_Rate_Limit::hit($action, $bucket, $db);
}

/**
 * Clear a rate-limit bucket (e.g. after successful login).
 *
 * @see AP_Rate_Limit::clear()
 */
function ap_rate_limit_clear(string $action, string $bucket = '', ?AP_DB $db = null): bool
{
    if (!class_exists('AP_Rate_Limit', false)) {
        return true;
    }

    return AP_Rate_Limit::clear($action, $bucket, $db);
}

/**
 * Whether login is currently rate-limited for this IP / identity.
 *
 * @return array{allowed: bool, retry_after: int, message: string}
 *
 * @see AP_Rate_Limit::checkLogin()
 */
function ap_check_login_rate_limit(
    string $loginOrEmail = '',
    string $ip = '',
    ?AP_DB $db = null
): array {
    if (!class_exists('AP_Rate_Limit', false)) {
        return ['allowed' => true, 'retry_after' => 0, 'message' => ''];
    }

    return AP_Rate_Limit::checkLogin($loginOrEmail, $ip, $db);
}

// -----------------------------------------------------------------------------
// Nonces (CSRF)
// -----------------------------------------------------------------------------

/**
 * Create a nonce for an action.
 *
 * @see AP_Nonce::create()
 */
function ap_create_nonce(string $action = '-1', ?int $userId = null): string
{
    return AP_Nonce::create($action, $userId);
}

/**
 * Verify a nonce. Returns 1 / 2 on success (tick age), false on failure.
 *
 * @return int|false
 *
 * @see AP_Nonce::verify()
 */
function ap_verify_nonce(string $nonce, string $action = '-1', ?int $userId = null): int|false
{
    return AP_Nonce::verify($nonce, $action, $userId);
}

/**
 * Whether a nonce is valid.
 *
 * @see AP_Nonce::check()
 */
function ap_check_nonce(string $nonce, string $action = '-1', ?int $userId = null): bool
{
    return AP_Nonce::check($nonce, $action, $userId);
}

/**
 * HTML hidden fields for a nonce (and optional referer).
 *
 * @see AP_Nonce::field()
 */
function ap_nonce_field(
    string $action = '-1',
    string $name = '_ap_nonce',
    bool $referer = true,
    ?int $userId = null
): string {
    return AP_Nonce::field($action, $name, $referer, $userId);
}

/**
 * Append a nonce query argument to a URL (GET row-actions / logout links).
 *
 * @see AP_Nonce::url()
 */
function ap_nonce_url(
    string $url,
    string $action = '-1',
    string $name = '_ap_nonce',
    ?int $userId = null
): string {
    return AP_Nonce::url($url, $action, $name, $userId);
}

/**
 * Verify a nonce taken from a request bag (POST/GET).
 *
 * @param array<string, mixed> $request
 *
 * @return int|false
 *
 * @see AP_Nonce::verifyRequest()
 */
function ap_verify_request_nonce(
    array $request,
    string $action = '-1',
    string $name = '_ap_nonce',
    ?int $userId = null
): int|false {
    return AP_Nonce::verifyRequest($request, $action, $name, $userId);
}

/**
 * Whether a request carries a valid nonce for the action.
 *
 * @param array<string, mixed> $request
 *
 * @see AP_Nonce::verifyRequest()
 */
function ap_check_request_nonce(
    array $request,
    string $action = '-1',
    string $name = '_ap_nonce',
    ?int $userId = null
): bool {
    return AP_Nonce::verifyRequest($request, $action, $name, $userId) !== false;
}

// -----------------------------------------------------------------------------
// Internationalization (gettext) + RTL
// -----------------------------------------------------------------------------

// Ensure AP_L10n is available when functions.php is loaded without full bootstrap.
if (!class_exists('AP_L10n', false)) {
    require_once __DIR__ . '/class-ap-l10n.php';
}

/**
 * Retrieve the current locale.
 *
 * @see AP_L10n::getLocale()
 */
function ap_get_locale(?AP_DB $db = null): string
{
    return AP_L10n::getLocale($db);
}

/**
 * Alias of {@see ap_get_locale()} (WordPress-compatible name).
 */
if (!function_exists('get_locale')) {
    function get_locale(?AP_DB $db = null): string
    {
        return ap_get_locale($db);
    }
}

/**
 * Set or clear the request locale override.
 *
 * @see AP_L10n::setLocale()
 */
function ap_set_locale(?string $locale): void
{
    AP_L10n::setLocale($locale);
}

/**
 * Whether the current (or given) locale is right-to-left.
 *
 * @see AP_L10n::isRtl()
 */
function ap_is_rtl(string $locale = ''): bool
{
    return AP_L10n::isRtl($locale);
}

/**
 * WordPress-compatible alias of {@see ap_is_rtl()}.
 */
if (!function_exists('is_rtl')) {
    function is_rtl(string $locale = ''): bool
    {
        return ap_is_rtl($locale);
    }
}

/**
 * Text direction for the current locale: "rtl" or "ltr".
 */
function ap_get_text_direction(string $locale = ''): string
{
    return AP_L10n::textDirection($locale);
}

/**
 * HTML lang attribute value (BCP 47), e.g. en-US.
 */
function ap_get_html_lang(string $locale = ''): string
{
    return AP_L10n::localeToHtmlLang($locale);
}

/**
 * Open Graph og:locale value, e.g. en_US.
 */
function ap_get_og_locale(string $locale = ''): string
{
    return AP_L10n::localeToOgLocale($locale);
}

/**
 * Attribute string for the root HTML element (lang + dir).
 *
 * @see AP_L10n::languageAttributes()
 */
function ap_get_language_attributes(string $doctype = 'html'): string
{
    return AP_L10n::languageAttributes($doctype);
}

/**
 * Echo language attributes for the root HTML element.
 */
function ap_language_attributes(string $doctype = 'html'): void
{
    echo AP_L10n::languageAttributes($doctype);
}

/**
 * Load a .mo file into a text domain.
 *
 * @see AP_L10n::loadTextdomain()
 */
function ap_load_textdomain(string $domain, string $mofile): bool
{
    return AP_L10n::loadTextdomain($domain, $mofile);
}

/**
 * WordPress-compatible alias of {@see ap_load_textdomain()}.
 */
if (!function_exists('load_textdomain')) {
    function load_textdomain(string $domain, string $mofile): bool
    {
        return ap_load_textdomain($domain, $mofile);
    }
}

/**
 * Unload a text domain (or all when empty).
 */
function ap_unload_textdomain(string $domain = ''): void
{
    AP_L10n::unloadTextdomain($domain);
}

/**
 * Load the default core text domain for the active locale.
 */
function ap_load_default_textdomain(): bool
{
    return AP_L10n::loadDefaultTextdomain();
}

/**
 * Load a plugin text domain.
 *
 * @see AP_L10n::loadPluginTextdomain()
 */
function ap_load_plugin_textdomain(string $domain, string $pluginRelPath = ''): bool
{
    return AP_L10n::loadPluginTextdomain($domain, $pluginRelPath);
}

/**
 * WordPress-compatible alias of {@see ap_load_plugin_textdomain()}.
 */
if (!function_exists('load_plugin_textdomain')) {
    function load_plugin_textdomain(string $domain, string|false $deprecated = false, string $pluginRelPath = ''): bool
    {
        // WP signature: ($domain, $deprecated, $plugin_rel_path)
        if (is_string($deprecated) && $deprecated !== '' && $pluginRelPath === '') {
            $pluginRelPath = $deprecated;
        }

        return ap_load_plugin_textdomain($domain, $pluginRelPath);
    }
}

/**
 * Load a theme text domain.
 *
 * @see AP_L10n::loadThemeTextdomain()
 */
function ap_load_theme_textdomain(string $domain, string $path = ''): bool
{
    return AP_L10n::loadThemeTextdomain($domain, $path);
}

/**
 * WordPress-compatible alias of {@see ap_load_theme_textdomain()}.
 */
if (!function_exists('load_theme_textdomain')) {
    function load_theme_textdomain(string $domain, string $path = ''): bool
    {
        return ap_load_theme_textdomain($domain, $path);
    }
}

/**
 * Retrieve the translation of $text.
 *
 * @see AP_L10n::translate()
 */
function ap__(string $text, string $domain = 'default'): string
{
    return AP_L10n::translate($text, $domain);
}

/**
 * Echo the translation of $text.
 */
function ap_e(string $text, string $domain = 'default'): void
{
    echo AP_L10n::translate($text, $domain);
}

/**
 * Retrieve the translation of $text (WordPress-compatible).
 */
if (!function_exists('__')) {
    function __(string $text, string $domain = 'default'): string
    {
        return AP_L10n::translate($text, $domain);
    }
}

/**
 * Echo the translation of $text (WordPress-compatible).
 */
if (!function_exists('_e')) {
    function _e(string $text, string $domain = 'default'): void
    {
        echo AP_L10n::translate($text, $domain);
    }
}

/**
 * Translate with context.
 */
function ap_x(string $text, string $context, string $domain = 'default'): string
{
    return AP_L10n::translateWithContext($text, $context, $domain);
}

/**
 * Translate with context (WordPress-compatible).
 */
if (!function_exists('_x')) {
    function _x(string $text, string $context, string $domain = 'default'): string
    {
        return AP_L10n::translateWithContext($text, $context, $domain);
    }
}

/**
 * Echo translation with context.
 */
function ap_ex(string $text, string $context, string $domain = 'default'): void
{
    echo AP_L10n::translateWithContext($text, $context, $domain);
}

/**
 * Echo translation with context (WordPress-compatible).
 */
if (!function_exists('_ex')) {
    function _ex(string $text, string $context, string $domain = 'default'): void
    {
        echo AP_L10n::translateWithContext($text, $context, $domain);
    }
}

/**
 * Plural translation.
 */
function ap_n(string $single, string $plural, int $number, string $domain = 'default'): string
{
    return AP_L10n::translatePlural($single, $plural, $number, $domain);
}

/**
 * Plural translation (WordPress-compatible).
 */
if (!function_exists('_n')) {
    function _n(string $single, string $plural, int $number, string $domain = 'default'): string
    {
        return AP_L10n::translatePlural($single, $plural, $number, $domain);
    }
}

/**
 * Plural translation with context.
 */
function ap_nx(
    string $single,
    string $plural,
    int $number,
    string $context,
    string $domain = 'default'
): string {
    return AP_L10n::translatePluralWithContext($single, $plural, $number, $context, $domain);
}

/**
 * Plural translation with context (WordPress-compatible).
 */
if (!function_exists('_nx')) {
    function _nx(
        string $single,
        string $plural,
        int $number,
        string $context,
        string $domain = 'default'
    ): string {
        return AP_L10n::translatePluralWithContext($single, $plural, $number, $context, $domain);
    }
}

/**
 * Translate and escape for HTML body.
 */
function ap_esc_html__(string $text, string $domain = 'default'): string
{
    return ap_esc_html(AP_L10n::translate($text, $domain));
}

/**
 * Translate and escape for HTML body (WordPress-compatible).
 */
if (!function_exists('esc_html__')) {
    function esc_html__(string $text, string $domain = 'default'): string
    {
        return ap_esc_html__($text, $domain);
    }
}

/**
 * Translate, escape for HTML, and echo.
 */
function ap_esc_html_e(string $text, string $domain = 'default'): void
{
    echo ap_esc_html__($text, $domain);
}

/**
 * Translate, escape for HTML, and echo (WordPress-compatible).
 */
if (!function_exists('esc_html_e')) {
    function esc_html_e(string $text, string $domain = 'default'): void
    {
        echo ap_esc_html__($text, $domain);
    }
}

/**
 * Translate and escape for HTML attributes.
 */
function ap_esc_attr__(string $text, string $domain = 'default'): string
{
    return ap_esc_attr(AP_L10n::translate($text, $domain));
}

/**
 * Translate and escape for HTML attributes (WordPress-compatible).
 */
if (!function_exists('esc_attr__')) {
    function esc_attr__(string $text, string $domain = 'default'): string
    {
        return ap_esc_attr__($text, $domain);
    }
}

/**
 * Translate, escape for attributes, and echo.
 */
function ap_esc_attr_e(string $text, string $domain = 'default'): void
{
    echo ap_esc_attr__($text, $domain);
}

/**
 * Translate, escape for attributes, and echo (WordPress-compatible).
 */
if (!function_exists('esc_attr_e')) {
    function esc_attr_e(string $text, string $domain = 'default'): void
    {
        echo ap_esc_attr__($text, $domain);
    }
}

/**
 * Translate with context and escape for HTML.
 */
function ap_esc_html_x(string $text, string $context, string $domain = 'default'): string
{
    return ap_esc_html(AP_L10n::translateWithContext($text, $context, $domain));
}

/**
 * Translate with context and escape for HTML (WordPress-compatible).
 */
if (!function_exists('esc_html_x')) {
    function esc_html_x(string $text, string $context, string $domain = 'default'): string
    {
        return ap_esc_html_x($text, $context, $domain);
    }
}

/**
 * Translate with context and escape for attributes.
 */
function ap_esc_attr_x(string $text, string $context, string $domain = 'default'): string
{
    return ap_esc_attr(AP_L10n::translateWithContext($text, $context, $domain));
}

/**
 * Translate with context and escape for attributes (WordPress-compatible).
 */
if (!function_exists('esc_attr_x')) {
    function esc_attr_x(string $text, string $context, string $domain = 'default'): string
    {
        return ap_esc_attr_x($text, $context, $domain);
    }
}

// -------------------------------------------------------------------------
// REST API
// -------------------------------------------------------------------------

/**
 * Whether the lightweight REST API is enabled.
 *
 * @see AP_Rest::isEnabled()
 */
function ap_rest_enabled(?AP_DB $db = null): bool
{
    if (!class_exists('AP_Rest', false)) {
        return false;
    }

    return AP_Rest::isEnabled($db);
}

/**
 * Public URL for a REST route (pretty /ap-json/… or plain ?rest_route=).
 *
 * @see AP_Rest::getUrl()
 */
function ap_rest_url(string $route = '/', ?AP_DB $db = null): string
{
    if (!class_exists('AP_Rest', false)) {
        return '';
    }

    return AP_Rest::getUrl($route, $db);
}

/**
 * Register a REST route under a namespace.
 *
 * @param array<string, mixed> $args
 *
 * @see AP_Rest::registerRoute()
 */
function ap_register_rest_route(string $namespace, string $route, array $args): void
{
    if (!class_exists('AP_Rest', false)) {
        return;
    }
    AP_Rest::registerRoute($namespace, $route, $args);
}

/**
 * Dispatch a REST request (no HTTP emit). For tests and internal use.
 *
 * @param array<string, mixed> $input
 *
 * @return array{status: int, data: mixed, headers: array<string, string>}
 *
 * @see AP_Rest::dispatch()
 */
function ap_rest_dispatch(array $input = [], ?AP_DB $db = null): array
{
    if (!class_exists('AP_Rest', false)) {
        return [
            'status' => 503,
            'data' => [
                'code' => 'rest_unavailable',
                'message' => 'REST API is not loaded.',
                'data' => ['status' => 503],
            ],
            'headers' => [],
        ];
    }

    return AP_Rest::dispatch($input, $db);
}

/**
 * Create a REST nonce for cookie-authenticated write requests (action ap_rest).
 */
function ap_create_rest_nonce(?int $userId = null): string
{
    if (!function_exists('ap_create_nonce')) {
        return '';
    }

    return ap_create_nonce(
        class_exists('AP_Rest', false) ? AP_Rest::NONCE_ACTION : 'ap_rest',
        $userId
    );
}
