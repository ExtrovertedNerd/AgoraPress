<?php

/**
 * Presence + discovery of the 0.3.9-beta charter’s SPEC-minimum PHPUnit cases.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class CharterSpecTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    /**
     * SPEC “Tests (minimum)” mapped to the suite methods that cover them.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function specMinimumCaseProvider(): array
    {
        return [
            'ensure creates Uncategorized' => [
                'tests/Taxonomy/TaxonomyTest.php',
                'testEnsureDefaultCategoryCreatesUncategorizedWhenNoLivingDefault',
            ],
            'ensure does not clobber living default' => [
                'tests/Taxonomy/TaxonomyTest.php',
                'testEnsureDefaultCategoryDoesNotClobberLivingDefault',
            ],
            'Uncategorized deletable after default moves' => [
                'tests/Taxonomy/TaxonomyTest.php',
                'testUncategorizedIsDeletableOnceItIsNotTheDefault',
            ],
            'last remaining category undeletable' => [
                'tests/Taxonomy/TaxonomyTest.php',
                'testLastRemainingCategoryCannotBeDeleted',
            ],
            'current default undeletable' => [
                'tests/Taxonomy/TaxonomyTest.php',
                'testCurrentDefaultCannotBeDeletedWhenAnotherCategoryExists',
            ],
            'Set as default then delete Uncategorized reassigns' => [
                'tests/Admin/AdminTermsTest.php',
                'testSetAsDefaultThenUncategorizedDeleteReassignsOrphans',
            ],
            'default delete uses honest message' => [
                'tests/Admin/AdminTermsTest.php',
                'testRowDeleteOfDefaultCategoryReturnsHonestMessage',
            ],
            'Writing save stores a living term id' => [
                'tests/Options/SettingsApiTest.php',
                'testUpdateWritingSettingsPersistsLivingTermId',
            ],
            'Writing refuses magic 0' => [
                'tests/Options/SettingsApiTest.php',
                'testUpdateWritingSettingsZeroResolvesToLivingTermId',
            ],
            'Writing then delete Uncategorized reassigns' => [
                'tests/Options/SettingsApiTest.php',
                'testWritingDefaultThenUncategorizedDeleteReassignsOrphans',
            ],
            'Posted in skips empty names' => [
                'tests/Template/TemplateTagsTest.php',
                'testCategoryListSkipsEmptyNames',
            ],
            'Posted in omitted when none remain' => [
                'tests/Template/TemplateTagsTest.php',
                'testCategoryListIsEmptyWhenAllNamesAreEmpty',
            ],
            'preview off: no header control' => [
                'tests/Theme/AgoraThemeTest.php',
                'testVisitorPreviewControlAbsentByDefault',
            ],
            'preview on: midnight query does not write option' => [
                'tests/Theme/AgoraThemeTest.php',
                'testVisitorPreviewQueryAppliesMidnightWithoutWritingOption',
            ],
            'preview cookie wins over site option' => [
                'tests/Theme/AgoraThemeTest.php',
                'testPreviewCookieWinsOverSiteOption',
            ],
            'invalid slug ignored; marble hard default' => [
                'tests/Theme/AgoraThemeTest.php',
                'testInvalidPreviewSlugIgnoredThenOptionThenMarble',
            ],
            'marble remains the hard default' => [
                'tests/Theme/AgoraThemeTest.php',
                'testDefaultSchemeIsMarble',
            ],
            'Agora dark schemes keep editor contrast' => [
                'tests/Theme/AgoraThemeTest.php',
                'testSixSchemesKeepEditorContrastWithoutAddons',
            ],
            'editor contrast fixture without Agora CSS' => [
                'tests/Editor/EditorTest.php',
                'testContrastFixtureWithoutAgoraStylesheet',
            ],
        ];
    }

    #[DataProvider('specMinimumCaseProvider')]
    public function testSpecMinimumCaseExists(string $relative, string $method): void
    {
        $path = $this->root . '/' . $relative;
        $this->assertFileIsReadable($path, "Missing charter suite: {$relative}");
        $src = (string) file_get_contents($path);
        $this->assertStringContainsString(
            'function ' . $method,
            $src,
            "{$relative} must define {$method}"
        );
    }

    public function testPhpunitDiscoversSpecMinimumCases(): void
    {
        $phpunit = $this->root . '/vendor/bin/phpunit';
        if (!is_file($phpunit)) {
            $this->markTestSkipped('vendor/bin/phpunit not installed');
        }

        $files = [];
        foreach (self::specMinimumCaseProvider() as $row) {
            $files[$this->root . '/' . $row[0]] = true;
        }

        $php = PHP_BINARY !== '' ? PHP_BINARY : 'php';
        $cmd = escapeshellarg($php)
            . ' ' . escapeshellarg($phpunit)
            . ' --configuration=' . escapeshellarg($this->root . '/phpunit.xml.dist')
            . ' --list-tests';
        foreach (array_keys($files) as $path) {
            $cmd .= ' ' . escapeshellarg($path);
        }
        $cmd .= ' 2>&1';

        $output = [];
        $exit = 0;
        exec($cmd, $output, $exit);
        $body = implode("\n", $output);

        $this->assertSame(0, $exit, "phpunit --list-tests failed:\n{$body}");
        foreach (self::specMinimumCaseProvider() as $label => $row) {
            $this->assertStringContainsString(
                '::' . $row[1],
                $body,
                "phpunit must discover {$row[1]} ({$label})"
            );
        }
    }
}
