<?php

/**
 * Tools — Import (WordPress WXR + phpBB).
 *
 * Upload a classic WordPress export (.xml) or a phpBB portable JSON export,
 * or connect to a live phpBB database to migrate users/forums/topics/posts.
 *
 * Cap: import (administrators; manage_options accepted as fallback).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

require_once __DIR__ . '/admin-bootstrap.php';

AP_Admin::requireCapability('import');

AP_Admin::consumeQueryNotice();

$userId = (int) ap_get_current_user_id();
$db = ap_db();

/** @var array<string, mixed>|null $lastResult */
$lastResult = null;
/** @var string $lastImporter wxr|phpbb */
$lastImporter = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = strtolower(trim((string) ($_POST['ap_import_action'] ?? '')));
    $nonce = (string) ($_POST['_ap_nonce'] ?? '');

    if ($action === 'wxr') {
        if (!ap_check_nonce($nonce, 'import-wxr', $userId > 0 ? $userId : null)) {
            AP_Admin::addNotice('Security check failed. Please try again.', 'error');
        } elseif (
            !AP_Admin::userCan($userId, 'import', null, $db)
            && !AP_Admin::userCan($userId, 'manage_options', null, $db)
        ) {
            AP_Admin::addNotice('You do not have permission to import content.', 'error');
        } else {
            if (function_exists('set_time_limit')) {
                @set_time_limit(300);
            }
            @ini_set('memory_limit', '256M');

            $file = $_FILES['wxr'] ?? null;
            if (!is_array($file)) {
                AP_Admin::addNotice('Please choose a WordPress export file (.xml).', 'error');
            } else {
                $args = [
                    'import_authors' => !empty($_POST['import_authors']),
                    'import_attachments' => !empty($_POST['import_attachments']),
                    'import_comments' => !empty($_POST['import_comments']),
                    'default_author' => $userId > 0 ? $userId : 0,
                ];
                $lastResult = ap_import_wxr_upload($file, $db, $args);
                $lastImporter = 'wxr';

                if (!empty($lastResult['ok'])) {
                    $parts = [];
                    if ((int) ($lastResult['authors'] ?? 0) > 0) {
                        $parts[] = (int) $lastResult['authors'] . ' author(s)';
                    }
                    if ((int) ($lastResult['categories'] ?? 0) > 0) {
                        $parts[] = (int) $lastResult['categories'] . ' categor' . ((int) $lastResult['categories'] === 1 ? 'y' : 'ies');
                    }
                    if ((int) ($lastResult['tags'] ?? 0) > 0) {
                        $parts[] = (int) $lastResult['tags'] . ' tag(s)';
                    }
                    if ((int) ($lastResult['posts'] ?? 0) > 0) {
                        $parts[] = (int) $lastResult['posts'] . ' post(s)';
                    }
                    if ((int) ($lastResult['pages'] ?? 0) > 0) {
                        $parts[] = (int) $lastResult['pages'] . ' page(s)';
                    }
                    if ((int) ($lastResult['attachments'] ?? 0) > 0) {
                        $parts[] = (int) $lastResult['attachments'] . ' attachment(s)';
                    }
                    if ((int) ($lastResult['comments'] ?? 0) > 0) {
                        $parts[] = (int) $lastResult['comments'] . ' comment(s)';
                    }
                    $summary = $parts !== []
                        ? 'Imported ' . implode(', ', $parts) . '.'
                        : 'Import finished (no new content rows).';
                    if ((int) ($lastResult['skipped'] ?? 0) > 0) {
                        $summary .= ' Skipped ' . (int) $lastResult['skipped'] . ' item(s).';
                    }
                    if ((int) ($lastResult['authors_created'] ?? 0) > 0) {
                        $summary .= ' New authors need a password reset before they can log in.';
                    }
                    AP_Admin::addNotice($summary, 'success');
                    foreach ($lastResult['warnings'] ?? [] as $warn) {
                        if (is_string($warn) && $warn !== '') {
                            AP_Admin::addNotice($warn, 'warning');
                        }
                    }
                } else {
                    $errs = $lastResult['errors'] ?? [];
                    if ($errs === []) {
                        AP_Admin::addNotice('Import failed.', 'error');
                    } else {
                        foreach ($errs as $err) {
                            AP_Admin::addNotice((string) $err, 'error');
                        }
                    }
                    foreach ($lastResult['warnings'] ?? [] as $warn) {
                        if (is_string($warn) && $warn !== '') {
                            AP_Admin::addNotice($warn, 'warning');
                        }
                    }
                }
            }
        }
    } elseif ($action === 'phpbb-json') {
        if (!ap_check_nonce($nonce, 'import-phpbb-json', $userId > 0 ? $userId : null)) {
            AP_Admin::addNotice('Security check failed. Please try again.', 'error');
        } elseif (
            !AP_Admin::userCan($userId, 'import', null, $db)
            && !AP_Admin::userCan($userId, 'manage_options', null, $db)
        ) {
            AP_Admin::addNotice('You do not have permission to import content.', 'error');
        } else {
            if (function_exists('set_time_limit')) {
                @set_time_limit(600);
            }
            @ini_set('memory_limit', '512M');

            $file = $_FILES['phpbb'] ?? null;
            if (!is_array($file)) {
                AP_Admin::addNotice('Please choose a phpBB JSON export file (.json).', 'error');
            } else {
                $args = [
                    'import_users' => !empty($_POST['import_users']),
                    'import_forums' => !empty($_POST['import_forums']),
                    'import_topics' => !empty($_POST['import_topics']),
                    'import_posts' => !empty($_POST['import_posts']),
                    'skip_bots' => !empty($_POST['skip_bots']),
                    'default_author' => $userId > 0 ? $userId : 0,
                ];
                $lastResult = ap_import_phpbb_upload($file, $db, $args);
                $lastImporter = 'phpbb';
                ap_admin_phpbb_import_notices($lastResult);
            }
        }
    } elseif ($action === 'phpbb-db') {
        if (!ap_check_nonce($nonce, 'import-phpbb-db', $userId > 0 ? $userId : null)) {
            AP_Admin::addNotice('Security check failed. Please try again.', 'error');
        } elseif (
            !AP_Admin::userCan($userId, 'import', null, $db)
            && !AP_Admin::userCan($userId, 'manage_options', null, $db)
        ) {
            AP_Admin::addNotice('You do not have permission to import content.', 'error');
        } else {
            if (function_exists('set_time_limit')) {
                @set_time_limit(600);
            }
            @ini_set('memory_limit', '512M');

            $connection = [
                'driver' => strtolower(trim((string) ($_POST['phpbb_driver'] ?? 'mysql'))),
                'host' => trim((string) ($_POST['phpbb_host'] ?? 'localhost')),
                'name' => trim((string) ($_POST['phpbb_name'] ?? '')),
                'user' => (string) ($_POST['phpbb_user'] ?? ''),
                'password' => (string) ($_POST['phpbb_password'] ?? ''),
                'table_prefix' => trim((string) ($_POST['phpbb_prefix'] ?? 'phpbb_')),
                'charset' => 'utf8mb4',
            ];
            if ($connection['name'] === '') {
                AP_Admin::addNotice('Please enter the phpBB database name (or SQLite path).', 'error');
            } else {
                $args = [
                    'import_users' => !empty($_POST['import_users']),
                    'import_forums' => !empty($_POST['import_forums']),
                    'import_topics' => !empty($_POST['import_topics']),
                    'import_posts' => !empty($_POST['import_posts']),
                    'skip_bots' => !empty($_POST['skip_bots']),
                    'default_author' => $userId > 0 ? $userId : 0,
                ];
                $lastResult = ap_import_phpbb_database($connection, $db, $args);
                $lastImporter = 'phpbb';
                ap_admin_phpbb_import_notices($lastResult);
            }
        }
    }
}

