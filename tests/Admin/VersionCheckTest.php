<?php

/**
 * Tests for AP_Version_Check (public version.json, no site identity).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Admin;

use AP_Admin;
use AP_DB;
use AP_Migrator;
use AP_Options;
use AP_Roles;
use AP_Transient;
use AP_User;
use AP_Version_Check;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_Version_Check::class)]
final class VersionCheckTest extends TestCase
{
    private string $root;

    private AP_DB $db;

    private int $adminId = 0;

    private int $subscriberId = 0;

    /** @var list<array{method: string, url: string}> */
    private array $httpCalls = [];

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        require_once $this->root . '/ap-includes/version.php';
        require_once $this->root . '/ap-includes/class-ap-db.php';
        require_once $this->root . '/ap-includes/class-ap-migrator.php';
        require_once $this->root . '/ap-includes/class-ap-options.php';
        require_once $this->root . '/ap-includes/class-ap-transient.php';
        require_once $this->root . '/ap-includes/class-ap-user.php';
        require_once $this->root . '/ap-includes/class-ap-session.php';
        require_once $this->root . '/ap-includes/class-ap-roles.php';
        require_once $this->root . '/ap-includes/class-ap-nonce.php';
        require_once $this->root . '/ap-includes/class-ap-version-check.php';
        require_once $this->root . '/ap-includes/functions.php';
        require_once $this->root . '/ap-admin/includes/class-ap-admin.php';

        if (!defined('AP_NONCE_KEY')) {
            define('AP_NONCE_KEY', 'test-nonce-key-' . str_repeat('v', 32));
        }
        if (!defined('AP_NONCE_SALT')) {
            define('AP_NONCE_SALT', 'test-nonce-salt-' . str_repeat('c', 32));
        }
        if (!defined('AP_LOGGED_IN_KEY')) {
            define('AP_LOGGED_IN_KEY', 'test-logged-in-key-' . str_repeat('h', 32));
        }
        if (!defined('AP_LOGGED_IN_SALT')) {
            define('AP_LOGGED_IN_SALT', 'test-logged-in-salt-' . str_repeat('k', 32));
        }

        AP_Roles::flushCache();
        AP_Options::flushCache();
        AP_Version_Check::resetHttpTransport();
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

        AP_Options::update('siteurl', 'https://secret-site.example/blog', $this->db);
        AP_Options::update('home', 'https://secret-site.example/blog', $this->db);
        AP_Options::update('blogname', 'Secret Site', $this->db);
        AP_Options::update(AP_Version_Check::OPTION_ENABLED, '1', $this->db);

        $admin = AP_User::create([
            'user_login' => 'vcadmin',
            'user_email' => 'vcadmin@example.test',
            'password' => 'password123',
            'role' => 'administrator',
        ], $this->db);
        $this->adminId = (int) $admin['id'];

        $sub = AP_User::create([
            'user_login' => 'vcsub',
            'user_email' => 'vcsub@example.test',
            'password' => 'password123',
            'role' => 'subscriber',
        ], $this->db);
        $this->subscriberId = (int) $sub['id'];
    }

    protected function tearDown(): void
    {
        AP_Version_Check::resetHttpTransport();
        AP_Roles::flushCache();
        AP_Options::flushCache();
        AP_Admin::clearNotices();
        if (defined('AP_ADMIN') && AP_ADMIN) {
            // Leave constant as-is if already defined by another test; cannot undefine.
        }
    }

    public function testPrivacyInvariants(): void
    {
        $this->assertFalse(AP_Version_Check::sendsSiteIdentity());
        $this->assertSame(
            'https://agorapress.extrovertednerd.com/version.json',
            AP_Version_Check::DEFAULT_ENDPOINT
        );
    }

    public function testCompareVersionsAndIsNewer(): void
    {
        $this->assertSame(-1, AP_Version_Check::compareVersions('0.1.0', '0.2.0'));
        $this->assertSame(0, AP_Version_Check::compareVersions('1.0.0', '1.0.0'));
        $this->assertSame(1, AP_Version_Check::compareVersions('2.0.0', '1.9.9'));
        $this->assertTrue(AP_Version_Check::isNewer('1.0.0', '0.1.0-dev'));
        $this->assertTrue(AP_Version_Check::isNewer('0.2.0', '0.1.0-dev'));
        $this->assertFalse(AP_Version_Check::isNewer('0.1.0-dev', '0.1.0-dev'));
        $this->assertFalse(AP_Version_Check::isNewer('0.0.9', '0.1.0'));
        $this->assertTrue(AP_Version_Check::isNewer('v1.2.3', '1.2.2'));
    }

    public function testParseResponseBody(): void
    {
        $parsed = AP_Version_Check::parseResponseBody(json_encode([
            'version' => '1.2.3',
            'download_url' => 'https://example.com/agorapress-1.2.3.zip',
            'changelog_url' => 'https://example.com/changelog',
        ], JSON_THROW_ON_ERROR));

        $this->assertTrue($parsed['ok']);
        $this->assertSame('1.2.3', $parsed['version']);
        $this->assertSame('https://example.com/agorapress-1.2.3.zip', $parsed['download_url']);
        $this->assertSame('https://example.com/changelog', $parsed['changelog_url']);
        $this->assertSame('', $parsed['sha256']);

        $alt = AP_Version_Check::parseResponseBody(json_encode([
            'latest' => 'v2.0.0',
            'package' => 'https://cdn.example/pkg.zip',
            'changelog' => 'https://cdn.example/changes',
            'package_sha256' => 'sha256:' . str_repeat('cd', 32),
        ], JSON_THROW_ON_ERROR));
        $this->assertTrue($alt['ok']);
        $this->assertSame('2.0.0', $alt['version']);
        $this->assertSame(str_repeat('cd', 32), $alt['sha256']);

        $this->assertFalse(AP_Version_Check::parseResponseBody('not-json')['ok']);
        $this->assertFalse(AP_Version_Check::parseResponseBody('{}')['ok']);

        $bad = AP_Version_Check::parseResponseBody(json_encode([
            'version' => '1.0.0',
            'download_url' => 'javascript:alert(1)',
        ], JSON_THROW_ON_ERROR));
        $this->assertTrue($bad['ok']);
        $this->assertSame('', $bad['download_url']);
    }

    public function testFetchUsesGetAndNeverSendsDomain(): void
    {
        $this->installOkTransport([
            'version' => '9.9.9',
            'download_url' => 'https://agorapress.extrovertednerd.com/download/9.9.9.zip',
            'changelog_url' => 'https://agorapress.extrovertednerd.com/changelog',
        ]);

        $info = AP_Version_Check::getRemoteInfo($this->db, true);
        $this->assertTrue($info['ok']);
        $this->assertSame('9.9.9', $info['version']);
        $this->assertFalse($info['from_cache']);

        $this->assertCount(1, $this->httpCalls);
        $this->assertSame('GET', $this->httpCalls[0]['method']);
        $this->assertSame(AP_Version_Check::DEFAULT_ENDPOINT, $this->httpCalls[0]['url']);
        $this->assertStringNotContainsString('secret-site', $this->httpCalls[0]['url']);
        $this->assertStringNotContainsString('domain', strtolower($this->httpCalls[0]['url']));
        $this->assertStringNotContainsString('example', $this->httpCalls[0]['url']);
    }

    public function testTransientCacheAvoidsSecondFetch(): void
    {
        $this->installOkTransport(['version' => '3.0.0']);

        $first = AP_Version_Check::getRemoteInfo($this->db, true);
        $this->assertFalse($first['from_cache']);
        $this->assertCount(1, $this->httpCalls);

        $second = AP_Version_Check::getRemoteInfo($this->db, false);
        $this->assertTrue($second['from_cache']);
        $this->assertSame('3.0.0', $second['version']);
        $this->assertCount(1, $this->httpCalls);

        $cached = AP_Transient::get(AP_Version_Check::TRANSIENT_KEY, false, $this->db);
        $this->assertIsArray($cached);
        $this->assertSame('3.0.0', $cached['version'] ?? null);
    }

    public function testNetworkFailureIsSilentAndCachedSoftly(): void
    {
        AP_Version_Check::setHttpTransport(function (string $method, string $url): array {
            $this->httpCalls[] = ['method' => $method, 'url' => $url];

            return [
                'ok' => false,
                'status' => 0,
                'body' => '',
                'error' => 'timeout',
            ];
        });

        $info = AP_Version_Check::getRemoteInfo($this->db, true);
        $this->assertFalse($info['ok']);
        $this->assertSame('', $info['version']);
        $this->assertFalse(AP_Version_Check::hasUpdate($this->db));
        $this->assertSame('', AP_Version_Check::buildNoticeHtml($this->db));

        // Soft failure should be cached (no second HTTP call).
        $again = AP_Version_Check::getRemoteInfo($this->db, false);
        $this->assertTrue($again['from_cache']);
        $this->assertCount(1, $this->httpCalls);
    }

    public function testDisabledOptionSkipsFetch(): void
    {
        AP_Options::update(AP_Version_Check::OPTION_ENABLED, '0', $this->db);
        $this->installOkTransport(['version' => '9.9.9']);

        $info = AP_Version_Check::getRemoteInfo($this->db, true);
        $this->assertFalse($info['ok']);
        $this->assertCount(0, $this->httpCalls);
        $this->assertFalse(AP_Version_Check::isEnabled($this->db));
        $this->assertFalse(ap_version_check_enabled($this->db));
    }

    public function testHasUpdateAndNoticeHtmlWhenNewer(): void
    {
        $this->installOkTransport([
            'version' => '99.0.0',
            'download_url' => 'https://agorapress.extrovertednerd.com/dl.zip',
            'changelog_url' => 'https://agorapress.extrovertednerd.com/changelog',
        ]);

        $this->assertTrue(AP_Version_Check::hasUpdate($this->db));
        $this->assertTrue(ap_has_core_update($this->db));

        $html = AP_Version_Check::buildNoticeHtml($this->db);
        $this->assertStringContainsString('Update available', $html);
        $this->assertStringContainsString('99.0.0', $html);
        $this->assertStringContainsString('Update now', $html);
        $this->assertStringContainsString('update-core.php', $html);
        $this->assertStringContainsString('Download', $html);
        $this->assertStringContainsString('Changelog', $html);
        $this->assertStringContainsString('https://agorapress.extrovertednerd.com/dl.zip', $html);
        $this->assertStringContainsString('rel="noopener noreferrer"', $html);
    }

    public function testNoNoticeWhenRemoteNotNewer(): void
    {
        $current = AP_Version_Check::currentVersion();
        $this->installOkTransport([
            'version' => $current !== '' ? $current : '0.0.1',
            'download_url' => 'https://example.com/x.zip',
        ]);

        // If current is a -dev build equal to itself, isNewer is false.
        if ($current !== '') {
            $this->assertFalse(AP_Version_Check::hasUpdate($this->db));
            $this->assertSame('', AP_Version_Check::buildNoticeHtml($this->db));
        }

        // Explicitly older remote.
        AP_Transient::delete(AP_Version_Check::TRANSIENT_KEY, $this->db);
        $this->installOkTransport(['version' => '0.0.1']);
        $this->assertFalse(AP_Version_Check::isNewer('0.0.1', '0.1.0-dev'));
        $this->assertFalse(AP_Version_Check::hasUpdate($this->db));
    }

    public function testMaybeQueueAdminNoticeRequiresAdminContextAndCaps(): void
    {
        $this->installOkTransport([
            'version' => '99.0.0',
            'download_url' => 'https://example.com/dl.zip',
            'changelog_url' => 'https://example.com/cl',
        ]);

        // Without AP_ADMIN, no notice (skip if another test already defined the constant).
        if (!defined('AP_ADMIN')) {
            AP_Admin::clearNotices();
            $this->assertFalse(AP_Version_Check::maybeQueueAdminNotice($this->db, $this->adminId));
            $this->assertSame([], AP_Admin::getNotices());
            define('AP_ADMIN', true);
        }

        // Subscriber cannot see update notices.
        AP_Admin::clearNotices();
        $this->assertFalse(AP_Version_Check::maybeQueueAdminNotice($this->db, $this->subscriberId));
        $this->assertSame([], AP_Admin::getNotices());

        // Administrator can.
        AP_Admin::clearNotices();
        $this->assertTrue(AP_Version_Check::maybeQueueAdminNotice($this->db, $this->adminId));
        $notices = AP_Admin::getNotices();
        $this->assertCount(1, $notices);
        $this->assertSame('warning', $notices[0]['type']);
        $this->assertFalse($notices[0]['escape']);
        $this->assertStringContainsString('Update available', $notices[0]['message']);

        $rendered = AP_Admin::renderNotices();
        $this->assertStringContainsString('Update available', $rendered);
        $this->assertStringContainsString('<a href=', $rendered);
        $this->assertStringContainsString('Download', $rendered);
    }

    public function testProceduralApiWrappers(): void
    {
        $this->installOkTransport(['version' => '5.5.5']);

        $this->assertTrue(ap_version_check_enabled($this->db));
        $info = ap_get_remote_version_info($this->db, true);
        $this->assertTrue($info['ok']);
        $this->assertSame('5.5.5', $info['version']);

        $forced = ap_force_version_check($this->db);
        $this->assertTrue($forced['ok']);
        $this->assertGreaterThanOrEqual(2, count($this->httpCalls));
    }

    public function testForceCheckClearsCache(): void
    {
        $this->installOkTransport(['version' => '1.0.0']);
        AP_Version_Check::getRemoteInfo($this->db, true);
        $this->assertCount(1, $this->httpCalls);

        $this->installOkTransport(['version' => '2.0.0']);
        $info = AP_Version_Check::forceCheck($this->db);
        $this->assertSame('2.0.0', $info['version']);
        $this->assertFalse($info['from_cache']);
        $this->assertCount(2, $this->httpCalls);
    }

    /**
     * @param array<string, string> $payload
     */
    private function installOkTransport(array $payload): void
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $this->assertNotFalse($body);

        AP_Version_Check::setHttpTransport(function (string $method, string $url) use ($body): array {
            $this->httpCalls[] = ['method' => $method, 'url' => $url];

            return [
                'ok' => true,
                'status' => 200,
                'body' => (string) $body,
                'error' => '',
            ];
        });
    }
}
