<?php

/**
 * Single post template.
 *
 * @package Agora
 */

declare(strict_types=1);

AP_Theme::getHeader();

if (function_exists('ap_have_posts') && ap_have_posts()) {
    while (ap_have_posts()) {
        ap_the_post();
        $postId = function_exists('ap_get_the_ID') ? ap_get_the_ID() : 0;
        ?>
        <article class="ap-entry ap-entry--single" <?php echo $postId > 0 ? 'id="post-' . (int) $postId . '"' : ''; ?>>
            <h1 class="ap-entry__title"><?php agora_the_title(); ?></h1>
            <?php
            if (function_exists('agora_the_entry_meta')) {
                agora_the_entry_meta();
            }
            ?>
            <div class="ap-entry__content">
                <?php agora_the_content(); ?>
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
                <?php if ($count === 0) : ?>
                    <p class="ap-entry__excerpt">No comments yet.</p>
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
                            ?>
                            <li class="ap-comment" id="comment-<?php echo $cid; ?>">
                                <div class="ap-comment__meta">
                                    <span class="ap-comment__author"><?php echo function_exists('agora_esc') ? agora_esc($author) : htmlspecialchars($author, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>
                                    <?php if ($date !== '') : ?>
                                        <time datetime="<?php echo function_exists('agora_esc_attr') ? agora_esc_attr($date) : htmlspecialchars($date, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                                            <?php echo function_exists('agora_esc') ? agora_esc($date) : htmlspecialchars($date, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                                        </time>
                                    <?php endif; ?>
                                </div>
                                <div class="ap-comment__body">
                                    <?php
                                    $escaped = function_exists('agora_esc')
                                        ? agora_esc($content)
                                        : htmlspecialchars($content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                                    echo nl2br($escaped, false);
                                    ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                <?php endif; ?>
            </section>
            <?php
        }
    }
} else {
    echo '<div class="ap-not-found" role="status"><p>Post not found.</p></div>';
}

AP_Theme::getFooter();
