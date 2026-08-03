<?php

/**
 * Tests for AP_Group + AP_Forum_Permissions (user groups + per-forum ACL).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Forum;

use AP_DB;
use AP_Forum;
use AP_Forum_Permissions;
use AP_Group;
use AP_Migrator;
use AP_Options;
use AP_Roles;
use AP_User;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_Group::class)]
#[CoversClass(AP_Forum_Permissions::class)]
final class ForumGroupsPermissionsTest extends TestCase
{
    private string $root;

    private AP_DB $db;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        require_once $this->root . '/ap-includes/version.php';
        require_once $this->root . '/ap-includes/class-ap-db.php';
        require_once $this->root . '/ap-includes/class-ap-migrator.php';
        require_once $this->root . '/ap-includes/class-ap-options.php';
        require_once $this->root . '/ap-includes/class-ap-user.php';
        require_once $this->root . '/ap-includes/class-ap-roles.php';
        require_once $this->root . '/ap-includes/class-ap-forum.php';
        require_once $this->root . '/ap-includes/class-ap-group.php';
        require_once $this->root . '/ap-includes/class-ap-forum-permissions.php';
        require_once $this->root . '/ap-includes/functions.php';

        AP_Roles::flushCache();
        AP_Options::flushCache();
        AP_Group::flushCache();
        AP_Forum_Permissions::flushCache();

        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');

        $migrator = new AP_Migrator($this->db, AP_Migrator::defaultMigrationsPath());
        $migrator->migrate();

        AP_Roles::ensureDefaults($this->db);
    }

    protected function tearDown(): void
    {
        AP_Roles::flushCache();
        AP_Options::flushCache();
        AP_Group::flushCache();
        AP_Forum_Permissions::flushCache();
    }

    public function testMigrationCreatesForumPermissionsTable(): void
    {
        $this->assertGreaterThanOrEqual(7, (int) AP_DB_VERSION);
        $name = $this->db->getVar(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?",
            ['ap_forum_permissions']
        );
        $this->assertSame('ap_forum_permissions', $name);
        $this->assertSame('ap_forum_permissions', $this->db->forum_permissions);
        $this->assertContains('forum_permissions', AP_Forum::baseTables());
        $this->assertContains('forum_permissions', AP_DB::knownBaseTables());
    }

    public function testSystemGroupsSeeded(): void
    {
        $ids = AP_Group::ensureSystemGroups($this->db);
        $this->assertArrayHasKey(AP_Group::SLUG_GUESTS, $ids);
        $this->assertArrayHasKey(AP_Group::SLUG_REGISTERED, $ids);
        $this->assertArrayHasKey(AP_Group::SLUG_ADMINISTRATORS, $ids);
        $this->assertArrayHasKey(AP_Group::SLUG_GLOBAL_MODERATORS, $ids);

        $guests = AP_Group::getBySlug(AP_Group::SLUG_GUESTS, $this->db);
        $this->assertNotNull($guests);
        $this->assertSame(AP_Group::TYPE_SYSTEM, $guests->group_type);

        // Idempotent.
        $ids2 = AP_Group::ensureSystemGroups($this->db);
        $this->assertSame($ids[AP_Group::SLUG_GUESTS], $ids2[AP_Group::SLUG_GUESTS]);
        $this->assertSame(4, AP_Group::count(['type' => AP_Group::TYPE_SYSTEM], $this->db));
    }

    public function testGroupCrudAndMembership(): void
    {
        $groupId = AP_Group::create([
            'group_name' => 'VIP Members',
            'group_desc' => 'Special access',
            'group_type' => 'closed',
        ], $this->db);
        $this->assertGreaterThan(0, $groupId);

        $group = AP_Group::get($groupId, $this->db);
        $this->assertNotNull($group);
        $this->assertSame('VIP Members', $group->group_name);
        $this->assertSame('vip-members', $group->group_slug);
        $this->assertSame('closed', $group->group_type);

        $this->assertTrue(AP_Group::update($groupId, [
            'group_name' => 'VIP Club',
            'group_desc' => 'Updated',
        ], $this->db));
        $group = AP_Group::get($groupId, $this->db);
        $this->assertSame('VIP Club', $group->group_name);

        $userId = $this->createUser('vipuser', 'vip@example.test');
        $membershipId = AP_Group::addMember($groupId, $userId, AP_Group::ROLE_LEADER, $this->db);
        $this->assertGreaterThan(0, $membershipId);
        $this->assertTrue(AP_Group::isMember($groupId, $userId, $this->db));

        $members = AP_Group::getMembers($groupId, [], $this->db);
        $this->assertCount(1, $members);
        $this->assertSame(AP_Group::ROLE_LEADER, $members[0]->member_role);

        $userGroups = AP_Group::getUserGroups($userId, $this->db);
        $this->assertCount(1, $userGroups);
        $this->assertSame('VIP Club', $userGroups[0]->group_name);

        $this->assertTrue(AP_Group::setMemberRole($groupId, $userId, AP_Group::ROLE_MEMBER, $this->db));
        $m = AP_Group::getMembership($groupId, $userId, $this->db);
        $this->assertNotNull($m);
        $this->assertSame(AP_Group::ROLE_MEMBER, $m->member_role);

        $group = AP_Group::get($groupId, $this->db);
        $this->assertSame(1, (int) $group->member_count);

        $this->assertTrue(AP_Group::removeMember($groupId, $userId, $this->db));
        $this->assertFalse(AP_Group::isMember($groupId, $userId, $this->db));
        $group = AP_Group::get($groupId, $this->db);
        $this->assertSame(0, (int) $group->member_count);

        $this->assertTrue(AP_Group::delete($groupId, $this->db));
        $this->assertNull(AP_Group::get($groupId, $this->db));
    }

    public function testSystemGroupsCannotBeDeleted(): void
    {
        AP_Group::ensureSystemGroups($this->db);
        $guests = AP_Group::getBySlug(AP_Group::SLUG_GUESTS, $this->db);
        $this->assertNotNull($guests);
        $this->assertFalse(AP_Group::delete((int) $guests->group_id, $this->db));
        $this->assertNotNull(AP_Group::getBySlug(AP_Group::SLUG_GUESTS, $this->db));
    }

    public function testDefaultGlobalPermissions(): void
    {
        AP_Forum_Permissions::ensureDefaults($this->db);

        $forumId = AP_Forum::insertForum([
            'forum_name' => 'General Chat',
            'forum_type' => 'forum',
        ], $this->db);
        $this->assertGreaterThan(0, $forumId);

        // Guests can view/read, not post.
        $this->assertTrue(AP_Forum_Permissions::userCan(0, $forumId, AP_Forum_Permissions::PERM_VIEW, $this->db));
        $this->assertTrue(AP_Forum_Permissions::userCan(0, $forumId, AP_Forum_Permissions::PERM_READ, $this->db));
        $this->assertFalse(AP_Forum_Permissions::userCan(0, $forumId, AP_Forum_Permissions::PERM_POST_TOPICS, $this->db));
        $this->assertFalse(AP_Forum_Permissions::userCan(0, $forumId, AP_Forum_Permissions::PERM_MODERATE, $this->db));

        // Registered subscriber can post.
        $userId = $this->createUser('member1', 'm1@example.test', 'subscriber');
        $this->assertTrue(AP_Forum_Permissions::userCan($userId, $forumId, AP_Forum_Permissions::PERM_VIEW, $this->db));
        $this->assertTrue(AP_Forum_Permissions::userCan($userId, $forumId, AP_Forum_Permissions::PERM_POST_TOPICS, $this->db));
        $this->assertTrue(AP_Forum_Permissions::userCan($userId, $forumId, AP_Forum_Permissions::PERM_POST_REPLIES, $this->db));
        $this->assertTrue(AP_Forum_Permissions::userCan($userId, $forumId, AP_Forum_Permissions::PERM_ATTACH, $this->db));
        $this->assertFalse(AP_Forum_Permissions::userCan($userId, $forumId, AP_Forum_Permissions::PERM_MODERATE, $this->db));
        $this->assertFalse(AP_Forum_Permissions::userCan($userId, $forumId, AP_Forum_Permissions::PERM_STICKY, $this->db));
    }

    public function testAdministratorBypassesAcl(): void
    {
        AP_Forum_Permissions::ensureDefaults($this->db);
        $forumId = AP_Forum::insertForum(['forum_name' => 'Staff Only'], $this->db);
        $adminId = $this->createUser('admin1', 'admin@example.test', 'administrator');

        // Deny registered on this forum for view — admin still allowed via manage_forums.
        $registered = AP_Group::getBySlug(AP_Group::SLUG_REGISTERED, $this->db);
        $this->assertNotNull($registered);
        AP_Forum_Permissions::setPermission(
            $forumId,
            (int) $registered->group_id,
            AP_Forum_Permissions::PERM_VIEW,
            false,
            $this->db
        );

        $memberId = $this->createUser('plain', 'plain@example.test', 'subscriber');
        $this->assertFalse(AP_Forum_Permissions::userCan($memberId, $forumId, AP_Forum_Permissions::PERM_VIEW, $this->db));
        $this->assertTrue(AP_Forum_Permissions::userCan($adminId, $forumId, AP_Forum_Permissions::PERM_VIEW, $this->db));
        $this->assertTrue(AP_Forum_Permissions::userCan($adminId, $forumId, AP_Forum_Permissions::PERM_MODERATE, $this->db));
    }

    public function testEditorModerateForumsCap(): void
    {
        AP_Forum_Permissions::ensureDefaults($this->db);
        $forumId = AP_Forum::insertForum(['forum_name' => 'News'], $this->db);
        $editorId = $this->createUser('editor1', 'ed@example.test', 'editor');

        $this->assertTrue(AP_Roles::userCan($editorId, 'moderate_forums', null, $this->db));
        $this->assertTrue(AP_Forum_Permissions::userCan($editorId, $forumId, AP_Forum_Permissions::PERM_MODERATE, $this->db));
        $this->assertTrue(AP_Forum_Permissions::userCan($editorId, $forumId, AP_Forum_Permissions::PERM_LOCK, $this->db));
        $this->assertTrue(AP_Forum_Permissions::userCanModerate($editorId, $forumId, $this->db));
    }

    public function testPerForumRegisteredDenyBlocksOutsiders(): void
    {
        AP_Forum_Permissions::ensureDefaults($this->db);
        $forumId = AP_Forum::insertForum(['forum_name' => 'Secret'], $this->db);

        $registered = AP_Group::getBySlug(AP_Group::SLUG_REGISTERED, $this->db);
        $this->assertNotNull($registered);
        AP_Forum_Permissions::setPermission(
            $forumId,
            (int) $registered->group_id,
            AP_Forum_Permissions::PERM_VIEW,
            false,
            $this->db
        );
        AP_Forum_Permissions::setPermission(
            $forumId,
            (int) $registered->group_id,
            AP_Forum_Permissions::PERM_POST_TOPICS,
            false,
            $this->db
        );

        $outsider = $this->createUser('outsider', 'out@example.test', 'subscriber');
        $this->assertFalse(AP_Forum_Permissions::userCan($outsider, $forumId, AP_Forum_Permissions::PERM_VIEW, $this->db));
        $this->assertFalse(AP_Forum_Permissions::userCanPostTopic($outsider, $forumId, $this->db));

        // Explicit ban group deny still blocks even when registered would allow elsewhere.
        $openForum = AP_Forum::insertForum(['forum_name' => 'Open'], $this->db);
        $bannedId = AP_Group::create(['group_name' => 'Banned'], $this->db);
        AP_Forum_Permissions::setPermission(
            $openForum,
            $bannedId,
            AP_Forum_Permissions::PERM_VIEW,
            false,
            $this->db
        );
        $bannedUser = $this->createUser('banned', 'ban@example.test', 'subscriber');
        AP_Group::addMember($bannedId, $bannedUser, AP_Group::ROLE_MEMBER, $this->db);
        $this->assertFalse(AP_Forum_Permissions::userCanViewForum($bannedUser, $openForum, $this->db));
        $this->assertTrue(AP_Forum_Permissions::userCanViewForum($outsider, $openForum, $this->db));
    }

    public function testVipOnlyForumOverridesRegisteredDeny(): void
    {
        AP_Forum_Permissions::ensureDefaults($this->db);
        $forumId = AP_Forum::insertForum(['forum_name' => 'VIP Lounge'], $this->db);

        $vipId = AP_Group::create(['group_name' => 'Lounge VIP'], $this->db);
        $registered = AP_Group::getBySlug(AP_Group::SLUG_REGISTERED, $this->db);
        $this->assertNotNull($registered);

        // Deny the virtual registered group; allow VIP group.
        foreach ([AP_Forum_Permissions::PERM_VIEW, AP_Forum_Permissions::PERM_READ, AP_Forum_Permissions::PERM_POST_TOPICS] as $perm) {
            AP_Forum_Permissions::setPermission($forumId, (int) $registered->group_id, $perm, false, $this->db);
            AP_Forum_Permissions::setPermission($forumId, $vipId, $perm, true, $this->db);
        }

        $outsider = $this->createUser('out2', 'out2@example.test', 'subscriber');
        $vipUser = $this->createUser('vip3', 'vip3@example.test', 'subscriber');
        AP_Group::addMember($vipId, $vipUser, AP_Group::ROLE_MEMBER, $this->db);

        $this->assertFalse(AP_Forum_Permissions::userCanViewForum($outsider, $forumId, $this->db));
        $this->assertFalse(AP_Forum_Permissions::userCanPostTopic($outsider, $forumId, $this->db));

        // VIP members: explicit group allow overrides virtual registered deny.
        $this->assertTrue(AP_Forum_Permissions::userCanViewForum($vipUser, $forumId, $this->db));
        $this->assertTrue(AP_Forum_Permissions::userCanPostTopic($vipUser, $forumId, $this->db));
    }

    public function testSetGroupPermissionsReplace(): void
    {
        AP_Group::ensureSystemGroups($this->db);
        $gid = AP_Group::create(['group_name' => 'Testers'], $this->db);
        $forumId = AP_Forum::insertForum(['forum_name' => 'QA'], $this->db);

        AP_Forum_Permissions::setGroupPermissions($forumId, $gid, [
            AP_Forum_Permissions::PERM_VIEW => true,
            AP_Forum_Permissions::PERM_READ => true,
            AP_Forum_Permissions::PERM_POST_TOPICS => false,
        ], $this->db);

        $map = AP_Forum_Permissions::getGroupPermissions($forumId, $gid, $this->db);
        $this->assertTrue($map[AP_Forum_Permissions::PERM_VIEW] ?? false);
        $this->assertTrue($map[AP_Forum_Permissions::PERM_READ] ?? false);
        $this->assertFalse($map[AP_Forum_Permissions::PERM_POST_TOPICS] ?? true);
        $this->assertArrayNotHasKey(AP_Forum_Permissions::PERM_MODERATE, $map);

        AP_Forum_Permissions::setGroupPermissions($forumId, $gid, [
            AP_Forum_Permissions::PERM_MODERATE => true,
        ], $this->db);
        $map = AP_Forum_Permissions::getGroupPermissions($forumId, $gid, $this->db);
        $this->assertCount(1, $map);
        $this->assertTrue($map[AP_Forum_Permissions::PERM_MODERATE]);
    }

    public function testProceduralHelpers(): void
    {
        $ids = ap_ensure_system_groups($this->db);
        $this->assertNotEmpty($ids);
        ap_ensure_forum_permission_defaults($this->db);

        $gid = ap_create_group(['group_name' => 'Helpers'], $this->db);
        $this->assertGreaterThan(0, $gid);
        $this->assertNotNull(ap_get_group($gid, $this->db));

        $uid = $this->createUser('helper', 'h@example.test');
        $this->assertGreaterThan(0, ap_add_group_member($gid, $uid, 'moderator', $this->db));
        $this->assertCount(1, ap_get_group_members($gid, [], $this->db));
        $this->assertNotEmpty(ap_get_user_groups($uid, $this->db));
        $this->assertContains($gid, ap_get_effective_group_ids($uid, $this->db));

        $forumId = AP_Forum::insertForum(['forum_name' => 'Helpers Forum'], $this->db);
        $this->assertTrue(ap_set_forum_permission($forumId, $gid, 'view_forum', true, $this->db));
        $this->assertTrue(ap_user_can_forum($uid, $forumId, 'view_forum', $this->db));
        $this->assertNotEmpty(ap_forum_permissions());
        $this->assertIsArray(ap_get_user_forum_permissions($uid, $forumId, $this->db));
        $this->assertTrue(ap_remove_group_member($gid, $uid, $this->db));
        $this->assertTrue(ap_delete_group($gid, $this->db));
    }

    public function testGetUserPermissionsAndMatrix(): void
    {
        AP_Forum_Permissions::ensureDefaults($this->db);
        $forumId = AP_Forum::insertForum(['forum_name' => 'Matrix'], $this->db);
        $userId = $this->createUser('mx', 'mx@example.test', 'subscriber');

        $all = AP_Forum_Permissions::getUserPermissions($userId, $forumId, $this->db);
        $this->assertArrayHasKey(AP_Forum_Permissions::PERM_VIEW, $all);
        $this->assertTrue($all[AP_Forum_Permissions::PERM_VIEW]);
        $this->assertFalse($all[AP_Forum_Permissions::PERM_MODERATE]);

        $matrix = AP_Forum_Permissions::getForumMatrix($forumId, true, $this->db);
        $this->assertNotEmpty($matrix);
    }

    public function testDeleteGroupRemovesPermissions(): void
    {
        $gid = AP_Group::create(['group_name' => 'Temp'], $this->db);
        $forumId = AP_Forum::insertForum(['forum_name' => 'Tmp'], $this->db);
        AP_Forum_Permissions::setPermission($forumId, $gid, AP_Forum_Permissions::PERM_VIEW, true, $this->db);
        $this->assertNotEmpty(AP_Forum_Permissions::getGroupPermissions($forumId, $gid, $this->db));
        $this->assertTrue(AP_Group::delete($gid, $this->db));
        $this->assertSame([], AP_Forum_Permissions::getGroupPermissions($forumId, $gid, $this->db));
    }

    public function testCreateTopicAndReplyHonorCheckPermissions(): void
    {
        AP_Forum_Permissions::ensureDefaults($this->db);
        $forumId = AP_Forum::insertForum(['forum_name' => 'ACL Write'], $this->db);
        $this->assertGreaterThan(0, $forumId);

        // Guest cannot post when ACL is enforced.
        $this->assertSame(0, AP_Forum::createTopic([
            'forum_id' => $forumId,
            'topic_title' => 'Guest topic',
            'content' => 'No guests',
            'poster_id' => 0,
        ], $this->db, ['check_permissions' => true]));

        $memberId = $this->createUser('writer', 'writer@example.test', 'subscriber');
        $topicId = AP_Forum::createTopic([
            'forum_id' => $forumId,
            'topic_title' => 'Member topic',
            'content' => 'Hello board',
            'poster_id' => $memberId,
        ], $this->db, ['check_permissions' => true]);
        $this->assertGreaterThan(0, $topicId);

        // Sticky requires sticky_topics (registered defaults lack it).
        $this->assertSame(0, AP_Forum::createTopic([
            'forum_id' => $forumId,
            'topic_title' => 'Sticky fail',
            'content' => 'Body',
            'poster_id' => $memberId,
            'topic_type' => 'sticky',
        ], $this->db, ['check_permissions' => true]));

        // Guest reply denied; member reply allowed.
        $this->assertSame(0, AP_Forum::createReply([
            'topic_id' => $topicId,
            'content' => 'Guest reply',
            'poster_id' => 0,
        ], $this->db, ['check_permissions' => true]));

        $replyId = AP_Forum::createReply([
            'topic_id' => $topicId,
            'content' => 'Member reply',
            'poster_id' => $memberId,
        ], $this->db, ['check_permissions' => true]);
        $this->assertGreaterThan(0, $replyId);

        // Deny post_replies for registered on this forum — member blocked.
        $registered = AP_Group::getBySlug(AP_Group::SLUG_REGISTERED, $this->db);
        $this->assertNotNull($registered);
        AP_Forum_Permissions::setPermission(
            $forumId,
            (int) $registered->group_id,
            AP_Forum_Permissions::PERM_POST_REPLIES,
            false,
            $this->db
        );
        $this->assertSame(0, AP_Forum::createReply([
            'topic_id' => $topicId,
            'content' => 'Blocked reply',
            'poster_id' => $memberId,
        ], $this->db, ['check_permissions' => true]));
    }

    /**
     * Create a minimal user with optional role.
     */
    private function createUser(string $login, string $email, string $role = 'subscriber'): int
    {
        $result = AP_User::create([
            'user_login' => $login,
            'user_email' => $email,
            'user_pass' => 'password-ok-123',
            'display_name' => $login,
        ], $this->db);
        $err = isset($result['errors']) && is_array($result['errors'])
            ? implode('; ', $result['errors'])
            : 'user create failed';
        $this->assertTrue($result['ok'] ?? false, $err);
        $id = (int) ($result['id'] ?? 0);
        $this->assertGreaterThan(0, $id);
        AP_Roles::setUserRole($id, $role, $this->db);

        return $id;
    }
}
