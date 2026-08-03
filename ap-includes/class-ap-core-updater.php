<?php

/**
 * One-click AgoraPress core auto-update.
 *
 * Downloads the package URL from the public version.json payload (via
 * {@see AP_Version_Check}), verifies integrity, extracts safely, applies
 * files while preserving site-local data, runs pending DB migrations, and
 * clears the version-check cache.
 *
 * Privacy: package download is a plain GET of the published download_url.
 * No domain, site URL, email, or other identifying data is sent.
 *
 * Safety:
 * - Capability `update_core` (or `manage_options`) required at the admin UI
 * - Nonce on the admin form
 * - Zip path-traversal / zip-bomb soft limits
 * - Optional SHA-256 verification when version.json provides sha256
 * - Maintenance mode during apply
 * - Never overwrites ap-config.php or user content under ap-content/
 *   (uploads, plugins, mu-plugins, non-default themes)
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * Core filesystem + database auto-updater.
 */
class AP_Core_Updater
{
    /** Default max package size (100 MiB). */
    public const DEFAULT_MAX_BYTES = 104857600;

    /** Soft limit: max files inside the zip. */
    public const MAX_ZIP_FILES = 50000;

    /** Soft limit: total uncompressed bytes (250 MiB). */
    public const MAX_UNCOMPRESSED_BYTES = 262144000;

    /** Soft limit: single entry uncompressed size (80 MiB). */
    public const MAX_ENTRY_BYTES = 83886080;

    /** Maintenance file basenames under ABSPATH. */
    public const MAINTENANCE_FILE = '.maintenance';

    /** Option key: last successful auto-update metadata (JSON). */
    public const OPTION_LAST_UPDATE = 'ap_last_core_update';

    /**
     * Relative paths / prefixes that must never be overwritten by a package.
     * Theme path exceptions: default agora may be updated (see shouldApplyRelative).
     *
     * @var list<string>
     */
    public const PRESERVE_EXACT = [
        'ap-config.php',
        '.maintenance',
    ];

    /**
     * Optional HTTP transport for tests:
     * function(string $method, string $url): array{ok:bool,status:int,body:string,error:string}
     *
     * @var callable|null
     */
    private static $httpTransport = null;

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Whether the site can attempt a one-click update right now (pre-flight).
     *
     * @return array{
     *   ok: bool,
     *   can_update: bool,
     *   current_version: string,
     *   remote_version: string,
     *   download_url: string,
     *   changelog_url: string,
     *   sha256: string,
     *   has_update: bool,
     *   errors: list<string>,
     *   warnings: list<string>,
     *   checks: array<string, bool>
     * }
     */
    public static function canUpdate(?AP_DB $db = null): array
    {
        $result = self::emptyCanUpdate();
        $result['current_version'] = class_exists('AP_Version_Check', false)
            ? AP_Version_Check::currentVersion()
            : (defined('AP_VERSION') ? (string) AP_VERSION : '');

        $checks = [
            'ziparchive' => class_exists('ZipArchive', false),
            'curl_or_stream' => function_exists('curl_init') || ini_get('allow_url_fopen'),
            'version_check_enabled' => class_exists('AP_Version_Check', false)
                && AP_Version_Check::isEnabled($db),
            'abspath_writable' => self::isDirWritable(self::abspath()),
            'tmpdir_writable' => self::isDirWritable(rtrim(sys_get_temp_dir(), '/\\')),
        ];
        $result['checks'] = $checks;

        if (!$checks['ziparchive']) {
            $result['errors'][] = 'The PHP ZipArchive extension is required for one-click updates.';
        }
        if (!$checks['curl_or_stream']) {
            $result['errors'][] = 'Either cURL or allow_url_fopen is required to download update packages.';
        }
        if (!$checks['abspath_writable']) {
            $result['errors'][] = 'The site root directory is not writable.';
        }
        if (!$checks['tmpdir_writable']) {
            $result['errors'][] = 'The system temporary directory is not writable.';
        }
        if (!$checks['version_check_enabled']) {
            $result['errors'][] = 'Version checks are disabled. Enable them under site options or via the version_check_enabled option.';
        }

        if (!class_exists('AP_Version_Check', false)) {
            $result['errors'][] = 'Version checker is not loaded.';
            $result['ok'] = $result['errors'] === [];

            return $result;
        }

        $info = AP_Version_Check::getRemoteInfo($db);
        $result['remote_version'] = (string) ($info['version'] ?? '');
        $result['download_url'] = (string) ($info['download_url'] ?? '');
        $result['changelog_url'] = (string) ($info['changelog_url'] ?? '');
        $result['sha256'] = (string) ($info['sha256'] ?? '');

        if (!$info['ok'] || $result['remote_version'] === '') {
            $result['errors'][] = 'Could not determine the latest version. Check again later or download manually.';
            $result['ok'] = $result['errors'] === [];

            return $result;
        }

        $result['has_update'] = AP_Version_Check::isNewer(
            $result['remote_version'],
            $result['current_version']
        );

        if (!$result['has_update']) {
            $result['warnings'][] = 'You are already on the latest available version.';
        }

        if ($result['download_url'] === '') {
            $result['errors'][] = 'No download URL was published for the latest version. Use the manual Download link when available.';
        }

        $result['can_update'] = $result['has_update']
            && $result['download_url'] !== ''
            && $result['errors'] === [];
        $result['ok'] = $result['errors'] === [];

        return $result;
    }

