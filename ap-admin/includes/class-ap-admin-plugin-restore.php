<?php

/**
 * Plugins screen — "Restore previous version" row action.
 *
 * The button is rendered only when the actor has `install_plugins` and
 * {@see AP_Plugin_Installer::latestBackup()} finds a restorable backup for
 * that plugin's slug. The form is POST and carries a nonce bound to the
 * slug (`restore-plugin_{slug}`). A request restores only when the actor
 * has that capability. The capability is checked before the nonce. The
 * screen never enables test mode.
 *
 * Success redirects with `message=plugin_restored`. Each installer error
 * code uses {@see AP_Admin_Plugin_Upload::ERROR_NOTICES}, except restore
 * and backup failures, which show the installer sentence when it is a
 * short single line. An unknown code shows a generic refusal.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * Request gate and row markup for restoring a plugin backup.
 */
class AP_Admin_Plugin_Restore
{
    public const NONCE_PREFIX = 'restore-plugin_';

    public const POST_FIELD = 'ap_plugin_action';

    public const POST_ACTION = 'restore';

    public const SLUG_FIELD = 'plugin_slug';

    public const LABEL = 'Restore previous version';

    public const MESSAGE_RESTORED = 'plugin_restored';

    public const NOTICE_FORBIDDEN = 'You do not have permission to restore plugins.';

    public const NOTICE_BAD_NONCE = 'Security check failed. Please try again.';

    /** Shown when the installer returns a code this screen does not know. */
    public const NOTICE_UNKNOWN_ERROR = 'The previous plugin version could not be restored.';

    public const NOTICE_UNAVAILABLE = 'Plugin installer is not available.';

    public const STATUS_IGNORED = 'ignored';

    public const STATUS_FORBIDDEN = 'forbidden';

    public const STATUS_BAD_NONCE = 'bad_nonce';

    public const STATUS_RESTORED = 'restored';

    public const STATUS_REJECTED = 'rejected';

    /**
     * Accept or refuse one restore POST.
     *
     * Ignored unless the method is POST and `ap_plugin_action` is `restore`.
     * Capability is checked before the nonce. The nonce action is
     * `restore-plugin_` plus the trimmed `plugin_slug`, so a nonce for one
     * slug cannot restore another.
     *
     * @param array<string, mixed> $post
     * @param array{
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
     *   error: AP_Plugin_Install_Error|null
     * }
     */
    public static function handle(
        string $method,
        array $post,
        int $userId,
        ?AP_DB $db = null,
        array $opts = []
    ): array {
        if (!self::isRestorePost($method, $post)) {
            return self::outcome(false, self::STATUS_IGNORED, '', 'info');
        }

        if (!self::userCanRestore($userId, $db)) {
            return self::outcome(true, self::STATUS_FORBIDDEN, self::NOTICE_FORBIDDEN);
        }

        $slug = '';
        if (isset($post[self::SLUG_FIELD]) && is_string($post[self::SLUG_FIELD])) {
            $slug = trim($post[self::SLUG_FIELD]);
        }
        $nonce = '';
        if (isset($post['_ap_nonce']) && is_scalar($post['_ap_nonce'])) {
            $nonce = (string) $post['_ap_nonce'];
        }
        $nonceUser = $userId > 0 ? $userId : null;
        if (!function_exists('ap_check_nonce') || !ap_check_nonce($nonce, self::nonceAction($slug), $nonceUser)) {
            return self::outcome(true, self::STATUS_BAD_NONCE, self::NOTICE_BAD_NONCE);
        }

        if (!class_exists(AP_Plugin_Installer::class)) {
            return self::outcome(true, self::STATUS_REJECTED, self::NOTICE_UNAVAILABLE);
        }

        $restored = AP_Plugin_Installer::restorePrevious($slug, self::directoryOptions($opts));
        if ($restored instanceof AP_Plugin_Install_Error) {
            return self::outcome(
                true,
                self::STATUS_REJECTED,
                self::noticeForError($restored),
                'error',
                null,
                null,
                $restored
            );
        }

        return self::outcome(
            true,
            self::STATUS_RESTORED,
            '',
            'success',
            self::successRedirect($restored),
            $restored,
            null
        );
    }

