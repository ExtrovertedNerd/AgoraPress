<?php

/**
 * AgoraPress Cron API.
 *
 * WP-inspired scheduled events stored in the `cron` option. Events run when
 * `AP_Cron::runDue()` is called (bootstrap pseudo-cron, or a real system cron
 * hitting a future wp-cron-style endpoint). No external telemetry.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * Schedule, unschedule, and run due events.
 */
class AP_Cron
{
    /** Option name holding the cron array. */
    public const OPTION = 'cron';

    /**
     * Built-in recurrence schedules: slug => [interval seconds, display label].
     *
     * @var array<string, array{interval: int, display: string}>
     */
    private static array $schedules = [
        'hourly' => ['interval' => 3600, 'display' => 'Once Hourly'],
        'twicedaily' => ['interval' => 43200, 'display' => 'Twice Daily'],
        'daily' => ['interval' => 86400, 'display' => 'Once Daily'],
        'weekly' => ['interval' => 604800, 'display' => 'Once Weekly'],
    ];

    /** @var bool Prevent re-entrancy during runDue. */
    private static bool $running = false;

    /** Max events processed per runDue() call. */
    private const MAX_PER_RUN = 20;

    /**
     * Known recurrence schedules (filterable via ap_cron_schedules).
     *
     * @return array<string, array{interval: int, display: string}>
     */
    public static function schedules(): array
    {
        $schedules = self::$schedules;
        if (function_exists('ap_apply_filters')) {
            $filtered = ap_apply_filters('ap_cron_schedules', $schedules);
            if (is_array($filtered)) {
                $schedules = [];
                foreach ($filtered as $slug => $def) {
                    if (!is_string($slug) || $slug === '' || !is_array($def)) {
                        continue;
                    }
                    $interval = (int) ($def['interval'] ?? 0);
                    if ($interval <= 0) {
                        continue;
                    }
                    $schedules[$slug] = [
                        'interval' => $interval,
                        'display' => (string) ($def['display'] ?? $slug),
                    ];
                }
            }
        }

        return $schedules;
    }

    /**
     * Schedule a recurring event.
     *
     * @param list<mixed> $args Arguments passed to the hook when fired.
     */
    public static function scheduleEvent(
        int $timestamp,
        string $recurrence,
        string $hook,
        array $args = [],
        ?AP_DB $db = null
    ): bool {
        $hook = self::normalizeHook($hook);
        $timestamp = self::normalizeTimestamp($timestamp);
        if ($hook === '' || $timestamp <= 0) {
            return false;
        }

        $schedules = self::schedules();
        if (!isset($schedules[$recurrence])) {
            return false;
        }

        $cron = self::getArray($db);
        $key = self::eventKey($hook, $args);

        // Avoid duplicate: same hook+args already scheduled.
        if (self::findEvent($cron, $hook, $args) !== null) {
            return false;
        }

        $cron[$timestamp][$hook][$key] = [
            'schedule' => $recurrence,
            'args' => array_values($args),
            'interval' => $schedules[$recurrence]['interval'],
        ];

        return self::saveArray($cron, $db);
    }

    /**
     * Schedule a one-time event.
     *
     * @param list<mixed> $args
     */
    public static function scheduleSingle(
        int $timestamp,
        string $hook,
        array $args = [],
        ?AP_DB $db = null
    ): bool {
        $hook = self::normalizeHook($hook);
        $timestamp = self::normalizeTimestamp($timestamp);
        if ($hook === '' || $timestamp <= 0) {
            return false;
        }

        $cron = self::getArray($db);
        $key = self::eventKey($hook, $args);

        if (self::findEvent($cron, $hook, $args) !== null) {
            return false;
        }

        $cron[$timestamp][$hook][$key] = [
            'schedule' => false,
            'args' => array_values($args),
            'interval' => 0,
        ];

        return self::saveArray($cron, $db);
    }

