<?php

/**
 * Capability checks on admin screens and privileged handlers.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Admin;

use AP_Admin;
use AP_Admin_Media;
use AP_Admin_Post_Edit;
use AP_Admin_Terms;
use AP_Comments_List_Table;
use AP_DB;
use AP_Migrator;
use AP_Nonce;
use AP_Options;
use AP_Post;
use AP_Posts_List_Table;
use AP_Roles;
use AP_Taxonomy;
use AP_User;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_Admin::class)]
#[CoversClass(AP_Admin_Post_Edit::class)]
final class AdminCapabilityTest extends TestCase
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
        require_once $this->root . '/ap-includes/class-ap-session.php';
        require_once $this->root . '/ap-includes/class-ap-roles.php';
        require_once $this->root . '/ap-includes/class-ap-nonce.php';
        require_once $this->root . '/ap-includes/class-ap-post.php';
        require_once $this->root . '/ap-includes/class-ap-taxonomy.php';
        require_once $this->root . '/ap-includes/class-ap-comment.php';
        require_once $this->root . '/ap-includes/functions.php';
        require_once $this->root . '/ap-admin/includes/class-ap-admin.php';
        require_once $this->root . '/ap-admin/includes/class-ap-posts-list-table.php';
        require_once $this->root . '/ap-admin/includes/class-ap-admin-post-edit.php';
        require_once $this->root . '/ap-admin/includes/class-ap-admin-terms.php';
        require_once $this->root . '/ap-admin/includes/class-ap-comments-list-table.php';
        require_once $this->root . '/ap-admin/includes/class-ap-admin-media.php';
        require_once $this->root . '/ap-admin/includes/class-ap-media-list-table.php';

        if (!defined('AP_NONCE_KEY')) {
            define('AP_NONCE_KEY', 'test-nonce-key-' . str_repeat('n', 32));
        }
        if (!defined('AP_NONCE_SALT')) {
            define('AP_NONCE_SALT', 'test-nonce-salt-' . str_repeat('s', 32));
        }
        if (!defined('AP_LOGGED_IN_KEY')) {
            define('AP_LOGGED_IN_KEY', 'test-logged-in-key-' . str_repeat('a', 32));
        }
        if (!defined('AP_LOGGED_IN_SALT')) {
            define('AP_LOGGED_IN_SALT', 'test-logged-in-salt-' . str_repeat('b', 32));
        }

        AP_Roles::flushCache();
        AP_Options::flushCache();
        AP_Post::resetRegistry();
        AP_Taxonomy::resetRegistry();
        AP_Admin::clearNotices();

        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');

        $migrator = new AP_Migrator($this->db, AP_Migrator::defaultMigrationsPath());
        $migrator->migrate();
        AP_Roles::ensureDefaults($this->db);
        AP_Post::ensureBuiltins();
        AP_Taxonomy::ensureBuiltins();
    }

    protected function tearDown(): void
    {
        AP_Roles::flushCache();
        AP_Options::flushCache();
        AP_Post::resetRegistry();
        AP_Taxonomy::resetRegistry();
        AP_Admin::clearNotices();
    }

    private function createUser(string $login, string $role): int
    {
        $created = AP_User::create([
            'user_login' => $login,
            'user_email' => $login . '@example.test',
            'password' => 'password123',
            'role' => $role,
        ], $this->db);
        $this->assertTrue($created['ok'], implode('; ', $created['errors'] ?? []));

        return $created['id'];
    }

    public function testScreenCapabilitiesMapCoversStaticScreens(): void
    {
        $map = AP_Admin::screenCapabilities();
        $expected = [
            'index.php' => 'read',
            'profile.php' => 'read',
            'users.php' => 'list_users',
            'user-new.php' => 'create_users',
            'user-edit.php' => 'edit_users',
            'edit-comments.php' => 'moderate_comments',
            'edit-tags.php' => 'manage_categories',
            'upload.php' => 'upload_files',
            'media.php' => 'upload_files',
            'media-new.php' => 'upload_files',
            'themes.php' => 'switch_themes',
            'theme-options.php' => 'edit_theme_options',
            'nav-menus.php' => 'edit_theme_options',
            'widgets.php' => 'edit_theme_options',
            'plugins.php' => 'activate_plugins',
            'update-core.php' => 'update_core',
            'site-health.php' => 'view_site_health',
            'analytics.php' => 'manage_options',
            'export-personal-data.php' => 'export_others_personal_data',
            'erase-personal-data.php' => 'erase_others_personal_data',
            'options-general.php' => 'manage_options',
            'options-mail.php' => 'manage_options',
            'options-modules.php' => 'manage_options',
            'options-writing.php' => 'manage_options',
            'options-reading.php' => 'manage_options',
            'options-discussion.php' => 'manage_options',
            'options-media.php' => 'manage_options',
            'options-permalink.php' => 'manage_options',
            'options-privacy.php' => 'manage_privacy_options',
            'options-hall-of-fame.php' => 'manage_options',
            'options-forums.php' => 'manage_options',
            'forums.php' => 'manage_forums',
            'forum-edit.php' => 'manage_forums',
            'forum-topics.php' => 'moderate_forums',
            'forum-moderation.php' => 'moderate_forums',
            'forum-groups.php' => 'manage_forums',
        ];
        foreach ($expected as $file => $cap) {
            $this->assertArrayHasKey($file, $map);
            $this->assertSame($cap, $map[$file], $file);
        }
    }

    public function testAdminScreensCallRequireCapability(): void
    {
        $adminDir = $this->root . '/ap-admin';
        $checks = [
            'index.php' => "'read'",
            'profile.php' => "'read'",
            'edit.php' => 'requireCapability',
            'post.php' => 'requireCapability',
            'post-new.php' => 'requireCapability',
            'revision.php' => 'requireCapability',
            // Registered plugin pages: cap from AP_Admin_Menu registry.
            'admin.php' => 'capabilityForRegisteredPage',
            'edit-comments.php' => 'moderate_comments',
            'edit-tags.php' => 'manage_categories',
            'upload.php' => 'upload_files',
            'media.php' => 'upload_files',
            'media-new.php' => 'upload_files',
            'themes.php' => 'switch_themes',
            'theme-options.php' => 'edit_theme_options',
            'nav-menus.php' => 'edit_theme_options',
            'widgets.php' => 'edit_theme_options',
            'plugins.php' => 'activate_plugins',
            'update-core.php' => 'update_core',
            'site-health.php' => 'view_site_health',
            'analytics.php' => 'manage_options',
            'options-general.php' => 'manage_options',
            'options-mail.php' => 'manage_options',
            'options-modules.php' => 'manage_options',
            'options-writing.php' => 'manage_options',
            'options-reading.php' => 'manage_options',
            'options-discussion.php' => 'manage_options',
            'options-media.php' => 'manage_options',
            'options-permalink.php' => 'manage_options',
            'options-privacy.php' => 'manage_privacy_options',
            'options-hall-of-fame.php' => 'manage_options',
            'options-forums.php' => 'manage_options',
            'export-personal-data.php' => 'export_others_personal_data',
            'erase-personal-data.php' => 'erase_others_personal_data',
            'forums.php' => 'manage_forums',
            'forum-edit.php' => 'manage_forums',
            'forum-topics.php' => 'moderate_forums',
            'forum-moderation.php' => 'moderate_forums',
            'forum-groups.php' => 'manage_forums',
            'users.php' => 'list_users',
            'user-new.php' => 'create_users',
            'user-edit.php' => 'edit_users',
        ];
        foreach ($checks as $file => $needle) {
            $src = (string) file_get_contents($adminDir . '/' . $file);
            $this->assertStringContainsString(
                'requireCapability',
                $src,
                "{$file} should gate with requireCapability"
            );
            if ($needle !== 'requireCapability') {
                $this->assertStringContainsString($needle, $src, "{$file} should mention {$needle}");
            }
        }
    }

    public function testPostTypeCapHelpers(): void
    {
        $this->assertSame('edit_posts', AP_Admin::editCapabilityForPostType('post'));
        $this->assertSame('edit_pages', AP_Admin::editCapabilityForPostType('page'));
        $this->assertSame('edit_post', AP_Admin::editMetaCapForPostType('post'));
        $this->assertSame('edit_page', AP_Admin::editMetaCapForPostType('page'));
        $this->assertSame('delete_post', AP_Admin::deleteMetaCapForPostType('post'));
        $this->assertSame('publish_pages', AP_Admin::publishCapabilityForPostType('page'));
        $this->assertSame('edit_others_posts', AP_Admin::editOthersCapabilityForPostType('post'));
        $this->assertSame('edit_others_pages', AP_Admin::editOthersCapabilityForPostType('page'));
        $this->assertSame('edit_others_posts', AP_Admin::editOthersCapabilityForPostType('custom'));
    }

    public function testMenuFilteredByRole(): void
    {
        $subId = $this->createUser('subcap', 'subscriber');
        // Simulate current user via session tokens is heavy; use userCan + menu without login.
        // menuItems only filters when logged in — without session, full map returned.
        $items = AP_Admin::menuItems('', $this->db);
        $ids = array_column($items, 'id');
        $this->assertContains('dashboard', $ids);
        $this->assertContains('posts', $ids);

        // Direct cap matrix for roles (menu caps).
        $this->assertTrue(AP_Roles::userCan($subId, 'read', null, $this->db));
        $this->assertFalse(AP_Roles::userCan($subId, 'edit_posts', null, $this->db));
        $this->assertFalse(AP_Roles::userCan($subId, 'list_users', null, $this->db));
        $this->assertFalse(AP_Roles::userCan($subId, 'manage_options', null, $this->db));
        $this->assertFalse(AP_Roles::userCan($subId, 'upload_files', null, $this->db));
        $this->assertFalse(AP_Roles::userCan($subId, 'moderate_comments', null, $this->db));

        $authorId = $this->createUser('authorcap', 'author');
        $this->assertTrue(AP_Roles::userCan($authorId, 'edit_posts', null, $this->db));
        $this->assertTrue(AP_Roles::userCan($authorId, 'upload_files', null, $this->db));
        $this->assertFalse(AP_Roles::userCan($authorId, 'edit_pages', null, $this->db));
        $this->assertFalse(AP_Roles::userCan($authorId, 'manage_categories', null, $this->db));
        $this->assertFalse(AP_Roles::userCan($authorId, 'list_users', null, $this->db));

        $editorId = $this->createUser('editorcap', 'editor');
        $this->assertTrue(AP_Roles::userCan($editorId, 'edit_pages', null, $this->db));
        $this->assertTrue(AP_Roles::userCan($editorId, 'manage_categories', null, $this->db));
        $this->assertTrue(AP_Roles::userCan($editorId, 'moderate_comments', null, $this->db));
        $this->assertFalse(AP_Roles::userCan($editorId, 'manage_options', null, $this->db));
        $this->assertFalse(AP_Roles::userCan($editorId, 'list_users', null, $this->db));

        $adminId = $this->createUser('admincap', 'administrator');
        $this->assertTrue(AP_Roles::userCan($adminId, 'manage_options', null, $this->db));
        $this->assertTrue(AP_Roles::userCan($adminId, 'list_users', null, $this->db));
        $this->assertTrue(AP_Roles::userCan($adminId, 'edit_theme_options', null, $this->db));
    }

    public function testSubscriberCannotCreatePost(): void
    {
        $subId = $this->createUser('subpost', 'subscriber');
        $nonce = ap_create_nonce('new-post', $subId);
        $result = AP_Admin_Post_Edit::save([
            'post_title' => 'Nope',
            'post_content' => 'Body',
            'post_status' => 'draft',
            'post_type' => 'post',
            '_ap_nonce' => $nonce,
        ], $subId, $this->db);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('permission', strtolower(implode(' ', $result['errors'])));
    }

    public function testContributorPublishDowngradesToPending(): void
    {
        $contribId = $this->createUser('contrib', 'contributor');
        $nonce = ap_create_nonce('new-post', $contribId);
        $result = AP_Admin_Post_Edit::save([
            'post_title' => 'My Draft',
            'post_content' => 'Body',
            'post_status' => 'publish',
            'post_type' => 'post',
            'save_action' => 'publish',
            '_ap_nonce' => $nonce,
        ], $contribId, $this->db);

        $this->assertTrue($result['ok'], implode('; ', $result['errors']));
        $this->assertNotNull($result['post']);
        $this->assertSame('pending', $result['post']->post_status);
    }

    public function testAuthorSelectOnlyWithEditOthersCapability(): void
    {
        $adminId = $this->createUser('authseladmin', 'administrator');
        $editorId = $this->createUser('autheseleditor', 'editor');
        $authorId = $this->createUser('authselauthor', 'author');
        $contribId = $this->createUser('authselcontrib', 'contributor');

        $this->assertTrue(AP_Admin_Post_Edit::canAssignAuthor($adminId, 'post', $this->db));
        $this->assertTrue(AP_Admin_Post_Edit::canAssignAuthor($adminId, 'page', $this->db));
        $this->assertTrue(AP_Admin_Post_Edit::canAssignAuthor($editorId, 'post', $this->db));
        $this->assertTrue(AP_Admin_Post_Edit::canAssignAuthor($editorId, 'page', $this->db));
        $this->assertFalse(AP_Admin_Post_Edit::canAssignAuthor($authorId, 'post', $this->db));
        $this->assertFalse(AP_Admin_Post_Edit::canAssignAuthor($authorId, 'page', $this->db));
        $this->assertFalse(AP_Admin_Post_Edit::canAssignAuthor($contribId, 'post', $this->db));
        $this->assertFalse(AP_Admin_Post_Edit::canAssignAuthor(0, 'post', $this->db));

        $postNewAdmin = AP_Admin_Post_Edit::renderForm(null, 'post', $adminId, $this->db);
        $this->assertStringContainsString('<select name="post_author"', $postNewAdmin);
        $this->assertStringContainsString('id="post_author"', $postNewAdmin);
        $this->assertStringContainsString('ap-metabox-author', $postNewAdmin);

        $pageNewAdmin = AP_Admin_Post_Edit::renderForm(null, 'page', $adminId, $this->db);
        $this->assertStringContainsString('<select name="post_author"', $pageNewAdmin);

        $postNewEditor = AP_Admin_Post_Edit::renderForm(null, 'post', $editorId, $this->db);
        $this->assertStringContainsString('<select name="post_author"', $postNewEditor);
        $pageNewEditor = AP_Admin_Post_Edit::renderForm(null, 'page', $editorId, $this->db);
        $this->assertStringContainsString('<select name="post_author"', $pageNewEditor);

        $postNewAuthor = AP_Admin_Post_Edit::renderForm(null, 'post', $authorId, $this->db);
        $this->assertStringNotContainsString('name="post_author"', $postNewAuthor);
        $this->assertStringNotContainsString('ap-metabox-author', $postNewAuthor);

        $pageNewAuthor = AP_Admin_Post_Edit::renderForm(null, 'page', $authorId, $this->db);
        $this->assertStringNotContainsString('name="post_author"', $pageNewAuthor);

        $postNewContrib = AP_Admin_Post_Edit::renderForm(null, 'post', $contribId, $this->db);
        $this->assertStringNotContainsString('name="post_author"', $postNewContrib);

        $ownPostId = AP_Post::insert([
            'post_title' => 'Author own post',
            'post_content' => 'x',
            'post_status' => 'draft',
            'post_type' => 'post',
            'post_author' => $authorId,
        ], $this->db);
        $this->assertGreaterThan(0, $ownPostId);
        $ownPost = AP_Post::get($ownPostId, $this->db);
        $this->assertNotNull($ownPost);

        $ownHtml = AP_Admin_Post_Edit::renderForm($ownPost, 'post', $authorId, $this->db);
        $this->assertStringNotContainsString('name="post_author"', $ownHtml);

        $editAsAdmin = AP_Admin_Post_Edit::renderForm($ownPost, 'post', $adminId, $this->db);
        $this->assertStringContainsString('<select name="post_author"', $editAsAdmin);
        $this->assertStringContainsString(
            'value="' . $authorId . '" selected',
            $editAsAdmin
        );

        $editAsEditor = AP_Admin_Post_Edit::renderForm($ownPost, 'post', $editorId, $this->db);
        $this->assertStringContainsString('<select name="post_author"', $editAsEditor);

        $pageId = AP_Post::insert([
            'post_title' => 'Admin page',
            'post_content' => 'p',
            'post_status' => 'draft',
            'post_type' => 'page',
            'post_author' => $adminId,
        ], $this->db);
        $page = AP_Post::get($pageId, $this->db);
        $this->assertNotNull($page);
        $pageEdit = AP_Admin_Post_Edit::renderForm($page, 'page', $adminId, $this->db);
        $this->assertStringContainsString('<select name="post_author"', $pageEdit);
        $pageEditAuthor = AP_Admin_Post_Edit::renderForm($page, 'page', $authorId, $this->db);
        $this->assertStringNotContainsString('name="post_author"', $pageEditAuthor);
    }

    public function testAuthorCandidatesAreLivingActiveOwners(): void
    {
        $adminId = $this->createUser('acandadmin', 'administrator');
        $editorId = $this->createUser('acandeditor', 'editor');
        $authorId = $this->createUser('acandauthor', 'author');
        $contribId = $this->createUser('acandcontrib', 'contributor');
        $subId = $this->createUser('acandsub', 'subscriber');

        $pendingId = $this->createUser('acandpending', 'author');
        $pendingUpdate = AP_User::update($pendingId, ['user_status' => 1], $this->db);
        $this->assertTrue($pendingUpdate['ok']);

        $bannedId = $this->createUser('acandbanned', 'editor');
        $bannedUpdate = AP_User::update($bannedId, ['user_status' => 1], $this->db);
        $this->assertTrue($bannedUpdate['ok']);

        $goneId = $this->createUser('acandgone', 'author');
        $this->assertTrue(AP_User::delete($goneId, $this->db));

        $this->assertTrue(AP_Admin_Post_Edit::userCanOwnPostType($adminId, 'post', $this->db));
        $this->assertTrue(AP_Admin_Post_Edit::userCanOwnPostType($editorId, 'post', $this->db));
        $this->assertTrue(AP_Admin_Post_Edit::userCanOwnPostType($authorId, 'post', $this->db));
        $this->assertTrue(AP_Admin_Post_Edit::userCanOwnPostType($contribId, 'post', $this->db));
        $this->assertFalse(AP_Admin_Post_Edit::userCanOwnPostType($subId, 'post', $this->db));
        $this->assertFalse(AP_Admin_Post_Edit::userCanOwnPostType($pendingId, 'post', $this->db));
        $this->assertFalse(AP_Admin_Post_Edit::userCanOwnPostType($bannedId, 'post', $this->db));
        $this->assertFalse(AP_Admin_Post_Edit::userCanOwnPostType($goneId, 'post', $this->db));
        $this->assertFalse(AP_Admin_Post_Edit::userCanOwnPostType(0, 'post', $this->db));

        $this->assertTrue(AP_Admin_Post_Edit::userCanOwnPostType($adminId, 'page', $this->db));
        $this->assertTrue(AP_Admin_Post_Edit::userCanOwnPostType($editorId, 'page', $this->db));
        $this->assertFalse(AP_Admin_Post_Edit::userCanOwnPostType($authorId, 'page', $this->db));
        $this->assertFalse(AP_Admin_Post_Edit::userCanOwnPostType($contribId, 'page', $this->db));
        $this->assertFalse(AP_Admin_Post_Edit::userCanOwnPostType($subId, 'page', $this->db));
        $this->assertFalse(AP_Admin_Post_Edit::userCanOwnPostType($pendingId, 'page', $this->db));

        $postIds = $this->idsOf(AP_Admin_Post_Edit::authorCandidates('post', 0, $this->db));
        foreach ([$adminId, $editorId, $authorId, $contribId] as $id) {
            $this->assertContains($id, $postIds);
        }
        foreach ([$subId, $pendingId, $bannedId, $goneId] as $id) {
            $this->assertNotContains($id, $postIds);
        }

        $pageIds = $this->idsOf(AP_Admin_Post_Edit::authorCandidates('page', 0, $this->db));
        $this->assertContains($adminId, $pageIds);
        $this->assertContains($editorId, $pageIds);
        foreach ([$authorId, $contribId, $subId, $pendingId, $bannedId, $goneId] as $id) {
            $this->assertNotContains($id, $pageIds);
        }

        // Edit screen keeps the current author visible even after a ban.
        $withBanned = $this->idsOf(
            AP_Admin_Post_Edit::authorCandidates('post', $bannedId, $this->db)
        );
        $this->assertContains($bannedId, $withBanned);

        $htmlPost = AP_Admin_Post_Edit::renderForm(null, 'post', $adminId, $this->db);
        $postOptions = $this->optionValuesFromSelect($htmlPost, 'post_author');
        foreach ([$adminId, $editorId, $authorId, $contribId] as $id) {
            $this->assertContains($id, $postOptions);
        }
        foreach ([$subId, $pendingId, $bannedId, $goneId] as $id) {
            $this->assertNotContains($id, $postOptions);
        }

        $htmlPage = AP_Admin_Post_Edit::renderForm(null, 'page', $adminId, $this->db);
        $pageOptions = $this->optionValuesFromSelect($htmlPage, 'post_author');
        $this->assertContains($adminId, $pageOptions);
        $this->assertContains($editorId, $pageOptions);
        foreach ([$authorId, $contribId, $subId] as $id) {
            $this->assertNotContains($id, $pageOptions);
        }
    }

    public function testInsertPersistsChosenAuthorWhenAllowed(): void
    {
        $adminId = $this->createUser('insadmin', 'administrator');
        $editorId = $this->createUser('inseditor', 'editor');
        $authorId = $this->createUser('insauthor', 'author');
        $contribId = $this->createUser('inscontrib', 'contributor');

        $nonce = ap_create_nonce('new-post', $adminId);
        $asAuthor = AP_Admin_Post_Edit::save([
            'post_title' => 'By other author',
            'post_content' => 'Body',
            'post_status' => 'draft',
            'post_type' => 'post',
            'post_author' => $authorId,
            'save_action' => 'draft',
            '_ap_nonce' => $nonce,
        ], $adminId, $this->db);
        $this->assertTrue($asAuthor['ok'], implode('; ', $asAuthor['errors']));
        $this->assertNotNull($asAuthor['post']);
        $this->assertSame($authorId, (int) $asAuthor['post']->post_author);

        $editorNonce = ap_create_nonce('new-post', $editorId);
        $asContrib = AP_Admin_Post_Edit::save([
            'post_title' => 'Editor assigns contrib',
            'post_content' => 'Body',
            'post_status' => 'draft',
            'post_type' => 'post',
            'post_author' => $contribId,
            'save_action' => 'draft',
            '_ap_nonce' => $editorNonce,
        ], $editorId, $this->db);
        $this->assertTrue($asContrib['ok'], implode('; ', $asContrib['errors']));
        $this->assertNotNull($asContrib['post']);
        $this->assertSame($contribId, (int) $asContrib['post']->post_author);

        $pageNonce = ap_create_nonce('new-post', $adminId);
        $page = AP_Admin_Post_Edit::save([
            'post_title' => 'Page by editor',
            'post_content' => 'Body',
            'post_status' => 'draft',
            'post_type' => 'page',
            'post_author' => $editorId,
            'save_action' => 'draft',
            '_ap_nonce' => $pageNonce,
        ], $adminId, $this->db);
        $this->assertTrue($page['ok'], implode('; ', $page['errors']));
        $this->assertNotNull($page['post']);
        $this->assertSame($editorId, (int) $page['post']->post_author);
    }

    public function testInsertUsesLoggedInUserWhenAuthorAssignmentNotAllowed(): void
    {
        $authorId = $this->createUser('insownauthor', 'author');
        $otherId = $this->createUser('insotherauthor', 'author');
        $contribId = $this->createUser('insowncontrib', 'contributor');
        $targetId = $this->createUser('inscontribtarget', 'author');

        $nonce = ap_create_nonce('new-post', $authorId);
        $result = AP_Admin_Post_Edit::save([
            'post_title' => 'Cannot reassign',
            'post_content' => 'Body',
            'post_status' => 'draft',
            'post_type' => 'post',
            'post_author' => $otherId,
            'save_action' => 'draft',
            '_ap_nonce' => $nonce,
        ], $authorId, $this->db);
        $this->assertTrue($result['ok'], implode('; ', $result['errors']));
        $this->assertNotNull($result['post']);
        $this->assertSame($authorId, (int) $result['post']->post_author);

        $contribNonce = ap_create_nonce('new-post', $contribId);
        $asContrib = AP_Admin_Post_Edit::save([
            'post_title' => 'Contrib cannot reassign',
            'post_content' => 'Body',
            'post_status' => 'draft',
            'post_type' => 'post',
            'post_author' => $targetId,
            'save_action' => 'draft',
            '_ap_nonce' => $contribNonce,
        ], $contribId, $this->db);
        $this->assertTrue($asContrib['ok'], implode('; ', $asContrib['errors']));
        $this->assertNotNull($asContrib['post']);
        $this->assertSame($contribId, (int) $asContrib['post']->post_author);
    }

    public function testInsertIgnoresCraftedForbiddenAuthorId(): void
    {
        $adminId = $this->createUser('inscraftadmin', 'administrator');
        $authorId = $this->createUser('inscraftauthor', 'author');
        $subId = $this->createUser('inscraftsub', 'subscriber');

        $asSub = AP_Admin_Post_Edit::save([
            'post_title' => 'Not a subscriber post',
            'post_content' => 'Body',
            'post_status' => 'draft',
            'post_type' => 'post',
            'post_author' => $subId,
            'save_action' => 'draft',
            '_ap_nonce' => ap_create_nonce('new-post', $adminId),
        ], $adminId, $this->db);
        $this->assertTrue($asSub['ok'], implode('; ', $asSub['errors']));
        $this->assertNotNull($asSub['post']);
        $this->assertSame($adminId, (int) $asSub['post']->post_author);

        $page = AP_Admin_Post_Edit::save([
            'post_title' => 'Not an author page',
            'post_content' => 'Body',
            'post_status' => 'draft',
            'post_type' => 'page',
            'post_author' => $authorId,
            'save_action' => 'draft',
            '_ap_nonce' => ap_create_nonce('new-post', $adminId),
        ], $adminId, $this->db);
        $this->assertTrue($page['ok'], implode('; ', $page['errors']));
        $this->assertNotNull($page['post']);
        $this->assertSame($adminId, (int) $page['post']->post_author);

        $unknown = AP_Admin_Post_Edit::save([
            'post_title' => 'Unknown author id',
            'post_content' => 'Body',
            'post_status' => 'draft',
            'post_type' => 'post',
            'post_author' => 999999,
            'save_action' => 'draft',
            '_ap_nonce' => ap_create_nonce('new-post', $adminId),
        ], $adminId, $this->db);
        $this->assertTrue($unknown['ok'], implode('; ', $unknown['errors']));
        $this->assertNotNull($unknown['post']);
        $this->assertSame($adminId, (int) $unknown['post']->post_author);

        $missing = AP_Admin_Post_Edit::save([
            'post_title' => 'No author field',
            'post_content' => 'Body',
            'post_status' => 'draft',
            'post_type' => 'post',
            'save_action' => 'draft',
            '_ap_nonce' => ap_create_nonce('new-post', $adminId),
        ], $adminId, $this->db);
        $this->assertTrue($missing['ok'], implode('; ', $missing['errors']));
        $this->assertNotNull($missing['post']);
        $this->assertSame($adminId, (int) $missing['post']->post_author);

        $asArray = AP_Admin_Post_Edit::save([
            'post_title' => 'Array author field',
            'post_content' => 'Body',
            'post_status' => 'draft',
            'post_type' => 'post',
            'post_author' => [$subId],
            'save_action' => 'draft',
            '_ap_nonce' => ap_create_nonce('new-post', $adminId),
        ], $adminId, $this->db);
        $this->assertTrue($asArray['ok'], implode('; ', $asArray['errors']));
        $this->assertNotNull($asArray['post']);
        $this->assertSame($adminId, (int) $asArray['post']->post_author);

        $bannedId = $this->createUser('inscraftbanned', 'author');
        $bannedUpdate = AP_User::update($bannedId, ['user_status' => 1], $this->db);
        $this->assertTrue($bannedUpdate['ok']);
        $asBanned = AP_Admin_Post_Edit::save([
            'post_title' => 'Banned author id',
            'post_content' => 'Body',
            'post_status' => 'draft',
            'post_type' => 'post',
            'post_author' => $bannedId,
            'save_action' => 'draft',
            '_ap_nonce' => ap_create_nonce('new-post', $adminId),
        ], $adminId, $this->db);
        $this->assertTrue($asBanned['ok'], implode('; ', $asBanned['errors']));
        $this->assertNotNull($asBanned['post']);
        $this->assertSame($adminId, (int) $asBanned['post']->post_author);
    }

    public function testUpdatePersistsPostedAuthorWhenAllowed(): void
    {
        $adminId = $this->createUser('updadmin', 'administrator');
        $editorId = $this->createUser('updeditor', 'editor');
        $authorId = $this->createUser('updauthor', 'author');
        $otherAuthorId = $this->createUser('updotherauthor', 'author');
        $contribId = $this->createUser('updcontrib', 'contributor');

        $postId = AP_Post::insert([
            'post_title' => 'Owned by author',
            'post_content' => 'Body',
            'post_status' => 'draft',
            'post_type' => 'post',
            'post_author' => $authorId,
        ], $this->db);
        $this->assertGreaterThan(0, $postId);

        $asOther = AP_Admin_Post_Edit::save([
            'post_ID' => $postId,
            'post_title' => 'Reassigned by admin',
            'post_content' => 'Body',
            'post_status' => 'draft',
            'post_type' => 'post',
            'post_author' => $otherAuthorId,
            'save_action' => 'draft',
            '_ap_nonce' => ap_create_nonce('update-post-' . $postId, $adminId),
        ], $adminId, $this->db);
        $this->assertTrue($asOther['ok'], implode('; ', $asOther['errors']));
        $this->assertNotNull($asOther['post']);
        $this->assertSame($otherAuthorId, (int) $asOther['post']->post_author);
        $this->assertSame('Reassigned by admin', $asOther['post']->post_title);

        $asContrib = AP_Admin_Post_Edit::save([
            'post_ID' => $postId,
            'post_title' => 'Reassigned by editor',
            'post_content' => 'Body',
            'post_status' => 'draft',
            'post_type' => 'post',
            'post_author' => $contribId,
            'save_action' => 'draft',
            '_ap_nonce' => ap_create_nonce('update-post-' . $postId, $editorId),
        ], $editorId, $this->db);
        $this->assertTrue($asContrib['ok'], implode('; ', $asContrib['errors']));
        $this->assertNotNull($asContrib['post']);
        $this->assertSame($contribId, (int) $asContrib['post']->post_author);

        $keep = AP_Admin_Post_Edit::save([
            'post_ID' => $postId,
            'post_title' => 'Title only',
            'post_content' => 'Body',
            'post_status' => 'draft',
            'post_type' => 'post',
            'save_action' => 'draft',
            '_ap_nonce' => ap_create_nonce('update-post-' . $postId, $adminId),
        ], $adminId, $this->db);
        $this->assertTrue($keep['ok'], implode('; ', $keep['errors']));
        $this->assertNotNull($keep['post']);
        $this->assertSame($contribId, (int) $keep['post']->post_author);
        $this->assertSame('Title only', $keep['post']->post_title);

        $pageId = AP_Post::insert([
            'post_title' => 'Admin page',
            'post_content' => 'Body',
            'post_status' => 'draft',
            'post_type' => 'page',
            'post_author' => $adminId,
        ], $this->db);
        $this->assertGreaterThan(0, $pageId);

        $page = AP_Admin_Post_Edit::save([
            'post_ID' => $pageId,
            'post_title' => 'Page by editor',
            'post_content' => 'Body',
            'post_status' => 'draft',
            'post_type' => 'page',
            'post_author' => $editorId,
            'save_action' => 'draft',
            '_ap_nonce' => ap_create_nonce('update-post-' . $pageId, $adminId),
        ], $adminId, $this->db);
        $this->assertTrue($page['ok'], implode('; ', $page['errors']));
        $this->assertNotNull($page['post']);
        $this->assertSame($editorId, (int) $page['post']->post_author);
    }

    public function testUpdateKeepsExistingAuthorWhenAssignmentNotAllowed(): void
    {
        $authorId = $this->createUser('updnocapauthor', 'author');
        $otherId = $this->createUser('updnocapother', 'author');
        $contribId = $this->createUser('updnocapcontrib', 'contributor');
        $targetId = $this->createUser('updnocaptarget', 'author');

        $postId = AP_Post::insert([
            'post_title' => 'Author own draft',
            'post_content' => 'Body',
            'post_status' => 'draft',
            'post_type' => 'post',
            'post_author' => $authorId,
        ], $this->db);
        $this->assertGreaterThan(0, $postId);

        $result = AP_Admin_Post_Edit::save([
            'post_ID' => $postId,
            'post_title' => 'Still mine',
            'post_content' => 'Body',
            'post_status' => 'draft',
            'post_type' => 'post',
            'post_author' => $otherId,
            'save_action' => 'draft',
            '_ap_nonce' => ap_create_nonce('update-post-' . $postId, $authorId),
        ], $authorId, $this->db);
        $this->assertTrue($result['ok'], implode('; ', $result['errors']));
        $this->assertNotNull($result['post']);
        $this->assertSame($authorId, (int) $result['post']->post_author);
        $this->assertSame('Still mine', $result['post']->post_title);

        $contribPostId = AP_Post::insert([
            'post_title' => 'Contrib own draft',
            'post_content' => 'Body',
            'post_status' => 'draft',
            'post_type' => 'post',
            'post_author' => $contribId,
        ], $this->db);
        $this->assertGreaterThan(0, $contribPostId);

        $asContrib = AP_Admin_Post_Edit::save([
            'post_ID' => $contribPostId,
            'post_title' => 'Contrib cannot reassign',
            'post_content' => 'Body',
            'post_status' => 'draft',
            'post_type' => 'post',
            'post_author' => $targetId,
            'save_action' => 'draft',
            '_ap_nonce' => ap_create_nonce('update-post-' . $contribPostId, $contribId),
        ], $contribId, $this->db);
        $this->assertTrue($asContrib['ok'], implode('; ', $asContrib['errors']));
        $this->assertNotNull($asContrib['post']);
        $this->assertSame($contribId, (int) $asContrib['post']->post_author);
    }

    public function testUpdateIgnoresCraftedForbiddenAuthorId(): void
    {
        $adminId = $this->createUser('updcraftadmin', 'administrator');
        $authorId = $this->createUser('updcraftauthor', 'author');
        $subId = $this->createUser('updcraftsub', 'subscriber');
        $bannedId = $this->createUser('updcraftbanned', 'author');
        $bannedUpdate = AP_User::update($bannedId, ['user_status' => 1], $this->db);
        $this->assertTrue($bannedUpdate['ok']);

        $postId = AP_Post::insert([
            'post_title' => 'Keep this author',
            'post_content' => 'Body',
            'post_status' => 'draft',
            'post_type' => 'post',
            'post_author' => $authorId,
        ], $this->db);
        $this->assertGreaterThan(0, $postId);

        $asSub = AP_Admin_Post_Edit::save([
            'post_ID' => $postId,
            'post_title' => 'Not a subscriber post',
            'post_content' => 'Body',
            'post_status' => 'draft',
            'post_type' => 'post',
            'post_author' => $subId,
            'save_action' => 'draft',
            '_ap_nonce' => ap_create_nonce('update-post-' . $postId, $adminId),
        ], $adminId, $this->db);
        $this->assertTrue($asSub['ok'], implode('; ', $asSub['errors']));
        $this->assertNotNull($asSub['post']);
        $this->assertSame($authorId, (int) $asSub['post']->post_author);

        $unknown = AP_Admin_Post_Edit::save([
            'post_ID' => $postId,
            'post_title' => 'Unknown author id',
            'post_content' => 'Body',
            'post_status' => 'draft',
            'post_type' => 'post',
            'post_author' => 999999,
            'save_action' => 'draft',
            '_ap_nonce' => ap_create_nonce('update-post-' . $postId, $adminId),
        ], $adminId, $this->db);
        $this->assertTrue($unknown['ok'], implode('; ', $unknown['errors']));
        $this->assertNotNull($unknown['post']);
        $this->assertSame($authorId, (int) $unknown['post']->post_author);

        $asBanned = AP_Admin_Post_Edit::save([
            'post_ID' => $postId,
            'post_title' => 'Banned author id',
            'post_content' => 'Body',
            'post_status' => 'draft',
            'post_type' => 'post',
            'post_author' => $bannedId,
            'save_action' => 'draft',
            '_ap_nonce' => ap_create_nonce('update-post-' . $postId, $adminId),
        ], $adminId, $this->db);
        $this->assertTrue($asBanned['ok'], implode('; ', $asBanned['errors']));
        $this->assertNotNull($asBanned['post']);
        $this->assertSame($authorId, (int) $asBanned['post']->post_author);

        $asArray = AP_Admin_Post_Edit::save([
            'post_ID' => $postId,
            'post_title' => 'Array author field',
            'post_content' => 'Body',
            'post_status' => 'draft',
            'post_type' => 'post',
            'post_author' => [$subId],
            'save_action' => 'draft',
            '_ap_nonce' => ap_create_nonce('update-post-' . $postId, $adminId),
        ], $adminId, $this->db);
        $this->assertTrue($asArray['ok'], implode('; ', $asArray['errors']));
        $this->assertNotNull($asArray['post']);
        $this->assertSame($authorId, (int) $asArray['post']->post_author);

        $pageId = AP_Post::insert([
            'post_title' => 'Admin page stays admin',
            'post_content' => 'Body',
            'post_status' => 'draft',
            'post_type' => 'page',
            'post_author' => $adminId,
        ], $this->db);
        $this->assertGreaterThan(0, $pageId);

        $page = AP_Admin_Post_Edit::save([
            'post_ID' => $pageId,
            'post_title' => 'Not an author page',
            'post_content' => 'Body',
            'post_status' => 'draft',
            'post_type' => 'page',
            'post_author' => $authorId,
            'save_action' => 'draft',
            '_ap_nonce' => ap_create_nonce('update-post-' . $pageId, $adminId),
        ], $adminId, $this->db);
        $this->assertTrue($page['ok'], implode('; ', $page['errors']));
        $this->assertNotNull($page['post']);
        $this->assertSame($adminId, (int) $page['post']->post_author);
    }

    /**
     * @param list<AP_User> $users
     *
     * @return list<int>
     */
    private function idsOf(array $users): array
    {
        $ids = [];
        foreach ($users as $user) {
            $ids[] = $user->ID;
        }

        return $ids;
    }

    /**
     * @return list<int>
     */
    private function optionValuesFromSelect(string $html, string $name): array
    {
        if (
            preg_match(
                '/<select name="' . preg_quote($name, '/') . '"[^>]*>(.*?)<\/select>/s',
                $html,
                $block
            ) !== 1
        ) {
            return [];
        }
        preg_match_all('/<option value="(\d+)"/', $block[1], $opts);

        return array_map('intval', $opts[1] ?? []);
    }

    public function testAuthorCanCreateAndPublishOwnPost(): void
    {
        $authorId = $this->createUser('authorpub', 'author');
        $nonce = ap_create_nonce('new-post', $authorId);
        $result = AP_Admin_Post_Edit::save([
            'post_title' => 'Author Post',
            'post_content' => 'Body',
            'post_status' => 'publish',
            'post_type' => 'post',
            'save_action' => 'publish',
            '_ap_nonce' => $nonce,
        ], $authorId, $this->db);

        $this->assertTrue($result['ok'], implode('; ', $result['errors']));
        $this->assertSame('publish', $result['post']->post_status);
        $this->assertSame($authorId, (int) $result['post']->post_author);
    }

    public function testAuthorCannotEditOthersPost(): void
    {
        $ownerId = $this->createUser('owner1', 'author');
        $otherId = $this->createUser('other1', 'author');
        $postId = AP_Post::insert([
            'post_title' => 'Owned',
            'post_content' => 'x',
            'post_status' => 'draft',
            'post_type' => 'post',
            'post_author' => $ownerId,
        ], $this->db);
        $this->assertGreaterThan(0, $postId);

        $nonce = ap_create_nonce('update-post-' . $postId, $otherId);
        $result = AP_Admin_Post_Edit::save([
            'post_ID' => $postId,
            'post_title' => 'Hijacked',
            'post_content' => 'y',
            'post_status' => 'draft',
            'post_type' => 'post',
            '_ap_nonce' => $nonce,
        ], $otherId, $this->db);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('permission', strtolower(implode(' ', $result['errors'])));
    }

    public function testAuthorCannotDeleteOthersPostViaRowAction(): void
    {
        $ownerId = $this->createUser('owner2', 'author');
        $otherId = $this->createUser('other2', 'author');
        $postId = AP_Post::insert([
            'post_title' => 'Stay',
            'post_status' => 'draft',
            'post_type' => 'post',
            'post_author' => $ownerId,
        ], $this->db);

        $nonce = ap_create_nonce('post-row-' . $postId, $otherId);
        $result = AP_Admin_Post_Edit::processRowAction([
            'action' => 'trash',
            'post' => $postId,
            '_ap_nonce' => $nonce,
        ], $this->db, $otherId);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('permission', strtolower(implode(' ', $result['errors'])));
    }

    public function testBulkActionSkipsItemsWithoutPermission(): void
    {
        $ownerId = $this->createUser('bulkowner', 'author');
        $otherId = $this->createUser('bulkother', 'author');
        $ownId = AP_Post::insert([
            'post_title' => 'Mine',
            'post_status' => 'draft',
            'post_type' => 'post',
            'post_author' => $otherId,
        ], $this->db);
        $theirsId = AP_Post::insert([
            'post_title' => 'Theirs',
            'post_status' => 'draft',
            'post_type' => 'post',
            'post_author' => $ownerId,
        ], $this->db);

        $table = new AP_Posts_List_Table('post', $this->db);
        $nonce = ap_create_nonce('bulk-posts', $otherId);
        $result = $table->processBulkAction([
            'action' => 'trash',
            'post' => [$ownId, $theirsId],
            '_ap_nonce' => $nonce,
        ], $otherId);

        $this->assertTrue($result['ok']);
        $this->assertSame(1, $result['count']);
        $own = AP_Post::get($ownId, $this->db);
        $theirs = AP_Post::get($theirsId, $this->db);
        $this->assertSame('trash', $own?->post_status);
        $this->assertSame('draft', $theirs?->post_status);
    }

    public function testAuthorCannotManageTerms(): void
    {
        $authorId = $this->createUser('termauthor', 'author');
        $nonce = ap_create_nonce('add-tag', $authorId);
        $result = AP_Admin_Terms::save([
            'taxonomy' => 'category',
            'name' => 'Blocked',
            '_ap_nonce' => $nonce,
        ], $authorId, $this->db);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('permission', strtolower(implode(' ', $result['errors'])));
    }

    public function testEditorCanManageTerms(): void
    {
        $editorId = $this->createUser('termeditor', 'editor');
        $nonce = ap_create_nonce('add-tag', $editorId);
        $result = AP_Admin_Terms::save([
            'taxonomy' => 'category',
            'name' => 'Allowed Cat',
            '_ap_nonce' => $nonce,
        ], $editorId, $this->db);

        $this->assertTrue($result['ok'], implode('; ', $result['errors']));
        $this->assertGreaterThan(0, $result['id']);
    }

    public function testAuthorCannotModerateComments(): void
    {
        $authorId = $this->createUser('comauthor', 'author');
        $postId = AP_Post::insert([
            'post_title' => 'Commented',
            'post_status' => 'publish',
            'post_type' => 'post',
            'post_author' => $authorId,
        ], $this->db);

        require_once $this->root . '/ap-includes/class-ap-comment.php';
        $commentId = \AP_Comment::insert([
            'comment_post_ID' => $postId,
            'comment_author' => 'Guest',
            'comment_author_email' => 'g@example.test',
            'comment_content' => 'Hi',
            'comment_approved' => '0',
        ], $this->db);
        $this->assertGreaterThan(0, $commentId);

        $table = new AP_Comments_List_Table($this->db);
        $nonce = ap_create_nonce('bulk-comments', $authorId);
        $result = $table->processBulkAction([
            'action' => 'approve',
            'comment' => [$commentId],
            '_ap_nonce' => $nonce,
        ], $authorId);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('permission', strtolower(implode(' ', $result['errors'])));
    }

    public function testSubscriberCannotUpload(): void
    {
        $subId = $this->createUser('uploadsub', 'subscriber');
        $nonce = ap_create_nonce('media-upload', $subId);
        $result = AP_Admin_Media::processUpload([], [
            '_ap_nonce' => $nonce,
        ], $subId, $this->db);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('permission', strtolower(implode(' ', $result['errors'])));
    }

    public function testAdminUserCanHelper(): void
    {
        $adminId = $this->createUser('adminhelper', 'administrator');
        $subId = $this->createUser('subhelper', 'subscriber');

        $this->assertTrue(AP_Admin::userCan($adminId, 'manage_options', null, $this->db));
        $this->assertFalse(AP_Admin::userCan($subId, 'edit_posts', null, $this->db));
        $this->assertTrue(AP_Admin::userCan($subId, 'read', null, $this->db));
    }

    public function testEditorCannotCreateUsers(): void
    {
        require_once $this->root . '/ap-admin/includes/class-ap-admin-user-edit.php';
        $editorId = $this->createUser('editcreate', 'editor');
        $nonce = ap_create_nonce('create-user', $editorId);
        $result = \AP_Admin_User_Edit::save([
            'user_login' => 'blockeduser',
            'user_email' => 'blocked@example.test',
            'pass1' => 'password123',
            'pass2' => 'password123',
            'role' => 'subscriber',
            '_ap_nonce' => $nonce,
        ], $editorId, 'create', $this->db);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('permission', strtolower(implode(' ', $result['errors'])));
    }

    public function testEditorCannotDeleteUsersViaBulk(): void
    {
        require_once $this->root . '/ap-admin/includes/class-ap-users-list-table.php';
        $editorId = $this->createUser('editdel', 'editor');
        $victimId = $this->createUser('victim1', 'subscriber');
        $table = new \AP_Users_List_Table($this->db);
        $nonce = ap_create_nonce('bulk-users', $editorId);
        $result = $table->processBulkAction([
            'action' => 'delete',
            'users' => [$victimId],
            '_ap_nonce' => $nonce,
        ], $editorId);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('permission', strtolower(implode(' ', $result['errors'])));
    }

    public function testEditorCannotManageOptionsOrThemeOptions(): void
    {
        $editorId = $this->createUser('editopts', 'editor');
        $this->assertFalse(AP_Admin::userCan($editorId, 'manage_options', null, $this->db));
        $this->assertFalse(AP_Admin::userCan($editorId, 'edit_theme_options', null, $this->db));
        $this->assertFalse(AP_Admin::userCan($editorId, 'list_users', null, $this->db));
        $this->assertFalse(AP_Admin::userCan($editorId, 'create_users', null, $this->db));
        $this->assertTrue(AP_Admin::userCan($editorId, 'edit_posts', null, $this->db));
        $this->assertTrue(AP_Admin::userCan($editorId, 'edit_pages', null, $this->db));
        $this->assertTrue(AP_Admin::userCan($editorId, 'moderate_comments', null, $this->db));
    }
}
