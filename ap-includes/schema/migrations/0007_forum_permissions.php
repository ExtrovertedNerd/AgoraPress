<?php

/**
 * Migration 0007 — granular per-forum permissions.
 *
 * Creates {prefix}forum_permissions: ACL rows for group × forum × capability.
 * forum_id = 0 means global defaults applied when no forum-specific row exists.
 * perm_setting: 1 = allow, 0 = deny (deny wins across a user's groups).
 *
 * Multi-driver DDL (MySQL/MariaDB, SQLite, PostgreSQL).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

// Guard: migrator may require this file more than once per process.
if (!class_exists('AP_Migration_0007_Forum_Permissions', false)) {
    /**
     * Create forum permissions ACL table.
     */
    final class AP_Migration_0007_Forum_Permissions implements AP_Migration
    {
        public function version(): int
        {
            return 7;
        }

        public function description(): string
        {
            return 'Forum permissions: forum_permissions ACL (group × forum × capability)';
        }

        public function up(AP_DB $db): void
        {
            $driver = $db->getDriver();

            foreach ($this->createStatements($db, $driver) as $sql) {
                $stmt = $db->query($sql);
                if ($stmt === false) {
                    throw new RuntimeException(
                        'Failed to apply forum permissions schema: '
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
            $table = $db->quoteIdentifier($db->table('forum_permissions'));
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
                    . ' `permission_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
                    . ' `forum_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,'
                    . ' `group_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,'
                    . " `perm_name` VARCHAR(50) NOT NULL DEFAULT '',"
                    . ' `perm_setting` TINYINT NOT NULL DEFAULT 0,'
                    . ' PRIMARY KEY (`permission_id`),'
                    . ' UNIQUE KEY `forum_group_perm` (`forum_id`, `group_id`, `perm_name`),'
                    . ' KEY `group_id` (`group_id`),'
                    . ' KEY `forum_id` (`forum_id`),'
                    . ' KEY `perm_name` (`perm_name`)'
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
                    . ' permission_id BIGSERIAL PRIMARY KEY,'
                    . ' forum_id BIGINT NOT NULL DEFAULT 0,'
                    . ' group_id BIGINT NOT NULL DEFAULT 0,'
                    . " perm_name VARCHAR(50) NOT NULL DEFAULT '',"
                    . ' perm_setting SMALLINT NOT NULL DEFAULT 0'
                    . ')',
                "CREATE UNIQUE INDEX {$idx}forum_permissions_forum_group_perm"
                    . " ON {$table} (forum_id, group_id, perm_name)",
                "CREATE INDEX {$idx}forum_permissions_group_id ON {$table} (group_id)",
                "CREATE INDEX {$idx}forum_permissions_forum_id ON {$table} (forum_id)",
                "CREATE INDEX {$idx}forum_permissions_perm_name ON {$table} (perm_name)",
            ];
        }

        /**
         * @return list<string>
         */
        private function sqliteStatements(string $table, string $idx): array
        {
            return [
                "CREATE TABLE {$table} ("
                    . ' permission_id INTEGER PRIMARY KEY AUTOINCREMENT,'
                    . ' forum_id INTEGER NOT NULL DEFAULT 0,'
                    . ' group_id INTEGER NOT NULL DEFAULT 0,'
                    . " perm_name TEXT NOT NULL DEFAULT '',"
                    . ' perm_setting INTEGER NOT NULL DEFAULT 0'
                    . ')',
                "CREATE UNIQUE INDEX {$idx}forum_permissions_forum_group_perm"
                    . " ON {$table} (forum_id, group_id, perm_name)",
                "CREATE INDEX {$idx}forum_permissions_group_id ON {$table} (group_id)",
                "CREATE INDEX {$idx}forum_permissions_forum_id ON {$table} (forum_id)",
                "CREATE INDEX {$idx}forum_permissions_perm_name ON {$table} (perm_name)",
            ];
        }
    }
}

return new AP_Migration_0007_Forum_Permissions();
