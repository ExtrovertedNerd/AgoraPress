<?php

/**
 * AgoraPress public registration, email verification, and password reset.
 *
 * Uses existing users columns:
 * - user_status: 0 = active, {@see self::STATUS_PENDING} = awaiting email verification
 * - user_activation_key: purpose:timestamp:hmac (never store the raw URL key)
 *
 * Gated by options:
 * - users_can_register (0/1)
 * - require_email_verification (0/1, default 1)
 * - default_role (seeded by installer)
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * Registration / verification / password-reset workflows.
 */
class AP_Registration
{
    /** user_status while waiting for email confirmation. */
    public const STATUS_PENDING = 1;

    /** Activation / reset key lifetime (24 hours). */
    public const KEY_TTL = 86400;

    /** Minimum seconds between password-reset emails for the same account. */
    public const RESET_COOLDOWN = 60;

    /** Key purpose: email verification after registration. */
    public const PURPOSE_ACTIVATE = 'activate';

    /** Key purpose: password reset. */
    public const PURPOSE_RESET = 'reset';

    /**
     * Whether anyone may register (option users_can_register).
     */
    public static function usersCanRegister(?AP_DB $db = null): bool
    {
        return self::optionIsTruthy('users_can_register', false, $db);
    }

    /**
     * Whether new public registrations must verify email before login.
     * Defaults to true when the option is missing (secure default).
     */
    public static function requireEmailVerification(?AP_DB $db = null): bool
    {
        return self::optionIsTruthy('require_email_verification', true, $db);
    }

    /**
     * Register a new public account.
     *
     * Required keys: user_login, user_email, user_pass (or password).
     * Optional: display_name.
     *
     * When email verification is required the account is created with
     * STATUS_PENDING and a verification email is sent. When not required the
     * account is active immediately (no activation email).
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
     */
    public static function register(array $data, ?AP_DB $db = null): array
    {
        $empty = [
            'ok' => false,
            'id' => 0,
            'errors' => [],
            'user' => null,
            'needs_verification' => false,
            'plain_key' => '',
        ];

        if (!self::usersCanRegister($db)) {
            $empty['errors'][] = 'Registration is currently closed.';

            return $empty;
        }

        // IP rate limit (registration floods / bot sign-ups).
        if (class_exists('AP_Rate_Limit', false)) {
            $gate = AP_Rate_Limit::check(
                AP_Rate_Limit::ACTION_REGISTER,
                AP_Rate_Limit::ipBucket(),
                $db
            );
            if (!$gate['allowed']) {
                $empty['errors'][] = AP_Rate_Limit::lockoutMessage(
                    (int) $gate['retry_after'],
                    'try registering again'
                );

                return $empty;
            }
        }

        $needsVerification = self::requireEmailVerification($db);
        $payload = $data;
        // Public registration always uses default_role (ignore client-supplied role).
        unset($payload['role']);
        $payload['user_status'] = $needsVerification ? self::STATUS_PENDING : 0;

        $result = AP_User::create($payload, $db);
        if (!$result['ok'] || $result['user'] === null) {
            // Count failed attempts too (enumeration / spam of taken logins).
            if (class_exists('AP_Rate_Limit', false)) {
                AP_Rate_Limit::hit(
                    AP_Rate_Limit::ACTION_REGISTER,
                    AP_Rate_Limit::ipBucket(),
                    $db
                );
            }

            return [
                'ok' => false,
                'id' => 0,
                'errors' => $result['errors'],
                'user' => null,
                'needs_verification' => false,
                'plain_key' => '',
            ];
        }

        if (class_exists('AP_Rate_Limit', false)) {
            AP_Rate_Limit::hit(
                AP_Rate_Limit::ACTION_REGISTER,
                AP_Rate_Limit::ipBucket(),
                $db
            );
        }

        /** @var AP_User $user */
        $user = $result['user'];
        $plainKey = '';

        if ($needsVerification) {
            $plainKey = self::issueKey($user, self::PURPOSE_ACTIVATE, $db);
            if ($plainKey === '') {
                // Roll back on key failure so we do not leave a stuck pending user.
                AP_User::delete($user->ID, $db);

                return [
                    'ok' => false,
                    'id' => 0,
                    'errors' => ['Could not prepare account verification. Please try again.'],
                    'user' => null,
                    'needs_verification' => false,
                    'plain_key' => '',
                ];
            }

            self::sendVerificationEmail($user, $plainKey, $db);
        }

        return [
            'ok' => true,
            'id' => $user->ID,
            'errors' => [],
            'user' => $user,
            'needs_verification' => $needsVerification,
            'plain_key' => $plainKey,
        ];
    }

