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
 * Presence of the generated config file is the install signal until the
 * installer writes a stronger marker in Phase 1.
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
            Copy <code>ap-config-sample.php</code> to <code>ap-config.php</code>
            and complete installation (web installer, CLI, or Docker Compose),
            or follow the project README quick-start.
        </p>
        <p class="meta">This is a temporary bootstrap screen until the installer is available.</p>
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

    // Site has a config file — load it, then core procedural includes.
    require_once ap_config_path();

    require_once AP_ABSPATH . 'ap-includes/functions.php';
    require_once AP_ABSPATH . 'ap-includes/hooks.php';

    /**
     * Fires after core bootstrap completes (config + base includes loaded).
     * Full hook system lands in Phase 4; reserved for early extensions.
     */
    if (function_exists('ap_do_action')) {
        ap_do_action('ap_loaded');
    }
}
