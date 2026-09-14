<?php

/**
 * Tests: approved reply POST enqueues topic_id + reply_post_id, no SMTP.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Forum;

use AP_Cron;
use AP_DB;
use AP_Forum;
use AP_Forum_Front;
use AP_Forum_Guard;
use AP_Forum_Moderation;
use AP_Forum_Notify;
use AP_Forum_Permissions;
use AP_Group;
use AP_Mail;
use AP_Migrator;
use AP_Nonce;
use AP_Options;
use AP_Roles;
use AP_Session;
use AP_User;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_Forum_Notify::class)]
#[CoversClass(AP_Forum_Front::class)]
final class ForumNotifyEnqueueTest extends TestCase
{
    private string $root;

    private AP_DB $db;

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
        require_once $this->root . '/ap-includes/class-ap-nonce.php';
        require_once $this->root . '/ap-includes/class-ap-group.php';
        require_once $this->root . '/ap-includes/class-ap-forum-permissions.php';
        require_once $this->root . '/ap-includes/class-ap-forum.php';
        require_once $this->root . '/ap-includes/class-ap-forum-moderation.php';
        require_once $this->root . '/ap-includes/class-ap-forum-guard.php';
        require_once $this->root . '/ap-includes/class-ap-forum-notify.php';
        require_once $this->root . '/ap-includes/class-ap-forum-front.php';
        require_once $this->root . '/ap-includes/class-ap-cron.php';
        require_once $this->root . '/ap-includes/class-ap-mail.php';
        require_once $this->root . '/ap-includes/hooks.php';
        require_once $this->root . '/ap-includes/functions.php';

        if (!defined('AP_NONCE_KEY')) {
            define('AP_NONCE_KEY', 'test-nonce-key');
        }
        if (!defined('AP_NONCE_SALT')) {
            define('AP_NONCE_SALT', 'test-nonce-salt');
        }
        if (!defined('AP_LOGGED_IN_KEY')) {
            define('AP_LOGGED_IN_KEY', 'test-logged-in-key');
        }
        if (!defined('AP_LOGGED_IN_SALT')) {
            define('AP_LOGGED_IN_SALT', 'test-logged-in-salt');
        }
        if (!defined('AP_AUTH_KEY')) {
            define('AP_AUTH_KEY', 'test-auth-key');
        }
        if (!defined('AP_AUTH_SALT')) {
            define('AP_AUTH_SALT', 'test-auth-salt');
        }

        if (function_exists('ap_reset_hooks')) {
            ap_reset_hooks();
        }
        AP_Options::flushCache();
        AP_Roles::flushCache();
        AP_Group::flushCache();
        AP_Forum_Permissions::flushCache();
        AP_Cron::reset();
        AP_Mail::resetForTests();
        AP_Mail::enableTestMode();
        AP_Session::enableTestMode();
        AP_Session::resetCurrentUser();
        AP_Forum_Front::setNotice(null);

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
        AP_Group::ensureSystemGroups($this->db);
        AP_Forum_Permissions::ensureDefaults($this->db);

        foreach (
            [
                'home' => 'https://example.com',
                'siteurl' => 'https://example.com',
                'blogname' => 'Notify Enqueue Site',
                'ap_module_forum' => '1',
            ] as $name => $value
        ) {
            $this->db->insert('options', [
                'option_name' => $name,
                'option_value' => $value,
                'autoload' => 'yes',
            ]);
        }

        $this->adminId = $this->createUser('enqueue-admin', 'administrator');
        AP_Options::update(AP_Forum_Guard::OPTION_FLOOD_INTERVAL, '0', $this->db);
        AP_Forum_Notify::registerHooks();
    }

    protected function tearDown(): void
    {
        AP_Session::resetCurrentUser();
        AP_Session::disableTestMode();
        AP_Options::flushCache();
        AP_Roles::flushCache();
        AP_Group::flushCache();
        AP_Forum_Permissions::flushCache();
        AP_Cron::reset();
        AP_Mail::resetForTests();
        AP_Forum_Front::setNotice(null);
        if (class_exists('AP_Forum_Guard', false)) {
            AP_Forum_Guard::resetSpamCheckers();
        }
        unset($GLOBALS['apdb']);
        if (function_exists('ap_reset_hooks')) {
            ap_reset_hooks();
        }
    }

    public function testApprovedReplyPostEnqueuesTopicAndReplyIdsWithoutMail(): void
    {
        AP_Options::update(AP_Forum_Notify::OPTION_ENABLED, '1', $this->db);
        [$topicId] = $this->openTopic();
        $watcher = $this->createUser('enqueue-watcher', 'subscriber');
        $this->assertTrue(AP_Forum_Notify::setUserNotifyEnabled($watcher, '1', $this->db));
        $this->assertTrue(AP_Forum_Notify::subscribe($watcher, $topicId, $this->db));
        $second = $this->createUser('enqueue-watcher-2', 'subscriber');
        $this->assertTrue(AP_Forum_Notify::setUserNotifyEnabled($second, '1', $this->db));
        $this->assertTrue(AP_Forum_Notify::subscribe($second, $topicId, $this->db));

        $fired = 0;
        ap_add_action(
            AP_Forum_Notify::CRON_HOOK,
            static function () use (&$fired): void {
                $fired++;
                AP_Mail::send('watcher@example.com', 'must not send in POST', 'body');
            },
            10,
            2
        );

        $this->assertTrue(AP_Session::setAuthCookie($this->adminId, false, $this->db));
        $redirect = AP_Forum_Front::handlePost([
            'ap_forum_action' => AP_Forum_Front::ACTION_REPLY,
            'topic_id' => $topicId,
            'reply_body' => 'An approved reply.',
            '_ap_nonce' => AP_Nonce::create('ap_forum_reply_' . $topicId, $this->adminId),
        ], $this->db);

        $this->assertIsString($redirect);
        $this->assertStringContainsString('ap_forum_notice=reply_posted', (string) $redirect);
        $this->assertMatchesRegularExpression('/#post-(\d+)/', (string) $redirect);
        preg_match('/#post-(\d+)/', (string) $redirect, $m);
        $replyId = (int) ($m[1] ?? 0);
        $this->assertGreaterThan(0, $replyId);

        $scheduled = AP_Cron::nextScheduled(
            AP_Forum_Notify::CRON_HOOK,
            [$topicId, $replyId],
            $this->db
        );
        $this->assertIsInt($scheduled);
        $this->assertSame(1, $this->notifyCronCount());
        $this->assertSame(0, $fired, 'Reply POST must not spawn cron / send mail');
        $this->assertSame(0, ap_did_action(AP_Forum_Notify::CRON_HOOK));
        $this->assertSame([], AP_Mail::getTestOutbox());
        $this->assertFalse(
            str_contains((string) $redirect, 'ap_forum_notice=reply_posted_email_on'),
            'Unchecked compose must not auto-watch'
        );
    }

    public function testSiteOffReplyPostDoesNotEnqueue(): void
    {
        $this->assertFalse(AP_Forum_Notify::isEnabled($this->db));
        [$topicId] = $this->openTopic();
        $this->assertTrue(AP_Session::setAuthCookie($this->adminId, false, $this->db));

        $redirect = AP_Forum_Front::handlePost([
            'ap_forum_action' => AP_Forum_Front::ACTION_REPLY,
            'topic_id' => $topicId,
            'reply_body' => 'Site master is off.',
            '_ap_nonce' => AP_Nonce::create('ap_forum_reply_' . $topicId, $this->adminId),
        ], $this->db);

        $this->assertIsString($redirect);
        $this->assertSame(0, $this->notifyCronCount());
        $this->assertSame([], AP_Mail::getTestOutbox());
    }

    public function testNewTopicPostDoesNotEnqueue(): void
    {
        AP_Options::update(AP_Forum_Notify::OPTION_ENABLED, '1', $this->db);
        $forumId = AP_Forum::insertForum(['forum_name' => 'Start no enqueue'], $this->db);
        $this->assertTrue(AP_Session::setAuthCookie($this->adminId, false, $this->db));

        $redirect = AP_Forum_Front::handlePost([
            'ap_forum_action' => AP_Forum_Front::ACTION_NEW_TOPIC,
            'forum_id' => $forumId,
            'topic_title' => 'Starter is not a reply',
            'topic_body' => 'First post must not enqueue notify.',
            '_ap_nonce' => AP_Nonce::create('ap_forum_new_topic_' . $forumId, $this->adminId),
        ], $this->db);

        $this->assertIsString($redirect);
        $this->assertSame(0, $this->notifyCronCount());
        $this->assertSame([], AP_Mail::getTestOutbox());
    }

    public function testCreateReplyBypassingFrontDoesNotEnqueue(): void
    {
        AP_Options::update(AP_Forum_Notify::OPTION_ENABLED, '1', $this->db);
        [$topicId] = $this->openTopic();
        $replyId = AP_Forum::createReply([
            'topic_id' => $topicId,
            'content' => 'Importer / model path.',
            'poster_id' => $this->adminId,
        ], $this->db);
        $this->assertGreaterThan(0, $replyId);
        $this->assertSame(0, $this->notifyCronCount());
        $this->assertFalse(
            AP_Cron::nextScheduled(AP_Forum_Notify::CRON_HOOK, [$topicId, $replyId], $this->db)
        );
        $this->assertSame([], AP_Mail::getTestOutbox());
    }

    public function testPendingReplyPostDoesNotEnqueueUntilApproved(): void
    {
        AP_Options::update(AP_Forum_Notify::OPTION_ENABLED, '1', $this->db);
        AP_Options::update(AP_Forum_Guard::OPTION_REQUIRE_APPROVAL, '1', $this->db);
        AP_Options::update(AP_Forum_Guard::OPTION_FLOOD_INTERVAL, '0', $this->db);

        [$topicId] = $this->openTopic();
        $memberId = $this->createUser('enqueue-pending', 'subscriber');
        $this->assertTrue(AP_Session::setAuthCookie($memberId, false, $this->db));

        $redirect = AP_Forum_Front::handlePost([
            'ap_forum_action' => AP_Forum_Front::ACTION_REPLY,
            'topic_id' => $topicId,
            'reply_body' => 'Hold for moderation.',
            '_ap_nonce' => AP_Nonce::create('ap_forum_reply_' . $topicId, $memberId),
        ], $this->db);

        $this->assertIsString($redirect);
        $this->assertStringContainsString('ap_forum_notice=reply_pending', (string) $redirect);

        $posts = AP_Forum::getPosts($topicId, ['approved_only' => false], $this->db);
        $this->assertCount(2, $posts);
        $reply = $posts[1];
        $replyId = (int) $reply->post_id;
        $this->assertSame(0, (int) $reply->post_approved);
        $this->assertSame(0, $this->notifyCronCount());
        $this->assertSame([], AP_Mail::getTestOutbox());

        $fired = 0;
        ap_add_action(
            AP_Forum_Notify::CRON_HOOK,
            static function () use (&$fired): void {
                $fired++;
                AP_Mail::send('watcher@example.com', 'must not send on approve', 'body');
            },
            10,
            2
        );

        $this->assertTrue(AP_Forum_Moderation::approvePost($replyId, $this->adminId, $this->db));
        $this->assertNotFalse(
            AP_Cron::nextScheduled(AP_Forum_Notify::CRON_HOOK, [$topicId, $replyId], $this->db)
        );
        $this->assertSame(1, $this->notifyCronCount());
        $this->assertSame(0, $fired, 'Approval must enqueue, not spawn cron / send mail');
        $this->assertSame(0, ap_did_action(AP_Forum_Notify::CRON_HOOK));
        $this->assertSame([], AP_Mail::getTestOutbox());
    }

    public function testEnqueueReplyDoesNotSpawnCron(): void
    {
        AP_Options::update(AP_Forum_Notify::OPTION_ENABLED, '1', $this->db);
        $fired = 0;
        ap_add_action(
            AP_Forum_Notify::CRON_HOOK,
            static function () use (&$fired): void {
                $fired++;
            },
            10,
            2
        );

        $this->assertTrue(AP_Forum_Notify::enqueueReply(12, 34, $this->db));
        $this->assertTrue(ap_forum_notify_enqueue_reply(12, 34, $this->db));
        $this->assertIsInt(
            AP_Cron::nextScheduled(AP_Forum_Notify::CRON_HOOK, [12, 34], $this->db)
        );
        $this->assertSame(1, $this->notifyCronCount());
        $this->assertSame(0, $fired);
        $this->assertSame(0, ap_did_action(AP_Forum_Notify::CRON_HOOK));
        $this->assertSame([], AP_Mail::getTestOutbox());
    }

    public function testFailedReplyPostDoesNotEnqueue(): void
    {
        AP_Options::update(AP_Forum_Notify::OPTION_ENABLED, '1', $this->db);
        [$topicId] = $this->openTopic();
        $this->assertTrue(AP_Session::setAuthCookie($this->adminId, false, $this->db));

        $redirect = AP_Forum_Front::handlePost([
            'ap_forum_action' => AP_Forum_Front::ACTION_REPLY,
            'topic_id' => $topicId,
            'reply_body' => '',
            '_ap_nonce' => AP_Nonce::create('ap_forum_reply_' . $topicId, $this->adminId),
        ], $this->db);

        $this->assertNull($redirect);
        $this->assertSame(0, $this->notifyCronCount());
        $this->assertSame([], AP_Mail::getTestOutbox());
    }

    public function testTwoApprovedRepliesEnqueueSeparateEvents(): void
    {
        AP_Options::update(AP_Forum_Notify::OPTION_ENABLED, '1', $this->db);
        [$topicId] = $this->openTopic();
        $this->assertTrue(AP_Session::setAuthCookie($this->adminId, false, $this->db));

        $first = AP_Forum_Front::handlePost([
            'ap_forum_action' => AP_Forum_Front::ACTION_REPLY,
            'topic_id' => $topicId,
            'reply_body' => 'First approved reply.',
            '_ap_nonce' => AP_Nonce::create('ap_forum_reply_' . $topicId, $this->adminId),
        ], $this->db);
        $second = AP_Forum_Front::handlePost([
            'ap_forum_action' => AP_Forum_Front::ACTION_REPLY,
            'topic_id' => $topicId,
            'reply_body' => 'Second approved reply.',
            '_ap_nonce' => AP_Nonce::create('ap_forum_reply_' . $topicId, $this->adminId),
        ], $this->db);

        $this->assertIsString($first);
        $this->assertIsString($second);
        preg_match('/#post-(\d+)/', (string) $first, $a);
        preg_match('/#post-(\d+)/', (string) $second, $b);
        $idA = (int) ($a[1] ?? 0);
        $idB = (int) ($b[1] ?? 0);
        $this->assertGreaterThan(0, $idA);
        $this->assertGreaterThan(0, $idB);
        $this->assertNotSame($idA, $idB);
        $this->assertNotFalse(
            AP_Cron::nextScheduled(AP_Forum_Notify::CRON_HOOK, [$topicId, $idA], $this->db)
        );
        $this->assertNotFalse(
            AP_Cron::nextScheduled(AP_Forum_Notify::CRON_HOOK, [$topicId, $idB], $this->db)
        );
        $this->assertSame(2, $this->notifyCronCount());
        $this->assertSame([], AP_Mail::getTestOutbox());
    }

    public function testMaybeEnqueueApprovedReplyRejectsUnapprovedAndFirstPost(): void
    {
        AP_Options::update(AP_Forum_Notify::OPTION_ENABLED, '1', $this->db);
        [$topicId, $firstPostId] = $this->openTopic();
        $topic = AP_Forum::getTopic($topicId, $this->db);
        $this->assertNotNull($topic);

        $this->assertFalse(AP_Forum_Notify::maybeEnqueueApprovedReply(null, $this->db));
        $this->assertFalse(ap_forum_notify_maybe_enqueue_approved_reply(null, $this->db));
        $this->assertFalse(AP_Forum_Notify::maybeEnqueueApprovedReply((object) [
            'post_id' => $firstPostId,
            'topic_id' => $topicId,
            'post_approved' => 1,
        ], $this->db));

        $replyId = AP_Forum::createReply([
            'topic_id' => $topicId,
            'content' => 'Pending row.',
            'poster_id' => $this->adminId,
            'post_approved' => 0,
        ], $this->db);
        $pending = AP_Forum::getPost($replyId, $this->db);
        $this->assertNotNull($pending);
        $this->assertFalse(AP_Forum_Notify::maybeEnqueueApprovedReply($pending, $this->db));
        $this->assertSame(0, $this->notifyCronCount());

        $this->assertTrue(AP_Forum::updatePost($replyId, ['post_approved' => 1], $this->db));
        $approved = AP_Forum::getPost($replyId, $this->db);
        $this->assertNotNull($approved);
        // updatePost already fired the approved hook (registerHooks in setUp).
        $this->assertNotFalse(
            AP_Cron::nextScheduled(AP_Forum_Notify::CRON_HOOK, [$topicId, $replyId], $this->db)
        );
        $this->assertSame([], AP_Mail::getTestOutbox());
    }

    /**
     * @return array{0: int, 1: int} topic_id, first_post_id
     */
    private function openTopic(): array
    {
        $forumId = AP_Forum::insertForum(['forum_name' => 'Enqueue board'], $this->db);
        $topicId = AP_Forum::createTopic([
            'forum_id' => $forumId,
            'topic_title' => 'Enqueue thread',
            'content' => 'Original post.',
            'poster_id' => $this->adminId,
        ], $this->db);
        $this->assertGreaterThan(0, $topicId);
        $topic = AP_Forum::getTopic($topicId, $this->db);
        $this->assertNotNull($topic);

        return [$topicId, (int) $topic->first_post_id];
    }

    private function createUser(string $login, string $role): int
    {
        $created = AP_User::create([
            'user_login' => $login,
            'user_email' => $login . '@example.com',
            'user_pass' => 'securepass99',
            'display_name' => $login,
            'role' => $role,
        ], $this->db);
        $this->assertTrue($created['ok'] ?? false, implode('; ', $created['errors'] ?? ['create failed']));
        $id = (int) ($created['id'] ?? 0);
        $this->assertGreaterThan(0, $id);

        return $id;
    }

    private function notifyCronCount(): int
    {
        $cron = AP_Cron::getCronArray($this->db);
        $n = 0;
        foreach ($cron as $ts => $hooks) {
            if ($ts === 'version' || !is_array($hooks)) {
                continue;
            }
            if (!isset($hooks[AP_Forum_Notify::CRON_HOOK]) || !is_array($hooks[AP_Forum_Notify::CRON_HOOK])) {
                continue;
            }
            $n += count($hooks[AP_Forum_Notify::CRON_HOOK]);
        }

        return $n;
    }
}
