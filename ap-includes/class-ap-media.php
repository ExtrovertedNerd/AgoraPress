<?php

/**
 * AgoraPress media library — secure uploads, attachment posts, metadata.
 *
 * Attachments are stored as post_type=attachment rows (status inherit) with
 * relative file path in postmeta and optional image dimensions / alt text.
 * Files live under ap-content/uploads/ (optionally year/month subdirs).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * Media upload and attachment helpers.
 */
class AP_Media
{
    /** Relative path under uploads/ (e.g. 2026/08/photo.jpg). */
    public const ATTACHED_FILE_META = '_ap_attached_file';

    /** JSON-encoded attachment metadata (width, height, filesize, …). */
    public const ATTACHMENT_META = '_ap_attachment_metadata';

    /** Image alt text. */
    public const ALT_META = '_ap_attachment_image_alt';

    /** Option: organize uploads into year/month folders (default true). */
    public const OPTION_ORGANIZE = 'uploads_use_yearmonth_folders';

    /**
     * Max upload size in bytes when not overridden (16 MiB).
     * Honors PHP upload_max_filesize / post_max_size when smaller.
     */
    public const DEFAULT_MAX_BYTES = 16777216;

    /**
     * Runtime basedir override (tests). Takes precedence over AP_UPLOADS_DIR.
     */
    private static ?string $basedirOverride = null;

    /**
     * Runtime baseurl override (tests). Takes precedence over AP_UPLOADS_URL.
     */
    private static ?string $baseurlOverride = null;

    /**
     * Extension → preferred MIME type map (allow-list for uploads).
     *
     * @var array<string, string>
     */
    private static array $mimeMap = [
        // Images
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'jpe' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
        'bmp' => 'image/bmp',
        // Documents
        'pdf' => 'application/pdf',
        'txt' => 'text/plain',
        'csv' => 'text/csv',
        'md' => 'text/markdown',
        'rtf' => 'application/rtf',
        // Archives
        'zip' => 'application/zip',
        'gz' => 'application/gzip',
        // Audio
        'mp3' => 'audio/mpeg',
        'ogg' => 'audio/ogg',
        'oga' => 'audio/ogg',
        'wav' => 'audio/wav',
        'm4a' => 'audio/mp4',
        // Video
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
        'ogv' => 'video/ogg',
        // Office-ish
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'odt' => 'application/vnd.oasis.opendocument.text',
        'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
    ];

    /**
     * MIME types accepted even when finfo reports a close variant.
     *
     * @var array<string, list<string>>
     */
    private static array $mimeAliases = [
        'image/jpeg' => ['image/jpg', 'image/pjpeg'],
        'image/png' => ['image/x-png'],
        'image/svg+xml' => ['image/svg', 'text/plain', 'application/xml', 'text/xml'],
        'text/plain' => ['text/x-plain', 'application/octet-stream'],
        'text/csv' => ['text/plain', 'application/csv', 'application/vnd.ms-excel'],
        'text/markdown' => ['text/plain', 'text/x-markdown'],
        'application/zip' => ['application/x-zip-compressed', 'multipart/x-zip', 'application/octet-stream'],
        'application/pdf' => ['application/x-pdf', 'application/octet-stream'],
        'audio/mpeg' => ['audio/mp3', 'audio/mpg'],
        'audio/wav' => ['audio/x-wav', 'audio/wave'],
        'video/mp4' => ['video/x-m4v', 'application/mp4'],
    ];

    // -------------------------------------------------------------------------
    // Upload directory
    // -------------------------------------------------------------------------

    /**
     * Resolve basedir / baseurl / path / url / subdir for the current request.
     *
     * @param int|null $time Unix timestamp for year/month folder (null = now).
     *
     * @return array{
     *   path: string,
     *   url: string,
     *   subdir: string,
     *   basedir: string,
     *   baseurl: string,
     *   error: string|false
     * }
     */
    public static function uploadDir(?int $time = null): array
    {
        $basedir = self::basedir();
        $baseurl = self::baseurl();

        $subdir = '';
        if (self::useYearMonthFolders()) {
            $ts = $time ?? time();
            $subdir = '/' . gmdate('Y', $ts) . '/' . gmdate('m', $ts);
        }

        $path = $basedir . $subdir;
        $url = $baseurl . $subdir;

        $error = false;
        if (!is_dir($path)) {
            if (!self::ensureDir($path)) {
                $error = 'Unable to create upload directory.';
            }
        } elseif (!is_writable($path)) {
            $error = 'Upload directory is not writable.';
        }

        // Hardening files live in the uploads root (and each subdir gets index.php).
        if ($error === false) {
            self::ensureProtectionFiles($basedir);
            if ($subdir !== '') {
                self::ensureDirIndex($path);
            }
        }

        return [
            'path' => $path,
            'url' => $url,
            'subdir' => $subdir,
            'basedir' => $basedir,
            'baseurl' => $baseurl,
            'error' => $error,
        ];
    }

    /**
     * Apache rules that allow static media but block server-side scripts.
     */
    public static function uploadsHtaccessContents(): string
    {
        return <<<'HTACCESS'
# AgoraPress uploads — allow static media, block script execution.
# This directory is runtime data (gitignored); AP_Media rewrites these files if missing.

<IfModule mod_authz_core.c>
    Require all granted
</IfModule>

# Disable directory listing when allowed by server config.
Options -Indexes

# Disable PHP engine when mod_php is in use.
<IfModule mod_php.c>
    php_flag engine off
</IfModule>
<IfModule mod_php7.c>
    php_flag engine off
</IfModule>
<IfModule mod_php8.c>
    php_flag engine off
</IfModule>

# Deny direct access to common server-side script extensions.
<FilesMatch "(?i)\.(php|phtml|php\d*|phar|cgi|pl|py|asp|aspx|jsp|shtml|htaccess|htpasswd)$">
    <IfModule mod_authz_core.c>
        Require all denied
    </IfModule>
    <IfModule !mod_authz_core.c>
        Order deny,allow
        Deny from all
    </IfModule>
</FilesMatch>

HTACCESS;
    }

    /**
     * Write .htaccess + index.php under the uploads basedir when missing
     * or when an older “deny all” ruleset would block static media URLs.
     */
    public static function ensureProtectionFiles(string $basedir): void
    {
        $basedir = rtrim(str_replace('\\', '/', $basedir), '/');
        if ($basedir === '' || !is_dir($basedir)) {
            return;
        }

        $htaccess = $basedir . '/.htaccess';
        $desired = self::uploadsHtaccessContents();
        $write = !is_file($htaccess);
        if (!$write) {
            $current = (string) @file_get_contents($htaccess);
            // Upgrade legacy deny-all rules or foreign stubs that lack our FilesMatch guard.
            if (
                $current === ''
                || !str_contains($current, 'FilesMatch')
                || (
                    str_contains($current, 'Require all denied')
                    && !str_contains($current, 'Require all granted')
                )
            ) {
                $write = true;
            }
        }
        if ($write) {
            @file_put_contents($htaccess, $desired);
        }

        self::ensureDirIndex($basedir);
    }

    /**
     * Write a silent index.php into a directory (prevents listing / script entry).
     */
    public static function ensureDirIndex(string $dir): void
    {
        $dir = rtrim(str_replace('\\', '/', $dir), '/');
        if ($dir === '' || !is_dir($dir)) {
            return;
        }

        $index = $dir . '/index.php';
        if (is_file($index)) {
            return;
        }

        $php = "<?php\n// Silence is golden.\nhttp_response_code(403);\nexit;\n";
        @file_put_contents($index, $php);
    }

    /**
     * Override uploads basedir for isolated tests (null clears).
     */
    public static function setBasedirOverride(?string $dir): void
    {
        if ($dir === null || $dir === '') {
            self::$basedirOverride = null;

            return;
        }
        self::$basedirOverride = rtrim(str_replace('\\', '/', $dir), '/');
    }

    /**
     * Override uploads base URL for isolated tests (null clears).
     */
    public static function setBaseurlOverride(?string $url): void
    {
        if ($url === null || $url === '') {
            self::$baseurlOverride = null;

            return;
        }
        self::$baseurlOverride = rtrim($url, '/');
    }

