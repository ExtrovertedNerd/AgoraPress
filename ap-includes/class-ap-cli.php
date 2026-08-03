<?php

/**
 * AgoraPress CLI (ap-cli): command dispatcher for installed sites.
 *
 * Lightweight WP-CLI-inspired tool. Plugins may register additional commands
 * via {@see AP_Cli::addCommand()} on the `ap_cli_init` action after core loads.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * Parses argv, boots core when needed, and dispatches built-in / registered commands.
 */
class AP_Cli
{
    /** Exit code: success. */
    public const EXIT_OK = 0;

    /** Exit code: usage / unknown command / validation error. */
    public const EXIT_USAGE = 1;

    /** Exit code: runtime failure (DB, migrate, plugin activate, …). */
    public const EXIT_ERROR = 2;

    /** Exit code: site not installed (missing ap-config.php). */
    public const EXIT_NOT_INSTALLED = 3;

    /**
     * Built-in and registered commands.
     *
     * @var array<string, array{
     *     description: string,
     *     usage: string,
     *     needs_install: bool,
     *     callback: callable
     * }>
     */
    private static array $commands = [];

    /** Whether built-ins have been registered. */
    private static bool $builtinsRegistered = false;

    /** Whether core bootstrap completed this process. */
    private static bool $coreLoaded = false;

    /**
     * Output sinks (overridable in tests).
     *
     * @var callable(string): void|null
     */
    private static $stdout = null;

    /**
     * @var callable(string): void|null
     */
    private static $stderr = null;

    /** Project root with trailing slash. */
    private static string $abspath = '';

    /**
     * Register a command (or replace an existing one).
     *
     * Callback signature:
     *   function (array $args, array $assoc, callable $out, callable $err): int
     *
     * @param callable(list<string>, array<string, string|bool>, callable, callable): int $callback
     */
    public static function addCommand(
        string $name,
        callable $callback,
        string $description = '',
        string $usage = '',
        bool $needsInstall = true
    ): void {
        $name = self::normalizeCommandName($name);
        if ($name === '') {
            return;
        }

        self::$commands[$name] = [
            'description' => $description,
            'usage' => $usage !== '' ? $usage : $name,
            'needs_install' => $needsInstall,
            'callback' => $callback,
        ];
    }

    /**
     * Whether a command is registered.
     */
    public static function hasCommand(string $name): bool
    {
        self::ensureBuiltins();

        return isset(self::$commands[self::normalizeCommandName($name)]);
    }

    /**
     * Registered command names (sorted).
     *
     * @return list<string>
     */
    public static function listCommands(): array
    {
        self::ensureBuiltins();
        $names = array_keys(self::$commands);
        sort($names, SORT_STRING);

        return $names;
    }

    /**
     * Reset static state (unit tests).
     */
    public static function reset(): void
    {
        self::$commands = [];
        self::$builtinsRegistered = false;
        self::$coreLoaded = false;
        self::$stdout = null;
        self::$stderr = null;
        self::$abspath = '';
    }

