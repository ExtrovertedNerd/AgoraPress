<?php

/**
 * Classic WordPress Theme Compatibility Layer — loader.
 *
 * Includes the coordinator class and converter. Shim files (WP template tags
 * and functions) load lazily when a classic theme needs them, or eagerly when
 * AP_Theme_Compat::ensureLoaded(true) is used (e.g. conversion / tests).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

require_once __DIR__ . '/class-ap-theme-compat.php';
require_once __DIR__ . '/class-ap-theme-converter.php';

/**
 * Whether the classic WP theme compatibility layer classes are available.
 */
function ap_theme_compat_available(): bool
{
    return class_exists('AP_Theme_Compat', false);
}

/**
 * Ensure WP shims are loaded when compatibility is active for the theme.
 *
 * @see AP_Theme_Compat::ensureLoaded()
 */
function ap_theme_compat_load(bool $force = false, ?AP_DB $db = null): bool
{
    if (!class_exists('AP_Theme_Compat', false)) {
        return false;
    }

    return AP_Theme_Compat::ensureLoaded($force, $db);
}

/**
 * Whether compatibility is active for the current request.
 *
 * @see AP_Theme_Compat::isActive()
 */
function ap_theme_compat_is_active(?AP_DB $db = null): bool
{
    return class_exists('AP_Theme_Compat', false) && AP_Theme_Compat::isActive($db);
}

/**
 * Per-theme compatibility mode: auto | on | off.
 *
 * @see AP_Theme_Compat::getMode()
 */
function ap_theme_compat_get_mode(string $slug, ?AP_DB $db = null): string
{
    if (!class_exists('AP_Theme_Compat', false)) {
        return 'auto';
    }

    return AP_Theme_Compat::getMode($slug, $db);
}

/**
 * Set per-theme compatibility mode.
 *
 * @see AP_Theme_Compat::setMode()
 */
function ap_theme_compat_set_mode(string $slug, string $mode, ?AP_DB $db = null): bool
{
    return class_exists('AP_Theme_Compat', false)
        && AP_Theme_Compat::setMode($slug, $mode, $db);
}

/**
 * Analyze a classic WP theme path for compatibility.
 *
 * @return array<string, mixed>
 *
 * @see AP_Theme_Converter::analyzePath()
 */
function ap_theme_compat_analyze(string $path): array
{
    if (!class_exists('AP_Theme_Converter', false)) {
        return [];
    }

    return AP_Theme_Converter::analyzePath($path);
}

/**
 * Human-readable compatibility report for a theme path.
 *
 * @see AP_Theme_Converter::formatReport()
 */
function ap_theme_compat_report(string $path): string
{
    if (!class_exists('AP_Theme_Converter', false)) {
        return '';
    }

    return AP_Theme_Converter::formatReport(AP_Theme_Converter::analyzePath($path));
}
