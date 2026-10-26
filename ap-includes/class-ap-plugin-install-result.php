<?php

/**
 * Structured result and typed error for plugin zip validation and install.
 *
 * {@see AP_Plugin_Installer::validate()} and {@see AP_Plugin_Installer::install()}
 * return one or the other.
 * A result means the package is acceptable. An error carries a stable code
 * and a human message. Callers tell them apart with instanceof.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * Successful plugin zip validation, install, or backup restore.
 *
 * `action` is `installed` when the slug is new, `upgraded` when that slug is
 * already present, and `restored` when the newest backup was put back.
 * `validate()` does not write files: `backupPath()` is null and `activated()`
 * is false until install. A restore result uses `backupPath()` for the backup
 * of the version that was displaced, or null when nothing was installed.
 * `activated()` on a restore is true only when that plugin stays active.
 */
final class AP_Plugin_Install_Result
{
    public const ACTION_INSTALLED = 'installed';

    public const ACTION_UPGRADED = 'upgraded';

    public const ACTION_RESTORED = 'restored';

    private function __construct(
        private readonly string $slug,
        private readonly string $name,
        private readonly string $version,
        private readonly ?string $previousVersion,
        private readonly string $action,
        private readonly ?string $backupPath,
        private readonly bool $activated,
        private readonly string $plugin,
    ) {
    }

    /**
     * @param self::ACTION_INSTALLED|self::ACTION_UPGRADED|self::ACTION_RESTORED $action
     */
    public static function success(
        string $slug,
        string $name,
        string $version,
        ?string $previousVersion,
        string $action,
        ?string $backupPath,
        bool $activated,
        string $plugin,
    ): self {
        $slug = trim($slug);
        $name = trim($name);
        $version = trim($version);
        $plugin = trim(str_replace('\\', '/', $plugin));
        if ($slug === '' || $name === '' || $version === '' || $plugin === '') {
            throw new InvalidArgumentException(
                'A plugin install result requires slug, name, version, and plugin file.'
            );
        }
        if (
            $action !== self::ACTION_INSTALLED
            && $action !== self::ACTION_UPGRADED
            && $action !== self::ACTION_RESTORED
        ) {
            throw new InvalidArgumentException('Plugin install action must be installed, upgraded, or restored.');
        }

        $previous = $previousVersion !== null ? trim($previousVersion) : '';
        $backup = $backupPath !== null ? trim($backupPath) : '';

        return new self(
            $slug,
            $name,
            $version,
            $previous !== '' ? $previous : null,
            $action,
            $backup !== '' ? $backup : null,
            $activated,
            $plugin
        );
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function version(): string
    {
        return $this->version;
    }

    public function previousVersion(): ?string
    {
        return $this->previousVersion;
    }

    /**
     * @return self::ACTION_INSTALLED|self::ACTION_UPGRADED|self::ACTION_RESTORED
     */
    public function action(): string
    {
        return $this->action;
    }

    public function backupPath(): ?string
    {
        return $this->backupPath;
    }

    public function activated(): bool
    {
        return $this->activated;
    }

    /**
     * Plugin basename under the plugins directory (`folder/file.php` or `file.php`).
     */
    public function plugin(): string
    {
        return $this->plugin;
    }

    /**
     * @return array{
     *   ok: true,
     *   slug: string,
     *   name: string,
     *   version: string,
     *   previous_version: string|null,
     *   action: string,
     *   backup_path: string|null,
     *   activated: bool,
     *   plugin: string
     * }
     */
    public function toArray(): array
    {
        return [
            'ok' => true,
            'slug' => $this->slug,
            'name' => $this->name,
            'version' => $this->version,
            'previous_version' => $this->previousVersion,
            'action' => $this->action,
            'backup_path' => $this->backupPath,
            'activated' => $this->activated,
            'plugin' => $this->plugin,
        ];
    }
}

/**
 * Typed failure from plugin zip validation or install.
 *
 * `code()` is a stable machine value. `message()` is safe to show to an admin.
 */
final class AP_Plugin_Install_Error
{
    public const MISSING_FILE = 'missing_file';

    public const EMPTY = 'empty';

    public const NOT_ZIP = 'not_zip';

    public const ZIP_OPEN_FAILED = 'zip_open_failed';

    public const ZIP_EXTENSION_MISSING = 'zip_extension_missing';

    public const OVERSIZE = 'oversize';

    public const SHA256_MISMATCH = 'sha256_mismatch';

    public const UNSAFE_PATH = 'unsafe_path';

    public const SYMLINK = 'symlink';

    public const SPECIAL_FILE = 'special_file';

    public const TOO_MANY_ENTRIES = 'too_many_entries';

    public const UNCOMPRESSED_TOO_LARGE = 'uncompressed_too_large';

    public const BAD_STRUCTURE = 'bad_structure';

    public const INVALID_SLUG = 'invalid_slug';

    public const MISSING_HEADER = 'missing_header';

    public const THEME_PACKAGE = 'theme_package';

    public const CORE_PACKAGE = 'core_package';

    public const ALREADY_INSTALLED = 'already_installed';

    public const STAGING_FAILED = 'staging_failed';

    public const INSTALL_FAILED = 'install_failed';

    /**
     * The package is installed, but activation failed.
     *
     * The plugin files stay in the plugins directory. The plugin is not left
     * on the active list. `message()` says that it remains inactive.
     */
    public const ACTIVATION_FAILED = 'activation_failed';

    public const BACKUP_FAILED = 'backup_failed';

    /** No timestamped backup exists for the requested slug. */
    public const NO_BACKUP = 'no_backup';

    /**
     * The newest backup could not be put back.
     *
     * When the installed plugin was still in place, it is left unchanged.
     */
    public const RESTORE_FAILED = 'restore_failed';

    /** @var list<string> */
    public const CODES = [
        self::MISSING_FILE,
        self::EMPTY,
        self::NOT_ZIP,
        self::ZIP_OPEN_FAILED,
        self::ZIP_EXTENSION_MISSING,
        self::OVERSIZE,
        self::SHA256_MISMATCH,
        self::UNSAFE_PATH,
        self::SYMLINK,
        self::SPECIAL_FILE,
        self::TOO_MANY_ENTRIES,
        self::UNCOMPRESSED_TOO_LARGE,
        self::BAD_STRUCTURE,
        self::INVALID_SLUG,
        self::MISSING_HEADER,
        self::THEME_PACKAGE,
        self::CORE_PACKAGE,
        self::ALREADY_INSTALLED,
        self::STAGING_FAILED,
        self::INSTALL_FAILED,
        self::ACTIVATION_FAILED,
        self::BACKUP_FAILED,
        self::NO_BACKUP,
        self::RESTORE_FAILED,
    ];

    private function __construct(
        private readonly string $code,
        private readonly string $message,
    ) {
    }

    public static function make(string $code, string $message): self
    {
        $message = trim($message);
        if ($message === '') {
            $message = 'Plugin package was rejected.';
        }

        return new self($code, $message);
    }

    public function code(): string
    {
        return $this->code;
    }

    public function message(): string
    {
        return $this->message;
    }

    /**
     * @return array{ok: false, code: string, message: string}
     */
    public function toArray(): array
    {
        return [
            'ok' => false,
            'code' => $this->code,
            'message' => $this->message,
        ];
    }
}
