<?php

/**
 * AgoraPress version constants.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * Current core version (SemVer). Pre-release suffix while building MVP.
 */
define('AP_VERSION', '0.1.0-dev');

/**
 * Database schema version expected by this codebase.
 *
 * Integer (stored as string for historical/define symmetry). Bumped when a new
 * migration ships under ap-includes/schema/migrations/. Version 1 ships core
 * options / users / usermeta tables. {@see AP_Migrator} tracks applied versions
 * in schema_migrations. Installer / update path apply pending migrations until
 * the database matches this target.
 */
define('AP_DB_VERSION', '1');
