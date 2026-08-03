<?php

/**
 * AgoraPress Object Cache API.
 *
 * Default implementation is a request-local in-memory store (non-persistent).
 * Sites may drop in a persistent backend by placing `object-cache.php` under
 * the content directory (AP_CONTENT_DIR). The drop-in is loaded first and may
 * define `ap_cache_*` helpers; when it does, core marks an external object
 * cache as active (WordPress-style).
 *
 * Public surface (WP-inspired, ap_ prefix):
 *   ap_cache_add / ap_cache_set / ap_cache_get / ap_cache_delete
 *   ap_cache_replace / ap_cache_incr / ap_cache_decr
 *   ap_cache_flush / ap_cache_flush_group / ap_cache_close
 *   ap_cache_add_global_groups / ap_cache_add_non_persistent_groups
 *   ap_cache_init / ap_start_object_cache / ap_using_ext_object_cache
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * Default request-local object cache.
 *
 * Persistent backends (Redis, Memcached, APCu, …) replace this class and/or
 * the procedural `ap_cache_*` functions via the content-dir drop-in.
 */
class AP_Object_Cache
{
    /**
     * Cached values keyed by group, then key.
     *
     * Each entry: ['v' => mixed, 'e' => int] where e is unix expiry (0 = none).
     *
     * @var array<string, array<string, array{v: mixed, e: int}>>
     */
    private array $cache = [];

    /** @var array<string, true> Groups shared across blogs (multisite-ready). */
    private array $globalGroups = [];

    /** @var array<string, true> Groups that must not be persisted by drop-ins. */
    private array $nonPersistentGroups = [];

    /** Blog id prefix for non-global groups (0 = single-site / default). */
    private int $blogPrefix = 0;

    public int $cacheHits = 0;

    public int $cacheMisses = 0;

    /**
     * Add a value only when the key is not already present (and not expired).
     *
     * @param mixed $data
     */
    public function add(string|int $key, mixed $data, string $group = 'default', int $expire = 0): bool
    {
        if ($this->exists($key, $group)) {
            return false;
        }

        return $this->set($key, $data, $group, $expire);
    }

    /**
     * Set a cache value (create or overwrite).
     *
     * @param mixed $data
     */
    public function set(string|int $key, mixed $data, string $group = 'default', int $expire = 0): bool
    {
        $group = $this->normalizeGroup($group);
        $key = $this->normalizeKey($key);
        if ($key === '') {
            return false;
        }

        $bucket = $this->groupKey($group);
        $expire = max(0, $expire);

        $this->cache[$bucket][$key] = [
            'v' => $data,
            'e' => $expire > 0 ? time() + $expire : 0,
        ];

        return true;
    }

    /**
     * Get a cached value.
     *
     * @param bool|null $found Set to true/false when the key was present and valid.
     *
     * @return mixed False on miss (WP-style); use $found to distinguish false values.
     */
    public function get(string|int $key, string $group = 'default', bool $force = false, ?bool &$found = null): mixed
    {
        unset($force); // Reserved for remote backends that may re-fetch.

        $group = $this->normalizeGroup($group);
        $key = $this->normalizeKey($key);
        if ($key === '') {
            $found = false;
            ++$this->cacheMisses;

            return false;
        }

        $bucket = $this->groupKey($group);
        if (!isset($this->cache[$bucket][$key])) {
            $found = false;
            ++$this->cacheMisses;

            return false;
        }

        $entry = $this->cache[$bucket][$key];
        if ($entry['e'] > 0 && $entry['e'] <= time()) {
            unset($this->cache[$bucket][$key]);
            $found = false;
            ++$this->cacheMisses;

            return false;
        }

        $found = true;
        ++$this->cacheHits;

        return $entry['v'];
    }

    /**
     * Replace an existing key only.
     *
     * @param mixed $data
     */
    public function replace(string|int $key, mixed $data, string $group = 'default', int $expire = 0): bool
    {
        if (!$this->exists($key, $group)) {
            return false;
        }

        return $this->set($key, $data, $group, $expire);
    }

