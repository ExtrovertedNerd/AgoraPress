<?php

/**
 * Contract for a single versioned database schema migration.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * One atomic schema change applied by {@see AP_Migrator}.
 *
 * Implementations live under `ap-includes/schema/migrations/` as PHP files that
 * return an object implementing this interface. File names should start with a
 * zero-padded version (`0001_slug.php`) matching {@see version()}.
 */
interface AP_Migration
{
    /**
     * Positive integer schema version this migration advances the database to.
     *
     * Versions must be unique across the migrations directory and applied in
     * ascending order. Version 0 is reserved for an empty (unmigrated) database.
     */
    public function version(): int;

    /**
     * Short human-readable summary for logs and installer output.
     */
    public function description(): string;

    /**
     * Apply this migration against the given database connection.
     *
     * Use {@see AP_DB::table()} / public table properties so the site table
     * prefix is honored. Prefer prepared statements for any value-bearing SQL.
     * Driver-specific DDL may branch on {@see AP_DB::getDriver()}.
     *
     * @throws Throwable On failure; the migrator will not record the version.
     */
    public function up(AP_DB $db): void;
}
