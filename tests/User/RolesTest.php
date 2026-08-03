<?php

/**
 * Tests for AP_Roles — role registry, assignment, and capability checks.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\User;

use AP_DB;
use AP_Migrator;
use AP_Options;
use AP_Post;
use AP_Roles;
use AP_User;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_Roles::class)]
final class RolesTest extends TestCase
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
        require_once $this->root . '/ap-includes/class-ap-post.php';
        require_once $this->root . '/ap-includes/class-ap-roles.php';
        require_once $this->root . '/ap-includes/functions.php';

        AP_Roles::flushCache();
        AP_Options::flushCache();
        AP_Post::resetRegistry();

        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');

        $migrator = new AP_Migrator($this->db, AP_Migrator::defaultMigrationsPath());
        $migrator->migrate();

        AP_Post::ensureBuiltins();
        AP_Roles::ensureDefaults($this->db);
    }

    protected function tearDown(): void
    {
        AP_Roles::flushCache();
        AP_Options::flushCache();
        AP_Post::resetRegistry();
    }

    public function testDefaultRolesSeeded(): void
    {
        $roles = AP_Roles::getRoles($this->db);
        foreach (['administrator', 'editor', 'author', 'contributor', 'subscriber'] as $slug) {
            $this->assertArrayHasKey($slug, $roles, "Missing role {$slug}");
            $this->assertNotSame('', $roles[$slug]['name']);
            $this->assertIsArray($roles[$slug]['capabilities']);
        }

        $this->assertTrue(AP_Roles::roleExists('administrator', $this->db));
        $this->assertFalse(AP_Roles::roleExists('nosuchrole', $this->db));

        $admin = AP_Roles::getRole('administrator', $this->db);
        $this->assertNotNull($admin);
        $this->assertTrue($admin['capabilities']['manage_options'] ?? false);
        $this->assertTrue($admin['capabilities']['edit_posts'] ?? false);

        $sub = AP_Roles::getRole('subscriber', $this->db);
        $this->assertNotNull($sub);
        $this->assertTrue($sub['capabilities']['read'] ?? false);
        $this->assertFalse($sub['capabilities']['edit_posts'] ?? false);

        $names = AP_Roles::getRoleNames($this->db);
        $this->assertSame('Administrator', $names['administrator']);
    }

    public function testEnsureDefaultsIsIdempotent(): void
    {
        AP_Roles::addCap('subscriber', 'custom_cap', true, $this->db);
        AP_Roles::flushCache();
        AP_Options::flushCache();
        AP_Roles::ensureDefaults($this->db);

        $sub = AP_Roles::getRole('subscriber', $this->db);
        $this->assertNotNull($sub);
        $this->assertTrue($sub['capabilities']['custom_cap'] ?? false);
    }

    public function testAddAndRemoveCustomRole(): void
    {
        $this->assertTrue(AP_Roles::addRole('moderator', 'Moderator', [
            'read' => true,
            'moderate_comments' => true,
            'edit_posts' => true,
        ], $this->db));
        $this->assertFalse(AP_Roles::addRole('moderator', 'Dup', [], $this->db));

        $role = AP_Roles::getRole('moderator', $this->db);
        $this->assertNotNull($role);
        $this->assertSame('Moderator', $role['name']);
        $this->assertTrue($role['capabilities']['moderate_comments']);

        $this->assertTrue(AP_Roles::addCap('moderator', 'upload_files', true, $this->db));
        $role = AP_Roles::getRole('moderator', $this->db);
        $this->assertTrue($role['capabilities']['upload_files'] ?? false);

        $this->assertTrue(AP_Roles::removeCap('moderator', 'upload_files', $this->db));
        $role = AP_Roles::getRole('moderator', $this->db);
        $this->assertArrayNotHasKey('upload_files', $role['capabilities']);

        $this->assertTrue(AP_Roles::removeRole('moderator', $this->db));
        $this->assertNull(AP_Roles::getRole('moderator', $this->db));

        // Cannot remove administrator.
        $this->assertFalse(AP_Roles::removeRole('administrator', $this->db));
        $this->assertTrue(AP_Roles::roleExists('administrator', $this->db));
    }

    public function testSetUserRoleAndCapabilityChecks(): void
    {
        $adminId = $this->createUser('adminuser');
        $authorId = $this->createUser('authoruser');
        $subId = $this->createUser('subuser');

        $this->assertTrue(AP_Roles::setUserRole($adminId, 'administrator', $this->db));
        $this->assertTrue(AP_Roles::setUserRole($authorId, 'author', $this->db));
        $this->assertTrue(AP_Roles::setUserRole($subId, 'subscriber', $this->db));

        $this->assertSame(['administrator'], AP_Roles::getUserRoles($adminId, $this->db));
        $this->assertSame('author', AP_Roles::getUserRole($authorId, $this->db));

        $this->assertTrue(AP_Roles::userCan($adminId, 'manage_options', null, $this->db));
        $this->assertTrue(AP_Roles::userCan($adminId, 'edit_posts', null, $this->db));
        $this->assertTrue(AP_Roles::userCan($adminId, 'moderate_forums', null, $this->db));

        $this->assertTrue(AP_Roles::userCan($authorId, 'publish_posts', null, $this->db));
        $this->assertTrue(AP_Roles::userCan($authorId, 'upload_files', null, $this->db));
        $this->assertFalse(AP_Roles::userCan($authorId, 'edit_others_posts', null, $this->db));
        $this->assertFalse(AP_Roles::userCan($authorId, 'manage_options', null, $this->db));

        $this->assertTrue(AP_Roles::userCan($subId, 'read', null, $this->db));
        $this->assertFalse(AP_Roles::userCan($subId, 'edit_posts', null, $this->db));
        $this->assertFalse(AP_Roles::userCan(0, 'read', null, $this->db));
    }

    public function testAddUserRoleAndDirectCaps(): void
    {
        $id = $this->createUser('multirole');
        $this->assertTrue(AP_Roles::setUserRole($id, 'subscriber', $this->db));
        $this->assertTrue(AP_Roles::addUserRole($id, 'author', $this->db));

        $roles = AP_Roles::getUserRoles($id, $this->db);
        $this->assertContains('subscriber', $roles);
        $this->assertContains('author', $roles);
        $this->assertTrue(AP_Roles::userCan($id, 'publish_posts', null, $this->db));

        $this->assertTrue(AP_Roles::removeUserRole($id, 'author', $this->db));
        $this->assertFalse(AP_Roles::userCan($id, 'publish_posts', null, $this->db));

        $this->assertTrue(AP_Roles::addUserCap($id, 'upload_files', true, $this->db));
        $this->assertTrue(AP_Roles::userCan($id, 'upload_files', null, $this->db));
        $this->assertTrue(AP_Roles::removeUserCap($id, 'upload_files', $this->db));
        $this->assertFalse(AP_Roles::userCan($id, 'upload_files', null, $this->db));
    }

    public function testMetaCapEditOwnVsOthersPost(): void
    {
        $authorId = $this->createUser('postauthor');
        $editorId = $this->createUser('posteditor');
        $otherId = $this->createUser('otherauthor');

        AP_Roles::setUserRole($authorId, 'author', $this->db);
        AP_Roles::setUserRole($editorId, 'editor', $this->db);
        AP_Roles::setUserRole($otherId, 'author', $this->db);

        $ownPost = AP_Post::insert([
            'post_title' => 'Own Post',
            'post_content' => 'Hello',
            'post_status' => 'publish',
            'post_type' => 'post',
            'post_author' => $authorId,
        ], $this->db);
        $this->assertGreaterThan(0, $ownPost);

        $otherPost = AP_Post::insert([
            'post_title' => 'Other Post',
            'post_content' => 'There',
            'post_status' => 'publish',
            'post_type' => 'post',
            'post_author' => $otherId,
        ], $this->db);
        $this->assertGreaterThan(0, $otherPost);

        // Author can edit own published post, not others'.
        $this->assertTrue(AP_Roles::userCan($authorId, 'edit_post', $ownPost, $this->db));
        $this->assertFalse(AP_Roles::userCan($authorId, 'edit_post', $otherPost, $this->db));
        $this->assertTrue(AP_Roles::userCan($authorId, 'delete_post', $ownPost, $this->db));
        $this->assertFalse(AP_Roles::userCan($authorId, 'delete_post', $otherPost, $this->db));

        // Editor can edit others' posts.
        $this->assertTrue(AP_Roles::userCan($editorId, 'edit_post', $otherPost, $this->db));
        $this->assertTrue(AP_Roles::userCan($editorId, 'delete_post', $otherPost, $this->db));

        $mapped = AP_Roles::mapMetaCap('edit_post', $authorId, $ownPost, $this->db);
        $this->assertContains('edit_published_posts', $mapped);
    }

    public function testMetaCapPrivatePost(): void
    {
        $authorId = $this->createUser('privauthor');
        $subId = $this->createUser('privsub');
        AP_Roles::setUserRole($authorId, 'author', $this->db);
        AP_Roles::setUserRole($subId, 'subscriber', $this->db);

        // Authors lack read_private_posts; editors/admins have it.
        $postId = AP_Post::insert([
            'post_title' => 'Secret',
            'post_content' => 'Hidden',
            'post_status' => 'private',
            'post_type' => 'post',
            'post_author' => $authorId,
        ], $this->db);
        $this->assertGreaterThan(0, $postId);

        // Owner can read own private post via 'read'.
        $this->assertTrue(AP_Roles::userCan($authorId, 'read_post', $postId, $this->db));
        // Subscriber cannot.
        $this->assertFalse(AP_Roles::userCan($subId, 'read_post', $postId, $this->db));

        $editorId = $this->createUser('priveditor');
        AP_Roles::setUserRole($editorId, 'editor', $this->db);
        $this->assertTrue(AP_Roles::userCan($editorId, 'read_post', $postId, $this->db));
        $this->assertTrue(AP_Roles::userCan($editorId, 'edit_post', $postId, $this->db));
    }

    public function testProceduralHelpers(): void
    {
        $this->assertTrue(function_exists('ap_user_can'));
        $this->assertTrue(function_exists('ap_current_user_can'));
        $this->assertTrue(function_exists('ap_get_roles'));
        $this->assertTrue(function_exists('ap_set_user_role'));
        $this->assertTrue(function_exists('ap_map_meta_cap'));

        ap_ensure_roles($this->db);
        $this->assertArrayHasKey('editor', ap_get_roles($this->db));

        $id = $this->createUser('procuser');
        $this->assertTrue(ap_set_user_role($id, 'contributor', $this->db));
        $this->assertSame('contributor', ap_get_user_role($id, $this->db));
        $this->assertTrue(ap_user_can($id, 'edit_posts', null, $this->db));
        $this->assertFalse(ap_user_can($id, 'publish_posts', null, $this->db));
        $this->assertSame(['edit_posts'], ap_map_meta_cap('edit_posts', $id, null, $this->db));
    }

    public function testInstallerSeedsRolesAndAdmin(): void
    {
        require_once $this->root . '/ap-includes/class-ap-installer.php';
        require_once $this->root . '/ap-includes/class-ap-taxonomy.php';

        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');
        $migrator = new AP_Migrator($db, AP_Migrator::defaultMigrationsPath());
        $migrator->migrate();

        AP_Roles::flushCache();
        AP_Options::flushCache();

        \AP_Installer::seedOptions($db, [
            'title' => 'Roles Site',
            'url' => 'https://example.test',
        ], [
            'username' => 'siteadmin',
            'email' => 'admin@example.test',
            'password' => 'secret-pass-99',
        ]);

        $adminId = \AP_Installer::seedAdminUser($db, [
            'username' => 'siteadmin',
            'email' => 'admin@example.test',
            'password' => 'secret-pass-99',
        ]);
        $this->assertGreaterThan(0, $adminId);

        AP_Roles::flushCache();
        $this->assertTrue(AP_Roles::roleExists('administrator', $db));
        $this->assertSame(['administrator'], AP_Roles::getUserRoles($adminId, $db));
        $this->assertTrue(AP_Roles::userCan($adminId, 'manage_options', null, $db));
        $this->assertTrue(AP_Roles::userCan($adminId, 'edit_theme_options', null, $db));

        $defaultRole = $db->getVar(
            'SELECT option_value FROM ' . $db->quoteIdentifier($db->table('options'))
            . ' WHERE option_name = ?',
            ['default_role']
        );
        $this->assertSame('subscriber', $defaultRole);

        $level = $db->getVar(
            'SELECT meta_value FROM ' . $db->quoteIdentifier($db->table('usermeta'))
            . ' WHERE user_id = ? AND meta_key = ?',
            [$adminId, 'ap_user_level']
        );
        $this->assertSame('10', (string) $level);
    }

    public function testUserLevelMetaWritten(): void
    {
        $id = $this->createUser('leveluser');
        AP_Roles::setUserRole($id, 'editor', $this->db);
        $level = $this->db->getVar(
            'SELECT meta_value FROM ' . $this->db->quoteIdentifier($this->db->table('usermeta'))
            . ' WHERE user_id = ? AND meta_key = ?',
            [$id, AP_Roles::META_USER_LEVEL]
        );
        $this->assertSame('7', (string) $level);
    }

    public function testBootstrapLoadsRoles(): void
    {
        $configPath = $this->root . '/ap-config.php';
        $created = false;

        if (!is_readable($configPath)) {
            $sample = $this->root . '/ap-config-sample.php';
            $this->assertFileIsReadable($sample);
            $this->assertTrue(copy($sample, $configPath));
            $created = true;
        }

        $tmpScript = sys_get_temp_dir() . '/aproles-bootstrap-' . uniqid('', true) . '.php';

        try {
            $root = $this->root . '/';
            $code = "<?php\ndeclare(strict_types=1);\n"
                . "define('AP_ABSPATH', " . var_export($root, true) . ");\n"
                . "require AP_ABSPATH . 'ap-includes/bootstrap.php';\n"
                . "ap_bootstrap();\n"
                . "echo class_exists('AP_Roles', false) ? \"ROLES_OK\\n\" : \"ROLES_MISSING\\n\";\n"
                . "echo function_exists('ap_user_can') ? \"CAN_FN_OK\\n\" : \"CAN_FN_MISSING\\n\";\n"
                . "echo function_exists('ap_current_user_can') ? \"CUR_FN_OK\\n\" : \"CUR_FN_MISSING\\n\";\n";
            file_put_contents($tmpScript, $code);

            $php = PHP_BINARY !== '' ? PHP_BINARY : 'php';
            $cmd = escapeshellarg($php)
                . ' -d display_errors=1 -d error_reporting=E_ALL '
                . escapeshellarg($tmpScript)
                . ' 2>&1';

            $output = [];
            $exit = 0;
            exec($cmd, $output, $exit);
            $body = implode("\n", $output);

            $this->assertSame(0, $exit, $body);
            $this->assertStringContainsString('ROLES_OK', $body);
            $this->assertStringContainsString('CAN_FN_OK', $body);
            $this->assertStringContainsString('CUR_FN_OK', $body);
        } finally {
            if (is_file($tmpScript)) {
                unlink($tmpScript);
            }
            if ($created && is_file($configPath)) {
                unlink($configPath);
            }
        }
    }

    public function testLegacySerializedCapabilitiesMeta(): void
    {
        $id = $this->createUser('legacy');
        // Classic installer format without going through setUserRole.
        $this->db->insert('usermeta', [
            'user_id' => $id,
            'meta_key' => 'ap_capabilities',
            'meta_value' => serialize(['editor' => true]),
        ]);
        AP_Roles::flushCache();

        $this->assertSame(['editor'], AP_Roles::getUserRoles($id, $this->db));
        $this->assertTrue(AP_Roles::userCan($id, 'edit_others_posts', null, $this->db));
    }

    private function createUser(string $login): int
    {
        $hash = AP_User::hashPassword('pass-' . $login);
        $ok = $this->db->insert('users', [
            'user_login' => $login,
            'user_pass' => $hash,
            'user_nicename' => $login,
            'user_email' => $login . '@example.test',
            'user_url' => '',
            'user_registered' => gmdate('Y-m-d H:i:s'),
            'user_activation_key' => '',
            'user_status' => 0,
            'display_name' => $login,
        ]);
        $this->assertNotFalse($ok);
        $id = (int) $this->db->lastInsertId();
        $this->assertGreaterThan(0, $id);

        return $id;
    }
}
