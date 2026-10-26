<?php

/**
 * Plugin zip installer — upload AgoraPress / classic WordPress plugins.
 *
 * Accepts a .zip containing a Plugin Name header in a PHP file at the archive
 * root (single-file plugin) or one level down (folder/plugin.php).
 *
 * Zip entries are extracted only into a private staging directory under the
 * content directory (`{content}/.ap-plugin-staging/`, mode 0700). They are
 * never extracted directly into the plugins directory. `ZipArchive::extractTo()`
 * is not used. After a successful install the staged payload is moved into
 * the plugins directory; that move is not an extraction.
 *
 * {@see AP_Plugin_Installer::validate()} inspects a zip and returns either
 * {@see AP_Plugin_Install_Result} or {@see AP_Plugin_Install_Error}. It does
 * not write into the plugins directory. It rejects empty files, files over
 * the filterable 20 MiB cap, and files that do not start with a zip signature.
 * An optional `expected_sha256` is compared to the file bytes before the
 * archive is opened, so a mismatch never extracts or inflates entries.
 * Entry names that are absolute, contain a `..` segment, a backslash, a drive
 * letter, or a NUL byte are rejected. Every other name is resolved under a
 * private staging directory and must stay inside that directory's realpath.
 * A package that passes those checks is unpacked into that private staging
 * directory, the unpacked realpaths are checked again, and the directory is
 * removed before validate returns.
 * Symlinks and other special files are rejected from the central-directory
 * external attributes. Entry count and declared uncompressed size are capped
 * (filters `ap_plugin_zip_max_entries` and
 * `ap_plugin_zip_max_uncompressed_bytes`) before any entry is inflated.
 * The archive must be one plugin: a single top-level folder mapped to a
 * lowercase slug, or a flat zip whose main file name maps to a slug. The main
 * PHP file must include Plugin Name and Version headers. Zips shaped like a
 * theme (`style.css` with Theme Name) or like AgoraPress / WordPress core
 * are rejected.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

require_once __DIR__ . '/class-ap-plugin-install-result.php';

/**
 * Install plugins from zip archives (admin upload + programmatic).
 */
class AP_Plugin_Installer
{
    /** Default max plugin zip size (40 MiB) when not overridden. */
    public const DEFAULT_MAX_BYTES = 41943040;

    /**
     * Default maximum size for {@see self::validate()} (20 MiB).
     *
     * Filter `ap_plugin_zip_max_bytes` may replace this with a positive
     * integer. {@see self::DEFAULT_MAX_BYTES} still caps the legacy upload path.
     */
    public const DEFAULT_VALIDATE_MAX_BYTES = 20971520;

    /**
     * Default maximum central-directory entries for {@see self::validate()}.
     *
     * Directory entries count. Filter `ap_plugin_zip_max_entries` may replace
     * this with a positive integer.
     */
    public const DEFAULT_MAX_ENTRIES = 5000;

    /**
     * Default maximum total declared uncompressed size for {@see self::validate()} (128 MiB).
     *
     * This is the sum of central-directory sizes, not the zip file size.
     * Filter `ap_plugin_zip_max_uncompressed_bytes` may replace this with a
     * positive integer.
     */
    public const DEFAULT_MAX_UNCOMPRESSED_BYTES = 134217728;

    private const UNSAFE_PATH_MESSAGE = 'Zip contains unsafe paths and was rejected.';

    private const SYMLINK_MESSAGE = 'Plugin zip contains a symbolic link and was rejected.';

    private const SPECIAL_FILE_MESSAGE =
        'Plugin zip contains a special file (device, fifo, or socket) and was rejected.';

    /**
     * Hidden content-directory parent for plugin zip extraction.
     *
     * Each attempt creates `ap-plugin-stage-{id}` inside this directory and
     * removes it before returning. The plugins directory is never this path.
     */
    private const STAGING_PARENT_NAME = '.ap-plugin-staging';

    private const STAGING_HTACCESS = <<<'HTACCESS'
# Private plugin zip staging. Do not serve these files.
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    Order deny,allow
    Deny from all
</IfModule>
HTACCESS;


    /**
     * Lowercase plugin folder or flat-file slug: letters, numbers, hyphens, underscores.
     */
    private const SLUG_PATTERN = '/^[a-z0-9]+(?:[_-][a-z0-9]+)*$/';

    /** Unix file-type mask (S_IFMT) in the high 16 bits of a zip external attribute. */
    private const UNIX_S_IFMT = 0170000;

    private const UNIX_S_IFREG = 0100000;

    private const UNIX_S_IFDIR = 0040000;

    private const UNIX_S_IFLNK = 0120000;

