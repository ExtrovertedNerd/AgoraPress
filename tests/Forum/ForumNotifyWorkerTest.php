<?php

/**
 * Tests: topic-notify cron worker filters recipients and sends text/plain mail.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Forum;

use AP_Content_Format;
use AP_Cron;
use AP_DB;
use AP_Forum;
use AP_Forum_Notify;
use AP_Forum_Permissions;
use AP_Group;
use AP_Mail;
use AP_Migrator;
use AP_Options;
use AP_Rate_Limit;
use AP_Roles;
use AP_Session;
use AP_Transient;
use AP_User;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_Forum_Notify::class)]
final class ForumNotifyWorkerTest extends TestCase
{
    private string $root;

    private AP_DB $db;

    private int $adminId = 0;

    private int $forumId = 0;

    private int $topicId = 0;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        require_once $this->root . '/ap-includes/version.php';
        require_once $this->root . '/ap-includes/class-ap-db.php';
        require_once $this->root . '/ap-includes/class-ap-migrator.php';
        require_once $this->root . '/ap-includes/class-ap-options.php';
        require_once $this->root . '/ap-includes/class-ap-transient.php';
        require_once $this->root . '/ap-includes/class-ap-user.php';
        require_once $this->root . '/ap-includes/class-ap-roles.php';
        require_once $this->root . '/ap-includes/class-ap-session.php';
        require_once $this->root . '/ap-includes/class-ap-group.php';
        require_once $this->root . '/ap-includes/class-ap-forum-permissions.php';
        require_once $this->root . '/ap-includes/class-ap-forum.php';
        require_once $this->root . '/ap-includes/class-ap-forum-notify.php';
        require_once $this->root . '/ap-includes/class-ap-content-format.php';
        require_once $this->root . '/ap-includes/class-ap-cron.php';
        require_once $this->root . '/ap-includes/class-ap-mail.php';
        require_once $this->root . '/ap-includes/class-ap-rate-limit.php';
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
        AP_Rate_Limit::resetTestState();
        AP_Forum_Notify::resetRateBucketForTests();
        AP_Session::enableTestMode();
        AP_Session::resetCurrentUser();

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
                'blogname' => 'Notify Worker Site',
                'ap_module_forum' => '1',
            ] as $name => $value
        ) {
            $this->db->insert('options', [
                'option_name' => $name,
                'option_value' => $value,
                'autoload' => 'yes',
            ]);
        }

        $this->adminId = $this->createUser('worker-admin', 'administrator');
        $this->forumId = AP_Forum::insertForum(['forum_name' => 'Worker board'], $this->db);
        $this->assertGreaterThan(0, $this->forumId);
        $this->assertTrue(AP_Forum_Permissions::applyAccessLevel(
            $this->forumId,
            AP_Forum_Permissions::ACCESS_PUBLIC,
            $this->db
        ));
        $this->topicId = AP_Forum::createTopic([
            'forum_id' => $this->forumId,
            'topic_title' => 'Worker thread',
            'content' => 'Original post.',
            'poster_id' => $this->adminId,
        ], $this->db);
        $this->assertGreaterThan(0, $this->topicId);

        AP_Options::update(AP_Forum_Notify::OPTION_ENABLED, '1', $this->db);
        AP_Forum_Notify::resetRateBucketForTests($this->db);
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
        AP_Rate_Limit::resetTestState();
        AP_Forum_Notify::resetRateBucketForTests($this->db);
        unset($GLOBALS['apdb']);
        if (function_exists('ap_reset_hooks')) {
            ap_reset_hooks();
        }
    }

    public function testWorkerSendsPlainTextWithSignedUnsubscribeAndDropsIneligible(): void
    {
        $eligible = $this->createUser('worker-ok', 'subscriber');
        $this->watch($eligible);

        $poster = $this->adminId;
        $this->watch($poster);

        $metaOff = $this->createUser('worker-meta-off', 'subscriber');
        $this->assertTrue(AP_Forum_Notify::subscribe($metaOff, $this->topicId, $this->db));
        $this->assertFalse(AP_Forum_Notify::isUserNotifyEnabled($metaOff, $this->db));

        $bad = $this->createUser('worker-bad-addr', 'subscriber');
        $this->watch($bad);
        $this->assertNotFalse($this->db->update(
            'users',
            ['user_email' => 'not-an-email'],
            ['ID' => $bad]
        ));

        $empty = $this->createUser('worker-empty-addr', 'subscriber');
        $this->watch($empty);
        $this->assertNotFalse($this->db->update(
            'users',
            ['user_email' => ''],
            ['ID' => $empty]
        ));

        $this->assertFalse(AP_Forum_Notify::isEligibleRecipient(
            $poster,
            $this->forumId,
            $poster,
            $this->db
        ));
        $this->assertFalse(AP_Forum_Notify::isEligibleRecipient(
            $metaOff,
            $this->forumId,
            $poster,
            $this->db
        ));
        $this->assertFalse(AP_Forum_Notify::isEligibleRecipient(
            $bad,
            $this->forumId,
            $poster,
            $this->db
        ));
        $this->assertFalse(AP_Forum_Notify::isEligibleRecipient(
            $empty,
            $this->forumId,
            $poster,
            $this->db
        ));
        $this->assertTrue(AP_Forum_Notify::isEligibleRecipient(
            $eligible,
            $this->forumId,
            $poster,
            $this->db
        ));
        $this->assertTrue(ap_forum_notify_is_eligible_recipient(
            $eligible,
            $this->forumId,
            $poster,
            $this->db
        ));
        $this->assertSame('', AP_Forum_Notify::usableRecipientEmail($bad, $this->db));
        $this->assertSame('', AP_Forum_Notify::usableRecipientEmail($empty, $this->db));
        $this->assertSame(
            'worker-ok@example.com',
            AP_Forum_Notify::usableRecipientEmail($eligible, $this->db)
        );

        $replyId = $this->replyWith(
            'Hello [spoiler]secret plot[/spoiler] world'
        );

        $sent = AP_Forum_Notify::processQueuedReply($this->topicId, $replyId, $this->db);
        $this->assertSame(1, $sent);
        $this->assertSame(1, ap_forum_notify_process_queued_reply($this->topicId, $replyId, $this->db));

        $outbox = AP_Mail::getTestOutbox();
        $this->assertCount(2, $outbox);
        foreach ($outbox as $mail) {
            $this->assertSame('worker-ok@example.com', $mail['to']);
            $this->assertSame(
                '[Notify Worker Site] New reply in Worker thread',
                $mail['subject']
            );
            $this->assertStringContainsString('text/plain', $mail['headers']);
            $this->assertStringContainsString('Worker thread', $mail['message']);
            $this->assertStringContainsString('worker-admin posted a reply.', $mail['message']);
            $this->assertStringContainsString('https://example.com', $mail['message']);
            $this->assertStringContainsString(AP_Forum_Notify::QUERY_UNSUBSCRIBE, $mail['message']);
            $this->assertStringContainsString('[Spoiler]', $mail['message']);
            $this->assertStringNotContainsString('secret plot', $mail['message']);
            $this->assertStringContainsString('no sign-in required', $mail['message']);
            $this->assertSame(1, preg_match(
                '/' . preg_quote(AP_Forum_Notify::QUERY_UNSUBSCRIBE, '/') . '=([A-Za-z0-9_-]+)/',
                $mail['message'],
                $m
            ));
            $parsed = AP_Forum_Notify::parseUnsubscribeToken(rawurldecode($m[1]));
            $this->assertNotNull($parsed);
            $this->assertSame($eligible, $parsed['user_id']);
            $this->assertSame($this->topicId, $parsed['topic_id']);
        }

        $this->assertTrue(AP_Forum_Notify::isSubscribed($poster, $this->topicId, $this->db));
        $this->assertTrue(AP_Forum_Notify::isSubscribed($metaOff, $this->topicId, $this->db));
        $this->assertTrue(AP_Forum_Notify::isSubscribed($bad, $this->topicId, $this->db));
        $this->assertTrue(AP_Forum_Notify::isSubscribed($empty, $this->topicId, $this->db));
        $this->assertTrue(AP_Forum_Notify::isSubscribed($eligible, $this->topicId, $this->db));
        $this->assertCount(5, AP_Forum_Notify::listForTopic($this->topicId, $this->db));
    }

    public function testWorkerDropsLostViewForum(): void
    {
        $lost = $this->createUser('worker-lost-view', 'subscriber');
        $this->watch($lost);
        $this->assertTrue(AP_Forum_Permissions::userCanViewForum($lost, $this->forumId, $this->db));

        $this->assertTrue(AP_Forum_Permissions::applyAccessLevel(
            $this->forumId,
            AP_Forum_Permissions::ACCESS_MODERATORS,
            $this->db
        ));
        AP_Forum_Permissions::flushCache();
        $this->assertFalse(AP_Forum_Permissions::userCanViewForum($lost, $this->forumId, $this->db));
        $this->assertFalse(AP_Forum_Notify::isEligibleRecipient(
            $lost,
            $this->forumId,
            $this->adminId,
            $this->db
        ));

        $replyId = $this->replyWith('Staff only now.');
        $this->assertSame(0, AP_Forum_Notify::processQueuedReply($this->topicId, $replyId, $this->db));
        $this->assertSame([], AP_Mail::getTestOutbox());
        $this->assertTrue(AP_Forum_Notify::isSubscribed($lost, $this->topicId, $this->db));
    }

    public function testSiteOffWorkerDoesNotSend(): void
    {
        $watcher = $this->createUser('worker-site-off', 'subscriber');
        $this->watch($watcher);
        $replyId = $this->replyWith('Site master off.');

        AP_Options::update(AP_Forum_Notify::OPTION_ENABLED, '0', $this->db);
        $this->assertSame(0, AP_Forum_Notify::processQueuedReply($this->topicId, $replyId, $this->db));
        $this->assertSame([], AP_Mail::getTestOutbox());
        $this->assertTrue(AP_Forum_Notify::isSubscribed($watcher, $this->topicId, $this->db));
    }

    public function testCronHookSendsWithoutConsumingRateLimitMail(): void
    {
        $watcher = $this->createUser('worker-cron', 'subscriber');
        $this->watch($watcher);
        $replyId = $this->replyWith('Queued then cron.');

        // Notify must not consume rate_limit_mail (verification / reset / test).
        AP_Rate_Limit::setTestLimits('mail', ['max' => 1, 'window' => 600, 'lockout' => 120]);
        $this->assertTrue(AP_Mail::send('other@example.com', 'Fill quota', 'Body'));
        $this->assertFalse(AP_Mail::send('worker-cron@example.com', 'Blocked', 'Body'));
        AP_Mail::clearTestOutbox();

        $this->assertTrue(AP_Forum_Notify::enqueueReply($this->topicId, $replyId, $this->db));
        $this->assertSame([], AP_Mail::getTestOutbox());

        $fired = AP_Cron::runDue($this->db, time() + 1);
        $this->assertGreaterThanOrEqual(1, $fired);

        $outbox = AP_Mail::getTestOutbox();
        $this->assertCount(1, $outbox);
        $this->assertSame('worker-cron@example.com', $outbox[0]['to']);
        $this->assertStringContainsString('text/plain', $outbox[0]['headers']);

        $mailLimits = AP_Rate_Limit::getLimits(AP_Rate_Limit::ACTION_MAIL, $this->db);
        $this->assertSame(1, $mailLimits['max']);
        $identity = AP_Rate_Limit::check(
            AP_Rate_Limit::ACTION_MAIL,
            AP_Rate_Limit::identityBucket('worker-cron@example.com'),
            $this->db
        );
        $this->assertSame(0, $identity['attempts']);
        $this->assertSame(1, $identity['remaining']);
    }

    public function testWorkerHonorsMaxPerMinuteWithoutConsumingRateLimitMail(): void
    {
        AP_Options::update(AP_Forum_Notify::OPTION_MAX_PER_MINUTE, '2', $this->db);
        AP_Forum_Notify::resetRateBucketForTests($this->db);
        $this->assertSame(2, AP_Forum_Notify::getMaxPerMinute($this->db));
        $this->assertSame(2, AP_Forum_Notify::remainingSends($this->db));

        $first = $this->createUser('worker-cap-a', 'subscriber');
        $second = $this->createUser('worker-cap-b', 'subscriber');
        $third = $this->createUser('worker-cap-c', 'subscriber');
        $this->watch($first);
        $this->watch($second);
        $this->watch($third);

        AP_Rate_Limit::setTestLimits('mail', ['max' => 1, 'window' => 600, 'lockout' => 120]);
        $this->assertTrue(AP_Mail::send('other@example.com', 'Fill quota', 'Body'));
        $this->assertFalse(AP_Mail::send('blocked@example.com', 'Blocked', 'Body'));
        $ipBefore = AP_Rate_Limit::check(
            AP_Rate_Limit::ACTION_MAIL,
            AP_Rate_Limit::ipBucket(),
            $this->db
        );
        $this->assertFalse($ipBefore['allowed']);
        AP_Mail::clearTestOutbox();

        $replyId = $this->replyWith('Cap the notify bucket.');
        $sent = AP_Forum_Notify::processQueuedReply($this->topicId, $replyId, $this->db);
        $this->assertSame(2, $sent);
        $this->assertSame(0, AP_Forum_Notify::remainingSends($this->db));
        $this->assertSame(0, ap_forum_notify_remaining_sends($this->db));

        $outbox = AP_Mail::getTestOutbox();
        $this->assertCount(2, $outbox);
        $recipients = array_column($outbox, 'to');
        $this->assertContains('worker-cap-a@example.com', $recipients);
        $this->assertContains('worker-cap-b@example.com', $recipients);
        $this->assertNotContains('worker-cap-c@example.com', $recipients);

        $this->assertTrue(AP_Forum_Notify::isSubscribed($first, $this->topicId, $this->db));
        $this->assertTrue(AP_Forum_Notify::isSubscribed($second, $this->topicId, $this->db));
        $this->assertTrue(AP_Forum_Notify::isSubscribed($third, $this->topicId, $this->db));

        $stored = AP_Transient::get(AP_Forum_Notify::RATE_BUCKET_TRANSIENT, false, $this->db);
        $this->assertIsArray($stored);
        $this->assertSame(2, (int) ($stored['count'] ?? 0));
        $this->assertStringStartsNotWith('ap_rl_', AP_Forum_Notify::RATE_BUCKET_TRANSIENT);

        AP_Forum_Notify::forgetInMemoryRateBucketForTests();
        $this->assertSame(0, AP_Forum_Notify::remainingSends($this->db));

        foreach (
            [
                'worker-cap-a@example.com',
                'worker-cap-b@example.com',
                'worker-cap-c@example.com',
            ] as $email
        ) {
            $identity = AP_Rate_Limit::check(
                AP_Rate_Limit::ACTION_MAIL,
                AP_Rate_Limit::identityBucket($email),
                $this->db
            );
            $this->assertSame(0, $identity['attempts']);
            $this->assertSame(1, $identity['remaining']);
        }

        $ipAfter = AP_Rate_Limit::check(
            AP_Rate_Limit::ACTION_MAIL,
            AP_Rate_Limit::ipBucket(),
            $this->db
        );
        $this->assertSame($ipBefore['attempts'], $ipAfter['attempts']);
        $this->assertFalse($ipAfter['allowed']);
        $this->assertFalse(AP_Mail::send('another@example.com', 'Still blocked', 'Body'));

        AP_Mail::clearTestOutbox();
        $this->assertSame(0, AP_Forum_Notify::processQueuedReply($this->topicId, $replyId, $this->db));
        $this->assertSame([], AP_Mail::getTestOutbox());
        $this->assertTrue(AP_Forum_Notify::isSubscribed($third, $this->topicId, $this->db));
    }

    public function testSignedTokenUnsubscribesOneTopicWithoutSession(): void
    {
        $this->assertGreaterThanOrEqual(30 * 86400, AP_Forum_Notify::UNSUBSCRIBE_TTL);

        $user = $this->createUser('worker-unsub', 'subscriber');
        $this->watch($user);
        $other = AP_Forum::createTopic([
            'forum_id' => $this->forumId,
            'topic_title' => 'Second watch',
            'content' => 'Keep this watch.',
            'poster_id' => $this->adminId,
        ], $this->db);
        $this->assertGreaterThan(0, $other);
        $this->assertTrue(AP_Forum_Notify::subscribe($user, $other, $this->db));
        $this->assertTrue(AP_Forum_Notify::setUserNotifyEnabled($user, '1', $this->db));

        AP_Session::resetCurrentUser();
        $this->assertSame(0, AP_Session::getCurrentUserId());

        $url = AP_Forum_Notify::unsubscribeUrl($user, $this->topicId, $this->db);
        $this->assertStringContainsString('https://example.com', $url);
        $this->assertStringContainsString(AP_Forum_Notify::QUERY_UNSUBSCRIBE . '=', $url);

        $token = AP_Forum_Notify::createUnsubscribeToken($user, $this->topicId);
        $parsed = AP_Forum_Notify::parseUnsubscribeToken($token);
        $this->assertNotNull($parsed);
        $this->assertSame($user, $parsed['user_id']);
        $this->assertSame($this->topicId, $parsed['topic_id']);
        $this->assertGreaterThan(time(), $parsed['expires']);

        $redirect = AP_Forum_Notify::maybeHandleSignedUnsubscribe(
            [AP_Forum_Notify::QUERY_UNSUBSCRIBE => $token],
            $this->db
        );
        $this->assertIsString($redirect);
        $this->assertStringContainsString('ap_forum_notice=topic_unsubscribed', (string) $redirect);
        $this->assertFalse(AP_Forum_Notify::isSubscribed($user, $this->topicId, $this->db));
        $this->assertTrue(AP_Forum_Notify::isSubscribed($user, $other, $this->db));
        $this->assertTrue(AP_Forum_Notify::isUserNotifyEnabled($user, $this->db));

        $again = ap_forum_notify_handle_unsubscribe(
            [AP_Forum_Notify::QUERY_UNSUBSCRIBE => $token],
            $this->db
        );
        $this->assertIsString($again);
        $this->assertTrue(AP_Forum_Notify::isSubscribed($user, $other, $this->db));
    }

    public function testInvalidAndExpiredTokensDoNotUnsubscribe(): void
    {
        $user = $this->createUser('worker-bad-token', 'subscriber');
        $this->watch($user);

        $this->assertNull(AP_Forum_Notify::maybeHandleSignedUnsubscribe([], $this->db));
        $this->assertNull(ap_forum_notify_handle_unsubscribe([], $this->db));

        $bogus = AP_Forum_Notify::maybeHandleSignedUnsubscribe(
            [AP_Forum_Notify::QUERY_UNSUBSCRIBE => 'not-a-token'],
            $this->db
        );
        $this->assertIsString($bogus);
        $this->assertStringContainsString('topic_unsubscribe_invalid', (string) $bogus);
        $this->assertTrue(AP_Forum_Notify::isSubscribed($user, $this->topicId, $this->db));

        $expired = AP_Forum_Notify::createUnsubscribeToken(
            $user,
            $this->topicId,
            time() - AP_Forum_Notify::UNSUBSCRIBE_TTL - 10
        );
        $this->assertNotSame('', $expired);
        $this->assertNull(AP_Forum_Notify::parseUnsubscribeToken($expired));
        $expiredRedirect = AP_Forum_Notify::maybeHandleSignedUnsubscribe(
            [AP_Forum_Notify::QUERY_UNSUBSCRIBE => $expired],
            $this->db
        );
        $this->assertIsString($expiredRedirect);
        $this->assertStringContainsString('topic_unsubscribe_invalid', (string) $expiredRedirect);
        $this->assertTrue(AP_Forum_Notify::isSubscribed($user, $this->topicId, $this->db));

        $good = AP_Forum_Notify::createUnsubscribeToken($user, $this->topicId);
        $tampered = substr($good, 0, -2) . (substr($good, -2, 1) === 'A' ? 'B' : 'A') . substr($good, -1);
        $this->assertNull(AP_Forum_Notify::parseUnsubscribeToken($tampered));
        $this->assertTrue(AP_Forum_Notify::isSubscribed($user, $this->topicId, $this->db));
    }

    public function testFailedSendDoesNotDeleteSubscription(): void
    {
        $watcher = $this->createUser('worker-fail', 'subscriber');
        $this->watch($watcher);
        $replyId = $this->replyWith('Transport down.');

        AP_Mail::failNextForTests('SMTP down');
        $this->assertSame(0, AP_Forum_Notify::processQueuedReply($this->topicId, $replyId, $this->db));
        $this->assertSame([], AP_Mail::getTestOutbox());
        $this->assertTrue(AP_Forum_Notify::isSubscribed($watcher, $this->topicId, $this->db));
        $this->assertTrue(AP_Forum_Notify::isUserNotifyEnabled($watcher, $this->db));
    }

    public function testUnapprovedAndFirstPostDoNotSend(): void
    {
        $watcher = $this->createUser('worker-pending', 'subscriber');
        $this->watch($watcher);

        $topic = AP_Forum::getTopic($this->topicId, $this->db);
        $this->assertNotNull($topic);
        $firstId = (int) $topic->first_post_id;
        $this->assertSame(0, AP_Forum_Notify::processQueuedReply($this->topicId, $firstId, $this->db));

        $pendingId = AP_Forum::createReply([
            'topic_id' => $this->topicId,
            'content' => 'Hold.',
            'poster_id' => $this->adminId,
            'post_approved' => 0,
        ], $this->db);
        $this->assertGreaterThan(0, $pendingId);
        $this->assertSame(0, AP_Forum_Notify::processQueuedReply($this->topicId, $pendingId, $this->db));
        $this->assertSame([], AP_Mail::getTestOutbox());
    }

    public function testSpoilerStripHelperIsShared(): void
    {
        $this->assertSame(
            'Hello [Spoiler] world',
            trim(preg_replace(
                '/\s+/',
                ' ',
                AP_Content_Format::stripSpoilers('Hello [spoiler]secret plot[/spoiler] world')
            ) ?? '')
        );
    }

    private function watch(int $userId): void
    {
        $this->assertTrue(AP_Forum_Notify::setUserNotifyEnabled($userId, '1', $this->db));
        $this->assertTrue(AP_Forum_Notify::subscribe($userId, $this->topicId, $this->db));
    }

    private function replyWith(string $body): int
    {
        $replyId = AP_Forum::createReply([
            'topic_id' => $this->topicId,
            'content' => $body,
            'poster_id' => $this->adminId,
        ], $this->db);
        $this->assertGreaterThan(0, $replyId);

        return $replyId;
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
}
