<?php

/**
 * Create / edit a forum or category (`forum-edit.php`).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

require __DIR__ . '/admin-bootstrap.php';

AP_Admin::requireCapability('manage_forums');

if (!AP_Options::isModuleEnabled('forum')) {
    AP_Admin::denyAccess('The Forum module is disabled. Enable it under Settings → Modules.');
}

$userId = ap_get_current_user_id();
$db = ap_db();
$forumId = max(0, (int) ($_REQUEST['forum'] ?? $_REQUEST['forum_id'] ?? 0));
$forum = $forumId > 0 ? AP_Forum::getForum($forumId, $db) : null;

if ($forumId > 0 && $forum === null) {
    AP_Admin::addNotice('That forum could not be found.', 'error');
    AP_Admin::redirect(AP_Admin::url('forums.php', ['message' => 'not_found']));
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $result = AP_Admin_Forum_Edit::save($_POST, $userId, $db);
    if ($result['ok']) {
        AP_Admin::redirect(AP_Admin::url('forum-edit.php', [
            'forum' => $result['forum_id'],
            'message' => $result['message_key'],
        ]));
    }
    foreach ($result['errors'] as $err) {
        AP_Admin::addNotice($err, 'error');
    }
    if ($result['message_key'] === 'nonce') {
        AP_Admin::addNotice('Security check failed. Please reload and try again.', 'error');
    }
    // Re-bind form from POST on failure.
    $forum = (object) [
        'forum_id' => $forumId,
        'forum_name' => (string) ($_POST['forum_name'] ?? ''),
        'forum_slug' => (string) ($_POST['forum_slug'] ?? ''),
        'forum_desc' => (string) ($_POST['forum_desc'] ?? ''),
        'forum_type' => (string) ($_POST['forum_type'] ?? AP_Forum::FORUM_TYPE_FORUM),
        'forum_status' => (string) ($_POST['forum_status'] ?? AP_Forum::FORUM_STATUS_OPEN),
        'parent_id' => (int) ($_POST['parent_id'] ?? 0),
        'forum_order' => (int) ($_POST['forum_order'] ?? 0),
    ];
}

AP_Admin::consumeQueryNotice();

$ap_admin_title = $forumId > 0 ? 'Edit Forum' : 'Add New Forum';
$ap_admin_screen = 'forums';
$ap_admin_body_class = 'ap-forum-edit-php';

require __DIR__ . '/admin-header.php';
?>
<div class="ap-page-header">
    <h1><?php echo ap_esc_html($ap_admin_title); ?></h1>
    <a class="button" href="<?php echo ap_esc_url(AP_Admin::url('forums.php')); ?>">← All Forums</a>
</div>

<?php echo AP_Admin_Forum_Edit::renderForm($forumId > 0 || isset($_POST['forum_name']) ? $forum : null, $userId, $db); ?>

<?php
require __DIR__ . '/admin-footer.php';
