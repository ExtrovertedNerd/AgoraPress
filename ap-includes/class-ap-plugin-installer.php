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
 * {@see AP_Plugin_Installer::install()} does that move when the slug is not
 * already present. An existing slug is left unchanged unless `overwrite` is
 * true. Overwrite moves the previous folder or file into a timestamped backup
 * under `{content}/.ap-plugin-backups/{slug}/{stamp}/`, then moves the staged
 * payload into its place. `rename` is used when the source and destination
 * are on the same filesystem; otherwise the tree is copied and the source is
 * removed. A failed swap, or a failed check after the swap, moves that backup
 * back into place. The last N backups of that slug are kept (default
 * {@see self::DEFAULT_MAX_BACKUPS}, filter `ap_plugin_zip_max_backups`).
 * A plugin that was already active stays active. Its upgrade routine runs
 * when one is registered with {@see AP_Plugin::registerUpgradeHook()}.
 * A plugin that was not active stays inactive unless `activate` is boolean
 * true. True activates it after the files are in place. If that activation
 * fails, the plugin stays installed and inactive and install() returns
 * {@see AP_Plugin_Install_Error::ACTIVATION_FAILED}.
 *
 * {@see AP_Plugin_Installer::restorePrevious()} puts the newest timestamped
 * backup of one slug back into the plugins directory. When that slug is
 * already installed, those files are moved into a new timestamped backup
 * first and are put back if the restore fails. The last N backups stay
 * capped by `ap_plugin_zip_max_backups`. A plugin that was active stays
 * active. Restore does not activate an inactive plugin and does not run
 * activation hooks.
 * {@see AP_Plugin_Installer::listInstalled()} lists installed plugins as
 * slug, name, version, and whether that plugin is active.
 *
 * Install, upgrade, and restore each fire a before action and an after
 * action. Before runs once the package is accepted and before any plugin
 * file moves. After runs only when that operation sticks. A failed upgrade
 * or restore that puts the previous files back does not fire after. An
 * activation failure still fires the install or upgrade after action with
 * `activated` false. Optional activation runs before that after action, so
 * `activated` matches the result returned to the caller. The argument is
 * one context array: slug, name, version,
 * previous_version, plugin, action (`installed`, `upgraded`, or `restored`),
 * backup_path, and activated. Names: `ap_plugin_before_install`,
 * `ap_plugin_after_install`, `ap_plugin_before_upgrade`,
 * `ap_plugin_after_upgrade`, `ap_plugin_before_restore`,
 * `ap_plugin_after_restore`. {@see AP_Plugin::activate()} fires
 * `ap_plugin_before_activate` and `ap_plugin_after_activate`.
 * {@see AP_Plugin::deactivate()} fires `ap_plugin_before_deactivate` and
 * `ap_plugin_after_deactivate`. That argument is the plugin basename.
 * Exceptions from these listeners are ignored, so a logger cannot cancel
 * the operation. Zip size, entry count, uncompressed size, and how many
 * backups are kept stay filterable (`ap_plugin_zip_max_bytes`,
 * `ap_plugin_zip_max_entries`, `ap_plugin_zip_max_uncompressed_bytes`,
 * `ap_plugin_zip_max_backups`).
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

    /**
     * Default number of timestamped backups kept for one plugin slug.
     *
     * Filter `ap_plugin_zip_max_backups` may replace this with a positive
     * integer. The backup just written counts toward the cap.
     */
    public const DEFAULT_MAX_BACKUPS = 5;

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
     * Hidden content-directory parent for timestamped plugin backups.
     *
     * Each upgrade writes `{slug}/{YmdHis}{sequence}-{hex}/` inside this directory.
 * The sequence keeps two backups taken in the same second in creation order.
     * A folder plugin is stored as `{stamp}/{slug}/`. A flat plugin is stored
     * as `{stamp}/{slug}.php`. The install result's backup path is `{stamp}`.
     */
    private const BACKUP_PARENT_NAME = '.ap-plugin-backups';

    /**
     * Stamp directory names created by {@see self::createBackupStamp()}.
     *
     * 14-digit UTC time, a 6-digit per-process sequence, then 8 hex characters.
     */
    private const BACKUP_STAMP_PATTERN = '/\A[0-9]{20}-[a-f0-9]{8}\z/';

    private const BACKUP_HTACCESS = <<<'HTACCESS'
# Private plugin backups. Do not serve these files.
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    Order deny,allow
    Deny from all
