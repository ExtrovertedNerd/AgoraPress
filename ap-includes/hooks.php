<?php

/**
 * AgoraPress hook system (actions & filters).
 *
 * Full WordPress-inspired public API (ap_ prefix):
 *
 *   Actions:
 *     ap_add_action / ap_do_action / ap_do_action_ref_array
 *     ap_remove_action / ap_remove_all_actions
 *     ap_has_action / ap_did_action / ap_current_action / ap_doing_action
 *
 *   Filters:
 *     ap_add_filter / ap_apply_filters / ap_apply_filters_ref_array
 *     ap_remove_filter / ap_remove_all_filters
 *     ap_has_filter / ap_current_filter / ap_doing_filter
 *
 *   Meta:
 *     ap_reset_hooks (tests) / ap_hook_callback_id
 *
 * Priorities: lower runs first (default 10). Equal priorities keep registration
 * order. Callbacks may add further callbacks at the same or later priority and
 * those still run in the current pass. The special hook name "all" runs before
 * every other hook (receives the target hook name as its first argument).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

require_once __DIR__ . '/class-ap-hooks.php';

/**
 * Register an action callback.
 *
 * @param callable $callback
 */
function ap_add_action(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): bool
{
    return ap_add_filter($hook, $callback, $priority, $acceptedArgs);
}

/**
 * Fire an action (no return value). Extra args are passed to callbacks.
 */
function ap_do_action(string $hook, mixed ...$args): void
{
    ap_do_action_ref_array($hook, $args);
}

/**
 * Fire an action with arguments supplied as an array.
 *
 * @param list<mixed> $args
 */
function ap_do_action_ref_array(string $hook, array $args = []): void
{
    $hook = trim($hook);
    if ($hook === '') {
        return;
    }

    AP_Hooks::incrementAction($hook);

    $ranAll = false;
    // Catch-all "all" runs first. current_filter reports the *target* hook name.
    if ($hook !== 'all' && AP_Hooks::exists('all')) {
        AP_Hooks::pushCurrent($hook);
        $ranAll = true;
        AP_Hooks::get('all')->doAction(array_merge([$hook], $args));
    }

    if (!AP_Hooks::exists($hook)) {
        if ($ranAll) {
            AP_Hooks::popCurrent();
        }

        return;
    }

    if (!$ranAll) {
        AP_Hooks::pushCurrent($hook);
    }
    AP_Hooks::get($hook)->doAction($args);
    AP_Hooks::popCurrent();
}

/**
 * Remove a previously registered action.
 *
 * @param callable $callback
 */
function ap_remove_action(string $hook, callable $callback, int $priority = 10): bool
{
    return ap_remove_filter($hook, $callback, $priority);
}

/**
 * Remove all action callbacks on a hook (optionally one priority).
 *
 * @param int|false $priority False removes every priority.
 */
function ap_remove_all_actions(string $hook, int|false $priority = false): void
{
    ap_remove_all_filters($hook, $priority);
}

/**
 * Whether any (or a specific) action is registered on a hook.
 *
 * @param callable|false $callback
 *
 * @return bool|int False if none; count when $callback is false;
 *                  priority when a specific callback is found.
 */
function ap_has_action(string $hook, callable|false $callback = false): bool|int
{
    return ap_has_filter($hook, $callback);
}

/**
 * How many times an action has fired this request (including empty fires).
 */
function ap_did_action(string $hook): int
{
    return AP_Hooks::didAction($hook);
}

/**
 * Name of the currently running action, or false when idle / only a filter.
 * Same stack as filters (actions and filters share execution tracking).
 */
function ap_current_action(): string|false
{
    return ap_current_filter();
}

/**
 * Whether an action is currently running (optionally a specific hook).
 */
function ap_doing_action(string|null $hook = null): bool
{
    return ap_doing_filter($hook);
}

/**
 * Register a filter callback.
 *
 * @param callable $callback
 */
function ap_add_filter(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): bool
{
    $hook = trim($hook);
    if ($hook === '') {
        return false;
    }

    return AP_Hooks::get($hook)->add($callback, $priority, $acceptedArgs);
}

/**
 * Apply filters to a value. Callbacks receive ($value, ...$args).
 *
 * @param mixed $value
 */
function ap_apply_filters(string $hook, mixed $value, mixed ...$args): mixed
{
    return ap_apply_filters_ref_array($hook, array_merge([$value], $args));
}

/**
 * Apply filters with arguments supplied as an array (first element is the value).
 *
 * @param list<mixed> $args
 */
function ap_apply_filters_ref_array(string $hook, array $args): mixed
{
    $hook = trim($hook);
    $value = $args[0] ?? null;
    $extra = array_slice($args, 1);

    if ($hook === '') {
        return $value;
    }

    $ranAll = false;
    // Catch-all "all" fires first (action-style; return values ignored).
    // Callbacks receive ($hook_name, $value, ...$extra). current_filter = target hook.
    if ($hook !== 'all' && AP_Hooks::exists('all')) {
        AP_Hooks::pushCurrent($hook);
        $ranAll = true;
        AP_Hooks::get('all')->doAction(array_merge([$hook], $args));
    }

    if (!AP_Hooks::exists($hook)) {
        if ($ranAll) {
            AP_Hooks::popCurrent();
        }

        return $value;
    }

    if (!$ranAll) {
        AP_Hooks::pushCurrent($hook);
    }
    $value = AP_Hooks::get($hook)->applyFilters($value, $extra);
    AP_Hooks::popCurrent();

    return $value;
}

/**
 * Remove a previously registered filter.
 *
 * @param callable $callback
 */
function ap_remove_filter(string $hook, callable $callback, int $priority = 10): bool
{
    $instance = AP_Hooks::maybeGet($hook);
    if ($instance === null) {
        return false;
    }

    return $instance->remove($callback, $priority);
}

/**
 * Remove all filter callbacks on a hook (optionally one priority).
 *
 * @param int|false $priority False removes every priority.
 */
function ap_remove_all_filters(string $hook, int|false $priority = false): void
{
    $instance = AP_Hooks::maybeGet($hook);
    if ($instance === null) {
        return;
    }
    $instance->removeAll($priority === false ? null : $priority);
}

/**
 * Whether any (or a specific) filter is registered on a hook.
 *
 * @param callable|false $callback
 *
 * @return bool|int False if none; count when $callback is false;
 *                  priority when a specific callback is found.
 */
function ap_has_filter(string $hook, callable|false $callback = false): bool|int
{
    $instance = AP_Hooks::maybeGet($hook);
    if ($instance === null) {
        return false;
    }

    return $instance->has($callback);
}

/**
 * Name of the currently running hook, or false when idle.
 */
function ap_current_filter(): string|false
{
    return AP_Hooks::currentFilter();
}

/**
 * Whether a filter (or any hook) is currently running.
 */
function ap_doing_filter(string|null $hook = null): bool
{
    return AP_Hooks::doing($hook);
}

/**
 * Reset all hooks and action counters (unit tests).
 */
function ap_reset_hooks(): void
{
    AP_Hooks::reset();
}

/**
 * Stable string id for a callable (for de-dupe / remove).
 *
 * @param callable $callback
 */
function ap_hook_callback_id(callable $callback): string
{
    return AP_Hooks::callbackId($callback);
}
