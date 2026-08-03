<?php

/**
 * Post / page revision history (`revision.php?post=ID`).
 *
 * Lists revisions + autosaves; supports restore and delete row actions.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

require __DIR__ . '/admin-bootstrap.php';

$postId = (int) ($_REQUEST['post'] ?? 0);
$userId = ap_get_current_user_id();
$action = (string) ($_REQUEST['action'] ?? '');

if ($postId < 1) {
    AP_Admin::redirect(AP_Admin::url('edit.php', ['message' => 'not_found']));
}

$parent = AP_Post::get($postId);
if ($parent === null || $parent->post_type === 'revision') {
    AP_Admin::redirect(AP_Admin::url('edit.php', ['message' => 'not_found']));
}

$postType = AP_Admin::resolvePostType($parent->post_type, $parent->post_type);

if (!AP_Post::typeSupports($postType, 'revisions')) {
    AP_Admin::redirect(AP_Admin::url('post.php', [
        'post' => $postId,
        'action' => 'edit',
        'message' => 'error',
    ]));
}

if ($action === 'restore') {
    $result = AP_Admin_Post_Edit::processRestoreRevision($_REQUEST);
    $targetId = $result['parent_id'] > 0 ? $result['parent_id'] : $postId;
    if ($result['ok']) {
        AP_Admin::redirect(AP_Admin::url('post.php', [
            'post' => $targetId,
            'action' => 'edit',
            'message' => $result['message_key'],
        ]));
    }
    foreach ($result['errors'] as $err) {
        AP_Admin::addNotice($err, 'error');
    }
    if ($result['message_key'] === 'nonce') {
        AP_Admin::addNotice('Security check failed.', 'error');
    }
}

if ($action === 'delete') {
    $result = AP_Admin_Post_Edit::processDeleteRevision($_REQUEST);
    $targetId = $result['parent_id'] > 0 ? $result['parent_id'] : $postId;
    if ($result['ok']) {
        AP_Admin::redirect(AP_Admin::url('revision.php', [
            'post' => $targetId,
            'message' => $result['message_key'],
        ]));
    }
    foreach ($result['errors'] as $err) {
        AP_Admin::addNotice($err, 'error');
    }
    if ($result['message_key'] === 'nonce') {
        AP_Admin::addNotice('Security check failed.', 'error');
    }
}

AP_Admin::consumeQueryNotice();

$singular = AP_Admin::postTypeLabel($postType, true);
$ap_admin_title = 'Revisions: ' . ($parent->post_title !== '' ? $parent->post_title : '(no title)');
$ap_admin_screen = $postType === 'page' ? 'pages' : 'posts';
$ap_admin_body_class = 'ap-revision-php post-type-' . $postType;

require __DIR__ . '/admin-header.php';
?>
<div class="ap-page-header">
    <h1><?php echo ap_esc_html($ap_admin_title); ?></h1>
    <?php
    $editUrl = AP_Admin::url('post.php', ['post' => $postId, 'action' => 'edit']);
    $listUrl = AP_Admin::url('edit.php', ['post_type' => $postType]);
    ?>
    <a class="button" href="<?php echo ap_esc_url($editUrl); ?>">
        ← Edit <?php echo ap_esc_html($singular); ?>
    </a>
    <a class="button" href="<?php echo ap_esc_url($listUrl); ?>">
        All <?php echo ap_esc_html(AP_Admin::postTypeLabel($postType, false)); ?>
    </a>
</div>

<p class="description">
    Revisions capture title, content, and excerpt when you update this
    <?php echo ap_esc_html(strtolower($singular)); ?>.
    Autosaves store a private draft for your account without changing the live post.
</p>

<?php
echo AP_Admin_Post_Edit::renderRevisionsList($parent, $userId);

require __DIR__ . '/admin-footer.php';
