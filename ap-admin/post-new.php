<?php

/**
 * Add New Post / Page (`post-new.php?post_type=post|page`).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

require __DIR__ . '/admin-bootstrap.php';

$postType = AP_Admin::resolvePostType((string) ($_REQUEST['post_type'] ?? 'post'), 'post');
$userId = ap_get_current_user_id();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $result = AP_Admin_Post_Edit::save($_POST, $userId);
    if ($result['ok'] && $result['id'] > 0) {
        AP_Admin::redirect(AP_Admin::url('post.php', [
            'post' => $result['id'],
            'action' => 'edit',
            'message' => $result['message_key'],
        ]));
    }
    foreach ($result['errors'] as $err) {
        AP_Admin::addNotice($err, 'error');
    }
    if ($result['message_key'] === 'nonce') {
        AP_Admin::addNotice('Security check failed. Please reload and try again.', 'error');
    }
}

$singular = AP_Admin::postTypeLabel($postType, true);
$ap_admin_title = 'Add New ' . $singular;
$ap_admin_screen = $postType === 'page' ? 'pages' : 'posts';
$ap_admin_body_class = 'ap-post-new post-type-' . $postType;

require __DIR__ . '/admin-header.php';
?>
<div class="ap-page-header">
    <h1><?php echo ap_esc_html($ap_admin_title); ?></h1>
    <?php
    $listUrl = AP_Admin::url('edit.php', ['post_type' => $postType]);
    $pluralLabel = AP_Admin::postTypeLabel($postType, false);
    ?>
    <a class="button" href="<?php echo ap_esc_url($listUrl); ?>">
        ← All <?php echo ap_esc_html($pluralLabel); ?>
    </a>
</div>

<?php
// Prefill from failed POST when present.
$prefill = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $prefill = new AP_Post();
    $prefill->post_title = ap_sanitize_text_field((string) ($_POST['post_title'] ?? ''));
    $prefill->post_content = (string) ($_POST['post_content'] ?? '');
    $prefill->post_excerpt = ap_sanitize_textarea_field((string) ($_POST['post_excerpt'] ?? ''));
    $prefill->post_name = ap_sanitize_text_field((string) ($_POST['post_name'] ?? ''));
    $prefill->post_status = (string) ($_POST['post_status'] ?? 'draft');
    $prefill->post_type = $postType;
    $prefill->post_password = (string) ($_POST['post_password'] ?? '');
    $prefill->comment_status = !empty($_POST['comment_status']) ? 'open' : 'closed';
    $prefill->post_parent = (int) ($_POST['post_parent'] ?? 0);
    $prefill->menu_order = (int) ($_POST['menu_order'] ?? 0);
}

echo AP_Admin_Post_Edit::renderForm($prefill, $postType, $userId);

require __DIR__ . '/admin-footer.php';