/**
 * Flash notices for a phpBB import result.
 *
 * @param array<string, mixed> $lastResult
 */
if (!function_exists('ap_admin_phpbb_import_notices')) {
    function ap_admin_phpbb_import_notices(array $lastResult): void
    {
        if (!empty($lastResult['ok'])) {
            $parts = [];
            if ((int) ($lastResult['users'] ?? 0) > 0) {
                $parts[] = (int) $lastResult['users'] . ' user(s)';
            }
            if ((int) ($lastResult['forums'] ?? 0) > 0) {
                $parts[] = (int) $lastResult['forums'] . ' forum(s)';
            }
            if ((int) ($lastResult['topics'] ?? 0) > 0) {
                $parts[] = (int) $lastResult['topics'] . ' topic(s)';
            }
            if ((int) ($lastResult['posts'] ?? 0) > 0) {
                $parts[] = (int) $lastResult['posts'] . ' post(s)';
            }
            $summary = $parts !== []
                ? 'Imported ' . implode(', ', $parts) . ' from phpBB.'
                : 'phpBB import finished (no new rows).';
            if ((int) ($lastResult['skipped'] ?? 0) > 0) {
                $summary .= ' Skipped ' . (int) $lastResult['skipped'] . ' item(s).';
            }
            if ((int) ($lastResult['users_created'] ?? 0) > 0) {
                $summary .= ' New users need a password reset before they can log in.';
            }
            AP_Admin::addNotice($summary, 'success');
            foreach ($lastResult['warnings'] ?? [] as $warn) {
                if (is_string($warn) && $warn !== '') {
                    AP_Admin::addNotice($warn, 'warning');
                }
            }
        } else {
            $errs = $lastResult['errors'] ?? [];
            if ($errs === []) {
                AP_Admin::addNotice('phpBB import failed.', 'error');
            } else {
                foreach ($errs as $err) {
                    AP_Admin::addNotice((string) $err, 'error');
                }
            }
            foreach ($lastResult['warnings'] ?? [] as $warn) {
                if (is_string($warn) && $warn !== '') {
                    AP_Admin::addNotice($warn, 'warning');
                }
            }
        }
    }
} // function_exists ap_admin_phpbb_import_notices

