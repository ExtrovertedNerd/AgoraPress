<?php

/**
 * AgoraPress configuration loader.
 *
 * Loads `ap-config.php`, validates required constants, applies safe defaults,
 * normalizes the table prefix, and defines content path constants used by core.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * Whether ap_load_config() has completed successfully in this request.
 */
function ap_config_is_loaded(): bool
{
    return defined('AP_CONFIG_LOADED') && AP_CONFIG_LOADED === true;
}

/**
 * Required constants that must be defined by ap-config.php.
 *
 * @return list<string>
 */
function ap_required_config_constants(): array
{
    return [
        'AP_DB_DRIVER',
        'AP_DB_NAME',
        'AP_DB_USER',
        'AP_DB_PASSWORD',
        'AP_DB_HOST',
        'AP_AUTH_KEY',
        'AP_SECURE_AUTH_KEY',
        'AP_LOGGED_IN_KEY',
        'AP_NONCE_KEY',
        'AP_AUTH_SALT',
        'AP_SECURE_AUTH_SALT',
        'AP_LOGGED_IN_SALT',
        'AP_NONCE_SALT',
    ];
}

/**
 * Optional constants and their default values when not set in ap-config.php.
 *
 * @return array<string, mixed>
 */
function ap_default_config_constants(): array
{
    return [
        'AP_DB_CHARSET' => 'utf8mb4',
        'AP_DB_COLLATE' => 'utf8mb4_unicode_ci',
        'AP_DEBUG' => false,
        'AP_DEBUG_DISPLAY' => false,
        'AP_DEBUG_LOG' => false,
        // Privacy: never enable telemetry unless the site config opts in.
        'AP_TELEMETRY' => false,
    ];
}

/**
 * Supported database driver identifiers (SPEC §1).
 *
 * @return list<string>
 */
function ap_supported_db_drivers(): array
{
    return ['mysql', 'sqlite', 'pgsql'];
}

/**
 * Default table prefix (SPEC §1).
 */
function ap_default_table_prefix(): string
{
    return 'ap_';
}

/**
 * Normalize a raw table prefix to a safe SQL identifier fragment.
 *
 * Allows only letters, digits, and underscores. Empty or fully invalid input
 * falls back to the SPEC default `ap_`. Does not force a trailing underscore
 * (sites may choose otherwise) but preserves a trailing `_` when present.
 */
function ap_normalize_table_prefix(string $prefix): string
{
    $prefix = trim($prefix);
    // Strip anything outside [A-Za-z0-9_].
    $clean = preg_replace('/[^A-Za-z0-9_]/', '', $prefix);
    if (!is_string($clean) || $clean === '') {
        return ap_default_table_prefix();
    }

    // Must start with a letter or underscore (not a digit) for broad SQL safety.
    if (preg_match('/^[0-9]/', $clean) === 1) {
        $clean = ap_default_table_prefix() . $clean;
    }

    return $clean;
}

/**
 * Unprefixed base names for core schema tables (SPEC §4).
 *
 * Used by the DB layer, installer, and migrations to build fully qualified
 * names via the site table prefix. Forum-only tables live in
 * {@see ap_forum_base_tables()}.
 *
 * @return list<string>
 */
function ap_core_base_tables(): array
{
    return [
        // Migration registry (AP_Migrator); not content, but part of core schema.
        'schema_migrations',
        'options',
        'users',
        'usermeta',
        'posts',
        'postmeta',
        'terms',
        'term_taxonomy',
        'term_relationships',
        'comments',
        'commentmeta',
    ];
}

/**
 * Unprefixed base names for dedicated forum tables (SPEC §4).
 *
 * @return list<string>
 */
function ap_forum_base_tables(): array
{
    return [
        'forums',
        'topics',
        'forum_posts',
        'forum_attachments',
        'groups',
        'group_members',
        'forum_permissions',
        'messages',
        'ranks',
        'reports',
        'warnings',
        'bans',
        'online',
        'topic_track',
        'forum_track',
    ];
}

/**
 * All known schema base table names (core + forums).
 *
 * @return list<string>
 */
function ap_all_base_tables(): array
{
    return array_values(array_unique(array_merge(
        ap_core_base_tables(),
        ap_forum_base_tables()
    )));
}

/**
 * Fully prefixed table name for a base fragment using the active site prefix.
 *
 * Example: ap_prefixed_table('options') → `ap_options` (or `myblog_options`).
 */
function ap_prefixed_table(string $base): string
{
    return ap_get_table_prefix() . $base;
}

/**
 * Map of base name => fully prefixed name for every known schema table.
 *
 * @return array<string, string>
 */
function ap_prefixed_tables(): array
{
    $prefix = ap_get_table_prefix();
    $map = [];
    foreach (ap_all_base_tables() as $base) {
        $map[$base] = $prefix . $base;
    }

    return $map;
}

/**
 * Site table prefix after config load (always defined once loaded).
 */
