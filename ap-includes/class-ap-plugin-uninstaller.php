<?php

/**
 * @package AgoraPress
 */

declare(strict_types=1);

final class AP_Plugin_Uninstaller
{
    /**
     * @param string $pluginDir
     * @param string $plugin
     * @return array{ran: bool, error: string}
     */
    public static function run(string $pluginDir, string $plugin): array
    {
        $dir = realpath($pluginDir);
        if (false === $dir || !is_dir($dir)) {
            return ['ran' => false, 'error' => ''];
        }

        $file = $dir . DIRECTORY_SEPARATOR . 'uninstall.php';
        if (!is_file($file)) {
            return ['ran' => false, 'error' => ''];
        }

        $real = realpath($file);
        if (false === $real || !str_starts_with($real, $dir . DIRECTORY_SEPARATOR)) {
            return ['ran' => false, 'error' => 'uninstall.php is outside the plugin folder; skipped.'];
        }

        if (!defined('AP_UNINSTALL_PLUGIN')) {
            define('AP_UNINSTALL_PLUGIN', $plugin);
        }

        if (function_exists('ap_do_action')) {
            ap_do_action('ap_pre_uninstall_plugin', $plugin);
        }

        $error = '';
        ob_start();
        try {
            self::includeIsolated($real);
        } catch (Throwable $e) {
            $error = 'uninstall.php failed: ' . get_class($e) . ': ' . $e->getMessage();
        } finally {
            ob_end_clean();
        }

        if ('' === $error && function_exists('ap_do_action')) {
            ap_do_action('ap_plugin_uninstalled', $plugin);
        }

        return ['ran' => true, 'error' => $error];
    }

    /**
     * @param string $file
     * @return void
     */
    private static function includeIsolated(string $file): void
    {
        (static function (string $apUninstallFile): void {
            include $apUninstallFile;
        })($file);
    }
}
