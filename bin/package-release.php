#!/usr/bin/env php
<?php

/**
 * Build a production release package (zip + sha256) for AgoraPress.
 *
 * Designed for operators who publish download_url packages consumed by
 * {@see AP_Core_Updater} and for fresh installs (extract + web/CLI installer).
 *
 * Usage:
 *   php bin/package-release.php
 *   php bin/package-release.php --output-dir=/tmp/dist
 *   php bin/package-release.php --version=0.1.0
 *   php bin/package-release.php --prefix=AgoraPress-0.1.0
 *   php bin/package-release.php --dry-run
 *   php bin/package-release.php --json
 *   php bin/package-release.php --help
 *
 * Outputs (default under dist/):
 *   AgoraPress-{version}.zip
 *   AgoraPress-{version}.sha256
 *   version.json.example   (template for the public version endpoint)
 *
 * Excludes development-only paths (tests, vendor, CI, VCS, caches, secrets).
 * Zero production Composer packages — vendor/ is never shipped.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "package-release.php must be run from the CLI.\n");
    exit(1);
}

if (!class_exists(ZipArchive::class)) {
    fwrite(STDERR, "ZipArchive is required (ext-zip).\n");
    exit(1);
}

$root = realpath(dirname(__DIR__));
if ($root === false || !is_dir($root)) {
    fwrite(STDERR, "Unable to resolve repository root.\n");
    exit(1);
}

$opts = parseCliArgs($argv);
if (!empty($opts['help'])) {
    printHelp();
    exit(0);
}

$version = $opts['version'] !== ''
    ? $opts['version']
    : readApVersion($root . '/ap-includes/version.php');

if ($version === '' || !isSafeVersionLabel($version)) {
    fwrite(STDERR, "Invalid or missing AP_VERSION (got: {$version}).\n");
    exit(1);
}

$prefix = $opts['prefix'] !== ''
    ? $opts['prefix']
    : 'AgoraPress';

if (!isSafePrefix($prefix)) {
    fwrite(STDERR, "Invalid --prefix (use a single path segment, letters/numbers/._-).\n");
    exit(1);
}

$outputDir = $opts['output-dir'] !== ''
    ? $opts['output-dir']
    : $root . '/dist';

if (!str_starts_with($outputDir, '/')) {
    $outputDir = $root . '/' . ltrim($outputDir, '/');
}

$zipName = 'AgoraPress-' . sanitizeFileVersion($version) . '.zip';
$shaName = 'AgoraPress-' . sanitizeFileVersion($version) . '.sha256';
$zipPath = rtrim($outputDir, '/\\') . '/' . $zipName;
$shaPath = rtrim($outputDir, '/\\') . '/' . $shaName;
$versionJsonPath = rtrim($outputDir, '/\\') . '/version.json.example';

$files = collectPackageFiles($root);
if ($files === []) {
    fwrite(STDERR, "No files selected for packaging — aborting.\n");
    exit(1);
}

$requiredRelative = [
    'index.php',
    'ap-includes/version.php',
    'ap-admin/index.php',
    'ap-config-sample.php',
    'LICENSE',
    'CHANGELOG.md',
    'install/index.php',
    'ap-cli',
    'ap-content/themes/agora/style.css',
];

foreach ($requiredRelative as $rel) {
    $found = false;
    foreach ($files as $f) {
        if ($f['relative'] === $rel) {
            $found = true;
            break;
        }
    }
    if (!$found) {
        fwrite(STDERR, "Required package path missing from selection: {$rel}\n");
        exit(1);
    }
}

$result = [
    'ok' => true,
    'version' => $version,
    'prefix' => $prefix,
    'file_count' => count($files),
    'output_dir' => $outputDir,
    'zip' => $zipPath,
    'sha256_file' => $shaPath,
    'version_json_example' => $versionJsonPath,
    'sha256' => '',
    'bytes' => 0,
    'dry_run' => (bool) $opts['dry-run'],
];

if ($opts['dry-run']) {
    if ($opts['json']) {
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    } else {
        fwrite(STDOUT, "Dry run: would package " . count($files) . " files as {$prefix}/…\n");
        fwrite(STDOUT, "Version: {$version}\n");
        fwrite(STDOUT, "Zip:     {$zipPath}\n");
        fwrite(STDOUT, "SHA-256: {$shaPath}\n");
        fwrite(STDOUT, "Example: {$versionJsonPath}\n");
    }
    exit(0);
}

if (!is_dir($outputDir) && !mkdir($outputDir, 0755, true) && !is_dir($outputDir)) {
    fwrite(STDERR, "Unable to create output directory: {$outputDir}\n");
    exit(1);
}

if (is_file($zipPath) && !unlink($zipPath)) {
    fwrite(STDERR, "Unable to replace existing zip: {$zipPath}\n");
    exit(1);
}

$zip = new ZipArchive();
$open = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
if ($open !== true) {
    fwrite(STDERR, "Unable to open zip for writing (code {$open}): {$zipPath}\n");
    exit(1);
}

foreach ($files as $file) {
    $entry = $prefix . '/' . $file['relative'];
    if ($file['is_dir']) {
        $zip->addEmptyDir(rtrim($entry, '/') . '/');
        continue;
    }
    if (!$zip->addFile($file['absolute'], $entry)) {
        $zip->close();
        @unlink($zipPath);
        fwrite(STDERR, "Failed to add file to zip: {$file['relative']}\n");
        exit(1);
    }
}

if (!$zip->close()) {
    @unlink($zipPath);
    fwrite(STDERR, "Failed to finalize zip: {$zipPath}\n");
    exit(1);
}

$bytes = filesize($zipPath);
if ($bytes === false) {
    fwrite(STDERR, "Zip written but unreadable: {$zipPath}\n");
    exit(1);
}

$sha256 = hash_file('sha256', $zipPath);
if ($sha256 === false) {
    fwrite(STDERR, "Unable to hash zip: {$zipPath}\n");
    exit(1);
}

$shaBody = $sha256 . '  ' . $zipName . "\n";
if (file_put_contents($shaPath, $shaBody) === false) {
    fwrite(STDERR, "Unable to write sha256 file: {$shaPath}\n");
    exit(1);
}

$versionExample = [
    'version' => $version,
    'download_url' => 'https://agorapress.extrovertednerd.com/download/' . $zipName,
    'changelog_url' => 'https://agorapress.extrovertednerd.com/changelog',
    'sha256' => $sha256,
    'released' => gmdate('Y-m-d'),
    'notes' => 'Replace download_url / changelog_url with the published locations before serving as version.json.',
];
$versionJson = json_encode($versionExample, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if (
    $versionJson === false
    || file_put_contents($versionJsonPath, $versionJson . "\n") === false
) {
    fwrite(STDERR, "Unable to write version.json.example: {$versionJsonPath}\n");
    exit(1);
}

$result['sha256'] = $sha256;
$result['bytes'] = $bytes;

if ($opts['json']) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
} else {
    fwrite(STDOUT, "Packaged AgoraPress {$version}\n");
    fwrite(STDOUT, "  Files:  " . count($files) . "\n");
    fwrite(STDOUT, "  Zip:    {$zipPath} (" . formatBytes($bytes) . ")\n");
    fwrite(STDOUT, "  SHA256: {$sha256}\n");
    fwrite(STDOUT, "  Wrote:  {$shaPath}\n");
    fwrite(STDOUT, "  Wrote:  {$versionJsonPath}\n");
}

exit(0);

// -----------------------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------------------

/**
 * @param list<string> $argv
 * @return array{help:bool,dry-run:bool,json:bool,version:string,prefix:string,output-dir:string}
 */