$maxBytes = class_exists('AP_Wxr_Importer', false)
    ? AP_Wxr_Importer::maxBytes()
    : 33554432;
$maxLabel = class_exists('AP_Wxr_Importer', false)
    ? AP_Wxr_Importer::formatBytes($maxBytes)
    : '32 MiB';

$phpbbMaxBytes = class_exists('AP_Phpbb_Importer', false)
    ? AP_Phpbb_Importer::maxBytes()
    : 67108864;
$phpbbMaxLabel = class_exists('AP_Phpbb_Importer', false)
    ? AP_Phpbb_Importer::formatBytes($phpbbMaxBytes)
    : '64 MiB';

$ap_admin_title = 'Import';
$ap_admin_screen = 'import';
require __DIR__ . '/admin-header.php';
?>
<div class="ap-page-header">
    <h1>Import</h1>
</div>

<p>
    Migrate content from <strong>WordPress</strong> (WXR) or <strong>phpBB</strong>
    (live database or portable JSON export) into AgoraPress.
</p>

<section class="ap-metabox" aria-labelledby="ap-import-wxr-title">
    <h2 id="ap-import-wxr-title" class="ap-metabox-title">WordPress (WXR)</h2>
    <div class="ap-metabox-body">
        <p>
            In WordPress, go to <strong>Tools → Export</strong>, choose All content
            (or Posts/Pages), download the <code>.xml</code> file, then upload it here.
            Maximum file size: <?php echo ap_esc_html($maxLabel); ?>.
        </p>
        <ul class="ap-list">
            <li>Matching authors (by username or email) are reused; new ones get a random password (reset required).</li>
            <li>Revisions, nav menu items, and block/FSE theme data are skipped.</li>
            <li>Attachments create library rows with the original URL stored in meta; files are not downloaded yet.</li>
        </ul>

        <form method="post" enctype="multipart/form-data" class="ap-form" action="">
            <?php echo ap_nonce_field('import-wxr', '_ap_nonce', true, $userId > 0 ? $userId : null); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <input type="hidden" name="ap_import_action" value="wxr">

            <p class="ap-field">
                <label for="ap-wxr-file"><strong>WXR file</strong></label><br>
                <input
                    type="file"
                    name="wxr"
                    id="ap-wxr-file"
                    accept=".xml,application/xml,text/xml"
                    required
                >
            </p>

            <fieldset class="ap-fieldset">
                <legend>Options</legend>
                <p>
                    <label>
                        <input type="checkbox" name="import_authors" value="1" checked>
                        Import authors (create missing users)
                    </label>
                </p>
                <p>
                    <label>
                        <input type="checkbox" name="import_comments" value="1" checked>
                        Import comments
                    </label>
                </p>
                <p>
                    <label>
                        <input type="checkbox" name="import_attachments" value="1" checked>
                        Import attachment rows (URLs only; no remote download)
                    </label>
                </p>
            </fieldset>

            <p class="ap-form-actions">
                <button type="submit" class="button button-primary">Upload file and import</button>
            </p>
        </form>
    </div>
</section>

