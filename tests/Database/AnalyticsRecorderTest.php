<?php

/**
 * Tests for server-side analytics hit recorder (AP_Analytics).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Database;

use AP_Analytics;
use AP_DB;
use AP_Migrator;
use AP_Options;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_Analytics::class)]
final class AnalyticsRecorderTest extends TestCase
{
    private string $root;

    private AP_DB $db;

    /** @var array<string, mixed> */
    private array $serverBackup = [];

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        require_once $this->root . '/ap-includes/version.php';
        require_once $this->root . '/ap-includes/hooks.php';
        require_once $this->root . '/ap-includes/class-ap-db.php';
        require_once $this->root . '/ap-includes/class-ap-migrator.php';
        require_once $this->root . '/ap-includes/class-ap-options.php';
        require_once $this->root . '/ap-includes/class-ap-analytics.php';
        require_once $this->root . '/ap-includes/functions.php';

        AP_Options::flushCache();
        AP_Analytics::resetRequestState();
        if (function_exists('ap_reset_hooks')) {
            ap_reset_hooks();
        }

        $this->serverBackup = $_SERVER;

        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');
        $migrator = new AP_Migrator($this->db, AP_Migrator::defaultMigrationsPath());
        $migrator->migrate();

        // PHPUnit runs under CLI; treat tests as web unless a case says otherwise.
        ap_add_filter('ap_analytics_cli_context', static fn (): bool => false);
        // No current user / caps layer in this fixture — not an admin.
        ap_add_filter('ap_analytics_exclude_admins', static fn (): bool => false);

        $this->seedPublicGet('/');
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        AP_Analytics::resetRequestState();
        AP_Options::flushCache();
        if (function_exists('ap_reset_hooks')) {
            ap_reset_hooks();
        }
        unset($GLOBALS['ap_query'], $GLOBALS['ap_rewrite_vars'], $GLOBALS['apdb']);
    }

    /**
     * @param array<string, string> $extra
     */
    private function seedPublicGet(string $uri, array $extra = []): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = $uri;
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Test Browser)';
        unset($_SERVER['HTTP_DNT'], $_SERVER['HTTP_REFERER']);
        foreach ($extra as $k => $v) {
            $_SERVER[$k] = $v;
        }
    }

    private function enableAnalytics(): void
    {
        AP_Options::update(AP_Analytics::OPTION_ENABLED, '1', $this->db);
        AP_Options::flushCache();
    }

    private function countHits(): int
    {
        return (int) $this->db->getVar(
            'SELECT COUNT(*) FROM ' . $this->db->quoteIdentifier($this->db->analytics_hits)
        );
    }

    public function testRecordHitWritesRowWhenEnabled(): void
    {
        $this->enableAnalytics();

        $id = AP_Analytics::recordHit([
            'path' => '/hello-world',
            'object_id' => 7,
            'status_code' => 200,
            'referrer' => 'https://example.com/from',
            'ua_class' => AP_Analytics::UA_BROWSER,
            'is_admin' => 0,
            'hit_time' => '2026-08-05 12:00:00',
        ], $this->db);

        $this->assertGreaterThan(0, $id);
        $this->assertSame(1, $this->countHits());

        $row = $this->db->getRow(
            'SELECT path, object_id, status_code, referrer, ua_class, is_admin FROM '
            . $this->db->quoteIdentifier($this->db->analytics_hits)
            . ' WHERE hit_id = ?',
            [$id]
        );
        $this->assertNotNull($row);
        $this->assertSame('/hello-world', $row->path);
        $this->assertSame(7, (int) $row->object_id);
        $this->assertSame(200, (int) $row->status_code);
        $this->assertSame('https://example.com/from', $row->referrer);
        $this->assertSame('browser', $row->ua_class);
        $this->assertSame(0, (int) $row->is_admin);
    }

    public function testRecordHitNoOpWhenDisabled(): void
    {
        // Default off.
        $id = AP_Analytics::recordHit([
            'path' => '/nope',
            'status_code' => 200,
        ], $this->db);

        $this->assertSame(0, $id);
        $this->assertSame(0, $this->countHits());

        // Explicit force still works for tooling/tests.
        $id = AP_Analytics::recordHit([
            'path' => '/forced',
            'status_code' => 200,
        ], $this->db, ['check_enabled' => false]);
        $this->assertGreaterThan(0, $id);
        $this->assertSame(1, $this->countHits());
    }

    public function testMaybeRecordCurrentRequestWhenEnabled(): void
    {
        $this->enableAnalytics();
        $this->seedPublicGet('/about', [
            'HTTP_REFERER' => 'https://news.example/',
            'HTTP_USER_AGENT' => 'Mozilla/5.0 (X11; Linux x86_64) Chrome/120.0.0.0',
        ]);

        $id = AP_Analytics::maybeRecordCurrentRequest($this->db);
        $this->assertGreaterThan(0, $id);
        $this->assertSame(1, $this->countHits());

        $row = $this->db->getRow(
            'SELECT path, status_code, ua_class, referrer FROM '
            . $this->db->quoteIdentifier($this->db->analytics_hits)
            . ' WHERE hit_id = ?',
            [$id]
        );
        $this->assertSame('/about', $row->path);
        $this->assertSame(200, (int) $row->status_code);
        $this->assertSame('browser', $row->ua_class);
        $this->assertSame('https://news.example/', $row->referrer);

        // Second call in same request is a no-op.
        $this->assertSame(0, AP_Analytics::maybeRecordCurrentRequest($this->db));
        $this->assertSame(1, $this->countHits());
    }

    public function testMaybeRecordSkippedWhenDisabled(): void
    {
        $this->seedPublicGet('/public');
        $this->assertFalse(AP_Analytics::isEnabled($this->db));
        $this->assertFalse(AP_Analytics::shouldRecordRequest($this->db));
        $this->assertSame(0, AP_Analytics::maybeRecordCurrentRequest($this->db));
        $this->assertSame(0, $this->countHits());
    }

    public function testSkipsApAdminPath(): void
    {
        $this->enableAnalytics();
        $this->seedPublicGet('/ap-admin/index.php');
        $this->assertFalse(AP_Analytics::shouldRecordRequest($this->db));
        $this->assertSame(0, AP_Analytics::maybeRecordCurrentRequest($this->db));
        $this->assertSame(0, $this->countHits());
    }

    public function testSkipsFeedAndRestAndSitemapPaths(): void
    {
        $this->enableAnalytics();

        foreach (['/feed/', '/feed', '/ap-json/wp/v2/posts', '/sitemap.xml', '/robots.txt', '/?feed=rss2'] as $uri) {
            AP_Analytics::resetRequestState();
            $this->seedPublicGet($uri);
            $this->assertFalse(
                AP_Analytics::shouldRecordRequest($this->db),
                "Expected skip for {$uri}"
            );
            $this->assertSame(0, AP_Analytics::maybeRecordCurrentRequest($this->db));
        }
        $this->assertSame(0, $this->countHits());
    }

    public function testSkipsObviousBots(): void
    {
        $this->enableAnalytics();
        $this->seedPublicGet('/post', [
            'HTTP_USER_AGENT' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
        ]);
        $this->assertSame(AP_Analytics::UA_BOT, AP_Analytics::classifyUserAgent((string) $_SERVER['HTTP_USER_AGENT']));
        $this->assertFalse(AP_Analytics::shouldRecordRequest($this->db));
        $this->assertSame(0, AP_Analytics::maybeRecordCurrentRequest($this->db));
        $this->assertSame(0, $this->countHits());
    }

    public function testSkipsPostMethod(): void
    {
        $this->enableAnalytics();
        $this->seedPublicGet('/form');
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->assertFalse(AP_Analytics::shouldRecordRequest($this->db));
    }

    public function testSkipsDoNotTrack(): void
    {
        $this->enableAnalytics();
        $this->seedPublicGet('/private', ['HTTP_DNT' => '1']);
        $this->assertFalse(AP_Analytics::shouldRecordRequest($this->db));
        $this->assertSame(0, AP_Analytics::maybeRecordCurrentRequest($this->db));
    }

    public function testRecords404ByDefault(): void
    {
        $this->enableAnalytics();
        $this->seedPublicGet('/missing-page');
        http_response_code(404);

        $this->assertTrue(AP_Analytics::shouldRecordRequest($this->db));
        $id = AP_Analytics::maybeRecordCurrentRequest($this->db);
        $this->assertGreaterThan(0, $id);

        $status = (int) $this->db->getVar(
            'SELECT status_code FROM ' . $this->db->quoteIdentifier($this->db->analytics_hits)
            . ' WHERE hit_id = ?',
            [$id]
        );
        $this->assertSame(404, $status);

        // Optional: skip 404 via filter.
        AP_Analytics::resetRequestState();
        ap_add_filter('ap_analytics_record_404', static fn (): bool => false);
        $this->assertFalse(AP_Analytics::shouldRecordRequest($this->db));

        // Restore response code for other tests.
        http_response_code(200);
    }

    public function testNormalizePathStripsQueryAndCapsLength(): void
    {
        $this->assertSame('/about', AP_Analytics::normalizePath('/about?utm=1#top'));
        $this->assertSame('/', AP_Analytics::normalizePath(''));
        $this->assertSame('/foo/bar', AP_Analytics::normalizePath('foo/bar/'));
        $this->assertSame('/a/b', AP_Analytics::normalizePath('//a//b//'));

        $long = '/' . str_repeat('p', 600);
        $norm = AP_Analytics::normalizePath($long);
        $this->assertLessThanOrEqual(AP_Analytics::MAX_PATH_LENGTH, strlen($norm));
    }

    public function testClassifyUserAgent(): void
    {
        $this->assertSame(
            AP_Analytics::UA_BROWSER,
            AP_Analytics::classifyUserAgent('Mozilla/5.0 (Windows NT 10.0) Chrome/120.0.0.0')
        );
        $this->assertSame(
            AP_Analytics::UA_BOT,
            AP_Analytics::classifyUserAgent('curl/8.0.0')
        );
        $this->assertSame(
            AP_Analytics::UA_OTHER,
            AP_Analytics::classifyUserAgent('')
        );
    }

    public function testProceduralWrappers(): void
    {
        $this->enableAnalytics();
        $this->assertTrue(ap_analytics_enabled($this->db));
        $this->assertTrue(ap_analytics_should_record($this->db));

        $id = ap_analytics_record_hit([
            'path' => '/via-helper',
            'status_code' => 200,
            'ua_class' => 'browser',
        ], $this->db);
        $this->assertGreaterThan(0, $id);

        AP_Analytics::resetRequestState();
        $id2 = ap_analytics_maybe_record($this->db);
        $this->assertGreaterThan(0, $id2);
        $this->assertSame(2, $this->countHits());
    }

    public function testTruncateReferrer(): void
    {
        $this->assertSame('', AP_Analytics::truncateReferrer(''));
        $long = 'https://example.com/' . str_repeat('x', 600);
        $out = AP_Analytics::truncateReferrer($long);
        $this->assertLessThanOrEqual(AP_Analytics::MAX_REFERRER_LENGTH, strlen($out));
    }
}
