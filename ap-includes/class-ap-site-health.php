<?php

/**
 * AgoraPress Site Health — runtime status checks and system information.
 *
 * Used by Tools → Site Health for critical/recommended/good checks and a
 * copy-friendly support info dump. Extensible via filter `ap_site_health_checks`
 * and `ap_site_health_info`.
 *
 * Privacy: checks and info never transmit data off-site. Version-check results
 * only appear if already cached by {@see AP_Version_Check} (no forced network).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * Site Health status checks + info collectors.
 */
class AP_Site_Health
{
    /** Status: everything looks fine. */
    public const STATUS_GOOD = 'good';

    /** Status: should fix soon; site still works. */
    public const STATUS_RECOMMENDED = 'recommended';

    /** Status: needs attention; may break features. */
    public const STATUS_CRITICAL = 'critical';

    /** Placeholder salt value from ap-config-sample.php. */
    public const DEFAULT_SALT_PLACEHOLDER = 'put your unique phrase here';

    /**
     * Auth key / salt constant names checked for uniqueness.
     *
     * @return list<string>
     */
    public static function saltConstants(): array
    {
        return [
            'AP_AUTH_KEY',
            'AP_SECURE_AUTH_KEY',
            'AP_LOGGED_IN_KEY',
            'AP_NONCE_KEY',
            'AP_AUTH_SALT',
            'AP_SECURE_AUTH_SALT',
            'AP_LOGGED_IN_SALT',
            'AP_NONCE_SALT',
        ];
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Run all Site Health checks (filtered).
     *
     * Each item:
     * - id, label, status (good|recommended|critical), message, badge (optional)
     *
     * @return list<array{
     *     id: string,
     *     label: string,
     *     status: string,
     *     message: string,
     *     badge?: string
     * }>
     */
    public static function getChecks(?AP_DB $db = null, ?string $abspath = null): array
    {
        $db = self::resolveDb($db);
        $root = self::resolveAbspath($abspath);
        $checks = [];

        $checks = array_merge($checks, self::checksFromRequirements($root));
        $checks[] = self::checkDatabase($db);
        $checks[] = self::checkSchema($db);
        $checks[] = self::checkSalts();
        $checks[] = self::checkDebugMode();
        $checks[] = self::checkTelemetry();
        $checks[] = self::checkHttps($db);
        $checks[] = self::checkAdminEmail($db);
        $checks[] = self::checkPrivacyPolicy($db);
        $checks[] = self::checkModules($db);
        $checks[] = self::checkCoreUpdate($db);
        $checks[] = self::checkObjectCache();
        $checks[] = self::checkPageCache();
        $checks[] = self::checkAutoloadOptions($db);
        $checks[] = self::checkPhpMemory();
        $checks[] = self::checkDiskSpace($root);

        if (function_exists('ap_apply_filters')) {
            $filtered = ap_apply_filters('ap_site_health_checks', $checks, $db);
            if (is_array($filtered)) {
                $checks = $filtered;
            }
        }

        return self::normalizeChecks($checks);
    }

    /**
     * Aggregate counts by status.
     *
     * @param list<array{status?: string}>|null $checks Pass null to run checks.
     *
     * @return array{good: int, recommended: int, critical: int, total: int}
     */
    public static function getSummary(?array $checks = null, ?AP_DB $db = null): array
    {
        if ($checks === null) {
            $checks = self::getChecks($db);
        }

        $summary = [
            'good' => 0,
            'recommended' => 0,
            'critical' => 0,
            'total' => 0,
        ];
        foreach ($checks as $check) {
            $status = self::normalizeStatus((string) ($check['status'] ?? self::STATUS_GOOD));
            if (!isset($summary[$status])) {
                $status = self::STATUS_GOOD;
            }
            $summary[$status]++;
            $summary['total']++;
        }

        return $summary;
    }

    /**
     * Overall site status from check results.
     *
     * @param list<array{status?: string}>|null $checks
     */
    public static function getOverallStatus(?array $checks = null, ?AP_DB $db = null): string
    {
        $summary = self::getSummary($checks, $db);
        if ($summary['critical'] > 0) {
            return self::STATUS_CRITICAL;
        }
        if ($summary['recommended'] > 0) {
            return self::STATUS_RECOMMENDED;
        }

        return self::STATUS_GOOD;
    }

    /**
     * Structured system information for the Info tab / support copy box.
     *
     * @return array<string, array{label: string, fields: list<array{label: string, value: string}>}>
     */
    public static function getInfo(?AP_DB $db = null, ?string $abspath = null): array
    {
        $db = self::resolveDb($db);
        $root = self::resolveAbspath($abspath);

        $sections = [
            'agorapress' => [
                'label' => 'AgoraPress',
                'fields' => self::infoAgorapress($db),
            ],
            'server' => [
                'label' => 'Server',
                'fields' => self::infoServer($root),
            ],
            'performance' => [
                'label' => 'Performance',
                'fields' => self::infoPerformance($db),
            ],
            'database' => [
                'label' => 'Database',
                'fields' => self::infoDatabase($db),
            ],
            'directories' => [
                'label' => 'Directories',
                'fields' => self::infoDirectories($root),
            ],
            'constants' => [
                'label' => 'Configuration constants',
                'fields' => self::infoConstants(),
            ],
            'modules' => [
                'label' => 'Modules & features',
                'fields' => self::infoModules($db),
            ],
        ];

        if (function_exists('ap_apply_filters')) {
            $filtered = ap_apply_filters('ap_site_health_info', $sections, $db);
            if (is_array($filtered)) {
                $sections = $filtered;
            }
        }

        return $sections;
    }

    /**
     * Plain-text dump of system info for copy/paste support.
     */
    public static function getInfoText(?AP_DB $db = null, ?string $abspath = null): string
    {
        $sections = self::getInfo($db, $abspath);
        $lines = [
            '### AgoraPress Site Health — System Information',
            'Generated: ' . gmdate('Y-m-d H:i:s') . ' UTC',
            '',
        ];

        foreach ($sections as $section) {
            $label = (string) ($section['label'] ?? 'Section');
            $lines[] = '## ' . $label;
            $fields = $section['fields'] ?? [];
            if (!is_array($fields)) {
                $fields = [];
            }
            foreach ($fields as $field) {
                if (!is_array($field)) {
                    continue;
                }
                $fl = (string) ($field['label'] ?? '');
                $fv = (string) ($field['value'] ?? '');
                $lines[] = $fl . ': ' . $fv;
            }
            $lines[] = '';
        }

        return rtrim(implode("\n", $lines)) . "\n";
    }

    /**
     * Clear runtime caches: object cache flush + expired option-backed transients.
     *
     * @return array{ok: bool, object_cache: bool, expired_transients: int, message: string}
     */
    public static function clearCaches(?AP_DB $db = null): array
    {
        $db = self::resolveDb($db);
        $objectOk = false;
        if (function_exists('ap_cache_flush')) {
            $objectOk = (bool) ap_cache_flush();
        } elseif (function_exists('ap_object_cache_instance')) {
            $cache = ap_object_cache_instance();
            if (is_object($cache) && method_exists($cache, 'flush')) {
                $objectOk = (bool) $cache->flush();
            }
        }

        $expired = self::deleteExpiredTransients($db);

        if (function_exists('ap_do_action')) {
            ap_do_action('ap_site_health_caches_cleared', $expired, $objectOk, $db);
        }

        return [
            'ok' => true,
            'object_cache' => $objectOk,
            'expired_transients' => $expired,
            'message' => sprintf(
                'Cleared runtime caches (%d expired transient%s).',
                $expired,
                $expired === 1 ? '' : 's'
            ),
        ];
    }

    /**
     * Delete option-backed transients whose timeout has passed.
     *
     * @return int Number of value rows removed.
     */
    public static function deleteExpiredTransients(?AP_DB $db = null): int
    {
        $db = self::resolveDb($db);
        if ($db === null) {
            return 0;
        }

        try {
            $table = $db->quoteIdentifier($db->table('options'));
            $rows = $db->getResults(
                'SELECT option_name, option_value FROM ' . $table
                . ' WHERE option_name LIKE ?',
                ['_transient_timeout_%']
            );
        } catch (Throwable) {
            return 0;
        }

        if (!is_array($rows) || $rows === []) {
            return 0;
        }

        $now = time();
        $deleted = 0;
        foreach ($rows as $row) {
            $data = is_array($row) ? $row : get_object_vars($row);
            $timeoutName = (string) ($data['option_name'] ?? '');
            $timeout = (int) ($data['option_value'] ?? 0);
            if ($timeoutName === '' || $timeout > $now) {
                continue;
            }
            // _transient_timeout_{name} → _transient_{name}
            $valueName = '_transient_' . substr($timeoutName, strlen('_transient_timeout_'));
            if (class_exists('AP_Options', false)) {
                AP_Options::delete($timeoutName, $db);
                if (AP_Options::delete($valueName, $db)) {
                    $deleted++;
                }
            } else {
                try {
                    $db->delete('options', ['option_name' => $timeoutName]);
                    $db->delete('options', ['option_name' => $valueName]);
                    $deleted++;
                } catch (Throwable) {
                    // ignore
                }
            }
        }

        return $deleted;
    }

    // -------------------------------------------------------------------------
    // Checks
    // -------------------------------------------------------------------------

    /**
     * Map AP_Requirements results into Site Health check rows.
     *
     * @return list<array{id: string, label: string, status: string, message: string}>
     */
    private static function checksFromRequirements(string $root): array
    {
        if (!class_exists('AP_Requirements', false)) {
            $path = dirname(__FILE__) . '/class-ap-requirements.php';
            if (is_file($path)) {
                require_once $path;
            }
        }
        if (!class_exists('AP_Requirements', false)) {
            return [[
                'id' => 'requirements',
                'label' => 'Server requirements',
                'status' => self::STATUS_RECOMMENDED,
                'message' => 'Requirements checker is not available.',
            ]];
        }

        $out = [];
        foreach (AP_Requirements::check($root) as $req) {
            $ok = (bool) ($req['ok'] ?? false);
            $required = (bool) ($req['required'] ?? true);
            if ($ok) {
                $status = self::STATUS_GOOD;
            } elseif ($required) {
                $status = self::STATUS_CRITICAL;
            } else {
                $status = self::STATUS_RECOMMENDED;
            }
            $out[] = [
                'id' => (string) ($req['id'] ?? 'req'),
                'label' => (string) ($req['label'] ?? 'Check'),
                'status' => $status,
                'message' => (string) ($req['message'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * @return array{id: string, label: string, status: string, message: string}
     */
    private static function checkDatabase(?AP_DB $db): array
    {
        if ($db === null) {
            return [
                'id' => 'database',
                'label' => 'Database connection',
                'status' => self::STATUS_CRITICAL,
                'message' => 'No database connection is available.',
            ];
        }

        try {
            $driver = $db->getDriver();
            $prefix = $db->getPrefix();
            // Touch options (or schema_migrations) to verify queries work.
            $table = $db->quoteIdentifier($db->table('options'));
            $db->getVar('SELECT 1 FROM ' . $table . ' LIMIT 1');

            return [
                'id' => 'database',
                'label' => 'Database connection',
                'status' => self::STATUS_GOOD,
                'message' => sprintf(
                    'Connected via %s (table prefix “%s”).',
                    $driver,
                    $prefix
                ),
            ];
        } catch (Throwable $e) {
            return [
                'id' => 'database',
                'label' => 'Database connection',
                'status' => self::STATUS_CRITICAL,
                'message' => 'Database query failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * @return array{id: string, label: string, status: string, message: string}
     */
    private static function checkSchema(?AP_DB $db): array
    {
        if ($db === null || !class_exists('AP_Migrator', false)) {
            return [
                'id' => 'schema',
                'label' => 'Database schema',
                'status' => self::STATUS_RECOMMENDED,
                'message' => 'Schema migrator is not available to verify migrations.',
            ];
        }

        try {
            $migrator = new AP_Migrator($db, AP_Migrator::defaultMigrationsPath());
            $pending = $migrator->pending();
            $target = AP_Migrator::codeTargetVersion();
            if ($pending === []) {
                return [
                    'id' => 'schema',
                    'label' => 'Database schema',
                    'status' => self::STATUS_GOOD,
                    'message' => sprintf(
                        'Schema is up to date (target version %d).',
                        $target
                    ),
                ];
            }

            $versions = [];
            foreach ($pending as $m) {
                if (is_object($m) && method_exists($m, 'version')) {
                    $versions[] = (string) $m->version();
                } elseif (is_array($m) && isset($m['version'])) {
                    $versions[] = (string) $m['version'];
                }
            }

            return [
                'id' => 'schema',
                'label' => 'Database schema',
                'status' => self::STATUS_CRITICAL,
                'message' => sprintf(
                    '%d pending migration(s)%s. Run the installer/updater or ap_run_core_update path.',
                    count($pending),
                    $versions !== [] ? ' (' . implode(', ', $versions) . ')' : ''
                ),
            ];
        } catch (Throwable $e) {
            return [
                'id' => 'schema',
                'label' => 'Database schema',
                'status' => self::STATUS_CRITICAL,
                'message' => 'Could not verify schema: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * @return array{id: string, label: string, status: string, message: string}
     */
    private static function checkSalts(): array
    {
        $missing = [];
        $placeholder = [];
        foreach (self::saltConstants() as $name) {
            if (!defined($name)) {
                $missing[] = $name;
                continue;
            }
            $val = (string) constant($name);
            if ($val === '' || $val === self::DEFAULT_SALT_PLACEHOLDER) {
                $placeholder[] = $name;
            }
        }

        if ($missing !== [] || $placeholder !== []) {
            $parts = [];
            if ($missing !== []) {
                $parts[] = 'undefined: ' . implode(', ', $missing);
            }
            if ($placeholder !== []) {
                $parts[] = 'placeholder/empty: ' . implode(', ', $placeholder);
            }

            return [
                'id' => 'salts',
                'label' => 'Authentication keys & salts',
                'status' => self::STATUS_CRITICAL,
                'message' => 'Unique keys and salts are required for secure sessions and nonces. Issues — '
                    . implode('; ', $parts),
            ];
        }

        return [
            'id' => 'salts',
            'label' => 'Authentication keys & salts',
            'status' => self::STATUS_GOOD,
            'message' => 'All authentication keys and salts are defined with unique values.',
        ];
    }

    /**
     * @return array{id: string, label: string, status: string, message: string}
     */
    private static function checkDebugMode(): array
    {
        $debug = defined('AP_DEBUG') && AP_DEBUG;
        $display = defined('AP_DEBUG_DISPLAY') && AP_DEBUG_DISPLAY;

        if (!$debug) {
            return [
                'id' => 'debug_mode',
                'label' => 'Debug mode',
                'status' => self::STATUS_GOOD,
                'message' => 'AP_DEBUG is off (recommended for production).',
            ];
        }

        if ($display) {
            return [
                'id' => 'debug_mode',
                'label' => 'Debug mode',
                'status' => self::STATUS_RECOMMENDED,
                'message' => 'AP_DEBUG and AP_DEBUG_DISPLAY are on. Disable display of errors on public sites.',
            ];
        }

        return [
            'id' => 'debug_mode',
            'label' => 'Debug mode',
            'status' => self::STATUS_RECOMMENDED,
            'message' => 'AP_DEBUG is on. Fine for staging; turn off on production when finished troubleshooting.',
        ];
    }

    /**
     * Telemetry is never used in core (no constant, flag, or option).
     *
     * @return array{id: string, label: string, status: string, message: string}
     */
    private static function checkTelemetry(): array
    {
        return [
            'id' => 'telemetry',
            'label' => 'Telemetry',
            'status' => self::STATUS_GOOD,
            'message' => 'Core does not include telemetry. No phone-home collectors ship with AgoraPress.',
        ];
    }

    /**
     * @return array{id: string, label: string, status: string, message: string}
     */
    private static function checkHttps(?AP_DB $db): array
    {
        $url = '';
        if (function_exists('ap_get_option')) {
            $raw = ap_get_option('siteurl', '', $db);
            $url = is_string($raw) ? $raw : '';
        }
        if ($url === '' && defined('AP_SITEURL') && is_string(AP_SITEURL)) {
            $url = (string) AP_SITEURL;
        }

        if ($url === '') {
            return [
                'id' => 'https',
                'label' => 'HTTPS / site URL',
                'status' => self::STATUS_RECOMMENDED,
                'message' => 'Site URL is not configured yet.',
            ];
        }

        $scheme = strtolower((string) (parse_url($url, PHP_URL_SCHEME) ?: ''));
        if ($scheme === 'https') {
            return [
                'id' => 'https',
                'label' => 'HTTPS / site URL',
                'status' => self::STATUS_GOOD,
                'message' => 'Site URL uses HTTPS (' . $url . ').',
            ];
        }

        // Localhost HTTP is acceptable for development.
        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?: ''));
        if (
            in_array($host, ['localhost', '127.0.0.1', '::1'], true)
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.test')
        ) {
            return [
                'id' => 'https',
                'label' => 'HTTPS / site URL',
                'status' => self::STATUS_GOOD,
                'message' => 'Site URL uses HTTP on a local host (' . $url . ') — fine for development.',
            ];
        }

        return [
            'id' => 'https',
            'label' => 'HTTPS / site URL',
            'status' => self::STATUS_RECOMMENDED,
            'message' => 'Site URL is not HTTPS (' . $url . '). Use TLS in production for secure logins and cookies.',
        ];
    }

    /**
     * @return array{id: string, label: string, status: string, message: string}
     */
    private static function checkAdminEmail(?AP_DB $db): array
    {
        $email = '';
        if (function_exists('ap_get_option')) {
            $raw = ap_get_option('admin_email', '', $db);
            $email = is_string($raw) ? trim($raw) : '';
        }

        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [
                'id' => 'admin_email',
                'label' => 'Administration email',
                'status' => self::STATUS_GOOD,
                'message' => 'Admin email is set to ' . $email . '.',
            ];
        }

        return [
            'id' => 'admin_email',
            'label' => 'Administration email',
            'status' => self::STATUS_RECOMMENDED,
            'message' => 'Set a valid administration email under Settings → General.',
        ];
    }

    /**
     * @return array{id: string, label: string, status: string, message: string}
     */
    private static function checkPrivacyPolicy(?AP_DB $db): array
    {
        $pageId = 0;
        if (class_exists('AP_Privacy', false)) {
            $pageId = AP_Privacy::getPrivacyPolicyPageId($db);
        } elseif (function_exists('ap_get_privacy_policy_page_id')) {
            $pageId = ap_get_privacy_policy_page_id($db);
        } elseif (function_exists('ap_get_option')) {
            $pageId = (int) ap_get_option('wp_page_for_privacy_policy', 0, $db);
        }

        if ($pageId > 0) {
            return [
                'id' => 'privacy_policy',
                'label' => 'Privacy policy page',
                'status' => self::STATUS_GOOD,
                'message' => 'A privacy policy page is selected (ID ' . $pageId . ').',
            ];
        }

        return [
            'id' => 'privacy_policy',
            'label' => 'Privacy policy page',
            'status' => self::STATUS_RECOMMENDED,
            'message' => 'No privacy policy page is selected. Configure one under Settings → Privacy.',
        ];
    }

    /**
     * @return array{id: string, label: string, status: string, message: string}
     */
    private static function checkModules(?AP_DB $db): array
    {
        $blog = true;
        $pages = true;
        $forum = true;
        if (class_exists('AP_Options', false) && method_exists('AP_Options', 'isModuleEnabled')) {
            $blog = AP_Options::isModuleEnabled('blog', $db);
            $pages = AP_Options::isModuleEnabled('static_pages', $db);
            $forum = AP_Options::isModuleEnabled('forum', $db);
        } elseif (function_exists('ap_get_option')) {
            $blog = (string) ap_get_option('ap_module_blog', '1', $db) !== '0';
            $pages = (string) ap_get_option('ap_module_static_pages', '1', $db) !== '0';
            $forum = (string) ap_get_option('ap_module_forum', '1', $db) !== '0';
        }

        $enabled = [];
        if ($blog) {
            $enabled[] = 'Blog';
        }
        if ($pages) {
            $enabled[] = 'Static Pages';
        }
        if ($forum) {
            $enabled[] = 'Forum';
        }

        if ($enabled === []) {
            return [
                'id' => 'modules',
                'label' => 'Content modules',
                'status' => self::STATUS_CRITICAL,
                'message' => 'All modules are disabled. Enable at least one under Settings → Modules.',
            ];
        }

        return [
            'id' => 'modules',
            'label' => 'Content modules',
            'status' => self::STATUS_GOOD,
            'message' => 'Enabled: ' . implode(', ', $enabled) . '.',
        ];
    }

    /**
     * Uses cached version-check data only — does not contact the network.
     *
     * @return array{id: string, label: string, status: string, message: string}
     */
    private static function checkCoreUpdate(?AP_DB $db): array
    {
        $local = defined('AP_VERSION') ? (string) AP_VERSION : 'unknown';

        if (!class_exists('AP_Version_Check', false)) {
            return [
                'id' => 'core_update',
                'label' => 'Core updates',
                'status' => self::STATUS_GOOD,
                'message' => 'Version check is not loaded. Installed: ' . $local . '.',
            ];
        }

        if (!AP_Version_Check::isEnabled($db)) {
            return [
                'id' => 'core_update',
                'label' => 'Core updates',
                'status' => self::STATUS_GOOD,
                'message' => 'Automatic version checks are disabled. Installed: ' . $local . '.',
            ];
        }

        // Read the transient directly so Site Health never triggers a network fetch.
        $remote = '';
        if (class_exists('AP_Transient', false)) {
            $cached = AP_Transient::get(AP_Version_Check::TRANSIENT_KEY, false, $db);
            if (is_array($cached) && !empty($cached['ok']) && !empty($cached['version'])) {
                $remote = (string) $cached['version'];
            }
        }

        if ($remote !== '' && AP_Version_Check::isNewer($remote, $local)) {
            return [
                'id' => 'core_update',
                'label' => 'Core updates',
                'status' => self::STATUS_RECOMMENDED,
                'message' => sprintf(
                    'Update available: %s (installed %s). See Tools → Update Core.',
                    $remote,
                    $local
                ),
            ];
        }

        return [
            'id' => 'core_update',
            'label' => 'Core updates',
            'status' => self::STATUS_GOOD,
            'message' => $remote !== ''
                ? sprintf('Core is up to date with cached remote %s (installed %s).', $remote, $local)
                : 'No newer version in cache. Installed: ' . $local . '.',
        ];
    }

    /**
     * @return array{id: string, label: string, status: string, message: string}
     */
    private static function checkObjectCache(): array
    {
        $external = function_exists('ap_using_ext_object_cache') && ap_using_ext_object_cache();
        if ($external) {
            return [
                'id' => 'object_cache',
                'label' => 'Object cache',
                'status' => self::STATUS_GOOD,
                'message' => 'An external object-cache drop-in is active.',
            ];
        }

        return [
            'id' => 'object_cache',
            'label' => 'Object cache',
            'status' => self::STATUS_GOOD,
            'message' => 'Using the default in-memory object cache. Optional drop-in: ap-content/object-cache.php.',
        ];
    }

    /**
     * Full-page cache drop-in status (AP_CACHE + advanced-cache.php).
     *
     * @return array{id: string, label: string, status: string, message: string}
     */
    private static function checkPageCache(): array
    {
        $enabled = defined('AP_CACHE') && AP_CACHE;
        $dropIn = '';
        if (defined('AP_CONTENT_DIR')) {
            $dropIn = rtrim((string) AP_CONTENT_DIR, '/\\') . '/advanced-cache.php';
        } elseif (defined('AP_ABSPATH')) {
            $dropIn = rtrim((string) AP_ABSPATH, '/\\') . '/ap-content/advanced-cache.php';
        }
        $hasDropIn = $dropIn !== '' && is_readable($dropIn);

        if ($enabled && $hasDropIn) {
            return [
                'id' => 'page_cache',
                'label' => 'Page cache',
                'status' => self::STATUS_GOOD,
                'message' => 'AP_CACHE is enabled and advanced-cache.php is present.',
            ];
        }
        if ($enabled && !$hasDropIn) {
            return [
                'id' => 'page_cache',
                'label' => 'Page cache',
                'status' => self::STATUS_RECOMMENDED,
                'message' => 'AP_CACHE is true but ap-content/advanced-cache.php was not found. Install a page-cache drop-in or set AP_CACHE to false.',
            ];
        }

        return [
            'id' => 'page_cache',
            'label' => 'Page cache',
            'status' => self::STATUS_GOOD,
            'message' => 'Full-page caching is optional. Set AP_CACHE and drop in advanced-cache.php when ready.',
        ];
    }

    /**
     * Autoloaded options payload size (N+1 prevention / memory budget).
     *
     * Soft thresholds: ≥ 800 KiB recommended, ≥ 1.5 MiB critical.
     *
     * @return array{id: string, label: string, status: string, message: string}
     */
    private static function checkAutoloadOptions(?AP_DB $db): array
    {
        $stats = ['count' => 0, 'bytes' => 0];
        if (class_exists('AP_Options', false)) {
            $stats = AP_Options::getAutoloadStats($db);
        } elseif (function_exists('ap_get_autoload_option_stats')) {
            $stats = ap_get_autoload_option_stats($db);
        }

        $count = (int) ($stats['count'] ?? 0);
        $bytes = (int) ($stats['bytes'] ?? 0);
        $label = self::formatBytes($bytes);
        $base = sprintf('%s across %d autoloaded option%s', $label, $count, $count === 1 ? '' : 's');

        // 1.5 MiB critical, 800 KiB recommended.
        if ($bytes >= 1572864) {
            return [
                'id' => 'autoload_options',
                'label' => 'Autoloaded options',
                'status' => self::STATUS_CRITICAL,
                'message' => $base . '. Autoload payload is very large and may slow every request. Set rarely-used options to autoload=no.',
            ];
        }
        if ($bytes >= 819200) {
            return [
                'id' => 'autoload_options',
                'label' => 'Autoloaded options',
                'status' => self::STATUS_RECOMMENDED,
                'message' => $base . '. Consider reducing autoload size for faster TTFB on shared hosting.',
            ];
        }

        return [
            'id' => 'autoload_options',
            'label' => 'Autoloaded options',
            'status' => self::STATUS_GOOD,
            'message' => $base . '. Within a healthy budget for modest shared hosting.',
        ];
    }

    /**
     * PHP memory_limit vs SPEC target (≥ 64 MB recommended).
     *
     * @return array{id: string, label: string, status: string, message: string}
     */
    private static function checkPhpMemory(): array
    {
        $raw = (string) ini_get('memory_limit');
        $bytes = self::parseIniBytes($raw);
        // -1 = unlimited.
        if ($bytes < 0) {
            return [
                'id' => 'php_memory',
                'label' => 'PHP memory limit',
                'status' => self::STATUS_GOOD,
                'message' => 'memory_limit is unlimited.',
            ];
        }

        $label = $raw !== '' ? $raw : self::formatBytes($bytes);
        // SPEC §1: 64 MB+ recommended. Soft floor 40 MB critical.
        if ($bytes > 0 && $bytes < 40 * 1024 * 1024) {
            return [
                'id' => 'php_memory',
                'label' => 'PHP memory limit',
                'status' => self::STATUS_CRITICAL,
                'message' => 'memory_limit is ' . $label . '. Raise to at least 64M for stable admin and imports.',
            ];
        }
        if ($bytes > 0 && $bytes < 64 * 1024 * 1024) {
            return [
                'id' => 'php_memory',
                'label' => 'PHP memory limit',
                'status' => self::STATUS_RECOMMENDED,
                'message' => 'memory_limit is ' . $label . '. SPEC recommends 64M or more on modest shared hosting.',
            ];
        }

        return [
            'id' => 'php_memory',
            'label' => 'PHP memory limit',
            'status' => self::STATUS_GOOD,
            'message' => 'memory_limit is ' . $label . '.',
        ];
    }

    /**
     * @return array{id: string, label: string, status: string, message: string}
     */
    private static function checkDiskSpace(string $root): array
    {
        if (!function_exists('disk_free_space')) {
            return [
                'id' => 'disk_space',
                'label' => 'Free disk space',
                'status' => self::STATUS_GOOD,
                'message' => 'disk_free_space() is not available on this host.',
            ];
        }

        $path = is_dir($root) ? $root : dirname($root);
        $free = @disk_free_space($path);
        if ($free === false) {
            return [
                'id' => 'disk_space',
                'label' => 'Free disk space',
                'status' => self::STATUS_RECOMMENDED,
                'message' => 'Could not determine free disk space.',
            ];
        }

        $freeMb = (int) floor($free / (1024 * 1024));
        $label = self::formatBytes((int) $free);

        // Soft thresholds: < 50 MiB critical, < 250 MiB recommended.
        if ($freeMb < 50) {
            return [
                'id' => 'disk_space',
                'label' => 'Free disk space',
                'status' => self::STATUS_CRITICAL,
                'message' => 'Only ' . $label . ' free. Low disk space can break uploads and updates.',
            ];
        }
        if ($freeMb < 250) {
            return [
                'id' => 'disk_space',
                'label' => 'Free disk space',
                'status' => self::STATUS_RECOMMENDED,
                'message' => $label . ' free. Consider freeing space before large media uploads or core updates.',
            ];
        }

        return [
            'id' => 'disk_space',
            'label' => 'Free disk space',
            'status' => self::STATUS_GOOD,
            'message' => $label . ' free on the volume holding the site root.',
        ];
    }

    // -------------------------------------------------------------------------
    // Info sections
    // -------------------------------------------------------------------------

    /**
     * @return list<array{label: string, value: string}>
     */
    private static function infoAgorapress(?AP_DB $db): array
    {
        $version = defined('AP_VERSION') ? (string) AP_VERSION : 'unknown';
        $dbVersion = defined('AP_DB_VERSION') ? (string) AP_DB_VERSION : 'unknown';
        $siteName = '';
        $siteUrl = '';
        $home = '';
        if (function_exists('ap_get_option')) {
            $siteName = (string) ap_get_option('blogname', '', $db);
            $siteUrl = (string) ap_get_option('siteurl', '', $db);
            $home = (string) ap_get_option('home', '', $db);
        }
        if ($siteUrl === '' && defined('AP_SITEURL')) {
            $siteUrl = (string) AP_SITEURL;
        }

        return [
            ['label' => 'Version', 'value' => $version],
            ['label' => 'DB schema target', 'value' => $dbVersion],
            ['label' => 'Site title', 'value' => $siteName !== '' ? $siteName : '(not set)'],
            ['label' => 'Site URL', 'value' => $siteUrl !== '' ? $siteUrl : '(not set)'],
            ['label' => 'Home URL', 'value' => $home !== '' ? $home : '(not set)'],
        ];
    }

    /**
     * Performance-related info for support dumps (no secrets).
     *
     * @return list<array{label: string, value: string}>
     */
    private static function infoPerformance(?AP_DB $db): array
    {
        $fields = [
            ['label' => 'PHP memory_limit', 'value' => (string) (ini_get('memory_limit') ?: '(unknown)')],
            ['label' => 'PHP max_execution_time', 'value' => (string) (ini_get('max_execution_time') ?: '0')],
            ['label' => 'OPcache', 'value' => self::opcacheEnabled() ? 'enabled' : 'disabled / unavailable'],
        ];

        $autoload = class_exists('AP_Options', false)
            ? AP_Options::getAutoloadStats($db)
            : ['count' => 0, 'bytes' => 0];
        $fields[] = [
            'label' => 'Autoloaded options',
            'value' => sprintf(
                '%d (%s)',
                (int) ($autoload['count'] ?? 0),
                self::formatBytes((int) ($autoload['bytes'] ?? 0))
            ),
        ];

        $extCache = function_exists('ap_using_ext_object_cache') && ap_using_ext_object_cache();
        $fields[] = [
            'label' => 'Object cache',
            'value' => $extCache ? 'external drop-in' : 'in-memory (default)',
        ];

        $pageCache = (defined('AP_CACHE') && AP_CACHE) ? 'AP_CACHE on' : 'AP_CACHE off';
        $fields[] = ['label' => 'Page cache flag', 'value' => $pageCache];

        $numQueries = 0;
        $queryTime = 0.0;
        if ($db instanceof AP_DB) {
            $numQueries = $db->getNumQueries();
            $queryTime = $db->getTotalQueryTime();
        } elseif (function_exists('ap_db')) {
            try {
                $live = ap_db();
                if ($live instanceof AP_DB) {
                    $numQueries = $live->getNumQueries();
                    $queryTime = $live->getTotalQueryTime();
                }
            } catch (Throwable) {
                // ignore
            }
        }
        $fields[] = [
            'label' => 'DB queries (this request so far)',
            'value' => sprintf('%d (%.4f s)', $numQueries, $queryTime),
        ];
        $fields[] = [
            'label' => 'Peak memory (this request)',
            'value' => self::formatBytes((int) memory_get_peak_usage(true)),
        ];

        return $fields;
    }

    private static function opcacheEnabled(): bool
    {
        if (!function_exists('opcache_get_status')) {
            return false;
        }
        try {
            $status = @opcache_get_status(false);
            return is_array($status) && !empty($status['opcache_enabled']);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Parse PHP ini size strings (e.g. 128M, 512K) into bytes. -1 for unlimited.
     */
    private static function parseIniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return -1;
        }
        if (!preg_match('/^(\d+)\s*([KMG])?$/i', $value, $m)) {
            $asInt = (int) $value;

            return $asInt > 0 ? $asInt : 0;
        }
        $n = (int) $m[1];
        $unit = strtoupper($m[2] ?? '');

        return match ($unit) {
            'G' => $n * 1024 * 1024 * 1024,
            'M' => $n * 1024 * 1024,
            'K' => $n * 1024,
            default => $n,
        };
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    private static function infoServer(string $root): array
    {
        $sapi = PHP_SAPI;
        $os = PHP_OS_FAMILY . ' / ' . php_uname('s') . ' ' . php_uname('r');
        $memoryLimit = (string) ini_get('memory_limit');
        $maxUpload = (string) ini_get('upload_max_filesize');
        $maxPost = (string) ini_get('post_max_size');
        $maxExec = (string) ini_get('max_execution_time');
        $serverSoft = (string) ($_SERVER['SERVER_SOFTWARE'] ?? 'unknown');

        $extensions = [];
        foreach (['pdo', 'mbstring', 'json', 'curl', 'fileinfo', 'zip', 'gd', 'imagick', 'intl', 'pdo_mysql', 'pdo_sqlite', 'pdo_pgsql'] as $ext) {
            if (extension_loaded($ext)) {
                $extensions[] = $ext;
            }
        }

        return [
            ['label' => 'PHP version', 'value' => PHP_VERSION],
            ['label' => 'PHP SAPI', 'value' => $sapi],
            ['label' => 'Server software', 'value' => $serverSoft],
            ['label' => 'Operating system', 'value' => $os],
            ['label' => 'memory_limit', 'value' => $memoryLimit],
            ['label' => 'upload_max_filesize', 'value' => $maxUpload],
            ['label' => 'post_max_size', 'value' => $maxPost],
            ['label' => 'max_execution_time', 'value' => $maxExec],
            ['label' => 'Loaded extensions (subset)', 'value' => $extensions !== [] ? implode(', ', $extensions) : '(none listed)'],
            ['label' => 'Document root path', 'value' => $root],
        ];
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    private static function infoDatabase(?AP_DB $db): array
    {
        if ($db === null) {
            return [
                ['label' => 'Driver', 'value' => 'not connected'],
            ];
        }

        $fields = [
            ['label' => 'Driver', 'value' => $db->getDriver()],
            ['label' => 'Table prefix', 'value' => $db->getPrefix()],
        ];

        try {
            if ($db->getDriver() === 'sqlite') {
                $ver = $db->getVar('SELECT sqlite_version()');
                $fields[] = ['label' => 'Server version', 'value' => 'SQLite ' . (string) $ver];
            } elseif ($db->getDriver() === 'pgsql') {
                $ver = $db->getVar('SELECT version()');
                $fields[] = ['label' => 'Server version', 'value' => (string) $ver];
            } else {
                $ver = $db->getVar('SELECT VERSION()');
                $fields[] = ['label' => 'Server version', 'value' => (string) $ver];
            }
        } catch (Throwable) {
            $fields[] = ['label' => 'Server version', 'value' => 'unavailable'];
        }

        return $fields;
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    private static function infoDirectories(string $root): array
    {
        $paths = [
            'Site root' => $root,
            'ap-content' => $root . 'ap-content',
            'uploads' => $root . 'ap-content/uploads',
            'themes' => $root . 'ap-content/themes',
            'plugins' => $root . 'ap-content/plugins',
        ];
        $fields = [];
        foreach ($paths as $label => $path) {
            $exists = is_dir($path) || is_file($path);
            $writable = $exists && is_writable($path);
            $fields[] = [
                'label' => $label,
                'value' => sprintf(
                    '%s — %s%s',
                    $path,
                    $exists ? 'exists' : 'missing',
                    $exists ? ($writable ? ', writable' : ', not writable') : ''
                ),
            ];
        }

        return $fields;
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    private static function infoConstants(): array
    {
        $bools = [
            'AP_DEBUG',
            'AP_DEBUG_DISPLAY',
            'AP_DEBUG_LOG',
            'AP_CACHE',
        ];
        $fields = [];
        foreach ($bools as $name) {
            if (!defined($name)) {
                $fields[] = ['label' => $name, 'value' => 'undefined'];
                continue;
            }
            $val = constant($name);
            $fields[] = [
                'label' => $name,
                'value' => $val ? 'true' : 'false',
            ];
        }

        // Salts: report defined/unique without leaking values.
        $saltOk = 0;
        $saltTotal = 0;
        foreach (self::saltConstants() as $name) {
            $saltTotal++;
            if (!defined($name)) {
                continue;
            }
            $v = (string) constant($name);
            if ($v !== '' && $v !== self::DEFAULT_SALT_PLACEHOLDER) {
                $saltOk++;
            }
        }
        $fields[] = [
            'label' => 'Auth keys/salts',
            'value' => sprintf('%d / %d unique (values redacted)', $saltOk, $saltTotal),
        ];

        return $fields;
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    private static function infoModules(?AP_DB $db): array
    {
        $fields = [];
        foreach (['blog' => 'Blog', 'static_pages' => 'Static Pages', 'forum' => 'Forum'] as $key => $label) {
            $on = true;
            if (class_exists('AP_Options', false) && method_exists('AP_Options', 'isModuleEnabled')) {
                $on = AP_Options::isModuleEnabled($key, $db);
            }
            $fields[] = ['label' => $label . ' module', 'value' => $on ? 'enabled' : 'disabled'];
        }

        $theme = '';
        if (function_exists('ap_get_option')) {
            $theme = (string) ap_get_option('stylesheet', '', $db);
        }
        $fields[] = ['label' => 'Active theme (stylesheet)', 'value' => $theme !== '' ? $theme : '(default)'];

        $plugins = 0;
        if (function_exists('ap_get_option')) {
            $active = ap_get_option('active_plugins', [], $db);
            if (is_array($active)) {
                $plugins = count($active);
            } elseif (is_string($active) && $active !== '') {
                $decoded = json_decode($active, true);
                $plugins = is_array($decoded) ? count($decoded) : 0;
            }
        }
        $fields[] = ['label' => 'Active plugins', 'value' => (string) $plugins];

        $blogPublic = true;
        if (function_exists('ap_get_option')) {
            $blogPublic = (string) ap_get_option('blog_public', '1', $db) !== '0';
        }
        $fields[] = [
            'label' => 'Search engine visibility',
            'value' => $blogPublic ? 'public (indexing allowed)' : 'discouraged (noindex)',
        ];

        return $fields;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @param list<mixed> $checks
     *
     * @return list<array{id: string, label: string, status: string, message: string, badge?: string}>
     */
    private static function normalizeChecks(array $checks): array
    {
        $out = [];
        foreach ($checks as $check) {
            if (!is_array($check)) {
                continue;
            }
            $id = (string) ($check['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $row = [
                'id' => $id,
                'label' => (string) ($check['label'] ?? $id),
                'status' => self::normalizeStatus((string) ($check['status'] ?? self::STATUS_GOOD)),
                'message' => (string) ($check['message'] ?? ''),
            ];
            if (isset($check['badge']) && is_string($check['badge']) && $check['badge'] !== '') {
                $row['badge'] = $check['badge'];
            }
            $out[] = $row;
        }

        return $out;
    }

    public static function normalizeStatus(string $status): string
    {
        $status = strtolower(trim($status));
        if (in_array($status, [self::STATUS_GOOD, self::STATUS_RECOMMENDED, self::STATUS_CRITICAL], true)) {
            return $status;
        }

        return self::STATUS_GOOD;
    }

    /**
     * Human label for a status slug.
     */
    public static function statusLabel(string $status): string
    {
        return match (self::normalizeStatus($status)) {
            self::STATUS_CRITICAL => 'Critical',
            self::STATUS_RECOMMENDED => 'Recommended',
            default => 'Good',
        };
    }

    public static function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        $units = ['KiB', 'MiB', 'GiB', 'TiB'];
        $value = (float) $bytes;
        foreach ($units as $unit) {
            $value /= 1024;
            if ($value < 1024) {
                return round($value, $value >= 10 ? 0 : 1) . ' ' . $unit;
            }
        }

        return round($value, 1) . ' PiB';
    }

    private static function resolveDb(?AP_DB $db): ?AP_DB
    {
        if ($db !== null) {
            return $db;
        }
        if (function_exists('ap_db')) {
            try {
                return ap_db();
            } catch (Throwable) {
                return null;
            }
        }
        if (isset($GLOBALS['apdb']) && $GLOBALS['apdb'] instanceof AP_DB) {
            return $GLOBALS['apdb'];
        }

        return null;
    }

    private static function resolveAbspath(?string $abspath): string
    {
        if ($abspath !== null && $abspath !== '') {
            return rtrim($abspath, "/\\") . '/';
        }
        if (defined('AP_ABSPATH')) {
            return (string) AP_ABSPATH;
        }

        return dirname(__DIR__) . '/';
    }
}
