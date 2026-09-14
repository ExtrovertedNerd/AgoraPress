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
            <?php
            if (function_exists('agora_the_entry_footer')) {
                agora_the_entry_footer();
            }
            ?>
        </article>
        <?php
        if (function_exists('ap_comments_template')) {
            ap_comments_template();
        }
    }
} else {
    echo '<div class="ap-not-found" role="status"><p>Post not found.</p></div>';
}

AP_Theme::getFooter();