<section class="ap-metabox" aria-labelledby="ap-import-phpbb-title">
    <h2 id="ap-import-phpbb-title" class="ap-metabox-title">phpBB</h2>
    <div class="ap-metabox-body">
        <p>
            Import users, forum hierarchy, topics, and posts from phpBB 3.x.
            BBCode UIDs are stripped so AgoraPress can re-render markup.
            Attachments, private messages, and ranks are not imported yet.
            Maximum JSON size: <?php echo ap_esc_html($phpbbMaxLabel); ?>.
        </p>
        <ul class="ap-list">
            <li>Matching users (by username or email) are reused; new ones get a random password (reset required).</li>
            <li>Anonymous and bot accounts are skipped by default.</li>
            <li>Historical post times and topic view counts are preserved when available.</li>
        </ul>

        <h3 class="ap-subtitle">Option A — JSON export</h3>
        <p>
            Upload a portable JSON file with format
            <code><?php echo ap_esc_html(class_exists('AP_Phpbb_Importer', false) ? AP_Phpbb_Importer::JSON_FORMAT : 'agorapress-phpbb-export'); ?></code>
            containing <code>users</code>, <code>forums</code>, <code>topics</code>, and <code>posts</code> arrays.
        </p>
        <form method="post" enctype="multipart/form-data" class="ap-form" action="">
            <?php echo ap_nonce_field('import-phpbb-json', '_ap_nonce', true, $userId > 0 ? $userId : null); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <input type="hidden" name="ap_import_action" value="phpbb-json">

            <p class="ap-field">
                <label for="ap-phpbb-file"><strong>JSON file</strong></label><br>
                <input
                    type="file"
                    name="phpbb"
                    id="ap-phpbb-file"
                    accept=".json,application/json,text/plain"
                    required
                >
            </p>

            <fieldset class="ap-fieldset">
                <legend>Options</legend>
                <p>
                    <label>
                        <input type="checkbox" name="import_users" value="1" checked>
                        Import users
                    </label>
                </p>
                <p>
                    <label>
                        <input type="checkbox" name="import_forums" value="1" checked>
                        Import forums / categories
                    </label>
                </p>
                <p>
                    <label>
                        <input type="checkbox" name="import_topics" value="1" checked>
                        Import topics
                    </label>
                </p>
                <p>
                    <label>
                        <input type="checkbox" name="import_posts" value="1" checked>
                        Import posts / replies
                    </label>
                </p>
                <p>
                    <label>
                        <input type="checkbox" name="skip_bots" value="1" checked>
                        Skip bots and ignore-type accounts
                    </label>
                </p>
            </fieldset>

            <p class="ap-form-actions">
                <button type="submit" class="button button-primary">Upload JSON and import</button>
            </p>
        </form>

        <h3 class="ap-subtitle">Option B — Live database</h3>
        <p>
            Connect to a phpBB MySQL/MariaDB (or compatible) database that this server can reach.
            Credentials are used only for this request and are not stored.
        </p>
        <form method="post" class="ap-form" action="">
            <?php echo ap_nonce_field('import-phpbb-db', '_ap_nonce', true, $userId > 0 ? $userId : null); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <input type="hidden" name="ap_import_action" value="phpbb-db">

            <p class="ap-field">
                <label for="ap-phpbb-driver"><strong>Driver</strong></label><br>
                <select name="phpbb_driver" id="ap-phpbb-driver">
                    <option value="mysql" selected>MySQL / MariaDB</option>
                    <option value="sqlite">SQLite (path in database name)</option>
                    <option value="pgsql">PostgreSQL</option>
                </select>
            </p>
            <p class="ap-field">
                <label for="ap-phpbb-host"><strong>Host</strong></label><br>
                <input type="text" name="phpbb_host" id="ap-phpbb-host" value="localhost" class="regular-text" autocomplete="off">
            </p>
            <p class="ap-field">
                <label for="ap-phpbb-name"><strong>Database name</strong></label><br>
                <input type="text" name="phpbb_name" id="ap-phpbb-name" value="" class="regular-text" required autocomplete="off">
            </p>
            <p class="ap-field">
                <label for="ap-phpbb-user"><strong>Username</strong></label><br>
                <input type="text" name="phpbb_user" id="ap-phpbb-user" value="" class="regular-text" autocomplete="off">
            </p>
            <p class="ap-field">
                <label for="ap-phpbb-password"><strong>Password</strong></label><br>
                <input type="password" name="phpbb_password" id="ap-phpbb-password" value="" class="regular-text" autocomplete="new-password">
            </p>
            <p class="ap-field">
                <label for="ap-phpbb-prefix"><strong>Table prefix</strong></label><br>
                <input type="text" name="phpbb_prefix" id="ap-phpbb-prefix" value="phpbb_" class="regular-text" autocomplete="off">
            </p>

            <fieldset class="ap-fieldset">
                <legend>Options</legend>
                <p>
                    <label>
                        <input type="checkbox" name="import_users" value="1" checked>
                        Import users
                    </label>
                </p>
                <p>
                    <label>
                        <input type="checkbox" name="import_forums" value="1" checked>
                        Import forums / categories
                    </label>
                </p>
                <p>
                    <label>
                        <input type="checkbox" name="import_topics" value="1" checked>
                        Import topics
                    </label>
                </p>
                <p>
                    <label>
                        <input type="checkbox" name="import_posts" value="1" checked>
                        Import posts / replies
                    </label>
                </p>
                <p>
                    <label>
                        <input type="checkbox" name="skip_bots" value="1" checked>
                        Skip bots and ignore-type accounts
                    </label>
                </p>
            </fieldset>

            <p class="ap-form-actions">
                <button type="submit" class="button button-primary">Connect and import</button>
            </p>
        </form>
    </div>
