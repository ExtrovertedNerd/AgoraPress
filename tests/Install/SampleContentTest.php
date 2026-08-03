<?php

/**
 * Tests for optional sample content seeder (AP_Sample_Content + installer hook).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Install;

use AP_DB;
use AP_Forum;
use AP_Installer;
use AP_Post;
use AP_Privacy;
use AP_Sample_Content;
use AP_Taxonomy;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_Sample_Content::class)]
final class SampleContentTest extends TestCase
{
    private string $root;

    private string $tempDir;

    private AP_DB $db;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        require_once $this->root . '/ap-includes/version.php';
        require_once $this->root . '/ap-includes/load-config.php';
        require_once $this->root . '/ap-includes/class-ap-db.php';
        require_once $this->root . '/ap-includes/class-ap-migrator.php';
        require_once $this->root . '/ap-includes/class-ap-user.php';
        require_once $this->root . '/ap-includes/class-ap-roles.php';
        require_once $this->root . '/ap-includes/class-ap-options.php';
        require_once $this->root . '/ap-includes/class-ap-post.php';
        require_once $this->root . '/ap-includes/class-ap-taxonomy.php';
        require_once $this->root . '/ap-includes/class-ap-comment.php';
        require_once $this->root . '/ap-includes/class-ap-forum.php';
        require_once $this->root . '/ap-includes/class-ap-privacy.php';
        require_once $this->root . '/ap-includes/class-ap-installer.php';
        require_once $this->root . '/ap-includes/class-ap-sample-content.php';

        $this->tempDir = sys_get_temp_dir() . '/ap-sample-' . uniqid('', true);
        $this->assertTrue(mkdir($this->tempDir, 0700, true));

        $sqlite = $this->tempDir . '/site.sqlite';
        $pdo = new PDO('sqlite:' . $sqlite, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $this->db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');

        $migrator = new \AP_Migrator($this->db);
        $migrator->migrate();

        AP_Installer::seedOptions(
            $this->db,
            ['title' => 'Sample Test Site', 'url' => 'https://sample.example.test'],
            ['email' => 'admin@sample.example.test']
        );
        $adminId = AP_Installer::seedAdminUser(
            $this->db,
            [
                'username' => 'admin',
                'email' => 'admin@sample.example.test',
                'password' => 'password12345',
            ],
            ['title' => 'Sample Test Site']
        );
        $this->assertGreaterThan(0, $adminId);

        AP_Taxonomy::ensureBuiltins();
        AP_Taxonomy::ensureDefaultCategory($this->db);
        \AP_Options::flushCache();
    }

    protected function tearDown(): void
    {
        \AP_Options::flushCache();
        if (!is_dir($this->tempDir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tempDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            if ($file->isDir()) {
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
        }
        @rmdir($this->tempDir);
    }

    public function testSeedCreatesPostsPagesForumsAndComment(): void
    {
        $result = AP_Sample_Content::seed($this->db, [
            'author_id' => 1,
            'site_title' => 'Sample Test Site',
        ]);

        $this->assertTrue($result['ok'], implode('; ', $result['errors']));
        $this->assertFalse($result['skipped']);
        $this->assertNotSame([], $result['posts']);
        $this->assertNotSame([], $result['pages']);
        $this->assertNotSame([], $result['forums']);
        $this->assertNotSame([], $result['topics']);
        $this->assertNotSame([], $result['comments']);
        $this->assertTrue(AP_Sample_Content::isInstalled($this->db));

        $hello = AP_Post::getBySlug(AP_Sample_Content::SLUG_HELLO_POST, 'post', $this->db);
        $this->assertNotNull($hello);
        $this->assertSame('Hello world!', $hello->post_title);
        $this->assertSame('publish', $hello->post_status);
        $this->assertSame('1', AP_Post::getMeta((int) $hello->ID, AP_Sample_Content::META_FLAG, true, $this->db));

        $about = AP_Post::getBySlug(AP_Sample_Content::SLUG_ABOUT, 'page', $this->db);
        $this->assertNotNull($about);
        $privacy = AP_Post::getBySlug(AP_Sample_Content::SLUG_PRIVACY, 'page', $this->db);
        $this->assertNotNull($privacy);
        $this->assertSame((int) $privacy->ID, AP_Privacy::getPrivacyPolicyPageId($this->db));

        $forum = AP_Forum::getForumBySlug(AP_Sample_Content::SLUG_FORUM_GENERAL, $this->db);
        $this->assertNotNull($forum);
        $topic = AP_Forum::getTopicBySlug(
            AP_Sample_Content::SLUG_WELCOME_TOPIC,
            (int) $forum->forum_id,
            $this->db
        );
        $this->assertNotNull($topic);
        $this->assertStringContainsString('Welcome', (string) $topic->topic_title);

        $commentCount = (int) $this->db->getVar(
            'SELECT COUNT(*) FROM ' . $this->db->quoteIdentifier($this->db->table('comments'))
            . ' WHERE comment_post_ID = ?',
            [(int) $hello->ID]
        );
        $this->assertSame(1, $commentCount);
    }

    public function testSeedIsIdempotent(): void
    {
        $first = AP_Sample_Content::seed($this->db, ['author_id' => 1]);
        $this->assertTrue($first['ok']);
        $postCount = (int) $this->db->getVar(
            'SELECT COUNT(*) FROM ' . $this->db->quoteIdentifier($this->db->table('posts'))
            . ' WHERE post_type = ?',
            ['post']
        );

        $second = AP_Sample_Content::seed($this->db, ['author_id' => 1]);
        $this->assertTrue($second['ok']);
        $this->assertTrue($second['skipped']);

        $postCountAfter = (int) $this->db->getVar(
            'SELECT COUNT(*) FROM ' . $this->db->quoteIdentifier($this->db->table('posts'))
            . ' WHERE post_type = ?',
            ['post']
        );
        $this->assertSame($postCount, $postCountAfter);
    }

    public function testSeedRespectsDisabledBlogModule(): void
    {
        \AP_Options::update('ap_module_blog', '0', $this->db);
        \AP_Options::flushCache();

        $result = AP_Sample_Content::seed($this->db, ['author_id' => 1]);
        $this->assertTrue($result['ok'], implode('; ', $result['errors']));
        $this->assertSame([], $result['posts']);
        $this->assertSame([], $result['comments']);
        // Pages and forums still seed when their modules are on.
        $this->assertNotSame([], $result['pages']);
        $this->assertNotSame([], $result['forums']);
    }

    public function testInstallerRunWithSampleContent(): void
    {
        $sqlite = $this->tempDir . '/install-sample.sqlite';
        $configPath = $this->tempDir . '/ap-config-sample-run.php';

        $result = AP_Installer::run(
            [
                'driver' => 'sqlite',
                'name' => $sqlite,
                'prefix' => 'ap_',
            ],
            ['title' => 'Install Sample Site', 'url' => 'https://install-sample.example.test'],
            [
                'username' => 'siteadmin',
                'email' => 'admin@install-sample.example.test',
                'password' => 'securepass99',
            ],
            $configPath,
            ['sample_content' => true]
        );

        $this->assertTrue($result['ok'], implode('; ', $result['errors']));
        $this->assertIsArray($result['sample_content']);
        $this->assertTrue((bool) ($result['sample_content']['ok'] ?? false));
        $this->assertNotSame([], $result['sample_content']['posts'] ?? []);
        $this->assertNotSame([], $result['sample_content']['pages'] ?? []);
        $this->assertNotSame([], $result['sample_content']['forums'] ?? []);

        $pdo = new PDO('sqlite:' . $sqlite, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $apdb = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');
        $hello = $apdb->getVar(
            'SELECT post_title FROM ' . $apdb->quoteIdentifier($apdb->table('posts'))
            . ' WHERE post_name = ? AND post_type = ?',
            [AP_Sample_Content::SLUG_HELLO_POST, 'post']
        );
        $this->assertSame('Hello world!', $hello);
        $flag = $apdb->getVar(
            'SELECT option_value FROM ' . $apdb->quoteIdentifier($apdb->options)
            . ' WHERE option_name = ?',
            [AP_Sample_Content::OPTION_INSTALLED]
        );
        $this->assertSame('1', $flag);
    }

    public function testInstallerRunWithoutSampleContentSkipsSeed(): void
    {
        $sqlite = $this->tempDir . '/install-nosample.sqlite';
        $configPath = $this->tempDir . '/ap-config-nosample.php';

        $result = AP_Installer::run(
            [
                'driver' => 'sqlite',
                'name' => $sqlite,
                'prefix' => 'ap_',
            ],
            ['title' => 'Bare Site', 'url' => 'https://bare.example.test'],
            [
                'username' => 'admin',
                'email' => 'admin@bare.example.test',
                'password' => 'securepass99',
            ],
            $configPath,
            ['sample_content' => false]
        );

        $this->assertTrue($result['ok'], implode('; ', $result['errors']));
        $this->assertNull($result['sample_content']);

        $pdo = new PDO('sqlite:' . $sqlite, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $apdb = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');
        $posts = (int) $apdb->getVar(
            'SELECT COUNT(*) FROM ' . $apdb->quoteIdentifier($apdb->table('posts'))
            . " WHERE post_type IN ('post','page')"
        );
        $this->assertSame(0, $posts);
    }
}
