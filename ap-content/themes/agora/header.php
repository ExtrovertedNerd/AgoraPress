<?php

/**
 * Theme header.
 *
 * @package Agora
 */

declare(strict_types=1);

$siteName = function_exists('agora_site_name') ? agora_site_name() : 'AgoraPress';
$siteDesc = function_exists('agora_site_description') ? agora_site_description() : '';
$home = (function_exists('ap_home_url') && class_exists('AP_Rewrite', false))
    ? ap_home_url('/')
    : '/';
$agoraBody = function_exists('agora_body_class') ? agora_body_class() : 'agora-theme agora-scheme-marble agora-mode-light';
$bodyClass = function_exists('ap_get_body_class')
    ? implode(' ', ap_get_body_class($agoraBody))
    : $agoraBody;
$schemeMode = function_exists('agora_get_color_scheme_mode') ? agora_get_color_scheme_mode() : 'light';
$feedRss = function_exists('ap_get_feed_link') ? ap_get_feed_link('rss2') : '';
// Canonical + Open Graph are emitted by AP_Seo on ap_head(); no duplicate tags here.
$htmlLang = function_exists('ap_get_bloginfo') ? ap_get_bloginfo('language') : 'en';
if ($htmlLang === '') {
    $htmlLang = function_exists('ap_get_html_lang') ? ap_get_html_lang() : 'en';
}
$textDir = function_exists('ap_get_text_direction')
    ? ap_get_text_direction()
    : (function_exists('ap_get_bloginfo') ? ap_get_bloginfo('text_direction') : 'ltr');
if ($textDir !== 'rtl') {
    $textDir = 'ltr';
}
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
<html lang="<?php echo $escAttr($htmlLang); ?>" dir="<?php echo $escAttr($textDir); ?>" data-agora-scheme-mode="<?php echo $escAttr($schemeMode); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="<?php echo $escAttr($schemeMode); ?>">
    <meta name="theme-color" content="<?php echo $escAttr($schemeMode === 'dark' ? '#0c0c10' : '#f4f5f7'); ?>">
    <title><?php echo $esc($siteName); ?></title>
<?php if ($feedRss !== '') : ?>
    <link rel="alternate" type="application/rss+xml" title="<?php echo $escAttr($siteName . ' RSS'); ?>" href="<?php echo $escUrl($feedRss); ?>">
<?php endif; ?>
<?php
// Enqueue pipeline + print styles/scripts (and ap_head action).
if (function_exists('ap_head')) {
    ap_head();
}
?>
</head>
<body class="<?php echo $escAttr($bodyClass); ?>">
<a class="skip-link" href="#main">Skip to content</a>
<header class="site-header" role="banner">
    <div class="site-header__inner">
        <div class="site-branding">
            <p class="site-title">
                <a href="<?php echo $escUrl($home); ?>" rel="home">
                    <?php echo $esc($siteName); ?>
                </a>
            </p>
<?php if ($siteDesc !== '') : ?>
            <p class="site-description"><?php echo $esc($siteDesc); ?></p>
<?php endif; ?>
        </div>
<?php
// Primary nav: assigned menu when set; otherwise Home + published Pages (+ Forums).
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

// Account area: welcome/log out when logged in; Log in (+ Register if open) for guests.
if (function_exists('agora_the_account_indicator')) {
    agora_the_account_indicator();
}
?>
    </div>
</header>
<main class="site-main" id="main" tabindex="-1" role="main">
<div class="site-content<?php
echo (function_exists('ap_is_active_sidebar') && ap_is_active_sidebar('sidebar-1'))
    ? ' site-content--has-sidebar'
    : '';
?>">
<div class="content-area">
