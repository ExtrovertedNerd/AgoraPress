<?php

/**
 * Server requirements checker for the AgoraPress installer.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * Evaluates PHP version, extensions, and filesystem writability for install.
 */
class AP_Requirements
{
    /**
     * Minimum PHP version string (display + comparison via PHP_VERSION_ID).
     */
    public const MIN_PHP = '8.2';

    /**
     * Minimum PHP_VERSION_ID (8.2.0).
     */
    public const MIN_PHP_ID = 80200;

    /**
     * Required PHP extensions (name => human label).
     * At least one of pdo_mysql / pdo_sqlite / pdo_pgsql is required separately.
     *
     * @return array<string, string>
     */
    public static function requiredExtensions(): array
    {
        return [
            'pdo' => 'PDO',
            'mbstring' => 'mbstring',
            'json' => 'JSON',
            'curl' => 'cURL',
            'fileinfo' => 'fileinfo',
            'zip' => 'Zip',
        ];
    }

    /**
     * Recommended (non-blocking) extensions.
     *
     * @return array<string, string>
     */
    public static function recommendedExtensions(): array
    {
        return [
            'intl' => 'intl (internationalization)',
        ];
    }

    /**
     * Run the full requirements suite.
     *
     * @param string|null $abspath Project root with trailing slash (defaults to AP_ABSPATH).
     *
     * @return list<array{
     *     id: string,
     *     label: string,
     *     ok: bool,
     *     required: bool,
     *     message: string
     * }>
     */
    public static function check(?string $abspath = null): array
    {
        $root = self::resolveAbspath($abspath);
        $checks = [];

        $checks[] = self::checkPhpVersion();
        $checks = array_merge($checks, self::checkRequiredExtensions());
        $checks[] = self::checkPdoDriver();
        $checks[] = self::checkImageExtension();
        $checks = array_merge($checks, self::checkRecommendedExtensions());
        $checks = array_merge($checks, self::checkFilesystem($root));

        return $checks;
    }

    /**
     * Whether every required check passed (recommended failures are ignored).
     *
     * @param list<array{ok: bool, required: bool}> $checks
     */
    public static function allRequiredPassed(array $checks): bool
    {
        foreach ($checks as $check) {
            if (($check['required'] ?? true) && !($check['ok'] ?? false)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{id: string, label: string, ok: bool, required: bool, message: string}
     */
    private static function checkPhpVersion(): array
    {
        $ok = PHP_VERSION_ID >= self::MIN_PHP_ID;

        return [
            'id' => 'php_version',
            'label' => 'PHP version',
            'ok' => $ok,
            'required' => true,
            'message' => $ok
                ? 'PHP ' . PHP_VERSION . ' (requires ' . self::MIN_PHP . '+)'
                : 'PHP ' . PHP_VERSION . ' is too old; requires ' . self::MIN_PHP . ' or higher',
        ];
    }

    /**
     * @return list<array{id: string, label: string, ok: bool, required: bool, message: string}>
     */
    private static function checkRequiredExtensions(): array
    {
        $out = [];
        foreach (self::requiredExtensions() as $ext => $label) {
            $ok = extension_loaded($ext);
            $out[] = [
                'id' => 'ext_' . $ext,
                'label' => $label,
                'ok' => $ok,
                'required' => true,
                'message' => $ok
                    ? "Extension {$label} is loaded"
                    : "Missing required extension: {$label}",
            ];
        }

        return $out;
    }

    /**
     * @return array{id: string, label: string, ok: bool, required: bool, message: string}
     */
    private static function checkPdoDriver(): array
    {
        $drivers = [];
        if (extension_loaded('pdo_mysql')) {
            $drivers[] = 'pdo_mysql';
        }
        if (extension_loaded('pdo_sqlite')) {
            $drivers[] = 'pdo_sqlite';
        }
        if (extension_loaded('pdo_pgsql')) {
            $drivers[] = 'pdo_pgsql';
        }

        $ok = $drivers !== [];

        return [
            'id' => 'pdo_driver',
            'label' => 'PDO database driver',
            'ok' => $ok,
            'required' => true,
            'message' => $ok
                ? 'Available: ' . implode(', ', $drivers)
                : 'Need at least one of pdo_mysql, pdo_sqlite, or pdo_pgsql',
        ];
    }

    /**
     * @return array{id: string, label: string, ok: bool, required: bool, message: string}
     */
    private static function checkImageExtension(): array
    {
        $gd = extension_loaded('gd');
        $imagick = extension_loaded('imagick');
        $ok = $gd || $imagick;
        $parts = [];
        if ($gd) {
            $parts[] = 'gd';
        }
        if ($imagick) {
            $parts[] = 'imagick';
        }

        return [
            'id' => 'image_extension',
            'label' => 'Image processing (gd or imagick)',
            'ok' => $ok,
            'required' => true,
            'message' => $ok
                ? 'Available: ' . implode(', ', $parts)
                : 'Need gd or imagick for media processing',
        ];
    }

    /**
     * @return list<array{id: string, label: string, ok: bool, required: bool, message: string}>
     */
    private static function checkRecommendedExtensions(): array
    {
        $out = [];
        foreach (self::recommendedExtensions() as $ext => $label) {
            $ok = extension_loaded($ext);
            $out[] = [
                'id' => 'rec_' . $ext,
                'label' => $label,
                'ok' => $ok,
                'required' => false,
                'message' => $ok
                    ? "Extension {$label} is loaded"
                    : "Recommended extension missing: {$label}",
            ];
        }

        return $out;
    }

    /**
     * @return list<array{id: string, label: string, ok: bool, required: bool, message: string}>
     */
    private static function checkFilesystem(string $root): array
    {
        $checks = [];

        $configPath = $root . 'ap-config.php';
        $configWritable = self::pathIsWritableForCreate($configPath, $root);
        $checks[] = [
            'id' => 'writable_config',
            'label' => 'Configuration file location',
            'ok' => $configWritable,
            'required' => true,
            'message' => $configWritable
                ? 'Can create ap-config.php in the site root'
                : 'Site root is not writable; cannot create ap-config.php',
        ];

        $contentDir = $root . 'ap-content';
        $contentOk = is_dir($contentDir) && is_writable($contentDir);
        $checks[] = [
            'id' => 'writable_content',
            'label' => 'ap-content directory',
            'ok' => $contentOk,
            'required' => true,
            'message' => $contentOk
                ? 'ap-content is writable'
                : 'ap-content must exist and be writable',
        ];

        $uploadsDir = $contentDir . '/uploads';
        $uploadsOk = is_dir($uploadsDir) && is_writable($uploadsDir);
        // Uploads may be created later; directory should exist from scaffold.
        $checks[] = [
            'id' => 'writable_uploads',
            'label' => 'ap-content/uploads directory',
            'ok' => $uploadsOk,
            'required' => true,
            'message' => $uploadsOk
                ? 'uploads is writable'
                : 'ap-content/uploads must exist and be writable',
        ];

        return $checks;
    }

    /**
     * Whether we can create or overwrite a file at $path (parent dir writable).
     */
    public static function pathIsWritableForCreate(string $path, ?string $parent = null): bool
    {
        if (is_file($path)) {
            return is_writable($path);
        }

        $dir = $parent ?? dirname($path);

        return is_dir($dir) && is_writable($dir);
    }

    private static function resolveAbspath(?string $abspath): string
    {
        if ($abspath !== null && $abspath !== '') {
            return rtrim($abspath, "/\\") . '/';
        }

        if (defined('AP_ABSPATH')) {
            return (string) AP_ABSPATH;
        }

        return dirname(__DIR__) . '/';
    }
}
