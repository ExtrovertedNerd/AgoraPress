<?php

/**
 * Generic archive template (author, date, CPT, tax fallback).
 *
 * @package ZeroShits
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
        $permalink = function_exists('zeroshits_the_permalink') ? zeroshits_the_permalink() : '';
        $excerpt = function_exists('ap_get_the_excerpt') ? ap_get_the_excerpt() : '';
        ?>
        <article class="ap-entry">
            <h2 class="ap-entry__title">
                <?php if ($permalink !== '') : ?>
                    <a href="<?php echo function_exists('ap_esc_url') ? ap_esc_url($permalink) : htmlspecialchars($permalink, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
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
    echo '<div class="ap-empty" role="status"><p>No posts in this archive.</p></div>';
}

AP_Theme::getFooter();
