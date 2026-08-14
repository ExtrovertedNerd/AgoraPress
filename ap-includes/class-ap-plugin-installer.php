<?php

/**
 * Plugin zip installer — upload AgoraPress / classic WordPress plugins.
 *
 * Accepts a .zip containing a Plugin Name header in a PHP file at the archive
 * root (single-file plugin) or one level down (folder/plugin.php). Extracts
 * safely into ap-content/plugins/.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * Install plugins from zip archives (admin upload + programmatic).
 */
class AP_Plugin_Installer
{
    /** Default max plugin zip size (40 MiB) when not overridden. */
    public const DEFAULT_MAX_BYTES = 41943040;

    /**
     * Install a plugin from a filesystem path to a zip archive.
     *
     * @param array{
     *   overwrite?: bool,
     *   plugins_root?: string,
     *   slug?: string
     * } $args
     *
     * @return array{
     *   ok: bool,
     *   plugin: string,
     *   slug: string,
     *   path: string,
     *   headers: array<string, string>,
     *   is_folder: bool,
     *   overwritten: bool,
     *   errors: list<string>,
     *   warnings: list<string>
     * }
     */
    public static function installFromZip(string $zipPath, array $args = []): array
    {
        $result = self::emptyResult();

        if (!class_exists('ZipArchive', false)) {
            $result['errors'][] = 'The PHP ZipArchive extension is required to install plugins from zip files.';

            return $result;
        }

        $zipPath = (string) realpath($zipPath);
        if ($zipPath === '' || !is_readable($zipPath)) {
            $result['errors'][] = 'Plugin zip file is missing or not readable.';

            return $result;
        }

        $size = @filesize($zipPath);
        if (!is_int($size) || $size < 1) {
            $result['errors'][] = 'Plugin zip file is empty.';

            return $result;
        }

        $max = self::maxUploadBytes();
        if ($size > $max) {
            $result['errors'][] = 'Plugin zip exceeds the maximum size of ' . self::formatBytes($max) . '.';

            return $result;
        }

        $fh = @fopen($zipPath, 'rb');
        if ($fh === false) {
            $result['errors'][] = 'Could not open the plugin zip file.';

            return $result;
        }
        $magic = (string) fread($fh, 4);
        fclose($fh);
        if ($magic === '' || ($magic[0] !== 'P' || $magic[1] !== 'K')) {
            $result['errors'][] = 'File is not a valid zip archive.';

            return $result;
        }

        $zip = new ZipArchive();
        $open = $zip->open($zipPath, ZipArchive::RDONLY);
        if ($open !== true) {
            $open = $zip->open($zipPath);
        }
        if ($open !== true) {
            $result['errors'][] = 'Could not open the zip archive (corrupt or unsupported).';

            return $result;
        }

        try {
            $layout = self::detectPluginRootInZip($zip);
            if ($layout['error'] !== '') {
                $result['errors'][] = $layout['error'];

                return $result;
            }

            $zipRoot = $layout['zip_root'];
            $mainRel = $layout['main_rel'];
            $isFolder = $layout['is_folder'];
            $suggestedSlug = $layout['slug'];

            if (!empty($args['slug']) && is_string($args['slug'])) {
                $slug = $isFolder
                    ? self::sanitizeSlug($args['slug'])
                    : self::sanitizeFileStem($args['slug']);
            } else {
                $slug = $isFolder
                    ? self::sanitizeSlug($suggestedSlug)
                    : self::sanitizeFileStem($suggestedSlug);
            }
            if ($slug === '') {
                $result['errors'][] = 'Could not determine a valid plugin directory or file name from the zip.';

                return $result;
            }

            $mainContents = self::zipReadFile($zip, $zipRoot . $mainRel);
            if ($mainContents === null || trim($mainContents) === '') {
                $result['errors'][] = 'The zip does not contain a readable plugin main file.';

                return $result;
            }

            $headers = self::parsePluginContents($mainContents);
            if ($headers === [] || trim((string) ($headers['Plugin Name'] ?? '')) === '') {
                $result['errors'][] = 'Plugin main file is missing a Plugin Name header.';

                return $result;
            }

            $scan = self::validateZipEntries($zip, $zipRoot);
            if ($scan['error'] !== '') {
                $result['errors'][] = $scan['error'];
                $result['headers'] = $headers;

                return $result;
            }

            $pluginsRoot = self::resolvePluginsRoot(
                isset($args['plugins_root']) && is_string($args['plugins_root'])
                    ? $args['plugins_root']
                    : null
            );
            if ($pluginsRoot === '' || !self::ensureDir($pluginsRoot)) {
                $result['errors'][] = 'Plugins directory is missing or not writable.';

                return $result;
            }

            if ($isFolder) {
                $dest = $pluginsRoot . '/' . $slug;
                $plugin = $slug . '/' . basename($mainRel);
                $exists = is_dir($dest) || is_file($dest);
            } else {
                $fileName = $slug . '.php';
                $dest = $pluginsRoot . '/' . $fileName;
                $plugin = $fileName;
                $exists = is_file($dest) || is_dir($dest);
            }

            $overwrite = !empty($args['overwrite']);
            if ($exists && !$overwrite) {
                $result['errors'][] = 'A plugin with the slug “' . $slug . '” is already installed. '
                    . 'Enable overwrite to replace it, or delete it first.';
                $result['headers'] = $headers;
                $result['plugin'] = $plugin;
                $result['slug'] = $slug;

                return $result;
            }

            if ($exists && $isFolder && is_file($dest)) {
                $result['errors'][] = 'Cannot install a folder plugin over an existing file named “' . $slug . '”.';
                $result['headers'] = $headers;

                return $result;
            }
            if ($exists && !$isFolder && is_dir($dest)) {
                $result['errors'][] = 'Cannot install a single-file plugin over an existing directory named “'
                    . $slug . '.php”.';
                $result['headers'] = $headers;

                return $result;
            }

            $tmpBase = self::tempDir('ap-plugin-install-');
            if ($tmpBase === null) {
                $result['errors'][] = 'Could not create a temporary directory for extraction.';

                return $result;
            }

            $tmpPlugin = $tmpBase . '/plugin';
            if (!self::ensureDir($tmpPlugin)) {
                self::removeDir($tmpBase);
                $result['errors'][] = 'Could not prepare temporary plugin directory.';

                return $result;
            }

            $extractError = self::extractPluginFiles($zip, $zipRoot, $tmpPlugin, $isFolder ? null : $mainRel);
            if ($extractError !== '') {
                self::removeDir($tmpBase);
                $result['errors'][] = $extractError;
                $result['headers'] = $headers;

                return $result;
            }

            $extractedMain = $tmpPlugin . '/' . $mainRel;
            if (!is_readable($extractedMain)) {
                self::removeDir($tmpBase);
                $result['errors'][] = 'Extraction failed: plugin main file missing after unpack.';

                return $result;
            }

            $extractedHeaders = self::parsePluginContents((string) file_get_contents($extractedMain));
            if (trim((string) ($extractedHeaders['Plugin Name'] ?? '')) === '') {
                self::removeDir($tmpBase);
                $result['errors'][] = 'Extraction failed: Plugin Name header missing after unpack.';

                return $result;
            }

            if ($exists && $overwrite) {
                $removed = $isFolder || is_dir($dest)
                    ? self::removeDir($dest)
                    : @unlink($dest);
                if (!$removed) {
                    self::removeDir($tmpBase);
                    $result['errors'][] = 'Could not remove the existing plugin for overwrite.';
                    $result['plugin'] = $plugin;
                    $result['slug'] = $slug;

                    return $result;
                }
                $result['overwritten'] = true;
            }

            if ($isFolder) {
                if (!@rename($tmpPlugin, $dest)) {
                    if (!self::copyDir($tmpPlugin, $dest)) {
                        self::removeDir($tmpBase);
                        $result['errors'][] = 'Could not install the plugin into the plugins directory.';
                        $result['plugin'] = $plugin;
                        $result['slug'] = $slug;

                        return $result;
                    }
                    self::removeDir($tmpPlugin);
                }
            } else {
                if (!@rename($extractedMain, $dest)) {
                    if (!@copy($extractedMain, $dest)) {
                        self::removeDir($tmpBase);
                        $result['errors'][] = 'Could not install the plugin file into the plugins directory.';
                        $result['plugin'] = $plugin;
                        $result['slug'] = $slug;

                        return $result;
                    }
                    @unlink($extractedMain);
                }
            }

            self::removeDir($tmpBase);

            $installedPath = $isFolder ? $dest . '/' . basename($mainRel) : $dest;
            $installedHeaders = $extractedHeaders;
            if (is_readable($installedPath)) {
                if (class_exists('AP_Plugin', false)) {
                    $fromDisk = AP_Plugin::parsePluginFile($installedPath);
                } else {
                    $fromDisk = self::parsePluginContents((string) file_get_contents($installedPath));
                }
                if (is_array($fromDisk) && $fromDisk !== []) {
                    $installedHeaders = $fromDisk;
                }
            }
            $installedHeaders['File'] = $plugin;
            $installedHeaders['Slug'] = $slug;

            $result['ok'] = true;
            $result['plugin'] = $plugin;
            $result['slug'] = $slug;
            $result['path'] = $dest;
            $result['headers'] = $installedHeaders;
            $result['is_folder'] = $isFolder;

            if (function_exists('ap_do_action')) {
                ap_do_action('ap_plugin_installed', $plugin, $result);
            }

            return $result;
        } finally {
            $zip->close();
        }
    }