    /**
     * Absolute filesystem path to ap-content/uploads (no trailing slash).
     */
    public static function basedir(): string
    {
        if (self::$basedirOverride !== null) {
            return self::$basedirOverride;
        }
        if (defined('AP_UPLOADS_DIR') && is_string(AP_UPLOADS_DIR) && AP_UPLOADS_DIR !== '') {
            return rtrim(str_replace('\\', '/', (string) AP_UPLOADS_DIR), '/');
        }
        if (defined('AP_CONTENT_DIR') && is_string(AP_CONTENT_DIR) && AP_CONTENT_DIR !== '') {
            return rtrim(str_replace('\\', '/', (string) AP_CONTENT_DIR), '/') . '/uploads';
        }
        if (defined('AP_ABSPATH')) {
            return rtrim(str_replace('\\', '/', (string) AP_ABSPATH), '/') . '/ap-content/uploads';
        }

        return rtrim(str_replace('\\', '/', dirname(__DIR__)), '/') . '/ap-content/uploads';
    }

    /**
     * Public base URL for uploads (no trailing slash).
     */
    public static function baseurl(): string
    {
        if (self::$baseurlOverride !== null) {
            return self::$baseurlOverride;
        }
        if (defined('AP_UPLOADS_URL') && is_string(AP_UPLOADS_URL) && AP_UPLOADS_URL !== '') {
            return rtrim((string) AP_UPLOADS_URL, '/');
        }

        $site = '';
        if (defined('AP_SITEURL') && is_string(AP_SITEURL) && AP_SITEURL !== '') {
            $site = rtrim((string) AP_SITEURL, '/');
        }

        $contentUrl = '';
        if (defined('AP_CONTENT_URL') && is_string(AP_CONTENT_URL) && AP_CONTENT_URL !== '') {
            $contentUrl = rtrim((string) AP_CONTENT_URL, '/');
        } elseif ($site !== '') {
            $contentUrl = $site . '/ap-content';
        }

        if ($contentUrl !== '') {
            return $contentUrl . '/uploads';
        }

        // Relative fallback for installs without AP_SITEURL (tests / CLI).
        return '/ap-content/uploads';
    }

    /**
     * Whether to nest uploads under YYYY/MM.
     *
     * Constant {@see AP_UPLOADS_USE_YEARMONTH} overrides the site option when set.
     * Option: uploads_use_yearmonth_folders (default on).
     */
    public static function useYearMonthFolders(?AP_DB $db = null): bool
    {
        if (defined('AP_UPLOADS_USE_YEARMONTH') && is_bool(AP_UPLOADS_USE_YEARMONTH)) {
            return (bool) AP_UPLOADS_USE_YEARMONTH;
        }

        if (class_exists('AP_Options', false)) {
            $raw = strtolower(trim((string) AP_Options::get(self::OPTION_ORGANIZE, '1', $db)));

            return !in_array($raw, ['0', 'false', 'no', 'off', ''], true);
        }
        if (function_exists('ap_get_option')) {
            $raw = strtolower(trim((string) ap_get_option(self::OPTION_ORGANIZE, '1', $db)));

            return !in_array($raw, ['0', 'false', 'no', 'off', ''], true);
        }

        return true;
    }

