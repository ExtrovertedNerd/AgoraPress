<?php

/**
 * Dashboard — Update Core (one-click auto-update).
 *
 * Shows installed vs remote version from the public version.json endpoint,
 * re-check control, and a nonce-protected one-click update button when a
 * newer package URL is available. Manual Download / Changelog links remain
 * available when published.
 *
 * Cap: update_core (administrators; manage_options accepted as fallback in
 * the version-check notice path).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

require_once __DIR__ . '/admin-bootstrap.php';

AP_Admin::requireCapability('update_core');

AP_Admin::consumeQueryNotice();

$userId = (int) ap_get_current_user_id();
$db = ap_db();

// --- POST actions ---
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = strtolower(trim((string) ($_POST['ap_update_action'] ?? '')));
    $nonce = (string) ($_POST['_ap_nonce'] ?? '');

    if ($action === 'check') {
        if (!ap_check_nonce($nonce, 'update-core-check', $userId > 0 ? $userId : null)) {
            AP_Admin::addNotice('Security check failed. Please try again.', 'error');
        } else {
            $info = ap_force_version_check($db);
            if ($info['ok'] && $info['version'] !== '') {
                if (ap_has_core_update($db)) {
                    AP_Admin::redirect(AP_Admin::url('update-core.php', [
                        'message' => 'update_available',
                        'v' => $info['version'],
                    ]));
                }
                AP_Admin::redirect(AP_Admin::url('update-core.php', [
                    'message' => 'up_to_date',
                    'v' => $info['version'],
                ]));
            }
            AP_Admin::addNotice(
                'Could not reach the version endpoint. Check network access or try again later.',
                'warning'
            );
        }
    } elseif ($action === 'update') {
        if (!ap_check_nonce($nonce, 'update-core-run', $userId > 0 ? $userId : null)) {
            AP_Admin::addNotice('Security check failed. Please try again.', 'error');
        } elseif (!AP_Admin::userCan($userId, 'update_core', null, $db)
            && !AP_Admin::userCan($userId, 'manage_options', null, $db)
        ) {
            AP_Admin::addNotice('You do not have permission to update core.', 'error');
        } else {
            // Raise limits for large packages when possible.
            if (function_exists('set_time_limit')) {
                @set_time_limit(300);
            }
            @ini_set('memory_limit', '256M');

            $result = ap_run_core_update($db, ['force_check' => false]);
            if ($result['ok']) {
                $params = [
                    'message' => 'updated',
                    'from' => $result['from_version'],
                    'to' => $result['to_version'] !== '' ? $result['to_version'] : $result['package_version'],
                    'files' => (string) $result['files_applied'],
                    'migrations' => (string) count($result['migrations']),
                ];
                AP_Admin::redirect(AP_Admin::url('update-core.php', $params));
            }
            $msg = $result['errors'] !== []
                ? implode(' ', $result['errors'])
                : 'The update could not be completed.';
            AP_Admin::addNotice($msg, 'error');
            foreach ($result['warnings'] as $warn) {
                AP_Admin::addNotice($warn, 'warning');
            }
        }
    }
}

// Query-string flash messages.
$message = (string) ($_GET['message'] ?? '');
if ($message === 'updated') {
    $from = (string) ($_GET['from'] ?? '');
    $to = (string) ($_GET['to'] ?? '');
    $files = (int) ($_GET['files'] ?? 0);
    $migrations = (int) ($_GET['migrations'] ?? 0);
    $text = 'AgoraPress was updated successfully';
    if ($from !== '' && $to !== '') {
        $text .= ' from ' . $from . ' to ' . $to;
    } elseif ($to !== '') {
        $text .= ' to ' . $to;
    }
    $text .= '.';
    if ($files > 0) {
        $text .= ' Applied ' . $files . ' file(s).';
    }
    if ($migrations > 0) {
        $text .= ' Ran ' . $migrations . ' database migration(s).';
    }
    $text .= ' You may need to refresh or re-login if the admin shell changed.';
    AP_Admin::addNotice($text, 'success');
} elseif ($message === 'up_to_date') {
    $v = (string) ($_GET['v'] ?? '');
    AP_Admin::addNotice(
        $v !== ''
            ? 'You are on the latest version (' . $v . ').'
            : 'You are on the latest version.',
        'success'
    );
} elseif ($message === 'update_available') {
    $v = (string) ($_GET['v'] ?? '');
    AP_Admin::addNotice(
        $v !== ''
            ? 'A newer version is available: ' . $v . '.'
            : 'A newer version is available.',
        'warning'
    );
}

$preflight = ap_can_core_update($db);
$current = $preflight['current_version'] !== ''
    ? $preflight['current_version']
    : (defined('AP_VERSION') ? (string) AP_VERSION : 'unknown');
$remote = $preflight['remote_version'];
$download = $preflight['download_url'];
$changelog = $preflight['changelog_url'];
$sha256 = $preflight['sha256'];
$canUpdate = !empty($preflight['can_update']);
$hasUpdate = !empty($preflight['has_update']);

$checkNonce = ap_create_nonce('update-core-check', $userId > 0 ? $userId : null);
$runNonce = ap_create_nonce('update-core-run', $userId > 0 ? $userId : null);

$ap_admin_title = 'Update Core';
$ap_admin_screen = 'update-core';
require __DIR__ . '/admin-header.php';
?>
<div class="ap-page-header">
    <h1>Update Core</h1>
</div>

<p class="ap-help">
    AgoraPress checks the public
    <code>version.json</code> endpoint for new releases. Checks never send your
    domain or other site identity. One-click update downloads the published
    package, verifies an optional SHA-256 checksum, replaces core files
    (preserving <code>ap-config.php</code> and your content under
    <code>ap-content/</code>), and runs any pending database migrations.
</p>

<div class="ap-dashboard-widget" style="margin-bottom:1.25rem">
    <h2 class="ap-dashboard-widget__title">Installed version</h2>
    <p>
        <strong><?php echo ap_esc_html($current); ?></strong>
    </p>
    <?php if ($remote !== '') : ?>
        <p>
            Latest reported:
            <strong><?php echo ap_esc_html($remote); ?></strong>
            <?php if ($hasUpdate) : ?>
                <span class="ap-badge ap-badge--warning">Update available</span>
            <?php else : ?>
                <span class="ap-badge ap-badge--success">Up to date</span>
            <?php endif; ?>
        </p>
    <?php else : ?>
        <p class="ap-muted">Latest version unknown (check disabled, offline, or not yet fetched).</p>
    <?php endif; ?>

    <?php if ($sha256 !== '') : ?>
        <p class="ap-muted"><small>Package SHA-256: <code><?php echo ap_esc_html($sha256); ?></code></small></p>
    <?php endif; ?>

    <form method="post" action="<?php echo ap_esc_url(AP_Admin::url('update-core.php')); ?>" class="ap-inline-form" style="margin-top:0.75rem">
        <input type="hidden" name="ap_update_action" value="check">
        <input type="hidden" name="_ap_nonce" value="<?php echo ap_esc_attr($checkNonce); ?>">
        <button type="submit" class="button">Check again</button>
    </form>
</div>

<?php if ($preflight['errors'] !== []) : ?>
    <div class="ap-notice ap-notice--warning">
        <p><strong>Pre-flight notes</strong></p>
        <ul>
            <?php foreach ($preflight['errors'] as $err) : ?>
                <li><?php echo ap_esc_html($err); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if ($preflight['warnings'] !== [] && $preflight['errors'] === []) : ?>
    <div class="ap-notice ap-notice--info">
        <ul>
            <?php foreach ($preflight['warnings'] as $warn) : ?>
                <li><?php echo ap_esc_html($warn); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="ap-dashboard-widget">
    <h2 class="ap-dashboard-widget__title">One-click update</h2>
    <?php if ($canUpdate) : ?>
        <p>
            Install AgoraPress <strong><?php echo ap_esc_html($remote); ?></strong>
            from the published package. The site will briefly enter maintenance
            mode for visitors while files are applied.
        </p>
        <form method="post" action="<?php echo ap_esc_url(AP_Admin::url('update-core.php')); ?>"
              onsubmit="return confirm('Update AgoraPress core to <?php echo ap_esc_attr($remote); ?>? Visitors will see a short maintenance page.');">
            <input type="hidden" name="ap_update_action" value="update">
            <input type="hidden" name="_ap_nonce" value="<?php echo ap_esc_attr($runNonce); ?>">
            <button type="submit" class="button button-primary">Update to <?php echo ap_esc_html($remote); ?></button>
        </form>
    <?php elseif ($hasUpdate && $download === '') : ?>
        <p>
            Version <strong><?php echo ap_esc_html($remote); ?></strong> is available,
            but no package download URL was published. Use a manual install when
            a download link appears, or obtain the release zip from the project site.
        </p>
    <?php else : ?>
        <p>No automatic update is ready. Use <strong>Check again</strong> after a new release is published.</p>
    <?php endif; ?>

    <?php if ($download !== '' || $changelog !== '') : ?>
        <p style="margin-top:1rem">
            <?php if ($download !== '') : ?>
                <a class="button" href="<?php echo ap_esc_url($download); ?>" target="_blank" rel="noopener noreferrer">Manual download</a>
            <?php endif; ?>
            <?php if ($changelog !== '') : ?>
                <a class="button" href="<?php echo ap_esc_url($changelog); ?>" target="_blank" rel="noopener noreferrer">Changelog</a>
            <?php endif; ?>
        </p>
    <?php endif; ?>
</div>

<div class="ap-notice ap-notice--info" style="margin-top:1.25rem">
    <p>
        <strong>What is preserved:</strong>
        <code>ap-config.php</code>,
        <code>ap-content/uploads/</code>,
        <code>ap-content/plugins/</code>,
        <code>ap-content/mu-plugins/</code>,
        and custom themes (the default <code>agora</code> theme may be updated from the package).
    </p>
</div>

<?php
require __DIR__ . '/admin-footer.php';
