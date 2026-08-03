<?php

/**
 * Assert repository layout matches SPEC.md §2 File & Directory Structure.
 *
 * Runnable without Composer/PHPUnit:
 *   php tests/Structure/assert-structure.php
 *
 * Exit code 0 = pass, 1 = fail.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);

/** @var list<string> $requiredPaths Paths relative to repo root that must exist. */
$requiredPaths = [
    // Root entry / config / tooling
    'index.php',
    'ap-config-sample.php',
    '.htaccess',
    'composer.json',
    'docker-compose.yml',
    'README.md',
    'LICENSE',
    // Nginx example (SPEC: ".htaccess / nginx examples")
    'docker/nginx.conf.example',
    // Docker Compose stack (SPEC §2 / Phase 0)
    'docker/Dockerfile',
    'docker/apache-vhost.conf',
    'docker/php-agorapress.ini',

    // Admin
    'ap-admin',
    // Web installer (requirements → DB → site/admin → tables + config)
    'install/index.php',
    // CLI installer (non-interactive; shares AP_Installer with web path)
    'install/cli.php',
    'ap-includes/class-ap-requirements.php',
    'ap-includes/class-ap-installer.php',
    'ap-includes/class-ap-cli-install.php',
    // Core includes
    'ap-includes/class-ap-db.php',
    'ap-includes/class-ap-migrator.php',
    'ap-includes/class-ap-migration.php',
    'ap-includes/schema/migrations',
    'ap-includes/class-ap-query.php',
    'ap-includes/class-ap-user.php',
    'ap-includes/class-ap-session.php',
    'ap-includes/class-ap-roles.php',
    'ap-includes/class-ap-theme.php',
    'ap-includes/class-ap-plugin.php',
    'ap-includes/class-ap-forum.php',
    'ap-includes/compatibility',
    'ap-includes/functions.php',
    'ap-includes/hooks.php',
    // Content
    'ap-content/themes',
    'ap-content/plugins',
    'ap-content/mu-plugins',
    'ap-content/languages',
    // Tests
    'tests',
];

/**
 * Paths that must NOT be committed (present in .gitignore).
 * We only assert gitignore rules, not that the files are absent on disk.
 *
 * @var list<string> $mustBeGitignored
 */
$mustBeGitignored = [
    'ap-config.php',
    'ap-content/uploads/',
    '.hephaestus/',
];

/**
 * Exact .gitignore rules required so Hephaestus process state is never tracked.
 *
 * @var list<string> $hephaestusGitignoreRules
 */
$hephaestusGitignoreRules = [
    '.hephaestus/',
    '**/.hephaestus/',
];

$failures = [];

foreach ($requiredPaths as $rel) {
    $abs = $root . '/' . $rel;
    if (!file_exists($abs)) {
        $failures[] = "Missing required path: {$rel}";
    }
}

// uploads/ is runtime data (SPEC). Directory may exist locally; must be gitignored.
$gitignore = $root . '/.gitignore';
if (!is_readable($gitignore)) {
    $failures[] = 'Missing .gitignore';
} else {
    $gi = (string) file_get_contents($gitignore);
    foreach ($mustBeGitignored as $pattern) {
        $needle = rtrim($pattern, '/');
        if (
            strpos($gi, $pattern) === false
            && strpos($gi, $needle) === false
            && strpos($gi, '/' . ltrim($needle, '/')) === false
        ) {
            $failures[] = "Expected .gitignore to cover: {$pattern}";
        }
    }

    // Constitution: never track .hephaestus/ (root or nested).
    $giLines = [];
    foreach (preg_split("/\R/", $gi) ?: [] as $rawLine) {
        $line = trim((string) $rawLine);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $giLines[$line] = true;
    }
    foreach ($hephaestusGitignoreRules as $rule) {
        if (!isset($giLines[$rule])) {
            $failures[] = "Expected .gitignore exact rule: {$rule}";
        }
    }

    // If git is available in a work tree, prove nothing under .hephaestus is tracked
    // and that check-ignore matches process paths.
    $gitDir = $root . '/.git';
    if (is_dir($gitDir) || is_file($gitDir)) {
        $trackedCmd = 'git -C ' . escapeshellarg($root)
            . ' ls-files -- .hephaestus 2>/dev/null';
        $trackedOut = [];
        $trackedExit = 0;
        exec($trackedCmd, $trackedOut, $trackedExit);
        if ($trackedExit === 0 && $trackedOut !== []) {
            $failures[] = '.hephaestus/ paths must not be tracked by git; found: '
                . implode(', ', $trackedOut);
        }

        // -q accepts only a single path; check each process-state path.
        foreach (['.hephaestus/', '.hephaestus/TODO.md', '.hephaestus/Workflow.md'] as $path) {
            $checkCmd = 'git -C ' . escapeshellarg($root)
                . ' check-ignore -q ' . escapeshellarg($path) . ' 2>/dev/null';
            $checkOut = [];
            $checkExit = 0;
            exec($checkCmd, $checkOut, $checkExit);
            // 0 = ignored; 1 = not ignored; 128 = not a repo / git error (skip soft).
            if ($checkExit === 1) {
                $failures[] = "git check-ignore must match process path: {$path}";
            }
        }
    }
}

// ap-config.php must never be a committed sample; only ap-config-sample.php ships.
if (is_file($root . '/ap-config.php')) {
    // Allowed locally for development; ensure sample exists (already checked).
    // No failure — presence is expected after install.
}

// LICENSE should mention GPL (GPLv2-or-later per SPEC).
$license = $root . '/LICENSE';
if (is_readable($license)) {
    $licenseBody = (string) file_get_contents($license);
    if (stripos($licenseBody, 'GNU GENERAL PUBLIC LICENSE') === false) {
        $failures[] = 'LICENSE does not appear to be GPL text';
    }
    if (strpos($licenseBody, 'Version 2') === false && stripos($licenseBody, 'GPL-2.0') === false) {
        $failures[] = 'LICENSE should be GPLv2 (or later)';
    }
    // Application notice must allow any later GPL version (not GPLv2-only).
    if (
        stripos($licenseBody, 'any later version') === false
        && stripos($licenseBody, 'GPL-2.0-or-later') === false
    ) {
        $failures[] = 'LICENSE should allow GPLv2 or later (not GPLv2-only)';
    }
}

if ($failures !== []) {
    fwrite(STDERR, "Structure check FAILED:\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

echo "Structure check PASSED (" . count($requiredPaths) . " required paths present).\n";
exit(0);
