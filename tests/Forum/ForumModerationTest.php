<?php

/**
 * Tests for AP_Forum_Moderation — full forum moderation tools.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Forum;

use AP_DB;
use AP_Forum;
use AP_Forum_Moderation;
use AP_Migrator;
use AP_User;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_Forum_Moderation::class)]
final class ForumModerationTest extends TestCase
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
        require_once $this->root . '/ap-includes/functions.php';

        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');

        $migrator = new AP_Migrator($this->db, AP_Migrator::defaultMigrationsPath());
        $migrator->migrate();
    }

    public function testSchemaVersionAndTables(): void
    {
        // Moderation tables ship at schema v8; later migrations may bump further.
        $this->assertGreaterThanOrEqual(8, (int) AP_DB_VERSION);
        $this->assertContains('warnings', AP_Forum::baseTables());
        $this->assertContains('bans', AP_Forum::baseTables());
        $this->assertSame('ap_warnings', $this->db->warnings);
        $this->assertSame('ap_bans', $this->db->bans);

        foreach (['ap_warnings', 'ap_bans', 'ap_reports'] as $table) {
            $name = $this->db->getVar(
                "SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?",
                [$table]
            );
            $this->assertSame($table, $name, "Expected table {$table}");
        }
    }

    public function testLockUnlockAndSoftDeleteRestoreTopic(): void
    {
        $forumId = AP_Forum::insertForum(['forum_name' => 'Mod'], $this->db);
        $topicId = AP_Forum::createTopic([
            'forum_id' => $forumId,
            'topic_title' => 'Lock me',
            'content' => 'Body',
        ], $this->db);

        $this->assertTrue(AP_Forum_Moderation::lockTopic($topicId, 0, $this->db));
        $topic = AP_Forum::getTopic($topicId, $this->db);
        $this->assertSame('locked', $topic?->topic_status);

        $this->assertTrue(AP_Forum_Moderation::unlockTopic($topicId, 0, $this->db));
        $topic = AP_Forum::getTopic($topicId, $this->db);
        $this->assertSame('open', $topic?->topic_status);

        $forum = AP_Forum::getForum($forumId, $this->db);
        $this->assertSame(1, (int) $forum?->topic_count);
        $this->assertSame(1, (int) $forum?->post_count);

        $this->assertTrue(AP_Forum_Moderation::softDeleteTopic($topicId, 0, $this->db));
        $topic = AP_Forum::getTopic($topicId, $this->db);
        $this->assertSame('deleted', $topic?->topic_status);
        $forum = AP_Forum::getForum($forumId, $this->db);
        $this->assertSame(0, (int) $forum?->topic_count);
        $this->assertSame(0, (int) $forum?->post_count);

        $this->assertTrue(AP_Forum_Moderation::restoreTopic($topicId, 0, $this->db));
        $topic = AP_Forum::getTopic($topicId, $this->db);
        $this->assertSame('open', $topic?->topic_status);
        $forum = AP_Forum::getForum($forumId, $this->db);
        $this->assertSame(1, (int) $forum?->topic_count);
        $this->assertSame(1, (int) $forum?->post_count);
    }

    public function testMoveTopicAdjustsCounters(): void
    {
        $a = AP_Forum::insertForum(['forum_name' => 'A'], $this->db);
        $b = AP_Forum::insertForum(['forum_name' => 'B'], $this->db);
        $topicId = AP_Forum::createTopic([
            'forum_id' => $a,
            'topic_title' => 'Move me',
            'content' => 'First',
        ], $this->db);
        AP_Forum::createReply([
            'topic_id' => $topicId,
            'content' => 'Second',
        ], $this->db);

        $this->assertTrue(AP_Forum_Moderation::moveTopic($topicId, $b, 0, $this->db));
        $topic = AP_Forum::getTopic($topicId, $this->db);
        $this->assertSame($b, (int) $topic?->forum_id);

        $forumA = AP_Forum::getForum($a, $this->db);
        $forumB = AP_Forum::getForum($b, $this->db);
        $this->assertSame(0, (int) $forumA?->topic_count);
        $this->assertSame(0, (int) $forumA?->post_count);
        $this->assertSame(1, (int) $forumB?->topic_count);
        $this->assertSame(2, (int) $forumB?->post_count);

        $posts = AP_Forum::getPosts($topicId, [], $this->db);
        $this->assertCount(2, $posts);
        foreach ($posts as $p) {
            $this->assertSame($b, (int) $p->forum_id);
        }
    }

    public function testMergeTopicsSameForum(): void
    {
        $forumId = AP_Forum::insertForum(['forum_name' => 'Merge'], $this->db);
        $target = AP_Forum::createTopic([
            'forum_id' => $forumId,
            'topic_title' => 'Keep',
            'content' => 'Target OP',
        ], $this->db);
        $source = AP_Forum::createTopic([
            'forum_id' => $forumId,
            'topic_title' => 'Absorb',
            'content' => 'Source OP',
        ], $this->db);
        $sourceReply = AP_Forum::createReply([
            'topic_id' => $source,
            'content' => 'Source reply',
        ], $this->db);

        $this->assertTrue(AP_Forum_Moderation::mergeTopics($source, $target, 0, $this->db));
        $this->assertNull(AP_Forum::getTopic($source, $this->db));

        $posts = AP_Forum::getPosts($target, ['approved_only' => false], $this->db);
        $this->assertCount(3, $posts);
        $ids = array_map(static fn ($p) => (int) $p->post_id, $posts);
        $this->assertContains($sourceReply, $ids);

        $topic = AP_Forum::getTopic($target, $this->db);
        $this->assertSame(2, (int) $topic?->reply_count);

        $forum = AP_Forum::getForum($forumId, $this->db);
        $this->assertSame(1, (int) $forum?->topic_count);
        $this->assertSame(3, (int) $forum?->post_count);
    }

    public function testSplitTopic(): void
    {
        $forumId = AP_Forum::insertForum(['forum_name' => 'Split'], $this->db);
        $topicId = AP_Forum::createTopic([
            'forum_id' => $forumId,
            'topic_title' => 'Original',
            'content' => 'OP stays',
        ], $this->db);
        $r1 = AP_Forum::createReply([
            'topic_id' => $topicId,
            'content' => 'Split one',
            'subject' => 'Split one subject',
        ], $this->db);
        $r2 = AP_Forum::createReply([
            'topic_id' => $topicId,
            'content' => 'Split two',
        ], $this->db);
        AP_Forum::createReply([
            'topic_id' => $topicId,
            'content' => 'Stay behind',
        ], $this->db);

        $newId = AP_Forum_Moderation::splitTopic($topicId, [$r1, $r2], [
            'title' => 'Split off',
        ], $this->db);
        $this->assertGreaterThan(0, $newId);

        $orig = AP_Forum::getTopic($topicId, $this->db);
        $new = AP_Forum::getTopic($newId, $this->db);
        $this->assertNotNull($orig);
        $this->assertNotNull($new);
        $this->assertSame('Split off', $new->topic_title);
        $this->assertSame(1, (int) $orig->reply_count);
        $this->assertSame(1, (int) $new->reply_count);

        $origPosts = AP_Forum::getPosts($topicId, [], $this->db);
        $newPosts = AP_Forum::getPosts($newId, [], $this->db);
        $this->assertCount(2, $origPosts);
        $this->assertCount(2, $newPosts);

        $forum = AP_Forum::getForum($forumId, $this->db);
        $this->assertSame(2, (int) $forum?->topic_count);
        $this->assertSame(4, (int) $forum?->post_count);
    }

    public function testSoftDeleteAndRestorePost(): void
    {
        $forumId = AP_Forum::insertForum(['forum_name' => 'Posts'], $this->db);
        $topicId = AP_Forum::createTopic([
            'forum_id' => $forumId,
            'topic_title' => 'Thread',
            'content' => 'OP',
        ], $this->db);
        $replyId = AP_Forum::createReply([
            'topic_id' => $topicId,
            'content' => 'Hide me',
        ], $this->db);

        $this->assertTrue(AP_Forum_Moderation::softDeletePost($replyId, 0, 'spam', $this->db));
        $post = AP_Forum::getPost($replyId, $this->db);
        $this->assertSame(0, (int) $post?->post_approved);
        $this->assertSame('spam', $post?->post_edit_reason);

        $listed = AP_Forum::getPosts($topicId, [], $this->db);
        $this->assertCount(1, $listed);

        $topic = AP_Forum::getTopic($topicId, $this->db);
        $this->assertSame(0, (int) $topic?->reply_count);

        $this->assertTrue(AP_Forum_Moderation::restorePost($replyId, 0, $this->db));
        $post = AP_Forum::getPost($replyId, $this->db);
        $this->assertSame(1, (int) $post?->post_approved);
        $topic = AP_Forum::getTopic($topicId, $this->db);
        $this->assertSame(1, (int) $topic?->reply_count);
    }

    public function testEditPostRecordsModerator(): void
    {
        $forumId = AP_Forum::insertForum(['forum_name' => 'Edit'], $this->db);
        $topicId = AP_Forum::createTopic([
            'forum_id' => $forumId,
            'topic_title' => 'T',
            'content' => 'Original',
        ], $this->db);
        $topic = AP_Forum::getTopic($topicId, $this->db);
        $postId = (int) $topic?->first_post_id;

        // moderator_id 0 skips ACL (system path); edit_user still recorded via data.
        $this->assertTrue(AP_Forum_Moderation::editPost($postId, [
            'content' => 'Edited by mod',
            'edit_reason' => 'cleanup',
            'edit_user' => 9,
        ], 0, $this->db));

        $post = AP_Forum::getPost($postId, $this->db);
        $this->assertSame('Edited by mod', $post?->post_content);
        $this->assertSame(9, (int) $post?->post_edit_user);
        $this->assertSame('cleanup', $post?->post_edit_reason);
        $this->assertSame(1, (int) $post?->post_edit_count);
    }

    public function testReportsLifecycle(): void
    {
        $forumId = AP_Forum::insertForum(['forum_name' => 'Rep'], $this->db);
        $topicId = AP_Forum::createTopic([
            'forum_id' => $forumId,
            'topic_title' => 'Reported',
            'content' => 'Bad content',
        ], $this->db);
        $topic = AP_Forum::getTopic($topicId, $this->db);
        $postId = (int) $topic?->first_post_id;

        $reportId = AP_Forum_Moderation::createReport([
            'reporter_id' => 5,
            'report_type' => 'post',
            'report_object_id' => $postId,
            'report_reason' => 'spam',
            'report_details' => 'Looks automated',
        ], $this->db);
        $this->assertGreaterThan(0, $reportId);

        $report = AP_Forum_Moderation::getReport($reportId, $this->db);
        $this->assertSame('open', $report?->report_status);
        $this->assertSame('spam', $report?->report_reason);

        $post = AP_Forum::getPost($postId, $this->db);
        $this->assertSame(1, (int) $post?->post_reported);

        $open = AP_Forum_Moderation::queryReports(['status' => 'open'], $this->db);
        $this->assertCount(1, $open);
        $this->assertSame(1, AP_Forum_Moderation::countReports(['status' => 'open'], $this->db));

        $this->assertTrue(AP_Forum_Moderation::resolveReport($reportId, 2, $this->db));
        $report = AP_Forum_Moderation::getReport($reportId, $this->db);
        $this->assertSame('closed', $report?->report_status);
        $this->assertSame(2, (int) $report?->resolved_by);
        $this->assertNotNull($report?->resolved_at);

        $this->assertTrue(AP_Forum_Moderation::reopenReport($reportId, $this->db));
        $this->assertTrue(AP_Forum_Moderation::dismissReport($reportId, 3, $this->db));
        $report = AP_Forum_Moderation::getReport($reportId, $this->db);
        $this->assertSame('dismissed', $report?->report_status);
    }

    public function testWarnings(): void
    {
        $wid = AP_Forum_Moderation::issueWarning([
            'user_id' => 10,
            'issuer_id' => 1,
            'reason' => 'Off-topic',
            'notes' => 'First warning',
            'related_type' => 'post',
            'related_id' => 99,
        ], $this->db);
        $this->assertGreaterThan(0, $wid);

        $w = AP_Forum_Moderation::getWarning($wid, $this->db);
        $this->assertSame(10, (int) $w?->user_id);
        $this->assertSame('Off-topic', $w?->warning_reason);
        $this->assertSame('active', $w?->warning_status);

        $list = AP_Forum_Moderation::getUserWarnings(10, [], $this->db);
        $this->assertCount(1, $list);
        $this->assertSame(1, AP_Forum_Moderation::countUserWarnings(10, 'active', $this->db));

        $this->assertTrue(AP_Forum_Moderation::revokeWarning($wid, 1, $this->db));
        $w = AP_Forum_Moderation::getWarning($wid, $this->db);
        $this->assertSame('revoked', $w?->warning_status);
        $this->assertSame(0, AP_Forum_Moderation::countUserWarnings(10, 'active', $this->db));
    }

    public function testBanAndUnbanUser(): void
    {
        $userResult = AP_User::create([
            'user_login' => 'trouble',
            'user_email' => 'trouble@example.test',
            'user_pass' => 'password123',
        ], $this->db);
        $this->assertTrue($userResult['ok']);
        $userId = (int) $userResult['id'];

        $banId = AP_Forum_Moderation::banUser($userId, [
            'reason' => 'Abuse',
            'banned_by' => 1,
        ], $this->db);
        $this->assertGreaterThan(0, $banId);
        $this->assertTrue(AP_Forum_Moderation::isUserBanned($userId, $this->db));

        $user = AP_User::getById($userId, $this->db);
        $this->assertSame(1, (int) $user?->user_status);

        $auth = AP_User::authenticate('trouble', 'password123', $this->db);
        $this->assertNull($auth);

        $this->assertTrue(AP_Forum_Moderation::unbanUser($userId, 1, $this->db));
        $this->assertFalse(AP_Forum_Moderation::isUserBanned($userId, $this->db));
        $user = AP_User::getById($userId, $this->db);
        $this->assertSame(0, (int) $user?->user_status);

        $auth = AP_User::authenticate('trouble', 'password123', $this->db);
        $this->assertNotNull($auth);
    }

    public function testSuspendExpiresAndIpBan(): void
    {
        $userResult = AP_User::create([
            'user_login' => 'tempban',
            'user_email' => 'temp@example.test',
            'user_pass' => 'password123',
        ], $this->db);
        $userId = (int) $userResult['id'];

        $past = date('Y-m-d H:i:s', time() - 3600);
        $banId = AP_Forum_Moderation::suspendUser($userId, $past, [
            'reason' => 'Cool down',
            'banned_by' => 1,
        ], $this->db);
        $this->assertGreaterThan(0, $banId);

        // getActiveUserBan expires due bans.
        $this->assertFalse(AP_Forum_Moderation::isUserBanned($userId, $this->db));
        $ban = AP_Forum_Moderation::getBan($banId, $this->db);
        $this->assertSame('expired', $ban?->ban_status);

        $ipBan = AP_Forum_Moderation::banIp('203.0.113.50', [
            'reason' => 'Proxy spam',
        ], $this->db);
        $this->assertGreaterThan(0, $ipBan);
        $this->assertTrue(AP_Forum_Moderation::isIpBanned('203.0.113.50', $this->db));
        $this->assertFalse(AP_Forum_Moderation::isIpBanned('203.0.113.51', $this->db));
        $this->assertTrue(AP_Forum_Moderation::liftBan($ipBan, 1, $this->db));
        $this->assertFalse(AP_Forum_Moderation::isIpBanned('203.0.113.50', $this->db));
    }

    public function testProceduralHelpers(): void
    {
        $forumId = ap_insert_forum(['forum_name' => 'Helpers'], $this->db);
        $topicId = ap_create_topic([
            'forum_id' => $forumId,
            'topic_title' => 'Via helpers',
            'content' => 'Body',
        ], $this->db);

        $this->assertTrue(ap_lock_topic($topicId, 0, $this->db));
        $this->assertTrue(ap_unlock_topic($topicId, 0, $this->db));

        $reportId = ap_create_report([
            'reporter_id' => 1,
            'type' => 'topic',
            'object_id' => $topicId,
            'reason' => 'troll',
        ], $this->db);
        $this->assertGreaterThan(0, $reportId);
        $this->assertNotNull(ap_get_report($reportId, $this->db));
        $this->assertTrue(ap_resolve_report($reportId, 1, $this->db));

        $wid = ap_issue_warning([
            'user_id' => 7,
            'issuer_id' => 1,
            'reason' => 'Tone',
        ], $this->db);
        $this->assertGreaterThan(0, $wid);
        $this->assertCount(1, ap_get_user_warnings(7, [], $this->db));
        $this->assertTrue(ap_revoke_warning($wid, 1, $this->db));

        $user = AP_User::create([
            'user_login' => 'helperban',
            'user_email' => 'hb@example.test',
            'user_pass' => 'password123',
        ], $this->db);
        $uid = (int) $user['id'];
        $this->assertGreaterThan(0, ap_ban_user($uid, ['reason' => 'x'], $this->db));
        $this->assertTrue(ap_is_user_banned($uid, $this->db));
        $this->assertTrue(ap_unban_user($uid, 1, $this->db));
    }

    public function testStickyHelper(): void
    {
        $forumId = AP_Forum::insertForum(['forum_name' => 'Sticky'], $this->db);
        $topicId = AP_Forum::createTopic([
            'forum_id' => $forumId,
            'topic_title' => 'Pin',
            'content' => 'Body',
        ], $this->db);

        $this->assertTrue(AP_Forum_Moderation::setTopicType(
            $topicId,
            AP_Forum::TOPIC_TYPE_STICKY,
            0,
            $this->db
        ));
        $topic = AP_Forum::getTopic($topicId, $this->db);
        $this->assertSame('sticky', $topic?->topic_type);
    }
}
