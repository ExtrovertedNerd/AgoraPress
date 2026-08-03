<?php

/**
 * AgoraPress global hook registry.
 *
 * Used by the procedural API in hooks.php (ap_add_action, ap_apply_filters, …).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

require_once __DIR__ . '/class-ap-hook.php';

/**
 * Global hook registry and shared helpers.
 */
class AP_Hooks
{
    /**
     * @var array<string, AP_Hook>
     */
    private static array $hooks = [];

    /**
     * @var array<string, int>
     */
    private static array $actionsDone = [];

    /**
     * @var list<string>
     */
    private static array $current = [];

    public static function get(string $hook): AP_Hook
    {
        if (!isset(self::$hooks[$hook])) {
            self::$hooks[$hook] = new AP_Hook();
        }

        return self::$hooks[$hook];
    }

    public static function exists(string $hook): bool
    {
        return isset(self::$hooks[$hook]) && !self::$hooks[$hook]->isEmpty();
    }

    public static function maybeGet(string $hook): ?AP_Hook
    {
        return self::$hooks[$hook] ?? null;
    }

    public static function pushCurrent(string $hook): void
    {
        self::$current[] = $hook;
    }

    public static function popCurrent(): void
    {
        array_pop(self::$current);
    }

    /**
     * @return list<string>
     */
    public static function currentStack(): array
    {
        return self::$current;
    }

    public static function currentFilter(): string|false
    {
        if (self::$current === []) {
            return false;
        }
        $top = self::$current[array_key_last(self::$current)];

        return is_string($top) ? $top : false;
    }

    public static function doing(string|null $hook = null): bool
    {
        if ($hook === null) {
            return self::$current !== [];
        }

        return in_array($hook, self::$current, true);
    }

    public static function didAction(string $hook): int
    {
        return (int) (self::$actionsDone[$hook] ?? 0);
    }

    public static function incrementAction(string $hook): void
    {
        self::$actionsDone[$hook] = (int) (self::$actionsDone[$hook] ?? 0) + 1;
    }

    public static function reset(): void
    {
        self::$hooks = [];
        self::$actionsDone = [];
        self::$current = [];
    }

    /**
     * Stable string id for a callable (dedupe / remove).
     *
     * @param callable $callback
     */
    public static function callbackId(callable $callback): string
    {
        if (is_string($callback)) {
            return 'fn:' . $callback;
        }
        if (is_array($callback)) {
            $obj = $callback[0] ?? null;
            $method = (string) ($callback[1] ?? '');
            if (is_object($obj)) {
                return 'obj:' . spl_object_hash($obj) . '::' . $method;
            }
            if (is_string($obj)) {
                return 'static:' . $obj . '::' . $method;
            }
        }
        if ($callback instanceof Closure) {
            return 'closure:' . spl_object_hash($callback);
        }
        if (is_object($callback)) {
            return 'invokable:' . spl_object_hash($callback);
        }

        return 'unknown:' . md5(serialize($callback));
    }
}