    /**
     * Handle a single $_FILES-style zip upload and install the plugin.
     *
     * $file keys: name, type, tmp_name, error, size (PHP upload array shape).
     *
     * @param array<string, mixed> $file
     * @param array{
     *   overwrite?: bool,
     *   plugins_root?: string,
     *   slug?: string,
     *   test_mode?: bool
     * } $args
     *
     * @return array{
     *   ok: bool,
     *   plugin: string,
     *   slug: string,
     *   path: string,
     *   headers: array<string, string>,
     *   is_folder: bool,
     *   overwritten: bool,
     *   errors: list<string>,
     *   warnings: list<string>
     * }
     */
    public static function handleUpload(array $file, array $args = []): array
    {
        $result = self::emptyResult();

        $errorCode = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($errorCode !== UPLOAD_ERR_OK) {
            $result['errors'][] = self::uploadErrorMessage($errorCode);

            return $result;
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        $origName = (string) ($file['name'] ?? '');
        $size = (int) ($file['size'] ?? 0);

        if ($tmpName === '' || $origName === '') {
            $result['errors'][] = 'No plugin file was uploaded.';

            return $result;
        }

        $testMode = !empty($args['test_mode']);
        if (!$testMode && !is_uploaded_file($tmpName)) {
            $result['errors'][] = 'Invalid upload source.';

            return $result;
        }
        if ($testMode && !is_readable($tmpName)) {
            $result['errors'][] = 'Upload temporary file is not readable.';

            return $result;
        }

        if ($size < 1) {
            $detected = @filesize($tmpName);
            $size = is_int($detected) ? $detected : 0;
        }
        if ($size < 1) {
            $result['errors'][] = 'Uploaded file is empty.';

            return $result;
        }

        $max = self::maxUploadBytes();
        if ($size > $max) {
            $result['errors'][] = 'Plugin zip exceeds the maximum size of ' . self::formatBytes($max) . '.';

            return $result;
        }

        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        if ($ext !== 'zip') {
            $result['errors'][] = 'Plugin packages must be a .zip file.';

            return $result;
        }

        if (function_exists('finfo_open')) {
            $finfo = @finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $mime = (string) finfo_file($finfo, $tmpName);
                finfo_close($finfo);
                $allowed = [
                    'application/zip',
                    'application/x-zip-compressed',
                    'application/octet-stream',
                    'multipart/x-zip',
                ];
                if ($mime !== '' && !in_array($mime, $allowed, true)) {
                    $result['errors'][] = 'Uploaded file does not look like a zip archive (detected ' . $mime . ').';

                    return $result;
                }
            }
        }

        return self::installFromZip($tmpName, $args);
    }

