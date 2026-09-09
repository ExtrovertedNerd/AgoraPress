<?php

/**
 * Tests for AP_Group + AP_Forum_Permissions (user groups + per-forum ACL).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Forum;

use AP_Admin_Forum_Edit;
use AP_DB;
use AP_Feed;
use AP_Forum;
use AP_Forum_Front;
use AP_Forum_Permissions;
use AP_Forums_List_Table;
use AP_Group;
use AP_Migrator;
use AP_Nav_Menu;
use AP_Options;
use AP_Query;
use AP_Rest;
use AP_Rewrite;
use AP_Roles;
use AP_Seo;
use AP_Session;
use AP_Sitemap;
use AP_Theme;
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
        $this->assertFalse(
            AP_Forum_Permissions::userCan(0, $forumId, AP_Forum_Permissions::PERM_POST_TOPICS, $this->db)
        );
        $this->assertFalse(AP_Forum_Permissions::userCan(0, $forumId, AP_Forum_Permissions::PERM_MODERATE, $this->db));

        // Registered subscriber can post.
        $userId = $this->createUser('member1', 'm1@example.test', 'subscriber');
        $this->assertTrue(AP_Forum_Permissions::userCan($userId, $forumId, AP_Forum_Permissions::PERM_VIEW, $this->db));
        $this->assertTrue(
            AP_Forum_Permissions::userCan($userId, $forumId, AP_Forum_Permissions::PERM_POST_TOPICS, $this->db)
        );
        $this->assertTrue(
            AP_Forum_Permissions::userCan($userId, $forumId, AP_Forum_Permissions::PERM_POST_REPLIES, $this->db)
        );
        $this->assertTrue(
            AP_Forum_Permissions::userCan($userId, $forumId, AP_Forum_Permissions::PERM_ATTACH, $this->db)
        );
        $this->assertFalse(
            AP_Forum_Permissions::userCan($userId, $forumId, AP_Forum_Permissions::PERM_MODERATE, $this->db)
        );
        $this->assertFalse(
            AP_Forum_Permissions::userCan($userId, $forumId, AP_Forum_Permissions::PERM_STICKY, $this->db)
        );
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
        $this->assertFalse(
            AP_Forum_Permissions::userCan($memberId, $forumId, AP_Forum_Permissions::PERM_VIEW, $this->db)
        );
        $this->assertTrue(
            AP_Forum_Permissions::userCan($adminId, $forumId, AP_Forum_Permissions::PERM_VIEW, $this->db)
        );
        $this->assertTrue(
            AP_Forum_Permissions::userCan($adminId, $forumId, AP_Forum_Permissions::PERM_MODERATE, $this->db)
        );
    }

    public function testEditorModerateForumsCap(): void
    {
        AP_Forum_Permissions::ensureDefaults($this->db);
        $forumId = AP_Forum::insertForum(['forum_name' => 'News'], $this->db);
        $editorId = $this->createUser('editor1', 'ed@example.test', 'editor');

        $this->assertTrue(AP_Roles::userCan($editorId, 'moderate_forums', null, $this->db));
        $this->assertTrue(
            AP_Forum_Permissions::userCan($editorId, $forumId, AP_Forum_Permissions::PERM_MODERATE, $this->db)
        );
        $this->assertTrue(
            AP_Forum_Permissions::userCan($editorId, $forumId, AP_Forum_Permissions::PERM_LOCK, $this->db)
        );
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
        $this->assertFalse(
            AP_Forum_Permissions::userCan($outsider, $forumId, AP_Forum_Permissions::PERM_VIEW, $this->db)
        );
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
        $vipPerms = [
            AP_Forum_Permissions::PERM_VIEW,
            AP_Forum_Permissions::PERM_READ,
            AP_Forum_Permissions::PERM_POST_TOPICS,
        ];
        foreach ($vipPerms as $perm) {
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

    public function testNamedGroupTypesAndExcludeSystemQuery(): void
    {
        AP_Group::ensureSystemGroups($this->db);

        $openId = AP_Group::create([
            'group_name' => 'Open Club',
            'group_type' => AP_Group::TYPE_OPEN,
        ], $this->db);
        $closedId = AP_Group::create([
            'group_name' => 'Closed Circle',
            'group_type' => AP_Group::TYPE_CLOSED,
        ], $this->db);
        $hiddenId = AP_Group::create([
            'group_name' => 'Hidden Cell',
            'group_type' => AP_Group::TYPE_HIDDEN,
        ], $this->db);
        $this->assertGreaterThan(0, $openId);
        $this->assertGreaterThan(0, $closedId);
        $this->assertGreaterThan(0, $hiddenId);

        $this->assertSame(AP_Group::TYPE_OPEN, (string) AP_Group::get($openId, $this->db)?->group_type);
        $this->assertSame(AP_Group::TYPE_CLOSED, (string) AP_Group::get($closedId, $this->db)?->group_type);
        $this->assertSame(AP_Group::TYPE_HIDDEN, (string) AP_Group::get($hiddenId, $this->db)?->group_type);

        $this->assertSame(
            [
                AP_Group::TYPE_OPEN,
                AP_Group::TYPE_CLOSED,
                AP_Group::TYPE_HIDDEN,
                AP_Group::TYPE_SYSTEM,
            ],
            AP_Group::groupTypes()
        );

        $named = AP_Group::query(['exclude_system' => true, 'orderby' => 'id'], $this->db);
        $namedIds = array_map(static fn (object $g): int => (int) $g->group_id, $named);
        $namedTypes = array_map(static fn (object $g): string => (string) $g->group_type, $named);
        $this->assertContains($openId, $namedIds);
        $this->assertContains($closedId, $namedIds);
        $this->assertContains($hiddenId, $namedIds);
        $this->assertNotContains(AP_Group::TYPE_SYSTEM, $namedTypes);
        $this->assertSame(3, AP_Group::count(['exclude_system' => true], $this->db));

        $system = AP_Group::query(['type' => AP_Group::TYPE_SYSTEM], $this->db);
        $this->assertCount(4, $system);
        foreach ($system as $row) {
            $this->assertNotContains((int) $row->group_id, $namedIds);
        }
    }

    public function testGroupsAcpAlreadyExistsAndForumEditDoesNotInventOne(): void
    {
        $this->assertFileExists($this->root . '/ap-admin/forum-groups.php');
        $this->assertFileExists($this->root . '/ap-admin/includes/class-ap-admin-forum-groups.php');
        $this->assertFileDoesNotExist($this->root . '/ap-admin/groups.php');
        $this->assertFileDoesNotExist($this->root . '/ap-admin/options-groups.php');
        $this->assertFileDoesNotExist($this->root . '/ap-admin/user-groups.php');

        $screen = (string) file_get_contents($this->root . '/ap-admin/forum-groups.php');
        $this->assertStringContainsString('AP_Admin_Forum_Groups', $screen);
        $this->assertStringContainsString('manage_forums', $screen);

        $edit = (string) file_get_contents(
            $this->root . '/ap-admin/includes/class-ap-admin-forum-edit.php'
        );
        $this->assertStringContainsString('This group only', $edit);
        $this->assertStringContainsString('forum_access_groups[]', $edit);
        $this->assertStringContainsString('renderGroupOnlyPicker', $edit);

        $this->assertSame(
            [
                AP_Forum_Permissions::ACCESS_PUBLIC,
                AP_Forum_Permissions::ACCESS_MEMBERS,
                AP_Forum_Permissions::ACCESS_MEMBERS_READONLY,
                AP_Forum_Permissions::ACCESS_MODERATORS,
                AP_Forum_Permissions::ACCESS_ADMINISTRATORS,
                AP_Forum_Permissions::ACCESS_GROUP_ONLY,
                AP_Forum_Permissions::ACCESS_CUSTOM,
            ],
            AP_Forum_Permissions::accessLevels()
        );
        $this->assertSame(
            'This group only',
            AP_Forum_Permissions::accessLevelLabels()[AP_Forum_Permissions::ACCESS_GROUP_ONLY]
        );
    }

    public function testHiddenGroupsHaveNoPublicJoinControl(): void
    {
        AP_Group::ensureSystemGroups($this->db);

        $openId = AP_Group::create([
            'group_name' => 'Public Circle',
            'group_type' => AP_Group::TYPE_OPEN,
        ], $this->db);
        $closedId = AP_Group::create([
            'group_name' => 'Closed Circle',
            'group_type' => AP_Group::TYPE_CLOSED,
        ], $this->db);
        $hiddenId = AP_Group::create([
            'group_name' => 'Hidden Cell',
            'group_type' => AP_Group::TYPE_HIDDEN,
        ], $this->db);
        $this->assertGreaterThan(0, $openId);
        $this->assertGreaterThan(0, $closedId);
        $this->assertGreaterThan(0, $hiddenId);

        $this->assertTrue(AP_Group::groupTypeAllowsPublicJoin(AP_Group::TYPE_OPEN));
        $this->assertFalse(AP_Group::groupTypeAllowsPublicJoin(AP_Group::TYPE_CLOSED));
        $this->assertFalse(AP_Group::groupTypeAllowsPublicJoin(AP_Group::TYPE_HIDDEN));
        $this->assertFalse(AP_Group::groupTypeAllowsPublicJoin(AP_Group::TYPE_SYSTEM));

        $this->assertTrue(AP_Group::allowsPublicJoin($openId, $this->db));
        $this->assertFalse(AP_Group::allowsPublicJoin($closedId, $this->db));
        $this->assertFalse(AP_Group::allowsPublicJoin($hiddenId, $this->db));
        $this->assertFalse(AP_Group::allowsPublicJoin(0, $this->db));

        $this->assertTrue(AP_Group::groupTypeIsPubliclyListed(AP_Group::TYPE_OPEN));
        $this->assertTrue(AP_Group::groupTypeIsPubliclyListed(AP_Group::TYPE_CLOSED));
        $this->assertFalse(AP_Group::groupTypeIsPubliclyListed(AP_Group::TYPE_HIDDEN));
        $this->assertFalse(AP_Group::groupTypeIsPubliclyListed(AP_Group::TYPE_SYSTEM));
        $this->assertTrue(AP_Group::isPubliclyListed($openId, $this->db));
        $this->assertTrue(AP_Group::isPubliclyListed($closedId, $this->db));
        $this->assertFalse(AP_Group::isPubliclyListed($hiddenId, $this->db));

        $userId = $this->createUser('hidden_join', 'hidden_join@example.test');
        $guest = 0;

        $this->assertSame(0, AP_Group::joinPublic($hiddenId, $userId, $this->db));
        $this->assertFalse(AP_Group::isMember($hiddenId, $userId, $this->db));
        $this->assertSame(0, AP_Group::joinPublic($hiddenId, $guest, $this->db));
        $this->assertSame(0, ap_join_group_public($hiddenId, $userId, $this->db));
        $this->assertFalse(ap_group_allows_public_join($hiddenId, $this->db));

        $this->assertSame(0, AP_Group::joinPublic($closedId, $userId, $this->db));
        $this->assertFalse(AP_Group::isMember($closedId, $userId, $this->db));

        $systemIds = AP_Group::ensureSystemGroups($this->db);
        $registeredGid = (int) $systemIds[AP_Group::SLUG_REGISTERED];
        $this->assertSame(0, AP_Group::joinPublic($registeredGid, $userId, $this->db));

        $openJoin = AP_Group::joinPublic($openId, $userId, $this->db);
        $this->assertGreaterThan(0, $openJoin);
        $this->assertTrue(AP_Group::isMember($openId, $userId, $this->db));
        $this->assertSame($openJoin, AP_Group::joinPublic($openId, $userId, $this->db));

        $this->assertSame(0, AP_Group::joinPublic($openId, $guest, $this->db));

        $acpAdd = AP_Group::addMember($hiddenId, $userId, AP_Group::ROLE_MEMBER, $this->db);
        $this->assertGreaterThan(0, $acpAdd);
        $this->assertTrue(AP_Group::isMember($hiddenId, $userId, $this->db));
        $this->assertSame(0, AP_Group::joinPublic($hiddenId, $userId, $this->db));

        $public = AP_Group::queryPublic(['orderby' => 'id'], $this->db);
        $publicIds = array_map(static fn (object $g): int => (int) $g->group_id, $public);
        $this->assertContains($openId, $publicIds);
        $this->assertContains($closedId, $publicIds);
        $this->assertNotContains($hiddenId, $publicIds);
        $this->assertNotContains($registeredGid, $publicIds);

        $helperPublic = ap_get_public_groups(['orderby' => 'id'], $this->db);
        $helperIds = array_map(static fn (object $g): int => (int) $g->group_id, $helperPublic);
        $this->assertSame($publicIds, $helperIds);

        $noHidden = AP_Group::query(['exclude_hidden' => true, 'exclude_system' => true], $this->db);
        $noHiddenIds = array_map(static fn (object $g): int => (int) $g->group_id, $noHidden);
        $this->assertContains($openId, $noHiddenIds);
        $this->assertContains($closedId, $noHiddenIds);
        $this->assertNotContains($hiddenId, $noHiddenIds);
        $this->assertSame(2, AP_Group::count(['public' => true], $this->db));

        $front = (string) file_get_contents($this->root . '/ap-includes/class-ap-forum-front.php');
        $this->assertStringNotContainsString('ACTION_JOIN_GROUP', $front);
        $this->assertStringNotContainsString('ap_group_join', $front);
        $this->assertStringNotContainsString('joinPublic', $front);
        $this->assertDoesNotMatchRegularExpression('/handleJoinGroup/', $front);

        $rest = (string) file_get_contents($this->root . '/ap-includes/class-ap-rest.php');
        $this->assertStringNotContainsString('/groups', $rest);
        $this->assertStringNotContainsString('joinPublic', $rest);
        $this->assertStringNotContainsString('ap_group_join', $rest);

        $themeDir = $this->root . '/ap-content/themes/agora';
        foreach (['forum.php', 'forum-view.php', 'topic.php', 'forum-search.php', 'functions.php'] as $file) {
            $src = (string) file_get_contents($themeDir . '/' . $file);
            $this->assertDoesNotMatchRegularExpression(
                '/join[-_ ]?(this[-_ ])?group|ap_group_join|ap_join_group|ap_forum_join_group/i',
                $src,
                $file . ' must not render a public group Join control'
            );
        }

        $this->assertFileExists($this->root . '/ap-admin/forum-groups.php');
        $acpScreen = (string) file_get_contents($this->root . '/ap-admin/forum-groups.php');
        $this->assertStringContainsString('add-member', $acpScreen);
        $this->assertStringContainsString('AP_Admin_Forum_Groups', $acpScreen);
        $this->assertStringNotContainsString('joinPublic', $acpScreen);

        $acpClass = (string) file_get_contents(
            $this->root . '/ap-admin/includes/class-ap-admin-forum-groups.php'
        );
        $this->assertStringContainsString('no Join control', $acpClass);
        $this->assertStringContainsString('Add Member', $acpClass);
        $this->assertStringContainsString('function addMember', $acpClass);
        $this->assertStringNotContainsString('joinPublic', $acpClass);
        $this->assertDoesNotMatchRegularExpression(
            '/name="ap_forum_action"[^>]*join|Join this group|Join group/i',
            $acpClass
        );
    }

    public function testThisGroupOnlyPresetStoresNamedGroupsAndRejectsSystem(): void
    {
        AP_Group::ensureSystemGroups($this->db);
        $vip = AP_Group::create([
            'group_name' => 'Lounge VIP',
            'group_type' => AP_Group::TYPE_CLOSED,
        ], $this->db);
        $open = AP_Group::create([
            'group_name' => 'Open Circle',
            'group_type' => AP_Group::TYPE_OPEN,
        ], $this->db);
        $this->assertGreaterThan(0, $vip);
        $this->assertGreaterThan(0, $open);
        $systemIds = AP_Group::ensureSystemGroups($this->db);
        $guestGid = (int) $systemIds[AP_Group::SLUG_GUESTS];

        $forumId = AP_Forum::insertForum(['forum_name' => 'Private Room'], $this->db);
        $this->assertGreaterThan(0, $forumId);

        $parsed = AP_Forum_Permissions::parseAccessFormInput([
            'forum_access_level' => 'group_only',
            'forum_access_groups' => [$vip, $open, $guestGid, 0, 'nope'],
        ]);
        $this->assertSame(AP_Forum_Permissions::ACCESS_GROUP_ONLY, $parsed['level']);
        $this->assertContains($vip, $parsed['group_ids']);
        $this->assertContains($open, $parsed['group_ids']);
        $this->assertContains($guestGid, $parsed['group_ids']);
        $this->assertSame(
            AP_Forum_Permissions::ACCESS_GROUP_ONLY,
            AP_Forum_Permissions::normalizeAccessLevel('group_only')
        );

        $this->assertTrue(AP_Forum_Permissions::saveAccessFromForm($forumId, [
            'forum_access_level' => 'group_only',
            'forum_access_groups' => [$vip, $open, $guestGid],
        ], $this->db));
        $this->assertTrue(AP_Forum_Permissions::isGroupOnlyForum($forumId, $this->db));
        $saved = AP_Forum_Permissions::getAccessGroupIds($forumId, $this->db);
        sort($saved);
        $expect = [$vip, $open];
        sort($expect);
        $this->assertSame($expect, $saved);
        $this->assertSame(
            AP_Forum_Permissions::ACCESS_GROUP_ONLY,
            AP_Forum_Permissions::detectAccessLevel($forumId, $this->db)
        );
        $this->assertFalse(AP_Forum_Permissions::applyAccessLevel(
            $forumId,
            AP_Forum_Permissions::ACCESS_GROUP_ONLY,
            $this->db
        ));
        $this->assertTrue(AP_Forum_Permissions::isGroupOnlyForum($forumId, $this->db));

        $picker = AP_Forum_Permissions::namedGroupsForPicker($this->db);
        $pickerIds = array_map(static fn (object $g): int => (int) $g->group_id, $picker);
        $this->assertContains($vip, $pickerIds);
        $this->assertContains($open, $pickerIds);
        $this->assertNotContains($guestGid, $pickerIds);

        $this->assertTrue(AP_Forum_Permissions::applyAccessLevel(
            $forumId,
            AP_Forum_Permissions::ACCESS_MEMBERS,
            $this->db
        ));
        $this->assertFalse(AP_Forum_Permissions::isGroupOnlyForum($forumId, $this->db));
        $this->assertSame([], AP_Forum_Permissions::getAccessGroupIds($forumId, $this->db));
        $this->assertSame(
            AP_Forum_Permissions::ACCESS_MEMBERS,
            AP_Forum_Permissions::detectAccessLevel($forumId, $this->db)
        );

        AP_Forum_Permissions::saveAccessFromForm($forumId, [
            'forum_access_level' => 'group_only',
            'forum_access_groups' => [$vip],
        ], $this->db);
        $this->assertTrue(AP_Forum::deleteForum($forumId, true, $this->db));
        $this->assertSame([], AP_Forum_Permissions::getAccessGroupIds($forumId, $this->db));
    }

    public function testThisGroupOnlyApplyDeniesGuestsNotRegisteredAndAllowsNamedGroup(): void
    {
        AP_Forum_Permissions::ensureDefaults($this->db);
        $systemIds = AP_Group::ensureSystemGroups($this->db);
        $guestGid = (int) $systemIds[AP_Group::SLUG_GUESTS];
        $registeredGid = (int) $systemIds[AP_Group::SLUG_REGISTERED];
        $adminGid = (int) $systemIds[AP_Group::SLUG_ADMINISTRATORS];

        $vip = AP_Group::create([
            'group_name' => 'Apply VIP',
            'group_type' => AP_Group::TYPE_CLOSED,
        ], $this->db);
        $other = AP_Group::create([
            'group_name' => 'Other Circle',
            'group_type' => AP_Group::TYPE_OPEN,
        ], $this->db);
        $this->assertGreaterThan(0, $vip);
        $this->assertGreaterThan(0, $other);

        $forumId = AP_Forum::insertForum(['forum_name' => 'Group Room'], $this->db);
        $this->assertGreaterThan(0, $forumId);

        // Leftover members-only registered ALLOW must be cleared, not flipped to deny.
        $this->assertTrue(AP_Forum_Permissions::applyAccessLevel(
            $forumId,
            AP_Forum_Permissions::ACCESS_MEMBERS,
            $this->db
        ));
        $this->assertNotSame(
            [],
            AP_Forum_Permissions::getGroupPermissions($forumId, $registeredGid, $this->db)
        );

        $this->assertTrue(AP_Forum_Permissions::saveAccessFromForm($forumId, [
            'forum_access_level' => 'group_only',
            'forum_access_groups' => [$vip, $other],
        ], $this->db));

        $guestRows = AP_Forum_Permissions::getGroupPermissions($forumId, $guestGid, $this->db);
        $this->assertFalse($guestRows[AP_Forum_Permissions::PERM_VIEW] ?? true);
        $this->assertFalse($guestRows[AP_Forum_Permissions::PERM_READ] ?? true);

        $this->assertSame(
            [],
            AP_Forum_Permissions::getGroupPermissions($forumId, $registeredGid, $this->db),
            'Must not stamp virtual registered (deny-wins would lock named-group members out)'
        );

        $adminRows = AP_Forum_Permissions::getGroupPermissions($forumId, $adminGid, $this->db);
        foreach (AP_Forum_Permissions::allPermissions() as $perm) {
            $this->assertTrue($adminRows[$perm] ?? false, "Administrators must be allowed {$perm}");
        }

        $vipRows = AP_Forum_Permissions::getGroupPermissions($forumId, $vip, $this->db);
        $memberPerms = AP_Forum_Permissions::baselinePermissionsForLevel(
            AP_Forum_Permissions::LEVEL_REGISTERED
        );
        foreach ($memberPerms as $perm) {
            $this->assertTrue($vipRows[$perm] ?? false, "Named group must be allowed {$perm}");
        }

        $guestUser = 0;
        $outsider = $this->createUser('go_out', 'go_out@example.test', 'subscriber');
        $member = $this->createUser('go_vip', 'go_vip@example.test', 'subscriber');
        $admin = $this->createUser('go_admin', 'go_admin@example.test', 'administrator');
        AP_Group::addMember($vip, $member, AP_Group::ROLE_MEMBER, $this->db);

        $this->assertFalse(AP_Forum_Permissions::userCanViewForum($guestUser, $forumId, $this->db));
        $this->assertFalse(AP_Forum_Permissions::userCanViewForum($outsider, $forumId, $this->db));
        $this->assertFalse(AP_Forum_Permissions::userCanPostTopic($outsider, $forumId, $this->db));
        $this->assertTrue(AP_Forum_Permissions::userCanViewForum($member, $forumId, $this->db));
        $this->assertTrue(AP_Forum_Permissions::userCanPostTopic($member, $forumId, $this->db));
        $this->assertTrue(AP_Forum_Permissions::userCanViewForum($admin, $forumId, $this->db));

        // Dropping a chosen group clears that group's forum rows; remaining group still works.
        $this->assertTrue(AP_Forum_Permissions::applyGroupOnlyAccess($forumId, [$vip], $this->db));
        $this->assertSame([], AP_Forum_Permissions::getGroupPermissions($forumId, $other, $this->db));
        $this->assertSame([$vip], AP_Forum_Permissions::getAccessGroupIds($forumId, $this->db));
        $this->assertTrue(AP_Forum_Permissions::userCanViewForum($member, $forumId, $this->db));

        $this->assertTrue(AP_Forum_Permissions::applyAccessLevel(
            $forumId,
            AP_Forum_Permissions::ACCESS_ADMINISTRATORS,
            $this->db
        ));
        $this->assertSame([], AP_Forum_Permissions::getGroupPermissions($forumId, $vip, $this->db));
        $this->assertFalse(AP_Forum_Permissions::isGroupOnlyForum($forumId, $this->db));
        $this->assertFalse(AP_Forum_Permissions::userCanViewForum($member, $forumId, $this->db));
        $this->assertTrue(AP_Forum_Permissions::userCanViewForum($admin, $forumId, $this->db));
    }

    public function testThisGroupOnlySiteWideModeratorCannotEnterUnlessMember(): void
    {
        AP_Forum_Permissions::ensureDefaults($this->db);
        $systemIds = AP_Group::ensureSystemGroups($this->db);
        $moderatorGid = (int) $systemIds[AP_Group::SLUG_GLOBAL_MODERATORS];
        $this->assertGreaterThan(0, $moderatorGid);

        $vip = AP_Group::create([
            'group_name' => 'Staff Circle',
            'group_type' => AP_Group::TYPE_CLOSED,
        ], $this->db);
        $this->assertGreaterThan(0, $vip);

        $forumId = AP_Forum::insertForum(['forum_name' => 'Private Circle'], $this->db);
        $this->assertGreaterThan(0, $forumId);

        // Leftover "Moderators only" rows must not keep site-wide mods inside.
        $this->assertTrue(AP_Forum_Permissions::applyAccessLevel(
            $forumId,
            AP_Forum_Permissions::ACCESS_MODERATORS,
            $this->db
        ));

        $outsiderMod = $this->createUser('go_mod_out', 'go_mod_out@example.test', 'editor');
        $memberMod = $this->createUser('go_mod_in', 'go_mod_in@example.test', 'editor');
        $admin = $this->createUser('go_mod_admin', 'go_mod_admin@example.test', 'administrator');
        $member = $this->createUser('go_mod_mem', 'go_mod_mem@example.test', 'subscriber');
        AP_Group::addMember($vip, $memberMod, AP_Group::ROLE_MEMBER, $this->db);
        AP_Group::addMember($vip, $member, AP_Group::ROLE_MEMBER, $this->db);

        $this->assertTrue(AP_Roles::userCan($outsiderMod, 'moderate_forums', null, $this->db));
        $this->assertFalse(AP_Roles::userCan($outsiderMod, 'manage_forums', null, $this->db));
        $this->assertTrue(AP_Forum_Permissions::userCanViewForum($outsiderMod, $forumId, $this->db));

        $this->assertTrue(AP_Forum_Permissions::saveAccessFromForm($forumId, [
            'forum_access_level' => 'group_only',
            'forum_access_groups' => [$vip],
        ], $this->db));

        $this->assertSame(
            [],
            AP_Forum_Permissions::getGroupPermissions($forumId, $moderatorGid, $this->db),
            'Must not stamp virtual global_moderators on a group-only forum'
        );

        $this->assertFalse(AP_Forum_Permissions::userCanViewForum($outsiderMod, $forumId, $this->db));
        $this->assertFalse(AP_Forum_Permissions::userCan(
            $outsiderMod,
            $forumId,
            AP_Forum_Permissions::PERM_READ,
            $this->db
        ));
        $this->assertFalse(AP_Forum_Permissions::userCanModerate($outsiderMod, $forumId, $this->db));
        $this->assertFalse(AP_Forum_Permissions::userCan(
            $outsiderMod,
            $forumId,
            AP_Forum_Permissions::PERM_LOCK,
            $this->db
        ));
        $this->assertFalse(AP_Forum_Permissions::userCanPostTopic($outsiderMod, $forumId, $this->db));

        $this->assertTrue(AP_Forum_Permissions::userCanViewForum($member, $forumId, $this->db));
        $this->assertFalse(AP_Forum_Permissions::userCanModerate($member, $forumId, $this->db));

        $this->assertTrue(AP_Forum_Permissions::userCanViewForum($memberMod, $forumId, $this->db));
        $this->assertTrue(AP_Forum_Permissions::userCanPostTopic($memberMod, $forumId, $this->db));
        $this->assertTrue(AP_Forum_Permissions::userCanModerate($memberMod, $forumId, $this->db));
        $this->assertTrue(AP_Forum_Permissions::userCan(
            $memberMod,
            $forumId,
            AP_Forum_Permissions::PERM_LOCK,
            $this->db
        ));

        $this->assertTrue(AP_Forum_Permissions::userCanViewForum($admin, $forumId, $this->db));
        $this->assertTrue(AP_Forum_Permissions::userCanModerate($admin, $forumId, $this->db));

        $post = (object) [
            'post_id' => 1,
            'forum_id' => $forumId,
            'poster_id' => $member,
        ];
        $this->assertFalse(AP_Forum::userCanEditPost($outsiderMod, $post, $this->db));
        $this->assertFalse(AP_Forum::userCanDeletePost($outsiderMod, $post, $this->db));
        $this->assertTrue(AP_Forum::userCanEditPost($memberMod, $post, $this->db));
        $this->assertTrue(AP_Forum::userCanDeletePost($memberMod, $post, $this->db));
        $this->assertTrue(AP_Forum::userCanEditPost($admin, $post, $this->db));
        $this->assertTrue(AP_Forum::userCanEditPost($member, $post, $this->db));
    }

    /**
     * Board index + direct URL: hide unlistable forums and empty parents.
     *
     * Search is {@see testThisGroupOnlySearchHidesForumAndEmptyParent()}.
     * Feeds are {@see testThisGroupOnlyFeedsHideForumAndEmptyParent()}.
     * Sitemap is {@see testThisGroupOnlySitemapHidesForumAndEmptyParent()}.
     * REST is {@see testThisGroupOnlyRestHidesForumAndEmptyParent()}.
     * Pretty-URL surfaces are covered by
     * {@see testThisGroupOnlyListingHygieneHidesBoardFromOutsiders()}.
     */
    public function testThisGroupOnlyBoardIndexHidesForumAndEmptyParent(): void
    {
        require_once $this->root . '/ap-includes/hooks.php';
        require_once $this->root . '/ap-includes/class-ap-query.php';
        require_once $this->root . '/ap-includes/class-ap-session.php';
        require_once $this->root . '/ap-includes/class-ap-forum-front.php';
        require_once $this->root . '/ap-includes/class-ap-theme.php';
        require_once $this->root . '/ap-admin/includes/class-ap-forums-list-table.php';

        foreach (
            [
                'AP_LOGGED_IN_KEY' => 'zx9-idx-logged-in-key',
                'AP_LOGGED_IN_SALT' => 'zx9-idx-logged-in-salt',
                'AP_AUTH_KEY' => 'zx9-idx-auth-key',
                'AP_AUTH_SALT' => 'zx9-idx-auth-salt',
            ] as $const => $value
        ) {
            if (!defined($const)) {
                define($const, $value);
            }
        }

        if (function_exists('ap_reset_hooks')) {
            ap_reset_hooks();
        }
        AP_Session::clearAuthCookie();
        AP_Session::resetCurrentUser();

        $GLOBALS['apdb'] = $this->db;
        AP_Options::update('home', 'https://example.test', $this->db);
        AP_Options::update('siteurl', 'https://example.test', $this->db);
        AP_Options::update('blogname', 'Board Index Hygiene', $this->db);
        AP_Options::update('ap_module_forum', '1', $this->db);
        AP_Options::flushCache();

        AP_Forum_Permissions::ensureDefaults($this->db);
        AP_Group::ensureSystemGroups($this->db);

        $vip = AP_Group::create([
            'group_name' => 'ZX9 Index Circle',
            'group_type' => AP_Group::TYPE_CLOSED,
        ], $this->db);
        $this->assertGreaterThan(0, $vip);

        $categoryId = AP_Forum::insertForum([
            'forum_name' => 'ZX9IndexCellar',
            'forum_type' => AP_Forum::FORUM_TYPE_CATEGORY,
        ], $this->db);
        $secretId = AP_Forum::insertForum([
            'forum_name' => 'ZX9IndexVault',
            'forum_type' => AP_Forum::FORUM_TYPE_FORUM,
            'parent_id' => $categoryId,
        ], $this->db);
        $publicId = AP_Forum::insertForum([
            'forum_name' => 'ZX9IndexSquare',
            'forum_type' => AP_Forum::FORUM_TYPE_FORUM,
        ], $this->db);
        $mixedCatId = AP_Forum::insertForum([
            'forum_name' => 'ZX9IndexHall',
            'forum_type' => AP_Forum::FORUM_TYPE_CATEGORY,
        ], $this->db);
        $mixedPublicId = AP_Forum::insertForum([
            'forum_name' => 'ZX9IndexLobby',
            'forum_type' => AP_Forum::FORUM_TYPE_FORUM,
            'parent_id' => $mixedCatId,
        ], $this->db);
        $mixedSecretId = AP_Forum::insertForum([
            'forum_name' => 'ZX9IndexBackroom',
            'forum_type' => AP_Forum::FORUM_TYPE_FORUM,
            'parent_id' => $mixedCatId,
        ], $this->db);
        $this->assertGreaterThan(0, $categoryId);
        $this->assertGreaterThan(0, $secretId);
        $this->assertGreaterThan(0, $publicId);
        $this->assertGreaterThan(0, $mixedCatId);
        $this->assertGreaterThan(0, $mixedPublicId);
        $this->assertGreaterThan(0, $mixedSecretId);

        $this->assertTrue(AP_Forum_Permissions::saveAccessFromForm($secretId, [
            'forum_access_level' => 'group_only',
            'forum_access_groups' => [$vip],
        ], $this->db));
        $this->assertTrue(AP_Forum_Permissions::saveAccessFromForm($mixedSecretId, [
            'forum_access_level' => 'group_only',
            'forum_access_groups' => [$vip],
        ], $this->db));

        $outsider = $this->createUser('zx9_idx_out', 'zx9_idx_out@example.test', 'subscriber');
        $member = $this->createUser('zx9_idx_vip', 'zx9_idx_vip@example.test', 'subscriber');
        $moderator = $this->createUser('zx9_idx_mod', 'zx9_idx_mod@example.test', 'editor');
        $admin = $this->createUser('zx9_idx_admin', 'zx9_idx_admin@example.test', 'administrator');
        AP_Group::addMember($vip, $member, AP_Group::ROLE_MEMBER, $this->db);

        $secretTopicId = AP_Forum::createTopic([
            'forum_id' => $secretId,
            'topic_title' => 'ZX9IndexSecretToken thread',
            'content' => 'ZX9IndexSecretBody stays off the index.',
            'poster_id' => $member,
        ], $this->db);
        $this->assertGreaterThan(0, $secretTopicId);

        $secretForum = AP_Forum::getForum($secretId, $this->db);
        $secretTopic = AP_Forum::getTopic($secretTopicId, $this->db);
        $this->assertNotNull($secretForum);
        $this->assertNotNull($secretTopic);
        $secretSlug = (string) $secretForum->forum_slug;
        $secretTopicSlug = (string) $secretTopic->topic_slug;
        $this->assertNotSame('', $secretSlug);
        $this->assertNotSame('', $secretTopicSlug);

        $secretNeedles = [
            'ZX9IndexCellar',
            'ZX9IndexVault',
            'ZX9IndexBackroom',
            'ZX9IndexSecretToken',
            'ZX9IndexSecretBody',
            $secretSlug,
            $secretTopicSlug,
        ];

        $guestIndex = AP_Forum::getIndexData($this->db, ['user_id' => 0]);
        $guestNames = $this->indexVisibleText($guestIndex);
        $this->assertContains('ZX9IndexSquare', $guestNames);
        $this->assertContains('ZX9IndexHall', $guestNames);
        $this->assertContains('ZX9IndexLobby', $guestNames);
        $this->assertSame([], $this->needlesFound($guestNames, $secretNeedles));

        $outsiderIndex = AP_Forum::getIndexData($this->db, ['user_id' => $outsider]);
        $this->assertSame([], $this->needlesFound($this->indexVisibleText($outsiderIndex), $secretNeedles));

        $modIndex = AP_Forum::getIndexData($this->db, ['user_id' => $moderator]);
        $this->assertSame([], $this->needlesFound($this->indexVisibleText($modIndex), $secretNeedles));
        $this->assertFalse(AP_Roles::userCan($moderator, 'manage_forums', null, $this->db));
        $this->assertTrue(AP_Roles::userCan($moderator, 'moderate_forums', null, $this->db));

        $memberIndex = AP_Forum::getIndexData($this->db, ['user_id' => $member]);
        $memberNames = $this->indexVisibleText($memberIndex);
        $this->assertContains('ZX9IndexCellar', $memberNames);
        $this->assertContains('ZX9IndexVault', $memberNames);
        $this->assertContains('ZX9IndexBackroom', $memberNames);

        $adminIndex = AP_Forum::getIndexData($this->db, ['user_id' => $admin]);
        $adminNames = $this->indexVisibleText($adminIndex);
        $this->assertContains('ZX9IndexVault', $adminNames);
        $this->assertContains('ZX9IndexCellar', $adminNames);

        $this->assertFalse(AP_Forum::isListableToUser(0, $secretId, $this->db));
        $this->assertFalse(AP_Forum::isListableToUser(0, $categoryId, $this->db));
        $this->assertTrue(AP_Forum::isListableToUser($member, $secretId, $this->db));
        $this->assertTrue(AP_Forum::isListableToUser($member, $categoryId, $this->db));

        $guestForumArgs = AP_Forum_Front::enrichQueryArgs([
            'ap_forum_view' => 'forum',
            'forum_slug' => $secretSlug,
        ], $this->db);
        $this->assertTrue(!empty($guestForumArgs['ap_forum_cannot_view']));
        $this->assertSame(AP_Forum_Front::CANNOT_VIEW_MESSAGE, $guestForumArgs['ap_forum_cannot_view_message'] ?? null);
        $this->assertSame('', (string) ($guestForumArgs['forum_name'] ?? 'x'));
        $this->assertSame('', (string) ($guestForumArgs['forum_slug'] ?? 'x'));
        $this->assertSame(0, (int) ($guestForumArgs['forum_id'] ?? -1));
        $this->assertTrue(!empty($guestForumArgs['is_404']));
        $this->assertSame([], $this->needlesFound(
            [json_encode($guestForumArgs, JSON_UNESCAPED_SLASHES) ?: ''],
            $secretNeedles
        ));

        AP_Session::resetCurrentUser();
        $stuffedDirect = new AP_Query([
            'ap_forum_view' => 'forum',
            'forum_id' => $secretId,
            'forum_slug' => $secretSlug,
            'forum_name' => 'ZX9IndexVault',
            'forum_desc' => 'ZX9IndexSecretBody stays off the index.',
        ], $this->db);
        AP_Forum_Front::applyToQuery($stuffedDirect, $this->db);
        $this->assertTrue(!empty($stuffedDirect->get('ap_forum_cannot_view', false)));
        $this->assertSame(
            AP_Forum_Front::CANNOT_VIEW_MESSAGE,
            (string) $stuffedDirect->get('ap_forum_cannot_view_message', '')
        );
        $this->assertSame('', (string) $stuffedDirect->get('forum_name', 'x'));
        $this->assertSame('', (string) $stuffedDirect->get('forum_slug', 'x'));
        $this->assertSame('', (string) $stuffedDirect->get('forum_desc', 'x'));
        $this->assertSame(0, (int) $stuffedDirect->get('forum_id', -1));
        $this->assertTrue($stuffedDirect->is_404);
        $this->assertSame([], $this->needlesFound(
            [json_encode($stuffedDirect->query_vars, JSON_UNESCAPED_SLASHES) ?: ''],
            $secretNeedles
        ));

        $emptyParentDirect = new AP_Query([
            'ap_forum_view' => 'forum',
            'forum_id' => $categoryId,
            'forum_name' => 'ZX9IndexCellar',
        ], $this->db);
        AP_Forum_Front::applyToQuery($emptyParentDirect, $this->db);
        $this->assertTrue(!empty($emptyParentDirect->get('ap_forum_cannot_view', false)));
        $this->assertSame(
            AP_Forum_Front::CANNOT_VIEW_MESSAGE,
            (string) $emptyParentDirect->get('ap_forum_cannot_view_message', '')
        );
        $this->assertSame('', (string) $emptyParentDirect->get('forum_name', 'x'));
        $this->assertSame(0, (int) $emptyParentDirect->get('forum_id', -1));

        $guestTopicArgs = AP_Forum_Front::enrichQueryArgs([
            'ap_forum_view' => 'topic',
            'topic_slug' => $secretTopicSlug,
        ], $this->db);
        $this->assertTrue(!empty($guestTopicArgs['ap_forum_cannot_view']));
        $this->assertSame('', (string) ($guestTopicArgs['topic_title'] ?? 'x'));
        $this->assertSame('', (string) ($guestTopicArgs['forum_name'] ?? 'x'));
        $this->assertSame([], $this->needlesFound(
            [json_encode($guestTopicArgs, JSON_UNESCAPED_SLASHES) ?: ''],
            $secretNeedles
        ));

        $deniedQuery = new AP_Query($guestForumArgs, $this->db);
        $this->assertTrue($deniedQuery->is_404);
        $this->assertSame('404.php', AP_Theme::getHierarchy($deniedQuery, $this->db)[0] ?? null);

        AP_Options::update('stylesheet', 'agora', $this->db);
        AP_Options::update('template', 'agora', $this->db);
        AP_Theme::reset();
        $GLOBALS['ap_query'] = $deniedQuery;
        ob_start();
        AP_Theme::render($deniedQuery, $this->db);
        $deniedHtml = (string) ob_get_clean();
        $this->assertStringContainsString(AP_Forum_Front::CANNOT_VIEW_MESSAGE, $deniedHtml);
        $this->assertSame([], $this->needlesFound([$deniedHtml], $secretNeedles));
        $this->assertStringNotContainsString('forum-view', $deniedHtml);
        $this->assertStringNotContainsString('ap-forum--index', $deniedHtml);

        AP_Session::resetCurrentUser();
        $indexQuery = new AP_Query([
            'ap_forum_view' => 'index',
            'ap_forum' => '1',
        ], $this->db);
        AP_Forum_Front::applyToQuery($indexQuery, $this->db);
        $GLOBALS['ap_query'] = $indexQuery;
        ob_start();
        AP_Theme::render($indexQuery, $this->db);
        $guestIndexHtml = (string) ob_get_clean();
        $this->assertStringContainsString('ap-forum--index', $guestIndexHtml);
        $this->assertStringContainsString('ZX9IndexSquare', $guestIndexHtml);
        $this->assertStringContainsString('ZX9IndexHall', $guestIndexHtml);
        $this->assertStringContainsString('ZX9IndexLobby', $guestIndexHtml);
        $this->assertSame([], $this->needlesFound([$guestIndexHtml], $secretNeedles));
        $this->assertStringNotContainsString(AP_Forum_Front::CANNOT_VIEW_MESSAGE, $guestIndexHtml);

        $this->assertTrue(AP_Session::setAuthCookie($member, false, $this->db));
        $memberIndexQuery = new AP_Query([
            'ap_forum_view' => 'index',
            'ap_forum' => '1',
        ], $this->db);
        AP_Forum_Front::applyToQuery($memberIndexQuery, $this->db);
        $GLOBALS['ap_query'] = $memberIndexQuery;
        ob_start();
        AP_Theme::render($memberIndexQuery, $this->db);
        $memberIndexHtml = (string) ob_get_clean();
        $this->assertStringContainsString('ZX9IndexVault', $memberIndexHtml);
        $this->assertStringContainsString('ZX9IndexCellar', $memberIndexHtml);
        $this->assertStringContainsString('ZX9IndexBackroom', $memberIndexHtml);
        AP_Session::clearAuthCookie();
        AP_Session::resetCurrentUser();

        unset($GLOBALS['ap_query']);
        AP_Theme::reset();

        $acpTable = new AP_Forums_List_Table($this->db);
        $acpTable->prepareItems([]);
        $acpNames = [];
        foreach ($acpTable->items as $item) {
            $acpNames[] = (string) ($item->forum_name ?? '');
        }
        $this->assertContains('ZX9IndexVault', $acpNames);
        $this->assertContains('ZX9IndexCellar', $acpNames);
        $this->assertContains('ZX9IndexSquare', $acpNames);

        unset($GLOBALS['apdb']);
    }

    /**
     * Forum search: hide unlistable forums and empty parents; direct scoped
     * search is a generic “you cannot view this” 404.
     */
    public function testThisGroupOnlySearchHidesForumAndEmptyParent(): void
    {
        require_once $this->root . '/ap-includes/hooks.php';
        require_once $this->root . '/ap-includes/class-ap-query.php';
        require_once $this->root . '/ap-includes/class-ap-rewrite.php';
        require_once $this->root . '/ap-includes/class-ap-session.php';
        require_once $this->root . '/ap-includes/class-ap-forum-front.php';
        require_once $this->root . '/ap-includes/class-ap-theme.php';
        require_once $this->root . '/ap-includes/class-ap-seo.php';
        require_once $this->root . '/ap-admin/includes/class-ap-forums-list-table.php';

        foreach (
            [
                'AP_LOGGED_IN_KEY' => 'zx9-srch-logged-in-key',
                'AP_LOGGED_IN_SALT' => 'zx9-srch-logged-in-salt',
                'AP_AUTH_KEY' => 'zx9-srch-auth-key',
                'AP_AUTH_SALT' => 'zx9-srch-auth-salt',
            ] as $const => $value
        ) {
            if (!defined($const)) {
                define($const, $value);
            }
        }

        if (function_exists('ap_reset_hooks')) {
            ap_reset_hooks();
        }
        AP_Rewrite::resetCache();
        AP_Seo::reset();
        AP_Session::clearAuthCookie();
        AP_Session::resetCurrentUser();

        $GLOBALS['apdb'] = $this->db;
        AP_Options::update('home', 'https://example.test', $this->db);
        AP_Options::update('siteurl', 'https://example.test', $this->db);
        AP_Options::update('permalink_structure', '/%postname%/', $this->db);
        AP_Options::update('blogname', 'Search Hygiene', $this->db);
        AP_Options::update('ap_module_forum', '1', $this->db);
        AP_Options::flushCache();
        AP_Rewrite::resetCache();

        AP_Forum_Permissions::ensureDefaults($this->db);
        AP_Group::ensureSystemGroups($this->db);

        $vip = AP_Group::create([
            'group_name' => 'ZX9 Search Circle',
            'group_type' => AP_Group::TYPE_CLOSED,
        ], $this->db);
        $this->assertGreaterThan(0, $vip);

        $categoryId = AP_Forum::insertForum([
            'forum_name' => 'ZX9SearchCellar',
            'forum_type' => AP_Forum::FORUM_TYPE_CATEGORY,
        ], $this->db);
        $secretId = AP_Forum::insertForum([
            'forum_name' => 'ZX9SearchVault',
            'forum_type' => AP_Forum::FORUM_TYPE_FORUM,
            'parent_id' => $categoryId,
        ], $this->db);
        $publicId = AP_Forum::insertForum([
            'forum_name' => 'ZX9SearchSquare',
            'forum_type' => AP_Forum::FORUM_TYPE_FORUM,
        ], $this->db);
        $mixedCatId = AP_Forum::insertForum([
            'forum_name' => 'ZX9SearchHall',
            'forum_type' => AP_Forum::FORUM_TYPE_CATEGORY,
        ], $this->db);
        $mixedPublicId = AP_Forum::insertForum([
            'forum_name' => 'ZX9SearchLobby',
            'forum_type' => AP_Forum::FORUM_TYPE_FORUM,
            'parent_id' => $mixedCatId,
        ], $this->db);
        $mixedSecretId = AP_Forum::insertForum([
            'forum_name' => 'ZX9SearchBackroom',
            'forum_type' => AP_Forum::FORUM_TYPE_FORUM,
            'parent_id' => $mixedCatId,
        ], $this->db);
        $this->assertGreaterThan(0, $categoryId);
        $this->assertGreaterThan(0, $secretId);
        $this->assertGreaterThan(0, $publicId);
        $this->assertGreaterThan(0, $mixedCatId);
        $this->assertGreaterThan(0, $mixedPublicId);
        $this->assertGreaterThan(0, $mixedSecretId);

        $this->assertTrue(AP_Forum_Permissions::saveAccessFromForm($secretId, [
            'forum_access_level' => 'group_only',
            'forum_access_groups' => [$vip],
        ], $this->db));
        $this->assertTrue(AP_Forum_Permissions::saveAccessFromForm($mixedSecretId, [
            'forum_access_level' => 'group_only',
            'forum_access_groups' => [$vip],
        ], $this->db));

        $outsider = $this->createUser('zx9_srch_out', 'zx9_srch_out@example.test', 'subscriber');
        $member = $this->createUser('zx9_srch_vip', 'zx9_srch_vip@example.test', 'subscriber');
        $moderator = $this->createUser('zx9_srch_mod', 'zx9_srch_mod@example.test', 'editor');
        $admin = $this->createUser('zx9_srch_admin', 'zx9_srch_admin@example.test', 'administrator');
        AP_Group::addMember($vip, $member, AP_Group::ROLE_MEMBER, $this->db);

        $secretTopicId = AP_Forum::createTopic([
            'forum_id' => $secretId,
            'topic_title' => 'ZX9SearchSecretToken thread',
            'content' => 'ZX9SearchSecretBody stays off search.',
            'poster_id' => $member,
        ], $this->db);
        $publicTopicId = AP_Forum::createTopic([
            'forum_id' => $publicId,
            'topic_title' => 'ZX9SearchTownHello thread',
            'content' => 'Public square chatter.',
            'poster_id' => $outsider,
        ], $this->db);
        $mixedPublicTopicId = AP_Forum::createTopic([
            'forum_id' => $mixedPublicId,
            'topic_title' => 'ZX9SearchLobbyHello thread',
            'content' => 'Hall lobby chatter.',
            'poster_id' => $outsider,
        ], $this->db);
        $mixedSecretTopicId = AP_Forum::createTopic([
            'forum_id' => $mixedSecretId,
            'topic_title' => 'ZX9SearchBackroomToken thread',
            'content' => 'ZX9SearchBackroomBody stays off search.',
            'poster_id' => $member,
        ], $this->db);
        $this->assertGreaterThan(0, $secretTopicId);
        $this->assertGreaterThan(0, $publicTopicId);
        $this->assertGreaterThan(0, $mixedPublicTopicId);
        $this->assertGreaterThan(0, $mixedSecretTopicId);

        $secretForum = AP_Forum::getForum($secretId, $this->db);
        $secretTopic = AP_Forum::getTopic($secretTopicId, $this->db);
        $this->assertNotNull($secretForum);
        $this->assertNotNull($secretTopic);
        $secretSlug = (string) $secretForum->forum_slug;
        $secretTopicSlug = (string) $secretTopic->topic_slug;
        $categorySlug = (string) (AP_Forum::getForum($categoryId, $this->db)->forum_slug ?? '');
        $this->assertNotSame('', $secretSlug);
        $this->assertNotSame('', $secretTopicSlug);
        $this->assertNotSame('', $categorySlug);

        $secretNeedles = [
            'ZX9SearchCellar',
            'ZX9SearchVault',
            'ZX9SearchBackroom',
            'ZX9SearchSecretToken',
            'ZX9SearchSecretBody',
            'ZX9SearchBackroomToken',
            'ZX9SearchBackroomBody',
            $secretSlug,
            $secretTopicSlug,
            $categorySlug,
        ];

        $acl = [
            'type' => 'all',
            'check_permissions' => true,
        ];

        $guestSearch = AP_Forum::search('ZX9SearchSecretToken', $acl + ['user_id' => 0], $this->db);
        $this->assertSame(0, (int) ($guestSearch['total'] ?? -1));
        $this->assertSame([], $guestSearch['results'] ?? ['x']);
        $this->assertSame([], $guestSearch['topics'] ?? ['x']);
        $this->assertSame([], $guestSearch['posts'] ?? ['x']);
        $this->assertSame([], $this->needlesFound($this->searchPayloadText($guestSearch), $secretNeedles));

        $outsiderSearch = AP_Forum::search('ZX9SearchSecretToken', $acl + ['user_id' => $outsider], $this->db);
        $this->assertSame(0, (int) ($outsiderSearch['total'] ?? -1));
        $this->assertSame([], $this->needlesFound($this->searchPayloadText($outsiderSearch), $secretNeedles));

        $modSearch = AP_Forum::search('ZX9SearchSecretToken', $acl + ['user_id' => $moderator], $this->db);
        $this->assertSame(0, (int) ($modSearch['total'] ?? -1));
        $this->assertFalse(AP_Roles::userCan($moderator, 'manage_forums', null, $this->db));
        $this->assertTrue(AP_Roles::userCan($moderator, 'moderate_forums', null, $this->db));

        $memberSearch = AP_Forum::search('ZX9SearchSecretToken', $acl + ['user_id' => $member], $this->db);
        $this->assertNotSame([], $this->needlesFound(
            $this->searchPayloadText($memberSearch),
            ['ZX9SearchSecretToken']
        ));

        $adminSearch = AP_Forum::search('ZX9SearchSecretToken', $acl + ['user_id' => $admin], $this->db);
        $this->assertNotSame([], $this->needlesFound(
            $this->searchPayloadText($adminSearch),
            ['ZX9SearchSecretToken']
        ));

        $publicSearch = AP_Forum::search('ZX9SearchTownHello', $acl + ['user_id' => 0], $this->db);
        $this->assertNotSame([], $this->needlesFound(
            $this->searchPayloadText($publicSearch),
            ['ZX9SearchTownHello']
        ));
        $this->assertSame([], $this->needlesFound($this->searchPayloadText($publicSearch), $secretNeedles));

        $guestNameSearch = AP_Forum::search('ZX9SearchVault', $acl + ['user_id' => 0], $this->db);
        $this->assertSame(0, (int) ($guestNameSearch['total'] ?? -1));
        $guestCatSearch = AP_Forum::search('ZX9SearchCellar', $acl + ['user_id' => 0], $this->db);
        $this->assertSame(0, (int) ($guestCatSearch['total'] ?? -1));

        $guestMixed = AP_Forum::search('ZX9SearchLobbyHello', $acl + ['user_id' => 0], $this->db);
        $this->assertNotSame([], $this->needlesFound(
            $this->searchPayloadText($guestMixed),
            ['ZX9SearchLobbyHello']
        ));
        $guestMixedSecret = AP_Forum::search('ZX9SearchBackroomToken', $acl + ['user_id' => 0], $this->db);
        $this->assertSame(0, (int) ($guestMixedSecret['total'] ?? -1));

        $guestBodySearch = AP_Forum::search(
            'ZX9SearchSecretBody',
            $acl + ['type' => 'posts', 'user_id' => 0],
            $this->db
        );
        $this->assertSame(0, (int) ($guestBodySearch['total'] ?? -1));
        $this->assertSame([], $guestBodySearch['posts'] ?? ['x']);
        $guestBodyTopics = AP_Forum::search(
            'ZX9SearchSecretBody',
            $acl + ['type' => 'topics', 'user_id' => 0],
            $this->db
        );
        $this->assertSame(0, (int) ($guestBodyTopics['total'] ?? -1));

        $guestHelper = ap_forum_search('ZX9SearchSecretToken', ['type' => 'all'], $this->db);
        $this->assertSame(0, (int) ($guestHelper['total'] ?? -1));
        $this->assertSame([], $guestHelper['results'] ?? ['x']);
        $this->assertSame([], $this->needlesFound($this->searchPayloadText($guestHelper), $secretNeedles));

        $guestFront = AP_Forum_Front::enrichQueryArgs([
            'ap_forum_view' => 'search',
            'forum_s' => 'ZX9SearchSecretToken',
        ], $this->db);
        $this->assertSame(0, (int) ($guestFront['forum_search_total'] ?? -1));
        $this->assertSame([], $guestFront['forum_search_results'] ?? ['x']);
        $this->assertSame('Forum search', (string) ($guestFront['forum_name'] ?? ''));
        $this->assertFalse(!empty($guestFront['ap_forum_cannot_view']));
        $this->assertSame([], $this->needlesFound($this->searchPayloadText($guestFront), $secretNeedles));

        $prettySearch = AP_Rewrite::toQueryArgs(
            AP_Rewrite::parseRequest('forums/search/ZX9SearchSecretToken', [], $this->db),
            $this->db
        );
        $this->assertSame(0, (int) ($prettySearch['forum_search_total'] ?? -1));
        $this->assertSame([], $prettySearch['forum_search_results'] ?? ['x']);
        $this->assertSame([], $this->needlesFound(
            [json_encode($prettySearch, JSON_UNESCAPED_SLASHES) ?: ''],
            ['ZX9SearchVault', 'ZX9SearchCellar', 'ZX9SearchSecretBody', $secretSlug, $secretTopicSlug]
        ));

        $scopedSecret = AP_Forum_Front::enrichQueryArgs([
            'ap_forum_view' => 'search',
            'forum_s' => 'ZX9SearchTownHello',
            'forum_id' => $secretId,
            'forum_slug' => $secretSlug,
            'forum_name' => 'ZX9SearchVault',
            'forum_desc' => 'ZX9SearchSecretBody stays off search.',
        ], $this->db);
        $this->assertTrue(!empty($scopedSecret['ap_forum_cannot_view']));
        $this->assertSame(
            AP_Forum_Front::CANNOT_VIEW_MESSAGE,
            $scopedSecret['ap_forum_cannot_view_message'] ?? null
        );
        $this->assertTrue(!empty($scopedSecret['is_404']));
        $this->assertSame('', (string) ($scopedSecret['forum_name'] ?? 'x'));
        $this->assertSame('', (string) ($scopedSecret['forum_slug'] ?? 'x'));
        $this->assertSame('', (string) ($scopedSecret['forum_desc'] ?? 'x'));
        $this->assertSame(0, (int) ($scopedSecret['forum_id'] ?? -1));
        $this->assertSame('', (string) ($scopedSecret['forum_s'] ?? 'x'));
        $this->assertSame('', (string) ($scopedSecret['s'] ?? 'x'));
        $this->assertSame(0, (int) ($scopedSecret['forum_search_total'] ?? -1));
        $this->assertSame([], $scopedSecret['forum_search_results'] ?? ['x']);
        $this->assertSame([], $this->needlesFound(
            [json_encode($scopedSecret, JSON_UNESCAPED_SLASHES) ?: ''],
            $secretNeedles
        ));

        $scopedBoardNameAsTerm = AP_Forum_Front::enrichQueryArgs([
            'ap_forum_view' => 'search',
            'forum_s' => 'ZX9SearchVault',
            's' => 'ZX9SearchVault',
            'forum_id' => $secretId,
            'forum_slug' => $secretSlug,
            'forum_name' => 'ZX9SearchVault',
        ], $this->db);
        $this->assertTrue(!empty($scopedBoardNameAsTerm['ap_forum_cannot_view']));
        $this->assertSame(
            AP_Forum_Front::CANNOT_VIEW_MESSAGE,
            $scopedBoardNameAsTerm['ap_forum_cannot_view_message'] ?? null
        );
        $this->assertSame('', (string) ($scopedBoardNameAsTerm['forum_s'] ?? 'x'));
        $this->assertSame('', (string) ($scopedBoardNameAsTerm['s'] ?? 'x'));
        $this->assertSame('', (string) ($scopedBoardNameAsTerm['forum_name'] ?? 'x'));
        $this->assertSame('', (string) ($scopedBoardNameAsTerm['forum_slug'] ?? 'x'));
        $this->assertSame([], $this->needlesFound(
            [json_encode($scopedBoardNameAsTerm, JSON_UNESCAPED_SLASHES) ?: ''],
            $secretNeedles
        ));

        $scopedEmptyParent = AP_Forum_Front::enrichQueryArgs([
            'ap_forum_view' => 'search',
            'forum_s' => 'ZX9SearchTownHello',
            'forum_id' => $categoryId,
            'forum_name' => 'ZX9SearchCellar',
            'forum_slug' => $categorySlug,
        ], $this->db);
        $this->assertTrue(!empty($scopedEmptyParent['ap_forum_cannot_view']));
        $this->assertSame('', (string) ($scopedEmptyParent['forum_name'] ?? 'x'));
        $this->assertSame('', (string) ($scopedEmptyParent['forum_slug'] ?? 'x'));
        $this->assertSame(0, (int) ($scopedEmptyParent['forum_id'] ?? -1));
        $this->assertSame([], $this->needlesFound(
            [json_encode($scopedEmptyParent, JSON_UNESCAPED_SLASHES) ?: ''],
            $secretNeedles
        ));

        $scopedBySlugOnly = AP_Forum_Front::enrichQueryArgs([
            'ap_forum_view' => 'search',
            'forum_s' => 'ZX9SearchTownHello',
            'forum_slug' => $secretSlug,
        ], $this->db);
        $this->assertTrue(!empty($scopedBySlugOnly['ap_forum_cannot_view']));
        $this->assertSame(
            AP_Forum_Front::CANNOT_VIEW_MESSAGE,
            $scopedBySlugOnly['ap_forum_cannot_view_message'] ?? null
        );
        $this->assertSame('', (string) ($scopedBySlugOnly['forum_slug'] ?? 'x'));
        $this->assertSame('', (string) ($scopedBySlugOnly['forum_name'] ?? 'x'));
        $this->assertSame(0, (int) ($scopedBySlugOnly['forum_id'] ?? -1));
        $this->assertSame([], $this->needlesFound(
            [json_encode($scopedBySlugOnly, JSON_UNESCAPED_SLASHES) ?: ''],
            $secretNeedles
        ));

        $scopedEmptyTerm = AP_Forum_Front::enrichQueryArgs([
            'ap_forum_view' => 'search',
            'forum_s' => '',
            'forum_id' => $secretId,
            'forum_slug' => $secretSlug,
            'forum_name' => 'ZX9SearchVault',
        ], $this->db);
        $this->assertTrue(!empty($scopedEmptyTerm['ap_forum_cannot_view']));
        $this->assertSame('', (string) ($scopedEmptyTerm['forum_name'] ?? 'x'));
        $this->assertSame('', (string) ($scopedEmptyTerm['forum_slug'] ?? 'x'));
        $this->assertSame('', (string) ($scopedEmptyTerm['forum_s'] ?? 'x'));
        $this->assertSame(0, (int) ($scopedEmptyTerm['forum_id'] ?? -1));
        $this->assertSame([], $this->needlesFound(
            [json_encode($scopedEmptyTerm, JSON_UNESCAPED_SLASHES) ?: ''],
            $secretNeedles
        ));

        $unviewableSearchQuery = new AP_Query([
            'ap_forum_view' => 'search',
            'forum_s' => 'ZX9SearchSecretToken',
            'forum_id' => $secretId,
            'forum_slug' => $secretSlug,
        ], $this->db);
        $fallbackSearch = AP_Forum_Front::searchForQuery($unviewableSearchQuery, $this->db);
        $this->assertSame(0, (int) ($fallbackSearch['total'] ?? -1));
        $this->assertSame([], $fallbackSearch['results'] ?? ['x']);
        $this->assertSame([], $fallbackSearch['topics'] ?? ['x']);
        $this->assertSame([], $fallbackSearch['posts'] ?? ['x']);
        $slugOnlyFallback = AP_Forum_Front::searchForQuery(new AP_Query([
            'ap_forum_view' => 'search',
            'forum_s' => 'ZX9SearchTownHello',
            'forum_slug' => $secretSlug,
        ], $this->db), $this->db);
        $this->assertSame(0, (int) ($slugOnlyFallback['total'] ?? -1));
        $this->assertSame([], $slugOnlyFallback['results'] ?? ['x']);

        AP_Session::resetCurrentUser();
        $stuffedSearch = new AP_Query([
            'ap_forum_view' => 'search',
            'forum_s' => 'ZX9SearchTownHello',
            'forum_id' => $secretId,
            'forum_slug' => $secretSlug,
            'forum_name' => 'ZX9SearchVault',
            'forum_desc' => 'ZX9SearchSecretBody stays off search.',
            'forum_search_results' => [
                ['title' => 'ZX9SearchSecretToken thread', 'snippet' => 'ZX9SearchSecretBody'],
            ],
            'forum_search_total' => 1,
        ], $this->db);
        AP_Forum_Front::applyToQuery($stuffedSearch, $this->db);
        $this->assertTrue(!empty($stuffedSearch->get('ap_forum_cannot_view', false)));
        $this->assertSame(
            AP_Forum_Front::CANNOT_VIEW_MESSAGE,
            (string) $stuffedSearch->get('ap_forum_cannot_view_message', '')
        );
        $this->assertTrue($stuffedSearch->is_404);
        $this->assertSame('404.php', AP_Theme::getHierarchy($stuffedSearch, $this->db)[0] ?? null);
        $this->assertSame('', (string) $stuffedSearch->get('forum_name', 'x'));
        $this->assertSame('', (string) $stuffedSearch->get('forum_slug', 'x'));
        $this->assertSame('', (string) $stuffedSearch->get('forum_s', 'x'));
        $this->assertSame('', (string) $stuffedSearch->get('s', 'x'));
        $this->assertFalse($stuffedSearch->is_search);
        $this->assertSame(0, (int) $stuffedSearch->get('forum_id', -1));
        $this->assertSame(0, (int) $stuffedSearch->get('forum_search_total', -1));
        $this->assertSame([], $stuffedSearch->get('forum_search_results', ['x']));
        $this->assertSame([], $this->needlesFound(
            [json_encode($stuffedSearch->query_vars, JSON_UNESCAPED_SLASHES) ?: ''],
            $secretNeedles
        ));
        $deniedOg = AP_Seo::getOpenGraphMeta($stuffedSearch, $this->db);
        $deniedCanonical = AP_Seo::getCanonicalUrl($stuffedSearch, $this->db);
        $this->assertSame('', $deniedCanonical);
        $this->assertSame([], $this->needlesFound(
            [json_encode($deniedOg, JSON_UNESCAPED_SLASHES) ?: ''],
            $secretNeedles
        ));
        $deniedFallback = AP_Forum_Front::searchForQuery($stuffedSearch, $this->db);
        $this->assertSame('', (string) ($deniedFallback['query'] ?? 'x'));
        $this->assertSame(0, (int) ($deniedFallback['total'] ?? -1));
        $this->assertSame([], $deniedFallback['results'] ?? ['x']);

        $constructorDenied = new AP_Query([
            'ap_forum_cannot_view' => true,
            'ap_forum_cannot_view_message' => AP_Forum_Front::CANNOT_VIEW_MESSAGE,
            'is_404' => true,
            's' => 'ZX9SearchVault',
            'forum_s' => 'ZX9SearchVault',
            'forum_name' => 'ZX9SearchVault',
            'forum_slug' => $secretSlug,
        ], $this->db);
        $this->assertTrue($constructorDenied->is_404);
        $this->assertFalse($constructorDenied->is_search);
        $this->assertFalse($constructorDenied->is_feed);

        AP_Options::update('stylesheet', 'agora', $this->db);
        AP_Options::update('template', 'agora', $this->db);
        AP_Theme::reset();
        $GLOBALS['ap_query'] = $stuffedSearch;
        ob_start();
        AP_Theme::render($stuffedSearch, $this->db);
        $deniedHtml = (string) ob_get_clean();
        $this->assertStringContainsString(AP_Forum_Front::CANNOT_VIEW_MESSAGE, $deniedHtml);
        $this->assertSame([], $this->needlesFound([$deniedHtml], $secretNeedles));
        $this->assertStringNotContainsString('ap-forum--search', $deniedHtml);
        $this->assertStringNotContainsString('forum-search', $deniedHtml);

        $themeSearch = agora_get_forum_search_data();
        $this->assertSame('', $themeSearch['query'] ?? 'x');
        $this->assertSame(0, (int) ($themeSearch['total'] ?? -1));
        $this->assertSame([], $themeSearch['results'] ?? ['x']);

        AP_Session::resetCurrentUser();
        $guestSearchQuery = new AP_Query([
            'ap_forum_view' => 'search',
            'forum_s' => 'ZX9SearchSecretToken',
        ], $this->db);
        AP_Forum_Front::applyToQuery($guestSearchQuery, $this->db);
        $this->assertFalse(!empty($guestSearchQuery->get('ap_forum_cannot_view', false)));
        $this->assertSame(0, (int) $guestSearchQuery->get('forum_search_total', -1));
        $GLOBALS['ap_query'] = $guestSearchQuery;
        ob_start();
        AP_Theme::render($guestSearchQuery, $this->db);
        $guestSearchHtml = (string) ob_get_clean();
        $this->assertStringContainsString('ap-forum--search', $guestSearchHtml);
        $this->assertStringContainsString('No results matched', $guestSearchHtml);
        $this->assertSame([], $this->needlesFound([$guestSearchHtml], [
            'ZX9SearchCellar',
            'ZX9SearchVault',
            'ZX9SearchBackroom',
            'ZX9SearchSecretBody',
            $secretSlug,
            $secretTopicSlug,
            $categorySlug,
        ]));
        $this->assertStringNotContainsString(AP_Forum_Front::CANNOT_VIEW_MESSAGE, $guestSearchHtml);

        $guestPublicQuery = new AP_Query([
            'ap_forum_view' => 'search',
            'forum_s' => 'ZX9SearchTownHello',
        ], $this->db);
        AP_Forum_Front::applyToQuery($guestPublicQuery, $this->db);
        $GLOBALS['ap_query'] = $guestPublicQuery;
        ob_start();
        AP_Theme::render($guestPublicQuery, $this->db);
        $guestPublicHtml = (string) ob_get_clean();
        $this->assertStringContainsString('ZX9SearchTownHello', $guestPublicHtml);
        $this->assertSame([], $this->needlesFound([$guestPublicHtml], $secretNeedles));

        $this->assertTrue(AP_Session::setAuthCookie($member, false, $this->db));
        $memberSearchQuery = new AP_Query([
            'ap_forum_view' => 'search',
            'forum_s' => 'ZX9SearchSecretToken',
        ], $this->db);
        AP_Forum_Front::applyToQuery($memberSearchQuery, $this->db);
        $GLOBALS['ap_query'] = $memberSearchQuery;
        ob_start();
        AP_Theme::render($memberSearchQuery, $this->db);
        $memberSearchHtml = (string) ob_get_clean();
        $this->assertStringContainsString('ZX9SearchSecretToken', $memberSearchHtml);
        $this->assertStringContainsString('ap-forum--search', $memberSearchHtml);
        AP_Session::clearAuthCookie();
        AP_Session::resetCurrentUser();

        $acpFound = AP_Forum::getForums(['search' => 'ZX9SearchVault'], $this->db);
        $acpNames = array_map(static fn ($f) => (string) ($f->forum_name ?? ''), $acpFound);
        $this->assertContains('ZX9SearchVault', $acpNames);

        unset($GLOBALS['ap_query']);
        AP_Theme::reset();
        AP_Rewrite::resetCache();
        AP_Seo::reset();
        unset($GLOBALS['apdb']);
    }

    /**
     * Feeds: hide unlistable forums and empty parents; a direct forum/topic
     * feed URL is a generic “you cannot view this” 404 (never RSS that names
     * the board or slug).
     */
    public function testThisGroupOnlyFeedsHideForumAndEmptyParent(): void
    {
        require_once $this->root . '/ap-includes/hooks.php';
        require_once $this->root . '/ap-includes/class-ap-query.php';
        require_once $this->root . '/ap-includes/class-ap-rewrite.php';
        require_once $this->root . '/ap-includes/class-ap-session.php';
        require_once $this->root . '/ap-includes/class-ap-forum-front.php';
        require_once $this->root . '/ap-includes/class-ap-feed.php';
        require_once $this->root . '/ap-admin/includes/class-ap-forums-list-table.php';

        foreach (
            [
                'AP_LOGGED_IN_KEY' => 'zx9-feed-logged-in-key',
                'AP_LOGGED_IN_SALT' => 'zx9-feed-logged-in-salt',
                'AP_AUTH_KEY' => 'zx9-feed-auth-key',
                'AP_AUTH_SALT' => 'zx9-feed-auth-salt',
            ] as $const => $value
        ) {
            if (!defined($const)) {
                define($const, $value);
            }
        }

        if (function_exists('ap_reset_hooks')) {
            ap_reset_hooks();
        }
        AP_Rewrite::resetCache();
        AP_Session::clearAuthCookie();
        AP_Session::resetCurrentUser();

        $GLOBALS['apdb'] = $this->db;
        AP_Options::update('home', 'https://example.test', $this->db);
        AP_Options::update('siteurl', 'https://example.test', $this->db);
        AP_Options::update('permalink_structure', '/%postname%/', $this->db);
        AP_Options::update('blogname', 'Feed Hygiene', $this->db);
        AP_Options::update('ap_module_forum', '1', $this->db);
        AP_Options::update('posts_per_rss', '20', $this->db);
        AP_Options::flushCache();
        AP_Rewrite::resetCache();

        AP_Forum_Permissions::ensureDefaults($this->db);
        AP_Group::ensureSystemGroups($this->db);

        $vip = AP_Group::create([
            'group_name' => 'ZX9 Feed Circle',
            'group_type' => AP_Group::TYPE_CLOSED,
        ], $this->db);
        $this->assertGreaterThan(0, $vip);

        $categoryId = AP_Forum::insertForum([
            'forum_name' => 'ZX9FeedCellar',
            'forum_type' => AP_Forum::FORUM_TYPE_CATEGORY,
        ], $this->db);
        $secretId = AP_Forum::insertForum([
            'forum_name' => 'ZX9FeedVault',
            'forum_type' => AP_Forum::FORUM_TYPE_FORUM,
            'parent_id' => $categoryId,
        ], $this->db);
        $publicId = AP_Forum::insertForum([
            'forum_name' => 'ZX9FeedSquare',
            'forum_type' => AP_Forum::FORUM_TYPE_FORUM,
        ], $this->db);
        $mixedCatId = AP_Forum::insertForum([
            'forum_name' => 'ZX9FeedHall',
            'forum_type' => AP_Forum::FORUM_TYPE_CATEGORY,
        ], $this->db);
        $mixedPublicId = AP_Forum::insertForum([
            'forum_name' => 'ZX9FeedLobby',
            'forum_type' => AP_Forum::FORUM_TYPE_FORUM,
            'parent_id' => $mixedCatId,
        ], $this->db);
        $mixedSecretId = AP_Forum::insertForum([
            'forum_name' => 'ZX9FeedBackroom',
            'forum_type' => AP_Forum::FORUM_TYPE_FORUM,
            'parent_id' => $mixedCatId,
        ], $this->db);
        $this->assertGreaterThan(0, $categoryId);
        $this->assertGreaterThan(0, $secretId);
        $this->assertGreaterThan(0, $publicId);
        $this->assertGreaterThan(0, $mixedCatId);
        $this->assertGreaterThan(0, $mixedPublicId);
        $this->assertGreaterThan(0, $mixedSecretId);

        $this->assertTrue(AP_Forum_Permissions::saveAccessFromForm($secretId, [
            'forum_access_level' => 'group_only',
            'forum_access_groups' => [$vip],
        ], $this->db));
        $this->assertTrue(AP_Forum_Permissions::saveAccessFromForm($mixedSecretId, [
            'forum_access_level' => 'group_only',
            'forum_access_groups' => [$vip],
        ], $this->db));

        $outsider = $this->createUser('zx9_feed_out', 'zx9_feed_out@example.test', 'subscriber');
        $member = $this->createUser('zx9_feed_vip', 'zx9_feed_vip@example.test', 'subscriber');
        $moderator = $this->createUser('zx9_feed_mod', 'zx9_feed_mod@example.test', 'editor');
        $admin = $this->createUser('zx9_feed_admin', 'zx9_feed_admin@example.test', 'administrator');
        AP_Group::addMember($vip, $member, AP_Group::ROLE_MEMBER, $this->db);

        $secretTopicId = AP_Forum::createTopic([
            'forum_id' => $secretId,
            'topic_title' => 'ZX9FeedSecretToken thread',
            'content' => 'ZX9FeedSecretBody stays off feeds.',
            'poster_id' => $member,
        ], $this->db);
        $publicTopicId = AP_Forum::createTopic([
            'forum_id' => $publicId,
            'topic_title' => 'ZX9FeedTownHello thread',
            'content' => 'Public square chatter.',
            'poster_id' => $outsider,
        ], $this->db);
        $mixedPublicTopicId = AP_Forum::createTopic([
            'forum_id' => $mixedPublicId,
            'topic_title' => 'ZX9FeedLobbyHello thread',
            'content' => 'Hall lobby chatter.',
            'poster_id' => $outsider,
        ], $this->db);
        $mixedSecretTopicId = AP_Forum::createTopic([
            'forum_id' => $mixedSecretId,
            'topic_title' => 'ZX9FeedBackroomToken thread',
            'content' => 'ZX9FeedBackroomBody stays off feeds.',
            'poster_id' => $member,
        ], $this->db);
        $this->assertGreaterThan(0, $secretTopicId);
        $this->assertGreaterThan(0, $publicTopicId);
        $this->assertGreaterThan(0, $mixedPublicTopicId);
        $this->assertGreaterThan(0, $mixedSecretTopicId);

        $secretForum = AP_Forum::getForum($secretId, $this->db);
        $secretTopic = AP_Forum::getTopic($secretTopicId, $this->db);
        $this->assertNotNull($secretForum);
        $this->assertNotNull($secretTopic);
        $secretSlug = (string) $secretForum->forum_slug;
        $secretTopicSlug = (string) $secretTopic->topic_slug;
        $categorySlug = (string) (AP_Forum::getForum($categoryId, $this->db)->forum_slug ?? '');
        $hallSlug = (string) (AP_Forum::getForum($mixedCatId, $this->db)->forum_slug ?? '');
        $backroomSlug = (string) (AP_Forum::getForum($mixedSecretId, $this->db)->forum_slug ?? '');
        $this->assertNotSame('', $secretSlug);
        $this->assertNotSame('', $secretTopicSlug);
        $this->assertNotSame('', $categorySlug);
        $this->assertNotSame('', $hallSlug);
        $this->assertNotSame('', $backroomSlug);

        $secretNeedles = [
            'ZX9FeedCellar',
            'ZX9FeedVault',
            'ZX9FeedBackroom',
            'ZX9FeedSecretToken',
            'ZX9FeedSecretBody',
            'ZX9FeedBackroomToken',
            'ZX9FeedBackroomBody',
            $secretSlug,
            $secretTopicSlug,
            $categorySlug,
            $backroomSlug,
        ];

        $this->assertSame([], AP_Forum::feedForumIdsForScope(0, $secretId, $this->db));
        $this->assertSame([], AP_Forum::feedForumIdsForScope(0, $categoryId, $this->db));
        $this->assertSame([], AP_Forum::feedForumIdsForScope($outsider, $secretId, $this->db));
        $this->assertSame([], AP_Forum::feedForumIdsForScope($moderator, $secretId, $this->db));
        $this->assertContains($secretId, AP_Forum::feedForumIdsForScope($member, $secretId, $this->db));
        $this->assertContains($secretId, AP_Forum::feedForumIdsForScope($admin, $categoryId, $this->db));
        $this->assertContains($publicId, AP_Forum::feedForumIdsForScope(0, 0, $this->db));
        $this->assertNotContains($secretId, AP_Forum::feedForumIdsForScope(0, 0, $this->db));
        $this->assertContains($mixedPublicId, AP_Forum::feedForumIdsForScope(0, $mixedCatId, $this->db));
        $this->assertNotContains($mixedSecretId, AP_Forum::feedForumIdsForScope(0, $mixedCatId, $this->db));
        $this->assertFalse(AP_Roles::userCan($moderator, 'manage_forums', null, $this->db));
        $this->assertTrue(AP_Roles::userCan($moderator, 'moderate_forums', null, $this->db));

        $guestIndexTopics = AP_Forum::getFeedTopics(['user_id' => 0, 'per_page' => 20], $this->db);
        $guestIndexTitles = array_map(
            static fn ($t) => (string) ($t->topic_title ?? ''),
            $guestIndexTopics
        );
        $this->assertContains('ZX9FeedTownHello thread', $guestIndexTitles);
        $this->assertContains('ZX9FeedLobbyHello thread', $guestIndexTitles);
        $this->assertSame([], $this->needlesFound($guestIndexTitles, $secretNeedles));

        $guestSecretTopics = AP_Forum::getFeedTopics([
            'user_id' => 0,
            'forum_id' => $secretId,
        ], $this->db);
        $this->assertSame([], $guestSecretTopics);
        $this->assertSame([], AP_Forum::getFeedTopics([
            'user_id' => $outsider,
            'forum_id' => $secretId,
        ], $this->db));
        $this->assertSame([], AP_Forum::getFeedTopics([
            'user_id' => $moderator,
            'forum_id' => $secretId,
        ], $this->db));
        $this->assertSame([], AP_Forum::getFeedTopics([
            'user_id' => 0,
            'forum_id' => $categoryId,
        ], $this->db));

        $memberSecretTopics = AP_Forum::getFeedTopics([
            'user_id' => $member,
            'forum_id' => $secretId,
        ], $this->db);
        $this->assertNotSame([], $this->needlesFound(
            array_map(static fn ($t) => (string) ($t->topic_title ?? ''), $memberSecretTopics),
            ['ZX9FeedSecretToken']
        ));

        $this->assertSame([], AP_Forum::getFeedPosts($secretTopicId, ['user_id' => 0], $this->db));
        $this->assertSame([], AP_Forum::getFeedPosts($secretTopicId, ['user_id' => $outsider], $this->db));
        $this->assertSame([], AP_Forum::getFeedPosts($secretTopicId, ['user_id' => $moderator], $this->db));
        $this->assertNotSame([], AP_Forum::getFeedPosts($secretTopicId, ['user_id' => $member], $this->db));

        $guestMixed = AP_Forum::getFeedTopics([
            'user_id' => 0,
            'forum_id' => $mixedCatId,
            'per_page' => 20,
        ], $this->db);
        $guestMixedTitles = array_map(
            static fn ($t) => (string) ($t->topic_title ?? ''),
            $guestMixed
        );
        $this->assertContains('ZX9FeedLobbyHello thread', $guestMixedTitles);
        $this->assertSame([], $this->needlesFound($guestMixedTitles, $secretNeedles));

        AP_Session::resetCurrentUser();
        $this->assertTrue(AP_Feed::isForumFeedRequest(['feed' => 'rss2', 'ap_forum_view' => 'index']));
        $this->assertTrue(AP_Feed::isForumFeedRequest(['feed' => 'rss2', 'forum_slug' => $secretSlug]));
        $this->assertFalse(AP_Feed::isForumFeedRequest(['feed' => 'rss2']));

        $indexVars = AP_Rewrite::parseRequest('forums/feed', [], $this->db);
        $this->assertTrue(AP_Feed::isForumFeedRequest($indexVars));
        $this->assertSame('index', $indexVars['ap_forum_view'] ?? null);
        $guestIndexRss = $this->captureFeedServe($indexVars);
        $this->assertStringContainsString('<rss', $guestIndexRss);
        $this->assertStringContainsString('ZX9FeedTownHello', $guestIndexRss);
        $this->assertStringContainsString('ZX9FeedLobbyHello', $guestIndexRss);
        $this->assertSame([], $this->needlesFound([$guestIndexRss], $secretNeedles));
        $this->assertStringNotContainsString(AP_Forum_Front::CANNOT_VIEW_MESSAGE, $guestIndexRss);

        $guestIndexAtom = $this->captureFeedServe(
            AP_Rewrite::parseRequest('forums/feed/atom', [], $this->db)
        );
        $this->assertStringContainsString('<feed xmlns="http://www.w3.org/2005/Atom">', $guestIndexAtom);
        $this->assertStringContainsString('ZX9FeedTownHello', $guestIndexAtom);
        $this->assertSame([], $this->needlesFound([$guestIndexAtom], $secretNeedles));

        $prettySecretForum = AP_Rewrite::parseRequest('forums/' . $secretSlug . '/feed', [], $this->db);
        $this->assertTrue(AP_Feed::isForumFeedRequest($prettySecretForum));
        $this->assertSame($secretSlug, $prettySecretForum['forum_slug'] ?? null);
        $this->assertForumFeedDenied($this->captureFeedServe($prettySecretForum), $secretNeedles);

        $prettySecretAtom = AP_Rewrite::parseRequest(
            'forums/' . $secretSlug . '/feed/atom',
            [],
            $this->db
        );
        $this->assertForumFeedDenied($this->captureFeedServe($prettySecretAtom), $secretNeedles);

        $prettySecretTopic = AP_Rewrite::parseRequest(
            'topic/' . $secretTopicSlug . '/feed',
            [],
            $this->db
        );
        $this->assertForumFeedDenied($this->captureFeedServe($prettySecretTopic), $secretNeedles);

        $prettyEmptyParent = AP_Rewrite::parseRequest(
            'forums/' . $categorySlug . '/feed',
            [],
            $this->db
        );
        $this->assertForumFeedDenied($this->captureFeedServe($prettyEmptyParent), $secretNeedles);

        $prettyBackroom = AP_Rewrite::parseRequest(
            'forums/' . $backroomSlug . '/feed',
            [],
            $this->db
        );
        $this->assertForumFeedDenied($this->captureFeedServe($prettyBackroom), $secretNeedles);

        $prettyHall = AP_Rewrite::parseRequest('forums/' . $hallSlug . '/feed', [], $this->db);
        $hallRss = $this->captureFeedServe($prettyHall);
        $this->assertStringContainsString('<rss', $hallRss);
        $this->assertStringContainsString('ZX9FeedHall', $hallRss);
        $this->assertStringContainsString('ZX9FeedLobbyHello', $hallRss);
        $this->assertSame([], $this->needlesFound([$hallRss], $secretNeedles));

        $stuffed = $this->captureFeedServe([
            'feed' => 'rss2',
            'ap_forum_view' => 'forum',
            'forum_id' => $secretId,
            'forum_slug' => $secretSlug,
            'forum_name' => 'ZX9FeedVault',
            'forum_desc' => 'ZX9FeedSecretBody stays off feeds.',
        ]);
        $this->assertForumFeedDenied($stuffed, $secretNeedles);

        $stuffedTopic = $this->captureFeedServe([
            'feed' => 'atom',
            'ap_forum_view' => 'topic',
            'topic_id' => $secretTopicId,
            'topic_slug' => $secretTopicSlug,
            'topic_title' => 'ZX9FeedSecretToken thread',
            'forum_name' => 'ZX9FeedVault',
        ]);
        $this->assertForumFeedDenied($stuffedTopic, $secretNeedles);

        $alreadyDenied = $this->captureFeedServe([
            'feed' => 'rss2',
            'ap_forum_cannot_view' => true,
            'ap_forum_cannot_view_message' => AP_Forum_Front::CANNOT_VIEW_MESSAGE,
            'forum_name' => 'ZX9FeedVault',
            'forum_slug' => $secretSlug,
            'forum_id' => $secretId,
        ]);
        $this->assertForumFeedDenied($alreadyDenied, $secretNeedles);

        $plainSecret = $this->captureFeedServe([
            'feed' => 'rss2',
            'ap_forum_view' => 'forum',
            'forum_id' => $secretId,
        ]);
        $this->assertForumFeedDenied($plainSecret, $secretNeedles);

        $publicSquare = AP_Forum::getForum($publicId, $this->db);
        $this->assertNotNull($publicSquare);
        $publicSlug = (string) $publicSquare->forum_slug;
        $publicRss = $this->captureFeedServe(
            AP_Rewrite::parseRequest('forums/' . $publicSlug . '/feed', [], $this->db)
        );
        $this->assertStringContainsString('<rss', $publicRss);
        $this->assertStringContainsString('ZX9FeedTownHello', $publicRss);
        $this->assertStringContainsString('ZX9FeedSquare', $publicRss);
        $this->assertSame([], $this->needlesFound([$publicRss], $secretNeedles));

        AP_Session::resetCurrentUser();
        $this->assertTrue(AP_Session::setAuthCookie($outsider, false, $this->db));
        $this->assertForumFeedDenied(
            $this->captureFeedServe(AP_Rewrite::parseRequest(
                'forums/' . $secretSlug . '/feed',
                [],
                $this->db
            )),
            $secretNeedles
        );
        AP_Session::clearAuthCookie();
        AP_Session::resetCurrentUser();

        $this->assertTrue(AP_Session::setAuthCookie($moderator, false, $this->db));
        $this->assertForumFeedDenied(
            $this->captureFeedServe(AP_Rewrite::parseRequest(
                'forums/' . $secretSlug . '/feed',
                [],
                $this->db
            )),
            $secretNeedles
        );
        AP_Session::clearAuthCookie();
        AP_Session::resetCurrentUser();

        $this->assertTrue(AP_Session::setAuthCookie($member, false, $this->db));
        $memberForumRss = $this->captureFeedServe(
            AP_Rewrite::parseRequest('forums/' . $secretSlug . '/feed', [], $this->db)
        );
        $this->assertStringContainsString('<rss', $memberForumRss);
        $this->assertStringContainsString('ZX9FeedVault', $memberForumRss);
        $this->assertStringContainsString('ZX9FeedSecretToken', $memberForumRss);
        $this->assertStringNotContainsString(AP_Forum_Front::CANNOT_VIEW_MESSAGE, $memberForumRss);

        $memberTopicRss = $this->captureFeedServe(
            AP_Rewrite::parseRequest('topic/' . $secretTopicSlug . '/feed', [], $this->db)
        );
        $this->assertStringContainsString('<rss', $memberTopicRss);
        $this->assertStringContainsString('ZX9FeedSecretToken', $memberTopicRss);
        $this->assertStringContainsString('ZX9FeedSecretBody', $memberTopicRss);

        $memberIndexRss = $this->captureFeedServe(
            AP_Rewrite::parseRequest('forums/feed', [], $this->db)
        );
        $this->assertStringContainsString('ZX9FeedSecretToken', $memberIndexRss);
        $this->assertStringContainsString('ZX9FeedTownHello', $memberIndexRss);

        $memberCellarRss = $this->captureFeedServe(
            AP_Rewrite::parseRequest('forums/' . $categorySlug . '/feed', [], $this->db)
        );
        $this->assertStringContainsString('<rss', $memberCellarRss);
        $this->assertStringContainsString('ZX9FeedCellar', $memberCellarRss);
        $this->assertStringContainsString('ZX9FeedSecretToken', $memberCellarRss);
        AP_Session::clearAuthCookie();
        AP_Session::resetCurrentUser();

        $this->assertTrue(AP_Session::setAuthCookie($admin, false, $this->db));
        $adminForumRss = $this->captureFeedServe(
            AP_Rewrite::parseRequest('forums/' . $secretSlug . '/feed', [], $this->db)
        );
        $this->assertStringContainsString('ZX9FeedSecretToken', $adminForumRss);
        AP_Session::clearAuthCookie();
        AP_Session::resetCurrentUser();

        $acpTable = new AP_Forums_List_Table($this->db);
        $acpTable->prepareItems([]);
        $acpNames = [];
        foreach ($acpTable->items as $item) {
            $acpNames[] = (string) ($item->forum_name ?? '');
        }
        $this->assertContains('ZX9FeedVault', $acpNames);
        $this->assertContains('ZX9FeedCellar', $acpNames);
        $this->assertContains('ZX9FeedSquare', $acpNames);

        AP_Rewrite::resetCache();
        unset($GLOBALS['apdb']);
    }

    /**
     * Sitemap: hide unlistable forums and empty parents; a direct URL stuffed
     * with a board id/slug is a generic “you cannot view this” 404 (never XML
     * that names the board or slug).
     */
    public function testThisGroupOnlySitemapHidesForumAndEmptyParent(): void
    {
        require_once $this->root . '/ap-includes/hooks.php';
        require_once $this->root . '/ap-includes/class-ap-query.php';
        require_once $this->root . '/ap-includes/class-ap-rewrite.php';
        require_once $this->root . '/ap-includes/class-ap-session.php';
        require_once $this->root . '/ap-includes/class-ap-forum-front.php';
        require_once $this->root . '/ap-includes/class-ap-sitemap.php';
        require_once $this->root . '/ap-admin/includes/class-ap-forums-list-table.php';

        foreach (
            [
                'AP_LOGGED_IN_KEY' => 'zx9-map-logged-in-key',
                'AP_LOGGED_IN_SALT' => 'zx9-map-logged-in-salt',
                'AP_AUTH_KEY' => 'zx9-map-auth-key',
                'AP_AUTH_SALT' => 'zx9-map-auth-salt',
            ] as $const => $value
        ) {
            if (!defined($const)) {
                define($const, $value);
            }
        }

        if (function_exists('ap_reset_hooks')) {
            ap_reset_hooks();
        }
        AP_Rewrite::resetCache();
        AP_Session::clearAuthCookie();
        AP_Session::resetCurrentUser();

        $GLOBALS['apdb'] = $this->db;
        AP_Options::update('home', 'https://example.test', $this->db);
        AP_Options::update('siteurl', 'https://example.test', $this->db);
        AP_Options::update('permalink_structure', '/%postname%/', $this->db);
        AP_Options::update('blogname', 'Sitemap Hygiene', $this->db);
        AP_Options::update('ap_module_forum', '1', $this->db);
        AP_Options::update('sitemap_enabled', '1', $this->db);
        AP_Options::update('blog_public', '1', $this->db);
        AP_Options::flushCache();
        AP_Rewrite::resetCache();

        AP_Forum_Permissions::ensureDefaults($this->db);
        AP_Group::ensureSystemGroups($this->db);

        $vip = AP_Group::create([
            'group_name' => 'ZX9 Sitemap Circle',
            'group_type' => AP_Group::TYPE_CLOSED,
        ], $this->db);
        $this->assertGreaterThan(0, $vip);

        $categoryId = AP_Forum::insertForum([
            'forum_name' => 'ZX9SitemapCellar',
            'forum_type' => AP_Forum::FORUM_TYPE_CATEGORY,
        ], $this->db);
        $secretId = AP_Forum::insertForum([
            'forum_name' => 'ZX9SitemapVault',
            'forum_type' => AP_Forum::FORUM_TYPE_FORUM,
            'parent_id' => $categoryId,
        ], $this->db);
        $publicId = AP_Forum::insertForum([
            'forum_name' => 'ZX9SitemapSquare',
            'forum_type' => AP_Forum::FORUM_TYPE_FORUM,
        ], $this->db);
        $mixedCatId = AP_Forum::insertForum([
            'forum_name' => 'ZX9SitemapHall',
            'forum_type' => AP_Forum::FORUM_TYPE_CATEGORY,
        ], $this->db);
        $mixedPublicId = AP_Forum::insertForum([
            'forum_name' => 'ZX9SitemapLobby',
            'forum_type' => AP_Forum::FORUM_TYPE_FORUM,
            'parent_id' => $mixedCatId,
        ], $this->db);
        $mixedSecretId = AP_Forum::insertForum([
            'forum_name' => 'ZX9SitemapBackroom',
            'forum_type' => AP_Forum::FORUM_TYPE_FORUM,
            'parent_id' => $mixedCatId,
        ], $this->db);
        $this->assertGreaterThan(0, $categoryId);
        $this->assertGreaterThan(0, $secretId);
        $this->assertGreaterThan(0, $publicId);
        $this->assertGreaterThan(0, $mixedCatId);
        $this->assertGreaterThan(0, $mixedPublicId);
        $this->assertGreaterThan(0, $mixedSecretId);

        $this->assertTrue(AP_Forum_Permissions::saveAccessFromForm($secretId, [
            'forum_access_level' => 'group_only',
            'forum_access_groups' => [$vip],
        ], $this->db));
        $this->assertTrue(AP_Forum_Permissions::saveAccessFromForm($mixedSecretId, [
            'forum_access_level' => 'group_only',
            'forum_access_groups' => [$vip],
        ], $this->db));

        $outsider = $this->createUser('zx9_map_out', 'zx9_map_out@example.test', 'subscriber');
        $member = $this->createUser('zx9_map_vip', 'zx9_map_vip@example.test', 'subscriber');
        $moderator = $this->createUser('zx9_map_mod', 'zx9_map_mod@example.test', 'editor');
        $admin = $this->createUser('zx9_map_admin', 'zx9_map_admin@example.test', 'administrator');
        AP_Group::addMember($vip, $member, AP_Group::ROLE_MEMBER, $this->db);

        $secretTopicId = AP_Forum::createTopic([
            'forum_id' => $secretId,
            'topic_title' => 'ZX9SitemapSecretToken thread',
            'content' => 'ZX9SitemapSecretBody stays off sitemaps.',
            'poster_id' => $member,
        ], $this->db);
        $publicTopicId = AP_Forum::createTopic([
            'forum_id' => $publicId,
            'topic_title' => 'ZX9SitemapTownHello thread',
            'content' => 'Public square chatter.',
            'poster_id' => $outsider,
        ], $this->db);
        $mixedPublicTopicId = AP_Forum::createTopic([
            'forum_id' => $mixedPublicId,
            'topic_title' => 'ZX9SitemapLobbyHello thread',
            'content' => 'Hall lobby chatter.',
            'poster_id' => $outsider,
        ], $this->db);
        $mixedSecretTopicId = AP_Forum::createTopic([
            'forum_id' => $mixedSecretId,
            'topic_title' => 'ZX9SitemapBackroomToken thread',
            'content' => 'ZX9SitemapBackroomBody stays off sitemaps.',
            'poster_id' => $member,
        ], $this->db);
        $this->assertGreaterThan(0, $secretTopicId);
        $this->assertGreaterThan(0, $publicTopicId);
        $this->assertGreaterThan(0, $mixedPublicTopicId);
        $this->assertGreaterThan(0, $mixedSecretTopicId);

        $secretForum = AP_Forum::getForum($secretId, $this->db);
        $secretTopic = AP_Forum::getTopic($secretTopicId, $this->db);
        $this->assertNotNull($secretForum);
        $this->assertNotNull($secretTopic);
        $secretSlug = (string) $secretForum->forum_slug;
        $secretTopicSlug = (string) $secretTopic->topic_slug;
        $categorySlug = (string) (AP_Forum::getForum($categoryId, $this->db)->forum_slug ?? '');
        $hallSlug = (string) (AP_Forum::getForum($mixedCatId, $this->db)->forum_slug ?? '');
        $backroomSlug = (string) (AP_Forum::getForum($mixedSecretId, $this->db)->forum_slug ?? '');
        $lobbySlug = (string) (AP_Forum::getForum($mixedPublicId, $this->db)->forum_slug ?? '');
        $publicSlug = (string) (AP_Forum::getForum($publicId, $this->db)->forum_slug ?? '');
        $publicTopicSlug = (string) (AP_Forum::getTopic($publicTopicId, $this->db)->topic_slug ?? '');
        $this->assertNotSame('', $secretSlug);
        $this->assertNotSame('', $secretTopicSlug);
        $this->assertNotSame('', $categorySlug);
        $this->assertNotSame('', $hallSlug);
        $this->assertNotSame('', $backroomSlug);
        $this->assertNotSame('', $lobbySlug);
        $this->assertNotSame('', $publicSlug);
        $this->assertNotSame('', $publicTopicSlug);

        $secretNeedles = [
            'ZX9SitemapCellar',
            'ZX9SitemapVault',
            'ZX9SitemapBackroom',
            'ZX9SitemapSecretToken',
            'ZX9SitemapSecretBody',
            'ZX9SitemapBackroomToken',
            'ZX9SitemapBackroomBody',
            $secretSlug,
            $secretTopicSlug,
            $categorySlug,
            $backroomSlug,
        ];

        $guestListable = $this->forumObjectsText(AP_Forum::getListableForums(0, [], $this->db));
        $this->assertContains('ZX9SitemapSquare', $guestListable);
        $this->assertContains('ZX9SitemapHall', $guestListable);
        $this->assertContains('ZX9SitemapLobby', $guestListable);
        $this->assertSame([], $this->needlesFound($guestListable, $secretNeedles));
        $this->assertFalse(AP_Forum::isListableToUser(0, $secretId, $this->db));
        $this->assertFalse(AP_Forum::isListableToUser(0, $categoryId, $this->db));
        $this->assertFalse(AP_Forum::isListableToUser($outsider, $secretId, $this->db));
        $this->assertFalse(AP_Forum::isListableToUser($moderator, $secretId, $this->db));
        $this->assertTrue(AP_Forum::isListableToUser($member, $secretId, $this->db));
        $this->assertTrue(AP_Forum::isListableToUser($member, $categoryId, $this->db));
        $this->assertTrue(AP_Forum::isListableToUser($admin, $secretId, $this->db));
        $this->assertTrue(AP_Forum::isListableToUser(0, $mixedCatId, $this->db));
        $this->assertFalse(AP_Forum::isListableToUser(0, $mixedSecretId, $this->db));
        $this->assertFalse(AP_Roles::userCan($moderator, 'manage_forums', null, $this->db));
        $this->assertTrue(AP_Roles::userCan($moderator, 'moderate_forums', null, $this->db));

        AP_Session::resetCurrentUser();
        $this->assertFalse(AP_Sitemap::isForumSitemapRequest(['sitemap' => 'forums']));
        $this->assertFalse(AP_Sitemap::isForumSitemapRequest(['sitemap' => 'topics']));
        $this->assertFalse(AP_Sitemap::isForumSitemapRequest(['sitemap' => 'index']));
        $this->assertTrue(AP_Sitemap::isForumSitemapRequest([
            'sitemap' => 'forums',
            'forum_slug' => $secretSlug,
        ]));
        $this->assertTrue(AP_Sitemap::isForumSitemapRequest([
            'sitemap' => 'topics',
            'topic_id' => $secretTopicId,
        ]));

        $prettyForums = AP_Rewrite::parseRequest('sitemap-forums.xml', [], $this->db);
        $this->assertTrue(AP_Sitemap::isSitemapRequest($prettyForums));
        $this->assertFalse(AP_Sitemap::isForumSitemapRequest($prettyForums));
        $guestForumXml = $this->captureSitemapServe($prettyForums);
        $this->assertStringContainsString('<urlset', $guestForumXml);
        $this->assertStringContainsString($publicSlug, $guestForumXml);
        $this->assertStringContainsString($lobbySlug, $guestForumXml);
        $this->assertStringContainsString($hallSlug, $guestForumXml);
        $this->assertSame([], $this->needlesFound([$guestForumXml], $secretNeedles));
        $this->assertStringNotContainsString(AP_Forum_Front::CANNOT_VIEW_MESSAGE, $guestForumXml);

        $prettyTopics = AP_Rewrite::parseRequest('sitemap-topics.xml', [], $this->db);
        $guestTopicXml = $this->captureSitemapServe($prettyTopics);
        $this->assertStringContainsString('<urlset', $guestTopicXml);
        $this->assertStringContainsString($publicTopicSlug, $guestTopicXml);
        $this->assertSame([], $this->needlesFound([$guestTopicXml], $secretNeedles));

        $indexXml = $this->captureSitemapServe(
            AP_Rewrite::parseRequest('sitemap.xml', [], $this->db)
        );
        $this->assertStringContainsString('<sitemapindex', $indexXml);
        $this->assertStringContainsString('sitemap-forums', $indexXml);
        $this->assertStringContainsString('sitemap-topics', $indexXml);
        $this->assertSame([], $this->needlesFound([$indexXml], $secretNeedles));

        $buildForums = AP_Sitemap::buildProvider('forums', 1, $this->db);
        $buildTopics = AP_Sitemap::buildProvider('topics', 1, $this->db);
        $this->assertSame([], $this->needlesFound([$buildForums, $buildTopics], $secretNeedles));
        $this->assertStringContainsString($publicSlug, $buildForums);
        $this->assertStringContainsString($publicTopicSlug, $buildTopics);

        $this->assertForumSitemapDenied($this->captureSitemapServe([
            'sitemap' => 'forums',
            'forum_id' => $secretId,
            'forum_slug' => $secretSlug,
            'forum_name' => 'ZX9SitemapVault',
        ]), $secretNeedles);

        $this->assertForumSitemapDenied($this->captureSitemapServe(
            array_merge(
                AP_Rewrite::parseRequest('sitemap-forums.xml', [], $this->db),
                [
                    'forum_slug' => $secretSlug,
                    'ap_forum_view' => 'forum',
                ]
            )
        ), $secretNeedles);

        $this->assertForumSitemapDenied($this->captureSitemapServe([
            'sitemap' => 'forums',
            'forum_id' => $categoryId,
            'forum_slug' => $categorySlug,
            'forum_name' => 'ZX9SitemapCellar',
        ]), $secretNeedles);

        $this->assertForumSitemapDenied($this->captureSitemapServe([
            'sitemap' => 'forums',
            'forum_id' => $mixedSecretId,
            'forum_slug' => $backroomSlug,
        ]), $secretNeedles);

        $this->assertForumSitemapDenied($this->captureSitemapServe([
            'sitemap' => 'topics',
            'topic_id' => $secretTopicId,
            'topic_slug' => $secretTopicSlug,
            'topic_title' => 'ZX9SitemapSecretToken thread',
            'forum_name' => 'ZX9SitemapVault',
        ]), $secretNeedles);

        $this->assertForumSitemapDenied($this->captureSitemapServe([
            'sitemap' => 'forums',
            'ap_forum_cannot_view' => true,
            'ap_forum_cannot_view_message' => AP_Forum_Front::CANNOT_VIEW_MESSAGE,
            'forum_name' => 'ZX9SitemapVault',
            'forum_slug' => $secretSlug,
            'forum_id' => $secretId,
        ]), $secretNeedles);

        $hallXml = $this->captureSitemapServe([
            'sitemap' => 'forums',
            'forum_id' => $mixedCatId,
            'forum_slug' => $hallSlug,
            'ap_forum_view' => 'forum',
        ]);
        $this->assertStringContainsString('<urlset', $hallXml);
        $this->assertStringContainsString($hallSlug, $hallXml);
        $this->assertStringContainsString($lobbySlug, $hallXml);
        $this->assertSame([], $this->needlesFound([$hallXml], $secretNeedles));

        $publicXml = $this->captureSitemapServe([
            'sitemap' => 'forums',
            'forum_id' => $publicId,
            'forum_slug' => $publicSlug,
        ]);
        $this->assertStringContainsString('<urlset', $publicXml);
        $this->assertStringContainsString($publicSlug, $publicXml);
        $this->assertSame([], $this->needlesFound([$publicXml], $secretNeedles));

        AP_Session::resetCurrentUser();
        $this->assertTrue(AP_Session::setAuthCookie($outsider, false, $this->db));
        $outsiderXml = $this->captureSitemapServe(
            AP_Rewrite::parseRequest('sitemap-forums.xml', [], $this->db)
        );
        $this->assertStringContainsString($publicSlug, $outsiderXml);
        $this->assertSame([], $this->needlesFound([$outsiderXml], $secretNeedles));
        $this->assertForumSitemapDenied($this->captureSitemapServe([
            'sitemap' => 'forums',
            'forum_slug' => $secretSlug,
        ]), $secretNeedles);
        AP_Session::clearAuthCookie();
        AP_Session::resetCurrentUser();

        $this->assertTrue(AP_Session::setAuthCookie($moderator, false, $this->db));
        $modXml = $this->captureSitemapServe(
            AP_Rewrite::parseRequest('sitemap-forums.xml', [], $this->db)
        );
        $this->assertSame([], $this->needlesFound([$modXml], $secretNeedles));
        $this->assertForumSitemapDenied($this->captureSitemapServe([
            'sitemap' => 'topics',
            'topic_slug' => $secretTopicSlug,
        ]), $secretNeedles);
        AP_Session::clearAuthCookie();
        AP_Session::resetCurrentUser();

        $this->assertTrue(AP_Session::setAuthCookie($member, false, $this->db));
        $memberForumXml = $this->captureSitemapServe(
            AP_Rewrite::parseRequest('sitemap-forums.xml', [], $this->db)
        );
        $this->assertStringContainsString('<urlset', $memberForumXml);
        $this->assertStringContainsString($secretSlug, $memberForumXml);
        $this->assertStringContainsString($categorySlug, $memberForumXml);
        $this->assertStringContainsString($backroomSlug, $memberForumXml);
        $this->assertStringContainsString($publicSlug, $memberForumXml);
        $this->assertStringNotContainsString(AP_Forum_Front::CANNOT_VIEW_MESSAGE, $memberForumXml);

        $memberTopicXml = $this->captureSitemapServe(
            AP_Rewrite::parseRequest('sitemap-topics.xml', [], $this->db)
        );
        $this->assertStringContainsString($secretTopicSlug, $memberTopicXml);
        $this->assertStringContainsString($publicTopicSlug, $memberTopicXml);

        $memberDirect = $this->captureSitemapServe([
            'sitemap' => 'forums',
            'forum_slug' => $secretSlug,
            'ap_forum_view' => 'forum',
        ]);
        $this->assertStringContainsString('<urlset', $memberDirect);
        $this->assertStringContainsString($secretSlug, $memberDirect);
        $this->assertStringNotContainsString(AP_Forum_Front::CANNOT_VIEW_MESSAGE, $memberDirect);

        $memberCellar = $this->captureSitemapServe([
            'sitemap' => 'forums',
            'forum_id' => $categoryId,
            'forum_slug' => $categorySlug,
        ]);
        $this->assertStringContainsString('<urlset', $memberCellar);
        $this->assertStringContainsString($categorySlug, $memberCellar);
        AP_Session::clearAuthCookie();
        AP_Session::resetCurrentUser();

        $this->assertTrue(AP_Session::setAuthCookie($admin, false, $this->db));
        $adminXml = $this->captureSitemapServe(
            AP_Rewrite::parseRequest('sitemap-forums.xml', [], $this->db)
        );
        $this->assertStringContainsString($secretSlug, $adminXml);
        AP_Session::clearAuthCookie();
        AP_Session::resetCurrentUser();

        $acpTable = new AP_Forums_List_Table($this->db);
        $acpTable->prepareItems([]);
        $acpNames = [];
        foreach ($acpTable->items as $item) {
            $acpNames[] = (string) ($item->forum_name ?? '');
        }
        $this->assertContains('ZX9SitemapVault', $acpNames);
        $this->assertContains('ZX9SitemapCellar', $acpNames);
        $this->assertContains('ZX9SitemapSquare', $acpNames);

        AP_Rewrite::resetCache();
        unset($GLOBALS['apdb']);
    }

    /**
     * REST: hide unlistable forums and empty parents; a direct URL stuffed
     * with a board id/slug is a generic “you cannot view this” JSON 404
     * (never a body that names the board or slug).
     */
    public function testThisGroupOnlyRestHidesForumAndEmptyParent(): void
    {
        require_once $this->root . '/ap-includes/hooks.php';
        require_once $this->root . '/ap-includes/class-ap-query.php';
        require_once $this->root . '/ap-includes/class-ap-rewrite.php';
        require_once $this->root . '/ap-includes/class-ap-session.php';
        require_once $this->root . '/ap-includes/class-ap-forum-front.php';
        require_once $this->root . '/ap-includes/class-ap-rest.php';
        require_once $this->root . '/ap-admin/includes/class-ap-forums-list-table.php';

        foreach (
            [
                'AP_LOGGED_IN_KEY' => 'zx9-rest-logged-in-key',
                'AP_LOGGED_IN_SALT' => 'zx9-rest-logged-in-salt',
                'AP_AUTH_KEY' => 'zx9-rest-auth-key',
                'AP_AUTH_SALT' => 'zx9-rest-auth-salt',
            ] as $const => $value
        ) {
            if (!defined($const)) {
                define($const, $value);
            }
        }

        if (function_exists('ap_reset_hooks')) {
            ap_reset_hooks();
        }
        AP_Rewrite::resetCache();
        AP_Rest::reset();
        AP_Session::clearAuthCookie();
        AP_Session::resetCurrentUser();

        $GLOBALS['apdb'] = $this->db;
        AP_Options::update('home', 'https://example.test', $this->db);
        AP_Options::update('siteurl', 'https://example.test', $this->db);
        AP_Options::update('permalink_structure', '/%postname%/', $this->db);
        AP_Options::update('blogname', 'REST Hygiene', $this->db);
        AP_Options::update('ap_module_forum', '1', $this->db);
        AP_Options::update('rest_api_enabled', '1', $this->db);
        AP_Options::flushCache();
        AP_Rewrite::resetCache();

        AP_Forum_Permissions::ensureDefaults($this->db);
        AP_Group::ensureSystemGroups($this->db);

        $vip = AP_Group::create([
            'group_name' => 'ZX9 REST Circle',
            'group_type' => AP_Group::TYPE_CLOSED,
        ], $this->db);
        $this->assertGreaterThan(0, $vip);

        $categoryId = AP_Forum::insertForum([
            'forum_name' => 'ZX9RestCellar',
            'forum_type' => AP_Forum::FORUM_TYPE_CATEGORY,
        ], $this->db);
        $secretId = AP_Forum::insertForum([
            'forum_name' => 'ZX9RestVault',
            'forum_type' => AP_Forum::FORUM_TYPE_FORUM,
            'parent_id' => $categoryId,
        ], $this->db);
        $publicId = AP_Forum::insertForum([
            'forum_name' => 'ZX9RestSquare',
            'forum_type' => AP_Forum::FORUM_TYPE_FORUM,
        ], $this->db);
        $mixedCatId = AP_Forum::insertForum([
            'forum_name' => 'ZX9RestHall',
            'forum_type' => AP_Forum::FORUM_TYPE_CATEGORY,
        ], $this->db);
        $mixedPublicId = AP_Forum::insertForum([
            'forum_name' => 'ZX9RestLobby',
            'forum_type' => AP_Forum::FORUM_TYPE_FORUM,
            'parent_id' => $mixedCatId,
        ], $this->db);
        $mixedSecretId = AP_Forum::insertForum([
            'forum_name' => 'ZX9RestBackroom',
            'forum_type' => AP_Forum::FORUM_TYPE_FORUM,
            'parent_id' => $mixedCatId,
        ], $this->db);
        $this->assertGreaterThan(0, $categoryId);
        $this->assertGreaterThan(0, $secretId);
        $this->assertGreaterThan(0, $publicId);
        $this->assertGreaterThan(0, $mixedCatId);
        $this->assertGreaterThan(0, $mixedPublicId);
        $this->assertGreaterThan(0, $mixedSecretId);

        $this->assertTrue(AP_Forum_Permissions::saveAccessFromForm($secretId, [
            'forum_access_level' => 'group_only',
            'forum_access_groups' => [$vip],
        ], $this->db));
        $this->assertTrue(AP_Forum_Permissions::saveAccessFromForm($mixedSecretId, [
            'forum_access_level' => 'group_only',
            'forum_access_groups' => [$vip],
        ], $this->db));

        $outsider = $this->createUser('zx9_rest_out', 'zx9_rest_out@example.test', 'subscriber');
        $member = $this->createUser('zx9_rest_vip', 'zx9_rest_vip@example.test', 'subscriber');
        $moderator = $this->createUser('zx9_rest_mod', 'zx9_rest_mod@example.test', 'editor');
        $admin = $this->createUser('zx9_rest_admin', 'zx9_rest_admin@example.test', 'administrator');
        AP_Group::addMember($vip, $member, AP_Group::ROLE_MEMBER, $this->db);

        $secretTopicId = AP_Forum::createTopic([
            'forum_id' => $secretId,
            'topic_title' => 'ZX9RestSecretToken thread',
            'content' => 'ZX9RestSecretBody stays off REST.',
            'poster_id' => $member,
        ], $this->db);
        $publicTopicId = AP_Forum::createTopic([
            'forum_id' => $publicId,
            'topic_title' => 'ZX9RestTownHello thread',
            'content' => 'Public square chatter.',
            'poster_id' => $outsider,
        ], $this->db);
        $mixedPublicTopicId = AP_Forum::createTopic([
            'forum_id' => $mixedPublicId,
            'topic_title' => 'ZX9RestLobbyHello thread',
            'content' => 'Hall lobby chatter.',
            'poster_id' => $outsider,
        ], $this->db);
        $mixedSecretTopicId = AP_Forum::createTopic([
            'forum_id' => $mixedSecretId,
            'topic_title' => 'ZX9RestBackroomToken thread',
            'content' => 'ZX9RestBackroomBody stays off REST.',
            'poster_id' => $member,
        ], $this->db);
        $this->assertGreaterThan(0, $secretTopicId);
        $this->assertGreaterThan(0, $publicTopicId);
        $this->assertGreaterThan(0, $mixedPublicTopicId);
        $this->assertGreaterThan(0, $mixedSecretTopicId);

        $secretForum = AP_Forum::getForum($secretId, $this->db);
        $secretTopic = AP_Forum::getTopic($secretTopicId, $this->db);
        $this->assertNotNull($secretForum);
        $this->assertNotNull($secretTopic);
        $secretSlug = (string) $secretForum->forum_slug;
        $secretTopicSlug = (string) $secretTopic->topic_slug;
        $categorySlug = (string) (AP_Forum::getForum($categoryId, $this->db)->forum_slug ?? '');
        $hallSlug = (string) (AP_Forum::getForum($mixedCatId, $this->db)->forum_slug ?? '');
        $backroomSlug = (string) (AP_Forum::getForum($mixedSecretId, $this->db)->forum_slug ?? '');
        $lobbySlug = (string) (AP_Forum::getForum($mixedPublicId, $this->db)->forum_slug ?? '');
        $publicSlug = (string) (AP_Forum::getForum($publicId, $this->db)->forum_slug ?? '');
        $publicTopicSlug = (string) (AP_Forum::getTopic($publicTopicId, $this->db)->topic_slug ?? '');
        $this->assertNotSame('', $secretSlug);
        $this->assertNotSame('', $secretTopicSlug);
        $this->assertNotSame('', $categorySlug);
        $this->assertNotSame('', $hallSlug);
        $this->assertNotSame('', $backroomSlug);
        $this->assertNotSame('', $lobbySlug);
        $this->assertNotSame('', $publicSlug);
        $this->assertNotSame('', $publicTopicSlug);

        $secretNeedles = [
            'ZX9RestCellar',
            'ZX9RestVault',
            'ZX9RestBackroom',
            'ZX9RestSecretToken',
            'ZX9RestSecretBody',
            'ZX9RestBackroomToken',
            'ZX9RestBackroomBody',
            $secretSlug,
            $secretTopicSlug,
            $categorySlug,
            $backroomSlug,
        ];

        $guestListable = $this->forumObjectsText(AP_Forum::getListableForums(0, [], $this->db));
        $this->assertContains('ZX9RestSquare', $guestListable);
        $this->assertContains('ZX9RestHall', $guestListable);
        $this->assertContains('ZX9RestLobby', $guestListable);
        $this->assertSame([], $this->needlesFound($guestListable, $secretNeedles));
        $this->assertFalse(AP_Forum::isListableToUser(0, $secretId, $this->db));
        $this->assertFalse(AP_Forum::isListableToUser(0, $categoryId, $this->db));
        $this->assertFalse(AP_Forum::isListableToUser($outsider, $secretId, $this->db));
        $this->assertFalse(AP_Forum::isListableToUser($moderator, $secretId, $this->db));
        $this->assertTrue(AP_Forum::isListableToUser($member, $secretId, $this->db));
        $this->assertTrue(AP_Forum::isListableToUser($member, $categoryId, $this->db));
        $this->assertTrue(AP_Forum::isListableToUser($admin, $secretId, $this->db));
        $this->assertTrue(AP_Forum::isListableToUser(0, $mixedCatId, $this->db));
        $this->assertFalse(AP_Forum::isListableToUser(0, $mixedSecretId, $this->db));
        $this->assertFalse(AP_Roles::userCan($moderator, 'manage_forums', null, $this->db));
        $this->assertTrue(AP_Roles::userCan($moderator, 'moderate_forums', null, $this->db));

        AP_Session::resetCurrentUser();
        $this->assertFalse(AP_Rest::isForumRestRequest(['rest_route' => '/ap/v1/forums']));
        $this->assertFalse(AP_Rest::isForumRestRequest(['rest_route' => '/ap/v1/topics']));
        $this->assertFalse(AP_Rest::isForumRestRequest(['rest_route' => '/ap/v1']));
        $this->assertTrue(AP_Rest::isForumRestRequest([
            'rest_route' => '/ap/v1/forums',
            'forum_slug' => $secretSlug,
        ]));
        $this->assertTrue(AP_Rest::isForumRestRequest([
            'rest_route' => '/ap/v1/topics',
            'topic_id' => $secretTopicId,
        ]));
        $this->assertTrue(AP_Rest::isForumRestRequest([
            'rest_route' => '/ap/v1/forums/' . $secretId,
        ]));
        $this->assertTrue(AP_Rest::isForumRestRequest([
            'rest_route' => '/ap/v1/topics/' . $secretTopicId,
        ]));

        $prettyForums = AP_Rewrite::parseRequest('ap-json/ap/v1/forums', [], $this->db);
        $this->assertTrue(AP_Rest::isRestRequest($prettyForums));
        $this->assertFalse(AP_Rest::isForumRestRequest($prettyForums));
        $guestForumJson = $this->captureRestServe($prettyForums);
        $this->assertSame(200, $guestForumJson['status']);
        $this->assertStringContainsString('ZX9RestSquare', $guestForumJson['body']);
        $this->assertStringContainsString('ZX9RestLobby', $guestForumJson['body']);
        $this->assertStringContainsString('ZX9RestHall', $guestForumJson['body']);
        $this->assertSame([], $this->needlesFound([$guestForumJson['body']], $secretNeedles));
        $this->assertStringNotContainsString(AP_Forum_Front::CANNOT_VIEW_MESSAGE, $guestForumJson['body']);

        $prettyTopics = AP_Rewrite::parseRequest('ap-json/ap/v1/topics', [], $this->db);
        $guestTopicJson = $this->captureRestServe($prettyTopics);
        $this->assertSame(200, $guestTopicJson['status']);
        $this->assertStringContainsString('ZX9RestTownHello', $guestTopicJson['body']);
        $this->assertStringContainsString('ZX9RestLobbyHello', $guestTopicJson['body']);
        $this->assertSame([], $this->needlesFound([$guestTopicJson['body']], $secretNeedles));

        $guestDispatchList = AP_Rest::dispatch([
            'method' => 'GET',
            'route' => '/ap/v1/forums',
            'user_id' => 0,
        ], $this->db);
        $this->assertSame(200, $guestDispatchList['status']);
        $this->assertSame([], $this->needlesFound(
            [json_encode($guestDispatchList['data'], JSON_UNESCAPED_SLASHES) ?: ''],
            $secretNeedles
        ));

        $this->assertForumRestDenied($this->captureRestServe([
            'rest_route' => '/ap/v1/forums/' . $secretId,
        ]), $secretNeedles);

        $this->assertForumRestDenied($this->captureRestServe(
            AP_Rewrite::parseRequest('ap-json/ap/v1/forums/' . $secretId, [], $this->db)
        ), $secretNeedles);

        $this->assertForumRestDenied($this->captureRestServe([
            'rest_route' => '/ap/v1/forums/' . $categoryId,
        ]), $secretNeedles);

        $this->assertForumRestDenied($this->captureRestServe([
            'rest_route' => '/ap/v1/topics/' . $secretTopicId,
        ]), $secretNeedles);

        $this->assertForumRestDenied($this->captureRestServe([
            'rest_route' => '/ap/v1/topics',
            'forum' => $secretId,
            'forum_id' => $secretId,
            'forum_slug' => $secretSlug,
            'forum_name' => 'ZX9RestVault',
        ]), $secretNeedles);

        $this->assertForumRestDenied($this->captureRestServe(
            array_merge(
                AP_Rewrite::parseRequest('ap-json/ap/v1/forums', [], $this->db),
                [
                    'forum_slug' => $secretSlug,
                    'ap_forum_view' => 'forum',
                ]
            )
        ), $secretNeedles);

        $this->assertForumRestDenied($this->captureRestServe([
            'rest_route' => '/ap/v1/forums',
            'forum_id' => $categoryId,
            'forum_slug' => $categorySlug,
            'forum_name' => 'ZX9RestCellar',
        ]), $secretNeedles);

        $this->assertForumRestDenied($this->captureRestServe([
            'rest_route' => '/ap/v1/forums',
            'forum_id' => $mixedSecretId,
            'forum_slug' => $backroomSlug,
        ]), $secretNeedles);

        $this->assertForumRestDenied($this->captureRestServe([
            'rest_route' => '/ap/v1/topics',
            'topic_id' => $secretTopicId,
            'topic_slug' => $secretTopicSlug,
            'topic_title' => 'ZX9RestSecretToken thread',
            'forum_name' => 'ZX9RestVault',
        ]), $secretNeedles);

        $this->assertForumRestDenied($this->captureRestServe([
            'rest_route' => '/ap/v1/forums',
            'ap_forum_cannot_view' => true,
            'ap_forum_cannot_view_message' => AP_Forum_Front::CANNOT_VIEW_MESSAGE,
            'forum_name' => 'ZX9RestVault',
            'forum_slug' => $secretSlug,
            'forum_id' => $secretId,
        ]), $secretNeedles);

        $hallJson = $this->captureRestServe([
            'rest_route' => '/ap/v1/forums/' . $mixedCatId,
        ]);
        $this->assertSame(200, $hallJson['status']);
        $this->assertStringContainsString('ZX9RestHall', $hallJson['body']);
        $this->assertSame([], $this->needlesFound([$hallJson['body']], $secretNeedles));

        $publicJson = $this->captureRestServe([
            'rest_route' => '/ap/v1/forums/' . $publicId,
            'forum_slug' => $publicSlug,
        ]);
        $this->assertSame(200, $publicJson['status']);
        $this->assertStringContainsString('ZX9RestSquare', $publicJson['body']);
        $this->assertSame([], $this->needlesFound([$publicJson['body']], $secretNeedles));

        $guestTopicsInSecret = AP_Rest::dispatch([
            'method' => 'GET',
            'route' => '/ap/v1/topics',
            'user_id' => 0,
            'query' => ['forum' => $secretId],
        ], $this->db);
        $this->assertSame(404, $guestTopicsInSecret['status']);
        $this->assertSame('rest_cannot_view', $guestTopicsInSecret['data']['code'] ?? null);
        $this->assertSame(
            AP_Forum_Front::CANNOT_VIEW_MESSAGE,
            $guestTopicsInSecret['data']['message'] ?? null
        );
        $this->assertSame([], $this->needlesFound(
            [json_encode($guestTopicsInSecret['data'], JSON_UNESCAPED_SLASHES) ?: ''],
            $secretNeedles
        ));

        AP_Session::resetCurrentUser();
        $this->assertTrue(AP_Session::setAuthCookie($outsider, false, $this->db));
        $outsiderJson = $this->captureRestServe(
            AP_Rewrite::parseRequest('ap-json/ap/v1/forums', [], $this->db)
        );
        $this->assertStringContainsString('ZX9RestSquare', $outsiderJson['body']);
        $this->assertSame([], $this->needlesFound([$outsiderJson['body']], $secretNeedles));
        $this->assertForumRestDenied($this->captureRestServe([
            'rest_route' => '/ap/v1/forums/' . $secretId,
            'forum_slug' => $secretSlug,
        ]), $secretNeedles);
        AP_Session::clearAuthCookie();
        AP_Session::resetCurrentUser();

        $this->assertTrue(AP_Session::setAuthCookie($moderator, false, $this->db));
        $modJson = $this->captureRestServe(
            AP_Rewrite::parseRequest('ap-json/ap/v1/forums', [], $this->db)
        );
        $this->assertSame([], $this->needlesFound([$modJson['body']], $secretNeedles));
        $this->assertForumRestDenied($this->captureRestServe([
            'rest_route' => '/ap/v1/topics/' . $secretTopicId,
            'topic_slug' => $secretTopicSlug,
        ]), $secretNeedles);
        AP_Session::clearAuthCookie();
        AP_Session::resetCurrentUser();

        $this->assertTrue(AP_Session::setAuthCookie($member, false, $this->db));
        $memberForumJson = $this->captureRestServe(
            AP_Rewrite::parseRequest('ap-json/ap/v1/forums', [], $this->db)
        );
        $this->assertSame(200, $memberForumJson['status']);
        $this->assertStringContainsString('ZX9RestVault', $memberForumJson['body']);
        $this->assertStringContainsString('ZX9RestCellar', $memberForumJson['body']);
        $this->assertStringContainsString('ZX9RestBackroom', $memberForumJson['body']);
        $this->assertStringContainsString('ZX9RestSquare', $memberForumJson['body']);
        $this->assertStringNotContainsString(AP_Forum_Front::CANNOT_VIEW_MESSAGE, $memberForumJson['body']);

        $memberTopicJson = $this->captureRestServe(
            AP_Rewrite::parseRequest('ap-json/ap/v1/topics', [], $this->db)
        );
        $this->assertStringContainsString('ZX9RestSecretToken', $memberTopicJson['body']);
        $this->assertStringContainsString('ZX9RestTownHello', $memberTopicJson['body']);

        $memberDirect = $this->captureRestServe([
            'rest_route' => '/ap/v1/forums/' . $secretId,
            'forum_slug' => $secretSlug,
        ]);
        $this->assertSame(200, $memberDirect['status']);
        $this->assertStringContainsString('ZX9RestVault', $memberDirect['body']);
        $this->assertStringNotContainsString(AP_Forum_Front::CANNOT_VIEW_MESSAGE, $memberDirect['body']);

        $memberCellar = $this->captureRestServe([
            'rest_route' => '/ap/v1/forums/' . $categoryId,
            'forum_slug' => $categorySlug,
        ]);
        $this->assertSame(200, $memberCellar['status']);
        $this->assertStringContainsString('ZX9RestCellar', $memberCellar['body']);

        $memberTopicsInSecret = $this->captureRestServe([
            'rest_route' => '/ap/v1/topics',
            'forum' => $secretId,
        ]);
        $this->assertSame(200, $memberTopicsInSecret['status']);
        $this->assertStringContainsString('ZX9RestSecretToken', $memberTopicsInSecret['body']);
        $this->assertStringNotContainsString('ZX9RestTownHello', $memberTopicsInSecret['body']);
        AP_Session::clearAuthCookie();
        AP_Session::resetCurrentUser();

        $this->assertTrue(AP_Session::setAuthCookie($admin, false, $this->db));
        $adminJson = $this->captureRestServe(
            AP_Rewrite::parseRequest('ap-json/ap/v1/forums', [], $this->db)
        );
        $this->assertStringContainsString('ZX9RestVault', $adminJson['body']);
        AP_Session::clearAuthCookie();
        AP_Session::resetCurrentUser();

        $acpTable = new AP_Forums_List_Table($this->db);
        $acpTable->prepareItems([]);
        $acpNames = [];
        foreach ($acpTable->items as $item) {
            $acpNames[] = (string) ($item->forum_name ?? '');
        }
        $this->assertContains('ZX9RestVault', $acpNames);
        $this->assertContains('ZX9RestCellar', $acpNames);
        $this->assertContains('ZX9RestSquare', $acpNames);

        AP_Rest::reset();
        AP_Rewrite::resetCache();
        unset($GLOBALS['apdb']);
    }

    /**
     * Pretty URLs: hide unlistable forums and empty parents; a direct
     * /forums/{slug}/ or /topic/{slug}/ is a generic “you cannot view this”
     * 404 (never a page that names the board or slug).
     */
    public function testThisGroupOnlyPrettyUrlsHideForumAndEmptyParent(): void
    {
        require_once $this->root . '/ap-includes/hooks.php';
        require_once $this->root . '/ap-includes/class-ap-query.php';
        require_once $this->root . '/ap-includes/class-ap-rewrite.php';
        require_once $this->root . '/ap-includes/class-ap-session.php';
        require_once $this->root . '/ap-includes/class-ap-forum-front.php';
        require_once $this->root . '/ap-includes/class-ap-theme.php';
        require_once $this->root . '/ap-includes/class-ap-seo.php';
        require_once $this->root . '/ap-admin/includes/class-ap-forums-list-table.php';

        foreach (
            [
                'AP_LOGGED_IN_KEY' => 'zx9-pretty-logged-in-key',
                'AP_LOGGED_IN_SALT' => 'zx9-pretty-logged-in-salt',
                'AP_AUTH_KEY' => 'zx9-pretty-auth-key',
                'AP_AUTH_SALT' => 'zx9-pretty-auth-salt',
            ] as $const => $value
        ) {
            if (!defined($const)) {
                define($const, $value);
            }
        }

        if (function_exists('ap_reset_hooks')) {
            ap_reset_hooks();
        }
        AP_Rewrite::resetCache();
        AP_Seo::reset();
        AP_Theme::reset();
        AP_Session::clearAuthCookie();
        AP_Session::resetCurrentUser();
        AP_Seo::register();

        $GLOBALS['apdb'] = $this->db;
        AP_Options::update('home', 'https://example.test', $this->db);
        AP_Options::update('siteurl', 'https://example.test', $this->db);
        AP_Options::update('permalink_structure', '/%postname%/', $this->db);
        AP_Options::update('blogname', 'Pretty URL Hygiene', $this->db);
        AP_Options::update('ap_module_forum', '1', $this->db);
        AP_Options::update('stylesheet', 'agora', $this->db);
        AP_Options::update('template', 'agora', $this->db);
        AP_Options::flushCache();
        AP_Rewrite::resetCache();

        AP_Forum_Permissions::ensureDefaults($this->db);
        AP_Group::ensureSystemGroups($this->db);

        $vip = AP_Group::create([
            'group_name' => 'ZX9 Pretty Circle',
            'group_type' => AP_Group::TYPE_CLOSED,
        ], $this->db);
        $this->assertGreaterThan(0, $vip);

        $categoryId = AP_Forum::insertForum([
            'forum_name' => 'ZX9PrettyCellar',
            'forum_type' => AP_Forum::FORUM_TYPE_CATEGORY,
        ], $this->db);
        $secretId = AP_Forum::insertForum([
            'forum_name' => 'ZX9PrettyVault',
            'forum_type' => AP_Forum::FORUM_TYPE_FORUM,
            'parent_id' => $categoryId,
        ], $this->db);
        $publicId = AP_Forum::insertForum([
            'forum_name' => 'ZX9PrettySquare',
            'forum_type' => AP_Forum::FORUM_TYPE_FORUM,
        ], $this->db);
        $mixedCatId = AP_Forum::insertForum([
            'forum_name' => 'ZX9PrettyHall',
            'forum_type' => AP_Forum::FORUM_TYPE_CATEGORY,
        ], $this->db);
        $mixedPublicId = AP_Forum::insertForum([
            'forum_name' => 'ZX9PrettyLobby',
            'forum_type' => AP_Forum::FORUM_TYPE_FORUM,
            'parent_id' => $mixedCatId,
        ], $this->db);
        $mixedSecretId = AP_Forum::insertForum([
            'forum_name' => 'ZX9PrettyBackroom',
            'forum_type' => AP_Forum::FORUM_TYPE_FORUM,
            'parent_id' => $mixedCatId,
        ], $this->db);
        $nestedOuterId = AP_Forum::insertForum([
            'forum_name' => 'ZX9PrettyNestedOuter',
            'forum_type' => AP_Forum::FORUM_TYPE_CATEGORY,
        ], $this->db);
        $nestedInnerId = AP_Forum::insertForum([
            'forum_name' => 'ZX9PrettyNestedInner',
            'forum_type' => AP_Forum::FORUM_TYPE_CATEGORY,
            'parent_id' => $nestedOuterId,
        ], $this->db);
        $nestedSecretId = AP_Forum::insertForum([
            'forum_name' => 'ZX9PrettyNestedVault',
            'forum_type' => AP_Forum::FORUM_TYPE_FORUM,
            'parent_id' => $nestedInnerId,
        ], $this->db);
        $this->assertGreaterThan(0, $categoryId);
        $this->assertGreaterThan(0, $secretId);
        $this->assertGreaterThan(0, $publicId);
        $this->assertGreaterThan(0, $mixedCatId);
        $this->assertGreaterThan(0, $mixedPublicId);
        $this->assertGreaterThan(0, $mixedSecretId);
        $this->assertGreaterThan(0, $nestedOuterId);
        $this->assertGreaterThan(0, $nestedInnerId);
        $this->assertGreaterThan(0, $nestedSecretId);

        $this->assertTrue(AP_Forum_Permissions::saveAccessFromForm($secretId, [
            'forum_access_level' => 'group_only',
            'forum_access_groups' => [$vip],
        ], $this->db));
        $this->assertTrue(AP_Forum_Permissions::saveAccessFromForm($mixedSecretId, [
            'forum_access_level' => 'group_only',
            'forum_access_groups' => [$vip],
        ], $this->db));
        $this->assertTrue(AP_Forum_Permissions::saveAccessFromForm($nestedSecretId, [
            'forum_access_level' => 'group_only',
            'forum_access_groups' => [$vip],
        ], $this->db));

        $outsider = $this->createUser('zx9_pretty_out', 'zx9_pretty_out@example.test', 'subscriber');
        $member = $this->createUser('zx9_pretty_vip', 'zx9_pretty_vip@example.test', 'subscriber');
        $moderator = $this->createUser('zx9_pretty_mod', 'zx9_pretty_mod@example.test', 'editor');
        $admin = $this->createUser('zx9_pretty_admin', 'zx9_pretty_admin@example.test', 'administrator');
        AP_Group::addMember($vip, $member, AP_Group::ROLE_MEMBER, $this->db);

        $secretTopicId = AP_Forum::createTopic([
            'forum_id' => $secretId,
            'topic_title' => 'ZX9PrettySecretToken thread',
            'content' => 'ZX9PrettySecretBody stays off pretty URLs.',
            'poster_id' => $member,
        ], $this->db);
        $publicTopicId = AP_Forum::createTopic([
            'forum_id' => $publicId,
            'topic_title' => 'ZX9PrettyTownHello thread',
            'content' => 'Public square chatter.',
            'poster_id' => $outsider,
        ], $this->db);
        $mixedPublicTopicId = AP_Forum::createTopic([
            'forum_id' => $mixedPublicId,
            'topic_title' => 'ZX9PrettyLobbyHello thread',
            'content' => 'Hall lobby chatter.',
            'poster_id' => $outsider,
        ], $this->db);
        $mixedSecretTopicId = AP_Forum::createTopic([
            'forum_id' => $mixedSecretId,
            'topic_title' => 'ZX9PrettyBackroomToken thread',
            'content' => 'ZX9PrettyBackroomBody stays off pretty URLs.',
            'poster_id' => $member,
        ], $this->db);
        $this->assertGreaterThan(0, $secretTopicId);
        $this->assertGreaterThan(0, $publicTopicId);
        $this->assertGreaterThan(0, $mixedPublicTopicId);
        $this->assertGreaterThan(0, $mixedSecretTopicId);

        $secretForum = AP_Forum::getForum($secretId, $this->db);
        $secretTopic = AP_Forum::getTopic($secretTopicId, $this->db);
        $this->assertNotNull($secretForum);
        $this->assertNotNull($secretTopic);
        $secretSlug = (string) $secretForum->forum_slug;
        $secretTopicSlug = (string) $secretTopic->topic_slug;
        $categorySlug = (string) (AP_Forum::getForum($categoryId, $this->db)->forum_slug ?? '');
        $hallSlug = (string) (AP_Forum::getForum($mixedCatId, $this->db)->forum_slug ?? '');
        $backroomSlug = (string) (AP_Forum::getForum($mixedSecretId, $this->db)->forum_slug ?? '');
        $lobbySlug = (string) (AP_Forum::getForum($mixedPublicId, $this->db)->forum_slug ?? '');
        $publicSlug = (string) (AP_Forum::getForum($publicId, $this->db)->forum_slug ?? '');
        $publicTopicSlug = (string) (AP_Forum::getTopic($publicTopicId, $this->db)->topic_slug ?? '');
        $nestedOuterSlug = (string) (AP_Forum::getForum($nestedOuterId, $this->db)->forum_slug ?? '');
        $nestedInnerSlug = (string) (AP_Forum::getForum($nestedInnerId, $this->db)->forum_slug ?? '');
        $nestedSecretSlug = (string) (AP_Forum::getForum($nestedSecretId, $this->db)->forum_slug ?? '');
        $this->assertNotSame('', $secretSlug);
        $this->assertNotSame('', $secretTopicSlug);
        $this->assertNotSame('', $categorySlug);
        $this->assertNotSame('', $hallSlug);
        $this->assertNotSame('', $backroomSlug);
        $this->assertNotSame('', $lobbySlug);
        $this->assertNotSame('', $publicSlug);
        $this->assertNotSame('', $publicTopicSlug);
        $this->assertNotSame('', $nestedOuterSlug);
        $this->assertNotSame('', $nestedInnerSlug);
        $this->assertNotSame('', $nestedSecretSlug);

        $secretNeedles = [
            'ZX9PrettyCellar',
            'ZX9PrettyVault',
            'ZX9PrettyBackroom',
            'ZX9PrettySecretToken',
            'ZX9PrettySecretBody',
            'ZX9PrettyBackroomToken',
            'ZX9PrettyBackroomBody',
            'ZX9PrettyNestedOuter',
            'ZX9PrettyNestedInner',
            'ZX9PrettyNestedVault',
            $secretSlug,
            $secretTopicSlug,
            $categorySlug,
            $backroomSlug,
            $nestedOuterSlug,
            $nestedInnerSlug,
            $nestedSecretSlug,
        ];

        $this->assertFalse(AP_Forum::isListableToUser(0, $secretId, $this->db));
        $this->assertFalse(AP_Forum::isListableToUser(0, $categoryId, $this->db));
        $this->assertFalse(AP_Forum::isListableToUser($outsider, $secretId, $this->db));
        $this->assertFalse(AP_Forum::isListableToUser($moderator, $secretId, $this->db));
        $this->assertTrue(AP_Forum::isListableToUser($member, $secretId, $this->db));
        $this->assertTrue(AP_Forum::isListableToUser($member, $categoryId, $this->db));
        $this->assertTrue(AP_Forum::isListableToUser($admin, $secretId, $this->db));
        $this->assertTrue(AP_Forum::isListableToUser(0, $mixedCatId, $this->db));
        $this->assertFalse(AP_Forum::isListableToUser(0, $mixedSecretId, $this->db));
        $this->assertFalse(AP_Forum::isListableToUser(0, $nestedOuterId, $this->db));
        $this->assertFalse(AP_Forum::isListableToUser(0, $nestedInnerId, $this->db));
        $this->assertFalse(AP_Roles::userCan($moderator, 'manage_forums', null, $this->db));
        $this->assertTrue(AP_Roles::userCan($moderator, 'moderate_forums', null, $this->db));

        AP_Session::resetCurrentUser();
        $prettySecret = $this->capturePrettyUrlPage('forums/' . $secretSlug);
        $this->assertSame('forum', $prettySecret['vars']['ap_forum_view'] ?? null);
        $this->assertSame($secretSlug, $prettySecret['vars']['forum_slug'] ?? null);
        $this->assertForumPrettyDenied($prettySecret, $secretNeedles);

        $this->assertForumPrettyDenied(
            $this->capturePrettyUrlPage('forums/' . $secretSlug . '/'),
            $secretNeedles
        );
        $this->assertForumPrettyDenied(
            $this->capturePrettyUrlPage('forums/' . $secretSlug . '/page/2'),
            $secretNeedles
        );
        $prettyTopic = $this->capturePrettyUrlPage('topic/' . $secretTopicSlug);
        $this->assertSame('topic', $prettyTopic['vars']['ap_forum_view'] ?? null);
        $this->assertSame($secretTopicSlug, $prettyTopic['vars']['topic_slug'] ?? null);
        $this->assertForumPrettyDenied($prettyTopic, $secretNeedles);

        $prettyEmptyParent = $this->capturePrettyUrlPage('forums/' . $categorySlug);
        $this->assertSame($categorySlug, $prettyEmptyParent['vars']['forum_slug'] ?? null);
        $this->assertForumPrettyDenied($prettyEmptyParent, $secretNeedles);

        $this->assertForumPrettyDenied(
            $this->capturePrettyUrlPage('forums/' . $backroomSlug),
            $secretNeedles
        );
        $this->assertForumPrettyDenied(
            $this->capturePrettyUrlPage('forums/' . $nestedOuterSlug),
            $secretNeedles
        );
        $this->assertForumPrettyDenied(
            $this->capturePrettyUrlPage('forums/' . $nestedInnerSlug),
            $secretNeedles
        );
        $this->assertForumPrettyDenied(
            $this->capturePrettyUrlPage('forums/' . $nestedSecretSlug),
            $secretNeedles
        );

        $stuffedVars = AP_Rewrite::parseRequest('forums/' . $secretSlug, [], $this->db);
        $stuffedVars['forum_name'] = 'ZX9PrettyVault';
        $stuffedVars['forum_desc'] = 'ZX9PrettySecretBody stays off pretty URLs.';
        $stuffedVars['topic_title'] = 'ZX9PrettySecretToken thread';
        $stuffedArgs = AP_Rewrite::toQueryArgs($stuffedVars, $this->db);
        $this->assertTrue(!empty($stuffedArgs['ap_forum_cannot_view']));
        $this->assertSame(AP_Forum_Front::CANNOT_VIEW_MESSAGE, $stuffedArgs['ap_forum_cannot_view_message'] ?? null);
        $this->assertSame('', (string) ($stuffedArgs['forum_name'] ?? 'x'));
        $this->assertSame('', (string) ($stuffedArgs['forum_slug'] ?? 'x'));
        $this->assertSame('', (string) ($stuffedArgs['forum_desc'] ?? 'x'));
        $this->assertSame('', (string) ($stuffedArgs['topic_title'] ?? 'x'));
        $this->assertSame(0, (int) ($stuffedArgs['forum_id'] ?? -1));
        $this->assertSame([], $this->needlesFound(
            [json_encode($stuffedArgs, JSON_UNESCAPED_SLASHES) ?: ''],
            $secretNeedles
        ));

        AP_Session::resetCurrentUser();
        $stuffedDirect = new AP_Query([
            'ap_forum_view' => 'forum',
            'forum_id' => $secretId,
            'forum_slug' => $secretSlug,
            'forum_name' => 'ZX9PrettyVault',
            'forum_desc' => 'ZX9PrettySecretBody stays off pretty URLs.',
        ], $this->db);
        AP_Forum_Front::applyToQuery($stuffedDirect, $this->db);
        $this->assertTrue(!empty($stuffedDirect->get('ap_forum_cannot_view', false)));
        $this->assertSame('', (string) $stuffedDirect->get('forum_name', 'x'));
        $this->assertSame('', (string) $stuffedDirect->get('forum_slug', 'x'));
        $this->assertTrue($stuffedDirect->is_404);
        $this->assertSame('404.php', AP_Theme::getHierarchy($stuffedDirect, $this->db)[0] ?? null);
        $this->assertSame([], $this->needlesFound(
            [json_encode($stuffedDirect->query_vars, JSON_UNESCAPED_SLASHES) ?: ''],
            $secretNeedles
        ));

        $hallPage = $this->capturePrettyUrlPage('forums/' . $hallSlug);
        $this->assertFalse(!empty($hallPage['query']->get('ap_forum_cannot_view', false)));
        $this->assertFalse($hallPage['query']->is_404);
        $this->assertSame('ZX9PrettyHall', (string) $hallPage['query']->get('forum_name', ''));
        $this->assertStringContainsString('ZX9PrettyHall', $hallPage['html']);
        $this->assertSame([], $this->needlesFound([$hallPage['html']], $secretNeedles));
        $this->assertStringNotContainsString(AP_Forum_Front::CANNOT_VIEW_MESSAGE, $hallPage['html']);

        $lobbyPage = $this->capturePrettyUrlPage('forums/' . $lobbySlug);
        $this->assertFalse(!empty($lobbyPage['query']->get('ap_forum_cannot_view', false)));
        $this->assertStringContainsString('ZX9PrettyLobby', $lobbyPage['html']);
        $this->assertSame([], $this->needlesFound([$lobbyPage['html']], $secretNeedles));

        $publicPage = $this->capturePrettyUrlPage('forums/' . $publicSlug);
        $this->assertFalse(!empty($publicPage['query']->get('ap_forum_cannot_view', false)));
        $this->assertSame('ZX9PrettySquare', (string) $publicPage['query']->get('forum_name', ''));
        $this->assertStringContainsString('ZX9PrettySquare', $publicPage['html']);
        $this->assertStringContainsString('ZX9PrettyTownHello', $publicPage['html']);
        $this->assertSame([], $this->needlesFound([$publicPage['html']], $secretNeedles));

        $publicTopicPage = $this->capturePrettyUrlPage('topic/' . $publicTopicSlug);
        $this->assertFalse(!empty($publicTopicPage['query']->get('ap_forum_cannot_view', false)));
        $this->assertStringContainsString('ZX9PrettyTownHello', $publicTopicPage['html']);
        $this->assertSame([], $this->needlesFound([$publicTopicPage['html']], $secretNeedles));

        $indexPage = $this->capturePrettyUrlPage('forums/');
        $this->assertFalse(!empty($indexPage['query']->get('ap_forum_cannot_view', false)));
        $this->assertStringContainsString('ap-forum--index', $indexPage['html']);
        $this->assertStringContainsString('ZX9PrettySquare', $indexPage['html']);
        $this->assertStringContainsString('ZX9PrettyHall', $indexPage['html']);
        $this->assertStringContainsString('ZX9PrettyLobby', $indexPage['html']);
        $this->assertSame([], $this->needlesFound([$indexPage['html']], $secretNeedles));
        $this->assertStringNotContainsString(AP_Forum_Front::CANNOT_VIEW_MESSAGE, $indexPage['html']);

        $this->assertTrue(AP_Session::setAuthCookie($outsider, false, $this->db));
        $this->assertForumPrettyDenied(
            $this->capturePrettyUrlPage('forums/' . $secretSlug),
            $secretNeedles
        );
        $this->assertForumPrettyDenied(
            $this->capturePrettyUrlPage('forums/' . $categorySlug),
            $secretNeedles
        );
        AP_Session::clearAuthCookie();
        AP_Session::resetCurrentUser();

        $this->assertTrue(AP_Session::setAuthCookie($moderator, false, $this->db));
        $this->assertForumPrettyDenied(
            $this->capturePrettyUrlPage('topic/' . $secretTopicSlug),
            $secretNeedles
        );
        $this->assertForumPrettyDenied(
            $this->capturePrettyUrlPage('forums/' . $nestedSecretSlug),
            $secretNeedles
        );
        AP_Session::clearAuthCookie();
        AP_Session::resetCurrentUser();

        $this->assertTrue(AP_Session::setAuthCookie($member, false, $this->db));
        $memberPretty = $this->capturePrettyUrlPage('forums/' . $secretSlug);
        $this->assertFalse(!empty($memberPretty['query']->get('ap_forum_cannot_view', false)));
        $this->assertFalse($memberPretty['query']->is_404);
        $this->assertSame('ZX9PrettyVault', (string) $memberPretty['query']->get('forum_name', ''));
        $this->assertStringContainsString('ZX9PrettyVault', $memberPretty['html']);
        $this->assertStringContainsString('ZX9PrettySecretToken', $memberPretty['html']);
        $memberTopic = $this->capturePrettyUrlPage('topic/' . $secretTopicSlug);
        $this->assertStringContainsString('ZX9PrettySecretToken', $memberTopic['html']);
        $memberCellar = $this->capturePrettyUrlPage('forums/' . $categorySlug);
        $this->assertFalse(!empty($memberCellar['query']->get('ap_forum_cannot_view', false)));
        $this->assertStringContainsString('ZX9PrettyCellar', $memberCellar['html']);
        $memberNested = $this->capturePrettyUrlPage('forums/' . $nestedOuterSlug);
        $this->assertFalse(!empty($memberNested['query']->get('ap_forum_cannot_view', false)));
        $this->assertStringContainsString('ZX9PrettyNestedOuter', $memberNested['html']);
        AP_Session::clearAuthCookie();
        AP_Session::resetCurrentUser();

        $this->assertTrue(AP_Session::setAuthCookie($admin, false, $this->db));
        $adminPretty = $this->capturePrettyUrlPage('forums/' . $secretSlug);
        $this->assertFalse(!empty($adminPretty['query']->get('ap_forum_cannot_view', false)));
        $this->assertStringContainsString('ZX9PrettyVault', $adminPretty['html']);
        AP_Session::clearAuthCookie();
        AP_Session::resetCurrentUser();

        $acpTable = new AP_Forums_List_Table($this->db);
        $acpTable->prepareItems([]);
        $acpNames = [];
        foreach ($acpTable->items as $item) {
            $acpNames[] = (string) ($item->forum_name ?? '');
        }
        $this->assertContains('ZX9PrettyVault', $acpNames);
        $this->assertContains('ZX9PrettyCellar', $acpNames);
        $this->assertContains('ZX9PrettySquare', $acpNames);
        $this->assertContains('ZX9PrettyNestedVault', $acpNames);

        unset($GLOBALS['ap_query']);
        AP_Theme::reset();
        AP_Seo::reset();
        AP_Rewrite::resetCache();
        unset($GLOBALS['apdb']);
    }

    public function testThisGroupOnlyListingHygieneHidesBoardFromOutsiders(): void
    {
        require_once $this->root . '/ap-includes/hooks.php';
        require_once $this->root . '/ap-includes/class-ap-post.php';
        require_once $this->root . '/ap-includes/class-ap-query.php';
        require_once $this->root . '/ap-includes/class-ap-rewrite.php';
        require_once $this->root . '/ap-includes/class-ap-session.php';
        require_once $this->root . '/ap-includes/class-ap-forum-front.php';
        require_once $this->root . '/ap-includes/class-ap-rest.php';
        require_once $this->root . '/ap-includes/class-ap-sitemap.php';
        require_once $this->root . '/ap-includes/class-ap-feed.php';
        require_once $this->root . '/ap-includes/class-ap-seo.php';
        require_once $this->root . '/ap-includes/class-ap-theme.php';
        require_once $this->root . '/ap-includes/class-ap-nav-menu.php';
        require_once $this->root . '/ap-admin/includes/class-ap-forums-list-table.php';

        foreach (
            [
                'AP_LOGGED_IN_KEY' => 'zx9-logged-in-key',
                'AP_LOGGED_IN_SALT' => 'zx9-logged-in-salt',
                'AP_AUTH_KEY' => 'zx9-auth-key',
                'AP_AUTH_SALT' => 'zx9-auth-salt',
            ] as $const => $value
        ) {
            if (!defined($const)) {
                define($const, $value);
            }
        }

        if (function_exists('ap_reset_hooks')) {
            ap_reset_hooks();
        }
        AP_Rewrite::resetCache();
        AP_Rest::reset();
        AP_Seo::reset();
        AP_Nav_Menu::reset();
        AP_Session::clearAuthCookie();
        AP_Session::resetCurrentUser();

        $GLOBALS['apdb'] = $this->db;
        AP_Options::update('home', 'https://example.test', $this->db);
        AP_Options::update('siteurl', 'https://example.test', $this->db);
        AP_Options::update('permalink_structure', '/%postname%/', $this->db);
        AP_Options::update('blogname', 'Listing Hygiene Site', $this->db);
        AP_Options::update('ap_module_forum', '1', $this->db);
        AP_Options::update('ap_module_blog', '1', $this->db);
        AP_Options::update('rest_api_enabled', '1', $this->db);
        AP_Options::update('sitemap_enabled', '1', $this->db);
        AP_Options::flushCache();
        AP_Rewrite::resetCache();

        AP_Forum_Permissions::ensureDefaults($this->db);
        AP_Group::ensureSystemGroups($this->db);

        $vip = AP_Group::create([
            'group_name' => 'ZX9 Inner Circle',
            'group_type' => AP_Group::TYPE_CLOSED,
        ], $this->db);
        $this->assertGreaterThan(0, $vip);

        $categoryId = AP_Forum::insertForum([
            'forum_name' => 'ZX9StaffCellar',
            'forum_type' => AP_Forum::FORUM_TYPE_CATEGORY,
        ], $this->db);
        $secretId = AP_Forum::insertForum([
            'forum_name' => 'ZX9VipCircle',
            'forum_type' => AP_Forum::FORUM_TYPE_FORUM,
            'parent_id' => $categoryId,
        ], $this->db);
        $publicId = AP_Forum::insertForum([
            'forum_name' => 'ZX9TownSquare',
            'forum_type' => AP_Forum::FORUM_TYPE_FORUM,
        ], $this->db);
        $nestedOuterId = AP_Forum::insertForum([
            'forum_name' => 'ZX9NestedOuter',
            'forum_type' => AP_Forum::FORUM_TYPE_CATEGORY,
        ], $this->db);
        $nestedInnerId = AP_Forum::insertForum([
            'forum_name' => 'ZX9NestedInner',
            'forum_type' => AP_Forum::FORUM_TYPE_CATEGORY,
            'parent_id' => $nestedOuterId,
        ], $this->db);
        $nestedSecretId = AP_Forum::insertForum([
            'forum_name' => 'ZX9NestedVault',
            'forum_type' => AP_Forum::FORUM_TYPE_FORUM,
            'parent_id' => $nestedInnerId,
        ], $this->db);
        $this->assertGreaterThan(0, $categoryId);
        $this->assertGreaterThan(0, $secretId);
        $this->assertGreaterThan(0, $publicId);
        $this->assertGreaterThan(0, $nestedOuterId);
        $this->assertGreaterThan(0, $nestedInnerId);
        $this->assertGreaterThan(0, $nestedSecretId);

        $this->assertTrue(AP_Forum_Permissions::saveAccessFromForm($secretId, [
            'forum_access_level' => 'group_only',
            'forum_access_groups' => [$vip],
        ], $this->db));
        $this->assertTrue(AP_Forum_Permissions::saveAccessFromForm($nestedSecretId, [
            'forum_access_level' => 'group_only',
            'forum_access_groups' => [$vip],
        ], $this->db));

        $outsider = $this->createUser('zx9_out', 'zx9_out@example.test', 'subscriber');
        $member = $this->createUser('zx9_vip', 'zx9_vip@example.test', 'subscriber');
        $moderator = $this->createUser('zx9_mod', 'zx9_mod@example.test', 'editor');
        $admin = $this->createUser('zx9_admin', 'zx9_admin@example.test', 'administrator');
        AP_Group::addMember($vip, $member, AP_Group::ROLE_MEMBER, $this->db);

        $secretTopicId = AP_Forum::createTopic([
            'forum_id' => $secretId,
            'topic_title' => 'ZX9SecretToken thread',
            'content' => 'ZX9SecretBody stays off public surfaces.',
            'poster_id' => $member,
        ], $this->db);
        $publicTopicId = AP_Forum::createTopic([
            'forum_id' => $publicId,
            'topic_title' => 'ZX9TownHello thread',
            'content' => 'Public square chatter.',
            'poster_id' => $outsider,
        ], $this->db);
        $this->assertGreaterThan(0, $secretTopicId);
        $this->assertGreaterThan(0, $publicTopicId);

        $secretForum = AP_Forum::getForum($secretId, $this->db);
        $secretTopic = AP_Forum::getTopic($secretTopicId, $this->db);
        $publicForum = AP_Forum::getForum($publicId, $this->db);
        $publicTopic = AP_Forum::getTopic($publicTopicId, $this->db);
        $nestedOuter = AP_Forum::getForum($nestedOuterId, $this->db);
        $nestedInner = AP_Forum::getForum($nestedInnerId, $this->db);
        $nestedSecret = AP_Forum::getForum($nestedSecretId, $this->db);
        $this->assertNotNull($secretForum);
        $this->assertNotNull($secretTopic);
        $this->assertNotNull($publicForum);
        $this->assertNotNull($publicTopic);
        $this->assertNotNull($nestedOuter);
        $this->assertNotNull($nestedInner);
        $this->assertNotNull($nestedSecret);
        $secretSlug = (string) $secretForum->forum_slug;
        $secretTopicSlug = (string) $secretTopic->topic_slug;
        $publicSlug = (string) $publicForum->forum_slug;
        $publicTopicSlug = (string) $publicTopic->topic_slug;
        $nestedOuterSlug = (string) $nestedOuter->forum_slug;
        $nestedInnerSlug = (string) $nestedInner->forum_slug;
        $nestedSecretSlug = (string) $nestedSecret->forum_slug;
        $this->assertNotSame('', $secretSlug);
        $this->assertNotSame('', $secretTopicSlug);
        $this->assertNotSame('', $publicSlug);
        $this->assertNotSame('', $publicTopicSlug);
        $this->assertNotSame('', $nestedOuterSlug);
        $this->assertNotSame('', $nestedInnerSlug);
        $this->assertNotSame('', $nestedSecretSlug);

        $secretNeedles = [
            'ZX9StaffCellar',
            'ZX9VipCircle',
            'ZX9SecretToken',
            'ZX9SecretBody',
            'ZX9NestedOuter',
            'ZX9NestedInner',
            'ZX9NestedVault',
            $secretSlug,
            $secretTopicSlug,
            $nestedOuterSlug,
            $nestedInnerSlug,
            $nestedSecretSlug,
        ];

        $guestIndex = AP_Forum::getIndexData($this->db, ['user_id' => 0]);
        $guestNames = $this->indexVisibleText($guestIndex);
        $this->assertContains('ZX9TownSquare', $guestNames);
        $this->assertSame([], $this->needlesFound($guestNames, $secretNeedles));

        $outsiderIndex = AP_Forum::getIndexData($this->db, ['user_id' => $outsider]);
        $this->assertSame([], $this->needlesFound($this->indexVisibleText($outsiderIndex), $secretNeedles));

        $modIndex = AP_Forum::getIndexData($this->db, ['user_id' => $moderator]);
        $this->assertSame([], $this->needlesFound($this->indexVisibleText($modIndex), $secretNeedles));
        $this->assertFalse(AP_Roles::userCan($moderator, 'manage_forums', null, $this->db));
        $this->assertTrue(AP_Roles::userCan($moderator, 'moderate_forums', null, $this->db));

        $memberIndex = AP_Forum::getIndexData($this->db, ['user_id' => $member]);
        $memberNames = $this->indexVisibleText($memberIndex);
        $this->assertContains('ZX9StaffCellar', $memberNames);
        $this->assertContains('ZX9VipCircle', $memberNames);

        $adminIndex = AP_Forum::getIndexData($this->db, ['user_id' => $admin]);
        $adminNames = $this->indexVisibleText($adminIndex);
        $this->assertContains('ZX9VipCircle', $adminNames);

        $guestListable = AP_Forum::getListableForums(0, [], $this->db);
        $this->assertSame([], $this->needlesFound($this->forumObjectsText($guestListable), $secretNeedles));
        $this->assertFalse(AP_Forum::isListableToUser(0, $secretId, $this->db));
        $this->assertFalse(AP_Forum::isListableToUser(0, $categoryId, $this->db));
        $this->assertFalse(AP_Forum::isListableToUser($outsider, $secretId, $this->db));
        $this->assertFalse(AP_Forum::isListableToUser($moderator, $secretId, $this->db));
        $this->assertTrue(AP_Forum::isListableToUser($member, $secretId, $this->db));
        $this->assertTrue(AP_Forum::isListableToUser($member, $categoryId, $this->db));
        $this->assertTrue(AP_Forum::isListableToUser($admin, $secretId, $this->db));
        $this->assertFalse(AP_Forum::hasListableDescendant($categoryId, 0, $this->db));
        $this->assertTrue(AP_Forum::hasListableDescendant($categoryId, $member, $this->db));
        $this->assertFalse(AP_Forum::hasListableDescendant($nestedOuterId, 0, $this->db));
        $this->assertFalse(AP_Forum::hasListableDescendant($nestedInnerId, 0, $this->db));
        $this->assertTrue(AP_Forum::hasListableDescendant($nestedOuterId, $member, $this->db));
        $this->assertTrue(AP_Forum::hasListableDescendant($nestedInnerId, $member, $this->db));
        $memberListable = $this->forumObjectsText(AP_Forum::getListableForums($member, [], $this->db));
        $this->assertContains('ZX9NestedVault', $memberListable);
        $this->assertContains('ZX9NestedInner', $memberListable);
        $this->assertContains('ZX9NestedOuter', $memberListable);

        $guestSearch = AP_Forum::search('ZX9SecretToken', [
            'type' => 'all',
            'check_permissions' => true,
            'user_id' => 0,
        ], $this->db);
        $this->assertSame(0, (int) ($guestSearch['total'] ?? -1));
        $this->assertSame([], $guestSearch['results'] ?? ['x']);

        $outsiderSearch = AP_Forum::search('ZX9SecretToken', [
            'type' => 'all',
            'check_permissions' => true,
            'user_id' => $outsider,
        ], $this->db);
        $this->assertSame(0, (int) ($outsiderSearch['total'] ?? -1));

        $modSearch = AP_Forum::search('ZX9SecretToken', [
            'type' => 'all',
            'check_permissions' => true,
            'user_id' => $moderator,
        ], $this->db);
        $this->assertSame(0, (int) ($modSearch['total'] ?? -1));

        $memberSearch = AP_Forum::search('ZX9SecretToken', [
            'type' => 'all',
            'check_permissions' => true,
            'user_id' => $member,
        ], $this->db);
        $memberTitles = array_map(
            static fn ($row) => (string) ($row['title'] ?? $row['topic_title'] ?? ''),
            is_array($memberSearch['results'] ?? null) ? $memberSearch['results'] : []
        );
        $this->assertContains('ZX9SecretToken thread', $memberTitles);

        $publicSearch = AP_Forum::search('ZX9TownHello', [
            'type' => 'topics',
            'check_permissions' => true,
            'user_id' => 0,
        ], $this->db);
        $publicTitles = array_map(
            static fn ($t) => (string) $t->topic_title,
            $publicSearch['topics']
        );
        $this->assertContains('ZX9TownHello thread', $publicTitles);

        $forumSitemap = AP_Sitemap::buildProvider('forums', 1, $this->db);
        $topicSitemap = AP_Sitemap::buildProvider('topics', 1, $this->db);
        $this->assertSame([], $this->needlesFound([$forumSitemap, $topicSitemap], $secretNeedles));
        $this->assertStringContainsString($publicSlug, $forumSitemap);
        $this->assertStringContainsString($publicTopicSlug, $topicSitemap);

        $rss = AP_Feed::buildRss2($this->db);
        $atom = AP_Feed::buildAtom($this->db);
        $this->assertSame([], $this->needlesFound([$rss, $atom], $secretNeedles));

        $guestForums = AP_Rest::dispatch([
            'method' => 'GET',
            'route' => '/ap/v1/forums',
            'user_id' => 0,
        ], $this->db);
        $this->assertSame(200, $guestForums['status']);
        $this->assertSame([], $this->needlesFound(
            [json_encode($guestForums['data'], JSON_UNESCAPED_SLASHES) ?: ''],
            $secretNeedles
        ));

        $guestForumGet = AP_Rest::dispatch([
            'method' => 'GET',
            'route' => '/ap/v1/forums/' . $secretId,
            'user_id' => 0,
        ], $this->db);
        $this->assertSame(404, $guestForumGet['status']);
        $this->assertSame('rest_cannot_view', $guestForumGet['data']['code'] ?? null);
        $this->assertSame(
            AP_Forum_Front::CANNOT_VIEW_MESSAGE,
            $guestForumGet['data']['message'] ?? null
        );
        $this->assertSame([], $this->needlesFound(
            [json_encode($guestForumGet['data'], JSON_UNESCAPED_SLASHES) ?: ''],
            $secretNeedles
        ));

        $guestEmptyCategoryGet = AP_Rest::dispatch([
            'method' => 'GET',
            'route' => '/ap/v1/forums/' . $categoryId,
            'user_id' => 0,
        ], $this->db);
        $this->assertSame(404, $guestEmptyCategoryGet['status']);
        $this->assertSame([], $this->needlesFound(
            [json_encode($guestEmptyCategoryGet['data'], JSON_UNESCAPED_SLASHES) ?: ''],
            $secretNeedles
        ));

        $guestNestedOuterGet = AP_Rest::dispatch([
            'method' => 'GET',
            'route' => '/ap/v1/forums/' . $nestedOuterId,
            'user_id' => 0,
        ], $this->db);
        $this->assertSame(404, $guestNestedOuterGet['status']);
        $this->assertSame([], $this->needlesFound(
            [json_encode($guestNestedOuterGet['data'], JSON_UNESCAPED_SLASHES) ?: ''],
            $secretNeedles
        ));

        $guestNestedInnerGet = AP_Rest::dispatch([
            'method' => 'GET',
            'route' => '/ap/v1/forums/' . $nestedInnerId,
            'user_id' => 0,
        ], $this->db);
        $this->assertSame(404, $guestNestedInnerGet['status']);

        $guestNestedVaultGet = AP_Rest::dispatch([
            'method' => 'GET',
            'route' => '/ap/v1/forums/' . $nestedSecretId,
            'user_id' => 0,
        ], $this->db);
        $this->assertSame(404, $guestNestedVaultGet['status']);
        $this->assertSame([], $this->needlesFound(
            [json_encode($guestNestedVaultGet['data'], JSON_UNESCAPED_SLASHES) ?: ''],
            $secretNeedles
        ));

        $guestTopicGet = AP_Rest::dispatch([
            'method' => 'GET',
            'route' => '/ap/v1/topics/' . $secretTopicId,
            'user_id' => 0,
        ], $this->db);
        $this->assertSame(404, $guestTopicGet['status']);
        $this->assertSame([], $this->needlesFound(
            [json_encode($guestTopicGet['data'], JSON_UNESCAPED_SLASHES) ?: ''],
            $secretNeedles
        ));

        $guestTopics = AP_Rest::dispatch([
            'method' => 'GET',
            'route' => '/ap/v1/topics',
            'user_id' => 0,
        ], $this->db);
        $this->assertSame(200, $guestTopics['status']);
        $this->assertSame([], $this->needlesFound(
            [json_encode($guestTopics['data'], JSON_UNESCAPED_SLASHES) ?: ''],
            $secretNeedles
        ));

        $guestTopicsInSecret = AP_Rest::dispatch([
            'method' => 'GET',
            'route' => '/ap/v1/topics',
            'user_id' => 0,
            'query' => ['forum' => $secretId],
        ], $this->db);
        $this->assertSame(404, $guestTopicsInSecret['status']);
        $this->assertSame([], $this->needlesFound(
            [json_encode($guestTopicsInSecret['data'], JSON_UNESCAPED_SLASHES) ?: ''],
            $secretNeedles
        ));

        $guestTopicsInSecretParams = AP_Rest::dispatch([
            'method' => 'GET',
            'route' => '/ap/v1/topics',
            'user_id' => 0,
            'params' => ['forum' => $secretId],
        ], $this->db);
        $this->assertSame(404, $guestTopicsInSecretParams['status']);

        $outsiderForums = AP_Rest::dispatch([
            'method' => 'GET',
            'route' => '/ap/v1/forums',
            'user_id' => $outsider,
        ], $this->db);
        $this->assertSame(200, $outsiderForums['status']);
        $this->assertSame([], $this->needlesFound(
            [json_encode($outsiderForums['data'], JSON_UNESCAPED_SLASHES) ?: ''],
            $secretNeedles
        ));

        $outsiderForumGet = AP_Rest::dispatch([
            'method' => 'GET',
            'route' => '/ap/v1/forums/' . $secretId,
            'user_id' => $outsider,
        ], $this->db);
        $this->assertSame(404, $outsiderForumGet['status']);

        $outsiderTopicsInSecret = AP_Rest::dispatch([
            'method' => 'GET',
            'route' => '/ap/v1/topics',
            'user_id' => $outsider,
            'query' => ['forum' => $secretId],
        ], $this->db);
        $this->assertSame(404, $outsiderTopicsInSecret['status']);

        $memberForumGet = AP_Rest::dispatch([
            'method' => 'GET',
            'route' => '/ap/v1/forums/' . $secretId,
            'user_id' => $member,
        ], $this->db);
        $this->assertSame(200, $memberForumGet['status']);
        $this->assertSame('ZX9VipCircle', $memberForumGet['data']['name'] ?? null);

        $memberTopicsInSecret = AP_Rest::dispatch([
            'method' => 'GET',
            'route' => '/ap/v1/topics',
            'user_id' => $member,
            'query' => ['forum' => $secretId],
        ], $this->db);
        $this->assertSame(200, $memberTopicsInSecret['status']);
        $this->assertSame([], $this->needlesFound(
            [json_encode($memberTopicsInSecret['data'], JSON_UNESCAPED_SLASHES) ?: ''],
            ['ZX9TownHello']
        ));
        $this->assertNotSame(
            [],
            $this->needlesFound(
                [json_encode($memberTopicsInSecret['data'], JSON_UNESCAPED_SLASHES) ?: ''],
                ['ZX9SecretToken']
            )
        );

        $memberForums = AP_Rest::dispatch([
            'method' => 'GET',
            'route' => '/ap/v1/forums',
            'user_id' => $member,
        ], $this->db);
        $this->assertSame(200, $memberForums['status']);
        $memberForumNames = [];
        foreach (is_array($memberForums['data']) ? $memberForums['data'] : [] as $row) {
            if (is_array($row)) {
                $memberForumNames[] = (string) ($row['name'] ?? '');
            }
        }
        $this->assertContains('ZX9VipCircle', $memberForumNames);
        $this->assertContains('ZX9StaffCellar', $memberForumNames);

        $adminForumGet = AP_Rest::dispatch([
            'method' => 'GET',
            'route' => '/ap/v1/forums/' . $secretId,
            'user_id' => $admin,
        ], $this->db);
        $this->assertSame(200, $adminForumGet['status']);

        $modForumGet = AP_Rest::dispatch([
            'method' => 'GET',
            'route' => '/ap/v1/forums/' . $secretId,
            'user_id' => $moderator,
        ], $this->db);
        $this->assertSame(404, $modForumGet['status']);

        $guestForumArgs = AP_Forum_Front::enrichQueryArgs([
            'ap_forum_view' => 'forum',
            'forum_slug' => $secretSlug,
        ], $this->db);
        $this->assertTrue(!empty($guestForumArgs['ap_forum_cannot_view']));
        $this->assertSame(AP_Forum_Front::CANNOT_VIEW_MESSAGE, $guestForumArgs['ap_forum_cannot_view_message'] ?? null);
        $this->assertSame('', (string) ($guestForumArgs['forum_name'] ?? 'x'));
        $this->assertSame('', (string) ($guestForumArgs['forum_slug'] ?? 'x'));
        $this->assertSame(0, (int) ($guestForumArgs['forum_id'] ?? -1));
        $this->assertTrue(!empty($guestForumArgs['is_404']));
        $this->assertSame([], $this->needlesFound(
            [json_encode($guestForumArgs, JSON_UNESCAPED_SLASHES) ?: ''],
            $secretNeedles
        ));

        $guestForumByIdArgs = AP_Forum_Front::enrichQueryArgs([
            'ap_forum_view' => 'forum',
            'forum_id' => $secretId,
        ], $this->db);
        $this->assertTrue(!empty($guestForumByIdArgs['ap_forum_cannot_view']));
        $this->assertSame('', (string) ($guestForumByIdArgs['forum_name'] ?? 'x'));
        $this->assertSame('', (string) ($guestForumByIdArgs['forum_slug'] ?? 'x'));
        $this->assertSame(0, (int) ($guestForumByIdArgs['forum_id'] ?? -1));

        // Query-string direct URL (index.php applyToQuery): strip stuffed name/slug.
        AP_Session::resetCurrentUser();
        $stuffedDirect = new AP_Query([
            'ap_forum_view' => 'forum',
            'forum_id' => $secretId,
            'forum_slug' => $secretSlug,
            'forum_name' => 'ZX9VipCircle',
            'forum_desc' => 'ZX9SecretBody stays off public surfaces.',
        ], $this->db);
        AP_Forum_Front::applyToQuery($stuffedDirect, $this->db);
        $this->assertTrue(!empty($stuffedDirect->get('ap_forum_cannot_view', false)));
        $this->assertSame(
            AP_Forum_Front::CANNOT_VIEW_MESSAGE,
            (string) $stuffedDirect->get('ap_forum_cannot_view_message', '')
        );
        $this->assertSame('', (string) $stuffedDirect->get('forum_name', 'x'));
        $this->assertSame('', (string) $stuffedDirect->get('forum_slug', 'x'));
        $this->assertSame('', (string) $stuffedDirect->get('forum_desc', 'x'));
        $this->assertSame(0, (int) $stuffedDirect->get('forum_id', -1));
        $this->assertTrue($stuffedDirect->is_404);
        $this->assertSame([], $this->needlesFound(
            [json_encode($stuffedDirect->query_vars, JSON_UNESCAPED_SLASHES) ?: ''],
            $secretNeedles
        ));

        $emptyParentDirect = new AP_Query([
            'ap_forum_view' => 'forum',
            'forum_id' => $categoryId,
            'forum_name' => 'ZX9StaffCellar',
        ], $this->db);
        AP_Forum_Front::applyToQuery($emptyParentDirect, $this->db);
        $this->assertTrue(!empty($emptyParentDirect->get('ap_forum_cannot_view', false)));
        $this->assertSame(
            AP_Forum_Front::CANNOT_VIEW_MESSAGE,
            (string) $emptyParentDirect->get('ap_forum_cannot_view_message', '')
        );
        $this->assertSame('', (string) $emptyParentDirect->get('forum_name', 'x'));
        $this->assertSame(0, (int) $emptyParentDirect->get('forum_id', -1));

        $guestTopicArgs = AP_Forum_Front::enrichQueryArgs([
            'ap_forum_view' => 'topic',
            'topic_slug' => $secretTopicSlug,
        ], $this->db);
        $this->assertTrue(!empty($guestTopicArgs['ap_forum_cannot_view']));
        $this->assertSame('', (string) ($guestTopicArgs['topic_title'] ?? 'x'));
        $this->assertSame('', (string) ($guestTopicArgs['forum_name'] ?? 'x'));
        $this->assertSame([], $this->needlesFound(
            [json_encode($guestTopicArgs, JSON_UNESCAPED_SLASHES) ?: ''],
            $secretNeedles
        ));

        $guestCategoryArgs = AP_Forum_Front::enrichQueryArgs([
            'ap_forum_view' => 'forum',
            'forum_id' => $categoryId,
        ], $this->db);
        $this->assertTrue(!empty($guestCategoryArgs['ap_forum_cannot_view']));
        $this->assertSame('', (string) ($guestCategoryArgs['forum_name'] ?? 'x'));

        $deniedQuery = new AP_Query($guestForumArgs, $this->db);
        $this->assertTrue($deniedQuery->is_404);
        $og = AP_Seo::getOpenGraphMeta($deniedQuery, $this->db);
        $this->assertSame([], $this->needlesFound(
            [json_encode($og, JSON_UNESCAPED_SLASHES) ?: ''],
            $secretNeedles
        ));

        $prettyVars = AP_Rewrite::parseRequest('forums/' . $secretSlug, [], $this->db);
        $prettyArgs = AP_Rewrite::toQueryArgs($prettyVars, $this->db);
        $this->assertTrue(!empty($prettyArgs['ap_forum_cannot_view']));
        $this->assertSame(AP_Forum_Front::CANNOT_VIEW_MESSAGE, $prettyArgs['ap_forum_cannot_view_message'] ?? null);
        $this->assertSame('', (string) ($prettyArgs['forum_name'] ?? 'x'));
        $this->assertSame('', (string) ($prettyArgs['forum_slug'] ?? 'x'));
        $this->assertSame(0, (int) ($prettyArgs['forum_id'] ?? -1));
        $this->assertSame([], $this->needlesFound(
            [json_encode($prettyArgs, JSON_UNESCAPED_SLASHES) ?: ''],
            $secretNeedles
        ));

        $prettyQuery = AP_Rewrite::queryFromVars($prettyVars, $this->db);
        $this->assertTrue($prettyQuery->is_404);
        $this->assertSame('404.php', AP_Theme::getHierarchy($prettyQuery, $this->db)[0] ?? null);

        $prettyTopicVars = AP_Rewrite::parseRequest('topic/' . $secretTopicSlug, [], $this->db);
        $prettyTopicArgs = AP_Rewrite::toQueryArgs($prettyTopicVars, $this->db);
        $this->assertTrue(!empty($prettyTopicArgs['ap_forum_cannot_view']));
        $this->assertSame('', (string) ($prettyTopicArgs['topic_title'] ?? 'x'));
        $this->assertSame([], $this->needlesFound(
            [json_encode($prettyTopicArgs, JSON_UNESCAPED_SLASHES) ?: ''],
            $secretNeedles
        ));

        $prettyCategoryVars = AP_Rewrite::parseRequest(
            'forums/' . (string) AP_Forum::getForum($categoryId, $this->db)?->forum_slug,
            [],
            $this->db
        );
        $prettyCategoryArgs = AP_Rewrite::toQueryArgs($prettyCategoryVars, $this->db);
        $this->assertTrue(!empty($prettyCategoryArgs['ap_forum_cannot_view']));
        $this->assertSame('', (string) ($prettyCategoryArgs['forum_name'] ?? 'x'));
        $this->assertSame([], $this->needlesFound(
            [json_encode($prettyCategoryArgs, JSON_UNESCAPED_SLASHES) ?: ''],
            $secretNeedles
        ));

        $prettyNestedOuter = AP_Rewrite::toQueryArgs(
            AP_Rewrite::parseRequest('forums/' . $nestedOuterSlug, [], $this->db),
            $this->db
        );
        $this->assertTrue(!empty($prettyNestedOuter['ap_forum_cannot_view']));
        $this->assertSame('', (string) ($prettyNestedOuter['forum_name'] ?? 'x'));
        $this->assertSame('', (string) ($prettyNestedOuter['forum_slug'] ?? 'x'));
        $this->assertSame([], $this->needlesFound(
            [json_encode($prettyNestedOuter, JSON_UNESCAPED_SLASHES) ?: ''],
            $secretNeedles
        ));

        $prettyNestedInner = AP_Rewrite::toQueryArgs(
            AP_Rewrite::parseRequest('forums/' . $nestedInnerSlug, [], $this->db),
            $this->db
        );
        $this->assertTrue(!empty($prettyNestedInner['ap_forum_cannot_view']));
        $this->assertSame('', (string) ($prettyNestedInner['forum_name'] ?? 'x'));
        $this->assertSame([], $this->needlesFound(
            [json_encode($prettyNestedInner, JSON_UNESCAPED_SLASHES) ?: ''],
            $secretNeedles
        ));

        $prettyNestedVault = AP_Rewrite::toQueryArgs(
            AP_Rewrite::parseRequest('forums/' . $nestedSecretSlug, [], $this->db),
            $this->db
        );
        $this->assertTrue(!empty($prettyNestedVault['ap_forum_cannot_view']));
        $this->assertSame(
            AP_Forum_Front::CANNOT_VIEW_MESSAGE,
            $prettyNestedVault['ap_forum_cannot_view_message'] ?? null
        );
        $this->assertSame([], $this->needlesFound(
            [json_encode($prettyNestedVault, JSON_UNESCAPED_SLASHES) ?: ''],
            $secretNeedles
        ));

        $guestSearchArgs = AP_Forum_Front::enrichQueryArgs([
            'ap_forum_view' => 'search',
            'forum_s' => 'ZX9SecretToken',
        ], $this->db);
        $this->assertSame(0, (int) ($guestSearchArgs['forum_search_total'] ?? -1));
        $this->assertSame([], $guestSearchArgs['forum_search_results'] ?? ['x']);
        $this->assertSame([], $this->needlesFound(
            [json_encode($guestSearchArgs['forum_search_results'] ?? [], JSON_UNESCAPED_SLASHES) ?: ''],
            $secretNeedles
        ));

        $prettySearchVars = AP_Rewrite::parseRequest('forums/search/ZX9SecretToken', [], $this->db);
        $prettySearchArgs = AP_Rewrite::toQueryArgs($prettySearchVars, $this->db);
        $this->assertSame(0, (int) ($prettySearchArgs['forum_search_total'] ?? -1));
        $this->assertSame([], $prettySearchArgs['forum_search_results'] ?? ['x']);
        $this->assertSame([], $this->needlesFound(
            [json_encode($prettySearchArgs, JSON_UNESCAPED_SLASHES) ?: ''],
            ['ZX9VipCircle', 'ZX9StaffCellar', 'ZX9SecretBody', $secretSlug, $secretTopicSlug]
        ));

        $guestHelperSearch = ap_forum_search('ZX9SecretToken', [
            'type' => 'all',
        ], $this->db);
        $this->assertSame(0, (int) ($guestHelperSearch['total'] ?? -1));
        $this->assertSame([], $guestHelperSearch['results'] ?? ['x']);
        $this->assertSame([], $guestHelperSearch['topics'] ?? ['x']);
        $this->assertSame([], $guestHelperSearch['posts'] ?? ['x']);

        $this->assertSame([], AP_Forum::getTopicsDisplayData($secretId, ['user_id' => 0], $this->db));
        $this->assertSame([], AP_Forum::getTopicsDisplayData($secretId, ['user_id' => $outsider], $this->db));
        $this->assertSame([], AP_Forum::getTopicsDisplayData($secretId, ['user_id' => $moderator], $this->db));
        $memberTopics = AP_Forum::getTopicsDisplayData($secretId, ['user_id' => $member], $this->db);
        $this->assertNotSame([], $this->needlesFound(
            [json_encode($memberTopics, JSON_UNESCAPED_SLASHES) ?: ''],
            ['ZX9SecretToken']
        ));
        $this->assertSame([], AP_Forum::getPostsDisplayData($secretTopicId, ['user_id' => 0], $this->db));
        $this->assertNotSame([], AP_Forum::getPostsDisplayData($secretTopicId, ['user_id' => $member], $this->db));
        $this->assertSame([], ap_get_forum_topics_data($secretId, ['user_id' => 0], $this->db));
        $this->assertSame([], ap_get_topic_posts_data($secretTopicId, ['user_id' => 0], $this->db));

        $scopedSearchArgs = AP_Forum_Front::enrichQueryArgs([
            'ap_forum_view' => 'search',
            'forum_s' => 'ZX9TownHello',
            'forum_id' => $secretId,
            'forum_slug' => $secretSlug,
        ], $this->db);
        $this->assertTrue(!empty($scopedSearchArgs['ap_forum_cannot_view']));
        $this->assertSame(
            AP_Forum_Front::CANNOT_VIEW_MESSAGE,
            $scopedSearchArgs['ap_forum_cannot_view_message'] ?? null
        );
        $this->assertSame(0, (int) ($scopedSearchArgs['forum_id'] ?? -1));
        $this->assertSame('', (string) ($scopedSearchArgs['forum_slug'] ?? 'x'));
        $this->assertSame('', (string) ($scopedSearchArgs['forum_name'] ?? 'x'));
        $this->assertSame(0, (int) ($scopedSearchArgs['forum_search_total'] ?? -1));
        $this->assertSame([], $this->needlesFound(
            [json_encode($scopedSearchArgs, JSON_UNESCAPED_SLASHES) ?: ''],
            $secretNeedles
        ));

        $guestTree = AP_Forum::getHierarchy(0, ['user_id' => 0], $this->db);
        $this->assertSame([], $this->needlesFound($this->hierarchyText($guestTree), $secretNeedles));
        $memberTree = AP_Forum::getHierarchy(0, ['user_id' => $member], $this->db);
        $this->assertNotSame(
            [],
            $this->needlesFound($this->hierarchyText($memberTree), ['ZX9VipCircle', 'ZX9StaffCellar'])
        );
        $this->assertNotSame(
            [],
            $this->needlesFound(
                $this->hierarchyText($memberTree),
                ['ZX9NestedOuter', 'ZX9NestedInner', 'ZX9NestedVault']
            )
        );

        $tree = AP_Forum::getHierarchy(0, ['include_hidden' => true], $this->db);
        $this->assertNotSame([], $this->needlesFound($this->hierarchyText($tree), ['ZX9VipCircle', 'ZX9StaffCellar']));

        $acpTable = new AP_Forums_List_Table($this->db);
        $acpTable->prepareItems([]);
        $acpNames = [];
        foreach ($acpTable->items as $item) {
            $acpNames[] = (string) ($item->forum_name ?? '');
        }
        $this->assertContains('ZX9VipCircle', $acpNames);
        $this->assertContains('ZX9StaffCellar', $acpNames);
        $this->assertContains('ZX9NestedVault', $acpNames);
        $this->assertContains('ZX9TownSquare', $acpNames);

        $this->assertFalse(ap_forum_is_listable_to_user(0, $secretId, $this->db));
        $this->assertFalse(ap_forum_is_listable_to_user($outsider, $secretId, $this->db));
        $this->assertTrue(ap_forum_is_listable_to_user($member, $secretId, $this->db));
        $this->assertTrue(ap_forum_is_listable_to_user($admin, $secretId, $this->db));
        $this->assertSame(
            [],
            $this->needlesFound(
                $this->forumObjectsText(ap_get_listable_forums(0, [], $this->db)),
                $secretNeedles
            )
        );

        $mixedCatId = AP_Forum::insertForum([
            'forum_name' => 'ZX9MixedHall',
            'forum_type' => AP_Forum::FORUM_TYPE_CATEGORY,
        ], $this->db);
        $mixedPublicId = AP_Forum::insertForum([
            'forum_name' => 'ZX9MixedSquare',
            'forum_type' => AP_Forum::FORUM_TYPE_FORUM,
            'parent_id' => $mixedCatId,
        ], $this->db);
        $mixedSecretId = AP_Forum::insertForum([
            'forum_name' => 'ZX9MixedVault',
            'forum_type' => AP_Forum::FORUM_TYPE_FORUM,
            'parent_id' => $mixedCatId,
        ], $this->db);
        $this->assertGreaterThan(0, $mixedCatId);
        $this->assertGreaterThan(0, $mixedPublicId);
        $this->assertGreaterThan(0, $mixedSecretId);
        $this->assertTrue(AP_Forum_Permissions::saveAccessFromForm($mixedSecretId, [
            'forum_access_level' => 'group_only',
            'forum_access_groups' => [$vip],
        ], $this->db));

        $guestMixedIndex = $this->indexVisibleText(AP_Forum::getIndexData($this->db, ['user_id' => 0]));
        $this->assertContains('ZX9MixedHall', $guestMixedIndex);
        $this->assertContains('ZX9MixedSquare', $guestMixedIndex);
        $this->assertSame([], $this->needlesFound($guestMixedIndex, ['ZX9MixedVault']));

        $memberMixedIndex = $this->indexVisibleText(AP_Forum::getIndexData($this->db, ['user_id' => $member]));
        $this->assertContains('ZX9MixedVault', $memberMixedIndex);

        $guestMixedList = $this->forumObjectsText(AP_Forum::getListableForums(0, [], $this->db));
        $this->assertContains('ZX9MixedHall', $guestMixedList);
        $this->assertContains('ZX9MixedSquare', $guestMixedList);
        $this->assertSame([], $this->needlesFound($guestMixedList, ['ZX9MixedVault']));

        $guestMixedRest = AP_Rest::dispatch([
            'method' => 'GET',
            'route' => '/ap/v1/forums',
            'user_id' => 0,
        ], $this->db);
        $this->assertSame(200, $guestMixedRest['status']);
        $guestMixedJson = json_encode($guestMixedRest['data'], JSON_UNESCAPED_SLASHES) ?: '';
        $this->assertStringContainsString('ZX9MixedSquare', $guestMixedJson);
        $this->assertStringNotContainsString('ZX9MixedVault', $guestMixedJson);

        $guestMixedGet = AP_Rest::dispatch([
            'method' => 'GET',
            'route' => '/ap/v1/forums/' . $mixedSecretId,
            'user_id' => 0,
        ], $this->db);
        $this->assertSame(404, $guestMixedGet['status']);
        $this->assertStringNotContainsString(
            'ZX9MixedVault',
            json_encode($guestMixedGet['data'], JSON_UNESCAPED_SLASHES) ?: ''
        );

        AP_Nav_Menu::saveMenu('zx9-hygiene', 'Hygiene', [
            ['type' => 'forum', 'title' => '', 'object_id' => $secretId],
            ['type' => 'forum', 'title' => 'ZX9StaffCellar', 'object_id' => $categoryId],
            ['type' => 'forum', 'title' => '', 'object_id' => $publicId],
            ['type' => 'forum', 'title' => '', 'object_id' => $mixedCatId],
            ['type' => 'forum', 'title' => '', 'object_id' => $mixedSecretId],
            ['type' => 'custom', 'title' => 'Home', 'url' => 'https://example.test/'],
        ], $this->db);
        $this->assertFalse(AP_Nav_Menu::isItemVisible([
            'type' => 'forum',
            'object_id' => $secretId,
        ], $this->db));
        $this->assertFalse(AP_Nav_Menu::isItemVisible([
            'type' => 'forum',
            'object_id' => $categoryId,
        ], $this->db));
        $this->assertTrue(AP_Nav_Menu::isItemVisible([
            'type' => 'forum',
            'object_id' => $publicId,
        ], $this->db));
        $this->assertTrue(AP_Nav_Menu::isItemVisible([
            'type' => 'forum',
            'object_id' => $mixedCatId,
        ], $this->db));
        $this->assertFalse(AP_Nav_Menu::isItemVisible([
            'type' => 'forum',
            'object_id' => $mixedSecretId,
        ], $this->db));
        $guestMenu = AP_Nav_Menu::render([
            'menu' => 'zx9-hygiene',
            'echo' => false,
        ], $this->db);
        $this->assertStringContainsString('ZX9TownSquare', $guestMenu);
        $this->assertStringContainsString('ZX9MixedHall', $guestMenu);
        $this->assertSame([], $this->needlesFound([$guestMenu], $secretNeedles));
        $this->assertStringNotContainsString('ZX9MixedVault', $guestMenu);

        AP_Session::resetCurrentUser();
        $this->assertTrue(AP_Session::setAuthCookie($member, false, $this->db));
        $this->assertTrue(AP_Nav_Menu::isItemVisible([
            'type' => 'forum',
            'object_id' => $secretId,
        ], $this->db));
        $memberMenu = AP_Nav_Menu::render([
            'menu' => 'zx9-hygiene',
            'echo' => false,
        ], $this->db);
        $this->assertStringContainsString('ZX9VipCircle', $memberMenu);
        $this->assertStringContainsString('ZX9StaffCellar', $memberMenu);
        AP_Session::clearAuthCookie();
        AP_Session::resetCurrentUser();

        AP_Options::update('stylesheet', 'agora', $this->db);
        AP_Options::update('template', 'agora', $this->db);
        AP_Theme::reset();
        $GLOBALS['ap_query'] = $deniedQuery;
        ob_start();
        AP_Theme::render($deniedQuery, $this->db);
        $deniedHtml = (string) ob_get_clean();
        $this->assertStringContainsString(AP_Forum_Front::CANNOT_VIEW_MESSAGE, $deniedHtml);
        $this->assertSame([], $this->needlesFound([$deniedHtml], $secretNeedles));
        $this->assertStringNotContainsString('forum-view', $deniedHtml);

        // Board index HTML: hide the room and an empty parent; keep mixed public rows.
        AP_Session::resetCurrentUser();
        $indexQuery = new AP_Query([
            'ap_forum_view' => 'index',
            'ap_forum' => '1',
        ], $this->db);
        AP_Forum_Front::applyToQuery($indexQuery, $this->db);
        $GLOBALS['ap_query'] = $indexQuery;
        ob_start();
        AP_Theme::render($indexQuery, $this->db);
        $guestIndexHtml = (string) ob_get_clean();
        $this->assertStringContainsString('ap-forum--index', $guestIndexHtml);
        $this->assertStringContainsString('ZX9TownSquare', $guestIndexHtml);
        $this->assertStringContainsString('ZX9MixedHall', $guestIndexHtml);
        $this->assertStringContainsString('ZX9MixedSquare', $guestIndexHtml);
        $this->assertSame([], $this->needlesFound([$guestIndexHtml], $secretNeedles));
        $this->assertStringNotContainsString('ZX9MixedVault', $guestIndexHtml);
        $this->assertStringNotContainsString(AP_Forum_Front::CANNOT_VIEW_MESSAGE, $guestIndexHtml);

        $this->assertTrue(AP_Session::setAuthCookie($member, false, $this->db));
        $memberIndexQuery = new AP_Query([
            'ap_forum_view' => 'index',
            'ap_forum' => '1',
        ], $this->db);
        AP_Forum_Front::applyToQuery($memberIndexQuery, $this->db);
        $GLOBALS['ap_query'] = $memberIndexQuery;
        ob_start();
        AP_Theme::render($memberIndexQuery, $this->db);
        $memberIndexHtml = (string) ob_get_clean();
        $this->assertStringContainsString('ZX9VipCircle', $memberIndexHtml);
        $this->assertStringContainsString('ZX9StaffCellar', $memberIndexHtml);
        $this->assertStringContainsString('ZX9MixedVault', $memberIndexHtml);
        AP_Session::clearAuthCookie();
        AP_Session::resetCurrentUser();

        unset($GLOBALS['ap_query']);
        AP_Theme::reset();

        AP_Session::resetCurrentUser();
        $this->assertTrue(AP_Session::setAuthCookie($outsider, false, $this->db));
        $outsiderPretty = AP_Rewrite::toQueryArgs(
            AP_Rewrite::parseRequest('forums/' . $secretSlug, [], $this->db),
            $this->db
        );
        $this->assertTrue(!empty($outsiderPretty['ap_forum_cannot_view']));
        $this->assertSame('', (string) ($outsiderPretty['forum_name'] ?? 'x'));
        $this->assertSame([], $this->needlesFound(
            [json_encode($outsiderPretty, JSON_UNESCAPED_SLASHES) ?: ''],
            $secretNeedles
        ));
        AP_Session::clearAuthCookie();
        AP_Session::resetCurrentUser();

        $this->assertTrue(AP_Session::setAuthCookie($moderator, false, $this->db));
        $modPretty = AP_Rewrite::toQueryArgs(
            AP_Rewrite::parseRequest('topic/' . $secretTopicSlug, [], $this->db),
            $this->db
        );
        $this->assertTrue(!empty($modPretty['ap_forum_cannot_view']));
        $this->assertSame('', (string) ($modPretty['topic_title'] ?? 'x'));
        $this->assertSame([], $this->needlesFound(
            [json_encode($modPretty, JSON_UNESCAPED_SLASHES) ?: ''],
            $secretNeedles
        ));
        AP_Session::clearAuthCookie();
        AP_Session::resetCurrentUser();

        $this->assertTrue(AP_Session::setAuthCookie($member, false, $this->db));
        $memberPretty = AP_Rewrite::toQueryArgs(
            AP_Rewrite::parseRequest('forums/' . $secretSlug, [], $this->db),
            $this->db
        );
        $this->assertTrue(empty($memberPretty['ap_forum_cannot_view']));
        $this->assertSame('ZX9VipCircle', $memberPretty['forum_name'] ?? null);
        AP_Session::clearAuthCookie();
        AP_Session::resetCurrentUser();

        unset($GLOBALS['apdb']);
        AP_Rest::reset();
        AP_Rewrite::resetCache();
        AP_Seo::reset();
        AP_Nav_Menu::reset();
    }

    /**
     * Guest and a registered non-member cannot list or open a group-only
     * board; a group member can. A site-wide moderator who is not in the
     * group cannot. An administrator can. The empty parent category is
     * absent from outsider indexes. Search, RSS, sitemap, and REST omit
     * the room. ACP still lists and edits it.
     */
    public function testThisGroupOnlyGuestAndNonMemberCannotListOrOpenBoard(): void
    {
        require_once $this->root . '/ap-includes/hooks.php';
        require_once $this->root . '/ap-includes/class-ap-query.php';
        require_once $this->root . '/ap-includes/class-ap-rewrite.php';
        require_once $this->root . '/ap-includes/class-ap-session.php';
        require_once $this->root . '/ap-includes/class-ap-forum-front.php';
        require_once $this->root . '/ap-includes/class-ap-rest.php';
        require_once $this->root . '/ap-includes/class-ap-sitemap.php';
        require_once $this->root . '/ap-includes/class-ap-feed.php';
        require_once $this->root . '/ap-includes/class-ap-nonce.php';
        require_once $this->root . '/ap-admin/includes/class-ap-admin.php';
        require_once $this->root . '/ap-admin/includes/class-ap-forums-list-table.php';
        require_once $this->root . '/ap-admin/includes/class-ap-admin-forum-edit.php';

        foreach (
            [
                'AP_LOGGED_IN_KEY' => 'zx9-cap-logged-in-key',
                'AP_LOGGED_IN_SALT' => 'zx9-cap-logged-in-salt',
                'AP_AUTH_KEY' => 'zx9-cap-auth-key',
                'AP_AUTH_SALT' => 'zx9-cap-auth-salt',
                'AP_NONCE_KEY' => 'zx9-cap-nonce-key',
                'AP_NONCE_SALT' => 'zx9-cap-nonce-salt',
            ] as $const => $value
        ) {
            if (!defined($const)) {
                define($const, $value);
            }
        }

        if (function_exists('ap_reset_hooks')) {
            ap_reset_hooks();
        }
        AP_Rewrite::resetCache();
        AP_Rest::reset();
        AP_Session::clearAuthCookie();
        AP_Session::resetCurrentUser();

        $GLOBALS['apdb'] = $this->db;
        AP_Options::update('home', 'https://example.test', $this->db);
        AP_Options::update('siteurl', 'https://example.test', $this->db);
        AP_Options::update('permalink_structure', '/%postname%/', $this->db);
        AP_Options::update('blogname', 'Capstone Hygiene', $this->db);
        AP_Options::update('ap_module_forum', '1', $this->db);
        AP_Options::update('ap_module_blog', '1', $this->db);
        AP_Options::update('rest_api_enabled', '1', $this->db);
        AP_Options::update('sitemap_enabled', '1', $this->db);
        AP_Options::flushCache();
        AP_Rewrite::resetCache();

        AP_Forum_Permissions::ensureDefaults($this->db);
        AP_Group::ensureSystemGroups($this->db);

        $vip = AP_Group::create([
            'group_name' => 'ZX9 Capstone Circle',
            'group_type' => AP_Group::TYPE_CLOSED,
        ], $this->db);
        $this->assertGreaterThan(0, $vip);

        $categoryId = AP_Forum::insertForum([
            'forum_name' => 'ZX9CapstoneCellar',
            'forum_type' => AP_Forum::FORUM_TYPE_CATEGORY,
        ], $this->db);
        $secretId = AP_Forum::insertForum([
            'forum_name' => 'ZX9CapstoneVault',
            'forum_type' => AP_Forum::FORUM_TYPE_FORUM,
            'parent_id' => $categoryId,
        ], $this->db);
        $publicId = AP_Forum::insertForum([
            'forum_name' => 'ZX9CapstoneSquare',
            'forum_type' => AP_Forum::FORUM_TYPE_FORUM,
        ], $this->db);
        $this->assertGreaterThan(0, $categoryId);
        $this->assertGreaterThan(0, $secretId);
        $this->assertGreaterThan(0, $publicId);

        $this->assertTrue(AP_Forum_Permissions::saveAccessFromForm($secretId, [
            'forum_access_level' => 'group_only',
            'forum_access_groups' => [$vip],
        ], $this->db));

        $outsider = $this->createUser('zx9_cap_out', 'zx9_cap_out@example.test', 'subscriber');
        $member = $this->createUser('zx9_cap_vip', 'zx9_cap_vip@example.test', 'subscriber');
        $moderator = $this->createUser('zx9_cap_mod', 'zx9_cap_mod@example.test', 'editor');
        $admin = $this->createUser('zx9_cap_admin', 'zx9_cap_admin@example.test', 'administrator');
        AP_Group::addMember($vip, $member, AP_Group::ROLE_MEMBER, $this->db);
        $this->assertTrue(AP_Roles::userCan($moderator, 'moderate_forums', null, $this->db));
        $this->assertFalse(AP_Roles::userCan($moderator, 'manage_forums', null, $this->db));

        $secretTopicId = AP_Forum::createTopic([
            'forum_id' => $secretId,
            'topic_title' => 'ZX9CapstoneSecretToken thread',
            'content' => 'ZX9CapstoneSecretBody stays off public surfaces.',
            'poster_id' => $member,
        ], $this->db);
        $publicTopicId = AP_Forum::createTopic([
            'forum_id' => $publicId,
            'topic_title' => 'ZX9CapstoneTownHello thread',
            'content' => 'Public square chatter.',
            'poster_id' => $outsider,
        ], $this->db);
        $this->assertGreaterThan(0, $secretTopicId);
        $this->assertGreaterThan(0, $publicTopicId);

        $secretForum = AP_Forum::getForum($secretId, $this->db);
        $this->assertNotNull($secretForum);
        $secretSlug = (string) $secretForum->forum_slug;
        $this->assertNotSame('', $secretSlug);

        $secretNeedles = [
            'ZX9CapstoneCellar',
            'ZX9CapstoneVault',
            'ZX9CapstoneSecretToken',
            'ZX9CapstoneSecretBody',
            $secretSlug,
        ];

        // List: guest, registered non-member, and a site-wide moderator
        // who is not in the group cannot see the board. Member and admin can.
        $this->assertFalse(AP_Forum::isListableToUser(0, $secretId, $this->db));
        $this->assertFalse(AP_Forum::isListableToUser($outsider, $secretId, $this->db));
        $this->assertFalse(AP_Forum::isListableToUser($moderator, $secretId, $this->db));
        $this->assertTrue(AP_Forum::isListableToUser($member, $secretId, $this->db));
        $this->assertTrue(AP_Forum::isListableToUser($admin, $secretId, $this->db));

        $guestIndex = $this->indexVisibleText(AP_Forum::getIndexData($this->db, ['user_id' => 0]));
        $this->assertContains('ZX9CapstoneSquare', $guestIndex);
        $this->assertSame([], $this->needlesFound($guestIndex, $secretNeedles));

        $outsiderIndex = $this->indexVisibleText(
            AP_Forum::getIndexData($this->db, ['user_id' => $outsider])
        );
        $this->assertContains('ZX9CapstoneSquare', $outsiderIndex);
        $this->assertSame([], $this->needlesFound($outsiderIndex, $secretNeedles));

        $moderatorIndex = $this->indexVisibleText(
            AP_Forum::getIndexData($this->db, ['user_id' => $moderator])
        );
        $this->assertContains('ZX9CapstoneSquare', $moderatorIndex);
        $this->assertSame([], $this->needlesFound($moderatorIndex, $secretNeedles));

        $memberIndex = $this->indexVisibleText(
            AP_Forum::getIndexData($this->db, ['user_id' => $member])
        );
        $this->assertContains('ZX9CapstoneCellar', $memberIndex);
        $this->assertContains('ZX9CapstoneVault', $memberIndex);

        $adminIndex = $this->indexVisibleText(
            AP_Forum::getIndexData($this->db, ['user_id' => $admin])
        );
        $this->assertContains('ZX9CapstoneVault', $adminIndex);

        // Open: guest, non-member, and outsider moderator get a generic
        // denial; member and administrator see the room.
        $guestOpen = $this->forumViewArgsForUser(0, $secretSlug);
        $this->assertTrue(!empty($guestOpen['ap_forum_cannot_view']));
        $this->assertSame(AP_Forum_Front::CANNOT_VIEW_MESSAGE, $guestOpen['ap_forum_cannot_view_message'] ?? null);
        $this->assertSame('', (string) ($guestOpen['forum_name'] ?? 'x'));
        $this->assertSame('', (string) ($guestOpen['forum_slug'] ?? 'x'));
        $this->assertSame(0, (int) ($guestOpen['forum_id'] ?? -1));
        $this->assertSame([], $this->needlesFound(
            [json_encode($guestOpen, JSON_UNESCAPED_SLASHES) ?: ''],
            $secretNeedles
        ));

        $outsiderOpen = $this->forumViewArgsForUser($outsider, $secretSlug);
        $this->assertTrue(!empty($outsiderOpen['ap_forum_cannot_view']));
        $this->assertSame('', (string) ($outsiderOpen['forum_name'] ?? 'x'));
        $this->assertSame([], $this->needlesFound(
            [json_encode($outsiderOpen, JSON_UNESCAPED_SLASHES) ?: ''],
            $secretNeedles
        ));

        $moderatorOpen = $this->forumViewArgsForUser($moderator, $secretSlug);
        $this->assertTrue(!empty($moderatorOpen['ap_forum_cannot_view']));
        $this->assertSame('', (string) ($moderatorOpen['forum_name'] ?? 'x'));
        $this->assertSame([], $this->needlesFound(
            [json_encode($moderatorOpen, JSON_UNESCAPED_SLASHES) ?: ''],
            $secretNeedles
        ));

        $memberOpen = $this->forumViewArgsForUser($member, $secretSlug);
        $this->assertTrue(empty($memberOpen['ap_forum_cannot_view']));
        $this->assertSame('ZX9CapstoneVault', $memberOpen['forum_name'] ?? null);
        $this->assertSame($secretId, (int) ($memberOpen['forum_id'] ?? 0));

        $adminOpen = $this->forumViewArgsForUser($admin, $secretSlug);
        $this->assertTrue(empty($adminOpen['ap_forum_cannot_view']));
        $this->assertSame('ZX9CapstoneVault', $adminOpen['forum_name'] ?? null);

        $this->assertSame([], AP_Forum::getTopicsDisplayData($secretId, ['user_id' => 0], $this->db));
        $this->assertSame([], AP_Forum::getTopicsDisplayData($secretId, ['user_id' => $outsider], $this->db));
        $this->assertSame([], AP_Forum::getTopicsDisplayData($secretId, ['user_id' => $moderator], $this->db));
        $memberTopics = AP_Forum::getTopicsDisplayData($secretId, ['user_id' => $member], $this->db);
        $this->assertNotSame([], $this->needlesFound(
            [json_encode($memberTopics, JSON_UNESCAPED_SLASHES) ?: ''],
            ['ZX9CapstoneSecretToken']
        ));

        $guestSearch = AP_Forum::search('ZX9CapstoneSecretToken', [
            'type' => 'all',
            'check_permissions' => true,
            'user_id' => 0,
        ], $this->db);
        $this->assertSame(0, (int) ($guestSearch['total'] ?? -1));
        $this->assertSame([], $this->needlesFound($this->searchPayloadText($guestSearch), $secretNeedles));

        $outsiderSearch = AP_Forum::search('ZX9CapstoneSecretToken', [
            'type' => 'all',
            'check_permissions' => true,
            'user_id' => $outsider,
        ], $this->db);
        $this->assertSame(0, (int) ($outsiderSearch['total'] ?? -1));
        $this->assertSame([], $this->needlesFound($this->searchPayloadText($outsiderSearch), $secretNeedles));

        $moderatorSearch = AP_Forum::search('ZX9CapstoneSecretToken', [
            'type' => 'all',
            'check_permissions' => true,
            'user_id' => $moderator,
        ], $this->db);
        $this->assertSame(0, (int) ($moderatorSearch['total'] ?? -1));
        $this->assertSame([], $this->needlesFound($this->searchPayloadText($moderatorSearch), $secretNeedles));

        $memberSearch = AP_Forum::search('ZX9CapstoneSecretToken', [
            'type' => 'all',
            'check_permissions' => true,
            'user_id' => $member,
        ], $this->db);
        $this->assertNotSame([], $this->needlesFound(
            $this->searchPayloadText($memberSearch),
            ['ZX9CapstoneSecretToken']
        ));

        $adminSearch = AP_Forum::search('ZX9CapstoneSecretToken', [
            'type' => 'all',
            'check_permissions' => true,
            'user_id' => $admin,
        ], $this->db);
        $this->assertNotSame([], $this->needlesFound(
            $this->searchPayloadText($adminSearch),
            ['ZX9CapstoneSecretToken']
        ));

        // RSS / sitemap / REST omit the room for anyone who cannot view it.
        $guestFeedTopics = AP_Forum::getFeedTopics(['user_id' => 0], $this->db);
        $this->assertSame([], $this->needlesFound(
            [json_encode($guestFeedTopics, JSON_UNESCAPED_SLASHES) ?: ''],
            $secretNeedles
        ));
        $moderatorFeedTopics = AP_Forum::getFeedTopics(['user_id' => $moderator], $this->db);
        $this->assertSame([], $this->needlesFound(
            [json_encode($moderatorFeedTopics, JSON_UNESCAPED_SLASHES) ?: ''],
            $secretNeedles
        ));
        $guestRss = $this->captureFeedServe(
            AP_Rewrite::parseRequest('forums/feed', [], $this->db)
        );
        $this->assertStringContainsString('<rss', $guestRss);
        $this->assertStringContainsString('ZX9CapstoneTownHello', $guestRss);
        $this->assertSame([], $this->needlesFound([$guestRss], $secretNeedles));

        $forumSitemap = AP_Sitemap::buildProvider('forums', 1, $this->db);
        $topicSitemap = AP_Sitemap::buildProvider('topics', 1, $this->db);
        $this->assertSame([], $this->needlesFound([$forumSitemap, $topicSitemap], $secretNeedles));
        $publicForum = AP_Forum::getForum($publicId, $this->db);
        $this->assertNotNull($publicForum);
        $this->assertStringContainsString((string) $publicForum->forum_slug, $forumSitemap);

        $guestRestList = AP_Rest::dispatch([
            'method' => 'GET',
            'route' => '/ap/v1/forums',
            'user_id' => 0,
        ], $this->db);
        $this->assertSame(200, $guestRestList['status']);
        $guestRestJson = json_encode($guestRestList['data'], JSON_UNESCAPED_SLASHES) ?: '';
        $this->assertStringContainsString('ZX9CapstoneSquare', $guestRestJson);
        $this->assertSame([], $this->needlesFound([$guestRestJson], $secretNeedles));

        $guestRestGet = AP_Rest::dispatch([
            'method' => 'GET',
            'route' => '/ap/v1/forums/' . $secretId,
            'user_id' => 0,
        ], $this->db);
        $this->assertSame(404, $guestRestGet['status']);
        $this->assertSame([], $this->needlesFound(
            [json_encode($guestRestGet['data'], JSON_UNESCAPED_SLASHES) ?: ''],
            $secretNeedles
        ));

        $outsiderRestGet = AP_Rest::dispatch([
            'method' => 'GET',
            'route' => '/ap/v1/forums/' . $secretId,
            'user_id' => $outsider,
        ], $this->db);
        $this->assertSame(404, $outsiderRestGet['status']);

        $moderatorRestGet = AP_Rest::dispatch([
            'method' => 'GET',
            'route' => '/ap/v1/forums/' . $secretId,
            'user_id' => $moderator,
        ], $this->db);
        $this->assertSame(404, $moderatorRestGet['status']);
        $this->assertSame([], $this->needlesFound(
            [json_encode($moderatorRestGet['data'], JSON_UNESCAPED_SLASHES) ?: ''],
            $secretNeedles
        ));

        $memberRestGet = AP_Rest::dispatch([
            'method' => 'GET',
            'route' => '/ap/v1/forums/' . $secretId,
            'user_id' => $member,
        ], $this->db);
        $this->assertSame(200, $memberRestGet['status']);
        $this->assertSame('ZX9CapstoneVault', $memberRestGet['data']['name'] ?? null);

        $adminRestGet = AP_Rest::dispatch([
            'method' => 'GET',
            'route' => '/ap/v1/forums/' . $secretId,
            'user_id' => $admin,
        ], $this->db);
        $this->assertSame(200, $adminRestGet['status']);
        $this->assertSame('ZX9CapstoneVault', $adminRestGet['data']['name'] ?? null);

        // Admin still sees the group-only room in ACP (list + edit).
        $acpTable = new AP_Forums_List_Table($this->db);
        $acpTable->prepareItems([]);
        $acpNames = [];
        foreach ($acpTable->items as $item) {
            $acpNames[] = (string) ($item->forum_name ?? '');
        }
        $this->assertContains('ZX9CapstoneVault', $acpNames);
        $this->assertContains('ZX9CapstoneCellar', $acpNames);
        $this->assertContains('ZX9CapstoneSquare', $acpNames);

        $acpHtml = $acpTable->render();
        $this->assertStringContainsString('ZX9CapstoneVault', $acpHtml);
        $this->assertStringContainsString('ZX9CapstoneCellar', $acpHtml);
        $this->assertStringContainsString('forum-edit.php', $acpHtml);

        $acpTable->prepareItems(['s' => 'ZX9CapstoneVault']);
        $searchNames = [];
        foreach ($acpTable->items as $item) {
            $searchNames[] = (string) ($item->forum_name ?? '');
        }
        $this->assertContains('ZX9CapstoneVault', $searchNames);

        $editForm = AP_Admin_Forum_Edit::renderForm($secretForum, $admin, $this->db);
        $this->assertStringContainsString('ZX9CapstoneVault', $editForm);
        $this->assertStringContainsString('value="group_only" selected', $editForm);

        unset($GLOBALS['apdb']);
        AP_Rest::reset();
        AP_Rewrite::resetCache();
        AP_Session::clearAuthCookie();
        AP_Session::resetCurrentUser();
    }

    /**
     * Direct forum URL args for a given viewer (0 = guest).
     *
     * @return array<string, mixed>
     */
    private function forumViewArgsForUser(int $userId, string $forumSlug): array
    {
        AP_Session::resetCurrentUser();
        AP_Session::clearAuthCookie();
        if ($userId > 0) {
            $this->assertTrue(AP_Session::setAuthCookie($userId, false, $this->db));
        }
        $args = AP_Forum_Front::enrichQueryArgs([
            'ap_forum_view' => 'forum',
            'forum_slug' => $forumSlug,
        ], $this->db);
        AP_Session::clearAuthCookie();
        AP_Session::resetCurrentUser();

        return $args;
    }

    /**
     * @param list<array{name?: string, forums?: list<array<string, mixed>>}> $index
     *
     * @return list<string>
     */
    private function indexVisibleText(array $index): array
    {
        $out = [];
        foreach ($index as $category) {
            $out[] = (string) ($category['name'] ?? '');
            $forums = is_array($category['forums'] ?? null) ? $category['forums'] : [];
            foreach ($forums as $forum) {
                if (!is_array($forum)) {
                    continue;
                }
                $out[] = (string) ($forum['name'] ?? '');
                $out[] = (string) ($forum['slug'] ?? '');
                $out[] = (string) ($forum['url'] ?? '');
                $out[] = (string) ($forum['description'] ?? '');
                $last = is_array($forum['last_post'] ?? null) ? $forum['last_post'] : [];
                $out[] = (string) ($last['title'] ?? '');
                $out[] = (string) ($last['url'] ?? '');
            }
        }

        return $out;
    }

    /**
     * @param list<object> $forums
     *
     * @return list<string>
     */
    private function forumObjectsText(array $forums): array
    {
        $out = [];
        foreach ($forums as $forum) {
            $out[] = (string) ($forum->forum_name ?? '');
            $out[] = (string) ($forum->forum_slug ?? '');
        }

        return $out;
    }

    /**
     * @param list<array{forum: object, children: list}> $tree
     *
     * @return list<string>
     */
    private function hierarchyText(array $tree): array
    {
        $out = [];
        foreach ($tree as $node) {
            $forum = $node['forum'] ?? null;
            if (is_object($forum)) {
                $out[] = (string) ($forum->forum_name ?? '');
            }
            if (!empty($node['children']) && is_array($node['children'])) {
                $out = array_merge($out, $this->hierarchyText($node['children']));
            }
        }

        return $out;
    }

    /**
     * Flatten a search() / front-search payload for leak checks.
     *
     * Omits the echoed query, search URL, and forum_s / s — the viewer typed
     * those. Hidden board names still appear here if results or teasers leak.
     *
     * @param array<string, mixed> $payload
     *
     * @return list<string>
     */
    private function searchPayloadText(array $payload): array
    {
        $slice = [
            'total' => $payload['total'] ?? $payload['forum_search_total'] ?? null,
            'results' => $payload['results'] ?? $payload['forum_search_results'] ?? null,
            'topics' => $payload['topics'] ?? null,
            'posts' => $payload['posts'] ?? null,
            'forum_id' => $payload['forum_id'] ?? null,
            'forum_name' => $payload['forum_name'] ?? null,
            'forum_slug' => $payload['forum_slug'] ?? null,
            'forum_desc' => $payload['forum_desc'] ?? null,
            'forum_status' => $payload['forum_status'] ?? null,
            'topic_id' => $payload['topic_id'] ?? null,
            'topic_title' => $payload['topic_title'] ?? null,
            'topic_slug' => $payload['topic_slug'] ?? null,
            'ap_forum_obj' => $payload['ap_forum_obj'] ?? null,
            'ap_topic' => $payload['ap_topic'] ?? null,
        ];

        return [json_encode($slice, JSON_UNESCAPED_SLASHES) ?: ''];
    }

    /**
     * @param array<string, mixed> $vars
     */
    private function captureFeedServe(array $vars): string
    {
        ob_start();
        try {
            $body = AP_Feed::serve($vars, $this->db, false);
            $echoed = (string) ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        $this->assertSame($body, $echoed);

        return $body;
    }

    /**
     * @param list<string> $secretNeedles
     */
    private function assertForumFeedDenied(string $body, array $secretNeedles): void
    {
        $this->assertStringContainsString(AP_Forum_Front::CANNOT_VIEW_MESSAGE, $body);
        $this->assertStringNotContainsString('<rss', $body);
        $this->assertStringNotContainsString('<feed xmlns', $body);
        $this->assertSame([], $this->needlesFound([$body], $secretNeedles));
    }

    /**
     * @param array<string, mixed> $vars
     */
    private function captureSitemapServe(array $vars): string
    {
        ob_start();
        try {
            $body = AP_Sitemap::serve($vars, $this->db, false);
            $echoed = (string) ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        $this->assertSame($body, $echoed);

        return $body;
    }

    /**
     * @param list<string> $secretNeedles
     */
    private function assertForumSitemapDenied(string $body, array $secretNeedles): void
    {
        $this->assertStringContainsString(AP_Forum_Front::CANNOT_VIEW_MESSAGE, $body);
        $this->assertStringNotContainsString('<urlset', $body);
        $this->assertStringNotContainsString('<sitemapindex', $body);
        $this->assertSame([], $this->needlesFound([$body], $secretNeedles));
    }

    /**
     * Live pretty-URL path: parseRequest → queryFromVars → applyToQuery (when
     * still a forum request) → theme render. Matches index.php.
     *
     * @return array{
     *     vars: array<string, mixed>,
     *     query: AP_Query,
     *     html: string,
     *     og: array<string, string>,
     *     canonical: string,
     *     args: array<string, mixed>
     * }
     */
    private function capturePrettyUrlPage(string $path): array
    {
        $vars = AP_Rewrite::parseRequest($path, [], $this->db);
        $query = AP_Rewrite::queryFromVars($vars, $this->db);
        if (AP_Forum_Front::isForumRequest($query)) {
            AP_Forum_Front::applyToQuery($query, $this->db);
        }
        $GLOBALS['ap_query'] = $query;
        $og = AP_Seo::getOpenGraphMeta($query, $this->db);
        $canonical = AP_Seo::getCanonicalUrl($query, $this->db);
        ob_start();
        try {
            AP_Theme::render($query, $this->db);
            $html = (string) ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        return [
            'vars' => $vars,
            'query' => $query,
            'html' => $html,
            'og' => $og,
            'canonical' => $canonical,
            'args' => $query->query_vars,
        ];
    }

    /**
     * @param array{
     *     vars?: array<string, mixed>,
     *     query: AP_Query,
     *     html: string,
     *     og: array<string, string>,
     *     canonical: string,
     *     args: array<string, mixed>
     * }            $captured
     * @param list<string> $secretNeedles
     */
    private function assertForumPrettyDenied(array $captured, array $secretNeedles): void
    {
        $query = $captured['query'];
        $this->assertTrue(!empty($query->get('ap_forum_cannot_view', false)));
        $this->assertSame(
            AP_Forum_Front::CANNOT_VIEW_MESSAGE,
            (string) $query->get('ap_forum_cannot_view_message', '')
        );
        $this->assertTrue($query->is_404);
        $this->assertSame('404.php', AP_Theme::getHierarchy($query, $this->db)[0] ?? null);
        $this->assertSame('', (string) $query->get('forum_name', 'x'));
        $this->assertSame('', (string) $query->get('forum_slug', 'x'));
        $this->assertSame(0, (int) $query->get('forum_id', -1));
        $this->assertSame('', (string) $query->get('topic_title', 'x'));
        $this->assertSame('', (string) $query->get('topic_slug', 'x'));
        $html = (string) ($captured['html'] ?? '');
        $this->assertStringContainsString(AP_Forum_Front::CANNOT_VIEW_MESSAGE, $html);
        $this->assertStringNotContainsString('Page not found', $html);
        $this->assertStringNotContainsString('forum-view', $html);
        $this->assertStringNotContainsString('ap-forum--view', $html);
        $this->assertStringNotContainsString('ap-forum--topic', $html);
        $this->assertStringNotContainsString('ap-forum--index', $html);
        $this->assertSame('', (string) ($captured['canonical'] ?? 'x'));
        $this->assertSame([], $this->needlesFound(
            [
                $html,
                json_encode($captured['args'] ?? [], JSON_UNESCAPED_SLASHES) ?: '',
                json_encode($captured['og'] ?? [], JSON_UNESCAPED_SLASHES) ?: '',
                (string) ($captured['canonical'] ?? ''),
            ],
            $secretNeedles
        ));
    }

    /**
     * @param array<string, mixed> $vars
     *
     * @return array{status: int, body: string, data: mixed}
     */
    private function captureRestServe(array $vars): array
    {
        $prevGet = $_GET;
        $prevMethod = $_SERVER['REQUEST_METHOD'] ?? null;
        $_GET = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        ob_start();
        try {
            $response = AP_Rest::serve($vars, $this->db, false);
            $echoed = (string) ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            $_GET = $prevGet;
            if ($prevMethod === null) {
                unset($_SERVER['REQUEST_METHOD']);
            } else {
                $_SERVER['REQUEST_METHOD'] = $prevMethod;
            }
            throw $e;
        }
        $_GET = $prevGet;
        if ($prevMethod === null) {
            unset($_SERVER['REQUEST_METHOD']);
        } else {
            $_SERVER['REQUEST_METHOD'] = $prevMethod;
        }

        return [
            'status' => (int) ($response['status'] ?? 0),
            'body' => $echoed,
            'data' => $response['data'] ?? null,
        ];
    }

    /**
     * @param array{status: int, body: string, data: mixed} $captured
     * @param list<string>                                 $secretNeedles
     */
    private function assertForumRestDenied(array $captured, array $secretNeedles): void
    {
        $this->assertSame(404, $captured['status']);
        $body = $captured['body'];
        $this->assertStringContainsString(AP_Forum_Front::CANNOT_VIEW_MESSAGE, $body);
        $this->assertStringContainsString('rest_cannot_view', $body);
        $this->assertSame([], $this->needlesFound([$body], $secretNeedles));
        $data = $captured['data'];
        $this->assertIsArray($data);
        $this->assertSame('rest_cannot_view', $data['code'] ?? null);
        $this->assertSame(AP_Forum_Front::CANNOT_VIEW_MESSAGE, $data['message'] ?? null);
        $this->assertSame([], $this->needlesFound(
            [json_encode($data, JSON_UNESCAPED_SLASHES) ?: ''],
            $secretNeedles
        ));
    }

    /**
     * @param list<string> $haystacks
     * @param list<string> $needles
     *
     * @return list<string>
     */
    private function needlesFound(array $haystacks, array $needles): array
    {
        $blob = implode("\n", $haystacks);
        $found = [];
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($blob, $needle)) {
                $found[] = $needle;
            }
        }

        return $found;
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
