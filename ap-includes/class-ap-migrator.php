<?php

/**
 * Versioned database schema migrator for AgoraPress.
 *
 * Discovers migrations under `ap-includes/schema/migrations/`, tracks applied
 * versions in `{prefix}schema_migrations`, and applies pending upgrades in
 * ascending version order. Used by the installer and future update path.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

require_once __DIR__ . '/class-ap-migration.php';
require_once __DIR__ . '/class-ap-migrator-exception.php';

/**
 * Applies versioned schema migrations against an {@see AP_DB} connection.
 *
 * The registry table (`schema_migrations`) is created automatically and is not
 * itself a numbered migration. Core product tables ship as numbered files under
 * the migrations directory (starting with 0001_core_options_users.php).
 */
class AP_Migrator
{
    /**
     * Unprefixed base name of the migration registry table.
     */
    public const REGISTRY_BASE = 'schema_migrations';

    private AP_DB $db;

    private string $migrationsPath;

    /**
     * @param AP_DB  $db              Live database connection.
     * @param string $migrationsPath  Directory containing `NNNN_slug.php` files.
     */
    public function __construct(AP_DB $db, string $migrationsPath = '')
    {
        $this->db = $db;
        if ($migrationsPath === '') {
            $migrationsPath = self::defaultMigrationsPath();
        }
        $this->migrationsPath = rtrim($migrationsPath, "/\\");
    }

    /**
     * Default directory for shipped core migrations.
     */
    public static function defaultMigrationsPath(): string
    {
        return __DIR__ . '/schema/migrations';
    }

    /**
     * Target schema version expected by the running code ({@see AP_DB_VERSION}).
     */
    public static function codeTargetVersion(): int
    {
        if (!defined('AP_DB_VERSION')) {
            return 0;
        }

        return max(0, (int) AP_DB_VERSION);
    }

    /**
     * Absolute path to the migrations directory used by this instance.
     */
    public function getMigrationsPath(): string
    {
        return $this->migrationsPath;
    }

    /**
     * Fully prefixed registry table name for the active connection prefix.
     */
    public function registryTable(): string
    {
        return $this->db->table(self::REGISTRY_BASE);
    }

    /**
     * Ensure the migration registry table exists (idempotent).
     *
     * @throws AP_Migrator_Exception When DDL fails.
     */
    public function ensureRegistry(): void
    {
        $table = $this->registryTable();
        $quoted = $this->db->quoteIdentifier($table);
        $sql = $this->registryCreateSql($quoted);

        $stmt = $this->db->query($sql);
        if ($stmt === false) {
            throw new AP_Migrator_Exception(
                'Could not create schema_migrations registry: '
                . ($this->db->lastError() ?? 'unknown error')
            );
        }
    }

    /**
     * Highest applied migration version, or 0 when none / empty database.
     */
    public function getCurrentVersion(): int
    {
        $this->ensureRegistry();
        $table = $this->db->quoteIdentifier($this->registryTable());
        $value = $this->db->getVar('SELECT MAX(version) FROM ' . $table);

        if ($value === null || $value === '') {
            return 0;
        }

        return (int) $value;
    }

    /**
     * List of applied version numbers, ascending.
     *
     * @return list<int>
     */
    public function getAppliedVersions(): array
    {
        $this->ensureRegistry();
        $table = $this->db->quoteIdentifier($this->registryTable());
        $rows = $this->db->getCol('SELECT version FROM ' . $table . ' ORDER BY version ASC');

        $versions = [];
        foreach ($rows as $row) {
            $versions[] = (int) $row;
        }

        return $versions;
    }

    /**
     * Discover migration objects from the migrations directory.
     *
     * Files must match `NNNN_*.php` (leading digits = version). Each file must
     * return an {@see AP_Migration} instance whose {@see AP_Migration::version()}
     * matches the filename prefix.
     *
     * @return list<AP_Migration> Sorted ascending by version.
     *
     * @throws AP_Migrator_Exception On invalid files or duplicate versions.
     */
    public function discover(): array
    {
        if (!is_dir($this->migrationsPath)) {
            return [];
        }

        $files = glob($this->migrationsPath . '/*.php');
        if ($files === false || $files === []) {
            return [];
        }

        sort($files, SORT_STRING);

        /** @var array<int, AP_Migration> $byVersion */
        $byVersion = [];

        foreach ($files as $file) {
            $base = basename($file);
            if (preg_match('/^(\d+)_.+\.php$/', $base, $m) !== 1) {
                // Ignore non-migration PHP helpers (e.g. README stubs).
                continue;
            }

            $fileVersion = (int) $m[1];
            if ($fileVersion < 1) {
                throw new AP_Migrator_Exception(
                    "Migration version must be >= 1 in filename: {$base}"
                );
            }

            /** @var mixed $migration */
            $migration = require $file;

            if (!$migration instanceof AP_Migration) {
                throw new AP_Migrator_Exception(
                    "Migration file must return an AP_Migration instance: {$base}"
                );
            }

            $declared = $migration->version();
            if ($declared !== $fileVersion) {
                throw new AP_Migrator_Exception(
                    "Migration version mismatch in {$base}: filename={$fileVersion},"
                    . " object={$declared}"
                );
            }

            if (isset($byVersion[$declared])) {
                throw new AP_Migrator_Exception(
                    "Duplicate migration version {$declared} (file {$base})"
                );
            }

            $byVersion[$declared] = $migration;
        }

        ksort($byVersion, SORT_NUMERIC);

        return array_values($byVersion);
    }

    /**
     * Highest version available on disk (0 when none).
     */
    public function getAvailableTargetVersion(): int
    {
        $all = $this->discover();
        if ($all === []) {
            return 0;
        }

        return $all[array_key_last($all)]->version();
    }

