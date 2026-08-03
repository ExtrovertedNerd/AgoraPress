<?php

/**
 * Migration 0002 — core posts + postmeta tables.
 *
 * Creates:
 * - {prefix}posts     — posts, pages, revisions, attachments, custom types
 * - {prefix}postmeta  — per-post key/value metadata
 *
 * Schema is WP-inspired (not a fork) and supports MySQL/MariaDB, SQLite, and
 * PostgreSQL via driver-specific DDL. Table prefix is honored via AP_DB.
 *
 * Supports full post statuses, sticky (via meta), password protection,
 * hierarchical pages (post_parent), scheduling (post_date + status),
 * revisions (post_type=revision), and lightweight custom post types.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

// Guard: migrator may require this file more than once per process (multiple
// AP_Migrator instances / discover() calls).
if (!class_exists('AP_Migration_0002_Core_Posts_Postmeta', false)) {
    /**
     * Create ap_posts and ap_postmeta.
     */
    final class AP_Migration_0002_Core_Posts_Postmeta implements AP_Migration
    {
        public function version(): int
        {
            return 2;
        }

        public function description(): string
        {
            return 'Core tables: posts, postmeta';
        }

        public function up(AP_DB $db): void
        {
            $driver = $db->getDriver();

            foreach ($this->createStatements($db, $driver) as $sql) {
                $stmt = $db->query($sql);
                if ($stmt === false) {
                    throw new RuntimeException(
                        'Failed to apply core posts/postmeta schema: '
                        . ($db->lastError() ?? 'unknown error')
                    );
                }
            }
        }

        /**
         * Ordered CREATE TABLE / INDEX statements for the active driver.
         *
         * @return list<string>
         */
        private function createStatements(AP_DB $db, string $driver): array
        {
            $posts = $db->quoteIdentifier($db->table('posts'));
            $postmeta = $db->quoteIdentifier($db->table('postmeta'));
            // Safe fragment of the table prefix for global index names (sqlite/pgsql).
            $idx = preg_replace('/[^A-Za-z0-9_]/', '', $db->getPrefix()) ?: 'ap_';

            return match ($driver) {
                'mysql' => $this->mysqlStatements($posts, $postmeta),
                'pgsql' => $this->pgsqlStatements($posts, $postmeta, $idx),
                default => $this->sqliteStatements($posts, $postmeta, $idx),
            };
        }

        /**
         * @return list<string>
         */
        private function mysqlStatements(string $posts, string $postmeta): array
        {
            return [
                // GMT defaults use a real epoch (not zero-date) for strict SQL modes.
                "CREATE TABLE {$posts} ("
                    . ' `ID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
                    . ' `post_author` BIGINT UNSIGNED NOT NULL DEFAULT 0,'
                    . ' `post_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,'
                    . " `post_date_gmt` DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',"
                    . ' `post_content` LONGTEXT NOT NULL,'
                    . ' `post_title` TEXT NOT NULL,'
                    . ' `post_excerpt` TEXT NOT NULL,'
                    . " `post_status` VARCHAR(20) NOT NULL DEFAULT 'publish',"
                    . " `comment_status` VARCHAR(20) NOT NULL DEFAULT 'open',"
                    . " `ping_status` VARCHAR(20) NOT NULL DEFAULT 'open',"
                    . " `post_password` VARCHAR(255) NOT NULL DEFAULT '',"
                    . " `post_name` VARCHAR(200) NOT NULL DEFAULT '',"
                    . ' `to_ping` TEXT NOT NULL,'
                    . ' `pinged` TEXT NOT NULL,'
                    . ' `post_modified` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,'
                    . " `post_modified_gmt` DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',"
                    . ' `post_content_filtered` LONGTEXT NOT NULL,'
                    . ' `post_parent` BIGINT UNSIGNED NOT NULL DEFAULT 0,'
                    . " `guid` VARCHAR(255) NOT NULL DEFAULT '',"
                    . ' `menu_order` INT NOT NULL DEFAULT 0,'
                    . " `post_type` VARCHAR(20) NOT NULL DEFAULT 'post',"
                    . " `post_mime_type` VARCHAR(100) NOT NULL DEFAULT '',"
                    . ' `comment_count` BIGINT NOT NULL DEFAULT 0,'
                    . ' PRIMARY KEY (`ID`),'
                    . ' KEY `post_name` (`post_name`(191)),'
                    . ' KEY `type_status_date` (`post_type`, `post_status`, `post_date`, `ID`),'
                    . ' KEY `post_parent` (`post_parent`),'
                    . ' KEY `post_author` (`post_author`)'
                    . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',

                "CREATE TABLE {$postmeta} ("
                    . ' `meta_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
                    . ' `post_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,'
                    . ' `meta_key` VARCHAR(255) DEFAULT NULL,'
                    . ' `meta_value` LONGTEXT,'
                    . ' PRIMARY KEY (`meta_id`),'
                    . ' KEY `post_id` (`post_id`),'
                    . ' KEY `meta_key` (`meta_key`(191))'
                    . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            ];
        }

        /**
         * @return list<string>
         */
        private function pgsqlStatements(
            string $posts,
            string $postmeta,
            string $idx
        ): array {
            // Unquoted identifiers fold to lower case in PostgreSQL; we quote via
            // AP_DB so mixed-case columns (e.g. ID) stay as defined.
            return [
                "CREATE TABLE {$posts} ("
                    . ' "ID" BIGSERIAL PRIMARY KEY,'
                    . ' post_author BIGINT NOT NULL DEFAULT 0,'
                    . ' post_date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,'
                    . " post_date_gmt TIMESTAMP NOT NULL DEFAULT '1970-01-01 00:00:00',"
                    . ' post_content TEXT NOT NULL,'
                    . " post_title TEXT NOT NULL DEFAULT '',"
                    . " post_excerpt TEXT NOT NULL DEFAULT '',"
                    . " post_status VARCHAR(20) NOT NULL DEFAULT 'publish',"
                    . " comment_status VARCHAR(20) NOT NULL DEFAULT 'open',"
                    . " ping_status VARCHAR(20) NOT NULL DEFAULT 'open',"
                    . " post_password VARCHAR(255) NOT NULL DEFAULT '',"
                    . " post_name VARCHAR(200) NOT NULL DEFAULT '',"
                    . " to_ping TEXT NOT NULL DEFAULT '',"
                    . " pinged TEXT NOT NULL DEFAULT '',"
                    . ' post_modified TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,'
                    . " post_modified_gmt TIMESTAMP NOT NULL DEFAULT '1970-01-01 00:00:00',"
                    . " post_content_filtered TEXT NOT NULL DEFAULT '',"
                    . ' post_parent BIGINT NOT NULL DEFAULT 0,'
                    . " guid VARCHAR(255) NOT NULL DEFAULT '',"
                    . ' menu_order INTEGER NOT NULL DEFAULT 0,'
                    . " post_type VARCHAR(20) NOT NULL DEFAULT 'post',"
                    . " post_mime_type VARCHAR(100) NOT NULL DEFAULT '',"
                    . ' comment_count BIGINT NOT NULL DEFAULT 0'
                    . ')',
                "CREATE INDEX {$idx}posts_post_name ON {$posts} (post_name)",
                "CREATE INDEX {$idx}posts_type_status_date ON {$posts}"
                    . ' (post_type, post_status, post_date, "ID")',
                "CREATE INDEX {$idx}posts_post_parent ON {$posts} (post_parent)",
                "CREATE INDEX {$idx}posts_post_author ON {$posts} (post_author)",

                "CREATE TABLE {$postmeta} ("
                    . ' meta_id BIGSERIAL PRIMARY KEY,'
                    . ' post_id BIGINT NOT NULL DEFAULT 0,'
                    . ' meta_key VARCHAR(255) DEFAULT NULL,'
                    . ' meta_value TEXT'
                    . ')',
                "CREATE INDEX {$idx}postmeta_post_id ON {$postmeta} (post_id)",
                "CREATE INDEX {$idx}postmeta_meta_key ON {$postmeta} (meta_key)",
            ];
        }

        /**
         * @return list<string>
         */
        private function sqliteStatements(
            string $posts,
            string $postmeta,
            string $idx
        ): array {
            return [
                "CREATE TABLE {$posts} ("
                    . ' ID INTEGER PRIMARY KEY AUTOINCREMENT,'
                    . ' post_author INTEGER NOT NULL DEFAULT 0,'
                    . " post_date TEXT NOT NULL DEFAULT (datetime('now')),"
                    . " post_date_gmt TEXT NOT NULL DEFAULT '1970-01-01 00:00:00',"
                    . " post_content TEXT NOT NULL DEFAULT '',"
                    . " post_title TEXT NOT NULL DEFAULT '',"
                    . " post_excerpt TEXT NOT NULL DEFAULT '',"
                    . " post_status TEXT NOT NULL DEFAULT 'publish',"
                    . " comment_status TEXT NOT NULL DEFAULT 'open',"
                    . " ping_status TEXT NOT NULL DEFAULT 'open',"
                    . " post_password TEXT NOT NULL DEFAULT '',"
                    . " post_name TEXT NOT NULL DEFAULT '',"
                    . " to_ping TEXT NOT NULL DEFAULT '',"
                    . " pinged TEXT NOT NULL DEFAULT '',"
                    . " post_modified TEXT NOT NULL DEFAULT (datetime('now')),"
                    . " post_modified_gmt TEXT NOT NULL DEFAULT '1970-01-01 00:00:00',"
                    . " post_content_filtered TEXT NOT NULL DEFAULT '',"
                    . ' post_parent INTEGER NOT NULL DEFAULT 0,'
                    . " guid TEXT NOT NULL DEFAULT '',"
                    . ' menu_order INTEGER NOT NULL DEFAULT 0,'
                    . " post_type TEXT NOT NULL DEFAULT 'post',"
                    . " post_mime_type TEXT NOT NULL DEFAULT '',"
                    . ' comment_count INTEGER NOT NULL DEFAULT 0'
                    . ')',
                "CREATE INDEX {$idx}posts_post_name ON {$posts} (post_name)",
                "CREATE INDEX {$idx}posts_type_status_date ON {$posts}"
                    . ' (post_type, post_status, post_date, ID)',
                "CREATE INDEX {$idx}posts_post_parent ON {$posts} (post_parent)",
                "CREATE INDEX {$idx}posts_post_author ON {$posts} (post_author)",

                "CREATE TABLE {$postmeta} ("
                    . ' meta_id INTEGER PRIMARY KEY AUTOINCREMENT,'
                    . ' post_id INTEGER NOT NULL DEFAULT 0,'
                    . ' meta_key TEXT DEFAULT NULL,'
                    . ' meta_value TEXT'
                    . ')',
                "CREATE INDEX {$idx}postmeta_post_id ON {$postmeta} (post_id)",
                "CREATE INDEX {$idx}postmeta_meta_key ON {$postmeta} (meta_key)",
            ];
        }
    }
}

return new AP_Migration_0002_Core_Posts_Postmeta();
