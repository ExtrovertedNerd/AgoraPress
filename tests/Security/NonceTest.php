<?php

/**
 * Unit tests for AP_Nonce + form-audit (every POST form carries CSRF).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Security;

use AP_Nonce;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_Nonce::class)]
final class NonceTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        require_once $this->root . '/ap-includes/class-ap-nonce.php';
        require_once $this->root . '/ap-includes/functions.php';

        if (!defined('AP_NONCE_KEY')) {
            define('AP_NONCE_KEY', 'test-nonce-key-' . str_repeat('k', 32));
        }
        if (!defined('AP_NONCE_SALT')) {
            define('AP_NONCE_SALT', 'test-nonce-salt-' . str_repeat('s', 32));
        }
    }

    public function testCreateAndCheckRoundTrip(): void
    {
        $nonce = ap_create_nonce('save-settings', 42);
        $this->assertNotSame('', $nonce);
        $this->assertGreaterThanOrEqual(10, strlen($nonce));
        $this->assertTrue(ap_check_nonce($nonce, 'save-settings', 42));
        $this->assertSame(1, ap_verify_nonce($nonce, 'save-settings', 42));
    }

    public function testRejectsWrongActionOrUser(): void
    {
        $nonce = ap_create_nonce('save-settings', 42);
        $this->assertFalse(ap_check_nonce($nonce, 'other-action', 42));
        $this->assertFalse(ap_check_nonce($nonce, 'save-settings', 99));
        $this->assertFalse(ap_check_nonce('not-a-real-nonce', 'save-settings', 42));
        $this->assertFalse(ap_check_nonce('', 'save-settings', 42));
    }

    public function testFieldHtml(): void
    {
        $html = ap_nonce_field('form-action', '_ap_nonce', false, 7);
        $this->assertStringContainsString('name="_ap_nonce"', $html);
        $this->assertStringContainsString('type="hidden"', $html);
        $nonce = ap_create_nonce('form-action', 7);
        $this->assertStringContainsString($nonce, $html);
        $this->assertStringNotContainsString('_ap_http_referer', $html);
    }

    public function testFieldWithReferer(): void
    {
        $_SERVER['REQUEST_URI'] = '/ap-admin/options-general.php?x=1';
        $html = ap_nonce_field('form-action', '_ap_nonce', true, 1);
        $this->assertStringContainsString('name="_ap_http_referer"', $html);
        $this->assertStringContainsString('/ap-admin/options-general.php?x=1', $html);
        unset($_SERVER['REQUEST_URI']);
    }

    public function testNonceUrlAppendsQueryArg(): void
    {
        $base = 'https://example.com/ap-admin/login.php?action=logout';
        $url = ap_nonce_url($base, 'log-out', '_ap_nonce', 5);
        $this->assertStringContainsString('action=logout', $url);
        $this->assertStringContainsString('_ap_nonce=', $url);

        $parts = parse_url($url);
        $this->assertIsArray($parts);
        parse_str((string) ($parts['query'] ?? ''), $q);
        $this->assertArrayHasKey('_ap_nonce', $q);
        $this->assertTrue(ap_check_nonce((string) $q['_ap_nonce'], 'log-out', 5));

        $noQuery = ap_nonce_url('/path', 'a', '_ap_nonce', 1);
        $this->assertStringStartsWith('/path?', $noQuery);
    }

    public function testVerifyRequestFromPostAndWpAlias(): void
    {
        $nonce = ap_create_nonce('bulk-posts', 3);
        $this->assertTrue(ap_check_request_nonce(['_ap_nonce' => $nonce], 'bulk-posts', '_ap_nonce', 3));
        $this->assertSame(
            1,
            ap_verify_request_nonce(['_wpnonce' => $nonce], 'bulk-posts', '_ap_nonce', 3)
        );
        $this->assertFalse(ap_check_request_nonce([], 'bulk-posts', '_ap_nonce', 3));
        $this->assertFalse(ap_check_request_nonce(['_ap_nonce' => 'nope'], 'bulk-posts', '_ap_nonce', 3));
    }

    public function testTickGraceWindowReturnsTwoForPreviousTick(): void
    {
        // Build a token for the previous tick using the same hashing contract.
        $action = 'tick-test';
        $uid = 11;
        $tick = AP_Nonce::tick();
        $key = (defined('AP_NONCE_KEY') ? (string) AP_NONCE_KEY : '')
            . (defined('AP_NONCE_SALT') ? (string) AP_NONCE_SALT : '');
        $data = ($tick - 1) . '|' . $action . '|' . $uid;
        $prev = substr(hash_hmac('sha256', $data, $key), -12);

        $this->assertSame(2, ap_verify_nonce($prev, $action, $uid));
        $this->assertTrue(ap_check_nonce($prev, $action, $uid));
    }

    public function testEveryPostFormCarriesCsrfToken(): void
    {
        $missing = $this->findPostFormsWithoutCsrf();
        $this->assertSame(
            [],
            $missing,
            "POST forms missing CSRF token (_ap_nonce / ap_nonce_field / settingsFields / ap_csrf):\n"
            . implode("\n", $missing)
        );
    }

    public function testLogoutLinkIsNonceProtected(): void
    {
        $header = (string) file_get_contents($this->root . '/ap-admin/admin-header.php');
        $this->assertStringContainsString('ap_nonce_url', $header);
        $this->assertStringContainsString('log-out', $header);

        $login = (string) file_get_contents($this->root . '/ap-admin/login.php');
        $this->assertStringContainsString("ap_check_nonce(\$logoutNonce, 'log-out'", $login);
    }

    public function testProceduralHelpersExist(): void
    {
        $src = (string) file_get_contents($this->root . '/ap-includes/functions.php');
        $helpers = [
            'function ap_create_nonce',
            'function ap_verify_nonce',
            'function ap_check_nonce',
            'function ap_nonce_field',
            'function ap_nonce_url',
            'function ap_verify_request_nonce',
            'function ap_check_request_nonce',
        ];
        foreach ($helpers as $fn) {
            $this->assertStringContainsString($fn, $src, "Missing {$fn}");
        }
    }

    public function testInstallerUsesSessionCsrf(): void
    {
        $src = (string) file_get_contents($this->root . '/install/index.php');
        $this->assertStringContainsString('function ap_install_csrf_token', $src);
        $this->assertStringContainsString('function ap_install_csrf_ok', $src);
        $this->assertStringContainsString('name="ap_csrf"', $src);
        $this->assertSame(4, substr_count($src, 'name="ap_csrf"'));
    }

    /**
     * Scan product PHP for method=post forms without a nearby CSRF token.
     *
     * @return list<string>
     */
    private function findPostFormsWithoutCsrf(): array
    {
        $dirs = [
            $this->root . '/ap-admin',
            $this->root . '/ap-includes',
            $this->root . '/ap-content',
            $this->root . '/install',
        ];
        $missing = [];
        $formRe = '/<form\b[^>]*\bmethod\s*=\s*["\']post["\'][^>]*>/i';
        $csrfRe = '/_ap_nonce|ap_nonce_field|settingsFields|ap_csrf|ap_install_csrf/';

        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $file) {
                /** @var \SplFileInfo $file */
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $path = $file->getPathname();
                if (str_contains($path, '/vendor/') || str_contains($path, '/tests/')) {
                    continue;
                }
                $src = (string) file_get_contents($path);
                if ($src === '') {
                    continue;
                }
                if (!preg_match_all($formRe, $src, $matches, PREG_OFFSET_CAPTURE)) {
                    continue;
                }
                foreach ($matches[0] as $match) {
                    $offset = (int) $match[1];
                    $window = substr($src, $offset, 1500);
                    if (!preg_match($csrfRe, $window)) {
                        $line = substr_count(substr($src, 0, $offset), "\n") + 1;
                        $rel = str_replace($this->root . '/', '', $path);
                        $missing[] = "{$rel}:{$line}";
                    }
                }
            }
        }

        return $missing;
    }
}
