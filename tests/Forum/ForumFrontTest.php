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
        $this->assertStringContainsString('First post body', $html);
        // Guests see a login prompt instead of the reply form.
        $this->assertStringContainsString('Log in', $html);

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
}