function ap_get_table_prefix(): string
{
    if (defined('AP_TABLE_PREFIX')) {
        return (string) AP_TABLE_PREFIX;
    }

    global $table_prefix;
    if (isset($table_prefix) && is_string($table_prefix) && $table_prefix !== '') {
        return ap_normalize_table_prefix($table_prefix);
    }

    return ap_default_table_prefix();
}

/**
 * List required constants that are not yet defined.
 *
 * @return list<string>
 */
function ap_missing_config_constants(): array
{
    $missing = [];
    foreach (ap_required_config_constants() as $name) {
        if (!defined($name)) {
            $missing[] = $name;
        }
    }

    return $missing;
}

/**
 * Apply optional constant defaults when ap-config.php omitted them.
 */
function ap_apply_config_defaults(): void
{
    foreach (ap_default_config_constants() as $name => $value) {
        if (!defined($name)) {
            define($name, $value);
        }
    }
}

/**
 * Define content-directory path constants when not set in ap-config.php.
 *
 * Paths use no trailing slash (join with '/' at call sites). AP_ABSPATH always
 * ends with '/'.
 */
function ap_define_path_constants(): void
{
    if (!defined('AP_ABSPATH')) {
        return;
    }

    if (!defined('AP_CONTENT_DIR')) {
        define('AP_CONTENT_DIR', AP_ABSPATH . 'ap-content');
    }

    if (!defined('AP_PLUGIN_DIR')) {
        define('AP_PLUGIN_DIR', AP_CONTENT_DIR . '/plugins');
    }

    if (!defined('AP_MU_PLUGIN_DIR')) {
        define('AP_MU_PLUGIN_DIR', AP_CONTENT_DIR . '/mu-plugins');
    }

    if (!defined('AP_THEME_DIR')) {
        define('AP_THEME_DIR', AP_CONTENT_DIR . '/themes');
    }

    if (!defined('AP_UPLOADS_DIR')) {
        define('AP_UPLOADS_DIR', AP_CONTENT_DIR . '/uploads');
    }

    if (!defined('AP_LANG_DIR')) {
        define('AP_LANG_DIR', AP_CONTENT_DIR . '/languages');
    }
}

/**
 * Normalize $table_prefix and expose AP_TABLE_PREFIX.
 *
 * Prefer `$table_prefix` from ap-config.php. When unset/empty, honor the
 * AP_TABLE_PREFIX environment variable (Docker Compose) before falling back
 * to the SPEC default `ap_`.
 */
function ap_finalize_table_prefix(): void
{
    global $table_prefix;

    if (!isset($table_prefix) || !is_string($table_prefix) || $table_prefix === '') {
        $fromEnv = getenv('AP_TABLE_PREFIX');
        if (is_string($fromEnv) && $fromEnv !== '') {
            $table_prefix = $fromEnv;
        } else {
            $table_prefix = ap_default_table_prefix();
        }
    }

    $table_prefix = ap_normalize_table_prefix($table_prefix);
    $GLOBALS['table_prefix'] = $table_prefix;

    if (!defined('AP_TABLE_PREFIX')) {
        define('AP_TABLE_PREFIX', $table_prefix);
    }
}

/**
 * Apply PHP error-reporting behavior from AP_DEBUG* flags.
 *
 * Production default: hide display errors. Debug mode enables full reporting;
 * display and log are controlled independently.
 */
function ap_apply_debug_settings(): void
{
    $debug = defined('AP_DEBUG') && AP_DEBUG;
    $display = defined('AP_DEBUG_DISPLAY') && AP_DEBUG_DISPLAY;
    $log = defined('AP_DEBUG_LOG') && AP_DEBUG_LOG;

    if ($debug) {
        error_reporting(E_ALL);
    }

    // Prefer ini_set when available; ignore failures (restricted hosts).
    if (function_exists('ini_set')) {
        if ($debug && $display) {
            @ini_set('display_errors', '1');
        } else {
            @ini_set('display_errors', '0');
        }

        if ($debug && $log) {
            @ini_set('log_errors', '1');
            if (defined('AP_CONTENT_DIR')) {
                $logFile = AP_CONTENT_DIR . '/debug.log';
                @ini_set('error_log', $logFile);
            }
        }
    }
}

/**
 * Validate AP_DB_DRIVER against supported values.
 *
 * @return string|null Normalized driver, or null if invalid / missing.
 */
function ap_normalized_db_driver(): ?string
{
    if (!defined('AP_DB_DRIVER')) {
        return null;
    }

    $driver = strtolower(trim((string) AP_DB_DRIVER));
    if (!in_array($driver, ap_supported_db_drivers(), true)) {
        return null;
    }

    return $driver;
}

/**
 * HTML shown when ap-config.php is present but incomplete or invalid.
 *
 * @param list<string> $missing Missing constant names.
 * @param string|null  $driverError Optional driver validation message.
 */
