<?php

/**
 * Theme zip installer — upload classic WordPress / AgoraPress themes.
 *
 * Accepts a .zip containing a classic theme (style.css with Theme Name, and
 * index.php for parent themes or a Template parent for children). Extracts
 * safely into ap-content/themes/{slug}/. Block / FSE packages are rejected by
 * default (out of scope for the Classic WP Theme Compatibility Layer).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * Install themes from zip archives (admin upload + programmatic).
 */
class AP_Theme_Installer
{
    /** Default max theme zip size (40 MiB) when not overridden. */
    public const DEFAULT_MAX_BYTES = 41943040;

    /** Protected shipped theme slug — cannot be deleted or overwritten. */
    public const PROTECTED_SLUGS = ['agora'];

    /**
     * Install a theme from a filesystem path to a zip archive.
     *
     * @param array{
     *   overwrite?: bool,
     *   allow_block?: bool,
     *   themes_root?: string,
     *   slug?: string
     * } $args
     *
     * @return array{
     *   ok: bool,
     *   slug: string,
     *   path: string,
     *   headers: array<string, string>,
     *   is_block: bool,
     *   is_classic: bool,
     *   is_child: bool,
     *   overwritten: bool,
     *   errors: list<string>,
     *   warnings: list<string>
     * }
     */
    public static function installFromZip(string $zipPath, array $args = []): array
    {
        $result = self::emptyResult();

        if (!class_exists('ZipArchive', false)) {
            $result['errors'][] = 'The PHP ZipArchive extension is required to install themes from zip files.';

            return $result;
        }

        $zipPath = (string) realpath($zipPath);
        if ($zipPath === '' || !is_readable($zipPath)) {
            $result['errors'][] = 'Theme zip file is missing or not readable.';

            return $result;
        }

        $size = @filesize($zipPath);
        if (!is_int($size) || $size < 1) {
            $result['errors'][] = 'Theme zip file is empty.';

            return $result;
        }

        $max = self::maxUploadBytes();
        if ($size > $max) {
            $result['errors'][] = 'Theme zip exceeds the maximum size of ' . self::formatBytes($max) . '.';

            return $result;
        }

        // Quick magic-byte check (PK\x03\x04 or empty zip PK\x05\x06).
        $fh = @fopen($zipPath, 'rb');
        if ($fh === false) {
            $result['errors'][] = 'Could not open the theme zip file.';

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
            // Some PHP builds lack RDONLY; fall back.
            $open = $zip->open($zipPath);
        }
        if ($open !== true) {
            $result['errors'][] = 'Could not open the zip archive (corrupt or unsupported).';

            return $result;
        }

        try {
            $layout = self::detectThemeRootInZip($zip);
            if ($layout['error'] !== '') {
                $result['errors'][] = $layout['error'];

                return $result;
            }

            $zipRoot = $layout['zip_root']; // '' or 'folder/'
            $suggestedSlug = $layout['slug'];

            if (!empty($args['slug']) && is_string($args['slug'])) {
                $slug = self::sanitizeSlug($args['slug']);
            } else {
                $slug = self::sanitizeSlug($suggestedSlug);
            }
            if ($slug === '') {
                $result['errors'][] = 'Could not determine a valid theme directory name from the zip.';

                return $result;
            }

            // Pre-read style.css from the archive for headers + block signals.
            $styleRel = $zipRoot . 'style.css';
            $styleContents = self::zipReadFile($zip, $styleRel);
            if ($styleContents === null || trim($styleContents) === '') {
                $result['errors'][] = 'The zip does not contain a readable style.css in the theme root.';

                return $result;
            }

            $headers = self::parseStyleCssContents($styleContents);
            if ($headers === [] || trim((string) ($headers['Theme Name'] ?? '')) === '') {
                $result['errors'][] = 'style.css is missing a Theme Name header (classic WordPress theme required).';

                return $result;
            }

            $isBlock = self::zipLooksLikeBlockTheme($zip, $zipRoot);
            $result['is_block'] = $isBlock;
            $allowBlock = !empty($args['allow_block']);
            if ($isBlock && !$allowBlock) {
                $result['errors'][] = 'This package looks like a block / Full Site Editing theme '
                    . '(theme.json or HTML templates). Block themes are out of scope for the '
                    . 'Classic WordPress Theme Compatibility Layer. Upload a classic PHP theme instead.';
                $result['headers'] = $headers;

                return $result;
            }

            $parentHeader = self::sanitizeSlug((string) ($headers['Template'] ?? ''));
            $isChild = $parentHeader !== '';
            $result['is_child'] = $isChild;
            $result['is_classic'] = !$isBlock;

            $hasIndex = self::zipHasFile($zip, $zipRoot . 'index.php');
            if (!$isChild && !$hasIndex) {
                $result['errors'][] = 'Classic parent themes must include index.php in the theme root.';
                $result['headers'] = $headers;

                return $result;
            }

            if ($isChild && $parentHeader === $slug) {
                $result['errors'][] = 'A child theme cannot declare itself as its own Template parent.';
                $result['headers'] = $headers;

                return $result;
            }

            // Reject zip bombs / path traversal before extract.
            $scan = self::validateZipEntries($zip, $zipRoot);
            if ($scan['error'] !== '') {
                $result['errors'][] = $scan['error'];
                $result['headers'] = $headers;

                return $result;
            }

            $themesRoot = self::resolveThemesRoot(
                isset($args['themes_root']) && is_string($args['themes_root'])
                    ? $args['themes_root']
                    : null
            );
            if ($themesRoot === '' || !self::ensureDir($themesRoot)) {
                $result['errors'][] = 'Themes directory is missing or not writable.';

                return $result;
            }

            $dest = $themesRoot . '/' . $slug;
            $overwrite = !empty($args['overwrite']);
            $exists = is_dir($dest);

            if ($exists && in_array($slug, self::PROTECTED_SLUGS, true)) {
                $result['errors'][] = 'The default Agora theme cannot be overwritten via upload.';
                $result['headers'] = $headers;
                $result['slug'] = $slug;

                return $result;
            }

            if ($exists && !$overwrite) {
                $result['errors'][] = 'A theme with the slug “' . $slug . '” is already installed. '
                    . 'Enable overwrite to replace it, or delete it first.';
                $result['headers'] = $headers;
                $result['slug'] = $slug;

                return $result;
            }

            // Extract to a temporary directory, then swap into place.
            $tmpBase = self::tempDir('ap-theme-install-');
            if ($tmpBase === null) {
                $result['errors'][] = 'Could not create a temporary directory for extraction.';

                return $result;
            }

            $tmpTheme = $tmpBase . '/theme';
            if (!self::ensureDir($tmpTheme)) {
                self::removeDir($tmpBase);
                $result['errors'][] = 'Could not prepare temporary theme directory.';

                return $result;
            }

            $extractError = self::extractThemeFiles($zip, $zipRoot, $tmpTheme);
            if ($extractError !== '') {
                self::removeDir($tmpBase);
                $result['errors'][] = $extractError;
                $result['headers'] = $headers;

                return $result;
            }

            // Validate extracted tree.
            $stylePath = $tmpTheme . '/style.css';
            if (!is_readable($stylePath)) {
                self::removeDir($tmpBase);
                $result['errors'][] = 'Extraction failed: style.css missing after unpack.';

                return $result;
            }

            if (!$isChild && !is_file($tmpTheme . '/index.php')) {
                self::removeDir($tmpBase);
                $result['errors'][] = 'Extraction failed: index.php missing after unpack.';

                return $result;
            }

            // Move into themes root.
            if ($exists && $overwrite) {
                if (!self::removeDir($dest)) {
                    self::removeDir($tmpBase);
                    $result['errors'][] = 'Could not remove the existing theme directory for overwrite.';
                    $result['slug'] = $slug;

                    return $result;
                }
                $result['overwritten'] = true;
            }

            if (!@rename($tmpTheme, $dest)) {
                // Cross-device rename can fail; fall back to copy.
                if (!self::copyDir($tmpTheme, $dest)) {
                    self::removeDir($tmpBase);
                    $result['errors'][] = 'Could not install the theme into the themes directory.';
                    $result['slug'] = $slug;

                    return $result;
                }
                self::removeDir($tmpTheme);
            }

            self::removeDir($tmpBase);

            // Re-parse from installed path (works even when themes_root was overridden).
            $installedHeaders = $headers;
            $installedStyle = $dest . '/style.css';
            if (is_readable($installedStyle)) {
                if (class_exists('AP_Theme', false)) {
                    $fromDisk = AP_Theme::parseStyleCss($installedStyle);
                } else {
                    $fromDisk = self::parseStyleCssContents((string) file_get_contents($installedStyle));
                }
                if (is_array($fromDisk) && $fromDisk !== []) {
                    $installedHeaders = $fromDisk;
                    $installedHeaders['Slug'] = $slug;
                }
            }

            // Child with missing parent: still install, but warn.
            if ($isChild && $parentHeader !== '' && !is_dir($themesRoot . '/' . $parentHeader)) {
                $result['warnings'][] = 'Parent theme “' . $parentHeader
                    . '” is not installed. Activate this child only after installing the parent.';
            }

            $result['ok'] = true;
            $result['slug'] = $slug;
            $result['path'] = $dest;
            $result['headers'] = is_array($installedHeaders) ? $installedHeaders : $headers;
            $result['is_classic'] = !$isBlock;

            if (function_exists('ap_do_action')) {
                ap_do_action('ap_theme_installed', $slug, $result);
            }

            return $result;
        } finally {
            $zip->close();
        }
    }

