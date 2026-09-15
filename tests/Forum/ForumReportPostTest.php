<?php

/**
 * SPEC-minimum front report tests: one open report; guest cannot;
 * duplicate open report refused. Reason required. Flood guard.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Forum;

use AP_DB;
use AP_Forum;
use AP_Forum_Front;
use AP_Forum_Guard;
use AP_Forum_Moderation;
use AP_Forum_Permissions;
use AP_Group;
use AP_Migrator;
use AP_Nonce;
use AP_Options;
use AP_Rewrite;
use AP_Roles;
use AP_Session;
use AP_Theme;
use AP_User;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_Forum_Front::class)]
#[CoversClass(AP_Forum_Moderation::class)]
final class ForumReportPostTest extends TestCase
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
        require_once $this->root . '/ap-includes/class-ap-post.php';
        require_once $this->root . '/ap-includes/class-ap-query.php';
        require_once $this->root . '/ap-includes/class-ap-rewrite.php';
        require_once $this->root . '/ap-includes/class-ap-nonce.php';
        require_once $this->root . '/ap-includes/class-ap-group.php';
        require_once $this->root . '/ap-includes/class-ap-forum-permissions.php';
        require_once $this->root . '/ap-includes/class-ap-forum.php';
        require_once $this->root . '/ap-includes/class-ap-forum-moderation.php';
        require_once $this->root . '/ap-includes/class-ap-forum-guard.php';
        require_once $this->root . '/ap-includes/class-ap-forum-read.php';
        require_once $this->root . '/ap-includes/class-ap-forum-front.php';
        require_once $this->root . '/ap-includes/class-ap-content-format.php';
        require_once $this->root . '/ap-includes/hooks.php';
        require_once $this->root . '/ap-includes/class-ap-theme.php';
        require_once $this->root . '/ap-includes/class-ap-assets.php';
        require_once $this->root . '/ap-includes/functions.php';
        require_once $this->root . '/ap-includes/template-tags.php';

        if (!defined('AP_NONCE_KEY')) {
            define('AP_NONCE_KEY', 'test-nonce-key-report');
        }
        if (!defined('AP_NONCE_SALT')) {
            define('AP_NONCE_SALT', 'test-nonce-salt-report');
        }
        if (!defined('AP_LOGGED_IN_KEY')) {
            define('AP_LOGGED_IN_KEY', 'test-logged-in-key-report');
        }
        if (!defined('AP_LOGGED_IN_SALT')) {
            define('AP_LOGGED_IN_SALT', 'test-logged-in-salt-report');
        }
        if (!defined('AP_AUTH_KEY')) {
            define('AP_AUTH_KEY', 'test-auth-key-report');
        }
        if (!defined('AP_AUTH_SALT')) {
            define('AP_AUTH_SALT', 'test-auth-salt-report');
        }

        if (function_exists('ap_reset_hooks')) {
            ap_reset_hooks();
        }
        AP_Rewrite::resetCache();
        AP_Options::flushCache();
        AP_Roles::flushCache();
        AP_Group::flushCache();
        AP_Forum_Permissions::flushCache();
        AP_Theme::reset();
        if (class_exists('AP_Assets', false)) {
            \AP_Assets::reset();
        }
        AP_Session::enableTestMode();
        AP_Session::resetCurrentUser();
        AP_Forum_Front::setNotice(null);
        unset($GLOBALS['ap_query'], $GLOBALS['ap_post']);

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
                'home' => 'https://example.test',
                'siteurl' => 'https://example.test',
                'permalink_structure' => '/%postname%/',
                'stylesheet' => 'agora',
                'template' => 'agora',
                'blogname' => 'Report Front Site',
                'ap_module_forum' => '1',
            ] as $name => $value
        ) {
            $this->db->insert('options', [
                'option_name' => $name,
                'option_value' => $value,
                'autoload' => 'yes',
            ]);
        }
        AP_Options::flushCache();
        AP_Options::update('forum_flood_interval', '0', $this->db);

        $created = AP_User::create([
            'user_login' => 'report_admin',
            'user_email' => 'report_admin@example.test',
            'user_pass' => 'Password123!',
            'display_name' => 'Report Admin',
            'role' => 'administrator',
        ], $this->db);
        $this->assertTrue($created['ok'] ?? false, implode('; ', $created['errors'] ?? ['create failed']));
        $this->adminId = (int) ($created['id'] ?? 0);
        $this->assertGreaterThan(0, $this->adminId);

        AP_Theme::setThemesRootOverride($this->root . '/ap-content/themes');
        AP_Theme::setActiveOverride('agora', 'agora');
        AP_Theme::setup($this->db);
        AP_Rewrite::flushRules($this->db);
    }

    protected function tearDown(): void
    {
        AP_Session::resetCurrentUser();
        AP_Session::disableTestMode();
        AP_Rewrite::resetCache();
        AP_Options::flushCache();
        AP_Roles::flushCache();
        AP_Group::flushCache();
        AP_Forum_Permissions::flushCache();
        AP_Theme::reset();
        AP_Forum_Front::setNotice(null);
        unset($GLOBALS['ap_query'], $GLOBALS['ap_post'], $GLOBALS['apdb']);
        if (function_exists('ap_reset_hooks')) {
            ap_reset_hooks();
        }
    }

    /**
     * SPEC: one open report (type post, status open) from a logged-in member.
     */
    public function testLoggedInMemberCreatesOneOpenPostReport(): void
    {
        $memberId = $this->createSubscriber('report_ok');
        [$postId] = $this->seedTopicWithReply();

        $this->assertTrue(AP_Session::setAuthCookie($memberId, false, $this->db));
        $redirect = AP_Forum_Front::handlePost([
            'ap_forum_action' => AP_Forum_Front::ACTION_REPORT_POST,
            'post_id' => $postId,
            'report_reason' => 'spam',
            '_ap_nonce' => AP_Nonce::create('ap_forum_report_post_' . $postId, $memberId),
        ], $this->db);

        $this->assertIsString($redirect);
        $this->assertStringContainsString('ap_forum_notice=post_reported', (string) $redirect);
        $this->assertStringContainsString('#post-' . $postId, (string) $redirect);

        $open = AP_Forum_Moderation::queryReports([
            'status' => AP_Forum_Moderation::REPORT_STATUS_OPEN,
            'type' => AP_Forum_Moderation::REPORT_TYPE_POST,
            'object_id' => $postId,
            'reporter_id' => $memberId,
        ], $this->db);
        $this->assertCount(1, $open);
        $this->assertSame('spam', $open[0]->report_reason ?? null);
        $this->assertSame('post', $open[0]->report_type ?? null);
        $this->assertSame('open', $open[0]->report_status ?? null);
        $this->assertTrue(AP_Forum_Moderation::hasOpenReport(
            $memberId,
            AP_Forum_Moderation::REPORT_TYPE_POST,
            $postId,
            $this->db
        ));

        $row = AP_Forum::getPost($postId, $this->db);
        $this->assertSame(1, (int) ($row?->post_reported ?? 0));

        $_GET['ap_forum_notice'] = 'post_reported';
        $notice = AP_Forum_Front::getNotice();
        unset($_GET['ap_forum_notice']);
        $this->assertSame('success', $notice['type'] ?? null);
        $this->assertSame('Report submitted.', $notice['message'] ?? null);
    }

    /**
     * SPEC: guest cannot (no insert; form is omitted on the topic view).
     */
    public function testGuestCannotReportPost(): void
    {
        [$postId] = $this->seedTopicWithReply();
        AP_Session::resetCurrentUser();

        $redirect = AP_Forum_Front::handlePost([
            'ap_forum_action' => AP_Forum_Front::ACTION_REPORT_POST,
            'post_id' => $postId,
            'report_reason' => 'spam',
            '_ap_nonce' => AP_Nonce::create('ap_forum_report_post_' . $postId, 0),
        ], $this->db);
        $this->assertNull($redirect);
        $notice = AP_Forum_Front::getNotice();
        $this->assertSame('error', $notice['type'] ?? null);
        $this->assertSame('You must be logged in to report posts.', $notice['message'] ?? null);
        $this->assertSame(0, $this->openReportCount($postId));

        $this->assertSame(0, AP_Forum_Moderation::createReport([
            'reporter_id' => 0,
            'report_type' => 'post',
            'report_object_id' => $postId,
            'report_reason' => 'spam',
        ], $this->db));
        $this->assertSame(0, $this->openReportCount($postId));
    }

    /**
     * SPEC: duplicate open report refused. After resolve, a new open report is allowed.
     */
    public function testDuplicateOpenReportRefused(): void
    {
        $memberId = $this->createSubscriber('report_dup');
        [$postId] = $this->seedTopicWithReply();

        $this->assertTrue(AP_Session::setAuthCookie($memberId, false, $this->db));
        $first = AP_Forum_Front::handlePost([
            'ap_forum_action' => AP_Forum_Front::ACTION_REPORT_POST,
            'post_id' => $postId,
            'report_reason' => 'harassment',
            '_ap_nonce' => AP_Nonce::create('ap_forum_report_post_' . $postId, $memberId),
        ], $this->db);
        $this->assertIsString($first);
        $this->assertSame(1, $this->openReportCount($postId, $memberId));

        AP_Forum_Front::setNotice(null);
        $second = AP_Forum_Front::handlePost([
            'ap_forum_action' => AP_Forum_Front::ACTION_REPORT_POST,
            'post_id' => $postId,
            'report_reason' => 'still bad',
            '_ap_nonce' => AP_Nonce::create('ap_forum_report_post_' . $postId, $memberId),
        ], $this->db);
        $this->assertNull($second);
        $notice = AP_Forum_Front::getNotice();
        $this->assertSame('error', $notice['type'] ?? null);
        $this->assertSame('You have already reported this post.', $notice['message'] ?? null);
        $this->assertSame(1, $this->openReportCount($postId, $memberId));
        $this->assertSame(1, $this->reportCount($postId, $memberId));

        $this->assertSame(0, AP_Forum_Moderation::createReport([
            'reporter_id' => $memberId,
            'report_type' => 'post',
            'report_object_id' => $postId,
            'report_reason' => 'again',
        ], $this->db));
        $this->assertSame(1, $this->reportCount($postId, $memberId));

        $open = AP_Forum_Moderation::queryReports([
            'status' => 'open',
            'object_id' => $postId,
            'reporter_id' => $memberId,
        ], $this->db);
        $this->assertCount(1, $open);
        $this->assertTrue(AP_Forum_Moderation::resolveReport(
            (int) $open[0]->report_id,
            $this->adminId,
            $this->db
        ));
        $this->assertFalse(AP_Forum_Moderation::hasOpenReport(
            $memberId,
            AP_Forum_Moderation::REPORT_TYPE_POST,
            $postId,
            $this->db
        ));

        AP_Forum_Front::setNotice(null);
        $third = AP_Forum_Front::handlePost([
            'ap_forum_action' => AP_Forum_Front::ACTION_REPORT_POST,
            'post_id' => $postId,
            'report_reason' => 'it came back',
            '_ap_nonce' => AP_Nonce::create('ap_forum_report_post_' . $postId, $memberId),
        ], $this->db);
        $this->assertIsString($third);
        $this->assertSame(1, $this->openReportCount($postId, $memberId));
        $this->assertSame(2, $this->reportCount($postId, $memberId));
    }

    public function testReasonRequiredAndFailedInsertDoesNotClaimSuccess(): void
    {
        $memberId = $this->createSubscriber('report_reason');
        [$postId] = $this->seedTopicWithReply();

        $this->assertTrue(AP_Session::setAuthCookie($memberId, false, $this->db));
        $empty = AP_Forum_Front::handlePost([
            'ap_forum_action' => AP_Forum_Front::ACTION_REPORT_POST,
            'post_id' => $postId,
            'report_reason' => '   ',
            '_ap_nonce' => AP_Nonce::create('ap_forum_report_post_' . $postId, $memberId),
        ], $this->db);
        $this->assertNull($empty);
        $notice = AP_Forum_Front::getNotice();
        $this->assertSame('error', $notice['type'] ?? null);
        $this->assertSame('Please provide a reason for this report.', $notice['message'] ?? null);
        $this->assertSame(0, $this->openReportCount($postId));

        $this->assertSame(0, AP_Forum_Moderation::createReport([
            'reporter_id' => $memberId,
            'report_type' => 'post',
            'report_object_id' => $postId,
            'report_reason' => '',
        ], $this->db));
        $this->assertSame(0, $this->openReportCount($postId));
    }

    public function testReportFloodGuard(): void
    {
        AP_Options::update('forum_flood_interval', '60', $this->db);
        $this->assertSame(60, AP_Forum_Guard::getFloodInterval($this->db));

        $memberId = $this->createSubscriber('report_flood');
        [$firstId, $secondId] = $this->seedTopicWithReply();

        $this->assertTrue(AP_Session::setAuthCookie($memberId, false, $this->db));
        $ok = AP_Forum_Front::handlePost([
            'ap_forum_action' => AP_Forum_Front::ACTION_REPORT_POST,
            'post_id' => $firstId,
            'report_reason' => 'first',
            '_ap_nonce' => AP_Nonce::create('ap_forum_report_post_' . $firstId, $memberId),
        ], $this->db);
        $this->assertIsString($ok);
        $this->assertTrue(AP_Forum_Moderation::isReportFlooding($memberId, $this->db));

        AP_Forum_Front::setNotice(null);
        $flooded = AP_Forum_Front::handlePost([
            'ap_forum_action' => AP_Forum_Front::ACTION_REPORT_POST,
            'post_id' => $secondId,
            'report_reason' => 'second',
            '_ap_nonce' => AP_Nonce::create('ap_forum_report_post_' . $secondId, $memberId),
        ], $this->db);
        $this->assertNull($flooded);
        $notice = AP_Forum_Front::getNotice();
        $this->assertSame('error', $notice['type'] ?? null);
        $this->assertSame(
            'You are reporting too quickly. Please wait a moment and try again.',
            $notice['message'] ?? null
        );
        $this->assertSame(0, $this->openReportCount($secondId, $memberId));
        $this->assertSame(1, $this->reportCount($firstId, $memberId));

        $this->assertTrue(AP_Session::setAuthCookie($this->adminId, false, $this->db));
        $this->assertFalse(AP_Forum_Moderation::isReportFlooding($this->adminId, $this->db));
        $staff = AP_Forum_Front::handlePost([
            'ap_forum_action' => AP_Forum_Front::ACTION_REPORT_POST,
            'post_id' => $secondId,
            'report_reason' => 'staff',
            '_ap_nonce' => AP_Nonce::create('ap_forum_report_post_' . $secondId, $this->adminId),
        ], $this->db);
        $this->assertIsString($staff);
        $this->assertSame(1, $this->openReportCount($secondId, $this->adminId));
    }

    public function testMissingViewForumRefused(): void
    {
        $groupId = AP_Group::create(['group_name' => 'Report VIP'], $this->db);
        $this->assertGreaterThan(0, $groupId);
        $forumId = AP_Forum::insertForum(['forum_name' => 'VIP report board'], $this->db);
        $this->assertTrue(AP_Forum_Permissions::applyGroupOnlyAccess($forumId, [$groupId], $this->db));
        $topicId = AP_Forum::createTopic([
            'forum_id' => $forumId,
            'topic_title' => 'VIP only',
            'content' => 'Private',
            'poster_id' => $this->adminId,
        ], $this->db);
        $topic = AP_Forum::getTopic($topicId, $this->db);
        $postId = (int) ($topic?->first_post_id ?? 0);
        $this->assertGreaterThan(0, $postId);

        $outsiderId = $this->createSubscriber('report_out');
        $this->assertTrue(AP_Session::setAuthCookie($outsiderId, false, $this->db));
        $denied = AP_Forum_Front::handlePost([
            'ap_forum_action' => AP_Forum_Front::ACTION_REPORT_POST,
            'post_id' => $postId,
            'report_reason' => 'spam',
            '_ap_nonce' => AP_Nonce::create('ap_forum_report_post_' . $postId, $outsiderId),
        ], $this->db);
        $this->assertNull($denied);
        $notice = AP_Forum_Front::getNotice();
        $this->assertSame('error', $notice['type'] ?? null);
        $this->assertSame(0, $this->openReportCount($postId));
    }

    public function testTopicViewShowsReportForLoggedInNotGuest(): void
    {
        $memberId = $this->createSubscriber('report_chrome');
        $forumId = AP_Forum::insertForum(['forum_name' => 'Report chrome'], $this->db);
        $topicId = AP_Forum::createTopic([
            'forum_id' => $forumId,
            'topic_title' => 'Report me',
            'content' => 'Body',
            'poster_id' => $this->adminId,
        ], $this->db);
        $replyId = AP_Forum::createReply([
            'topic_id' => $topicId,
            'content' => 'A reply',
            'poster_id' => $memberId,
        ], $this->db);
        $this->assertGreaterThan(0, $replyId);
        $topic = AP_Forum::getTopic($topicId, $this->db);
        $this->assertNotNull($topic);
        $opId = (int) ($topic->first_post_id ?? 0);

        $vars = AP_Rewrite::parseRequest('topic/' . $topic->topic_slug, [], $this->db);

        AP_Session::resetCurrentUser();
        $guestQuery = AP_Rewrite::queryFromVars($vars, $this->db);
        AP_Forum_Front::applyToQuery($guestQuery, $this->db);
        ap_set_query($guestQuery);
        ob_start();
        AP_Theme::render($guestQuery, $this->db);
        $guestHtml = (string) ob_get_clean();
        $this->assertStringNotContainsString('ap_forum_report_post', $guestHtml);
        $this->assertStringNotContainsString('name="report_reason"', $guestHtml);
        $this->assertStringNotContainsString('>Report</button>', $guestHtml);

        $this->assertTrue(AP_Session::setAuthCookie($memberId, false, $this->db));
        $memberQuery = AP_Rewrite::queryFromVars($vars, $this->db);
        AP_Forum_Front::applyToQuery($memberQuery, $this->db);
        ap_set_query($memberQuery);
        ob_start();
        AP_Theme::render($memberQuery, $this->db);
        $memberHtml = (string) ob_get_clean();
        $this->assertStringContainsString('ap_forum_report_post', $memberHtml);
        $this->assertStringContainsString('name="report_reason"', $memberHtml);
        $this->assertStringContainsString('>Report</button>', $memberHtml);
        $this->assertStringContainsString('id="agora-report-reason-' . $opId . '"', $memberHtml);
        $this->assertStringContainsString('id="agora-report-reason-' . $replyId . '"', $memberHtml);
        $this->assertMatchesRegularExpression('/name="report_reason"[^>]*\brequired\b/', $memberHtml);

        $rows = AP_Forum::getPostsDisplayData($topicId, ['per_page' => 50], $this->db);
        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertTrue((bool) ($row['can_report'] ?? false), 'logged-in viewer can report');
        }
    }

    public function testReportPostFormHtmlRequiresPostIdAndReason(): void
    {
        $this->assertSame('', ap_forum_report_post_form_html(0));
        $html = ap_forum_report_post_form_html(12, ['post_number' => 3]);
        $this->assertStringContainsString('ap_forum_report_post', $html);
        $this->assertStringContainsString('name="post_id"', $html);
        $this->assertStringContainsString('value="12"', $html);
        $this->assertStringContainsString('name="report_reason"', $html);
        $this->assertStringContainsString('required', $html);
        $this->assertStringContainsString('maxlength="255"', $html);
        $this->assertStringContainsString('>Report</button>', $html);
        $this->assertStringContainsString('aria-label="Report post #3"', $html);
    }

    /**
     * @return array{0: int, 1: int} first post id, reply id
     */
    private function seedTopicWithReply(): array
    {
        $forumId = AP_Forum::insertForum(['forum_name' => 'Report board'], $this->db);
        $topicId = AP_Forum::createTopic([
            'forum_id' => $forumId,
            'topic_title' => 'Report thread',
            'content' => 'Opening',
            'poster_id' => $this->adminId,
        ], $this->db);
        $topic = AP_Forum::getTopic($topicId, $this->db);
        $firstId = (int) ($topic?->first_post_id ?? 0);
        $this->assertGreaterThan(0, $firstId);
        $replyId = AP_Forum::createReply([
            'topic_id' => $topicId,
            'content' => 'Reply',
            'poster_id' => $this->adminId,
        ], $this->db);
        $this->assertGreaterThan(0, $replyId);

        return [$firstId, $replyId];
    }

    private function createSubscriber(string $login): int
    {
        $created = AP_User::create([
            'user_login' => $login,
            'user_email' => $login . '@example.test',
            'user_pass' => 'Password123!',
            'display_name' => $login,
            'role' => 'subscriber',
        ], $this->db);
        $this->assertTrue($created['ok'] ?? false, implode('; ', $created['errors'] ?? ['create failed']));
        $id = (int) ($created['id'] ?? 0);
        $this->assertGreaterThan(0, $id);

        return $id;
    }

    private function openReportCount(int $postId, int $reporterId = 0): int
    {
        $args = [
            'status' => AP_Forum_Moderation::REPORT_STATUS_OPEN,
            'type' => AP_Forum_Moderation::REPORT_TYPE_POST,
            'object_id' => $postId,
        ];
        if ($reporterId > 0) {
            $args['reporter_id'] = $reporterId;
        }

        return count(AP_Forum_Moderation::queryReports($args, $this->db));
    }

    private function reportCount(int $postId, int $reporterId = 0): int
    {
        $args = [
            'type' => AP_Forum_Moderation::REPORT_TYPE_POST,
            'object_id' => $postId,
        ];
        if ($reporterId > 0) {
            $args['reporter_id'] = $reporterId;
        }

        return count(AP_Forum_Moderation::queryReports($args, $this->db));
    }
}
