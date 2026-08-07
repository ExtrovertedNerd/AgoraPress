<?php

/**
 * Template Name: Full Width
 * Example custom page template for hierarchy / page_template meta tests.
 *
 * @package ZeroShits
 */

declare(strict_types=1);

AP_Theme::getHeader();

if (function_exists('ap_have_posts') && ap_have_posts()) {
    while (ap_have_posts()) {
        ap_the_post();
        ?>
        <article class="ap-entry ap-entry--page ap-entry--full-width">
            <h1 class="ap-entry__title"><?php zeroshits_the_title(); ?></h1>
            <div class="ap-entry__content">
                <?php zeroshits_the_content(); ?>
            </div>
        </article>
        <?php
    }
} else {
    echo '<div class="ap-not-found"><p>Page not found.</p></div>';
}

AP_Theme::getFooter();
