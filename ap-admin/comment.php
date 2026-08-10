<?php

/**
 * Edit a single comment (`comment.php?c={id}`).
 *
 * Allowed for users who can `edit_comment` on the row (own with
 * `edit_own_comments`, or any with `moderate_comments`).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

require __DIR__ . '/admin-bootstrap.php';

$db = ap_db();
$userId = (int) ap_get_current_user_id($db);
$commentId = (int) ($_GET['c'] ?? $_GET['comment'] ?? $_POST['comment_ID'] ?? 0);
$comment = $commentId > 0 ? AP_Comment::get($commentId, $db) : null;

if ($comment === null) {
    AP_Admin::addNotice('Comment not found.', 'error');
    AP_Admin::redirect(AP_Admin::url('edit-comments.php'));
}

if (!AP_Roles::userCan($userId, 'edit_comment', $commentId, $db)) {
    AP_Admin::denyAccess('You do not have permission to edit this comment.');
}

// Save.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $nonce = (string) ($_POST['_ap_nonce'] ?? '');
    if (!ap_check_nonce($nonce, 'edit-comment-' . $commentId, $userId)) {
        AP_Admin::addNotice('Security check failed. Please try again.', 'error');
    } else {
        $content = (string) ($_POST['comment_content'] ?? '');
        $author = ap_sanitize_text_field((string) ($_POST['comment_author'] ?? $comment->comment_author));
        $email = ap_sanitize_text_field((string) ($_POST['comment_author_email'] ?? $comment->comment_author_email));
        $url = ap_sanitize_text_field((string) ($_POST['comment_author_url'] ?? $comment->comment_author_url));
        $approved = isset($_POST['comment_approved'])
            ? (string) $_POST['comment_approved']
            : $comment->comment_approved;

        $data = [
            'comment_content' => $content,
            'comment_author' => $author,
            'comment_author_email' => $email,
            'comment_author_url' => $url,
        ];
        // Only moderators may change approval status from this screen.
        if (AP_Roles::userCan($userId, 'moderate_comments', null, $db)) {
            $data['comment_approved'] = $approved;
        }

        $ok = AP_Comment::update($commentId, $data, $db);
        if ($ok) {
            AP_Admin::redirect(AP_Admin::url('comment.php', [
                'c' => $commentId,
                'message' => 'comment_updated',
            ]));
        }
        AP_Admin::addNotice('Could not save the comment.', 'error');
    }
    $comment = AP_Comment::get($commentId, $db) ?? $comment;
}

AP_Admin::consumeQueryNotice();

$postTitle = 'Post #' . $comment->comment_post_ID;
$post = class_exists('AP_Post', false) ? AP_Post::get($comment->comment_post_ID, $db) : null;
if ($post !== null) {
    $postTitle = $post->post_title !== '' ? $post->post_title : '(no title)';
}
$canModerate = AP_Roles::userCan($userId, 'moderate_comments', null, $db);

$ap_admin_title = 'Edit Comment';
$ap_admin_screen = 'comments';
$ap_admin_body_class = 'ap-comment-edit-php';

require __DIR__ . '/admin-header.php';
?>
<div class="ap-page-header">
    <h1>Edit Comment</h1>
    <p class="ap-help">
        In response to
        <a href="<?php echo ap_esc_url(AP_Admin::url('post.php', ['post' => $comment->comment_post_ID, 'action' => 'edit'])); ?>">
            <?php echo ap_esc_html($postTitle); ?>
        </a>
        ·
        <a href="<?php echo ap_esc_url(AP_Admin::url('edit-comments.php')); ?>">← Back to Comments</a>
    </p>
</div>

<form method="post" action="" class="ap-form ap-form--comment-edit">
    <input type="hidden" name="comment_ID" value="<?php echo (int) $commentId; ?>">
    <?php echo ap_nonce_field('edit-comment-' . $commentId); ?>

    <div class="ap-field">
        <label for="comment_author">Author</label>
        <input type="text" id="comment_author" name="comment_author" class="regular-text"
            value="<?php echo ap_esc_attr($comment->comment_author); ?>" maxlength="245">
    </div>
    <div class="ap-field">
        <label for="comment_author_email">Email</label>
        <input type="email" id="comment_author_email" name="comment_author_email" class="regular-text"
            value="<?php echo ap_esc_attr($comment->comment_author_email); ?>" maxlength="100">
    </div>
    <div class="ap-field">
        <label for="comment_author_url">URL</label>
        <input type="url" id="comment_author_url" name="comment_author_url" class="regular-text"
            value="<?php echo ap_esc_attr($comment->comment_author_url); ?>" maxlength="200">
    </div>

    <div class="ap-field ap-field-content">
        <?php
        if (function_exists('ap_editor')) {
            echo ap_editor([
                'id' => 'comment_content',
                'name' => 'comment_content',
                'value' => $comment->comment_content,
                'mode' => class_exists('AP_Editor', false)
                    ? AP_Editor::modeForContext('comment')
                    : 'visual',
                'rows' => 10,
                'required' => true,
                'label' => 'Comment',
            ]);
        } else {
            echo '<label for="comment_content">Comment</label>';
            echo '<textarea id="comment_content" name="comment_content" rows="10" required class="large-text">'
                . ap_esc_textarea($comment->comment_content) . '</textarea>';
        }
        ?>
    </div>

    <?php if ($canModerate) : ?>
        <div class="ap-field">
            <label for="comment_approved">Status</label>
            <select name="comment_approved" id="comment_approved">
                <?php
                $statuses = [
                    AP_Comment::STATUS_APPROVED => 'Approved',
                    AP_Comment::STATUS_HOLD => 'Pending',
                    AP_Comment::STATUS_SPAM => 'Spam',
                    AP_Comment::STATUS_TRASH => 'Trash',
                ];
                foreach ($statuses as $val => $label) :
                    $sel = ((string) $comment->comment_approved === (string) $val) ? ' selected' : '';
                    ?>
                    <option value="<?php echo ap_esc_attr((string) $val); ?>"<?php echo $sel; ?>>
                        <?php echo ap_esc_html($label); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    <?php endif; ?>

    <p class="ap-submit">
        <button type="submit" class="button button-primary">Update Comment</button>
        <a class="button" href="<?php echo ap_esc_url(AP_Admin::url('edit-comments.php')); ?>">Cancel</a>
    </p>
</form>
<?php
require __DIR__ . '/admin-footer.php';