    /**
     * Confirm email with login + raw key from the verification link.
     *
     * @return array{ok: bool, errors: list<string>, user: ?AP_User}
     */
    public static function verifyEmail(string $login, string $plainKey, ?AP_DB $db = null): array
    {
        $login = trim($login);
        $plainKey = trim($plainKey);
        if ($login === '' || $plainKey === '') {
            return ['ok' => false, 'errors' => ['Invalid verification link.'], 'user' => null];
        }

        $user = AP_User::getByLogin($login, $db);
        if ($user === null) {
            return ['ok' => false, 'errors' => ['Invalid verification link.'], 'user' => null];
        }

        if ($user->user_status === 0 && $user->user_activation_key === '') {
            // Already verified — treat as success (idempotent).
            return ['ok' => true, 'errors' => [], 'user' => $user];
        }

        if (!self::validateKey($user, $plainKey, self::PURPOSE_ACTIVATE)) {
            return [
                'ok' => false,
                'errors' => ['This verification link is invalid or has expired.'],
                'user' => null,
            ];
        }

        $db = self::resolveDb($db);
        $updated = $db->update(
            'users',
            [
                'user_status' => 0,
                'user_activation_key' => '',
            ],
            ['ID' => $user->ID]
        );
        if ($updated === false) {
            return [
                'ok' => false,
                'errors' => ['Could not activate the account. Please try again.'],
                'user' => null,
            ];
        }

        $user->user_status = 0;
        $user->user_activation_key = '';

        return ['ok' => true, 'errors' => [], 'user' => $user];
    }

    /**
     * Start a password reset: issue key and email when the account exists and is active.
     *
     * Always returns ok=true with a generic message path so callers do not leak
     * whether an email/login is registered. The `sent` flag is for tests only.
     *
     * @return array{
     *     ok: bool,
     *     errors: list<string>,
     *     sent: bool,
     *     plain_key: string,
     *     user: ?AP_User
     * }
     */
    public static function requestPasswordReset(string $loginOrEmail, ?AP_DB $db = null): array
    {
        $loginOrEmail = trim($loginOrEmail);
        $generic = [
            'ok' => true,
            'errors' => [],
            'sent' => false,
            'plain_key' => '',
            'user' => null,
        ];

        if ($loginOrEmail === '') {
            return [
                'ok' => false,
                'errors' => ['Please enter your username or email address.'],
                'sent' => false,
                'plain_key' => '',
                'user' => null,
            ];
        }

        // IP throttle for reset form abuse (still returns generic success after).
        if (class_exists('AP_Rate_Limit', false)) {
            $gate = AP_Rate_Limit::check(
                AP_Rate_Limit::ACTION_PASSWORD_RESET,
                AP_Rate_Limit::ipBucket(),
                $db
            );
            if (!$gate['allowed']) {
                // Same generic success path so lockouts do not leak account state.
                return $generic;
            }
            AP_Rate_Limit::hit(
                AP_Rate_Limit::ACTION_PASSWORD_RESET,
                AP_Rate_Limit::ipBucket(),
                $db
            );
        }

        $user = AP_User::getByLogin($loginOrEmail, $db);
        if ($user === null && str_contains($loginOrEmail, '@')) {
            $user = AP_User::getByEmail($loginOrEmail, $db);
        }

        // Unknown account or pending verification: still pretend success.
        if ($user === null || $user->user_status !== 0) {
            return $generic;
        }

        // Cooldown to limit reset email flooding.
        $last = AP_User::getMeta($user->ID, 'ap_password_reset_sent', $db);
        if (is_string($last) && $last !== '' && ctype_digit($last)) {
            $elapsed = time() - (int) $last;
            if ($elapsed >= 0 && $elapsed < self::RESET_COOLDOWN) {
                return $generic;
            }
        }

        $plainKey = self::issueKey($user, self::PURPOSE_RESET, $db);
        if ($plainKey === '') {
            return $generic;
        }

        AP_User::updateMeta($user->ID, 'ap_password_reset_sent', (string) time(), $db);
        self::sendPasswordResetEmail($user, $plainKey, $db);

        return [
            'ok' => true,
            'errors' => [],
            'sent' => true,
            'plain_key' => $plainKey,
            'user' => $user,
        ];
    }

    /**
     * Validate a password-reset key without consuming it.
     */
    public static function checkPasswordResetKey(
        string $login,
        string $plainKey,
        ?AP_DB $db = null
    ): ?AP_User {
        $login = trim($login);
        $plainKey = trim($plainKey);
        if ($login === '' || $plainKey === '') {
            return null;
        }

        $user = AP_User::getByLogin($login, $db);
        if ($user === null || $user->user_status !== 0) {
            return null;
        }

        if (!self::validateKey($user, $plainKey, self::PURPOSE_RESET)) {
            return null;
        }

        return $user;
    }

