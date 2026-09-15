<?php

/**
 * Tests for forum front-end: rewrite routes, templates, forms.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Forum;

use AP_DB;
use AP_Forum;
use AP_Forum_Front;
use AP_Forum_Moderation;
use AP_Forum_Notify;
use AP_Forum_Permissions;
use AP_Group;
use AP_Migrator;
use AP_Nonce;
use AP_Options;
use AP_Query;
use AP_Rewrite;
use AP_Roles;
use AP_Session;
use AP_Theme;
use AP_User;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_Forum_Front::class)]
final class ForumFrontTest extends TestCase
{
    private string $root;

    private AP_DB $db;

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
        require_once $this->root . '/ap-includes/class-ap-session.php';
        require_once $this->root . '/ap-includes/class-ap-post.php';
        require_once $this->root . '/ap-includes/class-ap-query.php';
        require_once $this->root . '/ap-includes/class-ap-rewrite.php';
        require_once $this->root . '/ap-includes/class-ap-nonce.php';
        require_once $this->root . '/ap-includes/class-ap-group.php';
        require_once $this->root . '/ap-includes/class-ap-forum-permissions.php';
        require_once $this->root . '/ap-includes/class-ap-forum.php';
        require_once $this->root . '/ap-includes/class-ap-forum-moderation.php';
        require_once $this->root . '/ap-includes/class-ap-forum-read.php';
        require_once $this->root . '/ap-includes/class-ap-forum-notify.php';
        require_once $this->root . '/ap-includes/class-ap-forum-front.php';
        require_once $this->root . '/ap-includes/class-ap-content-format.php';
        require_once $this->root . '/ap-includes/hooks.php';
        require_once $this->root . '/ap-includes/class-ap-theme.php';
        require_once $this->root . '/ap-includes/class-ap-assets.php';
        require_once $this->root . '/ap-includes/functions.php';
        require_once $this->root . '/ap-includes/template-tags.php';

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
                'blogname' => 'Forum Front Site',
                'ap_module_forum' => '1',
            ] as $name => $value
        ) {
            $this->db->insert('options', [
                'option_name' => $name,
                'option_value' => $value,
                'autoload' => 'yes',
            ]);
        }

        $created = AP_User::create([
            'user_login' => 'poster',
            'user_email' => 'poster@example.test',
            'user_pass' => 'Password123!',
            'display_name' => 'Poster',
            'role' => 'administrator',
        ], $this->db);
        $this->assertTrue($created['ok'] ?? false, implode('; ', $created['errors'] ?? ['create failed']));
        $this->userId = (int) ($created['id'] ?? 0);
        $this->assertGreaterThan(0, $this->userId);

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

    public function testNotifyChromeQueryArgFollowsSiteOption(): void
    {
        require_once $this->root . '/ap-includes/class-ap-forum-notify.php';

        $off = AP_Forum_Front::enrichQueryArgs([
            'ap_forum_view' => 'index',
        ], $this->db);
        $this->assertArrayHasKey('forum_topic_notify_enabled', $off);
        $this->assertFalse((bool) $off['forum_topic_notify_enabled']);

        AP_Options::update('forum_topic_notify_enabled', '1', $this->db);
        $on = AP_Forum_Front::enrichQueryArgs([
            'ap_forum_view' => 'topic',
        ], $this->db);
        $this->assertTrue((bool) $on['forum_topic_notify_enabled']);

        AP_Options::update('forum_topic_notify_enabled', '0', $this->db);
        $again = AP_Forum_Front::enrichQueryArgs([
            'ap_forum_view' => 'forum',
        ], $this->db);
        $this->assertFalse((bool) $again['forum_topic_notify_enabled']);
        $this->assertFalse((bool) ($off['can_subscribe'] ?? true));
        $this->assertFalse((bool) ($on['can_subscribe'] ?? true));
        $this->assertFalse((bool) ($again['can_subscribe'] ?? true));
        $this->assertFalse((bool) ($on['topic_subscribed'] ?? true));
    }

    public function testRewriteRulesIncludeForumRoutes(): void
    {
        $rules = AP_Rewrite::generateRules($this->db);
        $this->assertArrayHasKey('forums/?$', $rules);
        $this->assertSame('ap_forum_view=index', $rules['forums/?$']);
        $this->assertArrayHasKey('forums/([^/]+)/?$', $rules);
        $this->assertArrayHasKey('topic/([^/]+)/?$', $rules);
    }

    public function testParseRequestForumIndexAndForumSlug(): void
    {
        $index = AP_Rewrite::parseRequest('forums', [], $this->db);
        $this->assertSame('index', $index['ap_forum_view'] ?? null);

        $forumId = AP_Forum::insertForum([
            'forum_name' => 'General Chat',
            'forum_type' => 'forum',
        ], $this->db);
        $this->assertGreaterThan(0, $forumId);
        $forum = AP_Forum::getForum($forumId, $this->db);
        $this->assertNotNull($forum);

        $vars = AP_Rewrite::parseRequest('forums/' . $forum->forum_slug, [], $this->db);
        $this->assertSame('forum', $vars['ap_forum_view'] ?? null);
        $this->assertSame($forum->forum_slug, $vars['forum_slug'] ?? null);

        $args = AP_Rewrite::toQueryArgs($vars, $this->db);
        $this->assertSame($forumId, (int) ($args['forum_id'] ?? 0));
        $this->assertSame('General Chat', $args['forum_name'] ?? null);
    }

    public function testParseRequestTopicSlugAndRender(): void
    {
        $forumId = AP_Forum::insertForum(['forum_name' => 'News'], $this->db);
        $topicId = AP_Forum::createTopic([
            'forum_id' => $forumId,
            'topic_title' => 'Hello World',
            'content' => 'First post body with **markdown**.',
            'poster_id' => $this->userId,
        ], $this->db);
        $this->assertGreaterThan(0, $topicId);
        $topic = AP_Forum::getTopic($topicId, $this->db);
        $this->assertNotNull($topic);

        $vars = AP_Rewrite::parseRequest('topic/' . $topic->topic_slug, [], $this->db);
        $this->assertSame('topic', $vars['ap_forum_view'] ?? null);

        $query = AP_Rewrite::queryFromVars($vars, $this->db);
        AP_Forum_Front::applyToQuery($query, $this->db);
        ap_set_query($query);

        $this->assertSame($topicId, (int) $query->get('topic_id', 0));
        $this->assertSame('Hello World', (string) $query->get('topic_title', ''));
        $this->assertSame('topic.php', AP_Theme::getHierarchy($query, $this->db)[0] ?? null);

        ob_start();
        AP_Theme::render($query, $this->db);
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('Hello World', $html);
        $this->assertStringContainsString('ap-forum-post', $html);
        $this->assertStringContainsString('ap-forum-post--two-pane', $html);
        $this->assertStringContainsString('ap-forum-post__author', $html);
        $this->assertStringContainsString('ap-forum-post__main', $html);
        $this->assertStringContainsString('ap-forum-post__body', $html);
        $this->assertStringContainsString('First post body', $html);
        // SPEC B2 — Top of page control (in-page anchor to topic top).
        $this->assertStringContainsString('id="ap-topic-top"', $html);
        $this->assertStringContainsString('ap-forum-post__top', $html);
        $this->assertStringContainsString('href="#ap-topic-top"', $html);
        // Guests see a login prompt instead of the reply form.
        $this->assertStringContainsString('Log in', $html);
        // Site notify default off: no Subscribe chrome for guests.
        $this->assertStringNotContainsString('ap_forum_subscribe_topic', $html);
        $this->assertStringNotContainsString('ap-forum-subscribe', $html);
        $this->assertStringNotContainsString('Notify me of replies', $html);
        $this->assertStringNotContainsString('name="notify_replies"', $html);

        // Logged-in user with ACL sees the reply form.
        $this->assertTrue(AP_Session::setAuthCookie($this->userId, false, $this->db));
        $query2 = AP_Rewrite::queryFromVars($vars, $this->db);
        AP_Forum_Front::applyToQuery($query2, $this->db);
        ap_set_query($query2);
        $this->assertTrue((bool) $query2->get('can_reply', false));

        ob_start();
        AP_Theme::render($query2, $this->db);
        $html2 = (string) ob_get_clean();
        $this->assertStringContainsString('name="ap_forum_action"', $html2);
        $this->assertStringContainsString('ap_forum_reply', $html2);
        $this->assertStringContainsString('name="reply_body"', $html2);
        // SPEC B2: Quote → Edit → Like for users who can reply.
        $this->assertStringContainsString('ap-forum-quote', $html2);
        $this->assertStringContainsString('>Quote</a>', $html2);
        $this->assertStringContainsString('ap_forum_like_post', $html2);
        $this->assertStringContainsString('ap-forum-like', $html2);
        $this->assertMatchesRegularExpression('/\bLike\b/', $html2);
        // Author is admin → can edit own OP.
        $this->assertStringContainsString('edit_post=', $html2);
        $this->assertStringContainsString('>Edit</a>', $html2);
        $this->assertStringNotContainsString('ap_forum_subscribe_topic', $html2);
        $this->assertStringNotContainsString('ap-forum-subscribe', $html2);
        $this->assertStringNotContainsString('Notify me of replies', $html2);
        $this->assertStringNotContainsString('name="notify_replies"', $html2);
    }

    public function testTopicViewMarksReadOnView(): void
    {
        $forumId = AP_Forum::insertForum(['forum_name' => 'Mark on view forum'], $this->db);
        $topicId = AP_Forum::createTopic([
            'forum_id' => $forumId,
            'topic_title' => 'Read on view',
            'content' => 'Body for mark-on-view.',
            'poster_id' => $this->userId,
        ], $this->db);
        $this->assertGreaterThan(0, $topicId);
        $topic = AP_Forum::getTopic($topicId, $this->db);
        $this->assertNotNull($topic);

        // Past post times so page mark (post_time) covers the thread.
        $posts = AP_Forum::getPosts($topicId, ['per_page' => 5], $this->db);
        $this->assertNotEmpty($posts);
        $opId = (int) $posts[0]->post_id;
        $opTime = date('Y-m-d H:i:s', time() - 120);
        $this->db->update('forum_posts', ['post_time' => $opTime], ['post_id' => $opId]);
        $this->db->update(
            'topics',
            [
                'topic_time' => $opTime,
                'topic_last_post_time' => $opTime,
            ],
            ['topic_id' => $topicId]
        );

        $readerCreated = AP_User::create([
            'user_login' => 'view_marker',
            'user_email' => 'view_marker@example.test',
            'user_pass' => 'Password123!',
            'display_name' => 'View Marker',
            'role' => 'subscriber',
        ], $this->db);
        $this->assertTrue($readerCreated['ok'] ?? false);
        $readerId = (int) ($readerCreated['id'] ?? 0);
        $this->assertGreaterThan(0, $readerId);

        $this->assertTrue(\AP_Forum_Read::isTopicUnread($readerId, $topicId, $this->db));
        $this->assertNull(\AP_Forum_Read::getTopicMarkTime($readerId, $topicId, $this->db));

        // Guest view: no track rows.
        AP_Session::resetCurrentUser();
        $vars = AP_Rewrite::parseRequest('topic/' . $topic->topic_slug, [], $this->db);
        $guestQuery = AP_Rewrite::queryFromVars($vars, $this->db);
        AP_Forum_Front::applyToQuery($guestQuery, $this->db);
        $this->assertNull(\AP_Forum_Read::getTopicMarkTime($readerId, $topicId, $this->db));
        $this->assertTrue(\AP_Forum_Read::isTopicUnread($readerId, $topicId, $this->db));

        // Logged-in view: mark posts read per AP_Forum_Read rules.
        $this->assertTrue(AP_Session::setAuthCookie($readerId, false, $this->db));
        $query = AP_Rewrite::queryFromVars($vars, $this->db);
        AP_Forum_Front::applyToQuery($query, $this->db);
        $this->assertFalse(\AP_Forum_Read::isTopicUnread($readerId, $topicId, $this->db));
        $this->assertSame($opTime, \AP_Forum_Read::getTopicMarkTime($readerId, $topicId, $this->db));
        // First request still exposes first unread (resolved before mark).
        $this->assertSame($opId, (int) $query->get('first_unread_post_id', 0));

        // Second view: fully read → no first-unread jump id.
        $query2 = AP_Rewrite::queryFromVars($vars, $this->db);
        AP_Forum_Front::applyToQuery($query2, $this->db);
        $this->assertSame(0, (int) $query2->get('first_unread_post_id', 0));
        $this->assertFalse(\AP_Forum_Read::isTopicUnread($readerId, $topicId, $this->db));
    }

    public function testTopicViewFirstUnreadLinkAboveOp(): void
    {
        $forumId = AP_Forum::insertForum(['forum_name' => 'Unread Jump'], $this->db);
        $topicId = AP_Forum::createTopic([
            'forum_id' => $forumId,
            'topic_title' => 'Jump to unread',
            'content' => 'Original post body.',
            'poster_id' => $this->userId,
        ], $this->db);
        $this->assertGreaterThan(0, $topicId);
        $topic = AP_Forum::getTopic($topicId, $this->db);
        $this->assertNotNull($topic);
        $firstPostId = (int) ($topic->first_post_id ?? 0);
        $this->assertGreaterThan(0, $firstPostId);

        $op = AP_Forum::getPost($firstPostId, $this->db);
        $this->assertNotNull($op);
        // Anchor times in the past so mark-on-view (uses "now") fully covers the thread.
        $opTime = date('Y-m-d H:i:s', time() - 300);
        $this->db->update('forum_posts', ['post_time' => $opTime], ['post_id' => $firstPostId]);
        $this->db->update(
            'topics',
            [
                'topic_time' => $opTime,
                'topic_last_post_time' => $opTime,
            ],
            ['topic_id' => $topicId]
        );

        // Mark reader through OP, then add a strictly newer reply (still before "now").
        $this->assertTrue(\AP_Forum_Read::markTopicRead($this->userId, $topicId, $this->db, [
            'mark_time' => $opTime,
        ]));
        $replyId = AP_Forum::createReply([
            'topic_id' => $topicId,
            'content' => 'Unread reply body.',
            'poster_id' => $this->userId,
        ], $this->db);
        $this->assertGreaterThan(0, $replyId);
        $replyTime = date('Y-m-d H:i:s', time() - 60);
        $this->db->update('forum_posts', ['post_time' => $replyTime], ['post_id' => $replyId]);
        $this->db->update('topics', ['topic_last_post_time' => $replyTime], ['topic_id' => $topicId]);

        $vars = AP_Rewrite::parseRequest('topic/' . $topic->topic_slug, [], $this->db);
        $this->assertSame('topic', $vars['ap_forum_view'] ?? null);

        // Guest: no first-unread link (prefer hide).
        AP_Session::resetCurrentUser();
        $guestQuery = AP_Rewrite::queryFromVars($vars, $this->db);
        AP_Forum_Front::applyToQuery($guestQuery, $this->db);
        ap_set_query($guestQuery);
        $this->assertSame(0, (int) $guestQuery->get('first_unread_post_id', 0));
        ob_start();
        AP_Theme::render($guestQuery, $this->db);
        $guestHtml = (string) ob_get_clean();
        $this->assertStringNotContainsString('ap-forum-first-unread', $guestHtml);
        $this->assertStringNotContainsString('First unread post', $guestHtml);

        // Logged-in with unread reply: link above OP targets the reply.
        $this->assertTrue(AP_Session::setAuthCookie($this->userId, false, $this->db));
        $query = AP_Rewrite::queryFromVars($vars, $this->db);
        AP_Forum_Front::applyToQuery($query, $this->db);
        ap_set_query($query);
        $this->assertSame($replyId, (int) $query->get('first_unread_post_id', 0));

        ob_start();
        AP_Theme::render($query, $this->db);
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('ap-forum-first-unread-wrap', $html);
        $this->assertStringContainsString('ap-forum-first-unread', $html);
        $this->assertStringContainsString('First unread post', $html);
        $this->assertStringContainsString('href="#post-' . $replyId . '"', $html);
        // Link appears before the posts list / OP region.
        $linkPos = strpos($html, 'ap-forum-first-unread');
        $postsPos = strpos($html, 'ap-forum-posts');
        $this->assertNotFalse($linkPos);
        $this->assertNotFalse($postsPos);
        $this->assertLessThan($postsPos, $linkPos);

        // Second view after mark-on-read: hide the link.
        $query2 = AP_Rewrite::queryFromVars($vars, $this->db);
        AP_Forum_Front::applyToQuery($query2, $this->db);
        ap_set_query($query2);
        $this->assertSame(0, (int) $query2->get('first_unread_post_id', 0));
        ob_start();
        AP_Theme::render($query2, $this->db);
        $html2 = (string) ob_get_clean();
        $this->assertStringNotContainsString('ap-forum-first-unread', $html2);
        $this->assertStringNotContainsString('First unread post', $html2);
    }

    public function testTopicQuotePrefillsReplyForm(): void
    {
        $forumId = AP_Forum::insertForum(['forum_name' => 'Quotes'], $this->db);
        $topicId = AP_Forum::createTopic([
            'forum_id' => $forumId,
            'topic_title' => 'Cite me',
            'content' => 'Original body to quote.',
            'poster_id' => $this->userId,
        ], $this->db);
        $this->assertGreaterThan(0, $topicId);
        $topic = AP_Forum::getTopic($topicId, $this->db);
        $this->assertNotNull($topic);
        $posts = AP_Forum::getPosts($topicId, ['per_page' => 5], $this->db);
        $this->assertNotEmpty($posts);
        $postId = (int) $posts[0]->post_id;

        $this->assertTrue(AP_Session::setAuthCookie($this->userId, false, $this->db));
        $_GET['quote'] = (string) $postId;

        $vars = AP_Rewrite::parseRequest('topic/' . $topic->topic_slug, [], $this->db);
        $query = AP_Rewrite::queryFromVars($vars, $this->db);
        AP_Forum_Front::applyToQuery($query, $this->db);
        ap_set_query($query);

        ob_start();
        AP_Theme::render($query, $this->db);
        $html = (string) ob_get_clean();
        unset($_GET['quote']);

        // Core builds BBCode via AP_Forum::getQuoteMarkupForPost(); the visual
        // editor (when loaded) converts it to HTML for the reply surface.
        $hasBbcode = str_contains($html, '[quote=') && str_contains($html, '[/quote]');
        $hasHtmlQuote = str_contains($html, 'ap-quote') || str_contains($html, '<blockquote');
        $this->assertTrue(
            $hasBbcode || $hasHtmlQuote,
            'Reply form should prefill a citation (BBCode or HTML quote)'
        );
        $this->assertStringContainsString('Original body to quote.', $html);
        $this->assertStringContainsString('id="reply"', $html);
        $this->assertStringContainsString('ap-forum-quote', $html);
    }

    public function testRenderForumIndexWithLiveData(): void
    {
        $catId = AP_Forum::insertForum([
            'forum_name' => 'Community',
            'forum_type' => 'category',
        ], $this->db);
        AP_Forum::insertForum([
            'forum_name' => 'Introductions',
            'parent_id' => $catId,
            'forum_desc' => 'Say hello',
        ], $this->db);

        $vars = AP_Rewrite::parseRequest('forums/', [], $this->db);
        $query = AP_Rewrite::queryFromVars($vars, $this->db);
        AP_Forum_Front::applyToQuery($query, $this->db);
        ap_set_query($query);

        ob_start();
        AP_Theme::render($query, $this->db);
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('Community', $html);
        $this->assertStringContainsString('Introductions', $html);
        $this->assertStringContainsString('Say hello', $html);
        $this->assertStringContainsString('ap-forum--index', $html);
    }

    public function testRenderForumViewWithTopicsAndNewTopicForm(): void
    {
        $forumId = AP_Forum::insertForum([
            'forum_name' => 'Support Desk',
            'forum_desc' => 'Get help here',
        ], $this->db);
        $this->assertGreaterThan(0, $forumId);
        $topicId = AP_Forum::createTopic([
            'forum_id' => $forumId,
            'topic_title' => 'Where are the docs?',
            'content' => 'Looking for the handbook.',
            'poster_id' => $this->userId,
        ], $this->db);
        $this->assertGreaterThan(0, $topicId);
        $forum = AP_Forum::getForum($forumId, $this->db);
        $this->assertNotNull($forum);

        $vars = AP_Rewrite::parseRequest('forums/' . $forum->forum_slug, [], $this->db);
        $query = AP_Rewrite::queryFromVars($vars, $this->db);
        AP_Forum_Front::applyToQuery($query, $this->db);
        ap_set_query($query);

        $this->assertSame('forum', (string) $query->get('ap_forum_view', ''));
        $this->assertSame($forumId, (int) $query->get('forum_id', 0));
        $this->assertSame('forum-view.php', AP_Theme::getHierarchy($query, $this->db)[0] ?? null);

        // Guests: topic list + login prompt, no create form.
        ob_start();
        AP_Theme::render($query, $this->db);
        $guestHtml = (string) ob_get_clean();
        $this->assertStringContainsString('Support Desk', $guestHtml);
        $this->assertStringContainsString('Get help here', $guestHtml);
        $this->assertStringContainsString('Where are the docs?', $guestHtml);
        $this->assertStringContainsString('ap-forum--view', $guestHtml);
        $this->assertStringContainsString('Log in', $guestHtml);
        $this->assertStringNotContainsString('name="topic_title"', $guestHtml);

        // Logged-in administrator: new-topic form with nonce.
        $this->assertTrue(AP_Session::setAuthCookie($this->userId, false, $this->db));
        $query2 = AP_Rewrite::queryFromVars($vars, $this->db);
        AP_Forum_Front::applyToQuery($query2, $this->db);
        ap_set_query($query2);
        $this->assertTrue((bool) $query2->get('can_post_topic', false));

        ob_start();
        AP_Theme::render($query2, $this->db);
        $authHtml = (string) ob_get_clean();
        $this->assertStringContainsString('name="ap_forum_action"', $authHtml);
        $this->assertStringContainsString('ap_forum_new_topic', $authHtml);
        $this->assertStringContainsString('name="topic_title"', $authHtml);
        $this->assertStringContainsString('name="topic_body"', $authHtml);
        // Admin has sticky/announce — type select is visible with elevated options.
        $this->assertStringContainsString('name="topic_type"', $authHtml);
        $this->assertStringContainsString('value="sticky"', $authHtml);
        $this->assertStringContainsString('value="announcement"', $authHtml);
        $this->assertStringContainsString('value="rules"', $authHtml);
        $allowed = $query2->get('allowed_topic_types', []);
        $this->assertIsArray($allowed);
        $this->assertContains('standard', $allowed);
        $this->assertContains('sticky', $allowed);
        $this->assertContains('announcement', $allowed);
        $this->assertContains('rules', $allowed);
        $this->assertStringNotContainsString('Notify me of replies', $authHtml);
        $this->assertStringNotContainsString('name="notify_replies"', $authHtml);
    }

    public function testModuleDisabledShowsNoticeOnIndex(): void
    {
        AP_Options::update('ap_module_forum', '0', $this->db);
        AP_Options::flushCache();

        $vars = AP_Rewrite::parseRequest('forums/', [], $this->db);
        $query = AP_Rewrite::queryFromVars($vars, $this->db);
        AP_Forum_Front::applyToQuery($query, $this->db);
        ap_set_query($query);

        $this->assertNotEmpty($query->get('ap_forum_disabled', false));

        ob_start();
        AP_Theme::render($query, $this->db);
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('forum module is currently disabled', $html);
    }

    public function testPlainQueryVarsForumIndex(): void
    {
        AP_Rewrite::setStructure('', $this->db);
        $vars = AP_Rewrite::parseRequest('', ['ap_forum_view' => 'index'], $this->db);
        $this->assertSame('index', $vars['ap_forum_view'] ?? null);
        $args = AP_Rewrite::toQueryArgs($vars, $this->db);
        $this->assertSame('index', $args['ap_forum_view'] ?? null);
    }

    public function testCreateTopicAndReplyViaFrontHandler(): void
    {
        $forumId = AP_Forum::insertForum(['forum_name' => 'Help'], $this->db);
        $this->assertGreaterThan(0, $forumId);

        $this->assertTrue(AP_Session::setAuthCookie($this->userId, false, $this->db));
        $this->assertSame($this->userId, ap_get_current_user_id($this->db));

        $nonce = AP_Nonce::create('ap_forum_new_topic_' . $forumId, $this->userId);
        $redirect = AP_Forum_Front::handlePost([
            'ap_forum_action' => AP_Forum_Front::ACTION_NEW_TOPIC,
            'forum_id' => $forumId,
            'topic_title' => 'Need help',
            'topic_body' => 'How do I configure modules?',
            '_ap_nonce' => $nonce,
        ], $this->db);

        $this->assertIsString($redirect);
        $this->assertStringContainsString('ap_forum_notice=topic_created', (string) $redirect);

        $topics = AP_Forum::getTopics($forumId, [], $this->db);
        $this->assertCount(1, $topics);
        $topicId = (int) $topics[0]->topic_id;

        $replyNonce = AP_Nonce::create('ap_forum_reply_' . $topicId, $this->userId);
        $replyRedirect = AP_Forum_Front::handlePost([
            'ap_forum_action' => AP_Forum_Front::ACTION_REPLY,
            'topic_id' => $topicId,
            'reply_body' => 'Thanks for asking — see docs.',
            '_ap_nonce' => $replyNonce,
        ], $this->db);

        $this->assertIsString($replyRedirect);
        $this->assertStringContainsString('ap_forum_notice=reply_posted', (string) $replyRedirect);

        $posts = AP_Forum::getPosts($topicId, [], $this->db);
        $this->assertCount(2, $posts);
        $this->assertSame(0, $this->subscriptionCount());
        $this->assertFalse(AP_Forum_Notify::isSubscribed($this->userId, $topicId, $this->db));
    }

    public function testCreateTopicRejectsBadNonce(): void
    {
        $forumId = AP_Forum::insertForum(['forum_name' => 'Secure'], $this->db);
        $this->assertTrue(AP_Session::setAuthCookie($this->userId, false, $this->db));

        $redirect = AP_Forum_Front::handlePost([
            'ap_forum_action' => AP_Forum_Front::ACTION_NEW_TOPIC,
            'forum_id' => $forumId,
            'topic_title' => 'Nope',
            'topic_body' => 'Should fail',
            '_ap_nonce' => 'invalid-nonce-value-here',
        ], $this->db);

        $this->assertNull($redirect);
        $notice = AP_Forum_Front::getNotice();
        $this->assertNotNull($notice);
        $this->assertSame('error', $notice['type'] ?? null);
        $this->assertCount(0, AP_Forum::getTopics($forumId, [], $this->db));
    }

    public function testForumUrlRespectsPrettyPermalinks(): void
    {
        $forumId = AP_Forum::insertForum(['forum_name' => 'Links'], $this->db);
        $forum = AP_Forum::getForum($forumId, $this->db);
        $url = AP_Forum::forumUrl($forum);
        $this->assertStringContainsString('/forums/', $url);
        $this->assertStringContainsString($forum->forum_slug, $url);

        AP_Rewrite::setStructure('', $this->db);
        $plain = AP_Forum::forumUrl($forum);
        $this->assertStringContainsString('forum_id=' . $forumId, $plain);
    }

    public function testMissingTopicIsNotFound(): void
    {
        $vars = AP_Rewrite::parseRequest('topic/does-not-exist', [], $this->db);
        $query = AP_Rewrite::queryFromVars($vars, $this->db);
        AP_Forum_Front::applyToQuery($query, $this->db);
        $this->assertTrue($query->is_404);
        $this->assertNotEmpty($query->get('ap_forum_not_found', false));
    }

    public function testCreateTopicWithTypeViaFrontHandler(): void
    {
        $forumId = AP_Forum::insertForum(['forum_name' => 'Typed'], $this->db);
        $this->assertTrue(AP_Session::setAuthCookie($this->userId, false, $this->db));

        $nonce = AP_Nonce::create('ap_forum_new_topic_' . $forumId, $this->userId);
        $redirect = AP_Forum_Front::handlePost([
            'ap_forum_action' => AP_Forum_Front::ACTION_NEW_TOPIC,
            'forum_id' => $forumId,
            'topic_title' => 'Pinned notice',
            'topic_body' => 'Please read',
            'topic_type' => 'sticky',
            '_ap_nonce' => $nonce,
        ], $this->db);

        $this->assertIsString($redirect);
        $this->assertStringContainsString('ap_forum_notice=topic_created', (string) $redirect);
        $topics = AP_Forum::getTopics($forumId, [], $this->db);
        $this->assertCount(1, $topics);
        $this->assertSame('sticky', (string) $topics[0]->topic_type);

        // Change type via set-topic-type action.
        $topicId = (int) $topics[0]->topic_id;
        $typeNonce = AP_Nonce::create('ap_forum_set_topic_type_' . $topicId, $this->userId);
        $typeRedirect = AP_Forum_Front::handlePost([
            'ap_forum_action' => AP_Forum_Front::ACTION_SET_TOPIC_TYPE,
            'topic_id' => $topicId,
            'topic_type' => 'announcement',
            '_ap_nonce' => $typeNonce,
        ], $this->db);
        $this->assertIsString($typeRedirect);
        $this->assertStringContainsString('ap_forum_notice=topic_type_updated', (string) $typeRedirect);
        $topic = AP_Forum::getTopic($topicId, $this->db);
        $this->assertSame('announcement', (string) ($topic->topic_type ?? ''));
    }

    public function testCreateStickyDeniedForMemberViaFrontHandler(): void
    {
        $forumId = AP_Forum::insertForum(['forum_name' => 'Members only type'], $this->db);
        $member = AP_User::create([
            'user_login' => 'member_type',
            'user_email' => 'member_type@example.test',
            'user_pass' => 'Password123!',
            'display_name' => 'Member',
            'role' => 'subscriber',
        ], $this->db);
        $this->assertTrue($member['ok'] ?? false);
        $memberId = (int) $member['id'];

        $this->assertTrue(AP_Session::setAuthCookie($memberId, false, $this->db));
        $nonce = AP_Nonce::create('ap_forum_new_topic_' . $forumId, $memberId);
        $redirect = AP_Forum_Front::handlePost([
            'ap_forum_action' => AP_Forum_Front::ACTION_NEW_TOPIC,
            'forum_id' => $forumId,
            'topic_title' => 'Sneaky sticky',
            'topic_body' => 'Should fail',
            'topic_type' => 'sticky',
            '_ap_nonce' => $nonce,
        ], $this->db);

        $this->assertNull($redirect);
        $notice = AP_Forum_Front::getNotice();
        $this->assertNotNull($notice);
        $this->assertSame('error', $notice['type'] ?? null);
        $this->assertCount(0, AP_Forum::getTopics($forumId, [], $this->db));

        // Standard type still works for members.
        $nonce2 = AP_Nonce::create('ap_forum_new_topic_' . $forumId, $memberId);
        $ok = AP_Forum_Front::handlePost([
            'ap_forum_action' => AP_Forum_Front::ACTION_NEW_TOPIC,
            'forum_id' => $forumId,
            'topic_title' => 'Normal topic',
            'topic_body' => 'Hello',
            'topic_type' => 'standard',
            '_ap_nonce' => $nonce2,
        ], $this->db);
        $this->assertIsString($ok);
        $this->assertCount(1, AP_Forum::getTopics($forumId, [], $this->db));
    }

    public function testTopicViewExposesTypeEditForStaff(): void
    {
        $forumId = AP_Forum::insertForum(['forum_name' => 'Staff type'], $this->db);
        $topicId = AP_Forum::createTopic([
            'forum_id' => $forumId,
            'topic_title' => 'Type me',
            'content' => 'Body',
            'poster_id' => $this->userId,
            'topic_type' => 'standard',
        ], $this->db);
        $topic = AP_Forum::getTopic($topicId, $this->db);
        $this->assertNotNull($topic);

        $this->assertTrue(AP_Session::setAuthCookie($this->userId, false, $this->db));
        $vars = AP_Rewrite::parseRequest('topic/' . $topic->topic_slug, [], $this->db);
        $query = AP_Rewrite::queryFromVars($vars, $this->db);
        AP_Forum_Front::applyToQuery($query, $this->db);
        ap_set_query($query);

        $this->assertTrue((bool) $query->get('can_set_topic_type', false));
        $this->assertSame('standard', (string) $query->get('topic_type', ''));
        $allowed = $query->get('allowed_topic_types', []);
        $this->assertIsArray($allowed);
        $this->assertContains('sticky', $allowed);

        ob_start();
        AP_Theme::render($query, $this->db);
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('ap_forum_set_topic_type', $html);
        $this->assertStringContainsString('Update type', $html);
        $this->assertStringNotContainsString('ap_forum_subscribe_topic', $html);
        $this->assertStringNotContainsString('ap_forum_move_topic', $html);
        $this->assertStringNotContainsString('ap_forum_merge_topic', $html);
        $this->assertTrue((bool) $query->get('can_move_topic', false));
        $this->assertSame([], $query->get('move_destinations', null));
        $this->assertTrue((bool) $query->get('can_merge_topic', false));
        $this->assertSame([], $query->get('merge_targets', null));
    }

    public function testTopicToolbarMoveDestinationSelect(): void
    {
        $categoryId = AP_Forum::insertForum([
            'forum_name' => 'Toolbar Cat',
            'forum_type' => AP_Forum::FORUM_TYPE_CATEGORY,
        ], $this->db);
        $sourceId = AP_Forum::insertForum(['forum_name' => 'Toolbar Source'], $this->db);
        $destId = AP_Forum::insertForum(['forum_name' => 'Toolbar Dest'], $this->db);
        $linkId = AP_Forum::insertForum([
            'forum_name' => 'Toolbar Link',
            'forum_type' => AP_Forum::FORUM_TYPE_LINK,
        ], $this->db);
        $this->assertGreaterThan(0, $categoryId);
        $this->assertGreaterThan(0, $linkId);

        $topicId = AP_Forum::createTopic([
            'forum_id' => $sourceId,
            'topic_title' => 'Move chrome',
            'content' => 'Body',
            'poster_id' => $this->userId,
        ], $this->db);
        $topic = AP_Forum::getTopic($topicId, $this->db);
        $this->assertNotNull($topic);

        $this->assertTrue(AP_Session::setAuthCookie($this->userId, false, $this->db));
        $vars = AP_Rewrite::parseRequest('topic/' . $topic->topic_slug, [], $this->db);
        $query = AP_Rewrite::queryFromVars($vars, $this->db);
        AP_Forum_Front::applyToQuery($query, $this->db);
        ap_set_query($query);

        $this->assertTrue((bool) $query->get('can_move_topic', false));
        $dests = $query->get('move_destinations', []);
        $this->assertIsArray($dests);
        $destIds = [];
        foreach ($dests as $row) {
            if (!is_array($row)) {
                continue;
            }
            $destIds[] = (int) ($row['forum_id'] ?? 0);
        }
        $this->assertContains($destId, $destIds);
        $this->assertNotContains($sourceId, $destIds);
        $this->assertNotContains($categoryId, $destIds);
        $this->assertNotContains($linkId, $destIds);

        ob_start();
        AP_Theme::render($query, $this->db);
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('ap_forum_move_topic', $html);
        $this->assertStringContainsString('name="dest_forum_id"', $html);
        $this->assertStringContainsString('>Toolbar Dest</option>', $html);
        $this->assertStringNotContainsString('>Toolbar Cat</option>', $html);
        $this->assertStringNotContainsString('>Toolbar Source</option>', $html);
        $this->assertStringNotContainsString('>Toolbar Link</option>', $html);
        $this->assertStringContainsString('>Move</button>', $html);
        $this->assertStringContainsString('id="agora-move-dest-forum"', $html);

        $member = AP_User::create([
            'user_login' => 'move_guest_member',
            'user_email' => 'move_guest_member@example.test',
            'user_pass' => 'Password123!',
            'display_name' => 'Member',
            'role' => 'subscriber',
        ], $this->db);
        $this->assertTrue($member['ok'] ?? false);
        $memberId = (int) $member['id'];
        $this->assertTrue(AP_Session::setAuthCookie($memberId, false, $this->db));

        $memberQuery = AP_Rewrite::queryFromVars($vars, $this->db);
        AP_Forum_Front::applyToQuery($memberQuery, $this->db);
        ap_set_query($memberQuery);
        $this->assertFalse((bool) $memberQuery->get('can_move_topic', false));
        $this->assertSame([], $memberQuery->get('move_destinations', null));

        ob_start();
        AP_Theme::render($memberQuery, $this->db);
        $memberHtml = (string) ob_get_clean();
        $this->assertStringNotContainsString('ap_forum_move_topic', $memberHtml);
        $this->assertStringNotContainsString('name="dest_forum_id"', $memberHtml);
        $this->assertStringNotContainsString('>Move</button>', $memberHtml);
    }

    public function testMoveTopicFormHtmlOmitsEmptyOrInvalidDests(): void
    {
        $this->assertSame('', ap_forum_move_topic_form_html(0, [
            ['forum_id' => 2, 'forum_name' => 'Dest'],
        ]));
        $this->assertSame('', ap_forum_move_topic_form_html(12, []));
        $this->assertSame('', ap_forum_move_topic_form_html(12, [
            ['forum_id' => 0, 'forum_name' => 'Nope'],
            ['forum_id' => 4, 'forum_name' => ''],
        ]));

        $html = ap_forum_move_topic_form_html(12, [
            ['forum_id' => 4, 'forum_name' => 'General'],
            (object) ['forum_id' => 5, 'forum_name' => 'Off-topic'],
        ]);
        $this->assertStringContainsString('ap_forum_move_topic', $html);
        $this->assertStringContainsString('name="dest_forum_id"', $html);
        $this->assertStringContainsString('value="4"', $html);
        $this->assertStringContainsString('General', $html);
        $this->assertStringContainsString('Off-topic', $html);
        $this->assertStringContainsString('>Move</button>', $html);
        $this->assertStringContainsString('Select forum', $html);
    }

    public function testMoveTopicViaFrontHandler(): void
    {
        $sourceId = AP_Forum::insertForum(['forum_name' => 'Move From'], $this->db);
        $destId = AP_Forum::insertForum(['forum_name' => 'Move To'], $this->db);
        $topicId = AP_Forum::createTopic([
            'forum_id' => $sourceId,
            'topic_title' => 'Relocate me',
            'content' => 'Opening post',
            'poster_id' => $this->userId,
        ], $this->db);
        AP_Forum::createReply([
            'topic_id' => $topicId,
            'content' => 'A reply',
            'poster_id' => $this->userId,
        ], $this->db);
        $before = AP_Forum::getTopic($topicId, $this->db);
        $this->assertNotNull($before);
        $slug = (string) ($before->topic_slug ?? '');
        $this->assertNotSame('', $slug);

        AP_Options::update('forum_topic_notify_enabled', '1', $this->db);
        $this->assertTrue(AP_Forum_Notify::subscribe($this->userId, $topicId, $this->db));

        $this->assertTrue(AP_Session::setAuthCookie($this->userId, false, $this->db));
        $nonce = AP_Nonce::create('ap_forum_move_topic_' . $topicId, $this->userId);
        $redirect = AP_Forum_Front::handlePost([
            'ap_forum_action' => AP_Forum_Front::ACTION_MOVE_TOPIC,
            'topic_id' => $topicId,
            'dest_forum_id' => $destId,
            '_ap_nonce' => $nonce,
        ], $this->db);

        $this->assertIsString($redirect);
        $this->assertStringContainsString('ap_forum_notice=topic_moved', (string) $redirect);

        $after = AP_Forum::getTopic($topicId, $this->db);
        $this->assertNotNull($after);
        $this->assertSame($topicId, (int) $after->topic_id);
        $this->assertSame($destId, (int) $after->forum_id);
        $this->assertSame($slug, (string) ($after->topic_slug ?? ''));
        $this->assertNotSame(AP_Forum::TOPIC_STATUS_MOVED, (string) ($after->topic_status ?? ''));

        $source = AP_Forum::getForum($sourceId, $this->db);
        $dest = AP_Forum::getForum($destId, $this->db);
        $this->assertSame(0, (int) ($source->topic_count ?? -1));
        $this->assertSame(0, (int) ($source->post_count ?? -1));
        $this->assertSame(0, (int) ($source->last_topic_id ?? -1));
        $this->assertSame(1, (int) ($dest->topic_count ?? 0));
        $this->assertSame(2, (int) ($dest->post_count ?? 0));
        $this->assertSame($topicId, (int) ($dest->last_topic_id ?? 0));

        $this->assertTrue(AP_Forum_Notify::isSubscribed($this->userId, $topicId, $this->db));

        $_GET['ap_forum_notice'] = 'topic_moved';
        $notice = AP_Forum_Front::getNotice();
        unset($_GET['ap_forum_notice']);
        $this->assertNotNull($notice);
        $this->assertSame('success', $notice['type'] ?? null);
        $this->assertSame('Topic moved.', $notice['message'] ?? null);

        $vars = AP_Rewrite::parseRequest('topic/' . $slug, [], $this->db);
        $query = AP_Rewrite::queryFromVars($vars, $this->db);
        AP_Forum_Front::applyToQuery($query, $this->db);
        ap_set_query($query);
        $_GET['ap_forum_notice'] = 'topic_moved';
        ob_start();
        AP_Theme::render($query, $this->db);
        $html = (string) ob_get_clean();
        unset($_GET['ap_forum_notice']);
        $this->assertSame($destId, (int) $query->get('forum_id', 0));
        $this->assertStringContainsString('ap-forum-notice--success', $html);
        $this->assertStringContainsString('Topic moved.', $html);
    }

    public function testMoveTopicViaFrontHandlerRefusesCategoryMissingCapAndMember(): void
    {
        $categoryId = AP_Forum::insertForum([
            'forum_name' => 'Move Cat Dest',
            'forum_type' => AP_Forum::FORUM_TYPE_CATEGORY,
        ], $this->db);
        $sourceId = AP_Forum::insertForum(['forum_name' => 'Move Cap Source'], $this->db);
        $destId = AP_Forum::insertForum(['forum_name' => 'Move Cap Dest'], $this->db);
        $otherId = AP_Forum::insertForum(['forum_name' => 'Move Cap Other'], $this->db);
        $topicId = AP_Forum::createTopic([
            'forum_id' => $sourceId,
            'topic_title' => 'Stay put unless allowed',
            'content' => 'Body',
            'poster_id' => $this->userId,
        ], $this->db);
        $slug = (string) (AP_Forum::getTopic($topicId, $this->db)->topic_slug ?? '');

        $this->assertTrue(AP_Session::setAuthCookie($this->userId, false, $this->db));
        $catRedirect = AP_Forum_Front::handlePost([
            'ap_forum_action' => AP_Forum_Front::ACTION_MOVE_TOPIC,
            'topic_id' => $topicId,
            'dest_forum_id' => $categoryId,
            '_ap_nonce' => AP_Nonce::create('ap_forum_move_topic_' . $topicId, $this->userId),
        ], $this->db);
        $this->assertNull($catRedirect);
        $catNotice = AP_Forum_Front::getNotice();
        $this->assertSame('error', $catNotice['type'] ?? null);
        $this->assertSame($sourceId, (int) (AP_Forum::getTopic($topicId, $this->db)->forum_id ?? 0));
        $this->assertSame($slug, (string) (AP_Forum::getTopic($topicId, $this->db)->topic_slug ?? ''));

        $local = AP_User::create([
            'user_login' => 'move_local_mod',
            'user_email' => 'move_local_mod@example.test',
            'user_pass' => 'Password123!',
            'display_name' => 'Local mod',
            'role' => 'subscriber',
        ], $this->db);
        $this->assertTrue($local['ok'] ?? false);
        $localId = (int) $local['id'];
        $groupId = AP_Group::create(['group_name' => 'Source-only mods'], $this->db);
        $this->assertGreaterThan(0, $groupId);
        $this->assertGreaterThan(0, AP_Group::addMember($groupId, $localId, AP_Group::ROLE_MEMBER, $this->db));
        $this->assertTrue(AP_Forum_Permissions::setPermission(
            $sourceId,
            $groupId,
            AP_Forum_Permissions::PERM_MODERATE,
            true,
            $this->db
        ));
        $this->assertTrue(AP_Forum_Permissions::setPermission(
            $otherId,
            $groupId,
            AP_Forum_Permissions::PERM_MODERATE,
            true,
            $this->db
        ));

        AP_Forum_Front::setNotice(null);
        $this->assertTrue(AP_Session::setAuthCookie($localId, false, $this->db));
        $missingCap = AP_Forum_Front::handlePost([
            'ap_forum_action' => AP_Forum_Front::ACTION_MOVE_TOPIC,
            'topic_id' => $topicId,
            'dest_forum_id' => $destId,
            '_ap_nonce' => AP_Nonce::create('ap_forum_move_topic_' . $topicId, $localId),
        ], $this->db);
        $this->assertNull($missingCap);
        $missingNotice = AP_Forum_Front::getNotice();
        $this->assertSame('error', $missingNotice['type'] ?? null);
        $this->assertSame($sourceId, (int) (AP_Forum::getTopic($topicId, $this->db)->forum_id ?? 0));

        $member = AP_User::create([
            'user_login' => 'move_plain_member',
            'user_email' => 'move_plain_member@example.test',
            'user_pass' => 'Password123!',
            'display_name' => 'Member',
            'role' => 'subscriber',
        ], $this->db);
        $this->assertTrue($member['ok'] ?? false);
        $memberId = (int) $member['id'];
        AP_Forum_Front::setNotice(null);
        $this->assertTrue(AP_Session::setAuthCookie($memberId, false, $this->db));
        $memberDenied = AP_Forum_Front::handlePost([
            'ap_forum_action' => AP_Forum_Front::ACTION_MOVE_TOPIC,
            'topic_id' => $topicId,
            'dest_forum_id' => $destId,
            '_ap_nonce' => AP_Nonce::create('ap_forum_move_topic_' . $topicId, $memberId),
        ], $this->db);
        $this->assertNull($memberDenied);
        $memberNotice = AP_Forum_Front::getNotice();
        $this->assertSame('error', $memberNotice['type'] ?? null);
        $this->assertSame($sourceId, (int) (AP_Forum::getTopic($topicId, $this->db)->forum_id ?? 0));
        $this->assertSame($slug, (string) (AP_Forum::getTopic($topicId, $this->db)->topic_slug ?? ''));
    }

    public function testTopicToolbarMergeTargetSelect(): void
    {
        $sourceForumId = AP_Forum::insertForum(['forum_name' => 'Merge Toolbar Source'], $this->db);
        $otherForumId = AP_Forum::insertForum(['forum_name' => 'Merge Toolbar Other'], $this->db);
        $this->assertGreaterThan(0, $sourceForumId);
        $this->assertGreaterThan(0, $otherForumId);

        $sourceId = AP_Forum::createTopic([
            'forum_id' => $sourceForumId,
            'topic_title' => 'Merge me away',
            'content' => 'Source body',
            'poster_id' => $this->userId,
        ], $this->db);
        $sameForumTargetId = AP_Forum::createTopic([
            'forum_id' => $sourceForumId,
            'topic_title' => 'Keep in source',
            'content' => 'Same forum target',
            'poster_id' => $this->userId,
        ], $this->db);
        $otherTargetId = AP_Forum::createTopic([
            'forum_id' => $otherForumId,
            'topic_title' => 'Keep in other',
            'content' => 'Other forum target',
            'poster_id' => $this->userId,
        ], $this->db);
        $deletedId = AP_Forum::createTopic([
            'forum_id' => $sourceForumId,
            'topic_title' => 'Deleted merge target',
            'content' => 'Gone',
            'poster_id' => $this->userId,
        ], $this->db);
        $this->assertTrue(AP_Forum_Moderation::softDeleteTopic($deletedId, 0, $this->db));

        $source = AP_Forum::getTopic($sourceId, $this->db);
        $this->assertNotNull($source);

        $this->assertTrue(AP_Session::setAuthCookie($this->userId, false, $this->db));
        $vars = AP_Rewrite::parseRequest('topic/' . $source->topic_slug, [], $this->db);
        $query = AP_Rewrite::queryFromVars($vars, $this->db);
        AP_Forum_Front::applyToQuery($query, $this->db);
        ap_set_query($query);

        $this->assertTrue((bool) $query->get('can_merge_topic', false));
        $targets = $query->get('merge_targets', []);
        $this->assertIsArray($targets);
        $targetIds = [];
        foreach ($targets as $row) {
            if (!is_array($row)) {
                continue;
            }
            $targetIds[] = (int) ($row['topic_id'] ?? 0);
        }
        $this->assertContains($sameForumTargetId, $targetIds);
        $this->assertContains($otherTargetId, $targetIds);
        $this->assertNotContains($sourceId, $targetIds);
        $this->assertNotContains($deletedId, $targetIds);

        ob_start();
        AP_Theme::render($query, $this->db);
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('ap_forum_merge_topic', $html);
        $this->assertStringContainsString('name="target_topic_id"', $html);
        $this->assertStringContainsString('>Keep in source — Merge Toolbar Source</option>', $html);
        $this->assertStringContainsString('>Keep in other — Merge Toolbar Other</option>', $html);
        $this->assertStringNotContainsString('<option value="' . $sourceId . '">', $html);
        $this->assertStringNotContainsString('<option value="' . $deletedId . '">', $html);
        $this->assertStringContainsString('>Merge</button>', $html);
        $this->assertStringContainsString('id="agora-merge-target-topic"', $html);

        $local = AP_User::create([
            'user_login' => 'merge_local_mod',
            'user_email' => 'merge_local_mod@example.test',
            'user_pass' => 'Password123!',
            'display_name' => 'Local merge mod',
            'role' => 'subscriber',
        ], $this->db);
        $this->assertTrue($local['ok'] ?? false);
        $localId = (int) $local['id'];
        $groupId = AP_Group::create(['group_name' => 'Source-only merge mods'], $this->db);
        $this->assertGreaterThan(0, $groupId);
        $this->assertGreaterThan(0, AP_Group::addMember($groupId, $localId, AP_Group::ROLE_MEMBER, $this->db));
        $this->assertTrue(AP_Forum_Permissions::setPermission(
            $sourceForumId,
            $groupId,
            AP_Forum_Permissions::PERM_MODERATE,
            true,
            $this->db
        ));

        $this->assertTrue(AP_Session::setAuthCookie($localId, false, $this->db));
        $localQuery = AP_Rewrite::queryFromVars($vars, $this->db);
        AP_Forum_Front::applyToQuery($localQuery, $this->db);
        ap_set_query($localQuery);
        $this->assertTrue((bool) $localQuery->get('can_merge_topic', false));
        $localIds = [];
        foreach ($localQuery->get('merge_targets', []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $localIds[] = (int) ($row['topic_id'] ?? 0);
        }
        $this->assertContains($sameForumTargetId, $localIds);
        $this->assertNotContains($otherTargetId, $localIds);
        $this->assertNotContains($sourceId, $localIds);

        $member = AP_User::create([
            'user_login' => 'merge_guest_member',
            'user_email' => 'merge_guest_member@example.test',
            'user_pass' => 'Password123!',
            'display_name' => 'Member',
            'role' => 'subscriber',
        ], $this->db);
        $this->assertTrue($member['ok'] ?? false);
        $memberId = (int) $member['id'];
        $this->assertTrue(AP_Session::setAuthCookie($memberId, false, $this->db));

        $memberQuery = AP_Rewrite::queryFromVars($vars, $this->db);
        AP_Forum_Front::applyToQuery($memberQuery, $this->db);
        ap_set_query($memberQuery);
        $this->assertFalse((bool) $memberQuery->get('can_merge_topic', false));
        $this->assertSame([], $memberQuery->get('merge_targets', null));

        ob_start();
        AP_Theme::render($memberQuery, $this->db);
        $memberHtml = (string) ob_get_clean();
        $this->assertStringNotContainsString('ap_forum_merge_topic', $memberHtml);
        $this->assertStringNotContainsString('name="target_topic_id"', $memberHtml);
        $this->assertStringNotContainsString('>Merge</button>', $memberHtml);
    }

    public function testMergeTopicFormHtmlOmitsEmptyOrInvalidTargets(): void
    {
        $this->assertSame('', ap_forum_merge_topic_form_html(0, [
            ['topic_id' => 2, 'topic_title' => 'Keep'],
        ]));
        $this->assertSame('', ap_forum_merge_topic_form_html(12, []));
        $this->assertSame('', ap_forum_merge_topic_form_html(12, [
            ['topic_id' => 0, 'topic_title' => 'Nope'],
            ['topic_id' => 12, 'topic_title' => 'Self'],
        ]));

        $html = ap_forum_merge_topic_form_html(12, [
            ['topic_id' => 4, 'topic_title' => 'Keep', 'forum_name' => 'General'],
            (object) ['topic_id' => 5, 'topic_title' => 'Other', 'forum_name' => ''],
            ['topic_id' => 12, 'topic_title' => 'Self'],
            ['topic_id' => 6, 'topic_title' => ''],
        ]);
        $this->assertStringContainsString('ap_forum_merge_topic', $html);
        $this->assertStringContainsString('name="target_topic_id"', $html);
        $this->assertStringContainsString('value="4"', $html);
        $this->assertStringContainsString('Keep — General', $html);
        $this->assertStringContainsString('Other', $html);
        $this->assertStringContainsString('(no title)', $html);
        $this->assertStringNotContainsString('Self', $html);
        $this->assertStringContainsString('>Merge</button>', $html);
        $this->assertStringContainsString('Select topic', $html);
    }

    public function testMergeTopicViaFrontHandler(): void
    {
        $forumId = AP_Forum::insertForum(['forum_name' => 'Merge From Front'], $this->db);
        $targetId = AP_Forum::createTopic([
            'forum_id' => $forumId,
            'topic_title' => 'Survive merge',
            'content' => 'Target OP',
            'poster_id' => $this->userId,
        ], $this->db);
        $sourceId = AP_Forum::createTopic([
            'forum_id' => $forumId,
            'topic_title' => 'Absorbed',
            'content' => 'Source OP',
            'poster_id' => $this->userId,
        ], $this->db);
        $sourceReply = AP_Forum::createReply([
            'topic_id' => $sourceId,
            'content' => 'Source reply',
            'poster_id' => $this->userId,
        ], $this->db);
        $this->assertGreaterThan(0, $sourceReply);

        $target = AP_Forum::getTopic($targetId, $this->db);
        $this->assertNotNull($target);
        $targetSlug = (string) ($target->topic_slug ?? '');
        $this->assertNotSame('', $targetSlug);

        AP_Options::update('forum_topic_notify_enabled', '1', $this->db);
        $dup = AP_User::create([
            'user_login' => 'merge_dup_sub',
            'user_email' => 'merge_dup_sub@example.test',
            'user_pass' => 'Password123!',
            'display_name' => 'Dup',
            'role' => 'subscriber',
        ], $this->db);
        $onlySource = AP_User::create([
            'user_login' => 'merge_src_sub',
            'user_email' => 'merge_src_sub@example.test',
            'user_pass' => 'Password123!',
            'display_name' => 'Source only',
            'role' => 'subscriber',
        ], $this->db);
        $onlyTarget = AP_User::create([
            'user_login' => 'merge_tgt_sub',
            'user_email' => 'merge_tgt_sub@example.test',
            'user_pass' => 'Password123!',
            'display_name' => 'Target only',
            'role' => 'subscriber',
        ], $this->db);
        $this->assertTrue($dup['ok'] ?? false);
        $this->assertTrue($onlySource['ok'] ?? false);
        $this->assertTrue($onlyTarget['ok'] ?? false);
        $dupId = (int) $dup['id'];
        $onlySourceId = (int) $onlySource['id'];
        $onlyTargetId = (int) $onlyTarget['id'];
        $this->assertTrue(AP_Forum_Notify::subscribe($this->userId, $sourceId, $this->db));
        $this->assertTrue(AP_Forum_Notify::subscribe($dupId, $sourceId, $this->db));
        $this->assertTrue(AP_Forum_Notify::subscribe($dupId, $targetId, $this->db));
        $this->assertTrue(AP_Forum_Notify::subscribe($onlySourceId, $sourceId, $this->db));
        $this->assertTrue(AP_Forum_Notify::subscribe($onlyTargetId, $targetId, $this->db));

        $this->assertTrue(AP_Session::setAuthCookie($this->userId, false, $this->db));
        $redirect = AP_Forum_Front::handlePost([
            'ap_forum_action' => AP_Forum_Front::ACTION_MERGE_TOPIC,
            'topic_id' => $sourceId,
            'target_topic_id' => $targetId,
            '_ap_nonce' => AP_Nonce::create('ap_forum_merge_topic_' . $sourceId, $this->userId),
        ], $this->db);

        $this->assertIsString($redirect);
        $this->assertStringContainsString('ap_forum_notice=topics_merged', (string) $redirect);
        $this->assertStringContainsString($targetSlug, (string) $redirect);

        $this->assertNull(AP_Forum::getTopic($sourceId, $this->db));
        $posts = AP_Forum::getPosts($targetId, ['approved_only' => false], $this->db);
        $this->assertCount(3, $posts);
        $ids = array_map(static fn ($p) => (int) $p->post_id, $posts);
        $this->assertContains($sourceReply, $ids);

        $forum = AP_Forum::getForum($forumId, $this->db);
        $this->assertNotNull($forum);
        $this->assertSame(1, (int) ($forum->topic_count ?? -1));
        $this->assertSame(3, (int) ($forum->post_count ?? -1));
        $this->assertSame($targetId, (int) ($forum->last_topic_id ?? 0));
        $this->assertSame($sourceReply, (int) ($forum->last_post_id ?? 0));
        $kept = AP_Forum::getTopic($targetId, $this->db);
        $this->assertNotNull($kept);
        $this->assertNotSame(AP_Forum::TOPIC_STATUS_MOVED, (string) ($kept->topic_status ?? ''));

        $this->assertFalse(AP_Forum_Notify::isSubscribed($this->userId, $sourceId, $this->db));
        $this->assertTrue(AP_Forum_Notify::isSubscribed($this->userId, $targetId, $this->db));
        $this->assertTrue(AP_Forum_Notify::isSubscribed($dupId, $targetId, $this->db));
        $this->assertFalse(AP_Forum_Notify::isSubscribed($dupId, $sourceId, $this->db));
        $this->assertTrue(AP_Forum_Notify::isSubscribed($onlySourceId, $targetId, $this->db));
        $this->assertTrue(AP_Forum_Notify::isSubscribed($onlyTargetId, $targetId, $this->db));
        $subCount = (int) $this->db->getVar(
            'SELECT COUNT(*) FROM '
            . $this->db->quoteIdentifier($this->db->table('topic_subscriptions'))
            . ' WHERE ' . $this->db->quoteIdentifier('topic_id') . ' = ?',
            [$targetId]
        );
        $this->assertSame(4, $subCount);

        $_GET['ap_forum_notice'] = 'topics_merged';
        $notice = AP_Forum_Front::getNotice();
        unset($_GET['ap_forum_notice']);
        $this->assertNotNull($notice);
        $this->assertSame('success', $notice['type'] ?? null);
        $this->assertSame('Topics merged.', $notice['message'] ?? null);

        $vars = AP_Rewrite::parseRequest('topic/' . $targetSlug, [], $this->db);
        $query = AP_Rewrite::queryFromVars($vars, $this->db);
        AP_Forum_Front::applyToQuery($query, $this->db);
        ap_set_query($query);
        $_GET['ap_forum_notice'] = 'topics_merged';
        ob_start();
        AP_Theme::render($query, $this->db);
        $html = (string) ob_get_clean();
        unset($_GET['ap_forum_notice']);
        $this->assertSame($targetId, (int) $query->get('topic_id', 0));
        $this->assertStringContainsString('ap-forum-notice--success', $html);
        $this->assertStringContainsString('Topics merged.', $html);
    }

    public function testMergeTopicViaFrontHandlerRefusesUnmoderateableTargetAndMember(): void
    {
        $sourceForumId = AP_Forum::insertForum(['forum_name' => 'Merge Cap Source'], $this->db);
        $otherForumId = AP_Forum::insertForum(['forum_name' => 'Merge Cap Other'], $this->db);
        $sourceId = AP_Forum::createTopic([
            'forum_id' => $sourceForumId,
            'topic_title' => 'Stay unless allowed',
            'content' => 'Source body',
            'poster_id' => $this->userId,
        ], $this->db);
        $sameTargetId = AP_Forum::createTopic([
            'forum_id' => $sourceForumId,
            'topic_title' => 'Same-forum keep',
            'content' => 'Same body',
            'poster_id' => $this->userId,
        ], $this->db);
        $otherTargetId = AP_Forum::createTopic([
            'forum_id' => $otherForumId,
            'topic_title' => 'Off-limits keep',
            'content' => 'Other body',
            'poster_id' => $this->userId,
        ], $this->db);

        $this->assertTrue(AP_Session::setAuthCookie($this->userId, false, $this->db));
        $selfRedirect = AP_Forum_Front::handlePost([
            'ap_forum_action' => AP_Forum_Front::ACTION_MERGE_TOPIC,
            'topic_id' => $sourceId,
            'target_topic_id' => $sourceId,
            '_ap_nonce' => AP_Nonce::create('ap_forum_merge_topic_' . $sourceId, $this->userId),
        ], $this->db);
        $this->assertNull($selfRedirect);
        $selfNotice = AP_Forum_Front::getNotice();
        $this->assertSame('error', $selfNotice['type'] ?? null);
        $this->assertNotNull(AP_Forum::getTopic($sourceId, $this->db));

        AP_Forum_Front::setNotice(null);
        $missing = AP_Forum_Front::handlePost([
            'ap_forum_action' => AP_Forum_Front::ACTION_MERGE_TOPIC,
            'topic_id' => $sourceId,
            'target_topic_id' => 0,
            '_ap_nonce' => AP_Nonce::create('ap_forum_merge_topic_' . $sourceId, $this->userId),
        ], $this->db);
        $this->assertNull($missing);
        $this->assertNotNull(AP_Forum::getTopic($sourceId, $this->db));

        $local = AP_User::create([
            'user_login' => 'merge_cap_local',
            'user_email' => 'merge_cap_local@example.test',
            'user_pass' => 'Password123!',
            'display_name' => 'Local mod',
            'role' => 'subscriber',
        ], $this->db);
        $this->assertTrue($local['ok'] ?? false);
        $localId = (int) $local['id'];
        $groupId = AP_Group::create(['group_name' => 'Merge source-only mods'], $this->db);
        $this->assertGreaterThan(0, $groupId);
        $this->assertGreaterThan(0, AP_Group::addMember($groupId, $localId, AP_Group::ROLE_MEMBER, $this->db));
        $this->assertTrue(AP_Forum_Permissions::setPermission(
            $sourceForumId,
            $groupId,
            AP_Forum_Permissions::PERM_MODERATE,
            true,
            $this->db
        ));

        AP_Forum_Front::setNotice(null);
        $this->assertTrue(AP_Session::setAuthCookie($localId, false, $this->db));
        $missingCap = AP_Forum_Front::handlePost([
            'ap_forum_action' => AP_Forum_Front::ACTION_MERGE_TOPIC,
            'topic_id' => $sourceId,
            'target_topic_id' => $otherTargetId,
            '_ap_nonce' => AP_Nonce::create('ap_forum_merge_topic_' . $sourceId, $localId),
        ], $this->db);
        $this->assertNull($missingCap);
        $missingNotice = AP_Forum_Front::getNotice();
        $this->assertSame('error', $missingNotice['type'] ?? null);
        $this->assertNotNull(AP_Forum::getTopic($sourceId, $this->db));
        $this->assertNotNull(AP_Forum::getTopic($otherTargetId, $this->db));

        $member = AP_User::create([
            'user_login' => 'merge_plain_member',
            'user_email' => 'merge_plain_member@example.test',
            'user_pass' => 'Password123!',
            'display_name' => 'Member',
            'role' => 'subscriber',
        ], $this->db);
        $this->assertTrue($member['ok'] ?? false);
        $memberId = (int) $member['id'];
        AP_Forum_Front::setNotice(null);
        $this->assertTrue(AP_Session::setAuthCookie($memberId, false, $this->db));
        $memberDenied = AP_Forum_Front::handlePost([
            'ap_forum_action' => AP_Forum_Front::ACTION_MERGE_TOPIC,
            'topic_id' => $sourceId,
            'target_topic_id' => $sameTargetId,
            '_ap_nonce' => AP_Nonce::create('ap_forum_merge_topic_' . $sourceId, $memberId),
        ], $this->db);
        $this->assertNull($memberDenied);
        $memberNotice = AP_Forum_Front::getNotice();
        $this->assertSame('error', $memberNotice['type'] ?? null);
        $this->assertNotNull(AP_Forum::getTopic($sourceId, $this->db));

        AP_Forum_Front::setNotice(null);
        $this->assertTrue(AP_Session::setAuthCookie($localId, false, $this->db));
        $ok = AP_Forum_Front::handlePost([
            'ap_forum_action' => AP_Forum_Front::ACTION_MERGE_TOPIC,
            'topic_id' => $sourceId,
            'target_topic_id' => $sameTargetId,
            '_ap_nonce' => AP_Nonce::create('ap_forum_merge_topic_' . $sourceId, $localId),
        ], $this->db);
        $this->assertIsString($ok);
        $this->assertStringContainsString('ap_forum_notice=topics_merged', (string) $ok);
        $this->assertNull(AP_Forum::getTopic($sourceId, $this->db));
        $this->assertNotNull(AP_Forum::getTopic($sameTargetId, $this->db));
        $this->assertNotNull(AP_Forum::getTopic($otherTargetId, $this->db));
    }

    public function testTopicSubscribeChromeWhenSiteOnLoggedInCanView(): void
    {
        $forumId = AP_Forum::insertForum(['forum_name' => 'Notify Chrome'], $this->db);
        $topicId = AP_Forum::createTopic([
            'forum_id' => $forumId,
            'topic_title' => 'Watch me',
            'content' => 'First post.',
            'poster_id' => $this->userId,
        ], $this->db);
        $topic = AP_Forum::getTopic($topicId, $this->db);
        $this->assertNotNull($topic);

        AP_Options::update('forum_topic_notify_enabled', '1', $this->db);
        $this->assertTrue(AP_Session::setAuthCookie($this->userId, false, $this->db));

        $vars = AP_Rewrite::parseRequest('topic/' . $topic->topic_slug, [], $this->db);
        $query = AP_Rewrite::queryFromVars($vars, $this->db);
        AP_Forum_Front::applyToQuery($query, $this->db);
        ap_set_query($query);

        $this->assertTrue((bool) $query->get('forum_topic_notify_enabled', false));
        $this->assertTrue((bool) $query->get('can_subscribe', false));
        $this->assertFalse((bool) $query->get('topic_subscribed', false));
        $this->assertSame(0, $this->subscriptionCount());

        ob_start();
        AP_Theme::render($query, $this->db);
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('ap_forum_subscribe_topic', $html);
        $this->assertStringContainsString('ap-forum-subscribe', $html);
        $this->assertStringContainsString('name="_ap_nonce"', $html);
        $this->assertStringContainsString('>Subscribe</button>', $html);
        $this->assertStringNotContainsString('ap_forum_unsubscribe_topic', $html);
        $this->assertStringNotContainsString('>Unsubscribe</button>', $html);
        $this->assertStringContainsString('Notify me of replies', $html);
        $this->assertStringContainsString('name="notify_replies"', $html);
        $this->assertStringContainsString('id="agora-notify-replies-reply"', $html);
        $this->assertDoesNotMatchRegularExpression(
            '/name="notify_replies"[^>]*\bchecked\b/',
            $html
        );

        $this->assertSame(1, preg_match(
            '/ap_forum_subscribe_topic.*?name="_ap_nonce" value="([^"]+)"/s',
            $html,
            $nonceMatch
        ));
        $posted = AP_Forum_Front::handlePost([
            'ap_forum_action' => AP_Forum_Front::ACTION_SUBSCRIBE_TOPIC,
            'topic_id' => $topicId,
            '_ap_nonce' => $nonceMatch[1],
        ], $this->db);
        $this->assertIsString($posted);
        $this->assertStringContainsString('ap_forum_notice=topic_subscribed_email_on', (string) $posted);
        $this->assertTrue(AP_Forum_Notify::isSubscribed($this->userId, $topicId, $this->db));
        $this->assertTrue(AP_Forum_Notify::isUserNotifyEnabled($this->userId, $this->db));
        AP_Forum_Front::setNotice(null);

        $query2 = AP_Rewrite::queryFromVars($vars, $this->db);
        AP_Forum_Front::applyToQuery($query2, $this->db);
        ap_set_query($query2);
        $this->assertTrue((bool) $query2->get('can_subscribe', false));
        $this->assertTrue((bool) $query2->get('topic_subscribed', false));

        ob_start();
        AP_Theme::render($query2, $this->db);
        $html2 = (string) ob_get_clean();
        $this->assertStringContainsString('ap_forum_unsubscribe_topic', $html2);
        $this->assertStringContainsString('>Unsubscribe</button>', $html2);
        $this->assertStringNotContainsString('ap_forum_subscribe_topic', $html2);
        $this->assertStringNotContainsString('name="notify_replies"', $html2);
        $this->assertStringNotContainsString('Notify me of replies', $html2);
    }

    public function testTopicSubscribeHiddenForGuestAndWhenSiteOff(): void
    {
        $forumId = AP_Forum::insertForum(['forum_name' => 'No Chrome'], $this->db);
        $topicId = AP_Forum::createTopic([
            'forum_id' => $forumId,
            'topic_title' => 'Quiet topic',
            'content' => 'Body.',
            'poster_id' => $this->userId,
        ], $this->db);
        $topic = AP_Forum::getTopic($topicId, $this->db);
        $this->assertNotNull($topic);
        $vars = AP_Rewrite::parseRequest('topic/' . $topic->topic_slug, [], $this->db);

        AP_Options::update('forum_topic_notify_enabled', '1', $this->db);
        AP_Session::resetCurrentUser();
        $guestQuery = AP_Rewrite::queryFromVars($vars, $this->db);
        AP_Forum_Front::applyToQuery($guestQuery, $this->db);
        ap_set_query($guestQuery);
        $this->assertTrue((bool) $guestQuery->get('forum_topic_notify_enabled', false));
        $this->assertFalse((bool) $guestQuery->get('can_subscribe', false));
        $this->assertFalse((bool) $guestQuery->get('topic_subscribed', false));

        ob_start();
        AP_Theme::render($guestQuery, $this->db);
        $guestHtml = (string) ob_get_clean();
        $this->assertStringNotContainsString('ap_forum_subscribe_topic', $guestHtml);
        $this->assertStringNotContainsString('ap-forum-subscribe', $guestHtml);
        $this->assertStringNotContainsString('>Subscribe</button>', $guestHtml);
        $this->assertStringNotContainsString('Notify me of replies', $guestHtml);
        $this->assertStringNotContainsString('name="notify_replies"', $guestHtml);

        AP_Options::update('forum_topic_notify_enabled', '0', $this->db);
        $this->assertTrue(AP_Session::setAuthCookie($this->userId, false, $this->db));
        $offQuery = AP_Rewrite::queryFromVars($vars, $this->db);
        AP_Forum_Front::applyToQuery($offQuery, $this->db);
        ap_set_query($offQuery);
        $this->assertFalse((bool) $offQuery->get('can_subscribe', false));
        $this->assertFalse((bool) $offQuery->get('forum_topic_notify_enabled', false));

        ob_start();
        AP_Theme::render($offQuery, $this->db);
        $offHtml = (string) ob_get_clean();
        $this->assertStringNotContainsString('ap_forum_subscribe_topic', $offHtml);
        $this->assertStringNotContainsString('>Subscribe</button>', $offHtml);
        $this->assertStringNotContainsString('Notify me of replies', $offHtml);
        $this->assertStringNotContainsString('name="notify_replies"', $offHtml);
    }

    public function testSubscribeUnsubscribeViaFrontHandler(): void
    {
        $forumId = AP_Forum::insertForum(['forum_name' => 'Watch POST'], $this->db);
        $topicId = AP_Forum::createTopic([
            'forum_id' => $forumId,
            'topic_title' => 'POST watch',
            'content' => 'Body.',
            'poster_id' => $this->userId,
        ], $this->db);
        $this->assertGreaterThan(0, $topicId);

        AP_Options::update('forum_topic_notify_enabled', '1', $this->db);
        $this->assertTrue(AP_Session::setAuthCookie($this->userId, false, $this->db));

        $bad = AP_Forum_Front::handlePost([
            'ap_forum_action' => AP_Forum_Front::ACTION_SUBSCRIBE_TOPIC,
            'topic_id' => $topicId,
            '_ap_nonce' => 'invalid-nonce-value-here',
        ], $this->db);
        $this->assertNull($bad);
        $this->assertSame(0, $this->subscriptionCount());
        AP_Forum_Front::setNotice(null);

        $nonce = AP_Nonce::create('ap_forum_subscribe_topic_' . $topicId, $this->userId);
        $redirect = AP_Forum_Front::handlePost([
            'ap_forum_action' => AP_Forum_Front::ACTION_SUBSCRIBE_TOPIC,
            'topic_id' => $topicId,
            '_ap_nonce' => $nonce,
        ], $this->db);
        $this->assertIsString($redirect);
        $this->assertStringContainsString('ap_forum_notice=topic_subscribed_email_on', (string) $redirect);
        $this->assertTrue(AP_Forum_Notify::isSubscribed($this->userId, $topicId, $this->db));
        $this->assertSame(1, $this->subscriptionCount());
        $this->assertTrue(AP_Forum_Notify::isUserNotifyEnabled($this->userId, $this->db));
        $this->assertSame(0, (int) $this->db->getVar(
            'SELECT COUNT(*) FROM '
            . $this->db->quoteIdentifier($this->db->table('topic_track'))
        ));

        $_GET['ap_forum_notice'] = 'topic_subscribed_email_on';
        $notice = AP_Forum_Front::getNotice();
        unset($_GET['ap_forum_notice']);
        $this->assertNotNull($notice);
        $this->assertSame('success', $notice['type'] ?? null);
        $this->assertSame(
            'Subscribed to this topic. Email notifications for topics you subscribe to are now on.',
            $notice['message'] ?? null
        );

        $unNonce = AP_Nonce::create('ap_forum_unsubscribe_topic_' . $topicId, $this->userId);
        $unRedirect = AP_Forum_Front::handlePost([
            'ap_forum_action' => AP_Forum_Front::ACTION_UNSUBSCRIBE_TOPIC,
            'topic_id' => $topicId,
            '_ap_nonce' => $unNonce,
        ], $this->db);
        $this->assertIsString($unRedirect);
        $this->assertStringContainsString('ap_forum_notice=topic_unsubscribed', (string) $unRedirect);
        $this->assertFalse(AP_Forum_Notify::isSubscribed($this->userId, $topicId, $this->db));
        $this->assertSame(0, $this->subscriptionCount());
        $this->assertTrue(AP_Forum_Notify::isUserNotifyEnabled($this->userId, $this->db));
    }

    public function testFirstSubscribeWithUserMasterOffFlipsMasterAndNotices(): void
    {
        $forumId = AP_Forum::insertForum(['forum_name' => 'First subscribe flip'], $this->db);
        $topicId = AP_Forum::createTopic([
            'forum_id' => $forumId,
            'topic_title' => 'Flip master',
            'content' => 'Body.',
            'poster_id' => $this->userId,
        ], $this->db);
        $topic = AP_Forum::getTopic($topicId, $this->db);
        $this->assertNotNull($topic);

        $member = AP_User::create([
            'user_login' => 'first_sub_flip',
            'user_email' => 'first_sub_flip@example.test',
            'user_pass' => 'Password123!',
            'display_name' => 'First Sub',
            'role' => 'subscriber',
        ], $this->db);
        $this->assertTrue($member['ok'] ?? false);
        $memberId = (int) $member['id'];
        $this->assertGreaterThan(0, $memberId);
        AP_Forum_Notify::setUserNotifyEnabled($memberId, '0', $this->db);
        $this->assertFalse(AP_Forum_Notify::isUserNotifyEnabled($memberId, $this->db));

        AP_Options::update('forum_topic_notify_enabled', '1', $this->db);
        $this->assertTrue(AP_Session::setAuthCookie($memberId, false, $this->db));

        $redirect = AP_Forum_Front::handlePost([
            'ap_forum_action' => AP_Forum_Front::ACTION_SUBSCRIBE_TOPIC,
            'topic_id' => $topicId,
            '_ap_nonce' => AP_Nonce::create('ap_forum_subscribe_topic_' . $topicId, $memberId),
        ], $this->db);
        $this->assertIsString($redirect);
        $this->assertMatchesRegularExpression(
            '/ap_forum_notice=topic_subscribed_email_on(?:&|$)/',
            (string) $redirect
        );
        $this->assertTrue(AP_Forum_Notify::isSubscribed($memberId, $topicId, $this->db));
        $this->assertTrue(AP_Forum_Notify::isUserNotifyEnabled($memberId, $this->db));
        $this->assertSame(
            '1',
            AP_User::getMeta($memberId, AP_Forum_Notify::META_NOTIFY_EMAIL, $this->db)
        );
        $this->assertStringNotContainsString('profile.php', (string) $redirect);
        $this->assertStringNotContainsString('ap-admin/profile', (string) $redirect);

        $_GET['ap_forum_notice'] = 'topic_subscribed_email_on';
        $vars = AP_Rewrite::parseRequest('topic/' . $topic->topic_slug, [], $this->db);
        $query = AP_Rewrite::queryFromVars($vars, $this->db);
        AP_Forum_Front::applyToQuery($query, $this->db);
        ap_set_query($query);
        ob_start();
        AP_Theme::render($query, $this->db);
        $html = (string) ob_get_clean();
        unset($_GET['ap_forum_notice']);
        $this->assertStringContainsString('ap-forum-notice--success', $html);
        $this->assertStringContainsString(
            'Email notifications for topics you subscribe to are now on.',
            $html
        );
        $this->assertStringContainsString('>Unsubscribe</button>', $html);
    }

    public function testSubscribeWhenUserMasterAlreadyOnKeepsSimpleNotice(): void
    {
        $forumId = AP_Forum::insertForum(['forum_name' => 'Master already on'], $this->db);
        $topicId = AP_Forum::createTopic([
            'forum_id' => $forumId,
            'topic_title' => 'Already opted in',
            'content' => 'Body.',
            'poster_id' => $this->userId,
        ], $this->db);

        AP_Options::update('forum_topic_notify_enabled', '1', $this->db);
        $this->assertTrue(AP_Forum_Notify::setUserNotifyEnabled($this->userId, '1', $this->db));
        $this->assertTrue(AP_Session::setAuthCookie($this->userId, false, $this->db));

        $redirect = AP_Forum_Front::handlePost([
            'ap_forum_action' => AP_Forum_Front::ACTION_SUBSCRIBE_TOPIC,
            'topic_id' => $topicId,
            '_ap_nonce' => AP_Nonce::create('ap_forum_subscribe_topic_' . $topicId, $this->userId),
        ], $this->db);
        $this->assertIsString($redirect);
        $this->assertMatchesRegularExpression(
            '/ap_forum_notice=topic_subscribed(?:&|$)/',
            (string) $redirect
        );
        $this->assertStringNotContainsString('topic_subscribed_email_on', (string) $redirect);
        $this->assertTrue(AP_Forum_Notify::isSubscribed($this->userId, $topicId, $this->db));
        $this->assertTrue(AP_Forum_Notify::isUserNotifyEnabled($this->userId, $this->db));

        $_GET['ap_forum_notice'] = 'topic_subscribed';
        $notice = AP_Forum_Front::getNotice();
        unset($_GET['ap_forum_notice']);
        $this->assertNotNull($notice);
        $this->assertSame('Subscribed to this topic.', $notice['message'] ?? null);
    }

    public function testSubscribeAfterUserTurnsMasterOffFlipsAgain(): void
    {
        $forumId = AP_Forum::insertForum(['forum_name' => 'Master off again'], $this->db);
        $topicId = AP_Forum::createTopic([
            'forum_id' => $forumId,
            'topic_title' => 'Watch again',
            'content' => 'Body.',
            'poster_id' => $this->userId,
        ], $this->db);
        $otherId = AP_Forum::createTopic([
            'forum_id' => $forumId,
            'topic_title' => 'Second watch',
            'content' => 'Body two.',
            'poster_id' => $this->userId,
        ], $this->db);

        AP_Options::update('forum_topic_notify_enabled', '1', $this->db);
        $this->assertTrue(AP_Session::setAuthCookie($this->userId, false, $this->db));

        $first = AP_Forum_Front::handlePost([
            'ap_forum_action' => AP_Forum_Front::ACTION_SUBSCRIBE_TOPIC,
            'topic_id' => $topicId,
            '_ap_nonce' => AP_Nonce::create('ap_forum_subscribe_topic_' . $topicId, $this->userId),
        ], $this->db);
        $this->assertIsString($first);
        $this->assertStringContainsString('topic_subscribed_email_on', (string) $first);
        $this->assertTrue(AP_Forum_Notify::isUserNotifyEnabled($this->userId, $this->db));

        $this->assertTrue(AP_Forum_Notify::unsubscribe($this->userId, $topicId, $this->db));
        $this->assertTrue(AP_Forum_Notify::setUserNotifyEnabled($this->userId, '0', $this->db));
        $this->assertFalse(AP_Forum_Notify::isUserNotifyEnabled($this->userId, $this->db));

        $again = AP_Forum_Front::handlePost([
            'ap_forum_action' => AP_Forum_Front::ACTION_SUBSCRIBE_TOPIC,
            'topic_id' => $otherId,
            '_ap_nonce' => AP_Nonce::create('ap_forum_subscribe_topic_' . $otherId, $this->userId),
        ], $this->db);
        $this->assertIsString($again);
        $this->assertMatchesRegularExpression(
            '/ap_forum_notice=topic_subscribed_email_on(?:&|$)/',
            (string) $again
        );
        $this->assertStringNotContainsString('profile.php', (string) $again);
        $this->assertTrue(AP_Forum_Notify::isSubscribed($this->userId, $otherId, $this->db));
        $this->assertTrue(AP_Forum_Notify::isUserNotifyEnabled($this->userId, $this->db));
    }

    public function testSubscribeRejectedWhenSiteOffGuestOrNoView(): void
    {
        $forumId = AP_Forum::insertForum(['forum_name' => 'Public watch'], $this->db);
        $topicId = AP_Forum::createTopic([
            'forum_id' => $forumId,
            'topic_title' => 'Public topic',
            'content' => 'Body.',
            'poster_id' => $this->userId,
        ], $this->db);

        $staffForum = AP_Forum::insertForum(['forum_name' => 'Staff only watch'], $this->db);
        $this->assertTrue(AP_Forum_Permissions::applyAccessLevel(
            $staffForum,
            AP_Forum_Permissions::ACCESS_MODERATORS,
            $this->db
        ));
        $staffTopic = AP_Forum::createTopic([
            'forum_id' => $staffForum,
            'topic_title' => 'Staff topic',
            'content' => 'Private body.',
            'poster_id' => $this->userId,
        ], $this->db);

        $member = AP_User::create([
            'user_login' => 'sub_member',
            'user_email' => 'sub_member@example.test',
            'user_pass' => 'Password123!',
            'display_name' => 'Sub Member',
            'role' => 'subscriber',
        ], $this->db);
        $this->assertTrue($member['ok'] ?? false);
        $memberId = (int) $member['id'];
        $this->assertGreaterThan(0, $memberId);

        AP_Options::update('forum_topic_notify_enabled', '0', $this->db);
        $this->assertTrue(AP_Session::setAuthCookie($this->userId, false, $this->db));
        $off = AP_Forum_Front::handlePost([
            'ap_forum_action' => AP_Forum_Front::ACTION_SUBSCRIBE_TOPIC,
            'topic_id' => $topicId,
            '_ap_nonce' => AP_Nonce::create('ap_forum_subscribe_topic_' . $topicId, $this->userId),
        ], $this->db);
        $this->assertNull($off);
        $this->assertSame(0, $this->subscriptionCount());

        AP_Options::update('forum_topic_notify_enabled', '1', $this->db);
        AP_Session::resetCurrentUser();
        $guest = AP_Forum_Front::handlePost([
            'ap_forum_action' => AP_Forum_Front::ACTION_SUBSCRIBE_TOPIC,
            'topic_id' => $topicId,
            '_ap_nonce' => AP_Nonce::create('ap_forum_subscribe_topic_' . $topicId, 0),
        ], $this->db);
        $this->assertNull($guest);
        $this->assertSame(0, $this->subscriptionCount());

        $this->assertTrue(AP_Session::setAuthCookie($memberId, false, $this->db));
        $denied = AP_Forum_Front::handlePost([
            'ap_forum_action' => AP_Forum_Front::ACTION_SUBSCRIBE_TOPIC,
            'topic_id' => $staffTopic,
            '_ap_nonce' => AP_Nonce::create('ap_forum_subscribe_topic_' . $staffTopic, $memberId),
        ], $this->db);
        $this->assertNull($denied);
        $this->assertFalse(AP_Forum_Notify::isSubscribed($memberId, $staffTopic, $this->db));
        $this->assertSame(0, $this->subscriptionCount());

        $ok = AP_Forum_Front::handlePost([
            'ap_forum_action' => AP_Forum_Front::ACTION_SUBSCRIBE_TOPIC,
            'topic_id' => $topicId,
            '_ap_nonce' => AP_Nonce::create('ap_forum_subscribe_topic_' . $topicId, $memberId),
        ], $this->db);
        $this->assertIsString($ok);
        $this->assertTrue(AP_Forum_Notify::isSubscribed($memberId, $topicId, $this->db));
    }

    public function testTopicViewDoesNotAutoSubscribe(): void
    {
        $forumId = AP_Forum::insertForum(['forum_name' => 'No auto watch'], $this->db);
        $topicId = AP_Forum::createTopic([
            'forum_id' => $forumId,
            'topic_title' => 'Visit only',
            'content' => 'Body.',
            'poster_id' => $this->userId,
        ], $this->db);
        $topic = AP_Forum::getTopic($topicId, $this->db);
        $this->assertNotNull($topic);

        AP_Options::update('forum_topic_notify_enabled', '1', $this->db);
        $this->assertTrue(AP_Session::setAuthCookie($this->userId, false, $this->db));

        $vars = AP_Rewrite::parseRequest('topic/' . $topic->topic_slug, [], $this->db);
        $query = AP_Rewrite::queryFromVars($vars, $this->db);
        AP_Forum_Front::applyToQuery($query, $this->db);
        $this->assertTrue((bool) $query->get('can_subscribe', false));
        $this->assertFalse(AP_Forum_Notify::isSubscribed($this->userId, $topicId, $this->db));
        $this->assertSame(0, $this->subscriptionCount());
    }

    public function testSubscribeChromeWhenUserMasterOffForMember(): void
    {
        $forumId = AP_Forum::insertForum(['forum_name' => 'Member chrome'], $this->db);
        $topicId = AP_Forum::createTopic([
            'forum_id' => $forumId,
            'topic_title' => 'Member watch',
            'content' => 'Body.',
            'poster_id' => $this->userId,
        ], $this->db);
        $topic = AP_Forum::getTopic($topicId, $this->db);
        $this->assertNotNull($topic);

        $member = AP_User::create([
            'user_login' => 'sub_chrome',
            'user_email' => 'sub_chrome@example.test',
            'user_pass' => 'Password123!',
            'display_name' => 'Sub Chrome',
            'role' => 'subscriber',
        ], $this->db);
        $this->assertTrue($member['ok'] ?? false);
        $memberId = (int) $member['id'];
        $this->assertGreaterThan(0, $memberId);

        AP_Options::update('forum_topic_notify_enabled', '1', $this->db);
        AP_Forum_Notify::setUserNotifyEnabled($memberId, '0', $this->db);
        $this->assertFalse(AP_Forum_Notify::isUserNotifyEnabled($memberId, $this->db));
        $this->assertTrue(AP_Session::setAuthCookie($memberId, false, $this->db));

        $vars = AP_Rewrite::parseRequest('topic/' . $topic->topic_slug, [], $this->db);
        $query = AP_Rewrite::queryFromVars($vars, $this->db);
        AP_Forum_Front::applyToQuery($query, $this->db);
        ap_set_query($query);

        $this->assertTrue((bool) $query->get('can_subscribe', false));
        $this->assertFalse((bool) $query->get('topic_subscribed', false));

        ob_start();
        AP_Theme::render($query, $this->db);
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('ap_forum_subscribe_topic', $html);
        $this->assertStringContainsString('>Subscribe</button>', $html);
        $this->assertSame(0, $this->subscriptionCount());
    }

    public function testSubscribeChromeHiddenWhenCannotViewForum(): void
    {
        $staffForum = AP_Forum::insertForum(['forum_name' => 'Staff chrome'], $this->db);
        $this->assertTrue(AP_Forum_Permissions::applyAccessLevel(
            $staffForum,
            AP_Forum_Permissions::ACCESS_MODERATORS,
            $this->db
        ));
        $staffTopic = AP_Forum::createTopic([
            'forum_id' => $staffForum,
            'topic_title' => 'Staff chrome topic',
            'content' => 'Private.',
            'poster_id' => $this->userId,
        ], $this->db);
        $topic = AP_Forum::getTopic($staffTopic, $this->db);
        $this->assertNotNull($topic);

        $member = AP_User::create([
            'user_login' => 'no_view_sub',
            'user_email' => 'no_view_sub@example.test',
            'user_pass' => 'Password123!',
            'display_name' => 'No View',
            'role' => 'subscriber',
        ], $this->db);
        $this->assertTrue($member['ok'] ?? false);
        $memberId = (int) $member['id'];
        $this->assertGreaterThan(0, $memberId);

        AP_Options::update('forum_topic_notify_enabled', '1', $this->db);
        $this->assertTrue(AP_Session::setAuthCookie($memberId, false, $this->db));

        $vars = AP_Rewrite::parseRequest('topic/' . $topic->topic_slug, [], $this->db);
        $query = AP_Rewrite::queryFromVars($vars, $this->db);
        AP_Forum_Front::applyToQuery($query, $this->db);
        ap_set_query($query);

        $this->assertTrue(!empty($query->get('ap_forum_cannot_view', false)));
        $this->assertFalse((bool) $query->get('can_subscribe', false));

        ob_start();
        AP_Theme::render($query, $this->db);
        $html = (string) ob_get_clean();
        $this->assertStringNotContainsString('ap_forum_subscribe_topic', $html);
        $this->assertStringNotContainsString('>Subscribe</button>', $html);
        $this->assertSame(0, $this->subscriptionCount());
    }

    public function testSubscribeChromeOnLockedTopic(): void
    {
        $forumId = AP_Forum::insertForum(['forum_name' => 'Locked watch'], $this->db);
        $topicId = AP_Forum::createTopic([
            'forum_id' => $forumId,
            'topic_title' => 'Locked but watchable',
            'content' => 'Body.',
            'poster_id' => $this->userId,
        ], $this->db);
        $this->assertGreaterThan(0, $topicId);
        $this->assertTrue(AP_Forum_Moderation::lockTopic($topicId, $this->userId, $this->db));
        $topic = AP_Forum::getTopic($topicId, $this->db);
        $this->assertNotNull($topic);

        AP_Options::update('forum_topic_notify_enabled', '1', $this->db);
        $this->assertTrue(AP_Session::setAuthCookie($this->userId, false, $this->db));

        $vars = AP_Rewrite::parseRequest('topic/' . $topic->topic_slug, [], $this->db);
        $query = AP_Rewrite::queryFromVars($vars, $this->db);
        AP_Forum_Front::applyToQuery($query, $this->db);
        ap_set_query($query);

        $this->assertTrue((bool) $query->get('topic_locked', false));
        $this->assertFalse((bool) $query->get('can_reply', false));
        $this->assertTrue((bool) $query->get('can_subscribe', false));

        ob_start();
        AP_Theme::render($query, $this->db);
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('ap_forum_subscribe_topic', $html);
        $this->assertStringContainsString('>Subscribe</button>', $html);

        $nonce = AP_Nonce::create('ap_forum_subscribe_topic_' . $topicId, $this->userId);
        $redirect = AP_Forum_Front::handlePost([
            'ap_forum_action' => AP_Forum_Front::ACTION_SUBSCRIBE_TOPIC,
            'topic_id' => $topicId,
            '_ap_nonce' => $nonce,
        ], $this->db);
        $this->assertIsString($redirect);
        $this->assertTrue(AP_Forum_Notify::isSubscribed($this->userId, $topicId, $this->db));
    }

    public function testUnsubscribeViaFrontHandlerIsIdempotent(): void
    {
        $forumId = AP_Forum::insertForum(['forum_name' => 'Idempotent unsub'], $this->db);
        $topicId = AP_Forum::createTopic([
            'forum_id' => $forumId,
            'topic_title' => 'Watch twice',
            'content' => 'Body.',
            'poster_id' => $this->userId,
        ], $this->db);
        $this->assertGreaterThan(0, $topicId);

        AP_Options::update('forum_topic_notify_enabled', '1', $this->db);
        $this->assertTrue(AP_Session::setAuthCookie($this->userId, false, $this->db));
        $this->assertTrue(AP_Forum_Notify::subscribe($this->userId, $topicId, $this->db));

        $unNonce = AP_Nonce::create('ap_forum_unsubscribe_topic_' . $topicId, $this->userId);
        $first = AP_Forum_Front::handlePost([
            'ap_forum_action' => AP_Forum_Front::ACTION_UNSUBSCRIBE_TOPIC,
            'topic_id' => $topicId,
            '_ap_nonce' => $unNonce,
        ], $this->db);
        $this->assertIsString($first);
        $this->assertStringContainsString('ap_forum_notice=topic_unsubscribed', (string) $first);
        $this->assertFalse(AP_Forum_Notify::isSubscribed($this->userId, $topicId, $this->db));

        $unNonce2 = AP_Nonce::create('ap_forum_unsubscribe_topic_' . $topicId, $this->userId);
        $second = AP_Forum_Front::handlePost([
            'ap_forum_action' => AP_Forum_Front::ACTION_UNSUBSCRIBE_TOPIC,
            'topic_id' => $topicId,
            '_ap_nonce' => $unNonce2,
        ], $this->db);
        $this->assertIsString($second);
        $this->assertStringContainsString('ap_forum_notice=topic_unsubscribed', (string) $second);
        $this->assertFalse(AP_Forum_Notify::isSubscribed($this->userId, $topicId, $this->db));
        $this->assertSame(0, $this->subscriptionCount());
    }

    public function testSubscribeWhenMembersReadonlyCanViewButNotReply(): void
    {
        $forumId = AP_Forum::insertForum(['forum_name' => 'Read only watch'], $this->db);
        $this->assertTrue(AP_Forum_Permissions::applyAccessLevel(
            $forumId,
            AP_Forum_Permissions::ACCESS_MEMBERS_READONLY,
            $this->db
        ));
        $topicId = AP_Forum::createTopic([
            'forum_id' => $forumId,
            'topic_title' => 'Readonly watch topic',
            'content' => 'Body.',
            'poster_id' => $this->userId,
        ], $this->db);
        $topic = AP_Forum::getTopic($topicId, $this->db);
        $this->assertNotNull($topic);

        $member = AP_User::create([
            'user_login' => 'ro_sub',
            'user_email' => 'ro_sub@example.test',
            'user_pass' => 'Password123!',
            'display_name' => 'RO Sub',
            'role' => 'subscriber',
        ], $this->db);
        $this->assertTrue($member['ok'] ?? false);
        $memberId = (int) $member['id'];
        $this->assertGreaterThan(0, $memberId);

        AP_Options::update('forum_topic_notify_enabled', '1', $this->db);
        $this->assertTrue(AP_Session::setAuthCookie($memberId, false, $this->db));

        $vars = AP_Rewrite::parseRequest('topic/' . $topic->topic_slug, [], $this->db);
        $query = AP_Rewrite::queryFromVars($vars, $this->db);
        AP_Forum_Front::applyToQuery($query, $this->db);
        ap_set_query($query);

        $this->assertFalse((bool) $query->get('can_reply', false));
        $this->assertTrue((bool) $query->get('can_subscribe', false));
        $this->assertFalse((bool) $query->get('topic_subscribed', false));

        ob_start();
        AP_Theme::render($query, $this->db);
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('ap_forum_subscribe_topic', $html);
        $this->assertStringContainsString('>Subscribe</button>', $html);

        $nonce = AP_Nonce::create('ap_forum_subscribe_topic_' . $topicId, $memberId);
        $redirect = AP_Forum_Front::handlePost([
            'ap_forum_action' => AP_Forum_Front::ACTION_SUBSCRIBE_TOPIC,
            'topic_id' => $topicId,
            '_ap_nonce' => $nonce,
        ], $this->db);
        $this->assertIsString($redirect);
        $this->assertTrue(AP_Forum_Notify::isSubscribed($memberId, $topicId, $this->db));
    }

    public function testSubscribeHonorsGroupOnlyViewForum(): void
    {
        $groupId = AP_Group::create(['group_name' => 'Watch VIP'], $this->db);
        $this->assertGreaterThan(0, $groupId);
        $forumId = AP_Forum::insertForum(['forum_name' => 'VIP watch board'], $this->db);
        $this->assertTrue(AP_Forum_Permissions::applyGroupOnlyAccess($forumId, [$groupId], $this->db));
        $topicId = AP_Forum::createTopic([
            'forum_id' => $forumId,
            'topic_title' => 'VIP only topic',
            'content' => 'Private body.',
            'poster_id' => $this->userId,
        ], $this->db);
        $topic = AP_Forum::getTopic($topicId, $this->db);
        $this->assertNotNull($topic);

        $insider = AP_User::create([
            'user_login' => 'vip_sub',
            'user_email' => 'vip_sub@example.test',
            'user_pass' => 'Password123!',
            'display_name' => 'VIP Sub',
            'role' => 'subscriber',
        ], $this->db);
        $outsider = AP_User::create([
            'user_login' => 'vip_out',
            'user_email' => 'vip_out@example.test',
            'user_pass' => 'Password123!',
            'display_name' => 'VIP Out',
            'role' => 'subscriber',
        ], $this->db);
        $this->assertTrue($insider['ok'] ?? false);
        $this->assertTrue($outsider['ok'] ?? false);
        $insiderId = (int) $insider['id'];
        $outsiderId = (int) $outsider['id'];
        $this->assertGreaterThan(0, AP_Group::addMember($groupId, $insiderId, AP_Group::ROLE_MEMBER, $this->db));

        AP_Options::update('forum_topic_notify_enabled', '1', $this->db);
        $vars = AP_Rewrite::parseRequest('topic/' . $topic->topic_slug, [], $this->db);

        $this->assertTrue(AP_Session::setAuthCookie($insiderId, false, $this->db));
        $inQuery = AP_Rewrite::queryFromVars($vars, $this->db);
        AP_Forum_Front::applyToQuery($inQuery, $this->db);
        ap_set_query($inQuery);
        $this->assertTrue((bool) $inQuery->get('can_subscribe', false));
        $this->assertTrue(empty($inQuery->get('ap_forum_cannot_view', false)));

        ob_start();
        AP_Theme::render($inQuery, $this->db);
        $inHtml = (string) ob_get_clean();
        $this->assertStringContainsString('ap_forum_subscribe_topic', $inHtml);
        $this->assertStringContainsString('>Subscribe</button>', $inHtml);

        $ok = AP_Forum_Front::handlePost([
            'ap_forum_action' => AP_Forum_Front::ACTION_SUBSCRIBE_TOPIC,
            'topic_id' => $topicId,
            '_ap_nonce' => AP_Nonce::create('ap_forum_subscribe_topic_' . $topicId, $insiderId),
        ], $this->db);
        $this->assertIsString($ok);
        $this->assertTrue(AP_Forum_Notify::isSubscribed($insiderId, $topicId, $this->db));

        $this->assertTrue(AP_Session::setAuthCookie($outsiderId, false, $this->db));
        $outQuery = AP_Rewrite::queryFromVars($vars, $this->db);
        AP_Forum_Front::applyToQuery($outQuery, $this->db);
        ap_set_query($outQuery);
        $this->assertTrue(!empty($outQuery->get('ap_forum_cannot_view', false)));
        $this->assertFalse((bool) $outQuery->get('can_subscribe', false));

        ob_start();
        AP_Theme::render($outQuery, $this->db);
        $outHtml = (string) ob_get_clean();
        $this->assertStringNotContainsString('ap_forum_subscribe_topic', $outHtml);
        $this->assertStringNotContainsString('>Subscribe</button>', $outHtml);

        $denied = AP_Forum_Front::handlePost([
            'ap_forum_action' => AP_Forum_Front::ACTION_SUBSCRIBE_TOPIC,
            'topic_id' => $topicId,
            '_ap_nonce' => AP_Nonce::create('ap_forum_subscribe_topic_' . $topicId, $outsiderId),
        ], $this->db);
        $this->assertNull($denied);
        $this->assertFalse(AP_Forum_Notify::isSubscribed($outsiderId, $topicId, $this->db));
    }

    public function testSubscribeNoticeRendersOnTopicView(): void
    {
        $forumId = AP_Forum::insertForum(['forum_name' => 'Notice watch'], $this->db);
        $topicId = AP_Forum::createTopic([
            'forum_id' => $forumId,
            'topic_title' => 'Notice topic',
            'content' => 'Body.',
            'poster_id' => $this->userId,
        ], $this->db);
        $topic = AP_Forum::getTopic($topicId, $this->db);
        $this->assertNotNull($topic);

        AP_Options::update('forum_topic_notify_enabled', '1', $this->db);
        $this->assertTrue(AP_Session::setAuthCookie($this->userId, false, $this->db));
        $this->assertTrue(AP_Forum_Notify::subscribe($this->userId, $topicId, $this->db));

        $vars = AP_Rewrite::parseRequest('topic/' . $topic->topic_slug, [], $this->db);
        $query = AP_Rewrite::queryFromVars($vars, $this->db);
        AP_Forum_Front::applyToQuery($query, $this->db);
        ap_set_query($query);

        $_GET['ap_forum_notice'] = 'topic_subscribed';
        ob_start();
        AP_Theme::render($query, $this->db);
        $html = (string) ob_get_clean();
        unset($_GET['ap_forum_notice']);

        $this->assertStringContainsString('ap-forum-notice--success', $html);
        $this->assertStringContainsString('Subscribed to this topic.', $html);
        $this->assertStringContainsString('ap_forum_unsubscribe_topic', $html);
        $this->assertStringContainsString('>Unsubscribe</button>', $html);
    }

    public function testSubscribeChromeNotOnForumIndexOrForumView(): void
    {
        $forumId = AP_Forum::insertForum(['forum_name' => 'Board only'], $this->db);
        $this->assertGreaterThan(0, $forumId);
        AP_Options::update('forum_topic_notify_enabled', '1', $this->db);
        $this->assertTrue(AP_Session::setAuthCookie($this->userId, false, $this->db));

        $index = AP_Forum_Front::enrichQueryArgs([
            'ap_forum_view' => 'index',
        ], $this->db);
        $this->assertTrue((bool) $index['forum_topic_notify_enabled']);
        $this->assertFalse((bool) ($index['can_subscribe'] ?? true));

        $forum = AP_Forum_Front::enrichQueryArgs([
            'ap_forum_view' => 'forum',
            'forum_id' => $forumId,
        ], $this->db);
        $this->assertTrue((bool) $forum['forum_topic_notify_enabled']);
        $this->assertFalse((bool) ($forum['can_subscribe'] ?? true));
        $this->assertFalse((bool) ($forum['topic_subscribed'] ?? true));
    }

    public function testNewTopicFormShowsNotifyCheckboxWhenSiteOn(): void
    {
        $forumId = AP_Forum::insertForum(['forum_name' => 'Compose notify'], $this->db);
        $forum = AP_Forum::getForum($forumId, $this->db);
        $this->assertNotNull($forum);

        AP_Options::update('forum_topic_notify_enabled', '1', $this->db);
        $this->assertTrue(AP_Session::setAuthCookie($this->userId, false, $this->db));

        $vars = AP_Rewrite::parseRequest('forums/' . $forum->forum_slug, [], $this->db);
        $query = AP_Rewrite::queryFromVars($vars, $this->db);
        AP_Forum_Front::applyToQuery($query, $this->db);
        ap_set_query($query);

        ob_start();
        AP_Theme::render($query, $this->db);
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('ap_forum_new_topic', $html);
        $this->assertStringContainsString('Notify me of replies', $html);
        $this->assertStringContainsString('name="notify_replies"', $html);
        $this->assertStringContainsString('id="agora-notify-replies-topic"', $html);
        $this->assertDoesNotMatchRegularExpression(
            '/name="notify_replies"[^>]*\bchecked\b/',
            $html
        );
        $this->assertStringNotContainsString('ap_forum_subscribe_topic', $html);
    }

    public function testCreateTopicAndReplyDoNotAutoWatchWhenSiteOn(): void
    {
        $forumId = AP_Forum::insertForum(['forum_name' => 'No auto watch'], $this->db);
        AP_Options::update('forum_topic_notify_enabled', '1', $this->db);
        $this->assertTrue(AP_Session::setAuthCookie($this->userId, false, $this->db));

        $nonce = AP_Nonce::create('ap_forum_new_topic_' . $forumId, $this->userId);
        $redirect = AP_Forum_Front::handlePost([
            'ap_forum_action' => AP_Forum_Front::ACTION_NEW_TOPIC,
            'forum_id' => $forumId,
            'topic_title' => 'Started without watch',
            'topic_body' => 'Should not subscribe.',
            '_ap_nonce' => $nonce,
        ], $this->db);
        $this->assertIsString($redirect);
        $topics = AP_Forum::getTopics($forumId, [], $this->db);
        $this->assertCount(1, $topics);
        $topicId = (int) $topics[0]->topic_id;
        $this->assertFalse(AP_Forum_Notify::isSubscribed($this->userId, $topicId, $this->db));
        $this->assertSame(0, $this->subscriptionCount());
        $this->assertFalse(AP_Forum_Notify::isUserNotifyEnabled($this->userId, $this->db));

        $replyNonce = AP_Nonce::create('ap_forum_reply_' . $topicId, $this->userId);
        $replyRedirect = AP_Forum_Front::handlePost([
            'ap_forum_action' => AP_Forum_Front::ACTION_REPLY,
            'topic_id' => $topicId,
            'reply_body' => 'Still not watching.',
            '_ap_nonce' => $replyNonce,
        ], $this->db);
        $this->assertIsString($replyRedirect);
        $this->assertFalse(AP_Forum_Notify::isSubscribed($this->userId, $topicId, $this->db));
        $this->assertSame(0, $this->subscriptionCount());
        $this->assertFalse(AP_Forum_Notify::isUserNotifyEnabled($this->userId, $this->db));
    }

    public function testCreateTopicWithNotifyCheckboxSubscribes(): void
    {
        $forumId = AP_Forum::insertForum(['forum_name' => 'Watch on start'], $this->db);
        AP_Options::update('forum_topic_notify_enabled', '1', $this->db);
        $this->assertTrue(AP_Session::setAuthCookie($this->userId, false, $this->db));

        $nonce = AP_Nonce::create('ap_forum_new_topic_' . $forumId, $this->userId);
        $redirect = AP_Forum_Front::handlePost([
            'ap_forum_action' => AP_Forum_Front::ACTION_NEW_TOPIC,
            'forum_id' => $forumId,
            'topic_title' => 'Please notify me',
            'topic_body' => 'I ticked the box.',
            'notify_replies' => '1',
            '_ap_nonce' => $nonce,
        ], $this->db);
        $this->assertIsString($redirect);
        $topics = AP_Forum::getTopics($forumId, [], $this->db);
        $this->assertCount(1, $topics);
        $topicId = (int) $topics[0]->topic_id;
        $this->assertTrue(AP_Forum_Notify::isSubscribed($this->userId, $topicId, $this->db));
        $this->assertSame(1, $this->subscriptionCount());
        $this->assertTrue(AP_Forum_Notify::isUserNotifyEnabled($this->userId, $this->db));
        $this->assertMatchesRegularExpression(
            '/ap_forum_notice=topic_created_email_on(?:&|#|$)/',
            (string) $redirect
        );
        $this->assertStringNotContainsString('profile.php', (string) $redirect);
        $this->assertSame(0, (int) $this->db->getVar(
            'SELECT COUNT(*) FROM '
            . $this->db->quoteIdentifier($this->db->table('topic_track'))
        ));

        $_GET['ap_forum_notice'] = 'topic_created_email_on';
        $notice = AP_Forum_Front::getNotice();
        unset($_GET['ap_forum_notice']);
        $this->assertNotNull($notice);
        $this->assertSame(
            'Topic created. Email notifications for topics you subscribe to are now on.',
            $notice['message'] ?? null
        );
    }

    public function testReplyWithNotifyCheckboxSubscribes(): void
    {
        $forumId = AP_Forum::insertForum(['forum_name' => 'Watch on reply'], $this->db);
        $topicId = AP_Forum::createTopic([
            'forum_id' => $forumId,
            'topic_title' => 'Existing thread',
            'content' => 'OP body.',
            'poster_id' => $this->userId,
        ], $this->db);
        $this->assertGreaterThan(0, $topicId);

        AP_Options::update('forum_topic_notify_enabled', '1', $this->db);
        $this->assertTrue(AP_Session::setAuthCookie($this->userId, false, $this->db));
        $this->assertFalse(AP_Forum_Notify::isSubscribed($this->userId, $topicId, $this->db));

        $replyNonce = AP_Nonce::create('ap_forum_reply_' . $topicId, $this->userId);
        $replyRedirect = AP_Forum_Front::handlePost([
            'ap_forum_action' => AP_Forum_Front::ACTION_REPLY,
            'topic_id' => $topicId,
            'reply_body' => 'Watch from now reply.',
            'notify_replies' => '1',
            '_ap_nonce' => $replyNonce,
        ], $this->db);
        $this->assertIsString($replyRedirect);
        $this->assertTrue(AP_Forum_Notify::isSubscribed($this->userId, $topicId, $this->db));
        $this->assertSame(1, $this->subscriptionCount());
        $this->assertTrue(AP_Forum_Notify::isUserNotifyEnabled($this->userId, $this->db));
        $this->assertMatchesRegularExpression(
            '/ap_forum_notice=reply_posted_email_on(?:&|#|$)/',
            (string) $replyRedirect
        );

        $_GET['ap_forum_notice'] = 'reply_posted_email_on';
        $notice = AP_Forum_Front::getNotice();
        unset($_GET['ap_forum_notice']);
        $this->assertNotNull($notice);
        $this->assertSame(
            'Reply posted. Email notifications for topics you subscribe to are now on.',
            $notice['message'] ?? null
        );
    }

    public function testComposeNotifyWhenUserMasterAlreadyOnKeepsCreateNotice(): void
    {
        $forumId = AP_Forum::insertForum(['forum_name' => 'Master already on compose'], $this->db);
        AP_Options::update('forum_topic_notify_enabled', '1', $this->db);
        $this->assertTrue(AP_Forum_Notify::setUserNotifyEnabled($this->userId, '1', $this->db));
        $this->assertTrue(AP_Session::setAuthCookie($this->userId, false, $this->db));

        $nonce = AP_Nonce::create('ap_forum_new_topic_' . $forumId, $this->userId);
        $redirect = AP_Forum_Front::handlePost([
            'ap_forum_action' => AP_Forum_Front::ACTION_NEW_TOPIC,
            'forum_id' => $forumId,
            'topic_title' => 'Already opted in compose',
            'topic_body' => 'Checkbox on, master already on.',
            'notify_replies' => '1',
            '_ap_nonce' => $nonce,
        ], $this->db);
        $this->assertIsString($redirect);
        $this->assertMatchesRegularExpression(
            '/ap_forum_notice=topic_created(?:&|#|$)/',
            (string) $redirect
        );
        $this->assertStringNotContainsString('topic_created_email_on', (string) $redirect);
        $topics = AP_Forum::getTopics($forumId, [], $this->db);
        $this->assertCount(1, $topics);
        $topicId = (int) $topics[0]->topic_id;
        $this->assertTrue(AP_Forum_Notify::isSubscribed($this->userId, $topicId, $this->db));
        $this->assertTrue(AP_Forum_Notify::isUserNotifyEnabled($this->userId, $this->db));
    }

    public function testNotifyCheckboxIgnoredWhenSiteOff(): void
    {
        $forumId = AP_Forum::insertForum(['forum_name' => 'Site off compose'], $this->db);
        $this->assertFalse(AP_Forum_Notify::isEnabled($this->db));
        $this->assertTrue(AP_Session::setAuthCookie($this->userId, false, $this->db));

        $nonce = AP_Nonce::create('ap_forum_new_topic_' . $forumId, $this->userId);
        $redirect = AP_Forum_Front::handlePost([
            'ap_forum_action' => AP_Forum_Front::ACTION_NEW_TOPIC,
            'forum_id' => $forumId,
            'topic_title' => 'Crafted checkbox',
            'topic_body' => 'Site master is off.',
            'notify_replies' => '1',
            '_ap_nonce' => $nonce,
        ], $this->db);
        $this->assertIsString($redirect);
        $topics = AP_Forum::getTopics($forumId, [], $this->db);
        $this->assertCount(1, $topics);
        $topicId = (int) $topics[0]->topic_id;
        $this->assertFalse(AP_Forum_Notify::isSubscribed($this->userId, $topicId, $this->db));
        $this->assertSame(0, $this->subscriptionCount());
        $this->assertFalse(AP_Forum_Notify::isUserNotifyEnabled($this->userId, $this->db));
        $this->assertMatchesRegularExpression(
            '/ap_forum_notice=topic_created(?:&|#|$)/',
            (string) $redirect
        );
        $this->assertStringNotContainsString('topic_created_email_on', (string) $redirect);
    }

    public function testReplyNotifyCheckboxDoesNotUnsubscribe(): void
    {
        $forumId = AP_Forum::insertForum(['forum_name' => 'Keep watch'], $this->db);
        $topicId = AP_Forum::createTopic([
            'forum_id' => $forumId,
            'topic_title' => 'Already watching',
            'content' => 'OP body.',
            'poster_id' => $this->userId,
        ], $this->db);
        AP_Options::update('forum_topic_notify_enabled', '1', $this->db);
        $this->assertTrue(AP_Session::setAuthCookie($this->userId, false, $this->db));
        $this->assertTrue(AP_Forum_Notify::subscribe($this->userId, $topicId, $this->db));

        $replyNonce = AP_Nonce::create('ap_forum_reply_' . $topicId, $this->userId);
        $replyRedirect = AP_Forum_Front::handlePost([
            'ap_forum_action' => AP_Forum_Front::ACTION_REPLY,
            'topic_id' => $topicId,
            'reply_body' => 'Checkbox omitted must not drop the watch.',
            '_ap_nonce' => $replyNonce,
        ], $this->db);
        $this->assertIsString($replyRedirect);
        $this->assertTrue(AP_Forum_Notify::isSubscribed($this->userId, $topicId, $this->db));
        $this->assertSame(1, $this->subscriptionCount());
    }

    private function subscriptionCount(): int
    {
        return (int) $this->db->getVar(
            'SELECT COUNT(*) FROM '
            . $this->db->quoteIdentifier($this->db->table('topic_subscriptions'))
        );
    }
}