    /**
     * Handle a single $_FILES-style zip upload and install the theme.
     *
     * $file keys: name, type, tmp_name, error, size (PHP upload array shape).
     *
     * @param array<string, mixed> $file
     * @param array{
     *   overwrite?: bool,
     *   allow_block?: bool,
     *   themes_root?: string,
     *   slug?: string,
     *   test_mode?: bool
     * } $args
     *
     * @return array{
     *   ok: bool,
     *   slug: string,
     *   path: string,
     *   headers: array<string, string>,
     *   is_block: bool,
     *   is_classic: bool,
     *   is_child: bool,
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
            $result['errors'][] = 'No theme file was uploaded.';

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
            $result['errors'][] = 'Theme zip exceeds the maximum size of ' . self::formatBytes($max) . '.';

            return $result;
        }

        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        if ($ext !== 'zip') {
            $result['errors'][] = 'Theme packages must be a .zip file.';

            return $result;
        }

        // Optional MIME sniff.
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
     * Delete an installed theme directory (not the active or protected theme).
     *
     * @return array{ok: bool, slug: string, errors: list<string>}
     */
    public static function deleteTheme(string $slug, ?AP_DB $db = null): array
    {
        $slug = self::sanitizeSlug($slug);
        $errors = [];

        if ($slug === '') {
            return ['ok' => false, 'slug' => '', 'errors' => ['No theme specified.']];
        }

        if (in_array($slug, self::PROTECTED_SLUGS, true)) {
            return [
                'ok' => false,
                'slug' => $slug,
                'errors' => ['The default Agora theme cannot be deleted.'],
            ];
        }

        if (class_exists('AP_Theme', false)) {
            $active = AP_Theme::getStylesheet($db);
            $parent = AP_Theme::getTemplate($db);
            if ($slug === $active || $slug === $parent) {
                return [
                    'ok' => false,
                    'slug' => $slug,
                    'errors' => ['Cannot delete the active theme. Switch to another theme first.'],
                ];
            }
        }

        $root = self::resolveThemesRoot(null);
        $dir = $root . '/' . $slug;
        if (!is_dir($dir)) {
            return ['ok' => false, 'slug' => $slug, 'errors' => ['That theme is not installed.']];
        }

        // Refuse to delete outside themes root (path hardening).
        $realDir = realpath($dir);
        $realRoot = realpath($root);
        if (
            $realDir === false
            || $realRoot === false
            || ($realDir !== $realRoot && !str_starts_with($realDir, $realRoot . DIRECTORY_SEPARATOR))
        ) {
            return ['ok' => false, 'slug' => $slug, 'errors' => ['Invalid theme path.']];
        }

        if (!self::removeDir($dir)) {
            return ['ok' => false, 'slug' => $slug, 'errors' => ['Could not remove the theme directory.']];
        }

        if (function_exists('ap_do_action')) {
            ap_do_action('ap_theme_deleted', $slug);
        }

        return ['ok' => true, 'slug' => $slug, 'errors' => []];
    }

