<?php

/**
 * Theme footer — Zero Shits to Give.
 *
 * @package ZeroShits
 */

declare(strict_types=1);

$siteName = function_exists('zeroshits_site_name') ? zeroshits_site_name() : 'Zero Shits to Give';
$esc = static function (string $text): string {
    return function_exists('ap_esc_html')
        ? ap_esc_html($text)
        : htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};
$escUrl = static function (string $url): string {
    return function_exists('ap_esc_url')
        ? ap_esc_url($url)
        : htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};

$quotes = [
    '“That’s not a moon… it’s a rest stop.” — almost Star Wars',
    '“I am the one who flushes.” — almost Breaking Bad',
    '“Winter is coming. Bring TP.” — almost GoT',
    '“May the fart be with you.” — a long time ago, in a bathroom far far away',
    '“One does not simply walk into Mordor without pepto.” — almost LotR',
    '“I’m gonna make him an offer he can’t refuse to wipe.” — almost The Godfather',
    '“Life finds a way… into the toilet.” — almost Jurassic Park',
    '“To infinity, and behind!” — almost Toy Story',
    '“Why so serious? Sit.” — almost The Dark Knight',
    '“I volunteer as tribute… to go first.” — almost Hunger Games (bathroom line)',
];
$quote = $quotes[array_rand($quotes)];

?>
</div><!-- .content-area -->
<?php
if (function_exists('ap_get_sidebar')) {
    ap_get_sidebar();
} elseif (class_exists('AP_Theme', false)) {
    AP_Theme::getSidebar();
}
?>
</div><!-- .site-content -->
</main>

<section class="zs-graffiti zs-shell" aria-hidden="true">
    <div class="zs-graffiti__wall">
        <span>🚽</span>
        <span class="zs-poop-ban"><span class="zs-poop-ban__emoji">💩</span></span>
        <span>🧻</span>
        <span>🚿</span>
        <span class="zs-poop-ban"><span class="zs-poop-ban__emoji">💩</span></span>
        <span>🛁</span>
        <span>🧼</span>
        <span class="zs-poop-ban"><span class="zs-poop-ban__emoji">💩</span></span>
    </div>
    <p class="zs-graffiti__quote"><?php echo $esc($quote); ?></p>
</section>

<footer class="site-footer" role="contentinfo">
    <div class="site-footer__inner zs-shell">
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

if (function_exists('ap_nav_menu')) {
    ap_nav_menu([
        'theme_location' => 'footer',
        'container' => 'nav',
        'container_class' => 'ap-nav ap-nav--footer',
        'menu_class' => 'ap-menu ap-menu--footer',
        'echo' => true,
        'fallback_cb' => static function (array $args, $db = null): string {
            if (class_exists('AP_Nav_Menu', false)) {
                return AP_Nav_Menu::fallbackFooter($args, $db instanceof AP_DB ? $db : null);
            }

            return '';
        },
    ]);
}
?>
        <p class="site-footer__legal">
            <span class="zs-poop-ban zs-poop-ban--sm" aria-hidden="true"><span class="zs-poop-ban__emoji">💩</span></span>
            &copy; <?php echo date('Y'); ?>
            <?php echo $esc($siteName); ?>
            · Zero shits were given in the making of this site
            · Powered by <a href="<?php echo $escUrl('https://agorapress.extrovertednerd.com'); ?>">AgoraPress</a>
<?php
$feedRss = function_exists('ap_get_feed_link') ? ap_get_feed_link('rss2') : '';
if ($feedRss !== '') :
    ?>
            · <a href="<?php echo $escUrl((string) $feedRss); ?>">RSS</a>
<?php endif; ?>
        </p>
        <p class="site-footer__fine">
            Pop-culture nods are parody / fair-use vibes, not endorsements.
            Please wash your hands. Seriously. Like, after you leave the internet.
        </p>
    </div>
</footer>
<?php
if (function_exists('ap_footer')) {
    ap_footer();
}
?>
</body>
</html>
