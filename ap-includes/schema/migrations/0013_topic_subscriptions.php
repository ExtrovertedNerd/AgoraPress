<?php

/**
 * Migration 0013 — per-topic email notification subscriptions.
 *
 * Creates:
 * - {prefix}topic_subscriptions — one row per (user, topic) watch
 *
 * Unique (user_id, topic_id) via composite primary key. Index on topic_id
 * for fan-out on reply. Does not alter unread tracking (migration 0009).
 *
 * Multi-driver DDL (MySQL/MariaDB, SQLite, PostgreSQL). Idempotent: CREATE
 * TABLE / INDEX IF NOT EXISTS so a retry after a partial apply is safe.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

// Guard: migrator may require this file more than once per process.
if (!class_exists('AP_Migration_0013_Topic_Subscriptions', false)) {
    /**
     * Create topic_subscriptions for opt-in per-topic reply mail.
     */
    final class AP_Migration_0013_Topic_Subscriptions implements AP_Migration
    {
        public function version(): int
        {
            return 13;
        }

        public function description(): string
        {
            return 'Topic email notify: topic_subscriptions table';
        }

        public function up(AP_DB $db): void
        {
            $driver = $db->getDriver();

            foreach ($this->createStatements($db, $driver) as $sql) {
                $stmt = $db->query($sql);
                if ($stmt === false) {
                    throw new RuntimeException(
                        'Failed to apply topic_subscriptions schema: '
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
            $table = $db->quoteIdentifier($db->table('topic_subscriptions'));
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
                    . ' `user_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,'
                    . ' `topic_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,'
                    . ' `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,'
                    . ' PRIMARY KEY (`user_id`, `topic_id`),'
                    . ' KEY `topic_id` (`topic_id`)'
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
                    . ' user_id BIGINT NOT NULL DEFAULT 0,'
                    . ' topic_id BIGINT NOT NULL DEFAULT 0,'
                    . ' created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,'
                    . ' PRIMARY KEY (user_id, topic_id)'
                    . ')',
                "CREATE INDEX IF NOT EXISTS {$idx}topic_subscriptions_topic_id"
                    . " ON {$table} (topic_id)",
            ];
        }

        /**
         * @return list<string>
         */
        private function sqliteStatements(string $table, string $idx): array
        {
            return [
                "CREATE TABLE IF NOT EXISTS {$table} ("
                    . ' user_id INTEGER NOT NULL DEFAULT 0,'
                    . ' topic_id INTEGER NOT NULL DEFAULT 0,'
                    . " created_at TEXT NOT NULL DEFAULT (datetime('now')),"
                    . ' PRIMARY KEY (user_id, topic_id)'
                    . ')',
                "CREATE INDEX IF NOT EXISTS {$idx}topic_subscriptions_topic_id"
                    . " ON {$table} (topic_id)",
            ];
        }
    }
}

return new AP_Migration_0013_Topic_Subscriptions();
