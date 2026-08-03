<?php

/**
 * AgoraPress Transients API.
 *
 * Temporary key/value storage with optional TTL. Backed by the Options table
 * (WordPress-compatible `_transient_{name}` / `_transient_timeout_{name}` keys)
 * when no external object cache is active. With a content-dir object-cache
 * drop-in, values are stored in the object cache (group `transient`) instead.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * Get / set / delete expiring site values.
 */
class AP_Transient
{
    private const PREFIX = '_transient_';

    private const TIMEOUT_PREFIX = '_transient_timeout_';

    /** Max length of the transient name (after sanitize). */
    private const MAX_NAME_LENGTH = 172;

    /**
     * Read a transient. Returns false when missing or expired.
     *
     * @param mixed $default Returned when missing/expired (default false, WP-style).
     *
     * @return mixed
     */
    public static function get(string $name, mixed $default = false, ?AP_DB $db = null): mixed
    {
        $name = self::normalizeName($name);
        if ($name === '') {
            return $default;
        }

        if (self::usesObjectCache()) {
            $found = false;
            $value = ap_cache_get($name, 'transient', false, $found);
            if (!$found) {
                return $default;
            }

            return $value;
        }

        if (self::isExpired($name, $db)) {
            self::delete($name, $db);

            return $default;
        }

        $value = AP_Options::get(self::PREFIX . $name, self::miss(), $db);
        if ($value === self::miss()) {
            return $default;
        }

        return $value;
    }

    /**
     * Store a transient.
     *
     * @param mixed $value      Scalar, array, or object (JSON via Options API).
     * @param int   $expiration Lifetime in seconds; 0 = no expiry.
     */
    public static function set(string $name, mixed $value, int $expiration = 0, ?AP_DB $db = null): bool
    {
        $name = self::normalizeName($name);
        if ($name === '') {
            return false;
        }

        $expiration = max(0, $expiration);

        if (self::usesObjectCache()) {
            return ap_cache_set($name, $value, 'transient', $expiration);
        }

        if ($expiration > 0) {
            $timeout = time() + $expiration;
            if (!AP_Options::update(self::TIMEOUT_PREFIX . $name, (string) $timeout, $db, 'no')) {
                return false;
            }
        } else {
            // Persistent until deleted — remove any prior timeout row.
            AP_Options::delete(self::TIMEOUT_PREFIX . $name, $db);
        }

        return AP_Options::update(self::PREFIX . $name, $value, $db, 'no');
    }

    /**
     * Delete a transient and its timeout companion.
     */
    public static function delete(string $name, ?AP_DB $db = null): bool
    {
        $name = self::normalizeName($name);
        if ($name === '') {
            return false;
        }

        if (self::usesObjectCache()) {
            return ap_cache_delete($name, 'transient');
        }

        $ok = AP_Options::delete(self::PREFIX . $name, $db);
        AP_Options::delete(self::TIMEOUT_PREFIX . $name, $db);

        return $ok;
    }

    /**
     * Whether a transient exists and is not expired.
     */
    public static function exists(string $name, ?AP_DB $db = null): bool
    {
        $name = self::normalizeName($name);
        if ($name === '') {
            return false;
        }

        if (self::usesObjectCache()) {
            $found = false;
            ap_cache_get($name, 'transient', false, $found);

            return $found;
        }

        if (self::isExpired($name, $db)) {
            return false;
        }

        $value = AP_Options::get(self::PREFIX . $name, self::miss(), $db);

        return $value !== self::miss();
    }

    /**
     * Seconds remaining until expiry, 0 when no timeout, false when missing/expired.
     *
     * When an external object cache is active, TTL is not tracked separately
     * (backends own expiry). Returns 0 if the key exists, false if missing.
     *
     * @return int|false
     */
    public static function ttl(string $name, ?AP_DB $db = null): int|false
    {
        $name = self::normalizeName($name);
        if ($name === '') {
            return false;
        }

        if (self::usesObjectCache()) {
            $found = false;
            ap_cache_get($name, 'transient', false, $found);

            return $found ? 0 : false;
        }

        if (AP_Options::get(self::PREFIX . $name, self::miss(), $db) === self::miss()) {
            return false;
        }

        $timeoutRaw = AP_Options::get(self::TIMEOUT_PREFIX . $name, null, $db);
        if ($timeoutRaw === null || $timeoutRaw === false || $timeoutRaw === '') {
            return 0;
        }

        $timeout = (int) $timeoutRaw;
        $remaining = $timeout - time();
        if ($remaining <= 0) {
            return false;
        }

        return $remaining;
    }

    /**
     * Option name used to store the value (for debugging / advanced use).
     */
    public static function optionName(string $name): string
    {
        $name = self::normalizeName($name);

        return $name === '' ? '' : self::PREFIX . $name;
    }

    /**
     * Option name used to store the expiry timestamp.
     */
    public static function timeoutOptionName(string $name): string
    {
        $name = self::normalizeName($name);

        return $name === '' ? '' : self::TIMEOUT_PREFIX . $name;
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * Whether transients should use the external object cache (drop-in).
     * Default in-memory cache is non-persistent, so options storage remains.
     */
    private static function usesObjectCache(): bool
    {
        return function_exists('ap_using_ext_object_cache')
            && ap_using_ext_object_cache()
            && function_exists('ap_cache_get')
            && function_exists('ap_cache_set')
            && function_exists('ap_cache_delete');
    }

    private static function isExpired(string $name, ?AP_DB $db): bool
    {
        $timeoutRaw = AP_Options::get(self::TIMEOUT_PREFIX . $name, null, $db);
        if ($timeoutRaw === null || $timeoutRaw === false || $timeoutRaw === '') {
            // No timeout row: either never expires, or value was never set with TTL.
            return false;
        }

        return (int) $timeoutRaw <= time();
    }

    private static function normalizeName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '';
        }
        // Allow alphanumerics, underscore, hyphen — strip other chars.
        $name = preg_replace('/[^a-zA-Z0-9_\-]/', '', $name) ?? '';
        if ($name === '' || strlen($name) > self::MAX_NAME_LENGTH) {
            return '';
        }

        return $name;
    }

    /**
     * Internal sentinel distinct from false/null user values.
     */
    private static function miss(): string
    {
        return "\0ap_transient_miss";
    }
}
