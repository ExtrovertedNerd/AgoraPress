<?php

/**
 * Single page template.
 *
 * @package Agora
 */

declare(strict_types=1);

AP_Theme::getHeader();

if (function_exists('ap_have_posts') && ap_have_posts()) {
    while (ap_have_posts()) {
        ap_the_post();
        ?>
        <article class="ap-entry ap-entry--page">
            <h1 class="ap-entry__title"><?php agora_the_title(); ?></h1>
            <div class="ap-entry__content">
                <?php agora_the_content(); ?>
            </div>
        </article>
        <?php
    }
} else {
    echo '<div class="ap-not-found"><p>Page not found.</p></div>';
}

AP_Theme::getFooter();