</IfModule>
HTACCESS;

    /**
     * Test-only upgrade failure point. Production leaves this null.
     *
     * `after_backup` fails once the previous plugin is in the backup directory.
     * `after_swap` fails after the new payload is in place and has passed the
     * same checks as a new install. Either point restores the previous plugin.
     */
    private static ?string $upgradeFailurePoint = null;

    /**
     * Test-only restore failure point. Production leaves this null.
     *
     * `after_backup` fails once the installed plugin is in a new backup
     * directory and before the previous version is moved into place.
     * `after_swap` fails after that previous version is in place. Either
     * point puts the installed plugin back and leaves the backup that was
     * being restored where it was.
     */
    private static ?string $restoreFailurePoint = null;

    /**
     * Increments for every stamp so same-second backups stay in creation order.
     */
    private static int $backupSequence = 0;


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
        $staged = self::stageValidatedPackage($zipPath, $opts);
        if ($staged instanceof AP_Plugin_Install_Error) {
            return $staged;
        }

        if (!self::discardStagingDir($staged['stage'])) {
            self::discardStagingDir($staged['stage']);

            return AP_Plugin_Install_Error::make(
                AP_Plugin_Install_Error::STAGING_FAILED,
                'Could not remove the private plugin staging directory.'
            );
        }

        return self::resultFromStagedPackage($staged, null, false);
    }

    /**
     * Install a plugin zip, or upgrade an existing slug when overwrite is requested.
     *
     * The zip is checked with the same rules as {@see self::validate()}. A
     * package that passes is extracted into a private staging directory under
     * the content directory, then that payload is moved into the plugins
     * directory. The staging directory is removed before this method returns.
     * Nothing is extracted directly into the plugins directory.
     *
     * When the slug is already installed, those files stay in place and the
     * result is {@see AP_Plugin_Install_Error::ALREADY_INSTALLED} unless
     * `overwrite` is boolean true. True moves the previous folder or file into
     * `{content}/.ap-plugin-backups/{slug}/{YmdHis}{sequence}-{hex}/` (a folder plugin is
     * `{stamp}/{slug}/`, a flat plugin is `{stamp}/{file}.php`), then moves
     * the staged payload into the plugins directory. The move is `rename`
     * when that works, and a copy followed by removing the source otherwise.
     * If the new payload cannot be placed, or the placed files fail the same
     * checks as a new install, the backup is moved back and the new files are
     * removed. The result backup path is that stamp directory.
     *
     * After a successful upgrade, stamp directories older than the newest
     * {@see self::maxBackups()} entries for that slug are deleted. The filter
     * is `ap_plugin_zip_max_backups`.
     *
     * `activate` must be boolean true. Any other value does not activate.
     * A plugin that was not already active stays off the active list, and
     * `activated()` is false. True activates the plugin after the files are
     * in place: the main file is loaded, activation hooks run, and the
     * basename is stored in the active list. `activated()` is then true.
     * A plugin that was already active stays active either way. When
     * `activate` is true for that plugin, `activated()` is true and activation
     * hooks are not run again. If activation fails, the new files stay, the
     * plugin is left inactive, and the return value is
     * {@see AP_Plugin_Install_Error::ACTIVATION_FAILED}. The message names the
     * plugin and says it remains inactive. The previous version is not
     * restored. When an already-active plugin registered an upgrade routine
     * with {@see AP_Plugin::registerUpgradeHook()}, the routine runs after
     * the new files are in place. If this request already included the
     * plugin, the routine runs on the next {@see AP_Plugin::loadActivePlugins()}
     * so the replacement file is the one that registers it. A failed attempt
     * to keep the plugin active restores the backup. A new slug is action
     * `installed` with no previous version and no backup. An overwrite is
     * action `upgraded`.
     *
     * A new slug fires `ap_plugin_before_install` before the files move and
     * `ap_plugin_after_install` when this method finishes with the files in
     * place. An overwrite fires `ap_plugin_before_upgrade` and
     * `ap_plugin_after_upgrade`. After does not run when the previous plugin
     * is put back. The argument is the context array described on this class.
     * Optional activation runs before that after action. When activation
     * fails, after still runs with activated false.
     *
     * @param array{
     *   overwrite?: bool,
     *   activate?: bool,
     *   expected_sha256?: string,
     *   plugins_root?: string,
     *   content_dir?: string
     * } $opts
     */
    public static function install(string $zipPath, array $opts = []): AP_Plugin_Install_Result|AP_Plugin_Install_Error
    {
        try {
            return self::installPackage($zipPath, $opts);
        } finally {
            self::$upgradeFailurePoint = null;
        }
    }

    /**
     * Put the newest backup of one plugin slug back into the plugins directory.
     *
     * Backups live under `{content}/.ap-plugin-backups/{slug}/{stamp}/`. The
     * newest stamp name is the one that sorts last. A folder plugin is stored
     * as `{stamp}/{slug}/`. A flat plugin is stored as `{stamp}/{slug}.php`.
     * Older stamps are left in place. A stamp that is not a single safe plugin
     * payload is not restored and is not replaced by an older stamp.
     *
     * When the slug is already installed, those files move into a new
     * timestamped backup first. The previous version then moves into the
     * plugins directory. `rename` is used when that works, and a copy followed
     * by removing the source otherwise. If that swap fails, or a later check
     * fails, the installed files are put back and the backup being restored
     * stays where it was. After a successful restore the consumed stamp is
     * removed. Stamp directories older than the newest
     * {@see self::maxBackups()} entries for that slug are deleted. The filter
     * is `ap_plugin_zip_max_backups`.
     *
     * When the slug is not installed, the backup moves into place and no new
     * backup is written. `previousVersion()` is null and `backupPath()` is
     * null in that result. Otherwise `previousVersion()` is the version that
     * was displaced, when that version could be read, and `backupPath()` is
     * the new stamp that holds those files.
     *
     * A plugin that was active stays active, including when the restored main
     * file basename differs. Activation and upgrade hooks are not run. An
     * inactive plugin stays inactive. `activated()` is true only when the
     * restored plugin is on the live active list afterward. Passing a
     * `plugins_root` other than that live directory does not change the
     * active list. `ap_plugin_before_restore` fires before files move.
     * `ap_plugin_after_restore` fires only after a restore that sticks.
     * The argument is the same context array as {@see self::install()}.
     *
     * `plugins_root` and `content_dir` match {@see self::install()}. The slug
     * is trimmed. It must be a lowercase plugin slug.
     *
     * @param array{
     *   plugins_root?: string,
     *   content_dir?: string
     * } $opts
     */
    public static function restorePrevious(
        string $slug,
        array $opts = []
    ): AP_Plugin_Install_Result|AP_Plugin_Install_Error {
        try {
            return self::restorePreviousPackage($slug, $opts);
        } finally {
            self::$restoreFailurePoint = null;
        }
    }

    /**
     * Installed plugins as slug, name, version, and active flag.
     *
     * One row is a folder whose name is a lowercase slug and whose main file
     * has Plugin Name and Version, or a flat `{slug}.php` with those headers.
     * The main file is `{slug}/{slug}.php` when that file has a plugin header.
     * A folder with several header files and no `{slug}.php` is omitted.
     * Symlinks are not followed. Dot entries are skipped.
     *
     * Rows are ordered by slug, then by plugin basename. A folder and a flat
     * file can share a slug; both are listed. `active` is true when that
     * plugin is on the live active list for this plugins directory. Any other
     * directory reports `active` false and does not read the active list.
     *
     * @param array{plugins_root?: string} $opts
     *
     * @return list<array{slug: string, name: string, version: string, active: bool}>
     */
    public static function listInstalled(array $opts = []): array
    {
        $pluginsRoot = self::resolvePluginsRoot(
            isset($opts['plugins_root']) && is_string($opts['plugins_root'])
                ? $opts['plugins_root']
                : null
        );
        if ($pluginsRoot === '' || is_link($pluginsRoot) || !is_dir($pluginsRoot)) {
            return [];
        }

        $entries = @scandir($pluginsRoot);
        if (!is_array($entries)) {
            return [];
        }

        $rows = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
                continue;
            }
            $full = $pluginsRoot . '/' . $entry;
            if (is_link($full)) {
                continue;
            }
            if (is_dir($full)) {
                $row = self::installedFolderRow($pluginsRoot, $entry);
            } elseif (is_file($full)) {
                $row = self::installedFlatRow($pluginsRoot, $entry);
            } else {
                continue;
            }
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        usort(
            $rows,
            static function (array $left, array $right): int {
                $bySlug = strcmp($left['slug'], $right['slug']);
                if ($bySlug !== 0) {
                    return $bySlug;
                }

                return strcmp($left['plugin'], $right['plugin']);
            }
        );

        $listed = [];
        foreach ($rows as $row) {
            $listed[] = [
                'slug' => $row['slug'],
                'name' => $row['name'],
                'version' => $row['version'],
                'active' => $row['active'],
            ];
        }

        return $listed;
    }

    /**
     * @param array{
     *   overwrite?: bool,
     *   activate?: bool,
     *   expected_sha256?: string,
     *   plugins_root?: string,
     *   content_dir?: string
     * } $opts
     */
    private static function installPackage(
        string $zipPath,
        array $opts
    ): AP_Plugin_Install_Result|AP_Plugin_Install_Error {
        $staged = self::stageValidatedPackage($zipPath, $opts);
        if ($staged instanceof AP_Plugin_Install_Error) {
            return $staged;
        }

        $error = null;
        $backupPath = null;
        $placed = false;
        $upgraded = false;
        $activeUpgrade = null;
        if ($staged['exists']) {
            if (!self::overwriteRequested($opts)) {
                $error = self::alreadyInstalledError($staged['slug']);
            } else {
                self::firePackageHook(
                    'ap_plugin_before_upgrade',
                    self::lifecycleContext(
                        $staged,
                        AP_Plugin_Install_Result::ACTION_UPGRADED,
                        null,
                        false
                    )
                );
                $activeUpgrade = self::snapshotActiveUpgrade($staged);
                $replaced = self::replaceExistingSlug($staged);
                if ($replaced instanceof AP_Plugin_Install_Error) {
                    $error = $replaced;
                } else {
                    $backupPath = $replaced;
                    $placed = true;
                    $upgraded = true;
                }
            }
        } else {
            self::firePackageHook(
                'ap_plugin_before_install',
                self::lifecycleContext(
                    $staged,
                    AP_Plugin_Install_Result::ACTION_INSTALLED,
                    null,
                    false
                )
            );
            $error = self::placeNewSlug($staged);
            $placed = $error === null;
        }

        if (!self::discardStagingDir($staged['stage'])) {
            self::discardStagingDir($staged['stage']);
            if ($upgraded && is_string($backupPath)) {
                $restored = self::rollBackPlacedUpgrade($staged, $backupPath);
                if (!$restored) {
                    return AP_Plugin_Install_Error::make(
                        AP_Plugin_Install_Error::INSTALL_FAILED,
                        'Could not remove the private plugin staging directory, and the previous plugin '
                        . 'could not be restored. Backup: ' . $backupPath
                    );
                }
            } elseif ($placed) {
                self::removePlacedPayload($staged['is_folder'], self::pluginDestination($staged));
            }

            return AP_Plugin_Install_Error::make(
                AP_Plugin_Install_Error::STAGING_FAILED,
                'Could not remove the private plugin staging directory.'
            );
        }

        if ($error !== null) {
            return $error;
        }

        if ($upgraded && is_string($backupPath)) {
            if (is_array($activeUpgrade) && $activeUpgrade['basenames'] !== []) {
                $activeError = self::finishActiveUpgrade($staged, $activeUpgrade);
                if ($activeError !== null) {
                    $restored = self::rollBackPlacedUpgrade($staged, $backupPath);
                    if (!$restored) {
                        return AP_Plugin_Install_Error::make(
                            AP_Plugin_Install_Error::INSTALL_FAILED,
                            'Could not keep the active plugin active, and the previous plugin could not be restored. '
                            . 'Backup: ' . $backupPath
                        );
                    }

                    return $activeError;
                }
            }
            self::pruneSlugBackups(dirname($backupPath), self::maxBackups($staged['slug']));
        }

        $activated = false;
        if (self::activateRequested($opts)) {
            $activationError = self::activateInstalledPlugin($staged);
            if ($activationError !== null) {
                self::fireSettledPackage($staged, $backupPath, false);

                return $activationError;
            }
            $activated = true;
        }

        $result = self::resultFromStagedPackage($staged, $backupPath, $activated);
        self::fireSettledResult($result);

        return $result;
    }

    /**
     * Context for install, upgrade, and restore actions.
     *
     * `activated` matches {@see AP_Plugin_Install_Result::activated()} and is
     * false on the before action. `backup_path` is null until a backup exists.
     *
     * @param array{
     *   slug: string,
     *   name: string,
     *   version: string,
     *   previous_version: string|null,
     *   plugin: string
     * } $package
     *
     * @return array{
     *   slug: string,
     *   name: string,
     *   version: string,
     *   previous_version: string|null,
     *   plugin: string,
     *   action: string,
     *   backup_path: string|null,
     *   activated: bool
     * }
     */
    private static function lifecycleContext(
        array $package,
        string $action,
        ?string $backupPath,
        bool $activated
    ): array {
        $previous = $package['previous_version'] ?? null;
        if (!is_string($previous)) {
            $previous = null;
        } else {
            $previous = trim($previous);
            if ($previous === '') {
                $previous = null;
            }
        }
        $backup = $backupPath !== null ? trim($backupPath) : '';

        return [
            'slug' => $package['slug'],
            'name' => $package['name'],
            'version' => $package['version'],
            'previous_version' => $previous,
            'plugin' => $package['plugin'],
            'action' => $action,
            'backup_path' => $backup !== '' ? $backup : null,
            'activated' => $activated,
        ];
    }

    /**
     * After action for a package that is on disk, including a failed activation.
     *
     * @param array{
     *   slug: string,
     *   name: string,
     *   version: string,
     *   previous_version: string|null,
     *   plugin: string,
     *   exists: bool
     * } $staged
     */
    private static function fireSettledPackage(array $staged, ?string $backupPath, bool $activated): void
    {
        $upgraded = $staged['exists'] === true;
        self::firePackageHook(
            $upgraded ? 'ap_plugin_after_upgrade' : 'ap_plugin_after_install',
            self::lifecycleContext(
                $staged,
                $upgraded
                    ? AP_Plugin_Install_Result::ACTION_UPGRADED
                    : AP_Plugin_Install_Result::ACTION_INSTALLED,
                $backupPath,
                $activated
            )
        );
    }

    /**
     * After action whose context matches the result returned to the caller.
     */
    private static function fireSettledResult(AP_Plugin_Install_Result $result): void
    {
        $action = $result->action();
        if ($action === AP_Plugin_Install_Result::ACTION_UPGRADED) {
            $hook = 'ap_plugin_after_upgrade';
        } elseif ($action === AP_Plugin_Install_Result::ACTION_RESTORED) {
            $hook = 'ap_plugin_after_restore';
        } else {
            $hook = 'ap_plugin_after_install';
        }
        self::firePackageHook($hook, self::lifecycleContext(
            [
                'slug' => $result->slug(),
                'name' => $result->name(),
                'version' => $result->version(),
                'previous_version' => $result->previousVersion(),
                'plugin' => $result->plugin(),
            ],
            $action,
            $result->backupPath(),
            $result->activated()
        ));
    }

    /**
     * Fire one installer lifecycle action.
     *
     * Names are literals so the hook inventory can see them. Any other name
     * is ignored. Listener exceptions are ignored so logging cannot cancel
     * install, upgrade, or restore, and cannot skip staging cleanup.
     *
     * @param array<string, mixed> $context
     */
    private static function firePackageHook(string $hook, array $context): void
    {
        if (!function_exists('ap_do_action')) {
            return;
        }

        try {
            switch ($hook) {
                case 'ap_plugin_before_install':
                    ap_do_action('ap_plugin_before_install', $context);
                    break;
                case 'ap_plugin_after_install':
                    ap_do_action('ap_plugin_after_install', $context);
                    break;
                case 'ap_plugin_before_upgrade':
                    ap_do_action('ap_plugin_before_upgrade', $context);
                    break;
                case 'ap_plugin_after_upgrade':
                    ap_do_action('ap_plugin_after_upgrade', $context);
                    break;
                case 'ap_plugin_before_restore':
                    ap_do_action('ap_plugin_before_restore', $context);
                    break;
                case 'ap_plugin_after_restore':
                    ap_do_action('ap_plugin_after_restore', $context);
                    break;
                default:
                    break;
            }
        } catch (Throwable) {
            // Observers may log. They do not cancel the installer operation.
        }
    }

    /**
     * @param array<string, mixed> $opts
     */
    private static function activateRequested(array $opts): bool
    {
        return ($opts['activate'] ?? false) === true;
    }

    /**
     * Activate a plugin that is already in the plugins directory.
     *
     * A plugin that is already active is left active and is not activated
     * again. A failed activation does not remove the installed files and does
     * not restore a backup. The plugin is removed from the active list when
     * a failed attempt left it there.
     *
     * @param array{
     *   name: string,
     *   version: string,
     *   plugin: string,
     *   exists: bool,
     *   plugins_root: string
     * } $staged
     */
    private static function activateInstalledPlugin(array $staged): ?AP_Plugin_Install_Error
    {
        $plugin = $staged['plugin'];
        $verb = $staged['exists'] === true ? 'upgraded' : 'installed';
        $label = 'Plugin “' . $staged['name'] . '” ' . $staged['version'] . ' (' . $plugin . ')';

        if (!class_exists('AP_Plugin', false) || !method_exists(AP_Plugin::class, 'activate')) {
            return self::activationFailed($label, $verb, 'Plugin activation is not available.');
        }

        if (!self::installerUsesLivePlugins($staged['plugins_root'])) {
            $reason = 'The install directory is not the active plugins directory, '
                . 'so the active plugin list was left unchanged.';
            if (AP_Plugin::isActive($plugin, null)) {
                return AP_Plugin_Install_Error::make(
                    AP_Plugin_Install_Error::ACTIVATION_FAILED,
                    $label . ' was ' . $verb . ' but could not be activated. ' . $reason
                );
            }

            return self::activationFailed($label, $verb, $reason);
        }

        if (AP_Plugin::isActive($plugin, null)) {
            return null;
        }

        try {
            $attempt = AP_Plugin::activate($plugin, null);
        } catch (Throwable $e) {
            $attempt = [
                'ok' => false,
                'errors' => ['Activation failed: ' . $e->getMessage()],
            ];
        }

        $reason = self::activationReason(is_array($attempt) ? $attempt : []);
        $activated = is_array($attempt)
            && ($attempt['ok'] ?? false) === true
            && AP_Plugin::isActive($plugin, null);
        if ($activated) {
            return null;
        }

        if (AP_Plugin::isActive($plugin, null)) {
            try {
                AP_Plugin::deactivate($plugin, null);
            } catch (Throwable) {
                // The message below reports that the plugin could not be left inactive.
            }
        }

        if (AP_Plugin::isActive($plugin, null)) {
            return AP_Plugin_Install_Error::make(
                AP_Plugin_Install_Error::ACTIVATION_FAILED,
                $label . ' was ' . $verb
                . ' but could not be activated, and it could not be left inactive. ' . $reason
            );
        }

        return self::activationFailed($label, $verb, $reason);
    }

    private static function activationFailed(string $label, string $verb, string $reason): AP_Plugin_Install_Error
    {
        $reason = trim($reason);
        if ($reason === '') {
            $reason = 'Activation failed.';
        }

        return AP_Plugin_Install_Error::make(
            AP_Plugin_Install_Error::ACTIVATION_FAILED,
            $label . ' was ' . $verb . ' but could not be activated and remains inactive. ' . $reason
        );
    }

    /**
     * @param array<string, mixed> $attempt
     */
    private static function activationReason(array $attempt): string
    {
        $errors = $attempt['errors'] ?? [];
        if (!is_array($errors)) {
            return 'Activation failed.';
        }

        $parts = [];
        foreach ($errors as $error) {
            if (!is_string($error)) {
                continue;
            }
            $error = trim($error);
            if ($error !== '') {
                $parts[] = $error;
            }
        }

        return $parts === [] ? 'Activation failed.' : implode(' ', $parts);
    }

    /**
     * Active basenames for this slug, captured before the previous files move.
     *
     * Null when plugin activation state is not available for this plugins
     * directory (the installer is writing somewhere other than the live
     * plugins root, or the plugin API is not loaded). An empty basename list
     * means the plugin is not active.
     *
     * @param array{
     *   slug: string,
     *   plugin: string,
     *   is_folder: bool,
     *   plugins_root: string,
     *   previous_version: string|null
     * } $staged
     *
     * @return array{basenames: list<string>, from_version: string|null}|null
     */
    private static function snapshotActiveUpgrade(array $staged): ?array
    {
        if (!self::installerUsesLivePlugins($staged['plugins_root'])) {
            return null;
        }

        $basenames = [];
        foreach (AP_Plugin::getActivePlugins(null, false) as $basename) {
            if (self::basenameBelongsToInstalledSlug($basename, $staged['slug'], $staged['is_folder'] === true)) {
                $basenames[] = $basename;
            }
        }
        if ($basenames === []) {
            return ['basenames' => [], 'from_version' => null];
        }

        $preferred = $basenames[0];
        foreach ($basenames as $basename) {
            if ($basename === $staged['plugin']) {
                $preferred = $basename;
                break;
            }
        }

        $from = null;
        $headers = AP_Plugin::getPluginHeaders($preferred);
        if (is_array($headers)) {
            $version = trim((string) ($headers['Version'] ?? ''));
            if ($version !== '') {
                $from = $version;
            }
        }
        if ($from === null && is_string($staged['previous_version'])) {
            $previous = trim($staged['previous_version']);
            if ($previous !== '') {
                $from = $previous;
            }
        }

        return [
            'basenames' => $basenames,
            'from_version' => $from,
        ];
    }

    /**
     * Leave an already-active plugin active and run its upgrade routine.
     *
     * @param array{plugin: string, version: string} $staged
     * @param array{basenames: list<string>, from_version: string|null} $activeUpgrade
     */
    private static function finishActiveUpgrade(array $staged, array $activeUpgrade): ?AP_Plugin_Install_Error
    {
        if (!class_exists('AP_Plugin', false) || !method_exists(AP_Plugin::class, 'keepActiveAndUpgrade')) {
            return null;
        }

        $result = AP_Plugin::keepActiveAndUpgrade(
            $staged['plugin'],
            $activeUpgrade['basenames'],
            $activeUpgrade['from_version'],
            $staged['version'],
            null
        );
        if ($result['ok']) {
            return null;
        }

        return AP_Plugin_Install_Error::make(
            AP_Plugin_Install_Error::INSTALL_FAILED,
            'Could not keep the active plugin active, so the previous version was restored.'
        );
    }

    /**
     * True when $pluginsRoot is the directory {@see AP_Plugin} treats as live.
     */
    private static function installerUsesLivePlugins(string $pluginsRoot): bool
    {
        if (!class_exists('AP_Plugin', false)) {
            return false;
        }

        $live = AP_Plugin::pluginsRoot();
        $left = realpath($pluginsRoot);
        $right = realpath($live);
        if ($left === false || $right === false) {
            return rtrim($pluginsRoot, '/\\') === rtrim($live, '/\\');
        }

        return $left === $right;
    }

    private static function basenameBelongsToInstalledSlug(string $plugin, string $slug, bool $isFolder): bool
    {
        if ($slug === '' || $plugin === '') {
            return false;
        }
        if ($isFolder) {
            return str_starts_with($plugin, $slug . '/');
        }

        return $plugin === $slug . '.php';
    }

    /**
     * Validate a zip and leave the unpacked payload in private staging.
     *
     * On success the caller owns `stage` and must move the payload or discard
     * the directory. On failure any staging directory created here is removed
     * before the error is returned.
     *
     * @param array{
     *   overwrite?: bool,
     *   activate?: bool,
     *   expected_sha256?: string,
     *   plugins_root?: string,
     *   content_dir?: string
     * } $opts
     *
     * @return array{
     *   slug: string,
     *   name: string,
     *   version: string,
     *   previous_version: string|null,
     *   exists: bool,
     *   plugin: string,
     *   is_folder: bool,
     *   main_rel: string,
     *   stage: string,
     *   payload: string,
     *   plugins_root: string,
     *   content_dir: string
     * }|AP_Plugin_Install_Error
     */
    private static function stageValidatedPackage(string $zipPath, array $opts): array|AP_Plugin_Install_Error
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
            if (!$unpacked) {
                self::discardStagingDir($staged['stage']);
                self::discardStagingDir($staged['stage']);

                return AP_Plugin_Install_Error::make(
                    AP_Plugin_Install_Error::STAGING_FAILED,
                    'Extraction failed: plugin main file missing after unpack.'
                );
            }

            return [
                'slug' => $slug,
                'name' => $name,
                'version' => $version,
                'previous_version' => $previous['version'],
                'exists' => $previous['exists'],
                'plugin' => $plugin,
                'is_folder' => $isFolder,
                'main_rel' => $mainRel,
                'stage' => $staged['stage'],
                'payload' => $staged['payload'],
                'plugins_root' => $pluginsRoot,
                'content_dir' => $contentDir,
            ];
        } finally {
            $zip->close();
        }
    }

    /**
     * @param array{
     *   slug: string,
     *   name: string,
     *   version: string,
     *   previous_version: string|null,
     *   exists: bool,
     *   plugin: string,
     *   is_folder: bool,
     *   main_rel: string,
     *   stage: string,
     *   payload: string,
     *   plugins_root: string
     * } $staged
     */
    private static function resultFromStagedPackage(
        array $staged,
        ?string $backupPath,
        bool $activated
    ): AP_Plugin_Install_Result {
        $exists = $staged['exists'] === true;

        return AP_Plugin_Install_Result::success(
            $staged['slug'],
            $staged['name'],
            $staged['version'],
            $exists ? $staged['previous_version'] : null,
            $exists
                ? AP_Plugin_Install_Result::ACTION_UPGRADED
                : AP_Plugin_Install_Result::ACTION_INSTALLED,
            $backupPath,
            $activated,
            $staged['plugin']
        );
    }

    private static function alreadyInstalledError(string $slug): AP_Plugin_Install_Error
    {
        return AP_Plugin_Install_Error::make(
            AP_Plugin_Install_Error::ALREADY_INSTALLED,
            'A plugin with the slug “' . $slug . '” is already installed and was left unchanged.'
        );
    }

    /**
     * @param array{plugins_root: string, is_folder: bool, slug: string, plugin: string} $staged
     */
    private static function pluginDestination(array $staged): string
    {
        $root = rtrim($staged['plugins_root'], '/\\');
        if ($staged['is_folder']) {
            return $root . '/' . $staged['slug'];
        }

        return $root . '/' . $staged['plugin'];
    }

    /**
     * Move a staged payload for a new slug into the plugins directory.
     *
     * The destination must not already exist. A failed copy removes a partial
     * destination. The caller still owns the staging directory.
     *
     * @param array{
     *   slug: string,
     *   name: string,
     *   version: string,
     *   plugin: string,
     *   is_folder: bool,
     *   main_rel: string,
     *   payload: string,
     *   plugins_root: string
     * } $staged
     */
    private static function placeNewSlug(array $staged): ?AP_Plugin_Install_Error
    {
        $pluginsRoot = $staged['plugins_root'];
        if ($pluginsRoot === '' || !self::ensureDir($pluginsRoot)) {
            return AP_Plugin_Install_Error::make(
                AP_Plugin_Install_Error::INSTALL_FAILED,
                'Plugins directory is missing or not writable.'
            );
        }

        $isFolder = $staged['is_folder'];
        $dest = self::pluginDestination($staged);
        if (file_exists($dest) || is_link($dest)) {
            return self::alreadyInstalledError($staged['slug']);
        }

        $source = $isFolder ? $staged['payload'] : $staged['payload'] . '/' . $staged['main_rel'];
        $sourceOk = $isFolder
            ? (is_dir($source) && !is_link($source))
            : (is_file($source) && !is_link($source));
        if (!$sourceOk) {
            return AP_Plugin_Install_Error::make(
                AP_Plugin_Install_Error::INSTALL_FAILED,
                'Could not install the plugin into the plugins directory.'
            );
        }

        if (!@rename($source, $dest)) {
            if (file_exists($dest) || is_link($dest)) {
                return self::alreadyInstalledError($staged['slug']);
            }

            $copied = $isFolder ? self::copyDir($source, $dest) : @copy($source, $dest);
            if ($copied !== true) {
                self::removePlacedPayload($isFolder, $dest);

                return AP_Plugin_Install_Error::make(
                    AP_Plugin_Install_Error::INSTALL_FAILED,
                    'Could not install the plugin into the plugins directory.'
                );
            }
            if ($isFolder) {
                self::removeDir($source);
            } else {
                @unlink($source);
            }
        }

        if (!self::placedPluginIsIntact($staged, $dest)) {
            self::removePlacedPayload($isFolder, $dest);

            return AP_Plugin_Install_Error::make(
                AP_Plugin_Install_Error::INSTALL_FAILED,
                'Could not install the plugin into the plugins directory.'
            );
        }

        return null;
    }

    /**
     * Whether the moved plugin file and its folder stay inside the plugins directory.
     */
    private static function placedPluginStaysInside(string $pluginsRoot, string $dest, string $installedFile): bool
    {
        if (is_link($dest) || is_link($installedFile) || !is_file($installedFile)) {
            return false;
        }

        $rootReal = realpath($pluginsRoot);
        $destReal = realpath($dest);
        $fileReal = realpath($installedFile);
        if ($rootReal === false || $destReal === false || $fileReal === false) {
            return false;
        }

        $root = self::normalizeFsPath($rootReal);
        $destNorm = self::normalizeFsPath($destReal);
        $fileNorm = self::normalizeFsPath($fileReal);
        $destInside = $destNorm !== $root && self::pathIsInside($root, $destNorm);
        $fileInside = $fileNorm !== $root && self::pathIsInside($root, $fileNorm);

        return $destInside && $fileInside;
    }

    /**
     * Remove a destination this installer just created. A missing path is success.
     */
    private static function removePlacedPayload(bool $isFolder, string $dest): void
    {
        if (is_link($dest)) {
            @unlink($dest);

            return;
        }
        if ($isFolder) {
            if (is_dir($dest)) {
                self::removeDir($dest);
            }

            return;
        }
        if (is_file($dest)) {
            @unlink($dest);
        }
    }

    /**
     * Whether `overwrite` was passed as boolean true.
     *
     * Only `true` replaces an existing slug. Other truthy values do not.
     *
     * @param array<string, mixed> $opts
     */
    private static function overwriteRequested(array $opts): bool
    {
        return ($opts['overwrite'] ?? false) === true;
    }

    /**
     * Replace an existing slug with the staged payload.
     *
     * The previous plugin is moved into a new stamp directory first. The
     * staged payload is then moved into the plugins directory. `rename` is
     * preferred. A copy is used only when `rename` cannot (for example across
     * filesystems). Any failure after the previous plugin has left the plugins
     * directory restores that backup.
     *
     * @param array{
     *   slug: string,
     *   name: string,
     *   version: string,
     *   plugin: string,
     *   is_folder: bool,
     *   main_rel: string,
     *   payload: string,
     *   plugins_root: string,
     *   content_dir: string
     * } $staged
     *
     * @return string|AP_Plugin_Install_Error Absolute stamp directory, or an error.
     */
    private static function replaceExistingSlug(array $staged): string|AP_Plugin_Install_Error
    {
        $pluginsRoot = $staged['plugins_root'];
        if ($pluginsRoot === '' || !self::ensureDir($pluginsRoot)) {
            return self::installFailed('Plugins directory is missing or not writable.');
        }

        $dest = self::pluginDestination($staged);
        $isFolder = $staged['is_folder'] === true;
        if (is_link($dest)) {
            return self::installFailed(
                'Cannot replace a symlinked plugin path. The existing plugin was left unchanged.'
            );
        }

        $present = $isFolder ? is_dir($dest) : is_file($dest);
        if (!$present) {
            return self::backupFailed('Could not back up the existing plugin, so it was left unchanged.');
        }

        $backupRoot = self::ensureBackupRoot($staged['content_dir'], $pluginsRoot);
        if ($backupRoot instanceof AP_Plugin_Install_Error) {
            return $backupRoot;
        }

        $slugDir = $backupRoot . '/' . $staged['slug'];
        if (is_link($slugDir) || !self::ensurePrivateDir($slugDir) || is_link($slugDir)) {
            return self::backupDirFailed();
        }

        $stampDir = self::createBackupStamp($slugDir);
        if ($stampDir === null) {
            return self::backupDirFailed();
        }

        $backupPayload = $stampDir . '/' . basename($dest);
        $moved = self::movePath($dest, $backupPayload);
        if (!$moved || !self::backupPayloadIsSafe($backupRoot, $pluginsRoot, $backupPayload)) {
            if (!file_exists($dest) && !is_link($dest) && (file_exists($backupPayload) || is_link($backupPayload))) {
                self::restoreBackupPayload($backupPayload, $dest, $isFolder);
            }
            self::removeBackupStamp($stampDir);

            return self::backupFailed('Could not back up the existing plugin, so it was left unchanged.');
        }

        if (self::upgradeShouldFail('after_backup')) {
            return self::restoreOrKeepBackup($staged, $stampDir, $backupPayload, $dest, $isFolder);
        }

        $source = $isFolder ? $staged['payload'] : $staged['payload'] . '/' . $staged['main_rel'];
        $swapped = self::movePath($source, $dest) && self::placedPluginIsIntact($staged, $dest);
        if (!$swapped || self::upgradeShouldFail('after_swap')) {
            self::removePlacedPayload($isFolder, $dest);

            return self::restoreOrKeepBackup($staged, $stampDir, $backupPayload, $dest, $isFolder);
        }

        $realStamp = realpath($stampDir);

        return $realStamp === false ? $stampDir : $realStamp;
    }

    /**
     * Put the backed-up plugin back. On success the stamp directory is removed.
     *
     * @param array{main_rel: string, is_folder: bool} $staged
     */
    private static function restoreOrKeepBackup(
        array $staged,
        string $stampDir,
        string $backupPayload,
        string $dest,
        bool $isFolder
    ): AP_Plugin_Install_Error {
        $mainName = basename($staged['main_rel']);
        $previousMain = $isFolder ? $backupPayload . '/' . $mainName : $backupPayload;
        $snapshot = (is_file($previousMain) && !is_link($previousMain))
            ? @file_get_contents($previousMain)
            : null;

        if (file_exists($dest) || is_link($dest)) {
            self::removePlacedPayload($isFolder, $dest);
        }

        $restored = self::restoreBackupPayload($backupPayload, $dest, $isFolder);
        $destOk = $isFolder
            ? (is_dir($dest) && !is_link($dest))
            : (is_file($dest) && !is_link($dest));
        $bytesOk = true;
        if (is_string($snapshot)) {
            $liveMain = $isFolder ? $dest . '/' . $mainName : $dest;
            $bytesOk = is_file($liveMain) && !is_link($liveMain) && @file_get_contents($liveMain) === $snapshot;
        }

        if (!$restored || !$destOk || !$bytesOk) {
            return self::installFailed(
                'Plugin upgrade failed. The previous version is in the backup at ' . $stampDir . '.'
            );
        }

        self::removeBackupStamp($stampDir);

        return self::installFailed('Plugin upgrade failed and the previous version was restored.');
    }

    /**
     * Undo a swap that already landed, used when staging cleanup fails afterward.
     *
     * @param array{
     *   is_folder: bool,
     *   main_rel: string,
     *   slug: string,
     *   plugin: string,
     *   plugins_root: string
     * } $staged
     */
    private static function rollBackPlacedUpgrade(array $staged, string $stampDir): bool
    {
        $dest = self::pluginDestination($staged);
        $isFolder = $staged['is_folder'] === true;
        $backupPayload = $stampDir . '/' . basename($dest);
        $mainName = basename($staged['main_rel']);
        $previousMain = $isFolder ? $backupPayload . '/' . $mainName : $backupPayload;
        $snapshot = (is_file($previousMain) && !is_link($previousMain))
            ? @file_get_contents($previousMain)
            : null;

        self::removePlacedPayload($isFolder, $dest);
        if (!self::restoreBackupPayload($backupPayload, $dest, $isFolder)) {
            return false;
        }
        if (is_string($snapshot)) {
            $liveMain = $isFolder ? $dest . '/' . $mainName : $dest;
            if (!is_file($liveMain) || is_link($liveMain) || @file_get_contents($liveMain) !== $snapshot) {
                return false;
            }
        }

        self::removeBackupStamp($stampDir);

        return true;
    }

    /**
     * Whether the placed plugin is still inside the plugins directory and matches the zip header.
     *
     * @param array{
     *   name: string,
     *   version: string,
     *   is_folder: bool,
     *   main_rel: string,
     *   plugins_root: string
     * } $staged
     */
    private static function placedPluginIsIntact(array $staged, string $dest): bool
    {
        $isFolder = $staged['is_folder'] === true;
        $installedFile = $isFolder ? $dest . '/' . basename($staged['main_rel']) : $dest;
        if (!self::placedPluginStaysInside($staged['plugins_root'], $dest, $installedFile)) {
            return false;
        }

        $raw = @file_get_contents($installedFile);
        if (!is_string($raw)) {
            return false;
        }

        $headers = self::parsePluginContents($raw);
        $name = trim((string) ($headers['Plugin Name'] ?? ''));
        $version = trim((string) ($headers['Version'] ?? ''));

        return $name === $staged['name'] && $version === $staged['version'];
    }

    /**
     * Create `{content}/.ap-plugin-backups` at mode 0700, outside the plugins directory.
     *
     * @return string|AP_Plugin_Install_Error Absolute backup root.
     */
    private static function ensureBackupRoot(string $contentDir, string $pluginsRoot): string|AP_Plugin_Install_Error
    {
        if ($contentDir === '' || !is_dir($contentDir) || is_link($contentDir) || !is_writable($contentDir)) {
            return self::backupDirFailed();
        }

        $root = rtrim($contentDir, '/\\') . '/' . self::BACKUP_PARENT_NAME;
        if (
            is_link($root)
            || self::directoryIsInside($pluginsRoot, $root)
            || self::directoryIsInside($root, $pluginsRoot)
        ) {
            return self::backupFailed(
                'Plugin backups must stay outside the plugins directory. The existing plugin was left unchanged.'
            );
        }
        $rootReady = self::ensurePrivateDir($root)
            && !is_link($root)
            && self::stagingPathIsPrivate($root, $contentDir, $pluginsRoot);
        if (!$rootReady) {
            return self::backupDirFailed();
        }

        $deny = $root . '/.htaccess';
        if (!is_file($deny)) {
            $written = @file_put_contents($deny, self::BACKUP_HTACCESS);
            if ($written === false) {
                return self::backupDirFailed();
            }
            @chmod($deny, 0644);
        }

        $real = realpath($root);
        if ($real === false) {
            return self::backupDirFailed();
        }

        return $real;
    }

    /**
     * Create one `{YmdHis}{sequence}-{hex}` directory under the slug's backup directory.
     *
     * The sequence is per process so two backups in the same UTC second still
     * sort in the order they were created.
     */
    private static function createBackupStamp(string $slugDir): ?string
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $sequence = self::$backupSequence + 1 + $attempt;
            if ($sequence > 999999) {
                return null;
            }

            $stamp = gmdate('YmdHis') . sprintf('%06d', $sequence) . '-' . bin2hex(random_bytes(4));
            if (preg_match(self::BACKUP_STAMP_PATTERN, $stamp) !== 1) {
                continue;
            }

            $path = $slugDir . '/' . $stamp;
            if (!@mkdir($path, 0700)) {
                continue;
            }

            self::$backupSequence = $sequence;
            @chmod($path, 0700);
            if (is_dir($path) && !is_link($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Whether a backed-up payload resolved inside the backup root and outside plugins.
     */
    private static function backupPayloadIsSafe(string $backupRoot, string $pluginsRoot, string $payload): bool
    {
        if (is_link($payload) || (!is_dir($payload) && !is_file($payload))) {
            return false;
        }

        $rootReal = realpath($backupRoot);
        $payloadReal = realpath($payload);
        if ($rootReal === false || $payloadReal === false) {
            return false;
        }

        $root = self::normalizeFsPath($rootReal);
        $path = self::normalizeFsPath($payloadReal);
        if (!self::pathIsInside($root, $path)) {
            return false;
        }

        $pluginsReal = realpath($pluginsRoot);
        if ($pluginsReal !== false) {
            $plugins = self::normalizeFsPath($pluginsReal);
            if ($path === $plugins || self::pathIsInside($plugins, $path)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Move a file or directory. `rename` is used when it succeeds.
     *
     * When `rename` fails and the destination was not created, the tree is
     * copied and the source is removed. A failed copy removes a partial
     * destination. Symlinks are refused.
     */
    private static function movePath(string $from, string $to): bool
    {
        if ($from === '' || $to === '' || is_link($from) || is_link($to) || file_exists($to)) {
            return false;
        }

        $fromIsDir = is_dir($from);
        $fromIsFile = is_file($from);
        if (($fromIsDir && $fromIsFile) || (!$fromIsDir && !$fromIsFile)) {
            return false;
        }

        if (@rename($from, $to)) {
            return $fromIsDir ? (is_dir($to) && !is_link($to)) : (is_file($to) && !is_link($to));
        }
        if (file_exists($to) || is_link($to)) {
            return false;
        }

        $copied = $fromIsDir ? self::copyDir($from, $to) : @copy($from, $to);
        if ($copied !== true) {
            self::removePlacedPayload($fromIsDir, $to);

            return false;
        }

        $removed = $fromIsDir ? self::removeDir($from) : @unlink($from);
        if ($removed !== true) {
            self::removePlacedPayload($fromIsDir, $to);

            return false;
        }

        return $fromIsDir ? (is_dir($to) && !is_link($to)) : (is_file($to) && !is_link($to));
    }

    /**
     * Move a backup payload back to the plugins directory.
     */
    private static function restoreBackupPayload(string $backupPayload, string $dest, bool $isFolder): bool
    {
        if (!self::movePath($backupPayload, $dest)) {
            return false;
        }
        if ($isFolder) {
            return is_dir($dest) && !is_link($dest);
        }

        return is_file($dest) && !is_link($dest);
    }

    /**
     * Delete one stamp directory. Removes the slug directory and backup root when they are empty.
     */
    private static function removeBackupStamp(string $stampDir): void
    {
        $stampDir = rtrim($stampDir, '/\\');
        $stamp = basename($stampDir);
        if (preg_match(self::BACKUP_STAMP_PATTERN, $stamp) !== 1) {
            return;
        }

        $slugDir = dirname($stampDir);
        $root = dirname($slugDir);
        if (basename($root) !== self::BACKUP_PARENT_NAME || is_link($stampDir)) {
            return;
        }

        self::removeDir($stampDir);
        if (!is_dir($slugDir) || is_link($slugDir) || !self::directoryIsEmpty($slugDir)) {
            return;
        }
        @rmdir($slugDir);

        if (!is_dir($root) || is_link($root)) {
            return;
        }
        $entries = @scandir($root);
        if (!is_array($entries)) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === '.htaccess') {
                continue;
            }

            return;
        }

        @unlink($root . '/.htaccess');
        @rmdir($root);
    }

    /**
     * Keep the newest stamp directories for one slug and delete the rest.
     *
     * `$slugDir` is `{content}/.ap-plugin-backups/{slug}`. Names that are not
     * stamp directories are left alone, as are other slugs.
     */
    private static function pruneSlugBackups(string $slugDir, int $keep): void
    {
        if ($keep < 1 || is_link($slugDir) || !is_dir($slugDir)) {
            return;
        }
        if (basename(dirname($slugDir)) !== self::BACKUP_PARENT_NAME) {
            return;
        }

        $entries = @scandir($slugDir);
        if (!is_array($entries)) {
            return;
        }

        $stamps = [];
        foreach ($entries as $entry) {
            if (preg_match(self::BACKUP_STAMP_PATTERN, $entry) !== 1) {
                continue;
            }
            $path = $slugDir . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                $stamps[] = $entry;
            }
        }

        rsort($stamps, SORT_STRING);
        foreach (array_slice($stamps, $keep) as $entry) {
            self::removeDir($slugDir . '/' . $entry);
        }
    }

    /**
     * @param array{
     *   plugins_root?: string,
     *   content_dir?: string
     * } $opts
     */
    private static function restorePreviousPackage(
        string $slug,
        array $opts
    ): AP_Plugin_Install_Result|AP_Plugin_Install_Error {
        $slug = trim($slug);
        if (!self::isValidPluginSlug($slug)) {
            return AP_Plugin_Install_Error::make(
                AP_Plugin_Install_Error::INVALID_SLUG,
                'Plugin slug must be lowercase letters, numbers, hyphens, and underscores.'
            );
        }

        $pluginsRoot = self::resolvePluginsRoot(
            isset($opts['plugins_root']) && is_string($opts['plugins_root'])
                ? $opts['plugins_root']
                : null
        );
        if ($pluginsRoot === '' || is_link($pluginsRoot) || !self::ensureDir($pluginsRoot)) {
            return self::restoreFailed('Plugins directory is missing or not writable.');
        }

        $contentDir = self::resolveContentDir(
            isset($opts['content_dir']) && is_string($opts['content_dir']) ? $opts['content_dir'] : null,
            $pluginsRoot
        );
        $backupParent = rtrim($contentDir, '/\\') . '/' . self::BACKUP_PARENT_NAME;
        if (!is_dir($backupParent) && !is_link($backupParent)) {
            return self::noBackup($slug);
        }
        if (
            is_link($backupParent)
            || !is_dir($backupParent)
            || self::directoryIsInside($pluginsRoot, $backupParent)
            || self::directoryIsInside($backupParent, $pluginsRoot)
        ) {
            return self::restoreFailed('Plugin backups are not in a private directory. Nothing was changed.');
        }

        $slugDir = $backupParent . '/' . $slug;
        if (!is_dir($slugDir) && !is_link($slugDir)) {
            return self::noBackup($slug);
        }
        if (is_link($slugDir) || !is_dir($slugDir)) {
            return self::badBackup($slug);
        }

        $rootReal = realpath($backupParent);
        $slugReal = realpath($slugDir);
        if ($rootReal === false || $slugReal === false) {
            return self::badBackup($slug);
        }
        $rootNorm = self::normalizeFsPath($rootReal);
        $slugNorm = self::normalizeFsPath($slugReal);
        if (!self::pathIsInside($rootNorm, $slugNorm)) {
            return self::badBackup($slug);
        }

        $stampDir = self::newestBackupStamp($slugReal);
        if ($stampDir === null) {
            return self::noBackup($slug);
        }

        $described = self::describeBackupPayload($stampDir, $slug);
        if ($described === null || !self::backupPayloadIsSafe($rootReal, $pluginsRoot, $described['payload'])) {
            return self::badBackup($slug);
        }
        $payloadReal = realpath($described['payload']);
        if (
            $payloadReal === false
            || self::backupTreeWalk(self::normalizeFsPath($payloadReal), $payloadReal, 0) < 0
        ) {
            return self::badBackup($slug);
        }

        $isFolder = $described['is_folder'];
        $dest = rtrim($pluginsRoot, '/\\') . '/' . $described['dest_name'];
        $other = rtrim($pluginsRoot, '/\\') . '/' . ($isFolder ? $slug . '.php' : $slug);
        if (file_exists($other) || is_link($other)) {
            return self::restoreFailed(
                'Could not restore “' . $slug . '” because both a folder and a single file are installed '
                . 'for that slug. Nothing was changed.'
            );
        }
        if (is_link($dest)) {
            return self::restoreFailed(
                'Cannot restore over a symlinked plugin path. The installed plugin was left unchanged.'
            );
        }

        $currentPresent = $isFolder
            ? (is_dir($dest) && !is_link($dest))
            : (is_file($dest) && !is_link($dest));
        $previousVersion = null;
        $currentBytes = null;
        $currentMain = null;
        if ($currentPresent && $isFolder) {
            $current = self::folderPluginIdentity($dest, $slug);
            if ($current !== null) {
                $previousVersion = $current['version'];
                $currentBytes = $current['bytes'];
                $currentMain = $current['main_rel'];
            }
        } elseif ($currentPresent) {
            $raw = @file_get_contents($dest);
            $currentBytes = is_string($raw) ? $raw : null;
            $currentMain = $slug . '.php';
            $current = self::flatPluginIdentity($dest, $slug);
            if ($current !== null) {
                $previousVersion = $current['version'];
                $currentBytes = $current['bytes'];
            }
        }

        $activeBasenames = self::activeBasenamesForSlug($pluginsRoot, $slug);
        self::firePackageHook(
            'ap_plugin_before_restore',
            self::lifecycleContext(
                [
                    'slug' => $slug,
                    'name' => $described['name'],
                    'version' => $described['version'],
                    'previous_version' => $previousVersion,
                    'plugin' => $described['plugin'],
                ],
                AP_Plugin_Install_Result::ACTION_RESTORED,
                null,
                false
            )
        );
        $displacedStamp = null;
        $displacedPayload = null;
        if ($currentPresent) {
            $prepared = self::displaceInstalledPlugin($slugReal, $pluginsRoot, $contentDir, $dest, $isFolder);
            if ($prepared instanceof AP_Plugin_Install_Error) {
                return $prepared;
            }
            $displacedStamp = $prepared['stamp'];
            $displacedPayload = $prepared['payload'];
            if (self::restoreShouldFail('after_backup')) {
                return self::finishFailedRestore(
                    $slug,
                    $isFolder,
                    $dest,
                    $described['payload'],
                    false,
                    $displacedStamp,
                    $displacedPayload,
                    $currentBytes,
                    $currentMain,
                    $described['bytes'],
                    $described['main_rel']
                );
            }
        }

        $oldPayload = $described['payload'];
        $moved = self::movePath($oldPayload, $dest);
        $liveMain = $isFolder ? $dest . '/' . $described['main_rel'] : $dest;
        $intact = $moved
            && self::placedPluginIsIntact(
                [
                    'name' => $described['name'],
                    'version' => $described['version'],
                    'is_folder' => $isFolder,
                    'main_rel' => $described['main_rel'],
                    'plugins_root' => $pluginsRoot,
                ],
                $dest
            )
            && self::fileHasBytes($liveMain, $described['bytes']);
        if (!$intact || self::restoreShouldFail('after_swap')) {
            return self::finishFailedRestore(
                $slug,
                $isFolder,
                $dest,
                $oldPayload,
                $moved,
                $displacedStamp,
                $displacedPayload,
                $currentBytes,
                $currentMain,
                $described['bytes'],
                $described['main_rel']
            );
        }

        if ($activeBasenames !== []) {
            if (!self::keepRestoredPluginActive($described['plugin'], $activeBasenames)) {
                $undo = self::undoRestoreSwap(
                    $isFolder,
                    $dest,
                    $oldPayload,
                    true,
                    $displacedStamp,
                    $displacedPayload,
                    $currentBytes,
                    $currentMain,
                    $described['bytes'],
                    $described['main_rel']
                );

                return $undo
                    ? self::restoreFailed(
                        'Could not keep the plugin active, so the installed plugin was left unchanged.'
                    )
                    : self::restoreFailed(
                        'Could not restore the previous version of “' . $slug
                        . '”, and the installed plugin could not be put back.'
                    );
            }
        }

        self::removeBackupStamp($stampDir);
        if (is_string($displacedStamp)) {
            self::pruneSlugBackups($slugReal, self::maxBackups($slug));
        }

        $activated = $activeBasenames !== []
            && self::installerUsesLivePlugins($pluginsRoot)
            && class_exists('AP_Plugin', false)
            && AP_Plugin::isActive($described['plugin'], null);
        $backupPath = null;
        if (is_string($displacedStamp)) {
            $realStamp = realpath($displacedStamp);
            $backupPath = $realStamp === false ? $displacedStamp : $realStamp;
        }

        $result = AP_Plugin_Install_Result::success(
            $slug,
            $described['name'],
            $described['version'],
            $previousVersion,
            AP_Plugin_Install_Result::ACTION_RESTORED,
            $backupPath,
            $activated,
            $described['plugin']
        );
        self::fireSettledResult($result);

        return $result;
    }

    /**
     * Move the installed plugin into a new stamp directory.
     *
     * On failure the installed plugin is still at $dest and no stamp remains.
     *
     * @return array{stamp: string, payload: string}|AP_Plugin_Install_Error
     */
    private static function displaceInstalledPlugin(
        string $slugDir,
        string $pluginsRoot,
        string $contentDir,
        string $dest,
        bool $isFolder
    ): array|AP_Plugin_Install_Error {
        $backupRoot = self::ensureBackupRoot($contentDir, $pluginsRoot);
        if ($backupRoot instanceof AP_Plugin_Install_Error) {
            return $backupRoot;
        }
        if (is_link($slugDir) || !self::ensurePrivateDir($slugDir)) {
            return self::backupDirFailed();
        }

        $stampDir = self::createBackupStamp($slugDir);
        if ($stampDir === null) {
            return self::backupDirFailed();
        }

        $payload = $stampDir . '/' . basename($dest);
        $moved = self::movePath($dest, $payload)
            && self::backupPayloadIsSafe($backupRoot, $pluginsRoot, $payload);
        if (!$moved) {
            if (!file_exists($dest) && !is_link($dest) && (file_exists($payload) || is_link($payload))) {
                self::restoreBackupPayload($payload, $dest, $isFolder);
            }
            self::removeBackupStamp($stampDir);

            return self::backupFailed('Could not back up the installed plugin, so it was left unchanged.');
        }

        $realStamp = realpath($stampDir);
        $realPayload = realpath($payload);

        return [
            'stamp' => $realStamp === false ? $stampDir : $realStamp,
            'payload' => $realPayload === false ? $payload : $realPayload,
        ];
    }

    private static function finishFailedRestore(
        string $slug,
        bool $isFolder,
        string $dest,
        string $oldPayload,
        bool $backupMoved,
        ?string $displacedStamp,
        ?string $displacedPayload,
        ?string $currentBytes,
        ?string $currentMain,
        string $backupBytes,
        string $backupMain
    ): AP_Plugin_Install_Error {
        $undo = self::undoRestoreSwap(
            $isFolder,
            $dest,
            $oldPayload,
            $backupMoved,
            $displacedStamp,
            $displacedPayload,
            $currentBytes,
            $currentMain,
            $backupBytes,
            $backupMain
        );
        if ($undo) {
            return self::restoreFailed(
                'Could not restore the previous version of “' . $slug
                . '”. The installed plugin was left unchanged.'
            );
        }

        return self::restoreFailed(
            'Could not restore the previous version of “' . $slug
            . '”, and the installed plugin could not be put back.'
        );
    }

    /**
     * Put a restore attempt back to the files that were there before it started.
     *
     * $backupMoved is true when the backup payload is currently at $dest.
     */
    private static function undoRestoreSwap(
        bool $isFolder,
        string $dest,
        string $oldPayload,
        bool $backupMoved,
        ?string $displacedStamp,
        ?string $displacedPayload,
        ?string $currentBytes,
        ?string $currentMain,
        string $backupBytes,
        string $backupMain
    ): bool {
        if ($backupMoved) {
            if (is_link($dest) || file_exists($oldPayload) || is_link($oldPayload)) {
                return false;
            }
            if (
                (file_exists($dest) || is_link($dest))
                && !self::restoreBackupPayload($dest, $oldPayload, $isFolder)
            ) {
                return false;
            }
        } elseif (file_exists($dest) || is_link($dest)) {
            if (is_link($dest)) {
                @unlink($dest);
            } else {
                self::removePlacedPayload($isFolder, $dest);
            }
        }

        $backupMainPath = $isFolder ? $oldPayload . '/' . $backupMain : $oldPayload;
        $backupOk = self::fileHasBytes($backupMainPath, $backupBytes);
        if ($displacedPayload === null) {
            return $backupOk && !file_exists($dest) && !is_link($dest);
        }

        $currentOk = true;
        if (file_exists($dest) || is_link($dest)) {
            $currentOk = false;
        } elseif (!self::restoreBackupPayload($displacedPayload, $dest, $isFolder)) {
            $currentOk = false;
        } elseif ($currentBytes === null) {
            $currentOk = $isFolder
                ? (is_dir($dest) && !is_link($dest))
                : (is_file($dest) && !is_link($dest));
        } else {
            $liveMain = $isFolder
                ? $dest . '/' . ($currentMain !== null && $currentMain !== '' ? $currentMain : $backupMain)
                : $dest;
            $currentOk = self::fileHasBytes($liveMain, $currentBytes);
        }
        if ($currentOk && is_string($displacedStamp)) {
            self::removeBackupStamp($displacedStamp);
        }

        return $backupOk && $currentOk;
    }

    private static function newestBackupStamp(string $slugDir): ?string
    {
        if (is_link($slugDir) || !is_dir($slugDir)) {
            return null;
        }

        $entries = @scandir($slugDir);
        if (!is_array($entries)) {
            return null;
        }

        $stamps = [];
        foreach ($entries as $entry) {
            if (preg_match(self::BACKUP_STAMP_PATTERN, $entry) !== 1) {
                continue;
            }
            $path = $slugDir . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                $stamps[] = $entry;
            }
        }
        if ($stamps === []) {
            return null;
        }

        rsort($stamps, SORT_STRING);

        return $slugDir . '/' . $stamps[0];
    }

    /**
     * @return array{
     *   is_folder: bool,
     *   payload: string,
     *   dest_name: string,
     *   main_rel: string,
     *   name: string,
     *   version: string,
     *   plugin: string,
     *   bytes: string
     * }|null
     */
    private static function describeBackupPayload(string $stampDir, string $slug): ?array
    {
        if (is_link($stampDir) || !is_dir($stampDir)) {
            return null;
        }

        $entries = @scandir($stampDir);
        if (!is_array($entries)) {
            return null;
        }

        $names = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $names[] = $entry;
        }
        if (count($names) !== 1) {
            return null;
        }

        $name = $names[0];
        $isFolder = $name === $slug;
        $isFile = $name === $slug . '.php';
        if ($isFolder === $isFile) {
            return null;
        }

        $payload = $stampDir . '/' . $name;
        if (is_link($payload)) {
            return null;
        }
        if ($isFolder) {
            if (!is_dir($payload)) {
                return null;
            }
            $identity = self::folderPluginIdentity($payload, $slug);
            if ($identity === null) {
                return null;
            }

            return [
                'is_folder' => true,
                'payload' => $payload,
                'dest_name' => $slug,
                'main_rel' => $identity['main_rel'],
                'name' => $identity['name'],
                'version' => $identity['version'],
                'plugin' => $identity['plugin'],
                'bytes' => $identity['bytes'],
            ];
        }
        if (!is_file($payload)) {
            return null;
        }

        $identity = self::flatPluginIdentity($payload, $slug);
        if ($identity === null) {
            return null;
        }

        return [
            'is_folder' => false,
            'payload' => $payload,
            'dest_name' => $slug . '.php',
            'main_rel' => $identity['main_rel'],
            'name' => $identity['name'],
            'version' => $identity['version'],
            'plugin' => $identity['plugin'],
            'bytes' => $identity['bytes'],
        ];
    }

    /**
     * Walk a backup payload. Returns the entry count, or -1 when it is unsafe.
     *
     * Symlinks, special files, and realpaths outside the payload are unsafe.
     * The walk stops at {@see self::DEFAULT_MAX_ENTRIES}.
     */
    private static function backupTreeWalk(string $payloadReal, string $path, int $count): int
    {
        $count++;
        if ($count > self::DEFAULT_MAX_ENTRIES || is_link($path)) {
            return -1;
        }

        $real = realpath($path);
        if ($real === false) {
            return -1;
        }
        $norm = self::normalizeFsPath($real);
        if ($norm !== $payloadReal && !self::pathIsInside($payloadReal, $norm)) {
            return -1;
        }
        if (is_dir($path)) {
            $entries = @scandir($path);
            if (!is_array($entries)) {
                return -1;
            }
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $count = self::backupTreeWalk($payloadReal, $path . '/' . $entry, $count);
                if ($count < 0) {
                    return -1;
                }
            }

            return $count;
        }
        if (!is_file($path)) {
            return -1;
        }

        return $count;
    }

    /**
     * @return array{
     *   name: string,
     *   version: string,
     *   main_rel: string,
     *   plugin: string,
     *   bytes: string
     * }|null
     */
    private static function folderPluginIdentity(string $folderPath, string $slug): ?array
    {
        if (is_link($folderPath) || !is_dir($folderPath) || !self::isValidPluginSlug($slug)) {
            return null;
        }

        $entries = @scandir($folderPath);
        if (!is_array($entries)) {
            return null;
        }

        $candidates = [];
        $bytesByFile = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
                continue;
            }
            if (!str_ends_with(strtolower($entry), '.php')) {
                continue;
            }
            $path = $folderPath . '/' . $entry;
            if (is_link($path) || !is_file($path)) {
                continue;
            }
            $raw = @file_get_contents($path);
            if (!is_string($raw) || !self::phpHasPluginName($raw)) {
                continue;
            }
            $candidates[] = $entry;
            $bytesByFile[$entry] = $raw;
        }

        $chosen = self::choosePluginMainFile($slug, $candidates);
        if ($chosen === null || !isset($bytesByFile[$chosen])) {
            return null;
        }

        $headers = self::parsePluginContents($bytesByFile[$chosen]);
        $name = trim((string) ($headers['Plugin Name'] ?? ''));
        $version = trim((string) ($headers['Version'] ?? ''));
        if ($name === '' || $version === '') {
            return null;
        }

        return [
            'name' => $name,
            'version' => $version,
            'main_rel' => $chosen,
            'plugin' => $slug . '/' . $chosen,
            'bytes' => $bytesByFile[$chosen],
        ];
    }

    /**
     * @return array{
     *   name: string,
     *   version: string,
     *   main_rel: string,
     *   plugin: string,
     *   bytes: string
     * }|null
     */
    private static function flatPluginIdentity(string $filePath, string $slug): ?array
    {
        if (is_link($filePath) || !is_file($filePath) || !self::isValidPluginSlug($slug)) {
            return null;
        }
        if (basename($filePath) !== $slug . '.php') {
            return null;
        }

        $raw = @file_get_contents($filePath);
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        $headers = self::parsePluginContents($raw);
        $name = trim((string) ($headers['Plugin Name'] ?? ''));
        $version = trim((string) ($headers['Version'] ?? ''));
        if ($name === '' || $version === '') {
            return null;
        }

        return [
            'name' => $name,
            'version' => $version,
            'main_rel' => $slug . '.php',
            'plugin' => $slug . '.php',
            'bytes' => $raw,
        ];
    }

    /**
     * @return array{slug: string, name: string, version: string, active: bool, plugin: string}|null
     */
    private static function installedFolderRow(string $pluginsRoot, string $slug): ?array
    {
        $identity = self::folderPluginIdentity(rtrim($pluginsRoot, '/\\') . '/' . $slug, $slug);
        if ($identity === null) {
            return null;
        }

        return [
            'slug' => $slug,
            'name' => $identity['name'],
            'version' => $identity['version'],
            'active' => self::installedPluginIsActive($pluginsRoot, $slug, true, $identity['plugin']),
            'plugin' => $identity['plugin'],
        ];
    }

    /**
     * @return array{slug: string, name: string, version: string, active: bool, plugin: string}|null
     */
    private static function installedFlatRow(string $pluginsRoot, string $filename): ?array
    {
        if (!str_ends_with($filename, '.php')) {
            return null;
        }
        $slug = substr($filename, 0, -4);
        $identity = self::flatPluginIdentity(rtrim($pluginsRoot, '/\\') . '/' . $filename, $slug);
        if ($identity === null) {
            return null;
        }

        return [
            'slug' => $slug,
            'name' => $identity['name'],
            'version' => $identity['version'],
            'active' => self::installedPluginIsActive($pluginsRoot, $slug, false, $identity['plugin']),
            'plugin' => $identity['plugin'],
        ];
    }

    private static function installedPluginIsActive(
        string $pluginsRoot,
        string $slug,
        bool $isFolder,
        string $plugin
    ): bool {
        if (!self::installerUsesLivePlugins($pluginsRoot) || !class_exists('AP_Plugin', false)) {
            return false;
        }
        if (AP_Plugin::isActive($plugin, null)) {
            return true;
        }
        foreach (AP_Plugin::getActivePlugins(null, false) as $basename) {
            if (self::basenameBelongsToInstalledSlug($basename, $slug, $isFolder)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function activeBasenamesForSlug(string $pluginsRoot, string $slug): array
    {
        if (
            !self::installerUsesLivePlugins($pluginsRoot)
            || !class_exists('AP_Plugin', false)
            || !method_exists(AP_Plugin::class, 'getActivePlugins')
        ) {
            return [];
        }

        $out = [];
        foreach (AP_Plugin::getActivePlugins(null, false) as $basename) {
            $inFolder = self::basenameBelongsToInstalledSlug($basename, $slug, true);
            $inFile = self::basenameBelongsToInstalledSlug($basename, $slug, false);
            if ($inFolder || $inFile) {
                $out[] = $basename;
            }
        }

        return $out;
    }

    /**
     * @param list<string> $replace
     */
    private static function keepRestoredPluginActive(string $plugin, array $replace): bool
    {
        if (!class_exists('AP_Plugin', false) || !method_exists(AP_Plugin::class, 'keepActive')) {
            return false;
        }

        $result = AP_Plugin::keepActive($plugin, $replace, null);

        return ($result['ok'] ?? false) === true && AP_Plugin::isActive($plugin, null);
    }

    private static function fileHasBytes(string $path, string $bytes): bool
    {
        if (is_link($path) || !is_file($path)) {
            return false;
        }
        $raw = @file_get_contents($path);

        return is_string($raw) && $raw === $bytes;
    }

    /**
     * True when the next restore should fail at $point. The flag is consumed.
     */
    private static function restoreShouldFail(string $point): bool
    {
        if (self::$restoreFailurePoint !== $point) {
            return false;
        }

        self::$restoreFailurePoint = null;

        return true;
    }

    private static function noBackup(string $slug): AP_Plugin_Install_Error
    {
        return AP_Plugin_Install_Error::make(
            AP_Plugin_Install_Error::NO_BACKUP,
            'No backup is available for the plugin “' . $slug . '”.'
        );
    }

    private static function badBackup(string $slug): AP_Plugin_Install_Error
    {
        return self::restoreFailed(
            'The most recent backup for “' . $slug . '” is not safe to restore and was left in place.'
        );
    }

    private static function restoreFailed(string $message): AP_Plugin_Install_Error
    {
        return AP_Plugin_Install_Error::make(AP_Plugin_Install_Error::RESTORE_FAILED, $message);
    }

    private static function directoryIsEmpty(string $dir): bool
    {
        $entries = @scandir($dir);
        if (!is_array($entries)) {
            return false;
        }
        foreach ($entries as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                return false;
            }
        }

        return true;
    }

    /**
     * True when the next upgrade should fail at $point. The flag is consumed.
     */
    private static function upgradeShouldFail(string $point): bool
    {
        if (self::$upgradeFailurePoint !== $point) {
            return false;
        }

        self::$upgradeFailurePoint = null;

        return true;
    }

    private static function installFailed(string $message): AP_Plugin_Install_Error
    {
        return AP_Plugin_Install_Error::make(AP_Plugin_Install_Error::INSTALL_FAILED, $message);
    }

    private static function backupFailed(string $message): AP_Plugin_Install_Error
    {
        return AP_Plugin_Install_Error::make(AP_Plugin_Install_Error::BACKUP_FAILED, $message);
    }

    private static function backupDirFailed(): AP_Plugin_Install_Error
    {
        return self::backupFailed(
            'Could not create a private backup directory. The existing plugin was left unchanged.'
        );
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
     * How many timestamped backups to keep for one plugin slug.
     *
     * Starts at {@see self::DEFAULT_MAX_BACKUPS}. The `ap_plugin_zip_max_backups`
     * filter receives that cap and the slug and may return a positive integer,
     * or a digit string, to replace it. Any other return value leaves the
     * default in place. Zero is not accepted: a successful upgrade always
     * keeps the backup it just wrote when that backup is among the newest
     * entries the cap allows.
     */
    public static function maxBackups(string $slug = ''): int
    {
        $default = self::DEFAULT_MAX_BACKUPS;
        if (!function_exists('ap_apply_filters')) {
            return $default;
        }

        return self::positiveIntOrDefault(
            ap_apply_filters('ap_plugin_zip_max_backups', $default, $slug),
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
