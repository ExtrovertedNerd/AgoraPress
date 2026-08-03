<?php

/**
 * Main template fallback (blog index and anything without a more specific file).
 *
 * @package Agora
 */

declare(strict_types=1);

AP_Theme::getHeader();

if (function_exists('ap_have_posts') && ap_have_posts()) {
    while (ap_have_posts()) {
        ap_the_post();
        $permalink = function_exists('agora_the_permalink') ? agora_the_permalink() : '';
        $excerpt = function_exists('ap_get_the_excerpt') ? ap_get_the_excerpt() : '';
        ?>
        <article class="ap-entry">
            <h2 class="ap-entry__title">
                <?php if ($permalink !== '') : ?>
                    <a href="<?php echo function_exists('agora_esc_url') ? agora_esc_url($permalink) : htmlspecialchars($permalink, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                        <?php agora_the_title(); ?>
                    </a>
                <?php else : ?>
                    <?php agora_the_title(); ?>
                <?php endif; ?>
            </h2>
            <?php
            if (function_exists('agora_the_entry_meta')) {
                agora_the_entry_meta();
            }
            ?>
            <?php if ($excerpt !== '') : ?>
                <div class="ap-entry__excerpt"><?php echo function_exists('ap_esc_html') ? ap_esc_html($excerpt) : htmlspecialchars($excerpt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
                <?php if ($permalink !== '') : ?>
                    <a class="ap-entry__more" href="<?php echo function_exists('agora_esc_url') ? agora_esc_url($permalink) : htmlspecialchars($permalink, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                        Continue reading<span class="screen-reader-text">: <?php agora_the_title(); ?></span>
                    </a>
                <?php endif; ?>
            <?php else : ?>
                <div class="ap-entry__content">
                    <?php agora_the_content(); ?>
                </div>
            <?php endif; ?>
        </article>
        <?php
    }
    if (function_exists('agora_the_posts_pagination')) {
        agora_the_posts_pagination();
    }
} else {
    echo '<div class="ap-empty" role="status"><p>No content found.</p></div>';
}

AP_Theme::getFooter();
