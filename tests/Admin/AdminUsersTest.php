<?php

/**
 * Tests for admin users list table and create/edit/profile save logic.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Admin;

use AP_Admin;
use AP_Admin_User_Edit;
use AP_DB;
use AP_Migrator;
use AP_Nonce;
use AP_Options;
use AP_Roles;
use AP_User;
use AP_Users_List_Table;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_Users_List_Table::class)]
#[CoversClass(AP_Admin_User_Edit::class)]
#[CoversClass(AP_User::class)]
final class AdminUsersTest extends TestCase
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
        require_once $this->root . '/ap-includes/functions.php';
        require_once $this->root . '/ap-admin/includes/class-ap-admin.php';
        require_once $this->root . '/ap-admin/includes/class-ap-users-list-table.php';
        require_once $this->root . '/ap-admin/includes/class-ap-admin-user-edit.php';

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
    }

    protected function tearDown(): void
    {
        AP_Roles::flushCache();
        AP_Options::flushCache();
        AP_Admin::clearNotices();
    }

    public function testCreateUpdateDeleteUserRoundTrip(): void
    {
        $created = AP_User::create([
            'user_login' => 'alice',
            'user_email' => 'alice@example.test',
            'password' => 'secret-pass-99',
            'display_name' => 'Alice A',
            'role' => 'author',
            'first_name' => 'Alice',
            'last_name' => 'Author',
            'nickname' => 'ally',
            'description' => 'Writes things.',
        ], $this->db);

        $this->assertTrue($created['ok'], implode('; ', $created['errors']));
        $this->assertGreaterThan(0, $created['id']);
        $this->assertInstanceOf(AP_User::class, $created['user']);
        $this->assertSame('alice', $created['user']->user_login);
        $this->assertSame('author', AP_Roles::getUserRole($created['id'], $this->db));

        $meta = AP_User::getProfileMeta($created['id'], $this->db);
        $this->assertSame('Alice', $meta['first_name']);
        $this->assertSame('Author', $meta['last_name']);
        $this->assertSame('ally', $meta['nickname']);
        $this->assertSame('Writes things.', $meta['description']);

        $updated = AP_User::update($created['id'], [
            'user_email' => 'alice2@example.test',
            'display_name' => 'Alice Updated',
            'role' => 'editor',
            'first_name' => 'Alicia',
        ], $this->db);
        $this->assertTrue($updated['ok'], implode('; ', $updated['errors']));
        $this->assertSame('alice2@example.test', $updated['user']->user_email);
        $this->assertSame('Alice Updated', $updated['user']->display_name);
        $this->assertSame('editor', AP_Roles::getUserRole($created['id'], $this->db));
        $this->assertSame('Alicia', AP_User::getMeta($created['id'], 'first_name', $this->db));

        $this->assertTrue(AP_User::delete($created['id'], $this->db));
        $this->assertNull(AP_User::getById($created['id'], $this->db));
        $this->assertNull(AP_User::getMeta($created['id'], 'first_name', $this->db));
    }

    public function testCreateRejectsDuplicateLoginAndEmail(): void
    {
        AP_User::create([
            'user_login' => 'bob',
            'user_email' => 'bob@example.test',
            'password' => 'password123',
        ], $this->db);

        $dupLogin = AP_User::create([
            'user_login' => 'bob',
            'user_email' => 'other@example.test',
            'password' => 'password123',
        ], $this->db);
        $this->assertFalse($dupLogin['ok']);
        $this->assertNotEmpty($dupLogin['errors']);

        $dupEmail = AP_User::create([
            'user_login' => 'bobby',
            'user_email' => 'bob@example.test',
            'password' => 'password123',
        ], $this->db);
        $this->assertFalse($dupEmail['ok']);
    }

    public function testQuerySearchAndRoleFilter(): void
    {
        AP_User::create([
            'user_login' => 'admin1',
            'user_email' => 'a1@example.test',
            'password' => 'password123',
            'role' => 'administrator',
        ], $this->db);
        AP_User::create([
            'user_login' => 'editor1',
            'user_email' => 'e1@example.test',
            'password' => 'password123',
            'role' => 'editor',
            'display_name' => 'Ed Itor',
        ], $this->db);
        AP_User::create([
            'user_login' => 'sub1',
            'user_email' => 's1@example.test',
            'password' => 'password123',
            'role' => 'subscriber',
        ], $this->db);

        $editors = AP_User::query(['role' => 'editor', 'number' => 0], $this->db);
        $this->assertCount(1, $editors);
        $this->assertSame('editor1', $editors[0]->user_login);

        $found = AP_User::query(['search' => 'Ed Itor', 'number' => 0], $this->db);
        $this->assertCount(1, $found);
        $this->assertSame('editor1', $found[0]->user_login);

        $this->assertSame(3, AP_User::count([], $this->db));
        $this->assertSame(1, AP_User::count(['role' => 'subscriber'], $this->db));

        $byRole = AP_User::countByRole($this->db);
        $this->assertSame(1, $byRole['administrator'] ?? 0);
        $this->assertSame(1, $byRole['editor'] ?? 0);
        $this->assertSame(1, $byRole['subscriber'] ?? 0);
    }

    public function testIsLastAdministrator(): void
    {
        $a = AP_User::create([
            'user_login' => 'onlyadmin',
            'user_email' => 'only@example.test',
            'password' => 'password123',
            'role' => 'administrator',
        ], $this->db);
        $this->assertTrue(AP_User::isLastAdministrator($a['id'], $this->db));

        $b = AP_User::create([
            'user_login' => 'secondadmin',
            'user_email' => 'second@example.test',
            'password' => 'password123',
            'role' => 'administrator',
        ], $this->db);
        $this->assertFalse(AP_User::isLastAdministrator($a['id'], $this->db));
        $this->assertFalse(AP_User::isLastAdministrator($b['id'], $this->db));

        $editor = AP_User::create([
            'user_login' => 'ed',
            'user_email' => 'ed@example.test',
            'password' => 'password123',
            'role' => 'editor',
        ], $this->db);
        $this->assertFalse(AP_User::isLastAdministrator($editor['id'], $this->db));
    }

    public function testAdminCreateAndUpdateViaFormSave(): void
    {
        $actor = AP_User::create([
            'user_login' => 'super',
            'user_email' => 'super@example.test',
            'password' => 'password123',
            'role' => 'administrator',
        ], $this->db);
        $actorId = $actor['id'];

        $nonce = ap_create_nonce('create-user', $actorId);
        $result = AP_Admin_User_Edit::save([
            '_ap_nonce' => $nonce,
            'user_login' => 'newbie',
            'user_email' => 'newbie@example.test',
            'pass1' => 'secure-pass-1',
            'pass2' => 'secure-pass-1',
            'display_name' => 'Newbie',
            'role' => 'author',
            'first_name' => 'New',
            'last_name' => 'Bee',
        ], $actorId, 'create', $this->db);

        $this->assertTrue($result['ok'], implode('; ', $result['errors']));
        $this->assertSame('user_created', $result['message_key']);
        $this->assertSame('author', AP_Roles::getUserRole($result['id'], $this->db));

        $updateNonce = ap_create_nonce('update-user-' . $result['id'], $actorId);
        $updated = AP_Admin_User_Edit::save([
            '_ap_nonce' => $updateNonce,
            'user_ID' => $result['id'],
            'user_email' => 'newbie2@example.test',
            'pass1' => '',
            'pass2' => '',
            'display_name' => 'Newbie Two',
            'role' => 'editor',
            'first_name' => 'New',
            'last_name' => 'Bee',
            'nickname' => 'nb',
            'description' => 'Hi',
            'user_url' => 'https://example.test',
        ], $actorId, 'update', $this->db);

        $this->assertTrue($updated['ok'], implode('; ', $updated['errors']));
        $this->assertSame('user_updated', $updated['message_key']);
        $this->assertSame('newbie2@example.test', $updated['user']->user_email);
        $this->assertSame('editor', AP_Roles::getUserRole($result['id'], $this->db));
        $this->assertSame('Hi', AP_User::getMeta($result['id'], 'description', $this->db));
    }

    public function testPasswordMismatchRejected(): void
    {
        $actor = AP_User::create([
            'user_login' => 'adminx',
            'user_email' => 'adminx@example.test',
            'password' => 'password123',
            'role' => 'administrator',
        ], $this->db);

        $nonce = ap_create_nonce('create-user', $actor['id']);
        $result = AP_Admin_User_Edit::save([
            '_ap_nonce' => $nonce,
            'user_login' => 'mismatch',
            'user_email' => 'mm@example.test',
            'pass1' => 'secure-pass-1',
            'pass2' => 'secure-pass-2',
            'role' => 'subscriber',
        ], $actor['id'], 'create', $this->db);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('match', strtolower(implode(' ', $result['errors'])));
    }

    public function testProfileSaveDoesNotChangeRole(): void
    {
        $user = AP_User::create([
            'user_login' => 'selfuser',
            'user_email' => 'self@example.test',
            'password' => 'password123',
            'role' => 'author',
        ], $this->db);
        $id = $user['id'];

        $nonce = ap_create_nonce('update-profile-' . $id, $id);
        $result = AP_Admin_User_Edit::save([
            '_ap_nonce' => $nonce,
            'user_ID' => $id,
            'user_email' => 'self2@example.test',
            'pass1' => '',
            'pass2' => '',
            'display_name' => 'Self',
            'role' => 'administrator', // should be ignored in profile mode
            'first_name' => 'Self',
            'last_name' => 'User',
            'nickname' => 'self',
            'description' => '',
            'user_url' => '',
        ], $id, 'profile', $this->db);

        $this->assertTrue($result['ok'], implode('; ', $result['errors']));
        $this->assertSame('profile_updated', $result['message_key']);
        $this->assertSame('author', AP_Roles::getUserRole($id, $this->db));
        $this->assertSame('self2@example.test', $result['user']->user_email);
    }

    public function testListTablePrepareAndRender(): void
    {
        AP_User::create([
            'user_login' => 'listme',
            'user_email' => 'list@example.test',
            'password' => 'password123',
            'role' => 'subscriber',
            'display_name' => 'List Me',
        ], $this->db);

        $table = new AP_Users_List_Table($this->db);
        $table->prepareItems([]);
        $this->assertGreaterThanOrEqual(1, $table->totalItems);
        $this->assertNotEmpty($table->items);

        $html = $table->render();
        $this->assertStringContainsString('listme', $html);
        $this->assertStringContainsString('list@example.test', $html);
        $this->assertStringContainsString('ap-list-table', $html);

        $views = $table->renderViews();
        $this->assertStringContainsString('All', $views);
        $this->assertStringContainsString('Subscriber', $views);

        $cols = $table->getColumns();
        $this->assertArrayHasKey('username', $cols);
        $this->assertArrayHasKey('role', $cols);
    }

    public function testBulkDeleteAndRoleChange(): void
    {
        $admin = AP_User::create([
            'user_login' => 'bulkadmin',
            'user_email' => 'bulkadmin@example.test',
            'password' => 'password123',
            'role' => 'administrator',
        ], $this->db);
        $u1 = AP_User::create([
            'user_login' => 'bulk1',
            'user_email' => 'bulk1@example.test',
            'password' => 'password123',
            'role' => 'subscriber',
        ], $this->db);
        $u2 = AP_User::create([
            'user_login' => 'bulk2',
            'user_email' => 'bulk2@example.test',
            'password' => 'password123',
            'role' => 'subscriber',
        ], $this->db);

        $table = new AP_Users_List_Table($this->db);
        $nonce = ap_create_nonce('bulk-users', $admin['id']);

        $roleResult = $table->processBulkAction([
            '_ap_nonce' => $nonce,
            'action' => 'change_role',
            'new_role' => 'author',
            'users' => [$u1['id'], $u2['id']],
        ], $admin['id']);
        $this->assertTrue($roleResult['ok'], implode('; ', $roleResult['errors']));
        $this->assertSame(2, $roleResult['count']);
        $this->assertSame('author', AP_Roles::getUserRole($u1['id'], $this->db));

        $delNonce = ap_create_nonce('bulk-users', $admin['id']);
        $delResult = $table->processBulkAction([
            '_ap_nonce' => $delNonce,
            'action' => 'delete',
            'users' => [$u1['id'], $u2['id']],
        ], $admin['id']);
        $this->assertTrue($delResult['ok'], implode('; ', $delResult['errors']));
        $this->assertSame(2, $delResult['count']);
        $this->assertNull(AP_User::getById($u1['id'], $this->db));
    }

    public function testCannotDeleteLastAdministrator(): void
    {
        $admin = AP_User::create([
            'user_login' => 'lastadmin',
            'user_email' => 'lastadmin@example.test',
            'password' => 'password123',
            'role' => 'administrator',
        ], $this->db);
        $helper = AP_User::create([
            'user_login' => 'helperadmin',
            'user_email' => 'helper@example.test',
            'password' => 'password123',
            'role' => 'administrator',
        ], $this->db);

        // With two admins, deleting one succeeds; then last admin is protected.
        $table = new AP_Users_List_Table($this->db);
        $nonce = ap_create_nonce('delete-user-' . $helper['id'], $admin['id']);
        $ok = $table->processRowAction([
            'action' => 'delete',
            'user' => $helper['id'],
            '_ap_nonce' => $nonce,
        ], $admin['id']);
        $this->assertTrue($ok['ok'], implode('; ', $ok['errors']));

        $nonce2 = ap_create_nonce('delete-user-' . $admin['id'], $admin['id']);
        // Cannot delete self.
        $self = $table->processRowAction([
            'action' => 'delete',
            'user' => $admin['id'],
            '_ap_nonce' => $nonce2,
        ], $admin['id']);
        $this->assertFalse($self['ok']);

        // Create an editor who somehow tries — still last admin after re-promote check via bulk.
        $editor = AP_User::create([
            'user_login' => 'deleter',
            'user_email' => 'deleter@example.test',
            'password' => 'password123',
            'role' => 'administrator',
        ], $this->db);
        // Now two admins again; demote editor to make admin last, then try delete from editor account after re-granting delete via still being admin.
        AP_Roles::setUserRole($editor['id'], 'administrator', $this->db);
        // Leave only $admin as admin:
        AP_Roles::setUserRole($editor['id'], 'editor', $this->db);
        // Editor lacks delete_users.
        $nonce3 = ap_create_nonce('delete-user-' . $admin['id'], $editor['id']);
        $denied = $table->processRowAction([
            'action' => 'delete',
            'user' => $admin['id'],
            '_ap_nonce' => $nonce3,
        ], $editor['id']);
        $this->assertFalse($denied['ok']);
        $this->assertNotNull(AP_User::getById($admin['id'], $this->db));
        $this->assertTrue(AP_User::isLastAdministrator($admin['id'], $this->db));
    }

    public function testMenuIncludesUsersAndProfile(): void
    {
        $items = AP_Admin::menuItems('');
        $ids = array_column($items, 'id');
        $this->assertContains('users', $ids);
        $this->assertContains('profile', $ids);
    }

    public function testConsumeQueryNoticeForUsers(): void
    {
        $_GET['message'] = 'user_created';
        AP_Admin::consumeQueryNotice();
        $notices = AP_Admin::getNotices();
        $this->assertNotEmpty($notices);
        $this->assertStringContainsString('User created', $notices[0]['message']);
        unset($_GET['message']);
        AP_Admin::clearNotices();
    }

    public function testRenderFormContainsExpectedFields(): void
    {
        $admin = AP_User::create([
            'user_login' => 'formadmin',
            'user_email' => 'formadmin@example.test',
            'password' => 'password123',
            'role' => 'administrator',
        ], $this->db);
        $html = AP_Admin_User_Edit::renderForm(null, 'create', $admin['id'], [], $this->db);
        $this->assertStringContainsString('name="user_login"', $html);
        $this->assertStringContainsString('name="user_email"', $html);
        $this->assertStringContainsString('name="pass1"', $html);
        $this->assertStringContainsString('name="role"', $html);
        $this->assertStringContainsString('name="description"', $html);
        $this->assertStringContainsString('Add New User', $html);
    }

    public function testProceduralUserHelpers(): void
    {
        $this->assertTrue(function_exists('ap_create_user'));
        $this->assertTrue(function_exists('ap_update_user'));
        $this->assertTrue(function_exists('ap_delete_user'));
        $this->assertTrue(function_exists('ap_get_users'));
        $this->assertTrue(function_exists('ap_count_users'));
        $this->assertTrue(function_exists('ap_get_user_meta'));
        $this->assertTrue(function_exists('ap_update_user_meta'));
        $this->assertTrue(function_exists('ap_generate_password'));

        $pass = ap_generate_password(12);
        $this->assertSame(12, strlen($pass));

        $created = ap_create_user([
            'user_login' => 'procuser',
            'user_email' => 'proc@example.test',
            'password' => 'password123',
            'role' => 'subscriber',
        ], $this->db);
        $this->assertTrue($created['ok']);
        $this->assertSame(1, ap_count_users(['search' => 'procuser'], $this->db));
        ap_update_user_meta($created['id'], 'nickname', 'proc', $this->db);
        $this->assertSame('proc', ap_get_user_meta($created['id'], 'nickname', $this->db));
        $this->assertTrue(ap_delete_user($created['id'], $this->db));
    }
}
