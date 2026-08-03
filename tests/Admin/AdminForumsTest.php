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
        ], $this->actorId, $this->db);
        $this->assertTrue($updated['ok']);
        $this->assertSame('forum_updated', $updated['message_key']);
        $forum = AP_Forum::getForum($id, $this->db);
        $this->assertSame('General', $forum?->forum_name);
        $this->assertSame('closed', $forum?->forum_status);

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
        ], $this->db);

        $this->assertTrue($ok);
        $this->assertSame(25, (int) AP_Options::get('forum_topics_per_page', 20, $this->db));
        $this->assertSame(12, (int) AP_Options::get('forum_posts_per_page', 15, $this->db));
        $this->assertSame(45, (int) AP_Options::get('forum_flood_interval', 30, $this->db));
        $this->assertSame('1', (string) AP_Options::get('forum_posts_require_approval', '0', $this->db));
        $this->assertSame('0', (string) AP_Options::get('forum_unread_tracking_enabled', '1', $this->db));
        $this->assertStringContainsString('viagra', (string) AP_Options::get('forum_spam_blacklist', '', $this->db));
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
        unset($_GET['message']);
        AP_Admin::clearNotices();
    }
}
