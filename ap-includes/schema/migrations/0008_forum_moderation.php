<?php

/**
 * Migration 0008 — forum moderation support tables.
 *
 * Creates:
 * - {prefix}warnings — staff warnings issued to users
 * - {prefix}bans     — user / IP / email bans and suspensions
 *
 * Reports already ship in migration 0005 ({prefix}reports). Topic soft-delete,
 * post edit metadata, and lock/sticky flags live on topics / forum_posts.
 *
 * Multi-driver DDL (MySQL/MariaDB, SQLite, PostgreSQL).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

// Guard: migrator may require this file more than once per process.
if (!class_exists('AP_Migration_0008_Forum_Moderation', false)) {
    /**
     * Create warnings and bans tables for full forum moderation tools.
     */
    final class AP_Migration_0008_Forum_Moderation implements AP_Migration
    {
        public function version(): int
        {
            return 8;
        }

        public function description(): string
        {
            return 'Forum moderation: warnings and bans tables';
        }

        public function up(AP_DB $db): void
        {
            $driver = $db->getDriver();

            foreach ($this->createStatements($db, $driver) as $sql) {
                $stmt = $db->query($sql);
                if ($stmt === false) {
                    throw new RuntimeException(
                        'Failed to apply forum moderation schema: '
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
            $warnings = $db->quoteIdentifier($db->table('warnings'));
            $bans = $db->quoteIdentifier($db->table('bans'));
            $idx = preg_replace('/[^A-Za-z0-9_]/', '', $db->getPrefix()) ?: 'ap_';

            return match ($driver) {
                'mysql' => $this->mysqlStatements($warnings, $bans),
                'pgsql' => $this->pgsqlStatements($warnings, $bans, $idx),
                default => $this->sqliteStatements($warnings, $bans, $idx),
            };
        }

        /**
         * @return list<string>
         */
        private function mysqlStatements(string $warnings, string $bans): array
        {
            return [
                // warning_status: active | expired | revoked
                "CREATE TABLE {$warnings} ("
                    . ' `warning_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
                    . ' `user_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,'
                    . ' `issuer_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,'
                    . " `warning_reason` VARCHAR(255) NOT NULL DEFAULT '',"
                    . ' `warning_notes` TEXT NOT NULL,'
                    . " `related_type` VARCHAR(20) NOT NULL DEFAULT '',"
                    . ' `related_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,'
                    . " `warning_status` VARCHAR(20) NOT NULL DEFAULT 'active',"
                    . ' `warned_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,'
                    . ' `expires_at` DATETIME DEFAULT NULL,'
                    . ' `revoked_at` DATETIME DEFAULT NULL,'
                    . ' `revoked_by` BIGINT UNSIGNED NOT NULL DEFAULT 0,'
                    . ' PRIMARY KEY (`warning_id`),'
                    . ' KEY `user_id` (`user_id`),'
                    . ' KEY `issuer_id` (`issuer_id`),'
                    . ' KEY `warning_status` (`warning_status`),'
                    . ' KEY `user_status` (`user_id`, `warning_status`)'
                    . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',

                // ban_type: user | ip | email
                // ban_status: active | expired | lifted
                "CREATE TABLE {$bans} ("
                    . ' `ban_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
                    . " `ban_type` VARCHAR(20) NOT NULL DEFAULT 'user',"
                    . " `ban_value` VARCHAR(255) NOT NULL DEFAULT '',"
                    . ' `user_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,'
                    . " `ban_reason` VARCHAR(255) NOT NULL DEFAULT '',"
                    . ' `ban_notes` TEXT NOT NULL,'
                    . ' `banned_by` BIGINT UNSIGNED NOT NULL DEFAULT 0,'
                    . " `ban_status` VARCHAR(20) NOT NULL DEFAULT 'active',"
                    . ' `banned_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,'
                    . ' `expires_at` DATETIME DEFAULT NULL,'
                    . ' `lifted_at` DATETIME DEFAULT NULL,'
                    . ' `lifted_by` BIGINT UNSIGNED NOT NULL DEFAULT 0,'
                    . ' PRIMARY KEY (`ban_id`),'
                    . ' KEY `ban_status` (`ban_status`),'
                    . ' KEY `ban_type_value` (`ban_type`, `ban_value`(191)),'
                    . ' KEY `user_id` (`user_id`),'
                    . ' KEY `user_status` (`user_id`, `ban_status`)'
                    . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            ];
        }

        /**
         * @return list<string>
         */
        private function pgsqlStatements(string $warnings, string $bans, string $idx): array
        {
            return [
                "CREATE TABLE {$warnings} ("
                    . ' warning_id BIGSERIAL PRIMARY KEY,'
                    . ' user_id BIGINT NOT NULL DEFAULT 0,'
                    . ' issuer_id BIGINT NOT NULL DEFAULT 0,'
                    . " warning_reason VARCHAR(255) NOT NULL DEFAULT '',"
                    . " warning_notes TEXT NOT NULL DEFAULT '',"
                    . " related_type VARCHAR(20) NOT NULL DEFAULT '',"
                    . ' related_id BIGINT NOT NULL DEFAULT 0,'
                    . " warning_status VARCHAR(20) NOT NULL DEFAULT 'active',"
                    . ' warned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,'
                    . ' expires_at TIMESTAMP DEFAULT NULL,'
                    . ' revoked_at TIMESTAMP DEFAULT NULL,'
                    . ' revoked_by BIGINT NOT NULL DEFAULT 0'
                    . ')',
                "CREATE INDEX {$idx}warnings_user_id ON {$warnings} (user_id)",
                "CREATE INDEX {$idx}warnings_issuer_id ON {$warnings} (issuer_id)",
                "CREATE INDEX {$idx}warnings_status ON {$warnings} (warning_status)",
                "CREATE INDEX {$idx}warnings_user_status ON {$warnings} (user_id, warning_status)",

                "CREATE TABLE {$bans} ("
                    . ' ban_id BIGSERIAL PRIMARY KEY,'
                    . " ban_type VARCHAR(20) NOT NULL DEFAULT 'user',"
                    . " ban_value VARCHAR(255) NOT NULL DEFAULT '',"
                    . ' user_id BIGINT NOT NULL DEFAULT 0,'
                    . " ban_reason VARCHAR(255) NOT NULL DEFAULT '',"
                    . " ban_notes TEXT NOT NULL DEFAULT '',"
                    . ' banned_by BIGINT NOT NULL DEFAULT 0,'
                    . " ban_status VARCHAR(20) NOT NULL DEFAULT 'active',"
                    . ' banned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,'
                    . ' expires_at TIMESTAMP DEFAULT NULL,'
                    . ' lifted_at TIMESTAMP DEFAULT NULL,'
                    . ' lifted_by BIGINT NOT NULL DEFAULT 0'
                    . ')',
                "CREATE INDEX {$idx}bans_status ON {$bans} (ban_status)",
                "CREATE INDEX {$idx}bans_type_value ON {$bans} (ban_type, ban_value)",
                "CREATE INDEX {$idx}bans_user_id ON {$bans} (user_id)",
                "CREATE INDEX {$idx}bans_user_status ON {$bans} (user_id, ban_status)",
            ];
        }

        /**
         * @return list<string>
         */
        private function sqliteStatements(string $warnings, string $bans, string $idx): array
        {
            return [
                "CREATE TABLE {$warnings} ("
                    . ' warning_id INTEGER PRIMARY KEY AUTOINCREMENT,'
                    . ' user_id INTEGER NOT NULL DEFAULT 0,'
                    . ' issuer_id INTEGER NOT NULL DEFAULT 0,'
                    . " warning_reason TEXT NOT NULL DEFAULT '',"
                    . " warning_notes TEXT NOT NULL DEFAULT '',"
                    . " related_type TEXT NOT NULL DEFAULT '',"
                    . ' related_id INTEGER NOT NULL DEFAULT 0,'
                    . " warning_status TEXT NOT NULL DEFAULT 'active',"
                    . " warned_at TEXT NOT NULL DEFAULT '',"
                    . ' expires_at TEXT DEFAULT NULL,'
                    . ' revoked_at TEXT DEFAULT NULL,'
                    . ' revoked_by INTEGER NOT NULL DEFAULT 0'
                    . ')',
                "CREATE INDEX {$idx}warnings_user_id ON {$warnings} (user_id)",
                "CREATE INDEX {$idx}warnings_issuer_id ON {$warnings} (issuer_id)",
                "CREATE INDEX {$idx}warnings_status ON {$warnings} (warning_status)",
                "CREATE INDEX {$idx}warnings_user_status ON {$warnings} (user_id, warning_status)",

                "CREATE TABLE {$bans} ("
                    . ' ban_id INTEGER PRIMARY KEY AUTOINCREMENT,'
                    . " ban_type TEXT NOT NULL DEFAULT 'user',"
                    . " ban_value TEXT NOT NULL DEFAULT '',"
                    . ' user_id INTEGER NOT NULL DEFAULT 0,'
                    . " ban_reason TEXT NOT NULL DEFAULT '',"
                    . " ban_notes TEXT NOT NULL DEFAULT '',"
                    . ' banned_by INTEGER NOT NULL DEFAULT 0,'
                    . " ban_status TEXT NOT NULL DEFAULT 'active',"
                    . " banned_at TEXT NOT NULL DEFAULT '',"
                    . ' expires_at TEXT DEFAULT NULL,'
                    . ' lifted_at TEXT DEFAULT NULL,'
                    . ' lifted_by INTEGER NOT NULL DEFAULT 0'
                    . ')',
                "CREATE INDEX {$idx}bans_status ON {$bans} (ban_status)",
                "CREATE INDEX {$idx}bans_type_value ON {$bans} (ban_type, ban_value)",
                "CREATE INDEX {$idx}bans_user_id ON {$bans} (user_id)",
                "CREATE INDEX {$idx}bans_user_status ON {$bans} (user_id, ban_status)",
            ];
        }
    }
}

return new AP_Migration_0008_Forum_Moderation();
