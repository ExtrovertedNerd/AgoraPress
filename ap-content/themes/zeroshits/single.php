<?php

/**
 * Single post template.
 *
 * @package ZeroShits
 */

declare(strict_types=1);

AP_Theme::getHeader();

if (function_exists('ap_have_posts') && ap_have_posts()) {
    while (ap_have_posts()) {
        ap_the_post();
        $postId = function_exists('ap_get_the_ID') ? ap_get_the_ID() : 0;
        ?>
        <article class="ap-entry ap-entry--single" <?php echo $postId > 0 ? 'id="post-' . (int) $postId . '"' : ''; ?>>
            <h1 class="ap-entry__title"><?php zeroshits_the_title(); ?></h1>
            <?php
            if (function_exists('zeroshits_the_entry_meta')) {
                zeroshits_the_entry_meta();
            }
            ?>
            <div class="ap-entry__content">
                <?php zeroshits_the_content(); ?>
            </div>
        </article>
        <?php
        // Comments scaffold (approved comments when the comment API is available).
        if ($postId > 0 && class_exists('AP_Comment', false)) {
            $comments = AP_Comment::getByPost($postId);
            $count = count($comments);
            ?>
            <section class="ap-comments" id="comments" aria-labelledby="comments-title">
                <h2 class="ap-comments__title" id="comments-title">
                    <?php
                    if ($count === 0) {
                        echo 'Comments';
                    } elseif ($count === 1) {
                        echo '1 comment';
                    } else {
                        echo (int) $count . ' comments';
                    }
                    ?>
                </h2>
                <?php
                $editCommentId = isset($_GET['comment_edit']) ? (int) $_GET['comment_edit'] : 0;
                $viewerId = function_exists('ap_get_current_user_id') ? (int) ap_get_current_user_id() : 0;
                ?>
                <?php if ($count === 0) : ?>
                    <p class="ap-entry__excerpt">No comments yet. Be the first to drop a deuce of wisdom.</p>
                <?php else : ?>
                    <ol class="ap-comment-list">
                        <?php foreach ($comments as $comment) : ?>
                            <?php
                            if (!$comment instanceof AP_Comment) {
                                continue;
                            }
                            $author = (string) ($comment->comment_author ?? 'Guest');
                            $date = (string) ($comment->comment_date ?? '');
                            $content = (string) ($comment->comment_content ?? '');
                            $cid = (int) ($comment->comment_ID ?? 0);
                            $canEdit = $cid > 0 && function_exists('ap_user_can_edit_comment')
                                && ap_user_can_edit_comment($cid, $viewerId);
                            $canDelete = $cid > 0 && function_exists('ap_user_can_delete_comment')
                                && ap_user_can_delete_comment($cid, $viewerId);
                            $isEditing = $editCommentId === $cid && $canEdit;
                            ?>
                            <li class="ap-comment" id="comment-<?php echo $cid; ?>">
                                <div class="ap-comment__meta">
                                    <span class="ap-comment__author"><?php echo function_exists('zeroshits_esc') ? zeroshits_esc($author) : htmlspecialchars($author, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>
                                    <?php if ($date !== '') : ?>
                                        <time datetime="<?php echo function_exists('zeroshits_esc_attr') ? zeroshits_esc_attr($date) : htmlspecialchars($date, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                                            <?php echo function_exists('zeroshits_esc') ? zeroshits_esc($date) : htmlspecialchars($date, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                                        </time>
                                    <?php endif; ?>
                                </div>
                                <?php if ($isEditing) : ?>
                                    <form method="post" action="" class="ap-comment-form__form ap-comment-form__form--edit">
                                        <input type="hidden" name="ap_comment_action" value="ap_comment_edit">
                                        <input type="hidden" name="comment_ID" value="<?php echo (int) $cid; ?>">
                                        <input type="hidden" name="comment_post_ID" value="<?php echo (int) $postId; ?>">
                                        <?php
                                        if (function_exists('ap_nonce_field')) {
                                            echo ap_nonce_field('ap-comment-edit-' . $cid);
                                        }
                                        if (function_exists('ap_editor')) {
                                            echo ap_editor([
                                                'id' => 'agora-comment-edit-' . $cid,
                                                'name' => 'comment',
                                                'value' => $content,
                                                'mode' => class_exists('AP_Editor', false)
                                                    ? AP_Editor::modeForContext('comment')
                                                    : 'visual',
                                                'rows' => 5,
                                                'required' => true,
                                                'label' => 'Edit comment',
                                            ]);
                                        } else {
                                            echo '<label for="agora-comment-edit-' . (int) $cid . '">Edit comment</label>';
                                            echo '<textarea id="agora-comment-edit-' . (int) $cid . '" name="comment" required rows="5">'
                                                . (function_exists('ap_esc_textarea')
                                                    ? ap_esc_textarea($content)
                                                    : htmlspecialchars($content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'))
                                                . '</textarea>';
                                        }
                                        ?>
                                        <p class="ap-comment__actions">
                                            <button type="submit" class="ap-btn">Save</button>
                                            <a class="ap-btn ap-btn--ghost" href="<?php
                                                $cancel = '';
                                                if ($postId > 0 && function_exists('ap_get_permalink') && function_exists('ap_get_post')) {
                                                    $p = ap_get_post($postId);
                                                    if ($p) {
                                                        $cancel = ap_get_permalink($p);
                                                    }
                                                }
                                                echo function_exists('ap_esc_url')
                                                    ? ap_esc_url(($cancel !== '' ? $cancel : '') . '#comment-' . $cid)
                                                    : htmlspecialchars(($cancel !== '' ? $cancel : '') . '#comment-' . $cid, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                                            ?>">Cancel</a>
                                        </p>
                                    </form>
                                <?php else : ?>
                                <div class="ap-comment__body">
                                    <?php
                                    // Format visual HTML / legacy Markdown for safe display.
                                    if (function_exists('ap_format_content')) {
                                        echo ap_format_content($content, ['mode' => 'auto', 'context' => 'comment']);
                                    } else {
                                        $escaped = function_exists('zeroshits_esc')
                                            ? zeroshits_esc($content)
                                            : htmlspecialchars($content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                                        echo nl2br($escaped, false);
                                    }
                                    ?>
                                </div>
                                <?php if ($canEdit || $canDelete) : ?>
                                    <div class="ap-comment__actions row-actions">
                                        <?php if ($canEdit) : ?>
                                            <a href="?comment_edit=<?php echo (int) $cid; ?>#comment-<?php echo (int) $cid; ?>">Edit</a>
                                        <?php endif; ?>
                                        <?php if ($canEdit && $canDelete) : ?> | <?php endif; ?>
                                        <?php if ($canDelete) : ?>
                                            <form method="post" action="" class="ap-comment-delete-form" style="display:inline">
                                                <input type="hidden" name="ap_comment_action" value="ap_comment_delete">
                                                <input type="hidden" name="comment_ID" value="<?php echo (int) $cid; ?>">
                                                <input type="hidden" name="comment_post_ID" value="<?php echo (int) $postId; ?>">
                                                <?php
                                                if (function_exists('ap_nonce_field')) {
                                                    echo ap_nonce_field('ap-comment-delete-' . $cid);
                                                }
                                                ?>
                                                <button type="submit" class="ap-comment-delete-btn submitdelete" style="background:none;border:0;padding:0;color:inherit;cursor:pointer;text-decoration:underline;font:inherit">Delete</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                <?php endif; ?>

                <?php
                // Comment form with classic editor toolbar (when comments are open).
                $commentsOpen = true;
                $currentPost = function_exists('ap_get_post') ? ap_get_post($postId) : null;
                if ($currentPost instanceof AP_Post) {
                    $commentsOpen = ($currentPost->comment_status ?? 'open') === 'open';
                } elseif (isset($GLOBALS['ap_post']) && $GLOBALS['ap_post'] instanceof AP_Post) {
                    $commentsOpen = ($GLOBALS['ap_post']->comment_status ?? 'open') === 'open';
                }
                $loggedIn = $viewerId > 0;
                $requireReg = false;
                if (class_exists('AP_Options', false) && function_exists('ap_db')) {
                    try {
                        $requireReg = (string) AP_Options::get('comment_registration', '0') === '1';
                    } catch (Throwable) {
                        $requireReg = false;
                    }
                }
                $commentError = isset($_GET['comment_error']) ? (string) $_GET['comment_error'] : '';
                $commentOk = isset($_GET['comment_ok']) ? (string) $_GET['comment_ok'] : '';
                if ($commentsOpen) :
                    ?>
                <section class="ap-comment-form" id="respond" aria-labelledby="reply-title">
                    <h3 id="reply-title" class="ap-comments__title">Leave a comment</h3>
                    <?php if ($commentOk !== '') : ?>
                        <p class="ap-entry__excerpt" role="status">
                            <?php
                            echo match ($commentOk) {
                                '1', 'approved' => 'Thank you — your comment has been posted.',
                                'edited' => 'Your comment has been updated.',
                                'deleted' => 'Your comment has been removed.',
                                default => 'Thank you — your comment has been submitted and is awaiting moderation.',
                            };
                            ?>
                        </p>
                    <?php endif; ?>
                    <?php if ($commentError !== '') : ?>
                        <p class="ap-entry__excerpt" role="alert">
                            <?php
                            echo match ($commentError) {
                                'nonce' => 'Security check failed. Please try again.',
                                'empty' => 'Please write a comment before submitting.',
                                'identity' => 'Name and a valid email are required.',
                                'login' => 'You must log in to comment.',
                                'closed' => 'Comments are closed for this post.',
                                'forbidden' => 'You do not have permission to do that.',
                                'server' => 'Something went wrong while saving your comment. Please try again.',
                                default => 'Could not post your comment. Please try again.',
                            };
                            ?>
                        </p>
                    <?php endif; ?>
                    <?php if ($requireReg && !$loggedIn) : ?>
                        <p class="ap-forum__lead">
                            <a href="<?php echo function_exists('ap_esc_url') && function_exists('ap_site_url')
                                ? ap_esc_url(ap_site_url('ap-admin/login.php'))
                                : '/ap-admin/login.php'; ?>">Log in</a>
                            to leave a comment.
                        </p>
                    <?php else : ?>
                        <form method="post" action="" class="ap-comment-form__form">
                            <input type="hidden" name="ap_comment_action" value="ap_comment_post">
                            <input type="hidden" name="comment_post_ID" value="<?php echo (int) $postId; ?>">
                            <input type="hidden" name="comment_parent" value="0">
                            <?php
                            $nonceAction = 'ap-comment-post-' . (int) $postId;
                            if (function_exists('ap_nonce_field')) {
                                echo ap_nonce_field($nonceAction);
                            } elseif (class_exists('AP_Nonce', false)) {
                                echo AP_Nonce::field($nonceAction);
                            }
                            if (!$loggedIn) :
                                ?>
                            <div class="ap-field">
                                <label for="agora-comment-author">Name</label>
                                <input type="text" id="agora-comment-author" name="author" required maxlength="245" autocomplete="name">
                            </div>
                            <div class="ap-field">
                                <label for="agora-comment-email">Email</label>
                                <input type="email" id="agora-comment-email" name="email" required maxlength="100" autocomplete="email">
                            </div>
                            <div class="ap-field">
                                <label for="agora-comment-url">Website <span class="ap-muted">(optional)</span></label>
                                <input type="url" id="agora-comment-url" name="url" maxlength="200" autocomplete="url">
                            </div>
                            <?php endif; ?>
                            <div class="ap-field">
                                <?php
                                if (function_exists('ap_editor')) {
                                    echo ap_editor([
                                        'id' => 'agora-comment-content',
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
                                    echo '<label for="agora-comment-content">Comment</label>';
                                    echo '<textarea id="agora-comment-content" name="comment" required rows="6" '
                                        . 'placeholder="Write your comment…"></textarea>';
                                }
                                ?>
                            </div>
                            <button type="submit" class="ap-btn">Post comment</button>
                        </form>
                    <?php endif; ?>
                </section>
                    <?php
                endif;
                ?>
            </section>
            <?php
        }
    }
} else {
    echo '<div class="ap-not-found" role="status"><p>Post not found.</p></div>';
}

AP_Theme::getFooter();
