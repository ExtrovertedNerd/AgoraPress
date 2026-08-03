<?php

/**
 * Migration 0009 — forum unread tracking tables.
 *
 * Creates:
 * - {prefix}topic_track — per-user last-read time for topics
 * - {prefix}forum_track — per-user mark-as-read time for forums
 *
 * Who’s online already ships in migration 0005 ({prefix}online). Global
 * “mark all forums read” is stored in usermeta (forum_last_mark).
 *
 * Multi-driver DDL (MySQL/MariaDB, SQLite, PostgreSQL).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

// Guard: migrator may require this file more than once per process.
if (!class_exists('AP_Migration_0009_Forum_Online_Unread', false)) {
    /**
     * Create topic_track and forum_track tables for unread tracking.
     */
    final class AP_Migration_0009_Forum_Online_Unread implements AP_Migration
    {
        public function version(): int
        {
            return 9;
        }

        public function description(): string
        {
            return 'Forum unread tracking: topic_track and forum_track tables';
        }

        public function up(AP_DB $db): void
        {
            $driver = $db->getDriver();

            foreach ($this->createStatements($db, $driver) as $sql) {
                $stmt = $db->query($sql);
                if ($stmt === false) {
                    throw new RuntimeException(
                        'Failed to apply forum online/unread schema: '
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
            $topicTrack = $db->quoteIdentifier($db->table('topic_track'));
            $forumTrack = $db->quoteIdentifier($db->table('forum_track'));
            $idx = preg_replace('/[^A-Za-z0-9_]/', '', $db->getPrefix()) ?: 'ap_';

            return match ($driver) {
                'mysql' => $this->mysqlStatements($topicTrack, $forumTrack),
                'pgsql' => $this->pgsqlStatements($topicTrack, $forumTrack, $idx),
                default => $this->sqliteStatements($topicTrack, $forumTrack, $idx),
            };
        }

        /**
         * @return list<string>
         */
        private function mysqlStatements(string $topicTrack, string $forumTrack): array
        {
            return [
                "CREATE TABLE {$topicTrack} ("
                    . ' `user_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,'
                    . ' `topic_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,'
                    . ' `forum_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,'
                    . ' `mark_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,'
                    . ' PRIMARY KEY (`user_id`, `topic_id`),'
                    . ' KEY `topic_id` (`topic_id`),'
                    . ' KEY `forum_user` (`forum_id`, `user_id`),'
                    . ' KEY `mark_time` (`mark_time`)'
                    . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',

                "CREATE TABLE {$forumTrack} ("
                    . ' `user_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,'
                    . ' `forum_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,'
                    . ' `mark_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,'
                    . ' PRIMARY KEY (`user_id`, `forum_id`),'
                    . ' KEY `forum_id` (`forum_id`),'
                    . ' KEY `mark_time` (`mark_time`)'
                    . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            ];
        }

        /**
         * @return list<string>
         */
        private function pgsqlStatements(string $topicTrack, string $forumTrack, string $idx): array
        {
            return [
                "CREATE TABLE {$topicTrack} ("
                    . ' user_id BIGINT NOT NULL DEFAULT 0,'
                    . ' topic_id BIGINT NOT NULL DEFAULT 0,'
                    . ' forum_id BIGINT NOT NULL DEFAULT 0,'
                    . ' mark_time TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,'
                    . ' PRIMARY KEY (user_id, topic_id)'
                    . ')',
                "CREATE INDEX {$idx}topic_track_topic_id ON {$topicTrack} (topic_id)",
                "CREATE INDEX {$idx}topic_track_forum_user ON {$topicTrack} (forum_id, user_id)",
                "CREATE INDEX {$idx}topic_track_mark_time ON {$topicTrack} (mark_time)",

                "CREATE TABLE {$forumTrack} ("
                    . ' user_id BIGINT NOT NULL DEFAULT 0,'
                    . ' forum_id BIGINT NOT NULL DEFAULT 0,'
                    . ' mark_time TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,'
                    . ' PRIMARY KEY (user_id, forum_id)'
                    . ')',
                "CREATE INDEX {$idx}forum_track_forum_id ON {$forumTrack} (forum_id)",
                "CREATE INDEX {$idx}forum_track_mark_time ON {$forumTrack} (mark_time)",
            ];
        }

        /**
         * @return list<string>
         */
        private function sqliteStatements(string $topicTrack, string $forumTrack, string $idx): array
        {
            return [
                "CREATE TABLE {$topicTrack} ("
                    . ' user_id INTEGER NOT NULL DEFAULT 0,'
                    . ' topic_id INTEGER NOT NULL DEFAULT 0,'
                    . ' forum_id INTEGER NOT NULL DEFAULT 0,'
                    . " mark_time TEXT NOT NULL DEFAULT '',"
                    . ' PRIMARY KEY (user_id, topic_id)'
                    . ')',
                "CREATE INDEX {$idx}topic_track_topic_id ON {$topicTrack} (topic_id)",
                "CREATE INDEX {$idx}topic_track_forum_user ON {$topicTrack} (forum_id, user_id)",
                "CREATE INDEX {$idx}topic_track_mark_time ON {$topicTrack} (mark_time)",

                "CREATE TABLE {$forumTrack} ("
                    . ' user_id INTEGER NOT NULL DEFAULT 0,'
                    . ' forum_id INTEGER NOT NULL DEFAULT 0,'
                    . " mark_time TEXT NOT NULL DEFAULT '',"
                    . ' PRIMARY KEY (user_id, forum_id)'
                    . ')',
                "CREATE INDEX {$idx}forum_track_forum_id ON {$forumTrack} (forum_id)",
                "CREATE INDEX {$idx}forum_track_mark_time ON {$forumTrack} (mark_time)",
            ];
        }
    }
}

return new AP_Migration_0009_Forum_Online_Unread();
