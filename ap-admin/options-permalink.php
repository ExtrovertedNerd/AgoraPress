<?php

/**
 * Settings — Permalinks (URL structure + category/tag bases).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

require_once __DIR__ . '/admin-bootstrap.php';

AP_Admin::requireCapability('manage_options');

AP_Admin::consumeQueryNotice();

$userId = ap_get_current_user_id();
$db = ap_db();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['ap_save_permalink'])) {
    $nonce = (string) ($_POST['_ap_nonce'] ?? '');
    if (!ap_check_nonce($nonce, 'ap_settings_permalink', $userId > 0 ? $userId : null)) {
        AP_Admin::addNotice('Security check failed. Please try again.', 'error');
    } else {
        $structure = (string) ($_POST['permalink_structure'] ?? '');
        // Radio selection for common presets.
        $selection = (string) ($_POST['selection'] ?? 'custom');
        if ($selection !== 'custom') {
            $structure = $selection;
        }
        $ok = AP_Options::updatePermalinkSettings([
            'permalink_structure' => $structure,
            'category_base' => (string) ($_POST['category_base'] ?? ''),
            'tag_base' => (string) ($_POST['tag_base'] ?? ''),
        ], $db);
        if ($ok) {
            AP_Admin::redirect(AP_Admin::url('options-permalink.php', ['message' => 'permalink_saved']));
        }
        AP_Admin::addNotice('Could not save permalink settings.', 'error');
    }
}

$structure = class_exists('AP_Rewrite', false)
    ? AP_Rewrite::getStructure($db)
    : (string) AP_Options::get('permalink_structure', '', $db);
$categoryBase = (string) AP_Options::get('category_base', '', $db);
$tagBase = (string) AP_Options::get('tag_base', '', $db);

$common = class_exists('AP_Rewrite', false)
    ? AP_Rewrite::commonStructures()
    : [
        'Plain' => '',
        'Day and name' => '/%year%/%monthnum%/%day%/%postname%/',
        'Month and name' => '/%year%/%monthnum%/%postname%/',
        'Numeric' => '/archives/%post_id%',
        'Post name' => '/%postname%/',
    ];

// Which radio is selected?
$selected = 'custom';
foreach ($common as $label => $struct) {
    if ($structure === $struct) {
        $selected = $struct;
        break;
    }
}

$ap_admin_title = 'Permalink Settings';
$ap_admin_screen = 'options-permalink';
require __DIR__ . '/admin-header.php';
?>
<div class="ap-page-header">
    <h1>Permalink Settings</h1>
</div>

<p>
    Choose how permanent links to your posts and pages look. Pretty permalinks
    require URL rewriting on the web server (see <code>.htaccess</code> or Nginx examples).
</p>

<form method="post" action="" class="ap-form ap-form--settings">
    <?php echo ap_nonce_field('ap_settings_permalink', '_ap_nonce', false); ?>

    <fieldset class="ap-fieldset">
        <legend>Common settings</legend>
        <?php foreach ($common as $label => $struct) : ?>
            <?php
            $value = $struct;
            $isSelected = $selected === $struct;
            $preview = $struct === ''
                ? '?p=123'
                : str_replace(
                    [
                        '%year%',
                        '%monthnum%',
                        '%day%',
                        '%postname%',
                        '%post_id%',
                        '%category%',
                        '%author%',
                    ],
                    ['2026', '08', '03', 'sample-post', '123', 'news', 'alice'],
                    $struct
                );
            ?>
            <p>
                <label>
                    <input type="radio" name="selection"
                        value="<?php echo ap_esc_attr($value); ?>"
                        <?php echo $isSelected ? 'checked' : ''; ?>
                        onchange="if(this.value!=='custom'){
                            document.getElementById('permalink_structure').value=this.value;
                        }">
                    <strong><?php echo ap_esc_html($label); ?></strong>
                    <code class="ap-muted"><?php echo ap_esc_html($preview); ?></code>
                </label>
            </p>
        <?php endforeach; ?>
        <p>
            <label>
                <input type="radio" name="selection" value="custom"
                    <?php echo $selected === 'custom' ? 'checked' : ''; ?>>
                <strong>Custom structure</strong>
            </label>
            <input type="text" name="permalink_structure" id="permalink_structure"
                class="regular-text" value="<?php echo ap_esc_attr($structure); ?>"
                placeholder="/%postname%/">
        </p>
        <p class="ap-help">
            Available tags:
            <code>%year%</code> <code>%monthnum%</code> <code>%day%</code>
            <code>%post_id%</code> <code>%postname%</code>
            <code>%category%</code> <code>%author%</code>
        </p>
    </fieldset>

    <fieldset class="ap-fieldset">
        <legend>Optional</legend>
        <p class="ap-field">
            <label for="category_base">Category base</label>
            <input type="text" name="category_base" id="category_base" class="regular-text"
                value="<?php echo ap_esc_attr($categoryBase); ?>" placeholder="category">
            <span class="ap-help">Leave empty for the default <code>category</code>.</span>
        </p>
        <p class="ap-field">
            <label for="tag_base">Tag base</label>
            <input type="text" name="tag_base" id="tag_base" class="regular-text"
                value="<?php echo ap_esc_attr($tagBase); ?>" placeholder="tag">
            <span class="ap-help">Leave empty for the default <code>tag</code>.</span>
        </p>
    </fieldset>

    <p class="ap-form-actions">
        <button type="submit" name="ap_save_permalink" value="1" class="button button-primary">
            Save Changes
        </button>
    </p>
</form>

<?php
require __DIR__ . '/admin-footer.php';