    /**
     * Validate a plugin zip without installing it.
     *
     * Success carries slug, name, version, the action install would take
     * (`installed` or `upgraded`), and the previous version when that slug is
     * already on disk. Failure is a typed error code plus a human message.
     *
     * The file must be non-empty, at most {@see self::maxValidateBytes()}
     * (default 20 MiB, filter `ap_plugin_zip_max_bytes`), and start with a zip
     * signature. The file name extension is ignored.
     *
     * Zip entry names are rejected when they are absolute, contain a `..`
     * segment, a backslash, a drive letter (`C:` or `C:/`), or a NUL byte.
     * Central-directory names and local-header names are both read from the
     * archive bytes, so a NUL that ZipArchive truncates is still visible.
     * Backslashes are rejected rather than rewritten as slashes. Each accepted
     * name is resolved under a private staging directory and the realpath of
     * that destination must stay inside the staging directory. The staging
     * directory is removed before this method returns.
     *
     * External attributes are read next. A Unix symlink mode is rejected, as
     * is any other Unix type that is not a regular file or a directory
     * (fifo, character device, block device, socket, or an unknown type).
     * The high 16 bits are honored even when the host id is not Unix, so a
     * DOS host id cannot hide a symlink mode. The archive may contain at most
     * {@see self::maxValidateEntries()} entries (default
     * {@see self::DEFAULT_MAX_ENTRIES}, filter `ap_plugin_zip_max_entries`).
     * The sum of declared uncompressed sizes may be at most
     * {@see self::maxValidateUncompressedBytes()} (default 128 MiB, filter
     * `ap_plugin_zip_max_uncompressed_bytes`). Both caps use central-directory
     * metadata and run before any entry is inflated.
     *
     * When `expected_sha256` is a non-empty string it is checked against the
     * file before the archive is opened. A blank value skips the check. The
     * digest is 64 hex characters, compared case-insensitively, and may use a
     * `sha256:` prefix. A mismatch or an unusable digest returns
     * {@see AP_Plugin_Install_Error::SHA256_MISMATCH}.
     *
     * Structure is checked after the archive limits. A plugin zip has exactly
     * one top-level folder, mapped to a lowercase slug, or it is flat (files
     * only at the archive root) and the main file name is mapped to a slug.
     * A name that does not map to a slug is rejected. The main PHP file must
     * include Plugin Name and Version.
     * Junk such as `__MACOSX/` and `.DS_Store` is ignored. A `style.css` with
     * a Theme Name header at the package root is a theme, not a plugin.
     * AgoraPress (`ap-includes/version.php`, `index.php`, and `ap-admin/`)
     * and WordPress (`wp-admin/` plus `wp-includes/`) packages are rejected
     * even when a plugin header is nested inside them.
     *
     * A package that passes every check is extracted into a private staging
     * directory under the content directory. The destination is
     * `{content}/.ap-plugin-staging/ap-plugin-stage-{id}/plugin`, mode 0700,
     * and it is never the plugins directory. Unpacked realpaths must stay
     * inside that staging directory. The staging directory is removed before
     * this method returns, whether extraction succeeds or fails.
     *
     * `overwrite` and `activate` are not applied here. `plugins_root` selects
     * the directory used to detect an existing slug (defaults to the plugins
     * directory). `content_dir`, when set, is the content directory that owns
     * the private staging directory. It defaults to the parent of the plugins
     * directory.
     *
     * @param array{
     *   overwrite?: bool,
     *   activate?: bool,
     *   expected_sha256?: string,
     *   plugins_root?: string,
     *   content_dir?: string
     * } $opts
     */
    public static function validate(string $zipPath, array $opts = []): AP_Plugin_Install_Result|AP_Plugin_Install_Error
    {
        if ($zipPath === '' || !is_file($zipPath) || !is_readable($zipPath)) {
            return AP_Plugin_Install_Error::make(
                AP_Plugin_Install_Error::MISSING_FILE,
                'Plugin zip file is missing or not readable.'
            );
        }

        $size = @filesize($zipPath);
        if (!is_int($size) || $size < 1) {
            return AP_Plugin_Install_Error::make(
                AP_Plugin_Install_Error::EMPTY,
                'Plugin zip file is empty.'
            );
        }

        $max = self::maxValidateBytes($zipPath);
        if ($size > $max) {
            return AP_Plugin_Install_Error::make(
                AP_Plugin_Install_Error::OVERSIZE,
                'Plugin zip exceeds the maximum size of ' . self::formatBytes($max) . '.'
            );
        }

        $prefix = self::readFilePrefix($zipPath, 4);
        if ($prefix === null) {
            return AP_Plugin_Install_Error::make(
                AP_Plugin_Install_Error::NOT_ZIP,
                'Could not read the plugin zip file.'
            );
        }
        if (!self::hasZipMagic($prefix)) {
            return AP_Plugin_Install_Error::make(
                AP_Plugin_Install_Error::NOT_ZIP,
                'File is not a zip archive (missing PK zip signature).'
            );
        }

        $checksum = self::expectedSha256Error($zipPath, $opts['expected_sha256'] ?? null);
        if ($checksum !== null) {
            return $checksum;
        }

        if (!class_exists('ZipArchive', false)) {
            return AP_Plugin_Install_Error::make(
                AP_Plugin_Install_Error::ZIP_EXTENSION_MISSING,
                'The PHP ZipArchive extension is required to validate plugin zip files.'
            );
        }

        $pluginsRoot = self::resolvePluginsRoot(
            isset($opts['plugins_root']) && is_string($opts['plugins_root'])
                ? $opts['plugins_root']
                : null
        );

        $zip = new ZipArchive();
        $open = $zip->open($zipPath, ZipArchive::RDONLY);
        if ($open !== true) {
            $open = $zip->open($zipPath);
        }
        if ($open !== true) {
            return AP_Plugin_Install_Error::make(
                AP_Plugin_Install_Error::NOT_ZIP,
                'File is not a valid zip archive.'
            );
        }

        try {
            $unsafe = self::rejectUnsafeArchivePaths($zip, $zipPath);
            if ($unsafe !== null) {
                return $unsafe;
            }

            $limits = self::rejectArchiveLimits($zip, $zipPath);
            if ($limits !== null) {
                return $limits;
            }

            $layout = self::inspectPackageStructure($zip);
            if ($layout instanceof AP_Plugin_Install_Error) {
                return $layout;
            }

            $isFolder = $layout['is_folder'];
            $slug = $layout['slug'];
            if (!self::isValidPluginSlug($slug)) {
                return AP_Plugin_Install_Error::make(
                    AP_Plugin_Install_Error::INVALID_SLUG,
                    'Could not determine a valid plugin directory or file name from the zip.'
                );
            }

            $mainRel = $layout['main_rel'];
            $mainContents = self::zipReadFile($zip, $layout['zip_root'] . $mainRel);
            if ($mainContents === null || trim($mainContents) === '') {
                return AP_Plugin_Install_Error::make(
                    AP_Plugin_Install_Error::MISSING_HEADER,
                    'The zip does not contain a readable plugin main file.'
                );
            }

            $headers = self::parsePluginContents($mainContents);
            $name = trim((string) ($headers['Plugin Name'] ?? ''));
            $version = trim((string) ($headers['Version'] ?? ''));
            if ($name === '' || $version === '') {
                return AP_Plugin_Install_Error::make(
                    AP_Plugin_Install_Error::MISSING_HEADER,
                    'Plugin main file must include Plugin Name and Version headers.'
                );
            }

            $scan = self::validateZipEntries($zip, $layout['zip_root']);
            if ($scan['error'] !== '') {
                return self::errorFromMessage($scan['error']);
            }

            $plugin = $isFolder ? $slug . '/' . basename($mainRel) : $slug . '.php';
            $previous = self::lookupInstalledVersion($pluginsRoot, $plugin, $isFolder, $slug);
            $action = $previous['exists']
                ? AP_Plugin_Install_Result::ACTION_UPGRADED
                : AP_Plugin_Install_Result::ACTION_INSTALLED;

            $contentDir = self::resolveContentDir(
                isset($opts['content_dir']) && is_string($opts['content_dir']) ? $opts['content_dir'] : null,
                $pluginsRoot
            );
            $staged = self::extractIntoPrivateStaging(
                $zip,
                $layout['zip_root'],
                $isFolder ? null : $mainRel,
                $contentDir,
                $pluginsRoot
            );
            if ($staged instanceof AP_Plugin_Install_Error) {
                return $staged;
            }

            $extractedMain = $staged['payload'] . '/' . $mainRel;
            $unpacked = is_file($extractedMain) && !is_link($extractedMain);
            if (!$unpacked || !self::discardStagingDir($staged['stage'])) {
                self::discardStagingDir($staged['stage']);

                return AP_Plugin_Install_Error::make(
                    AP_Plugin_Install_Error::STAGING_FAILED,
                    $unpacked
                        ? 'Could not remove the private plugin staging directory.'
                        : 'Extraction failed: plugin main file missing after unpack.'
                );
            }

            return AP_Plugin_Install_Result::success(
                $slug,
                $name,
                $version,
                $previous['version'],
                $action,
                null,
                false,
                $plugin
            );
        } finally {
            $zip->close();
        }
    }

    /**
     * Install a plugin from a filesystem path to a zip archive.
     *
     * `expected_sha256`, when a non-empty string, is verified against the zip
     * bytes before the archive is opened. A mismatch does not extract files.
     * Extraction writes only under a private staging directory in the content
     * directory (`content_dir`, or the parent of the plugins directory). The
     * staged payload is then moved into the plugins directory.
     *
     * @param array{
     *   overwrite?: bool,
     *   plugins_root?: string,
     *   content_dir?: string,
     *   slug?: string,
     *   expected_sha256?: string
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

        $checksum = self::expectedSha256Error($zipPath, $args['expected_sha256'] ?? null);
        if ($checksum !== null) {
            $result['errors'][] = $checksum->message();

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
            $unsafe = self::rejectUnsafeArchivePaths($zip, $zipPath);
            if ($unsafe !== null) {
                $result['errors'][] = $unsafe->message();

                return $result;
            }

            $limits = self::rejectArchiveLimits($zip, $zipPath);
            if ($limits !== null) {
                $result['errors'][] = $limits->message();

                return $result;
            }

            $layout = self::inspectPackageStructure($zip);
            if ($layout instanceof AP_Plugin_Install_Error) {
                $result['errors'][] = $layout->message();

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

            $contentDir = self::resolveContentDir(
                isset($args['content_dir']) && is_string($args['content_dir']) ? $args['content_dir'] : null,
                $pluginsRoot
            );
            $staged = self::extractIntoPrivateStaging(
                $zip,
                $zipRoot,
                $isFolder ? null : $mainRel,
                $contentDir,
                $pluginsRoot
            );
            if ($staged instanceof AP_Plugin_Install_Error) {
                $result['errors'][] = $staged->message();
                $result['headers'] = $headers;

                return $result;
            }

            $tmpBase = $staged['stage'];
            $tmpPlugin = $staged['payload'];

            $extractedMain = $tmpPlugin . '/' . $mainRel;
            if (!is_readable($extractedMain)) {
                self::discardStagingDir($tmpBase);
                $result['errors'][] = 'Extraction failed: plugin main file missing after unpack.';

                return $result;
            }

            $extractedHeaders = self::parsePluginContents((string) file_get_contents($extractedMain));
            if (trim((string) ($extractedHeaders['Plugin Name'] ?? '')) === '') {
                self::discardStagingDir($tmpBase);
                $result['errors'][] = 'Extraction failed: Plugin Name header missing after unpack.';

                return $result;
            }

            if ($exists && $overwrite) {
                $removed = $isFolder || is_dir($dest)
                    ? self::removeDir($dest)
                    : @unlink($dest);
                if (!$removed) {
                    self::discardStagingDir($tmpBase);
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
                        self::discardStagingDir($tmpBase);
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
                        self::discardStagingDir($tmpBase);
                        $result['errors'][] = 'Could not install the plugin file into the plugins directory.';
                        $result['plugin'] = $plugin;
                        $result['slug'] = $slug;

                        return $result;
                    }
                    @unlink($extractedMain);
                }
            }

            self::discardStagingDir($tmpBase);

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
     * Maximum zip size in bytes for {@see self::validate()}.
     *
     * Starts at {@see self::DEFAULT_VALIDATE_MAX_BYTES} (20 MiB). The
     * `ap_plugin_zip_max_bytes` filter receives that cap and the zip path and
     * may return a positive integer, or a digit string, to replace it. Any
     * other return value leaves the default in place. This cap is not tied to
     * PHP `upload_max_filesize`. {@see self::maxUploadBytes()} still bounds
     * the legacy upload path.
     */
    public static function maxValidateBytes(string $zipPath = ''): int
    {
        $default = self::DEFAULT_VALIDATE_MAX_BYTES;
        if (!function_exists('ap_apply_filters')) {
            return $default;
        }

        return self::positiveIntOrDefault(
            ap_apply_filters('ap_plugin_zip_max_bytes', $default, $zipPath),
            $default
        );
    }

