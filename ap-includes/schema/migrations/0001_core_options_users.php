<?php

/**
 * Migration 0001 — core options + users tables.
 *
 * Creates:
 * - {prefix}options   — site options (Options API)
 * - {prefix}users     — accounts (shared by blog, forums, admin)
 * - {prefix}usermeta  — per-user key/value metadata
 *
 * Schema is WP-inspired (not a fork) and supports MySQL/MariaDB, SQLite, and
 * PostgreSQL via driver-specific DDL. Table prefix is honored via AP_DB.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

// Guard: migrator may require this file more than once per process (multiple
// AP_Migrator instances / discover() calls).
if (!class_exists('AP_Migration_0001_Core_Options_Users', false)) {
    /**
     * Create ap_options, ap_users, and ap_usermeta.
     */
    final class AP_Migration_0001_Core_Options_Users implements AP_Migration
    {
        public function version(): int
        {
            return 1;
        }

        public function description(): string
        {
            return 'Core tables: options, users, usermeta';
        }

        public function up(AP_DB $db): void
        {
            $driver = $db->getDriver();

            foreach ($this->createStatements($db, $driver) as $sql) {
                $stmt = $db->query($sql);
                if ($stmt === false) {
                    throw new RuntimeException(
                        'Failed to apply core options/users schema: '
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
            $options = $db->quoteIdentifier($db->table('options'));
            $users = $db->quoteIdentifier($db->table('users'));
            $usermeta = $db->quoteIdentifier($db->table('usermeta'));
            // Safe fragment of the table prefix for global index names (sqlite/pgsql).
            $idx = preg_replace('/[^A-Za-z0-9_]/', '', $db->getPrefix()) ?: 'ap_';

            return match ($driver) {
                'mysql' => $this->mysqlStatements($options, $users, $usermeta),
                'pgsql' => $this->pgsqlStatements($options, $users, $usermeta, $idx),
                default => $this->sqliteStatements($options, $users, $usermeta, $idx),
            };
        }

        /**
         * @return list<string>
         */
        private function mysqlStatements(string $options, string $users, string $usermeta): array
        {
            return [
                "CREATE TABLE {$options} ("
                    . ' `option_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
                    . " `option_name` VARCHAR(191) NOT NULL DEFAULT '',"
                    . ' `option_value` LONGTEXT NOT NULL,'
                    . " `autoload` VARCHAR(20) NOT NULL DEFAULT 'yes',"
                    . ' PRIMARY KEY (`option_id`),'
                    . ' UNIQUE KEY `option_name` (`option_name`),'
                    . ' KEY `autoload` (`autoload`)'
                    . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',

                "CREATE TABLE {$users} ("
                    . ' `ID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
                    . " `user_login` VARCHAR(60) NOT NULL DEFAULT '',"
                    . " `user_pass` VARCHAR(255) NOT NULL DEFAULT '',"
                    . " `user_nicename` VARCHAR(50) NOT NULL DEFAULT '',"
                    . " `user_email` VARCHAR(100) NOT NULL DEFAULT '',"
                    . " `user_url` VARCHAR(100) NOT NULL DEFAULT '',"
                    . ' `user_registered` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,'
                    . " `user_activation_key` VARCHAR(255) NOT NULL DEFAULT '',"
                    . ' `user_status` INT NOT NULL DEFAULT 0,'
                    . " `display_name` VARCHAR(250) NOT NULL DEFAULT '',"
                    . ' PRIMARY KEY (`ID`),'
                    . ' KEY `user_login_key` (`user_login`),'
                    . ' KEY `user_nicename` (`user_nicename`),'
                    . ' KEY `user_email` (`user_email`)'
                    . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',

                "CREATE TABLE {$usermeta} ("
                    . ' `umeta_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
                    . ' `user_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,'
                    . ' `meta_key` VARCHAR(255) DEFAULT NULL,'
                    . ' `meta_value` LONGTEXT,'
                    . ' PRIMARY KEY (`umeta_id`),'
                    . ' KEY `user_id` (`user_id`),'
                    . ' KEY `meta_key` (`meta_key`(191))'
                    . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            ];
        }

        /**
         * @return list<string>
         */
        private function pgsqlStatements(
            string $options,
            string $users,
            string $usermeta,
            string $idx
        ): array {
            // Unquoted identifiers fold to lower case in PostgreSQL; we quote via
            // AP_DB so mixed-case columns (e.g. ID) stay as defined.
            return [
                "CREATE TABLE {$options} ("
                    . ' option_id BIGSERIAL PRIMARY KEY,'
                    . " option_name VARCHAR(191) NOT NULL DEFAULT '',"
                    . ' option_value TEXT NOT NULL,'
                    . " autoload VARCHAR(20) NOT NULL DEFAULT 'yes'"
                    . ')',
                "CREATE UNIQUE INDEX {$idx}options_option_name ON {$options} (option_name)",
                "CREATE INDEX {$idx}options_autoload ON {$options} (autoload)",

                "CREATE TABLE {$users} ("
                    . ' "ID" BIGSERIAL PRIMARY KEY,'
                    . " user_login VARCHAR(60) NOT NULL DEFAULT '',"
                    . " user_pass VARCHAR(255) NOT NULL DEFAULT '',"
                    . " user_nicename VARCHAR(50) NOT NULL DEFAULT '',"
                    . " user_email VARCHAR(100) NOT NULL DEFAULT '',"
                    . " user_url VARCHAR(100) NOT NULL DEFAULT '',"
                    . ' user_registered TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,'
                    . " user_activation_key VARCHAR(255) NOT NULL DEFAULT '',"
                    . ' user_status INTEGER NOT NULL DEFAULT 0,'
                    . " display_name VARCHAR(250) NOT NULL DEFAULT ''"
                    . ')',
                "CREATE INDEX {$idx}users_user_login ON {$users} (user_login)",
                "CREATE INDEX {$idx}users_user_nicename ON {$users} (user_nicename)",
                "CREATE INDEX {$idx}users_user_email ON {$users} (user_email)",

                "CREATE TABLE {$usermeta} ("
                    . ' umeta_id BIGSERIAL PRIMARY KEY,'
                    . ' user_id BIGINT NOT NULL DEFAULT 0,'
                    . ' meta_key VARCHAR(255) DEFAULT NULL,'
                    . ' meta_value TEXT'
                    . ')',
                "CREATE INDEX {$idx}usermeta_user_id ON {$usermeta} (user_id)",
                "CREATE INDEX {$idx}usermeta_meta_key ON {$usermeta} (meta_key)",
            ];
        }

        /**
         * @return list<string>
         */
        private function sqliteStatements(
            string $options,
            string $users,
            string $usermeta,
            string $idx
        ): array {
            return [
                "CREATE TABLE {$options} ("
                    . ' option_id INTEGER PRIMARY KEY AUTOINCREMENT,'
                    . " option_name TEXT NOT NULL DEFAULT '' UNIQUE,"
                    . ' option_value TEXT NOT NULL,'
                    . " autoload TEXT NOT NULL DEFAULT 'yes'"
                    . ')',
                "CREATE INDEX {$idx}options_autoload ON {$options} (autoload)",

                "CREATE TABLE {$users} ("
                    . ' ID INTEGER PRIMARY KEY AUTOINCREMENT,'
                    . " user_login TEXT NOT NULL DEFAULT '',"
                    . " user_pass TEXT NOT NULL DEFAULT '',"
                    . " user_nicename TEXT NOT NULL DEFAULT '',"
                    . " user_email TEXT NOT NULL DEFAULT '',"
                    . " user_url TEXT NOT NULL DEFAULT '',"
                    . " user_registered TEXT NOT NULL DEFAULT (datetime('now')),"
                    . " user_activation_key TEXT NOT NULL DEFAULT '',"
                    . ' user_status INTEGER NOT NULL DEFAULT 0,'
                    . " display_name TEXT NOT NULL DEFAULT ''"
                    . ')',
                "CREATE INDEX {$idx}users_user_login ON {$users} (user_login)",
                "CREATE INDEX {$idx}users_user_nicename ON {$users} (user_nicename)",
                "CREATE INDEX {$idx}users_user_email ON {$users} (user_email)",

                "CREATE TABLE {$usermeta} ("
                    . ' umeta_id INTEGER PRIMARY KEY AUTOINCREMENT,'
                    . ' user_id INTEGER NOT NULL DEFAULT 0,'
                    . ' meta_key TEXT DEFAULT NULL,'
                    . ' meta_value TEXT'
                    . ')',
                "CREATE INDEX {$idx}usermeta_user_id ON {$usermeta} (user_id)",
                "CREATE INDEX {$idx}usermeta_meta_key ON {$usermeta} (meta_key)",
            ];
        }
    }
}

return new AP_Migration_0001_Core_Options_Users();