    /**
     * Run a full one-click update from the cached/forced remote version info.
     *
     * @param array{
     *   force_check?: bool,
     *   abspath?: string,
     *   skip_migrate?: bool,
     *   package_path?: string,
     *   expected_version?: string,
     *   download_url?: string,
     *   sha256?: string,
     *   max_bytes?: int
     * } $args
     *
     * @return array{
     *   ok: bool,
     *   from_version: string,
     *   to_version: string,
     *   files_applied: int,
     *   migrations: list<array{version: int, description: string}>,
     *   package_version: string,
     *   errors: list<string>,
     *   warnings: list<string>,
     *   steps: list<string>
     * }
     */
    public static function run(?AP_DB $db = null, array $args = []): array
    {
        $out = self::emptyRunResult();
        $out['from_version'] = class_exists('AP_Version_Check', false)
            ? AP_Version_Check::currentVersion()
            : (defined('AP_VERSION') ? (string) AP_VERSION : '');

        $abspath = isset($args['abspath']) && is_string($args['abspath']) && $args['abspath'] !== ''
            ? rtrim(str_replace('\\', '/', $args['abspath']), '/') . '/'
            : self::abspath();

        $tmpBase = null;
        $maintenanceOn = false;

        try {
            // Resolve package source: either pre-downloaded path (tests) or remote.
            $downloadUrl = '';
            $expectedVersion = '';
            $sha256 = '';
            $packagePath = '';

            if (!empty($args['package_path']) && is_string($args['package_path'])) {
                $packagePath = (string) $args['package_path'];
                $expectedVersion = isset($args['expected_version'])
                    ? (string) $args['expected_version']
                    : '';
                $sha256 = isset($args['sha256']) ? (string) $args['sha256'] : '';
                $downloadUrl = isset($args['download_url']) ? (string) $args['download_url'] : '';
                $out['steps'][] = 'using_local_package';
            } else {
                if (!class_exists('AP_Version_Check', false)) {
                    $out['errors'][] = 'Version checker is not loaded.';

                    return $out;
                }

                $force = !empty($args['force_check']);
                $info = $force
                    ? AP_Version_Check::forceCheck($db)
                    : AP_Version_Check::getRemoteInfo($db);
                $out['steps'][] = $force ? 'forced_version_check' : 'version_check';

                if (!$info['ok'] || (string) ($info['version'] ?? '') === '') {
                    $out['errors'][] = 'Could not fetch the latest version information.';

                    return $out;
                }

                $expectedVersion = (string) $info['version'];
                $downloadUrl = (string) ($info['download_url'] ?? '');
                $sha256 = (string) ($info['sha256'] ?? '');

                if (isset($args['download_url']) && is_string($args['download_url']) && $args['download_url'] !== '') {
                    $downloadUrl = (string) $args['download_url'];
                }
                if (isset($args['sha256']) && is_string($args['sha256'])) {
                    $sha256 = (string) $args['sha256'];
                }
                if (isset($args['expected_version']) && is_string($args['expected_version']) && $args['expected_version'] !== '') {
                    $expectedVersion = (string) $args['expected_version'];
                }

                if (!AP_Version_Check::isNewer($expectedVersion, $out['from_version'])) {
                    $out['errors'][] = 'No newer version is available to install.';
                    $out['to_version'] = $expectedVersion;

                    return $out;
                }

                if ($downloadUrl === '' || !self::isHttpUrl($downloadUrl)) {
                    $out['errors'][] = 'No valid download URL is available for this release.';

                    return $out;
                }

                $preflight = self::canUpdate($db);
                // When forcing a local override of URL we still need zip + writable.
                if (!$preflight['checks']['ziparchive']) {
                    $out['errors'][] = 'The PHP ZipArchive extension is required for one-click updates.';

                    return $out;
                }
                if (!self::isDirWritable($abspath)) {
                    $out['errors'][] = 'The site root directory is not writable.';

                    return $out;
                }
            }

            if (!class_exists('ZipArchive', false)) {
                $out['errors'][] = 'The PHP ZipArchive extension is required for one-click updates.';

                return $out;
            }

            $tmpBase = self::tempDir('ap-core-update-');
            if ($tmpBase === null) {
                $out['errors'][] = 'Could not create a temporary directory for the update.';

                return $out;
            }

            if ($packagePath === '') {
                $packagePath = $tmpBase . '/package.zip';
                $maxBytes = isset($args['max_bytes']) ? (int) $args['max_bytes'] : self::DEFAULT_MAX_BYTES;
                $dl = self::downloadPackage($downloadUrl, $packagePath, $maxBytes);
                $out['steps'][] = 'download';
                if (!$dl['ok']) {
                    $out['errors'][] = $dl['error'] !== ''
                        ? $dl['error']
                        : 'Failed to download the update package.';

                    return $out;
                }
            } else {
                if (!is_readable($packagePath)) {
                    $out['errors'][] = 'Local package path is not readable.';

                    return $out;
                }
            }

            if ($sha256 !== '') {
                $out['steps'][] = 'verify_sha256';
                $verify = self::verifySha256($packagePath, $sha256);
                if (!$verify['ok']) {
                    $out['errors'][] = $verify['error'];

                    return $out;
                }
            }

            $stageDir = $tmpBase . '/stage';
            if (!self::ensureDir($stageDir)) {
                $out['errors'][] = 'Could not prepare the extraction staging directory.';

                return $out;
            }

            $out['steps'][] = 'extract';
            $extracted = self::extractPackage($packagePath, $stageDir);
            if (!$extracted['ok']) {
                $out['errors'] = array_merge($out['errors'], $extracted['errors']);

                return $out;
            }

            $packageRoot = $extracted['package_root'];
            $out['package_version'] = $extracted['package_version'];
            $out['steps'][] = 'validate_package';

            if ($expectedVersion !== '' && $extracted['package_version'] !== '') {
                // Allow package version to match expected; soft-warn on mismatch if both parseable.
                if (
                    class_exists('AP_Version_Check', false)
                    && AP_Version_Check::compareVersions($extracted['package_version'], $expectedVersion) !== 0
                ) {
                    $out['warnings'][] = 'Package version (' . $extracted['package_version']
                        . ') differs from the announced version (' . $expectedVersion . ').';
                }
            }

            if (
                $expectedVersion !== '' && $extracted['package_version'] !== ''
                && class_exists('AP_Version_Check', false)
                && !AP_Version_Check::isNewer($extracted['package_version'], $out['from_version'])
                && AP_Version_Check::compareVersions($extracted['package_version'], $out['from_version']) < 0
            ) {
                $out['errors'][] = 'The package version is older than the installed version; refusing to downgrade.';

                return $out;
            }

            // Maintenance mode for front-end visitors.
            $maintenanceOn = self::enableMaintenance($abspath);
            $out['steps'][] = $maintenanceOn ? 'maintenance_on' : 'maintenance_skipped';

            $out['steps'][] = 'apply_files';
            $applied = self::applyPackageFiles($packageRoot, $abspath);
            if (!$applied['ok']) {
                $out['errors'] = array_merge($out['errors'], $applied['errors']);
                $out['files_applied'] = $applied['files_applied'];

                return $out;
            }
            $out['files_applied'] = $applied['files_applied'];
            if ($applied['warnings'] !== []) {
                $out['warnings'] = array_merge($out['warnings'], $applied['warnings']);
            }

            // DB migrations (new code may have already been applied on disk).
            $migrations = [];
            if (empty($args['skip_migrate']) && $db instanceof AP_DB && class_exists('AP_Migrator', false)) {
                $out['steps'][] = 'migrate';
                try {
                    $migrator = new AP_Migrator($db, AP_Migrator::defaultMigrationsPath());
                    $migrations = $migrator->migrate();
                } catch (Throwable $e) {
                    $out['errors'][] = 'Files were updated but database migration failed: ' . $e->getMessage();
                    $out['migrations'] = $migrations;
                    // Still clear version cache / record partial state below.

                    return $out;
                }
            }
            $out['migrations'] = $migrations;

            $toVersion = $extracted['package_version'] !== ''
                ? $extracted['package_version']
                : $expectedVersion;
            $out['to_version'] = $toVersion;

            if (class_exists('AP_Options', false) && $db instanceof AP_DB) {
                try {
                    AP_Options::update('ap_version', $toVersion !== '' ? $toVersion : $out['from_version'], $db);
                    AP_Options::update(self::OPTION_LAST_UPDATE, json_encode([
                        'from' => $out['from_version'],
                        'to' => $toVersion,
                        'at' => time(),
                        'files' => $out['files_applied'],
                        'migrations' => count($migrations),
                    ], JSON_UNESCAPED_SLASHES), $db);
                } catch (Throwable) {
                    // Non-fatal.
                }
            }

            if (class_exists('AP_Version_Check', false) && class_exists('AP_Transient', false)) {
                try {
                    AP_Transient::delete(AP_Version_Check::TRANSIENT_KEY, $db);
                } catch (Throwable) {
                    // Ignore.
                }
            }

            if (function_exists('ap_do_action')) {
                ap_do_action('ap_core_updated', $out['from_version'], $toVersion, $out);
            }

            $out['ok'] = true;
            $out['steps'][] = 'complete';

            return $out;
        } catch (Throwable $e) {
            $out['errors'][] = 'Update failed: ' . $e->getMessage();

            return $out;
        } finally {
            if ($maintenanceOn) {
                self::disableMaintenance($abspath);
                $out['steps'][] = 'maintenance_off';
            }
            if ($tmpBase !== null) {
                self::removeDir($tmpBase);
            }
        }
    }