function ap_get_invalid_config_html(array $missing, ?string $driverError = null): string
{
    $version = defined('AP_VERSION') ? (string) AP_VERSION : '';
    $versionNote = $version !== ''
        ? ' <span class="meta">(' . htmlspecialchars($version, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ')</span>'
        : '';

    $details = '';
    if ($missing !== []) {
        $items = '';
        foreach ($missing as $name) {
            $safe = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $items .= "<li><code>{$safe}</code></li>\n";
        }
        $details .= "<p>Missing required constants:</p>\n<ul>\n{$items}</ul>\n";
    }
    if ($driverError !== null && $driverError !== '') {
        $safeMsg = htmlspecialchars($driverError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $details .= "<p>{$safeMsg}</p>\n";
    }

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>AgoraPress — Configuration Error</title>
    <style>
        :root { color-scheme: light dark; }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, Ubuntu, sans-serif;
            line-height: 1.5;
            background: #f4f5f7;
            color: #1a1a1a;
        }
        @media (prefers-color-scheme: dark) {
            body { background: #12141a; color: #e8eaed; }
            main { background: #1c1f28; border-color: #2a2f3a; }
            code { background: #2a2f3a; }
        }
        main {
            max-width: 36rem;
            margin: 1.5rem;
            padding: 1.75rem 1.5rem;
            background: #fff;
            border: 1px solid #dde1e6;
            border-radius: 8px;
        }
        h1 { margin: 0 0 0.75rem; font-size: 1.35rem; font-weight: 650; }
        p { margin: 0 0 0.75rem; }
        ul { margin: 0 0 0.75rem; padding-left: 1.25rem; }
        code {
            font-family: ui-monospace, Menlo, monospace;
            font-size: 0.9em;
            padding: 0.1em 0.35em;
            border-radius: 4px;
            background: #eef0f3;
        }
        .meta { font-weight: 400; opacity: 0.65; font-size: 0.85em; }
    </style>
</head>
<body>
    <main>
        <h1>Configuration error{$versionNote}</h1>
        <p>
            <code>ap-config.php</code> was found but is incomplete or invalid.
            Compare with <code>ap-config-sample.php</code> and fill in every
            required setting.
        </p>
        {$details}
        <p class="meta">AgoraPress will not continue until configuration is valid.</p>
    </main>
</body>
</html>

HTML;
}

/**
 * Load and process site configuration.
 *
 * @param string|null $configPath Explicit path (tests); defaults to ap_config_path().
 * @param bool        $exitOnError When true (web bootstrap), emit HTML and exit on
 *                                 validation failure. When false, return false instead.
 *
 * @return bool True when config is loaded and valid.
 */
function ap_load_config(?string $configPath = null, bool $exitOnError = true): bool
{
    if (ap_config_is_loaded()) {
        return true;
    }

    $path = $configPath ?? (function_exists('ap_config_path')
        ? ap_config_path()
        : (defined('AP_ABSPATH') ? AP_ABSPATH . 'ap-config.php' : ''));

    if ($path === '' || !is_readable($path)) {
        if ($exitOnError && function_exists('ap_graceful_exit') && function_exists('ap_get_not_installed_html')) {
            ap_graceful_exit(503, ap_get_not_installed_html());
        }

        return false;
    }

    // Bring config symbols into this function's local scope (constants are
    // always global; $table_prefix from the file is local until promoted).
    require_once $path;

    // Promote $table_prefix from the included file into the true global scope.
    // Leave unset when the file omitted it so ap_finalize_table_prefix() can
    // honor AP_TABLE_PREFIX from the environment (Docker) before defaulting.
    if (isset($table_prefix) && is_string($table_prefix) && $table_prefix !== '') {
        $GLOBALS['table_prefix'] = $table_prefix;
    }

    $missing = ap_missing_config_constants();
    $driver = null;
    $driverError = null;

    if ($missing === []) {
        $driver = ap_normalized_db_driver();
        if ($driver === null) {
            $raw = defined('AP_DB_DRIVER') ? (string) AP_DB_DRIVER : '';
            $allowed = implode(', ', ap_supported_db_drivers());
            $driverError = 'Unsupported AP_DB_DRIVER value'
                . ($raw !== '' ? ' "' . $raw . '"' : '')
                . ". Supported: {$allowed}.";
        }
    }

    if ($missing !== [] || $driverError !== null) {
        if ($exitOnError && function_exists('ap_graceful_exit')) {
            ap_graceful_exit(503, ap_get_invalid_config_html($missing, $driverError));
        }

        return false;
    }

    // Re-define driver in normalized form only if different and not already final.
    // Constants cannot be redefined; callers should use lowercase in config.
    // We accept mixed case via ap_normalized_db_driver() at connection time.

    ap_apply_config_defaults();
    ap_finalize_table_prefix();
    ap_define_path_constants();
    ap_apply_debug_settings();

    if (!defined('AP_CONFIG_LOADED')) {
        define('AP_CONFIG_LOADED', true);
    }

    return true;
}
