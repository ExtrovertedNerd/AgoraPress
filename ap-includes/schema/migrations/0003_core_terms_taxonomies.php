<?php

/**
 * Migration 0003 — core terms + taxonomies tables.
 *
 * Creates:
 * - {prefix}terms              — term names/slugs (shared across taxonomies)
 * - {prefix}term_taxonomy      — taxonomy membership + hierarchy + counts
 * - {prefix}term_relationships — object (post) ↔ term_taxonomy links
 *
 * Schema is WP-inspired (not a fork) and supports MySQL/MariaDB, SQLite, and
 * PostgreSQL via driver-specific DDL. Table prefix is honored via AP_DB.
 *
 * Built-in taxonomies (category, post_tag) and default “Uncategorized” term
 * are registered/seeded by AP_Taxonomy after migrations run.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

// Guard: migrator may require this file more than once per process.
if (!class_exists('AP_Migration_0003_Core_Terms_Taxonomies', false)) {
    /**
     * Create ap_terms, ap_term_taxonomy, ap_term_relationships.
     */
    final class AP_Migration_0003_Core_Terms_Taxonomies implements AP_Migration
    {
        public function version(): int
        {
            return 3;
        }

        public function description(): string
        {
            return 'Core tables: terms, term_taxonomy, term_relationships';
        }

        public function up(AP_DB $db): void
        {
            $driver = $db->getDriver();

            foreach ($this->createStatements($db, $driver) as $sql) {
                $stmt = $db->query($sql);
                if ($stmt === false) {
                    throw new RuntimeException(
                        'Failed to apply core terms/taxonomies schema: '
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
            $terms = $db->quoteIdentifier($db->table('terms'));
            $tax = $db->quoteIdentifier($db->table('term_taxonomy'));
            $rel = $db->quoteIdentifier($db->table('term_relationships'));
            $idx = preg_replace('/[^A-Za-z0-9_]/', '', $db->getPrefix()) ?: 'ap_';

            return match ($driver) {
                'mysql' => $this->mysqlStatements($terms, $tax, $rel),
                'pgsql' => $this->pgsqlStatements($terms, $tax, $rel, $idx),
                default => $this->sqliteStatements($terms, $tax, $rel, $idx),
            };
        }

        /**
         * @return list<string>
         */
        private function mysqlStatements(string $terms, string $tax, string $rel): array
        {
            return [
                "CREATE TABLE {$terms} ("
                    . ' `term_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
                    . " `name` VARCHAR(200) NOT NULL DEFAULT '',"
                    . " `slug` VARCHAR(200) NOT NULL DEFAULT '',"
                    . ' `term_group` BIGINT NOT NULL DEFAULT 0,'
                    . ' PRIMARY KEY (`term_id`),'
                    . ' KEY `slug` (`slug`(191)),'
                    . ' KEY `name` (`name`(191))'
                    . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',

                "CREATE TABLE {$tax} ("
                    . ' `term_taxonomy_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
                    . ' `term_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,'
                    . " `taxonomy` VARCHAR(32) NOT NULL DEFAULT '',"
                    . ' `description` LONGTEXT NOT NULL,'
                    . ' `parent` BIGINT UNSIGNED NOT NULL DEFAULT 0,'
                    . ' `count` BIGINT NOT NULL DEFAULT 0,'
                    . ' PRIMARY KEY (`term_taxonomy_id`),'
                    . ' UNIQUE KEY `term_id_taxonomy` (`term_id`, `taxonomy`),'
                    . ' KEY `taxonomy` (`taxonomy`)'
                    . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',

                "CREATE TABLE {$rel} ("
                    . ' `object_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,'
                    . ' `term_taxonomy_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,'
                    . ' `term_order` INT NOT NULL DEFAULT 0,'
                    . ' PRIMARY KEY (`object_id`, `term_taxonomy_id`),'
                    . ' KEY `term_taxonomy_id` (`term_taxonomy_id`)'
                    . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            ];
        }

        /**
         * @return list<string>
         */
        private function pgsqlStatements(
            string $terms,
            string $tax,
            string $rel,
            string $idx
        ): array {
            return [
                "CREATE TABLE {$terms} ("
                    . ' term_id BIGSERIAL PRIMARY KEY,'
                    . " name VARCHAR(200) NOT NULL DEFAULT '',"
                    . " slug VARCHAR(200) NOT NULL DEFAULT '',"
                    . ' term_group BIGINT NOT NULL DEFAULT 0'
                    . ')',
                "CREATE INDEX {$idx}terms_slug ON {$terms} (slug)",
                "CREATE INDEX {$idx}terms_name ON {$terms} (name)",

                "CREATE TABLE {$tax} ("
                    . ' term_taxonomy_id BIGSERIAL PRIMARY KEY,'
                    . ' term_id BIGINT NOT NULL DEFAULT 0,'
                    . " taxonomy VARCHAR(32) NOT NULL DEFAULT '',"
                    . " description TEXT NOT NULL DEFAULT '',"
                    . ' parent BIGINT NOT NULL DEFAULT 0,'
                    . ' count BIGINT NOT NULL DEFAULT 0'
                    . ')',
                "CREATE UNIQUE INDEX {$idx}term_taxonomy_term_id_taxonomy ON {$tax} (term_id, taxonomy)",
                "CREATE INDEX {$idx}term_taxonomy_taxonomy ON {$tax} (taxonomy)",

                "CREATE TABLE {$rel} ("
                    . ' object_id BIGINT NOT NULL DEFAULT 0,'
                    . ' term_taxonomy_id BIGINT NOT NULL DEFAULT 0,'
                    . ' term_order INTEGER NOT NULL DEFAULT 0,'
                    . ' PRIMARY KEY (object_id, term_taxonomy_id)'
                    . ')',
                "CREATE INDEX {$idx}term_relationships_tt ON {$rel} (term_taxonomy_id)",
            ];
        }

        /**
         * @return list<string>
         */
        private function sqliteStatements(
            string $terms,
            string $tax,
            string $rel,
            string $idx
        ): array {
            return [
                "CREATE TABLE {$terms} ("
                    . ' term_id INTEGER PRIMARY KEY AUTOINCREMENT,'
                    . " name TEXT NOT NULL DEFAULT '',"
                    . " slug TEXT NOT NULL DEFAULT '',"
                    . ' term_group INTEGER NOT NULL DEFAULT 0'
                    . ')',
                "CREATE INDEX {$idx}terms_slug ON {$terms} (slug)",
                "CREATE INDEX {$idx}terms_name ON {$terms} (name)",

                "CREATE TABLE {$tax} ("
                    . ' term_taxonomy_id INTEGER PRIMARY KEY AUTOINCREMENT,'
                    . ' term_id INTEGER NOT NULL DEFAULT 0,'
                    . " taxonomy TEXT NOT NULL DEFAULT '',"
                    . " description TEXT NOT NULL DEFAULT '',"
                    . ' parent INTEGER NOT NULL DEFAULT 0,'
                    . ' count INTEGER NOT NULL DEFAULT 0'
                    . ')',
                "CREATE UNIQUE INDEX {$idx}term_taxonomy_term_id_taxonomy ON {$tax} (term_id, taxonomy)",
                "CREATE INDEX {$idx}term_taxonomy_taxonomy ON {$tax} (taxonomy)",

                "CREATE TABLE {$rel} ("
                    . ' object_id INTEGER NOT NULL DEFAULT 0,'
                    . ' term_taxonomy_id INTEGER NOT NULL DEFAULT 0,'
                    . ' term_order INTEGER NOT NULL DEFAULT 0,'
                    . ' PRIMARY KEY (object_id, term_taxonomy_id)'
                    . ')',
                "CREATE INDEX {$idx}term_relationships_tt ON {$rel} (term_taxonomy_id)",
            ];
        }
    }
}

return new AP_Migration_0003_Core_Terms_Taxonomies();
