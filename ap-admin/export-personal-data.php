<?php

/**
 * Tools — Export Personal Data (GDPR-style).
 *
 * Look up a user by ID, login, or email and download a JSON package of their
 * personal data (profile, meta, posts, comments, forum activity, PMs, …).
 *
 * Cap: export_others_personal_data (administrators; manage_options fallback).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

require_once __DIR__ . '/admin-bootstrap.php';

if (
    !AP_Admin::currentUserCan('export_others_personal_data')
    && !AP_Admin::currentUserCan('manage_options')
    && !AP_Admin::currentUserCan('export')
) {
    AP_Admin::requireCapability('export_others_personal_data');
} else {
    AP_Admin::requireLogin();
}

AP_Admin::consumeQueryNotice();

$userId = (int) ap_get_current_user_id();
$db = ap_db();

/** @var AP_User|null $target */
$target = null;
/** @var array<string, mixed>|null $preview */
$preview = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = strtolower(trim((string) ($_POST['ap_privacy_action'] ?? '')));
    $nonce = (string) ($_POST['_ap_nonce'] ?? '');
    $identifier = trim((string) ($_POST['user_identifier'] ?? ''));

    $canExport = AP_Admin::userCan($userId, 'export_others_personal_data', null, $db)
        || AP_Admin::userCan($userId, 'manage_options', null, $db)
        || AP_Admin::userCan($userId, 'export', null, $db);

    if ($action === 'preview' || $action === 'download') {
        if (!ap_check_nonce($nonce, 'export-personal-data', $userId > 0 ? $userId : null)) {
            AP_Admin::addNotice('Security check failed. Please try again.', 'error');
        } elseif (!$canExport) {
            AP_Admin::addNotice('You do not have permission to export personal data.', 'error');
        } elseif ($identifier === '') {
            AP_Admin::addNotice('Enter a user ID, username, or email address.', 'error');
        } else {
            $target = AP_Privacy::resolveUser($identifier, $db);
            if ($target === null) {
                AP_Admin::addNotice('No user matched that identifier.', 'error');
            } elseif ($action === 'download') {
                $export = AP_Privacy::exportPersonalDataJson($target->ID, $db);
                if (!$export['ok']) {
                    foreach ($export['errors'] as $err) {
                        AP_Admin::addNotice((string) $err, 'error');
                    }
                } else {
                    if (!headers_sent()) {
                        header('Content-Type: application/json; charset=utf-8');
                        header(
                            'Content-Disposition: attachment; filename="'
                            . str_replace(['"', "\r", "\n"], '', $export['filename'])
                            . '"'
                        );
                        header('X-Content-Type-Options: nosniff');
                        header('Cache-Control: no-store, no-cache, must-revalidate');
                    }
                    echo $export['json'];
                    exit(0);
                }
            } else {
                $preview = AP_Privacy::exportPersonalData($target->ID, $db);
                if (!$preview['ok']) {
                    foreach ($preview['errors'] as $err) {
                        AP_Admin::addNotice((string) $err, 'error');
                    }
                    $preview = null;
                } else {
                    AP_Admin::addNotice(
                        'Preview ready for user “' . $target->user_login . '”. Download the full JSON package when ready.',
                        'success'
                    );
                }
            }
        }
    }
}

$ap_admin_title = 'Export Personal Data';
$ap_admin_screen = 'export-personal-data';
require __DIR__ . '/admin-header.php';
?>
<div class="ap-page-header">
    <h1>Export Personal Data</h1>
</div>

<p>
    Generate a portable JSON export of personal data stored for a registered user.
    Password hashes and session tokens are never included.
    See also
    <a href="<?php echo ap_esc_url(AP_Admin::url('erase-personal-data.php')); ?>">Erase Personal Data</a>
    and
    <a href="<?php echo ap_esc_url(AP_Admin::url('options-privacy.php')); ?>">Privacy Settings</a>.
</p>

<form method="post" action="" class="ap-form">
    <?php echo ap_nonce_field('export-personal-data', '_ap_nonce', false); ?>
    <p class="ap-field">
        <label for="user_identifier">User ID, username, or email</label>
        <input type="text" name="user_identifier" id="user_identifier" class="ap-input"
            value="<?php echo ap_esc_attr((string) ($_POST['user_identifier'] ?? '')); ?>"
            required autocomplete="off">
    </p>
    <p class="ap-form-actions">
        <button type="submit" name="ap_privacy_action" value="preview" class="ap-button">
            Preview groups
        </button>
        <button type="submit" name="ap_privacy_action" value="download" class="ap-button ap-button--primary">
            Download JSON
        </button>
    </p>
</form>

<?php if ($preview !== null && $target !== null) : ?>
    <section class="ap-metabox" aria-labelledby="ap-export-preview-title">
        <h2 id="ap-export-preview-title" class="ap-metabox-title">
            Export preview —
            <?php echo ap_esc_html($target->user_login); ?>
            (#<?php echo (int) $target->ID; ?>)
        </h2>
        <p>
            Generated <?php echo ap_esc_html((string) ($preview['generated_at'] ?? '')); ?>
            for <?php echo ap_esc_html((string) ($preview['site'] ?? '')); ?>.
        </p>
        <table class="ap-table">
            <thead>
                <tr>
                    <th scope="col">Group</th>
                    <th scope="col">Items</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($preview['groups'] ?? [] as $group) : ?>
                    <?php if (!is_array($group)) {
                        continue;
                    } ?>
                    <tr>
                        <td><?php echo ap_esc_html((string) ($group['group_label'] ?? $group['group_id'] ?? '')); ?></td>
                        <td><?php echo (int) ($group['item_count'] ?? 0); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>
<?php endif; ?>

<?php
require __DIR__ . '/admin-footer.php';
