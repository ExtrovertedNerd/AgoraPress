<?php

/**
 * Settings — Discussion (comments + avatars).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

require_once __DIR__ . '/admin-bootstrap.php';

AP_Admin::requireCapability('manage_options');

if (!AP_Options::isModuleEnabled('blog')) {
    AP_Admin::denyAccess('The Blog module is disabled. Enable it under Settings → Modules.');
}

AP_Admin::consumeQueryNotice();

$userId = ap_get_current_user_id();
$db = ap_db();

if (AP_Settings::isSaveRequest('discussion')) {
    if (!AP_Settings::verifyNonce('discussion', $userId > 0 ? $userId : null)) {
        AP_Admin::addNotice('Security check failed. Please try again.', 'error');
    } else {
        $ok = AP_Options::updateDiscussionSettings($_POST, $db);
        if ($ok) {
            AP_Admin::redirect(AP_Admin::url('options-discussion.php', ['message' => 'discussion_saved']));
        }
        AP_Admin::addNotice('Could not save discussion settings.', 'error');
        AP_Settings::flushErrorsToAdmin();
    }
}

$defaultComment = (string) AP_Options::get('default_comment_status', 'open', $db);
$requireNameEmail = (string) AP_Options::get('require_name_email', '1', $db) === '1';
$moderation = (string) AP_Options::get('comment_moderation', '0', $db) === '1';
$commentReg = (string) AP_Options::get('comment_registration', '0', $db) === '1';
$closeOld = (string) AP_Options::get('close_comments_for_old_posts', '0', $db) === '1';
$closeDays = (int) AP_Options::get('close_comments_days_old', 14, $db);
$thread = (string) AP_Options::get('thread_comments', '1', $db) === '1';
$depth = (int) AP_Options::get('thread_comments_depth', 5, $db);
$showAvatars = (string) AP_Options::get('show_avatars', '1', $db) === '1';
$avatarDefault = (string) AP_Options::get('avatar_default', 'mystery', $db);
$avatarRating = (string) AP_Options::get('avatar_rating', 'g', $db);

$avatarDefaults = [
    'mystery' => 'Mystery person',
    'blank' => 'Blank',
    'identicon' => 'Identicon',
    'mp' => 'Mystery person (Gravatar)',
    'retro' => 'Retro',
    'robohash' => 'Robohash',
    'wavatar' => 'Wavatar',
    'monsterid' => 'MonsterID',
];
$ratings = [
    'g' => 'G — suitable for all audiences',
    'pg' => 'PG — possibly offensive',
    'r' => 'R — intended for adult audiences',
    'x' => 'X — even more mature',
];

$ap_admin_title = 'Discussion Settings';
$ap_admin_screen = 'options-discussion';
require __DIR__ . '/admin-header.php';
?>
<div class="ap-page-header">
    <h1>Discussion Settings</h1>
</div>

<p>Default comment behaviour and avatar display.</p>

<form method="post" action="" class="ap-form ap-form--settings">
    <?php AP_Settings::settingsFields('discussion'); ?>

    <fieldset class="ap-fieldset">
        <legend>Default article settings</legend>
        <input type="hidden" name="default_comment_status" value="closed">
        <p>
            <label>
                <input type="checkbox" name="default_comment_status" value="open"
                    <?php echo $defaultComment === 'open' ? 'checked' : ''; ?>>
                Allow people to submit comments on new posts
            </label>
        </p>
        <p class="ap-help">
            Uncheck to close comments by default on new posts. Existing posts keep their own setting.
        </p>
    </fieldset>

    <fieldset class="ap-fieldset">
        <legend>Other comment settings</legend>
        <p>
            <label>
                <input type="checkbox" name="require_name_email" value="1"
                    <?php echo $requireNameEmail ? 'checked' : ''; ?>>
                Comment author must fill out name and email
            </label>
        </p>
        <p>
            <label>
                <input type="checkbox" name="comment_registration" value="1"
                    <?php echo $commentReg ? 'checked' : ''; ?>>
                Users must be registered and logged in to comment
            </label>
        </p>
        <p>
            <label>
                <input type="checkbox" name="close_comments_for_old_posts" value="1"
                    <?php echo $closeOld ? 'checked' : ''; ?>>
                Automatically close comments on posts older than
            </label>
            <input type="number" name="close_comments_days_old" min="0" max="3650"
                value="<?php echo (int) $closeDays; ?>" class="small-text"> days
        </p>
        <p>
            <label>
                <input type="checkbox" name="thread_comments" value="1"
                    <?php echo $thread ? 'checked' : ''; ?>>
                Enable threaded (nested) comments
            </label>
            depth
            <input type="number" name="thread_comments_depth" min="1" max="10"
                value="<?php echo (int) $depth; ?>" class="small-text">
        </p>
        <p>
            <label>
                <input type="checkbox" name="comment_moderation" value="1"
                    <?php echo $moderation ? 'checked' : ''; ?>>
                Comment must be manually approved
            </label>
        </p>
    </fieldset>

    <fieldset class="ap-fieldset">
        <legend>Avatars</legend>
        <p>
            <label>
                <input type="checkbox" name="show_avatars" value="1"
                    <?php echo $showAvatars ? 'checked' : ''; ?>>
                Show Avatars
            </label>
        </p>
        <p class="ap-field">
            <label for="avatar_default">Default Avatar</label>
            <select name="avatar_default" id="avatar_default">
                <?php foreach ($avatarDefaults as $slug => $label) : ?>
                    <option value="<?php echo ap_esc_attr($slug); ?>"
                        <?php echo $avatarDefault === $slug ? 'selected' : ''; ?>>
                        <?php echo ap_esc_html($label); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </p>
        <p class="ap-field">
            <label for="avatar_rating">Maximum Rating</label>
            <select name="avatar_rating" id="avatar_rating">
                <?php foreach ($ratings as $slug => $label) : ?>
                    <option value="<?php echo ap_esc_attr($slug); ?>"
                        <?php echo $avatarRating === $slug ? 'selected' : ''; ?>>
                        <?php echo ap_esc_html($label); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </p>
    </fieldset>

    <?php AP_Settings::submitButton(); ?>
</form>

<?php
require __DIR__ . '/admin-footer.php';
