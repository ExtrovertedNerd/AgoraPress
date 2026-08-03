<?php

/**
 * Admin page header + sidebar shell (responsive, accessible, light/dark modes).
 *
 * Expects (optional) before include:
 *   $ap_admin_title  string  Document / H1 title
 *   $ap_admin_screen string  Menu highlight id (dashboard|posts|pages)
 *   $ap_admin_body_class string Extra body classes
 *
 * @package AgoraPress
 */

declare(strict_types=1);

$ap_admin_title = $ap_admin_title ?? 'Admin';
$ap_admin_screen = $ap_admin_screen ?? '';
$ap_admin_body_class = $ap_admin_body_class ?? '';
$user = ap_get_current_user();
$displayName = $user !== null
    ? ($user->display_name !== '' ? $user->display_name : $user->user_login)
    : '';
$cssUrl = AP_Admin::url('css/admin.css');
$version = defined('AP_VERSION') ? (string) AP_VERSION : '';
$siteName = AP_Admin::siteName();
$homeUrl = AP_Admin::homeUrl();
$profileUrl = AP_Admin::url('profile.php');
$logoutBase = AP_Admin::url('login.php', ['action' => 'logout']);
$logoutUrl = function_exists('ap_nonce_url')
    ? ap_nonce_url($logoutBase, 'log-out')
    : $logoutBase;
$dashboardUrl = AP_Admin::url('index.php');
$menuItems = AP_Admin::menuItems($ap_admin_screen);
$colorModePref = AP_Admin::getColorMode();
$colorModeLabels = AP_Admin::colorModeLabels();
$colorModeLabel = $colorModeLabels[$colorModePref] ?? 'System';
$htmlLang = function_exists('ap_get_html_lang') ? ap_get_html_lang() : 'en';
$textDir = function_exists('ap_get_text_direction') ? ap_get_text_direction() : 'ltr';
$isRtl = $textDir === 'rtl';
if ($isRtl) {
    $ap_admin_body_class = trim($ap_admin_body_class . ' rtl');
} else {
    $ap_admin_body_class = trim($ap_admin_body_class . ' ltr');
}

?><!DOCTYPE html>
<html
    lang="<?php echo ap_esc_attr($htmlLang); ?>"
    dir="<?php echo ap_esc_attr($textDir); ?>"
    data-ap-color-mode-pref="<?php echo ap_esc_attr($colorModePref); ?>"
>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="color-scheme" content="light dark">
    <title><?php echo ap_esc_html($ap_admin_title); ?> ‹ <?php echo ap_esc_html($siteName); ?></title>
    <link rel="stylesheet" href="<?php echo ap_esc_url($cssUrl); ?>?v=<?php echo ap_esc_attr($version); ?>">
    <?php
    // Prevent flash of wrong color mode before CSS paints (localStorage → server pref → auto).
    ?>
    <script>
    (function () {
        try {
            var key = 'ap_admin_color_mode';
            var stored = null;
            try { stored = localStorage.getItem(key); } catch (e) {}
            var pref = document.documentElement.getAttribute('data-ap-color-mode-pref') || 'auto';
            var mode = (stored === 'light' || stored === 'dark' || stored === 'auto')
                ? stored
                : (pref === 'light' || pref === 'dark' || pref === 'auto' ? pref : 'auto');
            document.documentElement.setAttribute('data-ap-color-mode', mode);
            var meta = document.querySelector('meta[name="color-scheme"]');
            if (meta) {
                meta.setAttribute('content', mode === 'auto' ? 'light dark' : mode);
            }
        } catch (e) {}
    })();
    </script>