function parseCliArgs(array $argv): array
{
    $out = [
        'help' => false,
        'dry-run' => false,
        'json' => false,
        'version' => '',
        'prefix' => '',
        'output-dir' => '',
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            $out['help'] = true;
            continue;
        }
        if ($arg === '--dry-run') {
            $out['dry-run'] = true;
            continue;
        }
        if ($arg === '--json') {
            $out['json'] = true;
            continue;
        }
        if (str_starts_with($arg, '--version=')) {
            $out['version'] = substr($arg, 10);
            continue;
        }
        if (str_starts_with($arg, '--prefix=')) {
            $out['prefix'] = substr($arg, 9);
            continue;
        }
        if (str_starts_with($arg, '--output-dir=')) {
            $out['output-dir'] = substr($arg, 13);
            continue;
        }
        fwrite(STDERR, "Unknown argument: {$arg}\n");
        printHelp();
        exit(1);
    }

    return $out;
}

function printHelp(): void
{
    $help = <<<'HELP'
Build a production release package for AgoraPress.

Usage:
  php bin/package-release.php [options]

Options:
  --output-dir=DIR   Destination directory (default: <repo>/dist)
  --version=VER      Override AP_VERSION label used in filenames
  --prefix=NAME      Top-level folder inside the zip (default: AgoraPress)
  --dry-run          List outcome without writing files
  --json             Machine-readable summary on stdout
  --help, -h         Show this help

Artifacts:
  AgoraPress-{version}.zip
  AgoraPress-{version}.sha256
  version.json.example

Excluded: tests, vendor, .git, .github, .hephaestus, CI/tooling configs,
caches, secrets (ap-config.php, .env), and runtime uploads content.

HELP;
    fwrite(STDOUT, $help);
}

