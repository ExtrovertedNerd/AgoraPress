<?php

/**
 * Settings — Forums (module-gated).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

require_once __DIR__ . '/admin-bootstrap.php';

AP_Admin::requireCapability('manage_options');

if (!AP_Options::isModuleEnabled('forum')) {
    AP_Admin::denyAccess('The Forum module is disabled. Enable it under Settings → Modules.');
}

AP_Admin::consumeQueryNotice();

$userId = ap_get_current_user_id();
$db = ap_db();

if (AP_Settings::isSaveRequest('forums')) {
    if (!AP_Settings::verifyNonce('forums', $userId > 0 ? $userId : null)) {
        AP_Admin::addNotice('Security check failed. Please try again.', 'error');
    } else {
        $ok = AP_Options::updateForumSettings($_POST, $db);
        if ($ok) {
            AP_Admin::redirect(AP_Admin::url('options-forums.php', ['message' => 'forums_saved']));
        }
        AP_Admin::addNotice('Could not save forum settings.', 'error');
        AP_Settings::flushErrorsToAdmin();
    }
}

$topicsPerPage = (int) AP_Options::get('forum_topics_per_page', 20, $db);
$postsPerPage = (int) AP_Options::get('forum_posts_per_page', 15, $db);
$allowGuestView = (string) AP_Options::get('forum_allow_guest_viewing', '1', $db) === '1';
$allowGuestPost = (string) AP_Options::get('forum_allow_guest_posting', '0', $db) === '1';
$pmEnabled = (string) AP_Options::get('forum_private_messaging_enabled', '1', $db) === '1';
$attachEnabled = (string) AP_Options::get('forum_attachments_enabled', '1', $db) === '1';
$attachMaxSize = (int) AP_Options::get('forum_attachment_max_size', 2097152, $db);
$attachTypes = (string) AP_Options::get(
    'forum_attachment_allowed_types',
    'jpg,jpeg,png,gif,webp,pdf,txt,zip',
    $db
);
$floodInterval = (int) AP_Options::get('forum_flood_interval', 30, $db);
$requireApproval = (string) AP_Options::get('forum_posts_require_approval', '0', $db) === '1';
$searchEnabled = (string) AP_Options::get('forum_search_enabled', '1', $db) === '1';
$onlineEnabled = (string) AP_Options::get('forum_online_enabled', '1', $db) === '1';
$unreadEnabled = (string) AP_Options::get('forum_unread_tracking_enabled', '1', $db) === '1';
$signaturesEnabled = (string) AP_Options::get('forum_signatures_enabled', '1', $db) === '1';
$spamBlacklist = (string) AP_Options::get('forum_spam_blacklist', '', $db);
$spamMaxLinks = (int) AP_Options::get('forum_spam_max_links', 5, $db);

$ap_admin_title = 'Forum Settings';
$ap_admin_screen = 'options-forums';
require __DIR__ . '/admin-header.php';
?>
<div class="ap-page-header">
    <h1>Forum Settings</h1>
</div>

<p>Defaults for listing, guests, attachments, flood control, and moderation.</p>

<div class="ap-notice ap-notice--info" style="margin-bottom:1.25rem;">
    <strong>Per-forum visibility &amp; permissions</strong> are set on each forum’s edit screen
    (Forums → Edit): choose Public, Members only, Read only, Moderators only, Administrators only,
    or a custom matrix for Guest / Registered / Moderator / Administrator.
    These rules apply only to forums — not to blog posts or pages
    (those use publish status: published is visible to all).
    <a href="<?php echo ap_esc_url(AP_Admin::url('forums.php')); ?>">Manage forums →</a>
</div>

<form method="post" action="" class="ap-form ap-form--settings">
    <?php AP_Settings::settingsFields('forums'); ?>

    <fieldset class="ap-fieldset">
        <legend>Display</legend>
        <p class="ap-field">
            <label for="forum_topics_per_page">Topics per page</label><br>
            <input type="number" class="small-text" id="forum_topics_per_page" name="forum_topics_per_page"
                min="1" max="100" value="<?php echo (int) $topicsPerPage; ?>">
        </p>
        <p class="ap-field">
            <label for="forum_posts_per_page">Posts per page</label><br>
            <input type="number" class="small-text" id="forum_posts_per_page" name="forum_posts_per_page"
                min="1" max="100" value="<?php echo (int) $postsPerPage; ?>">
        </p>
    </fieldset>

    <fieldset class="ap-fieldset">
        <legend>Guests</legend>
        <p>
            <label>
                <input type="checkbox" name="forum_allow_guest_viewing" value="1"
                    <?php echo $allowGuestView ? 'checked' : ''; ?>>
                Allow guests to view forums
            </label>
        </p>
        <p>
            <label>
                <input type="checkbox" name="forum_allow_guest_posting" value="1"
                    <?php echo $allowGuestPost ? 'checked' : ''; ?>>
                Allow guest posting (when per-forum ACL permits)
            </label>
        </p>
    </fieldset>

    <fieldset class="ap-fieldset">
        <legend>Features</legend>
        <p>
            <label>
                <input type="checkbox" name="forum_private_messaging_enabled" value="1"
                    <?php echo $pmEnabled ? 'checked' : ''; ?>>
                Enable private messaging
            </label>
        </p>
        <p>
            <label>
                <input type="checkbox" name="forum_search_enabled" value="1"
                    <?php echo $searchEnabled ? 'checked' : ''; ?>>
                Enable forum search
            </label>
        </p>
        <p>
            <label>
                <input type="checkbox" name="forum_online_enabled" value="1"
                    <?php echo $onlineEnabled ? 'checked' : ''; ?>>
                Enable who’s online
            </label>
        </p>
        <p>
            <label>
                <input type="checkbox" name="forum_unread_tracking_enabled" value="1"
                    <?php echo $unreadEnabled ? 'checked' : ''; ?>>
                Enable unread tracking
            </label>
        </p>
        <p>
            <label>
                <input type="checkbox" name="forum_signatures_enabled" value="1"
                    <?php echo $signaturesEnabled ? 'checked' : ''; ?>>
                Enable signatures under forum posts
            </label>
            <span class="description" style="display:block;margin-top:0.25rem;">
                When enabled, each user’s profile signature appears at the bottom of their posts
                if they have set one.
            </span>
        </p>
    </fieldset>

    <fieldset class="ap-fieldset">
        <legend>Attachments</legend>
        <p>
            <label>
                <input type="checkbox" name="forum_attachments_enabled" value="1"
                    <?php echo $attachEnabled ? 'checked' : ''; ?>>
                Enable attachments
            </label>
        </p>
        <p class="ap-field">
            <label for="forum_attachment_max_size">Max attachment size (bytes)</label><br>
            <input type="number" class="regular-text" id="forum_attachment_max_size"
                name="forum_attachment_max_size" min="0"
                value="<?php echo (int) $attachMaxSize; ?>">
        </p>
        <p class="ap-field">
            <label for="forum_attachment_allowed_types">Allowed types (comma-separated extensions)</label><br>
            <input type="text" class="regular-text" id="forum_attachment_allowed_types"
                name="forum_attachment_allowed_types"
                value="<?php echo ap_esc_attr($attachTypes); ?>">
        </p>
    </fieldset>

    <fieldset class="ap-fieldset">
        <legend>Moderation &amp; anti-spam</legend>
        <p class="ap-field">
            <label for="forum_flood_interval">Flood control (seconds between posts)</label><br>
            <input type="number" class="small-text" id="forum_flood_interval" name="forum_flood_interval"
                min="0" max="3600" value="<?php echo (int) $floodInterval; ?>">
        </p>
        <p>
            <label>
                <input type="checkbox" name="forum_posts_require_approval" value="1"
                    <?php echo $requireApproval ? 'checked' : ''; ?>>
                New posts require moderator approval
            </label>
        </p>
        <p class="ap-field">
            <label for="forum_spam_max_links">Max links before spam flag</label><br>
            <input type="number" class="small-text" id="forum_spam_max_links" name="forum_spam_max_links"
                min="0" max="100" value="<?php echo (int) $spamMaxLinks; ?>">
        </p>
        <p class="ap-field">
            <label for="forum_spam_blacklist">Spam blacklist (one word/phrase per line)</label><br>
            <textarea class="large-text" id="forum_spam_blacklist" name="forum_spam_blacklist"
                rows="4"><?php echo ap_esc_html($spamBlacklist); ?></textarea>
        </p>
    </fieldset>

    <?php AP_Settings::submitButton('Save Forum Settings'); ?>
</form>

<?php
require __DIR__ . '/admin-footer.php';
