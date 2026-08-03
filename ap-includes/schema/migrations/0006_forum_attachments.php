<?php

/**
 * Migration 0006 — forum attachments (with quota support).
 *
 * Creates {prefix}forum_attachments linking uploaded media files to forum
 * posts/topics. Files themselves live under ap-content/uploads via AP_Media
 * (media library attachment posts); this table stores the forum association,
 * original filename, filesize (for quotas), and download counts.
 *
 * Multi-driver DDL (MySQL/MariaDB, SQLite, PostgreSQL).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

// Guard: migrator may require this file more than once per process.
if (!class_exists('AP_Migration_0006_Forum_Attachments', false)) {
    /**
     * Create forum attachment association table.
     */
    final class AP_Migration_0006_Forum_Attachments implements AP_Migration
    {
        public function version(): int
        {
            return 6;
        }

        public function description(): string
        {
            return 'Forum attachments: forum_attachments table (post/topic links, quotas, downloads)';
        }

        public function up(AP_DB $db): void
        {
            $driver = $db->getDriver();

            foreach ($this->createStatements($db, $driver) as $sql) {
                $stmt = $db->query($sql);
                if ($stmt === false) {
                    throw new RuntimeException(
                        'Failed to apply forum attachments schema: '
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
            $table = $db->quoteIdentifier($db->table('forum_attachments'));
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
                "CREATE TABLE {$table} ("
                    . ' `attach_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
                    . ' `post_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,'
                    . ' `topic_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,'
                    . ' `forum_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,'
                    . ' `user_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,'
                    . ' `media_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,'
                    . " `filename` VARCHAR(255) NOT NULL DEFAULT '',"
                    . " `mimetype` VARCHAR(100) NOT NULL DEFAULT '',"
                    . ' `filesize` BIGINT UNSIGNED NOT NULL DEFAULT 0,'
                    . ' `download_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,'
                    . ' `is_orphan` TINYINT(1) NOT NULL DEFAULT 0,'
                    . ' `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,'
                    . ' PRIMARY KEY (`attach_id`),'
                    . ' KEY `post_id` (`post_id`),'
                    . ' KEY `topic_id` (`topic_id`),'
                    . ' KEY `forum_id` (`forum_id`),'
                    . ' KEY `user_id` (`user_id`),'
                    . ' KEY `media_id` (`media_id`),'
                    . ' KEY `is_orphan` (`is_orphan`),'
                    . ' KEY `user_quota` (`user_id`, `is_orphan`)'
                    . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            ];
        }

        /**
         * @return list<string>
         */
        private function pgsqlStatements(string $table, string $idx): array
        {
            return [
                "CREATE TABLE {$table} ("
                    . ' attach_id BIGSERIAL PRIMARY KEY,'
                    . ' post_id BIGINT NOT NULL DEFAULT 0,'
                    . ' topic_id BIGINT NOT NULL DEFAULT 0,'
                    . ' forum_id BIGINT NOT NULL DEFAULT 0,'
                    . ' user_id BIGINT NOT NULL DEFAULT 0,'
                    . ' media_id BIGINT NOT NULL DEFAULT 0,'
                    . " filename VARCHAR(255) NOT NULL DEFAULT '',"
                    . " mimetype VARCHAR(100) NOT NULL DEFAULT '',"
                    . ' filesize BIGINT NOT NULL DEFAULT 0,'
                    . ' download_count BIGINT NOT NULL DEFAULT 0,'
                    . ' is_orphan SMALLINT NOT NULL DEFAULT 0,'
                    . ' created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP'
                    . ')',
                "CREATE INDEX {$idx}forum_attachments_post_id ON {$table} (post_id)",
                "CREATE INDEX {$idx}forum_attachments_topic_id ON {$table} (topic_id)",
                "CREATE INDEX {$idx}forum_attachments_forum_id ON {$table} (forum_id)",
                "CREATE INDEX {$idx}forum_attachments_user_id ON {$table} (user_id)",
                "CREATE INDEX {$idx}forum_attachments_media_id ON {$table} (media_id)",
                "CREATE INDEX {$idx}forum_attachments_is_orphan ON {$table} (is_orphan)",
                "CREATE INDEX {$idx}forum_attachments_user_quota ON {$table} (user_id, is_orphan)",
            ];
        }

        /**
         * @return list<string>
         */
        private function sqliteStatements(string $table, string $idx): array
        {
            return [
                "CREATE TABLE {$table} ("
                    . ' attach_id INTEGER PRIMARY KEY AUTOINCREMENT,'
                    . ' post_id INTEGER NOT NULL DEFAULT 0,'
                    . ' topic_id INTEGER NOT NULL DEFAULT 0,'
                    . ' forum_id INTEGER NOT NULL DEFAULT 0,'
                    . ' user_id INTEGER NOT NULL DEFAULT 0,'
                    . ' media_id INTEGER NOT NULL DEFAULT 0,'
                    . " filename TEXT NOT NULL DEFAULT '',"
                    . " mimetype TEXT NOT NULL DEFAULT '',"
                    . ' filesize INTEGER NOT NULL DEFAULT 0,'
                    . ' download_count INTEGER NOT NULL DEFAULT 0,'
                    . ' is_orphan INTEGER NOT NULL DEFAULT 0,'
                    . " created_at TEXT NOT NULL DEFAULT ''"
                    . ')',
                "CREATE INDEX {$idx}forum_attachments_post_id ON {$table} (post_id)",
                "CREATE INDEX {$idx}forum_attachments_topic_id ON {$table} (topic_id)",
                "CREATE INDEX {$idx}forum_attachments_forum_id ON {$table} (forum_id)",
                "CREATE INDEX {$idx}forum_attachments_user_id ON {$table} (user_id)",
                "CREATE INDEX {$idx}forum_attachments_media_id ON {$table} (media_id)",
                "CREATE INDEX {$idx}forum_attachments_is_orphan ON {$table} (is_orphan)",
                "CREATE INDEX {$idx}forum_attachments_user_quota ON {$table} (user_id, is_orphan)",
            ];
        }
    }
}

return new AP_Migration_0006_Forum_Attachments();
