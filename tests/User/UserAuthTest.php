<?php

/**
 * Tests for AP_User — Argon2id hashing and basic authentication.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\User;

use AP_DB;
use AP_Migrator;
use AP_User;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_User::class)]
final class UserAuthTest extends TestCase
{
    private string $root;

    private AP_DB $db;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        require_once $this->root . '/ap-includes/version.php';
        require_once $this->root . '/ap-includes/class-ap-db.php';
        require_once $this->root . '/ap-includes/class-ap-migrator.php';
        require_once $this->root . '/ap-includes/class-ap-user.php';
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

    public function testHashPasswordUsesArgon2idWhenAvailable(): void
    {
        $hash = AP_User::hashPassword('correct horse battery staple');
        $this->assertNotSame('', $hash);
        $this->assertTrue(password_verify('correct horse battery staple', $hash));
        $this->assertFalse(password_verify('wrong', $hash));

        if (defined('PASSWORD_ARGON2ID')) {
            $this->assertStringContainsString('argon2', strtolower($hash));
            $info = password_get_info($hash);
            $this->assertSame('argon2id', $info['algoName'] ?? '');
        }
    }

    public function testCheckPasswordRejectsEmpty(): void
    {
        $hash = AP_User::hashPassword('notempty');
        $this->assertFalse(AP_User::checkPassword('', $hash));
        $this->assertFalse(AP_User::checkPassword('notempty', ''));
        $this->assertFalse(AP_User::checkPassword('', ''));
    }

    public function testProceduralPasswordHelpers(): void
    {
        $this->assertTrue(function_exists('ap_hash_password'));
        $this->assertTrue(function_exists('ap_check_password'));
        $this->assertTrue(function_exists('ap_password_needs_rehash'));
        $this->assertTrue(function_exists('ap_authenticate'));
        $this->assertTrue(function_exists('ap_get_user_by'));

        $hash = ap_hash_password('proc-pass-99');
        $this->assertTrue(ap_check_password('proc-pass-99', $hash));
        $this->assertFalse(ap_check_password('nope', $hash));
        $this->assertIsBool(ap_password_needs_rehash($hash));
    }

    public function testAuthenticateByLoginAndEmail(): void
    {
        $password = 'admin-secret-42';
        $hash = AP_User::hashPassword($password);
        $this->db->insert('users', [
            'user_login' => 'siteadmin',
            'user_pass' => $hash,
            'user_nicename' => 'siteadmin',
            'user_email' => 'admin@example.test',
            'user_url' => '',
            'user_registered' => gmdate('Y-m-d H:i:s'),
            'user_activation_key' => '',
            'user_status' => 0,
            'display_name' => 'Site Admin',
        ]);
        $id = (int) $this->db->lastInsertId();
        $this->assertGreaterThan(0, $id);

        $byLogin = AP_User::authenticate('siteadmin', $password, $this->db);
        $this->assertInstanceOf(AP_User::class, $byLogin);
        $this->assertSame($id, $byLogin->ID);
        $this->assertSame('siteadmin', $byLogin->user_login);
        $this->assertSame('admin@example.test', $byLogin->user_email);
        // Password hash must not leak into public export.
        $public = $byLogin->toPublicArray();
        $this->assertArrayNotHasKey('user_pass', $public);
        $this->assertSame($id, $public['ID']);

        $byEmail = AP_User::authenticate('admin@example.test', $password, $this->db);
        $this->assertInstanceOf(AP_User::class, $byEmail);
        $this->assertSame($id, $byEmail->ID);

        $viaHelper = ap_authenticate('siteadmin', $password, $this->db);
        $this->assertInstanceOf(AP_User::class, $viaHelper);
        $this->assertSame($id, $viaHelper->ID);
    }

    public function testAuthenticateRejectsWrongPasswordAndUnknownUser(): void
    {
        $hash = AP_User::hashPassword('right-pass');
        $this->db->insert('users', [
            'user_login' => 'bob',
            'user_pass' => $hash,
            'user_nicename' => 'bob',
            'user_email' => 'bob@example.test',
            'user_url' => '',
            'user_registered' => gmdate('Y-m-d H:i:s'),
            'user_activation_key' => '',
            'user_status' => 0,
            'display_name' => 'Bob',
        ]);

        $this->assertNull(AP_User::authenticate('bob', 'wrong-pass', $this->db));
        $this->assertNull(AP_User::authenticate('nobody', 'right-pass', $this->db));
        $this->assertNull(AP_User::authenticate('', 'right-pass', $this->db));
        $this->assertNull(AP_User::authenticate('bob', '', $this->db));
    }

    public function testAuthenticateRejectsInactiveUser(): void
    {
        $password = 'still-valid-pass';
        $hash = AP_User::hashPassword($password);
        $this->db->insert('users', [
            'user_login' => 'banned',
            'user_pass' => $hash,
            'user_nicename' => 'banned',
            'user_email' => 'banned@example.test',
            'user_url' => '',
            'user_registered' => gmdate('Y-m-d H:i:s'),
            'user_activation_key' => '',
            'user_status' => 1,
            'display_name' => 'Banned',
        ]);

        $this->assertNull(AP_User::authenticate('banned', $password, $this->db));
        // Password itself is still correct on the row.
        $user = AP_User::getByLogin('banned', $this->db);
        $this->assertNotNull($user);
        $this->assertTrue($user->verifyPassword($password));
    }

    public function testGetByIdLoginEmailAndSlug(): void
    {
        $hash = AP_User::hashPassword('lookup-pass');
        $this->db->insert('users', [
            'user_login' => 'alice',
            'user_pass' => $hash,
            'user_nicename' => 'alice-slug',
            'user_email' => 'alice@example.test',
            'user_url' => 'https://example.test',
            'user_registered' => gmdate('Y-m-d H:i:s'),
            'user_activation_key' => '',
            'user_status' => 0,
            'display_name' => 'Alice',
        ]);
        $id = (int) $this->db->lastInsertId();

        $byId = AP_User::getById($id, $this->db);
        $this->assertNotNull($byId);
        $this->assertSame('alice', $byId->user_login);

        $this->assertSame($id, AP_User::getByLogin('alice', $this->db)?->ID);
        $this->assertSame($id, AP_User::getByEmail('alice@example.test', $this->db)?->ID);
        $this->assertSame($id, AP_User::getByNicename('alice-slug', $this->db)?->ID);
        $this->assertSame($id, AP_User::getBy('id', $id, $this->db)?->ID);
        $this->assertSame($id, ap_get_user_by('login', 'alice', $this->db)?->ID);
        $this->assertNull(AP_User::getById(0, $this->db));
        $this->assertNull(AP_User::getByLogin('missing', $this->db));
    }

    public function testUpdatePasswordAndRehashPath(): void
    {
        // Store a bcrypt hash (PASSWORD_BCRYPT) so Argon2id runtimes need rehash.
        $plain = 'legacy-password-1';
        $legacy = password_hash($plain, PASSWORD_BCRYPT);
        $this->assertIsString($legacy);

        $this->db->insert('users', [
            'user_login' => 'legacy',
            'user_pass' => $legacy,
            'user_nicename' => 'legacy',
            'user_email' => 'legacy@example.test',
            'user_url' => '',
            'user_registered' => gmdate('Y-m-d H:i:s'),
            'user_activation_key' => '',
            'user_status' => 0,
            'display_name' => 'Legacy',
        ]);
        $id = (int) $this->db->lastInsertId();

        if (defined('PASSWORD_ARGON2ID')) {
            $this->assertTrue(AP_User::passwordNeedsRehash($legacy));
        }

        $user = AP_User::authenticate('legacy', $plain, $this->db);
        $this->assertInstanceOf(AP_User::class, $user);
        $this->assertSame($id, $user->ID);

        // After successful auth, hash should match preferred algo when Argon2id exists.
        $fresh = AP_User::getById($id, $this->db);
        $this->assertNotNull($fresh);
        $this->assertTrue($fresh->verifyPassword($plain));
        if (defined('PASSWORD_ARGON2ID')) {
            $this->assertStringContainsString('argon2', strtolower($fresh->user_pass));
            $this->assertFalse(AP_User::passwordNeedsRehash($fresh->user_pass));
        }

        // Explicit password change.
        $this->assertTrue($fresh->updatePassword('brand-new-pass', $this->db));
        $this->assertNull(AP_User::authenticate('legacy', $plain, $this->db));
        $this->assertNotNull(AP_User::authenticate('legacy', 'brand-new-pass', $this->db));
    }

    public function testInstallerHashPasswordDelegatesToUser(): void
    {
        require_once $this->root . '/ap-includes/class-ap-installer.php';
        $hash = \AP_Installer::hashPassword('installer-delegated');
        $this->assertTrue(AP_User::checkPassword('installer-delegated', $hash));
        if (defined('PASSWORD_ARGON2ID')) {
            $this->assertStringContainsString('argon2', strtolower($hash));
        }
    }

    public function testBootstrapLoadsUserAuth(): void
    {
        $configPath = $this->root . '/ap-config.php';
        $created = false;

        if (!is_readable($configPath)) {
            $sample = $this->root . '/ap-config-sample.php';
            $this->assertFileIsReadable($sample);
            $this->assertTrue(copy($sample, $configPath));
            $created = true;
        }

        $tmpScript = sys_get_temp_dir() . '/apuser-bootstrap-' . uniqid('', true) . '.php';

        try {
            $root = $this->root . '/';
            $code = "<?php\ndeclare(strict_types=1);\n"
                . "define('AP_ABSPATH', " . var_export($root, true) . ");\n"
                . "require AP_ABSPATH . 'ap-includes/bootstrap.php';\n"
                . "ap_bootstrap();\n"
                . "echo class_exists('AP_User', false) ? \"USER_OK\\n\" : \"USER_MISSING\\n\";\n"
                . "echo function_exists('ap_authenticate') ? \"AUTH_FN_OK\\n\" : \"AUTH_FN_MISSING\\n\";\n"
                . "echo function_exists('ap_hash_password') ? \"HASH_FN_OK\\n\" : \"HASH_FN_MISSING\\n\";\n"
                . "\$h = AP_User::hashPassword('bootstrap-check');\n"
                . "echo (AP_User::checkPassword('bootstrap-check', \$h) ? \"HASH_OK\\n\" : \"HASH_FAIL\\n\");\n";
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
            $this->assertStringContainsString('USER_OK', $body);
            $this->assertStringContainsString('AUTH_FN_OK', $body);
            $this->assertStringContainsString('HASH_FN_OK', $body);
            $this->assertStringContainsString('HASH_OK', $body);
        } finally {
            if (is_file($tmpScript)) {
                unlink($tmpScript);
            }
            if ($created && is_file($configPath)) {
                unlink($configPath);
            }
        }
    }
}