    /**
     * Delete an installed plugin file or folder (not an active plugin).
     *
     * @return array{ok: bool, plugin: string, errors: list<string>}
     */
    public static function deletePlugin(string $plugin, ?AP_DB $db = null): array
    {
        $plugin = self::normalizePlugin($plugin);
        if ($plugin === '') {
            return ['ok' => false, 'plugin' => '', 'errors' => ['No plugin specified.']];
        }

        if (class_exists('AP_Plugin', false) && AP_Plugin::isActive($plugin, $db)) {
            return [
                'ok' => false,
                'plugin' => $plugin,
                'errors' => ['Cannot delete an active plugin. Deactivate it first.'],
            ];
        }

        $root = self::resolvePluginsRoot(null);
        if (str_contains($plugin, '/')) {
            $target = $root . '/' . dirname($plugin);
        } else {
            $target = $root . '/' . $plugin;
        }

        if (!file_exists($target)) {
            return ['ok' => false, 'plugin' => $plugin, 'errors' => ['That plugin is not installed.']];
        }

        $realTarget = realpath($target);
        $realRoot = realpath($root);
        if (
            $realTarget === false
            || $realRoot === false
            || ($realTarget !== $realRoot && !str_starts_with($realTarget, $realRoot . DIRECTORY_SEPARATOR))
        ) {
            return ['ok' => false, 'plugin' => $plugin, 'errors' => ['Invalid plugin path.']];
        }

        $removed = is_dir($target) ? self::removeDir($target) : @unlink($target);
        if (!$removed) {
            return ['ok' => false, 'plugin' => $plugin, 'errors' => ['Could not remove the plugin.']];
        }

        if (function_exists('ap_do_action')) {
            ap_do_action('ap_plugin_deleted', $plugin);
        }

        return ['ok' => true, 'plugin' => $plugin, 'errors' => []];
    }

