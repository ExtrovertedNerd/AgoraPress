<?php

/**
 * AgoraPress bootstrap: install detection, config load, core includes.
 *
 * Loaded by index.php (and later by admin / CLI entry points). Fails
 * gracefully with a friendly HTML response when the site is not installed.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

if (!defined('AP_ABSPATH')) {
    define('AP_ABSPATH', dirname(__DIR__) . '/');
}

require_once AP_ABSPATH . 'ap-includes/version.php';
require_once AP_ABSPATH . 'ap-includes/load-config.php';

/**
 * Absolute path to the site configuration file.
 */
function ap_config_path(): string
{
    return AP_ABSPATH . 'ap-config.php';
}

/**
 * Whether AgoraPress appears installed (readable ap-config.php present).
 *
 * Presence of the generated config file is the install signal. The web and CLI
 * installers refuse to run when this returns true (see AP_Installer::configExists).
 *
 * @param string|null $configPath Optional explicit path (for tests); defaults
 *                                to ap_config_path().
 */
function ap_is_installed(?string $configPath = null): bool
{
    $path = $configPath ?? ap_config_path();

    return is_readable($path);
}

/**
 * Whether the running PHP version meets AgoraPress requirements (8.2+).
 */
function ap_php_version_is_supported(): bool
{
    return PHP_VERSION_ID >= 80200;
}

/**
 * Relative URL path to the web installer (from site root).
 */
function ap_install_url_path(): string
{
    return 'install/';
}

/**
 * HTML document shown when the site is not installed.
 *
 * Pure string builder — no headers or output. Safe for unit tests.
 */