    /**
     * Maximum allowed theme zip size in bytes.
     */
    public static function maxUploadBytes(): int
    {
        $cap = self::DEFAULT_MAX_BYTES;
        if (defined('AP_MAX_THEME_UPLOAD_BYTES') && is_int(AP_MAX_THEME_UPLOAD_BYTES) && AP_MAX_THEME_UPLOAD_BYTES > 0) {
            $cap = (int) AP_MAX_THEME_UPLOAD_BYTES;
        } elseif (defined('AP_MAX_UPLOAD_BYTES') && is_int(AP_MAX_UPLOAD_BYTES) && AP_MAX_UPLOAD_BYTES > 0) {
            // Prefer at least the media default, but allow larger theme packages.
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
     * Find the single theme root inside a zip (style.css location).
     *
     * Supports:
     * - style.css at archive root
     * - one top-level folder containing style.css (classic WP packaging)
     *
     * @return array{zip_root: string, slug: string, error: string}
     */
    private static function detectThemeRootInZip(ZipArchive $zip): array
    {
        $empty = ['zip_root' => '', 'slug' => '', 'error' => ''];
        $styleCandidates = [];

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
                    'slug' => '',
                    'error' => 'Zip contains unsafe paths and was rejected.',
                ];
            }
            // Ignore macOS metadata / junk.
            if (str_starts_with($name, '__MACOSX/') || str_contains($name, '/.DS_Store')) {
                continue;
            }
            if (strtolower(basename($name)) === 'style.css') {
                $styleCandidates[] = $name;
            }
        }