    /**
     * Unschedule a specific event occurrence.
     *
     * @param list<mixed> $args
     */
    public static function unschedule(
        int $timestamp,
        string $hook,
        array $args = [],
        ?AP_DB $db = null
    ): bool {
        $hook = self::normalizeHook($hook);
        if ($hook === '') {
            return false;
        }

        $cron = self::getArray($db);
        $key = self::eventKey($hook, $args);
        if (!isset($cron[$timestamp][$hook][$key])) {
            return false;
        }

        unset($cron[$timestamp][$hook][$key]);
        if ($cron[$timestamp][$hook] === []) {
            unset($cron[$timestamp][$hook]);
        }
        if (($cron[$timestamp] ?? null) === [] || $cron[$timestamp] === null) {
            unset($cron[$timestamp]);
        }

        return self::saveArray($cron, $db);
    }

    /**
     * Clear all events for a hook (optionally matching args).
     *
     * @param list<mixed>|null $args null = all events for hook; array = exact match.
     *
     * @return int Number of events removed.
     */
    public static function clearHook(string $hook, ?array $args = null, ?AP_DB $db = null): int
    {
        $hook = self::normalizeHook($hook);
        if ($hook === '') {
            return 0;
        }

        $cron = self::getArray($db);
        $removed = 0;
        $key = $args !== null ? self::eventKey($hook, $args) : null;

        foreach ($cron as $ts => $hooks) {
            if (!is_int($ts) && !ctype_digit((string) $ts)) {
                continue;
            }
            $ts = (int) $ts;
            if (!isset($hooks[$hook]) || !is_array($hooks[$hook])) {
                continue;
            }
            if ($key === null) {
                $removed += count($hooks[$hook]);
                unset($cron[$ts][$hook]);
            } elseif (isset($hooks[$hook][$key])) {
                unset($cron[$ts][$hook][$key]);
                $removed++;
            }
            if (isset($cron[$ts][$hook]) && $cron[$ts][$hook] === []) {
                unset($cron[$ts][$hook]);
            }
            if (isset($cron[$ts]) && $cron[$ts] === []) {
                unset($cron[$ts]);
            }
        }

        if ($removed > 0) {
            self::saveArray($cron, $db);
        }

        return $removed;
    }

    /**
     * Next scheduled timestamp for hook (+ optional args), or false.
     *
     * @param list<mixed> $args
     *
     * @return int|false
     */
    public static function nextScheduled(string $hook, array $args = [], ?AP_DB $db = null): int|false
    {
        $hook = self::normalizeHook($hook);
        if ($hook === '') {
            return false;
        }

        $found = self::findEvent(self::getArray($db), $hook, $args);

        return $found['timestamp'] ?? false;
    }

    /**
     * Raw cron array (timestamps + version meta).
     *
     * @return array<int|string, mixed>
     */
    public static function getCronArray(?AP_DB $db = null): array
    {
        return self::getArray($db);
    }

    /**
     * Run all due events (timestamp <= now). Reschedules recurring ones.
     *
     * @return int Number of callbacks fired.
     */
    public static function runDue(?AP_DB $db = null, ?int $now = null): int
    {
        if (self::$running) {
            return 0;
        }
        self::$running = true;

        try {
            $now = $now ?? time();
            $cron = self::getArray($db);
            $fired = 0;

            $timestamps = [];
            foreach ($cron as $ts => $_) {
                if (is_int($ts) || (is_string($ts) && ctype_digit($ts))) {
                    $timestamps[] = (int) $ts;
                }
            }
            sort($timestamps, SORT_NUMERIC);

            foreach ($timestamps as $ts) {
                if ($ts > $now) {
                    break;
                }
                if ($fired >= self::MAX_PER_RUN) {
                    break;
                }

                $hooks = $cron[$ts] ?? null;
                if (!is_array($hooks)) {
                    unset($cron[$ts]);
                    continue;
                }

                foreach ($hooks as $hook => $events) {
                    if (!is_string($hook) || !is_array($events)) {
                        continue;
                    }
                    foreach ($events as $key => $event) {
                        if ($fired >= self::MAX_PER_RUN) {
                            break 3;
                        }
                        if (!is_array($event)) {
                            continue;
                        }

                        $args = is_array($event['args'] ?? null) ? $event['args'] : [];
                        unset($cron[$ts][$hook][$key]);

                        if (function_exists('ap_do_action')) {
                            ap_do_action($hook, ...$args);
                        }
                        $fired++;

                        // Reschedule recurring.
                        $schedule = $event['schedule'] ?? false;
                        if (is_string($schedule) && $schedule !== '') {
                            $interval = (int) ($event['interval'] ?? 0);
                            if ($interval <= 0) {
                                $schedules = self::schedules();
                                $interval = (int) ($schedules[$schedule]['interval'] ?? 0);
                            }
                            if ($interval > 0) {
                                $next = $ts + $interval;
                                // Catch up if far behind (avoid tight loops).
                                while ($next <= $now) {
                                    $next += $interval;
                                }
                                $cron[$next][$hook][$key] = [
                                    'schedule' => $schedule,
                                    'args' => array_values($args),
                                    'interval' => $interval,
                                ];
                            }
                        }
                    }
                    if (isset($cron[$ts][$hook]) && $cron[$ts][$hook] === []) {
                        unset($cron[$ts][$hook]);
                    }
                }
                if (isset($cron[$ts]) && $cron[$ts] === []) {
                    unset($cron[$ts]);
                }
            }

            self::saveArray($cron, $db);

            return $fired;
        } finally {
            self::$running = false;
        }
    }

