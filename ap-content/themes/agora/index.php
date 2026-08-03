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
        $date = function_exists('agora_the_date') ? agora_the_date() : '';
        ?>
        <article <?php echo 'class="ap-entry"'; ?>>
            <h2 class="ap-entry__title">
                <?php if ($permalink !== '') : ?>
                    <a href="<?php echo function_exists('ap_esc_url') ? ap_esc_url($permalink) : htmlspecialchars($permalink, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                        <?php agora_the_title(); ?>
                    </a>
                <?php else : ?>
                    <?php agora_the_title(); ?>
                <?php endif; ?>
            </h2>
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
    echo '<div class="ap-empty"><p>No content found.</p></div>';
}

AP_Theme::getFooter();
