<?php

/**
 * Plugins — list, activate, and deactivate installed plugins.
 *
 * Discovery reads Plugin Name headers under ap-content/plugins/. Active
 * basenames are stored in the `active_plugins` option.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

require_once __DIR__ . '/admin-bootstrap.php';

AP_Admin::requireCapability('activate_plugins');

AP_Admin::consumeQueryNotice();

$userId = ap_get_current_user_id();
$db = ap_db();

// --- Activate / deactivate (GET with nonce, WordPress-style) ---
$action = strtolower(trim((string) ($_GET['action'] ?? '')));
$plugin = isset($_GET['plugin']) ? (string) $_GET['plugin'] : '';
$plugin = str_replace('\\', '/', $plugin);

if ($action === 'activate' || $action === 'deactivate') {
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
    } else {
        $result = ap_deactivate_plugin($plugin, $db);
        if ($result['ok']) {
            AP_Admin::redirect(AP_Admin::url('plugins.php', ['message' => 'plugin_deactivated']));
        }
        $msg = $result['errors'] !== []
            ? implode(' ', $result['errors'])
            : 'Could not deactivate the plugin.';
        AP_Admin::addNotice($msg, 'error');
    }
}

// Query-string success messages (after redirect).
$message = (string) ($_GET['message'] ?? '');
if ($message === 'plugin_activated') {
    AP_Admin::addNotice('Plugin activated.', 'success');
} elseif ($message === 'plugin_deactivated') {
    AP_Admin::addNotice('Plugin deactivated.', 'success');
}

$plugins = ap_get_plugins();
$active = ap_get_active_plugins($db);
$activeMap = array_fill_keys($active, true);
$muPlugins = function_exists('ap_get_mu_plugins') ? ap_get_mu_plugins() : [];

$ap_admin_title = 'Plugins';
$ap_admin_screen = 'plugins';
require __DIR__ . '/admin-header.php';
?>
<div class="ap-page-header">
    <h1>Plugins</h1>
</div>

<p class="ap-help">
    Drop plugins into <code>ap-content/plugins/</code> as a single PHP file or a
    folder with a main PHP file containing a <strong>Plugin Name:</strong> header.
    Activate them here to load on every request. Must-use plugins in
    <code>ap-content/mu-plugins/</code> load automatically and cannot be deactivated here.
</p>

<?php if ($plugins === []) : ?>
    <div class="ap-notice ap-notice--info">
        No plugins found. Add a PHP file under <code>ap-content/plugins/</code>
        with a <code>Plugin Name:</code> header to get started.
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
                                <a class="button button-small ap-plugin-settings-link" href="<?php echo ap_esc_url($settingsLink['url']); ?>">
                                    <?php echo ap_esc_html($settingsLink['label']); ?>
                                </a>
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
