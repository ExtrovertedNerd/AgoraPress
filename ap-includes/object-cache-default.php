<?php

/**
 * Default procedural Object Cache API (in-memory AP_Object_Cache).
 *
 * Loaded only when no content-dir `object-cache.php` drop-in defined
 * `ap_cache_init`. Each function is guarded so partial drop-ins can still
 * override individual helpers.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

if (!function_exists('ap_cache_init')) {
    /**
     * Initialize the global object cache instance.
     */
    function ap_cache_init(): void
    {
        $GLOBALS['ap_object_cache'] = new AP_Object_Cache();
    }
}

if (!function_exists('ap_cache_get')) {
    /**
     * Retrieve a value from the object cache.
     *
     * @param bool|null $found
     *
     * @return mixed
     */
    function ap_cache_get(string|int $key, string $group = 'default', bool $force = false, ?bool &$found = null): mixed
    {
        $cache = ap_object_cache_instance();
        if ($cache === null) {
            $found = false;

            return false;
        }

        return $cache->get($key, $group, $force, $found);
    }
}

if (!function_exists('ap_cache_set')) {
    /**
     * Store a value in the object cache.
     *
     * @param mixed $data
     */
    function ap_cache_set(string|int $key, mixed $data, string $group = 'default', int $expire = 0): bool
    {
        $cache = ap_object_cache_instance();
        if ($cache === null) {
            return false;
        }

        return $cache->set($key, $data, $group, $expire);
    }
}

if (!function_exists('ap_cache_add')) {
    /**
     * Add a value only if the key does not exist.
     *
     * @param mixed $data
     */
    function ap_cache_add(string|int $key, mixed $data, string $group = 'default', int $expire = 0): bool
    {
        $cache = ap_object_cache_instance();
        if ($cache === null) {
            return false;
        }

        return $cache->add($key, $data, $group, $expire);
    }
}

if (!function_exists('ap_cache_replace')) {
    /**
     * Replace a value only if the key already exists.
     *
     * @param mixed $data
     */
    function ap_cache_replace(string|int $key, mixed $data, string $group = 'default', int $expire = 0): bool
    {
        $cache = ap_object_cache_instance();
        if ($cache === null) {
            return false;
        }

        return $cache->replace($key, $data, $group, $expire);
    }
}

if (!function_exists('ap_cache_delete')) {
    /**
     * Delete a cache key.
     */
    function ap_cache_delete(string|int $key, string $group = 'default'): bool
    {
        $cache = ap_object_cache_instance();
        if ($cache === null) {
            return false;
        }

        return $cache->delete($key, $group);
    }
}

if (!function_exists('ap_cache_flush')) {
    /**
     * Flush the entire object cache.
     */
    function ap_cache_flush(): bool
    {
        $cache = ap_object_cache_instance();
        if ($cache === null) {
            return false;
        }

        return $cache->flush();
    }
}

if (!function_exists('ap_cache_flush_group')) {
    /**
     * Flush a single cache group (supported by default backend; drop-ins may no-op).
     */
    function ap_cache_flush_group(string $group): bool
    {
        $cache = ap_object_cache_instance();
        if ($cache === null) {
            return false;
        }

        if (method_exists($cache, 'flushGroup')) {
            return (bool) $cache->flushGroup($group);
        }

        return false;
    }
}

if (!function_exists('ap_cache_incr')) {
    /**
     * Increment a numeric cache value.
     *
     * @return int|false
     */
    function ap_cache_incr(string|int $key, int $offset = 1, string $group = 'default'): int|false
    {
        $cache = ap_object_cache_instance();
        if ($cache === null || !method_exists($cache, 'incr')) {
            return false;
        }

        return $cache->incr($key, $offset, $group);
    }
}

if (!function_exists('ap_cache_decr')) {
    /**
     * Decrement a numeric cache value (floors at 0).
     *
     * @return int|false
     */
    function ap_cache_decr(string|int $key, int $offset = 1, string $group = 'default'): int|false
    {
        $cache = ap_object_cache_instance();
        if ($cache === null || !method_exists($cache, 'decr')) {
            return false;
        }

        return $cache->decr($key, $offset, $group);
    }
}

if (!function_exists('ap_cache_close')) {
    /**
     * Close the object cache connection (no-op for in-memory).
     */
    function ap_cache_close(): bool
    {
        $cache = ap_object_cache_instance();
        if ($cache === null) {
            return true;
        }

        if (method_exists($cache, 'close')) {
            return (bool) $cache->close();
        }

        return true;
    }
}

if (!function_exists('ap_cache_add_global_groups')) {
    /**
     * @param list<string>|string $groups
     */
    function ap_cache_add_global_groups(array|string $groups): void
    {
        $cache = ap_object_cache_instance();
        if ($cache !== null && method_exists($cache, 'addGlobalGroups')) {
            $cache->addGlobalGroups($groups);
        }
    }
}

if (!function_exists('ap_cache_add_non_persistent_groups')) {
    /**
     * @param list<string>|string $groups
     */
    function ap_cache_add_non_persistent_groups(array|string $groups): void
    {
        $cache = ap_object_cache_instance();
        if ($cache !== null && method_exists($cache, 'addNonPersistentGroups')) {
            $cache->addNonPersistentGroups($groups);
        }
    }
}
