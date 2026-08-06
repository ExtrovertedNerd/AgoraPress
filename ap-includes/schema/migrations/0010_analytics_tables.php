<?php

/**
 * Migration 0010 — local privacy-respecting site analytics tables.
 *
 * Creates:
 * - {prefix}analytics_hits  — per-request hit log (minimal fields)
 * - {prefix}analytics_daily — daily path/object rollups for ACP reports
 *
 * Server-side only; no third-party endpoints. Retention / prune is handled by
 * application cron. Options (seeded off/90 by installer; see AP_Analytics):
 * analytics_enabled (default off), analytics_retention_days (default 90).
 *
 * Multi-driver DDL (MySQL/MariaDB, SQLite, PostgreSQL).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

// Guard: migrator may require this file more than once per process.
if (!class_exists('AP_Migration_0010_Analytics_Tables', false)) {
    /**
     * Create analytics_hits and analytics_daily tables.
     */
    final class AP_Migration_0010_Analytics_Tables implements AP_Migration
    {
        public function version(): int
        {
            return 10;
        }

        public function description(): string
        {
            return 'Local analytics: analytics_hits and analytics_daily tables';
        }

        public function up(AP_DB $db): void
        {
            $driver = $db->getDriver();

            foreach ($this->createStatements($db, $driver) as $sql) {
                $stmt = $db->query($sql);
                if ($stmt === false) {
                    throw new RuntimeException(
                        'Failed to apply analytics schema: '
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
            $hits = $db->quoteIdentifier($db->table('analytics_hits'));
            $daily = $db->quoteIdentifier($db->table('analytics_daily'));
            $idx = preg_replace('/[^A-Za-z0-9_]/', '', $db->getPrefix()) ?: 'ap_';

            return match ($driver) {
                'mysql' => $this->mysqlStatements($hits, $daily),
                'pgsql' => $this->pgsqlStatements($hits, $daily, $idx),
                default => $this->sqliteStatements($hits, $daily, $idx),
            };
        }

        /**
         * @return list<string>
         */
        private function mysqlStatements(string $hits, string $daily): array
        {
            return [
                "CREATE TABLE {$hits} ("
                    . ' `hit_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
                    . ' `hit_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,'
                    . " `path` VARCHAR(512) NOT NULL DEFAULT '',"
                    . ' `object_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,'
                    . ' `status_code` SMALLINT UNSIGNED NOT NULL DEFAULT 200,'
                    . " `referrer` VARCHAR(512) NOT NULL DEFAULT '',"
                    . " `ua_class` VARCHAR(16) NOT NULL DEFAULT '',"
                    . ' `is_admin` TINYINT(1) NOT NULL DEFAULT 0,'
                    . ' PRIMARY KEY (`hit_id`),'
                    . ' KEY `hit_time` (`hit_time`),'
                    . ' KEY `path` (`path`(191)),'
                    . ' KEY `object_id` (`object_id`),'
                    . ' KEY `status_code` (`status_code`),'
                    . ' KEY `ua_class` (`ua_class`)'
                    . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',

                "CREATE TABLE {$daily} ("
                    . ' `day` DATE NOT NULL,'
                    . " `path` VARCHAR(512) NOT NULL DEFAULT '',"
                    . ' `object_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,'
                    . ' `hits` INT UNSIGNED NOT NULL DEFAULT 0,'
                    . ' PRIMARY KEY (`day`, `path`(191), `object_id`),'
                    . ' KEY `day` (`day`),'
                    . ' KEY `path` (`path`(191)),'
                    . ' KEY `object_id` (`object_id`)'
                    . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            ];
        }

        /**
         * @return list<string>
         */
        private function pgsqlStatements(string $hits, string $daily, string $idx): array
        {
            return [
                "CREATE TABLE {$hits} ("
                    . ' hit_id BIGSERIAL PRIMARY KEY,'
                    . ' hit_time TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,'
                    . " path VARCHAR(512) NOT NULL DEFAULT '',"
                    . ' object_id BIGINT NOT NULL DEFAULT 0,'
                    . ' status_code SMALLINT NOT NULL DEFAULT 200,'
                    . " referrer VARCHAR(512) NOT NULL DEFAULT '',"
                    . " ua_class VARCHAR(16) NOT NULL DEFAULT '',"
                    . ' is_admin SMALLINT NOT NULL DEFAULT 0'
                    . ')',
                "CREATE INDEX {$idx}analytics_hits_hit_time ON {$hits} (hit_time)",
                "CREATE INDEX {$idx}analytics_hits_path ON {$hits} (path)",
                "CREATE INDEX {$idx}analytics_hits_object_id ON {$hits} (object_id)",
                "CREATE INDEX {$idx}analytics_hits_status_code ON {$hits} (status_code)",
                "CREATE INDEX {$idx}analytics_hits_ua_class ON {$hits} (ua_class)",

                "CREATE TABLE {$daily} ("
                    . ' day DATE NOT NULL,'
                    . " path VARCHAR(512) NOT NULL DEFAULT '',"
                    . ' object_id BIGINT NOT NULL DEFAULT 0,'
                    . ' hits INTEGER NOT NULL DEFAULT 0,'
                    . ' PRIMARY KEY (day, path, object_id)'
                    . ')',
                "CREATE INDEX {$idx}analytics_daily_day ON {$daily} (day)",
                "CREATE INDEX {$idx}analytics_daily_path ON {$daily} (path)",
                "CREATE INDEX {$idx}analytics_daily_object_id ON {$daily} (object_id)",
            ];
        }

        /**
         * @return list<string>
         */
        private function sqliteStatements(string $hits, string $daily, string $idx): array
        {
            return [
                "CREATE TABLE {$hits} ("
                    . ' hit_id INTEGER PRIMARY KEY AUTOINCREMENT,'
                    . " hit_time TEXT NOT NULL DEFAULT '',"
                    . " path TEXT NOT NULL DEFAULT '',"
                    . ' object_id INTEGER NOT NULL DEFAULT 0,'
                    . ' status_code INTEGER NOT NULL DEFAULT 200,'
                    . " referrer TEXT NOT NULL DEFAULT '',"
                    . " ua_class TEXT NOT NULL DEFAULT '',"
                    . ' is_admin INTEGER NOT NULL DEFAULT 0'
                    . ')',
                "CREATE INDEX {$idx}analytics_hits_hit_time ON {$hits} (hit_time)",
                "CREATE INDEX {$idx}analytics_hits_path ON {$hits} (path)",
                "CREATE INDEX {$idx}analytics_hits_object_id ON {$hits} (object_id)",
                "CREATE INDEX {$idx}analytics_hits_status_code ON {$hits} (status_code)",
                "CREATE INDEX {$idx}analytics_hits_ua_class ON {$hits} (ua_class)",

                "CREATE TABLE {$daily} ("
                    . " day TEXT NOT NULL DEFAULT '',"
                    . " path TEXT NOT NULL DEFAULT '',"
                    . ' object_id INTEGER NOT NULL DEFAULT 0,'
                    . ' hits INTEGER NOT NULL DEFAULT 0,'
                    . ' PRIMARY KEY (day, path, object_id)'
                    . ')',
                "CREATE INDEX {$idx}analytics_daily_day ON {$daily} (day)",
                "CREATE INDEX {$idx}analytics_daily_path ON {$daily} (path)",
                "CREATE INDEX {$idx}analytics_daily_object_id ON {$daily} (object_id)",
            ];
        }
    }
}

return new AP_Migration_0010_Analytics_Tables();
