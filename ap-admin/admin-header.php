<?php

/**
 * Admin page header + sidebar shell.
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

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?php echo ap_esc_html($ap_admin_title); ?> ‹ AgoraPress</title>
    <link rel="stylesheet" href="<?php echo ap_esc_url($cssUrl); ?>?v=<?php echo ap_esc_attr($version); ?>">
</head>
<body class="ap-admin <?php echo ap_esc_attr($ap_admin_body_class); ?>">
<a class="skip-link screen-reader-text" href="#ap-admin-content">Skip to main content</a>
<div class="ap-admin-wrap">
    <header class="ap-admin-topbar">
        <div class="ap-admin-brand">
            <a href="<?php echo ap_esc_url(AP_Admin::url('index.php')); ?>">AgoraPress</a>
            <?php if ($version !== '') : ?>
                <span class="ap-version"><?php echo ap_esc_html($version); ?></span>
            <?php endif; ?>
        </div>
        <div class="ap-admin-user">
            <?php if ($displayName !== '') : ?>
                <span class="ap-user-name"><?php echo ap_esc_html($displayName); ?></span>
            <?php endif; ?>
            <?php $logoutUrl = AP_Admin::url('login.php', ['action' => 'logout']); ?>
            <a class="ap-logout" href="<?php echo ap_esc_url($logoutUrl); ?>">Log out</a>
        </div>
    </header>
    <div class="ap-admin-body">
        <nav class="ap-admin-menu" aria-label="Admin">
            <ul>
                <?php foreach (AP_Admin::menuItems($ap_admin_screen) as $item) : ?>
                    <?php
                    $liClass = $item['active'] ? 'current' : '';
                    $aria = $item['active'] ? ' aria-current="page"' : '';
                    ?>
                    <li class="<?php echo $liClass; ?>">
                        <a href="<?php echo ap_esc_url($item['url']); ?>"<?php echo $aria; ?>>
                            <?php echo ap_esc_html($item['label']); ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </nav>
        <main id="ap-admin-content" class="ap-admin-content">
            <?php echo AP_Admin::renderNotices(); ?>
