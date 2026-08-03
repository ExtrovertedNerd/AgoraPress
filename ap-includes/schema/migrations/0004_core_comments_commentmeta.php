<?php

/**
 * Migration 0004 — core comments + commentmeta tables.
 *
 * Creates:
 * - {prefix}comments    — nested comments with moderation statuses
 * - {prefix}commentmeta — per-comment key/value metadata
 *
 * Schema is WP-inspired (not a fork) and supports MySQL/MariaDB, SQLite, and
 * PostgreSQL via driver-specific DDL. Table prefix is honored via AP_DB.
 *
 * comment_approved values: '1' (approved), '0' (pending/hold), 'spam', 'trash'.
 * Nesting uses comment_parent. Posts.comment_count is maintained by AP_Comment.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

// Guard: migrator may require this file more than once per process.
if (!class_exists('AP_Migration_0004_Core_Comments_Commentmeta', false)) {
    /**
     * Create ap_comments and ap_commentmeta.
     */
    final class AP_Migration_0004_Core_Comments_Commentmeta implements AP_Migration
    {
        public function version(): int
        {
            return 4;
        }

        public function description(): string
        {
            return 'Core tables: comments, commentmeta';
        }

        public function up(AP_DB $db): void
        {
            $driver = $db->getDriver();

            foreach ($this->createStatements($db, $driver) as $sql) {
                $stmt = $db->query($sql);
                if ($stmt === false) {
                    throw new RuntimeException(
                        'Failed to apply core comments/commentmeta schema: '
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
            $comments = $db->quoteIdentifier($db->table('comments'));
            $commentmeta = $db->quoteIdentifier($db->table('commentmeta'));
            $idx = preg_replace('/[^A-Za-z0-9_]/', '', $db->getPrefix()) ?: 'ap_';

            return match ($driver) {
                'mysql' => $this->mysqlStatements($comments, $commentmeta),
                'pgsql' => $this->pgsqlStatements($comments, $commentmeta, $idx),
                default => $this->sqliteStatements($comments, $commentmeta, $idx),
            };
        }

        /**
         * @return list<string>
         */
        private function mysqlStatements(string $comments, string $commentmeta): array
        {
            return [
                "CREATE TABLE {$comments} ("
                    . ' `comment_ID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
                    . ' `comment_post_ID` BIGINT UNSIGNED NOT NULL DEFAULT 0,'
                    . ' `comment_author` TINYTEXT NOT NULL,'
                    . " `comment_author_email` VARCHAR(100) NOT NULL DEFAULT '',"
                    . " `comment_author_url` VARCHAR(200) NOT NULL DEFAULT '',"
                    . " `comment_author_IP` VARCHAR(100) NOT NULL DEFAULT '',"
                    . ' `comment_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,'
                    . " `comment_date_gmt` DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',"
                    . ' `comment_content` TEXT NOT NULL,'
                    . ' `comment_karma` INT NOT NULL DEFAULT 0,'
                    . " `comment_approved` VARCHAR(20) NOT NULL DEFAULT '1',"
                    . " `comment_agent` VARCHAR(255) NOT NULL DEFAULT '',"
                    . " `comment_type` VARCHAR(20) NOT NULL DEFAULT 'comment',"
                    . ' `comment_parent` BIGINT UNSIGNED NOT NULL DEFAULT 0,'
                    . ' `user_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,'
                    . ' PRIMARY KEY (`comment_ID`),'
                    . ' KEY `comment_post_ID` (`comment_post_ID`),'
                    . ' KEY `comment_approved_date_gmt` (`comment_approved`, `comment_date_gmt`),'
                    . ' KEY `comment_parent` (`comment_parent`),'
                    . ' KEY `comment_author_email` (`comment_author_email`)'
                    . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',

                "CREATE TABLE {$commentmeta} ("
                    . ' `meta_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
                    . ' `comment_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,'
                    . ' `meta_key` VARCHAR(255) DEFAULT NULL,'
                    . ' `meta_value` LONGTEXT,'
                    . ' PRIMARY KEY (`meta_id`),'
                    . ' KEY `comment_id` (`comment_id`),'
                    . ' KEY `meta_key` (`meta_key`(191))'
                    . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            ];
        }

        /**
         * @return list<string>
         */
        private function pgsqlStatements(
            string $comments,
            string $commentmeta,
            string $idx
        ): array {
            // Unquoted identifiers fold to lower case in PostgreSQL; quote mixed-case
            // columns (comment_ID, comment_post_ID, comment_author_IP) so AP_DB
            // quoteIdentifier() matches MySQL/SQLite.
            return [
                "CREATE TABLE {$comments} ("
                    . ' "comment_ID" BIGSERIAL PRIMARY KEY,'
                    . ' "comment_post_ID" BIGINT NOT NULL DEFAULT 0,'
                    . " comment_author TEXT NOT NULL DEFAULT '',"
                    . " comment_author_email VARCHAR(100) NOT NULL DEFAULT '',"
                    . " comment_author_url VARCHAR(200) NOT NULL DEFAULT '',"
                    . ' "comment_author_IP" VARCHAR(100) NOT NULL DEFAULT \'\','
                    . ' comment_date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,'
                    . " comment_date_gmt TIMESTAMP NOT NULL DEFAULT '1970-01-01 00:00:00',"
                    . " comment_content TEXT NOT NULL DEFAULT '',"
                    . ' comment_karma INTEGER NOT NULL DEFAULT 0,'
                    . " comment_approved VARCHAR(20) NOT NULL DEFAULT '1',"
                    . " comment_agent VARCHAR(255) NOT NULL DEFAULT '',"
                    . " comment_type VARCHAR(20) NOT NULL DEFAULT 'comment',"
                    . ' comment_parent BIGINT NOT NULL DEFAULT 0,'
                    . ' user_id BIGINT NOT NULL DEFAULT 0'
                    . ')',
                "CREATE INDEX {$idx}comments_post_id ON {$comments} (\"comment_post_ID\")",
                "CREATE INDEX {$idx}comments_approved_date ON {$comments} (comment_approved, comment_date_gmt)",
                "CREATE INDEX {$idx}comments_parent ON {$comments} (comment_parent)",
                "CREATE INDEX {$idx}comments_author_email ON {$comments} (comment_author_email)",

                "CREATE TABLE {$commentmeta} ("
                    . ' meta_id BIGSERIAL PRIMARY KEY,'
                    . ' comment_id BIGINT NOT NULL DEFAULT 0,'
                    . ' meta_key VARCHAR(255) DEFAULT NULL,'
                    . " meta_value TEXT DEFAULT ''"
                    . ')',
                "CREATE INDEX {$idx}commentmeta_comment_id ON {$commentmeta} (comment_id)",
                "CREATE INDEX {$idx}commentmeta_meta_key ON {$commentmeta} (meta_key)",
            ];
        }

        /**
         * @return list<string>
         */
        private function sqliteStatements(
            string $comments,
            string $commentmeta,
            string $idx
        ): array {
            return [
                "CREATE TABLE {$comments} ("
                    . ' comment_ID INTEGER PRIMARY KEY AUTOINCREMENT,'
                    . ' comment_post_ID INTEGER NOT NULL DEFAULT 0,'
                    . " comment_author TEXT NOT NULL DEFAULT '',"
                    . " comment_author_email TEXT NOT NULL DEFAULT '',"
                    . " comment_author_url TEXT NOT NULL DEFAULT '',"
                    . " comment_author_IP TEXT NOT NULL DEFAULT '',"
                    . " comment_date TEXT NOT NULL DEFAULT '',"
                    . " comment_date_gmt TEXT NOT NULL DEFAULT '',"
                    . " comment_content TEXT NOT NULL DEFAULT '',"
                    . ' comment_karma INTEGER NOT NULL DEFAULT 0,'
                    . " comment_approved TEXT NOT NULL DEFAULT '1',"
                    . " comment_agent TEXT NOT NULL DEFAULT '',"
                    . " comment_type TEXT NOT NULL DEFAULT 'comment',"
                    . ' comment_parent INTEGER NOT NULL DEFAULT 0,'
                    . ' user_id INTEGER NOT NULL DEFAULT 0'
                    . ')',
                "CREATE INDEX {$idx}comments_post_id ON {$comments} (comment_post_ID)",
                "CREATE INDEX {$idx}comments_approved_date ON {$comments} (comment_approved, comment_date_gmt)",
                "CREATE INDEX {$idx}comments_parent ON {$comments} (comment_parent)",
                "CREATE INDEX {$idx}comments_author_email ON {$comments} (comment_author_email)",

                "CREATE TABLE {$commentmeta} ("
                    . ' meta_id INTEGER PRIMARY KEY AUTOINCREMENT,'
                    . ' comment_id INTEGER NOT NULL DEFAULT 0,'
                    . ' meta_key TEXT,'
                    . ' meta_value TEXT'
                    . ')',
                "CREATE INDEX {$idx}commentmeta_comment_id ON {$commentmeta} (comment_id)",
                "CREATE INDEX {$idx}commentmeta_meta_key ON {$commentmeta} (meta_key)",
            ];
        }
    }
}

return new AP_Migration_0004_Core_Comments_Commentmeta();
