<?php

/**
 * Search results template.
 *
 * @package Agora
 */

declare(strict_types=1);

AP_Theme::getHeader();

$q = isset($GLOBALS['ap_query']) && $GLOBALS['ap_query'] instanceof AP_Query
    ? $GLOBALS['ap_query']
    : null;
$term = $q instanceof AP_Query ? (string) $q->get('s', '') : '';

echo '<h1 class="ap-archive-title">Search'
    . ($term !== ''
        ? ': ' . (function_exists('ap_esc_html') ? ap_esc_html($term) : htmlspecialchars($term, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'))
        : '')
    . '</h1>';

if (function_exists('ap_have_posts') && ap_have_posts()) {
    while (ap_have_posts()) {
        ap_the_post();
        $permalink = function_exists('agora_the_permalink') ? agora_the_permalink() : '';
        ?>
        <article class="ap-entry">
            <h2 class="ap-entry__title">
                <?php if ($permalink !== '') : ?>
                    <a href="<?php echo function_exists('ap_esc_url') ? ap_esc_url($permalink) : htmlspecialchars($permalink, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                        <?php agora_the_title(); ?>
                    </a>
                <?php else : ?>
                    <?php agora_the_title(); ?>
                <?php endif; ?>
            </h2>
            <div class="ap-entry__content">
                <?php agora_the_content(); ?>
            </div>
        </article>
        <?php
    }
} else {
    echo '<div class="ap-empty"><p>No results found.</p></div>';
}

AP_Theme::getFooter();
