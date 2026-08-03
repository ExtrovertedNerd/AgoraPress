<?php

/**
 * Tests for options autoload priming (performance).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Options;

use AP_DB;
use AP_Migrator;
use AP_Options;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_Options::class)]
final class AutoloadOptionsTest extends TestCase
{
    private string $root;

    private AP_DB $db;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        require_once $this->root . '/ap-includes/class-ap-db.php';
        require_once $this->root . '/ap-includes/class-ap-migrator.php';
        require_once $this->root . '/ap-includes/class-ap-options.php';
        require_once $this->root . '/ap-includes/functions.php';

        AP_Options::flushCache();

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
        AP_Options::flushCache();
    }

    public function testLoadAutoloadedPrimesCacheInOnePass(): void
    {
        AP_Options::update('blogname', 'Autoload Site', $this->db, 'yes');
        AP_Options::update('siteurl', 'https://example.test', $this->db, 'yes');
        AP_Options::update('secret_heavy', str_repeat('x', 100), $this->db, 'no');
        AP_Options::flushCache();

        $this->assertFalse(AP_Options::isAutoloaded());
        $this->db->resetQueryLog();

        $loaded = AP_Options::loadAutoloaded($this->db);
        $this->assertGreaterThanOrEqual(2, $loaded);
        $this->assertTrue(AP_Options::isAutoloaded());

        // Second call is a no-op.
        $this->assertSame(0, AP_Options::loadAutoloaded($this->db));

        $queriesBefore = $this->db->getNumQueries();
        $this->assertSame('Autoload Site', AP_Options::get('blogname', false, $this->db));
        $this->assertSame('https://example.test', AP_Options::get('siteurl', false, $this->db));
        // Cached hits must not issue extra SELECTs.
        $this->assertSame($queriesBefore, $this->db->getNumQueries());

        // Non-autoloaded still hits DB once after prime.
        $this->assertSame(str_repeat('x', 100), AP_Options::get('secret_heavy', false, $this->db));
    }

    public function testAutoloadStats(): void
    {
        AP_Options::update('blogname', 'Stats Site', $this->db, 'yes');
        AP_Options::update('not_auto', 'skip-me', $this->db, 'no');

        $stats = AP_Options::getAutoloadStats($this->db);
        $this->assertArrayHasKey('count', $stats);
        $this->assertArrayHasKey('bytes', $stats);
        $this->assertGreaterThanOrEqual(1, $stats['count']);
        $this->assertGreaterThanOrEqual(strlen('Stats Site'), $stats['bytes']);

        $viaFn = ap_get_autoload_option_stats($this->db);
        $this->assertSame($stats['count'], $viaFn['count']);
        $this->assertSame($stats['bytes'], $viaFn['bytes']);
    }

    public function testProceduralLoadWrapper(): void
    {
        AP_Options::update('blogname', 'Wrap', $this->db, 'yes');
        AP_Options::flushCache();
        $n = ap_load_autoloaded_options($this->db);
        $this->assertGreaterThanOrEqual(1, $n);
        $this->assertTrue(AP_Options::isAutoloaded());
    }
}
