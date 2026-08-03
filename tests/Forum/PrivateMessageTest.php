<?php

/**
 * Tests for AP_Private_Message — private messaging (inbox/outbox/threads).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Forum;

use AP_Content_Format;
use AP_DB;
use AP_Forum_Moderation;
use AP_Migrator;
use AP_Options;
use AP_Private_Message;
use AP_Roles;
use AP_User;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_Private_Message::class)]
final class PrivateMessageTest extends TestCase
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
        require_once $this->root . '/ap-includes/class-ap-forum-moderation.php';
        require_once $this->root . '/ap-includes/class-ap-content-format.php';
        require_once $this->root . '/ap-includes/class-ap-private-message.php';
        require_once $this->root . '/ap-includes/functions.php';

        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');

        $migrator = new AP_Migrator($this->db, AP_Migrator::defaultMigrationsPath());
        $migrator->migrate();

        AP_Options::update(AP_Private_Message::OPTION_ENABLED, '1', $this->db);
        AP_Options::update('ap_module_forum', '1', $this->db);

        AP_Roles::flushCache();
        AP_Roles::ensureDefaults($this->db);
    }

    private function createUser(string $login, string $role = 'subscriber'): int
    {
        $result = AP_User::create([
            'user_login' => $login,
            'user_email' => $login . '@example.test',
            'user_pass' => 'password12345',
        ], $this->db);
        $this->assertTrue(!empty($result['ok']), $result['error'] ?? 'user create failed');
        $id = (int) $result['id'];
        AP_Roles::setUserRole($id, $role, $this->db);

        return $id;
    }

    public function testMessagesTableAndOption(): void
    {
        $name = $this->db->getVar(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?",
            ['ap_messages']
        );
        $this->assertSame('ap_messages', $name);
        $this->assertSame('ap_messages', $this->db->messages);
        $this->assertTrue(AP_Private_Message::isEnabled($this->db));
        $this->assertTrue(AP_Private_Message::isAvailable($this->db));
        $this->assertTrue(ap_private_messaging_enabled($this->db));
    }

    public function testSendInboxOutboxAndUnread(): void
    {
        $alice = $this->createUser('alice_pm');
        $bob = $this->createUser('bob_pm');

        $id = AP_Private_Message::send([
            'sender_id' => $alice,
            'recipient_id' => $bob,
            'subject' => 'Hello Bob',
            'content' => 'This is a private note.',
        ], $this->db);
        $this->assertGreaterThan(0, $id);

        $msg = AP_Private_Message::get($id, $this->db);
        $this->assertNotNull($msg);
        $this->assertSame($alice, (int) $msg->sender_id);
        $this->assertSame($bob, (int) $msg->recipient_id);
        $this->assertSame('Hello Bob', $msg->subject);
        $this->assertSame('This is a private note.', $msg->message_content);
        $this->assertNull($msg->read_at);
        $this->assertSame(0, (int) $msg->parent_id);

        $inbox = AP_Private_Message::getInbox($bob, [], $this->db);
        $this->assertCount(1, $inbox);
        $this->assertSame($id, (int) $inbox[0]->message_id);

        $outbox = AP_Private_Message::getOutbox($alice, [], $this->db);
        $this->assertCount(1, $outbox);
        $this->assertSame($id, (int) $outbox[0]->message_id);

        $this->assertSame(1, AP_Private_Message::countUnread($bob, $this->db));
        $this->assertSame(0, AP_Private_Message::countUnread($alice, $this->db));
        $this->assertCount(1, AP_Private_Message::getUnread($bob, [], $this->db));
    }

    public function testMarkReadUnreadAndAccessControl(): void
    {
        $alice = $this->createUser('alice_read');
        $bob = $this->createUser('bob_read');
        $carol = $this->createUser('carol_read');

        $id = AP_Private_Message::send([
            'sender_id' => $alice,
            'recipient_id' => $bob,
            'subject' => 'Secret',
            'content' => 'Eyes only',
        ], $this->db);

        // Third party cannot view.
        $this->assertNull(AP_Private_Message::getForUser($id, $carol, $this->db));
        $this->assertFalse(AP_Private_Message::userCanView($carol, $id, $this->db));

        // Recipient can view and mark read.
        $this->assertNotNull(AP_Private_Message::getForUser($id, $bob, $this->db));
        $this->assertTrue(AP_Private_Message::markRead($id, $bob, $this->db));
        $msg = AP_Private_Message::get($id, $this->db);
        $this->assertNotNull($msg->read_at);
        $this->assertSame(0, AP_Private_Message::countUnread($bob, $this->db));

        // Sender cannot mark read (not recipient).
        $this->assertTrue(AP_Private_Message::markUnread($id, $bob, $this->db));
        $this->assertFalse(AP_Private_Message::markRead($id, $alice, $this->db));
        $this->assertSame(1, AP_Private_Message::countUnread($bob, $this->db));

        // Admin can view any PM.
        $admin = $this->createUser('admin_pm', 'administrator');
        $this->assertTrue(AP_Private_Message::userCanView($admin, $id, $this->db));
        $this->assertNotNull(AP_Private_Message::getForUser($id, $admin, $this->db));
    }

    public function testReplyThreadAndSubjectInheritance(): void
    {
        $alice = $this->createUser('alice_thread');
        $bob = $this->createUser('bob_thread');

        $rootId = AP_Private_Message::send([
            'sender_id' => $alice,
            'recipient_id' => $bob,
            'subject' => 'Project plan',
            'content' => 'Shall we start?',
            'sent_at' => '2026-08-03 10:00:00',
        ], $this->db);

        $replyId = AP_Private_Message::reply([
            'sender_id' => $bob,
            'parent_id' => $rootId,
            'content' => 'Yes, Monday works.',
            'sent_at' => '2026-08-03 11:00:00',
        ], $this->db);
        $this->assertGreaterThan(0, $replyId);

        $reply = AP_Private_Message::get($replyId, $this->db);
        $this->assertNotNull($reply);
        $this->assertSame($rootId, (int) $reply->parent_id);
        $this->assertSame($bob, (int) $reply->sender_id);
        $this->assertSame($alice, (int) $reply->recipient_id);
        $this->assertSame('Re: Project plan', $reply->subject);

        $reply2 = AP_Private_Message::reply([
            'sender_id' => $alice,
            'message_id' => $replyId, // any message in thread
            'content' => 'Great.',
            'sent_at' => '2026-08-03 12:00:00',
        ], $this->db);
        $this->assertGreaterThan(0, $reply2);
        $r2 = AP_Private_Message::get($reply2, $this->db);
        $this->assertSame($rootId, (int) $r2->parent_id);

        $thread = AP_Private_Message::getThread($replyId, $bob, $this->db);
        $this->assertCount(3, $thread);
        $this->assertSame($rootId, (int) $thread[0]->message_id);
        $this->assertSame($replyId, (int) $thread[1]->message_id);
        $this->assertSame($reply2, (int) $thread[2]->message_id);

        // Outsider gets empty thread.
        $outsider = $this->createUser('outsider_thread');
        $this->assertSame([], AP_Private_Message::getThread($rootId, $outsider, $this->db));

        $n = AP_Private_Message::markThreadRead($rootId, $alice, $this->db);
        $this->assertGreaterThanOrEqual(1, $n);
        $this->assertSame(0, AP_Private_Message::countUnread($alice, $this->db));
    }

    public function testSoftDeletePerUserThenPurge(): void
    {
        $alice = $this->createUser('alice_del');
        $bob = $this->createUser('bob_del');

        $id = AP_Private_Message::send([
            'sender_id' => $alice,
            'recipient_id' => $bob,
            'subject' => 'Temp',
            'content' => 'Delete me',
        ], $this->db);

        // Recipient soft-deletes → gone from inbox, still in outbox.
        $this->assertTrue(AP_Private_Message::deleteForUser($id, $bob, $this->db));
        $this->assertCount(0, AP_Private_Message::getInbox($bob, [], $this->db));
        $this->assertCount(1, AP_Private_Message::getOutbox($alice, [], $this->db));

        $msg = AP_Private_Message::get($id, $this->db);
        $this->assertNotNull($msg);
        $this->assertSame(1, (int) $msg->recipient_deleted);
        $this->assertSame(0, (int) $msg->sender_deleted);

        // Sender soft-deletes → hard purge.
        $this->assertTrue(AP_Private_Message::deleteForUser($id, $alice, $this->db));
        $this->assertNull(AP_Private_Message::get($id, $this->db));
        $this->assertCount(0, AP_Private_Message::getOutbox($alice, [], $this->db));
    }

    public function testRejectsSelfMessageGuestsAndDisabled(): void
    {
        $alice = $this->createUser('alice_rules');

        $this->assertSame(0, AP_Private_Message::send([
            'sender_id' => $alice,
            'recipient_id' => $alice,
            'subject' => 'Self',
            'content' => 'Nope',
        ], $this->db));

        $this->assertSame(0, AP_Private_Message::send([
            'sender_id' => 0,
            'recipient_id' => $alice,
            'subject' => 'Guest',
            'content' => 'Nope',
        ], $this->db));

        $this->assertSame(0, AP_Private_Message::send([
            'sender_id' => $alice,
            'recipient_id' => 0,
            'subject' => 'No recipient',
            'content' => 'Nope',
        ], $this->db));

        AP_Options::update(AP_Private_Message::OPTION_ENABLED, '0', $this->db);
        $this->assertFalse(AP_Private_Message::isAvailable($this->db));
        $bob = $this->createUser('bob_rules');
        $this->assertSame(0, AP_Private_Message::send([
            'sender_id' => $alice,
            'recipient_id' => $bob,
            'subject' => 'Off',
            'content' => 'Nope',
        ], $this->db));

        // Explicit skip still works for CLI/tests.
        $id = AP_Private_Message::send([
            'sender_id' => $alice,
            'recipient_id' => $bob,
            'subject' => 'Bypass',
            'content' => 'OK',
        ], $this->db, [
            'check_enabled' => false,
            'skip_permission' => true,
        ]);
        $this->assertGreaterThan(0, $id);
    }

    public function testBannedUserCannotSend(): void
    {
        $alice = $this->createUser('alice_ban');
        $bob = $this->createUser('bob_ban');

        $banId = AP_Forum_Moderation::banUser($alice, ['reason' => 'spam'], $this->db);
        $this->assertGreaterThan(0, $banId);
        $this->assertTrue(AP_Forum_Moderation::isUserBanned($alice, $this->db));

        $this->assertFalse(AP_Private_Message::userCanSend($alice, $this->db));
        $this->assertSame(0, AP_Private_Message::send([
            'sender_id' => $alice,
            'recipient_id' => $bob,
            'subject' => 'Blocked',
            'content' => 'Should fail',
        ], $this->db));
    }

    public function testDisplayHelpersAndProceduralApi(): void
    {
        $alice = $this->createUser('alice_disp');
        $bob = $this->createUser('bob_disp');

        $id = ap_send_private_message([
            'sender_id' => $alice,
            'recipient_id' => $bob,
            'subject' => 'BB [b]bold[/b]',
            'content' => 'Hello **world**',
        ], $this->db);
        $this->assertGreaterThan(0, $id);

        $this->assertSame(1, ap_count_unread_pms($bob, $this->db));
        $folder = ap_get_pm_folder_display($bob, 'inbox', [], $this->db);
        $this->assertCount(1, $folder);
        $this->assertSame($id, $folder[0]['id']);
        $this->assertTrue($folder[0]['is_unread']);
        $this->assertStringContainsString('world', $folder[0]['content_html']);

        $this->assertTrue(ap_mark_pm_read($id, $bob, $this->db));
        $this->assertSame(0, ap_count_unread_pms($bob, $this->db));

        $replyId = ap_reply_private_message([
            'sender_id' => $bob,
            'parent_id' => $id,
            'content' => 'Got it',
        ], $this->db);
        $thread = ap_get_pm_thread_display($id, $alice, $this->db);
        $this->assertCount(2, $thread);
        $this->assertSame($replyId, $thread[1]['id']);

        $html = ap_format_private_message('[b]hi[/b]');
        $this->assertNotSame('', $html);
        if (class_exists(AP_Content_Format::class, false)) {
            $this->assertStringContainsString('<', $html);
        }

        $this->assertTrue(ap_delete_private_message($id, $bob, $this->db));
    }

    public function testQueryForStaffAndRejectNonParticipantReply(): void
    {
        $alice = $this->createUser('alice_q');
        $bob = $this->createUser('bob_q');
        $carol = $this->createUser('carol_q');

        $id = AP_Private_Message::send([
            'sender_id' => $alice,
            'recipient_id' => $bob,
            'subject' => 'Findme unique-token-xyz',
            'content' => 'Body search-token-xyz',
        ], $this->db);

        $found = AP_Private_Message::query(['search' => 'unique-token-xyz'], $this->db);
        $this->assertCount(1, $found);
        $this->assertSame($id, (int) $found[0]->message_id);

        // Carol cannot reply into Alice/Bob thread.
        $this->assertSame(0, AP_Private_Message::reply([
            'sender_id' => $carol,
            'parent_id' => $id,
            'content' => 'Intruder',
        ], $this->db));
    }
}
