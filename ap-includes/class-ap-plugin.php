<?php

/**
 * AgoraPress plugin loader and registry.
 *
 * Discovery (headers), activation / deactivation, and request-time loading of
 * active plugins under ap-content/plugins/. Must-use plugins under
 * ap-content/mu-plugins/ load automatically before regular plugins.
 * WordPress-inspired basenames (`hello.php` or `folder/plugin.php`) and
 * classic Plugin Name headers.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * Plugin API: scan, parse headers, activate/deactivate, load active + MU plugins.
 * An active plugin can register an upgrade routine. Core runs that routine
 * after the plugin's files are replaced and the plugin stays active.
 */
class AP_Plugin
{
    /** Option storing the list of active plugin basenames. */
    public const OPTION_ACTIVE = 'active_plugins';

    /**
     * Option storing upgrade routines deferred until the next plugin load.
     *
     * Shape: plugin basename => array{from: string|null, to: string}.
     */
    public const OPTION_PENDING_UPGRADES = 'plugin_pending_upgrades';

    /** @var string|null Override plugins root (tests). */
    private static ?string $pluginsRootOverride = null;

    /** @var string|null Override must-use plugins root (tests). */
    private static ?string $muPluginsRootOverride = null;

    /** @var list<string> Plugin basenames loaded this request. */
    private static array $loaded = [];

    /** @var list<string> MU plugin basenames loaded this request. */
    private static array $muLoaded = [];

    /** @var bool Whether loadActivePlugins() has run this request. */
    private static bool $loadDone = false;

    /** @var bool Whether loadMuPlugins() has run this request. */
    private static bool $muLoadDone = false;

    /**
     * Activation hooks keyed by plugin basename.
     *
     * @var array<string, list<callable>>
     */
    private static array $activationHooks = [];

    /**
     * Deactivation hooks keyed by plugin basename.
     *
     * @var array<string, list<callable>>
     */
    private static array $deactivationHooks = [];

    /**
     * Upgrade routines keyed by plugin basename.
     *
     * Registered from the plugin main file, same as activation hooks.
     * Each callback is `function (string $plugin, ?string $fromVersion, string $toVersion): void`.
     *
     * @var array<string, list<callable>>
     */
    private static array $upgradeHooks = [];

    /**
     * Upgrades deferred during this request.
     *
     * The replacement file cannot be included again in this process, so
     * {@see self::loadActivePlugins()} must leave these pending.
     *
     * @var array<string, true>
     */
    private static array $deferredThisRequest = [];

    // -------------------------------------------------------------------------
    // Paths
    // -------------------------------------------------------------------------

