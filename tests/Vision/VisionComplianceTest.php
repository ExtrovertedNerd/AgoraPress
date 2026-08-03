<?php

/**
 * Full codebase reevaluation guards against VISION / FEATURES principles.
 *
 * Locks free-forever, no telemetry by default, lightweight defaults,
 * three module toggles, Classic WP Theme Compatibility Layer presence,
 * and default Agora theme constraints (image-free, six schemes).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Vision;

use AP_DB;
use AP_Hall_Of_Fame;
use AP_Migrator;
use AP_Options;
use AP_Theme_Compat;
use AP_Version_Check;
use PDO;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class VisionComplianceTest extends TestCase
{
    private string $root;

    private AP_DB $db;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        require_once $this->root . '/ap-includes/version.php';
        require_once $this->root . '/ap-includes/class-ap-db.php';
        require_once $this->root . '/ap-includes/class-ap-migrator.php';
        require_once $this->root . '/ap-includes/class-ap-options.php';
        require_once $this->root . '/ap-includes/class-ap-version-check.php';
        require_once $this->root . '/ap-includes/class-ap-hall-of-fame.php';
        require_once $this->root . '/ap-includes/compatibility/load.php';
        require_once $this->root . '/ap-includes/functions.php';

        AP_Options::flushCache();

        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');
        (new AP_Migrator($this->db, AP_Migrator::defaultMigrationsPath()))->migrate();
    }

    protected function tearDown(): void
    {
        AP_Options::flushCache();
        if (class_exists(AP_Theme_Compat::class, false)) {
            AP_Theme_Compat::reset();
        }
    }

    public function testLicenseIsGplv2OrLater(): void
    {
        $license = (string) file_get_contents($this->root . '/LICENSE');
        $this->assertStringContainsString('GNU GENERAL PUBLIC LICENSE', $license);
        $this->assertStringContainsString('Version 2', $license);

        $composer = (string) file_get_contents($this->root . '/composer.json');
        $decoded = json_decode($composer, true);
        $this->assertIsArray($decoded);
        $this->assertSame('GPL-2.0-or-later', $decoded['license'] ?? null);
    }

    public function testComposerHasNoRuntimePhpDependencies(): void
    {
        $decoded = json_decode((string) file_get_contents($this->root . '/composer.json'), true);
        $this->assertIsArray($decoded);
        $require = $decoded['require'] ?? [];
        $this->assertIsArray($require);
        foreach (array_keys($require) as $name) {
            $name = (string) $name;
            $this->assertTrue(
                $name === 'php' || str_starts_with($name, 'ext-'),
                "Runtime require must stay extension-only (lightweight); found: {$name}"
            );
        }
    }

    public function testTelemetryConstantDoesNotExist(): void
    {
        // Constitution: there is no AP_TELEMETRY constant, flag, or option.
        $paths = [
            $this->root . '/ap-config-sample.php',
            $this->root . '/ap-includes/load-config.php',
            $this->root . '/ap-includes/class-ap-installer.php',
            $this->root . '/ap-includes/class-ap-site-health.php',
        ];
        foreach ($paths as $path) {
            $src = (string) file_get_contents($path);
            $this->assertStringNotContainsString(
                'AP_TELEMETRY',
                $src,
                basename($path) . ' must not define or reference AP_TELEMETRY'
            );
        }
        $this->assertFalse(defined('AP_TELEMETRY'), 'AP_TELEMETRY must not be defined at runtime');
    }

    public function testVersionCheckNeverSendsSiteIdentity(): void
    {
        $this->assertFalse(AP_Version_Check::sendsSiteIdentity());

        $src = (string) file_get_contents($this->root . '/ap-includes/class-ap-version-check.php');
        $this->assertStringContainsString('no-site-id', $src);
        $this->assertStringContainsString('CURLOPT_HTTPGET', $src);
        // Must not build query strings from site options.
        $this->assertDoesNotMatchRegularExpression(
            '/http_build_query\s*\(/',
            $src,
            'Version check must not append query payloads'
        );
    }

    public function testHallOfFameIsNotTelemetryAndPayloadIsDomainOnly(): void
    {
        $this->assertFalse(AP_Hall_Of_Fame::isTelemetry());

        $payload = AP_Hall_Of_Fame::buildPayload('join', 'example.com');
        $this->assertSame(['action' => 'join', 'domain' => 'example.com'], $payload);
        $this->assertArrayNotHasKey('email', $payload);
        $this->assertArrayNotHasKey('user_id', $payload);
        $this->assertArrayNotHasKey('version', $payload);
        $this->assertArrayNotHasKey('siteurl', $payload);
    }

    public function testNoJqueryInProductSources(): void
    {
        $roots = [
            $this->root . '/ap-includes',
            $this->root . '/ap-admin',
            $this->root . '/ap-content/themes/agora',
            $this->root . '/install',
        ];
        $hits = [];
        foreach ($roots as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                /** @var \SplFileInfo $file */
                if (!$file->isFile()) {
                    continue;
                }
                $ext = strtolower($file->getExtension());
                if (!in_array($ext, ['php', 'js', 'css', 'html'], true)) {
                    continue;
                }
                $path = $file->getPathname();
                // Allow documentation strings that say "no jQuery".
                $contents = (string) file_get_contents($path);
                if (preg_match('/\bjquery\b/i', $contents) !== 1) {
                    continue;
                }
                // Coding standards / comments forbidding jQuery are fine.
                if (preg_match('/no\s+jquery|without\s+jquery|not\s+use\s+jquery/i', $contents) === 1
                    && preg_match_all('/\bjquery\b/i', $contents) === 1
                ) {
                    continue;
                }
                // Only fail on actual library references.
                if (preg_match(
                    '/jquery[\.-]?[0-9]|\/jquery|jquery\.min|jquery\.js|cdn\.jquery|code\.jquery/i',
                    $contents
                ) === 1) {
                    $hits[] = substr($path, strlen($this->root) + 1);
                }
            }
        }
        $this->assertSame([], $hits, 'No jQuery library references allowed in core product paths');
    }

    public function testNoTelemetryCollectorStringsInCoreProduct(): void
    {
        $scanDirs = [
            $this->root . '/ap-includes',
            $this->root . '/ap-admin',
            $this->root . '/install',
        ];
        $forbidden = [
            'google-analytics',
            'googletagmanager',
            'gtag(',
            'plausible.io',
            'mixpanel',
            'segment.com',
            'hotjar',
            'fullstory',
            'amplitude.com',
            'sentry.io',
        ];
        $hits = [];
        foreach ($scanDirs as $dir) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                /** @var \SplFileInfo $file */
                if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                    continue;
                }
                $contents = strtolower((string) file_get_contents($file->getPathname()));
                foreach ($forbidden as $needle) {
                    if (str_contains($contents, strtolower($needle))) {
                        $hits[] = substr($file->getPathname(), strlen($this->root) + 1) . ':' . $needle;
                    }
                }
            }
        }
        $this->assertSame([], $hits, 'Third-party telemetry collectors must not appear in core');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function compatibilityFileProvider(): array
    {
        return [
            'load' => ['load.php'],
            'compat class' => ['class-ap-theme-compat.php'],
            'converter' => ['class-ap-theme-converter.php'],
            'functions shim' => ['functions-shim.php'],
            'template tags' => ['template-tags.php'],
            'cli convert' => ['cli-convert.php'],
        ];
    }

    #[DataProvider('compatibilityFileProvider')]
    public function testCompatibilityLayerFilesExist(string $name): void
    {
        $path = $this->root . '/ap-includes/compatibility/' . $name;
        $this->assertFileIsReadable($path, "Classic WP compat file missing: {$name}");
    }

    public function testCompatibilityLayerApiSurface(): void
    {
        $this->assertTrue(class_exists(AP_Theme_Compat::class, false));
        $this->assertTrue(function_exists('ap_theme_compat_available'));
        $this->assertTrue(ap_theme_compat_available());

        $src = (string) file_get_contents(
            $this->root . '/ap-includes/compatibility/class-ap-theme-compat.php'
        );
        foreach (
            [
                'function shouldEnableForTheme',
                'function isBlockTheme',
                'function isClassicTheme',
                'function mapHook',
                'function safeLoadFunctionsPhp',
                'function ensureLoaded',
                'MODE_AUTO',
                'MODE_ON',
                'MODE_OFF',
                'wp_enqueue_scripts',
                'ap_enqueue_scripts',
            ] as $needle
        ) {
            $this->assertStringContainsString($needle, $src, "Compat class missing: {$needle}");
        }

        // Default Agora never needs WP shims under auto mode.
        AP_Options::update('stylesheet', 'agora', $this->db);
        AP_Options::update('template', 'agora', $this->db);
        AP_Theme_Compat::reset();
        $this->assertFalse(AP_Theme_Compat::shouldEnableForTheme('agora', $this->db));
    }

    public function testAgoraThemeHasExactlySixSchemesAndIsImageFree(): void
    {
        $functions = (string) file_get_contents(
            $this->root . '/ap-content/themes/agora/functions.php'
        );
        $style = (string) file_get_contents(
            $this->root . '/ap-content/themes/agora/style.css'
        );

        $schemes = ['marble', 'parchment', 'cloud', 'obsidian', 'midnight', 'charcoal'];
        foreach ($schemes as $slug) {
            $this->assertStringContainsString("'{$slug}'", $functions);
            $this->assertStringContainsString("agora-scheme-{$slug}", $style);
        }
        $this->assertSame(6, count($schemes));

        // No image files under the theme tree.
        $themeRoot = $this->root . '/ap-content/themes/agora';
        $imageExt = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'ico'];
        $found = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($themeRoot, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isFile() && in_array(strtolower($file->getExtension()), $imageExt, true)) {
                $found[] = substr($file->getPathname(), strlen($this->root) + 1);
            }
        }
        $this->assertSame([], $found, 'Agora theme must remain image-free');

        $this->assertDoesNotMatchRegularExpression(
            '/url\s*\(\s*[\'"]?(?:https?:|data:image|[^)]+\.(?:png|jpe?g|gif|webp|svg))/i',
            $style,
            'Agora style.css must not reference image URLs'
        );
    }

    public function testThreeIndependentModuleToggles(): void
    {
        $this->assertSame(
            [
                'static_pages' => AP_Options::MODULE_STATIC_PAGES,
                'blog' => AP_Options::MODULE_BLOG,
                'forum' => AP_Options::MODULE_FORUM,
            ],
            AP_Options::moduleOptionMap()
        );

        // Defaults on after install seed pattern.
        $this->assertTrue(AP_Options::isModuleEnabled('static_pages', $this->db));
        $this->assertTrue(AP_Options::isModuleEnabled('blog', $this->db));
        $this->assertTrue(AP_Options::isModuleEnabled('forum', $this->db));

        // Any combination with at least one on is allowed.
        $this->assertTrue(AP_Options::updateModules([
            'static_pages' => false,
            'blog' => true,
            'forum' => false,
        ], $this->db));
        $this->assertFalse(AP_Options::isModuleEnabled('static_pages', $this->db));
        $this->assertTrue(AP_Options::isModuleEnabled('blog', $this->db));
        $this->assertFalse(AP_Options::isModuleEnabled('forum', $this->db));

        // All off is rejected (architecture invariant).
        $this->assertFalse(AP_Options::updateModules([
            'static_pages' => false,
            'blog' => false,
            'forum' => false,
        ], $this->db));
        $this->assertTrue(AP_Options::isModuleEnabled('blog', $this->db));
    }

    public function testNonGoalsNotPresentInCore(): void
    {
        // No Gutenberg / block editor package in core tree.
        $this->assertDirectoryDoesNotExist($this->root . '/ap-includes/blocks');
        $this->assertDirectoryDoesNotExist($this->root . '/ap-includes/gutenberg');
        $this->assertFileDoesNotExist($this->root . '/ap-includes/class-ap-block-editor.php');

        // No multisite bootstrap.
        $this->assertFileDoesNotExist($this->root . '/ap-includes/class-ap-multisite.php');
        $this->assertFileDoesNotExist($this->root . '/ap-includes/ms-load.php');

        $readme = strtolower((string) file_get_contents($this->root . '/README.md'));
        $this->assertStringContainsString('gutenberg', $readme);
        $this->assertStringContainsString('non-goal', $readme);
        $this->assertStringContainsString('no telemetry', $readme);
        $this->assertStringContainsString('free forever', $readme);
    }

    public function testVisionComplianceDocExists(): void
    {
        $path = $this->root . '/docs/vision-compliance.md';
        $this->assertFileIsReadable($path);
        $text = (string) file_get_contents($path);
        $this->assertGreaterThan(2000, strlen($text));
        foreach (
            [
                'Intentional deviations',
                'No telemetry',
                'Classic WordPress Theme Compatibility',
                'Free forever',
                'Three independent modules',
                'D1',
                'D3',
            ] as $needle
        ) {
            $this->assertStringContainsString(
                $needle,
                $text,
                "vision-compliance.md should mention: {$needle}"
            );
        }
    }

    public function testReadmeReflectsImplementedProductNotPhaseOneStub(): void
    {
        $readme = (string) file_get_contents($this->root . '/README.md');
        $this->assertDoesNotMatchRegularExpression(
            '/Early development \(Phase 1\)/i',
            $readme,
            'README status must not still claim Phase 1 after MVP phases complete'
        );
        $this->assertStringContainsString('Classic WordPress Theme Compatibility', $readme);
        $this->assertStringContainsStringIgnoringCase('free forever', $readme);
    }
}
