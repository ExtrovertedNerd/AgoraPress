<?php

/**
 * Shared bootstrap for ap-admin screens.
 *
 * Loads core AgoraPress, admin includes, and (unless $ap_admin_skip_auth)
 * requires a logged-in user.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

if (!defined('AP_ABSPATH')) {
    define('AP_ABSPATH', dirname(__DIR__) . '/');
}

if (!defined('AP_ADMIN')) {
    define('AP_ADMIN', true);
}

require_once AP_ABSPATH . 'ap-includes/bootstrap.php';
ap_bootstrap();

require_once AP_ABSPATH . 'ap-admin/includes/class-ap-admin.php';
require_once AP_ABSPATH . 'ap-admin/includes/class-ap-admin-dashboard.php';
require_once AP_ABSPATH . 'ap-admin/includes/class-ap-posts-list-table.php';
require_once AP_ABSPATH . 'ap-admin/includes/class-ap-admin-post-edit.php';
require_once AP_ABSPATH . 'ap-admin/includes/class-ap-media-list-table.php';
require_once AP_ABSPATH . 'ap-admin/includes/class-ap-admin-media.php';
require_once AP_ABSPATH . 'ap-admin/includes/class-ap-admin-terms.php';
require_once AP_ABSPATH . 'ap-admin/includes/class-ap-comments-list-table.php';
require_once AP_ABSPATH . 'ap-admin/includes/class-ap-users-list-table.php';
require_once AP_ABSPATH . 'ap-admin/includes/class-ap-admin-user-edit.php';

// Login screen sets $ap_admin_skip_auth = true before including this file.
if (empty($ap_admin_skip_auth)) {
    AP_Admin::requireLogin();
}