    /**
     * Absolute filesystem path to the plugins directory (no trailing slash).
     */
    public static function pluginsRoot(): string
    {
        if (self::$pluginsRootOverride !== null && self::$pluginsRootOverride !== '') {
            return rtrim(self::$pluginsRootOverride, '/\\');
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

    /**
     * Absolute filesystem path to the must-use plugins directory (no trailing slash).
     */
    public static function muPluginsRoot(): string
    {
        if (self::$muPluginsRootOverride !== null && self::$muPluginsRootOverride !== '') {
            return rtrim(self::$muPluginsRootOverride, '/\\');
        }

        if (defined('AP_MU_PLUGIN_DIR')) {
            return (string) AP_MU_PLUGIN_DIR;
        }

        if (defined('AP_CONTENT_DIR')) {
            return AP_CONTENT_DIR . '/mu-plugins';
        }

        if (defined('AP_ABSPATH')) {
            return rtrim((string) AP_ABSPATH, '/\\') . '/ap-content/mu-plugins';
        }

        return dirname(__DIR__) . '/ap-content/mu-plugins';
    }

    /**
     * Override plugins root for tests. Pass null to clear.
     */
    public static function setPluginsRootOverride(?string $path): void
    {
        self::$pluginsRootOverride = $path !== null && $path !== ''
            ? rtrim($path, '/\\')
            : null;
    }

    /**
     * Override must-use plugins root for tests. Pass null to clear.
     */
    public static function setMuPluginsRootOverride(?string $path): void
    {
        self::$muPluginsRootOverride = $path !== null && $path !== ''
            ? rtrim($path, '/\\')
            : null;
    }

    /**
     * Public base URI for the plugins directory (no trailing slash).
     */
    public static function pluginsUrl(?AP_DB $db = null): string
    {
        if (defined('AP_CONTENT_URL') && is_string(AP_CONTENT_URL) && AP_CONTENT_URL !== '') {
            return rtrim((string) AP_CONTENT_URL, '/') . '/plugins';
        }

        if (class_exists('AP_Rewrite', false)) {
            $home = AP_Rewrite::homeUrl('', $db);

            return rtrim($home, '/') . '/ap-content/plugins';
        }

        return '/ap-content/plugins';
    }

    /**
     * Absolute path to a plugin's main file, or empty string when invalid.
     */
    public static function pluginPath(string $plugin): string
    {
        $plugin = self::normalizePlugin($plugin);
        if ($plugin === '' || !self::isSafePluginPath($plugin)) {
            return '';
        }

        $path = self::pluginsRoot() . '/' . $plugin;
        $realRoot = realpath(self::pluginsRoot());
        if ($realRoot === false) {
            return is_file($path) ? $path : '';
        }

        // Resolve when the file exists; otherwise keep constructed path for is_file checks.
        if (is_file($path)) {
            $real = realpath($path);
            if ($real === false || !str_starts_with($real, $realRoot . DIRECTORY_SEPARATOR)) {
                return '';
            }

            return $real;
        }

        return $path;
    }

    /**
     * Absolute directory containing the plugin main file (no trailing slash).
     */
    public static function pluginDir(string $plugin): string
    {
        $path = self::pluginPath($plugin);
        if ($path === '') {
            return '';
        }

        return dirname($path);
    }

    /**
     * Public URI for a plugin main file or its directory.
     *
     * @param string $plugin Plugin basename (folder/file.php or file.php).
     * @param string $path   Optional path relative to the plugin directory.
     */
    public static function pluginUrl(string $plugin, string $path = '', ?AP_DB $db = null): string
    {
        $plugin = self::normalizePlugin($plugin);
        if ($plugin === '' || !self::isSafePluginPath($plugin)) {
            return '';
        }

        $base = self::pluginsUrl($db);
        // Directory plugins: base is the folder; single-file: base is the plugins root.
        if (str_contains($plugin, '/')) {
            $dir = dirname($plugin);
            $url = $base . '/' . $dir;
        } else {
            $url = $base;
        }

        $path = ltrim(str_replace('\\', '/', $path), '/');
        if ($path !== '' && !str_contains($path, '..')) {
            $url .= '/' . $path;
        }

        return $url;
    }

    /**
     * Plugin basename relative to the plugins root (WordPress-style).
     *
     * Accepts an absolute path under the plugins directory, or an already-relative
     * basename such as `hello.php` / `akismet/akismet.php`.
     */
    public static function pluginBasename(string $file): string
    {
        $file = str_replace('\\', '/', $file);
        $root = str_replace('\\', '/', self::pluginsRoot());
        $root = rtrim($root, '/');

        if ($file !== '' && str_starts_with($file, $root . '/')) {
            $rel = substr($file, strlen($root) + 1);
        } else {
            $rel = ltrim($file, '/');
        }

        $rel = self::normalizePlugin($rel);
        if ($rel === '' || !self::isSafePluginPath($rel)) {
            return '';
        }

        return $rel;
    }

    // -------------------------------------------------------------------------
    // Headers & discovery
    // -------------------------------------------------------------------------

    /**
     * Known classic plugin header field names (WordPress-compatible).
     *
     * @return list<string>
     */
    public static function headerFields(): array
    {
        return [
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
    }

    /**
     * Parse plugin headers from the top of a PHP file.
     *
     * @return array<string, string>
     */
    public static function parsePluginFile(string $path): array
    {
        if (!is_readable($path)) {
            return [];
        }

        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return [];
        }

        // Classic headers live in the first 8 KiB.
        $chunk = (string) fread($fh, 8192);
        fclose($fh);

        $known = self::headerFields();
        if (function_exists('ap_apply_filters')) {
            $filtered = ap_apply_filters('ap_plugin_header_fields', $known);
            if (is_array($filtered)) {
                $known = [];
                foreach ($filtered as $field) {
                    if (is_string($field) && $field !== '') {
                        $known[] = $field;
                    }
                }
            }
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

    /**
     * Headers for a plugin basename, or null if missing/invalid.
     *
     * @return array<string, string>|null
     */
    public static function getPluginHeaders(string $plugin): ?array
    {
        $plugin = self::normalizePlugin($plugin);
        if ($plugin === '' || !self::isSafePluginPath($plugin)) {
            return null;
        }

        $path = self::pluginPath($plugin);
        if ($path === '' || !is_readable($path)) {
            return null;
        }

        $headers = self::parsePluginFile($path);
        if ($headers === []) {
            return null;
        }

        if (trim((string) ($headers['Plugin Name'] ?? '')) === '') {
            return null;
        }

        $headers['File'] = $plugin;
        $headers['Slug'] = self::pluginSlug($plugin);

        return $headers;
    }

    /**
     * Whether a plugin basename has valid headers and a readable main file.
     */
    public static function isValidPlugin(string $plugin): bool
    {
        return self::getPluginHeaders($plugin) !== null;
    }

    /**
     * List installed plugins keyed by basename.
     *
     * @return array<string, array<string, string>>
     */
    public static function listPlugins(): array
    {
        $root = self::pluginsRoot();
        if (!is_dir($root)) {
            return [];
        }

        $out = [];
        $entries = @scandir($root);
        if (!is_array($entries)) {
            return [];
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
                continue;
            }

            $full = $root . '/' . $entry;

            // Root-level single-file plugins.
            if (is_file($full) && str_ends_with(strtolower($entry), '.php')) {
                $plugin = self::normalizePlugin($entry);
                if ($plugin === '' || $plugin !== $entry) {
                    continue;
                }
                $headers = self::getPluginHeaders($plugin);
                if ($headers !== null) {
                    $out[$plugin] = $headers;
                }
                continue;
            }

            // One level of plugin directories.
            if (!is_dir($full)) {
                continue;
            }

            $dirSlug = self::sanitizeDirName($entry);
            if ($dirSlug === '' || $dirSlug !== $entry) {
                continue;
            }

            $sub = @scandir($full);
            if (!is_array($sub)) {
                continue;
            }

            foreach ($sub as $file) {
                if ($file === '.' || $file === '..' || str_starts_with($file, '.')) {
                    continue;
                }
                if (!str_ends_with(strtolower($file), '.php')) {
                    continue;
                }
                $rel = $entry . '/' . $file;
                $plugin = self::normalizePlugin($rel);
                if ($plugin === '' || $plugin !== $rel) {
                    continue;
                }
                if (!is_file($full . '/' . $file)) {
                    continue;
                }
                $headers = self::getPluginHeaders($plugin);
                if ($headers !== null) {
                    $out[$plugin] = $headers;
                }
            }
        }

        ksort($out);

        return $out;
    }

    // -------------------------------------------------------------------------
    // Active list & activate / deactivate
    // -------------------------------------------------------------------------

    /**
     * Active plugin basenames (normalized, existing only when $validate true).
     *
     * @return list<string>
     */
    public static function getActivePlugins(?AP_DB $db = null, bool $validate = true): array
    {
        $raw = self::readActiveOption($db);
        $list = [];
        foreach ($raw as $item) {
            if (!is_string($item)) {
                continue;
            }
            $plugin = self::normalizePlugin($item);
            if ($plugin === '' || !self::isSafePluginPath($plugin)) {
                continue;
            }
            if ($validate && !self::isValidPlugin($plugin)) {
                continue;
            }
            $list[] = $plugin;
        }

        // Stable unique order.
        $list = array_values(array_unique($list));
        sort($list);

        return $list;
    }

    /**
     * Whether a plugin is currently active.
     */
    public static function isActive(string $plugin, ?AP_DB $db = null): bool
    {
        $plugin = self::normalizePlugin($plugin);
        if ($plugin === '') {
            return false;
        }

        return in_array($plugin, self::getActivePlugins($db, false), true);
    }

    /**
     * Whether a plugin main file was included this request.
     */
    public static function isLoaded(string $plugin): bool
    {
        $plugin = self::normalizePlugin($plugin);

        return $plugin !== '' && in_array($plugin, self::$loaded, true);
    }

    /**
     * Activate a plugin: include file, fire activation hooks, persist active list.
     *
     * Fires `ap_plugin_before_activate` with the plugin basename before the
     * file is loaded, then `ap_activate_plugin` and `ap_activate_{slug}` after
     * the plugin's own activation callbacks. `ap_plugin_after_activate` fires
     * only after the active list is saved. An already active plugin does not
     * fire them. Exceptions from the before and after actions are ignored.
     *
     * @return array{ok: bool, errors: list<string>}
     */
    public static function activate(string $plugin, ?AP_DB $db = null): array
    {
        $plugin = self::normalizePlugin($plugin);
        $errors = [];

        if ($plugin === '' || !self::isSafePluginPath($plugin)) {
            return ['ok' => false, 'errors' => ['Invalid plugin path.']];
        }

        $headers = self::getPluginHeaders($plugin);
        if ($headers === null) {
            return ['ok' => false, 'errors' => ['Plugin not found or missing Plugin Name header.']];
        }

        $phpReq = trim((string) ($headers['Requires PHP'] ?? ''));
        if ($phpReq !== '' && version_compare(PHP_VERSION, $phpReq, '<')) {
            return [
                'ok' => false,
                'errors' => ['This plugin requires PHP ' . $phpReq . ' or higher.'],
            ];
        }

        if (self::isActive($plugin, $db)) {
            // Idempotent success when already active (ensure loaded).
            self::includePlugin($plugin);

            return ['ok' => true, 'errors' => []];
        }

        self::fireLifecycle('ap_plugin_before_activate', $plugin);

        // Include so the plugin can register activation hooks.
        $included = self::includePlugin($plugin);
        if (!$included) {
            return ['ok' => false, 'errors' => ['Failed to load the plugin file.']];
        }

        self::runHooks(self::$activationHooks[$plugin] ?? [], $plugin);

        if (function_exists('ap_do_action')) {
            ap_do_action('ap_activate_plugin', $plugin);
            ap_do_action('ap_activate_' . self::hookSlug($plugin), $plugin);
        }

        $active = self::getActivePlugins($db, false);
        $active[] = $plugin;
        $active = array_values(array_unique($active));
        sort($active);

        if (!self::writeActiveOption($active, $db)) {
            $errors[] = 'Plugin loaded but the active list could not be saved.';

            return ['ok' => false, 'errors' => $errors];
        }

        self::fireLifecycle('ap_plugin_after_activate', $plugin);

        return ['ok' => true, 'errors' => []];
    }

    /**
     * Deactivate a plugin: fire deactivation hooks, remove from active list.
     *
     * Fires `ap_plugin_before_deactivate` with the plugin basename before the
     * plugin's own deactivation callbacks, then `ap_deactivate_plugin` and
     * `ap_deactivate_{slug}`. `ap_plugin_after_deactivate` fires only after
     * the active list is saved. An inactive plugin does not fire them.
     * Exceptions from the before and after actions are ignored.
     *
     * @return array{ok: bool, errors: list<string>}
     */
    public static function deactivate(string $plugin, ?AP_DB $db = null): array
    {
        $plugin = self::normalizePlugin($plugin);

        if ($plugin === '' || !self::isSafePluginPath($plugin)) {
            return ['ok' => false, 'errors' => ['Invalid plugin path.']];
        }

        if (!self::isActive($plugin, $db)) {
            return ['ok' => true, 'errors' => []];
        }

        self::fireLifecycle('ap_plugin_before_deactivate', $plugin);

        // Ensure the plugin is loaded so deactivation hooks registered at top-level run.
        if (self::isValidPlugin($plugin) && !self::isLoaded($plugin)) {
            self::includePlugin($plugin);
        }

        self::runHooks(self::$deactivationHooks[$plugin] ?? [], $plugin);

        if (function_exists('ap_do_action')) {
            ap_do_action('ap_deactivate_plugin', $plugin);
            ap_do_action('ap_deactivate_' . self::hookSlug($plugin), $plugin);
        }

        $active = array_values(array_filter(
            self::getActivePlugins($db, false),
            static fn (string $p): bool => $p !== $plugin
        ));
        sort($active);

        if (!self::writeActiveOption($active, $db)) {
            return ['ok' => false, 'errors' => ['Could not update the active plugins list.']];
        }

        self::fireLifecycle('ap_plugin_after_deactivate', $plugin);

        return ['ok' => true, 'errors' => []];
    }

    /**
     * Fire a before or after activate/deactivate action.
     *
     * Names are literals so the hook inventory can see them. Listener
     * exceptions are ignored so a logger cannot cancel activation.
     */
    private static function fireLifecycle(string $hook, string $plugin): void
    {
        if (!function_exists('ap_do_action')) {
            return;
        }

        try {
            if ($hook === 'ap_plugin_before_activate') {
                ap_do_action('ap_plugin_before_activate', $plugin);
            } elseif ($hook === 'ap_plugin_after_activate') {
                ap_do_action('ap_plugin_after_activate', $plugin);
            } elseif ($hook === 'ap_plugin_before_deactivate') {
                ap_do_action('ap_plugin_before_deactivate', $plugin);
            } elseif ($hook === 'ap_plugin_after_deactivate') {
                ap_do_action('ap_plugin_after_deactivate', $plugin);
            }
        } catch (Throwable) {
            // Observers may log. They do not cancel activate or deactivate.
        }
    }

    /**
     * Register a callback to run when a plugin is activated.
     *
     * Pass the plugin main file path (typically __FILE__) as $file.
     *
     * @param callable $callback
     */
    public static function registerActivationHook(string $file, callable $callback): void
    {
        $plugin = self::pluginBasename($file);
        if ($plugin === '') {
            return;
        }
        self::$activationHooks[$plugin] ??= [];
        self::$activationHooks[$plugin][] = $callback;
    }

    /**
     * Register a callback to run when a plugin is deactivated.
     *
     * @param callable $callback
     */
    public static function registerDeactivationHook(string $file, callable $callback): void
    {
        $plugin = self::pluginBasename($file);
        if ($plugin === '') {
            return;
        }
        self::$deactivationHooks[$plugin] ??= [];
        self::$deactivationHooks[$plugin][] = $callback;
    }

    /**
     * Register a callback to run when an active plugin is upgraded in place.
     *
     * The callback receives the plugin basename, the previous version (or null
     * when that version was not recorded), and the new version. Core calls it
     * only when the plugin stays active. It does not run on a fresh install.
     *
     * @param callable $callback function (string $plugin, ?string $fromVersion, string $toVersion): void
     */
    public static function registerUpgradeHook(string $file, callable $callback): void
    {
        $plugin = self::pluginBasename($file);
        if ($plugin === '') {
            return;
        }
        self::$upgradeHooks[$plugin] ??= [];
        self::$upgradeHooks[$plugin][] = $callback;
    }

    /**
     * Keep a plugin on the active list without activation or deactivation hooks.
     *
     * `$replace` basenames are removed. Pass the previous main file when an
     * upgrade changes the basename and the plugin should stay active under the
     * new file. The plugin file must already be a valid plugin.
     *
     * @param list<string> $replace
     *
     * @return array{ok: bool, errors: list<string>}
     */
    public static function keepActive(string $plugin, array $replace = [], ?AP_DB $db = null): array
    {
        $plugin = self::normalizePlugin($plugin);
        if ($plugin === '' || !self::isSafePluginPath($plugin)) {
            return ['ok' => false, 'errors' => ['Invalid plugin path.']];
        }
        if (!self::isValidPlugin($plugin)) {
            return ['ok' => false, 'errors' => ['Plugin not found or missing Plugin Name header.']];
        }

        $drop = self::normalizeBasenameSet($replace);
        $drop[$plugin] = true;

        $active = self::getActivePlugins($db, false);
        $next = [];
        foreach ($active as $item) {
            if (!isset($drop[$item])) {
                $next[] = $item;
            }
        }
        $next[] = $plugin;
        $next = array_values(array_unique($next));
        sort($next);

        $current = $active;
        sort($current);
        if ($next !== $current && !self::writeActiveOption($next, $db)) {
            return ['ok' => false, 'errors' => ['Could not update the active plugins list.']];
        }

        self::forgetLoadedBasenames($drop, $plugin);

        return ['ok' => true, 'errors' => []];
    }

    /**
     * Keep an upgraded plugin active and run its upgrade routine.
     *
     * The routine runs now when this request has not included the plugin, so
     * the replacement file can register it. When this request already included
     * that file, the routine is stored and runs on the next
     * {@see self::loadActivePlugins()}. Activation and deactivation hooks are
     * not run. On failure the active list is put back the way it was.
     *
     * @param list<string> $replace Previous basenames that belong to this plugin.
     *
     * @return array{ok: bool, ran: bool, deferred: bool, errors: list<string>}
     */
    public static function keepActiveAndUpgrade(
        string $plugin,
        array $replace,
        ?string $fromVersion,
        string $toVersion,
        ?AP_DB $db = null
    ): array {
        $before = self::getActivePlugins($db, false);
        $kept = self::keepActive($plugin, $replace, $db);
        if (!$kept['ok']) {
            return [
                'ok' => false,
                'ran' => false,
                'deferred' => false,
                'errors' => $kept['errors'],
            ];
        }

        $result = self::upgrade($plugin, $fromVersion, $toVersion, $db);
        if (!$result['ok']) {
            self::writeActiveOption($before, $db);
            $normalized = self::normalizePlugin($plugin);
            self::$loaded = array_values(array_filter(
                self::$loaded,
                static fn (string $loaded): bool => $loaded !== $normalized
            ));
        }

        return $result;
    }

    /**
     * Run the upgrade routine for a plugin that is already active.
     *
     * No routine is run when the plugin is inactive or when it did not
     * register one. A plugin already included in this request cannot be
     * included again, so the routine is deferred until the next load.
     *
     * @return array{ok: bool, ran: bool, deferred: bool, errors: list<string>}
     */
    public static function upgrade(
        string $plugin,
        ?string $fromVersion,
        string $toVersion,
        ?AP_DB $db = null
    ): array {
        $plugin = self::normalizePlugin($plugin);
        $toVersion = trim($toVersion);
        $from = $fromVersion !== null ? trim($fromVersion) : '';
        $fromVersion = $from !== '' ? $from : null;

        if ($plugin === '' || !self::isSafePluginPath($plugin)) {
            return [
                'ok' => false,
                'ran' => false,
                'deferred' => false,
                'errors' => ['Invalid plugin path.'],
            ];
        }
        if (!self::isActive($plugin, $db)) {
            return ['ok' => true, 'ran' => false, 'deferred' => false, 'errors' => []];
        }

        if (self::isLoaded($plugin) || !self::includePlugin($plugin)) {
            $stored = self::deferUpgrade($plugin, $fromVersion, $toVersion, $db);
            if (!$stored) {
                return [
                    'ok' => false,
                    'ran' => false,
                    'deferred' => false,
                    'errors' => ['Could not store the plugin upgrade routine for the next load.'],
                ];
            }

            return ['ok' => true, 'ran' => false, 'deferred' => true, 'errors' => []];
        }

        self::runUpgradeHooks($plugin, $fromVersion, $toVersion);
        self::forgetPendingUpgrade($plugin, $db);

        return ['ok' => true, 'ran' => true, 'deferred' => false, 'errors' => []];
    }

    /**
     * Include all active plugins (once per request).
     *
     * Deferred upgrade routines run after each plugin is included and before
     * `ap_plugins_loaded`.
     */
    public static function loadActivePlugins(?AP_DB $db = null): void
    {
        if (self::$loadDone) {
            return;
        }
        self::$loadDone = true;

        $pending = self::readPendingUpgrades($db);
        $ran = [];
        foreach (self::getActivePlugins($db, true) as $plugin) {
            self::includePlugin($plugin);
            if (
                !isset($pending[$plugin])
                || !self::isLoaded($plugin)
                || isset(self::$deferredThisRequest[$plugin])
            ) {
                continue;
            }
            $job = $pending[$plugin];
            self::runUpgradeHooks($plugin, $job['from'], $job['to']);
            $ran[] = $plugin;
        }
        self::clearFinishedPendingUpgrades($pending, $ran, $db);

        if (function_exists('ap_do_action')) {
            ap_do_action('ap_plugins_loaded');
        }
    }

    // -------------------------------------------------------------------------
    // Must-use plugins (always loaded; not activatable)
    // -------------------------------------------------------------------------

    /**
     * List must-use plugins (root-level .php files only).
     *
     * Unlike regular plugins, MU files load even without a Plugin Name header.
     * Headers are used for admin display when present.
     *
     * @return array<string, array<string, string>>
     */
    public static function listMuPlugins(): array
    {
        $root = self::muPluginsRoot();
        if (!is_dir($root)) {
            return [];
        }

        $out = [];
        $entries = @scandir($root);
        if (!is_array($entries)) {
            return [];
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
                continue;
            }
            if (!str_ends_with(strtolower($entry), '.php')) {
                continue;
            }
            if (str_contains($entry, '/') || str_contains($entry, '\\') || str_contains($entry, '..')) {
                continue;
            }
            if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.\\-]*\\.php$/', $entry) !== 1) {
                continue;
            }

            $full = $root . '/' . $entry;
            if (!is_file($full) || !is_readable($full)) {
                continue;
            }

            $headers = self::parsePluginFile($full);
            if (trim((string) ($headers['Plugin Name'] ?? '')) === '') {
                $headers['Plugin Name'] = pathinfo($entry, PATHINFO_FILENAME);
            }
            $headers['File'] = $entry;
            $headers['Slug'] = pathinfo($entry, PATHINFO_FILENAME);
            $headers['MustUse'] = '1';
            $out[$entry] = $headers;
        }

        ksort($out);

        return $out;
    }