</section>

<?php if (is_array($lastResult) && !empty($lastResult['ok']) && $lastImporter === 'wxr') : ?>
<section class="ap-metabox" aria-labelledby="ap-import-result-title">
    <h2 id="ap-import-result-title" class="ap-metabox-title">Last import summary (WordPress)</h2>
    <div class="ap-metabox-body">
        <table class="ap-table widefat striped">
            <tbody>
                <tr><th scope="row">Site title (source)</th><td><?php echo ap_esc_html((string) ($lastResult['site_title'] ?? '')); ?></td></tr>
                <tr><th scope="row">WXR version</th><td><?php echo ap_esc_html((string) ($lastResult['wxr_version'] ?? '')); ?></td></tr>
                <tr><th scope="row">Base URL</th><td><?php echo ap_esc_html((string) ($lastResult['base_url'] ?? '')); ?></td></tr>
                <tr><th scope="row">Authors</th><td><?php echo (int) ($lastResult['authors'] ?? 0); ?> (<?php echo (int) ($lastResult['authors_created'] ?? 0); ?> created)</td></tr>
                <tr><th scope="row">Categories / tags</th><td><?php echo (int) ($lastResult['categories'] ?? 0); ?> / <?php echo (int) ($lastResult['tags'] ?? 0); ?></td></tr>
                <tr><th scope="row">Posts / pages</th><td><?php echo (int) ($lastResult['posts'] ?? 0); ?> / <?php echo (int) ($lastResult['pages'] ?? 0); ?></td></tr>
                <tr><th scope="row">Attachments</th><td><?php echo (int) ($lastResult['attachments'] ?? 0); ?></td></tr>
                <tr><th scope="row">Comments</th><td><?php echo (int) ($lastResult['comments'] ?? 0); ?></td></tr>
                <tr><th scope="row">Skipped</th><td><?php echo (int) ($lastResult['skipped'] ?? 0); ?></td></tr>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

<?php if (is_array($lastResult) && !empty($lastResult['ok']) && $lastImporter === 'phpbb') : ?>
<section class="ap-metabox" aria-labelledby="ap-import-phpbb-result-title">
    <h2 id="ap-import-phpbb-result-title" class="ap-metabox-title">Last import summary (phpBB)</h2>
    <div class="ap-metabox-body">
        <table class="ap-table widefat striped">
            <tbody>
                <tr><th scope="row">Board name (source)</th><td><?php echo ap_esc_html((string) ($lastResult['source_name'] ?? '')); ?></td></tr>
                <tr><th scope="row">phpBB version</th><td><?php echo ap_esc_html((string) ($lastResult['source_version'] ?? '')); ?></td></tr>
                <tr>
                    <th scope="row">Users</th>
                    <td>
                        <?php echo (int) ($lastResult['users'] ?? 0); ?>
                        (<?php echo (int) ($lastResult['users_created'] ?? 0); ?> created,
                        <?php echo (int) ($lastResult['users_mapped'] ?? 0); ?> mapped)
                    </td>
                </tr>
                <tr><th scope="row">Forums</th><td><?php echo (int) ($lastResult['forums'] ?? 0); ?></td></tr>
                <tr><th scope="row">Topics</th><td><?php echo (int) ($lastResult['topics'] ?? 0); ?></td></tr>
                <tr><th scope="row">Posts</th><td><?php echo (int) ($lastResult['posts'] ?? 0); ?></td></tr>
                <tr><th scope="row">Skipped</th><td><?php echo (int) ($lastResult['skipped'] ?? 0); ?></td></tr>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

<?php
require __DIR__ . '/admin-footer.php';
