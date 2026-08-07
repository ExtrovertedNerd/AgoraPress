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

    public function testAccessLevelPresetsAndLadder(): void
    {
        AP_Forum_Permissions::ensureDefaults($this->db);
        $guestUser = 0;
        $member = $this->createUser('lvl_member', 'lvl_m@example.test', 'subscriber');
        $mod = $this->createUser('lvl_mod', 'lvl_mod@example.test', 'editor'); // moderate_forums
        $admin = $this->createUser('lvl_admin', 'lvl_a@example.test', 'administrator');

        // --- Members only: guests cannot view; members can post ---
        $membersForum = AP_Forum::insertForum(['forum_name' => 'Members Board'], $this->db);
        $this->assertTrue(AP_Forum_Permissions::applyAccessLevel(
            $membersForum,
            AP_Forum_Permissions::ACCESS_MEMBERS,
            $this->db
        ));
        $this->assertSame(
            AP_Forum_Permissions::ACCESS_MEMBERS,
            AP_Forum_Permissions::detectAccessLevel($membersForum, $this->db)
        );
        $this->assertFalse(AP_Forum_Permissions::userCanViewForum($guestUser, $membersForum, $this->db));
        $this->assertTrue(AP_Forum_Permissions::userCanViewForum($member, $membersForum, $this->db));
        $this->assertTrue(AP_Forum_Permissions::userCanPostTopic($member, $membersForum, $this->db));
        $this->assertTrue(AP_Forum_Permissions::userCanViewForum($admin, $membersForum, $this->db));

        // --- Read only: members view but cannot post; mods can post ---
        $roForum = AP_Forum::insertForum(['forum_name' => 'Announcements'], $this->db);
        AP_Forum_Permissions::applyAccessLevel(
            $roForum,
            AP_Forum_Permissions::ACCESS_MEMBERS_READONLY,
            $this->db
        );
        $this->assertTrue(AP_Forum_Permissions::userCanViewForum($guestUser, $roForum, $this->db));
        $this->assertTrue(AP_Forum_Permissions::userCanViewForum($member, $roForum, $this->db));
        $this->assertFalse(AP_Forum_Permissions::userCanPostTopic($member, $roForum, $this->db));
        $this->assertFalse(AP_Forum_Permissions::userCanPostReply($member, $roForum, $this->db));
        // Editor has moderate_forums bypass for moderation family, but post_topics is not
        // in that list — mods group ACL grants post for this preset.
        $this->assertTrue(AP_Forum_Permissions::userCanPostTopic($mod, $roForum, $this->db));

        // --- Moderators only ---
        $modForum = AP_Forum::insertForum(['forum_name' => 'Staff'], $this->db);
        AP_Forum_Permissions::applyAccessLevel(
            $modForum,
            AP_Forum_Permissions::ACCESS_MODERATORS,
            $this->db
        );
        $this->assertSame(
            AP_Forum_Permissions::ACCESS_MODERATORS,
            AP_Forum_Permissions::detectAccessLevel($modForum, $this->db)
        );
        $this->assertFalse(AP_Forum_Permissions::userCanViewForum($guestUser, $modForum, $this->db));
        $this->assertFalse(AP_Forum_Permissions::userCanViewForum($member, $modForum, $this->db));
        // Global moderators group grants view; editor role maps to virtual global_moderators.
        $this->assertTrue(AP_Forum_Permissions::userCanViewForum($mod, $modForum, $this->db));
        $this->assertTrue(AP_Forum_Permissions::userCanViewForum($admin, $modForum, $this->db));

        // --- Administrators only ---
        $adminForum = AP_Forum::insertForum(['forum_name' => 'Root'], $this->db);
        AP_Forum_Permissions::applyAccessLevel(
            $adminForum,
            AP_Forum_Permissions::ACCESS_ADMINISTRATORS,
            $this->db
        );
        $this->assertSame(
            'Administrators only',
            AP_Forum_Permissions::summarizeAccess($adminForum, $this->db)
        );
        $this->assertFalse(AP_Forum_Permissions::userCanViewForum($member, $adminForum, $this->db));
        $this->assertFalse(AP_Forum_Permissions::userCanViewForum($mod, $adminForum, $this->db));
        $this->assertTrue(AP_Forum_Permissions::userCanViewForum($admin, $adminForum, $this->db));

        // Ladder: administrator baseline is a superset of moderator, etc.
        $adminPerms = AP_Forum_Permissions::baselinePermissionsForLevel(
            AP_Forum_Permissions::LEVEL_ADMINISTRATOR
        );
        $modPerms = AP_Forum_Permissions::baselinePermissionsForLevel(
            AP_Forum_Permissions::LEVEL_MODERATOR
        );
        foreach ($modPerms as $p) {
            $this->assertContains($p, $adminPerms);
        }
        $regPerms = AP_Forum_Permissions::baselinePermissionsForLevel(
            AP_Forum_Permissions::LEVEL_REGISTERED
        );
        foreach ($regPerms as $p) {
            $this->assertContains($p, $modPerms);
        }
    }

    public function testParseAndSaveAccessFormCustomMatrix(): void
    {
        AP_Forum_Permissions::ensureDefaults($this->db);
        $forumId = AP_Forum::insertForum(['forum_name' => 'Custom ACL'], $this->db);

        $input = [
            'forum_access_level' => 'custom',
            'forum_perm' => [
                'guest' => [
                    'view_forum' => '1',
                    'read_forum' => '1',
                ],
                'registered' => [
                    'view_forum' => '1',
                    'read_forum' => '1',
                    // no post — read-only members
                ],
                'moderator' => [
                    'view_forum' => '1',
                    'read_forum' => '1',
                    'post_topics' => '1',
                    'post_replies' => '1',
                    'moderate_forum' => '1',
                ],
                'administrator' => [
                    // forced full even if omitted
                ],
            ],
        ];
        $this->assertTrue(AP_Forum_Permissions::saveAccessFromForm($forumId, $input, $this->db));
        $this->assertSame(
            AP_Forum_Permissions::ACCESS_CUSTOM,
            AP_Forum_Permissions::detectAccessLevel($forumId, $this->db)
        );

        $member = $this->createUser('custom_m', 'cm@example.test', 'subscriber');
        $this->assertTrue(AP_Forum_Permissions::userCanViewForum($member, $forumId, $this->db));
        $this->assertFalse(AP_Forum_Permissions::userCanPostTopic($member, $forumId, $this->db));

        $matrix = AP_Forum_Permissions::getLevelMatrix($forumId, false, $this->db);
        foreach (AP_Forum_Permissions::allPermissions() as $perm) {
            $this->assertTrue(
                $matrix[AP_Forum_Permissions::LEVEL_ADMINISTRATOR][$perm],
                "Admin must have {$perm}"
            );
        }
    }

    public function testForumAclDoesNotApplyToPostsOrPagesConceptually(): void
    {
        // Guard: permission API is forum-scoped (forum_id), not post_type=post|page.
        $this->assertStringContainsString(
            'view_forum',
            AP_Forum_Permissions::PERM_VIEW
        );
        $perms = AP_Forum_Permissions::allPermissions();
        foreach ($perms as $p) {
            $this->assertDoesNotMatchRegularExpression(
                '/^(edit_post|publish_posts|read_private_posts|edit_pages)/',
                $p
            );
        }
        // Source of admin post edit must not call forum ACL.
        $postEdit = (string) file_get_contents(
            $this->root . '/ap-admin/includes/class-ap-admin-post-edit.php'
        );
        $this->assertStringNotContainsString('AP_Forum_Permissions', $postEdit);
        $this->assertStringNotContainsString('forum_access_level', $postEdit);
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

    public function testAllowedTopicTypesAndSetTypePermissions(): void
    {
        AP_Forum_Permissions::ensureDefaults($this->db);
        $forumId = AP_Forum::insertForum(['forum_name' => 'Type ACL'], $this->db);
        $memberId = $this->createUser('type_member', 'type_m@example.test', 'subscriber');
        $adminId = $this->createUser('type_admin', 'type_a@example.test', 'administrator');

        // Members: standard only for create.
        $memberCreate = AP_Forum_Permissions::allowedTopicTypesForCreate($memberId, $forumId, $this->db);
        $this->assertSame(['standard'], $memberCreate);
        $this->assertFalse(AP_Forum_Permissions::userCanSticky($memberId, $forumId, $this->db));
        $this->assertFalse(AP_Forum_Permissions::userCanAnnounce($memberId, $forumId, $this->db));
        $this->assertSame(
            [],
            AP_Forum_Permissions::allowedTopicTypesForEdit($memberId, $forumId, 'standard', $this->db)
        );

        // Admin (manage_forums): full type set.
        $adminCreate = AP_Forum_Permissions::allowedTopicTypesForCreate($adminId, $forumId, $this->db);
        $this->assertContains('standard', $adminCreate);
        $this->assertContains('sticky', $adminCreate);
        $this->assertContains('announcement', $adminCreate);
        $this->assertContains('rules', $adminCreate);
        $this->assertTrue(AP_Forum_Permissions::userCanSetTopicType(
            $adminId,
            $forumId,
            'sticky',
            $this->db,
            null
        ));
        $this->assertFalse(AP_Forum_Permissions::userCanSetTopicType(
            $memberId,
            $forumId,
            'sticky',
            $this->db,
            null
        ));

        // Grant sticky only to registered group — member can sticky but not announce.
        $registered = AP_Group::getBySlug(AP_Group::SLUG_REGISTERED, $this->db);
        $this->assertNotNull($registered);
        AP_Forum_Permissions::setPermission(
            $forumId,
            (int) $registered->group_id,
            AP_Forum_Permissions::PERM_STICKY,
            true,
            $this->db
        );
        AP_Forum_Permissions::flushCache();

        $this->assertTrue(AP_Forum_Permissions::userCanSticky($memberId, $forumId, $this->db));
        $this->assertFalse(AP_Forum_Permissions::userCanAnnounce($memberId, $forumId, $this->db));
        $withSticky = AP_Forum_Permissions::allowedTopicTypesForCreate($memberId, $forumId, $this->db);
        $this->assertSame(['standard', 'sticky'], $withSticky);
        $this->assertFalse(AP_Forum_Permissions::userCanSetTopicType(
            $memberId,
            $forumId,
            'announcement',
            $this->db,
            null
        ));
        $this->assertTrue(AP_Forum_Permissions::userCanSetTopicType(
            $memberId,
            $forumId,
            'sticky',
            $this->db,
            null
        ));

        // createTopic with check_permissions honors sticky grant.
        $stickyId = AP_Forum::createTopic([
            'forum_id' => $forumId,
            'topic_title' => 'Now sticky ok',
            'content' => 'Body',
            'poster_id' => $memberId,
            'topic_type' => 'sticky',
        ], $this->db, ['check_permissions' => true]);
        $this->assertGreaterThan(0, $stickyId);

        $this->assertSame(0, AP_Forum::createTopic([
            'forum_id' => $forumId,
            'topic_title' => 'Announce fail',
            'content' => 'Body',
            'poster_id' => $memberId,
            'topic_type' => 'announcement',
        ], $this->db, ['check_permissions' => true]));

        // Moderation setTopicType uses type-specific caps (not full moderate).
        require_once $this->root . '/ap-includes/class-ap-forum-moderation.php';
        $this->assertTrue(\AP_Forum_Moderation::setTopicType(
            $stickyId,
            AP_Forum::TOPIC_TYPE_STANDARD,
            $memberId,
            $this->db
        ));
        $topic = AP_Forum::getTopic($stickyId, $this->db);
        $this->assertSame('standard', (string) ($topic->topic_type ?? ''));

        $this->assertFalse(\AP_Forum_Moderation::setTopicType(
            $stickyId,
            AP_Forum::TOPIC_TYPE_ANNOUNCEMENT,
            $memberId,
            $this->db
        ));
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
