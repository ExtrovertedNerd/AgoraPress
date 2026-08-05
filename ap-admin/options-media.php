<?php

/**
 * Settings — Media (image sizes + upload organization).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

require_once __DIR__ . '/admin-bootstrap.php';

AP_Admin::requireCapability('manage_options');

AP_Admin::consumeQueryNotice();

$userId = ap_get_current_user_id();
$db = ap_db();

if (AP_Settings::isSaveRequest('media')) {
    if (!AP_Settings::verifyNonce('media', $userId > 0 ? $userId : null)) {
        AP_Admin::addNotice('Security check failed. Please try again.', 'error');
    } else {
        $ok = AP_Options::updateMediaSettings($_POST, $db);
        if ($ok) {
            AP_Admin::redirect(AP_Admin::url('options-media.php', ['message' => 'media_saved']));
        }
        AP_Admin::addNotice('Could not save media settings.', 'error');
        AP_Settings::flushErrorsToAdmin();
    }
}

$thumbW = (int) AP_Options::get('thumbnail_size_w', 150, $db);
$thumbH = (int) AP_Options::get('thumbnail_size_h', 150, $db);
$thumbCrop = (string) AP_Options::get('thumbnail_crop', '1', $db) === '1';
$medW = (int) AP_Options::get('medium_size_w', 300, $db);
$medH = (int) AP_Options::get('medium_size_h', 300, $db);
$largeW = (int) AP_Options::get('large_size_w', 1024, $db);
$largeH = (int) AP_Options::get('large_size_h', 1024, $db);
$maxDisplayW = (int) AP_Options::get('max_image_display_width', 1200, $db);
$organize = (string) AP_Options::get('uploads_use_yearmonth_folders', '1', $db) === '1';

$maxUpload = class_exists('AP_Media', false) ? AP_Media::maxUploadBytes() : 0;
$maxLabel = $maxUpload > 0
    ? (round($maxUpload / 1048576, 1) . ' MiB')
    : 'unknown';

$ap_admin_title = 'Media Settings';
$ap_admin_screen = 'options-media';
require __DIR__ . '/admin-header.php';
?>
<div class="ap-page-header">
    <h1>Media Settings</h1>
</div>

<p>Image size defaults and upload folder organization.</p>

<form method="post" action="" class="ap-form ap-form--settings">
    <?php AP_Settings::settingsFields('media'); ?>

    <fieldset class="ap-fieldset">
        <legend>Image sizes</legend>
        <p class="ap-help">
            The sizes listed below determine the maximum dimensions for images
            generated from uploads. Set a dimension to <code>0</code> to skip
            that size when image processing is available.
        </p>

        <p class="ap-field">
            <label>Thumbnail size</label>
            Width
            <input type="number" name="thumbnail_size_w" min="0" max="10000"
                value="<?php echo (int) $thumbW; ?>" class="small-text">
            Height
            <input type="number" name="thumbnail_size_h" min="0" max="10000"
                value="<?php echo (int) $thumbH; ?>" class="small-text">
            <label class="ap-inline-option">
                <input type="checkbox" name="thumbnail_crop" value="1"
                    <?php echo $thumbCrop ? 'checked' : ''; ?>>
                Crop thumbnail to exact dimensions
            </label>
        </p>

        <p class="ap-field">
            <label>Medium size</label>
            Max Width
            <input type="number" name="medium_size_w" min="0" max="10000"
                value="<?php echo (int) $medW; ?>" class="small-text">
            Max Height
            <input type="number" name="medium_size_h" min="0" max="10000"
                value="<?php echo (int) $medH; ?>" class="small-text">
        </p>

        <p class="ap-field">
            <label>Large size</label>
            Max Width
            <input type="number" name="large_size_w" min="0" max="10000"
                value="<?php echo (int) $largeW; ?>" class="small-text">
            Max Height
            <input type="number" name="large_size_h" min="0" max="10000"
                value="<?php echo (int) $largeH; ?>" class="small-text">
        </p>

        <p class="ap-field">
            <label for="max_image_display_width">Max display width</label>
            <input type="number" name="max_image_display_width" id="max_image_display_width"
                min="0" max="10000" value="<?php echo (int) $maxDisplayW; ?>" class="small-text">
            px
            <span class="description">
                CSS cap so content images never blow out the layout
                (<code>max-width: min(100%, Npx)</code>). Set to <code>0</code> for
                full-width only (<code>max-width: 100%</code>).
            </span>
        </p>
    </fieldset>

    <fieldset class="ap-fieldset">
        <legend>Uploading files</legend>
        <p>
            <label>
                <input type="checkbox" name="uploads_use_yearmonth_folders" value="1"
                    <?php echo $organize ? 'checked' : ''; ?>>
                Organize my uploads into month- and year-based folders
            </label>
        </p>
        <p class="ap-help">
            Maximum upload size for this server: <strong><?php echo ap_esc_html($maxLabel); ?></strong>
            (limited by PHP <code>upload_max_filesize</code> / <code>post_max_size</code>).
        </p>
    </fieldset>

    <?php AP_Settings::submitButton(); ?>
</form>

<?php
require __DIR__ . '/admin-footer.php';
