<?php

/**
 * Appearance — Themes: list, activate, upload classic zip, delete.
 *
 * Accepts classic WordPress theme packages (.zip with style.css + Theme Name).
 * Block / FSE themes are rejected by the installer (compatibility layer scope).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

require_once __DIR__ . '/admin-bootstrap.php';

AP_Admin::requireCapability('switch_themes');

AP_Admin::consumeQueryNotice();

$userId = ap_get_current_user_id();
$db = ap_db();

// --- Upload (install_themes) ---
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['ap_theme_action'])) {
    $postAction = strtolower(trim((string) $_POST['ap_theme_action']));

    if ($postAction === 'upload') {
        if (!AP_Admin::userCan($userId, 'install_themes', null, $db)) {
            AP_Admin::addNotice('You do not have permission to install themes.', 'error');
        } else {
            $nonce = (string) ($_POST['_ap_nonce'] ?? '');
            if (!ap_check_nonce($nonce, 'theme-upload', $userId > 0 ? $userId : null)) {
                AP_Admin::addNotice('Security check failed. Please try again.', 'error');
            } else {
                $file = isset($_FILES['themezip']) && is_array($_FILES['themezip'])
                    ? $_FILES['themezip']
                    : [];
                $overwrite = !empty($_POST['overwrite']);
                $result = AP_Theme_Installer::handleUpload($file, [
                    'overwrite' => $overwrite,
                ]);
                if ($result['ok']) {
                    $params = [
                        'message' => $result['overwritten'] ? 'theme_replaced' : 'theme_installed',
                        'theme' => $result['slug'],
                    ];
                    if ($result['warnings'] !== []) {
                        AP_Admin::addNotice(implode(' ', $result['warnings']), 'warning');
                    }
                    AP_Admin::redirect(AP_Admin::url('themes.php', $params));
                }
                $msg = $result['errors'] !== []
                    ? implode(' ', $result['errors'])
                    : 'Could not install the theme.';
                AP_Admin::addNotice($msg, 'error');
            }
        }
    }
}

// --- Activate / delete (GET + nonce) ---
$action = strtolower(trim((string) ($_GET['action'] ?? '')));
$theme = isset($_GET['theme']) ? (string) $_GET['theme'] : '';
$theme = preg_replace('/[^a-z0-9_\\-]+/i', '', $theme) ?? '';

if ($action === 'activate' || $action === 'delete') {
    $nonce = (string) ($_GET['_ap_nonce'] ?? $_GET['_wpnonce'] ?? '');
    $nonceAction = $action . '-theme_' . $theme;

    if (!ap_check_nonce($nonce, $nonceAction, $userId > 0 ? $userId : null)) {
        AP_Admin::addNotice('Security check failed. Please try again.', 'error');
    } elseif ($theme === '') {
        AP_Admin::addNotice('No theme specified.', 'error');
    } elseif ($action === 'activate') {
        if (!AP_Admin::userCan($userId, 'switch_themes', null, $db)) {
            AP_Admin::addNotice('You do not have permission to switch themes.', 'error');
        } elseif (!AP_Theme::isValidTheme($theme)) {
            // Child missing parent still has headers — give a clearer message.
            $headers = AP_Theme::getThemeHeaders($theme);
            if ($headers === null) {
                AP_Admin::addNotice('That theme is not installed or is invalid.', 'error');
            } else {
                $parent = (string) ($headers['Template'] ?? '');
                if ($parent !== '') {
                    AP_Admin::addNotice(
                        'Cannot activate: parent theme “' . $parent . '” is missing or invalid.',
                        'error'
                    );
                } else {
                    AP_Admin::addNotice('That theme is not valid for activation.', 'error');
                }
            }
        } elseif (AP_Theme::setActive($theme, null, $db)) {
            // Enable classic compat auto for non-agora uploads (mode remains auto).
            AP_Admin::redirect(AP_Admin::url('themes.php', [
                'message' => 'theme_activated',
                'theme' => $theme,
            ]));
        } else {
            AP_Admin::addNotice('Could not activate the theme.', 'error');
        }
    } else {
        // delete
        $canDeleteNow = AP_Admin::userCan($userId, 'delete_themes', null, $db)
            || AP_Admin::userCan($userId, 'install_themes', null, $db);
        if (!$canDeleteNow) {
            AP_Admin::addNotice('You do not have permission to delete themes.', 'error');
        } else {
            $del = AP_Theme_Installer::deleteTheme($theme, $db);
            if ($del['ok']) {
                AP_Admin::redirect(AP_Admin::url('themes.php', [
                    'message' => 'theme_deleted',
                    'theme' => $theme,
                ]));
            }
            $msg = $del['errors'] !== []
                ? implode(' ', $del['errors'])
                : 'Could not delete the theme.';
            AP_Admin::addNotice($msg, 'error');
        }
    }
}

// Query-string success messages.
$message = (string) ($_GET['message'] ?? '');
$msgTheme = isset($_GET['theme']) ? (string) $_GET['theme'] : '';
if ($message === 'theme_activated') {
    AP_Admin::addNotice(
        'Theme activated' . ($msgTheme !== '' ? ': ' . $msgTheme : '') . '.',
        'success'
    );
} elseif ($message === 'theme_installed') {
    AP_Admin::addNotice(
        'Theme installed' . ($msgTheme !== '' ? ': ' . $msgTheme : '') . '. You can activate it below.',
        'success'
    );
} elseif ($message === 'theme_replaced') {
    AP_Admin::addNotice(
        'Theme replaced' . ($msgTheme !== '' ? ': ' . $msgTheme : '') . '.',
        'success'
    );
} elseif ($message === 'theme_deleted') {
    AP_Admin::addNotice(
        'Theme deleted' . ($msgTheme !== '' ? ': ' . $msgTheme : '') . '.',
        'success'
    );
}

$themes = AP_Theme::listThemes();
$activeSlug = AP_Theme::getStylesheet($db);
$canInstall = AP_Admin::userCan($userId, 'install_themes', null, $db);
$canDelete = AP_Admin::userCan($userId, 'delete_themes', null, $db)
    || AP_Admin::userCan($userId, 'install_themes', null, $db);
$maxZip = AP_Theme_Installer::formatBytes(AP_Theme_Installer::maxUploadBytes());

$ap_admin_title = 'Themes';
$ap_admin_screen = 'themes';
require __DIR__ . '/admin-header.php';
?>
<div class="ap-page-header">
    <h1>Themes</h1>
</div>

<p class="ap-help">
    Manage installed themes under <code>ap-content/themes/</code>.
    Upload a classic WordPress theme <strong>.zip</strong> (pre-block PHP themes with
    <code>style.css</code> headers). The <strong>Classic WordPress Theme Compatibility Layer</strong>
    loads shims automatically for classic non-Agora themes so many WP themes can run
    with minimal changes. Block / Full Site Editing themes are not supported in this uploader.
</p>

<?php if ($canInstall) : ?>
    <div class="ap-card ap-theme-upload" style="margin-bottom:1.5rem;">
        <h2 class="ap-settings-section-title" style="margin-top:0;">Upload theme</h2>
        <p class="ap-help">
            Package must be a zip with <code>theme-folder/style.css</code> (or
            <code>style.css</code> at the zip root) and a <strong>Theme Name:</strong> header.
            Parent themes need <code>index.php</code>. Max size: <?php echo ap_esc_html($maxZip); ?>.
        </p>
        <form class="ap-theme-upload-form" method="post" action="<?php echo ap_esc_url(AP_Admin::url('themes.php')); ?>" enctype="multipart/form-data">
            <?php echo ap_nonce_field('theme-upload', '_ap_nonce', false, $userId > 0 ? $userId : null); ?>
            <input type="hidden" name="ap_theme_action" value="upload" />
            <p>
                <label for="themezip" class="screen-reader-text">Theme zip file</label>
                <input type="file" name="themezip" id="themezip" accept=".zip,application/zip" required />
            </p>
            <p>
                <label>
                    <input type="checkbox" name="overwrite" value="1" />
                    Overwrite if a theme with the same folder name already exists
                </label>
            </p>
            <p>
                <button type="submit" class="button button-primary">Install theme</button>
            </p>
        </form>
    </div>
<?php endif; ?>

<?php if ($themes === []) : ?>
    <div class="ap-notice ap-notice--info">
        No themes found. Upload a classic theme zip or add a folder under
        <code>ap-content/themes/</code> with a valid <code>style.css</code>.
    </div>
<?php else : ?>
    <div class="ap-theme-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(16rem,1fr));gap:1rem;">
        <?php foreach ($themes as $slug => $headers) :
            $name = (string) ($headers['Theme Name'] ?? $slug);
            $desc = (string) ($headers['Description'] ?? '');
            $version = (string) ($headers['Version'] ?? '');
            $author = (string) ($headers['Author'] ?? '');
            $isChild = (($headers['Is Child'] ?? '') === '1');
            $parent = (string) ($headers['Parent'] ?? '');
            $shot = (string) ($headers['Screenshot'] ?? '');
            $isActive = ($slug === $activeSlug);
            $isBlock = function_exists('ap_is_block_theme') && ap_is_block_theme($slug);
            $compatHint = '';
            if ($slug !== 'agora' && !$isBlock) {
                $compatHint = 'Classic WP compatibility available';
            } elseif ($isBlock) {
                $compatHint = 'Block theme (limited support)';
            }
            ?>
            <article
                class="ap-card ap-theme-card<?php echo $isActive ? ' ap-theme-card--active' : ''; ?>"
                style="display:flex;flex-direction:column;"
            >
                <div
                    class="ap-theme-card__preview"
                    style="aspect-ratio:4/3;background:var(--ap-table-head);
                        border-radius:var(--ap-radius,6px);overflow:hidden;
                        margin-bottom:0.75rem;display:flex;align-items:center;justify-content:center;"
                >
                    <?php if ($shot !== '') : ?>
                        <img
                            src="<?php echo ap_esc_url($shot); ?>"
                            alt=""
                            style="width:100%;height:100%;object-fit:cover;"
                            loading="lazy"
                        />
                    <?php else : ?>
                        <span class="ap-meta" style="padding:1rem;text-align:center;">
                            <?php echo ap_esc_html($name); ?>
                        </span>
                    <?php endif; ?>
                </div>
                <h2 class="ap-theme-card__title" style="font-size:1.05rem;margin:0 0 0.35rem;">
                    <?php echo ap_esc_html($name); ?>
                    <?php if ($isActive) : ?>
                        <span class="ap-badge ap-badge--success">Active</span>
                    <?php endif; ?>
                </h2>
                <p class="ap-meta" style="margin:0 0 0.5rem;">
                    <code><?php echo ap_esc_html($slug); ?></code>
                    <?php if ($version !== '') : ?>
                        · v<?php echo ap_esc_html($version); ?>
                    <?php endif; ?>
                    <?php if ($author !== '') : ?>
                        · <?php echo ap_esc_html($author); ?>
                    <?php endif; ?>
                </p>
                <?php if ($isChild && $parent !== '') : ?>
                    <p class="ap-meta">Child of <code><?php echo ap_esc_html($parent); ?></code></p>
                <?php endif; ?>
                <?php if ($desc !== '') : ?>
                    <p style="flex:1;font-size:0.9rem;"><?php echo ap_esc_html(mb_strlen($desc) > 160 ? mb_substr($desc, 0, 157) . '…' : $desc); ?></p>
                <?php endif; ?>
                <?php if ($compatHint !== '') : ?>
                    <p class="ap-meta"><?php echo ap_esc_html($compatHint); ?></p>
                <?php endif; ?>
                <div class="ap-card-actions" style="display:flex;flex-wrap:wrap;gap:0.4rem;margin-top:0.75rem;">
                    <?php if (!$isActive) :
                        $actUrl = ap_nonce_url(
                            AP_Admin::url('themes.php', [
                                'action' => 'activate',
                                'theme' => $slug,
                            ]),
                            'activate-theme_' . $slug,
                            '_ap_nonce',
                            $userId > 0 ? $userId : null
                        );
                        ?>
                        <a class="button button-primary button-small" href="<?php echo ap_esc_url($actUrl); ?>">Activate</a>
                    <?php endif; ?>
                    <?php if ($canDelete && !$isActive && $slug !== 'agora') :
                        $delUrl = ap_nonce_url(
                            AP_Admin::url('themes.php', [
                                'action' => 'delete',
                                'theme' => $slug,
                            ]),
                            'delete-theme_' . $slug,
                            '_ap_nonce',
                            $userId > 0 ? $userId : null
                        );
                        ?>
                        <a class="button button-small button-link-delete" href="<?php echo ap_esc_url($delUrl); ?>"
                           onclick="return confirm('Delete theme <?php echo ap_esc_attr($slug); ?>? This cannot be undone.');">Delete</a>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php
require __DIR__ . '/admin-footer.php';