    /**
     * Lightweight spawn: run due events if the earliest is due.
     * Safe to call on every request (no-op when nothing is due).
     *
     * @return int Events fired.
     */
    public static function spawn(?AP_DB $db = null): int
    {
        $cron = self::getArray($db);
        $now = time();
        $earliest = null;
        foreach ($cron as $ts => $_) {
            if (!is_int($ts) && !(is_string($ts) && ctype_digit($ts))) {
                continue;
            }
            $ts = (int) $ts;
            if ($earliest === null || $ts < $earliest) {
                $earliest = $ts;
            }
        }

        if ($earliest === null || $earliest > $now) {
            return 0;
        }

        return self::runDue($db, $now);
    }

    /**
     * Reset static flags (unit tests). Does not wipe the cron option.
     */
    public static function reset(): void
    {
        self::$running = false;
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * @return array<int|string, mixed>
     */
    private static function getArray(?AP_DB $db): array
    {
        $raw = AP_Options::get(self::OPTION, [], $db);
        if (!is_array($raw)) {
            return ['version' => 2];
        }
        if (!isset($raw['version'])) {
            $raw['version'] = 2;
        }

        return $raw;
    }

    /**
     * @param array<int|string, mixed> $cron
     */
    private static function saveArray(array $cron, ?AP_DB $db): bool
    {
        $cron['version'] = 2;

        // Sort numeric timestamp keys for stable storage.
        $meta = [];
        $events = [];
        foreach ($cron as $k => $v) {
            if ($k === 'version' || (!is_int($k) && !(is_string($k) && ctype_digit((string) $k)))) {
                $meta[$k] = $v;
                continue;
            }
            $events[(int) $k] = $v;
        }
        ksort($events, SORT_NUMERIC);

        return AP_Options::update(self::OPTION, $events + $meta, $db);
    }

    /**
     * @param array<int|string, mixed> $cron
     * @param list<mixed>              $args
     *
     * @return array{timestamp: int, key: string}|null
     */
    private static function findEvent(array $cron, string $hook, array $args): ?array
    {
        $key = self::eventKey($hook, $args);
        $foundTs = null;
        foreach ($cron as $ts => $hooks) {
            if (!is_int($ts) && !(is_string($ts) && ctype_digit((string) $ts))) {
                continue;
            }
            $ts = (int) $ts;
            if (isset($hooks[$hook][$key])) {
                if ($foundTs === null || $ts < $foundTs) {
                    $foundTs = $ts;
                }
            }
        }

        if ($foundTs === null) {
            return null;
        }

        return ['timestamp' => $foundTs, 'key' => $key];
    }

    /**
     * @param list<mixed> $args
     */
    private static function eventKey(string $hook, array $args): string
    {
        $json = json_encode(array_values($args), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $payload = $hook . '|' . (is_string($json) ? $json : '[]');

        return md5($payload);
    }

    private static function normalizeHook(string $hook): string
    {
        $hook = trim($hook);
        if ($hook === '' || strlen($hook) > 191) {
            return '';
        }

        return $hook;
    }

    private static function normalizeTimestamp(int $timestamp): int
    {
        return $timestamp > 0 ? $timestamp : 0;
    }
}
