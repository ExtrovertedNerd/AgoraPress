<?php

/**
 * Tools — Analytics (local pageview reports + settings).
 *
 * Privacy-respecting site analytics: data stays in this site's database.
 * No third-party scripts, pixels, or external endpoints. Not related to
 * Hall of Fame or the public version check.
 *
 * Cap: manage_options (administrators).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

require_once __DIR__ . '/admin-bootstrap.php';

// Cap: manage_options (administrators only; constant AP_Admin_Analytics::CAPABILITY).
AP_Admin::requireCapability(AP_Admin_Analytics::CAPABILITY);

$db = ap_db();
$userId = (int) ap_get_current_user_id();
AP_Admin::consumeQueryNotice();

// --- Save analytics settings (enable + retention) ---
if (AP_Admin_Analytics::isSettingsPost()) {
    $result = AP_Admin_Analytics::saveSettingsFromPost($_POST, $userId, $db);
    if (!empty($result['ok'])) {
        $daysKeep = AP_Admin_Analytics::sanitizeDays($_GET['days'] ?? AP_Admin_Analytics::DEFAULT_DAYS);
        $redirectArgs = [
            'message' => (string) ($result['message_key'] ?? 'analytics_saved'),
            'days' => (string) $daysKeep,
        ];
        AP_Admin::redirect(AP_Admin::url('analytics.php', $redirectArgs));
    }
    AP_Admin::addNotice(
        (string) ($result['error'] !== '' ? $result['error'] : 'Could not save analytics settings.'),
        'error'
    );
}

$daysParam = isset($_GET['days']) ? $_GET['days'] : AP_Admin_Analytics::DEFAULT_DAYS;
$days = AP_Admin_Analytics::sanitizeDays($daysParam);
$report = AP_Admin_Analytics::getReport($db, ['days' => $days]);

$summary = $report['summary'];
$topPaths = $report['top_paths'];
$topReferrers = $report['top_referrers'];
$daily = $report['daily'];
$maxDaily = AP_Admin_Analytics::maxDailyHits($daily);
$enabled = !empty($report['enabled']);
$retentionDays = (int) $report['retention_days'];
$hasHits = !empty($report['has_hits']);
$emptyKind = AP_Admin_Analytics::emptyStateKind($enabled, $hasHits);
$pageEmpty = AP_Admin_Analytics::emptyStateFor('page', $enabled, $hasHits, $days, !$hasHits);
$pathsEmpty = AP_Admin_Analytics::emptyStateFor('paths', $enabled, $hasHits, $days, $topPaths === []);
$referrersEmpty = AP_Admin_Analytics::emptyStateFor(
    'referrers',
    $enabled,
    $hasHits,
    $days,
    $topReferrers === []
);
$dailyEmpty = AP_Admin_Analytics::emptyStateFor(
    'daily',
    $enabled,
    $hasHits,
    $days,
    $daily === [] || !$hasHits || $maxDaily === 0
);

$ap_admin_title = 'Analytics';
$ap_admin_screen = 'analytics';
$ap_admin_body_class = 'ap-analytics ap-analytics--' . $emptyKind;
require __DIR__ . '/admin-header.php';
?>
<div class="ap-page-header">
    <h1>Analytics</h1>
</div>

<p class="ap-analytics-intro" role="note">
    <?php echo ap_esc_html(AP_Admin_Analytics::PRIVACY_INTRO); ?>
</p>

<?php if (!$enabled) : ?>
    <div class="ap-notice ap-notice--info ap-analytics-disabled-notice" role="status">
        <p>
            <strong>Collection is off.</strong>
            Page views are not being recorded. Enable collection in the
            <a href="#ap-analytics-settings">Analytics settings</a> below.
            <?php if ($hasHits) : ?>
                Existing history still shows below.
            <?php else : ?>
                Nothing is stored until you opt in.
            <?php endif; ?>
        </p>
    </div>
<?php else : ?>
    <p class="ap-muted ap-analytics-status">
        Collection is <strong>on</strong>.
        Retention: <?php echo (int) $retentionDays; ?> day<?php echo $retentionDays === 1 ? '' : 's'; ?>.
        Admin browsing of the public site is excluded by default.
    </p>
<?php endif; ?>

<!-- Settings: enable collection + retention -->
<section class="ap-metabox ap-analytics-settings" id="ap-analytics-settings" aria-labelledby="ap-analytics-settings-title">
    <h2 id="ap-analytics-settings-title" class="ap-metabox-title">Analytics settings</h2>
    <div class="ap-metabox-body">
        <form method="post" action="" class="ap-form ap-form--settings ap-analytics-settings-form">
            <?php echo ap_nonce_field(AP_Admin_Analytics::NONCE_ACTION, '_ap_nonce', false); ?>

            <fieldset class="ap-fieldset">
                <legend class="screen-reader-text">Collection and retention</legend>

                <p class="ap-field ap-analytics-enable-field">
                    <label>
                        <input type="checkbox" name="analytics_enabled" id="analytics_enabled" value="1"
                            <?php echo $enabled ? 'checked' : ''; ?>>
                        <strong>Enable pageview collection</strong>
                    </label>
                    <span class="ap-help ap-analytics-collection-help">
                        <?php echo ap_esc_html(AP_Admin_Analytics::PRIVACY_COLLECTION_HELP); ?>
                    </span>
                </p>

                <p class="ap-field">
                    <label for="analytics_retention_days">Keep history for</label>
                    <input type="number" name="analytics_retention_days" id="analytics_retention_days"
                        min="<?php echo (int) AP_Analytics::MIN_RETENTION_DAYS; ?>"
                        max="<?php echo (int) AP_Analytics::MAX_RETENTION_DAYS; ?>"
                        step="1"
                        value="<?php echo (int) $retentionDays; ?>"
                        required>
                    days
                    <span class="ap-help">
                        Older hits and daily rollups are pruned on a daily schedule
                        (default <?php echo (int) AP_Analytics::DEFAULT_RETENTION_DAYS; ?> days).
                        Allowed range: <?php echo (int) AP_Analytics::MIN_RETENTION_DAYS; ?>–
                        <?php echo (int) AP_Analytics::MAX_RETENTION_DAYS; ?> days.
                    </span>
                </p>
            </fieldset>

            <p class="ap-form-actions">
                <button type="submit" name="<?php echo ap_esc_attr(AP_Admin_Analytics::SETTINGS_SUBMIT); ?>" value="1"
                    class="button button-primary">
                    Save Analytics Settings
                </button>
            </p>
        </form>
    </div>
</section>

<?php if (!$hasHits) : ?>
    <div class="ap-analytics-empty-banner" data-empty-kind="<?php echo ap_esc_attr($emptyKind); ?>">
        <?php
        // Page-level empty: disabled (opt-in) vs enabled but waiting for first hit.
        echo AP_Admin_Analytics::renderEmptyState($pageEmpty, 'ap-analytics-empty--banner'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in helper
        ?>
    </div>
<?php endif; ?>

<!-- Summary counts -->
<section class="ap-metabox ap-analytics-summary<?php echo !$hasHits ? ' ap-analytics-summary--empty' : ''; ?>"
    aria-labelledby="ap-analytics-summary-title">
    <h2 id="ap-analytics-summary-title" class="ap-metabox-title">Pageviews</h2>
    <div class="ap-metabox-body">
        <ul class="ap-analytics-stat-list">
            <li class="ap-analytics-stat">
                <span class="ap-analytics-stat-count"><?php echo (int) $summary['today']; ?></span>
                <span class="ap-analytics-stat-label">Today</span>
            </li>
            <li class="ap-analytics-stat">
                <span class="ap-analytics-stat-count"><?php echo (int) $summary['last_7_days']; ?></span>
                <span class="ap-analytics-stat-label">Last 7 days</span>
            </li>
            <li class="ap-analytics-stat">
                <span class="ap-analytics-stat-count"><?php echo (int) $summary['last_30_days']; ?></span>
                <span class="ap-analytics-stat-label">Last 30 days</span>
            </li>
        </ul>
        <?php if (!$hasHits) : ?>
            <p class="ap-muted ap-analytics-summary-hint">
                <?php if (!$enabled) : ?>
                    Counts stay at zero until collection is enabled and public pages are visited.
                <?php else : ?>
                    Counts stay at zero until the first public pageview is recorded.
                <?php endif; ?>
            </p>
        <?php endif; ?>
    </div>
</section>

<nav class="ap-tabs ap-analytics-days-tabs" aria-label="Report window">
    <?php foreach (AP_Admin_Analytics::ALLOWED_DAYS as $window) : ?>
        <a class="ap-tab<?php echo $days === $window ? ' is-active' : ''; ?>"
           href="<?php echo ap_esc_url(AP_Admin::url('analytics.php', ['days' => (string) $window])); ?>">
            <?php echo (int) $window; ?> days
        </a>
    <?php endforeach; ?>
</nav>

<div class="ap-analytics-grid">
    <!-- Top paths -->
    <section class="ap-metabox ap-analytics-widget" aria-labelledby="ap-analytics-paths-title">
        <h2 id="ap-analytics-paths-title" class="ap-metabox-title">
            Top paths
            <span class="ap-metabox-meta">(last <?php echo (int) $days; ?> days)</span>
        </h2>
        <div class="ap-metabox-body">
            <?php if ($topPaths === []) : ?>
                <?php echo AP_Admin_Analytics::renderEmptyState($pathsEmpty); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in helper ?>
            <?php else : ?>
                <div class="ap-table-scroll">
                    <table class="ap-table ap-analytics-table">
                        <thead>
                            <tr>
                                <th scope="col">Path</th>
                                <th scope="col" class="ap-num">Views</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($topPaths as $row) : ?>
                                <tr>
                                    <td>
                                        <code class="ap-analytics-path" title="<?php echo ap_esc_attr((string) $row['path']); ?>">
                                            <?php echo ap_esc_html(AP_Admin_Analytics::truncateLabel((string) $row['path'], 72)); ?>
                                        </code>
                                    </td>
                                    <td class="ap-num"><?php echo (int) $row['hits']; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- Top referrers -->
    <section class="ap-metabox ap-analytics-widget" aria-labelledby="ap-analytics-referrers-title">
        <h2 id="ap-analytics-referrers-title" class="ap-metabox-title">
            Top referrers
            <span class="ap-metabox-meta">(last <?php echo (int) $days; ?> days)</span>
        </h2>
        <div class="ap-metabox-body">
            <?php if ($topReferrers === []) : ?>
                <?php echo AP_Admin_Analytics::renderEmptyState($referrersEmpty); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in helper ?>
            <?php else : ?>
                <div class="ap-table-scroll">
                    <table class="ap-table ap-analytics-table">
                        <thead>
                            <tr>
                                <th scope="col">Referrer</th>
                                <th scope="col" class="ap-num">Views</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($topReferrers as $row) : ?>
                                <tr>
                                    <td>
                                        <span class="ap-analytics-referrer" title="<?php echo ap_esc_attr((string) $row['referrer']); ?>">
                                            <?php echo ap_esc_html(AP_Admin_Analytics::truncateLabel((string) $row['referrer'], 72)); ?>
                                        </span>
                                    </td>
                                    <td class="ap-num"><?php echo (int) $row['hits']; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </section>
</div>

<!-- Daily totals table -->
<section class="ap-metabox ap-analytics-daily" aria-labelledby="ap-analytics-daily-title">
    <h2 id="ap-analytics-daily-title" class="ap-metabox-title">
        Daily pageviews
        <span class="ap-metabox-meta">(last <?php echo (int) $days; ?> days)</span>
    </h2>
    <div class="ap-metabox-body">
        <?php if ($daily === [] || !$hasHits || $maxDaily === 0) : ?>
            <?php echo AP_Admin_Analytics::renderEmptyState($dailyEmpty); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in helper ?>
        <?php else : ?>
            <div class="ap-table-scroll">
                <table class="ap-table ap-analytics-table ap-analytics-daily-table">
                    <thead>
                        <tr>
                            <th scope="col">Day</th>
                            <th scope="col" class="ap-num">Views</th>
                            <th scope="col" class="ap-analytics-bar-col"><span class="screen-reader-text">Relative volume</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        // Newest first for scanning recent activity.
                        $dailyDesc = array_reverse($daily);
                        foreach ($dailyDesc as $row) :
                            $hits = (int) ($row['hits'] ?? 0);
                            $pct = $maxDaily > 0 ? (int) round(($hits / $maxDaily) * 100) : 0;
                            ?>
                            <tr>
                                <td><?php echo ap_esc_html((string) ($row['day'] ?? '')); ?></td>
                                <td class="ap-num"><?php echo $hits; ?></td>
                                <td class="ap-analytics-bar-cell">
                                    <span class="ap-analytics-bar" style="width: <?php echo max(0, min(100, $pct)); ?>%;" aria-hidden="true"></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php
require __DIR__ . '/admin-footer.php';
