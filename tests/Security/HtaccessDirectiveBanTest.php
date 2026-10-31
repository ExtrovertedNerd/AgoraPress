<?php

/**
 * Shipped Apache config must not use an expression container.
 *
 * Some Apache 2.4 builds reject `<If>` and the `m~` delimiter form and
 * then return HTTP 500 for every URL that reads that file.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Security;

use PHPUnit\Framework\TestCase;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class HtaccessDirectiveBanTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testShippedHtaccessAndWritersBanExpressionContainers(): void
    {
        $htaccessFiles = [];
        $phpPayloads = 0;
        $moduleBodies = 0;
        $offenders = [];

        foreach ($this->projectFiles() as $path) {
            $rel = substr($path, strlen($this->root) + 1);
            if (str_ends_with($rel, '.htaccess')) {
                $htaccessFiles[] = $rel;
                $body = (string) file_get_contents($path);
                $this->collectBan($rel, $body, $offenders);
                if (str_contains($body, '<IfModule')) {
                    $moduleBodies++;
                }
                continue;
            }
            if (!str_ends_with($rel, '.php') || str_starts_with($rel, 'tests/')) {
                continue;
            }
            $source = (string) file_get_contents($path);
            if (stripos($source, 'htaccess') === false) {
                continue;
            }
            foreach ($this->stringBodies($source) as $body) {
                $phpPayloads++;
                $this->collectBan($rel, $body, $offenders);
                if (str_contains($body, '<IfModule')) {
                    $moduleBodies++;
                }
            }
        }

        $this->assertGreaterThanOrEqual(3, count($htaccessFiles), 'expected shipped .htaccess files');
        $this->assertContains('.htaccess', $htaccessFiles);
        $this->assertContains('ap-content/.htaccess', $htaccessFiles);
        $this->assertContains('ap-content/uploads/.htaccess', $htaccessFiles);
        $this->assertGreaterThanOrEqual(4, $phpPayloads, 'expected PHP strings that write .htaccess content');
        $this->assertGreaterThanOrEqual(6, $moduleBodies, 'scanner did not see the shipped IfModule rules');
        $this->assertSame([], $offenders, "Banned Apache directive:\n" . implode("\n", $offenders));

        $uploads = (string) file_get_contents($this->root . '/ap-content/uploads/.htaccess');
        $this->assertStringContainsString('<IfModule mod_headers.c>', $uploads);
        $this->assertStringContainsString('<FilesMatch "\.svg$">', $uploads);
        $this->assertStringContainsString("script-src 'none'", $uploads);
        $this->assertSame('', $this->banReason($uploads));

        $rootHt = (string) file_get_contents($this->root . '/.htaccess');
        $this->assertStringNotContainsString('Header set', $rootHt);
        $this->assertSame('', $this->banReason($rootHt));
    }

    /**
     * @param list<string> $offenders
     */
    private function collectBan(string $rel, string $body, array &$offenders): void
    {
        $reason = $this->banReason($body);
        if ($reason !== '') {
            $offenders[] = $rel . ': ' . $reason;
        }
    }

    /**
     * `<IfModule>`, `<IfDefine>`, `<IfVersion>`, and `<IfFile>` are allowed.
     * The expression container `<If` and the `m~` delimiter form are not.
     */
    private function banReason(string $body): string
    {
        if (preg_match('/<If(?!Module\b|Define\b|Version\b|File\b)/i', $body) === 1) {
            return 'Apache <If expression container';
        }
        if (preg_match('/=~\s*m~/i', $body) === 1 || preg_match('/(?<![A-Za-z0-9_])m~/i', $body) === 1) {
            return 'm~ regex delimiter';
        }

        return '';
    }

    /**
     * Quoted strings and nowdoc / heredoc bodies from one PHP file.
     *
     * @return list<string>
     */
    private function stringBodies(string $source): array
    {
        $tokens = token_get_all($source);
        $bodies = [];
        $inHeredoc = false;
        $heredoc = '';
        foreach ($tokens as $token) {
            if (!is_array($token)) {
                if ($inHeredoc) {
                    $heredoc .= $token;
                }
                continue;
            }
            [$id, $text] = $token;
            if ($id === T_START_HEREDOC) {
                $inHeredoc = true;
                $heredoc = '';
                continue;
            }
            if ($id === T_END_HEREDOC) {
                $inHeredoc = false;
                $bodies[] = $heredoc;
                $heredoc = '';
                continue;
            }
            if ($inHeredoc) {
                $heredoc .= $text;
                continue;
            }
            if ($id === T_CONSTANT_ENCAPSED_STRING) {
                $bodies[] = $this->unquotePhpString($text);
            }
        }

        return $bodies;
    }

    private function unquotePhpString(string $token): string
    {
        if (strlen($token) < 2) {
            return $token;
        }
        $quote = $token[0];
        if (($quote !== "'" && $quote !== '"') || !str_ends_with($token, $quote)) {
            return $token;
        }
        $inner = substr($token, 1, -1);
        if ($quote === "'") {
            return str_replace(["\\\\", "\\'"], ["\\", "'"], $inner);
        }

        return stripcslashes($inner);
    }

    /**
     * @return list<string>
     */
    private function projectFiles(): array
    {
        $skip = [
            'vendor',
            'dist',
            'node_modules',
            'coverage',
        ];
        $filter = new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            static function (SplFileInfo $current) use ($skip): bool {
                if (!$current->isDir()) {
                    return true;
                }
                $name = $current->getFilename();
                // Caches, VCS, and process trees are not shipped writers.
                if (str_starts_with($name, '.') || in_array($name, $skip, true)) {
                    return false;
                }

                return true;
            }
        );
        $files = [];
        $iterator = new RecursiveIteratorIterator($filter);
        foreach ($iterator as $info) {
            /** @var SplFileInfo $info */
            if (!$info->isFile()) {
                continue;
            }
            $files[] = str_replace('\\', '/', $info->getPathname());
        }
        sort($files);

        return $files;
    }
}
