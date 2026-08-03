<?php

/**
 * Settings — Modules (Static Pages / Blog / Forum master toggles).
 *
 * Three independent switches. At least one module must remain enabled.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

require_once __DIR__ . '/admin-bootstrap.php';

AP_Admin::requireCapability('manage_options');

AP_Admin::consumeQueryNotice();

$userId = ap_get_current_user_id();
$db = ap_db();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['ap_save_modules'])) {
    $nonce = (string) ($_POST['_ap_nonce'] ?? '');
    if (!ap_check_nonce($nonce, 'ap_settings_modules', $userId > 0 ? $userId : null)) {
        AP_Admin::addNotice('Security check failed. Please try again.', 'error');
    } else {
        $ok = AP_Options::updateModules([
            'static_pages' => isset($_POST['ap_module_static_pages']) ? '1' : '0',
            'blog' => isset($_POST['ap_module_blog']) ? '1' : '0',
            'forum' => isset($_POST['ap_module_forum']) ? '1' : '0',
        ], $db);
        if ($ok) {
            AP_Admin::redirect(AP_Admin::url('options-modules.php', ['message' => 'modules_saved']));
        }
        AP_Admin::addNotice(
            'Could not save modules. At least one of Static Pages, Blog, or Forum must stay enabled.',
            'error'
        );
    }
}

$staticOn = AP_Options::isModuleEnabled('static_pages', $db);
$blogOn = AP_Options::isModuleEnabled('blog', $db);
$forumOn = AP_Options::isModuleEnabled('forum', $db);

$ap_admin_title = 'Modules';
$ap_admin_screen = 'options-modules';
require __DIR__ . '/admin-header.php';
?>
<div class="ap-page-header">
    <h1>Modules</h1>
</div>

<p>
    Enable or disable the three core modules independently. Related admin menus and
    front-end routes follow these toggles. At least one module must remain on.
</p>

<form method="post" action="" class="ap-form ap-form--settings">
    <?php echo ap_nonce_field('ap_settings_modules', '_ap_nonce', false); ?>

    <fieldset class="ap-fieldset">
        <legend>Core modules</legend>

        <p class="ap-module-toggle">
            <label>
                <input type="checkbox" name="ap_module_static_pages" value="1"
                    <?php echo $staticOn ? 'checked' : ''; ?>>
                <strong>Static Pages</strong>
            </label>
            <span class="ap-help">
                Hierarchical pages, page templates, and static front-page options.
                When off, Pages menu is hidden and page routes are unavailable.
            </span>
        </p>

        <p class="ap-module-toggle">
            <label>
                <input type="checkbox" name="ap_module_blog" value="1"
                    <?php echo $blogOn ? 'checked' : ''; ?>>
                <strong>Blog</strong>
            </label>
            <span class="ap-help">
                Posts, categories, tags, comments, and blog-related settings.
                When off, those menus and screens are hidden.
            </span>
        </p>

        <p class="ap-module-toggle">
            <label>
                <input type="checkbox" name="ap_module_forum" value="1"
                    <?php echo $forumOn ? 'checked' : ''; ?>>
                <strong>Forum</strong>
            </label>
            <span class="ap-help">
                Full forum features (categories, topics, replies, moderation).
                Forum admin and front-end appear when this module is enabled.
            </span>
        </p>
    </fieldset>

    <p class="ap-form-actions">
        <button type="submit" name="ap_save_modules" value="1" class="button button-primary">
            Save Changes
        </button>
    </p>
</form>

<?php
require __DIR__ . '/admin-footer.php';