    /**
     * Absolute path to a must-use plugin file, or empty string.
     */
    public static function muPluginPath(string $plugin): string
    {
        $plugin = self::normalizePlugin($plugin);
        if ($plugin === '' || str_contains($plugin, '/') || !self::isSafePluginPath($plugin)) {
            return '';
        }

        $path = self::muPluginsRoot() . '/' . $plugin;
        $realRoot = realpath(self::muPluginsRoot());
        if ($realRoot === false) {
            return is_file($path) ? $path : '';
        }

        if (is_file($path)) {
            $real = realpath($path);
            if ($real === false || !str_starts_with($real, $realRoot . DIRECTORY_SEPARATOR)) {
                return '';
            }

            return $real;
        }

        return '';
    }

    /**
     * Whether a must-use plugin was included this request.
     */
    public static function isMuLoaded(string $plugin): bool
    {
        $plugin = self::normalizePlugin($plugin);

        return $plugin !== '' && in_array($plugin, self::$muLoaded, true);
    }

    /**
     * Include all must-use plugins (once per request).
     *
     * Loads every root-level `.php` file under mu-plugins/. Fires
     * `ap_mu_plugins_loaded` when finished. Call before loadActivePlugins().
     */
    public static function loadMuPlugins(): void
    {
        if (self::$muLoadDone) {
            return;
        }
        self::$muLoadDone = true;

        foreach (array_keys(self::listMuPlugins()) as $plugin) {
            self::includeMuPlugin($plugin);
        }

        if (function_exists('ap_do_action')) {
            ap_do_action('ap_mu_plugins_loaded');
        }
    }

