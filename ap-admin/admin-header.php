<?php

/**
 * Admin page header + sidebar shell (responsive, accessible).
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

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?php echo ap_esc_html($ap_admin_title); ?> ‹ <?php echo ap_esc_html($siteName); ?></title>
    <link rel="stylesheet" href="<?php echo ap_esc_url($cssUrl); ?>?v=<?php echo ap_esc_attr($version); ?>">
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
        <main id="ap-admin-content" class="ap-admin-content" tabindex="-1">
            <?php echo AP_Admin::renderNotices(); ?>
