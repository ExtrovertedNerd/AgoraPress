<?php

/**
 * PHPUnit bootstrap (scaffold).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_readable($autoload)) {
    require $autoload;
}
