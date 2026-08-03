<?php

/**
 * Unit tests + static audit for escaping / sanitization helpers.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Security;

use AP_Formatting;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_Formatting::class)]
final class FormattingTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        require_once $this->root . '/ap-includes/class-ap-formatting.php';
        require_once $this->root . '/ap-includes/functions.php';
    }

    public function testEscHtmlAndAttr(): void
    {
        $this->assertSame('a &amp; b', ap_esc_html('a & b'));
        $this->assertSame('&lt;script&gt;', ap_esc_html('<script>'));
        $this->assertSame('&quot;x&quot;', ap_esc_attr('"x"'));
        $this->assertSame('&#039;y&#039;', ap_esc_attr("'y'"));
        $this->assertSame(ap_esc_html("line\nbreak"), ap_esc_textarea("line\nbreak"));
    }

    public function testEscUrlAllowsSafeAndRelative(): void
    {
        $this->assertSame(
            'https://example.com/path?a=1&amp;b=2',
            ap_esc_url('https://example.com/path?a=1&b=2')
        );
        $this->assertSame('/ap-admin/index.php', ap_esc_url('/ap-admin/index.php'));
        $this->assertSame('#section', ap_esc_url('#section'));
        $this->assertSame('?page=1', ap_esc_url('?page=1'));
        $this->assertSame('//cdn.example.com/x.js', ap_esc_url('//cdn.example.com/x.js'));
        $this->assertSame('mailto:user@example.com', ap_esc_url('mailto:user@example.com'));

        $raw = ap_esc_url_raw('https://example.com/path?a=1&b=2');
        $this->assertSame('https://example.com/path?a=1&b=2', $raw);
        $this->assertStringNotContainsString('&amp;', $raw);
    }

    /**
     * @return list<array{0: string}>
     */
    public static function dangerousUrlProvider(): array
    {
        return [
            ['javascript:alert(1)'],
            ['JAVASCRIPT:alert(1)'],
            ['javascript:alert(1)'],
            ["java\tscript:alert(1)"],
            ['vbscript:msgbox(1)'],
            ['data:text/html,<script>alert(1)</script>'],
            ['data:image/svg+xml;base64,PHN2Zz4='],
            ['javascript&#58;alert(1)'],
        ];
    }

    #[DataProvider('dangerousUrlProvider')]
    public function testEscUrlRejectsDangerousSchemes(string $url): void
    {
        $this->assertSame('', ap_esc_url($url), "Expected empty for: {$url}");
        $this->assertSame('', ap_esc_url_raw($url), "Expected empty raw for: {$url}");
    }

    public function testEscJsAndXml(): void
    {
        $js = ap_esc_js("foo'bar\"baz</script>");
        $this->assertStringNotContainsString('</script>', $js);
        $this->assertStringContainsString('\u003C', $js);
        $this->assertStringContainsString('\u0027', $js);

        $xml = ap_esc_xml('a < b & "c"');
        $this->assertStringContainsString('&lt;', $xml);
        $this->assertStringContainsString('&amp;', $xml);
    }

    public function testSanitizeTextFields(): void
    {
        $this->assertSame('Hello x', ap_sanitize_text_field("  Hello \n <b>x</b> "));
        $this->assertSame("Line1\nLine2", ap_sanitize_textarea_field("Line1\n<b>Line2</b>"));
        $this->assertStringNotContainsString('<script>', ap_strip_all_tags('<script>alert(1)</script>hi'));
        $this->assertStringContainsString('hi', ap_strip_all_tags('<script>alert(1)</script>hi'));
        $this->assertSame('a b', ap_strip_all_tags("a\n\nb", true));
    }

    public function testSanitizeEmailKeyFileColorUserAbsint(): void
    {
        $this->assertSame('user@example.com', ap_sanitize_email('User@Example.COM'));
        $this->assertSame('', ap_sanitize_email('not-an-email'));
        $this->assertSame('', ap_sanitize_email('a@b'));

        $this->assertSame('my_option-key', ap_sanitize_key('My_Option-Key!!'));
        $this->assertSame('photo-name.jpg', ap_sanitize_file_name('../../photo name.jpg'));
        $this->assertSame('file', ap_sanitize_file_name('..'));

        $this->assertSame('#abc', ap_sanitize_hex_color('abc'));
        $this->assertSame('#aabbcc', ap_sanitize_hex_color('#AAbbCC'));
        $this->assertSame('', ap_sanitize_hex_color('#gg0000'));
        $this->assertSame('', ap_sanitize_hex_color('red'));

        $this->assertSame('alice', ap_sanitize_user('Alice', true));
        $this->assertSame('bob_smith', ap_sanitize_user("Bob_Smith\n", true));

        $this->assertSame(0, ap_absint(-5));
        $this->assertSame(42, ap_absint('42'));
        $this->assertSame(0, ap_absint('nope'));
        $this->assertSame(3, ap_absint(3.9));
    }

    public function testSanitizeHtmlClassViaFormatting(): void
    {
        // Spaces and punctuation are stripped (legacy ap_sanitize_html_class contract).
        $this->assertSame('foobar', AP_Formatting::sanitizeHtmlClass('Foo Bar!'));
        $this->assertSame('ok_class', AP_Formatting::sanitizeHtmlClass('ok_class'));
        $this->assertSame('single-post', AP_Formatting::sanitizeHtmlClass('single-post'));
    }

    public function testAllowedProtocols(): void
    {
        $protocols = ap_allowed_protocols();
        $this->assertContains('http', $protocols);
        $this->assertContains('https', $protocols);
        $this->assertContains('mailto', $protocols);
        $this->assertNotContains('javascript', $protocols);
        $this->assertNotContains('data', $protocols);
    }

    public function testProceduralHelpersExist(): void
    {
        $src = (string) file_get_contents($this->root . '/ap-includes/functions.php');
        $helpers = [
            'function ap_esc_html',
            'function ap_esc_attr',
            'function ap_esc_url',
            'function ap_esc_url_raw',
            'function ap_esc_textarea',
            'function ap_esc_js',
            'function ap_esc_xml',
            'function ap_sanitize_text_field',
            'function ap_sanitize_textarea_field',
            'function ap_sanitize_email',
            'function ap_sanitize_key',
            'function ap_sanitize_file_name',
            'function ap_sanitize_hex_color',
            'function ap_sanitize_user',
            'function ap_absint',
            'function ap_strip_all_tags',
            'function ap_allowed_protocols',
        ];
        foreach ($helpers as $fn) {
            $this->assertStringContainsString($fn, $src, "Missing {$fn}");
        }

        $class = (string) file_get_contents($this->root . '/ap-includes/class-ap-formatting.php');
        $this->assertStringContainsString('class AP_Formatting', $class);
    }

    public function testBootstrapLoadsFormattingClass(): void
    {
        $boot = (string) file_get_contents($this->root . '/ap-includes/bootstrap.php');
        $this->assertStringContainsString('class-ap-formatting.php', $boot);
    }

    public function testNoDirectSuperglobalEchoInProductCode(): void
    {
        $hits = $this->findDirectSuperglobalEchoes();
        $this->assertSame(
            [],
            $hits,
            "Direct echo of superglobals (XSS risk):\n" . implode("\n", $hits)
        );
    }

    public function testAdminTemplatesUseEscapingHelpers(): void
    {
        // Soft audit: majority of admin PHP files that echo output also call ap_esc_*.
        $admin = $this->root . '/ap-admin';
        $adminPhp = 0;
        $withEsc = 0;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($admin, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $src = (string) file_get_contents($file->getPathname());
            if (str_contains($src, 'echo')) {
                $adminPhp++;
                if (str_contains($src, 'ap_esc_')) {
                    $withEsc++;
                }
            }
        }
        $this->assertGreaterThan(10, $adminPhp, 'Expected admin PHP files with echo');
        $this->assertGreaterThan(
            (int) floor($adminPhp * 0.6),
            $withEsc,
            "Expected most admin files with echo to use ap_esc_* helpers ({$withEsc}/{$adminPhp})"
        );
    }

    public function testCompatShimsDelegateToApHelpers(): void
    {
        $src = (string) file_get_contents(
            $this->root . '/ap-includes/compatibility/functions-shim.php'
        );
        foreach (['esc_js', 'esc_xml', 'esc_url_raw', 'sanitize_email', 'sanitize_key', 'absint'] as $fn) {
            $this->assertStringContainsString("function {$fn}", $src);
        }
    }

    /**
     * @return list<string>
     */
    private function findDirectSuperglobalEchoes(): array
    {
        $dirs = [
            $this->root . '/ap-admin',
            $this->root . '/ap-includes',
            $this->root . '/ap-content/themes',
            $this->root . '/install',
        ];
        $hits = [];
        // echo $_GET['x'], print $_POST[...] , etc.
        $re = '/\b(?:echo|print)\s*\(?\s*\$_(GET|POST|REQUEST|COOKIE|SERVER)\b/';

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
                if (str_contains($path, '/vendor/')) {
                    continue;
                }
                $src = (string) file_get_contents($path);
                if ($src === '') {
                    continue;
                }
                if (!preg_match_all($re, $src, $matches, PREG_OFFSET_CAPTURE)) {
                    continue;
                }
                foreach ($matches[0] as $match) {
                    $offset = (int) $match[1];
                    $line = substr_count(substr($src, 0, $offset), "\n") + 1;
                    $rel = str_replace($this->root . '/', '', $path);
                    $hits[] = "{$rel}:{$line}";
                }
            }
        }

        return $hits;
    }
}