    /**
     * Human-readable top-level help.
     */
    public static function usage(string $script = 'ap-cli'): string
    {
        self::ensureBuiltins();
        $script = $script !== '' ? $script : 'ap-cli';

        $lines = [
            'AgoraPress CLI (ap-cli)',
            '',
            'Usage:',
            "  php {$script} <command> [<subcommand>] [options] [args]",
            "  php {$script} help [<command>]",
            "  php {$script} --version",
            '',
            'Global options:',
            '  --path=<path>     Path to the AgoraPress root (default: script directory)',
            '  --url=<url>       Override site URL for this run (sets AP_HOME / home hint)',
            '  --skip-plugins    Do not load active plugins (still loads MU plugins)',
            '  --skip-themes     Skip theme setup side effects where possible',
            '  -h, --help        Show this help',
            '  -V, --version     Print AgoraPress version',
            '',
            'Commands:',
        ];

        $names = self::listCommands();
        $width = 0;
        foreach ($names as $name) {
            $width = max($width, strlen($name));
        }
        foreach ($names as $name) {
            $desc = self::$commands[$name]['description'] ?? '';
            $lines[] = '  ' . str_pad($name, $width + 2) . $desc;
        }

        $lines[] = '';
        $lines[] = 'Fresh site install (separate tool):';
        $lines[] = '  php install/cli.php --help';
        $lines[] = '';
        $lines[] = 'Exit codes: 0=ok, 1=usage, 2=error, 3=not installed.';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * Parse argv into command + args + assoc + global flags.
     *
     * @param list<string> $argv Full argv including script name at [0].
     *
     * @return array{
     *     ok: bool,
     *     help: bool,
     *     version: bool,
     *     errors: list<string>,
     *     script: string,
     *     command: string,
     *     args: list<string>,
     *     assoc: array<string, string|bool>,
     *     path: string,
     *     url: string,
     *     skip_plugins: bool,
     *     skip_themes: bool
     * }
     */
    public static function parseArgv(array $argv, ?string $defaultAbspath = null): array
    {
        $script = isset($argv[0]) ? (string) $argv[0] : 'ap-cli';
        $root = self::resolveAbspath($defaultAbspath);
        $errors = [];
        $help = false;
        $version = false;
        $path = $root;
        $url = '';
        $skipPlugins = false;
        $skipThemes = false;
        $positionals = [];
        /** @var array<string, string|bool> $assoc */
        $assoc = [];

        $args = array_values(array_slice($argv, 1));
        $i = 0;
        $n = count($args);

        while ($i < $n) {
            $arg = (string) $args[$i];
            if ($arg === '--') {
                $i++;
                while ($i < $n) {
                    $positionals[] = (string) $args[$i];
                    $i++;
                }
                break;
            }

            if ($arg === '-h' || $arg === '--help') {
                $help = true;
                $i++;
                continue;
            }
            if ($arg === '-V' || $arg === '--version') {
                $version = true;
                $i++;
                continue;
            }
            if ($arg === '--skip-plugins') {
                $skipPlugins = true;
                $i++;
                continue;
            }
            if ($arg === '--skip-themes') {
                $skipThemes = true;
                $i++;
                continue;
            }

            if (str_starts_with($arg, '--')) {
                $eq = strpos($arg, '=');
                if ($eq !== false) {
                    $name = substr($arg, 2, $eq - 2);
                    $value = substr($arg, $eq + 1);
                    $i++;
                } else {
                    $name = substr($arg, 2);
                    if ($i + 1 < $n && !str_starts_with((string) $args[$i + 1], '-')) {
                        $value = (string) $args[$i + 1];
                        $i += 2;
                    } else {
                        $value = true;
                        $i++;
                    }
                }

                if ($name === 'path') {
                    $path = is_string($value) ? $value : $path;
                    continue;
                }
                if ($name === 'url') {
                    $url = is_string($value) ? trim($value) : '';
                    continue;
                }
                if ($name === 'help') {
                    $help = true;
                    continue;
                }
                if ($name === 'version') {
                    $version = true;
                    continue;
                }
                if ($name === 'skip-plugins') {
                    $skipPlugins = true;
                    continue;
                }
                if ($name === 'skip-themes') {
                    $skipThemes = true;
                    continue;
                }

                // Command-level flags.
                if (is_bool($value)) {
                    $assoc[$name] = true;
                } else {
                    $assoc[$name] = (string) $value;
                }
                continue;
            }

            $positionals[] = $arg;
            $i++;
        }

        // Normalize --path.
        $path = self::normalizePathArg($path, $root);

        $command = '';
        $cmdArgs = $positionals;
        if ($positionals !== []) {
            $command = self::normalizeCommandName((string) array_shift($positionals));
            $cmdArgs = $positionals;
            // Subcommands stay as first positional (e.g. plugin list → args=["list"]).
        }

        if ($command === 'help') {
            $help = true;
        }

        return [
            'ok' => $errors === [],
            'help' => $help,
            'version' => $version,
            'errors' => $errors,
            'script' => $script,
            'command' => $command,
            'args' => array_values($cmdArgs),
            'assoc' => $assoc,
            'path' => $path,
            'url' => $url,
            'skip_plugins' => $skipPlugins,
            'skip_themes' => $skipThemes,
        ];
    }

    /**
     * Full CLI run from argv (entry point).
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

        self::$stdout = $out;
        self::$stderr = $err;

        $parsed = self::parseArgv($argv, $abspath);
        self::$abspath = self::resolveAbspath($parsed['path'] !== '' ? $parsed['path'] : $abspath);

        // Define AP_ABSPATH from resolved --path (entry script does not set it early
        // so --path can point at another install root).
        if (!defined('AP_ABSPATH')) {
            define('AP_ABSPATH', self::$abspath);
        }
        if (!defined('AP_CLI')) {
            define('AP_CLI', true);
        }

        self::ensureBuiltins();

        $script = basename((string) $parsed['script']);
        if ($script === '' || $script === 'php') {
            $script = 'ap-cli';
        }

        // Global --version / -V
        if ($parsed['version'] && ($parsed['command'] === '' || $parsed['help'])) {
            $out(self::versionLine());

            return self::EXIT_OK;
        }

        // Top-level help when no command or help/help <cmd>
        if ($parsed['help'] || $parsed['command'] === '' || $parsed['command'] === 'help') {
            $topic = $parsed['command'] === 'help'
                ? (string) ($parsed['args'][0] ?? '')
                : ($parsed['command'] !== '' && $parsed['command'] !== 'help' ? $parsed['command'] : '');

            // `ap-cli help plugin` or `ap-cli plugin --help`
            if ($topic === '' && $parsed['command'] !== '' && $parsed['command'] !== 'help') {
                $topic = $parsed['command'];
            }
            // When user ran `ap-cli help option` args[0]=option
            if ($parsed['command'] === 'help' && isset($parsed['args'][0])) {
                $topic = (string) $parsed['args'][0];
            }

            if ($topic !== '' && $topic !== 'help' && self::hasCommand($topic)) {
                $out(self::commandHelp($topic, $script));

                return self::EXIT_OK;
            }

            if ($topic !== '' && $topic !== 'help' && !self::hasCommand($topic)) {
                $err("Unknown command: {$topic}");
                $err('Run `php ' . $script . ' help` for a list of commands.');

                return self::EXIT_USAGE;
            }

            $out(rtrim(self::usage($script)));

            return self::EXIT_OK;
        }

        $command = $parsed['command'];
        if (!self::hasCommand($command)) {
            $err("Unknown command: {$command}");
            $err('Run `php ' . $script . ' help` for a list of commands.');

            return self::EXIT_USAGE;
        }

        $meta = self::$commands[$command];
        // Subcommand-style: first arg may be "list", "get", …
        // Detect --help after command name.
        if (!empty($parsed['assoc']['help'])) {
            $out(self::commandHelp($command, $script));

            return self::EXIT_OK;
        }

        if ($meta['needs_install']) {
            $loaded = self::loadCore(
                self::$abspath,
                (bool) $parsed['skip_plugins'],
                (bool) $parsed['skip_themes'],
                (string) $parsed['url'],
                $err
            );
            if ($loaded !== self::EXIT_OK) {
                return $loaded;
            }
            // Allow plugins to register extra commands after bootstrap.
            if (function_exists('ap_do_action')) {
                ap_do_action('ap_cli_init');
            }
            // Re-read in case a plugin replaced the command.
            if (!isset(self::$commands[$command])) {
                $err("Command disappeared after bootstrap: {$command}");

                return self::EXIT_ERROR;
            }
            $meta = self::$commands[$command];
        } else {
            // Lightweight version constant for offline commands.
            self::loadVersionOnly(self::$abspath);
        }

        try {
            $code = ($meta['callback'])(
                $parsed['args'],
                $parsed['assoc'],
                $out,
                $err
            );
        } catch (Throwable $e) {
            $err('Error: ' . $e->getMessage());

            return self::EXIT_ERROR;
        }

        return is_int($code) ? $code : self::EXIT_ERROR;
    }

    /**
     * Help text for a single command.
     */
    public static function commandHelp(string $name, string $script = 'ap-cli'): string
    {
        self::ensureBuiltins();
        $name = self::normalizeCommandName($name);
        if (!isset(self::$commands[$name])) {
            return "Unknown command: {$name}\n";
        }
        $meta = self::$commands[$name];
        $lines = [
            'AgoraPress CLI — ' . $name,
            '',
            $meta['description'],
            '',
            'Usage:',
            '  php ' . $script . ' ' . $meta['usage'],
            '',
        ];
        if ($meta['needs_install']) {
            $lines[] = 'Requires an installed site (ap-config.php).';
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * Version string for --version / version command.
     */
    public static function versionLine(?string $abspath = null): string
    {
        self::loadVersionOnly($abspath ?? self::$abspath);
        $v = defined('AP_VERSION') ? (string) AP_VERSION : 'dev';
        $php = PHP_VERSION;

        return "AgoraPress {$v} (PHP {$php})";
    }

    /**
     * Whether core is loaded in this process.
     */
    public static function isCoreLoaded(): bool
    {
        return self::$coreLoaded;
    }

    // -------------------------------------------------------------------------
    // Bootstrap
    // -------------------------------------------------------------------------

    /**
     * Load version.php only (no config / DB).
     */
    public static function loadVersionOnly(?string $abspath = null): void
    {
        $root = self::resolveAbspath($abspath !== null && $abspath !== '' ? $abspath : self::$abspath);
        if (!defined('AP_ABSPATH')) {
            define('AP_ABSPATH', $root);
        }
        $versionFile = $root . 'ap-includes/version.php';
        if (is_readable($versionFile) && !defined('AP_VERSION')) {
            require_once $versionFile;
        }
    }

    /**
     * Load full core for an installed site. Returns exit code (0 = ok).
     *
     * @param callable(string): void $err
     */
    public static function loadCore(
        ?string $abspath = null,
        bool $skipPlugins = false,
        bool $skipThemes = false,
        string $url = '',
        ?callable $err = null
    ): int {
        if (self::$coreLoaded) {
            return self::EXIT_OK;
        }

        $err = $err ?? static function (string $line): void {
            fwrite(STDERR, $line . "\n");
        };

        $root = self::resolveAbspath($abspath !== null && $abspath !== '' ? $abspath : self::$abspath);
        self::$abspath = $root;

        if (!defined('AP_ABSPATH')) {
            define('AP_ABSPATH', $root);
        }
        if (!defined('AP_CLI')) {
            define('AP_CLI', true);
        }
        if ($skipPlugins && !defined('AP_CLI_SKIP_PLUGINS')) {
            define('AP_CLI_SKIP_PLUGINS', true);
        }
        if ($skipThemes && !defined('AP_CLI_SKIP_THEMES')) {
            define('AP_CLI_SKIP_THEMES', true);
        }

        $bootstrap = $root . 'ap-includes/bootstrap.php';
        if (!is_readable($bootstrap)) {
            $err('Error: Could not find ap-includes/bootstrap.php under ' . rtrim($root, '/'));
            $err('Pass --path= to the AgoraPress root (directory containing ap-includes/).');

            return self::EXIT_ERROR;
        }

        // Prefer an explicit config-path check before full bootstrap (clearer CLI errors).
        $configPath = $root . 'ap-config.php';
        if (!is_readable($configPath)) {
            $err('Error: AgoraPress is not installed (missing or unreadable ap-config.php).');
            $err('Run the installer first: php install/cli.php --help');

            return self::EXIT_NOT_INSTALLED;
        }

        require_once $bootstrap;

        if (!function_exists('ap_php_version_is_supported') || !ap_php_version_is_supported()) {
            $err('Error: AgoraPress requires PHP 8.2 or higher. Running ' . PHP_VERSION . '.');

            return self::EXIT_ERROR;
        }

        if (!function_exists('ap_is_installed') || !ap_is_installed($configPath)) {
            $err('Error: AgoraPress is not installed (missing or unreadable ap-config.php).');
            $err('Run the installer first: php install/cli.php --help');

            return self::EXIT_NOT_INSTALLED;
        }

        // Optional URL override for this process (home option hint for link builders).
        if ($url !== '' && !defined('AP_HOME')) {
            define('AP_HOME', rtrim($url, '/'));
        }

        // ap_bootstrap() is safe when installed: no HTML graceful_exit path.
        try {
            ap_bootstrap();
        } catch (Throwable $e) {
            $err('Error loading AgoraPress: ' . $e->getMessage());

            return self::EXIT_ERROR;
        }

        self::$coreLoaded = true;

        return self::EXIT_OK;
    }

    // -------------------------------------------------------------------------
    // Built-in commands
    // -------------------------------------------------------------------------

    /**
     * Register core commands once.
     */
    public static function ensureBuiltins(): void
    {
        if (self::$builtinsRegistered) {
            return;
        }
        self::$builtinsRegistered = true;

        self::addCommand(
            'help',
            [self::class, 'cmdHelp'],
            'Show help for ap-cli or a command',
            'help [<command>]',
            false
        );
        self::addCommand(
            'version',
            [self::class, 'cmdVersion'],
            'Print AgoraPress and PHP versions',
            'version',
            false
        );
        self::addCommand(
            'cli',
            [self::class, 'cmdCli'],
            'CLI info (info)',
            'cli info',
            false
        );
        self::addCommand(
            'core',
            [self::class, 'cmdCore'],
            'Core utilities (version, check-update)',
            'core <version|check-update>',
            true
        );
        self::addCommand(
            'db',
            [self::class, 'cmdDb'],
            'Database (check, migrate)',
            'db <check|migrate>',
            true
        );
        self::addCommand(
            'option',
            [self::class, 'cmdOption'],
            'Manage options (get, set, delete, list)',
            'option <get|set|delete|list> ...',
            true
        );
        self::addCommand(
            'plugin',
            [self::class, 'cmdPlugin'],
            'Manage plugins (list, activate, deactivate)',
            'plugin <list|activate|deactivate> [<plugin>]',
            true
        );
        self::addCommand(
            'theme',
            [self::class, 'cmdTheme'],
            'Manage themes (list, activate)',
            'theme <list|activate> [<stylesheet>]',
            true
        );
        self::addCommand(
            'user',
            [self::class, 'cmdUser'],
            'Manage users (list, get, create)',
            'user <list|get|create> ...',
            true
        );
        self::addCommand(
            'cache',
            [self::class, 'cmdCache'],
            'Object cache (flush)',
            'cache flush',
            true
        );
        self::addCommand(
            'cron',
            [self::class, 'cmdCron'],
            'Cron events (event list, event run)',
            'cron event <list|run>',
            true
        );
        self::addCommand(
            'rewrite',
            [self::class, 'cmdRewrite'],
            'Permalink rules (flush)',
            'rewrite flush',
            true
        );
        self::addCommand(
            'site',
            [self::class, 'cmdSite'],
            'Site utilities (health)',
            'site health [--format=text|json]',
            true
        );
    }

    /**
     * @param list<string> $args
     * @param array<string, string|bool> $assoc
     * @param callable(string): void $out
     * @param callable(string): void $err
     */
    public static function cmdHelp(array $args, array $assoc, callable $out, callable $err): int
    {
        $topic = (string) ($args[0] ?? '');
        if ($topic !== '' && self::hasCommand($topic)) {
            $out(rtrim(self::commandHelp($topic)));

            return self::EXIT_OK;
        }
        $out(rtrim(self::usage('ap-cli')));

        return self::EXIT_OK;
    }

    /**
     * @param list<string> $args
     * @param array<string, string|bool> $assoc
     * @param callable(string): void $out
     * @param callable(string): void $err
     */
    public static function cmdVersion(array $args, array $assoc, callable $out, callable $err): int
    {
        $out(self::versionLine());

        return self::EXIT_OK;
    }

    /**
     * @param list<string> $args
     * @param array<string, string|bool> $assoc
     * @param callable(string): void $out
     * @param callable(string): void $err
     */
    public static function cmdCli(array $args, array $assoc, callable $out, callable $err): int
    {
        $sub = strtolower((string) ($args[0] ?? 'info'));
        if ($sub === 'info' || $sub === '') {
            self::loadVersionOnly(self::$abspath);
            $root = self::resolveAbspath(self::$abspath);
            $installed = is_readable($root . 'ap-config.php');
            $out('os: ' . PHP_OS);
            $out('php: ' . PHP_VERSION);
            $out('sapi: ' . PHP_SAPI);
            $out('agorapress: ' . (defined('AP_VERSION') ? (string) AP_VERSION : 'unknown'));
            $out('root: ' . rtrim($root, '/'));
            $out('installed: ' . ($installed ? 'yes' : 'no'));
            $out('core_loaded: ' . (self::$coreLoaded ? 'yes' : 'no'));

            return self::EXIT_OK;
        }

        $err('Unknown cli subcommand: ' . $sub);
        $err('Usage: cli info');

        return self::EXIT_USAGE;
    }

    /**
     * @param list<string> $args
     * @param array<string, string|bool> $assoc
     * @param callable(string): void $out
     * @param callable(string): void $err
     */
    public static function cmdCore(array $args, array $assoc, callable $out, callable $err): int
    {
        $sub = strtolower((string) ($args[0] ?? ''));
        if ($sub === '' || $sub === 'version') {
            $out(self::versionLine());
            if (defined('AP_DB_VERSION')) {
                $out('db_version_target: ' . (string) AP_DB_VERSION);
            }
            if (class_exists('AP_Migrator', false) && function_exists('ap_db')) {
                try {
                    $db = ap_db();
                    $migrator = new AP_Migrator($db);
                    $out('db_version_applied: ' . (string) $migrator->getCurrentVersion());
                } catch (Throwable) {
                    $out('db_version_applied: unavailable');
                }
            }

            return self::EXIT_OK;
        }

        if ($sub === 'check-update' || $sub === 'check_update') {
            if (!class_exists('AP_Version_Check', false)) {
                $err('Version check is not available.');

                return self::EXIT_ERROR;
            }
            $force = !empty($assoc['force']);
            try {
                $info = $force
                    ? AP_Version_Check::forceCheck()
                    : AP_Version_Check::getRemoteInfo();
            } catch (Throwable $e) {
                $err('Version check failed: ' . $e->getMessage());

                return self::EXIT_ERROR;
            }

            $current = defined('AP_VERSION') ? (string) AP_VERSION : '';
            $remote = is_array($info) ? (string) ($info['version'] ?? '') : '';
            $ok = is_array($info) && !empty($info['ok']);
            $out('current: ' . $current);
            if (!$ok || $remote === '') {
                $out('remote: unavailable (offline, disabled, or cache empty)');
                $out('update: unknown');

                return self::EXIT_OK;
            }
            $out('remote: ' . $remote);
            $has = AP_Version_Check::isNewer($remote, $current);
            $out('update: ' . ($has ? 'available' : 'none'));
            if (!empty($info['download_url'])) {
                $out('download: ' . (string) $info['download_url']);
            }
            if (!empty($info['changelog_url'])) {
                $out('changelog: ' . (string) $info['changelog_url']);
            }

            return self::EXIT_OK;
        }

        $err('Unknown core subcommand: ' . ($sub !== '' ? $sub : '(empty)'));
        $err('Usage: core <version|check-update> [--force]');

        return self::EXIT_USAGE;
    }

    /**
     * @param list<string> $args
     * @param array<string, string|bool> $assoc
     * @param callable(string): void $out
     * @param callable(string): void $err
     */
    public static function cmdDb(array $args, array $assoc, callable $out, callable $err): int
    {
        $sub = strtolower((string) ($args[0] ?? ''));
        if ($sub === '' || $sub === 'check') {
            try {
                $db = ap_db();
                $driver = method_exists($db, 'getDriver') ? (string) $db->getDriver() : 'unknown';
                // Simple connectivity probe.
                $db->getVar('SELECT 1');
                $out('status: ok');
                $out('driver: ' . $driver);
                $out('prefix: ' . (function_exists('ap_get_table_prefix') ? ap_get_table_prefix() : ''));
                if (class_exists('AP_Migrator', false)) {
                    $migrator = new AP_Migrator($db);
                    $current = $migrator->getCurrentVersion();
                    $target = $migrator->getTargetVersion();
                    $pending = $migrator->pending();
                    $out('schema_current: ' . $current);
                    $out('schema_target: ' . $target);
                    $out('pending_migrations: ' . count($pending));
                    $out('needs_migration: ' . ($migrator->needsMigration() ? 'yes' : 'no'));
                }
            } catch (Throwable $e) {
                $err('Database check failed: ' . $e->getMessage());

                return self::EXIT_ERROR;
            }

            return self::EXIT_OK;
        }

        if ($sub === 'migrate') {
            try {
                $db = ap_db();
                $migrator = new AP_Migrator($db);
                $before = $migrator->getCurrentVersion();
                $pending = $migrator->pending();
                if ($pending === []) {
                    $out('No pending migrations (schema at ' . $before . ').');

                    return self::EXIT_OK;
                }
                $out('Applying ' . count($pending) . ' migration(s) from ' . $before . '…');
                $applied = $migrator->migrate();
                $after = $migrator->getCurrentVersion();
                $out('Applied: ' . (is_array($applied) ? (string) count($applied) : '0'));
                $out('schema_version: ' . $after);
            } catch (Throwable $e) {
                $err('Migration failed: ' . $e->getMessage());

                return self::EXIT_ERROR;
            }

            return self::EXIT_OK;
        }

        $err('Unknown db subcommand: ' . ($sub !== '' ? $sub : '(empty)'));
        $err('Usage: db <check|migrate>');

        return self::EXIT_USAGE;
    }

    /**
     * @param list<string> $args
     * @param array<string, string|bool> $assoc
     * @param callable(string): void $out
     * @param callable(string): void $err
     */
    public static function cmdOption(array $args, array $assoc, callable $out, callable $err): int
    {
        $sub = strtolower((string) ($args[0] ?? ''));

        if ($sub === 'get') {
            $name = (string) ($args[1] ?? $assoc['option'] ?? $assoc['key'] ?? '');
            if ($name === '') {
                $err('Usage: option get <name>');

                return self::EXIT_USAGE;
            }
            // Sentinel distinguishes missing option from empty/false stored values.
            $sentinel = "\0ap_cli_opt_miss";
            $value = AP_Options::get($name, $sentinel);
            if ($value === $sentinel) {
                $err("Option not found: {$name}");

                return self::EXIT_ERROR;
            }
            $out(self::formatOptionValue($value));

            return self::EXIT_OK;
        }

        if ($sub === 'set') {
            $name = (string) ($args[1] ?? '');
            $value = $args[2] ?? null;
            if ($name === '' || $value === null) {
                // Support option set name --value=x
                if ($name === '') {
                    $name = (string) ($assoc['option'] ?? $assoc['key'] ?? '');
                }
                if ($value === null && array_key_exists('value', $assoc)) {
                    $value = $assoc['value'];
                }
            }
            if ($name === '' || $value === null) {
                $err('Usage: option set <name> <value>');

                return self::EXIT_USAGE;
            }
            $value = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
            // JSON objects/arrays when value looks like JSON.
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE && (is_array($decoded) || is_object($decoded))) {
                $store = $decoded;
            } else {
                $store = $value;
            }
            $ok = AP_Options::update($name, $store);
            if (!$ok) {
                $err("Failed to update option: {$name}");

                return self::EXIT_ERROR;
            }
            $out("Updated option '{$name}'.");

            return self::EXIT_OK;
        }

        if ($sub === 'delete') {
            $name = (string) ($args[1] ?? $assoc['option'] ?? $assoc['key'] ?? '');
            if ($name === '') {
                $err('Usage: option delete <name>');

                return self::EXIT_USAGE;
            }
            $ok = AP_Options::delete($name);
            if (!$ok) {
                $err("Option not found or could not be deleted: {$name}");

                return self::EXIT_ERROR;
            }
            $out("Deleted option '{$name}'.");

            return self::EXIT_OK;
        }

        if ($sub === 'list') {
            $search = (string) ($assoc['search'] ?? $args[1] ?? '');
            try {
                $db = ap_db();
                $table = $db->quoteIdentifier($db->table('options'));
                $sql = 'SELECT option_name, option_value, autoload FROM ' . $table
                    . ' ORDER BY option_name ASC';
                $params = [];
                if ($search !== '') {
                    $sql = 'SELECT option_name, option_value, autoload FROM ' . $table
                        . ' WHERE option_name LIKE ? ORDER BY option_name ASC';
                    $params[] = '%' . $search . '%';
                }
                $rows = $db->getResults($sql, $params);
            } catch (Throwable $e) {
                $err('Could not list options: ' . $e->getMessage());

                return self::EXIT_ERROR;
            }
            if (!is_array($rows) || $rows === []) {
                $out('(no options)');

                return self::EXIT_OK;
            }
            foreach ($rows as $row) {
                $data = is_array($row) ? $row : get_object_vars($row);
                $name = (string) ($data['option_name'] ?? '');
                $raw = (string) ($data['option_value'] ?? '');
                $preview = strlen($raw) > 80 ? substr($raw, 0, 77) . '...' : $raw;
                $preview = str_replace(["\r", "\n"], ['', ' '], $preview);
                $out($name . "\t" . $preview);
            }

            return self::EXIT_OK;
        }

        $err('Unknown option subcommand: ' . ($sub !== '' ? $sub : '(empty)'));
        $err('Usage: option <get|set|delete|list> ...');

        return self::EXIT_USAGE;
    }

    /**
     * @param list<string> $args
     * @param array<string, string|bool> $assoc
     * @param callable(string): void $out
     * @param callable(string): void $err
     */
    public static function cmdPlugin(array $args, array $assoc, callable $out, callable $err): int
    {
        $sub = strtolower((string) ($args[0] ?? ''));

        if ($sub === '' || $sub === 'list') {
            $plugins = AP_Plugin::listPlugins();
            $active = array_fill_keys(AP_Plugin::getActivePlugins(), true);
            if ($plugins === []) {
                $out('(no plugins installed)');

                return self::EXIT_OK;
            }
            $format = strtolower((string) ($assoc['format'] ?? 'table'));
            if ($format === 'json') {
                $payload = [];
                foreach ($plugins as $file => $headers) {
                    $payload[] = [
                        'file' => $file,
                        'name' => (string) ($headers['Plugin Name'] ?? $file),
                        'version' => (string) ($headers['Version'] ?? ''),
                        'status' => isset($active[$file]) ? 'active' : 'inactive',
                    ];
                }
                $out((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

                return self::EXIT_OK;
            }
            foreach ($plugins as $file => $headers) {
                $status = isset($active[$file]) ? 'active' : 'inactive';
                $name = (string) ($headers['Plugin Name'] ?? $file);
                $ver = (string) ($headers['Version'] ?? '');
                $out(sprintf('%s%s  %s  %s', $status === 'active' ? '* ' : '  ', $file, $name, $ver !== '' ? 'v' . $ver : ''));
            }
            $out('');
            $out('Legend: * = active');

            return self::EXIT_OK;
        }

        if ($sub === 'activate') {
            $plugin = (string) ($args[1] ?? $assoc['plugin'] ?? '');
            if ($plugin === '') {
                $err('Usage: plugin activate <plugin-basename>');

                return self::EXIT_USAGE;
            }
            $result = AP_Plugin::activate($plugin);
            if (empty($result['ok'])) {
                foreach ($result['errors'] as $e) {
                    $err($e);
                }

                return self::EXIT_ERROR;
            }
            $out("Plugin activated: {$plugin}");

            return self::EXIT_OK;
        }

        if ($sub === 'deactivate') {
            $plugin = (string) ($args[1] ?? $assoc['plugin'] ?? '');
            if ($plugin === '') {
                $err('Usage: plugin deactivate <plugin-basename>');

                return self::EXIT_USAGE;
            }
            $result = AP_Plugin::deactivate($plugin);
            if (empty($result['ok'])) {
                foreach ($result['errors'] as $e) {
                    $err($e);
                }

                return self::EXIT_ERROR;
            }
            $out("Plugin deactivated: {$plugin}");

            return self::EXIT_OK;
        }

        $err('Unknown plugin subcommand: ' . ($sub !== '' ? $sub : '(empty)'));
        $err('Usage: plugin <list|activate|deactivate> [<plugin>]');

        return self::EXIT_USAGE;
    }

    /**
     * @param list<string> $args
     * @param array<string, string|bool> $assoc
     * @param callable(string): void $out
     * @param callable(string): void $err
     */
    public static function cmdTheme(array $args, array $assoc, callable $out, callable $err): int
    {
        $sub = strtolower((string) ($args[0] ?? ''));

        if ($sub === '' || $sub === 'list') {
            $themes = AP_Theme::listThemes();
            $active = AP_Theme::getStylesheet();
            if ($themes === []) {
                $out('(no themes installed)');

                return self::EXIT_OK;
            }
            foreach ($themes as $slug => $info) {
                $name = is_array($info)
                    ? (string) ($info['Name'] ?? $info['Theme Name'] ?? $slug)
                    : (string) $slug;
                $mark = $slug === $active ? '* ' : '  ';
                $out($mark . $slug . '  ' . $name);
            }
            $out('');
            $out('Legend: * = active stylesheet');

            return self::EXIT_OK;
        }

        if ($sub === 'activate') {
            $slug = (string) ($args[1] ?? $assoc['theme'] ?? $assoc['stylesheet'] ?? '');
            if ($slug === '') {
                $err('Usage: theme activate <stylesheet>');

                return self::EXIT_USAGE;
            }
            if (!AP_Theme::isValidTheme($slug)) {
                $err("Theme not found or invalid: {$slug}");

                return self::EXIT_ERROR;
            }
            $ok = AP_Theme::setActive($slug);
            if (!$ok) {
                $err("Failed to activate theme: {$slug}");

                return self::EXIT_ERROR;
            }
            $out("Theme activated: {$slug}");

            return self::EXIT_OK;
        }

        $err('Unknown theme subcommand: ' . ($sub !== '' ? $sub : '(empty)'));
        $err('Usage: theme <list|activate> [<stylesheet>]');

        return self::EXIT_USAGE;
    }

    /**
     * @param list<string> $args
     * @param array<string, string|bool> $assoc
     * @param callable(string): void $out
     * @param callable(string): void $err
     */
    public static function cmdUser(array $args, array $assoc, callable $out, callable $err): int
    {
        $sub = strtolower((string) ($args[0] ?? ''));

        if ($sub === '' || $sub === 'list') {
            $number = (int) ($assoc['number'] ?? 50);
            if ($number < 1) {
                $number = 50;
            }
            $role = (string) ($assoc['role'] ?? '');
            $search = (string) ($assoc['search'] ?? '');
            $queryArgs = [
                'number' => $number,
                'orderby' => 'ID',
                'order' => 'ASC',
            ];
            if ($role !== '') {
                $queryArgs['role'] = $role;
            }
            if ($search !== '') {
                $queryArgs['search'] = $search;
            }
            $users = AP_User::query($queryArgs);
            if ($users === []) {
                $out('(no users)');

                return self::EXIT_OK;
            }
            foreach ($users as $user) {
                $roles = class_exists('AP_Roles', false)
                    ? implode(',', AP_Roles::getUserRoles($user->ID))
                    : '';
                $out(sprintf(
                    "%d\t%s\t%s\t%s",
                    $user->ID,
                    $user->user_login,
                    $user->user_email,
                    $roles
                ));
            }

            return self::EXIT_OK;
        }

        if ($sub === 'get') {
            $idOrLogin = (string) ($args[1] ?? $assoc['user'] ?? $assoc['id'] ?? '');
            if ($idOrLogin === '') {
                $err('Usage: user get <id|login|email>');

                return self::EXIT_USAGE;
            }
            $user = null;
            if (ctype_digit($idOrLogin)) {
                $user = AP_User::getById((int) $idOrLogin);
            }
            if ($user === null) {
                $user = AP_User::getByLogin($idOrLogin);
            }
            if ($user === null) {
                $user = AP_User::getByEmail($idOrLogin);
            }
            if ($user === null) {
                $err("User not found: {$idOrLogin}");

                return self::EXIT_ERROR;
            }
            $pub = $user->toPublicArray();
            foreach ($pub as $k => $v) {
                $out($k . ': ' . (is_scalar($v) ? (string) $v : json_encode($v)));
            }
            if (class_exists('AP_Roles', false)) {
                $out('roles: ' . implode(', ', AP_Roles::getUserRoles($user->ID)));
            }

            return self::EXIT_OK;
        }

        if ($sub === 'create') {
            $login = (string) ($assoc['user_login'] ?? $assoc['login'] ?? $args[1] ?? '');
            $email = (string) ($assoc['user_email'] ?? $assoc['email'] ?? $args[2] ?? '');
            $pass = (string) ($assoc['user_pass'] ?? $assoc['password'] ?? getenv('AP_USER_PASSWORD') ?: '');
            $role = (string) ($assoc['role'] ?? 'subscriber');
            $display = (string) ($assoc['display_name'] ?? '');

            if ($login === '' || $email === '' || $pass === '') {
                $err('Usage: user create --user_login=NAME --user_email=EMAIL --user_pass=PASS [--role=subscriber]');
                $err('Password may also be provided via AP_USER_PASSWORD environment variable.');

                return self::EXIT_USAGE;
            }

            $data = [
                'user_login' => $login,
                'user_email' => $email,
                'user_pass' => $pass,
                'role' => $role,
            ];
            if ($display !== '') {
                $data['display_name'] = $display;
            }
            $result = AP_User::create($data);
            if (empty($result['ok'])) {
                foreach ($result['errors'] as $e) {
                    $err($e);
                }

                return self::EXIT_ERROR;
            }
            $out('User created: ID ' . (int) $result['id'] . ' (' . $login . ')');

            return self::EXIT_OK;
        }

        $err('Unknown user subcommand: ' . ($sub !== '' ? $sub : '(empty)'));
        $err('Usage: user <list|get|create> ...');

        return self::EXIT_USAGE;
    }

    /**
     * @param list<string> $args
     * @param array<string, string|bool> $assoc
     * @param callable(string): void $out
     * @param callable(string): void $err
     */
    public static function cmdCache(array $args, array $assoc, callable $out, callable $err): int
    {
        $sub = strtolower((string) ($args[0] ?? ''));
        if ($sub === '' || $sub === 'flush') {
            $flushed = false;
            if (function_exists('ap_cache_flush')) {
                $flushed = (bool) ap_cache_flush();
            }
            if (class_exists('AP_Options', false)) {
                AP_Options::flushCache();
            }
            if (function_exists('ap_clean_page_cache')) {
                ap_clean_page_cache();
            }
            $out($flushed ? 'Object cache flushed.' : 'Cache flush requested (object cache may be unavailable).');

            return self::EXIT_OK;
        }

        $err('Unknown cache subcommand: ' . $sub);
        $err('Usage: cache flush');

        return self::EXIT_USAGE;
    }

    /**
     * @param list<string> $args
     * @param array<string, string|bool> $assoc
     * @param callable(string): void $out
     * @param callable(string): void $err
     */
    public static function cmdCron(array $args, array $assoc, callable $out, callable $err): int
    {
        $group = strtolower((string) ($args[0] ?? ''));
        $sub = strtolower((string) ($args[1] ?? ''));

        // Allow `cron list` / `cron run` as aliases of `cron event list|run`
        if ($group === 'list' || ($group === 'event' && $sub === 'list') || ($group === 'event' && $sub === '')) {
            $cron = AP_Cron::getCronArray();
            $count = 0;
            foreach ($cron as $ts => $hooks) {
                if (!is_int($ts) && !(is_string($ts) && ctype_digit((string) $ts))) {
                    continue;
                }
                if (!is_array($hooks)) {
                    continue;
                }
                foreach ($hooks as $hook => $events) {
                    if (!is_string($hook) || !is_array($events)) {
                        continue;
                    }
                    foreach ($events as $key => $event) {
                        $sched = is_array($event) ? (string) ($event['schedule'] ?? 'once') : 'once';
                        $out(sprintf(
                            "%s\t%s\t%s\t%s",
                            date('c', (int) $ts),
                            $hook,
                            $sched,
                            (string) $key
                        ));
                        $count++;
                    }
                }
            }
            if ($count === 0) {
                $out('(no scheduled events)');
            }

            return self::EXIT_OK;
        }

        if ($group === 'run' || ($group === 'event' && $sub === 'run')) {
            $n = AP_Cron::runDue();
            $out('Ran ' . $n . ' due cron callback(s).');

            return self::EXIT_OK;
        }

        $err('Usage: cron event list | cron event run');

        return self::EXIT_USAGE;
    }

    /**
     * @param list<string> $args
     * @param array<string, string|bool> $assoc
     * @param callable(string): void $out
     * @param callable(string): void $err
     */
    public static function cmdRewrite(array $args, array $assoc, callable $out, callable $err): int
    {
        $sub = strtolower((string) ($args[0] ?? ''));
        if ($sub === '' || $sub === 'flush') {
            $rules = AP_Rewrite::flushRules();
            $count = is_array($rules) ? count($rules) : 0;
            $out('Rewrite rules flushed (' . $count . ' rule(s)).');

            return self::EXIT_OK;
        }

        $err('Usage: rewrite flush');

        return self::EXIT_USAGE;
    }

    /**
     * @param list<string> $args
     * @param array<string, string|bool> $assoc
     * @param callable(string): void $out
     * @param callable(string): void $err
     */
    public static function cmdSite(array $args, array $assoc, callable $out, callable $err): int
    {
        $sub = strtolower((string) ($args[0] ?? ''));
        if ($sub !== 'health' && $sub !== '') {
            $err('Unknown site subcommand: ' . $sub);
            $err('Usage: site health [--format=text|json]');

            return self::EXIT_USAGE;
        }

        if (!class_exists('AP_Site_Health', false)) {
            $err('Site Health is not available.');

            return self::EXIT_ERROR;
        }

        $checks = AP_Site_Health::getChecks();
        $format = strtolower((string) ($assoc['format'] ?? 'text'));

        if ($format === 'json') {
            $out((string) json_encode($checks, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::EXIT_OK;
        }

        $counts = ['good' => 0, 'recommended' => 0, 'critical' => 0];
        foreach ($checks as $check) {
            $status = (string) ($check['status'] ?? 'good');
            if (isset($counts[$status])) {
                $counts[$status]++;
            }
            $badge = strtoupper(substr($status, 0, 4));
            $label = (string) ($check['label'] ?? $check['id'] ?? '');
            $message = (string) ($check['message'] ?? '');
            $out(sprintf('[%s] %s — %s', $badge, $label, $message));
        }
        $out('');
        $out(sprintf(
            'Summary: %d good, %d recommended, %d critical',
            $counts['good'],
            $counts['recommended'],
            $counts['critical']
        ));

        return $counts['critical'] > 0 ? self::EXIT_ERROR : self::EXIT_OK;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private static function normalizeCommandName(string $name): string
    {
        $name = strtolower(trim($name));
        $name = preg_replace('/[^a-z0-9_-]/', '', $name) ?? '';

        return $name;
    }

    private static function resolveAbspath(?string $abspath): string
    {
        if ($abspath !== null && $abspath !== '') {
            $path = $abspath;
        } elseif (defined('AP_ABSPATH')) {
            $path = (string) AP_ABSPATH;
        } else {
            $path = dirname(__DIR__) . '/';
        }

        $path = str_replace('\\', '/', $path);
        if ($path !== '' && !str_ends_with($path, '/')) {
            $path .= '/';
        }

        return $path;
    }

    private static function normalizePathArg(string $path, string $fallback): string
    {
        $path = trim($path);
        if ($path === '') {
            return $fallback;
        }
        // Expand relative paths from CWD when possible.
        if ($path[0] !== '/' && !preg_match('#^[A-Za-z]:[\\\\/]#', $path)) {
            $cwd = getcwd();
            if (is_string($cwd) && $cwd !== '') {
                $path = rtrim(str_replace('\\', '/', $cwd), '/') . '/' . ltrim(str_replace('\\', '/', $path), '/');
            }
        }
        $real = realpath($path);
        if (is_string($real) && is_dir($real)) {
            $path = $real;
        }

        return self::resolveAbspath($path);
    }

    private static function formatOptionValue(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_bool($value)) {
            return $value ? '1' : '';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if ($value === null) {
            return '';
        }

        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        return is_string($json) ? $json : '';
    }

    private static function normalizeSemver(string $version): string
    {
        $version = trim($version);
        if ($version === '') {
            return '0.0.0';
        }
        // Strip leading "v" and pre-release for coarse compare when needed.
        $version = ltrim($version, 'vV');

        return $version;
    }
}
