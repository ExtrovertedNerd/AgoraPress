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

    /** @var list<array{id: int, login: string, email: string, status: int}> */
    private array $userCreated = [];

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

    protected function tearDown(): void
    {
        if (function_exists('ap_reset_hooks')) {
            ap_reset_hooks();
        }
    }

    private function listenForUserCreated(): void
    {
        require_once $this->root . '/ap-includes/hooks.php';
        if (function_exists('ap_reset_hooks')) {
            ap_reset_hooks();
        }

        $this->userCreated = [];
        ap_add_action(
            'ap_user_created',
            function (int $id, string $login, string $email, int $status): void {
                $this->userCreated[] = [
                    'id' => $id,
                    'login' => $login,
                    'email' => $email,
                    'status' => $status,
                ];
            },
            10,
            4
        );
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
        $this->assertSame($id, AP_User::getByLogin('Alice', $this->db)?->ID);
        $this->assertSame($id, AP_User::getByEmail('alice@example.test', $this->db)?->ID);
        $this->assertSame($id, AP_User::getByEmail('Alice@example.test', $this->db)?->ID);
        $this->assertSame($id, AP_User::getByNicename('alice-slug', $this->db)?->ID);
        $this->assertSame($id, AP_User::getBy('id', $id, $this->db)?->ID);
        $this->assertSame($id, ap_get_user_by('login', 'alice', $this->db)?->ID);
        $this->assertNull(AP_User::getById(0, $this->db));
        $this->assertNull(AP_User::getByLogin('missing', $this->db));
    }

    public function testCreateRejectsCaseVariantLoginAndKeepsEmailUnique(): void
    {
        $first = AP_User::create([
            'user_login' => 'Silas',
            'user_email' => 'silas@example.test',
            'user_pass' => 'securepass0',
        ], $this->db);
        $this->assertTrue($first['ok'], implode('; ', $first['errors']));
        $this->assertNotNull($first['user']);
        $this->assertSame('Silas', $first['user']->user_login);
        $this->assertSame('silas@example.test', $first['user']->user_email);

        $dupLogin = AP_User::create([
            'user_login' => 'silas',
            'user_email' => 'silas-other@example.test',
            'user_pass' => 'securepass0',
        ], $this->db);
        $this->assertFalse($dupLogin['ok']);
        $this->assertContains('That username is already registered.', $dupLogin['errors']);
        $this->assertNull(AP_User::getByEmail('silas-other@example.test', $this->db));

        $dupEmail = AP_User::create([
            'user_login' => 'silas2',
            'user_email' => 'Silas@example.test',
            'user_pass' => 'securepass0',
        ], $this->db);
        $this->assertFalse($dupEmail['ok']);
        $this->assertContains('That email address is already registered.', $dupEmail['errors']);
        $this->assertNull(AP_User::getByLogin('silas2', $this->db));

        $this->assertSame($first['id'], AP_User::getByLogin('silas', $this->db)?->ID);
        $this->assertSame($first['id'], AP_User::getByLogin('SILAS', $this->db)?->ID);
        $this->assertSame($first['id'], AP_User::getByEmail('SILAS@example.test', $this->db)?->ID);

        $auth = AP_User::authenticate('SILAS', 'securepass0', $this->db);
        $this->assertInstanceOf(AP_User::class, $auth);
        $this->assertSame($first['id'], $auth->ID);
        $this->assertSame('Silas', $auth->user_login);

        $other = AP_User::create([
            'user_login' => 'othercase',
            'user_email' => 'othercase@example.test',
            'user_pass' => 'securepass0',
        ], $this->db);
        $this->assertTrue($other['ok'], implode('; ', $other['errors']));
        $upd = AP_User::update((int) $other['id'], [
            'user_email' => 'SILAS@example.test',
        ], $this->db);
        $this->assertFalse($upd['ok']);
        $this->assertContains('That email address is already registered.', $upd['errors']);
        $this->assertSame('othercase@example.test', AP_User::getById((int) $other['id'], $this->db)?->user_email);
    }

    public function testCreateFiresUserCreatedAction(): void
    {
        $this->listenForUserCreated();

        $created = AP_User::create([
            'user_login' => 'hookuser',
            'user_email' => 'hookuser@example.test',
            'user_pass' => 'securepass0',
        ], $this->db);
        $this->assertTrue($created['ok'], implode('; ', $created['errors']));
        $this->assertCount(1, $this->userCreated);
        $this->assertSame($created['id'], $this->userCreated[0]['id']);
        $this->assertSame('hookuser', $this->userCreated[0]['login']);
        $this->assertSame('hookuser@example.test', $this->userCreated[0]['email']);
        $this->assertSame(0, $this->userCreated[0]['status']);
        $this->assertSame(1, ap_did_action('ap_user_created'));
    }

    public function testCreateFiresUserCreatedActionForPendingStatus(): void
    {
        require_once $this->root . '/ap-includes/class-ap-registration.php';
        $this->listenForUserCreated();

        $created = AP_User::create([
            'user_login' => 'pendinguser',
            'user_email' => 'pendinguser@example.test',
            'user_pass' => 'securepass0',
            'user_status' => \AP_Registration::STATUS_PENDING,
        ], $this->db);
        $this->assertTrue($created['ok'], implode('; ', $created['errors']));
        $this->assertSame(\AP_Registration::STATUS_PENDING, $created['user']->user_status ?? -1);
        $this->assertCount(1, $this->userCreated);
        $this->assertSame($created['id'], $this->userCreated[0]['id']);
        $this->assertSame('pendinguser', $this->userCreated[0]['login']);
        $this->assertSame('pendinguser@example.test', $this->userCreated[0]['email']);
        $this->assertSame(\AP_Registration::STATUS_PENDING, $this->userCreated[0]['status']);
    }

    public function testCreateDoesNotFireUserCreatedOnValidationFailure(): void
    {
        $this->listenForUserCreated();

        $failed = AP_User::create([
            'user_login' => 'nope',
            'user_email' => 'not-an-email',
            'user_pass' => 'short',
        ], $this->db);
        $this->assertFalse($failed['ok']);
        $this->assertSame([], $this->userCreated);
        $this->assertSame(0, ap_did_action('ap_user_created'));
    }

    public function testLoginUniquenessDoesNotMergeLegacyCaseCollisions(): void
    {
        $hash = AP_User::hashPassword('securepass0');
        $now = gmdate('Y-m-d H:i:s');
        $this->db->insert('users', [
            'user_login' => 'Silas',
            'user_pass' => $hash,
            'user_nicename' => 'silas',
            'user_email' => 'silas-legacy-a@example.test',
            'user_url' => '',
            'user_registered' => $now,
            'user_activation_key' => '',
            'user_status' => 0,
            'display_name' => 'Silas',
        ]);
        $idA = (int) $this->db->lastInsertId();
        $this->db->insert('users', [
            'user_login' => 'silas',
            'user_pass' => $hash,
            'user_nicename' => 'silas-2',
            'user_email' => 'silas-legacy-b@example.test',
            'user_url' => '',
            'user_registered' => $now,
            'user_activation_key' => '',
            'user_status' => 0,
            'display_name' => 'silas',
        ]);
        $idB = (int) $this->db->lastInsertId();
        $this->assertGreaterThan(0, $idA);
        $this->assertGreaterThan(0, $idB);
        $this->assertNotSame($idA, $idB);

        $this->assertSame('Silas', AP_User::getById($idA, $this->db)?->user_login);
        $this->assertSame('silas', AP_User::getById($idB, $this->db)?->user_login);
        $this->assertSame($idA, AP_User::getByLogin('Silas', $this->db)?->ID);
        $this->assertSame($idB, AP_User::getByLogin('silas', $this->db)?->ID);

        $dup = AP_User::create([
            'user_login' => 'SILAS',
            'user_email' => 'silas-new@example.test',
            'user_pass' => 'securepass0',
        ], $this->db);
        $this->assertFalse($dup['ok']);
        $this->assertContains('That username is already registered.', $dup['errors']);
        $this->assertSame('Silas', AP_User::getById($idA, $this->db)?->user_login);
        $this->assertSame('silas', AP_User::getById($idB, $this->db)?->user_login);
        $this->assertNull(AP_User::getByEmail('silas-new@example.test', $this->db));
        $this->assertSame($idA, AP_User::getByEmail('silas-legacy-a@example.test', $this->db)?->ID);
        $this->assertSame($idB, AP_User::getByEmail('silas-legacy-b@example.test', $this->db)?->ID);
    }

    public function testEmailUniquenessDoesNotMergeLegacyCaseCollisions(): void
    {
        $hash = AP_User::hashPassword('securepass0');
        $now = gmdate('Y-m-d H:i:s');
        $this->db->insert('users', [
            'user_login' => 'mailcasea',
            'user_pass' => $hash,
            'user_nicename' => 'mailcasea',
            'user_email' => 'Casey@example.test',
            'user_url' => '',
            'user_registered' => $now,
            'user_activation_key' => '',
            'user_status' => 0,
            'display_name' => 'Mail A',
        ]);
        $idA = (int) $this->db->lastInsertId();
        $this->db->insert('users', [
            'user_login' => 'mailcaseb',
            'user_pass' => $hash,
            'user_nicename' => 'mailcaseb',
            'user_email' => 'casey@example.test',
            'user_url' => '',
            'user_registered' => $now,
            'user_activation_key' => '',
            'user_status' => 0,
            'display_name' => 'Mail B',
        ]);
        $idB = (int) $this->db->lastInsertId();

        $this->assertSame($idA, AP_User::getByEmail('Casey@example.test', $this->db)?->ID);
        $this->assertSame($idB, AP_User::getByEmail('casey@example.test', $this->db)?->ID);

        $dup = AP_User::create([
            'user_login' => 'mailcasec',
            'user_email' => 'CASEY@example.test',
            'user_pass' => 'securepass0',
        ], $this->db);
        $this->assertFalse($dup['ok']);
        $this->assertContains('That email address is already registered.', $dup['errors']);
        $this->assertSame('Casey@example.test', AP_User::getById($idA, $this->db)?->user_email);
        $this->assertSame('casey@example.test', AP_User::getById($idB, $this->db)?->user_email);
        $this->assertNull(AP_User::getByLogin('mailcasec', $this->db));
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
