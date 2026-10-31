<?php

/**
 * REST HTTP Basic shares the login form lockout.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Rest;

use AP_DB;
use AP_Migrator;
use AP_Options;
use AP_Rate_Limit;
use AP_Rest;
use AP_Session;
use AP_User;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_Rest::class)]
final class RestBasicLockoutTest extends TestCase
{
    private const LOGIN = 'basicuser';

    private const PASSWORD = 'BasicPass-99!';

    private AP_DB $db;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 2);
        require_once $root . '/ap-includes/version.php';
        require_once $root . '/ap-includes/hooks.php';
        require_once $root . '/ap-includes/class-ap-db.php';
        require_once $root . '/ap-includes/class-ap-migrator.php';
        require_once $root . '/ap-includes/class-ap-options.php';
        require_once $root . '/ap-includes/class-ap-transient.php';
        require_once $root . '/ap-includes/class-ap-user.php';
        require_once $root . '/ap-includes/class-ap-session.php';
        require_once $root . '/ap-includes/class-ap-rate-limit.php';
        require_once $root . '/ap-includes/class-ap-rest.php';
        require_once $root . '/ap-includes/functions.php';

        if (!defined('AP_LOGGED_IN_KEY')) {
            define('AP_LOGGED_IN_KEY', 'rest-lock-logged-in-key');
        }
        if (!defined('AP_LOGGED_IN_SALT')) {
            define('AP_LOGGED_IN_SALT', 'rest-lock-logged-in-salt');
        }
        if (!defined('AP_AUTH_KEY')) {
            define('AP_AUTH_KEY', 'rest-lock-auth-key');
        }
        if (!defined('AP_AUTH_SALT')) {
            define('AP_AUTH_SALT', 'rest-lock-auth-salt');
        }

        AP_Rest::reset();
        AP_Rate_Limit::resetTestState();
        AP_Options::flushCache();
        AP_Session::clearLastLoginError();
        AP_Session::enableTestMode();
        AP_Session::resetCurrentUser();

        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');
        (new AP_Migrator($this->db, AP_Migrator::defaultMigrationsPath()))->migrate();
        $GLOBALS['apdb'] = $this->db;

        $this->db->insert('users', [
            'user_login' => self::LOGIN,
            'user_pass' => AP_User::hashPassword(self::PASSWORD),
            'user_nicename' => self::LOGIN,
            'user_email' => 'basicuser@example.test',
            'user_url' => '',
            'user_registered' => gmdate('Y-m-d H:i:s'),
            'user_activation_key' => '',
            'user_status' => 0,
            'display_name' => 'Basic User',
        ]);

        AP_Rate_Limit::setTestLimits('login', [
            'max' => 3,
            'window' => 900,
            'lockout' => 3600,
        ]);
        $_SERVER['REMOTE_ADDR'] = '203.0.113.44';
    }

    protected function tearDown(): void
    {
        AP_Rest::reset();
        AP_Rate_Limit::resetTestState();
        AP_Options::flushCache();
        AP_Session::disableTestMode();
        AP_Session::clearLastLoginError();
        unset($GLOBALS['apdb'], $_SERVER['REMOTE_ADDR']);
    }

    public function testFailedBasicAttemptsLockOutCorrectPassword(): void
    {
        $first = $this->basic(self::LOGIN, 'wrong-1');
        $second = $this->basic(self::LOGIN, 'wrong-2');
        $this->assertSame(200, $first['status']);
        $this->assertSame(200, $second['status']);

        $third = $this->basic(self::LOGIN, 'wrong-3');
        $this->assertSame(429, $third['status']);
        $this->assertSame('rest_rate_limited', $third['data']['code']);

        $this->assertInstanceOf(
            AP_User::class,
            AP_User::authenticate(self::LOGIN, self::PASSWORD, $this->db)
        );

        $correct = $this->basic(self::LOGIN, self::PASSWORD);
        $wrong = $this->basic(self::LOGIN, 'still-wrong');
        $this->assertSame(429, $correct['status']);
        $this->assertSame(429, $wrong['status']);
        $this->assertSame($wrong['data']['message'], $correct['data']['message']);
        $this->assertStringContainsString('Too many', (string) $correct['data']['message']);
        $this->assertStringNotContainsString(self::PASSWORD, (string) $correct['data']['message']);
        $this->assertSame(0, AP_Rest::authenticate(
            ['Authorization' => 'Basic ' . base64_encode(self::LOGIN . ':' . self::PASSWORD)],
            [],
            $this->db
        ));

        $user = AP_User::getByLogin(self::LOGIN, $this->db);
        $this->assertInstanceOf(AP_User::class, $user);
        $this->assertTrue($user->updatePassword('Rotated-Pass-42!', $this->db));
        $rotated = $this->basic(self::LOGIN, 'Rotated-Pass-42!');
        $this->assertSame(429, $rotated['status']);
        $this->assertSame('rest_rate_limited', $rotated['data']['code']);
        $this->assertStringContainsString('Too many', (string) $rotated['data']['message']);
        $this->assertStringNotContainsString('Rotated-Pass-42!', (string) $rotated['data']['message']);

        AP_Rate_Limit::clearLogin(self::LOGIN, '', $this->db);
        $this->assertGreaterThan(0, AP_Rest::authenticate(
            ['Authorization' => 'Basic ' . base64_encode(self::LOGIN . ':Rotated-Pass-42!')],
            [],
            $this->db
        ));
    }

    public function testFormFailuresLockBasicAndBasicFailuresLockForm(): void
    {
        AP_Session::login(self::LOGIN, 'nope-1', false, $this->db);
        AP_Session::login(self::LOGIN, 'nope-2', false, $this->db);
        AP_Session::login(self::LOGIN, 'nope-3', false, $this->db);

        $viaBasic = $this->basic(self::LOGIN, self::PASSWORD);
        $this->assertSame(429, $viaBasic['status']);
        $this->assertSame(0, AP_Rest::authenticate(
            ['Authorization' => 'Basic ' . base64_encode(self::LOGIN . ':' . self::PASSWORD)],
            [],
            $this->db
        ));

        AP_Rate_Limit::clearLogin(self::LOGIN, '', $this->db);
        AP_Session::clearLastLoginError();

        $this->basic(self::LOGIN, 'nope-a');
        $this->basic(self::LOGIN, 'nope-b');
        $this->basic(self::LOGIN, 'nope-c');

        $viaForm = AP_Session::login(self::LOGIN, self::PASSWORD, false, $this->db);
        $this->assertNull($viaForm);
        $err = AP_Session::getLastLoginError();
        $this->assertNotNull($err);
        $this->assertSame('rate_limited', $err['code']);
    }

    /**
     * @return array{status: int, data: mixed, headers: array<string, string>}
     */
    private function basic(string $login, string $password): array
    {
        $header = 'Basic ' . base64_encode($login . ':' . $password);

        return AP_Rest::dispatch([
            'method' => 'GET',
            'route' => '/ap/v1/settings',
            'headers' => ['Authorization' => $header],
            'server' => ['HTTP_AUTHORIZATION' => $header],
        ], $this->db);
    }
}