    /**
     * Maximum allowed plugin zip size in bytes.
     */
    public static function maxUploadBytes(): int
    {
        $cap = self::DEFAULT_MAX_BYTES;
        if (
            defined('AP_MAX_PLUGIN_UPLOAD_BYTES')
            && is_int(AP_MAX_PLUGIN_UPLOAD_BYTES)
            && AP_MAX_PLUGIN_UPLOAD_BYTES > 0
        ) {
            $cap = (int) AP_MAX_PLUGIN_UPLOAD_BYTES;
        } elseif (defined('AP_MAX_UPLOAD_BYTES') && is_int(AP_MAX_UPLOAD_BYTES) && AP_MAX_UPLOAD_BYTES > 0) {
            $cap = max($cap, (int) AP_MAX_UPLOAD_BYTES);
        }

        $phpLimits = [
            self::iniBytes((string) ini_get('upload_max_filesize')),
            self::iniBytes((string) ini_get('post_max_size')),
        ];
        foreach ($phpLimits as $limit) {
            if ($limit > 0 && $limit < $cap) {
                $cap = $limit;
            }
        }

        return max(1, $cap);
    }

    /**
     * Human-readable byte size.
     */
    public static function formatBytes(int $bytes): string
    {
        $bytes = max(0, $bytes);
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $value = (float) $bytes;
        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }

