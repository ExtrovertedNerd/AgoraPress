<?php

/**
 * Edit Post / Page (`post.php?post=ID&action=edit`).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

require __DIR__ . '/admin-bootstrap.php';

$postId = (int) ($_REQUEST['post'] ?? $_REQUEST['post_ID'] ?? 0);
$userId = ap_get_current_user_id();

if ($postId < 1) {
    AP_Admin::redirect(AP_Admin::url('edit.php', ['message' => 'not_found']));
}

$post = AP_Post::get($postId);
if ($post === null) {
    AP_Admin::redirect(AP_Admin::url('edit.php', ['message' => 'not_found']));
}

$postType = AP_Admin::resolvePostType($post->post_type, $post->post_type);
AP_Admin::requireCapability(
    AP_Admin::editMetaCapForPostType($postType),
    null,
    $postId
);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $result = AP_Admin_Post_Edit::save($_POST, $userId);
    if ($result['ok']) {
        AP_Admin::redirect(AP_Admin::url('post.php', [
            'post' => $result['id'],
            'action' => 'edit',
            'message' => $result['message_key'],
        ]));
    }
    foreach ($result['errors'] as $err) {
        AP_Admin::addNotice($err, 'error');
    }
    // Reload form data from POST on failure.
    $post = $result['post'] ?? $post;
    if ($post !== null && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $post->post_title = ap_sanitize_text_field((string) ($_POST['post_title'] ?? $post->post_title));
        $post->post_content = (string) ($_POST['post_content'] ?? $post->post_content);
        $post->post_excerpt = ap_sanitize_textarea_field((string) ($_POST['post_excerpt'] ?? $post->post_excerpt));
        $post->post_name = ap_sanitize_text_field((string) ($_POST['post_name'] ?? $post->post_name));
        $post->post_status = (string) ($_POST['post_status'] ?? $post->post_status);
        $post->post_password = (string) ($_POST['post_password'] ?? $post->post_password);
        $post->comment_status = !empty($_POST['comment_status']) ? 'open' : 'closed';
        $post->post_parent = (int) ($_POST['post_parent'] ?? $post->post_parent);
        $post->menu_order = (int) ($_POST['menu_order'] ?? $post->menu_order);
    }
}

AP_Admin::consumeQueryNotice();

$singular = AP_Admin::postTypeLabel($postType, true);
$ap_admin_title = 'Edit ' . $singular;
$ap_admin_screen = $postType === 'page' ? 'pages' : 'posts';
$ap_admin_body_class = 'ap-post-php post-type-' . $postType;

require __DIR__ . '/admin-header.php';
?>
<div class="ap-page-header">
    <h1><?php echo ap_esc_html($ap_admin_title); ?></h1>
    <?php
    $listUrl = AP_Admin::url('edit.php', ['post_type' => $postType]);
    $newUrl = AP_Admin::url('post-new.php', ['post_type' => $postType]);
    $pluralLabel = AP_Admin::postTypeLabel($postType, false);
    ?>
    <a class="button" href="<?php echo ap_esc_url($listUrl); ?>">
        ← All <?php echo ap_esc_html($pluralLabel); ?>
    </a>
    <a class="button button-primary" href="<?php echo ap_esc_url($newUrl); ?>">
        Add New
    </a>
</div>

<?php
echo AP_Admin_Post_Edit::renderForm($post, $postType, $userId);

require __DIR__ . '/admin-footer.php';
