<?php

/**
 * AgoraPress single-hook callback storage and execution.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * Callbacks for a single hook name (action or filter).
 */
class AP_Hook
{
    /**
     * priority => callback_id => [callable, accepted_args].
     *
     * @var array<int, array<string, array{0: callable, 1: int}>>
     */
    private array $callbacks = [];

    /** Nesting depth while this hook is executing. */
    private int $nesting = 0;

    /**
     * Register a callback at a priority.
     *
     * @param callable $callback
     */
    public function add(callable $callback, int $priority, int $acceptedArgs): bool
    {
        $id = AP_Hooks::callbackId($callback);
        if (!isset($this->callbacks[$priority])) {
            $this->callbacks[$priority] = [];
        }
        // Same callback at same priority is a no-op success (dedupe).
        if (isset($this->callbacks[$priority][$id])) {
            return true;
        }
        $this->callbacks[$priority][$id] = [$callback, max(0, $acceptedArgs)];

        return true;
    }

    /**
     * Remove a callback at a priority.
     *
     * @param callable $callback
     */
    public function remove(callable $callback, int $priority): bool
    {
        $id = AP_Hooks::callbackId($callback);
        if (!isset($this->callbacks[$priority][$id])) {
            return false;
        }
        unset($this->callbacks[$priority][$id]);
        if ($this->callbacks[$priority] === []) {
            unset($this->callbacks[$priority]);
        }

        return true;
    }

    /**
     * Remove all callbacks, or only those at $priority.
     */
    public function removeAll(?int $priority = null): void
    {
        if ($priority === null) {
            $this->callbacks = [];

            return;
        }
        unset($this->callbacks[$priority]);
    }

    /**
     * Whether any (or a specific) callback is registered.
     *
     * @param callable|false $callback
     *
     * @return bool|int False if none; callback count when $callback is false;
     *                  priority (int) when a specific callback is found.
     */
    public function has(callable|false $callback = false): bool|int
    {
        if ($this->callbacks === []) {
            return false;
        }

        if ($callback === false) {
            $count = 0;
            foreach ($this->callbacks as $group) {
                $count += count($group);
            }

            return $count > 0 ? $count : false;
        }

        $id = AP_Hooks::callbackId($callback);
        foreach ($this->callbacks as $priority => $group) {
            if (isset($group[$id])) {
                return (int) $priority;
            }
        }

        return false;
    }

    public function isEmpty(): bool
    {
        return $this->callbacks === [];
    }

    /**
     * Apply filters: each callback receives ($value, ...$extraArgs) limited by accepted_args.
     *
     * @param list<mixed> $args Extra args after $value (not including $value).
     */
    public function applyFilters(mixed $value, array $args = []): mixed
    {
        if ($this->callbacks === []) {
            return $value;
        }

        ++$this->nesting;

        $priority = $this->nextPriority(null);
        while ($priority !== null) {
            $ids = array_keys($this->callbacks[$priority] ?? []);
            foreach ($ids as $id) {
                if (!isset($this->callbacks[$priority][$id])) {
                    continue;
                }
                /** @var callable $cb */
                $cb = $this->callbacks[$priority][$id][0];
                $accepted = max(0, (int) $this->callbacks[$priority][$id][1]);
                if ($accepted === 0) {
                    $value = $cb();
                } else {
                    $pass = array_merge([$value], array_slice($args, 0, max(0, $accepted - 1)));
                    $value = $cb(...$pass);
                }
            }
            // Same-priority callbacks added during this run.
            $allIds = array_keys($this->callbacks[$priority] ?? []);
            $newIds = array_diff($allIds, $ids);
            foreach ($newIds as $id) {
                if (!isset($this->callbacks[$priority][$id])) {
                    continue;
                }
                /** @var callable $cb */
                $cb = $this->callbacks[$priority][$id][0];
                $accepted = max(0, (int) $this->callbacks[$priority][$id][1]);
                if ($accepted === 0) {
                    $value = $cb();
                } else {
                    $pass = array_merge([$value], array_slice($args, 0, max(0, $accepted - 1)));
                    $value = $cb(...$pass);
                }
            }
            $priority = $this->nextPriority($priority);
        }

        --$this->nesting;

        return $value;
    }

    /**
     * Fire action callbacks (no return value).
     *
     * @param list<mixed> $args
     */
    public function doAction(array $args = []): void
    {
        if ($this->callbacks === []) {
            return;
        }

        ++$this->nesting;

        $priority = $this->nextPriority(null);
        while ($priority !== null) {
            $ids = array_keys($this->callbacks[$priority] ?? []);
            foreach ($ids as $id) {
                if (!isset($this->callbacks[$priority][$id])) {
                    continue;
                }
                /** @var callable $cb */
                $cb = $this->callbacks[$priority][$id][0];
                $accepted = max(0, (int) $this->callbacks[$priority][$id][1]);
                $pass = $accepted === 0 ? [] : array_slice($args, 0, $accepted);
                $cb(...$pass);
            }
            $allIds = array_keys($this->callbacks[$priority] ?? []);
            $newIds = array_diff($allIds, $ids);
            foreach ($newIds as $id) {
                if (!isset($this->callbacks[$priority][$id])) {
                    continue;
                }
                /** @var callable $cb */
                $cb = $this->callbacks[$priority][$id][0];
                $accepted = max(0, (int) $this->callbacks[$priority][$id][1]);
                $pass = $accepted === 0 ? [] : array_slice($args, 0, $accepted);
                $cb(...$pass);
            }
            $priority = $this->nextPriority($priority);
        }

        --$this->nesting;
    }

    public function nestingLevel(): int
    {
        return $this->nesting;
    }

    /**
     * Next registered priority strictly greater than $after (or lowest when null).
     */
    private function nextPriority(?int $after): ?int
    {
        if ($this->callbacks === []) {
            return null;
        }
        $keys = array_keys($this->callbacks);
        sort($keys, SORT_NUMERIC);
        foreach ($keys as $p) {
            if ($after === null || $p > $after) {
                return (int) $p;
            }
        }

        return null;
    }
}
