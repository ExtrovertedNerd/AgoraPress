<?php

/**
 * Single page template.
 *
 * @package ZeroShits
 */

declare(strict_types=1);

AP_Theme::getHeader();

if (function_exists('ap_have_posts') && ap_have_posts()) {
    while (ap_have_posts()) {
        ap_the_post();
        ?>
        <article class="ap-entry ap-entry--page">
            <h1 class="ap-entry__title"><?php zeroshits_the_title(); ?></h1>
            <div class="ap-entry__content">
                <?php zeroshits_the_content(); ?>
            </div>
        </article>
        <?php
    }
} else {
    echo '<div class="ap-not-found"><p>Lost in the plumbing. This page took a permanent dump.</p></div>';
}

AP_Theme::getFooter();
