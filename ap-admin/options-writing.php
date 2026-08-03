<?php

/**
 * Settings — Writing (default category, comments on new posts).
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

if (AP_Settings::isSaveRequest('writing')) {
    if (!AP_Settings::verifyNonce('writing', $userId > 0 ? $userId : null)) {
        AP_Admin::addNotice('Security check failed. Please try again.', 'error');
    } else {
        $ok = AP_Options::updateWritingSettings($_POST, $db);
        if ($ok) {
            AP_Admin::redirect(AP_Admin::url('options-writing.php', ['message' => 'writing_saved']));
        }
        AP_Admin::addNotice('Could not save writing settings.', 'error');
        AP_Settings::flushErrorsToAdmin();
    }
}

$defaultCat = (int) AP_Options::get('default_category', 0, $db);
$useSmilies = (string) AP_Options::get('use_smilies', '1', $db) === '1';
$defaultComment = (string) AP_Options::get('default_comment_status', 'open', $db);

$categories = [];
if (class_exists('AP_Taxonomy', false)) {
    $categories = AP_Taxonomy::getTerms('category', [
        'hide_empty' => false,
        'orderby' => 'name',
        'order' => 'ASC',
        'number' => 200,
    ], $db);
    if (!is_array($categories)) {
        $categories = [];
    }
}

$ap_admin_title = 'Writing Settings';
$ap_admin_screen = 'options-writing';
require __DIR__ . '/admin-header.php';
?>
<div class="ap-page-header">
    <h1>Writing Settings</h1>
</div>

<p>Defaults for new blog posts.</p>

<form method="post" action="" class="ap-form ap-form--settings">
    <?php AP_Settings::settingsFields('writing'); ?>

    <fieldset class="ap-fieldset">
        <legend>Default post settings</legend>
        <p class="ap-field">
            <label for="default_category">Default Post Category</label>
            <select name="default_category" id="default_category">
                <option value="0">— Uncategorized / site default —</option>
                <?php foreach ($categories as $term) : ?>
                    <?php
                    $tid = is_object($term) ? (int) ($term->term_id ?? 0) : (int) ($term['term_id'] ?? 0);
                    $name = is_object($term) ? (string) ($term->name ?? '') : (string) ($term['name'] ?? '');
                    if ($tid <= 0) {
                        continue;
                    }
                    ?>
                    <option value="<?php echo $tid; ?>"
                        <?php echo $defaultCat === $tid ? 'selected' : ''; ?>>
                        <?php echo ap_esc_html($name); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </p>
        <p>
            <label>
                <input type="checkbox" name="use_smilies" value="1"
                    <?php echo $useSmilies ? 'checked' : ''; ?>>
                Convert emoticons like <code>:-)</code> to graphics in displayed text
            </label>
        </p>
        <p class="ap-field">
            <label for="default_comment_status">Allow comments on new posts</label>
            <select name="default_comment_status" id="default_comment_status">
                <option value="open" <?php echo $defaultComment === 'open' ? 'selected' : ''; ?>>
                    Open
                </option>
                <option value="closed" <?php echo $defaultComment === 'closed' ? 'selected' : ''; ?>>
                    Closed
                </option>
            </select>
        </p>
    </fieldset>

    <?php AP_Settings::submitButton(); ?>
</form>

<?php
require __DIR__ . '/admin-footer.php';