    /**
     * Reset static state (unit tests).
     */
    public static function reset(): void
    {
        self::$pluginsRootOverride = null;
        self::$muPluginsRootOverride = null;
        self::$loaded = [];
        self::$muLoaded = [];
        self::$loadDone = false;
        self::$muLoadDone = false;
        self::$activationHooks = [];
        self::$deactivationHooks = [];
        self::$upgradeHooks = [];
        self::$deferredThisRequest = [];
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * Include a plugin main file if not already loaded.
     */
    private static function includePlugin(string $plugin): bool
    {
        $plugin = self::normalizePlugin($plugin);
        if ($plugin === '' || self::isLoaded($plugin)) {
            return $plugin !== '' && self::isLoaded($plugin);
        }

        $path = self::pluginPath($plugin);
        if ($path === '' || !is_readable($path)) {
            return false;
        }

        // Mark loaded before include to avoid recursion if the plugin re-enters.
        self::$loaded[] = $plugin;

        try {
            include_once $path;
        } catch (Throwable) {
            self::$loaded = array_values(array_filter(
                self::$loaded,
                static fn (string $p): bool => $p !== $plugin
            ));

            return false;
        }

        return true;
    }

    /**
     * Include a must-use plugin file if not already loaded.
     */
    private static function includeMuPlugin(string $plugin): bool
    {
        $plugin = self::normalizePlugin($plugin);
        if ($plugin === '' || self::isMuLoaded($plugin)) {
            return $plugin !== '' && self::isMuLoaded($plugin);
        }

        $path = self::muPluginPath($plugin);
        if ($path === '' || !is_readable($path)) {
            return false;
        }

        self::$muLoaded[] = $plugin;

        try {
            include_once $path;
        } catch (Throwable) {
            self::$muLoaded = array_values(array_filter(
                self::$muLoaded,
                static fn (string $p): bool => $p !== $plugin
            ));

            return false;
        }

        return true;
    }

    /**
     * @param list<callable> $hooks
     */
    private static function runHooks(array $hooks, mixed ...$args): void
    {
        foreach ($hooks as $cb) {
            if (!is_callable($cb)) {
                continue;
            }
            try {
                $cb(...$args);
            } catch (Throwable) {
                // Activation, deactivation, and upgrade must not fatal the request.
            }
        }
    }

    /**
     * @param list<string> $basenames
     *
     * @return array<string, true>
     */
    private static function normalizeBasenameSet(array $basenames): array
    {
        $drop = [];
        foreach ($basenames as $item) {
            if (!is_string($item)) {
                continue;
            }
            $normalized = self::normalizePlugin($item);
            if ($normalized === '' || !self::isSafePluginPath($normalized)) {
                continue;
            }
            $drop[$normalized] = true;
        }

        return $drop;
    }

    /**
     * Drop replaced basenames from the loaded list. The plugin that stays active is kept.
     *
     * @param array<string, true> $drop
     */
    private static function forgetLoadedBasenames(array $drop, string $keep): void
    {
        if ($drop === []) {
            return;
        }
        self::$loaded = array_values(array_filter(
            self::$loaded,
            static function (string $loaded) use ($drop, $keep): bool {
                if ($loaded === $keep) {
                    return true;
                }

                return !isset($drop[$loaded]);
            }
        ));
    }

    private static function runUpgradeHooks(string $plugin, ?string $fromVersion, string $toVersion): void
    {
        self::runHooks(self::$upgradeHooks[$plugin] ?? [], $plugin, $fromVersion, $toVersion);
    }

    private static function deferUpgrade(
        string $plugin,
        ?string $fromVersion,
        string $toVersion,
        ?AP_DB $db
    ): bool {
        if ($toVersion === '') {
            return false;
        }
        $pending = self::readPendingUpgrades($db);
        $pending[$plugin] = [
            'from' => $fromVersion,
            'to' => $toVersion,
        ];
        self::$deferredThisRequest[$plugin] = true;

        return self::writePendingUpgrades($pending, $db);
    }

    private static function forgetPendingUpgrade(string $plugin, ?AP_DB $db): void
    {
        $pending = self::readPendingUpgrades($db);
        if (!isset($pending[$plugin])) {
            return;
        }
        unset($pending[$plugin]);
        self::writePendingUpgrades($pending, $db);
    }

    /**
     * Remove routines that ran, and routines whose plugin is no longer active.
     *
     * A plugin that is still active but failed to load keeps its pending row
     * so a later request can try the replacement file.
     *
     * @param array<string, array{from: string|null, to: string}> $original
     * @param list<string> $ran
     */
    private static function clearFinishedPendingUpgrades(array $original, array $ran, ?AP_DB $db): void
    {
        if ($original === [] && $ran === []) {
            $latest = self::readPendingUpgrades($db);
            if ($latest === []) {
                return;
            }
        }

        $latest = self::readPendingUpgrades($db);
        if ($latest === [] && $original === []) {
            return;
        }

        $active = array_flip(self::getActivePlugins($db, false));
        $ranSet = array_flip($ran);
        $next = [];
        foreach ($latest as $plugin => $job) {
            if (isset($ranSet[$plugin]) || !isset($active[$plugin])) {
                continue;
            }
            $next[$plugin] = $job;
        }
        if ($next !== $original) {
            self::writePendingUpgrades($next, $db);
        }
    }

    /**
     * @return array<string, array{from: string|null, to: string}>
     */
    private static function readPendingUpgrades(?AP_DB $db): array
    {
        if (class_exists('AP_Options', false)) {
            $value = AP_Options::get(self::OPTION_PENDING_UPGRADES, [], $db);
        } elseif (function_exists('ap_get_option')) {
            $value = ap_get_option(self::OPTION_PENDING_UPGRADES, [], $db);
        } else {
            $value = self::readOptionRaw(self::OPTION_PENDING_UPGRADES, $db);
        }

        return self::normalizePendingUpgrades($value);
    }

    /**
     * @param array<string, array{from: string|null, to: string}> $pending
     */
    private static function writePendingUpgrades(array $pending, ?AP_DB $db): bool
    {
        $pending = self::normalizePendingUpgrades($pending);
        if (class_exists('AP_Options', false)) {
            return AP_Options::update(self::OPTION_PENDING_UPGRADES, $pending, $db);
        }
        if (function_exists('ap_update_option')) {
            return ap_update_option(self::OPTION_PENDING_UPGRADES, $pending, $db);
        }

        return self::writeOptionRaw(self::OPTION_PENDING_UPGRADES, $pending, $db);
    }

    /**
     * @return array<string, array{from: string|null, to: string}>
     */
    private static function normalizePendingUpgrades(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $plugin => $job) {
            if (!is_string($plugin) || !is_array($job)) {
                continue;
            }
            $plugin = self::normalizePlugin($plugin);
            if ($plugin === '' || !self::isSafePluginPath($plugin)) {
                continue;
            }
            $to = trim((string) ($job['to'] ?? ''));
            if ($to === '') {
                continue;
            }
            $fromRaw = $job['from'] ?? null;
            $from = is_string($fromRaw) ? trim($fromRaw) : '';
            $out[$plugin] = [
                'from' => $from !== '' ? $from : null,
                'to' => $to,
            ];
        }
        ksort($out);

        return $out;
    }