    /**
     * Whether maintenance mode is currently active for a given root.
     */
    public static function isMaintenanceMode(?string $abspath = null): bool
    {
        $root = $abspath !== null && $abspath !== ''
            ? rtrim(str_replace('\\', '/', $abspath), '/') . '/'
            : self::abspath();
        $file = $root . self::MAINTENANCE_FILE;
        if (!is_readable($file)) {
            return false;
        }
        $raw = (string) @file_get_contents($file);
        // Stale maintenance older than 30 minutes is ignored (crashed update).
        if (preg_match('/\$upgrading\s*=\s*(\d+)/', $raw, $m) === 1) {
            $ts = (int) $m[1];
            if ($ts > 0 && (time() - $ts) > 1800) {
                return false;
            }
        }

        return true;
    }

    /**
     * Enable maintenance mode (front-end 503). Idempotent.
     */
    public static function enableMaintenance(?string $abspath = null): bool
    {
        $root = $abspath !== null && $abspath !== ''
            ? rtrim(str_replace('\\', '/', $abspath), '/') . '/'
            : self::abspath();
        $file = $root . self::MAINTENANCE_FILE;
        $body = "<?php\n// AgoraPress maintenance — auto-update in progress.\n\$upgrading = " . time() . ";\n";

        return @file_put_contents($file, $body) !== false;
    }

