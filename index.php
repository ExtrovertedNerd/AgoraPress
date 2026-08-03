<?php

/**
 * AgoraPress front controller.
 *
 * Loads the bootstrap. If the site is not installed (no ap-config.php),
 * bootstrap exits with a friendly 503 page. Full routing and themes land
 * in later phases.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

// Absolute filesystem path to the AgoraPress root, with trailing slash.
if (!defined('AP_ABSPATH')) {
    define('AP_ABSPATH', __DIR__ . '/');
}

require AP_ABSPATH . 'ap-includes/bootstrap.php';

ap_bootstrap();

// Installed and core loaded. Front-end template loader arrives in Phase 2.
// Keep the successful path quiet and error-free until then.
