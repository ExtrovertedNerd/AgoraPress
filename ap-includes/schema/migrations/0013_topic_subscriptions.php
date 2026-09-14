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
 * Seeds site options when missing (does not overwrite stored values):
 * - forum_topic_notify_enabled (default '0')
 * - forum_notify_max_per_minute (default 4)
 *
 * Does not backfill usermeta `forum_notify_email`; missing rows default off.
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
            return 'Topic email notify: topic_subscriptions table and option defaults';
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

            $this->seedNotifyOptions($db);
        }

        /**
         * Insert forum_topic_notify_enabled / forum_notify_max_per_minute when
         * missing. Safe to re-run: existing rows are left alone.
         */
        private function seedNotifyOptions(AP_DB $db): void
        {
            $classFile = dirname(__DIR__, 2) . '/class-ap-forum-notify.php';
            if (!class_exists('AP_Forum_Notify', false) && is_readable($classFile)) {
                require_once $classFile;
            }
            if (class_exists('AP_Forum_Notify', false)) {
                AP_Forum_Notify::seedDefaults($db);

                return;
            }

            $table = $db->quoteIdentifier($db->table('options'));
            $defaults = [
                'forum_topic_notify_enabled' => '0',
                'forum_notify_max_per_minute' => '4',
            ];
            foreach ($defaults as $name => $value) {
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
                    throw new RuntimeException(
                        'Failed to seed forum notify option ' . $name . ': '
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