function readApVersion(string $versionPhpPath): string
{
    if (!is_readable($versionPhpPath)) {
        return '';
    }
    $src = (string) file_get_contents($versionPhpPath);
    if (preg_match("/define\\s*\\(\\s*['\"]AP_VERSION['\"]\\s*,\\s*['\"]([^'\"]+)['\"]\\s*\\)/", $src, $m) === 1) {
        return trim((string) $m[1]);
    }

    return '';
}

function isSafeVersionLabel(string $version): bool
{
    // SemVer-ish: 0.1.0, 0.1.0-dev, 1.2.3-rc.1, 1.0.0+build — no path separators.
    return (bool) preg_match('/^[A-Za-z0-9][A-Za-z0-9._+-]{0,63}$/', $version)
        && !str_contains($version, '..')
        && !str_contains($version, '/')
        && !str_contains($version, '\\');
}

function isSafePrefix(string $prefix): bool
{
    return (bool) preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', $prefix)
        && !str_contains($prefix, '..')
        && !str_contains($prefix, '/');
}

function sanitizeFileVersion(string $version): string
{
    // Filesystem-safe; keep dots and hyphens (SemVer).
    return preg_replace('/[^A-Za-z0-9._+-]+/', '-', $version) ?? $version;
}

/**
 * Collect files/dirs to ship. Paths are relative to repo root using forward slashes.
 *
 * @return list<array{relative:string,absolute:string,is_dir:bool}>
 */
function collectPackageFiles(string $root): array
{
    $root = rtrim(str_replace('\\', '/', $root), '/');
    $out = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            $root,
            FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO
        ),
        RecursiveIteratorIterator::SELF_FIRST
    );

    /** @var SplFileInfo $info */
    foreach ($iterator as $info) {
        $absolute = str_replace('\\', '/', $info->getPathname());
        if (!str_starts_with($absolute, $root . '/')) {
            continue;
        }
        $relative = substr($absolute, strlen($root) + 1);
        if ($relative === false || $relative === '') {
            continue;
        }

        // Exclude by basename/prefix rules; children of excluded trees also match
        // (RecursiveDirectoryIterator still walks them — secondary guard below).
        if (shouldExcludeRelative($relative, $info->isDir()) || isUnderExcludedTree($relative)) {
            continue;
        }

        $out[] = [
            'relative' => $relative,
            'absolute' => $absolute,
            'is_dir' => $info->isDir(),
        ];
    }

    // Ensure empty shipping directories exist when the tree has no children after filters.
    foreach (['ap-content/plugins', 'ap-content/mu-plugins'] as $emptyDir) {
        $abs = $root . '/' . $emptyDir;
        if (is_dir($abs)) {
            $has = false;
            foreach ($out as $row) {
                if ($row['relative'] === $emptyDir || str_starts_with($row['relative'], $emptyDir . '/')) {
                    $has = true;
                    break;
                }
            }
            if (!$has) {
                $out[] = [
                    'relative' => $emptyDir,
                    'absolute' => $abs,
                    'is_dir' => true,
                ];
            }
        }
    }

    usort(
        $out,
        static fn(array $a, array $b): int => strcmp($a['relative'], $b['relative'])
    );

    return $out;
}

/**
 * Whether a relative path should be omitted from the release package.
 */
