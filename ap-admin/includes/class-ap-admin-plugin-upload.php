<?php

/**
 * Plugins screen — "Upload plugin (.zip)" form.
 *
 * The form is POST, multipart, and carries nonce `plugin-upload`. A request
 * is installed only when the actor has `install_plugins`. The form includes
 * an "Activate after install" checkbox. Only a posted value of `1` (or
 * boolean true) asks {@see AP_Plugin_Installer::install()} to activate.
 * Any other value leaves the plugin inactive. A posted overwrite flag does
 * not replace an existing slug.
 *
 * When the slug is already installed, the zip is held under
 * `{content}/.ap-plugin-overwrite/{token}/` and the screen asks for
 * confirmation. That step shows the plugin name from the uploaded header,
 * the installed version, and the uploaded version, and offers the same
 * activate checkbox (checked when this upload requested it). Only a later
 * `confirm_overwrite` POST, with nonce `plugin-overwrite` and that token,
 * installs with overwrite. Activation follows that confirm checkbox, not
 * the value stored with the held zip. Cancel discards the held zip and
 * leaves the installed plugin in place. The screen never enables test mode.
 *
 * Success text is {@see AP_Admin_Plugin_Upload::successNotice()}. Each
 * installer error code has a sentence in {@see AP_Admin_Plugin_Upload::ERROR_NOTICES}.
 * {@see AP_Admin_Plugin_Upload::noticeForError()} is what the screen shows.
 * An activation failure shows the installer message, which says the plugin
 * remains inactive. An unknown code shows a generic refusal and not the
 * raw message.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * Request gate and markup for the Plugins screen zip upload.
 */
class AP_Admin_Plugin_Upload
{
    public const NONCE_ACTION = 'plugin-upload';

    public const NONCE_OVERWRITE = 'plugin-overwrite';

    public const POST_FIELD = 'ap_plugin_action';

    public const POST_ACTION = 'upload';

    public const POST_CONFIRM = 'confirm_overwrite';

    public const POST_CANCEL = 'cancel_overwrite';

    public const FILE_FIELD = 'pluginzip';

    public const TOKEN_FIELD = 'plugin_overwrite_token';

    /** Checkbox posted by the upload form and the overwrite confirm form. */
    public const ACTIVATE_FIELD = 'activate';

    public const ACTIVATE_LABEL = 'Activate after install';

    public const HEADING = 'Upload plugin (.zip)';

    public const CONFIRM_HEADING = 'Replace installed plugin?';

    public const MESSAGE_INSTALLED = 'plugin_installed';

    public const MESSAGE_REPLACED = 'plugin_replaced';

    /** How long a held overwrite zip stays confirmable. */
    public const PENDING_SECONDS = 1800;

    /** Hidden content-directory parent for zips waiting on overwrite confirmation. */
    public const PENDING_DIR = '.ap-plugin-overwrite';

    public const NOTICE_FORBIDDEN = 'You do not have permission to install plugins.';

    public const NOTICE_BAD_NONCE = 'Security check failed. Please try again.';

    public const NOTICE_NOT_MULTIPART = 'Plugin upload must be sent as multipart form data.';

    public const NOTICE_PENDING_FAILED = 'Could not hold the plugin zip for confirmation. '
        . 'The installed plugin was left unchanged.';

    public const NOTICE_CONFIRM_INVALID = 'That overwrite confirmation is no longer valid. '
        . 'The installed plugin was left unchanged. Upload the plugin zip again.';

    public const NOTICE_CONFIRM_CHANGED = 'The installed plugin changed after this confirmation was shown. '
        . 'It was left unchanged. Upload the zip again to compare versions.';

    public const NOTICE_PACKAGE_MISMATCH = 'The uploaded package no longer matches this confirmation. '
        . 'The installed plugin was left unchanged.';

    public const NOTICE_CANCELLED = 'Plugin overwrite cancelled. The installed plugin was left unchanged.';

    /** Shown when the installer returns a code this screen does not know. */
    public const NOTICE_UNKNOWN_ERROR = 'The plugin could not be installed.';

    /**
     * Admin sentence for each {@see AP_Plugin_Install_Error::CODES} value.
     *
     * Key order matches that list. {@see self::noticeForError()} shows the
     * sentence, except activation failure, which shows the installer message
     * so the reason and the inactive result stay visible.
     *
     * @var array<string, string>
     */
    public const ERROR_NOTICES = [
        'missing_file' => 'The plugin zip could not be read.',
        'empty' => 'The plugin zip is empty.',
        'not_zip' => 'That file is not a zip archive.',
        'zip_open_failed' => 'The plugin zip could not be opened.',
        'zip_extension_missing' => 'The server cannot open zip files. The PHP ZipArchive extension is required.',
        'oversize' => 'The plugin zip is larger than the allowed size.',
        'sha256_mismatch' => 'The plugin zip checksum does not match.',
        'unsafe_path' => 'The plugin zip contains an unsafe path and was rejected.',
        'symlink' => 'The plugin zip contains a symbolic link and was rejected.',
        'special_file' => 'The plugin zip contains a file that is not a regular file or directory and was rejected.',
        'too_many_entries' => 'The plugin zip contains too many files.',
        'uncompressed_too_large' => 'The plugin zip expands to more than the allowed size.',
        'bad_structure' => 'The plugin zip must contain exactly one plugin folder, '
            . 'or a single plugin file at the archive root.',
        'invalid_slug' => 'The plugin folder name is not a valid slug.',
        'missing_header' => 'The plugin zip is missing a Plugin Name and Version header.',
        'theme_package' => 'That zip looks like a theme, not a plugin.',
        'core_package' => 'That zip looks like an AgoraPress or WordPress core package, not a plugin.',
        'already_installed' => 'That plugin is already installed. Confirm overwrite to replace it.',
        'staging_failed' => 'The plugin could not be prepared for install. Nothing was changed.',
        'install_failed' => 'The plugin could not be installed. '
            . 'Any previous version was left in place when a backup existed.',
        'activation_failed' => 'The plugin was installed but could not be activated and remains inactive.',
        'backup_failed' => 'The previous plugin could not be backed up, so it was left unchanged.',
        'no_backup' => 'No previous version is available to restore.',
        'restore_failed' => 'The previous version could not be restored.',
    ];

    public const STATUS_IGNORED = 'ignored';

