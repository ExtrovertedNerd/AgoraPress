<?php

/**
 * Settings — Privacy (policy page + tools links).
 *
 * Selects the public Privacy Policy page and links to Tools → Export / Erase
 * Personal Data (GDPR-style).
 *
 * Cap: manage_privacy_options (administrators; manage_options accepted as fallback).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

require_once __DIR__ . '/admin-bootstrap.php';

if (
    !AP_Admin::currentUserCan('manage_privacy_options')
    && !AP_Admin::currentUserCan('manage_options')
) {
    AP_Admin::requireCapability('manage_privacy_options');
} else {
    AP_Admin::requireLogin();
}

AP_Admin::consumeQueryNotice();

$userId = (int) ap_get_current_user_id();
$db = ap_db();

$pages = AP_Post::query([
    'post_type' => 'page',
    'post_status' => 'publish',
    'orderby' => 'post_title',
    'order' => 'ASC',
    'limit' => 200,
], $db);

// --- Save ---
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
    && (isset($_POST['ap_save_privacy']) || isset($_POST['ap_settings_submit']));
if ($isPost) {
    $nonce = (string) ($_POST['_ap_nonce'] ?? '');
    $nonceOk = ap_check_nonce($nonce, 'ap_options_privacy', $userId > 0 ? $userId : null)
        || ap_check_nonce($nonce, 'ap_settings_privacy', $userId > 0 ? $userId : null);
    if (!$nonceOk) {
        AP_Admin::addNotice('Security check failed. Please try again.', 'error');
    } elseif (
        !AP_Admin::userCan($userId, 'manage_privacy_options', null, $db)
        && !AP_Admin::userCan($userId, 'manage_options', null, $db)
    ) {
        AP_Admin::addNotice('You do not have permission to manage privacy settings.', 'error');
    } else {
        $pageId = (int) ($_POST['wp_page_for_privacy_policy'] ?? 0);
        if ($pageId > 0) {
            $page = AP_Post::get($pageId, $db);
            if ($page === null || $page->post_type !== 'page') {
                AP_Admin::addNotice('Please select a valid published page.', 'error');
            } else {
                $ok = AP_Privacy::setPrivacyPolicyPageId($pageId, $db);
                if ($ok) {
                    AP_Admin::redirect(AP_Admin::url('options-privacy.php', ['message' => 'privacy_saved']));
                }
                AP_Admin::addNotice('Could not save privacy settings.', 'error');
            }
        } else {
            $ok = AP_Privacy::setPrivacyPolicyPageId(0, $db);
            if ($ok) {
                AP_Admin::redirect(AP_Admin::url('options-privacy.php', ['message' => 'privacy_saved']));
            }
            AP_Admin::addNotice('Could not save privacy settings.', 'error');
        }
    }
}

$policyPageId = AP_Privacy::getPrivacyPolicyPageId($db);
$policyUrl = AP_Privacy::getPrivacyPolicyUrl($db);

$ap_admin_title = 'Privacy Settings';
$ap_admin_screen = 'options-privacy';
require __DIR__ . '/admin-header.php';
?>
<div class="ap-page-header">
    <h1>Privacy Settings</h1>
</div>

<p>
    Configure your public privacy policy page and use the tools below to fulfill
    personal data export and erasure requests (GDPR-style).
</p>

<form method="post" action="" class="ap-form ap-form--settings">
    <?php echo ap_nonce_field('ap_options_privacy', '_ap_nonce', false); ?>

    <fieldset class="ap-fieldset">
        <legend>Privacy Policy page</legend>
        <p class="ap-field">
            <label for="wp_page_for_privacy_policy">Policy page</label>
            <select name="wp_page_for_privacy_policy" id="wp_page_for_privacy_policy">
                <option value="0">— Select —</option>
                <?php foreach ($pages as $page) : ?>
                    <?php if (!$page instanceof AP_Post) {
                        continue;
                    } ?>
                    <option value="<?php echo (int) $page->ID; ?>"
                        <?php echo $policyPageId === (int) $page->ID ? 'selected' : ''; ?>>
                        <?php echo ap_esc_html((string) $page->post_title); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <span class="ap-help">
                Choose a published page that describes how this site handles personal data.
                <?php if ($pages === []) : ?>
                    No published pages yet — create one under Pages first.
                <?php endif; ?>
            </span>
        </p>
        <?php if ($policyUrl !== '') : ?>
            <p>
                <a href="<?php echo ap_esc_url($policyUrl); ?>" target="_blank" rel="noopener noreferrer">
                    View privacy policy
                </a>
            </p>
        <?php endif; ?>
    </fieldset>

    <p class="ap-form-actions">
        <button type="submit" name="ap_save_privacy" value="1" class="ap-button ap-button--primary">
            Save privacy settings
        </button>
    </p>
</form>

<section class="ap-metabox" aria-labelledby="ap-privacy-tools-title">
    <h2 id="ap-privacy-tools-title" class="ap-metabox-title">Personal data tools</h2>
    <p>
        Export a machine-readable copy of a user’s personal data, or permanently erase
        their personal identifiers and account while retaining content for site integrity
        (posts reassigned / comments anonymized as “Deleted User”).
    </p>
    <ul class="ap-list">
        <li>
            <a href="<?php echo ap_esc_url(AP_Admin::url('export-personal-data.php')); ?>">
                Export Personal Data
            </a>
            — download a JSON package of profile, comments, forum activity, and messages.
        </li>
        <li>
            <a href="<?php echo ap_esc_url(AP_Admin::url('erase-personal-data.php')); ?>">
                Erase Personal Data
            </a>
            — anonymize content ownership and delete the account (cannot erase the only administrator).
        </li>
    </ul>
</section>

<?php
require __DIR__ . '/admin-footer.php';
