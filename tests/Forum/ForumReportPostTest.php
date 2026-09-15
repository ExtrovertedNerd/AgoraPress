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
use AP_Forum_Moderation_Queue;
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
        require_once $this->root . '/ap-admin/includes/class-ap-forum-moderation-queue.php';
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

        $this->assertSame('ap_reports', $this->db->reports);
        $raw = $this->rawReportRow($postId, $memberId);
        $this->assertNotNull($raw);
        $this->assertSame('post', (string) ($raw->report_type ?? ''));
        $this->assertSame('open', (string) ($raw->report_status ?? ''));
        $this->assertSame($memberId, (int) ($raw->reporter_id ?? 0));
        $this->assertSame($postId, (int) ($raw->report_object_id ?? 0));
        $this->assertSame('spam', (string) ($raw->report_reason ?? ''));
        $this->assertTrue(
            $raw->resolved_at === null || $raw->resolved_at === '',
            'new report is unresolved'
        );
        $this->assertSame(0, (int) ($raw->resolved_by ?? 0));

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
        $this->assertSame(1, AP_Forum_Moderation::countReports([
            'status' => AP_Forum_Moderation::REPORT_STATUS_OPEN,
            'type' => AP_Forum_Moderation::REPORT_TYPE_POST,
        ], $this->db));

        $row = AP_Forum::getPost($postId, $this->db);
        $this->assertSame(1, (int) ($row?->post_reported ?? 0));

        $queue = $this->reportsQueue();
        $this->assertSame('reports', $queue->view);
        $this->assertSame(1, $queue->openReportCount);
        $this->assertCount(1, $queue->reports);
        $this->assertSame($postId, (int) ($queue->reports[0]->report_object_id ?? 0));
        $this->assertSame($memberId, (int) ($queue->reports[0]->reporter_id ?? 0));
        $this->assertSame('post', (string) ($queue->reports[0]->report_type ?? ''));
        $this->assertSame('open', (string) ($queue->reports[0]->report_status ?? ''));
        $this->assertSame('spam', (string) ($queue->reports[0]->report_reason ?? ''));

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

        AP_Forum_Front::setNotice(null);
        $noNonce = AP_Forum_Front::handlePost([
            'ap_forum_action' => AP_Forum_Front::ACTION_REPORT_POST,
            'post_id' => $postId,
            'report_reason' => 'spam',
        ], $this->db);
        $this->assertNull($noNonce);
        $this->assertSame(0, $this->openReportCount($postId));

        $this->assertSame(0, AP_Forum_Moderation::createReport([
            'reporter_id' => 0,
            'report_type' => 'post',
            'report_object_id' => $postId,
            'report_reason' => 'spam',
        ], $this->db));
        $this->assertSame(0, $this->openReportCount($postId));
        $this->assertSame(0, AP_Forum_Moderation::countReports([
            'status' => AP_Forum_Moderation::REPORT_STATUS_OPEN,
        ], $this->db));
        $this->assertSame([], AP_Forum_Moderation::openReportObjectIdSet(
            0,
            AP_Forum_Moderation::REPORT_TYPE_POST,
            [$postId],
            $this->db
        ));

        $first = AP_Forum::getPost($postId, $this->db);
        $this->assertNotNull($first);
        $topicId = (int) ($first->topic_id ?? 0);
        $guestRows = AP_Forum::getPostsDisplayData($topicId, ['per_page' => 50], $this->db);
        $this->assertNotEmpty($guestRows);
        foreach ($guestRows as $row) {
            $this->assertFalse((bool) ($row['can_report'] ?? true), 'guest has no Report form');
        }

        $queue = $this->reportsQueue();
        $this->assertSame(0, $queue->openReportCount);
        $this->assertSame([], $queue->reports);
    }

    /**
     * SPEC: duplicate open report refused. After dismiss or resolve, a new
     * open report is allowed (still one open row per user per post).
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

        $queue = $this->reportsQueue();
        $this->assertSame(1, $queue->openReportCount);
        $this->assertCount(1, $queue->reports);

        $open = AP_Forum_Moderation::queryReports([
            'status' => 'open',
            'object_id' => $postId,
            'reporter_id' => $memberId,
        ], $this->db);
        $this->assertCount(1, $open);
        $this->assertTrue(AP_Forum_Moderation::dismissReport(
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
        $afterDismiss = AP_Forum_Front::handlePost([
            'ap_forum_action' => AP_Forum_Front::ACTION_REPORT_POST,
            'post_id' => $postId,
            'report_reason' => 'it came back after dismiss',
            '_ap_nonce' => AP_Nonce::create('ap_forum_report_post_' . $postId, $memberId),
        ], $this->db);
        $this->assertIsString($afterDismiss);
        $this->assertSame(1, $this->openReportCount($postId, $memberId));
        $this->assertSame(2, $this->reportCount($postId, $memberId));

        AP_Forum_Front::setNotice(null);
        $dupAfterDismiss = AP_Forum_Front::handlePost([
            'ap_forum_action' => AP_Forum_Front::ACTION_REPORT_POST,
            'post_id' => $postId,
            'report_reason' => 'still open',
            '_ap_nonce' => AP_Nonce::create('ap_forum_report_post_' . $postId, $memberId),
        ], $this->db);
        $this->assertNull($dupAfterDismiss);
        $dupNotice = AP_Forum_Front::getNotice();
        $this->assertSame('error', $dupNotice['type'] ?? null);
        $this->assertSame('You have already reported this post.', $dupNotice['message'] ?? null);
        $this->assertSame(1, $this->openReportCount($postId, $memberId));

        $openAgain = AP_Forum_Moderation::queryReports([
            'status' => 'open',
            'object_id' => $postId,
            'reporter_id' => $memberId,
        ], $this->db);
        $this->assertCount(1, $openAgain);
        $this->assertTrue(AP_Forum_Moderation::resolveReport(
            (int) $openAgain[0]->report_id,
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
        $afterResolve = AP_Forum_Front::handlePost([
            'ap_forum_action' => AP_Forum_Front::ACTION_REPORT_POST,
            'post_id' => $postId,
            'report_reason' => 'it came back',
            '_ap_nonce' => AP_Nonce::create('ap_forum_report_post_' . $postId, $memberId),
        ], $this->db);
        $this->assertIsString($afterResolve);
        $this->assertSame(1, $this->openReportCount($postId, $memberId));
        $this->assertSame(3, $this->reportCount($postId, $memberId));
        $this->assertSame(1, $this->reportsQueue()->openReportCount);
    }

    /**
     * SPEC: one open report *per user* per post — a second member may still file.
     */
    public function testTwoUsersMayEachHaveOneOpenReportOnSamePost(): void
    {
        $firstUser = $this->createSubscriber('report_a');
        $secondUser = $this->createSubscriber('report_b');
        [$postId] = $this->seedTopicWithReply();

        $this->assertTrue(AP_Session::setAuthCookie($firstUser, false, $this->db));
        $first = AP_Forum_Front::handlePost([
            'ap_forum_action' => AP_Forum_Front::ACTION_REPORT_POST,
            'post_id' => $postId,
            'report_reason' => 'spam',
            '_ap_nonce' => AP_Nonce::create('ap_forum_report_post_' . $postId, $firstUser),
        ], $this->db);
        $this->assertIsString($first);
        $this->assertSame(1, $this->openReportCount($postId, $firstUser));

        $this->assertTrue(AP_Session::setAuthCookie($secondUser, false, $this->db));
        $second = AP_Forum_Front::handlePost([
            'ap_forum_action' => AP_Forum_Front::ACTION_REPORT_POST,
            'post_id' => $postId,
            'report_reason' => 'also spam',
            '_ap_nonce' => AP_Nonce::create('ap_forum_report_post_' . $postId, $secondUser),
        ], $this->db);
        $this->assertIsString($second);
        $this->assertSame(1, $this->openReportCount($postId, $secondUser));
        $this->assertSame(2, $this->openReportCount($postId));

        $set = AP_Forum_Moderation::openReportObjectIdSet(
            $firstUser,
            AP_Forum_Moderation::REPORT_TYPE_POST,
            [$postId],
            $this->db
        );
        $this->assertTrue($set[$postId] ?? false);
        $this->assertTrue(ap_forum_has_open_report(
            $firstUser,
            AP_Forum_Moderation::REPORT_TYPE_POST,
            $postId,
            $this->db
        ));
    }

    /**
     * POST ap_forum_report_post always inserts type post / status open.
     * Crafted type, status, and object id on the form are ignored.
     */
    public function testReportPostInsertIgnoresCraftedTypeAndStatus(): void
    {
        $memberId = $this->createSubscriber('report_crafted');
        [$postId] = $this->seedTopicWithReply();

        $this->assertTrue(AP_Session::setAuthCookie($memberId, false, $this->db));
        $redirect = AP_Forum_Front::handlePost([
            'ap_forum_action' => AP_Forum_Front::ACTION_REPORT_POST,
            'post_id' => $postId,
            'report_reason' => 'spam',
            'report_type' => 'user',
            'type' => 'topic',
            'report_status' => 'closed',
            'status' => 'dismissed',
            'report_object_id' => 99999,
            'object_id' => 88888,
            '_ap_nonce' => AP_Nonce::create('ap_forum_report_post_' . $postId, $memberId),
        ], $this->db);

        $this->assertIsString($redirect);
        $this->assertSame('ap_reports', $this->db->reports);
        $raw = $this->rawReportRow($postId, $memberId);
        $this->assertNotNull($raw);
        $this->assertSame('post', (string) ($raw->report_type ?? ''));
        $this->assertSame('open', (string) ($raw->report_status ?? ''));
        $this->assertSame($postId, (int) ($raw->report_object_id ?? 0));
        $this->assertSame($memberId, (int) ($raw->reporter_id ?? 0));
        $this->assertSame(0, $this->reportCount(99999, $memberId));
        $this->assertSame(0, $this->reportCount(88888, $memberId));
    }

    public function testReasonRequired(): void
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

    /**
     * Failed INSERT (false or zero rows, or no insert id) must not flash
     * "Report submitted." / post_reported. ACP already lists {prefix}reports.
     */
    public function testFailedInsertDoesNotClaimSuccess(): void
    {
        $memberId = $this->createSubscriber('report_fail');
        [$postId] = $this->seedTopicWithReply();
        $payload = [
            'reporter_id' => $memberId,
            'report_type' => AP_Forum_Moderation::REPORT_TYPE_POST,
            'report_object_id' => $postId,
            'report_reason' => 'spam',
        ];

        $createdFired = 0;
        if (function_exists('ap_add_action')) {
            ap_add_action('ap_report_created', static function () use (&$createdFired): void {
                $createdFired++;
            });
        }

        $falseInsert = $this->reportsInsertFailsDb();
        $this->assertSame(0, AP_Forum_Moderation::createReport($payload, $falseInsert));
        $this->assertSame(1, $falseInsert->reportInsertAttempts);
        $this->assertSame(0, $this->openReportCount($postId));
        $this->assertSame(0, $createdFired);

        $zeroRows = $this->reportsInsertFailsDb();
        $zeroRows->reportsInsertResult = 0;
        $this->assertSame(0, AP_Forum_Moderation::createReport($payload, $zeroRows));
        $this->assertSame(1, $zeroRows->reportInsertAttempts);
        $this->assertSame(0, $this->openReportCount($postId));
        $this->assertSame(0, $createdFired);

        $this->assertTrue(AP_Session::setAuthCookie($memberId, false, $this->db));
        $failing = $this->reportsInsertFailsDb();
        $redirect = AP_Forum_Front::handlePost([
            'ap_forum_action' => AP_Forum_Front::ACTION_REPORT_POST,
            'post_id' => $postId,
            'report_reason' => 'spam',
            '_ap_nonce' => AP_Nonce::create('ap_forum_report_post_' . $postId, $memberId),
        ], $failing);
        $this->assertNull($redirect);
        $notice = AP_Forum_Front::getNotice();
        $this->assertSame('error', $notice['type'] ?? null);
        $this->assertSame('Could not submit the report.', $notice['message'] ?? null);
        $this->assertStringNotContainsString('Report submitted.', (string) ($notice['message'] ?? ''));
        $this->assertSame(0, $this->openReportCount($postId));
        $this->assertSame(0, $createdFired);
        $this->assertGreaterThanOrEqual(1, $failing->reportInsertAttempts);

        $post = AP_Forum::getPost($postId, $this->db);
        $this->assertSame(0, (int) ($post?->post_reported ?? 0));

        AP_Forum_Front::setNotice(null);
        $noId = $this->reportsLastInsertIdZeroDb();
        $noIdRedirect = AP_Forum_Front::handlePost([
            'ap_forum_action' => AP_Forum_Front::ACTION_REPORT_POST,
            'post_id' => $postId,
            'report_reason' => 'spam',
            '_ap_nonce' => AP_Nonce::create('ap_forum_report_post_' . $postId, $memberId),
        ], $noId);
        $this->assertNull($noIdRedirect);
        $noIdNotice = AP_Forum_Front::getNotice();
        $this->assertSame('error', $noIdNotice['type'] ?? null);
        $this->assertSame('Could not submit the report.', $noIdNotice['message'] ?? null);
        $this->assertSame(0, $createdFired);
        $postAfter = AP_Forum::getPost($postId, $this->db);
        $this->assertSame(0, (int) ($postAfter?->post_reported ?? 0));
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
        $this->assertFalse(ap_forum_is_report_flooding($this->adminId, $this->db));
    }

    /**
     * Flood interval 0 (tests / Settings) does not block a second report.
     */
    public function testFloodOffAllowsRapidReportsOnDifferentPosts(): void
    {
        $this->assertSame(0, AP_Forum_Guard::getFloodInterval($this->db));
        $memberId = $this->createSubscriber('report_rapid');
        [$firstId, $secondId] = $this->seedTopicWithReply();

        $this->assertTrue(AP_Session::setAuthCookie($memberId, false, $this->db));
        $first = AP_Forum_Front::handlePost([
            'ap_forum_action' => AP_Forum_Front::ACTION_REPORT_POST,
            'post_id' => $firstId,
            'report_reason' => 'first',
            '_ap_nonce' => AP_Nonce::create('ap_forum_report_post_' . $firstId, $memberId),
        ], $this->db);
        $this->assertIsString($first);
        $this->assertFalse(AP_Forum_Moderation::isReportFlooding($memberId, $this->db));
        $this->assertFalse(ap_forum_is_report_flooding($memberId, $this->db));

        AP_Forum_Front::setNotice(null);
        $second = AP_Forum_Front::handlePost([
            'ap_forum_action' => AP_Forum_Front::ACTION_REPORT_POST,
            'post_id' => $secondId,
            'report_reason' => 'second',
            '_ap_nonce' => AP_Nonce::create('ap_forum_report_post_' . $secondId, $memberId),
        ], $this->db);
        $this->assertIsString($second);
        $this->assertSame(1, $this->openReportCount($firstId, $memberId));
        $this->assertSame(1, $this->openReportCount($secondId, $memberId));
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

    /**
     * One open report per user per post: form is omitted for that post, not others.
     */
    public function testOpenReportHidesFormForReporterNotOtherPostsOrUsers(): void
    {
        $memberId = $this->createSubscriber('report_hide');
        $otherId = $this->createSubscriber('report_peer');
        [$firstId, $secondId] = $this->seedTopicWithReply();
        $first = AP_Forum::getPost($firstId, $this->db);
        $this->assertNotNull($first);
        $topicId = (int) ($first->topic_id ?? 0);
        $topic = AP_Forum::getTopic($topicId, $this->db);
        $this->assertNotNull($topic);

        $this->assertTrue(AP_Session::setAuthCookie($memberId, false, $this->db));
        $redirect = AP_Forum_Front::handlePost([
            'ap_forum_action' => AP_Forum_Front::ACTION_REPORT_POST,
            'post_id' => $firstId,
            'report_reason' => 'spam',
            '_ap_nonce' => AP_Nonce::create('ap_forum_report_post_' . $firstId, $memberId),
        ], $this->db);
        $this->assertIsString($redirect);

        $memberRows = AP_Forum::getPostsDisplayData($topicId, ['per_page' => 50], $this->db);
        $byId = [];
        foreach ($memberRows as $row) {
            $byId[(int) ($row['id'] ?? 0)] = $row;
        }
        $this->assertFalse((bool) ($byId[$firstId]['can_report'] ?? true), 'already reported');
        $this->assertTrue((bool) ($byId[$secondId]['can_report'] ?? false), 'other post still reportable');

        $vars = AP_Rewrite::parseRequest('topic/' . $topic->topic_slug, [], $this->db);
        $memberQuery = AP_Rewrite::queryFromVars($vars, $this->db);
        AP_Forum_Front::applyToQuery($memberQuery, $this->db);
        ap_set_query($memberQuery);
        ob_start();
        AP_Theme::render($memberQuery, $this->db);
        $memberHtml = (string) ob_get_clean();
        $this->assertStringNotContainsString('id="agora-report-reason-' . $firstId . '"', $memberHtml);
        $this->assertStringContainsString('id="agora-report-reason-' . $secondId . '"', $memberHtml);

        $this->assertTrue(AP_Session::setAuthCookie($otherId, false, $this->db));
        $peerRows = AP_Forum::getPostsDisplayData($topicId, ['per_page' => 50], $this->db);
        foreach ($peerRows as $row) {
            $this->assertTrue((bool) ($row['can_report'] ?? false), 'peer still sees Report');
        }

        AP_Session::clearAuthCookie();
        AP_Session::resetCurrentUser();
        $guestRows = AP_Forum::getPostsDisplayData($topicId, ['per_page' => 50], $this->db);
        foreach ($guestRows as $row) {
            $this->assertFalse((bool) ($row['can_report'] ?? true), 'guest still has no form');
        }
        $guestQuery = AP_Rewrite::queryFromVars($vars, $this->db);
        AP_Forum_Front::applyToQuery($guestQuery, $this->db);
        ap_set_query($guestQuery);
        ob_start();
        AP_Theme::render($guestQuery, $this->db);
        $guestHtml = (string) ob_get_clean();
        $this->assertStringNotContainsString('ap_forum_report_post', $guestHtml);
        $this->assertStringNotContainsString('name="report_reason"', $guestHtml);
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

    private function rawReportRow(int $postId, int $reporterId): ?object
    {
        $table = $this->db->quoteIdentifier($this->db->reports);

        return $this->db->getRow(
            'SELECT * FROM ' . $table
            . ' WHERE ' . $this->db->quoteIdentifier('report_object_id') . ' = ?'
            . ' AND ' . $this->db->quoteIdentifier('reporter_id') . ' = ?'
            . ' ORDER BY ' . $this->db->quoteIdentifier('report_id') . ' DESC',
            [$postId, $reporterId]
        );
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

    private function reportsQueue(): AP_Forum_Moderation_Queue
    {
        $queue = new AP_Forum_Moderation_Queue($this->db);
        $queue->prepare(['view' => 'reports']);

        return $queue;
    }

    private function reportsInsertFailsDb(): ForumReportInsertFailsDb
    {
        return new ForumReportInsertFailsDb($this->db->pdo(), 'sqlite', $this->db->getPrefix());
    }

    private function reportsLastInsertIdZeroDb(): ForumReportLastInsertIdZeroDb
    {
        return new ForumReportLastInsertIdZeroDb($this->db->pdo(), 'sqlite', $this->db->getPrefix());
    }
}

/**
 * Test double: `{prefix}reports` INSERT never lands (false or zero rows).
 */
final class ForumReportInsertFailsDb extends AP_DB
{
    public int $reportInsertAttempts = 0;

    public int|false $reportsInsertResult = false;

    public function insert(string $table, array $data): int|false
    {
        if ($table === 'reports') {
            $this->reportInsertAttempts++;

            return $this->reportsInsertResult;
        }

        return parent::insert($table, $data);
    }
}

/**
 * Test double: INSERT may land, but lastInsertId() is always 0.
 */
final class ForumReportLastInsertIdZeroDb extends AP_DB
{
    public function lastInsertId(): string
    {
        return '0';
    }
}