    /**
     * Delete a cache key.
     */
    public function delete(string|int $key, string $group = 'default'): bool
    {
        $group = $this->normalizeGroup($group);
        $key = $this->normalizeKey($key);
        if ($key === '') {
            return false;
        }

        $bucket = $this->groupKey($group);
        if (!isset($this->cache[$bucket][$key])) {
            return false;
        }

        unset($this->cache[$bucket][$key]);

        return true;
    }

    /**
     * Flush the entire cache.
     */
    public function flush(): bool
    {
        $this->cache = [];

        return true;
    }

    /**
     * Flush a single group.
     */
    public function flushGroup(string $group): bool
    {
        $group = $this->normalizeGroup($group);
        $bucket = $this->groupKey($group);
        unset($this->cache[$bucket]);

        return true;
    }

    /**
     * Increment a numeric value.
     *
     * @return int|false New value, or false when key missing / non-numeric.
     */
    public function incr(string|int $key, int $offset = 1, string $group = 'default'): int|false
    {
        return $this->adjust($key, $offset, $group);
    }

    /**
     * Decrement a numeric value (floors at 0).
     *
     * @return int|false New value, or false when key missing / non-numeric.
     */
    public function decr(string|int $key, int $offset = 1, string $group = 'default'): int|false
    {
        return $this->adjust($key, -abs($offset), $group);
    }

    /**
     * Mark groups as global (shared across blog prefixes).
     *
     * @param list<string>|string $groups
     */
    public function addGlobalGroups(array|string $groups): void
    {
        foreach ((array) $groups as $g) {
            $g = $this->normalizeGroup((string) $g);
            $this->globalGroups[$g] = true;
        }
    }

    /**
     * Mark groups as non-persistent (hint for external backends).
     *
     * @param list<string>|string $groups
     */
    public function addNonPersistentGroups(array|string $groups): void
    {
        foreach ((array) $groups as $g) {
            $g = $this->normalizeGroup((string) $g);
            $this->nonPersistentGroups[$g] = true;
        }
    }

    /**
     * Whether a group is marked non-persistent.
     */
    public function isNonPersistentGroup(string $group): bool
    {
        $group = $this->normalizeGroup($group);

        return isset($this->nonPersistentGroups[$group]);
    }

    /**
     * Switch blog prefix for non-global groups (no-op multisite readiness).
     */
    public function switchToBlog(int $blogId): bool
    {
        $this->blogPrefix = max(0, $blogId);

        return true;
    }

    /**
     * Close / disconnect (no-op for in-memory).
     */
    public function close(): bool
    {
        return true;
    }

    /**
     * Reset instance state (tests).
     */
    public function reset(): void
    {
        $this->cache = [];
        $this->globalGroups = [];
        $this->nonPersistentGroups = [];
        $this->blogPrefix = 0;
        $this->cacheHits = 0;
        $this->cacheMisses = 0;
    }

