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
        $date = function_exists('agora_the_date') ? agora_the_date() : '';
        ?>
        <article class="ap-entry ap-entry--single">
            <h1 class="ap-entry__title"><?php agora_the_title(); ?></h1>
            <?php if ($date !== '') : ?>
                <p class="ap-entry__meta">
                    <time datetime="<?php echo function_exists('ap_esc_attr') ? ap_esc_attr($date) : htmlspecialchars($date, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                        <?php echo function_exists('ap_esc_html') ? ap_esc_html($date) : htmlspecialchars($date, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                    </time>
                </p>
            <?php endif; ?>
            <div class="ap-entry__content">
                <?php agora_the_content(); ?>
            </div>
        </article>
        <?php
    }
} else {
    echo '<div class="ap-not-found"><p>Post not found.</p></div>';
}

AP_Theme::getFooter();
