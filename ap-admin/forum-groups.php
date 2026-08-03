<?php

/**
 * Forum user groups (`forum-groups.php`).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

require __DIR__ . '/admin-bootstrap.php';

AP_Admin::requireCapability('manage_forums');

if (!AP_Options::isModuleEnabled('forum')) {
    AP_Admin::denyAccess('The Forum module is disabled. Enable it under Settings → Modules.');
}

$groups = new AP_Admin_Forum_Groups();
$userId = ap_get_current_user_id();
$db = ap_db();

$action = (string) ($_REQUEST['action'] ?? '');
if ($action === '-1' || $action === '') {
    $action = (string) ($_REQUEST['action2'] ?? '');
}

// Delete via GET.
if ($action === 'delete' && isset($_GET['group'])) {
    $result = $groups->delete($_GET, $userId);
    AP_Admin::redirect(AP_Admin::url('forum-groups.php', [
        'message' => $result['message_key'],
    ]));
}

// POST: save group / add member.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $postAction = (string) ($_POST['action'] ?? '');
    if ($postAction === 'add-group' || $postAction === 'edit-group') {
        $result = $groups->save($_POST, $userId);
        if ($result['ok']) {
            AP_Admin::redirect(AP_Admin::url('forum-groups.php', [
                'action' => 'edit',
                'group' => $result['group_id'],
                'message' => $result['message_key'],
            ]));
        }
        foreach ($result['errors'] as $err) {
            AP_Admin::addNotice($err, 'error');
        }
    } elseif ($postAction === 'add-member') {
        $result = $groups->addMember($_POST, $userId);
        $gid = max(0, (int) ($_POST['group_id'] ?? 0));
        if ($result['ok']) {
            AP_Admin::redirect(AP_Admin::url('forum-groups.php', [
                'action' => 'edit',
                'group' => $gid,
                'message' => $result['message_key'],
            ]));
        }
        foreach ($result['errors'] as $err) {
            AP_Admin::addNotice($err, 'error');
        }
    }
}

AP_Admin::consumeQueryNotice();

$editId = max(0, (int) ($_GET['group'] ?? 0));
$editing = null;
if (($action === 'edit' || $editId > 0) && $editId > 0) {
    $editing = AP_Group::get($editId, $db);
    if ($editing === null) {
        AP_Admin::addNotice('That group could not be found.', 'error');
        $editId = 0;
    }
}

$groups->prepareItems($_GET);

$ap_admin_title = $editing !== null ? 'Edit Group' : 'Forum Groups';
$ap_admin_screen = 'forum-groups';
$ap_admin_body_class = 'ap-forum-groups-php';

require __DIR__ . '/admin-header.php';
?>
<div class="ap-page-header">
    <h1><?php echo ap_esc_html($ap_admin_title); ?></h1>
    <?php if ($editing === null) : ?>
        <a class="button button-primary" href="<?php echo ap_esc_url(AP_Admin::url('forum-groups.php', ['action' => 'new'])); ?>">
            Add New Group
        </a>
    <?php else : ?>
        <a class="button" href="<?php echo ap_esc_url(AP_Admin::url('forum-groups.php')); ?>">← All Groups</a>
    <?php endif; ?>
</div>

<?php if ($editing !== null || $action === 'new') : ?>
    <?php echo $groups->renderForm($editing, $userId); ?>
<?php else : ?>
    <p class="ap-help">System groups (guests, registered, administrators, global moderators) are seeded automatically.</p>
    <div class="ap-list-toolbar">
        <form method="get" action="" class="ap-search-form" role="search">
            <label class="screen-reader-text" for="group-search">Search groups</label>
            <input type="search" id="group-search" name="s"
                value="<?php echo ap_esc_attr($groups->search); ?>" placeholder="Search groups…">
            <button type="submit" class="button">Search</button>
        </form>
    </div>
    <?php echo $groups->renderList(); ?>
<?php endif; ?>

<?php
require __DIR__ . '/admin-footer.php';
