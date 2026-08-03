#!/usr/bin/env php
<?php

/**
 * AgoraPress CLI installer entry point.
 *
 * Non-interactive install: requirements → migrate → seed admin → ap-config.php.
 * Same core path as the web installer (AP_Installer).
 *
 * Usage:
 *   php install/cli.php --help
 *   php install/cli.php --db-driver=sqlite --site-title=Demo ...
 *
 * @package AgoraPress
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    fwrite(STDERR, "This installer must be run from the command line.\n");
    exit(1);
}

if (!defined('AP_ABSPATH')) {
    define('AP_ABSPATH', dirname(__DIR__) . '/');
}

require_once AP_ABSPATH . 'ap-includes/class-ap-cli-install.php';

AP_Cli_Install::ensureDependencies();

/** @var list<string> $argv */
$argv = isset($argv) && is_array($argv) ? $argv : ($_SERVER['argv'] ?? []);

exit(AP_Cli_Install::runFromArgv($argv, null, null, AP_ABSPATH));