    /**
     * Whether a key is present and not expired (does not affect hit/miss stats).
     */
    public function exists(string|int $key, string $group = 'default'): bool
    {
        return $this->hasValidEntry($key, $group);
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    private function hasValidEntry(string|int $key, string $group): bool
    {
        $group = $this->normalizeGroup($group);
        $key = $this->normalizeKey($key);
        if ($key === '') {
            return false;
        }

        $bucket = $this->groupKey($group);
        if (!isset($this->cache[$bucket][$key])) {
            return false;
        }

        $entry = $this->cache[$bucket][$key];
        if ($entry['e'] > 0 && $entry['e'] <= time()) {
            unset($this->cache[$bucket][$key]);

            return false;
        }

        return true;
    }

    /**
     * @return int|false
     */
    private function adjust(string|int $key, int $delta, string $group): int|false
    {
        $group = $this->normalizeGroup($group);
        $key = $this->normalizeKey($key);
        if ($key === '') {
            return false;
        }

        if (!$this->hasValidEntry($key, $group)) {
            return false;
        }

        $bucket = $this->groupKey($group);
        $value = $this->cache[$bucket][$key]['v'];
        if (!is_numeric($value)) {
            return false;
        }

        $n = (int) $value + $delta;
        if ($n < 0) {
            $n = 0;
        }

        $this->cache[$bucket][$key]['v'] = $n;

        return $n;
    }

    private function groupKey(string $group): string
    {
        if (isset($this->globalGroups[$group]) || $this->blogPrefix === 0) {
            return $group;
        }

        return $this->blogPrefix . ':' . $group;
    }

    private function normalizeGroup(string $group): string
    {
        $group = trim($group);

        return $group === '' ? 'default' : $group;
    }

    private function normalizeKey(string|int $key): string
    {
        if (is_int($key)) {
            return (string) $key;
        }

        return trim($key);
    }
}

// -----------------------------------------------------------------------------
// Bootstrap / external flag
// -----------------------------------------------------------------------------

/**
 * Start the object cache: load content-dir drop-in when present, then ensure
 * default API is available and initialized.
 *
 * Drop-in is included *before* the default procedural API so it may define
 * `ap_cache_init` and the rest of the surface (WordPress-style).
 *
 * Safe to call once per request (idempotent).
 */
function ap_start_object_cache(): void
{
    static $started = false;
    if ($started) {
        return;
    }
    $started = true;

    $dropin = ap_object_cache_dropin_path();
    if ($dropin !== '' && is_readable($dropin)) {
        include_once $dropin;
        if (function_exists('ap_cache_init')) {
            ap_using_ext_object_cache(true);
        }
    }

    // Default procedural API only when the drop-in did not supply it.
    ap_ensure_object_cache_api();

    if (function_exists('ap_cache_init')) {
        ap_cache_init();
    }

    if (function_exists('ap_do_action')) {
        ap_do_action('ap_object_cache_started', ap_using_ext_object_cache());
    }
}

/**
 * Load default ap_cache_* helpers when not already defined (by a drop-in).
 */
function ap_ensure_object_cache_api(): void
{
    if (!function_exists('ap_cache_init')) {
        require_once __DIR__ . '/object-cache-default.php';
    }
}

/**
 * Absolute path to the object-cache drop-in, or empty when content dir unknown.
 */
function ap_object_cache_dropin_path(): string
{
    if (defined('AP_CONTENT_DIR') && is_string(AP_CONTENT_DIR) && AP_CONTENT_DIR !== '') {
        return rtrim(str_replace('\\', '/', AP_CONTENT_DIR), '/') . '/object-cache.php';
    }

    if (defined('AP_ABSPATH')) {
        return rtrim(str_replace('\\', '/', (string) AP_ABSPATH), '/') . '/ap-content/object-cache.php';
    }

    return '';
}

/**
 * Get or set whether an external (drop-in) object cache is in use.
 */
function ap_using_ext_object_cache(?bool $using = null): bool
{
    static $external = false;

    if ($using !== null) {
        $external = $using;
    }

    if (defined('AP_EXTERNAL_OBJECT_CACHE') && AP_EXTERNAL_OBJECT_CACHE) {
        return true;
    }

    return $external;
}

/**
 * Resolve the global object cache instance, or null when unavailable.
 *
 * Lazy-loads the default API and initializes when needed (unit tests / late use).
 */
function ap_object_cache_instance(): ?object
{
    ap_ensure_object_cache_api();

    if (!isset($GLOBALS['ap_object_cache']) || !is_object($GLOBALS['ap_object_cache'])) {
        if (function_exists('ap_cache_init')) {
            ap_cache_init();
        }
    }

    if (!isset($GLOBALS['ap_object_cache']) || !is_object($GLOBALS['ap_object_cache'])) {
        return null;
    }

    return $GLOBALS['ap_object_cache'];
}

/**
 * Reset object cache state (tests). Drops the global instance; clears the
 * external flag when $full is true.
 */
function ap_reset_object_cache(bool $full = false): void
{
    if (isset($GLOBALS['ap_object_cache']) && is_object($GLOBALS['ap_object_cache'])) {
        $cache = $GLOBALS['ap_object_cache'];
        if (method_exists($cache, 'reset')) {
            $cache->reset();
        } elseif (method_exists($cache, 'flush')) {
            $cache->flush();
        }
    }
    unset($GLOBALS['ap_object_cache']);

    if ($full) {
        ap_using_ext_object_cache(false);
    }

    ap_ensure_object_cache_api();
    if (function_exists('ap_cache_init')) {
        ap_cache_init();
    }
}
