<?php

/**
 * Minimal activity log for administrator credential changes.
 *
 * Records that an administrator changed another user's password or email.
 * The row stores the actor id, target id, field name, UTC time, and client IP.
 * It has no column for the password, the password hash, or the new address.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * Append-only audit rows in `{prefix}activity_log` (schema 14).
 */
class AP_Activity_Log
{
    /**
     * Unprefixed table name.
     */
    public const TABLE = 'activity_log';

    /**
     * Field name stored when the password was changed. Not the password.
     */
    public const FIELD_PASSWORD = 'password';

    /**
     * Field name stored when the email address was changed. Not the address.
     */
    public const FIELD_EMAIL = 'user_email';

    /**
     * @return list<string>
     */
    public static function fields(): array
    {
        return [self::FIELD_PASSWORD, self::FIELD_EMAIL];
    }

    /**
     * Whether `$field` is a field name this log accepts.
     */
    public static function isField(string $field): bool
    {
        return in_array($field, self::fields(), true);
    }

    /**
     * Append one audit row.
     *
     * Refuses a missing actor, a missing target, an actor editing themselves,
     * and any field name other than {@see FIELD_PASSWORD} or {@see FIELD_EMAIL}.
     * There is no parameter for a secret or a new address, so those values
     * cannot be written here. An invalid IP is stored as an empty string.
     *
     * A database failure returns false and does not throw. Callers that already
     * saved the user should keep that success.
     *
     * @param string|null $ip Null reads the request IP. A string is sanitized
     *                        and stored only when it is a valid IP.
     */
    public static function record(
        int $actorId,
        int $targetId,
        string $field,
        ?AP_DB $db = null,
        ?string $ip = null
    ): bool {
        if ($actorId < 1 || $targetId < 1 || $actorId === $targetId || !self::isField($field)) {
            return false;
        }

        $db = self::resolveDb($db);
        if ($db === null) {
            return false;
        }

        $ok = $db->insert(self::TABLE, [
            'actor_id' => $actorId,
            'target_id' => $targetId,
            'field_name' => $field,
            'created_at' => gmdate('Y-m-d H:i:s'),
            'ip' => self::requestIp($ip),
        ]);

        return $ok !== false;
    }

    /**
     * Rows for one target user, oldest first.
     *
     * @return list<object>
     */
    public static function forTarget(int $targetId, ?AP_DB $db = null, int $limit = 50): array
    {
        if ($targetId < 1) {
            return [];
        }

        $db = self::resolveDb($db);
        if ($db === null) {
            return [];
        }

        $limit = max(1, min(200, $limit));
        $table = $db->quoteIdentifier($db->table(self::TABLE));
        $rows = $db->getResults(
            'SELECT log_id, actor_id, target_id, field_name, created_at, ip FROM '
            . $table
            . ' WHERE target_id = ? ORDER BY log_id ASC LIMIT ' . $limit,
            [$targetId]
        );

        return $rows;
    }

    private static function resolveDb(?AP_DB $db): ?AP_DB
    {
        if ($db !== null) {
            return $db;
        }
        if (function_exists('ap_db')) {
            return ap_db();
        }

        return null;
    }

    /**
     * Client IP for the row. Uses the rate-limit helper when it is loaded so
     * proxy trust matches the rest of core. Anything that is not an IP is dropped.
     */
    private static function requestIp(?string $ip): string
    {
        if ($ip === null) {
            if (class_exists('AP_Rate_Limit', false)) {
                $ip = AP_Rate_Limit::clientIp();
            } elseif (!empty($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR'])) {
                $ip = $_SERVER['REMOTE_ADDR'];
            } else {
                $ip = '';
            }
        }

        $ip = trim($ip);
        if ($ip === '' || strlen($ip) > 45) {
            return '';
        }
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return '';
        }

        return $ip;
    }
}