    /**
     * Effective target: max of code {@see AP_DB_VERSION} and available migrations.
     *
     * Keeps code and on-disk migrations aligned; installer uses this ceiling.
     */
    public function getTargetVersion(): int
    {
        return max(self::codeTargetVersion(), $this->getAvailableTargetVersion());
    }

    /**
     * Migrations not yet recorded in the registry, up to an optional ceiling.
     *
     * @return list<AP_Migration>
     */
    public function pending(?int $toVersion = null): array
    {
        $ceiling = $toVersion ?? $this->getTargetVersion();
        $applied = array_fill_keys($this->getAppliedVersions(), true);
        $pending = [];

        foreach ($this->discover() as $migration) {
            $v = $migration->version();
            if ($v > $ceiling) {
                continue;
            }
            if (isset($applied[$v])) {
                continue;
            }
            $pending[] = $migration;
        }

        return $pending;
    }

    /**
     * Whether any pending migrations exist for the effective target.
     */
    public function needsMigration(): bool
    {
        return $this->pending() !== [];
    }

    /**
     * Apply all pending migrations up to `$toVersion` (default: target).
     *
     * Each migration runs inside a transaction when the driver allows it.
     * On failure the version is not recorded and the exception is rethrown
     * (wrapped as {@see AP_Migrator_Exception} when not already one).
     *
     * @param int|null $toVersion Optional inclusive ceiling.
     *
     * @return list<array{version: int, description: string}> Applied this run.
     *
     * @throws AP_Migrator_Exception
     */
    public function migrate(?int $toVersion = null): array
    {
        $this->ensureRegistry();
        $pending = $this->pending($toVersion);
        $applied = [];

        foreach ($pending as $migration) {
            $this->applyOne($migration);
            $applied[] = [
                'version' => $migration->version(),
                'description' => $migration->description(),
            ];
        }

        return $applied;
    }

    /**
     * Apply a single migration and record it in the registry.
     *
     * @throws AP_Migrator_Exception
     */
    private function applyOne(AP_Migration $migration): void
    {
        $version = $migration->version();
        $description = $migration->description();

        $startedTx = false;
        if (!$this->db->inTransaction()) {
            try {
                $startedTx = $this->db->beginTransaction();
            } catch (Throwable) {
                $startedTx = false;
            }
        }

        try {
            $migration->up($this->db);
            $this->recordApplied($version, $description);

            if ($startedTx && $this->db->inTransaction()) {
                $this->db->commit();
            }
        } catch (Throwable $e) {
            if ($startedTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }

            if ($e instanceof AP_Migrator_Exception) {
                throw $e;
            }

            throw new AP_Migrator_Exception(
                sprintf(
                    'Migration %d failed (%s): %s',
                    $version,
                    $description,
                    $e->getMessage()
                ),
                (int) $e->getCode(),
                $e
            );
        }
    }

    /**
     * Insert a row into the registry for a successfully applied migration.
     *
     * @throws AP_Migrator_Exception
     */
    private function recordApplied(int $version, string $description): void
    {
        $table = $this->db->quoteIdentifier($this->registryTable());
        $appliedAt = gmdate('Y-m-d H:i:s');

        // Direct INSERT with bound params (registry not in insert() base list issues —
        // insert() accepts any safe unprefixed name via table()).
        $result = $this->db->insert(self::REGISTRY_BASE, [
            'version' => $version,
            'description' => $description,
            'applied_at' => $appliedAt,
        ]);

        if ($result === false) {
            // Fallback for drivers where insert quoting differs — raw prepared SQL.
            $sql = sprintf(
                'INSERT INTO %s (version, description, applied_at) VALUES (?, ?, ?)',
                $table
            );
            $stmt = $this->db->query($sql, [$version, $description, $appliedAt]);
            if ($stmt === false) {
                throw new AP_Migrator_Exception(
                    "Failed to record migration version {$version}: "
                    . ($this->db->lastError() ?? 'unknown error')
                );
            }
        }
    }

    /**
     * CREATE TABLE SQL for the registry, driver-specific.
     */
    private function registryCreateSql(string $quotedTable): string
    {
        return match ($this->db->getDriver()) {
            'mysql' => "CREATE TABLE IF NOT EXISTS {$quotedTable} ("
                . ' `version` INT UNSIGNED NOT NULL,'
                . " `description` VARCHAR(255) NOT NULL DEFAULT '',"
                . ' `applied_at` DATETIME NOT NULL,'
                . ' PRIMARY KEY (`version`)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'pgsql' => "CREATE TABLE IF NOT EXISTS {$quotedTable} ("
                . ' version INTEGER NOT NULL PRIMARY KEY,'
                . " description VARCHAR(255) NOT NULL DEFAULT '',"
                . ' applied_at TIMESTAMP NOT NULL'
                . ')',
            default => "CREATE TABLE IF NOT EXISTS {$quotedTable} ("
                . ' version INTEGER NOT NULL PRIMARY KEY,'
                . " description TEXT NOT NULL DEFAULT '',"
                . ' applied_at TEXT NOT NULL'
                . ')',
        };
    }
}

/**
 * Build a migrator for the global database connection.
 *
 * @param AP_DB|null $db Optional explicit connection (tests); defaults to ap_db().
 */
function ap_migrator(?AP_DB $db = null): AP_Migrator
{
    if ($db === null) {
        if (!function_exists('ap_db')) {
            throw new AP_Migrator_Exception('ap_db() is not available.');
        }
        $db = ap_db();
    }

    return new AP_Migrator($db);
}
