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
 * - Never overwrites ap-config.php, ap-config-sample.php, the install/
 *   directory, plugins, mu-plugins, non-default themes, or operator
 *   files under ap-content/uploads/ (the uploads .htaccess guard is
 *   installed only when that file is missing)
 * - An existing root .htaccess or ap-content/uploads/.htaccess is not
 *   replaced. A differing package copy is written to .htaccess.ap-new
 *   in that directory and a notice is logged.
 * - After files and migrations, a short loopback GET of the home URL and
 *   /ap-json/ runs. HTTP 5xx restores the pre-update files and .htaccess.
 *   If the loopback cannot be reached, the update stands and a warning
 *   is recorded.
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

    /** Sidecar written when a package .htaccess differs from the live file. */
    public const HTACCESS_SIDECAR = '.htaccess.ap-new';

    /**
     * Uploads guard shipped in the package. Installed only when missing.
     * An existing file is left in place (see placePreservedHtaccess()).
     */
    public const UPLOADS_HTACCESS_REL = 'ap-content/uploads/.htaccess';

    /**
     * Notice when the live root .htaccess is left in place.
     * Shown in the update result and written to the PHP error log.
     */
    public const HTACCESS_DEFERRED_NOTICE =
        'Existing root .htaccess differs from the package and was not replaced. '
        . 'The package copy is at .htaccess.ap-new.';

    /** Loopback probe timeout (seconds) for the home URL and /ap-json/. */
    public const PROBE_TIMEOUT_SECONDS = 5;

    /** Loopback probe connect timeout (seconds). */
    public const PROBE_CONNECT_TIMEOUT_SECONDS = 3;

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
        'ap-config-sample.php',
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
     *   steps: list<string>,
     *   rolled_back: bool,
     *   htaccess_deferred: bool
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

            $out['steps'][] = 'backup';
            $backupDir = $tmpBase . '/pre-update';
            $backup = self::backupApplyTargets($packageRoot, $abspath, $backupDir);
            if (!$backup['ok']) {
                $out['errors'][] = $backup['error'] !== ''
                    ? $backup['error']
                    : 'Could not back up the current files before applying the update.';

                return $out;
            }

            $out['steps'][] = 'apply_files';
            $applied = self::applyPackageFiles($packageRoot, $abspath);
            $out['files_applied'] = $applied['files_applied'];
            $out['htaccess_deferred'] = $applied['htaccess_deferred'];
            if ($applied['warnings'] !== []) {
                $out['warnings'] = array_merge($out['warnings'], $applied['warnings']);
            }
            if (!$applied['ok']) {
                $out['errors'] = array_merge($out['errors'], $applied['errors']);

                return $out;
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

            // Maintenance returns 503. Drop it before the probe so that
            // response is not treated as a failed update.
            if ($maintenanceOn) {
                self::disableMaintenance($abspath);
                $maintenanceOn = false;
                $out['steps'][] = 'maintenance_off';
            }

            $probe = self::probeLoopback($db);
            $out['steps'][] = $probe['step'];
            if ($probe['warning'] !== '') {
                $out['warnings'][] = $probe['warning'];
            }
            if ($probe['outcome'] === 'failed') {
                self::logNotice($probe['error']);
                $restored = self::restoreBackup($backupDir, $abspath, $backup['files']);
                self::disableMaintenance($abspath);
                $out['steps'][] = 'rolled_back';
                $out['errors'][] = $probe['error'];
                if ($restored['ok']) {
                    $out['rolled_back'] = true;
                } else {
                    $out['errors'][] = 'Automatic rollback failed: ' . $restored['error'];
                }

                return $out;
            }

            if (class_exists('AP_Options', false) && $db instanceof AP_DB) {
                try {
                    AP_Options::update('ap_version', $toVersion !== '' ? $toVersion : $out['from_version'], $db);
                    AP_Options::update(self::OPTION_LAST_UPDATE, json_encode([
                        'from' => $out['from_version'],
                        'to' => $toVersion,
                        'at' => time(),
                        'files' => $out['files_applied'],
                        'migrations' => count($migrations),
                        'warnings' => $out['warnings'],
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
        // Installer is for fresh installs only — never rewrite on update.
        if ($rel === 'install' || str_starts_with($rel, 'install/')) {
            return false;
        }
        if ($rel === 'ap-content/uploads' || str_starts_with($rel, 'ap-content/uploads/')) {
            // Operator media stays. The guard file is installed only when missing.
            return $rel === self::UPLOADS_HTACCESS_REL;
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
     *   steps: list<string>,
     *   rolled_back: bool,
     *   htaccess_deferred: bool
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
            'rolled_back' => false,
            'htaccess_deferred' => false,
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
     *   warnings: list<string>,
     *   htaccess_deferred: bool
     * }
     */
    private static function applyPackageFiles(string $packageRoot, string $abspath): array
    {
        $packageRoot = rtrim(str_replace('\\', '/', $packageRoot), '/');
        $abspath = rtrim(str_replace('\\', '/', $abspath), '/') . '/';

        if (self::listFilesRecursive($packageRoot) === []) {
            return self::applyResult(false, 0, ['Package root has no files to apply.'], [], false);
        }

        $rels = self::listApplyRelative($packageRoot);
        if ($rels === []) {
            return self::applyResult(false, 0, ['No package files were eligible to apply.'], [], false);
        }

        $applied = 0;
        $warnings = [];
        $deferred = false;

        foreach ($rels as $rel) {
            $source = $packageRoot . '/' . $rel;
            $dest = $abspath . $rel;
            if (self::isPreservedHtaccess($rel)) {
                $placed = self::placePreservedHtaccess($source, $dest, $rel, $warnings);
                if ($placed['deferred']) {
                    $deferred = true;
                }
                if (!$placed['ok']) {
                    return self::applyResult(false, $applied, [$placed['error']], $warnings, $deferred);
                }
                if ($placed['wrote']) {
                    $applied++;
                }
                continue;
            }
            if (!self::copyOnto($source, $dest)) {
                return self::applyResult(
                    false,
                    $applied,
                    ['Could not write ' . $rel . '.'],
                    $warnings,
                    $deferred
                );
            }
            $applied++;
        }

        if ($applied < 1) {
            return self::applyResult(false, 0, ['No package files were eligible to apply.'], $warnings, $deferred);
        }

        return self::applyResult(true, $applied, [], $warnings, $deferred);
    }

    /**
     * @param list<string> $errors
     * @param list<string> $warnings
     *
     * @return array{
     *   ok: bool,
     *   files_applied: int,
     *   errors: list<string>,
     *   warnings: list<string>,
     *   htaccess_deferred: bool
     * }
     */
    private static function applyResult(
        bool $ok,
        int $applied,
        array $errors,
        array $warnings,
        bool $deferred
    ): array {
        return [
            'ok' => $ok,
            'files_applied' => $applied,
            'errors' => $errors,
            'warnings' => $warnings,
            'htaccess_deferred' => $deferred,
        ];
    }

    /**
     * Relative paths inside the package that this update will copy.
     *
     * @return list<string>
     */
    private static function listApplyRelative(string $packageRoot): array
    {
        $packageRoot = rtrim(str_replace('\\', '/', $packageRoot), '/');
        $rels = [];
        foreach (self::listFilesRecursive($packageRoot) as $absFile) {
            $absFile = str_replace('\\', '/', $absFile);
            if (!str_starts_with($absFile, $packageRoot . '/')) {
                continue;
            }
            $rel = substr($absFile, strlen($packageRoot) + 1);
            if ($rel === '' || str_contains($rel, "\0")) {
                continue;
            }
            if (!self::shouldApplyRelative($rel)) {
                continue;
            }
            $rels[] = $rel;
        }

        return $rels;
    }

    /**
     * Root and uploads guard files are never overwritten once they exist.
     */
    private static function isPreservedHtaccess(string $rel): bool
    {
        return $rel === '.htaccess' || $rel === self::UPLOADS_HTACCESS_REL;
    }

    /**
     * Copy a package .htaccess into place only when the site has none.
     * A different live file is left alone and the package bytes go to
     * .htaccess.ap-new in the same directory.
     *
     * @param list<string> $warnings
     *
     * @return array{ok: bool, wrote: bool, deferred: bool, error: string}
     */
    private static function placePreservedHtaccess(string $source, string $dest, string $rel, array &$warnings): array
    {
        $writeError = $rel === '.htaccess'
            ? 'Could not write .htaccess.'
            : 'Could not write ' . $rel . '.';
        $readError = $rel === '.htaccess'
            ? 'Could not read .htaccess while deciding whether to replace it.'
            : 'Could not read ' . $rel . ' while deciding whether to replace it.';

        if (!is_file($dest)) {
            if (!self::copyOnto($source, $dest)) {
                return [
                    'ok' => false,
                    'wrote' => false,
                    'deferred' => false,
                    'error' => $writeError,
                ];
            }

            return ['ok' => true, 'wrote' => true, 'deferred' => false, 'error' => ''];
        }

        $current = @file_get_contents($dest);
        $incoming = @file_get_contents($source);
        if (!is_string($current) || !is_string($incoming)) {
            return [
                'ok' => false,
                'wrote' => false,
                'deferred' => false,
                'error' => $readError,
            ];
        }
        if (!self::htaccessBodiesDiffer($current, $incoming)) {
            return ['ok' => true, 'wrote' => false, 'deferred' => false, 'error' => ''];
        }

        $sidecarRel = self::htaccessSidecarRelative($rel);
        $sidecar = dirname($dest) . '/' . self::HTACCESS_SIDECAR;
        if (@file_put_contents($sidecar, $incoming) === false) {
            $notice = $rel === '.htaccess'
                ? 'Existing root .htaccess was left in place, but .htaccess.ap-new could not be written.'
                : 'Existing ' . $rel . ' was left in place, but ' . $sidecarRel . ' could not be written.';
            $warnings[] = $notice;
            self::logNotice($notice);

            return ['ok' => true, 'wrote' => false, 'deferred' => true, 'error' => ''];
        }

        $notice = self::deferredHtaccessNotice($rel);
        $warnings[] = $notice;
        self::logNotice($notice);

        return ['ok' => true, 'wrote' => true, 'deferred' => true, 'error' => ''];
    }

    /**
     * Notice when a live guard file is left in place.
     */
    private static function deferredHtaccessNotice(string $rel): string
    {
        if ($rel === '.htaccess') {
            return self::HTACCESS_DEFERRED_NOTICE;
        }

        return 'Existing ' . $rel . ' differs from the package and was not replaced. '
            . 'The package copy is at ' . self::htaccessSidecarRelative($rel) . '.';
    }

    private static function htaccessSidecarRelative(string $rel): string
    {
        $dir = str_replace('\\', '/', dirname($rel));
        if ($dir === '.' || $dir === '') {
            return self::HTACCESS_SIDECAR;
        }

        return $dir . '/' . self::HTACCESS_SIDECAR;
    }

    private static function htaccessBodiesDiffer(string $current, string $incoming): bool
    {
        return !hash_equals(self::normalizeNewlines($current), self::normalizeNewlines($incoming));
    }

    private static function normalizeNewlines(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        return rtrim($text, "\n") . "\n";
    }

    /**
     * Snapshot files this update may replace, including root and uploads .htaccess.
     *
     * @return array{ok: bool, files: list<array{rel: string, existed: bool}>, error: string}
     */
    private static function backupApplyTargets(string $packageRoot, string $abspath, string $backupDir): array
    {
        $abspath = rtrim(str_replace('\\', '/', $abspath), '/') . '/';
        $backupDir = rtrim(str_replace('\\', '/', $backupDir), '/');
        if (!self::ensureDir($backupDir)) {
            return [
                'ok' => false,
                'files' => [],
                'error' => 'Could not create the pre-update backup directory.',
            ];
        }

        $rels = self::listApplyRelative($packageRoot);
        foreach (['.htaccess', self::UPLOADS_HTACCESS_REL] as $guard) {
            if (!in_array($guard, $rels, true) && is_file($abspath . $guard)) {
                $rels[] = $guard;
            }
        }

        $files = [];
        foreach ($rels as $rel) {
            if ($rel === '' || str_contains($rel, '..') || str_contains($rel, "\0")) {
                return [
                    'ok' => false,
                    'files' => [],
                    'error' => 'Refusing to back up an unsafe path.',
                ];
            }
            $live = $abspath . $rel;
            $existed = is_file($live);
            if ($existed && !self::copyOnto($live, $backupDir . '/' . $rel)) {
                return [
                    'ok' => false,
                    'files' => [],
                    'error' => 'Could not back up ' . $rel . ' before applying the update.',
                ];
            }
            $files[] = ['rel' => $rel, 'existed' => $existed];
        }

        return ['ok' => true, 'files' => $files, 'error' => ''];
    }

    /**
     * Put the pre-update tree back. .htaccess.ap-new is not removed: Apache
     * does not read it, and the operator can use it to replace a bad file.
     *
     * @param list<array{rel: string, existed: bool}> $files
     *
     * @return array{ok: bool, error: string}
     */
    private static function restoreBackup(string $backupDir, string $abspath, array $files): array
    {
        $abspath = rtrim(str_replace('\\', '/', $abspath), '/') . '/';
        $backupDir = rtrim(str_replace('\\', '/', $backupDir), '/');
        $problems = [];
        foreach ($files as $entry) {
            $rel = str_replace('\\', '/', (string) ($entry['rel'] ?? ''));
            if ($rel === '' || str_contains($rel, '..') || str_contains($rel, "\0")) {
                $problems[] = 'Refusing to restore an unsafe path.';
                continue;
            }
            $dest = $abspath . $rel;
            if (!empty($entry['existed'])) {
                $src = $backupDir . '/' . $rel;
                if (!is_file($src) || !self::copyOnto($src, $dest)) {
                    $problems[] = 'Could not restore ' . $rel . '.';
                }
                continue;
            }
            if (is_file($dest) && !@unlink($dest)) {
                $problems[] = 'Could not remove ' . $rel . '.';
            }
        }
        if ($problems !== []) {
            return ['ok' => false, 'error' => implode(' ', $problems)];
        }

        return ['ok' => true, 'error' => ''];
    }

    private static function copyOnto(string $source, string $dest): bool
    {
        if (!is_file($source)) {
            return false;
        }
        if (!self::ensureDir(dirname($dest))) {
            return false;
        }
        if (@copy($source, $dest)) {
            return true;
        }
        if (is_file($dest)) {
            @unlink($dest);
        }

        return @copy($source, $dest);
    }

    /**
     * Loopback GET of the stored home URL and /ap-json/.
     *
     * Uses the site options only. Process constants such as AP_SITEURL are
     * not consulted: tests and CLI flags can define those for a different
     * tree than the one just updated.
     *
     * @return array{outcome: string, step: string, error: string, warning: string}
     */
    private static function probeLoopback(?AP_DB $db): array
    {
        $skipped = [
            'outcome' => 'skipped',
            'step' => 'health_probe_skipped',
            'error' => '',
            'warning' => 'Loopback health probe skipped: home URL is not available. '
                . 'The update was not rolled back.',
        ];
        $home = self::resolveHomeUrl($db);
        if (!self::isProbeUrl($home)) {
            self::logNotice($skipped['warning']);

            return $skipped;
        }

        $home = rtrim($home, '/');
        $urls = [$home . '/', $home . '/ap-json/'];
        $failures = [];
        $unreachable = [];
        foreach ($urls as $url) {
            try {
                $response = self::httpProbe($url);
            } catch (Throwable) {
                $unreachable[] = $url;
                continue;
            }
            $status = (int) ($response['status'] ?? 0);
            if ($status >= 500 && $status <= 599) {
                $failures[] = 'HTTP ' . $status . ' for ' . $url;
                continue;
            }
            if ($status >= 200 && $status < 500) {
                continue;
            }
            $unreachable[] = $url;
        }

        if ($failures !== []) {
            return [
                'outcome' => 'failed',
                'step' => 'health_probe_failed',
                'error' => 'Health probe failed: ' . implode('; ', $failures)
                    . '. The update was rolled back and maintenance mode is off.',
                'warning' => '',
            ];
        }

        if ($unreachable !== []) {
            $warning = 'Loopback health probe skipped: ' . implode(', ', $unreachable)
                . ' could not be reached. The update was not rolled back.';
            self::logNotice($warning);
            $all = count($unreachable) === count($urls);

            return [
                'outcome' => $all ? 'skipped' : 'ok',
                'step' => $all ? 'health_probe_skipped' : 'health_probe',
                'error' => '',
                'warning' => $warning,
            ];
        }

        return [
            'outcome' => 'ok',
            'step' => 'health_probe',
            'error' => '',
            'warning' => '',
        ];
    }

    /**
     * Home URL for the health probe. Empty when the site has not stored one.
     */
    private static function resolveHomeUrl(?AP_DB $db): string
    {
        if (!$db instanceof AP_DB || !class_exists('AP_Options', false)) {
            return '';
        }
        foreach (['home', 'siteurl'] as $key) {
            $raw = AP_Options::get($key, '', $db);
            if (!is_string($raw)) {
                continue;
            }
            $url = trim($raw);
            if ($url !== '') {
                return $url;
            }
        }

        return '';
    }

    private static function isProbeUrl(string $url): bool
    {
        $url = trim($url);
        if ($url === '' || preg_match('/[\r\n\0]/', $url) === 1 || !self::isHttpUrl($url)) {
            return false;
        }
        $user = parse_url($url, PHP_URL_USER);
        $pass = parse_url($url, PHP_URL_PASS);

        return ($user === null || $user === false || $user === '')
            && ($pass === null || $pass === false || $pass === '');
    }

    /**
     * @return array{ok: bool, status: int, body: string, error: string}
     */
    private static function httpProbe(string $url): array
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
        if (function_exists('curl_init')) {
            return self::probeViaCurl($url);
        }

        return self::probeViaStream($url);
    }

    /**
     * @return array{ok: bool, status: int, body: string, error: string}
     */
    private static function probeViaCurl(string $url): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return [
                'ok' => false,
                'status' => 0,
                'body' => '',
                'error' => 'Could not initialize HTTP client for the health probe.',
            ];
        }

        $body = '';
        curl_setopt_array($ch, [
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => [
                'Accept: text/html, application/json, */*',
                'User-Agent: ' . self::updaterUserAgent(),
            ],
            CURLOPT_TIMEOUT => self::PROBE_TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => self::PROBE_CONNECT_TIMEOUT_SECONDS,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 2,
            CURLOPT_PROTOCOLS => defined('CURLPROTO_HTTPS')
                ? (CURLPROTO_HTTPS | CURLPROTO_HTTP)
                : 3,
            CURLOPT_REDIR_PROTOCOLS => defined('CURLPROTO_HTTPS')
                ? (CURLPROTO_HTTPS | CURLPROTO_HTTP)
                : 3,
            CURLOPT_WRITEFUNCTION => static function ($resource, string $chunk) use (&$body): int {
                // Signature is fixed by libcurl. $resource is the transfer handle.
                if ($chunk === '' && $resource === false) {
                    return 0;
                }
                if (strlen($body) < 65536) {
                    $body .= $chunk;
                }

                return strlen($chunk);
            },
        ]);

        $exec = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($exec === false) {
            return [
                'ok' => false,
                'status' => $status,
                'body' => '',
                'error' => $err !== '' ? $err : 'Health probe request failed.',
            ];
        }

        $ok = $status >= 200 && $status < 500;

        return [
            'ok' => $ok,
            'status' => $status,
            'body' => $body,
            'error' => $ok ? '' : 'HTTP ' . $status,
        ];
    }

    /**
     * @return array{ok: bool, status: int, body: string, error: string}
     */
    private static function probeViaStream(string $url): array
    {
        $ua = self::updaterUserAgent();
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "Accept: text/html, application/json, */*\r\nUser-Agent: {$ua}\r\n",
                'timeout' => self::PROBE_TIMEOUT_SECONDS,
                'ignore_errors' => true,
                'follow_location' => 1,
                'max_redirects' => 2,
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
                'error' => 'Could not reach the site for the health probe.',
            ];
        }
        if (strlen($body) > 65536) {
            $body = substr($body, 0, 65536);
        }
        $answered = $status >= 200 && $status < 500;
        $error = '';
        if (!$answered) {
            $error = $status > 0
                ? 'HTTP ' . $status
                : 'Could not reach the site for the health probe.';
        }

        return [
            'ok' => $answered,
            'status' => $status,
            'body' => $body,
            'error' => $error,
        ];
    }

    private static function updaterUserAgent(): string
    {
        return 'AgoraPress/' . (defined('AP_VERSION') ? (string) AP_VERSION : 'dev')
            . ' (CoreUpdater; no-site-id)';
    }

    private static function logNotice(string $message): void
    {
        $message = trim(str_replace(["\r", "\n"], ' ', $message));
        if ($message === '') {
            return;
        }
        error_log('AgoraPress core update: ' . $message);
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
