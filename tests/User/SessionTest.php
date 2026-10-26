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

    private function cookieField(string $cookie, int $index): string
    {
        $parts = explode('|', $cookie);

        return $parts[$index] ?? '';
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

    public function testSelfPasswordChangeRotatesCurrentSessionAndDropsOthers(): void
    {
        $password = 'old-password';
        $user = $this->insertUser('gina', $password);
        $this->assertNotNull(AP_Session::login('gina', $password, false, $this->db));

        $name = AP_Session::cookieName();
        $before = AP_Session::getTestCookies()[$name] ?? '';
        $beforeToken = $this->cookieField($before, 2);
        $this->assertNotSame('', $beforeToken);

        $otherToken = AP_Session::createSessionToken($user->ID, time() + 3600, $this->db);
        $rememberToken = AP_Session::createSessionToken(
            $user->ID,
            time() + AP_Session::LIFETIME_REMEMBER,
            $this->db,
            true
        );
        $loaded = AP_User::getById($user->ID, $this->db);
        $this->assertNotNull($loaded);
        $otherCookie = AP_Session::generateAuthCookie($loaded, time() + 3600, $otherToken);
        $rememberCookie = AP_Session::generateAuthCookie(
            $loaded,
            time() + AP_Session::LIFETIME_REMEMBER,
            $rememberToken
        );
        $this->assertCount(3, AP_Session::getSessionTokens($user->ID, $this->db));

        $updated = AP_User::update($user->ID, ['password' => 'new-password-1'], $this->db);
        $this->assertTrue($updated['ok'], implode('; ', $updated['errors']));

        $this->assertFalse(AP_Session::sessionTokenIsValid($user->ID, $beforeToken, $this->db));
        $this->assertFalse(AP_Session::sessionTokenIsValid($user->ID, $otherToken, $this->db));
        $this->assertFalse(AP_Session::sessionTokenIsValid($user->ID, $rememberToken, $this->db));
        $this->assertNull(AP_Session::validateAuthCookie($before, $this->db));
        $this->assertNull(AP_Session::validateAuthCookie($otherCookie, $this->db));
        $this->assertNull(AP_Session::validateAuthCookie($rememberCookie, $this->db));

        $this->assertTrue(AP_Session::isLoggedIn($this->db));
        $this->assertSame($user->ID, AP_Session::getCurrentUserId($this->db));
        $after = AP_Session::getTestCookies()[$name] ?? '';
        $afterToken = $this->cookieField($after, 2);
        $this->assertNotSame('', $afterToken);
        $this->assertNotSame($beforeToken, $afterToken);
        $this->assertNotSame($before, $after);
        $this->assertTrue(AP_Session::sessionTokenIsValid($user->ID, $afterToken, $this->db));
        $this->assertCount(1, AP_Session::getSessionTokens($user->ID, $this->db));

        $afterExp = (int) $this->cookieField($after, 1);
        $this->assertEqualsWithDelta(time() + AP_Session::LIFETIME_DEFAULT, $afterExp, 5);
        $only = array_values(AP_Session::getSessionTokens($user->ID, $this->db))[0];
        $this->assertSame(0, $only['remember'] ?? null);

        AP_Session::resetCurrentUser();
        $this->assertSame($user->ID, AP_Session::getCurrentUserId($this->db));
        $this->assertTrue(AP_User::checkPassword('new-password-1', (string) $updated['user']?->user_pass));
    }

    public function testSelfPasswordChangeKeepsRememberMeLifetime(): void
    {
        $password = 'remember-pass';
        $user = $this->insertUser('hank', $password);
        $this->assertNotNull(AP_Session::login('hank', $password, true, $this->db));

        $updated = AP_User::update($user->ID, ['password' => 'remember-pass-2'], $this->db);
        $this->assertTrue($updated['ok'], implode('; ', $updated['errors']));
        $this->assertTrue(AP_Session::isLoggedIn($this->db));

        $after = AP_Session::getTestCookies()[AP_Session::cookieName()] ?? '';
        $afterExp = (int) $this->cookieField($after, 1);
        $this->assertGreaterThan(time() + AP_Session::LIFETIME_DEFAULT, $afterExp);
        $this->assertEqualsWithDelta(time() + AP_Session::LIFETIME_REMEMBER, $afterExp, 5);
        $only = array_values(AP_Session::getSessionTokens($user->ID, $this->db))[0];
        $this->assertSame(1, $only['remember'] ?? null);
    }

    public function testLegacyRememberSpanIsKeptWhenCurrentSessionRotates(): void
    {
        $password = 'legacy-session';
        $user = $this->insertUser('legacy-sess', $password);
        $now = time();
        $expiration = $now + AP_Session::LIFETIME_REMEMBER;
        $token = bin2hex(random_bytes(32));
        $shortToken = bin2hex(random_bytes(32));
        $stored = json_encode([
            hash('sha256', $token) => [
                'expiration' => $expiration,
                'login' => $now,
            ],
            hash('sha256', $shortToken) => [
                'expiration' => $now + AP_Session::LIFETIME_DEFAULT,
                'login' => $now,
            ],
        ], JSON_THROW_ON_ERROR);
        $this->assertTrue(AP_User::updateMeta($user->ID, AP_Session::META_SESSION_TOKENS, $stored, $this->db));

        $cookie = AP_Session::generateAuthCookie($user, $expiration, $token);
        AP_Session::enableTestMode([AP_Session::cookieName() => $cookie]);
        AP_Session::resetCurrentUser();
        $this->assertTrue(AP_Session::isLoggedIn($this->db));

        $updated = AP_User::update($user->ID, ['password' => 'legacy-session-2'], $this->db);
        $this->assertTrue($updated['ok'], implode('; ', $updated['errors']));
        $this->assertTrue(AP_Session::isLoggedIn($this->db));
        $this->assertFalse(AP_Session::sessionTokenIsValid($user->ID, $token, $this->db));
        $this->assertFalse(AP_Session::sessionTokenIsValid($user->ID, $shortToken, $this->db));

        $after = AP_Session::getTestCookies()[AP_Session::cookieName()] ?? '';
        $this->assertNotSame($token, $this->cookieField($after, 2));
        $afterExp = (int) $this->cookieField($after, 1);
        $this->assertEqualsWithDelta(time() + AP_Session::LIFETIME_REMEMBER, $afterExp, 5);
        AP_Session::resetCurrentUser();
        $this->assertSame($user->ID, AP_Session::getCurrentUserId($this->db));
    }

    public function testPasswordChangeWithoutOwnSessionDestroysEveryToken(): void
    {
        $actor = $this->insertUser('adminjudy', 'admin-pass-1');
        $target = $this->insertUser('targetken', 'target-pass-1');
        $this->assertNotNull(AP_Session::login('adminjudy', 'admin-pass-1', false, $this->db));
        $actorCookie = AP_Session::getTestCookies()[AP_Session::cookieName()] ?? '';
        $actorToken = $this->cookieField($actorCookie, 2);

        $targetToken = AP_Session::createSessionToken(
            $target->ID,
            time() + AP_Session::LIFETIME_REMEMBER,
            $this->db,
            true
        );

        $updated = AP_User::update($target->ID, ['password' => 'target-pass-2'], $this->db);
        $this->assertTrue($updated['ok'], implode('; ', $updated['errors']));
        $this->assertTrue(AP_Session::isLoggedIn($this->db));
        $this->assertSame($actor->ID, AP_Session::getCurrentUserId($this->db));
        $this->assertSame($actorCookie, AP_Session::getTestCookies()[AP_Session::cookieName()] ?? '');
        $this->assertTrue(AP_Session::sessionTokenIsValid($actor->ID, $actorToken, $this->db));
        $this->assertFalse(AP_Session::sessionTokenIsValid($target->ID, $targetToken, $this->db));
        $this->assertSame([], AP_Session::getSessionTokens($target->ID, $this->db));

        $lonely = $this->insertUser('nolive', 'lonely-pass-1');
        AP_Session::resetCurrentUser();
        AP_Session::enableTestMode();
        $lonelyToken = AP_Session::createSessionToken($lonely->ID, time() + 3600, $this->db, true);
        $cleared = AP_User::update($lonely->ID, ['password' => 'lonely-pass-2'], $this->db);
        $this->assertTrue($cleared['ok'], implode('; ', $cleared['errors']));
        $this->assertFalse(AP_Session::sessionTokenIsValid($lonely->ID, $lonelyToken, $this->db));
        $this->assertFalse(AP_Session::isLoggedIn($this->db));
    }

    public function testEmailChangeDoesNotTouchSessions(): void
    {
        $password = 'email-pass-1';
        $user = $this->insertUser('june', $password);
        $this->assertNotNull(AP_Session::login('june', $password, false, $this->db));
        $cookie = AP_Session::getTestCookies()[AP_Session::cookieName()] ?? '';
        $current = $this->cookieField($cookie, 2);
        $other = AP_Session::createSessionToken($user->ID, time() + 3600, $this->db, true);

        $updated = AP_User::update($user->ID, [
            'user_email' => 'june-new@example.test',
        ], $this->db);
        $this->assertTrue($updated['ok'], implode('; ', $updated['errors']));
        $this->assertSame('june-new@example.test', $updated['user']?->user_email);
        $this->assertSame($cookie, AP_Session::getTestCookies()[AP_Session::cookieName()] ?? '');
        $this->assertTrue(AP_Session::sessionTokenIsValid($user->ID, $current, $this->db));
        $this->assertTrue(AP_Session::sessionTokenIsValid($user->ID, $other, $this->db));
        $this->assertTrue(AP_User::checkPassword($password, (string) $updated['user']?->user_pass));
    }

    public function testDestroyOtherSessionTokensKeepsOnlyTheNamedToken(): void
    {
        $user = $this->insertUser('keepone', 'keep-pass-1');
        $keep = AP_Session::createSessionToken($user->ID, time() + 3600, $this->db);
        $drop = AP_Session::createSessionToken(
            $user->ID,
            time() + AP_Session::LIFETIME_REMEMBER,
            $this->db,
            true
        );

        AP_Session::destroyOtherSessionTokens($user->ID, $keep, $this->db);
        $this->assertTrue(AP_Session::sessionTokenIsValid($user->ID, $keep, $this->db));
        $this->assertFalse(AP_Session::sessionTokenIsValid($user->ID, $drop, $this->db));
        $this->assertCount(1, AP_Session::getSessionTokens($user->ID, $this->db));

        AP_Session::destroyOtherSessionTokens($user->ID, '', $this->db);
        $this->assertSame([], AP_Session::getSessionTokens($user->ID, $this->db));

        $again = AP_Session::createSessionToken($user->ID, time() + 3600, $this->db);
        $this->assertFalse(AP_Session::rotateCurrentSession($user->ID, '', false, $this->db));
        $this->assertTrue(AP_Session::sessionTokenIsValid($user->ID, $again, $this->db));
    }
}
