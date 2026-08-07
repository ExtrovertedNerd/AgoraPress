<?php

/**
 * Permission matrix smoke checks for forum UI roles:
 * guest, member (subscriber), mod (editor / moderate_forums), admin.
 *
 * Covers default public ACL, display action flags (SPEC B2), topic-type caps
 * (SPEC A2), and gated write paths under check_permissions.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Forum;

use AP_DB;
use AP_Forum;
use AP_Forum_Front;
use AP_Forum_Permissions;
use AP_Group;
use AP_Migrator;
use AP_Options;
use AP_Query;
use AP_Roles;
use AP_Session;
use AP_User;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_Forum_Permissions::class)]
final class ForumPermissionMatrixTest extends TestCase
{
    private string $root;

    private AP_DB $db;

    private int $forumId = 0;

    private int $topicId = 0;

    private int $opPostId = 0;

    private int $memberId = 0;

    private int $otherMemberId = 0;

    private int $modId = 0;

    private int $adminId = 0;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        require_once $this->root . '/ap-includes/version.php';
        require_once $this->root . '/ap-includes/class-ap-db.php';
        require_once $this->root . '/ap-includes/class-ap-migrator.php';
        require_once $this->root . '/ap-includes/class-ap-options.php';
        require_once $this->root . '/ap-includes/class-ap-user.php';
        require_once $this->root . '/ap-includes/class-ap-roles.php';
        require_once $this->root . '/ap-includes/class-ap-session.php';
        require_once $this->root . '/ap-includes/class-ap-group.php';
        require_once $this->root . '/ap-includes/class-ap-forum-permissions.php';
        require_once $this->root . '/ap-includes/class-ap-forum.php';
        require_once $this->root . '/ap-includes/class-ap-forum-moderation.php';
        require_once $this->root . '/ap-includes/class-ap-forum-front.php';
        require_once $this->root . '/ap-includes/class-ap-query.php';
        require_once $this->root . '/ap-includes/hooks.php';
        require_once $this->root . '/ap-includes/functions.php';

        if (!defined('AP_NONCE_KEY')) {
            define('AP_NONCE_KEY', 'test-nonce-key-matrix');
        }
        if (!defined('AP_NONCE_SALT')) {
            define('AP_NONCE_SALT', 'test-nonce-salt-matrix');
        }
        if (!defined('AP_LOGGED_IN_KEY')) {
            define('AP_LOGGED_IN_KEY', 'test-logged-in-key-matrix');
        }
        if (!defined('AP_LOGGED_IN_SALT')) {
            define('AP_LOGGED_IN_SALT', 'test-logged-in-salt-matrix');
        }
        if (!defined('AP_AUTH_KEY')) {
            define('AP_AUTH_KEY', 'test-auth-key-matrix');
        }
        if (!defined('AP_AUTH_SALT')) {
            define('AP_AUTH_SALT', 'test-auth-salt-matrix');
        }

        if (function_exists('ap_reset_hooks')) {
            ap_reset_hooks();
        }

        AP_Roles::flushCache();
        AP_Options::flushCache();
        AP_Group::flushCache();
        AP_Forum_Permissions::flushCache();
        AP_Session::enableTestMode();
        AP_Session::resetCurrentUser();

        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');
        $GLOBALS['apdb'] = $this->db;

        (new AP_Migrator($this->db, AP_Migrator::defaultMigrationsPath()))->migrate();

        AP_Roles::ensureDefaults($this->db);
        AP_Group::ensureSystemGroups($this->db);
        AP_Forum_Permissions::ensureDefaults($this->db);

        $this->memberId = $this->createUser('matrix_member', 'matrix_m@example.test', 'subscriber');
        $this->otherMemberId = $this->createUser('matrix_other', 'matrix_o@example.test', 'subscriber');
        $this->modId = $this->createUser('matrix_mod', 'matrix_mod@example.test', 'editor');
        $this->adminId = $this->createUser('matrix_admin', 'matrix_a@example.test', 'administrator');

        $this->forumId = AP_Forum::insertForum([
            'forum_name' => 'Matrix Public',
            'forum_type' => 'forum',
            'forum_status' => 'open',
        ], $this->db);
        $this->assertGreaterThan(0, $this->forumId);

        // Explicit public preset (default install baseline).
        $this->assertTrue(AP_Forum_Permissions::applyAccessLevel(
            $this->forumId,
            AP_Forum_Permissions::ACCESS_PUBLIC,
            $this->db
        ));

        $this->topicId = AP_Forum::createTopic([
            'forum_id' => $this->forumId,
            'topic_title' => 'Matrix OP',
            'content' => 'Opening post for permission matrix smoke.',
            'poster_id' => $this->memberId,
        ], $this->db);
        $this->assertGreaterThan(0, $this->topicId);

        $posts = AP_Forum::getPosts($this->topicId, ['per_page' => 5], $this->db);
        $this->assertNotEmpty($posts);
        $this->opPostId = (int) ($posts[0]->post_id ?? 0);
        $this->assertGreaterThan(0, $this->opPostId);
    }

    protected function tearDown(): void
    {
        AP_Session::resetCurrentUser();
        AP_Session::disableTestMode();
        AP_Roles::flushCache();
        AP_Options::flushCache();
        AP_Group::flushCache();
        AP_Forum_Permissions::flushCache();
        unset($GLOBALS['apdb']);
    }

    /**
     * Full ACL matrix on a public forum for guest / member / mod / admin.
     *
     * @return array<string, array{user_id: int, expect: array<string, bool>}>
     */
    private function publicMatrixExpectations(): array
    {
        $all = AP_Forum_Permissions::allPermissions();
        $guestAllow = [
            AP_Forum_Permissions::PERM_VIEW => true,
            AP_Forum_Permissions::PERM_READ => true,
        ];
        $memberAllow = array_merge($guestAllow, [
            AP_Forum_Permissions::PERM_POST_TOPICS => true,
            AP_Forum_Permissions::PERM_POST_REPLIES => true,
            AP_Forum_Permissions::PERM_EDIT_OWN => true,
            AP_Forum_Permissions::PERM_DELETE_OWN => true,
            AP_Forum_Permissions::PERM_ATTACH => true,
        ]);
        $modAllow = array_merge($memberAllow, [
            AP_Forum_Permissions::PERM_MODERATE => true,
            AP_Forum_Permissions::PERM_STICKY => true,
            AP_Forum_Permissions::PERM_ANNOUNCE => true,
            AP_Forum_Permissions::PERM_LOCK => true,
            AP_Forum_Permissions::PERM_MOVE => true,
        ]);
        $adminAllow = [];
        foreach ($all as $perm) {
            $adminAllow[$perm] = true;
        }

        $fill = static function (array $allow) use ($all): array {
            $row = [];
            foreach ($all as $perm) {
                $row[$perm] = !empty($allow[$perm]);
            }

            return $row;
        };

        return [
            'guest' => [
                'user_id' => 0,
                'expect' => $fill($guestAllow),
            ],
            'member' => [
                'user_id' => $this->memberId,
                'expect' => $fill($memberAllow),
            ],
            'mod' => [
                'user_id' => $this->modId,
                'expect' => $fill($modAllow),
            ],
            'admin' => [
                'user_id' => $this->adminId,
                'expect' => $fill($adminAllow),
            ],
        ];
    }

    public function testPublicForumAclMatrixGuestMemberModAdmin(): void
    {
        $matrix = $this->publicMatrixExpectations();

        foreach ($matrix as $role => $spec) {
            $userId = (int) $spec['user_id'];
            $expect = $spec['expect'];
            $actual = AP_Forum_Permissions::getUserPermissions($userId, $this->forumId, $this->db);

            foreach ($expect as $perm => $allowed) {
                $this->assertSame(
                    $allowed,
                    !empty($actual[$perm]),
                    "role={$role} perm={$perm} expected " . ($allowed ? 'allow' : 'deny')
                );
                $this->assertSame(
                    $allowed,
                    AP_Forum_Permissions::userCan($userId, $this->forumId, $perm, $this->db),
                    "userCan role={$role} perm={$perm}"
                );
            }
        }

        // Convenience helpers match the matrix.
        $this->assertTrue(AP_Forum_Permissions::userCanViewForum(0, $this->forumId, $this->db));
        $this->assertFalse(AP_Forum_Permissions::userCanPostTopic(0, $this->forumId, $this->db));
        $this->assertFalse(AP_Forum_Permissions::userCanPostReply(0, $this->forumId, $this->db));
        $this->assertFalse(AP_Forum_Permissions::userCanModerate(0, $this->forumId, $this->db));

        $this->assertTrue(AP_Forum_Permissions::userCanPostTopic($this->memberId, $this->forumId, $this->db));
        $this->assertTrue(AP_Forum_Permissions::userCanPostReply($this->memberId, $this->forumId, $this->db));
        $this->assertFalse(AP_Forum_Permissions::userCanModerate($this->memberId, $this->forumId, $this->db));
        $this->assertFalse(AP_Forum_Permissions::userCanSticky($this->memberId, $this->forumId, $this->db));
        $this->assertFalse(AP_Forum_Permissions::userCanAnnounce($this->memberId, $this->forumId, $this->db));

        $this->assertTrue(AP_Forum_Permissions::userCanModerate($this->modId, $this->forumId, $this->db));
        $this->assertTrue(AP_Forum_Permissions::userCanSticky($this->modId, $this->forumId, $this->db));
        $this->assertTrue(AP_Forum_Permissions::userCanAnnounce($this->modId, $this->forumId, $this->db));
        $this->assertTrue(AP_Forum_Permissions::userCanPostTopic($this->modId, $this->forumId, $this->db));

        $this->assertTrue(AP_Forum_Permissions::userCanModerate($this->adminId, $this->forumId, $this->db));
        $this->assertTrue(AP_Forum_Permissions::userCanViewForum($this->adminId, $this->forumId, $this->db));
    }

    public function testTopicTypeCapsMatrix(): void
    {
        // Guest cannot create topics → no allowed types for create path.
        $this->assertSame(
            [],
            AP_Forum_Permissions::allowedTopicTypesForCreate(0, $this->forumId, $this->db)
        );

        $memberTypes = AP_Forum_Permissions::allowedTopicTypesForCreate(
            $this->memberId,
            $this->forumId,
            $this->db
        );
        $this->assertSame(['standard'], $memberTypes);
        $this->assertFalse(AP_Forum_Permissions::userCanSetTopicType(
            $this->memberId,
            $this->forumId,
            'sticky',
            $this->db,
            null
        ));
        $this->assertFalse(AP_Forum_Permissions::userCanSetTopicType(
            $this->memberId,
            $this->forumId,
            'announcement',
            $this->db,
            null
        ));
        $this->assertFalse(AP_Forum_Permissions::userCanSetTopicType(
            $this->memberId,
            $this->forumId,
            'rules',
            $this->db,
            null
        ));

        $modTypes = AP_Forum_Permissions::allowedTopicTypesForCreate(
            $this->modId,
            $this->forumId,
            $this->db
        );
        foreach (['standard', 'sticky', 'announcement', 'rules'] as $type) {
            $this->assertContains($type, $modTypes, "mod may create {$type}");
            $this->assertTrue(AP_Forum_Permissions::userCanSetTopicType(
                $this->modId,
                $this->forumId,
                $type,
                $this->db,
                null
            ), "mod can_set {$type}");
        }

        $adminTypes = AP_Forum_Permissions::allowedTopicTypesForCreate(
            $this->adminId,
            $this->forumId,
            $this->db
        );
        foreach (['standard', 'sticky', 'announcement', 'rules'] as $type) {
            $this->assertContains($type, $adminTypes, "admin may create {$type}");
        }
    }

    public function testDisplayActionFlagsMatrix(): void
    {
        // Guest viewer.
        $this->actAsUser(0);
        $guestRows = AP_Forum::getPostsDisplayData($this->topicId, ['per_page' => 10], $this->db);
        $this->assertNotEmpty($guestRows);
        foreach ($guestRows as $row) {
            $this->assertFalse((bool) ($row['can_quote'] ?? false), 'guest quote');
            $this->assertFalse((bool) ($row['can_like'] ?? false), 'guest like');
            $this->assertFalse((bool) ($row['can_edit'] ?? false), 'guest edit');
            $this->assertFalse((bool) ($row['can_delete'] ?? false), 'guest delete');
            $this->assertFalse((bool) ($row['can_moderate'] ?? false), 'guest moderate');
        }

        // Other member: quote + like; cannot edit OP they did not author.
        $this->actAsUser($this->otherMemberId);
        $otherRows = AP_Forum::getPostsDisplayData($this->topicId, ['per_page' => 10], $this->db);
        $opRow = $this->findPostRow($otherRows, $this->opPostId);
        $this->assertNotNull($opRow);
        $this->assertTrue((bool) ($opRow['can_quote'] ?? false), 'member quote');
        $this->assertTrue((bool) ($opRow['can_like'] ?? false), 'member like');
        $this->assertFalse((bool) ($opRow['can_edit'] ?? false), 'other member edit OP');
        $this->assertFalse((bool) ($opRow['can_delete'] ?? false), 'other member delete OP');
        $this->assertFalse((bool) ($opRow['can_moderate'] ?? false), 'member moderate');

        // OP author: can edit/delete own.
        $this->actAsUser($this->memberId);
        $authorRows = AP_Forum::getPostsDisplayData($this->topicId, ['per_page' => 10], $this->db);
        $authorOp = $this->findPostRow($authorRows, $this->opPostId);
        $this->assertNotNull($authorOp);
        $this->assertTrue((bool) ($authorOp['can_edit'] ?? false), 'author edit own');
        $this->assertTrue((bool) ($authorOp['can_delete'] ?? false), 'author delete own');
        $this->assertTrue((bool) ($authorOp['can_quote'] ?? false));
        $this->assertTrue((bool) ($authorOp['can_like'] ?? false));
        $this->assertFalse((bool) ($authorOp['can_moderate'] ?? false));

        // Moderator: quote/like + edit others + moderate.
        $this->actAsUser($this->modId);
        $modRows = AP_Forum::getPostsDisplayData($this->topicId, ['per_page' => 10], $this->db);
        $modOp = $this->findPostRow($modRows, $this->opPostId);
        $this->assertNotNull($modOp);
        $this->assertTrue((bool) ($modOp['can_quote'] ?? false), 'mod quote');
        $this->assertTrue((bool) ($modOp['can_like'] ?? false), 'mod like');
        $this->assertTrue((bool) ($modOp['can_edit'] ?? false), 'mod edit others');
        $this->assertTrue((bool) ($modOp['can_delete'] ?? false), 'mod delete others');
        $this->assertTrue((bool) ($modOp['can_moderate'] ?? false), 'mod moderate flag');

        // Admin: same staff powers.
        $this->actAsUser($this->adminId);
        $adminRows = AP_Forum::getPostsDisplayData($this->topicId, ['per_page' => 10], $this->db);
        $adminOp = $this->findPostRow($adminRows, $this->opPostId);
        $this->assertNotNull($adminOp);
        $this->assertTrue((bool) ($adminOp['can_quote'] ?? false));
        $this->assertTrue((bool) ($adminOp['can_like'] ?? false));
        $this->assertTrue((bool) ($adminOp['can_edit'] ?? false));
        $this->assertTrue((bool) ($adminOp['can_delete'] ?? false));
        $this->assertTrue((bool) ($adminOp['can_moderate'] ?? false));

        $this->actAsUser(0);
    }

    public function testFrontQueryFlagsMatrix(): void
    {
        // Forum view: can_post_topic + create-time allowed types.
        $forumCases = [
            'guest' => [
                'user_id' => 0,
                'can_post_topic' => false,
                'allowed_create' => [],
            ],
            'member' => [
                'user_id' => $this->memberId,
                'can_post_topic' => true,
                'allowed_create' => ['standard'],
            ],
            'mod' => [
                'user_id' => $this->modId,
                'can_post_topic' => true,
                'allowed_create' => ['standard', 'sticky', 'announcement', 'rules'],
            ],
            'admin' => [
                'user_id' => $this->adminId,
                'can_post_topic' => true,
                'allowed_create' => ['standard', 'sticky', 'announcement', 'rules'],
            ],
        ];

        foreach ($forumCases as $role => $spec) {
            $this->actAsUser((int) $spec['user_id']);

            $query = new AP_Query([
                'ap_forum_view' => 'forum',
                'forum_id' => $this->forumId,
                'no_found_rows' => true,
            ], $this->db);
            AP_Forum_Front::applyToQuery($query, $this->db);

            $this->assertSame(
                $spec['can_post_topic'],
                (bool) $query->get('can_post_topic', false),
                "{$role} can_post_topic"
            );
            $allowed = $query->get('allowed_topic_types', []);
            $this->assertIsArray($allowed);
            $this->assertSame($spec['allowed_create'], $allowed, "{$role} allowed create types");
        }

        // Topic view: can_reply / can_moderate / can_set_topic_type (edit toolbar).
        // Members without sticky/announce caps get empty edit types → can_set false.
        $topicCases = [
            'guest' => [
                'user_id' => 0,
                'can_reply' => false,
                'can_moderate' => false,
                'can_set_topic_type' => false,
            ],
            'member' => [
                'user_id' => $this->memberId,
                'can_reply' => true,
                'can_moderate' => false,
                'can_set_topic_type' => false,
            ],
            'mod' => [
                'user_id' => $this->modId,
                'can_reply' => true,
                'can_moderate' => true,
                'can_set_topic_type' => true,
            ],
            'admin' => [
                'user_id' => $this->adminId,
                'can_reply' => true,
                'can_moderate' => true,
                'can_set_topic_type' => true,
            ],
        ];

        foreach ($topicCases as $role => $spec) {
            $this->actAsUser((int) $spec['user_id']);

            $query = new AP_Query([
                'ap_forum_view' => 'topic',
                'topic_id' => $this->topicId,
                'forum_id' => $this->forumId,
                'no_found_rows' => true,
            ], $this->db);
            AP_Forum_Front::applyToQuery($query, $this->db);

            $this->assertSame(
                $spec['can_reply'],
                (bool) $query->get('can_reply', false),
                "{$role} can_reply"
            );
            $this->assertSame(
                $spec['can_moderate'],
                (bool) $query->get('can_moderate', false),
                "{$role} can_moderate"
            );
            $this->assertSame(
                $spec['can_set_topic_type'],
                (bool) $query->get('can_set_topic_type', false),
                "{$role} can_set_topic_type"
            );

            if ($role === 'mod' || $role === 'admin') {
                $allowed = $query->get('allowed_topic_types', []);
                $this->assertIsArray($allowed);
                $this->assertContains('sticky', $allowed);
                $this->assertContains('announcement', $allowed);
            }
        }

        $this->actAsUser(0);
    }

    public function testWritePathsHonorMatrix(): void
    {
        // Guest cannot create topic / reply.
        $this->assertSame(0, AP_Forum::createTopic([
            'forum_id' => $this->forumId,
            'topic_title' => 'Guest fail',
            'content' => 'Nope',
            'poster_id' => 0,
        ], $this->db, ['check_permissions' => true]));

        $this->assertSame(0, AP_Forum::createReply([
            'topic_id' => $this->topicId,
            'content' => 'Guest reply fail',
            'poster_id' => 0,
        ], $this->db, ['check_permissions' => true]));

        // Member can reply and create standard topics; sticky denied.
        $replyId = AP_Forum::createReply([
            'topic_id' => $this->topicId,
            'content' => 'Member reply ok',
            'poster_id' => $this->otherMemberId,
        ], $this->db, ['check_permissions' => true]);
        $this->assertGreaterThan(0, $replyId);

        $memberTopic = AP_Forum::createTopic([
            'forum_id' => $this->forumId,
            'topic_title' => 'Member topic ok',
            'content' => 'Body',
            'poster_id' => $this->otherMemberId,
            'topic_type' => 'standard',
        ], $this->db, ['check_permissions' => true]);
        $this->assertGreaterThan(0, $memberTopic);

        $this->assertSame(0, AP_Forum::createTopic([
            'forum_id' => $this->forumId,
            'topic_title' => 'Member sticky fail',
            'content' => 'Body',
            'poster_id' => $this->otherMemberId,
            'topic_type' => 'sticky',
        ], $this->db, ['check_permissions' => true]));

        // Mod can sticky + announce.
        $stickyId = AP_Forum::createTopic([
            'forum_id' => $this->forumId,
            'topic_title' => 'Mod sticky ok',
            'content' => 'Body',
            'poster_id' => $this->modId,
            'topic_type' => 'sticky',
        ], $this->db, ['check_permissions' => true]);
        $this->assertGreaterThan(0, $stickyId);

        $announceId = AP_Forum::createTopic([
            'forum_id' => $this->forumId,
            'topic_title' => 'Mod announce ok',
            'content' => 'Body',
            'poster_id' => $this->modId,
            'topic_type' => 'announcement',
        ], $this->db, ['check_permissions' => true]);
        $this->assertGreaterThan(0, $announceId);

        // Admin can create rules type.
        $rulesId = AP_Forum::createTopic([
            'forum_id' => $this->forumId,
            'topic_title' => 'Admin rules ok',
            'content' => 'Body',
            'poster_id' => $this->adminId,
            'topic_type' => 'rules',
        ], $this->db, ['check_permissions' => true]);
        $this->assertGreaterThan(0, $rulesId);
    }

    public function testMembersOnlyPresetMatrix(): void
    {
        $membersForum = AP_Forum::insertForum(['forum_name' => 'Members Matrix'], $this->db);
        $this->assertTrue(AP_Forum_Permissions::applyAccessLevel(
            $membersForum,
            AP_Forum_Permissions::ACCESS_MEMBERS,
            $this->db
        ));

        // Guests hidden; member can post; mod/admin can view+moderate.
        $this->assertFalse(AP_Forum_Permissions::userCanViewForum(0, $membersForum, $this->db));
        $this->assertTrue(AP_Forum_Permissions::userCanViewForum($this->memberId, $membersForum, $this->db));
        $this->assertTrue(AP_Forum_Permissions::userCanPostTopic($this->memberId, $membersForum, $this->db));
        $this->assertFalse(AP_Forum_Permissions::userCanModerate($this->memberId, $membersForum, $this->db));

        $this->assertTrue(AP_Forum_Permissions::userCanViewForum($this->modId, $membersForum, $this->db));
        $this->assertTrue(AP_Forum_Permissions::userCanModerate($this->modId, $membersForum, $this->db));
        $this->assertTrue(AP_Forum_Permissions::userCanViewForum($this->adminId, $membersForum, $this->db));
        $this->assertTrue(AP_Forum_Permissions::userCanModerate($this->adminId, $membersForum, $this->db));
    }

    public function testModeratorsOnlyAndAdministratorsOnlyMatrix(): void
    {
        $modForum = AP_Forum::insertForum(['forum_name' => 'Staff Matrix'], $this->db);
        AP_Forum_Permissions::applyAccessLevel(
            $modForum,
            AP_Forum_Permissions::ACCESS_MODERATORS,
            $this->db
        );
        $this->assertFalse(AP_Forum_Permissions::userCanViewForum(0, $modForum, $this->db));
        $this->assertFalse(AP_Forum_Permissions::userCanViewForum($this->memberId, $modForum, $this->db));
        $this->assertTrue(AP_Forum_Permissions::userCanViewForum($this->modId, $modForum, $this->db));
        $this->assertTrue(AP_Forum_Permissions::userCanPostTopic($this->modId, $modForum, $this->db));
        $this->assertTrue(AP_Forum_Permissions::userCanViewForum($this->adminId, $modForum, $this->db));

        $adminForum = AP_Forum::insertForum(['forum_name' => 'Root Matrix'], $this->db);
        AP_Forum_Permissions::applyAccessLevel(
            $adminForum,
            AP_Forum_Permissions::ACCESS_ADMINISTRATORS,
            $this->db
        );
        $this->assertFalse(AP_Forum_Permissions::userCanViewForum(0, $adminForum, $this->db));
        $this->assertFalse(AP_Forum_Permissions::userCanViewForum($this->memberId, $adminForum, $this->db));
        $this->assertFalse(AP_Forum_Permissions::userCanViewForum($this->modId, $adminForum, $this->db));
        $this->assertTrue(AP_Forum_Permissions::userCanViewForum($this->adminId, $adminForum, $this->db));
        $this->assertTrue(AP_Forum_Permissions::userCanModerate($this->adminId, $adminForum, $this->db));
    }

    /**
     * Switch session identity for display/front smoke checks.
     * Clears the auth cookie first so guests are truly anonymous.
     */
    private function actAsUser(int $userId): void
    {
        AP_Session::clearAuthCookie();
        AP_Session::resetCurrentUser();
        if ($userId > 0) {
            $this->assertTrue(
                AP_Session::setAuthCookie($userId, false, $this->db),
                "failed to auth user {$userId}"
            );
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>|null
     */
    private function findPostRow(array $rows, int $postId): ?array
    {
        foreach ($rows as $row) {
            if ((int) ($row['id'] ?? 0) === $postId) {
                return $row;
            }
        }

        return null;
    }

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