function ap_get_not_installed_html(): string
{
    $version = defined('AP_VERSION') ? (string) AP_VERSION : '';
    $versionNote = $version !== ''
        ? ' <span class="meta">(' . htmlspecialchars($version, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ')</span>'
        : '';
    $installHref = htmlspecialchars(ap_install_url_path(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>AgoraPress — Not Installed</title>
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
            a.button { background: #62a0ea; color: #0b1a2b; }
        }
        main {
            max-width: 36rem;
            margin: 1.5rem;
            padding: 1.75rem 1.5rem;
            background: #fff;
            border: 1px solid #dde1e6;
            border-radius: 8px;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
        }
        h1 { margin: 0 0 0.75rem; font-size: 1.35rem; font-weight: 650; }
        p { margin: 0 0 0.75rem; }
        p:last-child { margin-bottom: 0; }
        code {
            font-family: ui-monospace, "Cascadia Code", "Source Code Pro", Menlo, monospace;
            font-size: 0.9em;
            padding: 0.1em 0.35em;
            border-radius: 4px;
            background: #eef0f3;
        }
        .meta { font-weight: 400; opacity: 0.65; font-size: 0.85em; }
        a.button {
            display: inline-block;
            margin: 0.25rem 0 0.75rem;
            padding: 0.55rem 1rem;
            border-radius: 6px;
            background: #1a5fb4;
            color: #fff;
            font-weight: 600;
            text-decoration: none;
        }
        a.button:focus-visible { outline: 2px solid #1a5fb4; outline-offset: 2px; }
    </style>
</head>
<body>
    <main>
        <h1>AgoraPress is not installed{$versionNote}</h1>
        <p>
            No configuration file was found. AgoraPress looks for
            <code>ap-config.php</code> in the site root.
        </p>
        <p>
            <a class="button" href="{$installHref}">Run the web installer</a>
        </p>
        <p>
            Or copy <code>ap-config-sample.php</code> to <code>ap-config.php</code>
            and configure manually (CLI installer and Docker Compose are also supported).
            See the project README for details.
        </p>
        <p class="meta">Web installer: requirements → database → site &amp; admin → tables + config.</p>
    </main>
</body>
</html>

HTML;
}

/**
 * HTML document shown when PHP is below the minimum supported version.
 */
function ap_get_php_unsupported_html(string $required, string $actual): string
{
    $req = htmlspecialchars($required, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $act = htmlspecialchars($actual, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>AgoraPress — PHP Version Unsupported</title>
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
        p:last-child { margin-bottom: 0; }
        code {
            font-family: ui-monospace, Menlo, monospace;
            font-size: 0.9em;
            padding: 0.1em 0.35em;
            border-radius: 4px;
            background: #eef0f3;
        }
    </style>
</head>
<body>
    <main>
        <h1>PHP version not supported</h1>
        <p>
            AgoraPress requires <strong>PHP {$req} or higher</strong>.
            This server is running <code>{$act}</code>.
        </p>
        <p>Please upgrade PHP, then reload this page.</p>
    </main>
</body>
</html>

HTML;
}

/**
 * Emit a graceful failure response and stop execution.
 *
 * @param int    $status  HTTP status code (503 = not ready).
 * @param string $html    Full HTML document body.
 *
 * @return never
 */
function ap_graceful_exit(int $status, string $html): never
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        header('X-Robots-Tag: noindex, nofollow');
    }

    echo $html;
    exit(0);
}

/**
 * Bootstrap AgoraPress for a web (or CLI) request.
 *
 * When not installed (or PHP is too old), prints a friendly page and exits
 * without a fatal error. When installed, loads config and core includes.
 */
function ap_bootstrap(): void
{
    if (!ap_php_version_is_supported()) {
        ap_graceful_exit(
            503,
            ap_get_php_unsupported_html('8.2', PHP_VERSION)
        );
    }

    if (!ap_is_installed()) {
        ap_graceful_exit(503, ap_get_not_installed_html());
    }

    // Site has a config file — validate, apply defaults/paths, then core includes.
    // ap_load_config() exits with a friendly 503 page if the file is incomplete.
    ap_load_config(null, true);

    require_once AP_ABSPATH . 'ap-includes/hooks.php';
    // Database layer: class + ap_db() helper. Connection is lazy (first ap_db()).
    require_once AP_ABSPATH . 'ap-includes/class-ap-db.php';
    // Versioned schema migrations (runner only — does not auto-apply on bootstrap).
    require_once AP_ABSPATH . 'ap-includes/class-ap-migrator.php';
    // Users + Argon2id password authentication.
    require_once AP_ABSPATH . 'ap-includes/class-ap-user.php';
    // Signed auth cookies + session tokens (login / logout / current user).
    require_once AP_ABSPATH . 'ap-includes/class-ap-session.php';
    // Outbound mail (registration / password reset) with test outbox.
    require_once AP_ABSPATH . 'ap-includes/class-ap-mail.php';
    // Public registration, email verification, password reset.
    require_once AP_ABSPATH . 'ap-includes/class-ap-registration.php';
    // Roles & capabilities (role map + userCan / currentUserCan).
    require_once AP_ABSPATH . 'ap-includes/class-ap-roles.php';
    // Posts: statuses, types, CRUD, hierarchical pages.
    require_once AP_ABSPATH . 'ap-includes/class-ap-post.php';
    // Taxonomies: categories, tags, custom taxonomies, term relationships.
    require_once AP_ABSPATH . 'ap-includes/class-ap-taxonomy.php';
    // Comments: nested threads, moderation, pluggable spam hooks.
    require_once AP_ABSPATH . 'ap-includes/class-ap-comment.php';
    // Media library: secure uploads + attachment posts.
    require_once AP_ABSPATH . 'ap-includes/class-ap-media.php';
    // Avatars: local upload + Gravatar fallback.
    require_once AP_ABSPATH . 'ap-includes/class-ap-avatar.php';
    // Content query (WP_Query-inspired main loop + secondary queries).
    require_once AP_ABSPATH . 'ap-includes/class-ap-query.php';
    // Permalinks + rewrite rules (pretty URLs → query vars, link builders).
    require_once AP_ABSPATH . 'ap-includes/class-ap-rewrite.php';
    // Theme loader + classic template hierarchy.
    require_once AP_ABSPATH . 'ap-includes/class-ap-theme.php';
    // Options API + Reading / module helpers.
    require_once AP_ABSPATH . 'ap-includes/class-ap-options.php';
    // Settings API (register_setting / sections / fields / sanitized group save).
    require_once AP_ABSPATH . 'ap-includes/class-ap-settings.php';
    // Navigation menus (locations, items, render).
    require_once AP_ABSPATH . 'ap-includes/class-ap-nav-menu.php';
    // RSS / Atom syndication feeds.
    require_once AP_ABSPATH . 'ap-includes/class-ap-feed.php';
    // Nonces for state-changing forms (admin + front-end).
    require_once AP_ABSPATH . 'ap-includes/class-ap-nonce.php';
    // Hall of Fame: voluntary domain registration only (no installer pings / telemetry).
    require_once AP_ABSPATH . 'ap-includes/class-ap-hall-of-fame.php';
    // Procedural helpers (ap_hash_password, ap_login, ap_insert_post, …) after core classes.
    require_once AP_ABSPATH . 'ap-includes/functions.php';
    // Front-end template tags (the_title, body_class, …).
    require_once AP_ABSPATH . 'ap-includes/template-tags.php';

    // Register built-in post statuses/types and taxonomies once core is loaded.
    if (class_exists('AP_Post', false)) {
        AP_Post::ensureBuiltins();
    }
    if (class_exists('AP_Taxonomy', false)) {
        AP_Taxonomy::ensureBuiltins();
    }
    // Ensure default roles exist (idempotent; does not overwrite custom maps).
    if (class_exists('AP_Roles', false)) {
        try {
            AP_Roles::ensureDefaults();
        } catch (Throwable) {
            // DB may be unavailable during some CLI/tooling paths.
        }
    }

    // Register core Settings API option groups (general, modules, writing, …).
    if (class_exists('AP_Settings', false)) {
        AP_Settings::registerCore();
    }

    /**
     * Fires after core bootstrap completes (config + base includes loaded).
     * Full hook system lands in Phase 4; reserved for early extensions.
     */
    if (function_exists('ap_do_action')) {
        ap_do_action('ap_loaded');
    }
}
