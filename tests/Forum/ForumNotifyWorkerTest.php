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
        $this->assertFalse(AP_Forum_Permissions::userCan(
            $lost,
            $this->forumId,
            AP_Forum_Permissions::PERM_VIEW,
            $this->db
        ));
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

    public function testWorkerDropsRevokedViewForumPermission(): void
    {
        $lost = $this->createUser('worker-revoked-view', 'subscriber');
        $this->watch($lost);
        $this->assertTrue(AP_Forum_Permissions::userCan(
            $lost,
            $this->forumId,
            AP_Forum_Permissions::PERM_VIEW,
            $this->db
        ));

        $registered = AP_Group::getBySlug(AP_Group::SLUG_REGISTERED, $this->db);
        $this->assertNotNull($registered);
        $this->assertTrue(AP_Forum_Permissions::setPermission(
            $this->forumId,
            (int) $registered->group_id,
            AP_Forum_Permissions::PERM_VIEW,
            false,
            $this->db
        ));
        AP_Forum_Permissions::flushCache();
        $this->assertFalse(AP_Forum_Permissions::userCan(
            $lost,
            $this->forumId,
            AP_Forum_Permissions::PERM_VIEW,
            $this->db
        ));
        $this->assertFalse(AP_Forum_Permissions::userCanViewForum($lost, $this->forumId, $this->db));
        $this->assertFalse(AP_Forum_Notify::isEligibleRecipient(
            $lost,
            $this->forumId,
            $this->adminId,
            $this->db
        ));

        $replyId = $this->replyWith('view_forum revoked.');
        $this->assertSame(0, AP_Forum_Notify::processQueuedReply($this->topicId, $replyId, $this->db));
        $this->assertSame([], AP_Mail::getTestOutbox());
        $this->assertTrue(AP_Forum_Notify::isSubscribed($lost, $this->topicId, $this->db));
        $this->assertTrue(AP_Forum_Notify::isUserNotifyEnabled($lost, $this->db));
    }

    public function testSiteOffWorkerDoesNotSend(): void
    {
        $watcher = $this->createUser('worker-site-off', 'subscriber');
        $this->watch($watcher);
        $replyId = $this->replyWith('Site master off.');

        AP_Options::update(AP_Forum_Notify::OPTION_ENABLED, '0', $this->db);
        $this->assertFalse(AP_Forum_Notify::isEnabled($this->db));
        $remaining = AP_Forum_Notify::remainingSends($this->db);
        $this->assertSame(0, AP_Forum_Notify::processQueuedReply($this->topicId, $replyId, $this->db));
        $this->assertSame([], AP_Mail::getTestOutbox());
        $this->assertSame($remaining, AP_Forum_Notify::remainingSends($this->db));
        $this->assertTrue(AP_Forum_Notify::isSubscribed($watcher, $this->topicId, $this->db));
    }

    public function testUserOffWorkerDoesNotSend(): void
    {
        $watcher = $this->createUser('worker-user-off', 'subscriber');
        $this->assertTrue(AP_Forum_Notify::subscribe($watcher, $this->topicId, $this->db));
        $this->assertFalse(AP_Forum_Notify::isUserNotifyEnabled($watcher, $this->db));
        $this->assertTrue(AP_Forum_Permissions::userCanViewForum($watcher, $this->forumId, $this->db));
        $this->assertFalse(AP_Forum_Notify::isEligibleRecipient(
            $watcher,
            $this->forumId,
            $this->adminId,
            $this->db
        ));
        $this->assertFalse(ap_forum_notify_is_eligible_recipient(
            $watcher,
            $this->forumId,
            $this->adminId,
            $this->db
        ));

        $replyId = $this->replyWith('User master is off.');
        $this->assertSame(0, AP_Forum_Notify::processQueuedReply($this->topicId, $replyId, $this->db));
        $this->assertSame([], AP_Mail::getTestOutbox());
        $this->assertTrue(AP_Forum_Notify::isSubscribed($watcher, $this->topicId, $this->db));
        $this->assertFalse(AP_Forum_Notify::isUserNotifyEnabled($watcher, $this->db));

        $this->assertTrue(AP_Forum_Notify::setUserNotifyEnabled($watcher, '1', $this->db));
        $this->assertSame(1, AP_Forum_Notify::processQueuedReply($this->topicId, $replyId, $this->db));
        $outbox = AP_Mail::getTestOutbox();
        $this->assertCount(1, $outbox);
        $this->assertSame('worker-user-off@example.com', $outbox[0]['to']);
        $this->assertTrue(AP_Forum_Notify::isSubscribed($watcher, $this->topicId, $this->db));
    }

    public function testPosterExcludedFromWorkerMail(): void
    {
        $this->watch($this->adminId);
        $this->assertFalse(AP_Forum_Notify::isEligibleRecipient(
            $this->adminId,
            $this->forumId,
            $this->adminId,
            $this->db
        ));

        $replyId = $this->replyWith('Poster should not be mailed.');
        $this->assertSame(0, AP_Forum_Notify::processQueuedReply($this->topicId, $replyId, $this->db));
        $this->assertSame([], AP_Mail::getTestOutbox());
        $this->assertTrue(AP_Forum_Notify::isSubscribed($this->adminId, $this->topicId, $this->db));
        $this->assertTrue(AP_Forum_Notify::isUserNotifyEnabled($this->adminId, $this->db));

        $watcher = $this->createUser('worker-not-poster', 'subscriber');
        $this->watch($watcher);
        $this->assertSame(1, AP_Forum_Notify::processQueuedReply($this->topicId, $replyId, $this->db));
        $outbox = AP_Mail::getTestOutbox();
        $this->assertCount(1, $outbox);
        $this->assertSame('worker-not-poster@example.com', $outbox[0]['to']);
        $this->assertNotSame('worker-admin@example.com', $outbox[0]['to']);
        $this->assertTrue(AP_Forum_Notify::isSubscribed($this->adminId, $this->topicId, $this->db));
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

    public function testWorkerLeavesRateLimitMailAlone(): void
    {
        $watcher = $this->createUser('worker-rl-mail', 'subscriber');
        $this->watch($watcher);
        $email = 'worker-rl-mail@example.com';

        $before = $this->mailRateLimitSnapshot($email);
        $this->assertSame(0, $before['ip']['attempts']);
        $this->assertSame(0, $before['identity']['attempts']);
        $this->assertSame(4, AP_Forum_Notify::remainingSends($this->db));

        $replyId = $this->replyWith('Notify must skip rate_limit_mail.');
        $this->assertSame(1, AP_Forum_Notify::processQueuedReply($this->topicId, $replyId, $this->db));

        $outbox = AP_Mail::getTestOutbox();
        $this->assertCount(1, $outbox);
        $this->assertSame($email, $outbox[0]['to']);
        $this->assertSame(3, AP_Forum_Notify::remainingSends($this->db));
        $this->assertSame(3, ap_forum_notify_remaining_sends($this->db));

        $after = $this->mailRateLimitSnapshot($email);
        $this->assertSame($before['max'], $after['max']);
        $this->assertSame($before['window'], $after['window']);
        $this->assertSame($before['lockout'], $after['lockout']);
        $this->assertSame($before['limits'], $after['limits']);
        $this->assertSame($before['ip']['attempts'], $after['ip']['attempts']);
        $this->assertSame($before['ip']['remaining'], $after['ip']['remaining']);
        $this->assertSame($before['identity']['attempts'], $after['identity']['attempts']);
        $this->assertSame($before['identity']['remaining'], $after['identity']['remaining']);
        $this->assertTrue($after['ip']['allowed']);
        $this->assertTrue($after['identity']['allowed']);

        $stored = AP_Transient::get(AP_Forum_Notify::RATE_BUCKET_TRANSIENT, false, $this->db);
        $this->assertIsArray($stored);
        $this->assertSame(1, (int) ($stored['count'] ?? 0));
        $this->assertStringStartsNotWith('ap_rl_', AP_Forum_Notify::RATE_BUCKET_TRANSIENT);
        $this->assertStringNotContainsString('rate_limit_mail', AP_Forum_Notify::RATE_BUCKET_TRANSIENT);

        $this->assertTrue(AP_Mail::send('verify@example.com', 'Uses mail bucket', 'Body'));
        $verify = AP_Rate_Limit::check(
            AP_Rate_Limit::ACTION_MAIL,
            AP_Rate_Limit::identityBucket('verify@example.com'),
            $this->db
        );
        $this->assertSame(1, $verify['attempts']);
        $notifyIdentity = AP_Rate_Limit::check(
            AP_Rate_Limit::ACTION_MAIL,
            AP_Rate_Limit::identityBucket($email),
            $this->db
        );
        $this->assertSame(0, $notifyIdentity['attempts']);
        $this->assertTrue(AP_Forum_Notify::isSubscribed($watcher, $this->topicId, $this->db));
    }

    public function testSignedTokenUnsubscribesOneTopicWithoutSession(): void
    {
        $this->assertGreaterThanOrEqual(30 * 86400, AP_Forum_Notify::UNSUBSCRIBE_TTL);

        $user = $this->createUser('worker-unsub', 'subscriber');
        $this->watch($user);
        $sibling = $this->createUser('worker-unsub-sibling', 'subscriber');
        $this->watch($sibling);
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
        $this->assertTrue(AP_Forum_Notify::isSubscribed($sibling, $this->topicId, $this->db));
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

        $remainingBefore = AP_Forum_Notify::remainingSends($this->db);
        $this->assertSame(4, $remainingBefore);

        AP_Mail::failNextForTests('SMTP down');
        $this->assertSame(0, AP_Forum_Notify::processQueuedReply($this->topicId, $replyId, $this->db));
        $this->assertSame([], AP_Mail::getTestOutbox());
        $this->assertSame($remainingBefore, AP_Forum_Notify::remainingSends($this->db));
        $this->assertSame($remainingBefore, ap_forum_notify_remaining_sends($this->db));
        $this->assertStringContainsString('SMTP down', AP_Mail::lastError());
        $this->assertTrue(AP_Forum_Notify::isSubscribed($watcher, $this->topicId, $this->db));
        $this->assertTrue(AP_Forum_Notify::isUserNotifyEnabled($watcher, $this->db));
        $this->assertCount(1, AP_Forum_Notify::listForTopic($this->topicId, $this->db));

        // Slot was refunded, so a later worker pass can still send.
        $sent = AP_Forum_Notify::processQueuedReply($this->topicId, $replyId, $this->db);
        $this->assertSame(1, $sent);
        $this->assertSame($remainingBefore - 1, AP_Forum_Notify::remainingSends($this->db));
        $outbox = AP_Mail::getTestOutbox();
        $this->assertCount(1, $outbox);
        $this->assertSame('worker-fail@example.com', $outbox[0]['to']);
        $this->assertTrue(AP_Forum_Notify::isSubscribed($watcher, $this->topicId, $this->db));
    }

    public function testFailedSendAmongWatchersDoesNotClaimSuccessOrDropWatches(): void
    {
        $first = $this->createUser('worker-fail-a', 'subscriber');
        $second = $this->createUser('worker-fail-b', 'subscriber');
        $this->watch($first);
        $this->watch($second);
        $replyId = $this->replyWith('One transport failure.');

        AP_Mail::failNextForTests('SMTP down');
        $sent = AP_Forum_Notify::processQueuedReply($this->topicId, $replyId, $this->db);
        $this->assertSame(1, $sent);
        $this->assertSame(3, AP_Forum_Notify::remainingSends($this->db));

        $outbox = AP_Mail::getTestOutbox();
        $this->assertCount(1, $outbox);
        $this->assertContains($outbox[0]['to'], [
            'worker-fail-a@example.com',
            'worker-fail-b@example.com',
        ]);
        $this->assertTrue(AP_Forum_Notify::isSubscribed($first, $this->topicId, $this->db));
        $this->assertTrue(AP_Forum_Notify::isSubscribed($second, $this->topicId, $this->db));
        $this->assertTrue(AP_Forum_Notify::isUserNotifyEnabled($first, $this->db));
        $this->assertTrue(AP_Forum_Notify::isUserNotifyEnabled($second, $this->db));
    }

    public function testFailedDigestSendDoesNotClaimSiblingsOrDropWatch(): void
    {
        $watcher = $this->createUser('digest-fail', 'subscriber');
        $this->watch($watcher);

        $firstId = $this->replyWith('Digest first while SMTP is down.');
        $secondId = $this->replyWith('Digest second while SMTP is down.');
        $this->enqueue($firstId);
        $this->enqueue($secondId);

        AP_Mail::failNextForTests('SMTP down');
        $this->assertSame(0, AP_Forum_Notify::processQueuedReply($this->topicId, $firstId, $this->db));
        $this->assertSame([], AP_Mail::getTestOutbox());
        $this->assertSame(4, AP_Forum_Notify::remainingSends($this->db));
        $this->assertTrue(AP_Forum_Notify::isSubscribed($watcher, $this->topicId, $this->db));
        $this->assertFalse(AP_Transient::get(
            AP_Forum_Notify::DIGEST_CLAIM_TRANSIENT,
            false,
            $this->db
        ));
        $this->assertIsInt(AP_Cron::nextScheduled(
            AP_Forum_Notify::CRON_HOOK,
            [$this->topicId, $secondId],
            $this->db
        ));

        $sent = AP_Forum_Notify::processQueuedReply($this->topicId, $firstId, $this->db);
        $this->assertSame(1, $sent);
        $outbox = AP_Mail::getTestOutbox();
        $this->assertCount(1, $outbox);
        $this->assertSame(
            '[Notify Worker Site] 2 new replies in Worker thread',
            $outbox[0]['subject']
        );
        $this->assertTrue(AP_Forum_Notify::isSubscribed($watcher, $this->topicId, $this->db));
        $this->assertFalse(AP_Cron::nextScheduled(
            AP_Forum_Notify::CRON_HOOK,
            [$this->topicId, $secondId],
            $this->db
        ));
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

    public function testComposeDigestMailRequiresTwoPosts(): void
    {
        $watcher = $this->createUser('digest-compose', 'subscriber');
        $this->watch($watcher);
        $firstId = $this->replyWith('Alpha excerpt here.');
        $secondId = $this->replyWith('Bravo excerpt here.');
        $topic = AP_Forum::getTopic($this->topicId, $this->db);
        $this->assertNotNull($topic);
        $first = AP_Forum::getPost($firstId, $this->db);
        $second = AP_Forum::getPost($secondId, $this->db);
        $this->assertNotNull($first);
        $this->assertNotNull($second);

        $this->assertNull(AP_Forum_Notify::composeDigestMail($topic, [$first], $watcher, $this->db));
        $this->assertNull(AP_Forum_Notify::composeDigestMail($topic, [$first, $second], 0, $this->db));
        $this->assertNull(ap_forum_notify_compose_digest_mail($topic, [$first], $watcher, $this->db));

        $composed = AP_Forum_Notify::composeDigestMail(
            $topic,
            [$first, $second],
            $watcher,
            $this->db
        );
        $this->assertNotNull($composed);
        $this->assertSame(
            '[Notify Worker Site] 2 new replies in Worker thread',
            $composed['subject']
        );
        $this->assertStringContainsString('2 new replies.', $composed['message']);
        $this->assertStringContainsString('Alpha excerpt here.', $composed['message']);
        $this->assertStringContainsString('Bravo excerpt here.', $composed['message']);
        $this->assertStringContainsString(AP_Forum_Notify::QUERY_UNSUBSCRIBE, $composed['message']);
        $viaHelper = ap_forum_notify_compose_digest_mail(
            $topic,
            [$first, $second],
            $watcher,
            $this->db
        );
        $this->assertNotNull($viaHelper);
        $this->assertSame($composed['subject'], $viaHelper['subject']);
    }

    public function testWorkerSendsOneDigestForSeveralQueuedRepliesOnSameTopic(): void
    {
        $watcher = $this->createUser('digest-ok', 'subscriber');
        $this->watch($watcher);

        $firstId = $this->replyWith('Hello [spoiler]secret plot[/spoiler] world');
        $secondId = $this->replyWith('Second reply without spoilers.');
        $this->enqueue($firstId);
        $this->enqueue($secondId);

        $unsent = AP_Forum_Notify::unsentRepliesForTopic($this->topicId, $firstId, $this->db);
        $this->assertCount(2, $unsent);
        $this->assertCount(2, ap_forum_notify_unsent_replies_for_topic($this->topicId, $firstId, $this->db));

        $this->assertSame(4, AP_Forum_Notify::remainingSends($this->db));
        $sent = AP_Forum_Notify::processQueuedReply($this->topicId, $firstId, $this->db);
        $this->assertSame(1, $sent);
        $this->assertSame(3, AP_Forum_Notify::remainingSends($this->db));

        $outbox = AP_Mail::getTestOutbox();
        $this->assertCount(1, $outbox);
        $this->assertSame('digest-ok@example.com', $outbox[0]['to']);
        $this->assertSame(
            '[Notify Worker Site] 2 new replies in Worker thread',
            $outbox[0]['subject']
        );
        $this->assertStringContainsString('text/plain', $outbox[0]['headers']);
        $this->assertStringContainsString('2 new replies.', $outbox[0]['message']);
        $this->assertStringContainsString('worker-admin posted a reply.', $outbox[0]['message']);
        $this->assertStringContainsString('[Spoiler]', $outbox[0]['message']);
        $this->assertStringNotContainsString('secret plot', $outbox[0]['message']);
        $this->assertStringContainsString('Second reply without spoilers.', $outbox[0]['message']);
        $this->assertStringContainsString(AP_Forum_Notify::QUERY_UNSUBSCRIBE, $outbox[0]['message']);
        $this->assertStringContainsString('no sign-in required', $outbox[0]['message']);

        $this->assertFalse(AP_Cron::nextScheduled(
            AP_Forum_Notify::CRON_HOOK,
            [$this->topicId, $firstId],
            $this->db
        ));
        $this->assertFalse(AP_Cron::nextScheduled(
            AP_Forum_Notify::CRON_HOOK,
            [$this->topicId, $secondId],
            $this->db
        ));
        $this->assertSame(
            0,
            AP_Forum_Notify::processQueuedReply($this->topicId, $secondId, $this->db)
        );
        $this->assertCount(1, AP_Mail::getTestOutbox());
        $this->assertTrue(AP_Forum_Notify::isSubscribed($watcher, $this->topicId, $this->db));
    }

    public function testCronRunDueSendsOneDigestNotPerReplyMails(): void
    {
        $watcher = $this->createUser('digest-cron', 'subscriber');
        $this->watch($watcher);
        $firstId = $this->replyWith('Cron digest first.');
        $secondId = $this->replyWith('Cron digest second.');
        $this->enqueue($firstId);
        $this->enqueue($secondId);

        $fired = AP_Cron::runDue($this->db, time() + 1);
        $this->assertGreaterThanOrEqual(1, $fired);

        $outbox = AP_Mail::getTestOutbox();
        $this->assertCount(1, $outbox);
        $this->assertSame(
            '[Notify Worker Site] 2 new replies in Worker thread',
            $outbox[0]['subject']
        );
        $this->assertStringContainsString('Cron digest first.', $outbox[0]['message']);
        $this->assertStringContainsString('Cron digest second.', $outbox[0]['message']);
        $this->assertFalse(AP_Cron::nextScheduled(
            AP_Forum_Notify::CRON_HOOK,
            [$this->topicId, $firstId],
            $this->db
        ));
        $this->assertFalse(AP_Cron::nextScheduled(
            AP_Forum_Notify::CRON_HOOK,
            [$this->topicId, $secondId],
            $this->db
        ));
    }

    public function testDigestSlipFallsBackToPerReplyAndHonorsCap(): void
    {
        AP_Options::update(AP_Forum_Notify::OPTION_MAX_PER_MINUTE, '1', $this->db);
        AP_Forum_Notify::resetRateBucketForTests($this->db);
        $this->assertSame(1, AP_Forum_Notify::remainingSends($this->db));

        $first = $this->createUser('digest-slip-a', 'subscriber');
        $second = $this->createUser('digest-slip-b', 'subscriber');
        $this->watch($first);
        $this->watch($second);

        $replyId = $this->replyWith('Only this reply is still valid.');
        $siblingId = $this->replyWith('Will be unapproved so digest slips.');
        $this->enqueue($replyId);
        $this->enqueue($siblingId);
        $this->assertNotFalse($this->db->update(
            'forum_posts',
            ['post_approved' => 0],
            ['post_id' => $siblingId]
        ));

        $unsent = AP_Forum_Notify::unsentRepliesForTopic($this->topicId, $replyId, $this->db);
        $this->assertCount(1, $unsent);
        $this->assertSame($replyId, (int) ($unsent[0]->post_id ?? 0));

        $sent = AP_Forum_Notify::processQueuedReply($this->topicId, $replyId, $this->db);
        $this->assertSame(1, $sent);
        $this->assertSame(0, AP_Forum_Notify::remainingSends($this->db));

        $outbox = AP_Mail::getTestOutbox();
        $this->assertCount(1, $outbox);
        $this->assertSame(
            '[Notify Worker Site] New reply in Worker thread',
            $outbox[0]['subject']
        );
        $this->assertStringNotContainsString('new replies in', $outbox[0]['subject']);
        $this->assertContains($outbox[0]['to'], [
            'digest-slip-a@example.com',
            'digest-slip-b@example.com',
        ]);

        $this->assertTrue(AP_Forum_Notify::isSubscribed($first, $this->topicId, $this->db));
        $this->assertTrue(AP_Forum_Notify::isSubscribed($second, $this->topicId, $this->db));
        $this->assertIsInt(AP_Cron::nextScheduled(
            AP_Forum_Notify::CRON_HOOK,
            [$this->topicId, $siblingId],
            $this->db
        ));

        AP_Mail::clearTestOutbox();
        $this->assertSame(0, AP_Forum_Notify::processQueuedReply($this->topicId, $replyId, $this->db));
        $this->assertSame([], AP_Mail::getTestOutbox());
    }

    public function testDigestDoesNotMixTopics(): void
    {
        $watcher = $this->createUser('digest-mix', 'subscriber');
        $this->watch($watcher);

        $otherTopic = AP_Forum::createTopic([
            'forum_id' => $this->forumId,
            'topic_title' => 'Other thread',
            'content' => 'Stay out of the digest.',
            'poster_id' => $this->adminId,
        ], $this->db);
        $this->assertGreaterThan(0, $otherTopic);
        $this->assertTrue(AP_Forum_Notify::subscribe($watcher, $otherTopic, $this->db));

        $firstId = $this->replyWith('Topic one first.');
        $secondId = $this->replyWith('Topic one second.');
        $otherReply = AP_Forum::createReply([
            'topic_id' => $otherTopic,
            'content' => 'Other topic reply.',
            'poster_id' => $this->adminId,
        ], $this->db);
        $this->assertGreaterThan(0, $otherReply);
        $this->enqueue($firstId);
        $this->enqueue($secondId);
        $this->assertTrue(AP_Forum_Notify::enqueueReply($otherTopic, $otherReply, $this->db));

        $sent = AP_Forum_Notify::processQueuedReply($this->topicId, $firstId, $this->db);
        $this->assertSame(1, $sent);
        $outbox = AP_Mail::getTestOutbox();
        $this->assertCount(1, $outbox);
        $this->assertSame(
            '[Notify Worker Site] 2 new replies in Worker thread',
            $outbox[0]['subject']
        );
        $this->assertStringContainsString('Topic one first.', $outbox[0]['message']);
        $this->assertStringContainsString('Topic one second.', $outbox[0]['message']);
        $this->assertStringNotContainsString('Other topic reply.', $outbox[0]['message']);
        $this->assertIsInt(AP_Cron::nextScheduled(
            AP_Forum_Notify::CRON_HOOK,
            [$otherTopic, $otherReply],
            $this->db
        ));
    }

    public function testPosterOfOneReplyStillGetsDigestOfOthers(): void
    {
        $watcher = $this->createUser('digest-poster', 'subscriber');
        $this->watch($watcher);

        $firstId = $this->replyWith('From the admin.');
        $ownId = AP_Forum::createReply([
            'topic_id' => $this->topicId,
            'content' => 'Watcher posted this one.',
            'poster_id' => $watcher,
        ], $this->db);
        $this->assertGreaterThan(0, $ownId);
        $thirdId = $this->replyWith('Admin again.');
        $this->enqueue($firstId);
        $this->assertTrue(AP_Forum_Notify::enqueueReply($this->topicId, $ownId, $this->db));
        $this->enqueue($thirdId);

        $sent = AP_Forum_Notify::processQueuedReply($this->topicId, $firstId, $this->db);
        $this->assertSame(1, $sent);
        $outbox = AP_Mail::getTestOutbox();
        $this->assertCount(1, $outbox);
        $this->assertSame(
            '[Notify Worker Site] 2 new replies in Worker thread',
            $outbox[0]['subject']
        );
        $this->assertStringContainsString('From the admin.', $outbox[0]['message']);
        $this->assertStringContainsString('Admin again.', $outbox[0]['message']);
        $this->assertStringNotContainsString('Watcher posted this one.', $outbox[0]['message']);
        $this->assertTrue(AP_Forum_Notify::isSubscribed($watcher, $this->topicId, $this->db));
    }

    public function testDigestHonorsCapAcrossSubscribers(): void
    {
        AP_Options::update(AP_Forum_Notify::OPTION_MAX_PER_MINUTE, '1', $this->db);
        AP_Forum_Notify::resetRateBucketForTests($this->db);

        $first = $this->createUser('digest-cap-a', 'subscriber');
        $second = $this->createUser('digest-cap-b', 'subscriber');
        $this->watch($first);
        $this->watch($second);

        $firstId = $this->replyWith('Cap digest first.');
        $secondId = $this->replyWith('Cap digest second.');
        $this->enqueue($firstId);
        $this->enqueue($secondId);

        $sent = AP_Forum_Notify::processQueuedReply($this->topicId, $firstId, $this->db);
        $this->assertSame(1, $sent);
        $this->assertSame(0, AP_Forum_Notify::remainingSends($this->db));
        $outbox = AP_Mail::getTestOutbox();
        $this->assertCount(1, $outbox);
        $this->assertSame(
            '[Notify Worker Site] 2 new replies in Worker thread',
            $outbox[0]['subject']
        );
        $this->assertContains($outbox[0]['to'], [
            'digest-cap-a@example.com',
            'digest-cap-b@example.com',
        ]);
        $this->assertTrue(AP_Forum_Notify::isSubscribed($first, $this->topicId, $this->db));
        $this->assertTrue(AP_Forum_Notify::isSubscribed($second, $this->topicId, $this->db));
        $this->assertFalse(AP_Cron::nextScheduled(
            AP_Forum_Notify::CRON_HOOK,
            [$this->topicId, $secondId],
            $this->db
        ));
    }

    /**
     * @return array{
     *   max: string,
     *   window: string,
     *   lockout: string,
     *   limits: array{max: int, window: int, lockout: int},
     *   ip: array<string, mixed>,
     *   identity: array<string, mixed>
     * }
     */
    private function mailRateLimitSnapshot(string $email): array
    {
        return [
            'max' => (string) AP_Options::get('rate_limit_mail_max', '', $this->db),
            'window' => (string) AP_Options::get('rate_limit_mail_window', '', $this->db),
            'lockout' => (string) AP_Options::get('rate_limit_mail_lockout', '', $this->db),
            'limits' => AP_Rate_Limit::getLimits(AP_Rate_Limit::ACTION_MAIL, $this->db),
            'ip' => AP_Rate_Limit::check(
                AP_Rate_Limit::ACTION_MAIL,
                AP_Rate_Limit::ipBucket(),
                $this->db
            ),
            'identity' => AP_Rate_Limit::check(
                AP_Rate_Limit::ACTION_MAIL,
                AP_Rate_Limit::identityBucket($email),
                $this->db
            ),
        ];
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

    private function enqueue(int $replyId): void
    {
        $this->assertTrue(AP_Forum_Notify::enqueueReply($this->topicId, $replyId, $this->db));
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