    /**
     * Maximum central-directory entries for {@see self::validate()}.
     *
     * Starts at {@see self::DEFAULT_MAX_ENTRIES}. The `ap_plugin_zip_max_entries`
     * filter receives that cap and the zip path and may return a positive
     * integer, or a digit string, to replace it. Any other return value leaves
     * the default in place. Directory entries count toward the cap.
     */
    public static function maxValidateEntries(string $zipPath = ''): int
    {
        $default = self::DEFAULT_MAX_ENTRIES;
        if (!function_exists('ap_apply_filters')) {
            return $default;
        }

        return self::positiveIntOrDefault(
            ap_apply_filters('ap_plugin_zip_max_entries', $default, $zipPath),
            $default
        );
    }

    /**
     * Maximum total declared uncompressed size in bytes for {@see self::validate()}.
     *
     * Starts at {@see self::DEFAULT_MAX_UNCOMPRESSED_BYTES} (128 MiB). The
     * `ap_plugin_zip_max_uncompressed_bytes` filter receives that cap and the
     * zip path and may return a positive integer, or a digit string, to replace
     * it. Any other return value leaves the default in place. The measured
     * size is the sum of central-directory uncompressed sizes, not inflated
     * bytes and not the zip file size.
     */
    public static function maxValidateUncompressedBytes(string $zipPath = ''): int
    {
        $default = self::DEFAULT_MAX_UNCOMPRESSED_BYTES;
        if (!function_exists('ap_apply_filters')) {
            return $default;
        }

        return self::positiveIntOrDefault(
            ap_apply_filters('ap_plugin_zip_max_uncompressed_bytes', $default, $zipPath),
            $default
        );
    }

    /**
     * Keep a positive int, or a digit string that round-trips to that int.
     *
     * Zero, negatives, blanks, and other types leave $default in place.
     */
    private static function positiveIntOrDefault(mixed $filtered, int $default): int
    {
        if (is_int($filtered) && $filtered > 0) {
            return $filtered;
        }
        if (is_string($filtered) && preg_match('/\A[1-9][0-9]*\z/', $filtered) === 1) {
            $parsed = (int) $filtered;
            if ($parsed > 0 && (string) $parsed === $filtered) {
                return $parsed;
            }
        }

        return $default;
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
     * Read the first bytes of a file, or null when it cannot be read.
     */
    private static function readFilePrefix(string $path, int $length): ?string
    {
        if ($length < 1) {
            return '';
        }

        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }

        $prefix = fread($handle, $length);
        fclose($handle);
        if (!is_string($prefix)) {
            return null;
        }

        return $prefix;
    }

    /**
     * Whether $prefix begins with a ZIP local, empty-archive, or spanned signature.
     *
     * The check uses magic bytes only. The file name extension is irrelevant.
     * Accepted signatures: PK\x03\x04, PK\x05\x06, PK\x07\x08.
     */
    private static function hasZipMagic(string $prefix): bool
    {
        if (strlen($prefix) < 4) {
            return false;
        }

        $signature = substr($prefix, 0, 4);

        return $signature === "PK\x03\x04"
            || $signature === "PK\x05\x06"
            || $signature === "PK\x07\x08";
    }

    /**
     * Compare an optional expected SHA-256 to the zip file before it is opened.
     *
     * A missing, non-string, or blank value skips the check. Otherwise the
     * value is trimmed, lowercased, and may start with `sha256:`. It must then
     * be 64 hex characters. The file is hashed only after that shape check.
     *
     * @return AP_Plugin_Install_Error|null Null when the check is skipped or the digest matches.
     */
    private static function expectedSha256Error(string $zipPath, mixed $expected): ?AP_Plugin_Install_Error
    {
        if (!is_string($expected)) {
            return null;
        }

        $digest = strtolower(trim($expected));
        if ($digest === '') {
            return null;
        }
        if (str_starts_with($digest, 'sha256:')) {
            $digest = trim(substr($digest, 7));
        }
        if (preg_match('/\A[a-f0-9]{64}\z/', $digest) !== 1) {
            return AP_Plugin_Install_Error::make(
                AP_Plugin_Install_Error::SHA256_MISMATCH,
                'The expected SHA-256 checksum is not a 64-character hex digest.'
            );
        }

        $actual = hash_file('sha256', $zipPath);
        if (!is_string($actual) || preg_match('/\A[a-f0-9]{64}\z/', $actual) !== 1) {
            return AP_Plugin_Install_Error::make(
                AP_Plugin_Install_Error::SHA256_MISMATCH,
                'Could not compute the plugin zip SHA-256 checksum.'
            );
        }
        if (!hash_equals($digest, $actual)) {
            return AP_Plugin_Install_Error::make(
                AP_Plugin_Install_Error::SHA256_MISMATCH,
                'Plugin zip SHA-256 does not match the expected checksum.'
            );
        }

        return null;
    }

    /**
     * Reject zip-slip names before any entry is extracted.
     *
     * ZipArchive drops bytes after a NUL, and some archives hide `..` in the
     * local file header while the central directory looks safe. Both sources
     * are checked. Surviving names are resolved under a private staging
     * directory that is removed before returning.
     */
    private static function rejectUnsafeArchivePaths(ZipArchive $zip, string $zipPath): ?AP_Plugin_Install_Error
    {
        $names = self::readZipEntryNames($zipPath);
        if ($names instanceof AP_Plugin_Install_Error) {
            return $names;
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (is_array($stat) && isset($stat['name']) && is_string($stat['name'])) {
                $names[] = $stat['name'];
            }
            $indexed = $zip->getNameIndex($i, ZipArchive::FL_ENC_RAW);
            if (is_string($indexed)) {
                $names[] = $indexed;
            }
        }

        foreach ($names as $name) {
            if (self::isUnsafeZipEntryName($name)) {
                return self::unsafePathError();
            }
        }

        if ($names === []) {
            return null;
        }

        $staging = self::tempDir('ap-plugin-stage-');
        if ($staging === null) {
            return AP_Plugin_Install_Error::make(
                AP_Plugin_Install_Error::STAGING_FAILED,
                'Could not create a staging directory to check plugin zip paths.'
            );
        }

        try {
            $seen = [];
            foreach ($names as $name) {
                if (isset($seen[$name])) {
                    continue;
                }
                $seen[$name] = true;
                if (!self::entryStaysInsideStaging($staging, $name)) {
                    return self::unsafePathError();
                }
            }
        } finally {
            self::removeDir($staging);
        }

        return null;
    }

