<?php

/**
 * Live-site install readiness: web hardening, packaging exclusions, docs.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Install;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class LiveSiteReadinessTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testRootHtaccessDeniesSecretsAndSqlite(): void
    {
        $ht = (string) file_get_contents($this->root . '/.htaccess');
        $this->assertStringContainsString('ap-config\.php', $ht);
        $this->assertStringContainsString('sqlite', $ht);
        $this->assertStringContainsString('RewriteEngine On', $ht);
        $this->assertStringContainsString('index.php', $ht);
        // Core libraries not directly web-served (CSS/JS allow-list).
        $this->assertStringContainsString('ap-includes', $ht);
        $this->assertMatchesRegularExpression('/ap-includes\/\(css\|js\)/', $ht);
        $this->assertStringContainsString('[F,L]', $ht);
    }

    public function testContentHtaccessProtectsSqlite(): void
    {
        $path = $this->root . '/ap-content/.htaccess';
        $this->assertFileIsReadable($path);
        $ht = (string) file_get_contents($path);
        $this->assertStringContainsString('sqlite', $ht);
        $this->assertStringContainsString('Options -Indexes', $ht);
    }

    public function testNginxExampleHardensProduction(): void
    {
        $nginx = (string) file_get_contents($this->root . '/docker/nginx.conf.example');
        $this->assertStringContainsString('try_files', $nginx);
        $this->assertStringContainsString('ap-config', $nginx);
        $this->assertStringContainsString('sqlite', $nginx);
        $this->assertStringContainsString('ap-includes', $nginx);
        $this->assertStringContainsString('deny all', $nginx);
    }

    public function testGitignoreCoversSqliteRuntime(): void
    {
        $gi = (string) file_get_contents($this->root . '/.gitignore');
        $this->assertTrue(
            str_contains($gi, '*.sqlite') || str_contains($gi, 'database.sqlite'),
            '.gitignore must ignore SQLite runtime databases'
        );
        $this->assertStringContainsString('ap-config.php', $gi);
        $this->assertStringContainsString('/dist/', $gi);
    }

    public function testPackageReleaseExcludesSqlite(): void
    {
        $script = (string) file_get_contents($this->root . '/bin/package-release.php');
        $this->assertMatchesRegularExpression('/sqlite3\?|\.sqlite/', $script);
        $this->assertStringContainsString('ap-config.php', $script);
    }

    public function testReadmeHasProductionInstallSection(): void
    {
        $readme = (string) file_get_contents($this->root . '/README.md');
        $this->assertMatchesRegularExpression(
            '/(?im)^###\s+Production install \(live site\)\s*$/',
            $readme
        );
        $this->assertStringContainsString('ready for live-site install', $readme);
        $this->assertStringContainsString('Site Health', $readme);
        $this->assertStringContainsString('AP_DEBUG', $readme);
        $this->assertStringContainsString('MySQL', $readme);
    }

    public function testChangelogMentionsLiveSiteReadiness(): void
    {
        $changelog = (string) file_get_contents($this->root . '/CHANGELOG.md');
        $this->assertStringContainsString('Live-site install readiness', $changelog);
    }

    public function testPackageZipExcludesSqliteWhenPresent(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $script = $this->root . '/bin/package-release.php';
        $this->assertFileIsReadable($script);

        // Ensure a stray SQLite file would be excluded if present at package time.
        $sqlitePath = $this->root . '/ap-content/database.sqlite';
        $created = false;
        if (!is_file($sqlitePath)) {
            $this->assertNotFalse(@file_put_contents($sqlitePath, "SQLite format 3\0"));
            $created = true;
        }

        $out = sys_get_temp_dir() . '/ap-live-pkg-' . uniqid('', true);
        $this->assertTrue(mkdir($out, 0700, true));

        try {
            $php = PHP_BINARY !== '' ? PHP_BINARY : 'php';
            $cmd = escapeshellarg($php) . ' ' . escapeshellarg($script)
                . ' --output-dir=' . escapeshellarg($out)
                . ' --version=0.0.0-live-ready'
                . ' --json 2>&1';
            $output = [];
            $exit = 0;
            exec($cmd, $output, $exit);
            $combined = implode("\n", $output);
            $this->assertSame(0, $exit, $combined);

            $zipPath = $out . '/AgoraPress-0.0.0-live-ready.zip';
            $this->assertFileExists($zipPath);

            $zip = new \ZipArchive();
            $this->assertTrue($zip->open($zipPath) === true);
            $this->assertNotFalse($zip->locateName('AgoraPress/index.php'));
            $this->assertNotFalse($zip->locateName('AgoraPress/.htaccess'));
            $this->assertNotFalse($zip->locateName('AgoraPress/ap-content/.htaccess'));
            $this->assertNotFalse($zip->locateName('AgoraPress/install/index.php'));
            $this->assertFalse(
                $zip->locateName('AgoraPress/ap-content/database.sqlite'),
                'Release zip must not ship runtime SQLite databases'
            );
            $this->assertFalse($zip->locateName('AgoraPress/ap-config.php'));
            $zip->close();
        } finally {
            if ($created && is_file($sqlitePath)) {
                @unlink($sqlitePath);
            }
            foreach (glob($out . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($out);
        }
    }

    public function testCliInstallerStillSucceedsForFreshSqlite(): void
    {
        $cli = $this->root . '/install/cli.php';
        $this->assertFileIsReadable($cli);

        $tmp = sys_get_temp_dir() . '/ap-live-cli-' . uniqid('', true);
        $this->assertTrue(mkdir($tmp, 0700, true));
        $config = $tmp . '/ap-config.php';
        $db = $tmp . '/site.sqlite';

        $php = PHP_BINARY !== '' ? PHP_BINARY : 'php';
        $cmd = escapeshellarg($php) . ' ' . escapeshellarg($cli)
            . ' --db-driver=sqlite'
            . ' --db-name=' . escapeshellarg($db)
            . ' --site-title=' . escapeshellarg('Live Ready')
            . ' --site-url=' . escapeshellarg('https://example.com')
            . ' --admin-user=admin'
            . ' --admin-email=admin@example.com'
            . ' --admin-password=changeme123'
            . ' --config-path=' . escapeshellarg($config)
            . ' --no-sample-content 2>&1';

        $output = [];
        $exit = 0;
        exec($cmd, $output, $exit);
        $combined = implode("\n", $output);

        try {
            $this->assertSame(0, $exit, $combined);
            $this->assertFileExists($config);
            $this->assertFileExists($db);
            $this->assertStringContainsString('Installation complete', $combined);
            $cfg = (string) file_get_contents($config);
            $this->assertStringContainsString("define('AP_DEBUG', false)", $cfg);
            $this->assertStringContainsString("define('AP_DB_DRIVER', 'sqlite')", $cfg);
        } finally {
            @unlink($config);
            @unlink($db);
            @rmdir($tmp);
        }
    }
}
