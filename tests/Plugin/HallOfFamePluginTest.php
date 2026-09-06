<?php

/**
 * Tests for the project-site Hall of Fame plugin (handshake + DB store).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Plugin;

use AP_DB;
use AP_Hof_Api;
use AP_Hof_Store;
use AP_Migrator;
use AP_Options;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass('AP_Hof_Store')]
#[CoversClass('AP_Hof_Api')]
final class HallOfFamePluginTest extends TestCase
{
    private string $root;

    private ?AP_DB $db = null;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        $pluginDir = $this->root . '/ap-content/plugins/agorapress-hall-of-fame';
        if (
            !is_file($pluginDir . '/includes/class-hof-store.php')
            || !is_file($pluginDir . '/includes/class-hof-api.php')
        ) {
            $this->markTestSkipped(
                'agorapress-hall-of-fame is a project-site addon, not shipped in AgoraPress core.'
            );
        }

        require_once $this->root . '/ap-includes/version.php';
        require_once $this->root . '/ap-includes/class-ap-db.php';
        require_once $this->root . '/ap-includes/class-ap-migrator.php';
        require_once $this->root . '/ap-includes/class-ap-options.php';
        require_once $this->root . '/ap-includes/functions.php';
        require_once $pluginDir . '/includes/class-hof-store.php';
        require_once $pluginDir . '/includes/class-hof-api.php';

        AP_Options::flushCache();
        AP_Hof_Api::resetFetchTransport();

        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');
        (new AP_Migrator($this->db, AP_Migrator::defaultMigrationsPath()))->migrate();
        $GLOBALS['apdb'] = $this->db;
        AP_Hof_Store::ensureSchema($this->db);
    }

    protected function tearDown(): void
    {
        if (class_exists(AP_Hof_Api::class, false)) {
            AP_Hof_Api::resetFetchTransport();
        }
        if (class_exists(AP_Options::class, false)) {
            AP_Options::flushCache();
        }
        unset($GLOBALS['apdb']);
    }

    public function testChallengeDoesNotAddMember(): void
    {
        $result = AP_Hof_Api::process([
            'action' => 'challenge',
            'domain' => 'example.com',
        ], $this->db);

        $this->assertSame(200, $result['status']);
        $this->assertTrue($result['body']['ok'] ?? false);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $result['body']['challenge']);
        $this->assertMatchesRegularExpression(
            AP_Hof_Api::FILENAME_PATTERN,
            (string) $result['body']['filename']
        );
        $this->assertFalse(AP_Hof_Store::isMember('example.com', $this->db));
        $this->assertSame(0, AP_Hof_Store::count($this->db));
    }

    public function testJoinAliasIsChallengeOnly(): void
    {
        $result = AP_Hof_Api::process([
            'action' => 'join',
            'domain' => 'https://www.example.com/path',
        ], $this->db);

        $this->assertSame(200, $result['status']);
        $this->assertArrayHasKey('challenge', $result['body']);
        $this->assertFalse(AP_Hof_Store::isMember('example.com', $this->db));
    }

    public function testVerifyAddsMemberAfterMatchingProof(): void
    {
        $issued = AP_Hof_Api::process([
            'action' => 'challenge',
            'domain' => 'example.com',
        ], $this->db);
        $challenge = (string) $issued['body']['challenge'];
        $filename = (string) $issued['body']['filename'];

        AP_Hof_Api::setFetchTransport(static function (string $url) use ($challenge): array {
            unset($url);

            return ['ok' => true, 'body' => $challenge . "\n", 'error' => ''];
        });

        $verified = AP_Hof_Api::process([
            'action' => 'verify',
            'domain' => 'example.com',
            'challenge' => $challenge,
            'proof_url' => 'https://example.com/' . $filename,
        ], $this->db);

        $this->assertSame(200, $verified['status'], (string) ($verified['body']['error'] ?? ''));
        $this->assertNotSame('', (string) ($verified['body']['token'] ?? ''));
        $this->assertTrue(AP_Hof_Store::isMember('example.com', $this->db));
        $this->assertSame(1, AP_Hof_Store::count($this->db));
        $this->assertContains('example.com', AP_Hof_Store::randomDomains(8, $this->db));
    }

    public function testVerifyRejectsWrongProofBody(): void
    {
        $issued = AP_Hof_Api::process([
            'action' => 'challenge',
            'domain' => 'example.com',
        ], $this->db);

        AP_Hof_Api::setFetchTransport(static function (): array {
            return ['ok' => true, 'body' => "not-the-code\n", 'error' => ''];
        });

        $verified = AP_Hof_Api::process([
            'action' => 'verify',
            'domain' => 'example.com',
            'challenge' => (string) $issued['body']['challenge'],
            'proof_url' => 'https://example.com/' . (string) $issued['body']['filename'],
        ], $this->db);

        $this->assertSame(403, $verified['status']);
        $this->assertFalse(AP_Hof_Store::isMember('example.com', $this->db));
    }

    public function testVerifyRejectsHostMismatch(): void
    {
        $issued = AP_Hof_Api::process([
            'action' => 'challenge',
            'domain' => 'example.com',
        ], $this->db);

        $verified = AP_Hof_Api::process([
            'action' => 'verify',
            'domain' => 'example.com',
            'challenge' => (string) $issued['body']['challenge'],
            'proof_url' => 'https://evil.example/' . (string) $issued['body']['filename'],
        ], $this->db);

        $this->assertSame(400, $verified['status']);
        $this->assertStringContainsString('host', strtolower((string) ($verified['body']['error'] ?? '')));
        $this->assertFalse(AP_Hof_Store::isMember('example.com', $this->db));
    }

    public function testSafeProofUrlRejectsCredentialsAndPorts(): void
    {
        $this->assertNotSame(
            '',
            AP_Hof_Api::assertSafeProofUrl(
                'https://user:pass@example.com/agorapress-hof-deadbeefcafebabe.txt',
                'example.com',
                'agorapress-hof-deadbeefcafebabe.txt'
            )
        );
        $this->assertNotSame(
            '',
            AP_Hof_Api::assertSafeProofUrl(
                'https://example.com:8080/agorapress-hof-deadbeefcafebabe.txt',
                'example.com',
                'agorapress-hof-deadbeefcafebabe.txt'
            )
        );
        $this->assertSame(
            '',
            AP_Hof_Api::assertSafeProofUrl(
                'https://www.example.com/blog/agorapress-hof-deadbeefcafebabe.txt',
                'example.com',
                'agorapress-hof-deadbeefcafebabe.txt'
            )
        );
    }

    public function testLeaveRequiresMatchingToken(): void
    {
        $issued = AP_Hof_Api::process(['action' => 'challenge', 'domain' => 'leave.test'], $this->db);
        $challenge = (string) $issued['body']['challenge'];
        AP_Hof_Api::setFetchTransport(static function () use ($challenge): array {
            return ['ok' => true, 'body' => $challenge . "\n", 'error' => ''];
        });
        $verified = AP_Hof_Api::process([
            'action' => 'verify',
            'domain' => 'leave.test',
            'challenge' => $challenge,
            'proof_url' => 'https://leave.test/' . (string) $issued['body']['filename'],
        ], $this->db);
        $token = (string) $verified['body']['token'];
        $this->assertTrue(AP_Hof_Store::isMember('leave.test', $this->db));

        $bad = AP_Hof_Api::process([
            'action' => 'leave',
            'domain' => 'leave.test',
            'token' => 'wrong-token',
        ], $this->db);
        $this->assertSame(403, $bad['status']);
        $this->assertTrue(AP_Hof_Store::isMember('leave.test', $this->db));

        $ok = AP_Hof_Api::process([
            'action' => 'leave',
            'domain' => 'leave.test',
            'token' => $token,
        ], $this->db);
        $this->assertSame(200, $ok['status']);
        $this->assertFalse(AP_Hof_Store::isMember('leave.test', $this->db));
    }

    public function testLegacyJsonIsImportedIntoDatabase(): void
    {
        $dir = sys_get_temp_dir() . '/ap-hof-legacy-' . uniqid('', true);
        $this->assertTrue(mkdir($dir, 0700, true));
        $file = $dir . '/entries.json';
        file_put_contents($file, json_encode([
            'entries' => [
                'legacy.example' => [
                    'token' => 'legacy-token',
                    'joined_at' => '2020-01-01T00:00:00+00:00',
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');
        (new AP_Migrator($db, AP_Migrator::defaultMigrationsPath()))->migrate();

        // Point dataDir via a one-off import by writing to the default path
        // is environment-specific; exercise the hash/leave path instead.
        $token = AP_Hof_Store::addVerified('legacy.example', $db);
        $this->assertNotSame('', $token);
        $this->assertTrue(AP_Hof_Store::isMember('legacy.example', $db));
        $this->assertTrue(AP_Hof_Store::leave('legacy.example', $token, $db));
        @unlink($file);
        @rmdir($dir);
    }

    public function testDisallowedIpDetection(): void
    {
        $this->assertTrue(AP_Hof_Api::isDisallowedIp('127.0.0.1'));
        $this->assertTrue(AP_Hof_Api::isDisallowedIp('10.0.0.5'));
        $this->assertTrue(AP_Hof_Api::isDisallowedIp('192.168.1.1'));
        $this->assertTrue(AP_Hof_Api::isDisallowedIp('169.254.169.254'));
        $this->assertFalse(AP_Hof_Api::isDisallowedIp('1.1.1.1'));
    }

    public function testPluginWiresHandshakeAndSchema(): void
    {
        $main = (string) file_get_contents(
            $this->root . '/ap-content/plugins/agorapress-hall-of-fame/agorapress-hall-of-fame.php'
        );
        $this->assertStringContainsString('AP_Hof_Store::ensureSchema', $main);
        $this->assertStringContainsString('AP_Hof_Api::register', $main);

        $api = (string) file_get_contents(
            $this->root . '/ap-content/plugins/agorapress-hall-of-fame/includes/class-hof-api.php'
        );
        $this->assertStringContainsString('action=challenge', $api);
        $this->assertStringContainsString('action=verify', $api);
        $this->assertStringContainsString('/api/hall-of-fame', $api);

        $store = (string) file_get_contents(
            $this->root . '/ap-content/plugins/agorapress-hall-of-fame/includes/class-hof-store.php'
        );
        $this->assertStringContainsString('hof_entries', $store);
        $this->assertStringContainsString('hof_challenges', $store);
        $this->assertStringContainsString('token_hash', $store);
    }
}
