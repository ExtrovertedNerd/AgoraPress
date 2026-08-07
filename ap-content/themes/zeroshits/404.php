<?php

/**
 * 404 template.
 *
 * @package ZeroShits
 */

declare(strict_types=1);

AP_Theme::getHeader();

$home = function_exists('zeroshits_home_url') ? zeroshits_home_url('/') : (function_exists('ap_home_url') && class_exists('AP_Rewrite', false) ? ap_home_url('/') : '/');
?>
<div class="ap-not-found" role="status">
    <h1 class="ap-archive-title">Page not found</h1>
    <p>The content you requested could not be found. Try a search or return home.</p>
    <form class="ap-search-form" role="search" method="get" action="<?php echo function_exists('ap_esc_url') ? ap_esc_url($home) : htmlspecialchars($home, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
        <label class="screen-reader-text" for="ap-404-search">Search for:</label>
        <input type="search" id="ap-404-search" name="s" placeholder="Search…" required>
        <button type="submit">Search</button>
    </form>
    <p><a class="ap-btn ap-btn--ghost" href="<?php echo function_exists('ap_esc_url') ? ap_esc_url($home) : htmlspecialchars($home, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">Back to home</a></p>
</div>
<?php
AP_Theme::getFooter();
