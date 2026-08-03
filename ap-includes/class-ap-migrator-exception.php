<?php

/**
 * Schema migration failures.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * Thrown when migrations cannot be discovered, validated, or applied.
 */
class AP_Migrator_Exception extends RuntimeException
{
}
