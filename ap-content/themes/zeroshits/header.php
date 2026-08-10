<?php

/**
 * Theme header — Zero Shits to Give.
 *
 * @package ZeroShits
 */

declare(strict_types=1);

$siteName = function_exists('zeroshits_site_name') ? zeroshits_site_name() : 'Zero Shits to Give';
$siteDesc = function_exists('zeroshits_site_description') ? zeroshits_site_description() : '';
if ($siteDesc === '') {
    $siteDesc = 'We give exactly none. Bathroom humor, zero effort, maximum nonsense.';
}
$home = function_exists('zeroshits_home_url') ? zeroshits_home_url('/') : '/';
$zsBody = function_exists('zeroshits_body_class') ? zeroshits_body_class() : 'zeroshits-theme zeroshits-scheme-porcelain zeroshits-mode-light';
$bodyClass = function_exists('ap_get_body_class')
    ? implode(' ', ap_get_body_class($zsBody))
    : $zsBody;
$feedRss = function_exists('ap_get_feed_link') ? ap_get_feed_link('rss2') : '';
$htmlLang = function_exists('ap_get_bloginfo') ? ap_get_bloginfo('language') : 'en';
if ($htmlLang === '') {
    $htmlLang = function_exists('ap_get_html_lang') ? ap_get_html_lang() : 'en';
}
$textDir = function_exists('ap_get_text_direction')
    ? ap_get_text_direction()
    : 'ltr';
if ($textDir !== 'rtl') {
    $textDir = 'ltr';
}

// Rotating taglines — immature on purpose.
$taglines = [
    'Giving zero shits since forever 💩',
    'Not your dad’s blog. Not your mom’s either.',
    'Contents under pressure. Flush responsibly.',
    'May contain traces of South Park, Shrek, and poor decisions.',
    'This is the way. (To the bathroom.)',
    'Winter is coming… to the porcelain throne.',
    'I fart in your general direction.',
    'One does not simply give a shit.',
    'That’s what she said. (About the bathroom.)',
    'Hold my beer — no, hold my TP.',
    'Live long and plopper.',
    'With great power comes great responsibility… to flush.',
    'No soup for you — and no shits either.',
    'They’re taking the hobbits to Isengard. We’re taking a dump.',
    'You can either shit in the sink, or sink in the shit. Now let that shit sink in.',
    'Yippee-ki-yay, bathroom-goer.',
];
$tagline = $taglines[array_rand($taglines)];

$esc = static function (string $text): string {
    return function_exists('ap_esc_html')
        ? ap_esc_html($text)
        : htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};
$escAttr = static function (string $text): string {
    return function_exists('ap_esc_attr')
        ? ap_esc_attr($text)
        : htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};
$escUrl = static function (string $url): string {
    return function_exists('ap_esc_url')
        ? ap_esc_url($url)
        : htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};

?><!DOCTYPE html>
<html lang="<?php echo $escAttr($htmlLang); ?>" dir="<?php echo $escAttr($textDir); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <meta name="theme-color" content="#f5f0e6">
    <title><?php echo $esc($siteName); ?></title>
<?php if ($feedRss !== '') : ?>
    <link rel="alternate" type="application/rss+xml" title="<?php echo $escAttr($siteName . ' RSS'); ?>" href="<?php echo $escUrl($feedRss); ?>">
<?php endif; ?>
<?php
if (function_exists('ap_head')) {
    ap_head();
}
?>
</head>
<body class="<?php echo $escAttr($bodyClass); ?>">
<a class="skip-link" href="#main">Skip the stall — jump to content</a>

<div class="zs-ticker" role="note" aria-label="Site tagline">
    <div class="zs-ticker__inner zs-shell">
        <span class="zs-poop-ban" aria-hidden="true" title="Zero shits given"><span class="zs-poop-ban__emoji">💩</span></span>
        <span class="zs-ticker__text"><?php echo $esc($tagline); ?></span>
        <span class="zs-poop-ban" aria-hidden="true"><span class="zs-poop-ban__emoji">💩</span></span>
    </div>
</div>

<header class="site-header" role="banner">
    <div class="site-header__inner zs-shell">
        <div class="site-branding">
            <p class="site-title">
                <a href="<?php echo $escUrl($home); ?>" rel="home">
                    <span class="site-title__mark" aria-hidden="true">
                        <span class="zs-poop-ban zs-poop-ban--lg"><span class="zs-poop-ban__emoji">💩</span></span>
                    </span>
                    <span class="site-title__text"><?php echo $esc($siteName); ?></span>
                </a>
            </p>
<?php if ($siteDesc !== '') : ?>
            <p class="site-description"><?php echo $esc($siteDesc); ?></p>
<?php endif; ?>
            <p class="site-tagline-pop" aria-hidden="true">
                <span class="zs-chip">Mr. Hankey approved*</span>
                <span class="zs-chip">*not actually</span>
                <span class="zs-chip">Shrek swamp energy</span>
                <span class="zs-chip">Idiocracy-ready</span>
            </p>
        </div>
<?php
if (function_exists('ap_nav_menu')) {
    ap_nav_menu([
        'theme_location' => 'primary',
        'container' => 'nav',
        'container_class' => 'ap-nav ap-nav--primary',
        'menu_class' => 'ap-menu',
        'echo' => true,
        'fallback_cb' => static function (array $args, $db = null): string {
            if (class_exists('AP_Nav_Menu', false)) {
                return AP_Nav_Menu::fallbackPrimary($args, $db instanceof AP_DB ? $db : null);
            }

            return '';
        },
    ]);
}
if (function_exists('zeroshits_the_account_indicator')) {
    zeroshits_the_account_indicator();
}
?>
    </div>
</header>

<main class="site-main" id="main" tabindex="-1" role="main">
<div class="site-content zs-shell">
<div class="content-area">
