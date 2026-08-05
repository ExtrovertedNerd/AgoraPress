<?php

/**
 * Edit attachment details (`media.php?item={id}`).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

require __DIR__ . '/admin-bootstrap.php';

AP_Admin::requireCapability('upload_files');

$userId = ap_get_current_user_id();
$itemId = (int) ($_REQUEST['item'] ?? $_REQUEST['post'] ?? 0);

if (
    ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
    && (string) ($_POST['ap_media_action'] ?? '') === 'save'
) {
    $result = AP_Admin_Media::save($_POST, $userId);
    if ($result['ok']) {
        AP_Admin::redirect(AP_Admin::url('media.php', [
            'item' => $result['id'],
            'message' => $result['message_key'] !== '' ? $result['message_key'] : 'updated',
        ]));
    }
    foreach ($result['errors'] as $err) {
        AP_Admin::addNotice($err, 'error');
    }
    $itemId = $result['id'] > 0 ? $result['id'] : $itemId;
}

AP_Admin::consumeQueryNotice();

$post = $itemId > 0 ? AP_Post::get($itemId) : null;
if ($post === null || $post->post_type !== 'attachment') {
    AP_Admin::addNotice('That media item could not be found.', 'error');
    $ap_admin_title = 'Media';
    $ap_admin_screen = 'media';
    $ap_admin_body_class = 'ap-media-php';
    require __DIR__ . '/admin-header.php';
    echo '<div class="ap-page-header"><h1>Media</h1>';
    echo '<a class="button" href="' . ap_esc_url(AP_Admin::url('upload.php')) . '">← Media Library</a></div>';
    require __DIR__ . '/admin-footer.php';
    exit;
}

$ap_admin_title = $post->post_title !== '' ? $post->post_title : 'Edit Media';
$ap_admin_screen = 'media';
$ap_admin_body_class = 'ap-media-php';

require __DIR__ . '/admin-header.php';
?>
<div class="ap-page-header">
    <h1>Edit Media</h1>
    <a class="button" href="<?php echo ap_esc_url(AP_Admin::url('upload.php')); ?>">← Media Library</a>
</div>

<?php echo AP_Admin_Media::renderEditForm($post, $userId); ?>

<?php
require __DIR__ . '/admin-footer.php';
