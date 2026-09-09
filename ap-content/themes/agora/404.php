<?php

/**
 * 404 template.
 *
 * @package Agora
 */

declare(strict_types=1);

AP_Theme::getHeader();

$home = '/';
if (function_exists('agora_home_url')) {
    $home = agora_home_url('/');
} elseif (function_exists('ap_home_url') && class_exists('AP_Rewrite', false)) {
    $home = ap_home_url('/');
}
$q = isset($GLOBALS['ap_query']) && $GLOBALS['ap_query'] instanceof AP_Query
    ? $GLOBALS['ap_query']
    : null;
$cannotView = $q instanceof AP_Query && !empty($q->get('ap_forum_cannot_view', false));
$cannotViewMessage = $q instanceof AP_Query
    ? (string) $q->get('ap_forum_cannot_view_message', '')
    : '';
if ($cannotView && $cannotViewMessage === '') {
    $cannotViewMessage = class_exists('AP_Forum_Front', false)
        ? AP_Forum_Front::CANNOT_VIEW_MESSAGE
        : 'You cannot view this.';
}
$escCannotView = function_exists('agora_esc')
    ? agora_esc($cannotViewMessage)
    : htmlspecialchars($cannotViewMessage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$escHome = function_exists('ap_esc_url')
    ? ap_esc_url($home)
    : htmlspecialchars($home, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<?php if ($cannotView) : ?>
<div class="ap-not-found ap-forum-empty ap-forum-empty--cannot_view" role="status">
    <h1 class="ap-archive-title"><?php echo $escCannotView; ?></h1>
    <p><a class="ap-btn ap-btn--ghost" href="<?php echo $escHome; ?>">Back to home</a></p>
</div>
<?php
AP_Theme::getFooter();
return;
endif; ?>
<div class="ap-not-found" role="status">
    <h1 class="ap-archive-title">Page not found</h1>
    <p>The content you requested could not be found. Try a search or return home.</p>
    <form class="ap-search-form" role="search" method="get" action="<?php echo $escHome; ?>">
        <label class="screen-reader-text" for="ap-404-search">Search for:</label>
        <input type="search" id="ap-404-search" name="s" placeholder="Search…" required>
        <button type="submit">Search</button>
    </form>
    <p><a class="ap-btn ap-btn--ghost" href="<?php echo $escHome; ?>">Back to home</a></p>
</div>
<?php
AP_Theme::getFooter();
