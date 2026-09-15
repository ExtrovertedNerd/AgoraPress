<?php

/**
 * Presence + discovery of SPEC-minimum PHPUnit cases (0.3.10-beta charter
 * plus 0.3.11-beta report: one open report; guest cannot; duplicate refused).
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
            'spoiler bbcode to details' => [
                'tests/Content/ContentFormatTest.php',
                'testSpoilerFormatMarkupToDetails',
            ],
            'empty spoiler body renders nothing' => [
                'tests/Content/ContentFormatTest.php',
                'testSpoilerEmptyBodyRendersNothing',
            ],
            'default spoiler label Spoiler' => [
                'tests/Content/ContentFormatTest.php',
                'testSpoilerDefaultLabelAndTitleAttribute',
            ],
            'excerpt does not leak spoiler inner text' => [
                'tests/Template/TemplateTagsTest.php',
                'testExcerptStripsSpoilerInnerText',
            ],
            'feed does not leak spoiler inner text' => [
                'tests/Feed/FeedTest.php',
                'testRssAndAtomStripSpoilerInnerText',
            ],
            'toolbar spoiler wraps visual and inserts shortcode' => [
                'tests/Editor/EditorTest.php',
                'testSpoilerButtonWrapsVisualAndInsertsShortcodeInText',
            ],
            'spoiler toolbar on post page comment forum' => [
                'tests/Editor/EditorTest.php',
                'testSpoilerToolbarIsSharedOnPostPageCommentForum',
            ],
            'create as other author' => [
                'tests/Admin/AdminPostsTest.php',
                'testCreateAsOtherAuthor',
            ],
            'update as other author' => [
                'tests/Admin/AdminPostsTest.php',
                'testUpdateAsOtherAuthor',
            ],
            'no edit_others cannot reassign' => [
                'tests/Admin/AdminPostsTest.php',
                'testNoCapCannotReassignAuthor',
            ],
            'crafted POST author ignored' => [
                'tests/Admin/AdminPostsTest.php',
                'testCraftedPostAuthorIgnored',
            ],
            'site off does not enqueue' => [
                'tests/Forum/ForumNotifyEnqueueTest.php',
                'testSiteOffReplyPostDoesNotEnqueue',
            ],
            'user off does not send' => [
                'tests/Forum/ForumNotifyWorkerTest.php',
                'testUserOffWorkerDoesNotSend',
            ],
            'poster excluded from mail' => [
                'tests/Forum/ForumNotifyWorkerTest.php',
                'testPosterExcludedFromWorkerMail',
            ],
            'lost view_forum excluded' => [
                'tests/Forum/ForumNotifyWorkerTest.php',
                'testWorkerDropsLostViewForum',
            ],
            'unique user+topic pair' => [
                'tests/Database/TopicSubscriptionsMigrationTest.php',
                'testUniqueConstraintAndAddRemovePair',
            ],
            'token unsubscribes one topic' => [
                'tests/Forum/ForumNotifyWorkerTest.php',
                'testSignedTokenUnsubscribesOneTopicWithoutSession',
            ],
            'worker leaves rate_limit_mail alone' => [
                'tests/Forum/ForumNotifyWorkerTest.php',
                'testWorkerLeavesRateLimitMailAlone',
            ],
            'migrate schema 12 to 13' => [
                'tests/Database/TopicSubscriptionsMigrationTest.php',
                'testMigrateFromSchema12CreatesSubscriptionsTable',
            ],
            'migration 13 is idempotent' => [
                'tests/Database/TopicSubscriptionsMigrationTest.php',
                'testUpIsIdempotentWhenTableAlreadyExists',
            ],
            'no theme file still renders form' => [
                'tests/Comment/CommentsTemplateTest.php',
                'testNoThemeFileStillRendersFallbackForm',
            ],
            'non-singular prints empty' => [
                'tests/Comment/CommentsTemplateTest.php',
                'testNonSingularPrintsEmptyCommentsMarkup',
            ],
            'Agora single still one form' => [
                'tests/Comment/CommentsTemplateTest.php',
                'testAgoraSingleRendersExactlyOneCommentForm',
            ],
            'one open post report' => [
                'tests/Forum/ForumReportPostTest.php',
                'testLoggedInMemberCreatesOneOpenPostReport',
            ],
            'guest cannot report post' => [
                'tests/Forum/ForumReportPostTest.php',
                'testGuestCannotReportPost',
            ],
            'duplicate open report refused' => [
                'tests/Forum/ForumReportPostTest.php',
                'testDuplicateOpenReportRefused',
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