    /**
     * Disable maintenance mode.
     */
    public static function disableMaintenance(?string $abspath = null): bool
    {
        $root = $abspath !== null && $abspath !== ''
            ? rtrim(str_replace('\\', '/', $abspath), '/') . '/'
            : self::abspath();
        $file = $root . self::MAINTENANCE_FILE;
        if (!is_file($file)) {
            return true;
        }

        return @unlink($file);
    }

    /**
     * HTML document for visitors while maintenance mode is active.
     */
    public static function maintenanceHtml(): string
    {
        return <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Briefly unavailable — AgoraPress</title>
    <style>
        :root { color-scheme: light dark; }
        body { margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center;
            font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif; background:#f4f5f7; color:#1a1a1a; }
        main { max-width:28rem; padding:2rem; background:#fff; border:1px solid #d8dbe0; border-radius:10px; }
        h1 { font-size:1.25rem; margin:0 0 .75rem; }
        p { margin:0; line-height:1.5; color:#5c6570; }
        @media (prefers-color-scheme: dark) {
            body { background:#12141a; color:#e8eaed; }
            main { background:#1c1f28; border-color:#2a2f3a; }
            p { color:#9aa3ad; }
        }
    </style>
</head>
<body>
<main>
    <h1>Site briefly unavailable</h1>
    <p>AgoraPress is installing an update. Please refresh in a moment.</p>
</main>
</body>
</html>
HTML;
    }

    /**
     * Whether a relative package path should be applied onto the site.
     * Public for unit tests.
     */
    public static function shouldApplyRelative(string $relative): bool
    {
        $rel = str_replace('\\', '/', $relative);
        $rel = ltrim($rel, '/');
        if ($rel === '' || str_contains($rel, "\0")) {
            return false;
        }
        // Path traversal.
        if ($rel === '..' || str_starts_with($rel, '../') || str_contains($rel, '/../') || str_ends_with($rel, '/..')) {
            return false;
        }

        foreach (self::PRESERVE_EXACT as $exact) {
            if ($rel === $exact) {
                return false;
            }
        }

        // Runtime / VCS noise.
        if ($rel === '.git' || str_starts_with($rel, '.git/')) {
            return false;
        }
        if ($rel === '.hephaestus' || str_starts_with($rel, '.hephaestus/')) {
            return false;
        }
        if ($rel === 'ap-content/uploads' || str_starts_with($rel, 'ap-content/uploads/')) {
            return false;
        }
        if ($rel === 'ap-content/plugins' || str_starts_with($rel, 'ap-content/plugins/')) {
            return false;
        }
        if ($rel === 'ap-content/mu-plugins' || str_starts_with($rel, 'ap-content/mu-plugins/')) {
            return false;
        }

        // Themes: only ship default agora from core packages.
        if ($rel === 'ap-content/themes' || str_starts_with($rel, 'ap-content/themes/')) {
            if ($rel === 'ap-content/themes/agora' || str_starts_with($rel, 'ap-content/themes/agora/')) {
                return true;
            }
            // Allow empty themes/.gitkeep style placeholders at themes root only.
            if ($rel === 'ap-content/themes' || $rel === 'ap-content/themes/index.php') {
                return true;
            }

            return false;
        }

        return true;
    }

    /**
     * Detect package root directory inside an extracted tree (absolute path).
     * Public for tests.
     *
     * @return array{ok: bool, package_root: string, package_version: string, error: string}
     */
    public static function detectPackageRoot(string $stageDir): array
    {
        $stageDir = rtrim(str_replace('\\', '/', $stageDir), '/');
        $candidates = [];

        if (self::looksLikeCoreRoot($stageDir)) {
            $candidates[] = $stageDir;
        }

        $entries = @scandir($stageDir);
        if (is_array($entries)) {
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $path = $stageDir . '/' . $entry;
                if (is_dir($path) && self::looksLikeCoreRoot($path)) {
                    $candidates[] = $path;
                }
            }
        }

        if ($candidates === []) {
            return [
                'ok' => false,
                'package_root' => '',
                'package_version' => '',
                'error' => 'The package does not look like an AgoraPress core release (missing ap-includes/version.php).',
            ];
        }
        if (count($candidates) > 1) {
            return [
                'ok' => false,
                'package_root' => '',
                'package_version' => '',
                'error' => 'The package contains multiple possible AgoraPress roots; refusing to apply.',
            ];
        }

        $root = $candidates[0];
        $version = self::readVersionFromPackage($root);

        return [
            'ok' => true,
            'package_root' => $root,
            'package_version' => $version,
            'error' => '',
        ];
    }

    /**
     * Inject HTTP transport (tests).
     */
    public static function setHttpTransport(?callable $transport): void
    {
        self::$httpTransport = $transport;
    }

    /**
     * Clear injected transport (tests).
     */
    public static function resetHttpTransport(): void
    {
        self::$httpTransport = null;
    }

    /**
     * Privacy invariant: updater never attaches site identity to requests.
     */
    public static function sendsSiteIdentity(): bool
    {
        return false;
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * @return array{
     *   ok: bool,
     *   can_update: bool,
     *   current_version: string,
     *   remote_version: string,
     *   download_url: string,
     *   changelog_url: string,
     *   sha256: string,
     *   has_update: bool,
     *   errors: list<string>,
     *   warnings: list<string>,
     *   checks: array<string, bool>
     * }
     */
    private static function emptyCanUpdate(): array
    {
        return [
            'ok' => false,
            'can_update' => false,
            'current_version' => '',
            'remote_version' => '',
            'download_url' => '',
            'changelog_url' => '',
            'sha256' => '',
            'has_update' => false,
            'errors' => [],
            'warnings' => [],
            'checks' => [],
        ];
    }

    /**
     * @return array{
     *   ok: bool,
     *   from_version: string,
     *   to_version: string,
     *   files_applied: int,
     *   migrations: list<array{version: int, description: string}>,
     *   package_version: string,
     *   errors: list<string>,
     *   warnings: list<string>,
     *   steps: list<string>
     * }
     */
    private static function emptyRunResult(): array
    {
        return [
            'ok' => false,
            'from_version' => '',
            'to_version' => '',
            'files_applied' => 0,
            'migrations' => [],
            'package_version' => '',
            'errors' => [],
            'warnings' => [],
            'steps' => [],
        ];
    }

    private static function abspath(): string
    {
        if (defined('AP_ABSPATH') && is_string(AP_ABSPATH) && AP_ABSPATH !== '') {
            return rtrim(str_replace('\\', '/', (string) AP_ABSPATH), '/') . '/';
        }

        return rtrim(str_replace('\\', '/', dirname(__DIR__)), '/') . '/';
    }

    private static function isHttpUrl(string $url): bool
    {
        return preg_match('#^https?://#i', trim($url)) === 1;
    }

    private static function isDirWritable(string $path): bool
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        if ($path === '') {
            return false;
        }
        if (is_dir($path)) {
            return is_writable($path);
        }

        return false;
    }

    private static function looksLikeCoreRoot(string $dir): bool
    {
        return is_readable($dir . '/ap-includes/version.php')
            && is_file($dir . '/index.php')
            && is_dir($dir . '/ap-admin');
    }

    private static function readVersionFromPackage(string $packageRoot): string
    {
        $file = $packageRoot . '/ap-includes/version.php';
        if (!is_readable($file)) {
            return '';
        }
        $src = (string) file_get_contents($file);
        if (preg_match("/define\\s*\\(\\s*['\"]AP_VERSION['\"]\\s*,\\s*['\"]([^'\"]+)['\"]\\s*\\)/", $src, $m) === 1) {
            $ver = trim((string) $m[1]);
            if (class_exists('AP_Version_Check', false)) {
                // normalize via compare helpers if available
                return $ver;
            }

            return $ver;
        }

        return '';
    }

    /**
     * @return array{ok: bool, error: string}
     */
    public static function verifySha256(string $filePath, string $expectedHex): array
    {
        $expectedHex = strtolower(trim($expectedHex));
        if ($expectedHex === '' || preg_match('/^[a-f0-9]{64}$/', $expectedHex) !== 1) {
            return ['ok' => false, 'error' => 'Invalid SHA-256 checksum in version metadata.'];
        }
        if (!is_readable($filePath)) {
            return ['ok' => false, 'error' => 'Package file is not readable for checksum verification.'];
        }
        $hash = hash_file('sha256', $filePath);
        if (!is_string($hash) || $hash === '') {
            return ['ok' => false, 'error' => 'Could not compute package checksum.'];
        }
        if (!hash_equals($expectedHex, strtolower($hash))) {
            return ['ok' => false, 'error' => 'Package checksum mismatch. The download may be corrupt or tampered with.'];
        }

        return ['ok' => true, 'error' => ''];
    }

    /**
     * @return array{ok: bool, error: string, bytes: int}
     */
    private static function downloadPackage(string $url, string $destPath, int $maxBytes): array
    {
        $url = trim($url);
        if (!self::isHttpUrl($url)) {
            return ['ok' => false, 'error' => 'Download URL must be http(s).', 'bytes' => 0];
        }

        $response = self::httpGetBinary($url, $maxBytes);
        if (!$response['ok']) {
            return [
                'ok' => false,
                'error' => $response['error'] !== '' ? $response['error'] : 'Download failed.',
                'bytes' => 0,
            ];
        }

        $body = $response['body'];
        $len = strlen($body);
        if ($len < 4) {
            return ['ok' => false, 'error' => 'Downloaded package is empty or too small.', 'bytes' => $len];
        }
        // PK zip magic.
        if ($body[0] !== 'P' || $body[1] !== 'K') {
            return ['ok' => false, 'error' => 'Downloaded file is not a zip archive.', 'bytes' => $len];
        }
        if ($maxBytes > 0 && $len > $maxBytes) {
            return ['ok' => false, 'error' => 'Downloaded package exceeds the maximum allowed size.', 'bytes' => $len];
        }

        if (@file_put_contents($destPath, $body) === false) {
            return ['ok' => false, 'error' => 'Could not write the package to a temporary file.', 'bytes' => $len];
        }

        return ['ok' => true, 'error' => '', 'bytes' => $len];
    }

    /**
     * @return array{ok: bool, status: int, body: string, error: string}
     */
    private static function httpGetBinary(string $url, int $maxBytes): array
    {
        if (self::$httpTransport !== null) {
            $result = (self::$httpTransport)('GET', $url);
            if (!is_array($result)) {
                return [
                    'ok' => false,
                    'status' => 0,
                    'body' => '',
                    'error' => 'Invalid updater transport response.',
                ];
            }

            return [
                'ok' => (bool) ($result['ok'] ?? false),
                'status' => (int) ($result['status'] ?? 0),
                'body' => (string) ($result['body'] ?? ''),
                'error' => (string) ($result['error'] ?? ''),
            ];
        }

        // Prefer sharing Version_Check transport if set? Keep independent for binary size.
        if (function_exists('curl_init')) {
            return self::httpViaCurl($url, $maxBytes);
        }

        return self::httpViaStream($url, $maxBytes);
    }

    /**
     * @return array{ok: bool, status: int, body: string, error: string}
     */
    private static function httpViaCurl(string $url, int $maxBytes): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return [
                'ok' => false,
                'status' => 0,
                'body' => '',
                'error' => 'Could not initialize HTTP client for package download.',
            ];
        }

        $ua = 'AgoraPress/' . (defined('AP_VERSION') ? (string) AP_VERSION : 'dev')
            . ' (CoreUpdater; no-site-id)';

        curl_setopt_array($ch, [
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/zip, application/octet-stream, */*',
                'User-Agent: ' . $ua,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_PROTOCOLS => defined('CURLPROTO_HTTPS')
                ? (CURLPROTO_HTTPS | CURLPROTO_HTTP)
                : 3,
            CURLOPT_REDIR_PROTOCOLS => defined('CURLPROTO_HTTPS')
                ? (CURLPROTO_HTTPS | CURLPROTO_HTTP)
                : 3,
        ]);

        if ($maxBytes > 0) {
            // Abort if Content-Length is huge; still enforce after download.
            curl_setopt($ch, CURLOPT_NOPROGRESS, false);
            curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, static function (
                $resource,
                float $dlTotal,
                float $dlNow
            ) use ($maxBytes): int {
                if ($dlTotal > $maxBytes || $dlNow > $maxBytes) {
                    return 1; // abort
                }

                return 0;
            });
        }

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            return [
                'ok' => false,
                'status' => $status,
                'body' => '',
                'error' => $err !== '' ? $err : 'Package download failed.',
            ];
        }

        $ok = $status >= 200 && $status < 300;

        return [
            'ok' => $ok,
            'status' => $status,
            'body' => (string) $body,
            'error' => $ok ? '' : 'HTTP ' . $status,
        ];
    }

    /**
     * @return array{ok: bool, status: int, body: string, error: string}
     */
    private static function httpViaStream(string $url, int $maxBytes): array
    {
        $ua = 'AgoraPress/' . (defined('AP_VERSION') ? (string) AP_VERSION : 'dev')
            . ' (CoreUpdater; no-site-id)';
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "Accept: application/zip, application/octet-stream, */*\r\nUser-Agent: {$ua}\r\n",
                'timeout' => 120,
                'ignore_errors' => true,
                'follow_location' => 1,
                'max_redirects' => 3,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        // Populated by the HTTP stream wrapper after file_get_contents().
        $status = self::statusFromHttpHeaders($http_response_header);

        if ($body === false) {
            return [
                'ok' => false,
                'status' => $status,
                'body' => '',
                'error' => 'Could not download the update package.',
            ];
        }

        if ($maxBytes > 0 && strlen($body) > $maxBytes) {
            return [
                'ok' => false,
                'status' => $status,
                'body' => '',
                'error' => 'Downloaded package exceeds the maximum allowed size.',
            ];
        }

        $ok = $status === 0 || ($status >= 200 && $status < 300);

        return [
            'ok' => $ok,
            'status' => $status,
            'body' => (string) $body,
            'error' => $ok ? '' : 'HTTP ' . $status,
        ];
    }

    /**
     * Parse status code from HTTP stream response headers.
     *
     * @param list<string> $headers Lines from $http_response_header after file_get_contents().
     */
    private static function statusFromHttpHeaders(array $headers): int
    {
        foreach ($headers as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', (string) $line, $m) === 1) {
                return (int) $m[1];
            }
        }

        return 0;
    }

    /**
     * @return array{
     *   ok: bool,
     *   package_root: string,
     *   package_version: string,
     *   errors: list<string>
     * }
     */
    private static function extractPackage(string $zipPath, string $stageDir): array
    {
        $errors = [];
        $zipPath = (string) realpath($zipPath);
        if ($zipPath === '' || !is_readable($zipPath)) {
            return [
                'ok' => false,
                'package_root' => '',
                'package_version' => '',
                'errors' => ['Package zip is missing or not readable.'],
            ];
        }

        $zip = new ZipArchive();
        $open = $zip->open($zipPath, ZipArchive::RDONLY);
        if ($open !== true) {
            $open = $zip->open($zipPath);
        }
        if ($open !== true) {
            return [
                'ok' => false,
                'package_root' => '',
                'package_version' => '',
                'errors' => ['Could not open the update zip (corrupt or unsupported).'],
            ];
        }

        try {
            $scan = self::validateZipEntries($zip);
            if ($scan['error'] !== '') {
                return [
                    'ok' => false,
                    'package_root' => '',
                    'package_version' => '',
                    'errors' => [$scan['error']],
                ];
            }

            if (!$zip->extractTo($stageDir)) {
                return [
                    'ok' => false,
                    'package_root' => '',
                    'package_version' => '',
                    'errors' => ['Could not extract the update package.'],
                ];
            }
        } finally {
            $zip->close();
        }

        $detected = self::detectPackageRoot($stageDir);
        if (!$detected['ok']) {
            return [
                'ok' => false,
                'package_root' => '',
                'package_version' => '',
                'errors' => [$detected['error']],
            ];
        }

        return [
            'ok' => true,
            'package_root' => $detected['package_root'],
            'package_version' => $detected['package_version'],
            'errors' => $errors,
        ];
    }

    /**
     * @return array{error: string}
     */
    private static function validateZipEntries(ZipArchive $zip): array
    {
        $count = $zip->numFiles;
        if ($count < 1) {
            return ['error' => 'Update zip is empty.'];
        }
        if ($count > self::MAX_ZIP_FILES) {
            return ['error' => 'Update zip contains too many files.'];
        }

        $total = 0;
        for ($i = 0; $i < $count; $i++) {
            $stat = $zip->statIndex($i);
            if (!is_array($stat)) {
                continue;
            }
            $name = str_replace('\\', '/', (string) ($stat['name'] ?? ''));
            if ($name === '' || str_ends_with($name, '/')) {
                continue;
            }
            if (str_contains($name, "\0")) {
                return ['error' => 'Update zip contains an invalid entry name.'];
            }
            // Absolute paths / traversal.
            if ($name[0] === '/' || preg_match('#(^|/)\.\.(/|$)#', $name) === 1) {
                return ['error' => 'Update zip contains an unsafe path (traversal).'];
            }
            // Windows drive paths.
            if (preg_match('#^[A-Za-z]:#', $name) === 1) {
                return ['error' => 'Update zip contains an absolute Windows path.'];
            }
            $size = (int) ($stat['size'] ?? 0);
            if ($size > self::MAX_ENTRY_BYTES) {
                return ['error' => 'Update zip contains a file that is too large.'];
            }
            $total += max(0, $size);
            if ($total > self::MAX_UNCOMPRESSED_BYTES) {
                return ['error' => 'Update zip uncompressed size exceeds the safety limit.'];
            }
        }

        return ['error' => ''];
    }

    /**
     * @return array{
     *   ok: bool,
     *   files_applied: int,
     *   errors: list<string>,
     *   warnings: list<string>
     * }
     */
    private static function applyPackageFiles(string $packageRoot, string $abspath): array
    {
        $packageRoot = rtrim(str_replace('\\', '/', $packageRoot), '/');
        $abspath = rtrim(str_replace('\\', '/', $abspath), '/') . '/';

        $files = self::listFilesRecursive($packageRoot);
        if ($files === []) {
            return [
                'ok' => false,
                'files_applied' => 0,
                'errors' => ['Package root has no files to apply.'],
                'warnings' => [],
            ];
        }

        $applied = 0;
        $warnings = [];
        $errors = [];

        foreach ($files as $absFile) {
            $absFile = str_replace('\\', '/', $absFile);
            if (!str_starts_with($absFile, $packageRoot . '/')) {
                continue;
            }
            $rel = substr($absFile, strlen($packageRoot) + 1);
            if ($rel === false || $rel === '') {
                continue;
            }
            if (!self::shouldApplyRelative($rel)) {
                continue;
            }

            $dest = $abspath . $rel;
            $destDir = dirname($dest);
            if (!self::ensureDir($destDir)) {
                $errors[] = 'Could not create directory for ' . $rel . '.';

                return [
                    'ok' => false,
                    'files_applied' => $applied,
                    'errors' => $errors,
                    'warnings' => $warnings,
                ];
            }

            // Prefer copy; overwrite existing core files.
            if (!@copy($absFile, $dest)) {
                // Retry with unlink for read-only oddities.
                if (is_file($dest)) {
                    @unlink($dest);
                }
                if (!@copy($absFile, $dest)) {
                    $errors[] = 'Could not write ' . $rel . '.';

                    return [
                        'ok' => false,
                        'files_applied' => $applied,
                        'errors' => $errors,
                        'warnings' => $warnings,
                    ];
                }
            }
            $applied++;
        }

        if ($applied < 1) {
            return [
                'ok' => false,
                'files_applied' => 0,
                'errors' => ['No package files were eligible to apply.'],
                'warnings' => $warnings,
            ];
        }

        return [
            'ok' => true,
            'files_applied' => $applied,
            'errors' => [],
            'warnings' => $warnings,
        ];
    }

    /**
     * @return list<string>
     */
    private static function listFilesRecursive(string $dir): array
    {
        $dir = rtrim(str_replace('\\', '/', $dir), '/');
        $out = [];
        if (!is_dir($dir)) {
            return $out;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $fileInfo) {
            /** @var SplFileInfo $fileInfo */
            if ($fileInfo->isFile()) {
                $out[] = str_replace('\\', '/', $fileInfo->getPathname());
            }
        }

        return $out;
    }

    private static function ensureDir(string $path): bool
    {
        if (is_dir($path)) {
            return is_writable($path) || is_writable(dirname($path));
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
}
