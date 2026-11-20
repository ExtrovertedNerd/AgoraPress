<?php

/**
 * AP-004 open redirect, AP-006 hidden forum page, AP-009 security headers.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Security;

use AP_DB;
use AP_Forum;
use AP_Migrator;
use AP_Roles;
use AP_Security_Guard;
use AP_User;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_Security_Guard::class)]
final class SecurityGuardTest extends TestCase
{
    private string $root;

    private AP_DB $db;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        foreach (
            [
                'version', 'hooks', 'class-ap-db', 'class-ap-migrator', 'class-ap-options', 'class-ap-user',
                'class-ap-post', 'class-ap-roles', 'class-ap-forum', 'class-ap-security-guard', 'functions',
            ] as $f
        ) {
            require_once $this->root . '/ap-includes/' . $f . '.php';
        }
        AP_Roles::flushCache();
        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');
        (new AP_Migrator($this->db, AP_Migrator::defaultMigrationsPath()))->migrate();
        $GLOBALS['apdb'] = $this->db;
        AP_Roles::ensureDefaults($this->db);
    }

    protected function tearDown(): void
    {
        AP_Roles::flushCache();
        unset($GLOBALS['apdb']);
    }

    public function testLocalRedirectRules(): void
    {
        foreach (['/', '/ap-admin/', '/ap-admin/edit.php?post_type=page', '/forums/#top', '/a//b'] as $ok) {
            $this->assertTrue(AP_Security_Guard::isSafeLocalPath($ok), "should allow {$ok}");
        }
        foreach (
            [
                '//evil.example/', '/\\evil.example', '\\\\evil.example', '/\\/evil.example', "/\t/evil.example",
                "/%09/evil.example", '/%2F%2Fevil.example', '/%5Cevil.example', "/\n//evil.example", 'evil.example',
                'javascript:alert(1)', '', ' //evil.example',
            ] as $bad
        ) {
            $this->assertFalse(AP_Security_Guard::isSafeLocalPath($bad), 'should reject ' . json_encode($bad));
        }
    }

    public function testAdminSanitizeRedirectRejectsProtocolRelative(): void
    {
        require_once $this->root . '/ap-admin/includes/class-ap-admin.php';
        $fallback = \AP_Admin::sanitizeRedirect('');
        $this->assertSame($fallback, \AP_Admin::sanitizeRedirect('//evil.example/'));
        $this->assertSame($fallback, \AP_Admin::sanitizeRedirect('/\\evil.example'));
        $this->assertSame('/ap-admin/edit.php', \AP_Admin::sanitizeRedirect('/ap-admin/edit.php'));
    }

    public function testDefaultSecurityHeaders(): void
    {
        $h = AP_Security_Guard::headers();
        $this->assertSame('SAMEORIGIN', $h['X-Frame-Options'] ?? null);
        $this->assertSame('nosniff', $h['X-Content-Type-Options'] ?? null);
        $this->assertSame("frame-ancestors 'self'", $h['Content-Security-Policy'] ?? null);
    }

    public function testHeadersFilterCanAdjustAndDrop(): void
    {
        ap_add_filter('ap_security_headers', static function (array $h): array {
            unset($h['X-Frame-Options']);
            $h['Referrer-Policy'] = 'same-origin';

            return $h;
        });
        $h = AP_Security_Guard::headers();
        $this->assertArrayNotHasKey('X-Frame-Options', $h);
        $this->assertSame('same-origin', $h['Referrer-Policy']);
        $this->assertSame('nosniff', $h['X-Content-Type-Options']);
        if (function_exists('ap_reset_hooks')) {
            ap_reset_hooks();
        }
    }

    public function testHeadersAreSentOnFrontAndAdmin(): void
    {
        $index = (string) file_get_contents($this->root . '/index.php');
        $this->assertStringContainsString('AP_Security_Guard::sendHeaders()', $index);
        $admin = (string) file_get_contents($this->root . '/ap-admin/admin-bootstrap.php');
        $this->assertStringContainsString('AP_Security_Guard::sendHeaders()', $admin);
        $login = (string) file_get_contents($this->root . '/ap-admin/login.php');
        $this->assertTrue(
            str_contains($login, 'admin-bootstrap.php') || str_contains($login, 'AP_Security_Guard::sendHeaders()'),
            'login screen sends the headers'
        );
    }

    public function testHiddenForumOnlyListableToForumManagers(): void
    {
        $hidden = AP_Forum::insertForum(['forum_name' => 'Secret', 'forum_desc' => 'Shh', 'forum_status' => 'hidden'], $this->db);
        $open = AP_Forum::insertForum(['forum_name' => 'Open', 'forum_status' => 'open'], $this->db);
        $admin = $this->user('boss', 'administrator');
        $member = $this->user('pat', 'subscriber');

        $this->assertTrue(AP_Forum::isListableToUser(0, $open, $this->db));
        $this->assertFalse(AP_Forum::isListableToUser(0, $hidden, $this->db), 'guest');
        $this->assertFalse(AP_Forum::isListableToUser($member, $hidden, $this->db), 'subscriber');
        $this->assertTrue(AP_Forum::isListableToUser($admin, $hidden, $this->db), 'admin');

        $this->assertFalse(AP_Security_Guard::canSeeHiddenForum(0, $this->db));
        $this->assertFalse(AP_Security_Guard::canSeeHiddenForum($member, $this->db));
        $this->assertTrue(AP_Security_Guard::canSeeHiddenForum($admin, $this->db));
    }

    private function user(string $login, string $role): int
    {
        $this->db->insert('users', [
            'user_login' => $login, 'user_pass' => AP_User::hashPassword('x-' . $login), 'user_nicename' => $login,
            'user_email' => $login . '@example.test', 'user_url' => '', 'user_registered' => gmdate('Y-m-d H:i:s'),
            'user_activation_key' => '', 'user_status' => 0, 'display_name' => $login,
        ]);
        $id = (int) $this->db->lastInsertId();
        AP_Roles::setUserRole($id, $role, $this->db);

        return $id;
    }
}
