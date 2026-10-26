<?php

/**
 * Tests for AP_Plugin_Installer::validate() — structured result or typed error.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Plugin;

use AP_Plugin_Install_Error;
use AP_Plugin_Install_Result;
use AP_Plugin_Installer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ZipArchive;

#[CoversClass(AP_Plugin_Installer::class)]
#[CoversClass(AP_Plugin_Install_Result::class)]
#[CoversClass(AP_Plugin_Install_Error::class)]
final class PluginInstallValidateTest extends TestCase
{
    private string $root;

    private string $workDir;

    private string $pluginsRoot;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        require_once $this->root . '/ap-includes/class-ap-plugin-installer.php';
        require_once $this->root . '/ap-includes/functions.php';
        require_once $this->root . '/ap-includes/hooks.php';
        ap_reset_hooks();

        $this->workDir = sys_get_temp_dir() . '/ap-plugin-validate-' . uniqid('', true);
        $this->pluginsRoot = $this->workDir . '/plugins';
        $this->assertTrue(mkdir($this->pluginsRoot, 0700, true));
    }

    protected function tearDown(): void
    {
        ap_reset_hooks();
        $this->removeDir($this->workDir);
    }

    public function testErrorCodesMatchConstants(): void
    {
        $ref = new ReflectionClass(AP_Plugin_Install_Error::class);
        $constants = [];
        foreach ($ref->getReflectionConstants() as $constant) {
            if (!$constant->isPublic() || $constant->getName() === 'CODES') {
                continue;
            }
            $value = $constant->getValue();
            $this->assertIsString($value);
            $constants[] = $value;
        }

        $this->assertNotSame([], $constants);
        $this->assertEqualsCanonicalizing($constants, AP_Plugin_Install_Error::CODES);
        $this->assertSame(AP_Plugin_Install_Error::CODES, array_values(array_unique(AP_Plugin_Install_Error::CODES)));
        foreach (AP_Plugin_Install_Error::CODES as $code) {
            $this->assertMatchesRegularExpression('/^[a-z][a-z0-9_]*$/', $code);
        }
    }

    public function testMissingFileReturnsTypedError(): void
    {
        $error = AP_Plugin_Installer::validate($this->workDir . '/no-such.zip');

        $this->assertInstanceOf(AP_Plugin_Install_Error::class, $error);
        $this->assertSame(AP_Plugin_Install_Error::MISSING_FILE, $error->code());
        $this->assertNotSame('', $error->message());
        $this->assertFalse($error->toArray()['ok']);
        $this->assertSame($error->code(), $error->toArray()['code']);
        $this->assertSame($error->message(), $error->toArray()['message']);
    }

    public function testEmptyFileReturnsTypedError(): void
    {
        $path = $this->workDir . '/empty.zip';
        file_put_contents($path, '');

        $error = AP_Plugin_Installer::validate($path);

        $this->assertInstanceOf(AP_Plugin_Install_Error::class, $error);
        $this->assertSame(AP_Plugin_Install_Error::EMPTY, $error->code());
        $this->assertStringContainsString('empty', strtolower($error->message()));
    }

    public function testNonZipReturnsTypedError(): void
    {
        $path = $this->workDir . '/notes.zip';
        file_put_contents($path, 'this is not a zip archive');

        $error = AP_Plugin_Installer::validate($path);

        $this->assertInstanceOf(AP_Plugin_Install_Error::class, $error);
        $this->assertSame(AP_Plugin_Install_Error::NOT_ZIP, $error->code());
        $this->assertStringContainsString('signature', strtolower($error->message()));
    }

    public function testValidateSizeLimitDefaultsTo20MiB(): void
    {
        $this->assertSame(20 * 1024 * 1024, AP_Plugin_Installer::DEFAULT_VALIDATE_MAX_BYTES);
        $this->assertSame(
            AP_Plugin_Installer::DEFAULT_VALIDATE_MAX_BYTES,
            AP_Plugin_Installer::maxValidateBytes()
        );
    }

    public function testFileAboveTheDefaultSizeLimitIsOversize(): void
    {
        $path = $this->writeSparse(
            'over-limit.bin',
            AP_Plugin_Installer::DEFAULT_VALIDATE_MAX_BYTES + 1
        );

        $error = AP_Plugin_Installer::validate($path);

        $this->assertInstanceOf(AP_Plugin_Install_Error::class, $error);
        $this->assertSame(AP_Plugin_Install_Error::OVERSIZE, $error->code());
        $this->assertStringContainsString('20 MB', $error->message());
    }

    public function testFileAtTheSizeLimitIsNotOversize(): void
    {
        $path = $this->writeSparse(
            'at-limit.bin',
            AP_Plugin_Installer::DEFAULT_VALIDATE_MAX_BYTES
        );

        $error = AP_Plugin_Installer::validate($path);

        $this->assertInstanceOf(AP_Plugin_Install_Error::class, $error);
        $this->assertSame(AP_Plugin_Install_Error::NOT_ZIP, $error->code());
        $this->assertStringContainsString('signature', strtolower($error->message()));
    }

    public function testSizeFilterCanLowerTheCapAndSeesThePath(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $zip = $this->zipWith([
            'sized/sized.php' => $this->pluginPhp('Sized', '1.0.0'),
        ]);
        $size = filesize($zip);
        $this->assertIsInt($size);
        $this->assertGreaterThan(1, $size);

        $seenPath = null;
        $seenCap = null;
        ap_add_filter(
            'ap_plugin_zip_max_bytes',
            static function (int $bytes, string $path) use (&$seenPath, &$seenCap, $size): int {
                $seenPath = $path;
                $seenCap = $bytes;

                return $size - 1;
            },
            10,
            2
        );

        $error = AP_Plugin_Installer::validate($zip, [
            'plugins_root' => $this->pluginsRoot,
        ]);

        $this->assertInstanceOf(AP_Plugin_Install_Error::class, $error);
        $this->assertSame(AP_Plugin_Install_Error::OVERSIZE, $error->code());
        $this->assertSame($zip, $seenPath);
        $this->assertSame(AP_Plugin_Installer::DEFAULT_VALIDATE_MAX_BYTES, $seenCap);
        $this->assertStringContainsString('exceed', strtolower($error->message()));
    }

    public function testSizeFilterAtTheFileSizeAllowsAValidZip(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $zip = $this->zipWith([
            'sized/sized.php' => $this->pluginPhp('Sized', '1.0.0'),
        ]);
        $size = filesize($zip);
        $this->assertIsInt($size);
        ap_add_filter(
            'ap_plugin_zip_max_bytes',
            static function () use ($size): int {
                return $size;
            }
        );

        $result = AP_Plugin_Installer::validate($zip, [
            'plugins_root' => $this->pluginsRoot,
        ]);

        $this->assertInstanceOf(AP_Plugin_Install_Result::class, $result);
        $this->assertSame('sized', $result->slug());
    }

    public function testSizeFilterRejectsNonPositiveAndAcceptsDigitStrings(): void
    {
        $rejected = [0, -5, false, null, '', '0', 'nope', 1.5, '20MiB'];
        foreach ($rejected as $bad) {
            ap_reset_hooks();
            ap_add_filter(
                'ap_plugin_zip_max_bytes',
                static function () use ($bad): mixed {
                    return $bad;
                }
            );
            $this->assertSame(
                AP_Plugin_Installer::DEFAULT_VALIDATE_MAX_BYTES,
                AP_Plugin_Installer::maxValidateBytes('/tmp/plugin.zip'),
                'filter value should be ignored: ' . var_export($bad, true)
            );
        }

        ap_reset_hooks();
        ap_add_filter(
            'ap_plugin_zip_max_bytes',
            static function (): string {
                return '4096';
            }
        );
        $this->assertSame(4096, AP_Plugin_Installer::maxValidateBytes('/tmp/plugin.zip'));
    }

    public function testOversizeIsReportedBeforeMagic(): void
    {
        $path = $this->workDir . '/short-over.zip';
        file_put_contents($path, 'PK');
        ap_add_filter(
            'ap_plugin_zip_max_bytes',
            static function (): int {
                return 1;
            }
        );

        $error = AP_Plugin_Installer::validate($path);

        $this->assertInstanceOf(AP_Plugin_Install_Error::class, $error);
        $this->assertSame(AP_Plugin_Install_Error::OVERSIZE, $error->code());
    }

    public function testPkPrefixWithoutZipSignatureIsRejected(): void
    {
        $path = $this->workDir . '/almost.zip';
        file_put_contents($path, "PK\x00\x00not-a-zip");

        $error = AP_Plugin_Installer::validate($path);

        $this->assertInstanceOf(AP_Plugin_Install_Error::class, $error);
        $this->assertSame(AP_Plugin_Install_Error::NOT_ZIP, $error->code());
        $this->assertStringContainsString('signature', strtolower($error->message()));
    }

    public function testShortHeaderIsRejectedByMagic(): void
    {
        $path = $this->workDir . '/short.zip';
        file_put_contents($path, 'PK');

        $error = AP_Plugin_Installer::validate($path);

        $this->assertInstanceOf(AP_Plugin_Install_Error::class, $error);
        $this->assertSame(AP_Plugin_Install_Error::NOT_ZIP, $error->code());
        $this->assertStringContainsString('signature', strtolower($error->message()));
    }

    public function testEmptyArchiveSignatureIsAZipMissingAHeader(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $path = $this->workDir . '/empty-archive.zip';
        $eocd = "PK\x05\x06"
            . pack('v', 0)
            . pack('v', 0)
            . pack('v', 0)
            . pack('v', 0)
            . pack('V', 0)
            . pack('V', 0)
            . pack('v', 0);
        $this->assertSame(22, file_put_contents($path, $eocd));

        $error = AP_Plugin_Installer::validate($path, [
            'plugins_root' => $this->pluginsRoot,
        ]);

        $this->assertInstanceOf(AP_Plugin_Install_Error::class, $error);
        $this->assertSame(AP_Plugin_Install_Error::MISSING_HEADER, $error->code());
        $this->assertStringNotContainsString('signature', strtolower($error->message()));
    }

    public function testSpannedSignatureIsNotRejectedAsMissingMagic(): void
    {
        $path = $this->workDir . '/spanned.zip';
        file_put_contents($path, "PK\x07\x08" . str_repeat("\x00", 20));

        $error = AP_Plugin_Installer::validate($path);

        $this->assertInstanceOf(AP_Plugin_Install_Error::class, $error);
        $this->assertStringNotContainsString('signature', strtolower($error->message()));
        if (class_exists(ZipArchive::class)) {
            $this->assertSame(AP_Plugin_Install_Error::NOT_ZIP, $error->code());
            $this->assertStringContainsString('valid zip', strtolower($error->message()));
        } else {
            $this->assertSame(AP_Plugin_Install_Error::ZIP_EXTENSION_MISSING, $error->code());
        }
    }

    public function testExtensionIsIgnoredWhenMagicMatches(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $zip = $this->zipWith([
            'renamed/renamed.php' => $this->pluginPhp('Renamed', '1.4.0'),
        ]);
        $renamed = $this->workDir . '/plugin.dat';
        $this->assertTrue(rename($zip, $renamed));

        $result = AP_Plugin_Installer::validate($renamed, [
            'plugins_root' => $this->pluginsRoot,
        ]);

        $this->assertInstanceOf(AP_Plugin_Install_Result::class, $result);
        $this->assertSame('renamed', $result->slug());
        $this->assertSame('1.4.0', $result->version());
    }

    public function testValidFolderZipReturnsStructuredResult(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $zip = $this->zipWith([
            'classic-upload/classic-upload.php' => $this->pluginPhp('Classic Upload', '1.2.3'),
            'classic-upload/readme.txt' => "Test plugin\n",
        ]);

        $result = AP_Plugin_Installer::validate($zip, [
            'plugins_root' => $this->pluginsRoot,
        ]);

        $this->assertInstanceOf(AP_Plugin_Install_Result::class, $result);
        $this->assertSame('classic-upload', $result->slug());
        $this->assertSame('Classic Upload', $result->name());
        $this->assertSame('1.2.3', $result->version());
        $this->assertNull($result->previousVersion());
        $this->assertSame(AP_Plugin_Install_Result::ACTION_INSTALLED, $result->action());
        $this->assertNull($result->backupPath());
        $this->assertFalse($result->activated());
        $this->assertSame('classic-upload/classic-upload.php', $result->plugin());
        $this->assertSame(
            [
                'ok' => true,
                'slug' => 'classic-upload',
                'name' => 'Classic Upload',
                'version' => '1.2.3',
                'previous_version' => null,
                'action' => 'installed',
                'backup_path' => null,
                'activated' => false,
                'plugin' => 'classic-upload/classic-upload.php',
            ],
            $result->toArray()
        );
        $this->assertDirectoryDoesNotExist($this->pluginsRoot . '/classic-upload');
    }

    public function testValidFlatZipReturnsStructuredResult(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $zip = $this->zipWith([
            'hello.php' => $this->pluginPhp('Hello Flat', '0.1.0'),
        ]);

        $result = AP_Plugin_Installer::validate($zip, [
            'plugins_root' => $this->pluginsRoot,
        ]);

        $this->assertInstanceOf(AP_Plugin_Install_Result::class, $result);
        $this->assertSame('hello', $result->slug());
        $this->assertSame('Hello Flat', $result->name());
        $this->assertSame('0.1.0', $result->version());
        $this->assertSame(AP_Plugin_Install_Result::ACTION_INSTALLED, $result->action());
        $this->assertSame('hello.php', $result->plugin());
        $this->assertFileDoesNotExist($this->pluginsRoot . '/hello.php');
    }

    public function testExistingSlugReportsUpgradeAndPreviousVersion(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $dir = $this->pluginsRoot . '/sample';
        $this->assertTrue(mkdir($dir, 0700, true));
        file_put_contents($dir . '/sample.php', $this->pluginPhp('Sample', '1.0.0'));

        $zip = $this->zipWith([
            'sample/sample.php' => $this->pluginPhp('Sample', '2.0.0'),
        ]);

        $result = AP_Plugin_Installer::validate($zip, [
            'plugins_root' => $this->pluginsRoot,
            'overwrite' => false,
        ]);

        $this->assertInstanceOf(AP_Plugin_Install_Result::class, $result);
        $this->assertSame('2.0.0', $result->version());
        $this->assertSame('1.0.0', $result->previousVersion());
        $this->assertSame(AP_Plugin_Install_Result::ACTION_UPGRADED, $result->action());
        $this->assertNull($result->backupPath());
        $this->assertFalse($result->activated());
        $this->assertStringContainsString('Version: 1.0.0', (string) file_get_contents($dir . '/sample.php'));
    }

    public function testMissingHeaderReturnsTypedError(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $noName = $this->zipWith([
            'bare/bare.php' => "<?php\n// no header\n",
        ]);
        $noNameError = AP_Plugin_Installer::validate($noName, [
            'plugins_root' => $this->pluginsRoot,
        ]);
        $this->assertInstanceOf(AP_Plugin_Install_Error::class, $noNameError);
        $this->assertSame(AP_Plugin_Install_Error::MISSING_HEADER, $noNameError->code());

        $noVersion = $this->zipWith([
            'named/named.php' => "<?php\n/**\n * Plugin Name: Named Only\n */\n",
        ]);
        $noVersionError = AP_Plugin_Installer::validate($noVersion, [
            'plugins_root' => $this->pluginsRoot,
        ]);
        $this->assertInstanceOf(AP_Plugin_Install_Error::class, $noVersionError);
        $this->assertSame(AP_Plugin_Install_Error::MISSING_HEADER, $noVersionError->code());
        $this->assertStringContainsString('Version', $noVersionError->message());
    }

    public function testTwoPluginFoldersReturnBadStructure(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $zip = $this->zipWith([
            'alpha/alpha.php' => $this->pluginPhp('Alpha', '1.0.0'),
            'beta/beta.php' => $this->pluginPhp('Beta', '1.0.0'),
        ]);

        $error = AP_Plugin_Installer::validate($zip, [
            'plugins_root' => $this->pluginsRoot,
        ]);

        $this->assertInstanceOf(AP_Plugin_Install_Error::class, $error);
        $this->assertSame(AP_Plugin_Install_Error::BAD_STRUCTURE, $error->code());
        $this->assertNotSame('', $error->message());
    }

    public function testExtraTopLevelFolderIsBadStructure(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $zip = $this->zipWith([
            'alpha/alpha.php' => $this->pluginPhp('Alpha', '1.0.0'),
            'notes/readme.txt' => "not a second plugin\n",
        ]);

        $error = AP_Plugin_Installer::validate($zip, [
            'plugins_root' => $this->pluginsRoot,
        ]);

        $this->assertInstanceOf(AP_Plugin_Install_Error::class, $error);
        $this->assertSame(AP_Plugin_Install_Error::BAD_STRUCTURE, $error->code());
        $this->assertStringContainsString('one top-level folder', strtolower($error->message()));
    }

    public function testRootFileBesideFolderIsBadStructure(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $zip = $this->zipWith([
            'alpha/alpha.php' => $this->pluginPhp('Alpha', '1.0.0'),
            'readme.txt' => "sits beside the folder\n",
        ]);

        $error = AP_Plugin_Installer::validate($zip, [
            'plugins_root' => $this->pluginsRoot,
        ]);

        $this->assertInstanceOf(AP_Plugin_Install_Error::class, $error);
        $this->assertSame(AP_Plugin_Install_Error::BAD_STRUCTURE, $error->code());
    }

    public function testInvalidFolderSlugIsRejected(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $zip = $this->zipWith([
            '@@@/plugin.php' => $this->pluginPhp('Symbols', '1.0.0'),
        ]);

        $error = AP_Plugin_Installer::validate($zip, [
            'plugins_root' => $this->pluginsRoot,
        ]);

        $this->assertInstanceOf(AP_Plugin_Install_Error::class, $error);
        $this->assertSame(AP_Plugin_Install_Error::INVALID_SLUG, $error->code());
        $this->assertStringContainsString('slug', strtolower($error->message()));
    }

    public function testFolderNameSanitizesToSlug(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $zip = $this->zipWith([
            'My Plugin/my-plugin.php' => $this->pluginPhp('My Plugin', '1.0.0'),
        ]);

        $result = AP_Plugin_Installer::validate($zip, [
            'plugins_root' => $this->pluginsRoot,
        ]);

        $this->assertInstanceOf(AP_Plugin_Install_Result::class, $result);
        $this->assertSame('my-plugin', $result->slug());
        $this->assertSame('My Plugin', $result->name());
        $this->assertSame('my-plugin/my-plugin.php', $result->plugin());
    }

    public function testFlatFileNameMapsToSlug(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $zip = $this->zipWith([
            'Hello World.php' => $this->pluginPhp('Hello World', '2.0.0'),
            'readme.txt' => "flat archive\n",
        ]);

        $result = AP_Plugin_Installer::validate($zip, [
            'plugins_root' => $this->pluginsRoot,
        ]);

        $this->assertInstanceOf(AP_Plugin_Install_Result::class, $result);
        $this->assertSame('hello-world', $result->slug());
        $this->assertSame('Hello World', $result->name());
        $this->assertSame('2.0.0', $result->version());
        $this->assertSame('hello-world.php', $result->plugin());
    }

    public function testUnmappableFlatFileNameIsInvalidSlug(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $zip = $this->zipWith([
            '@@@.php' => $this->pluginPhp('Symbols', '1.0.0'),
        ]);

        $error = AP_Plugin_Installer::validate($zip, [
            'plugins_root' => $this->pluginsRoot,
        ]);

        $this->assertInstanceOf(AP_Plugin_Install_Error::class, $error);
        $this->assertSame(AP_Plugin_Install_Error::INVALID_SLUG, $error->code());
    }

    public function testMatchingFolderFileWinsWhenAnotherHeaderExists(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $zip = $this->zipWith([
            'sample/other.php' => $this->pluginPhp('Other', '9.0.0'),
            'sample/sample.php' => $this->pluginPhp('Sample', '1.2.0'),
        ]);

        $result = AP_Plugin_Installer::validate($zip, [
            'plugins_root' => $this->pluginsRoot,
        ]);

        $this->assertInstanceOf(AP_Plugin_Install_Result::class, $result);
        $this->assertSame('sample', $result->slug());
        $this->assertSame('Sample', $result->name());
        $this->assertSame('1.2.0', $result->version());
        $this->assertSame('sample/sample.php', $result->plugin());
    }

    public function testAmbiguousFolderHeadersAreBadStructure(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $zip = $this->zipWith([
            'sample/one.php' => $this->pluginPhp('One', '1.0.0'),
            'sample/two.php' => $this->pluginPhp('Two', '1.0.0'),
        ]);

        $error = AP_Plugin_Installer::validate($zip, [
            'plugins_root' => $this->pluginsRoot,
        ]);

        $this->assertInstanceOf(AP_Plugin_Install_Error::class, $error);
        $this->assertSame(AP_Plugin_Install_Error::BAD_STRUCTURE, $error->code());
    }

    public function testMainFileNeedNotMatchFolderName(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $zip = $this->zipWith([
            'sample/plugin.php' => $this->pluginPhp('Sample', '1.2.0'),
            'sample/readme.txt' => "notes\n",
        ]);

        $result = AP_Plugin_Installer::validate($zip, [
            'plugins_root' => $this->pluginsRoot,
        ]);

        $this->assertInstanceOf(AP_Plugin_Install_Result::class, $result);
        $this->assertSame('sample', $result->slug());
        $this->assertSame('sample/plugin.php', $result->plugin());
    }

    public function testDeeplyNestedMainFileIsBadStructure(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $zip = $this->zipWith([
            'wrap/nested/deep.php' => $this->pluginPhp('Too Deep', '1.0.0'),
        ]);

        $error = AP_Plugin_Installer::validate($zip, [
            'plugins_root' => $this->pluginsRoot,
        ]);

        $this->assertInstanceOf(AP_Plugin_Install_Error::class, $error);
        $this->assertSame(AP_Plugin_Install_Error::BAD_STRUCTURE, $error->code());
        $this->assertStringContainsString('deeply', strtolower($error->message()));
    }

    public function testMacOsJunkDoesNotCountAsASecondFolder(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $zip = $this->zipWith([
            'classic-upload/classic-upload.php' => $this->pluginPhp('Classic Upload', '1.2.3'),
            '__MACOSX/classic-upload/._classic-upload.php' => 'junk',
            'classic-upload/.DS_Store' => 'junk',
        ]);

        $result = AP_Plugin_Installer::validate($zip, [
            'plugins_root' => $this->pluginsRoot,
        ]);

        $this->assertInstanceOf(AP_Plugin_Install_Result::class, $result);
        $this->assertSame('classic-upload', $result->slug());
    }

    public function testThemeFolderIsRejected(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $zip = $this->zipWith([
            'example-theme/style.css' => $this->themeCss('Example Theme', '1.0.0'),
            'example-theme/index.php' => "<?php\n// theme index\n",
            'example-theme/functions.php' => "<?php\n// theme setup\n",
        ]);

        $error = AP_Plugin_Installer::validate($zip, [
            'plugins_root' => $this->pluginsRoot,
        ]);

        $this->assertInstanceOf(AP_Plugin_Install_Error::class, $error);
        $this->assertSame(AP_Plugin_Install_Error::THEME_PACKAGE, $error->code());
        $this->assertStringContainsString('theme', strtolower($error->message()));
    }

    public function testThemeWithPluginHeaderIsStillRejected(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $zip = $this->zipWith([
            'example-theme/style.css' => $this->themeCss('Example Theme', '1.0.0'),
            'example-theme/functions.php' => $this->pluginPhp('Sneaky Theme', '1.0.0'),
            'example-theme/index.php' => "<?php\n",
        ]);

        $error = AP_Plugin_Installer::validate($zip, [
            'plugins_root' => $this->pluginsRoot,
        ]);

        $this->assertInstanceOf(AP_Plugin_Install_Error::class, $error);
        $this->assertSame(AP_Plugin_Install_Error::THEME_PACKAGE, $error->code());
    }

    public function testThemeAtArchiveRootIsRejected(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $zip = $this->zipWith([
            'style.css' => $this->themeCss('Flat Theme', '1.0.0'),
            'index.php' => "<?php\n",
        ]);

        $error = AP_Plugin_Installer::validate($zip, [
            'plugins_root' => $this->pluginsRoot,
        ]);

        $this->assertInstanceOf(AP_Plugin_Install_Error::class, $error);
        $this->assertSame(AP_Plugin_Install_Error::THEME_PACKAGE, $error->code());
    }

    public function testWrappedThemeWithoutPluginHeaderIsRejected(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $zip = $this->zipWith([
            'dist/example-theme/style.css' => $this->themeCss('Wrapped Theme', '1.0.0'),
            'dist/example-theme/index.php' => "<?php\n",
        ]);

        $error = AP_Plugin_Installer::validate($zip, [
            'plugins_root' => $this->pluginsRoot,
        ]);

        $this->assertInstanceOf(AP_Plugin_Install_Error::class, $error);
        $this->assertSame(AP_Plugin_Install_Error::THEME_PACKAGE, $error->code());
    }

    public function testPluginCssWithoutThemeNameStaysAPlugin(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $zip = $this->zipWith([
            'bundled/bundled.php' => $this->pluginPhp('Bundled', '1.0.0'),
            'bundled/style.css' => "body { color: #111; }\n",
        ]);

        $result = AP_Plugin_Installer::validate($zip, [
            'plugins_root' => $this->pluginsRoot,
        ]);

        $this->assertInstanceOf(AP_Plugin_Install_Result::class, $result);
        $this->assertSame('bundled', $result->slug());
        $this->assertSame('1.0.0', $result->version());
    }

    public function testNestedThemeStylesheetDoesNotRejectPlugin(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $zip = $this->zipWith([
            'bundled/bundled.php' => $this->pluginPhp('Bundled', '1.0.0'),
            'bundled/themes/demo/style.css' => $this->themeCss('Demo', '1.0.0'),
            'bundled/themes/demo/index.php' => "<?php\n",
        ]);

        $result = AP_Plugin_Installer::validate($zip, [
            'plugins_root' => $this->pluginsRoot,
        ]);

        $this->assertInstanceOf(AP_Plugin_Install_Result::class, $result);
        $this->assertSame('bundled/bundled.php', $result->plugin());
    }

    public function testAgoraPressCorePackageIsRejected(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $zip = $this->zipWith([
            'AgoraPress/index.php' => "<?php\n",
            'AgoraPress/ap-admin/index.php' => "<?php\n",
            'AgoraPress/ap-includes/version.php' => "<?php\ndefine('AP_VERSION', '0.3.12-beta');\n",
            'AgoraPress/ap-content/plugins/demo/demo.php' => $this->pluginPhp('Nested Demo', '1.0.0'),
        ]);

        $error = AP_Plugin_Installer::validate($zip, [
            'plugins_root' => $this->pluginsRoot,
        ]);

        $this->assertInstanceOf(AP_Plugin_Install_Error::class, $error);
        $this->assertSame(AP_Plugin_Install_Error::CORE_PACKAGE, $error->code());
        $this->assertStringContainsString('core', strtolower($error->message()));
    }

    public function testWordPressCorePackageIsRejected(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $zip = $this->zipWith([
            'wordpress/index.php' => "<?php\n",
            'wordpress/wp-admin/index.php' => "<?php\n",
            'wordpress/wp-includes/version.php' => "<?php\n",
            'wordpress/wp-config-sample.php' => "<?php\n",
        ]);

        $error = AP_Plugin_Installer::validate($zip, [
            'plugins_root' => $this->pluginsRoot,
        ]);

        $this->assertInstanceOf(AP_Plugin_Install_Error::class, $error);
        $this->assertSame(AP_Plugin_Install_Error::CORE_PACKAGE, $error->code());
    }

    public function testCoreFilesAtArchiveRootAreRejected(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $zip = $this->zipWith([
            'index.php' => "<?php\n",
            'ap-admin/admin.php' => "<?php\n",
            'ap-includes/version.php' => "<?php\ndefine('AP_VERSION', '0.3.12-beta');\n",
        ]);

        $error = AP_Plugin_Installer::validate($zip, [
            'plugins_root' => $this->pluginsRoot,
        ]);

        $this->assertInstanceOf(AP_Plugin_Install_Error::class, $error);
        $this->assertSame(AP_Plugin_Install_Error::CORE_PACKAGE, $error->code());
    }

    public function testWordPressSettingsPairIsRejected(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $zip = $this->zipWith([
            'wp-config-sample.php' => "<?php\n",
            'wp-settings.php' => "<?php\n",
            'index.php' => "<?php\n",
        ]);

        $error = AP_Plugin_Installer::validate($zip, [
            'plugins_root' => $this->pluginsRoot,
        ]);

        $this->assertInstanceOf(AP_Plugin_Install_Error::class, $error);
        $this->assertSame(AP_Plugin_Install_Error::CORE_PACKAGE, $error->code());
    }

    public function testUnsafePathReturnsTypedError(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $zip = $this->zipWith([
            'evil/evil.php' => $this->pluginPhp('Evil', '1.0.0'),
            '../outside.php' => "<?php\n// pwned\n",
        ]);

        $error = AP_Plugin_Installer::validate($zip, [
            'plugins_root' => $this->pluginsRoot,
        ]);

        $this->assertInstanceOf(AP_Plugin_Install_Error::class, $error);
        $this->assertSame(AP_Plugin_Install_Error::UNSAFE_PATH, $error->code());
        $this->assertStringContainsString('unsafe', strtolower($error->message()));
        $this->assertFileDoesNotExist($this->workDir . '/outside.php');
    }

    public function testOversizeIsReportedBeforeSha256(): void
    {
        $path = $this->writeSparse(
            'over-hash.bin',
            AP_Plugin_Installer::DEFAULT_VALIDATE_MAX_BYTES + 1
        );

        $error = AP_Plugin_Installer::validate($path, [
            'expected_sha256' => str_repeat('ab', 32),
        ]);

        $this->assertInstanceOf(AP_Plugin_Install_Error::class, $error);
        $this->assertSame(AP_Plugin_Install_Error::OVERSIZE, $error->code());
    }

    public function testNonZipIsReportedBeforeSha256(): void
    {
        $path = $this->workDir . '/notes-hash.zip';
        file_put_contents($path, 'this is not a zip archive');

        $error = AP_Plugin_Installer::validate($path, [
            'expected_sha256' => str_repeat('ab', 32),
        ]);

        $this->assertInstanceOf(AP_Plugin_Install_Error::class, $error);
        $this->assertSame(AP_Plugin_Install_Error::NOT_ZIP, $error->code());
        $this->assertStringContainsString('signature', strtolower($error->message()));
    }

    public function testBlankExpectedSha256IsIgnored(): void
    {
        $path = $this->workDir . '/blank-hash.zip';
        file_put_contents($path, 'this is not a zip archive');

        foreach (['', '   ', null] as $blank) {
            $error = AP_Plugin_Installer::validate($path, [
                'expected_sha256' => $blank,
            ]);

            $this->assertInstanceOf(AP_Plugin_Install_Error::class, $error);
            $this->assertSame(AP_Plugin_Install_Error::NOT_ZIP, $error->code());
        }
    }

    public function testInvalidSha256IsRejectedBeforeTheArchiveIsOpened(): void
    {
        $path = $this->workDir . '/magic-bad-hash.zip';
        file_put_contents($path, "PK\x03\x04" . str_repeat("\x00", 24));

        foreach (['abc', 'sha256:', 'sha256:' . str_repeat('ab', 31), str_repeat('zz', 32)] as $bad) {
            $error = AP_Plugin_Installer::validate($path, [
                'expected_sha256' => $bad,
            ]);

            $this->assertInstanceOf(AP_Plugin_Install_Error::class, $error);
            $this->assertSame(AP_Plugin_Install_Error::SHA256_MISMATCH, $error->code(), $bad);
            $this->assertStringContainsString('64-character', $error->message());
        }
    }

    public function testMatchingSha256ReturnsStructuredResult(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $zip = $this->zipWith([
            'checksum/checksum.php' => $this->pluginPhp('Checksum', '1.2.3'),
        ]);
        $hash = hash_file('sha256', $zip);
        $this->assertIsString($hash);

        $result = AP_Plugin_Installer::validate($zip, [
            'plugins_root' => $this->pluginsRoot,
            'expected_sha256' => 'SHA256:' . strtoupper($hash),
        ]);

        $this->assertInstanceOf(AP_Plugin_Install_Result::class, $result);
        $this->assertSame('checksum', $result->slug());
        $this->assertSame('Checksum', $result->name());
        $this->assertSame('1.2.3', $result->version());
        $this->assertDirectoryDoesNotExist($this->pluginsRoot . '/checksum');
    }

    public function testSha256MismatchIsReportedBeforeArchiveInspection(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $zip = $this->zipWith([
            'evil/evil.php' => $this->pluginPhp('Evil', '1.0.0'),
            '../outside.php' => "<?php\n// pwned\n",
        ]);

        $error = ap_validate_plugin_zip($zip, [
            'plugins_root' => $this->pluginsRoot,
            'expected_sha256' => str_repeat('ab', 32),
        ]);

        $this->assertInstanceOf(AP_Plugin_Install_Error::class, $error);
        $this->assertSame(AP_Plugin_Install_Error::SHA256_MISMATCH, $error->code());
        $this->assertStringContainsString('does not match', $error->message());
        $this->assertFileDoesNotExist($this->workDir . '/outside.php');
        $this->assertDirectoryDoesNotExist($this->pluginsRoot . '/evil');
    }

    public function testMatchingSha256StillRejectsUnsafePaths(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $zip = $this->zipWith([
            'evil/evil.php' => $this->pluginPhp('Evil', '1.0.0'),
            '../outside.php' => "<?php\n// pwned\n",
        ]);
        $hash = hash_file('sha256', $zip);
        $this->assertIsString($hash);

        $error = AP_Plugin_Installer::validate($zip, [
            'plugins_root' => $this->pluginsRoot,
            'expected_sha256' => $hash,
        ]);

        $this->assertInstanceOf(AP_Plugin_Install_Error::class, $error);
        $this->assertSame(AP_Plugin_Install_Error::UNSAFE_PATH, $error->code());
        $this->assertFileDoesNotExist($this->workDir . '/outside.php');
    }

    public function testProceduralHelperReturnsSameContract(): void
    {
        $this->assertTrue(function_exists('ap_validate_plugin_zip'));

        $error = ap_validate_plugin_zip($this->workDir . '/missing.zip');

        $this->assertInstanceOf(AP_Plugin_Install_Error::class, $error);
        $this->assertSame(AP_Plugin_Install_Error::MISSING_FILE, $error->code());
    }

    public function testResultFactoryRejectsBlankIdentity(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AP_Plugin_Install_Result::success(
            '',
            'Name',
            '1.0.0',
            null,
            AP_Plugin_Install_Result::ACTION_INSTALLED,
            null,
            false,
            'x.php'
        );
    }

    public function testRejectsZipSlipEntryNames(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $absolute = sys_get_temp_dir() . '/ap-plugslip-' . bin2hex(random_bytes(4)) . '.php';
        $cases = [
            'parent segment' => [
                '../outside.php' => "<?php\n// pwned\n",
            ],
            'nested parent segment' => [
                'pkg/pkg.php' => $this->pluginPhp('Pkg', '1.0.0'),
                'pkg/../../outside.php' => "<?php\n// pwned\n",
            ],
            'absolute' => [
                'pkg/pkg.php' => $this->pluginPhp('Pkg', '1.0.0'),
                $absolute => "<?php\n// pwned\n",
            ],
            'backslash separator' => [
                'evil\\evil.php' => $this->pluginPhp('Evil', '1.0.0'),
            ],
            'backslash parent' => [
                '..\\outside.php' => "<?php\n// pwned\n",
            ],
            'drive letter with slash' => [
                'C:/Windows/evil.php' => $this->pluginPhp('Evil', '1.0.0'),
            ],
            'bare drive letter' => [
                'D:evil.php' => $this->pluginPhp('Evil', '1.0.0'),
            ],
            'drive letter with backslash' => [
                'C:\\evil.php' => $this->pluginPhp('Evil', '1.0.0'),
            ],
            'unc backslash' => [
                '\\\\server\\share\\evil.php' => $this->pluginPhp('Evil', '1.0.0'),
            ],
        ];

        try {
            foreach ($cases as $label => $entries) {
                $zip = $this->zipWith($entries);
                $error = AP_Plugin_Installer::validate($zip, [
                    'plugins_root' => $this->pluginsRoot,
                ]);

                $this->assertInstanceOf(AP_Plugin_Install_Error::class, $error, $label);
                $this->assertSame(AP_Plugin_Install_Error::UNSAFE_PATH, $error->code(), $label);
                $this->assertStringContainsString('unsafe', strtolower($error->message()), $label);

                $installed = AP_Plugin_Installer::installFromZip($zip, [
                    'plugins_root' => $this->pluginsRoot,
                ]);
                $this->assertFalse($installed['ok'], $label);
                $this->assertStringContainsString(
                    'unsafe',
                    strtolower(implode(' ', $installed['errors'])),
                    $label
                );
            }

            $this->assertFileDoesNotExist($absolute);
            $this->assertFileDoesNotExist($this->workDir . '/outside.php');
            $this->assertFileDoesNotExist($this->pluginsRoot . '/evil/evil.php');
            $this->assertFileDoesNotExist($this->pluginsRoot . '/evil.php');
            $this->assertDirectoryDoesNotExist($this->pluginsRoot . '/pkg');
            $this->assertDirectoryDoesNotExist($this->pluginsRoot . '/evil');
        } finally {
            if (is_file($absolute)) {
                unlink($absolute);
            }
            $leaks = [
                $this->root . '/outside.php',
                dirname($this->root) . '/outside.php',
                getcwd() . '/outside.php',
            ];
            foreach ($leaks as $leak) {
                if (is_file($leak)) {
                    unlink($leak);
                }
            }
        }
    }

    public function testRejectsNulByteThatZipArchiveTruncates(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $name = "good/good.php\0../outside.php";
        $zip = $this->zipWithRawNames(
            $name,
            $name,
            $this->pluginPhp('Good', '1.0.0')
        );

        $opened = new ZipArchive();
        $this->assertTrue($opened->open($zip));
        $this->assertSame('good/good.php', $opened->getNameIndex(0));
        $opened->close();

        $error = AP_Plugin_Installer::validate($zip, [
            'plugins_root' => $this->pluginsRoot,
        ]);
        $this->assertInstanceOf(AP_Plugin_Install_Error::class, $error);
        $this->assertSame(AP_Plugin_Install_Error::UNSAFE_PATH, $error->code());

        $installed = AP_Plugin_Installer::installFromZip($zip, [
            'plugins_root' => $this->pluginsRoot,
        ]);
        $this->assertFalse($installed['ok']);
        $this->assertDirectoryDoesNotExist($this->pluginsRoot . '/good');
        $this->assertFileDoesNotExist($this->workDir . '/outside.php');
    }

    public function testRejectsLocalHeaderTraversalHiddenByCentralName(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $zip = $this->zipWithRawNames(
            '../outside.php',
            'good/good.php',
            $this->pluginPhp('Good', '2.0.0')
        );

        $opened = new ZipArchive();
        $this->assertTrue($opened->open($zip));
        $this->assertSame('good/good.php', $opened->getNameIndex(0));
        $opened->close();

        $error = AP_Plugin_Installer::validate($zip, [
            'plugins_root' => $this->pluginsRoot,
        ]);
        $this->assertInstanceOf(AP_Plugin_Install_Error::class, $error);
        $this->assertSame(AP_Plugin_Install_Error::UNSAFE_PATH, $error->code());
        $this->assertDirectoryDoesNotExist($this->pluginsRoot . '/good');
        $this->assertFileDoesNotExist($this->workDir . '/outside.php');
    }

    public function testDotDotInsideAFileNameIsNotTraversal(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $zip = $this->zipWith([
            'my..plugin/my..plugin.php' => $this->pluginPhp('Dotted', '1.4.0'),
        ]);

        $result = AP_Plugin_Installer::validate($zip, [
            'plugins_root' => $this->pluginsRoot,
        ]);

        $this->assertInstanceOf(AP_Plugin_Install_Result::class, $result);
        $this->assertSame('my-plugin', $result->slug());
        $this->assertSame('Dotted', $result->name());
        $this->assertSame('1.4.0', $result->version());
        $this->assertSame('my-plugin/my..plugin.php', $result->plugin());
        $this->assertDirectoryDoesNotExist($this->pluginsRoot . '/my-plugin');
        $this->assertDirectoryDoesNotExist($this->pluginsRoot . '/my..plugin');
    }

    public function testResolvedRealpathMustStayInsideStaging(): void
    {
        $staging = $this->workDir . '/staging';
        $outside = $this->workDir . '/outside';
        $inner = $staging . '/inner';
        $this->assertTrue(mkdir($staging));
        $this->assertTrue(mkdir($outside));
        $this->assertTrue(mkdir($inner));
        $linked = @symlink($outside, $staging . '/escape')
            && @symlink($inner, $staging . '/inside')
            && @symlink($outside, $inner . '/hop');
        if (!$linked) {
            $this->markTestSkipped('symlinks are not available');
        }

        $method = new ReflectionMethod(AP_Plugin_Installer::class, 'entryStaysInsideStaging');
        $stays = static fn (string $name): bool => (bool) $method->invoke(null, $staging, $name);

        $this->assertFalse($stays('escape/pwned.php'));
        $this->assertFalse($stays('inside/hop/pwned.php'));
        $this->assertFalse($stays('inner/hop/pwned.php'));
        $this->assertFalse($stays('../outside/pwned.php'));
        $this->assertFalse($stays('/etc/passwd'));
        $this->assertFalse($stays("plugin\0.php"));
        $this->assertFalse($stays('plugin\\plugin.php'));
        $this->assertFalse($stays('C:/Windows/evil.php'));
        $this->assertFalse($stays('D:evil.php'));
        $this->assertTrue($stays('inside/file.php'));
        $this->assertTrue($stays('inner/file.php'));
        $this->assertTrue($stays('ok/file.php'));
        $this->assertTrue($stays('ok/nested/file.php'));

        $realInside = realpath($staging . '/inside');
        $this->assertSame(realpath($inner), $realInside);
        $this->assertFileDoesNotExist($outside . '/pwned.php');
        $this->assertDirectoryDoesNotExist($staging . '/ok');
        $this->assertFileDoesNotExist($inner . '/file.php');
    }

    public function testStagingDirectoryIsRemovedAfterValidate(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $before = glob(sys_get_temp_dir() . '/ap-plugin-stage-*') ?: [];
        $zip = $this->zipWith([
            'kept/kept.php' => $this->pluginPhp('Kept', '1.0.0'),
            'kept/readme.txt' => "hello\n",
        ]);

        $result = AP_Plugin_Installer::validate($zip, [
            'plugins_root' => $this->pluginsRoot,
        ]);

        $after = glob(sys_get_temp_dir() . '/ap-plugin-stage-*') ?: [];
        $this->assertInstanceOf(AP_Plugin_Install_Result::class, $result);
        $this->assertSame('kept', $result->slug());
        $this->assertEqualsCanonicalizing($before, $after);
        $this->assertDirectoryDoesNotExist($this->pluginsRoot . '/kept');
        $this->assertDirectoryDoesNotExist($this->workDir . '/.ap-plugin-staging');
    }

    public function testExtractsOnlyIntoPrivateStagingUnderContentDir(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $contentDir = $this->workDir . '/content';
        $this->assertTrue(mkdir($contentDir));
        file_put_contents($this->pluginsRoot . '/.keep', 'stay');
        $php = $this->pluginPhp('Staged', '1.2.3');
        $zipPath = $this->zipWith([
            'staged/staged.php' => $php,
            'staged/readme.txt' => "notes\n",
        ]);

        $opened = new ZipArchive();
        $this->assertTrue($opened->open($zipPath) === true);
        $method = new ReflectionMethod(AP_Plugin_Installer::class, 'extractIntoPrivateStaging');
        $staged = $method->invoke(null, $opened, 'staged/', null, $contentDir, $this->pluginsRoot);
        $opened->close();

        $this->assertIsArray($staged);
        $stage = $staged['stage'];
        $payload = $staged['payload'];
        $this->assertIsString($stage);
        $this->assertIsString($payload);
        $stageReal = realpath($stage);
        $payloadReal = realpath($payload);
        $contentReal = realpath($contentDir);
        $pluginsReal = realpath($this->pluginsRoot);
        $this->assertNotFalse($stageReal);
        $this->assertNotFalse($payloadReal);
        $this->assertNotFalse($contentReal);
        $this->assertNotFalse($pluginsReal);
        $this->assertStringStartsWith($contentReal . DIRECTORY_SEPARATOR, $stageReal);
        $this->assertStringContainsString('/.ap-plugin-staging/ap-plugin-stage-', str_replace('\\', '/', $stageReal));
        $this->assertStringStartsWith($stageReal . DIRECTORY_SEPARATOR, $payloadReal);
        $this->assertFalse(str_starts_with($stageReal, $pluginsReal . DIRECTORY_SEPARATOR));
        $this->assertSame(0700, fileperms($stage) & 0777);
        $this->assertSame(0700, fileperms(dirname($stage)) & 0777);
        $deny = dirname($stage) . '/.htaccess';
        $this->assertFileExists($deny);
        $this->assertStringContainsString('Require all denied', (string) file_get_contents($deny));
        $this->assertSame($php, (string) file_get_contents($payload . '/staged.php'));
        $this->assertSame("notes\n", (string) file_get_contents($payload . '/readme.txt'));
        $this->assertSame(['.', '..', '.keep'], scandir($this->pluginsRoot));
        $this->assertFileDoesNotExist($this->pluginsRoot . '/staged.php');
        $this->assertDirectoryDoesNotExist($this->pluginsRoot . '/staged');

        $flatPath = $this->zipWith([
            'flat-stage.php' => $this->pluginPhp('Flat Stage', '0.1.0'),
            'ignored.txt' => "nope\n",
        ]);
        $flatZip = new ZipArchive();
        $this->assertTrue($flatZip->open($flatPath) === true);
        $flat = $method->invoke(null, $flatZip, '', 'flat-stage.php', $contentDir, $this->pluginsRoot);
        $flatZip->close();
        $this->assertIsArray($flat);
        $this->assertFileExists($flat['payload'] . '/flat-stage.php');
        $this->assertFileDoesNotExist($flat['payload'] . '/ignored.txt');
        $this->assertFalse(str_starts_with(
            (string) realpath($flat['stage']),
            $pluginsReal . DIRECTORY_SEPARATOR
        ));

        $discard = new ReflectionMethod(AP_Plugin_Installer::class, 'discardStagingDir');
        $this->assertTrue($discard->invoke(null, $stage));
        $this->assertTrue($discard->invoke(null, $flat['stage']));
        $this->assertDirectoryDoesNotExist($contentDir . '/.ap-plugin-staging');
        $this->assertSame(['.', '..', '.keep'], scandir($this->pluginsRoot));
    }

    public function testValidateUnpacksThenRemovesPrivateStaging(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $contentDir = $this->workDir . '/content';
        $this->assertTrue(mkdir($contentDir));
        file_put_contents($this->pluginsRoot . '/.keep', 'stay');
        $zip = $this->zipWith([
            'unpacked/unpacked.php' => $this->pluginPhp('Unpacked', '3.0.0'),
            'unpacked/readme.txt' => "body\n",
        ]);

        $result = AP_Plugin_Installer::validate($zip, [
            'plugins_root' => $this->pluginsRoot,
            'content_dir' => $contentDir,
        ]);

        $this->assertInstanceOf(AP_Plugin_Install_Result::class, $result);
        $this->assertSame('unpacked', $result->slug());
        $this->assertSame('Unpacked', $result->name());
        $this->assertSame('3.0.0', $result->version());
        $this->assertDirectoryDoesNotExist($contentDir . '/.ap-plugin-staging');
        $this->assertSame(['.', '..', '.keep'], scandir($this->pluginsRoot));
        $this->assertDirectoryDoesNotExist($this->pluginsRoot . '/unpacked');
        $entries = scandir($contentDir);
        $this->assertIsArray($entries);
        $this->assertSame(['.', '..'], $entries);
    }

    public function testRefusesStagingInsideThePluginsDirectory(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $inside = $this->pluginsRoot . '/nested-content';
        $this->assertTrue(mkdir($inside));
        $zip = $this->zipWith([
            'hidden/hidden.php' => $this->pluginPhp('Hidden', '1.0.0'),
        ]);

        $error = AP_Plugin_Installer::validate($zip, [
            'plugins_root' => $this->pluginsRoot,
            'content_dir' => $inside,
        ]);

        $this->assertInstanceOf(AP_Plugin_Install_Error::class, $error);
        $this->assertSame(AP_Plugin_Install_Error::STAGING_FAILED, $error->code());
        $this->assertStringContainsString('plugins directory', strtolower($error->message()));
        $this->assertDirectoryDoesNotExist($inside . '/.ap-plugin-staging');
        $this->assertDirectoryDoesNotExist($this->pluginsRoot . '/hidden');
        $this->assertFileDoesNotExist($this->pluginsRoot . '/hidden.php');
        $this->assertSame(['.', '..', 'nested-content'], scandir($this->pluginsRoot));
    }

    public function testUnwritableContentDirDoesNotExtractIntoPlugins(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $contentDir = $this->workDir . '/content';
        $this->assertTrue(mkdir($contentDir));
        $this->assertTrue(chmod($contentDir, 0555));
        $probe = $contentDir . '/probe-' . bin2hex(random_bytes(2));
        $blocked = @file_put_contents($probe, 'x') === false;
        if (!$blocked) {
            @unlink($probe);
            chmod($contentDir, 0755);
            $this->markTestSkipped('content directory could not be made read-only');
        }

        try {
            $zip = $this->zipWith([
                'locked/locked.php' => $this->pluginPhp('Locked', '1.0.0'),
            ]);
            $error = AP_Plugin_Installer::validate($zip, [
                'plugins_root' => $this->pluginsRoot,
                'content_dir' => $contentDir,
            ]);
            $installed = AP_Plugin_Installer::installFromZip($zip, [
                'plugins_root' => $this->pluginsRoot,
                'content_dir' => $contentDir,
            ]);
        } finally {
            chmod($contentDir, 0755);
        }

        $this->assertInstanceOf(AP_Plugin_Install_Error::class, $error);
        $this->assertSame(AP_Plugin_Install_Error::STAGING_FAILED, $error->code());
        $this->assertStringContainsString('staging', strtolower($error->message()));
        $this->assertFalse($installed['ok']);
        $this->assertStringContainsString('staging', strtolower(implode(' ', $installed['errors'])));
        $this->assertDirectoryDoesNotExist($this->pluginsRoot . '/locked');
        $this->assertDirectoryDoesNotExist($contentDir . '/.ap-plugin-staging');
        $this->assertSame(['.', '..'], scandir($this->pluginsRoot));
    }

    public function testInstallMovesStagedPayloadAndLeavesNoStagingDir(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $contentDir = $this->workDir . '/content';
        $this->assertTrue(mkdir($contentDir));
        $php = $this->pluginPhp('Moved', '4.5.6');
        $zip = $this->zipWith([
            'moved/moved.php' => $php,
            'moved/readme.txt' => "kept\n",
        ]);

        $installed = AP_Plugin_Installer::installFromZip($zip, [
            'plugins_root' => $this->pluginsRoot,
            'content_dir' => $contentDir,
        ]);

        $this->assertTrue($installed['ok'], implode(' ', $installed['errors']));
        $this->assertSame('moved/moved.php', $installed['plugin']);
        $this->assertSame($php, (string) file_get_contents($this->pluginsRoot . '/moved/moved.php'));
        $this->assertSame("kept\n", (string) file_get_contents($this->pluginsRoot . '/moved/readme.txt'));
        $this->assertDirectoryDoesNotExist($contentDir . '/.ap-plugin-staging');
        $this->assertDirectoryDoesNotExist($contentDir . '/moved');
        $this->assertSame(['.', '..'], scandir($contentDir));
    }

    public function testSymlinkEntriesAreRejected(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $php = $this->pluginPhp('Linked', '1.0.0');
        $cases = [
            'unix file symlink' => [
                'entries' => [
                    'linked/linked.php' => $php,
                    'linked/escape' => '/tmp/outside',
                ],
                'directories' => [],
                'modes' => [
                    'linked/escape' => [
                        'opsys' => ZipArchive::OPSYS_UNIX,
                        'mode' => 0120777,
                    ],
                ],
                'typed' => 'linked/escape',
            ],
            'dos host id with symlink mode' => [
                'entries' => [
                    'linked/linked.php' => $php,
                    'linked/escape' => '../secret',
                ],
                'directories' => [],
                'modes' => [
                    'linked/escape' => [
                        'opsys' => ZipArchive::OPSYS_DOS,
                        'mode' => 0120777,
                    ],
                ],
                'typed' => 'linked/escape',
            ],
            'directory symlink' => [
                'entries' => [
                    'linked/linked.php' => $php,
                ],
                'directories' => ['linked/sub'],
                'modes' => [
                    'linked/sub/' => [
                        'opsys' => ZipArchive::OPSYS_UNIX,
                        'mode' => 0120777,
                    ],
                ],
                'typed' => 'linked/sub/',
            ],
        ];

        foreach ($cases as $label => $case) {
            $zip = $this->zipWithModes($case['entries'], $case['modes'], $case['directories']);
            $this->assertSame(0120000, $this->unixTypeOf($zip, $case['typed']), $label);

            $error = AP_Plugin_Installer::validate($zip, [
                'plugins_root' => $this->pluginsRoot,
            ]);
            $this->assertInstanceOf(AP_Plugin_Install_Error::class, $error, $label);
            $this->assertSame(AP_Plugin_Install_Error::SYMLINK, $error->code(), $label);
            $this->assertStringContainsString('symbolic link', strtolower($error->message()), $label);

            $installed = AP_Plugin_Installer::installFromZip($zip, [
                'plugins_root' => $this->pluginsRoot,
            ]);
            $this->assertFalse($installed['ok'], $label);
            $this->assertStringContainsString(
                'symbolic link',
                strtolower(implode(' ', $installed['errors'])),
                $label
            );
            $this->assertDirectoryDoesNotExist($this->pluginsRoot . '/linked');
        }

        $this->assertFileDoesNotExist($this->workDir . '/outside');
        $this->assertFileDoesNotExist($this->workDir . '/secret');
    }

    public function testSpecialFileEntriesAreRejected(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $php = $this->pluginPhp('Special', '1.0.0');
        $cases = [
            'fifo' => 0010666,
            'character device' => 0020666,
            'block device' => 0060666,
            'socket' => 0140666,
            'unknown unix type' => 0110000,
        ];

        foreach ($cases as $label => $mode) {
            $zip = $this->zipWithModes(
                [
                    'special/special.php' => $php,
                    'special/node' => 'x',
                ],
                [
                    'special/node' => [
                        'opsys' => ZipArchive::OPSYS_UNIX,
                        'mode' => $mode,
                    ],
                ]
            );
            $this->assertNotSame(0, $this->unixTypeOf($zip, 'special/node'), $label);
            $this->assertNotSame(0100000, $this->unixTypeOf($zip, 'special/node'), $label);
            $this->assertNotSame(0040000, $this->unixTypeOf($zip, 'special/node'), $label);

            $error = AP_Plugin_Installer::validate($zip, [
                'plugins_root' => $this->pluginsRoot,
            ]);
            $this->assertInstanceOf(AP_Plugin_Install_Error::class, $error, $label);
            $this->assertSame(AP_Plugin_Install_Error::SPECIAL_FILE, $error->code(), $label);
            $this->assertStringContainsString('special file', strtolower($error->message()), $label);

            $installed = AP_Plugin_Installer::installFromZip($zip, [
                'plugins_root' => $this->pluginsRoot,
            ]);
            $this->assertFalse($installed['ok'], $label);
            $this->assertStringContainsString(
                'special file',
                strtolower(implode(' ', $installed['errors'])),
                $label
            );
            $this->assertDirectoryDoesNotExist($this->pluginsRoot . '/special');
        }
    }

    public function testUnixRegularAndDirectoryModesAreAllowed(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $zip = $this->zipWithModes(
            [
                'modes/modes.php' => $this->pluginPhp('Modes', '3.1.0'),
                'modes/readme.txt' => "plain\n",
            ],
            [
                'modes/' => [
                    'opsys' => ZipArchive::OPSYS_UNIX,
                    'mode' => 0040755,
                ],
                'modes/modes.php' => [
                    'opsys' => ZipArchive::OPSYS_UNIX,
                    'mode' => 0100644,
                ],
                'modes/readme.txt' => [
                    'opsys' => ZipArchive::OPSYS_DOS,
                    'mode' => 0100644,
                ],
            ],
            ['modes']
        );
        $this->assertSame(0040000, $this->unixTypeOf($zip, 'modes/'));
        $this->assertSame(0100000, $this->unixTypeOf($zip, 'modes/modes.php'));
        $this->assertSame(0100000, $this->unixTypeOf($zip, 'modes/readme.txt'));

        $result = AP_Plugin_Installer::validate($zip, [
            'plugins_root' => $this->pluginsRoot,
        ]);

        $this->assertInstanceOf(AP_Plugin_Install_Result::class, $result);
        $this->assertSame('modes', $result->slug());
        $this->assertSame('Modes', $result->name());
        $this->assertSame('3.1.0', $result->version());
        $this->assertDirectoryDoesNotExist($this->pluginsRoot . '/modes');
    }

    public function testEntryCountCapIsFilterable(): void
    {
        $this->assertSame(5000, AP_Plugin_Installer::DEFAULT_MAX_ENTRIES);
        $this->assertSame(
            AP_Plugin_Installer::DEFAULT_MAX_ENTRIES,
            AP_Plugin_Installer::maxValidateEntries()
        );
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $zip = $this->zipWith([
            'counted/counted.php' => $this->pluginPhp('Counted', '1.0.0'),
            'counted/a.txt' => 'a',
            'counted/b.txt' => 'b',
        ]);
        $opened = new ZipArchive();
        $this->assertTrue($opened->open($zip) === true);
        $count = $opened->numFiles;
        $opened->close();
        $this->assertSame(3, $count);

        $seenPath = null;
        $seenCap = null;
        ap_add_filter(
            'ap_plugin_zip_max_entries',
            static function (int $cap, string $path) use (&$seenPath, &$seenCap): int {
                $seenPath = $path;
                $seenCap = $cap;

                return 2;
            },
            10,
            2
        );

        $error = AP_Plugin_Installer::validate($zip, [
            'plugins_root' => $this->pluginsRoot,
        ]);
        $this->assertInstanceOf(AP_Plugin_Install_Error::class, $error);
        $this->assertSame(AP_Plugin_Install_Error::TOO_MANY_ENTRIES, $error->code());
        $this->assertStringContainsString('limit 2', $error->message());
        $this->assertSame($zip, $seenPath);
        $this->assertSame(AP_Plugin_Installer::DEFAULT_MAX_ENTRIES, $seenCap);
        $this->assertDirectoryDoesNotExist($this->pluginsRoot . '/counted');

        $installed = AP_Plugin_Installer::installFromZip($zip, [
            'plugins_root' => $this->pluginsRoot,
        ]);
        $this->assertFalse($installed['ok']);
        $this->assertStringContainsString('too many', strtolower(implode(' ', $installed['errors'])));
        $this->assertDirectoryDoesNotExist($this->pluginsRoot . '/counted');

        ap_reset_hooks();
        ap_add_filter(
            'ap_plugin_zip_max_entries',
            static function () use ($count): int {
                return $count;
            }
        );

        $result = AP_Plugin_Installer::validate($zip, [
            'plugins_root' => $this->pluginsRoot,
        ]);
        $this->assertInstanceOf(AP_Plugin_Install_Result::class, $result);
        $this->assertSame('counted', $result->slug());
        $this->assertSame('1.0.0', $result->version());
        $this->assertDirectoryDoesNotExist($this->pluginsRoot . '/counted');
    }

    public function testUncompressedSizeCapIsFilterable(): void
    {
        $this->assertSame(128 * 1024 * 1024, AP_Plugin_Installer::DEFAULT_MAX_UNCOMPRESSED_BYTES);
        $this->assertSame(
            AP_Plugin_Installer::DEFAULT_MAX_UNCOMPRESSED_BYTES,
            AP_Plugin_Installer::maxValidateUncompressedBytes()
        );
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $zip = $this->zipWith([
            'sized-raw/sized-raw.php' => $this->pluginPhp('Sized Raw', '1.0.0'),
            'sized-raw/readme.txt' => str_repeat('a', 400),
        ]);
        $total = $this->declaredUncompressedTotal($zip);
        $this->assertGreaterThan(400, $total);

        $seenPath = null;
        $seenCap = null;
        ap_add_filter(
            'ap_plugin_zip_max_uncompressed_bytes',
            static function (int $cap, string $path) use (&$seenPath, &$seenCap, $total): int {
                $seenPath = $path;
                $seenCap = $cap;

                return $total - 1;
            },
            10,
            2
        );

        $error = AP_Plugin_Installer::validate($zip, [
            'plugins_root' => $this->pluginsRoot,
        ]);
        $this->assertInstanceOf(AP_Plugin_Install_Error::class, $error);
        $this->assertSame(AP_Plugin_Install_Error::UNCOMPRESSED_TOO_LARGE, $error->code());
        $this->assertStringContainsString('uncompressed', strtolower($error->message()));
        $this->assertSame($zip, $seenPath);
        $this->assertSame(AP_Plugin_Installer::DEFAULT_MAX_UNCOMPRESSED_BYTES, $seenCap);
        $this->assertDirectoryDoesNotExist($this->pluginsRoot . '/sized-raw');

        $installed = AP_Plugin_Installer::installFromZip($zip, [
            'plugins_root' => $this->pluginsRoot,
        ]);
        $this->assertFalse($installed['ok']);
        $this->assertStringContainsString('uncompressed', strtolower(implode(' ', $installed['errors'])));
        $this->assertDirectoryDoesNotExist($this->pluginsRoot . '/sized-raw');

        ap_reset_hooks();
        ap_add_filter(
            'ap_plugin_zip_max_uncompressed_bytes',
            static function () use ($total): int {
                return $total;
            }
        );

        $result = AP_Plugin_Installer::validate($zip, [
            'plugins_root' => $this->pluginsRoot,
        ]);
        $this->assertInstanceOf(AP_Plugin_Install_Result::class, $result);
        $this->assertSame('sized-raw', $result->slug());
        $this->assertSame('Sized Raw', $result->name());
        $this->assertDirectoryDoesNotExist($this->pluginsRoot . '/sized-raw');
    }

    public function testDeclaredUncompressedBombIsRejectedBeforeInflate(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $declared = 512 * 1024 * 1024;
        $this->assertGreaterThan(AP_Plugin_Installer::DEFAULT_MAX_UNCOMPRESSED_BYTES, $declared);
        $zip = $this->zipWithDeclaredUncompressed(
            'bomb/bomb.php',
            $this->pluginPhp('Bomb', '9.9.9'),
            $declared
        );
        $fileSize = filesize($zip);
        $this->assertIsInt($fileSize);
        $this->assertLessThan(4096, $fileSize);
        $this->assertSame($declared, $this->declaredUncompressedTotal($zip));

        $error = AP_Plugin_Installer::validate($zip, [
            'plugins_root' => $this->pluginsRoot,
        ]);
        $this->assertInstanceOf(AP_Plugin_Install_Error::class, $error);
        $this->assertSame(AP_Plugin_Install_Error::UNCOMPRESSED_TOO_LARGE, $error->code());
        $this->assertStringContainsString('128 MB', $error->message());
        $this->assertDirectoryDoesNotExist($this->pluginsRoot . '/bomb');

        $installed = AP_Plugin_Installer::installFromZip($zip, [
            'plugins_root' => $this->pluginsRoot,
        ]);
        $this->assertFalse($installed['ok']);
        $this->assertStringContainsString('uncompressed', strtolower(implode(' ', $installed['errors'])));
        $this->assertDirectoryDoesNotExist($this->pluginsRoot . '/bomb');
        $this->assertLessThan(4096, (int) filesize($zip));
    }

    public function testArchiveCapFiltersIgnoreNonPositiveValues(): void
    {
        $hooks = [
            'ap_plugin_zip_max_entries' => [
                AP_Plugin_Installer::DEFAULT_MAX_ENTRIES,
                '30',
                30,
                'maxValidateEntries',
            ],
            'ap_plugin_zip_max_uncompressed_bytes' => [
                AP_Plugin_Installer::DEFAULT_MAX_UNCOMPRESSED_BYTES,
                '2048',
                2048,
                'maxValidateUncompressedBytes',
            ],
        ];
        $rejected = [0, -5, false, null, '', '0', 'nope', 1.5, '20MiB'];

        foreach ($hooks as $hook => $spec) {
            [$default, $digits, $parsed, $method] = $spec;
            foreach ($rejected as $bad) {
                ap_reset_hooks();
                ap_add_filter(
                    $hook,
                    static function () use ($bad): mixed {
                        return $bad;
                    }
                );
                $this->assertSame(
                    $default,
                    AP_Plugin_Installer::{$method}('/tmp/plugin.zip'),
                    $hook . ' ignored ' . var_export($bad, true)
                );
            }

            ap_reset_hooks();
            ap_add_filter(
                $hook,
                static function () use ($digits): string {
                    return $digits;
                }
            );
            $this->assertSame($parsed, AP_Plugin_Installer::{$method}('/tmp/plugin.zip'), $hook);
        }
    }

    public function testUnreadableEntryNamesAreZipOpenFailed(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $zip = $this->zipWith([
            'named/named.php' => $this->pluginPhp('Named', '1.0.0'),
        ]);
        $bytes = file_get_contents($zip);
        $this->assertIsString($bytes);
        $eocd = strrpos($bytes, "PK\x05\x06");
        $this->assertNotFalse($eocd);

        $cases = [
            'bytes after the end of central directory' => $bytes . 'JUNK',
            'comment length past the end of the file' => substr_replace($bytes, pack('v', 4), $eocd + 20, 2),
        ];

        foreach ($cases as $label => $crafted) {
            $path = $this->workDir . '/unreadable-' . bin2hex(random_bytes(3)) . '.zip';
            $this->assertNotFalse(file_put_contents($path, $crafted));

            $opened = new ZipArchive();
            $this->assertTrue($opened->open($path) === true, $label);
            $opened->close();

            $error = AP_Plugin_Installer::validate($path, [
                'plugins_root' => $this->pluginsRoot,
            ]);
            $this->assertInstanceOf(AP_Plugin_Install_Error::class, $error, $label);
            $this->assertSame(AP_Plugin_Install_Error::ZIP_OPEN_FAILED, $error->code(), $label);
            $this->assertStringContainsString('entry names', strtolower($error->message()), $label);

            $installed = AP_Plugin_Installer::installFromZip($path, [
                'plugins_root' => $this->pluginsRoot,
            ]);
            $this->assertFalse($installed['ok'], $label);
            $this->assertStringContainsString(
                'entry names',
                strtolower(implode(' ', $installed['errors'])),
                $label
            );
            $this->assertDirectoryDoesNotExist($this->pluginsRoot . '/named');
        }
    }

    public function testBlankDotAndSlashEntryNamesAreUnsafe(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $cases = [
            'empty name' => ['', ''],
            'dot segment' => ['.', '.'],
            'slash only' => ['/', '/'],
            'leading slash' => ['/etc/passwd', '/etc/passwd'],
        ];

        foreach ($cases as $label => [$local, $central]) {
            $zip = $this->zipWithRawNames($local, $central, "<?php\n// not a plugin\n");
            $opened = new ZipArchive();
            $this->assertTrue($opened->open($zip) === true, $label);
            $opened->close();

            $error = AP_Plugin_Installer::validate($zip, [
                'plugins_root' => $this->pluginsRoot,
            ]);
            $this->assertInstanceOf(AP_Plugin_Install_Error::class, $error, $label);
            $this->assertSame(AP_Plugin_Install_Error::UNSAFE_PATH, $error->code(), $label);
            $this->assertStringContainsString('unsafe', strtolower($error->message()), $label);

            $installed = AP_Plugin_Installer::installFromZip($zip, [
                'plugins_root' => $this->pluginsRoot,
            ]);
            $this->assertFalse($installed['ok'], $label);
            $this->assertSame(['.', '..'], scandir($this->pluginsRoot), $label);
        }

        $this->assertFileDoesNotExist($this->workDir . '/passwd');
    }

    public function testDisallowedScriptExtensionsAreRejected(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $blocked = [
            'php3',
            'php4',
            'php5',
            'phtml',
            'phar',
            'cgi',
            'pl',
            'py',
            'exe',
            'sh',
            'bat',
            'cmd',
        ];

        foreach ($blocked as $ext) {
            $zip = $this->zipWith([
                'typed/typed.php' => $this->pluginPhp('Typed', '1.0.0'),
                'typed/extra.' . $ext => "nope\n",
            ]);

            $error = AP_Plugin_Installer::validate($zip, [
                'plugins_root' => $this->pluginsRoot,
            ]);
            $this->assertInstanceOf(AP_Plugin_Install_Error::class, $error, $ext);
            $this->assertSame(AP_Plugin_Install_Error::BAD_STRUCTURE, $error->code(), $ext);
            $this->assertStringContainsString('disallowed', strtolower($error->message()), $ext);
            $this->assertStringContainsString('.' . $ext, strtolower($error->message()), $ext);

            $installed = AP_Plugin_Installer::installFromZip($zip, [
                'plugins_root' => $this->pluginsRoot,
            ]);
            $this->assertFalse($installed['ok'], $ext);
            $this->assertStringContainsString(
                '.' . $ext,
                strtolower(implode(' ', $installed['errors'])),
                $ext
            );
            $this->assertDirectoryDoesNotExist($this->pluginsRoot . '/typed');
        }

        $upper = $this->zipWith([
            'typed/typed.php' => $this->pluginPhp('Typed', '1.0.0'),
            'typed/extra.PHTML' => "nope\n",
        ]);
        $upperError = AP_Plugin_Installer::validate($upper, [
            'plugins_root' => $this->pluginsRoot,
        ]);
        $this->assertInstanceOf(AP_Plugin_Install_Error::class, $upperError);
        $this->assertSame(AP_Plugin_Install_Error::BAD_STRUCTURE, $upperError->code());
        $this->assertStringContainsString('.phtml', strtolower($upperError->message()));
        $this->assertDirectoryDoesNotExist($this->pluginsRoot . '/typed');
    }

    public function testTwoPluginFilesAtArchiveRootAreBadStructure(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $zip = $this->zipWith([
            'one.php' => $this->pluginPhp('One', '1.0.0'),
            'two.php' => $this->pluginPhp('Two', '2.0.0'),
        ]);

        $error = AP_Plugin_Installer::validate($zip, [
            'plugins_root' => $this->pluginsRoot,
        ]);

        $this->assertInstanceOf(AP_Plugin_Install_Error::class, $error);
        $this->assertSame(AP_Plugin_Install_Error::BAD_STRUCTURE, $error->code());
        $this->assertStringContainsString('archive root', strtolower($error->message()));
        $this->assertFileDoesNotExist($this->pluginsRoot . '/one.php');
        $this->assertFileDoesNotExist($this->pluginsRoot . '/two.php');

        $installed = AP_Plugin_Installer::installFromZip($zip, [
            'plugins_root' => $this->pluginsRoot,
        ]);
        $this->assertFalse($installed['ok']);
        $this->assertStringContainsString('archive root', strtolower(implode(' ', $installed['errors'])));
        $this->assertFileDoesNotExist($this->pluginsRoot . '/one.php');
        $this->assertFileDoesNotExist($this->pluginsRoot . '/two.php');
    }

    public function testDirectoryOnlyZipIsMissingHeader(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $zip = $this->zipWithModes([], [], ['onlydir']);

        $error = AP_Plugin_Installer::validate($zip, [
            'plugins_root' => $this->pluginsRoot,
        ]);

        $this->assertInstanceOf(AP_Plugin_Install_Error::class, $error);
        $this->assertSame(AP_Plugin_Install_Error::MISSING_HEADER, $error->code());
        $this->assertStringContainsString('Plugin Name', $error->message());
        $this->assertDirectoryDoesNotExist($this->pluginsRoot . '/onlydir');
    }

    public function testEncryptedMainFileIsMissingHeader(): void
    {
        if (!class_exists(ZipArchive::class) || !defined(ZipArchive::class . '::EM_AES_256')) {
            $this->markTestSkipped('ZipArchive encryption is not available');
        }

        $path = $this->workDir . '/sealed-' . bin2hex(random_bytes(3)) . '.zip';
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        $this->assertTrue($zip->addFromString(
            'sealed/sealed.php',
            $this->pluginPhp('Sealed', '1.0.0')
        ));
        $encrypted = $zip->setEncryptionName('sealed/sealed.php', ZipArchive::EM_AES_256, 'secret');
        $zip->close();
        if ($encrypted !== true) {
            $this->markTestSkipped('ZipArchive could not encrypt an entry');
        }

        $error = AP_Plugin_Installer::validate($path, [
            'plugins_root' => $this->pluginsRoot,
        ]);

        $this->assertInstanceOf(AP_Plugin_Install_Error::class, $error);
        $this->assertSame(AP_Plugin_Install_Error::MISSING_HEADER, $error->code());
        $this->assertStringContainsString('Plugin Name', $error->message());

        $installed = AP_Plugin_Installer::installFromZip($path, [
            'plugins_root' => $this->pluginsRoot,
        ]);
        $this->assertFalse($installed['ok']);
        $this->assertDirectoryDoesNotExist($this->pluginsRoot . '/sealed');
    }

    private function writeSparse(string $name, int $size): string
    {
        $this->assertGreaterThan(0, $size);
        $path = $this->workDir . '/' . $name;
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            $this->fail('Could not create size fixture.');
        }
        $this->assertSame(0, fseek($handle, $size - 1));
        $this->assertSame(1, fwrite($handle, 'x'));
        fclose($handle);
        $this->assertSame($size, filesize($path));

        return $path;
    }

    /**
     * @param array<string, string> $entries
     */
    private function zipWith(array $entries): string
    {
        $path = $this->workDir . '/pkg-' . bin2hex(random_bytes(4)) . '.zip';
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        foreach ($entries as $name => $contents) {
            $this->assertTrue($zip->addFromString($name, $contents));
        }
        $zip->close();

        return $path;
    }

    /**
     * Build a stored zip whose local-header name and central-directory name may differ.
     *
     * ZipArchive::addFromString() truncates at NUL and will not keep a local-header
     * name that disagrees with the central directory.
     */
    private function zipWithRawNames(string $localName, string $centralName, string $data): string
    {
        $crc = crc32($data) & 0xffffffff;
        $size = strlen($data);
        $u16 = static fn (int $value): string => pack('v', $value);
        $u32 = static fn (int $value): string => pack('V', $value & 0xffffffff);
        $local = "PK\x03\x04"
            . $u16(20) . $u16(0) . $u16(0) . $u16(0) . $u16(0)
            . $u32($crc) . $u32($size) . $u32($size)
            . $u16(strlen($localName)) . $u16(0)
            . $localName . $data;
        $central = "PK\x01\x02"
            . $u16(20) . $u16(20) . $u16(0) . $u16(0) . $u16(0) . $u16(0)
            . $u32($crc) . $u32($size) . $u32($size)
            . $u16(strlen($centralName)) . $u16(0) . $u16(0) . $u16(0) . $u16(0)
            . $u32(0) . $u32(0)
            . $centralName;
        $eocd = "PK\x05\x06"
            . $u16(0) . $u16(0) . $u16(1) . $u16(1)
            . $u32(strlen($central)) . $u32(strlen($local)) . $u16(0);
        $path = $this->workDir . '/crafted-' . bin2hex(random_bytes(4)) . '.zip';
        $this->assertNotFalse(file_put_contents($path, $local . $central . $eocd));

        return $path;
    }

    /**
     * @param array<string, string> $entries
     * @param array<string, array{opsys: int, mode: int}> $modes Unix mode, not pre-shifted.
     * @param list<string> $directories
     */
    private function zipWithModes(array $entries, array $modes, array $directories = []): string
    {
        $path = $this->workDir . '/pkg-' . bin2hex(random_bytes(4)) . '.zip';
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        foreach ($directories as $dir) {
            $this->assertTrue($zip->addEmptyDir($dir));
        }
        foreach ($entries as $name => $contents) {
            $this->assertTrue($zip->addFromString($name, $contents));
        }
        foreach ($modes as $name => $spec) {
            $this->assertTrue(
                $zip->setExternalAttributesName($name, $spec['opsys'], $spec['mode'] << 16),
                $name
            );
        }
        $zip->close();

        return $path;
    }

    private function unixTypeOf(string $zipPath, string $name): int
    {
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipPath) === true);
        $opsys = 0;
        $attr = 0;
        $this->assertTrue($zip->getExternalAttributesName($name, $opsys, $attr), $name);
        $zip->close();

        return ($attr >> 16) & 0170000;
    }

    private function declaredUncompressedTotal(string $zipPath): int
    {
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipPath) === true);
        $total = 0;
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);
            $this->assertIsArray($stat);
            $this->assertIsInt($stat['size']);
            $this->assertGreaterThanOrEqual(0, $stat['size']);
            $total += $stat['size'];
        }
        $zip->close();

        return $total;
    }

    private function zipWithDeclaredUncompressed(string $name, string $data, int $declared): string
    {
        $this->assertGreaterThan(0, $declared);
        $this->assertLessThanOrEqual(0xFFFFFFFF, $declared);
        $crc = crc32($data) & 0xffffffff;
        $size = strlen($data);
        $u16 = static fn (int $value): string => pack('v', $value);
        $u32 = static fn (int $value): string => pack('V', $value & 0xffffffff);
        $local = "PK\x03\x04"
            . $u16(20) . $u16(0) . $u16(0) . $u16(0) . $u16(0)
            . $u32($crc) . $u32($size) . $u32($declared)
            . $u16(strlen($name)) . $u16(0)
            . $name . $data;
        $central = "PK\x01\x02"
            . $u16(20) . $u16(20) . $u16(0) . $u16(0) . $u16(0) . $u16(0)
            . $u32($crc) . $u32($size) . $u32($declared)
            . $u16(strlen($name)) . $u16(0) . $u16(0) . $u16(0) . $u16(0)
            . $u32(0) . $u32(0)
            . $name;
        $eocd = "PK\x05\x06"
            . $u16(0) . $u16(0) . $u16(1) . $u16(1)
            . $u32(strlen($central)) . $u32(strlen($local)) . $u16(0);
        $path = $this->workDir . '/bomb-' . bin2hex(random_bytes(4)) . '.zip';
        $this->assertNotFalse(file_put_contents($path, $local . $central . $eocd));

        return $path;
    }

    private function pluginPhp(string $name, string $version): string
    {
        return "<?php\n/**\n * Plugin Name: {$name}\n * Version: {$version}\n */\n";
    }

    private function themeCss(string $name, string $version): string
    {
        return "/*\nTheme Name: {$name}\nVersion: {$version}\n*/\n";
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $entries = scandir($dir);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                $this->removeDir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
