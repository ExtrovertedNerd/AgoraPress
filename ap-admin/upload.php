<?php

/**
 * Media Library (`upload.php`) — grid/list, upload, bulk delete, filters.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

require __DIR__ . '/admin-bootstrap.php';

AP_Admin::requireCapability('upload_files');

$listTable = new AP_Media_List_Table();
$userId = ap_get_current_user_id();

// Single-row delete via GET.
$rowAction = (string) ($_GET['action'] ?? '');
if ($rowAction === 'delete' && isset($_GET['media'])) {
    $result = $listTable->processRowAction($_GET);
    $redirect = AP_Admin::url('upload.php', array_filter([
        'mode' => (string) ($_GET['mode'] ?? '') ?: null,
        'mime_type' => (string) ($_GET['mime_type'] ?? '') ?: null,
        'message' => $result['message_key'] !== ''
            ? $result['message_key']
            : ($result['ok'] ? 'deleted' : 'error'),
    ]));
    AP_Admin::redirect($redirect);
}

// POST: upload or bulk actions.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $mediaAction = (string) ($_POST['ap_media_action'] ?? '');

    if ($mediaAction === 'upload') {
        $files = $_FILES['media_file'] ?? [];
        $result = AP_Admin_Media::processUpload(
            is_array($files) ? $files : [],
            $_POST,
            $userId
        );
        if ($result['ok']) {
            $redirect = AP_Admin::url('upload.php', [
                'message' => $result['message_key'],
                'count' => $result['count'],
            ]);
            AP_Admin::redirect($redirect);
        }
        foreach ($result['errors'] as $err) {
            AP_Admin::addNotice($err, 'error');
        }
    } else {
        $result = $listTable->processBulkAction($_POST);
        if ($result['message_key'] !== '' || $result['ok']) {
            $redirect = AP_Admin::url('upload.php', array_filter([
                'mode' => (string) ($_POST['mode'] ?? $_GET['mode'] ?? '') ?: null,
                'mime_type' => (string) ($_POST['mime_type'] ?? $_GET['mime_type'] ?? '') ?: null,
                'message' => $result['message_key'] !== '' ? $result['message_key'] : 'error',
                'count' => $result['count'] > 0 ? $result['count'] : null,
            ]));
            AP_Admin::redirect($redirect);
        }
        foreach ($result['errors'] as $err) {
            AP_Admin::addNotice($err, 'error');
        }
    }
}

AP_Admin::consumeQueryNotice();
$listTable->prepareItems($_GET);

$ap_admin_title = 'Media Library';
$ap_admin_screen = 'media';
$ap_admin_body_class = 'ap-upload-php';

require __DIR__ . '/admin-header.php';
?>
<div class="ap-page-header">
    <h1>Media Library</h1>
    <a class="button button-primary" href="#ap-media-upload">Add New</a>
    <?php echo $listTable->renderModeToggle(); ?>
</div>

<div id="ap-media-upload">
    <?php echo AP_Admin_Media::renderUploadForm($userId); ?>
</div>

<div class="ap-list-toolbar">
    <?php echo $listTable->renderViews(); ?>
    <div class="ap-list-toolbar-right">
        <?php echo $listTable->renderDateFilter(); ?>
        <?php echo $listTable->renderSearchBox(); ?>
    </div>
</div>

<?php echo $listTable->render(); ?>

<?php
require __DIR__ . '/admin-footer.php';