    /**
     * Complete password reset with a valid key.
     *
     * @return array{ok: bool, errors: list<string>, user: ?AP_User}
     */
    public static function resetPassword(
        string $login,
        string $plainKey,
        string $newPassword,
        ?AP_DB $db = null
    ): array {
        $user = self::checkPasswordResetKey($login, $plainKey, $db);
        if ($user === null) {
            return [
                'ok' => false,
                'errors' => ['This password reset link is invalid or has expired.'],
                'user' => null,
            ];
        }

        if ($newPassword === '') {
            return ['ok' => false, 'errors' => ['Password is required.'], 'user' => null];
        }
        if (strlen($newPassword) < 8) {
            return [
                'ok' => false,
                'errors' => ['Password must be at least 8 characters.'],
                'user' => null,
            ];
        }

        if (!$user->updatePassword($newPassword, $db)) {
            return [
                'ok' => false,
                'errors' => ['Could not update the password. Please try again.'],
                'user' => null,
            ];
        }

        // Invalidate the reset key after successful use.
        $db = self::resolveDb($db);
        $db->update('users', ['user_activation_key' => ''], ['ID' => $user->ID]);
        $user->user_activation_key = '';
        AP_User::deleteMeta($user->ID, 'ap_password_reset_sent', $db);

        // Re-fetch so callers see the new hash / clean key.
        $fresh = AP_User::getById($user->ID, $db);

        return ['ok' => true, 'errors' => [], 'user' => $fresh];
    }

    /**
     * Issue a one-time key for the given purpose and store its HMAC on the user.
     *
     * @return string Raw key for the email URL, or empty string on failure.
     */
    public static function issueKey(AP_User $user, string $purpose, ?AP_DB $db = null): string
    {
        if ($user->ID < 1) {
            return '';
        }
        if ($purpose !== self::PURPOSE_ACTIVATE && $purpose !== self::PURPOSE_RESET) {
            return '';
        }

        try {
            $plain = bin2hex(random_bytes(32));
        } catch (Throwable) {
            return '';
        }

        $timestamp = time();
        $hmac = self::hashKey($plain, $purpose, $user->ID, $timestamp);
        $stored = $purpose . ':' . $timestamp . ':' . $hmac;

        $db = self::resolveDb($db);
        $ok = $db->update(
            'users',
            ['user_activation_key' => $stored],
            ['ID' => $user->ID]
        );
        if ($ok === false) {
            return '';
        }

        $user->user_activation_key = $stored;

        return $plain;
    }

    /**
     * Validate a raw key against the stored activation field.
     */
    public static function validateKey(AP_User $user, string $plainKey, string $purpose): bool
    {
        $plainKey = trim($plainKey);
        if ($plainKey === '' || $user->user_activation_key === '') {
            return false;
        }

        $parts = explode(':', $user->user_activation_key, 3);
        if (count($parts) !== 3) {
            return false;
        }

        [$storedPurpose, $tsRaw, $storedHmac] = $parts;
        if ($storedPurpose !== $purpose) {
            return false;
        }
        if (!ctype_digit($tsRaw)) {
            return false;
        }
        $timestamp = (int) $tsRaw;
        if ($timestamp < 1 || (time() - $timestamp) > self::KEY_TTL) {
            return false;
        }

        $expected = self::hashKey($plainKey, $purpose, $user->ID, $timestamp);

        return hash_equals($storedHmac, $expected);
    }

    /**
     * Absolute URL for email verification.
     */
    public static function verificationUrl(AP_User $user, string $plainKey, ?AP_DB $db = null): string
    {
        return self::loginActionUrl(
            'verifyemail',
            [
                'login' => $user->user_login,
                'key' => $plainKey,
            ],
            $db
        );
    }

    /**
     * Absolute URL for password reset form.
     */
    public static function passwordResetUrl(AP_User $user, string $plainKey, ?AP_DB $db = null): string
    {
        return self::loginActionUrl(
            'rp',
            [
                'login' => $user->user_login,
                'key' => $plainKey,
            ],
            $db
        );
    }

    /**
     * Send the email-verification message.
     */
    public static function sendVerificationEmail(
        AP_User $user,
        string $plainKey,
        ?AP_DB $db = null
    ): bool {
        $site = self::siteName($db);
        $url = self::verificationUrl($user, $plainKey, $db);
        $subject = sprintf('[%s] Confirm your email', $site);
        $message = "Hello {$user->user_login},\r\n\r\n"
            . "Thank you for registering at {$site}.\r\n\r\n"
            . "Please confirm your email address by visiting this link:\r\n"
            . "{$url}\r\n\r\n"
            . "This link expires in 24 hours.\r\n\r\n"
            . "If you did not register, you can ignore this email.\r\n";

        return AP_Mail::send($user->user_email, $subject, $message);
    }