    /**
     * Absolute paths, `..` segments, backslashes, drive letters, and NUL bytes.
     *
     * Backslashes are a rejection, not a separator. A drive letter is a
     * segment that starts with `C:` (slash optional). `..` must be its own
     * segment; a filename such as `my..plugin.php` is not traversal.
     */
    private static function isUnsafeZipEntryName(string $name): bool
    {
        if ($name === '' || str_contains($name, "\0") || str_contains($name, '\\')) {
            return true;
        }
        if (str_starts_with($name, '/')) {
            return true;
        }

        $trimmed = rtrim($name, '/');
        if ($trimmed === '') {
            return true;
        }

        foreach (explode('/', $trimmed) as $segment) {
            if ($segment === '..') {
                return true;
            }
            if (preg_match('/\A[A-Za-z]:/', $segment) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve $entryName under $stagingDir and require the real path to stay inside.
     *
     * Existing components are followed with realpath, so a symlink already in
     * the staging directory cannot point a relative name outside. Components
     * that do not exist yet are appended lexically to that real parent. This
     * check does not create directories or files.
     */
    private static function entryStaysInsideStaging(string $stagingDir, string $entryName): bool
    {
        if (self::isUnsafeZipEntryName($entryName)) {
            return false;
        }

        $rootReal = realpath($stagingDir);
        if ($rootReal === false || !is_dir($rootReal)) {
            return false;
        }

        $root = self::normalizeFsPath($rootReal);
        $isDirectory = str_ends_with($entryName, '/');
        $relative = rtrim($entryName, '/');
        if ($relative === '' || $relative === '.') {
            return $isDirectory;
        }

        $parts = explode('/', $relative);
        $realCursor = $root;
        $pending = '';
        $lastIndex = count($parts) - 1;
        foreach ($parts as $index => $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                return false;
            }

            $isLast = $index === $lastIndex;
            if ($pending === '') {
                $next = $realCursor . '/' . $part;
                if (is_link($next) || file_exists($next)) {
                    $resolved = realpath($next);
                    if ($resolved === false || !self::pathIsInside($root, $resolved)) {
                        return false;
                    }
                    $resolved = self::normalizeFsPath($resolved);
                    if ($resolved === $root) {
                        return false;
                    }
                    if ((!$isLast || $isDirectory) && !is_dir($resolved)) {
                        return false;
                    }
                    $realCursor = $resolved;
                    if ($isLast) {
                        return true;
                    }
                    continue;
                }
            }

            $pending = $pending === '' ? $part : $pending . '/' . $part;
            $final = $realCursor . '/' . $pending;
            if (!self::pathIsInside($root, $final) || $final === $root) {
                return false;
            }
            if ($isLast) {
                return true;
            }
        }

        $final = $pending === '' ? $realCursor : $realCursor . '/' . $pending;

        return self::pathIsInside($root, $final) && ($isDirectory || $final !== $root);
    }

    private static function pathIsInside(string $root, string $path): bool
    {
        $root = self::normalizeFsPath($root);
        $path = self::normalizeFsPath($path);
        if ($root === '' || $path === '') {
            return false;
        }

        return $path === $root || str_starts_with($path, $root . '/');
    }

    private static function normalizeFsPath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        return $path;
    }

    private static function unsafePathError(): AP_Plugin_Install_Error
    {
        return AP_Plugin_Install_Error::make(
            AP_Plugin_Install_Error::UNSAFE_PATH,
            self::UNSAFE_PATH_MESSAGE
        );
    }

    /**
     * Reject symlinks, special files, and archive bombs before any entry is inflated.
     *
     * Symlinks and special files come from the central-directory external
     * attributes (Unix mode in the high 16 bits). The entry cap is
     * {@see ZipArchive::$numFiles}, including directories. The uncompressed
     * cap is the sum of declared sizes. Neither check reads entry bytes.
     */
    private static function rejectArchiveLimits(ZipArchive $zip, string $zipPath): ?AP_Plugin_Install_Error
    {
        $count = $zip->numFiles;
        $maxEntries = self::maxValidateEntries($zipPath);
        if ($count > $maxEntries) {
            return AP_Plugin_Install_Error::make(
                AP_Plugin_Install_Error::TOO_MANY_ENTRIES,
                'Plugin zip contains too many entries (limit ' . $maxEntries . ').'
            );
        }

        $maxUncompressed = self::maxValidateUncompressedBytes($zipPath);
        $total = 0;
        for ($index = 0; $index < $count; $index++) {
            $attributeError = self::entryAttributeError($zip, $index);
            if ($attributeError !== null) {
                return $attributeError;
            }

            $stat = $zip->statIndex($index);
            $size = is_array($stat) ? ($stat['size'] ?? null) : null;
            if (!is_int($size) || $size < 0) {
                return AP_Plugin_Install_Error::make(
                    AP_Plugin_Install_Error::ZIP_OPEN_FAILED,
                    'Could not read zip entry sizes.'
                );
            }
            if ($size > $maxUncompressed || $total > $maxUncompressed - $size) {
                return AP_Plugin_Install_Error::make(
                    AP_Plugin_Install_Error::UNCOMPRESSED_TOO_LARGE,
                    'Plugin zip uncompressed size exceeds the maximum of ' . self::formatBytes($maxUncompressed) . '.'
                );
            }
            $total += $size;
        }

        return null;
    }

    /**
     * Reject a symlink or special file based on central-directory external attributes.
     *
     * A zero Unix type means the high bits were not a mode (typical of DOS and
     * Windows zips) and is allowed. Regular files and directories are allowed.
     * The type bits are checked for every host id.
     */
    private static function entryAttributeError(ZipArchive $zip, int $index): ?AP_Plugin_Install_Error
    {
        $opsys = 0;
        $attr = 0;
        if ($zip->getExternalAttributesIndex($index, $opsys, $attr) !== true || !is_int($opsys) || !is_int($attr)) {
            return AP_Plugin_Install_Error::make(
                AP_Plugin_Install_Error::ZIP_OPEN_FAILED,
                'Could not read zip entry attributes.'
            );
        }

        $type = ($attr >> 16) & self::UNIX_S_IFMT;
        if ($type === 0 || $type === self::UNIX_S_IFREG || $type === self::UNIX_S_IFDIR) {
            return null;
        }
        if ($type === self::UNIX_S_IFLNK) {
            return AP_Plugin_Install_Error::make(
                AP_Plugin_Install_Error::SYMLINK,
                self::SYMLINK_MESSAGE
            );
        }

        return AP_Plugin_Install_Error::make(
            AP_Plugin_Install_Error::SPECIAL_FILE,
            self::SPECIAL_FILE_MESSAGE
        );
    }

    /**
     * Raw central-directory and local-header names.
     *
     * @return list<string>|AP_Plugin_Install_Error
     */
    private static function readZipEntryNames(string $zipPath): array|AP_Plugin_Install_Error
    {
        $data = @file_get_contents($zipPath);
        if (!is_string($data) || $data === '') {
            return self::zipNamesUnreadable();
        }

        $eocd = self::findZipEocd($data);
        if ($eocd === null) {
            return self::zipNamesUnreadable();
        }

        $disk = self::zipU16($data, $eocd + 4);
        $cdDisk = self::zipU16($data, $eocd + 6);
        $total = self::zipU16($data, $eocd + 10);
        $cdSize = self::zipU32($data, $eocd + 12);
        $cdOffset = self::zipU32($data, $eocd + 16);
        if (
            $disk === null
            || $cdDisk === null
            || $total === null
            || $cdSize === null
            || $cdOffset === null
            || $disk !== 0
            || $cdDisk !== 0
            || $total === 0xFFFF
            || $cdSize === 0xFFFFFFFF
            || $cdOffset === 0xFFFFFFFF
        ) {
            return self::zipNamesUnreadable();
        }

        $length = strlen($data);
        if ($cdOffset > $length || $cdSize > $length - $cdOffset) {
            return self::zipNamesUnreadable();
        }

        $names = [];
        $offset = $cdOffset;
        $end = $cdOffset + $cdSize;
        for ($index = 0; $index < $total; $index++) {
            if ($offset < 0 || $offset > $end || $end - $offset < 46) {
                return self::zipNamesUnreadable();
            }
            if (substr($data, $offset, 4) !== "PK\x01\x02") {
                return self::zipNamesUnreadable();
            }

            $nameLen = self::zipU16($data, $offset + 28);
            $extraLen = self::zipU16($data, $offset + 30);
            $commentLen = self::zipU16($data, $offset + 32);
            $localOffset = self::zipU32($data, $offset + 42);
            if ($nameLen === null || $extraLen === null || $commentLen === null || $localOffset === null) {
                return self::zipNamesUnreadable();
            }

            $nameAt = $offset + 46;
            if ($nameLen > $end - $nameAt) {
                return self::zipNamesUnreadable();
            }
            $centralName = substr($data, $nameAt, $nameLen);
            $names[] = $centralName;

            $record = 46 + $nameLen + $extraLen + $commentLen;
            if ($record < 46 || $record > $end - $offset) {
                return self::zipNamesUnreadable();
            }

            if ($localOffset > $length - 30 || substr($data, $localOffset, 4) !== "PK\x03\x04") {
                return self::zipNamesUnreadable();
            }
            $localNameLen = self::zipU16($data, $localOffset + 26);
            if ($localNameLen === null || $localNameLen > $length - ($localOffset + 30)) {
                return self::zipNamesUnreadable();
            }
            $localName = substr($data, $localOffset + 30, $localNameLen);
            if ($localName !== $centralName) {
                $names[] = $localName;
            }

            $offset += $record;
        }

        if ($offset !== $end) {
            return self::zipNamesUnreadable();
        }

        return $names;
    }

    private static function findZipEocd(string $data): ?int
    {
        $length = strlen($data);
        if ($length < 22) {
            return null;
        }

        $start = max(0, $length - (22 + 65535));
        for ($index = $length - 22; $index >= $start; $index--) {
            if (substr($data, $index, 4) !== "PK\x05\x06") {
                continue;
            }
            $commentLen = self::zipU16($data, $index + 20);
            if ($commentLen !== null && $index + 22 + $commentLen === $length) {
                return $index;
            }
        }

        return null;
    }

    private static function zipU16(string $data, int $offset): ?int
    {
        return self::zipUint($data, $offset, 'v', 2);
    }

    private static function zipU32(string $data, int $offset): ?int
    {
        return self::zipUint($data, $offset, 'V', 4);
    }

    private static function zipUint(string $data, int $offset, string $format, int $width): ?int
    {
        if ($offset < 0 || $width < 1 || $offset + $width > strlen($data)) {
            return null;
        }

        $parsed = unpack($format, substr($data, $offset, $width));
        if ($parsed === false || !isset($parsed[1]) || !is_int($parsed[1]) || $parsed[1] < 0) {
            return null;
        }

        return $parsed[1];
    }

    private static function zipNamesUnreadable(): AP_Plugin_Install_Error
    {
        return AP_Plugin_Install_Error::make(
            AP_Plugin_Install_Error::ZIP_OPEN_FAILED,
            'Could not read zip entry names.'
        );
    }

    /**
     * Require one plugin package: a slug folder, or a flat zip mapped to a slug.
     *
     * Theme-shaped and core-shaped archives fail before a nested plugin header
     * can be treated as the package.
     *
     * @return array{
     *   zip_root: string,
     *   main_rel: string,
     *   slug: string,
     *   is_folder: bool
     * }|AP_Plugin_Install_Error
     */
    private static function inspectPackageStructure(ZipArchive $zip): array|AP_Plugin_Install_Error
    {
        $collected = self::collectZipPaths($zip);
        if ($collected instanceof AP_Plugin_Install_Error) {
            return $collected;
        }

        if (self::looksLikeCorePackage($collected['files'], $collected['directories'])) {
            return self::corePackageError();
        }

        if (self::zipHasThemeStylesheet($zip, $collected['files'], 1)) {
            return self::themePackageError();
        }

        $layout = self::classifyPluginLayout($zip, $collected);
        if (
            $layout instanceof AP_Plugin_Install_Error
            && $layout->code() === AP_Plugin_Install_Error::MISSING_HEADER
            && self::zipHasThemeStylesheet($zip, $collected['files'], null)
        ) {
            return self::themePackageError();
        }

        return $layout;
    }

    /**
     * @return array{
     *   files: list<string>,
     *   directories: array<string, true>,
     *   root_files: list<string>,
     *   top_dirs: array<string, true>
     * }|AP_Plugin_Install_Error
     */
    private static function collectZipPaths(ZipArchive $zip): array|AP_Plugin_Install_Error
    {
        $files = [];
        $directories = [];
        $rootFiles = [];
        $topDirs = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (!is_array($stat)) {
                continue;
            }
            $name = (string) ($stat['name'] ?? '');
            if ($name === '') {
                continue;
            }
            if (self::isUnsafeZipEntryName($name)) {
                return self::unsafePathError();
            }
            if (self::isIgnoredArchiveEntry($name)) {
                continue;
            }

            $isDir = str_ends_with($name, '/');
            self::rememberZipDirectories($name, $isDir, $directories, $topDirs);
            if ($isDir) {
                continue;
            }

            $files[] = $name;
            if (!str_contains($name, '/')) {
                $rootFiles[] = $name;
            }
        }

        return [
            'files' => $files,
            'directories' => $directories,
            'root_files' => $rootFiles,
            'top_dirs' => $topDirs,
        ];
    }

