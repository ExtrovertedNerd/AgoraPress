<?php

/**
 * Assert docker-compose stack matches SPEC (PHP 8.2+, MySQL 8, required extensions).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Docker;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class DockerComposeTest extends TestCase
{
    private string $root;

    private string $compose;

    private string $dockerfile;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        $composePath = $this->root . '/docker-compose.yml';
        $dockerfilePath = $this->root . '/docker/Dockerfile';

        $this->assertFileIsReadable($composePath, 'docker-compose.yml must exist');
        $this->assertFileIsReadable($dockerfilePath, 'docker/Dockerfile must exist');
        $this->assertFileIsReadable($this->root . '/docker/apache-vhost.conf');
        $this->assertFileIsReadable($this->root . '/docker/php-agorapress.ini');

        $compose = file_get_contents($composePath);
        $dockerfile = file_get_contents($dockerfilePath);
        $this->assertNotFalse($compose);
        $this->assertNotFalse($dockerfile);
        $this->compose = $compose;
        $this->dockerfile = $dockerfile;
    }

    public function testComposeDefinesWebAndDbServices(): void
    {
        $this->assertMatchesRegularExpression('/(?m)^services:\s*$/', $this->compose);
        $this->assertMatchesRegularExpression('/(?m)^\s{2}web:\s*$/', $this->compose);
        $this->assertMatchesRegularExpression('/(?m)^\s{2}db:\s*$/', $this->compose);
    }

    public function testWebBuildsFromProjectDockerfile(): void
    {
        $this->assertStringContainsString('dockerfile: docker/Dockerfile', $this->compose);
        $this->assertStringContainsString('context: .', $this->compose);
    }

    public function testDbUsesMysql8(): void
    {
        $this->assertMatchesRegularExpression(
            '/image:\s*mysql:8(\.0)?\b/',
            $this->compose,
            'db service must use MySQL 8.x (SPEC primary DB)'
        );
    }

    public function testDbCharsetIsUtf8mb4(): void
    {
        $this->assertStringContainsString('utf8mb4', $this->compose);
        $this->assertStringContainsString('utf8mb4_unicode_ci', $this->compose);
    }

    public function testWebWaitsForHealthyDb(): void
    {
        $this->assertStringContainsString('depends_on', $this->compose);
        $this->assertStringContainsString('service_healthy', $this->compose);
    }

    public function testHttpPortPublished(): void
    {
        // Default host port 8080 → container 80 (optional AP_HTTP_PORT override).
        $this->assertMatchesRegularExpression(
            '/8080\}?:80|8080:80/',
            $this->compose
        );
    }

    public function testDefaultTablePrefixIsAp(): void
    {
        $this->assertMatchesRegularExpression(
            '/AP_TABLE_PREFIX:.*ap_/',
            $this->compose,
            'Docker stack should default table prefix to ap_'
        );
    }

    public function testDbVolumePersistsData(): void
    {
        $this->assertStringContainsString('ap_db_data', $this->compose);
        $this->assertStringContainsString('/var/lib/mysql', $this->compose);
    }

    public function testDockerfileUsesPhp83Apache(): void
    {
        $this->assertMatchesRegularExpression(
            '/FROM\s+php:8\.(3|4)/',
            $this->dockerfile,
            'Dockerfile should use php:8.3+ Apache base image'
        );
        $this->assertStringContainsStringIgnoringCase('apache', $this->dockerfile);
    }

    public function testDockerfileEnablesModRewrite(): void
    {
        $this->assertStringContainsString('a2enmod rewrite', $this->dockerfile);
    }

    /**
     * @return list<list<string>>
     */
    public static function requiredExtensionProvider(): array
    {
        return [
            ['pdo_mysql'],
            ['mbstring'],
            ['zip'],
            ['gd'],
            ['intl'],
        ];
    }

    #[DataProvider('requiredExtensionProvider')]
    public function testDockerfileInstallsRequiredExtension(string $ext): void
    {
        $this->assertStringContainsString(
            $ext,
            $this->dockerfile,
            "Dockerfile should install/configure PHP extension marker: {$ext}"
        );
    }

    public function testApacheVhostAllowsHtaccess(): void
    {
        $vhost = file_get_contents($this->root . '/docker/apache-vhost.conf');
        $this->assertNotFalse($vhost);
        $this->assertStringContainsString('AllowOverride All', $vhost);
        $this->assertStringContainsString('DocumentRoot /var/www/html', $vhost);
    }

    public function testComposeConfigValidatesWhenCliAvailable(): void
    {
        $composeBin = $this->findComposeBinary();
        if ($composeBin === null) {
            $this->markTestSkipped('docker-compose / docker compose not available');
        }

        $cmd = $composeBin . ' 2>&1';
        $output = [];
        $exit = 0;
        exec($cmd, $output, $exit);

        $joined = implode("\n", $output);
        $this->assertSame(
            0,
            $exit,
            "docker compose config failed:\n{$joined}"
        );
        $this->assertStringContainsString('web', $joined);
        $this->assertStringContainsString('db', $joined);
    }

    /**
     * Build a shell-safe "compose config" command, or null if no CLI found.
     */
    private function findComposeBinary(): ?string
    {
        $root = escapeshellarg($this->root);

        // Prefer standalone docker-compose (common on this host).
        $which = [];
        $code = 0;
        exec('command -v docker-compose 2>/dev/null', $which, $code);
        if ($code === 0 && $which !== []) {
            return 'cd ' . $root . ' && ' . escapeshellarg(trim($which[0])) . ' config';
        }

        $which = [];
        $code = 0;
        exec('command -v docker 2>/dev/null', $which, $code);
        if ($code === 0 && $which !== []) {
            $docker = escapeshellarg(trim($which[0]));
            // Probe plugin support.
            $probeOut = [];
            $probeCode = 0;
            exec($docker . ' compose version 2>/dev/null', $probeOut, $probeCode);
            if ($probeCode === 0) {
                return 'cd ' . $root . ' && ' . $docker . ' compose config';
            }
        }

        return null;
    }
}
