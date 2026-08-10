<?php

/**
 * Plugin Name: Logos
 * Plugin URI:  https://agorapress.extrovertednerd.com/
 * Description: Sample plugin that registers a Settings screen in the ACP (admin.php?page=logos). Used for smoke-testing the plugin admin page registry, sidebar menu merge, and plugins list Settings link.
 * Version:     1.0.0
 * Author:      AgoraPress
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: logos
 * Requires PHP: 8.2
 * Requires at least: 0.1.0
 *
 * @package AgoraPress
 */

declare(strict_types=1);

if (!defined('AP_ABSPATH')) {
    exit;
}

/**
 * Render the Logos settings screen inside ACP chrome (admin.php?page=logos).
 */
function logos_render_settings(): void
{
    echo '<div class="ap-wrap ap-plugin-settings logos-settings">';
    echo '<h1>' . ap_esc_html__('Logos', 'logos') . '</h1>';
    echo '<p class="ap-help">'
        . ap_esc_html__(
            'This is the sample Logos plugin settings page. It is loaded only through the admin router (admin.php?page=logos), not via a raw plugin URL.',
            'logos'
        )
        . '</p>';
    echo '<p>'
        . ap_esc_html__(
            'Activate this plugin to verify: (1) “Logos” appears under Settings in the ACP sidebar, and (2) a Settings action appears on the Plugins list row.',
            'logos'
        )
        . '</p>';
    echo '</div>';
}

// Prefer the real basename helper when the plugin API is loaded; fall back for
// early/bootstrap edge cases so registration still ties to this plugin file.
$logosPluginFile = function_exists('ap_plugin_basename')
    ? ap_plugin_basename(__FILE__)
    : 'logos/logos.php';
if ($logosPluginFile === '') {
    $logosPluginFile = 'logos/logos.php';
}

if (function_exists('ap_register_admin_page')) {
    ap_register_admin_page([
        'id' => 'logos',
        'parent' => 'settings',
        'title' => 'Logos',
        'menu' => 'Logos',
        'capability' => 'manage_options',
        'callback' => 'logos_render_settings',
        'plugin' => $logosPluginFile,
        'position' => 50,
    ]);
}
