<?php

/**
 * AgoraPress installer: config generation, migrations, admin seed.
 *
 * Used by the web installer (install/index.php) and CLI path (install/cli.php).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * Orchestrates a fresh site install (tables + ap-config.php + admin user).
 */
class AP_Installer
{
    /** Auth key / salt constant names written into ap-config.php. */
    public const SALT_KEYS = [
        'AP_AUTH_KEY',
        'AP_SECURE_AUTH_KEY',
        'AP_LOGGED_IN_KEY',
        'AP_NONCE_KEY',
        'AP_AUTH_SALT',
        'AP_SECURE_AUTH_SALT',
        'AP_LOGGED_IN_SALT',
        'AP_NONCE_SALT',
    ];

    /**
     * Generate cryptographically strong unique phrases for auth keys/salts.
     *
     * @return array<string, string> Constant name => value
     */
    public static function generateSalts(): array
    {
        $salts = [];
        foreach (self::SALT_KEYS as $key) {
            $salts[$key] = self::randomPhrase(64);
        }

        return $salts;
    }

    /**
     * Random printable phrase suitable for config salts.
     */
    public static function randomPhrase(int $length = 64): string
    {
        $length = max(32, $length);
        try {
            $bytes = random_bytes($length);
        } catch (Throwable) {
            $bytes = hash('sha256', uniqid((string) mt_rand(), true), true)
                . hash('sha256', (string) microtime(true), true);
        }

        // Base64-ish without quotes/backslashes that complicate PHP single-quoted strings.
        $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#%^&*()-_=+[]{}';
        $max = strlen($alphabet) - 1;
        $out = '';
        $len = strlen($bytes);
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[ord($bytes[$i % $len]) % ($max + 1)];
        }

