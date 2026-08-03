<?php

/**
 * Theme footer.
 *
 * @package Agora
 */

declare(strict_types=1);

$siteName = function_exists('agora_site_name') ? agora_site_name() : 'AgoraPress';
$esc = static function (string $text): string {
    return function_exists('ap_esc_html')
        ? ap_esc_html($text)
        : htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};

?>
</div><!-- .content-area -->
<?php
// Primary sidebar (modular area); no-op when empty / unregistered.
if (function_exists('ap_get_sidebar')) {
    ap_get_sidebar();
} elseif (class_exists('AP_Theme', false)) {
    AP_Theme::getSidebar();
}
?>
</div><!-- .site-content -->
</main>
<footer class="site-footer" role="contentinfo">
    <div class="site-footer__inner">
<?php
if (function_exists('ap_is_active_sidebar') && ap_is_active_sidebar('footer-1')) :
    ?>
        <div class="widget-area widget-area--footer" role="complementary" aria-label="Footer widgets">
<?php
    if (function_exists('ap_dynamic_sidebar')) {
        ap_dynamic_sidebar('footer-1');
    } elseif (class_exists('AP_Widgets', false)) {
        AP_Widgets::dynamicSidebar('footer-1');
    }
?>
        </div>
<?php
endif;

if (function_exists('ap_has_nav_menu') && ap_has_nav_menu('footer')) {
    ap_nav_menu([
        'theme_location' => 'footer',
        'container' => 'nav',
        'container_class' => 'ap-nav ap-nav--footer',
        'menu_class' => 'ap-menu ap-menu--footer',
        'echo' => true,
    ]);
}
?>
        <p>
            &copy; <?php echo date('Y'); ?>
            <?php echo $esc($siteName); ?>
            · Powered by <a href="<?php echo function_exists('ap_esc_url') ? ap_esc_url('https://agorapress.extrovertednerd.com') : 'https://agorapress.extrovertednerd.com'; ?>">AgoraPress</a>
<?php
$feedRss = function_exists('ap_get_feed_link') ? ap_get_feed_link('rss2') : '';
if ($feedRss !== '') :
    ?>
            · <a href="<?php echo function_exists('ap_esc_url') ? ap_esc_url($feedRss) : htmlspecialchars($feedRss, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">RSS</a>
<?php endif; ?>
        </p>
    </div>
</footer>
<?php
// Footer scripts + ap_footer action.
if (function_exists('ap_footer')) {
    ap_footer();
}
?>
</body>
</html>