        return ($i === 0 ? (string) (int) $value : number_format($value, $value >= 10 ? 0 : 1))
            . ' ' . $units[$i];
    }

    // -------------------------------------------------------------------------
    // Zip helpers
    // -------------------------------------------------------------------------

    /**
     * Find the plugin root inside a zip (PHP file with a Plugin Name header).
     *
     * Supports:
     * - plugin.php at archive root (single-file)
     * - one top-level folder containing a Plugin Name PHP file
     *
     * @return array{zip_root: string, main_rel: string, slug: string, is_folder: bool, error: string}
     */
    private static function detectPluginRootInZip(ZipArchive $zip): array
    {
        $candidates = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (!is_array($stat)) {
                continue;
            }
            $name = str_replace('\\', '/', (string) ($stat['name'] ?? ''));
            if ($name === '' || str_ends_with($name, '/')) {
                continue;
            }
            if (str_contains($name, '..') || str_starts_with($name, '/') || preg_match('#^[A-Za-z]:/#', $name) === 1) {
                return [
                    'zip_root' => '',
                    'main_rel' => '',
                    'slug' => '',
                    'is_folder' => false,
                    'error' => 'Zip contains unsafe paths and was rejected.',
                ];
            }
            if (str_starts_with($name, '__MACOSX/') || str_contains($name, '/.DS_Store')) {
                continue;
            }
            if (!str_ends_with(strtolower($name), '.php')) {
                continue;
            }

            $chunk = self::zipReadFile($zip, $name);
            if ($chunk === null || !self::phpHasPluginName($chunk)) {
                continue;
            }
            $candidates[] = $name;
        }

        if ($candidates === []) {
            return [
                'zip_root' => '',
                'main_rel' => '',
                'slug' => '',
                'is_folder' => false,
                'error' => 'No plugin main file found in the zip. '
                    . 'Packages must include a PHP file with a Plugin Name header.',
            ];
        }

        usort(
            $candidates,
            static function (string $a, string $b): int {
                $da = substr_count($a, '/');
                $db = substr_count($b, '/');
                if ($da !== $db) {
                    return $da <=> $db;
                }

                return strcmp($a, $b);
            }
        );

        $depth = substr_count($candidates[0], '/');
        $atDepth = array_values(array_filter(
            $candidates,
            static fn (string $p): bool => substr_count($p, '/') === $depth
        ));

        if ($depth === 0) {
            if (count($atDepth) > 1) {
                return [
                    'zip_root' => '',
                    'main_rel' => '',
                    'slug' => '',
                    'is_folder' => false,
                    'error' => 'Zip contains more than one plugin file at the archive root. Package a single plugin.',
                ];
            }
            $chosen = $atDepth[0];
            $slug = self::sanitizeFileStem(pathinfo($chosen, PATHINFO_FILENAME));
            if ($slug === '') {
                $headers = self::parsePluginContents((string) self::zipReadFile($zip, $chosen));
                $slug = self::slugFromHeaders($headers, 'plugin');
            }

            return [
                'zip_root' => '',
                'main_rel' => $chosen,
                'slug' => $slug,
                'is_folder' => false,
                'error' => '',
            ];
        }

        if ($depth === 1) {
            $folders = [];
            foreach ($atDepth as $path) {
                $folder = dirname($path);
                if ($folder === '.' || $folder === '' || $folder === '/') {
                    continue;
                }
                $folders[$folder][] = $path;
            }
            if (count($folders) !== 1) {
                return [
                    'zip_root' => '',
                    'main_rel' => '',
                    'slug' => '',
                    'is_folder' => false,
                    'error' => 'Zip contains more than one plugin folder. Package a single plugin.',
                ];
            }
            $folder = array_key_first($folders);
            $files = $folders[$folder];
            $chosen = self::preferMatchingMainFile($folder, $files);
            $slug = self::sanitizeSlug(basename($folder));
            if ($slug === '') {
                $headers = self::parsePluginContents((string) self::zipReadFile($zip, $chosen));
                $slug = self::slugFromHeaders($headers, 'plugin');
            }

            return [
                'zip_root' => rtrim(str_replace('\\', '/', $folder), '/') . '/',
                'main_rel' => basename($chosen),
                'slug' => $slug,
                'is_folder' => true,
                'error' => '',
            ];
        }

        return [
            'zip_root' => '',
            'main_rel' => '',
            'slug' => '',
            'is_folder' => false,
            'error' => 'Plugin main file is nested too deeply in the zip. Package the plugin as '
                . 'plugin-name/plugin-name.php (or a single .php file at the zip root).',
        ];
    }

    /**
     * @param list<string> $files
     */
    private static function preferMatchingMainFile(string $folder, array $files): string
    {
        $want = strtolower(basename($folder)) . '.php';
        foreach ($files as $path) {
            if (strtolower(basename($path)) === $want) {
                return $path;
            }
        }

        return $files[0];
    }

    /**
     * @return array{error: string, file_count: int}
     */
    private static function validateZipEntries(ZipArchive $zip, string $zipRoot): array
    {
        $prefix = $zipRoot;
        $fileCount = 0;
        $totalUncompressed = 0;
        $maxFiles = 5000;
        $maxUncompressed = self::maxUploadBytes() * 20;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (!is_array($stat)) {
                continue;
            }
            $name = str_replace('\\', '/', (string) ($stat['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            if (str_contains($name, '..') || str_starts_with($name, '/') || preg_match('#^[A-Za-z]:/#', $name) === 1) {
                return ['error' => 'Zip contains unsafe paths and was rejected.', 'file_count' => $fileCount];
            }
            if (str_starts_with($name, '__MACOSX/') || str_contains($name, '/.DS_Store')) {
                continue;
            }
            if ($prefix !== '' && !str_starts_with($name, $prefix)) {
                continue;
            }
            if (str_ends_with($name, '/')) {
                continue;
            }

            $rel = $prefix !== '' ? substr($name, strlen($prefix)) : $name;
            if ($rel === false || $rel === '' || str_contains($rel, '..')) {
                return ['error' => 'Zip contains unsafe relative paths.', 'file_count' => $fileCount];
            }

            $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
            $blocked = ['php3', 'php4', 'php5', 'phtml', 'phar', 'cgi', 'pl', 'py', 'exe', 'sh', 'bat', 'cmd'];
            if (in_array($ext, $blocked, true)) {
                return [
                    'error' => 'Zip contains disallowed file type “.' . $ext . '”.',
                    'file_count' => $fileCount,
                ];
            }

            $fileCount++;
            $totalUncompressed += (int) ($stat['size'] ?? 0);
            if ($fileCount > $maxFiles) {
                return ['error' => 'Plugin zip contains too many files.', 'file_count' => $fileCount];
            }
            if ($totalUncompressed > $maxUncompressed) {
                return ['error' => 'Plugin zip uncompressed size is too large.', 'file_count' => $fileCount];
            }
        }

        if ($fileCount < 1) {
            return ['error' => 'Plugin zip has no extractable files under the plugin root.', 'file_count' => 0];
        }

        return ['error' => '', 'file_count' => $fileCount];
    }

    private static function extractPluginFiles(
        ZipArchive $zip,
        string $zipRoot,
        string $destDir,
        ?string $onlyRel
    ): string {
        $prefix = $zipRoot;
        $destDir = rtrim($destDir, '/\\');

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (!is_array($stat)) {
                continue;
            }
            $name = str_replace('\\', '/', (string) ($stat['name'] ?? ''));
            if ($name === '' || str_ends_with($name, '/')) {
                continue;
            }
            if (str_starts_with($name, '__MACOSX/') || str_contains($name, '/.DS_Store')) {
                continue;
            }
            if ($prefix !== '' && !str_starts_with($name, $prefix)) {
                continue;
            }

            $rel = $prefix !== '' ? substr($name, strlen($prefix)) : $name;
            if ($rel === false || $rel === '' || str_contains($rel, '..')) {
                return 'Zip contains unsafe relative paths.';
            }
            if ($onlyRel !== null && $rel !== $onlyRel) {
                continue;
            }

            $target = $destDir . '/' . $rel;
            $targetDir = dirname($target);
            if (!self::ensureDir($targetDir)) {
                return 'Could not create directory during extraction.';
            }

            $stream = $zip->getStream($name);
            if ($stream === false) {
                return 'Could not read “' . $rel . '” from the zip.';
            }
            $out = @fopen($target, 'wb');
            if ($out === false) {
                fclose($stream);

                return 'Could not write extracted file “' . $rel . '”.';
            }
            while (!feof($stream)) {
                $chunk = fread($stream, 65536);
                if ($chunk === false) {
                    fclose($stream);
                    fclose($out);

                    return 'Failed while reading zip entry “' . $rel . '”.';
                }
                if ($chunk !== '' && fwrite($out, $chunk) === false) {
                    fclose($stream);
                    fclose($out);

                    return 'Failed while writing “' . $rel . '”.';
                }
            }
            fclose($stream);
            fclose($out);
            @chmod($target, 0644);
        }

        return '';
    }

    private static function zipReadFile(ZipArchive $zip, string $path): ?string
    {
        $index = $zip->locateName($path);
        if ($index === false) {
            $index = $zip->locateName($path, ZipArchive::FL_NOCASE);
        }
        if ($index === false) {
            return null;
        }
        $data = $zip->getFromIndex($index);
        if ($data === false) {
            return null;
        }

        return (string) $data;
    }

    private static function phpHasPluginName(string $chunk): bool
    {
        if (strlen($chunk) > 8192) {
            $chunk = substr($chunk, 0, 8192);
        }

        return preg_match('/^[ \\t\\/*#@]*Plugin Name:[ \\t]*.+$/mi', $chunk) === 1;
    }

    /**
     * @return array<string, string>
     */
    public static function parsePluginContents(string $chunk): array
    {
        $known = [
            'Plugin Name',
            'Plugin URI',
            'Description',
            'Version',
            'Author',
            'Author URI',
            'License',
            'License URI',
            'Text Domain',
            'Domain Path',
            'Network',
            'Requires at least',
            'Requires PHP',
            'Requires Plugins',
            'Update URI',
        ];
        if (class_exists('AP_Plugin', false)) {
            $known = AP_Plugin::headerFields();
        }

        if (strlen($chunk) > 8192) {
            $chunk = substr($chunk, 0, 8192);
        }

        $headers = [];
        foreach ($known as $field) {
            $pattern = '/^[ \\t\\/*#@]*' . preg_quote($field, '/') . ':[ \\t]*(.*)$/mi';
            if (preg_match($pattern, $chunk, $m) === 1) {
                $headers[$field] = trim((string) $m[1]);
            }
        }

        return $headers;
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * @return array{
     *   ok: bool,
     *   plugin: string,
     *   slug: string,
     *   path: string,
     *   headers: array<string, string>,
     *   is_folder: bool,
     *   overwritten: bool,
     *   errors: list<string>,
     *   warnings: list<string>
     * }
     */
    private static function emptyResult(): array
    {
        return [
            'ok' => false,
            'plugin' => '',
            'slug' => '',
            'path' => '',
            'headers' => [],
            'is_folder' => false,
            'overwritten' => false,
            'errors' => [],
            'warnings' => [],
        ];
    }

    private static function resolvePluginsRoot(?string $override): string
    {
        if ($override !== null && $override !== '') {
            return rtrim($override, '/\\');
        }
        if (class_exists('AP_Plugin', false)) {
            return AP_Plugin::pluginsRoot();
        }
        if (defined('AP_PLUGIN_DIR')) {
            return (string) AP_PLUGIN_DIR;
        }
        if (defined('AP_CONTENT_DIR')) {
            return AP_CONTENT_DIR . '/plugins';
        }
        if (defined('AP_ABSPATH')) {
            return rtrim((string) AP_ABSPATH, '/\\') . '/ap-content/plugins';
        }

        return dirname(__DIR__) . '/ap-content/plugins';
    }

    private static function sanitizeSlug(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9_\\-]+/', '-', $value) ?? $value;
        $value = preg_replace('/\\-+/', '-', $value) ?? $value;

        return trim($value, '-');
    }

    private static function sanitizeFileStem(string $value): string
    {
        $value = pathinfo(trim($value), PATHINFO_FILENAME);
        if ($value === '') {
            $value = trim($value);
        }

        return self::sanitizeSlug($value);
    }

    /**
     * @param array<string, string> $headers
     */
    private static function slugFromHeaders(array $headers, string $fallback): string
    {
        $name = (string) ($headers['Plugin Name'] ?? '');
        $slug = self::sanitizeSlug($name);
        if ($slug === '') {
            $slug = self::sanitizeSlug($fallback);
        }

        return $slug !== '' ? $slug : 'plugin';
    }

    private static function normalizePlugin(string $plugin): string
    {
        $plugin = str_replace('\\', '/', trim($plugin));
        $plugin = ltrim($plugin, '/');
        $plugin = preg_replace('#/+#', '/', $plugin) ?? $plugin;
        if (
            $plugin === ''
            || str_contains($plugin, '..')
            || substr_count($plugin, '/') > 1
            || preg_match('#^[a-zA-Z0-9][a-zA-Z0-9_./\\-]*\\.php$#', $plugin) !== 1
        ) {
            return '';
        }

        return $plugin;
    }

    private static function ensureDir(string $path): bool
    {
        if (is_dir($path)) {
            return is_writable($path);
        }

        return @mkdir($path, 0755, true) || is_dir($path);
    }

    private static function tempDir(string $prefix): ?string
    {
        $base = rtrim(sys_get_temp_dir(), '/\\');
        $path = $base . '/' . $prefix . bin2hex(random_bytes(8));
        if (!@mkdir($path, 0700, true) && !is_dir($path)) {
            return null;
        }

        return $path;
    }

    private static function removeDir(string $dir): bool
    {
        if (!is_dir($dir)) {
            return true;
        }
        $entries = @scandir($dir);
        if (!is_array($entries)) {
            return false;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                if (!self::removeDir($path)) {
                    return false;
                }
            } elseif (!@unlink($path)) {
                return false;
            }
        }

        return @rmdir($dir);
    }

    private static function copyDir(string $src, string $dest): bool
    {
        if (!self::ensureDir($dest)) {
            return false;
        }
        $entries = @scandir($src);
        if (!is_array($entries)) {
            return false;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $from = $src . '/' . $entry;
            $to = $dest . '/' . $entry;
            if (is_dir($from) && !is_link($from)) {
                if (!self::copyDir($from, $to)) {
                    return false;
                }
            } elseif (!@copy($from, $to)) {
                return false;
            }
        }

        return true;
    }

    private static function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '0') {
            return 0;
        }
        $unit = strtolower(substr($value, -1));
        $num = $value;
        if (in_array($unit, ['g', 'm', 'k'], true)) {
            $num = substr($value, 0, -1);
        }
        $bytes = (float) $num;

        return match ($unit) {
            'g' => (int) ($bytes * 1024 * 1024 * 1024),
            'm' => (int) ($bytes * 1024 * 1024),
            'k' => (int) ($bytes * 1024),
            default => (int) $bytes,
        };
    }

    private static function uploadErrorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the maximum allowed size.',
            UPLOAD_ERR_PARTIAL => 'The file was only partially uploaded.',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder on the server.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write the file to disk.',
            UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload.',
            default => 'File upload failed.',
        };
    }
}