</head>
<body class="ap-admin <?php echo ap_esc_attr($ap_admin_body_class); ?>">
<a class="skip-link screen-reader-text" href="#ap-admin-content">Skip to main content</a>
<div class="ap-admin-wrap">
    <header class="ap-admin-topbar" role="banner">
        <div class="ap-admin-topbar-start">
            <button
                type="button"
                class="ap-menu-toggle"
                id="ap-menu-toggle"
                aria-controls="ap-admin-menu"
                aria-expanded="false"
                aria-label="Open admin menu"
            >
                <span class="ap-menu-toggle-bars" aria-hidden="true"></span>
            </button>
            <div class="ap-admin-brand">
                <a href="<?php echo ap_esc_url($dashboardUrl); ?>" class="ap-admin-brand-name">
                    <?php echo ap_esc_html($siteName); ?>
                </a>
                <?php if ($version !== '') : ?>
                    <span class="ap-version" title="AgoraPress version"><?php echo ap_esc_html($version); ?></span>
                <?php endif; ?>
            </div>
        </div>
        <div class="ap-admin-topbar-end">
            <button
                type="button"
                class="ap-color-mode-toggle"
                id="ap-color-mode-toggle"
                aria-label="Color mode: <?php echo ap_esc_attr($colorModeLabel); ?>. Click to change."
                title="Color mode (System / Light / Dark)"
                data-ap-color-mode-current="<?php echo ap_esc_attr($colorModePref); ?>"
            >
                <svg class="ap-color-mode-icon ap-color-mode-icon--sun" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                     aria-hidden="true" focusable="false">
                    <circle cx="12" cy="12" r="4"></circle>
                    <path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"></path>
                </svg>
                <svg class="ap-color-mode-icon ap-color-mode-icon--moon" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                     aria-hidden="true" focusable="false">
                    <path d="M21 14.5A8.5 8.5 0 1 1 9.5 3a7 7 0 0 0 11.5 11.5z"></path>
                </svg>
                <svg class="ap-color-mode-icon ap-color-mode-icon--auto" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                     aria-hidden="true" focusable="false">
                    <rect x="2" y="4" width="20" height="14" rx="2"></rect>
                    <path d="M8 21h8M12 18v3"></path>
                    <path d="M2 12h20" opacity="0.35"></path>
                </svg>
            </button>
            <a class="ap-visit-site" href="<?php echo ap_esc_url($homeUrl); ?>" target="_blank" rel="noopener noreferrer">
                Visit Site
                <span class="screen-reader-text">(opens in a new tab)</span>
            </a>
            <div class="ap-admin-user">
                <?php if ($displayName !== '') : ?>
                    <a class="ap-user-name" href="<?php echo ap_esc_url($profileUrl); ?>"><?php echo ap_esc_html($displayName); ?></a>
                <?php endif; ?>
                <a class="ap-logout" href="<?php echo ap_esc_url($logoutUrl); ?>">Log out</a>
            </div>
        </div>
    </header>
    <div class="ap-admin-body">
        <div class="ap-admin-menu-backdrop" id="ap-admin-menu-backdrop" hidden></div>
        <nav class="ap-admin-menu" id="ap-admin-menu" aria-label="Admin">
            <ul>
                <?php
                $prevSection = null;
                foreach ($menuItems as $item) :
                    $section = (string) ($item['section'] ?? '');
                    if ($section !== '' && $section !== $prevSection) :
                        $prevSection = $section;
                        $sectionLabel = AP_Admin::menuSectionLabel($section);
                        if ($sectionLabel !== '') :
                            ?>
                    <li class="ap-menu-section" role="presentation">
                        <span class="ap-menu-section-label"><?php echo ap_esc_html($sectionLabel); ?></span>
                    </li>
                            <?php
                        endif;
                    endif;
                    $liClass = !empty($item['active']) ? 'current' : '';
                    $aria = !empty($item['active']) ? ' aria-current="page"' : '';
                    ?>
                    <li class="<?php echo ap_esc_attr($liClass); ?>">
                        <a href="<?php echo ap_esc_url($item['url']); ?>"<?php echo $aria; ?>>
                            <?php echo ap_esc_html($item['label']); ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </nav>
        <main id="ap-admin-content" class="ap-admin-content" tabindex="-1" role="main">
            <?php echo AP_Admin::renderNotices(); ?>
