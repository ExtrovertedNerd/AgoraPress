<?php

/**
 * Tools: Export (full-site JSON).
 *
 * Downloads one JSON file with content, users, terms, comments, options and
 * forums. Password hashes, session tokens, secret-looking options and private
 * messages are never included.
 *
 * Cap: export plus manage_options (administrators only by default).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

require_once __DIR__ . '/admin-bootstrap.php';

AP_Admin::requireCapability('export');
AP_Admin::consumeQueryNotice();

$userId = (int) ap_get_current_user_id();
$db = ap_db();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $nonce = (string) ($_POST['_ap_nonce'] ?? '');
    if (!ap_check_nonce($nonce, 'site-export', $userId > 0 ? $userId : null)) {
        AP_Admin::addNotice('Security check failed. Please try again.', 'error');
    } elseif (!AP_Site_Export::canExport($userId, $db)) {
        AP_Admin::addNotice('You do not have permission to export this site.', 'error');
    } else {
        try {
            $json = AP_Site_Export::toJson(AP_Site_Export::build($db));
            $siteName = (string) AP_Options::get('blogname', 'site', $db);
            $filename = AP_Site_Export::filename($siteName);
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
                header('Content-Disposition: attachment; filename="' . $filename . '"');
                header('X-Content-Type-Options: nosniff');
                header('Cache-Control: no-store, no-cache, must-revalidate');
            }
            echo $json;
            exit(0);
        } catch (Throwable $e) {
            AP_Admin::addNotice('Export failed: ' . $e->getMessage(), 'error');
        }
    }
}

$ap_admin_title = 'Export';
$ap_admin_screen = 'export';
require __DIR__ . '/admin-header.php';
?>
<div class="ap-page-header">
    <h1>Export</h1>
</div>

<p>
    Download a full-site JSON export: posts, pages, media records, terms, comments, users,
    settings and forums. Password hashes, session tokens, secret-looking settings and
    private messages are never included. Uploaded files are not inside the export; copy
    <code>ap-content/uploads/</code> separately.
</p>

<form method="post" action="" class="ap-form">
    <?php echo ap_nonce_field('site-export', '_ap_nonce', false); ?>
    <p><button type="submit" class="ap-button ap-button--primary">Download export file</button></p>
</form>

<?php
require __DIR__ . '/admin-footer.php';