        return $out;
    }

    /**
     * Hash a password with Argon2id when available, otherwise PASSWORD_DEFAULT.
     *
     * Delegates to {@see AP_User::hashPassword()} so installer seed and runtime
     * auth share one implementation.
     */
    public static function hashPassword(string $password): string
    {
        if (!class_exists('AP_User', false)) {
            require_once __DIR__ . '/class-ap-user.php';
        }

        return AP_User::hashPassword($password);
    }

    /**
     * Validate database form fields before connection test.
     *
     * @param array{
     *     driver?: string,
     *     name?: string,
     *     user?: string,
     *     password?: string,
     *     host?: string,
     *     prefix?: string
     * } $db
     *
     * @return list<string> Human-readable errors (empty = ok).
     */
    public static function validateDatabaseInput(array $db): array
    {
        $errors = [];
        $driver = strtolower(trim((string) ($db['driver'] ?? '')));
        $supported = ['mysql', 'sqlite', 'pgsql'];
        if (!in_array($driver, $supported, true)) {
            $errors[] = 'Choose a database driver: mysql, sqlite, or pgsql.';
        }

        $name = trim((string) ($db['name'] ?? ''));
        if ($name === '') {
            $errors[] = $driver === 'sqlite'
                ? 'Provide a path for the SQLite database file.'
                : 'Database name is required.';
        }

        if ($driver !== 'sqlite') {
            $host = trim((string) ($db['host'] ?? ''));
            if ($host === '') {
                $errors[] = 'Database host is required.';
            }
            $user = trim((string) ($db['user'] ?? ''));
            if ($user === '') {
                $errors[] = 'Database username is required.';
            }
        }

        $prefix = (string) ($db['prefix'] ?? 'ap_');
        $normalized = self::normalizePrefix($prefix);
        if ($normalized === '') {
            $errors[] = 'Table prefix is invalid.';
        }

        return $errors;
    }

    /**
     * Validate site + admin account fields.
     *
     * @param array{title?: string, url?: string, email?: string} $site
     * @param array{username?: string, email?: string, password?: string, password_confirm?: string} $admin
     *
     * @return list<string>
     */
    public static function validateSiteAndAdmin(array $site, array $admin): array
    {
        $errors = [];

        $title = trim((string) ($site['title'] ?? ''));
        if ($title === '') {
            $errors[] = 'Site title is required.';
        } elseif (mb_strlen($title) > 200) {
            $errors[] = 'Site title is too long (max 200 characters).';
        }

        $url = trim((string) ($site['url'] ?? ''));
        if ($url === '') {
            $errors[] = 'Site URL is required.';
        } elseif (filter_var($url, FILTER_VALIDATE_URL) === false) {
            $errors[] = 'Site URL must be a valid URL (e.g. https://example.com).';
        }

        $adminEmail = trim((string) ($admin['email'] ?? ($site['email'] ?? '')));
        if ($adminEmail === '' || filter_var($adminEmail, FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = 'A valid administrator email address is required.';
        }

        $username = trim((string) ($admin['username'] ?? ''));
        if ($username === '') {
            $errors[] = 'Administrator username is required.';
        } elseif (!preg_match('/^[A-Za-z0-9_\.\-]{3,60}$/', $username)) {
            $errors[] = 'Username must be 3–60 characters: letters, numbers, underscore, dot, or hyphen.';
        }

        $password = (string) ($admin['password'] ?? '');
        if (strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        }
        // Confirmation is required only when the key is present (web form).
        if (array_key_exists('password_confirm', $admin)) {
            $confirm = (string) $admin['password_confirm'];
            if ($password !== $confirm) {
                $errors[] = 'Password confirmation does not match.';
            }
        }

        return $errors;
    }

    /**
     * Attempt a database connection; returns null on success or an error string.
     *
     * @param array{
     *     driver: string,
     *     name: string,
     *     user?: string,
     *     password?: string,
     *     host?: string,
     *     charset?: string
     * } $db
     */
    public static function testConnection(array $db): ?string
    {
        try {
            self::connect($db);

            return null;
        } catch (Throwable $e) {
            return $e->getMessage();
        }
    }

    /**
     * Open an AP_DB connection from installer form data (does not use ap-config).
     *
     * @param array{
     *     driver: string,
     *     name: string,
     *     user?: string,
     *     password?: string,
     *     host?: string,
     *     charset?: string,
     *     prefix?: string
     * } $db
     *
     * @throws AP_DB_Exception
     */
    public static function connect(array $db): AP_DB
    {
        if (!class_exists('AP_DB', false)) {
            require_once __DIR__ . '/class-ap-db.php';
        }

        $driver = strtolower(trim((string) ($db['driver'] ?? 'mysql')));
        $name = (string) ($db['name'] ?? '');
        $user = (string) ($db['user'] ?? '');
        $password = (string) ($db['password'] ?? '');
        $host = (string) ($db['host'] ?? 'localhost');
        $charset = (string) ($db['charset'] ?? 'utf8mb4');
        $prefix = self::normalizePrefix((string) ($db['prefix'] ?? 'ap_'));

        if ($driver === 'sqlite') {
            $dir = dirname($name);
            if ($name !== '' && $dir !== '.' && $dir !== '' && !is_dir($dir)) {
                if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
                    throw new AP_DB_Exception('Cannot create SQLite directory: ' . $dir);
                }
            }
        }

        $pdo = AP_DB::createPdo($driver, $name, $user, $password, $host, $charset);

        return AP_DB::fromPdo($pdo, $driver, $prefix);
    }

    /**
     * Build ap-config.php PHP source from settings + salts.
     *
     * @param array{
     *     driver: string,
     *     name: string,
     *     user?: string,
     *     password?: string,
     *     host?: string,
     *     charset?: string,
     *     collate?: string,
     *     prefix?: string
     * } $db
     * @param array<string, string>|null $salts Constant => value; generated when null.
     */
    public static function generateConfigPhp(array $db, ?array $salts = null): string
    {
        $salts ??= self::generateSalts();
        $driver = strtolower(trim((string) ($db['driver'] ?? 'mysql')));
        $name = (string) ($db['name'] ?? '');
        $user = (string) ($db['user'] ?? '');
        $password = (string) ($db['password'] ?? '');
        $host = (string) ($db['host'] ?? 'localhost');
        $charset = (string) ($db['charset'] ?? 'utf8mb4');
        $collate = (string) ($db['collate'] ?? 'utf8mb4_unicode_ci');
        $prefix = self::normalizePrefix((string) ($db['prefix'] ?? 'ap_'));

        $e = static fn(string $v): string => var_export($v, true);

        $saltLines = '';
        foreach (self::SALT_KEYS as $key) {
            $value = $salts[$key] ?? self::randomPhrase(64);
            $saltLines .= "define('{$key}', " . $e($value) . ");\n";
        }

        return <<<PHP
<?php

/**
 * AgoraPress site configuration.
 *
 * Generated by the installer. Do not commit this file.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

// =============================================================================
// Database settings
// =============================================================================

define('AP_DB_DRIVER', {$e($driver)});
define('AP_DB_NAME', {$e($name)});
define('AP_DB_USER', {$e($user)});
define('AP_DB_PASSWORD', {$e($password)});
define('AP_DB_HOST', {$e($host)});
define('AP_DB_CHARSET', {$e($charset)});
define('AP_DB_COLLATE', {$e($collate)});

/**
 * Database table prefix.
 *
 * @var string
 */
\$table_prefix = {$e($prefix)};

// =============================================================================
// Authentication keys and salts
// =============================================================================

{$saltLines}
// =============================================================================
// Debugging (leave off on production)
// =============================================================================

define('AP_DEBUG', false);
define('AP_DEBUG_DISPLAY', false);
define('AP_DEBUG_LOG', false);

// =============================================================================
// Cache & optional paths
// =============================================================================

// Full-page cache drop-in (ap-content/advanced-cache.php) when true.
define('AP_CACHE', false);

// =============================================================================
// Absolute path — do not edit below this line
// =============================================================================

if (!defined('AP_ABSPATH')) {
    define('AP_ABSPATH', __DIR__ . '/');
}

PHP;
    }

    /**
     * Whether a site config file already exists at the given path.
     *
     * Presence of a readable `ap-config.php` is the primary “already installed”
     * signal. Callers must not overwrite an existing config.
     */
    public static function configExists(string $path): bool
    {
        return $path !== '' && is_readable($path);
    }

    /**
     * Human-readable refusal when reinstall is blocked by an existing config.
     */
    public static function alreadyInstalledMessage(string $path = 'ap-config.php'): string
    {
        $name = basename($path) !== '' ? basename($path) : 'ap-config.php';

        return $name . ' already exists. Remove it only if you intend to reinstall.';
    }

    /**
     * Write config contents atomically when possible.
     *
     * Refuses to overwrite an existing file (defense in depth for the installer).
     *
     * @return true|string True on success, error message on failure.
     */
    public static function writeConfigFile(string $path, string $contents): true|string
    {
        if (self::configExists($path) || is_file($path)) {
            return self::alreadyInstalledMessage($path);
        }

        $dir = dirname($path);
        if (!is_dir($dir) || !is_writable($dir)) {
            return 'Configuration directory is not writable: ' . $dir;
        }

        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
        $written = @file_put_contents($tmp, $contents, LOCK_EX);
        if ($written === false) {
            @unlink($tmp);
            // Fallback: direct write only when the target still does not exist.
            if (is_file($path)) {
                return self::alreadyInstalledMessage($path);
            }
            $written = @file_put_contents($path, $contents, LOCK_EX);
            if ($written === false) {
                return 'Could not write configuration file: ' . $path;
            }

            return true;
        }

        if (is_file($path)) {
            @unlink($tmp);

            return self::alreadyInstalledMessage($path);
        }

        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            if (is_file($path)) {
                return self::alreadyInstalledMessage($path);
            }
            $written = @file_put_contents($path, $contents, LOCK_EX);
            if ($written === false) {
                return 'Could not finalize configuration file: ' . $path;
            }
        }

        return true;
    }

    /**
     * Full install: migrate schema, seed options + admin, write ap-config.php.
     *
     * @param array{
     *     driver: string,
     *     name: string,
     *     user?: string,
     *     password?: string,
     *     host?: string,
     *     charset?: string,
     *     collate?: string,
     *     prefix?: string
     * } $db
     * @param array{title: string, url: string, email?: string} $site
     * @param array{username: string, email: string, password: string} $admin
     * @param string $configPath Absolute path for ap-config.php
     * @param array{sample_content?: bool} $options Install options (optional sample content).
     *
     * @return array{
     *     ok: bool,
     *     errors: list<string>,
     *     migrations: list<array{version: int, description: string}>,
     *     admin_id: int|null,
     *     config_path: string,
     *     config_written: bool,
     *     config_php: string|null,
     *     sample_content: array<string, mixed>|null
     * }
     */
    public static function run(
        array $db,
        array $site,
        array $admin,
        string $configPath,
        array $options = []
    ): array {
        $result = [
            'ok' => false,
            'errors' => [],
            'migrations' => [],
            'admin_id' => null,
            'config_path' => $configPath,
            'config_written' => false,
            'config_php' => null,
            'sample_content' => null,
        ];

        $errors = array_merge(
            self::validateDatabaseInput($db),
            self::validateSiteAndAdmin($site, $admin)
        );
        if ($errors !== []) {
            $result['errors'] = $errors;

            return $result;
        }

        if (self::configExists($configPath) || is_file($configPath)) {
            $result['errors'][] = self::alreadyInstalledMessage($configPath);

            return $result;
        }

        try {
            $connection = self::connect($db);
        } catch (Throwable $e) {
            $result['errors'][] = 'Database connection failed: ' . $e->getMessage();

            return $result;
        }

        if (!class_exists('AP_Migrator', false)) {
            require_once __DIR__ . '/class-ap-migrator.php';
        }

        try {
            $migrator = new AP_Migrator($connection);
            $result['migrations'] = $migrator->migrate();
        } catch (Throwable $e) {
            $result['errors'][] = 'Schema migration failed: ' . $e->getMessage();

            return $result;
        }

        try {
            self::seedOptions($connection, $site, $admin);
            $result['admin_id'] = self::seedAdminUser($connection, $admin, $site);
            // Default Uncategorized category (taxonomy tables from migration 0003+).
            if (!class_exists('AP_Taxonomy', false)) {
                require_once __DIR__ . '/class-ap-post.php';
                require_once __DIR__ . '/class-ap-taxonomy.php';
            }
            AP_Taxonomy::ensureBuiltins();
            AP_Taxonomy::ensureDefaultCategory($connection);
        } catch (Throwable $e) {
            $result['errors'][] = 'Could not create initial site data: ' . $e->getMessage();

            return $result;
        }

        // Optional sample content (FEATURES: optional sample content). Failures are
        // non-fatal: core install still succeeds; errors are recorded for the UI.
        $wantSample = !empty($options['sample_content']);
        if ($wantSample) {
            if (!class_exists('AP_Sample_Content', false)) {
                require_once __DIR__ . '/class-ap-sample-content.php';
            }
            try {
                $sample = AP_Sample_Content::seed($connection, [
                    'author_id' => (int) ($result['admin_id'] ?? 0),
                    'site_title' => (string) ($site['title'] ?? ''),
                ]);
                $result['sample_content'] = $sample;
                // Sample failures are non-fatal: keep details on sample_content only.
            } catch (Throwable $e) {
                $result['sample_content'] = [
                    'ok' => false,
                    'skipped' => false,
                    'posts' => [],
                    'pages' => [],
                    'comments' => [],
                    'forums' => [],
                    'topics' => [],
                    'tags' => [],
                    'errors' => ['Sample content failed: ' . $e->getMessage()],
                ];
            }
        }

        $salts = self::generateSalts();
        $configPhp = self::generateConfigPhp($db, $salts);
        $result['config_php'] = $configPhp;

        $write = self::writeConfigFile($configPath, $configPhp);
        if ($write !== true) {
            $result['errors'][] = $write;
            $result['errors'][] = 'Tables were created, but config was not written. '
                . 'Copy the generated configuration manually to ap-config.php.';

            return $result;
        }

        $result['config_written'] = true;
        $result['ok'] = true;

        return $result;
    }

    /**
     * Seed core options (blogname, siteurl, home, admin_email, etc.).
     *
     * @param array{title: string, url: string, email?: string} $site
     * @param array{username: string, email: string, password: string} $admin
     */
    public static function seedOptions(AP_DB $db, array $site, array $admin): void
    {
        $title = trim((string) ($site['title'] ?? 'AgoraPress'));
        $url = rtrim(trim((string) ($site['url'] ?? '')), '/');
        $email = trim((string) ($admin['email'] ?? ($site['email'] ?? '')));
        $dbVersion = defined('AP_DB_VERSION') ? (string) AP_DB_VERSION : '1';
        $version = defined('AP_VERSION') ? (string) AP_VERSION : '0.1.0-dev';

        $options = [
            'blogname' => $title,
            'blogdescription' => '',
            'siteurl' => $url,
            'home' => $url,
            'admin_email' => $email,
            'users_can_register' => '0',
            'require_email_verification' => '1',
            // Optional registration CAPTCHA: off|math (disableable; off by default).
            'registration_captcha' => 'off',
            'default_role' => 'subscriber',
            'ap_db_version' => $dbVersion,
            'ap_version' => $version,
            // Module toggles (independent; all on for a full install).
            'ap_module_static_pages' => '1',
            'ap_module_blog' => '1',
            'ap_module_forum' => '1',
            'WPLANG' => '',
            'timezone_string' => 'UTC',
            'date_format' => 'Y-m-d',
            'time_format' => 'H:i',
            'start_of_week' => '1',
            // Empty structure = plain ?p= / ?page_id= links (pretty permalinks optional).
            'permalink_structure' => '',
            'category_base' => '',
            'tag_base' => '',
            'rewrite_rules' => '',
            // Default theme (Agora) — stylesheet may become a child later.
            'stylesheet' => 'agora',
            'template' => 'agora',
            // Active plugins (JSON list of basenames; empty on fresh install).
            'active_plugins' => '[]',
            // Agora color scheme: marble|parchment|cloud|obsidian|midnight|charcoal.
            'agora_color_scheme' => 'marble',
            // Reading / front-page settings (options-reading.php).
            'show_on_front' => 'posts',
            'page_on_front' => '0',
            'page_for_posts' => '0',
            'posts_per_page' => '10',
            'posts_per_rss' => '10',
            'rss_use_excerpt' => '0',
            // SEO: allow indexing, XML sitemaps, Open Graph (FEATURES “SEO basics”).
            'blog_public' => '1',
            'sitemap_enabled' => '1',
            'open_graph_enabled' => '1',
            // Writing settings.
            'default_category' => '0',
            'use_smilies' => '1',
            'default_comment_status' => 'open',
            // Discussion settings.
            'require_name_email' => '1',
            'comment_moderation' => '0',
            'comment_registration' => '0',
            'close_comments_for_old_posts' => '0',
            'close_comments_days_old' => '14',
            'thread_comments' => '1',
            'thread_comments_depth' => '5',
            // Media settings.
            'thumbnail_size_w' => '150',
            'thumbnail_size_h' => '150',
            'thumbnail_crop' => '1',
            'medium_size_w' => '300',
            'medium_size_h' => '300',
            'large_size_w' => '1024',
            'large_size_h' => '1024',
            'uploads_use_yearmonth_folders' => '1',
            // Navigation menus (empty until admin creates them).
            'ap_nav_menus' => '',
            'nav_menu_locations' => '',
            // Widgets / modular areas (empty placements until admin assigns).
            'sidebars_widgets' => '',
            // Avatars (Discussion settings).
            'show_avatars' => '1',
            'avatar_default' => 'mystery',
            'avatar_rating' => 'g',
            // Forum attachments (Settings → Forums; FEATURES “Attachments with quotas”).
            'forum_attachments_enabled' => '1',
            'forum_attachment_max_size' => '2097152',
            'forum_attachment_allowed_types' => 'jpg,jpeg,png,gif,webp,pdf,txt,zip',
            'forum_attachment_max_per_post' => '5',
            'forum_attachment_user_quota' => '10485760',
            // Private messaging (Settings → Forums; FEATURES “Private Messaging”).
            'forum_private_messaging_enabled' => '1',
            // Who’s online + unread tracking (FEATURES “Who’s online, unread tracking”).
            'forum_online_enabled' => '1',
            'forum_online_window' => '900',
            'forum_unread_tracking_enabled' => '1',
            // Flood control, anti-spam, approval queues, search (FEATURES Phase 5).
            'forum_flood_interval' => '30',
            'forum_posts_require_approval' => '0',
            'forum_spam_blacklist' => '',
            'forum_spam_max_links' => '5',
            'forum_search_enabled' => '1',
            // Forum display / guest defaults (Settings → Forums).
            'forum_topics_per_page' => '20',
            'forum_posts_per_page' => '15',
            'forum_allow_guest_viewing' => '1',
            'forum_allow_guest_posting' => '0',
            // Hall of Fame: never auto-joined. Installer does not ping or
            // register domains (no telemetry). Admin-footer donation link is
            // permanent/non-optional and is not controlled by an option.
            'hall_of_fame_status' => '',
            'hall_of_fame_domain' => '',
            'hall_of_fame_token' => '',
            'hall_of_fame_joined_at' => '',
            'hall_of_fame_dismissed' => '0',
            // Version check: admin-only, cached GET of public version.json (no site id).
            'version_check_enabled' => '1',
            // Lightweight REST API (public JSON at /ap-json/; disable via option).
            'rest_api_enabled' => '1',
            // Rate limiting / login protection (AP_Rate_Limit; overridable per action).
            'rate_limit_login_max' => '5',
            'rate_limit_login_window' => '900',
            'rate_limit_login_lockout' => '900',
            'rate_limit_register_max' => '5',
            'rate_limit_register_window' => '3600',
            'rate_limit_register_lockout' => '3600',
            'rate_limit_password_reset_max' => '5',
            'rate_limit_password_reset_window' => '3600',
            'rate_limit_password_reset_lockout' => '1800',
            'rate_limit_upload_max' => '40',
            'rate_limit_upload_window' => '600',
            'rate_limit_upload_lockout' => '300',
        ];

        foreach ($options as $name => $value) {
            self::upsertOption($db, $name, (string) $value);
        }

        // Seed the roles map (administrator, editor, author, contributor, subscriber).
        if (!class_exists('AP_Roles', false)) {
            require_once __DIR__ . '/class-ap-roles.php';
        }
        AP_Roles::flushCache();
        AP_Roles::ensureDefaults($db);

        // Forum system groups + global default ACL (idempotent; safe if tables missing).
        if (!class_exists('AP_Group', false)) {
            $groupFile = __DIR__ . '/class-ap-group.php';
            if (is_readable($groupFile)) {
                require_once $groupFile;
            }
        }
        if (!class_exists('AP_Forum_Permissions', false)) {
            $permFile = __DIR__ . '/class-ap-forum-permissions.php';
            if (is_readable($permFile)) {
                require_once $permFile;
            }
        }
        if (class_exists('AP_Group', false) && class_exists('AP_Forum_Permissions', false)) {
            try {
                AP_Group::ensureSystemGroups($db);
                AP_Forum_Permissions::ensureDefaults($db);
            } catch (Throwable) {
                // Schema may not include forum tables yet on partial installs.
            }
        }
    }

    /**
     * Create the first administrator when no users exist.
     *
     * @param array{username: string, email: string, password: string} $admin
     * @param array{title?: string, url?: string} $site
     *
     * @return int New user ID (0 if skipped because users already exist).
     */
    public static function seedAdminUser(AP_DB $db, array $admin, array $site = []): int
    {
        $usersTable = $db->quoteIdentifier($db->table('users'));
        $count = (int) $db->getVar('SELECT COUNT(*) FROM ' . $usersTable);
        if ($count > 0) {
            return 0;
        }

        $login = trim((string) ($admin['username'] ?? 'admin'));
        $email = trim((string) ($admin['email'] ?? ''));
        $password = (string) ($admin['password'] ?? '');
        $hash = self::hashPassword($password);
        $nicename = strtolower(preg_replace('/[^A-Za-z0-9\-]+/', '-', $login) ?? $login);
        $nicename = trim($nicename, '-') ?: 'admin';
        $display = $login;
        $registered = gmdate('Y-m-d H:i:s');

        $ok = $db->insert('users', [
            'user_login' => $login,
            'user_pass' => $hash,
            'user_nicename' => $nicename,
            'user_email' => $email,
            'user_url' => '',
            'user_registered' => $registered,
            'user_activation_key' => '',
            'user_status' => 0,
            'display_name' => $display,
        ]);

        if ($ok === false) {
            throw new RuntimeException(
                'Failed to insert admin user: ' . ($db->lastError() ?? 'unknown error')
            );
        }

        $userId = (int) $db->lastInsertId();
        if ($userId < 1) {
            throw new RuntimeException('Admin user insert did not return an ID.');
        }

        // Assign administrator role via the roles API (ap_capabilities + ap_user_level).
        if (!class_exists('AP_Roles', false)) {
            require_once __DIR__ . '/class-ap-roles.php';
        }
        AP_Roles::ensureDefaults($db);
        AP_Roles::setUserRole($userId, 'administrator', $db);

        $db->insert('usermeta', [
            'user_id' => $userId,
            'meta_key' => 'nickname',
            'meta_value' => $display,
        ]);

        return $userId;
    }

    /**
     * Insert or update an option by name.
     */
    public static function upsertOption(AP_DB $db, string $name, string $value, string $autoload = 'yes'): void
    {
        $table = $db->quoteIdentifier($db->table('options'));
        $existing = $db->getVar(
            'SELECT option_id FROM ' . $table . ' WHERE option_name = ?',
            [$name]
        );

        if ($existing !== null && $existing !== '') {
            $updated = $db->update(
                'options',
                ['option_value' => $value, 'autoload' => $autoload],
                ['option_name' => $name]
            );
            if ($updated === false) {
                throw new RuntimeException(
                    "Failed to update option {$name}: " . ($db->lastError() ?? '')
                );
            }

            return;
        }

        $inserted = $db->insert('options', [
            'option_name' => $name,
            'option_value' => $value,
            'autoload' => $autoload,
        ]);
        if ($inserted === false) {
            throw new RuntimeException(
                "Failed to insert option {$name}: " . ($db->lastError() ?? '')
            );
        }
    }

    /**
     * Normalize table prefix using shared helper when available.
     */
    public static function normalizePrefix(string $prefix): string
    {
        if (function_exists('ap_normalize_table_prefix')) {
            return ap_normalize_table_prefix($prefix);
        }
        if (class_exists('AP_DB', false)) {
            return AP_DB::normalizePrefix($prefix);
        }

        $clean = preg_replace('/[^A-Za-z0-9_]/', '', trim($prefix));
        if (!is_string($clean) || $clean === '') {
            return 'ap_';
        }
        if (preg_match('/^[0-9]/', $clean) === 1) {
            $clean = 'ap_' . $clean;
        }

        return $clean;
    }

    /**
     * Guess public site URL from the current HTTP request (installer UI).
     */
    public static function guessSiteUrl(): string
    {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['SERVER_PORT']) && (string) $_SERVER['SERVER_PORT'] === '443')
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO'])
                && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');

        $scheme = $https ? 'https' : 'http';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost');
        $host = preg_replace('/[^A-Za-z0-9\.\-\:\[\]]/', '', $host) ?? 'localhost';

        // Strip /install or /install/index.php from script path.
        $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
        $dir = str_replace('\\', '/', dirname($script));
        if (str_ends_with($dir, '/install')) {
            $dir = substr($dir, 0, -strlen('/install'));
        }
        $dir = rtrim($dir, '/');

        return $scheme . '://' . $host . $dir;
    }

    /**
     * Default SQLite path under ap-content for zero-config installs.
     */
    public static function defaultSqlitePath(?string $abspath = null): string
    {
        $root = $abspath ?? (defined('AP_ABSPATH') ? (string) AP_ABSPATH : dirname(__DIR__) . '/');
        $root = rtrim($root, "/\\") . '/';

        return $root . 'ap-content/database.sqlite';
    }
}
