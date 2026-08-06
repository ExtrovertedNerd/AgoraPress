<?php

/**
 * Migration 0011 — forum post likes + denormalized like/stats counters.
 *
 * Creates:
 * - {prefix}forum_post_likes — one like per user per forum post
 *
 * Alters:
 * - {prefix}forum_posts.like_count — denormalized thumbs-up total
 *
 * User-level totals live in usermeta (forum_posts, forum_likes_given,
 * forum_likes_received) and are maintained by AP_Forum_Stats — no DDL for those.
 *
 * Multi-driver DDL (MySQL/MariaDB, SQLite, PostgreSQL).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

// Guard: migrator may require this file more than once per process.
if (!class_exists('AP_Migration_0011_Forum_Likes_Stats', false)) {
    /**
     * Forum likes table + post like_count column.
     */
    final class AP_Migration_0011_Forum_Likes_Stats implements AP_Migration
    {
        public function version(): int
        {
            return 11;
        }

        public function description(): string
        {
            return 'Forum likes: forum_post_likes table + forum_posts.like_count';
        }

        public function up(AP_DB $db): void
        {
            $driver = $db->getDriver();

            foreach ($this->statements($db, $driver) as $sql) {
                $stmt = $db->query($sql);
                if ($stmt === false) {
                    throw new RuntimeException(
                        'Failed to apply forum likes schema: '
                        . ($db->lastError() ?? 'unknown error')
                    );
                }
            }
        }

        /**
         * @return list<string>
         */
        private function statements(AP_DB $db, string $driver): array
        {
            $likes = $db->quoteIdentifier($db->table('forum_post_likes'));
            $posts = $db->quoteIdentifier($db->table('forum_posts'));
            $idx = preg_replace('/[^A-Za-z0-9_]/', '', $db->getPrefix()) ?: 'ap_';

            return match ($driver) {
                'mysql' => [
                    "CREATE TABLE {$likes} ("
                        . ' `like_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
                        . ' `post_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,'
                        . ' `user_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,'
                        . ' `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,'
                        . ' PRIMARY KEY (`like_id`),'
                        . ' UNIQUE KEY `post_user` (`post_id`, `user_id`),'
                        . ' KEY `user_id` (`user_id`),'
                        . ' KEY `post_id` (`post_id`)'
                        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
                    "ALTER TABLE {$posts} ADD COLUMN `like_count` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `post_position`",
                ],
                'pgsql' => [
                    "CREATE TABLE {$likes} ("
                        . ' like_id BIGSERIAL PRIMARY KEY,'
                        . ' post_id BIGINT NOT NULL DEFAULT 0,'
                        . ' user_id BIGINT NOT NULL DEFAULT 0,'
                        . ' created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP'
                        . ')',
                    "CREATE UNIQUE INDEX {$idx}forum_post_likes_post_user ON {$likes} (post_id, user_id)",
                    "CREATE INDEX {$idx}forum_post_likes_user_id ON {$likes} (user_id)",
                    "CREATE INDEX {$idx}forum_post_likes_post_id ON {$likes} (post_id)",
                    "ALTER TABLE {$posts} ADD COLUMN IF NOT EXISTS like_count INTEGER NOT NULL DEFAULT 0",
                ],
                default => [
                    "CREATE TABLE {$likes} ("
                        . ' like_id INTEGER PRIMARY KEY AUTOINCREMENT,'
                        . ' post_id INTEGER NOT NULL DEFAULT 0,'
                        . ' user_id INTEGER NOT NULL DEFAULT 0,'
                        . " created_at TEXT NOT NULL DEFAULT (datetime('now'))"
                        . ')',
                    "CREATE UNIQUE INDEX {$idx}forum_post_likes_post_user ON {$likes} (post_id, user_id)",
                    "CREATE INDEX {$idx}forum_post_likes_user_id ON {$likes} (user_id)",
                    "CREATE INDEX {$idx}forum_post_likes_post_id ON {$likes} (post_id)",
                    // SQLite: ADD COLUMN is ignored if present in newer versions; keep simple.
                    "ALTER TABLE {$posts} ADD COLUMN like_count INTEGER NOT NULL DEFAULT 0",
                ],
            };
        }
    }
}

return new AP_Migration_0011_Forum_Likes_Stats();
