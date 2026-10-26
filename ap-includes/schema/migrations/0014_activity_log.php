<?php

/**
 * Migration 0014 — activity log for admin credential changes.
 *
 * Creates:
 * - {prefix}activity_log — one row per password or email change an
 *   administrator makes on another user's account
 *
 * Columns are actor id, target id, field name (`password` or `user_email`),
 * UTC timestamp, and client IP. There is no password, hash, or address column.
 *
 * Multi-driver DDL (MySQL/MariaDB, SQLite, PostgreSQL). Idempotent: CREATE
 * TABLE / INDEX IF NOT EXISTS so a retry after a partial apply is safe.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

// Guard: migrator may require this file more than once per process.
if (!class_exists('AP_Migration_0014_Activity_Log', false)) {
    /**
     * Create activity_log.
     */
    final class AP_Migration_0014_Activity_Log implements AP_Migration
    {
        public function version(): int
        {
            return 14;
        }

        public function description(): string
        {
            return 'Activity log for admin password and email changes';
        }

        public function up(AP_DB $db): void
        {
            $driver = $db->getDriver();

            foreach ($this->createStatements($db, $driver) as $sql) {
                $stmt = $db->query($sql);
                if ($stmt === false) {
                    throw new RuntimeException(
                        'Failed to apply activity_log schema: '
                        . ($db->lastError() ?? 'unknown error')
                    );
                }
            }
        }

        /**
         * @return list<string>
         */
        private function createStatements(AP_DB $db, string $driver): array
        {
            $table = $db->quoteIdentifier($db->table('activity_log'));
            $idx = preg_replace('/[^A-Za-z0-9_]/', '', $db->getPrefix()) ?: 'ap_';

            return match ($driver) {
                'mysql' => $this->mysqlStatements($table),
                'pgsql' => $this->pgsqlStatements($table, $idx),
                default => $this->sqliteStatements($table, $idx),
            };
        }

        /**
         * @return list<string>
         */
        private function mysqlStatements(string $table): array
        {
            return [
                "CREATE TABLE IF NOT EXISTS {$table} ("
                    . ' `log_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
                    . ' `actor_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,'
                    . ' `target_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,'
                    . " `field_name` VARCHAR(32) NOT NULL DEFAULT '',"
                    . ' `created_at` DATETIME NOT NULL,'
                    . " `ip` VARCHAR(45) NOT NULL DEFAULT '',"
                    . ' PRIMARY KEY (`log_id`),'
                    . ' KEY `actor_id` (`actor_id`),'
                    . ' KEY `target_id` (`target_id`),'
                    . ' KEY `created_at` (`created_at`)'
                    . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            ];
        }

        /**
         * @return list<string>
         */
        private function pgsqlStatements(string $table, string $idx): array
        {
            return [
                "CREATE TABLE IF NOT EXISTS {$table} ("
                    . ' log_id BIGSERIAL PRIMARY KEY,'
                    . ' actor_id BIGINT NOT NULL DEFAULT 0,'
                    . ' target_id BIGINT NOT NULL DEFAULT 0,'
                    . " field_name VARCHAR(32) NOT NULL DEFAULT '',"
                    . ' created_at TIMESTAMP NOT NULL,'
                    . " ip VARCHAR(45) NOT NULL DEFAULT ''"
                    . ')',
                "CREATE INDEX IF NOT EXISTS {$idx}activity_log_actor_id ON {$table} (actor_id)",
                "CREATE INDEX IF NOT EXISTS {$idx}activity_log_target_id ON {$table} (target_id)",
                "CREATE INDEX IF NOT EXISTS {$idx}activity_log_created_at ON {$table} (created_at)",
            ];
        }

        /**
         * @return list<string>
         */
        private function sqliteStatements(string $table, string $idx): array
        {
            return [
                "CREATE TABLE IF NOT EXISTS {$table} ("
                    . ' log_id INTEGER PRIMARY KEY AUTOINCREMENT,'
                    . ' actor_id INTEGER NOT NULL DEFAULT 0,'
                    . ' target_id INTEGER NOT NULL DEFAULT 0,'
                    . " field_name TEXT NOT NULL DEFAULT '',"
                    . " created_at TEXT NOT NULL DEFAULT '',"
                    . " ip TEXT NOT NULL DEFAULT ''"
                    . ')',
                "CREATE INDEX IF NOT EXISTS {$idx}activity_log_actor_id ON {$table} (actor_id)",
                "CREATE INDEX IF NOT EXISTS {$idx}activity_log_target_id ON {$table} (target_id)",
                "CREATE INDEX IF NOT EXISTS {$idx}activity_log_created_at ON {$table} (created_at)",
            ];
        }
    }
}

return new AP_Migration_0014_Activity_Log();