    public const STATUS_FORBIDDEN = 'forbidden';

    public const STATUS_BAD_NONCE = 'bad_nonce';

    public const STATUS_NOT_MULTIPART = 'not_multipart';

    public const STATUS_BAD_UPLOAD = 'bad_upload';

    public const STATUS_INSTALLED = 'installed';

    public const STATUS_REPLACED = 'replaced';

    public const STATUS_NEEDS_CONFIRMATION = 'needs_confirmation';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_BAD_CONFIRMATION = 'bad_confirmation';

    public const STATUS_REJECTED = 'rejected';

    private const PENDING_HTACCESS = <<<'HTACCESS'
# Private plugin overwrite confirmation. Do not serve these files.
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    Order deny,allow
    Deny from all
</IfModule>
HTACCESS;

    /**
     * Accept or refuse one upload, overwrite confirmation, or cancel.
     *
     * Ignored unless the method is POST and `ap_plugin_action` is `upload`,
     * `confirm_overwrite`, or `cancel_overwrite`. Capability is checked before
     * the nonce. An upload body must be `multipart/form-data`. The file must
     * be a real PHP upload unless `test_mode` is boolean true (tests only).
     * `overwrite` in `$post` or `$opts` does not replace an existing slug.
     * `activate` in `$opts` is ignored. A posted `activate` of `1`, integer
     * `1`, or boolean true activates a new plugin after install. Any other
     * posted value does not. An existing slug is not installed or activated
     * on this request; the checkbox is shown again on the confirm form, and
     * only that later POST decides activation. Overwrite is applied only by
     * `confirm_overwrite`, and only for the zip held for that token.
     *
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files  $_FILES shape.
     * @param array<string, mixed> $server $_SERVER shape. `CONTENT_TYPE` is read.
     * @param array{
     *   test_mode?: bool,
     *   plugins_root?: string,
     *   content_dir?: string
     * } $opts
     *
     * @return array{
     *   handled: bool,
     *   status: string,
     *   notice: string,
     *   notice_type: string,
     *   redirect_query: array<string, string>|null,
     *   result: AP_Plugin_Install_Result|null,
     *   error: AP_Plugin_Install_Error|null,
     *   confirmation: array{
     *     token: string,
     *     slug: string,
     *     name: string,
     *     version: string,
     *     current_name: string,
     *     current_version: string,
     *     plugin: string,
     *     activate: bool
     *   }|null
     * }
     */
    public static function handle(
        string $method,
        array $post,
        array $files,
        array $server,
        int $userId,
        ?AP_DB $db = null,
        array $opts = []
    ): array {
        $action = self::postedAction($method, $post);
        if ($action === '') {
            return self::outcome(false, self::STATUS_IGNORED, '', 'info');
        }

        if (!self::userCanInstall($userId, $db)) {
            return self::outcome(true, self::STATUS_FORBIDDEN, self::NOTICE_FORBIDDEN);
        }

        $nonce = '';
        if (isset($post['_ap_nonce']) && is_scalar($post['_ap_nonce'])) {
            $nonce = (string) $post['_ap_nonce'];
        }
        $nonceUser = $userId > 0 ? $userId : null;
        $nonceAction = $action === self::POST_ACTION ? self::NONCE_ACTION : self::NONCE_OVERWRITE;
        if (!function_exists('ap_check_nonce') || !ap_check_nonce($nonce, $nonceAction, $nonceUser)) {
            return self::outcome(true, self::STATUS_BAD_NONCE, self::NOTICE_BAD_NONCE);
        }

        if ($action === self::POST_CONFIRM) {
            return self::confirmOverwrite($post, $userId, $opts);
        }
        if ($action === self::POST_CANCEL) {
            return self::cancelOverwrite($post, $userId, $opts);
        }

        if (!self::requestIsMultipart($server)) {
            return self::outcome(true, self::STATUS_NOT_MULTIPART, self::NOTICE_NOT_MULTIPART);
        }

        $testMode = ($opts['test_mode'] ?? false) === true;
        $resolved = self::resolveUploadedZip($files, $testMode);
        if (isset($resolved['error'])) {
            return self::outcome(true, self::STATUS_BAD_UPLOAD, $resolved['error']);
        }

        if (!class_exists('AP_Plugin_Installer', false)) {
            return self::outcome(
                true,
                self::STATUS_REJECTED,
                'Plugin installer is not available.'
            );
        }

        $activate = self::postedActivate($post);
        $installed = AP_Plugin_Installer::install(
            $resolved['path'],
            self::installOptionsFor($opts, $activate)
        );
        if (
            $installed instanceof AP_Plugin_Install_Error
            && $installed->code() === AP_Plugin_Install_Error::ALREADY_INSTALLED
        ) {
            return self::beginOverwriteConfirmation($resolved['path'], $userId, $opts, $activate);
        }

        return self::outcomeFromInstall($installed);
    }

