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

    // Admin (list tables + edit screens for posts/pages)
    'ap-admin',
    'ap-admin/index.php',
    'ap-admin/edit.php',
    'ap-admin/post.php',
    'ap-admin/post-new.php',
    'ap-admin/revision.php',
    'ap-admin/login.php',
    'ap-admin/admin-bootstrap.php',
    'ap-admin/includes/class-ap-admin.php',
    'ap-admin/includes/class-ap-posts-list-table.php',
    'ap-admin/includes/class-ap-admin-post-edit.php',
    'ap-admin/includes/class-ap-media-list-table.php',
    'ap-admin/includes/class-ap-admin-media.php',
    'ap-admin/upload.php',
    'ap-admin/media.php',
    'ap-admin/media-new.php',
    'ap-admin/css/admin.css',
    'ap-admin/theme-options.php',
    'ap-admin/nav-menus.php',
    'ap-admin/widgets.php',
    'ap-admin/options-general.php',
    'ap-admin/options-modules.php',
    'ap-admin/options-writing.php',
    'ap-admin/options-reading.php',
    'ap-admin/options-discussion.php',
    'ap-admin/options-media.php',
    'ap-admin/options-permalink.php',
    'ap-admin/options-hall-of-fame.php',
    'ap-includes/class-ap-hall-of-fame.php',
    'ap-includes/class-ap-version-check.php',
    'ap-includes/class-ap-core-updater.php',
    'ap-admin/update-core.php',
    'ap-includes/class-ap-nonce.php',
    'ap-includes/class-ap-media.php',
    'ap-includes/class-ap-options.php',
    'ap-includes/class-ap-settings.php',
    'ap-includes/class-ap-transient.php',
    'ap-includes/class-ap-shortcode.php',
    'ap-includes/class-ap-cron.php',
    'ap-includes/class-ap-nav-menu.php',
    'ap-includes/class-ap-widgets.php',
    'ap-includes/class-ap-feed.php',
    'ap-includes/template-tags.php',
    // Default theme (Agora)
    'ap-content/themes/agora',
    'ap-content/themes/agora/style.css',
    'ap-content/themes/agora/functions.php',
    'ap-content/themes/agora/index.php',
    'ap-content/themes/agora/front-page.php',
    'ap-content/themes/agora/sidebar.php',
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
    'ap-includes/class-ap-rewrite.php',
    'ap-includes/class-ap-user.php',
    'ap-includes/class-ap-avatar.php',
    'ap-includes/class-ap-session.php',
    'ap-includes/class-ap-mail.php',
    'ap-includes/class-ap-registration.php',
    'ap-includes/class-ap-post.php',
    'ap-includes/class-ap-taxonomy.php',
    'ap-admin/edit-tags.php',
    'ap-admin/includes/class-ap-admin-terms.php',
    'ap-includes/class-ap-comment.php',
    'ap-admin/edit-comments.php',
    'ap-admin/includes/class-ap-comments-list-table.php',
    'ap-admin/users.php',
    'ap-admin/user-new.php',
    'ap-admin/user-edit.php',
    'ap-admin/profile.php',
    'ap-admin/includes/class-ap-users-list-table.php',
    'ap-admin/includes/class-ap-admin-user-edit.php',
    'ap-includes/class-ap-roles.php',
    'ap-includes/class-ap-theme.php',
    'ap-includes/class-ap-theme-installer.php',
    'ap-includes/class-ap-assets.php',
    'ap-admin/themes.php',
    'ap-includes/class-ap-plugin.php',
    'ap-includes/class-ap-hook.php',
    'ap-includes/class-ap-hooks.php',
    'ap-includes/class-ap-forum.php',
    'ap-includes/class-ap-forum-moderation.php',
    'ap-includes/class-ap-group.php',
    'ap-admin/forums.php',
    'ap-admin/forum-edit.php',
    'ap-admin/forum-topics.php',
    'ap-admin/forum-moderation.php',
    'ap-admin/forum-groups.php',
    'ap-admin/options-forums.php',
    'ap-admin/includes/class-ap-forums-list-table.php',
    'ap-admin/includes/class-ap-admin-forum-edit.php',
    'ap-admin/includes/class-ap-forum-topics-list-table.php',
    'ap-admin/includes/class-ap-forum-moderation-queue.php',
    'ap-admin/includes/class-ap-admin-forum-groups.php',
    'ap-includes/class-ap-content-format.php',
    'ap-includes/compatibility',
    'ap-includes/compatibility/load.php',
    'ap-includes/compatibility/class-ap-theme-compat.php',
    'ap-includes/compatibility/class-ap-theme-converter.php',
    'ap-includes/compatibility/functions-shim.php',
    'ap-includes/compatibility/template-tags.php',
    'ap-includes/compatibility/cli-convert.php',
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