    /**
     * Row action for one installed plugin. Empty when no backup exists or the actor may not restore.
     *
     * @param array{
     *   plugins_root?: string,
     *   content_dir?: string
     * } $opts
     */
    public static function renderAction(
        int $userId,
        string $pluginFile,
        ?AP_DB $db = null,
        array $opts = []
    ): string {
        if (!self::userCanRestore($userId, $db) || !function_exists('ap_nonce_field')) {
            return '';
        }
        if (!class_exists(AP_Plugin_Installer::class)) {
            return '';
        }

        $slug = AP_Plugin_Installer::slugFromBasename($pluginFile);
        if ($slug === '') {
            return '';
        }

        $backup = AP_Plugin_Installer::latestBackup($slug, self::directoryOptions($opts));
        if ($backup === null) {
            return '';
        }

        $action = 'plugins.php';
        if (class_exists('AP_Admin', false)) {
            $action = AP_Admin::url('plugins.php');
        }
        $action = function_exists('ap_esc_url')
            ? ap_esc_url($action)
            : htmlspecialchars($action, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $version = self::plainLabel($backup['version']);
        $name = self::plainLabel($backup['name']);
        $who = $name !== '' ? $name : 'this plugin';
        $confirm = 'Restore the previous version of ' . $who;
        if ($version !== '') {
            $confirm .= ' (version ' . $version . ')';
        }
        $confirm .= '? The current version is backed up first.';
        $encoded = json_encode(
            $confirm,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES
        );
        if (!is_string($encoded)) {
            $encoded = '"Restore the previous version of this plugin? The current version is backed up first."';
        }

        $id = 'ap-plugin-restore-' . $slug . '-' . substr(hash('sha256', $pluginFile), 0, 8);
        $title = $version !== '' ? 'Restore version ' . $version : self::LABEL;

        $html = '<form class="ap-plugin-restore-form" method="post" action="' . $action
            . '" style="display:inline;margin:0">'
            . ap_nonce_field(self::nonceAction($slug), '_ap_nonce', false, $userId > 0 ? $userId : null)
            . '<input type="hidden" name="' . self::escAttr(self::POST_FIELD) . '" value="'
            . self::escAttr(self::POST_ACTION) . '" />'
            . '<input type="hidden" name="' . self::escAttr(self::SLUG_FIELD) . '" value="'
            . self::escAttr($slug) . '" />'
            . '<button type="submit" class="button button-small" id="' . self::escAttr($id) . '"'
            . ' title="' . self::escAttr($title) . '"'
            . ' onclick="' . self::escAttr('return confirm(' . $encoded . ');') . '">'
            . self::esc(self::LABEL)
            . '</button></form>';

        return $html;
    }

    /**
     * Sentence shown for one installer failure.
     *
     * Known upload-screen codes use {@see AP_Admin_Plugin_Upload::ERROR_NOTICES}.
     * Restore and backup failures show the installer message when it is a
     * short line without control characters, so the reason stays visible.
     * An unknown code shows {@see self::NOTICE_UNKNOWN_ERROR}.
     */
    public static function noticeForError(AP_Plugin_Install_Error $error): string
    {
        $code = $error->code();
        $known = class_exists(AP_Admin_Plugin_Upload::class, false)
            && isset(AP_Admin_Plugin_Upload::ERROR_NOTICES[$code]);
        if (!$known) {
            return self::NOTICE_UNKNOWN_ERROR;
        }
        if (
            $code === AP_Plugin_Install_Error::RESTORE_FAILED
            || $code === AP_Plugin_Install_Error::BACKUP_FAILED
        ) {
            $detail = self::plainLabel($error->message());
            if ($detail !== '') {
                return $detail;
            }
        }

        return AP_Admin_Plugin_Upload::ERROR_NOTICES[$code];
    }

    /**
     * Success sentence for the Plugins screen query string.
     *
     * `message` is `plugin_restored`. `activated` of `1` says the plugin is
     * still active. Name and version query values are shown only when they
     * are short and contain no control characters. Callers escape the result
     * before HTML output.
     *
     * @param array<string, mixed> $query
     */
    public static function successNotice(array $query): string
    {
        $plugin = self::queryScalar($query, 'plugin') ?? '';
        $name = self::queryScalar($query, 'plugin_name');
        $from = self::queryScalar($query, 'plugin_from');
        $to = self::queryScalar($query, 'plugin_to');
        $label = $name ?? ($plugin !== '' ? $plugin : 'Plugin');
        $active = self::queryScalar($query, 'activated') === '1';

        if ($from !== null && $to !== null) {
            $text = $label . ' restored from version ' . $from . ' to version ' . $to;
        } elseif ($to !== null) {
            $text = $label . ' restored to version ' . $to;
        } else {
            $text = 'Plugin restored' . ($plugin !== '' ? ': ' . $plugin : '');
        }
        if ($active) {
            $text .= ' and is still active';
        }

        return $text . '.';
    }

    public static function nonceAction(string $slug): string
    {
        return self::NONCE_PREFIX . $slug;
    }

    /**
     * @param array<string, mixed> $post
     */
    private static function isRestorePost(string $method, array $post): bool
    {
        if (strtoupper(trim($method)) !== 'POST') {
            return false;
        }
        if (!isset($post[self::POST_FIELD]) || !is_scalar($post[self::POST_FIELD])) {
            return false;
        }

        return strtolower(trim((string) $post[self::POST_FIELD])) === self::POST_ACTION;
    }

    private static function userCanRestore(int $userId, ?AP_DB $db): bool
    {
        if ($userId < 1 || !class_exists('AP_Roles', false)) {
            return false;
        }

        $cap = class_exists(AP_Plugin_Installer::class)
            ? AP_Plugin_Installer::CAPABILITY
            : 'install_plugins';
        if (class_exists('AP_Admin', false)) {
            return AP_Admin::userCan($userId, $cap, null, $db);
        }

        return AP_Roles::userCan($userId, $cap, null, $db);
    }

    /**
     * @param array<string, mixed> $opts
     *
     * @return array{plugins_root?: string, content_dir?: string}
     */
    private static function directoryOptions(array $opts): array
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
     * @return array<string, string>
     */
    private static function successRedirect(AP_Plugin_Install_Result $restored): array
    {
        $query = [
            'message' => self::MESSAGE_RESTORED,
            'plugin' => $restored->plugin(),
        ];
        $name = self::redirectScalar($restored->name());
        $from = self::redirectScalar((string) ($restored->previousVersion() ?? ''));
        $to = self::redirectScalar($restored->version());
        if ($name !== null) {
            $query['plugin_name'] = $name;
        }
        if ($from !== null) {
            $query['plugin_from'] = $from;
        }
        if ($to !== null) {
            $query['plugin_to'] = $to;
        }
        if ($restored->activated()) {
            $query['activated'] = '1';
        }

        return $query;
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

    private static function plainLabel(string $value): string
    {
        return self::redirectScalar($value) ?? '';
    }

    private static function esc(string $text): string
    {
        if (function_exists('ap_esc_html')) {
            return ap_esc_html($text);
        }

        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function escAttr(string $text): string
    {
        if (function_exists('ap_esc_attr')) {
            return ap_esc_attr($text);
        }

        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * @param array<string, string>|null $redirect
     *
     * @return array{
     *   handled: bool,
     *   status: string,
     *   notice: string,
     *   notice_type: string,
     *   redirect_query: array<string, string>|null,
     *   result: AP_Plugin_Install_Result|null,
     *   error: AP_Plugin_Install_Error|null
     * }
     */
    private static function outcome(
        bool $handled,
        string $status,
        string $notice,
        string $noticeType = 'error',
        ?array $redirect = null,
        ?AP_Plugin_Install_Result $result = null,
        ?AP_Plugin_Install_Error $error = null
    ): array {
        return [
            'handled' => $handled,
            'status' => $status,
            'notice' => $notice,
            'notice_type' => $noticeType,
            'redirect_query' => $redirect,
            'result' => $result,
            'error' => $error,
        ];
    }
}
