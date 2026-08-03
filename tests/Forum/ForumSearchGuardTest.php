<?php

/**
 * Tests for forum search, flood control, anti-spam, and post approval.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Forum;

use AP_DB;
use AP_Forum;
use AP_Forum_Guard;
use AP_Forum_Moderation;
use AP_Forum_Permissions;
use AP_Group;
use AP_Migrator;
use AP_Options;
use AP_Roles;
use AP_User;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_Forum_Guard::class)]
#[CoversClass(AP_Forum::class)]
final class ForumSearchGuardTest extends TestCase
{
    private string $root;

    private AP_DB $db;

    private int $forumId = 0;

    private int $userId = 0;

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
        require_once $this->root . '/ap-includes/class-ap-forum-guard.php';
        require_once $this->root . '/ap-includes/class-ap-forum-moderation.php';
        require_once $this->root . '/ap-includes/class-ap-group.php';
        require_once $this->root . '/ap-includes/class-ap-forum-permissions.php';
        require_once $this->root . '/ap-includes/hooks.php';
        require_once $this->root . '/ap-includes/functions.php';

        if (function_exists('ap_reset_hooks')) {
            ap_reset_hooks();
        }
        AP_Forum_Guard::resetSpamCheckers();
        AP_Forum_Guard::resetLastResult();
        AP_Options::flushCache();
        AP_Roles::flushCache();
        AP_Group::flushCache();
        AP_Forum_Permissions::flushCache();

        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');
        $GLOBALS['apdb'] = $this->db;

        $migrator = new AP_Migrator($this->db, AP_Migrator::defaultMigrationsPath());
        $migrator->migrate();

        AP_Roles::ensureDefaults($this->db);
        AP_Forum_Permissions::ensureDefaults($this->db);

        // Guard defaults for tests (flood off unless a test enables it).
        AP_Options::update(AP_Forum_Guard::OPTION_FLOOD_INTERVAL, '0', $this->db);
        AP_Options::update(AP_Forum_Guard::OPTION_REQUIRE_APPROVAL, '0', $this->db);
        AP_Options::update(AP_Forum_Guard::OPTION_SPAM_BLACKLIST, '', $this->db);
        AP_Options::update(AP_Forum_Guard::OPTION_SPAM_MAX_LINKS, '5', $this->db);
        AP_Options::update(AP_Forum_Guard::OPTION_SEARCH_ENABLED, '1', $this->db);

        $created = AP_User::create([
            'user_login' => 'forumuser',
            'user_email' => 'forumuser@example.test',
            'user_pass' => 'Password123!',
            'display_name' => 'Forum User',
            'role' => 'subscriber',
        ], $this->db);
        $this->assertTrue($created['ok'] ?? false, implode('; ', $created['errors'] ?? ['create failed']));
        $this->userId = (int) ($created['id'] ?? 0);
        $this->assertGreaterThan(0, $this->userId);

        $this->forumId = AP_Forum::insertForum([
            'forum_name' => 'Search Guard Forum',
            'forum_type' => 'forum',
        ], $this->db);
        $this->assertGreaterThan(0, $this->forumId);
    }

    protected function tearDown(): void
    {
        AP_Forum_Guard::resetSpamCheckers();
        AP_Forum_Guard::resetLastResult();
        AP_Options::flushCache();
        AP_Roles::flushCache();
        AP_Group::flushCache();
        AP_Forum_Permissions::flushCache();
        if (function_exists('ap_reset_hooks')) {
            ap_reset_hooks();
        }
        unset($GLOBALS['apdb']);
    }

    // -------------------------------------------------------------------------
    // Search
    // -------------------------------------------------------------------------

    public function testSearchFindsTopicsAndPosts(): void
    {
        $topicA = AP_Forum::createTopic([
            'forum_id' => $this->forumId,
            'topic_title' => 'UniqueZebra Title',
            'content' => 'Plain body without token',
            'poster_id' => $this->userId,
        ], $this->db);
        $this->assertGreaterThan(0, $topicA);

        $topicB = AP_Forum::createTopic([
            'forum_id' => $this->forumId,
            'topic_title' => 'Other subject',
            'content' => 'Body with UniqueZebra word inside',
            'poster_id' => $this->userId,
        ], $this->db);
        $this->assertGreaterThan(0, $topicB);

        $replyId = AP_Forum::createReply([
            'topic_id' => $topicB,
            'content' => 'Reply mentioning UniqueZebra again',
            'poster_id' => $this->userId,
        ], $this->db);
        $this->assertGreaterThan(0, $replyId);

        $all = AP_Forum::search('UniqueZebra', [
            'type' => 'all',
            'approved_only' => true,
        ], $this->db);
        $this->assertSame('UniqueZebra', $all['query']);
        $this->assertGreaterThanOrEqual(2, $all['total']);
        $this->assertNotEmpty($all['results']);

        $types = array_column($all['results'], 'result_type');
        $this->assertContains('topic', $types);

        $topicsOnly = AP_Forum::search('UniqueZebra', ['type' => 'topics'], $this->db);
        $this->assertGreaterThanOrEqual(1, $topicsOnly['total']);
        $this->assertNotEmpty($topicsOnly['topics']);

        $postsOnly = AP_Forum::search('UniqueZebra', ['type' => 'posts'], $this->db);
        $this->assertGreaterThanOrEqual(1, $postsOnly['total']);
        $this->assertNotEmpty($postsOnly['posts']);
    }

    public function testSearchExcludesUnapprovedAndDeleted(): void
    {
        $approved = AP_Forum::createTopic([
            'forum_id' => $this->forumId,
            'topic_title' => 'VisibleSearchToken',
            'content' => 'ok',
            'poster_id' => $this->userId,
        ], $this->db);
        $pending = AP_Forum::createTopic([
            'forum_id' => $this->forumId,
            'topic_title' => 'PendingSearchToken',
            'content' => 'ok',
            'poster_id' => $this->userId,
            'topic_approved' => 0,
        ], $this->db);
        $this->assertGreaterThan(0, $approved);
        $this->assertGreaterThan(0, $pending);

        $public = AP_Forum::search('SearchToken', ['approved_only' => true], $this->db);
        $titles = [];
        foreach ($public['topics'] as $t) {
            $titles[] = (string) $t->topic_title;
        }
        $this->assertContains('VisibleSearchToken', $titles);
        $this->assertNotContains('PendingSearchToken', $titles);

        $mod = AP_Forum::search('SearchToken', ['approved_only' => false], $this->db);
        $modTitles = [];
        foreach ($mod['topics'] as $t) {
            $modTitles[] = (string) $t->topic_title;
        }
        $this->assertContains('PendingSearchToken', $modTitles);

        AP_Forum::deleteTopic($approved, false, $this->db);
        $afterDelete = AP_Forum::search('VisibleSearchToken', [], $this->db);
        $this->assertSame(0, $afterDelete['total']);
    }

    public function testSearchDisabledReturnsEmpty(): void
    {
        AP_Forum::createTopic([
            'forum_id' => $this->forumId,
            'topic_title' => 'DisabledSearchHit',
            'content' => 'body',
            'poster_id' => $this->userId,
        ], $this->db);

        AP_Options::update(AP_Forum_Guard::OPTION_SEARCH_ENABLED, '0', $this->db);
        $this->assertFalse(AP_Forum_Guard::isSearchEnabled($this->db));

        $blocked = AP_Forum::search('DisabledSearchHit', [], $this->db);
        $this->assertSame(0, $blocked['total']);
        $this->assertSame([], $blocked['results']);

        $forced = AP_Forum::search('DisabledSearchHit', ['force' => true], $this->db);
        $this->assertGreaterThanOrEqual(1, $forced['total']);
    }

    public function testSearchUrlPrettyAndPlain(): void
    {
        $url = AP_Forum::searchUrl('hello world', $this->db);
        $this->assertNotSame('', $url);
        $this->assertTrue(
            str_contains($url, 'search') || str_contains($url, 'forum_s'),
            'search URL should contain search path or query var'
        );
        $this->assertTrue(function_exists('ap_forum_search'));
        $this->assertTrue(function_exists('ap_forum_search_url'));
    }

    public function testSearchRespectsForumPermissions(): void
    {
        $secretForum = AP_Forum::insertForum([
            'forum_name' => 'Secret Board',
            'forum_type' => 'forum',
        ], $this->db);
        $this->assertGreaterThan(0, $secretForum);

        AP_Forum::createTopic([
            'forum_id' => $this->forumId,
            'topic_title' => 'PublicAclToken thread',
            'content' => 'visible body',
            'poster_id' => $this->userId,
        ], $this->db);
        AP_Forum::createTopic([
            'forum_id' => $secretForum,
            'topic_title' => 'SecretAclToken thread',
            'content' => 'hidden body',
            'poster_id' => $this->userId,
        ], $this->db);

        // Deny registered members from reading the secret forum.
        $registered = AP_Group::getBySlug(AP_Group::SLUG_REGISTERED, $this->db);
        $this->assertNotNull($registered);
        AP_Forum_Permissions::setPermission(
            $secretForum,
            (int) $registered->group_id,
            AP_Forum_Permissions::PERM_VIEW,
            false,
            $this->db
        );
        AP_Forum_Permissions::setPermission(
            $secretForum,
            (int) $registered->group_id,
            AP_Forum_Permissions::PERM_READ,
            false,
            $this->db
        );
        AP_Forum_Permissions::flushCache();

        $this->assertFalse(
            AP_Forum_Permissions::userCan(
                $this->userId,
                $secretForum,
                AP_Forum_Permissions::PERM_READ,
                $this->db
            )
        );

        $acl = AP_Forum::search('AclToken', [
            'type' => 'topics',
            'check_permissions' => true,
            'user_id' => $this->userId,
        ], $this->db);
        $titles = array_map(static fn ($t) => (string) $t->topic_title, $acl['topics']);
        $this->assertContains('PublicAclToken thread', $titles);
        $this->assertNotContains('SecretAclToken thread', $titles);

        // Without check_permissions, both still match (API / moderation use).
        $raw = AP_Forum::search('AclToken', ['type' => 'topics'], $this->db);
        $rawTitles = array_map(static fn ($t) => (string) $t->topic_title, $raw['topics']);
        $this->assertContains('SecretAclToken thread', $rawTitles);

        // Staff with manage_forums sees private results when ACL is on.
        $admin = AP_User::create([
            'user_login' => 'searchadmin',
            'user_email' => 'searchadmin@example.test',
            'user_pass' => 'Password123!',
            'role' => 'administrator',
        ], $this->db);
        $adminId = (int) ($admin['id'] ?? 0);
        $this->assertGreaterThan(0, $adminId);
        $staff = AP_Forum::search('AclToken', [
            'type' => 'topics',
            'check_permissions' => true,
            'user_id' => $adminId,
        ], $this->db);
        $staffTitles = array_map(static fn ($t) => (string) $t->topic_title, $staff['topics']);
        $this->assertContains('SecretAclToken thread', $staffTitles);
    }

    // -------------------------------------------------------------------------
    // Flood control
    // -------------------------------------------------------------------------

    public function testFloodControlBlocksRapidPosts(): void
    {
        AP_Options::update(AP_Forum_Guard::OPTION_FLOOD_INTERVAL, '60', $this->db);
        $this->assertSame(60, AP_Forum_Guard::getFloodInterval($this->db));

        $first = AP_Forum::createTopic([
            'forum_id' => $this->forumId,
            'topic_title' => 'First post',
            'content' => 'Hello flood',
            'poster_id' => $this->userId,
            'poster_ip' => '10.0.0.1',
        ], $this->db, ['check_guard' => true]);
        $this->assertGreaterThan(0, $first);

        $this->assertTrue(AP_Forum_Guard::isFlooding($this->userId, '', $this->db));
        $retry = AP_Forum_Guard::secondsUntilAllowed($this->userId, '', $this->db);
        $this->assertGreaterThan(0, $retry);
        $this->assertLessThanOrEqual(60, $retry);

        $second = AP_Forum::createTopic([
            'forum_id' => $this->forumId,
            'topic_title' => 'Too fast',
            'content' => 'Should block',
            'poster_id' => $this->userId,
            'poster_ip' => '10.0.0.1',
        ], $this->db, ['check_guard' => true]);
        $this->assertSame(0, $second);

        $last = AP_Forum_Guard::getLastResult();
        $this->assertSame(AP_Forum_Guard::STATUS_FLOOD, $last['code'] ?? null);
        $this->assertFalse($last['allowed'] ?? true);

        // Flood off restores posting.
        AP_Options::update(AP_Forum_Guard::OPTION_FLOOD_INTERVAL, '0', $this->db);
        $third = AP_Forum::createTopic([
            'forum_id' => $this->forumId,
            'topic_title' => 'After disable',
            'content' => 'ok now',
            'poster_id' => $this->userId,
        ], $this->db, ['check_guard' => true]);
        $this->assertGreaterThan(0, $third);
    }

    public function testFloodExemptForModerators(): void
    {
        AP_Options::update(AP_Forum_Guard::OPTION_FLOOD_INTERVAL, '120', $this->db);

        $mod = AP_User::create([
            'user_login' => 'modflood',
            'user_email' => 'modflood@example.test',
            'user_pass' => 'Password123!',
            'role' => 'administrator',
        ], $this->db);
        $modId = (int) ($mod['id'] ?? 0);
        $this->assertGreaterThan(0, $modId);

        $a = AP_Forum::createTopic([
            'forum_id' => $this->forumId,
            'topic_title' => 'Mod first',
            'content' => 'body',
            'poster_id' => $modId,
        ], $this->db, ['check_guard' => true]);
        $this->assertGreaterThan(0, $a);

        $this->assertFalse(AP_Forum_Guard::isFlooding($modId, '', $this->db));
        $b = AP_Forum::createReply([
            'topic_id' => $a,
            'content' => 'mod reply immediate',
            'poster_id' => $modId,
        ], $this->db, ['check_guard' => true]);
        $this->assertGreaterThan(0, $b);
    }

    // -------------------------------------------------------------------------
    // Anti-spam
    // -------------------------------------------------------------------------

    public function testBlacklistRejectsSpam(): void
    {
        AP_Options::update(AP_Forum_Guard::OPTION_SPAM_BLACKLIST, 'casino,viagra', $this->db);
        $words = AP_Forum_Guard::getSpamBlacklist($this->db);
        $this->assertContains('casino', $words);
        $this->assertContains('viagra', $words);

        $id = AP_Forum::createTopic([
            'forum_id' => $this->forumId,
            'topic_title' => 'Buy casino chips',
            'content' => 'great deal',
            'poster_id' => $this->userId,
        ], $this->db, ['check_guard' => true]);
        $this->assertSame(0, $id);
        $this->assertSame(AP_Forum_Guard::STATUS_SPAM, AP_Forum_Guard::getLastResult()['code'] ?? null);

        $ok = AP_Forum::createTopic([
            'forum_id' => $this->forumId,
            'topic_title' => 'Normal title',
            'content' => 'clean content',
            'poster_id' => $this->userId,
        ], $this->db, ['check_guard' => true]);
        $this->assertGreaterThan(0, $ok);
    }

    public function testMaxLinksForcesPending(): void
    {
        AP_Options::update(AP_Forum_Guard::OPTION_SPAM_MAX_LINKS, '2', $this->db);

        $body = "See https://a.example/ https://b.example/ https://c.example/ for more";
        $id = AP_Forum::createTopic([
            'forum_id' => $this->forumId,
            'topic_title' => 'Link farm',
            'content' => $body,
            'poster_id' => $this->userId,
        ], $this->db, ['check_guard' => true]);
        $this->assertGreaterThan(0, $id);
        $topic = AP_Forum::getTopic($id, $this->db);
        $this->assertSame(0, (int) ($topic->topic_approved ?? 1));
        $this->assertSame(AP_Forum_Guard::STATUS_PENDING, AP_Forum_Guard::getLastResult()['code'] ?? null);
    }

    public function testPluggableSpamCheckerAndFilter(): void
    {
        AP_Forum_Guard::registerSpamChecker(static function (array $data): ?string {
            if (str_contains(strtolower((string) ($data['content'] ?? '')), 'blocked-by-plugin')) {
                return 'spam';
            }

            return null;
        });

        $id = AP_Forum::createTopic([
            'forum_id' => $this->forumId,
            'topic_title' => 'Plugin check',
            'content' => 'this is blocked-by-plugin material',
            'poster_id' => $this->userId,
        ], $this->db, ['check_guard' => true]);
        $this->assertSame(0, $id);

        if (function_exists('ap_add_filter')) {
            ap_add_filter('ap_pre_forum_post_status', static function ($status, $data) {
                if (str_contains((string) ($data['content'] ?? ''), 'force-pending')) {
                    return 'pending';
                }

                return $status;
            }, 10, 2);

            $pendingId = AP_Forum::createTopic([
                'forum_id' => $this->forumId,
                'topic_title' => 'Filter hold',
                'content' => 'please force-pending this',
                'poster_id' => $this->userId,
            ], $this->db, ['check_guard' => true]);
            $this->assertGreaterThan(0, $pendingId);
            $topic = AP_Forum::getTopic($pendingId, $this->db);
            $this->assertSame(0, (int) ($topic->topic_approved ?? 1));
        }
    }

    // -------------------------------------------------------------------------
    // Post approval
    // -------------------------------------------------------------------------

    public function testRequireApprovalCreatesPendingTopicAndReply(): void
    {
        AP_Options::update(AP_Forum_Guard::OPTION_REQUIRE_APPROVAL, '1', $this->db);
        $this->assertTrue(AP_Forum_Guard::requiresApproval($this->db));
        $this->assertTrue(AP_Forum_Guard::userRequiresApproval($this->userId, $this->db));

        $topicId = AP_Forum::createTopic([
            'forum_id' => $this->forumId,
            'topic_title' => 'Needs review',
            'content' => 'pending body',
            'poster_id' => $this->userId,
        ], $this->db, ['check_guard' => true]);
        $this->assertGreaterThan(0, $topicId);

        $topic = AP_Forum::getTopic($topicId, $this->db);
        $this->assertSame(0, (int) ($topic->topic_approved ?? 1));
        $first = AP_Forum::getPost((int) $topic->first_post_id, $this->db);
        $this->assertSame(0, (int) ($first->post_approved ?? 1));

        // Pending topics do not bump public forum counters.
        $forum = AP_Forum::getForum($this->forumId, $this->db);
        $this->assertSame(0, (int) ($forum->topic_count ?? -1));
        $this->assertSame(0, (int) ($forum->post_count ?? -1));

        // Public listing hides pending.
        $listed = AP_Forum::getTopics($this->forumId, ['approved_only' => true], $this->db);
        $this->assertCount(0, $listed);

        // Seed an approved topic for reply testing.
        $approvedTopic = AP_Forum::createTopic([
            'forum_id' => $this->forumId,
            'topic_title' => 'Already approved',
            'content' => 'seed',
            'poster_id' => $this->userId,
            'topic_approved' => 1,
        ], $this->db);
        $this->assertGreaterThan(0, $approvedTopic);

        $replyId = AP_Forum::createReply([
            'topic_id' => $approvedTopic,
            'content' => 'pending reply',
            'poster_id' => $this->userId,
        ], $this->db, ['check_guard' => true]);
        $this->assertGreaterThan(0, $replyId);
        $reply = AP_Forum::getPost($replyId, $this->db);
        $this->assertSame(0, (int) ($reply->post_approved ?? 1));

        $pendingTopics = AP_Forum::getPendingTopics([], $this->db);
        $this->assertNotEmpty($pendingTopics);
        $pendingPosts = AP_Forum::getPendingPosts([], $this->db);
        $this->assertNotEmpty($pendingPosts);
    }

    public function testApproveTopicAndPostViaModeration(): void
    {
        $topicId = AP_Forum::createTopic([
            'forum_id' => $this->forumId,
            'topic_title' => 'Hold topic',
            'content' => 'wait',
            'poster_id' => $this->userId,
            'topic_approved' => 0,
        ], $this->db);
        $this->assertGreaterThan(0, $topicId);
        $this->assertSame(1, AP_Forum::countPendingTopics([], $this->db));

        $forumBefore = AP_Forum::getForum($this->forumId, $this->db);
        $countBefore = (int) ($forumBefore->topic_count ?? 0);

        $this->assertTrue(AP_Forum_Moderation::approveTopic($topicId, 0, $this->db));
        $topic = AP_Forum::getTopic($topicId, $this->db);
        $this->assertSame(1, (int) ($topic->topic_approved ?? 0));
        $first = AP_Forum::getPost((int) $topic->first_post_id, $this->db);
        $this->assertSame(1, (int) ($first->post_approved ?? 0));
        $this->assertSame(0, AP_Forum::countPendingTopics([], $this->db));

        $forumAfter = AP_Forum::getForum($this->forumId, $this->db);
        $this->assertSame($countBefore + 1, (int) ($forumAfter->topic_count ?? 0));

        // Pending reply on approved topic.
        $replyId = AP_Forum::createReply([
            'topic_id' => $topicId,
            'content' => 'hold reply',
            'poster_id' => $this->userId,
            'post_approved' => 0,
        ], $this->db);
        $this->assertGreaterThan(0, $replyId);
        $this->assertSame(1, AP_Forum::countPendingPosts([], $this->db));

        $repliesPublic = AP_Forum::getPosts($topicId, ['approved_only' => true], $this->db);
        $publicIds = array_map(static fn ($p) => (int) $p->post_id, $repliesPublic);
        $this->assertNotContains($replyId, $publicIds);

        $this->assertTrue(AP_Forum_Moderation::approvePost($replyId, 0, $this->db));
        $reply = AP_Forum::getPost($replyId, $this->db);
        $this->assertSame(1, (int) ($reply->post_approved ?? 0));
        $this->assertSame(0, AP_Forum::countPendingPosts([], $this->db));

        // Unapprove reply again.
        $this->assertTrue(AP_Forum_Moderation::unapprovePost($replyId, 0, $this->db));
        $reply = AP_Forum::getPost($replyId, $this->db);
        $this->assertSame(0, (int) ($reply->post_approved ?? 1));
    }

    public function testEvaluateApiAndProceduralHelpers(): void
    {
        AP_Options::update(AP_Forum_Guard::OPTION_FLOOD_INTERVAL, '0', $this->db);
        $result = AP_Forum_Guard::evaluate([
            'type' => 'topic',
            'content' => 'hello',
            'title' => 'hi',
            'poster_id' => $this->userId,
        ], $this->db);
        $this->assertTrue($result['allowed']);
        $this->assertSame(1, $result['approved']);
        $this->assertSame(AP_Forum_Guard::STATUS_OK, $result['code']);

        $this->assertTrue(function_exists('ap_forum_is_flooding'));
        $this->assertFalse(ap_forum_is_flooding($this->userId, '', $this->db));
        $this->assertTrue(function_exists('ap_forum_guard_evaluate'));
        $eval = ap_forum_guard_evaluate([
            'content' => 'x',
            'poster_id' => $this->userId,
        ], $this->db);
        $this->assertTrue($eval['allowed'] ?? false);

        $this->assertTrue(function_exists('ap_get_pending_topics'));
        $this->assertTrue(function_exists('ap_approve_topic'));
        $this->assertTrue(function_exists('ap_register_forum_spam_checker'));
    }

    public function testGuardSkippedWhenCheckGuardFalse(): void
    {
        AP_Options::update(AP_Forum_Guard::OPTION_FLOOD_INTERVAL, '999', $this->db);
        AP_Options::update(AP_Forum_Guard::OPTION_SPAM_BLACKLIST, 'banword', $this->db);

        // Without check_guard, create succeeds even with blacklist + flood settings.
        $id = AP_Forum::createTopic([
            'forum_id' => $this->forumId,
            'topic_title' => 'banword title',
            'content' => 'body',
            'poster_id' => $this->userId,
        ], $this->db, ['check_guard' => false]);
        $this->assertGreaterThan(0, $id);

        $id2 = AP_Forum::createTopic([
            'forum_id' => $this->forumId,
            'topic_title' => 'banword again',
            'content' => 'body2',
            'poster_id' => $this->userId,
        ], $this->db);
        $this->assertGreaterThan(0, $id2);
    }
}
