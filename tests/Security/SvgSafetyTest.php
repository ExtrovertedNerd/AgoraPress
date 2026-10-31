<?php

/**
 * SVG upload scan: entity-encoded script URLs and response headers.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Security;

use AP_Media;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_Media::class)]
final class SvgSafetyTest extends TestCase
{
    private string $root;

    private string $dir = '';

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        require_once $this->root . '/ap-includes/class-ap-media.php';
        $this->dir = sys_get_temp_dir() . '/ap-svg-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        if ($this->dir !== '' && is_dir($this->dir)) {
            foreach (scandir($this->dir) ?: [] as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }
                $path = $this->dir . '/' . $name;
                if (is_file($path)) {
                    @unlink($path);
                }
            }
            @rmdir($this->dir);
        }
    }

    public function testEntityEncodedPayloadsAreRejected(): void
    {
        $cases = [
            'decimal href' => '<svg xmlns="http://www.w3.org/2000/svg">'
                . '<a href="&#106;avascript&#58;alert(1)"><text>x</text></a></svg>',
            'animate values' => '<svg xmlns="http://www.w3.org/2000/svg">'
                . '<animate attributeName="href" values="&#106;avascript&#58;alert(1)"/></svg>',
            'hex href' => '<svg xmlns="http://www.w3.org/2000/svg">'
                . '<a href="&#x6A;avascript&#x3A;alert(1)"><text>x</text></a></svg>',
            'tab and newline' => '<svg xmlns="http://www.w3.org/2000/svg">'
                . '<a href="java&#9;script&#10;:alert(1)"><text>x</text></a></svg>',
            'xlink href' => '<svg xmlns="http://www.w3.org/2000/svg"'
                . ' xmlns:xlink="http://www.w3.org/1999/xlink">'
                . '<a xlink:href="&#106;avascript&#58;alert(1)"><text>x</text></a></svg>',
            'set href' => '<svg xmlns="http://www.w3.org/2000/svg">'
                . '<set attributeName="href" to="&#106;avascript&#58;alert(1)"/></svg>',
            'animate safe fragment still targets href' => '<svg xmlns="http://www.w3.org/2000/svg">'
                . '<animate attributeName="href" values="#icon"/></svg>',
            'data url' => '<svg xmlns="http://www.w3.org/2000/svg">'
                . '<image href="data:image/svg+xml;base64,PHN2Zy8+"/></svg>',
            'foreignObject' => '<svg xmlns="http://www.w3.org/2000/svg">'
                . '<foreignObject><body xmlns="http://www.w3.org/1999/xhtml">x</body></foreignObject></svg>',
        ];

        foreach ($cases as $label => $svg) {
            $path = $this->write($label . '.svg', $svg);
            $this->assertNotSame('', AP_Media::scanSvgSafety($path), $label);
            $check = AP_Media::checkFileType('icon.svg', $path);
            $this->assertFalse($check['ok'], $label . ' ' . $check['error']);
        }
    }

    public function testSafeSvgAndHttpsLinkPass(): void
    {
        $plain = '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10">'
            . '<rect width="10" height="10" fill="#000"/></svg>';
        $this->assertSame('', AP_Media::scanSvgSafety($this->write('plain.svg', $plain)));

        $link = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10">'
            . '<a href="https://example.com/icon"><path d="M0 0h10v10H0z"/></a></svg>';
        $this->assertSame('', AP_Media::scanSvgSafety($this->write('link.svg', $link)));

        $use = '<svg xmlns="http://www.w3.org/2000/svg"'
            . ' xmlns:xlink="http://www.w3.org/1999/xlink">'
            . '<use xlink:href="#glyph"/></svg>';
        $this->assertSame('', AP_Media::scanSvgSafety($this->write('use.svg', $use)));
    }

    public function testUploadConfigsLockDownSvg(): void
    {
        $template = AP_Media::uploadsHtaccessContents();
        $this->assertStringContainsString("script-src 'none'", $template);
        $this->assertStringContainsString('sandbox', $template);
        $this->assertStringContainsString('X-Content-Type-Options', $template);
        $this->assertStringContainsString('nosniff', $template);

        file_put_contents(
            $this->dir . '/.htaccess',
            "Require all granted\n<FilesMatch \"php\">\nRequire all denied\n</FilesMatch>\n"
        );
        AP_Media::ensureProtectionFiles($this->dir);
        $written = (string) file_get_contents($this->dir . '/.htaccess');
        $this->assertStringContainsString("script-src 'none'", $written);
        $this->assertStringContainsString('nosniff', $written);

        $root = (string) file_get_contents($this->root . '/.htaccess');
        $this->assertStringContainsString("script-src 'none'", $root);
        $this->assertStringContainsString('ap-content/uploads', $root);
        $this->assertStringContainsString('nosniff', $root);

        $nginx = (string) file_get_contents($this->root . '/docker/nginx.conf.example');
        $this->assertStringContainsString('ap-content/uploads', $nginx);
        $this->assertStringContainsString("script-src 'none'", $nginx);
        $this->assertStringContainsString('nosniff', $nginx);
    }

    private function write(string $name, string $svg): string
    {
        $safe = preg_replace('/[^a-z0-9._-]+/i', '-', $name) ?? 'icon.svg';
        $path = $this->dir . '/' . $safe;
        file_put_contents($path, $svg);

        return $path;
    }
}
