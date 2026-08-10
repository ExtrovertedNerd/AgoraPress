<?php

/**
 * Tests for AP_Content_Format — BBCode, Markdown, limited safe HTML.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Content;

use AP_Content_Format;
use AP_DB;
use AP_Forum;
use AP_Migrator;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_Content_Format::class)]
final class ContentFormatTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        require_once $this->root . '/ap-includes/class-ap-content-format.php';
        require_once $this->root . '/ap-includes/functions.php';
        if (is_readable($this->root . '/ap-includes/hooks.php')) {
            require_once $this->root . '/ap-includes/hooks.php';
            if (function_exists('ap_reset_hooks')) {
                ap_reset_hooks();
            }
        }
    }

    protected function tearDown(): void
    {
        if (function_exists('ap_reset_hooks')) {
            ap_reset_hooks();
        }
    }

    public function testBbcodeBasicTags(): void
    {
        $html = AP_Content_Format::format('[b]Bold[/b] [i]Italic[/i] [u]Under[/u] [s]Strike[/s]');
        $this->assertStringContainsString('<strong>Bold</strong>', $html);
        $this->assertStringContainsString('<em>Italic</em>', $html);
        $this->assertStringContainsString('<u>Under</u>', $html);
        $this->assertStringContainsString('<del>Strike</del>', $html);
    }

    public function testNbspEntityDisplaysAsRegularSpace(): void
    {
        // Visual editor often inserts &nbsp; between period and emoji.
        $html = AP_Content_Format::format(
            'You could be a little more enthusiastic.&nbsp;😏',
            ['mode' => 'auto', 'context' => 'comment']
        );
        $this->assertStringNotContainsString('&amp;nbsp;', $html);
        $this->assertStringNotContainsString('&nbsp;', $html);
        $this->assertStringContainsString('enthusiastic. 😏', $html);

        // Already in a paragraph with entity.
        $html2 = AP_Content_Format::format(
            '<p>Hello.&nbsp;world</p>',
            ['mode' => 'html', 'context' => 'comment']
        );
        $this->assertStringNotContainsString('&nbsp;', $html2);
        $this->assertStringContainsString('Hello. world', $html2);

        // Unicode NBSP (U+00A0).
        $html3 = AP_Content_Format::format("Hi.\xC2\xA0there", ['mode' => 'auto']);
        $this->assertStringContainsString('Hi. there', $html3);

        // Double-encoded form after htmlspecialchars.
        $this->assertSame(
            'a b',
            trim(strip_tags(AP_Content_Format::normalizeNbsp('a&amp;nbsp;b')))
        );
    }

    public function testBbcodeUrlAndImg(): void
    {
        $html = AP_Content_Format::format(
            '[url=https://example.com/path]Click[/url] [url]https://example.com/[/url]'
        );
        $this->assertStringContainsString('href="https://example.com/path"', $html);
        $this->assertStringContainsString('Click</a>', $html);
        $this->assertStringContainsString('rel="nofollow ugc"', $html);

        $img = AP_Content_Format::format('[img]https://cdn.example.com/a.png[/img]');
        $this->assertStringContainsString('<img src="https://cdn.example.com/a.png"', $img);
        $this->assertStringContainsString('alt=""', $img);
    }

    public function testBbcodeRejectsJavascriptUrls(): void
    {
        $html = AP_Content_Format::format('[url=javascript:alert(1)]x[/url]');
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('href="javascript', $html);

        $img = AP_Content_Format::format('[img]javascript:alert(1)[/img]');
        $this->assertStringNotContainsString('javascript:', $img);
        $this->assertStringNotContainsString('<img', $img);
    }

    public function testBbcodeQuoteListCode(): void
    {
        $html = AP_Content_Format::format(
            "[quote=Ada]Hello[/quote]\n[list]\n[*]One\n[*]Two\n[/list]\n[code]a < b & c[/code]"
        );
        $this->assertStringContainsString('<blockquote', $html);
        $this->assertStringContainsString('<cite>Ada</cite>', $html);
        $this->assertStringContainsString('<ul>', $html);
        $this->assertStringContainsString('<li>One</li>', $html);
        $this->assertStringContainsString('<li>Two</li>', $html);
        $this->assertStringContainsString('<pre class="ap-code"><code>', $html);
        $this->assertStringContainsString('a &lt; b &amp; c', $html);
    }

    public function testMarkdownSubset(): void
    {
        $src = "# Title\n\n**bold** and *italic* and ~~strike~~\n\n"
            . "A [link](https://example.com) here.\n\n"
            . "- item one\n- item two\n\n"
            . "1. first\n2. second\n\n"
            . "```php\necho 1;\n```\n\n"
            . "Inline `code` here.\n\n"
            . "> quoted line\n";

        $html = AP_Content_Format::format($src, ['mode' => 'markdown']);
        $this->assertStringContainsString('<h1>Title</h1>', $html);
        $this->assertStringContainsString('<strong>bold</strong>', $html);
        $this->assertStringContainsString('<em>italic</em>', $html);
        $this->assertStringContainsString('<del>strike</del>', $html);
        $this->assertStringContainsString('href="https://example.com"', $html);
        $this->assertStringContainsString('<ul>', $html);
        $this->assertStringContainsString('<ol>', $html);
        $this->assertStringContainsString('language-php', $html);
        $this->assertStringContainsString('echo 1;', $html);
        $this->assertStringContainsString('<code>code</code>', $html);
        $this->assertStringContainsString('<blockquote>', $html);
        $this->assertStringContainsString('quoted line', $html);
    }

    public function testLimitedSafeHtmlStripsScripts(): void
    {
        $html = AP_Content_Format::format(
            'Hello <script>alert(1)</script><b onclick="evil()">ok</b> '
            . '<a href="javascript:void(0)">bad</a> <a href="https://ok.example">good</a>',
            ['mode' => 'html']
        );
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringContainsString('<b>ok</b>', $html);
        $this->assertStringContainsString('href="https://ok.example"', $html);
    }

    public function testAutoMixesBbcodeAndMarkdown(): void
    {
        $html = AP_Content_Format::format("**Hello** [b]world[/b]\n\n[url=https://ex.test]go[/url]");
        $this->assertStringContainsString('<strong>Hello</strong>', $html);
        $this->assertStringContainsString('<strong>world</strong>', $html);
        $this->assertStringContainsString('href="https://ex.test"', $html);
    }

    public function testPlainModeEscapesEverything(): void
    {
        $html = AP_Content_Format::format('<b>x</b> & y', ['mode' => 'plain']);
        $this->assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', $html);
        $this->assertStringContainsString('&amp; y', $html);
        $this->assertStringNotContainsString('<b>', $html);
    }

    public function testIsSafeUrl(): void
    {
        $this->assertTrue(AP_Content_Format::isSafeUrl('https://example.com'));
        $this->assertTrue(AP_Content_Format::isSafeUrl('http://example.com/a?b=1'));
        $this->assertTrue(AP_Content_Format::isSafeUrl('mailto:a@b.com'));
        $this->assertTrue(AP_Content_Format::isSafeUrl('/relative/path'));
        $this->assertTrue(AP_Content_Format::isSafeUrl('//cdn.example.com/x'));
        $this->assertFalse(AP_Content_Format::isSafeUrl('javascript:alert(1)'));
        $this->assertFalse(AP_Content_Format::isSafeUrl('data:text/html,hi'));
        $this->assertFalse(AP_Content_Format::isSafeUrl('vbscript:msg'));
    }

    public function testKsesDropsUnknownTagsAndEventHandlers(): void
    {
        $out = AP_Content_Format::kses(
            '<p class="x" onmouseover="x">Hi</p><custom>nope</custom><img src="https://a.test/i.png" onerror="x" alt="a">'
        );
        $this->assertStringContainsString('<p class="x">Hi</p>', $out);
        $this->assertStringNotContainsString('onmouseover', $out);
        $this->assertStringNotContainsString('<custom>', $out);
        $this->assertStringContainsString('src="https://a.test/i.png"', $out);
        $this->assertStringNotContainsString('onerror', $out);
        $this->assertStringContainsString('alt="a"', $out);
    }

    public function testProceduralHelpers(): void
    {
        $this->assertStringContainsString('<strong>x</strong>', ap_format_content('[b]x[/b]'));
        $this->assertStringContainsString('<strong>', ap_bbcode_to_html('[b]x[/b]'));
        $this->assertStringContainsString('<strong>', ap_markdown_to_html('**x**'));
        $this->assertStringNotContainsString('<script', ap_kses('<script>x</script><em>y</em>'));
        $this->assertArrayHasKey('a', ap_allowed_html());
        $this->assertTrue(ap_is_safe_url('https://ok.test'));
        $this->assertFalse(ap_is_safe_url('javascript:x'));
    }

    public function testFilterHookCanAmendOutput(): void
    {
        if (!function_exists('ap_add_filter')) {
            $this->markTestSkipped('hooks not loaded');
        }
        ap_add_filter('ap_format_content', static function (string $html): string {
            return $html . '<!--fmt-->';
        });
        $out = AP_Content_Format::format('hi');
        $this->assertStringEndsWith('<!--fmt-->', $out);
    }

    public function testForumDisplayProvidesContentHtml(): void
    {
        require_once $this->root . '/ap-includes/version.php';
        require_once $this->root . '/ap-includes/class-ap-db.php';
        require_once $this->root . '/ap-includes/class-ap-migrator.php';
        require_once $this->root . '/ap-includes/class-ap-forum.php';

        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');
        $migrator = new AP_Migrator($db, AP_Migrator::defaultMigrationsPath());
        $migrator->migrate();

        $forumId = AP_Forum::insertForum([
            'forum_name' => 'Chat',
            'forum_type' => 'forum',
        ], $db);
        $this->assertGreaterThan(0, $forumId);

        $topicId = AP_Forum::createTopic([
            'forum_id' => $forumId,
            'topic_title' => 'Formatted',
            'content' => 'Hello [b]world[/b] and **md**',
            'poster_id' => 1,
        ], $db);
        $this->assertGreaterThan(0, $topicId);

        $posts = AP_Forum::getPosts($topicId, [], $db);
        $this->assertCount(1, $posts);
        $this->assertNotSame('', (string) $posts[0]->post_content_filtered);
        $this->assertStringContainsString('<strong>', (string) $posts[0]->post_content_filtered);

        $display = AP_Forum::getPostsDisplayData($topicId, [], $db);
        $this->assertSame('Hello [b]world[/b] and **md**', $display[0]['content']);
        $this->assertArrayHasKey('content_html', $display[0]);
        $this->assertStringContainsString('<strong>world</strong>', $display[0]['content_html']);
        $this->assertStringContainsString('<strong>md</strong>', $display[0]['content_html']);

        // Update re-renders filtered content.
        $postId = (int) $posts[0]->post_id;
        $ok = AP_Forum::updatePost($postId, [
            'content' => '[i]edited[/i]',
        ], $db);
        $this->assertTrue($ok);
        $updated = AP_Forum::getPost($postId, $db);
        $this->assertNotNull($updated);
        $this->assertStringContainsString('<em>edited</em>', (string) $updated->post_content_filtered);
    }

    public function testSpoilerAndColor(): void
    {
        $html = AP_Content_Format::format('[spoiler=Ending]they lived[/spoiler] [color=#ff0000]red[/color]');
        $this->assertStringContainsString('<details', $html);
        $this->assertStringContainsString('<summary>Ending</summary>', $html);
        $this->assertStringContainsString('they lived', $html);
        $this->assertStringContainsString('style="color:#ff0000"', $html);
        $this->assertStringContainsString('red', $html);
    }
}