    /**
     * @return list<mixed>
     */
    private static function readActiveOption(?AP_DB $db): array
    {
        $value = null;
        if (class_exists('AP_Options', false)) {
            $value = AP_Options::get(self::OPTION_ACTIVE, [], $db);
        } elseif (function_exists('ap_get_option')) {
            $value = ap_get_option(self::OPTION_ACTIVE, [], $db);
        } else {
            $value = self::readOptionRaw(self::OPTION_ACTIVE, $db);
        }

        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
            // Comma-separated fallback.
            $parts = array_map('trim', explode(',', $value));

            return array_values(array_filter($parts, static fn (string $p): bool => $p !== ''));
        }

        return [];
    }

    /**
     * @param list<string> $plugins
     */
    private static function writeActiveOption(array $plugins, ?AP_DB $db): bool
    {
        $plugins = array_values(array_unique(array_map(
            static fn (string $p): string => self::normalizePlugin($p),
            $plugins
        )));
        $plugins = array_values(array_filter(
            $plugins,
            static fn (string $p): bool => $p !== '' && self::isSafePluginPath($p)
        ));
        sort($plugins);

        if (class_exists('AP_Options', false)) {
            return AP_Options::update(self::OPTION_ACTIVE, $plugins, $db);
        }
        if (function_exists('ap_update_option')) {
            return ap_update_option(self::OPTION_ACTIVE, $plugins, $db);
        }

        return self::writeOptionRaw(self::OPTION_ACTIVE, $plugins, $db);
    }

    private static function readOptionRaw(string $name, ?AP_DB $db): mixed
    {
        $db = self::resolveDb($db);
        if ($db === null) {
            return null;
        }
        try {
            $raw = $db->getVar(
                'SELECT option_value FROM ' . $db->quoteIdentifier($db->table('options'))
                . ' WHERE option_name = ? LIMIT 1',
                [$name]
            );
        } catch (Throwable) {
            return null;
        }

        if ($raw === null) {
            return null;
        }
        $trim = ltrim((string) $raw);
        if ($trim !== '' && ($trim[0] === '{' || $trim[0] === '[')) {
            $decoded = json_decode((string) $raw, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        return (string) $raw;
    }

    /**
     * @param list<string> $value
     */
    private static function writeOptionRaw(string $name, array $value, ?AP_DB $db): bool
    {
        $db = self::resolveDb($db);
        if ($db === null) {
            return false;
        }

        $stored = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($stored)) {
            return false;
        }

        try {
            $existing = $db->getVar(
                'SELECT option_id FROM ' . $db->quoteIdentifier($db->table('options'))
                . ' WHERE option_name = ? LIMIT 1',
                [$name]
            );
            if ($existing !== null) {
                return $db->update(
                    'options',
                    ['option_value' => $stored, 'autoload' => 'yes'],
                    ['option_name' => $name]
                ) !== false;
            }

            return $db->insert('options', [
                'option_name' => $name,
                'option_value' => $stored,
                'autoload' => 'yes',
            ]) !== false;
        } catch (Throwable) {
            return false;
        }
    }

    private static function resolveDb(?AP_DB $db): ?AP_DB
    {
        if ($db instanceof AP_DB) {
            return $db;
        }
        if (isset($GLOBALS['apdb']) && $GLOBALS['apdb'] instanceof AP_DB) {
            return $GLOBALS['apdb'];
        }
        if (function_exists('ap_db')) {
            try {
                return ap_db();
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }

    /**
     * Normalize separators and trim; does not force lowercase (plugin files are case-sensitive).
     */
    private static function normalizePlugin(string $plugin): string
    {
        $plugin = str_replace('\\', '/', trim($plugin));
        $plugin = ltrim($plugin, '/');
        // Collapse repeated slashes.
        $plugin = preg_replace('#/+#', '/', $plugin) ?? $plugin;

        return $plugin;
    }

    /**
     * Reject path traversal and absolute paths.
     */
    private static function isSafePluginPath(string $plugin): bool
    {
        if ($plugin === '' || str_starts_with($plugin, '/') || str_contains($plugin, '..')) {
            return false;
        }
        if (preg_match('#^[a-zA-Z0-9][a-zA-Z0-9_./\\-]*\\.php$#', $plugin) !== 1) {
            return false;
        }
        // At most one directory level (file.php or dir/file.php).
        if (substr_count($plugin, '/') > 1) {
            return false;
        }

        return true;
    }

    private static function sanitizeDirName(string $value): string
    {
        $value = trim($value);
        if ($value === '' || str_contains($value, '..') || str_contains($value, '/') || str_contains($value, '\\')) {
            return '';
        }
        if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.\\-]*$/', $value) !== 1) {
            return '';
        }

        return $value;
    }

    /**
     * Human-friendly slug from basename (folder name or file stem).
     */
    private static function pluginSlug(string $plugin): string
    {
        if (str_contains($plugin, '/')) {
            return dirname($plugin);
        }

        return pathinfo($plugin, PATHINFO_FILENAME);
    }

    /**
     * Safe hook fragment for per-plugin actions.
     */
    private static function hookSlug(string $plugin): string
    {
        $slug = strtolower(str_replace(['/', '\\', '.'], '_', $plugin));
        $slug = preg_replace('/[^a-z0-9_]+/', '_', $slug) ?? $slug;

        return trim($slug, '_');
    }
}
