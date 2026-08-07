<?php

/**
 * Main template fallback (blog index and anything without a more specific file).
 *
 * @package ZeroShits
 */

declare(strict_types=1);

AP_Theme::getHeader();

if (function_exists('ap_have_posts') && ap_have_posts()) {
    while (ap_have_posts()) {
        ap_the_post();
        $permalink = function_exists('zeroshits_the_permalink') ? zeroshits_the_permalink() : '';
        $excerpt = function_exists('ap_get_the_excerpt') ? ap_get_the_excerpt() : '';
        ?>
        <article class="ap-entry">
            <h2 class="ap-entry__title">
                <?php if ($permalink !== '') : ?>
                    <a href="<?php echo function_exists('zeroshits_esc_url') ? zeroshits_esc_url($permalink) : htmlspecialchars($permalink, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                        <?php zeroshits_the_title(); ?>
                    </a>
                <?php else : ?>
                    <?php zeroshits_the_title(); ?>
                <?php endif; ?>
            </h2>
            <?php
            if (function_exists('zeroshits_the_entry_meta')) {
                zeroshits_the_entry_meta();
            }
            ?>
            <?php if ($excerpt !== '') : ?>
                <div class="ap-entry__excerpt"><?php echo function_exists('ap_esc_html') ? ap_esc_html($excerpt) : htmlspecialchars($excerpt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
                <?php if ($permalink !== '') : ?>
                    <a class="ap-entry__more" href="<?php echo function_exists('zeroshits_esc_url') ? zeroshits_esc_url($permalink) : htmlspecialchars($permalink, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                        Keep scrolling (we still give zero)<span class="screen-reader-text">: <?php zeroshits_the_title(); ?></span>
                    </a>
                <?php endif; ?>
            <?php else : ?>
                <div class="ap-entry__content">
                    <?php zeroshits_the_content(); ?>
                </div>
            <?php endif; ?>
        </article>
        <?php
    }
    if (function_exists('zeroshits_the_posts_pagination')) {
        zeroshits_the_posts_pagination();
    }
} else {
    echo '<div class="ap-empty" role="status"><p>Nothing here but dust bunnies and broken dreams. Post something, coward.</p></div>';
}

AP_Theme::getFooter();
