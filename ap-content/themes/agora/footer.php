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
</main>
<footer class="site-footer">
    <div class="site-footer__inner">
<?php
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
            · Powered by AgoraPress
<?php
$feedRss = function_exists('ap_get_feed_link') ? ap_get_feed_link('rss2') : '';
if ($feedRss !== '') :
    ?>
            · <a href="<?php echo function_exists('ap_esc_url') ? ap_esc_url($feedRss) : htmlspecialchars($feedRss, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">RSS</a>
<?php endif; ?>
        </p>
    </div>
</footer>
</body>
</html>
