<?php

/**
 * AgoraPress admin dashboard (minimal shell).
 *
 * Full stats widgets land in Phase 3; this screen links to Posts / Pages.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

require __DIR__ . '/admin-bootstrap.php';

AP_Admin::consumeQueryNotice();

$postCounts = [];
$pageCounts = [];
try {
    $db = ap_db();
    $table = $db->quoteIdentifier($db->table('posts'));
    foreach (['post' => &$postCounts, 'page' => &$pageCounts] as $type => &$bucket) {
        $rows = $db->getResults(
            'SELECT ' . $db->quoteIdentifier('post_status') . ' AS st, COUNT(*) AS cnt FROM '
            . $table . ' WHERE ' . $db->quoteIdentifier('post_type') . ' = ? GROUP BY '
            . $db->quoteIdentifier('post_status'),
            [$type]
        );
        $bucket = [];
        foreach ($rows as $row) {
            $data = is_array($row) ? $row : get_object_vars($row);
            $bucket[(string) ($data['st'] ?? '')] = (int) ($data['cnt'] ?? 0);
        }
    }
    unset($bucket);
} catch (Throwable $e) {
    // Tables may not exist yet on a half-installed site.
    $postCounts = [];
    $pageCounts = [];
}

$postsPublished = (int) ($postCounts['publish'] ?? 0);
$pagesPublished = (int) ($pageCounts['publish'] ?? 0);
$postsDraft = (int) ($postCounts['draft'] ?? 0);
$pagesDraft = (int) ($pageCounts['draft'] ?? 0);

$ap_admin_title = 'Dashboard';
$ap_admin_screen = 'dashboard';
require __DIR__ . '/admin-header.php';
?>
<div class="ap-page-header">
    <h1>Dashboard</h1>
</div>

<p>Welcome to the AgoraPress admin. Manage posts and pages from the menu, or jump in below.</p>

<?php
$postsListUrl = AP_Admin::url('edit.php', ['post_type' => 'post']);
$postsNewUrl = AP_Admin::url('post-new.php', ['post_type' => 'post']);
$pagesListUrl = AP_Admin::url('edit.php', ['post_type' => 'page']);
$pagesNewUrl = AP_Admin::url('post-new.php', ['post_type' => 'page']);
$cardStyle = 'display:grid;grid-template-columns:repeat(auto-fill,minmax(14rem,1fr));'
    . 'gap:1rem;margin-top:1.25rem;';
?>
<div class="ap-dashboard-cards" style="<?php echo ap_esc_attr($cardStyle); ?>">
    <div class="ap-metabox">
        <h3 class="ap-metabox-title">Posts</h3>
        <div class="ap-metabox-body">
            <p><strong><?php echo (int) $postsPublished; ?></strong> published</p>
            <p><strong><?php echo (int) $postsDraft; ?></strong> draft</p>
            <p>
                <a class="button" href="<?php echo ap_esc_url($postsListUrl); ?>">All Posts</a>
                <a class="button button-primary" href="<?php echo ap_esc_url($postsNewUrl); ?>">Add New</a>
            </p>
        </div>
    </div>
    <div class="ap-metabox">
        <h3 class="ap-metabox-title">Pages</h3>
        <div class="ap-metabox-body">
            <p><strong><?php echo (int) $pagesPublished; ?></strong> published</p>
            <p><strong><?php echo (int) $pagesDraft; ?></strong> draft</p>
            <p>
                <a class="button" href="<?php echo ap_esc_url($pagesListUrl); ?>">All Pages</a>
                <a class="button button-primary" href="<?php echo ap_esc_url($pagesNewUrl); ?>">Add New</a>
            </p>
        </div>
    </div>
</div>
<?php
require __DIR__ . '/admin-footer.php';
