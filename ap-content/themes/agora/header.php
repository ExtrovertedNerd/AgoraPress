<?php

/**
 * Theme header.
 *
 * @package Agora
 */

declare(strict_types=1);

$siteName = function_exists('agora_site_name') ? agora_site_name() : 'AgoraPress';
$siteDesc = function_exists('agora_site_description') ? agora_site_description() : '';
$styleUri = class_exists('AP_Theme', false)
    ? AP_Theme::getStylesheetUri() . '/style.css'
    : '';
$home = (function_exists('ap_home_url') && class_exists('AP_Rewrite', false))
    ? ap_home_url('/')
    : '/';
$agoraBody = function_exists('agora_body_class') ? agora_body_class() : 'agora-theme agora-scheme-marble agora-mode-light';
$bodyClass = function_exists('ap_get_body_class')
    ? implode(' ', ap_get_body_class($agoraBody))
    : $agoraBody;
$schemeMode = function_exists('agora_get_color_scheme_mode') ? agora_get_color_scheme_mode() : 'light';
$feedRss = function_exists('ap_get_feed_link') ? ap_get_feed_link('rss2') : '';
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
<html lang="en" data-agora-scheme-mode="<?php echo $escAttr($schemeMode); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="<?php echo $escAttr($schemeMode); ?>">
    <title><?php echo $esc($siteName); ?></title>
<?php if ($styleUri !== '') : ?>
    <link rel="stylesheet" href="<?php echo $escUrl($styleUri); ?>">
<?php endif; ?>
<?php if ($feedRss !== '') : ?>
    <link rel="alternate" type="application/rss+xml" title="<?php echo $escAttr($siteName . ' RSS'); ?>" href="<?php echo $escUrl($feedRss); ?>">
<?php endif; ?>
</head>
<body class="<?php echo $escAttr($bodyClass); ?>">
<a class="skip-link" href="#main">Skip to content</a>
<header class="site-header">
    <div class="site-header__inner">
        <div class="site-branding">
            <p class="site-title">
                <a href="<?php echo $escUrl($home); ?>">
                    <?php echo $esc($siteName); ?>
                </a>
            </p>
<?php if ($siteDesc !== '') : ?>
            <p class="site-description"><?php echo $esc($siteDesc); ?></p>
<?php endif; ?>
        </div>
<?php
if (function_exists('ap_has_nav_menu') && ap_has_nav_menu('primary')) {
    ap_nav_menu([
        'theme_location' => 'primary',
        'container' => 'nav',
        'container_class' => 'ap-nav ap-nav--primary',
        'menu_class' => 'ap-menu',
        'echo' => true,
    ]);
}
?>
    </div>
</header>
<main class="site-main" id="main">
