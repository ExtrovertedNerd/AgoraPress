<?php

/**
 * Tests for Transients API (AP_Transient).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Options;

use AP_DB;
use AP_Migrator;
use AP_Options;
use AP_Transient;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_Transient::class)]
final class TransientTest extends TestCase
{
    private string $root;

    private AP_DB $db;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        require_once $this->root . '/ap-includes/class-ap-db.php';
        require_once $this->root . '/ap-includes/class-ap-migrator.php';
        require_once $this->root . '/ap-includes/class-ap-options.php';
        require_once $this->root . '/ap-includes/class-ap-transient.php';
        require_once $this->root . '/ap-includes/functions.php';

        AP_Options::flushCache();

        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');
        (new AP_Migrator($this->db, AP_Migrator::defaultMigrationsPath()))->migrate();
        $GLOBALS['apdb'] = $this->db;
    }

    protected function tearDown(): void
    {
        AP_Options::flushCache();
        unset($GLOBALS['apdb']);
    }

    public function testSetGetDelete(): void
    {
        $this->assertFalse(AP_Transient::get('missing', false, $this->db));
        $this->assertTrue(AP_Transient::set('greeting', 'hello', 0, $this->db));
        $this->assertSame('hello', AP_Transient::get('greeting', false, $this->db));
        $this->assertTrue(AP_Transient::exists('greeting', $this->db));
        $this->assertSame(0, AP_Transient::ttl('greeting', $this->db));

        $this->assertTrue(AP_Transient::delete('greeting', $this->db));
        $this->assertFalse(AP_Transient::get('greeting', false, $this->db));
    }

    public function testExpiration(): void
    {
        $this->assertTrue(AP_Transient::set('soon', ['a' => 1], 60, $this->db));
        $this->assertSame(['a' => 1], AP_Transient::get('soon', false, $this->db));
        $ttl = AP_Transient::ttl('soon', $this->db);
        $this->assertIsInt($ttl);
        $this->assertGreaterThan(0, $ttl);
        $this->assertLessThanOrEqual(60, $ttl);

        // Force expiry by rewriting timeout option.
        AP_Options::update(AP_Transient::timeoutOptionName('soon'), (string) (time() - 10), $this->db);
        AP_Options::flushCache();
        $this->assertFalse(AP_Transient::get('soon', false, $this->db));
        $this->assertFalse(AP_Transient::exists('soon', $this->db));
    }

    public function testProceduralHelpers(): void
    {
        $this->assertTrue(ap_set_transient('proc', 'value', 120, $this->db));
        $this->assertSame('value', ap_get_transient('proc', false, $this->db));
        $this->assertTrue(ap_delete_transient('proc', $this->db));
        $this->assertFalse(ap_get_transient('proc', false, $this->db));
    }

    public function testRejectsInvalidNames(): void
    {
        $this->assertFalse(AP_Transient::set('', 'x', 0, $this->db));
        $this->assertFalse(AP_Transient::set(str_repeat('a', 200), 'x', 0, $this->db));
        $this->assertFalse(AP_Transient::get('bad name!', false, $this->db));
    }
}