    /**
     * @param array<string, true> $directories
     * @param array<string, true> $topDirs
     */
    private static function rememberZipDirectories(
        string $name,
        bool $isDir,
        array &$directories,
        array &$topDirs
    ): void {
        $trimmed = trim($name, '/');
        if ($trimmed === '') {
            return;
        }

        $parts = explode('/', $trimmed);
        if (!$isDir) {
            array_pop($parts);
        }
        if ($parts === []) {
            return;
        }

        $topDirs[$parts[0]] = true;
        $built = '';
        foreach ($parts as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                return;
            }
            $built = $built === '' ? $part : $built . '/' . $part;
            $directories[$built] = true;
        }
    }

    private static function isIgnoredArchiveEntry(string $name): bool
    {
        $trimmed = trim($name, '/');
        if ($trimmed === '.DS_Store' || str_ends_with($trimmed, '/.DS_Store')) {
            return true;
        }

        return $trimmed === '__MACOSX' || str_starts_with($trimmed, '__MACOSX/');
    }

    /**
     * @param list<string> $files
     * @param array<string, true> $directories
     */
    private static function looksLikeCorePackage(array $files, array $directories): bool
    {
        $roots = array_keys($directories);
        $roots[] = '';
        foreach (array_unique($roots) as $root) {
            if (self::coreMarkersAt($files, $directories, $root)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $files
     * @param array<string, true> $directories
     */
    private static function coreMarkersAt(array $files, array $directories, string $root): bool
    {
        $prefix = $root === '' ? '' : $root . '/';
        $hasFile = static function (string $relative) use ($files, $prefix): bool {
            return in_array($prefix . $relative, $files, true);
        };
        $hasDir = static function (string $relative) use ($files, $directories, $prefix): bool {
            $dir = $prefix . trim($relative, '/');
            if (isset($directories[$dir])) {
                return true;
            }
            $needle = $dir . '/';
            foreach ($files as $file) {
                if (str_starts_with($file, $needle)) {
                    return true;
                }
            }

            return false;
        };

        if (
            $hasFile('ap-includes/version.php')
            && $hasFile('index.php')
            && $hasDir('ap-admin')
        ) {
            return true;
        }
        if ($hasDir('wp-admin') && $hasDir('wp-includes')) {
            return true;
        }

        return $hasFile('wp-config-sample.php') && $hasFile('wp-settings.php');
    }

    private static function corePackageError(): AP_Plugin_Install_Error
    {
        return AP_Plugin_Install_Error::make(
            AP_Plugin_Install_Error::CORE_PACKAGE,
            'This zip looks like an AgoraPress or WordPress core package, not a plugin.'
        );
    }

    /**
     * @param list<string> $files
     */
    private static function zipHasThemeStylesheet(ZipArchive $zip, array $files, ?int $maxDepth): bool
    {
        foreach ($files as $file) {
            if (strtolower(basename($file)) !== 'style.css') {
                continue;
            }
            if ($maxDepth !== null && substr_count($file, '/') > $maxDepth) {
                continue;
            }
            $chunk = self::zipReadFile($zip, $file);
            if ($chunk !== null && self::cssHasThemeName($chunk)) {
                return true;
            }
        }

        return false;
    }

    private static function cssHasThemeName(string $chunk): bool
    {
        if (strlen($chunk) > 8192) {
            $chunk = substr($chunk, 0, 8192);
        }

        return preg_match('/^[ \t\/*#@]*Theme Name:[ \t]*\S/mi', $chunk) === 1;
    }

    private static function themePackageError(): AP_Plugin_Install_Error
    {
        return AP_Plugin_Install_Error::make(
            AP_Plugin_Install_Error::THEME_PACKAGE,
            'This zip looks like a theme (style.css with a Theme Name header), not a plugin.'
        );
    }

    /**
     * @param array{
     *   files: list<string>,
     *   directories: array<string, true>,
     *   root_files: list<string>,
     *   top_dirs: array<string, true>
     * } $collected
     *
     * @return array{
     *   zip_root: string,
     *   main_rel: string,
     *   slug: string,
     *   is_folder: bool
     * }|AP_Plugin_Install_Error
     */
    private static function classifyPluginLayout(ZipArchive $zip, array $collected): array|AP_Plugin_Install_Error
    {
        $files = $collected['files'];
        $rootFiles = $collected['root_files'];
        $topDirs = $collected['top_dirs'];

        if ($files === [] && $topDirs === []) {
            return AP_Plugin_Install_Error::make(
                AP_Plugin_Install_Error::MISSING_HEADER,
                'No plugin main file found in the zip. '
                    . 'Packages must include a PHP file with a Plugin Name header.'
            );
        }

        if (count($topDirs) > 1 || (count($topDirs) === 1 && $rootFiles !== [])) {
            return AP_Plugin_Install_Error::make(
                AP_Plugin_Install_Error::BAD_STRUCTURE,
                'Plugin zip must contain exactly one top-level folder, or only files at the archive root.'
            );
        }

        if (count($topDirs) === 1) {
            $folder = array_key_first($topDirs);
            if (!is_string($folder) || $folder === '') {
                return AP_Plugin_Install_Error::make(
                    AP_Plugin_Install_Error::BAD_STRUCTURE,
                    'Could not determine a plugin folder in the zip.'
                );
            }

            return self::classifyFolderPlugin($zip, $files, $folder);
        }

        return self::classifyFlatPlugin($zip, $rootFiles);
    }

    /**
     * @param list<string> $files
     *
     * @return array{
     *   zip_root: string,
     *   main_rel: string,
     *   slug: string,
     *   is_folder: bool
     * }|AP_Plugin_Install_Error
     */
    private static function classifyFolderPlugin(
        ZipArchive $zip,
        array $files,
        string $folder
    ): array|AP_Plugin_Install_Error {
        $slug = self::sanitizeSlug($folder);
        if (!self::isValidPluginSlug($slug)) {
            return AP_Plugin_Install_Error::make(
                AP_Plugin_Install_Error::INVALID_SLUG,
                'Plugin folder name must be a lowercase slug (letters, numbers, hyphens, and underscores).'
            );
        }

        $direct = [];
        $deeperPhp = [];
        $prefix = $folder . '/';
        foreach ($files as $file) {
            if (!str_starts_with($file, $prefix)) {
                continue;
            }
            $relative = substr($file, strlen($prefix));
            if ($relative === '' || !str_ends_with(strtolower($relative), '.php')) {
                continue;
            }
            if (str_contains($relative, '/')) {
                $deeperPhp[] = $file;
            } else {
                $direct[] = $file;
            }
        }

        $candidates = [];
        foreach ($direct as $file) {
            if (self::zipEntryHasPluginName($zip, $file)) {
                $candidates[] = $file;
            }
        }

        if ($candidates === []) {
            foreach ($deeperPhp as $file) {
                if (self::zipEntryHasPluginName($zip, $file)) {
                    return AP_Plugin_Install_Error::make(
                        AP_Plugin_Install_Error::BAD_STRUCTURE,
                        'Plugin main file is nested too deeply in the zip. Package the plugin as '
                            . 'plugin-name/plugin-name.php (or a single .php file at the zip root).'
                    );
                }
            }

            return AP_Plugin_Install_Error::make(
                AP_Plugin_Install_Error::MISSING_HEADER,
                'No plugin main file found in the zip. '
                    . 'Packages must include a PHP file with a Plugin Name header.'
            );
        }

        $chosen = self::choosePluginMainFile($folder, $candidates);
        if ($chosen === null) {
            return AP_Plugin_Install_Error::make(
                AP_Plugin_Install_Error::BAD_STRUCTURE,
                'Zip contains more than one plugin file in the plugin folder. Package a single plugin.'
            );
        }

        $headerError = self::requirePluginNameAndVersion($zip, $chosen);
        if ($headerError !== null) {
            return $headerError;
        }

        return [
            'zip_root' => $folder . '/',
            'main_rel' => basename($chosen),
            'slug' => $slug,
            'is_folder' => true,
        ];
    }

    /**
     * @param list<string> $rootFiles
     *
     * @return array{
     *   zip_root: string,
     *   main_rel: string,
     *   slug: string,
     *   is_folder: bool
     * }|AP_Plugin_Install_Error
     */
    private static function classifyFlatPlugin(ZipArchive $zip, array $rootFiles): array|AP_Plugin_Install_Error
    {
        $candidates = [];
        foreach ($rootFiles as $file) {
            if (!str_ends_with(strtolower($file), '.php')) {
                continue;
            }
            if (self::zipEntryHasPluginName($zip, $file)) {
                $candidates[] = $file;
            }
        }

        if ($candidates === []) {
            return AP_Plugin_Install_Error::make(
                AP_Plugin_Install_Error::MISSING_HEADER,
                'No plugin main file found in the zip. '
                    . 'Packages must include a PHP file with a Plugin Name header.'
            );
        }
        if (count($candidates) > 1) {
            return AP_Plugin_Install_Error::make(
                AP_Plugin_Install_Error::BAD_STRUCTURE,
                'Zip contains more than one plugin file at the archive root. Package a single plugin.'
            );
        }

        $chosen = $candidates[0];
        $headerError = self::requirePluginNameAndVersion($zip, $chosen);
        if ($headerError !== null) {
            return $headerError;
        }

        $slug = self::sanitizeFileStem($chosen);
        if (!self::isValidPluginSlug($slug)) {
            return AP_Plugin_Install_Error::make(
                AP_Plugin_Install_Error::INVALID_SLUG,
                'Could not determine a valid plugin slug from the main file name.'
            );
        }

        return [
            'zip_root' => '',
            'main_rel' => $chosen,
            'slug' => $slug,
            'is_folder' => false,
        ];
    }

    /**
     * Prefer `{folder}/{folder}.php` when several PHP files carry a plugin header.
     *
     * @param list<string> $files
     */
    private static function choosePluginMainFile(string $folder, array $files): ?string
    {
        if ($files === []) {
            return null;
        }

        $want = strtolower($folder) . '.php';
        $matches = [];
        foreach ($files as $path) {
            if (strtolower(basename($path)) === $want) {
                $matches[] = $path;
            }
        }
        if (count($matches) === 1) {
            return $matches[0];
        }
        if (count($matches) > 1) {
            return null;
        }
        if (count($files) === 1) {
            return $files[0];
        }

        return null;
    }

    private static function zipEntryHasPluginName(ZipArchive $zip, string $path): bool
    {
        $contents = self::zipReadFile($zip, $path);
        if ($contents === null || trim($contents) === '') {
            return false;
        }

        return self::phpHasPluginName($contents);
    }

    private static function requirePluginNameAndVersion(ZipArchive $zip, string $path): ?AP_Plugin_Install_Error
    {
        $contents = self::zipReadFile($zip, $path);
        if ($contents === null || trim($contents) === '') {
            return AP_Plugin_Install_Error::make(
                AP_Plugin_Install_Error::MISSING_HEADER,
                'The zip does not contain a readable plugin main file.'
            );
        }

        $headers = self::parsePluginContents($contents);
        $name = trim((string) ($headers['Plugin Name'] ?? ''));
        $version = trim((string) ($headers['Version'] ?? ''));
        if ($name === '' || $version === '') {
            return AP_Plugin_Install_Error::make(
                AP_Plugin_Install_Error::MISSING_HEADER,
                'Plugin main file must include Plugin Name and Version headers.'
            );
        }

        return null;
    }

    /**
     * Check names and blocked extensions under the plugin root.
     *
     * Entry-count and uncompressed-size caps are enforced by
     * {@see self::rejectArchiveLimits()} before any entry is inflated, so this
     * scan does not measure bombs.
     *
     * @return array{error: string, file_count: int}
     */
    private static function validateZipEntries(ZipArchive $zip, string $zipRoot): array
    {
        $prefix = $zipRoot;
        $fileCount = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (!is_array($stat)) {
                continue;
            }
            $name = (string) ($stat['name'] ?? '');
            if ($name === '') {
                continue;
            }
            if (self::isUnsafeZipEntryName($name)) {
                return ['error' => self::UNSAFE_PATH_MESSAGE, 'file_count' => $fileCount];
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
            if ($rel === false || $rel === '' || self::isUnsafeZipEntryName($rel)) {
                return ['error' => self::UNSAFE_PATH_MESSAGE, 'file_count' => $fileCount];
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
        }

        if ($fileCount < 1) {
            return ['error' => 'Plugin zip has no extractable files under the plugin root.', 'file_count' => 0];
        }

        return ['error' => '', 'file_count' => $fileCount];
    }

    /**
     * Stream zip entries into $destDir.
     *
     * $destDir is the private staging payload. This does not extract into the
     * plugins directory and does not call `ZipArchive::extractTo()`.
     */
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
            $name = (string) ($stat['name'] ?? '');
            if ($name === '') {
                continue;
            }
            if (self::isUnsafeZipEntryName($name)) {
                return self::UNSAFE_PATH_MESSAGE;
            }
            $attributeError = self::entryAttributeError($zip, $i);
            if ($attributeError !== null) {
                return $attributeError->message();
            }
            if (
                str_ends_with($name, '/')
                || str_starts_with($name, '__MACOSX/')
                || str_contains($name, '/.DS_Store')
            ) {
                continue;
            }
            if ($prefix !== '' && !str_starts_with($name, $prefix)) {
                continue;
            }

            $rel = $prefix !== '' ? substr($name, strlen($prefix)) : $name;
            if ($rel === false || $rel === '' || self::isUnsafeZipEntryName($rel)) {
                return self::UNSAFE_PATH_MESSAGE;
            }
            if ($onlyRel !== null && $rel !== $onlyRel) {
                continue;
            }
            if (!self::entryStaysInsideStaging($destDir, $rel)) {
                return self::UNSAFE_PATH_MESSAGE;
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

    /**
     * Map an existing installer message onto a stable error code.
     */
    private static function errorFromMessage(string $message): AP_Plugin_Install_Error
    {
        $lower = strtolower($message);
        $code = AP_Plugin_Install_Error::BAD_STRUCTURE;
        if (str_contains($lower, 'unsafe')) {
            $code = AP_Plugin_Install_Error::UNSAFE_PATH;
        } elseif (str_contains($lower, 'symbolic link') || str_contains($lower, 'symlink')) {
            $code = AP_Plugin_Install_Error::SYMLINK;
        } elseif (str_contains($lower, 'special file')) {
            $code = AP_Plugin_Install_Error::SPECIAL_FILE;
        } elseif (str_contains($lower, 'too many files') || str_contains($lower, 'too many entries')) {
            $code = AP_Plugin_Install_Error::TOO_MANY_ENTRIES;
        } elseif (str_contains($lower, 'uncompressed')) {
            $code = AP_Plugin_Install_Error::UNCOMPRESSED_TOO_LARGE;
        } elseif (str_contains($lower, 'theme')) {
            $code = AP_Plugin_Install_Error::THEME_PACKAGE;
        } elseif (str_contains($lower, 'core package') || str_contains($lower, 'wordpress core')) {
            $code = AP_Plugin_Install_Error::CORE_PACKAGE;
        } elseif (str_contains($lower, 'slug')) {
            $code = AP_Plugin_Install_Error::INVALID_SLUG;
        } elseif (
            str_contains($lower, 'plugin name')
            || str_contains($lower, 'no plugin main')
            || str_contains($lower, 'readable plugin main')
        ) {
            $code = AP_Plugin_Install_Error::MISSING_HEADER;
        } elseif (
            str_contains($lower, 'valid plugin directory')
            || str_contains($lower, 'could not determine')
        ) {
            $code = AP_Plugin_Install_Error::INVALID_SLUG;
        }

        return AP_Plugin_Install_Error::make($code, $message);
    }

    /**
     * Whether a slug is already installed, and its Version header when readable.
     *
     * @return array{exists: bool, version: string|null}
     */
    private static function lookupInstalledVersion(
        string $pluginsRoot,
        string $pluginRel,
        bool $isFolder,
        string $slug
    ): array {
        $pluginsRoot = rtrim($pluginsRoot, '/\\');
        $none = ['exists' => false, 'version' => null];
        if ($pluginsRoot === '' || $pluginRel === '' || $slug === '') {
            return $none;
        }

        $dest = $isFolder
            ? $pluginsRoot . '/' . $slug
            : $pluginsRoot . '/' . $pluginRel;
        $exists = $isFolder ? is_dir($dest) : is_file($dest);
        if (!$exists) {
            return $none;
        }

        $main = $pluginsRoot . '/' . $pluginRel;
        if (!is_file($main)) {
            return ['exists' => true, 'version' => null];
        }

        $realRoot = realpath($pluginsRoot);
        $realMain = realpath($main);
        if ($realRoot === false || $realMain === false) {
            return ['exists' => true, 'version' => null];
        }
        $prefix = $realRoot . DIRECTORY_SEPARATOR;
        if (!str_starts_with($realMain, $prefix)) {
            return ['exists' => true, 'version' => null];
        }

        $headers = self::parsePluginContents((string) file_get_contents($realMain));
        $version = trim((string) ($headers['Version'] ?? ''));

        return [
            'exists' => true,
            'version' => $version !== '' ? $version : null,
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

    private static function isValidPluginSlug(string $value): bool
    {
        return preg_match(self::SLUG_PATTERN, $value) === 1;
    }

    private static function sanitizeFileStem(string $value): string
    {
        $value = pathinfo(trim($value), PATHINFO_FILENAME);
        if ($value === '') {
            $value = trim($value);
        }

        return self::sanitizeSlug($value);
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

    /**
     * Content directory that owns private plugin staging.
     *
     * An explicit directory wins. Otherwise the parent of the plugins directory
     * is the content directory (plugins live at `{content}/plugins`). Staging
     * is a sibling of that plugins directory, never a child of it.
     */
    private static function resolveContentDir(?string $contentDir, string $pluginsRoot): string
    {
        if ($contentDir !== null && trim($contentDir) !== '') {
            return rtrim($contentDir, '/\\');
        }

        $pluginsRoot = rtrim($pluginsRoot, '/\\');
        if ($pluginsRoot !== '' && $pluginsRoot !== '.' && $pluginsRoot !== '/') {
            $parent = dirname($pluginsRoot);
            if ($parent !== '' && $parent !== '.' && $parent !== $pluginsRoot) {
                return $parent;
            }
        }

        if (defined('AP_CONTENT_DIR') && is_string(AP_CONTENT_DIR) && AP_CONTENT_DIR !== '') {
            return rtrim(AP_CONTENT_DIR, '/\\');
        }

        return dirname(self::resolvePluginsRoot(null));
    }

    /**
     * Extract zip entries into a new private directory under the content dir.
     *
     * The payload path is `{content}/.ap-plugin-staging/ap-plugin-stage-{id}/plugin`.
     * Files are streamed into that directory. `ZipArchive::extractTo()` is not
     * used, and `$pluginsRoot` is never the destination. Every unpacked realpath
     * must stay inside the staging directory and outside the plugins directory.
     * On failure the staging directory is removed. On success the caller owns it.
     *
     * @return array{stage: string, payload: string}|AP_Plugin_Install_Error
     */
    private static function extractIntoPrivateStaging(
        ZipArchive $zip,
        string $zipRoot,
        ?string $onlyRel,
        string $contentDir,
        string $pluginsRoot
    ): array|AP_Plugin_Install_Error {
        if ($contentDir === '' || !is_dir($contentDir) || !is_writable($contentDir)) {
            return AP_Plugin_Install_Error::make(
                AP_Plugin_Install_Error::STAGING_FAILED,
                'Could not create a private staging directory for the plugin zip.'
            );
        }
        if (self::directoryIsInside($pluginsRoot, $contentDir)) {
            return AP_Plugin_Install_Error::make(
                AP_Plugin_Install_Error::STAGING_FAILED,
                'Plugin zip staging must stay outside the plugins directory.'
            );
        }

        $stage = self::createPrivateStagingDir($contentDir);
        if (!is_string($stage) || !self::stagingPathIsPrivate($stage, $contentDir, $pluginsRoot)) {
            if (is_string($stage) && $stage !== '') {
                self::discardStagingDir($stage);
            }

            return AP_Plugin_Install_Error::make(
                AP_Plugin_Install_Error::STAGING_FAILED,
                'Could not create a private staging directory for the plugin zip.'
            );
        }

        $payload = $stage . '/plugin';
        if (!self::ensureDir($payload) || !self::stagingPathIsPrivate($payload, $contentDir, $pluginsRoot)) {
            self::discardStagingDir($stage);

            return AP_Plugin_Install_Error::make(
                AP_Plugin_Install_Error::STAGING_FAILED,
                'Could not prepare the private plugin staging directory.'
            );
        }

        $extractError = self::extractPluginFiles($zip, $zipRoot, $payload, $onlyRel);
        if ($extractError !== '') {
            self::discardStagingDir($stage);

            return self::errorFromExtractMessage($extractError);
        }
        if (!self::extractedTreeStaysInside($stage, $pluginsRoot)) {
            self::discardStagingDir($stage);

            return self::unsafePathError();
        }

        return ['stage' => $stage, 'payload' => $payload];
    }

    /**
     * Map an extractPluginFiles failure onto a stable error code.
     */
    private static function errorFromExtractMessage(string $message): AP_Plugin_Install_Error
    {
        $mapped = self::errorFromMessage($message);
        if (
            $mapped->code() === AP_Plugin_Install_Error::UNSAFE_PATH
            || $mapped->code() === AP_Plugin_Install_Error::SYMLINK
            || $mapped->code() === AP_Plugin_Install_Error::SPECIAL_FILE
        ) {
            return $mapped;
        }

        return AP_Plugin_Install_Error::make(AP_Plugin_Install_Error::STAGING_FAILED, $message);
    }

    /**
     * Create `{content}/.ap-plugin-staging/ap-plugin-stage-{id}` at mode 0700.
     *
     * The parent denies HTTP access. It is not the plugins directory.
     */
    private static function createPrivateStagingDir(string $contentDir): ?string
    {
        $parent = rtrim($contentDir, '/\\') . '/' . self::STAGING_PARENT_NAME;
        if (!self::ensurePrivateDir($parent)) {
            return null;
        }

        $deny = $parent . '/.htaccess';
        if (!is_file($deny)) {
            $written = @file_put_contents($deny, self::STAGING_HTACCESS);
            if ($written === false) {
                self::discardStagingDir($parent . '/ap-plugin-stage-unused');

                return null;
            }
            @chmod($deny, 0644);
        }

        $path = $parent . '/ap-plugin-stage-' . bin2hex(random_bytes(8));
        if (!@mkdir($path, 0700) && !is_dir($path)) {
            self::discardStagingDir($path);

            return null;
        }
        @chmod($path, 0700);

        return is_dir($path) ? $path : null;
    }

    /**
     * Create a directory at mode 0700. An existing directory is left unchanged.
     */
    private static function ensurePrivateDir(string $path): bool
    {
        if (is_dir($path)) {
            return is_writable($path);
        }

        if (!@mkdir($path, 0700, true) && !is_dir($path)) {
            return false;
        }
        @chmod($path, 0700);

        return is_dir($path) && is_writable($path);
    }

    /**
     * Whether $path is the same as $root or a location inside it.
     *
     * Lexical paths are checked first. Existing paths are checked again with
     * realpath, so a symlink whose target is inside $root still counts.
     */
    private static function directoryIsInside(string $root, string $path): bool
    {
        $rootNorm = self::normalizeFsPath(rtrim($root, '/\\'));
        $pathNorm = self::normalizeFsPath(rtrim($path, '/\\'));
        if ($rootNorm === '' || $pathNorm === '') {
            return false;
        }
        if ($pathNorm === $rootNorm || self::pathIsInside($rootNorm, $pathNorm)) {
            return true;
        }

        $rootReal = realpath($root);
        $pathReal = realpath($path);
        if ($rootReal === false || $pathReal === false) {
            return false;
        }
        $rootReal = self::normalizeFsPath($rootReal);
        $pathReal = self::normalizeFsPath($pathReal);

        return $pathReal === $rootReal || self::pathIsInside($rootReal, $pathReal);
    }

    /**
     * Whether $path is inside the content directory and outside the plugins directory.
     */
    private static function stagingPathIsPrivate(string $path, string $contentDir, string $pluginsRoot): bool
    {
        if (is_link($path)) {
            return false;
        }

        $real = realpath($path);
        $contentReal = realpath($contentDir);
        if ($real === false || $contentReal === false) {
            return false;
        }

        $realNorm = self::normalizeFsPath($real);
        $contentNorm = self::normalizeFsPath($contentReal);
        if (!self::pathIsInside($contentNorm, $realNorm)) {
            return false;
        }

        return !self::directoryIsInside($pluginsRoot, $real);
    }

    /**
     * Every unpacked path must be a real file or directory inside the staging dir.
     *
     * Symlinks fail the check. Nothing may resolve inside the plugins directory.
     */
    private static function extractedTreeStaysInside(string $stageDir, string $pluginsRoot): bool
    {
        if (is_link($stageDir)) {
            return false;
        }

        $rootReal = realpath($stageDir);
        if ($rootReal === false || !is_dir($rootReal)) {
            return false;
        }

        $root = self::normalizeFsPath($rootReal);
        $pluginsReal = realpath($pluginsRoot);
        $plugins = $pluginsReal === false ? '' : self::normalizeFsPath($pluginsReal);

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($rootReal, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $info) {
            if (!$info instanceof SplFileInfo) {
                return false;
            }

            $path = $info->getPathname();
            if (is_link($path)) {
                return false;
            }

            $real = $info->getRealPath();
            if ($real === false) {
                return false;
            }
            $realNorm = self::normalizeFsPath($real);
            if (!self::pathIsInside($root, $realNorm)) {
                return false;
            }
            if ($plugins !== '' && ($realNorm === $plugins || self::pathIsInside($plugins, $realNorm))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Remove one `ap-plugin-stage-*` directory and the parent when it is empty.
     *
     * The parent `.ap-plugin-staging` is removed only when the sole leftover is
     * the deny file written for that directory. Other stage directories are left
     * in place. This never removes the plugins directory.
     */
    private static function discardStagingDir(string $stageDir): bool
    {
        $stageDir = rtrim($stageDir, '/\\');
        $base = basename($stageDir);
        if (!str_starts_with($base, 'ap-plugin-stage-')) {
            return false;
        }

        $removed = self::removeDir($stageDir);
        $parent = dirname($stageDir);
        if (basename($parent) !== self::STAGING_PARENT_NAME) {
            return $removed;
        }

        $entries = @scandir($parent);
        if (!is_array($entries)) {
            return $removed;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === '.htaccess') {
                continue;
            }

            return $removed;
        }

        @unlink($parent . '/.htaccess');
        $parentGone = @rmdir($parent) || !is_dir($parent);

        return $removed && $parentGone;
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
