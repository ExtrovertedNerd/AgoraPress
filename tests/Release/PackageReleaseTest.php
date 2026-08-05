<?php

/**
 * Release packaging script contract tests (Phase 7).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Release;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;

#[CoversNothing]
final class PackageReleaseTest extends TestCase
{
    private string $root;

    private string $script;

    private string $workDir;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        $this->script = $this->root . '/bin/package-release.php';
        $this->assertFileIsReadable($this->script, 'bin/package-release.php must exist');

        $this->workDir = sys_get_temp_dir() . '/ap-package-test-' . bin2hex(random_bytes(6));
        $this->assertTrue(mkdir($this->workDir, 0700, true));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->workDir)) {
            $this->rmTree($this->workDir);
        }
    }

    public function testScriptIsCliRunnableAndHelpWorks(): void
    {
        [$exit, $stdout, $stderr] = $this->runPackage(['--help']);
        $this->assertSame(0, $exit, $stderr . $stdout);
        $this->assertStringContainsString('package-release.php', $stdout . $stderr);
        $this->assertStringContainsString('--output-dir', $stdout . $stderr);
        $this->assertStringContainsString('--dry-run', $stdout . $stderr);
    }

    public function testDryRunJsonReportsVersionAndFileCount(): void
    {
        [$exit, $stdout, $stderr] = $this->runPackage(['--dry-run', '--json']);
        $this->assertSame(0, $exit, $stderr . $stdout);

        $data = json_decode($stdout, true);
        $this->assertIsArray($data);
        $this->assertTrue($data['ok'] ?? false);
        $this->assertTrue($data['dry_run'] ?? false);
        $this->assertNotSame('', (string) ($data['version'] ?? ''));
        $this->assertGreaterThan(50, (int) ($data['file_count'] ?? 0));
        $this->assertSame('AgoraPress', $data['prefix'] ?? null);

        $versionPhp = (string) file_get_contents($this->root . '/ap-includes/version.php');
        $this->assertMatchesRegularExpression(
            "/define\\s*\\(\\s*['\"]AP_VERSION['\"]\\s*,\\s*['\"]"
            . preg_quote((string) $data['version'], '/')
            . "['\"]\\s*\\)/",
            $versionPhp,
            'Dry-run version should match AP_VERSION'
        );
    }

    public function testBuildsZipSha256AndVersionJsonExample(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $out = $this->workDir . '/dist';
        [$exit, $stdout, $stderr] = $this->runPackage([
            '--output-dir=' . $out,
            '--version=9.9.9-test',
            '--json',
        ]);
        $this->assertSame(0, $exit, $stderr . $stdout);

        $data = json_decode($stdout, true);
        $this->assertIsArray($data);
        $this->assertTrue($data['ok'] ?? false);
        $this->assertSame('9.9.9-test', $data['version'] ?? null);
        $this->assertNotSame('', (string) ($data['sha256'] ?? ''));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $data['sha256']);
        $this->assertGreaterThan(0, (int) ($data['bytes'] ?? 0));

        $zipPath = $out . '/AgoraPress-9.9.9-test.zip';
        $shaPath = $out . '/AgoraPress-9.9.9-test.sha256';
        $examplePath = $out . '/version.json.example';

        $this->assertFileExists($zipPath);
        $this->assertFileExists($shaPath);
        $this->assertFileExists($examplePath);

        $expectedSha = hash_file('sha256', $zipPath);
        $this->assertSame($expectedSha, $data['sha256']);
        $shaBody = (string) file_get_contents($shaPath);
        $this->assertStringContainsString((string) $expectedSha, $shaBody);
        $this->assertStringContainsString('AgoraPress-9.9.9-test.zip', $shaBody);

        $example = json_decode((string) file_get_contents($examplePath), true);
        $this->assertIsArray($example);
        $this->assertSame('9.9.9-test', $example['version'] ?? null);
        $this->assertSame($expectedSha, $example['sha256'] ?? null);
        $this->assertArrayHasKey('download_url', $example);
        $this->assertArrayHasKey('changelog_url', $example);

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipPath) === true);
        $this->assertGreaterThan(50, $zip->numFiles);

        // Core root markers under AgoraPress/ prefix (AP_Core_Updater).
        $this->assertNotFalse($zip->locateName('AgoraPress/index.php'));
        $this->assertNotFalse($zip->locateName('AgoraPress/ap-includes/version.php'));
        $this->assertNotFalse($zip->locateName('AgoraPress/ap-admin/index.php'));
        $this->assertNotFalse($zip->locateName('AgoraPress/ap-config-sample.php'));
        $this->assertNotFalse($zip->locateName('AgoraPress/CHANGELOG.md'));
        $this->assertNotFalse($zip->locateName('AgoraPress/LICENSE'));
        $this->assertNotFalse($zip->locateName('AgoraPress/install/index.php'));
        $this->assertNotFalse($zip->locateName('AgoraPress/ap-cli'));
        $this->assertNotFalse($zip->locateName('AgoraPress/ap-content/themes/agora/style.css'));

        // Dev / process / secrets must not ship.
        $this->assertFalse($zip->locateName('AgoraPress/tests/bootstrap.php'));
        $this->assertFalse($zip->locateName('AgoraPress/vendor/autoload.php'));
        $this->assertFalse($zip->locateName('AgoraPress/bin/package-release.php'));
        $this->assertFalse($zip->locateName('AgoraPress/phpunit.xml.dist'));
        $this->assertFalse($zip->locateName('AgoraPress/phpcs.xml.dist'));
        $this->assertFalse($zip->locateName('AgoraPress/phpstan.neon.dist'));
        $this->assertFalse($zip->locateName('AgoraPress/composer.lock'));
        $this->assertFalse($zip->locateName('AgoraPress/ap-config.php'));
        $this->assertFalse($zip->locateName('AgoraPress/.hephaestus/TODO.md'));
        $this->assertFalse($zip->locateName('AgoraPress/.github/workflows/ci.yml'));

        // No accidental nested dist.
        $this->assertFalse($zip->locateName('AgoraPress/dist/AgoraPress-9.9.9-test.zip'));

        // Every entry must live under the single prefix (no zip-slip / dual roots).
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            $this->assertTrue(
                str_starts_with($name, 'AgoraPress/'),
                "Unexpected zip entry outside prefix: {$name}"
            );
            $this->assertStringNotContainsString('..', $name);
        }

        $zip->close();
    }

    public function testPackageIsRecognizedByCoreUpdaterDetectPackageRoot(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        // Load only the updater class (no full site bootstrap).
        require_once $this->root . '/ap-includes/class-ap-core-updater.php';

        $out = $this->workDir . '/updater-dist';
        [$exit, $stdout, $stderr] = $this->runPackage([
            '--output-dir=' . $out,
            '--version=1.2.3-pkg',
        ]);
        $this->assertSame(0, $exit, $stderr . $stdout);

        $zipPath = $out . '/AgoraPress-1.2.3-pkg.zip';
        $this->assertFileExists($zipPath);

        $extractDir = $this->workDir . '/extract';
        $this->assertTrue(mkdir($extractDir, 0700, true));
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipPath) === true);
        $this->assertTrue($zip->extractTo($extractDir));
        $zip->close();

        $detected = \AP_Core_Updater::detectPackageRoot($extractDir);
        $this->assertTrue($detected['ok'], $detected['error'] ?? 'detect failed');
        $this->assertNotSame('', $detected['package_root']);
        $this->assertFileExists($detected['package_root'] . '/ap-includes/version.php');
        $this->assertFileExists($detected['package_root'] . '/index.php');
        $this->assertDirectoryExists($detected['package_root'] . '/ap-admin');
    }

    public function testRejectsInvalidVersionLabel(): void
    {
        [$exit, $stdout, $stderr] = $this->runPackage([
            '--version=../evil',
            '--dry-run',
        ]);
        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('Invalid', $stderr . $stdout);
    }

    public function testChangelogMentionsReleasePackaging(): void
    {
        $changelog = (string) file_get_contents($this->root . '/CHANGELOG.md');
        $this->assertStringContainsString('package-release.php', $changelog);
        $this->assertStringContainsString('version.json.example', $changelog);
        $this->assertStringContainsString('Release packaging', $changelog);
    }

    public function testReadmeDocumentsReleasePackaging(): void
    {
        $readme = (string) file_get_contents($this->root . '/README.md');
        $this->assertMatchesRegularExpression('/(?im)^##\s+Release packaging\s*$/', $readme);
        $this->assertStringContainsString('bin/package-release.php', $readme);
        $this->assertStringContainsString('composer package', $readme);
    }

    public function testGitignoreCoversDist(): void
    {
        $gi = (string) file_get_contents($this->root . '/.gitignore');
        $this->assertTrue(
            str_contains($gi, '/dist/') || str_contains($gi, "\ndist/") || str_contains($gi, "dist/\n"),
            '.gitignore must ignore dist/ packaging artifacts'
        );
        $this->assertTrue(
            str_contains($gi, '*.sqlite') || str_contains($gi, 'database.sqlite'),
            '.gitignore must ignore SQLite runtime databases for live installs'
        );
    }

    /**
     * @param list<string> $args
     * @return array{0:int,1:string,2:string}
     */
    private function runPackage(array $args): array
    {
        $php = PHP_BINARY !== '' ? PHP_BINARY : 'php';
        $cmd = escapeshellarg($php) . ' ' . escapeshellarg($this->script);
        foreach ($args as $arg) {
            $cmd .= ' ' . escapeshellarg($arg);
        }
        $cmd .= ' 2>&1';

        $output = [];
        $exit = 0;
        exec($cmd, $output, $exit);
        $combined = implode("\n", $output);

        // Script writes help/errors to stderr; exec 2>&1 merges both into stdout capture.
        return [$exit, $combined, ''];
    }

    private function rmTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $path = $file->getPathname();
            if ($file->isDir()) {
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
