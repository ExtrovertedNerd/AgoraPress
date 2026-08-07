<?php

/**
 * Migration 0012 — topic type enum (SPEC phpBB-parity icons).
 *
 * Canonical values: standard | sticky | announcement | rules
 *
 * Backfills legacy labels stored by earlier installs:
 * - normal  → standard
 * - announce → announcement
 * - global  → announcement (phpBB global announce)
 * - empty / unknown → standard
 *
 * sticky is unchanged. rules is new (no legacy source).
 * Column default becomes standard where the driver supports it.
 *
 * Multi-driver (MySQL/MariaDB, SQLite, PostgreSQL).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

// Guard: migrator may require this file more than once per process.
if (!class_exists('AP_Migration_0012_Topic_Type_Enum', false)) {
    /**
     * Topic type enum rename/backfill to SPEC values.
     */
    final class AP_Migration_0012_Topic_Type_Enum implements AP_Migration
    {
        public function version(): int
        {
            return 12;
        }

        public function description(): string
        {
            return 'Topic type enum: standard | sticky | announcement | rules (backfill)';
        }

        public function up(AP_DB $db): void
        {
            $topics = $db->table('topics');
            $qTopics = $db->quoteIdentifier($topics);
            $col = $db->quoteIdentifier('topic_type');

            // 1) Remap known legacy labels.
            $maps = [
                'normal' => 'standard',
                'announce' => 'announcement',
                'global' => 'announcement',
            ];
            foreach ($maps as $from => $to) {
                $ok = $db->query(
                    "UPDATE {$qTopics} SET {$col} = ? WHERE {$col} = ?",
                    [$to, $from]
                );
                if ($ok === false) {
                    throw new RuntimeException(
                        'Failed to backfill topic_type ' . $from . ' → ' . $to . ': '
                        . ($db->lastError() ?? 'unknown error')
                    );
                }
            }

            // 2) Empty string → standard.
            $ok = $db->query(
                "UPDATE {$qTopics} SET {$col} = ? WHERE {$col} = ? OR {$col} IS NULL",
                ['standard', '']
            );
            if ($ok === false) {
                throw new RuntimeException(
                    'Failed to backfill empty topic_type: '
                    . ($db->lastError() ?? 'unknown error')
                );
            }

            // 3) Any remaining non-canonical value → standard (safe default).
            $ok = $db->query(
                "UPDATE {$qTopics} SET {$col} = ?"
                . " WHERE {$col} NOT IN (?, ?, ?, ?)",
                ['standard', 'standard', 'sticky', 'announcement', 'rules']
            );
            if ($ok === false) {
                throw new RuntimeException(
                    'Failed to normalize unknown topic_type values: '
                    . ($db->lastError() ?? 'unknown error')
                );
            }

            // 4) Column default → standard (best-effort; SQLite often cannot alter DEFAULT).
            $driver = $db->getDriver();
            $defaultSql = match ($driver) {
                'mysql' => "ALTER TABLE {$qTopics} MODIFY {$col} VARCHAR(20) NOT NULL DEFAULT 'standard'",
                'pgsql' => "ALTER TABLE {$qTopics} ALTER COLUMN topic_type SET DEFAULT 'standard'",
                default => null, // SQLite: application always writes normalized type
            };

            if ($defaultSql !== null) {
                $stmt = $db->query($defaultSql);
                if ($stmt === false) {
                    throw new RuntimeException(
                        'Failed to set topic_type default: '
                        . ($db->lastError() ?? 'unknown error')
                    );
                }
            }
        }
    }
}

return new AP_Migration_0012_Topic_Type_Enum();
