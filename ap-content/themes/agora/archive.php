<?php

/**
 * Generic archive template (author, date, CPT, tax fallback).
 *
 * @package Agora
 */

declare(strict_types=1);

AP_Theme::getHeader();

$heading = 'Archive';
if (isset($GLOBALS['ap_query']) && $GLOBALS['ap_query'] instanceof AP_Query) {
    $q = $GLOBALS['ap_query'];
    if ($q->is_category) {
        $heading = 'Category: ' . (string) $q->get('category_name', 'Category');
    } elseif ($q->is_tag) {
        $heading = 'Tag: ' . (string) $q->get('tag', 'Tag');
    } elseif ($q->is_author) {
        $heading = 'Author: ' . (string) $q->get('author_name', 'Author');
    } elseif ($q->is_search) {
        $heading = 'Search results';
    } elseif ($q->is_date) {
        $heading = 'Date archive';
    }
}

echo '<h1 class="ap-archive-title">'
    . (function_exists('ap_esc_html') ? ap_esc_html($heading) : htmlspecialchars($heading, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'))
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
    echo '<div class="ap-empty"><p>No posts in this archive.</p></div>';
}

AP_Theme::getFooter();