function shouldExcludeRelative(string $relative, bool $isDir): bool
{
    $rel = str_replace('\\', '/', $relative);
    $rel = ltrim($rel, '/');

    if ($rel === '' || str_contains($rel, "\0")) {
        return true;
    }

    // Path traversal / oddities.
    if ($rel === '..' || str_starts_with($rel, '../') || str_contains($rel, '/../')) {
        return true;
    }

    $parts = explode('/', $rel);
    $base = $parts[count($parts) - 1];

    // Exact top-level / any-depth directory basenames that are never shipped.
    $excludedBasenames = [
        '.git' => true,
        '.github' => true,
        '.hephaestus' => true,
        '.idea' => true,
        '.vscode' => true,
        'vendor' => true,
        'node_modules' => true,
        'dist' => true,
        'tests' => true,
        'bin' => true, // packaging tooling stays in the repo, not the release zip
        '__pycache__' => true,
        '.pytest_cache' => true,
        '.phpunit.cache' => true,
        '.phpcs.cache' => true,
        '.phpstan.cache' => true,
        '.mypy_cache' => true,
        '.ruff_cache' => true,
        '.tox' => true,
        'coverage' => true,
        'htmlcov' => true,
        'cache' => true,
        'tmp' => true,
        'temp' => true,
    ];

    foreach ($parts as $part) {
        if (isset($excludedBasenames[$part])) {
            return true;
        }
        // Hidden OS/editor junk dirs.
        if ($part === '.DS_Store') {
            return true;
        }
    }

    // Top-level development-only files (not needed to run the CMS).
    $topLevelDevFiles = [
        'composer.lock' => true,
        'phpunit.xml.dist' => true,
        'phpcs.xml.dist' => true,
        'phpstan.neon.dist' => true,
        'CODING_STANDARDS.md' => true,
        '.gitignore' => true,
        '.dockerignore' => true,
        '.gitattributes' => true,
        '.editorconfig' => true,
        '.phpunit.result.cache' => true,
        '.phpcs.cache' => true,
        '.phpstan.cache' => true,
    ];
    if (count($parts) === 1 && isset($topLevelDevFiles[$base])) {
        return true;
    }

    // Secrets / generated config — never ship.
    if ($base === 'ap-config.php') {
        return true;
    }
    if ($base === '.env' || str_starts_with($base, '.env.')) {
        return true;
    }
    if (str_ends_with($base, '.local')) {
        return true;
    }

    // Runtime SQLite / DB files (demo install leftovers must never ship).
    if (preg_match('/\.(sqlite3?|db)$/i', $base) === 1) {
        return true;
    }

    // Caches and logs.
    if (str_ends_with($base, '.cache') || str_ends_with($base, '.log')) {
        return true;
    }
    if (preg_match('/\.(swp|swo|bak|tmp|temp|orig|rej)$/', $base) === 1) {
        return true;
    }
    if ($base === 'Thumbs.db' || $base === '.DS_Store') {
        return true;
    }
    if (str_ends_with($base, '.pyc') || str_ends_with($base, '.pyo')) {
        return true;
    }

    // Runtime uploads: ship only a silent index.php placeholder when present.
    if ($rel === 'ap-content/uploads' || str_starts_with($rel, 'ap-content/uploads/')) {
        if ($rel === 'ap-content/uploads') {
            return false; // include the directory itself
        }
        if ($rel === 'ap-content/uploads/index.php') {
            return false;
        }

        return true; // drop real user media if present on the build machine
    }

    // docker-compose.override.yml is local-only.
    if ($base === 'docker-compose.override.yml') {
        return true;
    }

    // Unused $isDir kept for call-site clarity / future prune hooks.
    unset($isDir);

    return false;
}

/**
 * Secondary guard for trees that RecursiveDirectoryIterator still walks.
 */
function isUnderExcludedTree(string $relative): bool
{
    $rel = str_replace('\\', '/', $relative);
    $prefixes = [
        '.git/',
        '.github/',
        '.hephaestus/',
        'vendor/',
        'node_modules/',
        'dist/',
        'tests/',
        'bin/',
        '__pycache__/',
        '.pytest_cache/',
        '.phpunit.cache/',
        '.phpcs.cache/',
        '.phpstan.cache/',
        'coverage/',
        'htmlcov/',
    ];
    foreach ($prefixes as $p) {
        if ($rel === rtrim($p, '/') || str_starts_with($rel, $p)) {
            return true;
        }
    }

    // uploads: only index.php allowed under ap-content/uploads/
    if (str_starts_with($rel, 'ap-content/uploads/')) {
        return $rel !== 'ap-content/uploads/index.php';
    }

    return false;
}

function formatBytes(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    if ($bytes < 1048576) {
        return round($bytes / 1024, 1) . ' KiB';
    }

    return round($bytes / 1048576, 2) . ' MiB';
}
