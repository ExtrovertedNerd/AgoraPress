<?php

/**
 * Core comments.php fallback.
 *
 * Loaded by {@see ap_comments_template()} when no explicit file is readable
 * and the active theme (child then parent) does not ship comments.php.
 *
 * Surfaces: approved list, closed copy, log-in-to-comment, Leave-a-comment
 * form with {@see AP_Editor}. Posts through the same handler Agora uses
 * (`ap_comment_action` → {@see ap_handle_comment_form_post()}).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

$postId = function_exists('ap_get_the_ID') ? ap_get_the_ID() : 0;
$post = null;
if ($postId > 0 && function_exists('ap_get_post')) {
    $post = ap_get_post($postId);
}
if (
    !$post instanceof AP_Post
    && isset($GLOBALS['ap_post'])
    && $GLOBALS['ap_post'] instanceof AP_Post
) {
    $post = $GLOBALS['ap_post'];
    $postId = (int) $post->ID;
}

$comments = [];
if ($postId > 0 && class_exists('AP_Comment', false)) {
    $comments = AP_Comment::getByPost($postId);
}
$count = count($comments);

$commentsOpen = $post instanceof AP_Post
    && $post->comment_status === 'open';

$viewerId = function_exists('ap_get_current_user_id')
    ? (int) ap_get_current_user_id()
    : 0;
$loggedIn = $viewerId > 0;
$requireReg = false;
if (class_exists('AP_Options', false)) {
    try {
        $requireReg = (string) AP_Options::get('comment_registration', '0') === '1';
    } catch (Throwable) {
        $requireReg = false;
    }
}

$escHtml = static function (string $text): string {
    return function_exists('ap_esc_html')
        ? ap_esc_html($text)
        : htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};
$escAttr = static function (string $text): string {
    return function_exists('ap_esc_attr')
        ? ap_esc_attr($text)
        : htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};

$loginUrl = '/ap-admin/login.php';
if (function_exists('ap_site_url')) {
    $loginUrl = ap_site_url('ap-admin/login.php');
}
$loginHref = function_exists('ap_esc_url')
    ? ap_esc_url($loginUrl)
    : htmlspecialchars($loginUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

if ($count === 0) {
    $listTitle = 'Comments';
} elseif ($count === 1) {
    $listTitle = '1 comment';
} else {
    $listTitle = (string) $count . ' comments';
}

$commentError = isset($_GET['comment_error']) ? (string) $_GET['comment_error'] : '';
$commentOk = isset($_GET['comment_ok']) ? (string) $_GET['comment_ok'] : '';

$okMessage = match ($commentOk) {
    '1', 'approved' => 'Thank you — your comment has been posted.',
    'edited' => 'Your comment has been updated.',
    'deleted' => 'Your comment has been removed.',
    default => 'Thank you — your comment has been submitted and is awaiting moderation.',
};
$errorMessage = match ($commentError) {
    'nonce' => 'Security check failed. Please try again.',
    'empty' => 'Please write a comment before submitting.',
    'identity' => 'Name and a valid email are required.',
    'login' => 'You must log in to comment.',
    'closed' => 'Comments are closed for this post.',
    'forbidden' => 'You do not have permission to do that.',
    'server' => 'Something went wrong while saving your comment. Please try again.',
    default => 'Could not post your comment. Please try again.',
};

$showLogin = $commentsOpen && $requireReg && !$loggedIn;
$showForm = $commentsOpen && !$showLogin && $postId > 0;
?>
<section class="ap-comments ap-comments--compat" id="comments"
    aria-labelledby="comments-title">
    <h2 class="ap-comments__title" id="comments-title"><?php
        echo $escHtml($listTitle);
    ?></h2>
    <?php if ($postId > 0 && $count === 0) { ?>
        <p class="ap-comments__empty">No comments yet.</p>
    <?php } elseif ($count > 0) { ?>
        <ol class="ap-comment-list">
            <?php foreach ($comments as $comment) { ?>
                <?php
                if (!$comment instanceof AP_Comment) {
                    continue;
                }
                $author = $comment->comment_author;
                $date = $comment->comment_date;
                $content = $comment->comment_content;
                $cid = $comment->comment_ID;
                ?>
                <li class="ap-comment" id="comment-<?php echo $cid; ?>">
                    <div class="ap-comment__meta">
                        <span class="ap-comment__author"><?php
                            echo $escHtml($author !== '' ? $author : 'Guest');
                        ?></span>
                        <?php if ($date !== '') { ?>
                            <time datetime="<?php echo $escAttr($date); ?>"><?php
                                echo $escHtml($date);
                            ?></time>
                        <?php } ?>
                    </div>
                    <div class="ap-comment__body">
                        <?php
                        if (function_exists('ap_format_content')) {
                            echo ap_format_content($content, [
                                'mode' => 'auto',
                                'context' => 'comment',
                            ]);
                        } else {
                            echo nl2br($escHtml($content), false);
                        }
                        ?>
                    </div>
                </li>
            <?php } ?>
        </ol>
    <?php } ?>

    <?php if ($postId > 0 && !$commentsOpen) { ?>
        <p class="ap-comments__closed">Comments are closed.</p>
    <?php } ?>

    <?php if ($commentsOpen) { ?>
        <section class="ap-comment-form" id="respond" aria-labelledby="reply-title">
            <h3 id="reply-title" class="ap-comments__title">Leave a comment</h3>
            <?php if ($commentOk !== '') { ?>
                <p class="ap-comments__notice" role="status"><?php
                    echo $escHtml($okMessage);
                ?></p>
            <?php } ?>
            <?php if ($commentError !== '') { ?>
                <p class="ap-comments__notice" role="alert"><?php
                    echo $escHtml($errorMessage);
                ?></p>
            <?php } ?>
            <?php if ($showLogin) { ?>
                <p class="ap-comments__login">
                    <a href="<?php echo $loginHref; ?>">Log in</a>
                    to leave a comment.
                </p>
            <?php } elseif ($showForm) { ?>
                <form method="post" action="" class="ap-comment-form__form">
                    <input type="hidden" name="ap_comment_action"
                        value="ap_comment_post">
                    <input type="hidden" name="comment_post_ID"
                        value="<?php echo (int) $postId; ?>">
                    <input type="hidden" name="comment_parent" value="0">
                    <?php
                    $nonceAction = 'ap-comment-post-' . (int) $postId;
                    if (
                        function_exists('ap_nonce_field')
                        && class_exists('AP_Nonce', false)
                    ) {
                        echo ap_nonce_field($nonceAction);
                    }
                    if (!$loggedIn) {
                        ?>
                    <div class="ap-field">
                        <label for="ap-comment-author">Name</label>
                        <input type="text" id="ap-comment-author" name="author"
                            required maxlength="245" autocomplete="name">
                    </div>
                    <div class="ap-field">
                        <label for="ap-comment-email">Email</label>
                        <input type="email" id="ap-comment-email" name="email"
                            required maxlength="100" autocomplete="email">
                    </div>
                    <div class="ap-field">
                        <label for="ap-comment-url">Website
                            <span class="ap-muted">(optional)</span></label>
                        <input type="url" id="ap-comment-url" name="url"
                            maxlength="200" autocomplete="url">
                    </div>
                        <?php
                    }
                    ?>
                    <div class="ap-field">
                        <?php
                        if (function_exists('ap_editor')) {
                            echo ap_editor([
                                'id' => 'ap-comment-content',
                                'name' => 'comment',
                                'mode' => class_exists('AP_Editor', false)
                                    ? AP_Editor::modeForContext('comment')
                                    : 'visual',
                                'rows' => 6,
                                'required' => true,
                                'label' => 'Comment',
                                'placeholder' => 'Write your comment…',
                                'class' => '',
                            ]);
                        } else {
                            echo '<label for="ap-comment-content">Comment</label>';
                            echo '<textarea id="ap-comment-content" name="comment"'
                                . ' required rows="6"'
                                . ' placeholder="Write your comment…"></textarea>';
                        }
                        ?>
                    </div>
                    <button type="submit" class="ap-btn">Post comment</button>
                </form>
            <?php } ?>
        </section>
    <?php } ?>
</section>
