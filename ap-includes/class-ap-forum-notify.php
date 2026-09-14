<?php

/**
 * Topic email notification site options.
 *
 * Three gates (all default off) control reply mail. This class owns the
 * **site** options only:
 *
 * | Option                         | Default | Meaning                                      |
 * |--------------------------------|---------|----------------------------------------------|
 * | `forum_topic_notify_enabled`   | `'0'`   | Site master: allow topic email notifications |
 * | `forum_notify_max_per_minute`  | `4`     | Own send cap (does not consume rate_limit_mail) |
 *
 * Per-user `forum_notify_email` and per-topic Subscribe live elsewhere.
 * Missing options are treated as these defaults so upgrades never start
 * sending until an administrator turns the site switch on.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * Site options for opt-in per-topic reply mail.
 */
class AP_Forum_Notify
{
    /**
     * Site master switch. Stored as '1' / '0'. Missing or '0' = off.
     */
    public const OPTION_ENABLED = 'forum_topic_notify_enabled';

    /**
     * Own per-minute send cap. Stored as a decimal string. Default 4.
     * Does not share the verification / reset / test `rate_limit_mail` bucket.
     */
    public const OPTION_MAX_PER_MINUTE = 'forum_notify_max_per_minute';

    /** Default for {@see OPTION_ENABLED}: no chrome, enqueue, or send. */
    public const DEFAULT_ENABLED = false;

    /** Default mails per minute when the option is missing or invalid. */
    public const DEFAULT_MAX_PER_MINUTE = 4;

    /** Minimum allowed per-minute cap (0 / negative fall back to default). */
    public const MIN_PER_MINUTE = 1;

    /** Soft ceiling so a crafted value cannot enqueue unbounded SMTP. */
    public const MAX_PER_MINUTE = 60;

    /**
     * Whether the site allows topic email notifications.
     *
     * Default is **off** when the option is missing.
     */
    public static function isEnabled(?AP_DB $db = null): bool
    {
        $raw = strtolower(trim(self::optionValue(
            self::OPTION_ENABLED,
            self::DEFAULT_ENABLED ? '1' : '0',
            $db
        )));

        return !in_array($raw, ['0', 'false', 'no', 'off', ''], true);
    }

    /**
     * Per-minute send cap (clamped). Default 4.
     */
    public static function getMaxPerMinute(?AP_DB $db = null): int
    {
        $raw = self::optionValue(
            self::OPTION_MAX_PER_MINUTE,
            (string) self::DEFAULT_MAX_PER_MINUTE,
            $db
        );

        return self::sanitizeMaxPerMinute($raw);
    }

    /**
     * Normalize an enable flag to '1' or '0' for storage.
     */
    public static function sanitizeEnabled(mixed $value): string
    {
        if ($value === true || $value === 1 || $value === '1') {
            return '1';
        }
        if (is_string($value)) {
            $v = strtolower(trim($value));
            if (in_array($v, ['1', 'true', 'yes', 'on'], true)) {
                return '1';
            }
        }

        return '0';
    }

    /**
     * Normalize a per-minute cap. Invalid / out of range → default 4.
     */
    public static function sanitizeMaxPerMinute(mixed $value): int
    {
        if (is_bool($value)) {
            return self::DEFAULT_MAX_PER_MINUTE;
        }
        if (is_string($value)) {
            $value = trim($value);
            if ($value === '' || !is_numeric($value)) {
                return self::DEFAULT_MAX_PER_MINUTE;
            }
        }
        if (!is_numeric($value)) {
            return self::DEFAULT_MAX_PER_MINUTE;
        }

        $n = (int) $value;
        if ($n < self::MIN_PER_MINUTE) {
            return self::DEFAULT_MAX_PER_MINUTE;
        }

        return min(self::MAX_PER_MINUTE, $n);
    }

    /**
     * Persist notify site options. Omitted keys are left unchanged.
     *
     * @param array{
     *   forum_topic_notify_enabled?: mixed,
     *   forum_notify_max_per_minute?: mixed,
     *   enabled?: mixed,
     *   max_per_minute?: mixed
     * } $settings
     */
    public static function updateSettings(array $settings, ?AP_DB $db = null): bool
    {
        if (!class_exists('AP_Options', false)) {
            return false;
        }

        $ok = true;
        $enabledKey = array_key_exists(self::OPTION_ENABLED, $settings)
            ? self::OPTION_ENABLED
            : (array_key_exists('enabled', $settings) ? 'enabled' : null);
        if ($enabledKey !== null) {
            $ok = AP_Options::update(
                self::OPTION_ENABLED,
                self::sanitizeEnabled($settings[$enabledKey]),
                $db
            ) && $ok;
        }

        $capKey = array_key_exists(self::OPTION_MAX_PER_MINUTE, $settings)
            ? self::OPTION_MAX_PER_MINUTE
            : (array_key_exists('max_per_minute', $settings) ? 'max_per_minute' : null);
        if ($capKey !== null) {
            $ok = AP_Options::update(
                self::OPTION_MAX_PER_MINUTE,
                (string) self::sanitizeMaxPerMinute($settings[$capKey]),
                $db
            ) && $ok;
        }

        return $ok;
    }

    /**
     * Insert the two options when missing. Does not overwrite stored values.
     */
    public static function seedDefaults(AP_DB $db): void
    {
        foreach (self::defaultOptionMap() as $name => $value) {
            if (class_exists('AP_Options', false)) {
                AP_Options::add($name, $value, $db, 'yes');
                continue;
            }

            $table = $db->quoteIdentifier($db->table('options'));
            $existing = $db->getVar(
                'SELECT option_id FROM ' . $table . ' WHERE option_name = ? LIMIT 1',
                [$name]
            );
            if ($existing !== null && $existing !== '') {
                continue;
            }

            $ok = $db->insert('options', [
                'option_name' => $name,
                'option_value' => $value,
                'autoload' => 'yes',
            ]);
            if ($ok === false) {
                $again = $db->getVar(
                    'SELECT option_id FROM ' . $table . ' WHERE option_name = ? LIMIT 1',
                    [$name]
                );
                if ($again !== null && $again !== '') {
                    continue;
                }
                throw new RuntimeException(
                    'Failed to seed forum notify option ' . $name . ': '
                    . ($db->lastError() ?? 'unknown error')
                );
            }
        }
    }

    /**
     * Default option map for installer / migration (name => stored string).
     *
     * @return array<string, string>
     */
    public static function defaultOptionMap(): array
    {
        return [
            self::OPTION_ENABLED => self::DEFAULT_ENABLED ? '1' : '0',
            self::OPTION_MAX_PER_MINUTE => (string) self::DEFAULT_MAX_PER_MINUTE,
        ];
    }

    private static function optionValue(string $name, string $default, ?AP_DB $db): string
    {
        if (class_exists('AP_Options', false)) {
            $val = AP_Options::get($name, $default, $db);
            if (is_bool($val)) {
                return $val ? '1' : '0';
            }
            if (is_scalar($val)) {
                return (string) $val;
            }
        }
        if (function_exists('ap_get_option')) {
            $val = ap_get_option($name, $default, $db);
            if (is_bool($val)) {
                return $val ? '1' : '0';
            }
            if (is_scalar($val)) {
                return (string) $val;
            }
        }

        return $default;
    }
}
