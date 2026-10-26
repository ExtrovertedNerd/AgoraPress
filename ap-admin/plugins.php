<?php

/**
 * Plugins — list, activate, deactivate, upload zip, delete.
 *
 * Discovery reads Plugin Name headers under ap-content/plugins/. Active
 * basenames are stored in the `active_plugins` option. Zip upload is
 * {@see AP_Admin_Plugin_Upload}: POST, multipart, nonce `plugin-upload`,
 * capability `install_plugins`, then {@see AP_Plugin_Installer::install()}.
 * An existing slug is not replaced on that request. The screen shows the
 * installed version beside the uploaded version and replaces the plugin
 * only after a confirm POST. The upload form has an "Activate after install"
 * checkbox; the confirm form repeats it. Success text and installer error
 * codes are mapped by {@see AP_Admin_Plugin_Upload}.
 * When a backup exists, {@see AP_Admin_Plugin_Restore} adds a POST
 * "Restore previous version" action. That action requires `install_plugins`
 * and a nonce bound to the plugin slug.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

require_once __DIR__ . '/admin-bootstrap.php';
require_once __DIR__ . '/includes/class-ap-admin-plugin-upload.php';
require_once __DIR__ . '/includes/class-ap-admin-plugin-restore.php';

AP_Admin::requireCapability('activate_plugins');

AP_Admin::consumeQueryNotice();

$userId = ap_get_current_user_id();
$db = ap_db();

// Upload plugin (.zip): POST, multipart, nonce plugin-upload, install_plugins.
// An existing slug shows current vs new version and waits for confirmation.
$pluginUpload = AP_Admin_Plugin_Upload::handle(
    (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
    $_POST,
    $_FILES,
    $_SERVER,
    $userId,
    $db
);
if ($pluginUpload['handled']) {
    if ($pluginUpload['redirect_query'] !== null) {
        AP_Admin::redirect(AP_Admin::url('plugins.php', $pluginUpload['redirect_query']));
    }
    if ($pluginUpload['notice'] !== '') {
        AP_Admin::addNotice($pluginUpload['notice'], $pluginUpload['notice_type']);
    }
}

// Restore previous version: POST, nonce restore-plugin_{slug}, install_plugins.
// Shown later only on rows whose slug has a restorable backup.
$pluginRestore = AP_Admin_Plugin_Restore::handle(
    (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
    $_POST,
    $userId,
    $db
);
if ($pluginRestore['handled']) {
    if ($pluginRestore['redirect_query'] !== null) {
        AP_Admin::redirect(AP_Admin::url('plugins.php', $pluginRestore['redirect_query']));
    }
    if ($pluginRestore['notice'] !== '') {
        AP_Admin::addNotice($pluginRestore['notice'], $pluginRestore['notice_type']);
    }
}

// --- Activate / deactivate / delete (GET with nonce, WordPress-style) ---
$action = strtolower(trim((string) ($_GET['action'] ?? '')));
$plugin = isset($_GET['plugin']) ? (string) $_GET['plugin'] : '';
$plugin = str_replace('\\', '/', $plugin);

if ($action === 'activate' || $action === 'deactivate' || $action === 'delete') {
    $nonce = (string) ($_GET['_ap_nonce'] ?? $_GET['_wpnonce'] ?? '');
    $nonceAction = $action . '-plugin_' . $plugin;
    if (!ap_check_nonce($nonce, $nonceAction, $userId > 0 ? $userId : null)) {
        AP_Admin::addNotice('Security check failed. Please try again.', 'error');
    } elseif ($plugin === '') {
        AP_Admin::addNotice('No plugin specified.', 'error');
    } elseif ($action === 'activate') {
        $result = ap_activate_plugin($plugin, $db);
        if ($result['ok']) {
            AP_Admin::redirect(AP_Admin::url('plugins.php', ['message' => 'plugin_activated']));
        }
        $msg = $result['errors'] !== []
            ? implode(' ', $result['errors'])
            : 'Could not activate the plugin.';
        AP_Admin::addNotice($msg, 'error');
    } elseif ($action === 'deactivate') {
        $result = ap_deactivate_plugin($plugin, $db);
        if ($result['ok']) {
            AP_Admin::redirect(AP_Admin::url('plugins.php', ['message' => 'plugin_deactivated']));
        }
        $msg = $result['errors'] !== []
            ? implode(' ', $result['errors'])
            : 'Could not deactivate the plugin.';
        AP_Admin::addNotice($msg, 'error');
    } else {
        $canDeleteNow = AP_Admin::userCan($userId, 'delete_plugins', null, $db)
            || AP_Admin::userCan($userId, 'install_plugins', null, $db);
        if (!$canDeleteNow) {
            AP_Admin::addNotice('You do not have permission to delete plugins.', 'error');
        } else {
            $del = AP_Plugin_Installer::deletePlugin($plugin, $db);
            if ($del['ok']) {
                AP_Admin::redirect(AP_Admin::url('plugins.php', [
                    'message' => 'plugin_deleted',
                    'plugin' => $plugin,
                ]));
            }
            $msg = $del['errors'] !== []
                ? implode(' ', $del['errors'])
                : 'Could not delete the plugin.';
            AP_Admin::addNotice($msg, 'error');
        }
    }
}

// Query-string success messages (after redirect).
$message = (string) ($_GET['message'] ?? '');
$msgPlugin = isset($_GET['plugin']) ? (string) $_GET['plugin'] : '';
if ($message === 'plugin_activated') {
    AP_Admin::addNotice('Plugin activated.', 'success');
} elseif ($message === 'plugin_deactivated') {
    AP_Admin::addNotice('Plugin deactivated.', 'success');
} elseif (
    $message === AP_Admin_Plugin_Upload::MESSAGE_INSTALLED
    || $message === AP_Admin_Plugin_Upload::MESSAGE_REPLACED
) {
    AP_Admin::addNotice(AP_Admin_Plugin_Upload::successNotice($_GET), 'success');
} elseif ($message === AP_Admin_Plugin_Restore::MESSAGE_RESTORED) {
    AP_Admin::addNotice(AP_Admin_Plugin_Restore::successNotice($_GET), 'success');
} elseif ($message === 'plugin_deleted') {
    AP_Admin::addNotice(
        'Plugin deleted' . ($msgPlugin !== '' ? ': ' . $msgPlugin : '') . '.',
        'success'
    );
}

$plugins = ap_get_plugins();
$active = ap_get_active_plugins($db);
$activeMap = array_fill_keys($active, true);
$muPlugins = function_exists('ap_get_mu_plugins') ? ap_get_mu_plugins() : [];
$canDelete = AP_Admin::userCan($userId, 'delete_plugins', null, $db)
    || AP_Admin::userCan($userId, 'install_plugins', null, $db);

$ap_admin_title = 'Plugins';
$ap_admin_screen = 'plugins';
require __DIR__ . '/admin-header.php';
?>
<div class="ap-page-header">
    <h1>Plugins</h1>
</div>

<p class="ap-help">
    Drop plugins into <code>ap-content/plugins/</code> as a single PHP file or a
    folder with a main PHP file containing a <strong>Plugin Name:</strong> header,
    or upload a <strong>.zip</strong> below. Activate them here to load on every request.
    When a backup exists, administrators can use <strong>Restore previous version</strong>
    on that row. The current copy is saved as a new backup first.
    Must-use plugins in <code>ap-content/mu-plugins/</code> load automatically and cannot
    be deactivated here.
</p>

<?php
$overwriteConfirm = null;
if (
    $pluginUpload['status'] === AP_Admin_Plugin_Upload::STATUS_NEEDS_CONFIRMATION
    && is_array($pluginUpload['confirmation'])
) {
    $overwriteConfirm = $pluginUpload['confirmation'];
}
echo AP_Admin_Plugin_Upload::render($userId, $db, $overwriteConfirm);
?>

<?php if ($plugins === []) : ?>
    <div class="ap-notice ap-notice--info">
        No plugins found. Upload a plugin zip or add a PHP file under
        <code>ap-content/plugins/</code> with a <code>Plugin Name:</code> header.
    </div>
<?php else : ?>
    <div class="ap-table-wrap">
        <table class="ap-table ap-plugins-table">
            <thead>
                <tr>
                    <th scope="col">Plugin</th>
                    <th scope="col">Description</th>
                    <th scope="col">Status</th>
                    <th scope="col">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($plugins as $file => $headers) :
                    $isActive = isset($activeMap[$file]);
                    $name = (string) ($headers['Plugin Name'] ?? $file);
                    $desc = (string) ($headers['Description'] ?? '');
                    $version = (string) ($headers['Version'] ?? '');
                    $author = (string) ($headers['Author'] ?? '');
                    $uri = (string) ($headers['Plugin URI'] ?? '');
                    $authorUri = (string) ($headers['Author URI'] ?? '');
                    $nonceAct = $isActive ? 'deactivate-plugin_' . $file : 'activate-plugin_' . $file;
                    $act = $isActive ? 'deactivate' : 'activate';
                    $actionUrl = ap_nonce_url(
                        AP_Admin::url('plugins.php', [
                            'action' => $act,
                            'plugin' => $file,
                        ]),
                        $nonceAct,
                        '_ap_nonce',
                        $userId > 0 ? $userId : null
                    );
                    // Settings → admin.php?page={id} when the plugin registered a page
                    // with matching plugin basename, is active, and the user has the cap.
                    $settingsLink = $isActive
                        ? AP_Admin::pluginSettingsActionLink(
                            $file,
                            $db,
                            $userId > 0 ? $userId : null
                        )
                        : null;
                    ?>
                    <tr class="<?php echo $isActive ? 'ap-plugin-active' : 'ap-plugin-inactive'; ?>">
                        <td>
                            <strong>
                                <?php if ($uri !== '') : ?>
                                    <a href="<?php echo ap_esc_url($uri); ?>" rel="noopener noreferrer"><?php echo ap_esc_html($name); ?></a>
                                <?php else : ?>
                                    <?php echo ap_esc_html($name); ?>
                                <?php endif; ?>
                            </strong>
                            <div class="ap-meta"><code><?php echo ap_esc_html($file); ?></code></div>
                        </td>
                        <td>
                            <?php if ($desc !== '') : ?>
                                <p><?php echo ap_esc_html($desc); ?></p>
                            <?php endif; ?>
                            <p class="ap-meta">
                                <?php if ($version !== '') : ?>
                                    Version <?php echo ap_esc_html($version); ?>
                                <?php endif; ?>
                                <?php if ($author !== '') : ?>
                                    <?php echo $version !== '' ? ' | ' : ''; ?>
                                    By
                                    <?php if ($authorUri !== '') : ?>
                                        <a href="<?php echo ap_esc_url($authorUri); ?>" rel="noopener noreferrer"><?php echo ap_esc_html($author); ?></a>
                                    <?php else : ?>
                                        <?php echo ap_esc_html($author); ?>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </p>
                        </td>
                        <td>
                            <?php if ($isActive) : ?>
                                <span class="ap-badge ap-badge--success">Active</span>
                            <?php else : ?>
                                <span class="ap-badge">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td class="ap-plugin-actions">
                            <a class="button button-small" href="<?php echo ap_esc_url($actionUrl); ?>">
                                <?php echo $isActive ? 'Deactivate' : 'Activate'; ?>
                            </a>
                            <?php if ($settingsLink !== null) : ?>
                                <a class="button button-small ap-plugin-settings-link"
                                   href="<?php echo ap_esc_url($settingsLink['url']); ?>">
                                    <?php echo ap_esc_html($settingsLink['label']); ?>
                                </a>
                            <?php endif; ?>
                            <?php
                            echo AP_Admin_Plugin_Restore::renderAction(
                                $userId,
                                $file,
                                $db
                            );
                            ?>
                            <?php if ($canDelete && !$isActive) :
                                $delUrl = ap_nonce_url(
                                    AP_Admin::url('plugins.php', [
                                        'action' => 'delete',
                                        'plugin' => $file,
                                    ]),
                                    'delete-plugin_' . $file,
                                    '_ap_nonce',
                                    $userId > 0 ? $userId : null
                                );
                                $delConfirm = 'Delete plugin ' . $file . '? This cannot be undone.';
                                ?>
                                <a class="button button-small button-link-delete"
                                   href="<?php echo ap_esc_url($delUrl); ?>"
                                   onclick="return confirm('<?php echo ap_esc_attr($delConfirm); ?>');">Delete</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php if ($muPlugins !== []) : ?>
    <h2 class="ap-settings-section-title" style="margin-top:2rem;">Must-Use Plugins</h2>
    <p class="ap-help">
        Files in <code>ap-content/mu-plugins/</code> are always loaded (before regular plugins).
        Remove a file from the server to disable it.
    </p>
    <div class="ap-table-wrap">
        <table class="ap-table ap-plugins-table">
            <thead>
                <tr>
                    <th scope="col">Plugin</th>
                    <th scope="col">Description</th>
                    <th scope="col">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($muPlugins as $file => $headers) :
                    $name = (string) ($headers['Plugin Name'] ?? $file);
                    $desc = (string) ($headers['Description'] ?? '');
                    $version = (string) ($headers['Version'] ?? '');
                    $author = (string) ($headers['Author'] ?? '');
                    ?>
                    <tr class="ap-plugin-active ap-plugin-mu">
                        <td>
                            <strong><?php echo ap_esc_html($name); ?></strong>
                            <div class="ap-meta"><code><?php echo ap_esc_html($file); ?></code></div>
                        </td>
                        <td>
                            <?php if ($desc !== '') : ?>
                                <p><?php echo ap_esc_html($desc); ?></p>
                            <?php endif; ?>
                            <p class="ap-meta">
                                <?php if ($version !== '') : ?>
                                    Version <?php echo ap_esc_html($version); ?>
                                <?php endif; ?>
                                <?php if ($author !== '') : ?>
                                    <?php echo $version !== '' ? ' | ' : ''; ?>
                                    By <?php echo ap_esc_html($author); ?>
                                <?php endif; ?>
                            </p>
                        </td>
                        <td>
                            <span class="ap-badge ap-badge--success">Must-Use</span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php
require __DIR__ . '/admin-footer.php';
