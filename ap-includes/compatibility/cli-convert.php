<?php

/**
 * CLI: classic WordPress theme compatibility / conversion report.
 *
 * Usage:
 *   php ap-includes/compatibility/cli-convert.php /path/to/theme
 *   php ap-includes/compatibility/cli-convert.php --path=/path/to/theme --json
 *
 * Exit codes:
 *   0 success (classic theme supported)
 *   1 usage / help without path
 *   2 path missing
 *   3 block/FSE theme
 *   4 classic structure incomplete / unsupported
 *
 * @package AgoraPress
 */

declare(strict_types=1);

if (!defined('AP_ABSPATH')) {
    define('AP_ABSPATH', dirname(__DIR__, 2) . '/');
}

require_once AP_ABSPATH . 'ap-includes/class-ap-theme.php';
require_once AP_ABSPATH . 'ap-includes/compatibility/load.php';

/** @var list<string> $argv */
$argv = $_SERVER['argv'] ?? [];

exit(AP_Theme_Converter::runCli(is_array($argv) ? $argv : []));
