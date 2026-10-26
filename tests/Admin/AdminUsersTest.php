<?php

/**
 * Tests for admin users list table and create/edit/profile save logic.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Admin;

use AP_Activity_Log;
use AP_Admin;
use AP_Admin_User_Edit;
use AP_DB;
use AP_Forum;
use AP_Forum_Notify;
use AP_Mail;
use AP_Migrator;
use AP_Nonce;
use AP_Options;
use AP_Rate_Limit;
use AP_Registration;
use AP_Roles;
use AP_Session;
use AP_Transient;
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
        require_once $this->root . '/ap-includes/class-ap-transient.php';
        require_once $this->root . '/ap-includes/class-ap-rate-limit.php';
        require_once $this->root . '/ap-includes/class-ap-user.php';
        require_once $this->root . '/ap-includes/class-ap-session.php';
        require_once $this->root . '/ap-includes/class-ap-roles.php';
        require_once $this->root . '/ap-includes/class-ap-nonce.php';
        require_once $this->root . '/ap-includes/class-ap-post.php';
        require_once $this->root . '/ap-includes/class-ap-mail.php';
        require_once $this->root . '/ap-includes/class-ap-registration.php';
        require_once $this->root . '/ap-includes/class-ap-forum.php';
        require_once $this->root . '/ap-includes/class-ap-forum-notify.php';
        require_once $this->root . '/ap-includes/functions.php';
        require_once $this->root . '/ap-admin/includes/class-ap-admin.php';
        require_once $this->root . '/ap-admin/includes/class-ap-users-list-table.php';
        require_once $this->root . '/ap-admin/includes/class-ap-admin-user-edit.php';

        if (!defined('AP_AUTH_KEY')) {
            define('AP_AUTH_KEY', 'test-auth-key-' . str_repeat('c', 32));
        }
        if (!defined('AP_AUTH_SALT')) {
            define('AP_AUTH_SALT', 'test-auth-salt-' . str_repeat('d', 32));
        }
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
        AP_Mail::resetForTests();

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
        if (function_exists('ap_reset_hooks')) {
            ap_reset_hooks();
        }
        AP_Roles::flushCache();
        AP_Options::flushCache();
        AP_Admin::clearNotices();
        AP_Mail::resetForTests();
        if (class_exists('AP_Rate_Limit', false)) {
            AP_Rate_Limit::resetTestState();
        }
        unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_X_REAL_IP']);
        if (class_exists('AP_Session', false)) {
            AP_Session::resetCurrentUser();
            AP_Session::disableTestMode();
        }
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
            'location' => 'Athens',
            'signature' => 'Forum sig α',
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
        $this->assertSame('Athens', $meta['location']);
        $this->assertSame('Forum sig α', $meta['signature']);

        $updated = AP_User::update($created['id'], [
            'user_email' => 'alice2@example.test',
            'display_name' => 'Alice Updated',
            'role' => 'editor',
            'first_name' => 'Alicia',
            'location' => 'Sparta',
            'signature' => 'Updated sig',
        ], $this->db);
        $this->assertTrue($updated['ok'], implode('; ', $updated['errors']));
        $this->assertSame('alice2@example.test', $updated['user']->user_email);
        $this->assertSame('Alice Updated', $updated['user']->display_name);
        $this->assertSame('editor', AP_Roles::getUserRole($created['id'], $this->db));
        $this->assertSame('Alicia', AP_User::getMeta($created['id'], 'first_name', $this->db));
        $this->assertSame('Sparta', AP_User::getMeta($created['id'], 'location', $this->db));
        $this->assertSame('Updated sig', AP_User::getMeta($created['id'], 'signature', $this->db));

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

    public function testAdminCreateAllowsReservedLogin(): void
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
            'user_login' => 'admin',
            'user_email' => 'reserved-admin@example.test',
            'pass1' => 'secure-pass-1',
            'pass2' => 'secure-pass-1',
            'role' => 'administrator',
        ], $actorId, 'create', $this->db);

        $this->assertTrue($result['ok'], implode('; ', $result['errors']));
        $this->assertSame('admin', $result['user']->user_login ?? '');
        $this->assertTrue(AP_Registration::isReservedLogin('admin', $this->db));

        $dupNonce = ap_create_nonce('create-user', $actorId);
        $dup = AP_Admin_User_Edit::save([
            '_ap_nonce' => $dupNonce,
            'user_login' => 'Admin',
            'user_email' => 'reserved-admin-case@example.test',
            'pass1' => 'secure-pass-1',
            'pass2' => 'secure-pass-1',
            'role' => 'administrator',
        ], $actorId, 'create', $this->db);

        $this->assertFalse($dup['ok']);
        $this->assertContains('That username is already registered.', $dup['errors']);
        $this->assertNull(AP_User::getByEmail('reserved-admin-case@example.test', $this->db));
        $this->assertSame('admin', AP_User::getByLogin('Admin', $this->db)?->user_login);
        $this->assertSame($result['id'], AP_User::getByLogin('ADMIN', $this->db)?->ID);
    }

    public function testAdminCreateAllowsExtraReservedLogin(): void
    {
        AP_Options::update('reserved_usernames', "news\nBoard", $this->db);

        $actor = AP_User::create([
            'user_login' => 'super2',
            'user_email' => 'super2@example.test',
            'password' => 'password123',
            'role' => 'administrator',
        ], $this->db);
        $actorId = $actor['id'];

        $this->assertTrue(AP_Registration::isReservedLogin('Board', $this->db));

        $nonce = ap_create_nonce('create-user', $actorId);
        $result = AP_Admin_User_Edit::save([
            '_ap_nonce' => $nonce,
            'user_login' => 'Board',
            'user_email' => 'reserved-board@example.test',
            'pass1' => 'secure-pass-1',
            'pass2' => 'secure-pass-1',
            'role' => 'author',
        ], $actorId, 'create', $this->db);

        $this->assertTrue($result['ok'], implode('; ', $result['errors']));
        $this->assertSame('Board', $result['user']->user_login ?? '');
    }

    public function testAdminCreateAllowsFilterReservedLogin(): void
    {
        require_once $this->root . '/ap-includes/hooks.php';
        if (function_exists('ap_reset_hooks')) {
            ap_reset_hooks();
        }
        ap_add_filter(
            'ap_reserved_usernames',
            static function (mixed $names): array {
                $list = is_array($names) ? $names : [];
                $list[] = 'herald';

                return $list;
            }
        );

        $this->assertTrue(AP_Registration::isReservedLogin('herald', $this->db));

        $actor = AP_User::create([
            'user_login' => 'super3',
            'user_email' => 'super3@example.test',
            'password' => 'password123',
            'role' => 'administrator',
        ], $this->db);
        $actorId = $actor['id'];

        $nonce = ap_create_nonce('create-user', $actorId);
        $result = AP_Admin_User_Edit::save([
            '_ap_nonce' => $nonce,
            'user_login' => 'herald',
            'user_email' => 'reserved-herald@example.test',
            'pass1' => 'secure-pass-1',
            'pass2' => 'secure-pass-1',
            'role' => 'author',
        ], $actorId, 'create', $this->db);

        $this->assertTrue($result['ok'], implode('; ', $result['errors']));
        $this->assertSame('herald', $result['user']->user_login ?? '');

        if (function_exists('ap_reset_hooks')) {
            ap_reset_hooks();
        }
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
            'user_email' => 'self@example.test',
            'pass1' => '',
            'pass2' => '',
            'display_name' => 'Self',
            'role' => 'administrator', // should be ignored in profile mode
            'first_name' => 'Self',
            'last_name' => 'User',
            'nickname' => 'self',
            'description' => '',
            'location' => '',
            'signature' => 'Self forum signature',
            'user_url' => '',
        ], $id, 'profile', $this->db);

        $this->assertTrue($result['ok'], implode('; ', $result['errors']));
        $this->assertSame('profile_updated', $result['message_key']);
        $this->assertSame('author', AP_Roles::getUserRole($id, $this->db));
        $this->assertSame('self@example.test', $result['user']->user_email);
        $this->assertSame('Self', $result['user']->display_name);
        $this->assertSame('Self forum signature', AP_User::getMeta($id, 'signature', $this->db));
    }

    public function testProfileNonSensitiveFieldsSaveWithoutCurrentPassword(): void
    {
        $user = AP_User::create([
            'user_login' => 'plainprof',
            'user_email' => 'plainprof@example.test',
            'password' => 'password123',
            'role' => 'subscriber',
            'display_name' => 'Before',
        ], $this->db);
        $this->assertTrue($user['ok'], implode('; ', $user['errors']));
        $id = (int) $user['id'];

        $nonce = ap_create_nonce('update-profile-' . $id, $id);
        $result = AP_Admin_User_Edit::save([
            '_ap_nonce' => $nonce,
            'user_ID' => $id,
            'user_email' => '  plainprof@example.test  ',
            'pass1' => '',
            'pass2' => '',
            'display_name' => 'After',
            'first_name' => 'Pat',
            'last_name' => 'Lee',
            'nickname' => 'pat',
            'description' => 'Short bio',
            'location' => 'Lisbon',
            'signature' => 'Signed',
            'user_url' => 'https://example.com/pat',
        ], $id, 'profile', $this->db);

        $this->assertTrue($result['ok'], implode('; ', $result['errors']));
        $this->assertSame('profile_updated', $result['message_key']);
        $this->assertSame([], $result['errors']);
        $this->assertNotNull($result['user']);
        $this->assertSame('plainprof@example.test', $result['user']->user_email);
        $this->assertSame('After', $result['user']->display_name);
        $this->assertSame('https://example.com/pat', $result['user']->user_url);
        $this->assertSame('Pat', AP_User::getMeta($id, 'first_name', $this->db));
        $this->assertSame('Lee', AP_User::getMeta($id, 'last_name', $this->db));
        $this->assertSame('pat', AP_User::getMeta($id, 'nickname', $this->db));
        $this->assertSame('Short bio', AP_User::getMeta($id, 'description', $this->db));
        $this->assertSame('Lisbon', AP_User::getMeta($id, 'location', $this->db));
        $this->assertSame('Signed', AP_User::getMeta($id, 'signature', $this->db));
        $this->assertTrue(AP_User::checkPassword('password123', $result['user']->user_pass));

        // A wrong current password is ignored when email and password stay the same.
        $ignored = AP_Admin_User_Edit::save([
            '_ap_nonce' => ap_create_nonce('update-profile-' . $id, $id),
            'user_ID' => $id,
            'user_email' => 'plainprof@example.test',
            'pass1' => '',
            'pass2' => '',
            'display_name' => 'After Again',
            'description' => 'Bio still saved',
            'current_password' => 'not-the-real-password',
        ], $id, 'profile', $this->db);
        $this->assertTrue($ignored['ok'], implode('; ', $ignored['errors']));
        $this->assertSame([], $ignored['errors']);
        $this->assertNotNull($ignored['user']);
        $this->assertSame('After Again', $ignored['user']->display_name);
        $this->assertSame('plainprof@example.test', $ignored['user']->user_email);
        $this->assertSame('Bio still saved', AP_User::getMeta($id, 'description', $this->db));
        $this->assertTrue(AP_User::checkPassword('password123', $ignored['user']->user_pass));
        $this->assertStringNotContainsString('not-the-real-password', $this->encodeResult($ignored));
    }

    public function testProfileEmailAndPasswordChangeRequireCurrentPassword(): void
    {
        $user = AP_User::create([
            'user_login' => 'reauthprof',
            'user_email' => 'reauth@example.test',
            'password' => 'password123',
            'role' => 'author',
            'display_name' => 'Before',
        ], $this->db);
        $this->assertTrue($user['ok'], implode('; ', $user['errors']));
        $id = (int) $user['id'];

        $missingEmail = AP_Admin_User_Edit::save([
            '_ap_nonce' => ap_create_nonce('update-profile-' . $id, $id),
            'user_ID' => $id,
            'user_email' => 'reauth-new@example.test',
            'pass1' => '',
            'pass2' => '',
            'display_name' => 'Named',
            'description' => 'Bio saved anyway',
        ], $id, 'profile', $this->db);
        $this->assertFalse($missingEmail['ok']);
        $this->assertSame(
            ['Enter your current password to change your password or email.'],
            $missingEmail['errors']
        );
        $this->assertNotNull($missingEmail['user']);
        $this->assertSame('reauth@example.test', $missingEmail['user']->user_email);
        $this->assertSame('Named', $missingEmail['user']->display_name);
        $this->assertSame('Bio saved anyway', AP_User::getMeta($id, 'description', $this->db));
        $this->assertTrue(AP_User::checkPassword('password123', $missingEmail['user']->user_pass));

        $missingPass = AP_Admin_User_Edit::save([
            '_ap_nonce' => ap_create_nonce('update-profile-' . $id, $id),
            'user_ID' => $id,
            'user_email' => 'reauth@example.test',
            'pass1' => 'new-password-99',
            'pass2' => 'new-password-99',
            'display_name' => 'Named Again',
            'location' => 'Porto',
        ], $id, 'profile', $this->db);
        $this->assertFalse($missingPass['ok']);
        $this->assertSame(
            ['Enter your current password to change your password or email.'],
            $missingPass['errors']
        );
        $this->assertNotNull($missingPass['user']);
        $this->assertSame('Named Again', $missingPass['user']->display_name);
        $this->assertSame('Porto', AP_User::getMeta($id, 'location', $this->db));
        $this->assertTrue(AP_User::checkPassword('password123', $missingPass['user']->user_pass));
        $this->assertFalse(AP_User::checkPassword('new-password-99', $missingPass['user']->user_pass));

        $caseOnly = AP_Admin_User_Edit::save([
            '_ap_nonce' => ap_create_nonce('update-profile-' . $id, $id),
            'user_ID' => $id,
            'user_email' => 'Reauth@Example.test',
            'pass1' => '',
            'pass2' => '',
            'display_name' => 'Case Try',
        ], $id, 'profile', $this->db);
        $this->assertFalse($caseOnly['ok']);
        $this->assertNotNull($caseOnly['user']);
        $this->assertSame('reauth@example.test', $caseOnly['user']->user_email);
        $this->assertSame('Case Try', $caseOnly['user']->display_name);

        $wrong = 'wrong-current-secret';
        $wrongEmail = AP_Admin_User_Edit::save([
            '_ap_nonce' => ap_create_nonce('update-profile-' . $id, $id),
            'user_ID' => $id,
            'user_email' => 'taken-over@example.test',
            'pass1' => 'new-password-99',
            'pass2' => 'new-password-99',
            'display_name' => 'Still Here',
            'current_password' => $wrong,
        ], $id, 'profile', $this->db);
        $this->assertFalse($wrongEmail['ok']);
        $this->assertSame(['Your current password is incorrect.'], $wrongEmail['errors']);
        $this->assertNotNull($wrongEmail['user']);
        $this->assertSame('reauth@example.test', $wrongEmail['user']->user_email);
        $this->assertSame('Still Here', $wrongEmail['user']->display_name);
        $this->assertTrue(AP_User::checkPassword('password123', $wrongEmail['user']->user_pass));
        $encoded = json_encode($wrongEmail);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString($wrong, $encoded);
    }

    public function testCurrentPasswordNoticesSaveOtherFields(): void
    {
        $user = AP_User::create([
            'user_login' => 'noticeprof',
            'user_email' => 'notice@example.test',
            'password' => 'password123',
            'role' => 'author',
            'display_name' => 'Before',
        ], $this->db);
        $this->assertTrue($user['ok'], implode('; ', $user['errors']));
        $id = (int) $user['id'];
        $wrong = 'not-the-password';
        $short = 'short';

        $missing = AP_Admin_User_Edit::save([
            '_ap_nonce' => ap_create_nonce('update-profile-' . $id, $id),
            'user_ID' => $id,
            'user_email' => 'notice-new@example.test',
            'pass1' => 'new-password-99',
            'pass2' => 'new-password-98',
            'display_name' => 'Saved Name',
            'description' => 'Saved bio',
            'current_password' => '',
        ], $id, 'profile', $this->db);
        $this->assertFalse($missing['ok']);
        $this->assertSame(
            [AP_Admin_User_Edit::NOTICE_CURRENT_PASSWORD_REQUIRED],
            $missing['errors']
        );
        $this->assertNotNull($missing['user']);
        $this->assertSame('notice@example.test', $missing['user']->user_email);
        $this->assertSame('Saved Name', $missing['user']->display_name);
        $this->assertSame('Saved bio', AP_User::getMeta($id, 'description', $this->db));
        $this->assertTrue(AP_User::checkPassword('password123', $missing['user']->user_pass));
        $this->assertFalse(AP_User::checkPassword('new-password-99', $missing['user']->user_pass));
        $this->assertSame(
            'notice@example.test',
            AP_Admin_User_Edit::profileEmailAfterSave(
                $missing['errors'],
                'notice-new@example.test',
                $missing['user']->user_email
            )
        );

        $incorrect = AP_Admin_User_Edit::save([
            '_ap_nonce' => ap_create_nonce('update-profile-' . $id, $id),
            'user_ID' => $id,
            'user_email' => 'notice-taken@example.test',
            'pass1' => $short,
            'pass2' => $short,
            'display_name' => 'Saved Name',
            'location' => 'Coimbra',
            'current_password' => $wrong,
        ], $id, 'profile', $this->db);
        $this->assertFalse($incorrect['ok']);
        $this->assertSame(
            [AP_Admin_User_Edit::NOTICE_CURRENT_PASSWORD_INCORRECT],
            $incorrect['errors']
        );
        $this->assertNotNull($incorrect['user']);
        $this->assertSame('notice@example.test', $incorrect['user']->user_email);
        $this->assertSame('Coimbra', AP_User::getMeta($id, 'location', $this->db));
        $this->assertTrue(AP_User::checkPassword('password123', $incorrect['user']->user_pass));
        $this->assertStringNotContainsString($wrong, implode("\n", $incorrect['errors']));
        $this->assertStringNotContainsString($short, implode("\n", $incorrect['errors']));

        AP_Admin::clearNotices();
        AP_Admin_User_Edit::queueSaveErrors($missing['errors']);
        AP_Admin_User_Edit::queueSaveErrors($incorrect['errors']);
        $html = AP_Admin::renderNotices();
        $this->assertStringContainsString('ap-notice--error', $html);
        $this->assertStringContainsString('role="alert"', $html);
        $this->assertStringNotContainsString('ap-notice--success', $html);
        $this->assertStringContainsString(AP_Admin_User_Edit::NOTICE_CURRENT_PASSWORD_REQUIRED, $html);
        $this->assertStringContainsString(AP_Admin_User_Edit::NOTICE_CURRENT_PASSWORD_INCORRECT, $html);
        $this->assertStringNotContainsString($wrong, $html);
        $this->assertStringNotContainsString($short, $html);
        $this->assertStringNotContainsString('notice-new@example.test', $html);
        AP_Admin::clearNotices();

        $matchedButInvalid = AP_Admin_User_Edit::save([
            '_ap_nonce' => ap_create_nonce('update-profile-' . $id, $id),
            'user_ID' => $id,
            'user_email' => 'notice@example.test',
            'pass1' => 'new-password-99',
            'pass2' => 'different-password',
            'display_name' => 'Should Stay',
            'current_password' => 'password123',
        ], $id, 'profile', $this->db);
        $this->assertFalse($matchedButInvalid['ok']);
        $this->assertSame(['Passwords do not match.'], $matchedButInvalid['errors']);
        $this->assertNotNull($matchedButInvalid['user']);
        $this->assertSame('Saved Name', $matchedButInvalid['user']->display_name);
        $this->assertTrue(AP_User::checkPassword('password123', $matchedButInvalid['user']->user_pass));
        $this->assertSame(
            'notice-kept-on-other-errors@example.test',
            AP_Admin_User_Edit::profileEmailAfterSave(
                $matchedButInvalid['errors'],
                'notice-kept-on-other-errors@example.test',
                'notice@example.test'
            )
        );

        $profile = file_get_contents($this->root . '/ap-admin/profile.php');
        $this->assertIsString($profile);
        $this->assertStringContainsString("AP_Admin::requireCapability('read')", $profile);
        $this->assertStringContainsString('queueSaveErrors($result[\'errors\'])', $profile);
        $this->assertStringContainsString('profileEmailAfterSave(', $profile);
        $this->assertStringNotContainsString("extra['current_password']", $profile);
    }

    public function testProfileNonceAndEditCapabilityStayInPlace(): void
    {
        $owner = AP_User::create([
            'user_login' => 'nonceowner',
            'user_email' => 'nonceowner@example.test',
            'password' => 'password123',
            'role' => 'author',
            'display_name' => 'Owner',
        ], $this->db);
        $this->assertTrue($owner['ok'], implode('; ', $owner['errors']));
        $ownerId = (int) $owner['id'];

        $missingNonce = AP_Admin_User_Edit::save([
            'user_ID' => $ownerId,
            'user_email' => 'nonceowner-new@example.test',
            'pass1' => 'new-password-99',
            'pass2' => 'new-password-99',
            'display_name' => 'Hijack',
            'description' => 'Should not save',
            'current_password' => 'password123',
        ], $ownerId, 'profile', $this->db);
        $this->assertFalse($missingNonce['ok']);
        $this->assertSame('nonce', $missingNonce['message_key']);
        $this->assertSame(
            ['Security check failed. Please reload and try again.'],
            $missingNonce['errors']
        );
        $this->assertNotNull($missingNonce['user']);
        $this->assertSame('nonceowner@example.test', $missingNonce['user']->user_email);
        $this->assertSame('Owner', $missingNonce['user']->display_name);
        $this->assertSame('', (string) AP_User::getMeta($ownerId, 'description', $this->db));
        $this->assertTrue(AP_User::checkPassword('password123', $missingNonce['user']->user_pass));
        $this->assertFalse(AP_Admin_User_Edit::profileSensitiveChangeRejected($missingNonce['errors']));

        $stranger = AP_User::create([
            'user_login' => 'stranger',
            'user_email' => 'stranger@example.test',
            'password' => 'password123',
            'role' => 'subscriber',
            'display_name' => 'Stranger',
        ], $this->db);
        $this->assertTrue($stranger['ok'], implode('; ', $stranger['errors']));
        $strangerId = (int) $stranger['id'];

        $forbidden = AP_Admin_User_Edit::save([
            '_ap_nonce' => ap_create_nonce('update-user-' . $ownerId, $strangerId),
            'user_ID' => $ownerId,
            'user_email' => 'stranger-set@example.test',
            'pass1' => 'new-password-99',
            'pass2' => 'new-password-99',
            'display_name' => 'Taken',
            'current_password' => 'password123',
        ], $strangerId, 'update', $this->db);
        $this->assertFalse($forbidden['ok']);
        $this->assertSame(
            ['You do not have permission to edit this user.'],
            $forbidden['errors']
        );
        $fresh = AP_User::getById($ownerId, $this->db);
        $this->assertNotNull($fresh);
        $this->assertSame('Owner', $fresh->display_name);
        $this->assertSame('nonceowner@example.test', $fresh->user_email);
        $this->assertTrue(AP_User::checkPassword('password123', $fresh->user_pass));
    }

    public function testAdminEditOfAnotherUserSetsPasswordAndEmailWithoutCurrentPassword(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.77';
        AP_Rate_Limit::setTestLimits(AP_Rate_Limit::ACTION_CURRENT_PASSWORD, [
            'max' => 1,
            'window' => 900,
            'lockout' => 900,
        ]);

        $admin = AP_User::create([
            'user_login' => 'credadmin',
            'user_email' => 'credadmin@example.test',
            'password' => 'password123',
            'role' => 'administrator',
            'display_name' => 'Cred Admin',
        ], $this->db);
        $this->assertTrue($admin['ok'], implode('; ', $admin['errors']));
        $adminId = (int) $admin['id'];

        $target = AP_User::create([
            'user_login' => 'credtarget',
            'user_email' => 'credtarget@example.test',
            'password' => 'password123',
            'role' => 'subscriber',
            'display_name' => 'Cred Target',
        ], $this->db);
        $this->assertTrue($target['ok'], implode('; ', $target['errors']));
        $targetId = (int) $target['id'];

        $editor = AP_User::create([
            'user_login' => 'crededitor',
            'user_email' => 'crededitor@example.test',
            'password' => 'password123',
            'role' => 'editor',
        ], $this->db);
        $this->assertTrue($editor['ok'], implode('; ', $editor['errors']));
        $editorId = (int) $editor['id'];
        $this->assertFalse(ap_user_can($editorId, 'edit_users', null, $this->db));
        $this->assertTrue(ap_user_can($adminId, 'edit_users', null, $this->db));

        AP_Rate_Limit::recordFailedCurrentPassword($targetId, '203.0.113.77', $this->db);
        $locked = AP_Rate_Limit::checkCurrentPassword($targetId, '203.0.113.77', $this->db);
        $this->assertFalse($locked['allowed']);
        $attemptsBefore = $this->currentPasswordAttempts($targetId);

        $screen = file_get_contents($this->root . '/ap-admin/user-edit.php');
        $this->assertIsString($screen);
        $this->assertStringContainsString("AP_Admin::requireCapability('edit_users')", $screen);
        $this->assertStringContainsString("unset(\$_POST['current_password']", $screen);
        $this->assertStringContainsString(", 'update'", $screen);

        $targetUser = AP_User::getById($targetId, $this->db);
        $this->assertNotNull($targetUser);
        $html = AP_Admin_User_Edit::renderForm($targetUser, 'update', $adminId, [
            'current_password' => 'do-not-echo-this',
        ], $this->db);
        $this->assertStringContainsString('name="user_email"', $html);
        $this->assertStringContainsString('name="pass1"', $html);
        $this->assertStringContainsString('name="pass2"', $html);
        $this->assertStringNotContainsString('name="current_password"', $html);
        $this->assertStringNotContainsString('autocomplete="current-password"', $html);
        $this->assertStringNotContainsString('do-not-echo-this', $html);
        $this->assertStringContainsString('value="' . $targetId . '"', $html);

        $emailOnly = $this->saveOtherUser($adminId, $targetId, 'credtarget-new@example.test', [
            'display_name' => 'Renamed',
            'description' => 'Bio from admin',
        ]);
        $this->assertTrue($emailOnly['ok'], implode('; ', $emailOnly['errors']));
        $this->assertSame('user_updated', $emailOnly['message_key']);
        $this->assertSame([], $emailOnly['errors']);
        $this->assertNotNull($emailOnly['user']);
        $this->assertSame('credtarget-new@example.test', $emailOnly['user']->user_email);
        $this->assertSame('Renamed', $emailOnly['user']->display_name);
        $this->assertSame('Bio from admin', AP_User::getMeta($targetId, 'description', $this->db));
        $this->assertTrue(AP_User::checkPassword('password123', $emailOnly['user']->user_pass));
        $this->assertSame($attemptsBefore, $this->currentPasswordAttempts($targetId));

        $adminFresh = AP_User::getById($adminId, $this->db);
        $this->assertNotNull($adminFresh);
        $this->assertSame('credadmin@example.test', $adminFresh->user_email);
        $this->assertTrue(AP_User::checkPassword('password123', $adminFresh->user_pass));

        $newPassword = 'reset-password-1';
        $passwordOnly = $this->saveOtherUser($adminId, $targetId, 'credtarget-new@example.test', [
            'pass1' => $newPassword,
            'pass2' => $newPassword,
            'display_name' => 'Renamed',
        ]);
        $this->assertTrue($passwordOnly['ok'], implode('; ', $passwordOnly['errors']));
        $this->assertNotNull($passwordOnly['user']);
        $this->assertSame('credtarget-new@example.test', $passwordOnly['user']->user_email);
        $this->assertTrue(AP_User::checkPassword($newPassword, $passwordOnly['user']->user_pass));
        $this->assertFalse(AP_User::checkPassword('password123', $passwordOnly['user']->user_pass));
        $this->assertStringNotContainsString($newPassword, implode("\n", $passwordOnly['errors']));
        $this->assertSame($attemptsBefore, $this->currentPasswordAttempts($targetId));
        $this->assertFalse(AP_Rate_Limit::checkCurrentPassword($targetId, '203.0.113.77', $this->db)['allowed']);

        $crafted = 'not-the-target-password';
        $bothPassword = 'reset-password-2';
        $both = $this->saveOtherUser($adminId, $targetId, 'credtarget-both@example.test', [
            'pass1' => $bothPassword,
            'pass2' => $bothPassword,
            'display_name' => 'Both Saved',
            'current_password' => $crafted,
        ]);
        $this->assertTrue($both['ok'], implode('; ', $both['errors']));
        $this->assertSame([], $both['errors']);
        $this->assertNotNull($both['user']);
        $this->assertSame('credtarget-both@example.test', $both['user']->user_email);
        $this->assertSame('Both Saved', $both['user']->display_name);
        $this->assertTrue(AP_User::checkPassword($bothPassword, $both['user']->user_pass));
        $this->assertStringNotContainsString($crafted, $this->encodeResult($both));
        $this->assertStringNotContainsString($bothPassword, $this->encodeResult($both));
        $this->assertSame($attemptsBefore, $this->currentPasswordAttempts($targetId));

        $emptyCurrent = $this->saveOtherUser($adminId, $targetId, 'credtarget-empty@example.test', [
            'display_name' => 'Both Saved',
            'current_password' => '',
        ]);
        $this->assertTrue($emptyCurrent['ok'], implode('; ', $emptyCurrent['errors']));
        $this->assertNotNull($emptyCurrent['user']);
        $this->assertSame('credtarget-empty@example.test', $emptyCurrent['user']->user_email);
        $this->assertTrue(AP_User::checkPassword($bothPassword, $emptyCurrent['user']->user_pass));

        $missingNonce = AP_Admin_User_Edit::save([
            'user_ID' => $targetId,
            'user_email' => 'credtarget-hijack@example.test',
            'pass1' => 'other-password-9',
            'pass2' => 'other-password-9',
            'display_name' => 'Hijack',
            'current_password' => '',
        ], $adminId, 'update', $this->db);
        $this->assertFalse($missingNonce['ok']);
        $this->assertSame('nonce', $missingNonce['message_key']);
        $kept = AP_User::getById($targetId, $this->db);
        $this->assertNotNull($kept);
        $this->assertSame('credtarget-empty@example.test', $kept->user_email);
        $this->assertSame('Both Saved', $kept->display_name);
        $this->assertTrue(AP_User::checkPassword($bothPassword, $kept->user_pass));

        $forbidden = $this->saveOtherUser($editorId, $targetId, 'credtarget-editor@example.test', [
            'pass1' => 'other-password-8',
            'pass2' => 'other-password-8',
            'display_name' => 'Editor Takeover',
            'current_password' => $bothPassword,
        ]);
        $this->assertFalse($forbidden['ok']);
        $this->assertSame(
            ['You do not have permission to edit this user.'],
            $forbidden['errors']
        );
        $still = AP_User::getById($targetId, $this->db);
        $this->assertNotNull($still);
        $this->assertSame('credtarget-empty@example.test', $still->user_email);
        $this->assertSame('Both Saved', $still->display_name);
        $this->assertTrue(AP_User::checkPassword($bothPassword, $still->user_pass));
        $this->assertFalse(AP_User::checkPassword('other-password-8', $still->user_pass));

        $mismatch = $this->saveOtherUser($adminId, $targetId, 'credtarget-empty@example.test', [
            'pass1' => 'other-password-7',
            'pass2' => 'other-password-6',
            'display_name' => 'Mismatch Name',
        ]);
        $this->assertFalse($mismatch['ok']);
        $this->assertSame(['Passwords do not match.'], $mismatch['errors']);
        $afterMismatch = AP_User::getById($targetId, $this->db);
        $this->assertNotNull($afterMismatch);
        $this->assertSame('Both Saved', $afterMismatch->display_name);
        $this->assertTrue(AP_User::checkPassword($bothPassword, $afterMismatch->user_pass));
        $this->assertStringNotContainsString('other-password-7', implode("\n", $mismatch['errors']));
    }

    public function testAdminPasswordAndEmailChangesAreAuditedWithoutThePassword(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.50';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.20';

        $admin = AP_User::create([
            'user_login' => 'auditadmin',
            'user_email' => 'auditadmin@example.test',
            'password' => 'password123',
            'role' => 'administrator',
            'display_name' => 'Audit Admin',
        ], $this->db);
        $this->assertTrue($admin['ok'], implode('; ', $admin['errors']));
        $adminId = (int) $admin['id'];

        $target = AP_User::create([
            'user_login' => 'audittarget',
            'user_email' => 'audittarget@example.test',
            'password' => 'password123',
            'role' => 'subscriber',
            'display_name' => 'Audit Target',
        ], $this->db);
        $this->assertTrue($target['ok'], implode('; ', $target['errors']));
        $targetId = (int) $target['id'];

        $editor = AP_User::create([
            'user_login' => 'auditeditor',
            'user_email' => 'auditeditor@example.test',
            'password' => 'password123',
            'role' => 'editor',
        ], $this->db);
        $this->assertTrue($editor['ok'], implode('; ', $editor['errors']));
        $editorId = (int) $editor['id'];
        $this->assertFalse(ap_user_can($editorId, 'edit_users', null, $this->db));

        $renamed = $this->saveOtherUser($adminId, $targetId, 'audittarget@example.test', [
            'display_name' => 'Renamed Only',
        ]);
        $this->assertTrue($renamed['ok'], implode('; ', $renamed['errors']));
        $this->assertSame('Renamed Only', $renamed['user']->display_name);
        $this->assertSame([], $this->activityRows($targetId));
        $this->assertSame(0, $this->activityCount());

        $newEmail = 'audittarget-new@example.test';
        $emailOnly = $this->saveOtherUser($adminId, $targetId, $newEmail, [
            'display_name' => 'Renamed Only',
        ]);
        $this->assertTrue($emailOnly['ok'], implode('; ', $emailOnly['errors']));
        $this->assertSame($newEmail, $emailOnly['user']->user_email);
        $emailRows = $this->activityRows($targetId);
        $this->assertCount(1, $emailRows);
        $this->assertActivityRow($emailRows[0], $adminId, $targetId, AP_Activity_Log::FIELD_EMAIL, '203.0.113.50');
        $this->assertLogOmits($emailRows, [$newEmail, '198.51.100.20']);

        $secret = 'reset-password-1';
        $passwordOnly = $this->saveOtherUser($adminId, $targetId, $newEmail, [
            'pass1' => $secret,
            'pass2' => $secret,
            'display_name' => 'Renamed Only',
        ]);
        $this->assertTrue($passwordOnly['ok'], implode('; ', $passwordOnly['errors']));
        $this->assertTrue(AP_User::checkPassword($secret, $passwordOnly['user']->user_pass));
        $passwordRows = $this->activityRows($targetId);
        $this->assertCount(2, $passwordRows);
        $this->assertActivityRow(
            $passwordRows[1],
            $adminId,
            $targetId,
            AP_Activity_Log::FIELD_PASSWORD,
            '203.0.113.50'
        );
        $this->assertLogOmits($passwordRows, [$secret, $newEmail]);

        $bothSecret = 'reset-password-2';
        $bothEmail = 'audittarget-both@example.test';
        $crafted = 'not-the-target-password';
        $both = $this->saveOtherUser($adminId, $targetId, $bothEmail, [
            'pass1' => $bothSecret,
            'pass2' => $bothSecret,
            'display_name' => 'Both Saved',
            'current_password' => $crafted,
        ]);
        $this->assertTrue($both['ok'], implode('; ', $both['errors']));
        $this->assertTrue(AP_User::checkPassword($bothSecret, $both['user']->user_pass));
        $bothRows = $this->activityRows($targetId);
        $this->assertCount(4, $bothRows);
        $this->assertSame(AP_Activity_Log::FIELD_PASSWORD, (string) $bothRows[2]->field_name);
        $this->assertSame(AP_Activity_Log::FIELD_EMAIL, (string) $bothRows[3]->field_name);
        $this->assertLogOmits($bothRows, [$bothSecret, $crafted, $bothEmail, $secret]);

        $beforeReject = $this->activityCount();
        $mismatch = $this->saveOtherUser($adminId, $targetId, $bothEmail, [
            'pass1' => 'other-password-7',
            'pass2' => 'other-password-6',
        ]);
        $this->assertFalse($mismatch['ok']);
        $this->assertSame(['Passwords do not match.'], $mismatch['errors']);
        $short = $this->saveOtherUser($adminId, $targetId, $bothEmail, [
            'pass1' => 'short',
            'pass2' => 'short',
        ]);
        $this->assertFalse($short['ok']);
        $forbidden = $this->saveOtherUser($editorId, $targetId, 'audittarget-editor@example.test', [
            'pass1' => 'other-password-8',
            'pass2' => 'other-password-8',
            'current_password' => $bothSecret,
        ]);
        $this->assertFalse($forbidden['ok']);
        $this->assertSame(
            ['You do not have permission to edit this user.'],
            $forbidden['errors']
        );
        $missingNonce = AP_Admin_User_Edit::save([
            'user_ID' => $targetId,
            'user_email' => 'audittarget-hijack@example.test',
            'pass1' => 'other-password-9',
            'pass2' => 'other-password-9',
        ], $adminId, 'update', $this->db);
        $this->assertFalse($missingNonce['ok']);
        $this->assertSame('nonce', $missingNonce['message_key']);
        $this->assertSame($beforeReject, $this->activityCount());
        $kept = AP_User::getById($targetId, $this->db);
        $this->assertNotNull($kept);
        $this->assertSame($bothEmail, $kept->user_email);
        $this->assertTrue(AP_User::checkPassword($bothSecret, $kept->user_pass));
        $this->assertLogOmits($this->activityRows($targetId), [
            'other-password-7',
            'other-password-8',
            'other-password-9',
            'short',
            $bothSecret,
        ]);

        $selfMissing = AP_Admin_User_Edit::save([
            '_ap_nonce' => ap_create_nonce('update-user-' . $adminId, $adminId),
            'user_ID' => $adminId,
            'user_email' => 'auditadmin-new@example.test',
            'pass1' => 'self-password-1',
            'pass2' => 'self-password-1',
            'display_name' => 'Self Missing',
            'current_password' => '',
        ], $adminId, 'update', $this->db);
        $this->assertFalse($selfMissing['ok']);
        $this->assertSame(
            [AP_Admin_User_Edit::NOTICE_CURRENT_PASSWORD_REQUIRED],
            $selfMissing['errors']
        );
        $adminStill = AP_User::getById($adminId, $this->db);
        $this->assertNotNull($adminStill);
        $this->assertSame('auditadmin@example.test', $adminStill->user_email);
        $this->assertTrue(AP_User::checkPassword('password123', $adminStill->user_pass));
        $this->assertSame('Self Missing', $adminStill->display_name);

        $selfSecret = 'self-password-1';
        $selfOk = AP_Admin_User_Edit::save([
            '_ap_nonce' => ap_create_nonce('update-user-' . $adminId, $adminId),
            'user_ID' => $adminId,
            'user_email' => 'auditadmin-new@example.test',
            'pass1' => $selfSecret,
            'pass2' => $selfSecret,
            'display_name' => 'Self Saved',
            'current_password' => 'password123',
        ], $adminId, 'update', $this->db);
        $this->assertTrue($selfOk['ok'], implode('; ', $selfOk['errors']));
        $this->assertSame('auditadmin-new@example.test', $selfOk['user']->user_email);
        $this->assertTrue(AP_User::checkPassword($selfSecret, $selfOk['user']->user_pass));

        $profile = $this->saveProfile($adminId, 'auditadmin-profile@example.test', [
            'display_name' => 'Profile Saved',
            'current_password' => $selfSecret,
        ]);
        $this->assertTrue($profile['ok'], implode('; ', $profile['errors']));
        $this->assertSame('auditadmin-profile@example.test', $profile['user']->user_email);

        $this->assertSame([], $this->activityRows($adminId));
        $this->assertSame(4, $this->activityCount());
        $this->assertLogOmits($this->activityRows($targetId), [$selfSecret, 'password123']);
    }

    public function testUserEditOfOwnAccountRequiresCurrentPassword(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.90';
        AP_Rate_Limit::setTestLimits(AP_Rate_Limit::ACTION_CURRENT_PASSWORD, [
            'max' => 3,
            'window' => 900,
            'lockout' => 900,
        ]);

        $admin = AP_User::create([
            'user_login' => 'selfeditadmin',
            'user_email' => 'selfeditadmin@example.test',
            'password' => 'password123',
            'role' => 'administrator',
            'display_name' => 'Before',
        ], $this->db);
        $this->assertTrue($admin['ok'], implode('; ', $admin['errors']));
        $id = (int) $admin['id'];
        $this->assertTrue(ap_user_can($id, 'edit_users', null, $this->db));

        $other = AP_User::create([
            'user_login' => 'selfeditother',
            'user_email' => 'selfeditother@example.test',
            'password' => 'password123',
            'role' => 'subscriber',
            'display_name' => 'Other',
        ], $this->db);
        $this->assertTrue($other['ok'], implode('; ', $other['errors']));
        $otherId = (int) $other['id'];

        $editor = AP_User::create([
            'user_login' => 'selfediteditor',
            'user_email' => 'selfediteditor@example.test',
            'password' => 'password123',
            'role' => 'editor',
            'display_name' => 'Editor',
        ], $this->db);
        $this->assertTrue($editor['ok'], implode('; ', $editor['errors']));
        $editorId = (int) $editor['id'];
        $this->assertFalse(ap_user_can($editorId, 'edit_users', null, $this->db));

        $screen = file_get_contents($this->root . '/ap-admin/user-edit.php');
        $this->assertIsString($screen);
        $this->assertStringContainsString("AP_Admin::requireCapability('edit_users')", $screen);
        $this->assertStringContainsString("unset(\$_POST['current_password']", $screen);
        $this->assertStringContainsString('profileEmailAfterSave(', $screen);
        $this->assertStringNotContainsString("extra['current_password']", $screen);
        $this->assertStringContainsString(", 'update'", $screen);

        $selfUser = AP_User::getById($id, $this->db);
        $this->assertNotNull($selfUser);
        $html = AP_Admin_User_Edit::renderForm($selfUser, 'update', $id, [
            'current_password' => 'do-not-echo-this',
        ], $this->db);
        $this->assertStringContainsString('>Current password<', $html);
        $this->assertStringContainsString('name="current_password"', $html);
        $this->assertStringContainsString('id="current_password"', $html);
        $this->assertStringContainsString('autocomplete="current-password"', $html);
        $this->assertStringContainsString('name="user_email"', $html);
        $this->assertStringContainsString('name="pass1"', $html);
        $this->assertStringContainsString('name="pass2"', $html);
        $this->assertStringContainsString(
            'Enter your current password to change your password or email.',
            $html
        );
        $this->assertStringContainsString(
            'Changing your email requires your current password',
            $html
        );
        $this->assertStringNotContainsString('do-not-echo-this', $html);
        $this->assertDoesNotMatchRegularExpression(
            '/name="current_password"[^>]*value="/',
            $html
        );
        $this->assertSame(1, substr_count($html, 'name="current_password"'));
        $this->assertSame(1, substr_count($html, 'name="pass1"'));
        $emailPos = strpos($html, 'name="user_email"');
        $currentPos = strpos($html, 'name="current_password"');
        $passPos = strpos($html, 'name="pass1"');
        $nameLegend = strpos($html, '<legend>Name</legend>');
        $this->assertNotFalse($emailPos);
        $this->assertNotFalse($currentPos);
        $this->assertNotFalse($passPos);
        $this->assertNotFalse($nameLegend);
        $this->assertGreaterThan($emailPos, $currentPos);
        $this->assertLessThan($passPos, $currentPos);
        $this->assertLessThan($nameLegend, $currentPos);

        $otherUser = AP_User::getById($otherId, $this->db);
        $this->assertNotNull($otherUser);
        $otherHtml = AP_Admin_User_Edit::renderForm($otherUser, 'update', $id, [
            'current_password' => 'do-not-echo-this',
        ], $this->db);
        $this->assertStringContainsString('name="pass1"', $otherHtml);
        $this->assertStringNotContainsString('name="current_password"', $otherHtml);
        $this->assertStringNotContainsString('autocomplete="current-password"', $otherHtml);
        $this->assertStringNotContainsString('do-not-echo-this', $otherHtml);
        $this->assertStringNotContainsString(
            'Changing your email requires your current password',
            $otherHtml
        );

        $create = AP_Admin_User_Edit::renderForm(null, 'create', $id, [], $this->db);
        $this->assertStringNotContainsString('name="current_password"', $create);

        $otherSave = $this->saveOtherUser($id, $otherId, 'selfeditother-new@example.test', [
            'display_name' => 'Renamed Other',
        ]);
        $this->assertTrue($otherSave['ok'], implode('; ', $otherSave['errors']));
        $this->assertNotNull($otherSave['user']);
        $this->assertSame('selfeditother-new@example.test', $otherSave['user']->user_email);
        $this->assertSame('Renamed Other', $otherSave['user']->display_name);
        $this->assertTrue(AP_User::checkPassword('password123', $otherSave['user']->user_pass));
        $this->assertSame(0, $this->currentPasswordAttempts($otherId));

        $ignored = $this->saveOtherUser($id, $id, '  selfeditadmin@example.test  ', [
            'display_name' => 'After',
            'description' => 'Bio saved',
            'current_password' => 'not-the-real-password',
        ]);
        $this->assertTrue($ignored['ok'], implode('; ', $ignored['errors']));
        $this->assertSame([], $ignored['errors']);
        $this->assertNotNull($ignored['user']);
        $this->assertSame('selfeditadmin@example.test', $ignored['user']->user_email);
        $this->assertSame('After', $ignored['user']->display_name);
        $this->assertSame('Bio saved', AP_User::getMeta($id, 'description', $this->db));
        $this->assertTrue(AP_User::checkPassword('password123', $ignored['user']->user_pass));
        $this->assertSame(0, $this->currentPasswordAttempts($id));
        $this->assertStringNotContainsString('not-the-real-password', $this->encodeResult($ignored));
        $this->assertSame('administrator', AP_Roles::getUserRole($id, $this->db));

        $missingEmail = $this->saveOtherUser($id, $id, 'selfedit-new@example.test', [
            'display_name' => 'Named',
            'description' => 'Bio still',
        ]);
        $this->assertFalse($missingEmail['ok']);
        $this->assertSame(
            [AP_Admin_User_Edit::NOTICE_CURRENT_PASSWORD_REQUIRED],
            $missingEmail['errors']
        );
        $this->assertNotNull($missingEmail['user']);
        $this->assertSame('selfeditadmin@example.test', $missingEmail['user']->user_email);
        $this->assertSame('Named', $missingEmail['user']->display_name);
        $this->assertSame('Bio still', AP_User::getMeta($id, 'description', $this->db));
        $this->assertTrue(AP_User::checkPassword('password123', $missingEmail['user']->user_pass));
        $this->assertSame(1, $this->currentPasswordAttempts($id));
        $this->assertSame(
            'selfeditadmin@example.test',
            AP_Admin_User_Edit::profileEmailAfterSave(
                $missingEmail['errors'],
                'selfedit-new@example.test',
                $missingEmail['user']->user_email
            )
        );

        $_SERVER['REMOTE_ADDR'] = '203.0.113.91';
        $editorMissing = $this->saveOtherUser($editorId, $editorId, 'selfediteditor-new@example.test', [
            'display_name' => 'Editor Named',
        ]);
        $_SERVER['REMOTE_ADDR'] = '203.0.113.90';
        $this->assertFalse($editorMissing['ok']);
        $this->assertSame(
            [AP_Admin_User_Edit::NOTICE_CURRENT_PASSWORD_REQUIRED],
            $editorMissing['errors']
        );
        $this->assertNotNull($editorMissing['user']);
        $this->assertSame('selfediteditor@example.test', $editorMissing['user']->user_email);
        $this->assertSame('Editor Named', $editorMissing['user']->display_name);
        $this->assertTrue(AP_User::checkPassword('password123', $editorMissing['user']->user_pass));
        $this->assertSame(1, $this->currentPasswordAttempts($id));
        $this->assertSame(1, $this->currentPasswordAttempts($editorId));

        $wrong = 'wrong-current-secret';
        $short = 'short';
        $wrongPass = $this->saveOtherUser($id, $id, 'selfeditadmin@example.test', [
            'pass1' => $short,
            'pass2' => $short,
            'location' => 'Porto',
            'display_name' => 'Named',
            'current_password' => $wrong,
        ]);
        $this->assertFalse($wrongPass['ok']);
        $this->assertSame(
            [AP_Admin_User_Edit::NOTICE_CURRENT_PASSWORD_INCORRECT],
            $wrongPass['errors']
        );
        $this->assertNotNull($wrongPass['user']);
        $this->assertSame('selfeditadmin@example.test', $wrongPass['user']->user_email);
        $this->assertSame('Porto', AP_User::getMeta($id, 'location', $this->db));
        $this->assertTrue(AP_User::checkPassword('password123', $wrongPass['user']->user_pass));
        $this->assertFalse(AP_User::checkPassword($short, $wrongPass['user']->user_pass));
        $this->assertStringNotContainsString($wrong, implode("\n", $wrongPass['errors']));
        $this->assertStringNotContainsString($short, implode("\n", $wrongPass['errors']));
        $this->assertStringNotContainsString($wrong, $this->encodeResult($wrongPass));
        $this->assertSame(2, $this->currentPasswordAttempts($id));

        $mismatch = $this->saveOtherUser($id, $id, 'selfeditadmin@example.test', [
            'pass1' => 'new-password-99',
            'pass2' => 'different-password',
            'display_name' => 'Should Stay',
            'current_password' => 'password123',
        ]);
        $this->assertFalse($mismatch['ok']);
        $this->assertSame(['Passwords do not match.'], $mismatch['errors']);
        $this->assertNotNull($mismatch['user']);
        $this->assertSame('Named', $mismatch['user']->display_name);
        $this->assertTrue(AP_User::checkPassword('password123', $mismatch['user']->user_pass));
        $this->assertSame(0, $this->currentPasswordAttempts($id));
        $this->assertStringNotContainsString('new-password-99', implode("\n", $mismatch['errors']));
        $this->assertStringNotContainsString('password123', $this->encodeResult($mismatch));

        $email = $this->saveOtherUser($id, $id, 'selfedit-ok@example.test', [
            'display_name' => 'Ready',
            'current_password' => 'password123',
        ]);
        $this->assertTrue($email['ok'], implode('; ', $email['errors']));
        $this->assertSame('user_updated', $email['message_key']);
        $this->assertNotNull($email['user']);
        $this->assertSame('selfedit-ok@example.test', $email['user']->user_email);
        $this->assertSame('Ready', $email['user']->display_name);
        $this->assertTrue(AP_User::checkPassword('password123', $email['user']->user_pass));
        $this->assertStringNotContainsString('password123', $this->encodeResult($email));

        $newPassword = 'new-password-99';
        $password = $this->saveOtherUser($id, $id, 'selfedit-ok@example.test', [
            'pass1' => $newPassword,
            'pass2' => $newPassword,
            'display_name' => 'Ready',
            'current_password' => 'password123',
        ]);
        $this->assertTrue($password['ok'], implode('; ', $password['errors']));
        $this->assertNotNull($password['user']);
        $this->assertSame('selfedit-ok@example.test', $password['user']->user_email);
        $this->assertTrue(AP_User::checkPassword($newPassword, $password['user']->user_pass));
        $this->assertFalse(AP_User::checkPassword('password123', $password['user']->user_pass));
        $this->assertStringNotContainsString($newPassword, $this->encodeResult($password));
        $this->assertStringNotContainsString('password123', $this->encodeResult($password));
        $this->assertSame(0, $this->currentPasswordAttempts($id));

        $missingNonce = AP_Admin_User_Edit::save([
            'user_ID' => $id,
            'user_email' => 'selfedit-hijack@example.test',
            'pass1' => 'other-password-9',
            'pass2' => 'other-password-9',
            'display_name' => 'Hijack',
            'current_password' => $newPassword,
        ], $id, 'update', $this->db);
        $this->assertFalse($missingNonce['ok']);
        $this->assertSame('nonce', $missingNonce['message_key']);
        $this->assertSame(
            ['Security check failed. Please reload and try again.'],
            $missingNonce['errors']
        );
        $kept = AP_User::getById($id, $this->db);
        $this->assertNotNull($kept);
        $this->assertSame('selfedit-ok@example.test', $kept->user_email);
        $this->assertSame('Ready', $kept->display_name);
        $this->assertTrue(AP_User::checkPassword($newPassword, $kept->user_pass));
        $this->assertSame(0, $this->currentPasswordAttempts($id));
        $this->assertStringNotContainsString($newPassword, $this->encodeResult($missingNonce));

        $bad = 'still-wrong';
        $firstLock = $this->saveOtherUser($id, $id, 'selfedit-stolen@example.test', [
            'pass1' => 'other-password-1',
            'pass2' => 'other-password-1',
            'display_name' => 'Lock One',
            'current_password' => $bad,
        ]);
        $this->assertFalse($firstLock['ok']);
        $this->assertSame(
            [AP_Admin_User_Edit::NOTICE_CURRENT_PASSWORD_INCORRECT],
            $firstLock['errors']
        );
        $this->assertSame('Lock One', $firstLock['user']->display_name ?? '');
        $this->assertSame('selfedit-ok@example.test', $firstLock['user']->user_email ?? '');
        $secondLock = $this->saveOtherUser($id, $id, 'selfedit-stolen@example.test', [
            'display_name' => 'Lock Two',
            'current_password' => '',
        ]);
        $this->assertFalse($secondLock['ok']);
        $this->assertSame(
            [AP_Admin_User_Edit::NOTICE_CURRENT_PASSWORD_REQUIRED],
            $secondLock['errors']
        );
        $locked = $this->saveOtherUser($id, $id, 'selfedit-stolen@example.test', [
            'pass1' => 'other-password-2',
            'pass2' => 'other-password-2',
            'display_name' => 'Locked Name',
            'current_password' => $bad,
        ]);
        $this->assertFalse($locked['ok']);
        $this->assertSame(['Too many attempts, try again in 15 minutes.'], $locked['errors']);
        $this->assertNotNull($locked['user']);
        $this->assertSame('selfedit-ok@example.test', $locked['user']->user_email);
        $this->assertSame('Locked Name', $locked['user']->display_name);
        $this->assertTrue(AP_User::checkPassword($newPassword, $locked['user']->user_pass));
        $this->assertFalse(AP_User::checkPassword('other-password-2', $locked['user']->user_pass));
        $this->assertStringNotContainsString($bad, implode("\n", $locked['errors']));
        $this->assertStringNotContainsString($newPassword, $this->encodeResult($locked));
        $this->assertStringNotContainsString('other-password-2', $this->encodeResult($locked));
        $attemptsWhileLocked = $this->currentPasswordAttempts($id);

        $still = $this->saveOtherUser($id, $id, 'selfedit-stolen@example.test', [
            'display_name' => 'Still Locked',
            'current_password' => $newPassword,
        ]);
        $this->assertFalse($still['ok']);
        $this->assertSame(['Too many attempts, try again in 15 minutes.'], $still['errors']);
        $this->assertSame('selfedit-ok@example.test', $still['user']->user_email ?? '');
        $this->assertTrue(AP_User::checkPassword($newPassword, (string) $still['user']?->user_pass));
        $this->assertSame($attemptsWhileLocked, $this->currentPasswordAttempts($id));

        $duringLock = $this->saveOtherUser($id, $otherId, 'selfeditother-locked@example.test', [
            'pass1' => 'other-password-3',
            'pass2' => 'other-password-3',
            'display_name' => 'Other During Lock',
        ]);
        $this->assertTrue($duringLock['ok'], implode('; ', $duringLock['errors']));
        $this->assertNotNull($duringLock['user']);
        $this->assertSame('selfeditother-locked@example.test', $duringLock['user']->user_email);
        $this->assertSame('Other During Lock', $duringLock['user']->display_name);
        $this->assertTrue(AP_User::checkPassword('other-password-3', $duringLock['user']->user_pass));
        $this->assertSame(0, $this->currentPasswordAttempts($otherId));
        $this->assertSame($attemptsWhileLocked, $this->currentPasswordAttempts($id));
        $this->assertSame('administrator', AP_Roles::getUserRole($id, $this->db));
        $this->assertStringNotContainsString('other-password-3', $this->encodeResult($duringLock));
    }

    public function testProfileEmailAndPasswordChangeSucceedWithCurrentPassword(): void
    {
        $user = AP_User::create([
            'user_login' => 'reauthok',
            'user_email' => 'reauthok@example.test',
            'password' => 'password123',
            'role' => 'author',
        ], $this->db);
        $this->assertTrue($user['ok'], implode('; ', $user['errors']));
        $id = (int) $user['id'];

        $email = AP_Admin_User_Edit::save([
            '_ap_nonce' => ap_create_nonce('update-profile-' . $id, $id),
            'user_ID' => $id,
            'user_email' => 'reauthok-new@example.test',
            'pass1' => '',
            'pass2' => '',
            'display_name' => 'Ready',
            'current_password' => 'password123',
        ], $id, 'profile', $this->db);
        $this->assertTrue($email['ok'], implode('; ', $email['errors']));
        $this->assertSame('profile_updated', $email['message_key']);
        $this->assertNotNull($email['user']);
        $this->assertSame('reauthok-new@example.test', $email['user']->user_email);
        $this->assertSame('Ready', $email['user']->display_name);
        $this->assertTrue(AP_User::checkPassword('password123', $email['user']->user_pass));

        $password = AP_Admin_User_Edit::save([
            '_ap_nonce' => ap_create_nonce('update-profile-' . $id, $id),
            'user_ID' => $id,
            'user_email' => 'reauthok-new@example.test',
            'pass1' => 'new-password-99',
            'pass2' => 'new-password-99',
            'display_name' => 'Ready',
            'current_password' => 'password123',
        ], $id, 'profile', $this->db);
        $this->assertTrue($password['ok'], implode('; ', $password['errors']));
        $this->assertNotNull($password['user']);
        $this->assertSame('reauthok-new@example.test', $password['user']->user_email);
        $this->assertTrue(AP_User::checkPassword('new-password-99', $password['user']->user_pass));
        $this->assertFalse(AP_User::checkPassword('password123', $password['user']->user_pass));
    }

    public function testProfilePasswordChangeKeepsCurrentSessionAndDropsOthers(): void
    {
        $plain = 'password123';
        $user = AP_User::create([
            'user_login' => 'sesself',
            'user_email' => 'sesself@example.test',
            'password' => $plain,
            'role' => 'author',
            'display_name' => 'Session Self',
        ], $this->db);
        $this->assertTrue($user['ok'], implode('; ', $user['errors']));
        $id = (int) $user['id'];

        AP_Session::enableTestMode();
        $this->assertNotNull(AP_Session::login('sesself', $plain, true, $this->db));
        $name = AP_Session::cookieName();
        $before = AP_Session::getTestCookies()[$name] ?? '';
        $beforeParts = explode('|', $before);
        $beforeToken = $beforeParts[2] ?? '';
        $this->assertNotSame('', $beforeToken);

        $otherExp = time() + AP_Session::LIFETIME_REMEMBER;
        $otherToken = AP_Session::createSessionToken($id, $otherExp, $this->db, true);
        $loaded = AP_User::getById($id, $this->db);
        $this->assertNotNull($loaded);
        $otherCookie = AP_Session::generateAuthCookie($loaded, $otherExp, $otherToken);

        $rejected = AP_Admin_User_Edit::save([
            '_ap_nonce' => ap_create_nonce('update-profile-' . $id, $id),
            'user_ID' => $id,
            'user_email' => 'sesself@example.test',
            'pass1' => 'new-password-99',
            'pass2' => 'new-password-99',
            'display_name' => 'Session Self',
            'current_password' => 'not-the-password',
        ], $id, 'profile', $this->db);
        $this->assertFalse($rejected['ok']);
        $this->assertSame($before, AP_Session::getTestCookies()[$name] ?? '');
        $this->assertTrue(AP_Session::sessionTokenIsValid($id, $beforeToken, $this->db));
        $this->assertTrue(AP_Session::sessionTokenIsValid($id, $otherToken, $this->db));
        $this->assertTrue(AP_User::checkPassword($plain, (string) $rejected['user']?->user_pass));

        $saved = AP_Admin_User_Edit::save([
            '_ap_nonce' => ap_create_nonce('update-profile-' . $id, $id),
            'user_ID' => $id,
            'user_email' => 'sesself@example.test',
            'pass1' => 'new-password-99',
            'pass2' => 'new-password-99',
            'display_name' => 'Session Self',
            'current_password' => $plain,
        ], $id, 'profile', $this->db);
        $this->assertTrue($saved['ok'], implode('; ', $saved['errors']));
        $this->assertSame('profile_updated', $saved['message_key']);
        $this->assertTrue(AP_Session::isLoggedIn($this->db));
        $this->assertSame($id, AP_Session::getCurrentUserId($this->db));

        $after = AP_Session::getTestCookies()[$name] ?? '';
        $afterParts = explode('|', $after);
        $afterToken = $afterParts[2] ?? '';
        $this->assertNotSame('', $afterToken);
        $this->assertNotSame($beforeToken, $afterToken);
        $this->assertFalse(AP_Session::sessionTokenIsValid($id, $beforeToken, $this->db));
        $this->assertFalse(AP_Session::sessionTokenIsValid($id, $otherToken, $this->db));
        $this->assertTrue(AP_Session::sessionTokenIsValid($id, $afterToken, $this->db));
        $this->assertCount(1, AP_Session::getSessionTokens($id, $this->db));
        $afterExp = (int) ($afterParts[1] ?? 0);
        $this->assertGreaterThan(time() + AP_Session::LIFETIME_DEFAULT, $afterExp);

        AP_Session::resetCurrentUser();
        $this->assertSame($id, AP_Session::getCurrentUserId($this->db));
        $this->assertNull(AP_Session::validateAuthCookie($before, $this->db));
        $this->assertNull(AP_Session::validateAuthCookie($otherCookie, $this->db));
        $this->assertTrue(AP_Session::isLoggedIn($this->db));

        $secondOther = AP_Session::createSessionToken($id, time() + AP_Session::LIFETIME_REMEMBER, $this->db, true);
        $mid = AP_Session::getTestCookies()[$name] ?? '';
        $midToken = explode('|', $mid)[2] ?? '';
        $again = AP_Admin_User_Edit::save([
            '_ap_nonce' => ap_create_nonce('update-user-' . $id, $id),
            'user_ID' => $id,
            'user_email' => 'sesself@example.test',
            'pass1' => 'newer-password-1',
            'pass2' => 'newer-password-1',
            'display_name' => 'Session Self',
            'current_password' => 'new-password-99',
        ], $id, 'update', $this->db);
        $this->assertTrue($again['ok'], implode('; ', $again['errors']));
        $this->assertSame('user_updated', $again['message_key']);
        $this->assertTrue(AP_User::checkPassword('newer-password-1', (string) $again['user']?->user_pass));
        $this->assertTrue(AP_Session::isLoggedIn($this->db));
        $this->assertSame($id, AP_Session::getCurrentUserId($this->db));
        $final = AP_Session::getTestCookies()[$name] ?? '';
        $finalToken = explode('|', $final)[2] ?? '';
        $this->assertNotSame($midToken, $finalToken);
        $this->assertFalse(AP_Session::sessionTokenIsValid($id, $midToken, $this->db));
        $this->assertFalse(AP_Session::sessionTokenIsValid($id, $secondOther, $this->db));
        $this->assertTrue(AP_Session::sessionTokenIsValid($id, $finalToken, $this->db));
        $this->assertCount(1, AP_Session::getSessionTokens($id, $this->db));
    }

    public function testAdminResetOfAnotherUserDestroysAllTargetSessions(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.40';

        $admin = AP_User::create([
            'user_login' => 'sessadmin',
            'user_email' => 'sessadmin@example.test',
            'password' => 'password123',
            'role' => 'administrator',
            'display_name' => 'Session Admin',
        ], $this->db);
        $this->assertTrue($admin['ok'], implode('; ', $admin['errors']));
        $adminId = (int) $admin['id'];

        $target = AP_User::create([
            'user_login' => 'sesstarget',
            'user_email' => 'sesstarget@example.test',
            'password' => 'password123',
            'role' => 'subscriber',
            'display_name' => 'Session Target',
        ], $this->db);
        $this->assertTrue($target['ok'], implode('; ', $target['errors']));
        $targetId = (int) $target['id'];
        $this->assertTrue(ap_user_can($adminId, 'edit_users', null, $this->db));

        $editor = AP_User::create([
            'user_login' => 'sesseditor',
            'user_email' => 'sesseditor@example.test',
            'password' => 'password123',
            'role' => 'editor',
        ], $this->db);
        $this->assertTrue($editor['ok'], implode('; ', $editor['errors']));
        $editorId = (int) $editor['id'];
        $this->assertFalse(ap_user_can($editorId, 'edit_users', null, $this->db));

        AP_Session::enableTestMode();
        $this->assertNotNull(AP_Session::login('sessadmin', 'password123', true, $this->db));
        $name = AP_Session::cookieName();
        $adminCookie = AP_Session::getTestCookies()[$name] ?? '';
        $adminToken = explode('|', $adminCookie)[2] ?? '';
        $this->assertNotSame('', $adminToken);
        $adminOther = AP_Session::createSessionToken(
            $adminId,
            time() + AP_Session::LIFETIME_REMEMBER,
            $this->db,
            true
        );
        $this->assertCount(2, AP_Session::getSessionTokens($adminId, $this->db));

        $loaded = AP_User::getById($targetId, $this->db);
        $this->assertNotNull($loaded);
        $shortExp = time() + 3600;
        $rememberExp = time() + AP_Session::LIFETIME_REMEMBER;
        $shortToken = AP_Session::createSessionToken($targetId, $shortExp, $this->db, false);
        $rememberToken = AP_Session::createSessionToken($targetId, $rememberExp, $this->db, true);
        $shortCookie = AP_Session::generateAuthCookie($loaded, $shortExp, $shortToken);
        $rememberCookie = AP_Session::generateAuthCookie($loaded, $rememberExp, $rememberToken);
        $this->assertCount(2, AP_Session::getSessionTokens($targetId, $this->db));

        $emailOnly = $this->saveOtherUser($adminId, $targetId, 'sesstarget-new@example.test', [
            'display_name' => 'Renamed Target',
        ]);
        $this->assertTrue($emailOnly['ok'], implode('; ', $emailOnly['errors']));
        $this->assertSame($adminCookie, AP_Session::getTestCookies()[$name] ?? '');
        $this->assertTrue(AP_Session::sessionTokenIsValid($targetId, $shortToken, $this->db));
        $this->assertTrue(AP_Session::sessionTokenIsValid($targetId, $rememberToken, $this->db));
        $this->assertTrue(AP_User::checkPassword('password123', (string) $emailOnly['user']?->user_pass));

        $mismatch = $this->saveOtherUser($adminId, $targetId, 'sesstarget-new@example.test', [
            'pass1' => 'other-password-7',
            'pass2' => 'other-password-6',
            'display_name' => 'Renamed Target',
        ]);
        $this->assertFalse($mismatch['ok']);
        $this->assertSame(['Passwords do not match.'], $mismatch['errors']);
        $this->assertTrue(AP_Session::sessionTokenIsValid($targetId, $shortToken, $this->db));
        $this->assertTrue(AP_Session::sessionTokenIsValid($targetId, $rememberToken, $this->db));
        $this->assertSame($adminCookie, AP_Session::getTestCookies()[$name] ?? '');

        $forbidden = $this->saveOtherUser($editorId, $targetId, 'sesstarget-take@example.test', [
            'pass1' => 'other-password-8',
            'pass2' => 'other-password-8',
        ]);
        $this->assertFalse($forbidden['ok']);
        $this->assertTrue(AP_Session::sessionTokenIsValid($targetId, $shortToken, $this->db));
        $this->assertTrue(AP_Session::sessionTokenIsValid($targetId, $rememberToken, $this->db));
        $kept = AP_User::getById($targetId, $this->db);
        $this->assertNotNull($kept);
        $this->assertTrue(AP_User::checkPassword('password123', $kept->user_pass));

        $secret = 'reset-password-1';
        $saved = $this->saveOtherUser($adminId, $targetId, 'sesstarget-new@example.test', [
            'pass1' => $secret,
            'pass2' => $secret,
            'display_name' => 'Renamed Target',
            'current_password' => 'not-the-target-password',
        ]);
        $this->assertTrue($saved['ok'], implode('; ', $saved['errors']));
        $this->assertSame('user_updated', $saved['message_key']);
        $this->assertNotNull($saved['user']);
        $this->assertTrue(AP_User::checkPassword($secret, $saved['user']->user_pass));
        $this->assertFalse(AP_User::checkPassword('password123', $saved['user']->user_pass));
        $this->assertStringNotContainsString($secret, $this->encodeResult($saved));

        $this->assertSame([], AP_Session::getSessionTokens($targetId, $this->db));
        $this->assertFalse(AP_Session::sessionTokenIsValid($targetId, $shortToken, $this->db));
        $this->assertFalse(AP_Session::sessionTokenIsValid($targetId, $rememberToken, $this->db));
        $this->assertNull(AP_Session::validateAuthCookie($shortCookie, $this->db));
        $this->assertNull(AP_Session::validateAuthCookie($rememberCookie, $this->db));

        $this->assertTrue(AP_Session::isLoggedIn($this->db));
        $this->assertSame($adminId, AP_Session::getCurrentUserId($this->db));
        $this->assertSame($adminCookie, AP_Session::getTestCookies()[$name] ?? '');
        $this->assertTrue(AP_Session::sessionTokenIsValid($adminId, $adminToken, $this->db));
        $this->assertTrue(AP_Session::sessionTokenIsValid($adminId, $adminOther, $this->db));
        $this->assertCount(2, AP_Session::getSessionTokens($adminId, $this->db));

        $rows = $this->activityRows($targetId);
        $this->assertCount(2, $rows);
        $this->assertActivityRow(
            $rows[1],
            $adminId,
            $targetId,
            AP_Activity_Log::FIELD_PASSWORD,
            '203.0.113.40'
        );
        $this->assertLogOmits($rows, [$secret, 'not-the-target-password']);

        $againToken = AP_Session::createSessionToken(
            $targetId,
            time() + AP_Session::LIFETIME_REMEMBER,
            $this->db,
            true
        );
        $againCookie = AP_Session::generateAuthCookie(
            $saved['user'],
            time() + AP_Session::LIFETIME_REMEMBER,
            $againToken
        );
        $this->assertNotNull(AP_Session::validateAuthCookie($againCookie, $this->db));
        $second = 'reset-password-2';
        $again = $this->saveOtherUser($adminId, $targetId, 'sesstarget-new@example.test', [
            'pass1' => $second,
            'pass2' => $second,
            'display_name' => 'Renamed Target',
        ]);
        $this->assertTrue($again['ok'], implode('; ', $again['errors']));
        $this->assertTrue(AP_User::checkPassword($second, (string) $again['user']?->user_pass));
        $this->assertSame([], AP_Session::getSessionTokens($targetId, $this->db));
        $this->assertFalse(AP_Session::sessionTokenIsValid($targetId, $againToken, $this->db));
        $this->assertNull(AP_Session::validateAuthCookie($againCookie, $this->db));
        $this->assertNull(AP_Session::validateAuthCookie($shortCookie, $this->db));
        $this->assertSame($adminId, AP_Session::getCurrentUserId($this->db));
        $this->assertSame($adminCookie, AP_Session::getTestCookies()[$name] ?? '');
        $this->assertTrue(AP_Session::sessionTokenIsValid($adminId, $adminToken, $this->db));
        $this->assertCount(2, AP_Session::getSessionTokens($adminId, $this->db));
        $this->assertStringNotContainsString($second, $this->encodeResult($again));

        AP_Session::enableTestMode();
        $this->assertNotNull(AP_Session::login('sesstarget', $second, false, $this->db));
        $this->assertSame($targetId, AP_Session::getCurrentUserId($this->db));
        $this->assertCount(1, AP_Session::getSessionTokens($targetId, $this->db));
        $this->assertCount(2, AP_Session::getSessionTokens($adminId, $this->db));

        AP_Session::enableTestMode([$name => $adminCookie]);
        AP_Session::resetCurrentUser();
        $this->assertSame($adminId, AP_Session::getCurrentUserId($this->db));
        $this->assertTrue(AP_Session::sessionTokenIsValid($adminId, $adminToken, $this->db));
    }

    public function testProfileCurrentPasswordUsesHashVerifyAndIsNotDisclosed(): void
    {
        $plain = 'yes secret!';
        $wrong = 'nope secret!';
        $user = AP_User::create([
            'user_login' => 'hashv',
            'user_email' => 'hashv@example.test',
            'password' => $plain,
            'role' => 'author',
            'display_name' => 'Before',
        ], $this->db);
        $this->assertTrue($user['ok'], implode('; ', $user['errors']));
        $id = (int) $user['id'];
        $stored = AP_User::getById($id, $this->db);
        $this->assertNotNull($stored);
        $hash = $stored->user_pass;
        $this->assertNotSame($plain, $hash);
        $this->assertTrue(password_verify($plain, $hash));
        $this->assertTrue(AP_User::checkPassword($plain, $hash));
        $this->assertFalse(AP_User::checkPassword($hash, $hash));

        $logFile = tempnam(sys_get_temp_dir(), 'ap-reauth-');
        $this->assertIsString($logFile);
        $prevLogErrors = ini_set('log_errors', '1');
        $prevErrorLog = ini_set('error_log', $logFile);
        $captured = [];
        set_error_handler(static function (int $severity, string $message) use (&$captured): bool {
            $captured[] = $message;

            return false;
        });

        $save = function (array $fields) use ($id): array {
            return AP_Admin_User_Edit::save(array_merge([
                '_ap_nonce' => ap_create_nonce('update-profile-' . $id, $id),
                'user_ID' => $id,
                'user_email' => 'hashv@example.test',
                'pass1' => '',
                'pass2' => '',
                'display_name' => 'Hash Verify',
            ], $fields), $id, 'profile', $this->db);
        };

        $logged = '';
        try {
            $asHash = $save([
                'user_email' => 'hashv-hash@example.test',
                'display_name' => 'Hash Tried',
                'current_password' => $hash,
            ]);
            $this->assertFalse($asHash['ok']);
            $this->assertSame(['Your current password is incorrect.'], $asHash['errors']);
            $this->assertNotNull($asHash['user']);
            $this->assertSame('hashv@example.test', $asHash['user']->user_email);
            $this->assertSame('Hash Tried', $asHash['user']->display_name);
            $this->assertSame($hash, $asHash['user']->user_pass);
            $this->assertStringNotContainsString($hash, implode("\n", $asHash['errors']));
            $this->assertStringNotContainsString($plain, $this->encodeResult($asHash));

            $rejected = $save([
                'user_email' => 'hashv-wrong@example.test',
                'pass1' => 'brand-new-pass',
                'pass2' => 'brand-new-pass',
                'display_name' => 'Still Named',
                'current_password' => $wrong,
            ]);
            $this->assertFalse($rejected['ok']);
            $this->assertSame(['Your current password is incorrect.'], $rejected['errors']);
            $this->assertNotNull($rejected['user']);
            $this->assertSame('hashv@example.test', $rejected['user']->user_email);
            $this->assertSame('Still Named', $rejected['user']->display_name);
            $this->assertTrue(AP_User::checkPassword($plain, $rejected['user']->user_pass));
            $this->assertFalse(AP_User::checkPassword('brand-new-pass', $rejected['user']->user_pass));
            $this->assertStringNotContainsString($wrong, implode("\n", $rejected['errors']));
            $this->assertStringNotContainsString($wrong, $this->encodeResult($rejected));
            $this->assertStringNotContainsString($plain, $this->encodeResult($rejected));
            $this->assertStringNotContainsString($wrong, (string) ($this->db->lastError() ?? ''));

            $accepted = $save([
                'user_email' => 'hashv-ok@example.test',
                'current_password' => $plain,
            ]);
            $this->assertTrue($accepted['ok'], implode('; ', $accepted['errors']));
            $this->assertNotNull($accepted['user']);
            $this->assertSame('hashv-ok@example.test', $accepted['user']->user_email);
            $this->assertNotSame($plain, $accepted['user']->user_pass);
            $this->assertTrue(password_verify($plain, $accepted['user']->user_pass));
            $acceptedPublic = $accepted['user']->toPublicArray();
            $this->assertStringNotContainsString($plain, $this->encodeResult($accepted));
            $this->assertStringNotContainsString($plain, $this->encodeResult($acceptedPublic));

            $legacyPlain = 'legacy plain!';
            $legacy = password_hash($legacyPlain, PASSWORD_BCRYPT);
            $this->assertIsString($legacy);
            $this->assertNotFalse($this->db->update('users', ['user_pass' => $legacy], ['ID' => $id]));
            $legacyBad = $save([
                'user_email' => 'hashv-legacy-bad@example.test',
                'current_password' => $plain,
            ]);
            $this->assertFalse($legacyBad['ok']);
            $this->assertSame(['Your current password is incorrect.'], $legacyBad['errors']);
            $this->assertNotNull($legacyBad['user']);
            $this->assertSame('hashv-ok@example.test', $legacyBad['user']->user_email);
            $this->assertSame($legacy, $legacyBad['user']->user_pass);

            $legacyOk = $save([
                'user_email' => 'hashv-legacy@example.test',
                'current_password' => $legacyPlain,
            ]);
            $this->assertTrue($legacyOk['ok'], implode('; ', $legacyOk['errors']));
            $this->assertNotNull($legacyOk['user']);
            $this->assertSame('hashv-legacy@example.test', $legacyOk['user']->user_email);
            $this->assertSame($legacy, $legacyOk['user']->user_pass);
            $this->assertTrue(password_verify($legacyPlain, $legacyOk['user']->user_pass));
            $this->assertStringNotContainsString($legacyPlain, $this->encodeResult($legacyOk));
            $this->assertStringNotContainsString($legacyPlain, implode("\n", $legacyOk['errors']));

            $this->assertNotFalse($this->db->update('users', ['user_pass' => ''], ['ID' => $id]));
            $emptyHash = $save([
                'user_email' => 'hashv-empty@example.test',
                'display_name' => 'Empty Hash',
                'current_password' => $legacyPlain,
            ]);
            $this->assertFalse($emptyHash['ok']);
            $this->assertSame(['Your current password is incorrect.'], $emptyHash['errors']);
            $this->assertNotNull($emptyHash['user']);
            $this->assertSame('hashv-legacy@example.test', $emptyHash['user']->user_email);
            $this->assertSame('Empty Hash', $emptyHash['user']->display_name);
            $this->assertSame('', $emptyHash['user']->user_pass);

            $fresh = AP_User::getById($id, $this->db);
            $this->assertNotNull($fresh);
            $html = AP_Admin_User_Edit::renderForm($fresh, 'profile', $id, [
                'current_password' => $plain,
                'pass1' => $wrong,
                'pass2' => $legacyPlain,
            ], $this->db);
            $this->assertStringNotContainsString($plain, $html);
            $this->assertStringNotContainsString($wrong, $html);
            $this->assertStringNotContainsString($legacyPlain, $html);
            $this->assertDoesNotMatchRegularExpression(
                '/name="current_password"[^>]*value="/',
                $html
            );
            $this->assertDoesNotMatchRegularExpression(
                '/name="pass1"[^>]*value="/',
                $html
            );

            $sql = $this->encodeResult($this->db->getQueries());
            $this->assertStringNotContainsString($plain, $sql);
            $this->assertStringNotContainsString($wrong, $sql);
            $this->assertStringNotContainsString($legacyPlain, $sql);
            $this->assertStringNotContainsString($plain, (string) ($this->db->lastError() ?? ''));
        } finally {
            restore_error_handler();
            if ($prevLogErrors !== false) {
                ini_set('log_errors', (string) $prevLogErrors);
            }
            if ($prevErrorLog !== false) {
                ini_set('error_log', (string) $prevErrorLog);
            }
            if (is_file($logFile)) {
                $logged = (string) file_get_contents($logFile);
                unlink($logFile);
            }
        }

        $noise = implode("\n", $captured);
        $this->assertStringNotContainsString($plain, $logged);
        $this->assertStringNotContainsString($wrong, $logged);
        $this->assertStringNotContainsString($legacyPlain, $logged);
        $this->assertStringNotContainsString($plain, $noise);
        $this->assertStringNotContainsString($wrong, $noise);
        $this->assertStringNotContainsString($legacyPlain, $noise);

        $profile = file_get_contents($this->root . '/ap-admin/profile.php');
        $this->assertIsString($profile);
        $this->assertStringContainsString("unset(\$_POST['current_password']", $profile);
        $this->assertStringNotContainsString("extra['current_password']", $profile);
        $this->assertStringNotContainsString('echo $_POST', $profile);
    }

    public function testSanitizeSignatureCapsLength(): void
    {
        $max = AP_User::SIGNATURE_MAX_LENGTH;
        $long = str_repeat('x', $max + 40);
        $clean = AP_User::sanitizeSignature($long);
        $this->assertSame($max, function_exists('mb_strlen') ? mb_strlen($clean) : strlen($clean));
        $this->assertSame('', AP_User::sanitizeSignature("  \n  "));
    }

    public function testProfileFormIncludesSignatureField(): void
    {
        $user = AP_User::create([
            'user_login' => 'sigform',
            'user_email' => 'sigform@example.test',
            'password' => 'password123',
            'role' => 'subscriber',
            'signature' => 'Hello from form',
        ], $this->db);
        $this->assertTrue($user['ok']);
        $u = AP_User::getById($user['id'], $this->db);
        $this->assertNotNull($u);

        require_once $this->root . '/ap-admin/includes/class-ap-admin-user-edit.php';
        $html = AP_Admin_User_Edit::renderForm($u, 'profile', $user['id'], [], $this->db);
        $this->assertStringContainsString('name="signature"', $html);
        $this->assertStringContainsString('id="signature"', $html);
        $this->assertStringContainsString('Hello from form', $html);
        $this->assertStringContainsString('Forum signature', $html);
    }

    public function testProfileFormShowsCurrentPasswordWithEmailAndPassword(): void
    {
        $user = AP_User::create([
            'user_login' => 'curpass',
            'user_email' => 'curpass@example.test',
            'password' => 'password123',
            'role' => 'subscriber',
        ], $this->db);
        $this->assertTrue($user['ok'], implode('; ', $user['errors']));
        $id = (int) $user['id'];
        $u = AP_User::getById($id, $this->db);
        $this->assertNotNull($u);

        $html = AP_Admin_User_Edit::renderForm($u, 'profile', $id, [
            'current_password' => 'do-not-echo-this',
        ], $this->db);

        $this->assertStringContainsString('>Current password<', $html);
        $this->assertStringContainsString('name="current_password"', $html);
        $this->assertStringContainsString('id="current_password"', $html);
        $this->assertStringContainsString('type="password"', $html);
        $this->assertStringContainsString('autocomplete="current-password"', $html);
        $this->assertStringContainsString('name="user_email"', $html);
        $this->assertStringContainsString('name="pass1"', $html);
        $this->assertStringContainsString('name="pass2"', $html);
        $this->assertStringContainsString(
            'Enter your current password to change your password or email.',
            $html
        );
        $this->assertStringNotContainsString('do-not-echo-this', $html);
        $this->assertDoesNotMatchRegularExpression(
            '/name="current_password"[^>]*value="[^"]+"/s',
            $html
        );

        $emailPos = strpos($html, 'name="user_email"');
        $currentPos = strpos($html, 'name="current_password"');
        $passPos = strpos($html, 'name="pass1"');
        $nameLegend = strpos($html, '<legend>Name</legend>');
        $this->assertNotFalse($emailPos);
        $this->assertNotFalse($currentPos);
        $this->assertNotFalse($passPos);
        $this->assertNotFalse($nameLegend);
        $this->assertGreaterThan($emailPos, $currentPos);
        $this->assertLessThan($passPos, $currentPos);
        $this->assertLessThan($nameLegend, $currentPos);

        $create = AP_Admin_User_Edit::renderForm(null, 'create', $id, [], $this->db);
        $this->assertStringNotContainsString('name="current_password"', $create);
        $this->assertStringNotContainsString('autocomplete="current-password"', $create);

        // User-edit of this same account asks for the current password too.
        $update = AP_Admin_User_Edit::renderForm($u, 'update', $id, [], $this->db);
        $this->assertStringContainsString('name="current_password"', $update);
        $this->assertStringContainsString('autocomplete="current-password"', $update);
    }

    public function testProfileCurrentPasswordFailuresAreThrottled(): void
    {
        $this->assertSame(
            AP_Rate_Limit::CURRENT_PASSWORD_NOTICE_PREFIX,
            AP_Admin_User_Edit::NOTICE_CURRENT_PASSWORD_LIMITED_PREFIX
        );
        $defaults = AP_Rate_Limit::getLimits(AP_Rate_Limit::ACTION_CURRENT_PASSWORD, $this->db);
        $this->assertSame(5, $defaults['max']);
        $this->assertSame(900, $defaults['window']);

        AP_Rate_Limit::setTestLimits(AP_Rate_Limit::ACTION_CURRENT_PASSWORD, [
            'max' => 3,
            'window' => 900,
            'lockout' => 900,
        ]);
        $_SERVER['REMOTE_ADDR'] = '203.0.113.40';

        $owner = $this->makeProfileUser('throttleowner', 'throttle@example.test');
        $other = $this->makeProfileUser('throttleother', 'throttle-other@example.test');
        $id = $owner['id'];
        $wrong = 'not-the-real-password';

        $ignored = $this->saveProfile($id, 'throttle@example.test', [
            'display_name' => 'Bio Only',
            'current_password' => $wrong,
        ]);
        $this->assertTrue($ignored['ok'], implode('; ', $ignored['errors']));
        $this->assertSame(0, $this->currentPasswordAttempts($id));

        $first = $this->saveProfile($id, 'throttle@example.test', [
            'pass1' => 'new-password-99',
            'pass2' => 'new-password-99',
            'display_name' => 'Still Saved',
            'current_password' => $wrong,
        ]);
        $this->assertFalse($first['ok']);
        $this->assertSame(
            [AP_Admin_User_Edit::NOTICE_CURRENT_PASSWORD_INCORRECT],
            $first['errors']
        );
        $this->assertSame('Still Saved', $first['user']->display_name ?? '');
        $this->assertTrue(AP_User::checkPassword('password123', (string) $first['user']?->user_pass));
        $this->assertStringNotContainsString($wrong, $this->encodeResult($first));

        $second = $this->saveProfile($id, 'taken@example.test', [
            'display_name' => 'Email Held',
            'current_password' => '',
        ]);
        $this->assertFalse($second['ok']);
        $this->assertSame(
            [AP_Admin_User_Edit::NOTICE_CURRENT_PASSWORD_REQUIRED],
            $second['errors']
        );
        $this->assertSame('throttle@example.test', $second['user']->user_email ?? '');
        $this->assertSame('Email Held', $second['user']->display_name ?? '');
        $this->assertSame(2, $this->currentPasswordAttempts($id));

        $cleared = $this->saveProfile($id, 'throttle@example.test', [
            'pass1' => 'new-password-99',
            'pass2' => 'new-password-99',
            'current_password' => 'password123',
        ]);
        $this->assertTrue($cleared['ok'], implode('; ', $cleared['errors']));
        $this->assertTrue(AP_User::checkPassword('new-password-99', (string) $cleared['user']?->user_pass));
        $this->assertSame(0, $this->currentPasswordAttempts($id));
        $this->assertSame(0, $this->currentPasswordAttempts(0, AP_Rate_Limit::ipBucket('203.0.113.40')));

        $this->failCurrentPassword($id, 'throttle@example.test', $wrong, 'After Clear');
        $this->failCurrentPassword($id, 'throttle@example.test', $wrong, 'After Clear');
        $locked = $this->saveProfile($id, 'stolen@example.test', [
            'pass1' => 'other-password-1',
            'pass2' => 'other-password-1',
            'display_name' => 'Locked Name',
            'current_password' => $wrong,
        ]);
        $this->assertFalse($locked['ok']);
        $this->assertSame(['Too many attempts, try again in 15 minutes.'], $locked['errors']);
        $this->assertSame('throttle@example.test', $locked['user']->user_email ?? '');
        $this->assertSame('Locked Name', $locked['user']->display_name ?? '');
        $this->assertTrue(AP_User::checkPassword('new-password-99', (string) $locked['user']?->user_pass));
        $this->assertStringNotContainsString($wrong, implode("\n", $locked['errors']));
        $this->assertStringNotContainsString('other-password-1', $this->encodeResult($locked));
        $this->assertTrue(AP_Admin_User_Edit::profileSensitiveChangeRejected($locked['errors']));
        $this->assertSame(
            'throttle@example.test',
            AP_Admin_User_Edit::profileEmailAfterSave(
                $locked['errors'],
                'stolen@example.test',
                'throttle@example.test'
            )
        );
        $attemptsWhileLocked = $this->currentPasswordAttempts($id);

        $still = $this->saveProfile($id, 'stolen@example.test', [
            'display_name' => 'Still Locked',
            'current_password' => 'new-password-99',
        ]);
        $this->assertFalse($still['ok']);
        $this->assertSame(['Too many attempts, try again in 15 minutes.'], $still['errors']);
        $this->assertSame('throttle@example.test', $still['user']->user_email ?? '');
        $this->assertTrue(AP_User::checkPassword('new-password-99', (string) $still['user']?->user_pass));
        $this->assertSame($attemptsWhileLocked, $this->currentPasswordAttempts($id));

        $_SERVER['REMOTE_ADDR'] = '203.0.113.41';
        $otherIp = $this->saveProfile($id, 'throttle@example.test', [
            'pass1' => 'other-password-2',
            'pass2' => 'other-password-2',
            'current_password' => 'new-password-99',
        ]);
        $this->assertFalse($otherIp['ok']);
        $this->assertSame(['Too many attempts, try again in 15 minutes.'], $otherIp['errors']);

        $_SERVER['REMOTE_ADDR'] = '203.0.113.40';
        $sameIp = $this->saveProfile($other['id'], 'throttle-other@example.test', [
            'pass1' => 'other-password-3',
            'pass2' => 'other-password-3',
            'display_name' => 'Other Saved',
            'current_password' => 'password123',
        ]);
        $this->assertFalse($sameIp['ok']);
        $this->assertSame(['Too many attempts, try again in 15 minutes.'], $sameIp['errors']);
        $this->assertSame('Other Saved', $sameIp['user']->display_name ?? '');
        $this->assertTrue(AP_User::checkPassword('password123', (string) $sameIp['user']?->user_pass));
        $this->assertSame($attemptsWhileLocked, $this->currentPasswordAttempts($id));

        $_SERVER['REMOTE_ADDR'] = '198.51.100.50';
        $fresh = $this->saveProfile($other['id'], 'throttle-other@example.test', [
            'pass1' => 'other-password-4',
            'pass2' => 'other-password-4',
            'current_password' => $wrong,
        ]);
        $this->assertFalse($fresh['ok']);
        $this->assertSame(
            [AP_Admin_User_Edit::NOTICE_CURRENT_PASSWORD_INCORRECT],
            $fresh['errors']
        );

        $this->ageCurrentPasswordBucket(AP_Rate_Limit::userBucket($id));
        $this->ageCurrentPasswordBucket(AP_Rate_Limit::ipBucket('203.0.113.40'));
        $_SERVER['REMOTE_ADDR'] = '203.0.113.40';
        $reset = $this->saveProfile($id, 'throttle@example.test', [
            'pass1' => 'other-password-5',
            'pass2' => 'other-password-5',
            'current_password' => $wrong,
        ]);
        $this->assertFalse($reset['ok']);
        $this->assertSame(
            [AP_Admin_User_Edit::NOTICE_CURRENT_PASSWORD_INCORRECT],
            $reset['errors']
        );
        $this->assertSame(1, $this->currentPasswordAttempts($id));

        AP_Admin::clearNotices();
        AP_Admin_User_Edit::queueSaveErrors($locked['errors']);
        $html = AP_Admin::renderNotices();
        $this->assertStringContainsString('ap-notice--error', $html);
        $this->assertStringContainsString('Too many attempts, try again in 15 minutes.', $html);
        $this->assertStringNotContainsString($wrong, $html);
        AP_Admin::clearNotices();
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
        $this->assertStringContainsString('name="location"', $html);
        $this->assertStringContainsString('Add New User', $html);
    }

    public function testEditFormRendersTargetUserNotActor(): void
    {
        $admin = AP_User::create([
            'user_login' => 'siteadmin',
            'user_email' => 'siteadmin@example.test',
            'password' => 'password123',
            'role' => 'administrator',
            'display_name' => 'Site Admin',
        ], $this->db);
        $other = AP_User::create([
            'user_login' => 'otherperson',
            'user_email' => 'other@example.test',
            'password' => 'password123',
            'role' => 'author',
            'display_name' => 'Other Person',
        ], $this->db);
        $this->assertTrue($other['ok'], implode('; ', $other['errors']));

        $target = AP_User::getById($other['id'], $this->db);
        $this->assertNotNull($target);
        $html = AP_Admin_User_Edit::renderForm($target, 'update', $admin['id'], [], $this->db);

        $this->assertStringContainsString('otherperson', $html);
        $this->assertStringContainsString('other@example.test', $html);
        $this->assertStringContainsString('name="user_ID" value="' . $other['id'] . '"', $html);
        $this->assertStringContainsString('user_id=' . $other['id'], $html);
        $this->assertStringNotContainsString('siteadmin', $html);
        $this->assertStringNotContainsString('siteadmin@example.test', $html);
    }

    public function testAdminHeaderDoesNotClobberScreenUserVariable(): void
    {
        $src = (string) file_get_contents($this->root . '/ap-admin/admin-header.php');
        $this->assertDoesNotMatchRegularExpression(
            '/\$user\s*=/',
            $src,
            'admin-header.php must not assign $user; that overwrites the user-edit.php target'
        );
        $this->assertStringContainsString('$ap_admin_user', $src);
    }

    public function testUserEditScreenKeepsTargetAfterHeaderInclude(): void
    {
        $src = (string) file_get_contents($this->root . '/ap-admin/user-edit.php');
        $headerPos = strpos($src, 'admin-header.php');
        $this->assertNotFalse($headerPos);
        $after = substr($src, $headerPos);
        $this->assertStringContainsString('renderForm($editUser', $after);
        $this->assertStringNotContainsString('renderForm($user', $after);
    }

    public function testIncludingAdminHeaderDoesNotReplaceLoadedEditUser(): void
    {
        $other = AP_User::create([
            'user_login' => 'headerclobber',
            'user_email' => 'headerclobber@example.test',
            'password' => 'password123',
            'role' => 'author',
            'display_name' => 'Header Clobber',
        ], $this->db);
        $this->assertTrue($other['ok'], implode('; ', $other['errors']));

        $user = AP_User::getById($other['id'], $this->db);
        $this->assertNotNull($user);
        $targetId = $user->ID;
        $targetLogin = $user->user_login;

        if (!defined('AP_ABSPATH')) {
            define('AP_ABSPATH', $this->root . '/');
        }

        $ap_admin_title = 'Edit User';
        $ap_admin_screen = 'users';
        $ap_admin_body_class = 'ap-user-edit-php';
        ob_start();
        try {
            require $this->root . '/ap-admin/admin-header.php';
        } finally {
            ob_end_clean();
        }

        $this->assertInstanceOf(AP_User::class, $user);
        $this->assertSame($targetId, $user->ID);
        $this->assertSame($targetLogin, $user->user_login);
        $this->assertSame('headerclobber', $user->user_login);
    }

    public function testEditFormShowsResendVerificationForPendingUser(): void
    {
        AP_Mail::enableTestMode();
        AP_Options::update('users_can_register', '1', $this->db);
        AP_Options::update('require_email_verification', '1', $this->db);
        AP_Options::update('default_role', 'subscriber', $this->db);
        AP_Options::update('blogname', 'Test Site', $this->db);
        AP_Options::update('siteurl', 'https://example.test', $this->db);

        $admin = AP_User::create([
            'user_login' => 'verifyadmin',
            'user_email' => 'verifyadmin@example.test',
            'password' => 'password123',
            'role' => 'administrator',
        ], $this->db);
        $pending = AP_Registration::register([
            'user_login' => 'pendinguser',
            'user_email' => 'pendinguser@example.test',
            'user_pass' => 'securepass8',
            'ap_form_ticket' => AP_Registration::createFormTicket(
                time() - AP_Registration::MIN_FILL_SECONDS
            )['token'],
            'ap_hp' => '',
        ], $this->db);
        $this->assertTrue($pending['ok'], implode('; ', $pending['errors']));
        $target = AP_User::getById($pending['id'], $this->db);
        $this->assertNotNull($target);

        $html = AP_Admin_User_Edit::renderForm($target, 'update', $admin['id'], [], $this->db);
        $this->assertStringContainsString('Resend verification', $html);
        $this->assertStringContainsString('name="ap_resend_verification"', $html);
        $this->assertStringContainsString('ap-user-resend-form', $html);
        $this->assertStringContainsString('Activate account', $html);
        $this->assertStringContainsString('name="ap_activate_account"', $html);

        $active = AP_User::getById($admin['id'], $this->db);
        $this->assertNotNull($active);
        $activeHtml = AP_Admin_User_Edit::renderForm($active, 'update', $admin['id'], [], $this->db);
        $this->assertStringNotContainsString('Resend verification', $activeHtml);
        $this->assertStringNotContainsString('Activate account', $activeHtml);
        $this->assertStringNotContainsString('name="ap_activate_account"', $activeHtml);
    }

    public function testResendVerificationFromUserEdit(): void
    {
        AP_Mail::enableTestMode();
        AP_Options::update('users_can_register', '1', $this->db);
        AP_Options::update('require_email_verification', '1', $this->db);
        AP_Options::update('default_role', 'subscriber', $this->db);
        AP_Options::update('blogname', 'Test Site', $this->db);
        AP_Options::update('siteurl', 'https://forum.example.test', $this->db);

        $previousScript = $_SERVER['SCRIPT_NAME'] ?? null;
        $_SERVER['SCRIPT_NAME'] = '/ap-admin/login.php';

        try {
            $admin = AP_User::create([
                'user_login' => 'resendadmin',
                'user_email' => 'resendadmin@example.test',
                'password' => 'password123',
                'role' => 'administrator',
            ], $this->db);
            $pending = AP_Registration::register([
                'user_login' => 'editresend',
                'user_email' => 'editresend@example.test',
                'user_pass' => 'securepass8',
                'ap_form_ticket' => AP_Registration::createFormTicket(
                    time() - AP_Registration::MIN_FILL_SECONDS
                )['token'],
                'ap_hp' => '',
            ], $this->db);
            $this->assertTrue($pending['ok'], implode('; ', $pending['errors']));
            AP_Mail::clearTestOutbox();

            $nonce = ap_create_nonce('update-user-' . $pending['id'], $admin['id']);
            $result = AP_Admin_User_Edit::save([
                '_ap_nonce' => $nonce,
                'user_ID' => $pending['id'],
                'ap_resend_verification' => '1',
            ], $admin['id'], 'update', $this->db);

            $this->assertTrue($result['ok'], implode('; ', $result['errors']));
            $this->assertSame('verification_resent', $result['message_key']);
            $outbox = AP_Mail::getTestOutbox();
            $this->assertCount(1, $outbox);
            $this->assertSame('editresend@example.test', $outbox[0]['to']);
            $this->assertStringContainsString(
                'https://forum.example.test/ap-admin/login.php?',
                $outbox[0]['message']
            );
            $this->assertStringContainsString('action=verifyemail', $outbox[0]['message']);
            $this->assertDoesNotMatchRegularExpression(
                '/(?:^|\\s)\\/ap-admin\\/login\\.php/',
                $outbox[0]['message']
            );

            AP_Mail::failNextForTests('SMTP down');
            $nonce2 = ap_create_nonce('update-user-' . $pending['id'], $admin['id']);
            $failed = AP_Admin_User_Edit::save([
                '_ap_nonce' => $nonce2,
                'user_ID' => $pending['id'],
                'ap_resend_verification' => '1',
            ], $admin['id'], 'update', $this->db);
            $this->assertFalse($failed['ok']);
            $this->assertStringContainsString(
                'could not be sent',
                strtolower(implode(' ', $failed['errors']))
            );
            $still = AP_User::getById($pending['id'], $this->db);
            $this->assertNotNull($still);
            $this->assertSame(AP_Registration::STATUS_PENDING, $still->user_status);
        } finally {
            if ($previousScript === null) {
                unset($_SERVER['SCRIPT_NAME']);
            } else {
                $_SERVER['SCRIPT_NAME'] = $previousScript;
            }
        }
    }

    public function testActivatePendingAccountFromUserEdit(): void
    {
        AP_Mail::enableTestMode();
        AP_Options::update('users_can_register', '1', $this->db);
        AP_Options::update('require_email_verification', '1', $this->db);
        AP_Options::update('default_role', 'subscriber', $this->db);
        AP_Options::update('blogname', 'Test Site', $this->db);
        AP_Options::update('siteurl', 'https://example.test', $this->db);

        $admin = AP_User::create([
            'user_login' => 'activateadmin',
            'user_email' => 'activateadmin@example.test',
            'password' => 'password123',
            'role' => 'administrator',
        ], $this->db);
        $pending = AP_Registration::register([
            'user_login' => 'editactivate',
            'user_email' => 'editactivate@example.test',
            'user_pass' => 'securepass8',
            'ap_form_ticket' => AP_Registration::createFormTicket(
                time() - AP_Registration::MIN_FILL_SECONDS
            )['token'],
            'ap_hp' => '',
        ], $this->db);
        $this->assertTrue($pending['ok'], implode('; ', $pending['errors']));
        $this->assertSame(AP_Registration::STATUS_PENDING, $pending['user']->user_status ?? -1);

        $nonce = ap_create_nonce('update-user-' . $pending['id'], $admin['id']);
        $result = AP_Admin_User_Edit::save([
            '_ap_nonce' => $nonce,
            'user_ID' => $pending['id'],
            'ap_activate_account' => '1',
        ], $admin['id'], 'update', $this->db);

        $this->assertTrue($result['ok'], implode('; ', $result['errors']));
        $this->assertSame('user_activated', $result['message_key']);
        $this->assertNotNull($result['user']);
        $this->assertSame(0, $result['user']->user_status);
        $this->assertSame('', $result['user']->user_activation_key);

        $fresh = AP_User::getById($pending['id'], $this->db);
        $this->assertNotNull($fresh);
        $this->assertSame(0, $fresh->user_status);
        $this->assertNotNull(AP_User::authenticate('editactivate', 'securepass8', $this->db));

        $editor = AP_User::create([
            'user_login' => 'activateeditor',
            'user_email' => 'activateeditor@example.test',
            'password' => 'password123',
            'role' => 'editor',
        ], $this->db);
        $pending2 = AP_Registration::register([
            'user_login' => 'editactivate2',
            'user_email' => 'editactivate2@example.test',
            'user_pass' => 'securepass8',
            'ap_form_ticket' => AP_Registration::createFormTicket(
                time() - AP_Registration::MIN_FILL_SECONDS
            )['token'],
            'ap_hp' => '',
        ], $this->db);
        $this->assertTrue($pending2['ok'], implode('; ', $pending2['errors']));
        $nonce2 = ap_create_nonce('update-user-' . $pending2['id'], $editor['id']);
        $denied = AP_Admin_User_Edit::save([
            '_ap_nonce' => $nonce2,
            'user_ID' => $pending2['id'],
            'ap_activate_account' => '1',
        ], $editor['id'], 'update', $this->db);
        $this->assertFalse($denied['ok']);
        $still = AP_User::getById($pending2['id'], $this->db);
        $this->assertNotNull($still);
        $this->assertSame(AP_Registration::STATUS_PENDING, $still->user_status);
    }

    public function testActivatePendingAccountFromUserEditAfterKeyExpires(): void
    {
        AP_Mail::enableTestMode();
        AP_Options::update('users_can_register', '1', $this->db);
        AP_Options::update('require_email_verification', '1', $this->db);
        AP_Options::update('default_role', 'subscriber', $this->db);
        AP_Options::update('blogname', 'Test Site', $this->db);
        AP_Options::update('siteurl', 'https://example.test', $this->db);

        $admin = AP_User::create([
            'user_login' => 'expireadmin',
            'user_email' => 'expireadmin@example.test',
            'password' => 'password123',
            'role' => 'administrator',
        ], $this->db);
        $pending = AP_Registration::register([
            'user_login' => 'expireactivate',
            'user_email' => 'expireactivate@example.test',
            'user_pass' => 'securepass8',
            'ap_form_ticket' => AP_Registration::createFormTicket(
                time() - AP_Registration::MIN_FILL_SECONDS
            )['token'],
            'ap_hp' => '',
        ], $this->db);
        $this->assertTrue($pending['ok'], implode('; ', $pending['errors']));

        $user = AP_User::getById($pending['id'], $this->db);
        $this->assertNotNull($user);
        $parts = explode(':', $user->user_activation_key, 3);
        $this->assertCount(3, $parts);
        $staleTs = time() - AP_Registration::KEY_TTL - 60;
        $this->db->update(
            'users',
            ['user_activation_key' => $parts[0] . ':' . $staleTs . ':' . $parts[2]],
            ['ID' => $pending['id']]
        );

        $stale = AP_User::getById($pending['id'], $this->db);
        $this->assertNotNull($stale);
        $html = AP_Admin_User_Edit::renderForm($stale, 'update', $admin['id'], [], $this->db);
        $this->assertStringContainsString('Activate account', $html);
        $this->assertStringContainsString('name="ap_activate_account"', $html);

        $this->assertFalse(
            AP_Registration::verifyEmail('expireactivate', $pending['plain_key'], $this->db)['ok']
        );

        $nonce = ap_create_nonce('update-user-' . $pending['id'], $admin['id']);
        $result = AP_Admin_User_Edit::save([
            '_ap_nonce' => $nonce,
            'user_ID' => $pending['id'],
            'ap_activate_account' => '1',
        ], $admin['id'], 'update', $this->db);

        $this->assertTrue($result['ok'], implode('; ', $result['errors']));
        $this->assertSame('user_activated', $result['message_key']);
        $fresh = AP_User::getById($pending['id'], $this->db);
        $this->assertNotNull($fresh);
        $this->assertSame(0, $fresh->user_status);
        $this->assertSame('', $fresh->user_activation_key);
        $this->assertNotNull(AP_User::authenticate('expireactivate', 'securepass8', $this->db));
    }

    public function testActivatePendingAccountFromUsersListRow(): void
    {
        AP_Mail::enableTestMode();
        AP_Options::update('users_can_register', '1', $this->db);
        AP_Options::update('require_email_verification', '1', $this->db);
        AP_Options::update('default_role', 'subscriber', $this->db);
        AP_Options::update('blogname', 'Test Site', $this->db);
        AP_Options::update('siteurl', 'https://example.test', $this->db);

        $admin = AP_User::create([
            'user_login' => 'listactadmin',
            'user_email' => 'listactadmin@example.test',
            'password' => 'password123',
            'role' => 'administrator',
        ], $this->db);
        $pending = AP_Registration::register([
            'user_login' => 'listactivate',
            'user_email' => 'listactivate@example.test',
            'user_pass' => 'securepass8',
            'ap_form_ticket' => AP_Registration::createFormTicket(
                time() - AP_Registration::MIN_FILL_SECONDS
            )['token'],
            'ap_hp' => '',
        ], $this->db);
        $this->assertTrue($pending['ok'], implode('; ', $pending['errors']));

        AP_Session::enableTestMode();
        AP_Session::resetCurrentUser();
        $this->assertTrue(AP_Session::setAuthCookie($admin['id'], false, $this->db));

        $table = new AP_Users_List_Table($this->db);
        $table->prepareItems([]);
        $html = $table->render();
        $this->assertStringContainsString('listactivate', $html);
        $this->assertStringContainsString('Pending', $html);
        $this->assertStringContainsString('action=activate', $html);
        $this->assertStringContainsString('Activate</a>', $html);

        $badNonce = $table->processRowAction([
            'action' => 'activate',
            'user' => $pending['id'],
            '_ap_nonce' => 'not-a-nonce',
        ], $admin['id']);
        $this->assertFalse($badNonce['ok']);
        $this->assertSame('nonce', $badNonce['message_key']);
        $stillPending = AP_User::getById($pending['id'], $this->db);
        $this->assertNotNull($stillPending);
        $this->assertSame(AP_Registration::STATUS_PENDING, $stillPending->user_status);

        $nonce = ap_create_nonce('activate-user-' . $pending['id'], $admin['id']);
        $ok = $table->processRowAction([
            'action' => 'activate',
            'user' => $pending['id'],
            '_ap_nonce' => $nonce,
        ], $admin['id']);
        $this->assertTrue($ok['ok'], implode('; ', $ok['errors']));
        $this->assertSame('user_activated', $ok['message_key']);

        $fresh = AP_User::getById($pending['id'], $this->db);
        $this->assertNotNull($fresh);
        $this->assertSame(0, $fresh->user_status);
        $this->assertSame('', $fresh->user_activation_key);
        $this->assertNotNull(AP_User::authenticate('listactivate', 'securepass8', $this->db));

        $table->prepareItems([]);
        $after = $table->render();
        $this->assertStringNotContainsString('action=activate', $after);

        $editor = AP_User::create([
            'user_login' => 'listacteditor',
            'user_email' => 'listacteditor@example.test',
            'password' => 'password123',
            'role' => 'editor',
        ], $this->db);
        $pending2 = AP_Registration::register([
            'user_login' => 'listactivate2',
            'user_email' => 'listactivate2@example.test',
            'user_pass' => 'securepass8',
            'ap_form_ticket' => AP_Registration::createFormTicket(
                time() - AP_Registration::MIN_FILL_SECONDS
            )['token'],
            'ap_hp' => '',
        ], $this->db);
        $this->assertTrue($pending2['ok'], implode('; ', $pending2['errors']));
        $nonce2 = ap_create_nonce('activate-user-' . $pending2['id'], $editor['id']);
        $denied = $table->processRowAction([
            'action' => 'activate',
            'user' => $pending2['id'],
            '_ap_nonce' => $nonce2,
        ], $editor['id']);
        $this->assertFalse($denied['ok']);
        $still = AP_User::getById($pending2['id'], $this->db);
        $this->assertNotNull($still);
        $this->assertSame(AP_Registration::STATUS_PENDING, $still->user_status);
    }

    public function testConsumeQueryNoticeForVerificationResent(): void
    {
        $_GET['message'] = 'verification_resent';
        AP_Admin::consumeQueryNotice();
        $notices = AP_Admin::getNotices();
        $this->assertNotEmpty($notices);
        $this->assertStringContainsString('Verification email sent', $notices[0]['message']);
        unset($_GET['message']);
        AP_Admin::clearNotices();
    }

    public function testConsumeQueryNoticeForUserActivated(): void
    {
        $_GET['message'] = 'user_activated';
        AP_Admin::consumeQueryNotice();
        $notices = AP_Admin::getNotices();
        $this->assertNotEmpty($notices);
        $this->assertStringContainsString('Account activated', $notices[0]['message']);
        unset($_GET['message']);
        AP_Admin::clearNotices();
    }

    public function testProfileForumNotifyCheckboxDefaultOffAndPersists(): void
    {
        $user = AP_User::create([
            'user_login' => 'notifyprof',
            'user_email' => 'notifyprof@example.test',
            'password' => 'password123',
            'role' => 'subscriber',
        ], $this->db);
        $this->assertTrue($user['ok'], implode('; ', $user['errors']));
        $id = (int) $user['id'];
        $u = AP_User::getById($id, $this->db);
        $this->assertNotNull($u);

        $this->assertTrue(AP_Admin_User_Edit::shouldShowForumNotifyFields($this->db));
        $this->assertFalse(AP_Forum_Notify::isUserNotifyEnabled($id, $this->db));

        $html = AP_Admin_User_Edit::renderForm($u, 'profile', $id, [], $this->db);
        $this->assertStringContainsString('name="forum_notify_email"', $html);
        $this->assertStringContainsString('id="forum_notify_email"', $html);
        $this->assertStringContainsString('Email me about topics I subscribe to', $html);
        $this->assertStringContainsString('If this is off, Subscribe (or Notify me of replies) turns it on.', $html);
        $this->assertStringContainsString('Subscriptions', $html);
        $this->assertStringContainsString('No topic subscriptions.', $html);
        $this->assertDoesNotMatchRegularExpression(
            '/id="forum_notify_email"[^>]*checked/',
            $html
        );

        $nonce = ap_create_nonce('update-profile-' . $id, $id);
        $on = AP_Admin_User_Edit::save([
            '_ap_nonce' => $nonce,
            'user_ID' => $id,
            'user_email' => 'notifyprof@example.test',
            'display_name' => 'Notify Prof',
            'forum_notify_email' => '1',
        ], $id, 'profile', $this->db);
        $this->assertTrue($on['ok'], implode('; ', $on['errors']));
        $this->assertTrue(AP_Forum_Notify::isUserNotifyEnabled($id, $this->db));
        $this->assertSame(
            '1',
            AP_User::getMeta($id, AP_Forum_Notify::META_NOTIFY_EMAIL, $this->db)
        );

        $checked = AP_Admin_User_Edit::renderForm(
            AP_User::getById($id, $this->db),
            'profile',
            $id,
            [],
            $this->db
        );
        $this->assertMatchesRegularExpression(
            '/id="forum_notify_email"[^>]*checked/',
            $checked
        );

        $nonceOff = ap_create_nonce('update-profile-' . $id, $id);
        $off = AP_Admin_User_Edit::save([
            '_ap_nonce' => $nonceOff,
            'user_ID' => $id,
            'user_email' => 'notifyprof@example.test',
            'display_name' => 'Notify Prof',
        ], $id, 'profile', $this->db);
        $this->assertTrue($off['ok'], implode('; ', $off['errors']));
        $this->assertFalse(AP_Forum_Notify::isUserNotifyEnabled($id, $this->db));
        $this->assertSame(
            '0',
            AP_User::getMeta($id, AP_Forum_Notify::META_NOTIFY_EMAIL, $this->db)
        );
    }

    public function testCreateFormOmitsForumNotifyFields(): void
    {
        $admin = AP_User::create([
            'user_login' => 'notifycreateadmin',
            'user_email' => 'notifycreateadmin@example.test',
            'password' => 'password123',
            'role' => 'administrator',
        ], $this->db);
        $html = AP_Admin_User_Edit::renderForm(null, 'create', (int) $admin['id'], [], $this->db);
        $this->assertStringNotContainsString('name="forum_notify_email"', $html);
        $this->assertStringNotContainsString('ap_unsubscribe_topic', $html);
        $this->assertStringNotContainsString('Email me about topics I subscribe to', $html);
    }

    public function testForumModuleOffHidesNotifyFieldsAndDoesNotWipeMeta(): void
    {
        $user = AP_User::create([
            'user_login' => 'notifyhidden',
            'user_email' => 'notifyhidden@example.test',
            'password' => 'password123',
            'role' => 'subscriber',
        ], $this->db);
        $this->assertTrue($user['ok'], implode('; ', $user['errors']));
        $id = (int) $user['id'];
        $this->assertTrue(AP_Forum_Notify::setUserNotifyEnabled($id, true, $this->db));

        $this->assertTrue(AP_Options::updateModules([
            'static_pages' => '1',
            'blog' => '1',
            'forum' => '0',
        ], $this->db));
        $this->assertFalse(AP_Options::isModuleEnabled('forum', $this->db));
        $this->assertFalse(AP_Admin_User_Edit::shouldShowForumNotifyFields($this->db));

        $u = AP_User::getById($id, $this->db);
        $this->assertNotNull($u);
        $html = AP_Admin_User_Edit::renderForm($u, 'profile', $id, [], $this->db);
        $this->assertStringNotContainsString('name="forum_notify_email"', $html);
        $this->assertStringNotContainsString('Subscriptions', $html);

        $nonce = ap_create_nonce('update-profile-' . $id, $id);
        $saved = AP_Admin_User_Edit::save([
            '_ap_nonce' => $nonce,
            'user_ID' => $id,
            'user_email' => 'notifyhidden@example.test',
            'display_name' => 'Hidden',
            'forum_notify_email' => '0',
        ], $id, 'profile', $this->db);
        $this->assertTrue($saved['ok'], implode('; ', $saved['errors']));
        $this->assertTrue(AP_Forum_Notify::isUserNotifyEnabled($id, $this->db));

        AP_Options::updateModules([
            'static_pages' => '1',
            'blog' => '1',
            'forum' => '1',
        ], $this->db);
    }

    public function testProfileSubscriptionsListAndUnsubscribe(): void
    {
        $user = AP_User::create([
            'user_login' => 'notifysubs',
            'user_email' => 'notifysubs@example.test',
            'password' => 'password123',
            'role' => 'subscriber',
        ], $this->db);
        $this->assertTrue($user['ok'], implode('; ', $user['errors']));
        $id = (int) $user['id'];
        $this->assertTrue(AP_Forum_Notify::setUserNotifyEnabled($id, true, $this->db));

        $forumId = AP_Forum::insertForum(['forum_name' => 'Notify Profile'], $this->db);
        $this->assertGreaterThan(0, $forumId);
        $keepId = AP_Forum::createTopic([
            'forum_id' => $forumId,
            'topic_title' => 'Keep watching',
            'content' => 'First post',
        ], $this->db);
        $dropId = AP_Forum::createTopic([
            'forum_id' => $forumId,
            'topic_title' => 'Drop watching',
            'content' => 'First post',
        ], $this->db);
        $this->assertGreaterThan(0, $keepId);
        $this->assertGreaterThan(0, $dropId);
        $this->assertTrue(AP_Forum_Notify::subscribe($id, $keepId, $this->db));
        $this->assertTrue(AP_Forum_Notify::subscribe($id, $dropId, $this->db));

        $u = AP_User::getById($id, $this->db);
        $this->assertNotNull($u);
        $html = AP_Admin_User_Edit::renderForm($u, 'profile', $id, [], $this->db);
        $this->assertStringContainsString('Keep watching', $html);
        $this->assertStringContainsString('Drop watching', $html);
        $this->assertStringContainsString('name="ap_unsubscribe_topic"', $html);
        $this->assertStringContainsString('value="' . $dropId . '"', $html);
        $this->assertStringContainsString('Unsubscribe', $html);

        $nonce = ap_create_nonce('update-profile-' . $id, $id);
        $result = AP_Admin_User_Edit::save([
            '_ap_nonce' => $nonce,
            'user_ID' => $id,
            'ap_unsubscribe_topic' => (string) $dropId,
            'forum_notify_email' => '0',
            'user_email' => 'changed-should-not-save@example.test',
        ], $id, 'profile', $this->db);
        $this->assertTrue($result['ok'], implode('; ', $result['errors']));
        $this->assertSame('topic_unsubscribed', $result['message_key']);
        $this->assertFalse(AP_Forum_Notify::isSubscribed($id, $dropId, $this->db));
        $this->assertTrue(AP_Forum_Notify::isSubscribed($id, $keepId, $this->db));
        $this->assertTrue(AP_Forum_Notify::isUserNotifyEnabled($id, $this->db));
        $reloaded = AP_User::getById($id, $this->db);
        $this->assertNotNull($reloaded);
        $this->assertSame('notifysubs@example.test', $reloaded->user_email);

        $_GET['message'] = 'topic_unsubscribed';
        AP_Admin::consumeQueryNotice();
        $notices = AP_Admin::getNotices();
        $this->assertNotEmpty($notices);
        $this->assertStringContainsString('Unsubscribed from the topic', $notices[0]['message']);
        unset($_GET['message']);
        AP_Admin::clearNotices();
    }

    public function testUserEditForumNotifyUsesThirdPersonLabel(): void
    {
        $admin = AP_User::create([
            'user_login' => 'notifyeditadmin',
            'user_email' => 'notifyeditadmin@example.test',
            'password' => 'password123',
            'role' => 'administrator',
        ], $this->db);
        $member = AP_User::create([
            'user_login' => 'notifyeditmember',
            'user_email' => 'notifyeditmember@example.test',
            'password' => 'password123',
            'role' => 'subscriber',
        ], $this->db);
        $target = AP_User::getById((int) $member['id'], $this->db);
        $this->assertNotNull($target);
        $html = AP_Admin_User_Edit::renderForm(
            $target,
            'update',
            (int) $admin['id'],
            [],
            $this->db
        );
        $this->assertStringContainsString('name="forum_notify_email"', $html);
        $this->assertStringContainsString(
            'Email this member about topics they subscribe to',
            $html
        );
        $this->assertStringNotContainsString('Email me about topics I subscribe to', $html);
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

    /**
     * @param mixed $value
     */
    /**
     * @return array{id: int, email: string}
     */
    private function makeProfileUser(string $login, string $email): array
    {
        $user = AP_User::create([
            'user_login' => $login,
            'user_email' => $email,
            'password' => 'password123',
            'role' => 'author',
            'display_name' => $login,
        ], $this->db);
        $this->assertTrue($user['ok'], implode('; ', $user['errors']));

        return ['id' => (int) $user['id'], 'email' => $email];
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return array{ok: bool, id: int, message_key: string, errors: list<string>, user: ?AP_User}
     */
    /**
     * @return list<object>
     */
    private function activityRows(int $targetId): array
    {
        $rows = AP_Activity_Log::forTarget($targetId, $this->db);

        return $rows;
    }

    private function activityCount(): int
    {
        return (int) $this->db->getVar(
            'SELECT COUNT(*) FROM ' . $this->db->quoteIdentifier($this->db->table('activity_log'))
        );
    }

    private function assertActivityRow(
        object $row,
        int $actorId,
        int $targetId,
        string $field,
        string $ip
    ): void {
        $this->assertSame($actorId, (int) $row->actor_id);
        $this->assertSame($targetId, (int) $row->target_id);
        $this->assertSame($field, (string) $row->field_name);
        $this->assertSame($ip, (string) $row->ip);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
            (string) $row->created_at
        );
        $stamp = strtotime((string) $row->created_at . ' UTC');
        $this->assertNotFalse($stamp);
        $this->assertLessThanOrEqual(5, abs(time() - $stamp));
    }

    /**
     * @param list<object> $rows
     * @param list<string> $secrets
     */
    private function assertLogOmits(array $rows, array $secrets): void
    {
        $encoded = json_encode($rows);
        $this->assertIsString($encoded);
        foreach ($secrets as $secret) {
            $this->assertNotSame('', $secret);
            $this->assertStringNotContainsString($secret, $encoded);
        }
    }

    private function saveOtherUser(int $actorId, int $targetId, string $email, array $fields = []): array
    {
        return AP_Admin_User_Edit::save(array_merge([
            '_ap_nonce' => ap_create_nonce('update-user-' . $targetId, $actorId),
            'user_ID' => $targetId,
            'user_email' => $email,
            'pass1' => '',
            'pass2' => '',
            'display_name' => 'Kept',
        ], $fields), $actorId, 'update', $this->db);
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return array{ok: bool, id: int, message_key: string, errors: list<string>, user: ?AP_User}
     */
    private function saveProfile(int $id, string $email, array $fields = []): array
    {
        return AP_Admin_User_Edit::save(array_merge([
            '_ap_nonce' => ap_create_nonce('update-profile-' . $id, $id),
            'user_ID' => $id,
            'user_email' => $email,
            'pass1' => '',
            'pass2' => '',
            'display_name' => 'Kept',
        ], $fields), $id, 'profile', $this->db);
    }

    private function failCurrentPassword(int $id, string $email, string $wrong, string $displayName): void
    {
        $result = $this->saveProfile($id, $email, [
            'display_name' => $displayName,
            'pass1' => 'new-password-99',
            'pass2' => 'new-password-99',
            'current_password' => $wrong,
        ]);
        $this->assertFalse($result['ok']);
        $this->assertSame(
            [AP_Admin_User_Edit::NOTICE_CURRENT_PASSWORD_INCORRECT],
            $result['errors']
        );
    }

    private function currentPasswordAttempts(int $userId, string $bucket = ''): int
    {
        if ($bucket === '') {
            $bucket = AP_Rate_Limit::userBucket($userId);
        }
        $state = AP_Rate_Limit::check(AP_Rate_Limit::ACTION_CURRENT_PASSWORD, $bucket, $this->db);

        return (int) $state['attempts'];
    }

    private function ageCurrentPasswordBucket(string $bucket): void
    {
        $method = new \ReflectionMethod(AP_Rate_Limit::class, 'transientName');
        $name = $method->invoke(null, AP_Rate_Limit::ACTION_CURRENT_PASSWORD, $bucket);
        $this->assertIsString($name);
        $raw = AP_Transient::get($name, false, $this->db);
        $this->assertIsArray($raw);
        $raw['window_start'] = time() - 1000;
        $raw['locked_until'] = time() - 1;
        $this->assertTrue(AP_Transient::set($name, $raw, 3600, $this->db));
    }

    private function encodeResult(mixed $value): string
    {
        $encoded = json_encode($value);
        $this->assertIsString($encoded);

        return $encoded;
    }
}