    /**
     * Maximum allowed upload size in bytes (min of config and PHP limits).
     */
    public static function maxUploadBytes(): int
    {
        $cap = self::DEFAULT_MAX_BYTES;
        if (defined('AP_MAX_UPLOAD_BYTES') && is_int(AP_MAX_UPLOAD_BYTES) && AP_MAX_UPLOAD_BYTES > 0) {
            $cap = (int) AP_MAX_UPLOAD_BYTES;
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
     * Allowed extension → MIME map (filterable later via hooks).
     *
     * @return array<string, string>
     */
    public static function allowedMimes(): array
    {
        return self::$mimeMap;
    }

    // -------------------------------------------------------------------------
    // Upload handling
    // -------------------------------------------------------------------------

    /**
     * Handle a single $_FILES-style entry: validate, move, create attachment.
     *
     * $file keys: name, type, tmp_name, error, size (PHP upload array shape).
     *
     * @param array<string, mixed> $file
     * @param array<string, mixed> $args post_parent, post_author, post_title,
     *                                   post_content, post_excerpt, alt_text,
     *                                   time, test_mode (bool — copy not move),
     *                                   test_form (unused, WP parity).
     *
     * @return array{
     *   ok: bool,
     *   id: int,
     *   file: string,
     *   url: string,
     *   type: string,
     *   error: string,
     *   post: ?AP_Post
     * }
     */
    public static function handleUpload(array $file, array $args = [], ?AP_DB $db = null): array
    {
        $empty = static fn (string $error): array => [
            'ok' => false,
            'id' => 0,
            'file' => '',
            'url' => '',
            'type' => '',
            'error' => $error,
            'post' => null,
        ];

        $errorCode = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($errorCode !== UPLOAD_ERR_OK) {
            return $empty(self::uploadErrorMessage($errorCode));
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        $origName = (string) ($file['name'] ?? '');
        $size = (int) ($file['size'] ?? 0);

        if ($tmpName === '' || $origName === '') {
            return $empty('No file was uploaded.');
        }

        $testMode = !empty($args['test_mode']);
        if (!$testMode && !is_uploaded_file($tmpName)) {
            // Allow non-HTTP unit tests to pass a real temp path with test_mode.
            return $empty('Invalid upload source.');
        }
        if ($testMode && !is_readable($tmpName)) {
            return $empty('Upload temporary file is not readable.');
        }

        if ($size < 1) {
            // Prefer filesize when the client omitted size.
            $detected = @filesize($tmpName);
            $size = is_int($detected) ? $detected : 0;
        }
        if ($size < 1) {
            return $empty('Uploaded file is empty.');
        }

        $max = self::maxUploadBytes();
        if ($size > $max) {
            return $empty('File exceeds the maximum upload size of ' . self::formatBytes($max) . '.');
        }

        // Upload flood control (per user when known, else per IP).
        $author = (int) ($args['post_author'] ?? 0);
        if (class_exists('AP_Rate_Limit', false) && empty($args['skip_rate_limit'])) {
            $bucket = $author > 0
                ? AP_Rate_Limit::userBucket($author)
                : AP_Rate_Limit::ipBucket();
            $gate = AP_Rate_Limit::check(AP_Rate_Limit::ACTION_UPLOAD, $bucket, $db);
            if (!$gate['allowed']) {
                return $empty(AP_Rate_Limit::lockoutMessage(
                    (int) $gate['retry_after'],
                    'try uploading again'
                ));
            }
        }

        $check = self::checkFileType($origName, $tmpName);
        if (!$check['ok']) {
            return $empty($check['error'] !== '' ? $check['error'] : 'File type is not allowed.');
        }

        $ext = $check['ext'];
        $mime = $check['type'];

        $time = isset($args['time']) ? (int) $args['time'] : null;
        $uploads = self::uploadDir($time);
        if ($uploads['error'] !== false) {
            return $empty((string) $uploads['error']);
        }

        $filename = self::uniqueFilename(
            self::sanitizeFilename($origName, $ext),
            $uploads['path']
        );
        $destPath = $uploads['path'] . '/' . $filename;

        $moved = $testMode
            ? @copy($tmpName, $destPath)
            : @move_uploaded_file($tmpName, $destPath);

        if (!$moved || !is_file($destPath)) {
            return $empty('Could not save the uploaded file.');
        }

        // Restrict permissions on the stored file (owner rw, group/other r).
        @chmod($destPath, 0644);

        // Re-validate the stored file (defense in depth against race / swap).
        $recheck = self::checkFileType($filename, $destPath);
        if (!$recheck['ok']) {
            @unlink($destPath);

            return $empty(
                $recheck['error'] !== ''
                    ? $recheck['error']
                    : 'Stored file failed the security scan.'
            );
        }

        if (class_exists('AP_Rate_Limit', false) && empty($args['skip_rate_limit'])) {
            $bucket = $author > 0
                ? AP_Rate_Limit::userBucket($author)
                : AP_Rate_Limit::ipBucket();
            AP_Rate_Limit::hit(AP_Rate_Limit::ACTION_UPLOAD, $bucket, $db);
        }

        $relative = ltrim($uploads['subdir'] . '/' . $filename, '/');

        $title = isset($args['post_title']) && is_string($args['post_title']) && $args['post_title'] !== ''
            ? $args['post_title']
            : self::titleFromFilename($filename);

        $parent = (int) ($args['post_parent'] ?? 0);

        $id = self::insertAttachment([
            'file' => $relative,
            'url' => $uploads['baseurl']
                . ($uploads['subdir'] !== '' ? $uploads['subdir'] : '')
                . '/' . $filename,
            'type' => $mime,
            'post_title' => $title,
            'post_content' => (string) ($args['post_content'] ?? ''),
            'post_excerpt' => (string) ($args['post_excerpt'] ?? ''),
            'post_author' => $author,
            'post_parent' => $parent,
            'alt_text' => (string) ($args['alt_text'] ?? ''),
        ], $db);

        if ($id < 1) {
            @unlink($destPath);

            return $empty('File saved but attachment record could not be created.');
        }

        $post = AP_Post::get($id, $db);

        return [
            'ok' => true,
            'id' => $id,
            'file' => $relative,
            'url' => self::getAttachmentUrl($id, $db) ?: (string) ($post?->guid ?? ''),
            'type' => $mime,
            'error' => '',
            'post' => $post,
        ];
    }

    /**
     * Create an attachment post for an already-stored file under uploads/.
     *
     * @param array{
     *   file: string,
     *   url?: string,
     *   type?: string,
     *   post_title?: string,
     *   post_content?: string,
     *   post_excerpt?: string,
     *   post_author?: int,
     *   post_parent?: int,
     *   alt_text?: string
     * } $data
     */
    public static function insertAttachment(array $data, ?AP_DB $db = null): int
    {
        AP_Post::ensureBuiltins();
        $db = $db ?? (function_exists('ap_db') ? ap_db() : null);
        if ($db === null) {
            return 0;
        }

        $relative = self::normalizeRelativePath((string) ($data['file'] ?? ''));
        if ($relative === '') {
            return 0;
        }

        $abs = self::basedir() . '/' . $relative;
        if (!is_file($abs)) {
            return 0;
        }

        $mime = (string) ($data['type'] ?? '');
        if ($mime === '') {
            $check = self::checkFileType(basename($relative), $abs);
            $mime = $check['ok'] ? $check['type'] : 'application/octet-stream';
        }

        $title = (string) ($data['post_title'] ?? self::titleFromFilename(basename($relative)));
        $url = (string) ($data['url'] ?? '');
        if ($url === '') {
            $url = self::baseurl() . '/' . implode(
                '/',
                array_map('rawurlencode', explode('/', $relative))
            );
        }

        $id = AP_Post::insert([
            'post_title' => $title,
            'post_content' => (string) ($data['post_content'] ?? ''),
            'post_excerpt' => (string) ($data['post_excerpt'] ?? ''),
            'post_status' => 'inherit',
            'post_type' => 'attachment',
            'post_mime_type' => $mime,
            'post_author' => (int) ($data['post_author'] ?? 0),
            'post_parent' => max(0, (int) ($data['post_parent'] ?? 0)),
            'guid' => $url,
            'comment_status' => 'closed',
            'ping_status' => 'closed',
            'meta' => [
                self::ATTACHED_FILE_META => $relative,
            ],
        ], $db);

        if ($id < 1) {
            return 0;
        }

        $meta = self::generateMetadata($id, $abs, $mime, $db);
        AP_Post::updateMeta($id, self::ATTACHMENT_META, (string) json_encode($meta), $db);

        $alt = trim((string) ($data['alt_text'] ?? ''));
        if ($alt !== '') {
            self::setAltText($id, $alt, $db);
        }

        // Generate thumbnail / medium / large when GD is available.
        if (self::isImageMime($mime) && !str_contains(strtolower($mime), 'svg')) {
            self::generateIntermediateSizes($id, $db);
        }

        return $id;
    }

    /**
     * Permanently delete an attachment (DB row + file on disk).
     */
    public static function deleteAttachment(int $id, ?AP_DB $db = null): bool
    {
        if ($id < 1) {
            return false;
        }

        $db = $db ?? (function_exists('ap_db') ? ap_db() : null);
        if ($db === null) {
            return false;
        }

        $post = AP_Post::get($id, $db);
        if ($post === null || $post->post_type !== 'attachment') {
            return false;
        }

        $file = self::getAttachedFile($id, $db);
        $meta = self::getMetadata($id, $db);
        $ok = AP_Post::delete($id, true, $db);
        if (!$ok) {
            return false;
        }

        if ($file !== '' && is_file($file)) {
            // Intermediate sizes first (same directory as original).
            self::deleteIntermediateFiles($meta, $file);
            // Only unlink files that still live under the uploads basedir.
            $base = realpath(self::basedir());
            $real = realpath($file);
            if ($base !== false && $real !== false && str_starts_with($real, $base)) {
                @unlink($real);
                // Best-effort: remove empty year/month dirs.
                self::maybePruneEmptyDirs(dirname($real), $base);
            }
        }

        return true;
    }

    /**
     * Update attachment fields (title, caption, description, alt).
     *
     * @param array<string, mixed> $data
     */
    public static function updateAttachment(int $id, array $data, ?AP_DB $db = null): bool
    {
        if ($id < 1) {
            return false;
        }

        $db = $db ?? (function_exists('ap_db') ? ap_db() : null);
        if ($db === null) {
            return false;
        }

        $post = AP_Post::get($id, $db);
        if ($post === null || $post->post_type !== 'attachment') {
            return false;
        }

        $update = [];
        if (array_key_exists('post_title', $data)) {
            $update['post_title'] = (string) $data['post_title'];
        }
        if (array_key_exists('post_excerpt', $data)) {
            $update['post_excerpt'] = (string) $data['post_excerpt'];
        }
        if (array_key_exists('post_content', $data)) {
            $update['post_content'] = (string) $data['post_content'];
        }
        if (array_key_exists('post_parent', $data)) {
            $update['post_parent'] = max(0, (int) $data['post_parent']);
        }

        $ok = true;
        if ($update !== []) {
            $ok = AP_Post::update($id, $update, $db, ['create_revision' => false]);
        }

        if (array_key_exists('alt_text', $data)) {
            self::setAltText($id, (string) $data['alt_text'], $db);
        }

        return $ok;
    }

    // -------------------------------------------------------------------------
    // Read helpers
    // -------------------------------------------------------------------------

    /**
     * Absolute filesystem path for an attachment, or empty string.
     */
    public static function getAttachedFile(int $id, ?AP_DB $db = null): string
    {
        if ($id < 1) {
            return '';
        }

        $relative = AP_Post::getMeta($id, self::ATTACHED_FILE_META, true, $db);
        if (!is_string($relative) || $relative === '') {
            return '';
        }

        $relative = self::normalizeRelativePath($relative);
        if ($relative === '') {
            return '';
        }

        return self::basedir() . '/' . $relative;
    }

    /**
     * Relative path under uploads/ (normalized), or empty string.
     */
    public static function getAttachedFileRelative(int $id, ?AP_DB $db = null): string
    {
        if ($id < 1) {
            return '';
        }

        $relative = AP_Post::getMeta($id, self::ATTACHED_FILE_META, true, $db);
        if (!is_string($relative) || $relative === '') {
            return '';
        }

        return self::normalizeRelativePath($relative);
    }

    /**
     * Public URL for an attachment file.
     */
    public static function getAttachmentUrl(int $id, ?AP_DB $db = null): string
    {
        if ($id < 1) {
            return '';
        }

        $relative = self::getAttachedFileRelative($id, $db);
        if ($relative !== '') {
            $parts = explode('/', $relative);

            return self::baseurl() . '/' . implode('/', array_map('rawurlencode', $parts));
        }

        $post = AP_Post::get($id, $db);
        if ($post !== null && $post->guid !== '') {
            return $post->guid;
        }

        return '';
    }

    /**
     * Decoded attachment metadata array.
     *
     * @return array<string, mixed>
     */
    public static function getMetadata(int $id, ?AP_DB $db = null): array
    {
        if ($id < 1) {
            return [];
        }

        $raw = AP_Post::getMeta($id, self::ATTACHMENT_META, true, $db);
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    public static function getAltText(int $id, ?AP_DB $db = null): string
    {
        if ($id < 1) {
            return '';
        }

        $alt = AP_Post::getMeta($id, self::ALT_META, true, $db);

        return is_string($alt) ? $alt : '';
    }

    public static function setAltText(int $id, string $alt, ?AP_DB $db = null): bool
    {
        if ($id < 1) {
            return false;
        }

        $alt = function_exists('ap_sanitize_text_field')
            ? ap_sanitize_text_field($alt)
            : trim(strip_tags($alt));

        if ($alt === '') {
            return AP_Post::deleteMeta($id, self::ALT_META, $db);
        }

        return AP_Post::updateMeta($id, self::ALT_META, $alt, $db);
    }

    public static function isImage(AP_Post|string $postOrMime): bool
    {
        $mime = $postOrMime instanceof AP_Post
            ? $postOrMime->post_mime_type
            : (string) $postOrMime;

        return str_starts_with(strtolower($mime), 'image/')
            && !str_contains(strtolower($mime), 'svg'); // treat SVG as non-raster for thumb UI
    }

    public static function isImageMime(string $mime): bool
    {
        $mime = strtolower(trim($mime));

        return str_starts_with($mime, 'image/');
    }

    /**
     * List attachments with search, mime group, date, and pagination.
     *
     * @param array{
     *   s?: string,
     *   mime_type?: string,
     *   post_parent?: int|null,
     *   post_status?: string,
     *   year?: int,
     *   month?: int,
     *   orderby?: string,
     *   order?: string,
     *   limit?: int,
     *   offset?: int,
     *   post_author?: int
     * } $args
     *
     * @return array{items: list<AP_Post>, total: int}
     */
    public static function query(array $args = [], ?AP_DB $db = null): array
    {
        AP_Post::ensureBuiltins();
        $db = $db ?? (function_exists('ap_db') ? ap_db() : null);
        if ($db === null) {
            return ['items' => [], 'total' => 0];
        }

        $table = $db->quoteIdentifier($db->table('posts'));
        $where = [$db->quoteIdentifier('post_type') . ' = ?'];
        $params = ['attachment'];

        $status = (string) ($args['post_status'] ?? 'inherit');
        if ($status !== '' && $status !== 'any') {
            $where[] = $db->quoteIdentifier('post_status') . ' = ?';
            $params[] = $status;
        } else {
            // Exclude trash unless explicitly requested.
            $where[] = $db->quoteIdentifier('post_status') . ' <> ?';
            $params[] = 'trash';
        }

        if (array_key_exists('post_parent', $args) && $args['post_parent'] !== null) {
            $where[] = $db->quoteIdentifier('post_parent') . ' = ?';
            $params[] = (int) $args['post_parent'];
        }

        if (isset($args['post_author']) && (int) $args['post_author'] > 0) {
            $where[] = $db->quoteIdentifier('post_author') . ' = ?';
            $params[] = (int) $args['post_author'];
        }

        $mime = strtolower(trim((string) ($args['mime_type'] ?? '')));
        if ($mime !== '') {
            if (str_ends_with($mime, '/*')) {
                $prefix = substr($mime, 0, -1); // e.g. image/
                $where[] = $db->quoteIdentifier('post_mime_type') . ' LIKE ?';
                $params[] = $prefix . '%';
            } else {
                $where[] = $db->quoteIdentifier('post_mime_type') . ' = ?';
                $params[] = $mime;
            }
        }

        $search = trim((string) ($args['s'] ?? ''));
        if ($search !== '') {
            $like = '%' . self::escapeLike($search) . '%';
            $where[] = '('
                . $db->quoteIdentifier('post_title') . ' LIKE ? OR '
                . $db->quoteIdentifier('post_excerpt') . ' LIKE ? OR '
                . $db->quoteIdentifier('post_content') . ' LIKE ? OR '
                . $db->quoteIdentifier('guid') . ' LIKE ?'
                . ')';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $year = (int) ($args['year'] ?? 0);
        $month = (int) ($args['month'] ?? 0);
        if ($year >= 1970 && $year <= 2100) {
            if ($month >= 1 && $month <= 12) {
                $start = sprintf('%04d-%02d-01 00:00:00', $year, $month);
                $endTs = strtotime($start . ' +1 month');
                $end = $endTs !== false
                    ? gmdate('Y-m-d H:i:s', $endTs)
                    : sprintf('%04d-%02d-01 00:00:00', $year, $month + 1);
            } else {
                $start = sprintf('%04d-01-01 00:00:00', $year);
                $end = sprintf('%04d-01-01 00:00:00', $year + 1);
            }
            $where[] = $db->quoteIdentifier('post_date') . ' >= ?';
            $params[] = $start;
            $where[] = $db->quoteIdentifier('post_date') . ' < ?';
            $params[] = $end;
        }

        $whereSql = implode(' AND ', $where);

        $countSql = 'SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $whereSql;
        $total = (int) $db->getVar($countSql, $params);

        $orderby = (string) ($args['orderby'] ?? 'post_date');
        $allowed = ['post_date', 'post_title', 'post_modified', 'ID', 'post_mime_type'];
        if (!in_array($orderby, $allowed, true)) {
            $orderby = 'post_date';
        }
        $order = strtoupper((string) ($args['order'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
        $limit = isset($args['limit']) ? max(0, (int) $args['limit']) : 40;
        $offset = max(0, (int) ($args['offset'] ?? 0));

        $sql = 'SELECT * FROM ' . $table . ' WHERE ' . $whereSql
            . ' ORDER BY ' . $db->quoteIdentifier($orderby) . ' ' . $order
            . ', ' . $db->quoteIdentifier('ID') . ' ' . $order;

        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit;
            if ($offset > 0) {
                $sql .= ' OFFSET ' . $offset;
            }
        }

        $rows = $db->getResults($sql, $params);
        $items = [];
        foreach ($rows as $row) {
            $items[] = AP_Post::fromRow($row);
        }

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Counts for mime-type filter chips (image / audio / video / application / other).
     *
     * @return array<string, int>
     */
    public static function mimeTypeCounts(?AP_DB $db = null): array
    {
        $db = $db ?? (function_exists('ap_db') ? ap_db() : null);
        if ($db === null) {
            return ['all' => 0, 'image' => 0, 'audio' => 0, 'video' => 0, 'application' => 0];
        }

        $table = $db->quoteIdentifier($db->table('posts'));
        $sql = 'SELECT ' . $db->quoteIdentifier('post_mime_type') . ' AS mime, COUNT(*) AS cnt'
            . ' FROM ' . $table
            . ' WHERE ' . $db->quoteIdentifier('post_type') . ' = ?'
            . ' AND ' . $db->quoteIdentifier('post_status') . ' <> ?'
            . ' GROUP BY ' . $db->quoteIdentifier('post_mime_type');

        $rows = $db->getResults($sql, ['attachment', 'trash']);
        $counts = [
            'all' => 0,
            'image' => 0,
            'audio' => 0,
            'video' => 0,
            'application' => 0,
            'other' => 0,
        ];

        foreach ($rows as $row) {
            $mime = strtolower((string) ($row->mime ?? ''));
            $cnt = (int) ($row->cnt ?? 0);
            $counts['all'] += $cnt;
            if (str_starts_with($mime, 'image/')) {
                $counts['image'] += $cnt;
            } elseif (str_starts_with($mime, 'audio/')) {
                $counts['audio'] += $cnt;
            } elseif (str_starts_with($mime, 'video/')) {
                $counts['video'] += $cnt;
            } elseif (str_starts_with($mime, 'application/') || str_starts_with($mime, 'text/')) {
                $counts['application'] += $cnt;
            } else {
                $counts['other'] += $cnt;
            }
        }

        return $counts;
    }

    // -------------------------------------------------------------------------
    // Validation / sanitization
    // -------------------------------------------------------------------------

    /**
     * Validate original name + on-disk file against the allow-list.
     *
     * @return array{ok: bool, ext: string, type: string, error: string}
     */
    public static function checkFileType(string $filename, string $realPath = ''): array
    {
        $fail = static fn (string $error): array => [
            'ok' => false,
            'ext' => '',
            'type' => '',
            'error' => $error,
        ];

        $filename = basename(str_replace('\\', '/', $filename));
        if ($filename === '' || $filename === '.' || $filename === '..') {
            return $fail('Invalid file name.');
        }

        // Reject double extensions / embedded names that commonly mask executables.
        $lower = strtolower($filename);
        $blockedExt = 'php|phtml|php\d*|phar|cgi|pl|py|rb|asp|aspx|jsp|shtml|'
            . 'htaccess|htpasswd|exe|bat|cmd|com|scr|dll|sh|bash|ps1|vbs|wsf|msi|jar';
        if (preg_match('/\.(' . $blockedExt . ')(\.|$)/i', $lower) === 1) {
            return $fail('Executable or server script files are not allowed.');
        }

        // Null bytes and control characters in names are always hostile.
        if (str_contains($filename, "\0") || preg_match('/[\x00-\x1F\x7F]/', $filename) === 1) {
            return $fail('Invalid file name.');
        }

        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ($ext === '' || !isset(self::$mimeMap[$ext])) {
            return $fail('File type “' . ($ext !== '' ? $ext : 'unknown') . '” is not allowed.');
        }

        $expected = self::$mimeMap[$ext];

        if ($realPath !== '' && is_readable($realPath)) {
            $detected = self::detectMime($realPath);
            if ($detected !== '' && !self::mimeMatches($expected, $detected, $ext)) {
                return $fail(
                    'File content does not match its extension (detected '
                    . $detected . ', expected ' . $expected . ').'
                );
            }

            // Raster images must decode as images (blocks polyglot / renamed binaries).
            if (self::isRasterImageExt($ext)) {
                $info = @getimagesize($realPath);
                if (!is_array($info) || !isset($info[0], $info[1]) || (int) $info[0] < 1 || (int) $info[1] < 1) {
                    return $fail('Image file is corrupt or not a valid image.');
                }
                if (!empty($info['mime']) && !self::mimeMatches($expected, (string) $info['mime'], $ext)) {
                    return $fail(
                        'Image content does not match its extension (detected '
                        . $info['mime'] . ').'
                    );
                }
            }

            // Text + SVG: reject PHP tags and obvious shell payloads.
            if (str_starts_with($expected, 'text/') || $expected === 'image/svg+xml') {
                $snippet = (string) @file_get_contents($realPath, false, null, 0, 16384);
                $dangerous = '/<\?(php|=)?|\b(?:eval|system|exec|passthru|shell_exec|proc_open)\s*\(/i';
                if ($snippet !== '' && preg_match($dangerous, $snippet) === 1) {
                    return $fail('File content failed the security scan.');
                }
            }

            // SVG: block scripts, event handlers, and external/script URLs (XSS vector).
            if ($expected === 'image/svg+xml') {
                $svgError = self::scanSvgSafety($realPath);
                if ($svgError !== '') {
                    return $fail($svgError);
                }
            }
        }

        return [
            'ok' => true,
            'ext' => $ext,
            'type' => $expected,
            'error' => '',
        ];
    }

    /**
     * Whether an extension is a raster image that must pass getimagesize().
     */
    public static function isRasterImageExt(string $ext): bool
    {
        return in_array(strtolower($ext), ['jpg', 'jpeg', 'jpe', 'png', 'gif', 'webp', 'bmp', 'avif'], true);
    }

    /**
     * Scan an SVG file for common XSS / script-injection patterns.
     *
     * Returns an empty string when safe enough to store; otherwise an error message.
     * This is intentionally strict: SVG is XML and can embed active content.
     */
    public static function scanSvgSafety(string $path): string
    {
        if (!is_readable($path)) {
            return 'SVG file is not readable.';
        }

        $size = @filesize($path);
        if (!is_int($size) || $size < 1) {
            return 'SVG file is empty.';
        }
        // Soft cap for scan + storage of “images”.
        if ($size > 2 * 1024 * 1024) {
            return 'SVG file exceeds the 2 MiB security limit.';
        }

        $content = (string) @file_get_contents($path);
        if ($content === '') {
            return 'SVG file is empty.';
        }

        // Must look like SVG/XML.
        if (
            !preg_match('/<svg[\s>]/i', $content)
            && !preg_match('/<\?xml/i', $content)
        ) {
            return 'File does not appear to be a valid SVG.';
        }

        $patterns = [
            '/<script[\s>]/i' => 'SVG must not contain <script> elements.',
            '/<\/script>/i' => 'SVG must not contain <script> elements.',
            '/\bon[a-z]+\s*=/i' => 'SVG must not contain event handler attributes.',
            '/javascript\s*:/i' => 'SVG must not contain javascript: URLs.',
            '/vbscript\s*:/i' => 'SVG must not contain vbscript: URLs.',
            '/data\s*:\s*text\/html/i' => 'SVG must not embed HTML data URLs.',
            '/<foreignObject[\s>]/i' => 'SVG must not contain foreignObject elements.',
            '/xlink:href\s*=\s*["\']\s*javascript:/i' => 'SVG must not use javascript xlink:href.',
            '/href\s*=\s*["\']\s*javascript:/i' => 'SVG must not use javascript href values.',
            '/<iframe[\s>]/i' => 'SVG must not contain iframes.',
            '/<embed[\s>]/i' => 'SVG must not contain embed elements.',
            '/<object[\s>]/i' => 'SVG must not contain object elements.',
            '/<\?(php|=)/i' => 'SVG must not contain PHP tags.',
        ];
        foreach ($patterns as $pattern => $message) {
            if (preg_match($pattern, $content) === 1) {
                return $message;
            }
        }

        return '';
    }

    /**
     * Sanitize a client filename to a safe basename with the given extension.
     */
    public static function sanitizeFilename(string $filename, string $forceExt = ''): string
    {
        $filename = basename(str_replace('\\', '/', $filename));
        $filename = preg_replace('/[\x00-\x1F\x7F]/', '', $filename) ?? $filename;

        $ext = $forceExt !== ''
            ? strtolower($forceExt)
            : strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $base = pathinfo($filename, PATHINFO_FILENAME);
        $base = strtolower($base);
        $base = preg_replace('/[^a-z0-9._-]+/', '-', $base) ?? 'file';
        $base = trim($base, '.-_');
        if ($base === '') {
            $base = 'file';
        }
        // Cap length.
        if (strlen($base) > 100) {
            $base = substr($base, 0, 100);
        }

        $ext = preg_replace('/[^a-z0-9]+/', '', $ext) ?? '';
        if ($ext === '') {
            return $base;
        }

        return $base . '.' . $ext;
    }

    /**
     * Generate a unique filename inside $dir (does not create the file).
     */
    public static function uniqueFilename(string $filename, string $dir): string
    {
        $filename = self::sanitizeFilename($filename);
        $dir = rtrim(str_replace('\\', '/', $dir), '/');
        $path = $dir . '/' . $filename;
        if (!file_exists($path)) {
            return $filename;
        }

        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        $base = pathinfo($filename, PATHINFO_FILENAME);
        $i = 1;
        $candidate = $filename;
        do {
            $candidate = $base . '-' . $i . ($ext !== '' ? '.' . $ext : '');
            $path = $dir . '/' . $candidate;
            $i++;
        } while (file_exists($path) && $i < 10000);

        return $candidate;
    }

    /**
     * Build metadata (filesize, width/height for raster images).
     *
     * @return array<string, mixed>
     */
    public static function generateMetadata(
        int $attachmentId,
        string $absPath,
        string $mime = '',
        ?AP_DB $db = null
    ): array {
        $meta = [
            'filesize' => is_file($absPath) ? (int) filesize($absPath) : 0,
        ];

        if ($mime === '' && is_file($absPath)) {
            $mime = self::detectMime($absPath);
        }

        if (self::isImageMime($mime) && !str_contains(strtolower($mime), 'svg')) {
            $info = @getimagesize($absPath);
            if (is_array($info) && isset($info[0], $info[1])) {
                $meta['width'] = (int) $info[0];
                $meta['height'] = (int) $info[1];
                if (!empty($info['mime'])) {
                    $meta['mime_type'] = (string) $info['mime'];
                }
            }
        }

        if ($mime !== '') {
            $meta['mime_type'] = $meta['mime_type'] ?? $mime;
        }

        $relative = '';
        if ($attachmentId > 0) {
            $relative = self::getAttachedFileRelative($attachmentId, $db);
        }
        if ($relative === '' && is_file($absPath)) {
            $base = self::basedir();
            $norm = str_replace('\\', '/', $absPath);
            $baseNorm = str_replace('\\', '/', $base);
            if (str_starts_with($norm, $baseNorm . '/')) {
                $relative = substr($norm, strlen($baseNorm) + 1);
            }
        }
        if ($relative !== '') {
            $meta['file'] = $relative;
        }

        return $meta;
    }

    // -------------------------------------------------------------------------
    // Image editing (GD) — scale / crop / intermediate sizes / max display width
    // -------------------------------------------------------------------------

    /** Option: max CSS display width for content images (px). 0 = no fixed cap. */
    public const OPTION_MAX_DISPLAY_WIDTH = 'max_image_display_width';

    /** Default max display width when option is unset. */
    public const DEFAULT_MAX_DISPLAY_WIDTH = 1200;

    /** @var bool Whether content-image CSS printer was registered. */
    private static bool $contentCssRegistered = false;

    /**
     * Whether GD can load/save common raster formats used by AgoraPress.
     */
    public static function gdAvailable(): bool
    {
        return extension_loaded('gd')
            && function_exists('imagecreatetruecolor')
            && function_exists('imagecopyresampled');
    }

    /**
     * Register ap_head printer for content image max-width CSS (idempotent).
     */
    public static function registerContentImageCss(): void
    {
        if (self::$contentCssRegistered) {
            return;
        }
        self::$contentCssRegistered = true;

        if (!function_exists('ap_add_action')) {
            return;
        }

        ap_add_action('ap_head', [self::class, 'printContentImageCss'], 20);
    }

    /**
     * Max display width in pixels (0 = no fixed pixel cap; still max-width:100%).
     */
    public static function maxDisplayWidth(?AP_DB $db = null): int
    {
        $raw = null;
        if (function_exists('ap_get_option')) {
            $raw = ap_get_option(self::OPTION_MAX_DISPLAY_WIDTH, self::DEFAULT_MAX_DISPLAY_WIDTH, $db);
        } elseif (class_exists('AP_Options', false)) {
            $raw = AP_Options::get(self::OPTION_MAX_DISPLAY_WIDTH, self::DEFAULT_MAX_DISPLAY_WIDTH, $db);
        }
        $n = (int) $raw;

        return max(0, min(10000, $n));
    }

    /**
     * Print CSS so content images respect max display width and never overflow.
     */
    public static function printContentImageCss(?AP_DB $db = null): void
    {
        $max = self::maxDisplayWidth($db);
        $rules = [
            '.ap-the-content img',
            '.entry-content img',
            '.post-content img',
            '.ap-content img',
            '.ap-entry-content img',
            'article .content img',
            'img.ap-content-image',
        ];
        $sel = implode(', ', $rules);
        if ($max > 0) {
            $css = $sel . '{max-width:min(100%,' . $max . 'px);height:auto;}';
        } else {
            $css = $sel . '{max-width:100%;height:auto;}';
        }

        echo '<style id="ap-content-image-max">' . $css . '</style>' . "\n";
    }

    /**
     * Registered image size definitions from Media settings.
     *
     * @return array<string, array{width: int, height: int, crop: bool}>
     */
    public static function registeredImageSizes(?AP_DB $db = null): array
    {
        $get = static function (string $key, int $default) use ($db): int {
            $v = null;
            if (function_exists('ap_get_option')) {
                $v = ap_get_option($key, $default, $db);
            } elseif (class_exists('AP_Options', false)) {
                $v = AP_Options::get($key, $default, $db);
            }

            return max(0, min(10000, (int) ($v ?? $default)));
        };
        $crop = '1';
        if (function_exists('ap_get_option')) {
            $crop = (string) ap_get_option('thumbnail_crop', '1', $db);
        } elseif (class_exists('AP_Options', false)) {
            $crop = (string) AP_Options::get('thumbnail_crop', '1', $db);
        }

        return [
            'thumbnail' => [
                'width' => $get('thumbnail_size_w', 150),
                'height' => $get('thumbnail_size_h', 150),
                'crop' => $crop === '1',
            ],
            'medium' => [
                'width' => $get('medium_size_w', 300),
                'height' => $get('medium_size_h', 300),
                'crop' => false,
            ],
            'large' => [
                'width' => $get('large_size_w', 1024),
                'height' => $get('large_size_h', 1024),
                'crop' => false,
            ],
        ];
    }

    /**
     * Generate intermediate sizes (thumbnail / medium / large) for an attachment.
     *
     * Skips when GD is unavailable or dimensions are 0. Updates attachment metadata.
     *
     * @return array<string, mixed> Updated metadata (or existing when nothing done)
     */
    public static function generateIntermediateSizes(int $attachmentId, ?AP_DB $db = null): array
    {
        $db = $db ?? (function_exists('ap_db') ? ap_db() : null);
        $meta = self::getMetadata($attachmentId, $db);
        if (!self::gdAvailable()) {
            return $meta;
        }

        $abs = self::getAttachedFile($attachmentId, $db);
        if ($abs === '' || !is_file($abs)) {
            return $meta;
        }

        $post = AP_Post::get($attachmentId, $db);
        $mime = $post !== null ? (string) $post->post_mime_type : '';
        if ($mime === '' || !self::isImageMime($mime) || str_contains(strtolower($mime), 'svg')) {
            return $meta;
        }

        $srcW = (int) ($meta['width'] ?? 0);
        $srcH = (int) ($meta['height'] ?? 0);
        if ($srcW < 1 || $srcH < 1) {
            $info = @getimagesize($abs);
            if (!is_array($info) || !isset($info[0], $info[1])) {
                return $meta;
            }
            $srcW = (int) $info[0];
            $srcH = (int) $info[1];
            $meta['width'] = $srcW;
            $meta['height'] = $srcH;
        }

        // Remove previous intermediate files before regenerating.
        self::deleteIntermediateFiles($meta, $abs);

        $sizes = [];
        $dir = dirname($abs);
        $baseName = pathinfo($abs, PATHINFO_FILENAME);
        $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));

        foreach (self::registeredImageSizes($db) as $name => $def) {
            $maxW = (int) $def['width'];
            $maxH = (int) $def['height'];
            $crop = !empty($def['crop']);
            if ($maxW < 1 && $maxH < 1) {
                continue;
            }
            // Skip when source already fits inside the box (non-crop).
            if (!$crop && ($maxW < 1 || $srcW <= $maxW) && ($maxH < 1 || $srcH <= $maxH)) {
                continue;
            }
            if ($crop && $srcW === $maxW && $srcH === $maxH) {
                continue;
            }

            $destName = $baseName . '-' . $name . ($ext !== '' ? '.' . $ext : '');
            $destPath = $dir . '/' . $destName;
            $result = self::resampleFile($abs, $destPath, $mime, $maxW, $maxH, $crop);
            if (!$result['ok']) {
                continue;
            }

            $rel = self::relativeFromAbs($destPath);
            $sizes[$name] = [
                'file' => $rel !== '' ? basename($rel) : $destName,
                'width' => $result['width'],
                'height' => $result['height'],
                'mime-type' => $mime,
            ];
        }

        $meta['sizes'] = $sizes;
        if ($db !== null) {
            AP_Post::updateMeta($attachmentId, self::ATTACHMENT_META, (string) json_encode($meta), $db);
        }

        return $meta;
    }

    /**
     * Scale (and optionally crop) the original attachment file in place.
     *
     * @return array{ok: bool, error: string, width: int, height: int, meta: array<string, mixed>}
     */
    public static function editImage(
        int $attachmentId,
        int $maxWidth,
        int $maxHeight,
        bool $crop = false,
        ?AP_DB $db = null
    ): array {
        $fail = static fn (string $error): array => [
            'ok' => false,
            'error' => $error,
            'width' => 0,
            'height' => 0,
            'meta' => [],
        ];

        if (!self::gdAvailable()) {
            return $fail('Image editing requires the PHP GD extension.');
        }

        $db = $db ?? (function_exists('ap_db') ? ap_db() : null);
        $post = AP_Post::get($attachmentId, $db);
        if ($post === null || $post->post_type !== 'attachment') {
            return $fail('Attachment not found.');
        }

        $mime = (string) $post->post_mime_type;
        if (!self::isImageMime($mime) || str_contains(strtolower($mime), 'svg')) {
            return $fail('Only raster images can be scaled or cropped.');
        }

        $abs = self::getAttachedFile($attachmentId, $db);
        if ($abs === '' || !is_file($abs)) {
            return $fail('Image file is missing on disk.');
        }

        $maxWidth = max(0, min(10000, $maxWidth));
        $maxHeight = max(0, min(10000, $maxHeight));
        if ($maxWidth < 1 && $maxHeight < 1) {
            return $fail('Enter a width and/or height greater than zero.');
        }

        // Work on a temp file then replace original.
        $tmp = $abs . '.ap-edit-' . bin2hex(random_bytes(4));
        $result = self::resampleFile($abs, $tmp, $mime, $maxWidth, $maxHeight, $crop);
        if (!$result['ok'] || !is_file($tmp)) {
            @unlink($tmp);

            return $fail($result['error'] !== '' ? $result['error'] : 'Could not process the image.');
        }

        // Replace original.
        if (!@rename($tmp, $abs)) {
            $copied = @copy($tmp, $abs);
            @unlink($tmp);
            if (!$copied) {
                return $fail('Could not save the edited image.');
            }
        }
        @chmod($abs, 0644);

        $meta = self::generateMetadata($attachmentId, $abs, $mime, $db);
        // Drop old intermediate files (dimensions changed).
        $oldMeta = self::getMetadata($attachmentId, $db);
        self::deleteIntermediateFiles($oldMeta, $abs);
        $meta['sizes'] = [];
        if ($db !== null) {
            AP_Post::updateMeta($attachmentId, self::ATTACHMENT_META, (string) json_encode($meta), $db);
        }

        // Rebuild intermediates from the new original.
        $meta = self::generateIntermediateSizes($attachmentId, $db);

        return [
            'ok' => true,
            'error' => '',
            'width' => (int) ($meta['width'] ?? $result['width']),
            'height' => (int) ($meta['height'] ?? $result['height']),
            'meta' => $meta,
        ];
    }

    /**
     * URL for a named intermediate size, or full attachment URL as fallback.
     */
    public static function getAttachmentImageUrl(
        int $id,
        string $size = 'full',
        ?AP_DB $db = null
    ): string {
        $full = self::getAttachmentUrl($id, $db);
        if ($size === '' || $size === 'full' || $size === 'original') {
            return $full;
        }

        $meta = self::getMetadata($id, $db);
        $sizes = is_array($meta['sizes'] ?? null) ? $meta['sizes'] : [];
        if (!isset($sizes[$size]) || !is_array($sizes[$size])) {
            return $full;
        }
        $file = (string) ($sizes[$size]['file'] ?? '');
        if ($file === '') {
            return $full;
        }

        $relative = self::getAttachedFileRelative($id, $db);
        if ($relative === '') {
            return $full;
        }
        $dir = str_replace('\\', '/', dirname($relative));
        $sub = ($dir === '.' || $dir === '') ? $file : ($dir . '/' . $file);

        return self::baseurl() . '/' . implode('/', array_map('rawurlencode', explode('/', $sub)));
    }

    /**
     * Resample a source image file to a destination path.
     *
     * @return array{ok: bool, error: string, width: int, height: int}
     */
    public static function resampleFile(
        string $srcPath,
        string $destPath,
        string $mime,
        int $maxWidth,
        int $maxHeight,
        bool $crop = false
    ): array {
        $fail = static fn (string $error): array => [
            'ok' => false,
            'error' => $error,
            'width' => 0,
            'height' => 0,
        ];

        if (!self::gdAvailable()) {
            return $fail('GD is not available.');
        }
        if (!is_readable($srcPath)) {
            return $fail('Source image is not readable.');
        }

        $info = @getimagesize($srcPath);
        if (!is_array($info) || !isset($info[0], $info[1]) || (int) $info[0] < 1 || (int) $info[1] < 1) {
            return $fail('Source is not a valid image.');
        }
        $srcW = (int) $info[0];
        $srcH = (int) $info[1];
        $mime = $mime !== '' ? $mime : (string) ($info['mime'] ?? '');

        $src = self::createImageFromFile($srcPath, $mime);
        if ($src === null) {
            return $fail('Could not load the image (unsupported format or corrupt file).');
        }

        $maxWidth = max(0, $maxWidth);
        $maxHeight = max(0, $maxHeight);

        if ($crop && $maxWidth > 0 && $maxHeight > 0) {
            // Center-crop to exact dimensions (cover).
            $scale = max($maxWidth / $srcW, $maxHeight / $srcH);
            $cropW = (int) round($maxWidth / $scale);
            $cropH = (int) round($maxHeight / $scale);
            $cropW = min($srcW, max(1, $cropW));
            $cropH = min($srcH, max(1, $cropH));
            $srcX = (int) max(0, floor(($srcW - $cropW) / 2));
            $srcY = (int) max(0, floor(($srcH - $cropH) / 2));
            $dstW = $maxWidth;
            $dstH = $maxHeight;
        } else {
            // Fit inside box (scale down only).
            $dstW = $srcW;
            $dstH = $srcH;
            if ($maxWidth > 0 && $dstW > $maxWidth) {
                $dstH = (int) max(1, round($dstH * ($maxWidth / $dstW)));
                $dstW = $maxWidth;
            }
            if ($maxHeight > 0 && $dstH > $maxHeight) {
                $dstW = (int) max(1, round($dstW * ($maxHeight / $dstH)));
                $dstH = $maxHeight;
            }
            $srcX = 0;
            $srcY = 0;
            $cropW = $srcW;
            $cropH = $srcH;

            if ($dstW === $srcW && $dstH === $srcH) {
                // No change needed — copy file as-is when paths differ.
                if ($srcPath !== $destPath) {
                    if (!@copy($srcPath, $destPath)) {
                        imagedestroy($src);

                        return $fail('Could not copy image file.');
                    }
                }
                imagedestroy($src);

                return [
                    'ok' => true,
                    'error' => '',
                    'width' => $srcW,
                    'height' => $srcH,
                ];
            }
        }

        $dst = imagecreatetruecolor($dstW, $dstH);
        if ($dst === false) {
            imagedestroy($src);

            return $fail('Could not allocate destination image.');
        }

        // Preserve alpha for PNG / WebP / GIF.
        if (self::mimeSupportsAlpha($mime)) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
            if ($transparent !== false) {
                imagefilledrectangle($dst, 0, 0, $dstW, $dstH, $transparent);
            }
        }

        $ok = imagecopyresampled(
            $dst,
            $src,
            0,
            0,
            $srcX,
            $srcY,
            $dstW,
            $dstH,
            $cropW,
            $cropH
        );
        imagedestroy($src);
        if (!$ok) {
            imagedestroy($dst);

            return $fail('Resampling failed.');
        }

        $saved = self::saveImageToFile($dst, $destPath, $mime);
        imagedestroy($dst);
        if (!$saved) {
            return $fail('Could not write the processed image.');
        }
        @chmod($destPath, 0644);

        return [
            'ok' => true,
            'error' => '',
            'width' => $dstW,
            'height' => $dstH,
        ];
    }

    /**
     * @return \GdImage|resource|null
     */
    private static function createImageFromFile(string $path, string $mime)
    {
        $mime = strtolower($mime);
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (
            str_contains($mime, 'jpeg') || str_contains($mime, 'jpg')
            || in_array($ext, ['jpg', 'jpeg', 'jpe'], true)
        ) {
            $img = @imagecreatefromjpeg($path);

            return $img !== false ? $img : null;
        }
        if (str_contains($mime, 'png') || $ext === 'png') {
            $img = @imagecreatefrompng($path);

            return $img !== false ? $img : null;
        }
        if (str_contains($mime, 'gif') || $ext === 'gif') {
            $img = @imagecreatefromgif($path);

            return $img !== false ? $img : null;
        }
        if (str_contains($mime, 'webp') || $ext === 'webp') {
            if (!function_exists('imagecreatefromwebp')) {
                return null;
            }
            $img = @imagecreatefromwebp($path);

            return $img !== false ? $img : null;
        }
        if (str_contains($mime, 'bmp') || $ext === 'bmp') {
            if (!function_exists('imagecreatefrombmp')) {
                return null;
            }
            $img = @imagecreatefrombmp($path);

            return $img !== false ? $img : null;
        }
        if (str_contains($mime, 'avif') || $ext === 'avif') {
            if (!function_exists('imagecreatefromavif')) {
                return null;
            }
            $img = @imagecreatefromavif($path);

            return $img !== false ? $img : null;
        }

        // Last resort: let GD sniff.
        if (function_exists('imagecreatefromstring')) {
            $blob = @file_get_contents($path);
            if (is_string($blob) && $blob !== '') {
                $img = @imagecreatefromstring($blob);

                return $img !== false ? $img : null;
            }
        }

        return null;
    }

    /**
     * @param \GdImage|resource $image
     */
    private static function saveImageToFile($image, string $path, string $mime): bool
    {
        $mime = strtolower($mime);
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (
            str_contains($mime, 'jpeg') || str_contains($mime, 'jpg')
            || in_array($ext, ['jpg', 'jpeg', 'jpe'], true)
        ) {
            return @imagejpeg($image, $path, 90);
        }
        if (str_contains($mime, 'png') || $ext === 'png') {
            return @imagepng($image, $path, 6);
        }
        if (str_contains($mime, 'gif') || $ext === 'gif') {
            return @imagegif($image, $path);
        }
        if (str_contains($mime, 'webp') || $ext === 'webp') {
            if (!function_exists('imagewebp')) {
                return false;
            }

            return @imagewebp($image, $path, 90);
        }
        if (str_contains($mime, 'bmp') || $ext === 'bmp') {
            if (!function_exists('imagebmp')) {
                return false;
            }

            return @imagebmp($image, $path);
        }
        if (str_contains($mime, 'avif') || $ext === 'avif') {
            if (!function_exists('imageavif')) {
                return false;
            }

            return @imageavif($image, $path, 80);
        }

        // Default to PNG for unknown.
        return @imagepng($image, $path, 6);
    }

    private static function mimeSupportsAlpha(string $mime): bool
    {
        $mime = strtolower($mime);

        return str_contains($mime, 'png')
            || str_contains($mime, 'webp')
            || str_contains($mime, 'gif')
            || str_contains($mime, 'avif');
    }

    /**
     * @param array<string, mixed> $meta
     */
    private static function deleteIntermediateFiles(array $meta, string $originalAbs): void
    {
        $sizes = is_array($meta['sizes'] ?? null) ? $meta['sizes'] : [];
        if ($sizes === []) {
            return;
        }
        $dir = dirname($originalAbs);
        $base = realpath(self::basedir());
        foreach ($sizes as $sizeMeta) {
            if (!is_array($sizeMeta)) {
                continue;
            }
            $file = (string) ($sizeMeta['file'] ?? '');
            if ($file === '' || str_contains($file, '..') || str_contains($file, '/')) {
                // Only basenames stored under the original directory.
                if ($file === '' || str_contains($file, '..')) {
                    continue;
                }
            }
            $path = $dir . '/' . basename($file);
            if (!is_file($path)) {
                continue;
            }
            $real = realpath($path);
            if ($base !== false && $real !== false && str_starts_with($real, $base)) {
                @unlink($real);
            }
        }
    }

    private static function relativeFromAbs(string $absPath): string
    {
        $base = str_replace('\\', '/', self::basedir());
        $norm = str_replace('\\', '/', $absPath);
        if (str_starts_with($norm, $base . '/')) {
            return self::normalizeRelativePath(substr($norm, strlen($base) + 1));
        }

        return '';
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * @return string Empty when undetectable.
     */
    public static function detectMime(string $path): string
    {
        if (!is_readable($path)) {
            return '';
        }

        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $mime = finfo_file($finfo, $path);
                finfo_close($finfo);
                if (is_string($mime) && $mime !== '') {
                    return strtolower($mime);
                }
            }
        }

        if (function_exists('mime_content_type')) {
            $mime = @mime_content_type($path);
            if (is_string($mime) && $mime !== '') {
                return strtolower($mime);
            }
        }

        return '';
    }

    private static function mimeMatches(string $expected, string $detected, string $ext): bool
    {
        $expected = strtolower($expected);
        $detected = strtolower($detected);
        if ($expected === $detected) {
            return true;
        }

        $aliases = self::$mimeAliases[$expected] ?? [];
        if (in_array($detected, $aliases, true)) {
            return true;
        }

        // Some environments report application/octet-stream for uncommon types.
        if ($detected === 'application/octet-stream') {
            return in_array($ext, ['pdf', 'zip', 'docx', 'xlsx', 'pptx', 'odt', 'ods', 'gz'], true);
        }

        return false;
    }

    private static function normalizeRelativePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $path = ltrim($path, '/');
        // Reject traversal.
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '..')) {
            return '';
        }
        // Only allow simple relative segments.
        if (preg_match('#^[A-Za-z0-9._\-/]+$#', $path) !== 1) {
            return '';
        }

        return $path;
    }

    private static function ensureDir(string $path): bool
    {
        if (is_dir($path)) {
            return is_writable($path);
        }

        return @mkdir($path, 0755, true) && is_dir($path);
    }

    private static function maybePruneEmptyDirs(string $dir, string $stopAt): void
    {
        $dir = rtrim(str_replace('\\', '/', $dir), '/');
        $stopAt = rtrim(str_replace('\\', '/', $stopAt), '/');
        $guard = 0;
        while ($dir !== '' && $dir !== $stopAt && $guard < 5) {
            if (!is_dir($dir)) {
                return;
            }
            $items = @scandir($dir);
            if (!is_array($items)) {
                return;
            }
            $items = array_diff($items, ['.', '..']);
            if ($items !== []) {
                return;
            }
            if (!@rmdir($dir)) {
                return;
            }
            $dir = dirname($dir);
            $guard++;
        }
    }

    private static function titleFromFilename(string $filename): string
    {
        $base = pathinfo($filename, PATHINFO_FILENAME);
        $base = str_replace(['-', '_'], ' ', $base);
        $base = preg_replace('/\s+/', ' ', $base) ?? $base;
        $base = trim($base);

        return $base !== '' ? $base : $filename;
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
            default => 'File upload failed (error code ' . $code . ').',
        };
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
        return (int) match ($unit) {
            'g' => $bytes * 1024 * 1024 * 1024,
            'm' => $bytes * 1024 * 1024,
            'k' => $bytes * 1024,
            default => $bytes,
        };
    }

    public static function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1048576) {
            return round($bytes / 1024, 1) . ' KB';
        }
        if ($bytes < 1073741824) {
            return round($bytes / 1048576, 1) . ' MB';
        }

        return round($bytes / 1073741824, 2) . ' GB';
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