    /**
     * Form markup for an actor who may install plugins. Empty otherwise.
     *
     * The file input's accept hint is not a security check. The installer
     * accepts or rejects the body by zip magic bytes. When `$confirmation`
     * is present, a comparison of the installed version and the uploaded
     * version is rendered above the upload form. The activate checkbox on
     * that card is checked only when `activate` is boolean true.
     *
     * @param array{
     *   token: string,
     *   slug: string,
     *   name: string,
     *   version: string,
     *   current_name: string,
     *   current_version: string,
     *   plugin: string,
     *   activate?: bool
     * }|null $confirmation
     */
    public static function render(int $userId, ?AP_DB $db = null, ?array $confirmation = null): string
    {
        if (!self::userCanInstall($userId, $db) || !function_exists('ap_nonce_field')) {
            return '';
        }

        $action = 'plugins.php';
        if (class_exists('AP_Admin', false)) {
            $action = AP_Admin::url('plugins.php');
        }
        $action = function_exists('ap_esc_url')
            ? ap_esc_url($action)
            : htmlspecialchars($action, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $confirmHtml = self::renderConfirmationCard($userId, $action, $confirmation);

        $max = '';
        if (class_exists('AP_Plugin_Installer', false)) {
            $max = AP_Plugin_Installer::formatBytes(AP_Plugin_Installer::maxValidateBytes());
        }
        $maxHtml = function_exists('ap_esc_html')
            ? ap_esc_html($max)
            : htmlspecialchars($max, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $heading = function_exists('ap_esc_html')
            ? ap_esc_html(self::HEADING)
            : htmlspecialchars(self::HEADING, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $html = '<div class="ap-card ap-plugin-upload" style="margin-bottom:1.5rem;">';
        $html .= '<h2 class="ap-settings-section-title" style="margin-top:0;">' . $heading . '</h2>';
        $html .= '<p class="ap-help">Package must be a zip with one plugin folder, or a single PHP file '
            . 'at the zip root, and a Plugin Name and Version header. Max size: ' . $maxHtml . '.</p>';
        $html .= '<form class="ap-plugin-upload-form" method="post" action="' . $action
            . '" enctype="multipart/form-data">'
            . ap_nonce_field(self::NONCE_ACTION, '_ap_nonce', false, $userId > 0 ? $userId : null)
            . '<input type="hidden" name="' . self::POST_FIELD . '" value="' . self::POST_ACTION . '" />'
            . '<p><label for="pluginzip" class="screen-reader-text">Plugin zip file</label>'
            . '<input type="file" name="' . self::FILE_FIELD . '" id="pluginzip" '
            . 'accept=".zip,application/zip" required /></p>'
            . '<p>' . self::activateCheckbox('ap-plugin-activate', false) . '</p>'
            . '<p><button type="submit" class="button button-primary">Install plugin</button></p>'
            . '</form></div>';

        return $confirmHtml . $html;
    }

    /**
     * @param array<string, mixed> $post
     */
    private static function postedAction(string $method, array $post): string
    {
        if (strtoupper(trim($method)) !== 'POST') {
            return '';
        }
        if (!isset($post[self::POST_FIELD]) || !is_scalar($post[self::POST_FIELD])) {
            return '';
        }

        $action = strtolower(trim((string) $post[self::POST_FIELD]));
        if (
            $action === self::POST_ACTION
            || $action === self::POST_CONFIRM
            || $action === self::POST_CANCEL
        ) {
            return $action;
        }

        return '';
    }

    private static function userCanInstall(int $userId, ?AP_DB $db): bool
    {
        if ($userId < 1 || !class_exists('AP_Roles', false)) {
            return false;
        }

        $cap = class_exists('AP_Plugin_Installer', false)
            ? AP_Plugin_Installer::CAPABILITY
            : 'install_plugins';
        if (class_exists('AP_Admin', false)) {
            return AP_Admin::userCan($userId, $cap, null, $db);
        }

        return AP_Roles::userCan($userId, $cap, null, $db);
    }

    /**
     * @param array<string, mixed> $server
     */
    private static function requestIsMultipart(array $server): bool
    {
        $type = strtolower(trim((string) ($server['CONTENT_TYPE'] ?? '')));
        if ($type === '' && isset($server['HTTP_CONTENT_TYPE'])) {
            $type = strtolower(trim((string) $server['HTTP_CONTENT_TYPE']));
        }
        if ($type === '') {
            return false;
        }
        $semi = strpos($type, ';');
        $media = $semi === false ? trim($type) : trim(substr($type, 0, $semi));

        return $media === 'multipart/form-data';
    }

    /**
     * @param array<string, mixed> $files
     *
     * @return array{path: string}|array{error: string}
     */
    private static function resolveUploadedZip(array $files, bool $testMode): array
    {
        $file = $files[self::FILE_FIELD] ?? null;
        if (!is_array($file)) {
            return ['error' => 'No plugin file was uploaded.'];
        }

        $name = $file['name'] ?? '';
        if (is_array($name)) {
            return ['error' => 'Upload one plugin zip at a time.'];
        }

        $errorCode = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($errorCode !== UPLOAD_ERR_OK) {
            return ['error' => self::uploadErrorMessage($errorCode)];
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        $origName = trim((string) $name);
        if ($tmpName === '' || $origName === '') {
            return ['error' => 'No plugin file was uploaded.'];
        }

        if ($testMode) {
            if (!is_readable($tmpName) || !is_file($tmpName)) {
                return ['error' => 'Upload temporary file is not readable.'];
            }
        } elseif (!is_uploaded_file($tmpName)) {
            return ['error' => 'Invalid upload source.'];
        }

        return ['path' => $tmpName];
    }

    /**
     * Directory options forwarded to the installer. Overwrite is not set.
     *
     * @param array<string, mixed> $opts
     *
     * @return array{plugins_root?: string, content_dir?: string}
     */
    private static function installOptions(array $opts): array
    {
        $install = [];
        foreach (['plugins_root', 'content_dir'] as $key) {
            if (isset($opts[$key]) && is_string($opts[$key]) && $opts[$key] !== '') {
                $install[$key] = $opts[$key];
            }
        }

        return $install;
    }

    /**
     * Directory options plus activate when the admin checked the box.
     *
     * A posted activate flag in `$opts` is not read. Callers pass the
     * checkbox decision as `$activate`.
     *
     * @param array<string, mixed> $opts
     *
     * @return array{plugins_root?: string, content_dir?: string, activate?: true}
     */
    private static function installOptionsFor(array $opts, bool $activate): array
    {
        $install = self::installOptions($opts);
        if ($activate) {
            $install['activate'] = true;
        }

        return $install;
    }

    /**
     * True only for the checkbox value, integer 1, or boolean true.
     *
     * The string "on", "yes", "true", or a padded "1" does not activate.
     *
     * @param array<string, mixed> $post
     */
    private static function postedActivate(array $post): bool
    {
        if (!array_key_exists(self::ACTIVATE_FIELD, $post)) {
            return false;
        }
        $value = $post[self::ACTIVATE_FIELD];

        return $value === true || $value === 1 || $value === '1';
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

    /**
     * @param array{
     *   test_mode?: bool,
     *   plugins_root?: string,
     *   content_dir?: string
     * } $opts
     *
     * @return array{
     *   handled: bool,
     *   status: string,
     *   notice: string,
     *   notice_type: string,
     *   redirect_query: array<string, string>|null,
     *   result: AP_Plugin_Install_Result|null,
     *   error: AP_Plugin_Install_Error|null,
     *   confirmation: array{
     *     token: string,
     *     slug: string,
     *     name: string,
     *     version: string,
     *     current_name: string,
     *     current_version: string,
     *     plugin: string,
     *     activate: bool
     *   }|null
     * }
     */
    private static function beginOverwriteConfirmation(
        string $zipPath,
        int $userId,
        array $opts,
        bool $activate
    ): array {
        $validated = AP_Plugin_Installer::validate($zipPath, self::installOptions($opts));
        if ($validated instanceof AP_Plugin_Install_Error) {
            return self::outcomeFromInstall($validated);
        }
        if ($validated->action() !== AP_Plugin_Install_Result::ACTION_UPGRADED) {
            return self::outcomeFromInstall(
                AP_Plugin_Installer::install($zipPath, self::installOptionsFor($opts, $activate))
            );
        }

        $confirmation = self::stashOverwriteConfirmation($zipPath, $userId, $opts, $validated, $activate);
        if ($confirmation === null) {
            return self::outcome(true, self::STATUS_REJECTED, self::NOTICE_PENDING_FAILED);
        }

        return self::outcome(
            true,
            self::STATUS_NEEDS_CONFIRMATION,
            '',
            'info',
            null,
            null,
            null,
            $confirmation
        );
    }

    /**
     * @param array<string, mixed> $post
     * @param array{
     *   test_mode?: bool,
     *   plugins_root?: string,
     *   content_dir?: string
     * } $opts
     *
     * @return array{
     *   handled: bool,
     *   status: string,
     *   notice: string,
     *   notice_type: string,
     *   redirect_query: array<string, string>|null,
     *   result: AP_Plugin_Install_Result|null,
     *   error: AP_Plugin_Install_Error|null,
     *   confirmation: null
     * }
     */
    private static function confirmOverwrite(array $post, int $userId, array $opts): array
    {
        $opened = self::openPending($opts, self::postedToken($post), $userId);
        if ($opened['ok'] !== true) {
            return self::outcome(true, self::STATUS_BAD_CONFIRMATION, self::NOTICE_CONFIRM_INVALID);
        }

        $record = $opened['record'];
        $validated = AP_Plugin_Installer::validate($opened['zip'], self::installOptions($opts));
        if ($validated instanceof AP_Plugin_Install_Error) {
            self::discardPendingDir($opened['dir'], $opened['root']);

            return self::outcomeFromInstall($validated);
        }
        if (
            $validated->slug() !== $record['slug']
            || $validated->name() !== $record['name']
            || $validated->version() !== $record['version']
            || $validated->plugin() !== $record['plugin']
        ) {
            self::discardPendingDir($opened['dir'], $opened['root']);

            return self::outcome(true, self::STATUS_BAD_CONFIRMATION, self::NOTICE_PACKAGE_MISMATCH);
        }

        $stillInstalled = $validated->action() === AP_Plugin_Install_Result::ACTION_UPGRADED;
        if ($stillInstalled) {
            $live = self::installedIdentity($opened['plugins_root'], $validated);
            if (
                $live['name'] !== $record['current_name']
                || $live['version'] !== $record['current_version']
            ) {
                self::discardPendingDir($opened['dir'], $opened['root']);

                return self::outcome(true, self::STATUS_BAD_CONFIRMATION, self::NOTICE_CONFIRM_CHANGED);
            }
        }

        $installOpts = self::installOptionsFor($opts, self::postedActivate($post));
        if ($stillInstalled) {
            $installOpts['overwrite'] = true;
        }
        $installed = AP_Plugin_Installer::install($opened['zip'], $installOpts);
        self::discardPendingDir($opened['dir'], $opened['root']);

        return self::outcomeFromInstall($installed);
    }

    /**
     * @param array<string, mixed> $post
     * @param array{
     *   test_mode?: bool,
     *   plugins_root?: string,
     *   content_dir?: string
     * } $opts
     *
     * @return array{
     *   handled: bool,
     *   status: string,
     *   notice: string,
     *   notice_type: string,
     *   redirect_query: array<string, string>|null,
     *   result: AP_Plugin_Install_Result|null,
     *   error: AP_Plugin_Install_Error|null,
     *   confirmation: null
     * }
     */
    private static function cancelOverwrite(array $post, int $userId, array $opts): array
    {
        $opened = self::openPending($opts, self::postedToken($post), $userId);
        if ($opened['ok'] !== true) {
            return self::outcome(true, self::STATUS_BAD_CONFIRMATION, self::NOTICE_CONFIRM_INVALID);
        }

        self::discardPendingDir($opened['dir'], $opened['root']);

        return self::outcome(true, self::STATUS_CANCELLED, self::NOTICE_CANCELLED, 'info');
    }

    /**
     * @param array<string, mixed> $post
     */
    private static function postedToken(array $post): string
    {
        if (!isset($post[self::TOKEN_FIELD]) || !is_string($post[self::TOKEN_FIELD])) {
            return '';
        }

        return strtolower(trim($post[self::TOKEN_FIELD]));
    }

    /**
     * Copy a validated zip aside so the next request can confirm it.
     *
     * PHP removes an upload temporary file at the end of the request. The
     * comparison the admin sees is stored with the zip and checked again
     * before overwrite.
     *
     * @param array{
     *   test_mode?: bool,
     *   plugins_root?: string,
     *   content_dir?: string
     * } $opts
     *
     * @return array{
     *   token: string,
     *   slug: string,
     *   name: string,
     *   version: string,
     *   current_name: string,
     *   current_version: string,
     *   plugin: string,
     *   activate: bool
     * }|null
     */
    private static function stashOverwriteConfirmation(
        string $zipPath,
        int $userId,
        array $opts,
        AP_Plugin_Install_Result $validated,
        bool $activate
    ): ?array {
        $dirs = self::directories($opts);
        $root = self::createPendingRoot($dirs['content_dir'], $dirs['plugins_root']);
        if ($root === null) {
            return null;
        }

        self::prunePending($root, $userId);
        $identity = self::installedIdentity($dirs['plugins_root'], $validated);

        try {
            $token = bin2hex(random_bytes(16));
        } catch (Exception) {
            return null;
        }

        $dir = $root . '/' . $token;
        if (is_dir($dir) || is_link($dir) || !mkdir($dir, 0700)) {
            return null;
        }
        chmod($dir, 0700);

        $size = filesize($zipPath);
        $zipDest = $dir . '/package.zip';
        if (!is_int($size) || $size < 1 || !copy($zipPath, $zipDest)) {
            self::discardPendingDir($dir, $root);

            return null;
        }
        chmod($zipDest, 0600);
        $copied = filesize($zipDest);
        if ($copied !== $size) {
            self::discardPendingDir($dir, $root);

            return null;
        }

        $record = [
            'user_id' => $userId,
            'slug' => $validated->slug(),
            'name' => $validated->name(),
            'version' => $validated->version(),
            'current_name' => $identity['name'],
            'current_version' => $identity['version'],
            'plugin' => $validated->plugin(),
            'activate' => $activate,
            'created' => time(),
        ];
        if (!self::pendingRecordIsSafe($record) || !self::writePendingRecord($dir, $record)) {
            self::discardPendingDir($dir, $root);

            return null;
        }

        return [
            'token' => $token,
            'slug' => $record['slug'],
            'name' => $record['name'],
            'version' => $record['version'],
            'current_name' => $record['current_name'],
            'current_version' => $record['current_version'],
            'plugin' => $record['plugin'],
            'activate' => $record['activate'],
        ];
    }

    /**
     * @param array{
     *   plugins_root?: string,
     *   content_dir?: string
     * } $opts
     *
     * @return array{
     *   ok: true,
     *   record: array{
     *     user_id: int,
     *     slug: string,
     *     name: string,
     *     version: string,
     *     current_name: string,
     *     current_version: string,
     *     plugin: string,
     *     created: int
     *   },
     *   zip: string,
     *   dir: string,
     *   root: string,
     *   plugins_root: string
     * }|array{ok: false}
     */
    private static function openPending(array $opts, string $token, int $userId): array
    {
        if (preg_match('/\A[a-f0-9]{32}\z/', $token) !== 1) {
            return ['ok' => false];
        }

        $dirs = self::directories($opts);
        $root = self::existingPendingRoot($dirs['content_dir'], $dirs['plugins_root']);
        if ($root === null) {
            return ['ok' => false];
        }

        self::prunePending($root, null);

        $dir = $root . '/' . $token;
        if (is_link($dir)) {
            unlink($dir);

            return ['ok' => false];
        }
        if (!is_dir($dir)) {
            return ['ok' => false];
        }

        $record = self::readPendingRecord($dir);
        if ($record === null) {
            self::discardPendingDir($dir, $root);

            return ['ok' => false];
        }
        if ($record['user_id'] !== $userId) {
            return ['ok' => false];
        }

        $now = time();
        if (($now - $record['created']) > self::PENDING_SECONDS || $record['created'] > $now + 120) {
            self::discardPendingDir($dir, $root);

            return ['ok' => false];
        }

        $zipLink = $dir . '/package.zip';
        if (is_link($zipLink) || !is_file($zipLink)) {
            self::discardPendingDir($dir, $root);

            return ['ok' => false];
        }
        $zipReal = realpath($zipLink);
        $dirReal = realpath($dir);
        if (
            !is_string($zipReal)
            || !is_string($dirReal)
            || !str_starts_with($zipReal, $dirReal . DIRECTORY_SEPARATOR)
        ) {
            self::discardPendingDir($dir, $root);

            return ['ok' => false];
        }

        return [
            'ok' => true,
            'record' => $record,
            'zip' => $zipReal,
            'dir' => $dirReal,
            'root' => $root,
            'plugins_root' => $dirs['plugins_root'],
        ];
    }

    /**
     * @param array<string, mixed> $opts
     *
     * @return array{plugins_root: string, content_dir: string}
     */
    private static function directories(array $opts): array
    {
        $install = self::installOptions($opts);
        $plugins = $install['plugins_root'] ?? '';
        if ($plugins === '') {
            if (class_exists('AP_Plugin', false)) {
                $plugins = rtrim(AP_Plugin::pluginsRoot(), '/\\');
            } elseif (defined('AP_CONTENT_DIR') && is_string(AP_CONTENT_DIR) && AP_CONTENT_DIR !== '') {
                $plugins = rtrim(AP_CONTENT_DIR, '/\\') . '/plugins';
            }
        }

        $content = $install['content_dir'] ?? '';
        if ($content === '' && $plugins !== '') {
            $parent = dirname($plugins);
            if ($parent !== '' && $parent !== '.' && $parent !== $plugins) {
                $content = $parent;
            }
        }

        return [
            'plugins_root' => $plugins,
            'content_dir' => $content,
        ];
    }

    private static function createPendingRoot(string $contentDir, string $pluginsRoot): ?string
    {
        if ($contentDir === '' || !is_dir($contentDir) || !is_writable($contentDir)) {
            return null;
        }

        $root = rtrim($contentDir, '/\\') . '/' . self::PENDING_DIR;
        if (is_link($root)) {
            return null;
        }
        if (!is_dir($root) && !mkdir($root, 0700)) {
            return null;
        }
        chmod($root, 0700);

        $real = realpath($root);
        if (!is_string($real) || !self::pendingRootIsOutsidePlugins($real, $pluginsRoot)) {
            return null;
        }

        $deny = $real . '/.htaccess';
        if (!is_file($deny)) {
            $written = file_put_contents($deny, self::PENDING_HTACCESS);
            if ($written === false) {
                return null;
            }
            chmod($deny, 0644);
        }

        $index = $real . '/index.php';
        if (!is_file($index)) {
            $guard = "<?php\ndeclare(strict_types=1);\nhttp_response_code(403);\nexit;\n";
            $written = file_put_contents($index, $guard);
            if ($written === false) {
                return null;
            }
            chmod($index, 0644);
        }

        return $real;
    }

    private static function existingPendingRoot(string $contentDir, string $pluginsRoot): ?string
    {
        if ($contentDir === '') {
            return null;
        }

        $root = rtrim($contentDir, '/\\') . '/' . self::PENDING_DIR;
        if (is_link($root) || !is_dir($root)) {
            return null;
        }

        $real = realpath($root);
        if (!is_string($real) || !self::pendingRootIsOutsidePlugins($real, $pluginsRoot)) {
            return null;
        }

        return $real;
    }

    private static function pendingRootIsOutsidePlugins(string $realRoot, string $pluginsRoot): bool
    {
        $rootNorm = rtrim(str_replace('\\', '/', $realRoot), '/');
        if ($pluginsRoot === '') {
            return $rootNorm !== '';
        }

        $pluginsReal = realpath($pluginsRoot);
        $pluginsNorm = rtrim(str_replace('\\', '/', $pluginsReal !== false ? $pluginsReal : $pluginsRoot), '/');
        if ($pluginsNorm === '' || $rootNorm === '') {
            return false;
        }
        if ($rootNorm === $pluginsNorm || str_starts_with($rootNorm, $pluginsNorm . '/')) {
            return false;
        }

        return !str_starts_with($pluginsNorm, $rootNorm . '/');
    }

    /**
     * Drop expired holds. When `$dropUserId` is set, also drop that user's
     * other holds so a new upload replaces the zip waiting for confirmation.
     */
    private static function prunePending(string $root, ?int $dropUserId): void
    {
        $entries = scandir($root);
        if (!is_array($entries)) {
            return;
        }

        $now = time();
        foreach ($entries as $entry) {
            if (preg_match('/\A[a-f0-9]{32}\z/', $entry) !== 1) {
                continue;
            }
            $dir = $root . '/' . $entry;
            if (is_link($dir)) {
                unlink($dir);
                continue;
            }
            if (!is_dir($dir)) {
                continue;
            }

            $record = self::readPendingRecord($dir);
            $drop = $record === null;
            if ($record !== null) {
                $drop = ($now - $record['created']) > self::PENDING_SECONDS
                    || $record['created'] > $now + 120
                    || ($dropUserId !== null && $record['user_id'] === $dropUserId);
            }
            if ($drop) {
                self::discardPendingDir($dir, $root);
            }
        }
    }

    /**
     * @param array{
     *   user_id: int,
     *   slug: string,
     *   name: string,
     *   version: string,
     *   current_name: string,
     *   current_version: string,
     *   plugin: string,
     *   created: int
     * } $record
     */
    private static function writePendingRecord(string $dir, array $record): bool
    {
        try {
            $json = json_encode(
                $record,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        } catch (JsonException) {
            return false;
        }

        $path = $dir . '/pending.json';
        $written = file_put_contents($path, $json, LOCK_EX);
        if ($written !== strlen($json)) {
            return false;
        }
        chmod($path, 0600);

        return true;
    }

    /**
     * @return array{
     *   user_id: int,
     *   slug: string,
     *   name: string,
     *   version: string,
     *   current_name: string,
     *   current_version: string,
     *   plugin: string,
     *   activate: bool,
     *   created: int
     * }|null
     */
    private static function readPendingRecord(string $dir): ?array
    {
        $path = $dir . '/pending.json';
        if (is_link($path) || !is_file($path) || !is_readable($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        if (!is_string($raw) || $raw === '' || strlen($raw) > 100000) {
            return null;
        }

        try {
            $data = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (!is_array($data) || !self::pendingRecordIsSafe($data)) {
            return null;
        }

        $currentVersion = self::pendingText($data['current_version'], true);
        $name = self::pendingText($data['name'], false);
        $version = self::pendingText($data['version'], false);
        $currentName = self::pendingText($data['current_name'], false);
        $slug = self::pendingText($data['slug'], false);
        $plugin = self::pendingText($data['plugin'], false);
        if (
            $currentVersion === null
            || $name === null
            || $version === null
            || $currentName === null
            || $slug === null
            || $plugin === null
        ) {
            return null;
        }

        return [
            'user_id' => $data['user_id'],
            'slug' => $slug,
            'name' => $name,
            'version' => $version,
            'current_name' => $currentName,
            'current_version' => $currentVersion,
            'plugin' => $plugin,
            'activate' => ($data['activate'] ?? false) === true,
            'created' => $data['created'],
        ];
    }

    /**
     * @param array<mixed> $data
     */
    private static function pendingRecordIsSafe(array $data): bool
    {
        if (
            !isset(
                $data['user_id'],
                $data['slug'],
                $data['name'],
                $data['version'],
                $data['current_name'],
                $data['current_version'],
                $data['plugin'],
                $data['created']
            )
            || !is_int($data['user_id'])
            || $data['user_id'] < 1
            || !is_int($data['created'])
        ) {
            return false;
        }
        if (array_key_exists('activate', $data) && !is_bool($data['activate'])) {
            return false;
        }

        $slug = self::pendingText($data['slug'], false);
        $plugin = self::pendingText($data['plugin'], false);
        if ($slug === null || preg_match('/\A[a-z0-9]+(?:[_-][a-z0-9]+)*\z/', $slug) !== 1) {
            return false;
        }
        if (
            $plugin === null
            || str_contains($plugin, '\\')
            || str_contains($plugin, '..')
            || str_starts_with($plugin, '/')
            || substr_count($plugin, '/') > 1
            || !str_ends_with(strtolower($plugin), '.php')
        ) {
            return false;
        }

        return self::pendingText($data['name'], false) !== null
            && self::pendingText($data['version'], false) !== null
            && self::pendingText($data['current_name'], false) !== null
            && self::pendingText($data['current_version'], true) !== null;
    }

    private static function pendingText(mixed $value, bool $allowEmpty): ?string
    {
        if (!is_string($value) || str_contains($value, "\0") || strlen($value) > 8192) {
            return null;
        }
        if (!$allowEmpty && $value === '') {
            return null;
        }

        return $value;
    }

    /**
     * Installed name and version the confirmation step shows.
     *
     * Version prefers the installer's previous version. Name prefers the
     * installed header, so a renamed upload still shows the current name.
     *
     * @return array{name: string, version: string}
     */
    private static function installedIdentity(string $pluginsRoot, AP_Plugin_Install_Result $validated): array
    {
        $version = $validated->previousVersion() ?? '';
        $listOpts = $pluginsRoot !== '' ? ['plugins_root' => $pluginsRoot] : [];
        $matches = [];
        foreach (AP_Plugin_Installer::listInstalled($listOpts) as $row) {
            if ($row['slug'] === $validated->slug()) {
                $matches[] = $row;
            }
        }

        $chosen = null;
        if (count($matches) === 1) {
            $chosen = $matches[0];
        } else {
            foreach ($matches as $row) {
                if ($version !== '' && $row['version'] === $version) {
                    $chosen = $row;
                    break;
                }
            }
        }

        $name = '';
        if (is_array($chosen)) {
            $name = $chosen['name'];
            if ($version === '') {
                $version = $chosen['version'];
            }
        }
        if ($name === '') {
            $name = $validated->name();
        }

        return [
            'name' => $name,
            'version' => $version,
        ];
    }

    private static function discardPendingDir(string $dir, string $root): void
    {
        if (is_link($dir)) {
            unlink($dir);

            return;
        }

        $realRoot = realpath($root);
        $realDir = realpath($dir);
        if (!is_string($realRoot) || !is_string($realDir)) {
            return;
        }
        if ($realDir === $realRoot || !str_starts_with($realDir, $realRoot . DIRECTORY_SEPARATOR)) {
            return;
        }
        if (preg_match('/\A[a-f0-9]{32}\z/', basename($realDir)) !== 1) {
            return;
        }

        foreach (['package.zip', 'pending.json', 'index.php', '.htaccess'] as $name) {
            $file = $realDir . DIRECTORY_SEPARATOR . $name;
            if (is_link($file) || is_file($file)) {
                unlink($file);
            }
        }
        if (is_dir($realDir)) {
            @rmdir($realDir);
        }
    }

    /**
     * @param array{
     *   token: string,
     *   slug: string,
     *   name: string,
     *   version: string,
     *   current_name: string,
     *   current_version: string,
     *   plugin: string
     * }|null $confirmation
     */
    private static function renderConfirmationCard(int $userId, string $action, ?array $confirmation): string
    {
        if ($confirmation === null || !self::confirmationIsRenderable($confirmation)) {
            return '';
        }
        if (!function_exists('ap_create_nonce')) {
            return '';
        }

        $name = $confirmation['name'];
        $currentName = $confirmation['current_name'];
        $version = $confirmation['version'];
        $currentVersion = $confirmation['current_version'];
        $nonce = self::esc(ap_create_nonce(
            self::NONCE_OVERWRITE,
            $userId > 0 ? $userId : null
        ));
        $token = self::esc($confirmation['token']);
        $field = self::esc(self::POST_FIELD);
        $tokenField = self::esc(self::TOKEN_FIELD);

        $html = '<div class="ap-card ap-plugin-overwrite" style="margin-bottom:1.5rem;">';
        $html .= '<h2 class="ap-settings-section-title" style="margin-top:0;">'
            . self::esc(self::CONFIRM_HEADING) . '</h2>';
        $html .= '<p>This plugin is already installed. Compare the current version with the uploaded '
            . 'package. Nothing is replaced until you confirm.</p>';
        $html .= '<table class="ap-table ap-plugin-overwrite-versions"><tbody>';
        $html .= '<tr><th scope="row">Uploaded plugin</th><td class="ap-plugin-overwrite-name">'
            . self::esc($name) . '</td></tr>';
        if ($currentName !== $name) {
            $html .= '<tr><th scope="row">Installed plugin</th>'
                . '<td class="ap-plugin-overwrite-current-name">' . self::esc($currentName) . '</td></tr>';
        }
        $html .= '<tr><th scope="row">Current version</th><td class="ap-plugin-overwrite-current">'
            . self::esc(self::versionLabel($currentVersion)) . '</td></tr>';
        $html .= '<tr><th scope="row">New version</th><td class="ap-plugin-overwrite-new">'
            . self::esc($version) . '</td></tr>';
        $html .= '</tbody></table>';
        if ($currentVersion !== '' && $currentVersion === $version) {
            $html .= '<p class="ap-help ap-plugin-overwrite-same">The uploaded package uses the same '
                . 'version number as the installed plugin.</p>';
        }
        $html .= '<p class="ap-meta"><code class="ap-plugin-overwrite-plugin">'
            . self::esc($confirmation['plugin']) . '</code></p>';
        $html .= '<p class="ap-help">Confirming replaces the installed copy and keeps a backup of the '
            . 'current version. Activation runs only when the checkbox below is checked. '
            . 'If activation fails, the new version stays installed and inactive.</p>';
        $activate = ($confirmation['activate'] ?? false) === true;
        $html .= '<div class="ap-plugin-overwrite-actions" '
            . 'style="display:flex;gap:0.75rem;flex-wrap:wrap;align-items:center;">';
        $html .= '<form class="ap-plugin-overwrite-confirm" method="post" action="' . $action
            . '" style="margin:0;display:flex;gap:0.75rem;flex-wrap:wrap;align-items:center;">'
            . '<input type="hidden" name="_ap_nonce" value="' . $nonce . '" />'
            . '<input type="hidden" name="' . $field . '" value="' . self::POST_CONFIRM . '" />'
            . '<input type="hidden" name="' . $tokenField . '" value="' . $token . '" />'
            . self::activateCheckbox('ap-plugin-overwrite-activate', $activate)
            . '<button type="submit" class="button button-primary">Replace plugin</button>'
            . '</form>';
        $html .= '<form class="ap-plugin-overwrite-cancel" method="post" action="' . $action
            . '" style="margin:0;">'
            . '<input type="hidden" name="_ap_nonce" value="' . $nonce . '" />'
            . '<input type="hidden" name="' . $field . '" value="' . self::POST_CANCEL . '" />'
            . '<input type="hidden" name="' . $tokenField . '" value="' . $token . '" />'
            . '<button type="submit" class="button">Cancel</button>'
            . '</form>';
        $html .= '</div></div>';

        return $html;
    }

    /**
     * @param array<mixed> $confirmation
     */
    private static function confirmationIsRenderable(array $confirmation): bool
    {
        $token = $confirmation['token'] ?? null;
        if (!is_string($token) || preg_match('/\A[a-f0-9]{32}\z/', $token) !== 1) {
            return false;
        }

        foreach (['name', 'version', 'current_name', 'plugin', 'slug'] as $key) {
            if (self::pendingText($confirmation[$key] ?? null, false) === null) {
                return false;
            }
        }

        return self::pendingText($confirmation['current_version'] ?? null, true) !== null;
    }

    private static function versionLabel(string $version): string
    {
        return $version !== '' ? $version : 'Unknown';
    }

    private static function esc(string $text): string
    {
        if (function_exists('ap_esc_html')) {
            return ap_esc_html($text);
        }

        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * @return array{
     *   handled: bool,
     *   status: string,
     *   notice: string,
     *   notice_type: string,
     *   redirect_query: array<string, string>|null,
     *   result: AP_Plugin_Install_Result|null,
     *   error: AP_Plugin_Install_Error|null,
     *   confirmation: null
     * }
     */
    private static function outcomeFromInstall(AP_Plugin_Install_Result|AP_Plugin_Install_Error $installed): array
    {
        if ($installed instanceof AP_Plugin_Install_Result) {
            return self::outcome(
                true,
                $installed->action() === AP_Plugin_Install_Result::ACTION_UPGRADED
                    ? self::STATUS_REPLACED
                    : self::STATUS_INSTALLED,
                '',
                'success',
                self::successRedirect($installed),
                $installed,
                null
            );
        }

        return self::outcome(
            true,
            self::STATUS_REJECTED,
            self::noticeForError($installed),
            'error',
            null,
            null,
            $installed
        );
    }

    /**
     * Sentence shown for one installer failure.
     *
     * Known codes use {@see self::ERROR_NOTICES}. Activation failure uses the
     * installer message when it is non-empty, because that message names the
     * plugin and says it remains inactive. An unknown code shows
     * {@see self::NOTICE_UNKNOWN_ERROR} and does not echo the raw message.
     */
    public static function noticeForError(AP_Plugin_Install_Error $error): string
    {
        $code = $error->code();
        if (!isset(self::ERROR_NOTICES[$code])) {
            return self::NOTICE_UNKNOWN_ERROR;
        }
        if ($code === AP_Plugin_Install_Error::ACTIVATION_FAILED) {
            $detail = trim($error->message());
            if ($detail !== '') {
                return $detail;
            }
        }

        return self::ERROR_NOTICES[$code];
    }

    /**
     * Success sentence for the Plugins screen query string.
     *
     * `message` is `plugin_installed` or `plugin_replaced`. `activated` of
     * `1` says the plugin was activated. Name and version query values are
     * shown only when they are short and contain no control characters.
     * Callers escape the result before HTML output.
     *
     * @param array<string, mixed> $query
     */
    public static function successNotice(array $query): string
    {
        $message = self::queryScalar($query, 'message');
        $plugin = self::queryScalar($query, 'plugin') ?? '';
        $activated = self::queryScalar($query, 'activated') === '1';
        $suffix = $plugin !== '' ? ': ' . $plugin : '';

        if ($message === self::MESSAGE_REPLACED) {
            $from = self::queryScalar($query, 'plugin_from');
            $to = self::queryScalar($query, 'plugin_to');
            if ($from !== null && $to !== null) {
                $name = self::queryScalar($query, 'plugin_name');
                $label = $name ?? ($plugin !== '' ? $plugin : 'Plugin');
                $text = $label . ' updated from version ' . $from . ' to version ' . $to;
                if ($activated) {
                    $text .= ' and activated';
                }

                return $text . '.';
            }
            if ($activated) {
                return 'Plugin replaced and activated' . $suffix . '.';
            }

            return 'Plugin replaced' . $suffix . '.';
        }

        if ($activated) {
            return 'Plugin installed and activated' . $suffix . '.';
        }

        return 'Plugin installed' . $suffix . '. You can activate it below.';
    }

    /**
     * @param array<string, mixed> $query
     */
    private static function queryScalar(array $query, string $key): ?string
    {
        if (!isset($query[$key]) || !is_scalar($query[$key])) {
            return null;
        }

        return self::redirectScalar((string) $query[$key]);
    }

    /**
     * @return array<string, string>
     */
    private static function successRedirect(AP_Plugin_Install_Result $installed): array
    {
        if ($installed->action() !== AP_Plugin_Install_Result::ACTION_UPGRADED) {
            $query = [
                'message' => self::MESSAGE_INSTALLED,
                'plugin' => $installed->plugin(),
            ];
            if ($installed->activated()) {
                $query['activated'] = '1';
            }

            return $query;
        }

        $query = [
            'message' => self::MESSAGE_REPLACED,
            'plugin' => $installed->plugin(),
        ];
        $name = self::redirectScalar($installed->name());
        $from = self::redirectScalar((string) ($installed->previousVersion() ?? ''));
        $to = self::redirectScalar($installed->version());
        if ($name !== null) {
            $query['plugin_name'] = $name;
        }
        if ($from !== null) {
            $query['plugin_from'] = $from;
        }
        if ($to !== null) {
            $query['plugin_to'] = $to;
        }
        if ($installed->activated()) {
            $query['activated'] = '1';
        }

        return $query;
    }

    /**
     * Checkbox markup. The id is chosen by the caller so the upload form and
     * the confirm form can both be on the page.
     */
    private static function activateCheckbox(string $id, bool $checked): string
    {
        $idHtml = self::esc($id);
        $checkedAttr = $checked ? ' checked="checked"' : '';

        return '<label class="ap-plugin-activate" for="' . $idHtml . '">'
            . '<input type="checkbox" name="' . self::esc(self::ACTIVATE_FIELD) . '" id="' . $idHtml
            . '" value="1"' . $checkedAttr . ' /> '
            . self::esc(self::ACTIVATE_LABEL)
            . '</label>';
    }

    private static function redirectScalar(string $value): ?string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > 200) {
            return null;
        }
        if (preg_match('/[[:cntrl:]]/u', $value) !== 0) {
            return null;
        }

        return $value;
    }

    /**
     * @param array<string, string>|null $redirect
     * @param array{
     *   token: string,
     *   slug: string,
     *   name: string,
     *   version: string,
     *   current_name: string,
     *   current_version: string,
     *   plugin: string
     * }|null $confirmation
     *
     * @return array{
     *   handled: bool,
     *   status: string,
     *   notice: string,
     *   notice_type: string,
     *   redirect_query: array<string, string>|null,
     *   result: AP_Plugin_Install_Result|null,
     *   error: AP_Plugin_Install_Error|null,
     *   confirmation: array{
     *     token: string,
     *     slug: string,
     *     name: string,
     *     version: string,
     *     current_name: string,
     *     current_version: string,
     *     plugin: string
     *   }|null
     * }
     */
    private static function outcome(
        bool $handled,
        string $status,
        string $notice,
        string $noticeType = 'error',
        ?array $redirect = null,
        ?AP_Plugin_Install_Result $result = null,
        ?AP_Plugin_Install_Error $error = null,
        ?array $confirmation = null
    ): array {
        return [
            'handled' => $handled,
            'status' => $status,
            'notice' => $notice,
            'notice_type' => $noticeType,
            'redirect_query' => $redirect,
            'result' => $result,
            'error' => $error,
            'confirmation' => $confirmation,
        ];
    }
}
