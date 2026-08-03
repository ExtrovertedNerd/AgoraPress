<?php

/**
 * Minimal bootstrap for PHPStan (no database, no config file).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

if (!defined('AP_ABSPATH')) {
    define('AP_ABSPATH', dirname(__DIR__) . '/');
}

if (!defined('AP_CONTENT_DIR')) {
    define('AP_CONTENT_DIR', AP_ABSPATH . 'ap-content');
}

if (!defined('AP_CONTENT_URL')) {
    define('AP_CONTENT_URL', 'http://example.test/ap-content');
}

if (!defined('AP_INC')) {
    define('AP_INC', AP_ABSPATH . 'ap-includes');
}

if (!defined('AP_TABLE_PREFIX')) {
    define('AP_TABLE_PREFIX', 'ap_');
}

if (!defined('AP_DEBUG')) {
    define('AP_DEBUG', false);
}

if (!defined('AP_TELEMETRY')) {
    define('AP_TELEMETRY', false);
}

// Auth keys (placeholders for analysis only).
foreach (
    [
        'AP_AUTH_KEY',
        'AP_SECURE_AUTH_KEY',
        'AP_LOGGED_IN_KEY',
        'AP_NONCE_KEY',
        'AP_AUTH_SALT',
        'AP_SECURE_AUTH_SALT',
        'AP_LOGGED_IN_SALT',
        'AP_NONCE_SALT',
    ] as $const
) {
    if (!defined($const)) {
        define($const, 'phpstan-placeholder-' . $const);
    }
}

require_once AP_ABSPATH . 'ap-includes/version.php';

// Classmap autoload for AP_* classes.
$autoload = AP_ABSPATH . 'vendor/autoload.php';
if (is_readable($autoload)) {
    require_once $autoload;
}