    /**
     * Send the password-reset message.
     */
    public static function sendPasswordResetEmail(
        AP_User $user,
        string $plainKey,
        ?AP_DB $db = null
    ): bool {
        $site = self::siteName($db);
        $url = self::passwordResetUrl($user, $plainKey, $db);
        $subject = sprintf('[%s] Password reset', $site);
        $message = "Hello {$user->user_login},\r\n\r\n"
            . "Someone requested a password reset for your account on {$site}.\r\n\r\n"
            . "To choose a new password, visit:\r\n"
            . "{$url}\r\n\r\n"
            . "This link expires in 24 hours.\r\n\r\n"
            . "If you did not request this, you can ignore this email."
            . " Your password will not change.\r\n";

        return AP_Mail::send($user->user_email, $subject, $message);
    }

    /**
     * Build ap-admin/login.php?action=… URL (absolute when siteurl known).
     *
     * @param array<string, string> $query
     */
    public static function loginActionUrl(string $action, array $query = [], ?AP_DB $db = null): string
    {
        $query = array_merge(['action' => $action], $query);

        if (class_exists('AP_Admin', false)) {
            return AP_Admin::url('login.php', $query);
        }

        $base = '';
        if (class_exists('AP_Rewrite', false)) {
            $site = AP_Rewrite::siteUrl('ap-admin/login.php', $db);
            if ($site !== '') {
                $base = $site;
            }
        }
        if ($base === '') {
            $site = (string) self::readOption('siteurl', '', $db);
            if ($site === '') {
                $site = (string) self::readOption('home', '', $db);
            }
            if ($site === '' && defined('AP_SITEURL') && is_string(AP_SITEURL) && AP_SITEURL !== '') {
                $site = (string) AP_SITEURL;
            }
            $base = $site !== ''
                ? rtrim($site, '/') . '/ap-admin/login.php'
                : '/ap-admin/login.php';
        }

        $qs = http_build_query($query);
        if ($qs === '') {
            return $base;
        }

        return $base . (str_contains($base, '?') ? '&' : '?') . $qs;
    }

    /**
     * HMAC of the raw key (never store the raw value).
     */
    private static function hashKey(
        string $plainKey,
        string $purpose,
        int $userId,
        int $timestamp
    ): string {
        $material = $purpose . '|' . $userId . '|' . $timestamp . '|' . $plainKey;

        return hash_hmac('sha256', $material, self::signingSecret());
    }

    private static function signingSecret(): string
    {
        $key = defined('AP_AUTH_KEY') ? (string) AP_AUTH_KEY : '';
        $salt = defined('AP_AUTH_SALT') ? (string) AP_AUTH_SALT : '';
        if ($key === '' && $salt === '') {
            // Fallback so unit tests without config still exercise the path.
            $key = defined('AP_LOGGED_IN_KEY') ? (string) AP_LOGGED_IN_KEY : 'agorapress-auth';
            $salt = defined('AP_LOGGED_IN_SALT') ? (string) AP_LOGGED_IN_SALT : 'agorapress-salt';
        }

        return $key . $salt;
    }

    private static function siteName(?AP_DB $db): string
    {
        $name = self::readOption('blogname', 'AgoraPress', $db);
        $name = trim((string) $name);

        return $name !== '' ? $name : 'AgoraPress';
    }

    private static function optionIsTruthy(
        string $name,
        bool $default,
        ?AP_DB $db
    ): bool {
        $raw = self::readOption($name, $default ? '1' : '0', $db);
        if (is_bool($raw)) {
            return $raw;
        }
        $s = strtolower(trim((string) $raw));

        return in_array($s, ['1', 'true', 'yes', 'on'], true);
    }

    private static function readOption(string $name, mixed $default, ?AP_DB $db): mixed
    {
        if (class_exists('AP_Options', false)) {
            return AP_Options::get($name, $default, $db);
        }

        // Direct DB read when Options API is not loaded (isolated tests).
        if ($db instanceof AP_DB) {
            try {
                $raw = $db->getVar(
                    'SELECT option_value FROM ' . $db->quoteIdentifier($db->table('options'))
                    . ' WHERE option_name = ? LIMIT 1',
                    [$name]
                );
                if ($raw !== null) {
                    return $raw;
                }
            } catch (Throwable) {
                // fall through
            }
        }

        return $default;
    }

    private static function resolveDb(?AP_DB $db): AP_DB
    {
        if ($db instanceof AP_DB) {
            return $db;
        }
        if (function_exists('ap_db')) {
            return ap_db();
        }

        throw new RuntimeException('No database connection available for registration.');
    }
}
