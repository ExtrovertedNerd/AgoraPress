<?php

/**
 * Tools — Erase Personal Data (GDPR-style).
 *
 * Anonymize a user’s personal identifiers across content and delete their
 * account. Content is retained (posts reassigned or detached; comments become
 * “Deleted User”). The sole administrator cannot be erased.
 *
 * Cap: erase_others_personal_data (administrators; manage_options fallback).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

require_once __DIR__ . '/admin-bootstrap.php';

if (
    !AP_Admin::currentUserCan('erase_others_personal_data')
    && !AP_Admin::currentUserCan('manage_options')
    && !AP_Admin::currentUserCan('delete_users')
) {
    AP_Admin::requireCapability('erase_others_personal_data');
} else {
    AP_Admin::requireLogin();
}

AP_Admin::consumeQueryNotice();

$userId = (int) ap_get_current_user_id();
$db = ap_db();

/** @var array<string, mixed>|null $lastResult */
$lastResult = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = strtolower(trim((string) ($_POST['ap_privacy_action'] ?? '')));
    $nonce = (string) ($_POST['_ap_nonce'] ?? '');
    $identifier = trim((string) ($_POST['user_identifier'] ?? ''));
    $confirm = strtolower(trim((string) ($_POST['confirm_erase'] ?? '')));
    $reassign = (int) ($_POST['reassign'] ?? 0);

    $canErase = AP_Admin::userCan($userId, 'erase_others_personal_data', null, $db)
        || AP_Admin::userCan($userId, 'manage_options', null, $db)
        || AP_Admin::userCan($userId, 'delete_users', null, $db);

    if ($action === 'erase') {
        if (!ap_check_nonce($nonce, 'erase-personal-data', $userId > 0 ? $userId : null)) {
            AP_Admin::addNotice('Security check failed. Please try again.', 'error');
        } elseif (!$canErase) {
            AP_Admin::addNotice('You do not have permission to erase personal data.', 'error');
        } elseif ($identifier === '') {
            AP_Admin::addNotice('Enter a user ID, username, or email address.', 'error');
        } elseif ($confirm !== 'erase') {
            AP_Admin::addNotice('Type “erase” in the confirmation field to proceed.', 'error');
        } else {
            $target = AP_Privacy::resolveUser($identifier, $db);
            if ($target === null) {
                AP_Admin::addNotice('No user matched that identifier.', 'error');
            } elseif ($target->ID === $userId) {
                AP_Admin::addNotice(
                    'You cannot erase your own account from this screen. Ask another administrator.',
                    'error'
                );
            } elseif (AP_Privacy::isSoleAdministrator($target->ID, $db)) {
                AP_Admin::addNotice('Cannot erase the only administrator account.', 'error');
            } else {
                $lastResult = AP_Privacy::erasePersonalData($target->ID, [
                    'reassign' => $reassign > 0 ? $reassign : 0,
                ], $db);

                if (!empty($lastResult['ok'])) {
                    $counts = $lastResult['counts'] ?? [];
                    $parts = [];
                    if ((int) ($counts['posts_reassigned'] ?? 0) > 0) {
                        $parts[] = (int) $counts['posts_reassigned'] . ' post(s) reassigned';
                    }
                    if ((int) ($counts['comments_anonymized'] ?? 0) > 0) {
                        $parts[] = (int) $counts['comments_anonymized'] . ' comment(s) anonymized';
                    }
                    if ((int) ($counts['forum_posts'] ?? 0) > 0) {
                        $parts[] = (int) $counts['forum_posts'] . ' forum post update(s)';
                    }
                    if ((int) ($counts['private_messages'] ?? 0) > 0) {
                        $parts[] = (int) $counts['private_messages'] . ' private message(s) deleted';
                    }
                    $summary = $parts !== []
                        ? 'Personal data erased. ' . implode('; ', $parts) . '.'
                        : 'Personal data erased and the user account was deleted.';
                    AP_Admin::addNotice($summary, 'success');
                    foreach ($lastResult['warnings'] ?? [] as $warn) {
                        if (is_string($warn) && $warn !== '') {
                            AP_Admin::addNotice($warn, 'warning');
                        }
                    }
                } else {
                    $errs = $lastResult['errors'] ?? [];
                    if ($errs === []) {
                        AP_Admin::addNotice('Erase failed.', 'error');
                    } else {
                        foreach ($errs as $err) {
                            AP_Admin::addNotice((string) $err, 'error');
                        }
                    }
                }
            }
        }
    }
}

$ap_admin_title = 'Erase Personal Data';
$ap_admin_screen = 'erase-personal-data';
require __DIR__ . '/admin-header.php';
?>
<div class="ap-page-header">
    <h1>Erase Personal Data</h1>
</div>

<p>
    Permanently remove a user’s personal data and account. Authored content is kept
    for site integrity: posts/pages/media are reassigned (or detached), comments and
    forum posts are anonymized, private messages are deleted.
    This cannot be undone.
</p>
<p>
    Prefer
    <a href="<?php echo ap_esc_url(AP_Admin::url('export-personal-data.php')); ?>">Export Personal Data</a>
    first when fulfilling a data-portability request.
</p>

<form method="post" action="" class="ap-form" onsubmit="return confirm('Erase personal data and delete this user permanently?');">
    <?php echo ap_nonce_field('erase-personal-data', '_ap_nonce', false); ?>
    <input type="hidden" name="ap_privacy_action" value="erase">

    <p class="ap-field">
        <label for="user_identifier">User ID, username, or email</label>
        <input type="text" name="user_identifier" id="user_identifier" class="ap-input"
            value="<?php echo ap_esc_attr((string) ($_POST['user_identifier'] ?? '')); ?>"
            required autocomplete="off">
    </p>

    <p class="ap-field">
        <label for="reassign">Reassign content to user ID (optional)</label>
        <input type="number" name="reassign" id="reassign" class="ap-input" min="0" step="1"
            value="<?php echo (int) ($_POST['reassign'] ?? 0); ?>">
        <span class="ap-help">
            Posts, pages, and attachments authored by the user are reassigned to this ID.
            Leave 0 to detach authorship (post_author = 0).
        </span>
    </p>

    <p class="ap-field">
        <label for="confirm_erase">Type <strong>erase</strong> to confirm</label>
        <input type="text" name="confirm_erase" id="confirm_erase" class="ap-input"
            value="" autocomplete="off" required>
    </p>

    <p class="ap-form-actions">
        <button type="submit" class="ap-button ap-button-danger">
            Erase personal data
        </button>
    </p>
</form>

<?php if (is_array($lastResult) && !empty($lastResult['ok'])) : ?>
    <section class="ap-metabox" aria-labelledby="ap-erase-result-title">
        <h2 id="ap-erase-result-title" class="ap-metabox-title">Erase summary</h2>
        <ul>
            <?php foreach (($lastResult['counts'] ?? []) as $key => $n) : ?>
                <li>
                    <?php echo ap_esc_html((string) $key); ?>:
                    <?php echo (int) $n; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endif; ?>

<?php
require __DIR__ . '/admin-footer.php';