        if ($styleCandidates === []) {
            return [
                'zip_root' => '',
                'slug' => '',
                'error' => 'No style.css found in the zip. Classic themes must ship a style.css with a Theme Name header.',
            ];
        }

        // Prefer shallowest style.css (fewest directory components).
        usort(
            $styleCandidates,
            static function (string $a, string $b): int {
                $da = substr_count($a, '/');
                $db = substr_count($b, '/');
                if ($da !== $db) {
                    return $da <=> $db;
                }

                return strcmp($a, $b);
            }
        );

        $chosen = $styleCandidates[0];
        $depth = substr_count($chosen, '/');

        if ($depth === 0) {
            // style.css at zip root → theme files live at root; slug from Theme Name later.
            $headers = self::parseStyleCssContents((string) self::zipReadFile($zip, $chosen));
            $slug = self::slugFromHeaders($headers, 'theme');

            return ['zip_root' => '', 'slug' => $slug, 'error' => ''];
        }

        if ($depth === 1) {
            // folder/style.css
            $folder = dirname($chosen);
            if ($folder === '.' || $folder === '' || $folder === '/') {
                return [
                    'zip_root' => '',
                    'slug' => '',
                    'error' => 'Could not determine theme folder in the zip.',
                ];
            }
            $slug = self::sanitizeSlug(basename($folder));
            if ($slug === '') {
                $headers = self::parseStyleCssContents((string) self::zipReadFile($zip, $chosen));
                $slug = self::slugFromHeaders($headers, 'theme');
            }

            return [
                'zip_root' => rtrim(str_replace('\\', '/', $folder), '/') . '/',
                'slug' => $slug,
                'error' => '',
            ];
        }

        // Deeper nesting is unusual for WP themes and risky; reject.
        return [
            'zip_root' => '',
            'slug' => '',
            'error' => 'style.css is nested too deeply in the zip. Package the theme as '
                . 'theme-name/style.css (or style.css at the zip root).',
        ];
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
        $maxUncompressed = self::maxUploadBytes() * 20; // soft zip-bomb guard

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
            // Only entries under the theme root (or all if root is zip root).
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

            // Block executable-like extensions in theme packages.
            $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
            $blocked = ['php3', 'php4', 'php5', 'phtml', 'phar', 'cgi', 'pl', 'py', 'exe', 'sh', 'bat', 'cmd'];
            // PHP is allowed (theme templates); block other script-ish types.
            if (in_array($ext, $blocked, true)) {
                return [
                    'error' => 'Zip contains disallowed file type “.' . $ext . '”.',
                    'file_count' => $fileCount,
                ];
            }

