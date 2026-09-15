<?php

/**
 * Tests for admin forum screens: hierarchy, topics, moderation, groups, settings.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Admin;

use AP_Admin;
use AP_Admin_Forum_Edit;
use AP_Admin_Forum_Groups;
use AP_DB;
use AP_Forum;
use AP_Forum_Moderation;
use AP_Forum_Moderation_Queue;
use AP_Forum_Notify;
use AP_Forum_Permissions;
use AP_Forum_Topics_List_Table;
use AP_Forums_List_Table;
use AP_Group;
use AP_Migrator;
use AP_Nonce;
use AP_Options;
use AP_Roles;
use AP_Settings;
use AP_User;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_Admin_Forum_Edit::class)]
#[CoversClass(AP_Forums_List_Table::class)]
#[CoversClass(AP_Forum_Topics_List_Table::class)]
#[CoversClass(AP_Forum_Moderation_Queue::class)]
#[CoversClass(AP_Admin_Forum_Groups::class)]
final class AdminForumsTest extends TestCase
{
    private string $root;

    private AP_DB $db;

    private int $actorId = 0;

    private int $subscriberId = 0;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        require_once $this->root . '/ap-includes/version.php';
        require_once $this->root . '/ap-includes/class-ap-db.php';
        require_once $this->root . '/ap-includes/class-ap-migrator.php';
        require_once $this->root . '/ap-includes/class-ap-options.php';
        require_once $this->root . '/ap-includes/class-ap-settings.php';
        require_once $this->root . '/ap-includes/class-ap-user.php';
        require_once $this->root . '/ap-includes/class-ap-session.php';
        require_once $this->root . '/ap-includes/class-ap-roles.php';
        require_once $this->root . '/ap-includes/class-ap-nonce.php';
        require_once $this->root . '/ap-includes/class-ap-forum.php';
        require_once $this->root . '/ap-includes/class-ap-forum-moderation.php';
        require_once $this->root . '/ap-includes/class-ap-group.php';
        require_once $this->root . '/ap-includes/class-ap-forum-permissions.php';
        require_once $this->root . '/ap-includes/functions.php';
        require_once $this->root . '/ap-admin/includes/class-ap-admin.php';
        require_once $this->root . '/ap-admin/includes/class-ap-forums-list-table.php';
        require_once $this->root . '/ap-admin/includes/class-ap-admin-forum-edit.php';
        require_once $this->root . '/ap-admin/includes/class-ap-forum-topics-list-table.php';
        require_once $this->root . '/ap-admin/includes/class-ap-forum-moderation-queue.php';
        require_once $this->root . '/ap-admin/includes/class-ap-admin-forum-groups.php';

        if (!defined('AP_NONCE_KEY')) {
            define('AP_NONCE_KEY', 'test-nonce-key-' . str_repeat('n', 32));
        }
        if (!defined('AP_NONCE_SALT')) {
            define('AP_NONCE_SALT', 'test-nonce-salt-' . str_repeat('s', 32));
        }
        if (!defined('AP_LOGGED_IN_KEY')) {
            define('AP_LOGGED_IN_KEY', 'test-logged-in-key-' . str_repeat('a', 32));
        }
        if (!defined('AP_LOGGED_IN_SALT')) {
            define('AP_LOGGED_IN_SALT', 'test-logged-in-salt-' . str_repeat('b', 32));
        }

        AP_Roles::flushCache();
        AP_Options::flushCache();
        AP_Admin::clearNotices();
        if (class_exists('AP_Group', false)) {
            AP_Group::flushCache();
        }
        if (class_exists('AP_Forum_Permissions', false)) {
            AP_Forum_Permissions::flushCache();
        }
        if (class_exists('AP_Settings', false) && method_exists('AP_Settings', 'reset')) {
            AP_Settings::reset();
        }

        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');

        $migrator = new AP_Migrator($this->db, AP_Migrator::defaultMigrationsPath());
        $migrator->migrate();
        AP_Roles::ensureDefaults($this->db);
        AP_Settings::registerCore();
        AP_Options::update('module_forum', '1', $this->db);

        $admin = AP_User::create([
            'user_login' => 'forumadmin',
            'user_email' => 'forumadmin@example.test',
            'password' => 'password123',
            'role' => 'administrator',
        ], $this->db);
        $this->actorId = (int) $admin['id'];

        $sub = AP_User::create([
            'user_login' => 'forumsub',
            'user_email' => 'forumsub@example.test',
            'password' => 'password123',
            'role' => 'subscriber',
        ], $this->db);
        $this->subscriberId = (int) $sub['id'];
    }

    protected function tearDown(): void
    {
        AP_Roles::flushCache();
        AP_Options::flushCache();
        AP_Admin::clearNotices();
        if (class_exists('AP_Group', false)) {
            AP_Group::flushCache();
        }
        if (class_exists('AP_Forum_Permissions', false)) {
            AP_Forum_Permissions::flushCache();
        }
    }

    public function testScreenFilesAndCapsExist(): void
    {
        $files = [
            'forums.php' => 'manage_forums',
            'forum-edit.php' => 'manage_forums',
            'forum-topics.php' => 'moderate_forums',
            'forum-moderation.php' => 'moderate_forums',
            'forum-groups.php' => 'manage_forums',
            'options-forums.php' => 'manage_options',
        ];
        $map = AP_Admin::screenCapabilities();
        foreach ($files as $file => $cap) {
            $path = $this->root . '/ap-admin/' . $file;
            $this->assertFileExists($path);
            $src = (string) file_get_contents($path);
            $this->assertStringContainsString('requireCapability', $src, $file);
            $this->assertStringContainsString($cap, $src, $file);
            $this->assertStringContainsString('isModuleEnabled', $src, $file . ' should gate on forum module');
            $this->assertArrayHasKey($file, $map);
            $this->assertSame($cap, $map[$file]);
        }
    }

    public function testMenuIncludesForumItemsWhenModuleOn(): void
    {
        $items = AP_Admin::menuItems('', $this->db);
        $ids = array_column($items, 'id');
        foreach (['forums', 'forum-topics', 'forum-moderation', 'forum-groups', 'options-forums'] as $id) {
            $this->assertContains($id, $ids, "Menu should include {$id}");
        }
        $this->assertSame('Forums', AP_Admin::menuSectionLabel('forums'));
    }

    public function testCreateUpdateDeleteForum(): void
    {
        $nonce = ap_create_nonce('add-forum', $this->actorId);
        $created = AP_Admin_Forum_Edit::save([
            '_ap_nonce' => $nonce,
            'forum_name' => 'General Discussion',
            'forum_desc' => 'Talk about anything',
            'forum_type' => AP_Forum::FORUM_TYPE_CATEGORY,
            'forum_status' => AP_Forum::FORUM_STATUS_OPEN,
            'parent_id' => 0,
            'forum_order' => 5,
        ], $this->actorId, $this->db);

        $this->assertTrue($created['ok'], implode('; ', $created['errors']));
        $this->assertSame('forum_created', $created['message_key']);
        $id = $created['forum_id'];
        $this->assertGreaterThan(0, $id);

        $forum = AP_Forum::getForum($id, $this->db);
        $this->assertNotNull($forum);
        $this->assertSame('General Discussion', $forum->forum_name);
        $this->assertSame('category', $forum->forum_type);

        $editNonce = ap_create_nonce('edit-forum-' . $id, $this->actorId);
        $updated = AP_Admin_Forum_Edit::save([
            '_ap_nonce' => $editNonce,
            'forum_id' => $id,
            'forum_name' => 'General',
            'forum_desc' => 'Updated',
            'forum_type' => AP_Forum::FORUM_TYPE_FORUM,
            'forum_status' => AP_Forum::FORUM_STATUS_CLOSED,
            'parent_id' => 0,
            'forum_order' => 1,
            'forum_access_level' => AP_Forum_Permissions::ACCESS_MEMBERS_READONLY,
        ], $this->actorId, $this->db);
        $this->assertTrue($updated['ok']);
        $this->assertSame('forum_updated', $updated['message_key']);
        $forum = AP_Forum::getForum($id, $this->db);
        $this->assertSame('General', $forum?->forum_name);
        $this->assertSame('closed', $forum?->forum_status);
        $this->assertSame(
            AP_Forum_Permissions::ACCESS_MEMBERS_READONLY,
            AP_Forum_Permissions::detectAccessLevel($id, $this->db)
        );

        $delNonce = ap_create_nonce('delete-forum-' . $id, $this->actorId);
        $deleted = AP_Admin_Forum_Edit::delete([
            '_ap_nonce' => $delNonce,
            'forum' => $id,
        ], $this->actorId, $this->db);
        $this->assertTrue($deleted['ok']);
        $this->assertSame('forum_deleted', $deleted['message_key']);
        $this->assertNull(AP_Forum::getForum($id, $this->db));
    }

    public function testForumSaveRequiresCapabilityAndNonce(): void
    {
        $badNonce = AP_Admin_Forum_Edit::save([
            '_ap_nonce' => 'nope',
            'forum_name' => 'X',
        ], $this->actorId, $this->db);
        $this->assertFalse($badNonce['ok']);
        $this->assertSame('nonce', $badNonce['message_key']);

        $nonce = ap_create_nonce('add-forum', $this->subscriberId);
        $denied = AP_Admin_Forum_Edit::save([
            '_ap_nonce' => $nonce,
            'forum_name' => 'Nope',
        ], $this->subscriberId, $this->db);
        $this->assertFalse($denied['ok']);
        $this->assertStringContainsString('permission', implode(' ', $denied['errors']));
    }

    public function testForumsListTableHierarchyAndBulkDelete(): void
    {
        $cat = AP_Forum::insertForum([
            'forum_name' => 'Cat',
            'forum_type' => 'category',
        ], $this->db);
        $child = AP_Forum::insertForum([
            'forum_name' => 'Child Board',
            'parent_id' => $cat,
            'forum_type' => 'forum',
        ], $this->db);
        $this->assertGreaterThan(0, $child);

        $table = new AP_Forums_List_Table($this->db);
        $table->prepareItems([]);
        $this->assertGreaterThanOrEqual(2, $table->totalItems);

        $names = array_map(static fn (object $f): string => (string) $f->forum_name, $table->items);
        $this->assertContains('Cat', $names);
        $this->assertContains('Child Board', $names);

        $html = $table->render();
        $this->assertStringContainsString('Child Board', $html);
        $this->assertStringContainsString('Force delete', $html);
        $this->assertStringContainsString('name="forum[]"', $html);
        $this->assertStringContainsString('name="_ap_nonce"', $html);

        $form = AP_Admin_Forum_Edit::renderForm(null, $this->actorId, $this->db);
        $this->assertStringContainsString('forum_name', $form);
        $this->assertStringContainsString('Add Forum', $form);
        $this->assertStringContainsString('forum_access_level', $form);
        $this->assertStringContainsString('Visibility', $form);
        $this->assertStringContainsString('forum_perm[', $form);
        $this->assertStringContainsString('Members only', $form);
        $this->assertStringContainsString('Administrators only', $form);
        $this->assertStringContainsString('Guest', $form);
        $this->assertStringContainsString('Registered', $form);
        // Blog posts/pages are explicitly out of scope.
        $this->assertStringContainsString('blog posts and pages', strtolower($form));

        $this->assertStringContainsString('Access', $html);
        $this->assertStringContainsString('column-access', $html);

        $nonce = ap_create_nonce('bulk-forums', $this->actorId);
        $bulk = $table->processBulkAction([
            '_ap_nonce' => $nonce,
            'action' => 'force_delete',
            'forum' => [$child, $cat],
        ], $this->actorId);
        $this->assertTrue($bulk['ok']);
        $this->assertSame('bulk_forum_deleted', $bulk['message_key']);
        $this->assertSame(2, $bulk['count']);
    }

    public function testTopicsListTableLockAndBulkApprove(): void
    {
        $forumId = AP_Forum::insertForum(['forum_name' => 'Topics Forum'], $this->db);
        $topicId = AP_Forum::createTopic([
            'forum_id' => $forumId,
            'topic_title' => 'Hello world',
            'content' => 'First post body',
            'poster_id' => $this->actorId,
        ], $this->db);
        $this->assertGreaterThan(0, $topicId);

        // Create a pending topic for approval bulk path.
        $pendingId = AP_Forum::createTopic([
            'forum_id' => $forumId,
            'topic_title' => 'Needs review',
            'content' => 'Pending body',
            'poster_id' => $this->subscriberId,
            'topic_approved' => 0,
        ], $this->db);
        if ($pendingId < 1) {
            // Fallback: create then unapprove via moderation API.
            $pendingId = AP_Forum::createTopic([
                'forum_id' => $forumId,
                'topic_title' => 'Needs review',
                'content' => 'Pending body',
                'poster_id' => $this->subscriberId,
            ], $this->db);
            AP_Forum_Moderation::unapproveTopic($pendingId, $this->actorId, $this->db);
        }

        $table = new AP_Forum_Topics_List_Table($this->db);
        $table->prepareItems(['topic_status' => 'all']);
        $this->assertGreaterThanOrEqual(1, $table->totalItems);

        $html = $table->render();
        $this->assertStringContainsString('Hello world', $html);
        $this->assertStringContainsString('Make sticky', $html);
        $this->assertStringContainsString('name="topic[]"', $html);

        $views = $table->renderViews();
        $this->assertStringContainsString('Pending', $views);

        $lockNonce = ap_create_nonce('topic-lock-' . $topicId, $this->actorId);
        $locked = $table->processRowAction([
            'action' => 'lock',
            'topic' => $topicId,
            '_ap_nonce' => $lockNonce,
        ], $this->actorId);
        $this->assertTrue($locked['ok']);
        $this->assertSame('topic_locked', $locked['message_key']);
        $topic = AP_Forum::getTopic($topicId, $this->db);
        $this->assertSame('locked', $topic?->topic_status);

        $bulkNonce = ap_create_nonce('bulk-forum-topics', $this->actorId);
        $bulk = $table->processBulkAction([
            '_ap_nonce' => $bulkNonce,
            'action' => 'approve',
            'topic' => [$pendingId],
        ], $this->actorId);
        $this->assertTrue($bulk['ok'], implode('; ', $bulk['errors']));
        $this->assertSame('bulk_topic_approved', $bulk['message_key']);
    }

    public function testTopicsListTableMoveChromeFollowsDestRule(): void
    {
        $sourceId = AP_Forum::insertForum(['forum_name' => 'ACP Move Source'], $this->db);
        $topicId = AP_Forum::createTopic([
            'forum_id' => $sourceId,
            'topic_title' => 'ACP relocate chrome',
            'content' => 'Opening post',
            'poster_id' => $this->actorId,
        ], $this->db);
        $this->assertGreaterThan(0, $topicId);

        $loneTable = new AP_Forum_Topics_List_Table($this->db, $this->actorId);
        $loneTable->prepareItems(['forum_id' => $sourceId]);
        $loneHtml = $loneTable->render();
        $this->assertStringNotContainsString('Move to…', $loneHtml);
        $this->assertStringNotContainsString('class="move"', $loneHtml);

        $categoryId = AP_Forum::insertForum([
            'forum_name' => 'ACP Move Cat',
            'forum_type' => AP_Forum::FORUM_TYPE_CATEGORY,
        ], $this->db);
        $destId = AP_Forum::insertForum(['forum_name' => 'ACP Move Dest'], $this->db);
        $linkId = AP_Forum::insertForum([
            'forum_name' => 'ACP Move Link',
            'forum_type' => AP_Forum::FORUM_TYPE_LINK,
        ], $this->db);
        $this->assertGreaterThan(0, $categoryId);
        $this->assertGreaterThan(0, $destId);
        $this->assertGreaterThan(0, $linkId);

        $table = new AP_Forum_Topics_List_Table($this->db, $this->actorId);
        $table->prepareItems(['forum_id' => $sourceId]);
        $html = $table->render();

        $this->assertStringContainsString('Move to…', $html);
        $this->assertStringContainsString('name="dest_forum_id"', $html);
        $this->assertStringContainsString('name="dest_forum_id2"', $html);
        $this->assertStringContainsString('>ACP Move Dest</option>', $html);
        $this->assertStringNotContainsString('>ACP Move Source</option>', $html);
        $this->assertStringNotContainsString('>ACP Move Cat</option>', $html);
        $this->assertStringNotContainsString('>ACP Move Link</option>', $html);
        $this->assertStringContainsString('class="move"', $html);
        $this->assertStringContainsString('>Move</a>', $html);
        $this->assertStringContainsString('action=move', $html);
        $this->assertStringContainsString('ACP relocate chrome', $html);

        $deletedTable = new AP_Forum_Topics_List_Table($this->db, $this->actorId);
        $deletedTable->prepareItems(['topic_status' => 'deleted']);
        $this->assertSame(
            ['restore' => 'Restore', 'delete' => 'Delete permanently'],
            $deletedTable->getBulkActions()
        );

        $pickerNonce = ap_create_nonce('topic-move-' . $topicId, $this->actorId);
        $prepared = $table->prepareRowMovePicker([
            'action' => 'move',
            'topic' => $topicId,
            '_ap_nonce' => $pickerNonce,
        ], $this->actorId);
        $this->assertTrue($prepared['ok'], implode('; ', $prepared['errors']));
        $pickerHtml = $table->render();
        $this->assertStringContainsString('ap-topic-move-picker', $pickerHtml);
        $this->assertStringContainsString('ACP relocate chrome', $pickerHtml);
        $this->assertStringContainsString('name="dest_forum_id"', $pickerHtml);
        $this->assertStringContainsString('>ACP Move Dest</option>', $pickerHtml);
        $this->assertStringNotContainsString('>ACP Move Source</option>', $pickerHtml);
    }

    public function testTopicsListTableRowAndBulkMoveKeepsSlugAndCounters(): void
    {
        require_once $this->root . '/ap-includes/class-ap-forum-notify.php';

        $sourceId = AP_Forum::insertForum(['forum_name' => 'ACP Count Source'], $this->db);
        $destId = AP_Forum::insertForum(['forum_name' => 'ACP Count Dest'], $this->db);
        $topicId = AP_Forum::createTopic([
            'forum_id' => $sourceId,
            'topic_title' => 'Keep my slug',
            'content' => 'First',
            'poster_id' => $this->actorId,
        ], $this->db);
        AP_Forum::createReply([
            'topic_id' => $topicId,
            'content' => 'Second',
            'poster_id' => $this->actorId,
        ], $this->db);
        $secondId = AP_Forum::createTopic([
            'forum_id' => $sourceId,
            'topic_title' => 'Bulk companion',
            'content' => 'Also moving',
            'poster_id' => $this->actorId,
        ], $this->db);
        $this->assertGreaterThan(0, $topicId);
        $this->assertGreaterThan(0, $secondId);

        $before = AP_Forum::getTopic($topicId, $this->db);
        $this->assertNotNull($before);
        $slug = (string) ($before->topic_slug ?? '');
        $this->assertNotSame('', $slug);

        $this->assertTrue(AP_Forum_Notify::subscribe($this->actorId, $topicId, $this->db));

        $table = new AP_Forum_Topics_List_Table($this->db, $this->actorId);
        $rowNonce = ap_create_nonce('topic-move-' . $topicId, $this->actorId);
        $moved = $table->processRowAction([
            'action' => 'move',
            'topic' => $topicId,
            'dest_forum_id' => $destId,
            '_ap_nonce' => $rowNonce,
        ], $this->actorId);
        $this->assertTrue($moved['ok'], implode('; ', $moved['errors']));
        $this->assertSame('topic_moved', $moved['message_key']);

        $after = AP_Forum::getTopic($topicId, $this->db);
        $this->assertNotNull($after);
        $this->assertSame($topicId, (int) $after->topic_id);
        $this->assertSame($destId, (int) $after->forum_id);
        $this->assertSame($slug, (string) ($after->topic_slug ?? ''));
        $this->assertNotSame(AP_Forum::TOPIC_STATUS_MOVED, (string) ($after->topic_status ?? ''));
        $this->assertTrue(AP_Forum_Notify::isSubscribed($this->actorId, $topicId, $this->db));

        $source = AP_Forum::getForum($sourceId, $this->db);
        $dest = AP_Forum::getForum($destId, $this->db);
        $this->assertSame(1, (int) ($source->topic_count ?? -1));
        $this->assertSame(1, (int) ($source->post_count ?? -1));
        $this->assertSame($secondId, (int) ($source->last_topic_id ?? 0));
        $this->assertSame(1, (int) ($dest->topic_count ?? 0));
        $this->assertSame(2, (int) ($dest->post_count ?? 0));
        $this->assertSame($topicId, (int) ($dest->last_topic_id ?? 0));

        $bulkNonce = ap_create_nonce('bulk-forum-topics', $this->actorId);
        $bulk = $table->processBulkAction([
            '_ap_nonce' => $bulkNonce,
            'action' => '-1',
            'action2' => 'move',
            'dest_forum_id2' => $destId,
            'topic' => [$secondId],
        ], $this->actorId);
        $this->assertTrue($bulk['ok'], implode('; ', $bulk['errors']));
        $this->assertSame('bulk_topic_moved', $bulk['message_key']);
        $this->assertSame(1, $bulk['count']);

        $second = AP_Forum::getTopic($secondId, $this->db);
        $this->assertSame($destId, (int) ($second->forum_id ?? 0));
        $sourceAfter = AP_Forum::getForum($sourceId, $this->db);
        $destAfter = AP_Forum::getForum($destId, $this->db);
        $this->assertSame(0, (int) ($sourceAfter->topic_count ?? -1));
        $this->assertSame(0, (int) ($sourceAfter->post_count ?? -1));
        $this->assertSame(0, (int) ($sourceAfter->last_topic_id ?? -1));
        $this->assertSame(2, (int) ($destAfter->topic_count ?? 0));
        $this->assertSame(3, (int) ($destAfter->post_count ?? 0));
    }

    public function testTopicsListTableMoveRefusesCategoryCurrentAndDestCap(): void
    {
        AP_Group::ensureSystemGroups($this->db);
        AP_Forum_Permissions::ensureDefaults($this->db);

        $categoryId = AP_Forum::insertForum([
            'forum_name' => 'ACP Refuse Cat',
            'forum_type' => AP_Forum::FORUM_TYPE_CATEGORY,
        ], $this->db);
        $sourceId = AP_Forum::insertForum(['forum_name' => 'ACP Refuse Source'], $this->db);
        $destId = AP_Forum::insertForum(['forum_name' => 'ACP Refuse Dest'], $this->db);
        $restrictedId = AP_Forum::insertForum(['forum_name' => 'ACP Refuse Restricted'], $this->db);
        $this->assertGreaterThan(0, $categoryId);
        $this->assertGreaterThan(0, $restrictedId);

        $vipId = AP_Group::create(['group_name' => 'ACP Move VIP'], $this->db);
        $this->assertGreaterThan(0, $vipId);
        $this->assertTrue(AP_Forum_Permissions::saveAccessFromForm($restrictedId, [
            'forum_access_level' => AP_Forum_Permissions::ACCESS_GROUP_ONLY,
            'forum_access_groups' => [$vipId],
        ], $this->db));
        AP_Forum_Permissions::flushCache();

        $topicId = AP_Forum::createTopic([
            'forum_id' => $sourceId,
            'topic_title' => 'Stay unless allowed',
            'content' => 'Body',
            'poster_id' => $this->actorId,
        ], $this->db);
        $slug = (string) (AP_Forum::getTopic($topicId, $this->db)->topic_slug ?? '');
        $this->assertNotSame('', $slug);

        $table = new AP_Forum_Topics_List_Table($this->db, $this->actorId);

        $noDest = $table->processRowAction([
            'action' => 'move',
            'topic' => $topicId,
            '_ap_nonce' => ap_create_nonce('topic-move-' . $topicId, $this->actorId),
        ], $this->actorId);
        $this->assertFalse($noDest['ok']);
        $this->assertSame('error', $noDest['message_key']);
        $this->assertSame($sourceId, (int) (AP_Forum::getTopic($topicId, $this->db)->forum_id ?? 0));

        $cat = $table->processRowAction([
            'action' => 'move',
            'topic' => $topicId,
            'dest_forum_id' => $categoryId,
            '_ap_nonce' => ap_create_nonce('topic-move-' . $topicId, $this->actorId),
        ], $this->actorId);
        $this->assertFalse($cat['ok']);
        $this->assertSame($sourceId, (int) (AP_Forum::getTopic($topicId, $this->db)->forum_id ?? 0));
        $this->assertSame($slug, (string) (AP_Forum::getTopic($topicId, $this->db)->topic_slug ?? ''));

        $same = $table->processRowAction([
            'action' => 'move',
            'topic' => $topicId,
            'dest_forum_id' => $sourceId,
            '_ap_nonce' => ap_create_nonce('topic-move-' . $topicId, $this->actorId),
        ], $this->actorId);
        $this->assertFalse($same['ok']);
        $this->assertSame($sourceId, (int) (AP_Forum::getTopic($topicId, $this->db)->forum_id ?? 0));

        $bulkNoDest = $table->processBulkAction([
            '_ap_nonce' => ap_create_nonce('bulk-forum-topics', $this->actorId),
            'action' => 'move',
            'topic' => [$topicId],
        ], $this->actorId);
        $this->assertFalse($bulkNoDest['ok']);
        $this->assertSame('error', $bulkNoDest['message_key']);

        $bulkCat = $table->processBulkAction([
            '_ap_nonce' => ap_create_nonce('bulk-forum-topics', $this->actorId),
            'action' => 'move',
            'dest_forum_id' => $categoryId,
            'topic' => [$topicId],
        ], $this->actorId);
        $this->assertFalse($bulkCat['ok']);
        $this->assertNotSame([], $bulkCat['errors']);
        $this->assertSame($sourceId, (int) (AP_Forum::getTopic($topicId, $this->db)->forum_id ?? 0));

        $subDenied = $table->processRowAction([
            'action' => 'move',
            'topic' => $topicId,
            'dest_forum_id' => $destId,
            '_ap_nonce' => ap_create_nonce('topic-move-' . $topicId, $this->subscriberId),
        ], $this->subscriberId);
        $this->assertFalse($subDenied['ok']);
        $this->assertSame($sourceId, (int) (AP_Forum::getTopic($topicId, $this->db)->forum_id ?? 0));

        $editor = AP_User::create([
            'user_login' => 'acpmoveeditor',
            'user_email' => 'acpmoveeditor@example.test',
            'password' => 'password123',
            'role' => 'editor',
        ], $this->db);
        $editorId = (int) ($editor['id'] ?? 0);
        $this->assertGreaterThan(0, $editorId);
        $this->assertTrue(AP_Admin::userCan($editorId, 'moderate_forums', null, $this->db));
        $this->assertFalse(AP_Forum_Permissions::userCanModerate($editorId, $restrictedId, $this->db));
        $this->assertTrue(AP_Forum_Permissions::userCanModerate($editorId, $destId, $this->db));

        $editorTable = new AP_Forum_Topics_List_Table($this->db, $editorId);
        $editorTable->prepareItems(['forum_id' => $sourceId]);
        $editorHtml = $editorTable->render();
        $this->assertStringContainsString('>ACP Refuse Dest</option>', $editorHtml);
        $this->assertStringNotContainsString('>ACP Refuse Restricted</option>', $editorHtml);
        $this->assertStringNotContainsString('>ACP Refuse Source</option>', $editorHtml);
        $this->assertStringNotContainsString('>ACP Refuse Cat</option>', $editorHtml);

        $missingCap = $editorTable->processRowAction([
            'action' => 'move',
            'topic' => $topicId,
            'dest_forum_id' => $restrictedId,
            '_ap_nonce' => ap_create_nonce('topic-move-' . $topicId, $editorId),
        ], $editorId);
        $this->assertFalse($missingCap['ok']);
        $this->assertSame($sourceId, (int) (AP_Forum::getTopic($topicId, $this->db)->forum_id ?? 0));
        $this->assertSame($slug, (string) (AP_Forum::getTopic($topicId, $this->db)->topic_slug ?? ''));

        $ok = $editorTable->processRowAction([
            'action' => 'move',
            'topic' => $topicId,
            'dest_forum_id' => $destId,
            '_ap_nonce' => ap_create_nonce('topic-move-' . $topicId, $editorId),
        ], $editorId);
        $this->assertTrue($ok['ok'], implode('; ', $ok['errors']));
        $this->assertSame($destId, (int) (AP_Forum::getTopic($topicId, $this->db)->forum_id ?? 0));
        $this->assertSame($slug, (string) (AP_Forum::getTopic($topicId, $this->db)->topic_slug ?? ''));

        $gone = AP_Forum::createTopic([
            'forum_id' => $sourceId,
            'topic_title' => 'Deleted cannot move',
            'content' => 'Bye',
            'poster_id' => $this->actorId,
        ], $this->db);
        $this->assertTrue(AP_Forum_Moderation::softDeleteTopic($gone, $this->actorId, $this->db));
        $deletedMove = $table->processRowAction([
            'action' => 'move',
            'topic' => $gone,
            'dest_forum_id' => $destId,
            '_ap_nonce' => ap_create_nonce('topic-move-' . $gone, $this->actorId),
        ], $this->actorId);
        $this->assertFalse($deletedMove['ok']);
        $deleted = AP_Forum::getTopic($gone, $this->db);
        $this->assertSame($sourceId, (int) ($deleted->forum_id ?? 0));
        $this->assertSame(AP_Forum::TOPIC_STATUS_DELETED, (string) ($deleted->topic_status ?? ''));
    }

    public function testTopicsListTableBulkMergeChromeFollowsTargetRule(): void
    {
        $forumId = AP_Forum::insertForum(['forum_name' => 'ACP Merge Chrome Forum'], $this->db);
        $loneId = AP_Forum::createTopic([
            'forum_id' => $forumId,
            'topic_title' => 'ACP merge lone',
            'content' => 'Only thread',
            'poster_id' => $this->actorId,
        ], $this->db);
        $this->assertGreaterThan(0, $loneId);

        $loneTable = new AP_Forum_Topics_List_Table($this->db, $this->actorId);
        $loneTable->prepareItems(['forum_id' => $forumId]);
        $loneHtml = $loneTable->render();
        $this->assertStringNotContainsString('Merge into…', $loneHtml);
        $this->assertStringNotContainsString('name="target_topic_id"', $loneHtml);

        $keepId = AP_Forum::createTopic([
            'forum_id' => $forumId,
            'topic_title' => 'ACP merge keep',
            'content' => 'Keep body',
            'poster_id' => $this->actorId,
        ], $this->db);
        $this->assertGreaterThan(0, $keepId);

        $table = new AP_Forum_Topics_List_Table($this->db, $this->actorId);
        $table->prepareItems(['forum_id' => $forumId]);
        $html = $table->render();
        $this->assertStringContainsString('Merge into…', $html);
        $this->assertStringContainsString('name="target_topic_id"', $html);
        $this->assertStringContainsString('name="target_topic_id2"', $html);
        $this->assertStringContainsString('ACP merge lone — ACP Merge Chrome Forum', $html);
        $this->assertStringContainsString('ACP merge keep — ACP Merge Chrome Forum', $html);
        $this->assertStringContainsString('class="ap-merge-target"', $html);

        $deletedTable = new AP_Forum_Topics_List_Table($this->db, $this->actorId);
        $deletedTable->prepareItems(['topic_status' => 'deleted']);
        $this->assertArrayNotHasKey('merge', $deletedTable->getBulkActions());
    }

    public function testTopicsListTableBulkMergePostsSubsAndLastPost(): void
    {
        require_once $this->root . '/ap-includes/class-ap-forum-notify.php';

        $sourceForumId = AP_Forum::insertForum(['forum_name' => 'ACP Merge Source'], $this->db);
        $targetForumId = AP_Forum::insertForum(['forum_name' => 'ACP Merge Target'], $this->db);
        $stayId = AP_Forum::createTopic([
            'forum_id' => $sourceForumId,
            'topic_title' => 'Stays on source board',
            'content' => 'Older source board post',
            'poster_id' => $this->actorId,
        ], $this->db);
        $targetId = AP_Forum::createTopic([
            'forum_id' => $targetForumId,
            'topic_title' => 'Keep as merge target',
            'content' => 'Target OP',
            'poster_id' => $this->actorId,
        ], $this->db);
        $sourceId = AP_Forum::createTopic([
            'forum_id' => $sourceForumId,
            'topic_title' => 'Absorb me bulk',
            'content' => 'Source OP',
            'poster_id' => $this->actorId,
        ], $this->db);
        $secondId = AP_Forum::createTopic([
            'forum_id' => $sourceForumId,
            'topic_title' => 'Absorb me too',
            'content' => 'Second source OP',
            'poster_id' => $this->actorId,
        ], $this->db);
        $sourceReply = AP_Forum::createReply([
            'topic_id' => $sourceId,
            'content' => 'Newest source reply',
            'poster_id' => $this->subscriberId,
        ], $this->db);
        $this->assertGreaterThan(0, $stayId);
        $this->assertGreaterThan(0, $targetId);
        $this->assertGreaterThan(0, $sourceId);
        $this->assertGreaterThan(0, $secondId);
        $this->assertGreaterThan(0, $sourceReply);

        $dup = AP_User::create([
            'user_login' => 'acpmergedup',
            'user_email' => 'acpmergedup@example.test',
            'password' => 'password123',
            'role' => 'subscriber',
        ], $this->db);
        $srcOnly = AP_User::create([
            'user_login' => 'acpmergesrc',
            'user_email' => 'acpmergesrc@example.test',
            'password' => 'password123',
            'role' => 'subscriber',
        ], $this->db);
        $tgtOnly = AP_User::create([
            'user_login' => 'acpmergetgt',
            'user_email' => 'acpmergetgt@example.test',
            'password' => 'password123',
            'role' => 'subscriber',
        ], $this->db);
        $dupId = (int) ($dup['id'] ?? 0);
        $srcOnlyId = (int) ($srcOnly['id'] ?? 0);
        $tgtOnlyId = (int) ($tgtOnly['id'] ?? 0);
        $this->assertGreaterThan(0, $dupId);
        $this->assertTrue(AP_Forum_Notify::subscribe($dupId, $sourceId, $this->db));
        $this->assertTrue(AP_Forum_Notify::subscribe($dupId, $targetId, $this->db));
        $this->assertTrue(AP_Forum_Notify::subscribe($srcOnlyId, $sourceId, $this->db));
        $this->assertTrue(AP_Forum_Notify::subscribe($tgtOnlyId, $targetId, $this->db));

        $table = new AP_Forum_Topics_List_Table($this->db, $this->actorId);
        $bulk = $table->processBulkAction([
            '_ap_nonce' => ap_create_nonce('bulk-forum-topics', $this->actorId),
            'action' => '-1',
            'action2' => 'merge',
            'target_topic_id2' => $targetId,
            'topic' => [$sourceId, $secondId, $targetId],
        ], $this->actorId);
        $this->assertTrue($bulk['ok'], implode('; ', $bulk['errors']));
        $this->assertSame('topics_merged', $bulk['message_key']);
        $this->assertSame(2, $bulk['count']);
        $targetAfter = AP_Forum::getTopic($targetId, $this->db);
        $this->assertNotNull($targetAfter);
        $mergedRedirect = AP_Forum::topicUrlWithNotice($targetAfter, 'topics_merged');
        $this->assertSame($mergedRedirect, (string) ($bulk['redirect'] ?? ''));
        $this->assertStringContainsString('ap_forum_notice=topics_merged', $mergedRedirect);
        $this->assertSame($mergedRedirect, AP_Admin::sanitizeRedirect($mergedRedirect));

        $this->assertNull(AP_Forum::getTopic($sourceId, $this->db));
        $this->assertNull(AP_Forum::getTopic($secondId, $this->db));
        $this->assertNotNull(AP_Forum::getTopic($stayId, $this->db));
        $this->assertNotNull(AP_Forum::getTopic($targetId, $this->db));

        $posts = AP_Forum::getPosts($targetId, ['approved_only' => false], $this->db);
        $this->assertCount(4, $posts);
        $postIds = array_map(static fn ($p) => (int) $p->post_id, $posts);
        $this->assertContains($sourceReply, $postIds);
        foreach ($posts as $post) {
            $this->assertSame($targetForumId, (int) $post->forum_id);
            $this->assertSame($targetId, (int) $post->topic_id);
        }

        $sourceForum = AP_Forum::getForum($sourceForumId, $this->db);
        $targetForum = AP_Forum::getForum($targetForumId, $this->db);
        $this->assertSame(1, (int) ($sourceForum?->topic_count ?? -1));
        $this->assertSame(1, (int) ($sourceForum?->post_count ?? -1));
        $this->assertSame($stayId, (int) ($sourceForum?->last_topic_id ?? 0));
        $this->assertSame(1, (int) ($targetForum?->topic_count ?? -1));
        $this->assertSame(4, (int) ($targetForum?->post_count ?? -1));
        $this->assertSame($targetId, (int) ($targetForum?->last_topic_id ?? 0));
        $this->assertSame($sourceReply, (int) ($targetForum?->last_post_id ?? 0));
        $this->assertSame($this->subscriberId, (int) ($targetForum?->last_poster_id ?? 0));

        $this->assertFalse(AP_Forum_Notify::isSubscribed($dupId, $sourceId, $this->db));
        $this->assertTrue(AP_Forum_Notify::isSubscribed($dupId, $targetId, $this->db));
        $this->assertTrue(AP_Forum_Notify::isSubscribed($srcOnlyId, $targetId, $this->db));
        $this->assertTrue(AP_Forum_Notify::isSubscribed($tgtOnlyId, $targetId, $this->db));
    }

    public function testTopicsListTableBulkMergeRefusesMissingTargetAndCap(): void
    {
        AP_Group::ensureSystemGroups($this->db);
        AP_Forum_Permissions::ensureDefaults($this->db);

        $sourceId = AP_Forum::insertForum(['forum_name' => 'ACP Merge Refuse Source'], $this->db);
        $destId = AP_Forum::insertForum(['forum_name' => 'ACP Merge Refuse Dest'], $this->db);
        $restrictedId = AP_Forum::insertForum(['forum_name' => 'ACP Merge Refuse Restricted'], $this->db);
        $this->assertGreaterThan(0, $restrictedId);

        $vipId = AP_Group::create(['group_name' => 'ACP Merge VIP'], $this->db);
        $this->assertGreaterThan(0, $vipId);
        $this->assertTrue(AP_Forum_Permissions::saveAccessFromForm($restrictedId, [
            'forum_access_level' => AP_Forum_Permissions::ACCESS_GROUP_ONLY,
            'forum_access_groups' => [$vipId],
        ], $this->db));
        AP_Forum_Permissions::flushCache();

        $sourceTopicId = AP_Forum::createTopic([
            'forum_id' => $sourceId,
            'topic_title' => 'Stay unless allowed merge',
            'content' => 'Body',
            'poster_id' => $this->actorId,
        ], $this->db);
        $targetTopicId = AP_Forum::createTopic([
            'forum_id' => $destId,
            'topic_title' => 'Allowed merge target',
            'content' => 'Keep',
            'poster_id' => $this->actorId,
        ], $this->db);
        $restrictedTopicId = AP_Forum::createTopic([
            'forum_id' => $restrictedId,
            'topic_title' => 'Restricted merge target',
            'content' => 'Hidden keep',
            'poster_id' => $this->actorId,
        ], $this->db, ['check_open' => false]);
        $this->assertGreaterThan(0, $sourceTopicId);
        $this->assertGreaterThan(0, $targetTopicId);
        $this->assertGreaterThan(0, $restrictedTopicId);

        $table = new AP_Forum_Topics_List_Table($this->db, $this->actorId);

        $noTarget = $table->processBulkAction([
            '_ap_nonce' => ap_create_nonce('bulk-forum-topics', $this->actorId),
            'action' => 'merge',
            'topic' => [$sourceTopicId],
        ], $this->actorId);
        $this->assertFalse($noTarget['ok']);
        $this->assertSame('error', $noTarget['message_key']);
        $this->assertNotNull(AP_Forum::getTopic($sourceTopicId, $this->db));

        $onlyTarget = $table->processBulkAction([
            '_ap_nonce' => ap_create_nonce('bulk-forum-topics', $this->actorId),
            'action' => 'merge',
            'target_topic_id' => $targetTopicId,
            'topic' => [$targetTopicId],
        ], $this->actorId);
        $this->assertFalse($onlyTarget['ok']);
        $this->assertSame('error', $onlyTarget['message_key']);
        $this->assertNotNull(AP_Forum::getTopic($targetTopicId, $this->db));

        $subDenied = $table->processBulkAction([
            '_ap_nonce' => ap_create_nonce('bulk-forum-topics', $this->subscriberId),
            'action' => 'merge',
            'target_topic_id' => $targetTopicId,
            'topic' => [$sourceTopicId],
        ], $this->subscriberId);
        $this->assertFalse($subDenied['ok']);
        $this->assertNotNull(AP_Forum::getTopic($sourceTopicId, $this->db));

        $editor = AP_User::create([
            'user_login' => 'acpmergeeditor',
            'user_email' => 'acpmergeeditor@example.test',
            'password' => 'password123',
            'role' => 'editor',
        ], $this->db);
        $editorId = (int) ($editor['id'] ?? 0);
        $this->assertGreaterThan(0, $editorId);
        $this->assertTrue(AP_Admin::userCan($editorId, 'moderate_forums', null, $this->db));
        $this->assertFalse(AP_Forum_Permissions::userCanModerate($editorId, $restrictedId, $this->db));
        $this->assertTrue(AP_Forum_Permissions::userCanModerate($editorId, $destId, $this->db));

        $editorTable = new AP_Forum_Topics_List_Table($this->db, $editorId);
        $editorTable->prepareItems([]);
        $editorHtml = $editorTable->render();
        $this->assertStringContainsString(
            '>Allowed merge target — ACP Merge Refuse Dest</option>',
            $editorHtml
        );
        $this->assertStringNotContainsString(
            '>Restricted merge target — ACP Merge Refuse Restricted</option>',
            $editorHtml
        );

        $missingCap = $editorTable->processBulkAction([
            '_ap_nonce' => ap_create_nonce('bulk-forum-topics', $editorId),
            'action' => 'merge',
            'target_topic_id' => $restrictedTopicId,
            'topic' => [$sourceTopicId],
        ], $editorId);
        $this->assertFalse($missingCap['ok']);
        $this->assertNotNull(AP_Forum::getTopic($sourceTopicId, $this->db));

        $ok = $editorTable->processBulkAction([
            '_ap_nonce' => ap_create_nonce('bulk-forum-topics', $editorId),
            'action' => 'merge',
            'target_topic_id' => $targetTopicId,
            'topic' => [$sourceTopicId],
        ], $editorId);
        $this->assertTrue($ok['ok'], implode('; ', $ok['errors']));
        $this->assertSame('topics_merged', $ok['message_key']);
        $this->assertNull(AP_Forum::getTopic($sourceTopicId, $this->db));
        $keptTarget = AP_Forum::getTopic($targetTopicId, $this->db);
        $this->assertNotNull($keptTarget);
        $this->assertSame(
            AP_Forum::topicUrlWithNotice($keptTarget, 'topics_merged'),
            (string) ($ok['redirect'] ?? '')
        );
        $this->assertSame('', (string) ($noTarget['redirect'] ?? ''));
        $this->assertSame('', (string) ($onlyTarget['redirect'] ?? ''));
        $this->assertSame('', (string) ($missingCap['redirect'] ?? ''));
    }

    public function testModerationQueueApproveTopicAndResolveReport(): void
    {
        $forumId = AP_Forum::insertForum(['forum_name' => 'Mod Queue'], $this->db);
        $topicId = AP_Forum::createTopic([
            'forum_id' => $forumId,
            'topic_title' => 'Pending topic',
            'content' => 'Awaiting approval',
            'poster_id' => $this->subscriberId,
        ], $this->db);
        AP_Forum_Moderation::unapproveTopic($topicId, $this->actorId, $this->db);

        $replyId = AP_Forum::createReply([
            'topic_id' => $topicId,
            'content' => 'Pending reply text',
            'poster_id' => $this->subscriberId,
        ], $this->db);
        AP_Forum_Moderation::unapprovePost($replyId, $this->actorId, $this->db);

        $reportId = AP_Forum_Moderation::createReport([
            'reporter_id' => $this->subscriberId,
            'type' => 'topic',
            'object_id' => $topicId,
            'reason' => 'spam',
            'details' => 'Looks spammy',
        ], $this->db);
        $this->assertGreaterThan(0, $reportId);

        $queue = new AP_Forum_Moderation_Queue($this->db);
        $queue->prepare(['view' => 'pending']);
        $this->assertSame('pending', $queue->view);
        $this->assertGreaterThanOrEqual(1, $queue->pendingTopicCount);
        $this->assertGreaterThanOrEqual(1, $queue->pendingPostCount);

        $pendingHtml = $queue->render();
        $this->assertStringContainsString('Pending topic', $pendingHtml);
        $this->assertStringContainsString('Pending replies', $pendingHtml);

        $approveNonce = ap_create_nonce('mod-approve_topic-' . $topicId, $this->actorId);
        $approved = $queue->processAction([
            'action' => 'approve_topic',
            'topic' => $topicId,
            '_ap_nonce' => $approveNonce,
        ], $this->actorId);
        $this->assertTrue($approved['ok']);
        $this->assertSame('topic_approved', $approved['message_key']);

        $postNonce = ap_create_nonce('mod-approve_post-' . $replyId, $this->actorId);
        $postOk = $queue->processAction([
            'action' => 'approve_post',
            'post' => $replyId,
            '_ap_nonce' => $postNonce,
        ], $this->actorId);
        $this->assertTrue($postOk['ok']);
        $this->assertSame('forum_post_approved', $postOk['message_key']);

        $queue->prepare(['view' => 'reports']);
        $this->assertSame('reports', $queue->view);
        $this->assertGreaterThanOrEqual(1, $queue->openReportCount);
        $reportsHtml = $queue->render();
        $this->assertStringContainsString('spam', $reportsHtml);

        $resolveNonce = ap_create_nonce('mod-resolve_report-' . $reportId, $this->actorId);
        $resolved = $queue->processAction([
            'action' => 'resolve_report',
            'report' => $reportId,
            '_ap_nonce' => $resolveNonce,
        ], $this->actorId);
        $this->assertTrue($resolved['ok']);
        $this->assertSame('report_resolved', $resolved['message_key']);
    }

    public function testForumGroupsCreateAndList(): void
    {
        $groups = new AP_Admin_Forum_Groups($this->db);
        $groups->prepareItems([]);
        $this->assertGreaterThanOrEqual(1, $groups->totalItems);

        $listHtml = $groups->renderList();
        $this->assertStringContainsString('group', strtolower($listHtml));

        $nonce = ap_create_nonce('add-group', $this->actorId);
        $created = $groups->save([
            '_ap_nonce' => $nonce,
            'group_name' => 'VIP Members',
            'group_desc' => 'Special access',
            'group_type' => AP_Group::TYPE_OPEN,
        ], $this->actorId);
        $this->assertTrue($created['ok'], implode('; ', $created['errors']));
        $this->assertSame('group_created', $created['message_key']);
        $gid = $created['group_id'];
        $this->assertGreaterThan(0, $gid);

        $group = AP_Group::get($gid, $this->db);
        $this->assertNotNull($group);
        $this->assertSame('VIP Members', $group->group_name);

        $form = $groups->renderForm($group, $this->actorId);
        $this->assertStringContainsString('VIP Members', $form);

        $delNonce = ap_create_nonce('delete-group-' . $gid, $this->actorId);
        $deleted = $groups->delete([
            '_ap_nonce' => $delNonce,
            'group' => $gid,
        ], $this->actorId);
        $this->assertTrue($deleted['ok']);
        $this->assertSame('group_deleted', $deleted['message_key']);
    }

    public function testForumGroupsAcpCreatesHiddenNamedGroupAndAddsMember(): void
    {
        $groups = new AP_Admin_Forum_Groups($this->db);
        $nonce = ap_create_nonce('add-group', $this->actorId);
        $created = $groups->save([
            '_ap_nonce' => $nonce,
            'group_name' => 'Staff Circle',
            'group_desc' => 'Hidden roster',
            'group_type' => AP_Group::TYPE_HIDDEN,
        ], $this->actorId);
        $this->assertTrue($created['ok'], implode('; ', $created['errors']));
        $gid = $created['group_id'];
        $this->assertGreaterThan(0, $gid);

        $group = AP_Group::get($gid, $this->db);
        $this->assertNotNull($group);
        $this->assertSame(AP_Group::TYPE_HIDDEN, (string) $group->group_type);

        $form = $groups->renderForm($group, $this->actorId);
        $this->assertStringContainsString('Open', $form);
        $this->assertStringContainsString('Closed', $form);
        $this->assertStringContainsString('Hidden', $form);
        $this->assertStringContainsString('Members', $form);
        $this->assertStringContainsString('no Join control', $form);
        $this->assertStringContainsString('Forums → Groups is the roster', $form);
        $this->assertStringContainsString('Add Member', $form);
        $this->assertStringNotContainsString('Join this group', $form);
        $this->assertStringNotContainsString('name="ap_forum_action"', $form);
        $this->assertFalse(AP_Group::allowsPublicJoin($gid, $this->db));
        $this->assertSame(0, AP_Group::joinPublic($gid, $this->subscriberId, $this->db));
        $this->assertFalse(AP_Group::isMember($gid, $this->subscriberId, $this->db));

        $memberNonce = ap_create_nonce('add-group-member-' . $gid, $this->actorId);
        $added = $groups->addMember([
            '_ap_nonce' => $memberNonce,
            'group_id' => $gid,
            'user_id' => $this->subscriberId,
            'member_role' => AP_Group::ROLE_MEMBER,
        ], $this->actorId);
        $this->assertTrue($added['ok'], implode('; ', $added['errors']));
        $this->assertSame('group_member_added', $added['message_key']);
        $this->assertTrue(AP_Group::isMember($gid, $this->subscriberId, $this->db));
    }

    public function testForumEditThisGroupOnlyPresetListsNamedGroups(): void
    {
        AP_Group::ensureSystemGroups($this->db);
        $namedId = AP_Group::create([
            'group_name' => 'Lounge VIP',
            'group_type' => AP_Group::TYPE_CLOSED,
        ], $this->db);
        $this->assertGreaterThan(0, $namedId);
        $hiddenId = AP_Group::create([
            'group_name' => 'Staff Circle',
            'group_type' => AP_Group::TYPE_HIDDEN,
        ], $this->db);
        $this->assertGreaterThan(0, $hiddenId);

        $guests = AP_Group::getBySlug(AP_Group::SLUG_GUESTS, $this->db);
        $registered = AP_Group::getBySlug(AP_Group::SLUG_REGISTERED, $this->db);
        $this->assertNotNull($guests);
        $this->assertNotNull($registered);

        $form = AP_Admin_Forum_Edit::renderForm(null, $this->actorId, $this->db);
        $this->assertStringContainsString('Members only', $form);
        $this->assertStringContainsString('Moderators only', $form);
        $this->assertStringContainsString('Administrators only', $form);
        $this->assertStringContainsString('This group only', $form);
        $this->assertStringContainsString('value="group_only"', $form);
        $this->assertStringContainsString('Custom', $form);
        $this->assertStringContainsString('forum_access_groups[]', $form);
        $this->assertStringContainsString('Lounge VIP', $form);
        $this->assertStringContainsString('Staff Circle', $form);
        $this->assertStringContainsString('forum-groups.php', $form);
        $this->assertStringContainsString(
            'name="forum_access_groups[]" value="' . $namedId . '"',
            $form
        );
        $this->assertStringNotContainsString(
            'name="forum_access_groups[]" value="' . (int) $guests->group_id . '"',
            $form
        );
        $this->assertStringNotContainsString(
            'name="forum_access_groups[]" value="' . (int) $registered->group_id . '"',
            $form
        );

        $nonce = ap_create_nonce('add-forum', $this->actorId);
        $created = AP_Admin_Forum_Edit::save([
            '_ap_nonce' => $nonce,
            'forum_name' => 'VIP Lounge',
            'forum_type' => AP_Forum::FORUM_TYPE_FORUM,
            'forum_access_level' => AP_Forum_Permissions::ACCESS_GROUP_ONLY,
            'forum_access_groups' => [$namedId, $hiddenId, (int) $guests->group_id],
        ], $this->actorId, $this->db);
        $this->assertTrue($created['ok'], implode('; ', $created['errors']));
        $forumId = $created['forum_id'];
        $this->assertGreaterThan(0, $forumId);
        $this->assertSame(
            AP_Forum_Permissions::ACCESS_GROUP_ONLY,
            AP_Forum_Permissions::detectAccessLevel($forumId, $this->db)
        );
        $this->assertSame(
            'This group only',
            AP_Forum_Permissions::summarizeAccess($forumId, $this->db)
        );
        $savedIds = AP_Forum_Permissions::getAccessGroupIds($forumId, $this->db);
        sort($savedIds);
        $expect = [$namedId, $hiddenId];
        sort($expect);
        $this->assertSame($expect, $savedIds);

        $forum = AP_Forum::getForum($forumId, $this->db);
        $editForm = AP_Admin_Forum_Edit::renderForm($forum, $this->actorId, $this->db);
        $this->assertStringContainsString('value="group_only" selected', $editForm);
        $this->assertStringContainsString(
            'name="forum_access_groups[]" value="' . $namedId . '" checked',
            $editForm
        );
        $this->assertStringContainsString(
            'name="forum_access_groups[]" value="' . $hiddenId . '" checked',
            $editForm
        );

        $editNonce = ap_create_nonce('edit-forum-' . $forumId, $this->actorId);
        $updated = AP_Admin_Forum_Edit::save([
            '_ap_nonce' => $editNonce,
            'forum_id' => $forumId,
            'forum_name' => 'VIP Lounge',
            'forum_access_level' => AP_Forum_Permissions::ACCESS_PUBLIC,
        ], $this->actorId, $this->db);
        $this->assertTrue($updated['ok'], implode('; ', $updated['errors']));
        $this->assertSame(
            AP_Forum_Permissions::ACCESS_PUBLIC,
            AP_Forum_Permissions::detectAccessLevel($forumId, $this->db)
        );
        $this->assertSame([], AP_Forum_Permissions::getAccessGroupIds($forumId, $this->db));
    }

    public function testForumSettingsSave(): void
    {
        $ok = AP_Options::updateForumSettings([
            'forum_topics_per_page' => 25,
            'forum_posts_per_page' => 12,
            'forum_allow_guest_viewing' => '1',
            'forum_allow_guest_posting' => '0',
            'forum_private_messaging_enabled' => '1',
            'forum_attachments_enabled' => '1',
            'forum_attachment_max_size' => 1048576,
            'forum_attachment_allowed_types' => 'jpg,png,pdf',
            'forum_flood_interval' => 45,
            'forum_posts_require_approval' => '1',
            'forum_spam_blacklist' => "viagra\ncasino",
            'forum_spam_max_links' => 3,
            'forum_search_enabled' => '1',
            'forum_online_enabled' => '1',
            'forum_unread_tracking_enabled' => '0',
            'forum_signatures_enabled' => '0',
        ], $this->db);

        $this->assertTrue($ok);
        $this->assertSame(25, (int) AP_Options::get('forum_topics_per_page', 20, $this->db));
        $this->assertSame(12, (int) AP_Options::get('forum_posts_per_page', 15, $this->db));
        $this->assertSame(45, (int) AP_Options::get('forum_flood_interval', 30, $this->db));
        $this->assertSame('1', (string) AP_Options::get('forum_posts_require_approval', '0', $this->db));
        $this->assertSame('0', (string) AP_Options::get('forum_unread_tracking_enabled', '1', $this->db));
        $this->assertSame('0', (string) AP_Options::get('forum_signatures_enabled', '1', $this->db));
        $this->assertSame('0', (string) AP_Options::get('forum_topic_notify_enabled', '1', $this->db));
        $this->assertStringContainsString('viagra', (string) AP_Options::get('forum_spam_blacklist', '', $this->db));
    }

    public function testForumSettingsTopicNotifyCheckboxDefaultOffAndPersists(): void
    {
        $src = (string) file_get_contents($this->root . '/ap-admin/options-forums.php');
        $this->assertStringContainsString('name="forum_topic_notify_enabled"', $src);
        $this->assertStringContainsString('Allow topic email notifications', $src);
        $this->assertStringContainsString("AP_Options::get('forum_topic_notify_enabled', '0'", $src);

        $this->assertSame(
            '0',
            (string) AP_Options::get('forum_topic_notify_enabled', 'missing', $this->db)
        );

        $on = AP_Options::updateForumSettings([
            'forum_topic_notify_enabled' => '1',
            'forum_allow_guest_viewing' => '1',
            'forum_allow_guest_posting' => '0',
            'forum_private_messaging_enabled' => '1',
            'forum_attachments_enabled' => '1',
            'forum_posts_require_approval' => '0',
            'forum_search_enabled' => '1',
            'forum_online_enabled' => '1',
            'forum_unread_tracking_enabled' => '1',
            'forum_signatures_enabled' => '1',
        ], $this->db);
        $this->assertTrue($on);
        $this->assertSame(
            '1',
            (string) AP_Options::get('forum_topic_notify_enabled', 'x', $this->db)
        );

        // Unchecked checkbox is omitted from POST → stored off.
        $off = AP_Options::updateForumSettings([
            'forum_allow_guest_viewing' => '1',
            'forum_allow_guest_posting' => '0',
            'forum_private_messaging_enabled' => '1',
            'forum_attachments_enabled' => '1',
            'forum_posts_require_approval' => '0',
            'forum_search_enabled' => '1',
            'forum_online_enabled' => '1',
            'forum_unread_tracking_enabled' => '1',
            'forum_signatures_enabled' => '1',
        ], $this->db);
        $this->assertTrue($off);
        $this->assertSame(
            '0',
            (string) AP_Options::get('forum_topic_notify_enabled', 'x', $this->db)
        );
    }

    public function testAdminBootstrapLoadsForumIncludes(): void
    {
        $boot = (string) file_get_contents($this->root . '/ap-admin/admin-bootstrap.php');
        foreach (
            [
                'class-ap-forums-list-table.php',
                'class-ap-admin-forum-edit.php',
                'class-ap-forum-topics-list-table.php',
                'class-ap-forum-moderation-queue.php',
                'class-ap-admin-forum-groups.php',
            ] as $file
        ) {
            $this->assertStringContainsString($file, $boot);
        }
    }

    public function testNoticeMessagesCoverForumKeys(): void
    {
        $_GET['message'] = 'forum_created';
        AP_Admin::consumeQueryNotice();
        $notices = AP_Admin::getNotices();
        $this->assertNotEmpty($notices);
        $this->assertStringContainsString('Forum created', $notices[0]['message']);

        AP_Admin::clearNotices();
        $_GET['message'] = 'bulk_topic_locked';
        AP_Admin::consumeQueryNotice();
        $notices = AP_Admin::getNotices();
        $this->assertStringContainsString('locked', strtolower($notices[0]['message']));

        AP_Admin::clearNotices();
        $_GET['message'] = 'topic_moved';
        AP_Admin::consumeQueryNotice();
        $notices = AP_Admin::getNotices();
        $this->assertStringContainsString('moved', strtolower($notices[0]['message']));

        AP_Admin::clearNotices();
        $_GET['message'] = 'bulk_topic_moved';
        AP_Admin::consumeQueryNotice();
        $notices = AP_Admin::getNotices();
        $this->assertStringContainsString('moved', strtolower($notices[0]['message']));

        AP_Admin::clearNotices();
        $_GET['message'] = 'bulk_topic_merged';
        AP_Admin::consumeQueryNotice();
        $notices = AP_Admin::getNotices();
        $this->assertStringContainsString('merged', strtolower($notices[0]['message']));

        AP_Admin::clearNotices();
        $_GET['message'] = 'topics_merged';
        AP_Admin::consumeQueryNotice();
        $notices = AP_Admin::getNotices();
        $this->assertStringContainsString('merged', strtolower($notices[0]['message']));
        unset($_GET['message']);
        AP_Admin::clearNotices();
    }
}
