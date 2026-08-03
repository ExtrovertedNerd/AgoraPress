<?php

/**
 * Tests for Object Cache API (AP_Object_Cache + ap_cache_*).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Options;

use AP_DB;
use AP_Migrator;
use AP_Object_Cache;
use AP_Options;
use AP_Transient;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_Object_Cache::class)]
final class ObjectCacheTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        require_once $this->root . '/ap-includes/class-ap-object-cache.php';
        require_once $this->root . '/ap-includes/class-ap-db.php';
        require_once $this->root . '/ap-includes/class-ap-migrator.php';
        require_once $this->root . '/ap-includes/class-ap-options.php';
        require_once $this->root . '/ap-includes/class-ap-transient.php';
        require_once $this->root . '/ap-includes/functions.php';

        ap_reset_object_cache(true);
        AP_Options::flushCache();
    }

    protected function tearDown(): void
    {
        ap_reset_object_cache(true);
        AP_Options::flushCache();
        unset($GLOBALS['apdb']);
    }

    public function testSetGetDelete(): void
    {
        $this->assertFalse(ap_cache_get('missing'));
        $found = null;
        ap_cache_get('missing', 'default', false, $found);
        $this->assertFalse($found);

        $this->assertTrue(ap_cache_set('greeting', 'hello', 'default', 0));
        $this->assertSame('hello', ap_cache_get('greeting'));
        $found = false;
        $this->assertSame('hello', ap_cache_get('greeting', 'default', false, $found));
        $this->assertTrue($found);

        // False is a valid stored value — $found distinguishes miss.
        $this->assertTrue(ap_cache_set('flag', false));
        $found = false;
        $this->assertFalse(ap_cache_get('flag', 'default', false, $found));
        $this->assertTrue($found);

        $this->assertTrue(ap_cache_delete('greeting'));
        $this->assertFalse(ap_cache_get('greeting'));
    }

    public function testAddAndReplace(): void
    {
        $this->assertTrue(ap_cache_add('k', 'v1'));
        $this->assertFalse(ap_cache_add('k', 'v2'));
        $this->assertSame('v1', ap_cache_get('k'));

        $this->assertTrue(ap_cache_replace('k', 'v3'));
        $this->assertSame('v3', ap_cache_get('k'));
        $this->assertFalse(ap_cache_replace('nope', 'x'));
    }

    public function testGroupsAreIsolated(): void
    {
        $this->assertTrue(ap_cache_set('same', 'a', 'group-a'));
        $this->assertTrue(ap_cache_set('same', 'b', 'group-b'));
        $this->assertSame('a', ap_cache_get('same', 'group-a'));
        $this->assertSame('b', ap_cache_get('same', 'group-b'));

        $this->assertTrue(ap_cache_flush_group('group-a'));
        $this->assertFalse(ap_cache_get('same', 'group-a'));
        $this->assertSame('b', ap_cache_get('same', 'group-b'));
    }

    public function testIncrDecr(): void
    {
        $this->assertFalse(ap_cache_incr('counter'));
        $this->assertTrue(ap_cache_set('counter', 5));
        $this->assertSame(7, ap_cache_incr('counter', 2));
        $this->assertSame(4, ap_cache_decr('counter', 3));
        $this->assertSame(0, ap_cache_decr('counter', 100));
    }

    public function testFlush(): void
    {
        ap_cache_set('a', 1);
        ap_cache_set('b', 2, 'other');
        $this->assertTrue(ap_cache_flush());
        $this->assertFalse(ap_cache_get('a'));
        $this->assertFalse(ap_cache_get('b', 'other'));
    }

    public function testExpiration(): void
    {
        $this->assertTrue(ap_cache_set('soon', 'x', 'default', 1));
        $this->assertSame('x', ap_cache_get('soon'));
        sleep(2);
        $found = true;
        $this->assertFalse(ap_cache_get('soon', 'default', false, $found));
        $this->assertFalse($found);
    }

    public function testClassDirectApi(): void
    {
        $cache = new AP_Object_Cache();
        $this->assertTrue($cache->set('k', ['n' => 1], 'g'));
        $found = false;
        $this->assertSame(['n' => 1], $cache->get('k', 'g', false, $found));
        $this->assertTrue($found);
        $this->assertTrue($cache->exists('k', 'g'));
        $cache->addGlobalGroups(['g']);
        $cache->addNonPersistentGroups(['counts']);
        $this->assertTrue($cache->isNonPersistentGroup('counts'));
        $this->assertTrue($cache->close());
    }

    public function testDefaultIsNotExternal(): void
    {
        $this->assertFalse(ap_using_ext_object_cache());
        $this->assertFalse(ap_using_object_cache());
    }

    public function testTransientsStayOnOptionsWithoutExternalCache(): void
    {
        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');
        (new AP_Migrator($db, AP_Migrator::defaultMigrationsPath()))->migrate();
        $GLOBALS['apdb'] = $db;

        $this->assertFalse(ap_using_ext_object_cache());
        $this->assertTrue(AP_Transient::set('opt_path', 'stored', 0, $db));
        $this->assertSame('stored', AP_Transient::get('opt_path', false, $db));
        // Options-backed: option row exists.
        $optName = AP_Transient::optionName('opt_path');
        $this->assertNotSame(false, AP_Options::get($optName, false, $db));
        // Not forced into object cache group.
        $found = false;
        ap_cache_get('opt_path', 'transient', false, $found);
        $this->assertFalse($found);
    }

    public function testTransientsUseObjectCacheWhenExternal(): void
    {
        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');
        (new AP_Migrator($db, AP_Migrator::defaultMigrationsPath()))->migrate();
        $GLOBALS['apdb'] = $db;

        ap_using_ext_object_cache(true);
        $this->assertTrue(ap_using_ext_object_cache());

        $this->assertTrue(AP_Transient::set('oc_path', 'from-cache', 60, $db));
        $this->assertSame('from-cache', AP_Transient::get('oc_path', false, $db));
        $this->assertTrue(AP_Transient::exists('oc_path', $db));

        // Stored in object cache group, not options.
        $found = false;
        $this->assertSame('from-cache', ap_cache_get('oc_path', 'transient', false, $found));
        $this->assertTrue($found);
        $this->assertFalse(AP_Options::get(AP_Transient::optionName('oc_path'), false, $db));

        $this->assertTrue(AP_Transient::delete('oc_path', $db));
        $this->assertFalse(AP_Transient::get('oc_path', false, $db));
    }

    public function testDropinIsLoadedAndMarksExternal(): void
    {
        $script = <<<'PHP'
<?php
declare(strict_types=1);
$root = $argv[1];
$content = sys_get_temp_dir() . '/ap-oc-content-' . bin2hex(random_bytes(4));
mkdir($content, 0700, true);
$dropin = $content . '/object-cache.php';
file_put_contents($dropin, <<<'DROP'
<?php
declare(strict_types=1);
class AP_Test_Ext_Cache {
    private array $d = [];
    public function get($k, $g = 'default', $force = false, &$found = null) {
        $key = $g . ':' . $k;
        if (!array_key_exists($key, $this->d)) { $found = false; return false; }
        $found = true; return $this->d[$key];
    }
    public function set($k, $v, $g = 'default', $e = 0) {
        $this->d[$g . ':' . $k] = $v; return true;
    }
    public function add($k, $v, $g = 'default', $e = 0) {
        $key = $g . ':' . $k;
        if (array_key_exists($key, $this->d)) return false;
        return $this->set($k, $v, $g, $e);
    }
    public function delete($k, $g = 'default') {
        $key = $g . ':' . $k;
        if (!array_key_exists($key, $this->d)) return false;
        unset($this->d[$key]); return true;
    }
    public function flush() { $this->d = []; return true; }
    public function replace($k, $v, $g = 'default', $e = 0) {
        $key = $g . ':' . $k;
        if (!array_key_exists($key, $this->d)) return false;
        return $this->set($k, $v, $g, $e);
    }
    public function incr($k, $o = 1, $g = 'default') { return false; }
    public function decr($k, $o = 1, $g = 'default') { return false; }
    public function close() { return true; }
    public function addGlobalGroups($g) {}
    public function addNonPersistentGroups($g) {}
    public function flushGroup($g) { return true; }
}
function ap_cache_init(): void {
    $GLOBALS['ap_object_cache'] = new AP_Test_Ext_Cache();
    $GLOBALS['ap_dropin_marker'] = 'ext-loaded';
}
function ap_cache_get(string|int $key, string $group = 'default', bool $force = false, ?bool &$found = null): mixed {
    return $GLOBALS['ap_object_cache']->get($key, $group, $force, $found);
}
function ap_cache_set(string|int $key, mixed $data, string $group = 'default', int $expire = 0): bool {
    return $GLOBALS['ap_object_cache']->set($key, $data, $group, $expire);
}
function ap_cache_add(string|int $key, mixed $data, string $group = 'default', int $expire = 0): bool {
    return $GLOBALS['ap_object_cache']->add($key, $data, $group, $expire);
}
function ap_cache_delete(string|int $key, string $group = 'default'): bool {
    return $GLOBALS['ap_object_cache']->delete($key, $group);
}
function ap_cache_flush(): bool { return $GLOBALS['ap_object_cache']->flush(); }
function ap_cache_replace(string|int $key, mixed $data, string $group = 'default', int $expire = 0): bool {
    return $GLOBALS['ap_object_cache']->replace($key, $data, $group, $expire);
}
function ap_cache_incr(string|int $key, int $offset = 1, string $group = 'default'): int|false { return false; }
function ap_cache_decr(string|int $key, int $offset = 1, string $group = 'default'): int|false { return false; }
function ap_cache_close(): bool { return true; }
function ap_cache_flush_group(string $group): bool { return true; }
function ap_cache_add_global_groups(array|string $groups): void {}
function ap_cache_add_non_persistent_groups(array|string $groups): void {}
DROP);

if (!defined('AP_ABSPATH')) {
    define('AP_ABSPATH', $root . '/');
}
if (!defined('AP_CONTENT_DIR')) {
    define('AP_CONTENT_DIR', $content);
}
require_once $root . '/ap-includes/class-ap-object-cache.php';
ap_start_object_cache();
if (!ap_using_ext_object_cache()) {
    fwrite(STDERR, "expected external cache\n");
    exit(1);
}
if (($GLOBALS['ap_dropin_marker'] ?? '') !== 'ext-loaded') {
    fwrite(STDERR, "drop-in marker missing\n");
    exit(1);
}
if (!($GLOBALS['ap_object_cache'] instanceof AP_Test_Ext_Cache)) {
    fwrite(STDERR, "wrong cache instance\n");
    exit(1);
}
ap_cache_set('from-dropin', 42);
$found = false;
$v = ap_cache_get('from-dropin', 'default', false, $found);
if ($v !== 42 || !$found) {
    fwrite(STDERR, "get/set failed\n");
    exit(1);
}
// Cleanup
@unlink($dropin);
@rmdir($content);
echo "OK\n";
exit(0);
PHP;

        $tmp = tempnam(sys_get_temp_dir(), 'ap-oc-');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, $script);

        $php = PHP_BINARY;
        $cmd = escapeshellarg($php) . ' ' . escapeshellarg($tmp) . ' ' . escapeshellarg($this->root);
        $output = [];
        $code = 0;
        exec($cmd . ' 2>&1', $output, $code);
        @unlink($tmp);

        $this->assertSame(0, $code, implode("\n", $output));
        $this->assertStringContainsString('OK', implode("\n", $output));
    }

    public function testDropinPathHelper(): void
    {
        $path = ap_object_cache_dropin_path();
        // Without AP_CONTENT_DIR/AP_ABSPATH in this process path may be empty or derived.
        $this->assertIsString($path);
    }
}
