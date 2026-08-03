<?php

/**
 * Tests for voluntary Hall of Fame domain registration (no telemetry).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Admin;

use AP_Admin;
use AP_DB;
use AP_Hall_Of_Fame;
use AP_Migrator;
use AP_Options;
use AP_Roles;
use AP_User;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_Hall_Of_Fame::class)]
final class HallOfFameTest extends TestCase
{
    private string $root;

    private AP_DB $db;

    private int $adminId = 0;

    private int $subscriberId = 0;

    /** @var list<array{method: string, url: string, payload: array<string, mixed>}> */
    private array $httpCalls = [];

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
        require_once $this->root . '/ap-includes/class-ap-hall-of-fame.php';
        require_once $this->root . '/ap-includes/functions.php';
        require_once $this->root . '/ap-admin/includes/class-ap-admin.php';

        if (!defined('AP_NONCE_KEY')) {
            define('AP_NONCE_KEY', 'test-nonce-key-' . str_repeat('h', 32));
        }
        if (!defined('AP_NONCE_SALT')) {
            define('AP_NONCE_SALT', 'test-nonce-salt-' . str_repeat('i', 32));
        }
        if (!defined('AP_LOGGED_IN_KEY')) {
            define('AP_LOGGED_IN_KEY', 'test-logged-in-key-' . str_repeat('j', 32));
        }
        if (!defined('AP_LOGGED_IN_SALT')) {
            define('AP_LOGGED_IN_SALT', 'test-logged-in-salt-' . str_repeat('k', 32));
        }

        AP_Roles::flushCache();
        AP_Options::flushCache();
        AP_Hall_Of_Fame::resetHttpTransport();
        AP_Admin::clearNotices();
        $this->httpCalls = [];

        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');
        $migrator = new AP_Migrator($this->db, AP_Migrator::defaultMigrationsPath());
        $migrator->migrate();
        AP_Roles::ensureDefaults($this->db);

        AP_Options::update('siteurl', 'https://www.example.com/blog', $this->db);
        AP_Options::update('home', 'https://www.example.com/blog', $this->db);
        AP_Options::update('blogname', 'Example Agora', $this->db);

        $admin = AP_User::create([
            'user_login' => 'hofadmin',
            'user_email' => 'hofadmin@example.test',
            'password' => 'password123',
            'role' => 'administrator',
        ], $this->db);
        $this->adminId = (int) $admin['id'];

        $sub = AP_User::create([
            'user_login' => 'hofsub',
            'user_email' => 'hofsub@example.test',
            'password' => 'password123',
            'role' => 'subscriber',
        ], $this->db);
        $this->subscriberId = (int) $sub['id'];
    }

    protected function tearDown(): void
    {
        AP_Hall_Of_Fame::resetHttpTransport();
        AP_Roles::flushCache();
        AP_Options::flushCache();
        AP_Admin::clearNotices();
    }

    public function testPrivacyInvariants(): void
    {
        $this->assertFalse(AP_Hall_Of_Fame::usesInstallerPings());
        $this->assertFalse(AP_Hall_Of_Fame::isTelemetry());
    }

    public function testNormalizeDomain(): void
    {
        $this->assertSame('example.com', AP_Hall_Of_Fame::normalizeDomain('https://www.example.com/path'));
        $this->assertSame('example.com', AP_Hall_Of_Fame::normalizeDomain('http://Example.COM:8080'));
        $this->assertSame('example.com', AP_Hall_Of_Fame::normalizeDomain('example.com'));
        $this->assertSame('', AP_Hall_Of_Fame::normalizeDomain('https://127.0.0.1/'));
        $this->assertSame('', AP_Hall_Of_Fame::normalizeDomain(''));
        $this->assertSame('', AP_Hall_Of_Fame::normalizeDomain('not a host!!!'));
    }

    public function testResolveDomainFromSiteurl(): void
    {
        $this->assertSame('example.com', AP_Hall_Of_Fame::resolveDomain($this->db));
    }

    public function testBuildPayloadIsDomainOnly(): void
    {
        $join = AP_Hall_Of_Fame::buildPayload(AP_Hall_Of_Fame::ACTION_JOIN, 'example.com');
        $this->assertSame(['action' => 'join', 'domain' => 'example.com'], $join);
        $this->assertArrayNotHasKey('email', $join);
        $this->assertArrayNotHasKey('user', $join);
        $this->assertArrayNotHasKey('version', $join);
        $this->assertArrayNotHasKey('site_title', $join);

        $leave = AP_Hall_Of_Fame::buildPayload(AP_Hall_Of_Fame::ACTION_LEAVE, 'example.com', 'tok123');
        $this->assertSame([
            'action' => 'leave',
            'domain' => 'example.com',
            'token' => 'tok123',
        ], $leave);
    }

    public function testJoinSucceedsWithMockTransport(): void
    {
        $this->installOkTransport(['token' => 'withdraw-abc']);

        $nonce = ap_create_nonce(AP_Hall_Of_Fame::NONCE_JOIN, $this->adminId);
        $result = AP_Hall_Of_Fame::join($this->adminId, [
            '_ap_nonce' => $nonce,
            'domain' => 'https://www.example.com',
        ], $this->db);

        $this->assertTrue($result['ok']);
        $this->assertSame('hall_of_fame_joined', $result['message_key']);
        $this->assertSame('example.com', $result['domain']);
        $this->assertTrue(AP_Hall_Of_Fame::isJoined($this->db));

        $status = AP_Hall_Of_Fame::getStatus($this->db);
        $this->assertSame('example.com', $status['domain']);
        $this->assertSame('withdraw-abc', $status['token']);
        $this->assertNotSame('', $status['joined_at']);

        $this->assertCount(1, $this->httpCalls);
        $payload = $this->httpCalls[0]['payload'];
        $this->assertSame('join', $payload['action']);
        $this->assertSame('example.com', $payload['domain']);
        $this->assertArrayNotHasKey('email', $payload);
        $this->assertStringContainsString('hall-of-fame', $this->httpCalls[0]['url']);
    }

    public function testJoinFailsOnRemoteErrorWithoutLocalJoin(): void
    {
        AP_Hall_Of_Fame::setHttpTransport(static function (): array {
            return [
                'ok' => false,
                'status' => 503,
                'body' => '{"error":"unavailable"}',
                'error' => 'Service unavailable',
            ];
        });

        $nonce = ap_create_nonce(AP_Hall_Of_Fame::NONCE_JOIN, $this->adminId);
        $result = AP_Hall_Of_Fame::join($this->adminId, [
            '_ap_nonce' => $nonce,
        ], $this->db);

        $this->assertFalse($result['ok']);
        $this->assertSame('hall_of_fame_remote', $result['message_key']);
        $this->assertFalse(AP_Hall_Of_Fame::isJoined($this->db));
    }

    public function testJoinRejectsBadNonceAndCaps(): void
    {
        $this->installOkTransport();

        $bad = AP_Hall_Of_Fame::join($this->adminId, [
            '_ap_nonce' => 'nope',
        ], $this->db);
        $this->assertFalse($bad['ok']);
        $this->assertSame('nonce', $bad['message_key']);
        $this->assertSame([], $this->httpCalls);

        $subNonce = ap_create_nonce(AP_Hall_Of_Fame::NONCE_JOIN, $this->subscriberId);
        $noCap = AP_Hall_Of_Fame::join($this->subscriberId, [
            '_ap_nonce' => $subNonce,
        ], $this->db);
        $this->assertFalse($noCap['ok']);
        $this->assertSame([], $this->httpCalls);
    }

    public function testLeaveWithdrawsAndClearsLocalState(): void
    {
        $this->installOkTransport(['token' => 'leave-tok']);
        $joinNonce = ap_create_nonce(AP_Hall_Of_Fame::NONCE_JOIN, $this->adminId);
        AP_Hall_Of_Fame::join($this->adminId, ['_ap_nonce' => $joinNonce], $this->db);
        $this->httpCalls = [];

        $this->installOkTransport();
        $leaveNonce = ap_create_nonce(AP_Hall_Of_Fame::NONCE_LEAVE, $this->adminId);
        $result = AP_Hall_Of_Fame::leave($this->adminId, [
            '_ap_nonce' => $leaveNonce,
        ], $this->db);

        $this->assertTrue($result['ok']);
        $this->assertSame('hall_of_fame_left', $result['message_key']);
        $this->assertFalse(AP_Hall_Of_Fame::isJoined($this->db));
        $this->assertCount(1, $this->httpCalls);
        $this->assertSame('leave', $this->httpCalls[0]['payload']['action']);
        $this->assertSame('example.com', $this->httpCalls[0]['payload']['domain']);
        $this->assertSame('leave-tok', $this->httpCalls[0]['payload']['token']);
    }

    public function testLeaveClearsLocalEvenWhenRemoteFails(): void
    {
        $this->installOkTransport(['token' => 't1']);
        $joinNonce = ap_create_nonce(AP_Hall_Of_Fame::NONCE_JOIN, $this->adminId);
        AP_Hall_Of_Fame::join($this->adminId, ['_ap_nonce' => $joinNonce], $this->db);

        AP_Hall_Of_Fame::setHttpTransport(static function (): array {
            return [
                'ok' => false,
                'status' => 0,
                'body' => '',
                'error' => 'network',
            ];
        });

        $leaveNonce = ap_create_nonce(AP_Hall_Of_Fame::NONCE_LEAVE, $this->adminId);
        $result = AP_Hall_Of_Fame::leave($this->adminId, [
            '_ap_nonce' => $leaveNonce,
        ], $this->db);

        $this->assertTrue($result['ok']);
        $this->assertSame('hall_of_fame_left_local', $result['message_key']);
        $this->assertFalse(AP_Hall_Of_Fame::isJoined($this->db));
    }

    public function testDismissPromptDoesNotCallHttp(): void
    {
        $this->installOkTransport();
        $this->assertTrue(AP_Hall_Of_Fame::shouldShowPrompt($this->adminId, $this->db));

        $nonce = ap_create_nonce(AP_Hall_Of_Fame::NONCE_DISMISS, $this->adminId);
        $result = AP_Hall_Of_Fame::dismissPrompt($this->adminId, [
            '_ap_nonce' => $nonce,
        ], $this->db);

        $this->assertTrue($result['ok']);
        $this->assertFalse(AP_Hall_Of_Fame::shouldShowPrompt($this->adminId, $this->db));
        $this->assertSame([], $this->httpCalls);
    }

    public function testPromptHiddenForSubscriberAndWhenJoined(): void
    {
        $this->assertFalse(AP_Hall_Of_Fame::shouldShowPrompt($this->subscriberId, $this->db));
        $this->assertFalse(AP_Hall_Of_Fame::shouldShowPrompt(0, $this->db));

        $this->installOkTransport();
        $nonce = ap_create_nonce(AP_Hall_Of_Fame::NONCE_JOIN, $this->adminId);
        AP_Hall_Of_Fame::join($this->adminId, ['_ap_nonce' => $nonce], $this->db);
        $this->assertFalse(AP_Hall_Of_Fame::shouldShowPrompt($this->adminId, $this->db));
    }

    public function testDonationLinkIsPermanentNonOptional(): void
    {
        // Constitution: admin-footer tip link is permanent; no toggle option.
        $ref = new \ReflectionClass(AP_Hall_Of_Fame::class);
        $this->assertFalse($ref->hasConstant('OPTION_SHOW_DONATION'));
        $this->assertFalse($ref->hasConstant('ACTION_DONATION'));
        $this->assertFalse($ref->hasConstant('NONCE_DONATION'));
        $this->assertFalse($ref->hasMethod('showDonationButton'));
        $this->assertFalse($ref->hasMethod('setShowDonationButton'));
        $this->assertFalse($ref->hasMethod('saveDonationPreference'));
        $this->assertFalse(function_exists('ap_show_donation_button'));
        $this->assertNotSame('', AP_Hall_Of_Fame::DONATION_URL);
        $this->assertStringStartsWith('https://', AP_Hall_Of_Fame::DONATION_URL);

        $status = AP_Hall_Of_Fame::getStatus($this->db);
        $this->assertArrayNotHasKey('show_donation', $status);
    }

    public function testProceduralHelpers(): void
    {
        $this->assertFalse(ap_hall_of_fame_is_joined($this->db));
        $this->assertSame('example.com', ap_hall_of_fame_domain($this->db));
        $status = ap_hall_of_fame_status($this->db);
        $this->assertFalse($status['joined']);
        $this->assertArrayNotHasKey('show_donation', $status);
    }

    public function testAdminScreenAndMenuExist(): void
    {
        $this->assertFileIsReadable($this->root . '/ap-admin/options-hall-of-fame.php');
        $this->assertFileIsReadable($this->root . '/ap-includes/class-ap-hall-of-fame.php');

        $screen = (string) file_get_contents($this->root . '/ap-admin/options-hall-of-fame.php');
        // Operator-supplied Hall of Fame description (SPEC / TODO 8.2).
        $hofDescription = 'AgoraPress is free and open source. It never phones home by default. '
            . 'The Hall of Fame is the only optional way to count installs: you may '
            . 'voluntarily register your domain so it can appear in a public counter '
            . 'and random rotation on the project site. You can withdraw at any time. '
            . 'Nothing is sent during install or ordinary browsing.';
        $this->assertStringContainsString($hofDescription, $screen);
        $this->assertStringContainsString('Join the Hall of Fame', $screen);
        $this->assertStringContainsString('Leave Hall of Fame', $screen);
        $this->assertStringContainsString('No telemetry', $screen);
        $this->assertStringContainsString('No installer pings', $screen);
        $this->assertStringContainsString('manage_options', $screen);
        $this->assertStringContainsString('AP_Hall_Of_Fame::join', $screen);
        $this->assertStringContainsString('permanent and non-optional', $screen);
        $this->assertStringNotContainsString('show_donation_button', $screen);
        $this->assertStringNotContainsString('Show donation link in admin footer', $screen);

        $admin = (string) file_get_contents($this->root . '/ap-admin/includes/class-ap-admin.php');
        $this->assertStringContainsString('options-hall-of-fame', $admin);
        $this->assertStringContainsString('Hall of Fame', $admin);
        $this->assertStringContainsString('hall_of_fame_joined', $admin);

        $caps = AP_Admin::screenCapabilities();
        $this->assertSame('manage_options', $caps['options-hall-of-fame.php'] ?? null);

        $menu = AP_Admin::menuItems('options-hall-of-fame', $this->db);
        $ids = array_column($menu, 'id');
        $this->assertContains('options-hall-of-fame', $ids);
    }

    public function testDashboardPromptMarkup(): void
    {
        $index = (string) file_get_contents($this->root . '/ap-admin/index.php');
        $this->assertStringContainsString('Join the Hall of Fame', $index);
        $this->assertStringContainsString('shouldShowPrompt', $index);
        $this->assertStringContainsString('hof-dismiss', $index);
        $this->assertStringContainsString('no installer pings', $index);

        $footer = (string) file_get_contents($this->root . '/ap-admin/admin-footer.php');
        $this->assertStringContainsString('ap-footer-donate', $footer);
        $this->assertStringContainsString('DONATION_URL', $footer);
        $this->assertStringContainsString('Permanent non-optional', $footer);
        $this->assertStringContainsString('Hall of Fame', $footer);
        $this->assertStringNotContainsString('showDonationButton', $footer);
        // Donate link is unconditional (not wrapped in a preference check).
        $this->assertStringNotContainsString('if ($ap_show_donation)', $footer);
    }

    public function testInstallerDoesNotPhoneHome(): void
    {
        $installer = (string) file_get_contents($this->root . '/ap-includes/class-ap-installer.php');
        $cli = (string) file_get_contents($this->root . '/ap-includes/class-ap-cli-install.php');
        $web = (string) file_get_contents($this->root . '/install/index.php');

        foreach ([$installer, $cli, $web] as $src) {
            $this->assertStringNotContainsString('hall-of-fame', strtolower($src));
            $this->assertStringNotContainsString('AP_Hall_Of_Fame', $src);
            $this->assertStringNotContainsString('agorapress.extrovertednerd.com/api', $src);
        }

        // Seed defaults never join; donation footer is not an option.
        $this->assertStringContainsString("'hall_of_fame_status' => ''", $installer);
        $this->assertStringNotContainsString('show_donation_button', $installer);
        $this->assertStringContainsString('no telemetry', strtolower($web));
    }

    public function testBootstrapLoadsHallOfFame(): void
    {
        $bootstrap = (string) file_get_contents($this->root . '/ap-includes/bootstrap.php');
        $this->assertStringContainsString('class-ap-hall-of-fame.php', $bootstrap);
    }

    /**
     * @param array<string, mixed> $jsonBody
     */
    private function installOkTransport(array $jsonBody = []): void
    {
        $this->httpCalls = [];
        $calls = &$this->httpCalls;
        AP_Hall_Of_Fame::setHttpTransport(static function (
            string $method,
            string $url,
            array $payload
        ) use (
            &$calls,
            $jsonBody
        ): array {
            $calls[] = [
                'method' => $method,
                'url' => $url,
                'payload' => $payload,
            ];

            return [
                'ok' => true,
                'status' => 200,
                'body' => (string) json_encode($jsonBody !== [] ? $jsonBody : ['ok' => true]),
                'error' => '',
            ];
        });
    }
}
