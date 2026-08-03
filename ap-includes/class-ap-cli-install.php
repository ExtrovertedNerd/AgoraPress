<?php

/**
 * AgoraPress CLI installer: non-interactive install path.
 *
 * Invoked via install/cli.php. Shares AP_Installer / AP_Requirements with
 * the web installer so schema, salts, and admin seed stay identical.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * Parses CLI flags and runs a full site install.
 */
class AP_Cli_Install
{
    /** Exit code: success. */
    public const EXIT_OK = 0;

    /** Exit code: usage / validation error. */
    public const EXIT_USAGE = 1;

    /** Exit code: requirements failed. */
    public const EXIT_REQUIREMENTS = 2;

    /** Exit code: install failed (DB, migrate, config write). */
    public const EXIT_INSTALL = 3;

    /**
     * Long option names accepted by the CLI (without leading --).
     *
     * @var list<string>
     */
    public const KNOWN_OPTIONS = [
        'help',
        'db-driver',
        'db-name',
        'db-user',
        'db-password',
        'db-host',
        'db-charset',
        'table-prefix',
        'site-title',
        'site-url',
        'admin-user',
        'admin-email',
        'admin-password',
        'config-path',
        'skip-requirements',
    ];

    /**
     * Human-readable usage text for --help / stderr.
     */
    public static function usage(string $script = 'install/cli.php'): string
    {
        return <<<TXT
AgoraPress CLI installer

Usage:
  php {$script} [options]

Required (unless noted):
  --site-title=TITLE       Site title
  --site-url=URL           Public site URL (e.g. https://example.com)
  --admin-user=USER        Administrator username (3–60 chars)
  --admin-email=EMAIL      Administrator email
  --admin-password=PASS    Administrator password (min 8 chars)
                           (or set AP_ADMIN_PASSWORD in the environment)

Database:
  --db-driver=DRIVER       mysql | sqlite | pgsql  (default: mysql)
  --db-name=NAME           Database name, or SQLite file path
                           (sqlite default: ap-content/database.sqlite)
  --db-user=USER           Database user (mysql/pgsql)
  --db-password=PASS       Database password (or AP_DB_PASSWORD env)
  --db-host=HOST           Database host (default: localhost)
  --db-charset=CHARSET     Default utf8mb4
  --table-prefix=PREFIX    Default ap_

Other:
  --config-path=PATH       Write ap-config.php here (default: site root)
  --skip-requirements      Skip PHP/extension/filesystem checks
  -h, --help               Show this help

Examples:
  # Zero-config SQLite local demo
  php {$script} \\
    --db-driver=sqlite \\
    --site-title="Demo" --site-url=http://localhost:8080 \\
    --admin-user=admin --admin-email=admin@example.com \\
    --admin-password=changeme123

  # MySQL (Docker Compose service host "db")
  php {$script} \\
    --db-driver=mysql --db-host=db --db-name=agorapress \\
    --db-user=agorapress --db-password=agorapress \\
    --site-title="My Site" --site-url=http://localhost:8080 \\
    --admin-user=admin --admin-email=admin@example.com \\
    --admin-password=changeme123

Exit codes: 0=ok, 1=usage, 2=requirements, 3=install failure.

TXT;
    }

    /**
     * Parse argv into structured options.
     *
     * @param list<string> $argv Full argv including script name at [0].
     *
     * @return array{
     *     ok: bool,
     *     help: bool,
     *     errors: list<string>,
     *     options: array{
     *         db_driver: string,
     *         db_name: string,
     *         db_user: string,
     *         db_password: string,
     *         db_host: string,
     *         db_charset: string,
     *         table_prefix: string,
     *         site_title: string,
     *         site_url: string,
     *         admin_user: string,
     *         admin_email: string,
     *         admin_password: string,
     *         config_path: string,
     *         skip_requirements: bool
     *     }
     * }
     */
    public static function parseArgv(array $argv, ?string $abspath = null): array
    {
        $root = self::resolveAbspath($abspath);
        $errors = [];
        $help = false;
        $raw = [
            'db-driver' => 'mysql',
            'db-name' => '',
            'db-user' => '',
            'db-password' => (string) (getenv('AP_DB_PASSWORD') ?: ''),
            'db-host' => 'localhost',
            'db-charset' => 'utf8mb4',
            'table-prefix' => 'ap_',
            'site-title' => '',
            'site-url' => '',
            'admin-user' => '',
            'admin-email' => '',
            'admin-password' => (string) (getenv('AP_ADMIN_PASSWORD') ?: ''),
            'config-path' => $root . 'ap-config.php',
            'skip-requirements' => false,
        ];

        // Skip script name.
        $args = array_values(array_slice($argv, 1));
        $i = 0;
        $n = count($args);
        while ($i < $n) {
            $arg = $args[$i];
            if ($arg === '--') {
                break;
            }
            if ($arg === '-h' || $arg === '--help' || $arg === 'help') {
                $help = true;
                $i++;
                continue;
            }
            if ($arg === '--skip-requirements') {
                $raw['skip-requirements'] = true;
                $i++;
                continue;
            }
            if (str_starts_with($arg, '--')) {
                $eq = strpos($arg, '=');
                if ($eq !== false) {
                    $name = substr($arg, 2, $eq - 2);
                    $value = substr($arg, $eq + 1);
                } else {
                    $name = substr($arg, 2);
                    // Flag or next token as value.
                    if ($name === 'skip-requirements') {
                        $raw['skip-requirements'] = true;
                        $i++;
                        continue;
                    }
                    if ($i + 1 >= $n || str_starts_with((string) $args[$i + 1], '-')) {
                        $errors[] = "Option --{$name} requires a value.";
                        $i++;
                        continue;
                    }
                    $value = (string) $args[$i + 1];
                    $i += 2;
                    if (!in_array($name, self::KNOWN_OPTIONS, true)) {
                        $errors[] = "Unknown option: --{$name}";
                        continue;
                    }
                    if ($name === 'help') {
                        $help = true;
                        continue;
                    }
                    $raw[$name] = $value;
                    continue;
                }

                if (!in_array($name, self::KNOWN_OPTIONS, true)) {
                    $errors[] = "Unknown option: --{$name}";
                    $i++;
                    continue;
                }
                if ($name === 'help') {
                    $help = true;
                    $i++;
                    continue;
                }
                if ($name === 'skip-requirements') {
                    $raw['skip-requirements'] = true;
                    $i++;
                    continue;
                }
                $raw[$name] = $value;
                $i++;
                continue;
            }

            $errors[] = "Unexpected argument: {$arg}";
            $i++;
        }

        // SQLite default path when driver is sqlite and name empty.
        $driver = strtolower(trim((string) $raw['db-driver']));
        $dbName = trim((string) $raw['db-name']);
        if ($driver === 'sqlite' && $dbName === '') {
            if (!class_exists('AP_Installer', false)) {
                require_once __DIR__ . '/class-ap-installer.php';
            }
            $dbName = AP_Installer::defaultSqlitePath($root);
        }

        $options = [
            'db_driver' => $driver,
            'db_name' => $dbName,
            'db_user' => (string) $raw['db-user'],
            'db_password' => (string) $raw['db-password'],
            'db_host' => (string) $raw['db-host'],
            'db_charset' => (string) $raw['db-charset'],
            'table_prefix' => (string) $raw['table-prefix'],
            'site_title' => trim((string) $raw['site-title']),
            'site_url' => trim((string) $raw['site-url']),
            'admin_user' => trim((string) $raw['admin-user']),
            'admin_email' => trim((string) $raw['admin-email']),
            'admin_password' => (string) $raw['admin-password'],
            'config_path' => (string) $raw['config-path'],
            'skip_requirements' => (bool) $raw['skip-requirements'],
        ];

        if ($help) {
            return [
                'ok' => true,
                'help' => true,
                'errors' => [],
                'options' => $options,
            ];
        }

        // CLI-level required flags (installer validates formats in detail).
        $required = [
            'site_title' => '--site-title',
            'site_url' => '--site-url',
            'admin_user' => '--admin-user',
            'admin_email' => '--admin-email',
            'admin_password' => '--admin-password (or AP_ADMIN_PASSWORD)',
        ];
        foreach ($required as $key => $flag) {
            if ($options[$key] === '') {
                $errors[] = "Missing required option: {$flag}";
            }
        }
        if ($options['db_name'] === '') {
            $errors[] = 'Missing required option: --db-name'
                . ($driver === 'sqlite' ? '' : ' (database name)');
        }

        return [
            'ok' => $errors === [],
            'help' => false,
            'errors' => $errors,
            'options' => $options,
        ];
    }

    /**
     * Run install from parsed options. Writes progress to $stdout / $stderr.
     *
     * @param array{
     *     db_driver: string,
     *     db_name: string,
     *     db_user: string,
     *     db_password: string,
     *     db_host: string,
     *     db_charset: string,
     *     table_prefix: string,
     *     site_title: string,
     *     site_url: string,
     *     admin_user: string,
     *     admin_email: string,
     *     admin_password: string,
     *     config_path: string,
     *     skip_requirements: bool
     * } $options
     * @param callable(string): void|null $stdout
     * @param callable(string): void|null $stderr
     * @param string|null $abspath Project root with trailing slash.
     */
    public static function execute(
        array $options,
        ?callable $stdout = null,
        ?callable $stderr = null,
        ?string $abspath = null
    ): int {
        $out = $stdout ?? static function (string $line): void {
            fwrite(STDOUT, $line . "\n");
        };
        $err = $stderr ?? static function (string $line): void {
            fwrite(STDERR, $line . "\n");
        };

        $root = self::resolveAbspath($abspath);

        self::ensureDependencies();

        $version = defined('AP_VERSION') ? (string) AP_VERSION : 'dev';
        $out('AgoraPress CLI installer ' . $version);

        // Fail fast when a config already exists (before requirements / DB work).
        $configPathEarly = (string) ($options['config_path'] ?? '');
        if ($configPathEarly === '') {
            $configPathEarly = $root . 'ap-config.php';
        }
        if (AP_Installer::configExists($configPathEarly) || is_file($configPathEarly)) {
            $err('Error: ' . AP_Installer::alreadyInstalledMessage($configPathEarly));
            $err('Remove the existing configuration only if you intend to reinstall.');

            return self::EXIT_INSTALL;
        }

        if (empty($options['skip_requirements'])) {
            $out('Checking requirements…');
            $checks = AP_Requirements::check($root);
            foreach ($checks as $check) {
                $mark = $check['ok'] ? 'OK' : ($check['required'] ? 'FAIL' : 'WARN');
                $line = "  [{$mark}] {$check['label']}: {$check['message']}";
                if ($check['ok']) {
                    $out($line);
                } elseif ($check['required']) {
                    $err($line);
                } else {
                    $out($line);
                }
            }
            if (!AP_Requirements::allRequiredPassed($checks)) {
                $err(
                    'Requirements check failed. Fix the FAIL items above,'
                    . ' or pass --skip-requirements if you know what you are doing.'
                );

                return self::EXIT_REQUIREMENTS;
            }
            $out('Requirements OK.');
        } else {
            $out('Skipping requirements check (--skip-requirements).');
        }

        $db = [
            'driver' => (string) $options['db_driver'],
            'name' => (string) $options['db_name'],
            'user' => (string) $options['db_user'],
            'password' => (string) $options['db_password'],
            'host' => (string) $options['db_host'],
            'charset' => (string) ($options['db_charset'] ?: 'utf8mb4'),
            'collate' => 'utf8mb4_unicode_ci',
            'prefix' => (string) $options['table_prefix'],
        ];
        $site = [
            'title' => (string) $options['site_title'],
            'url' => (string) $options['site_url'],
            'email' => (string) $options['admin_email'],
        ];
        $admin = [
            'username' => (string) $options['admin_user'],
            'email' => (string) $options['admin_email'],
            'password' => (string) $options['admin_password'],
        ];
        $configPath = (string) $options['config_path'];
        if ($configPath === '') {
            $configPath = $root . 'ap-config.php';
        }

        $out('Connecting and installing…');
        $out('  Driver:  ' . $db['driver']);
        $out('  Prefix:  ' . AP_Installer::normalizePrefix((string) $db['prefix']));
        $out('  Config:  ' . $configPath);

        $result = AP_Installer::run($db, $site, $admin, $configPath);

        if (!$result['ok']) {
            foreach ($result['errors'] as $message) {
                $err('Error: ' . $message);
            }
            if (!empty($result['config_php']) && empty($result['config_written'])) {
                $err('Generated configuration follows (save as ap-config.php):');
                $err('-----BEGIN AP-CONFIG-----');
                $err((string) $result['config_php']);
                $err('-----END AP-CONFIG-----');
            }

            return self::EXIT_INSTALL;
        }

        $migCount = count($result['migrations']);
        $out("Migrations applied: {$migCount}");
        foreach ($result['migrations'] as $mig) {
            $ver = (int) ($mig['version'] ?? 0);
            $desc = (string) ($mig['description'] ?? '');
            $out("  - {$ver}: {$desc}");
        }
        if (!empty($result['admin_id'])) {
            $out('Administrator user ID: ' . (int) $result['admin_id']);
        }
        $out('Wrote configuration: ' . $configPath);
        $out('Installation complete.');
        $out('Open ' . rtrim((string) $site['url'], '/') . '/ in your browser.');

        return self::EXIT_OK;
    }

    /**
     * Parse argv and execute (or print help). Primary entry for install/cli.php.
     *
     * @param list<string> $argv
     * @param callable(string): void|null $stdout
     * @param callable(string): void|null $stderr
     */
    public static function runFromArgv(
        array $argv,
        ?callable $stdout = null,
        ?callable $stderr = null,
        ?string $abspath = null
    ): int {
        $out = $stdout ?? static function (string $line): void {
            fwrite(STDOUT, $line . "\n");
        };
        $err = $stderr ?? static function (string $line): void {
            fwrite(STDERR, $line . "\n");
        };

        $script = isset($argv[0]) ? basename((string) $argv[0]) : 'cli.php';
        $parsed = self::parseArgv($argv, $abspath);

        if ($parsed['help']) {
            $out(rtrim(self::usage($script !== '' ? $script : 'install/cli.php')));

            return self::EXIT_OK;
        }

        if (!$parsed['ok']) {
            foreach ($parsed['errors'] as $message) {
                $err($message);
            }
            $err('');
            $err(rtrim(self::usage('install/cli.php')));

            return self::EXIT_USAGE;
        }

        return self::execute($parsed['options'], $out, $err, $abspath);
    }

    /**
     * Load installer dependencies when not already loaded.
     */
    public static function ensureDependencies(): void
    {
        if (!defined('AP_ABSPATH')) {
            define('AP_ABSPATH', dirname(__DIR__) . '/');
        }

        $root = (string) AP_ABSPATH;
        $files = [
            'ap-includes/version.php',
            'ap-includes/load-config.php',
            'ap-includes/class-ap-db.php',
            'ap-includes/class-ap-migrator.php',
            'ap-includes/class-ap-user.php',
            'ap-includes/class-ap-requirements.php',
            'ap-includes/class-ap-installer.php',
        ];
        foreach ($files as $rel) {
            $path = $root . $rel;
            if (is_readable($path)) {
                require_once $path;
            }
        }
    }

    private static function resolveAbspath(?string $abspath): string
    {
        if ($abspath !== null && $abspath !== '') {
            return rtrim($abspath, "/\\") . '/';
        }
        if (defined('AP_ABSPATH')) {
            return rtrim((string) AP_ABSPATH, "/\\") . '/';
        }

        return dirname(__DIR__) . '/';
    }
}