            $fileCount++;
            $totalUncompressed += (int) ($stat['size'] ?? 0);
            if ($fileCount > $maxFiles) {
                return ['error' => 'Theme zip contains too many files.', 'file_count' => $fileCount];
            }
            if ($totalUncompressed > $maxUncompressed) {
                return ['error' => 'Theme zip uncompressed size is too large.', 'file_count' => $fileCount];
            }
        }

        if ($fileCount < 1) {
            return ['error' => 'Theme zip has no extractable files under the theme root.', 'file_count' => 0];
        }

        return ['error' => '', 'file_count' => $fileCount];
    }

    private static function extractThemeFiles(ZipArchive $zip, string $zipRoot, string $destDir): string
    {
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

            $target = $destDir . '/' . $rel;
            $targetDir = dirname($target);
            if (!self::ensureDir($targetDir)) {
                return 'Could not create directory during extraction.';
            }

            // Stream extract without extractTo (safer path control).
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

    private static function zipLooksLikeBlockTheme(ZipArchive $zip, string $zipRoot): bool
    {
        if (self::zipHasFile($zip, $zipRoot . 'theme.json')) {
            return true;
        }
        $prefix = $zipRoot . 'templates/';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (!is_array($stat)) {
                continue;
            }
            $name = str_replace('\\', '/', (string) ($stat['name'] ?? ''));
            if ($name === '' || str_ends_with($name, '/')) {
                continue;
            }
            if (str_starts_with($name, $prefix) && str_ends_with(strtolower($name), '.html')) {
                return true;
            }
        }

        return false;
    }

    private static function zipHasFile(ZipArchive $zip, string $path): bool
    {
        return $zip->locateName($path) !== false
            || $zip->locateName($path, ZipArchive::FL_NOCASE) !== false;
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

    /**
     * @return array<string, string>
     */
    public static function parseStyleCssContents(string $chunk): array
    {
        // Match AP_Theme::parseStyleCss field set.
        $known = [
            'Theme Name',
            'Theme URI',
            'Description',
            'Author',
            'Author URI',
            'Version',
            'Template',
            'Status',
            'Tags',
            'Text Domain',
            'Domain Path',
            'Requires at least',
            'Requires PHP',
            'License',
            'License URI',
        ];

        // Only need the header block.
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
     *   slug: string,
     *   path: string,
     *   headers: array<string, string>,
     *   is_block: bool,
     *   is_classic: bool,
     *   is_child: bool,
     *   overwritten: bool,
     *   errors: list<string>,
     *   warnings: list<string>
     * }
     */
    private static function emptyResult(): array
    {
        return [
            'ok' => false,
            'slug' => '',
            'path' => '',
            'headers' => [],
            'is_block' => false,
            'is_classic' => false,
            'is_child' => false,
            'overwritten' => false,
            'errors' => [],
            'warnings' => [],
        ];
    }

    private static function resolveThemesRoot(?string $override): string
    {
        if ($override !== null && $override !== '') {
            return rtrim($override, '/\\');
        }
        if (class_exists('AP_Theme', false)) {
            return AP_Theme::themesRoot();
        }
        if (defined('AP_THEME_DIR')) {
            return (string) AP_THEME_DIR;
        }
        if (defined('AP_CONTENT_DIR')) {
            return AP_CONTENT_DIR . '/themes';
        }
        if (defined('AP_ABSPATH')) {
            return rtrim((string) AP_ABSPATH, '/\\') . '/ap-content/themes';
        }

        return dirname(__DIR__) . '/ap-content/themes';
    }

    private static function sanitizeSlug(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9_\\-]+/', '-', $value) ?? $value;
        $value = preg_replace('/\\-+/', '-', $value) ?? $value;

        return trim($value, '-');
    }

    /**
     * @param array<string, string> $headers
     */
    private static function slugFromHeaders(array $headers, string $fallback): string
    {
        $name = (string) ($headers['Theme Name'] ?? '');
        $slug = self::sanitizeSlug($name);
        if ($slug === '') {
            $slug = self::sanitizeSlug($fallback);
        }

        return $slug !== '' ? $slug : 'theme';
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
            } else {
                if (!@unlink($path)) {
                    return false;
                }
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
            } else {
                if (!@copy($from, $to)) {
                    return false;
                }
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
