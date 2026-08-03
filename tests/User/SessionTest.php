<?php

/**
 * Tests for AP_Session — signed auth cookies and session tokens.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\User;

use AP_DB;
use AP_Migrator;
use AP_Session;
use AP_User;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_Session::class)]
final class SessionTest extends TestCase
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
        require_once $this->root . '/ap-includes/class-ap-session.php';
        require_once $this->root . '/ap-includes/functions.php';

        if (!defined('AP_LOGGED_IN_KEY')) {
            define('AP_LOGGED_IN_KEY', 'test-logged-in-key-' . str_repeat('a', 32));
        }
        if (!defined('AP_LOGGED_IN_SALT')) {
            define('AP_LOGGED_IN_SALT', 'test-logged-in-salt-' . str_repeat('b', 32));
        }

        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');

        $migrator = new AP_Migrator($this->db, AP_Migrator::defaultMigrationsPath());
        $migrator->migrate();

        AP_Session::enableTestMode();
        AP_Session::resetCurrentUser();
    }

    protected function tearDown(): void
    {
        AP_Session::disableTestMode();
    }

    private function insertUser(
        string $login = 'admin',
        string $password = 'session-secret-1',
        int $status = 0
    ): AP_User {
        $hash = AP_User::hashPassword($password);
        $this->db->insert('users', [
            'user_login' => $login,
            'user_pass' => $hash,
            'user_nicename' => $login,
            'user_email' => $login . '@example.test',
            'user_url' => '',
            'user_registered' => gmdate('Y-m-d H:i:s'),
            'user_activation_key' => '',
            'user_status' => $status,
            'display_name' => $login,
        ]);
        $id = (int) $this->db->lastInsertId();
        $user = AP_User::getById($id, $this->db);
        $this->assertNotNull($user);

        return $user;
    }

    public function testCookieNameIsStableAndPrefixed(): void
    {
        $name = AP_Session::cookieName();
        $this->assertStringStartsWith('ap_logged_in_', $name);
        $this->assertSame($name, AP_Session::cookieName());
        $this->assertSame($name, ap_auth_cookie_name());
    }

    public function testGenerateAndValidateAuthCookie(): void
    {
        $user = $this->insertUser();
        $expiration = time() + 3600;
        $token = AP_Session::createSessionToken($user->ID, $expiration, $this->db);
        $this->assertNotSame('', $token);

        $cookie = AP_Session::generateAuthCookie($user, $expiration, $token);
        $parts = explode('|', $cookie);
        $this->assertCount(4, $parts);
        $this->assertSame((string) $user->ID, $parts[0]);

        $validated = AP_Session::validateAuthCookie($cookie, $this->db);
        $this->assertInstanceOf(AP_User::class, $validated);
        $this->assertSame($user->ID, $validated->ID);
    }

    public function testValidateRejectsTamperedHmacAndExpired(): void
    {
        $user = $this->insertUser();
        $expiration = time() + 3600;
        $token = AP_Session::createSessionToken($user->ID, $expiration, $this->db);
        $cookie = AP_Session::generateAuthCookie($user, $expiration, $token);

        $tampered = preg_replace('/[0-9a-f]{8}$/', 'deadbeef', $cookie);
        $this->assertIsString($tampered);
        $this->assertNull(AP_Session::validateAuthCookie($tampered, $this->db));

        $expiredToken = AP_Session::createSessionToken($user->ID, time() - 10, $this->db);
        $expiredCookie = AP_Session::generateAuthCookie($user, time() - 10, $expiredToken);
        $this->assertNull(AP_Session::validateAuthCookie($expiredCookie, $this->db));

        $this->assertNull(AP_Session::validateAuthCookie(null, $this->db));
        $this->assertNull(AP_Session::validateAuthCookie('not|valid', $this->db));
    }

    public function testValidateRejectsRevokedToken(): void
    {
        $user = $this->insertUser();
        $expiration = time() + 3600;
        $token = AP_Session::createSessionToken($user->ID, $expiration, $this->db);
        $cookie = AP_Session::generateAuthCookie($user, $expiration, $token);

        AP_Session::destroySessionToken($user->ID, $token, $this->db);
        $this->assertNull(AP_Session::validateAuthCookie($cookie, $this->db));
    }

    public function testLoginSetsCookieAndCurrentUser(): void
    {
        $password = 'login-pass-99';
        $user = $this->insertUser('alice', $password);

        $loggedIn = AP_Session::login('alice', $password, false, $this->db);
        $this->assertInstanceOf(AP_User::class, $loggedIn);
        $this->assertSame($user->ID, $loggedIn->ID);

        $this->assertTrue(AP_Session::isLoggedIn($this->db));
        $this->assertSame($user->ID, AP_Session::getCurrentUserId($this->db));
        $this->assertSame('alice', AP_Session::getCurrentUser($this->db)?->user_login);

        $cookies = AP_Session::getTestCookies();
        $this->assertArrayHasKey(AP_Session::cookieName(), $cookies);
        $this->assertNotSame('', $cookies[AP_Session::cookieName()]);
    }

    public function testLoginFailsWithBadPassword(): void
    {
        $this->insertUser('bob', 'right-pass');
        $this->assertNull(AP_Session::login('bob', 'wrong-pass', false, $this->db));
        $this->assertFalse(AP_Session::isLoggedIn($this->db));
        $this->assertSame(0, AP_Session::getCurrentUserId($this->db));
    }

    public function testLogoutClearsCookieAndRevokesToken(): void
    {
        $password = 'logout-pass';
        $user = $this->insertUser('carol', $password);
        AP_Session::login('carol', $password, true, $this->db);
        $this->assertTrue(AP_Session::isLoggedIn($this->db));

        $cookieBefore = AP_Session::getTestCookies()[AP_Session::cookieName()] ?? '';
        $this->assertNotSame('', $cookieBefore);

        // Capture token validity via remaining sessions.
        $sessionsBefore = AP_Session::getSessionTokens($user->ID, $this->db);
        $this->assertNotEmpty($sessionsBefore);

        AP_Session::logout($this->db);
        $this->assertFalse(AP_Session::isLoggedIn($this->db));
        $this->assertArrayNotHasKey(AP_Session::cookieName(), AP_Session::getTestCookies());
        $this->assertEmpty(AP_Session::getSessionTokens($user->ID, $this->db));

        // Old cookie must no longer validate.
        $this->assertNull(AP_Session::validateAuthCookie($cookieBefore, $this->db));
    }

    public function testPasswordChangeInvalidatesSessions(): void
    {
        $password = 'old-password';
        $user = $this->insertUser('dave', $password);
        AP_Session::login('dave', $password, false, $this->db);
        $cookie = AP_Session::getTestCookies()[AP_Session::cookieName()] ?? '';
        $this->assertNotSame('', $cookie);

        $fresh = AP_User::getById($user->ID, $this->db);
        $this->assertNotNull($fresh);
        $this->assertTrue($fresh->updatePassword('new-password', $this->db));

        $this->assertEmpty(AP_Session::getSessionTokens($user->ID, $this->db));
        // Even if cookie still present, HMAC binds password fragment.
        AP_Session::enableTestMode([AP_Session::cookieName() => $cookie]);
        AP_Session::resetCurrentUser();
        $this->assertNull(AP_Session::validateAuthCookie($cookie, $this->db));
        $this->assertFalse(AP_Session::isLoggedIn($this->db));
    }

    public function testInactiveUserCannotReceiveCookie(): void
    {
        $password = 'inactive-pass';
        $user = $this->insertUser('eve', $password, 1);
        $this->assertFalse(AP_Session::setAuthCookie($user->ID, false, $this->db));
        $this->assertNull(AP_Session::login('eve', $password, false, $this->db));
    }

    public function testProceduralSessionHelpers(): void
    {
        $password = 'proc-session';
        $user = $this->insertUser('frank', $password);

        $this->assertTrue(function_exists('ap_login'));
        $this->assertTrue(function_exists('ap_logout'));
        $this->assertTrue(function_exists('ap_is_user_logged_in'));
        $this->assertTrue(function_exists('ap_get_current_user'));
        $this->assertTrue(function_exists('ap_get_current_user_id'));
        $this->assertTrue(function_exists('ap_set_auth_cookie'));
        $this->assertTrue(function_exists('ap_clear_auth_cookie'));
        $this->assertTrue(function_exists('ap_destroy_user_sessions'));

        $loggedIn = ap_login('frank', $password, false, $this->db);
        $this->assertInstanceOf(AP_User::class, $loggedIn);
        $this->assertTrue(ap_is_user_logged_in($this->db));
        $this->assertSame($user->ID, ap_get_current_user_id($this->db));
        $this->assertSame('frank', ap_get_current_user($this->db)?->user_login);

        ap_logout($this->db);
        $this->assertFalse(ap_is_user_logged_in($this->db));

        $this->assertTrue(ap_set_auth_cookie($user->ID, false, $this->db));
        $this->assertTrue(ap_is_user_logged_in($this->db));
        ap_destroy_user_sessions($user->ID, $this->db);
        AP_Session::resetCurrentUser();
        $this->assertFalse(ap_is_user_logged_in($this->db));
    }

    public function testRememberMeUsesLongerLifetime(): void
    {
        $default = AP_Session::cookieExpiration(false);
        $remember = AP_Session::cookieExpiration(true);
        $this->assertGreaterThan($default, $remember);
        $this->assertEqualsWithDelta(time() + AP_Session::LIFETIME_DEFAULT, $default, 2);
        $this->assertEqualsWithDelta(time() + AP_Session::LIFETIME_REMEMBER, $remember, 2);
    }

    public function testBootstrapLoadsSessionLayer(): void
    {
        $configPath = $this->root . '/ap-config.php';
        $created = false;

        if (!is_readable($configPath)) {
            $sample = $this->root . '/ap-config-sample.php';
            $this->assertFileIsReadable($sample);
            $this->assertTrue(copy($sample, $configPath));
            $created = true;
        }

        $tmpScript = sys_get_temp_dir() . '/apsession-bootstrap-' . uniqid('', true) . '.php';

        try {
            $root = $this->root . '/';
            $code = "<?php\ndeclare(strict_types=1);\n"
                . "define('AP_ABSPATH', " . var_export($root, true) . ");\n"
                . "require AP_ABSPATH . 'ap-includes/bootstrap.php';\n"
                . "ap_bootstrap();\n"
                . "echo class_exists('AP_Session', false) ? \"SESSION_OK\\n\" : \"SESSION_MISSING\\n\";\n"
                . "echo function_exists('ap_login') ? \"LOGIN_FN_OK\\n\" : \"LOGIN_FN_MISSING\\n\";\n"
                . "echo function_exists('ap_is_user_logged_in')"
                . " ? \"LOGGED_IN_FN_OK\\n\" : \"LOGGED_IN_FN_MISSING\\n\";\n"
                . "echo (AP_Session::cookieName() !== ''"
                . " ? \"COOKIE_NAME_OK\\n\" : \"COOKIE_NAME_FAIL\\n\");\n";
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
            $this->assertStringContainsString('SESSION_OK', $body);
            $this->assertStringContainsString('LOGIN_FN_OK', $body);
            $this->assertStringContainsString('LOGGED_IN_FN_OK', $body);
            $this->assertStringContainsString('COOKIE_NAME_OK', $body);
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
