<?php

/**
 * Tests for AP_Wxr_Importer (WordPress WXR import).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Import;

use AP_Comment;
use AP_DB;
use AP_Migrator;
use AP_Post;
use AP_Roles;
use AP_Taxonomy;
use AP_User;
use AP_Wxr_Importer;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_Wxr_Importer::class)]
final class WxrImporterTest extends TestCase
{
    private string $root;

    private AP_DB $db;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        require_once $this->root . '/ap-includes/version.php';
        require_once $this->root . '/ap-includes/hooks.php';
        require_once $this->root . '/ap-includes/class-ap-db.php';
        require_once $this->root . '/ap-includes/class-ap-migrator.php';
        require_once $this->root . '/ap-includes/class-ap-options.php';
        require_once $this->root . '/ap-includes/class-ap-user.php';
        require_once $this->root . '/ap-includes/class-ap-roles.php';
        require_once $this->root . '/ap-includes/class-ap-post.php';
        require_once $this->root . '/ap-includes/class-ap-taxonomy.php';
        require_once $this->root . '/ap-includes/class-ap-comment.php';
        require_once $this->root . '/ap-includes/class-ap-formatting.php';
        require_once $this->root . '/ap-includes/class-ap-wxr-importer.php';
        require_once $this->root . '/ap-includes/functions.php';

        AP_Post::resetRegistry();
        AP_Taxonomy::resetRegistry();
        AP_Roles::flushCache();

        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');
        $migrator = new AP_Migrator($this->db, AP_Migrator::defaultMigrationsPath());
        $migrator->migrate();
        AP_Roles::ensureDefaults($this->db);
        AP_Taxonomy::ensureBuiltins();
        AP_Post::ensureBuiltins();
    }

    protected function tearDown(): void
    {
        AP_Post::resetRegistry();
        AP_Taxonomy::resetRegistry();
        AP_Roles::flushCache();
    }

    public function testIsWxrDetectsMarkers(): void
    {
        $this->assertTrue(AP_Wxr_Importer::isWxr($this->sampleWxr()));
        $this->assertTrue(AP_Wxr_Importer::isWxr(
            '<?xml version="1.0"?><rss xmlns:wp="http://wordpress.org/export/1.2/"><channel></channel></rss>'
        ));
        $this->assertFalse(AP_Wxr_Importer::isWxr('<html><body>not wxr</body></html>'));
        $this->assertFalse(AP_Wxr_Importer::isWxr(''));
    }

    public function testParseExtractsAuthorsTermsAndItems(): void
    {
        $parsed = AP_Wxr_Importer::parse($this->sampleWxr());
        $this->assertSame([], $parsed['errors']);
        $this->assertSame('1.2', $parsed['wxr_version']);
        $this->assertSame('Demo Site', $parsed['site_title']);
        $this->assertSame('https://example.com', $parsed['base_url']);
        $this->assertCount(1, $parsed['authors']);
        $this->assertSame('alice', $parsed['authors'][0]['author_login']);
        $this->assertCount(1, $parsed['categories']);
        $this->assertSame('news', $parsed['categories'][0]['slug']);
        $this->assertCount(1, $parsed['tags']);
        $this->assertCount(2, $parsed['items']);
        $this->assertSame('Hello from WordPress', $parsed['items'][0]['title']);
        $this->assertSame('post', $parsed['items'][0]['post_type']);
        $this->assertNotSame('', $parsed['items'][0]['content']);
        $this->assertCount(1, $parsed['items'][0]['comments']);
    }

    public function testParseRejectsInvalidXml(): void
    {
        $parsed = AP_Wxr_Importer::parse('not xml at all');
        $this->assertNotEmpty($parsed['errors']);
    }

    public function testImportFromStringCreatesContent(): void
    {
        $result = AP_Wxr_Importer::importFromString($this->sampleWxr(), $this->db);

        $this->assertTrue($result['ok'], implode('; ', $result['errors']));
        $this->assertSame(1, $result['authors']);
        $this->assertSame(1, $result['authors_created']);
        $this->assertSame(1, $result['categories']);
        $this->assertSame(1, $result['tags']);
        $this->assertSame(1, $result['posts']);
        $this->assertSame(1, $result['pages']);
        $this->assertSame(1, $result['comments']);
        $this->assertArrayHasKey('alice', $result['author_map']);

        $user = AP_User::getByLogin('alice', $this->db);
        $this->assertNotNull($user);
        $this->assertSame('alice@example.com', $user->user_email);

        $posts = AP_Post::query([
            'post_type' => 'post',
            'post_status' => 'publish',
            'limit' => 10,
        ], $this->db);
        $this->assertNotEmpty($posts);
        $found = null;
        foreach ($posts as $p) {
            if ($p->post_title === 'Hello from WordPress') {
                $found = $p;
                break;
            }
        }
        $this->assertNotNull($found);
        $this->assertStringContainsString('Imported body', $found->post_content);
        $this->assertSame((int) $user->ID, $found->post_author);

        $wxrId = AP_Post::getMeta((int) $found->ID, AP_Wxr_Importer::META_WXR_POST_ID, true, $this->db);
        $this->assertSame('10', $wxrId);

        $cats = AP_Taxonomy::getObjectTerms((int) $found->ID, 'category', ['fields' => 'slugs'], $this->db);
        $this->assertContains('news', $cats);
        $tags = AP_Taxonomy::getObjectTerms((int) $found->ID, 'post_tag', ['fields' => 'slugs'], $this->db);
        $this->assertContains('launch', $tags);

        $meta = AP_Post::getMeta((int) $found->ID, '_custom_field', true, $this->db);
        $this->assertSame('custom-value', $meta);

        $page = AP_Post::getBySlug('about', 'page', $this->db);
        $this->assertNotNull($page);
        $this->assertSame('About Us', $page->post_title);

        $comments = AP_Comment::query([
            'post_id' => (int) $found->ID,
            'status' => 'approve',
            'number' => 10,
        ], $this->db);
        $this->assertNotEmpty($comments);
        $this->assertStringContainsString('Nice post', $comments[0]->comment_content);
    }

    public function testImportMapsExistingAuthorByLogin(): void
    {
        $created = AP_User::create([
            'user_login' => 'alice',
            'user_email' => 'alice-existing@example.com',
            'user_pass' => 'password-long-enough',
            'role' => 'author',
        ], $this->db);
        $this->assertTrue($created['ok']);
        $existingId = (int) $created['id'];

        $result = AP_Wxr_Importer::importFromString($this->sampleWxr(), $this->db);
        $this->assertTrue($result['ok'], implode('; ', $result['errors']));
        $this->assertSame(0, $result['authors_created']);
        $this->assertSame(1, $result['authors_mapped']);
        $this->assertSame($existingId, $result['author_map']['alice']);
    }

    public function testImportRemapsPageParent(): void
    {
        $xml = $this->wxrShell(
            '',
            '',
            $this->itemXml([
                'title' => 'Parent Page',
                'post_id' => 100,
                'post_name' => 'parent-page',
                'post_type' => 'page',
                'status' => 'publish',
                'content' => 'Parent',
            ])
            . $this->itemXml([
                'title' => 'Child Page',
                'post_id' => 101,
                'post_name' => 'child-page',
                'post_type' => 'page',
                'status' => 'publish',
                'post_parent' => 100,
                'content' => 'Child',
            ])
        );

        $result = AP_Wxr_Importer::importFromString($xml, $this->db);
        $this->assertTrue($result['ok'], implode('; ', $result['errors']));
        $this->assertSame(2, $result['pages']);

        $parent = AP_Post::getBySlug('parent-page', 'page', $this->db);
        $child = AP_Post::getBySlug('child-page', 'page', $this->db);
        $this->assertNotNull($parent);
        $this->assertNotNull($child);
        $this->assertSame((int) $parent->ID, (int) $child->post_parent);
    }

    public function testImportSkipsRevisionsAndNavMenuItems(): void
    {
        $xml = $this->wxrShell(
            '',
            '',
            $this->itemXml([
                'title' => 'Rev',
                'post_id' => 50,
                'post_type' => 'revision',
                'status' => 'inherit',
                'content' => 'x',
            ])
            . $this->itemXml([
                'title' => 'Menu Item',
                'post_id' => 51,
                'post_type' => 'nav_menu_item',
                'status' => 'publish',
                'content' => 'y',
            ])
            . $this->itemXml([
                'title' => 'Real Post',
                'post_id' => 52,
                'post_type' => 'post',
                'status' => 'publish',
                'content' => 'z',
                'post_name' => 'real-post',
            ])
        );

        $result = AP_Wxr_Importer::importFromString($xml, $this->db);
        $this->assertTrue($result['ok']);
        $this->assertSame(1, $result['posts']);
        $this->assertSame(2, $result['skipped']);
    }

    public function testImportAttachmentStoresUrlMeta(): void
    {
        $xml = $this->wxrShell(
            '',
            '',
            $this->itemXml([
                'title' => 'Photo',
                'post_id' => 70,
                'post_type' => 'attachment',
                'status' => 'inherit',
                'content' => '',
                'post_name' => 'photo',
                'attachment_url' => 'https://example.com/wp-content/uploads/photo.jpg',
                'post_mime_type' => 'image/jpeg',
            ])
        );

        $result = AP_Wxr_Importer::importFromString($xml, $this->db);
        $this->assertTrue($result['ok'], implode('; ', $result['errors']));
        $this->assertSame(1, $result['attachments']);

        $att = AP_Post::getBySlug('photo', 'attachment', $this->db);
        $this->assertNotNull($att);
        $url = AP_Post::getMeta((int) $att->ID, AP_Wxr_Importer::META_ATTACHMENT_URL, true, $this->db);
        $this->assertSame('https://example.com/wp-content/uploads/photo.jpg', $url);
    }

    public function testImportFromFileAndMissingFile(): void
    {
        $missing = AP_Wxr_Importer::importFromFile('/tmp/does-not-exist-wxr-' . bin2hex(random_bytes(4)) . '.xml', $this->db);
        $this->assertFalse($missing['ok']);
        $this->assertNotEmpty($missing['errors']);

        $tmp = sys_get_temp_dir() . '/ap-wxr-test-' . bin2hex(random_bytes(6)) . '.xml';
        file_put_contents($tmp, $this->sampleWxr());
        try {
            $result = AP_Wxr_Importer::importFromFile($tmp, $this->db);
            $this->assertTrue($result['ok'], implode('; ', $result['errors']));
            $this->assertSame(1, $result['posts']);
        } finally {
            @unlink($tmp);
        }
    }

    public function testProceduralHelpers(): void
    {
        $this->assertTrue(ap_is_wxr($this->sampleWxr()));
        $parsed = ap_parse_wxr($this->sampleWxr());
        $this->assertSame([], $parsed['errors']);
        $result = ap_import_wxr_string($this->sampleWxr(), $this->db);
        $this->assertTrue($result['ok']);
    }

    public function testNestedCommentsRemapParents(): void
    {
        $xml = $this->wxrShell(
            $this->authorXml('bob', 'bob@example.com', 2),
            '',
            $this->itemXml([
                'title' => 'Thread',
                'post_id' => 80,
                'post_type' => 'post',
                'status' => 'publish',
                'post_name' => 'thread',
                'content' => 'body',
                'creator' => 'bob',
                'comments' => [
                    [
                        'id' => 1,
                        'author' => 'Parent',
                        'email' => 'p@example.com',
                        'content' => 'Parent comment',
                        'approved' => '1',
                        'parent' => 0,
                    ],
                    [
                        'id' => 2,
                        'author' => 'Child',
                        'email' => 'c@example.com',
                        'content' => 'Child comment',
                        'approved' => '1',
                        'parent' => 1,
                    ],
                ],
            ])
        );

        $result = AP_Wxr_Importer::importFromString($xml, $this->db);
        $this->assertTrue($result['ok'], implode('; ', $result['errors']));
        $this->assertSame(2, $result['comments']);

        $post = AP_Post::getBySlug('thread', 'post', $this->db);
        $this->assertNotNull($post);
        $tree = AP_Comment::getTree((int) $post->ID, [], $this->db);
        $this->assertNotEmpty($tree);
        // Flatten: parent with one child.
        $flat = AP_Comment::query([
            'post_id' => (int) $post->ID,
            'status' => 'approve',
            'number' => 20,
            'orderby' => 'id',
            'order' => 'ASC',
        ], $this->db);
        $this->assertCount(2, $flat);
        $parent = $flat[0];
        $child = $flat[1];
        $this->assertSame(0, (int) $parent->comment_parent);
        $this->assertSame((int) $parent->comment_ID, (int) $child->comment_parent);
    }

    // -------------------------------------------------------------------------
    // Fixtures
    // -------------------------------------------------------------------------

    private function sampleWxr(): string
    {
        $authors = $this->authorXml('alice', 'alice@example.com', 1, 'Alice Author');
        $terms = <<<XML
	<wp:category>
		<wp:term_id>3</wp:term_id>
		<wp:category_nicename><![CDATA[news]]></wp:category_nicename>
		<wp:category_parent><![CDATA[]]></wp:category_parent>
		<wp:cat_name><![CDATA[News]]></wp:cat_name>
	</wp:category>
	<wp:tag>
		<wp:term_id>4</wp:term_id>
		<wp:tag_slug><![CDATA[launch]]></wp:tag_slug>
		<wp:tag_name><![CDATA[Launch]]></wp:tag_name>
	</wp:tag>
XML;
        $items = $this->itemXml([
            'title' => 'Hello from WordPress',
            'post_id' => 10,
            'post_name' => 'hello-from-wordpress',
            'post_type' => 'post',
            'status' => 'publish',
            'content' => 'Imported body with <strong>HTML</strong>.',
            'excerpt' => 'Short excerpt',
            'creator' => 'alice',
            'categories' => [
                ['domain' => 'category', 'nicename' => 'news', 'name' => 'News'],
                ['domain' => 'post_tag', 'nicename' => 'launch', 'name' => 'Launch'],
            ],
            'postmeta' => [
                ['_custom_field', 'custom-value'],
            ],
            'comments' => [
                [
                    'id' => 5,
                    'author' => 'Visitor',
                    'email' => 'visitor@example.com',
                    'content' => 'Nice post!',
                    'approved' => '1',
                    'parent' => 0,
                ],
            ],
        ])
        . $this->itemXml([
            'title' => 'About Us',
            'post_id' => 20,
            'post_name' => 'about',
            'post_type' => 'page',
            'status' => 'publish',
            'content' => 'About page content.',
            'creator' => 'alice',
        ]);

        return $this->wxrShell($authors, $terms, $items);
    }

    private function authorXml(
        string $login,
        string $email,
        int $id = 1,
        string $display = ''
    ): string {
        if ($display === '') {
            $display = $login;
        }

        return <<<XML
	<wp:author>
		<wp:author_id>{$id}</wp:author_id>
		<wp:author_login><![CDATA[{$login}]]></wp:author_login>
		<wp:author_email><![CDATA[{$email}]]></wp:author_email>
		<wp:author_display_name><![CDATA[{$display}]]></wp:author_display_name>
		<wp:author_first_name><![CDATA[]]></wp:author_first_name>
		<wp:author_last_name><![CDATA[]]></wp:author_last_name>
	</wp:author>
XML;
    }

    /**
     * @param array<string, mixed> $p
     */
    private function itemXml(array $p): string
    {
        $title = (string) ($p['title'] ?? 'Untitled');
        $postId = (int) ($p['post_id'] ?? 1);
        $postName = (string) ($p['post_name'] ?? 'item-' . $postId);
        $type = (string) ($p['post_type'] ?? 'post');
        $status = (string) ($p['status'] ?? 'publish');
        $content = (string) ($p['content'] ?? '');
        $excerpt = (string) ($p['excerpt'] ?? '');
        $creator = (string) ($p['creator'] ?? 'admin');
        $parent = (int) ($p['post_parent'] ?? 0);
        $mime = (string) ($p['post_mime_type'] ?? '');
        $attUrl = (string) ($p['attachment_url'] ?? '');
        $date = (string) ($p['post_date'] ?? '2024-06-15 12:00:00');

        $cats = '';
        foreach ($p['categories'] ?? [] as $c) {
            $domain = ap_esc_attr((string) $c['domain']);
            $nicename = ap_esc_attr((string) $c['nicename']);
            $name = (string) $c['name'];
            $cats .= "\t\t<category domain=\"{$domain}\" nicename=\"{$nicename}\"><![CDATA[{$name}]]></category>\n";
        }

        $meta = '';
        foreach ($p['postmeta'] ?? [] as $m) {
            $key = (string) $m[0];
            $val = (string) $m[1];
            $meta .= "\t\t<wp:postmeta>\n\t\t\t<wp:meta_key><![CDATA[{$key}]]></wp:meta_key>\n"
                . "\t\t\t<wp:meta_value><![CDATA[{$val}]]></wp:meta_value>\n\t\t</wp:postmeta>\n";
        }

        $comments = '';
        foreach ($p['comments'] ?? [] as $c) {
            $cid = (int) $c['id'];
            $cparent = (int) ($c['parent'] ?? 0);
            $cauthor = (string) $c['author'];
            $cemail = (string) $c['email'];
            $ccontent = (string) $c['content'];
            $capproved = (string) ($c['approved'] ?? '1');
            $comments .= <<<XML
		<wp:comment>
			<wp:comment_id>{$cid}</wp:comment_id>
			<wp:comment_author><![CDATA[{$cauthor}]]></wp:comment_author>
			<wp:comment_author_email><![CDATA[{$cemail}]]></wp:comment_author_email>
			<wp:comment_author_url><![CDATA[]]></wp:comment_author_url>
			<wp:comment_author_IP><![CDATA[127.0.0.1]]></wp:comment_author_IP>
			<wp:comment_date><![CDATA[{$date}]]></wp:comment_date>
			<wp:comment_date_gmt><![CDATA[{$date}]]></wp:comment_date_gmt>
			<wp:comment_content><![CDATA[{$ccontent}]]></wp:comment_content>
			<wp:comment_approved><![CDATA[{$capproved}]]></wp:comment_approved>
			<wp:comment_type><![CDATA[comment]]></wp:comment_type>
			<wp:comment_parent>{$cparent}</wp:comment_parent>
			<wp:comment_user_id>0</wp:comment_user_id>
		</wp:comment>
XML;
        }

        $attBlock = $attUrl !== ''
            ? "\t\t<wp:attachment_url><![CDATA[{$attUrl}]]></wp:attachment_url>\n"
            : '';
        $mimeBlock = $mime !== ''
            ? "\t\t<wp:post_mime_type><![CDATA[{$mime}]]></wp:post_mime_type>\n"
            : '';

        return <<<XML
	<item>
		<title><![CDATA[{$title}]]></title>
		<link>https://example.com/{$postName}/</link>
		<pubDate>Sat, 15 Jun 2024 12:00:00 +0000</pubDate>
		<dc:creator><![CDATA[{$creator}]]></dc:creator>
		<guid isPermaLink="false">https://example.com/?p={$postId}</guid>
		<description></description>
		<content:encoded><![CDATA[{$content}]]></content:encoded>
		<excerpt:encoded><![CDATA[{$excerpt}]]></excerpt:encoded>
		<wp:post_id>{$postId}</wp:post_id>
		<wp:post_date><![CDATA[{$date}]]></wp:post_date>
		<wp:post_date_gmt><![CDATA[{$date}]]></wp:post_date_gmt>
		<wp:comment_status><![CDATA[open]]></wp:comment_status>
		<wp:ping_status><![CDATA[closed]]></wp:ping_status>
		<wp:post_name><![CDATA[{$postName}]]></wp:post_name>
		<wp:status><![CDATA[{$status}]]></wp:status>
		<wp:post_parent>{$parent}</wp:post_parent>
		<wp:menu_order>0</wp:menu_order>
		<wp:post_type><![CDATA[{$type}]]></wp:post_type>
		<wp:post_password><![CDATA[]]></wp:post_password>
		<wp:is_sticky>0</wp:is_sticky>
{$mimeBlock}{$attBlock}{$cats}{$meta}{$comments}
	</item>
XML;
    }

    private function wxrShell(string $authors, string $terms, string $items): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8" ?>
<rss version="2.0"
	xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/"
	xmlns:content="http://purl.org/rss/1.0/modules/content/"
	xmlns:wfw="http://wellformedweb.org/CommentAPI/"
	xmlns:dc="http://purl.org/dc/elements/1.1/"
	xmlns:wp="http://wordpress.org/export/1.2/">
<channel>
	<title>Demo Site</title>
	<link>https://example.com</link>
	<description>Just another WordPress site</description>
	<pubDate>Sat, 15 Jun 2024 12:00:00 +0000</pubDate>
	<language>en-US</language>
	<wp:wxr_version>1.2</wp:wxr_version>
	<wp:base_site_url>https://example.com</wp:base_site_url>
	<wp:base_blog_url>https://example.com</wp:base_blog_url>
{$authors}
{$terms}
{$items}
</channel>
</rss>
XML;
    }
}
