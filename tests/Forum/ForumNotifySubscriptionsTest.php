<?php

/**
 * Tests for topic-notify subscribe / unsubscribe helpers.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Forum;

use AP_DB;
use AP_Forum;
use AP_Forum_Notify;
use AP_Migrator;
use AP_Options;
use AP_User;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_Forum_Notify::class)]
final class ForumNotifySubscriptionsTest extends TestCase
{
    private string $root;

    private AP_DB $db;

    private int $forumId = 0;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        require_once $this->root . '/ap-includes/version.php';
        require_once $this->root . '/ap-includes/class-ap-db.php';
        require_once $this->root . '/ap-includes/class-ap-migrator.php';
        require_once $this->root . '/ap-includes/class-ap-options.php';
        require_once $this->root . '/ap-includes/class-ap-forum-notify.php';
        require_once $this->root . '/ap-includes/class-ap-user.php';
        require_once $this->root . '/ap-includes/class-ap-forum.php';
        require_once $this->root . '/ap-includes/functions.php';

        AP_Options::flushCache();

        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');
        $migrator = new AP_Migrator($this->db, AP_Migrator::defaultMigrationsPath());
        $migrator->migrate();

        $this->forumId = AP_Forum::insertForum(['forum_name' => 'Notify Subs'], $this->db);
        $this->assertGreaterThan(0, $this->forumId);
    }

    protected function tearDown(): void
    {
        AP_Options::flushCache();
    }

    public function testSubscribeUnsubscribeAddsAndRemovesOnePair(): void
    {
        $userId = $this->createMember('sub-pair');
        $topicId = $this->createTopic('Watched');

        $this->assertFalse(AP_Forum_Notify::isSubscribed($userId, $topicId, $this->db));
        $this->assertTrue(AP_Forum_Notify::subscribe($userId, $topicId, $this->db));
        $this->assertTrue(AP_Forum_Notify::isSubscribed($userId, $topicId, $this->db));

        $rows = AP_Forum_Notify::listForUser($userId, $this->db);
        $this->assertCount(1, $rows);
        $this->assertSame($userId, (int) $rows[0]->user_id);
        $this->assertSame($topicId, (int) $rows[0]->topic_id);
        $this->assertNotSame('', (string) $rows[0]->created_at);

        $this->assertTrue(AP_Forum_Notify::unsubscribe($userId, $topicId, $this->db));
        $this->assertFalse(AP_Forum_Notify::isSubscribed($userId, $topicId, $this->db));
        $this->assertSame([], AP_Forum_Notify::listForUser($userId, $this->db));
        $this->assertSame(0, $this->subscriptionCount());
    }

    public function testSubscribeIsIdempotentAndHonorsUniquePair(): void
    {
        $alice = $this->createMember('sub-alice');
        $bob = $this->createMember('sub-bob');
        $topicA = $this->createTopic('Topic A');
        $topicB = $this->createTopic('Topic B');

        $this->assertTrue(AP_Forum_Notify::subscribe($alice, $topicA, $this->db));
        $this->assertTrue(AP_Forum_Notify::subscribe($alice, $topicA, $this->db));
        $this->assertTrue(AP_Forum_Notify::subscribe($alice, $topicB, $this->db));
        $this->assertTrue(AP_Forum_Notify::subscribe($bob, $topicA, $this->db));

        $this->assertSame(3, $this->subscriptionCount());
        $this->assertCount(2, AP_Forum_Notify::listForUser($alice, $this->db));
        $this->assertCount(1, AP_Forum_Notify::listForUser($bob, $this->db));

        $this->assertTrue(AP_Forum_Notify::unsubscribe($alice, $topicA, $this->db));
        $this->assertTrue(AP_Forum_Notify::unsubscribe($alice, $topicA, $this->db));
        $this->assertFalse(AP_Forum_Notify::isSubscribed($alice, $topicA, $this->db));
        $this->assertTrue(AP_Forum_Notify::isSubscribed($alice, $topicB, $this->db));
        $this->assertTrue(AP_Forum_Notify::isSubscribed($bob, $topicA, $this->db));
        $this->assertSame(2, $this->subscriptionCount());
    }

    public function testGuestAndMissingIdsAreRejected(): void
    {
        $userId = $this->createMember('sub-guest');
        $topicId = $this->createTopic('Guest-proof');

        $this->assertFalse(AP_Forum_Notify::subscribe(0, $topicId, $this->db));
        $this->assertFalse(AP_Forum_Notify::subscribe(-1, $topicId, $this->db));
        $this->assertFalse(AP_Forum_Notify::subscribe($userId, 0, $this->db));
        $this->assertFalse(AP_Forum_Notify::isSubscribed(0, $topicId, $this->db));
        $this->assertFalse(AP_Forum_Notify::unsubscribe(0, $topicId, $this->db));
        $this->assertFalse(AP_Forum_Notify::subscribe($userId, 999999, $this->db));
        $this->assertFalse(AP_Forum_Notify::subscribe(999999, $topicId, $this->db));
        $this->assertSame(0, AP_Forum_Notify::deleteForUser(0, $this->db));
        $this->assertSame(0, AP_Forum_Notify::deleteForTopic(0, $this->db));
        $this->assertSame(0, $this->subscriptionCount());
    }

    public function testUserDeleteDropsOnlyThatUsersRows(): void
    {
        $alice = $this->createMember('sub-del-alice');
        $bob = $this->createMember('sub-del-bob');
        $topicA = $this->createTopic('Keep for Bob');
        $topicB = $this->createTopic('Alice second');

        $this->assertTrue(AP_Forum_Notify::subscribe($alice, $topicA, $this->db));
        $this->assertTrue(AP_Forum_Notify::subscribe($alice, $topicB, $this->db));
        $this->assertTrue(AP_Forum_Notify::subscribe($bob, $topicA, $this->db));

        $this->assertTrue(AP_User::delete($alice, $this->db));
        $this->assertNull(AP_User::getById($alice, $this->db));
        $this->assertSame([], AP_Forum_Notify::listForUser($alice, $this->db));
        $this->assertFalse(AP_Forum_Notify::isSubscribed($alice, $topicA, $this->db));
        $this->assertTrue(AP_Forum_Notify::isSubscribed($bob, $topicA, $this->db));
        $this->assertSame(1, $this->subscriptionCount());

        $carol = $this->createMember('sub-del-carol');
        $this->assertTrue(AP_Forum_Notify::subscribe($carol, $topicB, $this->db));
        $this->assertTrue(ap_delete_user($carol, $this->db));
        $this->assertFalse(AP_Forum_Notify::isSubscribed($carol, $topicB, $this->db));
        $this->assertTrue(AP_Forum_Notify::isSubscribed($bob, $topicA, $this->db));
    }

    public function testTopicHardDeleteDropsRowsSoftDeleteKeepsThem(): void
    {
        $userId = $this->createMember('sub-topic-del');
        $softId = $this->createTopic('Soft gone');
        $hardId = $this->createTopic('Hard gone');
        $keepId = $this->createTopic('Still here');

        $this->assertTrue(AP_Forum_Notify::subscribe($userId, $softId, $this->db));
        $this->assertTrue(AP_Forum_Notify::subscribe($userId, $hardId, $this->db));
        $this->assertTrue(AP_Forum_Notify::subscribe($userId, $keepId, $this->db));

        $this->assertTrue(AP_Forum::deleteTopic($softId, false, $this->db));
        $this->assertNotNull(AP_Forum::getTopic($softId, $this->db));
        $this->assertTrue(AP_Forum_Notify::isSubscribed($userId, $softId, $this->db));
        $this->assertSame(3, $this->subscriptionCount());

        $this->assertTrue(AP_Forum::deleteTopic($hardId, true, $this->db));
        $this->assertNull(AP_Forum::getTopic($hardId, $this->db));
        $this->assertFalse(AP_Forum_Notify::isSubscribed($userId, $hardId, $this->db));
        $this->assertTrue(AP_Forum_Notify::isSubscribed($userId, $softId, $this->db));
        $this->assertTrue(AP_Forum_Notify::isSubscribed($userId, $keepId, $this->db));
        $this->assertSame(2, $this->subscriptionCount());
    }

    public function testProceduralSubscribeWrappers(): void
    {
        $userId = $this->createMember('sub-proc');
        $topicId = $this->createTopic('Proc topic');

        $this->assertFalse(ap_forum_user_subscribed_to_topic($userId, $topicId, $this->db));
        $this->assertTrue(ap_forum_subscribe_topic($userId, $topicId, $this->db));
        $this->assertTrue(ap_forum_user_subscribed_to_topic($userId, $topicId, $this->db));
        $this->assertTrue(ap_forum_unsubscribe_topic($userId, $topicId, $this->db));
        $this->assertFalse(ap_forum_user_subscribed_to_topic($userId, $topicId, $this->db));
    }

    public function testSubscribeFormHtmlHelper(): void
    {
        $this->assertSame('', ap_forum_topic_subscribe_form_html(0, false));
        $this->assertSame('', ap_forum_topic_subscribe_form_html(12, false, ['show' => false]));

        $html = ap_forum_topic_subscribe_form_html(12, false);
        $this->assertStringContainsString('ap-forum-subscribe', $html);
        $this->assertStringContainsString('name="ap_forum_action"', $html);
        $this->assertStringContainsString('ap_forum_subscribe_topic', $html);
        $this->assertStringContainsString('name="topic_id" value="12"', $html);
        $this->assertStringContainsString('name="_ap_nonce"', $html);
        $this->assertStringContainsString('>Subscribe</button>', $html);
        $this->assertStringContainsString('aria-pressed="false"', $html);

        $un = ap_forum_topic_subscribe_form_html(12, true);
        $this->assertStringContainsString('ap_forum_unsubscribe_topic', $un);
        $this->assertStringContainsString('>Unsubscribe</button>', $un);
        $this->assertStringContainsString('aria-pressed="true"', $un);
        $this->assertStringNotContainsString('ap_forum_subscribe_topic', $un);
    }

    public function testListForUserWithTitlesIncludesTitleAndUrl(): void
    {
        $userId = $this->createMember('sub-titles');
        $alpha = $this->createTopic('Alpha watch');
        $beta = $this->createTopic('Beta watch');

        $this->assertTrue(AP_Forum_Notify::subscribe($userId, $alpha, $this->db));
        $this->assertTrue(AP_Forum_Notify::subscribe($userId, $beta, $this->db));

        $rows = AP_Forum_Notify::listForUserWithTitles($userId, $this->db);
        $this->assertCount(2, $rows);
        $this->assertSame($alpha, $rows[0]['topic_id']);
        $this->assertSame('Alpha watch', $rows[0]['topic_title']);
        $this->assertNotSame('', $rows[0]['topic_url']);
        $this->assertStringContainsString((string) $alpha, $rows[0]['topic_url']);
        $this->assertSame($beta, $rows[1]['topic_id']);
        $this->assertSame('Beta watch', $rows[1]['topic_title']);

        $viaHelper = ap_forum_list_topic_subscriptions($userId, $this->db);
        $this->assertSame($rows, $viaHelper);

        $this->assertSame([], AP_Forum_Notify::listForUserWithTitles(0, $this->db));
        $this->assertSame([], ap_forum_list_topic_subscriptions(0, $this->db));
    }

    public function testListForUserWithTitlesFallsBackWhenTopicIsMissing(): void
    {
        $userId = $this->createMember('sub-orphan');
        $this->assertSame(1, $this->db->insert('topic_subscriptions', [
            'user_id' => $userId,
            'topic_id' => 999001,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]));

        $rows = AP_Forum_Notify::listForUserWithTitles($userId, $this->db);
        $this->assertCount(1, $rows);
        $this->assertSame(999001, $rows[0]['topic_id']);
        $this->assertSame('Topic #999001', $rows[0]['topic_title']);
        $this->assertNotSame('', $rows[0]['topic_url']);
    }

    public function testSubscribeDoesNotWriteUnreadTrack(): void
    {
        $userId = $this->createMember('sub-no-track');
        $topicId = $this->createTopic('Not a track');

        $this->assertTrue(AP_Forum_Notify::subscribe($userId, $topicId, $this->db));

        $track = $this->db->getVar(
            'SELECT COUNT(*) FROM ' . $this->db->quoteIdentifier($this->db->table('topic_track'))
        );
        $this->assertSame(0, (int) $track);
        $this->assertSame(1, $this->subscriptionCount());
    }

    private function createMember(string $login): int
    {
        $created = AP_User::create([
            'user_login' => $login,
            'user_email' => $login . '@example.com',
            'user_pass' => 'securepass0',
        ], $this->db);
        $this->assertTrue($created['ok'], implode('; ', $created['errors']));
        $this->assertGreaterThan(0, (int) $created['id']);

        return (int) $created['id'];
    }

    private function createTopic(string $title): int
    {
        $topicId = AP_Forum::createTopic([
            'forum_id' => $this->forumId,
            'topic_title' => $title,
            'content' => 'First post',
        ], $this->db);
        $this->assertGreaterThan(0, $topicId);

        return $topicId;
    }

    private function subscriptionCount(): int
    {
        return (int) $this->db->getVar(
            'SELECT COUNT(*) FROM '
            . $this->db->quoteIdentifier($this->db->table('topic_subscriptions'))
        );
    }
}
