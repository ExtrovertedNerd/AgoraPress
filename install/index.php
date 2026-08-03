<?php

/**
 * AgoraPress web installer.
 *
 * Steps: requirements → database → site info + admin → tables + config.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

// Absolute filesystem path to the AgoraPress root, with trailing slash.
if (!defined('AP_ABSPATH')) {
    define('AP_ABSPATH', dirname(__DIR__) . '/');
}

require_once AP_ABSPATH . 'ap-includes/version.php';
require_once AP_ABSPATH . 'ap-includes/load-config.php';
require_once AP_ABSPATH . 'ap-includes/class-ap-db.php';
require_once AP_ABSPATH . 'ap-includes/class-ap-migrator.php';
require_once AP_ABSPATH . 'ap-includes/class-ap-user.php';
require_once AP_ABSPATH . 'ap-includes/class-ap-requirements.php';
require_once AP_ABSPATH . 'ap-includes/class-ap-installer.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
        'use_strict_mode' => true,
    ]);
}

/**
 * Escape for HTML body/attributes.
 */
function ap_install_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Ensure CSRF token exists and return it.
 */
function ap_install_csrf_token(): string
{
    if (empty($_SESSION['ap_install_csrf']) || !is_string($_SESSION['ap_install_csrf'])) {
        $_SESSION['ap_install_csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['ap_install_csrf'];
}

/**
 * Verify POST CSRF token.
 */
function ap_install_csrf_ok(): bool
{
    $sent = (string) ($_POST['ap_csrf'] ?? '');
    $expect = (string) ($_SESSION['ap_install_csrf'] ?? '');

    return $sent !== '' && $expect !== '' && hash_equals($expect, $sent);
}

/**
 * Current step from query/post.
 */
function ap_install_step(): string
{
    $step = (string) ($_POST['step'] ?? $_GET['step'] ?? 'requirements');
    $allowed = ['requirements', 'database', 'site', 'run', 'done'];

    return in_array($step, $allowed, true) ? $step : 'requirements';
}

/**
 * Shared layout shell.
 *
 * @param list<string> $errors
 */
function ap_install_render(string $title, string $body, array $errors = [], string $step = 'requirements'): void
{
    $version = defined('AP_VERSION') ? (string) AP_VERSION : '';
    $steps = [
        'requirements' => '1. Requirements',
        'database' => '2. Database',
        'site' => '3. Site & admin',
        'run' => '4. Install',
        'done' => 'Done',
    ];
    $stepNav = '';
    foreach ($steps as $id => $label) {
        $class = $id === $step ? ' class="current"' : '';
        $stepNav .= '<li' . $class . '>' . ap_install_h($label) . '</li>';
    }

    $errorHtml = '';
    if ($errors !== []) {
        $items = '';
        foreach ($errors as $err) {
            $items .= '<li>' . ap_install_h($err) . '</li>';
        }
        $errorHtml = '<div class="notice error" role="alert"><ul>' . $items . '</ul></div>';
    }

    $safeTitle = ap_install_h($title);
    $safeVersion = ap_install_h($version);

    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow');

    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{$safeTitle} — AgoraPress Installer</title>
    <style>
        :root { color-scheme: light dark; --bg: #f4f5f7; --fg: #1a1a1a; --card: #fff;
            --border: #dde1e6; --accent: #1a5fb4; --ok: #2ec27e; --bad: #c01c28;
            --muted: #5c6370; --input: #fff; }
        @media (prefers-color-scheme: dark) {
            :root { --bg: #12141a; --fg: #e8eaed; --card: #1c1f28; --border: #2a2f3a;
                --accent: #62a0ea; --ok: #57e389; --bad: #f66151; --muted: #9aa0a6; --input: #12141a; }
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh; font-family: system-ui, -apple-system, "Segoe UI", Roboto, Ubuntu, sans-serif;
            line-height: 1.5; background: var(--bg); color: var(--fg);
        }
        .wrap { max-width: 40rem; margin: 0 auto; padding: 1.5rem 1rem 3rem; }
        header h1 { margin: 0 0 0.25rem; font-size: 1.5rem; font-weight: 650; }
        header .meta { color: var(--muted); font-size: 0.9rem; margin: 0 0 1rem; }
        .steps { list-style: none; display: flex; flex-wrap: wrap; gap: 0.35rem 0.75rem;
            padding: 0; margin: 0 0 1.25rem; font-size: 0.85rem; color: var(--muted); }
        .steps .current { color: var(--accent); font-weight: 600; }
        main {
            background: var(--card); border: 1px solid var(--border); border-radius: 8px;
            padding: 1.5rem 1.25rem; box-shadow: 0 1px 2px rgba(0,0,0,0.04);
        }
        h2 { margin: 0 0 0.75rem; font-size: 1.2rem; }
        p { margin: 0 0 0.75rem; }
        p:last-child { margin-bottom: 0; }
        table.checks { width: 100%; border-collapse: collapse; margin: 0 0 1rem; font-size: 0.95rem; }
        table.checks th, table.checks td { text-align: left; padding: 0.45rem 0.35rem; border-bottom: 1px solid var(--border); vertical-align: top; }
        table.checks th { font-weight: 600; color: var(--muted); font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.03em; }
        .badge { display: inline-block; font-size: 0.75rem; font-weight: 650; padding: 0.1rem 0.45rem; border-radius: 999px; }
        .badge.ok { background: color-mix(in srgb, var(--ok) 22%, transparent); color: var(--ok); }
        .badge.bad { background: color-mix(in srgb, var(--bad) 22%, transparent); color: var(--bad); }
        .badge.warn { background: color-mix(in srgb, #e5a50a 22%, transparent); color: #c88800; }
        label { display: block; font-weight: 600; margin: 0.75rem 0 0.3rem; font-size: 0.95rem; }
        label .hint { font-weight: 400; color: var(--muted); font-size: 0.85rem; }
        input[type="text"], input[type="password"], input[type="email"], input[type="url"], select {
            width: 100%; padding: 0.5rem 0.65rem; border: 1px solid var(--border); border-radius: 6px;
            background: var(--input); color: var(--fg); font: inherit;
        }
        input:focus, select:focus { outline: 2px solid color-mix(in srgb, var(--accent) 50%, transparent); border-color: var(--accent); }
        .row { display: grid; gap: 0.5rem 1rem; }
        @media (min-width: 36rem) { .row.two { grid-template-columns: 1fr 1fr; } }
        .actions { margin-top: 1.25rem; display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center; }
        button, .button {
            display: inline-block; border: 0; border-radius: 6px; padding: 0.55rem 1rem;
            font: inherit; font-weight: 600; cursor: pointer; text-decoration: none;
            background: var(--accent); color: #fff;
        }
        button.secondary, .button.secondary { background: transparent; color: var(--accent);
            border: 1px solid var(--border); }
        button:disabled { opacity: 0.55; cursor: not-allowed; }
        .notice { border-radius: 6px; padding: 0.75rem 1rem; margin: 0 0 1rem; }
        .notice.error { background: color-mix(in srgb, var(--bad) 12%, transparent); border: 1px solid color-mix(in srgb, var(--bad) 35%, transparent); }
        .notice.error ul { margin: 0; padding-left: 1.2rem; }
        .notice.success { background: color-mix(in srgb, var(--ok) 12%, transparent); border: 1px solid color-mix(in srgb, var(--ok) 35%, transparent); }
        code { font-family: ui-monospace, Menlo, monospace; font-size: 0.9em; padding: 0.1em 0.35em; border-radius: 4px; background: color-mix(in srgb, var(--border) 60%, transparent); }
        .field-group { margin-bottom: 0.25rem; }
    </style>
</head>
<body>
    <div class="wrap">
        <header>
            <h1>AgoraPress installer</h1>
            <p class="meta">Version {$safeVersion} · 5-minute setup · no telemetry</p>
            <ol class="steps">{$stepNav}</ol>
        </header>
        <main>
            {$errorHtml}
            {$body}
        </main>
    </div>
</body>
</html>
HTML;
}

// ---------------------------------------------------------------------------
// Already installed?
// ---------------------------------------------------------------------------
// Presence of ap-config.php means the site is installed. Allow the post-install
// “done” success screen only when this browser session just finished install.
$configPath = AP_ABSPATH . 'ap-config.php';
$justFinishedInstall = !empty($_SESSION['ap_install_success'])
    && is_array($_SESSION['ap_install_success']);
if (AP_Installer::configExists($configPath) && !$justFinishedInstall) {
    http_response_code(403);
    $body = <<<'HTML'
        <h2>Already installed</h2>
        <p>
            <code>ap-config.php</code> is present. The installer will not overwrite
            an existing site configuration.
        </p>
        <p>
            Visit the <a href="../">site home</a> or remove <code>ap-config.php</code>
            only if you intentionally want a fresh install (this will not drop database tables).
        </p>
HTML;
    ap_install_render('Already installed', $body, [], 'done');
    exit(0);
}

$errors = [];
$step = ap_install_step();

// ---------------------------------------------------------------------------
// POST handlers
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!ap_install_csrf_ok()) {
        $errors[] = 'Invalid security token. Please reload the page and try again.';
        $step = 'requirements';
    } else {
        $posted = (string) ($_POST['step'] ?? '');

        if ($posted === 'requirements') {
            $checks = AP_Requirements::check(AP_ABSPATH);
            if (!AP_Requirements::allRequiredPassed($checks)) {
                $errors[] = 'Fix the failed required checks before continuing.';
                $step = 'requirements';
            } else {
                $step = 'database';
            }
        } elseif ($posted === 'database') {
            $db = [
                'driver' => (string) ($_POST['db_driver'] ?? 'mysql'),
                'name' => trim((string) ($_POST['db_name'] ?? '')),
                'user' => trim((string) ($_POST['db_user'] ?? '')),
                'password' => (string) ($_POST['db_password'] ?? ''),
                'host' => trim((string) ($_POST['db_host'] ?? 'localhost')),
                'charset' => 'utf8mb4',
                'collate' => 'utf8mb4_unicode_ci',
                'prefix' => trim((string) ($_POST['table_prefix'] ?? 'ap_')),
            ];
            if ($db['driver'] === 'sqlite' && $db['name'] === '') {
                $db['name'] = AP_Installer::defaultSqlitePath(AP_ABSPATH);
            }

            $v = AP_Installer::validateDatabaseInput($db);
            if ($v !== []) {
                $errors = array_merge($errors, $v);
                $step = 'database';
            } else {
                $connErr = AP_Installer::testConnection($db);
                if ($connErr !== null) {
                    $errors[] = 'Could not connect: ' . $connErr;
                    $step = 'database';
                } else {
                    $_SESSION['ap_install_db'] = $db;
                    $step = 'site';
                }
            }
        } elseif ($posted === 'site') {
            $db = $_SESSION['ap_install_db'] ?? null;
            if (!is_array($db)) {
                $errors[] = 'Database settings were lost. Please enter them again.';
                $step = 'database';
            } else {
                $site = [
                    'title' => trim((string) ($_POST['site_title'] ?? '')),
                    'url' => trim((string) ($_POST['site_url'] ?? '')),
                ];
                $admin = [
                    'username' => trim((string) ($_POST['admin_user'] ?? '')),
                    'email' => trim((string) ($_POST['admin_email'] ?? '')),
                    'password' => (string) ($_POST['admin_password'] ?? ''),
                    'password_confirm' => (string) ($_POST['admin_password_confirm'] ?? ''),
                ];
                $v = AP_Installer::validateSiteAndAdmin($site, $admin);
                if ($v !== []) {
                    $errors = array_merge($errors, $v);
                    $step = 'site';
                } else {
                    // Drop confirm before session store.
                    unset($admin['password_confirm']);
                    $_SESSION['ap_install_site'] = $site;
                    $_SESSION['ap_install_admin'] = $admin;
                    // Optional sample content (FEATURES); default on for a browsable first run.
                    $_SESSION['ap_install_sample_content'] = !empty($_POST['sample_content']);
                    $step = 'run';
                }
            }
        } elseif ($posted === 'run') {
            $db = $_SESSION['ap_install_db'] ?? null;
            $site = $_SESSION['ap_install_site'] ?? null;
            $admin = $_SESSION['ap_install_admin'] ?? null;
            if (!is_array($db) || !is_array($site) || !is_array($admin)) {
                $errors[] = 'Install session incomplete. Start from the database step.';
                $step = 'database';
            } else {
                $installOptions = [
                    'sample_content' => !empty($_SESSION['ap_install_sample_content']),
                ];
                $result = AP_Installer::run($db, $site, $admin, $configPath, $installOptions);
                if (!$result['ok']) {
                    $errors = array_merge($errors, $result['errors']);
                    // Keep generated config available if tables succeeded.
                    if (!empty($result['config_php']) && !$result['config_written']) {
                        $_SESSION['ap_install_config_fallback'] = $result['config_php'];
                    }
                    $step = 'run';
                } else {
                    unset(
                        $_SESSION['ap_install_db'],
                        $_SESSION['ap_install_site'],
                        $_SESSION['ap_install_admin'],
                        $_SESSION['ap_install_sample_content'],
                        $_SESSION['ap_install_config_fallback']
                    );
                    $_SESSION['ap_install_success'] = [
                        'admin_id' => $result['admin_id'],
                        'migrations' => $result['migrations'],
                        'site_url' => $site['url'] ?? '',
                        'sample_content' => $result['sample_content'] ?? null,
                    ];
                    $step = 'done';
                    // Redirect to avoid re-POST.
                    header('Location: ?step=done');
                    exit(0);
                }
            }
        }
    }
}

// ---------------------------------------------------------------------------
// Views
// ---------------------------------------------------------------------------
$csrf = ap_install_csrf_token();

if ($step === 'requirements') {
    $checks = AP_Requirements::check(AP_ABSPATH);
    $canContinue = AP_Requirements::allRequiredPassed($checks);
    $rows = '';
    foreach ($checks as $c) {
        $ok = !empty($c['ok']);
        $required = !empty($c['required']);
        if ($ok) {
            $badge = '<span class="badge ok">OK</span>';
        } elseif ($required) {
            $badge = '<span class="badge bad">Required</span>';
        } else {
            $badge = '<span class="badge warn">Recommended</span>';
        }
        $rows .= '<tr><td>' . ap_install_h((string) $c['label']) . '</td><td>'
            . $badge . '</td><td>' . ap_install_h((string) $c['message']) . '</td></tr>';
    }
    $disabled = $canContinue ? '' : ' disabled';
    $body = <<<HTML
        <h2>Server requirements</h2>
        <p>AgoraPress checks PHP, extensions, and filesystem permissions before install.</p>
        <table class="checks">
            <thead><tr><th>Check</th><th>Status</th><th>Detail</th></tr></thead>
            <tbody>{$rows}</tbody>
        </table>
        <form method="post" action="">
            <input type="hidden" name="ap_csrf" value="{$csrf}">
            <input type="hidden" name="step" value="requirements">
            <div class="actions">
                <button type="submit"{$disabled}>Continue to database</button>
            </div>
        </form>
HTML;
    ap_install_render('Requirements', $body, $errors, 'requirements');
    exit(0);
}

if ($step === 'database') {
    $db = $_SESSION['ap_install_db'] ?? [];
    $driverRaw = (string) ($db['driver'] ?? ($_POST['db_driver'] ?? 'mysql'));
    $name = ap_install_h((string) ($db['name'] ?? ($_POST['db_name'] ?? '')));
    $user = ap_install_h((string) ($db['user'] ?? ($_POST['db_user'] ?? '')));
    $host = ap_install_h((string) ($db['host'] ?? ($_POST['db_host'] ?? 'localhost')));
    $prefix = ap_install_h((string) ($db['prefix'] ?? ($_POST['table_prefix'] ?? 'ap_')));
    $sqliteDefault = ap_install_h(AP_Installer::defaultSqlitePath(AP_ABSPATH));
    $selMysql = $driverRaw === 'mysql' ? ' selected' : '';
    $selSqlite = $driverRaw === 'sqlite' ? ' selected' : '';
    $selPgsql = $driverRaw === 'pgsql' ? ' selected' : '';

    $body = <<<HTML
        <h2>Database connection</h2>
        <p>
            MySQL / MariaDB is recommended for production. SQLite works for local demos
            (zero-config). PostgreSQL is also supported.
        </p>
        <form method="post" action="">
            <input type="hidden" name="ap_csrf" value="{$csrf}">
            <input type="hidden" name="step" value="database">
            <div class="field-group">
                <label for="db_driver">Database driver</label>
                <select name="db_driver" id="db_driver">
                    <option value="mysql"{$selMysql}>MySQL / MariaDB</option>
                    <option value="sqlite"{$selSqlite}>SQLite</option>
                    <option value="pgsql"{$selPgsql}>PostgreSQL</option>
                </select>
            </div>
            <div class="field-group">
                <label for="db_name">Database name <span class="hint">(SQLite: full file path)</span></label>
                <input type="text" name="db_name" id="db_name" value="{$name}" placeholder="{$sqliteDefault}" autocomplete="off">
            </div>
            <div class="row two">
                <div class="field-group">
                    <label for="db_user">Username <span class="hint">(not used for SQLite)</span></label>
                    <input type="text" name="db_user" id="db_user" value="{$user}" autocomplete="username">
                </div>
                <div class="field-group">
                    <label for="db_password">Password</label>
                    <input type="password" name="db_password" id="db_password" value="" autocomplete="current-password">
                </div>
            </div>
            <div class="field-group">
                <label for="db_host">Host <span class="hint">(e.g. localhost, 127.0.0.1:3307, or db in Docker)</span></label>
                <input type="text" name="db_host" id="db_host" value="{$host}" autocomplete="off">
            </div>
            <div class="field-group">
                <label for="table_prefix">Table prefix</label>
                <input type="text" name="table_prefix" id="table_prefix" value="{$prefix}" autocomplete="off">
            </div>
            <div class="actions">
                <a class="button secondary" href="?step=requirements">Back</a>
                <button type="submit">Test connection &amp; continue</button>
            </div>
        </form>
HTML;
    ap_install_render('Database', $body, $errors, 'database');
    exit(0);
}

if ($step === 'site') {
    if (empty($_SESSION['ap_install_db']) || !is_array($_SESSION['ap_install_db'])) {
        header('Location: ?step=database');
        exit(0);
    }
    $site = $_SESSION['ap_install_site'] ?? [];
    $admin = $_SESSION['ap_install_admin'] ?? [];
    $title = ap_install_h((string) ($site['title'] ?? ($_POST['site_title'] ?? 'My AgoraPress Site')));
    $url = ap_install_h((string) ($site['url'] ?? ($_POST['site_url'] ?? AP_Installer::guessSiteUrl())));
    $user = ap_install_h((string) ($admin['username'] ?? ($_POST['admin_user'] ?? 'admin')));
    $email = ap_install_h((string) ($admin['email'] ?? ($_POST['admin_email'] ?? '')));
    // Default sample content on; preserve choice after validation errors / back navigation.
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['step'] ?? '') === 'site') {
        $sampleChecked = !empty($_POST['sample_content']) ? ' checked' : '';
    } elseif (array_key_exists('ap_install_sample_content', $_SESSION)) {
        $sampleChecked = !empty($_SESSION['ap_install_sample_content']) ? ' checked' : '';
    } else {
        $sampleChecked = ' checked';
    }

    $body = <<<HTML
        <h2>Site information &amp; administrator</h2>
        <p>These details create your first administrator account and default options.</p>
        <form method="post" action="">
            <input type="hidden" name="ap_csrf" value="{$csrf}">
            <input type="hidden" name="step" value="site">
            <div class="field-group">
                <label for="site_title">Site title</label>
                <input type="text" name="site_title" id="site_title" value="{$title}" required maxlength="200">
            </div>
            <div class="field-group">
                <label for="site_url">Site URL</label>
                <input type="url" name="site_url" id="site_url" value="{$url}" required>
            </div>
            <div class="field-group">
                <label for="admin_user">Admin username</label>
                <input type="text" name="admin_user" id="admin_user" value="{$user}" required autocomplete="username">
            </div>
            <div class="field-group">
                <label for="admin_email">Admin email</label>
                <input type="email" name="admin_email" id="admin_email" value="{$email}" required autocomplete="email">
            </div>
            <div class="row two">
                <div class="field-group">
                    <label for="admin_password">Password <span class="hint">(min 8 characters)</span></label>
                    <input type="password" name="admin_password" id="admin_password" required minlength="8" autocomplete="new-password">
                </div>
                <div class="field-group">
                    <label for="admin_password_confirm">Confirm password</label>
                    <input type="password" name="admin_password_confirm" id="admin_password_confirm" required minlength="8" autocomplete="new-password">
                </div>
            </div>
            <div class="field-group" style="margin-top:1rem">
                <label for="sample_content" style="font-weight:500">
                    <input type="checkbox" name="sample_content" id="sample_content" value="1"{$sampleChecked}
                        style="width:auto;margin-right:0.4rem;vertical-align:middle">
                    Add sample content
                    <span class="hint">(Hello World post, About &amp; Privacy pages, welcome forum topic — optional)</span>
                </label>
            </div>
            <div class="actions">
                <a class="button secondary" href="?step=database">Back</a>
                <button type="submit">Continue to install</button>
            </div>
        </form>
HTML;
    ap_install_render('Site & admin', $body, $errors, 'site');
    exit(0);
}

if ($step === 'run') {
    $db = $_SESSION['ap_install_db'] ?? null;
    $site = $_SESSION['ap_install_site'] ?? null;
    $admin = $_SESSION['ap_install_admin'] ?? null;
    if (!is_array($db) || !is_array($site) || !is_array($admin)) {
        header('Location: ?step=database');
        exit(0);
    }

    $title = ap_install_h((string) ($site['title'] ?? ''));
    $url = ap_install_h((string) ($site['url'] ?? ''));
    $user = ap_install_h((string) ($admin['username'] ?? ''));
    $driver = ap_install_h((string) ($db['driver'] ?? ''));
    $prefix = ap_install_h(AP_Installer::normalizePrefix((string) ($db['prefix'] ?? 'ap_')));
    $sampleOn = !empty($_SESSION['ap_install_sample_content']);
    $sampleLabel = $sampleOn ? 'Yes (posts, pages, forums)' : 'No';

    $fallback = '';
    if (!empty($_SESSION['ap_install_config_fallback']) && is_string($_SESSION['ap_install_config_fallback'])) {
        $cfg = ap_install_h($_SESSION['ap_install_config_fallback']);
        $fallback = '<p>Generated <code>ap-config.php</code> (copy if write failed):</p>'
            . '<textarea readonly rows="12" style="width:100%;font-family:monospace;font-size:0.8rem">'
            . $cfg . '</textarea>';
    }

    $body = <<<HTML
        <h2>Ready to install</h2>
        <p>Review the summary, then create tables and write <code>ap-config.php</code>.</p>
        <table class="checks">
            <tbody>
                <tr><td>Site title</td><td colspan="2">{$title}</td></tr>
                <tr><td>Site URL</td><td colspan="2">{$url}</td></tr>
                <tr><td>Admin user</td><td colspan="2">{$user}</td></tr>
                <tr><td>Database</td><td colspan="2">{$driver} · prefix <code>{$prefix}</code></td></tr>
                <tr><td>Sample content</td><td colspan="2">{$sampleLabel}</td></tr>
            </tbody>
        </table>
        {$fallback}
        <form method="post" action="">
            <input type="hidden" name="ap_csrf" value="{$csrf}">
            <input type="hidden" name="step" value="run">
            <div class="actions">
                <a class="button secondary" href="?step=site">Back</a>
                <button type="submit">Run installation</button>
            </div>
        </form>
HTML;
    ap_install_render('Install', $body, $errors, 'run');
    exit(0);
}

if ($step === 'done') {
    $success = $_SESSION['ap_install_success'] ?? null;
    $siteUrl = is_array($success) ? (string) ($success['site_url'] ?? '') : '';
    $homeHref = $siteUrl !== '' ? ap_install_h(rtrim($siteUrl, '/') . '/') : '../';
    $migCount = is_array($success) && isset($success['migrations']) && is_array($success['migrations'])
        ? count($success['migrations'])
        : 0;

    $sampleNote = '';
    if (is_array($success) && isset($success['sample_content']) && is_array($success['sample_content'])) {
        $sc = $success['sample_content'];
        $posts = count($sc['posts'] ?? []);
        $pages = count($sc['pages'] ?? []);
        $forums = count($sc['forums'] ?? []);
        $topics = count($sc['topics'] ?? []);
        if (!empty($sc['skipped'])) {
            $sampleNote = '<p>Sample content was already present and was left unchanged.</p>';
        } elseif (!empty($sc['ok'])) {
            $sampleNote = '<p>Sample content added: '
                . ap_install_h((string) $posts) . ' post(s), '
                . ap_install_h((string) $pages) . ' page(s), '
                . ap_install_h((string) $forums) . ' forum(s), '
                . ap_install_h((string) $topics) . ' topic(s). '
                . 'Edit or delete them anytime from the admin.</p>';
        }
    }

    $body = <<<HTML
        <h2>Installation complete</h2>
        <div class="notice success">
            <p>
                AgoraPress is installed. Core tables were created
                ({$migCount} migration(s)), an administrator account was added,
                and <code>ap-config.php</code> was written.
            </p>
        </div>
        {$sampleNote}
        <p>
            Open the site home or sign in at <code>ap-admin/</code>.
            Keep your admin password safe.
        </p>
        <div class="actions">
            <a class="button" href="{$homeHref}">Visit site</a>
        </div>
HTML;
    ap_install_render('Complete', $body, $errors, 'done');
    exit(0);
}

// Fallback
header('Location: ?step=requirements');
exit(0);
