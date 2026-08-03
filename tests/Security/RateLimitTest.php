<?php

/**
 * Tests for AP_Rate_Limit — windows, lockouts, login protection, upload helpers.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Security;

use AP_DB;
use AP_Media;
use AP_Migrator;
use AP_Options;
use AP_Rate_Limit;
use AP_Session;
use AP_Transient;
use AP_User;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_Rate_Limit::class)]
final class RateLimitTest extends TestCase
{
    private string $root;

    private AP_DB $db;

    private static string $uploadsTmp = '';

    public static function setUpBeforeClass(): void
    {
        self::$uploadsTmp = sys_get_temp_dir() . '/ap-rl-media-' . bin2hex(random_bytes(4));
        mkdir(self::$uploadsTmp, 0777, true);
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$uploadsTmp !== '' && is_dir(self::$uploadsTmp)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(self::$uploadsTmp, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $file) {
                $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
            }
            @rmdir(self::$uploadsTmp);
        }
    }

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
        require_once $this->root . '/ap-includes/class-ap-rate-limit.php';
        require_once $this->root . '/ap-includes/class-ap-post.php';
        require_once $this->root . '/ap-includes/class-ap-media.php';
        require_once $this->root . '/ap-includes/functions.php';

        if (!defined('AP_LOGGED_IN_KEY')) {
            define('AP_LOGGED_IN_KEY', 'test-logged-in-key-' . str_repeat('a', 32));
        }
        if (!defined('AP_LOGGED_IN_SALT')) {
            define('AP_LOGGED_IN_SALT', 'test-logged-in-salt-' . str_repeat('b', 32));
        }

        AP_Rate_Limit::resetTestState();
        AP_Options::flushCache();
        AP_Session::clearLastLoginError();

        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');
        (new AP_Migrator($this->db, AP_Migrator::defaultMigrationsPath()))->migrate();
        $GLOBALS['apdb'] = $this->db;

        AP_Media::setBasedirOverride(self::$uploadsTmp);
        AP_Media::setBaseurlOverride('https://example.test/uploads');
        $this->emptyDir(self::$uploadsTmp);

        AP_Session::enableTestMode();
        AP_Session::resetCurrentUser();

        $_SERVER['REMOTE_ADDR'] = '203.0.113.50';
    }

    protected function tearDown(): void
    {
        AP_Rate_Limit::resetTestState();
        AP_Options::flushCache();
        AP_Session::disableTestMode();
        AP_Session::clearLastLoginError();
        AP_Media::setBasedirOverride(null);
        AP_Media::setBaseurlOverride(null);
        unset($GLOBALS['apdb'], $_SERVER['REMOTE_ADDR']);
    }

    public function testHitLocksAfterMaxAttempts(): void
    {
        AP_Rate_Limit::setTestLimits('login', ['max' => 3, 'window' => 600, 'lockout' => 300]);
        $bucket = 'ip:test-lock';

        $this->assertTrue(AP_Rate_Limit::check('login', $bucket, $this->db)['allowed']);

        AP_Rate_Limit::hit('login', $bucket, $this->db);
        AP_Rate_Limit::hit('login', $bucket, $this->db);
        $this->assertTrue(AP_Rate_Limit::check('login', $bucket, $this->db)['allowed']);
        $this->assertSame(1, AP_Rate_Limit::check('login', $bucket, $this->db)['remaining']);

        $after = AP_Rate_Limit::hit('login', $bucket, $this->db);
        $this->assertFalse($after['allowed']);
        $this->assertTrue($after['locked']);
        $this->assertGreaterThan(0, $after['retry_after']);
        $this->assertTrue(AP_Rate_Limit::isLimited('login', $bucket, $this->db));

        AP_Rate_Limit::clear('login', $bucket, $this->db);
        $this->assertFalse(AP_Rate_Limit::isLimited('login', $bucket, $this->db));
    }

    public function testProceduralHelpers(): void
    {
        $this->assertTrue(function_exists('ap_client_ip'));
        $this->assertTrue(function_exists('ap_rate_limit_check'));
        $this->assertTrue(function_exists('ap_rate_limit_hit'));
        $this->assertTrue(function_exists('ap_rate_limit_clear'));
        $this->assertTrue(function_exists('ap_check_login_rate_limit'));
        $this->assertTrue(function_exists('ap_get_last_login_error'));

        $this->assertSame('203.0.113.50', ap_client_ip());

        AP_Rate_Limit::setTestLimits('register', ['max' => 2, 'window' => 600, 'lockout' => 120]);
        $bucket = AP_Rate_Limit::ipBucket();
        ap_rate_limit_hit('register', $bucket, $this->db);
        ap_rate_limit_hit('register', $bucket, $this->db);
        $check = ap_rate_limit_check('register', $bucket, $this->db);
        $this->assertFalse($check['allowed']);
        ap_rate_limit_clear('register', $bucket, $this->db);
        $this->assertTrue(ap_rate_limit_check('register', $bucket, $this->db)['allowed']);
    }

    public function testLoginProtectionLocksOutBruteForce(): void
    {
        AP_Rate_Limit::setTestLimits('login', ['max' => 3, 'window' => 900, 'lockout' => 600]);

        $hash = AP_User::hashPassword('correct-horse');
        $this->db->insert('users', [
            'user_login' => 'victim',
            'user_pass' => $hash,
            'user_nicename' => 'victim',
            'user_email' => 'victim@example.test',
            'user_url' => '',
            'user_registered' => gmdate('Y-m-d H:i:s'),
            'user_activation_key' => '',
            'user_status' => 0,
            'display_name' => 'Victim',
        ]);

        for ($i = 0; $i < 3; $i++) {
            $user = AP_Session::login('victim', 'wrong-password', false, $this->db);
            $this->assertNull($user);
        }

        $err = AP_Session::getLastLoginError();
        $this->assertNotNull($err);
        $this->assertSame('rate_limited', $err['code']);
        $this->assertStringContainsString('Too many', $err['message']);

        // Even the correct password is blocked while locked.
        $blocked = AP_Session::login('victim', 'correct-horse', false, $this->db);
        $this->assertNull($blocked);
        $err2 = AP_Session::getLastLoginError();
        $this->assertNotNull($err2);
        $this->assertSame('rate_limited', $err2['code']);

        // Clear IP + identity buckets and succeed.
        AP_Rate_Limit::clearLogin('victim', '', $this->db);
        $ok = AP_Session::login('victim', 'correct-horse', false, $this->db);
        $this->assertInstanceOf(AP_User::class, $ok);
        $this->assertNull(AP_Session::getLastLoginError());
    }

    public function testSuccessfulLoginClearsFailures(): void
    {
        AP_Rate_Limit::setTestLimits('login', ['max' => 5, 'window' => 900, 'lockout' => 900]);

        $hash = AP_User::hashPassword('ok-pass-99');
        $this->db->insert('users', [
            'user_login' => 'alice',
            'user_pass' => $hash,
            'user_nicename' => 'alice',
            'user_email' => 'alice@example.test',
            'user_url' => '',
            'user_registered' => gmdate('Y-m-d H:i:s'),
            'user_activation_key' => '',
            'user_status' => 0,
            'display_name' => 'Alice',
        ]);

        AP_Session::login('alice', 'nope', false, $this->db);
        AP_Session::login('alice', 'nope', false, $this->db);
        // Still allowed (under max of 5).
        $gate = AP_Rate_Limit::checkLogin('alice', '', $this->db);
        $this->assertTrue($gate['allowed']);
        $ipState = AP_Rate_Limit::check('login', AP_Rate_Limit::ipBucket(), $this->db);
        $this->assertSame(2, $ipState['attempts']);

        $user = AP_Session::login('alice', 'ok-pass-99', false, $this->db);
        $this->assertInstanceOf(AP_User::class, $user);

        // Counters cleared — remaining full budget.
        $ipCheck = AP_Rate_Limit::check('login', AP_Rate_Limit::ipBucket(), $this->db);
        $this->assertSame(0, $ipCheck['attempts']);
    }

    public function testClientIpPrefersRemoteAddrWithoutTrustProxy(): void
    {
        $_SERVER['REMOTE_ADDR'] = '198.51.100.9';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '8.8.8.8';
        $this->assertSame('198.51.100.9', AP_Rate_Limit::clientIp());
        unset($_SERVER['HTTP_X_FORWARDED_FOR']);
    }

    public function testSanitizeIpAndBuckets(): void
    {
        $this->assertSame('127.0.0.1', AP_Rate_Limit::sanitizeIp('127.0.0.1'));
        $this->assertSame('', AP_Rate_Limit::sanitizeIp('not-an-ip'));
        $this->assertStringStartsWith('id:', AP_Rate_Limit::identityBucket('Admin@Example.COM'));
        $this->assertSame(
            AP_Rate_Limit::identityBucket('admin@example.com'),
            AP_Rate_Limit::identityBucket('Admin@Example.COM')
        );
        $this->assertStringStartsWith('ip:', AP_Rate_Limit::ipBucket('203.0.113.1'));
        $this->assertSame('user:42', AP_Rate_Limit::userBucket(42));
    }

    public function testLockoutMessage(): void
    {
        $this->assertStringContainsString('second', AP_Rate_Limit::lockoutMessage(45));
        $this->assertStringContainsString('minute', AP_Rate_Limit::lockoutMessage(120));
        $this->assertStringContainsString('hour', AP_Rate_Limit::lockoutMessage(7200));
    }

    public function testUploadHardeningRejectsScriptsAndBadSvg(): void
    {
        $php = AP_Media::checkFileType('shell.php');
        $this->assertFalse($php['ok']);

        $double = AP_Media::checkFileType('photo.php.jpg');
        $this->assertFalse($double['ok']);

        $exe = AP_Media::checkFileType('payload.exe');
        $this->assertFalse($exe['ok']);

        $bat = AP_Media::checkFileType('run.bat');
        $this->assertFalse($bat['ok']);

        // SVG with script payload.
        $svgPath = self::$uploadsTmp . '/evil.svg';
        file_put_contents(
            $svgPath,
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'
        );
        $svg = AP_Media::checkFileType('icon.svg', $svgPath);
        $this->assertFalse($svg['ok']);
        $this->assertStringContainsString('script', strtolower($svg['error']));

        // SVG with onload handler.
        file_put_contents(
            $svgPath,
            '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><rect width="1" height="1"/></svg>'
        );
        $svg2 = AP_Media::checkFileType('icon.svg', $svgPath);
        $this->assertFalse($svg2['ok']);

        // Clean minimal SVG is allowed.
        file_put_contents(
            $svgPath,
            '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect width="10" height="10" fill="#000"/></svg>'
        );
        $svgOk = AP_Media::checkFileType('icon.svg', $svgPath);
        $this->assertTrue($svgOk['ok'], $svgOk['error']);
        $this->assertSame('image/svg+xml', $svgOk['type']);
    }

    public function testUploadHardeningRequiresValidRaster(): void
    {
        $tmp = self::$uploadsTmp . '/fake.png';
        file_put_contents($tmp, 'not a real png at all');
        $check = AP_Media::checkFileType('fake.png', $tmp);
        $this->assertFalse($check['ok']);
        $this->assertNotSame('', $check['error']);
    }

    public function testHandleUploadRateLimit(): void
    {
        AP_Rate_Limit::setTestLimits('upload', ['max' => 2, 'window' => 600, 'lockout' => 120]);

        $png = $this->writeMinimalPng();
        $file = [
            'name' => 'a.png',
            'type' => 'image/png',
            'tmp_name' => $png,
            'error' => UPLOAD_ERR_OK,
            'size' => (int) filesize($png),
        ];

        $r1 = AP_Media::handleUpload($file, ['test_mode' => true, 'post_author' => 7], $this->db);
        $this->assertTrue($r1['ok'], $r1['error']);

        $png2 = $this->writeMinimalPng();
        $file['tmp_name'] = $png2;
        $file['name'] = 'b.png';
        $file['size'] = (int) filesize($png2);
        $r2 = AP_Media::handleUpload($file, ['test_mode' => true, 'post_author' => 7], $this->db);
        $this->assertTrue($r2['ok'], $r2['error']);

        $png3 = $this->writeMinimalPng();
        $file['tmp_name'] = $png3;
        $file['name'] = 'c.png';
        $file['size'] = (int) filesize($png3);
        $r3 = AP_Media::handleUpload($file, ['test_mode' => true, 'post_author' => 7], $this->db);
        $this->assertFalse($r3['ok']);
        $this->assertStringContainsString('Too many', $r3['error']);

        // skip_rate_limit bypass for trusted internal paths.
        $png4 = $this->writeMinimalPng();
        $file['tmp_name'] = $png4;
        $file['name'] = 'd.png';
        $file['size'] = (int) filesize($png4);
        $r4 = AP_Media::handleUpload(
            $file,
            ['test_mode' => true, 'post_author' => 7, 'skip_rate_limit' => true],
            $this->db
        );
        $this->assertTrue($r4['ok'], $r4['error']);
    }

    public function testBootstrapLoadsRateLimit(): void
    {
        $src = (string) file_get_contents($this->root . '/ap-includes/bootstrap.php');
        $this->assertStringContainsString('class-ap-rate-limit.php', $src);
    }

    public function testInstallerSeedsRateLimitOptions(): void
    {
        $src = (string) file_get_contents($this->root . '/ap-includes/class-ap-installer.php');
        $this->assertStringContainsString('rate_limit_login_max', $src);
        $this->assertStringContainsString('rate_limit_upload_max', $src);
    }

    private function writeMinimalPng(): string
    {
        // 1×1 transparent PNG.
        $bin = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true
        );
        $this->assertNotFalse($bin);
        $path = self::$uploadsTmp . '/t-' . bin2hex(random_bytes(4)) . '.png';
        file_put_contents($path, $bin);

        return $path;
    }

    private function emptyDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->emptyDir($path);
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }
    }
}
