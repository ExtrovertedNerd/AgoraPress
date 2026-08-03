<?php

/**
 * Tools — Site Health (status checks, system info, clear caches).
 *
 * Cap: view_site_health (administrators; manage_options accepted as fallback).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

require_once __DIR__ . '/admin-bootstrap.php';

if (
    !AP_Admin::currentUserCan('view_site_health')
    && !AP_Admin::currentUserCan('manage_options')
) {
    AP_Admin::requireCapability('view_site_health');
} else {
    AP_Admin::requireLogin();
}

AP_Admin::consumeQueryNotice();

$userId = (int) ap_get_current_user_id();
$db = ap_db();

// --- POST: clear caches ---
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = strtolower(trim((string) ($_POST['ap_site_health_action'] ?? '')));
    $nonce = (string) ($_POST['_ap_nonce'] ?? '');

    $canManage = AP_Admin::userCan($userId, 'view_site_health', null, $db)
        || AP_Admin::userCan($userId, 'manage_options', null, $db);

    if ($action === 'clear_caches') {
        if (!ap_check_nonce($nonce, 'site-health-clear-caches', $userId > 0 ? $userId : null)) {
            AP_Admin::addNotice('Security check failed. Please try again.', 'error');
        } elseif (!$canManage) {
            AP_Admin::addNotice('You do not have permission to clear caches.', 'error');
        } else {
            $result = ap_clear_site_health_caches($db);
            if ($result['ok']) {
                AP_Admin::redirect(AP_Admin::url('site-health.php', [
                    'tab' => 'status',
                    'message' => 'caches_cleared',
                    'expired' => (string) $result['expired_transients'],
                ]));
            }
            AP_Admin::addNotice($result['message'] !== '' ? $result['message'] : 'Could not clear caches.', 'error');
        }
    }
}

// Query notices
$message = strtolower(trim((string) ($_GET['message'] ?? '')));
if ($message === 'caches_cleared') {
    $expired = max(0, (int) ($_GET['expired'] ?? 0));
    AP_Admin::addNotice(
        sprintf(
            'Runtime caches cleared (%d expired transient%s removed).',
            $expired,
            $expired === 1 ? '' : 's'
        ),
        'success'
    );
}

$tab = strtolower(trim((string) ($_GET['tab'] ?? 'status')));
if (!in_array($tab, ['status', 'info'], true)) {
    $tab = 'status';
}

$checks = ap_get_site_health_checks($db);
$summary = ap_get_site_health_summary($checks, $db);
$overall = ap_get_site_health_status($checks, $db);
$info = $tab === 'info' ? ap_get_site_health_info($db) : [];
$infoText = $tab === 'info' ? ap_get_site_health_info_text($db) : '';

// Group checks by status for display (critical first).
$grouped = [
    AP_Site_Health::STATUS_CRITICAL => [],
    AP_Site_Health::STATUS_RECOMMENDED => [],
    AP_Site_Health::STATUS_GOOD => [],
];
foreach ($checks as $check) {
    $st = AP_Site_Health::normalizeStatus((string) ($check['status'] ?? 'good'));
    $grouped[$st][] = $check;
}

$ap_admin_title = 'Site Health';
$ap_admin_screen = 'site-health';
require __DIR__ . '/admin-header.php';
?>
<div class="ap-page-header">
    <h1>Site Health</h1>
</div>

<p>
    Review runtime status checks and copy system information for support.
    Checks never send data off-site; version-check results use the local cache only.
</p>

<nav class="ap-tabs ap-site-health-tabs" aria-label="Site Health sections">
    <a class="ap-tab<?php echo $tab === 'status' ? ' is-active' : ''; ?>"
       href="<?php echo ap_esc_url(AP_Admin::url('site-health.php', ['tab' => 'status'])); ?>">
        Status
    </a>
    <a class="ap-tab<?php echo $tab === 'info' ? ' is-active' : ''; ?>"
       href="<?php echo ap_esc_url(AP_Admin::url('site-health.php', ['tab' => 'info'])); ?>">
        Info
    </a>
</nav>

<?php if ($tab === 'status') : ?>
    <section class="ap-metabox ap-site-health-summary" aria-labelledby="ap-site-health-summary-title">
        <h2 id="ap-site-health-summary-title" class="ap-metabox-title">Overview</h2>
        <div class="ap-metabox-body">
            <p class="ap-site-health-overall ap-site-health-overall--<?php echo ap_esc_attr($overall); ?>">
                <span class="ap-site-health-badge ap-site-health-badge--<?php echo ap_esc_attr($overall); ?>">
                    <?php echo ap_esc_html(AP_Site_Health::statusLabel($overall)); ?>
                </span>
                <?php
                if ($overall === AP_Site_Health::STATUS_CRITICAL) {
                    echo ' Critical issues need attention before some features may work correctly.';
                } elseif ($overall === AP_Site_Health::STATUS_RECOMMENDED) {
                    echo ' Recommended improvements are available; the site should still function.';
                } else {
                    echo ' All checks look good.';
                }
                ?>
            </p>
            <ul class="ap-site-health-counts">
                <li><strong><?php echo (int) $summary['critical']; ?></strong> critical</li>
                <li><strong><?php echo (int) $summary['recommended']; ?></strong> recommended</li>
                <li><strong><?php echo (int) $summary['good']; ?></strong> good</li>
            </ul>

            <form method="post" action="" class="ap-form ap-site-health-actions">
                <?php echo ap_nonce_field('site-health-clear-caches', '_ap_nonce', false); ?>
                <input type="hidden" name="ap_site_health_action" value="clear_caches">
                <button type="submit" class="ap-button">Clear caches &amp; expired transients</button>
            </form>
        </div>
    </section>

    <?php foreach ($grouped as $status => $items) : ?>
        <?php if ($items === []) {
            continue;
        } ?>
        <section class="ap-metabox ap-site-health-group" aria-labelledby="ap-sh-<?php echo ap_esc_attr($status); ?>-title">
            <h2 id="ap-sh-<?php echo ap_esc_attr($status); ?>-title" class="ap-metabox-title">
                <?php echo ap_esc_html(AP_Site_Health::statusLabel($status)); ?>
                <span class="ap-site-health-count">(<?php echo count($items); ?>)</span>
            </h2>
            <div class="ap-metabox-body">
                <ul class="ap-site-health-list">
                    <?php foreach ($items as $check) : ?>
                        <li class="ap-site-health-item ap-site-health-item--<?php echo ap_esc_attr($status); ?>">
                            <div class="ap-site-health-item-head">
                                <span class="ap-site-health-badge ap-site-health-badge--<?php echo ap_esc_attr($status); ?>">
                                    <?php echo ap_esc_html(AP_Site_Health::statusLabel($status)); ?>
                                </span>
                                <strong class="ap-site-health-item-label">
                                    <?php echo ap_esc_html((string) $check['label']); ?>
                                </strong>
                            </div>
                            <p class="ap-site-health-item-message">
                                <?php echo ap_esc_html((string) $check['message']); ?>
                            </p>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </section>
    <?php endforeach; ?>

<?php else : /* info tab */ ?>
    <section class="ap-metabox" aria-labelledby="ap-site-health-copy-title">
        <h2 id="ap-site-health-copy-title" class="ap-metabox-title">Copy for support</h2>
        <div class="ap-metabox-body">
            <p>
                Share this text when asking for help. Secrets (passwords, salt values) are never included.
            </p>
            <label class="screen-reader-text" for="ap-site-health-info-text">System information</label>
            <textarea id="ap-site-health-info-text" class="ap-input ap-site-health-info-text" rows="14" readonly
                ><?php echo ap_esc_textarea($infoText); ?></textarea>
        </div>
    </section>

    <?php foreach ($info as $sectionId => $section) : ?>
        <?php
        $sectionLabel = (string) ($section['label'] ?? $sectionId);
        $fields = is_array($section['fields'] ?? null) ? $section['fields'] : [];
        ?>
        <section class="ap-metabox" aria-labelledby="ap-sh-info-<?php echo ap_esc_attr((string) $sectionId); ?>">
            <h2 id="ap-sh-info-<?php echo ap_esc_attr((string) $sectionId); ?>" class="ap-metabox-title">
                <?php echo ap_esc_html($sectionLabel); ?>
            </h2>
            <div class="ap-metabox-body">
                <table class="ap-table ap-site-health-info-table">
                    <tbody>
                    <?php foreach ($fields as $field) : ?>
                        <?php if (!is_array($field)) {
                            continue;
                        } ?>
                        <tr>
                            <th scope="row"><?php echo ap_esc_html((string) ($field['label'] ?? '')); ?></th>
                            <td><?php echo ap_esc_html((string) ($field['value'] ?? '')); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endforeach; ?>
<?php endif; ?>

<?php
require __DIR__ . '/admin-footer.php';
