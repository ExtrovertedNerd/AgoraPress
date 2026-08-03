<?php

/**
 * Front page template.
 *
 * Used when Reading settings put latest posts or a static page on the front.
 * Falls back through home.php / page.php when this file is missing.
 *
 * @package Agora
 */

declare(strict_types=1);

$q = isset($GLOBALS['ap_query']) && $GLOBALS['ap_query'] instanceof AP_Query
    ? $GLOBALS['ap_query']
    : null;

// Static front page: single page content.
if ($q instanceof AP_Query && !empty($q->is_page) && !empty($q->is_front_page)) {
    AP_Theme::getHeader();
    if (function_exists('ap_have_posts') && ap_have_posts()) {
        while (ap_have_posts()) {
            ap_the_post();
            ?>
            <article class="ap-entry ap-entry--page ap-entry--front">
                <h1 class="ap-entry__title"><?php agora_the_title(); ?></h1>
                <div class="ap-entry__content">
                    <?php agora_the_content(); ?>
                </div>
            </article>
            <?php
        }
    } else {
        echo '<div class="ap-empty"><p>Front page content is not available.</p></div>';
    }
    AP_Theme::getFooter();

    return;
}

// Latest posts on the front — same loop as home/index (includes header/footer).
require __DIR__ . '/index.php';
